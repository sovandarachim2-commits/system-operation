<?php
declare(strict_types=1);

require_once __DIR__ . '/purchase_common.php';

$pdo = get_db_connection();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = purchase_api_str($_GET['action'] ?? ($method === 'POST' ? '' : 'list'));

function purchase_next_return_number(PDO $pdo): string
{
    $prefix = 'RET-' . date('Ymd') . '-';
    $stmt = $pdo->prepare('SELECT return_number FROM purchase_returns WHERE return_number LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $next = (int)substr((string)$last, strlen($prefix)) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function purchase_return_item_name(PDO $pdo, array $orderItem): string
{
    $itemName = (string)($orderItem['item_name'] ?? '');
    if (!empty($orderItem['product_id'])) {
        $stmt = $pdo->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$orderItem['product_id']]);
        $productName = $stmt->fetchColumn();
        if ($productName) {
            $itemName = (string)$productName;
        }
    }
    return $itemName;
}

function purchase_return_location_stock(PDO $pdo, string $itemName, int $locationId): float
{
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(quantity_on_hand), 0)
        FROM current_inventory
        WHERE item_name = ?
          AND storage_location_id = ?
          AND quantity_on_hand > 0
    ');
    $stmt->execute([$itemName, $locationId]);
    return (float)$stmt->fetchColumn();
}

