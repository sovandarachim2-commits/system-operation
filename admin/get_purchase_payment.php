<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_payments.view');

header('Content-Type: application/json');

$pdo = get_db_connection();
$payment_id = (int)($_GET['id'] ?? 0);

if ($payment_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
    exit;
}

try {
    $stmt = $pdo->prepare('
        SELECT pp.*, po.order_number, pv.name as vendor_name
        FROM purchase_payments pp
        LEFT JOIN purchase_orders po ON pp.purchase_order_id = po.id
        LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id
        WHERE pp.id = ?
    ');
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'payment' => $payment
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
