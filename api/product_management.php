<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_role_or_permission(['admin'], 'reports_data.view');

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = get_db_connection();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

    if ($method === 'POST') {
        handlePost($pdo, $action);
    } else {
        handleGet($pdo, $action);
    }
} catch (Throwable $e) {
    error_log('product_management API error: ' . $e->getMessage());
    api_error('Unable to load product management data.', 500);
}

/* ------------------------------------------------------------------ */
/*  POST Handler                                                       */
/* ------------------------------------------------------------------ */

function handlePost(PDO $pdo, string $action): void
{
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    switch ($action) {
        case 'save_brand':
            saveBrand($pdo, $body);
            break;
        case 'delete_brand':
            deleteBrand($pdo, $body);
            break;
        case 'add_product':
            addProduct($pdo, $body);
            break;
        case 'update_cost':
            updateCost($pdo, $body);
            break;
        case 'delete_product':
            deleteProduct($pdo, $body);
            break;
        case 'save_product_set':
            saveProductSet($pdo, $body);
            break;
        case 'delete_product_set':
            deleteProductSet($pdo, $body);
            break;
        case 'save_qr_customer_code':
            saveQrCustomerCode($pdo, $body);
            break;
        case 'delete_qr_customer_code':
            deleteQrCustomerCode($pdo, $body);
            break;
        default:
            api_error('Unknown action: ' . $action, 422);
    }
}

/* ------------------------------------------------------------------ */
/*  GET Handler                                                        */
/* ------------------------------------------------------------------ */

function handleGet(PDO $pdo, string $action): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $brandId = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0;
    $month = trim((string)($_GET['month'] ?? date('Y-m')));
    $productType = trim((string)($_GET['product_type'] ?? 'all'));

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    switch ($action) {
        case 'products':
            $rows = fetchProducts($pdo, $q, $month, $productType, $brandId);
            api_json($rows);
            break;
        case 'brands':
            $rows = fetchBrands($pdo, $q, $status);
            api_json($rows);
            break;
        case 'product_sets':
            $rows = fetchProductSets($pdo, $q, $status);
            api_json($rows);
            break;
        case 'storage_locations':
            $rows = fetchStorageLocations($pdo);
            api_json($rows);
            break;
        case 'lucky_box_sets':
            $rows = fetchProductSets($pdo, $q, $status, true);
            api_json($rows);
            break;
        case 'qr_customer_codes':
            $rows = fetchQrCustomerCodes($pdo, $q);
            api_json($rows);
            break;
        case 'qr_label_history':
            $rows = fetchQrLabelHistory($pdo, $q, $status);
            api_json($rows);
            break;
        default:
            api_json([]);
    }
}

/* ------------------------------------------------------------------ */
/*  Brand CRUD                                                         */
/* ------------------------------------------------------------------ */

function saveBrand(PDO $pdo, array $body): void
{
    $name = trim((string)($body['brand_name'] ?? ''));
    if ($name === '') api_error('Brand name is required.');
    $color = trim((string)($body['brand_key'] ?? '#6c757d'));
    $active = (int)($body['status'] ?? 1);
    $id = (int)($body['id'] ?? 0);

    if ($id > 0) {
        $pdo->prepare('UPDATE brands SET name = ?, color = ?, active = ? WHERE id = ?')->execute([$name, $color, $active, $id]);
    } else {
        $pdo->prepare('INSERT INTO brands (name, color, active, created_at) VALUES (?, ?, ?, NOW())')->execute([$name, $color, $active]);
    }
    api_json(['success' => true, 'message' => $id > 0 ? 'Brand updated.' : 'Brand created.']);
}

function deleteBrand(PDO $pdo, array $body): void
{
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) api_error('Invalid brand ID.');
    $pdo->prepare('DELETE FROM brands WHERE id = ?')->execute([$id]);
    api_json(['success' => true, 'message' => 'Brand deleted.']);
}

/* ------------------------------------------------------------------ */
/*  Product / Cost CRUD                                                */
/* ------------------------------------------------------------------ */

