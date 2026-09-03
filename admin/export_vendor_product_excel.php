<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_reports.view');

$pdo = get_db_connection();

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$vendor_filter = (int)($_GET['vendor_filter'] ?? 0);
$status_filter = $_GET['status_filter'] ?? '';
$payment_filter = $_GET['payment_filter'] ?? '';
$product_search = trim($_GET['product_search'] ?? '');
$order_code_filter = trim($_GET['order_code_filter'] ?? '');
$group_by = $_GET['group_by'] ?? 'vendor';

$params = [];
$sql = "
    SELECT 
        pv.id as vendor_id,
        pv.name as vendor_name,
        po.id as purchase_order_id,
        po.order_number,
        po.order_date,
        po.status as order_status,
        COALESCE(po.total_paid, 0) as total_paid,
        (po.total_amount - COALESCE(po.total_paid, 0)) as balance_due,
        COALESCE(po.payment_status, 'unpaid') as payment_status,
        poi.item_name,
        poi.sku,
        poi.quantity_ordered,
        COALESCE(SUM(pri.quantity_received), 0) as quantity_received,
        COALESCE((SELECT SUM(pri_ret.quantity_returned) FROM purchase_return_items pri_ret WHERE pri_ret.purchase_order_item_id = poi.id), 0) as quantity_returned,
        COALESCE((SELECT SUM(pri_ret.total_cost) FROM purchase_return_items pri_ret WHERE pri_ret.purchase_order_item_id = poi.id), 0) as amount_returned,
        poi.unit_price,
        poi.line_total
    FROM purchase_order_items poi
    JOIN purchase_orders po ON poi.purchase_order_id = po.id
    LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id
    LEFT JOIN purchase_receiving_items pri ON poi.id = pri.purchase_order_item_id
    WHERE po.order_date BETWEEN ? AND ?
";
$params[] = $from;
$params[] = $to;
if ($vendor_filter > 0) { $sql .= ' AND po.vendor_id = ?'; $params[] = $vendor_filter; }
if ($status_filter !== '') { $sql .= ' AND po.status = ?'; $params[] = $status_filter; }
if ($payment_filter !== '') { $sql .= ' AND COALESCE(po.payment_status, \'unpaid\') = ?'; $params[] = $payment_filter; }
if ($product_search !== '') {
    $search_param = '%' . $product_search . '%';
    $sql .= ' AND (poi.item_name LIKE ? OR poi.sku LIKE ?)';
    $params[] = $search_param;
    $params[] = $search_param;
}
if ($order_code_filter !== '') {
    $sql .= ' AND po.order_number LIKE ?';
    $params[] = '%' . $order_code_filter . '%';
}
$sql .= " GROUP BY poi.id, pv.id, pv.name, po.id, po.order_number, po.order_date, po.status, po.total_amount, po.total_paid, po.payment_status, poi.item_name, poi.sku, poi.quantity_ordered, poi.unit_price, poi.line_total ORDER BY pv.name, po.order_date DESC, poi.item_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$by_vendor = [];
$by_product = [];
$grand_total = 0;
$grand_total_paid = 0;
$grand_total_amount_return = 0;
$grand_balance_due = 0;
$seen_order_ids = [];

foreach ($rows as $row) {
    $row['quantity_received'] = (float)$row['quantity_received'];
    $row['quantity_returned'] = (float)($row['quantity_returned'] ?? 0);
    $row['amount_returned'] = (float)($row['amount_returned'] ?? 0);
    $row['quantity_ordered'] = (float)$row['quantity_ordered'];
    $row['quantity_not_received'] = max(0, $row['quantity_ordered'] - $row['quantity_received']);
    $row['unit_price'] = (float)$row['unit_price'];
    $row['line_total'] = (float)$row['line_total'];
    $row['total_paid'] = (float)($row['total_paid'] ?? 0);
    $row['balance_due'] = (float)($row['balance_due'] ?? 0);
    $grand_total += $row['line_total'];
    $grand_total_amount_return += $row['amount_returned'];
    $oid = (int)($row['purchase_order_id'] ?? 0);
    if ($oid > 0 && empty($seen_order_ids[$oid])) {
        $seen_order_ids[$oid] = true;
        $grand_total_paid += $row['total_paid'];
        $grand_balance_due += $row['balance_due'];
    }
    $vid = $row['vendor_id'];
    $vname = $row['vendor_name'] ?? 'Unknown Vendor';
    if (!isset($by_vendor[$vid])) {
        $by_vendor[$vid] = ['name' => $vname, 'items' => [], 'total_amount' => 0, 'total_paid' => 0, 'total_amount_return' => 0, 'balance_due' => 0, 'seen_orders' => []];
    }
    $by_vendor[$vid]['items'][] = $row;
    $by_vendor[$vid]['total_amount'] += $row['line_total'];
    $by_vendor[$vid]['total_amount_return'] += $row['amount_returned'];
    if ($oid > 0 && empty($by_vendor[$vid]['seen_orders'][$oid])) {
        $by_vendor[$vid]['seen_orders'][$oid] = true;
        $by_vendor[$vid]['total_paid'] += $row['total_paid'];
        $by_vendor[$vid]['balance_due'] += $row['balance_due'];
    }
    $pkey = ($row['item_name'] ?? '') . '|' . ($row['sku'] ?? '');
    if (!isset($by_product[$pkey])) {
        $by_product[$pkey] = ['item_name' => $row['item_name'], 'sku' => $row['sku'], 'rows' => [], 'total_qty' => 0, 'total_amount' => 0, 'total_paid' => 0, 'total_amount_return' => 0, 'balance_due' => 0, 'seen_orders' => []];
    }
    $by_product[$pkey]['rows'][] = $row;
    $by_product[$pkey]['total_qty'] += $row['quantity_ordered'];
    $by_product[$pkey]['total_amount'] += $row['line_total'];
    $by_product[$pkey]['total_amount_return'] += $row['amount_returned'];
    if ($oid > 0 && empty($by_product[$pkey]['seen_orders'][$oid])) {
        $by_product[$pkey]['seen_orders'][$oid] = true;
        $by_product[$pkey]['total_paid'] += $row['total_paid'];
        $by_product[$pkey]['balance_due'] += $row['balance_due'];
    }
}

