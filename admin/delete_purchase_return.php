<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_receiving.delete');

header('Content-Type: application/json');

$pdo = get_db_connection();
$return_id = (int)($_POST['return_id'] ?? $_GET['return_id'] ?? 0);

if ($return_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid return ID']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT pr.*, po.order_number FROM purchase_returns pr JOIN purchase_orders po ON pr.purchase_order_id = po.id WHERE pr.id = ?');
    $stmt->execute([$return_id]);
    $ret = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ret) {
        echo json_encode(['success' => false, 'message' => 'Return not found']);
        exit;
    }

    $itemsStmt = $pdo->prepare('SELECT pri.*, poi.item_name, poi.sku, poi.stock_item_id, poi.product_id FROM purchase_return_items pri JOIN purchase_order_items poi ON pri.purchase_order_item_id = poi.id WHERE pri.purchase_return_id = ?');
    $itemsStmt->execute([$return_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $order_id = (int)$ret['purchase_order_id'];
    $return_number = $ret['return_number'];
    $return_date = $ret['return_date'];
    $total_amount = (float)$ret['total_amount'];
    $user = current_user();

    $pdo->beginTransaction();

    foreach ($items as $it) {
        $poi_id = (int)$it['purchase_order_item_id'];
        $qty = (float)$it['quantity_returned'];
        $unit_cost = (float)$it['unit_cost'];
        $storage_location_id = (int)($it['storage_location_id'] ?? 0);
        $item_name = $it['item_name'];
        $sku = $it['sku'] ?? null;
        $stock_item_id = (int)($it['stock_item_id'] ?? 0);

        if (!empty($it['product_id'] ?? null)) {
            $pstmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
            $pstmt->execute([$it['product_id']]);
            $pname = $pstmt->fetchColumn();
            if ($pname) $item_name = $pname;
        }

        // Restore quantity_received on purchase_order_items
        $pdo->prepare('UPDATE purchase_order_items SET quantity_received = quantity_received + ? WHERE id = ?')->execute([$qty, $poi_id]);

        // Restore inventory at storage location
        if ($storage_location_id > 0) {
            $invStmt = $pdo->prepare('SELECT id FROM current_inventory WHERE item_name = ? AND storage_location_id = ? ORDER BY last_updated DESC, id DESC LIMIT 1');
            $invStmt->execute([$item_name, $storage_location_id]);
            $invRow = $invStmt->fetch(PDO::FETCH_ASSOC);
            if ($invRow) {
                $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = quantity_on_hand + ?, last_updated = NOW() WHERE id = ?')->execute([$qty, $invRow['id']]);
            } else {
                $pdo->prepare('INSERT INTO current_inventory (item_name, sku, storage_location_id, quantity_on_hand, unit_cost, updated_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$item_name, $sku, $storage_location_id, $qty, $unit_cost, $user['id'] ?? null]);
            }
            try {
                $pdo->prepare('INSERT INTO inventory_movements (movement_type, item_name, sku, quantity, unit_cost, total_cost, to_location_id, reference_type, reference_id, reference_no, reason, user_id, movement_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    'purchase_return_reversal', $item_name, $sku, $qty, $unit_cost, $qty * $unit_cost, $storage_location_id, 'purchase_return', $return_id, $return_number, 'Return reversal', $user['id'] ?? null, $return_date
                ]);
            } catch (Throwable $x) { /* optional */ }
        }

        if ($stock_item_id > 0) {
            $pdo->prepare('UPDATE stock_items SET current_quantity = current_quantity + ? WHERE id = ?')->execute([$qty, $stock_item_id]);
        }
    }

    // Restore purchase order total_amount and recalc payment_status
    $pdo->prepare('UPDATE purchase_orders SET total_amount = total_amount + ?, payment_status = CASE WHEN total_paid >= total_amount + ? THEN "paid" WHEN total_paid > 0 THEN "partial" ELSE "unpaid" END, updated_at = NOW() WHERE id = ?')->execute([$total_amount, $total_amount, $order_id]);

    // Recalc order status
    $stmt = $pdo->prepare('SELECT COUNT(*) as t, SUM(CASE WHEN COALESCE(quantity_received,0) >= quantity_ordered THEN 1 ELSE 0 END) as c FROM purchase_order_items WHERE purchase_order_id = ?');
    $stmt->execute([$order_id]);
    $sc = $stmt->fetch();
    $new_status = ($sc['t'] > 0 && $sc['c'] == $sc['t']) ? 'received' : ($sc['c'] > 0 ? 'partial' : 'confirmed');
    $pdo->prepare('UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$new_status, $order_id]);

    // Delete return (items cascade)
    $pdo->prepare('DELETE FROM purchase_returns WHERE id = ?')->execute([$return_id]);

    $pdo->commit();
    if (!empty($_POST['return_id']) && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        $back = trim($_POST['redirect'] ?? '') ?: 'purchase_returns.php';
        header('Location: ' . $back . (strpos($back, '?') !== false ? '&' : '?') . 'success=' . urlencode('Return deleted successfully.'));
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Return deleted successfully.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!empty($_POST['return_id']) && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        $back = trim($_POST['redirect'] ?? '') ?: 'purchase_returns.php';
        header('Location: ' . preg_replace('/\?.*/', '', $back) . '?error=' . urlencode($e->getMessage()));
        exit;
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
