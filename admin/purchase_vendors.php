<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_vendors.view');

$pdo = get_db_connection();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        require_role_or_permission(['admin'], 'purchase_vendors.create');
        $name = trim($_POST['name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $payment_terms = trim($_POST['payment_terms'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Validate required fields
        if ($name === '') {
            $errors[] = 'Vendor name is required.';
        }
        if ($contact_person === '') {
            $errors[] = 'Contact person is required.';
        }
        if ($phone === '') {
            $errors[] = 'Phone number is required.';
        }

        // Only proceed if no validation errors
        if (empty($errors)) {
            try {
                $user = current_user();
                $stmt = $pdo->prepare('INSERT INTO purchase_vendors (name, contact_person, phone, email, address, payment_terms, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $contact_person, $phone, $email, $address, $payment_terms, $notes, $user['id']]);
                $success = 'Vendor added successfully.';
            } catch (PDOException $e) {
                $errors[] = 'Failed to add vendor: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'update') {
        require_role_or_permission(['admin'], 'purchase_vendors.update');
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $payment_terms = trim($_POST['payment_terms'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($id > 0 && $name !== '') {
            try {
                $stmt = $pdo->prepare('UPDATE purchase_vendors SET name = ?, contact_person = ?, phone = ?, email = ?, address = ?, payment_terms = ?, notes = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$name, $contact_person, $phone, $email, $address, $payment_terms, $notes, $is_active, $id]);
                $success = 'Vendor updated successfully.';
            } catch (PDOException $e) {
                $errors[] = 'Failed to update vendor: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'delete') {
        require_role_or_permission(['admin'], 'purchase_vendors.delete');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Check if vendor has purchase orders
            $check = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE vendor_id = ?');
            $check->execute([$id]);
            $count = $check->fetchColumn();
            
            if ($count > 0) {
                $errors[] = 'Cannot delete vendor with existing purchase orders.';
            } else {
                $stmt = $pdo->prepare('DELETE FROM purchase_vendors WHERE id = ?');
                $stmt->execute([$id]);
                $success = 'Vendor deleted successfully.';
            }
        }
    }
}

// Get vendors
try {
    $stmt = $pdo->query('SELECT * FROM purchase_vendors ORDER BY name');
    $vendors = $stmt->fetchAll();
} catch (PDOException $e) {
    $vendors = [];
    $errors[] = 'Purchase vendors table not found. Please run setup script first.';
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Purchase Vendors</h1>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addVendorModal">
            <i class="bi bi-plus-circle me-2"></i>Add Vendor
        </button>
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
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Payment Terms</th>
                            <th>Status</th>
                            <th>Orders</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$vendors): ?>
                        <tr><td colspan="9" class="text-center py-4">No vendors found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($vendors as $vendor): ?>
                            <?php
                            // Get order count for this vendor
                            $orderCountStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE vendor_id = ?');
                            $orderCountStmt->execute([$vendor['id']]);
                            $orderCount = $orderCountStmt->fetchColumn();
                            ?>
                        <tr>
                            <td><?= (int)$vendor['id'] ?></td>
                            <td>
                                <div><?= htmlspecialchars($vendor['name']) ?></div>
                                <?php if (!empty($vendor['address'])): ?>
                                    <small class="text-muted"><?= htmlspecialchars(substr($vendor['address'], 0, 50)) ?>...</small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($vendor['contact_person'] ?? '') ?></td>
                            <td><?= htmlspecialchars($vendor['phone'] ?? '') ?></td>
                            <td><?= htmlspecialchars($vendor['email'] ?? '') ?></td>
                            <td><?= htmlspecialchars($vendor['payment_terms'] ?? '') ?></td>
                            <td>
                                <span class="badge bg-<?= $vendor['is_active'] ? 'success' : 'secondary' ?>">
                                    <?= $vendor['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-info"><?= (int)$orderCount ?></span>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editVendorModal<?= (int)$vendor['id'] ?>">Edit</button>
                                    <form method="post" onsubmit="return confirm('Delete this vendor?');" style="display: inline;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$vendor['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm" <?= $orderCount > 0 ? 'disabled' : '' ?>>Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit Vendor Modal -->
                        <div class="modal fade" id="editVendorModal<?= (int)$vendor['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Vendor</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int)$vendor['id'] ?>">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label">Name *</label>
                                                    <input type="text" name="name" class="form-control form-control-lg" value="<?= htmlspecialchars($vendor['name']) ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Contact Person</label>
                                                    <input type="text" name="contact_person" class="form-control form-control-lg" value="<?= htmlspecialchars($vendor['contact_person'] ?? '') ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Phone</label>
                                                    <input type="text" name="phone" class="form-control form-control-lg" value="<?= htmlspecialchars($vendor['phone'] ?? '') ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Email</label>
                                                    <input type="email" name="email" class="form-control form-control-lg" value="<?= htmlspecialchars($vendor['email'] ?? '') ?>">
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Address</label>
                                                    <textarea name="address" class="form-control form-control-lg" rows="2"><?= htmlspecialchars($vendor['address'] ?? '') ?></textarea>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Payment Terms</label>
                                                    <input type="text" name="payment_terms" class="form-control form-control-lg" value="<?= htmlspecialchars($vendor['payment_terms'] ?? '') ?>" placeholder="e.g., Net 30, 50% upfront">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Notes</label>
                                                    <textarea name="notes" class="form-control form-control-lg" rows="2"><?= htmlspecialchars($vendor['notes'] ?? '') ?></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="is_active" <?= $vendor['is_active'] ? 'checked' : '' ?>>
                                                        <label class="form-check-label">Active</label>
                                                    </div>
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
</div>

<!-- Add Vendor Modal -->
<div class="modal fade" id="addVendorModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add Vendor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name *</label>
                            <input type="text" name="name" class="form-control form-control-lg" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Person *</label>
                            <input type="text" name="contact_person" class="form-control form-control-lg" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone *</label>
                            <input type="text" name="phone" class="form-control form-control-lg" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control form-control-lg">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control form-control-lg" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Terms</label>
                            <input type="text" name="payment_terms" class="form-control form-control-lg" placeholder="e.g., Net 30, 50% upfront">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control form-control-lg" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Vendor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
