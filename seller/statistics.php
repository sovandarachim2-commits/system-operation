<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['seller'], 'seller_statistics.view');
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();
$user = current_user();

$today = date('Y-m-d');
$from = $_GET['from'] ?? $today;
$to = $_GET['to'] ?? $from;
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from) ? (string)$from : $today;
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to) ? (string)$to : $from;
if ($to < $from) {
    [$from, $to] = [$to, $from];
}

$sellerId = (int)$user['id'];
$rangeParams = [$sellerId, $from, $to];

$money = static function ($value): string {
    return '$' . number_format((float)$value, 2);
};

$stmt = $pdo->prepare('
    SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN o.is_cancelled = 0 THEN 1 ELSE 0 END) AS active_orders,
        SUM(CASE WHEN o.is_cancelled = 1 THEN 1 ELSE 0 END) AS cancelled_orders,
        SUM(CASE WHEN o.is_cancelled = 0 AND o.status = "paid" THEN 1 ELSE 0 END) AS paid_orders,
        SUM(CASE WHEN o.is_cancelled = 0 AND o.status = "unpaid" THEN 1 ELSE 0 END) AS unpaid_orders,
        SUM(CASE WHEN o.is_cancelled = 0 THEN o.total_amount ELSE 0 END) AS sales_total,
        SUM(CASE WHEN o.is_cancelled = 0 AND o.status = "paid" THEN o.total_amount ELSE 0 END) AS paid_total,
        SUM(CASE WHEN o.is_cancelled = 0 AND o.status = "unpaid" THEN o.total_amount ELSE 0 END) AS unpaid_total,
        SUM(CASE WHEN o.is_cancelled = 1 THEN o.total_amount ELSE 0 END) AS cancelled_total,
        SUM(CASE WHEN o.is_cancelled = 0 AND EXISTS (SELECT 1 FROM print_jobs pj WHERE pj.order_id = o.id) THEN 1 ELSE 0 END) AS printed_orders,
        SUM(CASE WHEN o.is_cancelled = 0 AND NOT EXISTS (SELECT 1 FROM print_jobs pj WHERE pj.order_id = o.id) THEN 1 ELSE 0 END) AS pending_print_orders
    FROM orders o
    WHERE o.seller_id = ?
      AND DATE(o.created_at) BETWEEN ? AND ?
');
$stmt->execute($rangeParams);
$summary = $stmt->fetch() ?: [];

$totalOrders = (int)($summary['total_orders'] ?? 0);
$activeOrders = (int)($summary['active_orders'] ?? 0);
$cancelledOrders = (int)($summary['cancelled_orders'] ?? 0);
$paidOrders = (int)($summary['paid_orders'] ?? 0);
$unpaidOrders = (int)($summary['unpaid_orders'] ?? 0);
$salesTotal = (float)($summary['sales_total'] ?? 0);
$paidTotal = (float)($summary['paid_total'] ?? 0);
$unpaidTotal = (float)($summary['unpaid_total'] ?? 0);
$cancelledTotal = (float)($summary['cancelled_total'] ?? 0);
$printedOrders = (int)($summary['printed_orders'] ?? 0);
$pendingPrintOrders = (int)($summary['pending_print_orders'] ?? 0);
$averageOrder = $activeOrders > 0 ? $salesTotal / $activeOrders : 0;
$cancelRate = $totalOrders > 0 ? ($cancelledOrders / $totalOrders) * 100 : 0;

