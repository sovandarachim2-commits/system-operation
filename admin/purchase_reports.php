<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_reports.view');

$pdo = get_db_connection();

$errors = [];
$success = '';

// Get date filters
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$firstOfMonth = date('Y-m-01');
$lastOfMonth = date('Y-m-t');
$firstOfLastMonth = date('Y-m-01', strtotime('first day of last month'));
$lastOfLastMonth = date('Y-m-t', strtotime('last month'));

// If no filter is set, default to this month
if (!isset($_GET['from']) && !isset($_GET['to'])) {
    $from = $firstOfMonth;
    $to = $lastOfMonth;
} else {
    $from = $_GET['from'] ?? $firstOfMonth;
    $to = $_GET['to'] ?? $lastOfMonth;
}
$vendor_filter = (int)($_GET['vendor_filter'] ?? 0);
$status_filter = trim((string)($_GET['status_filter'] ?? ''));
$valid_statuses = ['draft', 'sent', 'confirmed', 'partial', 'received', 'cancelled'];
if ($status_filter !== '' && !in_array($status_filter, $valid_statuses, true)) {
    $status_filter = '';
}

/**
 * Append shared purchase report filters (date / vendor / status).
 * @param bool $excludeCancelledWhenNoStatus When true and no status filter, skip cancelled rows.
 */
function purchase_reports_append_filters(
    string &$sql,
    array &$params,
    string $from,
    string $to,
    int $vendorFilter,
    string $statusFilter,
    bool $excludeCancelledWhenNoStatus = false
): void {
    if ($from !== '') {
        $sql .= ' AND po.order_date >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        $sql .= ' AND po.order_date <= ?';
        $params[] = $to;
    }
    if ($vendorFilter > 0) {
        $sql .= ' AND po.vendor_id = ?';
        $params[] = $vendorFilter;
    }
    if ($statusFilter !== '') {
        $sql .= ' AND po.status = ?';
        $params[] = $statusFilter;
    } elseif ($excludeCancelledWhenNoStatus) {
        $sql .= " AND po.status != 'cancelled'";
    }
}

$querySuffix = '';
if ($vendor_filter > 0) {
    $querySuffix .= '&vendor_filter=' . $vendor_filter;
}
if ($status_filter !== '') {
    $querySuffix .= '&status_filter=' . rawurlencode($status_filter);
}

// Get vendors for filter
try {
    $vendorsStmt = $pdo->query('SELECT id, name FROM purchase_vendors ORDER BY name');
    $vendors = $vendorsStmt->fetchAll();
} catch (PDOException $e) {
    $vendors = [];
    $errors[] = 'Purchase vendors table not found. Please run setup script first.';
}

// Build query for purchase orders
$params = [];
$sql = '
    SELECT 
        po.*,
        pv.name as vendor_name,
        u.name as created_by_name,
        COUNT(poi.id) as item_count,
        SUM(poi.quantity_ordered) as total_quantity,
        SUM(poi.quantity_received) as total_received,
        SUM(poi.line_total) as total_line_amount
    FROM purchase_orders po
    LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id
    LEFT JOIN users u ON po.created_by = u.id
    LEFT JOIN purchase_order_items poi ON po.id = poi.purchase_order_id
    WHERE 1=1
';
purchase_reports_append_filters($sql, $params, $from, $to, $vendor_filter, $status_filter);
$sql .= ' GROUP BY po.id ORDER BY po.created_at DESC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
} catch (PDOException $e) {
    $orders = [];
    $errors[] = 'Error loading purchase orders: ' . htmlspecialchars($e->getMessage());
}

// Calculate summary statistics
$total_orders = count($orders);
$total_amount = 0;
$total_items = 0;
$total_received = 0;
$orders_by_status = [];

