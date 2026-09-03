<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_reports.view');

$pdo = get_db_connection();

$errors = [];
$success = '';

// Filters
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$vendor_filter = (int)($_GET['vendor_filter'] ?? 0);
$status_filter = $_GET['status_filter'] ?? '';
$payment_filter = $_GET['payment_filter'] ?? '';
$product_search = trim($_GET['product_search'] ?? '');
$order_code_filter = trim($_GET['order_code_filter'] ?? '');
$group_by = $_GET['group_by'] ?? 'vendor'; // vendor | product

// Get vendors for filter
try {
    $vendorsStmt = $pdo->query('SELECT id, name FROM purchase_vendors ORDER BY name');
    $vendors = $vendorsStmt->fetchAll();
} catch (PDOException $e) {
    $vendors = [];
    $errors[] = 'Purchase vendors table not found.';
}

// Build query for product-level purchase data
$params = [];
$sql = "
    SELECT 
        pv.id as vendor_id,
        pv.name as vendor_name,
        po.id as purchase_order_id,
        po.order_number,
        po.order_date,
        po.status as order_status,
        po.total_amount as order_total_amount,
        COALESCE(po.total_paid, 0) as total_paid,
        (po.total_amount - COALESCE(po.total_paid, 0)) as balance_due,
        COALESCE(po.payment_status, 'unpaid') as payment_status,
        poi.item_name,
        poi.sku,
        poi.quantity_ordered,
        COALESCE(SUM(pri.quantity_received), 0) as quantity_received,
        COALESCE((SELECT SUM(pri_ret.quantity_returned) FROM purchase_return_items pri_ret WHERE pri_ret.purchase_order_item_id = poi.id), 0) as quantity_returned,
        COALESCE((SELECT SUM(pri_ret.total_cost) FROM purchase_return_items pri_ret WHERE pri_ret.purchase_order_item_id = poi.id), 0) as amount_returned,
        poi.unit_price,
        poi.line_total
    FROM purchase_order_items poi
    JOIN purchase_orders po ON poi.purchase_order_id = po.id
    LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id
    LEFT JOIN purchase_receiving_items pri ON poi.id = pri.purchase_order_item_id
    WHERE po.order_date BETWEEN ? AND ?
";
$params[] = $from;
$params[] = $to;

if ($vendor_filter > 0) {
    $sql .= ' AND po.vendor_id = ?';
    $params[] = $vendor_filter;
}

if ($status_filter !== '') {
    $sql .= ' AND po.status = ?';
    $params[] = $status_filter;
}

if ($payment_filter !== '') {
    $sql .= ' AND COALESCE(po.payment_status, \'unpaid\') = ?';
    $params[] = $payment_filter;
}

if ($product_search !== '') {
    $sql .= ' AND (poi.item_name LIKE ? OR poi.sku LIKE ?)';
    $search_param = '%' . $product_search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
}
if ($order_code_filter !== '') {
    $sql .= ' AND po.order_number LIKE ?';
    $params[] = '%' . $order_code_filter . '%';
}

$sql .= " GROUP BY poi.id, pv.id, pv.name, po.id, po.order_number, po.order_date, po.status, po.total_amount, po.total_paid, po.payment_status, poi.item_name, poi.sku, poi.quantity_ordered, poi.unit_price, poi.line_total ORDER BY pv.name, po.order_date DESC, poi.item_name";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
    $errors[] = 'Error loading data: ' . htmlspecialchars($e->getMessage());
}

// Group data by vendor (or by product if group_by=product)
$by_vendor = [];
$by_product = [];

