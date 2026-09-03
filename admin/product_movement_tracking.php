<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'stock_operations.view');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$userRoles = $currentUser ? user_role_names($pdo, $currentUser) : [];
$isAdmin = in_array('admin', $userRoles, true);
$canViewAllMarkets = $isAdmin || (function_exists('has_permission') && has_permission('marketing_take_view_all.view'));

// Filters
$quick_filter = trim((string)($_GET['quick_filter'] ?? 'today'));
if (!in_array($quick_filter, ['today', 'yesterday', 'custom'], true)) {
    $quick_filter = 'today';
}

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to'] ?? date('Y-m-d');
$product_filter = trim($_GET['product_filter'] ?? '');
$status_filter = trim($_GET['status_filter'] ?? '');
$location_filter = (int)($_GET['location_filter'] ?? 0);

if ($quick_filter === 'today') {
    $date_from = date('Y-m-d');
    $date_to = date('Y-m-d');
} elseif ($quick_filter === 'yesterday') {
    $date_from = date('Y-m-d', strtotime('-1 day'));
    $date_to = $date_from;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = date('Y-m-d');
}
if (strcmp($date_from, $date_to) > 0) {
    $tmp = $date_from;
    $date_from = $date_to;
    $date_to = $tmp;
}
// Inclusive calendar range as [start, end) in server time — avoids DATE() / TZ surprises vs DATE(col) BETWEEN
$rangeStart = $date_from . ' 00:00:00';
$rangeEndEx = date('Y-m-d H:i:s', strtotime($date_to . ' +1 day'));

$movements = [];
$trackingLoadErrors = [];
$productOptions = [];

$locationMap = [];
try {
    $locRows = $pdo->query('SELECT id, location_code, location_name FROM storage_locations WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($locRows as $lr) {
        $locationMap[(int)$lr['id']] = trim(($lr['location_code'] ?? '') . ' ' . ($lr['location_name'] ?? ''));
    }
} catch (Throwable $e) {
}

try {
    $optionQueries = [
        "SELECT DISTINCT name AS item_name FROM products WHERE name IS NOT NULL AND TRIM(name) <> ''",
        "SELECT DISTINCT set_name AS item_name FROM product_sets WHERE set_name IS NOT NULL AND TRIM(set_name) <> ''",
        "SELECT DISTINCT item_name FROM inventory_movements WHERE item_name IS NOT NULL AND TRIM(item_name) <> ''",
        "SELECT DISTINCT item_name FROM current_inventory WHERE item_name IS NOT NULL AND TRIM(item_name) <> ''",
    ];

    foreach ($optionQueries as $optionSql) {
        foreach ($pdo->query($optionSql)->fetchAll(PDO::FETCH_COLUMN) as $optionName) {
            $optionName = trim((string)$optionName);
            if ($optionName !== '') {
                $productOptions[mb_strtolower($optionName)] = $optionName;
            }
        }
    }
    natcasesort($productOptions);
    $productOptions = array_values($productOptions);
} catch (Throwable $e) {
    $trackingLoadErrors[] = 'product dropdown: ' . $e->getMessage();
}

// Map internal types to user-friendly labels
$statusLabels = [
    'in' => 'Stock In',
    'out' => 'Stock Out',
    'transfer' => 'Transfer',
    'adjustment' => 'Adjustment',
    'purchase_in' => 'Received',
    'purchase_return' => 'Return (to vendor)',
    'purchase_return_reversal' => 'Return Reversal',
    'inbound' => 'Inbound',
    'outbound' => 'Outbound',
    'set_outbound' => 'Set Out',
    'set_inbound' => 'Set In (Return)',
    'return_in' => 'Order Return',
    'return_component_in' => 'Component Return',
    'return_component_out' => 'Component Return (Reversal)',
    'return_reversal_out' => 'Order Return (Reversal)',
    'transfer_in' => 'Transfer In',
    'transfer_out' => 'Transfer Out',
    'set_creation' => 'Set Creation',
    'set_addition' => 'Set Addition',
    'set_auto_creation_component_out' => 'Auto Create (Component Out)',
    'set_auto_created' => 'Auto Create (Set)',
    'auto_created' => 'Auto Create',
    'marketing_outbound' => 'Marketing Take Out',
    'marketing_return' => 'Marketing Return',
    'marketing_reversal' => 'Approval Reversed (Stock Returned)',
    'marketing_writeoff' => 'Marketing Write-off',
    'offline_sale' => 'Offline Sale',
    'purchase_back' => 'Offline Purchase Back',
    'cancelled_offline_sale' => 'Cancelled Offline Sale',
    'cancelled_purchase_back' => 'Offline Cancelled Purchase Back',
];

/**
 * stock_movements / stock_operations often store outbound quantity as positive; show as negative for UI and totals.
 */
function normalize_movement_quantity_for_display(string $raw, float $qty): float {
    static $outwardPositive = null;
    if ($outwardPositive === null) {
        $outwardPositive = [
            'out', 'outbound', 'set_outbound', 'purchase_return',
            'return_component_out', 'return_reversal_out', 'set_auto_creation_component_out',
            'marketing_outbound',
            'offline_sale', 'cancelled_purchase_back',
        ];
    }
    if (in_array($raw, $outwardPositive, true) && $qty > 0) {
        return -abs($qty);
    }
    if ($raw === 'marketing_writeoff' && $qty > 0) {
        return -abs($qty);
    }
    return $qty;
}