foreach ($orders as $order) {
    $total_amount += $order['total_amount'];
    $total_items += $order['total_quantity'];
    $total_received += $order['total_received'];
    
    $status = $order['status'];
    if (!isset($orders_by_status[$status])) {
        $orders_by_status[$status] = 0;
    }
    $orders_by_status[$status]++;
}

// Get vendor performance
try {
    $vendorParams = [];
    $vendorSql = '
        SELECT 
            pv.name,
            COUNT(po.id) as order_count,
            COALESCE(SUM(po.total_amount), 0) as total_spent,
            COALESCE(SUM(item_agg.total_items), 0) as total_items,
            COALESCE(SUM(item_agg.total_received), 0) as total_received
        FROM purchase_vendors pv
        INNER JOIN purchase_orders po ON pv.id = po.vendor_id
        LEFT JOIN (
            SELECT
                purchase_order_id,
                SUM(quantity_ordered) AS total_items,
                SUM(quantity_received) AS total_received
            FROM purchase_order_items
            GROUP BY purchase_order_id
        ) item_agg ON item_agg.purchase_order_id = po.id
        WHERE 1=1
    ';
    purchase_reports_append_filters($vendorSql, $vendorParams, $from, $to, $vendor_filter, $status_filter);
    $vendorSql .= ' GROUP BY pv.id, pv.name ORDER BY total_spent DESC';
    $vendorStmt = $pdo->prepare($vendorSql);
    $vendorStmt->execute($vendorParams);
    $vendor_performance = $vendorStmt->fetchAll();
} catch (PDOException $e) {
    $vendor_performance = [];
}

// Get total products ordered for the filtered period (with total cost and received)
$product_totals = [];
try {
    $productParams = [];
    $productSql = "
        SELECT
            item_name,
            SUM(quantity_ordered) as total_ordered,
            SUM(quantity_received) as total_received,
            SUM(line_total) as total_cost
        FROM purchase_order_items poi
        JOIN purchase_orders po ON poi.purchase_order_id = po.id
        WHERE 1=1
    ";
    purchase_reports_append_filters($productSql, $productParams, $from, $to, $vendor_filter, $status_filter, true);
    $productSql .= ' GROUP BY item_name ORDER BY total_ordered DESC';
    $stmt = $pdo->prepare($productSql);
    $stmt->execute($productParams);
    $product_totals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $product_totals = [];
}

