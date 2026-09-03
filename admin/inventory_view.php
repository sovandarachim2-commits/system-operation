<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'inventory_view.view');
require_once __DIR__ . '/../includes/product_set_location.php';

$pdo = get_db_connection();
product_set_ensure_schema($pdo);

$errors = [];
$success = '';

// Store "1 box = ? units" in database (per item + sku + location).
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_box_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(255) NOT NULL,
            sku VARCHAR(255) NULL,
            location_code VARCHAR(100) NULL,
            units_per_box INT NOT NULL DEFAULT 0,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_item_sku_loc (item_name, sku, location_code)
        )
    ");
} catch (PDOException $e) {
    $errors[] = 'Could not prepare box settings storage.';
}

// AJAX save endpoint for popup "Save" action.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'save_box_settings')) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $itemName = trim((string)($_POST['item_name'] ?? ''));
        $sku = trim((string)($_POST['sku'] ?? ''));
        $locationCode = trim((string)($_POST['location_code'] ?? ''));
        $unitsPerBox = (int)($_POST['units_per_box'] ?? 0);
        $unitsPerBox = max(1, $unitsPerBox);
        $updatedBy = (int)($user['id'] ?? 0);

        if ($itemName === '') {
            throw new RuntimeException('Invalid item name.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO inventory_box_settings (item_name, sku, location_code, units_per_box, updated_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                units_per_box = VALUES(units_per_box),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $itemName,
            $sku !== '' ? $sku : null,
            $locationCode !== '' ? $locationCode : null,
            $unitsPerBox,
            $updatedBy > 0 ? $updatedBy : null
        ]);

        echo json_encode([
            'success' => true,
            'units_per_box' => $unitsPerBox
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Get filters
$location_filter = (int)($_GET['location_filter'] ?? 0);
$product_filter = trim($_GET['product_filter'] ?? '');
$product_type_filter = $_GET['product_type_filter'] ?? 'all'; // 'all', 'inventory', 'set'
$search = trim($_GET['search'] ?? '');

// Get storage locations
try {
    $locationsStmt = $pdo->query('SELECT * FROM storage_locations WHERE is_active = 1 ORDER BY location_code');
    $locations = $locationsStmt->fetchAll();
} catch (PDOException $e) {
    $locations = [];
    $errors[] = 'Storage locations not available.';
}

try {
    $productsStmt = $pdo->query("
        SELECT DISTINCT item_name
        FROM (
            SELECT ci.item_name
            FROM current_inventory ci
            UNION
            SELECT ps.set_name as item_name
            FROM product_sets ps
            WHERE ps.is_active = 1
        ) product_list
        WHERE item_name IS NOT NULL AND item_name <> ''
        ORDER BY item_name
    ");
    $products = $productsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $products = [];
    $errors[] = 'Product list not available.';
}

// Build query for current inventory and product sets
$params = [];
$sql = '
    SELECT * FROM (
        SELECT
            ci.id,
            ci.storage_location_id,
            ci.item_name,
            ci.sku,
            ci.quantity_on_hand as available_quantity,
            COALESCE(NULLIF(pc.selling_price, 0), NULLIF(p.cost, 0), NULLIF(pc.total_cost, 0), NULLIF(pc.original_cost, 0), NULLIF(ci.unit_cost, 0), 0) as unit_cost,
            (ci.quantity_on_hand * COALESCE(NULLIF(pc.selling_price, 0), NULLIF(p.cost, 0), NULLIF(pc.total_cost, 0), NULLIF(pc.original_cost, 0), NULLIF(ci.unit_cost, 0), 0)) as total_value,
            ci.last_updated,
            b.name as brand_name,
            b.color as brand_color,
            sl.location_code,
            sl.location_name,
            sl.location_type,
            COALESCE(sl.is_default, 0) as is_default_location,
            COALESCE(sl.is_offline_location, 0) as is_offline_location,
            \'inventory\' as item_type,
            NULL as total_created,
            NULL as set_cost,
            0 as units_per_box
        FROM current_inventory ci
        LEFT JOIN storage_locations sl ON ci.storage_location_id = sl.id
        LEFT JOIN product_sets ps ON ci.item_name = ps.set_name
        LEFT JOIN products p ON p.name = ci.item_name
        LEFT JOIN brands b ON b.id = p.brand_id
        LEFT JOIN product_costs pc ON pc.product_id = p.id AND pc.month_year = DATE_FORMAT(CURDATE(), \'%Y-%m\')
        WHERE ps.id IS NULL

        UNION ALL

        SELECT
            ps.id,
            COALESCE(ps.storage_location_id, 0) as storage_location_id,
            ps.set_name as item_name,
            CONCAT(\'SET-\', ps.id) as sku,
            ps.available_stock as available_quantity,
            COALESCE(NULLIF(pc.selling_price, 0), NULLIF(p.cost, 0), NULLIF(ps.selling_price, 0), NULLIF(ps.total_cost, 0), NULLIF(pc.total_cost, 0), NULLIF(pc.original_cost, 0), 0) as unit_cost,
            (ps.available_stock * COALESCE(NULLIF(pc.selling_price, 0), NULLIF(p.cost, 0), NULLIF(ps.selling_price, 0), NULLIF(ps.total_cost, 0), NULLIF(pc.total_cost, 0), NULLIF(pc.original_cost, 0), 0)) as total_value,
            ps.created_at as last_updated,
            b.name as brand_name,
            b.color as brand_color,
            sl.location_code,
            sl.location_name,
            \'sets\' as location_type,
            COALESCE(sl.is_default, 0) as is_default_location,
            COALESCE(sl.is_offline_location, 0) as is_offline_location,
            \'set\' as item_type,
            ps.total_created,
            ps.total_cost as set_cost,
            0 as units_per_box
        FROM product_sets ps
        LEFT JOIN storage_locations sl ON sl.id = ps.storage_location_id
        LEFT JOIN products p ON p.name = ps.set_name AND COALESCE(p.product_type, \'General\') = \'set\'
        LEFT JOIN brands b ON b.id = p.brand_id
        LEFT JOIN product_costs pc ON pc.product_id = p.id AND pc.month_year = DATE_FORMAT(CURDATE(), \'%Y-%m\')
        WHERE ps.is_active = 1
    ) combined_inventory
    WHERE 1=1
';

if ($product_type_filter !== 'all') {
    if ($product_type_filter === 'inventory') {
        $sql .= ' AND item_type = ?';
        $params[] = 'inventory';
    } elseif ($product_type_filter === 'set') {
        $sql .= ' AND item_type = ?';
        $params[] = 'set';
    }
}

if ($location_filter > 0) {
    $sql .= ' AND storage_location_id = ?';
    $params[] = $location_filter;
}

if ($product_filter !== '') {
    $sql .= ' AND item_name = ?';
    $params[] = $product_filter;
}

if ($search !== '') {
    $sql .= ' AND (item_name LIKE ? OR sku LIKE ?)';
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$sql .= ' ORDER BY location_code, item_name';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $inventory_raw = $stmt->fetchAll();
    
    // Group same product + same location and sum quantities
    $inventory = [];
    foreach ($inventory_raw as $item) {
        $key = ($item['item_name'] ?? '') . '|' . ($item['sku'] ?? '') . '|' . (string)($item['storage_location_id'] ?? 0) . '|' . ($item['location_code'] ?? '');
        
        if (!isset($inventory[$key])) {
            $inventory[$key] = $item;
            $inventory[$key]['available_quantity'] = 0;
            $inventory[$key]['total_value'] = 0;
        }
        
        $inventory[$key]['available_quantity'] += $item['available_quantity'];
        $inventory[$key]['total_value'] += $item['total_value'];
    }
    
    // Convert back to simple array
    $inventory = array_values($inventory);

    // Apply saved database settings for units per box (shared by same product across locations).
    $boxSettingsMap = [];
    try {
        $settingsRows = $pdo->query('
            SELECT item_name, sku, location_code, units_per_box, updated_at
            FROM inventory_box_settings
            ORDER BY updated_at DESC, id DESC
        ')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($settingsRows as $setting) {
            // Product-level key: same item + sku should reuse same units_per_box on all locations.
            $mapKey = (string)($setting['item_name'] ?? '') . '|' . (string)($setting['sku'] ?? '');
            if (!isset($boxSettingsMap[$mapKey])) {
                $boxSettingsMap[$mapKey] = (int)($setting['units_per_box'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // Keep page usable even if settings table has issues.
    }

    foreach ($inventory as &$invRow) {
        $mapKey = (string)($invRow['item_name'] ?? '') . '|' . (string)($invRow['sku'] ?? '');
        if (($invRow['item_type'] ?? '') === 'inventory' && isset($boxSettingsMap[$mapKey])) {
            $invRow['units_per_box'] = max(0, (int)$boxSettingsMap[$mapKey]);
        }
    }
    unset($invRow);
    
} catch (PDOException $e) {
    $inventory = [];
    $errors[] = 'Error loading inventory: ' . htmlspecialchars($e->getMessage());
}

// Calculate summary statistics
$total_items = count($inventory);
$total_quantity = array_sum(array_column($inventory, 'available_quantity'));
$total_value = array_sum(array_column($inventory, 'total_value'));
$locations_summary = [];

foreach ($inventory as $item) {
    $locKey = (string)($item['storage_location_id'] ?? 0) . '|' . ($item['location_code'] ?? 'Unknown');
    if (!isset($locations_summary[$locKey])) {
        $locations_summary[$locKey] = [
            'name' => $item['location_name'] ?? 'Unknown',
            'code' => $item['location_code'] ?? 'Unknown',
            'is_default' => !empty($item['is_default_location']),
            'is_offline' => !empty($item['is_offline_location']),
            'items' => 0,
            'quantity' => 0,
            'value' => 0
        ];
    }
    $locations_summary[$locKey]['is_default'] = $locations_summary[$locKey]['is_default'] || !empty($item['is_default_location']);
    $locations_summary[$locKey]['is_offline'] = $locations_summary[$locKey]['is_offline'] || !empty($item['is_offline_location']);
    $locations_summary[$locKey]['items']++;
    $locations_summary[$locKey]['quantity'] += $item['available_quantity'];
    $locations_summary[$locKey]['value'] += $item['total_value'];
}

$total_inventory_items = count($inventory);
$paged_inventory = $inventory;

$SHOW_BOX_PRICE_CALCULATOR_BUTTON = false;

include __DIR__ . '/../layout/header.php';
?>
<div class="inventory-dashboard d-flex flex-column h-100">
    <div class="inventory-page-head d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h3 mb-0">Current Inventory</h1>
        <div class="d-flex flex-wrap gap-2">
            <a href="storage_receipts.php" class="btn inventory-action-btn btn-outline-primary btn-lg">
                <i class="bi bi-archive me-2"></i>Storage Receipts
            </a>
            <button class="btn inventory-action-btn btn-outline-success btn-lg" onclick="window.print()">
                <i class="bi bi-printer-fill me-2"></i>Print Report
            </button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-4">
            <div class="inventory-stat-card stat-blue">
                <div class="stat-icon"><i class="bi bi-box-seam"></i></div>
                <div>
                    <h5>Total Items</h5>
                    <h3><?= number_format($total_items) ?></h3>
                    <small>Different products</small>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="inventory-stat-card stat-green">
                <div class="stat-icon"><i class="bi bi-box"></i></div>
                <div>
                    <h5>Total Quantity</h5>
                    <h3><?= number_format($total_quantity) ?></h3>
                    <small>Units in stock</small>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="inventory-stat-card stat-orange">
                <div class="stat-icon"><i class="bi bi-geo-alt-fill"></i></div>
                <div>
                    <h5>Locations</h5>
                    <h3><?= count($locations_summary) ?></h3>
                    <small>Storage areas</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="inventory-panel inventory-filter-panel mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-12 col-md-2">
                <label class="form-label">Product</label>
                <select name="product_filter" class="form-select form-select-lg">
                    <option value="">All Products</option>
                    <?php foreach ($products as $productName): ?>
                        <option value="<?= htmlspecialchars($productName) ?>" <?= $product_filter === $productName ? 'selected' : '' ?>>
                            <?= htmlspecialchars($productName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Product Type</label>
                <select name="product_type_filter" class="form-select form-select-lg">
                    <option value="all" <?= $product_type_filter === 'all' ? 'selected' : '' ?>>All Products</option>
                        <option value="inventory" <?= $product_type_filter === 'inventory' ? 'selected' : '' ?>>Items</option>
                    <option value="set" <?= $product_type_filter === 'set' ? 'selected' : '' ?>>Product Sets</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Storage Location</label>
                <select name="location_filter" class="form-select form-select-lg">
                    <option value="">All Locations</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int)$location['id'] ?>" <?= $location_filter === (int)$location['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($location['location_code']) ?> - <?= htmlspecialchars($location['location_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn inventory-filter-btn btn-outline-primary btn-lg w-100">
                    <i class="bi bi-funnel-fill me-2"></i>Filter
                </button>
                <a href="inventory_view.php" class="btn inventory-reset-btn btn-outline-secondary btn-lg w-100">
                    <i class="bi bi-arrow-clockwise me-2"></i>Reset
                </a>
            </div>
        </div>
    </form>

    <!-- Location Summary -->
    <div class="inventory-panel mb-4">
        <div class="inventory-panel-header">
            <h5 class="mb-0">Inventory by Location</h5>
        </div>
        <div class="card-body">
            <div class="inventory-location-list">
                <?php foreach ($locations_summary as $locKey => $summary): ?>
                    <div class="inventory-location-row">
                        <div class="location-main">
                            <div class="location-icon"><i class="bi bi-geo-alt-fill"></i></div>
                            <div>
                                <h6><?= htmlspecialchars($summary['code'] ?? 'Unknown') ?></h6>
                                <div class="text-muted small">
                                    <?= htmlspecialchars($summary['name']) ?>
                                    <?php if (!empty($summary['is_default'])): ?>
                                        <span class="badge bg-success-subtle text-success ms-2">Default Location</span>
                                    <?php endif; ?>
                                    <?php if (!empty($summary['is_offline'])): ?>
                                        <span class="badge bg-dark ms-2"><i class="bi bi-shop me-1"></i>Offline Default</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="location-metric">
                            <span>Items</span>
                            <strong><?= (int)$summary['items'] ?></strong>
                        </div>
                        <div class="location-metric">
                            <span>Quantity</span>
                            <strong><?= number_format($summary['quantity']) ?></strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Detailed Inventory Table -->
    <div class="inventory-panel">
        <div class="inventory-panel-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="mb-0">Detailed Inventory (<?= count($inventory) ?> items)</h5>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportInventoryTableCsv()">
                <i class="bi bi-download me-2"></i>Export
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
            <table class="table table-striped table-bordered inventory-table align-middle">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Type</th>
                        <th>Location</th>
                        <th>Item</th>
                        <th>Brand</th>
                        <th>SKU</th>
                        <th>Available Quantity</th>
                        <th class="col-product-box">Product in Box</th>
                        <th class="col-box-breakdown">Box Breakdown</th>
                        <th class="col-units-box">Units per Box</th>
                        <th>Last Updated</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($paged_inventory)): ?>
                        <tr><td colspan="12" class="text-center py-3">No inventory found</td></tr>
                    <?php else: ?>
                        <?php foreach ($paged_inventory as $rowIndex => $item): ?>
                            <?php
                                $units_per_box = (int)($item['units_per_box'] ?? 0);
                                $available_qty = (float)($item['available_quantity'] ?? 0);
                                $boxes_in_stock = $units_per_box > 0
                                    ? ($available_qty / $units_per_box)
                                    : null;
                                $boxes_in_stock_int = $boxes_in_stock !== null ? (int)floor($boxes_in_stock) : 0;
                                $full_boxes = $units_per_box > 0 ? (int)floor($available_qty / $units_per_box) : 0;
                                $remaining_units = $units_per_box > 0 ? (int)fmod($available_qty, $units_per_box) : (int)floor($available_qty);
                                $row_key = 'row_' . md5((string)($item['id'] ?? '') . '|' . (string)($item['sku'] ?? '') . '|' . (string)($item['location_code'] ?? ''));
                            ?>
                            <tr>
                                <td class="text-muted fw-semibold"><?= $rowIndex + 1 ?></td>
                                <td>
                                    <?php if ($item['item_type'] === 'set'): ?>
                                        <span class="badge bg-info">📦 Set</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary">📦 Item</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <?php if ($item['location_code'] === 'DEFAULT'): ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="bi bi-house-door"></i> Default
                                            </span>
                                            <small class="text-muted fw-semibold">
                                                <?= htmlspecialchars($item['location_name'] ?? '') ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">
                                                <?= htmlspecialchars($item['location_code'] ?? 'N/A') ?>
                                            </span>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($item['location_name'] ?? '') ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($item['item_name']) ?></strong>
                                    <?php if ($item['item_type'] === 'set' && $item['total_created']): ?>
                                        <br><small class="text-muted">Total: <?= number_format($item['total_created']) ?> created</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($item['brand_name'])): ?>
                                        <span class="badge inventory-brand-badge" style="--brand-color: <?= htmlspecialchars($item['brand_color'] ?: '#6b7280') ?>">
                                            <?= htmlspecialchars($item['brand_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($item['sku'] ?? '') ?></code></td>
                                <td class="text-end" id="<?= $row_key ?>_available" data-available-qty="<?= (float)$item['available_quantity'] ?>">
                                    <span class="badge bg-<?= $item['available_quantity'] > 0 ? 'success' : 'danger' ?>">
                                        <?= number_format($item['available_quantity']) ?>
                                        <?php if ($item['item_type'] === 'set'): ?> sets<?php endif; ?>
                                    </span>
                                </td>
                                <td class="text-end col-product-box">
                                    <?php if ($item['item_type'] === 'inventory'): ?>
                                        <span class="fw-semibold" id="<?= $row_key ?>_box"><?= number_format($boxes_in_stock_int) ?></span>
                                        <small class="text-muted" id="<?= $row_key ?>_box_suffix">boxs</small>
                                    <?php else: ?>
                                        <span class="text-muted" id="<?= $row_key ?>_box">-</span>
                                        <small class="text-muted" id="<?= $row_key ?>_box_suffix"> </small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end col-box-breakdown">
                                    <?php if ($item['item_type'] === 'inventory'): ?>
                                        <span id="<?= $row_key ?>_breakdown">
                                            <span class="fw-semibold"><?= number_format($full_boxes) ?></span>
                                            <small class="text-muted">boxs</small>
                                            <span class="text-muted"> + </span>
                                            <span class="fw-semibold"><?= number_format($remaining_units) ?></span>
                                            <small class="text-muted">units</small>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted" id="<?= $row_key ?>_breakdown">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end col-units-box">
                                    <?php if ($item['item_type'] === 'inventory'): ?>
                                        <span id="<?= $row_key ?>_units"><?= number_format($units_per_box) ?></span> <small class="text-muted" id="<?= $row_key ?>_units_suffix">units</small>
                                    <?php else: ?>
                                        <span class="text-muted" id="<?= $row_key ?>_units">-</span> <small class="text-muted" id="<?= $row_key ?>_units_suffix"> </small>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= date('M j, Y', strtotime($item['last_updated'])) ?></small></td>
                                <td class="col-actions">
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-info" onclick="viewItemDetails('<?= htmlspecialchars($item['item_name']) ?>', '<?= $item['item_type'] ?>', '<?= htmlspecialchars($item['sku']) ?>', '<?= $item['available_quantity'] ?>', '<?= htmlspecialchars($item['location_name']) ?>', '<?= htmlspecialchars($item['location_code']) ?>', '<?= $item['id'] ?>', '<?= $units_per_box ?>', '<?= $boxes_in_stock !== null ? number_format($boxes_in_stock, 2, '.', '') : '' ?>')">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <?php if ($item['item_type'] === 'inventory' && $SHOW_BOX_PRICE_CALCULATOR_BUTTON): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                title="Box Price Calculator"
                                                onclick="openBoxPricePopup(
                                                    '<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>',
                                                    '<?= htmlspecialchars((string)($item['sku'] ?? ''), ENT_QUOTES) ?>',
                                                    '<?= htmlspecialchars((string)($item['location_code'] ?? ''), ENT_QUOTES) ?>',
                                                    <?= (float)($item['unit_cost'] ?? 0) ?>,
                                                    <?= max(1, $units_per_box) ?>,
                                                    '<?= $row_key ?>'
                                                )"
                                            >
                                                <i class="bi bi-calculator"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="6" class="text-end">Total:</th>
                        <th class="text-end"><?= number_format(array_sum(array_column($inventory, 'available_quantity'))) ?></th>
                        <th class="text-end">-</th>
                        <th class="text-end">-</th>
                        <th class="text-end">-</th>
                        <th>-</th>
                        <th>-</th>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
    </div>
</div>

<!-- Item Details Modal -->
<div class="modal fade" id="itemDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-info-circle me-2"></i>
                    <span id="modalItemType"></span> Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="itemDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Box Price Calculator Modal -->
<div class="modal fade" id="boxPriceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calculator me-2"></i>Box Price Calculator</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="card border-0 bg-light">
                    <div class="card-body">
                        <div class="mb-2 small text-muted">Item</div>
                        <div class="fw-semibold mb-3" id="popupCalcItemName">-</div>

                        <label for="popupCalcUnits" class="form-label">1 Box = ? Units</label>
                        <input type="number" min="1" step="1" class="form-control mb-3" id="popupCalcUnits">

                        <button type="button" class="btn btn-primary w-100 mb-2" onclick="savePopupBoxSettings()">
                            <i class="bi bi-check2-circle me-1"></i>Save
                        </button>

                        <div class="text-center">
                            <div class="small text-muted">Price per 1 Box</div>
                            <div class="h5 mb-0 text-success" id="popupCalcResult">$0.00</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.inventory-dashboard {
    color: #111827;
}

.inventory-page-head h1 {
    font-weight: 800;
    letter-spacing: 0;
}

.inventory-action-btn {
    border-radius: 6px;
    font-weight: 700;
    padding: 0.78rem 1.35rem;
}

.inventory-action-btn.btn-outline-primary {
    color: #ff5faa;
    border-color: #ffb7d8;
    background: #fff8fc;
}

.inventory-action-btn.btn-outline-primary:hover,
.inventory-action-btn.btn-outline-primary:focus {
    color: #fff;
    background: #ff5faa;
    border-color: #ff5faa;
}

.inventory-action-btn.btn-outline-success {
    color: #16a34a;
    border-color: #9bd8b1;
    background: #f7fff9;
}

.inventory-tip {
    border-radius: 6px;
    border-color: #bfdbfe;
    background: #eff6ff;
    color: #315f9f;
    font-weight: 600;
    padding: 1rem 1.35rem;
}

.inventory-tip i {
    color: #0d6efd;
    font-size: 1.2rem;
}

.inventory-stat-card {
    min-height: 118px;
    display: flex;
    align-items: center;
    gap: 1.35rem;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #fff;
    padding: 1.25rem 1.55rem;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
}

.inventory-stat-card h5 {
    margin: 0 0 0.35rem;
    font-size: 0.95rem;
    font-weight: 800;
}

.inventory-stat-card h3 {
    margin: 0;
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.05;
}

.inventory-stat-card small {
    display: block;
    margin-top: 0.35rem;
    font-weight: 700;
}

.inventory-stat-card .stat-icon {
    width: 58px;
    height: 58px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    flex: 0 0 58px;
    font-size: 1.8rem;
}

.stat-blue { color: #0d6efd; }
.stat-blue .stat-icon { background: #eaf2ff; }
.stat-green { color: #16a34a; }
.stat-green .stat-icon { background: #e7f8ed; }
.stat-cyan { color: #0891b2; }
.stat-cyan .stat-icon { background: #e6f8fc; }
.stat-orange { color: #fb8500; }
.stat-orange .stat-icon { background: #fff1dc; }

.inventory-panel {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
}

.inventory-panel-header {
    padding: 1.05rem 1.25rem;
    border-bottom: 1px solid #e5e7eb;
    background: #fff;
    border-radius: 8px 8px 0 0;
}

.inventory-panel-header h5 {
    font-weight: 800;
}

.inventory-filter-panel label {
    color: #374151;
    font-weight: 800;
    font-size: 0.88rem;
}

.inventory-filter-panel .form-control,
.inventory-filter-panel .form-select {
    border-radius: 6px;
    border-color: #dbe3ef;
    min-height: 46px;
    font-size: 0.98rem;
}

.inventory-filter-btn,
.inventory-reset-btn {
    min-height: 46px;
    border-radius: 6px;
    font-weight: 800;
}

.inventory-filter-btn {
    color: #ff5faa;
    border-color: #ffb7d8;
    background: #fff8fc;
}

.inventory-reset-btn {
    color: #6b7280;
    border-color: #d7dde6;
    background: #fff;
}

.inventory-location-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.inventory-location-row {
    display: grid;
    grid-template-columns: minmax(220px, 1.8fr) repeat(3, minmax(120px, 1fr));
    align-items: center;
    gap: 1rem;
    padding: 1rem 0.25rem;
    border-top: 1px solid #e5e7eb;
}

.inventory-location-row:first-child {
    border-top: none;
}

.location-main {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.location-main h6 {
    margin: 0 0 0.25rem;
    font-weight: 800;
}

.location-icon {
    width: 46px;
    height: 46px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    color: #16a34a;
    background: #dff7e8;
    font-size: 1.35rem;
}

.location-metric {
    border-left: 1px solid #e5e7eb;
    padding-left: 1.25rem;
}

.location-metric span {
    display: block;
    color: #4b5563;
    font-weight: 700;
    font-size: 0.86rem;
    margin-bottom: 0.35rem;
}

.location-metric strong {
    font-size: 1.35rem;
    color: #111827;
}

.inventory-table {
    table-layout: auto;
    font-size: 0.95rem;
    border-collapse: separate;
    border-spacing: 0;
}

.inventory-table th,
.inventory-table td {
    vertical-align: middle;
    white-space: nowrap;
}

.inventory-table thead th {
    position: sticky;
    top: 0;
    z-index: 4;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    color: #0f172a;
    font-weight: 700;
    border-bottom: 1px solid #dbe4ee;
}

.inventory-table tbody td {
    border-color: #e9edf3;
    padding-top: 0.62rem;
    padding-bottom: 0.62rem;
    font-weight: 600;
}

.inventory-table tbody tr:hover td {
    background: #f8fbff;
}

.inventory-table tbody tr:nth-child(even) td {
    background: #fcfdff;
}

.inventory-table code {
    background: #eef2ff;
    color: #4338ca;
    border-radius: 6px;
    padding: 0.12rem 0.4rem;
}

.inventory-brand-badge {
    color: var(--brand-color);
    background: color-mix(in srgb, var(--brand-color) 13%, white);
    border: 1px solid color-mix(in srgb, var(--brand-color) 34%, white);
    border-radius: 999px;
    font-weight: 800;
    padding: 0.35rem 0.55rem;
}

.inventory-table .col-product-box {
    min-width: 110px;
}

.inventory-table .col-box-breakdown {
    min-width: 150px;
}

.inventory-table .col-units-box {
    min-width: 110px;
}

.inventory-table .col-actions {
    min-width: 95px;
}

.inventory-table .btn {
    padding: 0.3rem 0.55rem;
}

/* Keep Actions visible on small/scrolling screens */
.inventory-table th.col-actions,
.inventory-table td.col-actions {
    position: sticky;
    right: 0;
    z-index: 2;
    background: #fff;
}

.inventory-table thead th.col-actions {
    z-index: 3;
}

.table-responsive {
    overflow-x: auto;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
}

.inventory-pagination .btn {
    min-width: 42px;
    border-radius: 6px;
    font-weight: 800;
}

.inventory-pagination .btn-primary {
    background: #ff5faa;
    border-color: #ff5faa;
}

.inventory-pagination .form-select {
    min-width: 118px;
    border-radius: 6px;
    font-weight: 700;
}

@media (max-width: 992px) {
    .inventory-location-row {
        grid-template-columns: 1fr 1fr;
    }

    .location-main {
        grid-column: 1 / -1;
    }

    .inventory-table {
        font-size: 0.88rem;
    }

    .inventory-table .col-product-box {
        min-width: 95px;
    }

    .inventory-table .col-box-breakdown {
        min-width: 135px;
    }

    .inventory-table .col-units-box {
        min-width: 95px;
    }

    .inventory-table .col-actions {
        min-width: 82px;
    }

    .inventory-table .btn {
        padding: 0.25rem 0.45rem;
    }

    .inventory-table thead th {
        top: 0;
    }
}

@media (max-width: 576px) {
    .inventory-page-head {
        align-items: stretch !important;
    }

    .inventory-page-head > div,
    .inventory-page-head .btn {
        width: 100%;
    }

    .inventory-stat-card {
        min-height: 104px;
        padding: 1rem;
    }

    .inventory-stat-card h3 {
        font-size: 1.65rem;
    }

    .inventory-location-row {
        grid-template-columns: 1fr;
    }

    .location-metric {
        border-left: none;
        border-top: 1px solid #e5e7eb;
        padding: 0.75rem 0 0;
    }

    .inventory-table {
        font-size: 0.82rem;
    }

    #boxPriceModal .modal-dialog {
        margin: 0.75rem;
    }
}

@media print {
    .btn, form, .card-header h5 {
        display: none !important;
    }
    
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
        page-break-inside: avoid;
    }
    
    .col-md-4 {
        float: left;
        width: 33.333%;
    }
}
</style>

<script>
function viewItemDetails(itemName, itemType, sku, quantity, locationName, locationCode, itemId, unitsPerBox, boxesInStock) {
    // Set modal title
    const modalTitle = itemType === 'set' ? 'Product Set' : 'Inventory Item';
    document.getElementById('modalItemType').textContent = modalTitle;
    
    // Build detailed content based on item type
    let content = `
        <div class="row g-3">
            <div class="col-md-8">
                <div class="card border-0 bg-light">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="bi bi-${itemType === 'set' ? 'collection' : 'box-seam'} me-2"></i>
                            ${itemName}
                        </h5>
                        
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <div class="border rounded p-3 bg-white">
                                    <div class="text-muted small mb-1">SKU/Code</div>
                                    <div class="fw-semibold">${sku || 'N/A'}</div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="border rounded p-3 bg-white">
                                    <div class="text-muted small mb-1">Type</div>
                                    <div class="fw-semibold">
                                        <span class="badge bg-${itemType === 'set' ? 'info' : 'primary'}">
                                            ${itemType === 'set' ? '📦 Product Set' : '📦 Regular Item'}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card border-0 bg-light">
                    <div class="card-body">
                        <h6 class="card-title mb-3">Stock Information</h6>
                        
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Available Quantity:</span>
                            <span class="badge bg-${quantity > 0 ? 'success' : 'danger'} fs-6">
                                ${quantity} ${itemType === 'set' ? 'sets' : 'units'}
                            </span>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Product in Box:</span>
                            <span class="fw-semibold">${itemType === 'inventory' && parseFloat(unitsPerBox) > 0 ? (parseFloat(boxesInStock || 0).toFixed(2) + ' boxs') : '-'}</span>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Units per Box:</span>
                            <span class="fw-semibold">${itemType === 'inventory' && parseFloat(unitsPerBox) > 0 ? (parseFloat(unitsPerBox).toFixed(0) + ' pcs') : '-'}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <hr class="my-4">
        
        <div class="row g-3">
            <div class="col-md-6">
                <h6 class="mb-3">
                    <i class="bi bi-geo-alt text-primary me-2"></i>
                    Location Information
                </h6>
                <div class="card border">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Location Code:</span>
                            <span class="badge bg-secondary">${locationCode || 'N/A'}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Location Name:</span>
                            <span class="fw-semibold">${locationName || 'N/A'}</span>
                        </div>
                    </div>
                </div>
            </div>`;
    
    // Add product set specific information
    if (itemType === 'set') {
        content += `
            <div class="col-md-6">
                <h6 class="mb-3">
                    <i class="bi bi-diagram-3 text-info me-2"></i>
                    Product Set Details
                </h6>
                <div class="card border">
                    <div class="card-body">
                        <div id="setComponents_${itemId}">
                            <div class="text-center">
                                <div class="spinner-border spinner-border-sm text-info" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <small class="text-muted ms-2">Loading components...</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;
        
        // Load set components
        setTimeout(() => {
            loadSetComponents(itemId);
        }, 300);
    } else {
        content += `
            <div class="col-md-6">
                <h6 class="mb-3">
                    <i class="bi bi-graph-up text-success me-2"></i>
                    Inventory Analytics
                </h6>
                <div class="card border">
                    <div class="card-body">
                        <div class="text-center text-muted">
                            <i class="bi bi-bar-chart-line fs-1 mb-2"></i>
                            <p>Analytics data coming soon</p>
                        </div>
                    </div>
                </div>
            </div>`;
    }
    
    content += `
        </div>
    `;
    
    // Set modal content and show
    document.getElementById('itemDetailsContent').innerHTML = content;
    const modal = new bootstrap.Modal(document.getElementById('itemDetailsModal'));
    modal.show();
}

function loadSetComponents(setId) {
    // Fetch set components via AJAX
    fetch('?action=get_set_components&set_id=' + setId)
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('setComponents_' + setId);
            
            if (data.success && data.components.length > 0) {
                let html = '<div class="small">';
                data.components.forEach(component => {
                    html += `
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span>${component.product_name}</span>
                            <span class="badge bg-light text-dark">${component.quantity}x</span>
                        </div>`;
                });
                html += '</div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="text-muted small">No components found</div>';
            }
        })
        .catch(error => {
            console.error('Error loading set components:', error);
            const container = document.getElementById('setComponents_' + setId);
            container.innerHTML = '<div class="text-danger small">Error loading components</div>';
        });
}

function exportInventoryTableCsv() {
    const table = document.querySelector('.inventory-table');
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('tr'));
    const csv = rows.map(row => {
        const cells = Array.from(row.querySelectorAll('th,td')).filter(cell => !cell.classList.contains('col-actions'));
        return cells.map(cell => {
            const value = cell.innerText.replace(/\s+/g, ' ').trim().replace(/"/g, '""');
            return `"${value}"`;
        }).join(',');
    }).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'inventory_view_' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

function openBoxPricePopup(itemName, sku, locationCode, unitCost, unitsPerBoxDefault, rowKey) {
    const unitsEl = document.getElementById('popupCalcUnits');
    const resultEl = document.getElementById('popupCalcResult');
    const itemEl = document.getElementById('popupCalcItemName');

    if (!unitsEl || !resultEl || !itemEl) return;

    itemEl.textContent = itemName || '-';
    unitsEl.value = String(Math.max(1, parseFloat(unitsPerBoxDefault || 1)));
    resultEl.textContent = '$0.00';

    unitsEl.dataset.unitCost = String(parseFloat(unitCost || 0));
    unitsEl.dataset.rowKey = rowKey || '';
    unitsEl.dataset.itemName = itemName || '';
    unitsEl.dataset.sku = sku || '';
    unitsEl.dataset.locationCode = locationCode || '';

    const modal = new bootstrap.Modal(document.getElementById('boxPriceModal'));
    modal.show();
}

function savePopupBoxSettings() {
    const unitsEl = document.getElementById('popupCalcUnits');
    const resultEl = document.getElementById('popupCalcResult');
    if (!unitsEl || !resultEl) return;

    const unitCost = parseFloat(unitsEl.dataset.unitCost || 0);
    const rowKey = unitsEl.dataset.rowKey || '';
    const itemName = unitsEl.dataset.itemName || '';
    const sku = unitsEl.dataset.sku || '';
    const locationCode = unitsEl.dataset.locationCode || '';
    const unitsPerBox = parseFloat(unitsEl.value || 0);
    const safeUnits = Math.max(1, Math.floor(unitsPerBox));
    const pricePerBox = safeUnits * Math.max(0, unitCost);
    resultEl.textContent = '$' + pricePerBox.toFixed(2);

    // Update table columns in inventory view (frontend only)
    if (rowKey) {
        const boxEl = document.getElementById(rowKey + '_box');
        const boxSuffixEl = document.getElementById(rowKey + '_box_suffix');
        const breakdownEl = document.getElementById(rowKey + '_breakdown');
        const unitsElRow = document.getElementById(rowKey + '_units');
        const unitsSuffixEl = document.getElementById(rowKey + '_units_suffix');

        const availableEl = document.getElementById(rowKey + '_available');
        const availableQty = availableEl ? parseFloat(availableEl.dataset.availableQty || 0) : 0;
        const fullBoxes = Math.floor(availableQty / safeUnits);
        const remainingUnits = Math.max(0, Math.floor(availableQty - (fullBoxes * safeUnits)));

        if (boxEl) {
            boxEl.textContent = String(fullBoxes);
            boxEl.classList.remove('text-muted');
            boxEl.classList.add('fw-semibold');
        }
        if (boxSuffixEl) boxSuffixEl.textContent = 'boxs';
        if (unitsElRow) {
            unitsElRow.textContent = String(Math.round(safeUnits));
            unitsElRow.classList.remove('text-muted');
        }
        if (unitsSuffixEl) unitsSuffixEl.textContent = 'units';
        if (breakdownEl) {
            breakdownEl.classList.remove('text-muted');
            breakdownEl.innerHTML = `
                <span class="fw-semibold">${fullBoxes}</span>
                <small class="text-muted">boxs</small>
                <span class="text-muted"> + </span>
                <span class="fw-semibold">${remainingUnits}</span>
                <small class="text-muted">units</small>
            `;
        }

    }

    // Save to database
    const payload = new URLSearchParams();
    payload.set('action', 'save_box_settings');
    payload.set('item_name', itemName);
    payload.set('sku', sku);
    payload.set('location_code', locationCode);
    payload.set('units_per_box', String(safeUnits));

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: payload.toString()
    })
    .then((res) => res.json())
    .then((data) => {
        if (!data || data.success !== true) {
            throw new Error((data && data.message) ? data.message : 'Save failed.');
        }
        const modalEl = document.getElementById('boxPriceModal');
        const activeModal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
        if (activeModal) activeModal.hide();
    })
    .catch((err) => {
        alert('Could not save to database: ' + (err.message || 'Unknown error'));
    });
}

// Make functions globally available
window.viewItemDetails = viewItemDetails;
window.loadSetComponents = loadSetComponents;
window.openBoxPricePopup = openBoxPricePopup;
window.savePopupBoxSettings = savePopupBoxSettings;
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
