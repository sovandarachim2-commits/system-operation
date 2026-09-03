<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'note_options.view');
require_once __DIR__ . '/../config.php';

// Create database connection
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$errors = [];
$success = '';

// Ensure note_options has a dedicated finance default flag.
$noteCols = [];
$noteColsResult = $conn->query("SHOW COLUMNS FROM note_options");
if ($noteColsResult) {
    while ($c = $noteColsResult->fetch_assoc()) {
        $noteCols[] = $c['Field'];
    }
}
if (!in_array('is_finance_default', $noteCols, true)) {
    $conn->query("ALTER TABLE note_options ADD COLUMN is_finance_default TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
}
if (!in_array('is_admin_active', $noteCols, true)) {
    $conn->query("ALTER TABLE note_options ADD COLUMN is_admin_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_finance_default");
}
if (!in_array('is_seller_active', $noteCols, true)) {
    $conn->query("ALTER TABLE note_options ADD COLUMN is_seller_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_admin_active");
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        require_role_or_permission(['admin'], 'note_options.create');
        $optionText = trim($_POST['option_text'] ?? '');
        $isAdminActive = isset($_POST['is_admin_active']) ? 1 : 0;
        $isSellerActive = isset($_POST['is_seller_active']) ? 1 : 0;
        
        if (empty($optionText)) {
            $errors[] = 'Option text is required.';
        } else {
            $stmt = $conn->prepare("INSERT INTO note_options (option_text, is_admin_active, is_seller_active) VALUES (?, ?, ?)");
            $stmt->bind_param("sii", $optionText, $isAdminActive, $isSellerActive);
            $stmt->execute();
            $success = 'Note option added successfully.';
        }
    } elseif ($action === 'edit') {
        require_role_or_permission(['admin'], 'note_options.update');
        $id = (int)$_POST['id'];
        $optionText = trim($_POST['option_text'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $isAdminActive = isset($_POST['is_admin_active']) ? 1 : 0;
        $isSellerActive = isset($_POST['is_seller_active']) ? 1 : 0;

        // If global status is OFF, force both role toggles OFF.
        if ($isActive === 0) {
            $isAdminActive = 0;
            $isSellerActive = 0;
        }
        
        if (empty($optionText) || $id <= 0) {
            $errors[] = 'Valid option text and ID are required.';
        } else {
            $stmt = $conn->prepare("UPDATE note_options SET option_text = ?, is_active = ?, is_admin_active = ?, is_seller_active = ? WHERE id = ?");
            $stmt->bind_param("siiii", $optionText, $isActive, $isAdminActive, $isSellerActive, $id);
            $stmt->execute();
            $success = 'Note option updated successfully.';
        }
    } elseif ($action === 'delete') {
        require_role_or_permission(['admin'], 'note_options.delete');
        $id = (int)$_POST['id'];
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM note_options WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $success = 'Note option deleted successfully.';
        }
    } elseif ($action === 'set_finance_default') {
        require_role_or_permission(['admin'], 'note_options.update');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT id FROM note_options WHERE id = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            if ($row) {
                $conn->query("UPDATE note_options SET is_finance_default = 0");
                $stmt = $conn->prepare("UPDATE note_options SET is_finance_default = 1 WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $success = 'Finance default bank updated.';
            } else {
                $errors[] = 'Selected option must be active to set as finance default.';
            }
        }
    } elseif ($action === 'toggle_global_status') {
        require_role_or_permission(['admin'], 'note_options.update');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE note_options
                SET
                    is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END,
                    is_admin_active = CASE WHEN is_active = 1 THEN 0 ELSE is_admin_active END,
                    is_seller_active = CASE WHEN is_active = 1 THEN 0 ELSE is_seller_active END
                WHERE id = ?
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $success = 'Global status updated.';
        }
    } elseif ($action === 'toggle_admin_status') {
        require_role_or_permission(['admin'], 'note_options.update');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE note_options SET is_admin_active = CASE WHEN is_admin_active = 1 THEN 0 ELSE 1 END WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $success = 'Admin status updated.';
        }
    } elseif ($action === 'toggle_seller_status') {
        require_role_or_permission(['admin'], 'note_options.update');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE note_options SET is_seller_active = CASE WHEN is_seller_active = 1 THEN 0 ELSE 1 END WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $success = 'Seller status updated.';
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: manage_note_options.php");
    exit;
}