foreach ($rows as $row) {
    $row['quantity_received'] = (float)$row['quantity_received'];
    $row['quantity_returned'] = (float)($row['quantity_returned'] ?? 0);
    $row['amount_returned'] = (float)($row['amount_returned'] ?? 0);
    $row['quantity_ordered'] = (float)$row['quantity_ordered'];
    $row['quantity_not_received'] = max(0, $row['quantity_ordered'] - $row['quantity_received']);
    $row['unit_price'] = (float)$row['unit_price'];
    $row['line_total'] = (float)$row['line_total'];
    $row['total_paid'] = (float)($row['total_paid'] ?? 0);
    $row['balance_due'] = (float)($row['balance_due'] ?? 0);
    $row['payment_status'] = $row['payment_status'] ?? 'unpaid';

    $vid = $row['vendor_id'];
    $vname = $row['vendor_name'] ?? 'Unknown Vendor';
    if (!isset($by_vendor[$vid])) {
        $by_vendor[$vid] = ['name' => $vname, 'items' => [], 'total_amount' => 0, 'total_paid' => 0, 'total_amount_return' => 0, 'balance_due' => 0, 'seen_orders' => []];
    }
    $by_vendor[$vid]['items'][] = $row;
    $by_vendor[$vid]['total_amount'] += $row['line_total'];
    $by_vendor[$vid]['total_amount_return'] += $row['amount_returned'];
    $oid = (int)($row['purchase_order_id'] ?? 0);
    if ($oid > 0 && empty($by_vendor[$vid]['seen_orders'][$oid])) {
        $by_vendor[$vid]['seen_orders'][$oid] = true;
        $by_vendor[$vid]['total_paid'] += (float)($row['total_paid'] ?? 0);
        $by_vendor[$vid]['balance_due'] += (float)($row['balance_due'] ?? 0);
    }

    // Aggregate by product (item_name + sku) across vendors
    $pkey = ($row['item_name'] ?? '') . '|' . ($row['sku'] ?? '');
    if (!isset($by_product[$pkey])) {
        $by_product[$pkey] = [
            'item_name' => $row['item_name'],
            'sku' => $row['sku'],
            'rows' => [],
            'total_qty' => 0,
            'total_amount' => 0,
            'total_paid' => 0,
            'total_amount_return' => 0,
            'balance_due' => 0,
            'seen_orders' => []
        ];
    }
    $by_product[$pkey]['rows'][] = $row;
    $by_product[$pkey]['total_qty'] += $row['quantity_ordered'];
    $by_product[$pkey]['total_amount'] += $row['line_total'];
    $by_product[$pkey]['total_amount_return'] += $row['amount_returned'];
    $oid = (int)($row['purchase_order_id'] ?? 0);
    if ($oid > 0 && empty($by_product[$pkey]['seen_orders'][$oid])) {
        $by_product[$pkey]['seen_orders'][$oid] = true;
        $by_product[$pkey]['total_paid'] += (float)($row['total_paid'] ?? 0);
        $by_product[$pkey]['balance_due'] += (float)($row['balance_due'] ?? 0);
    }
}

