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
        SELECT pr.id, pr.purchase_order_id, pr.receiving_date, pr.received_by, pr.notes,
               po.order_number, pv.name as vendor_name, u.name as received_by_name,
               sr.receipt_number as storage_receipt_number
        FROM purchase_receiving pr
        JOIN purchase_orders po ON pr.purchase_order_id = po.id
        LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id
        LEFT JOIN users u ON pr.received_by = u.id
        LEFT JOIN storage_receipts sr ON pr.storage_receipt_id = sr.id
        WHERE pr.id = ?
    ');
    $stmt->execute([$id]);
    $receiving = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receiving) {
        echo json_encode(['success' => false, 'message' => 'Receiving record not found']);
        exit;
    }

    $itemsStmt = $pdo->prepare('
        SELECT pri.quantity_received, pri.unit_cost, pri.total_cost, poi.item_name, poi.sku,
               sl.location_code, sl.location_name,
               COALESCE(CONCAT(sl.location_code, " - ", sl.location_name), pri.location, "-") as storage_location
        FROM purchase_receiving_items pri
        JOIN purchase_order_items poi ON pri.purchase_order_item_id = poi.id
        LEFT JOIN storage_receipts sr ON sr.receiving_id = pri.receiving_id
        LEFT JOIN storage_receipt_items sri ON sri.receipt_id = sr.id AND sri.purchase_order_item_id = pri.purchase_order_item_id
        LEFT JOIN storage_locations sl ON sri.storage_location_id = sl.id
        WHERE pri.receiving_id = ?
    ');
    $itemsStmt->execute([$id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $total = 0;
    foreach ($items as &$it) {
        $it['quantity_received'] = (float)$it['quantity_received'];
        $it['unit_cost'] = (float)$it['unit_cost'];
        $it['total_cost'] = (float)$it['total_cost'];
        $total += $it['total_cost'];
    }

    echo json_encode([
        'success' => true,
        'receiving' => $receiving,
        'items' => $items,
        'total_value' => $total
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
