<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/product_costing_lib.php';
require_login();

function pcur_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_cost_unlock_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        channel VARCHAR(20) NOT NULL,
        month_year VARCHAR(7) NOT NULL,
        reason TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        requested_by INT NULL,
        requester_name VARCHAR(120) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT NULL,
        reviewer_name VARCHAR(120) NULL,
        reviewed_at TIMESTAMP NULL,
        KEY idx_product_cost_unlock_report (channel, month_year, status),
        KEY idx_product_cost_unlock_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pcur_channel(mixed $value): string
{
    $channel = strtolower(trim((string)$value));
    if (!in_array($channel, ['online', 'offline', 'dealer'], true)) api_error('Invalid product costing channel.', 422);
    return $channel;
}

function pcur_user_label(?array $user): string
{
    return trim((string)(($user['name'] ?? '') ?: ($user['username'] ?? '')));
}

function pcur_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

$pdo = get_db_connection();
pcur_ensure_schema($pdo);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require_role_or_permission(['admin'], 'sr_unlock_requests.view');
    $status = strtolower(trim((string)($_GET['status'] ?? 'all')));
    $where = $status === 'all' ? '' : 'WHERE r.status = ?';
    $stmt = $pdo->prepare("SELECT r.id, r.id AS request_id, r.channel, r.month_year AS month,
        CONCAT(UPPER(LEFT(r.channel, 1)), SUBSTRING(r.channel, 2), ' Product Costing') AS title,
        r.reason, r.reason AS note, r.status, r.requester_name, r.requested_by AS requested_by_id, r.created_at,
        r.requested_by, r.reviewer_name, r.reviewed_at
        FROM product_cost_unlock_requests r $where ORDER BY FIELD(r.status, 'pending', 'approved', 'rejected'), r.created_at DESC");
    $stmt->execute($status === 'all' ? [] : [$status]);
    api_json(['success' => true, 'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

$input = pcur_input();
$action = trim((string)($input['action'] ?? ''));
$user = current_user() ?: [];
$userId = isset($user['id']) ? (int)$user['id'] : null;
$userName = pcur_user_label($user);
if ($action === 'request_unlock') {
    $channel = pcur_channel($input['channel'] ?? '');
    $permission = ['online' => 'sr_product_costing.update', 'offline' => 'sr_offline_product_costing.update', 'dealer' => 'sr_dealer_product_costing.update'][$channel];
    require_role_or_permission(['admin'], $permission, 'product_costs.update');
    $month = pc_lock_month_value($input['month_year'] ?? null);
    if ($month === '') api_error('A valid month is required.', 422);
    $reason = trim((string)($input['note'] ?? ''));
    if ($reason === '') api_error('Unlock reason is required.', 422);
    $lock = pc_lock_row($pdo, $channel, $month);
    if (!pc_lock_is_locked($lock)) api_error('This month is not locked.', 409);
    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare("SELECT status FROM product_cost_locks WHERE channel = ? AND month_year = ? FOR UPDATE");
        $check->execute([$channel, $month]);
        if (strtoupper((string)$check->fetchColumn()) !== 'LOCKED') { $pdo->rollBack(); api_error('This month is no longer locked.', 409); }
        $duplicate = $pdo->prepare("SELECT id FROM product_cost_unlock_requests WHERE channel = ? AND month_year = ? AND status = 'pending' LIMIT 1");
        $duplicate->execute([$channel, $month]);
        if ($duplicate->fetchColumn()) { $pdo->rollBack(); api_error('An unlock request for this report and month is already pending.', 409); }
        $stmt = $pdo->prepare("INSERT INTO product_cost_unlock_requests (channel, month_year, reason, requested_by, requester_name) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$channel, $month, $reason, $userId, $userName]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((string)$e->getCode() === '23000') api_error('An unlock request for this report and month is already pending.', 409);
        throw $e;
    }
    api_json(['success' => true, 'message' => 'Unlock request submitted for approval.', 'request' => ['status' => 'pending', 'note' => $reason]]);
}

if (in_array($action, ['approve', 'reject'], true)) {
    require_role_or_permission(['admin'], 'sr_unlock_requests.approve');
    $requestId = (int)($input['request_id'] ?? 0);
    if ($requestId <= 0) api_error('Invalid unlock request.', 422);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM product_cost_unlock_requests WHERE id = ? FOR UPDATE");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) { $pdo->rollBack(); api_error('Unlock request not found.', 404); }
        if (($request['status'] ?? '') !== 'pending') { $pdo->rollBack(); api_error('This request has already been reviewed.', 409); }
        if ($action === 'approve') {
            $lock = $pdo->prepare("UPDATE product_cost_locks SET status = 'OPEN', unlocked_by = ?, unlocked_by_name = ?, unlocked_at = CURRENT_TIMESTAMP, note = ? WHERE channel = ? AND month_year = ? AND status = 'LOCKED'");
            $lock->execute([$userId, $userName, (string)$request['reason'], (string)$request['channel'], (string)$request['month_year']]);
            if ($lock->rowCount() !== 1) { $pdo->rollBack(); api_error('This costing month is no longer locked.', 409); }
        }
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $pdo->prepare('UPDATE product_cost_unlock_requests SET status = ?, reviewed_by = ?, reviewer_name = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$status, $userId, $userName, $requestId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    api_json(['success' => true, 'message' => $action === 'approve' ? 'Unlock request approved.' : 'Unlock request rejected.']);
}
api_error('Unknown unlock request action.', 422);