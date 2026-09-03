<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'cashflow.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

// Get filter parameters
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date   = $_GET['to_date'] ?? date('Y-m-d');

// Get all note options (payment methods) - used for order dropdown
$stmt = $pdo->query("SELECT id, option_text, sort_order FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order, option_text");
$noteOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Query: Total money received from orders by payment method (note option)
// Only paid, non-cancelled, non-returned orders
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(NULLIF(TRIM(o.payment_method), ''), '(No method)') AS payment_method,
        COUNT(*) AS order_count,
        COALESCE(SUM(o.total_amount), 0) AS total_amount
    FROM orders o
    WHERE o.status = 'paid'
      AND o.is_cancelled = 0
      AND o.is_returned = 0
      AND (COALESCE(o.payment_date, DATE(o.created_at)) BETWEEN ? AND ?)
    GROUP BY COALESCE(NULLIF(TRIM(o.payment_method), ''), '(No method)')
    ORDER BY total_amount DESC
");
$stmt->execute([$from_date, $to_date]);
$byPaymentMethod = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build a map: payment_method => [order_count, total_amount]
$methodTotals = [];
foreach ($byPaymentMethod as $row) {
    $methodTotals[$row['payment_method']] = [
        'order_count' => (int) $row['order_count'],
        'total_amount' => (float) $row['total_amount'],
    ];
}

// Merge with note options so we show all options (including $0)
// First add any from note_options not yet in results
$displayRows = [];
foreach ($noteOptions as $opt) {
    $text = trim($opt['option_text'] ?? '');
    if ($text === '') continue;
    $displayRows[] = [
        'payment_method' => $text,
        'order_count'   => $methodTotals[$text]['order_count'] ?? 0,
        'total_amount'  => $methodTotals[$text]['total_amount'] ?? 0,
    ];
}
// Then add payment methods from orders that might not be in note_options (e.g. old/renamed)
foreach ($methodTotals as $method => $data) {
    if ($method === '(No method)') continue;
    $exists = false;
    foreach ($noteOptions as $opt) {
        if (trim($opt['option_text'] ?? '') === $method) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $displayRows[] = [
            'payment_method' => $method,
            'order_count'   => $data['order_count'],
            'total_amount'  => $data['total_amount'],
        ];
    }
}
// Add "(No method)" row if any
if (isset($methodTotals['(No method)'])) {
    $displayRows[] = [
        'payment_method' => '(No method)',
        'order_count'   => $methodTotals['(No method)']['order_count'],
        'total_amount'  => $methodTotals['(No method)']['total_amount'],
    ];
}

// Sort by total_amount descending
usort($displayRows, function ($a, $b) {
    return $b['total_amount'] <=> $a['total_amount'];
});

$grandTotalOrders = array_sum(array_column($displayRows, 'order_count'));
$grandTotalAmount = array_sum(array_column($displayRows, 'total_amount'));

// Cash flow spending (outflows) - check if table exists
$totalSpending = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cashflow_spending WHERE spending_date BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $totalSpending = (float) $stmt->fetchColumn();
} catch (PDOException $e) {}

// Finance spending also reduces closing balance.
$financeSpendingPeriod = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_spending WHERE DATE(spending_date) BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $financeSpendingPeriod = (float)$stmt->fetchColumn();
} catch (PDOException $e) {}
$totalSpendingForBalance = $totalSpending + $financeSpendingPeriod;

// Top up (cashflow_topups)
$totalTopup = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cashflow_topups WHERE topup_date BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $totalTopup = (float) $stmt->fetchColumn();
} catch (PDOException $e) {}
// Finance topups are also part of inflow for closing.
$financeTopupPeriod = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_topups WHERE DATE(topup_date) BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $financeTopupPeriod = (float)$stmt->fetchColumn();
} catch (PDOException $e) {}
$totalTopup += $financeTopupPeriod;

$netCashflow = $grandTotalAmount + $totalTopup - $totalSpendingForBalance;

require_once __DIR__ . '/../layout/header.php';
?>
<style>
.cashflow-card { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
.cashflow-card h5 { margin: 0 0 10px 0; font-size: 1rem; opacity: 0.95; }
.cashflow-card .amount { font-size: 1.8rem; font-weight: bold; }
.cashflow-out { background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%); color: white; }
.cashflow-net { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; }
@media (max-width: 768px) { .cashflow-card .amount { font-size: 1.5rem; } }
</style>

