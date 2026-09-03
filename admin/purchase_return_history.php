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
    $where[] = 'DATE(pr.return_date) >= ?';
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = 'DATE(pr.return_date) <= ?';
    $params[] = $date_to;
}
if ($vendor_id > 0) {
    $where[] = 'pr.vendor_id = ?';
    $params[] = $vendor_id;
}
if ($search !== '') {
    $where[] = '(pr.return_number LIKE ? OR po.order_number LIKE ? OR pv.name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Pagination
$page = (int)($_GET['page'] ?? 1);
$per_page = 25;
$offset = ($page - 1) * $per_page;

// Count
$countSql = "SELECT COUNT(*) FROM purchase_returns pr
    JOIN purchase_orders po ON pr.purchase_order_id = po.id
    LEFT JOIN purchase_vendors pv ON pr.vendor_id = pv.id
    $whereClause";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total_records = (int)$countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_records / $per_page));

// Get return history
$sql = "SELECT pr.id, pr.purchase_order_id, pr.return_number, pr.return_date, pr.status, pr.reason, pr.notes, pr.total_amount,
        po.order_number, pv.name as vendor_name, u.name as created_by_name
    FROM purchase_returns pr
    JOIN purchase_orders po ON pr.purchase_order_id = po.id
    LEFT JOIN purchase_vendors pv ON pr.vendor_id = pv.id
    LEFT JOIN users u ON pr.created_by = u.id
    $whereClause
    ORDER BY pr.return_date DESC, pr.id DESC
    LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$returns_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Vendors for filter
$vendors = $pdo->query('SELECT id, name FROM purchase_vendors ORDER BY name')->fetchAll();

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0"><i class="bi bi-clock-history me-2"></i>Purchase Return History</h1>
        <div class="d-flex gap-2">
            <a href="purchase_returns.php" class="btn btn-outline-warning">
                <i class="bi bi-arrow-return-left me-1"></i>Create Return
            </a>
            <a href="purchase_orders.php" class="btn btn-outline-secondary">
                <i class="bi bi-list me-1"></i>Purchase Orders
            </a>
        </div>
    </div>

    <?php if (!empty($_GET['success'])): ?><div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div><?php endif; ?>
    <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>

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
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Return #, Order #, vendor" value="<?= htmlspecialchars($search) ?>" style="width:200px">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
                    <a href="purchase_return_history.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Return Records</h5>
            <small class="text-muted"><?= $total_records ?> return(s)</small>
        </div>
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Return #</th>
                            <th>Date</th>
                            <th>Order #</th>
                            <th>Vendor</th>
                            <th class="text-end">Total Amount</th>
                            <th>Reason</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($returns_list)): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">No purchase returns found.</td></tr>
                        <?php else: ?>
                            <?php $no = $offset + 1; foreach ($returns_list as $r): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><strong><?= htmlspecialchars($r['return_number']) ?></strong></td>
                                    <td><?= date('M j, Y', strtotime($r['return_date'])) ?></td>
                                    <td><a href="purchase_orders.php?view=<?= $r['purchase_order_id'] ?>" class="text-decoration-none"><?= htmlspecialchars($r['order_number']) ?></a></td>
                                    <td><?= htmlspecialchars($r['vendor_name'] ?? '-') ?></td>
                                    <td class="text-end">$<?= number_format((float)$r['total_amount'], 2) ?></td>
                                    <td><small><?= htmlspecialchars(mb_substr($r['reason'] ?? '', 0, 40)) ?><?= mb_strlen($r['reason'] ?? '') > 40 ? '…' : '' ?></small></td>
                                    <td><?= htmlspecialchars($r['created_by_name'] ?? '-') ?></td>
                                    <td>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="showReturnDetails(<?= $r['id'] ?>)" title="View Details"><i class="bi bi-list-ul"></i></button>
                                        <a href="purchase_orders.php?view=<?= $r['purchase_order_id'] ?>" class="btn btn-outline-primary btn-sm" title="View Order"><i class="bi bi-eye"></i></a>
                                        <?php if (($user['role'] ?? '') === 'admin' || (function_exists('has_permission') && has_permission('purchase_receiving.update'))): ?>
                                            <a href="purchase_returns.php?edit=<?= $r['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Edit"><i class="bi bi-pencil-square"></i></a>
                                        <?php endif; ?>
                                        <?php if (($user['role'] ?? '') === 'admin' || (function_exists('has_permission') && has_permission('purchase_receiving.delete'))): ?>
                                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteReturn(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['return_number'])) ?>', '<?= htmlspecialchars(addslashes(basename($_SERVER['SCRIPT_NAME']) . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''))) ?>')" title="Delete"><i class="bi bi-trash"></i></button>
                                        <?php endif; ?>
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

