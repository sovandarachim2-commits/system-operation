<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'stock_dashboard.view', 'stock_reports.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();

// Get dashboard statistics
try {
    $lowStockThreshold = 5;

    // Total active products
    $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE active = 1");
    $totalProducts = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(DISTINCT storage_location_id) FROM current_inventory");
    $totalLocations = $stmt->fetchColumn() ?: 0;

    // Stock levels
    $stmt = $pdo->query("SELECT SUM(quantity_on_hand) FROM current_inventory");
    $totalStockQuantity = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->query("SELECT SUM(quantity_on_hand * unit_cost) FROM current_inventory");
    $totalStockValue = $stmt->fetchColumn() ?: 0;

    // Low stock items
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM products p
        LEFT JOIN (
            SELECT item_name, SUM(quantity_on_hand) AS total_qty
            FROM current_inventory
            GROUP BY item_name
        ) ci ON ci.item_name = p.name
        WHERE p.active = 1
          AND COALESCE(ci.total_qty, 0) > 0
          AND COALESCE(ci.total_qty, 0) <= ?
    ");
    $stmt->execute([$lowStockThreshold]);
    $lowStockCount = $stmt->fetchColumn();

    // Out of stock items
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM products p
        LEFT JOIN (
            SELECT item_name, SUM(quantity_on_hand) AS total_qty
            FROM current_inventory
            GROUP BY item_name
        ) ci ON ci.item_name = p.name
        WHERE p.active = 1
          AND COALESCE(ci.total_qty, 0) <= 0
    ");
    $outOfStockCount = $stmt->fetchColumn();

    // Recent movements (last 10)
    $stmt = $pdo->query("
        SELECT sm.*, p.name AS item_name, CONCAT('PROD-', p.id) AS item_code
        FROM stock_movements sm
        JOIN products p ON sm.item_id = p.id
        ORDER BY sm.created_at DESC
        LIMIT 10
    ");
    $recentMovements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stock by product type
    $stmt = $pdo->query("
        SELECT
            COALESCE(NULLIF(p.product_type, ''), 'normal') AS product_type,
            COUNT(DISTINCT p.id) AS item_count,
            SUM(COALESCE(ci.total_qty, 0)) AS total_stock
        FROM products p
        LEFT JOIN (
            SELECT item_name, SUM(quantity_on_hand) AS total_qty
            FROM current_inventory
            GROUP BY item_name
        ) ci ON ci.item_name = p.name
        WHERE p.active = 1
        GROUP BY COALESCE(NULLIF(p.product_type, ''), 'normal')
        ORDER BY item_count DESC
        LIMIT 10
    ");
    $productTypeStock = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top 5 low stock items
    $stmt = $pdo->prepare("
        SELECT
            p.name AS item_name,
            CONCAT('PROD-', p.id) AS item_code,
            COALESCE(ci.total_qty, 0) AS current_stock
        FROM products p
        LEFT JOIN (
            SELECT item_name, SUM(quantity_on_hand) AS total_qty
            FROM current_inventory
            GROUP BY item_name
        ) ci ON ci.item_name = p.name
        WHERE p.active = 1
          AND COALESCE(ci.total_qty, 0) <= ?
        ORDER BY COALESCE(ci.total_qty, 0) ASC, p.name ASC
        LIMIT 5
    ");
    $stmt->execute([$lowStockThreshold]);
    $lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Monthly movement summary (last 6 months)
    $stmt = $pdo->query("
        SELECT
            DATE_FORMAT(created_at, '%Y-%m') as month,
            SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE 0 END) as stock_in,
            SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE 0 END) as stock_out
        FROM stock_movements
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month DESC
    ");
    $monthlyMovements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Current stock by product (for bar chart)
    $stmt = $pdo->query("
        SELECT
            p.name AS item_name,
            CONCAT('PROD-', p.id) AS item_code,
            COALESCE(ci.total_qty, 0) AS current_stock
        FROM products p
        LEFT JOIN (
            SELECT item_name, SUM(quantity_on_hand) AS total_qty
            FROM current_inventory
            GROUP BY item_name
        ) ci ON ci.item_name = p.name
        WHERE p.active = 1
          AND COALESCE(NULLIF(p.product_type, ''), 'normal') = 'normal'
        ORDER BY item_name ASC
    ");
    $productStockLevels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // All product inventory rows (clean grouped table view)
    $stmt = $pdo->query("
        SELECT
            p.name AS item_name,
            COALESCE(NULLIF(p.product_type, ''), 'normal') AS product_type,
            COALESCE(sl.location_code, CONCAT('LOC-', ci.storage_location_id)) AS location_code,
            SUM(COALESCE(ci.quantity_on_hand, 0)) AS closing_stock,
            MAX(COALESCE(ci.unit_cost, 0)) AS unit_cost,
            SUM(COALESCE(ci.quantity_on_hand, 0) * COALESCE(ci.unit_cost, 0)) AS stock_value,
            MAX(ci.last_updated) AS last_updated,
            MAX(b.name) AS brand_name,
            MAX(b.color) AS brand_color
        FROM current_inventory ci
        JOIN products p ON p.name = ci.item_name
        LEFT JOIN storage_locations sl ON sl.id = ci.storage_location_id
        LEFT JOIN brands b ON b.id = p.brand_id
        WHERE p.active = 1
          AND COALESCE(NULLIF(p.product_type, ''), 'normal') = 'normal'
        GROUP BY
            p.id,
            p.name,
            COALESCE(NULLIF(p.product_type, ''), 'normal'),
            COALESCE(sl.location_code, CONCAT('LOC-', ci.storage_location_id))
        HAVING SUM(COALESCE(ci.quantity_on_hand, 0)) > 0
        ORDER BY p.name ASC, location_code ASC
    ");
    $allProductRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group rows by product for merged table display
    $groupedProductRows = [];
    foreach ($allProductRows as $row) {
        $key = (string)($row['item_name'] ?? '');
        if (!isset($groupedProductRows[$key])) {
            $groupedProductRows[$key] = [];
        }
        $groupedProductRows[$key][] = $row;
    }
    $allProductsTotalQty = 0.0;
    $allProductsTotalValue = 0.0;
    foreach ($allProductRows as $row) {
        $allProductsTotalQty += (float)($row['closing_stock'] ?? 0);
        $allProductsTotalValue += (float)($row['stock_value'] ?? 0);
    }

    // Load latest units_per_box settings (shared per product name).
    $unitsPerBoxByItem = [];
    try {
        $settingsRows = $pdo->query("
            SELECT item_name, units_per_box, updated_at
            FROM inventory_box_settings
            ORDER BY updated_at DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($settingsRows as $setting) {
            $itemKey = (string)($setting['item_name'] ?? '');
            if ($itemKey === '' || isset($unitsPerBoxByItem[$itemKey])) {
                continue;
            }
            $unitsPerBoxByItem[$itemKey] = max(0, (int)($setting['units_per_box'] ?? 0));
        }
    } catch (Throwable $e) {
        // If settings table is missing/unavailable, keep dashboard usable.
        $unitsPerBoxByItem = [];
    }

} catch (PDOException $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/../layout/header.php';
?>

<style>
    :root {
        --report-bg: #f4f6f8;
        --report-card: #ffffff;
        --report-border: #e8edf2;
        --report-title: #1f2a37;
        --report-muted: #6b7280;
    }
    .stock-dashboard-page {
        background: var(--report-bg);
    }
    .report-title {
        color: var(--report-title);
        font-weight: 700;
        letter-spacing: 0.01em;
    }
    .report-subtitle {
        color: var(--report-muted);
        font-size: 0.92rem;
    }
    .btn-soft {
        border-radius: 10px;
        font-weight: 600;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
    }
    .stock-dashboard-page .report-card {
        background: var(--report-card);
        border: 1px solid var(--report-border);
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 6px 20px rgba(30, 41, 59, 0.06);
    }
    .stock-dashboard-page .report-card-header {
        background: linear-gradient(180deg, #f9fafb 0%, #f4f6f9 100%);
        border-bottom: 1px solid var(--report-border);
    }
    .stock-dashboard-page .metric-card {
        border: none;
        border-radius: 14px;
        color: #fff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.16);
    }
    .stock-dashboard-page .metric-primary { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }
    .stock-dashboard-page .metric-success { background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); }
    .stock-dashboard-page .metric-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
    .stock-dashboard-page .metric-info { background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%); }
    .stock-dashboard-page .metric-label {
        font-size: 0.9rem;
        opacity: 0.92;
        margin-bottom: 0.35rem;
    }
    .stock-dashboard-page .metric-value {
        margin: 0;
        font-weight: 700;
        letter-spacing: 0.01em;
    }
    .stock-dashboard-page .chart-meta {
        color: #64748b;
        font-size: 0.82rem;
    }
    .stock-dashboard-page .chart-legend-dot {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        margin-right: 6px;
        vertical-align: -1px;
    }
    .stock-dashboard-page .table-clean thead th {
        background: #2f855a;
        color: #f4fff8;
        border-bottom: 0;
        border-color: transparent;
        font-weight: 600;
        white-space: nowrap;
        font-size: 1.05rem;
    }
    .stock-dashboard-page .table-clean tbody td {
        border-color: transparent;
        font-size: 1rem;
        vertical-align: middle;
        color: #000000;
    }
    .stock-dashboard-page .table-clean tbody td.text-end {
        color: #000000 !important;
        font-weight: 600;
    }
    .stock-dashboard-page .table-clean tfoot th,
    .stock-dashboard-page .table-clean tfoot td {
        background: #f3f4f6;
        color: #111827;
        border-top: 0;
        border-color: transparent;
        font-weight: 700;
    }
    .stock-dashboard-page .table-clean tbody tr:hover {
        background: #fdecc8 !important;
    }
    .stock-dashboard-page .table-clean tbody tr.product-group-even {
        background: #ffe4e6;
    }
    .stock-dashboard-page .table-clean tbody tr.product-group-odd {
        background: #e0f2fe;
    }
    .stock-dashboard-page .badge-clean {
        background: #eaf7ef;
        color: #1f7a3e;
        border: 1px solid #c8ead3;
        font-weight: 600;
    }
    .stock-dashboard-page .table-clean tbody tr.seller-group-start td {
        border-top: 3px solid #2f855a !important;
    }
    .stock-dashboard-page .table-clean tbody tr.product-group-end td {
        border-bottom: 3px solid #2f855a !important;
    }
    .stock-dashboard-page .location-qty {
        color: #000000;
        font-size: 0.95rem;
        font-weight: 600;
    }
</style>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4 flex-grow-1 stock-dashboard-page">
        <div class="row">
            <div class="col-12">
                <div class="report-topbar d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                    <div>
                        <h2 class="h4 mb-1 report-title"><i class="bi bi-house-door me-2"></i>Stock Dashboard</h2>
                        <div class="report-subtitle">Clean stock overview, chart, and product inventory list</div>
                    </div>
                    <div class="d-flex gap-2 report-actions">
                        <a href="stock_operations.php" class="btn btn-success btn-soft">
                            <i class="bi bi-arrow-left-right me-1"></i>Add Movement
                        </a>
                    </div>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Database error: <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center metric-card metric-primary">
                            <div class="card-body">
                                <i class="bi bi-box-seam fs-3 mb-2"></i>
                                <div class="metric-label">Total Products</div>
                                <h3 class="metric-value"><?= number_format($totalProducts) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center metric-card metric-success">
                            <div class="card-body">
                                <i class="bi bi-graph-up fs-3 mb-2"></i>
                                <div class="metric-label">Total Stock Qty</div>
                                <h3 class="metric-value"><?= number_format($totalStockQuantity, 2) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center metric-card metric-info">
                            <div class="card-body">
                                <i class="bi bi-cash fs-3 mb-2"></i>
                                <div class="metric-label">Stock Value</div>
                                <h3 class="metric-value">$<?= number_format($totalStockValue, 2) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center metric-card metric-warning">
                            <div class="card-body">
                                <i class="bi bi-geo-alt fs-3 mb-2"></i>
                                <div class="metric-label">Locations</div>
                                <h3 class="metric-value"><?= number_format($totalLocations) ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current Stock Chart -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card report-card">
                            <div class="card-header report-card-header">
                                <h5 class="mb-0"><i class="bi bi-bar-chart-line text-success me-2"></i>Current Stock by Product</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($productStockLevels)): ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-bar-chart-line text-muted fs-1 mb-2"></i>
                                        <p class="text-muted mb-0">No product stock data available yet.</p>
                                    </div>
                                <?php else: ?>
                                    <?php
                                    $allStockLevels = $productStockLevels;
                                    usort($allStockLevels, function($a, $b) {
                                        return (float)$b['current_stock'] <=> (float)$a['current_stock'];
                                    });
                                    $chartLabels = [];
                                    $chartValues = [];
                                    $chartFullLabels = [];
                                    foreach ($allStockLevels as $product) {
                                        $fullLabel = $product['item_name'];
                                        $chartFullLabels[] = $fullLabel;
                                        $chartLabels[] = $fullLabel;
                                        $chartValues[] = (float) $product['current_stock'];
                                    }
                                    $chartHeight = max(420, min(count($chartLabels) * 30, 1600));
                                    ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <small class="chart-meta">Showing items only by current stock</small>
                                        <small class="chart-meta">Total products: <?= count($allStockLevels) ?></small>
                                    </div>
                                    <div class="mb-2 chart-meta">
                                        <span class="chart-legend-dot" style="background:#4e79a7;"></span>Each product has a different color
                                    </div>
                                    <div style="height: <?= (int) $chartHeight ?>px;">
                                        <canvas id="productStockChart"></canvas>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stock by Product Type -->
                <div class="row">
                    <div class="col-12">
                        <div class="card report-card">
                            <div class="card-header report-card-header">
                                <h5 class="mb-0"><i class="bi bi-tags text-info me-2"></i>Stock by Product Type</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($productTypeStock)): ?>
                                    <p class="text-muted text-center py-3">No product type data available</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-clean mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Type</th>
                                                    <th>Products</th>
                                                    <th>Total Stock</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($productTypeStock as $typeRow): ?>
                                                    <tr>
                                                        <td><strong><?= htmlspecialchars(product_type_display_label($typeRow['product_type'] ?? 'normal')) ?></strong></td>
                                                        <td>
                                                            <span class="badge bg-primary">
                                                                <?= number_format($typeRow['item_count']) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-success">
                                                                <?= number_format($typeRow['total_stock'] ?: 0, 2) ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- All Products Table -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card report-card">
                            <div class="card-header report-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h5 class="mb-0"><i class="bi bi-table me-2 text-primary"></i>All Product List</h5>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <small class="chart-meta">Rows: <?= isset($allProductRows) ? count($allProductRows) : 0 ?></small>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printTableById('allProductsTable', 'All Product List')">
                                        <i class="bi bi-printer me-1"></i>Print
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success" onclick="exportTableToCsv('allProductsTable', 'all_product_list')">
                                        <i class="bi bi-file-earmark-excel me-1"></i>Excel
                                    </button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($allProductRows)): ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-inbox text-muted fs-1 mb-2"></i>
                                        <p class="text-muted mb-0">No product inventory rows found.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover mb-0 table-clean" id="allProductsTable">
                                            <thead>
                                                <tr>
                                                    <th>No.</th>
                                                    <th>Item Name</th>
                                                    <th>Brand</th>
                                                    <th>Location</th>
                                                    <th class="text-end">Closing Stock</th>
                                                    <th class="text-end">Unit Cost</th>
                                                    <th class="text-end">Stock Value</th>
                                                    <th>Status</th>
                                                    <th>Last Updated</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $groupIndex = 1; ?>
                                                <?php foreach (($groupedProductRows ?? []) as $itemName => $rows): ?>
                                                    <?php $rowspan = count($rows); ?>
                                                    <?php
                                                    $groupTotalQty = 0.0;
                                                    foreach ($rows as $groupRow) {
                                                        $groupTotalQty += (float)($groupRow['closing_stock'] ?? 0);
                                                    }
                                                    $groupTotalQtyUnits = (int)floor($groupTotalQty);
                                                    $itemUnitsPerBox = (int)($unitsPerBoxByItem[$itemName] ?? 0);
                                                    if ($itemUnitsPerBox > 0) {
                                                        $groupBoxes = (int)floor($groupTotalQtyUnits / $itemUnitsPerBox);
                                                        $groupRemainingUnits = (int)($groupTotalQtyUnits % $itemUnitsPerBox);
                                                    } else {
                                                        $groupBoxes = 0;
                                                        $groupRemainingUnits = $groupTotalQtyUnits;
                                                    }
                                                    ?>
                                                    <?php $groupRowClass = ($groupIndex % 2 === 0) ? 'product-group-even' : 'product-group-odd'; ?>
                                                    <?php foreach ($rows as $rowIndex => $row): ?>
                                                        <tr class="<?= $groupRowClass ?> <?= $rowIndex === 0 ? 'seller-group-start' : 'seller-type-row' ?> <?= $rowIndex === ($rowspan - 1) ? 'product-group-end' : '' ?>">
                                                            <?php if ($rowIndex === 0):
                                                                $brandName  = $rows[0]['brand_name'] ?? '';
                                                                $brandColor = $rows[0]['brand_color'] ?? '';
                                                            ?>
                                                                <td class="text-center fw-bold align-middle" rowspan="<?= $rowspan ?>"><?= $groupIndex ?></td>
                                                                <td class="fw-semibold align-middle" rowspan="<?= $rowspan ?>"><?= htmlspecialchars($itemName) ?></td>
                                                                <td class="align-middle" rowspan="<?= $rowspan ?>">
                                                                    <?php if ($brandName !== ''): ?>
                                                                        <span class="badge rounded-pill" style="background:<?= htmlspecialchars($brandColor) ?>;font-size:0.82rem;padding:4px 12px;font-weight:600;">
                                                                            <?= htmlspecialchars($brandName) ?>
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span class="text-muted">—</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            <?php endif; ?>
                                                            <td>
                                                                <?= htmlspecialchars($row['location_code']) ?>
                                                                <span class="location-qty">(Qty: <?= number_format((float)$row['closing_stock'], 2) ?>)</span>
                                                            </td>
                                                            <?php if ($rowIndex === 0): ?>
                                                                <td class="text-end fw-semibold align-middle" rowspan="<?= $rowspan ?>">
                                                                    <?= number_format($groupTotalQty, 2) ?>
                                                                    <br>
                                                                    <span class="fw-semibold">
                                                                        <?= number_format($groupBoxes) ?> boxs + <?= number_format($groupRemainingUnits) ?> units
                                                                    </span>
                                                                </td>
                                                            <?php endif; ?>
                                                            <td class="text-end">$<?= number_format((float)$row['unit_cost'], 2) ?></td>
                                                            <td class="text-end text-success fw-semibold">$<?= number_format((float)$row['stock_value'], 2) ?></td>
                                                            <td><span class="badge badge-clean">Active</span></td>
                                                            <td><?= !empty($row['last_updated']) ? date('Y-m-d H:i', strtotime($row['last_updated'])) : '-' ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php $groupIndex++; ?>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <th colspan="4" class="text-start">Total</th>
                                                    <th class="text-end"><?= number_format($allProductsTotalQty ?? 0, 2) ?></th>
                                                    <th class="text-end">-</th>
                                                    <th class="text-end">$<?= number_format($allProductsTotalValue ?? 0, 2) ?></th>
                                                    <th colspan="2"></th>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php if (!empty($productStockLevels)): ?>
<script>
    (function () {
        var productStockLabels = <?= json_encode($chartLabels) ?>;
        var productStockFullLabels = <?= json_encode($chartFullLabels) ?>;
        var productStockValues = <?= json_encode($chartValues) ?>;
        var palette = [
            '#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f',
            '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ab',
            '#2d9cdb', '#6fcf97', '#f2994a', '#bb6bd9', '#27ae60'
        ];

        function renderStockChart() {
            var stockChartCtx = document.getElementById('productStockChart');
            if (!stockChartCtx || typeof window.Chart === 'undefined') return;

            var existing = window.Chart.getChart(stockChartCtx);
            if (existing) {
                existing.destroy();
            }

            var barColors = productStockLabels.map(function (_, index) { return palette[index % palette.length]; });
            var barBorderColors = barColors.slice();

            var valueLabelPlugin = {
                id: 'valueLabelPlugin',
                afterDatasetsDraw: function (chart) {
                    var ctx = chart.ctx;
                    var dataset = chart.data.datasets[0];
                    var meta = chart.getDatasetMeta(0);
                    ctx.save();
                    ctx.font = '11px sans-serif';
                    ctx.fillStyle = '#495057';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    meta.data.forEach(function (bar, index) {
                        var value = dataset.data[index];
                        ctx.fillText(Number(value).toLocaleString(), bar.x, bar.y - 4);
                    });
                    ctx.restore();
                }
            };

            new window.Chart(stockChartCtx, {
                type: 'bar',
                plugins: [valueLabelPlugin],
                data: {
                    labels: productStockLabels,
                    datasets: [{
                        label: 'Current Stock',
                        data: productStockValues,
                        backgroundColor: barColors,
                        borderColor: barBorderColors,
                        borderWidth: 1,
                        borderRadius: 6,
                        maxBarThickness: 32
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: { left: 8, right: 16, top: 4, bottom: 4 }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                autoSkip: true,
                                maxTicksLimit: 24,
                                maxRotation: 45,
                                minRotation: 25,
                                font: { size: 10 }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0, 0, 0, 0.06)' },
                            ticks: {
                                callback: function (value) {
                                    return Number(value).toLocaleString();
                                }
                            },
                            title: {
                                display: true,
                                text: 'Current Stock Quantity'
                            }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                title: function (context) {
                                    var idx = context[0].dataIndex;
                                    return productStockFullLabels[idx] || productStockLabels[idx];
                                },
                                label: function (context) {
                                    return 'Stock: ' + Number(context.parsed.y).toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }

        function ensureChartJsAndRender() {
            if (typeof window.Chart !== 'undefined') {
                renderStockChart();
                return;
            }

            var existingScript = document.querySelector('script[data-stock-chartjs="1"]');
            if (existingScript) {
                existingScript.addEventListener('load', renderStockChart, { once: true });
                return;
            }

            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
            script.setAttribute('data-stock-chartjs', '1');
            script.onload = renderStockChart;
            document.body.appendChild(script);
        }

        ensureChartJsAndRender();
    })();
</script>
<?php endif; ?>

<script>
function exportTableToCsv(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;

    // Export Excel-friendly HTML (download as .xls) so it opens like Print:
    // - smaller font
    // - fixed table layout
    // - inline borders/padding
    const styledTableHtml = buildInlineStyledTableHtml(table);
    const excelHtml = `
        <html>
            <head>
                <meta charset="UTF-8" />
                <title>${escapeHtml(filename || 'export')}</title>
            </head>
            <body>${styledTableHtml}</body>
        </html>
    `;

    const blob = new Blob([excelHtml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = (filename || 'export') + '.xls';
    a.click();
    URL.revokeObjectURL(url);
}

function printTableById(tableId, title) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const popup = window.open('', '_blank');
    if (!popup) return;
    popup.document.write(buildStyledTableDocument(table, title || 'Table'));
    popup.document.close();
    popup.focus();
    popup.print();
    popup.close();
}

function buildStyledTableDocument(table, title) {
    const styledTableHtml = buildInlineStyledTableHtml(table);
    const generatedText = new Date().toLocaleString();
    return `
    <html>
    <head>
        <meta charset="UTF-8">
        <title>${escapeHtml(title || 'Table')}</title>
        <style>
            @page { size: A4; margin: 12mm; }
            body { font-family: Arial, sans-serif; padding: 10px; color: #111; font-size: 12px; }
            .report-header { text-align: center; margin-bottom: 14px; }
            h2 { margin: 0; color: #111; font-size: 22px; font-weight: 700; }
            .generated { margin-top: 6px; color: #111; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class="report-header">
            <h2>${escapeHtml(title || 'Table')}</h2>
            <div class="generated">Generated: ${escapeHtml(generatedText)}</div>
        </div>
        ${styledTableHtml}
    </body>
    </html>
    `;
}

function buildInlineStyledTableHtml(table) {
    const clone = table.cloneNode(true);
    clone.style.width = '100%';
    clone.style.borderCollapse = 'collapse';
    clone.style.tableLayout = 'fixed';
    clone.style.fontSize = '12px';
    clone.style.border = '1px solid #000000';
    clone.style.marginBottom = '8px';

    clone.querySelectorAll('thead tr').forEach((tr) => {
        tr.querySelectorAll('th').forEach((th) => {
            th.style.backgroundColor = '#efefef';
            th.style.color = '#111111';
            th.style.fontWeight = '700';
            th.style.border = '1px solid #000000';
            th.style.padding = '7px 8px';
            th.style.whiteSpace = 'nowrap';
        });
    });

    clone.querySelectorAll('tbody tr').forEach((tr) => {
        tr.querySelectorAll('th, td').forEach((cell) => {
            cell.style.border = '1px solid #000000';
            cell.style.padding = '6px 8px';
            cell.style.wordBreak = 'break-word';
            cell.style.backgroundColor = '#ffffff';
        });
    });

    clone.querySelectorAll('tfoot tr').forEach((tr) => {
        tr.querySelectorAll('th, td').forEach((cell) => {
            cell.style.backgroundColor = '#d9f2e4';
            cell.style.fontWeight = '700';
            cell.style.border = '1px solid #000000';
            cell.style.padding = '7px 8px';
        });
    });

    clone.querySelectorAll('.text-end').forEach((cell) => { cell.style.textAlign = 'right'; });
    clone.querySelectorAll('.text-center').forEach((cell) => { cell.style.textAlign = 'center'; });
    return clone.outerHTML;
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
