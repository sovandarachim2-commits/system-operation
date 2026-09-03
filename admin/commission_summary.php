<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_role_or_permission(['admin'], 'commission_sales.view');

$pdo = get_db_connection();

// Get filter parameters
$quick_filter = $_GET['quick_filter'] ?? '';
$allowed_quick_filters = ['today', 'yesterday', 'this_month', 'last_month'];
if (!in_array($quick_filter, $allowed_quick_filters, true)) {
    $quick_filter = '';
}

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$selected_seller_id = isset($_GET['seller_filter_id']) ? (int)$_GET['seller_filter_id'] : 0;
$selected_payment_status = $_GET['payment_status'] ?? 'all';
$allowed_payment_statuses = ['all', 'paid', 'unpaid'];
if (!in_array($selected_payment_status, $allowed_payment_statuses, true)) {
    $selected_payment_status = 'all';
}
$brand_filter_id = isset($_GET['brand_filter_id']) ? (int)$_GET['brand_filter_id'] : 0;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = date('Y-m-d');
}
if ($date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
}

if ($quick_filter === 'today') {
    $date_from = date('Y-m-d');
    $date_to = date('Y-m-d');
} elseif ($quick_filter === 'yesterday') {
    $date_from = date('Y-m-d', strtotime('-1 day'));
    $date_to = date('Y-m-d', strtotime('-1 day'));
} elseif ($quick_filter === 'this_month') {
    $date_from = date('Y-m-01');
    $date_to = date('Y-m-t');
} elseif ($quick_filter === 'last_month') {
    $lastMonthTs = strtotime('first day of last month');
    $date_from = date('Y-m-01', $lastMonthTs);
    $date_to = date('Y-m-t', $lastMonthTs);
}

$report_period_label = date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to));
$report_params = [
    'date_from' => $date_from,
    'date_to' => $date_to,
];
$payment_status_condition = "AND o.status IN ('paid', 'unpaid')";
if ($selected_payment_status !== 'all') {
    $payment_status_condition = "AND o.status = :payment_status";
    $report_params['payment_status'] = $selected_payment_status;
}

$brand_condition = '';
$brand_condition_exists = '';
if ($brand_filter_id > 0) {
    $brand_condition = 'AND p.brand_id = :brand_filter_id';
    $brand_condition_exists = 'AND EXISTS (SELECT 1 FROM order_items oi2 JOIN products p2 ON p2.id = oi2.product_id WHERE oi2.order_id = o.id AND p2.brand_id = :brand_filter_id)';
    $report_params['brand_filter_id'] = $brand_filter_id;
}

// Fetch company branding from invoice settings
$invoice_settings = get_invoice_settings($pdo);
$company_name = $invoice_settings['company_name'] ?? 'Company';
$logo_url = '';

// Fetch logo if set in invoice settings
if (!empty($invoice_settings['logo_id'])) {
    $logo_query = "SELECT file_path FROM logos WHERE id = ? LIMIT 1";
    $logo_stmt = $pdo->prepare($logo_query);
    $logo_stmt->execute([$invoice_settings['logo_id']]);
    $logo_result = $logo_stmt->fetch(PDO::FETCH_ASSOC);
    if ($logo_result && !empty($logo_result['file_path'])) {
        $logo_url = uploaded_file_url($logo_result['file_path'], 'logos');
    }
}

// Calculate commission summary
$query = "
    SELECT
        COALESCE(NULLIF(TRIM(u.name), ''), u.username) as seller_name,
        u.id as seller_id,
        CASE WHEN LOWER(COALESCE(p.product_type, '')) = 'set' THEN 'Set' ELSE 'Item' END as product_type,
        pb.eff_brand_name AS brand_name,
        pb.eff_brand_color AS brand_color,
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
        SUM(
            CASE
                WHEN COALESCE(pc.commission_rate, 0) > 0 THEN oi.line_total * (pc.commission_rate / 100)
                ELSE oi.quantity * COALESCE(pc.commission_amount, 0)
            END
        ) as total_commission,
        COUNT(DISTINCT o.id) as total_orders
    FROM orders o
    JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        WHERE printed_at IS NOT NULL
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN (
        SELECT order_id, SUM(line_total) AS order_gross
        FROM order_items
        GROUP BY order_id
    ) ot ON ot.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN product_costs pc ON pc.product_id = p.id
        AND pc.month_year = (
            SELECT MAX(pc2.month_year)
            FROM product_costs pc2
            WHERE pc2.product_id = p.id
                AND pc2.month_year <= DATE_FORMAT(pj.printed_at, '%Y-%m')
        )
    LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
    JOIN (
        SELECT p2.id,
            CASE WHEN LOWER(COALESCE(p2.product_type,'')) = 'set' THEN
                COALESCE((
                    SELECT CASE WHEN COUNT(DISTINCT cb.id) > 1 THEN 'Mix Brand' ELSE MAX(cb.name) END
                    FROM product_sets ps2
                    JOIN product_set_items psi2 ON psi2.product_set_id = ps2.id
                    JOIN products cp ON cp.id = psi2.product_id
                    LEFT JOIN brands cb ON cb.id = cp.brand_id
                    WHERE ps2.set_name = p2.name
                ), b2.name)
            ELSE b2.name END AS eff_brand_name,
            CASE WHEN LOWER(COALESCE(p2.product_type,'')) = 'set' THEN
                COALESCE((
                    SELECT CASE WHEN COUNT(DISTINCT cb.id) > 1 THEN '#6c757d' ELSE MAX(cb.color) END
                    FROM product_sets ps2
                    JOIN product_set_items psi2 ON psi2.product_set_id = ps2.id
                    JOIN products cp ON cp.id = psi2.product_id
                    LEFT JOIN brands cb ON cb.id = cp.brand_id
                    WHERE ps2.set_name = p2.name
                ), b2.color)
            ELSE b2.color END AS eff_brand_color
        FROM products p2
        LEFT JOIN brands b2 ON b2.id = p2.brand_id
    ) pb ON pb.id = p.id
    JOIN users u ON o.seller_id = u.id
    WHERE DATE(pj.printed_at) BETWEEN :date_from AND :date_to
        AND o.is_cancelled = 0
        AND o.is_returned = 0
        {$payment_status_condition}
        {$brand_condition}
    GROUP BY u.id, u.name, u.username, product_type, pb.eff_brand_name, pb.eff_brand_color
    ORDER BY seller_name ASC, product_type ASC, brand_name ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute($report_params);
