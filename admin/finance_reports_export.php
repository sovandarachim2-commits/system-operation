<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_reports.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$category = $_GET['category'] ?? '';

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
$sql .= " ORDER BY fs.category, fs.spending_date ASC, fs.created_at ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$spending_records = $stmt->fetchAll();

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

// Build rows + styles for XLSX
$rows = [];
$styles = [];
$r = 0;

$rows[$r] = ['Spending History'];
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

foreach ($cat_order as $cat) {
    $items = $spending_by_cat[$cat];
    $cat_name = ucfirst(str_replace('_', ' ', $cat));
    $cat_total = array_sum(array_column($items, 'amount'));

    $rows[$r] = [strtoupper($cat_name)];
    $styles[$r] = 'section';
    $r++;

    $rows[$r] = ['No.', 'Date', 'Paid By', 'Description', 'Spending Category', 'Amount USD'];
    $styles[$r] = 'header';
    $r++;

    $row = 1;
    foreach ($items as $s) {
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

        $rows[$r] = [
            $row++,
            date('d-M-Y', strtotime($s['spending_date'])),
            $paid_by,
            $desc,
            $cat_name,
            '$ ' . number_format($s['amount'], 2)
        ];
        $styles[$r] = 'cell';
        $r++;
    }

    $rows[$r] = ['Total', '', '', '', $cat_name, '$ ' . number_format($cat_total, 2)];
    $styles[$r] = 'total-red';
    $r++;

    $rows[$r] = [''];
    $styles[$r] = 'default';
    $r++;
}

$rows[$r] = ['GRAND TOTAL SUMMARY'];
$styles[$r] = 'section';
$r++;

$rows[$r] = ['Category', 'Count', 'Amount USD'];
$styles[$r] = 'header';
$r++;

foreach ($cat_order as $cat) {
    $items = $spending_by_cat[$cat];
    $cat_name = ucfirst(str_replace('_', ' ', $cat));
    $cat_total = array_sum(array_column($items, 'amount'));
    $cat_count = count($items);
    $rows[$r] = [$cat_name, $cat_count, '$ ' . number_format($cat_total, 2)];
    $styles[$r] = 'cell';
    $r++;
}
$grand_total = array_sum(array_column($spending_records, 'amount'));
$rows[$r] = ['All Categories Total', count($spending_records), '$ ' . number_format($grand_total, 2)];
$styles[$r] = 'total-red';
$r++;

$rows[$r] = [''];
$styles[$r] = 'default';
$r++;

$rows[$r] = ['Prepared by', 'Checked by', 'Approved by'];
$styles[$r] = 'section';
$r++;

$rows[$r] = ['Date: __________/__________/__________', 'Date: __________/__________/__________', 'Date: __________/__________/__________'];
$styles[$r] = 'default';
$r++;

$rows[$r] = ['Name:', 'Name:', 'Name:'];
$styles[$r] = 'default';
$r++;

$colWidths = [6, 14, 16, 28, 18, 14];

if (class_exists('ZipArchive')) {
    require_once __DIR__ . '/finance_xlsx_helper.php';
    $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('spending_history_', true) . '.xlsx';
    finance_create_xlsx($tempPath, $rows, $styles, 'Spending History', $colWidths);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="spending_history_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    readfile($tempPath);
    @unlink($tempPath);
} else {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="spending_history_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        fputcsv($out, is_array($row) ? $row : [$row]);
    }
    fclose($out);
}
exit;
