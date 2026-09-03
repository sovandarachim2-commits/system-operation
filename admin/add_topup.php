<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_dashboard.view', 'sr_expense_topup.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

$note_cols = [];
try {
    $note_cols = $pdo->query("SHOW COLUMNS FROM note_options")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $note_cols = [];
}
if (!in_array('is_finance_default', $note_cols, true)) {
    try {
        $pdo->exec("ALTER TABLE note_options ADD COLUMN is_finance_default TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
    } catch (PDOException $e) {}
}
$bank_options = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY is_finance_default DESC, sort_order, option_text")->fetchAll(PDO::FETCH_COLUMN);
$bank_options = array_values(array_filter(array_map('trim', $bank_options), static function ($v) { return $v !== ''; }));
$default_finance_bank = $bank_options[0] ?? '';

// Get users for dropdown
$stmt = $pdo->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name");
$users = $stmt->fetchAll();

require_once __DIR__ . '/../layout/header.php';
?>

<style>
.add-page .page-header {
    background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    color: #fff;
    box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35);
}
.add-page .page-header h2 { margin: 0; font-weight: 600; }
.add-page .page-header .subtitle { color: rgba(255,255,255,0.9); margin: 0.25rem 0 0; }
.add-page .form-card {
    border-radius: 12px;
    overflow: hidden;
    border: none;
    box-shadow: 0 4px 20px rgba(5, 150, 105, 0.15);
}
.add-page .form-card .card-header {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    color: #fff;
    padding: 1rem 1.25rem;
    font-weight: 600;
}
.add-page .form-card .card-body {
    padding: 1.5rem;
}
.add-page .form-card .form-control:focus,
.add-page .form-card .form-select:focus {
    border-color: #10b981;
    box-shadow: 0 0 0 0.2rem rgba(16, 185, 129, 0.25);
}
.add-page .btn-success {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border: none;
}
.add-page .btn-success:hover {
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
    border: none;
    transform: translateY(-1px);
}
.add-page .input-group-text {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border-color: #6ee7b7;
    color: #047857;
    font-weight: 600;
}
.add-page .form-label {
    font-weight: 600;
    color: #374151;
}
.add-page .text-muted { color: #212529 !important; }
</style>

<div class="container-fluid py-3 add-page">
    <div class="row mb-4">
        <div class="col-12">
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h2><i class="bi bi-wallet2 me-2"></i>Top Up Money</h2>
                        <p class="subtitle mb-0">Add money to the finance system</p>
                    </div>
                    <a href="topup_report.php" class="btn btn-light btn-sm">
                        <i class="bi bi-clock-history me-1"></i>Top Up History
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['success']) || isset($_GET['topup_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i>Money topped up successfully!
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['topup_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars(urldecode($_GET['topup_error'])) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8 col-xl-6">
            <div class="card form-card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>New Top Up</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="process_topup.php" enctype="multipart/form-data" id="topupForm">
                        <input type="hidden" name="redirect" value="add_topup.php">
                        <div class="mb-3">
                            <label for="topupAmount" class="form-label">Amount *</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="topupAmount" name="amount"
                                       step="0.01" min="0.01" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="topupSource" class="form-label">Source *</label>
                            <select class="form-select" id="topupSource" name="source" required>
                                <option value="">Select source</option>
                                <?php foreach ($bank_options as $bank): ?>
                                    <option value="<?= htmlspecialchars($bank) ?>" <?= $bank === $default_finance_bank ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($bank) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="topupPerson" class="form-label">Person Name *</label>
                            <select class="form-select" id="topupPerson" name="person_name" required>
                                <option value="">Select person</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= htmlspecialchars($user['name']) ?>">
                                        <?= htmlspecialchars($user['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="other">Other (Specify in description)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="topupDate" class="form-label">Date *</label>
                            <input type="date" class="form-control" id="topupDate" name="topup_date"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="topupReceipt" class="form-label">Receipt Image</label>
                            <input type="file" class="form-control" id="topupReceipt" name="receipt_image"
                                   accept="image/*">
                            <small class="form-text text-muted">Upload receipt or proof of payment (optional)</small>
                        </div>
                        <div class="mb-3">
                            <label for="topupDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="topupDescription" name="description" rows="3"
                                      placeholder="Add any notes about this top-up..."></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success" id="topupSubmitBtn">
                                <i class="bi bi-check-circle me-1"></i>Top Up Money
                            </button>
                            <a href="finance_dashboard.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle me-1"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Prevent double-submit
document.getElementById('topupForm').addEventListener('submit', function() {
    var btn = document.getElementById('topupSubmitBtn');
    if (btn && !btn.disabled) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Processing...';
    }
});
// Clean URL after redirect (refresh = fresh page, no resubmit)
if (window.location.search.includes('topup_success=') || window.location.search.includes('topup_error=')) {
    history.replaceState({}, '', window.location.pathname);
}
</script>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
