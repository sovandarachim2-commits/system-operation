<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_common.php';

$pdo = get_db_connection();
require_role_or_permission(
    ['admin'],
    'sr_inventory_movements.view',
    'sr_inventory_adjustment.view',
    'inventory.view',
    'stock_operations.view',
    'stock_dashboard.view',
    'stock_reports.view'
);

$from = inventory_api_date($_GET['from'] ?? null, date('Y-m-01'));
$to = inventory_api_date($_GET['to'] ?? null, date('Y-m-d'));
$locationId = inventory_api_int($_GET['location_id'] ?? 0);
$movementType = inventory_api_str($_GET['movement_type'] ?? '');
$q = inventory_api_str($_GET['q'] ?? '');
$limit = max(1, min(1000, inventory_api_int($_GET['limit'] ?? 200)));

$rangeStart = $from . ' 00:00:00';
$rangeEndEx = date('Y-m-d H:i:s', strtotime($to . ' +1 day'));

$locationMap = [];
foreach (inventory_location_options($pdo) as $loc) {
    $locationMap[(int)$loc['value']] = (string)$loc['label'];
}

$movements = [];

try {
    $sql = '
        SELECT
            im.id,
            im.item_name,
            im.sku,
            im.movement_type,
            im.quantity,
            im.unit_cost,
            im.total_cost,
            im.from_location_id,
            im.to_location_id,
            im.reference_type,
            im.reference_id,
            im.reference_no,
            im.reason AS notes,
            im.movement_date,
            im.created_at,
            u.name AS created_by_name,
            u.username AS created_by_username,
            \'inventory_movements\' AS source
        FROM inventory_movements im
        LEFT JOIN users u ON u.id = im.user_id
        WHERE im.movement_date >= ? AND im.movement_date <= ?
    ';
    $params = [$from, $to];
    if ($locationId > 0) {
        $sql .= ' AND (im.from_location_id = ? OR im.to_location_id = ?)';
        $params[] = $locationId;
        $params[] = $locationId;
    }
    if ($movementType !== '') {
        if ($movementType === 'transfer') {
            $sql .= " AND im.movement_type IN ('transfer', 'transfer_in', 'transfer_out')";
        } else {
            $sql .= ' AND im.movement_type = ?';
            $params[] = $movementType;
        }
    }
    if ($q !== '') {
        $sql .= ' AND (im.item_name LIKE ? OR im.sku LIKE ? OR im.reference_no LIKE ? OR im.reason LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY im.movement_date DESC, im.id DESC LIMIT ' . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fromId = (int)($row['from_location_id'] ?? 0);
        $toId = (int)($row['to_location_id'] ?? 0);
        $type = (string)($row['movement_type'] ?? '');
        $qty = inventory_api_num($row['quantity'] ?? 0);
        $locationLabel = '';
        if ($toId > 0 && in_array($type, ['purchase_in', 'transfer_in', 'return_in', 'adjustment'], true)) {
            $locationLabel = $locationMap[$toId] ?? ('Location #' . $toId);
        } elseif ($fromId > 0) {
            $locationLabel = $locationMap[$fromId] ?? ('Location #' . $fromId);
        } elseif ($toId > 0) {
            $locationLabel = $locationMap[$toId] ?? ('Location #' . $toId);
        }

        $refParts = array_filter([
            inventory_api_str($row['reference_type'] ?? ''),
            inventory_api_str($row['reference_no'] ?? '') !== ''
                ? inventory_api_str($row['reference_no'])
                : ((int)($row['reference_id'] ?? 0) > 0 ? '#' . (int)$row['reference_id'] : ''),
        ]);

        $movements[] = [
            'id' => 'im-' . $row['id'],
            'item_name' => $row['item_name'],
            'sku' => $row['sku'],
            'movement_type' => $type,
            'movement_type_label' => inventory_movement_type_label($type),
            'quantity' => $qty,
            'previous_stock' => null,
            'new_stock' => null,
            'unit_cost' => inventory_api_num($row['unit_cost'] ?? 0),
            'total_cost' => inventory_api_num($row['total_cost'] ?? 0),
            'location' => $locationLabel,
            'from_location_id' => $fromId,
            'to_location_id' => $toId,
            'from_location' => $fromId ? ($locationMap[$fromId] ?? ('Location #' . $fromId)) : '',
            'to_location' => $toId ? ($locationMap[$toId] ?? ('Location #' . $toId)) : '',
            'reference_type' => $row['reference_type'],
            'reference_id' => $row['reference_id'],
            'reference' => implode(' ', $refParts),
            'document_code' => (static function () use ($row): string {
                $no = inventory_api_str($row['reference_no'] ?? '');
                if ($no !== '' && !in_array(strtolower($no), ['transfer', 'adjustment', 'in', 'out'], true)) {
                    return $no;
                }
                return '';
            })(),
            'notes' => inventory_notes_display((string)($row['notes'] ?? ''), $locationMap),
            'movement_date' => $row['movement_date'] ?: $row['created_at'],
            'created_by' => inventory_user_display_label(
                (string)($row['created_by_username'] ?? ''),
                (string)($row['created_by_name'] ?? '')
            ),
            'created_by_name' => inventory_user_display_label(
                (string)($row['created_by_username'] ?? ''),
                (string)($row['created_by_name'] ?? '')
            ),
            'reference_files' => [],
            'reference_file_urls' => [],
            'source' => 'inventory_movements',
        ];
    }
} catch (Throwable $e) {
    // inventory_movements may be missing on older installs
}

