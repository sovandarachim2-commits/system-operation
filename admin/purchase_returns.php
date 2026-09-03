<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_receiving.view');

$pdo = get_db_connection();
$errors = [];
$success = '';

// Create return
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_return') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $return_date = $_POST['return_date'] ?? date('Y-m-d');
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $items = $_POST['items'] ?? [];

    if ($order_id <= 0) {
        $errors[] = 'Invalid purchase order.';
    } else {
        $valid_items = [];
        foreach ($items as $item_id => $item_data) {
            $item_id = (int)$item_id;
            $qty = is_array($item_data) ? (float)($item_data['qty'] ?? 0) : (float)$item_data;
            $storage_location_id = is_array($item_data) ? (int)($item_data['storage_location_id'] ?? 0) : 0;
            if ($item_id > 0 && $qty > 0) {
                $valid_items[$item_id] = ['qty' => $qty, 'storage_location_id' => $storage_location_id];
            }
        }
        if (empty($valid_items)) {
            $errors[] = 'Select at least one item with quantity to return.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT id, vendor_id, order_number FROM purchase_orders WHERE id = ?');
                $stmt->execute([$order_id]);
                $po = $stmt->fetch();
                if (!$po) {
                    throw new Exception('Purchase order not found.');
                }
                $return_number = 'PR-' . date('Ymd') . '-' . str_pad((string)(time() % 10000), 4, '0', STR_PAD_LEFT);
                $user = current_user();
                $stmt = $pdo->prepare('INSERT INTO purchase_returns (purchase_order_id, vendor_id, return_number, return_date, status, reason, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$order_id, $po['vendor_id'], $return_number, $return_date, 'completed', $reason, $notes, $user['id'] ?? null]);
                $return_id = $pdo->lastInsertId();
                $total_amount = 0;

                foreach ($valid_items as $poi_id => $item_data) {
                    $qty_return = (float)$item_data['qty'];
                    $storage_location_id = (int)($item_data['storage_location_id'] ?? 0);

                    $stmt = $pdo->prepare('SELECT poi.*, COALESCE((SELECT SUM(pri.quantity_received) FROM purchase_receiving_items pri WHERE pri.purchase_order_item_id = poi.id), 0) as total_received FROM purchase_order_items poi WHERE poi.id = ? AND poi.purchase_order_id = ?');
                    $stmt->execute([$poi_id, $order_id]);
                    $poi = $stmt->fetch();
                    if (!$poi || $qty_return > (float)$poi['total_received']) {
                        throw new Exception("Invalid quantity for item ID $poi_id.");
                    }

                    $item_name = $poi['item_name'];
                    if (!empty($poi['product_id'])) {
                        $pstmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                        $pstmt->execute([$poi['product_id']]);
                        $pname = $pstmt->fetchColumn();
                        if ($pname) $item_name = $pname;
                    }

                    if ($storage_location_id <= 0) {
                        throw new Exception("Storage location is required for {$item_name}. Please select where the product is stored.");
                    }

                    $availableAtLoc = $pdo->prepare('SELECT COALESCE(SUM(quantity_on_hand), 0) FROM current_inventory WHERE item_name = ? AND storage_location_id = ? AND quantity_on_hand > 0');
                    $availableAtLoc->execute([$item_name, $storage_location_id]);
                    $qty_at_loc = (float)$availableAtLoc->fetchColumn();
                    if ($qty_at_loc < $qty_return) {
                        throw new Exception("Insufficient inventory for {$item_name} at selected location. Available: {$qty_at_loc}, Return: {$qty_return}");
                    }

                    $unit_cost = (float)$poi['unit_price'];
                    $line_total = $qty_return * $unit_cost;
                    $total_amount += $line_total;

                    $stmt = $pdo->prepare('INSERT INTO purchase_return_items (purchase_return_id, purchase_order_item_id, quantity_returned, unit_cost, total_cost, reason, storage_location_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$return_id, $poi_id, $qty_return, $unit_cost, $line_total, $reason, $storage_location_id]);

                    $stmt = $pdo->prepare('UPDATE purchase_order_items SET quantity_received = quantity_received - ? WHERE id = ?');
                    $stmt->execute([$qty_return, $poi_id]);

                    $remaining = $qty_return;
                    $invStmt = $pdo->prepare('SELECT id, quantity_on_hand FROM current_inventory WHERE item_name = ? AND storage_location_id = ? AND quantity_on_hand > 0 ORDER BY last_updated ASC, id ASC');
                    $invStmt->execute([$item_name, $storage_location_id]);
                    $invRows = $invStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($invRows as $row) {
                        if ($remaining <= 0) break;
                        $reduce = min($remaining, (float)$row['quantity_on_hand']);
                        $newQty = (float)$row['quantity_on_hand'] - $reduce;
                        $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = ?, last_updated = NOW() WHERE id = ?')->execute([max(0, $newQty), $row['id']]);
                        try {
                            $pdo->prepare('INSERT INTO inventory_movements (movement_type, item_name, sku, quantity, unit_cost, total_cost, to_location_id, reference_type, reference_id, reference_no, reason, user_id, movement_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                                'purchase_return', $item_name, $poi['sku'] ?? null, -$reduce, $unit_cost, -$reduce * $unit_cost, null, 'purchase_return', $return_id, $return_number, 'Return to vendor', $user['id'] ?? null, $return_date
                            ]);
                        } catch (Throwable $x) { /* audit optional */ }
                        $remaining -= $reduce;
                    }
                    if ($poi['stock_item_id'] > 0) {
                        $pdo->prepare('UPDATE stock_items SET current_quantity = current_quantity - ? WHERE id = ?')->execute([$qty_return, $poi['stock_item_id']]);
                    }
                }

                $pdo->prepare('UPDATE purchase_returns SET total_amount = ? WHERE id = ?')->execute([$total_amount, $return_id]);

                // Adjust purchase order: reduce total_amount by return value and recalculate payment status
                $pdo->prepare('UPDATE purchase_orders SET total_amount = GREATEST(0, total_amount - ?), payment_status = CASE WHEN total_paid >= GREATEST(0, total_amount - ?) THEN "paid" WHEN total_paid > 0 THEN "partial" ELSE "unpaid" END, updated_at = NOW() WHERE id = ?')->execute([$total_amount, $total_amount, $order_id]);

                $stmt = $pdo->prepare('SELECT COUNT(*) as t, SUM(CASE WHEN COALESCE(quantity_received,0) >= quantity_ordered THEN 1 ELSE 0 END) as c FROM purchase_order_items WHERE purchase_order_id = ?');
                $stmt->execute([$order_id]);
                $sc = $stmt->fetch();
                $new_status = ($sc['t'] > 0 && $sc['c'] == $sc['t']) ? 'received' : ($sc['c'] > 0 ? 'partial' : 'confirmed');
                $pdo->prepare('UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$new_status, $order_id]);
                $pdo->commit();
                $success = "Purchase return $return_number created successfully.";
                header('Location: purchase_returns.php?success=' . urlencode($success));
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Edit return (full edit: items, quantities, date, reason, notes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_return') {
    require_role_or_permission(['admin'], 'purchase_receiving.update');
    $return_id = (int)($_POST['return_id'] ?? 0);
    $return_date = $_POST['return_date'] ?? date('Y-m-d');
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $items = $_POST['items'] ?? [];

    $valid_items = [];
    foreach ($items as $row) {
        if (!is_array($row)) continue;
        $poi_id = (int)($row['purchase_order_item_id'] ?? 0);
        $qty = (float)($row['qty'] ?? 0);
        $storage_location_id = (int)($row['storage_location_id'] ?? 0);
        if ($poi_id > 0 && $qty > 0 && $storage_location_id > 0) {
            $key = $poi_id . '_' . $storage_location_id;
            if (!isset($valid_items[$key])) {
                $valid_items[$key] = ['poi_id' => $poi_id, 'qty' => 0, 'storage_location_id' => $storage_location_id];
            }
            $valid_items[$key]['qty'] += $qty;
        }
    }

    if ($return_id <= 0) {
        $errors[] = 'Invalid return ID.';
    } elseif (empty($valid_items)) {
        $errors[] = 'At least one item with quantity and storage location is required.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT pr.*, po.id as order_id, po.vendor_id, po.order_number FROM purchase_returns pr JOIN purchase_orders po ON pr.purchase_order_id = po.id WHERE pr.id = ?');
            $stmt->execute([$return_id]);
            $ret = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ret) {
                throw new Exception('Return not found.');
            }
            $order_id = (int)$ret['order_id'];
            $return_number = $ret['return_number'];
            $user = current_user();

            $itemsStmt = $pdo->prepare('SELECT pri.*, poi.item_name, poi.sku, poi.stock_item_id, poi.product_id FROM purchase_return_items pri JOIN purchase_order_items poi ON pri.purchase_order_item_id = poi.id WHERE pri.purchase_return_id = ?');
            $itemsStmt->execute([$return_id]);
            $oldItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            $old_total = (float)$ret['total_amount'];

            $pdo->beginTransaction();

            // 1. Reverse existing return (restore inventory, quantity_received, order total)
            foreach ($oldItems as $it) {
                $poi_id = (int)$it['purchase_order_item_id'];
                $qty = (float)$it['quantity_returned'];
                $unit_cost = (float)$it['unit_cost'];
                $storage_location_id = (int)($it['storage_location_id'] ?? 0);
                $item_name = $it['item_name'];
                if (!empty($it['product_id'])) {
                    $pstmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                    $pstmt->execute([$it['product_id']]);
                    $pname = $pstmt->fetchColumn();
                    if ($pname) $item_name = $pname;
                }
                $pdo->prepare('UPDATE purchase_order_items SET quantity_received = quantity_received + ? WHERE id = ?')->execute([$qty, $poi_id]);
                if ($storage_location_id > 0) {
                    $invStmt = $pdo->prepare('SELECT id FROM current_inventory WHERE item_name = ? AND storage_location_id = ? ORDER BY last_updated DESC, id DESC LIMIT 1');
                    $invStmt->execute([$item_name, $storage_location_id]);
                    $invRow = $invStmt->fetch(PDO::FETCH_ASSOC);
                    if ($invRow) {
                        $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = quantity_on_hand + ?, last_updated = NOW() WHERE id = ?')->execute([$qty, $invRow['id']]);
                    } else {
                        $pdo->prepare('INSERT INTO current_inventory (item_name, sku, storage_location_id, quantity_on_hand, unit_cost, updated_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$item_name, $it['sku'] ?? null, $storage_location_id, $qty, $unit_cost, $user['id'] ?? null]);
                    }
                    try {
                        $pdo->prepare('INSERT INTO inventory_movements (movement_type, item_name, sku, quantity, unit_cost, total_cost, to_location_id, reference_type, reference_id, reference_no, reason, user_id, movement_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                            'purchase_return_reversal', $item_name, $it['sku'] ?? null, $qty, $unit_cost, $qty * $unit_cost, $storage_location_id, 'purchase_return', $return_id, $return_number, 'Return reversal (edit)', $user['id'] ?? null, $return_date
                        ]);
                    } catch (Throwable $x) { /* optional */ }
                }
                if (!empty($it['stock_item_id']) && (int)$it['stock_item_id'] > 0) {
                    $pdo->prepare('UPDATE stock_items SET current_quantity = current_quantity + ? WHERE id = ?')->execute([$qty, $it['stock_item_id']]);
                }
            }
            $pdo->prepare('UPDATE purchase_orders SET total_amount = total_amount + ?, payment_status = CASE WHEN total_paid >= total_amount + ? THEN "paid" WHEN total_paid > 0 THEN "partial" ELSE "unpaid" END, updated_at = NOW() WHERE id = ?')->execute([$old_total, $old_total, $order_id]);

            // 2. Delete old return items
            $pdo->prepare('DELETE FROM purchase_return_items WHERE purchase_return_id = ?')->execute([$return_id]);

            // 3. Apply new items (same logic as create)
            $total_amount = 0;
            foreach ($valid_items as $item_data) {
                $poi_id = $item_data['poi_id'];
                $qty_return = $item_data['qty'];
                $storage_location_id = $item_data['storage_location_id'];

                $stmt = $pdo->prepare('SELECT poi.*, COALESCE(poi.quantity_received, 0) as available FROM purchase_order_items poi WHERE poi.id = ? AND poi.purchase_order_id = ?');
                $stmt->execute([$poi_id, $order_id]);
                $poi = $stmt->fetch();
                if (!$poi || $qty_return > (float)$poi['available']) {
                    throw new Exception("Invalid quantity for item ID $poi_id. Max available: " . ((float)($poi['available'] ?? 0)));
                }

                $item_name = $poi['item_name'];
                if (!empty($poi['product_id'])) {
                    $pstmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                    $pstmt->execute([$poi['product_id']]);
                    $pname = $pstmt->fetchColumn();
                    if ($pname) $item_name = $pname;
                }

                $availableAtLoc = $pdo->prepare('SELECT COALESCE(SUM(quantity_on_hand), 0) FROM current_inventory WHERE item_name = ? AND storage_location_id = ?');
                $availableAtLoc->execute([$item_name, $storage_location_id]);
                $qty_at_loc = (float)$availableAtLoc->fetchColumn();
                if ($qty_at_loc < $qty_return) {
                    throw new Exception("Insufficient inventory for {$item_name} at selected location. Available: {$qty_at_loc}, Return: {$qty_return}");
                }

                $unit_cost = (float)$poi['unit_price'];
                $line_total = $qty_return * $unit_cost;
                $total_amount += $line_total;

                $stmt = $pdo->prepare('INSERT INTO purchase_return_items (purchase_return_id, purchase_order_item_id, quantity_returned, unit_cost, total_cost, reason, storage_location_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$return_id, $poi_id, $qty_return, $unit_cost, $line_total, $reason, $storage_location_id]);

                $stmt = $pdo->prepare('UPDATE purchase_order_items SET quantity_received = quantity_received - ? WHERE id = ?');
                $stmt->execute([$qty_return, $poi_id]);

                $remaining = $qty_return;
                $invStmt = $pdo->prepare('SELECT id, quantity_on_hand FROM current_inventory WHERE item_name = ? AND storage_location_id = ? AND quantity_on_hand > 0 ORDER BY last_updated ASC, id ASC');
                $invStmt->execute([$item_name, $storage_location_id]);
                $invRows = $invStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($invRows as $row) {
                    if ($remaining <= 0) break;
                    $reduce = min($remaining, (float)$row['quantity_on_hand']);
                    $newQty = (float)$row['quantity_on_hand'] - $reduce;
                    $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = ?, last_updated = NOW() WHERE id = ?')->execute([max(0, $newQty), $row['id']]);
                    try {
                        $pdo->prepare('INSERT INTO inventory_movements (movement_type, item_name, sku, quantity, unit_cost, total_cost, to_location_id, reference_type, reference_id, reference_no, reason, user_id, movement_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                            'purchase_return', $item_name, $poi['sku'] ?? null, -$reduce, $unit_cost, -$reduce * $unit_cost, null, 'purchase_return', $return_id, $return_number, 'Return to vendor', $user['id'] ?? null, $return_date
                        ]);
                    } catch (Throwable $x) { /* optional */ }
                    $remaining -= $reduce;
                }
                if (!empty($poi['stock_item_id']) && (int)$poi['stock_item_id'] > 0) {
                    $pdo->prepare('UPDATE stock_items SET current_quantity = current_quantity - ? WHERE id = ?')->execute([$qty_return, $poi['stock_item_id']]);
                }
            }

            $pdo->prepare('UPDATE purchase_returns SET return_date = ?, reason = ?, notes = ?, total_amount = ? WHERE id = ?')->execute([$return_date, $reason, $notes, $total_amount, $return_id]);

            $pdo->prepare('UPDATE purchase_orders SET total_amount = GREATEST(0, total_amount - ?), payment_status = CASE WHEN total_paid >= GREATEST(0, total_amount - ?) THEN "paid" WHEN total_paid > 0 THEN "partial" ELSE "unpaid" END, updated_at = NOW() WHERE id = ?')->execute([$total_amount, $total_amount, $order_id]);

            $stmt = $pdo->prepare('SELECT COUNT(*) as t, SUM(CASE WHEN COALESCE(quantity_received,0) >= quantity_ordered THEN 1 ELSE 0 END) as c FROM purchase_order_items WHERE purchase_order_id = ?');
            $stmt->execute([$order_id]);
            $sc = $stmt->fetch();
            $new_status = ($sc['t'] > 0 && $sc['c'] == $sc['t']) ? 'received' : ($sc['c'] > 0 ? 'partial' : 'confirmed');
            $pdo->prepare('UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$new_status, $order_id]);

            $pdo->commit();
            $success = 'Return updated successfully.';
            header('Location: purchase_returns.php?success=' . urlencode($success) . '&date_from=' . urlencode($_GET['date_from'] ?? '') . '&date_to=' . urlencode($_GET['date_to'] ?? ''));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

$success = $_GET['success'] ?? $success;
if (!empty($_GET['error'])) $errors[] = $_GET['error'];
$edit_id = (int)($_GET['edit'] ?? $_POST['return_id'] ?? 0);
$edit_return = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM purchase_returns WHERE id = ?');
    $stmt->execute([$edit_id]);
    $edit_return = $stmt->fetch(PDO::FETCH_ASSOC);
}

// List returns
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$where = [];
$params = [];
if ($date_from) { $where[] = 'DATE(pr.return_date) >= ?'; $params[] = $date_from; }
if ($date_to) { $where[] = 'DATE(pr.return_date) <= ?'; $params[] = $date_to; }
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$returns = [];
try {
    $stmt = $pdo->prepare("SELECT pr.*, po.order_number, pv.name as vendor_name, u.name as created_by_name FROM purchase_returns pr JOIN purchase_orders po ON pr.purchase_order_id = po.id LEFT JOIN purchase_vendors pv ON pr.vendor_id = pv.id LEFT JOIN users u ON pr.created_by = u.id $whereClause ORDER BY pr.return_date DESC, pr.id DESC");
    $stmt->execute($params);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $returns = [];
}

$orders_with_received = [];
try {
    $stmt = $pdo->query("SELECT po.id, po.order_number, pv.name as vendor_name FROM purchase_orders po LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id WHERE po.status IN ('partial','received','confirmed') AND EXISTS (SELECT 1 FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id AND COALESCE((SELECT SUM(pri.quantity_received) FROM purchase_receiving_items pri WHERE pri.purchase_order_item_id = poi.id), 0) > 0) ORDER BY po.order_number DESC");
    $orders_with_received = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $orders_with_received = [];
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0"><i class="bi bi-arrow-return-left me-2"></i>Purchase Returns to Vendor</h1>
        <div class="d-flex gap-2">
            <a href="purchase_return_history.php" class="btn btn-outline-secondary">Return History</a>
            <a href="purchase_orders.php" class="btn btn-outline-secondary">Purchase Orders</a>
            <a href="purchase_receiving.php" class="btn btn-outline-primary">Receiving</a>
            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#createReturnModal"><i class="bi bi-plus-circle me-1"></i>Create Return</button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <?php if ($edit_return): ?>
    <div class="card shadow-sm mb-3 border-primary">
        <div class="card-header bg-primary text-white"><h5 class="mb-0">Edit Return <?= htmlspecialchars($edit_return['return_number']) ?></h5></div>
        <div class="card-body">
            <form method="post" id="editReturnForm">
                <input type="hidden" name="action" value="edit_return">
                <input type="hidden" name="return_id" value="<?= $edit_return['id'] ?>">
                <div class="row g-2 mb-3">
                    <div class="col-md-4"><label class="form-label">Return Date</label><input type="date" name="return_date" class="form-control" value="<?= htmlspecialchars($edit_return['return_date']) ?>" required></div>
                    <div class="col-md-4"><label class="form-label">Reason</label><input type="text" name="reason" class="form-control" value="<?= htmlspecialchars($edit_return['reason'] ?? '') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Notes</label><input type="text" name="notes" class="form-control" value="<?= htmlspecialchars($edit_return['notes'] ?? '') ?>"></div>
                </div>
                <div id="editReturnItemsContainer">
                    <p class="text-muted">Loading items...</p>
                </div>
                <div class="mt-2">
                    <button type="submit" class="btn btn-primary" id="editReturnSubmit" disabled>Save</button>
                    <a href="purchase_returns.php?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-auto"><label class="form-label small mb-0">Date From</label><input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>"></div>
                <div class="col-auto"><label class="form-label small mb-0">Date To</label><input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>"></div>
                <div class="col-auto"><button type="submit" class="btn btn-primary btn-sm">Filter</button></div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm flex-grow-1">
        <div class="card-header bg-light"><h5 class="mb-0">Return History</h5></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>#</th><th>Return #</th><th>Date</th><th>Order #</th><th>Vendor</th><th class="text-end">Total Amount</th><th>Reason</th><th>Created By</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($returns)): ?>
                        <tr><td colspan="9" class="text-center py-4 text-muted">No purchase returns found. Create a return from a purchase order that has received items.</td></tr>
                    <?php else: ?>
                        <?php $n = 1; foreach ($returns as $r): ?>
                            <tr>
                                <td><?= $n++ ?></td>
                                <td><strong><?= htmlspecialchars($r['return_number']) ?></strong></td>
                                <td><?= date('M j, Y', strtotime($r['return_date'])) ?></td>
                                <td><a href="purchase_orders.php?view=<?= $r['purchase_order_id'] ?>"><?= htmlspecialchars($r['order_number']) ?></a></td>
                                <td><?= htmlspecialchars($r['vendor_name'] ?? '-') ?></td>
                                <td class="text-end">$<?= number_format((float)$r['total_amount'], 2) ?></td>
                                <td><?= htmlspecialchars(mb_substr($r['reason'] ?? '', 0, 40)) ?><?= mb_strlen($r['reason'] ?? '') > 40 ? '…' : '' ?></td>
                                <td><?= htmlspecialchars($r['created_by_name'] ?? '-') ?></td>
                                <td>
                                    <?php if ($can_edit = ($user['role'] ?? '') === 'admin' || $can('purchase_receiving.update')): ?>
                                        <a href="?edit=<?= $r['id'] ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>" class="btn btn-outline-secondary btn-sm" title="Edit"><i class="bi bi-pencil-square"></i></a>
                                    <?php endif; ?>
                                    <?php if ($can_delete = ($user['role'] ?? '') === 'admin' || $can('purchase_receiving.delete')): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteReturn(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['return_number'])) ?>')" title="Delete"><i class="bi bi-trash"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Return Modal -->
<div class="modal fade" id="createReturnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="create_return">
                <div class="modal-header"><h5 class="modal-title">Create Purchase Return</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="text-muted small">Select a purchase order with received items, then enter quantities to return. Inventory will be reduced accordingly.</p>
                    <div class="mb-3">
                        <label class="form-label">Purchase Order *</label>
                        <select name="order_id" id="returnOrderId" class="form-select" required>
                            <option value="">Select order with received items</option>
                            <?php foreach ($orders_with_received as $o): ?>
                                <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['order_number']) ?> - <?= htmlspecialchars($o['vendor_name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><label class="form-label">Return Date *</label><input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                        <div class="col-md-8"><label class="form-label">Reason</label><input type="text" name="reason" class="form-control" placeholder="e.g. Defective, wrong item"></div>
                    </div>
                    <div class="mb-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                    <div id="returnItemsContainer"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-warning">Create Return</button></div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('returnOrderId').addEventListener('change', function() {
    const orderId = this.value;
    const container = document.getElementById('returnItemsContainer');
    container.innerHTML = '<p class="text-muted">Loading...</p>';
    if (!orderId) { container.innerHTML = ''; return; }
    fetch('get_purchase_order_items_for_return.php?order_id=' + orderId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.items || data.items.length === 0) {
                container.innerHTML = '<p class="text-muted">No items with received quantity to return.</p>';
                return;
            }
            let html = '<h6>Items to Return</h6><p class="small text-muted">Select storage location and return quantity for each item. Location must have the product in stock.</p>';
            html += '<table class="table table-sm"><thead><tr><th>Product</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th>Storage Location</th><th class="text-end">Return Qty</th></tr></thead><tbody>';
            data.items.forEach(it => {
                const ordered = parseFloat(it.quantity_ordered) || 0;
                const received = parseFloat(it.quantity_received) || 0;
                if (received <= 0) return;
                const locs = it.storage_locations || [];
                let locOpts = '<option value="">Select location</option>';
                locs.forEach(loc => {
                    locOpts += '<option value="' + loc.id + '" data-max="' + loc.qty_available + '">' + (loc.location_code || loc.location_name || 'ID ' + loc.id) + ' (' + parseFloat(loc.qty_available).toFixed(2) + ' avail)</option>';
                });
                const hasLocations = locs.length > 0;
                const locRequired = hasLocations ? 'required' : '';
                const locDisabled = !hasLocations ? 'disabled' : '';
                const qtyDisabled = !hasLocations ? 'disabled' : '';
                if (!hasLocations) {
                    locOpts = '<option value="">No stock in any location</option>';
                }
                html += '<tr data-item-id="' + it.id + '">';
                html += '<td>' + (it.item_name || '').replace(/</g,'&lt;') + '<br><small class="text-muted">' + (hasLocations ? '' : 'No stock in storage - cannot return') + '</small></td>';
                html += '<td class="text-end">' + ordered + '</td>';
                html += '<td class="text-end">' + received + '</td>';
                html += '<td><select name="items[' + it.id + '][storage_location_id]" class="form-select form-select-sm location-select" ' + locRequired + ' ' + locDisabled + ' data-received="' + received + '">' + locOpts + '</select></td>';
                html += '<td><input type="number" step="0.01" name="items[' + it.id + '][qty]" class="form-control form-control-sm text-end return-qty" min="0" max="' + received + '" value="0" placeholder="0" data-received="' + received + '" ' + qtyDisabled + '></td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            container.innerHTML = html;

            container.querySelectorAll('.location-select').forEach(sel => {
                sel.addEventListener('change', function() {
                    const row = this.closest('tr');
                    const qtyInput = row.querySelector('.return-qty');
                    const opt = this.options[this.selectedIndex];
                    const maxAtLoc = opt && opt.dataset.max ? parseFloat(opt.dataset.max) : 0;
                    const received = parseFloat(this.dataset.received || 0);
                    const maxQty = Math.min(maxAtLoc, received);
                    if (qtyInput) {
                        qtyInput.max = maxQty;
                        if (parseFloat(qtyInput.value || 0) > maxQty) qtyInput.value = maxQty;
                    }
                });
            });
            container.querySelectorAll('.return-qty').forEach(inp => {
                inp.addEventListener('input', function() {
                    const row = this.closest('tr');
                    const sel = row.querySelector('.location-select');
                    const opt = sel && sel.options[sel.selectedIndex];
                    const maxAtLoc = opt && opt.dataset.max ? parseFloat(opt.dataset.max) : 0;
                    const received = parseFloat(this.dataset.received || 0);
                    const maxQty = Math.min(maxAtLoc, received);
                    const val = parseFloat(this.value || 0);
                    if (val > maxQty) this.value = maxQty;
                });
            });
        })
        .catch(e => { container.innerHTML = '<p class="text-danger">Failed to load items.</p>'; });
});

<?php if ($edit_return): ?>
document.addEventListener('DOMContentLoaded', function() {
    const editId = <?= (int)$edit_return['id'] ?>;
    const container = document.getElementById('editReturnItemsContainer');
    const submitBtn = document.getElementById('editReturnSubmit');
    fetch('get_return_items_for_edit.php?id=' + editId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.items || data.items.length === 0) {
                container.innerHTML = '<p class="text-muted">No items in this return.</p>';
                return;
            }
            let html = '<h6>Items to Return</h6><p class="small text-muted">Edit quantities and storage locations. Set qty to 0 to remove an item.</p>';
            html += '<table class="table table-sm"><thead><tr><th>Product</th><th class="text-end">Max</th><th>Storage Location</th><th class="text-end">Return Qty</th></tr></thead><tbody>';
            data.items.forEach(function(it, idx) {
                const maxQty = parseFloat(it.max_qty) || 0;
                const locs = it.storage_locations || [];
                let locOpts = '<option value="">Select location</option>';
                const selLocId = parseInt(it.storage_location_id) || 0;
                locs.forEach(loc => {
                    const sel = loc.id === selLocId ? ' selected' : '';
                    locOpts += '<option value="' + loc.id + '" data-max="' + loc.qty_available + '"' + sel + '>' + (loc.location_code || loc.location_name || 'ID ' + loc.id) + ' (' + parseFloat(loc.qty_available).toFixed(2) + ' avail)</option>';
                });
                html += '<tr>';
                html += '<td>' + (it.item_name || '').replace(/</g,'&lt;') + '<br><small class="text-muted">' + (it.sku || '') + '</small></td>';
                html += '<td class="text-end">' + maxQty.toFixed(2) + '</td>';
                html += '<td><input type="hidden" name="items[' + idx + '][purchase_order_item_id]" value="' + it.purchase_order_item_id + '"><select name="items[' + idx + '][storage_location_id]" class="form-select form-select-sm edit-loc-select" data-max-poi="' + maxQty + '" required>' + locOpts + '</select></td>';
                html += '<td><input type="number" step="0.01" name="items[' + idx + '][qty]" class="form-control form-control-sm text-end edit-qty" min="0" max="' + maxQty + '" value="' + (parseFloat(it.quantity_returned) || 0) + '" data-max-poi="' + maxQty + '"></td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            container.innerHTML = html;

            function updateEditRowMax(row) {
                const sel = row.querySelector('.edit-loc-select');
                const qtyInput = row.querySelector('.edit-qty');
                if (!sel || !qtyInput) return;
                const opt = sel.options[sel.selectedIndex];
                const maxAtLoc = opt && opt.dataset.max ? parseFloat(opt.dataset.max) : 0;
                const maxPoi = parseFloat(sel.dataset.maxPoi || 0);
                const maxQty = Math.min(maxAtLoc, maxPoi);
                qtyInput.max = maxQty;
                if (parseFloat(qtyInput.value || 0) > maxQty) qtyInput.value = maxQty;
            }
            container.querySelectorAll('.edit-loc-select').forEach(sel => {
                sel.addEventListener('change', function() { updateEditRowMax(this.closest('tr')); });
            });
            container.querySelectorAll('tbody tr').forEach(tr => { updateEditRowMax(tr); });
            container.querySelectorAll('.edit-qty').forEach(inp => {
                inp.addEventListener('input', function() {
                    const row = this.closest('tr');
                    const sel = row.querySelector('.edit-loc-select');
                    const opt = sel && sel.options[sel.selectedIndex];
                    const maxAtLoc = opt && opt.dataset.max ? parseFloat(opt.dataset.max) : 0;
                    const maxPoi = parseFloat(this.dataset.maxPoi || 0);
                    const maxQty = Math.min(maxAtLoc, maxPoi);
                    const val = parseFloat(this.value || 0);
                    if (val > maxQty) this.value = maxQty;
                });
            });
            if (submitBtn) submitBtn.disabled = false;
        })
        .catch(e => {
            container.innerHTML = '<p class="text-danger">Failed to load items.</p>';
        });
});
<?php endif; ?>

function deleteReturn(id, label, redirect) {
    if (!confirm('Delete return ' + label + '? This will restore inventory and reverse the order adjustment.')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'delete_purchase_return.php';
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'return_id'; inp.value = id;
    form.appendChild(inp);
    if (redirect) { var r = document.createElement('input'); r.type = 'hidden'; r.name = 'redirect'; r.value = redirect; form.appendChild(r); }
    document.body.appendChild(form);
    form.submit();
}
</script>
<?php include __DIR__ . '/../layout/footer.php'; ?>
