<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_reports.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$category = $_GET['category'] ?? '';
$sub_category_filter = $_GET['sub_category'] ?? '';

$sql = "SELECT fs.*, u.name AS created_by_name FROM finance_spending fs 
        LEFT JOIN users u ON (fs.created_by = u.id 
            OR (fs.created_by COLLATE utf8mb4_unicode_ci = u.name COLLATE utf8mb4_unicode_ci) 
            OR (fs.created_by COLLATE utf8mb4_unicode_ci = u.username COLLATE utf8mb4_unicode_ci)) 
        WHERE DATE(fs.spending_date) BETWEEN ? AND ?";
$params = [$from_date, $to_date];
if ($category !== '') {
    $sql .= " AND fs.category = ?";
    $params[] = $category;
}
$sql .= " ORDER BY fs.spending_date ASC, fs.created_at ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$spending_records = $stmt->fetchAll();

// Group by sub-category (sub_category, or first from sub_categories, or main category)
$spending_by_subcat = [];
foreach ($spending_records as $s) {
    $subcat_label = '';
    if (!empty($s['sub_categories'])) {
        $subs = json_decode($s['sub_categories'], true);
        if (is_array($subs) && !empty($subs)) {
            $first = array_values(array_filter($subs, function($x) { return !empty(trim($x)); }))[0] ?? '';
            $subcat_label = ucfirst(str_replace('_', ' ', $first));
        }
    }
    if ($subcat_label === '' && !empty($s['sub_category'])) {
        $subcat_label = ucfirst(str_replace('_', ' ', $s['sub_category']));
    }
    if ($subcat_label === '') {
        $subcat_label = ucfirst(str_replace('_', ' ', $s['category'])) . ' (General)';
    }
    $key = strtolower(str_replace(' ', '_', $subcat_label));
    if (!isset($spending_by_subcat[$key])) {
        $spending_by_subcat[$key] = ['label' => $subcat_label, 'items' => []];
    }
    $spending_by_subcat[$key]['items'][] = $s;
}

// Order by total amount desc
usort($spending_by_subcat, function($a, $b) {
    $ta = array_sum(array_column($a['items'], 'amount'));
    $tb = array_sum(array_column($b['items'], 'amount'));
    return $tb <=> $ta;
});

// Apply sub-category filter
if ($sub_category_filter !== '') {
    $filter_key = strtolower(str_replace(' ', '_', $sub_category_filter));
    $spending_by_subcat = array_filter($spending_by_subcat, function($data) use ($filter_key) {
        $key = strtolower(str_replace(' ', '_', $data['label']));
        return $key === $filter_key;
    });
    $spending_by_subcat = array_values($spending_by_subcat);
}

$filtered_records = [];
foreach ($spending_by_subcat as $data) {
    $filtered_records = array_merge($filtered_records, $data['items']);
}
$total_amount = array_sum(array_column($filtered_records, 'amount'));
$total_count = count($filtered_records);