function addProduct(PDO $pdo, array $body): void
{
    $name = trim((string)($body['product_name'] ?? ''));
    if ($name === '') api_error('Product name is required.');
    $brandId = ($body['brand_id'] ?? '') === '' ? null : (int)$body['brand_id'];
    $sellingPrice = (float)($body['selling_price'] ?? 0);
    $month = trim((string)($body['month'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    if ($month > date('Y-m')) api_error('Future month is not allowed.');

    $user = current_user();
    $userId = $user['id'] ?? null;

    $check = $pdo->prepare("SELECT id FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    $check->execute([$name]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $productId = (int)$existing['id'];
        if ($brandId !== null) $pdo->prepare("UPDATE products SET brand_id = ? WHERE id = ?")->execute([$brandId, $productId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO products (name, cost, selling_price, product_type, brand_id) VALUES (?, ?, ?, 'normal', ?)");
        $stmt->execute([$name, $sellingPrice, $sellingPrice, $brandId]);
        $productId = (int)$pdo->lastInsertId();
        if ($productId <= 0) {
            $lookup = $pdo->prepare("SELECT id FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
            $lookup->execute([$name]);
            $productId = (int)($lookup->fetchColumn() ?: 0);
        }
    }

    if ($productId > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO product_costs (product_id, month_year, selling_price, original_cost, updated_by)
            VALUES (?, ?, ?, 0, ?)
            ON DUPLICATE KEY UPDATE selling_price = VALUES(selling_price), updated_by = VALUES(updated_by), cost_updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$productId, $month, $sellingPrice, $userId]);
        if ($month === date('Y-m')) $pdo->prepare("UPDATE products SET cost = ? WHERE id = ?")->execute([$sellingPrice, $productId]);
        api_json(['success' => true, 'message' => $existing ? 'Product already exists. Monthly cost updated.' : 'Product added.']);
    } else {
        api_error('Failed to create product.');
    }
}

function updateCost(PDO $pdo, array $body): void
{
    $productId = (int)($body['product_id'] ?? 0);
    $month = trim((string)($body['month_year'] ?? ''));
    $productName = trim((string)($body['product_name'] ?? ''));
    $sellingPrice = (float)($body['selling_price'] ?? 0);
    $brandId = ($body['brand_id'] ?? '') === '' ? null : (int)$body['brand_id'];
    $currentMonth = date('Y-m');

    if ($productId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month) || $month > $currentMonth) {
        api_error('Invalid data or future month not allowed.');
    }

    if ($productName !== '') {
        $pdo->prepare("UPDATE products SET name = ?, brand_id = ?, selling_price = ? WHERE id = ?")->execute([$productName, $brandId, $sellingPrice, $productId]);
    }

    $user = current_user();
    $userId = $user['id'] ?? null;

    $stmt = $pdo->prepare("
        INSERT INTO product_costs (product_id, month_year, selling_price, original_cost, updated_by)
        VALUES (?, ?, ?, 0, ?)
        ON DUPLICATE KEY UPDATE selling_price = VALUES(selling_price), updated_by = VALUES(updated_by), cost_updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$productId, $month, $sellingPrice, $userId]);

    if ($month === $currentMonth) {
        $pdo->prepare("UPDATE products SET cost = ?, selling_price = ? WHERE id = ?")->execute([$sellingPrice, $sellingPrice, $productId]);
    }

    api_json(['success' => true, 'message' => 'Product cost updated.']);
}

function deleteProduct(PDO $pdo, array $body): void
{
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) api_error('Invalid product ID.');
    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    api_json(['success' => true, 'message' => 'Product deleted.']);
}

/* ------------------------------------------------------------------ */
/*  Product Set / Lucky Box CRUD                                       */
/* ------------------------------------------------------------------ */

function saveProductSet(PDO $pdo, array $body): void
{
    $name = trim((string)($body['set_name'] ?? ''));
    if ($name === '') api_error('Set name is required.');
    $price = (float)($body['price'] ?? 0);
    $description = trim((string)($body['description'] ?? ''));
    $active = (int)($body['status'] ?? 1);
    $storageLocationId = (int)($body['storage_location_id'] ?? 0);
    $commissionRate = (float)($body['commission_rate'] ?? 0);
    $commissionAmount = (float)($body['commission_amount'] ?? 0);
    $id = (int)($body['id'] ?? 0);
    $components = $body['components'] ?? [];

    $totalCost = 0;
    foreach ($components as $comp) {
        $totalCost += (float)($comp['unit_cost'] ?? $comp['price'] ?? 0) * (int)($comp['quantity'] ?? 1);
    }
    $profitMargin = $price > 0 ? round((($price - $totalCost - $commissionAmount) / $price) * 100, 2) : 0;

    if ($id > 0) {
        $pdo->prepare('UPDATE product_sets SET set_name = ?, selling_price = ?, set_description = ?, is_active = ?, storage_location_id = ?, commission_rate = ?, commission_amount = ?, total_cost = ?, profit_margin = ? WHERE id = ?')->execute([$name, $price, $description, $active, $storageLocationId, $commissionRate, $commissionAmount, $totalCost, $profitMargin, $id]);
    } else {
        $sku = 'PSET-' . str_pad((string)($pdo->query("SELECT COUNT(*) FROM product_sets")->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO product_sets (sku, set_name, selling_price, set_description, is_active, available_stock, storage_location_id, commission_rate, commission_amount, total_cost, profit_margin) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)')->execute([$sku, $name, $price, $description, $active, $storageLocationId, $commissionRate, $commissionAmount, $totalCost, $profitMargin]);
        $id = (int)$pdo->lastInsertId();
    }

    if ($id > 0 && is_array($components)) {
        $pdo->prepare('DELETE FROM product_set_items WHERE product_set_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO product_set_items (product_set_id, product_id, quantity, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?)');
        foreach ($components as $comp) {
            $pid = (int)($comp['product_id'] ?? 0);
            $qty = max(1, (int)($comp['quantity'] ?? 1));
            $uc = (float)($comp['unit_cost'] ?? $comp['price'] ?? 0);
            if ($pid > 0) $ins->execute([$id, $pid, $qty, $uc, $uc * $qty]);
        }
    }

    api_json(['success' => true, 'message' => $id > 0 ? 'Set updated.' : 'Set created.']);
}

function deleteProductSet(PDO $pdo, array $body): void
{
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) api_error('Invalid set ID.');
    $pdo->prepare('DELETE FROM product_set_items WHERE product_set_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM product_sets WHERE id = ?')->execute([$id]);
    api_json(['success' => true, 'message' => 'Set deleted.']);
}

/* ------------------------------------------------------------------ */
/*  QR Customer Code CRUD                                              */
/* ------------------------------------------------------------------ */

function saveQrCustomerCode(PDO $pdo, array $body): void
{
    $qrCode = trim((string)($body['qr_code'] ?? ''));
    if ($qrCode === '') api_error('QR code is required.');
    $productSetId = (int)($body['product_set_id'] ?? 0);

    if ($productSetId > 0) {
        $pdo->prepare('INSERT INTO product_set_qr_code_settings (product_set_id, code_prefix, created_at) VALUES (?, ?, NOW())')->execute([$productSetId, $qrCode]);
    }

    api_json(['success' => true, 'message' => 'QR code assigned.']);
}

function deleteQrCustomerCode(PDO $pdo, array $body): void
{
    $productSetId = (int)($body['product_set_id'] ?? 0);
    $qrCode = trim((string)($body['qr_code'] ?? ''));
    if ($productSetId <= 0 || $qrCode === '') api_error('Invalid QR code.');
    $pdo->prepare('DELETE FROM product_set_qr_code_settings WHERE product_set_id = ? AND code_prefix = ?')->execute([$productSetId, $qrCode]);
    api_json(['success' => true, 'message' => 'QR code deleted.']);
}

/* ------------------------------------------------------------------ */
/*  GET Fetchers                                                       */
/* ------------------------------------------------------------------ */

function fetchProducts(PDO $pdo, string $q, string $month, string $productType, int $brandId): array
{
    $conditions = [];
    $whereParams = [];

    if ($productType === 'normal') {
        $conditions[] = "COALESCE(p.product_type, 'General') IN ('normal', 'General')";
    } elseif ($productType === 'set') {
        $conditions[] = "COALESCE(p.product_type, 'General') = 'set'";
    }
    if ($q !== '') {
        $conditions[] = '(p.name LIKE ? OR p.sku LIKE ? OR b.name LIKE ?)';
        $like = '%' . $q . '%';
        $whereParams[] = $like;
        $whereParams[] = $like;
        $whereParams[] = $like;
    }
    if ($brandId > 0) {
        $conditions[] = 'p.brand_id = ?';
        $whereParams[] = $brandId;
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $params = array_merge([$month], $whereParams);

    $stmt = $pdo->prepare("
        SELECT p.id, p.name AS product_name, p.name, p.sku, p.brand_id, p.active,
               COALESCE(p.product_type, 'General') AS product_type,
               COALESCE(pc.selling_price, p.cost) AS selling_price,
               COALESCE(pc.total_cost, 0) AS total_cost,
               COALESCE(pc.original_cost, 0) AS original_cost,
               COALESCE(pc.supplier_cost, 0) AS supplier_cost,
               COALESCE(pc.shipping_cost, 0) AS shipping_cost,
               COALESCE(pc.other_costs, 0) AS other_costs,
               COALESCE(pc.commission_rate, 0) AS commission_rate,
               COALESCE(pc.commission_amount, 0) AS commission_amount,
               COALESCE(pc.notes, '') AS notes,
               pc.cost_updated_at,
               u.name AS updated_by_name,
               COALESCE(b.name, '') AS brand_name,
               COALESCE(b.color, '') AS brand_color,
               CASE WHEN COALESCE(p.product_type, 'General') = 'set' THEN COALESCE(ps.available_stock, 0) ELSE COALESCE(inv.total_quantity, 0) END AS available_stock,
               CASE WHEN COALESCE(p.product_type, 'General') = 'set' THEN '' ELSE COALESCE(inv.storage_locations, '') END AS storage_locations
        FROM products p
        LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
        LEFT JOIN users u ON pc.updated_by = u.id
        LEFT JOIN brands b ON b.id = p.brand_id
        LEFT JOIN product_sets ps ON p.name = ps.set_name AND COALESCE(p.product_type, 'General') = 'set'
        LEFT JOIN (
            SELECT ci.item_name, SUM(ci.quantity_on_hand) AS total_quantity,
                   GROUP_CONCAT(CONCAT(sl.location_code, ':', ci.quantity_on_hand) ORDER BY sl.location_code SEPARATOR '|') AS storage_locations
            FROM current_inventory ci
            LEFT JOIN storage_locations sl ON ci.storage_location_id = sl.id
            GROUP BY ci.item_name
        ) inv ON inv.item_name = p.name
        {$where}
        ORDER BY p.name ASC
    ");
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $profit = (float)$r['selling_price'] - (float)$r['total_cost'] - (float)$r['commission_amount'];
        $margin = (float)$r['selling_price'] > 0 ? round(($profit / (float)$r['selling_price']) * 100, 1) : 0;

$rows[] = [
            'id' => (int)$r['id'],
            'product_name' => (string)$r['product_name'],
            'sku' => (string)$r['sku'],
            'brand_id' => $r['brand_id'] ? (int)$r['brand_id'] : null,
            'brand_name' => (string)$r['brand_name'],
            'brand_color' => (string)$r['brand_color'],
            'selling_price' => (float)$r['selling_price'],
            'cost' => (float)($r['cost'] ?? $r['total_cost'] ?? 0),
            'total_cost' => (float)$r['total_cost'],
            'supplier_cost' => (float)$r['supplier_cost'],
            'commission_rate' => (float)$r['commission_rate'],
            'commission_amount' => (float)$r['commission_amount'],
            'available_stock' => (int)$r['available_stock'],
            'product_type' => (string)$r['product_type'],
        ];
    }
    return $rows;
}

function fetchBrands(PDO $pdo, string $q, string $status): array
{
    $conditions = [];
    $params = [];
    if ($q !== '') {
        $conditions[] = 'b.name LIKE ?';
        $params[] = '%' . $q . '%';
    }
    if ($status === 'active') { $conditions[] = 'b.active = 1'; }
    elseif ($status === 'inactive') { $conditions[] = 'b.active = 0'; }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $stmt = $pdo->prepare("
        SELECT b.id, b.name, b.color, b.active, b.created_at,
               (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id) AS product_count
        FROM brands b {$where} ORDER BY b.name ASC
    ");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id' => (int)$r['id'],
            'brand_name' => (string)$r['name'],
            'brand_key' => (string)($r['color'] ?? ''),
            'status' => (int)$r['active'],
            'product_count' => (int)$r['product_count'],
            'created_at' => (string)$r['created_at'],
        ];
    }
    return $rows;
}

function fetchProductSets(PDO $pdo, string $q, string $status, bool $luckyOnly = false): array
{
    $conditions = [];
    if ($luckyOnly) {
        $conditions[] = 'ps.is_lucky_box = 1';
    } else {
        $conditions[] = 'ps.is_lucky_box = 0';
    }
    if ($q !== '') {
        $conditions[] = '(ps.set_name LIKE ? OR ps.sku LIKE ?)';
    }
    if ($status === 'active') { $conditions[] = 'ps.is_active = 1'; }
    elseif ($status === 'inactive') { $conditions[] = 'ps.is_active = 0'; }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $params = [];
    if ($q !== '') { $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }

    $stmt = $pdo->prepare("
        SELECT ps.id, ps.sku, ps.set_name, ps.set_description, ps.selling_price, ps.available_stock, ps.is_active,
               (SELECT COUNT(*) FROM product_set_items psi WHERE psi.product_set_id = ps.id) AS item_count
        FROM product_sets ps {$where} ORDER BY ps.set_name ASC
    ");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $setId = (int)$r['id'];
        $compStmt = $pdo->prepare("
            SELECT psi.product_id, p.name AS product_name, psi.quantity, psi.unit_cost
            FROM product_set_items psi
            JOIN products p ON p.id = psi.product_id
            WHERE psi.product_set_id = ?
        ");
        $compStmt->execute([$setId]);
        $components = [];
        foreach ($compStmt->fetchAll(PDO::FETCH_ASSOC) as $ci) {
            $components[] = [
                'product_id' => (int)$ci['product_id'],
                'product_name' => (string)$ci['product_name'],
                'quantity' => (int)$ci['quantity'],
                'unit_cost' => (float)$ci['unit_cost'],
            ];
        }
        $rows[] = [
            'id' => (int)$r['id'],
            'set_name' => (string)$r['set_name'],
            'description' => (string)($r['set_description'] ?? ''),
            'sku' => (string)$r['sku'],
            'component_count' => (int)$r['item_count'],
            'components' => $components,
            'price' => (float)$r['selling_price'],
            'stock' => (int)$r['available_stock'],
            'status' => (int)$r['is_active'],
            'storage_location_id' => (int)($r['storage_location_id'] ?? 0),
            'commission_rate' => (float)($r['commission_rate'] ?? 0),
            'commission_amount' => (float)($r['commission_amount'] ?? 0),
        ];
    }
    return $rows;
}

function fetchStorageLocations(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, location_code, location_name FROM storage_locations WHERE is_active = 1 ORDER BY location_code");
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id' => (int)$r['id'],
            'location_code' => (string)$r['location_code'],
            'location_name' => (string)$r['location_name'],
        ];
    }
    return $rows;
}