$stmt = $pdo->prepare('
    SELECT
        DATE(o.created_at) AS sale_date,
        COUNT(*) AS total_orders,
        SUM(CASE WHEN o.is_cancelled = 0 THEN 1 ELSE 0 END) AS active_orders,
        SUM(CASE WHEN o.is_cancelled = 1 THEN 1 ELSE 0 END) AS cancelled_orders,
        SUM(CASE WHEN o.is_cancelled = 0 THEN o.total_amount ELSE 0 END) AS sales_total,
        SUM(CASE WHEN o.is_cancelled = 0 AND o.status = "paid" THEN o.total_amount ELSE 0 END) AS paid_total,
        SUM(CASE WHEN o.is_cancelled = 0 AND o.status = "unpaid" THEN o.total_amount ELSE 0 END) AS unpaid_total
    FROM orders o
    WHERE o.seller_id = ?
      AND DATE(o.created_at) BETWEEN ? AND ?
    GROUP BY DATE(o.created_at)
    ORDER BY sale_date
');
$stmt->execute($rangeParams);
$dailyRows = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT
        COALESCE(p.name, "No page") AS page_name,
        COUNT(*) AS total_orders,
        SUM(CASE WHEN o.is_cancelled = 0 THEN 1 ELSE 0 END) AS active_orders,
        SUM(CASE WHEN o.is_cancelled = 1 THEN 1 ELSE 0 END) AS cancelled_orders,
        SUM(CASE WHEN o.is_cancelled = 0 THEN o.total_amount ELSE 0 END) AS sales_total,
        SUM(CASE WHEN o.is_cancelled = 0 AND o.status = "paid" THEN o.total_amount ELSE 0 END) AS paid_total,
        SUM(CASE WHEN o.is_cancelled = 0 AND o.status = "unpaid" THEN o.total_amount ELSE 0 END) AS unpaid_total
    FROM orders o
    LEFT JOIN pages p ON p.id = o.page_id
    WHERE o.seller_id = ?
      AND DATE(o.created_at) BETWEEN ? AND ?
    GROUP BY p.id, p.name
    ORDER BY sales_total DESC, total_orders DESC
');
$stmt->execute($rangeParams);
$pageRows = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT
        p.name AS product_name,
        SUM(oi.quantity) AS quantity_sold,
        SUM(oi.line_total) AS product_total
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE o.seller_id = ?
      AND DATE(o.created_at) BETWEEN ? AND ?
      AND o.is_cancelled = 0
    GROUP BY oi.product_id, p.name
    ORDER BY quantity_sold DESC, product_total DESC
    LIMIT 10
');
$stmt->execute($rangeParams);
$productRows = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT
        HOUR(o.created_at) AS order_hour,
        COUNT(*) AS order_count
    FROM orders o
    WHERE o.seller_id = ?
      AND DATE(o.created_at) BETWEEN ? AND ?
      AND o.is_cancelled = 0
    GROUP BY HOUR(o.created_at)
    ORDER BY order_hour
');
$stmt->execute($rangeParams);
$hourRows = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT o.order_code, o.customer_name, o.status, o.is_cancelled, o.total_amount, o.created_at,
           COALESCE(p.name, "No page") AS page_name
    FROM orders o
    LEFT JOIN pages p ON p.id = o.page_id
    WHERE o.seller_id = ?
      AND DATE(o.created_at) BETWEEN ? AND ?
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT 12
');
$stmt->execute($rangeParams);
$recentOrders = $stmt->fetchAll();

$chartLabels = array_map(static fn($row) => (string)$row['sale_date'], $dailyRows);
$chartOrders = array_map(static fn($row) => (int)$row['active_orders'], $dailyRows);
$chartCancelled = array_map(static fn($row) => (int)$row['cancelled_orders'], $dailyRows);

$hourLabels = [];
$hourData = [];
for ($hour = 0; $hour < 24; $hour++) {
    $hourLabels[] = str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00';
    $hourData[$hour] = 0;
}
foreach ($hourRows as $row) {
    $hourData[(int)$row['order_hour']] = (int)$row['order_count'];
}

require_once __DIR__ . '/../layout/header.php';
?>
<style>
    .seller-stat-page {
        color: #232323;
    }
    .seller-stat-header {
        background: linear-gradient(135deg, #232323 0%, #34302b 62%, #5f4520 100%);
        color: #fff;
        border-radius: 8px;
        padding: 1rem;
        box-shadow: 0 12px 26px rgba(35, 35, 35, 0.18);
    }
    .seller-stat-header .form-control,
    .seller-stat-header .btn {
        min-height: 42px;
    }
    .seller-stat-card {
        border: 1px solid rgba(35, 35, 35, 0.08);
        border-radius: 8px;
        box-shadow: 0 10px 24px rgba(35, 35, 35, 0.08);
        height: 100%;
    }
    .seller-stat-card .metric-icon {
        width: 2.4rem;
        height: 2.4rem;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #232323;
        background: #fdb04c;
        flex-shrink: 0;
    }
    .seller-stat-card .metric-label {
        color: #6c757d;
        font-size: .82rem;
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    .seller-stat-card .metric-value {
        font-size: clamp(1.4rem, 2.4vw, 2rem);
        font-weight: 750;
        line-height: 1.1;
    }
    .seller-stat-section {
        border-radius: 8px;
        border: 1px solid rgba(35, 35, 35, 0.08);
        box-shadow: 0 8px 20px rgba(35, 35, 35, 0.07);
    }
    .seller-stat-section .card-header {
        background: #fff;
        border-bottom: 1px solid rgba(35, 35, 35, 0.08);
        font-weight: 700;
    }
    .seller-stat-table th {
        white-space: nowrap;
        color: #6c757d;
        font-size: .82rem;
        text-transform: uppercase;
    }
    .seller-chart-box {
        position: relative;
        min-height: 280px;
    }
    @media (max-width: 767px) {
        .seller-stat-header {
            padding: .85rem;
        }
        .seller-chart-box {
            min-height: 220px;
        }
    }
</style>

<div class="seller-stat-page d-flex flex-column gap-3">
    <div class="seller-stat-header">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-lg">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi bi-bar-chart-line fs-4 text-warning"></i>
                    <h1 class="h4 mb-0">My Sales Statistics</h1>
                    <a href="<?= htmlspecialchars($BASE_URL) ?>/seller/order_new.php" class="btn btn-success ms-auto">
                        <i class="bi bi-plus-circle me-1"></i>New Order
                    </a>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small text-white-50">From</label>
                <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small text-white-50">To</label>
                <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
            </div>
            <div class="col-12 col-md-4 col-lg-3 d-flex gap-2">
                <button class="btn btn-warning flex-fill" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a class="btn btn-outline-light flex-fill" href="statistics.php">Today</a>
            </div>
        </form>
    </div>

    <div class="row g-3">
        <div class="col-6 col-md-3">
            <div class="card bg-primary text-white shadow h-100">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-receipt me-2"></i>Active Orders</h5>
                    <h3 class="mb-1"><?= $activeOrders ?></h3>
                    <small class="opacity-75">Not cancelled</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-danger text-white shadow h-100">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-x-circle me-2"></i>Cancelled</h5>
                    <h3 class="mb-1"><?= $cancelledOrders ?></h3>
                    <small class="opacity-75"><?= number_format($cancelRate, 1) ?>% of all orders</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-info text-white shadow h-100">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-printer me-2"></i>Printed</h5>
                    <h3 class="mb-1"><?= $printedOrders ?></h3>
                    <small class="opacity-75">Completed print</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-warning text-white shadow h-100">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-hourglass-split me-2"></i>Pending Print</h5>
                    <h3 class="mb-1"><?= $pendingPrintOrders ?></h3>
                    <small class="opacity-75">Not yet printed</small>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card seller-stat-section h-100">
                <div class="card-header"><i class="bi bi-graph-up me-2"></i>Daily Sales</div>
                <div class="card-body seller-chart-box">
                    <canvas id="dailySalesChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card seller-stat-section h-100">
                <div class="card-header"><i class="bi bi-pie-chart me-2"></i>Order Status</div>
                <div class="card-body seller-chart-box">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card seller-stat-section h-100">
                <div class="card-header"><i class="bi bi-trophy me-2"></i>Top Products</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 seller-stat-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Product Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$productRows): ?>
                                <tr><td colspan="3" class="text-center text-muted py-4">No product sales in this range.</td></tr>
                            <?php else: ?>
                                <?php foreach ($productRows as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['product_name'] ?? 'Unknown product') ?></td>
                                        <td class="text-end"><?= (int)$row['quantity_sold'] ?></td>
                                        <td class="text-end"><?= $money($row['product_total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card seller-stat-section h-100">
                <div class="card-header"><i class="bi bi-clock me-2"></i>Active Orders by Hour</div>
                <div class="card-body seller-chart-box">
                    <canvas id="hourlyChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card seller-stat-section h-100">
                <div class="card-header"><i class="bi bi-file-text me-2"></i>Page Performance</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 seller-stat-table">
                        <thead>
                            <tr>
                                <th>Page</th>
                                <th class="text-end">Orders</th>
                                <th class="text-end">Cancel</th>
                                <th class="text-end">Sales</th>
                                <th class="text-end">Paid</th>
                                <th class="text-end">Unpaid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$pageRows): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No page data in this range.</td></tr>
                            <?php else: ?>
                                <?php foreach ($pageRows as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['page_name']) ?></td>
                                        <td class="text-end"><?= (int)$row['active_orders'] ?></td>
                                        <td class="text-end"><?= (int)$row['cancelled_orders'] ?></td>
                                        <td class="text-end"><?= $money($row['sales_total']) ?></td>
                                        <td class="text-end"><?= $money($row['paid_total']) ?></td>
                                        <td class="text-end"><?= $money($row['unpaid_total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card seller-stat-section h-100">
                <div class="card-header"><i class="bi bi-receipt me-2"></i>Recent Orders</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 seller-stat-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$recentOrders): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No orders in this range.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentOrders as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($row['order_code']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$row['created_at']))) ?></div>
                                        </td>
                                        <td>
                                            <div><?= htmlspecialchars($row['customer_name']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($row['page_name']) ?></div>
                                        </td>
                                        <td>
                                            <?php if ((int)$row['is_cancelled'] === 1): ?>
                                                <span class="badge bg-danger">Cancelled</span>
                                            <?php elseif ($row['status'] === 'paid'): ?>
                                                <span class="badge bg-success">Paid</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?= $money($row['total_amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(window.adminRunWhenReady || function (fn) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn);
    } else {
        fn();
    }
})(function () {
    var dailyLabels = <?= json_encode($chartLabels, JSON_UNESCAPED_SLASHES) ?>;
    var dailyOrders = <?= json_encode($chartOrders) ?>;
    var dailyCancelled = <?= json_encode($chartCancelled) ?>;
    var hourLabels = <?= json_encode($hourLabels) ?>;
    var hourData = <?= json_encode(array_values($hourData)) ?>;

    var dailyEl = document.getElementById('dailySalesChart');
    if (dailyEl && window.Chart) {
        new Chart(dailyEl, {
            type: 'bar',
            data: {
                labels: dailyLabels,
                datasets: [
                    {
                        label: 'Active Orders',
                        data: dailyOrders,
                        backgroundColor: 'rgba(253, 176, 76, 0.78)',
                        borderColor: '#FDB04C',
                        borderWidth: 1
                    },
                    {
                        label: 'Cancelled',
                        data: dailyCancelled,
                        backgroundColor: 'rgba(220, 53, 69, 0.72)',
                        borderColor: '#dc3545',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }

    var statusEl = document.getElementById('statusChart');
    if (statusEl && window.Chart) {
        new Chart(statusEl, {
            type: 'doughnut',
            data: {
                labels: ['Paid', 'Unpaid', 'Cancelled'],
                datasets: [{
                    data: [<?= $paidOrders ?>, <?= $unpaidOrders ?>, <?= $cancelledOrders ?>],
                    backgroundColor: ['#198754', '#FDB04C', '#dc3545'],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    var hourlyEl = document.getElementById('hourlyChart');
    if (hourlyEl && window.Chart) {
        new Chart(hourlyEl, {
            type: 'bar',
            data: {
                labels: hourLabels,
                datasets: [{
                    label: 'Active Orders',
                    data: hourData,
                    backgroundColor: 'rgba(35, 35, 35, 0.78)',
                    borderColor: '#232323',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
