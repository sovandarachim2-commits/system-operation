<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'manage_categories.view', 'categories.view', 'sr_expense_categories.view', 'sr_expense_settings.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();
$isAdminUser = ((current_user()['role'] ?? '') === 'admin');
$canCreateCategories = $isAdminUser
    || has_permission('manage_categories.create') || has_permission('sr_expense_categories.create') || has_permission('sr_expense_settings.create')
    || has_permission('categories.create');
$canUpdateCategories = $isAdminUser
    || has_permission('manage_categories.update') || has_permission('sr_expense_categories.update') || has_permission('sr_expense_settings.update')
    || has_permission('categories.update');
$canDeleteCategories = $isAdminUser
    || has_permission('manage_categories.delete') || has_permission('sr_expense_categories.delete') || has_permission('sr_expense_settings.delete')
    || has_permission('categories.delete');

// Handle form submission for adding main category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_main_category') {
    if (!$canCreateCategories) {
        $error = 'You do not have permission to create categories.';
    } else {
    $category_name = trim($_POST['category_name'] ?? '');
    
    if (!empty($category_name)) {
        // Check if category already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_categories WHERE name = ? AND type = 'main'");
        $stmt->execute([$category_name]);
        
        if ($stmt->fetchColumn() == 0) {
            $created_by = isset($_SESSION['user']['name']) ? $_SESSION['user']['name'] : 'Admin';
            $stmt = $pdo->prepare("INSERT INTO finance_categories (name, type, created_by) VALUES (?, 'main', ?)");
            $stmt->execute([$category_name, $created_by]);
            $success = "Main category '$category_name' added successfully!";
        } else {
            $error = "Main category '$category_name' already exists!";
        }
    } else {
        $error = "Category name cannot be empty!";
    }
    }
}

// Handle form submission for adding subcategory
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_subcategory') {
    if (!$canCreateCategories) {
        $error = 'You do not have permission to create categories.';
    } else {
    $subcategory_name = trim($_POST['subcategory_name'] ?? '');
    $main_category = $_POST['main_category'] ?? '';
    
    if (!empty($subcategory_name) && !empty($main_category)) {
        // Check if subcategory already exists for this main category
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_categories WHERE name = ? AND type = 'sub' AND parent_category = ?");
        $stmt->execute([$subcategory_name, $main_category]);
        
        if ($stmt->fetchColumn() == 0) {
            $created_by = isset($_SESSION['user']['name']) ? $_SESSION['user']['name'] : 'Admin';
            $stmt = $pdo->prepare("INSERT INTO finance_categories (name, type, parent_category, created_by) VALUES (?, 'sub', ?, ?)");
            $stmt->execute([$subcategory_name, $main_category, $created_by]);
            $success = "Subcategory '$subcategory_name' added successfully!";
        } else {
            $error = "Subcategory '$subcategory_name' already exists for '$main_category'!";
        }
    } else {
        $error = "Both subcategory name and main category are required!";
    }
    }
}

// Handle form submission for editing main category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_main_category') {
    if (!$canUpdateCategories) {
        $error = 'You do not have permission to edit categories.';
    } else {
    $category_id = $_POST['category_id'] ?? '';
    $category_name = trim($_POST['category_name'] ?? '');
    
    if (!empty($category_id) && !empty($category_name)) {
        // Check if category name already exists (excluding current category)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_categories WHERE name = ? AND type = 'main' AND id != ?");
        $stmt->execute([$category_name, $category_id]);
        
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("UPDATE finance_categories SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND type = 'main'");
            $stmt->execute([$category_name, $category_id]);
            
            // Also update the category name in spending records
            $old_name = $pdo->query("SELECT name FROM finance_categories WHERE id = $category_id")->fetchColumn();
            $stmt = $pdo->prepare("UPDATE finance_spending SET category = ? WHERE category = ?");
            $stmt->execute([$category_name, $old_name]);
            
            $success = "Main category updated successfully!";
        } else {
            $error = "Main category '$category_name' already exists!";
        }
    } else {
        $error = "Category ID and name are required!";
    }
    }
}

