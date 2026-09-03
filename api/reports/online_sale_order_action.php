<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../user_activity_lib.php';

require_role_or_permission(['admin', 'seller'], 'seller_orders.update', 'orders.update', 'sr_orders.update');

function online_sale_action_payload(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error('Invalid order action payload.', 422);
    }
    return $data;
}

function online_sale_action_default_location_id(PDO $pdo): int
{
    $stmt = $pdo->query('SELECT id FROM storage_locations WHERE is_default = 1 LIMIT 1');
    return (int)($stmt->fetchColumn() ?: 0);
}

function online_sale_action_upsert_inventory(PDO $pdo, int $productId, string $productName, float $quantityDelta, int $locationId, int $userId): void
{
    $inventoryStmt = $pdo->prepare('
        SELECT id, quantity_on_hand
        FROM current_inventory
        WHERE item_name = ? AND storage_location_id = ?
        ORDER BY id ASC
        LIMIT 1
    ');
    $inventoryStmt->execute([$productName, $locationId]);
    $inventoryRow = $inventoryStmt->fetch(PDO::FETCH_ASSOC);

    if ($inventoryRow) {
        $newQuantity = (float)$inventoryRow['quantity_on_hand'] + $quantityDelta;
        if ($newQuantity < 0) {
            throw new RuntimeException("Insufficient inventory to reverse return for {$productName}");
        }

        $updateStmt = $pdo->prepare('
            UPDATE current_inventory
            SET quantity_on_hand = ?,
                last_updated = NOW(),
                updated_by = ?
            WHERE id = ?
        ');
        $updateStmt->execute([$newQuantity, $userId, $inventoryRow['id']]);
        return;
    }

    if ($quantityDelta < 0) {
        throw new RuntimeException("Inventory record not found for {$productName}");
    }

    $productStmt = $pdo->prepare('SELECT sku, cost FROM products WHERE id = ? LIMIT 1');
    $productStmt->execute([$productId]);
    $productRow = $productStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $insertStmt = $pdo->prepare('
        INSERT INTO current_inventory (item_name, sku, storage_location_id, quantity_on_hand, unit_cost, updated_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $insertStmt->execute([
        $productName,
        $productRow['sku'] ?? null,
        $locationId,
        $quantityDelta,
        $productRow['cost'] ?? 0,
        $userId,
    ]);
}

function online_sale_action_apply_return_inventory(PDO $pdo, int $orderId, int $userId): void
{
    $locationId = online_sale_action_default_location_id($pdo);
    if ($locationId <= 0) {
        throw new RuntimeException('Default storage location is required for order returns');
    }

    $orderStmt = $pdo->prepare('SELECT order_code, is_returned FROM orders WHERE id = ? LIMIT 1');
    $orderStmt->execute([$orderId]);
    $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$orderRow) {
        throw new RuntimeException('Order not found');
    }
    if ((int)$orderRow['is_returned'] === 1) {
        throw new RuntimeException('Order is already marked as returned');
    }

    $itemsStmt = $pdo->prepare("
        SELECT oi.product_id, oi.quantity AS order_quantity, p.name AS product_name, COALESCE(p.product_type, 'normal') AS product_type
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $orderedQuantity = (float)$item['order_quantity'];

        if ($item['product_type'] === 'set') {
            $setStmt = $pdo->prepare('SELECT id FROM product_sets WHERE set_name = ? LIMIT 1');
            $setStmt->execute([$item['product_name']]);
            $setId = (int)($setStmt->fetchColumn() ?: 0);
            if ($setId <= 0) {
                throw new RuntimeException("Product set not found for {$item['product_name']}");
            }

            $componentsStmt = $pdo->prepare('
                SELECT psi.product_id, psi.quantity, p.name AS product_name
                FROM product_set_items psi
                JOIN products p ON p.id = psi.product_id
                WHERE psi.product_set_id = ?
            ');
            $componentsStmt->execute([$setId]);
            $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$components) {
                throw new RuntimeException("No components found for product set {$item['product_name']}");
            }

            foreach ($components as $component) {
                $componentQuantity = (float)$component['quantity'] * $orderedQuantity;
                online_sale_action_upsert_inventory($pdo, (int)$component['product_id'], (string)$component['product_name'], $componentQuantity, $locationId, $userId);

                $logStmt = $pdo->prepare("
                    INSERT INTO stock_operations
                    (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by)
                    VALUES (?, 'return_component_in', ?, 'order_return', ?, ?, ?)
                ");
                $logStmt->execute([
                    $locationId,
                    abs($componentQuantity),
                    $orderId,
                    'Returned set component restored for order ' . $orderRow['order_code'] . ' - ' . $component['product_name'],
                    $userId,
                ]);
            }

            continue;
        }

        online_sale_action_upsert_inventory($pdo, (int)$item['product_id'], (string)$item['product_name'], $orderedQuantity, $locationId, $userId);
        $logStmt = $pdo->prepare("
            INSERT INTO stock_operations
            (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by)
            VALUES (?, 'return_in', ?, 'order_return', ?, ?, ?)
        ");
        $logStmt->execute([
            $locationId,
            abs($orderedQuantity),
            $orderId,
            'Returned product restored for order ' . $orderRow['order_code'] . ' - ' . $item['product_name'],
            $userId,
        ]);
    }
}

try {
    $pdo = get_db_connection();
    $user = current_user();
    if (!$user) {
        api_error('Please log in again.', 401, ['error' => 'session_expired']);
    }

    $payload = online_sale_action_payload();
    $orderId = filter_var($payload['order_id'] ?? null, FILTER_VALIDATE_INT);
    $action = strtolower(trim((string)($payload['action'] ?? '')));
    $reason = trim((string)($payload['reason'] ?? ''));

    if ($orderId === false || $orderId === null || $orderId <= 0) {
        api_error('Order is required.', 422);
    }
    if (!in_array($action, ['cancel', 'return'], true)) {
        api_error('Order action is required.', 422);
    }
    if ($reason === '') {
        api_error($action === 'return' ? 'Return reason is required.' : 'Cancellation reason is required.', 422);
    }

    $canEditAnyOrder = ($user['role'] ?? '') === 'admin'
        || (function_exists('rbac_is_enabled') && rbac_is_enabled($pdo) && has_permission('orders.update'));

    $loadSql = 'SELECT id, order_code, seller_id, is_cancelled, is_returned FROM orders WHERE id = ?';
    $loadParams = [(int)$orderId];
    if (!$canEditAnyOrder) {
        $loadSql .= ' AND seller_id = ?';
        $loadParams[] = (int)$user['id'];
    }
    $loadSql .= ' LIMIT 1';
    $loadStmt = $pdo->prepare($loadSql);
    $loadStmt->execute($loadParams);
    $order = $loadStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        api_error('Order not found or you do not have permission to update it.', 404);
    }
    if ((int)($order['is_cancelled'] ?? 0) === 1 || (int)($order['is_returned'] ?? 0) === 1) {
        api_error('This order is already cancelled or returned.', 422);
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_edit_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            user_id INT NULL,
            user_name VARCHAR(255) NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $auditTableError) {
        error_log('online_sale_order_action audit table warning: ' . $auditTableError->getMessage());
    }

    $pdo->beginTransaction();
    if ($action === 'return') {
        online_sale_action_apply_return_inventory($pdo, (int)$orderId, (int)$user['id']);
        $stmt = $pdo->prepare('UPDATE orders SET is_returned = 1, return_note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$reason, (int)$user['id'], (int)$orderId]);
        $message = 'Order returned successfully.';
    } else {
        $stmt = $pdo->prepare("
            UPDATE orders
            SET is_cancelled = 1,
                status = 'cancelled',
                is_paid = 0,
                payment_method = NULL,
                paid_note = NULL,
                payment_date = NULL,
                cancel_note = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$reason, (int)$user['id'], (int)$orderId]);
        $message = 'Order cancelled successfully.';
    }
    try {
        $auditStmt = $pdo->prepare('INSERT INTO order_edit_audit (order_id, user_id, user_name, action, details) VALUES (?, ?, ?, ?, ?)');
        $auditStmt->execute([
            (int)$orderId,
            (int)$user['id'],
            $user['name'] ?? $user['username'] ?? null,
            $action === 'return' ? 'order_return_report_native' : 'order_cancel_report_native',
            json_encode([
                'reason' => $reason,
                'status' => $action === 'return' ? 'Return' : 'Cancel',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $auditError) {
        error_log('online_sale_order_action audit warning: ' . $auditError->getMessage());
    }
    $pdo->commit();

    user_activity_log_module_mutation(
        $user,
        'seller',
        'update',
        __FILE__,
        ($action === 'return' ? 'returned order ' : 'cancelled order ') . (string)($order['order_code'] ?? $orderId)
    );

    api_json([
        'success' => true,
        'message' => $message,
        'order' => [
            'id' => (int)$orderId,
            'order_code' => (string)($order['order_code'] ?? ''),
            'order_status' => $action === 'return' ? 'Return' : 'Cancel',
            'is_returned' => $action === 'return' ? 1 : 0,
            'is_cancelled' => $action === 'cancel' ? 1 : 0,
            'return_note' => $action === 'return' ? $reason : '',
            'cancel_note' => $action === 'cancel' ? $reason : '',
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('online_sale_order_action API error: ' . $e->getMessage());
    api_error('Unable to update order.', 500);
}
