<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';
require_once __DIR__ . '/../helpers.php';

header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

function report_app_icon_redirect(string $url): void
{
    if ($url === '') {
        http_response_code(404);
        exit;
    }
    header('Location: ' . $url, true, 302);
    exit;
}

try {
    $pdo = get_db_connection();
    $path = '';
    try {
        $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
        $stmt->execute(['report_app_logo']);
        $path = trim((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $path = '';
    }

    $url = $path !== '' ? trim((string)uploaded_file_url($path, 'logos')) : '';
    if ($url === '' && function_exists('get_default_logo')) {
        $logo = get_default_logo($pdo);
        if ($logo && !empty($logo['file_path'])) {
            $url = trim((string)uploaded_file_url((string)$logo['file_path'], 'logos'));
        }
    }

    report_app_icon_redirect($url);
} catch (Throwable $e) {
    http_response_code(404);
    exit;
}
