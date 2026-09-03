<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'bank_transfer.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

// Create bank_transfers table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS bank_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_date DATE NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        from_bank VARCHAR(100) NOT NULL,
        to_bank VARCHAR(100) NOT NULL,
        note TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Add updated_by, updated_at, images if missing
$cols = $pdo->query("SHOW COLUMNS FROM bank_transfers")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('updated_by', $cols)) $pdo->exec("ALTER TABLE bank_transfers ADD COLUMN updated_by INT NULL AFTER created_at");
if (!in_array('updated_at', $cols)) $pdo->exec("ALTER TABLE bank_transfers ADD COLUMN updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP AFTER updated_by");
if (!in_array('images', $cols)) $pdo->exec("ALTER TABLE bank_transfers ADD COLUMN images TEXT NULL AFTER note");

$errors = [];
$success = '';

// Load banks from note_options (payment methods)
$banks = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order, option_text")->fetchAll(PDO::FETCH_COLUMN);

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
    require_role_or_permission(['admin'], 'bank_transfer.create');
    $transferDate = trim($_POST['transfer_date'] ?? date('Y-m-d'));
    $amount = (float)($_POST['amount'] ?? 0);
    $fromBank = trim($_POST['from_bank'] ?? '');
    $toBank = trim($_POST['to_bank'] ?? '');
    $note = trim($_POST['note'] ?? '') ?: null;
    $userId = $_SESSION['user_id'] ?? null;

    // Handle image uploads
    $uploaded_images = [];
    if (!empty($_FILES['transfer_images']['name'][0])) {
        $max_size = 2 * 1024 * 1024; // 2MB
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
        foreach ($_FILES['transfer_images']['name'] as $key => $name) {
            if (!empty($name)) {
                $file_tmp = $_FILES['transfer_images']['tmp_name'][$key];
                $file_size = $_FILES['transfer_images']['size'][$key];
                $file_error = $_FILES['transfer_images']['error'][$key];
                if ($file_error === UPLOAD_ERR_OK && $file_size > 0 && $file_size <= $max_size) {
                    $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (in_array($file_ext, $allowed_extensions)) {
                        $new_filename = 'bt_' . time() . '_' . $key . '.' . $file_ext;
                        $storedPath = upload_store_uploaded_file([
                            'error' => $file_error,
                            'tmp_name' => $file_tmp,
                            'type' => $_FILES['transfer_images']['type'][$key] ?? '',
                        ], 'bank_transfer_images', $new_filename, $transferDate, (string)($_FILES['transfer_images']['type'][$key] ?? ''));
                        if ($storedPath !== '') {
                            $uploaded_images[] = preg_replace('#^uploads/bank_transfer_images/#', '', $storedPath);
                        }
                    }
                }
            }
        }
    }
    $images_json = !empty($uploaded_images) ? json_encode($uploaded_images) : null;

    if (empty($fromBank)) {
        $errors[] = 'From bank is required.';
    } elseif (empty($toBank)) {
        $errors[] = 'To bank is required.';
    } elseif ($fromBank === $toBank) {
        $errors[] = 'From and To bank must be different.';
    } elseif ($amount <= 0) {
        $errors[] = 'Amount must be greater than 0.';
    } else {
        try {
            $stmt = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_finance_default = 1 ORDER BY id ASC LIMIT 1");
            $defaultFinanceBank = trim((string)$stmt->fetchColumn());
            if ($defaultFinanceBank === '') {
                $defaultFinanceBank = (string)($banks[0] ?? '');
            }

            $available = get_bank_closing_balance($pdo, (string)$fromBank, $transferDate, $defaultFinanceBank);
            if ($amount > $available) {
                $errors[] = 'Insufficient balance in ' . $fromBank . '. Available on ' . $transferDate . ' is $' . number_format($available, 2) . '.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Unable to verify source bank balance at the moment.';
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("INSERT INTO bank_transfers (transfer_date, amount, from_bank, to_bank, note, images, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$transferDate, $amount, $fromBank, $toBank, $note, $images_json, $userId]);
        $success = 'Transfer recorded successfully.';
        header('Location: bank_transfer_add.php?success=1');
        exit;
    }
}

if (isset($_GET['success'])) $success = 'Transfer recorded successfully.';

// Recent transfers
$recentTransfers = [];
try {
    $recentTransfers = $pdo->query("SELECT * FROM bank_transfers ORDER BY transfer_date DESC, created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

require_once __DIR__ . '/../layout/header.php';
?>
<style>
.bt-banner { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25); }
.bt-card { border-left: 4px solid #0d9488; }
.bt-card .card-header { background: #f0fdfa; color: #0f766e; font-weight: 600; }
.bt-btn { background: #0d9488; border-color: #0d9488; color: white; }
.bt-btn:hover { background: #0f766e; border-color: #0f766e; color: white; }
</style>
<div class="container-fluid py-4">
    <div class="bt-banner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-bank me-2"></i>Transfer to Other Bank</h1>
            <p class="mb-0 mt-1 opacity-90">Record money transfers between bank accounts.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/bank_transfer_history.php" class="btn btn-light btn-sm"><i class="bi bi-clock-history me-1"></i>Transfer History</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_summary.php" class="btn btn-light btn-sm">Cash Flow</a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <div class="row">
        <div class="col-lg-5 mb-4">
            <div class="card shadow-sm bt-card">
                <div class="card-header py-3"><h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add Transfer</h5></div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" name="transfer_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" placeholder="0.00" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">From Bank <span class="text-danger">*</span></label>
                            <select name="from_bank" class="form-select" required>
                                <option value="">— Select —</option>
                                <?php foreach ($banks as $b): ?>
                                    <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($banks)): ?><small class="text-warning"><a href="manage_note_options.php">Add payment methods (banks) first</a></small><?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">To Bank <span class="text-danger">*</span></label>
                            <select name="to_bank" class="form-select" required>
                                <option value="">— Select —</option>
                                <?php foreach ($banks as $b): ?>
                                    <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note</label>
                            <textarea name="note" class="form-control" rows="2" placeholder="Optional"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Upload Images (Optional)</label>
                            <input type="file" name="transfer_images[]" class="form-control" accept="image/*,.pdf,.doc,.docx" multiple>
                            <small class="text-muted">Receipts, documents (JPG, PNG, GIF, PDF, DOC, DOCX). Max 2MB per file.</small>
                            <div id="imagePreview" class="mt-2 d-flex flex-wrap gap-2"></div>
                        </div>
                        <button type="submit" class="btn bt-btn w-100"><i class="bi bi-arrow-left-right me-1"></i>Record Transfer</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Transfers</h5>
                    <a href="bank_transfer_history.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentTransfers)): ?>
                        <div class="text-center text-muted py-4">No transfers yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="table-light">
                                    <tr><th>Date</th><th>Amount</th><th>From</th><th>To</th><th>Note</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentTransfers as $r): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($r['transfer_date']) ?></td>
                                            <td class="fw-semibold">$<?= number_format((float)$r['amount'], 2) ?></td>
                                            <td><?= htmlspecialchars($r['from_bank']) ?></td>
                                            <td><?= htmlspecialchars($r['to_bank']) ?></td>
                                            <td class="text-truncate small" style="max-width: 120px;"><?= htmlspecialchars($r['note'] ?? '') ?></td>
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
document.querySelector('input[name="transfer_images[]"]')?.addEventListener('change', function(e) {
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
                div.innerHTML = '<img src="' + ev.target.result + '" class="img-thumbnail" style="max-width: 80px; max-height: 80px;"><button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1" onclick="removeBtImage(' + index + ')"><i class="bi bi-x"></i></button>';
                preview.appendChild(div);
            };
            reader.readAsDataURL(file);
        } else {
            const div = document.createElement('div');
            div.className = 'd-inline-block border rounded p-2 bg-white small';
            div.innerHTML = '<i class="bi bi-file-earmark text-primary"></i> ' + file.name + ' <button type="button" class="btn btn-danger btn-sm ms-1" onclick="removeBtImage(' + index + ')"><i class="bi bi-x"></i></button>';
            preview.appendChild(div);
        }
    });
});
function removeBtImage(index) {
    const input = document.querySelector('input[name="transfer_images[]"]');
    const files = Array.from(input.files);
    files.splice(index, 1);
    const dt = new DataTransfer();
    files.forEach(function(f) { dt.items.add(f); });
    input.files = dt.files;
    input.dispatchEvent(new Event('change'));
}
</script>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
