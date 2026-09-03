<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_dashboard.view');

$pdo = get_db_connection();
$success = '';
$errors = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_method') {
        require_role_or_permission(['admin'], 'finance_dashboard.create');
        $method_name = trim($_POST['method_name'] ?? '');
        $method_code = trim($_POST['method_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon'] ?? '');
        $is_default = isset($_POST['is_default']);
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        
        if (empty($method_name)) {
            $errors[] = 'Method name is required.';
        }
        if (empty($method_code)) {
            $errors[] = 'Method code is required.';
        }
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                // If setting as default, unset other defaults
                if ($is_default) {
                    $pdo->query('UPDATE payment_methods SET is_default = FALSE');
                }
                
                $stmt = $pdo->prepare('INSERT INTO payment_methods (method_name, method_code, description, icon, is_default, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$method_name, $method_code, $description, $icon, $is_default, $sort_order, current_user()['id']]);
                
                $pdo->commit();
                $success = "Payment method '$method_name' added successfully.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() == 23000) {
                    $errors[] = 'Method name or code already exists.';
                } else {
                    $errors[] = 'Failed to add payment method: ' . $e->getMessage();
                }
            }
        }
    }
    
    if ($action === 'edit_method') {
        require_role_or_permission(['admin'], 'finance_dashboard.update');
        $method_id = (int)($_POST['method_id'] ?? 0);
        $method_name = trim($_POST['method_name'] ?? '');
        $method_code = trim($_POST['method_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon'] ?? '');
        $is_default = isset($_POST['is_default']);
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        
        if (empty($method_name)) {
            $errors[] = 'Method name is required.';
        }
        if (empty($method_code)) {
            $errors[] = 'Method code is required.';
        }
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                // If setting as default, unset other defaults
                if ($is_default) {
                    $pdo->query('UPDATE payment_methods SET is_default = FALSE WHERE id != ?');
                }
                
                $stmt = $pdo->prepare('UPDATE payment_methods SET method_name = ?, method_code = ?, description = ?, icon = ?, is_default = ?, sort_order = ? WHERE id = ?');
                $stmt->execute([$method_name, $method_code, $description, $icon, $is_default, $sort_order, $method_id]);
                
                $pdo->commit();
                $success = "Payment method updated successfully.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() == 23000) {
                    $errors[] = 'Method name or code already exists.';
                } else {
                    $errors[] = 'Failed to update payment method: ' . $e->getMessage();
                }
            }
        }
    }
    
    if ($action === 'delete_method') {
        require_role_or_permission(['admin'], 'finance_dashboard.delete');
        $method_id = (int)($_POST['method_id'] ?? 0);
        
        try {
            // Check if method is used in payments
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_payments WHERE payment_method = (SELECT method_code FROM payment_methods WHERE id = ?)');
            $stmt->execute([$method_id]);
            $usage_count = $stmt->fetchColumn();
            
            if ($usage_count > 0) {
                $errors[] = 'Cannot delete payment method. It is used in ' . $usage_count . ' payment records.';
            } else {
                $stmt = $pdo->prepare('DELETE FROM payment_methods WHERE id = ?');
                $stmt->execute([$method_id]);
                $success = "Payment method deleted successfully.";
            }
        } catch (PDOException $e) {
            $errors[] = 'Failed to delete payment method: ' . $e->getMessage();
        }
    }
}

// Get all payment methods
try {
    $stmt = $pdo->query('SELECT * FROM payment_methods ORDER BY sort_order, method_name');
    $payment_methods = $stmt->fetchAll();
} catch (PDOException $e) {
    $payment_methods = [];
    $errors[] = 'Failed to load payment methods.';
}