// 1. Stock movements (stock_operations.php - in, out, transfer, adjustment)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM stock_movements")->fetchAll(PDO::FETCH_COLUMN);
    $colsLc = array_map('strtolower', array_map('strval', $cols));
    $hasItemId = in_array('item_id', $colsLc, true);
    $itemCol = $hasItemId ? 'item_id' : (in_array('product_id', $colsLc, true) ? 'product_id' : null);
    $dateColSm = in_array('created_at', $colsLc, true) ? 'created_at' : (in_array('movement_date', $colsLc, true) ? 'movement_date' : null);
    $hasSmCreatedBy = in_array('created_by', $colsLc, true);
    $hasSmFromLoc = in_array('from_storage_location_id', $colsLc, true);
    $hasSmToLoc = in_array('to_storage_location_id', $colsLc, true);
    $smLocSelect = '';
    if ($hasSmFromLoc) {
        $smLocSelect .= ', sm.from_storage_location_id';
    }
    if ($hasSmToLoc) {
        $smLocSelect .= ', sm.to_storage_location_id';
    }
    if ($itemCol !== null && $dateColSm !== null) {
        // Avoid LEFT JOIN users ... OR ... — mixed collation (utf8mb4 vs latin1) throws and this block was failing silently.
        if ($hasSmCreatedBy) {
            $createdByNameSql = "COALESCE(
                NULLIF((SELECT ux.name COLLATE utf8mb4_unicode_ci FROM users ux WHERE sm.created_by IS NOT NULL AND ux.username COLLATE utf8mb4_unicode_ci = sm.created_by COLLATE utf8mb4_unicode_ci LIMIT 1), ''),
                NULLIF((SELECT ux.name COLLATE utf8mb4_unicode_ci FROM users ux WHERE sm.created_by IS NOT NULL AND CAST(ux.id AS CHAR) COLLATE utf8mb4_unicode_ci = sm.created_by COLLATE utf8mb4_unicode_ci LIMIT 1), ''),
                sm.created_by COLLATE utf8mb4_unicode_ci
            )";
            $createdByColSql = 'sm.created_by';
        } else {
            $createdByNameSql = "''";
            $createdByColSql = "''";
        }
        $smSql = "
            SELECT 
                sm.id, sm.{$itemCol} as product_id,
                COALESCE(NULLIF(TRIM(p.name), ''), CONCAT('Product #', IFNULL(sm.{$itemCol}, 0))) as item_name,
                sm.movement_type, sm.quantity,
                sm.reference_type, sm.reference_id, sm.notes{$smLocSelect},
                sm.{$dateColSm} as movement_date,
                {$createdByNameSql} as created_by_name, {$createdByColSql} as created_by
            FROM stock_movements sm
            LEFT JOIN products p ON sm.{$itemCol} = p.id
            WHERE sm.{$dateColSm} >= ? AND sm.{$dateColSm} < ?
        ";
        $params = [$rangeStart, $rangeEndEx];
        if ($product_filter !== '') {
            $smSql .= " AND (p.name LIKE ? OR p.id = ? OR sm.{$itemCol} = ?)";
            $params[] = "%{$product_filter}%";
            $params[] = is_numeric($product_filter) ? $product_filter : 0;
            $params[] = is_numeric($product_filter) ? $product_filter : 0;
        }
        $smSql .= " ORDER BY sm.{$dateColSm} DESC";
        $stmt = $pdo->prepare($smSql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ((int)($row['product_id'] ?? 0) > 0 || (string)($row['item_name'] ?? '') !== '') {
                $mt = $row['movement_type'] ?? '';
                $notes = (string)($row['notes'] ?? '');
                $baseRef = ($row['reference_type'] ?? '') . (!empty($row['reference_id']) ? ' #' . $row['reference_id'] : '');
                $qtyAbs = abs((float)$row['quantity']);

                $fromIdCol = $hasSmFromLoc ? (int)($row['from_storage_location_id'] ?? 0) : 0;
                $toIdCol = $hasSmToLoc ? (int)($row['to_storage_location_id'] ?? 0) : 0;

                // Transfers: prefer DB columns; else parse notes [From:… To:…]
                if ($mt === 'transfer' && ($fromIdCol > 0 && $toIdCol > 0
                    || preg_match('/\[From:\s*(\d+)\s+To:\s*(\d+)\]/', $notes, $tm)
                    || preg_match('/\[From:(\d+) To:(\d+)\]/', $notes, $tm)
                )) {
                    $fromId = $fromIdCol > 0 ? $fromIdCol : (int)$tm[1];
                    $toId = $toIdCol > 0 ? $toIdCol : (int)$tm[2];
                    $fromLabel = $locationMap[$fromId] ?? ('Location #' . $fromId);
                    $toLabel = $locationMap[$toId] ?? ('Location #' . $toId);
                    $by = $row['created_by_name'] ?? $row['created_by'] ?? '';
                    $movements[] = [
                        'source' => 'stock_movements',
                        'item_name' => $row['item_name'],
                        'status' => $statusLabels['transfer_out'],
                        'status_raw' => 'transfer_out',
                        'quantity' => -$qtyAbs,
                        'location' => $fromLabel,
                        'storage_location_id' => $fromId,
                        'movement_date' => $row['movement_date'],
                        'reference' => $baseRef,
                        'notes' => $notes,
                        'created_by' => $by,
                    ];
                    $movements[] = [
                        'source' => 'stock_movements',
                        'item_name' => $row['item_name'],
                        'status' => $statusLabels['transfer_in'],
                        'status_raw' => 'transfer_in',
                        'quantity' => $qtyAbs,
                        'location' => $toLabel,
                        'storage_location_id' => $toId,
                        'movement_date' => $row['movement_date'],
                        'reference' => $baseRef,
                        'notes' => $notes,
                        'created_by' => $by,
                    ];
                    continue;
                }

                $locIdForFilter = null;
                if ($mt === 'in' && $toIdCol > 0) {
                    $locIdForFilter = $toIdCol;
                } elseif (in_array($mt, ['out', 'adjustment'], true) && $fromIdCol > 0) {
                    $locIdForFilter = $fromIdCol;
                } elseif ($mt !== 'transfer' && preg_match('/\[Location:\s*(\d+)\]/', $notes, $lm)) {
                    $locIdForFilter = (int)$lm[1];
                }
                $locLabel = null;
                if ($locIdForFilter !== null && $locIdForFilter > 0) {
                    $locLabel = $locationMap[$locIdForFilter] ?? ('Location #' . $locIdForFilter);
                }

                $referenceType = strtolower(trim((string)($row['reference_type'] ?? '')));
                if ($referenceType === '') {
                    if (str_starts_with($notes, 'Purchased from customer ')) {
                        $referenceType = 'offline_customer_purchase';
                    } elseif (str_starts_with($notes, 'Offline sale cancelled ')) {
                        $referenceType = 'offline_sale_cancel';
                    } elseif (str_starts_with($notes, 'Customer purchase cancelled ')) {
                        $referenceType = 'offline_customer_purchase_cancel';
                    } elseif (str_starts_with($notes, 'Offline sale edit restore ')) {
                        $referenceType = 'offline_sale_edit';
                    } elseif (str_starts_with($notes, 'Customer purchase edit reversal ')) {
                        $referenceType = 'offline_purchase_edit';
                    } elseif (str_starts_with($notes, 'Offline sale ')) {
                        $referenceType = 'offline_sale';
                    }
                }
                $displayType = match ($referenceType) {
                    'offline_sale' => 'offline_sale',
                    'offline_customer_purchase' => 'purchase_back',
                    'offline_sale_cancel', 'offline_sale_edit' => 'cancelled_offline_sale',
                    'offline_customer_purchase_cancel', 'offline_purchase_edit' => 'cancelled_purchase_back',
                    default => $mt,
                };

                $movements[] = [
                    'source' => 'stock_movements',
                    'item_name' => $row['item_name'],
                    'status' => $statusLabels[$displayType] ?? ($displayType !== '' ? $displayType : '-'),
                    'status_raw' => $displayType,
                    'quantity' => (float)$row['quantity'],
                    'location' => $locLabel,
                    'storage_location_id' => $locIdForFilter !== null && $locIdForFilter > 0 ? $locIdForFilter : null,
                    'movement_date' => $row['movement_date'],
                    'reference' => $baseRef,
                    'notes' => $notes,
                    'created_by' => $row['created_by_name'] ?? $row['created_by'] ?? '',
                ];
            }
        }
    }
} catch (Throwable $e) {
    $trackingLoadErrors[] = 'stock_movements: ' . $e->getMessage();
}