$excelRows = [];
$rowStyles = [];
$r = 0;

$excelRows[$r] = ['Vendor Product Detail Report'];
$rowStyles[$r] = 'title';
$r++;

$excelRows[$r] = ['Period:', $from . ' to ' . $to, '', '', '', '', '', '', '', '', '', '', ''];
$rowStyles[$r] = 'meta';
$r++;

$excelRows[$r] = ['Grand Total:', '$' . number_format($grand_total, 2), 'Total Paid:', '$' . number_format($grand_total_paid, 2), 'Amount Return:', '$' . number_format($grand_total_amount_return, 2), 'Balance Due:', '$' . number_format($grand_balance_due, 2), count($rows) . ' line(s)', count($seen_order_ids) . ' order(s)', '', '', '', '', ''];
$rowStyles[$r] = 'total';
$r++;

$excelRows[$r] = [''];
$rowStyles[$r] = 'default';
$r++;

if (empty($rows)) {
    $excelRows[$r] = ['No purchase data found for the selected filters.'];
    $rowStyles[$r] = 'meta';
    $r++;
} elseif ($group_by === 'vendor') {
    $headers = ['No', 'Item Name', 'SKU', 'Order #', 'Order Date', 'Qty Ordered', 'Qty Received', 'Qty Not Received', 'Qty Return', 'Amount Return', 'Unit Price', 'Line Total', 'Payment Status', 'Status'];
    foreach ($by_vendor as $vendor) {
        $excelRows[$r] = [$vendor['name'] . ' — Total: $' . number_format($vendor['total_amount'], 2) . ' | Paid: $' . number_format($vendor['total_paid'], 2) . ' | Amount Return: $' . number_format($vendor['total_amount_return'] ?? 0, 2) . ' | Balance Due: $' . number_format($vendor['balance_due'], 2)];
        $rowStyles[$r] = 'section';
        $r++;
        $excelRows[$r] = $headers;
        $rowStyles[$r] = 'header';
        $r++;
        $last_oid = null;
        $no = 0;
        $t_qo = $t_qr = $t_qnr = $t_qret = $t_amt_ret = $t_line = 0;
        foreach ($vendor['items'] as $item) {
            $no++;
            $show = ($last_oid !== (int)($item['purchase_order_id'] ?? 0));
            if ($show) $last_oid = (int)($item['purchase_order_id'] ?? 0);
            $t_qo += $item['quantity_ordered'];
            $t_qr += $item['quantity_received'];
            $t_qnr += $item['quantity_not_received'];
            $t_qret += ($item['quantity_returned'] ?? 0);
            $t_amt_ret += ($item['amount_returned'] ?? 0);
            $t_line += $item['line_total'];
            $excelRows[$r] = [
                $no,
                $item['item_name'] ?? '',
                $item['sku'] ?? '-',
                $item['order_number'] ?? '',
                date('M j, Y', strtotime($item['order_date'] ?? '')),
                number_format($item['quantity_ordered'], 2),
                number_format($item['quantity_received'], 2),
                number_format($item['quantity_not_received'], 2),
                number_format($item['quantity_returned'] ?? 0, 2),
                '$' . number_format($item['amount_returned'] ?? 0, 2),
                '$' . number_format($item['unit_price'], 2),
                '$' . number_format($item['line_total'], 2),
                ucfirst($item['payment_status'] ?? ''),
                ucfirst($item['order_status'] ?? '')
            ];
            $rowStyles[$r] = ($no % 2 === 0) ? 'alt' : 'default';
            $r++;
        }
        $avg_unit = $t_qo > 0 ? '$' . number_format($t_line / $t_qo, 2) : '-';
        $excelRows[$r] = ['', '', '', '', 'Total:', number_format($t_qo, 2), number_format($t_qr, 2), number_format($t_qnr, 2), number_format($t_qret, 2), '$' . number_format($t_amt_ret, 2), $avg_unit, '$' . number_format($t_line, 2), '', ''];
        $rowStyles[$r] = 'total';
        $r++;
        $excelRows[$r] = ['Total: ' . count($vendor['items']) . ' line item(s) • ' . count($vendor['seen_orders']) . ' purchase order(s)'];
        $rowStyles[$r] = 'meta';
        $r++;
        $excelRows[$r] = [''];
        $rowStyles[$r] = 'default';
        $r++;
    }
} else {
    $headers = ['No', 'Vendor', 'Order #', 'Order Date', 'Qty Ordered', 'Qty Received', 'Qty Not Received', 'Qty Return', 'Amount Return', 'Unit Price', 'Line Total', 'Payment Status', 'Status'];
    foreach ($by_product as $prod) {
        $excelRows[$r] = [($prod['item_name'] ?? '') . (isset($prod['sku']) && $prod['sku'] !== '' ? ' (' . $prod['sku'] . ')' : '') . ' — Qty: ' . number_format($prod['total_qty'], 2) . ' | Total: $' . number_format($prod['total_amount'], 2) . ' | Paid: $' . number_format($prod['total_paid'], 2) . ' | Amount Return: $' . number_format($prod['total_amount_return'] ?? 0, 2) . ' | Balance Due: $' . number_format($prod['balance_due'], 2)];
        $rowStyles[$r] = 'section';
        $r++;
        $excelRows[$r] = $headers;
        $rowStyles[$r] = 'header';
        $r++;
        $last_oid = null;
        $no = 0;
        $t_qo = $t_qr = $t_qnr = $t_qret = $t_amt_ret = $t_line = 0;
        foreach ($prod['rows'] as $item) {
            $no++;
            $show = ($last_oid !== (int)($item['purchase_order_id'] ?? 0));
            if ($show) $last_oid = (int)($item['purchase_order_id'] ?? 0);
            $t_qo += $item['quantity_ordered'];
            $t_qr += $item['quantity_received'];
            $t_qnr += $item['quantity_not_received'];
            $t_qret += ($item['quantity_returned'] ?? 0);
            $t_amt_ret += ($item['amount_returned'] ?? 0);
            $t_line += $item['line_total'];
            $excelRows[$r] = [
                $no,
                $item['vendor_name'] ?? '',
                $item['order_number'] ?? '',
                date('M j, Y', strtotime($item['order_date'] ?? '')),
                number_format($item['quantity_ordered'], 2),
                number_format($item['quantity_received'], 2),
                number_format($item['quantity_not_received'], 2),
                number_format($item['quantity_returned'] ?? 0, 2),
                '$' . number_format($item['amount_returned'] ?? 0, 2),
                '$' . number_format($item['unit_price'], 2),
                '$' . number_format($item['line_total'], 2),
                ucfirst($item['payment_status'] ?? ''),
                ucfirst($item['order_status'] ?? '')
            ];
            $rowStyles[$r] = ($no % 2 === 0) ? 'alt' : 'default';
            $r++;
        }
        $avg_unit = $t_qo > 0 ? '$' . number_format($t_line / $t_qo, 2) : '-';
        $excelRows[$r] = ['', '', '', 'Total:', number_format($t_qo, 2), number_format($t_qr, 2), number_format($t_qnr, 2), number_format($t_qret, 2), '$' . number_format($t_amt_ret, 2), $avg_unit, '$' . number_format($t_line, 2), '', ''];
        $rowStyles[$r] = 'total';
        $r++;
        $excelRows[$r] = ['Total: ' . count($prod['rows']) . ' line item(s) • ' . count($prod['seen_orders']) . ' purchase order(s)'];
        $rowStyles[$r] = 'meta';
        $r++;
        $excelRows[$r] = [''];
        $rowStyles[$r] = 'default';
        $r++;
    }
}

