<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'sold_products.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

function soldProductsEscapeTelegramHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function soldProductsGetPendingAutoSend(PDO $pdo): ?array {
    try {
        $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
        $stmt->execute(['sold_products_pending_auto_send']);
        $raw = (string)$stmt->fetchColumn();
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['raw' => $raw];
    } catch (Throwable $e) {
        return null;
    }
}

function soldProductsClearPendingAutoSend(PDO $pdo): void {
    try {
        $stmt = $pdo->prepare('DELETE FROM app_settings WHERE `key` = ?');
        $stmt->execute(['sold_products_pending_auto_send']);
    } catch (Throwable $e) {
        // Ignore clear errors.
    }
}

function soldProductsGenerateProductListExcel(array $soldProducts, array $summary, $fromDate, $toDate) {
    $tempDir = sys_get_temp_dir();
    $safeFrom = preg_replace('/[^0-9\-]/', '', (string)$fromDate);
    $safeTo = preg_replace('/[^0-9\-]/', '', (string)$toDate);
    $fileName = 'daily_sold_product_list_' . ($safeFrom !== '' ? $safeFrom : date('Y-m-d')) . '_to_' . ($safeTo !== '' ? $safeTo : date('Y-m-d')) . '.xlsx';
    $filePath = $tempDir . DIRECTORY_SEPARATOR . uniqid('sold_products_list_', true) . '.xlsx';

    $rows = [];
    $rows[] = ['Daily Sold Product List Shadow', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['From Date', $fromDate, 'To Date', $toDate, '', '', '', '', '', '', '', '', ''];
    $rows[] = ['Products Sold', $summary['total_products'], 'Total Qty', number_format(((float)($summary['total_quantity'] ?? 0)) + ((float)($summary['total_return_quantity'] ?? 0)), 2, '.', ''), '', '', '', '', '', '', '', '', ''];
    $rows[] = ['Full Cost', number_format(((float)($summary['total_sales'] ?? 0)) + ((float)($summary['total_delivery_cost'] ?? 0)), 2, '.', ''), 'Discount', number_format($summary['total_discount'], 2, '.', ''), '', '', '', '', '', '', '', '', ''];
    $rows[] = ['Total Amount', number_format(((float)($summary['total_sold'] ?? 0)) + ((float)($summary['total_delivery_cost'] ?? 0)), 2, '.', ''), 'Orders', $summary['total_orders'], '', '', '', '', '', '', '', '', ''];
    $rows[] = ['No', 'Product Name', 'Brand', 'Code', 'Product type', 'Total Sold', 'Qty Return', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Delivery Cost', 'Total Amount', 'Last Sold'];

    foreach ($soldProducts as $index => $product) {
        $fullCost = (float)($product['total_sales'] ?? 0);
        $discount = (float)($product['total_discount'] ?? 0);
        $deliveryCost = (float)($product['total_delivery_cost'] ?? 0);
        $returnQty = (float)($product['return_quantity'] ?? 0);
        $netQty = (float)($product['total_quantity'] ?? 0);
        $typeLabel = product_type_display_label($product['product_type'] ?? 'normal');
        $rows[] = [
            $index + 1,
            $product['product_name'] ?? '',
            $product['brand_name'] ?? '',
            $product['product_code'] ?? '',
            $typeLabel,
            number_format($netQty + $returnQty, 2, '.', ''),
            number_format($returnQty, 2, '.', ''),
            number_format($netQty, 2, '.', ''),
            (int)($product['order_count'] ?? 0),
            number_format($fullCost + $deliveryCost, 2, '.', ''),
            number_format($discount, 2, '.', ''),
            number_format($deliveryCost, 2, '.', ''),
            number_format($fullCost - $discount + $deliveryCost, 2, '.', ''),
            isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : ''
        ];
    }

    $rows[] = ['', 'TOTAL', '', '', '', number_format(((float)($summary['total_quantity'] ?? 0)) + ((float)($summary['total_return_quantity'] ?? 0)), 2, '.', ''), number_format((float)($summary['total_return_quantity'] ?? 0), 2, '.', ''), number_format($summary['total_quantity'], 2, '.', ''), $summary['total_orders'], number_format(((float)($summary['total_sales'] ?? 0)) + ((float)($summary['total_delivery_cost'] ?? 0)), 2, '.', ''), number_format($summary['total_discount'], 2, '.', ''), number_format((float)($summary['total_delivery_cost'] ?? 0), 2, '.', ''), number_format(((float)($summary['total_sold'] ?? 0)) + ((float)($summary['total_delivery_cost'] ?? 0)), 2, '.', ''), ''];
    soldProductsCreateSimpleXlsxFile(
        $filePath,
        $rows,
        [8, 28, 16, 16, 14, 12, 12, 12, 10, 14, 14, 14, 14, 18],
        [
            0 => 'title',
            1 => 'meta',
            2 => 'meta',
            3 => 'meta',
            4 => 'meta',
            5 => 'header',
            count($rows) - 1 => 'total',
        ]
    );

    return [
        'file_path' => $filePath,
        'file_name' => $fileName,
    ];
}

function soldProductsGenerateDetailedOrdersExcel(array $detailedOrders, array $summary, $fromDate, $toDate) {
    $tempDir = sys_get_temp_dir();
    $safeFrom = preg_replace('/[^0-9\-]/', '', (string)$fromDate);
    $safeTo = preg_replace('/[^0-9\-]/', '', (string)$toDate);
    $fileName = 'detailed_orders_' . ($safeFrom !== '' ? $safeFrom : date('Y-m-d')) . '_to_' . ($safeTo !== '' ? $safeTo : date('Y-m-d')) . '.xlsx';
    $filePath = $tempDir . DIRECTORY_SEPARATOR . uniqid('sold_products_orders_', true) . '.xlsx';

    $rows = [];
    $rows[] = ['Detailed Orders', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['From Date', $fromDate, 'To Date', $toDate, '', '', '', '', '', '', ''];
    $rows[] = ['Orders', $summary['detailed_total_orders'], 'Cancel', $summary['cancelled_orders'], '', '', '', '', '', '', ''];
    $rows[] = ['Return', $summary['returned_orders'], 'Discount', number_format($summary['detailed_total_discount'], 2, '.', ''), '', '', '', '', '', '', ''];
    $rows[] = ['Total Paid', number_format($summary['detailed_paid_amount'], 2, '.', ''), 'Total Unpaid', number_format($summary['detailed_unpaid_amount'], 2, '.', ''), '', '', '', '', '', '', ''];
    $rows[] = ['Amount', number_format($summary['detailed_total_amount'], 2, '.', ''), 'Qty', number_format($summary['detailed_total_quantity'], 2, '.', ''), '', '', '', '', '', '', ''];
    $rows[] = ['No', 'Printed At', 'Order Code', 'Customer', 'Seller', 'Items', 'Products', 'Qty', 'Status', 'Discount', 'Amount'];

    foreach ($detailedOrders as $index => $order) {
        $statusLabel = 'Paid';
        if (!empty($order['is_cancelled'])) {
            $statusLabel = 'Cancelled';
        } elseif (!empty($order['is_returned'])) {
            $statusLabel = 'Returned';
        } elseif (($order['status'] ?? '') !== 'paid') {
            $statusLabel = 'Unpaid';
        }

        $rows[] = [
            $index + 1,
            isset($order['printed_at']) ? date('Y-m-d H:i', strtotime((string)$order['printed_at'])) : '',
            $order['order_code'] ?? '',
            $order['customer_name'] ?? '',
            $order['seller_name'] ?? 'N/A',
            (int)($order['item_count'] ?? 0),
            (string)($order['products_summary'] ?? ''),
            number_format((float)($order['total_quantity'] ?? 0), 2, '.', ''),
            $statusLabel,
            number_format((float)($order['discount'] ?? 0), 2, '.', ''),
            number_format((float)($order['total_amount'] ?? 0), 2, '.', '')
        ];
    }

    $rows[] = ['', '', '', '', 'TOTAL', $summary['detailed_total_items'], '', number_format($summary['detailed_total_quantity'], 2, '.', ''), $summary['detailed_total_orders'], number_format($summary['detailed_total_discount'], 2, '.', ''), number_format($summary['detailed_total_amount'], 2, '.', '')];
    soldProductsCreateSimpleXlsxFile(
        $filePath,
        $rows,
        [8, 18, 16, 22, 18, 10, 32, 10, 14, 14, 14],
        [
            0 => 'title',
            1 => 'meta',
            2 => 'meta',
            3 => 'meta',
            4 => 'meta',
            5 => 'meta',
            6 => 'header',
            count($rows) - 1 => 'total',
        ]
    );

    return [
        'file_path' => $filePath,
        'file_name' => $fileName,
    ];
}

function soldProductsGenerateProductDetailListExcel(array $productDetailList, array $summary, $fromDate, $toDate) {
    $tempDir = sys_get_temp_dir();
    $safeFrom = preg_replace('/[^0-9\-]/', '', (string)$fromDate);
    $safeTo = preg_replace('/[^0-9\-]/', '', (string)$toDate);
    $fileName = 'product_detail_list_' . ($safeFrom !== '' ? $safeFrom : date('Y-m-d')) . '_to_' . ($safeTo !== '' ? $safeTo : date('Y-m-d')) . '.xlsx';
    $filePath = $tempDir . DIRECTORY_SEPARATOR . uniqid('sold_products_detail_', true) . '.xlsx';

    $rows = [];
    $rows[] = ['Product Detail List', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['From Date', $fromDate, 'To Date', $toDate, '', '', '', '', '', '', '', ''];
    $rows[] = ['Products', $summary['detail_total_products'], 'Orders', $summary['detail_total_orders'], '', '', '', '', '', '', '', ''];
    $rows[] = ['Total Sold', number_format(((float)($summary['detail_total_quantity'] ?? 0)) + ((float)($summary['detail_total_return_quantity'] ?? 0)), 2, '.', ''), 'Qty Sold', number_format($summary['detail_total_quantity'], 2, '.', ''), 'Qty Return', number_format((float)($summary['detail_total_return_quantity'] ?? 0), 2, '.', ''), '', '', '', '', '', ''];
    $rows[] = ['No', 'Product Name', 'Brand', 'Code', 'Total Sold', 'Qty Return', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Total Amount', 'Last Sold'];

    foreach ($productDetailList as $index => $product) {
        $netQty = (float)($product['total_quantity'] ?? 0);
        $returnQty = (float)($product['return_quantity'] ?? 0);
        $fullCost = (float)($product['total_sales'] ?? 0);
        $discount = (float)($product['total_discount'] ?? 0);
        $rows[] = [
            $index + 1,
            $product['product_name'] ?? '',
            $product['brand_name'] ?? '',
            $product['product_code'] ?? '',
            number_format($netQty + $returnQty, 2, '.', ''),
            number_format($returnQty, 2, '.', ''),
            number_format($netQty, 2, '.', ''),
            (int)($product['order_count'] ?? 0),
            number_format($fullCost, 2, '.', ''),
            number_format($discount, 2, '.', ''),
            number_format($fullCost - $discount, 2, '.', ''),
            isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : ''
        ];
    }

    $rows[] = ['', 'TOTAL', '', '', number_format(((float)($summary['detail_total_quantity'] ?? 0)) + ((float)($summary['detail_total_return_quantity'] ?? 0)), 2, '.', ''), number_format((float)($summary['detail_total_return_quantity'] ?? 0), 2, '.', ''), number_format($summary['detail_total_quantity'], 2, '.', ''), $summary['detail_total_orders'], number_format($summary['detail_total_sales'], 2, '.', ''), number_format($summary['detail_total_discount'], 2, '.', ''), number_format($summary['detail_total_sold'], 2, '.', ''), ''];

    soldProductsCreateSimpleXlsxFile(
        $filePath,
        $rows,
        [8, 28, 18, 14, 12, 12, 12, 10, 14, 14, 14, 18],
        [
            0 => 'title',
            1 => 'meta',
            2 => 'meta',
            3 => 'meta',
            4 => 'header',
            count($rows) - 1 => 'total',
        ]
    );

    return [
        'file_path' => $filePath,
        'file_name' => $fileName,
    ];
}

function soldProductsGenerateCombinedAllTablesExcel(
    array $soldProducts,
    array $productDetailList,
    array $detailedOrders,
    array $summary,
    string $fromDate,
    string $toDate
): array {
    $tempDir = sys_get_temp_dir();
    $safeFrom = preg_replace('/[^0-9\-]/', '', $fromDate);
    $safeTo = preg_replace('/[^0-9\-]/', '', $toDate);
    $fileName = 'sold_products_all_tables_' . ($safeFrom !== '' ? $safeFrom : date('Y-m-d')) . '_to_' . ($safeTo !== '' ? $safeTo : date('Y-m-d')) . '.xlsx';
    $filePath = $tempDir . DIRECTORY_SEPARATOR . uniqid('sold_products_all_', true) . '.xlsx';

    $rows = [];
    $rowStyles = [];

    $rows[] = ['Top Selling Products - Combined Report', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rowStyles[count($rows) - 1] = 'title';
    $rows[] = ['From Date', $fromDate, 'To Date', $toDate, '', '', '', '', '', '', '', '', ''];
    $rowStyles[count($rows) - 1] = 'meta';
    $rows[] = ['Products Sold', $summary['total_products'], 'Total Qty', number_format(((float)($summary['total_quantity'] ?? 0)) + ((float)($summary['total_return_quantity'] ?? 0)), 2, '.', ''), 'Total Amount', number_format(((float)($summary['total_sold'] ?? 0)) + ((float)($summary['total_delivery_cost'] ?? 0)), 2, '.', ''), '', '', '', '', '', '', ''];
    $rowStyles[count($rows) - 1] = 'meta';
    $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', ''];

    $rows[] = ['Daily Sold Product List', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rowStyles[count($rows) - 1] = 'section';
    $rows[] = ['No', 'Product Name', 'Brand', 'Code', 'Type', 'Total Sold', 'Qty Return', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Delivery Cost', 'Total Amount', 'Last Sold'];
    $rowStyles[count($rows) - 1] = 'header';
    $dailyTotalGrossQty = 0.0;
    $dailyTotalNetQty = 0.0;
    $dailyTotalReturnQty = 0.0;
    $dailyTotalOrders = 0;
    $dailyTotalCost = 0.0;
    $dailyTotalDiscount = 0.0;
    $dailyTotalDelivery = 0.0;
    $dailyTotalNet = 0.0;
    foreach ($soldProducts as $index => $product) {
        $qty = (float)($product['total_quantity'] ?? 0);
        $returnQty = (float)($product['return_quantity'] ?? 0);
        $orders = (int)($product['order_count'] ?? 0);
        $cost = (float)($product['total_sales'] ?? 0);
        $discount = (float)($product['total_discount'] ?? 0);
        $delivery = (float)($product['total_delivery_cost'] ?? 0);
        $net = $cost - $discount + $delivery;
        $rows[] = [
            $index + 1,
            $product['product_name'] ?? '',
            $product['brand_name'] ?? '',
            $product['product_code'] ?? '',
            product_type_display_label($product['product_type'] ?? 'normal'),
            number_format($qty + $returnQty, 2, '.', ''),
            number_format($returnQty, 2, '.', ''),
            number_format($qty, 2, '.', ''),
            $orders,
            number_format($cost + $delivery, 2, '.', ''),
            number_format($discount, 2, '.', ''),
            number_format($delivery, 2, '.', ''),
            number_format($net, 2, '.', ''),
            isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : '',
        ];
        $dailyTotalGrossQty += $qty + $returnQty;
        $dailyTotalNetQty += $qty;
        $dailyTotalReturnQty += $returnQty;
        $dailyTotalOrders += $orders;
        $dailyTotalCost += $cost + $delivery;
        $dailyTotalDiscount += $discount;
        $dailyTotalDelivery += $delivery;
        $dailyTotalNet += $net;
    }
    $rows[] = ['', 'TOTAL', '', '', '', number_format($dailyTotalGrossQty, 2, '.', ''), number_format($dailyTotalReturnQty, 2, '.', ''), number_format($dailyTotalNetQty, 2, '.', ''), $dailyTotalOrders, number_format($dailyTotalCost, 2, '.', ''), number_format($dailyTotalDiscount, 2, '.', ''), number_format($dailyTotalDelivery, 2, '.', ''), number_format($dailyTotalNet, 2, '.', ''), ''];
    $rowStyles[count($rows) - 1] = 'total';

    $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['Product Detail List', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rowStyles[count($rows) - 1] = 'section';
    $rows[] = ['No', 'Product Name', 'Brand', 'Code', 'Total Sold', 'Qty Return', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Total Amount', 'Last Sold', ''];
    $rowStyles[count($rows) - 1] = 'header';
    $detailTotalGrossQty = 0.0;
    $detailTotalNetQty = 0.0;
    $detailTotalReturnQty = 0.0;
    $detailTotalOrders = 0;
    $detailTotalCost = 0.0;
    $detailTotalDiscount = 0.0;
    $detailTotalNet = 0.0;
    foreach ($productDetailList as $index => $product) {
        $qty = (float)($product['total_quantity'] ?? 0);
        $returnQty = (float)($product['return_quantity'] ?? 0);
        $orders = (int)($product['order_count'] ?? 0);
        $cost = (float)($product['total_sales'] ?? 0);
        $discount = (float)($product['total_discount'] ?? 0);
        $net = $cost - $discount;
        $rows[] = [
            $index + 1,
            $product['product_name'] ?? '',
            $product['brand_name'] ?? '',
            $product['product_code'] ?? '',
            number_format($qty + $returnQty, 2, '.', ''),
            number_format($returnQty, 2, '.', ''),
            number_format($qty, 2, '.', ''),
            $orders,
            number_format($cost, 2, '.', ''),
            number_format($discount, 2, '.', ''),
            number_format($net, 2, '.', ''),
            isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : '',
            '',
        ];
        $detailTotalGrossQty += $qty + $returnQty;
        $detailTotalNetQty += $qty;
        $detailTotalReturnQty += $returnQty;
        $detailTotalOrders += $orders;
        $detailTotalCost += $cost;
        $detailTotalDiscount += $discount;
        $detailTotalNet += $net;
    }
    $rows[] = ['', 'TOTAL', '', '', number_format($detailTotalGrossQty, 2, '.', ''), number_format($detailTotalReturnQty, 2, '.', ''), number_format($detailTotalNetQty, 2, '.', ''), $detailTotalOrders, number_format($detailTotalCost, 2, '.', ''), number_format($detailTotalDiscount, 2, '.', ''), number_format($detailTotalNet, 2, '.', ''), '', ''];
    $rowStyles[count($rows) - 1] = 'total';

    $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['Detailed Orders', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rowStyles[count($rows) - 1] = 'section';
    $rows[] = ['No', 'Printed At', 'Order Code', 'Customer', 'Seller', 'Items', 'Products', 'Qty', 'Status', 'Discount', 'Amount', '', ''];
    $rowStyles[count($rows) - 1] = 'header';
    $ordersTotalItems = 0;
    $ordersTotalQty = 0.0;
    $ordersTotalCount = 0;
    $ordersTotalDiscount = 0.0;
    $ordersTotalAmount = 0.0;
    foreach ($detailedOrders as $index => $order) {
        $statusLabel = 'Paid';
        if (!empty($order['is_cancelled'])) {
            $statusLabel = 'Cancelled';
        } elseif (!empty($order['is_returned'])) {
            $statusLabel = 'Returned';
        } elseif (($order['status'] ?? '') !== 'paid') {
            $statusLabel = 'Unpaid';
        }
        $items = (int)($order['item_count'] ?? 0);
        $qty = (float)($order['total_quantity'] ?? 0);
        $discount = (float)($order['discount'] ?? 0);
        $amount = (float)($order['total_amount'] ?? 0);
        $rows[] = [
            $index + 1,
            isset($order['printed_at']) ? date('Y-m-d H:i', strtotime((string)$order['printed_at'])) : '',
            $order['order_code'] ?? '',
            $order['customer_name'] ?? '',
            $order['seller_name'] ?? 'N/A',
            $items,
            (string)($order['products_summary'] ?? ''),
            number_format($qty, 2, '.', ''),
            $statusLabel,
            number_format($discount, 2, '.', ''),
            number_format($amount, 2, '.', ''),
            '',
            '',
        ];
        $ordersTotalItems += $items;
        $ordersTotalQty += $qty;
        $ordersTotalCount++;
        $ordersTotalDiscount += $discount;
        $ordersTotalAmount += $amount;
    }
    $rows[] = ['', '', '', '', 'TOTAL', $ordersTotalItems, '', number_format($ordersTotalQty, 2, '.', ''), $ordersTotalCount, number_format($ordersTotalDiscount, 2, '.', ''), number_format($ordersTotalAmount, 2, '.', ''), '', ''];
    $rowStyles[count($rows) - 1] = 'total';

    soldProductsCreateSimpleXlsxFile($filePath, $rows, [7, 26, 14, 18, 12, 12, 28, 10, 14, 14, 14, 14, 18], $rowStyles);
    return ['file_path' => $filePath, 'file_name' => $fileName];
}

function soldProductsGenerateCombinedAllTablesPdf(
    array $soldProducts,
    array $productDetailList,
    array $detailedOrders,
    string $fromDate,
    string $toDate
): array {
    $safeFrom = preg_replace('/[^0-9\-]/', '', $fromDate);
    $safeTo = preg_replace('/[^0-9\-]/', '', $toDate);
    $fileName = 'sold_products_all_tables_' . ($safeFrom !== '' ? $safeFrom : date('Y-m-d')) . '_to_' . ($safeTo !== '' ? $safeTo : date('Y-m-d')) . '.pdf';
    $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sold_products_all_pdf_', true) . '.pdf';

    $html = soldProductsBuildPdfHtmlHeader('Top Selling Products - Combined Report', $fromDate, $toDate);
    $html .= soldProductsBuildCombinedAllTablesHtmlBody($soldProducts, $productDetailList, $detailedOrders);
    $html .= '</body></html>';

    soldProductsRenderHtmlToPdf($html, $filePath);
    return ['file_path' => $filePath, 'file_name' => $fileName];
}

/** Full HTML document for browser print — same flow as admin/finance_print_report.php (Save as PDF tip, auto-print, new tab). */
function soldProductsBuildCombinedPrintPageHtml(
    array $soldProducts,
    array $productDetailList,
    array $detailedOrders,
    string $fromDate,
    string $toDate
): string {
    $printCss = '@page{size:A4;margin:15mm;}'
        . '.no-print{margin-top:30px;text-align:center;}'
        . '.no-print .print-actions-btn{padding:10px 20px;font-size:14px;margin-bottom:10px;cursor:pointer;border:1px solid #ccc;border-radius:4px;background:#f8f9fa;}'
        . '.no-print .print-close-btn{padding:8px 16px;font-size:12px;margin-top:8px;cursor:pointer;border:1px solid #ccc;border-radius:4px;background:#fff;}'
        . '.no-print .print-tip{color:#666;font-size:12px;display:block;margin:8px 0 16px;}'
        . '@media print{.no-print{display:none!important}body{margin:0;padding:12px;}}';
    $html = soldProductsBuildPdfHtmlHeader('Top Selling Products - Combined Report', $fromDate, $toDate, $printCss);
    $html .= '<div class="no-print">'
        . '<button type="button" class="print-actions-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>'
        . '<br><small class="print-tip">💡 Tip: Choose &quot;Save as PDF&quot; in the print dialog to save as a PDF file</small>'
        . '<br><button type="button" class="print-close-btn" onclick="window.close()">❌ Close Window</button>'
        . '</div>';
    $html .= soldProductsBuildCombinedAllTablesHtmlBody($soldProducts, $productDetailList, $detailedOrders);
    $html .= '<script>'
        . 'window.addEventListener("load",function(){setTimeout(function(){window.print();},500);});'
        . 'window.addEventListener("afterprint",function(){setTimeout(function(){},1000);});'
        . 'document.addEventListener("keydown",function(e){if((e.ctrlKey||e.metaKey)&&e.key==="s"){e.preventDefault();window.print();}});'
        . '</script>';
    $html .= '</body></html>';

    return $html;
}

function soldProductsGetTelegramConfig() {
    global $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID, $TELEGRAM_TARGETS;
    global $SOLD_PRODUCTS_TELEGRAM_BOT_TOKEN, $SOLD_PRODUCTS_TELEGRAM_CHAT_ID, $SOLD_PRODUCTS_TELEGRAM_TARGETS;

    $botToken = $TELEGRAM_BOT_TOKEN ?? '';
    $chatId = $TELEGRAM_CHAT_ID ?? '';
    $targets = (isset($TELEGRAM_TARGETS) && is_array($TELEGRAM_TARGETS)) ? $TELEGRAM_TARGETS : [];

    $soldProductsBotToken = $SOLD_PRODUCTS_TELEGRAM_BOT_TOKEN ?? '';
    $soldProductsChatId = $SOLD_PRODUCTS_TELEGRAM_CHAT_ID ?? '';
    $soldProductsTargets = (isset($SOLD_PRODUCTS_TELEGRAM_TARGETS) && is_array($SOLD_PRODUCTS_TELEGRAM_TARGETS)) ? $SOLD_PRODUCTS_TELEGRAM_TARGETS : [];

    if ($soldProductsBotToken !== '') {
        $botToken = $soldProductsBotToken;
    }

    if (!empty($soldProductsTargets)) {
        $targets = $soldProductsTargets;
    } elseif ($soldProductsChatId !== '') {
        $targets = [['chat_id' => $soldProductsChatId, 'thread_id' => null]];
    }

    if (empty($targets) && !empty($chatId)) {
        $targets = [['chat_id' => $chatId, 'thread_id' => null]];
    }

    return [
        'bot_token' => $botToken,
        'targets' => $targets,
    ];
}

function soldProductsSendDocumentToTelegram($filePath, $fileName, $caption = '') {
    $telegramConfig = soldProductsGetTelegramConfig();
    $botToken = $telegramConfig['bot_token'];
    $targets = $telegramConfig['targets'];
    $results = [];

    if ($botToken === '' || empty($targets)) {
        return [[
            'chat_id' => null,
            'thread_id' => null,
            'success' => false,
            'error' => 'Telegram is not configured'
        ]];
    }

    if (!file_exists($filePath)) {
        return [[
            'chat_id' => null,
            'thread_id' => null,
            'success' => false,
            'error' => 'Export file not found'
        ]];
    }

    if (!function_exists('curl_init')) {
        return [[
            'chat_id' => null,
            'thread_id' => null,
            'success' => false,
            'error' => 'cURL extension is not available'
        ]];
    }

    foreach ($targets as $target) {
        $chat_id = trim((string)($target['chat_id'] ?? ''));
        $thread_id = $target['thread_id'] ?? null;
        $url = "https://api.telegram.org/bot{$botToken}/sendDocument";

        $params = [
            'chat_id' => $chat_id,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'document' => new CURLFile($filePath, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $fileName)
        ];

        if ($thread_id) {
            $params['message_thread_id'] = $thread_id;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response !== false) {
            $decoded = json_decode($response, true);
            $results[] = [
                'chat_id' => $chat_id,
                'thread_id' => $thread_id,
                'success' => $decoded['ok'] ?? false,
                'response' => $decoded,
                'http_code' => $httpCode,
            ];
        } else {
            $results[] = [
                'chat_id' => $chat_id,
                'thread_id' => $thread_id,
                'success' => false,
                'error' => $curlError !== '' ? $curlError : 'Failed to send document',
                'http_code' => $httpCode,
            ];
        }
    }

    return $results;
}

function soldProductsBuildSummary(array $summaryInput): array {
    return [
        'total_products' => $summaryInput['total_products'] ?? 0,
        'total_quantity' => $summaryInput['total_quantity'] ?? 0,
        'total_return_quantity' => $summaryInput['total_return_quantity'] ?? 0,
        'total_discount' => $summaryInput['total_discount'] ?? 0,
        'total_sales' => $summaryInput['total_sales'] ?? 0,
        'total_delivery_cost' => $summaryInput['total_delivery_cost'] ?? 0,
        'total_sold' => $summaryInput['total_sold'] ?? 0,
        'total_orders' => $summaryInput['total_orders'] ?? 0,
        'detailed_total_orders' => $summaryInput['detailed_total_orders'] ?? 0,
        'detailed_total_items' => $summaryInput['detailed_total_items'] ?? 0,
        'detailed_total_quantity' => $summaryInput['detailed_total_quantity'] ?? 0,
        'detail_total_return_quantity' => $summaryInput['detail_total_return_quantity'] ?? 0,
        'detailed_total_discount' => $summaryInput['detailed_total_discount'] ?? 0,
        'detailed_total_amount' => $summaryInput['detailed_total_amount'] ?? 0,
        'detailed_paid_amount' => $summaryInput['detailed_paid_amount'] ?? 0,
        'detailed_unpaid_amount' => $summaryInput['detailed_unpaid_amount'] ?? 0,
        'cancelled_orders' => $summaryInput['cancelled_orders'] ?? 0,
        'returned_orders' => $summaryInput['returned_orders'] ?? 0,
    ];
}

function soldProductsDosDateTime(): array
{
    $dt = getdate();
    $year = max(1980, (int)$dt['year']);
    $dosTime = (($dt['hours'] & 0x1F) << 11) | (($dt['minutes'] & 0x3F) << 5) | ((int)floor($dt['seconds'] / 2) & 0x1F);
    $dosDate = ((($year - 1980) & 0x7F) << 9) | (($dt['mon'] & 0x0F) << 5) | ($dt['mday'] & 0x1F);
    return [$dosDate, $dosTime];
}

function soldProductsUInt32($value): int
{
    return (int)sprintf('%u', $value);
}

function soldProductsCreateZipWithoutExtension(string $filePath, array $entries): void
{
    if (!function_exists('gzdeflate')) {
        throw new RuntimeException('Cannot create XLSX: zlib extension is missing');
    }

    [$dosDate, $dosTime] = soldProductsDosDateTime();
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
        $crc = soldProductsUInt32(crc32($data));

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

function soldProductsSendTelegramReports(array $soldProducts, array $detailedOrders, array $summary, string $fromDate, string $toDate): array {
    $errors = [];
    $success = false;
    $productListExport = null;
    $detailedOrdersExport = null;

    try {
        $productListExport = soldProductsGenerateProductListExcel($soldProducts, $summary, $fromDate, $toDate);
        $detailedOrdersExport = soldProductsGenerateDetailedOrdersExcel($detailedOrders, $summary, $fromDate, $toDate);

        $productCaption = "📊 <b>Daily Sold Product List</b>\n";
        $productCaption .= "📅 From: " . soldProductsEscapeTelegramHtml($fromDate) . "\n";
        $productCaption .= "📅 To: " . soldProductsEscapeTelegramHtml($toDate) . "\n";
        $productCaption .= "📦 Products Sold: " . number_format((float)$summary['total_products']) . "\n";
        $productCaption .= "🧾 Orders: " . number_format((float)$summary['total_orders']) . "\n";
        $productCaption .= "💰 Full Cost: $" . number_format((float)$summary['total_sales'], 2) . "\n";
        $productCaption .= "🏷️ Discount: $" . number_format((float)$summary['total_discount'], 2) . "\n";
        $productCaption .= "✅ Total Sold: $" . number_format((float)$summary['total_sold'], 2);

        $detailsCaption = "🧾 <b>Detailed Orders</b>\n";
        $detailsCaption .= "📅 From: " . soldProductsEscapeTelegramHtml($fromDate) . "\n";
        $detailsCaption .= "📅 To: " . soldProductsEscapeTelegramHtml($toDate) . "\n";
        $detailsCaption .= "✅ Orders: " . number_format((float)$summary['detailed_total_orders']) . "\n";
        $detailsCaption .= "❌ Cancel: " . number_format((float)$summary['cancelled_orders']) . "\n";
        $detailsCaption .= "↩️ Return: " . number_format((float)$summary['returned_orders']) . "\n";
        $detailsCaption .= "💵 Total Paid: $" . number_format((float)$summary['detailed_paid_amount'], 2) . "\n";
        $detailsCaption .= "🧾 Total Unpaid: $" . number_format((float)$summary['detailed_unpaid_amount'], 2) . "\n";
        $detailsCaption .= "📊 Total: $" . number_format((float)$summary['detailed_total_amount'], 2);

        $results = [];
        $results = array_merge($results, soldProductsSendDocumentToTelegram($productListExport['file_path'], $productListExport['file_name'], $productCaption));
        $results = array_merge($results, soldProductsSendDocumentToTelegram($detailedOrdersExport['file_path'], $detailedOrdersExport['file_name'], $detailsCaption));

        $successful = array_filter($results, static fn($result) => !empty($result['success']));
        $failed = array_filter($results, static fn($result) => empty($result['success']));
        $success = !empty($successful);

        if (!empty($failed)) {
            $errors[] = 'Some Telegram sends failed: ' . implode('; ', array_map(static function ($result) {
                return (string)($result['error'] ?? 'Unknown Telegram error');
            }, $failed));
        }
    } catch (Throwable $e) {
        $errors[] = 'Failed to generate Telegram Excel report: ' . $e->getMessage();
    } finally {
        if (!empty($productListExport['file_path']) && file_exists($productListExport['file_path'])) {
            @unlink($productListExport['file_path']);
        }
        if (!empty($detailedOrdersExport['file_path']) && file_exists($detailedOrdersExport['file_path'])) {
            @unlink($detailedOrdersExport['file_path']);
        }
    }

    return [
        'success' => $success,
        'errors' => $errors,
    ];
}

function soldProductsCreateSimpleXlsxFile($filePath, array $rows, array $columnWidths = [], array $rowStyles = []) {
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
        . '<fonts count="5">'
        . '<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><sz val="11"/><color rgb="FF111827"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="6">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0F2FE"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right><top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="6">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $colsXml = '';
    if (!empty($columnWidths)) {
        foreach (array_values($columnWidths) as $index => $width) {
            $columnNumber = $index + 1;
            $colsXml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="' . soldProductsXml($width) . '" customWidth="1"/>';
        }
        $colsXml = '<cols>' . $colsXml . '</cols>';
    }

    $sheetData = '';
    foreach ($rows as $rowIndex => $row) {
        $sheetData .= '<row r="' . ($rowIndex + 1) . '">';
        foreach (array_values($row) as $columnIndex => $value) {
            $cellRef = soldProductsColumnLetter($columnIndex + 1) . ($rowIndex + 1);
            $styleKey = $rowStyles[$rowIndex] ?? 'default';
            $styleIndex = soldProductsGetStyleIndex($styleKey);
            $sheetData .= '<c r="' . $cellRef . '" t="inlineStr" s="' . $styleIndex . '"><is><t>'
                . soldProductsXml($value)
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

    soldProductsCreateZipWithoutExtension($filePath, $entries);
}

function soldProductsXml($value) {
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function soldProductsGetStyleIndex($styleKey) {
    $map = [
        'default' => 4,
        'title' => 1,
        'meta' => 2,
        'header' => 3,
        'section' => 5,
        'total' => 2,
    ];

    return $map[$styleKey] ?? 4;
}

function soldProductsColumnLetter($columnNumber) {
    $letter = '';
    while ($columnNumber > 0) {
        $mod = ($columnNumber - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $columnNumber = (int)(($columnNumber - $mod) / 26);
    }
    return $letter;
}

function soldProductsResolveChromeBinary(): ?string
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

function soldProductsBuildPdfHtmlHeader(string $title, string $fromDate, string $toDate, string $extraStyle = ''): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'body{font-family:"Khmer OS Siemreap","Khmer OS Battambang","Khmer OS Content","Khmer OS System","MoolBoran","DaunPenh","Khmer UI","Noto Sans Khmer","Leelawadee UI","Segoe UI",Arial,sans-serif;font-size:12px;color:#0f172a;padding:16px;background:#fff;}'
        . 'h1{font-size:22px;font-weight:700;color:#0f172a;margin:0 0 6px;letter-spacing:-0.02em;}'
        . 'p.meta{font-size:13px;color:#64748b;margin:0 0 20px;padding-bottom:12px;border-bottom:2px solid #e2e8f0;}'
        . '.section-title{font-size:15px;font-weight:700;color:#1e40af;margin:22px 0 10px;padding:8px 12px;border-left:4px solid #2563eb;background:linear-gradient(90deg,rgba(37,99,235,0.1) 0%,rgba(255,255,255,0) 72%);}'
        . 'table.table-report{width:100%;border-collapse:collapse;margin-bottom:20px;table-layout:fixed;border:2px solid #334155;}'
        . 'table.table-report thead th{background:linear-gradient(180deg,#2563eb 0%,#1d4ed8 100%);color:#fff;font-weight:600;border:1px solid #1e3a8a;padding:10px 8px;text-align:left;vertical-align:middle;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        . 'table.table-report thead th.th-num{text-align:right;}'
        . 'table.table-report tbody td{border:1px solid #64748b;padding:8px;vertical-align:top;word-break:break-word;}'
        . 'table.table-report tbody tr:nth-child(even){background:#f1f5f9;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        . 'table.table-report tbody tr:nth-child(odd){background:#fff;}'
        . 'table.table-report tbody tr.table-totals{background:#d1fae5;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        . 'table.table-report tbody td.td-num{text-align:right;font-variant-numeric:tabular-nums;}'
        . 'table.table-report tbody tr.table-totals td{background:#d1fae5;border:1px solid #047857;color:#064e3b;font-weight:700;padding:10px 8px;border-top:2px solid #047857;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        . 'table.table-report tbody tr.table-totals td.td-num{text-align:right;font-variant-numeric:tabular-nums;}'
        . 'table.table-report tbody tr.table-totals td.td-total-cell{font-weight:500;color:#64748b;}'
        . 'table.table-report tbody tr.table-totals td.td-total-key{font-weight:700;color:#064e3b;text-align:left;}'
        . 'table.table-report tbody tr.table-totals td.td-total-dash{text-align:center;color:#047857;font-weight:600;}'
        . 'table.table-report.table-compact tbody tr.table-totals td{font-size:10px;padding:6px 5px;}'
        . '.report-table-note{margin:6px 0 22px;padding:8px 10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:0 0 6px 6px;color:#065f46;font-size:11px;line-height:1.4;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        . 'table.table-compact thead th{font-size:10px;padding:7px 5px;}'
        . 'table.table-compact tbody td{font-size:10px;padding:6px 5px;}'
        . '.table-detailed col.no{width:5%;}.table-detailed col.printed{width:12%;}.table-detailed col.code{width:15%;}'
        . '.table-detailed col.customer{width:14%;}.table-detailed col.seller{width:9%;}.table-detailed col.items{width:5%;}'
        . '.table-detailed col.products{width:22%;}.table-detailed col.qty{width:7%;}.table-detailed col.status{width:8%;}.table-detailed col.discount{width:6%;}.table-detailed col.amount{width:6%;}'
        . '.page-break{page-break-before:always;}'
        . '.text-right{text-align:right;}'
        . '@media print{body{padding:10px;}table.table-report{box-shadow:none;}}'
        . $extraStyle
        . '</style></head><body>'
        . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p class="meta">From: ' . htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') . ' &nbsp;&nbsp; To: ' . htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') . '</p>';
}

/** Sum numeric columns for Daily Sold / Product Detail style rows. */
function soldProductsAggregateProductTableTotals(array $products): array
{
    $qty = 0.0;
    $orders = 0;
    $cost = 0.0;
    $discount = 0.0;
    $total = 0.0;
    foreach ($products as $p) {
        $qty += (float)($p['total_quantity'] ?? 0);
        $orders += (int)($p['order_count'] ?? 0);
        $ts = (float)($p['total_sales'] ?? 0);
        $td = (float)($p['total_discount'] ?? 0);
        $cost += $ts;
        $discount += $td;
        $total += $ts - $td;
    }

    return [$qty, $orders, $cost, $discount, $total];
}

/** Totals for the newer export layout with gross sold, sold, returned, delivery and amount columns. */
function soldProductsAggregateProductExportTotals(array $products, bool $includeDelivery): array
{
    $grossQty = 0.0;
    $netQty = 0.0;
    $returnQty = 0.0;
    $orders = 0;
    $fullCost = 0.0;
    $discount = 0.0;
    $delivery = 0.0;
    $amount = 0.0;

    foreach ($products as $product) {
        $productNetQty = (float)($product['total_quantity'] ?? 0);
        $productReturnQty = (float)($product['return_quantity'] ?? 0);
        $productSales = (float)($product['total_sales'] ?? 0);
        $productDiscount = (float)($product['total_discount'] ?? 0);
        $productDelivery = $includeDelivery ? (float)($product['total_delivery_cost'] ?? 0) : 0.0;

        $grossQty += $productNetQty + $productReturnQty;
        $netQty += $productNetQty;
        $returnQty += $productReturnQty;
        $orders += (int)($product['order_count'] ?? 0);
        $fullCost += $productSales + $productDelivery;
        $discount += $productDiscount;
        $delivery += $productDelivery;
        $amount += $productSales - $productDiscount + $productDelivery;
    }

    return [
        'gross_qty' => $grossQty,
        'net_qty' => $netQty,
        'return_qty' => $returnQty,
        'orders' => $orders,
        'full_cost' => $fullCost,
        'discount' => $discount,
        'delivery' => $delivery,
        'amount' => $amount,
    ];
}

/** Totals for Detailed Orders table (same rule as dashboard: exclude cancelled & returned). */
function soldProductsAggregateDetailedOrdersTotals(array $orders): array
{
    $items = 0;
    $qty = 0.0;
    $discount = 0.0;
    $amount = 0.0;
    foreach ($orders as $o) {
        if (!empty($o['is_cancelled']) || !empty($o['is_returned'])) {
            continue;
        }
        $items += (int)($o['item_count'] ?? 0);
        $qty += (float)($o['total_quantity'] ?? 0);
        $discount += (float)($o['discount'] ?? 0);
        $amount += (float)($o['total_amount'] ?? 0);
    }

    return [$items, $qty, $discount, $amount];
}

/** Tables only (shared by PDF and browser print). */
function soldProductsBuildCombinedAllTablesHtmlBody(
    array $soldProducts,
    array $productDetailList,
    array $detailedOrders
): string {
    $dailyNumericCols = [4, 5, 6, 7, 8, 9, 10, 11];
    $detailNumericCols = [4, 5, 6, 7, 8, 9, 10];

    $t1 = soldProductsAggregateProductExportTotals($soldProducts, true);
    $totalsRowDaily = '<tr class="table-totals">'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell td-total-key">TOTAL</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t1['gross_qty'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t1['net_qty'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t1['return_qty'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t1['orders'], 0, '.', ''), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t1['full_cost'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t1['discount'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t1['delivery'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t1['amount'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-total-dash">&#8212;</td>'
        . '</tr>';

    $html = soldProductsBuildPdfTable(
        'Daily Sold Product List',
        ['No', 'Product Name', 'Code', 'Type', 'Total Sold', 'Qty Return', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Delivery Cost', 'Total Amount', 'Last Sold'],
        array_map(static function ($product, $index) {
            $netQty = (float)($product['total_quantity'] ?? 0);
            $returnQty = (float)($product['return_quantity'] ?? 0);
            $sales = (float)($product['total_sales'] ?? 0);
            $discount = (float)($product['total_discount'] ?? 0);
            $delivery = (float)($product['total_delivery_cost'] ?? 0);
            return [
                (string)($index + 1),
                (string)($product['product_name'] ?? ''),
                (string)($product['product_code'] ?? ''),
                product_type_display_label($product['product_type'] ?? 'normal'),
                number_format($netQty + $returnQty, 2),
                number_format($returnQty, 2),
                number_format($netQty, 2),
                number_format((int)($product['order_count'] ?? 0)),
                number_format($sales + $delivery, 2),
                number_format($discount, 2),
                number_format($delivery, 2),
                number_format($sales - $discount + $delivery, 2),
                isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : '',
            ];
        }, $soldProducts, array_keys($soldProducts)),
        'table-compact',
        [],
        $totalsRowDaily,
        $dailyNumericCols
    );

    $t2 = soldProductsAggregateProductExportTotals($productDetailList, false);
    $totalsRowDetail = '<tr class="table-totals">'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell td-total-key">TOTAL</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t2['gross_qty'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t2['net_qty'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t2['return_qty'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t2['orders'], 0, '.', ''), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t2['full_cost'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t2['discount'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($t2['amount'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-total-dash">&#8212;</td>'
        . '</tr>';

    $html .= soldProductsBuildPdfTable(
        'Product Detail List',
        ['No', 'Product Name', 'Brand', 'Code', 'Total Sold', 'Qty Return', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Total Amount', 'Last Sold'],
        array_map(static function ($product, $index) {
            $netQty = (float)($product['total_quantity'] ?? 0);
            $returnQty = (float)($product['return_quantity'] ?? 0);
            $sales = (float)($product['total_sales'] ?? 0);
            $discount = (float)($product['total_discount'] ?? 0);
            return [
                (string)($index + 1),
                (string)($product['product_name'] ?? ''),
                (string)($product['brand_name'] ?? ''),
                (string)($product['product_code'] ?? ''),
                number_format($netQty + $returnQty, 2),
                number_format($returnQty, 2),
                number_format($netQty, 2),
                number_format((int)($product['order_count'] ?? 0)),
                number_format($sales, 2),
                number_format($discount, 2),
                number_format($sales - $discount, 2),
                isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : '',
            ];
        }, $productDetailList, array_keys($productDetailList)),
        'table-compact',
        [],
        $totalsRowDetail,
        $detailNumericCols
    );

    $d = soldProductsAggregateDetailedOrdersTotals($detailedOrders);
    $totalsRowOrders = '<tr class="table-totals">'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell td-total-key">TOTAL</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($d[0], 0, '.', ''), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-total-dash">&#8212;</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($d[1], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-total-dash">&#8212;</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($d[2], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($d[3], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '</tr>';

    $html .= '<div class="page-break"></div>';
    $html .= soldProductsBuildPdfTable(
        'Detailed Orders',
        ['No', 'Printed At', 'Order Code', 'Customer', 'Seller', 'Items', 'Products', 'Qty', 'Status', 'Discount', 'Amount'],
        array_map(static function ($order, $index) {
            $statusLabel = 'Paid';
            if (!empty($order['is_cancelled'])) {
                $statusLabel = 'Cancelled';
            } elseif (!empty($order['is_returned'])) {
                $statusLabel = 'Returned';
            } elseif (($order['status'] ?? '') !== 'paid') {
                $statusLabel = 'Unpaid';
            }
            return [
                (string)($index + 1),
                isset($order['printed_at']) ? date('Y-m-d H:i', strtotime((string)$order['printed_at'])) : '',
                soldProductsBuildPdfTruncate((string)($order['order_code'] ?? ''), 16),
                soldProductsBuildPdfTruncate((string)($order['customer_name'] ?? ''), 26),
                soldProductsBuildPdfTruncate((string)($order['seller_name'] ?? 'N/A'), 14),
                number_format((int)($order['item_count'] ?? 0)),
                soldProductsBuildPdfTruncate(str_replace("\n", '; ', (string)($order['products_summary'] ?? '')), 80),
                number_format((float)($order['total_quantity'] ?? 0), 2),
                $statusLabel,
                number_format((float)($order['discount'] ?? 0), 2),
                number_format((float)($order['total_amount'] ?? 0), 2),
            ];
        }, $detailedOrders, array_keys($detailedOrders)),
        'table-compact table-detailed',
        ['no', 'printed', 'code', 'customer', 'seller', 'items', 'products', 'qty', 'status', 'discount', 'amount'],
        $totalsRowOrders,
        [5, 7, 9, 10]
    );
    $html .= '<p class="report-table-note">'
        . htmlspecialchars(
            'Detailed Orders: the TOTAL row sums Items, Qty, Discount, and Amount only for orders that are not cancelled or returned (cancelled/returned rows are still listed in the table).',
            ENT_QUOTES,
            'UTF-8'
        )
        . '</p>';

    return $html;
}

function soldProductsBuildPdfTruncate(string $text, int $maxLen): string
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

/**
 * @param string|null $totalsRowHtml Last row(s) HTML inside tbody (e.g. &lt;tr class="table-totals"&gt;…). Do not use tfoot — it repeats on every printed page.
 */
function soldProductsBuildPdfTable(
    string $title,
    array $headers,
    array $rows,
    string $tableClass = '',
    array $colClasses = [],
    ?string $totalsRowHtml = null,
    array $numericColIndexes = []
): string {
    $classAttr = trim('table-report ' . $tableClass);
    $html = '<div class="section-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<table class="' . htmlspecialchars($classAttr, ENT_QUOTES, 'UTF-8') . '" border="1" cellspacing="0" cellpadding="0" rules="all" frame="box">';
    if (!empty($colClasses)) {
        $html .= '<colgroup>';
        foreach ($colClasses as $colClass) {
            $html .= '<col class="' . htmlspecialchars((string)$colClass, ENT_QUOTES, 'UTF-8') . '">';
        }
        $html .= '</colgroup>';
    }
    $html .= '<thead><tr>';
    foreach ($headers as $hi => $header) {
        $thClass = in_array((int)$hi, $numericColIndexes, true) ? ' class="th-num"' : '';
        $html .= '<th' . $thClass . '>' . htmlspecialchars((string)$header, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        $ci = 0;
        foreach ($row as $cell) {
            $tdClass = in_array((int)$ci, $numericColIndexes, true) ? ' class="td-num"' : '';
            $html .= '<td' . $tdClass . '>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</td>';
            $ci++;
        }
        $html .= '</tr>';
    }
    if ($totalsRowHtml !== null && $totalsRowHtml !== '') {
        $html .= $totalsRowHtml;
    }
    $html .= '</tbody></table>';
    return $html;
}

function soldProductsRenderHtmlToPdf(string $html, string $pdfPath): void
{
    $chrome = soldProductsResolveChromeBinary();
    if ($chrome === null) {
        throw new RuntimeException('Chrome is required for Khmer PDF export but was not found.');
    }

    $htmlPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sold_products_pdf_html_', true) . '.html';
    if (file_put_contents($htmlPath, $html) === false) {
        throw new RuntimeException('Unable to create temporary HTML for PDF export');
    }

    try {
        $htmlReal = str_replace('\\', '/', realpath($htmlPath) ?: $htmlPath);
        $fileUrl = 'file:///' . ltrim($htmlReal, '/');
        $command = '"' . $chrome . '" --headless --disable-gpu --no-sandbox --no-pdf-header-footer --print-to-pdf="' . $pdfPath . '" "' . $fileUrl . '" 2>&1';
        $output = shell_exec($command);
        if (!is_file($pdfPath) || filesize($pdfPath) <= 0) {
            throw new RuntimeException('Failed to render Khmer PDF via Chrome. ' . trim((string)$output));
        }
    } finally {
        @unlink($htmlPath);
    }
}

function soldProductsGenerateProductListPdf(array $soldProducts, array $summary, string $fromDate, string $toDate): array
{
    $safeFrom = preg_replace('/[^0-9\-]/', '', $fromDate);
    $safeTo = preg_replace('/[^0-9\-]/', '', $toDate);
    $fileName = 'daily_sold_product_list_' . ($safeFrom !== '' ? $safeFrom : date('Y-m-d')) . '_to_' . ($safeTo !== '' ? $safeTo : date('Y-m-d')) . '.pdf';
    $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sold_products_pdf_', true) . '.pdf';

    $html = soldProductsBuildPdfHtmlHeader('Daily Sold Product List', $fromDate, $toDate);
    $html .= '<p class="meta">Products Sold: ' . number_format((float)$summary['total_products'])
        . ' &nbsp;|&nbsp; Orders: ' . number_format((float)$summary['total_orders'])
        . ' &nbsp;|&nbsp; Full Cost: $' . number_format(((float)($summary['total_sales'] ?? 0)) + ((float)($summary['total_delivery_cost'] ?? 0)), 2)
        . ' &nbsp;|&nbsp; Discount: $' . number_format((float)$summary['total_discount'], 2)
        . ' &nbsp;|&nbsp; Total Amount: $' . number_format(((float)($summary['total_sold'] ?? 0)) + ((float)($summary['total_delivery_cost'] ?? 0)), 2) . '</p>';
    $tpl = soldProductsAggregateProductExportTotals($soldProducts, true);
    $totalsRowList = '<tr class="table-totals">'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell">&nbsp;</td>'
        . '<td class="td-total-cell td-total-key">TOTAL</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($tpl['gross_qty'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($tpl['net_qty'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($tpl['return_qty'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($tpl['orders'], 0, '.', ''), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($tpl['full_cost'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($tpl['discount'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($tpl['delivery'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-num">' . htmlspecialchars(number_format($tpl['amount'], 2), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td class="td-total-dash">&#8212;</td>'
        . '</tr>';
    $html .= soldProductsBuildPdfTable(
        'Daily Sold Product List',
        ['No', 'Product Name', 'Code', 'Type', 'Total Sold', 'Qty Return', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Delivery Cost', 'Total Amount', 'Last Sold'],
        array_map(static function ($product, $index) {
            $netQty = (float)($product['total_quantity'] ?? 0);
            $returnQty = (float)($product['return_quantity'] ?? 0);
            $sales = (float)($product['total_sales'] ?? 0);
            $discount = (float)($product['total_discount'] ?? 0);
            $delivery = (float)($product['total_delivery_cost'] ?? 0);
            return [
                (string)($index + 1),
                (string)($product['product_name'] ?? ''),
                (string)($product['product_code'] ?? ''),
                product_type_display_label($product['product_type'] ?? 'normal'),
                number_format($netQty + $returnQty, 2),
                number_format($returnQty, 2),
                number_format($netQty, 2),
                number_format((int)($product['order_count'] ?? 0)),
                number_format($sales + $delivery, 2),
                number_format($discount, 2),
                number_format($delivery, 2),
                number_format($sales - $discount + $delivery, 2),
                isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : '',
            ];
        }, $soldProducts, array_keys($soldProducts)),
        'table-compact',
        [],
        $totalsRowList,
        [4, 5, 6, 7, 8, 9, 10, 11]
    );
    $html .= '</body></html>';

    soldProductsRenderHtmlToPdf($html, $filePath);
    return ['file_path' => $filePath, 'file_name' => $fileName];
}

function soldProductsGenerateTelegramExcel(array $soldProducts, array $detailedOrders, array $summary, $fromDate, $toDate) {
    $tempDir = sys_get_temp_dir();
    $safeFrom = preg_replace('/[^0-9\-]/', '', (string)$fromDate);
    $safeTo = preg_replace('/[^0-9\-]/', '', (string)$toDate);
    $fileName = 'sold_products_report_' . ($safeFrom !== '' ? $safeFrom : date('Y-m-d')) . '_to_' . ($safeTo !== '' ? $safeTo : date('Y-m-d')) . '.xlsx';
    $filePath = $tempDir . DIRECTORY_SEPARATOR . uniqid('sold_products_', true) . '.xlsx';

    $rows = [];
    $rows[] = ['Top Selling Products Report', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['From Date', $fromDate, 'To Date', $toDate, '', '', '', '', '', '', '', '', ''];
    $rows[] = ['Products Sold', $summary['total_products'], 'Total Qty', number_format(((float)($summary['total_quantity'] ?? 0)) + ((float)($summary['total_return_quantity'] ?? 0)), 2, '.', ''), 'Full Cost', number_format(((float)($summary['total_sales'] ?? 0)) + ((float)($summary['total_delivery_cost'] ?? 0)), 2, '.', ''), 'Discount', number_format($summary['total_discount'], 2, '.', ''), 'Total Amount', number_format(((float)($summary['total_sold'] ?? 0)) + ((float)($summary['total_delivery_cost'] ?? 0)), 2, '.', ''), '', '', ''];
    $rows[] = ['Daily Sold Product List', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['No', 'Product Name', 'Brand', 'Code', 'Product type', 'Total Sold', 'Qty Return', 'Qty Sold', 'Orders', 'Full Cost', 'Discount', 'Delivery Cost', 'Total Amount', 'Last Sold'];

    foreach ($soldProducts as $index => $product) {
        $fullCost = (float)($product['total_sales'] ?? 0);
        $discount = (float)($product['total_discount'] ?? 0);
        $deliveryCost = (float)($product['total_delivery_cost'] ?? 0);
        $returnQty = (float)($product['return_quantity'] ?? 0);
        $netQty = (float)($product['total_quantity'] ?? 0);
        $typeLabel = product_type_display_label($product['product_type'] ?? 'normal');
        $rows[] = [
            $index + 1,
            $product['product_name'] ?? '',
            $product['brand_name'] ?? '',
            $product['product_code'] ?? '',
            $typeLabel,
            number_format($netQty + $returnQty, 2, '.', ''),
            number_format($returnQty, 2, '.', ''),
            number_format($netQty, 2, '.', ''),
            (int)($product['order_count'] ?? 0),
            number_format($fullCost + $deliveryCost, 2, '.', ''),
            number_format($discount, 2, '.', ''),
            number_format($deliveryCost, 2, '.', ''),
            number_format($fullCost - $discount + $deliveryCost, 2, '.', ''),
            isset($product['last_sold_at']) ? date('Y-m-d H:i', strtotime((string)$product['last_sold_at'])) : ''
        ];
    }

    $rows[] = ['', 'TOTAL', '', '', '', number_format(((float)($summary['total_quantity'] ?? 0)) + ((float)($summary['total_return_quantity'] ?? 0)), 2, '.', ''), number_format((float)($summary['total_return_quantity'] ?? 0), 2, '.', ''), number_format($summary['total_quantity'], 2, '.', ''), $summary['total_orders'], number_format(((float)($summary['total_sales'] ?? 0)) + ((float)($summary['total_delivery_cost'] ?? 0)), 2, '.', ''), number_format($summary['total_discount'], 2, '.', ''), number_format((float)($summary['total_delivery_cost'] ?? 0), 2, '.', ''), number_format(((float)($summary['total_sold'] ?? 0)) + ((float)($summary['total_delivery_cost'] ?? 0)), 2, '.', ''), ''];
    $rows[] = ['Detailed Orders Shadow', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['No', 'Printed At', 'Order Code', 'Customer', 'Seller', 'Items', 'Products', 'Qty', 'Status', 'Discount', 'Amount', '', ''];

    foreach ($detailedOrders as $index => $order) {
        $statusLabel = 'Paid';
        if (!empty($order['is_cancelled'])) {
            $statusLabel = 'Cancelled';
        } elseif (!empty($order['is_returned'])) {
            $statusLabel = 'Returned';
        } elseif (($order['status'] ?? '') !== 'paid') {
            $statusLabel = 'Unpaid';
        }

        $rows[] = [
            $index + 1,
            isset($order['printed_at']) ? date('Y-m-d H:i', strtotime((string)$order['printed_at'])) : '',
            $order['order_code'] ?? '',
            $order['customer_name'] ?? '',
            $order['seller_name'] ?? 'N/A',
            (int)($order['item_count'] ?? 0),
            (string)($order['products_summary'] ?? ''),
            number_format((float)($order['total_quantity'] ?? 0), 2, '.', ''),
            $statusLabel,
            number_format((float)($order['discount'] ?? 0), 2, '.', ''),
            number_format((float)($order['total_amount'] ?? 0), 2, '.', ''),
            '',
            ''
        ];
    }

    $rows[] = ['', '', '', '', 'TOTAL', $summary['detailed_total_items'], '', number_format($summary['detailed_total_quantity'], 2, '.', ''), $summary['detailed_total_orders'], number_format($summary['detailed_total_discount'], 2, '.', ''), number_format($summary['detailed_total_amount'], 2, '.', ''), '', ''];

    soldProductsCreateSimpleXlsxFile($filePath, $rows, [7, 26, 14, 18, 12, 12, 28, 10, 14, 14, 14, 14, 18]);

    return [
        'file_path' => $filePath,
        'file_name' => $fileName,
    ];
}

$errors = [];
$success = '';

$pdo = get_db_connection();

if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(16));
}

$formToken = $_SESSION['form_token'];

$quick_filter = $_POST['quick_filter'] ?? $_GET['quick_filter'] ?? '';
$month_filter = $_POST['month_filter'] ?? $_GET['month_filter'] ?? '';
$payment_status = $_POST['payment_status'] ?? $_GET['payment_status'] ?? '';
$brand_filter_id = (int)($_POST['brand_filter_id'] ?? $_GET['brand_filter_id'] ?? 0);
if (!in_array($payment_status, ['paid', 'unpaid'], true)) {
    $payment_status = '';
}
$brand_filter_options = [];
try {
    $brand_filter_options = $pdo->query('SELECT id, name, color FROM brands WHERE active = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $brand_filter_options = [];
}
$validBrandIds = array_map(static fn($brand) => (int)($brand['id'] ?? 0), $brand_filter_options);
if ($brand_filter_id > 0 && !in_array($brand_filter_id, $validBrandIds, true)) {
    $brand_filter_id = 0;
}
$month_names = [
    '01' => 'Jan',
    '02' => 'Feb',
    '03' => 'Mar',
    '04' => 'Apr',
    '05' => 'May',
    '06' => 'Jun',
    '07' => 'Jul',
    '08' => 'Aug',
    '09' => 'Sep',
    '10' => 'Oct',
    '11' => 'Nov',
    '12' => 'Dec',
];

$from_date = $_POST['from_date'] ?? $_GET['from_date'] ?? date('Y-m-d');
$to_date = $_POST['to_date'] ?? $_GET['to_date'] ?? date('Y-m-d');

if ($quick_filter === 'today') {
    $from_date = date('Y-m-d');
    $to_date = date('Y-m-d');
    $month_filter = '';
} elseif ($quick_filter === 'yesterday') {
    $from_date = date('Y-m-d', strtotime('-1 day'));
    $to_date = date('Y-m-d', strtotime('-1 day'));
    $month_filter = '';
} elseif ($quick_filter === 'week') {
    $from_date = date('Y-m-d', strtotime('monday this week'));
    $to_date = date('Y-m-d', strtotime('sunday this week'));
    $month_filter = '';
} elseif (preg_match('/^\d{4}-\d{2}$/', $month_filter)) {
    $monthStart = $month_filter . '-01';
    $from_date = date('Y-m-d', strtotime($monthStart));
    $to_date = date('Y-m-t', strtotime($monthStart));
    $quick_filter = '';
}

$paymentStatusCondition = '';
$paymentStatusParams = [];
if ($payment_status === 'paid') {
    $paymentStatusCondition = ' AND o.status = ?';
    $paymentStatusParams[] = 'paid';
} elseif ($payment_status === 'unpaid') {
    $paymentStatusCondition = ' AND COALESCE(o.status, \'\') <> ?';
    $paymentStatusParams[] = 'paid';
}
$brandCondition = $brand_filter_id > 0 ? ' AND p.brand_id = ?' : '';
$brandParams = $brand_filter_id > 0 ? [$brand_filter_id] : [];
$orderBrandJoin = $brand_filter_id > 0 ? ' LEFT JOIN products op ON op.id = oi.product_id' : '';
$orderBrandCondition = $brand_filter_id > 0 ? ' AND op.brand_id = ?' : '';

$stmt = $pdo->prepare('
    SELECT
        oi.product_id,
        p.name AS product_name,
        MAX(COALESCE(b.name, "")) AS brand_name,
        CONCAT("PID-", p.id) AS product_code,
        MAX(COALESCE(p.product_type, \'normal\')) AS product_type,
        SUM(oi.quantity) AS total_quantity,
        SUM(
            CASE
                WHEN COALESCE(order_totals.order_line_total, 0) > 0
                THEN (oi.line_total / order_totals.order_line_total) * COALESCE(o.discount, 0)
                ELSE 0
            END
        ) AS total_discount,
        SUM(
            CASE
                WHEN COALESCE(order_totals.order_line_total, 0) > 0
                THEN (oi.line_total / order_totals.order_line_total) * COALESCE(dc.amount, 0)
                ELSE 0
            END
        ) AS total_delivery_cost,
        SUM(oi.line_total) AS total_sales,
        COUNT(DISTINCT oi.order_id) AS order_count,
        MIN(pj.printed_at) AS first_sold_at,
        MAX(pj.printed_at) AS last_sold_at
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN brands b ON b.id = p.brand_id
    JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
    LEFT JOIN (
        SELECT order_id, SUM(line_total) AS order_line_total
        FROM order_items
        GROUP BY order_id
    ) order_totals ON order_totals.order_id = oi.order_id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ?
      AND o.is_cancelled = 0
      AND o.is_returned = 0
      ' . $paymentStatusCondition . '
      ' . $brandCondition . '
    GROUP BY oi.product_id, p.name, p.id
    ORDER BY total_quantity DESC, total_sales DESC, p.name ASC
');
$stmt->execute(array_merge([$from_date, $to_date], $paymentStatusParams, $brandParams));
$sold_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$return_stmt = $pdo->prepare('
    SELECT
        oi.order_id,
        oi.product_id,
        p.name AS product_name,
        COALESCE(b.name, "") AS brand_name,
        CONCAT("PID-", p.id) AS product_code,
        COALESCE(p.product_type, "normal") AS product_type,
        oi.quantity AS quantity,
        oi.line_total AS line_total,
        pj.printed_at AS printed_at
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN brands b ON b.id = p.brand_id
    JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ?
      AND o.is_cancelled = 0
      AND o.is_returned = 1
      ' . $paymentStatusCondition . '
      ' . $brandCondition . '
    ORDER BY pj.printed_at DESC, oi.order_id DESC, oi.id ASC
');
$return_stmt->execute(array_merge([$from_date, $to_date], $paymentStatusParams, $brandParams));
$returned_items_detail = $return_stmt->fetchAll(PDO::FETCH_ASSOC);

$soldProductsById = [];
foreach ($sold_products as $product) {
    $pid = (int)($product['product_id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $product['return_quantity'] = 0.0;
    $soldProductsById[$pid] = $product;
}
foreach ($returned_items_detail as $row) {
    $pid = (int)($row['product_id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    if (!isset($soldProductsById[$pid])) {
        $soldProductsById[$pid] = [
            'product_id' => $pid,
            'product_name' => (string)($row['product_name'] ?? ''),
            'brand_name' => (string)($row['brand_name'] ?? ''),
            'product_code' => (string)($row['product_code'] ?? ''),
            'product_type' => (string)($row['product_type'] ?? 'normal'),
            'total_quantity' => 0.0,
            'total_discount' => 0.0,
            'total_delivery_cost' => 0.0,
            'total_sales' => 0.0,
            'order_count' => 0,
            'first_sold_at' => null,
            'last_sold_at' => (string)($row['printed_at'] ?? ''),
            'return_quantity' => 0.0,
        ];
    }
    $soldProductsById[$pid]['return_quantity'] += (float)($row['quantity'] ?? 0);
    if (!empty($row['printed_at']) && (empty($soldProductsById[$pid]['last_sold_at']) || (string)$row['printed_at'] > (string)$soldProductsById[$pid]['last_sold_at'])) {
        $soldProductsById[$pid]['last_sold_at'] = (string)$row['printed_at'];
    }
}
$sold_products = array_values($soldProductsById);
usort($sold_products, static function ($a, $b) {
    $qa = (float)($a['total_quantity'] ?? 0);
    $qb = (float)($b['total_quantity'] ?? 0);
    if ($qa === $qb) {
        $ra = (float)($a['return_quantity'] ?? 0);
        $rb = (float)($b['return_quantity'] ?? 0);
        if ($ra === $rb) {
            return strcmp((string)($a['product_name'] ?? ''), (string)($b['product_name'] ?? ''));
        }
        return $rb <=> $ra;
    }
    return $qb <=> $qa;
});

$detail_stmt = $pdo->prepare('
    SELECT
        oi.order_id,
        oi.product_id,
        p.name AS product_name,
        CONCAT("PID-", p.id) AS product_code,
        COALESCE(p.product_type, "normal") AS product_type,
        oi.quantity AS quantity,
        oi.line_total AS line_total,
        (
            CASE
                WHEN COALESCE(order_totals.order_line_total, 0) > 0
                THEN (oi.line_total / order_totals.order_line_total) * COALESCE(o.discount, 0)
                ELSE 0
            END
        ) AS line_discount,
        pj.printed_at AS printed_at
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    LEFT JOIN (
        SELECT order_id, SUM(line_total) AS order_line_total
        FROM order_items
        GROUP BY order_id
    ) order_totals ON order_totals.order_id = oi.order_id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ?
      AND o.is_cancelled = 0
      AND o.is_returned = 0
      ' . $paymentStatusCondition . '
      ' . $brandCondition . '
    ORDER BY pj.printed_at DESC, oi.order_id DESC, oi.id ASC
');
$detail_stmt->execute(array_merge([$from_date, $to_date], $paymentStatusParams, $brandParams));
$sold_items_detail = $detail_stmt->fetchAll(PDO::FETCH_ASSOC);

$setNames = [];
foreach ($sold_items_detail as $row) {
    if (($row['product_type'] ?? '') === 'set' && !empty($row['product_name'])) {
        $setNames[(string)$row['product_name']] = true;
    }
}
foreach ($returned_items_detail as $row) {
    if (($row['product_type'] ?? '') === 'set' && !empty($row['product_name'])) {
        $setNames[(string)$row['product_name']] = true;
    }
}

$productSetIdsByName = [];
$componentsBySetId = [];
if (!empty($setNames)) {
    $setNameList = array_keys($setNames);
    $placeholders = implode(',', array_fill(0, count($setNameList), '?'));
    $stmtSets = $pdo->prepare("SELECT id, set_name FROM product_sets WHERE set_name IN ($placeholders)");
    $stmtSets->execute($setNameList);
    $setRows = $stmtSets->fetchAll(PDO::FETCH_ASSOC);
    $setIds = [];
    foreach ($setRows as $sr) {
        $sid = (int)($sr['id'] ?? 0);
        $sname = (string)($sr['set_name'] ?? '');
        if ($sid > 0 && $sname !== '') {
            $productSetIdsByName[$sname] = $sid;
            $setIds[$sid] = true;
        }
    }

    if (!empty($setIds)) {
        $setIdList = array_keys($setIds);
        $placeSet = implode(',', array_fill(0, count($setIdList), '?'));
        $stmtComponents = $pdo->prepare("
            SELECT
                psi.product_set_id,
                psi.quantity AS component_quantity,
                p.id AS product_id,
                p.name AS product_name,
                CONCAT('PID-', p.id) AS product_code,
                COALESCE(p.cost, 0) AS unit_cost
            FROM product_set_items psi
            JOIN products p ON psi.product_id = p.id
            WHERE psi.product_set_id IN ($placeSet)
        ");
        $stmtComponents->execute($setIdList);
        foreach ($stmtComponents->fetchAll(PDO::FETCH_ASSOC) as $cr) {
            $sid = (int)($cr['product_set_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            if (!isset($componentsBySetId[$sid])) {
                $componentsBySetId[$sid] = [];
            }
            $componentsBySetId[$sid][] = [
                'product_id' => (int)($cr['product_id'] ?? 0),
                'product_name' => (string)($cr['product_name'] ?? ''),
                'product_code' => (string)($cr['product_code'] ?? ''),
                'component_quantity' => (float)($cr['component_quantity'] ?? 0),
                'unit_cost' => (float)($cr['unit_cost'] ?? 0),
            ];
        }
    }
}

$set_components_by_name = [];
foreach ($productSetIdsByName as $setName => $sid) {
    $set_components_by_name[$setName] = array_values($componentsBySetId[$sid] ?? []);
}

$product_detail_map = [];
foreach ($sold_items_detail as $row) {
    $isSet = (($row['product_type'] ?? '') === 'set');
    $qty = (float)($row['quantity'] ?? 0);
    if ($qty <= 0) {
        continue;
    }
    $lineTotal = (float)($row['line_total'] ?? 0);
    $lineDiscount = (float)($row['line_discount'] ?? 0);
    $orderId = (int)($row['order_id'] ?? 0);
    $printedAt = (string)($row['printed_at'] ?? '');

    if ($isSet) {
        $setName = (string)($row['product_name'] ?? '');
        $setId = $productSetIdsByName[$setName] ?? 0;
        $components = ($setId > 0) ? ($componentsBySetId[$setId] ?? []) : [];

        if (!empty($components)) {
            $totalWeight = 0.0;
            foreach ($components as $component) {
                $totalWeight += ((float)($component['component_quantity'] ?? 0)) * ((float)($component['unit_cost'] ?? 0));
            }
            if ($totalWeight <= 0) {
                foreach ($components as $component) {
                    $totalWeight += (float)($component['component_quantity'] ?? 0);
                }
            }
            if ($totalWeight <= 0) {
                $totalWeight = 1.0;
            }

            foreach ($components as $component) {
                $componentQty = (float)($component['component_quantity'] ?? 0);
                if ($componentQty <= 0) {
                    continue;
                }

                $pid = (int)($component['product_id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }

                $weight = ($componentQty * (float)($component['unit_cost'] ?? 0));
                if ($weight <= 0) {
                    $weight = $componentQty;
                }
                $ratio = $weight / $totalWeight;

                $key = (string)$pid;
                if (!isset($product_detail_map[$key])) {
                    $product_detail_map[$key] = [
                        'product_id' => $pid,
                        'product_name' => (string)($component['product_name'] ?? ''),
                        'product_code' => (string)($component['product_code'] ?? ''),
                        'total_quantity' => 0.0,
                        'return_quantity' => 0.0,
                        'total_sales' => 0.0,
                        'total_discount' => 0.0,
                        'order_ids' => [],
                        'last_sold_at' => null,
                    ];
                }

                $product_detail_map[$key]['total_quantity'] += ($qty * $componentQty);
                $product_detail_map[$key]['total_sales'] += ($lineTotal * $ratio);
                $product_detail_map[$key]['total_discount'] += ($lineDiscount * $ratio);
                if ($orderId > 0) {
                    $product_detail_map[$key]['order_ids'][$orderId] = true;
                }
                if ($printedAt !== '') {
                    if ($product_detail_map[$key]['last_sold_at'] === null || $printedAt > $product_detail_map[$key]['last_sold_at']) {
                        $product_detail_map[$key]['last_sold_at'] = $printedAt;
                    }
                }
            }
            continue;
        }
    }

    $pid = (int)($row['product_id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $key = (string)$pid;
    if (!isset($product_detail_map[$key])) {
        $product_detail_map[$key] = [
            'product_id' => $pid,
            'product_name' => (string)($row['product_name'] ?? ''),
            'product_code' => (string)($row['product_code'] ?? ''),
            'total_quantity' => 0.0,
            'return_quantity' => 0.0,
            'total_sales' => 0.0,
            'total_discount' => 0.0,
            'order_ids' => [],
            'last_sold_at' => null,
        ];
    }
    $product_detail_map[$key]['total_quantity'] += $qty;
    $product_detail_map[$key]['total_sales'] += $lineTotal;
    $product_detail_map[$key]['total_discount'] += $lineDiscount;
    if ($orderId > 0) {
        $product_detail_map[$key]['order_ids'][$orderId] = true;
    }
    if ($printedAt !== '') {
        if ($product_detail_map[$key]['last_sold_at'] === null || $printedAt > $product_detail_map[$key]['last_sold_at']) {
            $product_detail_map[$key]['last_sold_at'] = $printedAt;
        }
    }
}

foreach ($returned_items_detail as $row) {
    $isSet = (($row['product_type'] ?? '') === 'set');
    $qty = (float)($row['quantity'] ?? 0);
    if ($qty <= 0) {
        continue;
    }
    $orderId = (int)($row['order_id'] ?? 0);
    $printedAt = (string)($row['printed_at'] ?? '');

    if ($isSet) {
        $setName = (string)($row['product_name'] ?? '');
        $setId = $productSetIdsByName[$setName] ?? 0;
        $components = ($setId > 0) ? ($componentsBySetId[$setId] ?? []) : [];

        if (!empty($components)) {
            foreach ($components as $component) {
                $componentQty = (float)($component['component_quantity'] ?? 0);
                $pid = (int)($component['product_id'] ?? 0);
                if ($componentQty <= 0 || $pid <= 0) {
                    continue;
                }

                $key = (string)$pid;
                if (!isset($product_detail_map[$key])) {
                    $product_detail_map[$key] = [
                        'product_id' => $pid,
                        'product_name' => (string)($component['product_name'] ?? ''),
                        'product_code' => (string)($component['product_code'] ?? ''),
                        'total_quantity' => 0.0,
                        'return_quantity' => 0.0,
                        'total_sales' => 0.0,
                        'total_discount' => 0.0,
                        'order_ids' => [],
                        'last_sold_at' => null,
                    ];
                }

                $product_detail_map[$key]['return_quantity'] += ($qty * $componentQty);
                if ($orderId > 0) {
                    $product_detail_map[$key]['order_ids'][$orderId] = true;
                }
                if ($printedAt !== '' && ($product_detail_map[$key]['last_sold_at'] === null || $printedAt > $product_detail_map[$key]['last_sold_at'])) {
                    $product_detail_map[$key]['last_sold_at'] = $printedAt;
                }
            }
            continue;
        }
    }

    $pid = (int)($row['product_id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $key = (string)$pid;
    if (!isset($product_detail_map[$key])) {
        $product_detail_map[$key] = [
            'product_id' => $pid,
            'product_name' => (string)($row['product_name'] ?? ''),
            'product_code' => (string)($row['product_code'] ?? ''),
            'total_quantity' => 0.0,
            'return_quantity' => 0.0,
            'total_sales' => 0.0,
            'total_discount' => 0.0,
            'order_ids' => [],
            'last_sold_at' => null,
        ];
    }
    $product_detail_map[$key]['return_quantity'] += $qty;
    if ($orderId > 0) {
        $product_detail_map[$key]['order_ids'][$orderId] = true;
    }
    if ($printedAt !== '' && ($product_detail_map[$key]['last_sold_at'] === null || $printedAt > $product_detail_map[$key]['last_sold_at'])) {
        $product_detail_map[$key]['last_sold_at'] = $printedAt;
    }
}

$product_detail_list = array_values($product_detail_map);
foreach ($product_detail_list as &$item) {
    $item['order_count'] = isset($item['order_ids']) ? count($item['order_ids']) : 0;
    unset($item['order_ids']);
}
unset($item);

$detailProductIds = array_values(array_unique(array_filter(array_map(static function ($item) {
    return (int)($item['product_id'] ?? 0);
}, $product_detail_list))));
$detailBrandsByProductId = [];
$detailBrandIdsByProductId = [];
if ($detailProductIds) {
    $brandPlaceholders = implode(',', array_fill(0, count($detailProductIds), '?'));
    $brandStmt = $pdo->prepare("
        SELECT
            p.id AS product_id,
            p.brand_id AS brand_id,
            b.name AS brand_name
        FROM products p
        LEFT JOIN brands b ON b.id = p.brand_id
        WHERE p.id IN ($brandPlaceholders)
    ");
    $brandStmt->execute($detailProductIds);
    foreach ($brandStmt->fetchAll(PDO::FETCH_ASSOC) as $brandRow) {
        $detailProductId = (int)$brandRow['product_id'];
        $detailBrandsByProductId[$detailProductId] = (string)($brandRow['brand_name'] ?? '');
        $detailBrandIdsByProductId[$detailProductId] = (int)($brandRow['brand_id'] ?? 0);
    }
}
foreach ($product_detail_list as &$item) {
    $pid = (int)($item['product_id'] ?? 0);
    $item['brand_name'] = $detailBrandsByProductId[$pid] ?? '';
    $item['brand_id'] = $detailBrandIdsByProductId[$pid] ?? 0;
}
unset($item);
if ($brand_filter_id > 0) {
    $product_detail_list = array_values(array_filter($product_detail_list, static function ($item) use ($brand_filter_id) {
        return (int)($item['brand_id'] ?? 0) === $brand_filter_id;
    }));
}

usort($product_detail_list, static function ($a, $b) {
    $qa = (float)($a['total_quantity'] ?? 0);
    $qb = (float)($b['total_quantity'] ?? 0);
    if ($qa === $qb) {
        $sa = (float)($a['total_sales'] ?? 0);
        $sb = (float)($b['total_sales'] ?? 0);
        if ($sa === $sb) {
            return strcmp((string)($a['product_name'] ?? ''), (string)($b['product_name'] ?? ''));
        }
        return $sb <=> $sa;
    }
    return $qb <=> $qa;
});

$detail_total_products = count($product_detail_list);
$detail_total_quantity = 0.0;
$detail_total_return_quantity = 0.0;
$detail_total_sales = 0.0;
$detail_total_discount = 0.0;
$detail_total_sold = 0.0;
$detail_total_orders = 0;
foreach ($product_detail_list as $p) {
    $detail_total_quantity += (float)($p['total_quantity'] ?? 0);
    $detail_total_return_quantity += (float)($p['return_quantity'] ?? 0);
    $detail_total_sales += (float)($p['total_sales'] ?? 0);
    $detail_total_discount += (float)($p['total_discount'] ?? 0);
    $detail_total_sold += (float)($p['total_sales'] ?? 0) - (float)($p['total_discount'] ?? 0);
    $detail_total_orders += (int)($p['order_count'] ?? 0);
}

$orders_stmt = $pdo->prepare('
    SELECT
        o.id,
        o.order_code,
        o.customer_name,
        o.total_amount,
        o.discount,
        o.status,
        o.is_cancelled,
        o.is_returned,
        o.payment_method,
        pj.printed_at,
        u.name AS seller_name,
        COUNT(oi.id) AS item_count,
        COALESCE(SUM(oi.quantity), 0) AS total_quantity
    FROM orders o
    JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    LEFT JOIN users u ON o.seller_id = u.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    ' . $orderBrandJoin . '
    WHERE DATE(pj.printed_at) BETWEEN ? AND ?
      AND pj.printed_at IS NOT NULL
      ' . $paymentStatusCondition . '
      ' . $orderBrandCondition . '
    GROUP BY o.id, o.order_code, o.customer_name, o.total_amount, o.discount, o.status, o.is_cancelled, o.is_returned, o.payment_method, pj.printed_at, u.name
    ORDER BY pj.printed_at DESC
');
$orders_stmt->execute(array_merge([$from_date, $to_date], $paymentStatusParams, $brandParams));
$detailed_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

$detailedOrderIds = array_values(array_filter(array_map(static function ($order) {
    return (int)($order['id'] ?? 0);
}, $detailed_orders)));
$detailedOrderProducts = [];
if (!empty($detailedOrderIds)) {
    $placeholders = implode(',', array_fill(0, count($detailedOrderIds), '?'));
    $orderProductsStmt = $pdo->prepare("
        SELECT
            oi.order_id,
            p.name AS product_name,
            oi.quantity,
            oi.line_total
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id IN ($placeholders)
          " . ($brand_filter_id > 0 ? 'AND p.brand_id = ?' : '') . "
        ORDER BY oi.order_id, oi.id
    ");
    $orderProductsStmt->execute(array_merge($detailedOrderIds, $brandParams));
    foreach ($orderProductsStmt->fetchAll(PDO::FETCH_ASSOC) as $itemRow) {
        $oid = (int)($itemRow['order_id'] ?? 0);
        if ($oid <= 0) {
            continue;
        }
        if (!isset($detailedOrderProducts[$oid])) {
            $detailedOrderProducts[$oid] = [];
        }
        $detailedOrderProducts[$oid][] = [
            'name' => (string)($itemRow['product_name'] ?? ''),
            'quantity' => (float)($itemRow['quantity'] ?? 0),
            'line_total' => (float)($itemRow['line_total'] ?? 0),
        ];
    }
}
foreach ($detailed_orders as &$detailOrder) {
    $orderProducts = $detailedOrderProducts[(int)($detailOrder['id'] ?? 0)] ?? [];
    $productLines = [];
    foreach ($orderProducts as $orderProduct) {
        $qtyText = number_format((float)($orderProduct['quantity'] ?? 0), 0);
        $amountText = number_format((float)($orderProduct['line_total'] ?? 0), 0);
        $productLines[] = (string)($orderProduct['name'] ?? '') . " ({$qtyText}x, \${$amountText})";
    }
    $detailOrder['products_summary'] = implode("\n", $productLines);
}
unset($detailOrder);

$total_products = count($sold_products);
$total_quantity = 0;
$total_return_quantity = 0;
$total_discount = 0;
$total_delivery_cost = 0;
$total_sales = 0;
$total_sold = 0;
$total_orders = 0;
$detailed_total_orders = 0;
$cancelled_orders = 0;
$returned_orders = 0;
$detailed_total_items = 0;
$detailed_total_quantity = 0;
$detailed_total_discount = 0;
$detailed_total_amount = 0;
$detailed_paid_amount = 0;
$detailed_unpaid_amount = 0;

foreach ($sold_products as $product) {
    $total_quantity += (float)($product['total_quantity'] ?? 0);
    $total_return_quantity += (float)($product['return_quantity'] ?? 0);
    $total_discount += (float)($product['total_discount'] ?? 0);
    $total_delivery_cost += (float)($product['total_delivery_cost'] ?? 0);
    $total_sales += (float)($product['total_sales'] ?? 0);
    $total_sold += (float)($product['total_sales'] ?? 0) - (float)($product['total_discount'] ?? 0);
    $total_orders += (int)($product['order_count'] ?? 0);
}

foreach ($detailed_orders as $order) {
    if (!empty($order['is_cancelled'])) {
        $cancelled_orders++;
    } elseif (!empty($order['is_returned'])) {
        $returned_orders++;
    } else {
        $detailed_total_orders++;
        $detailed_total_items += (int)($order['item_count'] ?? 0);
        $detailed_total_quantity += (float)($order['total_quantity'] ?? 0);
        $detailed_total_discount += (float)($order['discount'] ?? 0);
        $detailed_total_amount += (float)($order['total_amount'] ?? 0);

        if (($order['status'] ?? '') === 'paid') {
            $detailed_paid_amount += (float)($order['total_amount'] ?? 0);
        } else {
            $detailed_unpaid_amount += (float)($order['total_amount'] ?? 0);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $submittedToken = $_POST['form_token'] ?? '';

    if (!hash_equals((string)$formToken, (string)$submittedToken)) {
        $errors[] = 'Invalid form submission. Please refresh the page and try again.';
    } elseif ($action === 'send_telegram_report') {
        $summary = soldProductsBuildSummary([
            'total_products' => $total_products,
            'total_quantity' => $total_quantity,
            'total_return_quantity' => $total_return_quantity,
            'total_discount' => $total_discount,
            'total_sales' => $total_sales,
            'total_delivery_cost' => $total_delivery_cost,
            'total_sold' => $total_sold,
            'total_orders' => $total_orders,
            'detailed_total_orders' => $detailed_total_orders,
            'detailed_total_items' => $detailed_total_items,
            'detailed_total_quantity' => $detailed_total_quantity,
            'detail_total_return_quantity' => $detail_total_return_quantity,
            'detailed_total_discount' => $detailed_total_discount,
            'detailed_total_amount' => $detailed_total_amount,
            'detailed_paid_amount' => $detailed_paid_amount,
            'detailed_unpaid_amount' => $detailed_unpaid_amount,
            'cancelled_orders' => $cancelled_orders,
            'returned_orders' => $returned_orders,
        ]);

        $sendResult = soldProductsSendTelegramReports($sold_products, $detailed_orders, $summary, (string)$from_date, (string)$to_date);
        if ($sendResult['success']) {
            $success = 'Sold products reports sent to Telegram successfully.';
        }
        if (!empty($sendResult['errors'])) {
            $errors = array_merge($errors, $sendResult['errors']);
        }
    } elseif ($action === 'download_product_list') {
        // Build summary and export Product List
        $summary = [
            'total_products' => $total_products,
            'total_quantity' => $total_quantity,
            'total_return_quantity' => $total_return_quantity,
            'total_discount' => $total_discount,
            'total_sales' => $total_sales,
            'total_delivery_cost' => $total_delivery_cost,
            'total_sold' => $total_sold,
            'total_orders' => $total_orders,
            'detailed_total_orders' => $detailed_total_orders,
            'detailed_total_items' => $detailed_total_items,
            'detailed_total_quantity' => $detailed_total_quantity,
            'detailed_total_discount' => $detailed_total_discount,
            'detailed_total_amount' => $detailed_total_amount,
            'detailed_paid_amount' => $detailed_paid_amount,
            'detailed_unpaid_amount' => $detailed_unpaid_amount,
            'cancelled_orders' => $cancelled_orders,
            'returned_orders' => $returned_orders,
        ];
        try {
            $export = soldProductsGenerateProductListExcel($sold_products, $summary, $from_date, $to_date);
            if (!empty($export['file_path']) && is_file($export['file_path'])) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . basename($export['file_name']) . '"');
                header('Content-Length: ' . filesize($export['file_path']));
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: public');
                readfile($export['file_path']);
                @unlink($export['file_path']);
                exit;
            } else {
                $errors[] = 'Failed to generate Product List export file.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Failed to export Product List: ' . $e->getMessage();
        }
    } elseif ($action === 'download_product_list_pdf') {
        $summary = [
            'total_products' => $total_products,
            'total_quantity' => $total_quantity,
            'total_return_quantity' => $total_return_quantity,
            'total_discount' => $total_discount,
            'total_sales' => $total_sales,
            'total_delivery_cost' => $total_delivery_cost,
            'total_sold' => $total_sold,
            'total_orders' => $total_orders,
            'detailed_total_orders' => $detailed_total_orders,
            'detailed_total_items' => $detailed_total_items,
            'detailed_total_quantity' => $detailed_total_quantity,
            'detailed_total_discount' => $detailed_total_discount,
            'detailed_total_amount' => $detailed_total_amount,
            'detailed_paid_amount' => $detailed_paid_amount,
            'detailed_unpaid_amount' => $detailed_unpaid_amount,
            'cancelled_orders' => $cancelled_orders,
            'returned_orders' => $returned_orders,
        ];
        try {
            $export = soldProductsGenerateProductListPdf($sold_products, $summary, (string)$from_date, (string)$to_date);
            if (!empty($export['file_path']) && is_file($export['file_path'])) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . basename($export['file_name']) . '"');
                header('Content-Length: ' . filesize($export['file_path']));
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: public');
                readfile($export['file_path']);
                @unlink($export['file_path']);
                exit;
            } else {
                $errors[] = 'Failed to generate Product List PDF file.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Failed to export Product List PDF: ' . $e->getMessage();
        }
    } elseif ($action === 'download_detailed_orders') {
        // Build summary and export Detailed Orders
        $summary = [
            'total_products' => $total_products,
            'total_quantity' => $total_quantity,
            'total_return_quantity' => $total_return_quantity,
            'total_discount' => $total_discount,
            'total_sales' => $total_sales,
            'total_delivery_cost' => $total_delivery_cost,
            'total_sold' => $total_sold,
            'total_orders' => $total_orders,
            'detailed_total_orders' => $detailed_total_orders,
            'detailed_total_items' => $detailed_total_items,
            'detailed_total_quantity' => $detailed_total_quantity,
            'detailed_total_discount' => $detailed_total_discount,
            'detailed_total_amount' => $detailed_total_amount,
            'detailed_paid_amount' => $detailed_paid_amount,
            'detailed_unpaid_amount' => $detailed_unpaid_amount,
            'cancelled_orders' => $cancelled_orders,
            'returned_orders' => $returned_orders,
        ];
        try {
            $export = soldProductsGenerateDetailedOrdersExcel($detailed_orders, $summary, $from_date, $to_date);
            if (!empty($export['file_path']) && is_file($export['file_path'])) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . basename($export['file_name']) . '"');
                header('Content-Length: ' . filesize($export['file_path']));
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: public');
                readfile($export['file_path']);
                @unlink($export['file_path']);
                exit;
            } else {
                $errors[] = 'Failed to generate Detailed Orders export file.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Failed to export Detailed Orders: ' . $e->getMessage();
        }
    } elseif ($action === 'download_product_detail_list') {
        $summary = [
            'detail_total_products' => $detail_total_products,
            'detail_total_quantity' => $detail_total_quantity,
            'detail_total_return_quantity' => $detail_total_return_quantity,
            'detail_total_sales' => $detail_total_sales,
            'detail_total_discount' => $detail_total_discount,
            'detail_total_sold' => $detail_total_sold,
            'detail_total_orders' => $detail_total_orders,
        ];
        try {
            $export = soldProductsGenerateProductDetailListExcel($product_detail_list, $summary, $from_date, $to_date);
            if (!empty($export['file_path']) && is_file($export['file_path'])) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . basename($export['file_name']) . '"');
                header('Content-Length: ' . filesize($export['file_path']));
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: public');
                readfile($export['file_path']);
                @unlink($export['file_path']);
                exit;
            } else {
                $errors[] = 'Failed to generate Product Detail List export file.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Failed to export Product Detail List: ' . $e->getMessage();
        }
    } elseif ($action === 'download_all_tables_excel') {
        $summary = [
            'total_products' => $total_products,
            'total_quantity' => $total_quantity,
            'total_return_quantity' => $total_return_quantity,
            'total_delivery_cost' => $total_delivery_cost,
            'total_sold' => $total_sold,
        ];
        try {
            $export = soldProductsGenerateCombinedAllTablesExcel($sold_products, $product_detail_list, $detailed_orders, $summary, (string)$from_date, (string)$to_date);
            if (!empty($export['file_path']) && is_file($export['file_path'])) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . basename($export['file_name']) . '"');
                header('Content-Length: ' . filesize($export['file_path']));
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: public');
                readfile($export['file_path']);
                @unlink($export['file_path']);
                exit;
            } else {
                $errors[] = 'Failed to generate combined Excel file.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Failed to export combined Excel: ' . $e->getMessage();
        }
    } elseif ($action === 'print_all_tables') {
        $html = soldProductsBuildCombinedPrintPageHtml($sold_products, $product_detail_list, $detailed_orders, (string)$from_date, (string)$to_date);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $html;
        exit;
    }
}

// Auto-send once when #stop in cashier broadcast queued it.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $pendingAutoSend = soldProductsGetPendingAutoSend($pdo);
    if ($pendingAutoSend !== null) {
        $summary = soldProductsBuildSummary([
            'total_products' => $total_products,
            'total_quantity' => $total_quantity,
            'total_return_quantity' => $total_return_quantity,
            'total_discount' => $total_discount,
            'total_sales' => $total_sales,
            'total_delivery_cost' => $total_delivery_cost,
            'total_sold' => $total_sold,
            'total_orders' => $total_orders,
            'detailed_total_orders' => $detailed_total_orders,
            'detailed_total_items' => $detailed_total_items,
            'detailed_total_quantity' => $detailed_total_quantity,
            'detailed_total_discount' => $detailed_total_discount,
            'detailed_total_amount' => $detailed_total_amount,
            'detailed_paid_amount' => $detailed_paid_amount,
            'detailed_unpaid_amount' => $detailed_unpaid_amount,
            'cancelled_orders' => $cancelled_orders,
            'returned_orders' => $returned_orders,
        ]);
        $sendResult = soldProductsSendTelegramReports($sold_products, $detailed_orders, $summary, (string)$from_date, (string)$to_date);
        if ($sendResult['success']) {
            $success = 'Auto send from #stop completed: sold products report sent to Telegram.';
            soldProductsClearPendingAutoSend($pdo);
        }
        if (!empty($sendResult['errors'])) {
            $errors = array_merge($errors, $sendResult['errors']);
        }
    }
}

include __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Top Selling Products</h1>
        <div class="d-flex gap-2 flex-wrap">
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="download_all_tables_excel">
                <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken) ?>">
                <input type="hidden" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
                <input type="hidden" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
                <input type="hidden" name="quick_filter" value="<?= htmlspecialchars($quick_filter) ?>">
                <input type="hidden" name="month_filter" value="<?= htmlspecialchars($month_filter) ?>">
                <input type="hidden" name="payment_status" value="<?= htmlspecialchars($payment_status) ?>">
                <input type="hidden" name="brand_filter_id" value="<?= (int)$brand_filter_id ?>">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-download me-1"></i>Excel (All Tables)
                </button>
            </form>
            <form method="post" class="d-inline" target="_blank" rel="noopener">
                <input type="hidden" name="action" value="print_all_tables">
                <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken) ?>">
                <input type="hidden" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
                <input type="hidden" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
                <input type="hidden" name="quick_filter" value="<?= htmlspecialchars($quick_filter) ?>">
                <input type="hidden" name="month_filter" value="<?= htmlspecialchars($month_filter) ?>">
                <input type="hidden" name="payment_status" value="<?= htmlspecialchars($payment_status) ?>">
                <input type="hidden" name="brand_filter_id" value="<?= (int)$brand_filter_id ?>">
                <button type="submit" class="btn btn-secondary btn-sm">
                    <i class="bi bi-printer me-1"></i>Print (All Tables)
                </button>
            </form>
        </div>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-3">
            <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="get" class="card shadow-sm mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label">From Date</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">To Date</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>" required>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
            <div class="col-12 col-md-2">
                <a href="sold_products.php?<?= htmlspecialchars(http_build_query(array_filter(['quick_filter' => 'today', 'payment_status' => $payment_status, 'brand_filter_id' => $brand_filter_id], static fn($v) => $v !== '' && $v !== 0))) ?>" class="btn btn-outline-secondary w-100">Today</a>
            </div>
            <div class="col-12 col-md-2">
                <a href="sold_products.php?<?= htmlspecialchars(http_build_query(array_filter(['quick_filter' => 'yesterday', 'payment_status' => $payment_status, 'brand_filter_id' => $brand_filter_id], static fn($v) => $v !== '' && $v !== 0))) ?>" class="btn btn-outline-secondary w-100">Yesterday</a>
            </div>
            <div class="col-12 col-md-2">
                <a href="sold_products.php?<?= htmlspecialchars(http_build_query(array_filter(['quick_filter' => 'week', 'payment_status' => $payment_status, 'brand_filter_id' => $brand_filter_id], static fn($v) => $v !== '' && $v !== 0))) ?>" class="btn btn-outline-secondary w-100">This Week</a>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Payment Status</label>
                <select name="payment_status" class="form-select" onchange="this.form.submit()">
                    <option value="" <?= $payment_status === '' ? 'selected' : '' ?>>All</option>
                    <option value="paid" <?= $payment_status === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="unpaid" <?= $payment_status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Brand</label>
                <select name="brand_filter_id" class="form-select" onchange="this.form.submit()">
                    <option value="0" <?= $brand_filter_id === 0 ? 'selected' : '' ?>>All Brands</option>
                    <?php foreach ($brand_filter_options as $brandOption): ?>
                        <?php
                        $brandId = (int)($brandOption['id'] ?? 0);
                        $brandName = (string)($brandOption['name'] ?? '');
                        ?>
                        <option value="<?= $brandId ?>" <?= $brand_filter_id === $brandId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($brandName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Month</label>
                <select name="month_filter" class="form-select" onchange="this.form.submit()">
                    <option value="">Select Month</option>
                    <?php foreach ($month_names as $month_number => $month_label): ?>
                        <?php $month_value = date('Y') . '-' . $month_number; ?>
                        <option value="<?= htmlspecialchars($month_value) ?>" <?= $month_filter === $month_value ? 'selected' : '' ?>>
                            <?= htmlspecialchars($month_label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-1">
                <a href="sold_products.php" class="btn btn-outline-dark w-100">All</a>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white shadow-sm">
                <div class="card-body text-center">
                    <h5 class="card-title">Products Sold</h5>
                    <h3 class="mb-0"><?= number_format($total_products) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white shadow-sm">
                <div class="card-body text-center">
                    <h5 class="card-title">Total Qty</h5>
                    <h3 class="mb-0"><?= number_format($total_quantity, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-dark text-white shadow-sm">
                <div class="card-body text-center">
                    <h5 class="card-title">Full Cost</h5>
                    <h3 class="mb-0">$<?= number_format($total_sales, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-dark shadow-sm">
                <div class="card-body text-center">
                    <h5 class="card-title">Discount</h5>
                    <h3 class="mb-0">$<?= number_format($total_discount, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-info text-white shadow-sm">
                <div class="card-body text-center">
                    <h5 class="card-title">Total Sold</h5>
                    <h3 class="mb-0">$<?= number_format($total_sold, 2) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm flex-grow-1">
        <div class="card-body d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
                <h5 class="card-title mb-0"><i class="bi bi-box-seam me-2"></i>Daily Sold Product List</h5>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="download_product_list">
                        <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken) ?>">
                        <input type="hidden" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
                        <input type="hidden" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
                        <input type="hidden" name="quick_filter" value="<?= htmlspecialchars($quick_filter) ?>">
                        <input type="hidden" name="month_filter" value="<?= htmlspecialchars($month_filter) ?>">
                        <input type="hidden" name="payment_status" value="<?= htmlspecialchars($payment_status) ?>">
                        <input type="hidden" name="brand_filter_id" value="<?= (int)$brand_filter_id ?>">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-download me-1"></i>Excel
                        </button>
                    </form>
                    <span class="badge bg-primary"><?= number_format($total_orders) ?> Orders</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:60px;">No</th>
                            <th>Product Name</th>
                            <th style="width:150px;">Brand</th>
                            <th style="width:140px;">Code</th>
                            <th style="width:100px;">Type</th>
                            <th class="text-end" style="width:150px;">Total Sold</th>
                            <th class="text-end" style="width:120px;">Qty Return</th>
                            <th class="text-end" style="width:120px;">Sold Out</th>
                            <th class="text-end" style="width:120px;">Orders</th>
                            <th class="text-end" style="width:150px;">Full Cost</th>
                            <th class="text-end" style="width:140px;">Discount</th>
                            <th class="text-end" style="width:150px;">Delivery Cost</th>
                            <th class="text-end" style="width:150px;">Total Amount</th>
                            <th style="width:160px;">Last Sold</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($sold_products)): ?>
                            <?php foreach ($sold_products as $index => $product): ?>
                                <tr>
                                    <td class="text-center"><?= $index + 1 ?></td>
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-1">
                                            <span class="align-middle"><?= htmlspecialchars((string)$product['product_name']) ?></span>
                                            <?php if (($product['product_type'] ?? '') === 'set'): ?>
                                                <button type="button"
                                                    class="btn btn-outline-secondary btn-sm rounded-pill sold-products-set-btn d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                                    title="View set components"
                                                    aria-label="View set components"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#soldProductsSetModal"
                                                    data-set-name="<?= htmlspecialchars((string)$product['product_name'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <i class="bi bi-boxes" aria-hidden="true"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars((string)($product['brand_name'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)$product['product_code']) ?></td>
                                    <td><?= htmlspecialchars(product_type_display_label($product['product_type'] ?? 'normal')) ?></td>
                                    <td class="text-end"><?= number_format(((float)$product['total_quantity']) + ((float)($product['return_quantity'] ?? 0)), 2) ?></td>
                                    <td class="text-end text-warning"><?= number_format((float)($product['return_quantity'] ?? 0), 2) ?></td>
                                    <td class="text-end text-success"><?= number_format((float)$product['total_quantity'], 2) ?></td>
                                    <td class="text-end"><?= number_format((int)$product['order_count']) ?></td>
                                    <td class="text-end">$<?= number_format((float)$product['total_sales'] + ((float)($product['total_delivery_cost'] ?? 0)), 2) ?></td>
                                    <td class="text-end">$<?= number_format((float)($product['total_discount'] ?? 0), 2) ?></td>
                                    <td class="text-end">$<?= number_format((float)($product['total_delivery_cost'] ?? 0), 2) ?></td>
                                    <td class="text-end">$<?= number_format(((float)$product['total_sales']) - ((float)($product['total_discount'] ?? 0)) + ((float)($product['total_delivery_cost'] ?? 0)), 2) ?></td>
                                    <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$product['last_sold_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="14" class="text-center text-muted py-4">No sold products found for the selected date range.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($sold_products)): ?>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="5" class="text-end">Total</th>
                                <th class="text-end"><?= number_format($total_quantity + $total_return_quantity, 2) ?></th>
                                <th class="text-end text-warning"><?= number_format($total_return_quantity, 2) ?></th>
                                <th class="text-end text-success"><?= number_format($total_quantity, 2) ?></th>
                                <th class="text-end"><?= number_format($total_orders) ?></th>
                                <th class="text-end">$<?= number_format($total_sales + $total_delivery_cost, 2) ?></th>
                                <th class="text-end">$<?= number_format($total_discount, 2) ?></th>
                                <th class="text-end">$<?= number_format($total_delivery_cost, 2) ?></th>
                                <th class="text-end">$<?= number_format($total_sold + $total_delivery_cost, 2) ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-body d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
                <h5 class="card-title mb-0"><i class="bi bi-list-ul me-2"></i>Product Detail List</h5>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="download_product_detail_list">
                        <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken) ?>">
                        <input type="hidden" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
                        <input type="hidden" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
                        <input type="hidden" name="quick_filter" value="<?= htmlspecialchars($quick_filter) ?>">
                        <input type="hidden" name="month_filter" value="<?= htmlspecialchars($month_filter) ?>">
                        <input type="hidden" name="payment_status" value="<?= htmlspecialchars($payment_status) ?>">
                        <input type="hidden" name="brand_filter_id" value="<?= (int)$brand_filter_id ?>">
                        <button type="submit" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-download me-1"></i>Excel
                        </button>
                    </form>
                    <span class="badge bg-secondary"><?= number_format($detail_total_orders) ?> Orders</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:60px;">No</th>
                            <th>Product Name</th>
                            <th style="width:150px;">Brand</th>
                            <th style="width:140px;">Code</th>
                            <th class="text-end" style="width:150px;">Total Sold</th>
                            <th class="text-end" style="width:120px;">Qty Return</th>
                            <th class="text-end" style="width:120px;">Sold Out</th>
                            <th class="text-end" style="width:120px;">Orders</th>
                            <th class="text-end" style="width:150px;">Full Cost</th>
                            <th class="text-end" style="width:140px;">Discount</th>
                            <th class="text-end" style="width:150px;">Total Amount</th>
                            <th style="width:160px;">Last Sold</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($product_detail_list)): ?>
                            <?php foreach ($product_detail_list as $index => $product): ?>
                                <tr>
                                    <td class="text-center"><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars((string)$product['product_name']) ?></td>
                                    <td>
                                        <?php if (!empty($product['brand_name'])): ?>
                                            <span class="badge bg-info-subtle text-info-emphasis">
                                                <?= htmlspecialchars((string)$product['brand_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)($product['brand_name'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)$product['product_code']) ?></td>
                                    <td class="text-end"><?= number_format(((float)($product['total_quantity'] ?? 0)) + ((float)($product['return_quantity'] ?? 0)), 2) ?></td>
                                    <td class="text-end text-warning"><?= number_format((float)($product['return_quantity'] ?? 0), 2) ?></td>
                                    <td class="text-end text-success"><?= number_format((float)($product['total_quantity'] ?? 0), 2) ?></td>
                                    <td class="text-end"><?= number_format((int)($product['order_count'] ?? 0)) ?></td>
                                    <td class="text-end">$<?= number_format((float)($product['total_sales'] ?? 0), 2) ?></td>
                                    <td class="text-end">$<?= number_format((float)($product['total_discount'] ?? 0), 2) ?></td>
                                    <td class="text-end">$<?= number_format(((float)($product['total_sales'] ?? 0)) - ((float)($product['total_discount'] ?? 0)), 2) ?></td>
                                    <td>
                                        <?php if (!empty($product['last_sold_at'])): ?>
                                            <?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$product['last_sold_at']))) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center text-muted py-4">No product details found for the selected date range.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($product_detail_list)): ?>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="5" class="text-end">Total</th>
                                <th class="text-end"><?= number_format($detail_total_quantity + $detail_total_return_quantity, 2) ?></th>
                                <th class="text-end text-warning"><?= number_format($detail_total_return_quantity, 2) ?></th>
                                <th class="text-end text-success"><?= number_format($detail_total_quantity, 2) ?></th>
                                <th class="text-end"><?= number_format($detail_total_orders) ?></th>
                                <th class="text-end">$<?= number_format($detail_total_sales, 2) ?></th>
                                <th class="text-end">$<?= number_format($detail_total_discount, 2) ?></th>
                                <th class="text-end">$<?= number_format($detail_total_sold, 2) ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-body d-flex flex-column">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <h5 class="card-title mb-0"><i class="bi bi-receipt-cutoff me-2"></i>Detailed Orders (<?= number_format($detailed_total_orders) ?> orders)</h5>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="download_detailed_orders">
                        <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken) ?>">
                        <input type="hidden" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
                        <input type="hidden" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
                        <input type="hidden" name="quick_filter" value="<?= htmlspecialchars($quick_filter) ?>">
                        <input type="hidden" name="month_filter" value="<?= htmlspecialchars($month_filter) ?>">
                        <input type="hidden" name="payment_status" value="<?= htmlspecialchars($payment_status) ?>">
                        <input type="hidden" name="brand_filter_id" value="<?= (int)$brand_filter_id ?>">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-download me-1"></i>Excel
                        </button>
                    </form>
                    <span class="badge bg-danger">Cancel: <?= number_format($cancelled_orders) ?></span>
                    <span class="badge bg-warning text-dark">Return: <?= number_format($returned_orders) ?></span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:60px;">No</th>
                            <th>Printed At</th>
                            <th>Order Code</th>
                            <th>Customer</th>
                            <th>Seller</th>
                            <th class="text-end">Items</th>
                            <th>Products</th>
                            <th class="text-end">Qty</th>
                            <th>Status</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Amount</th>
                            <th class="text-center" style="width:70px;">View</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($detailed_orders)): ?>
                            <?php foreach ($detailed_orders as $index => $order): ?>
                                <?php
                                $statusLabel = 'Paid';
                                $statusClass = 'success';
                                if (!empty($order['is_cancelled'])) {
                                    $statusLabel = 'Cancelled';
                                    $statusClass = 'danger';
                                } elseif (!empty($order['is_returned'])) {
                                    $statusLabel = 'Returned';
                                    $statusClass = 'danger';
                                } elseif (($order['status'] ?? '') !== 'paid') {
                                    $statusLabel = 'Unpaid';
                                    $statusClass = 'secondary';
                                }
                                ?>
                                <tr class="<?= !empty($order['is_returned']) ? 'table-danger' : '' ?>">
                                    <td class="text-center"><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$order['printed_at']))) ?></td>
                                    <td><?= htmlspecialchars((string)$order['order_code']) ?></td>
                                    <td><?= htmlspecialchars((string)$order['customer_name']) ?></td>
                                    <td><?= htmlspecialchars((string)($order['seller_name'] ?? 'N/A')) ?></td>
                                    <td class="text-end"><?= number_format((int)$order['item_count']) ?></td>
                                    <td>
                                        <?php foreach (($detailedOrderProducts[(int)$order['id']] ?? []) as $orderProduct): ?>
                                            <div>
                                                <?= htmlspecialchars((string)$orderProduct['name']) ?>
                                                (<?= number_format((float)$orderProduct['quantity'], 0) ?>x, $<?= number_format((float)$orderProduct['line_total'], 0) ?>)
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="text-end"><?= number_format((float)$order['total_quantity'], 2) ?></td>
                                    <td><span class="badge bg-<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                                    <td class="text-end">$<?= number_format((float)($order['discount'] ?? 0), 2) ?></td>
                                    <td class="text-end">$<?= number_format((float)$order['total_amount'], 2) ?></td>
                                    <td class="text-center">
                                        <a href="<?= htmlspecialchars($BASE_URL) ?>/receipt.php?id=<?= (int)$order['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm" title="View Receipt">
                                            <i class="bi bi-receipt"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center text-muted py-4">No printed orders found for the selected date range.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($detailed_orders)): ?>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="5" class="text-end">Total</th>
                                <th class="text-end"><?= number_format($detailed_total_items) ?></th>
                                <th></th>
                                <th class="text-end"><?= number_format($detailed_total_quantity, 2) ?></th>
                                <th class="text-end"><?= number_format($detailed_total_orders) ?></th>
                                <th class="text-end">$<?= number_format($detailed_total_discount, 2) ?></th>
                                <th class="text-end">$<?= number_format($detailed_total_amount, 2) ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="soldProductsSetModal" tabindex="-1" aria-labelledby="soldProductsSetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-light border-bottom py-3">
                <div class="d-flex align-items-start gap-3">
                    <span class="rounded-3 bg-white border d-inline-flex align-items-center justify-content-center flex-shrink-0 sold-products-set-modal-icon">
                        <i class="bi bi-boxes text-primary" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <h5 class="modal-title mb-1 fw-semibold" id="soldProductsSetModalLabel">Set contents</h5>
                        <p class="text-muted small mb-0 text-break" id="soldProductsSetModalSub">—</p>
                    </div>
                </div>
                <button type="button" class="btn-close mt-1" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 p-md-4">
                <div id="soldProductsSetModalTableWrap" class="d-none">
                    <p class="small text-secondary mb-2 mb-md-3">Each row is one part of a single set when sold.</p>
                    <div class="table-responsive rounded-3 border bg-white">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="text-center" style="width:3rem;">#</th>
                                    <th scope="col">Component</th>
                                    <th scope="col" style="width:140px;">Code</th>
                                    <th scope="col" class="text-end" style="width:120px;">Qty / set</th>
                                </tr>
                            </thead>
                            <tbody id="soldProductsSetModalBody">
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="soldProductsSetModalEmptyWrap" class="d-none text-center py-5 px-3">
                    <span class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3 sold-products-set-empty-icon">
                        <i class="bi bi-inbox text-secondary" aria-hidden="true"></i>
                    </span>
                    <p class="fw-medium text-dark mb-1">No components found</p>
                    <p class="text-muted small mb-0">This set has no product mapping defined.</p>
                </div>
            </div>
            <div class="modal-footer bg-light border-0 pt-0 pb-3 px-3 px-md-4">
                <button type="button" class="btn btn-primary px-4" data-bs-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>

<style>
.sold-products-set-btn {
    min-width: 2rem;
    min-height: 2rem;
    padding: 0.25rem 0.5rem !important;
    line-height: 1;
}
.sold-products-set-btn:hover {
    background-color: var(--bs-secondary-bg);
    border-color: var(--bs-secondary-bg);
}
.sold-products-set-modal-icon {
    width: 2.75rem;
    height: 2.75rem;
}
.sold-products-set-empty-icon {
    width: 4rem;
    height: 4rem;
}
</style>

<script>
(function () {
    const map = <?= json_encode($set_components_by_name, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const modalEl = document.getElementById('soldProductsSetModal');
    if (!modalEl) return;
    modalEl.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        const name = btn && btn.getAttribute('data-set-name') ? btn.getAttribute('data-set-name') : '';
        const subEl = modalEl.querySelector('#soldProductsSetModalSub');
        const tbody = modalEl.querySelector('#soldProductsSetModalBody');
        const tableWrap = modalEl.querySelector('#soldProductsSetModalTableWrap');
        const emptyWrap = modalEl.querySelector('#soldProductsSetModalEmptyWrap');
        if (subEl) {
            subEl.textContent = name ? name : '—';
        }
        const comps = (name && map[name]) ? map[name] : [];
        if (!tbody) return;
        tbody.innerHTML = '';
        if (comps.length === 0) {
            if (tableWrap) tableWrap.classList.add('d-none');
            if (emptyWrap) emptyWrap.classList.remove('d-none');
        } else {
            if (emptyWrap) emptyWrap.classList.add('d-none');
            if (tableWrap) tableWrap.classList.remove('d-none');
            comps.forEach(function (c, idx) {
                const tr = document.createElement('tr');
                const q = (c.component_quantity !== undefined && c.component_quantity !== null)
                    ? Number(c.component_quantity)
                    : 0;
                tr.innerHTML =
                    '<td class="text-center text-muted small">' + (idx + 1) + '</td>' +
                    '<td>' + escapeHtml(String(c.product_name || '')) + '</td>' +
                    '<td><code class="small text-body bg-light px-1 rounded">' + escapeHtml(String(c.product_code || '')) + '</code></td>' +
                    '<td class="text-end fw-medium">' + (isFinite(q) ? q.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) : '') + '</td>';
                tbody.appendChild(tr);
            });
        }
    });
    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
})();
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