// 2. Inventory movements (purchase receiving, returns - purchase_in, purchase_return)
try {
    $imSql = "
        SELECT 
            im.id, im.item_name, im.movement_type, im.quantity,
            im.to_location_id as storage_location_id, im.reference_type, im.reference_id, im.reference_no, im.reason,
            im.movement_date,
            sl.location_code, sl.location_name,
            COALESCE(u.name, CAST(im.user_id AS CHAR)) as created_by_name
        FROM inventory_movements im
        LEFT JOIN storage_locations sl ON im.to_location_id = sl.id
        LEFT JOIN users u ON u.id = im.user_id
        WHERE im.movement_date >= ? AND im.movement_date < ?
    ";
    $params = [$rangeStart, $rangeEndEx];
    if ($product_filter !== '') {
        $imSql .= " AND im.item_name LIKE ?";
        $params[] = "%{$product_filter}%";
    }
    if ($location_filter > 0) {
        $imSql .= " AND im.to_location_id = ?";
        $params[] = $location_filter;
    }
    $imSql .= " ORDER BY im.movement_date DESC";
    $stmt = $pdo->prepare($imSql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $locName = null;
        if (!empty($row['storage_location_id'])) {
            $locName = trim(($row['location_code'] ?? '') . ' ' . ($row['location_name'] ?? ''));
        }
        $ref = ($row['reference_no'] ?: $row['reference_type']) . ($row['reference_id'] ? ' #' . $row['reference_id'] : '');
        $mt = $row['movement_type'] ?? '';
        $movements[] = [
            'source' => 'inventory_movements',
            'item_name' => $row['item_name'],
            'status' => $statusLabels[$mt] ?? ($mt !== '' ? $mt : '-'),
            'status_raw' => $mt,
            'quantity' => (float)$row['quantity'],
            'location' => $locName,
            'movement_date' => $row['movement_date'],
            'reference' => $ref,
            'notes' => $row['reason'] ?? '',
            'created_by' => $row['created_by_name'] ?? '',
        ];
    }
} catch (Throwable $e) {
    $trackingLoadErrors[] = 'inventory_movements: ' . $e->getMessage();
}

