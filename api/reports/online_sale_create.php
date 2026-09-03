<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../user_activity_lib.php';

require_role_or_permission(['admin', 'seller'], 'seller_orders.create', 'sr_orders.create');

function online_sale_create_payload(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error('Invalid sale payload.', 422);
    }
    return $data;
}

function online_sale_trim(array $data, string $key): string
{
    return trim((string)($data[$key] ?? ''));
}

function online_sale_int(array $data, string $key): int
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

    $payload = online_sale_create_payload();
    $customerName = online_sale_trim($payload, 'customer_name');
    $phone = online_sale_trim($payload, 'phone');
    $location = online_sale_trim($payload, 'location');
    $pageId = online_sale_int($payload, 'page_id');
    $deliveryTypeId = online_sale_int($payload, 'delivery_type_id');
    $deliveryCostId = online_sale_int($payload, 'delivery_cost_id');
    $status = online_sale_trim($payload, 'status');
    $status = in_array($status, ['paid', 'unpaid'], true) ? $status : '';
    $paymentMethod = online_sale_trim($payload, 'payment_method');
    $paidNote = online_sale_trim($payload, 'paid_note');
    $paymentDate = online_sale_trim($payload, 'payment_date');
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

    $pdo->beginTransaction();
    $orderCode = generate_order_code($pdo);
    $insertOrder = $pdo->prepare('INSERT INTO orders (order_code, customer_name, seller_id, phone, location, page_id, delivery_type_id, delivery_cost_id, status, is_cancelled, cancel_note, is_returned, paid_note, return_note, discount, total_amount, telegram_message_id, telegram_last_message_id, updated_by, is_paid, payment_method, payment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $insertOrder->execute([
        $orderCode,
        $customerName,
        (int)$user['id'],
        $phone,
        $location,
        $pageId ?: null,
        $deliveryTypeId ?: null,
        $deliveryCostId ?: null,
        $status,
        0,
        null,
        0,
        $paidNote ?: null,
        null,
        $discount,
        $grandTotal,
        null,
        null,
        null,
        $status === 'paid' ? 1 : 0,
        $paymentMethod ?: null,
        ($status === 'paid' && $paymentDate !== '') ? $paymentDate : null,
    ]);

    $orderId = (int)$pdo->lastInsertId();
    if ($orderId <= 0) {
        throw new RuntimeException('Failed to get order ID after insert.');
    }

    $insertItem = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_cost, line_total, is_lucky_box) VALUES (?,?,?,?,?,?)');
    foreach ($items as $item) {
        $insertItem->execute([
            $orderId,
            $item['product_id'],
            $item['quantity'],
            $item['unit_cost'],
            $item['line_total'],
            !empty($item['is_lucky_box']) ? 1 : 0,
        ]);
    }
    $pdo->commit();

    $details = user_activity_seller_order_log_details([
        'order_id' => $orderId,
        'code' => $orderCode,
        'customer' => $customerName,
        'phone' => $phone,
        'status' => $status,
        'total' => $grandTotal,
    ], $items);
    user_activity_log_module_mutation($user, 'seller', 'create', __FILE__, $details !== '' ? $details : 'order ' . $orderCode . ' (id ' . $orderId . ')');

    try {
        send_order_to_telegram($pdo, $orderId);
    } catch (Throwable $telegramError) {
        error_log('online_sale_create Telegram warning: ' . $telegramError->getMessage());
    }

    api_json([
        'success' => true,
        'message' => 'Sale saved.',
        'order' => [
            'id' => $orderId,
            'order_code' => $orderCode,
            'total_amount' => $grandTotal,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('online_sale_create API error: ' . $e->getMessage());
    api_error('Unable to save online sale.', 500);
}