try {
    $cols = $pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(PDO::FETCH_COLUMN);
    $colsLc = array_map('strtolower', array_map('strval', $cols));
    $itemCol = in_array('item_id', $colsLc, true) ? 'item_id' : (in_array('product_id', $colsLc, true) ? 'product_id' : null);
    $dateCol = in_array('created_at', $colsLc, true) ? 'created_at' : (in_array('movement_date', $colsLc, true) ? 'movement_date' : null);
    $hasFrom = in_array('from_storage_location_id', $colsLc, true);
    $hasTo = in_array('to_storage_location_id', $colsLc, true);
    $hasNotes = in_array('notes', $colsLc, true);
    $hasUnitCost = in_array('unit_cost', $colsLc, true);
    $hasTotalCost = in_array('total_cost', $colsLc, true);
    $hasRefType = in_array('reference_type', $colsLc, true);
    $hasRefId = in_array('reference_id', $colsLc, true);
    $hasPrevious = in_array('previous_stock', $colsLc, true);
    $hasNewStock = in_array('new_stock', $colsLc, true);
    $hasCreatedBy = in_array('created_by', $colsLc, true);
    $hasReferenceFiles = in_array('reference_files', $colsLc, true);

    if ($itemCol && $dateCol) {
        $selectExtra = '';
        if ($hasFrom) {
            $selectExtra .= ', sm.from_storage_location_id';
        }
        if ($hasTo) {
            $selectExtra .= ', sm.to_storage_location_id';
        }
        if ($hasNotes) {
            $selectExtra .= ', sm.notes';
        }
        if ($hasUnitCost) {
            $selectExtra .= ', sm.unit_cost';
        }
        if ($hasTotalCost) {
            $selectExtra .= ', sm.total_cost';
        }
        if ($hasRefType) {
            $selectExtra .= ', sm.reference_type';
        }
        if ($hasRefId) {
            $selectExtra .= ', sm.reference_id';
        }
        if ($hasPrevious) {
            $selectExtra .= ', sm.previous_stock';
        }
        if ($hasNewStock) {
            $selectExtra .= ', sm.new_stock';
        }
        if ($hasCreatedBy) {
            $selectExtra .= ', sm.created_by';
        }
        if ($hasReferenceFiles) {
            $selectExtra .= ', sm.reference_files';
        }

        $sql = "
            SELECT
                sm.id,
                sm.{$itemCol} AS product_id,
                COALESCE(NULLIF(TRIM(p.name), ''), CONCAT('Product #', sm.{$itemCol})) AS item_name,
                p.sku,
                sm.movement_type,
                sm.quantity,
                sm.{$dateCol} AS movement_date
                {$selectExtra}
            FROM stock_movements sm
            LEFT JOIN products p ON p.id = sm.{$itemCol}
            WHERE sm.{$dateCol} >= ? AND sm.{$dateCol} < ?
        ";
        $params = [$rangeStart, $rangeEndEx];
        if ($locationId > 0) {
            $parts = [];
            if ($hasFrom) {
                $parts[] = 'sm.from_storage_location_id = ?';
                $params[] = $locationId;
            }
            if ($hasTo) {
                $parts[] = 'sm.to_storage_location_id = ?';
                $params[] = $locationId;
            }
            if ($parts) {
                $sql .= ' AND (' . implode(' OR ', $parts) . ')';
            }
        }
        if ($movementType !== '') {
            if ($movementType === 'transfer') {
                $sql .= " AND sm.movement_type IN ('transfer', 'transfer_in', 'transfer_out')";
            } else {
                $sql .= ' AND sm.movement_type = ?';
                $params[] = $movementType;
            }
        }
        if ($q !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR sm.' . $itemCol . ' = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = is_numeric($q) ? (int)$q : 0;
        }
        $sql .= ' ORDER BY sm.' . $dateCol . ' DESC, sm.id DESC LIMIT ' . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fromId = $hasFrom ? (int)($row['from_storage_location_id'] ?? 0) : 0;
            $toId = $hasTo ? (int)($row['to_storage_location_id'] ?? 0) : 0;
            $type = (string)($row['movement_type'] ?? '');
            $qty = inventory_api_num($row['quantity'] ?? 0);
            $locationLabel = '';
            if ($toId > 0) {
                $locationLabel = $locationMap[$toId] ?? ('Location #' . $toId);
            } elseif ($fromId > 0) {
                $locationLabel = $locationMap[$fromId] ?? ('Location #' . $fromId);
            }
            $refType = $hasRefType ? (string)($row['reference_type'] ?? '') : '';
            $refId = $hasRefId ? (string)($row['reference_id'] ?? '') : '';
            $files = $hasReferenceFiles ? inventory_api_stored_files($row['reference_files'] ?? '') : [];
            $createdByName = $hasCreatedBy
                ? inventory_resolve_user_name($pdo, (string)($row['created_by'] ?? ''))
                : '';
            $movements[] = [
                'id' => 'sm-' . $row['id'],
                'item_name' => $row['item_name'],
                'sku' => $row['sku'] ?? '',
                'movement_type' => $type,
                'movement_type_label' => inventory_movement_type_label($type),
                'quantity' => $qty,
                'previous_stock' => $hasPrevious ? inventory_api_num($row['previous_stock'] ?? 0) : null,
                'new_stock' => $hasNewStock ? inventory_api_num($row['new_stock'] ?? 0) : null,
                'unit_cost' => $hasUnitCost ? inventory_api_num($row['unit_cost'] ?? 0) : 0,
                'total_cost' => $hasTotalCost ? inventory_api_num($row['total_cost'] ?? 0) : 0,
                'location' => $locationLabel,
                'from_location_id' => $fromId,
                'to_location_id' => $toId,
                'from_location' => $fromId ? ($locationMap[$fromId] ?? ('Location #' . $fromId)) : '',
                'to_location' => $toId ? ($locationMap[$toId] ?? ('Location #' . $toId)) : '',
                'reference_type' => $refType,
                'reference_id' => $refId,
                'reference' => trim($refType . ($refId !== '' ? ' #' . $refId : '')),
                'document_code' => (
                    $refId !== ''
                    && !in_array(strtolower($refId), ['transfer', 'adjustment', 'in', 'out'], true)
                ) ? $refId : '',
                'notes' => $hasNotes ? inventory_notes_display((string)($row['notes'] ?? ''), $locationMap) : '',
                'movement_date' => $row['movement_date'],
                'created_by' => $createdByName,
                'created_by_name' => $createdByName,
                'reference_files' => $files,
                'reference_file_urls' => inventory_api_reference_file_urls($files),
                'source' => 'stock_movements',
            ];
        }
    }
} catch (Throwable $e) {
    // optional
}

