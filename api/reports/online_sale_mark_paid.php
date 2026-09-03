<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../user_activity_lib.php';

require_role_or_permission(['admin', 'seller'], 'seller_orders.update', 'sr_orders.update', 'sr_orders.create', 'seller_orders.create');

function online_sale_mark_paid_payload(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error('Invalid payment payload.', 422);
    }
    return $data;
}

function online_sale_mark_paid_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    api_error('Payment date is required.', 422);
}

try {
    $pdo = get_db_connection();
    $user = current_user();
    if (!$user) {
        api_error('Please log in again.', 401, ['error' => 'session_expired']);
    }

    $payload = online_sale_mark_paid_payload();
    $orderId = filter_var($payload['order_id'] ?? null, FILTER_VALIDATE_INT);
    $paymentDate = online_sale_mark_paid_date($payload['payment_date'] ?? null);
    $paymentMethod = trim((string)($payload['payment_method'] ?? ''));
    $paidNote = trim((string)($payload['paid_note'] ?? ''));

    if ($orderId === false || $orderId === null || $orderId <= 0) {
        api_error('Order is required.', 422);
    }
    if ($paymentMethod === '') {
        api_error('Payment method is required.', 422);
    }
    if ($paidNote === '') {
        api_error('Paid confirmation is required.', 422);
    }

    $stmt = $pdo->prepare('SELECT id, order_code, customer_name, status, is_cancelled, is_returned FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        api_error('Order not found.', 404);
    }

    $orderStatus = strtolower((string)($order['status'] ?? ''));
    if ((int)($order['is_cancelled'] ?? 0) === 1 || in_array($orderStatus, ['cancel', 'cancelled', 'canceled'], true)) {
        api_error('Cancelled orders cannot be marked paid.', 422);
    }
    if ((int)($order['is_returned'] ?? 0) === 1 || in_array($orderStatus, ['return', 'returned'], true)) {
        api_error('Returned orders cannot be marked paid.', 422);
    }

    $update = $pdo->prepare('
        UPDATE orders
        SET status = "paid",
            is_paid = 1,
            payment_method = ?,
            payment_date = ?,
            paid_note = ?,
            updated_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ');
    $update->execute([
        $paymentMethod,
        $paymentDate,
        $paidNote,
        (int)$user['id'],
        (int)$orderId,
    ]);

    user_activity_log_module_mutation(
        $user,
        'seller',
        'update',
        __FILE__,
        'marked order ' . (string)($order['order_code'] ?? $orderId) . ' paid via ' . $paymentMethod . ' on ' . $paymentDate
    );

    api_json([
        'success' => true,
        'message' => 'Order marked as paid.',
        'order' => [
            'id' => (int)$orderId,
            'order_code' => (string)($order['order_code'] ?? ''),
            'payment_method' => $paymentMethod,
            'payment_date' => $paymentDate,
            'paid_note' => $paidNote,
            'payment_status' => 'Paid',
            'order_status' => 'Paid',
            'is_paid' => 1,
        ],
    ]);
} catch (Throwable $e) {
    error_log('online_sale_mark_paid API error: ' . $e->getMessage());
    api_error('Unable to mark order as paid.', 500);
}
