<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_receiving.view');

header('Content-Type: application/json');

$pdo = get_db_connection();
$return_id = (int)($_GET['id'] ?? 0);

if ($return_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT pr.*, po.order_number, po.id as order_id FROM purchase_returns pr JOIN purchase_orders po ON pr.purchase_order_id = po.id WHERE pr.id = ?');
    $stmt->execute([$return_id]);
    $ret = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ret) {
        echo json_encode(['success' => false, 'message' => 'Return not found']);
        exit;
    }

    $order_id = (int)$ret['order_id'];
    $itemsStmt = $pdo->prepare('
        SELECT pri.id as return_item_id, pri.purchase_order_item_id, pri.quantity_returned, pri.unit_cost, pri.total_cost, pri.storage_location_id,
               poi.item_name, poi.sku, poi.product_id, poi.stock_item_id,
               COALESCE((SELECT SUM(pri2.quantity_received) FROM purchase_receiving_items pri2 WHERE pri2.purchase_order_item_id = poi.id), 0) as total_received,
               COALESCE((SELECT SUM(pri_ret.quantity_returned) FROM purchase_return_items pri_ret JOIN purchase_returns pr2 ON pr2.id = pri_ret.purchase_return_id WHERE pri_ret.purchase_order_item_id = poi.id AND pr2.id <> ?), 0) as returned_by_others
        FROM purchase_return_items pri
        JOIN purchase_order_items poi ON pri.purchase_order_item_id = poi.id
        WHERE pri.purchase_return_id = ?
    ');
    $itemsStmt->execute([$return_id, $return_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as &$it) {
        $item_name = $it['item_name'];
        if (!empty($it['product_id'])) {
            $pstmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
            $pstmt->execute([$it['product_id']]);
            $pname = $pstmt->fetchColumn();
            if ($pname) $item_name = $pname;
        }
        $it['item_name'] = $item_name;
        $it['quantity_returned'] = (float)$it['quantity_returned'];
        $it['unit_cost'] = (float)$it['unit_cost'];
        $it['total_cost'] = (float)$it['total_cost'];
        $total_received = (float)$it['total_received'];
        $returned_others = (float)$it['returned_by_others'];
        $current_returned = $it['quantity_returned'];
        $it['max_qty'] = max(0, $total_received - $returned_others);
        $it['storage_locations'] = [];
        $return_loc_id = (int)($it['storage_location_id'] ?? 0);
        $return_qty = $it['quantity_returned'];

        $invStmt = $pdo->prepare('
            SELECT ci.storage_location_id, sl.location_code, sl.location_name, COALESCE(SUM(ci.quantity_on_hand), 0) as qty_available
            FROM current_inventory ci
            LEFT JOIN storage_locations sl ON sl.id = ci.storage_location_id
            WHERE ci.item_name = ? AND ci.storage_location_id IS NOT NULL
            GROUP BY ci.storage_location_id, sl.location_code, sl.location_name
        ');
        $invStmt->execute([$item_name]);
        $loc_ids_found = [];
        while ($inv = $invStmt->fetch(PDO::FETCH_ASSOC)) {
            $lid = (int)$inv['storage_location_id'];
            $qty = (float)$inv['qty_available'];
            if ($lid === $return_loc_id) {
                $qty += $return_qty;
            }
            $loc_ids_found[] = $lid;
            $it['storage_locations'][] = [
                'id' => $lid,
                'location_code' => $inv['location_code'] ?? '',
                'location_name' => $inv['location_name'] ?? '',
                'qty_available' => $qty,
            ];
        }
        if ($return_loc_id > 0 && !in_array($return_loc_id, $loc_ids_found)) {
            $locStmt = $pdo->prepare('SELECT location_code, location_name FROM storage_locations WHERE id = ?');
            $locStmt->execute([$return_loc_id]);
            $loc = $locStmt->fetch(PDO::FETCH_ASSOC);
            if ($loc) {
                $it['storage_locations'][] = [
                    'id' => $return_loc_id,
                    'location_code' => $loc['location_code'] ?? '',
                    'location_name' => $loc['location_name'] ?? '',
                    'qty_available' => $return_qty,
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'return' => $ret, 'items' => $items]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
