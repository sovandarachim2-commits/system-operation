<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_common.php';

$pdo = get_db_connection();
$source = strtolower(inventory_api_str($_GET['source'] ?? 'online'));
if (!in_array($source, ['online', 'offline'], true)) {
    $source = 'online';
}
if ($source === 'offline') {
    require_role_or_permission(
        ['admin'],
        'sr_sales_dashboard.view',
        'sr_inventory_sold_offline.view',
        'sr_inventory_movements.view',
        'sr_offline_orders.view',
        'offline_sales.view',
        'inventory.view',
        'stock_reports.view'
    );
} else {
    require_role_or_permission(
        ['admin'],
        'sr_sales_dashboard.view',
        'sr_inventory_sold_online.view',
        'sr_inventory_movements.view',
        'sr_sold_products.view',
        'sr_orders.view',
        'inventory.view',
        'stock_reports.view'
    );
}

$from = inventory_api_date($_GET['from'] ?? null, date('Y-m-01'));
$to = inventory_api_date($_GET['to'] ?? null, date('Y-m-d'));
$q = inventory_api_str($_GET['q'] ?? '');
$brandId = inventory_api_int($_GET['brand_id'] ?? 0);
$teamId = inventory_api_int($_GET['team_id'] ?? 0);
$productId = inventory_api_int($_GET['product_id'] ?? 0);
$limit = max(1, min(1000, inventory_api_int($_GET['limit'] ?? 200)));
$flow = strtolower(inventory_api_str($_GET['flow'] ?? 'sale'));
$flowParts = array_values(array_unique(array_filter(array_map('trim', explode(',', $flow)))));
if (in_array('sale', $flowParts, true) && in_array('buy_back', $flowParts, true)) {
    $flow = 'both';
} elseif (in_array('buy_back', $flowParts, true)) {
    $flow = 'buy_back';
} elseif (in_array('sale', $flowParts, true)) {
    $flow = 'sale';
} else {
    $flow = 'sale';
}
$paymentStatus = strtolower(inventory_api_str($_GET['payment_status'] ?? ''));
if (!in_array($paymentStatus, ['paid', 'partial', 'unpaid'], true)) {
    $paymentStatus = '';
}

