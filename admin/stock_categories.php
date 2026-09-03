<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'categories.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_category'])) {
        require_role_or_permission(['admin'], 'categories.create');
        // Add new category
        $category_name = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($category_name)) {
            $message = 'Category name is required';
            $messageType = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO stock_categories (category_name, description) VALUES (?, ?)");
                $stmt->execute([$category_name, $description]);
                $message = 'Category added successfully!';
                $messageType = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { // Duplicate entry
                    $message = 'Category name already exists';
                    $messageType = 'danger';
                } else {
                    $message = 'Error adding category: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    } elseif (isset($_POST['edit_category'])) {
        require_role_or_permission(['admin'], 'categories.update');
        // Edit category
        $id = $_POST['category_id'] ?? 0;
        $category_name = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($category_name)) {
            $message = 'Category name is required';
            $messageType = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE stock_categories SET category_name = ?, description = ? WHERE id = ?");
                $stmt->execute([$category_name, $description, $id]);
                $message = 'Category updated successfully!';
                $messageType = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { // Duplicate entry
                    $message = 'Category name already exists';
                    $messageType = 'danger';
                } else {
                    $message = 'Error updating category: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    } elseif (isset($_POST['delete_category'])) {
        require_role_or_permission(['admin'], 'categories.delete');
        // Delete category
        $id = $_POST['category_id'] ?? 0;

        try {
            // Check if category has products
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_items WHERE category_id = ?");
            $stmt->execute([$id]);
            $productCount = $stmt->fetchColumn();

            if ($productCount > 0) {
                $message = 'Cannot delete category with existing products. Please reassign or delete products first.';
                $messageType = 'danger';
            } else {
                $stmt = $pdo->prepare("DELETE FROM stock_categories WHERE id = ?");
                $stmt->execute([$id]);
                $message = 'Category deleted successfully!';
                $messageType = 'success';
            }
        } catch (PDOException $e) {
            $message = 'Error deleting category: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// Get all categories
$stmt = $pdo->query("SELECT * FROM stock_categories ORDER BY category_name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get product counts for each category
$categoryCounts = [];
foreach ($categories as $category) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_items WHERE category_id = ?");
    $stmt->execute([$category['id']]);
    $categoryCounts[$category['id']] = $stmt->fetchColumn();
}

require_once __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4 flex-grow-1">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-tags me-2"></i>Stock Categories</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="bi bi-plus-lg me-1"></i>Add Category
                    </button>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Categories Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Categories (<?= count($categories) ?>)</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="categoriesTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Category Name</th>
                                        <th>Description</th>
                                        <th>Products</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($categories)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4">
                                                <i class="bi bi-info-circle text-muted fs-1 mb-2"></i>
                                                <p class="text-muted mb-0">No categories found.</p>
                                                <p class="text-muted small">Click "Add Category" to create your first category.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($categories as $category): ?>
                                            <tr>
                                                <td><?= $category['id'] ?></td>
                                                <td>
                                                    <strong class="text-primary"><?= htmlspecialchars($category['category_name']) ?></strong>
                                                </td>
                                                <td>
                                                    <span class="text-truncate d-inline-block" style="max-width: 300px;" title="<?= htmlspecialchars($category['description']) ?>">
                                                        <?= htmlspecialchars($category['description']) ?: '<em class="text-muted">No description</em>' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info">
                                                        <?= $categoryCounts[$category['id']] ?? 0 ?> products
                                                    </span>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= date('M j, Y', strtotime($category['created_at'])) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                                                data-bs-toggle="dropdown">
                                                            <i class="bi bi-three-dots"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li>
                                                                <a class="dropdown-item" href="#"
                                                                   onclick="editCategory(<?= $category['id'] ?>, '<?= htmlspecialchars(addslashes($category['category_name'])) ?>', '<?= htmlspecialchars(addslashes($category['description'])) ?>')">
                                                                    <i class="bi bi-pencil me-2"></i>Edit
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <a class="dropdown-item text-danger" href="#"
                                                                   onclick="deleteCategory(<?= $category['id'] ?>, '<?= htmlspecialchars(addslashes($category['category_name'])) ?>')">
                                                                    <i class="bi bi-trash me-2"></i>Delete
                                                                </a>
                                                            </li>
                                                        </ul>
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
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Name *</label>
                        <input type="text" name="category_name" class="form-control" required>
                        <div class="form-text">Enter a unique category name</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Optional description for this category"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_category" class="btn btn-primary">Add Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="category_id" id="editCategoryId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Name *</label>
                        <input type="text" name="category_name" id="editCategoryName" class="form-control" required>
                        <div class="form-text">Enter a unique category name</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="editCategoryDescription" class="form-control" rows="3" placeholder="Optional description for this category"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_category" class="btn btn-primary">Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Category Modal -->
<div class="modal fade" id="deleteCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the category "<strong id="deleteCategoryName"></strong>"?</p>
                <p class="text-warning small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    This action cannot be undone. Any products in this category will need to be reassigned before deletion.
                </p>
            </div>
            <form method="POST">
                <input type="hidden" name="category_id" id="deleteCategoryId">
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_category" class="btn btn-danger">Delete Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Edit category functionality
function editCategory(id, name, description) {
    document.getElementById('editCategoryId').value = id;
    document.getElementById('editCategoryName').value = name;
    document.getElementById('editCategoryDescription').value = description;
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

// Delete category functionality
function deleteCategory(id, name) {
    document.getElementById('deleteCategoryId').value = id;
    document.getElementById('deleteCategoryName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteCategoryModal')).show();
}
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
