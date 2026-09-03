<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

function sprlGetTelegramConfig(): array
{
    global $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID, $TELEGRAM_TARGETS;
    global $SOLD_PRODUCTS_TELEGRAM_BOT_TOKEN, $SOLD_PRODUCTS_TELEGRAM_CHAT_ID, $SOLD_PRODUCTS_TELEGRAM_TARGETS;

    $botToken = $TELEGRAM_BOT_TOKEN ?? '';
    $chatId = $TELEGRAM_CHAT_ID ?? '';
    $targets = (isset($TELEGRAM_TARGETS) && is_array($TELEGRAM_TARGETS)) ? $TELEGRAM_TARGETS : [];

    if (!empty($SOLD_PRODUCTS_TELEGRAM_BOT_TOKEN)) {
        $botToken = $SOLD_PRODUCTS_TELEGRAM_BOT_TOKEN;
    }
    if (!empty($SOLD_PRODUCTS_TELEGRAM_TARGETS) && is_array($SOLD_PRODUCTS_TELEGRAM_TARGETS)) {
        $targets = $SOLD_PRODUCTS_TELEGRAM_TARGETS;
    } elseif (!empty($SOLD_PRODUCTS_TELEGRAM_CHAT_ID)) {
        $targets = [['chat_id' => $SOLD_PRODUCTS_TELEGRAM_CHAT_ID, 'thread_id' => null]];
    }
    if (empty($targets) && !empty($chatId)) {
        $targets = [['chat_id' => $chatId, 'thread_id' => null]];
    }

    return ['bot_token' => $botToken, 'targets' => $targets];
}

function sprlCreateCsv(array $header, array $rows, string $prefix): array
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid($prefix, true) . '.csv';
    $fp = fopen($path, 'wb');
    if ($fp === false) {
        throw new RuntimeException('Cannot create report file');
    }
    fputcsv($fp, $header);
    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);

    return [
        'file_path' => $path,
        'file_name' => $prefix . '_' . date('Ymd_His') . '.csv',
    ];
}

function sprlSendDocumentToTelegram(string $filePath, string $fileName, string $caption = ''): array
{
    $cfg = sprlGetTelegramConfig();
    $botToken = (string)($cfg['bot_token'] ?? '');
    $targets = (array)($cfg['targets'] ?? []);
    if ($botToken === '' || empty($targets)) {
        return [['success' => false, 'error' => 'Telegram is not configured']];
    }
    if (!file_exists($filePath)) {
        return [['success' => false, 'error' => 'Report file not found']];
    }
    if (!function_exists('curl_init')) {
        return [['success' => false, 'error' => 'cURL extension is not available']];
    }

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimeType = 'application/octet-stream';
    if ($ext === 'xlsx') {
        $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    } elseif ($ext === 'pdf') {
        $mimeType = 'application/pdf';
    }

    $results = [];
    foreach ($targets as $target) {
        $chatId = trim((string)($target['chat_id'] ?? ''));
        $threadId = isset($target['thread_id']) && $target['thread_id'] !== null && $target['thread_id'] !== ''
            ? (int)$target['thread_id']
            : null;
        if ($chatId === '') {
            continue;
        }
        $url = "https://api.telegram.org/bot{$botToken}/sendDocument";
        $params = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'document' => new CURLFile($filePath, $mimeType, $fileName),
        ];
        if ($threadId !== null) {
            $params['message_thread_id'] = $threadId;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Keep broadcast response faster when Telegram network is slow.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        $targetLabel = 'chat_id=' . $chatId . ($threadId !== null ? ', thread_id=' . $threadId : '');
        if ($response !== false) {
            $decoded = json_decode($response, true);
            $results[] = [
                'success' => !empty($decoded['ok']),
                'error' => empty($decoded['ok'])
                    ? ($targetLabel . ': ' . ($decoded['description'] ?? 'Telegram API error'))
                    : null,
            ];
        } else {
            $results[] = [
                'success' => false,
                'error' => $targetLabel . ': ' . ($curlError !== '' ? $curlError : 'Failed to send document'),
            ];
        }
    }

    return $results;
}

