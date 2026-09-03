<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_dashboard.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$category_filter = $_GET['category'] ?? '';

// Opening balance
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_topups WHERE topup_date < ?");
$stmt->execute([$from_date]);
$opening_balance = (float) $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_spending WHERE DATE(spending_date) < ?");
$stmt->execute([$from_date]);
$opening_balance -= (float) $stmt->fetchColumn();

// Top-ups in period
$stmt = $pdo->prepare('SELECT * FROM finance_topups 
                       WHERE DATE(topup_date) BETWEEN ? AND ?
                       ORDER BY topup_date ASC, created_at ASC');
$stmt->execute([$from_date, $to_date]);
$topups = $stmt->fetchAll();

// Spending in period
$spending_query = 'SELECT * FROM finance_spending 
                   WHERE DATE(spending_date) BETWEEN ? AND ?';
$spending_params = [$from_date, $to_date];
if (!empty($category_filter)) {
    $spending_query .= ' AND category = ?';
    $spending_params[] = $category_filter;
}
$spending_query .= ' ORDER BY category, spending_date ASC, created_at ASC';
$stmt = $pdo->prepare($spending_query);
$stmt->execute($spending_params);
$spending = $stmt->fetchAll();

$total_topups = array_sum(array_column($topups, 'amount'));
$total_spending = array_sum(array_column($spending, 'amount'));

$cat_labels = [
    'employee' => 'Company Expenses',
    'marketing' => 'Marketing Expenses',
    'boost' => 'Boost Expenses',
    'salary' => 'Salary & Commission'
];
$spending_by_cat = [];
foreach ($spending as $s) {
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

// Build rows + styles for XLSX
$rows = [];
$styles = [];
$r = 0;

$rows[$r] = ['Expense Report – Finance Dashboard'];
$styles[$r] = 'title';
$r++;

$rows[$r] = ['Period: ' . date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date))];
$styles[$r] = 'meta';
$r++;

$rows[$r] = ['Generated: ' . date('d-m-Y H:i')];
$styles[$r] = 'meta';
$r++;

$rows[$r] = [''];
$styles[$r] = 'default';
$r++;

// Table 1: Top Up and Opening Balance
$rows[$r] = ['TOP UP AND OPENING BALANCE'];
$styles[$r] = 'section';
$r++;

$rows[$r] = ['No.', 'Date', 'Description', 'Debit', 'Credit', 'Running Balance'];
$styles[$r] = 'header';
$r++;

$running = $opening_balance;
$rows[$r] = [1, '', 'Opening Balance (' . date('m-Y', strtotime($from_date . '-01 -1 month')) . ')', '', '', '$' . number_format($running, 2)];
$styles[$r] = 'cell';
$r++;

$no = 2;
foreach ($topups as $t) {
    $running += $t['amount'];
    $rows[$r] = [$no++, date('d/m/Y', strtotime($t['topup_date'])), 'Add - ' . $t['source'], '$' . number_format($t['amount'], 2), '', '$' . number_format($running, 2)];
    $styles[$r] = 'cell';
    $r++;
}

$rows[$r] = ['', '', 'Total Top Up', '$' . number_format($total_topups, 2), '', '$' . number_format($running, 2)];
$styles[$r] = 'total-green';
$r++;

$rows[$r] = [''];
$styles[$r] = 'default';
$r++;

// Table 2: Spending by Category
foreach ($cat_order as $cat) {
    $items = $spending_by_cat[$cat];
    $cat_label = $cat_labels[$cat] ?? ucfirst($cat);
    $cat_total = array_sum(array_column($items, 'amount'));

    $rows[$r] = [strtoupper($cat_label)];
    $styles[$r] = 'section';
    $r++;

    $rows[$r] = ['No.', 'Date', 'Description', 'Debit', 'Credit', 'Running Balance'];
    $styles[$r] = 'header';
    $r++;

    $no = 1;
    foreach ($items as $s) {
        $desc = $s['spending_code'];
        if (!empty($s['sub_categories'])) {
            $subs = json_decode($s['sub_categories'], true);
            if (is_array($subs) && !empty($subs)) {
                $desc = implode(', ', array_map(function($x) { return ucfirst(str_replace('_', ' ', $x)); }, array_filter($subs)));
            }
        } elseif (!empty($s['sub_category'])) {
            $desc = ucfirst(str_replace('_', ' ', $s['sub_category']));
        }
        $running -= $s['amount'];
        $rows[$r] = [$no++, date('d/m/Y', strtotime($s['spending_date'])), $desc, '', '$' . number_format($s['amount'], 2), '$' . number_format($running, 2)];
        $styles[$r] = 'cell';
        $r++;
    }

    $rows[$r] = ['', '', 'Total ' . $cat_label, '', '$' . number_format($cat_total, 2), '$' . number_format($running, 2)];
    $styles[$r] = 'total-red';
    $r++;

    $rows[$r] = [''];
    $styles[$r] = 'default';
    $r++;
}

// Grand Total Summary
$rows[$r] = ['Summary', 'Total Expenses', 'Closing Balance'];
$styles[$r] = 'header';
$r++;

$rows[$r] = ['Grand Total (Expenses)', '$' . number_format($total_spending, 2), '$' . number_format($running, 2)];
$styles[$r] = 'total-red';
$r++;

$rows[$r] = [''];
$styles[$r] = 'default';
$r++;

// Signature block
$rows[$r] = ['Prepared by', 'Checked by', 'Approved by'];
$styles[$r] = 'section';
$r++;

$rows[$r] = ['Date: __________/__________/__________', 'Date: __________/__________/__________', 'Date: __________/__________/__________'];
$styles[$r] = 'default';
$r++;

$rows[$r] = ['Name:', 'Name:', 'Name:'];
$styles[$r] = 'default';
$r++;

$colWidths = [6, 12, 40, 14, 14, 16];

if (class_exists('ZipArchive')) {
    require_once __DIR__ . '/finance_xlsx_helper.php';
    $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('finance_dashboard_', true) . '.xlsx';
    finance_create_xlsx($tempPath, $rows, $styles, 'Finance Dashboard', $colWidths);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="finance_report_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    readfile($tempPath);
    @unlink($tempPath);
} else {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="finance_report_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        fputcsv($out, is_array($row) ? $row : [$row]);
    }
    fclose($out);
}
exit;
