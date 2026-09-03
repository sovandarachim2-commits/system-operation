<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'cashflow_topup.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

// Create cashflow_topups table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cashflow_topups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        topup_date DATE NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        payment_method VARCHAR(100) NULL,
        note TEXT NULL,
        images TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$cols = $pdo->query("SHOW COLUMNS FROM cashflow_topups")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('payment_method', $cols)) $pdo->exec("ALTER TABLE cashflow_topups ADD COLUMN payment_method VARCHAR(100) NULL AFTER amount");

$errors = [];
$banks = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order, option_text")->fetchAll(PDO::FETCH_COLUMN);

$success = '';

// Handle add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    require_role_or_permission(['admin'], 'cashflow_topup.create');
    $topupDate = trim($_POST['topup_date'] ?? date('Y-m-d'));
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? '') ?: null;
    $note = trim($_POST['note'] ?? '') ?: null;
    $userId = $_SESSION['user_id'] ?? null;

    // Handle image uploads
    $uploaded_images = [];
    if (!empty($_FILES['topup_images']['name'][0])) {
        $max_size = 2 * 1024 * 1024; // 2MB
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
        foreach ($_FILES['topup_images']['name'] as $key => $name) {
            if (!empty($name)) {
                $file_tmp = $_FILES['topup_images']['tmp_name'][$key];
                $file_size = $_FILES['topup_images']['size'][$key];
                $file_error = $_FILES['topup_images']['error'][$key];
                if ($file_error === UPLOAD_ERR_OK && $file_size > 0 && $file_size <= $max_size) {
                    $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (in_array($file_ext, $allowed_extensions)) {
                        $new_filename = 'topup_' . time() . '_' . $key . '.' . $file_ext;
                        $storedPath = upload_store_uploaded_file([
                            'error' => $file_error,
                            'tmp_name' => $file_tmp,
                            'type' => $_FILES['topup_images']['type'][$key] ?? '',
                        ], 'cashflow_topup_images', $new_filename, $topupDate, (string)($_FILES['topup_images']['type'][$key] ?? ''));
                        if ($storedPath !== '') {
                            $uploaded_images[] = preg_replace('#^uploads/cashflow_topup_images/#', '', $storedPath);
                        }
                    }
                }
            }
        }
    }
    $images_json = !empty($uploaded_images) ? json_encode($uploaded_images) : null;

    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than 0.';
    } elseif (empty($paymentMethod)) {
        $errors[] = 'Bank / Payment method is required.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO cashflow_topups (topup_date, amount, payment_method, note, images, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$topupDate, $amount, $paymentMethod, $note, $images_json, $userId]);
        $success = 'Top up added successfully.';
        header('Location: cashflow_topup_add.php?success=1');
        exit;
    }
}

if (isset($_GET['success'])) $success = 'Top up added successfully.';