// Handle form submission for editing subcategory
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_subcategory') {
    if (!$canUpdateCategories) {
        $error = 'You do not have permission to edit categories.';
    } else {
    $category_id = $_POST['category_id'] ?? '';
    $subcategory_name = trim($_POST['subcategory_name'] ?? '');
    $main_category = $_POST['main_category'] ?? '';
    
    if (!empty($category_id) && !empty($subcategory_name) && !empty($main_category)) {
        // Check if subcategory name already exists for this main category (excluding current subcategory)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_categories WHERE name = ? AND type = 'sub' AND parent_category = ? AND id != ?");
        $stmt->execute([$subcategory_name, $main_category, $category_id]);
        
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("UPDATE finance_categories SET name = ?, parent_category = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND type = 'sub'");
            $stmt->execute([$subcategory_name, $main_category, $category_id]);
            
            // Also update the subcategory name in spending records
            $old_name = $pdo->query("SELECT name FROM finance_categories WHERE id = $category_id")->fetchColumn();
            $stmt = $pdo->prepare("UPDATE finance_spending SET sub_category = ? WHERE sub_category = ?");
            $stmt->execute([$subcategory_name, $old_name]);
            
            $success = "Subcategory updated successfully!";
        } else {
            $error = "Subcategory '$subcategory_name' already exists for '$main_category'!";
        }
    } else {
        $error = "All fields are required!";
    }
    }
}

// Handle bulk deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_delete_main') {
    if (!$canDeleteCategories) {
        $error = 'You do not have permission to delete categories.';
    } else {
    $category_ids = $_POST['category_ids'] ?? [];
    
    if (!empty($category_ids)) {
        $deleted_count = 0;
        $error_count = 0;
        
        foreach ($category_ids as $category_id) {
            // Check if category is being used in spending records
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_spending WHERE category = (SELECT name FROM finance_categories WHERE id = ? AND type = 'main')");
            $stmt->execute([$category_id]);
            $usage_count = $stmt->fetchColumn();
            
            if ($usage_count == 0) {
                $stmt = $pdo->prepare("DELETE FROM finance_categories WHERE id = ? AND type = 'main'");
                $stmt->execute([$category_id]);
                $deleted_count++;
            } else {
                $error_count++;
            }
        }
        
        if ($deleted_count > 0) {
            $success = "Successfully deleted $deleted_count main categories!";
        }
        if ($error_count > 0) {
            $error = "Could not delete $error_count categories because they are in use.";
        }
    } else {
        $error = "No categories selected for deletion!";
    }
    }
}

// Handle individual category deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_category') {
    if (!$canDeleteCategories) {
        $error = 'You do not have permission to delete categories.';
    } else {
    $category_id = $_POST['category_id'] ?? '';
    
    if (!empty($category_id)) {
        // Check if it's a main category or subcategory
        $stmt = $pdo->prepare("SELECT type, name FROM finance_categories WHERE id = ?");
        $stmt->execute([$category_id]);
        $category_info = $stmt->fetch();
        
        if ($category_info) {
            if ($category_info['type'] === 'main') {
                // Check if main category is being used in spending records
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_spending WHERE category = ?");
                $stmt->execute([$category_info['name']]);
                $usage_count = $stmt->fetchColumn();
                
                if ($usage_count == 0) {
                    // Also delete all subcategories under this main category
                    $stmt = $pdo->prepare("DELETE FROM finance_categories WHERE parent_category = ?");
                    $stmt->execute([$category_info['name']]);
                    
                    // Delete the main category
                    $stmt = $pdo->prepare("DELETE FROM finance_categories WHERE id = ?");
                    $stmt->execute([$category_id]);
                    $success = "Main category '{$category_info['name']}' and its subcategories deleted successfully!";
                } else {
                    $error = "Cannot delete main category '{$category_info['name']}'! It is used in $usage_count spending records.";
                }
            } else {
                // Check if subcategory is being used in spending records
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_spending WHERE sub_category = ?");
                $stmt->execute([$category_info['name']]);
                $usage_count = $stmt->fetchColumn();
                
                if ($usage_count == 0) {
                    $stmt = $pdo->prepare("DELETE FROM finance_categories WHERE id = ?");
                    $stmt->execute([$category_id]);
                    $success = "Subcategory '{$category_info['name']}' deleted successfully!";
                } else {
                    $error = "Cannot delete subcategory '{$category_info['name']}'! It is used in $usage_count spending records.";
                }
            }
        } else {
            $error = "Category not found!";
        }
    } else {
        $error = "Category ID is required!";
    }
    }
}