function sprlResolveChromeBinary(): ?string
{
    $candidates = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function sprlRenderHtmlToPdf(string $html, string $pdfPath): void
{
    $chrome = sprlResolveChromeBinary();
    if ($chrome === null) {
        throw new RuntimeException('Chrome is required for PDF auto-send but was not found.');
    }

    $htmlPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sprl_pdf_', true) . '.html';
    if (file_put_contents($htmlPath, $html) === false) {
        throw new RuntimeException('Unable to create temporary HTML for PDF');
    }

    try {
        $real = str_replace('\\', '/', realpath($htmlPath) ?: $htmlPath);
        $fileUrl = 'file:///' . ltrim($real, '/');
        $command = '"' . $chrome . '" --headless --disable-gpu --no-sandbox --no-pdf-header-footer --virtual-time-budget=10000 --print-to-pdf="' . $pdfPath . '" "' . $fileUrl . '" 2>&1';
        $output = shell_exec($command);
        if (!is_file($pdfPath) || filesize($pdfPath) <= 0) {
            throw new RuntimeException('Failed to render PDF. ' . trim((string)$output));
        }
    } finally {
        @unlink($htmlPath);
    }
}

function sprlBuildPdfHtml(string $title, string $fromDate, string $toDate, array $headers, array $rows): string
{
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'body{font-family:"Khmer OS Siemreap","Khmer OS Battambang","Khmer OS Content","Khmer UI","Noto Sans Khmer","Segoe UI",Arial,sans-serif;font-size:12px;color:#111;padding:14px;}'
        . 'h1{font-size:18px;margin:0 0 8px;}'
        . '.meta{margin:0 0 12px;color:#333;}'
        . 'table{width:100%;border-collapse:collapse;table-layout:fixed;}'
        . 'th,td{border:1px solid #d1d5db;padding:6px 8px;word-break:break-word;vertical-align:top;font-size:11px;}'
        . 'th{background:#f3f4f6;text-align:left;}'
        . '.section-title{font-size:16px;margin:0 0 8px;}'
        . '.table-compact th,.table-compact td{font-size:10px;padding:4px 5px;}'
        . '.page-break{page-break-before:always;}'
        . '</style></head><body>';
    $html .= '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    $html .= '<p class="meta">From: ' . htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') . ' &nbsp;&nbsp; To: ' . htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') . '</p>';
    $html .= '<table><thead><tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . htmlspecialchars((string)$header, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></body></html>';
    return $html;
}

function sprlPdfTruncate(string $text, int $maxLen): string
{
    $text = trim($text);
    if ($maxLen <= 0) {
        return '';
    }
    if (mb_strlen($text) <= $maxLen) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $maxLen - 1)) . '…';
}

