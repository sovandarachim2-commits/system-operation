<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_reports.view', 'sr_expense_reports.view', 'sr_expense_subcategory_report.view', 'sr_expense_records.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

function ensure_finance_spending_update_columns(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            color VARCHAR(20) NULL DEFAULT '#6b7280',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_company_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $cols = $pdo->query("SHOW COLUMNS FROM finance_spending")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('payment_method', $cols, true)) {
            $pdo->exec("ALTER TABLE finance_spending ADD COLUMN payment_method VARCHAR(100) NULL AFTER currency");
        }
        if (!in_array('company_id', $cols, true)) {
            $pdo->exec("ALTER TABLE finance_spending ADD COLUMN company_id INT NULL AFTER payment_method");
        }
        if (!in_array('updated_by', $cols, true)) {
            $pdo->exec("ALTER TABLE finance_spending ADD COLUMN updated_by INT NULL AFTER created_by");
        }
        if (!in_array('updated_at', $cols, true)) {
            $pdo->exec("ALTER TABLE finance_spending ADD COLUMN updated_at DATETIME NULL AFTER updated_by");
        }
    } catch (Throwable $e) {
        error_log('finance_spending update column check failed: ' . $e->getMessage());
    }
}

ensure_finance_spending_update_columns($pdo);

// Get filter parameters (supports quick month filter)
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$category = $_GET['category'] ?? '';
$quick_month = trim((string)($_GET['quick_month'] ?? ''));
$quick_range = trim((string)($_GET['quick_range'] ?? ''));

if ($quick_range === 'this_month') {
    $quick_month = date('Y-m');
}
if ($quick_range === 'last_month') {
    $quick_month = date('Y-m', strtotime('first day of last month'));
}
if (preg_match('/^\d{4}\-(0[1-9]|1[0-2])$/', $quick_month)) {
    $from_date = $quick_month . '-01';
    $to_date = date('Y-m-t', strtotime($from_date));
}

// Build base query - JOIN users to get created_by name (handles id, name, or username in created_by)
$sql = "SELECT fs.*, u.name AS created_by_name, updater.name AS updated_by_name, COALESCE(c.name, '') AS company_name FROM finance_spending fs 
        LEFT JOIN users u ON (fs.created_by = u.id 
            OR (fs.created_by COLLATE utf8mb4_unicode_ci = u.name COLLATE utf8mb4_unicode_ci) 
            OR (fs.created_by COLLATE utf8mb4_unicode_ci = u.username COLLATE utf8mb4_unicode_ci)) 
        LEFT JOIN users updater ON fs.updated_by = updater.id
        LEFT JOIN companies c ON c.id = fs.company_id
        WHERE DATE(fs.spending_date) BETWEEN ? AND ?";
$params = [$from_date, $to_date];

if ($category !== '') {
    $sql .= " AND fs.category = ?";
    $params[] = $category;
}

$sql .= " ORDER BY COALESCE(fs.updated_at, fs.created_at) DESC, fs.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$spending_records = $stmt->fetchAll();

