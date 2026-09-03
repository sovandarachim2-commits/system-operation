<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'pages.view');

$pdo = get_db_connection();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        require_role_or_permission(['admin'], 'pages.create');
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        if ($name === '' || $slug === '') {
            $errors[] = 'Name and slug are required.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO pages (name, slug) VALUES (?, ?)');
                $stmt->execute([$name, $slug]);
                $success = 'Page added.';
            } catch (PDOException $e) {
                $errors[] = 'Slug must be unique.';
            }
        }
    } elseif ($action === 'update') {
        require_role_or_permission(['admin'], 'pages.update');
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        if ($id > 0 && $name !== '' && $slug !== '') {
            $stmt = $pdo->prepare('UPDATE pages SET name = ?, slug = ? WHERE id = ?');
            $stmt->execute([$name, $slug, $id]);
            $success = 'Page updated.';
        }
    } elseif ($action === 'delete') {
        require_role_or_permission(['admin'], 'pages.delete');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM pages WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'Page deleted.';
        }
    }
}

$stmt = $pdo->query('SELECT * FROM pages ORDER BY id DESC');
$pages = $stmt->fetchAll();

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Pages</h1>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addPageModal">+ Add Page</button>
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
                            <th>Slug</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$pages): ?>
                        <tr><td colspan="4" class="text-center py-4">No pages found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pages as $p): ?>
                        <tr>
                            <td><?= (int)$p['id'] ?></td>
                            <td><?= htmlspecialchars($p['name']) ?></td>
                            <td><?= htmlspecialchars($p['slug']) ?></td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editPageModal<?= (int)$p['id'] ?>">Edit</button>
                                    <form method="post" onsubmit="return confirm('Delete this page?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit Page Modal -->
                        <div class="modal fade" id="editPageModal<?= (int)$p['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Page</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body d-flex flex-column gap-3">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                            <div>
                                                <label class="form-label">Name</label>
                                                <input type="text" name="name" class="form-control form-control-lg" value="<?= htmlspecialchars($p['name']) ?>" required>
                                            </div>
                                            <div>
                                                <label class="form-label">Slug</label>
                                                <input type="text" name="slug" class="form-control form-control-lg" value="<?= htmlspecialchars($p['slug']) ?>" required>
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

<!-- Add Page Modal -->
<div class="modal fade" id="addPageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add Page</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control form-control-lg" required>
                    </div>
                    <div>
                        <label class="form-label">Slug</label>
                        <input type="text" name="slug" class="form-control form-control-lg" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-lg">Save Page</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
