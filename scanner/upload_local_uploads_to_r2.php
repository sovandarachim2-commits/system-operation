<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/storage.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    echo "This script must be run from CLI.\n";
    exit(1);
}

function scanner_upload_cli_write(string $message, bool $isError = false): void
{
    $stream = $isError ? fopen('php://stderr', 'wb') : fopen('php://stdout', 'wb');
    if ($stream) {
        fwrite($stream, $message);
        fclose($stream);
        return;
    }
    echo $message;
}

function scanner_upload_cli_usage(): void
{
    $usage = <<<TXT
Usage:
  php scanner/upload_local_uploads_to_r2.php [--apply] [--workers=4] [--force]

Behavior:
  - Without --apply: dry run only
  - With --apply: uploads scanner/uploads/** to Cloudflare R2
  - workers controls parallel upload count during --apply
  - existing R2 files are skipped unless --force is provided

Notes:
  - Keeps the same relative object path, like uploads/2026/05/file.jpg
  - Does not update database rows
  - Requires SCANNER_STORAGE_DRIVER = 'r2'

TXT;
    scanner_upload_cli_write($usage);
}

$apply = in_array('--apply', $argv, true);
$showHelp = in_array('--help', $argv, true) || in_array('-h', $argv, true);
$force = in_array('--force', $argv, true);
$workers = 4;
foreach ($argv as $arg) {
    if (strpos($arg, '--workers=') === 0) {
        $workers = max(1, min(12, (int)substr($arg, 10)));
    }
}

if ($showHelp) {
    scanner_upload_cli_usage();
    exit(0);
}

if (scanner_storage_driver() !== 'r2') {
    scanner_upload_cli_write("SCANNER_STORAGE_DRIVER must be set to 'r2' before using this script.\n", true);
    exit(1);
}

$uploadsRoot = __DIR__ . '/uploads';
if (!is_dir($uploadsRoot)) {
    scanner_upload_cli_write("Uploads folder not found: {$uploadsRoot}\n", true);
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($uploadsRoot, FilesystemIterator::SKIP_DOTS)
);

$files = [];
$totalFiles = 0;
$totalBytes = 0;
$uploaded = 0;
$skipped = 0;
$failed = 0;

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }

    $absolutePath = $fileInfo->getPathname();
    $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($uploadsRoot) + 1));
    $objectPrefix = scanner_storage_object_prefix();
    $objectKey = ($objectPrefix !== '' ? $objectPrefix . '/' : '') . 'uploads/' . ltrim($relativePath, '/');
    $size = (int)$fileInfo->getSize();

    $totalFiles++;
    $totalBytes += $size;
    $files[] = [
        'absolute_path' => $absolutePath,
        'object_key' => $objectKey,
        'size' => $size,
    ];

    if (!$apply) {
        scanner_upload_cli_write("[DRY RUN] {$objectKey} (" . number_format($size) . " bytes)\n");
    }
}

if (!$apply) {
    scanner_upload_cli_write("\nDry run complete.\n");
    scanner_upload_cli_write("Files found: {$totalFiles}\n");
    scanner_upload_cli_write("Total size: " . number_format($totalBytes) . " bytes\n");
    scanner_upload_cli_write("Run with --apply to upload these files to R2.\n");
    exit(0);
}

scanner_upload_cli_write(
    "Starting upload of {$totalFiles} files (" . number_format($totalBytes) . " bytes) with {$workers} worker(s)...\n"
);

$multi = curl_multi_init();
$activeUploads = [];
$queueIndex = 0;
$completed = 0;
$lastProgressPrint = 0;

$startUpload = function (array $file) use (&$multi, &$activeUploads): void {
    $contentType = mime_content_type($file['absolute_path']);
    if (!is_string($contentType) || $contentType === '') {
        $contentType = 'application/octet-stream';
    }

    $upload = scanner_storage_r2_create_upload_handle($file['absolute_path'], $file['object_key'], $contentType);
    $ch = $upload['curl'];
    curl_multi_add_handle($multi, $ch);
    $activeUploads[(int)$ch] = [
        'curl' => $ch,
        'stream' => $upload['stream'],
        'object_key' => $file['object_key'],
    ];
};

while ($queueIndex < count($files) && count($activeUploads) < $workers) {
    if (!$force) {
        try {
            if (scanner_storage_r2_object_exists($files[$queueIndex]['object_key'])) {
                $skipped++;
                $completed++;
                if ($completed - $lastProgressPrint >= 25 || $completed === $totalFiles) {
                    $lastProgressPrint = $completed;
                    scanner_upload_cli_write("Progress: {$completed}/{$totalFiles} uploaded {$uploaded}, skipped {$skipped}, failed {$failed}\n");
                }
                $queueIndex++;
                continue;
            }
        } catch (Throwable $e) {
            scanner_upload_cli_write("[HEAD CHECK FAILED] {$files[$queueIndex]['object_key']} :: " . $e->getMessage() . "\n", true);
        }
    }
    $startUpload($files[$queueIndex]);
    $queueIndex++;
}

do {
    do {
        $multiExec = curl_multi_exec($multi, $running);
    } while ($multiExec === CURLM_CALL_MULTI_PERFORM);

    while ($info = curl_multi_info_read($multi)) {
        $ch = $info['handle'];
        $key = (int)$ch;
        if (!isset($activeUploads[$key])) {
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            continue;
        }

        $meta = $activeUploads[$key];
        $response = curl_multi_getcontent($ch);
        $curlError = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($info['result'] === CURLE_OK && $statusCode >= 200 && $statusCode < 300) {
            $uploaded++;
        } else {
            $failed++;
            $message = $curlError !== '' ? $curlError : ('HTTP ' . $statusCode);
            scanner_upload_cli_write("[FAILED] {$meta['object_key']} :: {$message}\n", true);
        }

        fclose($meta['stream']);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        unset($activeUploads[$key]);

        $completed++;
        if ($completed - $lastProgressPrint >= 25 || $completed === $totalFiles) {
            $lastProgressPrint = $completed;
            scanner_upload_cli_write("Progress: {$completed}/{$totalFiles} uploaded {$uploaded}, skipped {$skipped}, failed {$failed}\n");
        }

        while ($queueIndex < count($files) && count($activeUploads) < $workers) {
            if (!$force) {
                try {
                    if (scanner_storage_r2_object_exists($files[$queueIndex]['object_key'])) {
                        $skipped++;
                        $completed++;
                        if ($completed - $lastProgressPrint >= 25 || $completed === $totalFiles) {
                            $lastProgressPrint = $completed;
                            scanner_upload_cli_write("Progress: {$completed}/{$totalFiles} uploaded {$uploaded}, skipped {$skipped}, failed {$failed}\n");
                        }
                        $queueIndex++;
                        continue;
                    }
                } catch (Throwable $e) {
                    scanner_upload_cli_write("[HEAD CHECK FAILED] {$files[$queueIndex]['object_key']} :: " . $e->getMessage() . "\n", true);
                }
            }
            $startUpload($files[$queueIndex]);
            $queueIndex++;
        }
    }

    if ($running > 0) {
        curl_multi_select($multi, 1.0);
    }
} while ($running > 0 || !empty($activeUploads));

curl_multi_close($multi);

scanner_upload_cli_write("\nUpload complete.\n");
scanner_upload_cli_write("Files found: {$totalFiles}\n");
scanner_upload_cli_write("Uploaded: {$uploaded}\n");
scanner_upload_cli_write("Failed: {$failed}\n");
scanner_upload_cli_write("Skipped: {$skipped}\n");
scanner_upload_cli_write("Total size: " . number_format($totalBytes) . " bytes\n");

exit($failed > 0 ? 2 : 0);