$grand_total = array_sum(array_column($rows, 'line_total'));
$grand_total_paid = 0;
$grand_total_amount_return = array_sum(array_map(function ($r) { return (float)($r['amount_returned'] ?? 0); }, $rows));
$grand_balance_due = 0;
$seen_order_ids = [];
foreach ($rows as $row) {
    $oid = (int)($row['purchase_order_id'] ?? 0);
    if ($oid > 0 && !isset($seen_order_ids[$oid])) {
        $seen_order_ids[$oid] = true;
        $grand_total_paid += (float)($row['total_paid'] ?? 0);
        $grand_balance_due += (float)($row['balance_due'] ?? 0);
    }
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Vendor Product Detail</h1>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-success btn-lg" onclick="window.print()">
                <i class="bi bi-printer me-2"></i>Print
            </button>
            <a href="export_vendor_product_excel.php?<?= http_build_query(['from' => $from, 'to' => $to, 'vendor_filter' => $vendor_filter, 'status_filter' => $status_filter, 'payment_filter' => $payment_filter, 'product_search' => $product_search, 'order_code_filter' => $order_code_filter, 'group_by' => $group_by]) ?>" class="btn btn-outline-primary btn-lg">
                <i class="bi bi-file-earmark-excel me-2"></i>Export Excel
            </a>
            <a href="purchase_reports.php" class="btn btn-outline-secondary btn-lg">
                <i class="bi bi-graph-up me-2"></i>Purchase Reports
            </a>
        </div>
    </div>

    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <!-- Filters -->
    <form method="get" class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filters</h5>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-2">
                    <label class="form-label">From Date</label>
                    <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>" required>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">To Date</label>
                    <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>" required>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Vendor</label>
                    <select name="vendor_filter" class="form-select">
                        <option value="">All Vendors</option>
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>" <?= $vendor_filter === (int)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Order Status</label>
                    <select name="status_filter" class="form-select">
                        <option value="">All Status</option>
                        <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="sent" <?= $status_filter === 'sent' ? 'selected' : '' ?>>Sent</option>
                        <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                        <option value="partial" <?= $status_filter === 'partial' ? 'selected' : '' ?>>Partial</option>
                        <option value="received" <?= $status_filter === 'received' ? 'selected' : '' ?>>Received</option>
                        <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Payment Status</label>
                    <select name="payment_filter" class="form-select">
                        <option value="">All</option>
                        <option value="unpaid" <?= $payment_filter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="partial" <?= $payment_filter === 'partial' ? 'selected' : '' ?>>Partial</option>
                        <option value="paid" <?= $payment_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Product Search</label>
                    <input type="text" name="product_search" class="form-control" value="<?= htmlspecialchars($product_search) ?>" placeholder="Item name or SKU">
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Order Code</label>
                    <div class="position-relative">
                        <input type="text" name="order_code_filter" id="orderCodeFilter" class="form-control" value="<?= htmlspecialchars($order_code_filter) ?>" placeholder="Type to search..." autocomplete="off">
                        <div id="orderCodeDropdown" class="dropdown-menu w-100" style="max-height: 200px; overflow-y: auto; display: none;"></div>
                    </div>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">View By</label>
                    <select name="group_by" class="form-select">
                        <option value="vendor" <?= $group_by === 'vendor' ? 'selected' : '' ?>>By Vendor</option>
                        <option value="product" <?= $group_by === 'product' ? 'selected' : '' ?>>By Product</option>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">Apply</button>
                    <a href="vendor_product_detail.php" class="btn btn-outline-secondary">Reset</a>
                </div>
                <div class="col-12">
                    <small class="text-muted">Quick presets: </small>
                    <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>&vendor_filter=<?= $vendor_filter ?>&status_filter=<?= htmlspecialchars($status_filter) ?>&payment_filter=<?= htmlspecialchars($payment_filter) ?>&product_search=<?= urlencode($product_search) ?>&order_code_filter=<?= urlencode($order_code_filter) ?>&group_by=<?= htmlspecialchars($group_by) ?>" class="btn btn-sm btn-outline-secondary">This Month</a>
                    <a href="?from=<?= date('Y-m-01', strtotime('-1 month')) ?>&to=<?= date('Y-m-t', strtotime('-1 month')) ?>&vendor_filter=<?= $vendor_filter ?>&status_filter=<?= htmlspecialchars($status_filter) ?>&payment_filter=<?= htmlspecialchars($payment_filter) ?>&product_search=<?= urlencode($product_search) ?>&order_code_filter=<?= urlencode($order_code_filter) ?>&group_by=<?= htmlspecialchars($group_by) ?>" class="btn btn-sm btn-outline-secondary">Last Month</a>
                    <a href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>&vendor_filter=<?= $vendor_filter ?>&status_filter=<?= htmlspecialchars($status_filter) ?>&payment_filter=<?= htmlspecialchars($payment_filter) ?>&product_search=<?= urlencode($product_search) ?>&order_code_filter=<?= urlencode($order_code_filter) ?>&group_by=<?= htmlspecialchars($group_by) ?>" class="btn btn-sm btn-outline-secondary">This Year</a>
                </div>
            </div>
        </div>
    </form>

    <!-- Grand Total Summary -->
    <?php if (!empty($rows)): ?>
    <div class="card shadow-sm mb-4 border-primary">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-calculator me-2"></i>Grand Total</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                        <span class="text-muted">Total Amount</span>
                        <strong class="fs-5">$<?= number_format($grand_total, 2) ?></strong>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                        <span class="text-muted">Total Paid</span>
                        <strong class="fs-5 text-success">$<?= number_format($grand_total_paid, 2) ?></strong>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                        <span class="text-muted">Amount Return</span>
                        <strong class="fs-5 text-warning">$<?= number_format($grand_total_amount_return, 2) ?></strong>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                        <span class="text-muted">Balance Due</span>
                        <strong class="fs-5 text-<?= $grand_balance_due > 0 ? 'danger' : 'success' ?>">$<?= number_format($grand_balance_due, 2) ?></strong>
                    </div>
                </div>
            </div>
            <small class="text-muted mt-2 d-block"><?= count($rows) ?> line item(s) • <?= count($seen_order_ids) ?> order(s)</small>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($group_by === 'vendor'): ?>
        <!-- By Vendor -->
        <?php if (empty($by_vendor)): ?>
            <div class="alert alert-info">No purchase data found for the selected filters.</div>
        <?php else: ?>
            <?php foreach ($by_vendor as $vid => $vendor): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">
                            <i class="bi bi-building me-2"></i><?= htmlspecialchars($vendor['name']) ?>
                            <span class="badge bg-primary ms-2"><?= count($vendor['items']) ?> line items</span>
                        </h5>
                        <div class="d-flex gap-3">
                            <span><strong>Total:</strong> $<?= number_format($vendor['total_amount'], 2) ?></span>
                            <span class="text-success"><strong>Paid:</strong> $<?= number_format($vendor['total_paid'], 2) ?></span>
                            <span class="text-warning"><strong>Amount Return:</strong> $<?= number_format($vendor['total_amount_return'] ?? 0, 2) ?></span>
                            <span class="text-<?= $vendor['balance_due'] > 0 ? 'danger' : 'success' ?>"><strong>Balance Due:</strong> $<?= number_format($vendor['balance_due'], 2) ?></span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-center">No</th>
                                        <th>Item Name</th>
                                        <th>SKU</th>
                                        <th>Order #</th>
                                        <th>Order Date</th>
                                        <th class="text-end">Qty Ordered</th>
                                        <th class="text-end">Qty Received</th>
                                        <th class="text-end">Qty Not Received</th>
                                        <th class="text-end">Qty Return</th>
                                        <th class="text-end">Amount Return</th>
                                        <th class="text-end">Unit Price</th>
                                        <th class="text-end">Line Total</th>
                                        <th>Payment Status</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $last_order_id_v = null;
                                    $row_no_v = 0;
                                    $tot_qty_ord_v = 0; $tot_qty_rec_v = 0; $tot_qty_not_rec_v = 0; $tot_qty_ret_v = 0; $tot_amt_ret_v = 0; $tot_line_v = 0;
                                    foreach ($vendor['items'] as $item):
                                        $row_no_v++;
                                        $show_order_totals = ($last_order_id_v !== (int)($item['purchase_order_id'] ?? 0));
                                        if ($show_order_totals) $last_order_id_v = (int)($item['purchase_order_id'] ?? 0);
                                        $tot_qty_ord_v += $item['quantity_ordered'];
                                        $tot_qty_rec_v += $item['quantity_received'];
                                        $tot_qty_not_rec_v += $item['quantity_not_received'];
                                        $tot_qty_ret_v += ($item['quantity_returned'] ?? 0);
                                        $tot_amt_ret_v += ($item['amount_returned'] ?? 0);
                                        $tot_line_v += $item['line_total'];
                                        $status_colors = ['draft' => 'secondary', 'sent' => 'info', 'confirmed' => 'primary', 'partial' => 'warning', 'received' => 'success', 'cancelled' => 'danger'];
                                        $sc = $status_colors[$item['order_status']] ?? 'secondary';
                                        $pay_colors = ['unpaid' => 'danger', 'partial' => 'warning', 'paid' => 'success', 'overpaid' => 'info'];
                                        $pay_sc = $pay_colors[$item['payment_status']] ?? 'secondary';
                                        ?>
                                        <tr>
                                            <td class="text-center"><?= $row_no_v ?></td>
                                            <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                            <td><code><?= htmlspecialchars($item['sku'] ?? '-') ?></code></td>
                                            <td><?= htmlspecialchars($item['order_number']) ?></td>
                                            <td><?= date('M j, Y', strtotime($item['order_date'])) ?></td>
                                            <td class="text-end"><?= number_format($item['quantity_ordered'], 2) ?></td>
                                            <td class="text-end"><?= number_format($item['quantity_received'], 2) ?></td>
                                            <td class="text-end"><?= number_format($item['quantity_not_received'], 2) ?></td>
                                            <td class="text-end"><?= number_format($item['quantity_returned'] ?? 0, 2) ?></td>
                                            <td class="text-end">$<?= number_format($item['amount_returned'] ?? 0, 2) ?></td>
                                            <td class="text-end">$<?= number_format($item['unit_price'], 2) ?></td>
                                            <td class="text-end"><strong>$<?= number_format($item['line_total'], 2) ?></strong></td>
                                            <td><span class="badge bg-<?= $pay_sc ?>"><?= ucfirst($item['payment_status']) ?></span></td>
                                            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($item['order_status']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                        <tr class="table-light fw-bold">
                                            <td colspan="5" class="text-end">Total:</td>
                                            <td class="text-end"><?= number_format($tot_qty_ord_v, 2) ?></td>
                                            <td class="text-end"><?= number_format($tot_qty_rec_v, 2) ?></td>
                                            <td class="text-end"><?= number_format($tot_qty_not_rec_v, 2) ?></td>
                                            <td class="text-end"><?= number_format($tot_qty_ret_v, 2) ?></td>
                                            <td class="text-end">$<?= number_format($tot_amt_ret_v, 2) ?></td>
                                            <td class="text-end"><?= $tot_qty_ord_v > 0 ? '$' . number_format($tot_line_v / $tot_qty_ord_v, 2) : '-' ?></td>
                                            <td class="text-end">$<?= number_format($tot_line_v, 2) ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer bg-light small text-muted">
                            Total: <?= count($vendor['items']) ?> line item(s) • <?= count($vendor['seen_orders']) ?> purchase order(s)
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php else: ?>
        <!-- By Product -->
        <?php if (empty($by_product)): ?>
            <div class="alert alert-info">No purchase data found for the selected filters.</div>
        <?php else: ?>
            <?php foreach ($by_product as $pkey => $prod): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">
                            <i class="bi bi-box-seam me-2"></i><?= htmlspecialchars($prod['item_name']) ?>
                            <?php if (!empty($prod['sku'])): ?><code class="ms-2"><?= htmlspecialchars($prod['sku']) ?></code><?php endif; ?>
                        </h5>
                        <div class="d-flex gap-3 flex-wrap align-items-center">
                            <span class="badge bg-info">Qty: <?= number_format($prod['total_qty'], 2) ?></span>
                            <span><strong>Total:</strong> $<?= number_format($prod['total_amount'], 2) ?></span>
                            <span class="text-success"><strong>Paid:</strong> $<?= number_format($prod['total_paid'], 2) ?></span>
                            <span class="text-warning"><strong>Amount Return:</strong> $<?= number_format($prod['total_amount_return'] ?? 0, 2) ?></span>
                            <span class="text-<?= $prod['balance_due'] > 0 ? 'danger' : 'success' ?>"><strong>Balance Due:</strong> $<?= number_format($prod['balance_due'], 2) ?></span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-center">No</th>
                                        <th>Vendor</th>
                                        <th>Order #</th>
                                        <th>Order Date</th>
                                        <th class="text-end">Qty Ordered</th>
                                        <th class="text-end">Qty Received</th>
                                        <th class="text-end">Qty Not Received</th>
                                        <th class="text-end">Qty Return</th>
                                        <th class="text-end">Amount Return</th>
                                        <th class="text-end">Unit Price</th>
                                        <th class="text-end">Line Total</th>
                                        <th>Payment Status</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $last_order_id_p = null;
                                    $row_no_p = 0;
                                    $tot_qty_ord_p = 0; $tot_qty_rec_p = 0; $tot_qty_not_rec_p = 0; $tot_qty_ret_p = 0; $tot_amt_ret_p = 0; $tot_line_p = 0;
                                    foreach ($prod['rows'] as $item):
                                        $row_no_p++;
                                        $show_order_totals = ($last_order_id_p !== (int)($item['purchase_order_id'] ?? 0));
                                        if ($show_order_totals) $last_order_id_p = (int)($item['purchase_order_id'] ?? 0);
                                        $tot_qty_ord_p += $item['quantity_ordered'];
                                        $tot_qty_rec_p += $item['quantity_received'];
                                        $tot_qty_not_rec_p += $item['quantity_not_received'];
                                        $tot_qty_ret_p += ($item['quantity_returned'] ?? 0);
                                        $tot_amt_ret_p += ($item['amount_returned'] ?? 0);
                                        $tot_line_p += $item['line_total'];
                                        $status_colors = ['draft' => 'secondary', 'sent' => 'info', 'confirmed' => 'primary', 'partial' => 'warning', 'received' => 'success', 'cancelled' => 'danger'];
                                        $sc = $status_colors[$item['order_status']] ?? 'secondary';
                                        $pay_colors = ['unpaid' => 'danger', 'partial' => 'warning', 'paid' => 'success', 'overpaid' => 'info'];
                                        $pay_sc = $pay_colors[$item['payment_status']] ?? 'secondary';
                                        ?>
                                        <tr>
                                            <td class="text-center"><?= $row_no_p ?></td>
                                            <td><strong><?= htmlspecialchars($item['vendor_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($item['order_number']) ?></td>
                                            <td><?= date('M j, Y', strtotime($item['order_date'])) ?></td>
                                            <td class="text-end"><?= number_format($item['quantity_ordered'], 2) ?></td>
                                            <td class="text-end"><?= number_format($item['quantity_received'], 2) ?></td>
                                            <td class="text-end"><?= number_format($item['quantity_not_received'], 2) ?></td>
                                            <td class="text-end"><?= number_format($item['quantity_returned'] ?? 0, 2) ?></td>
                                            <td class="text-end">$<?= number_format($item['amount_returned'] ?? 0, 2) ?></td>
                                            <td class="text-end">$<?= number_format($item['unit_price'], 2) ?></td>
                                            <td class="text-end"><strong>$<?= number_format($item['line_total'], 2) ?></strong></td>
                                            <td><span class="badge bg-<?= $pay_sc ?>"><?= ucfirst($item['payment_status']) ?></span></td>
                                            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($item['order_status']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                        <tr class="table-light fw-bold">
                                            <td colspan="4" class="text-end">Total:</td>
                                            <td class="text-end"><?= number_format($tot_qty_ord_p, 2) ?></td>
                                            <td class="text-end"><?= number_format($tot_qty_rec_p, 2) ?></td>
                                            <td class="text-end"><?= number_format($tot_qty_not_rec_p, 2) ?></td>
                                            <td class="text-end"><?= number_format($tot_qty_ret_p, 2) ?></td>
                                            <td class="text-end">$<?= number_format($tot_amt_ret_p, 2) ?></td>
                                            <td class="text-end"><?= $tot_qty_ord_p > 0 ? '$' . number_format($tot_line_p / $tot_qty_ord_p, 2) : '-' ?></td>
                                            <td class="text-end">$<?= number_format($tot_line_p, 2) ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer bg-light small text-muted">
                            Total: <?= count($prod['rows']) ?> line item(s) • <?= count($prod['seen_orders']) ?> purchase order(s)
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>


<style>
@media print {
    .btn, form, .card-header .badge { display: none !important; }
    .card { break-inside: avoid; }
}
#orderCodeDropdown { position: absolute; top: 100%; left: 0; z-index: 1050; }
#orderCodeDropdown .dropdown-item { cursor: pointer; white-space: nowrap; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('orderCodeFilter');
    const dropdown = document.getElementById('orderCodeDropdown');
    const form = input && input.closest('form');
    if (!input || !dropdown || !form) return;

    const fromInput = form.querySelector('input[name="from"]');
    const toInput = form.querySelector('input[name="to"]');

    function fetchOrderCodes() {
        const q = input.value.trim();
        if (q.length < 1) { dropdown.style.display = 'none'; return; }
        const from = (fromInput && fromInput.value) || '<?= addslashes($from) ?>';
        const to = (toInput && toInput.value) || '<?= addslashes($to) ?>';
        fetch('get_order_codes.php?q=' + encodeURIComponent(q) + '&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to))
            .then(r => r.json())
            .then(codes => {
                dropdown.innerHTML = '';
                if (codes.length === 0) {
                    dropdown.innerHTML = '<span class="dropdown-item text-muted">No matches</span>';
                } else {
                    codes.forEach(code => {
                        const a = document.createElement('a');
                        a.className = 'dropdown-item';
                        a.href = '#';
                        a.textContent = code;
                        a.addEventListener('click', function(e) { e.preventDefault(); input.value = code; dropdown.style.display = 'none'; });
                        dropdown.appendChild(a);
                    });
                }
                dropdown.style.display = 'block';
            })
            .catch(() => { dropdown.style.display = 'none'; });
    }

    let debounce;
    input.addEventListener('input', function() {
        clearTimeout(debounce);
        debounce = setTimeout(fetchOrderCodes, 200);
    });
    input.addEventListener('focus', function() {
        if (input.value.trim()) fetchOrderCodes();
    });
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) dropdown.style.display = 'none';
    });
});
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
