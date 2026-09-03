<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../upload_paths.php';

const REPORT_APP_LOGO_KEY = 'report_app_logo';
const REPORT_APP_NAME_KEY = 'report_app_name';
const REPORT_APP_NAME_DEFAULT = 'Shadow Report';

function report_app_logo_can_view(): bool
{
    $user = current_user() ?: [];
    if (in_array((string)($user['role'] ?? ''), ['admin'], true) || ($user['username'] ?? '') === 'admin') {
        return true;
    }
    return function_exists('has_permission') && (
        has_permission('logos.view')
        || has_permission('logos.update')
        || has_permission('sr_notification_settings.view')
    );
}

function report_app_logo_can_update(): bool
{
    $user = current_user() ?: [];
    if (in_array((string)($user['role'] ?? ''), ['admin'], true) || ($user['username'] ?? '') === 'admin') {
        return true;
    }
    return function_exists('has_permission') && (
        has_permission('logos.update')
        || has_permission('logos.create')
        || has_permission('sr_notification_settings.update')
    );
}

function report_app_logo_ensure(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            `key` VARCHAR(100) NOT NULL PRIMARY KEY,
            `value` TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function report_app_setting_get(PDO $pdo, string $key): string
{
    report_app_logo_ensure($pdo);
    $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    return trim((string)($stmt->fetchColumn() ?: ''));
}

function report_app_setting_set(PDO $pdo, string $key, string $value): void
{
    report_app_logo_ensure($pdo);
    $stmt = $pdo->prepare('
        INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ');
    $stmt->execute([$key, $value]);
}

function report_app_name_clean(mixed $value): string
{
    $clean = preg_replace('/\s+/', ' ', (string)$value);
    $name = trim(is_string($clean) ? $clean : '');
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 40) : substr($name, 0, 40);
    return $name;
}

function report_app_logo_path(PDO $pdo): string
{
    return report_app_setting_get($pdo, REPORT_APP_LOGO_KEY);
}

function report_app_logo_url(PDO $pdo): string
{
    $path = report_app_logo_path($pdo);
    if ($path === '') {
        return '';
    }
    return trim((string)uploaded_file_url($path, 'logos'));
}

function report_app_logo_payload(PDO $pdo): array
{
    $url = report_app_logo_url($pdo);
    $name = report_app_setting_get($pdo, REPORT_APP_NAME_KEY);
    return [
        'success' => true,
        'logo_url' => $url,
        'app_name' => $name !== '' ? $name : REPORT_APP_NAME_DEFAULT,
        'icon_url' => rtrim((string)($GLOBALS['BASE_URL'] ?? ''), '/') . '/api/report_app_icon.php',
    ];
}

$pdo = get_db_connection();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    api_json(report_app_logo_payload($pdo));
}

require_login();

if ($method !== 'POST') {
    api_error('Method not allowed.', 405);
}

if (!report_app_logo_can_update()) {
    api_error('You do not have permission to change the app logo.', 403);
}

$action = trim((string)($_POST['action'] ?? ''));
$json = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($json)) {
    $json = [];
}
if ($action === '' && isset($json['action'])) {
    $action = trim((string)$json['action']);
}

if ($action === 'save_name') {
    $name = report_app_name_clean($json['app_name'] ?? $_POST['app_name'] ?? '');
    if ($name === '') {
        $stmt = $pdo->prepare('DELETE FROM app_settings WHERE `key` = ?');
        $stmt->execute([REPORT_APP_NAME_KEY]);
    } else {
        report_app_setting_set($pdo, REPORT_APP_NAME_KEY, $name);
    }
    api_json(report_app_logo_payload($pdo) + ['message' => 'App name saved.']);
}

if ($action === 'clear') {
    report_app_logo_ensure($pdo);
    $stmt = $pdo->prepare('DELETE FROM app_settings WHERE `key` = ?');
    $stmt->execute([REPORT_APP_LOGO_KEY]);
    api_json(report_app_logo_payload($pdo) + ['message' => 'Logo removed.']);
}

$file = $_FILES['file'] ?? null;
if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    api_error('Choose a PNG or JPG logo first.');
}

$ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
$type = strtolower((string)($file['type'] ?? ''));
if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
    api_error('Only PNG and JPG images are allowed.');
}
if ($type !== '' && !in_array($type, ['image/png', 'image/jpeg', 'image/jpg'], true)) {
    api_error('Only PNG and JPG images are allowed.');
}
if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
    api_error('Logo file must be 2 MB or smaller.');
}

$filename = 'report_app_' . bin2hex(random_bytes(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
$path = upload_store_uploaded_file($file, 'logos', $filename, null, (string)($file['type'] ?? ''));
if ($path === '') {
    api_error('Unable to save logo.', 500);
}

report_app_setting_set($pdo, REPORT_APP_LOGO_KEY, $path);

api_json(report_app_logo_payload($pdo) + ['message' => 'Logo saved.']);
