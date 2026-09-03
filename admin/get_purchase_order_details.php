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
    // Get order details
    $orderStmt = $pdo->prepare('
        SELECT po.*, pv.name as vendor_name
        FROM purchase_orders po
        LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id
        WHERE po.id = ?
    ');
    $orderStmt->execute([$order_id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    // Get order items
    $itemsStmt = $pdo->prepare('
        SELECT poi.*, 
               COALESCE(SUM(pri.quantity_received), 0) as total_received
        FROM purchase_order_items poi
        LEFT JOIN purchase_receiving_items pri ON poi.id = pri.purchase_order_item_id
        WHERE poi.purchase_order_id = ?
        GROUP BY poi.id
        ORDER BY poi.id
    ');
    $itemsStmt->execute([$order_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Update quantity_received in items for display
    foreach ($items as &$item) {
        $item['quantity_received'] = (float)$item['total_received'];
        unset($item['total_received']);
    }

    // Get receiving history with item details
    $receiving_history = [];
    try {
        $receivingStmt = $pdo->prepare('
            SELECT pr.id, pr.purchase_order_id, pr.receiving_date, pr.received_by, pr.notes,
                   COUNT(pri.id) as total_items,
                   SUM(pri.total_cost) as total_value,
                   sr.receipt_number as storage_receipt_number,
                   u.name as received_by_name
            FROM purchase_receiving pr
            LEFT JOIN purchase_receiving_items pri ON pr.id = pri.receiving_id
            LEFT JOIN storage_receipts sr ON pr.storage_receipt_id = sr.id
            LEFT JOIN users u ON pr.received_by = u.id
            WHERE pr.purchase_order_id = ?
            GROUP BY pr.id
            ORDER BY pr.receiving_date DESC, pr.id DESC
        ');
        $receivingStmt->execute([$order_id]);
        $receiving_history = $receivingStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get items for each receiving
        foreach ($receiving_history as &$receiving) {
            $receiving['total_items'] = (int)$receiving['total_items'];
            $receiving['total_value'] = (float)$receiving['total_value'];
            if ($receiving['storage_receipt_number']) {
                $receiving['storage_receipt'] = [
                    'receipt_number' => $receiving['storage_receipt_number']
                ];
            }
            unset($receiving['storage_receipt_number']);
            $receiving['receipt_number'] = 'REC-' . $receiving['id'];
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
            $itemsStmt->execute([$receiving['id']]);
            $receiving['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($receiving['items'] as &$ri) {
                $ri['quantity_received'] = (float)$ri['quantity_received'];
                $ri['unit_cost'] = (float)$ri['unit_cost'];
                $ri['total_cost'] = (float)$ri['total_cost'];
            }
        }
    } catch (PDOException $e) {
        // Receiving tables might not exist, just return empty array
        $receiving_history = [];
    }

    // Get return history with item details
    $return_history = [];
    try {
        $returnStmt = $pdo->prepare('
            SELECT pr.id, pr.purchase_order_id, pr.return_number, pr.return_date, pr.reason, pr.notes, pr.total_amount,
                   u.name as created_by_name
            FROM purchase_returns pr
            LEFT JOIN users u ON pr.created_by = u.id
            WHERE pr.purchase_order_id = ?
            ORDER BY pr.return_date DESC, pr.id DESC
        ');
        $returnStmt->execute([$order_id]);
        $return_history = $returnStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($return_history as &$ret) {
            $ret['total_amount'] = (float)$ret['total_amount'];
            $itemsStmt = $pdo->prepare('
                SELECT pri.quantity_returned, pri.unit_cost, pri.total_cost, poi.item_name, poi.sku, poi.product_id,
                       COALESCE(CONCAT(sl.location_code, " - ", sl.location_name), "-") as storage_location
                FROM purchase_return_items pri
                JOIN purchase_order_items poi ON pri.purchase_order_item_id = poi.id
                LEFT JOIN storage_locations sl ON pri.storage_location_id = sl.id
                WHERE pri.purchase_return_id = ?
            ');
            $itemsStmt->execute([$ret['id']]);
            $ret['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ret['items'] as &$ri) {
                if (!empty($ri['product_id'])) {
                    $pstmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                    $pstmt->execute([$ri['product_id']]);
                    $pname = $pstmt->fetchColumn();
                    if ($pname) $ri['item_name'] = $pname;
                }
                $ri['quantity_returned'] = (float)$ri['quantity_returned'];
                $ri['unit_cost'] = (float)$ri['unit_cost'];
                $ri['total_cost'] = (float)$ri['total_cost'];
            }
        }
    } catch (PDOException $e) {
        $return_history = [];
    }

    echo json_encode([
        'success' => true,
        'order' => $order,
        'items' => $items,
        'receiving_history' => $receiving_history,
        'return_history' => $return_history
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
