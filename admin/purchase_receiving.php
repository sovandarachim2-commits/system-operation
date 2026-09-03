<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_receiving.view');

$pdo = get_db_connection();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'receive_order') {
        $purchase_order_id = (int)($_POST['purchase_order_id'] ?? 0);
        $receiving_date = $_POST['receiving_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $items = $_POST['items'] ?? [];

        if ($purchase_order_id <= 0) {
            $errors[] = 'Purchase order is required.';
        }

        if (empty($items)) {
            $errors[] = 'At least one item is required.';
        }

        // Validate each item has required fields for receiving
        $valid_items = 0;
        foreach ($items as $item_id => $item_data) {
            $quantity_received = (float)($item_data['quantity_received'] ?? 0);
            $storage_location_id = (int)($item_data['storage_location_id'] ?? 0);

            // Only validate items that have quantity received > 0
            if ($quantity_received > 0) {
                // Check storage location is selected
                if ($storage_location_id <= 0) {
                    $errors[] = "Item #$item_id: Storage location is required when receiving items.";
                }

                // Quantity must be positive (already checked by > 0 condition)
                $valid_items++;
            }
        }

        if ($valid_items == 0 && !empty($items)) {
            $errors[] = 'At least one item must have quantity received greater than 0.';
        }

        // Only proceed if no validation errors
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // Create receiving record
                $user = current_user();
                $stmt = $pdo->prepare('INSERT INTO purchase_receiving (purchase_order_id, receiving_date, received_by, notes) VALUES (?, ?, ?, ?)');
                $stmt->execute([$purchase_order_id, $receiving_date, $user['id'], $notes]);
                $receiving_id = $pdo->lastInsertId();

                $total_received = 0;
                $storage_items = []; // Collect items for storage receipt

                foreach ($items as $item_id => $item_data) {
                    $quantity_received = (float)($item_data['quantity_received'] ?? 0);
                    $storage_location_id = (int)($item_data['storage_location_id'] ?? 0);
                    $location = trim($item_data['location'] ?? '');
                    $item_notes = trim($item_data['notes'] ?? '');

                    if ($quantity_received > 0) {
                        // Get order item details
                        $itemStmt = $pdo->prepare('SELECT * FROM purchase_order_items WHERE id = ?');
                        $itemStmt->execute([$item_id]);
                        $order_item = $itemStmt->fetch();

                        if ($order_item) {
                            $unit_cost = $order_item['unit_price'];
                            $total_cost = $quantity_received * $unit_cost;

                            // Add receiving item
                            $stmt = $pdo->prepare('INSERT INTO purchase_receiving_items (receiving_id, purchase_order_item_id, quantity_received, unit_cost, total_cost, location, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
                            $stmt->execute([$receiving_id, $item_id, $quantity_received, $unit_cost, $total_cost, $location, $item_notes]);

                            // Update order item received quantity
                            $stmt = $pdo->prepare('UPDATE purchase_order_items SET quantity_received = quantity_received + ? WHERE id = ?');
                            $stmt->execute([$quantity_received, $item_id]);

                            // Add to stock movements if linked to stock item
                            if ($order_item['stock_item_id'] > 0) {
                                $moveStmt = $pdo->prepare('INSERT INTO stock_movements (item_id, movement_type, quantity, reference_type, reference_id, reference_no, reason, unit_cost, total_cost, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                                $moveStmt->execute([
                                    $order_item['stock_item_id'],
                                    'in',
                                    $quantity_received,
                                    'purchase',
                                    $purchase_order_id,
                                    'PO-' . $purchase_order_id,
                                    'Purchase receiving',
                                    $unit_cost,
                                    $total_cost,
                                    $user['id']
                                ]);

                                // Update stock item quantity
                                $stmt = $pdo->prepare('UPDATE stock_items SET current_quantity = current_quantity + ? WHERE id = ?');
                                $stmt->execute([$quantity_received, $order_item['stock_item_id']]);
                            }

                            // If storage location is specified, collect for storage receipt
                            if ($storage_location_id > 0) {
                                // Use products.name when product_id set - ensures inventory matches print/orders
                                $item_name = $order_item['item_name'];
                                if (!empty($order_item['product_id'])) {
                                    $pstmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                                    $pstmt->execute([$order_item['product_id']]);
                                    $pname = $pstmt->fetchColumn();
                                    if ($pname !== false && $pname !== '') {
                                        $item_name = $pname;
                                    }
                                }
                                $storage_items[] = [
                                    'purchase_order_item_id' => $item_id,
                                    'item_name' => $item_name,
                                    'sku' => $order_item['sku'],
                                    'quantity_received' => $quantity_received,
                                    'unit_cost' => $unit_cost,
                                    'total_cost' => $total_cost,
                                    'storage_location_id' => $storage_location_id,
                                    'location' => $location,
                                    'notes' => $item_notes
                                ];
                            }

                            $total_received += $quantity_received;
                        }
                    }
                }

                // Create storage receipt if items have storage locations
                if (!empty($storage_items)) {
                    $receipt_number = 'STR-' . date('Y-m-d') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                    
                    $stmt = $pdo->prepare('INSERT INTO storage_receipts (receipt_number, purchase_order_id, receiving_id, receipt_date, received_by, status, total_items, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$receipt_number, $purchase_order_id, $receiving_id, $receiving_date, $user['id'], 'stored', count($storage_items), 'Auto-created from receiving']);

                    $receipt_id = $pdo->lastInsertId();

                    // Add storage receipt items
                    foreach ($storage_items as $item) {
                        $stmt = $pdo->prepare('INSERT INTO storage_receipt_items (receipt_id, purchase_order_item_id, item_name, sku, quantity_received, unit_cost, total_cost, storage_location_id, storage_bin, quality_status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([
                            $receipt_id,
                            $item['purchase_order_item_id'],
                            $item['item_name'],
                            $item['sku'],
                            $item['quantity_received'],
                            $item['unit_cost'],
                            $item['total_cost'],
                            $item['storage_location_id'],
                            $item['location'],
                            'approved',
                            $item['notes']
                        ]);

                        // Add inventory movement
                        $stmt = $pdo->prepare('INSERT INTO inventory_movements (movement_type, item_name, sku, quantity, unit_cost, total_cost, to_location_id, reference_type, reference_id, reference_no, reason, user_id, movement_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([
                            'purchase_in',
                            $item['item_name'],
                            $item['sku'],
                            $item['quantity_received'],
                            $item['unit_cost'],
                            $item['total_cost'],
                            $item['storage_location_id'],
                            'purchase',
                            $purchase_order_id,
                            'PO-' . $purchase_order_id,
                            'Receiving with storage assignment',
                            $user['id'],
                            $receiving_date
                        ]);

                        // Update current inventory - INSERT new row each time for FIFO (bulk print reduces oldest first)
                        $stmt = $pdo->prepare('INSERT INTO current_inventory (item_name, sku, storage_location_id, quantity_on_hand, unit_cost, updated_by) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmt->execute([
                            $item['item_name'],
                            $item['sku'],
                            $item['storage_location_id'],
                            $item['quantity_received'],
                            $item['unit_cost'],
                            $user['id']
                        ]);
                    }

                    // Update receiving record with storage receipt reference
                    $stmt = $pdo->prepare('UPDATE purchase_receiving SET storage_receipt_id = ? WHERE id = ?');
                    $stmt->execute([$receipt_id, $receiving_id]);
                }

                // Update purchase order status
                $stmt = $pdo->prepare('SELECT COUNT(*) as total_items, SUM(CASE WHEN quantity_received >= quantity_ordered THEN 1 ELSE 0 END) as completed_items FROM purchase_order_items WHERE purchase_order_id = ?');
                $stmt->execute([$purchase_order_id]);
                $status_check = $stmt->fetch();

                if ($status_check['total_items'] == $status_check['completed_items']) {
                    $new_status = 'received';
                } elseif ($total_received > 0) {
                    $new_status = 'partial';
                } else {
                    $new_status = 'confirmed';
                }

                $stmt = $pdo->prepare('UPDATE purchase_orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $stmt->execute([$new_status, $purchase_order_id]);

                $pdo->commit();
                $storage_msg = !empty($storage_items) ? " Storage receipt created for " . count($storage_items) . " items." : "";
                $success = "Purchase order received successfully. $total_received items processed.$storage_msg";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Failed to receive order: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Get purchase orders ready for receiving (exclude fully received orders)
try {
    $stmt = $pdo->query("
        SELECT po.*, pv.name as vendor_name 
        FROM purchase_orders po
        LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id
        WHERE po.status IN ('confirmed', 'partial')
        AND EXISTS (
            SELECT 1 FROM purchase_order_items poi 
            WHERE poi.purchase_order_id = po.id 
            AND COALESCE(poi.quantity_received, 0) < poi.quantity_ordered
        )
        ORDER BY po.expected_date ASC, po.created_at DESC
    ");
    $orders = $stmt->fetchAll();
} catch (PDOException $e) {
    $orders = [];
    $errors[] = 'Purchase orders table not found. Please run setup script first.';
}

// Get storage locations for receiving
try {
    $locationsStmt = $pdo->query('SELECT * FROM storage_locations WHERE is_active = 1 ORDER BY location_code');
    $locations = $locationsStmt->fetchAll();
} catch (PDOException $e) {
    $locations = [];
    $errors[] = 'Storage locations not available. Please run storage setup script first.';
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Purchase Receiving</h1>
        <div class="d-flex gap-2">
            <a href="purchase_returns.php" class="btn btn-outline-warning btn-lg">
                <i class="bi bi-arrow-return-left me-2"></i>Returns to Vendor
            </a>
            <a href="purchase_receiving_history.php" class="btn btn-outline-secondary btn-lg">
                <i class="bi bi-clock-history me-2"></i>Receiving History
            </a>
            <a href="storage_receipts.php" class="btn btn-outline-success btn-lg">
                <i class="bi bi-archive me-2"></i>Storage Receipts
            </a>
            <a href="inventory_view.php" class="btn btn-outline-info btn-lg">
                <i class="bi bi-box-seam me-2"></i>View Inventory
            </a>
            <a href="purchase_orders.php" class="btn btn-outline-primary btn-lg">
                <i class="bi bi-list me-2"></i>View Orders
            </a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <!-- Orders Ready for Receiving -->
    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-header bg-light">
            <h5 class="mb-0">Orders Ready for Receiving</h5>
        </div>
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive table-responsive-full">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order #</th>
                            <th>Vendor</th>
                            <th>Order Date</th>
                            <th>Expected Date</th>
                            <th>Status</th>
                            <th>Total Amount</th>
                            <th>Items</th>
                            <th>Progress</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$orders): ?>
                        <tr><td colspan="9" class="text-center py-4">No orders ready for receiving.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            // Get order items and progress
                            $itemsStmt = $pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_order_id = ?');
                            $itemsStmt->execute([$order['id']]);
                            $order_items = $itemsStmt->fetchAll();
                            
                            $total_items = count($order_items);
                            $completed_items = 0;
                            $total_qty = 0;
                            $received_qty = 0;
                            
                            foreach ($order_items as $item) {
                                $total_qty += $item['quantity_ordered'];
                                $received_qty += $item['quantity_received'];
                                if ($item['quantity_received'] >= $item['quantity_ordered']) {
                                    $completed_items++;
                                }
                            }
                            
                            $progress_percent = $total_qty > 0 ? ($received_qty / $total_qty) * 100 : 0;
                            ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($order['order_number']) ?></strong>
                            </td>
                            <td><?= htmlspecialchars($order['vendor_name']) ?></td>
                            <td><?= date('M j, Y', strtotime($order['order_date'])) ?></td>
                            <td><?= $order['expected_date'] ? date('M j, Y', strtotime($order['expected_date'])) : '-' ?></td>
                            <td>
                                <span class="badge bg-<?= $order['status'] === 'confirmed' ? 'primary' : 'warning' ?>">
                                    <?= ucfirst($order['status']) ?>
                                </span>
                            </td>
                            <td class="text-end">$<?= number_format($order['total_amount'], 2) ?></td>
                            <td><?= $completed_items ?>/<?= $total_items ?></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar" style="width: <?= $progress_percent ?>%">
                                        <?= number_format($progress_percent, 1) ?>%
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <button class="btn btn-outline-primary btn-sm" onclick="viewOrderDetails(<?= $order['id'] ?>)">
                                        <i class="bi bi-eye me-1"></i>View
                                    </button>
                                    <button class="btn btn-primary btn-sm" onclick="openReceivingModal(<?= $order['id'] ?>)">
                                        <i class="bi bi-truck me-1"></i>Receive
                                    </button>
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

<!-- Receiving Modal -->
<div class="modal fade" id="receivingModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="post" id="receivingForm">
                <input type="hidden" name="action" value="receive_order">
                <input type="hidden" name="purchase_order_id" id="purchaseOrderId">
                <div class="modal-header">
                    <h5 class="modal-title">Receive Purchase Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Receiving Date</label>
                            <input type="date" name="receiving_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="1" placeholder="Any receiving notes..."></textarea>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Items to Receive</h6>
                        </div>
                        <div class="card-body">
                            <div id="receivingItems">
                                <!-- Items will be loaded here via JavaScript -->
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Process Receiving</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Order Details Modal -->
<div class="modal fade" id="viewOrderModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Purchase Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="orderDetailsContent">
                    <!-- Order details will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="receiveFromViewBtn">Receive Items</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentViewOrderId = null;

function viewOrderDetails(orderId) {
    currentViewOrderId = orderId;
    console.log('Viewing order details for:', orderId);
    
    // Load order details via AJAX
    fetch(`get_purchase_order_details.php?id=${orderId}`)
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('API response:', data);
            if (data.success) {
                displayOrderDetails(data.order, data.items, data.receiving_history);
            } else {
                let errorMsg = data.message || 'Unknown error occurred';
                if (errorMsg.includes('not found')) {
                    errorMsg = 'Order not found. It may have been deleted.';
                } else if (errorMsg.includes('Database error')) {
                    errorMsg = 'Database connection error. Please check if all required tables are created.';
                }
                
                const content = document.getElementById('orderDetailsContent');
                content.innerHTML = `
                    <div class="alert alert-danger">
                        <h6>Error Loading Order Details</h6>
                        <p>${errorMsg}</p>
                        <hr>
                        <small class="text-muted">
                            Please ensure all purchase management tables are created by running:
                            <br><code>complete_purchase_management.sql</code>
                        </small>
                    </div>
                `;
                
                // Hide receive button
                document.getElementById('receiveFromViewBtn').style.display = 'none';
                
                // Show modal anyway to display error
                const modal = new bootstrap.Modal(document.getElementById('viewOrderModal'));
                modal.show();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            
            const content = document.getElementById('orderDetailsContent');
            content.innerHTML = `
                <div class="alert alert-danger">
                    <h6>Network Error</h6>
                    <p>Failed to connect to the server. Please check your connection and try again.</p>
                    <hr>
                    <small class="text-muted">Error details: ${error.message}</small>
                </div>
            `;
            
            // Hide receive button
            document.getElementById('receiveFromViewBtn').style.display = 'none';
            
            // Show modal anyway to display error
            const modal = new bootstrap.Modal(document.getElementById('viewOrderModal'));
            modal.show();
        });
}

function displayOrderDetails(order, items, receiving_history) {
    const content = document.getElementById('orderDetailsContent');
    
    let itemsHtml = '';
    items.forEach(item => {
        const unitPrice = parseFloat(item.unit_price) || 0;
        const lineTotal = parseFloat(item.line_total) || 0;
        const progress = item.quantity_ordered > 0 ? (item.quantity_received / item.quantity_ordered) * 100 : 0;
        const progressColor = progress >= 100 ? 'success' : progress > 0 ? 'warning' : 'secondary';
        
        itemsHtml += `
            <tr>
                <td>${item.item_name}</td>
                <td>${item.sku || '-'}</td>
                <td class="text-end">${item.quantity_ordered}</td>
                <td class="text-end">${item.quantity_received}</td>
                <td class="text-end">${item.quantity_ordered - item.quantity_received}</td>
                <td class="text-end">$${unitPrice.toFixed(2)}</td>
                <td class="text-end">$${lineTotal.toFixed(2)}</td>
                <td>
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar bg-${progressColor}" style="width: ${progress}%">
                            ${progress.toFixed(1)}%
                        </div>
                    </div>
                </td>
            </tr>
        `;
    });
    
    let receivingHtml = '';
    if (receiving_history && receiving_history.length > 0) {
        receiving_history.forEach(receiving => {
            const totalValue = parseFloat(receiving.total_value) || 0;
            receivingHtml += `
                <tr>
                    <td>${receiving.receiving_date}</td>
                    <td>${receiving.total_items} items</td>
                    <td>$${totalValue.toFixed(2)}</td>
                    <td>${receiving.notes || '-'}</td>
                    <td>${receiving.storage_receipt ? 'STR-' + receiving.storage_receipt.receipt_number : 'No storage'}</td>
                </tr>
            `;
        });
    } else {
        receivingHtml = '<tr><td colspan="5" class="text-center text-muted">No receiving history</td></tr>';
    }
    
    content.innerHTML = `
        <div class="row mb-4">
            <div class="col-md-6">
                <h6>Order Information</h6>
                <table class="table table-sm">
                    <tr><td><strong>Order Number:</strong></td><td>${order.order_number}</td></tr>
                    <tr><td><strong>Vendor:</strong></td><td>${order.vendor_name || 'N/A'}</td></tr>
                    <tr><td><strong>Order Date:</strong></td><td>${order.order_date}</td></tr>
                    <tr><td><strong>Expected Date:</strong></td><td>${order.expected_date || 'Not set'}</td></tr>
                    <tr><td><strong>Status:</strong></td><td><span class="badge bg-primary">${order.status}</span></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6>Financial Summary</h6>
                <table class="table table-sm">
                    <tr><td><strong>Subtotal:</strong></td><td class="text-end">$${(parseFloat(order.subtotal) || 0).toFixed(2)}</td></tr>
                    <tr><td><strong>Tax:</strong></td><td class="text-end">$${(parseFloat(order.tax_amount) || 0).toFixed(2)}</td></tr>
                    <tr><td><strong>Shipping:</strong></td><td class="text-end">$${(parseFloat(order.shipping_cost) || 0).toFixed(2)}</td></tr>
                    <tr><td><strong><u>Total:</strong></u></td><td class="text-end"><u>$${(parseFloat(order.total_amount) || 0).toFixed(2)}</u></td></tr>
                </table>
            </div>
        </div>
        
        <div class="mb-4">
            <h6>Order Items</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>SKU</th>
                            <th class="text-end">Ordered</th>
                            <th class="text-end">Received</th>
                            <th class="text-end">Pending</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total</th>
                            <th>Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="mb-4">
            <h6>Receiving History</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Value</th>
                            <th>Notes</th>
                            <th>Storage Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${receivingHtml}
                    </tbody>
                </table>
            </div>
        </div>
        
        ${order.notes ? `
        <div class="mb-4">
            <h6>Order Notes</h6>
            <p>${order.notes}</p>
        </div>
        ` : ''}
    `;
    
    // Set up receive button
    const receiveBtn = document.getElementById('receiveFromViewBtn');
    if (order.status === 'confirmed' || order.status === 'partial') {
        receiveBtn.style.display = 'inline-block';
        receiveBtn.onclick = function() {
            bootstrap.Modal.getInstance(document.getElementById('viewOrderModal')).hide();
            openReceivingModal(orderId);
        };
    } else {
        receiveBtn.style.display = 'none';
    }
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('viewOrderModal'));
    modal.show();
}
function openReceivingModal(orderId) {
    document.getElementById('purchaseOrderId').value = orderId;
    
    // Load order items via AJAX
    const itemsContainer = document.getElementById('receivingItems');
    
    // Show loading state
    itemsContainer.innerHTML = `
        <div class="text-center py-4">
            <p>Loading order items...</p>
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `;
    
    // Fetch actual order items
    fetch(`get_purchase_order_items.php?id=${orderId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayReceivingItems(data.items);
            } else {
                itemsContainer.innerHTML = `
                    <div class="alert alert-danger">
                        Error loading order items: ${data.message}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            itemsContainer.innerHTML = `
                <div class="alert alert-danger">
                    Error loading order items. Please try again.
                </div>
            `;
        });
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('receivingModal'));
    modal.show();
}