$commission_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load row-level commission details
$detail_query = "
    SELECT
        DATE(pj.printed_at) as print_date,
        o.order_code,
        o.status,
        COALESCE(NULLIF(TRIM(u.name), ''), u.username) as seller_name,
        p.name as product_name,
        CASE
        WHEN LOWER(COALESCE(p.product_type, '')) = 'set' THEN 'Set'
        ELSE 'Item'
        END as product_type,
        oi.quantity,
        oi.line_total,
        COALESCE(pc.commission_rate, 0) as commission_rate,
        COALESCE(pc.commission_amount, 0) as commission_amount,
        CASE
            WHEN COALESCE(pc.commission_rate, 0) > 0 THEN oi.line_total * (pc.commission_rate / 100)
            ELSE oi.quantity * COALESCE(pc.commission_amount, 0)
        END as commission_value,
        CASE WHEN LOWER(COALESCE(p.product_type,'')) = 'set' THEN
            COALESCE((
                SELECT CASE WHEN COUNT(DISTINCT cb.id) > 1 THEN 'Mix Brand' ELSE MAX(cb.name) END
                FROM product_sets ps2
                JOIN product_set_items psi2 ON psi2.product_set_id = ps2.id
                JOIN products cp ON cp.id = psi2.product_id
                LEFT JOIN brands cb ON cb.id = cp.brand_id
                WHERE ps2.set_name = p.name
            ), b.name)
        ELSE b.name END AS brand_name,
        CASE WHEN LOWER(COALESCE(p.product_type,'')) = 'set' THEN
            COALESCE((
                SELECT CASE WHEN COUNT(DISTINCT cb.id) > 1 THEN '#6c757d' ELSE MAX(cb.color) END
                FROM product_sets ps2
                JOIN product_set_items psi2 ON psi2.product_set_id = ps2.id
                JOIN products cp ON cp.id = psi2.product_id
                LEFT JOIN brands cb ON cb.id = cp.brand_id
                WHERE ps2.set_name = p.name
            ), b.color)
        ELSE b.color END AS brand_color
    FROM orders o
    JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        WHERE printed_at IS NOT NULL
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN product_costs pc ON pc.product_id = p.id
        AND pc.month_year = (
            SELECT MAX(pc2.month_year)
            FROM product_costs pc2
            WHERE pc2.product_id = p.id
                AND pc2.month_year <= DATE_FORMAT(pj.printed_at, '%Y-%m')
        )
    JOIN users u ON o.seller_id = u.id
    WHERE DATE(pj.printed_at) BETWEEN :date_from AND :date_to
        AND o.is_cancelled = 0
        AND o.is_returned = 0
        {$payment_status_condition}
        {$brand_condition}
    ORDER BY pj.printed_at DESC, o.id DESC, p.name ASC
";

$detail_stmt = $pdo->prepare($detail_query);
$detail_stmt->execute($report_params);
$commission_detail_data = $detail_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load top products summary
$top_products_query = "
    SELECT
        p.id as product_id,
        p.name as product_name,
        CASE
        WHEN LOWER(COALESCE(p.product_type, '')) = 'set' THEN 'Set'
        ELSE 'Item'
        END as product_type,
        CASE WHEN LOWER(COALESCE(p.product_type,'')) = 'set' THEN
            COALESCE((
                SELECT CASE WHEN COUNT(DISTINCT cb.id) > 1 THEN 'Mix Brand' ELSE MAX(cb.name) END
                FROM product_sets ps2
                JOIN product_set_items psi2 ON psi2.product_set_id = ps2.id
                JOIN products cp ON cp.id = psi2.product_id
                LEFT JOIN brands cb ON cb.id = cp.brand_id
                WHERE ps2.set_name = p.name
            ), b.name)
        ELSE b.name END AS brand_name,
        CASE WHEN LOWER(COALESCE(p.product_type,'')) = 'set' THEN
            COALESCE((
                SELECT CASE WHEN COUNT(DISTINCT cb.id) > 1 THEN '#6c757d' ELSE MAX(cb.color) END
                FROM product_sets ps2
                JOIN product_set_items psi2 ON psi2.product_set_id = ps2.id
                JOIN products cp ON cp.id = psi2.product_id
                LEFT JOIN brands cb ON cb.id = cp.brand_id
                WHERE ps2.set_name = p.name
            ), b.color)
        ELSE b.color END AS brand_color,
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
        SUM(
            CASE
                WHEN COALESCE(pc.commission_rate, 0) > 0 THEN oi.line_total * (pc.commission_rate / 100)
                ELSE oi.quantity * COALESCE(pc.commission_amount, 0)
            END
        ) as total_commission,
        COUNT(DISTINCT o.id) as order_count
    FROM orders o
    JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        WHERE printed_at IS NOT NULL
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN (
        SELECT order_id, SUM(line_total) AS order_gross
        FROM order_items
        GROUP BY order_id
    ) ot ON ot.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN product_costs pc ON pc.product_id = p.id
        AND pc.month_year = (
            SELECT MAX(pc2.month_year)
            FROM product_costs pc2
            WHERE pc2.product_id = p.id
                AND pc2.month_year <= DATE_FORMAT(pj.printed_at, '%Y-%m')
        )
    WHERE DATE(pj.printed_at) BETWEEN :date_from AND :date_to
        AND o.is_cancelled = 0
        AND o.is_returned = 0
        {$payment_status_condition}
        {$brand_condition}
    GROUP BY p.id, p.name, p.product_type
    ORDER BY total_sales DESC, total_qty DESC
