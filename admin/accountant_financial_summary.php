<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'financial_summary.view');

$pdo = get_db_connection();

// Get month filter
$selected_month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}

$month_start = $selected_month . '-01';
$from = date('Y-m-01', strtotime($month_start));
$to = date('Y-m-t', strtotime($month_start));
$table_from = $_GET['table_from'] ?? $from;
$table_to = $_GET['table_to'] ?? $to;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $table_from)) {
    $table_from = $from;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $table_to)) {
    $table_to = $to;
}
if (strtotime($table_from) > strtotime($table_to)) {
    $table_from = $from;
    $table_to = $to;
}

// Build date ranges for monthly comparison
$current_from = $from;
$current_to = $to;
$prev_from = date('Y-m-01', strtotime($from . ' -1 month'));
$prev_to = date('Y-m-t', strtotime($from . ' -1 month'));
$title = "Monthly Financial Summary - " . date('F Y', strtotime($from));

// Get current period financial data
$stmt = $pdo->prepare('
    SELECT 
        COUNT(DISTINCT o.id) as total_orders,
        COUNT(DISTINCT CASE WHEN o.status = "paid" THEN o.id END) as paid_orders,
        COUNT(DISTINCT CASE WHEN o.status = "unpaid" THEN o.id END) as unpaid_orders,
        COUNT(DISTINCT CASE WHEN o.is_cancelled = 1 THEN o.id END) as cancelled_orders,
        COALESCE(SUM(o.total_amount), 0) as gross_revenue,
        COALESCE(SUM(CASE WHEN o.status = "paid" THEN o.total_amount ELSE 0 END), 0) as paid_revenue,
        COALESCE(SUM(CASE WHEN o.status = "unpaid" THEN o.total_amount ELSE 0 END), 0) as unpaid_revenue,
        COALESCE(SUM(o.discount), 0) as total_discount,
        COALESCE(AVG(o.total_amount), 0) as avg_order_value,
        COALESCE(SUM(COALESCE(oi_agg.total_items, 0)), 0) as total_items_sold,
        COUNT(DISTINCT oi.product_id) as unique_products_sold,
        COUNT(DISTINCT o.seller_id) as active_sellers
    FROM orders o
    INNER JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    LEFT JOIN (
        SELECT 
            oi.order_id,
            SUM(
                CASE
                    WHEN COALESCE(p.product_type, "normal") = "set"
                        THEN oi.quantity * COALESCE(psi.quantity, 1)
                    ELSE oi.quantity
                END
            ) AS total_items
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN product_sets ps
            ON ps.set_name = p.name
           AND COALESCE(p.product_type, "normal") = "set"
        LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
        GROUP BY oi.order_id
    ) oi_agg ON o.id = oi_agg.order_id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ?
');
$stmt->execute([$current_from, $current_to]);
$current_data = $stmt->fetch();

// Get previous period data for comparison
$stmt = $pdo->prepare('
    SELECT 
        COUNT(DISTINCT o.id) as total_orders,
        COALESCE(SUM(o.total_amount), 0) as gross_revenue,
        COALESCE(SUM(COALESCE(oi_agg.total_items, 0)), 0) as total_items_sold
    FROM orders o
    INNER JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    LEFT JOIN (
        SELECT 
            oi.order_id,
            SUM(
                CASE
                    WHEN COALESCE(p.product_type, "normal") = "set"
                        THEN oi.quantity * COALESCE(psi.quantity, 1)
                    ELSE oi.quantity
                END
            ) AS total_items
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN product_sets ps
            ON ps.set_name = p.name
           AND COALESCE(p.product_type, "normal") = "set"
        LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
        GROUP BY oi.order_id
    ) oi_agg ON o.id = oi_agg.order_id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ?
');
$stmt->execute([$prev_from, $prev_to]);
$prev_data = $stmt->fetch();

// Get daily breakdown for current period
$daily_breakdown = [];
$stmt = $pdo->prepare('
    SELECT 
        DATE(pj.printed_at) as date,
        COUNT(DISTINCT o.id) as orders,
        COALESCE(SUM(o.total_amount), 0) as revenue,
        COALESCE(SUM(CASE WHEN o.status = "paid" THEN o.total_amount ELSE 0 END), 0) as paid_revenue,
        COALESCE(SUM(CASE WHEN o.status = "unpaid" THEN o.total_amount ELSE 0 END), 0) as unpaid_revenue,
        COALESCE(SUM(o.discount), 0) as discount,
        COALESCE(SUM(COALESCE(oi_agg.total_items, 0)), 0) as items_sold
    FROM orders o
    INNER JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    LEFT JOIN (
        SELECT 
            oi.order_id,
            SUM(
                CASE
                    WHEN COALESCE(p.product_type, "normal") = "set"
                        THEN oi.quantity * COALESCE(psi.quantity, 1)
                    ELSE oi.quantity
                END
            ) AS total_items
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN product_sets ps
            ON ps.set_name = p.name
           AND COALESCE(p.product_type, "normal") = "set"
        LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
        GROUP BY oi.order_id
    ) oi_agg ON o.id = oi_agg.order_id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ?
      AND o.is_cancelled = 0
      AND o.is_returned = 0
    GROUP BY DATE(pj.printed_at)
    ORDER BY date ASC
');
$stmt->execute([$table_from, $table_to]);
$daily_breakdown = $stmt->fetchAll();

$daily_totals = [
    'orders' => 0,
    'revenue' => 0.0,
    'paid_revenue' => 0.0,
    'unpaid_revenue' => 0.0,
    'discount' => 0.0,
    'items_sold' => 0.0,
];
foreach ($daily_breakdown as $day) {
    $daily_totals['orders'] += (int)($day['orders'] ?? 0);
    $daily_totals['revenue'] += (float)($day['revenue'] ?? 0);
    $daily_totals['paid_revenue'] += (float)($day['paid_revenue'] ?? 0);
    $daily_totals['unpaid_revenue'] += (float)($day['unpaid_revenue'] ?? 0);
    $daily_totals['discount'] += (float)($day['discount'] ?? 0);
    $daily_totals['items_sold'] += (float)($day['items_sold'] ?? 0);
}

// Get payment method summary
$payment_method_summary = [];
try {
    $stmt = $pdo->prepare('
        SELECT
            COALESCE(NULLIF(TRIM(o.payment_method), ""), "អត់ទាន់ទូទាត់") AS payment_method,
            COUNT(DISTINCT o.id) AS order_count,
            COALESCE(SUM(o.total_amount), 0) AS total_amount,
            ROUND(
                COUNT(DISTINCT o.id) * 100.0 /
                NULLIF((
                    SELECT COUNT(DISTINCT o2.id)
                    FROM orders o2
                    INNER JOIN (
                        SELECT order_id, MAX(printed_at) AS printed_at
                        FROM print_jobs
                        GROUP BY order_id
                    ) pj2 ON pj2.order_id = o2.id
                    WHERE DATE(pj2.printed_at) BETWEEN ? AND ?
                      AND o2.is_cancelled = 0
                      AND o2.is_returned = 0
                ), 0),
                2
            ) AS percentage
        FROM orders o
        INNER JOIN (
            SELECT order_id, MAX(printed_at) AS printed_at
            FROM print_jobs
            GROUP BY order_id
        ) pj ON pj.order_id = o.id
        WHERE DATE(pj.printed_at) BETWEEN ? AND ?
          AND o.is_cancelled = 0
          AND o.is_returned = 0
        GROUP BY COALESCE(NULLIF(TRIM(o.payment_method), ""), "អត់ទាន់ទូទាត់")
        ORDER BY total_amount DESC
    ');
    $stmt->execute([$table_from, $table_to, $table_from, $table_to]);
    $payment_method_summary = $stmt->fetchAll();
} catch (Exception $e) {
    // Continue without payment method summary
}
$payment_method_totals = [
    'orders' => 0,
    'amount' => 0.0,
];
foreach ($payment_method_summary as $method) {
    $payment_method_totals['orders'] += (int)($method['order_count'] ?? 0);
    $payment_method_totals['amount'] += (float)($method['total_amount'] ?? 0);
}

// Get payment method breakdown by day.
$payment_method_breakdown = [];
$payment_method_breakdown_by_day = [];
$payment_method_breakdown_day_totals = [];
$payment_method_breakdown_totals = [
    'orders' => 0,
    'amount' => 0.0,
    'items' => 0.0,
];
try {
    $stmt = $pdo->prepare('
        SELECT
            DATE(pj.printed_at) AS report_date,
            COALESCE(NULLIF(TRIM(o.payment_method), ""), "អត់ទាន់ទូទាត់") AS payment_method,
            COUNT(DISTINCT o.id) AS order_count,
            COALESCE(SUM(o.total_amount), 0) AS total_amount,
            COALESCE(SUM(COALESCE(oi_agg.total_items, 0)), 0) AS items_sold
        FROM orders o
        INNER JOIN (
            SELECT order_id, MAX(printed_at) AS printed_at
            FROM print_jobs
            GROUP BY order_id
        ) pj ON pj.order_id = o.id
        LEFT JOIN (
            SELECT
                oi.order_id,
                SUM(
                    CASE
                        WHEN COALESCE(p.product_type, "normal") = "set"
                            THEN oi.quantity * COALESCE(psi.quantity, 1)
                        ELSE oi.quantity
                    END
                ) AS total_items
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            LEFT JOIN product_sets ps
                ON ps.set_name = p.name
               AND COALESCE(p.product_type, "normal") = "set"
            LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
            GROUP BY oi.order_id
        ) oi_agg ON o.id = oi_agg.order_id
        WHERE DATE(pj.printed_at) BETWEEN ? AND ?
          AND o.is_cancelled = 0
          AND o.is_returned = 0
        GROUP BY DATE(pj.printed_at), COALESCE(NULLIF(TRIM(o.payment_method), ""), "អត់ទាន់ទូទាត់")
        ORDER BY report_date ASC, total_amount DESC
    ');
    $stmt->execute([$table_from, $table_to]);
    $payment_method_breakdown = $stmt->fetchAll();

    foreach ($payment_method_breakdown as $row) {
        $dateKey = (string)($row['report_date'] ?? '');
        if (!isset($payment_method_breakdown_day_totals[$dateKey])) {
            $payment_method_breakdown_day_totals[$dateKey] = [
                'orders' => 0,
                'amount' => 0.0,
                'items' => 0.0,
            ];
        }
        $payment_method_breakdown_day_totals[$dateKey]['orders'] += (int)($row['order_count'] ?? 0);
        $payment_method_breakdown_day_totals[$dateKey]['amount'] += (float)($row['total_amount'] ?? 0);
        $payment_method_breakdown_day_totals[$dateKey]['items'] += (float)($row['items_sold'] ?? 0);
    }

    foreach ($payment_method_breakdown as $index => $row) {
        $dateKey = (string)($row['report_date'] ?? '');
        $dayOrders = (int)($payment_method_breakdown_day_totals[$dateKey]['orders'] ?? 0);
        $payment_method_breakdown[$index]['percentage'] = $dayOrders > 0
            ? ((int)($row['order_count'] ?? 0) / $dayOrders) * 100
            : 0;
        $payment_method_breakdown_totals['orders'] += (int)($row['order_count'] ?? 0);
        $payment_method_breakdown_totals['amount'] += (float)($row['total_amount'] ?? 0);
        $payment_method_breakdown_totals['items'] += (float)($row['items_sold'] ?? 0);
    }

    foreach ($payment_method_breakdown as $row) {
        $dateKey = (string)($row['report_date'] ?? '');
        $payment_method_breakdown_by_day[$dateKey][] = $row;
    }
} catch (Exception $e) {
    // Continue without payment method breakdown
}

// Get order detail list for the selected table date range.
$order_details = [];
$order_detail_totals = [
    'orders' => 0,
    'items' => 0.0,
    'gross_amount' => 0.0,
    'discount' => 0.0,
    'total_amount' => 0.0,
];
$order_detail_counts = [
    'all' => 0,
    'paid' => 0,
    'unpaid' => 0,
    'cancelled' => 0,
    'returned' => 0,
];
try {
    $stmt = $pdo->prepare('
        SELECT
            o.id,
            o.order_code,
            o.customer_name,
            o.phone,
            o.status,
            o.is_paid,
            o.is_cancelled,
            o.is_returned,
            o.payment_method,
            o.discount,
            o.total_amount,
            pj.printed_at,
            u.name AS seller_name,
            dt.name AS delivery_type_name,
            dc.label AS delivery_cost_label,
            COALESCE(oi_agg.item_count, 0) AS item_count,
            COALESCE(oi_agg.total_items, 0) AS total_items
        FROM orders o
        INNER JOIN (
            SELECT order_id, MAX(printed_at) AS printed_at
            FROM print_jobs
            GROUP BY order_id
        ) pj ON pj.order_id = o.id
        LEFT JOIN users u ON u.id = o.seller_id
        LEFT JOIN delivery_types dt ON dt.id = o.delivery_type_id
        LEFT JOIN delivery_costs dc ON dc.id = o.delivery_cost_id
        LEFT JOIN (
            SELECT
                oi.order_id,
                COUNT(*) AS item_count,
                SUM(
                    CASE
                        WHEN COALESCE(p.product_type, "normal") = "set"
                            THEN oi.quantity * COALESCE(psi.quantity, 1)
                        ELSE oi.quantity
                    END
                ) AS total_items
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            LEFT JOIN product_sets ps
                ON ps.set_name = p.name
               AND COALESCE(p.product_type, "normal") = "set"
            LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
            GROUP BY oi.order_id
        ) oi_agg ON oi_agg.order_id = o.id
        WHERE DATE(pj.printed_at) BETWEEN ? AND ?
        ORDER BY pj.printed_at ASC, o.id ASC
    ');
    $stmt->execute([$table_from, $table_to]);
    $order_details = $stmt->fetchAll();
} catch (Exception $e) {
    $order_details = [];
}
foreach ($order_details as $order) {
    $isCancelled = (int)($order['is_cancelled'] ?? 0) === 1;
    $isReturned = (int)($order['is_returned'] ?? 0) === 1;
    $status = strtolower((string)($order['status'] ?? ''));
    $order_detail_counts['all']++;
    if ($isCancelled) {
        $order_detail_counts['cancelled']++;
    } elseif ($isReturned) {
        $order_detail_counts['returned']++;
    } elseif ($status === 'paid' || (int)($order['is_paid'] ?? 0) === 1) {
        $order_detail_counts['paid']++;
    } else {
        $order_detail_counts['unpaid']++;
    }

    $order_detail_totals['orders']++;
    $order_detail_totals['items'] += (float)($order['total_items'] ?? 0);
    $order_detail_totals['gross_amount'] += (float)($order['total_amount'] ?? 0) + (float)($order['discount'] ?? 0);
    $order_detail_totals['discount'] += (float)($order['discount'] ?? 0);
    $order_detail_totals['total_amount'] += (float)($order['total_amount'] ?? 0);
}

// Calculate growth percentages
$order_growth = $prev_data['total_orders'] > 0 ? (($current_data['total_orders'] - $prev_data['total_orders']) / $prev_data['total_orders']) * 100 : 0;
$revenue_growth = $prev_data['gross_revenue'] > 0 ? (($current_data['gross_revenue'] - $prev_data['gross_revenue']) / $prev_data['gross_revenue']) * 100 : 0;
$items_growth = $prev_data['total_items_sold'] > 0 ? (($current_data['total_items_sold'] - $prev_data['total_items_sold']) / $prev_data['total_items_sold']) * 100 : 0;

// Financial metrics
$net_revenue = $current_data['gross_revenue'] - $current_data['total_discount'];
$collection_rate = $current_data['gross_revenue'] > 0 ? ($current_data['paid_revenue'] / $current_data['gross_revenue']) * 100 : 0;
$cancellation_rate = $current_data['total_orders'] > 0 ? ($current_data['cancelled_orders'] / $current_data['total_orders']) * 100 : 0;

$reportLogoUrl = '';
$reportLogoPath = '';
try {
    // Auto source from invoice_settings (logo_id), with helper fallback logic.
    if (function_exists('get_invoice_logo')) {
        $invoiceLogo = get_invoice_logo($pdo);
        if (!empty($invoiceLogo['file_path'])) {
            $relativeLogoPath = ltrim((string)$invoiceLogo['file_path'], '/');
            $reportLogoUrl = rtrim((string)$BASE_URL, '/') . '/' . $relativeLogoPath;
            $candidatePath = realpath(__DIR__ . '/../' . $relativeLogoPath);
            if ($candidatePath && is_file($candidatePath)) {
                $reportLogoPath = $candidatePath;
            }
        }
    }
} catch (Throwable $e) {}
if ($reportLogoUrl === '') {
    $reportLogoUrl = rtrim((string)$BASE_URL, '/') . '/public/image.png';
}
if ($reportLogoPath === '') {
    $candidatePath = realpath(__DIR__ . '/../public/image.png');
    if ($candidatePath && is_file($candidatePath)) {
        $reportLogoPath = $candidatePath;
    }
}
$reportLogoDataUrl = '';
if ($reportLogoPath !== '') {
    $mimeType = function_exists('mime_content_type') ? mime_content_type($reportLogoPath) : '';
    if (!is_string($mimeType) || $mimeType === '') {
        $extension = strtolower(pathinfo($reportLogoPath, PATHINFO_EXTENSION));
        $mimeType = $extension === 'jpg' || $extension === 'jpeg' ? 'image/jpeg' : 'image/png';
    }
    $imageData = file_get_contents($reportLogoPath);
    if ($imageData !== false) {
        $reportLogoDataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
    }
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0"><?= htmlspecialchars($title) ?></h1>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-success btn-lg" onclick="printAllTables()">
                <i class="bi bi-printer me-2"></i>Print Report
            </button>
            <button class="btn btn-outline-primary btn-lg" onclick="exportToExcel()">
                <i class="bi bi-file-earmark-excel me-2"></i>Export Excel
            </button>
        </div>
    </div>

    <!-- Report Type and Date Filters -->
    <form method="get" class="card shadow-sm mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label">Select Month</label>
                <input type="month" name="month" class="form-control form-control-lg" value="<?= htmlspecialchars($selected_month) ?>" required>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Table From</label>
                <input type="date" name="table_from" class="form-control form-control-lg" value="<?= htmlspecialchars($table_from) ?>" required>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Table To</label>
                <input type="date" name="table_to" class="form-control form-control-lg" value="<?= htmlspecialchars($table_to) ?>" required>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary btn-lg w-100">Generate</button>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label d-none d-md-block">&nbsp;</label>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="setQuickDate('this_month')">This Month</button>
                    <button type="button" class="btn btn-outline-dark" onclick="setQuickDate('last_month')">Last Month</button>
                </div>
            </div>
        </div>
    </form>

    <!-- Revenue Trend Chart -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Revenue Trend</h5>
        </div>
        <div class="card-body">
            <canvas id="revenueChart" height="100"></canvas>
        </div>
    </div>

    <!-- Payment Method Summary -->
    <?php if (!empty($payment_method_summary)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Payment Method Summary (<?= htmlspecialchars($table_from) ?> to <?= htmlspecialchars($table_to) ?>)</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-success" onclick="printTableById('paymentMethodTable', 'Payment Method Summary')">Print</button>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="exportTableToCsv('paymentMethodTable', 'payment_method_summary_<?= htmlspecialchars($selected_month) ?>')">Excel</button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm" id="paymentMethodTable">
                    <thead>
                        <tr>
                            <th>Payment Method</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payment_method_summary as $method): ?>
                            <tr class="<?= ($method['payment_method'] ?? '') === 'អត់ទាន់ទូទាត់' ? 'table-danger' : '' ?>">
                                <td><?= htmlspecialchars($method['payment_method']) ?></td>
                                <td class="text-end"><?= number_format($method['order_count']) ?></td>
                                <td class="text-end">$<?= number_format($method['total_amount'], 2) ?></td>
                                <td class="text-end"><?= number_format((float)($method['percentage'] ?? 0), 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th>Total</th>
                            <th class="text-end"><?= number_format($payment_method_totals['orders']) ?></th>
                            <th class="text-end">$<?= number_format($payment_method_totals['amount'], 2) ?></th>
                            <th class="text-end">100.0%</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Payment Method Breakdown by Day -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Payment Method Breakdown by Day (<?= htmlspecialchars($table_from) ?> to <?= htmlspecialchars($table_to) ?>)</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-success" onclick="printTableById('paymentMethodBreakdownTable', 'Payment Method Breakdown by Day')">Print</button>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="exportTableToCsv('paymentMethodBreakdownTable', 'payment_method_breakdown_<?= htmlspecialchars($selected_month) ?>')">Excel</button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm" id="paymentMethodBreakdownTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Payment Method</th>
                            <th class="text-end">Qty of Order</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">% of Day</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payment_method_breakdown_by_day)): ?>
                            <tr><td colspan="6" class="text-center py-3">No data</td></tr>
                        <?php else: ?>
                            <?php foreach ($payment_method_breakdown_by_day as $date => $rows): ?>
                                <?php foreach ($rows as $index => $row): ?>
                                    <tr class="<?= ($row['payment_method'] ?? '') === 'អត់ទាន់ទូទាត់' ? 'table-danger' : '' ?>">
                                        <?php if ($index === 0): ?>
                                            <td rowspan="<?= count($rows) + 1 ?>" class="align-middle fw-bold"><?= date('M j, Y', strtotime($date)) ?></td>
                                        <?php endif; ?>
                                        <td><?= htmlspecialchars($row['payment_method']) ?></td>
                                        <td class="text-end"><?= number_format($row['order_count']) ?></td>
                                        <td class="text-end">$<?= number_format($row['total_amount'], 2) ?></td>
                                        <td class="text-end"><?= number_format($row['items_sold']) ?></td>
                                        <td class="text-end"><?= number_format((float)($row['percentage'] ?? 0), 1) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="table-success">
                                    <th>Total</th>
                                    <th class="text-end"><?= number_format($payment_method_breakdown_day_totals[$date]['orders'] ?? 0) ?></th>
                                    <th class="text-end">$<?= number_format($payment_method_breakdown_day_totals[$date]['amount'] ?? 0, 2) ?></th>
                                    <th class="text-end"><?= number_format($payment_method_breakdown_day_totals[$date]['items'] ?? 0) ?></th>
                                    <th class="text-end">100.0%</th>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="2">Grand Total</th>
                            <th class="text-end"><?= number_format($payment_method_breakdown_totals['orders']) ?></th>
                            <th class="text-end">$<?= number_format($payment_method_breakdown_totals['amount'], 2) ?></th>
                            <th class="text-end"><?= number_format($payment_method_breakdown_totals['items']) ?></th>
                            <th class="text-end"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Daily Breakdown Table -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Daily Breakdown (<?= htmlspecialchars($table_from) ?> to <?= htmlspecialchars($table_to) ?>)</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-success" onclick="printTableById('dailyTable', 'Daily Breakdown')">Print</button>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="exportTableToCsv('dailyTable', 'daily_breakdown_<?= htmlspecialchars($selected_month) ?>')">Excel</button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm" id="dailyTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Unpaid</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Total Amount</th>
                            <th class="text-end">Items</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daily_breakdown)): ?>
                            <tr><td colspan="8" class="text-center py-3">No data</td></tr>
                        <?php else: ?>
                            <?php foreach ($daily_breakdown as $day): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($day['date'])) ?></td>
                                    <td class="text-end"><?= number_format($day['orders']) ?></td>
                                    <td class="text-end">$<?= number_format((float)$day['revenue'] + (float)$day['discount'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($day['paid_revenue'], 2) ?></td>
                                    <td class="text-end <?= (float)($day['unpaid_revenue'] ?? 0) > 0 ? 'text-danger fw-semibold' : '' ?>">
                                        $<?= number_format($day['unpaid_revenue'], 2) ?>
                                    </td>
                                    <td class="text-end">$<?= number_format($day['discount'], 2) ?></td>
                                    <td class="text-end">$<?= number_format((float)$day['revenue'], 2) ?></td>
                                    <td class="text-end"><?= number_format($day['items_sold']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th>Total</th>
                            <th class="text-end"><?= number_format($daily_totals['orders']) ?></th>
                            <th class="text-end">$<?= number_format($daily_totals['revenue'] + $daily_totals['discount'], 2) ?></th>
                            <th class="text-end">$<?= number_format($daily_totals['paid_revenue'], 2) ?></th>
                            <th class="text-end <?= (float)($daily_totals['unpaid_revenue'] ?? 0) > 0 ? 'text-danger fw-semibold' : '' ?>">
                                $<?= number_format($daily_totals['unpaid_revenue'], 2) ?>
                            </th>
                            <th class="text-end">$<?= number_format($daily_totals['discount'], 2) ?></th>
                            <th class="text-end">$<?= number_format($daily_totals['revenue'], 2) ?></th>
                            <th class="text-end"><?= number_format($daily_totals['items_sold']) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Order Detail Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Order Details (<?= htmlspecialchars($table_from) ?> to <?= htmlspecialchars($table_to) ?>)</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-success" onclick="printTableById('orderDetailTable', 'Order Details')">Print</button>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="exportTableToCsv('orderDetailTable', 'order_details_<?= htmlspecialchars($selected_month) ?>')">Excel</button>
            </div>
        </div>
        <div class="card-body">
            <div class="order-detail-filter-bar mb-3">
                <div class="btn-group order-status-tabs" role="group" aria-label="Order detail status filter">
                    <button type="button" class="btn btn-outline-secondary active" data-order-filter="all">All</button>
                    <button type="button" class="btn btn-success" data-order-filter="paid">Paid</button>
                    <button type="button" class="btn btn-warning" data-order-filter="unpaid">Unpaid</button>
                    <button type="button" class="btn btn-danger" data-order-filter="cancelled">Cancelled</button>
                    <button type="button" class="btn btn-secondary" data-order-filter="returned">Returned</button>
                </div>
                <div class="order-status-counts">
                    <span class="badge rounded-3 text-bg-success">Paid: <?= number_format($order_detail_counts['paid']) ?></span>
                    <span class="badge rounded-3 text-bg-warning text-dark">Unpaid: <?= number_format($order_detail_counts['unpaid']) ?></span>
                    <span class="badge rounded-3 text-bg-danger">Cancel: <?= number_format($order_detail_counts['cancelled']) ?></span>
                    <span class="badge rounded-3 text-bg-warning text-dark">Return: <?= number_format($order_detail_counts['returned']) ?></span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm" id="orderDetailTable">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Printed Date</th>
                            <th>Order Code</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Seller</th>
                            <th>Delivery</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Gross</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Total Amount</th>
                            <th class="text-center">Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($order_details)): ?>
                            <tr id="orderDetailEmptyRow"><td colspan="14" class="text-center py-3">No data</td></tr>
                        <?php else: ?>
                            <?php foreach ($order_details as $index => $order): ?>
                                <?php
                                    $isCancelled = (int)($order['is_cancelled'] ?? 0) === 1;
                                    $isReturned = (int)($order['is_returned'] ?? 0) === 1;
                                    $rawStatus = strtolower((string)($order['status'] ?? ''));
                                    $rowStatus = $isCancelled ? 'cancelled' : ($isReturned ? 'returned' : (($rawStatus === 'paid' || (int)($order['is_paid'] ?? 0) === 1) ? 'paid' : 'unpaid'));
                                    $statusLabel = ucfirst($rowStatus);
                                    $grossAmount = (float)($order['total_amount'] ?? 0) + (float)($order['discount'] ?? 0);
                                    $deliveryLabel = trim((string)($order['delivery_type_name'] ?? ''));
                                    $deliveryCost = trim((string)($order['delivery_cost_label'] ?? ''));
                                    if ($deliveryCost !== '') {
                                        $deliveryLabel = $deliveryLabel !== '' ? ($deliveryLabel . ' (' . $deliveryCost . ')') : $deliveryCost;
                                    }
                                    $paymentLabel = $rowStatus === 'unpaid'
                                        ? 'អត់ទាន់ទូទាត់'
                                        : trim((string)($order['payment_method'] ?? ''));
                                    if ($paymentLabel === '') {
                                        $paymentLabel = '-';
                                    }
                                ?>
                                <tr class="<?= $isCancelled || $isReturned ? 'table-danger' : ($rowStatus === 'unpaid' ? 'table-warning' : '') ?>" data-order-detail-row="1" data-status="<?= htmlspecialchars($rowStatus) ?>" data-items="<?= htmlspecialchars((string)((float)($order['total_items'] ?? 0))) ?>" data-gross="<?= htmlspecialchars((string)$grossAmount) ?>" data-discount="<?= htmlspecialchars((string)((float)($order['discount'] ?? 0))) ?>" data-total="<?= htmlspecialchars((string)((float)($order['total_amount'] ?? 0))) ?>">
                                    <td><?= number_format($index + 1) ?></td>
                                    <td><?= htmlspecialchars(date('M j, Y H:i', strtotime((string)$order['printed_at']))) ?></td>
                                    <td><?= htmlspecialchars((string)($order['order_code'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($order['customer_name'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($order['phone'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($order['seller_name'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($deliveryLabel !== '' ? $deliveryLabel : '-') ?></td>
                                    <td><?= htmlspecialchars($paymentLabel) ?></td>
                                    <td><?= htmlspecialchars($statusLabel) ?></td>
                                    <td class="text-end"><?= number_format((float)($order['total_items'] ?? 0)) ?></td>
                                    <td class="text-end">$<?= number_format($grossAmount, 2) ?></td>
                                    <td class="text-end">$<?= number_format((float)($order['discount'] ?? 0), 2) ?></td>
                                    <td class="text-end">$<?= number_format((float)($order['total_amount'] ?? 0), 2) ?></td>
                                    <td class="text-center">
                                        <a href="<?= htmlspecialchars($BASE_URL) ?>/receipt.php?id=<?= (int)($order['id'] ?? 0) ?>" target="_blank" class="btn btn-outline-primary btn-sm" title="View Receipt">
                                            <i class="bi bi-receipt"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="orderDetailEmptyRow" style="display:none;"><td colspan="14" class="text-center py-3">No orders for selected status</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="9">Total</th>
                            <th class="text-end" id="orderDetailTotalItems"><?= number_format($order_detail_totals['items']) ?></th>
                            <th class="text-end" id="orderDetailTotalGross">$<?= number_format($order_detail_totals['gross_amount'], 2) ?></th>
                            <th class="text-end" id="orderDetailTotalDiscount">$<?= number_format($order_detail_totals['discount'], 2) ?></th>
                            <th class="text-end" id="orderDetailTotalAmount">$<?= number_format($order_detail_totals['total_amount'], 2) ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const REPORT_LOGO_URL = (() => {
    const embedded = <?= json_encode($reportLogoDataUrl) ?> || '';
    if (embedded) return embedded;
    const raw = <?= json_encode($reportLogoUrl) ?> || '';
    if (!raw) return '';
    if (/^https?:\/\//i.test(raw) || raw.startsWith('data:')) return raw;
    const normalized = raw.startsWith('/') ? raw : ('/' + raw);
    return window.location.origin + normalized;
})();
document.addEventListener('DOMContentLoaded', function() {
    // Revenue Trend Chart
    const dailyData = <?= json_encode($daily_breakdown) ?>;
    
    const revenueCtx = document.getElementById('revenueChart').getContext('2d');
    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: dailyData.map(d => new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
            datasets: [{
                label: 'Total Revenue',
                data: dailyData.map(d => d.revenue),
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Paid Revenue',
                data: dailyData.map(d => d.paid_revenue),
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Orders',
                data: dailyData.map(d => d.orders),
                borderColor: '#ffc107',
                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Revenue ($)'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Orders'
                    },
                    grid: {
                        drawOnChartArea: false,
                    }
                }
            }
        }
    });

    initOrderDetailFilters();
});

function initOrderDetailFilters() {
    const buttons = document.querySelectorAll('[data-order-filter]');
    if (!buttons.length) return;

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            buttons.forEach((b) => b.classList.remove('active'));
            button.classList.add('active');
            filterOrderDetails(button.dataset.orderFilter || 'all');
        });
    });

    filterOrderDetails('all');
}

function filterOrderDetails(status) {
    const rows = Array.from(document.querySelectorAll('#orderDetailTable tbody tr[data-order-detail-row="1"]'));
    const emptyRow = document.getElementById('orderDetailEmptyRow');
    const totals = {
        items: 0,
        gross: 0,
        discount: 0,
        amount: 0,
        visible: 0
    };

    rows.forEach((row) => {
        const rowStatus = row.dataset.status || '';
        const show = status === 'all' || rowStatus === status;
        row.style.display = show ? '' : 'none';
        if (!show) return;

        totals.visible++;
        totals.items += parseFloat(row.dataset.items || '0') || 0;
        totals.gross += parseFloat(row.dataset.gross || '0') || 0;
        totals.discount += parseFloat(row.dataset.discount || '0') || 0;
        totals.amount += parseFloat(row.dataset.total || '0') || 0;
    });

    if (emptyRow) {
        emptyRow.style.display = rows.length === 0 || totals.visible === 0 ? '' : 'none';
        const cell = emptyRow.querySelector('td');
        if (cell) {
            cell.textContent = rows.length === 0 ? 'No data' : 'No orders for selected status';
        }
    }

    setOrderDetailTotal('orderDetailTotalItems', totals.items, false);
    setOrderDetailTotal('orderDetailTotalGross', totals.gross, true);
    setOrderDetailTotal('orderDetailTotalDiscount', totals.discount, true);
    setOrderDetailTotal('orderDetailTotalAmount', totals.amount, true);
}

function setOrderDetailTotal(id, value, isMoney) {
    const el = document.getElementById(id);
    if (!el) return;
    const formatted = isMoney
        ? '$' + value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : value.toLocaleString(undefined, { maximumFractionDigits: 0 });
    el.textContent = formatted;
}

function setQuickDate(range) {
    const form = document.querySelector('form');
    const formatMonthLocal = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        return `${year}-${month}`;
    };
    const formatDateLocal = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    const monthInput = form.querySelector('input[name="month"]');
    const tableFromInput = form.querySelector('input[name="table_from"]');
    const tableToInput = form.querySelector('input[name="table_to"]');

    if (range === 'this_month') {
        const today = new Date();
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        monthInput.value = formatMonthLocal(today);
        tableFromInput.value = formatDateLocal(firstDay);
        tableToInput.value = formatDateLocal(lastDay);
    } else if (range === 'last_month') {
        const today = new Date();
        const firstDayLastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        const lastDayLastMonth = new Date(today.getFullYear(), today.getMonth(), 0);
        monthInput.value = formatMonthLocal(firstDayLastMonth);
        tableFromInput.value = formatDateLocal(firstDayLastMonth);
        tableToInput.value = formatDateLocal(lastDayLastMonth);
    }

    form.submit();
}

function exportToExcel() {
    const reportHtml = buildCombinedTablesDocument({ forExcel: true });
    const blob = new Blob(['\ufeff', reportHtml], { type: 'application/vnd.ms-excel' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'financial_summary_' + document.querySelector('input[name="month"]').value + '.xls';
    a.click();
    window.URL.revokeObjectURL(url);
}

function exportTableToCsv(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const tableTitles = {
        paymentMethodTable: 'Payment Method Summary',
        paymentMethodBreakdownTable: 'Payment Method Breakdown by Day',
        orderDetailTable: 'Order Details',
        dailyTable: 'Daily Breakdown'
    };
    const title = tableTitles[tableId] || 'Table';
    const htmlContent = buildStyledTableDocument(table, title, { forExcel: true });
    const blob = new Blob(['\ufeff', htmlContent], { type: 'application/vnd.ms-excel' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = (filename || 'export') + '.xls';
    a.click();
    window.URL.revokeObjectURL(url);
}

function printTableById(tableId, title) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const popup = window.open('', '_blank');
    if (!popup) return;
    popup.document.write(buildStyledTableDocument(table, title || 'Table'));
    popup.document.close();
    popup.focus();
    popup.print();
    popup.close();
}

function printAllTables() {
    const popup = window.open('', '_blank');
    if (!popup) return;
    popup.document.write(buildCombinedTablesDocument());
    popup.document.close();
    popup.focus();
    popup.print();
    popup.close();
}

function buildStyledTableDocument(table, title, options = {}) {
    const isOrderDetail = table && table.id === 'orderDetailTable';
    const forExcel = !!options.forExcel;
    const styledTableHtml = isOrderDetail
        ? buildInlineStyledTableHtml(table, forExcel
            ? { width: '1700px', maxWidth: '1700px', fontSize: '11px', columnWidths: ['55px', '135px', '130px', '180px', '120px', '120px', '165px', '130px', '110px', '75px', '105px', '105px', '125px', '85px'] }
            : { width: '100%', maxWidth: '100%', fontSize: '8px', columnWidths: ['3.5%', '9%', '8%', '11%', '8%', '7%', '10%', '7%', '6%', '4.5%', '6%', '6%', '7%', '4%'] })
        : buildInlineStyledTableHtml(table);
    const fromInput = document.querySelector('input[name="table_from"]');
    const toInput = document.querySelector('input[name="table_to"]');
    const periodText = fromInput && toInput
        ? `${fromInput.value} to ${toInput.value}`
        : '';
    const generatedText = new Date().toLocaleString();

    return `
    <html>
    <head>
        <meta charset="UTF-8">
        <title>${escapeHtml(title || 'Table')}</title>
        <style>
            @page { size: ${isOrderDetail ? 'A4 landscape' : 'A4'}; margin: ${isOrderDetail ? '8mm' : '12mm'}; }
            body { font-family: Arial, sans-serif; padding: 10px; color: #111; font-size: ${isOrderDetail && !forExcel ? '10px' : '12px'}; }
            .report-header {
                text-align: center;
                margin-bottom: 14px;
            }
            .logo-wrap { text-align: center; margin-bottom: 8px; }
            .logo-wrap img { max-height: 90px; max-width: 220px; object-fit: contain; }
            h2 { margin: 0; color: #111; font-size: ${isOrderDetail && !forExcel ? '20px' : '22px'}; font-weight: 700; }
            .period { margin-top: 8px; color: #111; font-size: 13px; font-weight: 600; }
            .generated { margin-top: 3px; color: #111; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class="report-header">
            ${!forExcel && REPORT_LOGO_URL ? `<div class="logo-wrap"><img src="${escapeHtml(REPORT_LOGO_URL)}" alt="Logo"></div>` : ''}
            <h2>${escapeHtml(title || 'Table')}</h2>
            <div class="period">${escapeHtml(periodText)}</div>
            <div class="generated">Generated: ${escapeHtml(generatedText)}</div>
        </div>
        ${styledTableHtml}
    </body>
    </html>
    `;
}

function buildCombinedTablesDocument(options = {}) {
    const forExcel = !!options.forExcel;
    const paymentTable = document.getElementById('paymentMethodTable');
    const paymentMethodBreakdownTable = document.getElementById('paymentMethodBreakdownTable');
    const orderDetailTable = document.getElementById('orderDetailTable');
    const dailyTable = document.getElementById('dailyTable');
    const fromInput = document.querySelector('input[name="table_from"]');
    const toInput = document.querySelector('input[name="table_to"]');
    const periodText = fromInput && toInput ? `${fromInput.value} to ${toInput.value}` : '';
    const generatedText = new Date().toLocaleString();

    return `
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Financial Summary Report</title>
        <style>
            @page { size: A4 landscape; margin: 8mm; }
            body { font-family: Arial, sans-serif; padding: 10px; color: #111; font-size: 12px; }
            .report-header { text-align: center; margin-bottom: 14px; }
            .logo-wrap { text-align: center; margin-bottom: 8px; }
            .logo-wrap img { max-height: 90px; max-width: 220px; object-fit: contain; }
            h2 { margin: 0; color: #111; font-size: 22px; font-weight: 700; }
            .period { margin-top: 8px; color: #111; font-size: 13px; font-weight: 600; }
            .generated { margin-top: 3px; color: #111; font-size: 12px; }
            .section-title { margin: 16px 0 8px; font-size: 16px; font-weight: 700; color: #111; }
        </style>
    </head>
    <body>
        <div class="report-header">
            ${!forExcel && REPORT_LOGO_URL ? `<div class="logo-wrap"><img src="${escapeHtml(REPORT_LOGO_URL)}" alt="Logo"></div>` : ''}
            <h2>Financial Summary Report</h2>
            <div class="period">${escapeHtml(periodText)}</div>
            <div class="generated">Generated: ${escapeHtml(generatedText)}</div>
        </div>
        ${paymentTable ? `<div class="section-title">Payment Method Summary</div>${buildInlineStyledTableHtml(paymentTable, { width: '1000px', maxWidth: '1000px', fontSize: '12px', columnWidths: ['42%', '14%', '24%', '20%'] })}` : ''}
        ${paymentMethodBreakdownTable ? `<div class="section-title">Payment Method Breakdown by Day</div>${buildInlineStyledTableHtml(paymentMethodBreakdownTable, { width: '1000px', maxWidth: '1000px', fontSize: '11px', columnWidths: ['16%', '30%', '14%', '18%', '10%', '12%'] })}` : ''}
        ${dailyTable ? `<div class="section-title">Daily Breakdown</div>${buildInlineStyledTableHtml(dailyTable, { width: '1000px', maxWidth: '1000px', fontSize: '11px', columnWidths: ['14%', '10%', '13%', '12%', '12%', '12%', '15%', '12%'] })}` : ''}
        ${orderDetailTable ? `<div class="section-title">Order Details</div>${buildInlineStyledTableHtml(orderDetailTable, { width: '1700px', maxWidth: '1700px', fontSize: '11px', columnWidths: ['55px', '135px', '130px', '180px', '120px', '120px', '165px', '130px', '110px', '75px', '105px', '105px', '125px', '85px'] })}` : ''}
    </body>
    </html>
    `;
}

function buildInlineStyledTableHtml(table, options = {}) {
    const clone = table.cloneNode(true);
    clone.style.width = options.width || '100%';
    clone.style.maxWidth = options.maxWidth || '100%';
    clone.style.borderCollapse = 'collapse';
    clone.style.tableLayout = 'fixed';
    clone.style.fontSize = options.fontSize || '12px';
    clone.style.border = '1px solid #000000';
    clone.style.marginBottom = '8px';

    const columnWidths = options.columnWidths || [];
    if (columnWidths.length > 0) {
        const colgroup = document.createElement('colgroup');
        columnWidths.forEach((w) => {
            const col = document.createElement('col');
            col.style.width = w;
            colgroup.appendChild(col);
        });
        clone.insertBefore(colgroup, clone.firstChild);
    }

    const headRows = clone.querySelectorAll('thead tr');
    headRows.forEach((tr) => {
        tr.querySelectorAll('th').forEach((th) => {
            th.style.backgroundColor = '#efefef';
            th.style.color = '#111111';
            th.style.fontWeight = '700';
            th.style.border = '1px solid #000000';
            th.style.padding = '7px 8px';
            th.style.whiteSpace = 'nowrap';
        });
    });

    const bodyRows = clone.querySelectorAll('tbody tr');
    bodyRows.forEach((tr) => {
        const isTotalRow = tr.classList.contains('table-success');
        const isWarningRow = tr.classList.contains('table-danger');
        const isUnpaidRow = tr.classList.contains('table-warning');
        const rowColor = isTotalRow ? '#d9f2e4' : (isWarningRow ? '#f8d7da' : (isUnpaidRow ? '#fff3cd' : '#ffffff'));
        tr.style.backgroundColor = rowColor;
        tr.querySelectorAll('th, td').forEach((cell) => {
            cell.style.backgroundColor = rowColor;
            cell.style.border = '1px solid #000000';
            cell.style.padding = '6px 8px';
            cell.style.wordBreak = 'break-word';
            if (isTotalRow || isWarningRow || isUnpaidRow) {
                cell.style.fontWeight = '700';
            }
        });
    });

    clone.querySelectorAll('tfoot tr').forEach((tr) => {
        tr.querySelectorAll('th, td').forEach((cell) => {
            cell.style.backgroundColor = '#d9f2e4';
            cell.style.fontWeight = '700';
            cell.style.border = '1px solid #000000';
            cell.style.padding = '7px 8px';
        });
    });

    clone.querySelectorAll('.text-end').forEach((cell) => { cell.style.textAlign = 'right'; });
    clone.querySelectorAll('.text-center').forEach((cell) => { cell.style.textAlign = 'center'; });

    return clone.outerHTML;
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
</script>

<style>
.order-detail-filter-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.order-status-tabs .btn {
    font-weight: 600;
    border-radius: 0;
}
.order-status-tabs .btn:first-child {
    border-top-left-radius: 6px;
    border-bottom-left-radius: 6px;
}
.order-status-tabs .btn:last-child {
    border-top-right-radius: 6px;
    border-bottom-right-radius: 6px;
}
.order-status-counts {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.order-status-counts .badge {
    font-size: 1rem;
    padding: 10px 14px;
}
@media print {
    @page {
        size: A4 landscape;
        margin: 8mm;
    }
    .btn, form, .card-header h5 {
        display: none !important;
    }
    
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
        page-break-inside: avoid;
    }
    
    .col-lg-6 {
        float: left;
        width: 50%;
    }
}
</style>

<?php include __DIR__ . '/../layout/footer.php'; ?>
