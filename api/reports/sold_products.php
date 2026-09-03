<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_role_or_permission(['admin'], 'sr_sales_dashboard.view', 'sr_sold_products.view', 'sr_income_statement.view', 'sold_products.view');

function sold_api_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

function sold_api_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function sold_api_month(?string $value, ?string $fallbackDate = null): string
{
    $value = trim((string)$value);
    if (preg_match('/^\d{4}-\d{2}$/', $value)) {
        return $value;
    }
    $fallbackDate = trim((string)$fallbackDate);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallbackDate)) {
        return substr($fallbackDate, 0, 7);
    }
    return date('Y-m');
}

function sold_product_cost_table_exists(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'product_costs'");
        return $stmt && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function sold_product_cost_column_exists(PDO $pdo, string $column): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM product_costs LIKE " . $pdo->quote($column));
        return $stmt && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function sold_type_label(?string $type): string
{
    $value = strtolower(trim((string)($type ?? 'normal')));
    if ($value === 'set') {
        return 'Set';
    }
    if ($value === '' || $value === 'normal' || $value === 'general') {
        return 'Item';
    }
    return ucfirst($value);
}

function sold_sort_products(array &$rows): void
{
    usort($rows, static function (array $a, array $b): int {
        $qa = (float)($a['total_quantity'] ?? 0);
        $qb = (float)($b['total_quantity'] ?? 0);
        if ($qa === $qb) {
            $sa = (float)($a['total_sales'] ?? 0);
            $sb = (float)($b['total_sales'] ?? 0);
            if ($sa === $sb) {
                return strcmp((string)($a['product_name'] ?? ''), (string)($b['product_name'] ?? ''));
            }
            return $sb <=> $sa;
        }
        return $qb <=> $qa;
    });
}

try {
    $pdo = get_db_connection();
    $limit = sold_api_int('limit', 100, 1, 500);
    $offset = sold_api_int('offset', 0, 0, 1000000);
    $q = trim((string)($_GET['q'] ?? ''));
    $from = sold_api_date($_GET['from'] ?? null);
    $to = sold_api_date($_GET['to'] ?? null);
    $costMonth = sold_api_month($_GET['month'] ?? null, $to ?? $from);
    $productId = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT);
    $brandId = filter_var($_GET['brand_id'] ?? null, FILTER_VALIDATE_INT);
    $sellerId = filter_var($_GET['seller_id'] ?? null, FILTER_VALIDATE_INT);
    $categoryId = filter_var($_GET['category_id'] ?? null, FILTER_VALIDATE_INT);

    $where = ['COALESCE(o.is_cancelled, 0) = 0'];
    $params = [];

    if ($q !== '') {
        $where[] = '(o.order_code LIKE ? OR p.name LIKE ? OR p.sku LIKE ? OR b.name LIKE ? OR u.name LIKE ? OR u.username LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if ($from !== null) {
        $where[] = 'DATE(pj.printed_at) >= ?';
        $params[] = $from;
    }
    if ($to !== null) {
        $where[] = 'DATE(pj.printed_at) <= ?';
        $params[] = $to;
    }
    if ($productId !== false && $productId !== null) {
        $where[] = 'oi.product_id = ?';
        $params[] = (int)$productId;
    }
    if ($brandId !== false && $brandId !== null) {
        $where[] = "(p.brand_id = ? OR EXISTS (
            SELECT 1
            FROM product_sets ps_brand
            JOIN product_set_items psi_brand ON psi_brand.product_set_id = ps_brand.id
            JOIN products cp_brand ON cp_brand.id = psi_brand.product_id
            WHERE ps_brand.set_name = p.name
              AND cp_brand.brand_id = ?
        ))";
        array_push($params, (int)$brandId, (int)$brandId);
    }
    if ($sellerId !== false && $sellerId !== null) {
        $where[] = 'o.seller_id = ?';
        $params[] = (int)$sellerId;
    }
    if ($categoryId !== false && $categoryId !== null) {
        $where[] = 'p.category_id = ?';
        $params[] = (int)$categoryId;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $baseJoins = "
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN brands b ON b.id = p.brand_id
        LEFT JOIN users u ON u.id = o.seller_id
        INNER JOIN (
            SELECT order_id, MAX(printed_at) AS printed_at
            FROM print_jobs
            GROUP BY order_id
        ) pj ON pj.order_id = o.id
    ";

    $dailyStmt = $pdo->prepare("
        SELECT
            oi.product_id,
            MAX(p.name) AS product_name,
            MAX(COALESCE(b.name, '')) AS brand_name,
            MAX(COALESCE(b.color, '')) AS brand_color,
            CONCAT('PID-', oi.product_id) AS product_code,
            MAX(COALESCE(p.product_type, 'normal')) AS product_type,
            SUM(oi.quantity) AS total_quantity,
            SUM(
                CASE
                    WHEN COALESCE(order_totals.order_line_total, 0) > 0
                    THEN (oi.line_total / order_totals.order_line_total) * COALESCE(o.discount, 0)
                    ELSE 0
                END
            ) AS total_discount,
            SUM(
                CASE
                    WHEN COALESCE(order_totals.order_line_total, 0) > 0
                    THEN (oi.line_total / order_totals.order_line_total) * COALESCE(dc.amount, 0)
                    ELSE 0
                END
            ) AS total_delivery_cost,
            SUM(oi.line_total) AS total_sales,
            COUNT(DISTINCT oi.order_id) AS order_count,
            MAX(pj.printed_at) AS last_sold_at
        {$baseJoins}
        LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
        LEFT JOIN (
            SELECT order_id, SUM(line_total) AS order_line_total
            FROM order_items
            GROUP BY order_id
        ) order_totals ON order_totals.order_id = oi.order_id
        {$whereSql}
          AND COALESCE(o.is_returned, 0) = 0
        GROUP BY oi.product_id
    ");
    $dailyStmt->execute($params);
    $dailyProducts = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

    $returnStmt = $pdo->prepare("
        SELECT
            oi.order_id,
            oi.product_id,
            p.name AS product_name,
            COALESCE(b.name, '') AS brand_name,
            COALESCE(b.color, '') AS brand_color,
            CONCAT('PID-', p.id) AS product_code,
            COALESCE(p.product_type, 'normal') AS product_type,
            oi.quantity,
            oi.line_total,
            pj.printed_at
        {$baseJoins}
        {$whereSql}
          AND COALESCE(o.is_returned, 0) = 1
        ORDER BY pj.printed_at DESC, oi.order_id DESC, oi.id ASC
    ");
    $returnStmt->execute($params);
    $returnedItems = $returnStmt->fetchAll(PDO::FETCH_ASSOC);

    $dailyById = [];
    foreach ($dailyProducts as $product) {
        $pid = (int)($product['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $product['return_quantity'] = 0.0;
        $dailyById[$pid] = $product;
    }
    foreach ($returnedItems as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        if (!isset($dailyById[$pid])) {
            $dailyById[$pid] = [
                'product_id' => $pid,
                'product_name' => (string)($row['product_name'] ?? ''),
                'brand_name' => (string)($row['brand_name'] ?? ''),
                'brand_color' => (string)($row['brand_color'] ?? ''),
                'product_code' => (string)($row['product_code'] ?? ''),
                'product_type' => (string)($row['product_type'] ?? 'normal'),
                'total_quantity' => 0.0,
                'total_discount' => 0.0,
                'total_delivery_cost' => 0.0,
                'total_sales' => 0.0,
                'order_count' => 0,
                'last_sold_at' => (string)($row['printed_at'] ?? ''),
                'return_quantity' => 0.0,
            ];
        }
        $dailyById[$pid]['return_quantity'] += (float)($row['quantity'] ?? 0);
        if (!empty($row['printed_at']) && (empty($dailyById[$pid]['last_sold_at']) || (string)$row['printed_at'] > (string)$dailyById[$pid]['last_sold_at'])) {
            $dailyById[$pid]['last_sold_at'] = (string)$row['printed_at'];
        }
    }
    $dailyProducts = array_values($dailyById);
    sold_sort_products($dailyProducts);

    $detailStmt = $pdo->prepare("
        SELECT
            oi.order_id,
            oi.product_id,
            p.name AS product_name,
            CONCAT('PID-', p.id) AS product_code,
            COALESCE(p.product_type, 'normal') AS product_type,
            oi.quantity,
            oi.line_total,
            (
                CASE
                    WHEN COALESCE(order_totals.order_line_total, 0) > 0
                    THEN (oi.line_total / order_totals.order_line_total) * COALESCE(o.discount, 0)
                    ELSE 0
                END
            ) AS line_discount,
            pj.printed_at
        {$baseJoins}
        LEFT JOIN (
            SELECT order_id, SUM(line_total) AS order_line_total
            FROM order_items
            GROUP BY order_id
        ) order_totals ON order_totals.order_id = oi.order_id
        {$whereSql}
          AND COALESCE(o.is_returned, 0) = 0
        ORDER BY pj.printed_at DESC, oi.order_id DESC, oi.id ASC
    ");
    $detailStmt->execute($params);
    $soldItems = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

    $setNames = [];
    foreach (array_merge($soldItems, $returnedItems) as $row) {
        if (($row['product_type'] ?? '') === 'set' && !empty($row['product_name'])) {
            $setNames[(string)$row['product_name']] = true;
        }
    }

    $productSetIdsByName = [];
    $componentsBySetId = [];
    if (!empty($setNames)) {
        $setNameList = array_keys($setNames);
        $placeholders = implode(',', array_fill(0, count($setNameList), '?'));
        $stmtSets = $pdo->prepare("SELECT id, set_name FROM product_sets WHERE set_name IN ($placeholders)");
        $stmtSets->execute($setNameList);
        $setIds = [];
        foreach ($stmtSets->fetchAll(PDO::FETCH_ASSOC) as $setRow) {
            $sid = (int)($setRow['id'] ?? 0);
            $name = (string)($setRow['set_name'] ?? '');
            if ($sid > 0 && $name !== '') {
                $productSetIdsByName[$name] = $sid;
                $setIds[] = $sid;
            }
        }
        if ($setIds) {
            $placeholders = implode(',', array_fill(0, count($setIds), '?'));
            $stmtComponents = $pdo->prepare("
                SELECT
                    psi.product_set_id,
                    psi.quantity AS component_quantity,
                    psi.quantity AS quantity,
                    p.id AS product_id,
                    p.name AS product_name,
                    CONCAT('PID-', p.id) AS product_code,
                    COALESCE(b.name, '') AS brand_name,
                    COALESCE(b.color, '') AS brand_color,
                    COALESCE(p.cost, 0) AS unit_cost
                FROM product_set_items psi
                JOIN products p ON psi.product_id = p.id
                LEFT JOIN brands b ON b.id = p.brand_id
                WHERE psi.product_set_id IN ($placeholders)
            ");
            $stmtComponents->execute($setIds);
            foreach ($stmtComponents->fetchAll(PDO::FETCH_ASSOC) as $component) {
                $sid = (int)($component['product_set_id'] ?? 0);
                if ($sid > 0) {
                    $componentsBySetId[$sid][] = $component;
                }
            }
        }
    }

    foreach ($dailyProducts as &$product) {
        if (($product['product_type'] ?? '') !== 'set') {
            continue;
        }
        $setId = $productSetIdsByName[(string)($product['product_name'] ?? '')] ?? 0;
        $components = $setId > 0 ? array_values($componentsBySetId[$setId] ?? []) : [];
        $product['set_components'] = $components;
        $product['components'] = $components;
    }
    unset($product);
    $detailMap = [];
    $addDetail = static function (array $source, float $qty, float $sales, float $discount, int $orderId, string $printedAt) use (&$detailMap): void {
        $pid = (int)($source['product_id'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            return;
        }
        $key = (string)$pid;
        if (!isset($detailMap[$key])) {
            $detailMap[$key] = [
                'product_id' => $pid,
                'product_name' => (string)($source['product_name'] ?? ''),
                'product_code' => (string)($source['product_code'] ?? ''),
                'total_quantity' => 0.0,
                'return_quantity' => 0.0,
                'total_sales' => 0.0,
                'total_discount' => 0.0,
                'order_ids' => [],
                'last_sold_at' => null,
            ];
        }
        $detailMap[$key]['total_quantity'] += $qty;
        $detailMap[$key]['total_sales'] += $sales;
        $detailMap[$key]['total_discount'] += $discount;
        if ($orderId > 0) {
            $detailMap[$key]['order_ids'][$orderId] = true;
        }
        if ($printedAt !== '' && ($detailMap[$key]['last_sold_at'] === null || $printedAt > $detailMap[$key]['last_sold_at'])) {
            $detailMap[$key]['last_sold_at'] = $printedAt;
        }
    };

    foreach ($soldItems as $row) {
        $qty = (float)($row['quantity'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $lineTotal = (float)($row['line_total'] ?? 0);
        $lineDiscount = (float)($row['line_discount'] ?? 0);
        $orderId = (int)($row['order_id'] ?? 0);
        $printedAt = (string)($row['printed_at'] ?? '');

        if (($row['product_type'] ?? '') === 'set') {
            $setId = $productSetIdsByName[(string)($row['product_name'] ?? '')] ?? 0;
            $components = $setId > 0 ? ($componentsBySetId[$setId] ?? []) : [];
            if ($components) {
                $weightTotal = 0.0;
                foreach ($components as $component) {
                    $componentQty = (float)($component['component_quantity'] ?? 0);
                    $weightTotal += max($componentQty * (float)($component['unit_cost'] ?? 0), $componentQty);
                }
                $weightTotal = $weightTotal > 0 ? $weightTotal : 1.0;
                foreach ($components as $component) {
                    $componentQty = (float)($component['component_quantity'] ?? 0);
                    if ($componentQty <= 0) {
                        continue;
                    }
                    $weight = max($componentQty * (float)($component['unit_cost'] ?? 0), $componentQty);
                    $ratio = $weight / $weightTotal;
                    $addDetail($component, $qty * $componentQty, $lineTotal * $ratio, $lineDiscount * $ratio, $orderId, $printedAt);
                }
                continue;
            }
        }
        $addDetail($row, $qty, $lineTotal, $lineDiscount, $orderId, $printedAt);
    }

    foreach ($returnedItems as $row) {
        $qty = (float)($row['quantity'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $targets = [$row];
        if (($row['product_type'] ?? '') === 'set') {
            $setId = $productSetIdsByName[(string)($row['product_name'] ?? '')] ?? 0;
            $components = $setId > 0 ? ($componentsBySetId[$setId] ?? []) : [];
            if ($components) {
                $targets = $components;
            }
        }
        foreach ($targets as $target) {
            $pid = (int)($target['product_id'] ?? 0);
            $componentQty = isset($target['component_quantity']) ? (float)$target['component_quantity'] : 1.0;
            if ($pid <= 0 || $componentQty <= 0) {
                continue;
            }
            $key = (string)$pid;
            if (!isset($detailMap[$key])) {
                $detailMap[$key] = [
                    'product_id' => $pid,
                    'product_name' => (string)($target['product_name'] ?? ''),
                    'product_code' => (string)($target['product_code'] ?? ''),
                    'total_quantity' => 0.0,
                    'return_quantity' => 0.0,
                    'total_sales' => 0.0,
                    'total_discount' => 0.0,
                    'order_ids' => [],
                    'last_sold_at' => null,
                ];
            }
            $detailMap[$key]['return_quantity'] += $qty * $componentQty;
            $orderId = (int)($row['order_id'] ?? 0);
            if ($orderId > 0) {
                $detailMap[$key]['order_ids'][$orderId] = true;
            }
            $printedAt = (string)($row['printed_at'] ?? '');
            if ($printedAt !== '' && ($detailMap[$key]['last_sold_at'] === null || $printedAt > $detailMap[$key]['last_sold_at'])) {
                $detailMap[$key]['last_sold_at'] = $printedAt;
            }
        }
    }

    $detailProducts = array_values($detailMap);
    $detailIds = array_values(array_unique(array_filter(array_map(static fn(array $item): int => (int)($item['product_id'] ?? 0), $detailProducts))));
    $brandsByProduct = [];
    if ($detailIds) {
        $placeholders = implode(',', array_fill(0, count($detailIds), '?'));
        $brandStmt = $pdo->prepare("
            SELECT p.id AS product_id, COALESCE(b.name, '') AS brand_name, COALESCE(b.color, '') AS brand_color
            FROM products p
            LEFT JOIN brands b ON b.id = p.brand_id
            WHERE p.id IN ($placeholders)
        ");
        $brandStmt->execute($detailIds);
        foreach ($brandStmt->fetchAll(PDO::FETCH_ASSOC) as $brandRow) {
            $brandsByProduct[(int)$brandRow['product_id']] = [
                'name' => (string)($brandRow['brand_name'] ?? ''),
                'color' => (string)($brandRow['brand_color'] ?? ''),
            ];
        }
    }

    foreach ($detailProducts as &$detail) {
        $pid = (int)($detail['product_id'] ?? 0);
        $detailBrand = $brandsByProduct[$pid] ?? ['name' => '', 'color' => ''];
        $detail['brand_name'] = $detailBrand['name'] ?? '';
        $detail['brand_color'] = $detailBrand['color'] ?? '';
        $detail['order_count'] = isset($detail['order_ids']) ? count($detail['order_ids']) : 0;
        unset($detail['order_ids']);
    }
    unset($detail);

    $costByProduct = [];
    if ($detailIds && sold_product_cost_table_exists($pdo)) {
        $hasMarketingCost = sold_product_cost_column_exists($pdo, 'marketing_cost');
        $marketingSelect = $hasMarketingCost ? 'COALESCE(marketing_cost, 0)' : '0';
        $placeholders = implode(',', array_fill(0, count($detailIds), '?'));
        $costStmt = $pdo->prepare("
            SELECT
                product_id,
                COALESCE(original_cost, 0) AS original_cost,
                COALESCE(supplier_cost, 0) AS supplier_cost,
                COALESCE(shipping_cost, 0) AS shipping_cost,
                COALESCE(other_costs, 0) AS other_costs,
                {$marketingSelect} AS marketing_cost,
                COALESCE(commission_amount, 0) AS commission_amount,
                (
                    COALESCE(original_cost, 0)
                    + COALESCE(supplier_cost, 0)
                    + COALESCE(shipping_cost, 0)
                    + COALESCE(other_costs, 0)
                    + {$marketingSelect}
                ) AS unit_cost
            FROM product_costs
            WHERE month_year = ?
              AND product_id IN ($placeholders)
        ");
        $costStmt->execute(array_merge([$costMonth], $detailIds));
        foreach ($costStmt->fetchAll(PDO::FETCH_ASSOC) as $costRow) {
            $pid = (int)($costRow['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $costByProduct[$pid] = [
                'original_cost' => (float)($costRow['original_cost'] ?? 0),
                'supplier_cost' => (float)($costRow['supplier_cost'] ?? 0),
                'shipping_cost' => (float)($costRow['shipping_cost'] ?? 0),
                'other_costs' => (float)($costRow['other_costs'] ?? 0),
                'marketing_cost' => (float)($costRow['marketing_cost'] ?? 0),
                'commission_amount' => (float)($costRow['commission_amount'] ?? 0),
                'unit_cost' => (float)($costRow['unit_cost'] ?? 0) + (float)($costRow['commission_amount'] ?? 0),
            ];
        }
    }

    foreach ($detailProducts as &$detail) {
        $pid = (int)($detail['product_id'] ?? 0);
        $cost = $costByProduct[$pid] ?? [
            'original_cost' => 0.0,
            'supplier_cost' => 0.0,
            'shipping_cost' => 0.0,
            'other_costs' => 0.0,
            'marketing_cost' => 0.0,
            'commission_amount' => 0.0,
            'unit_cost' => 0.0,
        ];
        $qty = (float)($detail['total_quantity'] ?? 0);
        $netSales = max(0.0, (float)($detail['total_sales'] ?? 0) - (float)($detail['total_discount'] ?? 0));
        $productCost = $qty * (float)$cost['unit_cost'];
        $detail['cost_month'] = $costMonth;
        $detail['original_cost'] = $cost['original_cost'];
        $detail['supplier_cost'] = $cost['supplier_cost'];
        $detail['shipping_cost'] = $cost['shipping_cost'];
        $detail['other_costs'] = $cost['other_costs'];
        $detail['marketing_cost'] = $cost['marketing_cost'];
        $detail['commission_amount'] = $cost['commission_amount'];
        $detail['unit_cost'] = $cost['unit_cost'];
        $detail['net_sales'] = $netSales;
        $detail['total_original_cost'] = $qty * (float)$cost['original_cost'];
        $detail['total_supplier_cost'] = $qty * (float)$cost['supplier_cost'];
        $detail['total_shipping_cost'] = $qty * (float)$cost['shipping_cost'];
        $detail['total_other_costs'] = $qty * (float)$cost['other_costs'];
        $detail['total_marketing_cost'] = $qty * (float)$cost['marketing_cost'];
        $detail['total_commission_amount'] = $qty * (float)$cost['commission_amount'];
        $detail['total_cost'] = $productCost;
        $detail['gross_profit'] = $netSales - $productCost;
        $detail['missing_cost'] = $qty > 0 && (float)$cost['unit_cost'] <= 0;
    }
    unset($detail);
    sold_sort_products($detailProducts);

    $orderStmt = $pdo->prepare("
        SELECT
            o.id,
            o.order_code,
            o.customer_name,
            o.total_amount,
            o.discount,
            o.status,
            o.is_cancelled,
            o.is_returned,
            o.payment_method,
            pj.printed_at,
            COALESCE(u.name, u.username, 'N/A') AS seller_name,
            COUNT(oi.id) AS item_count,
            COALESCE(SUM(oi.quantity), 0) AS total_quantity
        FROM orders o
        INNER JOIN (
            SELECT order_id, MAX(printed_at) AS printed_at
            FROM print_jobs
            GROUP BY order_id
        ) pj ON pj.order_id = o.id
        LEFT JOIN users u ON o.seller_id = u.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN brands b ON b.id = p.brand_id
        WHERE DATE(pj.printed_at) " . ($from !== null ? ">= ?" : ">= DATE('1970-01-01')") . "
          " . ($to !== null ? "AND DATE(pj.printed_at) <= ?" : "") . "
          " . ($q !== '' ? "AND (o.order_code LIKE ? OR o.customer_name LIKE ? OR p.name LIKE ?)" : "") . "
          " . (($productId !== false && $productId !== null) ? "AND oi.product_id = ?" : "") . "
          " . (($brandId !== false && $brandId !== null) ? "AND p.brand_id = ?" : "") . "
          " . (($sellerId !== false && $sellerId !== null) ? "AND o.seller_id = ?" : "") . "
          " . (($categoryId !== false && $categoryId !== null) ? "AND p.category_id = ?" : "") . "
        GROUP BY o.id, o.order_code, o.customer_name, o.total_amount, o.discount, o.status, o.is_cancelled, o.is_returned, o.payment_method, pj.printed_at, u.name, u.username
        ORDER BY pj.printed_at DESC
    ");
    $orderParams = [];
    if ($from !== null) {
        $orderParams[] = $from;
    }
    if ($to !== null) {
        $orderParams[] = $to;
    }
    if ($q !== '') {
        $like = '%' . $q . '%';
        array_push($orderParams, $like, $like, $like);
    }
    if ($productId !== false && $productId !== null) {
        $orderParams[] = (int)$productId;
    }
    if ($brandId !== false && $brandId !== null) {
        $orderParams[] = (int)$brandId;
    }
    if ($sellerId !== false && $sellerId !== null) {
        $orderParams[] = (int)$sellerId;
    }
    if ($categoryId !== false && $categoryId !== null) {
        $orderParams[] = (int)$categoryId;
    }
    $orderStmt->execute($orderParams);
    $detailedOrders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

    $detailedOrderIds = array_values(array_filter(array_map(static fn(array $order): int => (int)($order['id'] ?? 0), $detailedOrders)));
    $detailedOrderProducts = [];
    if ($detailedOrderIds) {
        $placeholders = implode(',', array_fill(0, count($detailedOrderIds), '?'));
        $productStmt = $pdo->prepare("
            SELECT
                oi.order_id,
                p.name AS product_name,
                oi.quantity,
                oi.line_total
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id IN ($placeholders)
              " . (($brandId !== false && $brandId !== null) ? "AND p.brand_id = ?" : "") . "
            ORDER BY oi.order_id, oi.id
        ");
        $productParams = $detailedOrderIds;
        if ($brandId !== false && $brandId !== null) {
            $productParams[] = (int)$brandId;
        }
        $productStmt->execute($productParams);
        foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $orderId = (int)($item['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $detailedOrderProducts[$orderId][] = [
                'name' => (string)($item['product_name'] ?? ''),
                'quantity' => (float)($item['quantity'] ?? 0),
                'line_total' => (float)($item['line_total'] ?? 0),
            ];
        }
    }

    $detailedSummary = [
        'detailed_total_orders' => 0,
        'detailed_total_items' => 0,
        'detailed_total_quantity' => 0.0,
        'detailed_total_discount' => 0.0,
        'detailed_total_amount' => 0.0,
        'cancelled_orders' => 0,
        'returned_orders' => 0,
    ];
    foreach ($detailedOrders as &$order) {
        $orderId = (int)($order['id'] ?? 0);
        $order['products'] = $detailedOrderProducts[$orderId] ?? [];
        $productLines = [];
        foreach ($order['products'] as $product) {
            $productLines[] = $product['name'] . ' (' . number_format((float)$product['quantity'], 0) . 'x, $' . number_format((float)$product['line_total'], 0) . ')';
        }
        $order['products_summary'] = implode("\n", $productLines);

        $isCancelled = (int)($order['is_cancelled'] ?? 0) === 1;
        $isReturned = (int)($order['is_returned'] ?? 0) === 1;
        if ($isCancelled) {
            $detailedSummary['cancelled_orders']++;
            $order['status_label'] = 'Cancelled';
        } elseif ($isReturned) {
            $detailedSummary['returned_orders']++;
            $order['status_label'] = 'Returned';
        } elseif (($order['status'] ?? '') === 'paid') {
            $order['status_label'] = 'Paid';
        } else {
            $order['status_label'] = 'Unpaid';
        }

        if (!$isCancelled && !$isReturned) {
            $detailedSummary['detailed_total_orders']++;
            $detailedSummary['detailed_total_items'] += (int)($order['item_count'] ?? 0);
            $detailedSummary['detailed_total_quantity'] += (float)($order['total_quantity'] ?? 0);
            $detailedSummary['detailed_total_discount'] += (float)($order['discount'] ?? 0);
            $detailedSummary['detailed_total_amount'] += (float)($order['total_amount'] ?? 0);
        }
    }
    unset($order);

    $summary = [
        'products_sold' => count($dailyProducts),
        'revenue' => 0.0,
        'profit' => 0.0,
        'total_original_cost' => 0.0,
        'total_supplier_cost' => 0.0,
        'total_shipping_cost' => 0.0,
        'total_other_costs' => 0.0,
        'total_marketing_cost' => 0.0,
        'total_commission_amount' => 0.0,
        'total_product_cost' => 0.0,
        'total_cost' => 0.0,
        'missing_cost' => 0,
        'total_quantity' => 0.0,
        'total_return_quantity' => 0.0,
        'total_discount' => 0.0,
        'total_delivery_cost' => 0.0,
        'total_orders' => 0,
    ];
    foreach ($dailyProducts as &$product) {
        $product['type_label'] = sold_type_label($product['product_type'] ?? 'normal');
        $summary['revenue'] += (float)($product['total_sales'] ?? 0);
        $summary['total_quantity'] += (float)($product['total_quantity'] ?? 0);
        $summary['total_return_quantity'] += (float)($product['return_quantity'] ?? 0);
        $summary['total_discount'] += (float)($product['total_discount'] ?? 0);
        $summary['total_delivery_cost'] += (float)($product['total_delivery_cost'] ?? 0);
        $summary['total_orders'] += (int)($product['order_count'] ?? 0);
    }
    unset($product);

    foreach ($detailProducts as $detail) {
        $summary['total_original_cost'] += (float)($detail['total_original_cost'] ?? 0);
        $summary['total_supplier_cost'] += (float)($detail['total_supplier_cost'] ?? 0);
        $summary['total_shipping_cost'] += (float)($detail['total_shipping_cost'] ?? 0);
        $summary['total_other_costs'] += (float)($detail['total_other_costs'] ?? 0);
        $summary['total_marketing_cost'] += (float)($detail['total_marketing_cost'] ?? 0);
        $summary['total_commission_amount'] += (float)($detail['total_commission_amount'] ?? 0);
        $summary['total_product_cost'] += (float)($detail['total_cost'] ?? 0);
        $summary['profit'] += (float)($detail['gross_profit'] ?? 0);
        if (!empty($detail['missing_cost'])) {
            $summary['missing_cost']++;
        }
    }
    $summary['total_cost'] = $summary['total_product_cost'];
    $summary['total_product_delivery_cost'] = $summary['total_product_cost'] + $summary['total_delivery_cost'];

    $totalRows = count($dailyProducts);
    $pagedDaily = array_slice($dailyProducts, $offset, $limit);

    api_json([
        'success' => true,
        'summary' => $summary,
        'rows' => $pagedDaily,
        'product_detail_list' => $detailProducts,
        'detailed_orders' => $detailedOrders,
        'detailed_summary' => $detailedSummary,
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'total_rows' => $totalRows,
            'has_more' => ($offset + $limit) < $totalRows,
        ],
    ]);
} catch (Throwable $e) {
    error_log('sold_products API error: ' . $e->getMessage());
    api_error('Unable to load sold products.', 500);
}


