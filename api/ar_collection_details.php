<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_role_or_permission(['admin'], 'sr_month_end_closing.view', 'sr_income_statement.view', 'sr_bank_balances.view', 'sr_inventory_closing.view');
$pdo = get_db_connection();
$me = current_user(true);
$canEdit = ($me['role'] ?? '') === 'admin' || has_permission('sr_month_end_closing.update');
$pdo->exec("CREATE TABLE IF NOT EXISTS ar_collection_assignments (
    source VARCHAR(20) NOT NULL, order_id BIGINT UNSIGNED NOT NULL,
    collection_by VARCHAR(255) NOT NULL DEFAULT '', due_date DATE NULL,
    updated_by BIGINT UNSIGNED NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (source, order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'GET') {
    $rows = $pdo->query('SELECT source, order_id, collection_by, due_date FROM ar_collection_assignments')->fetchAll(PDO::FETCH_ASSOC);
    if (api_table_exists($pdo, 'offline_sale_orders')) {
        $columns = $pdo->query('SHOW COLUMNS FROM offline_sale_orders')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('due_date', $columns, true) && in_array('collection_assigned_to', $columns, true)) {
            $offline = $pdo->query("SELECT 'offline' AS source, id AS order_id, collection_assigned_to AS collection_by, due_date FROM offline_sale_orders")->fetchAll(PDO::FETCH_ASSOC);
            $rows = array_merge($rows, $offline);
        }
    }
    api_json(['success' => true, 'rows' => $rows, 'can_edit' => $canEdit]);
}
if ($method !== 'POST') api_error('Method not allowed.', 405);
if (!$canEdit) api_error('You do not have permission to edit collection assignments.', 403);
$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) api_error('Invalid request.', 422);
$source = strtolower(trim((string)($input['source'] ?? '')));
$tables = ['online' => 'orders', 'offline' => 'offline_sale_orders', 'dealer' => 'dealer_orders'];
$id = (int)($input['order_id'] ?? 0);
if (!isset($tables[$source]) || $id <= 0) api_error('Invalid invoice.', 422);
$collector = trim((string)($input['collection_by'] ?? ''));
if ((function_exists('mb_strlen') ? mb_strlen($collector) : strlen($collector)) > 255) api_error('Collection By must be 255 characters or fewer.', 422);
$due = trim((string)($input['due_date'] ?? ''));
if ($due !== '') {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $due);
    if (!$date || $date->format('Y-m-d') !== $due) api_error('Enter a valid due date.', 422);
}
$table = $tables[$source];
$check = $pdo->prepare("SELECT id FROM `$table` WHERE id = ?");
$check->execute([$id]);
if (!$check->fetchColumn()) api_error('Invoice not found.', 404);
$pdo->beginTransaction();
try {
    if ($source === 'offline') {
        $columns = $pdo->query('SHOW COLUMNS FROM offline_sale_orders')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('due_date', $columns, true) && in_array('collection_assigned_to', $columns, true)) {
            $stmt = $pdo->prepare('UPDATE offline_sale_orders SET due_date = ?, collection_assigned_to = ? WHERE id = ?');
            $stmt->execute([$due ?: null, $collector ?: null, $id]);
        }
    }
    $stmt = $pdo->prepare('INSERT INTO ar_collection_assignments (source, order_id, collection_by, due_date, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE collection_by = VALUES(collection_by), due_date = VALUES(due_date), updated_by = VALUES(updated_by)');
    $stmt->execute([$source, $id, $collector, $due ?: null, $me['id'] ?? null]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_error('Unable to save collection details.', 500);
}
api_json(['success' => true, 'collection_by' => $collector, 'due_date' => $due ?: null]);
