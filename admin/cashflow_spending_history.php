<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'cashflow_spending.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

$errors = [];
$success = '';

// Handle update (from modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    require_role_or_permission(['admin'], 'cashflow_spending.update');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $spendingDate = trim($_POST['spending_date'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $category = trim($_POST['category'] ?? '');
        $subCategoriesList = isset($_POST['sub_categories']) && is_array($_POST['sub_categories']) ? array_filter(array_map('trim', $_POST['sub_categories'])) : [];
        $subCategory = !empty($subCategoriesList) ? $subCategoriesList[0] : null;
        $subCategoriesJson = !empty($subCategoriesList) ? json_encode(array_values($subCategoriesList)) : null;
        $paymentMethod = trim($_POST['payment_method'] ?? '') ?: null;
        $spentBy = !empty($_POST['spent_by']) ? (int)$_POST['spent_by'] : null;
        $note = trim($_POST['note'] ?? '') ?: null;
        $userId = $_SESSION['user_id'] ?? null;

        // Handle images: remove + new uploads
        $removedImages = !empty($_POST['removed_images']) ? json_decode($_POST['removed_images'], true) : [];
        $removedImages = is_array($removedImages) ? $removedImages : [];
        $current = $pdo->prepare('SELECT images FROM cashflow_spending WHERE id = ?');
        $current->execute([$id]);
        $row = $current->fetch();
        $currentImages = !empty($row['images']) ? json_decode($row['images'], true) : [];
        $currentImages = is_array($currentImages) ? $currentImages : [];
        $finalImages = array_values(array_diff($currentImages, $removedImages));
        foreach ($removedImages as $rm) {
            upload_delete_local_file($rm, 'spending_images');
        }
        if (!empty($_FILES['new_images']['name'][0])) {
            $max_size = 2 * 1024 * 1024;
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
            foreach ($_FILES['new_images']['name'] as $key => $name) {
                if (!empty($name) && $_FILES['new_images']['error'][$key] === UPLOAD_ERR_OK && $_FILES['new_images']['size'][$key] > 0 && $_FILES['new_images']['size'][$key] <= $max_size) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed)) {
                        $fn = 'cf_' . time() . '_' . $key . '.' . $ext;
                        $storedPath = upload_store_uploaded_file([
                            'error' => $_FILES['new_images']['error'][$key],
                            'tmp_name' => $_FILES['new_images']['tmp_name'][$key],
                            'type' => $_FILES['new_images']['type'][$key] ?? '',
                        ], 'spending_images', $fn, $spendingDate, (string)($_FILES['new_images']['type'][$key] ?? ''));
                        if ($storedPath !== '') {
                            $finalImages[] = preg_replace('#^uploads/spending_images/#', '', $storedPath);
                        }
                    }
                }
            }
        }
        $imagesJson = !empty($finalImages) ? json_encode($finalImages) : null;

        if ($category && $amount > 0) {
            $pdo->prepare("UPDATE cashflow_spending SET spending_date=?, amount=?, category=?, sub_category=?, sub_categories=?, payment_method=?, spent_by=?, note=?, images=?, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$spendingDate, $amount, $category, $subCategory, $subCategoriesJson, $paymentMethod, $spentBy, $note, $imagesJson, $userId, $id]);
        }
    }
    $redirect = 'cashflow_spending_history.php?updated=1';
    foreach (['from_date','to_date','category','user_id'] as $k) {
        if (!empty($_POST['_filter_'.$k])) $redirect .= '&'.$k.'='.urlencode($_POST['_filter_'.$k]);
    }
    header('Location: ' . $redirect);
    exit;
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_role_or_permission(['admin'], 'cashflow_spending.delete');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("DELETE FROM cashflow_spending WHERE id = ?")->execute([$id]);
        $success = 'Spending deleted.';
        $redirect = 'cashflow_spending_history.php?deleted=1';
        if (!empty($_GET['from_date'])) $redirect .= '&from_date=' . urlencode($_GET['from_date']);
        if (!empty($_GET['to_date'])) $redirect .= '&to_date=' . urlencode($_GET['to_date']);
        if (!empty($_GET['category'])) $redirect .= '&category=' . urlencode($_GET['category']);
        if (isset($_GET['user_id']) && $_GET['user_id'] !== '') $redirect .= '&user_id=' . urlencode($_GET['user_id']);
        header('Location: ' . $redirect);
        exit;
    }
}