// Fetch all note options
$noteOptions = [];
$result = $conn->query("SELECT * FROM note_options ORDER BY option_text");
if ($result) {
    $noteOptions = $result->fetch_all(MYSQLI_ASSOC);
}

$financeDefaultBank = '';
foreach ($noteOptions as $option) {
    if (!empty($option['is_finance_default']) && !empty($option['is_active'])) {
        $financeDefaultBank = trim((string)$option['option_text']);
        break;
    }
}
if ($financeDefaultBank === '') {
    foreach ($noteOptions as $option) {
        if (!empty($option['is_active'])) {
            $financeDefaultBank = trim((string)$option['option_text']);
            break;
        }
    }
}

include __DIR__ . '/../layout/header.php';
?>
<style>
.status-switch {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.status-switch .switch-pill {
    width: 56px;
    height: 30px;
    border-radius: 999px;
    position: relative;
    display: inline-block;
    transition: all 0.2s ease;
}
.status-switch .switch-pill::after {
    content: "";
    position: absolute;
    top: 3px;
    left: 3px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #fff;
    transition: transform 0.2s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.status-switch.active .switch-pill {
    background: #16a34a;
}
.status-switch.active .switch-pill::after {
    transform: translateX(26px);
}
.status-switch.inactive .switch-pill {
    background: #dc2626;
}
.status-switch .switch-label {
    font-size: 0.75rem;
    font-weight: 700;
    color: #fff;
    position: absolute;
    left: 8px;
    top: 50%;
    transform: translateY(-50%);
    letter-spacing: 0.03em;
}
.status-switch.inactive .switch-label {
    left: auto;
    right: 8px;
}
.role-switch {
    display: inline-flex;
    align-items: center;
}
.role-switch .switch-pill {
    width: 52px;
    height: 28px;
    border-radius: 999px;
    position: relative;
    display: inline-block;
}
.role-switch .switch-pill::after {
    content: "";
    position: absolute;
    top: 3px;
    left: 3px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.role-switch.on .switch-pill {
    background: #16a34a;
}
.role-switch.on .switch-pill::after {
    transform: translateX(24px);
}
.role-switch.off .switch-pill {
    background: #dc2626;
}
.role-switch .switch-label {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.03em;
}
.role-switch.on .switch-label {
    left: 8px;
}
.role-switch.off .switch-label {
    right: 8px;
}
.switch-toggle-btn {
    border: none;
    background: transparent;
    padding: 0;
    line-height: 1;
    cursor: pointer;
}
</style>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Manage Note Options</h1>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addNoteOptionModal">+ Add Note Option</button>
        </div>
    </div>
    <div class="alert alert-info py-2">
        Finance default bank: <strong><?= htmlspecialchars($financeDefaultBank !== '' ? $financeDefaultBank : 'First active option') ?></strong>
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
                            <th>Option Text</th>
                            <th>Status</th>
                            <th>Admin</th>
                            <th>Seller</th>
                            <th>Finance Default</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($noteOptions)): ?>
                        <tr><td colspan="8" class="text-center py-4">No note options found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($noteOptions as $idx => $option): ?>
                        <tr>
                            <td><?= (int)$idx + 1 ?></td>
                            <td>
                                <span class="fw-medium"><?= htmlspecialchars($option['option_text']) ?></span>
                                <?php if ($financeDefaultBank !== '' && $financeDefaultBank === $option['option_text']): ?>
                                    <span class="badge bg-primary ms-2">Finance Default</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="m-0">
                                    <input type="hidden" name="action" value="toggle_global_status">
                                    <input type="hidden" name="id" value="<?= (int)$option['id'] ?>">
                                    <button type="submit" class="switch-toggle-btn" title="Toggle Global Status">
                                        <?php if ($option['is_active']): ?>
                                            <span class="status-switch active">
                                                <span class="switch-pill">
                                                    <span class="switch-label">ON</span>
                                                </span>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-switch inactive">
                                                <span class="switch-pill">
                                                    <span class="switch-label">OFF</span>
                                                </span>
                                            </span>
                                        <?php endif; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <form method="post" class="m-0">
                                    <input type="hidden" name="action" value="toggle_admin_status">
                                    <input type="hidden" name="id" value="<?= (int)$option['id'] ?>">
                                    <button type="submit" class="switch-toggle-btn" title="Toggle Admin Status">
                                        <?php if (!empty($option['is_admin_active'])): ?>
                                            <span class="role-switch on">
                                                <span class="switch-pill"><span class="switch-label">ON</span></span>
                                            </span>
                                        <?php else: ?>
                                            <span class="role-switch off">
                                                <span class="switch-pill"><span class="switch-label">OFF</span></span>
                                            </span>
                                        <?php endif; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <form method="post" class="m-0">
                                    <input type="hidden" name="action" value="toggle_seller_status">
                                    <input type="hidden" name="id" value="<?= (int)$option['id'] ?>">
                                    <button type="submit" class="switch-toggle-btn" title="Toggle Seller Status">
                                        <?php if (!empty($option['is_seller_active'])): ?>
                                            <span class="role-switch on">
                                                <span class="switch-pill"><span class="switch-label">ON</span></span>
                                            </span>
                                        <?php else: ?>
                                            <span class="role-switch off">
                                                <span class="switch-pill"><span class="switch-label">OFF</span></span>
                                            </span>
                                        <?php endif; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <?php if (!empty($option['is_finance_default'])): ?>
                                    <span class="badge bg-primary">Default</span>
                                <?php else: ?>
                                    <span class="text-muted">No</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M j, Y', strtotime($option['created_at'])) ?></td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-outline-primary btn-sm" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editNoteOptionModal<?= (int)$option['id'] ?>">
                                        Edit
                                    </button>
                                    <?php if (!empty($option['is_active'])): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="set_finance_default">
                                        <input type="hidden" name="id" value="<?= (int)$option['id'] ?>">
                                        <button type="submit" class="btn btn-outline-success btn-sm">Set Default Finance</button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Are you sure you want to delete this note option?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$option['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit Note Option Modal -->
                        <div class="modal fade" id="editNoteOptionModal<?= (int)$option['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit Note Option</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="post">
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="id" value="<?= (int)$option['id'] ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Option Text</label>
                                                <input type="text" name="option_text" class="form-control" 
                                                       value="<?= htmlspecialchars($option['option_text']) ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <div class="form-check">
                                                    <input type="checkbox" name="is_active" id="editIsActive<?= (int)$option['id'] ?>" 
                                                           class="form-check-input" <?= $option['is_active'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="editIsActive<?= (int)$option['id'] ?>">
                                                        Active
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label mb-1">Role Visibility</label>
                                                <div class="form-check">
                                                    <input type="checkbox" name="is_admin_active" id="editIsAdminActive<?= (int)$option['id'] ?>"
                                                           class="form-check-input" <?= !empty($option['is_admin_active']) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="editIsAdminActive<?= (int)$option['id'] ?>">Show for Admin</label>
                                                </div>
                                                <div class="form-check">
                                                    <input type="checkbox" name="is_seller_active" id="editIsSellerActive<?= (int)$option['id'] ?>"
                                                           class="form-check-input" <?= !empty($option['is_seller_active']) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="editIsSellerActive<?= (int)$option['id'] ?>">Show for Seller</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Save Changes</button>
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

    <!-- Add Note Option Modal -->
    <div class="modal fade" id="addNoteOptionModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Note Option</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">Option Text</label>
                            <input type="text" name="option_text" class="form-control" 
                                   placeholder="Enter option text" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label mb-1">Role Visibility</label>
                            <div class="form-check">
                                <input type="checkbox" name="is_admin_active" id="addIsAdminActive" class="form-check-input" checked>
                                <label class="form-check-label" for="addIsAdminActive">Show for Admin</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="is_seller_active" id="addIsSellerActive" class="form-check-input" checked>
                                <label class="form-check-label" for="addIsSellerActive">Show for Seller</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Option</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