";

$top_products_stmt = $pdo->prepare($top_products_query);
$top_products_stmt->execute($report_params);
$top_products_data = $top_products_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load sellers for seller-product filter dropdown
$seller_options_query = "
    SELECT DISTINCT
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
        {$payment_status_condition}
        {$brand_condition_exists}
    ORDER BY seller_name ASC
";

$seller_options_stmt = $pdo->prepare($seller_options_query);
$seller_options_stmt->execute($report_params);
$seller_options = $seller_options_stmt->fetchAll(PDO::FETCH_ASSOC);

$brands = $pdo->query('SELECT id, name, color FROM brands WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// Load seller-product breakdown (each seller total per product)
$seller_products_query = "
    SELECT
        COALESCE(NULLIF(TRIM(u.name), ''), u.username) as seller_name,
        u.id as seller_id,
        p.id as product_id,
        p.name as product_name,
        CASE
        WHEN LOWER(COALESCE(p.product_type, '')) = 'set' THEN 'Set'
        ELSE 'Item'
        END as product_type,
        CASE WHEN LOWER(COALESCE(p.product_type,'')) = 'set' THEN
            COALESCE((
                SELECT CASE WHEN COUNT(DISTINCT cb.id) > 1 THEN 'Mix Brand' ELSE MAX(cb.name) END
                FROM product_sets ps2
                JOIN product_set_items psi2 ON psi2.product_set_id = ps2.id
                JOIN products cp ON cp.id = psi2.product_id
                LEFT JOIN brands cb ON cb.id = cp.brand_id
                WHERE ps2.set_name = p.name
            ), b.name)
        ELSE b.name END AS brand_name,
        CASE WHEN LOWER(COALESCE(p.product_type,'')) = 'set' THEN
            COALESCE((
                SELECT CASE WHEN COUNT(DISTINCT cb.id) > 1 THEN '#6c757d' ELSE MAX(cb.color) END
                FROM product_sets ps2
                JOIN product_set_items psi2 ON psi2.product_set_id = ps2.id
                JOIN products cp ON cp.id = psi2.product_id
                LEFT JOIN brands cb ON cb.id = cp.brand_id
                WHERE ps2.set_name = p.name
            ), b.color)
        ELSE b.color END AS brand_color,
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
        SUM(
            CASE
                WHEN COALESCE(pc.commission_rate, 0) > 0 THEN oi.line_total * (pc.commission_rate / 100)
                ELSE oi.quantity * COALESCE(pc.commission_amount, 0)
            END
        ) as total_commission,
        COUNT(DISTINCT o.id) as order_count
    FROM orders o
    JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        WHERE printed_at IS NOT NULL
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN (
        SELECT order_id, SUM(line_total) AS order_gross
        FROM order_items
        GROUP BY order_id
    ) ot ON ot.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN product_costs pc ON pc.product_id = p.id
        AND pc.month_year = (
            SELECT MAX(pc2.month_year)
            FROM product_costs pc2
            WHERE pc2.product_id = p.id
                AND pc2.month_year <= DATE_FORMAT(pj.printed_at, '%Y-%m')
        )
    JOIN users u ON o.seller_id = u.id
    WHERE DATE(pj.printed_at) BETWEEN :date_from AND :date_to
        AND o.is_cancelled = 0
        AND o.is_returned = 0
        {$payment_status_condition}
        {$brand_condition}
    GROUP BY u.id, u.name, u.username, p.id, p.name, p.product_type
    ORDER BY seller_name ASC, total_sales DESC, product_name ASC
";

$seller_products_stmt = $pdo->prepare($seller_products_query);
$seller_products_stmt->execute($report_params);
$seller_products_data = $seller_products_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load order status counts for transaction list (distinct orders)
$order_status_counts_query = "
    SELECT
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
        {$payment_status_condition}
        {$brand_condition_exists}
";

$order_status_counts_stmt = $pdo->prepare($order_status_counts_query);
$order_status_counts_stmt->execute($report_params);
$order_status_counts = $order_status_counts_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_orders' => 0,
    'paid_orders' => 0,
    'unpaid_orders' => 0,
    'paid_amount' => 0,
    'unpaid_amount' => 0,
];

// Calculate totals
$total_commission = array_sum(array_column($commission_data, 'total_commission'));
$total_sales = array_sum(array_column($commission_data, 'total_sales'));
$total_discount = array_sum(array_column($commission_data, 'total_discount'));
$total_delivery_cost = array_sum(array_column($commission_data, 'total_delivery_cost'));
$total_gross = $total_sales + $total_discount + $total_delivery_cost;
$total_orders = (int)($order_status_counts['total_orders'] ?? 0);
$total_qty = array_sum(array_column($commission_data, 'total_qty'));
$top_total_qty = array_sum(array_column($top_products_data, 'total_qty'));
$top_total_gross = array_sum(array_column($top_products_data, 'total_gross'));
$top_total_sales = array_sum(array_column($top_products_data, 'total_sales'));
$top_total_discount = array_sum(array_column($top_products_data, 'total_discount'));
$top_total_commission = array_sum(array_column($top_products_data, 'total_commission'));
$top_total_orders = array_sum(array_column($top_products_data, 'order_count'));
$seller_products_total_qty = array_sum(array_column($seller_products_data, 'total_qty'));
$seller_products_total_gross = array_sum(array_column($seller_products_data, 'total_gross'));
$seller_products_total_discount = array_sum(array_column($seller_products_data, 'total_discount'));
$seller_products_total_sales = array_sum(array_column($seller_products_data, 'total_sales'));
$seller_products_total_commission = array_sum(array_column($seller_products_data, 'total_commission'));
$seller_products_total_orders = array_sum(array_column($seller_products_data, 'order_count'));
$detail_total_qty = array_sum(array_column($commission_detail_data, 'quantity'));
$detail_total_line_total = array_sum(array_column($commission_detail_data, 'line_total'));
$detail_total_commission = array_sum(array_column($commission_detail_data, 'commission_value'));
$detail_avg_rate = $detail_total_line_total > 0 ? ($detail_total_commission / $detail_total_line_total) * 100 : 0;

