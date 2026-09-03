<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'bank_transfer.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

$errors = [];
$success = '';

// Handle update (from modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    require_role_or_permission(['admin'], 'bank_transfer.update');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $transferDate = trim($_POST['transfer_date'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $fromBank = trim($_POST['from_bank'] ?? '');
        $toBank = trim($_POST['to_bank'] ?? '');
        $note = trim($_POST['note'] ?? '') ?: null;
        $userId = $_SESSION['user_id'] ?? null;

        // Handle images: remove + new uploads
        $removedImages = !empty($_POST['removed_images']) ? json_decode($_POST['removed_images'], true) : [];
        $removedImages = is_array($removedImages) ? $removedImages : [];
        $current = $pdo->prepare('SELECT images FROM bank_transfers WHERE id = ?');
        $current->execute([$id]);
        $row = $current->fetch();
        $currentImages = !empty($row['images']) ? json_decode($row['images'], true) : [];
        $currentImages = is_array($currentImages) ? $currentImages : [];
        $finalImages = array_values(array_diff($currentImages, $removedImages));
        foreach ($removedImages as $rm) {
            upload_delete_local_file($rm, 'bank_transfer_images');
        }
        if (!empty($_FILES['new_images']['name'][0])) {
            $max_size = 2 * 1024 * 1024;
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
            foreach ($_FILES['new_images']['name'] as $key => $name) {
                if (!empty($name) && $_FILES['new_images']['error'][$key] === UPLOAD_ERR_OK && $_FILES['new_images']['size'][$key] > 0 && $_FILES['new_images']['size'][$key] <= $max_size) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed)) {
                        $fn = 'bt_' . time() . '_' . $key . '.' . $ext;
                        $storedPath = upload_store_uploaded_file([
                            'error' => $_FILES['new_images']['error'][$key],
                            'tmp_name' => $_FILES['new_images']['tmp_name'][$key],
                            'type' => $_FILES['new_images']['type'][$key] ?? '',
                        ], 'bank_transfer_images', $fn, $transferDate, (string)($_FILES['new_images']['type'][$key] ?? ''));
                        if ($storedPath !== '') {
                            $finalImages[] = preg_replace('#^uploads/bank_transfer_images/#', '', $storedPath);
                        }
                    }
                }
            }
        }
        $imagesJson = !empty($finalImages) ? json_encode($finalImages) : null;

        if ($fromBank && $toBank && $fromBank !== $toBank && $amount > 0) {
            $pdo->prepare("UPDATE bank_transfers SET transfer_date=?, amount=?, from_bank=?, to_bank=?, note=?, images=?, updated_by=?, updated_at=NOW() WHERE id=?")
                ->execute([$transferDate, $amount, $fromBank, $toBank, $note, $imagesJson, $userId, $id]);
        }
    }
    $redirect = 'bank_transfer_history.php?updated=1';
    foreach (['from_date','to_date','bank'] as $k) {
        if (!empty($_POST['_filter_'.$k])) $redirect .= '&'.$k.'='.urlencode($_POST['_filter_'.$k]);
    }
    header('Location: ' . $redirect);
    exit;
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_role_or_permission(['admin'], 'bank_transfer.delete');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("DELETE FROM bank_transfers WHERE id = ?")->execute([$id]);
        $success = 'Transfer deleted.';
        $redirect = 'bank_transfer_history.php?deleted=1';
        if (!empty($_GET['from_date'])) $redirect .= '&from_date=' . urlencode($_GET['from_date']);
        if (!empty($_GET['to_date'])) $redirect .= '&to_date=' . urlencode($_GET['to_date']);
        if (!empty($_GET['bank'])) $redirect .= '&bank=' . urlencode($_GET['bank']);
        header('Location: ' . $redirect);
        exit;
    }
}

if (isset($_GET['deleted'])) $success = 'Transfer deleted.';
if (isset($_GET['updated'])) $success = 'Transfer updated successfully.';

$banks = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order, option_text")->fetchAll(PDO::FETCH_COLUMN);

// Filters
$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-d');
$bankFilter = trim($_GET['bank'] ?? '');

$where = ["bt.transfer_date BETWEEN ? AND ?"];
$params = [$fromDate, $toDate];
if ($bankFilter) {
    $where[] = "(bt.from_bank = ? OR bt.to_bank = ?)";
    $params[] = $bankFilter;
    $params[] = $bankFilter;
}
$whereClause = implode(' AND ', $where);