// 3. Stock operations (order print, returns, transfer, adjustment)
try {
    $soCols = $pdo->query("SHOW COLUMNS FROM stock_operations")->fetchAll(PDO::FETCH_COLUMN);
    $hasProductId = in_array('product_id', $soCols);
    $soSql = "
        SELECT 
            so.id, so.product_id, so.storage_location_id, so.operation_type,
            so.quantity, so.reference_type, so.reference_id, so.notes, so.created_at as movement_date,
            p.name as product_name,
            sl.location_code, sl.location_name,
            COALESCE(u.name, CAST(so.created_by AS CHAR)) as created_by_name
        FROM stock_operations so
        LEFT JOIN products p ON so.product_id = p.id
        LEFT JOIN storage_locations sl ON so.storage_location_id = sl.id
        LEFT JOIN users u ON u.id = so.created_by
        LEFT JOIN marketing_takes mt ON so.reference_type = 'marketing_take' AND so.reference_id = mt.id
        WHERE so.created_at >= ? AND so.created_at < ?
    ";
    $params = [$rangeStart, $rangeEndEx];
    if (!$canViewAllMarkets && $userId > 0) {
        $soSql .= " AND (so.reference_type != 'marketing_take' OR mt.created_by = ?)";
        $params[] = $userId;
    }
    if ($product_filter !== '') {
        $soSql .= " AND (p.name LIKE ? OR (so.notes LIKE ? AND so.product_id IS NULL))";
        $params[] = "%{$product_filter}%";
        $params[] = "%{$product_filter}%";
    }
    if ($location_filter > 0) {
        $soSql .= " AND so.storage_location_id = ?";
        $params[] = $location_filter;
    }
    $soSql .= " ORDER BY so.created_at DESC";
    $stmt = $pdo->prepare($soSql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $itemName = $row['product_name'];
        if (empty($itemName) && !empty($row['notes'])) {
            // Extract from "Product set sold: X" or "Product set return: X" or similar
            if (preg_match('/Product set (?:sold|return):\s*(.+)/', $row['notes'], $m)) {
                $itemName = trim($m[1]);
            } elseif (preg_match('/Set[^:]*:\s*(.+)/', $row['notes'], $m)) {
                $itemName = trim($m[1]);
            } elseif (preg_match('/for order .+? \- (.+)$/', $row['notes'], $m)) {
                // "Returned set component restored for order SHA-xxx - ProductName" or "Returned product restored for order SHA-xxx - ProductName"
                $itemName = trim($m[1]);
            } elseif (preg_match('/Auto-created set component usage for (.+)$/', $row['notes'], $m)) {
                // "Auto-created set component usage for SetName - ComponentName"
                $itemName = trim($m[1]);
            } elseif (preg_match('/Auto-created missing set stock during print for (.+)$/', $row['notes'], $m)) {
                $itemName = trim($m[1]);
            } elseif (preg_match('/Auto-created missing set stock during order edit for (.+)$/', $row['notes'], $m)) {
                $itemName = trim($m[1]);
            } elseif (preg_match('/Auto-created [0-9.]+ sets during print order for ([^(]+)/', $row['notes'], $m)) {
                // "Auto-created N sets during print order for ProductName (storage_location_id:...)"
                $itemName = trim($m[1]);
            } elseif (preg_match('/Marketing (?:take|return|write-off)\s+(?:MT-[^\s]+\s+)?for\s+[^:]+:\s*(.+?)(?:\s*\(|$)/', $row['notes'], $m)) {
                // "Marketing take MT-xxx for EventName: ProductName" or legacy "Marketing take for EventName: ProductName"
                $itemName = trim($m[1]);
            } elseif (preg_match('/Marketing approval reversed\s+(?:MT-[^\s]+\s+)?for\s+[^:]+:\s*(.+?)(?:\s*\(|$)/', $row['notes'], $m)) {
                // "Marketing approval reversed MT-xxx for EventName: ProductName (reason: ...)"
                $itemName = trim($m[1]);
            }
        }
        if ($itemName === '' && !empty($row['notes'])) {
            $itemName = '(From notes)';
        }
        if ($itemName !== '') {
            $locName = null;
            if (!empty($row['storage_location_id'])) {
                $locName = trim(($row['location_code'] ?? '') . ' ' . ($row['location_name'] ?? ''));
            }
            $ref = ($row['reference_type'] ?? '') . ($row['reference_id'] ? ' #' . $row['reference_id'] : '');
            $opType = $row['operation_type'] ?? '';
            // Infer status from notes when operation_type is empty (e.g. old records)
            if ($opType === '' && !empty($row['notes'])) {
                $notes = $row['notes'];
                if (stripos($notes, 'Reversed returned set component') !== false) {
                    $opType = 'return_component_out';
                } elseif (stripos($notes, 'Reversed returned product') !== false) {
                    $opType = 'return_reversal_out';
                } elseif (stripos($notes, 'Returned set component') !== false) {
                    $opType = 'return_component_in';
                } elseif (stripos($notes, 'Returned product') !== false) {
                    $opType = 'return_in';
                } elseif (stripos($notes, 'Marketing write-off') !== false) {
                    $opType = 'marketing_writeoff';
                } elseif (stripos($notes, 'Marketing return') !== false) {
                    $opType = 'marketing_return';
                } elseif (stripos($notes, 'Marketing approval reversed') !== false) {
                    $opType = 'marketing_reversal';
                } elseif (stripos($notes, 'Marketing take') !== false) {
                    $opType = 'marketing_outbound';
                } elseif (stripos($notes, 'Product set sold') !== false) {
                    $opType = 'set_outbound';
                } elseif (stripos($notes, 'Product set return') !== false) {
                    $opType = 'set_inbound';
                } elseif (stripos($notes, 'Auto-created set component') !== false) {
                    $opType = 'set_auto_creation_component_out';
                } elseif (preg_match('/Auto-created\s+(?:missing\s+)?set/', $notes)) {
                    $opType = 'set_auto_created';
                } elseif (stripos($notes, 'Auto-created') !== false) {
                    $opType = 'auto_created';
                }
            }
            $soLocId = (int)($row['storage_location_id'] ?? 0);
            $movements[] = [
                'source' => 'stock_operations',
                'item_name' => $itemName,
                'status' => $statusLabels[$opType] ?? ($opType !== '' ? $opType : '-'),
                'status_raw' => $opType,
                'quantity' => (float)($row['quantity'] ?? 0),
                'location' => $locName,
                'storage_location_id' => $soLocId > 0 ? $soLocId : null,
                'movement_date' => $row['movement_date'],
                'reference' => $ref,
                'notes' => $row['notes'] ?? '',
                'created_by' => $row['created_by_name'] ?? '',
            ];
        }
    }
} catch (Throwable $e) {
    $trackingLoadErrors[] = 'stock_operations: ' . $e->getMessage();
}

// Location filter: match storage_location_id; stock_movements without a parsed location are dropped when filtering
if ($location_filter > 0) {
    $movements = array_values(array_filter($movements, static function ($m) use ($location_filter) {
        $sid = isset($m['storage_location_id']) && $m['storage_location_id'] !== null && $m['storage_location_id'] !== ''
            ? (int)$m['storage_location_id'] : 0;
        if ($sid === $location_filter) {
            return true;
        }
        if ($sid !== 0) {
            return false;
        }
        if (($m['source'] ?? '') !== 'stock_movements') {
            return true;
        }
        return false;
    }));
}

foreach ($movements as $k => $m) {
    $movements[$k]['quantity'] = normalize_movement_quantity_for_display(
        (string)($m['status_raw'] ?? ''),
        (float)($m['quantity'] ?? 0)
    );
}

// Filter by status if specified
if ($status_filter !== '') {
    $movements = array_filter($movements, function ($m) use ($status_filter) {
        return $m['status_raw'] === $status_filter || stripos($m['status'], $status_filter) !== false;
    });
}

// Sort all by date descending
usort($movements, function ($a, $b) {
    return strcmp($b['movement_date'], $a['movement_date']);
});

// Summary stats from movements
$inTypes = ['in', 'purchase_in', 'inbound', 'return_in', 'return_component_in', 'set_inbound', 'transfer_in', 'set_creation', 'set_addition', 'purchase_return_reversal', 'purchase_back', 'cancelled_offline_sale'];
$outTypes = ['out', 'outbound', 'set_outbound', 'transfer_out', 'purchase_return', 'offline_sale', 'cancelled_purchase_back'];

$totalIn = 0.0;
$totalOut = 0.0;
$receivedCount = 0;
$returnCount = 0;
$adjustmentCount = 0;
$transferCount = 0;

// Per-type summary: count, qty_in, qty_out for each status_raw
$summaryByType = [];
foreach ($statusLabels as $key => $label) {
    $summaryByType[$key] = ['label' => $label, 'count' => 0, 'qty_in' => 0.0, 'qty_out' => 0.0];
}
$summaryByProductStatus = [];

