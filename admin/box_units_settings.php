<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'inventory_box_settings.view');

$pdo = get_db_connection();
$user = current_user();

$isAdminUser = (($user['role'] ?? '') === 'admin');
$canCreateBox = $isAdminUser || (function_exists('has_permission') && has_permission('inventory_box_settings.create'));
$canUpdateBox = $isAdminUser || (function_exists('has_permission') && has_permission('inventory_box_settings.update'));

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
            created_by INT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_item_sku_loc (item_name, sku, location_code)
        )
    ");
    try {
        $hasCreatedBy = (bool)$pdo->query("SHOW COLUMNS FROM inventory_box_settings LIKE 'created_by'")->fetchColumn();
        if (!$hasCreatedBy) {
            $pdo->exec("ALTER TABLE inventory_box_settings ADD COLUMN created_by INT NULL AFTER units_per_box");
        }
        $hasCreatedAt = (bool)$pdo->query("SHOW COLUMNS FROM inventory_box_settings LIKE 'created_at'")->fetchColumn();
        if (!$hasCreatedAt) {
            $pdo->exec("ALTER TABLE inventory_box_settings ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by");
            $pdo->exec("UPDATE inventory_box_settings SET created_at = updated_at WHERE created_at IS NULL");
        }
    } catch (Throwable $e) {
        // Keep page usable if audit columns already exist.
    }
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
        if ($locationCode === '') {
            $locationCode = '__ALL__';
        }
        $unitsPerBox = (int)($_POST['units_per_box'] ?? 0);

        $unitsPerBox = max(1, $unitsPerBox);
        $createdBy = (int)($user['id'] ?? 0);
        $updatedBy = (int)($user['id'] ?? 0);

        if ($itemName === '') {
            throw new RuntimeException('Invalid item name.');
        }

        // Decide permission based on whether this setting already exists.
        // - If exists: require inventory_box_settings.update
        // - If not exists: require inventory_box_settings.create
        $existsStmt = $pdo->prepare("
            SELECT id
            FROM inventory_box_settings
            WHERE item_name COLLATE utf8mb4_unicode_ci = ?
              AND COALESCE(sku, '') COLLATE utf8mb4_unicode_ci = COALESCE(?, '')
              AND COALESCE(location_code, '') COLLATE utf8mb4_unicode_ci = COALESCE(?, '')
            LIMIT 1
        ");
        $existsStmt->execute([$itemName, $sku, $locationCode]);
        $exists = (bool)$existsStmt->fetchColumn();

        if ($exists && !$canUpdateBox) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'No edit permission (inventory_box_settings.update).',
            ]);
            exit;
        }

        if (!$exists && !$canCreateBox) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'No create permission (inventory_box_settings.create).',
            ]);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO inventory_box_settings (item_name, sku, location_code, units_per_box, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?)
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
            $createdBy > 0 ? $createdBy : null,
            $updatedBy > 0 ? $updatedBy : null,
        ]);

        echo json_encode([
            'success' => true,
            'units_per_box' => $unitsPerBox,
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
}

// AJAX endpoint: refresh only the table body after save.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'refresh_box_units_settings_table')) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $location_filter = (int)($_GET['location_filter'] ?? 0);
        $search = trim((string)($_GET['search'] ?? ''));

        $params = [];
        $sql = "
            SELECT
                base.item_name,
                base.sku,
                base.available_quantity,
                base.unit_cost,
                ibs.units_per_box,
                ibs.created_at,
                ibs.updated_at,
                creator.name AS created_by_name,
                updater.name AS updated_by_name
            FROM (
                SELECT
                    ci.item_name,
                    MAX(ci.sku) AS sku,
                    SUM(COALESCE(ci.quantity_on_hand, 0)) AS available_quantity,
                    MAX(COALESCE(ci.unit_cost, 0)) AS unit_cost
                FROM current_inventory ci
                LEFT JOIN product_sets ps ON ci.item_name = ps.set_name
                LEFT JOIN storage_locations sl ON ci.storage_location_id = sl.id
                WHERE ps.id IS NULL
        ";

        if ($location_filter > 0) {
            $sql .= ' AND ci.storage_location_id = ?';
            $params[] = $location_filter;
        }

        if ($search !== '') {
            $sql .= ' AND (ci.item_name LIKE ? OR ci.sku LIKE ? OR sl.location_code LIKE ?)';
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        $sql .= "
                GROUP BY ci.item_name
                HAVING SUM(COALESCE(ci.quantity_on_hand, 0)) >= 0
            ) base
            LEFT JOIN (
                SELECT s1.*
                FROM inventory_box_settings s1
                INNER JOIN (
                    SELECT item_name, MAX(id) AS max_id
                    FROM inventory_box_settings
                    GROUP BY item_name
                ) latest ON latest.max_id = s1.id
            ) ibs
                ON ibs.item_name COLLATE utf8mb4_unicode_ci = base.item_name COLLATE utf8mb4_unicode_ci
            LEFT JOIN users creator ON creator.id = ibs.created_by
            LEFT JOIN users updater ON updater.id = ibs.updated_by
            ORDER BY base.item_name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $groupedItems = array_values($items);

        $html = '';
        foreach ($groupedItems as $index => $row) {
            $rowKey = 'row_' . md5((string)($row['item_name'] ?? ''));
            $unitsPerBoxRaw = $row['units_per_box'] ?? null;
            $hasSetting = $unitsPerBoxRaw !== null;
            $unitsPerBox = (int)($unitsPerBoxRaw ?? 0);

            $itemNameEsc = htmlspecialchars((string)($row['item_name'] ?? ''), ENT_QUOTES);
            $skuEsc = htmlspecialchars((string)($row['sku'] ?? ''), ENT_QUOTES);

            $createdByNameEsc = htmlspecialchars((string)($row['created_by_name'] ?? 'System'), ENT_QUOTES);
            $updatedByNameEsc = htmlspecialchars((string)($row['updated_by_name'] ?? 'System'), ENT_QUOTES);

            $createdAt = !empty($row['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$row['created_at'])) : '-';
            $updatedAt = !empty($row['updated_at']) ? date('Y-m-d H:i:s', strtotime((string)$row['updated_at'])) : '-';

            $createdAtEsc = htmlspecialchars((string)$createdAt, ENT_QUOTES);
            $updatedAtEsc = htmlspecialchars((string)$updatedAt, ENT_QUOTES);

            $availableQty = number_format((float)($row['available_quantity'] ?? 0), 2);
            $unitCost = (float)($row['unit_cost'] ?? 0);

            // Buttons based on permissions + whether a setting already exists.
            $actionHtml = '';
            if ($hasSetting) {
                if ($canUpdateBox) {
                    $actionHtml = '
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100" title="Edit units per box" onclick="openBoxPricePopup(
                            \'' . $itemNameEsc . '\',
                            \'' . $skuEsc . '\',
                            \'\',
                            ' . $unitCost . ',
                            ' . max(1, (int)$unitsPerBox) . ',
                            \'' . $rowKey . '\'
                        )">
                            <i class="bi bi-pencil-square me-1"></i>Edit
                        </button>
                    ';
                } else {
                    $actionHtml = '
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100" disabled title="No edit permission">
                            <i class="bi bi-lock me-1"></i>Edit
                        </button>
                    ';
                }
            } else {
                if ($canCreateBox) {
                    $actionHtml = '
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" title="Create units per box setting" onclick="openBoxPricePopup(
                            \'' . $itemNameEsc . '\',
                            \'' . $skuEsc . '\',
                            \'\',
                            ' . $unitCost . ',
                            1,
                            \'' . $rowKey . '\'
                        )">
                            <i class="bi bi-calculator me-1"></i>Create
                        </button>
                    ';
                } else {
                    $actionHtml = '
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" disabled title="No create permission">
                            <i class="bi bi-lock me-1"></i>Create
                        </button>
                    ';
                }
            }

            $html .= '
                <tr>
                    <td class="text-center fw-bold align-middle">' . ((int)$index + 1) . '</td>
                    <td class="fw-semibold align-middle">' . $itemNameEsc . '</td>
                    <td class="text-end">' . $availableQty . '</td>
                    <td class="text-end" id="' . $rowKey . '_units">' . number_format(max(0, $unitsPerBox)) . '</td>
                    <td>' . $createdByNameEsc . '</td>
                    <td>' . $createdAtEsc . '</td>
                    <td>' . $updatedByNameEsc . '</td>
                    <td>' . $updatedAtEsc . '</td>
                    <td>
                        <div class="d-flex flex-column gap-1">' . $actionHtml . '</div>
                    </td>
                </tr>
            ';
        }

        echo json_encode(['success' => true, 'html' => $html], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$location_filter = (int)($_GET['location_filter'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));

// Storage locations
try {
    $locationsStmt = $pdo->query('SELECT * FROM storage_locations WHERE is_active = 1 ORDER BY location_code');
    $locations = $locationsStmt->fetchAll();
} catch (PDOException $e) {
    $locations = [];
    $errors[] = 'Storage locations not available.';
}

// Inventory rows (normal items only; one row per product)
$params = [];
$sql = "
    SELECT
        base.item_name,
        base.sku,
        base.available_quantity,
        base.unit_cost,
        ibs.units_per_box,
        ibs.created_at,
        ibs.updated_at,
        creator.name AS created_by_name,
        updater.name AS updated_by_name
    FROM (
        SELECT
            ci.item_name,
            MAX(ci.sku) AS sku,
            SUM(COALESCE(ci.quantity_on_hand, 0)) AS available_quantity,
            MAX(COALESCE(ci.unit_cost, 0)) AS unit_cost
        FROM current_inventory ci
        LEFT JOIN product_sets ps ON ci.item_name = ps.set_name
        LEFT JOIN storage_locations sl ON ci.storage_location_id = sl.id
        WHERE ps.id IS NULL
";

if ($location_filter > 0) {
    $sql .= ' AND ci.storage_location_id = ?';
    $params[] = $location_filter;
}

if ($search !== '') {
    $sql .= ' AND (ci.item_name LIKE ? OR ci.sku LIKE ? OR sl.location_code LIKE ?)';
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$sql .= "
        GROUP BY ci.item_name
        HAVING SUM(COALESCE(ci.quantity_on_hand, 0)) >= 0
    ) base
    LEFT JOIN (
        SELECT s1.*
        FROM inventory_box_settings s1
        INNER JOIN (
            SELECT item_name, MAX(id) AS max_id
            FROM inventory_box_settings
            GROUP BY item_name
        ) latest ON latest.max_id = s1.id
    ) ibs
        ON ibs.item_name COLLATE utf8mb4_unicode_ci = base.item_name COLLATE utf8mb4_unicode_ci
    LEFT JOIN users creator ON creator.id = ibs.created_by
    LEFT JOIN users updater ON updater.id = ibs.updated_by
    ORDER BY base.item_name ASC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $items = [];
    $errors[] = 'Error loading box-unit settings: ' . htmlspecialchars($e->getMessage());
}

$totalRows = count($items);

// Metrics for the dashboard-like UI
$totalProducts = count(array_values(array_unique(array_map(
    fn($x) => (string)($x['item_name'] ?? ''),
    $items
))));
$totalLocations = count($locations);
$totalQty = array_sum(array_map(
    fn($x) => (float)($x['available_quantity'] ?? 0),
    $items
));

// One row per product for display.
$groupedItems = array_values($items);

include __DIR__ . '/../layout/header.php';
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
    .stock-dashboard-page .metric-info { background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%); }
    .stock-dashboard-page .metric-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }

    .stock-dashboard-page .metric-label {
        font-size: 0.9rem;
        opacity: 0.92;
        margin-bottom: 0.35rem;
        letter-spacing: 0.01em;
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
</style>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4 flex-grow-1 stock-dashboard-page">
        <div class="row">
            <div class="col-12">
                <div class="report-topbar d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                    <div>
                        <h2 class="h4 mb-1 report-title"><i class="bi bi-calculator me-2"></i>Set Box to Unit</h2>
                    <div class="report-subtitle">Configure units per box for items</div>
                    </div>
                    <div class="d-flex gap-2 report-actions">
                        <a href="inventory_view.php" class="btn btn-outline-secondary btn-soft">
                            <i class="bi bi-eye me-1"></i>Back
                        </a>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($errors as $e): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>

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
                                <div class="metric-label">Total Units</div>
                                <h3 class="metric-value"><?= number_format($totalQty, 2) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center metric-card metric-info">
                            <div class="card-body">
                                <i class="bi bi-geo-alt fs-3 mb-2"></i>
                                <div class="metric-label">Locations</div>
                                <h3 class="metric-value"><?= number_format($totalLocations) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center metric-card metric-warning">
                            <div class="card-body">
                                <i class="bi bi-table fs-3 mb-2"></i>
                                <div class="metric-label">Rows</div>
                                <h3 class="metric-value"><?= number_format($totalRows) ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter -->
                <form method="get" class="card report-card mb-4">
                    <div class="card-body row g-3 align-items-end">
                        <div class="col-12 col-md-5">
                            <label class="form-label">Search Item</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="form-control form-control-lg" placeholder="Search by item name or SKU...">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Storage Location</label>
                            <select name="location_filter" class="form-select form-select-lg">
                                <option value="0">All Locations</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?= (int)$location['id'] ?>" <?= $location_filter === (int)$location['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($location['location_code']) ?> - <?= htmlspecialchars($location['location_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-outline-primary btn-lg w-100">
                                <i class="bi bi-funnel me-2"></i>Filter
                            </button>
                            <a href="box_units_settings.php" class="btn btn-outline-secondary btn-lg w-100">Reset</a>
                        </div>
                    </div>
                </form>

                <!-- Main Table -->
                <div class="card report-card">
                    <div class="card-header report-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="bi bi-table me-2 text-primary"></i>Box Units Settings (Items)</h5>
                        <small class="chart-meta">Rows: <?= number_format($totalRows) ?></small>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <?php if (empty($items)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-1 mb-2"></i>
                                    <p class="mb-0">No items found</p>
                                </div>
                            <?php else: ?>
                                <table class="table table-bordered table-hover mb-0 table-clean">
                                    <thead>
                                        <tr>
                                            <th style="width: 60px;">No.</th>
                                            <th>Item Name</th>
                                            <th class="text-end">Closing Stock</th>
                                            <th class="text-end">Units per Box</th>
                                            <th>Created By</th>
                                            <th>Created At</th>
                                            <th>Updated By</th>
                                            <th>Updated At</th>
                                            <th style="width: 120px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="boxUnitsTableBody">
                                        <?php foreach ($groupedItems as $index => $row): ?>
                                            <?php
                                                $rowKey = 'row_' . md5((string)($row['item_name'] ?? ''));
                                                $unitsPerBoxRaw = $row['units_per_box'] ?? null;
                                                $hasSetting = $unitsPerBoxRaw !== null;
                                                $unitsPerBox = (int)($unitsPerBoxRaw ?? 0);
                                            ?>
                                            <tr>
                                                <td class="text-center fw-bold align-middle"><?= $index + 1 ?></td>
                                                <td class="fw-semibold align-middle"><?= htmlspecialchars((string)($row['item_name'] ?? '(Unknown Item)')) ?></td>
                                                <td class="text-end">
                                                    <?= number_format((float)($row['available_quantity'] ?? 0), 2) ?>
                                                </td>
                                                <td class="text-end" id="<?= $rowKey ?>_units">
                                                    <?= number_format(max(0, $unitsPerBox)) ?>
                                                </td>
                                                <td><?= htmlspecialchars((string)($row['created_by_name'] ?? 'System')) ?></td>
                                                <td><?= !empty($row['created_at']) ? htmlspecialchars(date('Y-m-d H:i:s', strtotime((string)$row['created_at']))) : '-' ?></td>
                                                <td><?= htmlspecialchars((string)($row['updated_by_name'] ?? 'System')) ?></td>
                                                <td><?= !empty($row['updated_at']) ? htmlspecialchars(date('Y-m-d H:i:s', strtotime((string)$row['updated_at']))) : '-' ?></td>
                                                <td>
                                                    <div class="d-flex flex-column gap-1">
                                                        <?php if ($hasSetting): ?>
                                                            <?php if ($canUpdateBox): ?>
                                                                <button
                                                                    type="button"
                                                                    class="btn btn-sm btn-outline-secondary w-100"
                                                                    title="Edit units per box"
                                                                    onclick="openBoxPricePopup(
                                                                        '<?= htmlspecialchars((string)($row['item_name'] ?? ''), ENT_QUOTES) ?>',
                                                                        '<?= htmlspecialchars((string)($row['sku'] ?? ''), ENT_QUOTES) ?>',
                                                                        '',
                                                                        <?= (float)($row['unit_cost'] ?? 0) ?>,
                                                                        <?= max(1, (int)$unitsPerBox) ?>,
                                                                        '<?= $rowKey ?>'
                                                                    )"
                                                                >
                                                                    <i class="bi bi-pencil-square me-1"></i>Edit
                                                                </button>
                                                            <?php else: ?>
                                                                <button type="button" class="btn btn-sm btn-outline-secondary w-100" disabled title="No edit permission">
                                                                    <i class="bi bi-lock me-1"></i>Edit
                                                                </button>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <?php if ($canCreateBox): ?>
                                                                <button
                                                                    type="button"
                                                                    class="btn btn-sm btn-outline-primary w-100"
                                                                    title="Create units per box setting"
                                                                    onclick="openBoxPricePopup(
                                                                        '<?= htmlspecialchars((string)($row['item_name'] ?? ''), ENT_QUOTES) ?>',
                                                                        '<?= htmlspecialchars((string)($row['sku'] ?? ''), ENT_QUOTES) ?>',
                                                                        '',
                                                                        <?= (float)($row['unit_cost'] ?? 0) ?>,
                                                                        1,
                                                                        '<?= $rowKey ?>'
                                                                    )"
                                                                >
                                                                    <i class="bi bi-calculator me-1"></i>Create
                                                                </button>
                                                            <?php else: ?>
                                                                <button type="button" class="btn btn-sm btn-outline-primary w-100" disabled title="No create permission">
                                                                    <i class="bi bi-lock me-1"></i>Create
                                                                </button>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Box Price / Units-per-box Modal -->
<div class="modal fade" id="boxPriceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background:#fff;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calculator me-2"></i>Set Box to Unit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="card border-0 bg-light">
                    <div class="card-body">
                        <div class="mb-2 small text-muted">Item</div>
                        <div class="fw-semibold mb-3" id="popupCalcItemName">-</div>

                        <label for="popupCalcUnits" class="form-label">1 Box = ? Units</label>
                        <input type="number" min="1" step="1" class="form-control mb-3" id="popupCalcUnits">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-muted">Unit Cost:</span>
                            <span class="fw-semibold text-primary" id="popupCalcUnitCost">$0.00</span>
                        </div>

                        <div class="text-center mb-3">
                            <div class="small text-muted">Price per 1 Box</div>
                            <div class="h5 mb-0 text-success" id="popupCalcResult">$0.00</div>
                        </div>

                        <button type="button" class="btn btn-primary w-100" onclick="savePopupBoxSettings()">
                            <i class="bi bi-check2-circle me-1"></i>Save
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function openBoxPricePopup(itemName, sku, locationCode, unitCost, unitsPerBoxDefault, rowKey) {
        const unitsEl = document.getElementById('popupCalcUnits');
        const resultEl = document.getElementById('popupCalcResult');
        const itemEl = document.getElementById('popupCalcItemName');
        const unitCostEl = document.getElementById('popupCalcUnitCost');

        if (!unitsEl || !resultEl || !itemEl || !unitCostEl) return;

        itemEl.textContent = itemName || '-';
        unitCostEl.textContent = '$' + (parseFloat(unitCost || 0)).toFixed(2);

        const safeDefault = Math.max(1, parseInt(unitsPerBoxDefault || 1, 10));
        unitsEl.value = String(safeDefault);

        // Store values for save request
        unitsEl.dataset.unitCost = String(parseFloat(unitCost || 0));
        unitsEl.dataset.rowKey = rowKey || '';
        unitsEl.dataset.itemName = itemName || '';
        unitsEl.dataset.sku = sku || '';
        unitsEl.dataset.locationCode = locationCode || '';

        resultEl.textContent = '$0.00';

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

        const unitsPerBox = parseInt(unitsEl.value || 0, 10);
        const safeUnits = Math.max(1, Math.floor(unitsPerBox));

        const pricePerBox = safeUnits * Math.max(0, unitCost);
        resultEl.textContent = '$' + pricePerBox.toFixed(2);

        const payload = new URLSearchParams();
        payload.set('action', 'save_box_settings');
        payload.set('item_name', itemName);
        payload.set('sku', sku);
        payload.set('location_code', locationCode);
        payload.set('units_per_box', String(safeUnits));

        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: payload.toString(),
        })
        .then((res) => res.json())
        .then((data) => {
            if (!data || data.success !== true) {
                throw new Error((data && data.message) ? data.message : 'Save failed.');
            }

            // Update units-per-box cell immediately
            if (rowKey) {
                const unitsCell = document.getElementById(rowKey + '_units');
                if (unitsCell) unitsCell.textContent = String(safeUnits);
            }

            const modalEl = document.getElementById('boxPriceModal');
            const activeModal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
            if (activeModal) activeModal.hide();

            // Refresh table body only (no full page reload).
            if (typeof refreshBoxUnitsTable === 'function') {
                refreshBoxUnitsTable();
            }
        })
        .catch((err) => {
            alert('Could not save to database: ' + (err.message || 'Unknown error'));
        });
    }

    function refreshBoxUnitsTable() {
        const tbody = document.getElementById('boxUnitsTableBody');
        if (!tbody) return;

        const payload = new URLSearchParams();
        payload.set('action', 'refresh_box_units_settings_table');

        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: payload.toString(),
        })
        .then((res) => res.json())
        .then((data) => {
            if (data && data.success === true && typeof data.html === 'string') {
                tbody.innerHTML = data.html;
            }
        })
        .catch(() => {
            // Ignore refresh errors; the save already succeeded.
        });
    }

    window.openBoxPricePopup = openBoxPricePopup;
    window.savePopupBoxSettings = savePopupBoxSettings;
    window.refreshBoxUnitsTable = refreshBoxUnitsTable;
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
