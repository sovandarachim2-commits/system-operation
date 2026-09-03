<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'offline_stock.view');
require_once __DIR__ . '/offline_lib.php';

$pdo = get_db_connection();
offline_ensure_schema($pdo);

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$brandFilter = trim((string)($_GET['brand'] ?? ''));
$locationFilter = (int)($_GET['location'] ?? 0);
$locations = offline_locations($pdo, true);

$selectedLocation = null;
foreach ($locations as $loc) {
    if ((int)$loc['id'] === $locationFilter) {
        $selectedLocation = $loc;
        break;
    }
}

$where = ['COALESCE(sl.is_offline_location, 0) = 1', 'COALESCE(sl.is_active, 1) = 1'];
$params = [];
if ($locationFilter > 0) {
    $where[] = 'ci.storage_location_id = ?';
    $params[] = $locationFilter;
}
if ($search !== '') {
    $where[] = 'ci.item_name LIKE ?';
    $searchLike = '%' . $search . '%';
    $params[] = $searchLike;
}

$stmt = $pdo->prepare("
    SELECT
        MIN(ci.id) AS inventory_id,
        ci.item_name,
        ci.storage_location_id,
        sl.location_code,
        sl.location_name,
        COALESCE(NULLIF(b.name, ''), 'No Brand') AS brand_name,
        COALESCE(NULLIF(b.color, ''), '#6b7280') AS brand_color,
        COALESCE(NULLIF(p.unit, ''), NULLIF(si.unit, ''), 'Pcs') AS unit,
        MAX(COALESCE(ibs.units_per_box, 0)) AS units_per_box,
        SUM(COALESCE(ci.quantity_on_hand, 0)) AS quantity_on_hand,
        SUM(COALESCE(ci.available_quantity, ci.quantity_on_hand, 0)) AS available_quantity,
        MAX(COALESCE(NULLIF(ci.unit_cost, 0), NULLIF(p.cost, 0), NULLIF(si.unit_cost, 0), 0)) AS unit_cost,
        MAX(COALESCE(NULLIF(p.reorder_point, 0), NULLIF(p.min_stock, 0), NULLIF(si.minimum_stock, 0), 5)) AS low_threshold,
        MAX(ci.last_updated) AS last_updated
    FROM current_inventory ci
    JOIN storage_locations sl ON sl.id = ci.storage_location_id
    LEFT JOIN products p ON p.name COLLATE utf8mb4_unicode_ci = ci.item_name COLLATE utf8mb4_unicode_ci OR (ci.sku IS NOT NULL AND ci.sku <> '' AND p.sku COLLATE utf8mb4_unicode_ci = ci.sku COLLATE utf8mb4_unicode_ci)
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN stock_items si ON si.item_name COLLATE utf8mb4_unicode_ci = ci.item_name COLLATE utf8mb4_unicode_ci OR (ci.sku IS NOT NULL AND ci.sku <> '' AND si.item_code COLLATE utf8mb4_unicode_ci = ci.sku COLLATE utf8mb4_unicode_ci)
    LEFT JOIN (
        SELECT s1.*
        FROM inventory_box_settings s1
        INNER JOIN (
            SELECT item_name, MAX(id) AS max_id
            FROM inventory_box_settings
            GROUP BY item_name
        ) latest ON latest.max_id = s1.id
    ) ibs ON ibs.item_name COLLATE utf8mb4_unicode_ci = ci.item_name COLLATE utf8mb4_unicode_ci
    WHERE " . implode(' AND ', $where) . "
    GROUP BY ci.item_name, ci.storage_location_id, sl.location_code, sl.location_name, b.name, b.color, p.unit, si.unit
    ORDER BY sl.location_code, ci.item_name
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $qty = (float)($row['quantity_on_hand'] ?? 0);
    $threshold = max(1.0, (float)($row['low_threshold'] ?? 5));
    if ($qty <= 0) {
        $row['stock_status'] = 'out';
        $row['stock_status_label'] = 'Out';
        $row['stock_status_class'] = 'danger';
    } elseif ($qty <= min(5.0, $threshold)) {
        $row['stock_status'] = 'critical';
        $row['stock_status_label'] = 'Critical';
        $row['stock_status_class'] = 'danger';
    } elseif ($qty <= $threshold) {
        $row['stock_status'] = 'low';
        $row['stock_status_label'] = 'Low Stock';
        $row['stock_status_class'] = 'warning';
    } else {
        $row['stock_status'] = 'in';
        $row['stock_status_label'] = 'In Stock';
        $row['stock_status_class'] = 'success';
    }
}
unset($row);

