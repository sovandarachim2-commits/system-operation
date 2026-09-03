<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_orders.view');

header('Content-Type: application/json');

$pdo = get_db_connection();
$order_id = (int)($_GET['id'] ?? 0);

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

try {
    // Get order items with received quantities - handle missing receiving tables
    $items = [];
    try {
        $itemsStmt = $pdo->prepare('
            SELECT poi.*, 
                   COALESCE(SUM(pri.quantity_received), 0) as quantity_received
            FROM purchase_order_items poi
            LEFT JOIN purchase_receiving_items pri ON poi.id = pri.purchase_order_item_id
            WHERE poi.purchase_order_id = ?
            GROUP BY poi.id
            ORDER BY poi.id
        ');
        $itemsStmt->execute([$order_id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If receiving tables don't exist, just get basic items
        $itemsStmt = $pdo->prepare('
            SELECT poi.*, 0 as quantity_received
            FROM purchase_order_items poi
            WHERE poi.purchase_order_id = ?
            ORDER BY poi.id
        ');
        $itemsStmt->execute([$order_id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Format quantities as numbers
    foreach ($items as &$item) {
        $item['quantity_ordered'] = (float)$item['quantity_ordered'];
        $item['quantity_received'] = (float)$item['quantity_received'];
        $item['unit_price'] = (float)$item['unit_price'];
        $item['line_total'] = (float)$item['line_total'];
    }

    echo json_encode([
        'success' => true,
        'items' => $items
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
