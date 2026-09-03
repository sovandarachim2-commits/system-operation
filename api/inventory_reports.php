<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_common.php';

$pdo = get_db_connection();
require_role_or_permission(
    ['admin'],
    'sr_inventory_reports.view',
    'inventory.view',
    'stock_reports.view',
    'stock_dashboard.view'
);

$locationId = inventory_api_int($_GET['location_id'] ?? 0);
$threshold = inventory_api_num($_GET['threshold'] ?? 10);
if ($threshold < 0) {
    $threshold = 10;
}
$q = inventory_api_str($_GET['q'] ?? '');

try {
    $base = inventory_onhand_base_sql($pdo);
    $sql = 'SELECT * FROM (' . $base . ') stock WHERE 1=1';
    $params = [];
    if ($locationId > 0) {
        $sql .= ' AND storage_location_id = ?';
        $params[] = $locationId;
    }
    if ($q !== '') {
        $sql .= ' AND (item_name LIKE ? OR sku LIKE ? OR location_name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }

    $stmt = $pdo->prepare($sql . ' ORDER BY total_value DESC, item_name ASC');
    $stmt->execute($params);
    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $sql = '
        SELECT
            ci.item_name,
            ci.sku,
            \'inventory\' AS item_type,
            ci.storage_location_id,
            sl.location_code,
            sl.location_name,
            ci.quantity_on_hand,
            COALESCE(ci.reserved_quantity, 0) AS reserved_quantity,
            COALESCE(ci.available_quantity, ci.quantity_on_hand - COALESCE(ci.reserved_quantity, 0)) AS available_quantity,
            COALESCE(ci.unit_cost, 0) AS unit_cost,
            COALESCE(ci.total_value, ci.quantity_on_hand * COALESCE(ci.unit_cost, 0)) AS total_value
        FROM current_inventory ci
        LEFT JOIN storage_locations sl ON sl.id = ci.storage_location_id
        WHERE 1=1
    ';
    $params = [];
    if ($locationId > 0) {
        $sql .= ' AND ci.storage_location_id = ?';
        $params[] = $locationId;
    }
    if ($q !== '') {
        $sql .= ' AND (ci.item_name LIKE ? OR ci.sku LIKE ? OR sl.location_name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    $sql .= ' ORDER BY total_value DESC, ci.item_name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$lowStock = [];
$valuationByLocation = [];
$summary = [
    'sku_count' => 0,
    'total_qty' => 0.0,
    'total_value' => 0.0,
    'low_stock_count' => 0,
    'out_of_stock_count' => 0,
    'location_count' => 0,
    'threshold' => $threshold,
];

foreach ($allRows as $row) {
    $onHand = inventory_api_num($row['quantity_on_hand'] ?? 0);
    $available = inventory_api_num($row['available_quantity'] ?? $onHand);
    $unitCost = inventory_api_num($row['unit_cost'] ?? 0);
    $totalValue = inventory_api_num($row['total_value'] ?? ($onHand * $unitCost));
    $locId = (int)($row['storage_location_id'] ?? 0);
    $locName = inventory_api_str($row['location_name'] ?? '') ?: (inventory_api_str($row['location_code'] ?? '') ?: 'Unassigned');

    $summary['sku_count'] += 1;
    $summary['total_qty'] += $onHand;
    $summary['total_value'] += $totalValue;

    if ($available <= 0) {
        $summary['out_of_stock_count'] += 1;
    } elseif ($available <= $threshold) {
        $summary['low_stock_count'] += 1;
        $lowStock[] = [
            'item_name' => $row['item_name'],
            'sku' => $row['sku'],
            'item_type' => $row['item_type'] ?? 'inventory',
            'storage_location_id' => $locId,
            'location_name' => $locName,
            'location_code' => $row['location_code'] ?? '',
            'quantity_on_hand' => $onHand,
            'available_quantity' => $available,
            'unit_cost' => $unitCost,
            'total_value' => $totalValue,
        ];
    }

    if (!isset($valuationByLocation[$locId])) {
        $valuationByLocation[$locId] = [
            'storage_location_id' => $locId,
            'location_name' => $locName,
            'location_code' => $row['location_code'] ?? '',
            'sku_count' => 0,
            'total_qty' => 0.0,
            'total_value' => 0.0,
        ];
    }
    $valuationByLocation[$locId]['sku_count'] += 1;
    $valuationByLocation[$locId]['total_qty'] += $onHand;
    $valuationByLocation[$locId]['total_value'] += $totalValue;
}

$valuation = array_values($valuationByLocation);
usort($valuation, static function (array $a, array $b): int {
    return $b['total_value'] <=> $a['total_value'];
});
$summary['location_count'] = count($valuation);

usort($lowStock, static function (array $a, array $b): int {
    return $a['available_quantity'] <=> $b['available_quantity'];
});

$topValue = array_slice(array_map(static function (array $row): array {
    $onHand = inventory_api_num($row['quantity_on_hand'] ?? 0);
    $unitCost = inventory_api_num($row['unit_cost'] ?? 0);
    return [
        'item_name' => $row['item_name'],
        'sku' => $row['sku'],
        'location_name' => $row['location_name'] ?? $row['location_code'] ?? '',
        'quantity_on_hand' => $onHand,
        'unit_cost' => $unitCost,
        'total_value' => inventory_api_num($row['total_value'] ?? ($onHand * $unitCost)),
    ];
}, $allRows), 0, 50);

api_json([
    'success' => true,
    'filters' => [
        'location_id' => $locationId,
        'threshold' => $threshold,
        'q' => $q,
    ],
    'summary' => $summary,
    'low_stock' => $lowStock,
    'valuation_by_location' => $valuation,
    'top_value' => $topValue,
    'location_options' => inventory_location_options($pdo),
]);
