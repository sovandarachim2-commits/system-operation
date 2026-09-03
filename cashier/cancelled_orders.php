<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['cashier', 'admin'], 'cancelled_orders.view');
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();

$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';
$params = [];
$where  = 'WHERE o.is_cancelled = 1';

if ($start_date !== '') {
    $where   .= ' AND DATE(o.created_at) >= ?';
    $params[] = $start_date;
}
if ($end_date !== '') {
    $where   .= ' AND DATE(o.created_at) <= ?';
    $params[] = $end_date;
}

$sql = "SELECT o.*, u.name AS seller_name, p.name AS page_name
        FROM orders o
        JOIN users u ON o.seller_id = u.id
        LEFT JOIN pages p ON o.page_id = p.id
        $where
        ORDER BY o.created_at DESC
        LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Cancelled Orders (Cashier)</h1>
    </div>

    <form method="get" class="card shadow-sm mb-3">
        <div class="card-body row g-3 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label">From</label>
                <input type="date" name="start_date" class="form-control form-control-lg" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">To</label>
                <input type="date" name="end_date" class="form-control form-control-lg" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="col-12 col-md-3 d-grid">
                <button type="submit" class="btn btn-outline-primary btn-lg">Filter</button>
            </div>
        </div>
    </form>

    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-body p-0 d-flex flex-column">
            <div class="table-responsive table-responsive-full">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Time</th>
                            <th>Order Code</th>
                            <th>Seller</th>
                            <th>Page</th>
                            <th>Customer</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$orders): ?>
                        <tr><td colspan="6" class="text-center py-3">No cancelled orders.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $o): ?>
                        <tr>
                            <td><?= htmlspecialchars($o['created_at']) ?></td>
                            <td><?= htmlspecialchars($o['order_code']) ?></td>
                            <td><?= htmlspecialchars($o['seller_name']) ?></td>
                            <td><?= htmlspecialchars($o['page_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($o['customer_name']) ?></td>
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