// Get categories for dropdown from finance_categories table
$stmt = $pdo->query("SELECT name FROM finance_categories WHERE type = 'main' ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get subcategories for edit modal
$stmt = $pdo->query("SELECT * FROM finance_categories WHERE type = 'sub' ORDER BY parent_category, name");
$all_subcategories = $stmt->fetchAll();
$subcategories_by_parent = [];
foreach ($all_subcategories as $subcat) {
    $subcategories_by_parent[$subcat['parent_category']][] = ucfirst(str_replace('_', ' ', $subcat['name']));
}

// Get users for edit modal
$stmt = $pdo->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name");
$users = $stmt->fetchAll();

// Calculate totals
$total_amount = array_sum(array_column($spending_records, 'amount'));
$total_count = count($spending_records);

// Category breakdown
$category_totals = [];
foreach ($spending_records as $record) {
    if (!isset($category_totals[$record['category']])) {
        $category_totals[$record['category']] = 0;
    }
    $category_totals[$record['category']] += $record['amount'];
}

require_once __DIR__ . '/../layout/header.php';

// Success/error messages
if (isset($_GET['success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="bi bi-check-circle me-2"></i>' . htmlspecialchars($_GET['success']) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
if (isset($_GET['error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="bi bi-exclamation-triangle me-2"></i>' . htmlspecialchars($_GET['error']) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

// Spending Report - Clear & Easy Layout
echo "
<style>
/* Finance Reports - Clear Layout */
.report-page {
    width: 100%;
    max-width: 100%;
}
.report-page .page-header {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 50%, #f87171 100%);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    color: #fff;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.report-page .page-header h2 { margin: 0; font-weight: 600; }
.report-page .page-header .subtitle { color: rgba(255,255,255,0.9); margin: 0.25rem 0 0; }
.report-page .filter-bar {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
}
.report-page .filter-bar .form-label { font-weight: 600; color: #212529; font-size: 0.8rem; }
.report-page .quick-filter-row {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.report-page .quick-filter-chip {
    border-radius: 999px;
    padding: 0.35rem 0.75rem;
}
.report-page .metric-card {
    border-radius: 12px;
    border: none;
    padding: 1.25rem;
    margin-bottom: 1rem;
    transition: transform 0.2s;
}
.report-page .metric-card:hover { transform: translateY(-2px); }
.report-page .metric-card .metric-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.9; }
.report-page .metric-card .metric-value { font-size: 1.5rem; font-weight: 700; }
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
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-left: 4px solid #ef4444;
}
.report-page .section-card .table thead th {
    background: #f1f5f9 !important;
    color: #212529;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.report-page .section-card .table tbody td {
    color: #212529;
}
.report-page .section-card .table tbody td small,
.report-page .section-card .table tbody td .text-muted,
.report-page .text-muted {
    color: #212529 !important;
}
.report-page .period-badge {
    background: #fee2e2;
    color: #dc2626;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
}

/* Mobile & Tablet Responsive */
@media (max-width: 991px) {
    .report-page .page-header {
        padding: 1rem 1.25rem;
    }
    .report-page .page-header h2 {
        font-size: 1.35rem;
    }
    .report-page .section-header {
        flex-wrap: wrap;
        gap: 0.5rem;
    }
}

@media (max-width: 768px) {
    .report-page .container-fluid {
        padding: 0.75rem !important;
    }
    .report-page .page-header {
        padding: 0.875rem 1rem;
        margin-bottom: 1rem;
    }
    .report-page .page-header h2 {
        font-size: 1.2rem;
    }
    .report-page .page-header .d-flex.gap-2 {
        width: 100%;
        justify-content: flex-start;
    }
    .report-page .page-header .btn {
        flex: 1 1 auto;
        min-width: 0;
    }
    .report-page .filter-bar {
        padding: 1rem;
    }
    .report-page .metric-card {
        padding: 1rem;
    }
    .report-page .metric-card .metric-value {
        font-size: 1.25rem;
    }
    .report-page .table-responsive {
        font-size: 13px;
        -webkit-overflow-scrolling: touch;
        margin: 0 -0.75rem;
        overflow-x: auto;
    }
    .report-page .table th,
    .report-page .table td {
        padding: 0.5rem 0.4rem;
        white-space: nowrap;
    }
    .report-page .table th:nth-child(n+9),
    .report-page .table td:nth-child(n+9) {
        white-space: normal;
    }
    .form-control, .form-select {
        font-size: 16px !important;
    }
}

@media (max-width: 576px) {
    .report-page .container-fluid {
        padding: 0.5rem !important;
    }
    .report-page .page-header .btn-sm {
        font-size: 0.75rem;
        padding: 0.4rem 0.6rem;
    }
    .report-page .metric-value {
        font-size: 1.1rem !important;
    }
    .report-page .section-header {
        flex-direction: column;
        align-items: flex-start;
    }
    .report-page .table-responsive {
        font-size: 12px;
    }
}

@media (max-width: 768px) {
    .report-page .row.g-3 {
        --bs-gutter-x: 0.75rem;
        --bs-gutter-y: 0.75rem;
    }
}

/* Phone devices (small screens) */
@media (max-width: 480px) {
    .report-page .container-fluid {
        padding: 0.4rem !important;
        padding-left: max(0.4rem, env(safe-area-inset-left)) !important;
        padding-right: max(0.4rem, env(safe-area-inset-right)) !important;
    }
    .report-page .page-header {
        padding: 0.75rem !important;
        margin-bottom: 0.75rem !important;
    }
    .report-page .page-header h2 {
        font-size: 1.1rem;
    }
    .report-page .page-header .btn-sm {
        font-size: 0.7rem;
        padding: 0.5rem 0.5rem;
        min-height: 40px;
    }
    .report-page .filter-bar {
        padding: 0.75rem !important;
    }
    .report-page .metric-card {
        padding: 0.75rem !important;
    }
    .report-page .metric-value {
        font-size: 1rem !important;
    }
    .report-page .table-responsive {
        font-size: 11px;
        margin: 0 -0.4rem;
        border-radius: 8px;
    }
    .report-page .table th,
    .report-page .table td {
        padding: 0.4rem 0.35rem;
    }
}

/* Touch-friendly & table scroll */
.report-page .table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    width: 100%;
    display: block;
}
.report-page .table {
    min-width: 100%;
}
.report-page .btn {
    min-height: 44px;
}
.report-page .btn-sm {
    min-height: 36px;
}

.btn-sm {
    min-height: 36px !important;
    min-width: 36px !important;
}

/* Better mobile table scrolling */
.table-responsive {
    -webkit-overflow-scrolling: touch !important;
}

/* Edit Spending modal - scrollable on ALL devices */
#editSpendingModal .modal-body {
    overflow-y: auto !important;
    -webkit-overflow-scrolling: touch !important;
    max-height: calc(100vh - 180px) !important;
}

/* Mobile modal improvements */
@media (max-width: 768px) {
    .modal-dialog {
        margin: 10px !important;
        max-width: 95% !important;
    }
    
    .modal-content {
        border-radius: 0 !important;
        border: none !important;
    }
    
    .modal-header {
        border-radius: 0 !important;
    }
    
    .modal-body {
        padding: 15px !important;
    }
}
</style>
";
?>

<div class="container-fluid py-3 report-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2><i class="bi bi-cash-stack me-2"></i>Spending History</h2>
                <p class="subtitle mb-0">All money spent from the system</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="finance_reports_export.php?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?><?= $category ? '&category=' . urlencode($category) : '' ?>" 
                   class="btn btn-success btn-sm no-print">
                    <i class="bi bi-file-earmark-excel me-1"></i>Excel
                </a>
                <button type="button" class="btn btn-outline-light btn-sm no-print" onclick="window.print();">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                <a href="finance_dashboard.php" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Dashboard
                </a>
                <a href="add_spending.php" class="btn btn-light btn-sm">
                    <i class="bi bi-plus-circle me-1"></i>Add Spending
                </a>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="filter-bar">
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
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat ?>" <?= $category === $cat ? 'selected' : '' ?>>
                                <?= ucfirst($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label">Quick Month</label>
                    <div class="input-group">
                        <input type="month" name="quick_month" class="form-control" value="<?= htmlspecialchars($quick_month) ?>">
                        <button type="submit" class="btn btn-outline-primary" title="Apply month">
                            <i class="bi bi-calendar-check"></i>
                        </button>
                    </div>
                </div>
                <div class="col-12">
                    <div class="quick-filter-row">
                        <a href="finance_reports.php?quick_range=this_month<?= $category ? '&category=' . urlencode($category) : '' ?>" class="btn btn-sm btn-outline-primary quick-filter-chip">
                            <i class="bi bi-lightning-charge me-1"></i>This Month
                        </a>
                        <a href="finance_reports.php?quick_range=last_month<?= $category ? '&category=' . urlencode($category) : '' ?>" class="btn btn-sm btn-outline-secondary quick-filter-chip">
                            <i class="bi bi-clock-history me-1"></i>Last Month
                        </a>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel me-1"></i>Filter
                        </button>
                        <a href="finance_reports.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-1"></i>Clear
                        </a>
                    </div>
                </div>
            </form>
    </div>

    <!-- Print Report (visible only when printing) -->
    <div class="print-report-only report-page" style="display: none;">
        <?php 
        $printLogo = null;
        if (function_exists('get_invoice_logo')) {
            try {
                $printLogo = get_invoice_logo(get_db_connection());
            } catch (Throwable $e) {}
        }
        if (!$printLogo && function_exists('get_default_logo')) {
            try {
                $printLogo = get_default_logo(get_db_connection());
            } catch (Throwable $e) {}
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
            <h3>Spending History</h3>
            <p class="mb-0"><strong>Period:</strong> <?= date('d-m-Y', strtotime($from_date)) ?> to <?= date('d-m-Y', strtotime($to_date)) ?></p>
            <p class="mb-0"><strong>Generated:</strong> <?= date('d-m-Y H:i') ?></p>
        </div>
        <?php 
        $spending_by_cat = [];
        foreach ($spending_records as $s) {
            $cat = $s['category'];
            if (!isset($spending_by_cat[$cat])) $spending_by_cat[$cat] = [];
            $spending_by_cat[$cat][] = $s;
        }
        $cat_order = array_keys($spending_by_cat);
        usort($cat_order, function($a, $b) use ($spending_by_cat) {
            $ta = array_sum(array_column($spending_by_cat[$a], 'amount'));
            $tb = array_sum(array_column($spending_by_cat[$b], 'amount'));
            return $tb <=> $ta;
        });
        foreach ($cat_order as $cat):
            $items = $spending_by_cat[$cat];
            $cat_total = array_sum(array_column($items, 'amount'));
            $cat_name = ucfirst(str_replace('_', ' ', $cat));
        ?>
        <table class="table table-bordered print-table mb-4" border="1" cellpadding="4" cellspacing="0" rules="all" frame="box" style="border: 1px solid #000; border-collapse: collapse;">
            <thead style="background: #fce7f3;">
                <tr>
                    <th class="text-center" style="width: 50px; border: 1px solid #000;">No.</th>
                    <th style="width: 100px; border: 1px solid #000;">Date</th>
                    <th style="width: 120px; border: 1px solid #000;">Paid By</th>
                    <th style="border: 1px solid #000;">Description</th>
                    <th style="width: 150px; border: 1px solid #000;">Spending Category</th>
                    <th class="text-end" style="width: 110px; border: 1px solid #000;">Amount USD</th>
                </tr>
            </thead>
            <tbody>
                <?php $row = 1; foreach ($items as $s): 
                    $desc = $s['note'] ?? '';
                    if (!empty($s['sub_categories'])) {
                        $subs = json_decode($s['sub_categories'], true);
                        if (is_array($subs) && !empty($subs)) {
                            $desc = implode(', ', array_map(function($x) { return ucfirst(str_replace('_', ' ', $x)); }, array_filter($subs)));
                        }
                    } elseif (!empty($s['sub_category'])) {
                        $desc = ucfirst(str_replace('_', ' ', $s['sub_category']));
                    }
                    if ($desc === '') $desc = $s['spending_code'] ?? '-';
                    $paid_by = $s['paid_by'] ?? $s['receive_by'] ?? 'Cash';
                ?>
                <tr>
                    <td class="text-center"><?= $row++ ?></td>
                    <td><?= date('d-M-Y', strtotime($s['spending_date'])) ?></td>
                    <td><?= htmlspecialchars($paid_by) ?></td>
                    <td><?= htmlspecialchars($desc) ?></td>
                    <td><?= htmlspecialchars($cat_name) ?></td>
                    <td class="text-end">$ <?= number_format($s['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row" style="background-color: #d1fae5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
                    <td colspan="4" class="text-center fw-bold">Total</td>
                    <td><?= htmlspecialchars($cat_name) ?></td>
                    <td class="text-end fw-bold">$ <?= number_format($cat_total, 2) ?></td>
                </tr>
            </tbody>
        </table>
        <?php endforeach; ?>
        <?php if (!empty($spending_by_cat)): ?>
        <!-- Grand Total: By Category + All -->
        <table class="table table-bordered print-table" border="1" cellpadding="4" cellspacing="0" rules="all" frame="box" style="border: 1px solid #000; border-collapse: collapse;">
            <thead style="background: #fce7f3;">
                <tr>
                    <th style="border: 1px solid #000;">Grand Total Summary</th>
                    <th class="text-center" style="width: 80px; border: 1px solid #000;">Count</th>
                    <th class="text-end" style="width: 140px; border: 1px solid #000;">Amount USD</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cat_order as $cat): 
                    $items = $spending_by_cat[$cat];
                    $cat_total = array_sum(array_column($items, 'amount'));
                    $cat_count = count($items);
                    $cat_name = ucfirst(str_replace('_', ' ', $cat));
                ?>
                <tr>
                    <td><?= htmlspecialchars($cat_name) ?></td>
                    <td class="text-center"><?= number_format($cat_count) ?></td>
                    <td class="text-end">$ <?= number_format($cat_total, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background: #fee2e2; font-weight: bold;">
                    <td>All Categories Total</td>
                    <td class="text-center"><?= number_format($total_count) ?></td>
                    <td class="text-end">$ <?= number_format($total_amount, 2) ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if (empty($spending_by_cat)): ?>
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
    <!-- Key Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="metric-card shadow" style="background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%); color: #fff;">
                <div class="metric-label">Total Spending</div>
                <div class="metric-value">$<?= number_format($total_amount, 2) ?></div>
                <small>Money out this period</small>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="metric-card shadow" style="background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 100%); color: #fff;">
                <div class="metric-label">Transactions</div>
                <div class="metric-value"><?= number_format($total_count) ?></div>
                <small><?= count($category_totals) ?> categories</small>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="metric-card shadow" style="background: linear-gradient(135deg, #64748b 0%, #475569 100%); color: #fff;">
                <div class="metric-label">Period</div>
                <div class="metric-value" style="font-size: 1.1rem;"><?= date('M d', strtotime($from_date)) ?> â€“ <?= date('M d, Y', strtotime($to_date)) ?></div>
                <small>Filtered results</small>
            </div>
        </div>
    </div>

    <!-- Category Breakdown -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="section-card">
                <div class="section-header">
                    <span><i class="bi bi-pie-chart me-2"></i>By Category</span>
                    <span class="period-badge"><?= htmlspecialchars($from_date) ?> â†’ <?= htmlspecialchars($to_date) ?></span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="table-layout: fixed; width: 100%;" dir="ltr">
                            <colgroup>
                                <col style="width: 50%;">
                                <col style="width: 25%;">
                                <col style="width: 25%;">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th style="width: 50%;">Category</th>
                                    <th class="text-end" style="width: 25%;">Amount</th>
                                    <th class="text-end" style="width: 25%;">Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($category_totals as $cat => $amount): ?>
                                    <tr>
                                        <td style="width: 50%; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($cat) ?></td>
                                        <td class="text-end" style="width: 25%; white-space: nowrap;">$<?= number_format($amount, 2) ?></td>
                                        <td class="text-end" style="width: 25%; white-space: nowrap;"><?= $total_amount > 0 ? round(($amount / $total_amount) * 100, 1) : 0 ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($category_totals)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted">No data found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Transactions Table -->
    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-table me-2"></i>All Transactions</span>
            <span class="period-badge"><?= $total_count ?> records</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="transactionsTable">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 50px;">No</th>
                            <th style="width: 130px;">Date</th>
                            <th style="width: 120px;">Code</th>
                            <th style="width: 150px;">Category</th>
                            <th style="width: 200px;">Sub Categories</th>
                            <th class="text-end" style="width: 100px;">Amount</th>
                            <th class="text-center" style="width: 80px;">Status</th>
                            <th style="width: 100px;">Paid By</th>
                            <th style="width: 100px;">Receive By</th>
                            <th style="width: 100px;">Created By</th>
                            <th style="width: 130px;">Created At</th>
                            <th>Note</th>
                            <th class="text-center no-print" style="width: 90px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        foreach ($spending_records as $spending): ?>
                            <tr data-payment-method="<?= htmlspecialchars((string)($spending['payment_method'] ?? ''), ENT_QUOTES) ?>" data-company-id="<?= htmlspecialchars((string)($spending['company_id'] ?? ''), ENT_QUOTES) ?>" data-company-name="<?= htmlspecialchars((string)($spending['company_name'] ?? ''), ENT_QUOTES) ?>">
                                <td class="text-center"><span class="badge bg-secondary"><?= $counter ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-calendar-event text-muted me-1"></i>
                                        <small><?= date('M d, Y', strtotime($spending['spending_date'])) ?></small>
                                    </div>
                                </td>
                                <td><code class="text-primary"><?= htmlspecialchars($spending['spending_code']) ?></code></td>
                                <td>
                                    <span class="badge bg-<?= $spending['category'] === 'boost' ? 'success' : ($spending['category'] === 'employee' ? 'info' : ($spending['category'] === 'marketing' ? 'warning' : 'primary')) ?>">
                                        <?= ucfirst($spending['category']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    // Handle multiple subcategories
                                    $has_subcategories = false;
                                    if (!empty($spending['sub_categories'])) {
                                        $sub_cats = json_decode($spending['sub_categories'], true);
                                        if (is_array($sub_cats) && !empty($sub_cats)) {
                                            foreach ($sub_cats as $index => $sub_cat) {
                                                if (!empty(trim($sub_cat))) {
                                                    echo '<span class="badge bg-light text-dark me-1">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $sub_cat))) . '</span>';
                                                    $has_subcategories = true;
                                                }
                                            }
                                        }
                                    }
                                    
                                    // Fallback to single subcategory
                                    if (!$has_subcategories && !empty($spending['sub_category'])) {
                                        echo '<span class="badge bg-light text-dark">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $spending['sub_category']))) . '</span>';
                                        $has_subcategories = true;
                                    }
                                    
                                    // Show N/A if no subcategories found
                                    if (!$has_subcategories) {
                                        echo '<span class="text-muted">N/A</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-end">
                                    <strong style="color: #dc2626;">$<?= number_format($spending['amount'], 2) ?></strong>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $spending['status'] === 'completed' ? 'success' : ($spending['status'] === 'approved' ? 'primary' : ($spending['status'] === 'pending' ? 'warning' : 'danger')) ?>">
                                        <?= ucfirst($spending['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars($spending['paid_by'] ?? 'N/A') ?></small>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars($spending['receive_by'] ?? 'N/A') ?></small>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars(!empty($spending['updated_at']) ? ($spending['updated_by_name'] ?? $spending['created_by_name'] ?? $spending['updated_by'] ?? $spending['created_by'] ?? 'System') : ($spending['created_by_name'] ?? $spending['created_by'] ?? 'System')) ?></small>
                                </td>
                                <td>
                                    <small><?= !empty($spending['updated_at']) ? date('M d, Y H:i', strtotime($spending['updated_at'])) : (!empty($spending['created_at']) ? date('M d, Y H:i', strtotime($spending['created_at'])) : 'N/A') ?></small>
                                </td>
                                <td>
                                    <small class="text-muted"><?= htmlspecialchars($spending['note'] ?? 'No note') ?></small>
                                </td>
                                <td class="text-center no-print">
                                    <div class="d-flex flex-nowrap gap-1 justify-content-center">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="editSpending(<?= (int)$spending['id'] ?>)" data-bs-toggle="tooltip" title="Edit">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <form method="post" action="delete_spending.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this spending record?');">
                                            <input type="hidden" name="spending_id" value="<?= (int)$spending['id'] ?>">
                                            <input type="hidden" name="redirect" value="finance_reports.php?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?><?= $category ? '&category=' . urlencode($category) : '' ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" data-bs-toggle="tooltip" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php 
                        $counter++;
                        endforeach; ?>
                        <?php if (empty($spending_records)): ?>
                            <tr>
                                <td colspan="13" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                    No transactions found for the selected criteria
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5" class="text-end">Total:</th>
                            <th class="text-end"><strong style="color: #dc2626;">$<?= number_format($total_amount, 2) ?></strong></th>
                            <th colspan="7"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    </div><!-- .screen-content -->

    <!-- Edit Spending Modal - Responsive -->
    <div class="modal fade" id="editSpendingModal" tabindex="-1" aria-labelledby="editSpendingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="editSpendingModalLabel">
                        <i class="bi bi-pencil me-2"></i>Edit Spending
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="update_spending.php" id="editSpendingForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" id="editSpendingId" name="spending_id">
                        <input type="hidden" id="removedImages" name="removed_images" value="">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Spending Code *</label>
                                <input type="text" name="spending_code" class="form-control" id="editSpendingCode" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="amount" class="form-control" id="editAmount" step="0.01" min="0.01" required>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Paid By</label>
                                <select name="paid_by" class="form-select" id="editPaidBy">
                                    <option value="">Select who paid (optional)</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= htmlspecialchars($user['name']) ?>"><?= htmlspecialchars($user['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Receive By</label>
                                <select name="receive_by" class="form-select" id="editReceiveBy">
                                    <option value="">Select who received (optional)</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= htmlspecialchars($user['name']) ?>"><?= htmlspecialchars($user['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Date *</label>
                                <input type="date" name="spending_date" class="form-control" id="editSpendingDate" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Status *</label>
                                <select name="status" class="form-select" id="editStatus" required>
                                    <option value="">Select status</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Spend To *</label>
                                <select name="spend_to" class="form-select" id="editSpendTo" required>
                                    <option value="">Select main category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $cat))) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Sub Categories *</label>
                                <div id="editSubCategoryRows" class="d-flex flex-column gap-2"></div>
                                <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addEditSubCategoryRow">
                                    <i class="bi bi-plus-circle me-1"></i>Add More Subcategories
                                </button>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Note</label>
                                <textarea name="note" class="form-control" rows="3" id="editNote"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label"><i class="bi bi-image me-1"></i>Receipt Images</label>
                                <div id="editReceiptImages" class="border rounded p-3 bg-light">
                                    <div class="text-center text-muted" id="noReceiptsMessage">
                                        <i class="bi bi-dash-circle display-4 d-block mb-2"></i>No receipts uploaded
                                    </div>
                                    <div id="receiptsGallery" class="row d-none"></div>
                                    <div id="imageManagementSection" class="mt-3 d-none">
                                        <hr>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0">Manage Images</h6>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="showAddImageSection()">
                                                <i class="bi bi-plus-circle me-1"></i>Add New Image
                                            </button>
                                        </div>
                                        <div id="addImageSection" class="mt-2 d-none">
                                            <input type="file" name="new_images[]" class="form-control" accept="image/*,.pdf,.doc,.docx" multiple>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>Update Spending
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
function exportToCSV() {
    // Create CSV data from detailed transactions
    let csv = ['No,Date,Code,Category,Sub Categories,Amount,Status,Paid By,Receive By,Created By,Created At,Note'];
    
    <?php 
    $counter = 1;
    foreach ($spending_records as $spending): 
        // Format subcategories for CSV
        $subcats_csv = 'N/A';
        if (!empty($spending['sub_categories'])) {
            $sub_cats = json_decode($spending['sub_categories'], true);
            if (is_array($sub_cats) && !empty($sub_cats)) {
                $valid_subcats = array_filter($sub_cats, function($cat) {
                    return !empty(trim($cat));
                });
                if (!empty($valid_subcats)) {
                    $subcats_csv = implode('; ', array_map(function($cat) {
                        return ucfirst(str_replace('_', ' ', $cat));
                    }, $valid_subcats));
                }
            }
        } else if (!empty($spending['sub_category'])) {
            $subcats_csv = ucfirst(str_replace('_', ' ', $spending['sub_category']));
        }
    ?>
    csv.push([
        '<?= $counter ?>',
        '<?= date('M d, Y', strtotime($spending['spending_date'])) ?>',
        '<?= htmlspecialchars($spending['spending_code']) ?>',
        '<?= ucfirst($spending['category']) ?>',
        '<?= htmlspecialchars($subcats_csv) ?>',
        '<?= number_format($spending['amount'], 2) ?>',
        '<?= ucfirst($spending['status']) ?>',
        '<?= htmlspecialchars($spending['paid_by'] ?? 'N/A') ?>',
        '<?= htmlspecialchars($spending['receive_by'] ?? 'N/A') ?>',
        '<?= htmlspecialchars(!empty($spending['updated_at']) ? ($spending['updated_by_name'] ?? $spending['created_by_name'] ?? $spending['updated_by'] ?? $spending['created_by'] ?? 'System') : ($spending['created_by_name'] ?? $spending['created_by'] ?? 'System')) ?>',
        '<?= !empty($spending['updated_at']) ? date('M d, Y H:i', strtotime($spending['updated_at'])) : (!empty($spending['created_at']) ? date('M d, Y H:i', strtotime($spending['created_at'])) : 'N/A') ?>',
        '<?= htmlspecialchars(str_replace(["\r\n", "\n", ","], [" ", " ", ";"], $spending['note'] ?? 'No note')) ?>'
    ].join(','));
    <?php 
    $counter++;
    endforeach; ?>
    
    // Add total row
    csv.push(['', '', '', '', 'Total', '<?= number_format($total_amount, 2) ?>', '', '', '', '', '', ''].join(','));
    
    // Download CSV
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `finance_report_detailed_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
}

function printReport() {
    window.print();
}

// Edit Spending Modal - data & handlers
const dbSubCategories = <?= json_encode($subcategories_by_parent) ?>;

let removedImages = [];

function showAddImageSection() {
    const el = document.getElementById('addImageSection');
    if (el) el.classList.toggle('d-none');
}

function removeExistingImage(btn) {
    const filename = btn.getAttribute('data-filename') || '';
    if (!filename || !confirm('Remove "' + filename + '"?')) return;
    removedImages.push(filename);
    const card = btn.closest('.col-md-4');
    if (card) card.remove();
    const remaining = document.querySelectorAll('#receiptsGallery .col-md-4');
    if (remaining.length === 0) {
        document.getElementById('noReceiptsMessage').classList.remove('d-none');
        document.getElementById('receiptsGallery').classList.add('d-none');
        document.getElementById('imageManagementSection').classList.add('d-none');
    }
}

function displayEditReceipts(imagesJson) {
    const noMsg = document.getElementById('noReceiptsMessage');
    const gallery = document.getElementById('receiptsGallery');
    const mgmt = document.getElementById('imageManagementSection');
    if (!imagesJson || imagesJson === 'null' || imagesJson === '') {
        noMsg.classList.remove('d-none');
        gallery.classList.add('d-none');
        mgmt.classList.add('d-none');
        gallery.innerHTML = '';
        return;
    }
    try {
        const images = JSON.parse(imagesJson);
        if (!Array.isArray(images) || images.length === 0) {
            noMsg.classList.remove('d-none');
            gallery.classList.add('d-none');
            mgmt.classList.add('d-none');
            gallery.innerHTML = '';
            return;
        }
        noMsg.classList.add('d-none');
        gallery.classList.remove('d-none');
        mgmt.classList.remove('d-none');
        let html = '';
        images.forEach((img) => {
            const isImg = /\.(jpg|jpeg|png|gif)$/i.test(img);
            const fn = (img || '');
            const enc = fn.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            const path = <?= json_encode(str_replace('__file__', '', uploaded_file_url('__file__', 'spending_images'))) ?> + fn.replace(/^uploads\/spending_images\//, '');
            const dataFn = fn.replace(/"/g,'&quot;');
            if (isImg) {
                html += '<div class="col-12 col-sm-6 col-md-4 mb-3"><div class="card"><img src="' + path + '" class="card-img-top" alt="Receipt" style="height:120px;object-fit:cover;cursor:pointer;" onclick="window.open(this.src,\'_blank\')"><div class="card-body p-2"><small class="text-muted d-block text-truncate" title="' + enc + '">' + enc + '</small><div class="mt-1 d-flex gap-1"><a href="' + path + '" target="_blank" class="btn btn-sm btn-outline-primary flex-fill"><i class="bi bi-eye"></i></a><button type="button" class="btn btn-sm btn-outline-danger" data-filename="' + dataFn + '" onclick="removeExistingImage(this)"><i class="bi bi-trash"></i></button></div></div></div></div>';
            } else {
                html += '<div class="col-12 col-sm-6 col-md-4 mb-3"><div class="card"><div class="card-body text-center"><i class="bi bi-file-earmark display-4 text-primary"></i><h6 class="mt-2 small text-truncate" title="' + enc + '">' + enc + '</h6><div class="d-flex gap-1"><a href="' + path + '" target="_blank" class="btn btn-sm btn-outline-primary flex-fill"><i class="bi bi-download"></i></a><button type="button" class="btn btn-sm btn-outline-danger" data-filename="' + dataFn + '" onclick="removeExistingImage(this)"><i class="bi bi-trash"></i></button></div></div></div></div>';
            }
        });
        gallery.innerHTML = html;
    } catch (e) {
        noMsg.classList.remove('d-none');
        gallery.classList.add('d-none');
        mgmt.classList.add('d-none');
        gallery.innerHTML = '';
    }
}

function createEditSubCategoryRow(selectedValue) {
    const main = document.getElementById('editSpendTo').value;
    const container = document.getElementById('editSubCategoryRows');
    if (!main || !dbSubCategories[main]) return;
    const opts = dbSubCategories[main];
    const rowCount = container.querySelectorAll('.edit-subcategory-row').length;
    const row = document.createElement('div');
    row.className = 'row g-2 align-items-center edit-subcategory-row';
    let options = '<option value="">Select sub category</option>';
    opts.forEach(function(sc) {
        const val = sc.toLowerCase().replace(/\s+/g, '_');
        options += '<option value="' + val + '"' + (selectedValue === val ? ' selected' : '') + '>' + sc + '</option>';
    });
    row.innerHTML = '<div class="col-12 col-md-8"><select name="sub_categories[]" class="form-select edit-subcategory-select" required>' + options + '</select></div><div class="col-12 col-md-4"><button type="button" class="btn btn-outline-danger btn-sm remove-edit-row"' + (rowCount === 0 ? ' style="display:none;"' : '') + '><i class="bi bi-trash"></i> Remove</button></div>';
    container.appendChild(row);
    updateEditRemoveButtons();
}

function updateEditRemoveButtons() {
    const rows = document.querySelectorAll('.edit-subcategory-row');
    rows.forEach(function(r) {
        const btn = r.querySelector('.remove-edit-row');
        if (btn) btn.style.display = rows.length > 1 ? 'inline-block' : 'none';
    });
}

document.getElementById('editSpendTo').addEventListener('change', function() {
    const main = this.value;
    const cur = window.currentEditMainCategory;
    if (cur && cur !== main) {
        document.getElementById('editSubCategoryRows').innerHTML = '';
        if (main && dbSubCategories[main]) createEditSubCategoryRow();
    }
    window.currentEditMainCategory = main;
});

document.getElementById('addEditSubCategoryRow').addEventListener('click', function() {
    createEditSubCategoryRow();
});

document.getElementById('editSubCategoryRows').addEventListener('click', function(e) {
    if (e.target.closest('.remove-edit-row')) {
        const rows = document.querySelectorAll('.edit-subcategory-row');
        if (rows.length > 1) {
            e.target.closest('.edit-subcategory-row').remove();
            updateEditRemoveButtons();
        }
    }
});

document.getElementById('editSpendingForm').addEventListener('submit', function(e) {
    e.preventDefault();
    document.getElementById('removedImages').value = JSON.stringify(removedImages);
    const formData = new FormData(this);
    fetch('update_spending.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('editSpendingModal')).hide();
                alert('Spending updated successfully!');
                window.location.reload();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(function(err) {
            console.error(err);
            alert('Error updating spending');
        });
});

function editSpending(id) {
    removedImages = [];
    if (!id) return;
    fetch('get_spending.php?id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var s = data.spending;
                window.currentEditMainCategory = s.category;
                document.getElementById('editSpendingId').value = s.id;
                document.getElementById('editSpendingCode').value = s.spending_code;
                document.getElementById('editAmount').value = s.amount;
                document.getElementById('editPaidBy').value = s.paid_by || '';
                document.getElementById('editReceiveBy').value = s.receive_by || '';
                document.getElementById('editSpendingDate').value = s.spending_date;
                document.getElementById('editStatus').value = s.status || '';
                document.getElementById('editSpendTo').value = s.category || '';
                var rows = document.getElementById('editSubCategoryRows');
                rows.innerHTML = '';
                var subCats = [];
                if (s.sub_categories) {
                    try { subCats = JSON.parse(s.sub_categories); } catch (x) {}
                } else if (s.sub_category) {
                    subCats = [s.sub_category];
                }
                if (subCats.length > 0) {
                    subCats.forEach(function(sc) { createEditSubCategoryRow(sc); });
                } else {
                    createEditSubCategoryRow();
                }
                document.getElementById('editNote').value = s.note || '';
                displayEditReceipts(s.images);
                new bootstrap.Modal(document.getElementById('editSpendingModal')).show();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(function(err) {
            console.error(err);
            alert('Error loading spending data');
        });
}
</script>

<style>
/* Responsive modal - fullscreen on small devices */
@media (max-width: 576px) {
    .modal-fullscreen-sm-down {
        max-width: 100%;
        margin: 0;
    }
    .modal-fullscreen-sm-down .modal-content {
        min-height: 100vh;
        border-radius: 0;
    }
}
@media print {
    .no-print, .admin-topbar, .filter-bar, .page-header, .report-page .btn, .admin-sidebar, .sidebar-overlay, .navbar, .sidebar, aside {
        display: none !important;
    }
    .print-report-only {
        display: block !important;
    }
    .screen-content {
        display: none !important;
    }
    .print-table { font-size: 11px; border-collapse: collapse !important; border: 1px solid #000 !important; }
    .print-table th, .print-table td { padding: 4px 8px; border: 1px solid #000 !important; border-style: solid !important; }
    .print-table thead th { border-top: 1px solid #000 !important; border-bottom: 1px solid #000 !important; }
    .print-report-only, .print-report-only *, .print-report-only th, .print-report-only td, .print-report-only p, .print-report-only h3, .print-report-only strong { color: #000 !important; }
    .print-table .text-success, .print-table .text-danger, .print-table .text-primary, .print-table a { color: #000 !important; text-decoration: none !important; }
    .print-report-only .total-row td { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .print-report-only .total-row td { background-color: #d1fae5 !important; }
    .print-report-only .total-row.total-row-danger td { background-color: #fee2e2 !important; }
}
</style>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>





