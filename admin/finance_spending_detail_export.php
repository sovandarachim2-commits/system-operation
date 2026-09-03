<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_reports.view');
require_once __DIR__ . '/../db.php';

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

// Group by sub-category
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

usort($spending_by_subcat, function($a, $b) {
    $ta = array_sum(array_column($a['items'], 'amount'));
    $tb = array_sum(array_column($b['items'], 'amount'));
    return $tb <=> $ta;
});

if ($sub_category_filter !== '') {
    $filter_key = strtolower(str_replace(' ', '_', $sub_category_filter));
    $spending_by_subcat = array_values(array_filter($spending_by_subcat, function($data) use ($filter_key) {
        $key = strtolower(str_replace(' ', '_', $data['label']));
        return $key === $filter_key;
    }));
}

$filtered_records = [];
foreach ($spending_by_subcat as $data) {
    $filtered_records = array_merge($filtered_records, $data['items']);
}
$total_amount = array_sum(array_column($filtered_records, 'amount'));

// Build rows + styles for XLSX
$rows = [];
$styles = [];
$r = 0;

$rows[$r] = ['Spending by Sub-Category'];
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

foreach ($spending_by_subcat as $data) {
    $items = $data['items'];
    $cat_total = array_sum(array_column($items, 'amount'));
    $subcat_label = $data['label'];

    $rows[$r] = [strtoupper($subcat_label)];
    $styles[$r] = 'section';
    $r++;

    $rows[$r] = ['No.', 'Date', 'Paid By', 'Category', 'Description', 'Amount USD'];
    $styles[$r] = 'header';
    $r++;

    $row = 1;
    foreach ($items as $s) {
        $desc = $s['note'] ?? '';
        if ($desc === '') $desc = $s['spending_code'] ?? '-';
        $paid_by = $s['paid_by'] ?? $s['receive_by'] ?? 'Cash';
        $main_cat = ucfirst(str_replace('_', ' ', $s['category']));

        $rows[$r] = [
            $row++,
            date('d-M-Y', strtotime($s['spending_date'])),
            $paid_by,
            $main_cat,
            $desc,
            '$ ' . number_format($s['amount'], 2)
        ];
        $styles[$r] = 'cell';
        $r++;
    }

    $rows[$r] = ['Total', '', '', $subcat_label, '', '$ ' . number_format($cat_total, 2)];
    $styles[$r] = 'total-red';
    $r++;

    $rows[$r] = [''];
    $styles[$r] = 'default';
    $r++;
}

$rows[$r] = ['GRAND TOTAL SUMMARY'];
$styles[$r] = 'section';
$r++;

$rows[$r] = ['Sub-Category', 'Count', 'Amount USD'];
$styles[$r] = 'header';
$r++;

foreach ($spending_by_subcat as $data) {
    $cat_name = $data['label'];
    $cat_total = array_sum(array_column($data['items'], 'amount'));
    $cat_count = count($data['items']);
    $rows[$r] = [$cat_name, $cat_count, '$ ' . number_format($cat_total, 2)];
    $styles[$r] = 'cell';
    $r++;
}
$rows[$r] = ['All Sub-Categories Total', count($filtered_records), '$ ' . number_format($total_amount, 2)];
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
    $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('spending_detail_', true) . '.xlsx';
    finance_create_xlsx($tempPath, $rows, $styles, 'Spending by Sub-Category', $colWidths);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="spending_by_subcategory_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    readfile($tempPath);
    @unlink($tempPath);
} else {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="spending_by_subcategory_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        fputcsv($out, is_array($row) ? $row : [$row]);
    }
    fclose($out);
}
exit;
