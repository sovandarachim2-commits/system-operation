<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_dashboard.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

// Handle success/error messages
$success_message = '';
$error_message = '';

if (isset($_GET['success'])) {
    $success_message = 'Spending record added successfully!';
}

if (isset($_GET['topup_success'])) {
    $success_message = 'Money topped up successfully!';
}

if (isset($_GET['topup_error'])) {
    $error_message = urldecode($_GET['topup_error']);
}

// Get current date range
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$category_filter = $_GET['category'] ?? '';
$current_page_redirect = 'finance_dashboard.php?from_date=' . urlencode($from_date) . '&to_date=' . urlencode($to_date) . ($category_filter !== '' ? ('&category=' . urlencode($category_filter)) : '');

// Finance bank is controlled from note_options.is_finance_default.
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
$selected_bank = $default_finance_bank;

// Get categories for dropdown from database
$stmt = $pdo->query("SELECT name FROM finance_categories WHERE type = 'main' ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get all subcategories from database for JavaScript
$stmt = $pdo->query("SELECT * FROM finance_categories WHERE type = 'sub' ORDER BY parent_category, name");
$all_subcategories = $stmt->fetchAll();

// Organize subcategories by parent category
$subcategories_by_parent = [];
foreach ($all_subcategories as $subcat) {
    $subcategories_by_parent[$subcat['parent_category']][] = ucfirst(str_replace('_', ' ', $subcat['name']));
}

// Get users for dropdowns
$stmt = $pdo->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name");
$users = $stmt->fetchAll();

// Calculate total spending
$spending_query = 'SELECT COALESCE(SUM(amount), 0) as total_spending 
                   FROM finance_spending 
                   WHERE DATE(spending_date) BETWEEN ? AND ?';
$spending_params = [$from_date, $to_date];

if (!empty($category_filter)) {
    $spending_query .= ' AND category = ?';
    $spending_params[] = $category_filter;
}

$stmt = $pdo->prepare($spending_query);
$stmt->execute($spending_params);
$total_spending = $stmt->fetch()['total_spending'] ?? 0;

// Calculate total top-ups (not affected by category filter)
$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) as total_topups 
                       FROM finance_topups 
                       WHERE DATE(topup_date) BETWEEN ? AND ?');
$stmt->execute([$from_date, $to_date]);
$total_topups = $stmt->fetch()['total_topups'] ?? 0;

// Cashflow top-ups for selected/default finance bank (period)
$cashflow_topups_period = 0;
if ($selected_bank !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM cashflow_topups
            WHERE COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
              AND topup_date BETWEEN ? AND ?
        ");
        $stmt->execute([$selected_bank, $from_date, $to_date]);
        $cashflow_topups_period = (float)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $cashflow_topups_period = 0;
    }
}
$total_money_in = (float)$total_topups + (float)$cashflow_topups_period;

// Calculate total ALL spending (not affected by category filter)
$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) as total_all_spending 
                       FROM finance_spending 
                       WHERE DATE(spending_date) BETWEEN ? AND ?');
$stmt->execute([$from_date, $to_date]);
$total_all_spending = $stmt->fetch()['total_all_spending'] ?? 0;

// Calculate balance (top-ups - ALL spending) for filtered period
$balance = $total_topups - $total_all_spending;

// Current balance in finance always uses cashflow closing.
$cashflow_balance = 0.0;
$cashflow_balance_ready = false;
if ($selected_bank !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0)
            FROM orders
            WHERE status = 'paid'
              AND is_cancelled = 0
              AND is_returned = 0
              AND COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
              AND COALESCE(payment_date, DATE(created_at)) <= ?
        ");
        $stmt->execute([$selected_bank, $to_date]);
        $bank_orders_in = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM cashflow_topups
            WHERE COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
              AND topup_date <= ?
        ");
        $stmt->execute([$selected_bank, $to_date]);
        $bank_topup_in = (float)$stmt->fetchColumn();

        // Finance topups also count toward cashflow closing balance.
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM finance_topups
            WHERE COALESCE(NULLIF(TRIM(source), ''), '(No method)') = ?
              AND DATE(topup_date) <= ?
        ");
        $stmt->execute([$selected_bank, $to_date]);
        $bank_topup_in += (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM cashflow_spending
            WHERE COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
              AND spending_date <= ?
        ");
        $stmt->execute([$selected_bank, $to_date]);
        $bank_spending_out = (float)$stmt->fetchColumn();

        // Finance spending is applied on default finance bank for closing balance.
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM finance_spending
            WHERE DATE(spending_date) <= ?
        ");
        $stmt->execute([$to_date]);
        $bank_spending_out += (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM bank_transfers WHERE to_bank = ? AND transfer_date <= ?");
        $stmt->execute([$selected_bank, $to_date]);
        $bank_transfer_in = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM bank_transfers WHERE from_bank = ? AND transfer_date <= ?");
        $stmt->execute([$selected_bank, $to_date]);
        $bank_transfer_out = (float)$stmt->fetchColumn();

        $cashflow_balance = $bank_orders_in + $bank_topup_in - $bank_spending_out + $bank_transfer_in - $bank_transfer_out;
        $cashflow_balance_ready = true;
    } catch (PDOException $e) {
        $cashflow_balance_ready = false;
    }
}
$overall_balance = $cashflow_balance;

// Spending by category
$spending_category_query = 'SELECT category, COALESCE(SUM(amount), 0) as total, COUNT(*) as count
                           FROM finance_spending 
                           WHERE DATE(spending_date) BETWEEN ? AND ?';
$spending_category_params = [$from_date, $to_date];

if (!empty($category_filter)) {
    $spending_category_query .= ' AND category = ?';
    $spending_category_params[] = $category_filter;
}

$spending_category_query .= ' GROUP BY category ORDER BY total DESC';

$stmt = $pdo->prepare($spending_category_query);
$stmt->execute($spending_category_params);
$spending_by_category = $stmt->fetchAll();

// Recent spending - JOIN users to get created_by name
$recent_spending_query = 'SELECT fs.*, u.name AS created_by_name, DATE_FORMAT(fs.spending_date, "%Y-%m-%d %H:%i:%s") as formatted_date 
                          FROM finance_spending fs 
                          LEFT JOIN users u ON (fs.created_by = u.id OR (fs.created_by COLLATE utf8mb4_unicode_ci = u.name COLLATE utf8mb4_unicode_ci) OR (fs.created_by COLLATE utf8mb4_unicode_ci = u.username COLLATE utf8mb4_unicode_ci))
                          WHERE DATE(fs.spending_date) BETWEEN ? AND ?';
$recent_spending_params = [$from_date, $to_date];

if (!empty($category_filter)) {
    $recent_spending_query .= ' AND category = ?';
    $recent_spending_params[] = $category_filter;
}

$recent_spending_query .= ' ORDER BY fs.created_at DESC LIMIT 10';

$stmt = $pdo->prepare($recent_spending_query);
$stmt->execute($recent_spending_params);
$recent_spending = $stmt->fetchAll();

