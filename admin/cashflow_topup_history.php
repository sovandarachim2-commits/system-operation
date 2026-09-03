<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'cashflow_topup.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

$errors = [];
$success = '';

// Handle update (from modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    require_role_or_permission(['admin'], 'cashflow_topup.update');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $topupDate = trim($_POST['topup_date'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? '') ?: null;
        $note = trim($_POST['note'] ?? '') ?: null;

        // Handle images: remove + new uploads
        $removedImages = !empty($_POST['removed_images']) ? json_decode($_POST['removed_images'], true) : [];
        $removedImages = is_array($removedImages) ? $removedImages : [];
        $current = $pdo->prepare('SELECT images FROM cashflow_topups WHERE id = ?');
        $current->execute([$id]);
        $row = $current->fetch();
        $currentImages = !empty($row['images']) ? json_decode($row['images'], true) : [];
        $currentImages = is_array($currentImages) ? $currentImages : [];
        $finalImages = array_values(array_diff($currentImages, $removedImages));
        foreach ($removedImages as $rm) {
            upload_delete_local_file($rm, 'cashflow_topup_images');
        }
        if (!empty($_FILES['new_images']['name'][0])) {
            $max_size = 2 * 1024 * 1024;
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
            foreach ($_FILES['new_images']['name'] as $key => $name) {
                if (!empty($name) && $_FILES['new_images']['error'][$key] === UPLOAD_ERR_OK && $_FILES['new_images']['size'][$key] > 0 && $_FILES['new_images']['size'][$key] <= $max_size) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed)) {
                        $fn = 'topup_' . time() . '_' . $key . '.' . $ext;
                        $storedPath = upload_store_uploaded_file([
                            'error' => $_FILES['new_images']['error'][$key],
                            'tmp_name' => $_FILES['new_images']['tmp_name'][$key],
                            'type' => $_FILES['new_images']['type'][$key] ?? '',
                        ], 'cashflow_topup_images', $fn, $topupDate, (string)($_FILES['new_images']['type'][$key] ?? ''));
                        if ($storedPath !== '') {
                            $finalImages[] = preg_replace('#^uploads/cashflow_topup_images/#', '', $storedPath);
                        }
                    }
                }
            }
        }
        $imagesJson = !empty($finalImages) ? json_encode($finalImages) : null;

        if ($paymentMethod && $amount > 0) {
            $pdo->prepare("UPDATE cashflow_topups SET topup_date=?, amount=?, payment_method=?, note=?, images=? WHERE id=?")
                ->execute([$topupDate, $amount, $paymentMethod, $note, $imagesJson, $id]);
        }
    }
    $redirect = 'cashflow_topup_history.php?updated=1';
    foreach (['from_date','to_date','bank'] as $k) {
        if (!empty($_POST['_filter_'.$k])) $redirect .= '&'.$k.'='.urlencode($_POST['_filter_'.$k]);
    }
    header('Location: ' . $redirect);
    exit;
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_role_or_permission(['admin'], 'cashflow_topup.delete');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("DELETE FROM cashflow_topups WHERE id = ?")->execute([$id]);
        $success = 'Top up deleted.';
        $redirect = 'cashflow_topup_history.php?deleted=1';
        if (!empty($_GET['from_date'])) $redirect .= '&from_date=' . urlencode($_GET['from_date']);
        if (!empty($_GET['to_date'])) $redirect .= '&to_date=' . urlencode($_GET['to_date']);
        if (!empty($_GET['bank'])) $redirect .= '&bank=' . urlencode($_GET['bank']);
        header('Location: ' . $redirect);
        exit;
    }
}

if (isset($_GET['deleted'])) $success = 'Top up deleted.';
if (isset($_GET['updated'])) $success = 'Top up updated successfully.';

$banks = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order, option_text")->fetchAll(PDO::FETCH_COLUMN);

// Filters
$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-d');
$bankFilter = trim($_GET['bank'] ?? '');

$where = ["ct.topup_date BETWEEN ? AND ?"];
$params = [$fromDate, $toDate];
if ($bankFilter) {
    $where[] = "ct.payment_method = ?";
    $params[] = $bankFilter;
}
$whereClause = implode(' AND ', $where);