function fetchQrCustomerCodes(PDO $pdo, string $q): array
{
    $where = '';
    $params = [];
    if ($q !== '') {
        $where = 'WHERE ps.set_name LIKE ? OR ps.sku LIKE ?';
        $params = ['%' . $q . '%', '%' . $q . '%'];
    }

    $stmt = $pdo->prepare("
        SELECT qr.product_set_id, ps.sku, ps.set_name, qr.code_prefix, qr.created_at
        FROM product_set_qr_code_settings qr
        JOIN product_sets ps ON ps.id = qr.product_set_id
        {$where} ORDER BY ps.set_name ASC
    ");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'product_set_id' => (int)$r['product_set_id'],
            'qr_code' => (string)$r['code_prefix'],
            'product_set_name' => (string)$r['set_name'],
            'sku' => (string)$r['sku'],
            'assigned_date' => (string)$r['created_at'],
        ];
    }
    return $rows;
}

function fetchQrLabelHistory(PDO $pdo, string $q, string $status): array
{
    $conditions = [];
    $params = [];
    if ($q !== '') {
        $conditions[] = '(h.label_code LIKE ? OR h.set_name LIKE ? OR h.printed_by_name LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($status !== '') {
        $conditions[] = 'h.print_status = ?';
        $params[] = $status;
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $stmt = $pdo->prepare("
        SELECT h.id, h.label_code, h.set_name, h.set_sku, h.printed_by_name, h.printed_at, h.print_status
        FROM product_set_qr_label_print_history h {$where} ORDER BY h.id DESC
    ");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id' => (int)$r['id'],
            'qr_code' => (string)$r['label_code'],
            'product_name' => (string)$r['set_name'],
            'action' => (string)$r['print_status'],
            'date' => (string)$r['printed_at'],
            'performed_by' => (string)$r['printed_by_name'],
        ];
    }
    return $rows;
}
