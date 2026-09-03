<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'cashflow.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date   = $_GET['to_date'] ?? date('Y-m-d');

// Total money IN (from orders)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0) 
    FROM orders 
    WHERE status = 'paid' AND is_cancelled = 0 AND is_returned = 0 
    AND (COALESCE(payment_date, DATE(created_at)) BETWEEN ? AND ?)
");
$stmt->execute([$from_date, $to_date]);
$totalMoney = (float) $stmt->fetchColumn();

// Total spending OUT
$totalSpending = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cashflow_spending WHERE spending_date BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $totalSpending = (float) $stmt->fetchColumn();
} catch (PDOException $e) {}

$balance = $totalMoney - $totalSpending;

// Order count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status = 'paid' AND is_cancelled = 0 AND is_returned = 0 AND (COALESCE(payment_date, DATE(created_at)) BETWEEN ? AND ?)");
$stmt->execute([$from_date, $to_date]);
$orderCount = (int) $stmt->fetchColumn();

// Spending count
$spendingCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cashflow_spending WHERE spending_date BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $spendingCount = (int) $stmt->fetchColumn();
} catch (PDOException $e) {}

// Spending by main category
$spendingByCategory = [];
try {
    $stmt = $pdo->prepare("
        SELECT category, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
        FROM cashflow_spending
        WHERE spending_date BETWEEN ? AND ?
        GROUP BY category
        ORDER BY total DESC
    ");
    $stmt->execute([$from_date, $to_date]);
    $spendingByCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Spending by user (spent_by)
$spendingByUser = [];
try {
    $stmt = $pdo->prepare("
        SELECT cs.spent_by, u.name AS user_name,
               COUNT(*) AS cnt, COALESCE(SUM(cs.amount), 0) AS total
        FROM cashflow_spending cs
        LEFT JOIN users u ON cs.spent_by = u.id
        WHERE cs.spending_date BETWEEN ? AND ?
        GROUP BY cs.spent_by, u.name
        ORDER BY total DESC
    ");
    $stmt->execute([$from_date, $to_date]);
    $spendingByUser = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Top spending records (last 10)
$topSpending = [];
try {
    $stmt = $pdo->prepare("
        SELECT cs.*, u.name AS spent_by_name
        FROM cashflow_spending cs
        LEFT JOIN users u ON cs.spent_by = u.id
        WHERE cs.spending_date BETWEEN ? AND ?
        ORDER BY cs.amount DESC
        LIMIT 10
    ");
    $stmt->execute([$from_date, $to_date]);
    $topSpending = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

require_once __DIR__ . '/../layout/header.php';
?>
<style>
.cf-report-banner { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25); }
.cf-report-card { border-radius: 12px; padding: 1.5rem; margin-bottom: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.cf-report-card.money { background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: white; }
.cf-report-card.spending { background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%); color: white; }
.cf-report-card.balance { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; }
.cf-report-card .label { font-size: 0.9rem; opacity: 0.95; }
.cf-report-card .amount { font-size: 2rem; font-weight: 700; }
.cf-report-card .sub { font-size: 0.85rem; opacity: 0.9; margin-top: 0.25rem; }
@media (max-width: 768px) { .cf-report-card .amount { font-size: 1.6rem; } }
</style>
<div class="container-fluid py-4">
    <div class="cf-report-banner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>Cash Flow Report</h1>
            <p class="mb-0 mt-1 opacity-90">Summary, spending by category, and by user</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow.php" class="btn btn-light btn-sm"><i class="bi bi-credit-card me-1"></i>By Payment Method</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_summary.php" class="btn btn-light btn-sm">Summary</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_add_spending.php" class="btn btn-light btn-sm">Add Spending</a>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">From Date</label>
                    <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To Date</label>
                    <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
                    <a href="cashflow_report.php" class="btn btn-outline-secondary ms-2">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="cf-report-card money">
                <div class="label"><i class="bi bi-arrow-down-circle me-1"></i>Total Inflows</div>
                <div class="amount">$<?= number_format($totalMoney, 2) ?></div>
                <div class="sub"><?= $orderCount ?> paid orders</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="cf-report-card spending">
                <div class="label"><i class="bi bi-arrow-up-circle me-1"></i>Total Outflows</div>
                <div class="amount">$<?= number_format($totalSpending, 2) ?></div>
                <div class="sub"><?= $spendingCount ?> spending records</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="cf-report-card balance">
                <div class="label"><i class="bi bi-wallet2 me-1"></i>Balance</div>
                <div class="amount">$<?= number_format($balance, 2) ?></div>
                <div class="sub">Inflows − Outflows</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Spending by Category -->
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header py-3 bg-light">
                    <h5 class="mb-0"><i class="bi bi-tags me-2"></i>Spending by Category</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($spendingByCategory)): ?>
                        <div class="p-4 text-center text-muted">No spending in this period.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Category</th><th class="text-end">Count</th><th class="text-end">Amount</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($spendingByCategory as $r): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($r['category']) ?></td>
                                            <td class="text-end"><?= (int)$r['cnt'] ?></td>
                                            <td class="text-end fw-semibold text-danger">$<?= number_format((float)$r['total'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-bold">
                                        <td>Total</td>
                                        <td class="text-end"><?= $spendingCount ?></td>
                                        <td class="text-end text-danger">$<?= number_format($totalSpending, 2) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Spending by User -->
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header py-3 bg-light">
                    <h5 class="mb-0"><i class="bi bi-person me-2"></i>Spending by User</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($spendingByUser)): ?>
                        <div class="p-4 text-center text-muted">No spending in this period.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>User</th><th class="text-end">Count</th><th class="text-end">Amount</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($spendingByUser as $r): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($r['user_name'] ?? '(No user)') ?></td>
                                            <td class="text-end"><?= (int)$r['cnt'] ?></td>
                                            <td class="text-end fw-semibold text-danger">$<?= number_format((float)$r['total'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-bold">
                                        <td>Total</td>
                                        <td class="text-end"><?= $spendingCount ?></td>
                                        <td class="text-end text-danger">$<?= number_format($totalSpending, 2) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Top 10 Spending Records -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header py-3 bg-light">
                    <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Top 10 Spending (Highest Amounts)</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($topSpending)): ?>
                        <div class="p-4 text-center text-muted">No spending in this period.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Date</th><th>Amount</th><th>Category</th><th>Subcategory</th><th>User</th><th>Note</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topSpending as $r): 
                                        $subs = $r['sub_categories'] ?? '';
                                        if ($subs) {
                                            $arr = json_decode($subs, true);
                                            $subs = is_array($arr) ? implode(', ', $arr) : ($r['sub_category'] ?? '-');
                                        } else {
                                            $subs = $r['sub_category'] ?? '-';
                                        }
                                    ?>
                                        <tr>
                                            <td><?= htmlspecialchars($r['spending_date']) ?></td>
                                            <td class="fw-semibold text-danger">$<?= number_format((float)$r['amount'], 2) ?></td>
                                            <td><?= htmlspecialchars($r['category']) ?></td>
                                            <td class="small"><?= htmlspecialchars($subs) ?></td>
                                            <td><?= htmlspecialchars($r['spent_by_name'] ?? '-') ?></td>
                                            <td class="text-truncate small" style="max-width: 150px;"><?= htmlspecialchars($r['note'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <div class="card bg-light border-0">
                <div class="card-body text-center text-muted py-3">
                    <p class="mb-0"><strong>Report period:</strong> <?= htmlspecialchars($from_date) ?> to <?= htmlspecialchars($to_date) ?></p>
                    <p class="mb-0 small mt-1"><a href="cashflow.php?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>">View by Payment Method</a> · <a href="cashflow_spending_history.php?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>">Spending History</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