if (isset($_GET['deleted'])) $success = 'Spending deleted.';
if (isset($_GET['updated'])) $success = 'Spending updated successfully.';

// Load data
$mainCategories = $pdo->query("SELECT * FROM cashflow_categories WHERE type = 'main' AND is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
$subCategories = [];
$subRaw = $pdo->query("SELECT * FROM cashflow_categories WHERE type = 'sub' AND is_active = 1 ORDER BY parent_category, sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
foreach ($subRaw as $sc) {
    $subCategories[$sc['parent_category']][] = $sc;
}
$noteOptions = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order, option_text")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Filters
$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-d');
$catFilter = $_GET['category'] ?? '';
$userFilter = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;

$where = ["cs.spending_date BETWEEN ? AND ?"];
$params = [$fromDate, $toDate];
if ($catFilter) {
    $where[] = "cs.category = ?";
    $params[] = $catFilter;
}
if ($userFilter > 0) {
    $where[] = "cs.spent_by = ?";
    $params[] = $userFilter;
}
$whereClause = implode(' AND ', $where);

$history = $pdo->prepare("
    SELECT cs.*, u.name as spent_by_name,
           creator.name as created_by_name, cs.created_at,
           updater.name as updated_by_name, cs.updated_at
    FROM cashflow_spending cs
    LEFT JOIN users u ON cs.spent_by = u.id
    LEFT JOIN users creator ON cs.created_by = creator.id
    LEFT JOIN users updater ON cs.updated_by = updater.id
    WHERE $whereClause
    ORDER BY cs.spending_date DESC, cs.created_at DESC
    LIMIT 200
");
$history->execute($params);
$historyRows = $history->fetchAll(PDO::FETCH_ASSOC);

$totalSpending = 0;
foreach ($historyRows as $r) {
    $totalSpending += (float)$r['amount'];
}

$user = function_exists('current_user') ? current_user() : null;
$canEdit = $user && (($user['role'] ?? '') === 'admin' || (function_exists('has_permission') && has_permission('cashflow_spending.update')));
$canDelete = $user && (($user['role'] ?? '') === 'admin' || (function_exists('has_permission') && has_permission('cashflow_spending.delete')));

// Export to Excel (styled HTML with colors - red for amounts, easy to read)
if (isset($_GET['export']) && ($_GET['export'] === 'excel' || $_GET['export'] === 'csv')) {
    $filename = 'spending_history_' . $fromDate . '_to_' . $toDate . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"><style>
      body{font-family:Arial,sans-serif;font-size:11px;margin:8px}
      table{border-collapse:collapse;width:100%;border:1px solid #999}
      th,td{border:1px solid #999;padding:6px 8px;vertical-align:middle}
      .thead{background:#0d9488!important;color:#fff!important;font-weight:bold;text-align:center}
      .tfoot{background:#fee2e2!important;color:#b91c1c!important;font-weight:bold}
      .num{text-align:right}
      .amount{color:#dc2626;font-weight:bold}
    </style></head><body>';
    echo '<table><tr><td colspan="3" style="background:#0d9488;color:#fff;font-size:14px;font-weight:bold;padding:10px">Cash Flow Spending History</td></tr>';
    echo '<tr><td style="background:#f3f4f6;width:120px">Period</td><td colspan="2">' . $h(date('M d, Y', strtotime($fromDate)) . ' - ' . date('M d, Y', strtotime($toDate))) . '</td></tr>';
    echo '<tr><td style="background:#f3f4f6">Generated</td><td colspan="2">' . $h(date('M d, Y H:i')) . '</td></tr>';
    echo '<tr><td style="background:#f3f4f6">Total Spending</td><td colspan="2" style="color:#dc2626;font-weight:bold;font-size:13px">$' . number_format($totalSpending, 2) . '</td></tr></table><br/>';

    echo '<table><tr><td colspan="9" style="background:#dc2626;color:#fff;font-weight:bold;padding:8px">Spending Records (Amounts in red = money out)</td></tr>';
    echo '<tr class="thead"><th>No</th><th>Date</th><th class="num">Amount</th><th>Category</th><th>Subcategories</th><th>User</th><th>Payment</th><th>Note</th><th>Created By</th></tr>';
    $no = 1;
    foreach ($historyRows as $r) {
        $subsDisplay = $r['sub_categories'] ?? '';
        if ($subsDisplay) {
            $arr = json_decode($subsDisplay, true);
            $subsDisplay = is_array($arr) ? implode(', ', $arr) : ($r['sub_category'] ?? '-');
        } else {
            $subsDisplay = $r['sub_category'] ?? '-';
        }
        echo '<tr>';
        echo '<td>' . $no++ . '</td>';
        echo '<td>' . $h($r['spending_date']) . '</td>';
        echo '<td class="num amount">$' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . $h($r['category']) . '</td>';
        echo '<td>' . $h($subsDisplay) . '</td>';
        echo '<td>' . $h($r['spent_by_name'] ?? '-') . '</td>';
        echo '<td>' . $h($r['payment_method'] ?? '-') . '</td>';
        echo '<td>' . $h($r['note'] ?? '') . '</td>';
        echo '<td>' . $h($r['created_by_name'] ?? '-') . '</td>';
        echo '</tr>';
    }
    echo '<tr class="tfoot"><td colspan="2" style="text-align:right">TOTAL</td><td class="num">$' . number_format($totalSpending, 2) . '</td><td colspan="6"></td></tr>';
    echo '</table></body></html>';
    exit;
}

require_once __DIR__ . '/../layout/header.php';
?>
<style>
.cf-history-banner { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25); }
.cf-out-card { background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%); color: white; border-radius: 10px; padding: 1rem; }
/* Edit modal - ensure scrollable on all screens */
#editSpendingModal .modal-body { overflow-y: auto !important; -webkit-overflow-scrolling: touch; }
@media (max-width: 575.98px) {
    #editSpendingModal .modal-body { max-height: calc(100vh - 140px) !important; }
}
/* Mobile responsive */
@media (max-width: 767.98px) {
    .cf-history-banner .d-flex { flex-direction: column; align-items: flex-start !important; }
    .cf-history-banner .d-flex.gap-2 { flex-wrap: wrap; }
    .cf-history-banner .btn { min-height: 44px; }
    .cf-filters-form [class^="col-"] { margin-bottom: 0.5rem; }
    .cf-filters-form [class^="col-"] input, .cf-filters-form [class^="col-"] select { width: 100%; }
    .card-header .cf-out-card { width: 100%; text-align: center; }
    .table-responsive { -webkit-overflow-scrolling: touch; margin: 0 -1rem; padding: 0 1rem; }
    .table { font-size: 0.8rem; }
    .table th, .table td { padding: 0.5rem 0.35rem; white-space: nowrap; }
    .table .d-flex.gap-1 .btn { padding: 0.35rem 0.5rem; min-width: 38px; }
}
/* Sticky Actions column - always visible when horizontal scroll */
.table-responsive { position: relative; }
.table th:last-child, .table td:last-child {
    position: sticky; right: 0; background: #fff; box-shadow: -4px 0 6px rgba(0,0,0,0.06); z-index: 0;
}
.table thead th:last-child { background: #f8f9fa !important; z-index: 1; }
.table tbody tr:hover td:last-child { background: #f8f9fa; }
</style>
<div class="container-fluid py-4">
    <div class="cf-history-banner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-clock-history me-2"></i>Cash Flow Spending History</h1>
            <p class="mb-0 mt-1 opacity-90">View and manage all cash flow spending records.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="cashflow_spending_history.php?export=excel&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>&category=<?= urlencode($catFilter) ?>&user_id=<?= $userFilter ? (int)$userFilter : '' ?>" class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-excel me-1"></i>Export to Excel
            </a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_add_spending.php" class="btn btn-light btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Spending</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow.php" class="btn btn-light btn-sm">Cash Flow Report</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_summary.php" class="btn btn-light btn-sm">Summary</a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <div class="card shadow-sm">
        <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Spending Records</h5>
            <div class="cf-out-card">
                <small class="d-block">Total in period</small>
                <strong class="fs-4">$<?= number_format($totalSpending, 2) ?></strong>
            </div>
        </div>
        <div class="card-body">
            <form method="get" class="row g-2 mb-3 cf-filters-form">
                <div class="col-6 col-md-auto"><input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>"></div>
                <div class="col-6 col-md-auto"><input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>"></div>
                <div class="col-6 col-md-auto">
                    <select name="category" class="form-select form-select-sm" style="min-width: 120px;">
                        <option value="">All categories</option>
                        <?php foreach ($mainCategories as $mc): ?>
                            <option value="<?= htmlspecialchars($mc['name']) ?>" <?= $catFilter === $mc['name'] ? 'selected' : '' ?>><?= htmlspecialchars($mc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-auto">
                    <select name="user_id" class="form-select form-select-sm" style="min-width: 100px;">
                        <option value="">All users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= $userFilter === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-auto"><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></div>
            </form>
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-light">
                        <tr><th>Date</th><th>Amount</th><th>Category</th><th>Subcategories</th><th>User</th><th>Payment</th><th>Note</th><th>Created By</th><th>Updated By</th><th class="text-center">Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($historyRows)): ?>
                            <tr><td colspan="10" class="text-center py-4 text-muted">No spending in this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($historyRows as $r): 
                                $subsDisplay = $r['sub_categories'] ?? '';
                                if ($subsDisplay) {
                                    $arr = json_decode($subsDisplay, true);
                                    $subsDisplay = is_array($arr) ? implode(', ', $arr) : ($r['sub_category'] ?? '-');
                                } else {
                                    $subsDisplay = $r['sub_category'] ?? '-';
                                }
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['spending_date']) ?></td>
                                    <td class="fw-semibold text-danger">$<?= number_format((float)$r['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($r['category']) ?></td>
                                    <td class="small"><?= htmlspecialchars($subsDisplay) ?></td>
                                    <td><?= htmlspecialchars($r['spent_by_name'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($r['payment_method'] ?? '-') ?></td>
                                    <td class="text-truncate" style="max-width: 120px;"><?= htmlspecialchars($r['note'] ?? '') ?></td>
                                    <td class="small" title="Created: <?= htmlspecialchars($r['created_at'] ?? '') ?>">
                                        <span class="d-inline-block"><?= htmlspecialchars($r['created_by_name'] ?? '-') ?></span>
                                        <?php if (!empty($r['created_at'])): ?>
                                            <br><small class="text-muted"><?= date('M j, H:i', strtotime($r['created_at'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small" title="Updated: <?= htmlspecialchars($r['updated_at'] ?? '') ?>">
                                        <span class="d-inline-block"><?= htmlspecialchars($r['updated_by_name'] ?? '-') ?></span>
                                        <?php if (!empty($r['updated_at'])): ?>
                                            <br><small class="text-muted"><?= date('M j, H:i', strtotime($r['updated_at'])) ?></small>
                                        <?php endif; ?>
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
                                            <button type="button" class="btn btn-sm btn-outline-success" title="View images" onclick="showCfImages(<?= htmlspecialchars(json_encode($imgArr)) ?>)">
                                                <i class="bi bi-images"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($canEdit): 
                                                $subsJson = $r['sub_categories'] ?? '';
                                                if (!$subsJson && !empty($r['sub_category'])) $subsJson = json_encode([$r['sub_category']]);
                                                if (!$subsJson) $subsJson = '[]';
                                            ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-edit-spending" title="Edit"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-date="<?= htmlspecialchars($r['spending_date']) ?>"
                                                data-amount="<?= htmlspecialchars((string)$r['amount']) ?>"
                                                data-category="<?= htmlspecialchars($r['category']) ?>"
                                                data-subcategories="<?= htmlspecialchars($subsJson) ?>"
                                                data-spent-by="<?= !empty($r['spent_by']) ? (int)$r['spent_by'] : '' ?>"
                                                data-payment="<?= htmlspecialchars($r['payment_method'] ?? '') ?>"
                                                data-note="<?= htmlspecialchars($r['note'] ?? '') ?>"
                                                data-images="<?= htmlspecialchars($r['images'] ?? '[]', ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($canDelete): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this spending?');">
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
<!-- Edit Spending Modal -->
<div class="modal fade" id="editSpendingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Spending</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="editSpendingForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="editId">
                <input type="hidden" name="_filter_from_date" value="<?= htmlspecialchars($fromDate) ?>">
                <input type="hidden" name="_filter_to_date" value="<?= htmlspecialchars($toDate) ?>">
                <input type="hidden" name="_filter_category" value="<?= htmlspecialchars($catFilter) ?>">
                <input type="hidden" name="_filter_user_id" value="<?= $userFilter ? (int)$userFilter : '' ?>">
                <div class="modal-body" style="max-height: 65vh; overflow-y: auto; -webkit-overflow-scrolling: touch;">
                    <div class="mb-3">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="spending_date" id="editDate" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="editAmount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Main Category <span class="text-danger">*</span></label>
                        <select name="category" id="editMainCat" class="form-select" required>
                            <option value="">— Select —</option>
                            <?php foreach ($mainCategories as $mc): ?>
                            <option value="<?= htmlspecialchars($mc['name']) ?>"><?= htmlspecialchars($mc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subcategories</label>
                        <div id="editSubCatCheckboxes" class="border rounded p-2" style="max-height: 100px; overflow-y: auto; background: #f8f9fa;"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">User (Spent By)</label>
                        <select name="spent_by" id="editSpentBy" class="form-select">
                            <option value="">— Optional —</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" id="editPayment" class="form-select">
                            <option value="">— Optional —</option>
                            <?php foreach ($noteOptions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Note</label>
                        <textarea name="note" id="editNote" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Images</label>
                        <input type="hidden" name="removed_images" id="editCfRemovedImages" value="">
                        <div id="editCfImagesList" class="d-flex flex-wrap gap-2 mb-2"></div>
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
<div class="modal fade" id="viewCfImagesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-images me-2"></i>Receipt / Images</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewCfImagesBody">
                <div class="row g-2" id="viewCfImagesContent"></div>
            </div>
        </div>
    </div>
</div>

<script>
function showCfImages(images) {
    if (!images || !Array.isArray(images) || images.length === 0) return;
    var base = <?= json_encode(str_replace('__file__', '', uploaded_file_url('__file__', 'spending_images'))) ?>;
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
    document.getElementById('viewCfImagesContent').innerHTML = html || '<p class="text-muted">No images</p>';
    new bootstrap.Modal(document.getElementById('viewCfImagesModal')).show();
}
(function() {
    var subsData = <?= json_encode($subCategories) ?>;
    function renderEditSubs(main, selected) {
        var container = document.getElementById('editSubCatCheckboxes');
        if (!container) return;
        container.innerHTML = '';
        if (main && subsData[main]) {
            subsData[main].forEach(function(s) {
                var label = document.createElement('label');
                label.className = 'd-block mb-1';
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.name = 'sub_categories[]';
                cb.value = s.name;
                cb.className = 'form-check-input me-2';
                if (selected.indexOf(s.name) >= 0) cb.checked = true;
                label.appendChild(cb);
                label.appendChild(document.createTextNode(s.name));
                container.appendChild(label);
            });
        } else {
            container.innerHTML = '<small class="text-muted">Select category first</small>';
        }
    }
    document.getElementById('editMainCat')?.addEventListener('change', function() {
        var selected = [];
        var cbs = document.querySelectorAll('#editSubCatCheckboxes input[type="checkbox"]:checked');
        cbs.forEach(function(c) { selected.push(c.value); });
        renderEditSubs(this.value, selected);
    });
    var cfRemovedImages = [];
    function renderCfEditImages(imagesJson) {
        var container = document.getElementById('editCfImagesList');
        var hidden = document.getElementById('editCfRemovedImages');
        if (!container) return;
        cfRemovedImages = [];
        hidden.value = '[]';
        var images = [];
        try { images = typeof imagesJson === 'string' ? JSON.parse(imagesJson || '[]') : (imagesJson || []); } catch(e) {}
        if (!Array.isArray(images)) images = [];
        var base = <?= json_encode(str_replace('__file__', '', uploaded_file_url('__file__', 'spending_images'))) ?>;
        var html = '';
        images.forEach(function(img) {
            var ext = (img.split('.').pop() || '').toLowerCase();
            var isImg = ['jpg','jpeg','png','gif','webp'].indexOf(ext) >= 0;
            var safeImg = (img || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;');
            html += '<div class="position-relative d-inline-block cf-edit-img-wrap" data-cf-img="' + safeImg + '">';
            if (isImg) {
                html += '<img src="' + base + img + '" class="img-thumbnail" style="max-width:80px;max-height:80px;cursor:pointer" onclick="window.open(\'' + base + img.replace(/'/g,"\\'") + '\',\'_blank\')">';
            } else {
                html += '<div class="border rounded p-2 small"><i class="bi bi-file-earmark"></i> ' + (img || '').replace(/</g,'&lt;') + '</div>';
            }
            html += '<button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0" style="padding:0 4px;line-height:1" data-cf-remove="' + safeImg + '" title="Remove"><i class="bi bi-x"></i></button></div>';
        });
        container.innerHTML = html || '<span class="text-muted small">No images</span>';
        container.querySelectorAll('[data-cf-remove]').forEach(function(btn) {
            btn.onclick = function() {
                var imgName = this.getAttribute('data-cf-remove');
                if (!imgName) return;
                cfRemovedImages.push(imgName);
                document.getElementById('editCfRemovedImages').value = JSON.stringify(cfRemovedImages);
                var wrap = this.closest('.cf-edit-img-wrap');
                if (wrap) wrap.remove();
                if (!container.querySelector('.cf-edit-img-wrap')) {
                    container.innerHTML = '<span class="text-muted small">No images (removed)</span>';
                }
            };
        });
    }
    document.querySelectorAll('.btn-edit-spending').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.dataset.id;
            var selectedSubs = [];
            try { selectedSubs = JSON.parse(this.dataset.subcategories || '[]'); } catch(e) {}
            document.getElementById('editId').value = id;
            document.getElementById('editDate').value = this.dataset.date || '';
            document.getElementById('editAmount').value = this.dataset.amount || '';
            document.getElementById('editMainCat').value = this.dataset.category || '';
            document.getElementById('editSpentBy').value = this.dataset.spentBy || '';
            document.getElementById('editPayment').value = this.dataset.payment || '';
            document.getElementById('editNote').value = this.dataset.note || '';
            renderEditSubs(this.dataset.category || '', selectedSubs);
            renderCfEditImages(this.dataset.images || '[]');
            new bootstrap.Modal(document.getElementById('editSpendingModal')).show();
        });
    });
})();
</script>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
