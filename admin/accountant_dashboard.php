<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'accountant_dashboard.view');

$pdo = get_db_connection();

// Get date filters
$from = $_GET['from'] ?? date('Y-m-01'); // First day of current month
$to = $_GET['to'] ?? date('Y-m-d');     // Today
$printedOrdersJoin = '(SELECT order_id, MAX(printed_at) AS printed_at FROM print_jobs GROUP BY order_id)';

// Calculate previous month for comparison
$prev_from = date('Y-m-01', strtotime('-1 month'));
$prev_to = date('Y-m-t', strtotime('-1 month'));

// Current period statistics - exclude cancelled and returned from totals
$stmt = $pdo->prepare('
    SELECT 
        COUNT(DISTINCT o.id) as total_orders,
        COUNT(DISTINCT CASE WHEN o.status = \'paid\' AND o.is_cancelled = 0 AND o.is_returned = 0 THEN o.id END) as paid_orders,
        COUNT(DISTINCT CASE WHEN o.status = \'unpaid\' AND o.is_cancelled = 0 AND o.is_returned = 0 THEN o.id END) as unpaid_orders,
        COALESCE(SUM(CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 THEN o.total_amount ELSE 0 END), 0) as total_revenue,
        COALESCE(SUM(CASE WHEN o.status = \'paid\' AND o.is_cancelled = 0 AND o.is_returned = 0 THEN o.total_amount ELSE 0 END), 0) as paid_revenue,
        COALESCE(SUM(CASE WHEN o.status = \'unpaid\' AND o.is_cancelled = 0 AND o.is_returned = 0 THEN o.total_amount ELSE 0 END), 0) as unpaid_revenue,
        COALESCE(SUM(CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 THEN o.discount ELSE 0 END), 0) as total_discounts,
        COALESCE(AVG(CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 THEN o.total_amount ELSE NULL END), 0) as avg_order_value
    FROM orders o
    JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND o.is_cancelled = 0 AND o.is_returned = 0
');
$stmt->execute([$from, $to]);
$current_stats_result = $stmt->fetch();

// Get cancelled orders separately
$stmt_cancelled = $pdo->prepare('
    SELECT COUNT(DISTINCT o.id) as cancelled_orders
    FROM orders o
    JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND o.is_cancelled = 1
');
$stmt_cancelled->execute([$from, $to]);
$cancelled_result = $stmt_cancelled->fetch();

// Get returned orders separately
$stmt_returned = $pdo->prepare('
    SELECT COUNT(DISTINCT o.id) as returned_orders
    FROM orders o
    JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND o.is_returned = 1
');
$stmt_returned->execute([$from, $to]);
$returned_result = $stmt_returned->fetch();

$current_stats = $current_stats_result ?: [
    'total_orders' => 0,
    'paid_orders' => 0,
    'unpaid_orders' => 0,
    'cancelled_orders' => 0,
    'returned_orders' => 0,
    'total_revenue' => 0,
    'paid_revenue' => 0,
    'unpaid_revenue' => 0,
    'total_discounts' => 0,
    'avg_order_value' => 0,
    'unique_products_sold' => 0,
    'total_items_sold' => 0
];

// Add cancelled and returned orders count
$current_stats['cancelled_orders'] = $cancelled_result['cancelled_orders'] ?? 0;
$current_stats['returned_orders'] = $returned_result['returned_orders'] ?? 0;

// Get product and item counts separately
$stmt_items = $pdo->prepare('
    SELECT 
        COUNT(DISTINCT oi.product_id) as unique_products_sold,
        COALESCE(SUM(oi.quantity), 0) as total_items_sold
    FROM order_items oi
    WHERE oi.order_id IN (
        SELECT o.id 
        FROM orders o
        JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
        WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND o.is_cancelled = 0 AND o.is_returned = 0
    )
');
$stmt_items->execute([$from, $to]);
$items_result = $stmt_items->fetch();

$current_stats['unique_products_sold'] = $items_result['unique_products_sold'] ?? 0;
$current_stats['total_items_sold'] = $items_result['total_items_sold'] ?? 0;

// Previous period statistics for comparison
$stmt = $pdo->prepare('
    SELECT
        COUNT(DISTINCT o.id) as total_orders,
        COALESCE(SUM(o.total_amount), 0) as total_revenue,
        COALESCE((
            SELECT SUM(oi.quantity)
            FROM order_items oi
            WHERE oi.order_id IN (
                SELECT o2.id
                FROM orders o2
                JOIN ' . $printedOrdersJoin . ' pj2 ON pj2.order_id = o2.id
                WHERE DATE(pj2.printed_at) BETWEEN ? AND ?
                  AND o2.is_cancelled = 0
                  AND o2.is_returned = 0
            )
        ), 0) as total_items_sold
    FROM orders o
    JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ?
      AND o.is_cancelled = 0
      AND o.is_returned = 0
');
$stmt->execute([$prev_from, $prev_to, $prev_from, $prev_to]);
$prev_stats_result = $stmt->fetch();
$prev_stats = $prev_stats_result ?: [
    'total_orders' => 0,
    'total_revenue' => 0,
    'total_items_sold' => 0
];

// Today's statistics
$stmt = $pdo->prepare('
    SELECT
        COUNT(DISTINCT o.id) as today_orders,
        COALESCE(SUM(o.total_amount), 0) as today_revenue,
        COALESCE((
            SELECT SUM(oi.quantity)
            FROM order_items oi
            WHERE oi.order_id IN (
                SELECT o2.id
                FROM orders o2
                JOIN ' . $printedOrdersJoin . ' pj2 ON pj2.order_id = o2.id
                WHERE DATE(pj2.printed_at) = CURDATE()
                  AND o2.is_cancelled = 0
                  AND o2.is_returned = 0
            )
        ), 0) as today_items
    FROM orders o
    JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) = CURDATE()
      AND o.is_cancelled = 0
      AND o.is_returned = 0
');
$stmt->execute();
$today_stats_result = $stmt->fetch();
$today_stats = $today_stats_result ?: [
    'today_orders' => 0,
    'today_revenue' => 0,
    'today_items' => 0
];

// Top selling products in current period
$stmt = $pdo->prepare('
    SELECT 
        p.name,
        SUM(oi.quantity) as total_quantity,
        SUM(oi.line_total) as total_revenue,
        COUNT(DISTINCT oi.order_id) as order_count
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN orders o ON oi.order_id = o.id
    JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND o.is_cancelled = 0 AND o.is_returned = 0
    GROUP BY oi.product_id, p.name
    ORDER BY total_quantity DESC
    LIMIT 10
');
$stmt->execute([$from, $to]);
$top_products = $stmt->fetchAll();

// Daily revenue trend for current period
$stmt = $pdo->prepare('
    SELECT
        order_totals.order_date,
        order_totals.order_count,
        order_totals.daily_revenue,
        COALESCE(item_totals.daily_items, 0) as daily_items
    FROM (
        SELECT
            DATE(pj.printed_at) as order_date,
            COUNT(DISTINCT o.id) as order_count,
            COALESCE(SUM(o.total_amount), 0) as daily_revenue
        FROM orders o
        JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
        WHERE DATE(pj.printed_at) BETWEEN ? AND ?
          AND o.is_cancelled = 0
          AND o.is_returned = 0
        GROUP BY DATE(pj.printed_at)
    ) order_totals
    LEFT JOIN (
        SELECT
            DATE(pj.printed_at) as order_date,
            COALESCE(SUM(oi.quantity), 0) as daily_items
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
        WHERE DATE(pj.printed_at) BETWEEN ? AND ?
          AND o.is_cancelled = 0
          AND o.is_returned = 0
        GROUP BY DATE(pj.printed_at)
    ) item_totals ON item_totals.order_date = order_totals.order_date
    ORDER BY order_date ASC
');
$stmt->execute([$from, $to, $from, $to]);
$daily_trend = $stmt->fetchAll();

// Payment status breakdown (including cancelled and returned orders with original status)
$stmt = $pdo->prepare('
    SELECT 
        CASE 
            WHEN o.is_cancelled = 1 THEN CONCAT(o.status, \' (CANCELLED)\')
            WHEN o.is_returned = 1 THEN CONCAT(o.status, \' (RETURNED)\')
            ELSE o.status
        END as display_status,
        o.status as original_status,
        o.is_cancelled,
        o.is_returned,
        COUNT(DISTINCT o.id) as order_count,
        COALESCE(SUM(o.total_amount), 0) as total_amount,
        ROUND(COUNT(DISTINCT o.id) * 100.0 / (SELECT COUNT(DISTINCT o2.id) FROM orders o2 JOIN ' . $printedOrdersJoin . ' pj2 ON pj2.order_id = o2.id WHERE DATE(pj2.printed_at) BETWEEN ? AND ?), 2) as percentage
    FROM orders o
    JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ?
    GROUP BY o.status, o.is_cancelled, o.is_returned
    ORDER BY o.is_cancelled DESC, o.is_returned DESC, o.status
');
$stmt->execute([$from, $to, $from, $to]);
$payment_breakdown = $stmt->fetchAll();

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <h1 class="h4 mb-0"><i class="bi bi-calculator me-2"></i>Accountant Dashboard</h1>
        <div class="d-flex gap-2 align-items-center">
            <input type="date" id="fromDate" class="form-control" value="<?= htmlspecialchars($from) ?>" style="width: auto;">
            <input type="date" id="toDate" class="form-control" value="<?= htmlspecialchars($to) ?>" style="width: auto;">
            <button class="btn btn-primary" onclick="updateDashboard()">Update</button>
            <button class="btn btn-outline-success" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print
            </button>
            <button class="btn btn-outline-primary" onclick="exportToExcel()">
                <i class="bi bi-file-earmark-excel me-1"></i>Export
            </button>
        </div>
    </div>

    <!-- Quick Date Range Buttons -->
    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-outline-secondary btn-sm" onclick="setDateRange('today')">Today</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="setDateRange('yesterday')">Yesterday</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="setDateRange('thisweek')">This Week</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="setDateRange('lastweek')">Last Week</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="setDateRange('thismonth')">This Month</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="setDateRange('lastmonth')">Last Month</button>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Total Orders</h5>
                    <h3 class="mb-0"><?= number_format($current_stats['total_orders']) ?></h3>
                    <small>
                        <?php 
                        $growth = $prev_stats['total_orders'] > 0 ? (($current_stats['total_orders'] - $prev_stats['total_orders']) / $prev_stats['total_orders']) * 100 : 0;
                        echo $growth >= 0 ? '↑' : '↓';
                        echo abs(number_format($growth, 1)) . '% vs last month';
                        ?>
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Total Revenue</h5>
                    <h3 class="mb-0">$<?= number_format($current_stats['total_revenue'], 2) ?></h3>
                    <small>
                        <?php 
                        $growth = $prev_stats['total_revenue'] > 0 ? (($current_stats['total_revenue'] - $prev_stats['total_revenue']) / $prev_stats['total_revenue']) * 100 : 0;
                        echo $growth >= 0 ? '↑' : '↓';
                        echo abs(number_format($growth, 1)) . '% vs last month';
                        ?>
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Total Discounts</h5>
                    <h3 class="mb-0">$<?= number_format($current_stats['total_discounts'], 2) ?></h3>
                    <small>Discounts given</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-dark text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Net Revenue</h5>
                    <h3 class="mb-0">$<?= number_format($current_stats['total_revenue'] - $current_stats['total_discounts'], 2) ?></h3>
                    <small>After discounts</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Cancelled Orders</h5>
                    <h3 class="mb-0"><?= number_format($current_stats['cancelled_orders']) ?></h3>
                    <small>Orders cancelled</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-secondary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Returned Orders</h5>
                    <h3 class="mb-0"><?= number_format($current_stats['returned_orders']) ?></h3>
                    <small>Orders returned</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Items Sold</h5>
                    <h3 class="mb-0"><?= number_format($current_stats['total_items_sold']) ?></h3>
                    <small>
                        <?php 
                        $growth = $prev_stats['total_items_sold'] > 0 ? (($current_stats['total_items_sold'] - $prev_stats['total_items_sold']) / $prev_stats['total_items_sold']) * 100 : 0;
                        echo $growth >= 0 ? '↑' : '↓';
                        echo abs(number_format($growth, 1)) . '% vs last month';
                        ?>
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-secondary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Avg Order Value</h5>
                    <h3 class="mb-0">$<?= number_format($current_stats['avg_order_value'], 2) ?></h3>
                    <small>Per order average</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Additional Stats Row -->
    <!-- <div class="row g-3 mb-4"> -->
        
        
        
    </div>

    <!-- Today's Performance -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Today's Performance</h5>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-4">
                    <h4 class="text-primary"><?= number_format($today_stats['today_orders']) ?></h4>
                    <p class="mb-0">Orders Today</p>
                </div>
                <div class="col-md-4">
                    <h4 class="text-success">$<?= number_format($today_stats['today_revenue'], 2) ?></h4>
                    <p class="mb-0">Revenue Today</p>
                </div>
                <div class="col-md-4">
                    <h4 class="text-info"><?= number_format($today_stats['today_items']) ?></h4>
                    <p class="mb-0">Items Sold Today</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts and Tables Row -->
    <div class="row g-4">
        <!-- Top Products -->
        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Top Selling Products</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Revenue</th>
                                    <th class="text-end">Orders</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_products)): ?>
                                    <tr><td colspan="4" class="text-center py-3">No sales data</td></tr>
                                <?php else: ?>
                                    <?php foreach ($top_products as $product): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($product['name']) ?></td>
                                            <td class="text-end"><?= number_format($product['total_quantity']) ?></td>
                                            <td class="text-end">$<?= number_format($product['total_revenue'], 2) ?></td>
                                            <td class="text-end"><?= number_format($product['order_count']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Status Breakdown -->
        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Payment Status Breakdown</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th class="text-end">Orders</th>
                                    <th class="text-end">Amount</th>
                                    <th class="text-end">Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payment_breakdown)): ?>
                                    <tr><td colspan="4" class="text-center py-3">No data</td></tr>
                                <?php else: ?>
                                    <?php foreach ($payment_breakdown as $status): ?>
                                        <tr>
                                            <td>
                                                <?php if ($status['is_cancelled'] == 1): ?>
                                                    <span class="badge bg-danger">
                                                        <?= htmlspecialchars($status['display_status']) ?>
                                                    </span>
                                                <?php elseif ($status['is_returned'] == 1): ?>
                                                    <span class="badge bg-secondary">
                                                        <?= htmlspecialchars($status['display_status']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-<?= $status['original_status'] === 'paid' ? 'success' : 'warning' ?>">
                                                        <?= ucfirst($status['original_status']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><?= number_format($status['order_count']) ?></td>
                                            <td class="text-end">$<?= number_format($status['total_amount'], 2) ?></td>
                                            <td class="text-end"><?= number_format($status['percentage'], 1) ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-active fw-bold">
                                    <td><strong>Total</strong></td>
                                    <td class="text-end">
                                        <strong><?= number_format(array_sum(array_column($payment_breakdown, 'order_count'))) ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong>$<?= number_format(array_sum(array_column($payment_breakdown, 'total_amount')), 2) ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong><?= number_format(array_sum(array_column($payment_breakdown, 'percentage')), 1) ?>%</strong>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Daily Trend Chart -->
    <div class="card shadow mt-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Daily Revenue Trend</h5>
        </div>
        <div class="card-body">
            <canvas id="dailyTrendChart" height="100"></canvas>
        </div>
    </div>
</div>

<!-- Hidden data for JavaScript -->
<div id="chartData" style="display: none;">
    <?= json_encode([
        'daily_trend' => $daily_trend,
        'top_products' => $top_products,
        'payment_breakdown' => $payment_breakdown
    ]) ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chartData = JSON.parse(document.getElementById('chartData').textContent);
    
    // Daily Trend Chart
    const ctx = document.getElementById('dailyTrendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.daily_trend.map(d => d.order_date),
            datasets: [{
                label: 'Daily Revenue',
                data: chartData.daily_trend.map(d => d.daily_revenue),
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Order Count',
                data: chartData.daily_trend.map(d => d.order_count),
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
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
                        text: 'Order Count'
                    },
                    grid: {
                        drawOnChartArea: false,
                    },
                }
            }
        }
    });
});

// Update button handler (like statistics page)
window.updateDashboard = function() {
    const fromDate = document.getElementById('fromDate').value;
    const toDate = document.getElementById('toDate').value;

    // Update URL without page reload
    const url = new URL(window.location);
    url.searchParams.set('from', fromDate);
    url.searchParams.set('to', toDate);
    window.location.href = url.toString();
};

function setDateRange(range) {
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    
    let fromDate, toDate;
    
    switch(range) {
        case 'today':
            fromDate = toDate = today.toISOString().split('T')[0];
            break;
        case 'yesterday':
            fromDate = toDate = yesterday.toISOString().split('T')[0];
            break;
        case 'thisweek':
            const startOfWeek = new Date(today);
            startOfWeek.setDate(today.getDate() - today.getDay());
            fromDate = startOfWeek.toISOString().split('T')[0];
            toDate = today.toISOString().split('T')[0];
            break;
        case 'lastweek':
            const startOfLastWeek = new Date(today);
            startOfLastWeek.setDate(today.getDate() - today.getDay() - 7);
            const endOfLastWeek = new Date(startOfLastWeek);
            endOfLastWeek.setDate(startOfLastWeek.getDate() + 6);
            fromDate = startOfLastWeek.toISOString().split('T')[0];
            toDate = endOfLastWeek.toISOString().split('T')[0];
            break;
        case 'thismonth':
            fromDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
            toDate = today.toISOString().split('T')[0];
            break;
        case 'lastmonth':
            fromDate = new Date(today.getFullYear(), today.getMonth() - 1, 1).toISOString().split('T')[0];
            toDate = new Date(today.getFullYear(), today.getMonth(), 0).toISOString().split('T')[0];
            break;
    }
    
    document.getElementById('fromDate').value = fromDate;
    document.getElementById('toDate').value = toDate;
    updateDashboard();
}

function exportToExcel() {
    // Create CSV content
    let csv = 'Date,Order Count,Revenue,Items Sold\n';
    
    const chartData = JSON.parse(document.getElementById('chartData').textContent);
    chartData.daily_trend.forEach(row => {
        csv += `${row.order_date},${row.order_count},${row.daily_revenue},${row.daily_items}\n`;
    });
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `accountant_report_${document.querySelector('input[name="from"]').value}_to_${document.querySelector('input[name="to"]').value}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<style>
@media print {
    /* Hide controls and buttons */
    .btn, .d-flex.gap-2, .card-header h5 {
        display: none !important;
    }
    
    /* Show date range in print */
    .d-flex.flex-wrap.justify-content-between::before {
        content: "Report Period: <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?>";
        display: block;
        font-weight: bold;
        margin-bottom: 20px;
        font-size: 14px;
    }
    
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
        page-break-inside: avoid;
        margin-bottom: 15px !important;
    }
    
    .col-md-3, .col-md-4, .col-lg-6 {
        float: left;
        width: 50%;
    }
    
    /* Ensure proper spacing in print */
    .row {
        margin-bottom: 20px;
    }
    
    /* Add page title */
    .d-flex.flex-column.h-100::before {
        content: "Accountant Financial Report";
        display: block;
        font-size: 20px;
        font-weight: bold;
        text-align: center;
        margin-bottom: 30px;
    }
}
</style>

<?php include __DIR__ . '/../layout/footer.php'; ?>