function displayReceivingItems(items) {
    const itemsContainer = document.getElementById('receivingItems');
    
    if (items.length === 0) {
        itemsContainer.innerHTML = `
            <div class="alert alert-info">
                No items found for this order.
            </div>
        `;
        return;
    }
    
    let itemsHtml = '';
    items.forEach((item, index) => {
        const remaining_quantity = item.quantity_ordered - item.quantity_received;
        const can_receive = remaining_quantity > 0;
        
        itemsHtml += `
            <div class="row g-3 align-items-center receiving-item mb-3" data-item-id="${item.id}">
                <div class="col-md-3">
                    <label class="form-label">Item Name</label>
                    <input type="text" class="form-control" value="${item.item_name}" readonly>
                </div>
                <div class="col-md-2">
                    <label class="form-label">SKU</label>
                    <input type="text" class="form-control" value="${item.sku || ''}" readonly>
                </div>
                <div class="col-md-1">
                    <label class="form-label">Ordered</label>
                    <input type="number" class="form-control" value="${item.quantity_ordered}" readonly>
                </div>
                <div class="col-md-1">
                    <label class="form-label">Received</label>
                    <input type="number" class="form-control" value="${item.quantity_received}" readonly>
                </div>
                <div class="col-md-1">
                    <label class="form-label">Remaining</label>
                    <input type="number" class="form-control" value="${remaining_quantity}" readonly>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Receiving Now *</label>
                    <input type="number" step="0.01" name="items[${item.id}][quantity_received]" class="form-control" min="0" max="${remaining_quantity}" value="${can_receive ? remaining_quantity : 0}" ${!can_receive ? 'disabled' : ''} required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Storage Location *</label>
                    <select name="items[${item.id}][storage_location_id]" class="form-select" ${!can_receive ? 'disabled' : ''} required>
                        <option value="">Select Location</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?= $location['id'] ?>"><?= htmlspecialchars($location['location_code']) ?> - <?= htmlspecialchars($location['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Notes</label>
                    <input type="text" name="items[${item.id}][notes]" class="form-control" placeholder="Item notes..." ${!can_receive ? 'disabled' : ''}>
                </div>
            </div>
        `;
    });
    
    itemsContainer.innerHTML = itemsHtml;
}

</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
