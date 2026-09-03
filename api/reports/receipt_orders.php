<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../helpers.php';

function ro_date(?string $value): ?string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function ro_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ro_payload(): array
{
    $raw = (string)file_get_contents('php://input');
    $data = $raw !== '' ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

function ro_slug(string $value): string
{
    $slug = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', $value));
    $slug = trim($slug, '_');
    return $slug !== '' ? $slug : 'seller';
}

function ro_resolve_seller_id(PDO $pdo, array $payload, array $user): int
{
    $mode = strtolower(trim((string)($payload['seller_mode'] ?? 'existing')));
    if ($mode !== 'custom') {
        return (int)($payload['seller_id'] ?? ($user['id'] ?? 0));
    }

    $name = trim((string)($payload['seller_name_custom'] ?? ''));
    if ($name === '') {
        return 0;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'seller' AND (LOWER(name) = LOWER(?) OR LOWER(username) = LOWER(?)) LIMIT 1");
    $stmt->execute([$name, $name]);
    $existing = (int)($stmt->fetchColumn() ?: 0);
    if ($existing > 0) {
        return $existing;
    }

    $base = 'seller_' . ro_slug($name);
    $username = substr($base, 0, 42);
    $suffix = 1;
    $check = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    while (true) {
        $check->execute([$username]);
        if (!$check->fetchColumn()) {
            break;
        }
        $suffix++;
        $username = substr($base, 0, 38) . '_' . $suffix;
    }

    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, name, role, active) VALUES (?, SHA2(?, 256), ?, 'seller', 1)");
    $stmt->execute([$username, bin2hex(random_bytes(8)), $name]);
    return (int)$pdo->lastInsertId();
}

function ro_options(PDO $pdo): array
{
    $month = date('Y-m');
    return [
        'products' => ro_rows($pdo, "
            SELECT
                p.id AS value,
                p.name AS label,
                COALESCE(pc.selling_price, p.cost, 0) AS cost
            FROM products p
            LEFT JOIN product_costs pc ON pc.product_id = p.id AND pc.month_year = ?
            WHERE p.active = 1
            ORDER BY p.name
        ", [$month]),
        'sellers' => ro_rows($pdo, "
            SELECT id AS value, COALESCE(NULLIF(name, ''), username, CONCAT('Seller #', id)) AS label
            FROM users
            WHERE role = 'seller'
            ORDER BY label
        "),
        'pages' => ro_rows($pdo, 'SELECT id AS value, name AS label FROM pages ORDER BY name'),
        'delivery_types' => ro_rows($pdo, 'SELECT id AS value, name AS label FROM delivery_types ORDER BY name'),
        'delivery_costs' => ro_rows($pdo, 'SELECT id AS value, CONCAT(label, " - $", FORMAT(amount, 2)) AS label, amount FROM delivery_costs ORDER BY amount'),
    ];
}

try {
    $pdo = get_db_connection();
    $action = strtolower(trim((string)($_GET['action'] ?? 'list')));

    if ($action === 'options') {
        require_role_or_permission(['admin', 'seller'], 'receipts.view');
        api_json(['success' => true, 'options' => ro_options($pdo)]);
    }

    if ($action === 'list') {
        require_role_or_permission(['admin', 'seller'], 'receipts.view');
        $search = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $sellerId = filter_var($_GET['seller_id'] ?? null, FILTER_VALIDATE_INT);
        $from = ro_date($_GET['from'] ?? null);
        $to = ro_date($_GET['to'] ?? null);

        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(ro.receipt_code LIKE ? OR ro.customer_name LIKE ? OR ro.phone LIKE ? OR ro.location LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (in_array($status, ['preparing', 'completed', 'cancelled'], true)) {
            $where[] = 'ro.status = ?';
            $params[] = $status;
        }
        if ($sellerId !== false && $sellerId !== null) {
            $where[] = 'ro.seller_id = ?';
            $params[] = (int)$sellerId;
        }
        if ($from) {
            $where[] = 'DATE(ro.created_at) >= ?';
            $params[] = $from;
        }
        if ($to) {
            $where[] = 'DATE(ro.created_at) <= ?';
            $params[] = $to;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $rows = ro_rows($pdo, "
            SELECT
                ro.id,
                ro.receipt_code,
                ro.customer_name,
                ro.phone,
                ro.location,
                ro.status,
                ro.total_amount,
                ro.discount,
                ro.created_at,
                COALESCE(NULLIF(seller.name, ''), seller.username, '') AS seller_name,
                COALESCE(NULLIF(created_by.name, ''), created_by.username, 'Unknown') AS created_by_name,
                COALESCE(COUNT(roi.id), 0) AS item_count,
                COALESCE(GROUP_CONCAT(CONCAT(p.name, ' x', roi.quantity) ORDER BY p.name SEPARATOR ', '), '') AS product_list
            FROM receipt_orders ro
            LEFT JOIN users seller ON seller.id = ro.seller_id
            LEFT JOIN users created_by ON created_by.id = ro.created_by
            LEFT JOIN receipt_order_items roi ON roi.receipt_order_id = ro.id
            LEFT JOIN products p ON p.id = roi.product_id
            {$whereSql}
            GROUP BY ro.id
            ORDER BY ro.created_at DESC, ro.id DESC
            LIMIT 500
        ", $params);

        $summary = [
            'total_receipts' => count($rows),
            'preparing' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'total_amount' => 0.0,
        ];
        foreach ($rows as $row) {
            $rowStatus = (string)($row['status'] ?? '');
            if (isset($summary[$rowStatus])) {
                $summary[$rowStatus]++;
            }
            $summary['total_amount'] += (float)($row['total_amount'] ?? 0);
        }

        api_json(['success' => true, 'rows' => $rows, 'summary' => $summary]);
    }

    if ($action === 'create') {
        require_role_or_permission(['admin', 'seller'], 'receipts.create');
        $user = current_user();
        $payload = ro_payload();
        $customerName = trim((string)($payload['customer_name'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));
        $location = trim((string)($payload['location'] ?? ''));
        $sellerMode = strtolower(trim((string)($payload['seller_mode'] ?? 'existing')));
        $customSellerName = trim((string)($payload['seller_name_custom'] ?? ''));
        $sellerId = $sellerMode === 'custom' ? 0 : (int)($payload['seller_id'] ?? ($user['id'] ?? 0));
        $pageId = (int)($payload['page_id'] ?? 0);
        $deliveryTypeId = (int)($payload['delivery_type_id'] ?? 0);
        $deliveryCostId = (int)($payload['delivery_cost_id'] ?? 0);
        $discount = max(0, (float)($payload['discount'] ?? 0));
        $notes = trim((string)($payload['notes'] ?? ''));
        $itemsInput = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        $errors = [];
        if ($customerName === '') $errors[] = 'Customer name is required.';
        $phoneError = validate_customer_phones($phone);
        if ($phoneError !== null) $errors[] = $phoneError;
        if ($location === '') $errors[] = 'Location is required.';
        if ($deliveryCostId <= 0) $errors[] = 'Delivery cost is required.';
        if ($sellerMode === 'custom') {
            if ($customSellerName === '') $errors[] = 'Seller name is required.';
        } elseif ($sellerId <= 0) {
            $errors[] = 'Seller selection is required.';
        }

        $month = date('Y-m');
        $itemsByProduct = [];
        foreach ($itemsInput as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = max(0, (int)($item['quantity'] ?? 0));
            if ($productId <= 0 || $quantity <= 0) continue;

            $stmt = $pdo->prepare("
                SELECT p.id, p.name, COALESCE(pc.selling_price, p.cost, 0) AS cost
                FROM products p
                LEFT JOIN product_costs pc ON pc.product_id = p.id AND pc.month_year = ?
                WHERE p.id = ? AND p.active = 1
            ");
            $stmt->execute([$month, $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) continue;

            $key = (int)$product['id'];
            if (!isset($itemsByProduct[$key])) {
                $itemsByProduct[$key] = [
                    'product_id' => $key,
                    'unit_cost' => (float)$product['cost'],
                    'quantity' => 0,
                    'line_total' => 0.0,
                ];
            }
            $itemsByProduct[$key]['quantity'] += $quantity;
            $itemsByProduct[$key]['line_total'] = $itemsByProduct[$key]['unit_cost'] * $itemsByProduct[$key]['quantity'];
        }

        $items = array_values($itemsByProduct);
        if (!$items) $errors[] = 'Please select at least one product to prepare.';
        if ($errors) api_error(implode(' ', $errors), 422);

        if ($sellerMode === 'custom') {
            $sellerId = ro_resolve_seller_id($pdo, $payload, $user);
            if ($sellerId <= 0) api_error('Seller name is required.', 422);
        }

        $totalProducts = array_sum(array_map(static fn($item) => (float)$item['line_total'], $items));
        $grandTotal = max(0, $totalProducts - $discount);

        $pdo->beginTransaction();
        try {
            $receiptCode = 'REC' . date('ymd') . str_pad((string)random_int(1, 999), 3, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare('INSERT INTO receipt_orders (receipt_code, customer_name, seller_id, created_by, phone, location, page_id, delivery_type_id, delivery_cost_id, discount, total_amount, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $receiptCode,
                $customerName,
                $sellerId,
                (int)($user['id'] ?? 0),
                $phone,
                $location,
                $pageId ?: null,
                $deliveryTypeId ?: null,
                $deliveryCostId ?: null,
                $discount,
                $grandTotal,
                $notes ?: null,
            ]);

            $receiptId = (int)$pdo->lastInsertId();
            $itemStmt = $pdo->prepare('INSERT INTO receipt_order_items (receipt_order_id, product_id, quantity, unit_cost, line_total) VALUES (?,?,?,?,?)');
            foreach ($items as $item) {
                $itemStmt->execute([$receiptId, $item['product_id'], $item['quantity'], $item['unit_cost'], $item['line_total']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        api_json([
            'success' => true,
            'id' => $receiptId,
            'receipt_code' => $receiptCode,
            'message' => 'Receipt order created successfully.',
        ]);
    }

    if ($action === 'get') {
        require_role_or_permission(['admin', 'seller'], 'receipts.view');
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) api_error('Receipt order is required.', 422);

        $stmt = $pdo->prepare('
            SELECT id, receipt_code, customer_name, phone, location, seller_id, page_id,
                   delivery_type_id, delivery_cost_id, discount, notes, status
            FROM receipt_orders
            WHERE id = ?
            LIMIT 1
        ');
        $stmt->execute([$id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) api_error('Receipt order not found.', 404);

        $itemsStmt = $pdo->prepare('SELECT product_id, quantity FROM receipt_order_items WHERE receipt_order_id = ? ORDER BY id');
        $itemsStmt->execute([$id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        api_json([
            'success' => true,
            'receipt' => [
                'id' => (int)$order['id'],
                'receipt_code' => $order['receipt_code'],
                'customer_name' => $order['customer_name'],
                'phone' => $order['phone'],
                'location' => $order['location'],
                'seller_id' => $order['seller_id'] !== null ? (int)$order['seller_id'] : '',
                'page_id' => $order['page_id'] !== null ? (int)$order['page_id'] : '',
                'delivery_type_id' => $order['delivery_type_id'] !== null ? (int)$order['delivery_type_id'] : '',
                'delivery_cost_id' => $order['delivery_cost_id'] !== null ? (int)$order['delivery_cost_id'] : '',
                'discount' => (float)$order['discount'],
                'notes' => (string)($order['notes'] ?? ''),
                'status' => (string)($order['status'] ?? 'preparing'),
                'items' => array_map(static fn($item) => [
                    'product_id' => (int)$item['product_id'],
                    'quantity' => (int)$item['quantity'],
                ], $items),
            ],
        ]);
    }

    if ($action === 'update') {
        require_role_or_permission(['admin'], 'receipts.update');
        $payload = ro_payload();
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) api_error('Receipt order is required.', 422);

        $existsStmt = $pdo->prepare('SELECT id FROM receipt_orders WHERE id = ? LIMIT 1');
        $existsStmt->execute([$id]);
        if (!$existsStmt->fetchColumn()) api_error('Receipt order not found.', 404);

        $customerName = trim((string)($payload['customer_name'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));
        $location = trim((string)($payload['location'] ?? ''));
        $sellerId = (int)($payload['seller_id'] ?? 0);
        $pageId = (int)($payload['page_id'] ?? 0);
        $deliveryTypeId = (int)($payload['delivery_type_id'] ?? 0);
        $deliveryCostId = (int)($payload['delivery_cost_id'] ?? 0);
        $discount = max(0, (float)($payload['discount'] ?? 0));
        $notes = trim((string)($payload['notes'] ?? ''));
        $statusRaw = strtolower(trim((string)($payload['status'] ?? 'preparing')));
        $status = in_array($statusRaw, ['preparing', 'completed', 'cancelled'], true) ? $statusRaw : 'preparing';
        $itemsInput = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        $errors = [];
        if ($customerName === '') $errors[] = 'Customer name is required.';
        $phoneError = validate_customer_phones($phone);
        if ($phoneError !== null) $errors[] = $phoneError;
        if ($location === '') $errors[] = 'Location is required.';
        if ($deliveryCostId <= 0) $errors[] = 'Delivery cost is required.';
        if ($sellerId <= 0) $errors[] = 'Seller selection is required.';

        $month = date('Y-m');
        $itemsByProduct = [];
        foreach ($itemsInput as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = max(0, (int)($item['quantity'] ?? 0));
            if ($productId <= 0 || $quantity <= 0) continue;

            $stmt = $pdo->prepare("
                SELECT p.id, p.name, COALESCE(pc.selling_price, p.cost, 0) AS cost
                FROM products p
                LEFT JOIN product_costs pc ON pc.product_id = p.id AND pc.month_year = ?
                WHERE p.id = ? AND p.active = 1
            ");
            $stmt->execute([$month, $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) continue;

            $key = (int)$product['id'];
            if (!isset($itemsByProduct[$key])) {
                $itemsByProduct[$key] = [
                    'product_id' => $key,
                    'unit_cost' => (float)$product['cost'],
                    'quantity' => 0,
                    'line_total' => 0.0,
                ];
            }
            $itemsByProduct[$key]['quantity'] += $quantity;
            $itemsByProduct[$key]['line_total'] = $itemsByProduct[$key]['unit_cost'] * $itemsByProduct[$key]['quantity'];
        }

        $items = array_values($itemsByProduct);
        if (!$items) $errors[] = 'Please select at least one product to prepare.';
        if ($errors) api_error(implode(' ', $errors), 422);

        $totalProducts = array_sum(array_map(static fn($item) => (float)$item['line_total'], $items));
        $grandTotal = max(0, $totalProducts - $discount);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                UPDATE receipt_orders
                SET customer_name = ?, seller_id = ?, phone = ?, location = ?, page_id = ?, delivery_type_id = ?,
                    delivery_cost_id = ?, discount = ?, total_amount = ?, notes = ?, status = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $customerName,
                $sellerId,
                $phone,
                $location,
                $pageId ?: null,
                $deliveryTypeId ?: null,
                $deliveryCostId ?: null,
                $discount,
                $grandTotal,
                $notes ?: null,
                $status,
                $id,
            ]);

            $pdo->prepare('DELETE FROM receipt_order_items WHERE receipt_order_id = ?')->execute([$id]);
            $itemStmt = $pdo->prepare('INSERT INTO receipt_order_items (receipt_order_id, product_id, quantity, unit_cost, line_total) VALUES (?,?,?,?,?)');
            foreach ($items as $item) {
                $itemStmt->execute([$id, $item['product_id'], $item['quantity'], $item['unit_cost'], $item['line_total']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        api_json([
            'success' => true,
            'id' => $id,
            'message' => 'Receipt order updated successfully.',
        ]);
    }

    if ($action === 'delete') {
        require_role_or_permission(['admin'], 'receipts.delete');
        $payload = ro_payload();
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) api_error('Receipt order is required.', 422);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM receipt_order_items WHERE receipt_order_id = ?');
            $stmt->execute([$id]);
            $stmt = $pdo->prepare('DELETE FROM receipt_orders WHERE id = ?');
            $stmt->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        api_json(['success' => true, 'message' => 'Receipt order deleted.']);
    }

    api_error('Unknown receipt order action.', 404);
} catch (Throwable $e) {
    error_log('receipt_orders API error: ' . $e->getMessage());
    api_error('Unable to load receipt orders.', 500);
}