$stmt = $pdo->query("SELECT name FROM finance_categories WHERE type = 'main' ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get sub-categories from DB and from actual spending (for custom sub-cats)
$stmt = $pdo->query("SELECT name FROM finance_categories WHERE type = 'sub' ORDER BY parent_category, name");
$sub_categories_from_db = $stmt->fetchAll(PDO::FETCH_COLUMN);
$all_subcat_keys = [];
foreach ($spending_records as $s) {
    $subcat_label = '';
    if (!empty($s['sub_categories'])) {
        $subs = json_decode($s['sub_categories'], true);
        if (is_array($subs) && !empty($subs)) {
            $first = array_values(array_filter($subs, function($x) { return !empty(trim($x)); }))[0] ?? '';
            $subcat_label = ucfirst(str_replace('_', ' ', $first));
        }
    }
    if ($subcat_label === '' && !empty($s['sub_category'])) {
        $subcat_label = ucfirst(str_replace('_', ' ', $s['sub_category']));
    }
    if ($subcat_label === '') {
        $subcat_label = ucfirst(str_replace('_', ' ', $s['category'])) . ' (General)';
    }
    $key = strtolower(str_replace(' ', '_', $subcat_label));
    $all_subcat_keys[$key] = $subcat_label;
}
$sub_category_keys = array_unique(array_merge($sub_categories_from_db, array_keys($all_subcat_keys)));
sort($sub_category_keys);

require_once __DIR__ . '/../layout/header.php';

if (isset($_GET['success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="bi bi-check-circle me-2"></i>' . htmlspecialchars($_GET['success']) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
if (isset($_GET['error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="bi bi-exclamation-triangle me-2"></i>' . htmlspecialchars($_GET['error']) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
?>
<style>
.report-page { width: 100%; max-width: 100%; }
.report-page .page-header {
    background: linear-gradient(135deg, #0d9488 0%, #14b8a6 50%, #2dd4bf 100%);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    color: #fff;
    box-shadow: 0 4px 14px rgba(13, 148, 136, 0.35);
}
.report-page .page-header h2 { margin: 0; font-weight: 600; }
.report-page .filter-bar {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
}
.report-page .period-badge { background: #ccfbf1; color: #0d9488; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.8rem; }
.report-page .section-card {
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    margin-bottom: 1.5rem;
}
.report-page .section-card .section-header {
    padding: 1rem 1.25rem;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    font-weight: 600;
    border-left: 4px solid #0d9488;
}
.report-page .section-card .table thead th { background: #fce7f3 !important; color: #212529; font-weight: 600; }
.report-page .section-card .table tbody td { color: #212529; }
@media print {
    .no-print, .admin-topbar, .admin-sidebar, .sidebar-overlay, .filter-bar, .report-page .page-header, .report-page .btn { display: none !important; }
    .print-report-only { display: block !important; }
    .screen-content { display: none !important; }
    .print-table { font-size: 11px; border-collapse: collapse !important; border: 1px solid #000 !important; }
    .print-table th, .print-table td { padding: 4px 8px; border: 1px solid #000 !important; }
    .print-report-only .total-row td { background-color: #d1fae5 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .print-report-only .total-row.total-row-danger td { background-color: #fee2e2 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .print-report-only, .print-report-only * { color: #000 !important; }
}
</style>

<div class="container-fluid py-3 report-page">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2><i class="bi bi-collection me-2"></i>Spending by Sub-Category</h2>
                <p class="subtitle mb-0">Detailed report grouped by sub-category</p>
            </div>
            <div class="d-flex gap-2 flex-wrap no-print">
                <a href="finance_spending_detail_export.php?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $sub_category_filter ? '&sub_category=' . urlencode($sub_category_filter) : '' ?>" 
                   class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i>Excel
                </a>
                <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print();">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                <a href="finance_reports.php" class="btn btn-light btn-sm">
                    <i class="bi bi-clock-history me-1"></i>Spending History
                </a>
                <a href="finance_dashboard.php" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </div>

    <div class="filter-bar no-print">
        <form method="get" class="row g-3">
            <div class="col-12 col-sm-6 col-md-3">
                <label class="form-label">From Date</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label class="form-label">To Date</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label class="form-label">Main Category</label>
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('_', ' ', $cat)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label class="form-label">Sub-Category</label>
                <select name="sub_category" class="form-select">
                    <option value="">All Sub-Categories</option>
                    <?php foreach ($sub_category_keys as $key): 
                        $label = $all_subcat_keys[$key] ?? ucfirst(str_replace('_', ' ', $key));
                        $sel = $sub_category_filter !== '' && strtolower(str_replace(' ', '_', $sub_category_filter)) === strtolower($key);
                    ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $sel ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                    <a href="finance_spending_detail.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Clear</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Print Content -->
    <div class="print-report-only" style="display: none;">
        <?php
        $printLogo = null;
        if (function_exists('get_invoice_logo')) {
            try { $printLogo = get_invoice_logo(get_db_connection()); } catch (Throwable $e) {}
        }
        if (!$printLogo && function_exists('get_default_logo')) {
            try { $printLogo = get_default_logo(get_db_connection()); } catch (Throwable $e) {}
        }
        $invoiceSettings = function_exists('get_invoice_settings') ? get_invoice_settings(get_db_connection()) : [];
        $logoW = max(40, min(200, (int)($invoiceSettings['logo_width'] ?? 80)));
        $logoH = max(40, min(200, (int)($invoiceSettings['logo_height'] ?? 70)));
        ?>
        <div class="print-logo text-center mb-3">
            <?php if ($printLogo && !empty($printLogo['file_path'])): ?>
                <img src="<?= htmlspecialchars(uploaded_file_url($printLogo['file_path'], 'logos')) ?>" alt="Logo" style="max-height: <?= $logoH ?>px; max-width: <?= $logoW ?>px; object-fit: contain;">
            <?php else: ?>
                <img src="<?= htmlspecialchars($BASE_URL . '/public/image.png') ?>" alt="Logo" style="max-height: 60px; max-width: 180px; object-fit: contain;">
            <?php endif; ?>
        </div>
        <div class="text-center mb-4">
            <h3>Spending by Sub-Category</h3>
            <p class="mb-0"><strong>Period:</strong> <?= date('d-m-Y', strtotime($from_date)) ?> to <?= date('d-m-Y', strtotime($to_date)) ?></p>
            <p class="mb-0"><strong>Generated:</strong> <?= date('d-m-Y H:i') ?></p>
        </div>
        <?php foreach ($spending_by_subcat as $data): 
            $subcat_label = $data['label'];
            $items = $data['items'];
            $cat_total = array_sum(array_column($items, 'amount'));
        ?>
        <table class="table table-bordered print-table mb-4" border="1" cellpadding="4" cellspacing="0" rules="all" frame="box" style="border: 1px solid #000; border-collapse: collapse;">
            <thead style="background: #fce7f3;">
                <tr>
                    <th colspan="6" style="border: 1px solid #000;"><?= htmlspecialchars($subcat_label) ?></th>
                </tr>
                <tr>
                    <th class="text-center" style="width: 50px; border: 1px solid #000;">No.</th>
                    <th style="width: 100px; border: 1px solid #000;">Date</th>
                    <th style="width: 120px; border: 1px solid #000;">Paid By</th>
                    <th style="width: 150px; border: 1px solid #000;">Category</th>
                    <th style="border: 1px solid #000;">Description</th>
                    <th class="text-end" style="width: 110px; border: 1px solid #000;">Amount USD</th>
                </tr>
            </thead>
            <tbody>
                <?php $row = 1; foreach ($items as $s): 
                    $desc = $s['note'] ?? '';
                    if ($desc === '') $desc = $s['spending_code'] ?? '-';
                    $paid_by = $s['paid_by'] ?? $s['receive_by'] ?? 'Cash';
                    $main_cat = ucfirst(str_replace('_', ' ', $s['category']));
                ?>
                <tr>
                    <td class="text-center"><?= $row++ ?></td>
                    <td><?= date('d-M-Y', strtotime($s['spending_date'])) ?></td>
                    <td><?= htmlspecialchars($paid_by) ?></td>
                    <td><?= htmlspecialchars($main_cat) ?></td>
                    <td><?= htmlspecialchars($desc) ?></td>
                    <td class="text-end">$ <?= number_format($s['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row" style="background-color: #d1fae5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
                    <td colspan="3" class="text-center fw-bold">Total</td>
                    <td class="fw-bold"><?= htmlspecialchars($subcat_label) ?></td>
                    <td></td>
                    <td class="text-end fw-bold">$ <?= number_format($cat_total, 2) ?></td>
                </tr>
            </tbody>
        </table>
        <?php endforeach; ?>
        <?php if (!empty($spending_by_subcat)): ?>
        <table class="table table-bordered print-table" border="1" cellpadding="4" cellspacing="0" rules="all" frame="box" style="border: 1px solid #000; border-collapse: collapse;">
            <thead style="background: #fce7f3;">
                <tr>
                    <th style="border: 1px solid #000;">Grand Total Summary</th>
                    <th class="text-center" style="width: 80px; border: 1px solid #000;">Count</th>
                    <th class="text-end" style="width: 140px; border: 1px solid #000;">Amount USD</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($spending_by_subcat as $data): 
                    $cat_total = array_sum(array_column($data['items'], 'amount'));
                    $cat_count = count($data['items']);
                ?>
                <tr>
                    <td><?= htmlspecialchars($data['label']) ?></td>
                    <td class="text-center"><?= number_format($cat_count) ?></td>
                    <td class="text-end">$ <?= number_format($cat_total, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row total-row-danger" style="background-color: #fee2e2 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-weight: bold;">
                    <td>All Sub-Categories Total</td>
                    <td class="text-center"><?= number_format($total_count) ?></td>
                    <td class="text-end">$ <?= number_format($total_amount, 2) ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if (empty($spending_by_subcat)): ?>
        <p class="text-center py-4">No spending found for the selected period.</p>
        <?php endif; ?>

        <div class="mt-5 pt-4" style="margin-top: 40px; padding-top: 30px; border-top: 1px solid #ddd;">
            <div class="d-flex justify-content-around" style="display: flex; justify-content: space-around; gap: 20px;">
                <div style="flex: 1; text-align: center;">
                    <div style="font-weight: bold; margin-bottom: 20px;">Prepared by</div>
                    <div style="height: 4em;"></div>
                    <div style="text-align: left; margin-left: 15%; margin-bottom: 12px; font-size: 11px;">Date: <span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span>/<span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span>/<span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span></div>
                    <div style="text-align: left; margin-left: 15%; font-size: 11px;">Name:</div>
                </div>
                <div style="flex: 1; text-align: center;">
                    <div style="font-weight: bold; margin-bottom: 20px;">Checked by</div>
                    <div style="height: 4em;"></div>
                    <div style="text-align: left; margin-left: 15%; margin-bottom: 12px; font-size: 11px;">Date: <span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span>/<span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span>/<span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span></div>
                    <div style="text-align: left; margin-left: 15%; font-size: 11px;">Name:</div>
                </div>
                <div style="flex: 1; text-align: center;">
                    <div style="font-weight: bold; margin-bottom: 20px;">Approved by</div>
                    <div style="height: 4em;"></div>
                    <div style="text-align: left; margin-left: 15%; margin-bottom: 12px; font-size: 11px;">Date: <span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span>/<span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span>/<span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span></div>
                    <div style="text-align: left; margin-left: 15%; font-size: 11px;">Name:</div>
                </div>
            </div>
        </div>
    </div>

    <div class="screen-content">
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="metric-card shadow" style="background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: #fff; border-radius: 12px; padding: 1.25rem;">
                <div class="metric-label" style="font-size: 0.75rem; opacity: 0.9;">Total Spending</div>
                <div class="metric-value" style="font-size: 1.5rem; font-weight: 700;">$<?= number_format($total_amount, 2) ?></div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="metric-card shadow" style="background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 100%); color: #fff; border-radius: 12px; padding: 1.25rem;">
                <div class="metric-label" style="font-size: 0.75rem; opacity: 0.9;">Transactions</div>
                <div class="metric-value" style="font-size: 1.5rem; font-weight: 700;"><?= number_format($total_count) ?></div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="metric-card shadow" style="background: linear-gradient(135deg, #64748b 0%, #475569 100%); color: #fff; border-radius: 12px; padding: 1.25rem;">
                <div class="metric-label" style="font-size: 0.75rem; opacity: 0.9;">Sub-Categories</div>
                <div class="metric-value" style="font-size: 1.5rem; font-weight: 700;"><?= count($spending_by_subcat) ?></div>
            </div>
        </div>
    </div>

    <?php foreach ($spending_by_subcat as $data): 
        $subcat_label = $data['label'];
        $items = $data['items'];
        $cat_total = array_sum(array_column($items, 'amount'));
    ?>
    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-tag me-2"></i><?= htmlspecialchars($subcat_label) ?></span>
            <span class="period-badge"><?= count($items) ?> records ¡¤ $<?= number_format($cat_total, 2) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 50px;">No.</th>
                            <th style="width: 100px;">Date</th>
                            <th style="width: 120px;">Paid By</th>
                            <th>Description</th>
                            <th style="width: 150px;">Category</th>
                            <th class="text-end" style="width: 110px;">Amount USD</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $row = 1; foreach ($items as $s): 
                            $desc = $s['note'] ?? '';
                            if ($desc === '') $desc = $s['spending_code'] ?? '-';
                            $paid_by = $s['paid_by'] ?? $s['receive_by'] ?? 'Cash';
                            $main_cat = ucfirst(str_replace('_', ' ', $s['category']));
                        ?>
                        <tr>
                            <td class="text-center"><?= $row++ ?></td>
                            <td><?= date('d-M-Y', strtotime($s['spending_date'])) ?></td>
                            <td><?= htmlspecialchars($paid_by) ?></td>
                            <td><?= htmlspecialchars($desc) ?></td>
                            <td><?= htmlspecialchars($main_cat) ?></td>
                            <td class="text-end">$ <?= number_format($s['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-success">
                            <td colspan="4" class="text-center fw-bold">Total</td>
                            <td><?= htmlspecialchars($subcat_label) ?></td>
                            <td class="text-end fw-bold">$ <?= number_format($cat_total, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($spending_by_subcat)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>No spending found for the selected period.
    </div>
    <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