// Handle bulk deletion of subcategories
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_delete_sub') {
    if (!$canDeleteCategories) {
        $error = 'You do not have permission to delete categories.';
    } else {
    $category_ids = $_POST['category_ids'] ?? [];
    
    if (!empty($category_ids)) {
        $deleted_count = 0;
        $error_count = 0;
        
        foreach ($category_ids as $category_id) {
            // Check if subcategory is being used in spending records
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_spending WHERE sub_category = (SELECT name FROM finance_categories WHERE id = ? AND type = 'sub')");
            $stmt->execute([$category_id]);
            $usage_count = $stmt->fetchColumn();
            
            if ($usage_count == 0) {
                $stmt = $pdo->prepare("DELETE FROM finance_categories WHERE id = ? AND type = 'sub'");
                $stmt->execute([$category_id]);
                $deleted_count++;
            } else {
                $error_count++;
            }
        }
        
        if ($deleted_count > 0) {
            $success = "Successfully deleted $deleted_count subcategories!";
        }
        if ($error_count > 0) {
            $error = "Could not delete $error_count subcategories because they are in use.";
        }
    } else {
        $error = "No subcategories selected for deletion!";
    }
    }
}

// Get all main categories
$main_categories = $pdo->query("SELECT * FROM finance_categories WHERE type = 'main' ORDER BY name")->fetchAll();

