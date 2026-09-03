<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'stock_reports.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/product_set_location.php';

$pdo = get_db_connection();
product_set_ensure_schema($pdo);
$errors = [];
$success = '';

// Get filter parameters
$filter_location = (int)($_GET['filter_location'] ?? 0);
$filter_item_type = $_GET['filter_item_type'] ?? 'all'; // all, inventory, set
$report_type = $_GET['report_type'] ?? 'summary';
$low_stock_threshold = (float)($_GET['low_stock_threshold'] ?? 10);

// Get storage locations for filter
try {
    $stmt = $pdo->query("SELECT id, location_code, location_name FROM storage_locations WHERE is_active = 1 ORDER BY location_code");
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $locations = [];
}

// Base aggregated inventory (current_inventory + product_sets, same logic as inventory_view)
$base_sql = "
    SELECT
        item_name,
        sku,
        item_type,
        storage_location_id,
        location_code,
        location_name,
        SUM(quantity_on_hand) as current_stock,
        SUM(total_value) as total_value,
        CASE WHEN SUM(quantity_on_hand) > 0 THEN SUM(total_value) / SUM(quantity_on_hand) ELSE 0 END as unit_cost
    FROM (
        SELECT
            ci.item_name,
            ci.sku,
            'inventory' as item_type,
            ci.storage_location_id,
            sl.location_code,
            sl.location_name,
            ci.quantity_on_hand,
            COALESCE(ci.total_value, ci.quantity_on_hand * ci.unit_cost, 0) as total_value
        FROM current_inventory ci
        LEFT JOIN storage_locations sl ON ci.storage_location_id = sl.id
        LEFT JOIN product_sets ps ON ci.item_name = ps.set_name
        WHERE ps.id IS NULL

        UNION ALL

        SELECT
            ps.set_name as item_name,
            CONCAT('SET-', ps.id) as sku,
            'set' as item_type,
            COALESCE(ps.storage_location_id, 0) as storage_location_id,
            sl.location_code,
            sl.location_name,
            ps.available_stock as quantity_on_hand,
            (ps.available_stock * ps.selling_price) as total_value
        FROM product_sets ps
        LEFT JOIN storage_locations sl ON sl.id = ps.storage_location_id
        WHERE ps.is_active = 1
    ) inv
    GROUP BY item_name, sku, item_type, storage_location_id, location_code, location_name
";

$where_parts = [];
$params = [];
if ($filter_location > 0) {
    $where_parts[] = "storage_location_id = ?";
    $params[] = $filter_location;
}
if ($filter_item_type === 'inventory') {
    $where_parts[] = "item_type = 'inventory'";
} elseif ($filter_item_type === 'set') {
    $where_parts[] = "item_type = 'set'";
}
$where_sql = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// Get stock data based on report type
$stock_data = [];
$total_value = 0;
$total_quantity = 0;

try {
    switch ($report_type) {
        case 'summary':
            $sql = "SELECT *, 
                CASE 
                    WHEN current_stock <= 0 THEN 'Out of Stock'
                    WHEN current_stock <= ? AND ? > 0 THEN 'Low Stock'
                    ELSE 'In Stock'
                END as stock_level_status,
                ? as low_threshold
                FROM ($base_sql) agg $where_sql
                ORDER BY item_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($params, [$low_stock_threshold, $low_stock_threshold, $low_stock_threshold]));
            $stock_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($stock_data as $item) {
                $total_value += (float)($item['total_value'] ?? 0);
                $total_quantity += (float)($item['current_stock'] ?? 0);
            }
            break;

        case 'low_stock':
            $add_where = $where_sql ? ' AND ' . preg_replace('/^WHERE\s+/', '', $where_sql) : '';
            $sql = "SELECT *, (? - current_stock) as needed_quantity 
                FROM ($base_sql) agg 
                WHERE current_stock > 0 AND current_stock <= ? $add_where
                ORDER BY needed_quantity DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$low_stock_threshold, $low_stock_threshold], $params));
            $stock_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'out_of_stock':
            $add_where = $where_sql ? preg_replace('/^WHERE\s+/', ' AND ', $where_sql) : '';
            $sql = "SELECT * FROM ($base_sql) agg 
                WHERE current_stock <= 0 $add_where
                ORDER BY item_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $stock_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'value_analysis':
            $sql = "SELECT * FROM ($base_sql) agg $where_sql
                ORDER BY total_value DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $stock_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total_value = array_sum(array_column($stock_data, 'total_value'));
            $total_quantity = array_sum(array_column($stock_data, 'current_stock'));
            break;

        case 'location_summary':
            $sql = "SELECT 
                COALESCE(location_code, 'N/A') as location_name,
                location_code,
                COUNT(*) as item_count,
                SUM(current_stock) as total_quantity,
                SUM(total_value) as total_value,
                SUM(CASE WHEN current_stock > 0 AND current_stock <= ? THEN 1 ELSE 0 END) as low_stock_count,
                SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock_count
                FROM ($base_sql) agg
                " . ($where_sql ? ' ' . $where_sql : '') . "
                GROUP BY storage_location_id, location_code, location_name
                ORDER BY location_code";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$low_stock_threshold], $params));
            $stock_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
} catch (PDOException $e) {
    $errors[] = 'Error generating report: ' . $e->getMessage();
}