try {
    $history = $pdo->prepare("
        SELECT ct.*, creator.name as created_by_name
        FROM cashflow_topups ct
        LEFT JOIN users creator ON ct.created_by = creator.id
        WHERE $whereClause
        ORDER BY ct.topup_date DESC, ct.created_at DESC
        LIMIT 200
    ");
    $history->execute($params);
    $rows = $history->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
}

$totalAmount = 0;
foreach ($rows as $r) {
    $totalAmount += (float)$r['amount'];
}

$user = function_exists('current_user') ? current_user() : null;
$canEdit = $user && (($user['role'] ?? '') === 'admin' || (function_exists('has_permission') && has_permission('cashflow_topup.update')));
$canDelete = $user && (($user['role'] ?? '') === 'admin' || (function_exists('has_permission') && has_permission('cashflow_topup.delete')));

require_once __DIR__ . '/../layout/header.php';
?>
<style>
.cf-topup-banner { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25); }
.cf-topup-total { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; border-radius: 10px; padding: 1rem; }
@media (max-width: 767.98px) {
    .cf-topup-banner .d-flex { flex-direction: column; align-items: flex-start !important; }
    .cf-topup-banner .btn { min-height: 44px; }
    .table { font-size: 0.8rem; }
    .table th, .table td { padding: 0.5rem 0.35rem; }
}
</style>
<div class="container-fluid py-4">
    <div class="cf-topup-banner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-clock-history me-2"></i>Top Up History</h1>
            <p class="mb-0 mt-1 opacity-90">View all cashflow top ups by date and bank.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_topup_add.php" class="btn btn-light btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Top Up</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_summary.php" class="btn btn-light btn-sm">Cash Flow Summary</a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <div class="card shadow-sm">
        <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Top Ups</h5>
            <div class="cf-topup-total">
                <small class="d-block">Total in period</small>
                <strong class="fs-4">$<?= number_format($totalAmount, 2) ?></strong>
            </div>
        </div>
        <div class="card-body">
            <form method="get" class="row g-2 mb-3">
                <div class="col-6 col-md-auto"><input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>"></div>
                <div class="col-6 col-md-auto"><input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>"></div>
                <div class="col-6 col-md-auto">
                    <select name="bank" class="form-select form-select-sm" style="min-width: 120px;">
                        <option value="">All banks</option>
                        <?php foreach ($banks as $b): ?>
                            <option value="<?= htmlspecialchars($b) ?>" <?= $bankFilter === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-auto"><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></div>
            </form>
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-light">
                        <tr><th>Date</th><th>Amount</th><th>Bank</th><th>Note</th><th>Created By</th><th class="text-center">Images</th><th class="text-center">Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No top ups in this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r):
                                $imgs = $r['images'] ?? '';
                                $imgArr = $imgs ? json_decode($imgs, true) : [];
                                $hasImgs = is_array($imgArr) && !empty($imgArr);
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['topup_date']) ?></td>
                                    <td class="fw-semibold text-success">$<?= number_format((float)$r['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($r['payment_method'] ?? '—') ?></td>
                                    <td class="text-truncate small" style="max-width: 150px;"><?= htmlspecialchars($r['note'] ?? '') ?></td>
                                    <td class="small"><?= htmlspecialchars($r['created_by_name'] ?? '-') ?></td>
                                    <td class="text-center">
                                        <?php if ($hasImgs): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success" title="View images" onclick="showTopupImages(<?= htmlspecialchars(json_encode($imgArr)) ?>)">
                                                <i class="bi bi-images"></i> (<?= count($imgArr) ?>)
                                            </button>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <div class="d-flex gap-1 justify-content-center">
                                            <?php if ($canEdit): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-edit-topup" title="Edit"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-date="<?= htmlspecialchars($r['topup_date']) ?>"
                                                data-amount="<?= htmlspecialchars((string)$r['amount']) ?>"
                                                data-bank="<?= htmlspecialchars($r['payment_method'] ?? '') ?>"
                                                data-note="<?= htmlspecialchars($r['note'] ?? '') ?>"
                                                data-images="<?= htmlspecialchars($r['images'] ?? '[]', ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($canDelete): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this top up?');">
                                                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                            </form>
                                            <?php endif; ?>
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

<?php if ($canEdit): ?>
<!-- Edit Top Up Modal -->
<div class="modal fade" id="editTopupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Top Up</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="editId">
                <input type="hidden" name="_filter_from_date" value="<?= htmlspecialchars($fromDate) ?>">
                <input type="hidden" name="_filter_to_date" value="<?= htmlspecialchars($toDate) ?>">
                <input type="hidden" name="_filter_bank" value="<?= htmlspecialchars($bankFilter) ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="topup_date" id="editDate" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="editAmount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Bank / Payment Method <span class="text-danger">*</span></label>
                        <select name="payment_method" id="editBank" class="form-select" required>
                            <option value="">— Select —</option>
                            <?php foreach ($banks as $b): ?><option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Note</label>
                        <textarea name="note" id="editNote" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Images</label>
                        <input type="hidden" name="removed_images" id="editTopupRemovedImages" value="">
                        <div id="editTopupImagesList" class="d-flex flex-wrap gap-2 mb-2"></div>
                        <input type="file" name="new_images[]" class="form-control form-control-sm" accept="image/*,.pdf,.doc,.docx" multiple>
                        <small class="text-muted">Add or remove images. Max 2MB per file.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

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
(function() {
    var topupRemovedImages = [];
    function renderTopupEditImages(imagesJson) {
        var container = document.getElementById('editTopupImagesList');
        var hidden = document.getElementById('editTopupRemovedImages');
        if (!container) return;
        topupRemovedImages = [];
        hidden.value = '[]';
        var images = [];
        try { images = typeof imagesJson === 'string' ? JSON.parse(imagesJson || '[]') : (imagesJson || []); } catch(e) {}
        if (!Array.isArray(images)) images = [];
        var base = <?= json_encode(str_replace('__file__', '', uploaded_file_url('__file__', 'cashflow_topup_images'))) ?>;
        var html = '';
        images.forEach(function(img) {
            var ext = (img.split('.').pop() || '').toLowerCase();
            var isImg = ['jpg','jpeg','png','gif','webp'].indexOf(ext) >= 0;
            var safeImg = (img || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;');
            html += '<div class="position-relative d-inline-block topup-edit-img-wrap" data-topup-img="' + safeImg + '">';
            if (isImg) {
                html += '<img src="' + base + img + '" class="img-thumbnail" style="max-width:80px;max-height:80px;cursor:pointer" onclick="window.open(\'' + base + img.replace(/'/g,"\\'") + '\',\'_blank\')">';
            } else {
                html += '<div class="border rounded p-2 small"><i class="bi bi-file-earmark"></i> ' + (img || '').replace(/</g,'&lt;') + '</div>';
            }
            html += '<button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0" style="padding:0 4px;line-height:1" data-topup-remove="' + safeImg + '" title="Remove"><i class="bi bi-x"></i></button></div>';
        });
        container.innerHTML = html || '<span class="text-muted small">No images</span>';
        container.querySelectorAll('[data-topup-remove]').forEach(function(btn) {
            btn.onclick = function() {
                var imgName = this.getAttribute('data-topup-remove');
                if (!imgName) return;
                topupRemovedImages.push(imgName);
                document.getElementById('editTopupRemovedImages').value = JSON.stringify(topupRemovedImages);
                var wrap = this.closest('.topup-edit-img-wrap');
                if (wrap) wrap.remove();
                if (!container.querySelector('.topup-edit-img-wrap')) {
                    container.innerHTML = '<span class="text-muted small">No images (removed)</span>';
                }
            };
        });
    }
    document.querySelectorAll('.btn-edit-topup').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var modal = document.getElementById('editTopupModal');
            if (!modal) return;
            document.getElementById('editId').value = this.dataset.id || '';
            document.getElementById('editDate').value = this.dataset.date || '';
            document.getElementById('editAmount').value = this.dataset.amount || '';
            document.getElementById('editBank').value = this.dataset.bank || '';
            document.getElementById('editNote').value = this.dataset.note || '';
            renderTopupEditImages(this.dataset.images || '[]');
            new bootstrap.Modal(modal).show();
        });
    });
})();
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
</script>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
