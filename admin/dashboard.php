<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'statistics.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

// Selected calendar month (default: current month) — all widgets use this range
$today = new DateTimeImmutable('today');
$monthParam = trim((string) ($_GET['m'] ?? ''));
$monthStart = $today->modify('first day of this month');
if (preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m', $monthParam);
    if ($parsed instanceof DateTimeImmutable) {
        $monthStart = $parsed;
    }
}
$monthEnd = $monthStart->modify('last day of this month');
$startStr = $monthStart->format('Y-m-d');
$endStr = $monthEnd->format('Y-m-d');
$monthLabel = $monthStart->format('F Y');
$monthInputValue = $monthStart->format('Y-m');

$canFinanceDashboard = function_exists('has_permission') && has_permission('finance_dashboard.view');

$stmt = $pdo->prepare('
    SELECT DATE(pj.printed_at) AS d,
           COALESCE(SUM(o.total_amount), 0) AS net_revenue,
           COUNT(DISTINCT o.id) AS order_count
    FROM print_jobs pj
    INNER JOIN orders o ON o.id = pj.order_id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ?
      AND o.is_cancelled = 0 AND o.is_returned = 0
    GROUP BY DATE(pj.printed_at)
    ORDER BY d
');
$stmt->execute([$startStr, $endStr]);
$byDay = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $byDay[$row['d']] = [
        'net_revenue' => (float) $row['net_revenue'],
        'order_count' => (int) $row['order_count'],
    ];
}

$labels = [];
$revenueSeries = [];
$countSeries = [];
$totalRev = 0.0;
$totalOrders = 0;
for ($d = $monthStart; $d <= $monthEnd; $d = $d->modify('+1 day')) {
    $key = $d->format('Y-m-d');
    $labels[] = $d->format('M j');
    $rev = $byDay[$key]['net_revenue'] ?? 0.0;
    $cnt = $byDay[$key]['order_count'] ?? 0;
    $revenueSeries[] = round($rev, 2);
    $countSeries[] = $cnt;
    $totalRev += $rev;
    $totalOrders += $cnt;
}

$chartPayload = [
    'labels' => $labels,
    'revenue' => $revenueSeries,
    'orders' => $countSeries,
];

