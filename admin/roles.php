<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'roles.view');

$pdo = get_db_connection();
$me = current_user();
$isMainAdmin = (($me['username'] ?? '') === 'admin');

if (!has_permission('roles.view') && !has_permission('role_permissions.view') && !$isMainAdmin) {
    http_response_code(403);
    exit('Access denied');
}

// Ensure roles table exists and has description column
$pdo->exec("
    CREATE TABLE IF NOT EXISTS roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(32) NOT NULL UNIQUE,
        label VARCHAR(100) NOT NULL,
        description VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Add description if missing (existing DBs)
try {
    $pdo->exec("ALTER TABLE roles ADD COLUMN description VARCHAR(255) NULL AFTER label");
} catch (Throwable $e) {
    // Column may already exist
}

$errors = [];
$success = '';

function normalize_role_name(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?? $name;
    $name = trim($name, '_');
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        require_role_or_permission(['admin'], 'roles.create');
        $name = normalize_role_name($_POST['name'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '' || $label === '') {
            $errors[] = 'Role name and label are required.';
        } elseif (strlen($name) > 32) {
            $errors[] = 'Role name is too long.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO roles(name, label, description) VALUES(?, ?, ?)");
                $stmt->execute([$name, $label, $description ?: null]);
                $success = 'Role created.';
            } catch (Throwable $e) {
                $errors[] = 'Failed to create role (maybe already exists).';
            }
        }
    }

    if ($action === 'update') {
        require_role_or_permission(['admin'], 'roles.update');
        $id = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($id <= 0 || $label === '') {
            $errors[] = 'Invalid role update.';
        } else {
            $stmt = $pdo->prepare("UPDATE roles SET label = ?, description = ? WHERE id = ?");
            $stmt->execute([$label, $description ?: null, $id]);
            $success = 'Role updated.';
        }
    }

    if ($action === 'delete') {
        require_role_or_permission(['admin'], 'roles.delete');
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if (in_array($name, ['admin', 'seller', 'cashier', 'scanner'], true)) {
            $errors[] = 'Core roles cannot be deleted.';
        } elseif ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
            $stmt->execute([$id]);
            $success = 'Role deleted.';
        }
    }
}

