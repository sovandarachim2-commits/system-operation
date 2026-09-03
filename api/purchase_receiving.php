<?php
declare(strict_types=1);

require_once __DIR__ . '/purchase_common.php';

function purchase_receiving_apply_stock(PDO $pdo, array $item, int $orderId, int $userId): void
{
    $locationId = (int)($item['storage_location_id'] ?? 0);
    $itemName = trim((string)($item['item_name'] ?? ''));
    $sku = trim((string)($item['sku'] ?? ''));
    $qty = (float)($item['quantity_received'] ?? 0);
    $unitCost = (float)($item['unit_cost'] ?? 0);
    if ($locationId <= 0 || $itemName === '' || $qty <= 0) {
        return;
    }

    $stmt = $pdo->prepare('
        SELECT id, quantity_on_hand
        FROM current_inventory
        WHERE storage_location_id = ?
          AND TRIM(item_name) = ?
          AND TRIM(COALESCE(sku, \'\')) = ?
        ORDER BY id ASC
    ');
    $stmt->execute([$locationId, $itemName, $sku]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $prev = 0.0;
    $keeperId = 0;
    $extraIds = [];
    foreach ($rows as $index => $row) {
        $prev += (float)($row['quantity_on_hand'] ?? 0);
        if ($index === 0) {
            $keeperId = (int)$row['id'];
        } else {
            $extraIds[] = (int)$row['id'];
        }
    }
    $newQty = $prev + $qty;
    if ($keeperId > 0) {
        if ($unitCost > 0) {
            $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = ?, unit_cost = ?, last_updated = NOW(), updated_by = ? WHERE id = ?')
                ->execute([$newQty, $unitCost, $userId ?: null, $keeperId]);
        } else {
            $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = ?, last_updated = NOW(), updated_by = ? WHERE id = ?')
                ->execute([$newQty, $userId ?: null, $keeperId]);
        }
        $extraIds = array_values(array_filter($extraIds, static fn ($id) => $id > 0));
        if ($extraIds) {
            $placeholders = implode(',', array_fill(0, count($extraIds), '?'));
            $pdo->prepare("DELETE FROM current_inventory WHERE id IN ($placeholders)")->execute($extraIds);
        }
    } else {
        $pdo->prepare('
            INSERT INTO current_inventory (item_name, sku, storage_location_id, quantity_on_hand, unit_cost, updated_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$itemName, $sku !== '' ? $sku : null, $locationId, $newQty, $unitCost, $userId ?: null]);
    }

    $productId = 0;
    try {
        $p = $pdo->prepare('SELECT id FROM products WHERE TRIM(COALESCE(sku, \'\')) = ? OR TRIM(name) = ? ORDER BY (TRIM(COALESCE(sku, \'\')) = ?) DESC LIMIT 1');
        $p->execute([$sku, $itemName, $sku]);
        $productId = (int)($p->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $productId = 0;
    }
    if ($productId <= 0) {
        return;
    }
    $pdo->prepare('
        INSERT INTO stock_movements
        (item_id, movement_type, quantity, previous_stock, new_stock, reference_type, reference_id, notes, to_storage_location_id, unit_cost, total_cost, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $productId,
        'in',
        $qty,
        $prev,
        $newQty,
        'purchase',
        'PO-' . $orderId,
        'Receiving with storage assignment',
        $locationId,
        $unitCost,
        abs($qty) * $unitCost,
        (string)$userId,
    ]);
}

$pdo = get_db_connection();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = purchase_api_str($_GET['action'] ?? ($method === 'POST' ? '' : 'list'));
purchase_api_ensure_text_column($pdo, 'purchase_receiving', 'reference_files');

try {
    if ($method === 'GET' && $action === 'options') {
        require_role_or_permission(['admin'], 'sr_purchase_receiving.view', 'purchase_receiving.view');
        $orders = $pdo->query("
            SELECT po.id AS value, CONCAT(po.order_number, ' · ', COALESCE(pv.name, 'Supplier')) AS label, po.status
            FROM purchase_orders po
            LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
            WHERE po.status IN ('sent', 'confirmed', 'partial', 'received')
            ORDER BY po.order_date DESC, po.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $locations = [];
        try {
            $locations = $pdo->query('SELECT id AS value, location_name AS label FROM storage_locations WHERE is_active = 1 ORDER BY location_name')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $locations = [];
        }
        api_json(['success' => true, 'orders' => $orders, 'locations' => $locations]);
    }

    if ($method === 'GET' && $action === 'ready') {
        require_role_or_permission(['admin'], 'sr_purchase_receiving.view', 'purchase_receiving.view');
        $orders = $pdo->query("
            SELECT
                po.id AS value,
                CONCAT(po.order_number, ' - ', COALESCE(pv.name, 'Supplier')) AS label,
                po.order_number,
                po.order_date,
                po.expected_date,
                po.status,
                po.total_amount,
                pv.name AS vendor_name,
                COUNT(poi.id) AS total_items,
                COALESCE(SUM(poi.quantity_ordered), 0) AS total_qty,
                COALESCE(SUM(COALESCE(poi.quantity_received, 0)), 0) AS received_qty,
                COALESCE(SUM(CASE WHEN COALESCE(poi.quantity_received, 0) >= poi.quantity_ordered THEN 1 ELSE 0 END), 0) AS completed_items
            FROM purchase_orders po
            LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
            LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
            WHERE po.status IN ('confirmed', 'partial')
            AND EXISTS (
                SELECT 1 FROM purchase_order_items poi2
                WHERE poi2.purchase_order_id = po.id
                AND COALESCE(poi2.quantity_received, 0) < poi2.quantity_ordered
            )
            GROUP BY po.id
            ORDER BY po.expected_date ASC, po.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        api_json(['success' => true, 'orders' => $orders]);
    }

    if ($method === 'GET' && $action === 'order_items') {
        require_role_or_permission(['admin'], 'sr_purchase_receiving.view', 'purchase_receiving.view');
        $orderId = (int)($_GET['purchase_order_id'] ?? 0);
        $stmt = $pdo->prepare('
            SELECT
                poi.*,
                GREATEST(poi.quantity_ordered - COALESCE(poi.quantity_received, 0), 0) AS remaining_qty
            FROM purchase_order_items poi
            WHERE poi.purchase_order_id = ?
            ORDER BY poi.id
        ');
        $stmt->execute([$orderId]);
        api_json(['success' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'GET' && ($action === 'list' || $action === 'history' || $action === '')) {
        require_role_or_permission(['admin'], 'sr_purchase_receiving.view', 'purchase_receiving.view');
        $from = purchase_api_str($_GET['from'] ?? '');
        $to = purchase_api_str($_GET['to'] ?? '');
        $sql = '
            SELECT
                pr.*,
                po.order_number,
                pv.name AS vendor_name,
                u.name AS received_by_name,
                (SELECT COUNT(*) FROM purchase_receiving_items pri WHERE pri.receiving_id = pr.id) AS item_count,
                (SELECT COALESCE(SUM(pri.quantity_received), 0) FROM purchase_receiving_items pri WHERE pri.receiving_id = pr.id) AS total_qty
            FROM purchase_receiving pr
            JOIN purchase_orders po ON po.id = pr.purchase_order_id
            LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
            LEFT JOIN users u ON u.id = pr.received_by
            WHERE 1=1
        ';
        $params = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $sql .= ' AND pr.receiving_date >= ?';
            $params[] = $from;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $sql .= ' AND pr.receiving_date <= ?';
            $params[] = $to;
        }
        $sql .= ' ORDER BY pr.receiving_date DESC, pr.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $receivings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($receivings as &$receiving) {
            $receiving['reference_files'] = purchase_api_stored_files($receiving['reference_files'] ?? '');
            $receiving['reference_file_urls'] = purchase_api_reference_file_urls($receiving['reference_files']);
        }
        unset($receiving);

        $productSql = '
            SELECT
                poi.item_name,
                poi.sku,
                pv.name AS vendor_name,
                COUNT(DISTINCT pr.id) AS receiving_count,
                COUNT(DISTINCT po.id) AS order_count,
                COALESCE(SUM(pri.quantity_received), 0) AS total_qty,
                COALESCE(SUM(pri.total_cost), 0) AS total_cost,
                MAX(pr.receiving_date) AS last_receiving_date
            FROM purchase_receiving_items pri
            JOIN purchase_receiving pr ON pr.id = pri.receiving_id
            JOIN purchase_order_items poi ON poi.id = pri.purchase_order_item_id
            JOIN purchase_orders po ON po.id = pr.purchase_order_id
            LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
            WHERE 1=1
        ';
        $productParams = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $productSql .= ' AND pr.receiving_date >= ?';
            $productParams[] = $from;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $productSql .= ' AND pr.receiving_date <= ?';
            $productParams[] = $to;
        }
        $productSql .= '
            GROUP BY poi.item_name, poi.sku, pv.name
            ORDER BY total_qty DESC, poi.item_name ASC
        ';
        $productStmt = $pdo->prepare($productSql);
        $productStmt->execute($productParams);

        api_json([
            'success' => true,
            'receivings' => $receivings,
            'product_report' => $productStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    if ($method === 'GET' && $action === 'detail') {
        require_role_or_permission(['admin'], 'sr_purchase_receiving.view', 'purchase_receiving.view');
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            api_error('Receiving record not found.', 404);
        }
        $stmt = $pdo->prepare('
            SELECT
                pr.*,
                po.order_number,
                pv.name AS vendor_name,
                u.name AS received_by_name,
                (SELECT COALESCE(SUM(pri.quantity_received), 0) FROM purchase_receiving_items pri WHERE pri.receiving_id = pr.id) AS total_qty
            FROM purchase_receiving pr
            JOIN purchase_orders po ON po.id = pr.purchase_order_id
            LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
            LEFT JOIN users u ON u.id = pr.received_by
            WHERE pr.id = ?
            LIMIT 1
        ');
        $stmt->execute([$id]);
        $receiving = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$receiving) {
            api_error('Receiving record not found.', 404);
        }
        $items = $pdo->prepare('
            SELECT
                pri.*,
                poi.item_name,
                poi.sku,
                poi.quantity_ordered,
                poi.quantity_received AS order_quantity_received,
                sl.location_name AS location_name
            FROM purchase_receiving_items pri
            LEFT JOIN purchase_order_items poi ON poi.id = pri.purchase_order_item_id
            LEFT JOIN storage_locations sl ON sl.id = pri.location
            WHERE pri.receiving_id = ?
            ORDER BY pri.id
        ');
        $items->execute([$id]);
        $receiving['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
        $receiving['reference_files'] = purchase_api_stored_files($receiving['reference_files'] ?? '');
        $receiving['reference_file_urls'] = purchase_api_reference_file_urls($receiving['reference_files']);
        api_json(['success' => true, 'receiving' => $receiving]);
    }

    if ($method !== 'POST') {
        api_error('Unsupported method.', 405);
    }

    $input = purchase_api_input();
    $action = purchase_api_str($input['action'] ?? $action);

    if ($action === 'receive') {
        require_role_or_permission(['admin'], 'sr_purchase_receiving.create', 'purchase_receiving.create', 'purchase_receiving.update');
        $orderId = (int)($input['purchase_order_id'] ?? 0);
        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        if ($orderId <= 0) {
            api_error('Purchase order is required.');
        }
        $valid = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemId = (int)($item['purchase_order_item_id'] ?? $item['id'] ?? 0);
            $qty = purchase_api_num($item['quantity_received'] ?? 0);
            $locationId = (int)($item['storage_location_id'] ?? 0);
            if ($itemId > 0 && $qty > 0) {
                $valid[] = [
                    'id' => $itemId,
                    'qty' => $qty,
                    'storage_location_id' => $locationId,
                    'location' => purchase_api_str($item['location'] ?? ''),
                    'notes' => purchase_api_str($item['notes'] ?? ''),
                ];
            }
        }
        if (!$valid) {
            api_error('At least one item quantity is required.');
        }

        $pdo->beginTransaction();
        $userId = purchase_api_user_id();
        $date = purchase_api_date($input['receiving_date'] ?? null);
        $notes = purchase_api_str($input['notes'] ?? '');
        $referenceFiles = purchase_api_save_reference_files(is_array($input['reference_files'] ?? null) ? $input['reference_files'] : [], 'purchase_receiving', $date, 'Receiving image');
        $pdo->prepare('INSERT INTO purchase_receiving (purchase_order_id, receiving_date, received_by, notes, reference_files) VALUES (?, ?, ?, ?, ?)')
            ->execute([$orderId, $date, $userId, $notes, json_encode($referenceFiles)]);
        $receivingId = (int)$pdo->lastInsertId();
        $totalReceived = 0.0;
        $storageItems = [];

        foreach ($valid as $row) {
            $itemStmt = $pdo->prepare('SELECT * FROM purchase_order_items WHERE id = ? AND purchase_order_id = ?');
            $itemStmt->execute([$row['id'], $orderId]);
            $orderItem = $itemStmt->fetch(PDO::FETCH_ASSOC);
            if (!$orderItem) {
                continue;
            }
            $unitCost = (float)$orderItem['unit_price'];
            $totalCost = $row['qty'] * $unitCost;
            $pdo->prepare('
                INSERT INTO purchase_receiving_items
                (receiving_id, purchase_order_item_id, quantity_received, unit_cost, total_cost, location, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([$receivingId, $row['id'], $row['qty'], $unitCost, $totalCost, $row['location'], $row['notes']]);
            $pdo->prepare('UPDATE purchase_order_items SET quantity_received = COALESCE(quantity_received, 0) + ? WHERE id = ?')
                ->execute([$row['qty'], $row['id']]);

            if ((int)($orderItem['stock_item_id'] ?? 0) > 0) {
                try {
                    $pdo->prepare('
                        INSERT INTO stock_movements
                        (item_id, movement_type, quantity, reference_type, reference_id, reference_no, reason, unit_cost, total_cost, user_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ')->execute([
                        (int)$orderItem['stock_item_id'], 'in', $row['qty'], 'purchase', $orderId,
                        'PO-' . $orderId, 'Purchase receiving', $unitCost, $totalCost, $userId,
                    ]);
                    $pdo->prepare('UPDATE stock_items SET current_quantity = current_quantity + ? WHERE id = ?')
                        ->execute([$row['qty'], (int)$orderItem['stock_item_id']]);
                } catch (Throwable $e) {
                    // optional stock tables
                }
            }

            if ($row['storage_location_id'] > 0) {
                $itemName = (string)$orderItem['item_name'];
                if (!empty($orderItem['product_id'])) {
                    $p = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                    $p->execute([(int)$orderItem['product_id']]);
                    $pname = $p->fetchColumn();
                    if ($pname) {
                        $itemName = (string)$pname;
                    }
                }
                $storageItems[] = [
                    'purchase_order_item_id' => $row['id'],
                    'item_name' => $itemName,
                    'sku' => $orderItem['sku'],
                    'quantity_received' => $row['qty'],
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'storage_location_id' => $row['storage_location_id'],
                    'location' => $row['location'],
                    'notes' => $row['notes'],
                ];
            }
            $totalReceived += $row['qty'];
        }

        if ($storageItems) {
            try {
                $receiptNumber = 'STR-' . date('Y-m-d') . '-' . str_pad((string)mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                $pdo->prepare('
                    INSERT INTO storage_receipts
                    (receipt_number, purchase_order_id, receiving_id, receipt_date, received_by, status, total_items, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([$receiptNumber, $orderId, $receivingId, $date, $userId, 'stored', count($storageItems), 'Auto-created from receiving']);
                $receiptId = (int)$pdo->lastInsertId();
                foreach ($storageItems as $item) {
                    $pdo->prepare('
                        INSERT INTO storage_receipt_items
                        (receipt_id, purchase_order_item_id, item_name, sku, quantity_received, unit_cost, total_cost, storage_location_id, storage_bin, quality_status, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ')->execute([
                        $receiptId, $item['purchase_order_item_id'], $item['item_name'], $item['sku'],
                        $item['quantity_received'], $item['unit_cost'], $item['total_cost'],
                        $item['storage_location_id'], $item['location'], 'approved', $item['notes'],
                    ]);
                    $pdo->prepare('
                        INSERT INTO inventory_movements
                        (movement_type, item_name, sku, quantity, unit_cost, total_cost, to_location_id, reference_type, reference_id, reference_no, reason, user_id, movement_date)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ')->execute([
                        'purchase_in', $item['item_name'], $item['sku'], $item['quantity_received'], $item['unit_cost'], $item['total_cost'],
                        $item['storage_location_id'], 'purchase', $orderId, 'PO-' . $orderId, 'Receiving with storage assignment', $userId, $date,
                    ]);
                }
                $pdo->prepare('UPDATE purchase_receiving SET storage_receipt_id = ? WHERE id = ?')->execute([$receiptId, $receivingId]);
            } catch (Throwable $e) {
                // storage receipt tables optional for core receive
            }
            foreach ($storageItems as $item) {
                purchase_receiving_apply_stock($pdo, $item, $orderId, $userId);
            }
        }

        $statusCheck = $pdo->prepare('
            SELECT COUNT(*) AS total_items,
                   SUM(CASE WHEN COALESCE(quantity_received,0) >= quantity_ordered THEN 1 ELSE 0 END) AS completed_items
            FROM purchase_order_items
            WHERE purchase_order_id = ?
        ');
        $statusCheck->execute([$orderId]);
        $statusRow = $statusCheck->fetch(PDO::FETCH_ASSOC);
        $newStatus = 'confirmed';
        if ($statusRow && (int)$statusRow['total_items'] === (int)$statusRow['completed_items']) {
            $newStatus = 'received';
        } elseif ($totalReceived > 0) {
            $newStatus = 'partial';
        }
        $pdo->prepare('UPDATE purchase_orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$newStatus, $orderId]);
        $pdo->commit();
        api_json(['success' => true, 'id' => $receivingId, 'message' => "Received $totalReceived units successfully."]);
    }

    api_error('Unknown action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e->getMessage(), 500);
}
