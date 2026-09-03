<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'delivery_types.view');

$pdo = get_db_connection();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        require_role_or_permission(['admin'], 'delivery_types.create');
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Delivery type name is required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO delivery_types (name) VALUES (?)');
            $stmt->execute([$name]);
            $success = 'Delivery type added.';
        }
    } elseif ($action === 'update') {
        require_role_or_permission(['admin'], 'delivery_types.update');
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            $stmt = $pdo->prepare('UPDATE delivery_types SET name = ? WHERE id = ?');
            $stmt->execute([$name, $id]);
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
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$types): ?>
                        <tr><td colspan="3" class="text-center py-4">No delivery types found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($types as $t): ?>
                        <tr>
                            <td><?= (int)$t['id'] ?></td>
                            <td><?= htmlspecialchars($t['name']) ?></td>
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