$columnWidths = [5, 25, 12, 14, 12, 12, 12, 14, 12, 12, 12, 12, 14, 12];

$fileName = 'vendor_product_detail_' . date('Y-m-d') . '_' . preg_replace('/[^0-9\-]/', '', $from) . '_to_' . preg_replace('/[^0-9\-]/', '', $to);

if (class_exists('ZipArchive')) {
    $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('vendor_product_', true) . '.xlsx';
    vendorProductCreateXlsx($tempPath, $excelRows, $rowStyles, $columnWidths);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '.xlsx"');
    header('Cache-Control: max-age=0');
    readfile($tempPath);
    @unlink($tempPath);
} else {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.xls"');
    header('Cache-Control: max-age=0');
    echo vendorProductCreateHtmlExcel($excelRows, $rowStyles);
}
exit;

function vendorProductCreateXlsx($filePath, array $rows, array $rowStyles, array $columnWidths = []) {
    $zip = new ZipArchive();
    if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create XLSX file');
    }
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Vendor Product Detail" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="6"><font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><sz val="11"/><color rgb="FF111827"/><name val="Calibri"/></font>'
        . '<font><sz val="11"/><color rgb="FF374151"/><name val="Calibri"/></font></fonts>'
        . '<fills count="8"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF3B82F6"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0E7FF"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF9FAFB"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFE5E7EB"/></left><right style="thin"><color rgb="FFE5E7EB"/></right><top style="thin"><color rgb="FFE5E7EB"/></top><bottom style="thin"><color rgb="FFE5E7EB"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="8">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="5" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="4" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $styleMap = ['default' => 4, 'title' => 1, 'meta' => 2, 'header' => 3, 'section' => 6, 'total' => 2, 'alt' => 7];
    $colsXml = '';
    if (!empty($columnWidths)) {
        foreach (array_values($columnWidths) as $i => $w) {
            $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(50, max(8, $w)) . '" customWidth="1"/>';
        }
        $colsXml = '<cols>' . $colsXml . '</cols>';
    }
    $sheetData = '';
    foreach ($rows as $ri => $row) {
        $vals = is_array($row) ? array_values($row) : [$row];
        $sheetData .= '<row r="' . ($ri + 1) . '">';
        foreach ($vals as $ci => $v) {
            $cellRef = vendorProductCol($ci + 1) . ($ri + 1);
            $si = $styleMap[$rowStyles[$ri] ?? 'default'] ?? 4;
            $sheetData .= '<c r="' . $cellRef . '" t="inlineStr" s="' . $si . '"><is><t>' . htmlspecialchars((string)$v, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></is></c>';
        }
        $sheetData .= '</row>';
    }
    $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $colsXml . '<sheetData>' . $sheetData . '</sheetData></worksheet>';
    $created = gmdate('Y-m-d\TH:i:s\Z');
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Vendor Product Detail</dc:title><dc:creator>Shadow</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created></cp:coreProperties>';
    $app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Shadow</Application></Properties>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $worksheet);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('docProps/core.xml', $core);
    $zip->addFromString('docProps/app.xml', $app);
    $zip->close();
}

