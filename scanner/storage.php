<?php

function scanner_storage_driver(): string
{
    $driver = isset($GLOBALS['SCANNER_STORAGE_DRIVER']) ? strtolower(trim((string)$GLOBALS['SCANNER_STORAGE_DRIVER'])) : 'local';
    return $driver === 'r2' ? 'r2' : 'local';
}

function scanner_storage_upload_subdir_from_datetime(string $dateTime): string
{
    $ts = strtotime($dateTime);
    if ($ts === false) {
        $ts = time();
    }
    return date('Y/m', $ts);
}

function scanner_storage_object_prefix(): string
{
    $prefix = isset($GLOBALS['SCANNER_R2_OBJECT_PREFIX']) ? trim((string)$GLOBALS['SCANNER_R2_OBJECT_PREFIX']) : 'scanner';
    return trim(str_replace('\\', '/', $prefix), '/');
}

function scanner_storage_public_base_url(): string
{
    $base = isset($GLOBALS['SCANNER_R2_PUBLIC_BASE_URL']) ? trim((string)$GLOBALS['SCANNER_R2_PUBLIC_BASE_URL']) : '';
    return rtrim($base, '/');
}

function scanner_storage_is_remote_path(string $path): bool
{
    return preg_match('#^https?://#i', $path) === 1;
}

function scanner_storage_build_public_url(string $storedPath): string
{
    if ($storedPath === '' || scanner_storage_is_remote_path($storedPath)) {
        return $storedPath;
    }

    $relative = ltrim(str_replace('\\', '/', $storedPath), '/');
    $base = scanner_storage_public_base_url();
    $objectPrefix = scanner_storage_object_prefix();

    if (strncmp($relative, 'uploads/', 8) === 0) {
        if (scanner_storage_driver() === 'r2' && $base !== '') {
            $prefixed = ($objectPrefix !== '' ? $objectPrefix . '/' : '') . $relative;
            return $base . '/' . ltrim($prefixed, '/');
        }
        return $relative;
    }

    if ($base === '') {
        return $relative;
    }

    return $base . '/' . $relative;
}

function scanner_storage_resolve_public_url(string $storedPath): string
{
    if ($storedPath === '') {
        return '';
    }

    $base = scanner_storage_public_base_url();
    if (scanner_storage_is_remote_path($storedPath)) {
        if ($base === '') {
            return $storedPath;
        }

        $storedHost = (string)parse_url($storedPath, PHP_URL_HOST);
        $baseHost = (string)parse_url($base, PHP_URL_HOST);
        if ($storedHost !== '' && strcasecmp($storedHost, $baseHost) === 0) {
            return $storedPath;
        }

        $path = (string)parse_url($storedPath, PHP_URL_PATH);
        return $base . '/' . ltrim($path, '/');
    }

    return scanner_storage_build_public_url($storedPath);
}

function scanner_storage_local_absolute_path(string $storedPath): string
{
    $relative = ltrim(str_replace('\\', '/', $storedPath), '/');
    return __DIR__ . '/' . $relative;
}

function scanner_storage_store_uploaded_file(array $file, string $prefix, string $dateTime): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return '';
    }

    $ext = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = 'jpg';
    }

    $subdir = scanner_storage_upload_subdir_from_datetime($dateTime);
    $filename = uniqid($prefix, true) . '.' . $ext;

    if (scanner_storage_driver() === 'r2') {
        $objectPrefix = scanner_storage_object_prefix();
        $key = ($objectPrefix !== '' ? $objectPrefix . '/' : '') . $subdir . '/' . $filename;
        scanner_storage_r2_put_object($tmpName, $key, (string)($file['type'] ?? 'image/jpeg'));
        return $key;
    }

    $relativeDir = 'uploads/' . $subdir;
    $targetDir = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Failed to create upload directory: ' . $targetDir);
    }

    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Failed to store uploaded file locally.');
    }

    return $relativeDir . '/' . $filename;
}

function scanner_storage_r2_put_object(string $sourcePath, string $objectKey, string $contentType = 'application/octet-stream'): void
{
    $upload = scanner_storage_r2_create_upload_handle($sourcePath, $objectKey, $contentType);
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

function scanner_storage_r2_create_upload_handle(string $sourcePath, string $objectKey, string $contentType = 'application/octet-stream'): array
{
    $accountId = trim((string)($GLOBALS['SCANNER_R2_ACCOUNT_ID'] ?? ''));
    $bucket = trim((string)($GLOBALS['SCANNER_R2_BUCKET'] ?? ''));
    $accessKey = trim((string)($GLOBALS['SCANNER_R2_ACCESS_KEY_ID'] ?? ''));
    $secretKey = trim((string)($GLOBALS['SCANNER_R2_SECRET_ACCESS_KEY'] ?? ''));

    if ($accountId === '' || $bucket === '' || $accessKey === '' || $secretKey === '') {
        throw new RuntimeException('R2 storage is enabled, but scanner R2 credentials are incomplete.');
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
        throw new RuntimeException('Failed to hash uploaded file for R2 upload.');
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

    $signingKey = scanner_storage_r2_signing_key($secretKey, $dateStamp, $region, $service);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);
    $authorization = $algorithm
        . ' Credential=' . $accessKey . '/' . $credentialScope
        . ', SignedHeaders=' . $signedHeaders
        . ', Signature=' . $signature;

    $size = filesize($sourcePath);
    if ($size === false) {
        fclose($stream);
        throw new RuntimeException('Failed to determine upload file size.');
    }

    $url = 'https://' . $host . $canonicalUri;
    $ch = curl_init($url);
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

    return [
        'curl' => $ch,
        'stream' => $stream,
    ];
}

function scanner_storage_r2_object_exists(string $objectKey): bool
{
    $accountId = trim((string)($GLOBALS['SCANNER_R2_ACCOUNT_ID'] ?? ''));
    $bucket = trim((string)($GLOBALS['SCANNER_R2_BUCKET'] ?? ''));
    $accessKey = trim((string)($GLOBALS['SCANNER_R2_ACCESS_KEY_ID'] ?? ''));
    $secretKey = trim((string)($GLOBALS['SCANNER_R2_SECRET_ACCESS_KEY'] ?? ''));

    if ($accountId === '' || $bucket === '' || $accessKey === '' || $secretKey === '') {
        throw new RuntimeException('R2 storage is enabled, but scanner R2 credentials are incomplete.');
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
    $payloadHash = hash('sha256', '');
    $canonicalHeaders =
        'host:' . $host . "\n" .
        'x-amz-content-sha256:' . $payloadHash . "\n" .
        'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = "HEAD\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = $algorithm . "\n" . $amzDate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);

    $signingKey = scanner_storage_r2_signing_key($secretKey, $dateStamp, $region, $service);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);
    $authorization = $algorithm
        . ' Credential=' . $accessKey . '/' . $credentialScope
        . ', SignedHeaders=' . $signedHeaders
        . ', Signature=' . $signature;

    $url = 'https://' . $host . $canonicalUri;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_CUSTOMREQUEST => 'HEAD',
        CURLOPT_HTTPHEADER => [
            'Host: ' . $host,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
            'Authorization: ' . $authorization,
            'Expect:',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('R2 HEAD check failed: ' . $curlError);
    }

    if ($statusCode === 200) {
        return true;
    }

    if ($statusCode === 404) {
        return false;
    }

    throw new RuntimeException('R2 HEAD check failed with HTTP ' . $statusCode . '.');
}

function scanner_storage_r2_signing_key(string $secretKey, string $dateStamp, string $region, string $service): string
{
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    return hash_hmac('sha256', 'aws4_request', $kService, true);
}
