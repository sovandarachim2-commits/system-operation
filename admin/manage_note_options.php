<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'note_options.view');

$pdo = get_db_connection();
$user = current_user();

$errors = [];
$success = '';

// Ensure table columns match React report
try {
    $noteColsResult = $pdo->query("SHOW COLUMNS FROM note_options");
    $noteCols = $noteColsResult->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('title', $noteCols, true)) {
        $pdo->exec("ALTER TABLE note_options ADD COLUMN title VARCHAR(255) DEFAULT NULL AFTER id");
    }
    if (!in_array('category', $noteCols, true)) {
        $pdo->exec("ALTER TABLE note_options ADD COLUMN category VARCHAR(100) DEFAULT 'Payment Remark' AFTER title");
    }
    if (!in_array('status', $noteCols, true)) {
        $pdo->exec("ALTER TABLE note_options ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
    }
    if (!in_array('created_by', $noteCols, true)) {
        $pdo->exec("ALTER TABLE note_options ADD COLUMN created_by INT DEFAULT NULL");
    }
    if (!in_array('updated_by', $noteCols, true)) {
        $pdo->exec("ALTER TABLE note_options ADD COLUMN updated_by INT DEFAULT NULL");
    }
} catch (PDOException $e) {
    // Table might not exist or other DB error
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        require_role_or_permission(['admin'], 'note_options.create');
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? 'Payment Remark');
        $optionText = trim($_POST['option_text'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($optionText)) {
            $errors[] = 'Option text is required.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO note_options (title, category, option_text, status, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $category, $optionText, $status, $user['id'], $user['id']]);
            $success = 'Note option added successfully.';
        }
    } elseif ($action === 'edit') {
        require_role_or_permission(['admin'], 'note_options.update');
        $id = (int)$_POST['id'];
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? 'Payment Remark');
        $optionText = trim($_POST['option_text'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($optionText) || $id <= 0) {
            $errors[] = 'Valid option text and ID are required.';
        } else {
            $stmt = $pdo->prepare("UPDATE note_options SET title = ?, category = ?, option_text = ?, status = ?, updated_by = ? WHERE id = ?");
            $stmt->execute([$title, $category, $optionText, $status, $user['id'], $id]);
            $success = 'Note option updated successfully.';
        }
    } elseif ($action === 'delete') {
        require_role_or_permission(['admin'], 'note_options.delete');
        $id = (int)$_POST['id'];
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM note_options WHERE id = ?");
            $stmt->execute([$id]);
            $success = 'Note option deleted successfully.';
        }
    }
}

// Fetch all note options
$stmt = $pdo->query("SELECT * FROM note_options ORDER BY category, title");
$noteOptions = $stmt->fetchAll();

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Manage Note Options</h1>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addNoteOptionModal">+ Add Note Option</button>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endforeach; ?>

    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive table-responsive-full">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th>Template Title</th>
                            <th>Category</th>
                            <th>Note Content</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($noteOptions)): ?>
                        <tr><td colspan="6" class="text-center py-4">No note options found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($noteOptions as $idx => $option): ?>
                        <tr>
                            <td><?= (int)$idx + 1 ?></td>
                            <td><strong><?= htmlspecialchars($option['title'] ?? 'N/A') ?></strong></td>
                            <td><span class="badge bg-info text-dark"><?= htmlspecialchars($option['category'] ?? 'General') ?></span></td>
                            <td><div class="text-truncate" style="max-width: 300px;"><?= htmlspecialchars($option['option_text'] ?? $option['text'] ?? '') ?></div></td>
                            <td>
                                <span class="badge bg-<?= ($option['status'] ?? 'active') === 'active' ? 'success' : 'secondary' ?>">
                                    <?= ucfirst($option['status'] ?? 'active') ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editNoteOptionModal<?= (int)$option['id'] ?>">Edit</button>
                                    <form method="post" onsubmit="return confirm('Delete this note option?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$option['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit Modal -->
                        <div class="modal fade" id="editNoteOptionModal<?= (int)$option['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Note Option</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body d-flex flex-column gap-3">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="id" value="<?= (int)$option['id'] ?>">
                                            <div>
                                                <label class="form-label">Template Title</label>
                                                <input type="text" name="title" class="form-control form-control-lg" value="<?= htmlspecialchars($option['title'] ?? '') ?>" required>
                                            </div>
                                            <div>
                                                <label class="form-label">Category</label>
                                                <select name="category" class="form-select form-select-lg">
                                                    <option value="Payment Remark" <?= ($option['category'] ?? '') === 'Payment Remark' ? 'selected' : '' ?>>Payment Remark</option>
                                                    <option value="Delivery Instruction" <?= ($option['category'] ?? '') === 'Delivery Instruction' ? 'selected' : '' ?>>Delivery Instruction</option>
                                                    <option value="Fulfillment Note" <?= ($option['category'] ?? '') === 'Fulfillment Note' ? 'selected' : '' ?>>Fulfillment Note</option>
                                                    <option value="Return Reason" <?= ($option['category'] ?? '') === 'Return Reason' ? 'selected' : '' ?>>Return Reason</option>
                                                    <option value="Order Flag" <?= ($option['category'] ?? '') === 'Order Flag' ? 'selected' : '' ?>>Order Flag</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Note Content</label>
                                                <textarea name="option_text" class="form-control form-control-lg" rows="3" required><?= htmlspecialchars($option['option_text'] ?? $option['text'] ?? '') ?></textarea>
                                            </div>
                                            <div>
                                                <label class="form-label">Status</label>
                                                <select name="status" class="form-select form-select-lg">
                                                    <option value="active" <?= ($option['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                                    <option value="inactive" <?= ($option['status'] ?? 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
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

<!-- Add Modal -->
<div class="modal fade" id="addNoteOptionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Note Option</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <input type="hidden" name="action" value="add">
                    <div>
                        <label class="form-label">Template Title</label>
                        <input type="text" name="title" class="form-control form-control-lg" placeholder="e.g. ABA Bank Transfer Account" required>
                    </div>
                    <div>
                        <label class="form-label">Category</label>
                        <select name="category" class="form-select form-select-lg">
                            <option value="Payment Remark">Payment Remark</option>
                            <option value="Delivery Instruction">Delivery Instruction</option>
                            <option value="Fulfillment Note">Fulfillment Note</option>
                            <option value="Return Reason">Return Reason</option>
                            <option value="Order Flag">Order Flag</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Note Content</label>
                        <textarea name="option_text" class="form-control form-control-lg" rows="3" placeholder="Enter pre-configured note text..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-lg">Add Option</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
