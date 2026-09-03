<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'storage_receipts.view');

$pdo = get_db_connection();

$errors = [];
$success = '';

// AJAX: return receipt details as JSON
if (isset($_GET['ajax']) && $_GET['ajax'] === 'receipt_details' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("
            SELECT sr.*, po.order_number, u.name as received_by_name
            FROM storage_receipts sr
            LEFT JOIN purchase_orders po ON sr.purchase_order_id = po.id
            LEFT JOIN users u ON sr.received_by = u.id
            WHERE sr.id = ?
        ");
        $stmt->execute([$id]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$receipt) {
            echo json_encode(['error' => 'Receipt not found']);
            exit;
        }
        $itemsStmt = $pdo->prepare("
            SELECT sri.*, sl.location_code, sl.location_name
            FROM storage_receipt_items sri
            LEFT JOIN storage_locations sl ON sri.storage_location_id = sl.id
            WHERE sri.receipt_id = ?
            ORDER BY sri.id
        ");
        $itemsStmt->execute([$id]);
        $receipt['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($receipt);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Get storage locations
try {
    $locationsStmt = $pdo->query('SELECT * FROM storage_locations WHERE is_active = 1 ORDER BY location_code');
    $locations = $locationsStmt->fetchAll();
} catch (PDOException $e) {
    $locations = [];
    $errors[] = 'Storage locations not set up. Please run storage setup script first.';
}

// Get pending receiving items
try {
    $pendingStmt = $pdo->query('
        SELECT pri.*, po.order_number, pv.name as vendor_name, pr.receiving_date
        FROM purchase_receiving_items pri
        JOIN purchase_receiving pr ON pri.receiving_id = pr.id
        JOIN purchase_order_items poi ON pri.purchase_order_item_id = poi.id
        JOIN purchase_orders po ON pr.purchase_order_id = po.id
        LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id
        LEFT JOIN storage_receipt_items sri ON pri.id = sri.purchase_receiving_item_id
        WHERE sri.id IS NULL
        ORDER BY pr.receiving_date DESC, pri.id
    ');
    $pendingItems = $pendingStmt->fetchAll();
} catch (PDOException $e) {
    $pendingItems = [];
}

// Get existing storage receipts
try {
    $receiptsStmt = $pdo->query('
        SELECT sr.*, po.order_number, COUNT(sri.id) as item_count,
               sl.location_name as primary_location
        FROM storage_receipts sr
        LEFT JOIN purchase_orders po ON sr.purchase_order_id = po.id
        LEFT JOIN storage_receipt_items sri ON sr.id = sri.receipt_id
        LEFT JOIN storage_locations sl ON sri.storage_location_id = sl.id
        GROUP BY sr.id
        ORDER BY sr.receipt_date DESC
    ');
    $receipts = $receiptsStmt->fetchAll();
} catch (PDOException $e) {
    $receipts = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_receipt') {
        $receipt_number = 'STR-' . date('Y-m-d') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        $purchase_order_id = (int)($_POST['purchase_order_id'] ?? 0);
        $receiving_id = (int)($_POST['receiving_id'] ?? 0);
        $receipt_date = $_POST['receipt_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $items = $_POST['items'] ?? [];

        if (empty($items)) {
            $errors[] = 'At least one item must be selected.';
        } else {
            try {
                $pdo->beginTransaction();

                // Create storage receipt
                $stmt = $pdo->prepare('
                    INSERT INTO storage_receipts 
                    (receipt_number, purchase_order_id, receiving_id, receipt_date, received_by, status, total_items, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $user = current_user();
                $stmt->execute([$receipt_number, $purchase_order_id, $receiving_id, $receipt_date, $user['id'], 'confirmed', count($items), $notes]);
                $receipt_id = $pdo->lastInsertId();

                // Process items
                foreach ($items as $item_id => $item_data) {
                    $quantity = (float)($item_data['quantity'] ?? 0);
                    $location_id = (int)($item_data['storage_location_id'] ?? 0);
                    $storage_bin = trim($item_data['storage_bin'] ?? '');
                    $quality_status = $item_data['quality_status'] ?? 'pending';
                    $item_notes = trim($item_data['notes'] ?? '');

                    if ($quantity > 0 && $location_id > 0) {
                        // Get receiving item details (include product_id for canonical name lookup)
                        $itemStmt = $pdo->prepare('SELECT pri.*, poi.item_name, poi.sku, poi.unit_price, poi.product_id FROM purchase_receiving_items pri JOIN purchase_order_items poi ON pri.purchase_order_item_id = poi.id WHERE pri.id = ?');
                        $itemStmt->execute([$item_id]);
                        $receiving_item = $itemStmt->fetch();

                        if ($receiving_item) {
                            $unit_cost = $receiving_item['unit_price'];
                            $total_cost = $quantity * $unit_cost;

                            // Use products.name when product_id set - ensures inventory matches print/orders
                            $item_name = $receiving_item['item_name'];
                            if (!empty($receiving_item['product_id'])) {
                                $pstmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                                $pstmt->execute([$receiving_item['product_id']]);
                                $pname = $pstmt->fetchColumn();
                                if ($pname !== false && $pname !== '') {
                                    $item_name = $pname;
                                }
                            }

                            // Add storage receipt item
                            $stmt = $pdo->prepare('
                                INSERT INTO storage_receipt_items 
                                (receipt_id, purchase_order_item_id, item_name, sku, quantity_received, unit_cost, total_cost, storage_location_id, storage_bin, quality_status, notes)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ');
                            $stmt->execute([
                                $receipt_id, 
                                $receiving_item['purchase_order_item_id'], 
                                $item_name, 
                                $receiving_item['sku'], 
                                $quantity, 
                                $unit_cost, 
                                $total_cost, 
                                $location_id, 
                                $storage_bin, 
                                $quality_status, 
                                $item_notes
                            ]);

                            // Add inventory movement
                            $stmt = $pdo->prepare('
                                INSERT INTO inventory_movements 
                                (movement_type, item_name, sku, quantity, unit_cost, total_cost, to_location_id, reference_type, reference_id, reference_no, reason, user_id, movement_date)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ');
                            $stmt->execute([
                                'purchase_in',
                                $item_name,
                                $receiving_item['sku'],
                                $quantity,
                                $unit_cost,
                                $total_cost,
                                $location_id,
                                'purchase',
                                $purchase_order_id,
                                'PO-' . $purchase_order_id,
                                'Storage receipt processing',
                                $user['id'],
                                $receipt_date
                            ]);

                            // Update current inventory - INSERT new row each time for FIFO (bulk print reduces oldest first)
                            $stmt = $pdo->prepare('
                                INSERT INTO current_inventory (item_name, sku, storage_location_id, quantity_on_hand, unit_cost, updated_by)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ');
                            $stmt->execute([
                                $item_name,
                                $receiving_item['sku'],
                                $location_id,
                                $quantity,
                                $unit_cost,
                                $user['id']
                            ]);
                        }
                    }
                }

                // Update receiving record
                $stmt = $pdo->prepare('UPDATE purchase_receiving SET storage_receipt_id = ? WHERE id = ?');
                $stmt->execute([$receipt_id, $receiving_id]);

                $pdo->commit();
                $success = "Storage receipt $receipt_number created successfully.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Failed to create storage receipt: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Storage Receipts</h1>
        <div class="d-flex gap-2">
            <a href="purchase_receiving.php" class="btn btn-outline-primary btn-lg">
                <i class="bi bi-truck me-2"></i>Purchase Receiving
            </a>
            <a href="inventory_view.php" class="btn btn-outline-info btn-lg">
                <i class="bi bi-box-seam me-2"></i>View Inventory
            </a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <!-- Pending Items for Storage -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Items Pending Storage (<?= count($pendingItems) ?>)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($pendingItems)): ?>
                <p class="text-muted">No items pending storage.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Vendor</th>
                                <th>Item</th>
                                <th>SKU</th>
                                <th>Quantity</th>
                                <th>Unit Cost</th>
                                <th>Total Cost</th>
                                <th>Receiving Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingItems as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['order_number']) ?></td>
                                    <td><?= htmlspecialchars($item['vendor_name']) ?></td>
                                    <td><?= htmlspecialchars($item['item_name']) ?></td>
                                    <td><?= htmlspecialchars($item['sku']) ?></td>
                                    <td><?= number_format($item['quantity_received'], 2) ?></td>
                                    <td>$<?= number_format($item['unit_cost'], 2) ?></td>
                                    <td>$<?= number_format($item['total_cost'], 2) ?></td>
                                    <td><?= date('M j, Y', strtotime($item['receiving_date'])) ?></td>
                                    <td>
                                        <button class="btn btn-primary btn-sm" onclick="openStorageModal(<?= $item['id'] ?>, <?= htmlspecialchars(json_encode($item)) ?>)">
                                            <i class="bi bi-archive me-1"></i>Store
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Storage Receipts History -->
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0">Storage Receipts History</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Receipt #</th>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Items</th>
                            <th>Primary Location</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($receipts)): ?>
                            <tr><td colspan="7" class="text-center py-3">No storage receipts found</td></tr>
                        <?php else: ?>
                            <?php foreach ($receipts as $receipt): ?>
                                <?php
                                $status_colors = [
                                    'draft' => 'secondary',
                                    'confirmed' => 'primary',
                                    'stored' => 'success',
                                    'distributed' => 'info'
                                ];
                                $status_color = $status_colors[$receipt['status']] ?? 'secondary';
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($receipt['receipt_number']) ?></td>
                                    <td><?= htmlspecialchars($receipt['order_number']) ?></td>
                                    <td><?= date('M j, Y', strtotime($receipt['receipt_date'])) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $status_color ?>"><?= ucfirst($receipt['status']) ?></span>
                                    </td>
                                    <td><?= (int)$receipt['item_count'] ?></td>
                                    <td><?= htmlspecialchars($receipt['primary_location'] ?? 'N/A') ?></td>
                                    <td>
                                        <button class="btn btn-outline-primary btn-sm" onclick="viewReceipt(<?= $receipt['id'] ?>)">
                                            <i class="bi bi-eye me-1"></i>View
                                        </button>
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

<!-- View Receipt Modal -->
<div class="modal fade" id="viewReceiptModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Storage Receipt Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewReceiptContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                    <p class="mt-2 mb-0">Loading...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Storage Receipt Modal -->
<div class="modal fade" id="storageModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="post" id="storageForm">
                <input type="hidden" name="action" value="create_receipt">
                <input type="hidden" name="receiving_id" id="receivingId">
                <input type="hidden" name="purchase_order_id" id="purchaseOrderId">
                <div class="modal-header">
                    <h5 class="modal-title">Create Storage Receipt</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Receipt Date</label>
                            <input type="date" name="receipt_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="1" placeholder="Storage receipt notes..."></textarea>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Storage Details</h6>
                        </div>
                        <div class="card-body">
                            <div id="storageItems">
                                <!-- Items will be loaded here via JavaScript -->
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Storage Receipt</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let currentItemId = null;
let currentItemData = null;

function openStorageModal(itemId, itemData) {
    currentItemId = itemId;
    currentItemData = itemData;
    
    document.getElementById('receivingId').value = itemData.receiving_id;
    document.getElementById('purchaseOrderId').value = itemData.purchase_order_id || '';
    
    // Load storage form
    const itemsContainer = document.getElementById('storageItems');
    itemsContainer.innerHTML = `
        <div class="row g-3 align-items-center">
            <div class="col-md-3">
                <label class="form-label">Item Name</label>
                <input type="text" class="form-control" value="${itemData.item_name}" readonly>
            </div>
            <div class="col-md-2">
                <label class="form-label">SKU</label>
                <input type="text" class="form-control" value="${itemData.sku || ''}" readonly>
            </div>
            <div class="col-md-2">
                <label class="form-label">Quantity</label>
                <input type="number" step="0.01" name="items[${itemId}][quantity]" class="form-control" value="${itemData.quantity_received}" readonly>
            </div>
            <div class="col-md-2">
                <label class="form-label">Storage Location *</label>
                <select name="items[${itemId}][storage_location_id]" class="form-select" required>
                    <option value="">Select Location</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= $location['id'] ?>"><?= htmlspecialchars($location['location_code']) ?> - <?= htmlspecialchars($location['location_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Storage Bin</label>
                <input type="text" name="items[${itemId}][storage_bin]" class="form-control" placeholder="e.g., A1-01">
            </div>
            <div class="col-md-1">
                <label class="form-label">Quality</label>
                <select name="items[${itemId}][quality_status]" class="form-select">
                    <option value="pending">Pending</option>
                    <option value="approved" selected>Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="quarantine">Quarantine</option>
                </select>
            </div>
        </div>
    `;
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('storageModal'));
    modal.show();
}

function viewReceipt(receiptId) {
    const modal = new bootstrap.Modal(document.getElementById('viewReceiptModal'));
    const content = document.getElementById('viewReceiptContent');
    content.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 mb-0">Loading...</p></div>';
    modal.show();

    const url = window.location.pathname + '?ajax=receipt_details&id=' + receiptId;
    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                content.innerHTML = '<div class="alert alert-danger">' + escapeHtml(data.error) + '</div>';
                return;
            }
            let html = '<div class="row mb-3">';
            html += '<div class="col-md-3"><strong>Receipt #</strong><br>' + escapeHtml(data.receipt_number || '') + '</div>';
            html += '<div class="col-md-3"><strong>Order #</strong><br>' + escapeHtml(data.order_number || 'N/A') + '</div>';
            html += '<div class="col-md-2"><strong>Date</strong><br>' + (data.receipt_date || '') + '</div>';
            html += '<div class="col-md-2"><strong>Status</strong><br><span class="badge bg-primary">' + escapeHtml((data.status || '').charAt(0).toUpperCase() + (data.status || '').slice(1)) + '</span></div>';
            html += '<div class="col-md-2"><strong>Received By</strong><br>' + escapeHtml(data.received_by_name || 'N/A') + '</div>';
            html += '</div>';
            if (data.notes) {
                html += '<div class="mb-3"><strong>Notes</strong><br><p class="mb-0">' + escapeHtml(data.notes) + '</p></div>';
            }
            html += '<table class="table table-sm table-bordered"><thead class="table-light"><tr><th>Item Name</th><th>SKU</th><th>Quantity</th><th>Unit Cost</th><th>Total Cost</th><th>Location</th><th>Storage Bin</th><th>Quality</th></tr></thead><tbody>';
            (data.items || []).forEach(function(it) {
                html += '<tr><td>' + escapeHtml(it.item_name || '') + '</td><td>' + escapeHtml(it.sku || '') + '</td><td>' + parseFloat(it.quantity_received || 0).toFixed(2) + '</td><td>$' + parseFloat(it.unit_cost || 0).toFixed(2) + '</td><td>$' + parseFloat(it.total_cost || 0).toFixed(2) + '</td><td>' + escapeHtml((it.location_code || '') + ' - ' + (it.location_name || '')) + '</td><td>' + escapeHtml(it.storage_bin || '') + '</td><td>' + escapeHtml(it.quality_status || '') + '</td></tr>';
            });
            html += '</tbody></table>';
            content.innerHTML = html;
        })
        .catch(err => {
            content.innerHTML = '<div class="alert alert-danger">Failed to load receipt: ' + escapeHtml(String(err)) + '</div>';
        });
}

function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