$page_title = "Commission Sales Report";
include __DIR__ . '/../layout/header.php';
?>

<div class="container-fluid flex-grow-1 py-4">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Commission Sales Report</h5>
                        <div class="badge bg-white text-primary fs-6"><?= number_format(count($commission_detail_data)) ?> Rows</div>
                    </div>
                </div>
                <div class="card-body">
    <div class="report-topbar d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <div class="report-subtitle">Styled summary and detailed commission transactions</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card report-card report-filter-card mb-4">
        <div class="card-body p-3 p-md-4">
            <form method="GET" class="row g-3" id="filterForm">
                <input type="hidden" name="quick_filter" id="quick_filter" value="<?= htmlspecialchars($quick_filter) ?>">
                <div class="col-12">
                    <label class="form-label">Quick Filter</label>
                    <div class="quick-filter-group">
                        <button type="button" class="btn quick-filter-btn <?= $quick_filter === 'today' ? 'active' : '' ?>" onclick="applyQuickFilter('today')">Today</button>
                        <button type="button" class="btn quick-filter-btn <?= $quick_filter === 'yesterday' ? 'active' : '' ?>" onclick="applyQuickFilter('yesterday')">Yesterday</button>
                        <button type="button" class="btn quick-filter-btn <?= $quick_filter === 'this_month' ? 'active' : '' ?>" onclick="applyQuickFilter('this_month')">This Month</button>
                        <button type="button" class="btn quick-filter-btn <?= $quick_filter === 'last_month' ? 'active' : '' ?>" onclick="applyQuickFilter('last_month')">Last Month</button>
                    </div>
                </div>
                <div class="col-12 col-md-5">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" name="date_from" id="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-12 col-md-5">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" name="date_to" id="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-12 col-md-2">
                    <label for="payment_status" class="form-label">Payment Status</label>
                    <select name="payment_status" id="payment_status" class="form-select">
                        <option value="all" <?= $selected_payment_status === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="paid" <?= $selected_payment_status === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="unpaid" <?= $selected_payment_status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label for="brand_filter_id" class="form-label">Brand</label>
                    <select name="brand_filter_id" id="brand_filter_id" class="form-select">
                        <option value="0">All Brands</option>
                        <?php foreach ($brands as $br): ?>
                            <option value="<?= (int)$br['id'] ?>" <?= $brand_filter_id === (int)$br['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($br['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-12 col-lg-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-soft w-100" onclick="clearQuickFilter()">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4 g-3">
        <div class="col-md-2">
            <div class="card metric-card metric-primary h-100">
                <div class="card-body">
                    <div class="metric-label">Total Commission</div>
                    <h3 class="metric-value">$<?= number_format($total_commission, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card metric-card metric-success h-100">
                <div class="card-body">
                    <div class="metric-label">Final Amount</div>
                    <h3 class="metric-value">$<?= number_format($total_sales, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card metric-card metric-warning h-100">
                <div class="card-body">
                    <div class="metric-label">Total Discount</div>
                    <h3 class="metric-value">$<?= number_format($total_discount, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card metric-card metric-info h-100">
                <div class="card-body">
                    <div class="metric-label">Total Orders</div>
                    <h3 class="metric-value"><?= number_format($total_orders) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card metric-card metric-paid h-100">
                <div class="card-body">
                    <div class="metric-label">Paid Orders</div>
                    <h3 class="metric-value"><?= number_format((float)$order_status_counts['paid_orders']) ?></h3>
                    <div class="metric-subvalue">$<?= number_format((float)$order_status_counts['paid_amount'], 2) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card metric-card metric-unpaid h-100">
                <div class="card-body">
                    <div class="metric-label">Unpaid Orders</div>
                    <h3 class="metric-value"><?= number_format((float)$order_status_counts['unpaid_orders']) ?></h3>
                    <div class="metric-subvalue">$<?= number_format((float)$order_status_counts['unpaid_amount'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Commission Table -->
    <div class="card report-card">
        <div class="card-header report-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0">Commission Summary by Seller</h5>
            <div class="d-flex align-items-center gap-2 table-export-actions">
                <span class="period-chip"><?= htmlspecialchars($report_period_label) ?></span>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="printTable('commissionTable', 'Commission Summary by Seller')">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                <button type="button" class="btn btn-sm btn-outline-success" onclick="exportTableToExcel('commissionTable', 'Commission Summary by Seller', 'seller_summary')">
                    <i class="bi bi-file-earmark-excel me-1"></i>Excel
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-clean table-hover mb-0" id="commissionTable">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Seller</th>
                            <th>Type</th>
                            <th>Brand</th>
                            <th>Qty</th>
                            <th>Gross Amount</th>
                            <th>Discount</th>
                            <th>Delivery Cost</th>
                            <th>Final Amount</th>
                            <th>Commission Amount</th>
                            <th>Orders Count</th>
                            <th>Commission Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $seller_rowcounts = [];
                        foreach ($commission_data as $r) {
                            $seller_rowcounts[$r['seller_id']] = ($seller_rowcounts[$r['seller_id']] ?? 0) + 1;
                        }
                        $seller_printed = [];
                        $seller_no = 0;
                        foreach ($commission_data as $row):
                            $sid = $row['seller_id'];
                            $is_first = !isset($seller_printed[$sid]);
                            if ($is_first) { $seller_no++; $seller_printed[$sid] = true; }
                            $rowspan = $seller_rowcounts[$sid];
                        ?>
                            <tr class="<?= !$is_first ? 'seller-type-row' : ($seller_no > 1 ? 'seller-group-start' : '') ?>">
                                <?php if ($is_first): ?>
                                <td rowspan="<?= $rowspan ?>" class="align-middle text-center fw-semibold"><?= $seller_no ?></td>
                                <td rowspan="<?= $rowspan ?>" class="align-middle fw-semibold"><?= htmlspecialchars($row['seller_name']) ?></td>
                                <?php endif; ?>
                                <td>
                                    <?php if ($row['product_type'] === 'Set'): ?>
                                        <span class="badge bg-purple-soft text-purple">Set</span>
                                    <?php else: ?>
                                                    <span class="badge bg-blue-soft text-blue">Item</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['brand_name'])): ?>
                                        <span class="fw-semibold" style="color:<?= htmlspecialchars($row['brand_color'] ?: '#6c757d') ?>;"><?= htmlspecialchars($row['brand_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format((float)$row['total_qty']) ?></td>
                                <td>$<?= number_format((float)$row['total_sales'] + (float)$row['total_discount'] + (float)$row['total_delivery_cost'], 2) ?></td>
                                <td>$<?= number_format((float)$row['total_discount'], 2) ?></td>
                                <td>$<?= number_format((float)$row['total_delivery_cost'], 2) ?></td>
                                <td>$<?= number_format((float)$row['total_sales'] + (float)$row['total_delivery_cost'], 2) ?></td>
                                <td>$<?= number_format((float)$row['total_commission'], 2) ?></td>
                                <td><?= number_format((float)$row['total_orders']) ?></td>
                                <td>
                                    <?php
                                    $rate = $row['total_sales'] > 0 ? ($row['total_commission'] / $row['total_sales']) * 100 : 0;
                                    echo number_format($rate, 2) . '%';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($commission_data)): ?>
                            <tr>
                                <td colspan="12" class="text-center text-muted">No commission data found for the selected period.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($commission_data)): ?>
                        <tfoot>
                            <tr>
                                <th colspan="4">Total</th>
                                <th><?= number_format($total_qty) ?></th>
                                <th>$<?= number_format($total_gross, 2) ?></th>
                                <th>$<?= number_format($total_discount, 2) ?></th>
                                <th>$<?= number_format($total_delivery_cost, 2) ?></th>
                                <th>$<?= number_format($total_sales + $total_delivery_cost, 2) ?></th>
                                <th>$<?= number_format($total_commission, 2) ?></th>
                                <th><?= number_format($total_orders) ?></th>
                                <th>
                                    <?php
                                    $avg_rate = $total_sales > 0 ? ($total_commission / $total_sales) * 100 : 0;
                                    echo number_format($avg_rate, 2) . '%';
                                    ?>
                                </th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="card report-card mt-4">
        <div class="card-header report-card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Top Products</h5>
            <div class="d-flex align-items-center gap-2 table-export-actions">
                <small class="row-pill">Rows: <?= number_format(count($top_products_data)) ?></small>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="printTable('topProductsTable', 'Top Products')">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                <button type="button" class="btn btn-sm btn-outline-success" onclick="exportTableToExcel('topProductsTable', 'Top Products', 'top_products')">
                    <i class="bi bi-file-earmark-excel me-1"></i>Excel
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-clean table-hover mb-0" id="topProductsTable">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Product</th>
                            <th>Brand</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Gross Amount</th>
                            <th>Discount</th>
                            <th>Final Amount</th>
                            <th>Total Commission</th>
                            <th>Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_products_data as $index => $product): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($product['product_name']) ?></td>
                                <td>
                                    <?php if (!empty($product['brand_name'])): ?>
                                        <span class="fw-semibold" style="color:<?= htmlspecialchars($product['brand_color'] ?: '#6c757d') ?>;"><?= htmlspecialchars($product['brand_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($product['product_type']) ?></td>
                                <td><?= number_format((float)$product['total_qty']) ?></td>
                                <td>$<?= number_format((float)$product['total_gross'], 2) ?></td>
                                <td>$<?= number_format((float)$product['total_discount'], 2) ?></td>
                                <td>$<?= number_format((float)$product['total_sales'], 2) ?></td>
                                <td>$<?= number_format((float)$product['total_commission'], 2) ?></td>
                                <td><?= number_format((float)$product['order_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($top_products_data)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted">No top product data found for the selected date range.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($top_products_data)): ?>
                        <tfoot>
                            <tr>
                                <th colspan="4">Total</th>
                                <th><?= number_format((float)$top_total_qty) ?></th>
                                <th>$<?= number_format((float)$top_total_gross, 2) ?></th>
                                <th>$<?= number_format((float)$top_total_discount, 2) ?></th>
                                <th>$<?= number_format((float)$top_total_sales, 2) ?></th>
                                <th>$<?= number_format((float)$top_total_commission, 2) ?></th>
                                <th><?= number_format((float)$top_total_orders) ?></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="card report-card mt-4">
        <div class="card-header report-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0">Seller Product Breakdown</h5>
            <div class="d-flex align-items-center gap-2 table-export-actions">
                <label for="seller_filter_id" class="small text-muted mb-0">Seller</label>
                <select id="seller_filter_id" name="seller_filter_id" class="form-select form-select-sm" style="min-width: 180px;" onchange="filterSellerProducts(this.value)">
                    <option value="0">All Seller</option>
                    <?php foreach ($seller_options as $seller_option): ?>
                        <option value="<?= (int)$seller_option['seller_id'] ?>" <?= ((int)$seller_option['seller_id'] === $selected_seller_id) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($seller_option['seller_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="row-pill" id="sellerProductsRowsCount">Rows: <?= number_format(count($seller_products_data)) ?></small>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="printTable('sellerProductsTable', 'Seller Product Breakdown')">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                <button type="button" class="btn btn-sm btn-outline-success" onclick="exportTableToExcel('sellerProductsTable', 'Seller Product Breakdown', 'seller_products')">
                    <i class="bi bi-file-earmark-excel me-1"></i>Excel
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-clean table-hover mb-0" id="sellerProductsTable">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Seller</th>
                            <th>Product</th>
                            <th>Brand</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Gross Amount</th>
                            <th>Discount</th>
                            <th>Final Amount</th>
                            <th>Total Commission</th>
                            <th>Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($seller_products_data as $index => $row): ?>
                            <tr data-seller-id="<?= (int)$row['seller_id'] ?>" data-qty="<?= (float)$row['total_qty'] ?>" data-gross="<?= (float)$row['total_gross'] ?>" data-discount="<?= (float)$row['total_discount'] ?>" data-sales="<?= (float)$row['total_sales'] ?>" data-commission="<?= (float)$row['total_commission'] ?>" data-orders="<?= (float)$row['order_count'] ?>">
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($row['seller_name']) ?></td>
                                <td><?= htmlspecialchars($row['product_name']) ?></td>
                                <td>
                                    <?php if (!empty($row['brand_name'])): ?>
                                        <span class="fw-semibold" style="color:<?= htmlspecialchars($row['brand_color'] ?: '#6c757d') ?>;"><?= htmlspecialchars($row['brand_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['product_type']) ?></td>
                                <td><?= number_format((float)$row['total_qty']) ?></td>
                                <td>$<?= number_format((float)$row['total_gross'], 2) ?></td>
                                <td>$<?= number_format((float)$row['total_discount'], 2) ?></td>
                                <td>$<?= number_format((float)$row['total_sales'], 2) ?></td>
                                <td>$<?= number_format((float)$row['total_commission'], 2) ?></td>
                                <td><?= number_format((float)$row['order_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($seller_products_data)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted">No seller-product data found for the selected date range.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($seller_products_data)): ?>
                        <tfoot>
                            <tr>
                                <th colspan="5">Total</th>
                                <th id="footerTotalQty"><?= number_format((float)$seller_products_total_qty) ?></th>
                                <th id="footerTotalGross">$<?= number_format((float)$seller_products_total_gross, 2) ?></th>
                                <th id="footerTotalDiscount">$<?= number_format((float)$seller_products_total_discount, 2) ?></th>
                                <th id="footerTotalSales">$<?= number_format((float)$seller_products_total_sales, 2) ?></th>
                                <th id="footerTotalCommission">$<?= number_format((float)$seller_products_total_commission, 2) ?></th>
                                <th id="footerTotalOrders"><?= number_format((float)$seller_products_total_orders) ?></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="card report-card mt-4">
        <div class="card-header report-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2 table-export-actions flex-wrap">
                <h5 class="mb-0 me-2">Commission Transaction List</h5>
                <small class="row-pill">Rows: <?= number_format(count($commission_detail_data)) ?></small>
                <button type="button" id="filterAllBtn" class="row-pill row-pill-btn <?= $selected_payment_status === 'all' ? 'active' : '' ?>" onclick="filterTransactionStatus('all')">Total Orders: <?= number_format((float)$order_status_counts['total_orders']) ?></button>
                <button type="button" id="filterPaidBtn" class="row-pill row-pill-btn <?= $selected_payment_status === 'paid' ? 'active' : '' ?>" onclick="filterTransactionStatus('paid')">Paid: <?= number_format((float)$order_status_counts['paid_orders']) ?></button>
                <button type="button" id="filterUnpaidBtn" class="row-pill row-pill-btn row-pill-unpaid <?= $selected_payment_status === 'unpaid' ? 'active' : '' ?>" onclick="filterTransactionStatus('unpaid')">Unpaid: <?= number_format((float)$order_status_counts['unpaid_orders']) ?></button>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="printTable('commissionDetailTable', 'Commission Transaction List')">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                <button type="button" class="btn btn-sm btn-outline-success" onclick="exportTableToExcel('commissionDetailTable', 'Commission Transaction List', 'transaction_list')">
                    <i class="bi bi-file-earmark-excel me-1"></i>Excel
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-clean table-hover mb-0" id="commissionDetailTable">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Print Date</th>
                            <th>Order Code</th>
                            <th>Seller</th>
                            <th>Product</th>
                            <th>Brand</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Status</th>
                            <th>Line Total</th>
                            <th>Comm. Rate</th>
                            <th>Comm. Amount</th>
                            <th>Total Commission</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($commission_detail_data as $index => $detail): ?>
                            <tr data-status="<?= htmlspecialchars(strtolower((string)$detail['status'])) ?>" class="<?= strtolower((string)$detail['status']) === 'unpaid' ? 'row-unpaid' : '' ?>">
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($detail['print_date']) ?></td>
                                <td><?= htmlspecialchars($detail['order_code'] ?? '') ?></td>
                                <td><?= htmlspecialchars($detail['seller_name']) ?></td>
                                <td><?= htmlspecialchars($detail['product_name']) ?></td>
                                <td>
                                    <?php if (!empty($detail['brand_name'])): ?>
                                        <span class="fw-semibold" style="color:<?= htmlspecialchars($detail['brand_color'] ?: '#6c757d') ?>;"><?= htmlspecialchars($detail['brand_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($detail['product_type']) ?></td>
                                <td><?= number_format((float)$detail['quantity']) ?></td>
                                <td class="status-cell <?= strtolower((string)$detail['status']) === 'unpaid' ? 'status-unpaid' : 'status-paid' ?>"><?= htmlspecialchars(ucfirst((string)$detail['status'])) ?></td>
                                <td>$<?= number_format((float)$detail['line_total'], 2) ?></td>
                                <td><?= number_format((float)$detail['commission_rate'], 2) ?>%</td>
                                <td>$<?= number_format((float)$detail['commission_amount'], 2) ?></td>
                                <td>$<?= number_format((float)$detail['commission_value'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($commission_detail_data)): ?>
                            <tr>
                                <td colspan="13" class="text-center text-muted">No detail data found for the selected date range.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($commission_detail_data)): ?>
                        <tfoot>
                            <tr>
                                <th colspan="8">Total</th>
                                <th><?= number_format((float)$detail_total_qty) ?></th>
                                <th>$<?= number_format((float)$detail_total_line_total, 2) ?></th>
                                <th><?= number_format((float)$detail_avg_rate, 2) ?>%</th>
                                <th>-</th>
                                <th>$<?= number_format((float)$detail_total_commission, 2) ?></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
:root {
    --report-bg: #f4f6f8;
    --report-card: #ffffff;
    --report-border: #e8edf2;
    --report-title: #1f2a37;
    --report-muted: #6b7280;
    --report-accent: #f59e0b;
}

.report-title {
    color: var(--report-title);
    font-weight: 700;
    letter-spacing: 0.01em;
}

.report-subtitle {
    color: var(--report-muted);
    font-size: 0.92rem;
}

.btn-soft {
    border-radius: 10px;
    font-weight: 600;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
}

.report-card {
    background: var(--report-card);
    border: 1px solid var(--report-border);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 6px 20px rgba(30, 41, 59, 0.06);
}

.report-card-header {
    background: linear-gradient(180deg, #f9fafb 0%, #f4f6f9 100%);
    border-bottom: 1px solid var(--report-border);
}

.period-chip,
.row-pill {
    display: inline-block;
    padding: 0.25rem 0.7rem;
    border-radius: 999px;
    font-size: 0.82rem;
    color: #1f5134;
    background: #edf7f0;
    border: 1px solid #cfe7d7;
}

.row-pill-btn {
    border: 1px solid #cfe7d7;
    background: #edf7f0;
    cursor: pointer;
}

.row-pill-btn.active {
    background: #d8f0df;
    border-color: #97d2ac;
    color: #1f7a45;
}

.row-pill-unpaid {
    color: #b91c1c;
    border-color: #fecaca;
    background: #fff1f2;
}

.row-pill-unpaid.active {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #991b1b;
}

.metric-card {
    border: none;
    border-radius: 14px;
    color: #fff;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.16);
}

.metric-primary { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }
.metric-success { background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); }
.metric-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
.metric-info { background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%); }
.metric-paid { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
.metric-unpaid { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }

.metric-label {
    font-size: 0.9rem;
    opacity: 0.92;
    margin-bottom: 0.35rem;
}

.metric-value {
    margin: 0;
    font-weight: 700;
    letter-spacing: 0.01em;
}

.metric-subvalue {
    margin-top: 0.2rem;
    font-size: 0.95rem;
    opacity: 0.95;
    font-weight: 600;
}

.table-clean thead th {
    background: #2f855a;
    color: #f4fff8;
    border-bottom: 0;
    border-color: #276749;
    font-weight: 600;
    white-space: nowrap;
}

.table-clean tbody td {
    border-color: #dbe5f0;
    vertical-align: middle;
}

.table-clean tfoot th {
    background: #f3f4f6;
    color: #111827;
    border-top: 2px solid #d1d5db;
}

.table-clean tbody tr:hover {
    background: #f8fafc;
}

.table-clean tbody tr.seller-group-start td {
    border-top: 2px solid #276749 !important;
}

.table-clean tbody tr.seller-type-row td {
    border-top: 1px dashed #c6e6d4;
}

.badge.bg-purple-soft {
    background: #ede9fe;
}
.badge.text-purple {
    color: #6d28d9;
}
.badge.bg-blue-soft {
    background: #dbeafe;
}
.badge.text-blue {
    color: #1d4ed8;
}

.status-cell {
    font-weight: 700;
}

.status-paid {
    color: #166534;
}

.status-unpaid {
    color: #d00000;
}

.table-clean tbody tr.row-unpaid td {
    background: #ffe8e8 !important;
    border-color: #f5b5b5;
}

.quick-filter-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.quick-filter-btn {
    border: 1px solid #2f855a;
    color: #2f855a;
    background: #fff;
    border-radius: 8px;
    padding: 0.38rem 0.85rem;
    font-weight: 600;
    line-height: 1.2;
}

.quick-filter-btn:hover {
    border-color: #276749;
    color: #276749;
    background: #edf7f0;
}

.quick-filter-btn.active {
    background: #2f855a;
    color: #fff;
    border-color: #2f855a;
}

@media print {
    .admin-sidebar, .admin-topbar, .btn, form {
        display: none !important;
    }
    .admin-content {
        margin-left: 0 !important;
        width: 100% !important;
    }
    .card {
        border: 1px solid #ddd !important;
        box-shadow: none !important;
    }
}

@media (max-width: 768px) {
    .report-topbar { align-items: flex-start !important; }
    .report-actions { width: 100%; }
    .report-actions .btn { flex: 1; }
    .table-export-actions {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
    }
}
</style>

<script>
const reportPeriod = <?= json_encode($report_period_label) ?>;
const reportDateFrom = <?= json_encode($date_from) ?>;
const reportDateTo = <?= json_encode($date_to) ?>;
const BASE_URL = <?= json_encode($BASE_URL) ?>;
const companyName = <?= json_encode($company_name) ?>;
const logoUrl = <?= json_encode($logo_url) ?>;
const selectedPaymentStatus = <?= json_encode($selected_payment_status) ?>;

function getTableClone(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return null;
    const clone = table.cloneNode(true);
    clone.querySelectorAll('button').forEach((el) => el.remove());
    return clone;
}

function tableToStyledDocument(title, tableElement) {
    let logoHtml = '';
    if (logoUrl) {
        logoHtml = `<img src="${logoUrl}" alt="Company Logo" style="height: 50px; margin-right: 12px; vertical-align: middle;">`;
    }
    
    const headerHtml = logoHtml ? `
        <div style="display: flex; align-items: center; margin-bottom: 12px;">
            ${logoHtml}
            <div>
                <div style="font-weight: 700; font-size: 18px; color: #000000; margin: 0;">${companyName}</div>
                <div style="font-size: 12px; color: #000000; margin: 0;">${title}</div>
            </div>
        </div>
    ` : `<div style="font-weight: 700; font-size: 18px; color: #000000; margin-bottom: 6px;">${companyName || title}</div>`;

    return `<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>${title}</title>
    <style>
        @page { size: landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Khmer', sans-serif; margin: 0; color: #000000; }
        .report-header { margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #000000; }
        .report-period { margin-top: 4px; color: #000000; font-size: 12px; }
        .table-wrap { border: 1px solid #000000; border-radius: 6px; overflow: hidden; }
        table { border-collapse: collapse; width: 100%; font-size: 11px; table-layout: auto; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th {
            background: #eef2ff;
            color: #000000;
            text-align: left;
            font-weight: 700;
            padding: 6px 7px;
            border: 1px solid #000000;
            white-space: normal;
            line-height: 1.25;
        }
        td {
            padding: 5px 7px;
            border: 1px solid #000000;
            vertical-align: top;
            white-space: normal;
            word-break: break-word;
            line-height: 1.3;
        }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tfoot th { background: #e5e7eb; color: #000000; border: 1px solid #000000; }
        .num { text-align: right; white-space: nowrap; }
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="report-header">
        ${headerHtml}
        <div class="report-period">Period: ${reportPeriod}</div>
    </div>
    <div class="table-wrap">${tableElement.outerHTML}</div>
</body>
</html>`;
}

function printTable(tableId, title) {
    const table = getTableClone(tableId);
    if (!table) return;

    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    iframe.setAttribute('aria-hidden', 'true');
    document.body.appendChild(iframe);

    const doc = iframe.contentWindow?.document;
    if (!doc || !iframe.contentWindow) {
        document.body.removeChild(iframe);
        return;
    }

    doc.open();
    doc.write(tableToStyledDocument(title, table));
    doc.close();

    const cleanup = () => {
        setTimeout(() => {
            if (iframe.parentNode) {
                iframe.parentNode.removeChild(iframe);
            }
        }, 800);
    };

    iframe.onload = () => {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
        cleanup();
    };
}

function exportTableToExcel(tableId, title, fileSuffix) {
    const table = getTableClone(tableId);
    if (!table) return;

    const html = tableToStyledDocument(title, table);
    const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `commission_${fileSuffix}_${reportDateFrom}_to_${reportDateTo}.xls`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function filterTransactionStatus(status) {
    const table = document.getElementById('commissionDetailTable');
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr[data-status]');
    rows.forEach((row) => {
        const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
        const show = status === 'all' || rowStatus === status;
        row.style.display = show ? '' : 'none';
    });

    const allBtn = document.getElementById('filterAllBtn');
    const paidBtn = document.getElementById('filterPaidBtn');
    const unpaidBtn = document.getElementById('filterUnpaidBtn');
    [allBtn, paidBtn, unpaidBtn].forEach((btn) => btn && btn.classList.remove('active'));

    if (status === 'paid' && paidBtn) paidBtn.classList.add('active');
    else if (status === 'unpaid' && unpaidBtn) unpaidBtn.classList.add('active');
    else if (allBtn) allBtn.classList.add('active');
}

function filterSellerProducts(sellerId) {
    const table = document.getElementById('sellerProductsTable');
    const rowsCount = document.getElementById('sellerProductsRowsCount');
    if (!table) return;

    const targetSellerId = String(sellerId || '0');
    const rows = table.querySelectorAll('tbody tr[data-seller-id]');
    let visibleRows = 0;
    let totalQty = 0, totalGross = 0, totalDiscount = 0, totalSales = 0, totalCommission = 0, totalOrders = 0;

    rows.forEach((row) => {
        const rowSellerId = row.getAttribute('data-seller-id') || '0';
        const show = targetSellerId === '0' || rowSellerId === targetSellerId;
        row.style.display = show ? '' : 'none';
        
        if (show) {
            visibleRows += 1;
            totalQty += parseFloat(row.getAttribute('data-qty') || '0');
            totalGross += parseFloat(row.getAttribute('data-gross') || '0');
            totalDiscount += parseFloat(row.getAttribute('data-discount') || '0');
            totalSales += parseFloat(row.getAttribute('data-sales') || '0');
            totalCommission += parseFloat(row.getAttribute('data-commission') || '0');
            totalOrders += parseFloat(row.getAttribute('data-orders') || '0');
        }
    });

    if (rowsCount) {
        rowsCount.textContent = `Rows: ${visibleRows.toLocaleString()}`;
    }

    // Update footer totals
    const footers = {
        'footerTotalQty': totalQty,
        'footerTotalGross': totalGross,
        'footerTotalDiscount': totalDiscount,
        'footerTotalSales': totalSales,
        'footerTotalCommission': totalCommission,
        'footerTotalOrders': totalOrders
    };

    for (const [id, value] of Object.entries(footers)) {
        const elem = document.getElementById(id);
        if (!elem) continue;
        
        if (id === 'footerTotalQty' || id === 'footerTotalOrders') {
            elem.textContent = value.toLocaleString('en-US', { maximumFractionDigits: 0 });
        } else {
            elem.textContent = '$' + value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }
}

function applyQuickFilter(mode) {
    const form = document.getElementById('filterForm');
    const quickFilterInput = document.getElementById('quick_filter');
    if (!form || !quickFilterInput) return;
    quickFilterInput.value = mode;
    form.submit();
}

function clearQuickFilter() {
    const quickFilterInput = document.getElementById('quick_filter');
    if (!quickFilterInput) return;
    quickFilterInput.value = '';
}

document.addEventListener('DOMContentLoaded', () => {
    const sellerSelect = document.getElementById('seller_filter_id');
    if (sellerSelect) {
        filterSellerProducts(sellerSelect.value);
    }
    filterTransactionStatus(selectedPaymentStatus || 'all');
});
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
