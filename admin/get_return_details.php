<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_receiving.view');

header('Content-Type: application/json');

$pdo = get_db_connection();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

try {
    $stmt = $pdo->prepare('
        SELECT pr.id, pr.purchase_order_id, pr.return_number, pr.return_date, pr.status, pr.reason, pr.notes, pr.total_amount,
               po.order_number, pv.name as vendor_name, u.name as created_by_name
        FROM purchase_returns pr
        JOIN purchase_orders po ON pr.purchase_order_id = po.id
        LEFT JOIN purchase_vendors pv ON pr.vendor_id = pv.id
        LEFT JOIN users u ON pr.created_by = u.id
        WHERE pr.id = ?
    ');
    $stmt->execute([$id]);
    $return_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$return_row) {
        echo json_encode(['success' => false, 'message' => 'Return record not found']);
        exit;
    }

    $itemsStmt = $pdo->prepare('
        SELECT pri.quantity_returned, pri.unit_cost, pri.total_cost, pri.reason as item_reason,
               poi.item_name, poi.sku,
               COALESCE(CONCAT(sl.location_code, " - ", sl.location_name), "N/A") as storage_location
        FROM purchase_return_items pri
        JOIN purchase_order_items poi ON pri.purchase_order_item_id = poi.id
        LEFT JOIN storage_locations sl ON pri.storage_location_id = sl.id
        WHERE pri.purchase_return_id = ?
    ');
    $itemsStmt->execute([$id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $total = 0;
    foreach ($items as &$it) {
        $it['quantity_returned'] = (float)$it['quantity_returned'];
        $it['unit_cost'] = (float)$it['unit_cost'];
        $it['total_cost'] = (float)$it['total_cost'];
        $total += $it['total_cost'];
    }

    echo json_encode([
        'success' => true,
        'return' => $return_row,
        'items' => $items,
        'total_amount' => $total
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
