<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'cashflow_categories.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

// Create cashflow_categories table with main/sub structure (separate from finance_categories)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cashflow_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('main','sub') NOT NULL DEFAULT 'main',
        name VARCHAR(100) NOT NULL,
        parent_category VARCHAR(100) NULL,
        sort_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_main (name, type, parent_category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Migrate old flat schema if type column doesn't exist
$hasType = false;
foreach ($pdo->query("SHOW COLUMNS FROM cashflow_categories")->fetchAll(PDO::FETCH_COLUMN) as $col) {
    if ($col === 'type') { $hasType = true; break; }
}
if (!$hasType) {
    $pdo->exec("ALTER TABLE cashflow_categories ADD COLUMN type ENUM('main','sub') NOT NULL DEFAULT 'main' AFTER id");
    $pdo->exec("ALTER TABLE cashflow_categories ADD COLUMN parent_category VARCHAR(100) NULL AFTER name");
    try { $pdo->exec("ALTER TABLE cashflow_categories DROP INDEX uk_name"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE cashflow_categories ADD UNIQUE KEY uk_main (name, type, parent_category)"); } catch (PDOException $e) {}
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_main') {
        require_role_or_permission(['admin'], 'cashflow_categories.create');
        $name = trim($_POST['name'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        if (empty($name)) {
            $errors[] = 'Category name is required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO cashflow_categories (type, name, sort_order) VALUES ('main', ?, ?)");
                $stmt->execute([$name, $sortOrder]);
                $success = 'Main category added successfully.';
            } catch (PDOException $e) {
                $errors[] = $e->getCode() == 23000 ? "Main category '$name' already exists." : 'Failed to add.';
            }
        }
    } elseif ($action === 'add_sub') {
        require_role_or_permission(['admin'], 'cashflow_categories.create');
        $name = trim($_POST['name'] ?? '');
        $parentCategory = trim($_POST['parent_category'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        if (empty($name) || empty($parentCategory)) {
            $errors[] = 'Name and main category are required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO cashflow_categories (type, name, parent_category, sort_order) VALUES ('sub', ?, ?, ?)");
                $stmt->execute([$name, $parentCategory, $sortOrder]);
                $success = 'Subcategory added successfully.';
            } catch (PDOException $e) {
                $errors[] = $e->getCode() == 23000 ? "Subcategory '$name' already exists under this main category." : 'Failed to add.';
            }
        }
    } elseif ($action === 'edit_main') {
        require_role_or_permission(['admin'], 'cashflow_categories.update');
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($id <= 0 || empty($name)) {
            $errors[] = 'Valid ID and name are required.';
        } else {
            try {
                $old = $pdo->prepare("SELECT name FROM cashflow_categories WHERE id = ? AND type = 'main'");
                $old->execute([$id]);
                $oldName = $old->fetchColumn();
                if ($oldName) {
                    $pdo->prepare("UPDATE cashflow_categories SET parent_category = ? WHERE parent_category = ?")->execute([$name, $oldName]);
                }
                $stmt = $pdo->prepare("UPDATE cashflow_categories SET name = ?, sort_order = ?, is_active = ? WHERE id = ? AND type = 'main'");
                $stmt->execute([$name, $sortOrder, $isActive, $id]);
                $success = 'Main category updated.';
            } catch (PDOException $e) {
                $errors[] = $e->getCode() == 23000 ? "Main category '$name' already exists." : 'Failed to update.';
            }
        }
    } elseif ($action === 'edit_sub') {
        require_role_or_permission(['admin'], 'cashflow_categories.update');
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $parentCategory = trim($_POST['parent_category'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($id <= 0 || empty($name) || empty($parentCategory)) {
            $errors[] = 'Valid ID, name and main category are required.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE cashflow_categories SET name = ?, parent_category = ?, sort_order = ?, is_active = ? WHERE id = ? AND type = 'sub'");
                $stmt->execute([$name, $parentCategory, $sortOrder, $isActive, $id]);
                $success = 'Subcategory updated.';
            } catch (PDOException $e) {
                $errors[] = $e->getCode() == 23000 ? "Subcategory '$name' already exists under this main category." : 'Failed to update.';
            }
        }
    } elseif ($action === 'delete') {
        require_role_or_permission(['admin'], 'cashflow_categories.delete');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT type, name FROM cashflow_categories WHERE id = ?");
            $row->execute([$id]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                if ($r['type'] === 'main') {
                    $pdo->prepare("DELETE FROM cashflow_categories WHERE parent_category = ?")->execute([$r['name']]);
                }
                $pdo->prepare("DELETE FROM cashflow_categories WHERE id = ?")->execute([$id]);
                $success = 'Category deleted.';
            }
        }
    }

    if (empty($errors)) {
        header('Location: cashflow_categories.php?success=1');
        exit;
    }
}

if (isset($_GET['success'])) {
    $success = 'Changes saved successfully.';
}

$mainCategories = $pdo->query("SELECT * FROM cashflow_categories WHERE type = 'main' ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
$subcategories = $pdo->query("
    SELECT sc.*, mc.name as parent_name
    FROM cashflow_categories sc
    LEFT JOIN cashflow_categories mc ON sc.parent_category = mc.name
    WHERE sc.type = 'sub'
    ORDER BY mc.name, sc.sort_order, sc.name
")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../layout/header.php';
?>
<style>
/* Cash Flow Categories – distinct from Finance (teal/cyan theme) */
.cf-page-banner {
    background: linear-gradient(135deg, #0d9488 0%, #14b8a6 50%, #2dd4bf 100%);
    color: white;
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25);
}
.cf-page-banner h1 { font-size: 1.35rem; font-weight: 600; margin: 0; }
.cf-page-banner p { margin: 0.35rem 0 0 0; opacity: 0.95; font-size: 0.9rem; }
.cf-card-main { border-left: 4px solid #0d9488; }
.cf-card-sub { border-left: 4px solid #0891b2; }
.cf-card-main .card-header { background: #f0fdfa; color: #0f766e; border-bottom: 1px solid #ccfbf1; font-weight: 600; }
.cf-card-sub .card-header { background: #ecfeff; color: #0e7490; border-bottom: 1px solid #cffafe; font-weight: 600; }
.cf-btn-main { background: #0d9488; border-color: #0d9488; color: white; }
.cf-btn-main:hover { background: #0f766e; border-color: #0f766e; color: white; }
.cf-btn-sub { background: #0891b2; border-color: #0891b2; color: white; }
.cf-btn-sub:hover { background: #0e7490; border-color: #0e7490; color: white; }
.cf-badge-parent { background: #0d9488; color: white; }
.cf-table thead { background: #f0fdfa; color: #0f766e; font-weight: 600; }
.cf-table .cf-table-sub thead { background: #ecfeff; color: #0e7490; }
.cf-empty { padding: 2rem; text-align: center; color: #64748b; background: #f8fafc; border-radius: 8px; }
</style>
<div class="container-fluid py-4">
    <div class="cf-page-banner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1><i class="bi bi-cash-coin me-2"></i>Cash Flow Categories</h1>
            <p>Manage main and subcategories for cash flow. <strong>Not linked to Finance spending categories.</strong></p>
        </div>
        <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow.php" class="btn btn-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Cash Flow
        </a>
    </div>

    <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endforeach; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm cf-card-main">
                <div class="card-header py-3">
                    <h5 class="mb-0"><i class="bi bi-folder me-2"></i>Add Main Category</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="add_main">
                        <div class="mb-3">
                            <label class="form-label fw-medium">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. Cash, Bank, Digital">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control" value="0" style="max-width: 100px;">
                        </div>
                        <button type="submit" class="btn cf-btn-main"><i class="bi bi-plus-lg me-1"></i>Add Main Category</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm cf-card-sub">
                <div class="card-header py-3">
                    <h5 class="mb-0"><i class="bi bi-folder2-open me-2"></i>Add Subcategory</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="add_sub">
                        <div class="mb-3">
                            <label class="form-label fw-medium">Main Category <span class="text-danger">*</span></label>
                            <select name="parent_category" class="form-select" required>
                                <option value="">— Select main category —</option>
                                <?php foreach ($mainCategories as $mc): ?>
                                    <option value="<?= htmlspecialchars($mc['name']) ?>"><?= htmlspecialchars($mc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium">Subcategory Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. Cash in Hand, Bank Transfer">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control" value="0" style="max-width: 100px;">
                        </div>
                        <button type="submit" class="btn cf-btn-sub"><i class="bi bi-plus-lg me-1"></i>Add Subcategory</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-lg-6">
            <div class="card shadow-sm cf-card-main">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Main Categories</h5>
                    <?php if (!empty($mainCategories)): ?><span class="badge cf-badge-parent"><?= count($mainCategories) ?></span><?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($mainCategories)): ?>
                        <div class="cf-empty m-3">No main categories yet. Add one above.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 cf-table">
                                <thead><tr><th>Name</th><th>Sort</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($mainCategories as $mc): ?>
                                    <tr>
                                        <td class="fw-medium"><?= htmlspecialchars($mc['name']) ?></td>
                                        <td><?= (int)$mc['sort_order'] ?></td>
                                        <td><?= !empty($mc['is_active']) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#editMain<?= (int)$mc['id'] ?>"><i class="bi bi-pencil"></i></button>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this main category and all its subcategories?');">
                                                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$mc['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <div class="modal fade" id="editMain<?= (int)$mc['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog"><div class="modal-content">
                                            <form method="post">
                                                <input type="hidden" name="action" value="edit_main"><input type="hidden" name="id" value="<?= (int)$mc['id'] ?>">
                                                <div class="modal-header"><h5 class="modal-title">Edit Main Category</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                                <div class="modal-body">
                                                    <div class="mb-3"><label class="form-label">Name</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($mc['name']) ?>" required></div>
                                                    <div class="mb-3"><label class="form-label">Sort Order</label><input type="number" name="sort_order" class="form-control" value="<?= (int)$mc['sort_order'] ?>" style="max-width: 100px;"></div>
                                                    <div class="form-check"><input type="checkbox" name="is_active" class="form-check-input" <?= !empty($mc['is_active']) ? 'checked' : '' ?>><label class="form-check-label">Active</label></div>
                                                </div>
                                                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn cf-btn-main">Save</button></div>
                                            </form>
                                        </div></div>
                                    </div>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm cf-card-sub">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-list-nested me-2"></i>Subcategories</h5>
                    <?php if (!empty($subcategories)): ?><span class="badge bg-info"><?= count($subcategories) ?></span><?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($subcategories)): ?>
                        <div class="cf-empty m-3">No subcategories yet. Add one above.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 cf-table cf-table-sub">
                                <thead><tr><th>Name</th><th>Under</th><th>Sort</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($subcategories as $sc): ?>
                                    <tr>
                                        <td class="fw-medium"><?= htmlspecialchars($sc['name']) ?></td>
                                        <td><span class="badge cf-badge-parent"><?= htmlspecialchars($sc['parent_name'] ?? $sc['parent_category']) ?></span></td>
                                        <td><?= (int)$sc['sort_order'] ?></td>
                                        <td><?= !empty($sc['is_active']) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#editSub<?= (int)$sc['id'] ?>"><i class="bi bi-pencil"></i></button>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this subcategory?');">
                                                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$sc['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <div class="modal fade" id="editSub<?= (int)$sc['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog"><div class="modal-content">
                                            <form method="post">
                                                <input type="hidden" name="action" value="edit_sub"><input type="hidden" name="id" value="<?= (int)$sc['id'] ?>">
                                                <div class="modal-header"><h5 class="modal-title">Edit Subcategory</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                                <div class="modal-body">
                                                    <div class="mb-3"><label class="form-label">Main Category</label>
                                                        <select name="parent_category" class="form-select" required>
                                                            <?php foreach ($mainCategories as $mc): ?>
                                                                <option value="<?= htmlspecialchars($mc['name']) ?>" <?= ($sc['parent_category'] ?? '') === $mc['name'] ? 'selected' : '' ?>><?= htmlspecialchars($mc['name']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="mb-3"><label class="form-label">Subcategory Name</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($sc['name']) ?>" required></div>
                                                    <div class="mb-3"><label class="form-label">Sort Order</label><input type="number" name="sort_order" class="form-control" value="<?= (int)$sc['sort_order'] ?>" style="max-width: 100px;"></div>
                                                    <div class="form-check"><input type="checkbox" name="is_active" class="form-check-input" <?= !empty($sc['is_active']) ? 'checked' : '' ?>><label class="form-check-label">Active</label></div>
                                                </div>
                                                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn cf-btn-sub">Save</button></div>
                                            </form>
                                        </div></div>
                                    </div>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