// Recent topups (last 10)
$recentTopups = [];
try {
    $recentTopups = $pdo->query("SELECT * FROM cashflow_topups ORDER BY topup_date DESC, created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

require_once __DIR__ . '/../layout/header.php';
?>
<style>
.cf-topup-banner { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25); }
.cf-topup-card { border-left: 4px solid #0d9488; }
.cf-topup-card .card-header { background: #f0fdfa; color: #0f766e; font-weight: 600; }
.cf-btn-teal { background: #0d9488; border-color: #0d9488; color: white; }
.cf-btn-teal:hover { background: #0f766e; border-color: #0f766e; color: white; }
</style>
<div class="container-fluid py-4">
    <div class="cf-topup-banner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-wallet2 me-2"></i>Cash Flow Top Up</h1>
            <p class="mb-0 mt-1 opacity-90">Add money to cashflow balance (capital injection, etc.)</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_topup_history.php" class="btn btn-light btn-sm"><i class="bi bi-clock-history me-1"></i>Top Up History</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_summary.php" class="btn btn-light btn-sm">Cash Flow Summary</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_add_spending.php" class="btn btn-light btn-sm">Add Spending</a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <div class="row">
        <div class="col-lg-5 mb-4">
            <div class="card shadow-sm cf-topup-card">
                <div class="card-header py-3"><h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add Top Up</h5></div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" name="topup_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" placeholder="0.00" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Bank / Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" class="form-select" required>
                                <option value="">— Select bank —</option>
                                <?php foreach ($banks as $b): ?>
                                    <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($banks)): ?><small class="text-warning"><a href="manage_note_options.php">Add payment methods (banks) first</a></small><?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note</label>
                            <textarea name="note" class="form-control" rows="2" placeholder="Optional - e.g. Capital injection, Loan"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Upload Images (Optional)</label>
                            <input type="file" name="topup_images[]" class="form-control" accept="image/*,.pdf,.doc,.docx" multiple>
                            <small class="text-muted">Receipts (JPG, PNG, GIF, PDF, DOC, DOCX). Max 2MB per file.</small>
                            <div id="imagePreview" class="mt-2 d-flex flex-wrap gap-2"></div>
                        </div>
                        <button type="submit" class="btn cf-btn-teal w-100"><i class="bi bi-plus-lg me-1"></i>Add Top Up</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Top Ups</h5>
                    <a href="cashflow_topup_history.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentTopups)): ?>
                        <div class="text-center text-muted py-4">No top ups yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="table-light">
                                    <tr><th>Date</th><th>Amount</th><th>Bank</th><th>Note</th><th>Images</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentTopups as $r): 
                                        $imgs = $r['images'] ?? '';
                                        $imgArr = $imgs ? json_decode($imgs, true) : [];
                                        $hasImgs = is_array($imgArr) && !empty($imgArr);
                                    ?>
                                        <tr>
                                            <td><?= htmlspecialchars($r['topup_date']) ?></td>
                                            <td class="fw-semibold text-success">$<?= number_format((float)$r['amount'], 2) ?></td>
                                            <td><?= htmlspecialchars($r['payment_method'] ?? '—') ?></td>
                                            <td class="text-truncate small" style="max-width: 120px;"><?= htmlspecialchars($r['note'] ?? '') ?></td>
                                            <td>
                                                <?php if ($hasImgs): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-success" title="View images" onclick="showTopupImages(<?= htmlspecialchars(json_encode($imgArr)) ?>)">
                                                        <i class="bi bi-images"></i> (<?= count($imgArr) ?>)
                                                    </button>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
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

<!-- View Images Modal -->
<div class="modal fade" id="viewTopupImagesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-images me-2"></i>Top Up Receipt / Images</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2" id="viewTopupImagesContent"></div>
            </div>
        </div>
    </div>
</div>

<script>
function showTopupImages(images) {
    if (!images || !Array.isArray(images) || images.length === 0) return;
    var base = <?= json_encode(str_replace('__file__', '', uploaded_file_url('__file__', 'cashflow_topup_images'))) ?>;
    var html = '';
    images.forEach(function(img, i) {
        var ext = (img.split('.').pop() || '').toLowerCase();
        var isImg = ['jpg','jpeg','png','gif','webp'].indexOf(ext) >= 0;
        if (isImg) {
            html += '<div class="col-6 col-md-4"><a href="' + base + img + '" target="_blank" class="d-block"><img src="' + base + img + '" class="img-fluid img-thumbnail" alt="Image ' + (i+1) + '" style="max-height: 180px; object-fit: cover; width: 100%;"></a></div>';
        } else {
            html += '<div class="col-6 col-md-4"><a href="' + base + img + '" target="_blank" class="btn btn-outline-primary w-100"><i class="bi bi-file-earmark me-1"></i>' + img + '</a></div>';
        }
    });
    document.getElementById('viewTopupImagesContent').innerHTML = html || '<p class="text-muted">No images</p>';
    new bootstrap.Modal(document.getElementById('viewTopupImagesModal')).show();
}
document.querySelector('input[name="topup_images[]"]')?.addEventListener('change', function(e) {
    var files = e.target.files;
    var preview = document.getElementById('imagePreview');
    if (!preview) return;
    preview.innerHTML = '';
    Array.from(files).forEach(function(file, index) {
        if (file.type.startsWith('image/')) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                var div = document.createElement('div');
                div.className = 'position-relative d-inline-block';
                div.innerHTML = '<img src="' + ev.target.result + '" class="img-thumbnail" style="max-width: 80px; max-height: 80px;"><button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1" onclick="removeTopupImage(' + index + ')"><i class="bi bi-x"></i></button>';
                preview.appendChild(div);
            };
            reader.readAsDataURL(file);
        } else {
            var div = document.createElement('div');
            div.className = 'd-inline-block border rounded p-2 bg-white small';
            div.innerHTML = '<i class="bi bi-file-earmark text-primary"></i> ' + file.name + ' <button type="button" class="btn btn-danger btn-sm ms-1" onclick="removeTopupImage(' + index + ')"><i class="bi bi-x"></i></button>';
            preview.appendChild(div);
        }
    });
});
function removeTopupImage(index) {
    var input = document.querySelector('input[name="topup_images[]"]');
    var files = Array.from(input.files);
    files.splice(index, 1);
    var dt = new DataTransfer();
    files.forEach(function(f) { dt.items.add(f); });
    input.files = dt.files;
    input.dispatchEvent(new Event('change'));
}
</script>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