// Get monthly trend
try {
    $trendParams = [];
    $trendSql = '
        SELECT 
            DATE_FORMAT(po.order_date, "%Y-%m") as month,
            COUNT(po.id) as order_count,
            COALESCE(SUM(po.total_amount), 0) as total_amount,
            COALESCE(SUM(item_agg.total_items), 0) as total_items
        FROM purchase_orders po
        LEFT JOIN (
            SELECT
                purchase_order_id,
                SUM(quantity_ordered) AS total_items
            FROM purchase_order_items
            GROUP BY purchase_order_id
        ) item_agg ON item_agg.purchase_order_id = po.id
        WHERE 1=1
    ';
    purchase_reports_append_filters($trendSql, $trendParams, $from, $to, $vendor_filter, $status_filter);
    $trendSql .= ' GROUP BY DATE_FORMAT(po.order_date, "%Y-%m") ORDER BY month ASC';
    $trendStmt = $pdo->prepare($trendSql);
    $trendStmt->execute($trendParams);
    $monthly_trend = $trendStmt->fetchAll();
} catch (PDOException $e) {
    $monthly_trend = [];
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Purchase Reports</h1>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-success btn-lg" onclick="printAllTables()" type="button">
                <i class="bi bi-printer me-2"></i>Print Report
            </button>
            <button class="btn btn-outline-primary btn-lg" onclick="exportAllTablesToExcel()" type="button">
                <i class="bi bi-file-earmark-excel me-2"></i>Export Excel
            </button>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="card shadow-sm mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-12 mb-2">
                <div class="btn-group" role="group" aria-label="Quick Date Filters">
                    <a href="?from=<?= $today ?>&to=<?= $today ?><?= $querySuffix ?>" class="btn btn-outline-secondary<?= ($from === $today && $to === $today) ? ' active' : '' ?>">Today</a>
                    <a href="?from=<?= $yesterday ?>&to=<?= $yesterday ?><?= $querySuffix ?>" class="btn btn-outline-secondary<?= ($from === $yesterday && $to === $yesterday) ? ' active' : '' ?>">Yesterday</a>
                    <a href="?from=<?= $firstOfMonth ?>&to=<?= $lastOfMonth ?><?= $querySuffix ?>" class="btn btn-outline-secondary<?= ($from === $firstOfMonth && $to === $lastOfMonth) ? ' active' : '' ?>">This Month</a>
                    <a href="?from=<?= $firstOfLastMonth ?>&to=<?= $lastOfLastMonth ?><?= $querySuffix ?>" class="btn btn-outline-secondary<?= ($from === $firstOfLastMonth && $to === $lastOfLastMonth) ? ' active' : '' ?>">Last Month</a>
                </div>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">From Date</label>
                <input type="date" name="from" class="form-control form-control-lg" value="<?= htmlspecialchars($from) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">To Date</label>
                <input type="date" name="to" class="form-control form-control-lg" value="<?= htmlspecialchars($to) ?>" required>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Vendor</label>
                <select name="vendor_filter" class="form-select form-select-lg">
                    <option value="">All Vendors</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <option value="<?= (int)$vendor['id'] ?>" <?= $vendor_filter === (int)$vendor['id'] ? 'selected' : '' ?>><?= htmlspecialchars($vendor['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Status</label>
                <select name="status_filter" class="form-select form-select-lg">
                    <option value="">All Status</option>
                    <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="sent" <?= $status_filter === 'sent' ? 'selected' : '' ?>>Sent</option>
                    <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="partial" <?= $status_filter === 'partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="received" <?= $status_filter === 'received' ? 'selected' : '' ?>>Received</option>
                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary btn-lg w-100">Filter</button>
                <a href="purchase_reports.php" class="btn btn-outline-secondary btn-lg w-100">Reset</a>
            </div>
        </div>
    </form>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Total Orders</h5>
                    <h3 class="mb-0"><?= number_format($total_orders) ?></h3>
                    <small>Purchase orders</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Total Amount</h5>
                    <h3 class="mb-0">$<?= number_format($total_amount, 2) ?></h3>
                    <small>Total spending</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Items Ordered</h5>
                    <h3 class="mb-0"><?= number_format($total_items) ?></h3>
                    <small>Total quantity</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Items Received</h5>
                    <h3 class="mb-0"><?= number_format($total_received) ?></h3>
                    <small><?= $total_items > 0 ? number_format(($total_received / $total_items) * 100, 1) : 0 ?>% received</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Breakdown -->
    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="card shadow">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Monthly Purchase Trend</h5>
                </div>
                <div class="card-body">
                    <canvas id="trendChart" height="100"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Order Status Breakdown</h5>
                </div>
                <div class="card-body">
                    <canvas id="statusChart" height="150"></canvas>
                </div>
            </div>
        </div>
    </div>


    <!-- Total Products Ordered -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <i class="bi bi-box-seam me-2"></i>
                <h5 class="mb-0">Total Products Ordered (<?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?>)</h5>
            </div>
            <div>
                <button type="button" class="btn btn-outline-primary btn-sm me-1" style="border-color:#2196f3;color:#2196f3;background:#e3f2fd" onclick="printTable('productsTable')" title="Print"><i class="bi bi-printer"></i></button>
                <button type="button" class="btn btn-outline-success btn-sm" style="border-color:#43a047;color:#43a047;background:#e3f2fd" onclick="exportTableToExcel('productsTable')" title="Export to Excel"><i class="bi bi-file-earmark-excel"></i></button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle" id="productsTable">
                    <caption class="d-print-table-caption text-primary" style="caption-side: top; text-align: left; font-size: 1.25em; font-weight: bold; color: #0d6efd; letter-spacing: 0.5px;">
                        Products Ordered Report <span style='color:#43a047;'>(<?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?>)</span>
                    </caption>
                    <thead class="table-primary">
                        <tr>
                            <th>No</th>
                            <th>Product Name</th>
                            <th class="text-end">Total Ordered</th>
                            <th class="text-end">Received</th>
                            <th class="text-end" style="color:#dc3545;">Not Yet Received</th>
                            <th class="text-end">Total Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1; 
                        $grand_total_ordered = 0;
                        $grand_total_received = 0;
                        $grand_total_not_yet = 0;
                        $grand_total_cost = 0;
                        foreach ($product_totals as $prod): 
                            $grand_total_ordered += $prod['total_ordered'];
                            $grand_total_received += $prod['total_received'];
                            $not_yet = max(0, $prod['total_ordered'] - $prod['total_received']);
                            $grand_total_not_yet += $not_yet;
                            $grand_total_cost += $prod['total_cost'];
                        ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><?= htmlspecialchars($prod['item_name']) ?></td>
                            <td class="text-end fw-bold text-success"><?= number_format($prod['total_ordered'], 2) ?></td>
                            <td class="text-end fw-bold text-info"><?= number_format($prod['total_received'], 2) ?></td>
                            <td class="text-end fw-bold" style="color:#dc3545;"><?= number_format($not_yet, 2) ?></td>
                            <td class="text-end fw-bold text-primary">$<?= number_format($prod['total_cost'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($product_totals)): ?>
                        <tr><td colspan="4" class="text-center text-muted">No products found for this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="2" class="text-end">Total:</th>
                            <th class="text-end fw-bold text-success"><?= number_format($grand_total_ordered, 2) ?></th>
                            <th class="text-end fw-bold text-info"><?= number_format($grand_total_received, 2) ?></th>
                            <th class="text-end fw-bold" style="color:#dc3545;"><?= number_format($grand_total_not_yet, 2) ?></th>
                            <th class="text-end fw-bold text-primary">$<?= number_format($grand_total_cost, 2) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Vendor Performance -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light d-flex align-items-center justify-content-between">
            <h5 class="mb-0">Vendor Performance</h5>
            <div>
                <button type="button" class="btn btn-outline-warning btn-sm me-1" style="border-color:#ffc107;color:#856404;background:#fff3cd" onclick="printTable('vendorTable')" title="Print"><i class="bi bi-printer"></i></button>
                <button type="button" class="btn btn-outline-success btn-sm" style="border-color:#43a047;color:#43a047;background:#fff3cd" onclick="exportTableToExcel('vendorTable')" title="Export to Excel"><i class="bi bi-file-earmark-excel"></i></button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm" id="vendorTable">
                    <caption class="d-print-table-caption" style="caption-side: top; text-align: left; font-size: 1.15em; font-weight: bold; color: #232323;">Vendor Performance</caption>
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Total Spent</th>
                            <th class="text-end">Items Ordered</th>
                            <th class="text-end">Items Received</th>
                            <th class="text-end">Receive Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_order_count = 0;
                        $total_spent = 0;
                        $total_items = 0;
                        $total_received = 0;
                        foreach ($vendor_performance as $vendor): 
                            $total_order_count += $vendor['order_count'];
                            $total_spent += $vendor['total_spent'];
                            $total_items += $vendor['total_items'];
                            $total_received += $vendor['total_received'];
                            $receive_rate = $vendor['total_items'] > 0 ? ($vendor['total_received'] / $vendor['total_items']) * 100 : 0;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($vendor['name']) ?></td>
                            <td class="text-end"><?= number_format($vendor['order_count']) ?></td>
                            <td class="text-end">$<?= number_format($vendor['total_spent'], 2) ?></td>
                            <td class="text-end"><?= number_format($vendor['total_items']) ?></td>
                            <td class="text-end"><?= number_format($vendor['total_received']) ?></td>
                            <td class="text-end"><?= number_format($receive_rate, 1) ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($vendor_performance)): ?>
                        <tr><td colspan="6" class="text-center py-3">No vendor data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th class="text-end">Total:</th>
                            <th class="text-end"><?= number_format($total_order_count) ?></th>
                            <th class="text-end">$<?= number_format($total_spent, 2) ?></th>
                            <th class="text-end"><?= number_format($total_items) ?></th>
                            <th class="text-end"><?= number_format($total_received) ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Detailed Orders Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-light d-flex align-items-center justify-content-between">
            <h5 class="mb-0">Detailed Purchase Orders (<?= count($orders) ?> orders)</h5>
            <div>
                <button type="button" class="btn btn-outline-success btn-sm me-1" style="border-color:#388e3c;color:#388e3c;background:#e9fbe5" onclick="printTable('ordersTable')" title="Print"><i class="bi bi-printer"></i></button>
                <button type="button" class="btn btn-outline-primary btn-sm" style="border-color:#1976d2;color:#1976d2;background:#e9fbe5" onclick="exportTableToExcel('ordersTable')" title="Export to Excel"><i class="bi bi-file-earmark-excel"></i></button>
            </div>
        </div>
        <script>
        // Print a specific table by id with clean style and header
        function printTable(tableId) {
            var table = document.getElementById(tableId);
            if (!table) return;
            var logoUrl = '<?= htmlspecialchars($BASE_URL) ?>/public/image.png';
            var title = document.title || 'Purchase Report';
            var headerHtml = `
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
                    <img src="${logoUrl}" alt="Logo" style="height:60px;">
                    <div>
                        <h2 style="margin:0;font-family:sans-serif;">${title}</h2>
                        <div style="font-size:16px;color:#555;">Printed: ${new Date().toLocaleString()}</div>
                    </div>
                </div>
            `;
            var style = `
                <style>
                    body { font-family: Arial, sans-serif; background: #fff; color: #222; margin: 24px; }
                    table { border-collapse: collapse; width: 100%; margin-top: 16px; }
                    th, td { border: 1px solid #bbb; padding: 8px 12px; text-align: left; }
                    th { background: #fdb04c; color: #232323; font-size: 1.1em; }
                    tfoot th, tfoot td { background: #f7f7f7; font-weight: bold; }
                    caption { caption-side: top; font-size: 1.2em; margin-bottom: 8px; }
                    @media print { body { margin: 0; } }
                </style>
            `;
            var html = '<html><head><title>' + title + '</title>' + style + '</head><body>' + headerHtml + table.outerHTML + '</body></html>';
            var printFrame = document.createElement('iframe');
            printFrame.style.position = 'fixed';
            printFrame.style.right = '0';
            printFrame.style.bottom = '0';
            printFrame.style.width = '0';
            printFrame.style.height = '0';
            printFrame.style.border = 'none';
            document.body.appendChild(printFrame);
            var frameDoc = printFrame.contentWindow || printFrame.contentDocument;
            if (frameDoc.document) frameDoc = frameDoc.document;
            frameDoc.open();
            frameDoc.write(html);
            frameDoc.close();
            printFrame.onload = function() {
                frameDoc.defaultView.focus();
                frameDoc.defaultView.print();
                setTimeout(function() { document.body.removeChild(printFrame); }, 1000);
            };
        }

        // Export a specific table to Excel
        function exportTableToExcel(tableId) {
            var table = document.getElementById(tableId);
            var html = table.outerHTML.replace(/ /g, '%20');
            var filename = tableId + '_export.xls';
            var downloadLink = document.createElement('a');
            document.body.appendChild(downloadLink);
            downloadLink.href = 'data:application/vnd.ms-excel,' + html;
            downloadLink.download = filename;
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }
        </script>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle" id="ordersTable">
                    <caption class="d-print-table-caption" style="caption-side: top; text-align: left; font-size: 1.15em; font-weight: bold; color: #232323;">Detailed Purchase Orders (<?= count($orders) ?> orders)</caption>
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Vendor</th>
                            <th>Order Date</th>
                            <th>Status</th>
                            <th class="text-end">Total Amount</th>
                            <th class="text-end">Total Items</th>
                            <th class="text-end">Total Received</th>
                            <th class="text-end">Not Yet Received</th>
                            <th>Created By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="8" class="text-center py-3">No orders found</td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
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
                                    <td><?= htmlspecialchars($order['order_number']) ?></td>
                                    <td><?= htmlspecialchars($order['vendor_name']) ?></td>
                                    <td><?= date('M j, Y', strtotime($order['order_date'])) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $status_color ?>"><?= ucfirst($order['status']) ?></span>
                                    </td>
                                    <td class="text-end">$<?= number_format($order['total_line_amount'], 2) ?></td>
                                    <td class="text-end"><?= number_format($order['total_quantity']) ?></td>
                                    <td class="text-end"><?= number_format($order['total_received']) ?></td>
                                    <td class="text-end text-danger fw-bold">
                                        <?= number_format(max(0, $order['total_quantity'] - $order['total_received'])) ?>
                                    </td>
                                    <td><?= htmlspecialchars($order['created_by_name']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <?php
                        // Calculate total not yet received
                        $total_not_yet_received = 0;
                        foreach ($orders as $order) {
                            $total_not_yet_received += max(0, $order['total_quantity'] - $order['total_received']);
                        }
                        ?>
                        <tr>
                            <th colspan="4" class="text-end">Total:</th>
                            <th class="text-end">$<?= number_format($total_amount, 2) ?></th>
                            <th class="text-end"><?= number_format($total_items) ?></th>
                            <th class="text-end"><?= number_format($total_received) ?></th>
                            <th class="text-end text-danger fw-bold"><?= number_format($total_not_yet_received) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Print all main tables together
function printAllTables() {
    var logoUrl = '<?= htmlspecialchars($BASE_URL) ?>/public/image.png';
    var title = document.title || 'Purchase Report';
    var headerHtml = `
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
            <img src="${logoUrl}" alt="Logo" style="height:60px;">
            <div>
                <h2 style="margin:0;font-family:sans-serif;">${title}</h2>
                <div style="font-size:16px;color:#555;">Printed: ${new Date().toLocaleString()}</div>
            </div>
        </div>
    `;
    var style = `
        <style>
            body { font-family: Arial, sans-serif; background: #fff; color: #222; margin: 24px; }
            table { border-collapse: collapse; width: 100%; margin-top: 16px; }
            th, td { border: 1px solid #bbb; padding: 8px 12px; text-align: left; }
            th { background: #fdb04c !important; color: #232323 !important; font-size: 1.1em; }
            tfoot th, tfoot td { background: #f7f7f7 !important; font-weight: bold; }
            caption { caption-side: top; font-size: 1.2em; margin-bottom: 8px; color: #232323; font-weight: bold; }
            .text-success { color: #198754 !important; }
            .text-primary { color: #0d6efd !important; }
            .text-info { color: #0dcaf0 !important; }
            .text-danger { color: #dc3545 !important; }
            .fw-bold { font-weight: bold !important; }
            .bg-warning { background: #ffc107 !important; color: #232323 !important; }
            .bg-success { background: #198754 !important; color: #fff !important; }
            .bg-info { background: #0dcaf0 !important; color: #232323 !important; }
            .bg-primary { background: #0d6efd !important; color: #fff !important; }
            .bg-secondary { background: #6c757d !important; color: #fff !important; }
            .bg-danger { background: #dc3545 !important; color: #fff !important; }
            .table-striped tbody tr:nth-of-type(odd) { background-color: #f9f9f9; }
            @media print { body { margin: 0; } }
        </style>
    `;
    var tables = [
        {el: document.getElementById('productsTable'), color: '#e3f2fd'}, // light blue
        {el: document.getElementById('vendorTable'), color: '#fff3cd'},   // light yellow
        {el: document.getElementById('ordersTable'), color: '#e9fbe5'}    // light green
    ];
    var html = '<html><head><title>' + title + '</title>' + style + '</head><body>' + headerHtml;
    tables.forEach(function(tbl) {
        if (tbl.el) {
            html += '<div style="background:' + tbl.color + ';padding:18px 12px 12px 12px;border-radius:12px;margin-bottom:24px;">' + tbl.el.outerHTML + '</div>';
        }
    });
    html += '</body></html>';
    var printFrame = document.createElement('iframe');
    printFrame.style.position = 'fixed';
    printFrame.style.right = '0';
    printFrame.style.bottom = '0';
    printFrame.style.width = '0';
    printFrame.style.height = '0';
    printFrame.style.border = 'none';
    document.body.appendChild(printFrame);
    var frameDoc = printFrame.contentWindow || printFrame.contentDocument;
    if (frameDoc.document) frameDoc = frameDoc.document;
    frameDoc.open();
    frameDoc.write(html);
    frameDoc.close();
    printFrame.onload = function() {
        frameDoc.defaultView.focus();
        frameDoc.defaultView.print();
        setTimeout(function() { document.body.removeChild(printFrame); }, 1000);
    };
}

// Export all main tables to a single Excel file
function exportAllTablesToExcel() {
    var tables = [
        document.getElementById('productsTable'),
        document.getElementById('vendorTable'),
        document.getElementById('ordersTable')
    ];
    var html = '';
    tables.forEach(function(tbl) { if (tbl) html += tbl.outerHTML + '<br><br>'; });
    var filename = 'purchase_report_export.xls';
    var downloadLink = document.createElement('a');
    document.body.appendChild(downloadLink);
    downloadLink.href = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    downloadLink.download = filename;
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
document.addEventListener('DOMContentLoaded', function() {
    // Monthly Trend Chart
    const trendData = <?= json_encode($monthly_trend) ?>;
    
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: trendData.map(d => d.month),
            datasets: [{
                label: 'Total Amount',
                data: trendData.map(d => d.total_amount),
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y'
            }, {
                label: 'Order Count',
                data: trendData.map(d => d.order_count),
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Amount ($)'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Orders'
                    },
                    grid: {
                        drawOnChartArea: false,
                    }
                }
            }
        }
    });

    // Status Breakdown Chart
    const statusData = <?= json_encode($orders_by_status) ?>;
    
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: Object.keys(statusData).map(s => s.charAt(0).toUpperCase() + s.slice(1)),
            datasets: [{
                data: Object.values(statusData),
                backgroundColor: ['#6c757d', '#17a2b8', '#007bff', '#ffc107', '#28a745', '#dc3545'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { 
                    position: 'bottom' 
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((context.parsed / total) * 100).toFixed(1);
                            return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
});

function exportToExcel() {
    const table = document.getElementById('ordersTable');
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        
        for (let j = 0; j < cols.length; j++) {
            const text = cols[j].innerText.replace(/,/g, '');
            rowData.push('"' + text + '"');
        }
        
        csv.push(rowData.join(','));
    }
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'purchase_report_' + document.querySelector('input[name="from"]').value + '_to_' + document.querySelector('input[name="to"]').value + '.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<style>
@media print {
    .btn, form, .card-header h5 {
        display: none !important;
    }
    
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
        page-break-inside: avoid;
    }
    
    .col-lg-8, .col-lg-4 {
        float: left;
        width: 50%;
    }
}
</style>

<?php include __DIR__ . '/../layout/footer.php'; ?>