<!-- Return Details Modal -->
<div class="modal fade" id="returnDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Return Details — <span id="retDetailsReturnNumber"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Date:</strong> <span id="retDetailsDate"></span></p>
                        <p class="mb-1"><strong>Order #:</strong> <span id="retDetailsOrderNumber"></span></p>
                        <p class="mb-1"><strong>Created By:</strong> <span id="retDetailsBy"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Vendor:</strong> <span id="retDetailsVendor"></span></p>
                        <p class="mb-1"><strong>Reason:</strong> <span id="retDetailsReason"></span></p>
                    </div>
                </div>
                <h6>Items Returned</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead class="table-light">
                            <tr><th>Product</th><th>SKU</th><th class="text-end">Qty Returned</th><th>Storage Location</th><th class="text-end">Unit Cost</th><th class="text-end">Amount</th></tr>
                        </thead>
                        <tbody id="retDetailsItems"></tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-end">
                    <strong>Total: <span id="retDetailsTotal" class="text-success"></span></strong>
                </div>
                <div id="retDetailsNotes" class="mt-2 small text-muted"></div>
            </div>
        </div>
    </div>
</div>

<script>
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
function showReturnDetails(returnId) {
    fetch('get_return_details.php?id=' + returnId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || 'Failed to load details');
                return;
            }
            const r = data.return;
            const items = data.items || [];
            document.getElementById('retDetailsReturnNumber').textContent = r.return_number || '';
            document.getElementById('retDetailsDate').textContent = r.return_date || '-';
            document.getElementById('retDetailsOrderNumber').textContent = r.order_number || '-';
            document.getElementById('retDetailsBy').textContent = r.created_by_name || '-';
            document.getElementById('retDetailsVendor').textContent = r.vendor_name || '-';
            document.getElementById('retDetailsReason').textContent = r.reason || '-';
            document.getElementById('retDetailsTotal').textContent = '$' + (data.total_amount || 0).toFixed(2);
            const notesEl = document.getElementById('retDetailsNotes');
            notesEl.textContent = r.notes ? 'Notes: ' + r.notes : '';
            notesEl.style.display = r.notes ? 'block' : 'none';
            const tbody = document.getElementById('retDetailsItems');
            tbody.innerHTML = items.map(it => '<tr><td>' + (it.item_name || '').replace(/</g, '&lt;') + '</td><td>' + (it.sku || '-').replace(/</g, '&lt;') + '</td><td class="text-end">' + (parseFloat(it.quantity_returned) || 0).toFixed(2) + '</td><td>' + (it.storage_location || '-').replace(/</g, '&lt;') + '</td><td class="text-end">$' + (parseFloat(it.unit_cost) || 0).toFixed(2) + '</td><td class="text-end">$' + (parseFloat(it.total_cost) || 0).toFixed(2) + '</td></tr>').join('') || '<tr><td colspan="6" class="text-center text-muted">No items</td></tr>';
            new bootstrap.Modal(document.getElementById('returnDetailsModal')).show();
        })
        .catch(e => { console.error(e); alert('Failed to load details'); });
}
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