// Recent top-ups - JOIN users to get created_by name
$stmt = $pdo->prepare('SELECT ft.*, u.name AS created_by_name FROM finance_topups ft 
                       LEFT JOIN users u ON (ft.created_by = u.id OR (ft.created_by COLLATE utf8mb4_unicode_ci = u.name COLLATE utf8mb4_unicode_ci) OR (ft.created_by COLLATE utf8mb4_unicode_ci = u.username COLLATE utf8mb4_unicode_ci))
                       WHERE DATE(ft.topup_date) BETWEEN ? AND ?
                       ORDER BY ft.created_at DESC
                       LIMIT 10');
$stmt->execute([$from_date, $to_date]);
$recent_topups = $stmt->fetchAll();

// For print/export: opening balance, all topups, all spending by category
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_topups WHERE topup_date < ?");
$stmt->execute([$from_date]);
$opening_balance = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_spending WHERE DATE(spending_date) < ?");
$stmt->execute([$from_date]);
$opening_balance -= (float) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT * FROM finance_topups 
                       WHERE DATE(topup_date) BETWEEN ? AND ?
                       ORDER BY topup_date ASC, created_at ASC');
$stmt->execute([$from_date, $to_date]);
$print_topups = $stmt->fetchAll();

$print_spending_query = 'SELECT * FROM finance_spending 
                         WHERE DATE(spending_date) BETWEEN ? AND ?';
$print_spending_params = [$from_date, $to_date];
if (!empty($category_filter)) {
    $print_spending_query .= ' AND category = ?';
    $print_spending_params[] = $category_filter;
}
$print_spending_query .= ' ORDER BY category, spending_date ASC, created_at ASC';
$stmt = $pdo->prepare($print_spending_query);
$stmt->execute($print_spending_params);
$print_spending = $stmt->fetchAll();

require_once __DIR__ . '/../layout/header.php';

// Finance Dashboard - Clear layout and styled colors
echo "
<style>
/* Finance Dashboard - Clean Layout & Color Theme */
.finance-dashboard .page-header {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    color: #fff;
}
.finance-dashboard .page-header h2 {
    margin: 0;
    font-weight: 600;
    letter-spacing: -0.02em;
}
.finance-dashboard .page-header .subtitle {
    color: rgba(255,255,255,0.8);
    margin: 0.25rem 0 0;
    font-size: 0.95rem;
}

/* Filter Bar */
.finance-dashboard .filter-bar {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
}
.finance-dashboard .filter-bar .form-label {
    font-weight: 600;
    color: #212529;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* Key Metrics Cards */
.finance-dashboard .metric-card {
    border-radius: 12px;
    border: none;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.finance-dashboard .metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.12) !important;
}
.finance-dashboard .metric-card.card-topup {
    background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%);
    color: #fff;
    box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35);
}
.finance-dashboard .metric-card.card-spending {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 50%, #f87171 100%);
    color: #fff;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.finance-dashboard .metric-card.card-balance-positive {
    background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 50%, #38bdf8 100%);
    color: #fff;
    box-shadow: 0 4px 14px rgba(2, 132, 199, 0.35);
}
.finance-dashboard .metric-card.card-balance-negative {
    background: linear-gradient(135deg, #d97706 0%, #f59e0b 50%, #fbbf24 100%);
    color: #1e293b;
    box-shadow: 0 4px 14px rgba(217, 119, 6, 0.35);
}
.finance-dashboard .metric-card .card-body {
    padding: 1.5rem;
}
.finance-dashboard .metric-card .metric-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: rgba(255,255,255,0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-bottom: 0.75rem;
}
.finance-dashboard .metric-card .metric-label {
    font-size: 0.8rem;
    opacity: 0.95;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.25rem;
}
.finance-dashboard .metric-card .metric-value {
    font-size: 1.75rem;
    font-weight: 700;
    letter-spacing: -0.02em;
}
.finance-dashboard .metric-card .metric-hint {
    font-size: 0.75rem;
    opacity: 0.85;
    margin-top: 0.25rem;
}

/* Section Cards */
.finance-dashboard .section-card {
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    overflow: hidden;
    margin-bottom: 1.5rem;
}
.finance-dashboard .section-card .card-header-custom {
    padding: 1rem 1.25rem;
    font-weight: 600;
    font-size: 1rem;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.finance-dashboard .section-card .card-header-custom.spending { border-left: 4px solid #ef4444; }
.finance-dashboard .section-card .card-header-custom.topup { border-left: 4px solid #10b981; }
.finance-dashboard .section-card .table {
    margin-bottom: 0;
}
.finance-dashboard .section-card .table thead th {
    background: #f1f5f9 !important;
    color: #212529;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e2e8f0;
}
.finance-dashboard .section-card .table tbody td {
    padding: 0.75rem 1rem;
    vertical-align: middle;
    color: #212529;
}
.finance-dashboard .text-muted {
    color: #212529 !important;
}

/* Period Badge */
.finance-dashboard .period-badge {
    background: #e0f2fe;
    color: #0369a1;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
}

/* Print Report */
@media print {
    .no-print, .admin-topbar, .filter-bar, .page-header, .finance-dashboard .btn, .admin-sidebar, .sidebar-overlay, .navbar, aside, .quick-actions, .fab-menu {
        display: none !important;
    }
    .print-report-only {
        display: block !important;
    }
    .screen-content {
        display: none !important;
    }
    .print-table { font-size: 11px; border-collapse: collapse !important; border-spacing: 0 !important; border: 1px solid #000 !important; }
    .print-table *, .print-table *::before, .print-table *::after { box-sizing: border-box !important; }
    .print-table th, .print-table td { padding: 4px 8px; border: 1px solid #000 !important; border-style: solid !important; border-width: 1px !important; border-color: #000 !important; }
    .print-table thead th { border-top: 1px solid #000 !important; border-bottom: 1px solid #000 !important; border-left: 1px solid #000 !important; border-right: 1px solid #000 !important; }
    .print-table tbody td, .print-table tbody tr td { border: 1px solid #000 !important; border-style: solid !important; }
    .print-table .table-success td, .print-table .table-danger td { border: 1px solid #000 !important; }
    /* Total row colors - visible on screen and when printing */
    .print-table .table-success { background-color: #d1fae5 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .print-table .table-success td { background-color: #d1fae5 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .print-table .table-danger { background-color: #fee2e2 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .print-table .table-danger td { background-color: #fee2e2 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    /* Force gray text to black for print */
    .print-report-only .text-muted,
    .print-report-only .text-secondary,
    .print-report-only small,
    .print-table .text-muted,
    .print-table .text-secondary,
    .print-table small,
    .print-report-only p { color: #000 !important; }
    .print-report-only, .print-report-only *, .print-report-only th, .print-report-only td, .print-report-only p, .print-report-only h3, .print-report-only strong { color: #000 !important; }
    .print-table th, .print-table td, .print-table thead th, .print-table a,
    .print-table .text-success, .print-table .text-danger, .print-table .text-primary { color: #000 !important; text-decoration: none !important; }
    .print-table thead th { background: #f8f9fa !important; border: 1px solid #000 !important; }
    .print-table thead tr th { border-top: 1px solid #000 !important; border-bottom: 1px solid #000 !important; border-left: 1px solid #000 !important; border-right: 1px solid #000 !important; }
}

/* Mobile Responsive - Finance Dashboard */
@media (max-width: 768px) {
    .finance-dashboard .page-header {
        padding: 1rem;
    }
    .finance-dashboard .metric-card .metric-value {
        font-size: 1.4rem;
    }
    .finance-dashboard .period-badge {
        font-size: 0.7rem;
        padding: 0.25rem 0.5rem;
    }
}

@media (max-width: 768px) {
    .container-fluid {
        padding: 5px !important;
    }
    
    .card {
        margin-bottom: 10px !important;
        border-radius: 8px !important;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
    }
    
    .card-header {
        padding: 10px 15px !important;
        font-size: 16px !important;
    }
    
    .card-body {
        padding: 15px !important;
    }
    
    .table-responsive {
        font-size: 14px !important;
        border-radius: 8px !important;
        overflow: hidden !important;
    }
    
    .table th, .table td {
        padding: 10px 8px !important;
        vertical-align: middle !important;
        border-bottom: 1px solid #f8f9fa !important;
    }
    
    .table th {
        background: #f8f9fa !important;
        font-weight: 600 !important;
        position: sticky !important;
        top: 0 !important;
        z-index: 10 !important;
    }
    
    .btn {
        font-size: 16px !important;
        padding: 12px 16px !important;
        margin: 3px !important;
        min-height: 48px !important;
        min-width: 48px !important;
        border-radius: 8px !important;
        font-weight: 500 !important;
    }
    
    .btn-sm {
        font-size: 14px !important;
        padding: 8px 12px !important;
        min-height: 40px !important;
        min-width: 40px !important;
    }
    
    .btn-success {
        background: linear-gradient(135deg, #28a745, #20c997) !important;
        border: none !important;
        box-shadow: 0 2px 4px rgba(40, 167, 69, 0.3) !important;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #007bff, #6610f2) !important;
        border: none !important;
        box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3) !important;
    }
    
    .btn-outline-light {
        background: rgba(255, 255, 255, 0.1) !important;
        border: 1px solid rgba(255, 255, 255, 0.3) !important;
        backdrop-filter: blur(10px) !important;
    }
    
    .form-control, .form-select {
        font-size: 16px !important;
        padding: 12px 15px !important;
        margin-bottom: 15px !important;
        border-radius: 8px !important;
        border: 2px solid #e9ecef !important;
        transition: all 0.3s ease !important;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #007bff !important;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1) !important;
    }
    
    .input-group-text {
        font-size: 16px !important;
        padding: 12px 15px !important;
        border-radius: 8px 0 0 8px !important;
        background: #f8f9fa !important;
        border: 2px solid #e9ecef !important;
        border-right: none !important;
    }
    
    .d-flex.gap-2 {
        flex-wrap: wrap !important;
        gap: 8px !important;
        justify-content: center !important;
    }
    
    .col-md-6, .col-md-4, .col-md-8, .col-md-12 {
        margin-bottom: 10px !important;
    }
    
    .text-end {
        text-align: center !important;
    }
    
    .badge {
        font-size: 12px !important;
        padding: 6px 10px !important;
        border-radius: 20px !important;
        font-weight: 500 !important;
    }
    
    .alert {
        margin-bottom: 15px !important;
        padding: 15px 20px !important;
        border-radius: 8px !important;
        border: none !important;
        font-size: 14px !important;
    }
    
    .alert-success {
        background: linear-gradient(135deg, #d4edda, #c3e6cb) !important;
        color: #155724 !important;
    }
    
    .alert-danger {
        background: linear-gradient(135deg, #f8d7da, #f5c6cb) !important;
        color: #721c24 !important;
    }
    
    /* Hide some columns on very small screens */
    @media (max-width: 576px) {
        .table-responsive {
            font-size: 13px !important;
        }
        
        .table th:nth-child(4),
        .table td:nth-child(4),
        .table th:nth-child(5),
        .table td:nth-child(5),
        .table th:nth-child(6),
        .table td:nth-child(6) {
            display: none !important;
        }
        
        .btn-group .btn {
            padding: 6px 10px !important;
            font-size: 12px !important;
            min-height: 36px !important;
        }
        
        .card-header {
            font-size: 14px !important;
            padding: 8px 12px !important;
        }
    }
    
    /* Quick action buttons */
    .quick-actions {
        position: fixed !important;
        bottom: 20px !important;
        right: 20px !important;
        z-index: 1000 !important;
    }
    
    .quick-actions .btn {
        width: 56px !important;
        height: 56px !important;
        border-radius: 50% !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 20px !important;
        margin: 0 !important;
    }
    
    /* Floating action menu */
    .fab-menu {
        position: fixed !important;
        bottom: 80px !important;
        right: 20px !important;
        z-index: 999 !important;
        display: none !important;
        flex-direction: column !important;
        gap: 10px !important;
    }
    
    .fab-menu.show {
        display: flex !important;
    }
    
    .fab-menu .btn {
        width: 48px !important;
        height: 48px !important;
        border-radius: 50% !important;
        transform: scale(0) !important;
        animation: fabPop 0.3s ease forwards !important;
    }
    
    .fab-menu .btn:nth-child(1) { animation-delay: 0.1s !important; }
    .fab-menu .btn:nth-child(2) { animation-delay: 0.2s !important; }
    .fab-menu .btn:nth-child(3) { animation-delay: 0.3s !important; }
    
    @keyframes fabPop {
        to {
            transform: scale(1) !important;
        }
    }
}

/* Touch-friendly buttons */
.btn {
    min-height: 44px !important;
    min-width: 44px !important;
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
        margin: 0 !important;
        max-width: 100% !important;
        height: 100vh !important;
    }
    
    .modal-content {
        border-radius: 0 !important;
        border: none !important;
        height: 100vh !important;
        margin: 0 !important;
    }
    
    .modal-header {
        border-radius: 0 !important;
        padding: 15px !important;
    }
    
    .modal-body {
        padding: 15px !important;
        overflow-y: auto !important;
        max-height: calc(100vh - 120px) !important;
    }
    
    .modal-footer {
        padding: 15px !important;
        border-top: 1px solid #dee2e6 !important;
        position: sticky !important;
        bottom: 0 !important;
        background: white !important;
    }
    
    /* Full screen for top-up modal specifically */
    #topUpModal .modal-dialog {
        margin: 0 !important;
        max-width: 100% !important;
        height: 100vh !important;
    }
    
    #topUpModal .modal-content {
        height: 100vh !important;
        border-radius: 0 !important;
        border: none !important;
        margin: 0 !important;
    }
    
    #topUpModal .modal-body {
        overflow-y: auto !important;
        max-height: calc(100vh - 140px) !important;
        padding: 20px 15px !important;
    }
    
    #topUpModal .modal-footer {
        position: sticky !important;
        bottom: 0 !important;
        background: white !important;
        padding: 15px !important;
        border-top: 1px solid #dee2e6 !important;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.1) !important;
    }
    
}
</style>
";
?>

<div class="container-fluid py-3 finance-dashboard">
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h2><i class="bi bi-graph-up-arrow me-2"></i>Finance Dashboard</h2>
            <p class="subtitle mb-0">Overview of money in, money out, and current balance</p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="finance_dashboard_export.php?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?><?= $category_filter ? '&category=' . urlencode($category_filter) : '' ?>" 
               class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-excel me-1"></i>Excel
            </a>
            <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print();">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <!-- Date Filter -->
    <div class="filter-bar">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">From Date</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">To Date</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Category</label>
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category) ?>" <?= $category_filter === $category ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('_', ' ', htmlspecialchars($category))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="finance_dashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>Clear
                    </a>
                </div>
            </div>
        </form>
        <div class="mt-2 small text-muted">
            Balance source bank: <strong><?= htmlspecialchars($selected_bank !== '' ? $selected_bank : 'No active note option') ?></strong>
            (set by first active item in Note Options sort order).
        </div>
    </div>

    <!-- Print Report Content (visible only when printing) -->
    <div class="print-report-only" style="display: none;">
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
            <h3>Expense Report – Finance Dashboard</h3>
            <p class="mb-0"><strong>Period:</strong> <?= date('d-m-Y', strtotime($from_date)) ?> to <?= date('d-m-Y', strtotime($to_date)) ?></p>
            <p class="mb-0"><strong>Generated:</strong> <?= date('d-m-Y H:i') ?></p>
        </div>

        <!-- Table 1: Top Up and Opening Balance -->
        <table class="table table-bordered print-table mb-4" border="1" cellpadding="4" cellspacing="0" rules="all" frame="box" style="border: 1px solid #000; border-collapse: collapse;">
            <thead class="table-light">
                <tr>
                    <th class="text-center" style="width: 40px; border: 1px solid #000;">No.</th>
                    <th class="text-center" style="width: 90px; border: 1px solid #000;">Date</th>
                    <th style="border: 1px solid #000;">Description</th>
                    <th class="text-end" style="width: 120px; border: 1px solid #000;">Debit</th>
                    <th class="text-end" style="width: 120px; border: 1px solid #000;">Credit</th>
                    <th class="text-end" style="width: 120px; border: 1px solid #000;">Running Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $running = $opening_balance;
                $topup_no = 1;
                ?>
                <tr>
                    <td class="text-center"><?= $topup_no++ ?></td>
                    <td></td>
                    <td>Opening Balance (<?= date('m-Y', strtotime($from_date . '-01 -1 month')) ?>)</td>
                    <td class="text-end"></td>
                    <td class="text-end"></td>
                    <td class="text-end fw-bold">$<?= number_format($running, 2) ?></td>
                </tr>
                <?php foreach ($print_topups as $t): ?>
                    <?php $running += $t['amount']; ?>
                    <tr>
                        <td class="text-center"><?= $topup_no++ ?></td>
                        <td class="text-center"><?= date('d/m/Y', strtotime($t['topup_date'])) ?></td>
                        <td>Add – <?= htmlspecialchars($t['source']) ?></td>
                        <td class="text-end">$<?= number_format($t['amount'], 2) ?></td>
                        <td class="text-end"></td>
                        <td class="text-end">$<?= number_format($running, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="table-success">
                    <td colspan="3" class="fw-bold">Total Top Up</td>
                    <td class="text-end fw-bold text-success">$<?= number_format($total_topups, 2) ?></td>
                    <td class="text-end"></td>
                    <td class="text-end fw-bold">$<?= number_format($running, 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Table 2: Spending by Category -->
        <?php 
        $spending_by_cat = [];
        foreach ($print_spending as $s) {
            $cat = $s['category'];
            if (!isset($spending_by_cat[$cat])) $spending_by_cat[$cat] = [];
            $spending_by_cat[$cat][] = $s;
        }
        $cat_labels = [
            'employee' => 'Company Expenses',
            'marketing' => 'Marketing Expenses',
            'boost' => 'Boost Expenses',
            'salary' => 'Salary & Commission'
        ];
        $cat_order = array_keys($spending_by_cat);
        usort($cat_order, function($a, $b) use ($spending_by_cat) {
            $ta = array_sum(array_column($spending_by_cat[$a], 'amount'));
            $tb = array_sum(array_column($spending_by_cat[$b], 'amount'));
            return $tb <=> $ta; // desc by total
        });
        $running_spend = $running; // start from balance after top-ups
        foreach ($cat_order as $cat):
            $items = $spending_by_cat[$cat];
            $cat_total = array_sum(array_column($items, 'amount'));
            $cat_label = $cat_labels[$cat] ?? ucfirst($cat);
        ?>
        <table class="table table-bordered print-table mb-3" border="1" cellpadding="4" cellspacing="0" rules="all" frame="box" style="border: 1px solid #000; border-collapse: collapse;">
            <thead class="table-light">
                <tr>
                    <th class="text-center" style="width: 40px; border: 1px solid #000;">No.</th>
                    <th class="text-center" style="width: 90px; border: 1px solid #000;">Date</th>
                    <th style="border: 1px solid #000;"><?= htmlspecialchars($cat_label) ?></th>
                    <th class="text-end" style="width: 120px; border: 1px solid #000;">Debit</th>
                    <th class="text-end" style="width: 120px; border: 1px solid #000;">Credit</th>
                    <th class="text-end" style="width: 120px; border: 1px solid #000;">Running Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php $item_no = 1; foreach ($items as $s): 
                    $desc = $s['spending_code'];
                    if (!empty($s['sub_categories'])) {
                        $subs = json_decode($s['sub_categories'], true);
                        if (is_array($subs) && !empty($subs)) {
                            $desc = implode(', ', array_map(function($x) { return ucfirst(str_replace('_', ' ', $x)); }, array_filter($subs)));
                        }
                    } elseif (!empty($s['sub_category'])) {
                        $desc = ucfirst(str_replace('_', ' ', $s['sub_category']));
                    }
                    $running_spend -= $s['amount'];
                ?>
                <tr>
                    <td class="text-center"><?= $item_no++ ?></td>
                    <td class="text-center"><?= date('d/m/Y', strtotime($s['spending_date'])) ?></td>
                    <td><?= htmlspecialchars($desc) ?></td>
                    <td class="text-end"></td>
                    <td class="text-end">$<?= number_format($s['amount'], 2) ?></td>
                    <td class="text-end">$<?= number_format($running_spend, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="table-success">
                    <td colspan="3" class="fw-bold">Total <?= htmlspecialchars($cat_label) ?></td>
                    <td class="text-end"></td>
                    <td class="text-end fw-bold text-danger">$<?= number_format($cat_total, 2) ?></td>
                    <td class="text-end fw-bold">$<?= number_format($running_spend, 2) ?></td>
                </tr>
            </tbody>
        </table>
        <?php endforeach; 
        if (empty($spending_by_cat)) $running_spend = $running;
        ?>

        <!-- Grand Total: By Category + All -->
        <?php if (!empty($spending_by_cat)): ?>
        <table class="table table-bordered print-table mb-3" border="1" cellpadding="4" cellspacing="0" rules="all" frame="box" style="border: 1px solid #000; border-collapse: collapse;">
            <thead class="table-light">
                <tr>
                    <th style="border: 1px solid #000;">Grand Total Summary</th>
                    <th class="text-center" style="width: 80px; border: 1px solid #000;">Count</th>
                    <th class="text-end" style="width: 120px; border: 1px solid #000;">Credit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cat_order as $cat): 
                    $items = $spending_by_cat[$cat];
                    $cat_total = array_sum(array_column($items, 'amount'));
                    $cat_count = count($items);
                    $cat_label = $cat_labels[$cat] ?? ucfirst($cat);
                ?>
                <tr>
                    <td><?= htmlspecialchars($cat_label) ?></td>
                    <td class="text-center"><?= number_format($cat_count) ?></td>
                    <td class="text-end">$<?= number_format($cat_total, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="table-danger">
                    <td class="fw-bold">All Categories Total</td>
                    <td class="text-center fw-bold"><?= number_format(count($print_spending)) ?></td>
                    <td class="text-end fw-bold">$<?= number_format($total_all_spending, 2) ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Grand Total (Expenses + Closing Balance) - Clear summary -->
        <table class="table table-bordered print-table" border="1" cellpadding="4" cellspacing="0" rules="all" frame="box" style="border: 1px solid #000; border-collapse: collapse;">
            <thead class="table-light">
                <tr>
                    <th style="border: 1px solid #000;">Summary</th>
                    <th class="text-end" style="width: 120px; border: 1px solid #000;">Total Expenses</th>
                    <th class="text-end" style="width: 120px; border: 1px solid #000;">Closing Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr class="table-danger">
                    <td class="fw-bold">Grand Total (Expenses)</td>
                    <td class="text-end fw-bold">$<?= number_format($total_all_spending, 2) ?></td>
                    <td class="text-end fw-bold">$<?= number_format($running_spend, 2) ?></td>
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

    <div class="screen-content">
    <!-- Key Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card metric-card card-topup shadow">
                <div class="card-body">
                    <div class="metric-icon"><i class="bi bi-wallet2"></i></div>
                    <div class="metric-label">Money In</div>
                    <div class="metric-value">$<?= number_format($total_money_in, 2) ?></div>
                    <div class="metric-hint">Finance top-up + Cashflow top-up (period)</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card metric-card card-spending shadow">
                <div class="card-body">
                    <div class="metric-icon"><i class="bi bi-cash-stack"></i></div>
                    <div class="metric-label">Money Out</div>
                    <div class="metric-value">$<?= number_format($total_spending, 2) ?></div>
                    <div class="metric-hint">Total spending in period</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card metric-card shadow <?= $overall_balance >= 0 ? 'card-balance-positive' : 'card-balance-negative' ?>">
                <div class="card-body">
                    <div class="metric-icon"><i class="bi bi-piggy-bank"></i></div>
                    <div class="metric-label">Current Balance</div>
                    <div class="metric-value">$<?= number_format($overall_balance, 2) ?></div>
                    <div class="metric-hint">
                        <?= $cashflow_balance_ready
                            ? ('Cashflow closing (' . htmlspecialchars($selected_bank) . ')')
                            : 'Cashflow closing unavailable' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Spending by Category -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="section-card">
                <div class="card-header-custom spending">
                    <span><i class="bi bi-tags me-2"></i>Spending by Category</span>
                    <span class="period-badge"><?= htmlspecialchars($from_date) ?> → <?= htmlspecialchars($to_date) ?></span>
                </div>
                <div class="card-body">
                    <script type="application/json" id="categoryChartData"><?= json_encode([
                        'labels' => array_map(function($r) { return ucfirst($r['category']); }, $spending_by_category),
                        'amounts' => array_map(function($r) { return (float) $r['total']; }, $spending_by_category),
                        'counts' => array_map(function($r) { return (int) $r['count']; }, $spending_by_category)
                    ]) ?></script>
                    <div style="height: 280px;">
                        <canvas id="categoryBarChart"></canvas>
                    </div>
                    <?php if (!empty($spending_by_category)): ?>
                    <div class="mt-3 pt-3 border-top">
                        <?php foreach ($spending_by_category as $cat): ?>
                        <div class="d-flex justify-content-between align-items-center py-1 small">
                            <span class="badge bg-<?= $cat['category'] === 'boost' ? 'success' : ($cat['category'] === 'employee' ? 'info' : ($cat['category'] === 'marketing' ? 'warning' : 'primary')) ?> me-2"><?= ucfirst($cat['category']) ?></span>
                            <span class="fw-semibold">$<?= number_format($cat['total'], 2) ?></span>
                            <span class="text-muted"><?= (int)$cat['count'] ?> records</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted mb-0 mt-2"><i class="bi bi-inbox me-1"></i>No spending in this period</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="row g-4">
        <div class="col-12">
            <div class="section-card">
                <div class="card-header-custom spending">
                    <span><i class="bi bi-cash-stack me-2"></i>Recent Spending</span>
                    <span class="period-badge"><?= htmlspecialchars($from_date) ?> → <?= htmlspecialchars($to_date) ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width: 50px;">No</th>
                                    <th style="width: 130px;">Date & Time</th>
                                    <th style="width: 120px;">Code</th>
                                    <th style="width: 150px;">Category</th>
                                    <th class="text-end" style="width: 100px;">Amount</th>
                                    <th class="text-center" style="width: 80px;">Status</th>
                                    <th style="width: 100px;">Paid By</th>
                                    <th style="width: 100px;">Receive By</th>
                                    <th style="width: 100px;">Created By</th>
                                    <th style="width: 80px;">Receipt</th>
                                    <th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $counter = 1;
                                foreach ($recent_spending as $spending): ?>
                                    <tr>
                                        <td class="text-center"><span class="badge bg-secondary"><?= $counter ?></span></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-calendar-event text-muted me-1"></i>
                                                <div>
                                                    <small><?= date('M d, Y', strtotime($spending['formatted_date'])) ?></small>
                                                    <br><small class="text-muted"><?= date('H:i', strtotime($spending['formatted_date'])) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><code class="text-primary"><?= htmlspecialchars($spending['spending_code']) ?></code></td>
                                        <td>
                                            <div>
                                                <span class="badge bg-<?= $spending['category'] === 'boost' ? 'success' : ($spending['category'] === 'employee' ? 'info' : ($spending['category'] === 'marketing' ? 'warning' : 'primary')) ?> me-1">
                                                    <?= ucfirst($spending['category']) ?>
                                                </span>
                                                <br>
                                                <?php 
                                                // Handle multiple subcategories
                                                $has_subcategories = false;
                                                if (!empty($spending['sub_categories'])) {
                                                    $sub_cats = json_decode($spending['sub_categories'], true);
                                                    if (is_array($sub_cats) && !empty($sub_cats)) {
                                                        foreach ($sub_cats as $index => $sub_cat) {
                                                            if (!empty(trim($sub_cat))) {
                                                                echo '<small class="text-muted">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $sub_cat))) . '</small>';
                                                                if ($index < count($sub_cats) - 1) echo ', ';
                                                                $has_subcategories = true;
                                                            }
                                                        }
                                                    }
                                                }
                                                
                                                // Fallback to single subcategory
                                                if (!$has_subcategories && !empty($spending['sub_category'])) {
                                                    echo '<small class="text-muted">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $spending['sub_category']))) . '</small>';
                                                    $has_subcategories = true;
                                                }
                                                
                                                // Show N/A if no subcategories found
                                                if (!$has_subcategories) {
                                                    echo '<small class="text-muted">N/A</small>';
                                                }
                                                ?>
                                            </div>
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
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-arrow-up-circle text-muted me-1"></i>
                                                <small><?= htmlspecialchars($spending['paid_by'] ?? 'N/A') ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-arrow-down-circle text-muted me-1"></i>
                                                <small><?= htmlspecialchars($spending['receive_by'] ?? 'N/A') ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-person-circle text-muted me-1"></i>
                                                <small><?= htmlspecialchars($spending['created_by_name'] ?? $spending['created_by'] ?? 'System') ?></small>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?php 
                                            $has_images = false;
                                            if (!empty($spending['images'])) {
                                                $images = json_decode($spending['images'], true);
                                                if (is_array($images) && !empty($images)) {
                                                    $has_images = true;
                                                    echo '<button class="btn btn-sm btn-outline-success" onclick="showReceipts(\'' . htmlspecialchars(json_encode($images)) . '\', \'' . htmlspecialchars($spending['spending_code']) . '\')" title="View Receipts">';
                                                    echo '<i class="bi bi-image"></i>';
                                                    echo ' (' . count($images) . ')';
                                                    echo '</button>';
                                                }
                                            }
                                            if (!$has_images) {
                                                echo '<span class="text-muted"><i class="bi bi-dash-circle"></i> None</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= htmlspecialchars($spending['note'] ?? 'No note') ?></small>
                                        </td>
                                    </tr>
                                <?php 
                                $counter++;
                                endforeach; ?>
                                <?php if (empty($recent_spending)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                            No recent spending found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-12">
            <div class="section-card">
                <div class="card-header-custom topup">
                    <span><i class="bi bi-wallet2 me-2"></i>Recent Top-Ups</span>
                    <span class="period-badge"><?= htmlspecialchars($from_date) ?> → <?= htmlspecialchars($to_date) ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width: 60px;">No</th>
                                    <th style="width: 150px;">Date & Time</th>
                                    <th class="text-end" style="width: 120px;">Amount</th>
                                    <th class="text-center" style="width: 100px;">Source</th>
                                    <th class="text-center" style="width: 80px;">Receipt</th>
                                    <th>Description</th>
                                    <th style="width: 120px;">Created By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $counter = 1;
                                foreach ($recent_topups as $topup): ?>
                                    <tr>
                                        <td class="text-center"><span class="badge bg-secondary"><?= $counter ?></span></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-calendar-event text-muted me-2"></i>
                                                <div>
                                                    <strong><?= date('M d, Y', strtotime($topup['topup_date'])) ?></strong>
                                                    <br><small class="text-muted"><?= date('H:i', strtotime($topup['created_at'])) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <span class="fw-bold" style="color: #059669;">$<?= number_format($topup['amount'], 2) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary">
                                                <?= htmlspecialchars($topup['source']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if (!empty($topup['receipt_image'])): ?>
                                                <a href="../<?= htmlspecialchars($topup['receipt_image']) ?>" target="_blank" 
                                                   class="btn btn-sm btn-outline-primary" 
                                                   data-bs-toggle="tooltip" title="View Receipt">
                                                    <i class="bi bi-image"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted" data-bs-toggle="tooltip" title="No receipt">
                                                    <i class="bi bi-dash-circle"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-file-text text-muted me-2"></i>
                                                <div>
                                                    <?= htmlspecialchars($topup['description'] ?? 'No description') ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-person-circle text-muted me-2"></i>
                                                <small><?= htmlspecialchars($topup['created_by_name'] ?? $topup['created_by'] ?? 'System') ?></small>
                                            </div>
                                        </td>
                                    </tr>
                                <?php 
                                $counter++;
                                endforeach; ?>
                                <?php if (empty($recent_topups)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                            No recent top-ups found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div><!-- .screen-content -->

    <!-- Edit Spending Modal -->
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
                            <!-- Spending Code -->
                            <div class="col-md-6">
                                <label class="form-label">Spending Code *</label>
                                <input type="text" name="spending_code" class="form-control" id="editSpendingCode" required>
                            </div>

                            <!-- Amount -->
                            <div class="col-md-6">
                                <label class="form-label">Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="amount" class="form-control" id="editAmount" step="0.01" min="0.01" required>
                                </div>
                            </div>

                            <!-- Paid By -->
                            <div class="col-md-6">
                                <label class="form-label">Paid By</label>
                                <select name="paid_by" class="form-select" id="editPaidBy">
                                    <option value="">Select who paid (optional)</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= htmlspecialchars($user['name']) ?>">
                                            <?= htmlspecialchars($user['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Receive By -->
                            <div class="col-md-6">
                                <label class="form-label">Receive By</label>
                                <select name="receive_by" class="form-select" id="editReceiveBy">
                                    <option value="">Select who received (optional)</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= htmlspecialchars($user['name']) ?>">
                                            <?= htmlspecialchars($user['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Date -->
                            <div class="col-md-6">
                                <label class="form-label">Date *</label>
                                <input type="date" name="spending_date" class="form-control" id="editSpendingDate" required>
                            </div>

                            <!-- Status -->
                            <div class="col-md-6">
                                <label class="form-label">Status *</label>
                                <select name="status" class="form-select" id="editStatus" required>
                                    <option value="">Select status</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>

                            <!-- Spend To (Main Category) -->
                            <div class="col-md-6">
                                <label class="form-label">Spend To *</label>
                                <select name="spend_to" class="form-select" id="editSpendTo" required>
                                    <option value="">Select main category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= htmlspecialchars($category) ?>">
                                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $category))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Sub Categories (Dynamic rows like seller system) -->
                            <div class="col-12">
                                <label class="form-label">Sub Categories *</label>
                                <div id="editSubCategoryRows" class="d-flex flex-column gap-2">
                                    <!-- Subcategory rows will be added here dynamically -->
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addEditSubCategoryRow">
                                    <i class="bi bi-plus-circle me-1"></i>Add More Subcategories
                                </button>
                                <small class="form-text text-muted">Add one or more sub categories for better expense tracking.</small>
                            </div>

                            <!-- Note -->
                            <div class="col-12">
                                <label class="form-label">Note</label>
                                <textarea name="note" class="form-control" rows="3" id="editNote" placeholder="Add any additional notes..."></textarea>
                            </div>

                            <!-- Receipt Images -->
                            <div class="col-12">
                                <label class="form-label">
                                    <i class="bi bi-image me-1"></i>Receipt Images
                                </label>
                                <div id="editReceiptImages" class="border rounded p-3 bg-light">
                                    <div class="text-center text-muted" id="noReceiptsMessage">
                                        <i class="bi bi-dash-circle display-4 d-block mb-2"></i>
                                        No receipts uploaded
                                    </div>
                                    <div id="receiptsGallery" class="row d-none">
                                        <!-- Receipt images will be displayed here -->
                                    </div>
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
                                            <small class="text-muted">Upload additional images (JPG, PNG, GIF, PDF, DOC, DOCX)</small>
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

    <!-- Top Up Money Modal -->
    <div class="modal fade" id="topUpModal" tabindex="-1" aria-labelledby="topUpModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="topUpModalLabel">
                        <i class="bi bi-wallet2 me-2"></i>Top Up Money
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="process_topup.php" enctype="multipart/form-data">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($current_page_redirect) ?>">
                    <div class="modal-body">
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
                            <label for="topupReceipt" class="form-label">Receipt Image</label>
                            <input type="file" class="form-control" id="topupReceipt" name="receipt_image" 
                                   accept="image/*" onchange="previewImage(event)">
                            <div id="imagePreview" class="mt-2" style="display: none;">
                                <img src="" alt="Receipt Preview" style="max-width: 100%; max-height: 200px; border-radius: 4px;">
                            </div>
                            <small class="form-text text-muted">Upload receipt or proof of payment (optional)</small>
                        </div>
                        <div class="mb-3">
                            <label for="topupDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="topupDescription" name="description" rows="3" 
                                      placeholder="Add any notes about this top-up..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="topupDate" class="form-label">Date *</label>
                            <input type="date" class="form-control" id="topupDate" name="topup_date" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle me-1"></i>Top Up Money
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Top-Up Modal -->
<div class="modal fade" id="editTopupModal" tabindex="-1" aria-labelledby="editTopupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editTopupModalLabel">
                    <i class="bi bi-pencil-square me-2"></i>Edit Top-Up Money
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="update_topup.php" enctype="multipart/form-data" onsubmit="return validateTopupEdit()">
                <input type="hidden" id="editTopupId" name="id">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($current_page_redirect) ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editTopupAmount" class="form-label">Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" id="editTopupAmount" name="amount" 
                                           step="0.01" min="0.01" placeholder="0.00" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editTopupDate" class="form-label">Date *</label>
                                <input type="date" class="form-control" id="editTopupDate" name="topup_date" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editTopupSource" class="form-label">Source *</label>
                                <select class="form-select" id="editTopupSource" name="source" required>
                                    <option value="">Select source</option>
                                    <?php foreach ($bank_options as $bank): ?>
                                        <option value="<?= htmlspecialchars($bank) ?>">
                                            <?= htmlspecialchars($bank) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editTopupPerson" class="form-label">Person Name *</label>
                                <select class="form-select" id="editTopupPerson" name="person_name" required>
                                    <option value="">Select person</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= htmlspecialchars($user['name']) ?>">
                                            <?= htmlspecialchars($user['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="other">Other (Specify in description)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editTopupDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="editTopupDescription" name="description" rows="3" 
                                  placeholder="Add any notes about this top-up..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editTopupReceipt" class="form-label">Receipt Image</label>
                        <input type="file" class="form-control" id="editTopupReceipt" name="receipt_image" 
                               accept="image/*" onchange="previewEditImage(event)">
                        <div id="editReceiptPreview" class="mt-2"></div>
                        <small class="form-text text-muted">Upload new receipt (optional). Current receipt will be replaced.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-1"></i>Update Top-Up
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function previewEditImage(event) {
    const file = event.target.files[0];
    const preview = document.getElementById('editReceiptPreview');
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `<img src="${e.target.result}" alt="Receipt Preview" style="max-width: 100px; max-height: 100px; border-radius: 4px;">`;
        }
        reader.readAsDataURL(file);
    }
}

function validateTopupEdit() {
    const newAmount = parseFloat(document.getElementById('editTopupAmount').value);
    const originalAmount = window.originalTopupAmount;
    
    if (newAmount < originalAmount) {
        alert('Cannot edit top-up amount to a lower value!\n\nOriginal amount: $' + originalAmount.toFixed(2) + '\nNew amount: $' + newAmount.toFixed(2) + '\n\nTop-up amounts cannot be reduced to maintain financial integrity.');
        return false;
    }
    
    return true;
}
</script>

<script>
// Sub categories from database
const dbSubCategories = <?php 
    echo json_encode($subcategories_by_parent);
?>;

// Update sub categories when main category changes (for edit modal)
document.getElementById('editSpendTo').addEventListener('change', function() {
    const mainCategory = this.value;
    
    // Only clear if main category is different from current
    const currentMainCategory = window.currentEditMainCategory;
    if (currentMainCategory && currentMainCategory !== mainCategory) {
        // Clear all existing rows only when category actually changes
        const rowsContainer = document.getElementById('editSubCategoryRows');
        rowsContainer.innerHTML = '';
        
        if (mainCategory && dbSubCategories[mainCategory]) {
            // Create initial row
            createEditSubCategoryRow();
        }
    }
    
    // Update current main category tracker
    window.currentEditMainCategory = mainCategory;
});

function createEditSubCategoryRow(selectedValue = '') {
    const mainCategory = document.getElementById('editSpendTo').value;
    const rowsContainer = document.getElementById('editSubCategoryRows');
    
    if (!mainCategory || !dbSubCategories[mainCategory]) return;
    
    const rowCount = rowsContainer.querySelectorAll('.edit-subcategory-row').length;
    const row = document.createElement('div');
    row.className = 'row g-2 align-items-center edit-subcategory-row';
    row.innerHTML = `
        <div class="col-md-8">
            <select name="sub_categories[]" class="form-select edit-subcategory-select" required>
                <option value="">Select sub category</option>
                ${dbSubCategories[mainCategory].map(subCat => 
                    `<option value="${subCat.toLowerCase().replace(/\s+/g, '_')}" ${selectedValue === subCat.toLowerCase().replace(/\s+/g, '_') ? 'selected' : ''}>${subCat}</option>`
                ).join('')}
            </select>
        </div>
        <div class="col-md-4">
            <button type="button" class="btn btn-outline-danger btn-sm remove-edit-row" ${rowCount === 0 ? 'style="display:none;"' : ''}>
                <i class="bi bi-trash"></i> Remove
            </button>
        </div>
    `;
    
    rowsContainer.appendChild(row);
    
    // Show/hide remove buttons based on row count
    updateEditRemoveButtons();
}

function updateEditRemoveButtons() {
    const rows = document.querySelectorAll('.edit-subcategory-row');
    rows.forEach((row, index) => {
        const removeBtn = row.querySelector('.remove-edit-row');
        if (removeBtn) {
            removeBtn.style.display = rows.length > 1 ? 'inline-block' : 'none';
        }
    });
}

// Add more subcategories for edit
document.getElementById('addEditSubCategoryRow').addEventListener('click', function() {
    createEditSubCategoryRow();
});

// Remove subcategory row for edit
document.getElementById('editSubCategoryRows').addEventListener('click', function(e) {
    if (e.target.closest('.remove-edit-row')) {
        const rows = document.querySelectorAll('.edit-subcategory-row');
        if (rows.length > 1) {
            e.target.closest('.edit-subcategory-row').remove();
            updateEditRemoveButtons();
        }
    }
});

// Prevent duplicate subcategories in edit
document.getElementById('editSubCategoryRows').addEventListener('change', function(e) {
    if (e.target.classList.contains('edit-subcategory-select')) {
        const currentSelect = e.target;
        const selectedValue = currentSelect.value;
        
        if (selectedValue) {
            let duplicateFound = false;
            
            document.querySelectorAll('.edit-subcategory-select').forEach(select => {
                if (select !== currentSelect && select.value === selectedValue) {
                    duplicateFound = true;
                }
            });
            
            if (duplicateFound) {
                alert('This subcategory is already selected. Please choose a different one.');
                currentSelect.value = '';
            }
        }
    }
});

// Handle edit form submission
document.getElementById('editSpendingForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Set removed images data
    document.getElementById('removedImages').value = JSON.stringify(removedImages);
    
    const formData = new FormData(this);
    
    fetch('update_spending.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('editSpendingModal'));
            modal.hide();
            
            // Show success message and reload page
            alert('Spending updated successfully!');
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating spending');
    });
});

// Edit spending function
function editSpending(id) {
    if (id) {
        // Load spending data via AJAX and show modal
        fetch('get_spending.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Populate modal fields
                    document.getElementById('editSpendingId').value = data.spending.id;
                    document.getElementById('editSpendingCode').value = data.spending.spending_code;
                    document.getElementById('editAmount').value = data.spending.amount;
                    document.getElementById('editPaidBy').value = data.spending.paid_by;
                    document.getElementById('editReceiveBy').value = data.spending.receive_by;
                    document.getElementById('editSpendingDate').value = data.spending.spending_date;
                    document.getElementById('editStatus').value = data.spending.status;
                    document.getElementById('editSpendTo').value = data.spending.category;
                    
                    // Load subcategories using dynamic rows
                    const rowsContainer = document.getElementById('editSubCategoryRows');
                    rowsContainer.innerHTML = '';
                    
                    const mainCategory = data.spending.category;
                    if (mainCategory && dbSubCategories[mainCategory]) {
                        // Handle multiple subcategories selection
                        let selectedSubCategories = [];
                        if (data.spending.sub_categories) {
                            selectedSubCategories = JSON.parse(data.spending.sub_categories);
                        } else if (data.spending.sub_category) {
                            selectedSubCategories = [data.spending.sub_category];
                        }
                        
                        // Create rows for each selected subcategory
                        if (selectedSubCategories.length > 0) {
                            selectedSubCategories.forEach(subCat => {
                                createEditSubCategoryRow(subCat);
                            });
                        } else {
                            // Create at least one empty row
                            createEditSubCategoryRow();
                        }
                    }
                    
                    document.getElementById('editNote').value = data.spending.note || '';
                    
                    // Display receipt images
                    displayEditReceipts(data.spending.images);
                    
                    // Show modal
                    const modal = new bootstrap.Modal(document.getElementById('editSpendingModal'));
                    modal.show();
                } else {
                    alert('Error loading spending data: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading spending data');
            });
    }
}

// Delete spending function
function deleteSpending(id, code) {
    if (id && code) {
        if (confirm('Are you sure you want to delete spending record "' + code + '"? This action cannot be undone.')) {
            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'delete_spending.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'spending_id';
            input.value = id;
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }
}
</script>

<!-- Floating Action Button for Mobile -->
<div class="quick-actions d-md-none">
    <button class="btn btn-primary" onclick="toggleFabMenu()" id="fabMain">
        <i class="bi bi-plus-lg"></i>
    </button>
</div>

<div class="fab-menu d-md-none" id="fabMenu">
    <a href="add_topup.php" class="btn btn-success" onclick="closeFabMenu()">
        <i class="bi bi-wallet2"></i>
    </a>
    <a href="add_spending.php" class="btn btn-danger" onclick="closeFabMenu()">
        <i class="bi bi-dash-circle"></i>
    </a>
    <a href="manage_categories.php" class="btn btn-info" onclick="closeFabMenu()">
        <i class="bi bi-tags"></i>
    </a>
</div>

<script>
function toggleFabMenu() {
    const fabMenu = document.getElementById('fabMenu');
    const fabMain = document.getElementById('fabMain');
    
    if (fabMenu.classList.contains('show')) {
        fabMenu.classList.remove('show');
        fabMain.innerHTML = '<i class="bi bi-plus-lg"></i>';
    } else {
        fabMenu.classList.add('show');
        fabMain.innerHTML = '<i class="bi bi-x-lg"></i>';
    }
}

function closeFabMenu() {
    const fabMenu = document.getElementById('fabMenu');
    const fabMain = document.getElementById('fabMain');
    
    fabMenu.classList.remove('show');
    fabMain.innerHTML = '<i class="bi bi-plus-lg"></i>';
}

// Close FAB when clicking outside
document.addEventListener('click', function(event) {
    const fabMenu = document.getElementById('fabMenu');
    const fabMain = document.getElementById('fabMain');
    
    if (!fabMain.contains(event.target) && !fabMenu.contains(event.target)) {
        closeFabMenu();
    }
});

// Top-up management functions
function editTopup(id) {
    // Fetch top-up data and populate edit modal
    fetch('get_topup.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Store original amount for validation
                window.originalTopupAmount = parseFloat(data.topup.amount);
                
                // Populate edit modal fields
                document.getElementById('editTopupId').value = data.topup.id;
                document.getElementById('editTopupAmount').value = data.topup.amount;
                document.getElementById('editTopupSource').value = data.topup.source;
                document.getElementById('editTopupDescription').value = data.topup.description || '';
                document.getElementById('editTopupDate').value = data.topup.topup_date;
                document.getElementById('editTopupPerson').value = data.topup.person_name || '';
                
                // Show existing receipt if any
                const receiptPreview = document.getElementById('editReceiptPreview');
                if (data.topup.receipt_image) {
                    receiptPreview.innerHTML = `<img src="../${data.topup.receipt_image}" alt="Current Receipt" style="max-width: 100px; max-height: 100px; border-radius: 4px;">`;
                } else {
                    receiptPreview.innerHTML = '';
                }
                
                // Show edit modal
                const modal = new bootstrap.Modal(document.getElementById('editTopupModal'));
                modal.show();
            } else {
                alert('Error loading top-up data: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading top-up data');
        });
}

function deleteTopup(id) {
    if (confirm('Are you sure you want to delete this top-up record? This action cannot be undone.')) {
        // Create form for safe deletion
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'delete_topup_safe.php';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'id';
        input.value = id;
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
}

const spendingImageBaseUrl = <?= json_encode(str_replace('__file__', '', uploaded_file_url('__file__', 'spending_images'))) ?>;

function showReceipts(imagesJson, spendingCode) {
    const images = JSON.parse(imagesJson);
    let imageHtml = '';
    
    images.forEach((image, index) => {
        const isImage = /\.(jpg|jpeg|png|gif)$/i.test(image);
        if (isImage) {
            imageHtml += `
                <div class="col-md-4 mb-3">
                    <div class="card">
                        <img src="${spendingImageBaseUrl}${image.replace(/^uploads\/spending_images\//, '')}" class="card-img-top" alt="Receipt ${index + 1}" style="height: 200px; object-fit: cover;">
                        <div class="card-body p-2">
                            <small class="text-muted">${image}</small>
                            <div class="mt-1">
                                <a href="${spendingImageBaseUrl}${image.replace(/^uploads\/spending_images\//, '')}" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i> View Full
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        } else {
            imageHtml += `
                <div class="col-md-4 mb-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="bi bi-file-earmark display-4 text-primary"></i>
                            <h6 class="mt-2">${image}</h6>
                            <a href="${spendingImageBaseUrl}${image.replace(/^uploads\/spending_images\//, '')}" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-download"></i> Download
                            </a>
                        </div>
                    </div>
                </div>
            `;
        }
    });
    
    // Create modal content
    const modalHtml = `
        <div class="modal fade" id="receiptsModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-image me-2"></i>
                            Receipts for ${spendingCode}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            ${imageHtml}
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('receiptsModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to page and show it
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = new bootstrap.Modal(document.getElementById('receiptsModal'));
    modal.show();
    
    // Clean up modal after hidden
    document.getElementById('receiptsModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

function displayEditReceipts(imagesJson) {
    const noReceiptsMessage = document.getElementById('noReceiptsMessage');
    const receiptsGallery = document.getElementById('receiptsGallery');
    const imageManagementSection = document.getElementById('imageManagementSection');
    
    if (!imagesJson || imagesJson === 'null' || imagesJson === '') {
        // Show no receipts message
        noReceiptsMessage.classList.remove('d-none');
        receiptsGallery.classList.add('d-none');
        imageManagementSection.classList.add('d-none');
        receiptsGallery.innerHTML = '';
        return;
    }
    
    try {
        const images = JSON.parse(imagesJson);
        if (!Array.isArray(images) || images.length === 0) {
            // Show no receipts message
            noReceiptsMessage.classList.remove('d-none');
            receiptsGallery.classList.add('d-none');
            imageManagementSection.classList.add('d-none');
            receiptsGallery.innerHTML = '';
            return;
        }
        
        // Hide no receipts message and show gallery
        noReceiptsMessage.classList.add('d-none');
        receiptsGallery.classList.remove('d-none');
        imageManagementSection.classList.remove('d-none');
        
        let galleryHtml = '';
        images.forEach((image, index) => {
            const isImage = /\.(jpg|jpeg|png|gif)$/i.test(image);
            if (isImage) {
                galleryHtml += `
                    <div class="col-md-4 mb-3">
                        <div class="card position-relative">
                            <img src="${spendingImageBaseUrl}${image.replace(/^uploads\/spending_images\//, '')}" class="card-img-top" alt="Receipt ${index + 1}" style="height: 150px; object-fit: cover; cursor: pointer;" onclick="window.open(spendingImageBaseUrl + '${image}'.replace(/^uploads\/spending_images\//, ''), '_blank')">
                            <div class="card-body p-2">
                                <small class="text-muted d-block text-truncate" title="${image}">${image}</small>
                                <div class="mt-1 d-flex gap-1">
                                    <a href="${spendingImageBaseUrl}${image.replace(/^uploads\/spending_images\//, '')}" target="_blank" class="btn btn-sm btn-outline-primary flex-fill">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeExistingImage(${index}, '${image}')" title="Remove Image">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                galleryHtml += `
                    <div class="col-md-4 mb-3">
                        <div class="card position-relative">
                            <div class="card-body text-center">
                                <i class="bi bi-file-earmark display-4 text-primary"></i>
                                <h6 class="mt-2 small text-truncate" title="${image}">${image}</h6>
                                <div class="d-flex gap-1">
                                    <a href="${spendingImageBaseUrl}${image.replace(/^uploads\/spending_images\//, '')}" target="_blank" class="btn btn-sm btn-outline-primary flex-fill">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeExistingImage(${index}, '${image}')" title="Remove Image">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }
        });
        
        receiptsGallery.innerHTML = galleryHtml;
        
    } catch (e) {
        console.error('Error parsing images:', e);
        // Show no receipts message on error
        noReceiptsMessage.classList.remove('d-none');
        receiptsGallery.classList.add('d-none');
        imageManagementSection.classList.add('d-none');
        receiptsGallery.innerHTML = '';
    }
}

// Global variable to track removed images
let removedImages = [];
let currentImages = [];

function showAddImageSection() {
    const addImageSection = document.getElementById('addImageSection');
    addImageSection.classList.toggle('d-none');
}

function removeExistingImage(index, filename) {
    if (confirm(`Are you sure you want to remove "${filename}"?`)) {
        // Add to removed images list
        removedImages.push(filename);
        
        // Remove from current images display
        const card = event.target.closest('.col-md-4');
        if (card) {
            card.remove();
        }
        
        // Check if any images left
        const remainingCards = document.querySelectorAll('#receiptsGallery .col-md-4');
        if (remainingCards.length === 0) {
            document.getElementById('noReceiptsMessage').classList.remove('d-none');
            document.getElementById('receiptsGallery').classList.add('d-none');
            document.getElementById('imageManagementSection').classList.add('d-none');
        }
        
        console.log('Removed images:', removedImages);
    }
}

// Update editSpending function to handle images
function editSpending(id) {
    // Reset removed images list
    removedImages = [];
    
    if (id) {
        // Load spending data via AJAX and show modal
        fetch('get_spending.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Store current images
                    currentImages = data.spending.images ? JSON.parse(data.spending.images) : [];
                    
                    // Track current main category to prevent clearing
                    window.currentEditMainCategory = data.spending.category;
                    
                    // Populate modal fields
                    document.getElementById('editSpendingId').value = data.spending.id;
                    document.getElementById('editSpendingCode').value = data.spending.spending_code;
                    document.getElementById('editAmount').value = data.spending.amount;
                    document.getElementById('editPaidBy').value = data.spending.paid_by;
                    document.getElementById('editReceiveBy').value = data.spending.receive_by;
                    document.getElementById('editSpendingDate').value = data.spending.spending_date;
                    document.getElementById('editStatus').value = data.spending.status;
                    document.getElementById('editSpendTo').value = data.spending.category;
                    
                    // Handle subcategories
                    const subCategories = data.spending.sub_categories ? JSON.parse(data.spending.sub_categories) : [];
                    const subCategoryRows = document.getElementById('editSubCategoryRows');
                    subCategoryRows.innerHTML = '';
                    
                    if (subCategories.length > 0) {
                        subCategories.forEach(subCat => {
                            createEditSubCategoryRow(subCat);
                        });
                    } else {
                        // Create at least one empty row
                        createEditSubCategoryRow();
                    }
                    
                    document.getElementById('editNote').value = data.spending.note || '';
                    
                    // Display receipt images
                    displayEditReceipts(data.spending.images);
                    
                    // Show modal
                    const modal = new bootstrap.Modal(document.getElementById('editSpendingModal'));
                    modal.show();
                } else {
                    alert('Error loading spending data: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading spending data');
            });
    }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Spending by Category chart (horizontal bar)
    const catEl = document.getElementById('categoryChartData');
    if (catEl && catEl.textContent.trim()) {
        const catData = JSON.parse(catEl.textContent);
        const catCtx = document.getElementById('categoryBarChart');
        if (catCtx && catData.labels.length > 0) {
            const colors = ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899'];
            new Chart(catCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: catData.labels,
                    datasets: [{
                        label: 'Amount ($)',
                        data: catData.amounts,
                        backgroundColor: catData.labels.map((_, i) => colors[i % colors.length] + 'cc'),
                        borderColor: catData.labels.map((_, i) => colors[i % colors.length]),
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, ticks: { callback: v => '$' + v.toLocaleString() } }
                    }
                }
            });
        }
    }
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
