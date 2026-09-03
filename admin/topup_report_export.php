<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_dashboard.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

// Get parameters
$format = $_GET['format'] ?? 'excel';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
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
        COUNT(DISTINCT person_name) as unique_persons
    FROM finance_topups 
    WHERE $where_clause
");
$stmt->execute($params);
$summary = $stmt->fetch();

// Opening balance (balance before the selected period)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_topups WHERE topup_date < ?");
$stmt->execute([$start_date]);
$total_topups_before = (float) $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_spending WHERE DATE(spending_date) < ?");
$stmt->execute([$start_date]);
$total_spending_before = (float) $stmt->fetchColumn();
$opening_balance = $total_topups_before - $total_spending_before;

if ($format === 'excel') {
    // Build rows + styles for XLSX (matching print: borders, green total)
    $rows = [];
    $styles = [];
    $r = 0;

    $rows[$r] = ['Top-Up Report'];
    $styles[$r] = 'title';
    $r++;

    $rows[$r] = ['Period: ' . date('M d, Y', strtotime($start_date)) . ' - ' . date('M d, Y', strtotime($end_date))];
    $styles[$r] = 'meta';
    $r++;

    $rows[$r] = ['Generated: ' . date('M d, Y H:i')];
    $styles[$r] = 'meta';
    $r++;

    $rows[$r] = [''];

    $styles[$r] = 'default';
    $r++;

    $rows[$r] = ['Opening Balance'];
    $styles[$r] = 'section';
    $r++;

    $rows[$r] = ['$' . number_format($opening_balance, 2)];
    $styles[$r] = 'cell';
    $r++;

    $rows[$r] = ['DETAILED RECORDS'];
    $styles[$r] = 'section';
    $r++;

    $rows[$r] = ['No', 'Date', 'Source', 'Amount', 'Total', 'Person', 'Description', 'Receipt', 'Created By'];
    $styles[$r] = 'header';
    $r++;

    $running_total = $opening_balance;
    $row_num = 1;
    foreach ($topups as $topup) {
        $running_total += $topup['amount'];
        $rows[$r] = [
            $row_num++,
            date('M d, Y', strtotime($topup['topup_date'])),
            $topup['source'],
            '$' . number_format($topup['amount'], 2),
            '$' . number_format($running_total, 2),
            $topup['person_name'] ?? 'N/A',
            $topup['description'] ?? 'N/A',
            $topup['receipt_image'] ? 'Yes' : 'No',
            $topup['created_by_name'] ?? $topup['created_by'] ?? 'N/A'
        ];
        $styles[$r] = 'cell';
        $r++;
    }

    $rows[$r] = [
        'TOTAL',
        '',
        '',
        '$' . number_format($summary['total_amount'], 2),
        '$' . number_format($running_total, 2),
        '',
        '',
        '',
        $summary['total_count'] . ' Records'
    ];
    $styles[$r] = 'total-green';
    $r++;

    $rows[$r] = [''];
    $styles[$r] = 'default';
    $r++;

    // Source Summary (matching print)
    $rows[$r] = ['SOURCE SUMMARY'];
    $styles[$r] = 'section';
    $r++;

    $rows[$r] = ['No', 'Source', 'Count', 'Total Amount', 'Percentage'];
    $styles[$r] = 'header';
    $r++;

    $source_stats = [];
    foreach ($topups as $topup) {
        if (!isset($source_stats[$topup['source']])) {
            $source_stats[$topup['source']] = ['count' => 0, 'total' => 0];
        }
        $source_stats[$topup['source']]['count']++;
        $source_stats[$topup['source']]['total'] += $topup['amount'];
    }
    $sn = 1;
    foreach ($source_stats as $source => $stats) {
        $pct = $summary['total_amount'] > 0 ? ($stats['total'] / $summary['total_amount']) * 100 : 0;
        $rows[$r] = [$sn++, $source, $stats['count'], '$' . number_format($stats['total'], 2), number_format($pct, 1) . '%'];
        $styles[$r] = 'cell';
        $r++;
    }
    $rows[$r] = ['', 'All Sources', $summary['total_count'], '$' . number_format($summary['total_amount'], 2), '100%'];
    $styles[$r] = 'total-green';
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

    $colWidths = [5, 14, 18, 12, 14, 16, 25, 8, 16];

    if (class_exists('ZipArchive')) {
        require_once __DIR__ . '/finance_xlsx_helper.php';
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('topup_report_', true) . '.xlsx';
        finance_create_xlsx($tempPath, $rows, $styles, 'Top-Up Report', $colWidths);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="topup_report_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');
        readfile($tempPath);
        @unlink($tempPath);
    } else {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="topup_report_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($output, is_array($row) ? $row : [$row]);
        }
        fclose($output);
    }
    exit;
    
} elseif ($format === 'pdf') {
    // Export to PDF (Simple HTML to PDF conversion)
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="topup_report_' . date('Y-m-d') . '.pdf"');
    
    // HTML content for PDF
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Top-Up Report</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
            table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
            th, td { border: 1px solid #000; padding: 5px; text-align: left; }
            th { background-color: #f8f9fa; font-weight: bold; }
            .text-end { text-align: right; }
            .text-center { text-align: center; }
            .header { text-align: center; margin-bottom: 20px; }
            .summary { background-color: #f8f9fa; font-weight: bold; }
            @page { margin: 20px; }
        </style>
    </head>
    <body>
        <div class="header">
            <h2>Top-Up Report</h2>
            <p><strong>Period:</strong> ' . date('M d, Y', strtotime($start_date)) . ' - ' . date('M d, Y', strtotime($end_date)) . '</p>
            <p><strong>Generated:</strong> ' . date('M d, Y H:i') . '</p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Date</th>
                    <th>Source</th>
                    <th>Amount</th>
                    <th>Name Person</th>
                    <th>Create By</th>
                </tr>
            </thead>
            <tbody>';
    
    $running_total = 0;
    $row_num = 1;
    
    foreach ($topups as $topup) {
        $running_total += $topup['amount'];
        echo '
            <tr>
                <td class="text-center">' . $row_num++ . '</td>
                <td>' . date('M d, Y', strtotime($topup['topup_date'])) . '</td>
                <td>' . htmlspecialchars($topup['source']) . '</td>
                <td class="text-end">$' . number_format($topup['amount'], 2) . '</td>
                <td>' . htmlspecialchars($topup['person_name'] ?? 'N/A') . '</td>
                <td>' . htmlspecialchars($topup['created_by_name'] ?? $topup['created_by'] ?? 'N/A') . '</td>
            </tr>';
    }
    
    echo '
            </tbody>
        </table>
        
        <table>
            <thead>
                <tr>
                    <th colspan="5" class="text-center">Source Summary</th>
                </tr>
                <tr>
                    <th>Source</th>
                    <th class="text-end">Old Balance</th>
                    <th>Count</th>
                    <th>Total Amount</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>';
    
    // Calculate source breakdown for PDF
    $source_stats = [];
    foreach ($topups as $topup) {
        if (!isset($source_stats[$topup['source']])) {
            $source_stats[$topup['source']] = [
                'count' => 0,
                'total' => 0
            ];
        }
        $source_stats[$topup['source']]['count']++;
        $source_stats[$topup['source']]['total'] += $topup['amount'];
    }
    
    foreach ($source_stats as $source => $stats) {
        $percentage = $summary['total_amount'] > 0 ? ($stats['total'] / $summary['total_amount']) * 100 : 0;
        echo '
            <tr>
                <td>' . htmlspecialchars($source) . '</td>
                <td class="text-end">$' . number_format($opening_balance, 2) . '</td>
                <td class="text-center">' . number_format($stats['count']) . '</td>
                <td class="text-end">$' . number_format($stats['total'], 2) . '</td>
                <td class="text-end">' . number_format($percentage, 1) . '%</td>
            </tr>';
    }
    
    echo '
            <tr class="summary">
                <td><strong>All Sources</strong></td>
                <td class="text-end"><strong>$' . number_format($opening_balance, 2) . '</strong></td>
                <td class="text-center"><strong>' . number_format($summary['total_count']) . '</strong></td>
                <td class="text-end"><strong>$' . number_format($summary['total_amount'], 2) . '</strong></td>
                <td class="text-end"><strong>100%</strong></td>
            </tr>
            </tbody>
        </table>
        <div style="margin-top: 40px; padding-top: 30px; border-top: 1px solid #000;">
            <table style="width: 100%; border: none;">
                <tr>
                    <td style="width: 33%; text-align: center; border: none; vertical-align: top;">
                        <div style="font-weight: bold; margin-bottom: 20px;">Prepared by</div>
                        <div style="height: 4em;"></div>
                        <div style="text-align: left; margin-left: 15%; margin-bottom: 12px; font-size: 11px;">Date: <span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span>/<span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span>/<span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span></div>
                        <div style="text-align: left; margin-left: 15%; font-size: 11px;">Name:</div>
                    </td>
                    <td style="width: 33%; text-align: center; border: none; vertical-align: top;">
                        <div style="font-weight: bold; margin-bottom: 20px;">Checked by</div>
                        <div style="height: 4em;"></div>
                        <div style="text-align: left; margin-left: 15%; margin-bottom: 12px; font-size: 11px;">Date: <span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span>/<span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span>/<span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span></div>
                        <div style="text-align: left; margin-left: 15%; font-size: 11px;">Name:</div>
                    </td>
                    <td style="width: 34%; text-align: center; border: none; vertical-align: top;">
                        <div style="font-weight: bold; margin-bottom: 20px;">Approved by</div>
                        <div style="height: 4em;"></div>
                        <div style="text-align: left; margin-left: 15%; margin-bottom: 12px; font-size: 11px;">Date: <span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span>/<span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span>/<span style="display: inline-block; width: 50px; border-bottom: 1px solid #000;"></span></div>
                        <div style="text-align: left; margin-left: 15%; font-size: 11px;">Name:</div>
                    </td>
                </tr>
            </table>
        </div>
    </body>
    </html>';
    exit;
}

// Redirect if no valid format
header('Location: topup_report.php');
exit;
?>
