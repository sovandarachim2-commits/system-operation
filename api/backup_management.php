<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/backup_management_lib.php';

function osb_api_user_can(string ...$perms): bool
{
    $user = current_user();
    if (!$user) {
        api_error('Please log in again.', 401);
    }
    $roles = user_role_names(get_db_connection(), $user);
    if (in_array('admin', $roles, true) || (string)($user['username'] ?? '') === 'admin') {
        return true;
    }
    foreach ($perms as $perm) {
        if (has_permission($perm)) {
            return true;
        }
    }
    return false;
}

function osb_api_require(string ...$perms): void
{
    if (!osb_api_user_can(...$perms)) {
        api_error('You do not have permission for Backup Management.', 403);
    }
}

function osb_api_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : [];
}

$viewPerms = [
    'sr_ordershadow_backup_status.view',
    'sr_ordershadow_backup_servers.view',
    'sr_ordershadow_backup_history.view',
    'sr_ordershadow_backup_report.view',
    'sr_ordershadow_backup_telegram.view',
];

try {
    $action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
    if ($action === '') {
        $body = osb_api_body();
        $action = (string)($body['action'] ?? 'status');
    } else {
        $body = osb_api_body();
    }

    if ($action === 'ingest') {
        $run = osb_ingest(
            (string)($body['token'] ?? ''),
            trim((string)($body['name'] ?? '')),
            (string)($body['status'] ?? ''),
            (int)($body['size_bytes'] ?? 0),
            trim((string)($body['error'] ?? ''))
        );
        api_json(['success' => true, 'run' => $run]);
    }

    osb_api_require(...$viewPerms);

    if ($action === 'download') {
        $path = osb_download_path((int)($_GET['id'] ?? 0));
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    if ($action === 'status') {
        api_json([
            'success' => true,
            'rows' => osb_status_rows(),
            'file_example' => osb_backup_filename(),
            'app_name' => osb_app_label(),
        ]);
    }
    if ($action === 'servers') {
        api_json(['success' => true, 'servers' => osb_servers(), 'database_name' => (string)($GLOBALS['DB_NAME'] ?? '')]);
    }
    if ($action === 'history') {
        api_json(['success' => true, 'runs' => osb_history()]);
    }
    if ($action === 'report') {
        api_json(['success' => true, 'body' => osb_build_summary(), 'log' => osb_report_log()]);
    }
    if ($action === 'telegram') {
        api_json(['success' => true, 'settings' => osb_public_settings()]);
    }
    if ($action === 'save_server') {
        osb_api_require('sr_ordershadow_backup_servers.update', 'sr_ordershadow_backup_servers.create');
        api_json(['success' => true, 'server' => osb_save_server($body)]);
    }
    if ($action === 'delete_server') {
        osb_api_require('sr_ordershadow_backup_servers.delete');
        osb_delete_server((int)($body['id'] ?? 0));
        api_json(['success' => true]);
    }
    if ($action === 'run') {
        osb_api_require('sr_ordershadow_backup_status.update');
        set_time_limit(0);
        $id = (int)($body['id'] ?? 0);
        $runs = $id > 0 ? [osb_run_server($id)] : osb_run_all();
        api_json(['success' => true, 'runs' => $runs, 'rows' => osb_status_rows()]);
    }
    if ($action === 'send_report') {
        osb_api_require('sr_ordershadow_backup_report.update');
        $result = osb_send_daily_report();
        if (!$result['ok']) {
            api_error($result['error'] !== '' ? $result['error'] : 'Telegram did not accept the report.', 502, ['body' => $result['body']]);
        }
        api_json(['success' => true, 'body' => $result['body'], 'log' => osb_report_log()]);
    }
    if ($action === 'save_telegram') {
        osb_api_require('sr_ordershadow_backup_telegram.update');
        api_json(['success' => true, 'settings' => osb_save_settings($body)]);
    }
    if ($action === 'test_telegram') {
        osb_api_require('sr_ordershadow_backup_telegram.update');
        osb_send_text(osb_app_label() . " backup test\n" . date('d M Y, h:i A'));
        api_json(['success' => true, 'message' => 'Test message sent to the Telegram group.']);
    }

    api_error('Unknown backup action.', 404);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}
