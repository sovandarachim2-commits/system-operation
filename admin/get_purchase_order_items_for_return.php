<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_receiving.view');
header('Content-Type: application/json');

$pdo = get_db_connection();
$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(['success' => false, 'items' => [], 'locations' => []]);
    exit;
}

try {
    $stmt = $pdo->prepare('
        SELECT poi.id, poi.item_name, poi.sku, poi.unit_price, poi.quantity_ordered, poi.product_id, poi.stock_item_id,
               COALESCE((SELECT SUM(pri.quantity_received) FROM purchase_receiving_items pri WHERE pri.purchase_order_item_id = poi.id), 0) as quantity_received
        FROM purchase_order_items poi
        WHERE poi.purchase_order_id = ?
        ORDER BY poi.id
    ');
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $locations = [];
    $locStmt = $pdo->query('SELECT id, location_code, location_name FROM storage_locations WHERE is_active = 1 ORDER BY location_code');
    while ($row = $locStmt->fetch(PDO::FETCH_ASSOC)) {
        $locations[] = $row;
    }

    foreach ($items as &$it) {
        $it['quantity_ordered'] = (float)$it['quantity_ordered'];
        $it['quantity_received'] = (float)$it['quantity_received'];
        $item_name = $it['item_name'];
        if (!empty($it['product_id'])) {
            $pstmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
            $pstmt->execute([$it['product_id']]);
            $pname = $pstmt->fetchColumn();
            if ($pname) $item_name = $pname;
        }
        $it['resolved_item_name'] = $item_name;
        $it['storage_locations'] = [];
        $invStmt = $pdo->prepare('
            SELECT ci.storage_location_id, sl.location_code, sl.location_name, SUM(ci.quantity_on_hand) as qty_available
            FROM current_inventory ci
            LEFT JOIN storage_locations sl ON sl.id = ci.storage_location_id
            WHERE ci.item_name = ? AND ci.quantity_on_hand > 0 AND ci.storage_location_id IS NOT NULL
            GROUP BY ci.storage_location_id, sl.location_code, sl.location_name
        ');
        $invStmt->execute([$item_name]);
        while ($inv = $invStmt->fetch(PDO::FETCH_ASSOC)) {
            $it['storage_locations'][] = [
                'id' => (int)$inv['storage_location_id'],
                'location_code' => $inv['location_code'] ?? '',
                'location_name' => $inv['location_name'] ?? '',
                'qty_available' => (float)$inv['qty_available'],
            ];
        }
    }

    echo json_encode(['success' => true, 'items' => $items, 'locations' => $locations]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'items' => [], 'locations' => []]);
}
