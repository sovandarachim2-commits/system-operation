<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'cashflow_spending.update');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();
$errors = [];
$success = '';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$row = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM cashflow_spending WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$row) {
    header('Location: cashflow_spending_history.php?error=notfound');
    exit;
}

// Load categories
$mainCategories = $pdo->query("SELECT * FROM cashflow_categories WHERE type = 'main' AND is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
$subCategories = [];
$subRaw = $pdo->query("SELECT * FROM cashflow_categories WHERE type = 'sub' AND is_active = 1 ORDER BY parent_category, sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
foreach ($subRaw as $sc) {
    $subCategories[$sc['parent_category']][] = $sc;
}
$noteOptions = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order, option_text")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Parse existing subcategories
$selectedSubs = [];
if (!empty($row['sub_categories'])) {
    $arr = json_decode($row['sub_categories'], true);
    $selectedSubs = is_array($arr) ? $arr : [];
} elseif (!empty($row['sub_category'])) {
    $selectedSubs = [$row['sub_category']];
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $spendingDate = trim($_POST['spending_date'] ?? $row['spending_date']);
    $amount = (float)($_POST['amount'] ?? 0);
    $category = trim($_POST['category'] ?? '');
    $subCategoriesList = isset($_POST['sub_categories']) && is_array($_POST['sub_categories']) ? array_filter(array_map('trim', $_POST['sub_categories'])) : [];
    $subCategory = !empty($subCategoriesList) ? $subCategoriesList[0] : null;
    $subCategoriesJson = !empty($subCategoriesList) ? json_encode(array_values($subCategoriesList)) : null;
    $paymentMethod = trim($_POST['payment_method'] ?? '') ?: null;
    $spentBy = !empty($_POST['spent_by']) ? (int)$_POST['spent_by'] : null;
    $note = trim($_POST['note'] ?? '') ?: null;
    $userId = $_SESSION['user_id'] ?? null;

    if (empty($category)) {
        $errors[] = 'Category is required.';
    } elseif ($amount <= 0) {
        $errors[] = 'Amount must be greater than 0.';
    } else {
        $stmt = $pdo->prepare("UPDATE cashflow_spending SET spending_date=?, amount=?, category=?, sub_category=?, sub_categories=?, payment_method=?, spent_by=?, note=?, updated_by=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$spendingDate, $amount, $category, $subCategory, $subCategoriesJson, $paymentMethod, $spentBy, $note, $userId, $id]);
        $success = 'Spending updated successfully.';
        header('Location: cashflow_spending_history.php?updated=1');
        exit;
    }
}

require_once __DIR__ . '/../layout/header.php';
?>
<style>
.cf-edit-banner { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25); }
.cf-spend-card { border-left: 4px solid #0d9488; }
.cf-spend-card .card-header { background: #f0fdfa; color: #0f766e; font-weight: 600; }
.cf-btn-teal { background: #0d9488; border-color: #0d9488; color: white; }
.cf-btn-teal:hover { background: #0f766e; border-color: #0f766e; color: white; }
</style>
<div class="container-fluid py-4">
    <div class="cf-edit-banner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-pencil me-2"></i>Edit Spending</h1>
            <p class="mb-0 mt-1 opacity-90">Edit spending record #<?= (int)$row['id'] ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_spending_history.php" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to History</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_add_spending.php" class="btn btn-light btn-sm">Add Spending</a>
        </div>
    </div>

    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <div class="row">
        <div class="col-lg-6 col-xl-5 mb-4">
            <div class="card shadow-sm cf-spend-card">
                <div class="card-header py-3"><h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Update Spending</h5></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                        <div class="mb-3">
                            <label class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" name="spending_date" class="form-control" value="<?= htmlspecialchars($row['spending_date']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" placeholder="0.00" value="<?= htmlspecialchars((string)$row['amount']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Main Category <span class="text-danger">*</span></label>
                            <select name="category" id="mainCat" class="form-select" required>
                                <option value="">— Select category —</option>
                                <?php foreach ($mainCategories as $mc): ?>
                                    <option value="<?= htmlspecialchars($mc['name']) ?>" <?= $row['category'] === $mc['name'] ? 'selected' : '' ?>><?= htmlspecialchars($mc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subcategories (select one or more)</label>
                            <div id="subCatCheckboxes" class="border rounded p-2" style="max-height: 120px; overflow-y: auto; background: #f8f9fa;"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">User (Spent By)</label>
                            <select name="spent_by" class="form-select">
                                <option value="">— Optional —</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>" <?= (int)($row['spent_by'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="">— Optional —</option>
                                <?php foreach ($noteOptions as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($row['payment_method'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note</label>
                            <textarea name="note" class="form-control" rows="2" placeholder="Optional"><?= htmlspecialchars($row['note'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="btn cf-btn-teal w-100"><i class="bi bi-check-lg me-1"></i>Update Spending</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    var selectedSubs = <?= json_encode($selectedSubs) ?>;
    var main = document.getElementById('mainCat').value;
    var container = document.getElementById('subCatCheckboxes');
    function renderSubs() {
        main = document.getElementById('mainCat').value;
        container.innerHTML = '';
        <?php if (!empty($subCategories)): ?>
        var subs = <?= json_encode($subCategories) ?>;
        if (main && subs[main]) {
            subs[main].forEach(function(s) {
                var label = document.createElement('label');
                label.className = 'd-block mb-1';
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.name = 'sub_categories[]';
                cb.value = s.name;
                cb.className = 'form-check-input me-2';
                if (selectedSubs.indexOf(s.name) >= 0) cb.checked = true;
                label.appendChild(cb);
                label.appendChild(document.createTextNode(s.name));
                container.appendChild(label);
            });
        } else {
            container.innerHTML = '<small class="text-muted">No subcategories for this category</small>';
        }
        <?php else: ?>
        container.innerHTML = '<small class="text-muted">No subcategories</small>';
        <?php endif; ?>
    }
    renderSubs();
    document.getElementById('mainCat')?.addEventListener('change', function() {
        selectedSubs = [];
        renderSubs();
    });
})();
</script>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
