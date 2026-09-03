<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['cashier', 'admin'], 'print_history.view');
require_once __DIR__ . '/../db.php';

$pdo  = get_db_connection();
$user = current_user();

// Get search parameters
$search = $_GET['search'] ?? '';

// Build query with search
$query = 'SELECT pj.*, o.order_code, o.customer_name, u.name AS cashier_name, s.name AS seller_name
          FROM print_jobs pj
          JOIN orders o ON pj.order_id = o.id
          JOIN users u ON pj.cashier_id = u.id
          JOIN users s ON o.seller_id = s.id';

$params = [];
if (!empty($search)) {
    $query .= ' WHERE (o.order_code LIKE ? OR o.customer_name LIKE ? OR s.name LIKE ? OR u.name LIKE ?)';
    $searchParam = '%' . $search . '%';
    $params = [$searchParam, $searchParam, $searchParam, $searchParam];
}

$query .= ' ORDER BY pj.printed_at DESC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Print History</h1>
    </div>

    <!-- Search Form -->
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Search Orders</label>
                    <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Order code, customer, seller, or cashier...">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Search
                    </button>
                    <?php if (!empty($search)): ?>
                        <a href="print_history.php" class="btn btn-outline-secondary ms-2">
                            <i class="bi bi-x-circle"></i> Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive table-responsive-full">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th>Printed At</th>
                            <th>Order Code</th>
                            <th>Customer</th>
                            <th>Seller</th>
                            <th>Cashier</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$jobs): ?>
                        <tr><td colspan="7" class="text-center py-4">No print history yet.</td></tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($jobs as $j): ?>
                        <tr>
                            <td><?= $no++; ?></td>
                            <td><?= htmlspecialchars($j['printed_at']) ?></td>
                            <td><?= htmlspecialchars($j['order_code']) ?></td>
                            <td><?= htmlspecialchars($j['customer_name']) ?></td>
                            <td><?= htmlspecialchars($j['seller_name']) ?></td>
                            <td><?= htmlspecialchars($j['cashier_name']) ?></td>
                            <td class="d-flex gap-1">
                                <a href="../receipt.php?id=<?= (int)$j['order_id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm">View</a>
                                <a href="../receipt.php?id=<?= (int)$j['order_id'] ?>&from=reprint" target="_blank" class="btn btn-outline-primary btn-sm">Reprint</a>
                            </td>
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
