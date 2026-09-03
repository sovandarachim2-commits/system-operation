<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_orders.view');
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();

$errors = [];
$success = '';

// Handle success message from redirect
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// Get vendors for dropdown
try {
    $vendorsStmt = $pdo->query('SELECT id, name FROM purchase_vendors WHERE is_active = 1 ORDER BY name');
    $vendors = $vendorsStmt->fetchAll();
} catch (PDOException $e) {
    $vendors = [];
    $errors[] = 'Purchase vendors table not found. Please run setup script first.';
}

// Get products for dropdown (exclude product sets)
try {
    $productsStmt = $pdo->query('SELECT id, name, cost FROM products WHERE product_type != \'set\' OR product_type IS NULL ORDER BY name');
    $products = $productsStmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
}

// Get stock items for dropdown
try {
    $stockItemsStmt = $pdo->query('SELECT id, name, unit, current_quantity FROM stock_items WHERE is_active = 1 ORDER BY name');
    $stockItems = $stockItemsStmt->fetchAll();
} catch (PDOException $e) {
    $stockItems = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_order') {
        $vendor_id = (int)($_POST['vendor_id'] ?? 0);
        $order_date = $_POST['order_date'] ?? date('Y-m-d');
        $expected_date = $_POST['expected_date'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        $items = $_POST['items'] ?? [];

        // Validate required fields
        if ($vendor_id <= 0) {
            $errors[] = 'Vendor is required.';
        }

        if (empty($items)) {
            $errors[] = 'At least one item is required.';
        }

        // Validate each item has required fields
        $valid_items = 0;
        foreach ($items as $index => $item) {
            $item_num = $index + 1;

            // Check if product is selected
            if (empty($item['product_id']) && empty($item['stock_item_id'])) {
                $errors[] = "Item #$item_num: Product or stock item must be selected.";
                continue;
            }

            // Check quantity is provided and valid
            if (!isset($item['quantity']) || $item['quantity'] === '' || !is_numeric($item['quantity'])) {
                $errors[] = "Item #$item_num: Quantity is required and must be a valid number.";
                continue;
            }

            $quantity = (float)$item['quantity'];
            if ($quantity <= 0) {
                $errors[] = "Item #$item_num: Quantity must be greater than 0.";
                continue;
            }

            // Check unit price is provided and valid
            if (!isset($item['unit_price']) || $item['unit_price'] === '' || !is_numeric($item['unit_price'])) {
                $errors[] = "Item #$item_num: Unit price is required and must be a valid number.";
                continue;
            }

            $unit_price = (float)$item['unit_price'];
            if ($unit_price <= 0) {
                $errors[] = "Item #$item_num: Unit price must be greater than 0.";
                continue;
            }

            $valid_items++;
        }

        if ($valid_items == 0 && !empty($items)) {
            $errors[] = 'At least one valid item with quantity and unit price is required.';
        }

        // Only proceed if no validation errors
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $order_number = trim($_POST['order_number'] ?? '');
                if ($order_number === '') {
                    $order_number = generate_purchase_order_code($pdo);
                } else {
                    $chk = $pdo->prepare('SELECT id FROM purchase_orders WHERE order_number = ?');
                    $chk->execute([$order_number]);
                    if ($chk->fetch()) {
                        $errors[] = 'Order code already exists. Please use a different code or leave blank to auto-generate.';
                        $pdo->rollBack();
                        throw new PDOException('Duplicate order code');
                    }
                }

                // Calculate totals from validated items
                $subtotal = 0;
                foreach ($items as $item) {
                    if ((!empty($item['product_id']) || !empty($item['stock_item_id'])) &&
                        isset($item['quantity']) && is_numeric($item['quantity']) && $item['quantity'] > 0 &&
                        isset($item['unit_price']) && is_numeric($item['unit_price']) && $item['unit_price'] > 0) {
                        $subtotal += (float)$item['quantity'] * (float)$item['unit_price'];
                    }
                }

                $tax_rate = (float)($_POST['tax_rate'] ?? 0);
                $tax_amount = $subtotal * ($tax_rate / 100);
                $shipping_cost = (float)($_POST['shipping_cost'] ?? 0);
                $total_amount = $subtotal + $tax_amount + $shipping_cost;

                // Create purchase order
                $stmt = $pdo->prepare('
                    INSERT INTO purchase_orders
                    (order_number, vendor_id, order_date, expected_date, status, subtotal, tax_rate, tax_amount, shipping_cost, total_amount, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $user = current_user();
                $stmt->execute([
                    $order_number, $vendor_id, $order_date, $expected_date, 'draft',
                    $subtotal, $tax_rate, $tax_amount, $shipping_cost, $total_amount, $notes, $user['id']
                ]);

                $purchase_order_id = $pdo->lastInsertId();

                // Add validated order items
                foreach ($items as $item) {
                    if ((!empty($item['product_id']) || !empty($item['stock_item_id'])) &&
                        isset($item['quantity']) && is_numeric($item['quantity']) && $item['quantity'] > 0 &&
                        isset($item['unit_price']) && is_numeric($item['unit_price']) && $item['unit_price'] > 0) {

                        $product_id = (int)($item['product_id'] ?? 0);
                        $stock_item_id = (int)($item['stock_item_id'] ?? 0);
                        $quantity = (float)$item['quantity'];
                        $unit_price = (float)$item['unit_price'];
                        $line_total = $quantity * $unit_price;

                        // Get product/stock item details
                        $item_name = '';
                        $sku = '';
                        if ($stock_item_id > 0) {
                            foreach ($stockItems as $stockItem) {
                                if ($stockItem['id'] == $stock_item_id) {
                                    $item_name = $stockItem['name'];
                                    $sku = 'STOCK-' . $stock_item_id;
                                    break;
                                }
                            }
                        } elseif ($product_id > 0) {
                            foreach ($products as $product) {
                                if ($product['id'] == $product_id) {
                                    $item_name = $product['name'];
                                    $sku = 'PROD-' . str_pad($product['id'], 4, '0', STR_PAD_LEFT);
                                    break;
                                }
                            }
                        }

                        $stmt = $pdo->prepare('
                            INSERT INTO purchase_order_items
                            (purchase_order_id, product_id, stock_item_id, item_name, sku, quantity_ordered, unit_price, line_total)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([$purchase_order_id, $product_id, $stock_item_id, $item_name, $sku, $quantity, $unit_price, $line_total]);
                    }
                }

                $pdo->commit();
                $success = "Purchase order $order_number created successfully.";
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=' . urlencode($success));
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $msg = $e->getMessage();
                if ($msg !== 'Duplicate order code') {
                    $errors[] = 'Failed to create purchase order: ' . htmlspecialchars($msg);
                }
            }
        }
    } elseif ($action === 'update_status') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        $allowed = ['draft', 'sent', 'confirmed', 'partial', 'cancelled'];
        if ($status === 'received') {
            $chk = $pdo->prepare('SELECT COUNT(*) as total, SUM(CASE WHEN COALESCE(quantity_received,0) >= quantity_ordered THEN 1 ELSE 0 END) as completed FROM purchase_order_items WHERE purchase_order_id = ?');
            $chk->execute([$order_id]);
            $r = $chk->fetch();
            if ($r['total'] > 0 && $r['completed'] == $r['total']) {
                $allowed[] = 'received';
            }
        }
        if ($order_id > 0 && in_array($status, $allowed)) {
            try {
                $stmt = $pdo->prepare('UPDATE purchase_orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $stmt->execute([$status, $order_id]);
                $success = 'Order status updated successfully.';
            } catch (PDOException $e) {
                $errors[] = 'Failed to update status: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'delete_order') {
        require_role_or_permission(['admin'], 'purchase_orders.delete');
        $order_id = (int)($_POST['order_id'] ?? 0);
        if ($order_id > 0) {
            try {
                $stmt = $pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
                $stmt->execute([$order_id]);
                $order = $stmt->fetch();
                if ($order && $order['status'] === 'draft') {
                    $chk = $pdo->prepare('SELECT COUNT(*) FROM purchase_payments WHERE purchase_order_id = ?');
                    $chk->execute([$order_id]);
                    if ((int)$chk->fetchColumn() > 0) {
                        $errors[] = 'Cannot delete: order has payments.';
                    } else {
                        $pdo->beginTransaction();
                        $pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$order_id]);
                        $pdo->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$order_id]);
                        $pdo->commit();
                        $success = 'Draft order deleted successfully.';
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=' . urlencode($success));
                        exit;
                    }
                } else {
                    $errors[] = 'Only draft orders can be deleted.';
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Failed to delete order: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'update_order') {
        require_role_or_permission(['admin'], 'purchase_orders.update');
        $order_id = (int)($_POST['order_id'] ?? 0);
        $order_number = trim($_POST['order_number'] ?? '');
        $vendor_id = (int)($_POST['vendor_id'] ?? 0);
        $order_date = $_POST['order_date'] ?? date('Y-m-d');
        $expected_date = trim($_POST['expected_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $tax_rate = (float)($_POST['tax_rate'] ?? 0);
        $shipping_cost = (float)($_POST['shipping_cost'] ?? 0);
        $items = $_POST['items'] ?? [];
        if ($order_id > 0 && $vendor_id > 0) {
            try {
                $stmt = $pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
                $stmt->execute([$order_id]);
                $order = $stmt->fetch();
                if (!$order || $order['status'] !== 'draft') {
                    $errors[] = 'Only draft orders can be edited.';
                } elseif ($order_number === '') {
                    $errors[] = 'Order code is required.';
                } elseif (empty($items)) {
                    $errors[] = 'At least one item is required.';
                } else {
                    $valid_items = [];
                    foreach ($items as $item) {
                        $sel = trim($item['product_select'] ?? $item['product_id'] ?? $item['stock_item_id'] ?? '');
                        $has_product = !empty($sel) || !empty($item['product_id']) || !empty($item['stock_item_id']);
                        if ($has_product &&
                            isset($item['quantity']) && is_numeric($item['quantity']) && (float)$item['quantity'] > 0 &&
                            isset($item['unit_price']) && is_numeric($item['unit_price']) && (float)$item['unit_price'] >= 0) {
                            $valid_items[] = $item;
                        }
                    }
                    if (empty($valid_items)) {
                        $errors[] = 'At least one valid item is required.';
                    } else {
                        $pdo->beginTransaction();
                        $subtotal = 0;
                        foreach ($valid_items as $item) {
                            $qty = (float)$item['quantity'];
                            $price = (float)$item['unit_price'];
                            $subtotal += $qty * $price;
                        }
                        $tax_amount = $subtotal * ($tax_rate / 100);
                        $total_amount = $subtotal + $tax_amount + $shipping_cost;
                        $chk = $pdo->prepare('SELECT id FROM purchase_orders WHERE order_number = ? AND id != ?');
                        $chk->execute([$order_number, $order_id]);
                        if ($chk->fetch()) {
                            $errors[] = 'Order code already exists.';
                        } else {
                        $pdo->prepare('UPDATE purchase_orders SET order_number=?, vendor_id=?, order_date=?, expected_date=?, notes=?, tax_rate=?, shipping_cost=?, subtotal=?, tax_amount=?, total_amount=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
                            ->execute([$order_number, $vendor_id, $order_date, $expected_date ?: null, $notes, $tax_rate, $shipping_cost, $subtotal, $tax_amount, $total_amount, $order_id]);
                        $pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$order_id]);
                        foreach ($valid_items as $item) {
                            $product_id = 0;
                            $stock_item_id = 0;
                            $sel = trim($item['product_select'] ?? $item['product_id'] ?? '');
                            if (preg_match('/^s(\d+)$/', $sel, $m)) {
                                $stock_item_id = (int)$m[1];
                            } elseif (preg_match('/^p(\d+)$/', $sel, $m)) {
                                $product_id = (int)$m[1];
                            } else {
                                $product_id = (int)($item['product_id'] ?? 0);
                                $stock_item_id = (int)($item['stock_item_id'] ?? 0);
                            }
                            $qty = (float)$item['quantity'];
                            $price = (float)$item['unit_price'];
                            $line_total = $qty * $price;
                            $item_name = '';
                            $sku = '';
                            if ($stock_item_id > 0) {
                                foreach ($stockItems as $si) {
                                    if ($si['id'] == $stock_item_id) { $item_name = $si['name']; $sku = 'STOCK-' . $stock_item_id; break; }
                                }
                            }
                            if ($product_id > 0) {
                                foreach ($products as $p) {
                                    if ($p['id'] == $product_id) { $item_name = $p['name']; $sku = 'PROD-' . str_pad($p['id'], 4, '0', STR_PAD_LEFT); break; }
                                }
                            }
                            $pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, stock_item_id, item_name, sku, quantity_ordered, unit_price, line_total) VALUES (?,?,?,?,?,?,?,?)')
                                ->execute([$order_id, $product_id, $stock_item_id, $item_name, $sku, $qty, $price, $line_total]);
                        }
                        $pdo->commit();
                        $success = 'Order updated successfully.';
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=' . urlencode($success));
                        exit;
                        }
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Failed to update order: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'update_unit_prices') {
        require_role_or_permission(['admin'], 'purchase_orders.update');
        $order_id = (int)($_POST['order_id'] ?? 0);
        $prices = $_POST['unit_prices'] ?? [];
        if ($order_id > 0 && !empty($prices)) {
            try {
                $pdo->beginTransaction();
                $subtotal = 0;
                foreach ($prices as $item_id => $price) {
                    $item_id = (int)$item_id;
                    $price = (float)$price;
                    if ($item_id <= 0) continue;
                    $stmt = $pdo->prepare('SELECT quantity_ordered FROM purchase_order_items WHERE id = ? AND purchase_order_id = ?');
                    $stmt->execute([$item_id, $order_id]);
                    $item = $stmt->fetch();
                    if ($item) {
                        $qty = (float)$item['quantity_ordered'];
                        $line_total = $qty * $price;
                        $pdo->prepare('UPDATE purchase_order_items SET unit_price = ?, line_total = ? WHERE id = ?')->execute([$price, $line_total, $item_id]);
                        $subtotal += $line_total;
                    }
                }
                $stmt = $pdo->prepare('SELECT tax_rate, shipping_cost FROM purchase_orders WHERE id = ?');
                $stmt->execute([$order_id]);
                $po = $stmt->fetch();
                $tax_rate = (float)($po['tax_rate'] ?? 0);
                $shipping = (float)($po['shipping_cost'] ?? 0);
                $tax_amount = $subtotal * ($tax_rate / 100);
                $total_amount = $subtotal + $tax_amount + $shipping;
                $pdo->prepare('UPDATE purchase_orders SET subtotal = ?, tax_amount = ?, total_amount = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$subtotal, $tax_amount, $total_amount, $order_id]);
                $pdo->commit();
                $success = 'Unit prices updated successfully.';
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=' . urlencode($success));
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Failed to update unit prices: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'cancel_order_items') {
        require_role_or_permission(['admin'], 'purchase_orders.update');
        $order_id = (int)($_POST['order_id'] ?? 0);
        $adjustments = $_POST['item_adjustments'] ?? [];
        $reason = trim($_POST['cancel_reason'] ?? '');
        if ($order_id > 0 && !empty($adjustments)) {
            if (strlen($reason) === 0) {
                $errors[] = 'Please provide a reason for the item cancellation/reduction.';
            } else {
            try {
                $stmt = $pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
                $stmt->execute([$order_id]);
                $order = $stmt->fetch();
                if (!$order || in_array($order['status'], ['received', 'cancelled'], true)) {
                    $errors[] = 'Cannot adjust items: order is fully received or cancelled.';
                } else {
                    $pdo->beginTransaction();
                    foreach ($adjustments as $item_id => $new_qty) {
                        $item_id = (int)$item_id;
                        $new_qty = (float)$new_qty;
                        if ($item_id <= 0) continue;
                        $stmt = $pdo->prepare('
                            SELECT poi.quantity_ordered, poi.unit_price,
                                   COALESCE((SELECT SUM(pri.quantity_received) FROM purchase_receiving_items pri WHERE pri.purchase_order_item_id = poi.id), 0) as received
                            FROM purchase_order_items poi WHERE poi.id = ? AND poi.purchase_order_id = ?
                        ');
                        $stmt->execute([$item_id, $order_id]);
                        $item = $stmt->fetch();
                        if (!$item) continue;
                        $received = (float)($item['received'] ?? 0);
                        $min_qty = $received;
                        if ($new_qty < $min_qty) $new_qty = $min_qty;
                        if ($new_qty <= 0 && $received <= 0) {
                            $pdo->prepare('DELETE FROM purchase_order_items WHERE id = ?')->execute([$item_id]);
                            continue;
                        }
                        if ($new_qty < (float)$item['quantity_ordered']) {
                            $unit_price = (float)$item['unit_price'];
                            $line_total = $new_qty * $unit_price;
                            $pdo->prepare('UPDATE purchase_order_items SET quantity_ordered = ?, line_total = ? WHERE id = ?')->execute([$new_qty, $line_total, $item_id]);
                        }
                    }
                    $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity_ordered * unit_price), 0) FROM purchase_order_items WHERE purchase_order_id = ?');
                    $stmt->execute([$order_id]);
                    $subtotal = (float)$stmt->fetchColumn();
                    $stmt = $pdo->prepare('SELECT tax_rate, shipping_cost FROM purchase_orders WHERE id = ?');
                    $stmt->execute([$order_id]);
                    $po = $stmt->fetch();
                    $tax_rate = (float)($po['tax_rate'] ?? 0);
                    $shipping = (float)($po['shipping_cost'] ?? 0);
                    $tax_amount = $subtotal * ($tax_rate / 100);
                    $total_amount = $subtotal + $tax_amount + $shipping;
                    $adj_note = "\n[Item adjustment " . date('Y-m-d H:i') . "] Reason: " . $reason;
                    $notesStmt = $pdo->prepare('SELECT notes FROM purchase_orders WHERE id = ?');
                    $notesStmt->execute([$order_id]);
                    $curNotes = $notesStmt->fetchColumn() ?: '';
                    $newNotes = $curNotes . $adj_note;
                    $chk = $pdo->prepare('SELECT COUNT(*) as total, SUM(CASE WHEN COALESCE(quantity_received,0) >= quantity_ordered THEN 1 ELSE 0 END) as completed FROM purchase_order_items WHERE purchase_order_id = ?');
                    $chk->execute([$order_id]);
                    $chkRow = $chk->fetch();
                    $newStatus = ($chkRow['total'] > 0 && $chkRow['completed'] == $chkRow['total']) ? 'received' : null;
                    $updateFields = [$subtotal, $tax_amount, $total_amount, $newNotes, $order_id];
                    $updateSql = 'UPDATE purchase_orders SET subtotal = ?, tax_amount = ?, total_amount = ?, notes = ?, updated_at = CURRENT_TIMESTAMP';
                    if ($newStatus !== null) {
                        $updateSql .= ', status = ?';
                        array_splice($updateFields, -1, 0, [$newStatus]);
                    }
                    $updateSql .= ' WHERE id = ?';
                    $pdo->prepare($updateSql)->execute($updateFields);
                    $pdo->commit();
                    $success = 'Order items updated successfully.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=' . urlencode($success));
                    exit;
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Failed to update items: ' . htmlspecialchars($e->getMessage());
            }
            }
        }
    }
}

// Get purchase orders
try {
    $stmt = $pdo->query('
        SELECT po.*, pv.name as vendor_name, u.name as created_by_name
        FROM purchase_orders po
        LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id
        LEFT JOIN users u ON po.created_by = u.id
        ORDER BY po.created_at DESC
    ');
    $orders = $stmt->fetchAll();
} catch (PDOException $e) {
    $orders = [];
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Purchase Orders</h1>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-success btn-lg" onclick="window.print()">
                <i class="bi bi-printer me-2"></i>Print
            </button>
            <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addOrderModal">
                <i class="bi bi-plus-circle me-2"></i>New Purchase Order
            </button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <!-- Purchase Orders Table -->
    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive table-responsive-full">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center">No</th>
                            <th>Order #</th>
                            <th>Vendor</th>
                            <th>Order Date</th>
                            <th>Expected Date</th>
                            <th>Status</th>
                            <th>Total Amount</th>
                            <th>Items</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$orders): ?>
                        <tr><td colspan="10" class="text-center py-4">No purchase orders found.</td></tr>
                    <?php else: ?>
                        <?php $rowNo = 0; foreach ($orders as $order): $rowNo++; ?>
                            <?php
                            $status_colors = [
                                'draft' => 'secondary',
                                'sent' => 'info',
                                'confirmed' => 'primary',
                                'partial' => 'warning',
                                'received' => 'success',
                                'cancelled' => 'danger'
                            ];
                            $status_color = $status_colors[$order['status']] ?? 'secondary';
                            ?>
                        <tr>
                            <td class="text-center"><?= $rowNo ?></td>
                            <td>
                                <strong><?= htmlspecialchars($order['order_number']) ?></strong>
                                <br><small class="text-muted"><?= date('M j, Y H:i', strtotime($order['created_at'])) ?></small>
                            </td>
                            <td><?= htmlspecialchars($order['vendor_name']) ?></td>
                            <td><?= date('M j, Y', strtotime($order['order_date'])) ?></td>
                            <td><?= $order['expected_date'] ? date('M j, Y', strtotime($order['expected_date'])) : '-' ?></td>
                            <td>
                                <span class="badge bg-<?= $status_color ?>"><?= ucfirst($order['status']) ?></span>
                            </td>
                            <td class="text-end">$<?= number_format($order['total_amount'], 2) ?></td>
                            <td>
                                <?php
                                $itemCountStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = ?');
                                $itemCountStmt->execute([$order['id']]);
                                $itemCount = $itemCountStmt->fetchColumn();
                                echo $itemCount;
                                ?>
                            </td>
                            <td><?= htmlspecialchars($order['created_by_name']) ?></td>
                            <td>
                                <?php
                                $itemsCheck = $pdo->prepare('SELECT COUNT(*) as total, SUM(CASE WHEN COALESCE(quantity_received,0) >= quantity_ordered THEN 1 ELSE 0 END) as completed, COALESCE(SUM(quantity_received), 0) as total_received FROM purchase_order_items WHERE purchase_order_id = ?');
                                $itemsCheck->execute([$order['id']]);
                                $itemsCheckRow = $itemsCheck->fetch();
                                $isFullyReceived = ($itemsCheckRow['total'] > 0 && $itemsCheckRow['completed'] == $itemsCheckRow['total']);
                                $hasReceivedItems = ((float)($itemsCheckRow['total_received'] ?? 0)) > 0;
                                ?>
                                <div class="d-flex flex-wrap gap-1 align-items-center">
                                    <button class="btn btn-outline-primary btn-sm" onclick="viewOrder(<?= $order['id'] ?>)" title="View"><i class="bi bi-eye"></i></button>
                                    <button class="btn btn-outline-info btn-sm" onclick="editUnitPrices(<?= $order['id'] ?>)" title="Unit Prices"><i class="bi bi-currency-dollar"></i></button>
                                    <?php if (!$isFullyReceived && $order['status'] !== 'draft'): ?>
                                        <button class="btn btn-outline-warning btn-sm" onclick="openCancelItemsModal(<?= $order['id'] ?>)" title="Cancel / Reduce Items"><i class="bi bi-dash-circle"></i></button>
                                    <?php endif; ?>
                                    <?php if ($order['status'] === 'draft'): ?>
                                        <button class="btn btn-outline-warning btn-sm" onclick="editOrder(<?= $order['id'] ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Delete this draft order? This cannot be undone.');">
                                            <input type="hidden" name="action" value="delete_order">
                                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($isFullyReceived): ?>
                                        <span class="badge bg-success">Received</span>
                                    <?php else:
                                        $statusOpts = $hasReceivedItems ? ['confirmed', 'partial'] : ['draft', 'sent', 'confirmed', 'partial', 'cancelled'];
                                    ?>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                            <select name="status" class="form-select form-select-sm" style="width: auto; min-width: 100px;" onchange="this.form.submit()">
                                                <?php foreach ($statusOpts as $status): ?>
                                                    <option value="<?= $status ?>" <?= $order['status'] === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit Unit Prices Modal -->
<div class="modal fade" id="editUnitPricesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="editUnitPricesForm">
                <input type="hidden" name="action" value="update_unit_prices">
                <input type="hidden" name="order_id" id="unitPricesOrderId">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Unit Prices — <span id="unitPricesOrderNumber"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Unit prices can be edited for orders in any status.</p>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr><th>Item</th><th>SKU</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Line Total</th></tr>
                            </thead>
                            <tbody id="unitPricesItems"></tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-end gap-3 mt-2">
                        <strong>Subtotal:</strong> <span id="unitPricesSubtotal">$0.00</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Unit Prices</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel / Reduce Items Modal -->
<div class="modal fade" id="cancelItemsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="cancelItemsForm">
                <input type="hidden" name="action" value="cancel_order_items">
                <input type="hidden" name="order_id" id="cancelItemsOrderId">
                <div class="modal-header">
                    <h5 class="modal-title">Cancel or Reduce Items — <span id="cancelItemsOrderNumber"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Reduce quantities or remove items that are not fully received. You cannot reduce below the quantity already received.</p>
                    <div class="mb-3">
                        <label class="form-label">Reason <span class="text-danger">*</span></label>
                        <textarea name="cancel_reason" class="form-control" rows="2" placeholder="e.g. Vendor out of stock, partial delivery only, product discontinued" required></textarea>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr><th>Item</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">New Qty</th><th class="text-end">Unit Price</th></tr>
                            </thead>
                            <tbody id="cancelItemsTable"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-warning">Apply Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Purchase Order Modal -->
<div class="modal fade" id="editOrderModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="post" id="editOrderForm">
                <input type="hidden" name="action" value="update_order">
                <input type="hidden" name="order_id" id="editOrderId">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Purchase Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Order Code *</label>
                            <div class="input-group">
                                <input type="text" name="order_number" id="editOrderNumber" class="form-control" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="generateOrderCode('edit')" title="Generate new code">⟳</button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Vendor *</label>
                            <select name="vendor_id" id="editVendorId" class="form-select" required>
                                <option value="">Select Vendor</option>
                                <?php foreach ($vendors as $vendor): ?>
                                    <option value="<?= $vendor['id'] ?>"><?= htmlspecialchars($vendor['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Order Date *</label>
                            <input type="date" name="order_date" id="editOrderDate" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Expected Date</label>
                            <input type="date" name="expected_date" id="editExpectedDate" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Tax Rate (%)</label>
                            <input type="number" step="0.01" name="tax_rate" id="editTaxRate" class="form-control" value="0" min="0" max="100">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="editNotes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Shipping Cost</label>
                            <input type="number" step="0.01" name="shipping_cost" id="editShippingCost" class="form-control" value="0" min="0">
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Order Items</h6>
                            <button type="button" class="btn btn-sm btn-primary" onclick="addEditOrderItem()">+ Add Item</button>
                        </div>
                        <div class="card-body">
                            <div id="editOrderItems"></div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-8"></div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between"><span>Subtotal:</span><span id="editSubtotal">$0.00</span></div>
                                    <div class="d-flex justify-content-between"><span>Tax:</span><span id="editTax">$0.00</span></div>
                                    <div class="d-flex justify-content-between"><span>Shipping:</span><span id="editShipping">$0.00</span></div>
                                    <hr>
                                    <div class="d-flex justify-content-between fw-bold"><span>Total:</span><span id="editTotal">$0.00</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Purchase Order Modal -->
<div class="modal fade" id="addOrderModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="post" id="purchaseOrderForm">
                <input type="hidden" name="action" value="create_order">
                <div class="modal-header">
                    <h5 class="modal-title">New Purchase Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Order Code</label>
                            <div class="input-group">
                                <input type="text" name="order_number" id="createOrderNumber" class="form-control" placeholder="Auto-generate if blank">
                                <button type="button" class="btn btn-outline-secondary" onclick="generateOrderCode('create')" title="Generate code">⟳</button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Vendor *</label>
                            <select name="vendor_id" class="form-select" required>
                                <option value="">Select Vendor</option>
                                <?php foreach ($vendors as $vendor): ?>
                                    <option value="<?= $vendor['id'] ?>"><?= htmlspecialchars($vendor['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Order Date *</label>
                            <input type="date" name="order_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Expected Date</label>
                            <input type="date" name="expected_date" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Tax Rate (%)</label>
                            <input type="number" step="0.01" name="tax_rate" class="form-control" value="0" min="0" max="100">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Shipping Cost</label>
                            <input type="number" step="0.01" name="shipping_cost" class="form-control" value="0" min="0">
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Order Items</h6>
                            <button type="button" class="btn btn-sm btn-primary" onclick="addOrderItem()">+ Add Item</button>
                        </div>
                        <div class="card-body">
                            <div id="orderItems">
                                <div class="row g-3 order-item" data-item-index="0">
                                    <div class="col-md-3">
                                        <label class="form-label">Product/Stock Item</label>
                                        <select name="items[0][product_id]" class="form-select product-select" onchange="updateItemDetails(0)">
                                            <option value="">Select Product</option>
                                            <optgroup label="Products">
                                                <?php foreach ($products as $product): ?>
                                                    <option value="<?= $product['id'] ?>" data-type="product" data-name="<?= htmlspecialchars($product['name']) ?>" data-cost="<?= $product['cost'] ?>"><?= htmlspecialchars($product['name']) ?> ($<?= number_format($product['cost'], 2) ?>)</option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <optgroup label="Stock Items">
                                                <?php foreach ($stockItems as $item): ?>
                                                    <option value="<?= $item['id'] ?>" data-type="stock" data-name="<?= htmlspecialchars($item['name']) ?>" data-cost="0"><?= htmlspecialchars($item['name']) ?> (<?= $item['current_quantity'] ?> <?= $item['unit'] ?>)</option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Quantity</label>
                                        <input type="number" step="0.01" name="items[0][quantity]" class="form-control" min="0.01" onchange="calculateTotals()">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Unit Price *</label>
                                        <input type="number" step="0.01" name="items[0][unit_price]" class="form-control" min="0" placeholder="Enter price manually" onchange="calculateTotals()" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Line Total</label>
                                        <input type="text" class="form-control" readonly value="0.00">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">&nbsp;</label>
                                        <button type="button" class="btn btn-danger btn-sm w-100" onclick="removeOrderItem(0)">Remove</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-8"></div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>Subtotal:</span>
                                        <span id="subtotal">$0.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Tax:</span>
                                        <span id="tax">$0.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Shipping:</span>
                                        <span id="shipping">$0.00</span>
                                    </div>
                                    <hr>
                                    <div class="d-flex justify-content-between fw-bold">
                                        <span>Total:</span>
                                        <span id="total">$0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Purchase Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let itemIndex = 1;

function generateOrderCode(target) {
    fetch('get_purchase_order_code.php')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.order_number) {
                const el = document.getElementById(target === 'edit' ? 'editOrderNumber' : 'createOrderNumber');
                if (el) el.value = data.order_number;
            }
        })
        .catch(e => console.error(e));
}

function addOrderItem() {
    const container = document.getElementById('orderItems');
    const newItem = document.createElement('div');
    newItem.className = 'row g-3 order-item';
    newItem.setAttribute('data-item-index', itemIndex);
    
    newItem.innerHTML = `
        <div class="col-md-3">
            <label class="form-label">Product/Stock Item</label>
            <select name="items[${itemIndex}][product_id]" class="form-select product-select" onchange="updateItemDetails(${itemIndex})">
                <option value="">Select Product</option>
                <optgroup label="Products">
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product['id'] ?>" data-type="product" data-name="<?= htmlspecialchars($product['name']) ?>" data-cost="<?= $product['cost'] ?>"><?= htmlspecialchars($product['name']) ?> ($<?= number_format($product['cost'], 2) ?>)</option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Stock Items">
                    <?php foreach ($stockItems as $item): ?>
                        <option value="<?= $item['id'] ?>" data-type="stock" data-name="<?= htmlspecialchars($item['name']) ?>" data-cost="0"><?= htmlspecialchars($item['name']) ?> (<?= $item['current_quantity'] ?> <?= $item['unit'] ?>)</option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Quantity</label>
            <input type="number" step="0.01" name="items[${itemIndex}][quantity]" class="form-control" min="0.01" onchange="calculateTotals()">
        </div>
        <div class="col-md-2">
            <label class="form-label">Unit Price *</label>
            <input type="number" step="0.01" name="items[${itemIndex}][unit_price]" class="form-control" min="0" placeholder="Enter price manually" onchange="calculateTotals()" required>
        </div>
        <div class="col-md-2">
            <label class="form-label">Line Total</label>
            <input type="text" class="form-control" readonly value="0.00">
        </div>
        <div class="col-md-2">
            <label class="form-label">&nbsp;</label>
            <button type="button" class="btn btn-danger btn-sm w-100" onclick="removeOrderItem(${itemIndex})">Remove</button>
        </div>
    `;
    
    container.appendChild(newItem);
    itemIndex++;
}

function removeOrderItem(index) {
    const item = document.querySelector(`[data-item-index="${index}"]`);
    if (item) {
        item.remove();
        calculateTotals();
    }
}

function updateItemDetails(index) {
    const select = document.querySelector(`[data-item-index="${index}"] select`);
    const option = select.options[select.selectedIndex];

    // No longer auto-fill unit price - users must enter it manually
    // The unit price field now has placeholder "Enter price manually"
    calculateTotals();
}

function calculateTotals() {
    let subtotal = 0;
    const items = document.querySelectorAll('.order-item');
    
    items.forEach(item => {
        const quantity = parseFloat(item.querySelector('input[name*="quantity"]').value) || 0;
        const unitPrice = parseFloat(item.querySelector('input[name*="unit_price"]').value) || 0;
        const lineTotal = quantity * unitPrice;
        
        item.querySelector('input[readonly]').value = lineTotal.toFixed(2);
        subtotal += lineTotal;
    });
    
    const taxRate = parseFloat(document.querySelector('input[name="tax_rate"]').value) || 0;
    const shipping = parseFloat(document.querySelector('input[name="shipping_cost"]').value) || 0;
    
    const tax = subtotal * (taxRate / 100);
    const total = subtotal + tax + shipping;
    
    document.getElementById('subtotal').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('tax').textContent = '$' + tax.toFixed(2);
    document.getElementById('shipping').textContent = '$' + shipping.toFixed(2);
    document.getElementById('total').textContent = '$' + total.toFixed(2);
}

let editItemIndex = 0;
const productsOpts = <?= json_encode($products) ?>;
const stockItemsOpts = <?= json_encode($stockItems) ?>;

function openCancelItemsModal(orderId) {
    fetch('get_purchase_order_details.php?id=' + orderId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + (data.message || 'Failed to load order'));
                return;
            }
            const order = data.order;
            const items = data.items || [];
            document.getElementById('cancelItemsOrderId').value = orderId;
            document.getElementById('cancelItemsOrderNumber').textContent = order.order_number || '';
            const tbody = document.getElementById('cancelItemsTable');
            tbody.innerHTML = '';
            items.forEach(item => {
                const ordered = parseFloat(item.quantity_ordered) || 0;
                const received = parseFloat(item.quantity_received) || 0;
                const pending = ordered - received;
                const unitPrice = parseFloat(item.unit_price) || 0;
                const canAdjust = pending > 0 || (ordered > 0 && received === 0);
                const minQty = received;
                const tr = document.createElement('tr');
                tr.innerHTML = '<td>' + (item.item_name || '').replace(/</g, '&lt;') + '<br><small class="text-muted">' + (item.sku || '-').replace(/</g, '&lt;') + '</small></td>' +
                    '<td class="text-end">' + ordered.toFixed(2) + '</td>' +
                    '<td class="text-end">' + received.toFixed(2) + '</td>' +
                    '<td class="text-end">' + (canAdjust
                        ? '<input type="number" step="0.01" name="item_adjustments[' + item.id + ']" class="form-control form-control-sm text-end" style="width:90px;display:inline-block" value="' + ordered.toFixed(2) + '" min="' + minQty + '" max="' + ordered + '" onchange="validateCancelQty(this,' + minQty + ',' + ordered + ')">'
                        : '<span class="text-success">' + ordered.toFixed(2) + ' (full)</span>') + '</td>' +
                    '<td class="text-end">$' + unitPrice.toFixed(2) + '</td>';
                tbody.appendChild(tr);
            });
            const hasAdjustable = items.some(i => (parseFloat(i.quantity_ordered) || 0) - (parseFloat(i.quantity_received) || 0) > 0);
            if (!hasAdjustable) {
                const tr = document.createElement('tr');
                tr.innerHTML = '<td colspan="5" class="text-center text-muted">All items are fully received. Nothing to adjust.</td>';
                tbody.appendChild(tr);
            }
            const submitBtn = document.querySelector('#cancelItemsForm button[type="submit"]');
            if (submitBtn) submitBtn.disabled = !hasAdjustable;
            new bootstrap.Modal(document.getElementById('cancelItemsModal')).show();
        })
        .catch(e => { console.error(e); alert('Failed to load order'); });
}

function validateCancelQty(input, minQty, maxQty) {
    const v = parseFloat(input.value) || 0;
    if (v < minQty) input.value = minQty;
    if (v > maxQty) input.value = maxQty;
}

function editUnitPrices(orderId) {
    fetch('get_purchase_order_details.php?id=' + orderId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('unitPricesOrderId').value = orderId;
                document.getElementById('unitPricesOrderNumber').textContent = data.order.order_number || '';
                const tbody = document.getElementById('unitPricesItems');
                tbody.innerHTML = '';
                let subtotal = 0;
                (data.items || []).forEach(item => {
                    const qty = parseFloat(item.quantity_ordered) || 0;
                    const price = parseFloat(item.unit_price) || 0;
                    const lineTotal = qty * price;
                    subtotal += lineTotal;
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<td>' + (item.item_name || '').replace(/</g, '&lt;') + '</td>' +
                        '<td><code>' + (item.sku || '-').replace(/</g, '&lt;') + '</code></td>' +
                        '<td class="text-end">' + qty.toFixed(2) + '</td>' +
                        '<td class="text-end"><input type="number" step="0.01" name="unit_prices[' + item.id + ']" value="' + price.toFixed(2) + '" class="form-control form-control-sm text-end" style="width: 100px; display: inline-block;" onchange="calcUnitPricesSubtotal()"></td>' +
                        '<td class="text-end unit-price-line-total">$' + lineTotal.toFixed(2) + '</td>';
                    tbody.appendChild(tr);
                });
                document.getElementById('unitPricesSubtotal').textContent = '$' + subtotal.toFixed(2);
                new bootstrap.Modal(document.getElementById('editUnitPricesModal')).show();
            } else {
                alert('Error: ' + (data.message || 'Failed to load order'));
            }
        })
        .catch(e => { console.error(e); alert('Failed to load order details'); });
}

function calcUnitPricesSubtotal() {
    let subtotal = 0;
    document.querySelectorAll('#unitPricesItems tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('td:nth-child(3)')?.textContent) || 0;
        const price = parseFloat(tr.querySelector('input[name^="unit_prices"]')?.value) || 0;
        const lineTotal = qty * price;
        const lineEl = tr.querySelector('.unit-price-line-total');
        if (lineEl) lineEl.textContent = '$' + lineTotal.toFixed(2);
        subtotal += lineTotal;
    });
    const el = document.getElementById('unitPricesSubtotal');
    if (el) el.textContent = '$' + subtotal.toFixed(2);
}

function editOrder(orderId) {
    fetch('get_purchase_order_details.php?id=' + orderId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const order = data.order;
                document.getElementById('editOrderId').value = order.id;
                document.getElementById('editOrderNumber').value = order.order_number || '';
                document.getElementById('editVendorId').value = order.vendor_id;
                document.getElementById('editOrderDate').value = order.order_date || '';
                document.getElementById('editExpectedDate').value = order.expected_date || '';
                document.getElementById('editTaxRate').value = order.tax_rate || 0;
                document.getElementById('editShippingCost').value = order.shipping_cost || 0;
                document.getElementById('editNotes').value = order.notes || '';
                document.getElementById('editOrderItems').innerHTML = '';
                editItemIndex = 0;
                (data.items || []).forEach((item, i) => {
                    const prodId = parseInt(item.product_id) || 0;
                    const stockId = parseInt(item.stock_item_id) || 0;
                    const selVal = stockId > 0 ? 's' + stockId : (prodId > 0 ? 'p' + prodId : '');
                    addEditOrderItem(selVal, item.quantity_ordered, item.unit_price);
                });
                if ((data.items || []).length === 0) addEditOrderItem();
                calculateEditTotals();
                new bootstrap.Modal(document.getElementById('editOrderModal')).show();
            } else {
                alert('Error: ' + (data.message || 'Failed to load order'));
            }
        })
        .catch(e => { console.error(e); alert('Failed to load order details'); });
}

function addEditOrderItem(selectedVal, qty, unitPrice) {
    const idx = editItemIndex++;
    let prodOpts = '<option value="">Select Product</option><optgroup label="Products">';
    (productsOpts || []).forEach(p => {
        prodOpts += '<option value="p' + p.id + '" data-name="' + (p.name || '').replace(/"/g, '&quot;') + '">' + (p.name || '') + ' ($' + (parseFloat(p.cost) || 0).toFixed(2) + ')</option>';
    });
    prodOpts += '</optgroup><optgroup label="Stock Items">';
    (stockItemsOpts || []).forEach(s => {
        prodOpts += '<option value="s' + s.id + '" data-name="' + (s.name || '').replace(/"/g, '&quot;') + '">' + (s.name || '') + ' (' + (s.current_quantity || 0) + ' ' + (s.unit || '') + ')</option>';
    });
    prodOpts += '</optgroup>';
    const div = document.createElement('div');
    div.className = 'row g-3 order-item mb-2';
    div.setAttribute('data-edit-index', idx);
    div.innerHTML = '<div class="col-md-3"><label class="form-label">Product/Stock</label><select name="items[' + idx + '][product_select]" class="form-select product-select-edit" onchange="calculateEditTotals()">' + prodOpts + '</select></div>' +
        '<div class="col-md-2"><label class="form-label">Qty</label><input type="number" step="0.01" name="items[' + idx + '][quantity]" class="form-control" value="' + (qty || '') + '" min="0.01" onchange="calculateEditTotals()"></div>' +
        '<div class="col-md-2"><label class="form-label">Unit Price</label><input type="number" step="0.01" name="items[' + idx + '][unit_price]" class="form-control" value="' + (unitPrice || '') + '" min="0" onchange="calculateEditTotals()"></div>' +
        '<div class="col-md-2"><label class="form-label">Line Total</label><input type="text" class="form-control" readonly></div>' +
        '<div class="col-md-2"><label class="form-label">&nbsp;</label><button type="button" class="btn btn-danger btn-sm w-100" onclick="this.closest(\'.order-item\').remove();calculateEditTotals()">Remove</button></div>';
    document.getElementById('editOrderItems').appendChild(div);
    if (selectedVal) div.querySelector('select').value = selectedVal;
    calculateEditTotals();
}

function calculateEditTotals() {
    let subtotal = 0;
    document.querySelectorAll('#editOrderItems .order-item').forEach(item => {
        const qty = parseFloat(item.querySelector('input[name*="quantity"]')?.value) || 0;
        const price = parseFloat(item.querySelector('input[name*="unit_price"]')?.value) || 0;
        const lineTotal = qty * price;
        const ro = item.querySelector('input[readonly]');
        if (ro) ro.value = lineTotal.toFixed(2);
        subtotal += lineTotal;
    });
    const taxRate = parseFloat(document.getElementById('editTaxRate')?.value) || 0;
    const shipping = parseFloat(document.getElementById('editShippingCost')?.value) || 0;
    const tax = subtotal * (taxRate / 100);
    const total = subtotal + tax + shipping;
    const e = id => document.getElementById(id);
    if (e('editSubtotal')) e('editSubtotal').textContent = '$' + subtotal.toFixed(2);
    if (e('editTax')) e('editTax').textContent = '$' + tax.toFixed(2);
    if (e('editShipping')) e('editShipping').textContent = '$' + shipping.toFixed(2);
    if (e('editTotal')) e('editTotal').textContent = '$' + total.toFixed(2);
}

function viewOrder(orderId) {
    console.log('Viewing order:', orderId);
    
    fetch('get_purchase_order_details.php?id=' + orderId)
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);
            return response.json();
        })
        .then(data => {
            console.log('API response:', data);
            if (data.success) {
                displayOrderDetails(data);
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to load order details. Check console for details.');
        });
}

function displayOrderDetails(data) {
    const order = data.order;
    const items = data.items;
    const receivingHistory = data.receiving_history || [];
    const returnHistory = data.return_history || [];
    
    // Build items table HTML
    let itemsHtml = '';
    items.forEach(item => {
        const unitPrice = parseFloat(item.unit_price) || 0;
        const lineTotal = parseFloat(item.line_total) || 0;
        const receivedPercent = item.quantity_ordered > 0 ? (item.quantity_received / item.quantity_ordered * 100).toFixed(1) : 0;
        itemsHtml += `
            <tr>
                <td>${item.item_name}</td>
                <td>${item.sku}</td>
                <td class="text-end">${item.quantity_ordered}</td>
                <td class="text-end">${item.quantity_received}</td>
                <td class="text-end">${receivedPercent}%</td>
                <td class="text-end">$${unitPrice.toFixed(2)}</td>
                <td class="text-end">$${lineTotal.toFixed(2)}</td>
            </tr>
        `;
    });
    
    // Build receiving history HTML with item details
    let receivingHtml = '';
    if (receivingHistory.length > 0) {
        receivingHistory.forEach(receiving => {
            const totalValue = parseFloat(receiving.total_value) || 0;
            const receivedByName = receiving.received_by_name || '-';
            const items = receiving.items || [];
            let itemsRows = '';
            items.forEach(it => {
                const qty = parseFloat(it.quantity_received) || 0;
                const unitCost = parseFloat(it.unit_cost) || 0;
                const amt = parseFloat(it.total_cost) || 0;
                const loc = (it.storage_location || '-').replace(/</g, '&lt;');
                itemsRows += `<tr><td>${(it.item_name || '').replace(/</g, '&lt;')}</td><td class="text-end">${qty.toFixed(2)}</td><td>${loc}</td><td class="text-end">$${unitCost.toFixed(2)}</td><td class="text-end">$${amt.toFixed(2)}</td></tr>`;
            });
            receivingHtml += `
                <tr>
                    <td>${receiving.receiving_date}</td>
                    <td>${receiving.receipt_number || ('REC-' + receiving.id)}</td>
                    <td>${receivedByName}</td>
                    <td class="text-end">${receiving.total_items}</td>
                    <td class="text-end">$${totalValue.toFixed(2)}</td>
                    <td>${receiving.storage_receipt ? receiving.storage_receipt.receipt_number : '-'}</td>
                </tr>
                ${items.length ? `<tr class="table-light"><td colspan="6" class="p-0"><table class="table table-sm mb-0"><thead><tr><th class="ps-4">Product</th><th class="text-end">Qty</th><th>Storage Location</th><th class="text-end">Unit Cost</th><th class="text-end">Amount</th></tr></thead><tbody>${itemsRows}</tbody></table></td></tr>` : ''}
            `;
        });
    } else {
        receivingHtml = '<tr><td colspan="6" class="text-center">No receiving records found</td></tr>';
    }

    // Build return history HTML with item details
    let returnHtml = '';
    if (returnHistory.length > 0) {
        returnHistory.forEach(ret => {
            const totalAmt = parseFloat(ret.total_amount) || 0;
            const createdBy = ret.created_by_name || '-';
            const retItems = ret.items || [];
            let itemsRows = '';
            retItems.forEach(it => {
                const qty = parseFloat(it.quantity_returned) || 0;
                const unitCost = parseFloat(it.unit_cost) || 0;
                const amt = parseFloat(it.total_cost) || 0;
                const loc = (it.storage_location || '-').replace(/</g, '&lt;');
                itemsRows += `<tr><td>${(it.item_name || '').replace(/</g, '&lt;')}</td><td class="text-end">${qty.toFixed(2)}</td><td>${loc}</td><td class="text-end">$${unitCost.toFixed(2)}</td><td class="text-end">$${amt.toFixed(2)}</td></tr>`;
            });
            returnHtml += `
                <tr>
                    <td>${ret.return_date}</td>
                    <td><a href="purchase_returns.php?edit=${ret.id}" target="_blank">${ret.return_number || ('PR-' + ret.id)}</a></td>
                    <td>${(ret.reason || '-').replace(/</g, '&lt;')}</td>
                    <td>${createdBy}</td>
                    <td class="text-end">${retItems.length}</td>
                    <td class="text-end">$${totalAmt.toFixed(2)}</td>
                </tr>
                ${retItems.length ? `<tr class="table-light"><td colspan="6" class="p-0"><table class="table table-sm mb-0"><thead><tr><th class="ps-4">Product</th><th class="text-end">Qty</th><th>Storage Location</th><th class="text-end">Unit Cost</th><th class="text-end">Amount</th></tr></thead><tbody>${itemsRows}</tbody></table></td></tr>` : ''}
            `;
        });
    } else {
        returnHtml = '<tr><td colspan="6" class="text-center">No return records found</td></tr>';
    }
    
    // Create and show modal
    const modalHtml = `
        <div class="modal fade" id="viewOrderModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Purchase Order Details: ${order.order_number}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6>Order Information</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>Vendor:</strong></td><td>${order.vendor_name}</td></tr>
                                    <tr><td><strong>Order Date:</strong></td><td>${new Date(order.order_date).toLocaleDateString()}</td></tr>
                                    <tr><td><strong>Expected Date:</strong></td><td>${order.expected_date ? new Date(order.expected_date).toLocaleDateString() : '-'}</td></tr>
                                    <tr><td><strong>Status:</strong></td><td><span class="badge bg-${getStatusColor(order.status)}">${order.status.toUpperCase()}</span></td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6>Financial Information</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>Subtotal:</strong></td><td class="text-end">$${(parseFloat(order.subtotal) || 0).toFixed(2)}</td></tr>
                                    <tr><td><strong>Tax (${order.tax_rate}%):</strong></td><td class="text-end">$${(parseFloat(order.tax_amount) || 0).toFixed(2)}</td></tr>
                                    <tr><td><strong>Shipping:</strong></td><td class="text-end">$${(parseFloat(order.shipping_cost) || 0).toFixed(2)}</td></tr>
                                    <tr><td><strong>Total:</strong></td><td class="text-end"><strong>$${(parseFloat(order.total_amount) || 0).toFixed(2)}</strong></td></tr>
                                </table>
                            </div>
                        </div>
                        
                        ${order.notes ? `<div class="mb-3"><h6>Notes</h6><p>${order.notes}</p></div>` : ''}
                        
                        <h6>Order Items</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th>SKU</th>
                                        <th class="text-end">Ordered</th>
                                        <th class="text-end">Received</th>
                                        <th class="text-end">%</th>
                                        <th class="text-end">Unit Price</th>
                                        <th class="text-end">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsHtml}
                                </tbody>
                            </table>
                        </div>
                        
                        <h6 class="mt-3">Receiving History</h6>
                        <p class="text-muted small">Date/time, who received, product quantities and amounts per delivery.</p>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt #</th>
                                        <th>Received By</th>
                                        <th class="text-end">Items</th>
                                        <th class="text-end">Value</th>
                                        <th>Storage Receipt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${receivingHtml}
                                </tbody>
                            </table>
                        </div>

                        <h6 class="mt-4">Return History</h6>
                        <p class="text-muted small">Returns to vendor with item quantities and amounts.</p>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Return #</th>
                                        <th>Reason</th>
                                        <th>Created By</th>
                                        <th class="text-end">Items</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${returnHtml}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('viewOrderModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to page and show it
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = new bootstrap.Modal(document.getElementById('viewOrderModal'));
    modal.show();
    
    // Clean up modal after it's hidden
    document.getElementById('viewOrderModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

function getStatusColor(status) {
    const colors = {
        'draft': 'secondary',
        'sent': 'info',
        'confirmed': 'primary',
        'partial': 'warning',
        'received': 'success',
        'cancelled': 'danger'
    };
    return colors[status] || 'secondary';
}

// Initialize calculations
document.addEventListener('DOMContentLoaded', function() {
    const tr = document.querySelector('input[name="tax_rate"]');
    const sc = document.querySelector('input[name="shipping_cost"]');
    if (tr) tr.addEventListener('change', calculateTotals);
    if (sc) sc.addEventListener('change', calculateTotals);
    document.getElementById('editOrderModal')?.addEventListener('show.bs.modal', function() {
        document.getElementById('editTaxRate')?.addEventListener('change', calculateEditTotals);
        document.getElementById('editShippingCost')?.addEventListener('change', calculateEditTotals);
    });
    const viewId = new URLSearchParams(window.location.search).get('view');
    if (viewId && typeof viewOrder === 'function') viewOrder(parseInt(viewId, 10));
});
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
