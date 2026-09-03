<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_receiving.view');

$pdo = get_db_connection();

// Filters
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$vendor_id = (int)($_GET['vendor_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

$where = [];
$params = [];
if ($date_from !== '') {
    $where[] = 'DATE(pr.receiving_date) >= ?';
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = 'DATE(pr.receiving_date) <= ?';
    $params[] = $date_to;
}
if ($vendor_id > 0) {
    $where[] = 'po.vendor_id = ?';
    $params[] = $vendor_id;
}
if ($search !== '') {
    $where[] = '(po.order_number LIKE ? OR pv.name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Pagination
$page = (int)($_GET['page'] ?? 1);
$per_page = 25;
$offset = ($page - 1) * $per_page;

// Count
$countSql = "SELECT COUNT(*) FROM purchase_receiving pr
    JOIN purchase_orders po ON pr.purchase_order_id = po.id
    LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id
    $whereClause";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total_records = (int)$countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_records / $per_page));

// Get receiving history
$sql = "SELECT pr.id, pr.purchase_order_id, pr.receiving_date, pr.received_by, pr.notes,
        COUNT(pri.id) as total_items, COALESCE(SUM(pri.quantity_received), 0) as total_qty, COALESCE(SUM(pri.total_cost), 0) as total_value,
        po.order_number, pv.name as vendor_name, u.name as received_by_name,
        sr.receipt_number as storage_receipt_number
    FROM purchase_receiving pr
    JOIN purchase_orders po ON pr.purchase_order_id = po.id
    LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id
    LEFT JOIN users u ON pr.received_by = u.id
    LEFT JOIN purchase_receiving_items pri ON pr.id = pri.receiving_id
    LEFT JOIN storage_receipts sr ON pr.storage_receipt_id = sr.id
    $whereClause
    GROUP BY pr.id
    ORDER BY pr.receiving_date DESC, pr.id DESC
    LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$receiving_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Vendors for filter
$vendors = $pdo->query('SELECT id, name FROM purchase_vendors ORDER BY name')->fetchAll();

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0"><i class="bi bi-clock-history me-2"></i>Purchase Receiving History</h1>
        <div class="d-flex gap-2">
            <a href="purchase_receiving.php" class="btn btn-outline-primary">
                <i class="bi bi-truck me-1"></i>Receiving
            </a>
            <a href="purchase_orders.php" class="btn btn-outline-secondary">
                <i class="bi bi-list me-1"></i>Purchase Orders
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-0">Date From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Date To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Vendor</label>
                    <select name="vendor_id" class="form-select form-select-sm" style="width:180px">
                        <option value="">All Vendors</option>
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?= $v['id'] ?>" <?= $vendor_id == $v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Order #, vendor" value="<?= htmlspecialchars($search) ?>" style="width:160px">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
                    <a href="purchase_receiving_history.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Receiving Records</h5>
            <small class="text-muted"><?= $total_records ?> record(s)</small>
        </div>
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Order #</th>
                            <th>Vendor</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Total Qty</th>
                            <th class="text-end">Total Amount</th>
                            <th>Received By</th>
                            <th>Storage Receipt</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($receiving_list)): ?>
                            <tr><td colspan="11" class="text-center py-4 text-muted">No receiving records found.</td></tr>
                        <?php else: ?>
                            <?php $no = $offset + 1; foreach ($receiving_list as $r): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= date('M j, Y', strtotime($r['receiving_date'])) ?><br><small class="text-muted"><?= date('H:i', strtotime($r['receiving_date'])) ?></small></td>
                                    <td><a href="purchase_orders.php" onclick="viewOrderFromHistory(<?= $r['purchase_order_id'] ?>); return false;" class="text-decoration-none"><?= htmlspecialchars($r['order_number']) ?></a></td>
                                    <td><?= htmlspecialchars($r['vendor_name'] ?? '-') ?></td>
                                    <td class="text-end"><?= (int)$r['total_items'] ?></td>
                                    <td class="text-end"><?= number_format((float)($r['total_qty'] ?? 0), 2) ?></td>
                                    <td class="text-end">$<?= number_format((float)$r['total_value'], 2) ?></td>
                                    <td><?= htmlspecialchars($r['received_by_name'] ?? '-') ?></td>
                                    <td><?= $r['storage_receipt_number'] ? htmlspecialchars($r['storage_receipt_number']) : '<span class="text-muted">-</span>' ?></td>
                                    <td><small class="text-muted"><?= htmlspecialchars(mb_substr($r['notes'] ?? '', 0, 40)) ?><?= mb_strlen($r['notes'] ?? '') > 40 ? '…' : '' ?></small></td>
                                    <td>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="showReceivingDetails(<?= $r['id'] ?>)" title="View Details"><i class="bi bi-list-ul"></i></button>
                                        <a href="purchase_orders.php" class="btn btn-outline-primary btn-sm" onclick="viewOrderFromHistory(<?= $r['purchase_order_id'] ?>); return false;" title="View Order"><i class="bi bi-eye"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($total_pages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination pagination-sm mb-0 justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&laquo;</a></li>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a></li>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">&raquo;</a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Receiving Details Modal -->
<div class="modal fade" id="receivingDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Receiving Details — <span id="recDetailsOrderNumber"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Date:</strong> <span id="recDetailsDate"></span></p>
                        <p class="mb-1"><strong>Received By:</strong> <span id="recDetailsBy"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Vendor:</strong> <span id="recDetailsVendor"></span></p>
                        <p class="mb-1"><strong>Storage Receipt:</strong> <span id="recDetailsStorage"></span></p>
                    </div>
                </div>
                <h6>Products Received</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead class="table-light">
                            <tr><th>Product</th><th>SKU</th><th class="text-end">Qty</th><th>Storage Location</th><th class="text-end">Unit Cost</th><th class="text-end">Amount</th></tr>
                        </thead>
                        <tbody id="recDetailsItems"></tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-end">
                    <strong>Total: <span id="recDetailsTotal" class="text-success"></span></strong>
                </div>
                <div id="recDetailsNotes" class="mt-2 small text-muted"></div>
            </div>
        </div>
    </div>
</div>

<script>
function viewOrderFromHistory(orderId) {
    window.location.href = 'purchase_orders.php?view=' + orderId;
}

function showReceivingDetails(receivingId) {
    fetch('get_receiving_details.php?id=' + receivingId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || 'Failed to load details');
                return;
            }
            const r = data.receiving;
            const items = data.items || [];
            document.getElementById('recDetailsOrderNumber').textContent = r.order_number || '';
            document.getElementById('recDetailsDate').textContent = r.receiving_date || '-';
            document.getElementById('recDetailsBy').textContent = r.received_by_name || '-';
            document.getElementById('recDetailsVendor').textContent = r.vendor_name || '-';
            document.getElementById('recDetailsStorage').textContent = r.storage_receipt_number || '-';
            document.getElementById('recDetailsTotal').textContent = '$' + (data.total_value || 0).toFixed(2);
            const notesEl = document.getElementById('recDetailsNotes');
            notesEl.textContent = r.notes ? 'Notes: ' + r.notes : '';
            notesEl.style.display = r.notes ? 'block' : 'none';
            const tbody = document.getElementById('recDetailsItems');
            tbody.innerHTML = items.map(it => '<tr><td>' + (it.item_name || '').replace(/</g, '&lt;') + '</td><td>' + (it.sku || '-').replace(/</g, '&lt;') + '</td><td class="text-end">' + (parseFloat(it.quantity_received) || 0).toFixed(2) + '</td><td>' + (it.storage_location || '-').replace(/</g, '&lt;') + '</td><td class="text-end">$' + (parseFloat(it.unit_cost) || 0).toFixed(2) + '</td><td class="text-end">$' + (parseFloat(it.total_cost) || 0).toFixed(2) + '</td></tr>').join('') || '<tr><td colspan="6" class="text-center text-muted">No items</td></tr>';
            new bootstrap.Modal(document.getElementById('receivingDetailsModal')).show();
        })
        .catch(e => { console.error(e); alert('Failed to load details'); });
}
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