// Search and pagination
$search = trim($_GET['search'] ?? '');
$perPage = (int)($_GET['per_page'] ?? 10);
$perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = '';
$params = [];
if ($search !== '') {
    $where = " WHERE name LIKE ? OR label LIKE ? OR COALESCE(description,'') LIKE ?";
    $p = '%' . $search . '%';
    $params = [$p, $p, $p];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM roles" . $where);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = $totalRows > 0 ? (int) ceil($totalRows / $perPage) : 1;
$page = min($page, max(1, $totalPages));
$offset = ($page - 1) * $perPage;

$roles = [];
if ($totalRows > 0) {
    $sql = "SELECT id, name, label, description, created_at FROM roles" . $where . " ORDER BY name ASC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $roles = $stmt->fetchAll();
}

function formatCreatedAt(?string $dt): string
{
    if (!$dt) return '';
    $t = strtotime($dt);
    return $t ? date('d-m-Y H:i:s', $t) : $dt;
}

require_once __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">User Role</h1>
        <div class="d-flex gap-2 flex-wrap">
            <a href="roles.php<?= $search ? '?search=' . urlencode($search) . '&per_page=' . $perPage : '' ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addRoleModal"><i class="bi bi-plus me-1"></i>Add</button>
            <a href="#" id="btnEdit" class="btn btn-outline-info" style="display:none;" title="Manage what this role can do (View/Create/Update/Delete)"><i class="bi bi-pencil-square me-1"></i>Edit</a>
            <form method="post" id="formDelete" style="display:none;" onsubmit="return confirm('Delete the selected role? This will also remove its permissions and user assignments.');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId" value="">
                <input type="hidden" name="name" id="deleteName" value="">
                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete Record</button>
            </form>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="d-flex flex-wrap justify-content-between align-items-center p-3 border-bottom gap-2">
                <div class="d-flex gap-2 align-items-center">
                    <form method="get" class="d-flex gap-2">
                        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                        <input type="search" name="search" class="form-control form-control-sm" style="min-width:180px;" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>
                    </form>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small">Show</span>
                    <select class="form-select form-select-sm" style="width:auto;" onchange="location.href='?search=<?= urlencode($search) ?>&per_page='+this.value">
                        <?php foreach ([10, 25, 50, 100] as $n): ?>
                            <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="text-muted small">per page</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px;"></th>
                            <th>Role</th>
                            <th>User Type</th>
                            <th>Description</th>
                            <th>Created at</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($roles as $r): ?>
                        <tr class="role-row" data-id="<?= (int)$r['id'] ?>" data-name="<?= htmlspecialchars($r['name']) ?>" data-label="<?= htmlspecialchars($r['label']) ?>" role="button">
                            <td><i class="bi bi-circle role-unselected"></i><i class="bi bi-check-circle-fill text-primary role-selected" style="display:none;"></i></td>
                            <td class="fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
                            <td><?= htmlspecialchars($r['label']) ?></td>
                            <td class="text-muted"><?= htmlspecialchars($r['description'] ?? '') ?></td>
                            <td class="text-muted small"><?= htmlspecialchars(formatCreatedAt($r['created_at'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($roles)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No roles found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="d-flex justify-content-between align-items-center p-3 border-top">
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?search=<?= urlencode($search) ?>&per_page=<?= $perPage ?>&page=<?= $page - 1 ?>">Previous</a>
                        </li>
                        <?php for ($i = 1; $i <= min($totalPages, 5); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?search=<?= urlencode($search) ?>&per_page=<?= $perPage ?>&page=<?= $i ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?search=<?= urlencode($search) ?>&per_page=<?= $perPage ?>&page=<?= $page + 1 ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <span class="text-muted small">Page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> records)</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit modal (opens when Edit clicked with selected row) -->
<div class="modal fade" id="editRoleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="editId" value="">
                    <div>
                        <label class="form-label">Role name</label>
                        <input type="text" class="form-control form-control-lg" id="editName" disabled>
                    </div>
                    <div>
                        <label class="form-label">User Type (Label)</label>
                        <input name="label" class="form-control form-control-lg" id="editLabel" required>
                    </div>
                    <div>
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" id="editDesc" rows="2" placeholder="Optional"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="btnEditPermissions" class="btn btn-info me-auto">Edit Permissions (View/Create/Update/Delete)</a>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="addRoleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="form-label">Role name (code)</label>
                        <input name="name" class="form-control form-control-lg" placeholder="e.g. warehouse_manager" required>
                        <div class="text-muted small mt-1">Lowercase, letters/numbers/underscore.</div>
                    </div>
                    <div>
                        <label class="form-label">User Type (Label)</label>
                        <input name="label" class="form-control form-control-lg" placeholder="Warehouse Manager" required>
                    </div>
                    <div>
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Optional"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    const rows = document.querySelectorAll('.role-row');
    const btnEdit = document.getElementById('btnEdit');
    const formDelete = document.getElementById('formDelete');
    const deleteId = document.getElementById('deleteId');
    const deleteName = document.getElementById('deleteName');
    const editModal = document.getElementById('editRoleModal');
    const editId = document.getElementById('editId');
    const editName = document.getElementById('editName');
    const editLabel = document.getElementById('editLabel');
    const editDesc = document.getElementById('editDesc');
    const btnEditPermissions = document.getElementById('btnEditPermissions');

    let selectedRow = null;

    function clearSelection() {
        rows.forEach(r => {
            r.classList.remove('table-info');
            r.querySelector('.role-unselected').style.display = '';
            r.querySelector('.role-selected').style.display = 'none';
        });
        selectedRow = null;
        btnEdit.style.display = 'none';
        formDelete.style.display = 'none';
    }

    function selectRow(row) {
        clearSelection();
        if (!row) return;
        selectedRow = row;
        row.classList.add('table-info');
        row.querySelector('.role-unselected').style.display = 'none';
        row.querySelector('.role-selected').style.display = 'inline';
        const id = row.dataset.id;
        const name = row.dataset.name;
        const label = row.dataset.label;
        deleteId.value = id;
        deleteName.value = name;
        btnEdit.style.display = 'inline-block';
        btnEdit.href = 'role_permissions.php?role=' + encodeURIComponent(name);
        if (!['admin','seller','cashier','scanner'].includes(name)) {
            formDelete.style.display = 'inline-block';
        }
    }

    rows.forEach(r => {
        r.addEventListener('click', function(e) {
            if (e.target.closest('a') || e.target.closest('button')) return;
            selectRow(this);
        });
    });

    btnEdit.addEventListener('click', function(e) {
        e.preventDefault();
        if (!selectedRow) return;
        const id = selectedRow.dataset.id;
        const name = selectedRow.dataset.name;
        const label = selectedRow.dataset.label;
        const desc = (selectedRow.querySelector('td:nth-child(4)')?.textContent?.trim() || '').trim();
        editId.value = id;
        editName.value = name;
        editLabel.value = label;
        editDesc.value = desc;
        btnEditPermissions.href = 'role_permissions.php?role=' + encodeURIComponent(name);
        new bootstrap.Modal(editModal).show();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') clearSelection();
    });
})();
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
