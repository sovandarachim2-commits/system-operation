<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'daily_summary.view');
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();

$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date   = $_GET['end_date'] ?? $start_date;
if ($start_date === '') {
    $start_date = date('Y-m-d');
}
if ($end_date === '') {
    $end_date = $start_date;
}

// Per-seller summary in selected date range
$sql = 'SELECT u.id AS seller_id,
               u.name AS seller_name,
               COUNT(*) AS total_orders,
               SUM(CASE WHEN o.is_cancelled = 1 THEN 1 ELSE 0 END) AS cancelled_orders,
               SUM(CASE WHEN o.is_cancelled = 0 THEN o.total_amount ELSE 0 END) AS active_total_amount,
               SUM(CASE WHEN o.is_cancelled = 0 AND o.status = "paid"   THEN o.total_amount ELSE 0 END) AS active_paid_amount,
               SUM(CASE WHEN o.is_cancelled = 0 AND o.status = "unpaid" THEN o.total_amount ELSE 0 END) AS active_unpaid_amount,
               SUM(CASE WHEN o.is_cancelled = 1 AND o.status = "paid"   THEN o.total_amount ELSE 0 END) AS cancel_paid_amount,
               SUM(CASE WHEN o.is_cancelled = 1 AND o.status = "unpaid" THEN o.total_amount ELSE 0 END) AS cancel_unpaid_amount
        FROM orders o
        JOIN users u ON o.seller_id = u.id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY u.id, u.name
        ORDER BY u.name';
$stmt = $pdo->prepare($sql);
$stmt->execute([$start_date, $end_date]);
$rows = $stmt->fetchAll();

$overall_orders             = 0;
$overall_cancelled          = 0;
$overall_active_amount      = 0.0;
$overall_active_paid        = 0.0;
$overall_active_unpaid      = 0.0;
$overall_cancel_paid_amount = 0.0;
$overall_cancel_unpaid      = 0.0;
foreach ($rows as $r) {
    $overall_orders             += (int)$r['total_orders'];
    $overall_cancelled          += (int)$r['cancelled_orders'];
    $overall_active_amount      += (float)$r['active_total_amount'];
    $overall_active_paid        += (float)$r['active_paid_amount'];
    $overall_active_unpaid      += (float)$r['active_unpaid_amount'];
    $overall_cancel_paid_amount += (float)$r['cancel_paid_amount'];
    $overall_cancel_unpaid      += (float)$r['cancel_unpaid_amount'];
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Daily Seller Summary</h1>
    </div>

    <form method="get" class="card shadow-sm mb-3">
        <div class="card-body row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label">From date</label>
                <input type="date" name="start_date" class="form-control form-control-lg" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">To date</label>
                <input type="date" name="end_date" class="form-control form-control-lg" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="col-12 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary btn-lg w-100">View</button>
                <a href="daily_seller.php" class="btn btn-outline-secondary btn-lg w-100">Today</a>
            </div>
        </div>
    </form>

    <div class="card shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap gap-3">
            <div><strong>Total Orders:</strong> <?= (int)$overall_orders ?></div>
            <div><strong>Cancelled Orders:</strong> <?= (int)$overall_cancelled ?></div>
            <div><strong>Orders Paid:</strong> $<?= number_format($overall_active_paid, 2) ?></div>
            <div><strong>Orders Unpaid:</strong> $<?= number_format($overall_active_unpaid, 2) ?></div>
            <div><strong>Cancel Paid:</strong> $<?= number_format($overall_cancel_paid_amount, 2) ?></div>
            <div><strong>Cancel Unpaid:</strong> $<?= number_format($overall_cancel_unpaid, 2) ?></div>
        </div>
    </div>

    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive table-responsive-full">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th>Seller</th>
                            <th class="text-end">Total Orders</th>
                            <th class="text-end">Cancelled</th>
                            <th class="text-end">Orders Paid</th>
                            <th class="text-end">Orders Unpaid</th>
                            <th class="text-end">Cancel Paid</th>
                            <th class="text-end">Cancel Unpaid</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" class="text-center py-4">No orders for this range.</td></tr>
                    <?php else: ?>
                        <?php $no = 1; ?>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><?= htmlspecialchars($r['seller_name']) ?></td>
                            <td class="text-end"><?= (int)$r['total_orders'] ?></td>
                            <td class="text-end"><?= (int)$r['cancelled_orders'] ?></td>
                            <td class="text-end">$<?= number_format($r['active_paid_amount'], 2) ?></td>
                            <td class="text-end">$<?= number_format($r['active_unpaid_amount'], 2) ?></td>
                            <td class="text-end">$<?= number_format($r['cancel_paid_amount'], 2) ?></td>
                            <td class="text-end">$<?= number_format($r['cancel_unpaid_amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
