<?php
declare(strict_types=1);

require_once __DIR__ . '/purchase_common.php';

$pdo = get_db_connection();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = purchase_api_str($_GET['action'] ?? ($method === 'POST' ? '' : 'list'));
purchase_api_ensure_text_column($pdo, 'purchase_orders', 'reference_files');

function purchase_order_load(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT po.*, pv.name AS vendor_name, u.name AS created_by_name
        FROM purchase_orders po
        LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
        LEFT JOIN users u ON u.id = po.created_by
        WHERE po.id = ?
    ');
    $stmt->execute([$id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return null;
    }
    $items = $pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id');
    $items->execute([$id]);
    $order['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
    $order['reference_files'] = purchase_api_stored_files($order['reference_files'] ?? '');
    $order['reference_file_urls'] = purchase_api_reference_file_urls($order['reference_files']);
    $order['balance_amount'] = max(0, (float)$order['total_amount'] - (float)$order['total_paid']);
    return $order;
}

function purchase_order_validate_items(array $items): array
{
    $valid = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $productId = (int)($item['product_id'] ?? 0);
        $stockItemId = (int)($item['stock_item_id'] ?? 0);
        $qty = purchase_api_num($item['quantity'] ?? $item['quantity_ordered'] ?? 0);
        $price = purchase_api_num($item['unit_price'] ?? 0);
        if (($productId <= 0 && $stockItemId <= 0) || $qty <= 0 || $price <= 0) {
            continue;
        }
        $valid[] = [
            'product_id' => $productId,
            'stock_item_id' => $stockItemId,
            'item_name' => purchase_api_str($item['item_name'] ?? ''),
            'sku' => purchase_api_str($item['sku'] ?? ''),
            'quantity' => $qty,
            'unit_price' => $price,
        ];
    }
    return $valid;
}

try {
    if ($method === 'GET' && $action === 'options') {
        require_role_or_permission(['admin'], 'sr_purchase_orders.view', 'purchase_orders.view');
        $vendors = $pdo->query('SELECT id AS value, name AS label FROM purchase_vendors WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $products = $pdo->query("
            SELECT id AS value, name AS label, COALESCE(cost, 0) AS unit_cost
            FROM products
            WHERE product_type != 'set' OR product_type IS NULL
            ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);
        $stockItems = [];
        try {
            $stockItems = $pdo->query('SELECT id AS value, name AS label, COALESCE(current_quantity, 0) AS current_quantity FROM stock_items WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $stockItems = [];
        }
        api_json(['success' => true, 'vendors' => $vendors, 'products' => $products, 'stock_items' => $stockItems]);
    }

    if ($method === 'GET' && ($action === 'list' || $action === '')) {
        require_role_or_permission(['admin'], 'sr_purchase_orders.view', 'purchase_orders.view');
        $status = purchase_api_str($_GET['status'] ?? '');
        $vendorId = (int)($_GET['vendor_id'] ?? 0);
        $q = purchase_api_str($_GET['q'] ?? '');
        $sql = '
            SELECT
                po.*,
                pv.name AS vendor_name,
                u.name AS created_by_name,
                COUNT(poi.id) AS item_count,
                COALESCE(SUM(poi.quantity_ordered), 0) AS total_quantity,
                COALESCE(SUM(poi.quantity_received), 0) AS total_received,
                COALESCE(SUM(CASE WHEN COALESCE(poi.quantity_received, 0) >= poi.quantity_ordered THEN 1 ELSE 0 END), 0) AS items_completed,
                GROUP_CONCAT(
                    CONCAT(poi.item_name, \' x\', TRIM(TRAILING \'.\' FROM TRIM(TRAILING \'0\' FROM FORMAT(poi.quantity_ordered, 2))))
                    ORDER BY poi.id SEPARATOR \', \'
                ) AS product_items,
                GREATEST(po.total_amount - COALESCE(po.total_paid, 0), 0) AS balance_amount
            FROM purchase_orders po
            LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
            LEFT JOIN users u ON u.id = po.created_by
            LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
            WHERE 1=1
        ';
        $params = [];
        if ($status !== '') {
            $sql .= ' AND po.status = ?';
            $params[] = $status;
        }
        if ($vendorId > 0) {
            $sql .= ' AND po.vendor_id = ?';
            $params[] = $vendorId;
        }
        if ($q !== '') {
            $sql .= ' AND (po.order_number LIKE ? OR pv.name LIKE ? OR po.notes LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' GROUP BY po.id ORDER BY po.order_date DESC, po.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        api_json(['success' => true, 'orders' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'GET' && $action === 'get') {
        require_role_or_permission(['admin'], 'sr_purchase_orders.view', 'purchase_orders.view');
        $id = (int)($_GET['id'] ?? 0);
        $order = purchase_order_load($pdo, $id);
        if (!$order) {
            api_error('Purchase order not found.', 404);
        }
        api_json(['success' => true, 'order' => $order]);
    }

    if ($method !== 'POST') {
        api_error('Unsupported method.', 405);
    }

    $input = purchase_api_input();
    $action = purchase_api_str($input['action'] ?? $action);

    if ($action === 'create') {
        require_role_or_permission(['admin'], 'sr_purchase_orders.create', 'purchase_orders.create');
        $vendorId = (int)($input['vendor_id'] ?? 0);
        $items = purchase_order_validate_items(is_array($input['items'] ?? null) ? $input['items'] : []);
        if ($vendorId <= 0) {
            api_error('Supplier is required.');
        }
        if (!$items) {
            api_error('At least one valid item is required.');
        }

        $productMap = [];
        foreach ($pdo->query('SELECT id, name FROM products')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productMap[(int)$row['id']] = (string)$row['name'];
        }
        $stockMap = [];
        try {
            foreach ($pdo->query('SELECT id, name FROM stock_items')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $stockMap[(int)$row['id']] = (string)$row['name'];
            }
        } catch (Throwable $e) {
            $stockMap = [];
        }

        $pdo->beginTransaction();
        $orderNumber = purchase_api_str($input['order_number'] ?? '');
        if ($orderNumber === '') {
            $orderNumber = generate_purchase_order_code($pdo);
        } else {
            $chk = $pdo->prepare('SELECT id FROM purchase_orders WHERE order_number = ?');
            $chk->execute([$orderNumber]);
            if ($chk->fetch()) {
                $pdo->rollBack();
                api_error('Order code already exists.');
            }
        }

        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += $item['quantity'] * $item['unit_price'];
        }
        $taxRate = purchase_api_num($input['tax_rate'] ?? 0);
        $taxAmount = $subtotal * ($taxRate / 100);
        $shipping = purchase_api_num($input['shipping_cost'] ?? 0);
        $total = $subtotal + $taxAmount + $shipping;
        $referenceFiles = purchase_api_save_reference_files(is_array($input['reference_files'] ?? null) ? $input['reference_files'] : [], 'purchase_orders', purchase_api_date($input['order_date'] ?? null), 'Order image');

        $stmt = $pdo->prepare('
            INSERT INTO purchase_orders
            (order_number, vendor_id, order_date, expected_date, status, subtotal, tax_rate, tax_amount, shipping_cost, total_amount, notes, reference_files, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $orderNumber,
            $vendorId,
            purchase_api_date($input['order_date'] ?? null),
            purchase_api_str($input['expected_date'] ?? '') ?: null,
            'draft',
            $subtotal,
            $taxRate,
            $taxAmount,
            $shipping,
            $total,
            purchase_api_str($input['notes'] ?? ''),
            json_encode($referenceFiles),
            purchase_api_user_id(),
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare('
            INSERT INTO purchase_order_items
            (purchase_order_id, product_id, stock_item_id, item_name, sku, quantity_ordered, unit_price, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($items as $item) {
            $name = $item['item_name'];
            $sku = $item['sku'];
            if ($item['stock_item_id'] > 0) {
                $name = $name !== '' ? $name : ($stockMap[$item['stock_item_id']] ?? 'Stock item');
                $sku = $sku !== '' ? $sku : ('STOCK-' . $item['stock_item_id']);
            } elseif ($item['product_id'] > 0) {
                $name = $name !== '' ? $name : ($productMap[$item['product_id']] ?? 'Product');
                $sku = $sku !== '' ? $sku : ('PROD-' . str_pad((string)$item['product_id'], 4, '0', STR_PAD_LEFT));
            }
            $line = $item['quantity'] * $item['unit_price'];
            $itemStmt->execute([
                $orderId,
                $item['product_id'] ?: null,
                $item['stock_item_id'] ?: null,
                $name,
                $sku,
                $item['quantity'],
                $item['unit_price'],
                $line,
            ]);
        }
        $pdo->commit();
        api_json(['success' => true, 'id' => $orderId, 'order_number' => $orderNumber, 'message' => "Purchase order $orderNumber created."]);
    }

    if ($action === 'update') {
        require_role_or_permission(['admin'], 'sr_purchase_orders.update', 'purchase_orders.update');
        $id = (int)($input['id'] ?? 0);
        $order = purchase_order_load($pdo, $id);
        if (!$order) {
            api_error('Purchase order not found.', 404);
        }
        if (($order['status'] ?? '') !== 'draft') {
            api_error('Only draft orders can be fully edited.');
        }
        $vendorId = (int)($input['vendor_id'] ?? 0);
        $items = purchase_order_validate_items(is_array($input['items'] ?? null) ? $input['items'] : []);
        if ($vendorId <= 0 || !$items) {
            api_error('Supplier and at least one item are required.');
        }

        $productMap = [];
        foreach ($pdo->query('SELECT id, name FROM products')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productMap[(int)$row['id']] = (string)$row['name'];
        }

        $pdo->beginTransaction();
        $orderNumber = purchase_api_str($input['order_number'] ?? '');
        if ($orderNumber === '') {
            $orderNumber = (string)$order['order_number'];
        } elseif ($orderNumber !== (string)$order['order_number']) {
            $chk = $pdo->prepare('SELECT id FROM purchase_orders WHERE order_number = ? AND id <> ?');
            $chk->execute([$orderNumber, $id]);
            if ($chk->fetch()) {
                $pdo->rollBack();
                api_error('Order code already exists.');
            }
        }
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += $item['quantity'] * $item['unit_price'];
        }
        $taxRate = purchase_api_num($input['tax_rate'] ?? 0);
        $taxAmount = $subtotal * ($taxRate / 100);
        $shipping = purchase_api_num($input['shipping_cost'] ?? 0);
        $total = $subtotal + $taxAmount + $shipping;
        $referenceFiles = purchase_api_merge_reference_files(
            purchase_api_stored_files($order['reference_files'] ?? ''),
            is_array($input['existing_reference_files'] ?? null) ? $input['existing_reference_files'] : [],
            is_array($input['removed_reference_files'] ?? null) ? $input['removed_reference_files'] : [],
            is_array($input['reference_files'] ?? null) ? $input['reference_files'] : [],
            'purchase_orders',
            purchase_api_date($input['order_date'] ?? null)
        );

        $pdo->prepare('
            UPDATE purchase_orders
            SET order_number = ?, vendor_id = ?, order_date = ?, expected_date = ?, subtotal = ?, tax_rate = ?, tax_amount = ?, shipping_cost = ?, total_amount = ?, notes = ?, reference_files = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([
            $orderNumber,
            $vendorId,
            purchase_api_date($input['order_date'] ?? null),
            purchase_api_str($input['expected_date'] ?? '') ?: null,
            $subtotal,
            $taxRate,
            $taxAmount,
            $shipping,
            $total,
            purchase_api_str($input['notes'] ?? ''),
            json_encode($referenceFiles),
            $id,
        ]);
        $pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$id]);
        $itemStmt = $pdo->prepare('
            INSERT INTO purchase_order_items
            (purchase_order_id, product_id, stock_item_id, item_name, sku, quantity_ordered, unit_price, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($items as $item) {
            $name = $item['item_name'] !== '' ? $item['item_name'] : ($productMap[$item['product_id']] ?? 'Item');
            $sku = $item['sku'] !== '' ? $item['sku'] : ('PROD-' . str_pad((string)$item['product_id'], 4, '0', STR_PAD_LEFT));
            $itemStmt->execute([
                $id,
                $item['product_id'] ?: null,
                $item['stock_item_id'] ?: null,
                $name,
                $sku,
                $item['quantity'],
                $item['unit_price'],
                $item['quantity'] * $item['unit_price'],
            ]);
        }
        $pdo->commit();
        api_json(['success' => true, 'message' => 'Purchase order updated.']);
    }

    if ($action === 'update_status') {
        require_role_or_permission(['admin'], 'sr_purchase_orders.update', 'purchase_orders.update');
        $id = (int)($input['id'] ?? $input['order_id'] ?? 0);
        $status = purchase_api_str($input['status'] ?? '');
        $allowed = ['draft', 'sent', 'confirmed', 'partial', 'cancelled'];
        if ($status === 'received') {
            $chk = $pdo->prepare('
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN COALESCE(quantity_received,0) >= quantity_ordered THEN 1 ELSE 0 END) AS completed
                FROM purchase_order_items
                WHERE purchase_order_id = ?
            ');
            $chk->execute([$id]);
            $r = $chk->fetch(PDO::FETCH_ASSOC);
            if ($r && (int)$r['total'] > 0 && (int)$r['completed'] === (int)$r['total']) {
                $allowed[] = 'received';
            }
        }
        if ($id <= 0 || !in_array($status, $allowed, true)) {
            api_error('Invalid order status update.');
        }
        $pdo->prepare('UPDATE purchase_orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$status, $id]);
        api_json(['success' => true, 'message' => 'Order status updated.']);
    }

    if ($action === 'delete') {
        require_role_or_permission(['admin'], 'sr_purchase_orders.delete', 'purchase_orders.delete');
        $id = (int)($input['id'] ?? $input['order_id'] ?? 0);
        $order = purchase_order_load($pdo, $id);
        if (!$order) {
            api_error('Purchase order not found.', 404);
        }
        if (($order['status'] ?? '') !== 'draft') {
            api_error('Only draft orders can be deleted.');
        }
        $chk = $pdo->prepare('SELECT COUNT(*) FROM purchase_payments WHERE purchase_order_id = ?');
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() > 0) {
            api_error('Cannot delete: order has payments.');
        }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$id]);
        $pdo->commit();
        api_json(['success' => true, 'message' => 'Draft order deleted.']);
    }

    if ($action === 'update_unit_prices') {
        require_role_or_permission(['admin'], 'sr_purchase_orders.update', 'purchase_orders.update');
        $orderId = (int)($input['id'] ?? $input['order_id'] ?? 0);
        $prices = is_array($input['unit_prices'] ?? null) ? $input['unit_prices'] : [];
        $order = purchase_order_load($pdo, $orderId);
        if (!$order) {
            api_error('Purchase order not found.', 404);
        }
        if (!$prices) {
            api_error('No unit prices provided.');
        }

        $pdo->beginTransaction();
        $subtotal = 0.0;
        foreach ($prices as $itemId => $price) {
            $itemId = (int)$itemId;
            $price = purchase_api_num($price);
            if ($itemId <= 0 || $price < 0) {
                continue;
            }
            $stmt = $pdo->prepare('SELECT quantity_ordered FROM purchase_order_items WHERE id = ? AND purchase_order_id = ?');
            $stmt->execute([$itemId, $orderId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                continue;
            }
            $qty = (float)$item['quantity_ordered'];
            $lineTotal = $qty * $price;
            $pdo->prepare('UPDATE purchase_order_items SET unit_price = ?, line_total = ? WHERE id = ?')
                ->execute([$price, $lineTotal, $itemId]);
            $subtotal += $lineTotal;
        }
        $taxRate = (float)($order['tax_rate'] ?? 0);
        $shipping = (float)($order['shipping_cost'] ?? 0);
        $taxAmount = $subtotal * ($taxRate / 100);
        $totalAmount = $subtotal + $taxAmount + $shipping;
        $pdo->prepare('UPDATE purchase_orders SET subtotal = ?, tax_amount = ?, total_amount = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$subtotal, $taxAmount, $totalAmount, $orderId]);
        $pdo->commit();
        api_json(['success' => true, 'message' => 'Unit prices updated.']);
    }

    if ($action === 'cancel_order_items') {
        require_role_or_permission(['admin'], 'sr_purchase_orders.update', 'purchase_orders.update');
        $orderId = (int)($input['id'] ?? $input['order_id'] ?? 0);
        $adjustments = is_array($input['item_adjustments'] ?? null) ? $input['item_adjustments'] : [];
        $reason = purchase_api_str($input['cancel_reason'] ?? $input['reason'] ?? '');
        $order = purchase_order_load($pdo, $orderId);
        if (!$order) {
            api_error('Purchase order not found.', 404);
        }
        if (in_array((string)($order['status'] ?? ''), ['received', 'cancelled'], true)) {
            api_error('Cannot adjust items: order is fully received or cancelled.');
        }
        if ($reason === '') {
            api_error('Please provide a reason for the item cancellation/reduction.');
        }
        if (!$adjustments) {
            api_error('No item adjustments provided.');
        }

        $pdo->beginTransaction();
        foreach ($adjustments as $itemId => $newQty) {
            $itemId = (int)$itemId;
            $newQty = purchase_api_num($newQty);
            if ($itemId <= 0) {
                continue;
            }
            $stmt = $pdo->prepare('
                SELECT poi.quantity_ordered, poi.unit_price,
                       COALESCE(poi.quantity_received, 0) AS received
                FROM purchase_order_items poi
                WHERE poi.id = ? AND poi.purchase_order_id = ?
            ');
            $stmt->execute([$itemId, $orderId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                continue;
            }
            $received = (float)($item['received'] ?? 0);
            if ($newQty < $received) {
                $newQty = $received;
            }
            if ($newQty <= 0 && $received <= 0) {
                $pdo->prepare('DELETE FROM purchase_order_items WHERE id = ?')->execute([$itemId]);
                continue;
            }
            if ($newQty < (float)$item['quantity_ordered']) {
                $unitPrice = (float)$item['unit_price'];
                $pdo->prepare('UPDATE purchase_order_items SET quantity_ordered = ?, line_total = ? WHERE id = ?')
                    ->execute([$newQty, $newQty * $unitPrice, $itemId]);
            }
        }

        $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity_ordered * unit_price), 0) FROM purchase_order_items WHERE purchase_order_id = ?');
        $sumStmt->execute([$orderId]);
        $subtotal = (float)$sumStmt->fetchColumn();
        $taxRate = (float)($order['tax_rate'] ?? 0);
        $shipping = (float)($order['shipping_cost'] ?? 0);
        $taxAmount = $subtotal * ($taxRate / 100);
        $totalAmount = $subtotal + $taxAmount + $shipping;
        $adjNote = "\n[Item adjustment " . date('Y-m-d H:i') . "] Reason: " . $reason;
        $newNotes = ((string)($order['notes'] ?? '')) . $adjNote;

        $chk = $pdo->prepare('
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN COALESCE(quantity_received,0) >= quantity_ordered THEN 1 ELSE 0 END) AS completed
            FROM purchase_order_items
            WHERE purchase_order_id = ?
        ');
        $chk->execute([$orderId]);
        $chkRow = $chk->fetch(PDO::FETCH_ASSOC);
        $newStatus = ($chkRow && (int)$chkRow['total'] > 0 && (int)$chkRow['completed'] === (int)$chkRow['total']) ? 'received' : null;

        if ($newStatus !== null) {
            $pdo->prepare('UPDATE purchase_orders SET subtotal = ?, tax_amount = ?, total_amount = ?, notes = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$subtotal, $taxAmount, $totalAmount, $newNotes, $newStatus, $orderId]);
        } else {
            $pdo->prepare('UPDATE purchase_orders SET subtotal = ?, tax_amount = ?, total_amount = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$subtotal, $taxAmount, $totalAmount, $newNotes, $orderId]);
        }
        $pdo->commit();
        api_json(['success' => true, 'message' => 'Order items updated.']);
    }

    api_error('Unknown action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e->getMessage(), 500);
}
