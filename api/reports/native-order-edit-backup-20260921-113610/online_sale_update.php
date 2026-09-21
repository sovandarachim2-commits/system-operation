<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../user_activity_lib.php';

require_role_or_permission(['admin', 'seller'], 'seller_orders.update', 'orders.update', 'sr_orders.update');

function online_sale_update_payload(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error('Invalid sale payload.', 422);
    }
    return $data;
}

function online_sale_update_trim(array $data, string $key): string
{
    return trim((string)($data[$key] ?? ''));
}

function online_sale_update_int(array $data, string $key): int
{
    $value = filter_var($data[$key] ?? null, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? 0 : (int)$value;
}

try {
    $pdo = get_db_connection();
    ensure_order_items_lucky_box_column($pdo);
    $user = current_user();
    if (!$user) {
        api_error('Please log in again.', 401, ['error' => 'session_expired']);
    }

    $payload = online_sale_update_payload();
    $orderId = online_sale_update_int($payload, 'order_id');
    if ($orderId <= 0) {
        api_error('Order ID is required.', 422);
    }

    $canEditAnyOrder = ($user['role'] ?? '') === 'admin'
        || (function_exists('rbac_is_enabled') && rbac_is_enabled($pdo) && has_permission('orders.update'));

    $loadSql = 'SELECT * FROM orders WHERE id = ?';
    $loadParams = [$orderId];
    if (!$canEditAnyOrder) {
        $loadSql .= ' AND seller_id = ?';
        $loadParams[] = (int)$user['id'];
    }
    $loadSql .= ' LIMIT 1';
    $loadStmt = $pdo->prepare($loadSql);
    $loadStmt->execute($loadParams);
    $order = $loadStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        api_error('Order not found or you do not have permission to edit it.', 404);
    }

    $printedStmt = $pdo->prepare('SELECT 1 FROM print_jobs WHERE order_id = ? LIMIT 1');
    $printedStmt->execute([$orderId]);
    if ($printedStmt->fetchColumn()) {
        api_error('Printed orders must be edited from the full order page so inventory stays correct.', 409);
    }

    $customerName = online_sale_update_trim($payload, 'customer_name');
    $phone = online_sale_update_trim($payload, 'phone');
    $location = online_sale_update_trim($payload, 'location');
    $pageId = online_sale_update_int($payload, 'page_id');
    $deliveryTypeId = online_sale_update_int($payload, 'delivery_type_id');
    $deliveryCostId = online_sale_update_int($payload, 'delivery_cost_id');
    $status = online_sale_update_trim($payload, 'status');
    $status = in_array($status, ['paid', 'unpaid'], true) ? $status : '';
    $paymentMethod = online_sale_update_trim($payload, 'payment_method');
    $paidNote = online_sale_update_trim($payload, 'paid_note');
    $paymentDate = online_sale_update_trim($payload, 'payment_date');
    $discount = max(0.0, (float)($payload['discount'] ?? 0));
    $submittedItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];

    $errors = [];
    if ($customerName === '') {
        $errors[] = 'Customer name is required.';
    }
    $phoneError = validate_customer_phones($phone);
    if ($phoneError !== null) {
        $errors[] = $phoneError;
    }
    if ($location === '') {
        $errors[] = 'Location is required.';
    }
    if ($pageId <= 0) {
        $errors[] = 'Page is required.';
    }
    if ($deliveryTypeId <= 0) {
        $errors[] = 'Delivery type is required.';
    }
    if ($deliveryCostId <= 0) {
        $errors[] = 'Delivery cost is required.';
    }
    if ($status === '') {
        $errors[] = 'Status is required.';
    }
    if ($status === 'paid' && $paymentMethod === '') {
        $errors[] = 'Payment method is required when status is Paid.';
    }
    if ($status === 'paid' && $paymentDate === '') {
        $errors[] = 'Payment date is required when status is Paid.';
    }
    if ($status !== 'paid') {
        $paymentMethod = '';
        $paidNote = '';
        $paymentDate = '';
    }

    $currentMonth = date('Y-m');
    $itemsByKey = [];
    foreach ($submittedItems as $submittedItem) {
        if (!is_array($submittedItem)) {
            continue;
        }
        $productId = filter_var($submittedItem['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = max(1, (int)($submittedItem['quantity'] ?? 1));
        $isLucky = (($submittedItem['line_mode'] ?? '') === 'lucky') ? 1 : 0;
        if ($productId === false || $productId === null || $productId <= 0) {
            continue;
        }

        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.name,
                COALESCE(pc.selling_price, p.cost, 0) AS cost,
                pc.month_year
            FROM products p
            LEFT JOIN product_costs pc ON pc.product_id = p.id AND pc.month_year = ?
            WHERE p.id = ? AND p.active = 1
            LIMIT 1
        ");
        $stmt->execute([$currentMonth, (int)$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            continue;
        }

        $key = (int)$product['id'] . '_' . $isLucky;
        $unitCost = (float)$product['cost'];
        if (!isset($itemsByKey[$key])) {
            $itemsByKey[$key] = [
                'product_id' => (int)$product['id'],
                'product_name' => (string)$product['name'],
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'line_total' => $unitCost * $quantity,
                'is_lucky_box' => $isLucky,
            ];
        } else {
            $itemsByKey[$key]['quantity'] += $quantity;
            $itemsByKey[$key]['line_total'] = $itemsByKey[$key]['unit_cost'] * $itemsByKey[$key]['quantity'];
        }
    }

    $items = array_values($itemsByKey);
    if (!$items) {
        $errors[] = 'Please add at least one sale product.';
    }

    if ($errors) {
        api_error(implode(' ', $errors), 422);
    }

    $costStmt = $pdo->prepare('SELECT amount FROM delivery_costs WHERE id = ?');
    $costStmt->execute([$deliveryCostId]);
    $deliveryAmount = (float)($costStmt->fetchColumn() ?: 0);
    $productsTotal = array_reduce($items, static fn(float $sum, array $item): float => $sum + (float)$item['line_total'], 0.0);
    $grandTotal = max(0.0, $productsTotal + $deliveryAmount - $discount);

    $existingItemsStmt = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
    $existingItemsStmt->execute([$orderId]);
    $existingItems = $existingItemsStmt->fetchAll(PDO::FETCH_ASSOC);

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
        error_log('online_sale_update audit table warning: ' . $auditTableError->getMessage());
    }

    $pdo->beginTransaction();
    $updateOrder = $pdo->prepare('
        UPDATE orders
        SET
            customer_name = ?,
            phone = ?,
            location = ?,
            page_id = ?,
            delivery_type_id = ?,
            delivery_cost_id = ?,
            status = ?,
            paid_note = ?,
            discount = ?,
            total_amount = ?,
            updated_by = ?,
            updated_at = NOW(),
            is_paid = ?,
            payment_method = ?,
            payment_date = ?
        WHERE id = ?
    ');
    $updateOrder->execute([
        $customerName,
        $phone,
        $location,
        $pageId ?: null,
        $deliveryTypeId ?: null,
        $deliveryCostId ?: null,
        $status,
        $paidNote ?: null,
        $discount,
        $grandTotal,
        (int)$user['id'],
        $status === 'paid' ? 1 : 0,
        $paymentMethod ?: null,
        ($status === 'paid' && $paymentDate !== '') ? $paymentDate : null,
        $orderId,
    ]);

    $delStmt = $pdo->prepare('DELETE FROM order_items WHERE order_id = ?');
    $delStmt->execute([$orderId]);

    $itemsStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_cost, line_total, is_lucky_box) VALUES (?,?,?,?,?,?)');
    foreach ($items as $item) {
        $itemsStmt->execute([
            $orderId,
            $item['product_id'],
            $item['quantity'],
            $item['unit_cost'],
            $item['line_total'],
            !empty($item['is_lucky_box']) ? 1 : 0,
        ]);
    }

    try {
        $oldQtyMap = [];
        foreach ($existingItems as $existingItem) {
            $pid = (int)($existingItem['product_id'] ?? 0);
            $oldQtyMap[$pid] = ($oldQtyMap[$pid] ?? 0) + (int)($existingItem['quantity'] ?? 0);
        }
        $newQtyMap = [];
        foreach ($items as $item) {
            $pid = (int)$item['product_id'];
            $newQtyMap[$pid] = ($newQtyMap[$pid] ?? 0) + (int)$item['quantity'];
        }
        $fieldChanges = [];
        $fieldMap = [
            'customer_name' => ['label' => 'Customer', 'old' => $order['customer_name'] ?? '', 'new' => $customerName],
            'phone' => ['label' => 'Phone', 'old' => $order['phone'] ?? '', 'new' => $phone],
            'location' => ['label' => 'Location', 'old' => $order['location'] ?? '', 'new' => $location],
            'page_id' => ['label' => 'Page', 'old' => (string)($order['page_id'] ?? ''), 'new' => (string)($pageId ?: '')],
            'delivery_type_id' => ['label' => 'Delivery type', 'old' => (string)($order['delivery_type_id'] ?? ''), 'new' => (string)($deliveryTypeId ?: '')],
            'delivery_cost_id' => ['label' => 'Delivery cost', 'old' => (string)($order['delivery_cost_id'] ?? ''), 'new' => (string)($deliveryCostId ?: '')],
            'status' => ['label' => 'Payment status', 'old' => $order['status'] ?? '', 'new' => $status],
            'payment_method' => ['label' => 'Payment method', 'old' => $order['payment_method'] ?? '', 'new' => $paymentMethod],
            'payment_date' => ['label' => 'Payment date', 'old' => $order['payment_date'] ?? '', 'new' => ($status === 'paid' ? $paymentDate : '')],
            'paid_note' => ['label' => 'Payment note', 'old' => $order['paid_note'] ?? '', 'new' => $paidNote],
            'discount' => ['label' => 'Discount', 'old' => (string)($order['discount'] ?? '0'), 'new' => (string)$discount],
            'total_amount' => ['label' => 'Total amount', 'old' => (string)($order['total_amount'] ?? '0'), 'new' => (string)$grandTotal],
        ];
        foreach ($fieldMap as $key => $change) {
            if (trim((string)$change['old']) !== trim((string)$change['new'])) {
                $fieldChanges[$key] = $change;
            }
        }
        $auditStmt = $pdo->prepare('INSERT INTO order_edit_audit (order_id, user_id, user_name, action, details) VALUES (?, ?, ?, ?, ?)');
        $auditStmt->execute([
            $orderId,
            (int)$user['id'],
            $user['name'] ?? $user['username'] ?? null,
            'order_edit_report_native',
            json_encode([
                'fields' => $fieldChanges,
                'changes' => [
                    'old_qty' => $oldQtyMap,
                    'new_qty' => $newQtyMap,
                ],
                'totals' => ['grand_total' => $grandTotal],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $auditError) {
        error_log('online_sale_update audit warning: ' . $auditError->getMessage());
    }

    $pdo->commit();

    $details = user_activity_seller_order_log_details([
        'order_id' => $orderId,
        'code' => $order['order_code'] ?? '',
        'customer' => $customerName,
        'phone' => $phone,
        'status' => $status,
        'total' => $grandTotal,
    ], $items);
    user_activity_log_module_mutation($user, 'seller', 'update', __FILE__, $details !== '' ? $details : 'order id ' . $orderId);

    try {
        send_order_to_telegram_async($pdo, $orderId);
    } catch (Throwable $telegramError) {
        error_log('online_sale_update Telegram warning: ' . $telegramError->getMessage());
    }

    api_json([
        'success' => true,
        'message' => 'Sale updated.',
        'order' => [
            'id' => $orderId,
            'order_code' => $order['order_code'] ?? '',
            'total_amount' => $grandTotal,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('online_sale_update API error: ' . $e->getMessage());
    api_error('Unable to update online sale.', 500);
}
