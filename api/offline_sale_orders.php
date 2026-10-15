<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../admin/offline_lib.php';
require_once __DIR__ . '/../upload_paths.php';

function offline_api_input(): array
{
    if (!empty($_POST) || !empty($_FILES)) {
        $data = $_POST;
        foreach (['sale_items', 'purchase_items', 'keep_images'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $decoded = json_decode($data[$key], true);
                if (is_array($decoded)) {
                    $data[$key] = $decoded;
                }
            }
        }
        return $data;
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function offline_api_date(?string $value): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : date('Y-m-d');
}

function offline_api_num(mixed $value): float
{
    $number = (float)$value;
    return is_finite($number) ? $number : 0.0;
}

function offline_api_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function offline_api_parse_images($value): array
{
    $items = is_array($value) ? $value : json_decode(trim((string)$value), true);
    if (!is_array($items)) {
        return [];
    }
    $clean = [];
    foreach ($items as $item) {
        $path = trim((string)$item);
        if ($path !== '') {
            $clean[$path] = $path;
        }
    }
    return array_values($clean);
}

function offline_api_image_urls($value): array
{
    $urls = [];
    foreach (offline_api_parse_images($value) as $path) {
        $url = trim((string)uploaded_file_url($path, 'offline_order_images'));
        if ($url !== '') {
            $urls[] = $url;
        }
    }
    return $urls;
}

function offline_api_upload_images(?string $date = null): array
{
    $files = $_FILES['images'] ?? null;
    if (!$files) {
        return [];
    }
    $list = [];
    if (is_array($files['name'] ?? null)) {
        foreach ($files['name'] as $index => $name) {
            $list[] = [
                'name' => (string)$name,
                'tmp_name' => (string)($files['tmp_name'][$index] ?? ''),
                'error' => (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($files['size'][$index] ?? 0),
                'type' => (string)($files['type'][$index] ?? ''),
            ];
        }
    } else {
        $list[] = $files;
    }
    $saved = [];
    foreach ($list as $file) {
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) || (int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
            continue;
        }
        $filename = 'offline_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $path = upload_store_uploaded_file($file, 'offline_order_images', $filename, $date, (string)($file['type'] ?? ''));
        if ($path !== '') {
            $saved[] = $path;
        }
    }
    return $saved;
}

function offline_api_store_images($existing, $keep, ?string $date = null): string
{
    $current = offline_api_parse_images($existing);
    $kept = $keep === null ? $current : offline_api_parse_images($keep);
    $keepSet = array_fill_keys($kept, true);
    foreach ($current as $path) {
        if (!isset($keepSet[$path])) {
            upload_delete_local_file($path, 'offline_order_images');
        }
    }
    $merged = array_slice(array_values(array_unique(array_merge($kept, offline_api_upload_images($date)))), 0, 6);
    return $merged ? json_encode($merged, JSON_UNESCAPED_SLASHES) : '';
}

function offline_api_product_options(array $products): array
{
    return array_map(static function (array $product): array {
        return [
            'value' => (int)$product['id'],
            'label' => (string)$product['name'],
            'sku' => (string)($product['sku'] ?? ''),
            'barcode' => (string)($product['barcode'] ?? ''),
            'brand_id' => (int)($product['brand_id'] ?? 0),
            'selling_price' => (float)($product['selling_price'] ?? 0),
            'unit_cost' => (float)($product['unit_cost'] ?? 0),
        ];
    }, $products);
}

function offline_api_options(PDO $pdo): void
{
    require_role_or_permission(['admin'], 'sr_offline_orders.view', 'sr_offline_orders.create', 'offline_sales.view', 'offline_sales.create');

    $includeProducts = (string)($_GET['include_products'] ?? '1') !== '0';
    $products = $includeProducts ? offline_products($pdo) : [];
    $teams = $pdo->query("
        SELECT id AS value, name AS label, location_id
        FROM offline_teams
        WHERE is_active = 1
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $brands = $pdo->query("
        SELECT id AS value, name AS label, color AS brand_color
        FROM brands
        WHERE active = 1
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $paymentMethods = $pdo->query("
        SELECT option_text AS value, option_text AS label
        FROM note_options
        WHERE is_active = 1 AND is_admin_active = 1
        ORDER BY sort_order, option_text
    ")->fetchAll(PDO::FETCH_ASSOC);
    $orders = $pdo->query("
        SELECT
            o.id,
            o.order_code,
            o.sale_date,
            o.location_id,
            o.team_id,
            COALESCE(o.customer_name, '') AS customer_name,
            COALESCE(o.phone, '') AS phone,
            COALESCE(o.customer_location, '') AS customer_location,
            COALESCE(t.name, 'Unassigned') AS team_name,
            o.status,
            o.subtotal,
            o.discount,
            o.purchase_total,
            o.total_amount,
            o.received_amount,
            o.payment_date,
            o.due_date,
            o.collection_assigned_to,
            o.images,
            GREATEST(o.total_amount - o.received_amount, 0) AS balance_amount,
            o.created_at,
            o.updated_at,
            COALESCE(uc.name, '') AS created_by_name,
            COALESCE(uu.name, '') AS updated_by_name
        FROM offline_sale_orders o
        LEFT JOIN offline_teams t ON t.id = o.team_id
        LEFT JOIN users uc ON uc.id = o.created_by
        LEFT JOIN users uu ON uu.id = o.updated_by
        ORDER BY o.sale_date DESC, o.created_at DESC, o.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $itemsByOrder = [];
    $paymentsByOrder = [];
    $logsByOrder = [];
    $logCounts = [];
    $includeLogs = (string)($_GET['include_logs'] ?? '1') !== '0';
    if ($orders) {
        $orderIds = array_map(static fn(array $row): int => (int)$row['id'], $orders);
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $itemStmt = $pdo->prepare("
            SELECT order_id, product_id, product_name, quantity, unit_price, line_total
            FROM offline_sale_order_items
            WHERE order_id IN ($placeholders)
            ORDER BY order_id, id
        ");
        $itemStmt->execute($orderIds);
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $itemsByOrder[(int)$item['order_id']][] = [
                'type' => 'sale',
                'product_id' => (int)$item['product_id'],
                'product_name' => (string)$item['product_name'],
                'quantity' => (float)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'line_total' => (float)$item['line_total'],
            ];
        }
        $purchaseStmt = $pdo->prepare("
            SELECT order_id, product_id, product_name, quantity, unit_price, line_total, item_condition, reason
            FROM offline_sale_purchase_items
            WHERE order_id IN ($placeholders)
            ORDER BY order_id, id
        ");
        $purchaseStmt->execute($orderIds);
        foreach ($purchaseStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $itemsByOrder[(int)$item['order_id']][] = [
                'type' => 'purchase',
                'product_id' => (int)$item['product_id'],
                'product_name' => (string)$item['product_name'],
                'quantity' => (float)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'line_total' => (float)$item['line_total'],
                'condition' => (string)($item['item_condition'] ?? 'good'),
                'reason' => (string)($item['reason'] ?? 'Customer purchase'),
            ];
        }
        $paymentsByOrder = offline_payments_for_orders($pdo, $orderIds);
        if ($includeLogs) {
            $logsByOrder = offline_logs_for_orders($pdo, $orderIds);
        } else {
            $countStmt = $pdo->prepare("
                SELECT order_id, COUNT(*) AS log_count
                FROM offline_sale_order_logs
                WHERE order_id IN ($placeholders)
                GROUP BY order_id
            ");
            $countStmt->execute($orderIds);
            foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $logCounts[(int)$row['order_id']] = (int)$row['log_count'];
            }
        }
    }

    $orders = array_map(static function (array $order) use ($itemsByOrder, $paymentsByOrder, $logsByOrder, $logCounts, $includeLogs): array {
        $orderId = (int)$order['id'];
        $order['items'] = $itemsByOrder[$orderId] ?? [];
        $order['payments'] = array_map(static function (array $payment): array {
            return [
                'id' => (int)$payment['id'],
                'payment_date' => (string)$payment['payment_date'],
                'amount' => (float)$payment['amount'],
                'payment_method' => (string)($payment['payment_method'] ?? ''),
                'paid_note' => (string)($payment['paid_note'] ?? ''),
                'created_by_name' => (string)($payment['created_by_name'] ?? ''),
            ];
        }, $paymentsByOrder[$orderId] ?? []);
        $logs = $logsByOrder[$orderId] ?? [];
        $order['log_count'] = $includeLogs ? count($logs) : ($logCounts[$orderId] ?? 0);
        $order['logs'] = $includeLogs ? array_map(static function (array $log): array {
            return [
                'id' => (int)$log['id'],
                'action' => (string)$log['action'],
                'description' => (string)($log['description'] ?? ''),
                'created_at' => (string)$log['created_at'],
                'created_by_name' => (string)($log['created_by_name'] ?? ''),
            ];
        }, $logs) : [];
        $order['images'] = offline_api_parse_images($order['images'] ?? '');
        $order['image_urls'] = offline_api_image_urls($order['images']);
        $order['image_url'] = $order['image_urls'][0] ?? '';
        return $order;
    }, $orders);

    api_json([
        'success' => true,
        'options' => [
            'products' => offline_api_product_options($products),
            'teams' => $teams,
            'brands' => $brands,
            'payment_methods' => $paymentMethods,
        ],
        'orders' => $orders,
        'recent_orders' => array_slice($orders, 0, 12),
    ]);
}

function offline_api_location_products(PDO $pdo): void
{
    require_role_or_permission(['admin'], 'sr_offline_orders.view', 'sr_offline_orders.create', 'offline_sales.view', 'offline_sales.create');

    $teamId = (int)($_GET['team_id'] ?? 0);
    $locationId = (int)($_GET['location_id'] ?? 0);
    if ($teamId > 0) {
        $teamStmt = $pdo->prepare('SELECT location_id FROM offline_teams WHERE id = ? LIMIT 1');
        $teamStmt->execute([$teamId]);
        $locationId = (int)($teamStmt->fetchColumn() ?: $locationId);
    }
    if ($locationId <= 0) {
        api_json([
            'success' => true,
            'products' => [],
            'location_id' => 0,
            'location_name' => '',
            'message' => 'No storage location assigned to this team.',
        ]);
    }

    $nameStmt = $pdo->prepare('SELECT COALESCE(location_name, location_code) FROM storage_locations WHERE id = ? LIMIT 1');
    $nameStmt->execute([$locationId]);
    $products = array_values(array_filter(
        offline_location_products_with_stock($pdo, $locationId, offline_products($pdo)),
        static fn(array $product): bool => (float)($product['available_stock'] ?? 0) > 0
    ));
    api_json([
        'success' => true,
        'products' => $products,
        'location_id' => $locationId,
        'location_name' => (string)($nameStmt->fetchColumn() ?: ''),
    ]);
}

function offline_api_create_order(PDO $pdo, array $user): void
{
    require_role_or_permission(['admin'], 'sr_offline_orders.create', 'offline_sales.create');

    $payload = offline_api_input();
    $customerName = trim((string)($payload['customer_name'] ?? ''));
    $phone = trim((string)($payload['phone'] ?? ''));
    $customerLocation = trim((string)($payload['customer_location'] ?? ''));
    $saleDate = offline_api_date($payload['sale_date'] ?? null);
    $dueDate = trim((string)($payload['due_date'] ?? ''));
    $collectionAssignedTo = trim((string)($payload['collection_assigned_to'] ?? ''));
    if (offline_api_text_length($collectionAssignedTo) > 255) {
        api_error('Collection Assigned To cannot exceed 255 characters.', 422);
    }
    $teamId = (int)($payload['team_id'] ?? 0);
    $discount = max(0, offline_api_num($payload['discount'] ?? 0));
    $receivedAmount = max(0, offline_api_num($payload['received_amount'] ?? 0));
    $paymentMethod = trim((string)($payload['payment_method'] ?? ''));
    $paidNote = trim((string)($payload['paid_note'] ?? ''));
    $saleItemsRaw = is_array($payload['sale_items'] ?? null) ? $payload['sale_items'] : [];
    $purchaseItemsRaw = is_array($payload['purchase_items'] ?? null) ? $payload['purchase_items'] : [];
    $keepImages = array_key_exists('keep_images', $payload) && is_array($payload['keep_images']) ? $payload['keep_images'] : null;

    if ($customerName === '' || $phone === '' || $customerLocation === '' || $teamId <= 0) {
        api_error('Customer name, phone, location, and team are required.', 422);
    }
    if ($receivedAmount > 0 && $paymentMethod === '') {
        api_error('Payment method is required when received amount is greater than zero.', 422);
    }

    $locStmt = $pdo->prepare('SELECT location_id FROM offline_teams WHERE id = ? AND is_active = 1 LIMIT 1');
    $locStmt->execute([$teamId]);
    $locationId = (int)($locStmt->fetchColumn() ?: 0);
    if ($locationId <= 0) {
        api_error('Selected team does not have an offline stock location.', 422);
    }

    $allProducts = offline_products($pdo);
    $allProductsById = [];
    foreach ($allProducts as $product) {
        $allProductsById[(int)$product['id']] = $product;
    }

    $items = offline_sale_items_from_rows($saleItemsRaw, $allProductsById);
    if (!$items) {
        api_error('Add at least one sale product.', 422);
    }

    $purchaseItems = [];
    foreach ($purchaseItemsRaw as $raw) {
        $pid = (int)($raw['product_id'] ?? 0);
        $qty = offline_api_num($raw['quantity'] ?? 0);
        $price = offline_api_num($raw['unit_price'] ?? 0);
        $condition = strtolower(trim((string)($raw['condition'] ?? 'good')));
        $reason = trim((string)($raw['reason'] ?? 'Customer purchase'));
        if ($pid <= 0 || $qty <= 0 || !isset($allProductsById[$pid])) {
            continue;
        }
        if ($price < 0 || !in_array($condition, ['good', 'fair', 'poor', 'damaged'], true) || $reason === '') {
            api_error('Every purchased product needs a valid purchase price, condition, and reason.', 422);
        }
        $key = $pid . '|' . $condition . '|' . $reason . '|' . number_format($price, 2, '.', '');
        if (!isset($purchaseItems[$key])) {
            $purchaseItems[$key] = $allProductsById[$pid];
            $purchaseItems[$key]['quantity'] = 0.0;
            $purchaseItems[$key]['purchase_price'] = max(0, $price);
            $purchaseItems[$key]['condition'] = $condition;
            $purchaseItems[$key]['reason'] = $reason;
        }
        $purchaseItems[$key]['quantity'] += $qty;
    }

    foreach (offline_sale_stock_totals($items) as $item) {
        $inv = offline_inventory_row($pdo, $locationId, (string)$item['name']);
        if (!$inv || (float)$inv['quantity_on_hand'] + 0.009 < (float)$item['quantity']) {
            api_error('Insufficient offline stock for ' . $item['name'], 422);
        }
    }

    $subtotal = array_reduce($items, static fn(float $sum, array $item): float => $sum + ((float)$item['selling_price'] * (float)$item['quantity']), 0.0);
    $purchaseTotal = array_reduce($purchaseItems, static fn(float $sum, array $item): float => $sum + ((float)$item['purchase_price'] * (float)$item['quantity']), 0.0);
    $total = max(0, $subtotal - $discount - $purchaseTotal);
    $paidBackAmount = max(0, $purchaseTotal + $discount - $subtotal);
    $status = $total <= 0.009 ? 'paid' : 'unpaid';
    $balance = max(0, $total - min($total, $receivedAmount));
    if ($balance > 0.009) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            api_error('Due date is required for partial or unpaid orders.', 422);
        }
        if ($dueDate < $saleDate) {
            api_error('Due date cannot be before the sale date.', 422);
        }
        if ($collectionAssignedTo === '') {
            api_error('Collection Assigned To is required for partial or unpaid orders.', 422);
        }
    } else {
        $dueDate = '';
        $collectionAssignedTo = '';
    }

    try {
        $pdo->beginTransaction();
        $orderCode = offline_next_order_code($pdo);
        $userId = isset($user['id']) ? (int)$user['id'] : null;
        $stmt = $pdo->prepare("INSERT INTO offline_sale_orders (order_code, customer_name, phone, customer_location, location_id, team_id, sale_date, due_date, collection_assigned_to, status, subtotal, discount, purchase_total, total_amount, received_amount, payment_date, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, ?, ?)");
        $stmt->execute([$orderCode, $customerName, $phone, $customerLocation, $locationId, $teamId, $saleDate, $dueDate ?: null, $collectionAssignedTo ?: null, $status, $subtotal, $discount, $purchaseTotal, $total, $userId, $userId]);
        $orderId = (int)$pdo->lastInsertId();
        $storedImages = offline_api_store_images([], $keepImages, $saleDate);
        if ($storedImages !== '') {
            $pdo->prepare('UPDATE offline_sale_orders SET images = ? WHERE id = ?')->execute([$storedImages, $orderId]);
        }
        offline_log_order_activity($pdo, $orderId, 'created', 'Order created', $userId);

        $itemStmt = $pdo->prepare("INSERT INTO offline_sale_order_items (order_id, product_id, product_name, quantity, unit_price, line_total, unit_cost) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($items as $item) {
            $pid = (int)$item['id'];
            $qty = (float)$item['quantity'];
            $inv = offline_inventory_row($pdo, $locationId, (string)$item['name']);
            $prev = (float)$inv['quantity_on_hand'];
            $new = $prev - $qty;
            $unitCost = (float)($inv['unit_cost'] ?? $item['unit_cost'] ?? 0);
            offline_save_inventory($pdo, $locationId, (string)$item['name'], $new, $unitCost, $inv['sku'] ?? null, (int)$inv['id']);
            offline_cleanup_inventory_duplicates($pdo, (int)$inv['id'], (array)($inv['duplicate_ids'] ?? []));
            $lineTotal = (float)$item['selling_price'] * $qty;
            $itemStmt->execute([$orderId, $pid, $item['name'], $qty, $item['selling_price'], $lineTotal, $unitCost]);
            offline_insert_stock_movement($pdo, (int)$pid, 'out', $qty, $prev, $new, 'offline_sale', $orderCode, 'Offline sale ' . $orderCode . ' [Location:' . $locationId . ']', $unitCost, offline_current_user_label($user), $locationId, null);
        }

        $purchaseStmt = $pdo->prepare("INSERT INTO offline_sale_purchase_items (order_id, product_id, product_name, quantity, unit_price, line_total, item_condition, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($purchaseItems as $item) {
            $pid = (int)$item['id'];
            $qty = (float)$item['quantity'];
            $price = (float)$item['purchase_price'];
            $inv = offline_inventory_row($pdo, $locationId, (string)$item['name']);
            $prev = $inv ? (float)$inv['quantity_on_hand'] : 0.0;
            $new = $prev + $qty;
            $oldCost = $inv ? (float)($inv['unit_cost'] ?? 0) : 0.0;
            $weightedCost = $new > 0 ? (($prev * $oldCost) + ($qty * $price)) / $new : $price;
            offline_save_inventory($pdo, $locationId, (string)$item['name'], $new, $weightedCost, $inv['sku'] ?? ($item['sku'] ?? null), $inv ? (int)$inv['id'] : null);
            if ($inv) {
                offline_cleanup_inventory_duplicates($pdo, (int)$inv['id'], (array)($inv['duplicate_ids'] ?? []));
            }
            $purchaseStmt->execute([$orderId, $pid, $item['name'], $qty, $price, $price * $qty, $item['condition'], $item['reason']]);
            offline_insert_stock_movement($pdo, $pid, 'in', $qty, $prev, $new, 'offline_customer_purchase', $orderCode, 'Purchased from customer ' . $orderCode . ' - ' . $item['condition'] . ' / ' . $item['reason'] . ' [Location:' . $locationId . ']', $price, offline_current_user_label($user), null, $locationId);
        }

        if ($receivedAmount > 0) {
            offline_add_order_payment($pdo, $orderId, $saleDate, $receivedAmount, $paymentMethod, $paidNote ?: null, $userId);
        }
        $pdo->commit();

        api_json([
            'success' => true,
            'message' => 'Offline order created.',
            'order' => [
                'id' => $orderId,
                'order_code' => $orderCode,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'purchase_total' => $purchaseTotal,
                'total_amount' => $total,
                'received_amount' => $receivedAmount,
                'balance_amount' => max(0, $total - $receivedAmount),
                'paid_back_amount' => $paidBackAmount,
                'images' => offline_api_parse_images($storedImages),
                'image_urls' => offline_api_image_urls($storedImages),
            ],
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        api_error($e->getMessage(), 500);
    }
}

function offline_api_update_order(PDO $pdo, array $user): void
{
    require_role_or_permission(['admin'], 'sr_offline_orders.update', 'offline_sales.update');

    $payload = offline_api_input();
    $orderId = (int)($payload['id'] ?? 0);
    $customerName = trim((string)($payload['customer_name'] ?? ''));
    $phone = trim((string)($payload['phone'] ?? ''));
    $customerLocation = trim((string)($payload['customer_location'] ?? ''));
    $saleDate = offline_api_date($payload['sale_date'] ?? null);
    $dueDate = trim((string)($payload['due_date'] ?? ''));
    $collectionAssignedTo = trim((string)($payload['collection_assigned_to'] ?? ''));
    if (offline_api_text_length($collectionAssignedTo) > 255) {
        api_error('Collection Assigned To cannot exceed 255 characters.', 422);
    }
    $teamId = (int)($payload['team_id'] ?? 0);
    $discount = max(0, offline_api_num($payload['discount'] ?? 0));
    $targetReceivedAmount = max(0, offline_api_num($payload['received_amount'] ?? 0));
    $paymentMethod = trim((string)($payload['payment_method'] ?? ''));
    $paidNote = trim((string)($payload['paid_note'] ?? ''));
    $saleItemsRaw = is_array($payload['sale_items'] ?? null) ? $payload['sale_items'] : [];
    $purchaseItemsRaw = is_array($payload['purchase_items'] ?? null) ? $payload['purchase_items'] : [];
    $keepImages = array_key_exists('keep_images', $payload) && is_array($payload['keep_images']) ? $payload['keep_images'] : null;

    if ($orderId <= 0) {
        api_error('Invalid order.', 422);
    }
    if ($customerName === '' || $phone === '' || $customerLocation === '' || $teamId <= 0) {
        api_error('Customer name, phone, location, and team are required.', 422);
    }
    if ($targetReceivedAmount > 0 && $paymentMethod === '') {
        api_error('Payment method is required when received amount is greater than zero.', 422);
    }

    $locStmt = $pdo->prepare('SELECT location_id FROM offline_teams WHERE id = ? AND is_active = 1 LIMIT 1');
    $locStmt->execute([$teamId]);
    $locationId = (int)($locStmt->fetchColumn() ?: 0);
    if ($locationId <= 0) {
        api_error('Selected team does not have an offline stock location.', 422);
    }

    $allProducts = offline_products($pdo);
    $allProductsById = [];
    foreach ($allProducts as $product) {
        $allProductsById[(int)$product['id']] = $product;
    }

    $items = offline_sale_items_from_rows($saleItemsRaw, $allProductsById);
    if (!$items) {
        api_error('Add at least one sale product.', 422);
    }

    $purchaseItems = [];
    foreach ($purchaseItemsRaw as $raw) {
        $pid = (int)($raw['product_id'] ?? 0);
        $qty = offline_api_num($raw['quantity'] ?? 0);
        $price = offline_api_num($raw['unit_price'] ?? 0);
        $condition = strtolower(trim((string)($raw['condition'] ?? 'good')));
        $reason = trim((string)($raw['reason'] ?? 'Customer purchase'));
        if ($pid <= 0 || $qty <= 0 || !isset($allProductsById[$pid])) {
            continue;
        }
        if ($price < 0 || !in_array($condition, ['good', 'fair', 'poor', 'damaged'], true) || $reason === '') {
            api_error('Every purchased product needs a valid purchase price, condition, and reason.', 422);
        }
        $key = $pid . '|' . $condition . '|' . $reason . '|' . number_format($price, 2, '.', '');
        if (!isset($purchaseItems[$key])) {
            $purchaseItems[$key] = $allProductsById[$pid];
            $purchaseItems[$key]['quantity'] = 0.0;
            $purchaseItems[$key]['purchase_price'] = max(0, $price);
            $purchaseItems[$key]['condition'] = $condition;
            $purchaseItems[$key]['reason'] = $reason;
        }
        $purchaseItems[$key]['quantity'] += $qty;
    }

    $subtotal = array_reduce($items, static fn(float $sum, array $item): float => $sum + ((float)$item['selling_price'] * (float)$item['quantity']), 0.0);
    $purchaseTotal = array_reduce($purchaseItems, static fn(float $sum, array $item): float => $sum + ((float)$item['purchase_price'] * (float)$item['quantity']), 0.0);
    $total = max(0, $subtotal - $discount - $purchaseTotal);
    $balance = max(0, $total - min($total, $targetReceivedAmount));
    if ($balance > 0.009) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            api_error('Due date is required for partial or unpaid orders.', 422);
        }
        if ($dueDate < $saleDate) {
            api_error('Due date cannot be before the sale date.', 422);
        }
        if ($collectionAssignedTo === '') {
            api_error('Collection Assigned To is required for partial or unpaid orders.', 422);
        }
    } else {
        $dueDate = '';
        $collectionAssignedTo = '';
    }

    try {
        $imageStmt = $pdo->prepare('SELECT images FROM offline_sale_orders WHERE id = ? LIMIT 1');
        $imageStmt->execute([$orderId]);
        $existingImages = (string)($imageStmt->fetchColumn() ?: '');
        offline_update_sale_order(
            $pdo,
            $orderId,
            $customerName,
            $phone,
            $customerLocation,
            $locationId,
            $teamId,
            $saleDate,
            $dueDate ?: null,
            $collectionAssignedTo ?: null,
            $discount,
            $items,
            $purchaseItems,
            $targetReceivedAmount,
            $user,
            $paymentMethod ?: null,
            $paidNote ?: null
        );
        $storedImages = offline_api_store_images($existingImages, $keepImages, $saleDate);
        $pdo->prepare('UPDATE offline_sale_orders SET images = ? WHERE id = ?')->execute([$storedImages ?: null, $orderId]);
        api_json(['success' => true, 'message' => 'Order updated.']);
    } catch (Throwable $e) {
        api_error($e->getMessage(), 422);
    }
}

function offline_api_mark_payment(PDO $pdo, array $user): void
{
    require_role_or_permission(['admin'], 'sr_offline_orders.update', 'offline_sales.update');

    $payload = offline_api_input();
    $orderId = (int)($payload['id'] ?? 0);
    $paymentMethod = trim((string)($payload['payment_method'] ?? ''));
    $paidNote = trim((string)($payload['paid_note'] ?? ''));
    $paymentAmount = max(0, offline_api_num($payload['payment_amount'] ?? 0));
    $paymentDate = offline_api_date($payload['payment_date'] ?? null);

    if ($orderId <= 0) {
        api_error('Invalid order.', 422);
    }
    if ($paymentMethod === '') {
        api_error('Payment method is required.', 422);
    }
    if ($paymentAmount <= 0) {
        api_error('Payment amount must be greater than zero.', 422);
    }

    $orderStmt = $pdo->prepare('SELECT total_amount, received_amount, status FROM offline_sale_orders WHERE id = ? LIMIT 1');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$order) {
        api_error('Order not found.', 404);
    }
    if (in_array(strtolower((string)($order['status'] ?? '')), ['cancelled', 'canceled'], true)) {
        api_error('Cannot record payment for a cancelled order.', 422);
    }

    $payments = offline_payments_for_orders($pdo, [$orderId])[$orderId] ?? [];
    $alreadyReceived = offline_order_paid_from_payments($payments, $order);
    $balanceDue = max(0, (float)$order['total_amount'] - $alreadyReceived);
    if ($paymentAmount > $balanceDue + 0.009) {
        api_error('Payment amount cannot exceed balance due ($' . number_format($balanceDue, 2) . ').', 422);
    }

    offline_add_order_payment($pdo, $orderId, $paymentDate, $paymentAmount, $paymentMethod, $paidNote ?: null, isset($user['id']) ? (int)$user['id'] : null);
    api_json(['success' => true, 'message' => 'Payment recorded.']);
}

function offline_api_cancel_order(PDO $pdo, array $user): void
{
    require_role_or_permission(['admin'], 'sr_offline_orders.update', 'offline_sales.update');

    $payload = offline_api_input();
    $orderId = (int)($payload['id'] ?? 0);
    $cancelNote = trim((string)($payload['cancel_note'] ?? ''));

    if ($orderId <= 0) {
        api_error('Invalid order.', 422);
    }

    offline_cancel_sale_order($pdo, $orderId, $cancelNote, $user);
    api_json(['success' => true, 'message' => 'Order cancelled and stock restored.']);
}

function offline_api_products(PDO $pdo): void
{
    require_role_or_permission(['admin'], 'sr_offline_orders.view', 'sr_offline_orders.create', 'offline_sales.view', 'offline_sales.create');
    api_json([
        'success' => true,
        'products' => offline_api_product_options(offline_products($pdo)),
    ]);
}

function offline_api_order_logs(PDO $pdo): void
{
    require_role_or_permission(['admin'], 'sr_offline_orders.view', 'sr_offline_orders.create', 'offline_sales.view', 'offline_sales.create');

    $orderId = (int)($_GET['id'] ?? 0);
    if ($orderId <= 0) {
        api_error('Invalid order.', 422);
    }

    $logs = offline_logs_for_orders($pdo, [$orderId])[$orderId] ?? [];
    api_json([
        'success' => true,
        'logs' => array_map(static function (array $log): array {
            return [
                'id' => (int)$log['id'],
                'action' => (string)$log['action'],
                'description' => (string)($log['description'] ?? ''),
                'created_at' => (string)$log['created_at'],
                'created_by_name' => (string)($log['created_by_name'] ?? ''),
            ];
        }, $logs),
    ]);
}

try {
    $pdo = get_db_connection();
    offline_ensure_schema($pdo);
    $user = current_user() ?: [];
    $action = (string)($_GET['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'create' : 'options'));

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'mark_payment') {
        offline_api_mark_payment($pdo, $user);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancel_order') {
        offline_api_cancel_order($pdo, $user);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
        offline_api_update_order($pdo, $user);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $action === 'create') {
        offline_api_create_order($pdo, $user);
    }
    if ($action === 'location_products') {
        offline_api_location_products($pdo);
    }
    if ($action === 'logs') {
        offline_api_order_logs($pdo);
    }
    if ($action === 'products') {
        offline_api_products($pdo);
    }
    offline_api_options($pdo);
} catch (Throwable $e) {
    error_log('offline_sale_orders API error: ' . $e->getMessage());
    api_error('Unable to process offline sale order.', 500);
}