function vendorProductCol($n) {
    $s = '';
    while ($n > 0) { $mod = ($n - 1) % 26; $s = chr(65 + $mod) . $s; $n = (int)(($n - $mod) / 26); }
    return $s;
}

function vendorProductCreateHtmlExcel(array $excelRows, array $rowStyles) {
    $styles = [
        'title'   => 'background:#1D4ED8;color:#FFF;font-size:16px;font-weight:bold;padding:12px;text-align:center;border:1px solid #1E40AF',
        'meta'    => 'background:#E0E7FF;color:#1E293B;font-weight:bold;padding:8px;border:1px solid #C7D2FE',
        'total'   => 'background:#FEF3C7;color:#92400E;font-weight:bold;padding:8px;border:1px solid #FDE68A',
        'section' => 'background:#FDE68A;color:#78350F;font-weight:bold;padding:8px;border:1px solid #FCD34D',
        'header'  => 'background:#3B82F6;color:#FFF;font-weight:bold;padding:8px;border:1px solid #2563EB;text-align:center',
        'default' => 'background:#FFF;padding:6px;border:1px solid #E5E7EB',
        'alt'     => 'background:#F9FAFB;padding:6px;border:1px solid #E5E7EB',
    ];
    $html = "\xEF\xBB\xBF" . '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body><table border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-family:Calibri,Arial;font-size:11px">';
    foreach ($excelRows as $ri => $row) {
        $vals = is_array($row) ? array_values($row) : [$row];
        $styleKey = $rowStyles[$ri] ?? 'default';
        $style = $styles[$styleKey] ?? $styles['default'];
        $html .= '<tr>';
        if (count($vals) === 1 && in_array($styleKey, ['title', 'section', 'meta'], true)) {
            $html .= '<td colspan="11" style="' . $style . '">' . htmlspecialchars((string)$vals[0], ENT_QUOTES, 'UTF-8') . '</td>';
        } else {
            foreach ($vals as $v) {
                $html .= '<td style="' . $style . '">' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</td>';
            }
        }
        $html .= '</tr>';
    }
    $html .= '</table></body></html>';
    return $html;
}