$history = $pdo->prepare("
    SELECT bt.*, creator.name as created_by_name, updater.name as updated_by_name
    FROM bank_transfers bt
    LEFT JOIN users creator ON bt.created_by = creator.id
    LEFT JOIN users updater ON bt.updated_by = updater.id
    WHERE $whereClause
    ORDER BY bt.transfer_date DESC, bt.created_at DESC
    LIMIT 200
");
$history->execute($params);
$rows = $history->fetchAll(PDO::FETCH_ASSOC);

$totalAmount = 0;
foreach ($rows as $r) {
    $totalAmount += (float)$r['amount'];
}

$user = function_exists('current_user') ? current_user() : null;
$canEdit = $user && (($user['role'] ?? '') === 'admin' || (function_exists('has_permission') && has_permission('bank_transfer.update')));
$canDelete = $user && (($user['role'] ?? '') === 'admin' || (function_exists('has_permission') && has_permission('bank_transfer.delete')));

require_once __DIR__ . '/../layout/header.php';
?>
<style>
.bt-banner { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25); }
.bt-total { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; border-radius: 10px; padding: 1rem; }
/* Edit modal scrollable */
#editTransferModal .modal-body { overflow-y: auto !important; -webkit-overflow-scrolling: touch; max-height: 65vh; }
@media (max-width: 575.98px) { #editTransferModal .modal-body { max-height: calc(100vh - 140px) !important; } }
/* Mobile responsive */
@media (max-width: 767.98px) {
    .bt-banner .d-flex { flex-direction: column; align-items: flex-start !important; }
    .bt-banner .btn { min-height: 44px; }
    .bt-filters-form [class^="col-"] { margin-bottom: 0.5rem; }
    .bt-filters-form [class^="col-"] input, .bt-filters-form [class^="col-"] select { width: 100%; }
    .card-header .bt-total { width: 100%; text-align: center; }
    .table-responsive { -webkit-overflow-scrolling: touch; }
    .table { font-size: 0.8rem; }
    .table th, .table td { padding: 0.5rem 0.35rem; }
    .table .d-flex.gap-1 .btn { padding: 0.35rem 0.5rem; min-width: 38px; }
    .bt-col-created, .bt-col-updated { display: none !important; }
}
/* Sticky Actions column */
.table th:last-child, .table td:last-child { position: sticky; right: 0; background: #fff; box-shadow: -4px 0 6px rgba(0,0,0,0.06); }
.table thead th:last-child { background: #f8f9fa !important; z-index: 1; }
.table tbody tr:hover td:last-child { background: #f8f9fa; }
</style>
<div class="container-fluid py-4">
    <div class="bt-banner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-clock-history me-2"></i>Bank Transfer History</h1>
            <p class="mb-0 mt-1 opacity-90">View and manage bank-to-bank transfers.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/bank_transfer_add.php" class="btn btn-light btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Transfer</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_summary.php" class="btn btn-light btn-sm">Cash Flow</a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Transfers</h5>
            <div class="bt-total">
                <small class="d-block">Total in period</small>
                <strong class="fs-4">$<?= number_format($totalAmount, 2) ?></strong>
            </div>
        </div>
        <div class="card-body">
            <form method="get" class="row g-2 mb-3 bt-filters-form">
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
                        <tr><th>Date</th><th>Amount</th><th>From</th><th>To</th><th>Note</th><th class="bt-col-created">Created By</th><th class="bt-col-updated">Updated By</th><th class="text-center">Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No transfers in this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['transfer_date']) ?></td>
                                    <td class="fw-semibold">$<?= number_format((float)$r['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($r['from_bank']) ?></td>
                                    <td><?= htmlspecialchars($r['to_bank']) ?></td>
                                    <td class="text-truncate small" style="max-width: 120px;"><?= htmlspecialchars($r['note'] ?? '') ?></td>
                                    <td class="small bt-col-created" title="<?= htmlspecialchars($r['created_at'] ?? '') ?>">
                                        <?= htmlspecialchars($r['created_by_name'] ?? '-') ?>
                                        <?php if (!empty($r['created_at'])): ?><br><small class="text-muted"><?= date('M j, H:i', strtotime($r['created_at'])) ?></small><?php endif; ?>
                                    </td>
                                    <td class="small bt-col-updated" title="<?= htmlspecialchars($r['updated_at'] ?? '') ?>">
                                        <?= htmlspecialchars($r['updated_by_name'] ?? '-') ?>
                                        <?php if (!empty($r['updated_at'])): ?><br><small class="text-muted"><?= date('M j, H:i', strtotime($r['updated_at'])) ?></small><?php endif; ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <div class="d-flex gap-1 align-items-center">
                                            <?php
                                                $imgs = $r['images'] ?? '';
                                                $hasImgs = false;
                                                if ($imgs) {
                                                    $imgArr = json_decode($imgs, true);
                                                    $hasImgs = is_array($imgArr) && !empty($imgArr);
                                                }
                                                if ($hasImgs):
                                            ?>
                                            <button type="button" class="btn btn-sm btn-outline-success" title="View images" onclick="showBtImages(<?= htmlspecialchars(json_encode($imgArr)) ?>)">
                                                <i class="bi bi-images"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($canEdit): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-edit-transfer" title="Edit"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-date="<?= htmlspecialchars($r['transfer_date']) ?>"
                                                data-amount="<?= htmlspecialchars((string)$r['amount']) ?>"
                                                data-from="<?= htmlspecialchars($r['from_bank']) ?>"
                                                data-to="<?= htmlspecialchars($r['to_bank']) ?>"
                                                data-note="<?= htmlspecialchars($r['note'] ?? '') ?>"
                                                data-images="<?= htmlspecialchars($r['images'] ?? '[]', ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($canDelete): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this transfer?');">
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
<!-- Edit Transfer Modal -->
<div class="modal fade" id="editTransferModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Transfer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="editTransferForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="editId">
                <input type="hidden" name="_filter_from_date" value="<?= htmlspecialchars($fromDate) ?>">
                <input type="hidden" name="_filter_to_date" value="<?= htmlspecialchars($toDate) ?>">
                <input type="hidden" name="_filter_bank" value="<?= htmlspecialchars($bankFilter) ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="transfer_date" id="editDate" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="editAmount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">From Bank <span class="text-danger">*</span></label>
                        <select name="from_bank" id="editFrom" class="form-select" required>
                            <option value="">— Select —</option>
                            <?php foreach ($banks as $b): ?><option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">To Bank <span class="text-danger">*</span></label>
                        <select name="to_bank" id="editTo" class="form-select" required>
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
                        <input type="hidden" name="removed_images" id="editBtRemovedImages" value="">
                        <div id="editBtImagesList" class="d-flex flex-wrap gap-2 mb-2"></div>
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
<div class="modal fade" id="viewBtImagesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-images me-2"></i>Transfer Receipt / Images</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2" id="viewBtImagesContent"></div>
            </div>
        </div>
    </div>
</div>

<script>
function showBtImages(images) {
    if (!images || !Array.isArray(images) || images.length === 0) return;
    var base = <?= json_encode(str_replace('__file__', '', uploaded_file_url('__file__', 'bank_transfer_images'))) ?>;
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
    document.getElementById('viewBtImagesContent').innerHTML = html || '<p class="text-muted">No images</p>';
    new bootstrap.Modal(document.getElementById('viewBtImagesModal')).show();
}
</script>
<script>
(function() {
    var btRemovedImages = [];
    function renderBtEditImages(imagesJson) {
        var container = document.getElementById('editBtImagesList');
        var hidden = document.getElementById('editBtRemovedImages');
        if (!container) return;
        btRemovedImages = [];
        hidden.value = '[]';
        var images = [];
        try { images = typeof imagesJson === 'string' ? JSON.parse(imagesJson || '[]') : (imagesJson || []); } catch(e) {}
        if (!Array.isArray(images)) images = [];
        var base = <?= json_encode(str_replace('__file__', '', uploaded_file_url('__file__', 'bank_transfer_images'))) ?>;
        var html = '';
        images.forEach(function(img) {
            var ext = (img.split('.').pop() || '').toLowerCase();
            var isImg = ['jpg','jpeg','png','gif','webp'].indexOf(ext) >= 0;
            var safeImg = (img || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;');
            html += '<div class="position-relative d-inline-block bt-edit-img-wrap" data-bt-img="' + safeImg + '">';
            if (isImg) {
                html += '<img src="' + base + img + '" class="img-thumbnail" style="max-width:80px;max-height:80px;cursor:pointer" onclick="window.open(\'' + base + img.replace(/'/g,"\\'") + '\',\'_blank\')">';
            } else {
                html += '<div class="border rounded p-2 small"><i class="bi bi-file-earmark"></i> ' + (img || '').replace(/</g,'&lt;') + '</div>';
            }
            html += '<button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0" style="padding:0 4px;line-height:1" data-bt-remove="' + safeImg + '" title="Remove"><i class="bi bi-x"></i></button></div>';
        });
        container.innerHTML = html || '<span class="text-muted small">No images</span>';
        container.querySelectorAll('[data-bt-remove]').forEach(function(btn) {
            btn.onclick = function() {
                var imgName = this.getAttribute('data-bt-remove');
                if (!imgName) return;
                btRemovedImages.push(imgName);
                document.getElementById('editBtRemovedImages').value = JSON.stringify(btRemovedImages);
                var wrap = this.closest('.bt-edit-img-wrap');
                if (wrap) wrap.remove();
                if (!container.querySelector('.bt-edit-img-wrap')) {
                    container.innerHTML = '<span class="text-muted small">No images (removed)</span>';
                }
            };
        });
    }
    document.querySelectorAll('.btn-edit-transfer').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('editId').value = this.dataset.id || '';
            document.getElementById('editDate').value = this.dataset.date || '';
            document.getElementById('editAmount').value = this.dataset.amount || '';
            document.getElementById('editFrom').value = this.dataset.from || '';
            document.getElementById('editTo').value = this.dataset.to || '';
            document.getElementById('editNote').value = this.dataset.note || '';
            renderBtEditImages(this.dataset.images || '[]');
            new bootstrap.Modal(document.getElementById('editTransferModal')).show();
        });
    });
})();
</script>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
