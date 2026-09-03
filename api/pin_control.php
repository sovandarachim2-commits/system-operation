<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$pdo = get_db_connection();
$me = current_user(true);
$isAdmin = (($me['username'] ?? '') === 'admin') || (($me['role'] ?? '') === 'admin');

function pin_ensure_settings_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            `key` VARCHAR(100) NOT NULL PRIMARY KEY,
            `value` TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function pin_get_setting(PDO $pdo, string $key): ?string
{
    pin_ensure_settings_table($pdo);
    $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string)$value;
}

function pin_set_setting(PDO $pdo, string $key, string $value): void
{
    pin_ensure_settings_table($pdo);
    $stmt = $pdo->prepare('
        INSERT INTO app_settings(`key`, `value`) VALUES(?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ');
    $stmt->execute([$key, $value]);
}

function pin_hash(string $pin): string
{
    return password_hash($pin, PASSWORD_DEFAULT);
}

function pin_verify(string $pin, string $hash): bool
{
    return password_verify($pin, $hash);
}

function pin_can_manage(): bool
{
    global $isAdmin;
    return $isAdmin
        || (function_exists('has_permission') && (
            has_permission('sr_pin_control.update')
            || has_permission('sr_role_permissions.update')
            || has_permission('role_permissions.update')
        ));
}

function pin_can_view_control(): bool
{
    global $isAdmin;
    return $isAdmin
        || pin_can_manage()
        || (function_exists('has_permission') && (
            has_permission('sr_pin_control.view')
            || has_permission('sr_role_permissions.view')
            || has_permission('role_permissions.view')
        ));
}

function pin_status_payload(PDO $pdo): array
{
    $hash = pin_get_setting($pdo, 'costing_pin_hash');
    $pinSet = is_string($hash) && $hash !== '';
    $unlocked = !empty($_SESSION['costing_financial_unlocked']);
    return [
        'success' => true,
        'pin_set' => $pinSet,
        'unlocked' => $pinSet ? $unlocked : true,
        'can_manage' => pin_can_manage(),
    ];
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string)$raw, true);
    $input = is_array($decoded) ? $decoded : $_POST;
}

if ($method === 'GET') {
    api_json(pin_status_payload($pdo));
}

if ($method !== 'POST') {
    api_error('Method not allowed.', 405);
}

$action = trim((string)($input['action'] ?? ''));

if ($action === 'verify') {
    $pin = trim((string)($input['pin'] ?? ''));
    $hash = pin_get_setting($pdo, 'costing_pin_hash');
    if (!is_string($hash) || $hash === '') {
        $_SESSION['costing_financial_unlocked'] = true;
        api_json(array_merge(pin_status_payload($pdo), [
            'message' => 'No PIN is set. Financial columns are visible.',
        ]));
    }
    if ($pin === '' || !preg_match('/^\d{4,8}$/', $pin)) {
        api_error('Enter a valid 4–8 digit PIN.', 422);
    }
    if (!pin_verify($pin, $hash)) {
        api_error('Incorrect PIN.', 403);
    }
    $_SESSION['costing_financial_unlocked'] = true;
    api_json(array_merge(pin_status_payload($pdo), [
        'message' => 'PIN accepted. Financial columns unlocked.',
    ]));
}

if ($action === 'lock') {
    unset($_SESSION['costing_financial_unlocked']);
    api_json(array_merge(pin_status_payload($pdo), [
        'message' => 'Financial columns locked.',
    ]));
}

if ($action === 'set_pin' || $action === 'clear_pin') {
    if (!pin_can_manage()) {
        api_error('You do not have permission to manage the PIN.', 403);
    }

    if ($action === 'clear_pin') {
        $hash = pin_get_setting($pdo, 'costing_pin_hash');
        $pinAlreadySet = is_string($hash) && $hash !== '';
        if ($pinAlreadySet) {
            $current = trim((string)($input['current_pin'] ?? ''));
            if ($current === '' || !pin_verify($current, (string)$hash)) {
                api_error('Current PIN is incorrect.', 403);
            }
        }
        pin_set_setting($pdo, 'costing_pin_hash', '');
        unset($_SESSION['costing_financial_unlocked']);
        api_json(array_merge(pin_status_payload($pdo), [
            'message' => 'PIN removed. Financial columns are always visible.',
        ]));
    }

    $pin = trim((string)($input['pin'] ?? ''));
    $confirm = trim((string)($input['confirm_pin'] ?? ''));
    $current = trim((string)($input['current_pin'] ?? ''));
    $hash = pin_get_setting($pdo, 'costing_pin_hash');
    $pinAlreadySet = is_string($hash) && $hash !== '';

    if ($pinAlreadySet) {
        if ($current === '' || !pin_verify($current, (string)$hash)) {
            api_error('Current PIN is incorrect.', 403);
        }
    }

    if (!preg_match('/^\d{4,8}$/', $pin)) {
        api_error('New PIN must be 4–8 digits.', 422);
    }
    if ($pin !== $confirm) {
        api_error('PIN confirmation does not match.', 422);
    }

    pin_set_setting($pdo, 'costing_pin_hash', pin_hash($pin));
    unset($_SESSION['costing_financial_unlocked']);
    api_json(array_merge(pin_status_payload($pdo), [
        'message' => 'PIN saved. Financial columns are locked until unlocked.',
    ]));
}

if ($action === 'status') {
    if (!pin_can_view_control() && !pin_can_manage()) {
        // Any logged-in user can check unlock status for costing pages.
        api_json(pin_status_payload($pdo));
    }
    api_json(pin_status_payload($pdo));
}

api_error('Unsupported action.', 400);