foreach ($movements as $m) {
    $qty = (float)$m['quantity'];
    $raw = $m['status_raw'];

    if ($qty > 0) {
        $totalIn += $qty;
    } else {
        $totalOut += abs($qty);
    }

    if (in_array($raw, ['purchase_in', 'inbound', 'return_in', 'return_component_in', 'set_inbound', 'set_creation', 'set_addition', 'purchase_return_reversal', 'purchase_back', 'cancelled_offline_sale'])) {
        $receivedCount++;
    } elseif (in_array($raw, ['purchase_return', 'outbound', 'set_outbound', 'return_component_out', 'return_reversal_out', 'set_auto_creation_component_out'])) {
        $returnCount++;
    } elseif ($raw === 'adjustment') {
        $adjustmentCount++;
    } elseif (in_array($raw, ['transfer', 'transfer_in', 'transfer_out'])) {
        $transferCount++;
    }

    $rawKey = ($raw === '' || $raw === null) ? '_unknown' : $raw;
    if (!isset($summaryByType[$rawKey])) {
        $label = trim($m['status'] ?? '');
        $summaryByType[$rawKey] = ['label' => $label !== '' ? $label : ($raw !== '' ? (string)$raw : '-'), 'count' => 0, 'qty_in' => 0.0, 'qty_out' => 0.0];
    }
    $summaryByType[$rawKey]['count']++;
    if ($qty > 0) {
        $summaryByType[$rawKey]['qty_in'] += $qty;
    } else {
        $summaryByType[$rawKey]['qty_out'] += abs($qty);
    }

    $productName = trim((string)($m['item_name'] ?? ''));
    if ($productName === '') {
        $productName = '-';
    }
    $productStatusKey = mb_strtolower($productName) . '|' . $rawKey;
    if (!isset($summaryByProductStatus[$productStatusKey])) {
        $summaryByProductStatus[$productStatusKey] = [
            'product' => $productName,
            'status_raw' => $rawKey,
            'status' => trim((string)($m['status'] ?? '')) !== '' ? (string)$m['status'] : ($raw !== '' ? (string)$raw : '-'),
            'count' => 0,
            'qty_in' => 0.0,
            'qty_out' => 0.0,
        ];
    }
    $summaryByProductStatus[$productStatusKey]['count']++;
    if ($qty > 0) {
        $summaryByProductStatus[$productStatusKey]['qty_in'] += $qty;
    } else {
        $summaryByProductStatus[$productStatusKey]['qty_out'] += abs($qty);
    }
}

usort($summaryByProductStatus, static function ($a, $b) {
    $productCompare = strcasecmp((string)$a['product'], (string)$b['product']);
    if ($productCompare !== 0) {
        return $productCompare;
    }
    return strcasecmp((string)$a['status'], (string)$b['status']);
});

$summaryByProductRows = [];
foreach ($summaryByProductStatus as $statusSummary) {
    $productKey = mb_strtolower((string)$statusSummary['product']);
    if (!isset($summaryByProductRows[$productKey])) {
        $summaryByProductRows[$productKey] = [
            'product' => (string)$statusSummary['product'],
            'statuses' => [],
            'count' => 0,
            'qty_in' => 0.0,
            'qty_out' => 0.0,
        ];
    }

    $summaryByProductRows[$productKey]['statuses'][] = $statusSummary;
    $summaryByProductRows[$productKey]['count'] += (int)$statusSummary['count'];
    $summaryByProductRows[$productKey]['qty_in'] += (float)$statusSummary['qty_in'];
    $summaryByProductRows[$productKey]['qty_out'] += (float)$statusSummary['qty_out'];
}
$summaryByProductRows = array_values($summaryByProductRows);

// Show all status types (including zeros). Order: statusLabels first, then any unknowns from data
$ordered = [];
foreach ($statusLabels as $key => $label) {
    $ordered[$key] = $summaryByType[$key] ?? ['label' => $label, 'count' => 0, 'qty_in' => 0.0, 'qty_out' => 0.0];
}
foreach ($summaryByType as $key => $s) {
    if (!isset($ordered[$key])) {
        $ordered[$key] = $s; // unknowns (e.g. _unknown)
    }
}
$summaryByType = $ordered;
$netChange = $totalIn - $totalOut;

// Get storage locations for filter
$locations = $pdo->query("SELECT id, location_code, location_name FROM storage_locations WHERE is_active = 1 ORDER BY location_code")->fetchAll(PDO::FETCH_ASSOC);

// Unique statuses for filter dropdown
$allStatuses = array_unique(array_column($movements, 'status_raw'));
sort($allStatuses);

