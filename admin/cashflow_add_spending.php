<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'cashflow_spending.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

// Create cashflow_spending table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cashflow_spending (
        id INT AUTO_INCREMENT PRIMARY KEY,
        spending_date DATE NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        category VARCHAR(100) NOT NULL,
        sub_category VARCHAR(100) NULL,
        sub_categories TEXT NULL,
        payment_method VARCHAR(100) NULL,
        spent_by INT NULL,
        note TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Add new columns if missing
foreach (['sub_categories', 'spent_by', 'updated_by', 'updated_at', 'images'] as $col) {
    $cols = $pdo->query("SHOW COLUMNS FROM cashflow_spending")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($col, $cols)) {
        if ($col === 'sub_categories') $pdo->exec("ALTER TABLE cashflow_spending ADD COLUMN sub_categories TEXT NULL AFTER sub_category");
        if ($col === 'spent_by') $pdo->exec("ALTER TABLE cashflow_spending ADD COLUMN spent_by INT NULL AFTER payment_method");
        if ($col === 'updated_by') $pdo->exec("ALTER TABLE cashflow_spending ADD COLUMN updated_by INT NULL AFTER created_at");
        if ($col === 'updated_at') $pdo->exec("ALTER TABLE cashflow_spending ADD COLUMN updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP AFTER updated_by");
        if ($col === 'images') $pdo->exec("ALTER TABLE cashflow_spending ADD COLUMN images TEXT NULL AFTER note");
    }
}

$errors = [];
$success = '';

if (empty($_SESSION['cashflow_add_spending_form_token'])) {
    $_SESSION['cashflow_add_spending_form_token'] = bin2hex(random_bytes(16));
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
$postedSubCategories = isset($_POST['sub_categories']) && is_array($_POST['sub_categories']) ? array_values(array_filter(array_map('trim', $_POST['sub_categories']))) : [];

/**
 * Calculate closing balance for one bank up to a date (inclusive).
 */
function get_bank_closing_balance(PDO $pdo, string $bank, string $toDate, string $defaultFinanceBank = ''): float {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0)
        FROM orders
        WHERE status = 'paid'
          AND is_cancelled = 0
          AND is_returned = 0
          AND COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
          AND COALESCE(payment_date, DATE(created_at)) <= ?
    ");
    $stmt->execute([$bank, $toDate]);
    $ordersIn = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM cashflow_topups
        WHERE COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
          AND topup_date <= ?
    ");
    $stmt->execute([$bank, $toDate]);
    $topupIn = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM cashflow_spending
        WHERE COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
          AND spending_date <= ?
    ");
    $stmt->execute([$bank, $toDate]);
    $spendingOut = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM bank_transfers WHERE to_bank = ? AND transfer_date <= ?");
    $stmt->execute([$bank, $toDate]);
    $transferIn = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM bank_transfers WHERE from_bank = ? AND transfer_date <= ?");
    $stmt->execute([$bank, $toDate]);
    $transferOut = (float)$stmt->fetchColumn();

    return $ordersIn + $topupIn - $spendingOut + $transferIn - $transferOut;
}