if ($movementType === 'transfer') {
    $stockTransferCodes = [];
    foreach ($movements as $row) {
        if (($row['source'] ?? '') !== 'stock_movements') {
            continue;
        }
        $code = inventory_api_str($row['document_code'] ?? '');
        if ($code !== '') {
            $stockTransferCodes[strtolower($code)] = true;
        }
    }

    if ($stockTransferCodes) {
        $movements = array_values(array_filter($movements, static function (array $row) use ($stockTransferCodes): bool {
            if (($row['source'] ?? '') !== 'inventory_movements') {
                return true;
            }
            $type = strtolower(inventory_api_str($row['movement_type'] ?? ''));
            if (!in_array($type, ['transfer', 'transfer_in', 'transfer_out'], true)) {
                return true;
            }
            $code = strtolower(inventory_api_str($row['document_code'] ?? ''));
            return $code === '' || !isset($stockTransferCodes[$code]);
        }));
    }
}

$stockMovementKeys = [];
foreach ($movements as $row) {
    if (($row['source'] ?? '') !== 'stock_movements') {
        continue;
    }
    $key = inventory_movement_duplicate_key($row);
    if ($key !== '') {
        $stockMovementKeys[$key] = true;
    }
}
if ($stockMovementKeys) {
    $movements = array_values(array_filter($movements, static function (array $row) use ($stockMovementKeys): bool {
        if (($row['source'] ?? '') !== 'inventory_movements') {
            return true;
        }
        $key = inventory_movement_duplicate_key($row);
        return $key === '' || !isset($stockMovementKeys[$key]);
    }));
}