function purchase_return_reduce_inventory(PDO $pdo, string $itemName, ?string $sku, int $locationId, float $qty, float $unitCost, int $returnId, string $returnNumber, int $userId, string $returnDate): void
{
    $remaining = $qty;
    $stmt = $pdo->prepare('
        SELECT id, quantity_on_hand
        FROM current_inventory
        WHERE item_name = ?
          AND storage_location_id = ?
          AND quantity_on_hand > 0
        ORDER BY last_updated ASC, id ASC
    ');
    $stmt->execute([$itemName, $locationId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($remaining <= 0) {
            break;
        }
        $reduce = min($remaining, (float)$row['quantity_on_hand']);
        $newQty = max(0, (float)$row['quantity_on_hand'] - $reduce);
        $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = ?, last_updated = NOW(), updated_by = ? WHERE id = ?')
            ->execute([$newQty, $userId, (int)$row['id']]);
        $remaining -= $reduce;
    }

    if ($remaining > 0.0001) {
        throw new RuntimeException('Inventory changed while saving. Please refresh and try again.');
    }

    try {
        $pdo->prepare('
            INSERT INTO inventory_movements
            (movement_type, item_name, sku, quantity, unit_cost, total_cost, from_location_id, reference_type, reference_id, reference_no, reason, user_id, movement_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            'damage_out', $itemName, $sku, -$qty, $unitCost, -$qty * $unitCost,
            $locationId, 'damage', $returnId, $returnNumber, 'Return to supplier', $userId, $returnDate,
        ]);
    } catch (Throwable $e) {
        // Movement logging is optional because older installs may have narrower enums.
    }
}

try {
    if ($method === 'GET' && $action === 'options') {
        require_role_or_permission(['admin'], 'sr_purchase_returns.view', 'purchase_receiving.view');
        $orders = $pdo->query("
            SELECT po.id AS value, CONCAT(po.order_number, ' · ', COALESCE(pv.name, 'Supplier')) AS label, po.vendor_id
            FROM purchase_orders po
            LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
            WHERE po.status IN ('partial', 'received', 'confirmed')
              AND EXISTS (
                SELECT 1 FROM purchase_order_items poi
                WHERE poi.purchase_order_id = po.id AND COALESCE(poi.quantity_received, 0) > 0
              )
            ORDER BY po.order_number DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $locations = [];
        try {
            $locations = $pdo->query('SELECT id AS value, COALESCE(location_name, location_code) AS label FROM storage_locations WHERE is_active = 1 ORDER BY location_code, location_name')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $locations = [];
        }
        api_json(['success' => true, 'orders' => $orders, 'locations' => $locations]);
    }

    if ($method === 'GET' && $action === 'order_items') {
        require_role_or_permission(['admin'], 'sr_purchase_returns.view', 'purchase_receiving.view');
        $orderId = (int)($_GET['purchase_order_id'] ?? 0);
        $stmt = $pdo->prepare('
            SELECT
                poi.*,
                COALESCE(poi.quantity_received, 0) AS total_received,
                COALESCE((
                    SELECT SUM(pri.quantity_returned)
                    FROM purchase_return_items pri
                    JOIN purchase_returns pr ON pr.id = pri.purchase_return_id
                    WHERE pri.purchase_order_item_id = poi.id AND pr.status != \'cancelled\'
                ), 0) AS already_returned
            FROM purchase_order_items poi
            WHERE poi.purchase_order_id = ?
              AND COALESCE(poi.quantity_received, 0) > 0
            ORDER BY poi.id
        ');
        $stmt->execute([$orderId]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['returnable_qty'] = max(0, (float)$row['total_received'] - (float)$row['already_returned']);
            $itemName = purchase_return_item_name($pdo, $row);
            $row['resolved_item_name'] = $itemName;
            $row['storage_locations'] = [];
            try {
                $invStmt = $pdo->prepare('
                    SELECT
                        ci.storage_location_id AS value,
                        COALESCE(sl.location_name, sl.location_code, CONCAT(\'Location #\', ci.storage_location_id)) AS label,
                        COALESCE(SUM(ci.quantity_on_hand), 0) AS qty_available
                    FROM current_inventory ci
                    LEFT JOIN storage_locations sl ON sl.id = ci.storage_location_id
                    WHERE ci.item_name = ?
                      AND ci.quantity_on_hand > 0
                      AND ci.storage_location_id IS NOT NULL
                    GROUP BY ci.storage_location_id, sl.location_name, sl.location_code
                    ORDER BY sl.location_code, sl.location_name
                ');
                $invStmt->execute([$itemName]);
                $row['storage_locations'] = $invStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $row['storage_locations'] = [];
            }
            $items[] = $row;
        }
        api_json(['success' => true, 'items' => $items]);
    }

    if ($method === 'GET' && ($action === 'list' || $action === 'history' || $action === '')) {
        require_role_or_permission(['admin'], 'sr_purchase_returns.view', 'purchase_receiving.view');
        $from = purchase_api_str($_GET['from'] ?? '');
        $to = purchase_api_str($_GET['to'] ?? '');
        $sql = '
            SELECT
                pr.*,
                po.order_number,
                pv.name AS vendor_name,
                u.name AS created_by_name
            FROM purchase_returns pr
            JOIN purchase_orders po ON po.id = pr.purchase_order_id
            LEFT JOIN purchase_vendors pv ON pv.id = pr.vendor_id
            LEFT JOIN users u ON u.id = pr.created_by
            WHERE 1=1
        ';
        $params = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $sql .= ' AND pr.return_date >= ?';
            $params[] = $from;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $sql .= ' AND pr.return_date <= ?';
            $params[] = $to;
        }
        $sql .= ' ORDER BY pr.return_date DESC, pr.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        api_json(['success' => true, 'returns' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method !== 'POST') {
        api_error('Unsupported method.', 405);
    }

    $input = purchase_api_input();
    $action = purchase_api_str($input['action'] ?? $action);

    if ($action === 'create') {
        require_role_or_permission(['admin'], 'sr_purchase_returns.create', 'purchase_receiving.create', 'purchase_receiving.update');
        $orderId = (int)($input['purchase_order_id'] ?? 0);
        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        if ($orderId <= 0) {
            api_error('Purchase order is required.');
        }
        $orderStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            api_error('Purchase order not found.', 404);
        }

        $valid = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemId = (int)($item['purchase_order_item_id'] ?? $item['id'] ?? 0);
            $qty = purchase_api_num($item['quantity_returned'] ?? 0);
            if ($itemId > 0 && $qty > 0) {
                $valid[] = [
                    'id' => $itemId,
                    'qty' => $qty,
                    'reason' => '',
                    'storage_location_id' => (int)($item['storage_location_id'] ?? 0) ?: null,
                ];
            }
        }
        if (!$valid) {
            api_error('At least one return quantity is required.');
        }

        $pdo->beginTransaction();
        $returnNumber = purchase_next_return_number($pdo);
        $returnDate = purchase_api_date($input['return_date'] ?? null);
        $reason = '';
        $notes = purchase_api_str($input['notes'] ?? '');
        $userId = purchase_api_user_id();
        $pdo->prepare('
            INSERT INTO purchase_returns
            (purchase_order_id, vendor_id, return_number, return_date, status, reason, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([$orderId, (int)$order['vendor_id'], $returnNumber, $returnDate, 'completed', $reason, $notes, $userId]);
        $returnId = (int)$pdo->lastInsertId();
        $totalAmount = 0.0;

        foreach ($valid as $row) {
            $poiStmt = $pdo->prepare('SELECT * FROM purchase_order_items WHERE id = ? AND purchase_order_id = ?');
            $poiStmt->execute([$row['id'], $orderId]);
            $poi = $poiStmt->fetch(PDO::FETCH_ASSOC);
            if (!$poi) {
                continue;
            }
            $received = (float)($poi['quantity_received'] ?? 0);
            $alreadyStmt = $pdo->prepare('
                SELECT COALESCE(SUM(pri.quantity_returned), 0)
                FROM purchase_return_items pri
                JOIN purchase_returns pr ON pr.id = pri.purchase_return_id
                WHERE pri.purchase_order_item_id = ?
                  AND pr.status != \'cancelled\'
            ');
            $alreadyStmt->execute([$row['id']]);
            $alreadyReturned = (float)$alreadyStmt->fetchColumn();
            $returnableQty = max(0, $received - $alreadyReturned);
            if ($row['qty'] > $returnableQty + 0.0001) {
                $pdo->rollBack();
                api_error('Return qty exceeds returnable qty for ' . $poi['item_name']);
            }
            if ((int)($row['storage_location_id'] ?? 0) <= 0) {
                $pdo->rollBack();
                api_error('Inventory location is required for ' . $poi['item_name']);
            }
            $unitCost = (float)$poi['unit_price'];
            $line = $row['qty'] * $unitCost;
            $totalAmount += $line;
            $itemName = purchase_return_item_name($pdo, $poi);
            $locationStock = purchase_return_location_stock($pdo, $itemName, (int)$row['storage_location_id']);
            if ($locationStock + 0.0001 < $row['qty']) {
                $pdo->rollBack();
                api_error('Not enough inventory for ' . $itemName . ' at selected location. Available: ' . $locationStock);
            }
            $pdo->prepare('
                INSERT INTO purchase_return_items
                (purchase_return_id, purchase_order_item_id, quantity_returned, unit_cost, total_cost, reason, storage_location_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([$returnId, $row['id'], $row['qty'], $unitCost, $line, $row['reason'], $row['storage_location_id']]);

            purchase_return_reduce_inventory($pdo, $itemName, $poi['sku'] ?? null, (int)$row['storage_location_id'], $row['qty'], $unitCost, $returnId, $returnNumber, $userId, $returnDate);
            if ((int)($poi['stock_item_id'] ?? 0) > 0) {
                try {
                    $pdo->prepare('UPDATE stock_items SET current_quantity = GREATEST(0, current_quantity - ?) WHERE id = ?')
                        ->execute([$row['qty'], (int)$poi['stock_item_id']]);
                } catch (Throwable $e) {
                    // optional stock table
                }
            }
        }

        $pdo->prepare('UPDATE purchase_returns SET total_amount = ? WHERE id = ?')->execute([$totalAmount, $returnId]);
        $pdo->commit();
        api_json(['success' => true, 'id' => $returnId, 'return_number' => $returnNumber, 'message' => "Return $returnNumber created."]);
    }

    if ($action === 'delete') {
        require_role_or_permission(['admin'], 'sr_purchase_returns.delete', 'purchase_receiving.delete');
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            api_error('Return id is required.');
        }
        $pdo->beginTransaction();
        $returnStmt = $pdo->prepare('SELECT return_number, return_date FROM purchase_returns WHERE id = ?');
        $returnStmt->execute([$id]);
        $returnRow = $returnStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $itemsStmt = $pdo->prepare('
            SELECT
                pri.*,
                poi.item_name,
                poi.sku,
                poi.product_id,
                poi.stock_item_id
            FROM purchase_return_items pri
            JOIN purchase_order_items poi ON poi.id = pri.purchase_order_item_id
            WHERE pri.purchase_return_id = ?
        ');
        $itemsStmt->execute([$id]);
        $userId = purchase_api_user_id();
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $locationId = (int)($item['storage_location_id'] ?? 0);
            $qty = (float)($item['quantity_returned'] ?? 0);
            if ($locationId <= 0 || $qty <= 0) {
                continue;
            }
            $itemName = purchase_return_item_name($pdo, $item);
            $sku = $item['sku'] ?? null;
            $unitCost = (float)($item['unit_cost'] ?? 0);
            $invStmt = $pdo->prepare('SELECT id FROM current_inventory WHERE item_name = ? AND storage_location_id = ? ORDER BY last_updated DESC, id DESC LIMIT 1');
            $invStmt->execute([$itemName, $locationId]);
            $invId = (int)($invStmt->fetchColumn() ?: 0);
            if ($invId > 0) {
                $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = quantity_on_hand + ?, last_updated = NOW(), updated_by = ? WHERE id = ?')
                    ->execute([$qty, $userId, $invId]);
            } else {
                $pdo->prepare('
                    INSERT INTO current_inventory
                    (item_name, sku, storage_location_id, quantity_on_hand, unit_cost, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ')->execute([$itemName, $sku, $locationId, $qty, $unitCost, $userId]);
            }
            if ((int)($item['stock_item_id'] ?? 0) > 0) {
                try {
                    $pdo->prepare('UPDATE stock_items SET current_quantity = current_quantity + ? WHERE id = ?')
                        ->execute([$qty, (int)$item['stock_item_id']]);
                } catch (Throwable $e) {
                    // optional stock table
                }
            }
            try {
                $pdo->prepare('
                    INSERT INTO inventory_movements
                    (movement_type, item_name, sku, quantity, unit_cost, total_cost, to_location_id, reference_type, reference_id, reference_no, reason, user_id, movement_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([
                    'return_in', $itemName, $sku, $qty, $unitCost, $qty * $unitCost,
                    $locationId, 'return', $id, $returnRow['return_number'] ?? ('RET-' . $id), 'Deleted supplier return', $userId, $returnRow['return_date'] ?? date('Y-m-d'),
                ]);
            } catch (Throwable $e) {
                // Movement logging is optional because older installs may have narrower enums.
            }
        }
        $pdo->prepare('DELETE FROM purchase_return_items WHERE purchase_return_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM purchase_returns WHERE id = ?')->execute([$id]);
        $pdo->commit();
        api_json(['success' => true, 'message' => 'Return deleted.']);
    }

    api_error('Unknown action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e->getMessage(), 500);
}
