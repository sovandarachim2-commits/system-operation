<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_common.php';

$pdo = get_db_connection();
require_role_or_permission(
    ['admin'],
    'sr_inventory_onhand.view',
    'inventory.view',
    'inventory_view.view',
    'stock_dashboard.view',
    'stock_reports.view'
);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '{}', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $action = strtolower(trim((string)($payload['action'] ?? $_GET['action'] ?? '')));
    if ($action === 'copy_skus' || $action === 'backfill_skus') {
        $result = inventory_copy_product_skus($pdo);
        if (empty($result['ok'])) {
            api_json($result, 400);
        }
        api_json($result);
    }
}

$locationId = inventory_api_int($_GET['location_id'] ?? 0);
$brandId = inventory_api_int($_GET['brand_id'] ?? 0);
$q = inventory_api_str($_GET['q'] ?? '');
$locationIds = [];
$locationIdsRaw = inventory_api_str($_GET['location_ids'] ?? '');
if ($locationIdsRaw !== '') {
    foreach (preg_split('/[,\s]+/', $locationIdsRaw) as $part) {
        $id = (int)$part;
        if ($id > 0) {
            $locationIds[$id] = $id;
        }
    }
    $locationIds = array_values($locationIds);
}
if (!$locationIds && $locationId > 0) {
    $locationIds = [$locationId];
}
$itemType = strtolower(inventory_api_str($_GET['item_type'] ?? $_GET['product_type'] ?? ''));
if ($itemType === 'item' || $itemType === 'normal' || $itemType === 'general' || $itemType === 'inventory') {
    $itemType = 'inventory';
}
if ($itemType !== '' && $itemType !== 'inventory' && $itemType !== 'set') {
    $itemType = '';
}
$lowStockOnly = inventory_api_str($_GET['low_stock'] ?? '') === '1';
$includeZero = inventory_api_str($_GET['include_zero'] ?? '') === '1';
$threshold = inventory_api_num($_GET['threshold'] ?? 10);
if ($threshold < 0) {
    $threshold = 10;
}

function inventory_onhand_normalize_rows(array $rows, float $threshold): array
{
    $summary = [
        'sku_count' => 0,
        'total_qty' => 0.0,
        'total_value' => 0.0,
        'low_stock_count' => 0,
        'location_count' => 0,
        'threshold' => $threshold,
    ];
    $locationsSeen = [];

    foreach ($rows as &$row) {
        $onHand = inventory_api_num($row['quantity_on_hand'] ?? 0);
        $reserved = inventory_api_num($row['reserved_quantity'] ?? 0);
        $available = inventory_api_num($row['available_quantity'] ?? ($onHand - $reserved));
        $unitCost = inventory_api_num($row['unit_cost'] ?? 0);
        $totalValue = inventory_api_num($row['total_value'] ?? ($onHand * $unitCost));
        $rowItemType = strtolower(trim((string)($row['item_type'] ?? 'inventory')));
        if ($rowItemType !== 'set') {
            $rowItemType = 'inventory';
        }

        $row['quantity_on_hand'] = $onHand;
        $row['reserved_quantity'] = $reserved;
        $row['available_quantity'] = $available;
        $row['unit_cost'] = $unitCost;
        $row['total_value'] = $totalValue;
        $row['brand_id'] = (int)($row['brand_id'] ?? 0);
        $row['brand_name'] = trim((string)($row['brand_name'] ?? ''));
        $row['item_type'] = $rowItemType;
        $row['item_type_label'] = $rowItemType === 'set' ? 'Set' : 'Item';
        $row['product_type'] = $rowItemType === 'set' ? 'set' : 'item';
        $row['product_type_label'] = $row['item_type_label'];
        $row['is_low_stock'] = $available > 0 && $available <= $threshold;

        $summary['sku_count'] += 1;
        $summary['total_qty'] += $onHand;
        $summary['total_value'] += $totalValue;
        if ($row['is_low_stock']) {
            $summary['low_stock_count'] += 1;
        }
        $locId = (int)($row['storage_location_id'] ?? 0);
        if ($locId > 0) {
            $locationsSeen[$locId] = true;
        }
    }
    unset($row);

    $summary['location_count'] = count($locationsSeen);
    return [$rows, $summary];
}