// Top products (printed sales, same window as chart — matches Statistics)
$stmt = $pdo->prepare('
    SELECT p.name,
           SUM(oi.quantity) AS qty,
           SUM(oi.line_total) AS revenue,
           COUNT(DISTINCT o.id) AS order_count
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN orders o ON oi.order_id = o.id
    JOIN print_jobs pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ?
      AND o.is_cancelled = 0 AND o.is_returned = 0
    GROUP BY p.id, p.name
    ORDER BY revenue DESC
    LIMIT 10
');
$stmt->execute([$startStr, $endStr]);
$topProductsByRevenue = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('
    SELECT p.name,
           SUM(oi.quantity) AS qty,
           SUM(oi.line_total) AS revenue,
           COUNT(DISTINCT o.id) AS order_count
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN orders o ON oi.order_id = o.id
    JOIN print_jobs pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ?
      AND o.is_cancelled = 0 AND o.is_returned = 0
    GROUP BY p.id, p.name
    ORDER BY qty DESC
    LIMIT 10
');
$stmt->execute([$startStr, $endStr]);
$topProductsByQty = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Paid order totals (money recorded as paid — same dates as payment_date or created_at)
$stmt = $pdo->prepare('
    SELECT COALESCE(SUM(o.total_amount), 0) AS paid_total,
           COUNT(*) AS paid_order_count
    FROM orders o
    WHERE o.status = \'paid\'
      AND o.is_cancelled = 0 AND o.is_returned = 0
      AND (COALESCE(o.payment_date, DATE(o.created_at)) BETWEEN ? AND ?)
');
$stmt->execute([$startStr, $endStr]);
$paidRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['paid_total' => 0, 'paid_order_count' => 0];
$paidTotal = (float) $paidRow['paid_total'];
$paidOrderCount = (int) $paidRow['paid_order_count'];

$stmt = $pdo->prepare('
    SELECT COALESCE(NULLIF(TRIM(o.payment_method), \'\'), \'(No method)\') AS payment_method,
           COUNT(*) AS cnt,
           COALESCE(SUM(o.total_amount), 0) AS amt
    FROM orders o
    WHERE o.status = \'paid\'
      AND o.is_cancelled = 0 AND o.is_returned = 0
      AND (COALESCE(o.payment_date, DATE(o.created_at)) BETWEEN ? AND ?)
    GROUP BY COALESCE(NULLIF(TRIM(o.payment_method), \'\'), \'(No method)\')
    ORDER BY amt DESC
    LIMIT 8
');
$stmt->execute([$startStr, $endStr]);
$topPaymentMethods = $stmt->fetchAll(PDO::FETCH_ASSOC);

$showCashflow = function_exists('has_permission') && has_permission('cashflow.view');
$totalTopup = 0.0;
$totalSpending = 0.0;
if ($showCashflow) {
    try {
        $st = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM cashflow_topups WHERE topup_date BETWEEN ? AND ?');
        $st->execute([$startStr, $endStr]);
        $totalTopup = (float) $st->fetchColumn();
    } catch (Throwable $e) {
        $showCashflow = false;
    }
    try {
        $st = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM cashflow_spending WHERE spending_date BETWEEN ? AND ?');
        $st->execute([$startStr, $endStr]);
        $totalSpending = (float) $st->fetchColumn();
    } catch (Throwable $e) {
        /* keep partial */
    }
}
$netCashflowPeriod = $showCashflow ? ($paidTotal + $totalTopup - $totalSpending) : null;

include __DIR__ . '/../layout/header.php';
?>
<div class="row g-4 flex-grow-1">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-bottom py-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="flex-grow-1">
                    <h2 class="h5 mb-1"><i class="bi bi-bar-chart-fill text-primary me-2"></i>Dashboard — <span class="text-primary"><?= htmlspecialchars($monthLabel) ?></span></h2>
                    <small class="text-muted">Printed sales by day + paid income + top products + cashflow — all for this month (<strong><?= htmlspecialchars($startStr) ?></strong> → <strong><?= htmlspecialchars($endStr) ?></strong>)</small>
                </div>
                <form method="get" class="d-flex flex-wrap align-items-end gap-2" action="dashboard.php">
                    <div>
                        <label class="form-label small text-muted mb-0" for="dashMonth">Month</label>
                        <input type="month" class="form-control form-control-sm" id="dashMonth" name="m" value="<?= htmlspecialchars($monthInputValue) ?>">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">Show</button>
                </form>
            </div>
            <div class="card-body border-bottom bg-light py-2">
                <div class="d-flex flex-wrap align-items-center gap-2 small">
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2">
                        Month net (printed): <strong>$<?= number_format($totalRev, 2) ?></strong>
                    </span>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                        Month printed orders: <strong><?= number_format($totalOrders) ?></strong>
                    </span>
                    <span class="text-muted">|</span>
                    <a href="statistics.php?from=<?= urlencode($startStr) ?>&amp;to=<?= urlencode($endStr) ?>" class="btn btn-sm btn-outline-primary">Statistics (same month)</a>
                    <?php if ($showCashflow): ?>
                    <a href="cashflow.php?from_date=<?= urlencode($startStr) ?>&amp;to_date=<?= urlencode($endStr) ?>" class="btn btn-sm btn-outline-secondary">Cash flow (same month)</a>
                    <?php endif; ?>
                    <?php if ($canFinanceDashboard): ?>
                    <a href="finance_dashboard.php?from_date=<?= urlencode($startStr) ?>&amp;to_date=<?= urlencode($endStr) ?>" class="btn btn-sm btn-outline-success">Finance dashboard (same month)</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div style="min-height: 300px; max-height: 480px;">
                    <canvas id="dashboardBarChart" aria-label="Daily revenue and orders bar chart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <p class="text-muted small mb-2">Figures below match <strong><?= htmlspecialchars($monthLabel) ?></strong> (<?= htmlspecialchars($startStr) ?> → <?= htmlspecialchars($endStr) ?>).</p>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Printed sales (net) · month</div>
                <div class="fs-4 fw-bold text-primary">$<?= number_format($totalRev, 2) ?></div>
                <small class="text-muted"><?= number_format($totalOrders) ?> printed orders</small>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Paid orders (income) · month</div>
                <div class="fs-4 fw-bold text-success">$<?= number_format($paidTotal, 2) ?></div>
                <small class="text-muted"><?= number_format($paidOrderCount) ?> orders marked paid</small>
            </div>
        </div>
    </div>
    <?php if ($showCashflow): ?>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-warning border-3">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Cashflow · Top up</div>
                <div class="fs-4 fw-bold text-warning">$<?= number_format($totalTopup, 2) ?></div>
                <small class="text-muted">Spending: $<?= number_format($totalSpending, 2) ?></small>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-teal border-3" style="border-color: #14b8a6 !important;">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Net cashflow (est.)</div>
                <div class="fs-4 fw-bold" style="color: #0d9488;">$<?= number_format($netCashflowPeriod, 2) ?></div>
                <small class="text-muted">Paid + top up − spending</small>
                <div class="mt-2"><a href="cashflow.php?from_date=<?= urlencode($startStr) ?>&amp;to_date=<?= urlencode($endStr) ?>" class="btn btn-sm btn-outline-secondary">Cash flow detail</a></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-light py-3">
                <h3 class="h6 mb-0"><i class="bi bi-trophy text-warning me-2"></i>Top products by revenue</h3>
                <small class="text-muted">Printed orders in <?= htmlspecialchars($monthLabel) ?></small>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topProductsByRevenue)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No data in this period</td></tr>
                        <?php else: ?>
                            <?php foreach ($topProductsByRevenue as $i => $p): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= htmlspecialchars($p['name']) ?></td>
                                    <td class="text-end"><?= number_format((int) $p['qty']) ?></td>
                                    <td class="text-end"><?= number_format((int) $p['order_count']) ?></td>
                                    <td class="text-end fw-semibold">$<?= number_format((float) $p['revenue'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-light py-3">
                <h3 class="h6 mb-0"><i class="bi bi-graph-up-arrow text-success me-2"></i>Top products by units sold</h3>
                <small class="text-muted">Printed orders in <?= htmlspecialchars($monthLabel) ?></small>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topProductsByQty)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No data in this period</td></tr>
                        <?php else: ?>
                            <?php foreach ($topProductsByQty as $i => $p): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= htmlspecialchars($p['name']) ?></td>
                                    <td class="text-end"><?= number_format((int) $p['qty']) ?></td>
                                    <td class="text-end"><?= number_format((int) $p['order_count']) ?></td>
                                    <td class="text-end">$<?= number_format((float) $p['revenue'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-light py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h3 class="h6 mb-0"><i class="bi bi-wallet2 me-2"></i>Paid income by payment method</h3>
                    <small class="text-muted">Paid orders in <?= htmlspecialchars($monthLabel) ?></small>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Method</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topPaymentMethods)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">No paid orders in this period</td></tr>
                        <?php else: ?>
                            <?php foreach ($topPaymentMethods as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['payment_method']) ?></td>
                                    <td class="text-end"><?= number_format((int) $row['cnt']) ?></td>
                                    <td class="text-end fw-semibold">$<?= number_format((float) $row['amt'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 h-100 bg-light">
            <div class="card-body">
                <h3 class="h6"><i class="bi bi-info-circle me-2"></i>How to read these numbers</h3>
                <ul class="small text-muted mb-0 ps-3">
                    <li><strong>Chart / printed sales</strong> — Each day in the selected month: revenue and order count when orders were <strong>printed</strong> (<code>print_jobs</code>), excluding cancelled &amp; returned.</li>
                    <li><strong>Paid orders</strong> — Totals for orders with status <strong>Paid</strong> whose payment date (or created date) falls in that month.</li>
                    <li><strong>Top products</strong> — Line totals from <code>order_items</code> on printed orders in that month.</li>
                    <?php if ($showCashflow): ?>
                    <li><strong>Net cashflow</strong> — Paid total + cashflow top-ups − cashflow spending (same dates as <a href="cashflow.php">Cash flow</a>).</li>
                    <?php else: ?>
                    <li>Cashflow summary appears here if your role has <strong>Cash flow</strong> view permission.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="dashboard-chart-data"><?= json_encode($chartPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function (run) {
    (typeof window.adminRunWhenReady === 'function' ? window.adminRunWhenReady : run)(function () {
        var el = document.getElementById('dashboardBarChart');
        var dataEl = document.getElementById('dashboard-chart-data');
        if (!el || !dataEl) return;
        var payload;
        try {
            payload = JSON.parse(dataEl.textContent);
        } catch (e) {
            return;
        }
        var labels = payload.labels || [];
        var revenue = payload.revenue || [];
        var orders = payload.orders || [];

        new Chart(el.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Net revenue ($)',
                        data: revenue,
                        backgroundColor: 'rgba(13, 110, 253, 0.65)',
                        borderColor: 'rgb(13, 110, 253)',
                        borderWidth: 1,
                        borderRadius: 6,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Printed orders',
                        data: orders,
                        backgroundColor: 'rgba(25, 135, 84, 0.55)',
                        borderColor: 'rgb(25, 135, 84)',
                        borderWidth: 1,
                        borderRadius: 6,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true, padding: 16 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var v = ctx.parsed.y;
                                if (ctx.datasetIndex === 0) {
                                    return 'Net revenue: $' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                }
                                return 'Orders: ' + v;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxRotation: 45, minRotation: 0 }
                    },
                    y: {
                        type: 'linear',
                        position: 'left',
                        beginAtZero: true,
                        title: { display: true, text: 'Net revenue ($)' },
                        ticks: {
                            callback: function (value) {
                                return '$' + value;
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        beginAtZero: true,
                        title: { display: true, text: 'Order count' },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    });
})(function (fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
});
</script>
<?php include __DIR__ . '/../layout/footer.php'; ?>
