<?php

function upload_date_parts(?string $date = null): array
{
    $timestamp = $date !== null && trim($date) !== '' ? strtotime($date) : false;
    if ($timestamp === false) {
        $timestamp = time();
    }

    return [
        'year' => date('Y', $timestamp),
        'month' => date('m', $timestamp),
    ];
}

function upload_dated_dir(string $category, ?string $date = null): array
{
    $category = trim(str_replace('\\', '/', $category), '/');
    $parts = upload_date_parts($date);
    $relativeDir = 'uploads/' . $category . '/' . $parts['year'] . '/' . $parts['month'];
    $absoluteDir = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Failed to create upload directory: ' . $absoluteDir);
    }

    return [
        'absolute' => $absoluteDir,
        'relative' => $relativeDir,
        'suffix' => $parts['year'] . '/' . $parts['month'],
    ];
}

function upload_storage_driver(): string
{
    $driver = strtolower(trim((string)($GLOBALS['APP_STORAGE_DRIVER'] ?? 'local')));
    return $driver === 'r2' ? 'r2' : 'local';
}

function upload_storage_is_r2(): bool
{
    return upload_storage_driver() === 'r2';
}

function upload_storage_object_prefix(): string
{
    return trim(str_replace('\\', '/', (string)($GLOBALS['APP_R2_OBJECT_PREFIX'] ?? '')), '/');
}

function upload_storage_public_base_url(): string
{
    return rtrim(trim((string)($GLOBALS['APP_R2_PUBLIC_BASE_URL'] ?? '')), '/');
}

function upload_storage_is_remote_path(string $path): bool
{
    return preg_match('#^https?://#i', $path) === 1;
}

function upload_storage_relative_path(string $storedPath, string $category = ''): string
{
    $path = ltrim(str_replace('\\', '/', trim($storedPath)), '/');
    if ($path === '' || upload_storage_is_remote_path($path)) {
        return $path;
    }
    if (strncmp($path, 'uploads/', 8) === 0 || strncmp($path, 'public/', 7) === 0) {
        return $path;
    }
    $category = trim(str_replace('\\', '/', $category), '/');
    return $category !== '' ? 'uploads/' . $category . '/' . $path : $path;
}

function uploaded_file_url(?string $storedPath, string $category = ''): string
{
    if ($storedPath === null || trim($storedPath) === '') {
        return '';
    }

    $path = upload_storage_relative_path($storedPath, $category);
    if ($path === '' || upload_storage_is_remote_path($path)) {
        return $path;
    }

    if (upload_storage_is_r2()) {
        $base = upload_storage_public_base_url();
        if ($base !== '') {
            $prefix = upload_storage_object_prefix();
            $key = ($prefix !== '' ? $prefix . '/' : '') . $path;
            return $base . '/' . ltrim($key, '/');
        }
    }

    $baseUrl = rtrim((string)($GLOBALS['BASE_URL'] ?? ''), '/');
    return $baseUrl . '/' . ltrim($path, '/');
}

function upload_storage_object_key(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $prefix = upload_storage_object_prefix();
    return ($prefix !== '' ? $prefix . '/' : '') . $relativePath;
}

function upload_store_uploaded_file(array $file, string $category, string $filename, ?string $date = null, string $contentType = ''): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return '';
    }

    return upload_store_file_path($tmpName, $category, $filename, $date, $contentType, true);
}

function upload_store_file_path(string $sourcePath, string $category, string $filename, ?string $date = null, string $contentType = '', bool $uploadedFile = false): string
{
    $target = upload_dated_dir($category, $date);
    $filename = basename(str_replace('\\', '/', $filename));
    $relativePath = $target['relative'] . '/' . $filename;

    if (upload_storage_is_r2()) {
        $contentType = $contentType !== '' ? $contentType : (mime_content_type($sourcePath) ?: 'application/octet-stream');
        upload_storage_r2_put_object($sourcePath, upload_storage_object_key($relativePath), $contentType);
        return $relativePath;
    }

    $targetPath = $target['absolute'] . DIRECTORY_SEPARATOR . $filename;
    if ($uploadedFile) {
        if (!move_uploaded_file($sourcePath, $targetPath)) {
            throw new RuntimeException('Failed to store uploaded file locally.');
        }
    } elseif (!copy($sourcePath, $targetPath)) {
        throw new RuntimeException('Failed to copy file into upload directory.');
    }

    return $relativePath;
}

