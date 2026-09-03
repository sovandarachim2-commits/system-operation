<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'delivery_costs.view');

$pdo = get_db_connection();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        require_role_or_permission(['admin'], 'delivery_costs.create');
        $label  = trim($_POST['label'] ?? '');
        $amount = trim($_POST['amount'] ?? '0');
        if ($label === '') {
            $errors[] = 'Label is required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO delivery_costs (label, amount) VALUES (?, ?)');
            $stmt->execute([$label, $amount]);
            $success = 'Delivery cost added.';
        }
    } elseif ($action === 'update') {
        require_role_or_permission(['admin'], 'delivery_costs.update');
        $id     = (int)($_POST['id'] ?? 0);
        $label  = trim($_POST['label'] ?? '');
        $amount = trim($_POST['amount'] ?? '0');
        if ($id > 0 && $label !== '') {
            $stmt = $pdo->prepare('UPDATE delivery_costs SET label = ?, amount = ? WHERE id = ?');
            $stmt->execute([$label, $amount, $id]);
            $success = 'Delivery cost updated.';
        }
    } elseif ($action === 'delete') {
        require_role_or_permission(['admin'], 'delivery_costs.delete');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM delivery_costs WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'Delivery cost deleted.';
        }
    }
}

$stmt = $pdo->query('SELECT * FROM delivery_costs ORDER BY amount ASC');
$costs = $stmt->fetchAll();

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Delivery Costs</h1>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addCostModal">+ Add Cost</button>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive table-responsive-full">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Label</th>
                            <th>Amount</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$costs): ?>
                        <tr><td colspan="4" class="text-center py-4">No delivery costs found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($costs as $c): ?>
                        <tr>
                            <td><?= (int)$c['id'] ?></td>
                            <td><?= htmlspecialchars($c['label']) ?></td>
                            <td>$<?= number_format((float)$c['amount'], 2) ?></td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editCostModal<?= (int)$c['id'] ?>">Edit</button>
                                    <form method="post" onsubmit="return confirm('Delete this delivery cost?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit Cost Modal -->
                        <div class="modal fade" id="editCostModal<?= (int)$c['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Delivery Cost</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body d-flex flex-column gap-3">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <div>
                                                <label class="form-label">Label</label>
                                                <input type="text" name="label" class="form-control form-control-lg" value="<?= htmlspecialchars($c['label']) ?>" required>
                                            </div>
                                            <div>
                                                <label class="form-label">Amount</label>
                                                <input type="number" step="0.01" name="amount" class="form-control form-control-lg" value="<?= htmlspecialchars($c['amount']) ?>" required>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary btn-lg">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Cost Modal -->
<div class="modal fade" id="addCostModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add Delivery Cost</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="form-label">Label (e.g. 0$, 1$)</label>
                        <input type="text" name="label" class="form-control form-control-lg" required>
                    </div>
                    <div>
                        <label class="form-label">Amount</label>
                        <input type="number" step="0.01" name="amount" class="form-control form-control-lg" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-lg">Save Cost</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
