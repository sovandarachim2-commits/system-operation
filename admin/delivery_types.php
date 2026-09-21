<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'delivery_types.view');

$pdo = get_db_connection();

$errors = [];
$success = '';

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        require_role_or_permission(['admin'], 'delivery_types.create');
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $fee = (float)($_POST['fee'] ?? 0);
        $tracking_format = trim($_POST['tracking_format'] ?? '');
        $order_display = (int)($_POST['order_display'] ?? 0);
        
        if ($name === '') {
            $errors[] = 'Delivery type name is required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO delivery_types (name, code, fee, tracking_format, order_display, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $code, $fee, $tracking_format, $order_display, $user['id'], $user['id']]);
            $success = 'Delivery type added.';
        }
    } elseif ($action === 'update') {
        require_role_or_permission(['admin'], 'delivery_types.update');
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $fee = (float)($_POST['fee'] ?? 0);
        $tracking_format = trim($_POST['tracking_format'] ?? '');
        $order_display = (int)($_POST['order_display'] ?? 0);
        $status = $_POST['status'] ?? 'active';

        if ($id > 0 && $name !== '') {
            $stmt = $pdo->prepare('UPDATE delivery_types SET name = ?, code = ?, fee = ?, tracking_format = ?, order_display = ?, status = ?, updated_by = ? WHERE id = ?');
            $stmt->execute([$name, $code, $fee, $tracking_format, $order_display, $status, $user['id'], $id]);
            $success = 'Delivery type updated.';
        }
    } elseif ($action === 'delete') {
        require_role_or_permission(['admin'], 'delivery_types.delete');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM delivery_types WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'Delivery type deleted.';
        }
    }
}

$stmt = $pdo->query('SELECT * FROM delivery_types ORDER BY id DESC');
$types = $stmt->fetchAll();

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Delivery Types</h1>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addTypeModal">+ Add Type</button>
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
                            <th>Name</th>
                            <th>Method Code</th>
                            <th>Default Fee</th>
                            <th>Order</th>
                            <th>Tracking Number Pattern</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$types): ?>
                        <tr><td colspan="7" class="text-center py-4">No delivery types found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($types as $t): ?>
                        <tr>
                            <td><?= (int)$t['id'] ?></td>
                            <td><?= htmlspecialchars($t['name']) ?></td>
                            <td><code><?= htmlspecialchars($t['code'] ?? '') ?></code></td>
                            <td>$<?= number_format((float)($t['fee'] ?? 0), 2) ?></td>
                            <td><?= (int)($t['order_display'] ?? 0) ?></td>
                            <td><code><?= htmlspecialchars($t['tracking_format'] ?? '') ?></code></td>
                            <td>
                                <span class="badge bg-<?= $t['status'] === 'active' ? 'success' : 'secondary' ?>">
                                    <?= ucfirst($t['status'] ?? 'active') ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editTypeModal<?= (int)$t['id'] ?>">Edit</button>
                                    <form method="post" onsubmit="return confirm('Delete this delivery type?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit Type Modal -->
                        <div class="modal fade" id="editTypeModal<?= (int)$t['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Delivery Type</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body d-flex flex-column gap-3">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                            <div>
                                                <label class="form-label">Name</label>
                                                <input type="text" name="name" class="form-control form-control-lg" value="<?= htmlspecialchars($t['name']) ?>" required>
                                            </div>
                                            <div>
                                                <label class="form-label">Method Code</label>
                                                <input type="text" name="code" class="form-control form-control-lg" value="<?= htmlspecialchars($t['code'] ?? '') ?>">
                                            </div>
                                            <div>
                                                <label class="form-label">Default Fee ($)</label>
                                                <input type="number" step="0.01" name="fee" class="form-control form-control-lg" value="<?= htmlspecialchars((string)($t['fee'] ?? 0)) ?>">
                                            </div>
                                            <div>
                                                <label class="form-label">Display Order</label>
                                                <input type="number" name="order_display" class="form-control form-control-lg" value="<?= (int)($t['order_display'] ?? 0) ?>">
                                            </div>
                                            <div>
                                                <label class="form-label">Tracking Number Pattern</label>
                                                <input type="text" name="tracking_format" class="form-control form-control-lg" value="<?= htmlspecialchars($t['tracking_format'] ?? '') ?>">
                                            </div>
                                            <div>
                                                <label class="form-label">Status</label>
                                                <select name="status" class="form-select form-select-lg">
                                                    <option value="active" <?= ($t['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                                    <option value="inactive" <?= ($t['status'] ?? 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                </select>
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

<!-- Add Type Modal -->
<div class="modal fade" id="addTypeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add Delivery Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control form-control-lg" required>
                    </div>
                    <div>
                        <label class="form-label">Method Code</label>
                        <input type="text" name="code" class="form-control form-control-lg" placeholder="e.g. VET-EXPRESS">
                    </div>
                    <div>
                        <label class="form-label">Default Fee ($)</label>
                        <input type="number" step="0.01" name="fee" class="form-control form-control-lg" value="0.00">
                    </div>
                    <div>
                        <label class="form-label">Display Order</label>
                        <input type="number" name="order_display" class="form-control form-control-lg" value="0">
                    </div>
                    <div>
                        <label class="form-label">Tracking Number Pattern</label>
                        <input type="text" name="tracking_format" class="form-control form-control-lg" placeholder="VET-{ORDER_CODE}">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-lg">Save Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