<div class="container-fluid py-3">
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-cash-stack me-2"></i>Cashflow – Money by Payment Method</h2>
            <p class="text-muted">Money in (orders + top up) and money out (spending). <?php if (function_exists('has_permission') && has_permission('cashflow_topup.view')): ?><a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_topup_add.php">Top Up</a><?php endif; ?> · <?php if (function_exists('has_permission') && has_permission('cashflow_categories.view')): ?><a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_categories.php">Categories</a><?php endif; ?><?php if (function_exists('has_permission') && has_permission('cashflow_spending.view')): ?> · <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_add_spending.php">Add Spending</a><?php endif; ?></p>
        </div>
    </div>

    <!-- Filter -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">From Date</label>
                    <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To Date</label>
                    <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="cashflow.php" class="btn btn-outline-secondary ms-2">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3 col-lg-2">
            <div class="cashflow-card">
                <h5><i class="bi bi-arrow-down-circle me-2"></i>Inflows (Orders)</h5>
                <div class="amount">$<?= number_format($grandTotalAmount, 2) ?></div>
                <small><?= $grandTotalOrders ?> paid orders</small>
            </div>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <div class="cashflow-card" style="background: linear-gradient(135deg, #0ea5e9 0%, #38bdf8 100%);">
                <h5><i class="bi bi-wallet2 me-2"></i>Top Up</h5>
                <div class="amount">$<?= number_format($totalTopup, 2) ?></div>
                <small><?php if (function_exists('has_permission') && has_permission('cashflow_topup.view')): ?><a href="cashflow_topup_add.php" class="text-white text-decoration-underline">Add top up</a><?php else: ?>—<?php endif; ?></small>
            </div>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <div class="cashflow-card cashflow-out">
                <h5><i class="bi bi-arrow-up-circle me-2"></i>Outflows (Spending)</h5>
                <div class="amount">$<?= number_format($totalSpending, 2) ?></div>
                <small>
                    <?php if (function_exists('has_permission') && has_permission('cashflow_spending.view')): ?><a href="cashflow_add_spending.php" class="text-white text-decoration-underline">Add spending</a><?php else: ?>—<?php endif; ?>
                </small>
            </div>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <div class="cashflow-card cashflow-net">
                <h5><i class="bi bi-graph-up me-2"></i>Net Cash Flow</h5>
                <div class="amount">$<?= number_format($netCashflow, 2) ?></div>
                <small>Orders + Top Up − Spending</small>
            </div>
        </div>
        <div class="col-6 col-md-2 col-lg-2">
            <div class="card bg-light border">
                <div class="card-body py-2">
                    <h6 class="card-title mb-0"><i class="bi bi-calendar-range me-1"></i>Date Range</h6>
                    <small class="text-muted"><?= htmlspecialchars($from_date) ?> to <?= htmlspecialchars($to_date) ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Table: Money by Note Option (Payment Method) -->
    <div class="card shadow-sm">
        <div class="card-header">
            <strong><i class="bi bi-tags me-2"></i>Money Received by Note Option (Payment Method)</strong>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Payment Method (Note Option)</th>
                            <th class="text-center">Orders</th>
                            <th class="text-end">Total Received</th>
                            <th class="text-end">% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($displayRows)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">No paid orders in this date range.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($displayRows as $row): ?>
                                <?php
                                $pct = $grandTotalAmount > 0 ? ($row['total_amount'] / $grandTotalAmount) * 100 : 0;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['payment_method']) ?></td>
                                    <td class="text-center"><?= (int) $row['order_count'] ?></td>
                                    <td class="text-end fw-semibold">$<?= number_format($row['total_amount'], 2) ?></td>
                                    <td class="text-end"><?= number_format($pct, 1) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($displayRows)): ?>
                    <tfoot class="table-light">
                        <tr>
                            <th>Total</th>
                            <th class="text-center"><?= $grandTotalOrders ?></th>
                            <th class="text-end fw-bold">$<?= number_format($grandTotalAmount, 2) ?></th>
                            <th class="text-end">100%</th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
