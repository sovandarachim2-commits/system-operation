<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'activity.view');
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();

$selected_from_date = $_GET['from_date'] ?? date('Y-m-d');
$selected_to_date = $_GET['to_date'] ?? date('Y-m-d');
$errors = [];

// Validate date format (simple check)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_from_date)) {
    $selected_from_date = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_to_date)) {
    $selected_to_date = date('Y-m-d');
}

// Ensure from_date is not after to_date
if ($selected_from_date > $selected_to_date) {
    $temp = $selected_from_date;
    $selected_from_date = $selected_to_date;
    $selected_to_date = $temp;
}

// Per-seller summary for the date range
$summaryStmt = $pdo->prepare('SELECT u.id, u.name, u.role,
    COUNT(o.id) AS order_count,
    SUM(CASE WHEN o.is_cancelled = 1 THEN 1 ELSE 0 END) AS cancelled_count,
    SUM(o.total_amount) AS total_amount,
    SUM(CASE WHEN o.status = "paid" THEN o.total_amount ELSE 0 END) AS paid_amount,
    SUM(CASE WHEN o.status = "unpaid" THEN o.total_amount ELSE 0 END) AS unpaid_amount
  FROM orders o
  JOIN users u ON o.seller_id = u.id
  WHERE DATE(o.created_at) BETWEEN ? AND ?
  GROUP BY u.id, u.name, u.role
  ORDER BY u.name');
$summaryStmt->execute([$selected_from_date, $selected_to_date]);
$summaries = $summaryStmt->fetchAll();

// Page summary for the date range
$pageSummaryStmt = $pdo->prepare('SELECT p.id, p.name,
    COUNT(o.id) AS order_count,
    SUM(CASE WHEN o.is_cancelled = 1 THEN 1 ELSE 0 END) AS cancelled_count,
    SUM(o.total_amount) AS total_amount,
    SUM(CASE WHEN o.status = "paid" THEN o.total_amount ELSE 0 END) AS paid_amount,
    SUM(CASE WHEN o.status = "unpaid" THEN o.total_amount ELSE 0 END) AS unpaid_amount
  FROM orders o
  LEFT JOIN pages p ON o.page_id = p.id
  WHERE DATE(o.created_at) BETWEEN ? AND ?
  GROUP BY p.id, p.name
  ORDER BY p.name');
$pageSummaryStmt->execute([$selected_from_date, $selected_to_date]);
$pageSummaries = $pageSummaryStmt->fetchAll();

// Detailed orders list for the date range
$detailsStmt = $pdo->prepare('SELECT o.*, u.name AS seller_name
  FROM orders o
  JOIN users u ON o.seller_id = u.id
  WHERE DATE(o.created_at) BETWEEN ? AND ?
  ORDER BY o.created_at DESC');
$detailsStmt->execute([$selected_from_date, $selected_to_date]);
$orders = $detailsStmt->fetchAll();

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column gap-3 flex-grow-1">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">Daily Activity</h1>
        <form method="get" class="d-flex flex-wrap align-items-center gap-2">
            <label class="form-label mb-0">From Date</label>
            <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($selected_from_date) ?>">
            <label class="form-label mb-0">To Date</label>
            <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($selected_to_date) ?>">
            <button type="submit" class="btn btn-primary">View</button>
        </form>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header">
            <strong>Seller summary for <?= htmlspecialchars($selected_from_date) ?> to <?= htmlspecialchars($selected_to_date) ?></strong>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive mb-0">
                <table class="table table-striped mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Seller</th>
                            <th>Role</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Cancelled</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Unpaid</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$summaries): ?>
                        <tr><td colspan="7" class="text-center py-3">No activity for this date.</td></tr>
                    <?php else: ?>
                        <?php foreach ($summaries as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= htmlspecialchars($row['role']) ?></td>
                            <td class="text-end"><?= (int)$row['order_count'] ?></td>
                            <td class="text-end"><?= (int)$row['cancelled_count'] ?></td>
                            <td class="text-end">$<?= number_format($row['total_amount'] ?? 0, 2) ?></td>
                            <td class="text-end">$<?= number_format($row['paid_amount'] ?? 0, 2) ?></td>
                            <td class="text-end">$<?= number_format($row['unpaid_amount'] ?? 0, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header">
            <strong>Page summary for <?= htmlspecialchars($selected_from_date) ?> to <?= htmlspecialchars($selected_to_date) ?></strong>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive mb-0">
                <table class="table table-striped mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Page</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Cancelled</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Unpaid</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$pageSummaries): ?>
                        <tr><td colspan="6" class="text-center py-3">No page activity for this date.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pageSummaries as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['name'] ?? 'Direct/Other') ?></td>
                            <td class="text-end"><?= (int)$row['order_count'] ?></td>
                            <td class="text-end"><?= (int)$row['cancelled_count'] ?></td>
                            <td class="text-end">$<?= number_format($row['total_amount'] ?? 0, 2) ?></td>
                            <td class="text-end">$<?= number_format($row['paid_amount'] ?? 0, 2) ?></td>
                            <td class="text-end">$<?= number_format($row['unpaid_amount'] ?? 0, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-header">
            <strong>Orders for <?= htmlspecialchars($selected_from_date) ?> to <?= htmlspecialchars($selected_to_date) ?></strong>
        </div>
        <div class="card-body p-0 d-flex flex-column">
            <div class="table-responsive table-responsive-full">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Time</th>
                            <th>Order Code</th>
                            <th>Seller</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$orders): ?>
                        <tr><td colspan="6" class="text-center py-3">No orders for this date.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $o): ?>
                        <tr>
                            <td><?= htmlspecialchars($o['created_at']) ?></td>
                            <td><?= htmlspecialchars($o['order_code']) ?></td>
                            <td><?= htmlspecialchars($o['seller_name']) ?></td>
                            <td><?= htmlspecialchars($o['customer_name']) ?></td>
                            <td>
                                <span class="badge <?= $o['status']==='paid' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= strtoupper($o['status']) ?></span>
                            </td>
                            <td class="text-end">$<?= number_format($o['total_amount'], 2) ?></td>
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
