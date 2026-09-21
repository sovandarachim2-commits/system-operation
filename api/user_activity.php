<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../user_activity_lib.php';

require_role_or_permission(['admin'], 'users_activity.view', 'sr_user_activity.view');

$pdo = get_db_connection();
user_activity_ensure_table($pdo);

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$action_q = trim($_GET['action'] ?? '');
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$quick_range = strtolower(trim((string)($_GET['quick_range'] ?? '')));
$filter_month = (int)($_GET['filter_month'] ?? 0);
$filter_year = (int)($_GET['filter_year'] ?? 0);
$log_type = strtolower(trim((string)($_GET['log_type'] ?? '')));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = api_int('per_page', 100, 50, 500);

$quick_range_valid = ['today', 'yesterday', 'last7', 'month'];
if (!in_array($quick_range, $quick_range_valid, true)) {
    $quick_range = '';
}
if ($filter_month < 1 || $filter_month > 12) {
    $filter_month = (int)date('n');
}
if ($filter_year < 2000 || $filter_year > 2100) {
    $filter_year = (int)date('Y');
}

if ($quick_range !== '') {
    $today = date('Y-m-d');
    switch ($quick_range) {
        case 'today':
            $start_date = $today;
            $end_date = $today;
            break;
        case 'yesterday':
            $d = date('Y-m-d', strtotime('-1 day'));
            $start_date = $d;
            $end_date = $d;
            break;
        case 'last7':
            $end_date = $today;
            $start_date = date('Y-m-d', strtotime('-6 days'));
            break;
        case 'month':
            $start_date = sprintf('%04d-%02d-01', $filter_year, $filter_month);
            $end_date = date('Y-m-t', strtotime($start_date));
            break;
    }
}

if ($log_type !== '' && !in_array($log_type, user_activity_log_type_keys(), true)) {
    $log_type = '';
}

$params = [];
$where = [];

if ($user_id > 0) {
    $where[] = 'user_id = ?';
    $params[] = $user_id;
}
if ($action_q !== '') {
    $where[] = 'action LIKE ?';
    $params[] = '%' . $action_q . '%';
}
[$logTypeSql, $logTypeParams] = user_activity_log_type_sql($log_type);
if ($logTypeSql !== '') {
    $where[] = '(' . $logTypeSql . ')';
    foreach ($logTypeParams as $lp) {
        $params[] = $lp;
    }
}
if ($start_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $where[] = 'created_at >= ?';
    $params[] = $start_date . ' 00:00:00';
}
if ($end_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $endTs = strtotime($end_date . ' 23:59:59');
    if ($endTs !== false) {
        $where[] = 'created_at <= ?';
        $params[] = date('Y-m-d H:i:s', $endTs);
    }
}

$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM user_activity_log' . $whereSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql = 'SELECT user_id, user_name, action, details, context, ip_address, device, device_name, device_model, user_agent, request_uri, frontend_url, created_at'
    . ' FROM user_activity_log' . $whereSql . ' ORDER BY id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$userPickerList = $pdo->query('SELECT id, name, username FROM users ORDER BY name ASC, username ASC')->fetchAll(PDO::FETCH_ASSOC);

$users = [];
foreach ($userPickerList as $uu) {
    $users[] = [
        'id' => (int)$uu['id'],
        'name' => (string)$uu['name'],
        'username' => (string)$uu['username'],
    ];
}

$logTypes = user_activity_log_type_options();
$logTypeKeys = user_activity_log_type_keys();

$activityRows = [];
foreach ($rows as $r) {
    $ltKey = user_activity_log_type_from_action((string)($r['action'] ?? ''));
    $dname = user_activity_display_device_name(
        isset($r['device_name']) ? (string)$r['device_name'] : null,
        isset($r['device']) ? (string)$r['device'] : null
    );
    $dmod = user_activity_display_device_model(
        isset($r['device_model']) ? (string)$r['device_model'] : null,
        isset($r['user_agent']) ? (string)$r['user_agent'] : null
    );

    $ip = (string)($r['ip_address'] ?? '');
    if ($ip === '::1') {
        $ip = '127.0.0.1';
    }

    $activityRows[] = [
        'user_id' => isset($r['user_id']) ? (int)$r['user_id'] : null,
        'user_name' => trim((string)($r['user_name'] ?? '')),
        'action' => (string)($r['action'] ?? ''),
        'log_type' => $ltKey,
        'details' => (string)($r['details'] ?? ''),
        'ip_address' => $ip,
        'device' => (string)($r['device'] ?? ''),
        'device_name' => $dname,
        'device_model' => $dmod,
        'request_uri' => (string)($r['request_uri'] ?? ''),
        'frontend_url' => (string)($r['frontend_url'] ?? ''),
        'created_at' => (string)($r['created_at'] ?? ''),
    ];
}

$actionListStmt = $pdo->query('SELECT DISTINCT action FROM user_activity_log ORDER BY action ASC');
$actionList = [];
foreach ($actionListStmt->fetchAll(PDO::FETCH_COLUMN) as $a) {
    $actionList[] = (string)$a;
}

api_json([
    'success' => true,
    'rows' => $activityRows,
    'users' => $users,
    'actions' => $actionList,
    'log_types' => $logTypes,
    'log_type_keys' => $logTypeKeys,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total_rows' => $totalRows,
        'total_pages' => $totalPages,
    ],
]);