include __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Stock Reports <small class="text-muted">(Current Inventory)</small></h1>
        <div class="d-flex gap-2">
            <a href="inventory_view.php" class="btn btn-outline-info">
                <i class="bi bi-box-seam me-1"></i>View Inventory
            </a>
            <button type="button" class="btn btn-outline-success" onclick="exportReport()">
                <i class="bi bi-download me-2"></i>Export to Excel
            </button>
            <button type="button" class="btn btn-outline-primary" onclick="printReport()">
                <i class="bi bi-printer me-2"></i>Print Report
            </button>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>

    <!-- Report Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Report Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Report Type</label>
                    <select name="report_type" class="form-select" onchange="this.form.submit()">
                        <option value="summary" <?= $report_type === 'summary' ? 'selected' : '' ?>>Stock Summary</option>
                        <option value="low_stock" <?= $report_type === 'low_stock' ? 'selected' : '' ?>>Low Stock Items</option>
                        <option value="out_of_stock" <?= $report_type === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                        <option value="value_analysis" <?= $report_type === 'value_analysis' ? 'selected' : '' ?>>Value Analysis</option>
                        <option value="location_summary" <?= $report_type === 'location_summary' ? 'selected' : '' ?>>Location Summary</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Location</label>
                    <select name="filter_location" class="form-select">
                        <option value="">All Locations</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= (int)$loc['id'] ?>" <?= $filter_location == $loc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($loc['location_code']) ?> - <?= htmlspecialchars($loc['location_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Item Type</label>
                    <select name="filter_item_type" class="form-select">
                        <option value="all" <?= $filter_item_type === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="inventory" <?= $filter_item_type === 'inventory' ? 'selected' : '' ?>>Inventory</option>
                        <option value="set" <?= $filter_item_type === 'set' ? 'selected' : '' ?>>Product Sets</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Low Stock Threshold</label>
                    <input type="number" name="low_stock_threshold" class="form-control" value="<?= (int)$low_stock_threshold ?>" min="1" step="1" title="Items with quantity below this are 'Low Stock'">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search me-2"></i>Generate
                    </button>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="button" class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                        <i class="bi bi-x-circle me-2"></i>Clear
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Content -->
    <div class="card shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="bi bi-file-earmark-bar-graph me-2"></i>
                <?= ucfirst(str_replace('_', ' ', $report_type)) ?> Report
            </h5>
            <span class="badge bg-primary"><?= count($stock_data) ?> items</span>
        </div>
        <div class="card-body">
            <?php if (empty($stock_data)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox text-muted fs-1"></i>
                    <p class="text-muted mt-3">No data found for the selected filters.</p>
                </div>
            <?php else: ?>
                <?php if ($report_type === 'summary' || $report_type === 'value_analysis'): ?>
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="card border-primary">
                                <div class="card-body text-center">
                                    <h6 class="card-title text-primary">Total Items</h6>
                                    <h3 class="mb-0"><?= count($stock_data) ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-success">
                                <div class="card-body text-center">
                                    <h6 class="card-title text-success">Total Quantity</h6>
                                    <h3 class="mb-0"><?= number_format($total_quantity, 2) ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-info">
                                <div class="card-body text-center">
                                    <h6 class="card-title text-info">Total Value</h6>
                                    <h3 class="mb-0">$<?= number_format($total_value, 2) ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="reportTable">
                        <thead class="table-dark">
                            <?php if ($report_type === 'location_summary'): ?>
                                <tr>
                                    <th>Location</th>
                                    <th class="text-end">Items</th>
                                    <th class="text-end">Total Quantity</th>
                                    <th class="text-end">Total Value</th>
                                    <th class="text-end">Low Stock</th>
                                    <th class="text-end">Out of Stock</th>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <th>No</th>
                                    <th>Item Name</th>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th class="text-end">Quantity</th>
                                    <?php if ($report_type === 'low_stock'): ?>
                                        <th class="text-end">Needed</th>
                                    <?php endif; ?>
                                    <?php if ($report_type === 'summary'): ?>
                                        <th>Status</th>
                                    <?php endif; ?>
                                    <?php if ($report_type === 'value_analysis' || $report_type === 'summary'): ?>
                                        <th class="text-end">Unit Cost</th>
                                        <th class="text-end">Total Value</th>
                                    <?php endif; ?>
                                </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php if ($report_type === 'location_summary'): ?>
                                <?php foreach ($stock_data as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['location_code'] ?? $item['location_name']) ?> - <?= htmlspecialchars($item['location_name'] ?? '') ?></td>
                                        <td class="text-end"><?= number_format($item['item_count']) ?></td>
                                        <td class="text-end"><?= number_format($item['total_quantity'], 2) ?></td>
                                        <td class="text-end">$<?= number_format($item['total_value'], 2) ?></td>
                                        <td class="text-end">
                                            <?php if (($item['low_stock_count'] ?? 0) > 0): ?>
                                                <span class="badge bg-warning"><?= $item['low_stock_count'] ?></span>
                                            <?php else: ?>0<?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if (($item['out_of_stock_count'] ?? 0) > 0): ?>
                                                <span class="badge bg-danger"><?= $item['out_of_stock_count'] ?></span>
                                            <?php else: ?>0<?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php $row_number = 1; foreach ($stock_data as $item): ?>
                                    <tr>
                                        <td><?= $row_number++ ?></td>
                                        <td><?= htmlspecialchars($item['item_name'] ?? '') ?></td>
                                        <td>
                                            <span class="badge bg-<?= ($item['item_type'] ?? '') === 'set' ? 'info' : 'primary' ?>">
                                                <?= ucfirst($item['item_type'] ?? 'inventory') ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($item['location_code'] ?? 'N/A') ?></td>
                                        <td class="text-end">
                                            <span class="<?= ($item['current_stock'] ?? 0) <= 0 ? 'text-danger fw-bold' : '' ?>">
                                                <?= number_format($item['current_stock'] ?? 0, 2) ?>
                                            </span>
                                        </td>
                                        <?php if ($report_type === 'low_stock'): ?>
                                            <td class="text-end"><span class="badge bg-danger"><?= number_format($item['needed_quantity'] ?? 0, 2) ?></span></td>
                                        <?php endif; ?>
                                        <?php if ($report_type === 'summary'): ?>
                                            <td>
                                                <?php
                                                $status = $item['stock_level_status'] ?? 'In Stock';
                                                $cls = $status === 'Out of Stock' ? 'danger' : ($status === 'Low Stock' ? 'warning' : 'success');
                                                ?>
                                                <span class="badge bg-<?= $cls ?>"><?= $status ?></span>
                                            </td>
                                        <?php endif; ?>
                                        <?php if ($report_type === 'value_analysis' || $report_type === 'summary'): ?>
                                            <td class="text-end">$<?= number_format($item['unit_cost'] ?? 0, 2) ?></td>
                                            <td class="text-end fw-bold">$<?= number_format($item['total_value'] ?? 0, 2) ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function clearFilters() {
    window.location.href = 'stock_reports.php';
}
function exportReport() {
    const table = document.getElementById('reportTable');
    if (!table) return;
    const rows = table.querySelectorAll('tr');
    let csv = [];
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        cols.forEach(col => rowData.push('"' + col.innerText.replace(/"/g, '""') + '"'));
        csv.push(rowData.join(','));
    });
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'stock_report_<?= $report_type ?>_' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
}
function printReport() {
    window.print();
}
</script>

<style>
@media print {
    .btn, .card-header .badge, .form-select, form { display: none !important; }
    .card { border: 1px solid #000 !important; box-shadow: none !important; }
}
</style>

<?php include __DIR__ . '/../layout/footer.php'; ?>