// Handle add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    require_role_or_permission(['admin'], 'cashflow_spending.create');
    $submittedToken = (string)($_POST['form_token'] ?? '');
    $sessionToken = (string)($_SESSION['cashflow_add_spending_form_token'] ?? '');
    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Duplicate or invalid submit detected. Please try again.';
    } else {
        // Rotate token immediately so browser POST-resend cannot insert again.
        $_SESSION['cashflow_add_spending_form_token'] = bin2hex(random_bytes(16));
    }
    $spendingDate = trim($_POST['spending_date'] ?? date('Y-m-d'));
    $amount = (float)($_POST['amount'] ?? 0);
    $category = trim($_POST['category'] ?? '');
    $subCategoriesList = isset($_POST['sub_categories']) && is_array($_POST['sub_categories']) ? array_filter(array_map('trim', $_POST['sub_categories'])) : [];
    $subCategory = !empty($subCategoriesList) ? $subCategoriesList[0] : null;
    $subCategoriesJson = !empty($subCategoriesList) ? json_encode(array_values($subCategoriesList)) : null;
    $paymentMethod = trim($_POST['payment_method'] ?? '') ?: null;
    $spentBy = !empty($_POST['spent_by']) ? (int)$_POST['spent_by'] : null;
    $note = trim($_POST['note'] ?? '') ?: null;
    $userId = $_SESSION['user_id'] ?? null;

    // Handle image uploads
    $uploaded_images = [];
    if (!empty($_FILES['spending_images']['name'][0])) {
        $max_size = 2 * 1024 * 1024; // 2MB
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
        foreach ($_FILES['spending_images']['name'] as $key => $name) {
            if (!empty($name)) {
                $file_tmp = $_FILES['spending_images']['tmp_name'][$key];
                $file_size = $_FILES['spending_images']['size'][$key];
                $file_error = $_FILES['spending_images']['error'][$key];
                if ($file_error === UPLOAD_ERR_OK && $file_size > 0 && $file_size <= $max_size) {
                    $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (in_array($file_ext, $allowed_extensions)) {
                        $new_filename = 'cf_' . time() . '_' . $key . '.' . $file_ext;
                        $storedPath = upload_store_uploaded_file([
                            'error' => $file_error,
                            'tmp_name' => $file_tmp,
                            'type' => $_FILES['spending_images']['type'][$key] ?? '',
                        ], 'spending_images', $new_filename, $spendingDate, (string)($_FILES['spending_images']['type'][$key] ?? ''));
                        if ($storedPath !== '') {
                            $uploaded_images[] = preg_replace('#^uploads/spending_images/#', '', $storedPath);
                        }
                    }
                }
            }
        }
    }
    $images_json = !empty($uploaded_images) ? json_encode($uploaded_images) : null;

    if (empty($category)) {
        $errors[] = 'Category is required.';
    }
    if (empty($subCategoriesList)) {
        $errors[] = 'At least one subcategory is required.';
    }
    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than 0.';
    }
    if (empty($paymentMethod)) {
        $errors[] = 'Payment method is required.';
    }
    if (!empty($paymentMethod)) {
        $defaultFinanceBank = '';
        try {
            $stmt = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_finance_default = 1 ORDER BY id ASC LIMIT 1");
            $defaultFinanceBank = trim((string)$stmt->fetchColumn());
            if ($defaultFinanceBank === '') {
                $defaultFinanceBank = (string)($noteOptions[0] ?? '');
            }
        } catch (PDOException $e) {}

        try {
            $available = get_bank_closing_balance($pdo, (string)$paymentMethod, $spendingDate, $defaultFinanceBank);
            if ($amount > $available) {
                $errors[] = 'Insufficient bank balance for this spending. Available on ' . htmlspecialchars($spendingDate) . ' is $' . number_format($available, 2) . '.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Unable to verify bank balance at the moment.';
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("INSERT INTO cashflow_spending (spending_date, amount, category, sub_category, sub_categories, payment_method, spent_by, note, images, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$spendingDate, $amount, $category, $subCategory, $subCategoriesJson, $paymentMethod, $spentBy, $note, $images_json, $userId]);
        $success = 'Spending added successfully.';
        header('Location: cashflow_add_spending.php?success=1');
        exit;
    }
}

if (isset($_GET['success'])) $success = 'Spending added successfully.';

// Recent spending (last 15)
$recentSpending = [];
try {
    $stmt = $pdo->query("
        SELECT cs.*, u.name as spent_by_name
        FROM cashflow_spending cs
        LEFT JOIN users u ON cs.spent_by = u.id
        ORDER BY cs.spending_date DESC, cs.created_at DESC
        LIMIT 15
    ");
    $recentSpending = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

require_once __DIR__ . '/../layout/header.php';
?>
<style>
.cf-spend-banner { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25); }
.cf-spend-card { border-left: 4px solid #0d9488; }
.cf-spend-card .card-header { background: #f0fdfa; color: #0f766e; font-weight: 600; }
.cf-btn-teal { background: #0d9488; border-color: #0d9488; color: white; }
.cf-btn-teal:hover { background: #0f766e; border-color: #0f766e; color: white; }
.subcat-box-invalid {
    border-color: #dc2626 !important;
    background: #fff5f5 !important;
    box-shadow: 0 0 0 0.2rem rgba(220, 38, 38, 0.15);
}
.subcat-error-text {
    color: #dc2626;
    font-size: 0.875rem;
    margin-top: 0.35rem;
    display: none;
}
.subcat-error-text.show {
    display: block;
}
.cf-feedback-alert {
    border: 0;
    border-radius: 12px;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
}
.cf-feedback-alert.alert-success {
    background: linear-gradient(135deg, #047857 0%, #10b981 100%);
    color: #fff;
}
.cf-feedback-alert.alert-danger {
    background: linear-gradient(135deg, #b91c1c 0%, #ef4444 100%);
    color: #fff;
}
.cf-success-toast {
    position: fixed;
    top: 50%;
    left: 50%;
    z-index: 1090;
    width: min(420px, calc(100vw - 24px));
    background: linear-gradient(135deg, #047857 0%, #10b981 100%);
    color: #fff;
    border-radius: 14px;
    box-shadow: 0 14px 30px rgba(16, 185, 129, 0.35);
    transform: translate(-50%, -50%);
    overflow: hidden;
}
.cf-success-toast .toast-head { display: flex; gap: 10px; padding: 14px 16px 10px; }
.cf-success-toast .toast-title { margin: 0; font-weight: 700; font-size: 1rem; }
.cf-success-toast .toast-desc { margin: 2px 0 0; font-size: 0.9rem; opacity: 0.96; }
.cf-success-toast .toast-close { margin-left: auto; border: 0; background: transparent; color: #fff; font-size: 1.1rem; line-height: 1; cursor: pointer; }
.cf-success-toast .toast-progress { height: 4px; background: rgba(255,255,255,0.25); }
.cf-success-toast .toast-progress span { display: block; height: 100%; width: 100%; background: rgba(255,255,255,0.85); transform-origin: left; animation: cfToastProgress 3s linear forwards; }
.cf-error-toast {
    position: fixed;
    top: 50%;
    left: 50%;
    z-index: 1091;
    width: min(440px, calc(100vw - 24px));
    background: linear-gradient(135deg, #b91c1c 0%, #ef4444 100%);
    color: #fff;
    border-radius: 14px;
    box-shadow: 0 14px 30px rgba(239, 68, 68, 0.35);
    transform: translate(-50%, -50%);
    overflow: hidden;
}
.cf-error-toast .toast-head { padding: 14px 16px 10px; }
.cf-error-toast .toast-title { margin: 0; font-weight: 700; font-size: 1rem; }
.cf-error-toast .toast-desc { margin: 6px 0 0; font-size: 0.9rem; white-space: pre-line; opacity: 0.97; }
.cf-error-toast .toast-progress { height: 4px; background: rgba(255,255,255,0.25); }
.cf-error-toast .toast-progress span { display: block; height: 100%; width: 100%; background: rgba(255,255,255,0.85); transform-origin: left; animation: cfToastProgress 3s linear forwards; }
@keyframes cfToastProgress { from { transform: scaleX(1); } to { transform: scaleX(0); } }
/* Mobile responsive */
@media (max-width: 767.98px) {
    .cf-spend-banner .d-flex { flex-direction: column; align-items: flex-start !important; }
    .cf-spend-banner .btn { min-height: 44px; }
    .cf-spend-banner .gap-2 .btn { min-width: 44px; }
    .container-fluid { padding-left: 0.75rem; padding-right: 0.75rem; }
    .recent-spending-wrap { max-height: 320px !important; }
    .recent-spending-wrap .table { font-size: 0.8rem; }
    .recent-spending-wrap .table th, .recent-spending-wrap .table td { padding: 0.5rem 0.35rem; }
}
</style>
<div class="container-fluid py-4">
    <?php if (isset($_GET['success'])): ?>
        <div class="cf-success-toast" id="cfSuccessToast" role="status" aria-live="polite">
            <div class="toast-head">
                <div>
                    <p class="toast-title">Saved Successfully</p>
                    <p class="toast-desc">Cashflow spending record has been created.</p>
                </div>
                <button type="button" class="toast-close" aria-label="Close" onclick="closeCfSuccessToast()">&times;</button>
            </div>
            <div class="toast-progress"><span></span></div>
        </div>
    <?php endif; ?>

    <div class="cf-spend-banner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-wallet2 me-2"></i>Cash Flow Spending</h1>
            <p class="mb-0 mt-1 opacity-90">Record money going out from cash flow. Uses Cash Flow categories.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_spending_history.php" class="btn btn-light btn-sm"><i class="bi bi-clock-history me-1"></i>Spending History</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow.php" class="btn btn-light btn-sm">Cash Flow Report</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_categories.php" class="btn btn-light btn-sm">Categories</a>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6 col-xl-5 mb-4">
            <div class="card shadow-sm cf-spend-card">
                <div class="card-header py-3"><h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add Spending</h5></div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" id="cashflowSpendingForm" novalidate>
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_SESSION['cashflow_add_spending_form_token']) ?>">
                        <div class="mb-3">
                            <label class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" name="spending_date" class="form-control" value="<?= htmlspecialchars($_POST['spending_date'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" placeholder="0.00" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Main Category <span class="text-danger">*</span></label>
                            <select name="category" id="mainCat" class="form-select" required>
                                <option value="">— Select category —</option>
                                <?php foreach ($mainCategories as $mc): ?>
                                    <option value="<?= htmlspecialchars($mc['name']) ?>" <?= (($_POST['category'] ?? '') === $mc['name']) ? 'selected' : '' ?>><?= htmlspecialchars($mc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($mainCategories)): ?>
                                <small class="text-warning"><a href="cashflow_categories.php">Add categories first</a></small>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subcategories (select one or more) <span class="text-danger">*</span></label>
                            <div id="subCatCheckboxes" class="border rounded p-2" style="max-height: 120px; overflow-y: auto; background: #f8f9fa;">
                                <small class="text-muted">Select main category first</small>
                            </div>
                            <div id="subCatError" class="subcat-error-text">
                                <i class="bi bi-exclamation-circle me-1"></i>Please select at least one subcategory.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">User (Spent By)</label>
                            <select name="spent_by" class="form-select">
                                <option value="">— Optional —</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>" <?= ((string)($_POST['spent_by'] ?? '') === (string)$u['id']) ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" class="form-select" required>
                                <option value="">— Select payment method —</option>
                                <?php foreach ($noteOptions as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= (($_POST['payment_method'] ?? '') === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note</label>
                            <textarea name="note" class="form-control" rows="2" placeholder="Optional"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Upload Images (Optional)</label>
                            <input type="file" name="spending_images[]" class="form-control" accept="image/*,.pdf,.doc,.docx" multiple>
                            <small class="text-muted">Receipts, documents (JPG, PNG, GIF, PDF, DOC, DOCX). Max 2MB per file.</small>
                            <div id="imagePreview" class="mt-2 d-flex flex-wrap gap-2"></div>
                        </div>
                        <button type="submit" class="btn cf-btn-teal w-100"><i class="bi bi-plus-lg me-1"></i>Add Spending</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6 col-xl-7 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Spending</h5>
                    <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_spending_history.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0 recent-spending-wrap" style="max-height: 480px; overflow-y: auto; -webkit-overflow-scrolling: touch;">
                    <?php if (empty($recentSpending)): ?>
                        <div class="text-center text-muted py-4">No spending records yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead class="table-light sticky-top">
                                <tr><th>Date</th><th>Amount</th><th>Category</th><th>Sub</th><th>User</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentSpending as $r):
                                    $subsDisplay = $r['sub_categories'] ?? '';
                                    if ($subsDisplay) {
                                        $arr = json_decode($subsDisplay, true);
                                        $subsDisplay = is_array($arr) ? implode(', ', $arr) : ($r['sub_category'] ?? '-');
                                    } else {
                                        $subsDisplay = $r['sub_category'] ?? '-';
                                    }
                                ?>
                                    <tr>
                                        <td class="text-nowrap"><?= htmlspecialchars($r['spending_date']) ?></td>
                                        <td class="fw-semibold text-danger">$<?= number_format((float)$r['amount'], 2) ?></td>
                                        <td><?= htmlspecialchars($r['category']) ?></td>
                                        <td class="small text-truncate" style="max-width: 80px;" title="<?= htmlspecialchars($subsDisplay) ?>"><?= htmlspecialchars($subsDisplay) ?></td>
                                        <td class="small"><?= htmlspecialchars($r['spent_by_name'] ?? '-') ?></td>
                                    </tr>
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
<script>
const postedSubCategories = <?= json_encode($postedSubCategories, JSON_UNESCAPED_UNICODE) ?>;

function renderSubCategoryCheckboxes(main, selectedSubs) {
    var container = document.getElementById('subCatCheckboxes');
    if (!container) return;
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
            if (Array.isArray(selectedSubs) && selectedSubs.indexOf(s.name) !== -1) {
                cb.checked = true;
            }
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

document.getElementById('mainCat')?.addEventListener('change', function() {
    renderSubCategoryCheckboxes(this.value, []);
    const subCatBox = document.getElementById('subCatCheckboxes');
    const subCatError = document.getElementById('subCatError');
    if (subCatBox) subCatBox.classList.remove('subcat-box-invalid');
    if (subCatError) subCatError.classList.remove('show');
});

// Image preview
document.querySelector('input[name="spending_images[]"]')?.addEventListener('change', function(e) {
    const files = e.target.files;
    const preview = document.getElementById('imagePreview');
    if (!preview) return;
    preview.innerHTML = '';
    Array.from(files).forEach((file, index) => {
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(ev) {
                const div = document.createElement('div');
                div.className = 'position-relative d-inline-block';
                div.innerHTML = '<img src="' + ev.target.result + '" class="img-thumbnail" style="max-width: 80px; max-height: 80px;"><button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1" onclick="removeCfImage(' + index + ')"><i class="bi bi-x"></i></button>';
                preview.appendChild(div);
            };
            reader.readAsDataURL(file);
        } else {
            const div = document.createElement('div');
            div.className = 'd-inline-block border rounded p-2 bg-white small';
            div.innerHTML = '<i class="bi bi-file-earmark text-primary"></i> ' + file.name + ' <button type="button" class="btn btn-danger btn-sm ms-1" onclick="removeCfImage(' + index + ')"><i class="bi bi-x"></i></button>';
            preview.appendChild(div);
        }
    });
});
function removeCfImage(index) {
    const input = document.querySelector('input[name="spending_images[]"]');
    const files = Array.from(input.files);
    files.splice(index, 1);
    const dt = new DataTransfer();
    files.forEach(function(f) { dt.items.add(f); });
    input.files = dt.files;
    input.dispatchEvent(new Event('change'));
}

function closeCfSuccessToast() {
    const toast = document.getElementById('cfSuccessToast');
    if (!toast) return;
    toast.remove();
}

window.addEventListener('DOMContentLoaded', function() {
    const serverErrors = <?= json_encode(array_values($errors), JSON_UNESCAPED_UNICODE) ?>;

    function showErrorPopup(message, durationMs) {
        const old = document.getElementById('cfErrorToast');
        if (old) old.remove();
        const toast = document.createElement('div');
        toast.id = 'cfErrorToast';
        toast.className = 'cf-error-toast';
        toast.innerHTML = `
            <div class="toast-head">
                <p class="toast-title">Please fix this first</p>
                <p class="toast-desc">${String(message).replace(/</g, '&lt;')}</p>
            </div>
            <div class="toast-progress"><span></span></div>
        `;
        document.body.appendChild(toast);
        setTimeout(function() {
            const el = document.getElementById('cfErrorToast');
            if (el) el.remove();
        }, durationMs || 3000);
    }

    const toast = document.getElementById('cfSuccessToast');
    if (toast) {
        setTimeout(closeCfSuccessToast, 3000);
    }

    if (Array.isArray(serverErrors) && serverErrors.length > 0) {
        showErrorPopup(serverErrors.join('\n'), 3500);
    }

    const form = document.getElementById('cashflowSpendingForm');
    if (!form) return;
    const subCatBox = document.getElementById('subCatCheckboxes');
    const subCatError = document.getElementById('subCatError');

    const currentMainCategory = document.getElementById('mainCat')?.value || '';
    if (currentMainCategory) {
        renderSubCategoryCheckboxes(currentMainCategory, postedSubCategories);
    }

    form.addEventListener('change', function(e) {
        if (e.target && e.target.name === 'sub_categories[]') {
            if (subCatBox) subCatBox.classList.remove('subcat-box-invalid');
            if (subCatError) subCatError.classList.remove('show');
        }
    });

    form.addEventListener('submit', function(e) {
        const checkedSubCats = form.querySelectorAll('input[name="sub_categories[]"]:checked');
        if (checkedSubCats.length === 0) {
            e.preventDefault();
            e.stopPropagation();
            if (subCatBox) subCatBox.classList.add('subcat-box-invalid');
            if (subCatError) subCatError.classList.add('show');
            const subCatSection = subCatBox || subCatError;
            if (subCatSection) {
                subCatSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            alert('Missing:\n- Subcategories');
            return;
        }
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            const missing = [];
            form.querySelectorAll('[required]').forEach(function(field) {
                if (field.disabled) return;
                if (field.value !== null && String(field.value).trim() !== '') return;
                const wrapper = field.closest('.mb-3');
                const labelEl = wrapper ? wrapper.querySelector('.form-label') : null;
                let label = labelEl ? labelEl.textContent : (field.name || 'Field');
                label = String(label).replace('*', '').trim();
                if (label && missing.indexOf(label) === -1) {
                    missing.push(label);
                }
            });
            const detail = missing.length ? ('Missing:\n- ' + missing.join('\n- ')) : 'Please fill all required fields before saving.';
            showErrorPopup(detail, 3200);
        }
    });
});
</script>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