include __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column h-100">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Payment Methods Management</h1>
        <button type="button" class="btn btn-success" onclick="openAddMethodModal()">
            <i class="bi bi-plus-circle me-2"></i>Add New Method
        </button>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $e): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0">Available Payment Methods</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Icon</th>
                            <th>Method Name</th>
                            <th>Code</th>
                            <th>Description</th>
                            <th>Default</th>
                            <th>Order</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payment_methods)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="bi bi-credit-card fs-4 d-block mb-2"></i>
                                        No payment methods found
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payment_methods as $method): ?>
                                <tr class="align-middle">
                                    <td>
                                        <i class="<?= htmlspecialchars($method['icon'] ?? 'bi-credit-card') ?>"></i>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= htmlspecialchars($method['method_name']) ?></span>
                                    </td>
                                    <td>
                                        <code><?= htmlspecialchars($method['method_code']) ?></code>
                                    </td>
                                    <td>
                                        <span class="text-muted small"><?= htmlspecialchars($method['description'] ?? '') ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($method['is_default']): ?>
                                            <span class="badge bg-success">Default</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?= $method['sort_order'] ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $method['is_active'] ? 'success' : 'secondary' ?>">
                                            <?= $method['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-outline-warning" onclick="editMethod(<?= $method['id'] ?>, '<?= htmlspecialchars($method['method_name']) ?>', '<?= htmlspecialchars($method['method_code']) ?>', '<?= htmlspecialchars($method['description'] ?? '') ?>', '<?= htmlspecialchars($method['icon'] ?? '') ?>', <?= $method['is_default'] ? 'true' : 'false' ?>, <?= $method['sort_order'] ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-outline-danger" onclick="deleteMethod(<?= $method['id'] ?>, '<?= htmlspecialchars($method['method_name']) ?>')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
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

<!-- Add Method Modal -->
<div class="modal fade" id="addMethodModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Payment Method</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="add_method">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Method Name *</label>
                        <input type="text" name="method_name" class="form-control" required placeholder="e.g., PayPal">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Method Code *</label>
                        <input type="text" name="method_code" class="form-control" required placeholder="e.g., paypal">
                        <small class="text-muted">Unique identifier used in system</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Brief description of the payment method"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Icon</label>
                        <select name="icon" class="form-select">
                            <option value="bi-cash-stack">💵 Cash</option>
                            <option value="bi-bank">🏦 Bank</option>
                            <option value="bi-journal-text">📄 Check</option>
                            <option value="bi-credit-card">💳 Credit Card</option>
                            <option value="bi-credit-card-2-back">💳 Debit Card</option>
                            <option value="bi-globe">🌐 Online</option>
                            <option value="bi-wallet">👛 Wallet</option>
                            <option value="bi-phone">📱 Mobile</option>
                            <option value="bi-three-dots">📝 Other</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Sort Order</label>
                                <input type="number" name="sort_order" class="form-control" value="0" min="0">
                                <small class="text-muted">Lower numbers appear first</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" name="is_default" id="addIsDefault">
                                    <label class="form-check-label" for="addIsDefault">
                                        Set as default
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-circle me-1"></i>Add Method
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Method Modal -->
<div class="modal fade" id="editMethodModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Payment Method</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="edit_method">
                <input type="hidden" name="method_id" id="editMethodId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Method Name *</label>
                        <input type="text" name="method_name" class="form-control" id="editMethodName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Method Code *</label>
                        <input type="text" name="method_code" class="form-control" id="editMethodCode" required>
                        <small class="text-muted">Unique identifier used in system</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" class="form-control" rows="2" id="editDescription"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Icon</label>
                        <select name="icon" class="form-select" id="editIcon">
                            <option value="bi-cash-stack">💵 Cash</option>
                            <option value="bi-bank">🏦 Bank</option>
                            <option value="bi-journal-text">📄 Check</option>
                            <option value="bi-credit-card">💳 Credit Card</option>
                            <option value="bi-credit-card-2-back">💳 Debit Card</option>
                            <option value="bi-globe">🌐 Online</option>
                            <option value="bi-wallet">👛 Wallet</option>
                            <option value="bi-phone">📱 Mobile</option>
                            <option value="bi-three-dots">📝 Other</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Sort Order</label>
                                <input type="number" name="sort_order" class="form-control" id="editSortOrder" min="0">
                                <small class="text-muted">Lower numbers appear first</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" name="is_default" id="editIsDefault">
                                    <label class="form-check-label" for="editIsDefault">
                                        Set as default
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-pencil me-1"></i>Update Method
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Method Modal -->
<div class="modal fade" id="deleteMethodModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Payment Method</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="delete_method">
                <input type="hidden" name="method_id" id="deleteMethodId">
                <div class="modal-body">
                    <p>Are you sure you want to delete the payment method <strong id="deleteMethodName"></strong>?</p>
                    <p class="text-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action cannot be undone.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Delete Method
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddMethodModal() {
    const modal = new bootstrap.Modal(document.getElementById('addMethodModal'));
    modal.show();
}

function editMethod(id, name, code, description, icon, isDefault, sortOrder) {
    document.getElementById('editMethodId').value = id;
    document.getElementById('editMethodName').value = name;
    document.getElementById('editMethodCode').value = code;
    document.getElementById('editDescription').value = description;
    document.getElementById('editIcon').value = icon;
    document.getElementById('editSortOrder').value = sortOrder;
    document.getElementById('editIsDefault').checked = isDefault;
    
    const modal = new bootstrap.Modal(document.getElementById('editMethodModal'));
    modal.show();
}

function deleteMethod(id, name) {
    document.getElementById('deleteMethodId').value = id;
    document.getElementById('deleteMethodName').textContent = name;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteMethodModal'));
    modal.show();
}
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