function upload_delete_local_file(?string $storedPath, string $category = ''): void
{
    if ($storedPath === null || $storedPath === '' || upload_storage_is_remote_path($storedPath)) {
        return;
    }
    $relative = upload_storage_relative_path($storedPath, $category);
    if ($relative === '' || strpos($relative, '..') !== false) {
        return;
    }
    $full = realpath(__DIR__ . '/' . $relative);
    $base = realpath(__DIR__ . '/uploads');
    if ($full && $base && strpos($full, $base) === 0 && is_file($full)) {
        @unlink($full);
    }
}

function upload_storage_r2_put_object(string $sourcePath, string $objectKey, string $contentType = 'application/octet-stream'): void
{
    $upload = upload_storage_r2_create_upload_handle($sourcePath, $objectKey, $contentType);
    $ch = $upload['curl'];
    $stream = $upload['stream'];

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($stream);

    if ($response === false) {
        throw new RuntimeException('R2 upload failed: ' . $curlError);
    }
    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('R2 upload failed with HTTP ' . $statusCode . '.');
    }
}

function upload_storage_r2_create_upload_handle(string $sourcePath, string $objectKey, string $contentType = 'application/octet-stream'): array
{
    $accountId = trim((string)($GLOBALS['APP_R2_ACCOUNT_ID'] ?? ''));
    $bucket = trim((string)($GLOBALS['APP_R2_BUCKET'] ?? ''));
    $accessKey = trim((string)($GLOBALS['APP_R2_ACCESS_KEY_ID'] ?? ''));
    $secretKey = trim((string)($GLOBALS['APP_R2_SECRET_ACCESS_KEY'] ?? ''));

    if ($accountId === '' || $bucket === '' || $accessKey === '' || $secretKey === '') {
        throw new RuntimeException('R2 storage is enabled, but app R2 credentials are incomplete.');
    }
    if (!is_file($sourcePath)) {
        throw new RuntimeException('R2 source file not found: ' . $sourcePath);
    }

    $stream = fopen($sourcePath, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Failed to open file for R2 upload: ' . $sourcePath);
    }

    $service = 's3';
    $region = 'auto';
    $host = $accountId . '.r2.cloudflarestorage.com';
    $canonicalUri = '/' . rawurlencode($bucket);
    foreach (explode('/', trim(str_replace('\\', '/', $objectKey), '/')) as $segment) {
        $canonicalUri .= '/' . rawurlencode($segment);
    }

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash_file('sha256', $sourcePath);
    if ($payloadHash === false) {
        fclose($stream);
        throw new RuntimeException('Failed to hash file for R2 upload.');
    }
    $contentType = $contentType !== '' ? $contentType : 'application/octet-stream';

    $canonicalHeaders =
        'content-type:' . $contentType . "\n" .
        'host:' . $host . "\n" .
        'x-amz-content-sha256:' . $payloadHash . "\n" .
        'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = "PUT\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = $algorithm . "\n" . $amzDate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);
    $signature = hash_hmac('sha256', $stringToSign, upload_storage_r2_signing_key($secretKey, $dateStamp, $region, $service));
    $authorization = $algorithm
        . ' Credential=' . $accessKey . '/' . $credentialScope
        . ', SignedHeaders=' . $signedHeaders
        . ', Signature=' . $signature;

    $size = filesize($sourcePath);
    if ($size === false) {
        fclose($stream);
        throw new RuntimeException('Failed to determine upload file size.');
    }

    $ch = curl_init('https://' . $host . $canonicalUri);
    curl_setopt_array($ch, [
        CURLOPT_UPLOAD => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_INFILE => $stream,
        CURLOPT_INFILESIZE => $size,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . $contentType,
            'Host: ' . $host,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
            'Authorization: ' . $authorization,
            'Content-Length: ' . $size,
            'Expect:',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    return ['curl' => $ch, 'stream' => $stream];
}

function upload_storage_r2_signing_key(string $secretKey, string $dateStamp, string $region, string $service): string
{
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    return hash_hmac('sha256', 'aws4_request', $kService, true);
}
