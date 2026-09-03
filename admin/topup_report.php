<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_dashboard.view', 'sr_expense_topup.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Today
$source_filter = $_GET['source'] ?? '';

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];

if ($start_date) {
    $where_conditions[] = "topup_date >= ?";
    $params[] = $start_date;
}

if ($end_date) {
    $where_conditions[] = "topup_date <= ?";
    $params[] = $end_date;
}

if ($source_filter) {
    $where_conditions[] = "source = ?";
    $params[] = $source_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Get top-up records - JOIN users to get created_by name (handles id, name, or username)
$stmt = $pdo->prepare("
    SELECT ft.*, u.name AS created_by_name FROM finance_topups ft 
    LEFT JOIN users u ON (ft.created_by = u.id 
        OR (ft.created_by COLLATE utf8mb4_unicode_ci = u.name COLLATE utf8mb4_unicode_ci) 
        OR (ft.created_by COLLATE utf8mb4_unicode_ci = u.username COLLATE utf8mb4_unicode_ci))
    WHERE $where_clause 
    ORDER BY ft.topup_date DESC, ft.created_at DESC
");
$stmt->execute($params);
$topups = $stmt->fetchAll();

// Get summary statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(amount), 0) as total_amount,
        COUNT(DISTINCT source) as unique_sources,
        COUNT(DISTINCT person_name) as unique_persons,
        MIN(topup_date) as earliest_date,
        MAX(topup_date) as latest_date
    FROM finance_topups 
    WHERE $where_clause
");
$stmt->execute($params);
$summary = $stmt->fetch();

// Get source breakdown
$stmt = $pdo->prepare("
    SELECT 
        source,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total_amount,
        COALESCE(AVG(amount), 0) as avg_amount
    FROM finance_topups 
    WHERE $where_clause
    GROUP BY source
    ORDER BY total_amount DESC
");
$stmt->execute($params);
$source_breakdown = $stmt->fetchAll();

// Get opening balance (balance before the selected period)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_topups WHERE topup_date < ?");
$stmt->execute([$start_date]);
$total_topups_before = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_spending WHERE DATE(spending_date) < ?");
$stmt->execute([$start_date]);
$total_spending_before = (float) $stmt->fetchColumn();

$opening_balance = $total_topups_before - $total_spending_before;

// Get unique sources for filter dropdown
$stmt = $pdo->query("SELECT DISTINCT source FROM finance_topups ORDER BY source");
$all_sources = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get users for edit modal
$stmt = $pdo->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name");
$users = $stmt->fetchAll();

// Default sources if none in DB (for new topups)
$default_sources = ['Cash By Laiheang', 'ABA GM', 'ABA J Mey'];
$all_sources_for_modal = array_unique(array_merge($default_sources, $all_sources));
sort($all_sources_for_modal);

require_once __DIR__ . '/../layout/header.php';

// Success/error messages
if (isset($_GET['topup_success']) || isset($_GET['success'])) {
    $msg = $_GET['message'] ?? $_GET['success'] ?? 'Operation completed successfully.';
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="bi bi-check-circle me-2"></i>' . htmlspecialchars($msg) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
if (isset($_GET['topup_error']) || isset($_GET['error'])) {
    $msg = $_GET['topup_error'] ?? $_GET['error'] ?? 'An error occurred.';
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="bi bi-exclamation-triangle me-2"></i>' . htmlspecialchars(urldecode($msg)) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
?>

<style>
/* Top Up Report - Clear & Easy Layout */
.report-page {
    width: 100%;
    max-width: 100%;
}
.report-page .page-header {
    background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    color: #fff;
    box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35);
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
    border-left: 4px solid #10b981;
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
    background: #d1fae5;
    color: #059669;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
}

/* Regular screen styles */
.summary-card {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.summary-card h3 {
    margin: 0 0 10px 0;
    font-size: 1.2rem;
}

.summary-card .amount {
    font-size: 1.8rem;
    font-weight: bold;
}

.summary-card .subtitle {
    font-size: 0.9rem;
    opacity: 0.9;
}

/* Responsive - all devices */
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
        flex-wrap: wrap;
    }
    .report-page .page-header .btn-sm {
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
    .form-control, .form-select {
        font-size: 16px !important;
    }
    .summary-card {
        padding: 15px;
    }
    .summary-card .amount {
        font-size: 1.5rem;
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

/* Print styles */
@media print {
    .no-print, .admin-topbar, .admin-sidebar, .sidebar-overlay { display: none !important; }
    .print-only { display: block !important; }
    body { font-size: 12px; margin: 10px; }
    .print-only .table { font-size: 11px; margin-bottom: 20px; border-collapse: collapse !important; border: 1px solid #000 !important; }
    .print-only .table th, .print-only .table td { border: 1px solid #000 !important; border-style: solid !important; }
    .print-only .table thead th { border-top: 1px solid #000 !important; border-bottom: 1px solid #000 !important; }
    .card { border: 1px solid #000 !important; box-shadow: none !important; margin-bottom: 20px !important; }
    .card-body { padding: 10px !important; }
    .card-header { background: #f8f9fa !important; border-bottom: 1px solid #000 !important; }
    .badge { border: 1px solid #000 !important; background: #fff !important; color: #000 !important; padding: 2px 4px !important; }
    .progress { display: none !important; }
    .btn { display: none !important; }
    .container-fluid { padding: 0 !important; }
    .row { margin: 0 !important; }
    .col-md-2, .col-md-3, .col-12 { width: 100% !important; margin: 0 !important; padding: 5px !important; }
    .print-only .table th { background: #f8f9fa !important; border: 1px solid #000 !important; font-weight: bold !important; padding: 5px !important; }
    .print-only .table td { border: 1px solid #000 !important; padding: 5px !important; }
    .print-only, .print-only *, .print-only th, .print-only td, .print-only p, .print-only h3, .print-only strong { color: #000 !important; }
    .text-success, .text-danger, .text-primary, .text-info, .text-muted { color: #000 !important; }
    .text-end { text-align: right !important; }
    .text-center { text-align: center !important; }
    .shadow-sm { box-shadow: none !important; }
    .bg-success, .bg-primary, .bg-info, .bg-warning, .bg-secondary, .bg-dark { 
        background: #fff !important; color: #000 !important; border: 1px solid #000 !important; 
    }
    .card-title { color: #000 !important; font-size: 14px !important; margin-bottom: 5px !important; }
    h2, h3, h5 { color: #000 !important; }
    .d-flex { display: block !important; }
    .admin-topbar.d-flex { display: none !important; }
    .justify-content-between { justify-content: normal !important; }
    .align-items-center { align-items: normal !important; }
    .mb-3, .mb-4, .mb-0 { margin-bottom: 10px !important; }
    .mt-3 { margin-top: 10px !important; }
    
    /* Force hide all unwanted elements when printing - only print-only content shows */
    .report-page .page-header.no-print,
    .report-page .filter-bar,
    .report-page .row.g-3,
    .report-page .row.g-4,
    .report-page .row.mb-4,
    .report-page .section-card,
    .report-page .metric-card,
    .report-page .card:not(.print-only *),
    .report-page .table-responsive:not(.print-only *) { display: none !important; }
    
    /* Show only print content */
    .print-only { display: block !important; visibility: visible !important; }
    .print-only table { display: table !important; visibility: visible !important; }
    .print-only thead { display: table-header-group !important; visibility: visible !important; }
    .print-only tbody { display: table-row-group !important; visibility: visible !important; }
    .print-only tr { display: table-row !important; visibility: visible !important; }
    .print-only th, .print-only td { display: table-cell !important; visibility: visible !important; }
    .print-only .total-row td { background-color: #d1fae5 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
}

.print-only { display: none; }

.stat-number {
    font-size: 2rem;
    font-weight: bold;
}
.stat-label {
    font-size: 0.9rem;
    opacity: 0.9;
}

</style>

<div class="container-fluid py-4 report-page">
    <!-- Page Header -->
    <div class="page-header no-print">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2><i class="bi bi-wallet2 me-2"></i>Top Up History</h2>
                <p class="subtitle mb-0">All money added to the system</p>
            </div>
            <div class="d-flex gap-2 no-print">
                <a href="finance_dashboard.php" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Dashboard
                </a>
                <a href="add_topup.php" class="btn btn-light btn-sm">
                    <i class="bi bi-plus-circle me-1"></i>Add Top Up
                </a>
                <button onclick="window.print()" class="btn btn-light btn-sm">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                <a href="topup_report_export.php?format=excel&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&source=<?= urlencode($source_filter) ?>" class="btn btn-light btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                </a>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-bar no-print">
        <form method="GET" class="row g-3">
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label">Source Filter</label>
                    <select name="source" class="form-select">
                        <option value="">All Sources</option>
                        <?php foreach ($all_sources as $source): ?>
                            <option value="<?= htmlspecialchars($source) ?>" <?= ($source_filter === $source) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($source) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-3 d-flex flex-wrap align-items-end gap-2">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="topup_report.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>Clear
                    </a>
                </div>
            </form>
    </div>

    <!-- Print Content - Hidden on screen, visible when printing -->
    <div class="print-only">
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
        <!-- Print Header -->
        <div class="text-center mb-4">
            <h3>Top-Up Report</h3>
            <p><strong>Period:</strong> <?= date('M d, Y', strtotime($start_date)) ?> - <?= date('M d, Y', strtotime($end_date)) ?></p>
            <p><strong>Opening Balance:</strong> $<?= number_format($opening_balance, 2) ?></p>
            <p><strong>Generated:</strong> <?= date('M d, Y H:i') ?></p>
        </div>

        <!-- Print Detailed Records Table -->
        <table class="table table-bordered" border="1" cellpadding="5" cellspacing="0" rules="all" frame="box" style="border: 1px solid #000; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="border: 1px solid #000;">No</th>
                    <th style="border: 1px solid #000;">Date</th>
                    <th style="border: 1px solid #000;">Source</th>
                    <th style="border: 1px solid #000;">Amount</th>
                    <th style="border: 1px solid #000;">Name Person</th>
                    <th style="border: 1px solid #000;">Create By</th>
                    <th style="border: 1px solid #000;">Created At</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($topups) > 0): ?>
                    <?php $detail_row_num = 1; foreach ($topups as $topup): ?>
                    <tr>
                        <td class="text-center"><?= $detail_row_num++ ?></td>
                        <td><?= date('M d, y', strtotime($topup['topup_date'])) ?></td>
                        <td><?= htmlspecialchars($topup['source']) ?></td>
                        <td class="text-end">$<?= number_format($topup['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($topup['person_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($topup['created_by_name'] ?? $topup['created_by'] ?? 'N/A') ?></td>
                        <td><?= date('M d, Y H:i', strtotime($topup['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center">No top-up records found for the selected period.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Print Source Summary -->
        <table class="table table-bordered mt-4" border="1" cellpadding="5" cellspacing="0" rules="all" frame="box" style="border: 1px solid #000; border-collapse: collapse;">
            <thead>
                <tr>
                    <th colspan="5" class="text-center" style="border: 1px solid #000;"><strong>Source Summary</strong></th>
                </tr>
                <tr>
                    <th style="border: 1px solid #000;">No</th>
                    <th style="border: 1px solid #000;">Source</th>
                    <th class="text-center" style="border: 1px solid #000;">Count</th>
                    <th class="text-end" style="border: 1px solid #000;">Total Amount</th>
                    <th class="text-end" style="border: 1px solid #000;">Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Calculate source breakdown for print
                $source_stats = [];
                foreach ($topups as $topup) {
                    if (!isset($source_stats[$topup['source']])) {
                        $source_stats[$topup['source']] = ['count' => 0, 'total' => 0];
                    }
                    $source_stats[$topup['source']]['count']++;
                    $source_stats[$topup['source']]['total'] += $topup['amount'];
                }
                
                $summary_row_num = 1;
                foreach ($source_stats as $source => $stats): 
                    $percentage = $summary['total_amount'] > 0 ? ($stats['total'] / $summary['total_amount']) * 100 : 0;
                ?>
                    <tr>
                        <td class="text-center"><?= $summary_row_num++ ?></td>
                        <td><?= htmlspecialchars($source) ?></td>
                        <td class="text-center"><?= number_format($stats['count']) ?></td>
                        <td class="text-end">$<?= number_format($stats['total'], 2) ?></td>
                        <td class="text-end"><?= number_format($percentage, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row" style="background-color: #d1fae5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-weight: bold;">
                    <td></td>
                    <td><strong>All Sources</strong></td>
                    <td class="text-center"><strong><?= number_format($summary['total_count']) ?></strong></td>
                    <td class="text-end"><strong>$<?= number_format($summary['total_amount'], 2) ?></strong></td>
                    <td class="text-end"><strong>100%</strong></td>
                </tr>
            </tbody>
        </table>

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

    <!-- Key Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="metric-card shadow" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: #fff;">
                <div class="metric-label">Total Amount</div>
                <div class="metric-value">$<?= number_format($summary['total_amount'], 2) ?></div>
                <small>Money in this period</small>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="metric-card shadow" style="background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 100%); color: #fff;">
                <div class="metric-label">Transactions</div>
                <div class="metric-value"><?= number_format($summary['total_count']) ?></div>
                <small>Avg $<?= number_format($summary['total_amount'] / max($summary['total_count'], 1), 2) ?> each</small>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="metric-card shadow" style="background: linear-gradient(135deg, #64748b 0%, #475569 100%); color: #fff;">
                <div class="metric-label">Period</div>
                <div class="metric-value" style="font-size: 1.1rem;"><?= date('M d', strtotime($start_date)) ?> – <?= date('M d, Y', strtotime($end_date)) ?></div>
                <small><?= $summary['unique_sources'] ?> sources</small>
            </div>
        </div>
    </div>

    <!-- Source Breakdown -->
    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-pie-chart me-2"></i>By Source</span>
            <span class="period-badge"><?= htmlspecialchars($start_date) ?> → <?= htmlspecialchars($end_date) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Source</th>
                                    <th class="text-end">Old Balance</th>
                                    <th class="text-center">Count</th>
                                    <th class="text-end">Total Amount</th>
                                    <th class="text-end">Average Amount</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($source_breakdown as $source): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($source['source']) ?></strong></td>
                                        <td class="text-end">$<?= number_format($opening_balance, 2) ?></td>
                                        <td class="text-center"><?= number_format($source['count']) ?></td>
                                        <td class="text-end"><strong>$<?= number_format($source['total_amount'], 2) ?></strong></td>
                                        <td class="text-end">$<?= number_format($source['avg_amount'], 2) ?></td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-success" role="progressbar" 
                                                     style="width: <?= ($source['total_amount'] / max($summary['total_amount'], 1)) * 100 ?>%">
                                                    <?= number_format(($source['total_amount'] / max($summary['total_amount'], 1)) * 100, 1) ?>%
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
            </div>
        </div>
    </div>

    <!-- Detailed Records -->
    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-table me-2"></i>All Transactions</span>
            <span class="period-badge"><?= count($topups) ?> records</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                                <tr>
                                    <th class="text-center" style="width: 50px;">No</th>
                                    <th style="width: 120px;">Date</th>
                                    <th class="text-end" style="width: 120px;">Amount</th>
                                    <th style="width: 150px;">Source</th>
                                    <th style="width: 120px;">Person</th>
                                    <th>Description</th>
                                    <th class="text-center" style="width: 80px;">Receipt</th>
                                    <th style="width: 120px;">Created By</th>
                                    <th style="width: 130px;">Created At</th>
                                    <th class="text-center no-print" style="width: 90px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($topups) > 0): ?>
                                    <?php 
                                    $row_num = 1;
                                    foreach ($topups as $topup): ?>
                                        <tr>
                                            <td class="text-center"><span class="badge bg-secondary"><?= $row_num++ ?></span></td>
                                            <td><?= date('M d, Y', strtotime($topup['topup_date'])) ?></td>
                                            <td class="text-end"><strong class="text-success">$<?= number_format($topup['amount'], 2) ?></strong></td>
                                            <td>
                                                <span class="badge bg-primary"><?= htmlspecialchars($topup['source']) ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($topup['person_name'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($topup['description'] ?? 'N/A') ?></td>
                                            <td class="text-center">
                                                <?php if ($topup['receipt_image']): ?>
                                                    <a href="<?= htmlspecialchars(uploaded_file_url($topup['receipt_image'], 'receipts')) ?>" target="_blank" class="btn btn-sm btn-outline-success no-print">
                                                        <i class="bi bi-image"></i> View
                                                    </a>
                                                    <span class="print-only">✓</span>
                                                <?php else: ?>
                                                    <span class="text-muted">No</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($topup['created_by_name'] ?? $topup['created_by'] ?? 'N/A') ?></td>
                                            <td><?= date('M d, Y H:i', strtotime($topup['created_at'])) ?></td>
                                            <td class="text-center no-print">
                                                <div class="d-flex flex-nowrap gap-1 justify-content-center">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="editTopup(<?= (int)$topup['id'] ?>)" data-bs-toggle="tooltip" title="Edit">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                    <form method="post" action="delete_topup_safe.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this top-up record?');">
                                                        <input type="hidden" name="id" value="<?= (int)$topup['id'] ?>">
                                                        <input type="hidden" name="redirect" value="topup_report.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?><?= $source_filter ? '&source=' . urlencode($source_filter) : '' ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" data-bs-toggle="tooltip" title="Delete">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                            <p class="mt-2">No top-up records found for the selected criteria.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit Top-Up Modal - Responsive -->
<div class="modal fade" id="editTopupModal" tabindex="-1" aria-labelledby="editTopupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editTopupModalLabel">
                    <i class="bi bi-pencil-square me-2"></i>Edit Top-Up Money
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="update_topup.php" enctype="multipart/form-data" id="editTopupForm">
                <input type="hidden" id="editTopupId" name="id">
                <input type="hidden" name="redirect" value="topup_report.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?><?= $source_filter ? '&source=' . urlencode($source_filter) : '' ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <div class="mb-3">
                                <label for="editTopupAmount" class="form-label">Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" id="editTopupAmount" name="amount" step="0.01" min="0.01" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="mb-3">
                                <label for="editTopupDate" class="form-label">Date *</label>
                                <input type="date" class="form-control" id="editTopupDate" name="topup_date" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 col-md-6">
                            <div class="mb-3">
                                <label for="editTopupSource" class="form-label">Source *</label>
                                <select class="form-select" id="editTopupSource" name="source" required>
                                    <option value="">Select source</option>
                                    <?php foreach ($all_sources_for_modal as $src): ?>
                                        <option value="<?= htmlspecialchars($src) ?>"><?= htmlspecialchars($src) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="mb-3">
                                <label for="editTopupPerson" class="form-label">Person Name *</label>
                                <select class="form-select" id="editTopupPerson" name="person_name" required>
                                    <option value="">Select person</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= htmlspecialchars($user['name']) ?>"><?= htmlspecialchars($user['name']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="other">Other (Specify in description)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="editTopupDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="editTopupDescription" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="editTopupReceipt" class="form-label">Receipt Image</label>
                        <input type="file" class="form-control" id="editTopupReceipt" name="receipt_image" accept="image/*">
                        <div id="editReceiptPreview" class="mt-2"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-success" id="editTopupSubmitBtn">
                        <i class="bi bi-check-circle me-1"></i>Update Top-Up
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editTopup(id) {
    fetch('get_topup.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.originalTopupAmount = parseFloat(data.topup.amount);
                document.getElementById('editTopupId').value = data.topup.id;
                document.getElementById('editTopupAmount').value = data.topup.amount;
                document.getElementById('editTopupSource').value = data.topup.source;
                document.getElementById('editTopupDescription').value = data.topup.description || '';
                document.getElementById('editTopupDate').value = data.topup.topup_date;
                document.getElementById('editTopupPerson').value = data.topup.person_name || '';
                var preview = document.getElementById('editReceiptPreview');
                preview.innerHTML = data.topup.receipt_image
                    ? '<img src="' + <?= json_encode(str_replace('__file__', '', uploaded_file_url('__file__', 'receipts'))) ?> + data.topup.receipt_image.replace(/^uploads\/receipts\//, '') + '" alt="Current Receipt" style="max-width:100px;max-height:100px;border-radius:4px;">'
                    : '';
                new bootstrap.Modal(document.getElementById('editTopupModal')).show();
            } else {
                alert('Error loading top-up data: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(function() { alert('Error loading top-up data'); });
}
document.getElementById('editTopupForm').addEventListener('submit', function(e) {
    var newAmt = parseFloat(document.getElementById('editTopupAmount').value);
    var origAmt = window.originalTopupAmount;
    if (origAmt && newAmt < origAmt) {
        alert('Cannot reduce top-up amount. Original: $' + origAmt.toFixed(2) + ', New: $' + newAmt.toFixed(2));
        e.preventDefault();
        return false;
    }
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