// Get all subcategories with their parent categories
$subcategories = $pdo->query("
    SELECT sc.*, mc.name as parent_name 
    FROM finance_categories sc 
    LEFT JOIN finance_categories mc ON sc.parent_category = mc.name 
    WHERE sc.type = 'sub' 
    ORDER BY mc.name, sc.name
")->fetchAll();

require_once __DIR__ . '/../layout/header.php';

// Categories Management - Styled & Responsive
echo "
<style>
/* Manage Categories - Clear Layout & Colors */
.categories-page .page-header {
    background: linear-gradient(135deg, #d97706 0%, #f59e0b 50%, #fbbf24 100%);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    color: #1e293b;
    box-shadow: 0 4px 14px rgba(217, 119, 6, 0.35);
}
.categories-page .page-header h2 { margin: 0; font-weight: 600; }
.categories-page .page-header .subtitle { color: rgba(30, 41, 59, 0.8); margin: 0.25rem 0 0; }
.categories-page .action-card {
    border-radius: 12px;
    border: none;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: transform 0.2s, box-shadow 0.2s;
}
.categories-page .action-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.12); }
.categories-page .action-card .card-header-custom {
    padding: 1rem 1.25rem;
    font-weight: 600;
    color: #fff;
    border: none;
}
.categories-page .action-card.card-main .card-header-custom {
    background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 100%);
}
.categories-page .action-card.card-sub .card-header-custom {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
}
.categories-page .action-card .card-body { padding: 1.25rem; }
.categories-page .action-card .form-label { font-weight: 600; color: #212529; }
.categories-page .list-card {
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    margin-bottom: 1.5rem;
}
.categories-page .list-card .section-header {
    padding: 1rem 1.25rem;
    font-weight: 600;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.categories-page .list-card.list-main .section-header { border-left: 4px solid #0ea5e9; }
.categories-page .list-card.list-sub .section-header { border-left: 4px solid #10b981; }
.categories-page .list-card .table thead th {
    background: #f1f5f9 !important;
    color: #212529;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.75rem 1rem;
}
.categories-page .list-card .table tbody td { padding: 0.75rem 1rem; vertical-align: middle; color: #212529; }
.categories-page .text-muted { color: #212529 !important; }
.categories-page .btn-action-group { display: flex; gap: 0.25rem; flex-wrap: wrap; }

/* Responsive */
@media (max-width: 768px) {
    .categories-page .page-header { padding: 1rem; }
    .categories-page .action-card .card-body { padding: 1rem; }
    .categories-page .list-card .table thead th,
    .categories-page .list-card .table tbody td { padding: 0.6rem 0.5rem; font-size: 0.875rem; }
    .categories-page .btn-action-group .btn { padding: 0.4rem 0.6rem; font-size: 0.8rem; }
}
@media (max-width: 576px) {
    .categories-page .table th:nth-child(3),
    .categories-page .table td:nth-child(3) { display: none !important; }
    .categories-page .list-card .section-header { flex-direction: column; align-items: flex-start; }
    .categories-page .btn-action-group { width: 100%; justify-content: flex-start; }
}

.categories-page .form-control, .categories-page .form-select {
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}
.categories-page .form-control:focus, .categories-page .form-select:focus {
    border-color: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
}
.table-responsive { -webkit-overflow-scrolling: touch !important; }
@media (max-width: 768px) {
    .modal-dialog { margin: 0.5rem !important; max-width: calc(100% - 1rem) !important; }
    .modal-content { border-radius: 12px !important; }
}
</style>
";
?>

<div class="container-fluid py-4 categories-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2><i class="bi bi-tags me-2"></i>Manage Categories</h2>
                <p class="subtitle mb-0">Main categories and subcategories for spending</p>
            </div>
            <a href="finance_dashboard.php" class="btn btn-light btn-sm" style="background: rgba(255,255,255,0.9); color: #1e293b;">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= $error ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <!-- Add Main Category -->
        <div class="col-lg-6">
            <div class="action-card card-main">
                <div class="card-header-custom">
                    <i class="bi bi-folder-plus me-2"></i>Add Main Category
                </div>
                <div class="card-body">
                    <?php if ($canCreateCategories): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="add_main_category">
                            <div class="mb-3">
                                <label class="form-label">Category Name *</label>
                                <input type="text" class="form-control" name="category_name" 
                                       placeholder="e.g., Marketing, Operations, HR" required>
                                <small class="text-muted">Enter a name for the main spending category</small>
                            </div>
                            <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 100%); border: none;">
                                <i class="bi bi-plus-lg me-1"></i>Add Main Category
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="text-muted small"><i class="bi bi-lock me-1"></i>No create permission.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Add Subcategory -->
        <div class="col-lg-6">
            <div class="action-card card-sub">
                <div class="card-header-custom">
                    <i class="bi bi-tag-plus me-2"></i>Add Subcategory
                </div>
                <div class="card-body">
                    <?php if ($canCreateCategories): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="add_subcategory">
                            <div class="mb-3">
                                <label class="form-label">Main Category *</label>
                                <select class="form-select" name="main_category" required>
                                    <option value="">Select main category</option>
                                    <?php foreach ($main_categories as $category): ?>
                                        <option value="<?= htmlspecialchars($category['name']) ?>">
                                            <?= htmlspecialchars($category['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Subcategory Name *</label>
                                <input type="text" class="form-control" name="subcategory_name" 
                                       placeholder="e.g., Advertising, Office Supplies, Training" required>
                                <small class="text-muted">Enter a name for the subcategory</small>
                            </div>
                            <button type="submit" class="btn btn-success" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); border: none;">
                                <i class="bi bi-plus-lg me-1"></i>Add Subcategory
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="text-muted small"><i class="bi bi-lock me-1"></i>No create permission.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Categories List -->
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="list-card list-main">
                <div class="section-header">
                    <span><i class="bi bi-folder me-2"></i>Main Categories</span>
                    <?php if (!empty($main_categories)): ?>
                    <span class="badge bg-primary"><?= count($main_categories) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($main_categories)): ?>
                        <p class="text-muted p-3 mb-0">No main categories yet. Add one above!</p>
                    <?php else: ?>
                        <form method="post" id="bulkDeleteMainForm">
                            <input type="hidden" name="action" value="bulk_delete_main">
                            <?php if ($canDeleteCategories): ?>
                                <div class="p-2 bg-light border-bottom d-flex gap-2 flex-wrap">
                                    <button type="button" class="btn btn-sm btn-danger" onclick="bulkDeleteMain()">
                                        <i class="bi bi-trash"></i> Delete Selected
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleMainCheckboxes()">
                                        <i class="bi bi-check-all"></i> Select All
                                    </button>
                                </div>
                            <?php endif; ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th width="40">
                                                <?php if ($canDeleteCategories): ?>
                                                    <input type="checkbox" id="selectAllMain" onchange="toggleMainCheckboxes()">
                                                <?php endif; ?>
                                            </th>
                                            <th>Category Name</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($main_categories as $category): ?>
                                            <tr>
                                                <td>
                                                    <?php if ($canDeleteCategories): ?>
                                                        <input type="checkbox" name="category_ids[]" value="<?= $category['id'] ?>" class="main-checkbox">
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?= htmlspecialchars($category['name']) ?></strong>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= date('M d, Y', strtotime($category['created_at'])) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="btn-action-group">
                                                        <?php if ($canUpdateCategories): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    onclick="editMainCategory(<?= $category['id'] ?>, <?= json_encode($category['name']) ?>)">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($canDeleteCategories): ?>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="action" value="delete_category">
                                                                <input type="hidden" name="category_id" value="<?= $category['id'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                                        onclick="return confirm('Are you sure you want to delete this category?')">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Subcategories List -->
        <div class="col-lg-6">
            <div class="list-card list-sub">
                <div class="section-header">
                    <span><i class="bi bi-tag me-2"></i>Subcategories</span>
                    <?php if (!empty($subcategories)): ?>
                    <span class="badge bg-success"><?= count($subcategories) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($subcategories)): ?>
                        <p class="text-muted p-3 mb-0">No subcategories yet. Add one above!</p>
                    <?php else: ?>
                        <form method="post" id="bulkDeleteSubForm">
                            <input type="hidden" name="action" value="bulk_delete_sub">
                            <?php if ($canDeleteCategories): ?>
                                <div class="p-2 bg-light border-bottom d-flex gap-2 flex-wrap">
                                    <button type="button" class="btn btn-sm btn-danger" onclick="bulkDeleteSub()">
                                        <i class="bi bi-trash"></i> Delete Selected
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleSubCheckboxes()">
                                        <i class="bi bi-check-all"></i> Select All
                                    </button>
                                </div>
                            <?php endif; ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th width="40">
                                                <?php if ($canDeleteCategories): ?>
                                                    <input type="checkbox" id="selectAllSub" onchange="toggleSubCheckboxes()">
                                                <?php endif; ?>
                                            </th>
                                            <th>Subcategory</th>
                                            <th>Main Category</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($subcategories as $subcategory): ?>
                                            <tr>
                                                <td>
                                                    <?php if ($canDeleteCategories): ?>
                                                        <input type="checkbox" name="category_ids[]" value="<?= $subcategory['id'] ?>" class="sub-checkbox">
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($subcategory['name']) ?></td>
                                                <td>
                                                    <span class="badge" style="background: #10b981;">
                                                        <?= htmlspecialchars($subcategory['parent_name']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-action-group">
                                                        <?php if ($canUpdateCategories): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    onclick="editSubcategory(<?= $subcategory['id'] ?>, <?= json_encode($subcategory['name']) ?>, <?= json_encode($subcategory['parent_name']) ?>)">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($canDeleteCategories): ?>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="action" value="delete_category">
                                                                <input type="hidden" name="category_id" value="<?= $subcategory['id'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                                        onclick="return confirm('Are you sure you want to delete this subcategory?')">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
const CAN_UPDATE_CATEGORIES = <?= $canUpdateCategories ? 'true' : 'false' ?>;
const CAN_DELETE_CATEGORIES = <?= $canDeleteCategories ? 'true' : 'false' ?>;

function toggleMainCheckboxes() {
    if (!CAN_DELETE_CATEGORIES) return;
    const selectAll = document.getElementById('selectAllMain');
    const checkboxes = document.querySelectorAll('.main-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
}

function toggleSubCheckboxes() {
    if (!CAN_DELETE_CATEGORIES) return;
    const selectAll = document.getElementById('selectAllSub');
    const checkboxes = document.querySelectorAll('.sub-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
}

function bulkDeleteMain() {
    if (!CAN_DELETE_CATEGORIES) {
        alert('No permission to delete categories.');
        return;
    }
    const checkboxes = document.querySelectorAll('.main-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one main category to delete.');
        return;
    }
    
    if (confirm(`Are you sure you want to delete ${checkboxes.length} main categories?\n\nNote: Categories that are in use will not be deleted.`)) {
        document.getElementById('bulkDeleteMainForm').submit();
    }
}

function bulkDeleteSub() {
    if (!CAN_DELETE_CATEGORIES) {
        alert('No permission to delete categories.');
        return;
    }
    const checkboxes = document.querySelectorAll('.sub-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one subcategory to delete.');
        return;
    }
    
    if (confirm(`Are you sure you want to delete ${checkboxes.length} subcategories?\n\nNote: Subcategories that are in use will not be deleted.`)) {
        document.getElementById('bulkDeleteSubForm').submit();
    }
}

function editMainCategory(id, name) {
    if (!CAN_UPDATE_CATEGORIES) {
        alert('No permission to edit categories.');
        return;
    }
    const newName = prompt('Edit main category name:', name);
    if (newName && newName.trim() && newName !== name) {
        const form = document.createElement('form');
        form.method = 'post';
        form.innerHTML = `
            <input type="hidden" name="action" value="edit_main_category">
            <input type="hidden" name="category_id" value="${id}">
            <input type="hidden" name="category_name" value="${newName.trim()}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function editSubcategory(id, name, parentName) {
    if (!CAN_UPDATE_CATEGORIES) {
        alert('No permission to edit categories.');
        return;
    }
    const newName = prompt('Edit subcategory name:', name);
    if (newName && newName.trim()) {
        // Get main categories for selection
        const mainCategories = <?php echo json_encode(array_column($main_categories, 'name')); ?>;
        
        let categoryOptions = '';
        mainCategories.forEach(cat => {
            const selected = cat === parentName ? 'selected' : '';
            categoryOptions += `<option value="${cat}" ${selected}>${cat}</option>`;
        });
        
        const modal = document.createElement('div');
        modal.innerHTML = `
            <div class="modal fade show" style="display: block; background: rgba(0,0,0,0.5);" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Subcategory</h5>
                            <button type="button" class="btn-close" onclick="this.closest('.modal').remove()"></button>
                        </div>
                        <div class="modal-body">
                            <form id="editSubForm">
                                <input type="hidden" name="action" value="edit_subcategory">
                                <input type="hidden" name="category_id" value="${id}">
                                <div class="mb-3">
                                    <label class="form-label">Subcategory Name</label>
                                    <input type="text" class="form-control" name="subcategory_name" value="${newName.trim()}" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Main Category</label>
                                    <select class="form-select" name="main_category" required>
                                        ${categoryOptions}
                                    </select>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').remove()">Cancel</button>
                            <button type="button" class="btn btn-primary" onclick="submitEditSub()">Save Changes</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
}

function submitEditSub() {
    document.getElementById('editSubForm').submit();
}
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