function inventory_apply_box_settings(PDO $pdo, array $rows): array
{
    $byNameSkuLoc = [];
    $byNameSku = [];
    $byName = [];
    try {
        $settings = $pdo->query('
            SELECT item_name, sku, location_code, units_per_box, updated_at, id
            FROM inventory_box_settings
            WHERE units_per_box > 0
            ORDER BY updated_at DESC, id DESC
        ')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($settings as $setting) {
            $name = strtolower(trim((string)($setting['item_name'] ?? '')));
            $sku = strtolower(trim((string)($setting['sku'] ?? '')));
            $location = strtolower(trim((string)($setting['location_code'] ?? '')));
            if ($location === '__all__') {
                $location = '';
            }
            $units = max(0, (int)($setting['units_per_box'] ?? 0));
            if ($name === '' || $units <= 0) {
                continue;
            }
            $nameSkuLoc = $name . '|' . $sku . '|' . $location;
            $nameSku = $name . '|' . $sku;
            if ($location !== '' && !isset($byNameSkuLoc[$nameSkuLoc])) {
                $byNameSkuLoc[$nameSkuLoc] = $units;
            }
            if (!isset($byNameSku[$nameSku])) {
                $byNameSku[$nameSku] = $units;
            }
            if (!isset($byName[$name])) {
                $byName[$name] = $units;
            }
        }
    } catch (Throwable $e) {
        foreach ($rows as &$row) {
            $row['units_per_box'] = (int)($row['units_per_box'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    foreach ($rows as &$row) {
        if (strtolower((string)($row['item_type'] ?? '')) === 'set') {
            $row['units_per_box'] = 0;
            continue;
        }
        $name = strtolower(trim((string)($row['item_name'] ?? '')));
        $sku = strtolower(trim((string)($row['sku'] ?? '')));
        $location = strtolower(trim((string)($row['location_code'] ?? '')));
        $units = $byNameSkuLoc[$name . '|' . $sku . '|' . $location]
            ?? $byNameSku[$name . '|' . $sku]
            ?? ($sku !== '' ? ($byNameSku[$name . '|'] ?? null) : null)
            ?? $byName[$name]
            ?? 0;
        $row['units_per_box'] = (int)$units;
    }
    unset($row);
    return $rows;
}

function inventory_onhand_response(PDO $pdo, array $rows, array $summary, array $filters): void
{
    api_json([
        'success' => true,
        'filters' => $filters,
        'summary' => $summary,
        'rows' => $rows,
        'location_options' => inventory_location_options($pdo),
        'brand_options' => inventory_brand_options($pdo),
        'item_type_options' => [
            ['value' => 'inventory', 'label' => 'Item'],
            ['value' => 'set', 'label' => 'Set'],
        ],
        'product_type_options' => [
            ['value' => 'inventory', 'label' => 'Item'],
            ['value' => 'set', 'label' => 'Set'],
        ],
    ]);
}

$filterPayload = [
    'location_id' => $locationIds[0] ?? 0,
    'location_ids' => $locationIds,
    'brand_id' => $brandId,
    'q' => $q,
    'item_type' => $itemType,
    'product_type' => $itemType,
    'low_stock' => $lowStockOnly ? 1 : 0,
    'include_zero' => $includeZero ? 1 : 0,
    'threshold' => $threshold,
];

try {
    try {
        inventory_copy_product_skus($pdo);
    } catch (Throwable $copyError) {
        // Keep loading stock even if SKU copy fails.
    }
    $sql = 'SELECT * FROM (' . inventory_onhand_base_sql($pdo) . ') stock WHERE 1=1';
    $params = [];

    if ($locationIds) {
        $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
        $sql .= " AND storage_location_id IN ($placeholders)";
        foreach ($locationIds as $id) {
            $params[] = $id;
        }
    }
    if ($brandId > 0) {
        $sql .= ' AND brand_id = ?';
        $params[] = $brandId;
    }
    if ($itemType !== '') {
        $sql .= ' AND item_type = ?';
        $params[] = $itemType;
    }
    if ($q !== '') {
        $sql .= ' AND (item_name LIKE ? OR sku LIKE ? OR brand_name LIKE ? OR location_name LIKE ? OR location_code LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if (!$includeZero) {
        $sql .= ' AND quantity_on_hand > 0';
    }
    if ($lowStockOnly) {
        $sql .= ' AND available_quantity > 0 AND available_quantity <= ?';
        $params[] = $threshold;
    }

    $sql .= ' ORDER BY item_name ASC, location_name ASC, sku ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    [$rows, $summary] = inventory_onhand_normalize_rows($stmt->fetchAll(PDO::FETCH_ASSOC), $threshold);
    $rows = inventory_apply_box_settings($pdo, $rows);
    inventory_onhand_response($pdo, $rows, $summary, $filterPayload);
} catch (Throwable $e) {
    try {
        if ($itemType === 'set') {
            inventory_onhand_response($pdo, [], [
                'sku_count' => 0,
                'total_qty' => 0.0,
                'total_value' => 0.0,
                'low_stock_count' => 0,
                'location_count' => 0,
                'threshold' => $threshold,
            ], $filterPayload);
        }

        $sql = '
            SELECT
                ci.id,
                ci.item_name,
                COALESCE(NULLIF(TRIM(ci.sku), \'\'), NULLIF(TRIM(p.sku), \'\')) AS sku,
                \'inventory\' AS item_type,
                \'normal\' AS product_type,
                COALESCE(p.brand_id, 0) AS brand_id,
                COALESCE(NULLIF(TRIM(b.name), \'\'), \'\') AS brand_name,
                ci.storage_location_id,
                sl.location_code,
                sl.location_name,
                ci.quantity_on_hand,
                COALESCE(ci.reserved_quantity, 0) AS reserved_quantity,
                COALESCE(ci.available_quantity, ci.quantity_on_hand - COALESCE(ci.reserved_quantity, 0)) AS available_quantity,
                COALESCE(ci.unit_cost, 0) AS unit_cost,
                COALESCE(ci.total_value, ci.quantity_on_hand * COALESCE(ci.unit_cost, 0)) AS total_value,
                ci.last_updated
            FROM current_inventory ci
            LEFT JOIN storage_locations sl ON sl.id = ci.storage_location_id
            LEFT JOIN (
                SELECT
                    TRIM(name) AS name_key,
                    MIN(NULLIF(TRIM(sku), \'\')) AS sku,
                    MIN(brand_id) AS brand_id
                FROM products
                GROUP BY TRIM(name)
            ) p ON p.name_key COLLATE utf8mb4_unicode_ci = TRIM(ci.item_name) COLLATE utf8mb4_unicode_ci
            LEFT JOIN brands b ON b.id = p.brand_id
            WHERE 1=1
        ';
        $params = [];
        if ($locationIds) {
            $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
            $sql .= " AND ci.storage_location_id IN ($placeholders)";
            foreach ($locationIds as $id) {
                $params[] = $id;
            }
        }
        if ($brandId > 0) {
            $sql .= ' AND p.brand_id = ?';
            $params[] = $brandId;
        }
        if ($q !== '') {
            $sql .= ' AND (ci.item_name LIKE ? OR ci.sku LIKE ? OR b.name LIKE ? OR sl.location_name LIKE ? OR sl.location_code LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if (!$includeZero) {
            $sql .= ' AND ci.quantity_on_hand > 0';
        }
        if ($lowStockOnly) {
            $sql .= ' AND COALESCE(ci.available_quantity, ci.quantity_on_hand - COALESCE(ci.reserved_quantity, 0)) > 0
                      AND COALESCE(ci.available_quantity, ci.quantity_on_hand - COALESCE(ci.reserved_quantity, 0)) <= ?';
            $params[] = $threshold;
        }
        $sql .= ' ORDER BY ci.item_name ASC, sl.location_name ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        [$rows, $summary] = inventory_onhand_normalize_rows($stmt->fetchAll(PDO::FETCH_ASSOC), $threshold);
        $rows = inventory_apply_box_settings($pdo, $rows);
        inventory_onhand_response($pdo, $rows, $summary, $filterPayload);
    } catch (Throwable $fallbackError) {
        api_json([
            'success' => false,
            'message' => 'Unable to load stock on hand: ' . $fallbackError->getMessage(),
        ], 500);
    }
}