function sprlGenerateProductListPdf(array $soldProducts, string $fromDate, string $toDate): array
{
    $safeFrom = preg_replace('/[^0-9\-]/', '', $fromDate);
    $safeTo = preg_replace('/[^0-9\-]/', '', $toDate);
    $fileName = 'daily_sold_product_list_' . ($safeFrom !== '' ? $safeFrom : date('Y-m-d')) . '_to_' . ($safeTo !== '' ? $safeTo : date('Y-m-d')) . '.pdf';
    $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sold_products_list_', true) . '.pdf';

    $rows = [];
    foreach ($soldProducts as $index => $product) {
        $rows[] = [
            $index + 1,
            (string)($product['product_name'] ?? ''),
            (string)($product['product_code'] ?? ''),
            number_format((float)($product['total_quantity'] ?? 0), 2),
            number_format((int)($product['order_count'] ?? 0)),
            number_format((float)($product['total_sales'] ?? 0), 2),
            number_format((float)($product['total_discount'] ?? 0), 2),
            number_format(((float)($product['total_sales'] ?? 0)) - ((float)($product['total_discount'] ?? 0)), 2),
            isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : '',
        ];
    }

    $html = sprlBuildPdfHtml(
        'Daily Sold Product List',
        $fromDate,
        $toDate,
        ['No', 'Product Name', 'Code', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Total Sold', 'Last Sold'],
        $rows
    );
    sprlRenderHtmlToPdf($html, $filePath);
    return ['file_path' => $filePath, 'file_name' => $fileName];
}

function sprlGenerateDetailedOrdersPdf(array $detailedOrders, string $fromDate, string $toDate): array
{
    $safeFrom = preg_replace('/[^0-9\-]/', '', $fromDate);
    $safeTo = preg_replace('/[^0-9\-]/', '', $toDate);
    $fileName = 'detailed_orders_' . ($safeFrom !== '' ? $safeFrom : date('Y-m-d')) . '_to_' . ($safeTo !== '' ? $safeTo : date('Y-m-d')) . '.pdf';
    $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sold_products_orders_', true) . '.pdf';

    $rows = [];
    foreach ($detailedOrders as $index => $order) {
        $status = 'Paid';
        if (!empty($order['is_cancelled'])) {
            $status = 'Cancelled';
        } elseif (!empty($order['is_returned'])) {
            $status = 'Returned';
        } elseif (($order['status'] ?? '') !== 'paid') {
            $status = 'Unpaid';
        }
        $rows[] = [
            $index + 1,
            isset($order['printed_at']) ? date('Y-m-d H:i', strtotime((string)$order['printed_at'])) : '',
            (string)($order['order_code'] ?? ''),
            (string)($order['customer_name'] ?? ''),
            (string)($order['seller_name'] ?? 'N/A'),
            number_format((int)($order['item_count'] ?? 0)),
            number_format((float)($order['total_quantity'] ?? 0), 2),
            $status,
            number_format((float)($order['discount'] ?? 0), 2),
            number_format((float)($order['total_amount'] ?? 0), 2),
        ];
    }

    $html = sprlBuildPdfHtml(
        'Detailed Orders',
        $fromDate,
        $toDate,
        ['No', 'Printed At', 'Order Code', 'Customer', 'Seller', 'Items', 'Qty', 'Status', 'Discount', 'Amount'],
        $rows
    );
    sprlRenderHtmlToPdf($html, $filePath);
    return ['file_path' => $filePath, 'file_name' => $fileName];
}

function sprlGenerateCombinedAllTablesPdf(array $soldProducts, array $productDetailList, array $detailedOrders, string $fromDate, string $toDate): array
{
    $safeFrom = preg_replace('/[^0-9\-]/', '', $fromDate);
    $safeTo = preg_replace('/[^0-9\-]/', '', $toDate);
    $fileName = 'sold_products_all_tables_' . ($safeFrom !== '' ? $safeFrom : date('Y-m-d')) . '_to_' . ($safeTo !== '' ? $safeTo : date('Y-m-d')) . '.pdf';
    $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sold_products_all_', true) . '.pdf';

    $soldRows = [];
    $soldTotalQty = 0.0;
    $soldTotalOrders = 0;
    $soldTotalCost = 0.0;
    $soldTotalDiscount = 0.0;
    $soldTotalNet = 0.0;
    foreach ($soldProducts as $index => $product) {
        $qty = (float)($product['total_quantity'] ?? 0);
        $orders = (int)($product['order_count'] ?? 0);
        $cost = (float)($product['total_sales'] ?? 0);
        $discount = (float)($product['total_discount'] ?? 0);
        $net = $cost - $discount;
        $soldRows[] = [
            $index + 1,
            (string)($product['product_name'] ?? ''),
            (string)($product['product_code'] ?? ''),
            number_format($qty, 2),
            number_format($orders),
            number_format($cost, 2),
            number_format($discount, 2),
            number_format($net, 2),
            isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : '',
        ];
        $soldTotalQty += $qty;
        $soldTotalOrders += $orders;
        $soldTotalCost += $cost;
        $soldTotalDiscount += $discount;
        $soldTotalNet += $net;
    }
    $soldRows[] = [
        '',
        'TOTAL',
        '',
        number_format($soldTotalQty, 2),
        number_format($soldTotalOrders),
        number_format($soldTotalCost, 2),
        number_format($soldTotalDiscount, 2),
        number_format($soldTotalNet, 2),
        '',
    ];

    $detailRows = [];
    $detailTotalQty = 0.0;
    $detailTotalOrders = 0;
    $detailTotalCost = 0.0;
    $detailTotalDiscount = 0.0;
    $detailTotalNet = 0.0;
    foreach ($productDetailList as $index => $product) {
        $qty = (float)($product['total_quantity'] ?? 0);
        $orders = (int)($product['order_count'] ?? 0);
        $cost = (float)($product['total_sales'] ?? 0);
        $discount = (float)($product['total_discount'] ?? 0);
        $net = $cost - $discount;
        $detailRows[] = [
            $index + 1,
            (string)($product['product_name'] ?? ''),
            (string)($product['product_code'] ?? ''),
            number_format($qty, 2),
            number_format($orders),
            number_format($cost, 2),
            number_format($discount, 2),
            number_format($net, 2),
            isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : '',
        ];
        $detailTotalQty += $qty;
        $detailTotalOrders += $orders;
        $detailTotalCost += $cost;
        $detailTotalDiscount += $discount;
        $detailTotalNet += $net;
    }
    $detailRows[] = [
        '',
        'TOTAL',
        '',
        number_format($detailTotalQty, 2),
        number_format($detailTotalOrders),
        number_format($detailTotalCost, 2),
        number_format($detailTotalDiscount, 2),
        number_format($detailTotalNet, 2),
        '',
    ];

    $orderRows = [];
    $orderTotalItems = 0;
    $orderTotalQty = 0.0;
    $orderTotalDiscount = 0.0;
    $orderTotalAmount = 0.0;
    foreach ($detailedOrders as $index => $order) {
        $status = 'Paid';
        if (!empty($order['is_cancelled'])) {
            $status = 'Cancelled';
        } elseif (!empty($order['is_returned'])) {
            $status = 'Returned';
        } elseif (($order['status'] ?? '') !== 'paid') {
            $status = 'Unpaid';
        }
        $items = (int)($order['item_count'] ?? 0);
        $qty = (float)($order['total_quantity'] ?? 0);
        $discount = (float)($order['discount'] ?? 0);
        $amount = (float)($order['total_amount'] ?? 0);
        $orderRows[] = [
            $index + 1,
            isset($order['printed_at']) ? date('Y-m-d H:i', strtotime((string)$order['printed_at'])) : '',
            sprlPdfTruncate((string)($order['order_code'] ?? ''), 16),
            sprlPdfTruncate((string)($order['customer_name'] ?? ''), 24),
            sprlPdfTruncate((string)($order['seller_name'] ?? 'N/A'), 14),
            number_format($items),
            number_format($qty, 2),
            $status,
            number_format($discount, 2),
            number_format($amount, 2),
        ];
        $orderTotalItems += $items;
        $orderTotalQty += $qty;
        $orderTotalDiscount += $discount;
        $orderTotalAmount += $amount;
    }
    $orderRows[] = [
        '',
        '',
        '',
        'TOTAL',
        '',
        number_format($orderTotalItems),
        number_format($orderTotalQty, 2),
        number_format(count($detailedOrders)),
        number_format($orderTotalDiscount, 2),
        number_format($orderTotalAmount, 2),
    ];

    $html = sprlBuildPdfHtml(
        'Top Selling Products - Combined Report',
        $fromDate,
        $toDate,
        ['No', 'Product Name', 'Code', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Total Sold', 'Last Sold'],
        $soldRows
    );
    $html = str_replace('</body></html>', '', $html);

    $html .= '<h1 class="section-title">Product Detail List</h1>';
    $html .= '<table>';
    $html .= '<thead><tr>';
    foreach (['No', 'Product Name', 'Code', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Total Sold', 'Last Sold'] as $h) {
        $html .= '<th>' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($detailRows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<div class="page-break"></div>';
    $html .= '<h1 class="section-title">Detailed Orders</h1>';
    $html .= '<table class="table-compact">';
    $html .= '<thead><tr>';
    foreach (['No', 'Printed At', 'Order Code', 'Customer', 'Seller', 'Items', 'Qty', 'Status', 'Discount', 'Amount'] as $h) {
        $html .= '<th>' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($orderRows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></body></html>';

    sprlRenderHtmlToPdf($html, $filePath);
    return ['file_path' => $filePath, 'file_name' => $fileName];
}

function sprlXml($value): string
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function sprlColumnLetter(int $columnNumber): string
{
    $letter = '';
    while ($columnNumber > 0) {
        $mod = ($columnNumber - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $columnNumber = (int)(($columnNumber - $mod) / 26);
    }
    return $letter;
}

function sprlDosDateTime(): array
{
    $dt = getdate();
    $year = max(1980, (int)$dt['year']);
    $dosTime = (($dt['hours'] & 0x1F) << 11) | (($dt['minutes'] & 0x3F) << 5) | ((int)floor($dt['seconds'] / 2) & 0x1F);
    $dosDate = ((($year - 1980) & 0x7F) << 9) | (($dt['mon'] & 0x0F) << 5) | ($dt['mday'] & 0x1F);
    return [$dosDate, $dosTime];
}

function sprlUInt32($value): int
{
    return (int)sprintf('%u', $value);
}

function sprlCreateZipWithoutExtension(string $filePath, array $entries): void
{
    if (!function_exists('gzdeflate')) {
        throw new RuntimeException('Cannot create XLSX: zlib extension is missing');
    }

    [$dosDate, $dosTime] = sprlDosDateTime();
    $zipData = '';
    $central = '';
    $offset = 0;
    $count = 0;

    foreach ($entries as $name => $data) {
        $name = str_replace('\\', '/', (string)$name);
        $data = (string)$data;
        $rawLen = strlen($data);
        $compressed = gzdeflate($data, 9);
        if ($compressed === false) {
            throw new RuntimeException('Failed to compress XLSX data');
        }
        $compLen = strlen($compressed);
        $crc = sprlUInt32(crc32($data));

        $local = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            8,
            $dosTime,
            $dosDate,
            $crc,
            $compLen,
            $rawLen,
            strlen($name),
            0
        );
        $zipData .= $local . $name . $compressed;

        $centralHeader = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            0x0314,
            20,
            0,
            8,
            $dosTime,
            $dosDate,
            $crc,
            $compLen,
            $rawLen,
            strlen($name),
            0,
            0,
            0,
            0,
            32,
            $offset
        );
        $central .= $centralHeader . $name;
        $offset = strlen($zipData);
        $count++;
    }

    $eocd = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $count,
        $count,
        strlen($central),
        strlen($zipData),
        0
    );

    if (file_put_contents($filePath, $zipData . $central . $eocd) === false) {
        throw new RuntimeException('Unable to write XLSX file');
    }
}

function sprlCreateSimpleXlsxFile(string $filePath, array $rows, array $columnWidths = [], array $rowStyles = []): void
{
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sold Products" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="12"/><color rgb="FF111827"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="4">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right><top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $colsXml = '';
    if (!empty($columnWidths)) {
        foreach (array_values($columnWidths) as $index => $width) {
            $columnNumber = $index + 1;
            $colsXml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="' . sprlXml($width) . '" customWidth="1"/>';
        }
        $colsXml = '<cols>' . $colsXml . '</cols>';
    }

    $sheetData = '';
    foreach ($rows as $rowIndex => $row) {
        $sheetData .= '<row r="' . ($rowIndex + 1) . '">';
        foreach (array_values($row) as $columnIndex => $value) {
            $cellRef = sprlColumnLetter($columnIndex + 1) . ($rowIndex + 1);
            $styleKey = $rowStyles[$rowIndex] ?? 'default';
            $styleIndex = ($styleKey === 'title') ? 1 : (($styleKey === 'header') ? 2 : 3);
            $sheetData .= '<c r="' . $cellRef . '" t="inlineStr" s="' . $styleIndex . '"><is><t>'
                . sprlXml($value)
                . '</t></is></c>';
        }
        $sheetData .= '</row>';
    }

    $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . $colsXml
        . '<sheetData>' . $sheetData . '</sheetData>'
        . '</worksheet>';

    $created = gmdate('Y-m-d\TH:i:s\Z');
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>Sold Products Report</dc:title><dc:creator>Shadow</dc:creator><cp:lastModifiedBy>Shadow</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Shadow</Application>'
        . '</Properties>';

    $entries = [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rels,
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/worksheets/sheet1.xml' => $worksheet,
        'xl/styles.xml' => $styles,
        'docProps/core.xml' => $core,
        'docProps/app.xml' => $app,
    ];

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create XLSX file');
        }
        foreach ($entries as $entryName => $entryData) {
            $zip->addFromString($entryName, $entryData);
        }
        $zip->close();
        return;
    }

    sprlCreateZipWithoutExtension($filePath, $entries);
}

function sprlGenerateProductListExcel(array $soldProducts, string $fromDate, string $toDate): array
{
    $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sold_products_list_', true) . '.xlsx';
    $fileName = 'daily_sold_product_list_' . $fromDate . '_to_' . $toDate . '.xlsx';

    $rows = [];
    $rows[] = ['Daily Sold Product List', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['From Date', $fromDate, 'To Date', $toDate, '', '', '', '', '', '', '', '', ''];
    $rows[] = ['No', 'Product Name', 'Code', 'Type', 'Total Sold', 'Qty Return', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Delivery Cost', 'Total Amount', 'Last Sold'];
    foreach ($soldProducts as $index => $product) {
        $fullCost = (float)($product['total_sales'] ?? 0);
        $discount = (float)($product['total_discount'] ?? 0);
        $deliveryCost = (float)($product['total_delivery_cost'] ?? 0);
        $netQty = (float)($product['total_quantity'] ?? 0);
        $returnQty = (float)($product['return_quantity'] ?? 0);
        $rows[] = [
            $index + 1,
            (string)($product['product_name'] ?? ''),
            (string)($product['product_code'] ?? ''),
        product_type_display_label($product['product_type'] ?? 'normal'),
            number_format($netQty + $returnQty, 2, '.', ''),
            number_format($returnQty, 2, '.', ''),
            number_format($netQty, 2, '.', ''),
            (int)($product['order_count'] ?? 0),
            number_format($fullCost + $deliveryCost, 2, '.', ''),
            number_format($discount, 2, '.', ''),
            number_format($deliveryCost, 2, '.', ''),
            number_format($fullCost - $discount + $deliveryCost, 2, '.', ''),
            isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : '',
        ];
    }

    sprlCreateSimpleXlsxFile($filePath, $rows, [8, 28, 14, 14, 12, 12, 12, 10, 14, 14, 14, 14, 18], [0 => 'title', 2 => 'header']);
    return ['file_path' => $filePath, 'file_name' => $fileName];
}

function sprlGenerateDetailedOrdersExcel(array $detailedOrders, string $fromDate, string $toDate): array
{
    $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sold_products_orders_', true) . '.xlsx';
    $fileName = 'detailed_orders_' . $fromDate . '_to_' . $toDate . '.xlsx';

    $rows = [];
    $rows[] = ['Detailed Orders', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['From Date', $fromDate, 'To Date', $toDate, '', '', '', '', '', '', ''];
    $rows[] = ['No', 'Printed At', 'Order Code', 'Customer', 'Seller', 'Items', 'Products', 'Qty', 'Status', 'Discount', 'Amount'];
    foreach ($detailedOrders as $index => $order) {
        $status = 'Paid';
        if (!empty($order['is_cancelled'])) {
            $status = 'Cancelled';
        } elseif (!empty($order['is_returned'])) {
            $status = 'Returned';
        } elseif (($order['status'] ?? '') !== 'paid') {
            $status = 'Unpaid';
        }
        $rows[] = [
            $index + 1,
            isset($order['printed_at']) ? date('Y-m-d H:i', strtotime((string)$order['printed_at'])) : '',
            (string)($order['order_code'] ?? ''),
            (string)($order['customer_name'] ?? ''),
            (string)($order['seller_name'] ?? 'N/A'),
            (int)($order['item_count'] ?? 0),
            (string)($order['products_summary'] ?? ''),
            number_format((float)($order['total_quantity'] ?? 0), 2, '.', ''),
            $status,
            number_format((float)($order['discount'] ?? 0), 2, '.', ''),
            number_format((float)($order['total_amount'] ?? 0), 2, '.', ''),
        ];
    }

    sprlCreateSimpleXlsxFile($filePath, $rows, [8, 18, 16, 22, 18, 10, 32, 10, 14, 14, 14], [0 => 'title', 2 => 'header']);
    return ['file_path' => $filePath, 'file_name' => $fileName];
}

function sprlGenerateCombinedAllTablesExcel(array $soldProducts, array $productDetailList, array $detailedOrders, string $fromDate, string $toDate): array
{
    $safeFrom = preg_replace('/[^0-9\-]/', '', $fromDate);
    $safeTo = preg_replace('/[^0-9\-]/', '', $toDate);
    $fileName = 'sold_products_all_tables_' . ($safeFrom !== '' ? $safeFrom : date('Y-m-d')) . '_to_' . ($safeTo !== '' ? $safeTo : date('Y-m-d')) . '.xlsx';
    $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sold_products_all_', true) . '.xlsx';

    $rows = [];
    $rowStyles = [];

    $rows[] = ['Top Selling Products - Combined Report', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rowStyles[0] = 'title';
    $rows[] = ['From Date', $fromDate, 'To Date', $toDate, '', '', '', '', '', '', '', '', ''];
    $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', ''];

    $soldHeader = ['No', 'Product Name', 'Code', 'Type', 'Total Sold', 'Qty Return', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Delivery Cost', 'Total Amount', 'Last Sold'];
    $rows[] = $soldHeader;
    $rowStyles[count($rows) - 1] = 'header';
    foreach ($soldProducts as $index => $product) {
        $qty = (float)($product['total_quantity'] ?? 0);
        $returnQty = (float)($product['return_quantity'] ?? 0);
        $orders = (int)($product['order_count'] ?? 0);
        $cost = (float)($product['total_sales'] ?? 0);
        $discount = (float)($product['total_discount'] ?? 0);
        $deliveryCost = (float)($product['total_delivery_cost'] ?? 0);
        $rows[] = [
            $index + 1,
            (string)($product['product_name'] ?? ''),
            (string)($product['product_code'] ?? ''),
            product_type_display_label($product['product_type'] ?? 'normal'),
            number_format($qty + $returnQty, 2, '.', ''),
            number_format($returnQty, 2, '.', ''),
            number_format($qty, 2, '.', ''),
            $orders,
            number_format($cost + $deliveryCost, 2, '.', ''),
            number_format($discount, 2, '.', ''),
            number_format($deliveryCost, 2, '.', ''),
            number_format($cost - $discount + $deliveryCost, 2, '.', ''),
            isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : '',
        ];
    }

    $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['Product Detail List', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rowStyles[count($rows) - 1] = 'header';
    $detailHeader = ['No', 'Product Name', 'Code', 'Total Sold', 'Qty Return', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Total Amount', 'Last Sold', '', ''];
    $rows[] = $detailHeader;
    $rowStyles[count($rows) - 1] = 'header';
    foreach ($productDetailList as $index => $product) {
        $qty = (float)($product['total_quantity'] ?? 0);
        $returnQty = (float)($product['return_quantity'] ?? 0);
        $orders = (int)($product['order_count'] ?? 0);
        $cost = (float)($product['total_sales'] ?? 0);
        $discount = (float)($product['total_discount'] ?? 0);
        $rows[] = [
            $index + 1,
            (string)($product['product_name'] ?? ''),
            (string)($product['product_code'] ?? ''),
            number_format($qty + $returnQty, 2, '.', ''),
            number_format($returnQty, 2, '.', ''),
            number_format($qty, 2, '.', ''),
            $orders,
            number_format($cost, 2, '.', ''),
            number_format($discount, 2, '.', ''),
            number_format($cost - $discount, 2, '.', ''),
            isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : '',
            '',
            '',
        ];
    }

    $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['Detailed Orders', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rowStyles[count($rows) - 1] = 'header';
    $rows[] = ['No', 'Printed At', 'Order Code', 'Customer', 'Seller', 'Items', 'Products', 'Qty', 'Status', 'Discount', 'Amount', '', ''];
    $rowStyles[count($rows) - 1] = 'header';
    foreach ($detailedOrders as $index => $order) {
        $status = 'Paid';
        if (!empty($order['is_cancelled'])) {
            $status = 'Cancelled';
        } elseif (!empty($order['is_returned'])) {
            $status = 'Returned';
        } elseif (($order['status'] ?? '') !== 'paid') {
            $status = 'Unpaid';
        }
        $rows[] = [
            $index + 1,
            isset($order['printed_at']) ? date('Y-m-d H:i', strtotime((string)$order['printed_at'])) : '',
            (string)($order['order_code'] ?? ''),
            (string)($order['customer_name'] ?? ''),
            (string)($order['seller_name'] ?? 'N/A'),
            (int)($order['item_count'] ?? 0),
            (string)($order['products_summary'] ?? ''),
            number_format((float)($order['total_quantity'] ?? 0), 2, '.', ''),
            $status,
            number_format((float)($order['discount'] ?? 0), 2, '.', ''),
            number_format((float)($order['total_amount'] ?? 0), 2, '.', ''),
            '',
            '',
        ];
    }

    sprlCreateSimpleXlsxFile($filePath, $rows, [8, 26, 14, 18, 12, 12, 28, 10, 14, 14, 14, 14, 18], $rowStyles);
    return ['file_path' => $filePath, 'file_name' => $fileName];
}

function sprlBuildSoldProductsData(PDO $pdo, string $fromDate, string $toDate): array
{
    $stmt = $pdo->prepare(
        'SELECT oi.product_id, p.name AS product_name, CONCAT("PID-", p.id) AS product_code,
                MAX(COALESCE(p.product_type, \'normal\')) AS product_type,
                SUM(oi.quantity) AS total_quantity,
                SUM(CASE
                    WHEN COALESCE(order_totals.order_line_total, 0) > 0
                    THEN (oi.line_total / order_totals.order_line_total) * COALESCE(o.discount, 0)
                    ELSE 0 END) AS total_discount,
                SUM(CASE
                    WHEN COALESCE(order_totals.order_line_total, 0) > 0
                    THEN (oi.line_total / order_totals.order_line_total) * COALESCE(dc.amount, 0)
                    ELSE 0 END) AS total_delivery_cost,
                SUM(oi.line_total) AS total_sales,
                COUNT(DISTINCT oi.order_id) AS order_count,
                MAX(pj.printed_at) AS last_sold_at
         FROM order_items oi
         JOIN orders o ON oi.order_id = o.id
         JOIN products p ON oi.product_id = p.id
         JOIN print_jobs pj ON pj.order_id = o.id
         LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
         LEFT JOIN (
            SELECT order_id, SUM(line_total) AS order_line_total
            FROM order_items
            GROUP BY order_id
         ) order_totals ON order_totals.order_id = oi.order_id
         WHERE DATE(pj.printed_at) BETWEEN ? AND ?
           AND o.is_cancelled = 0
           AND o.is_returned = 0
         GROUP BY oi.product_id, p.name, p.id
         ORDER BY total_quantity DESC, total_sales DESC, p.name ASC'
    );
    $stmt->execute([$fromDate, $toDate]);
    $soldProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $detailStmt = $pdo->prepare(
        'SELECT oi.product_id, p.name AS product_name, CONCAT("PID-", p.id) AS product_code,
                MAX(COALESCE(p.product_type, \'normal\')) AS product_type,
                SUM(oi.quantity) AS total_quantity,
                SUM(CASE
                    WHEN COALESCE(order_totals.order_line_total, 0) > 0
                    THEN (oi.line_total / order_totals.order_line_total) * COALESCE(o.discount, 0)
                    ELSE 0 END) AS total_discount,
                SUM(oi.line_total) AS total_sales,
                COUNT(DISTINCT oi.order_id) AS order_count,
                MAX(pj.printed_at) AS last_sold_at
         FROM order_items oi
         JOIN orders o ON oi.order_id = o.id
         JOIN products p ON oi.product_id = p.id
         JOIN print_jobs pj ON pj.order_id = o.id
         LEFT JOIN (
            SELECT order_id, SUM(line_total) AS order_line_total
            FROM order_items
            GROUP BY order_id
         ) order_totals ON order_totals.order_id = oi.order_id
         WHERE DATE(pj.printed_at) BETWEEN ? AND ?
           AND o.is_cancelled = 0
           AND o.is_returned = 0
         GROUP BY oi.product_id, p.name, p.id
         ORDER BY total_quantity DESC, total_sales DESC, p.name ASC'
    );
    $detailStmt->execute([$fromDate, $toDate]);
    $productDetailList = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

    $ordersStmt = $pdo->prepare(
        'SELECT o.id, o.order_code, o.customer_name, o.total_amount, o.discount, o.status, o.payment_method,
                o.is_cancelled, o.is_returned, pj.printed_at, u.name AS seller_name,
                COUNT(oi.id) AS item_count, COALESCE(SUM(oi.quantity), 0) AS total_quantity
         FROM orders o
         LEFT JOIN print_jobs pj ON pj.order_id = o.id
         LEFT JOIN users u ON o.seller_id = u.id
         LEFT JOIN order_items oi ON o.id = oi.order_id
         WHERE DATE(pj.printed_at) BETWEEN ? AND ?
           AND pj.printed_at IS NOT NULL
         GROUP BY o.id, o.order_code, o.customer_name, o.total_amount, o.discount, o.status, o.is_cancelled, o.is_returned, pj.printed_at, u.name
         ORDER BY pj.printed_at DESC'
    );
    $ordersStmt->execute([$fromDate, $toDate]);
    $detailedOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    $orderIds = array_values(array_unique(array_filter(array_map(static fn($order) => (int)($order['id'] ?? 0), $detailedOrders))));
    $productsByOrder = [];
    if (!empty($orderIds)) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $productsStmt = $pdo->prepare(
            "SELECT
                oi.order_id,
                p.name AS product_name,
                oi.quantity,
                oi.line_total
             FROM order_items oi
             JOIN products p ON oi.product_id = p.id
             WHERE oi.order_id IN ($placeholders)
             ORDER BY oi.order_id ASC, oi.id ASC"
        );
        $productsStmt->execute($orderIds);
        foreach ($productsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orderId = (int)($row['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            if (!isset($productsByOrder[$orderId])) {
                $productsByOrder[$orderId] = [];
            }
            $productsByOrder[$orderId][] = sprintf(
                '%s (%sx, $%s)',
                (string)($row['product_name'] ?? ''),
                number_format((float)($row['quantity'] ?? 0), 0, '.', ''),
                number_format((float)($row['line_total'] ?? 0), 2, '.', '')
            );
        }
    }
    foreach ($detailedOrders as &$order) {
        $orderId = (int)($order['id'] ?? 0);
        $order['products_summary'] = implode("\n", $productsByOrder[$orderId] ?? []);
    }
    unset($order);

    return [$soldProducts, $productDetailList, $detailedOrders];
}

function sprlSendSoldProductsReport(PDO $pdo, string $fromDate, string $toDate): array
{
    $errors = [];
    $success = false;
    $combinedExport = null;

    try {
        [$soldProducts, $productDetailList, $detailedOrders] = sprlBuildSoldProductsData($pdo, $fromDate, $toDate);

        $combinedExport = sprlGenerateCombinedAllTablesExcel($soldProducts, $productDetailList, $detailedOrders, $fromDate, $toDate);

        $topProductName = 'N/A';
        $topProductSale = 0.0;
        if (!empty($soldProducts[0])) {
            $topProductName = (string)($soldProducts[0]['product_name'] ?? 'N/A');
            $topProductSale = ((float)($soldProducts[0]['total_sales'] ?? 0)) - ((float)($soldProducts[0]['total_discount'] ?? 0));
        }

        $orderCount = 0;
        $cancelCount = 0;
        $returnCount = 0;
        $totalPaid = 0.0;
        $totalUnpaid = 0.0;
        $totalAmount = 0.0;
        $sellerPaidTotals = [];
        $paymentMethodStats = [];

        foreach ($detailedOrders as $order) {
            $isCancelled = !empty($order['is_cancelled']);
            $isReturned = !empty($order['is_returned']);
            $amount = (float)($order['total_amount'] ?? 0);
            $sellerName = (string)($order['seller_name'] ?? 'N/A');
            $paymentMethod = trim((string)($order['payment_method'] ?? 'Unknown'));
            if ($paymentMethod === '') {
                $paymentMethod = 'Unknown';
            }

            if ($isCancelled) {
                $cancelCount++;
                continue;
            }
            if ($isReturned) {
                $returnCount++;
                continue;
            }

            $orderCount++;
            $totalAmount += $amount;
            if (!isset($paymentMethodStats[$paymentMethod])) {
                $paymentMethodStats[$paymentMethod] = ['count' => 0, 'amount' => 0.0];
            }
            $paymentMethodStats[$paymentMethod]['count']++;
            $paymentMethodStats[$paymentMethod]['amount'] += $amount;
            if (($order['status'] ?? '') === 'paid') {
                $totalPaid += $amount;
                if (!isset($sellerPaidTotals[$sellerName])) {
                    $sellerPaidTotals[$sellerName] = 0.0;
                }
                $sellerPaidTotals[$sellerName] += $amount;
            } else {
                $totalUnpaid += $amount;
            }
        }

        $topSellerName = 'N/A';
        if (!empty($sellerPaidTotals)) {
            arsort($sellerPaidTotals);
            $topSellerName = (string)array_key_first($sellerPaidTotals);
        }

        $paymentMethodLine = 'N/A';
        if (!empty($paymentMethodStats)) {
            uasort($paymentMethodStats, static function ($a, $b) {
                $amountA = (float)($a['amount'] ?? 0);
                $amountB = (float)($b['amount'] ?? 0);
                if ($amountA === $amountB) {
                    return ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0));
                }
                return $amountB <=> $amountA;
            });
            $parts = [];
            foreach ($paymentMethodStats as $method => $stats) {
                $parts[] = '- ' . $method . ' : ' . number_format((int)$stats['count']) . ' = $' . number_format((float)$stats['amount'], 2);
            }
            $paymentMethodLine = implode("\n", $parts);
        }

        $caption = "📊 <b>Daily Report Shadow Group</b>\n";
        $caption .= "📅 Date: {$fromDate}\n";
        $caption .= "🧾 Order: " . number_format($orderCount) . "\n";
        $caption .= "🏆 Top Product: {$topProductName}\n";
        $caption .= "🏆 Top Seller: {$topSellerName}\n";
        $caption .= "💳 Payment Method:\n{$paymentMethodLine}\n";
        $caption .= "❌ Cancel: " . number_format($cancelCount) . "\n";
        $caption .= "↩️ Return: " . number_format($returnCount) . "\n";
        $caption .= "💵 Total paid: $" . number_format($totalPaid, 2) . "\n";
        $caption .= "🧾 Total unpaid: $" . number_format($totalUnpaid, 2) . "\n";
        $caption .= "✅ Total: $" . number_format($totalAmount, 2);

        $results = sprlSendDocumentToTelegram($combinedExport['file_path'], $combinedExport['file_name'], $caption);
        $ok = array_filter($results, static fn($r) => !empty($r['success']));
        $fail = array_filter($results, static fn($r) => empty($r['success']));
        $success = !empty($ok);
        if (!empty($fail)) {
            $errors[] = 'Some Telegram sends failed: ' . implode('; ', array_map(static fn($r) => (string)($r['error'] ?? 'Unknown error'), $fail));
        }
    } catch (Throwable $e) {
        $errors[] = 'Failed to auto send sold products report: ' . $e->getMessage();
    } finally {
        if (!empty($combinedExport['file_path']) && file_exists($combinedExport['file_path'])) {
            @unlink($combinedExport['file_path']);
        }
    }

    return ['success' => $success, 'errors' => $errors];
}