$current = 'product_movement_tracking.php';
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
    .movement-dashboard-page {
        background: var(--report-bg);
    }
    .movement-dashboard-page .report-title {
        color: var(--report-title);
        font-weight: 700;
        letter-spacing: 0.01em;
    }
    .movement-dashboard-page .report-subtitle {
        color: var(--report-muted);
        font-size: 0.92rem;
    }
    .movement-dashboard-page .btn-soft {
        border-radius: 10px;
        font-weight: 600;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
    }
    .movement-dashboard-page .report-card {
        background: var(--report-card);
        border: 1px solid var(--report-border);
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 6px 20px rgba(30, 41, 59, 0.06);
    }
    .movement-dashboard-page .report-card-header {
        background: linear-gradient(180deg, #f9fafb 0%, #f4f6f9 100%);
        border-bottom: 1px solid var(--report-border);
        color: #1f2a37;
    }
    .movement-dashboard-page .metric-card {
        border: none;
        border-radius: 14px;
        color: #fff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.16);
    }
    .movement-dashboard-page .metric-primary { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }
    .movement-dashboard-page .metric-success { background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); }
    .movement-dashboard-page .metric-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
    .movement-dashboard-page .metric-info { background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%); }
    .movement-dashboard-page .metric-secondary { background: linear-gradient(135deg, #64748b 0%, #475569 100%); }
    .movement-dashboard-page .metric-danger { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); }
    .movement-dashboard-page .metric-label {
        font-size: 0.9rem;
        opacity: 0.92;
        margin-bottom: 0.35rem;
    }
    .movement-dashboard-page .metric-value {
        margin: 0;
        font-weight: 700;
        letter-spacing: 0.01em;
    }
    .movement-dashboard-page .table-clean thead th {
        background: #2f855a;
        color: #f4fff8;
        border-bottom: 0;
        border-color: transparent;
        font-weight: 600;
        white-space: nowrap;
        font-size: 1.02rem;
    }
    .movement-dashboard-page .table-clean tbody td {
        border-color: transparent;
        font-size: 0.98rem;
        vertical-align: middle;
        color: #000000;
    }
    .movement-dashboard-page .table-clean tbody td.text-end {
        color: #000000 !important;
        font-weight: 600;
    }
    .movement-dashboard-page .table-clean tfoot th,
    .movement-dashboard-page .table-clean tfoot td {
        background: #f3f4f6;
        color: #111827;
        border-top: 0;
        border-color: transparent;
        font-weight: 700;
    }
    .movement-dashboard-page .table-clean tbody tr:hover {
        background: #fdecc8 !important;
    }
    .movement-dashboard-page .product-summary-row:nth-child(odd) {
        background: #e0f2fe;
    }
    .movement-dashboard-page .product-summary-row:nth-child(even) {
        background: #ffe4e6;
    }
    .movement-dashboard-page .product-summary-row td {
        border-top: 3px solid #2f855a !important;
        border-bottom: 0 !important;
        box-shadow: inset 0 3px 0 #2f855a;
    }
    .movement-dashboard-page .product-summary-row td:first-child {
        border-left: 0 !important;
    }
    .movement-dashboard-page .product-summary-row td:last-child {
        border-right: 0 !important;
    }
    .movement-dashboard-page .product-summary-row:last-child td {
        border-bottom: 3px solid #2f855a !important;
        box-shadow: inset 0 3px 0 #2f855a, inset 0 -3px 0 #2f855a;
    }
    .movement-dashboard-page .product-status-line {
        border-color: rgba(47, 133, 90, 0.18) !important;
    }
</style>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4 flex-grow-1 movement-dashboard-page">
        <div class="row">
            <div class="col-12">
                <div class="report-topbar d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                    <div>
                        <h2 class="h4 mb-1 report-title"><i class="bi bi-diagram-3 me-2"></i>Product Movement Tracking</h2>
                        <div class="report-subtitle">Track stock movement by product, status, location, and date</div>
                    </div>
                    <a href="inventory_view.php" class="btn btn-success btn-soft">
                        <i class="bi bi-box-seam me-1"></i>Inventory View
                    </a>
                </div>

                <?php if (!$canViewAllMarkets && $userId > 0): ?>
                    <p class="text-muted mb-4"><span class="badge bg-secondary">Marketing movements: My requests only</span></p>
                <?php endif; ?>

                <?php if (!empty($trackingLoadErrors)): ?>
                <div class="alert alert-danger mb-4">
                    <strong>Could not load some movement sources.</strong> (Data may still appear from other tables.)
                    <ul class="mb-0 small">
                        <?php foreach ($trackingLoadErrors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ($location_filter > 0): ?>
                <div class="alert alert-info py-2 small mb-4">
                    <i class="bi bi-funnel me-1"></i>Location filter is on: Stock Operations rows without <code>[Location:…]</code> in notes are hidden. Choose <strong>All Locations</strong> to see every <code>stock_movements</code> line.
                </div>
                <?php endif; ?>

                <!-- Filters -->
                <div class="card report-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label">Quick Date</label>
                                <input type="hidden" name="quick_filter" id="quickFilter" value="<?= htmlspecialchars($quick_filter) ?>">
                                <div class="d-flex gap-2">
                                    <button type="submit"
                                            name="quick_filter"
                                            value="today"
                                            class="btn <?= $quick_filter === 'today' ? 'btn-primary' : 'btn-outline-primary' ?> flex-fill">
                                        Today
                                    </button>
                                    <button type="submit"
                                            name="quick_filter"
                                            value="yesterday"
                                            class="btn <?= $quick_filter === 'yesterday' ? 'btn-primary' : 'btn-outline-primary' ?> flex-fill">
                                        Yesterday
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date From</label>
                                <input type="date" name="date_from" id="dateFrom" class="form-control" value="<?= htmlspecialchars($date_from) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date To</label>
                                <input type="date" name="date_to" id="dateTo" class="form-control" value="<?= htmlspecialchars($date_to) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Product</label>
                                <select name="product_filter" class="form-select">
                                    <option value="">All Products</option>
                                    <?php foreach ($productOptions as $productOption): ?>
                                        <option value="<?= htmlspecialchars($productOption) ?>" <?= $product_filter === $productOption ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($productOption) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status_filter" class="form-select">
                                    <option value="">All Types</option>
                                    <?php foreach ($statusLabels as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>" <?= $status_filter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Location</label>
                                <select name="location_filter" class="form-select">
                                    <option value="0">All Locations</option>
                                    <?php foreach ($locations as $loc): ?>
                                        <option value="<?= (int)$loc['id'] ?>" <?= $location_filter === (int)$loc['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($loc['location_code'] . ' - ' . $loc['location_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-funnel me-1"></i>Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4 g-2">
                    <div class="col-6 col-md-4 col-lg">
                        <div class="card text-center metric-card metric-primary h-100">
                            <div class="card-body">
                                <i class="bi bi-list-ul fs-3 mb-2"></i>
                                <div>
                                    <div class="metric-label">Total Movements</div>
                                    <h3 class="metric-value"><?= number_format(count($movements)) ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg">
                        <div class="card text-center metric-card metric-success h-100">
                            <div class="card-body">
                                <i class="bi bi-box-arrow-in-down fs-3 mb-2"></i>
                                <div>
                                    <div class="metric-label">Stock In</div>
                                    <h3 class="metric-value">+<?= number_format($totalIn, 2) ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg">
                        <div class="card text-center metric-card metric-danger h-100">
                            <div class="card-body">
                                <i class="bi bi-box-arrow-up fs-3 mb-2"></i>
                                <div>
                                    <div class="metric-label">Stock Out</div>
                                    <h3 class="metric-value">-<?= number_format($totalOut, 2) ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg">
                        <div class="card text-center metric-card metric-info h-100">
                            <div class="card-body">
                                <i class="bi bi-arrow-left-right fs-3 mb-2"></i>
                                <div>
                                    <div class="metric-label">Net Change</div>
                                    <h3 class="metric-value"><?= $netChange >= 0 ? '+' : '' ?><?= number_format($netChange, 2) ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg">
                        <div class="card text-center metric-card metric-secondary h-100">
                            <div class="card-body">
                                <i class="bi bi-truck fs-3 mb-2"></i>
                                <div>
                                    <div class="metric-label">Received</div>
                                    <h3 class="metric-value"><?= number_format($receivedCount) ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg">
                        <div class="card text-center metric-card metric-warning h-100">
                            <div class="card-body">
                                <i class="bi bi-arrow-return-left fs-3 mb-2"></i>
                                <div>
                                    <div class="metric-label">Returns / Out</div>
                                    <h3 class="metric-value"><?= number_format($returnCount) ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary by Type -->
                <?php if (!empty($summaryByType)): ?>
                <?php
                $OUTLIER_THRESHOLD = 1000000;
                $hasOutlier = $totalOut > $OUTLIER_THRESHOLD || abs($netChange) > $OUTLIER_THRESHOLD;
                ?>
                <?php if ($hasOutlier): ?>
                <div class="alert alert-warning mb-4">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Unusual values detected.</strong> One or more movements have very large quantities (possibly data entry errors).
                    Check the <strong>Adjustment</strong> and other types in the table below and correct bad records in the source data.
                </div>
                <?php endif; ?>
                <div class="card report-card mb-4">
                    <div class="card-header report-card-header">
                        <h5 class="mb-0"><i class="bi bi-pie-chart text-success me-2"></i>Summary by Movement Type</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 table-clean">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th class="text-center">Count</th>
                                        <th class="text-end">Quantity In</th>
                                        <th class="text-end">Quantity Out</th>
                                        <th class="text-end">Net</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($summaryByType as $typeKey => $s): 
                                        $net = $s['qty_in'] - $s['qty_out'];
                                        $isOutlier = abs($net) > $OUTLIER_THRESHOLD || $s['qty_out'] > $OUTLIER_THRESHOLD || $s['qty_in'] > $OUTLIER_THRESHOLD;
                                        if ((int)$s['count'] === 0 && (float)$s['qty_in'] == 0.0 && (float)$s['qty_out'] == 0.0) {
                                            continue;
                                        }
                                    ?>
                                    <tr class="<?= $isOutlier ? 'table-warning' : '' ?>">
                                        <td>
                                            <?php
                                            $badge = 'bg-secondary';
                                            if (in_array($typeKey, ['in', 'purchase_in', 'inbound', 'return_in', 'return_component_in', 'set_inbound', 'transfer_in', 'set_creation', 'set_addition', 'purchase_return_reversal', 'marketing_return', 'purchase_back', 'cancelled_offline_sale'])) $badge = 'bg-success';
                                            elseif (in_array($typeKey, ['out', 'outbound', 'set_outbound', 'transfer_out', 'purchase_return', 'return_component_out', 'return_reversal_out', 'set_auto_creation_component_out', 'marketing_outbound', 'marketing_writeoff', 'offline_sale', 'cancelled_purchase_back'])) $badge = 'bg-danger';
                                            elseif (in_array($typeKey, ['transfer', 'transfer_in', 'transfer_out'])) $badge = 'bg-info';
                                            elseif (in_array($typeKey, ['adjustment', 'set_auto_created', 'auto_created'])) $badge = 'bg-warning text-dark';
                                            ?>
                                            <span class="badge <?= $badge ?>"><?= htmlspecialchars($s['label'] ?: '-') ?></span>
                                            <?php if ($isOutlier): ?><span class="badge bg-danger ms-1" title="Possible data error">!</span><?php endif; ?>
                                        </td>
                                        <td class="text-center"><?= number_format($s['count']) ?></td>
                                        <td class="text-end text-success"><?= $s['qty_in'] > 0 ? '+' : '' ?><?= number_format($s['qty_in'], 2) ?></td>
                                        <td class="text-end text-danger"><?= $s['qty_out'] > 0 ? '-' : '' ?><?= number_format($s['qty_out'], 2) ?></td>
                                        <td class="text-end fw-semibold"><?= $net >= 0 ? '+' : '' ?><?= number_format($net, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-secondary">
                                    <tr class="fw-bold">
                                        <td>Total</td>
                                        <td class="text-center"><?= number_format(count($movements)) ?></td>
                                        <td class="text-end text-success">+<?= number_format($totalIn, 2) ?></td>
                                        <td class="text-end text-danger">-<?= number_format($totalOut, 2) ?></td>
                                        <td class="text-end"><?= $netChange >= 0 ? '+' : '' ?><?= number_format($netChange, 2) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Summary by Product and Status -->
                <?php if (!empty($summaryByProductRows)): ?>
                <div class="card report-card mb-4">
                    <div class="card-header report-card-header">
                        <h5 class="mb-0"><i class="bi bi-box-seam text-success me-2"></i>Summary by Product and Status</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 table-clean">
                                <thead>
                                    <tr class="product-summary-row">
                                        <th>Product</th>
                                        <th>Status</th>
                                        <th class="text-center">Count</th>
                                        <th class="text-end">Quantity In</th>
                                        <th class="text-end">Quantity Out</th>
                                        <th class="text-end">Net</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($summaryByProductRows as $productRow):
                                        $productNet = (float)$productRow['qty_in'] - (float)$productRow['qty_out'];
                                    ?>
                                    <tr>
                                        <td class="fw-semibold align-middle"><?= htmlspecialchars((string)$productRow['product']) ?></td>
                                        <td>
                                            <?php foreach ($productRow['statuses'] as $s):
                                                $typeKey = (string)($s['status_raw'] ?? '');
                                                $badge = 'bg-secondary';
                                                if (in_array($typeKey, ['in', 'purchase_in', 'inbound', 'return_in', 'return_component_in', 'set_inbound', 'transfer_in', 'set_creation', 'set_addition', 'purchase_return_reversal', 'marketing_return', 'purchase_back', 'cancelled_offline_sale'])) $badge = 'bg-success';
                                                elseif (in_array($typeKey, ['out', 'outbound', 'set_outbound', 'transfer_out', 'purchase_return', 'return_component_out', 'return_reversal_out', 'set_auto_creation_component_out', 'marketing_outbound', 'marketing_writeoff', 'offline_sale', 'cancelled_purchase_back'])) $badge = 'bg-danger';
                                                elseif (in_array($typeKey, ['transfer', 'transfer_in', 'transfer_out'])) $badge = 'bg-info';
                                                elseif (in_array($typeKey, ['adjustment', 'set_auto_created', 'auto_created'])) $badge = 'bg-warning text-dark';
                                            ?>
                                                <div class="py-1 border-bottom product-status-line"><span class="badge <?= $badge ?>"><?= htmlspecialchars((string)$s['status']) ?></span></div>
                                            <?php endforeach; ?>
                                            <div class="pt-2 fw-semibold">Total</div>
                                        </td>
                                        <td class="text-center">
                                            <?php foreach ($productRow['statuses'] as $s): ?>
                                                <div class="py-1 border-bottom product-status-line"><?= number_format((int)$s['count']) ?></div>
                                            <?php endforeach; ?>
                                            <div class="pt-2 fw-semibold"><?= number_format((int)$productRow['count']) ?></div>
                                        </td>
                                        <td class="text-end text-success">
                                            <?php foreach ($productRow['statuses'] as $s): ?>
                                                <div class="py-1 border-bottom product-status-line"><?= $s['qty_in'] > 0 ? '+' : '' ?><?= number_format((float)$s['qty_in'], 2) ?></div>
                                            <?php endforeach; ?>
                                            <div class="pt-2 fw-semibold">+<?= number_format((float)$productRow['qty_in'], 2) ?></div>
                                        </td>
                                        <td class="text-end text-danger">
                                            <?php foreach ($productRow['statuses'] as $s): ?>
                                                <div class="py-1 border-bottom product-status-line"><?= $s['qty_out'] > 0 ? '-' : '' ?><?= number_format((float)$s['qty_out'], 2) ?></div>
                                            <?php endforeach; ?>
                                            <div class="pt-2 fw-semibold">-<?= number_format((float)$productRow['qty_out'], 2) ?></div>
                                        </td>
                                        <td class="text-end">
                                            <?php foreach ($productRow['statuses'] as $s):
                                                $net = (float)$s['qty_in'] - (float)$s['qty_out'];
                                            ?>
                                                <div class="py-1 border-bottom product-status-line"><?= $net >= 0 ? '+' : '' ?><?= number_format($net, 2) ?></div>
                                            <?php endforeach; ?>
                                            <div class="pt-2 fw-semibold"><?= $productNet >= 0 ? '+' : '' ?><?= number_format($productNet, 2) ?></div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-secondary">
                                    <tr class="fw-bold">
                                        <td colspan="2">Total</td>
                                        <td class="text-center"><?= number_format(count($movements)) ?></td>
                                        <td class="text-end text-success">+<?= number_format($totalIn, 2) ?></td>
                                        <td class="text-end text-danger">-<?= number_format($totalOut, 2) ?></td>
                                        <td class="text-end"><?= $netChange >= 0 ? '+' : '' ?><?= number_format($netChange, 2) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Results -->
                <div class="card report-card">
                    <div class="card-header report-card-header">
                        <strong><i class="bi bi-list-ul text-success me-2"></i>Movement History</strong>
                        <span class="badge bg-light text-dark ms-2"><?= number_format(count($movements)) ?> records</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($movements)): ?>
                            <p class="text-muted text-center py-5 mb-0">No movements found for the selected period.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover mb-0 table-clean">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Product</th>
                                            <th>Status</th>
                                            <th class="text-end">Quantity</th>
                                            <th>Storage Location</th>
                                            <th>Reference</th>
                                            <th>By</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($movements as $m): ?>
                                            <tr>
                                                <td class="text-nowrap"><?= date('M j, Y H:i', strtotime($m['movement_date'])) ?></td>
                                                <td><?= htmlspecialchars($m['item_name']) ?></td>
                                                <td>
                                                    <?php
                                                    $badge = 'bg-secondary';
                                                    if (in_array($m['status_raw'], ['in', 'purchase_in', 'inbound', 'return_in', 'return_component_in', 'set_inbound', 'transfer_in', 'set_creation', 'set_addition', 'purchase_return_reversal', 'marketing_return', 'purchase_back', 'cancelled_offline_sale'])) $badge = 'bg-success';
                                                    elseif (in_array($m['status_raw'], ['out', 'outbound', 'set_outbound', 'transfer_out', 'purchase_return', 'return_component_out', 'return_reversal_out', 'set_auto_creation_component_out', 'marketing_outbound', 'marketing_writeoff', 'offline_sale', 'cancelled_purchase_back'])) $badge = 'bg-danger';
                                                    elseif (in_array($m['status_raw'], ['transfer', 'transfer_in', 'transfer_out'])) $badge = 'bg-info';
                                                    elseif (in_array($m['status_raw'], ['adjustment', 'set_auto_created', 'auto_created'])) $badge = 'bg-warning text-dark';
                                                    $statusText = trim((string)($m['status'] ?? '')) !== '' ? ($m['status']) : (trim((string)($m['status_raw'] ?? '')) !== '' ? $m['status_raw'] : '-');
                                                    ?>
                                                    <span class="badge <?= $badge ?>"><?= htmlspecialchars($statusText) ?></span>
                                                </td>
                                                <td class="text-end"><?= $m['quantity'] >= 0 ? '+' : '' ?><?= number_format($m['quantity'], 2) ?></td>
                                                <td><?= htmlspecialchars($m['location'] ?? '-') ?></td>
                                                <td><small><?= htmlspecialchars($m['reference']) ?></small></td>
                                                <td><small><?= htmlspecialchars($m['created_by']) ?></small></td>
                                                <td><small class="text-muted"><?= htmlspecialchars(mb_substr($m['notes'] ?? '', 0, 60)) ?><?= mb_strlen($m['notes'] ?? '') > 60 ? '…' : '' ?></small></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-secondary">
                                        <tr>
                                            <td colspan="2" class="fw-bold">Summary</td>
                                            <td></td>
                                            <td class="text-end fw-bold">
                                                <span class="text-success">+<?= number_format($totalIn, 2) ?></span>
                                                / <span class="text-danger">-<?= number_format($totalOut, 2) ?></span>
                                                = <span class="text-primary"><?= $netChange >= 0 ? '+' : '' ?><?= number_format($netChange, 2) ?></span>
                                            </td>
                                            <td colspan="4"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-3">
                    <small class="text-muted">
                        <strong>Status legend:</strong>
                        <span class="badge bg-success">In</span> Received, Stock In, Return to storage, Transfer In, Set Creation |
                        <span class="badge bg-danger">Out</span> Stock Out, Sale, Return to vendor, Transfer Out |
                        <span class="badge bg-info">Transfer</span> Between locations |
                        <span class="badge bg-warning text-dark">Adjustment</span> Manual correction
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const quickFilter = document.getElementById('quickFilter');
    const dateFrom = document.getElementById('dateFrom');
    const dateTo = document.getElementById('dateTo');
    if (!quickFilter || !dateFrom || !dateTo) {
        return;
    }

    [dateFrom, dateTo].forEach(function (input) {
        input.addEventListener('change', function () {
            quickFilter.value = 'custom';
        });
    });
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
