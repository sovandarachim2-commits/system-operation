<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_role_or_permission(['admin'], 'commission_sales.view', 'sr_sales_dashboard.view', 'sr_daily_offline_sale.view');

function cs_date(string $key, ?string $fallback = null): string
{
    $value = trim((string)($_GET[$key] ?? ''));
    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    return $fallback ?? date('Y-m-d');
}

function cs_int(string $key): ?int
{
    $value = trim((string)($_GET[$key] ?? ''));
    if ($value === '' || $value === '0') {
        return null;
    }
    $int = filter_var($value, FILTER_VALIDATE_INT);
    return $int === false ? null : (int)$int;
}

function cs_payment_status(string $key): string
{
    $value = strtolower(trim((string)($_GET[$key] ?? 'all')));
    return in_array($value, ['all', 'paid', 'unpaid'], true) ? $value : 'all';
}

function cs_columns_sql(array $where): string
{
    return $where === [] ? '' : ' AND ' . implode(' AND ', $where);
}

function cs_wrap_param(?string $sql, array $params): array
{
    return [trim((string)$sql), array_values($params)];
}

try {
    $pdo = get_db_connection();

    $from = cs_date('from', date('Y-m-01'));
    $to = cs_date('to', date('Y-m-d'));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $quick = trim((string)($_GET['quick'] ?? ''));
    if ($quick === 'today') {
        $from = date('Y-m-d');
        $to = date('Y-m-d');
    } elseif ($quick === 'yesterday') {
        $from = date('Y-m-d', strtotime('-1 day'));
        $to = date('Y-m-d', strtotime('-1 day'));
    } elseif ($quick === 'this_month') {
        $from = date('Y-m-01');
        $to = date('Y-m-t');
    } elseif ($quick === 'last_month') {
        $from = date('Y-m-01', strtotime('first day of last month'));
        $to = date('Y-m-t', strtotime('last day of last month'));
    }

    $paymentStatus = cs_payment_status('payment_status');
    $sellerId = cs_int('seller_id');
    $brandId = cs_int('brand_id');

    $paymentSql = "AND o.status IN ('paid', 'unpaid')";
    $paymentSqlExists = "AND o.status IN ('paid', 'unpaid')";
    if ($paymentStatus !== 'all') {
        $paymentSql = "AND o.status = :payment_status";
        $paymentSqlExists = "AND o.status = :payment_status_exists";
    }

    $brandSql = '';
    $brandSqlExists = '';
    if ($brandId !== null) {
        $brandSql = 'AND p.brand_id = :brand_id';
        $brandSqlExists = 'AND EXISTS (SELECT 1 FROM order_items oi2 JOIN products p2 ON p2.id = oi2.product_id WHERE oi2.order_id = o.id AND p2.brand_id = :brand_id_exists)';
    }

    $sellerSql = '';
    $sellerSqlExists = '';
    if ($sellerId !== null) {
        $sellerSql = 'AND o.seller_id = :seller_id';
        $sellerSqlExists = 'AND o.seller_id = :seller_id_exists';
    }

    $brandColorsSql = "CASE WHEN LOWER(COALESCE(p.product_type,'')) = 'set' THEN
            COALESCE((
                SELECT CASE WHEN COUNT(DISTINCT cb.id) > 1 THEN '#6c757d' ELSE MAX(cb.color) END
                FROM product_sets ps2
                JOIN product_set_items psi2 ON psi2.product_set_id = ps2.id
                JOIN products cp ON cp.id = psi2.product_id
                LEFT JOIN brands cb ON cb.id = cp.brand_id
                WHERE ps2.set_name = p.name
            ), b.color)
        ELSE b.color END";

    $brandNamesSql = "CASE WHEN LOWER(COALESCE(p.product_type,'')) = 'set' THEN
            COALESCE((
                SELECT CASE WHEN COUNT(DISTINCT cb.id) > 1 THEN 'Mix Brand' ELSE MAX(cb.name) END
                FROM product_sets ps2
                JOIN product_set_items psi2 ON psi2.product_set_id = ps2.id
                JOIN products cp ON cp.id = psi2.product_id
                LEFT JOIN brands cb ON cb.id = cp.brand_id
                WHERE ps2.set_name = p.name
            ), b.name)
        ELSE b.name END";

    $commissionExpr = "CASE
        WHEN COALESCE(pc.commission_rate, 0) > 0 THEN oi.line_total * (pc.commission_rate / 100)
        ELSE oi.quantity * COALESCE(pc.commission_amount, 0)
    END";

    $pcJoin = "LEFT JOIN product_costs pc ON pc.product_id = p.id
        AND pc.month_year = (
            SELECT MAX(pc2.month_year)
            FROM product_costs pc2
            WHERE pc2.product_id = p.id
                AND pc2.month_year <= DATE_FORMAT(pj.printed_at, '%Y-%m')
        )";

    $baseJoin = "FROM orders o
    JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        WHERE printed_at IS NOT NULL
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN brands b ON b.id = p.brand_id
    {$pcJoin}";

    function cs_bind_common(PDOStatement $stmt, array $bindings): void
    {
        foreach ($bindings as $key => $val) {
            if ($val === null || $val === '') continue;
            if (is_int($val)) {
                $stmt->bindValue($key, $val, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $val);
            }
        }
    }

    // 1. Commission summary by seller
    $baseSummary = "SELECT
        COALESCE(NULLIF(TRIM(u.name), ''), u.username) as seller_name,
        u.id as seller_id,
        CASE WHEN LOWER(COALESCE(p.product_type, '')) = 'set' THEN 'Set' ELSE 'Item' END as product_type,
        {$brandNamesSql} AS brand_name,
        {$brandColorsSql} AS brand_color,
        SUM(oi.quantity) as total_qty,
        SUM(
            CASE
                WHEN COALESCE(ot.order_gross, 0) > 0 THEN COALESCE(dc.amount, 0) * (oi.line_total / ot.order_gross)
                ELSE 0
            END
        ) as total_delivery_cost,
        SUM(
            CASE
                WHEN COALESCE(ot.order_gross, 0) > 0 THEN COALESCE(o.discount, 0) * (oi.line_total / ot.order_gross)
                ELSE 0
            END
        ) as total_discount,
        SUM(oi.line_total) - SUM(
            CASE
                WHEN COALESCE(ot.order_gross, 0) > 0 THEN COALESCE(o.discount, 0) * (oi.line_total / ot.order_gross)
                ELSE 0
            END
        ) as total_sales,
        SUM({$commissionExpr}) as total_commission,
        COUNT(DISTINCT o.id) as total_orders
    {$baseJoin}
    LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
    JOIN (
        SELECT order_id, SUM(line_total) AS order_gross
        FROM order_items
        GROUP BY order_id
    ) ot ON ot.order_id = o.id
    JOIN users u ON o.seller_id = u.id
    WHERE DATE(pj.printed_at) BETWEEN :date_from AND :date_to
        AND o.is_cancelled = 0
        AND o.is_returned = 0
        {$paymentSql}
        {$brandSql}
        {$sellerSql}
    GROUP BY u.id, u.name, u.username, product_type, brand_name, brand_color
    ORDER BY seller_name ASC, product_type ASC, brand_name ASC";

    $summaryStmt = $pdo->prepare($baseSummary);
    $binds = [
        ':date_from' => $from,
        ':date_to' => $to,
    ];
    if ($paymentStatus !== 'all') $binds[':payment_status'] = $paymentStatus;
    if ($brandId !== null) $binds[':brand_id'] = $brandId;
    if ($sellerId !== null) $binds[':seller_id'] = $sellerId;
    cs_bind_common($summaryStmt, $binds);
    $summaryStmt->execute();
    $sellerSummary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Top products
    $topProductsSql = "SELECT
        p.id as product_id,
        p.name as product_name,
        CASE WHEN LOWER(COALESCE(p.product_type, '')) = 'set' THEN 'Set' ELSE 'Item' END as product_type,
        {$brandNamesSql} AS brand_name,
        {$brandColorsSql} AS brand_color,
        SUM(oi.quantity) as total_qty,
        SUM(oi.line_total) as total_gross,
        SUM(
            CASE
                WHEN COALESCE(ot.order_gross, 0) > 0 THEN COALESCE(o.discount, 0) * (oi.line_total / ot.order_gross)
                ELSE 0
            END
        ) as total_discount,
        SUM(oi.line_total) - SUM(
            CASE
                WHEN COALESCE(ot.order_gross, 0) > 0 THEN COALESCE(o.discount, 0) * (oi.line_total / ot.order_gross)
                ELSE 0
            END
        ) as total_sales,
        SUM({$commissionExpr}) as total_commission,
        COUNT(DISTINCT o.id) as order_count
    {$baseJoin}
    JOIN (
        SELECT order_id, SUM(line_total) AS order_gross
        FROM order_items
        GROUP BY order_id
    ) ot ON ot.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN :date_from AND :date_to
        AND o.is_cancelled = 0
        AND o.is_returned = 0
        {$paymentSql}
        {$brandSql}
        {$sellerSql}
    GROUP BY p.id, p.name, p.product_type
    ORDER BY total_sales DESC, total_qty DESC";
    $topProductsStmt = $pdo->prepare($topProductsSql);
    cs_bind_common($topProductsStmt, $binds);
    $topProductsStmt->execute();
    $topProducts = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Seller × product breakdown
    $sellerProductsSql = "SELECT
        COALESCE(NULLIF(TRIM(u.name), ''), u.username) as seller_name,
        u.id as seller_id,
        p.id as product_id,
        p.name as product_name,
        CASE WHEN LOWER(COALESCE(p.product_type, '')) = 'set' THEN 'Set' ELSE 'Item' END as product_type,
        {$brandNamesSql} AS brand_name,
        {$brandColorsSql} AS brand_color,
        SUM(oi.quantity) as total_qty,
        SUM(oi.line_total) as total_gross,
        SUM(
            CASE
                WHEN COALESCE(ot.order_gross, 0) > 0 THEN COALESCE(o.discount, 0) * (oi.line_total / ot.order_gross)
                ELSE 0
            END
        ) as total_discount,
        SUM(oi.line_total) - SUM(
            CASE
                WHEN COALESCE(ot.order_gross, 0) > 0 THEN COALESCE(o.discount, 0) * (oi.line_total / ot.order_gross)
                ELSE 0
            END
        ) as total_sales,
        SUM({$commissionExpr}) as total_commission,
        COUNT(DISTINCT o.id) as order_count
    {$baseJoin}
    JOIN (
        SELECT order_id, SUM(line_total) AS order_gross
        FROM order_items
        GROUP BY order_id
    ) ot ON ot.order_id = o.id
    JOIN users u ON o.seller_id = u.id
    WHERE DATE(pj.printed_at) BETWEEN :date_from AND :date_to
        AND o.is_cancelled = 0
        AND o.is_returned = 0
        {$paymentSql}
        {$brandSql}
        {$sellerSql}
    GROUP BY u.id, u.name, u.username, p.id, p.name, p.product_type
    ORDER BY seller_name ASC, total_sales DESC, product_name ASC";
    $sellerProductsStmt = $pdo->prepare($sellerProductsSql);
    cs_bind_common($sellerProductsStmt, $binds);
    $sellerProductsStmt->execute();
    $sellerProducts = $sellerProductsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Transaction list (row level)
    $detailSql = "SELECT
        DATE(pj.printed_at) as print_date,
        o.order_code,
        o.status,
        COALESCE(NULLIF(TRIM(u.name), ''), u.username) as seller_name,
        u.id as seller_id,
        p.name as product_name,
        p.id as product_id,
        CASE WHEN LOWER(COALESCE(p.product_type, '')) = 'set' THEN 'Set' ELSE 'Item' END as product_type,
        oi.quantity,
        oi.line_total,
        COALESCE(pc.commission_rate, 0) as commission_rate,
        COALESCE(pc.commission_amount, 0) as commission_amount,
        {$commissionExpr} as commission_value,
        {$brandNamesSql} AS brand_name,
        {$brandColorsSql} AS brand_color
    {$baseJoin}
    JOIN users u ON o.seller_id = u.id
    WHERE DATE(pj.printed_at) BETWEEN :date_from AND :date_to
        AND o.is_cancelled = 0
        AND o.is_returned = 0
        {$paymentSql}
        {$brandSql}
        {$sellerSql}
    ORDER BY pj.printed_at DESC, o.id DESC, p.name ASC";
    $detailStmt = $pdo->prepare($detailSql);
    cs_bind_common($detailStmt, $binds);
    $detailStmt->execute();
    $transactions = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Order status counts (for the 6th metric card)
    $countsSql = "SELECT
        COUNT(DISTINCT o.id) AS total_orders,
        COUNT(DISTINCT CASE WHEN o.status = 'paid' THEN o.id END) AS paid_orders,
        COUNT(DISTINCT CASE WHEN o.status = 'unpaid' THEN o.id END) AS unpaid_orders,
        COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.total_amount ELSE 0 END), 0) AS paid_amount,
        COALESCE(SUM(CASE WHEN o.status = 'unpaid' THEN o.total_amount ELSE 0 END), 0) AS unpaid_amount
    FROM orders o
    JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        WHERE printed_at IS NOT NULL
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN :date_from AND :date_to
        AND o.is_cancelled = 0
        AND o.is_returned = 0
        {$paymentSqlExists}
        {$brandSqlExists}
        {$sellerSqlExists}";
    $countsStmt = $pdo->prepare($countsSql);
    $countsBinds = [
        ':date_from' => $from,
        ':date_to' => $to,
    ];
    if ($paymentStatus !== 'all') $countsBinds[':payment_status_exists'] = $paymentStatus;
    if ($brandId !== null) $countsBinds[':brand_id_exists'] = $brandId;
    if ($sellerId !== null) $countsBinds[':seller_id_exists'] = $sellerId;
    cs_bind_common($countsStmt, $countsBinds);
    $countsStmt->execute();
    $orderCounts = $countsStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_orders' => 0,
        'paid_orders' => 0,
        'unpaid_orders' => 0,
        'paid_amount' => 0,
        'unpaid_amount' => 0,
    ];

    // 6. Seller dropdown options (based on matching date window)
    $sellersSql = "SELECT DISTINCT
        u.id as seller_id,
        COALESCE(NULLIF(TRIM(u.name), ''), u.username) as seller_name
    FROM orders o
    JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        WHERE printed_at IS NOT NULL
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    JOIN users u ON o.seller_id = u.id
    WHERE DATE(pj.printed_at) BETWEEN :date_from AND :date_to
        AND o.is_cancelled = 0
        AND o.is_returned = 0
        {$paymentSqlExists}
        {$brandSqlExists}
    ORDER BY seller_name ASC";
    $sellersStmt = $pdo->prepare($sellersSql);
    $sellersBinds = [
        ':date_from' => $from,
        ':date_to' => $to,
    ];
    if ($paymentStatus !== 'all') $sellersBinds[':payment_status_exists'] = $paymentStatus;
    if ($brandId !== null) $sellersBinds[':brand_id_exists'] = $brandId;
    cs_bind_common($sellersStmt, $sellersBinds);
    $sellersStmt->execute();
    $sellerOptions = $sellersStmt->fetchAll(PDO::FETCH_ASSOC);

    $brands = $pdo->query('SELECT id, name, color FROM brands WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

    $totalCommission = (float)array_sum(array_column($sellerSummary, 'total_commission'));
    $totalSales = (float)array_sum(array_column($sellerSummary, 'total_sales'));
    $totalDiscount = (float)array_sum(array_column($sellerSummary, 'total_discount'));
    $totalDeliveryCost = (float)array_sum(array_column($sellerSummary, 'total_delivery_cost'));
    $totalGross = $totalSales + $totalDiscount + $totalDeliveryCost;
    $totalQty = (float)array_sum(array_column($sellerSummary, 'total_qty'));
    $uniqueSellers = count(array_unique(array_column($sellerSummary, 'seller_id')));
    $avgRate = $totalSales > 0 ? ($totalCommission / $totalSales) * 100 : 0;

    $totalLineTotal = (float)array_sum(array_column($transactions, 'line_total'));
    $detailCommissionTotal = (float)array_sum(array_column($transactions, 'commission_value'));
    $detailAvgRate = $totalLineTotal > 0 ? ($detailCommissionTotal / $totalLineTotal) * 100 : 0;

    api_json([
        'success' => true,
        'filters' => [
            'from' => $from,
            'to' => $to,
            'quick' => $quick,
            'payment_status' => $paymentStatus,
            'seller_id' => $sellerId,
            'brand_id' => $brandId,
            'period_label' => date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)),
        ],
        'options' => [
            'sellers' => $sellerOptions,
            'brands' => $brands,
            'payment_statuses' => [
                ['value' => 'all', 'label' => 'All Status'],
                ['value' => 'paid', 'label' => 'Paid'],
                ['value' => 'unpaid', 'label' => 'Unpaid'],
            ],
        ],
        'summary' => [
            'total_commission' => $totalCommission,
            'total_sales' => $totalSales,
            'total_discount' => $totalDiscount,
            'total_gross' => $totalGross,
            'total_delivery_cost' => $totalDeliveryCost,
            'total_qty' => $totalQty,
            'total_orders' => (int)($orderCounts['total_orders'] ?? 0),
            'paid_orders' => (int)($orderCounts['paid_orders'] ?? 0),
            'unpaid_orders' => (int)($orderCounts['unpaid_orders'] ?? 0),
            'paid_amount' => (float)($orderCounts['paid_amount'] ?? 0),
            'unpaid_amount' => (float)($orderCounts['unpaid_amount'] ?? 0),
            'unique_sellers' => $uniqueSellers,
            'avg_commission_rate' => round($avgRate, 2),
            'detail_avg_rate' => round($detailAvgRate, 2),
            'detail_line_total' => $totalLineTotal,
            'detail_total_commission' => $detailCommissionTotal,
            'detail_total_qty' => (float)array_sum(array_column($transactions, 'quantity')),
        ],
        'tables' => [
            'seller_summary' => $sellerSummary,
            'top_products' => $topProducts,
            'seller_products' => $sellerProducts,
            'transactions' => $transactions,
        ],
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500, [
        'exception_class' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}
