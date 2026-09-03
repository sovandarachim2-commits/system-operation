<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../helpers.php';

require_role_or_permission(['admin', 'seller'], 'seller_orders.update', 'orders.update', 'sr_orders.update');

function online_sale_detail_order_id(): int
{
    $value = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? 0 : (int)$value;
}

try {
    $pdo = get_db_connection();
    ensure_order_items_lucky_box_column($pdo);
    $user = current_user();
    if (!$user) {
        api_error('Please log in again.', 401, ['error' => 'session_expired']);
    }

    $orderId = online_sale_detail_order_id();
    if ($orderId <= 0) {
        api_error('Order ID is required.', 422);
    }

    $canEditAnyOrder = ($user['role'] ?? '') === 'admin'
        || (function_exists('rbac_is_enabled') && rbac_is_enabled($pdo) && has_permission('orders.update'));

    $sql = '
        SELECT
            o.id,
            o.order_code,
            o.customer_name,
            o.phone,
            o.location,
            o.page_id,
            o.delivery_type_id,
            o.delivery_cost_id,
            o.status,
            o.payment_method,
            o.payment_date,
            o.paid_note,
            o.discount,
            o.total_amount,
            o.is_paid,
            o.is_cancelled,
            o.is_returned,
            o.seller_id,
            COALESCE(NULLIF(u.name, \'\'), u.username, \'Shadow Shop\') AS seller
        FROM orders o
        LEFT JOIN users u ON u.id = o.seller_id
        WHERE o.id = ?
    ';
    $params = [$orderId];
    if (!$canEditAnyOrder) {
        $sql .= ' AND o.seller_id = ?';
        $params[] = (int)$user['id'];
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        api_error('Order not found or you do not have permission to edit it.', 404);
    }

    $itemsStmt = $pdo->prepare('
        SELECT
            oi.product_id,
            oi.quantity,
            oi.unit_cost,
            oi.line_total,
            COALESCE(oi.is_lucky_box, 0) AS is_lucky_box,
            p.name AS product_name,
            COALESCE(p.brand_id, 0) AS brand_id
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.id
    ');
    $itemsStmt->execute([$orderId]);
    $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    api_json([
        'success' => true,
        'order' => $order,
    ]);
} catch (Throwable $e) {
    error_log('online_sale_detail API error: ' . $e->getMessage());
    api_error('Unable to load online sale order.', 500);
}