$stockDocumentCodes = [];
foreach ($movements as $row) {
    if (($row['source'] ?? '') !== 'stock_movements') {
        continue;
    }
    $code = strtolower(inventory_api_str($row['document_code'] ?? ''));
    if ($code !== '') {
        $stockDocumentCodes[$code] = true;
    }
}
if ($stockDocumentCodes) {
    $movements = array_values(array_filter($movements, static function (array $row) use ($stockDocumentCodes): bool {
        if (($row['source'] ?? '') !== 'inventory_movements') {
            return true;
        }
        $code = strtolower(inventory_api_str($row['document_code'] ?? ''));
        if ($code === '' || empty($stockDocumentCodes[$code])) {
            return true;
        }
        return $row['previous_stock'] !== null || $row['new_stock'] !== null;
    }));
}

usort($movements, static function (array $a, array $b): int {
    return strcmp((string)$b['movement_date'], (string)$a['movement_date']);
});
$movements = array_slice($movements, 0, $limit);

$summary = [
    'movement_count' => count($movements),
    'qty_in' => 0.0,
    'qty_out' => 0.0,
    'total_cost' => 0.0,
];
$outTypes = ['out', 'sale_out', 'transfer_out', 'damage_out', 'purchase_return'];
foreach ($movements as $row) {
    $qty = inventory_api_num($row['quantity'] ?? 0);
    $type = strtolower((string)($row['movement_type'] ?? ''));
    if (in_array($type, $outTypes, true) || $qty < 0) {
        $summary['qty_out'] += abs($qty);
    } else {
        $summary['qty_in'] += abs($qty);
    }
    $summary['total_cost'] += inventory_api_num($row['total_cost'] ?? 0);
}

$typeOptions = [
    ['value' => 'purchase_in', 'label' => 'Received'],
    ['value' => 'in', 'label' => 'Stock In'],
    ['value' => 'out', 'label' => 'Stock Out'],
    ['value' => 'sale_out', 'label' => 'Sale Out'],
    ['value' => 'transfer', 'label' => 'Transfer'],
    ['value' => 'transfer_in', 'label' => 'Transfer In'],
    ['value' => 'transfer_out', 'label' => 'Transfer Out'],
    ['value' => 'adjustment', 'label' => 'Adjustment'],
    ['value' => 'return_in', 'label' => 'Return In'],
    ['value' => 'damage_out', 'label' => 'Damage Out'],
];

function inventory_movement_duplicate_key(array $row): string
{
    $type = strtolower(inventory_api_str($row['movement_type'] ?? ''));
    $normalizedType = [
        'sale_out' => 'out',
        'purchase_in' => 'in',
        'return_in' => 'in',
        'transfer_out' => 'transfer',
        'transfer_in' => 'transfer',
    ][$type] ?? $type;
    if (!in_array($normalizedType, ['in', 'out', 'adjustment', 'transfer'], true)) {
        return '';
    }

    $documentCode = strtolower(inventory_api_str($row['document_code'] ?? ''));
    $itemName = strtolower(trim((string)($row['item_name'] ?? '')));
    $sku = strtolower(trim((string)($row['sku'] ?? '')));
    $quantity = number_format(abs(inventory_api_num($row['quantity'] ?? 0)), 4, '.', '');
    $date = substr((string)($row['movement_date'] ?? ''), 0, 10);

    if ($documentCode === '' || $itemName === '' || $quantity === '0.0000') {
        return '';
    }

    return implode('|', [$normalizedType, $documentCode, $sku, $itemName, $quantity, $date]);
}

api_json([
    'success' => true,
    'filters' => [
        'from' => $from,
        'to' => $to,
        'location_id' => $locationId,
        'movement_type' => $movementType,
        'q' => $q,
        'limit' => $limit,
    ],
    'summary' => $summary,
    'rows' => $movements,
    'location_options' => array_values(array_map(static fn ($loc) => [
        'value' => $loc['value'],
        'label' => $loc['label'],
    ], inventory_location_options($pdo))),
    'movement_type_options' => $typeOptions,
]);