function sold_stock_brand_options(PDO $pdo): array
{
    try {
        return $pdo->query("
            SELECT id AS value, name AS label, color AS brand_color
            FROM brands
            WHERE COALESCE(active, 1) = 1
            ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function sold_stock_team_options(PDO $pdo): array
{
    try {
        return $pdo->query("
            SELECT id AS value, name AS label
            FROM offline_teams
            WHERE COALESCE(is_active, 1) = 1
            ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function sold_stock_product_options(PDO $pdo): array
{
    try {
        return $pdo->query("
            SELECT id AS value, name AS label, sku
            FROM products
            ORDER BY name
            LIMIT 800
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function sold_stock_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];

    if (!isset($cache[$table])) {
        $cache[$table] = [];
        foreach (api_table_columns($pdo, $table) as $row) {
            $cache[$table][strtolower((string)($row['name'] ?? ''))] = true;
        }
    }

    return isset($cache[$table][strtolower($column)]);
}

function sold_stock_online_payment_filter_sql(string $status): string
{
    $paid = "(COALESCE(o.is_paid, 0) = 1 OR LOWER(COALESCE(o.status, '')) = 'paid')";
    if ($status === 'paid') {
        return $paid;
    }
    if ($status === 'partial') {
        return "LOWER(COALESCE(o.status, '')) = 'partial'";
    }
    if ($status === 'unpaid') {
        return "NOT {$paid} AND LOWER(COALESCE(o.status, '')) <> 'partial'";
    }
    return '';
}

function sold_stock_offline_payment_join_sql(): string
{
    return "
        LEFT JOIN (
            SELECT order_id, SUM(COALESCE(amount, 0)) AS paid_amount
            FROM offline_sale_payments
            GROUP BY order_id
        ) pay ON pay.order_id = o.id";
}

function sold_stock_offline_paid_expr(): string
{
    return "CASE
        WHEN COALESCE(pay.paid_amount, 0) > 0 THEN LEAST(COALESCE(pay.paid_amount, 0), COALESCE(o.total_amount, 0))
        WHEN COALESCE(o.received_amount, 0) > 0 THEN LEAST(COALESCE(o.received_amount, 0), COALESCE(o.total_amount, 0))
        WHEN LOWER(COALESCE(o.status, '')) = 'paid' THEN COALESCE(o.total_amount, 0)
        ELSE 0
    END";
}

function sold_stock_offline_payment_filter_sql(string $status): string
{
    $paid = sold_stock_offline_paid_expr();
    $active = "LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'canceled')";
    if ($status === 'paid') {
        return "{$active} AND (COALESCE(o.total_amount, 0) <= 0 OR {$paid} >= COALESCE(o.total_amount, 0) - 0.009)";
    }
    if ($status === 'partial') {
        return "{$active} AND COALESCE(o.total_amount, 0) > 0 AND {$paid} > 0 AND {$paid} < COALESCE(o.total_amount, 0) - 0.009";
    }
    if ($status === 'unpaid') {
        return "{$active} AND COALESCE(o.total_amount, 0) > 0 AND {$paid} <= 0";
    }
    return '';
}

/**
 * Same return-order source as admin/product_return_report.php:
 * scanner return_items + order-management returns not already in return_items.
 */
function sold_stock_online_return_orders_sql(PDO $pdo): string
{
    try {
        $pdo->query('SELECT 1 FROM return_items LIMIT 1');
    } catch (Throwable $e) {
        // Keep sold-stock reporting available when the optional return_items table is unavailable.
        return "(
            SELECT o.id AS order_id, o.updated_at AS return_at, o.order_code
            FROM orders o
            WHERE COALESCE(o.is_returned, 0) = 1
        )";
    }

    return "(
        SELECT o.id AS order_id, MIN(ri.date_time) AS return_at, o.order_code
        FROM return_items ri
        JOIN orders o ON o.order_code = ri.inv
        GROUP BY o.id, o.order_code
        UNION ALL
        SELECT o.id AS order_id, o.updated_at AS return_at, o.order_code
        FROM orders o
        WHERE COALESCE(o.is_returned, 0) = 1
          AND NOT EXISTS (
              SELECT 1 FROM return_items ri2 WHERE ri2.inv = o.order_code
          )
    )";
}

function sold_stock_offline_daily_rows(PDO $pdo, string $from, string $to, string $q, int $brandId, int $teamId, int $productId, string $paymentStatus, int $limit): array
{
    $saleWhere = ['o.sale_date >= ?', 'o.sale_date <= ?'];
    $buyWhere = ['o.sale_date >= ?', 'o.sale_date <= ?'];
    $saleParams = [$from, $to];
    $buyParams = [$from, $to];
    $paymentFilter = sold_stock_offline_payment_filter_sql($paymentStatus);

    if ($paymentFilter !== '') {
        $saleWhere[] = $paymentFilter;
        $buyWhere[] = $paymentFilter;
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        $saleWhere[] = '(i.product_name LIKE ? OR p.name LIKE ? OR b.name LIKE ? OR p.sku LIKE ? OR o.order_code LIKE ?)';
        array_push($saleParams, $like, $like, $like, $like, $like);
        $buyWhere[] = '(i.product_name LIKE ? OR p.name LIKE ? OR b.name LIKE ? OR p.sku LIKE ? OR o.order_code LIKE ? OR i.item_condition LIKE ?)';
        array_push($buyParams, $like, $like, $like, $like, $like, $like);
    }
    if ($brandId > 0) {
        $saleWhere[] = 'p.brand_id = ?';
        $saleParams[] = $brandId;
        $buyWhere[] = 'p.brand_id = ?';
        $buyParams[] = $brandId;
    }
    if ($teamId > 0) {
        $saleWhere[] = 'o.team_id = ?';
        $saleParams[] = $teamId;
        $buyWhere[] = 'o.team_id = ?';
        $buyParams[] = $teamId;
    }
    if ($productId > 0) {
        $saleWhere[] = 'i.product_id = ?';
        $saleParams[] = $productId;
        $buyWhere[] = 'i.product_id = ?';
        $buyParams[] = $productId;
    }

    $sql = "
        SELECT
            sale_date,
            product_id,
            product_name,
            brand_name,
            brand_color,
            sku,
            team_id,
            team_name,
            SUM(sold_qty) AS sold_qty,
            SUM(buy_back_qty) AS buy_back_qty,
            MAX(last_update) AS last_update
        FROM (
            SELECT
                i.product_id,
                i.product_name,
                b.name AS brand_name,
                b.color AS brand_color,
                p.sku,
                o.team_id,
                t.name AS team_name,
                CASE WHEN LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'canceled') THEN COALESCE(i.quantity, 0) ELSE 0 END AS sold_qty,
                0 AS buy_back_qty,
                o.sale_date AS sale_date,
                o.sale_date AS last_update
            FROM offline_sale_order_items i
            JOIN offline_sale_orders o ON o.id = i.order_id
            " . sold_stock_offline_payment_join_sql() . "
            LEFT JOIN products p ON p.id = i.product_id
            LEFT JOIN brands b ON b.id = p.brand_id
            LEFT JOIN offline_teams t ON t.id = o.team_id
            WHERE " . implode(' AND ', $saleWhere) . "

            UNION ALL

            SELECT
                i.product_id,
                i.product_name,
                b.name AS brand_name,
                b.color AS brand_color,
                p.sku,
                o.team_id,
                t.name AS team_name,
                0 AS sold_qty,
                CASE WHEN LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'canceled') THEN COALESCE(i.quantity, 0) ELSE 0 END AS buy_back_qty,
                o.sale_date AS sale_date,
                o.sale_date AS last_update
            FROM offline_sale_purchase_items i
            JOIN offline_sale_orders o ON o.id = i.order_id
            " . sold_stock_offline_payment_join_sql() . "
            LEFT JOIN products p ON p.id = i.product_id
            LEFT JOIN brands b ON b.id = p.brand_id
            LEFT JOIN offline_teams t ON t.id = o.team_id
            WHERE " . implode(' AND ', $buyWhere) . "
        ) daily
        GROUP BY sale_date, product_id, product_name, brand_name, brand_color, sku, team_id, team_name
        HAVING sold_qty <> 0 OR buy_back_qty <> 0
        ORDER BY sale_date ASC, sold_qty DESC, buy_back_qty DESC, product_name ASC, team_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($saleParams, $buyParams));
    return array_map(static function (array $row): array {
        return [
            'product_id' => (int)($row['product_id'] ?? 0),
            'date' => (string)($row['sale_date'] ?? ''),
            'product_name' => (string)($row['product_name'] ?? ''),
            'brand_name' => (string)($row['brand_name'] ?? ''),
            'brand_color' => (string)($row['brand_color'] ?? ''),
            'sku' => (string)($row['sku'] ?? ''),
            'team_id' => (int)($row['team_id'] ?? 0),
            'team_name' => (string)($row['team_name'] ?? ''),
            'sold_qty' => (float)($row['sold_qty'] ?? 0),
            'buy_back_qty' => (float)($row['buy_back_qty'] ?? 0),
            'last_update' => (string)($row['last_update'] ?? ''),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function sold_stock_online_set_joins(): string
{
    // Expand product sets into component SKUs (qty × set-item qty).
    return "
        LEFT JOIN product_sets ps ON ps.set_name = p.name
        LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
        LEFT JOIN products cp ON cp.id = psi.product_id
        LEFT JOIN brands cb ON cb.id = cp.brand_id";
}

function sold_stock_online_line_qty_expr(string $alias = 'oi'): string
{
    return "COALESCE({$alias}.quantity, 0) * COALESCE(psi.quantity, 1)";
}

function sold_stock_online_daily_rows(PDO $pdo, string $from, string $to, string $q, int $brandId, int $productId, string $paymentStatus, int $limit): array
{
    $lineQty = sold_stock_online_line_qty_expr();
    $setJoins = sold_stock_online_set_joins();
    $returnOrders = sold_stock_online_return_orders_sql($pdo);

    $saleWhere = [
        'COALESCE(o.is_cancelled, 0) = 0',
        'COALESCE(o.is_returned, 0) = 0',
        'DATE(pj.printed_at) >= ?',
        'DATE(pj.printed_at) <= ?',
    ];
    $saleParams = [$from, $to];

    $returnWhere = [
        'COALESCE(o.is_cancelled, 0) = 0',
        'DATE(ro.return_at) >= ?',
        'DATE(ro.return_at) <= ?',
    ];
    $returnParams = [$from, $to];
    $paymentFilter = sold_stock_online_payment_filter_sql($paymentStatus);

    if ($paymentFilter !== '') {
        $saleWhere[] = $paymentFilter;
        $returnWhere[] = $paymentFilter;
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        $saleWhere[] = '(p.name LIKE ? OR cp.name LIKE ? OR b.name LIKE ? OR cb.name LIKE ? OR p.sku LIKE ? OR cp.sku LIKE ? OR o.order_code LIKE ?)';
        $returnWhere[] = '(p.name LIKE ? OR cp.name LIKE ? OR b.name LIKE ? OR cb.name LIKE ? OR p.sku LIKE ? OR cp.sku LIKE ? OR o.order_code LIKE ?)';
        array_push($saleParams, $like, $like, $like, $like, $like, $like, $like);
        array_push($returnParams, $like, $like, $like, $like, $like, $like, $like);
    }
    if ($brandId > 0) {
        $saleWhere[] = '(p.brand_id = ? OR cp.brand_id = ?)';
        $returnWhere[] = '(p.brand_id = ? OR cp.brand_id = ?)';
        array_push($saleParams, $brandId, $brandId);
        array_push($returnParams, $brandId, $brandId);
    }
    if ($productId > 0) {
        $saleWhere[] = '(oi.product_id = ? OR psi.product_id = ?)';
        $returnWhere[] = '(oi.product_id = ? OR psi.product_id = ?)';
        array_push($saleParams, $productId, $productId);
        array_push($returnParams, $productId, $productId);
    }

    $sql = "
        SELECT
            sale_date,
            product_id,
            MAX(product_name) AS product_name,
            MAX(brand_name) AS brand_name,
            MAX(brand_color) AS brand_color,
            MAX(sku) AS sku,
            SUM(sold_qty) AS sold_qty,
            SUM(return_qty) AS return_qty,
            MAX(last_update) AS last_update
        FROM (
            SELECT
                DATE(pj.printed_at) AS sale_date,
                COALESCE(cp.id, p.id) AS product_id,
                COALESCE(cp.name, p.name) AS product_name,
                COALESCE(cb.name, b.name) AS brand_name,
                COALESCE(cb.color, b.color) AS brand_color,
                COALESCE(cp.sku, p.sku) AS sku,
                {$lineQty} AS sold_qty,
                0 AS return_qty,
                pj.printed_at AS last_update
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN products p ON p.id = oi.product_id
            LEFT JOIN brands b ON b.id = p.brand_id
            {$setJoins}
            INNER JOIN (
                SELECT order_id, MAX(printed_at) AS printed_at
                FROM print_jobs
                GROUP BY order_id
            ) pj ON pj.order_id = o.id
            WHERE " . implode(' AND ', $saleWhere) . "

            UNION ALL

            SELECT
                DATE(ro.return_at) AS sale_date,
                COALESCE(cp.id, p.id) AS product_id,
                COALESCE(cp.name, p.name) AS product_name,
                COALESCE(cb.name, b.name) AS brand_name,
                COALESCE(cb.color, b.color) AS brand_color,
                COALESCE(cp.sku, p.sku) AS sku,
                0 AS sold_qty,
                {$lineQty} AS return_qty,
                ro.return_at AS last_update
            FROM {$returnOrders} ro
            JOIN orders o ON o.id = ro.order_id
            JOIN order_items oi ON oi.order_id = ro.order_id
            JOIN products p ON p.id = oi.product_id
            LEFT JOIN brands b ON b.id = p.brand_id
            {$setJoins}
            WHERE " . implode(' AND ', $returnWhere) . "
        ) daily
        GROUP BY sale_date, product_id
        HAVING sold_qty <> 0 OR return_qty <> 0
        ORDER BY sale_date ASC, sold_qty DESC, product_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($saleParams, $returnParams));
    return array_map(static function (array $row): array {
        return [
            'date' => (string)($row['sale_date'] ?? ''),
            'product_id' => (int)($row['product_id'] ?? 0),
            'product_name' => (string)($row['product_name'] ?? ''),
            'brand_name' => (string)($row['brand_name'] ?? ''),
            'brand_color' => (string)($row['brand_color'] ?? ''),
            'sku' => (string)($row['sku'] ?? ''),
            'sold_qty' => (float)($row['sold_qty'] ?? 0),
            'return_qty' => (float)($row['return_qty'] ?? 0),
            'sold_out' => (float)($row['sold_qty'] ?? 0) - (float)($row['return_qty'] ?? 0),
            'last_update' => (string)($row['last_update'] ?? ''),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

try {
    $where = [];
    $params = [];

    if ($source === 'online') {
        $lineQty = sold_stock_online_line_qty_expr();
        $setJoins = sold_stock_online_set_joins();
        $returnOrders = sold_stock_online_return_orders_sql($pdo);

        $saleWhere = [
            'COALESCE(o.is_cancelled, 0) = 0',
            'COALESCE(o.is_returned, 0) = 0',
            'DATE(pj.printed_at) >= ?',
            'DATE(pj.printed_at) <= ?',
        ];
        $saleParams = [$from, $to];

        $returnWhere = [
            'COALESCE(o.is_cancelled, 0) = 0',
            'DATE(ro.return_at) >= ?',
            'DATE(ro.return_at) <= ?',
        ];
        $returnParams = [$from, $to];
        $paymentFilter = sold_stock_online_payment_filter_sql($paymentStatus);

        if ($paymentFilter !== '') {
            $saleWhere[] = $paymentFilter;
            $returnWhere[] = $paymentFilter;
        }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $saleWhere[] = '(p.name LIKE ? OR cp.name LIKE ? OR b.name LIKE ? OR cb.name LIKE ? OR p.sku LIKE ? OR cp.sku LIKE ? OR o.order_code LIKE ?)';
            $returnWhere[] = '(p.name LIKE ? OR cp.name LIKE ? OR b.name LIKE ? OR cb.name LIKE ? OR p.sku LIKE ? OR cp.sku LIKE ? OR o.order_code LIKE ?)';
            array_push($saleParams, $like, $like, $like, $like, $like, $like, $like);
            array_push($returnParams, $like, $like, $like, $like, $like, $like, $like);
        }
        if ($brandId > 0) {
            $saleWhere[] = '(p.brand_id = ? OR cp.brand_id = ?)';
            $returnWhere[] = '(p.brand_id = ? OR cp.brand_id = ?)';
            array_push($saleParams, $brandId, $brandId);
            array_push($returnParams, $brandId, $brandId);
        }
        if ($productId > 0) {
            $saleWhere[] = '(oi.product_id = ? OR psi.product_id = ?)';
            $returnWhere[] = '(oi.product_id = ? OR psi.product_id = ?)';
            array_push($saleParams, $productId, $productId);
            array_push($returnParams, $productId, $productId);
        }

        $params = array_merge($saleParams, $returnParams);
        $sql = "
            SELECT
                product_id,
                MAX(product_name) AS product_name,
                MAX(brand_name) AS brand_name,
                MAX(brand_color) AS brand_color,
                MAX(sku) AS sku,
                SUM(sold_qty) + SUM(return_qty) AS total_sold,
                SUM(return_qty) AS qty_return,
                SUM(sold_qty) AS sold_out,
                COUNT(DISTINCT NULLIF(order_id, 0)) AS order_count,
                MAX(last_sold_at) AS last_sold_at
            FROM (
                SELECT
                    COALESCE(cp.id, p.id) AS product_id,
                    COALESCE(cp.name, p.name) AS product_name,
                    COALESCE(cb.name, b.name) AS brand_name,
                    COALESCE(cb.color, b.color) AS brand_color,
                    COALESCE(cp.sku, p.sku) AS sku,
                    {$lineQty} AS sold_qty,
                    0 AS return_qty,
                    oi.order_id AS order_id,
                    pj.printed_at AS last_sold_at
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                JOIN products p ON p.id = oi.product_id
                LEFT JOIN brands b ON b.id = p.brand_id
                {$setJoins}
                INNER JOIN (
                    SELECT order_id, MAX(printed_at) AS printed_at
                    FROM print_jobs
                    GROUP BY order_id
                ) pj ON pj.order_id = o.id
                WHERE " . implode(' AND ', $saleWhere) . "

                UNION ALL

                SELECT
                    COALESCE(cp.id, p.id) AS product_id,
                    COALESCE(cp.name, p.name) AS product_name,
                    COALESCE(cb.name, b.name) AS brand_name,
                    COALESCE(cb.color, b.color) AS brand_color,
                    COALESCE(cp.sku, p.sku) AS sku,
                    0 AS sold_qty,
                    {$lineQty} AS return_qty,
                    oi.order_id AS order_id,
                    ro.return_at AS last_sold_at
                FROM {$returnOrders} ro
                JOIN orders o ON o.id = ro.order_id
                JOIN order_items oi ON oi.order_id = ro.order_id
                JOIN products p ON p.id = oi.product_id
                LEFT JOIN brands b ON b.id = p.brand_id
                {$setJoins}
                WHERE " . implode(' AND ', $returnWhere) . "
            ) stock
            GROUP BY product_id
            ORDER BY sold_out DESC, total_sold DESC, product_name ASC
            LIMIT " . (int)$limit;
    } else {
        $where[] = 'o.sale_date >= ?';
        $where[] = 'o.sale_date <= ?';
        $params[] = $from;
        $params[] = $to;
        $paymentFilter = sold_stock_offline_payment_filter_sql($paymentStatus);

        if ($paymentFilter !== '') {
            $where[] = $paymentFilter;
        }

        if ($flow === 'buy_back') {
            if ($q !== '') {
                $where[] = '(i.product_name LIKE ? OR p.name LIKE ? OR b.name LIKE ? OR p.sku LIKE ? OR o.order_code LIKE ? OR i.item_condition LIKE ?)';
                $like = '%' . $q . '%';
                array_push($params, $like, $like, $like, $like, $like, $like);
            }
            if ($brandId > 0) {
                $where[] = 'p.brand_id = ?';
                $params[] = $brandId;
            }
            if ($teamId > 0) {
                $where[] = 'o.team_id = ?';
                $params[] = $teamId;
            }
            if ($productId > 0) {
                $where[] = 'i.product_id = ?';
                $params[] = $productId;
            }

            $sql = "
                SELECT
                    i.product_id,
                    i.product_name AS product_name,
                    MAX(b.name) AS brand_name,
                    MAX(b.color) AS brand_color,
                    MAX(p.sku) AS sku,
                    SUM(COALESCE(i.quantity, 0)) AS total_sold,
                    SUM(CASE WHEN LOWER(COALESCE(o.status, '')) IN ('cancelled', 'canceled') THEN COALESCE(i.quantity, 0) ELSE 0 END) AS qty_return,
                    SUM(CASE WHEN LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'canceled') THEN COALESCE(i.quantity, 0) ELSE 0 END) AS sold_out,
                    COUNT(DISTINCT i.order_id) AS order_count,
                    MAX(o.sale_date) AS last_sold_at
                FROM offline_sale_purchase_items i
                JOIN offline_sale_orders o ON o.id = i.order_id
                " . sold_stock_offline_payment_join_sql() . "
                LEFT JOIN products p ON p.id = i.product_id
                LEFT JOIN brands b ON b.id = p.brand_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY i.product_id, i.product_name
                ORDER BY sold_out DESC, total_sold DESC, product_name ASC
                LIMIT " . (int)$limit;
        } else {
            if ($q !== '') {
                $where[] = '(i.product_name LIKE ? OR p.name LIKE ? OR b.name LIKE ? OR p.sku LIKE ? OR o.order_code LIKE ?)';
                $like = '%' . $q . '%';
                array_push($params, $like, $like, $like, $like, $like);
            }
            if ($brandId > 0) {
                $where[] = 'p.brand_id = ?';
                $params[] = $brandId;
            }
            if ($teamId > 0) {
                $where[] = 'o.team_id = ?';
                $params[] = $teamId;
            }
            if ($productId > 0) {
                $where[] = 'i.product_id = ?';
                $params[] = $productId;
            }

            $sql = "
                SELECT
                    i.product_id,
                    i.product_name AS product_name,
                    MAX(b.name) AS brand_name,
                    MAX(b.color) AS brand_color,
                    MAX(p.sku) AS sku,
                    SUM(COALESCE(i.quantity, 0)) AS total_sold,
                    SUM(CASE WHEN LOWER(COALESCE(o.status, '')) IN ('cancelled', 'canceled') THEN COALESCE(i.quantity, 0) ELSE 0 END) AS qty_return,
                    SUM(CASE WHEN LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'canceled') THEN COALESCE(i.quantity, 0) ELSE 0 END) AS sold_out,
                    COUNT(DISTINCT i.order_id) AS order_count,
                    MAX(o.sale_date) AS last_sold_at
                FROM offline_sale_order_items i
                JOIN offline_sale_orders o ON o.id = i.order_id
                " . sold_stock_offline_payment_join_sql() . "
                LEFT JOIN products p ON p.id = i.product_id
                LEFT JOIN brands b ON b.id = p.brand_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY i.product_id, i.product_name
                ORDER BY sold_out DESC, total_sold DESC, product_name ASC
                LIMIT " . (int)$limit;
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_map(static function (array $row): array {
        return [
            'product_id' => (int)($row['product_id'] ?? 0),
            'product_name' => (string)($row['product_name'] ?? ''),
            'brand_name' => (string)($row['brand_name'] ?? ''),
            'brand_color' => (string)($row['brand_color'] ?? ''),
            'sku' => (string)($row['sku'] ?? ''),
            'total_sold' => (float)($row['total_sold'] ?? 0),
            'qty_return' => (float)($row['qty_return'] ?? 0),
            'sold_out' => (float)($row['sold_out'] ?? 0),
            'sold_qty' => (float)($row['sold_out'] ?? 0),
            'buy_back_qty' => 0.0,
            'order_count' => (int)($row['order_count'] ?? 0),
            'last_sold_at' => (string)($row['last_sold_at'] ?? ''),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

    if ($source === 'offline' && $flow === 'buy_back') {
        foreach ($rows as &$row) {
            $row['sold_qty'] = 0.0;
            $row['buy_back_qty'] = (float)($row['sold_out'] ?? 0);
        }
        unset($row);
    }

    $dailyRows = $source === 'offline'
        ? sold_stock_offline_daily_rows($pdo, $from, $to, $q, $brandId, $teamId, $productId, $paymentStatus, $limit)
        : sold_stock_online_daily_rows($pdo, $from, $to, $q, $brandId, $productId, $paymentStatus, $limit);

    if ($source === 'offline' && $flow === 'both') {
        $productMap = [];
        foreach ($dailyRows as $daily) {
            $key = (string)($daily['product_id'] ?: $daily['product_name']);
            if (!isset($productMap[$key])) {
                $productMap[$key] = [
                    'product_id' => (int)($daily['product_id'] ?? 0),
                    'product_name' => (string)($daily['product_name'] ?? ''),
                    'brand_name' => (string)($daily['brand_name'] ?? ''),
                    'brand_color' => (string)($daily['brand_color'] ?? ''),
                    'sku' => (string)($daily['sku'] ?? ''),
                    'total_sold' => 0.0,
                    'qty_return' => 0.0,
                    'sold_out' => 0.0,
                    'sold_qty' => 0.0,
                    'buy_back_qty' => 0.0,
                    'order_count' => 0,
                    'last_sold_at' => '',
                ];
            }
            $productMap[$key]['sold_qty'] += (float)($daily['sold_qty'] ?? 0);
            $productMap[$key]['buy_back_qty'] += (float)($daily['buy_back_qty'] ?? 0);
            $productMap[$key]['total_sold'] += (float)($daily['sold_qty'] ?? 0);
            $productMap[$key]['sold_out'] += (float)($daily['sold_qty'] ?? 0);
            if (($daily['last_update'] ?? '') !== '' && (string)$daily['last_update'] > (string)$productMap[$key]['last_sold_at']) {
                $productMap[$key]['last_sold_at'] = (string)$daily['last_update'];
            }
        }
        $rows = array_values($productMap);
        usort($rows, static function (array $a, array $b): int {
            $aQty = (float)($a['sold_qty'] ?? 0) + (float)($a['buy_back_qty'] ?? 0);
            $bQty = (float)($b['sold_qty'] ?? 0) + (float)($b['buy_back_qty'] ?? 0);
            if ($aQty === $bQty) {
                return strcmp((string)($a['product_name'] ?? ''), (string)($b['product_name'] ?? ''));
            }
            return $bQty <=> $aQty;
        });
    }

    $summary = [
        'product_count' => count($rows),
        'total_sold' => 0.0,
        'qty_return' => 0.0,
        'sold_out' => 0.0,
        'buy_back_qty' => 0.0,
        'order_count' => 0,
    ];
    foreach ($rows as $row) {
        $summary['total_sold'] += (float)$row['total_sold'];
        $summary['qty_return'] += (float)$row['qty_return'];
        $summary['sold_out'] += (float)$row['sold_out'];
        $summary['buy_back_qty'] += (float)($row['buy_back_qty'] ?? 0);
        $summary['order_count'] += (int)$row['order_count'];
    }

    api_json([
        'success' => true,
        'source' => $source,
        'flow' => $flow,
        'rows' => $rows,
        'daily_rows' => $dailyRows,
        'summary' => $summary,
        'brand_options' => sold_stock_brand_options($pdo),
        'team_options' => $source === 'offline' ? sold_stock_team_options($pdo) : [],
        'product_options' => sold_stock_product_options($pdo),
    ]);
} catch (Throwable $e) {
    error_log('inventory_sold_stock API error: ' . $e->getMessage());
    api_error('Unable to load sold stock report.', 500);
}