$brandOptions = array_values(array_unique(array_map(static fn($r) => (string)($r['brand_name'] ?? 'No Brand'), $rows)));
sort($brandOptions);

if ($statusFilter !== '') {
    $rows = array_values(array_filter($rows, static fn($row) => ($row['stock_status'] ?? '') === $statusFilter));
}
if ($brandFilter !== '') {
    $rows = array_values(array_filter($rows, static fn($row) => ($row['brand_name'] ?? 'No Brand') === $brandFilter));
}

$totalProducts = count($rows);
$totalQty = array_sum(array_map(static fn($r) => (float)($r['quantity_on_hand'] ?? 0), $rows));
$totalLocations = count($locations);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="offline_stock.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Product', 'Brand', 'Location', 'Units per Box', 'Box', 'Available Stock', 'Total Stock', 'Unit Cost', 'Status', 'Updated']);
    foreach ($rows as $idx => $row) {
        fputcsv($out, [
            $idx + 1,
            $row['item_name'] ?? '',
            $row['brand_name'] ?? '',
            trim(($row['location_code'] ?? '') . ' - ' . ($row['location_name'] ?? '')),
            (int)($row['units_per_box'] ?? 0),
            (int)($row['units_per_box'] ?? 0) > 0
                ? ((int)floor((float)($row['quantity_on_hand'] ?? 0) / (int)$row['units_per_box']) . ' boxs + ' . (int)fmod((float)($row['quantity_on_hand'] ?? 0), (int)$row['units_per_box']) . ' units')
                : ('0 boxs + ' . (int)floor((float)($row['quantity_on_hand'] ?? 0)) . ' units'),
            (float)($row['available_quantity'] ?? $row['quantity_on_hand'] ?? 0),
            (float)($row['quantity_on_hand'] ?? 0),
            (float)($row['unit_cost'] ?? 0),
            $row['stock_status_label'] ?? '',
            $row['last_updated'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

include __DIR__ . '/../layout/header.php';
?>
<style>
.offline-stock-page {
    --soft-border: #e8edf5;
    --soft-text: #667085;
    --ink: #111827;
}
.offline-stock-page .metric-card,
.offline-stock-page .inventory-panel,
.offline-stock-page .stock-note {
    border: 1px solid var(--soft-border);
    border-radius: 8px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
}
.offline-stock-page .metric-card {
    min-height: 116px;
}
.offline-stock-page .metric-icon {
    width: 66px;
    height: 66px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    font-size: 2rem;
}
.offline-stock-page .metric-label,
.offline-stock-page .table-muted {
    color: var(--soft-text);
}
.offline-stock-page .metric-value {
    color: var(--ink);
    font-size: 1.85rem;
    line-height: 1.1;
}
.offline-stock-page .filter-control {
    min-height: 46px;
    border-color: #dbe3ef;
}
.offline-stock-page .table thead th {
    color: #475467;
    font-weight: 700;
    font-size: .84rem;
    border-bottom: 1px solid var(--soft-border);
    background: #fbfcfe;
    white-space: nowrap;
}
.offline-stock-page .table tbody td {
    border-color: var(--soft-border);
    padding-top: 1rem;
    padding-bottom: 1rem;
    vertical-align: middle;
}
.offline-stock-page .product-avatar {
    width: 46px;
    height: 46px;
    border-radius: 8px;
    background: linear-gradient(135deg, #edf2ff, #f8fafc);
    color: #3b5bdb;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
}
.offline-stock-page .status-pill {
    border-radius: 8px;
    padding: .45rem .7rem;
    font-weight: 700;
    font-size: .82rem;
}
.offline-stock-page .status-success { background: #dcfce7; color: #16a34a; }
.offline-stock-page .status-warning { background: #ffedd5; color: #f97316; }
.offline-stock-page .status-danger { background: #fee2e2; color: #ef4444; }
.offline-stock-page .qty-success { color: #16a34a; }
.offline-stock-page .qty-warning { color: #f97316; }
.offline-stock-page .qty-danger { color: #ef4444; }
.offline-stock-page .brand-badge {
    --brand-color: #6b7280;
    border: 1px solid color-mix(in srgb, var(--brand-color) 30%, transparent);
    background: color-mix(in srgb, var(--brand-color) 12%, white);
    color: var(--brand-color);
    border-radius: 8px;
    font-weight: 700;
    padding: .35rem .55rem;
}
.offline-stock-page .box-breakdown {
    white-space: nowrap;
}
.offline-stock-page .stock-note-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
}
@media (max-width: 767.98px) {
    .offline-stock-page .metric-card {
        min-height: auto;
    }
    .offline-stock-page .filter-actions {
        width: 100%;
    }
}
</style>
<div class="container-fluid py-4 offline-stock-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1"><i class="bi bi-shop me-2"></i>Stock Offline</h1>
            <div class="text-muted small">
                <?php if ($selectedLocation): ?>
                    <?= htmlspecialchars($selectedLocation['location_code'] . ' - ' . $selectedLocation['location_name']) ?>
                <?php elseif ($locations): ?>
                    All Offline Locations
                <?php else: ?>
                    No offline location set
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$locations): ?>
        <div class="alert alert-warning">No offline location found. Open <strong>Storage Locations</strong> and assign a team to mark a location as offline.</div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card metric-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="metric-icon" style="background:#ede9fe;color:#5b5cf6;"><i class="bi bi-layers"></i></div>
                    <div><div class="metric-label fw-semibold">Total Products</div><div class="metric-value fw-bold"><?= number_format($totalProducts) ?></div><div class="metric-label">Products</div></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card metric-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="metric-icon" style="background:#dcfce7;color:#16a34a;"><i class="bi bi-box-seam"></i></div>
                    <div><div class="metric-label fw-semibold">Total Stock Quantity</div><div class="metric-value fw-bold"><?= number_format($totalQty, 2) ?></div><div class="metric-label">Total Units</div></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card metric-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="metric-icon" style="background:#e0f2fe;color:#0284c7;"><i class="bi bi-shop"></i></div>
                    <div><div class="metric-label fw-semibold">Total Locations</div><div class="metric-value fw-bold"><?= number_format($totalLocations) ?></div><div class="metric-label">Offline Locations</div></div>
                </div>
            </div>
        </div>
    </div>

    <div class="inventory-panel bg-white mb-3">
        <div class="p-3 border-bottom" style="border-color: var(--soft-border) !important;">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-12 col-lg-4">
                    <label class="visually-hidden" for="offlineStockSearch">Search</label>
                    <div class="input-group">
                        <input id="offlineStockSearch" class="form-control filter-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search product...">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    </div>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="visually-hidden" for="offlineLocation">Location</label>
                    <select id="offlineLocation" name="location" class="form-select filter-control">
                        <option value="0">All Locations</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= (int)$loc['id'] ?>" <?= $locationFilter === (int)$loc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($loc['location_code'] . ' - ' . $loc['location_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="visually-hidden" for="offlineBrand">Brand</label>
                    <select id="offlineBrand" name="brand" class="form-select filter-control">
                        <option value="">All Brands</option>
                        <?php foreach ($brandOptions as $bn): ?>
                            <option value="<?= htmlspecialchars($bn) ?>" <?= $brandFilter === $bn ? 'selected' : '' ?>>
                                <?= htmlspecialchars($bn) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="visually-hidden" for="offlineStatus">Stock Status</label>
                    <select id="offlineStatus" name="status" class="form-select filter-control">
                        <option value="">Stock Status</option>
                        <option value="in" <?= $statusFilter === 'in' ? 'selected' : '' ?>>In Stock</option>
                        <option value="low" <?= $statusFilter === 'low' ? 'selected' : '' ?>>Low Stock</option>
                        <option value="critical" <?= $statusFilter === 'critical' ? 'selected' : '' ?>>Critical</option>
                        <option value="out" <?= $statusFilter === 'out' ? 'selected' : '' ?>>Out</option>
                    </select>
                </div>
                <div class="col-6 col-md-2 col-lg-1">
                    <button class="btn btn-primary filter-control w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                </div>
                <div class="col-6 col-md-2 col-lg-1">
                    <a class="btn btn-outline-secondary filter-control w-100 d-inline-flex align-items-center justify-content-center" href="?<?= htmlspecialchars(http_build_query(array_filter(['search' => $search, 'brand' => $brandFilter ?: null, 'status' => $statusFilter, 'location' => $locationFilter ?: null, 'export' => 'csv']))) ?>" title="Export CSV">
                        <i class="bi bi-download"></i>
                    </a>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:48px;">#</th>
                        <th>Product</th>
                        <th>Brand</th>
                        <th>Location</th>
                        <th>Units per Box</th>
                        <th>Box</th>
                        <th class="text-end">Available Stock</th>
                        <th class="text-end">Total Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $index => $row): ?>
                        <?php
                            $qty = (float)($row['quantity_on_hand'] ?? 0);
                            $available = (float)($row['available_quantity'] ?? $qty);
                            $statusClass = (string)($row['stock_status_class'] ?? 'success');
                            $qtyClass = $statusClass === 'danger' ? 'qty-danger' : ($statusClass === 'warning' ? 'qty-warning' : 'qty-success');
                            $initial = strtoupper(substr((string)($row['item_name'] ?? 'P'), 0, 1));
                            $unitsPerBox = (int)($row['units_per_box'] ?? 0);
                            $fullBoxes = $unitsPerBox > 0 ? (int)floor($qty / $unitsPerBox) : 0;
                            $remainingUnits = $unitsPerBox > 0 ? (int)fmod($qty, $unitsPerBox) : (int)floor($qty);
                        ?>
                        <tr>
                            <td class="fw-semibold text-muted"><?= $index + 1 ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="product-avatar"><?= htmlspecialchars($initial) ?></div>
                                    <div>
                                        <div class="fw-bold"><?= htmlspecialchars($row['item_name'] ?? '') ?></div>
                                        <div class="table-muted small">Offline stock item</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (($row['brand_name'] ?? 'No Brand') !== 'No Brand'): ?>
                                    <span class="brand-badge" style="--brand-color: <?= htmlspecialchars($row['brand_color'] ?? '#6b7280') ?>"><?= htmlspecialchars($row['brand_name']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">No Brand</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['location_name'] ?? '') ?></td>
                            <td>
                                <span class="fw-semibold"><?= number_format((int)($row['units_per_box'] ?? 0)) ?></span>
                                <small class="text-muted">units</small>
                            </td>
                            <td class="box-breakdown">
                                <span class="fw-semibold"><?= number_format($fullBoxes) ?></span>
                                <small class="text-muted">boxs</small>
                                <span class="text-muted"> + </span>
                                <span class="fw-semibold"><?= number_format($remainingUnits) ?></span>
                                <small class="text-muted">units</small>
                            </td>
                            <td class="text-end fw-bold <?= $qtyClass ?>"><?= number_format($available, 2) ?></td>
                            <td class="text-end fw-bold"><?= number_format($qty, 2) ?></td>
                            <td><span class="status-pill status-<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($row['stock_status_label'] ?? 'In Stock') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="9" class="text-center text-muted py-5">No offline stock found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="stock-note bg-white p-3">
        <div class="fw-bold mb-3">Stock Calculation</div>
        <div class="row g-3">
            <div class="col-12 col-md-4"><span class="stock-note-dot me-2" style="background:#16a34a;"></span><span class="fw-semibold text-success">Available Stock</span><div class="table-muted ps-4">Stock available for offline sale.</div></div>
            <div class="col-12 col-md-4"><span class="stock-note-dot me-2" style="background:#7c3aed;"></span><span class="fw-semibold" style="color:#7c3aed;">Total Stock</span><div class="table-muted ps-4">Total quantity in offline default location.</div></div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../layout/footer.php'; ?>
