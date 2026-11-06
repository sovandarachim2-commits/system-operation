<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function is_report_date(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

/**
 * Expand sold items so any product with product_type='set' is broken down into its component products.
 *
 * @param PDO $pdo
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array{product_id:int, product_name:string, sku:string, brand_name:string, quantity:float, month_year:string}>
 */
function expand_sold_items_with_sets(PDO $pdo, array $items): array
{
    if (!$items) {
        return [];
    }

    $setNames = [];
    foreach ($items as $row) {
        if (strtolower((string)($row['product_type'] ?? 'normal')) === 'set' && !empty($row['product_name'])) {
            $setNames[(string)$row['product_name']] = true;
        }
    }

    $productSetIdsByName = [];
    $componentsBySetId = [];
    if ($setNames && api_table_exists($pdo, 'product_sets') && api_table_exists($pdo, 'product_set_items')) {
        $setNameList = array_keys($setNames);
        $placeholders = implode(',', array_fill(0, count($setNameList), '?'));
        $stmtSets = $pdo->prepare("SELECT id, set_name FROM product_sets WHERE set_name IN ($placeholders)");
        $stmtSets->execute($setNameList);
        $setIds = [];
        foreach ($stmtSets->fetchAll(PDO::FETCH_ASSOC) as $setRow) {
            $sid = (int)($setRow['id'] ?? 0);
            $name = (string)($setRow['set_name'] ?? '');
            if ($sid > 0 && $name !== '') {
                $productSetIdsByName[$name] = $sid;
                $setIds[] = $sid;
            }
        }
        if ($setIds) {
            $placeholders = implode(',', array_fill(0, count($setIds), '?'));
            $stmtComponents = $pdo->prepare("
                SELECT
                    psi.product_set_id,
                    psi.quantity AS component_quantity,
                    p.id AS product_id,
                    p.name AS product_name,
                    COALESCE(p.sku, '') AS sku,
                    COALESCE(b.name, '') AS brand_name
                FROM product_set_items psi
                JOIN products p ON psi.product_id = p.id
                LEFT JOIN brands b ON b.id = p.brand_id
                WHERE psi.product_set_id IN ($placeholders)
            ");
            $stmtComponents->execute($setIds);
            foreach ($stmtComponents->fetchAll(PDO::FETCH_ASSOC) as $component) {
                $sid = (int)($component['product_set_id'] ?? 0);
                if ($sid > 0) {
                    $componentsBySetId[$sid][] = $component;
                }
            }
        }
    }

    $expanded = [];
    foreach ($items as $row) {
        $qty = (float)($row['quantity'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $monthYear = substr((string)($row['sale_date'] ?? ''), 0, 7);
        $isSet = strtolower((string)($row['product_type'] ?? 'normal')) === 'set';

        if ($isSet) {
            $setId = $productSetIdsByName[(string)($row['product_name'] ?? '')] ?? 0;
            $components = $setId > 0 ? ($componentsBySetId[$setId] ?? []) : [];
            if ($components) {
                foreach ($components as $comp) {
                    $compQty = (float)($comp['component_quantity'] ?? 0);
                    if ($compQty <= 0) {
                        continue;
                    }
                    $expanded[] = [
                        'product_id' => (int)$comp['product_id'],
                        'product_name' => (string)$comp['product_name'],
                        'sku' => (string)($comp['sku'] ?? ''),
                        'brand_name' => (string)($comp['brand_name'] ?? ''),
                        'quantity' => $qty * $compQty,
                        'month_year' => $monthYear,
                    ];
                }
                continue;
            }
        }

        $expanded[] = [
            'product_id' => (int)($row['product_id'] ?? 0),
            'product_name' => (string)($row['product_name'] ?? ''),
            'sku' => (string)($row['sku'] ?? ''),
            'brand_name' => (string)($row['brand_name'] ?? ''),
            'quantity' => $qty,
            'month_year' => $monthYear,
        ];
    }

    return $expanded;
}

try {
    require_role_or_permission(
        ['admin'],
        'sr_income_statement.view',
        'finance_reports.view'
    );

    $pdo = get_db_connection();

    // Determine date range and period type
    $periodType = strtolower(trim((string)($_GET['period_type'] ?? '')));
    $yearParam = trim((string)($_GET['year'] ?? ''));
    $monthParam = trim((string)($_GET['month'] ?? ''));
    $fromParam = trim((string)($_GET['from'] ?? $_GET['from_date'] ?? ''));
    $toParam = trim((string)($_GET['to'] ?? $_GET['to_date'] ?? ''));

    if (!$periodType) {
        if ($fromParam !== '' || $toParam !== '') {
            $periodType = 'custom';
        } elseif ($monthParam !== '') {
            $periodType = 'monthly';
        } elseif ($yearParam !== '') {
            $periodType = 'yearly';
        } else {
            $periodType = 'monthly';
        }
    }

    $today = date('Y-m-d');
    $currentMonth = date('Y-m');
    $currentYear = date('Y');

    if ($periodType === 'yearly') {
        $year = preg_match('/^\d{4}$/', $yearParam) ? $yearParam : $currentYear;
        $from = "{$year}-01-01";
        $to = "{$year}-12-31";
        $periodLabelEn = "Year {$year}";
        $periodLabelKh = "ប្រចាំឆ្នាំ {$year}";
    } elseif ($periodType === 'monthly') {
        $month = preg_match('/^\d{4}-\d{2}$/', $monthParam) ? $monthParam : $currentMonth;
        $from = "{$month}-01";
        $to = date('Y-m-t', strtotime($from));
        $periodLabelEn = date('F Y', strtotime($from));
        $periodLabelKh = "ខែ " . date('m/Y', strtotime($from));
    } else {
        $periodType = 'custom';
        $from = is_report_date($_GET['from'] ?? $_GET['from_date'] ?? null, date('Y-m-01'));
        $to = is_report_date($_GET['to'] ?? $_GET['to_date'] ?? null, $today);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $periodLabelEn = "{$from} to {$to}";
        $periodLabelKh = "{$from} ដល់ {$to}";
    }

    // Active Exchange Rate (USD to KHR)
    $exchangeRate = 4100.0;
    if (api_table_exists($pdo, 'money_exchange')) {
        $rateStmt = $pdo->prepare("
            SELECT rate 
            FROM money_exchange 
            WHERE target_currency = 'KHR' 
              AND status = 'active' 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $rateStmt->execute();
        $dbRate = (float)$rateStmt->fetchColumn();
        if ($dbRate > 0) {
            $exchangeRate = $dbRate;
        }
    }
    if ($exchangeRate <= 0 && api_table_exists($pdo, 'settings')) {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'usd_to_khr_rate' LIMIT 1");
        $stmt->execute();
        $dbRate = (float)$stmt->fetchColumn();
        if ($dbRate > 0) {
            $exchangeRate = $dbRate;
        }
    }

    // Preload product_costs cache helper
    $hasProductCostsTable = api_table_exists($pdo, 'product_costs');
    $productCostCache = [];
    $getProductUnitCost = static function (int $productId, string $monthYear, string $channel) use ($pdo, $hasProductCostsTable, &$productCostCache): float {
        if (!$hasProductCostsTable || $productId <= 0 || $monthYear === '') {
            return 0.0;
        }
        $cacheKey = "{$productId}|{$monthYear}";
        if (!array_key_exists($cacheKey, $productCostCache)) {
            $cStmt = $pdo->prepare("
                SELECT
                    (
                        COALESCE(original_cost, 0)
                        + COALESCE(supplier_cost, 0)
                        + COALESCE(shipping_cost, 0)
                        + COALESCE(other_costs, 0)
                        + COALESCE(marketing_cost, 0)
                    ) AS base_cost,
                    COALESCE(commission_amount, 0) AS online_comm,
                    COALESCE(offline_commission_amount, 0) AS offline_comm,
                    COALESCE(dealer_commission_amount, 0) AS dealer_comm
                FROM product_costs
                WHERE product_id = ? AND month_year = ?
                LIMIT 1
            ");
            $cStmt->execute([$productId, $monthYear]);
            $productCostCache[$cacheKey] = $cStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $pc = $productCostCache[$cacheKey];
        if (!$pc) {
            return 0.0;
        }
        $base = (float)($pc['base_cost'] ?? 0);
        if ($channel === 'online') {
            return $base + (float)($pc['online_comm'] ?? 0);
        }
        if ($channel === 'offline') {
            return $base + (float)($pc['offline_comm'] ?? 0);
        }
        if ($channel === 'dealer') {
            return $base + (float)($pc['dealer_comm'] ?? 0);
        }
        return $base;
    };

    // Track missing product costs across all channels
    $missingCostsMap = [];
    $recordMissingCost = static function (array $item, string $channelLabel) use (&$missingCostsMap): void {
        $pid = (int)($item['product_id'] ?? 0);
        $m = (string)($item['month_year'] ?? '');
        if ($pid <= 0) {
            return;
        }
        $key = "{$pid}|{$m}";
        if (!isset($missingCostsMap[$key])) {
            $missingCostsMap[$key] = [
                'product_id' => $pid,
                'product_name' => (string)($item['product_name'] ?? "Product #{$pid}"),
                'sku' => (string)($item['sku'] ?? ''),
                'brand_name' => (string)($item['brand_name'] ?? ''),
                'month_year' => $m,
                'sold_qty' => 0.0,
                'channels' => [],
            ];
        }
        $missingCostsMap[$key]['sold_qty'] += (float)($item['quantity'] ?? 0);
        if (!in_array($channelLabel, $missingCostsMap[$key]['channels'], true)) {
            $missingCostsMap[$key]['channels'][] = $channelLabel;
        }
    };

    // 1. ONLINE CHANNEL
    $onlineOrdersCount = 0;
    $onlineGross = 0.0;
    $onlineDiscount = 0.0;
    $onlineRevenue = 0.0;
    $onlineCogs = 0.0;

    if (api_table_exists($pdo, 'orders')) {
        $onlineStmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT o.id) AS order_count,
                COALESCE(SUM(o.total_amount), 0) AS net_sales,
                COALESCE(SUM(o.discount), 0) AS discount
            FROM orders o
            INNER JOIN (
                SELECT order_id, MAX(printed_at) AS printed_at
                FROM print_jobs
                GROUP BY order_id
            ) pj ON pj.order_id = o.id
            WHERE COALESCE(o.is_cancelled, 0) = 0
              AND COALESCE(o.is_returned, 0) = 0
              AND DATE(pj.printed_at) BETWEEN ? AND ?
        ");
        $onlineStmt->execute([$from, $to]);
        $onlineData = $onlineStmt->fetch(PDO::FETCH_ASSOC);
        $onlineOrdersCount = (int)($onlineData['order_count'] ?? 0);
        $onlineRevenue = (float)($onlineData['net_sales'] ?? 0);
        $onlineDiscount = (float)($onlineData['discount'] ?? 0);
        $onlineGross = $onlineRevenue + $onlineDiscount;

        // Online COGS
        if (api_table_exists($pdo, 'order_items') && api_table_exists($pdo, 'products')) {
            $onlineItemsStmt = $pdo->prepare("
                SELECT
                    oi.product_id,
                    p.name AS product_name,
                    COALESCE(p.sku, '') AS sku,
                    COALESCE(b.name, '') AS brand_name,
                    COALESCE(p.product_type, 'normal') AS product_type,
                    oi.quantity,
                    DATE(pj.printed_at) AS sale_date
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                JOIN products p ON p.id = oi.product_id
                LEFT JOIN brands b ON b.id = p.brand_id
                INNER JOIN (
                    SELECT order_id, MAX(printed_at) AS printed_at
                    FROM print_jobs
                    GROUP BY order_id
                ) pj ON pj.order_id = o.id
                WHERE COALESCE(o.is_cancelled, 0) = 0
                  AND COALESCE(o.is_returned, 0) = 0
                  AND DATE(pj.printed_at) BETWEEN ? AND ?
            ");
            $onlineItemsStmt->execute([$from, $to]);
            $onlineExpanded = expand_sold_items_with_sets($pdo, $onlineItemsStmt->fetchAll(PDO::FETCH_ASSOC));

            foreach ($onlineExpanded as $item) {
                $unit = $getProductUnitCost((int)$item['product_id'], (string)$item['month_year'], 'online');
                if ($unit <= 0) {
                    $recordMissingCost($item, 'Online');
                } else {
                    $onlineCogs += (float)$item['quantity'] * $unit;
                }
            }
        }
    }

    // 2. OFFLINE CHANNEL
    $offlineOrdersCount = 0;
    $offlineGross = 0.0;
    $offlineDiscount = 0.0;
    $offlineBuyBack = 0.0;
    $offlineRevenue = 0.0;
    $offlineCogs = 0.0;

    if (api_table_exists($pdo, 'offline_sale_orders')) {
        $offlineStmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS order_count,
                COALESCE(SUM(total_amount), 0) AS net_sales,
                COALESCE(SUM(subtotal), 0) AS subtotal,
                COALESCE(SUM(discount), 0) AS discount,
                COALESCE(SUM(purchase_total), 0) AS buy_back
            FROM offline_sale_orders
            WHERE DATE(sale_date) BETWEEN ? AND ?
              AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'canceled')
        ");
        $offlineStmt->execute([$from, $to]);
        $offlineData = $offlineStmt->fetch(PDO::FETCH_ASSOC);
        $offlineOrdersCount = (int)($offlineData['order_count'] ?? 0);
        $offlineRevenue = (float)($offlineData['net_sales'] ?? 0);
        $offlineGross = (float)($offlineData['subtotal'] ?? 0);
        $offlineDiscount = (float)($offlineData['discount'] ?? 0);
        $offlineBuyBack = (float)($offlineData['buy_back'] ?? 0);

        // Offline COGS
        if (api_table_exists($pdo, 'offline_sale_order_items') && api_table_exists($pdo, 'products')) {
            $offlineItemsStmt = $pdo->prepare("
                SELECT
                    oi.product_id,
                    p.name AS product_name,
                    COALESCE(p.sku, '') AS sku,
                    COALESCE(b.name, '') AS brand_name,
                    COALESCE(p.product_type, 'normal') AS product_type,
                    oi.quantity,
                    DATE(o.sale_date) AS sale_date
                FROM offline_sale_order_items oi
                JOIN offline_sale_orders o ON o.id = oi.order_id
                JOIN products p ON p.id = oi.product_id
                LEFT JOIN brands b ON b.id = p.brand_id
                WHERE DATE(o.sale_date) BETWEEN ? AND ?
                  AND LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'canceled')
            ");
            $offlineItemsStmt->execute([$from, $to]);
            $offlineExpanded = expand_sold_items_with_sets($pdo, $offlineItemsStmt->fetchAll(PDO::FETCH_ASSOC));

            foreach ($offlineExpanded as $item) {
                $unit = $getProductUnitCost((int)$item['product_id'], (string)$item['month_year'], 'offline');
                if ($unit <= 0) {
                    $recordMissingCost($item, 'Offline');
                } else {
                    $offlineCogs += (float)$item['quantity'] * $unit;
                }
            }
        }
    }

    // 3. DEALER CHANNEL
    $dealerOrdersCount = 0;
    $dealerGross = 0.0;
    $dealerDiscount = 0.0;
    $dealerRevenue = 0.0;
    $dealerCogs = 0.0;

    if (api_table_exists($pdo, 'dealer_orders')) {
        $dealerStmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS order_count,
                COALESCE(SUM(grand_total), 0) AS net_sales,
                COALESCE(SUM(subtotal), 0) AS subtotal,
                COALESCE(SUM(discount_total), 0) AS discount
            FROM dealer_orders
            WHERE DATE(order_date) BETWEEN ? AND ?
              AND LOWER(COALESCE(status, '')) NOT IN ('draft', 'cancel', 'cancelled', 'canceled', 'return', 'returned')
        ");
        $dealerStmt->execute([$from, $to]);
        $dealerData = $dealerStmt->fetch(PDO::FETCH_ASSOC);
        $dealerOrdersCount = (int)($dealerData['order_count'] ?? 0);
        $dealerRevenue = (float)($dealerData['net_sales'] ?? 0);
        $dealerGross = (float)($dealerData['subtotal'] ?? 0);
        $dealerDiscount = (float)($dealerData['discount'] ?? 0);

        // Dealer COGS
        if (api_table_exists($pdo, 'dealer_order_items') && api_table_exists($pdo, 'products')) {
            $dealerItemsStmt = $pdo->prepare("
                SELECT
                    p.id AS product_id,
                    p.name AS product_name,
                    COALESCE(p.sku, '') AS sku,
                    COALESCE(b.name, '') AS brand_name,
                    COALESCE(p.product_type, 'normal') AS product_type,
                    oi.quantity,
                    DATE(o.order_date) AS sale_date
                FROM dealer_order_items oi
                JOIN dealer_orders o ON o.id = oi.order_id
                JOIN products p ON p.id = COALESCE(NULLIF(oi.movement_item_id, 0), CAST(oi.product_id AS UNSIGNED))
                LEFT JOIN brands b ON b.id = p.brand_id
                WHERE DATE(o.order_date) BETWEEN ? AND ?
                  AND LOWER(COALESCE(o.status, '')) NOT IN ('draft', 'cancel', 'cancelled', 'canceled', 'return', 'returned')
            ");
            $dealerItemsStmt->execute([$from, $to]);
            $dealerExpanded = expand_sold_items_with_sets($pdo, $dealerItemsStmt->fetchAll(PDO::FETCH_ASSOC));

            foreach ($dealerExpanded as $item) {
                $unit = $getProductUnitCost((int)$item['product_id'], (string)$item['month_year'], 'dealer');
                if ($unit <= 0) {
                    $recordMissingCost($item, 'Dealer');
                } else {
                    $dealerCogs += (float)$item['quantity'] * $unit;
                }
            }
        }
    }

    // 4. TOTAL EXPENSES (Consolidated Operating & Applicable Expenses)
    $totalExpenses = 0.0;
    $expenseCount = 0;
    if (api_table_exists($pdo, 'finance_spending')) {
        $expStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total_expenses, COUNT(*) AS count
            FROM finance_spending
            WHERE DATE(spending_date) BETWEEN ? AND ?
              AND (TRIM(COALESCE(status, '')) = '' OR LOWER(status) IN ('approved', 'completed'))
        ");
        $expStmt->execute([$from, $to]);
        $expData = $expStmt->fetch(PDO::FETCH_ASSOC);
        $totalExpenses = (float)($expData['total_expenses'] ?? 0);
        $expenseCount = (int)($expData['count'] ?? 0);
    }

    // PAYMENT STATUS BREAKDOWNS (Paid, Partial, Unpaid)
    // 1. Online Payment Status
    $onlinePaid = ['order_count' => 0, 'amount' => 0.0];
    $onlineUnpaid = ['order_count' => 0, 'amount' => 0.0];
    if (api_table_exists($pdo, 'orders')) {
        $onlineStatusStmt = $pdo->prepare("
            SELECT 
                LOWER(COALESCE(o.status, 'unpaid')) AS payment_status,
                COUNT(DISTINCT o.id) AS order_count,
                COALESCE(SUM(o.total_amount), 0) AS net_sales
            FROM orders o
            INNER JOIN (
                SELECT order_id, MAX(printed_at) AS printed_at
                FROM print_jobs
                GROUP BY order_id
            ) pj ON pj.order_id = o.id
            WHERE COALESCE(o.is_cancelled, 0) = 0
              AND COALESCE(o.is_returned, 0) = 0
              AND DATE(pj.printed_at) BETWEEN ? AND ?
            GROUP BY LOWER(COALESCE(o.status, 'unpaid'))
        ");
        $onlineStatusStmt->execute([$from, $to]);
        foreach ($onlineStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $sRow) {
            $st = strtolower(trim((string)$sRow['payment_status']));
            if ($st === 'paid') {
                $onlinePaid['order_count'] += (int)$sRow['order_count'];
                $onlinePaid['amount'] += (float)$sRow['net_sales'];
            } else {
                $onlineUnpaid['order_count'] += (int)$sRow['order_count'];
                $onlineUnpaid['amount'] += (float)$sRow['net_sales'];
            }
        }
    }

    // 2. Offline Payment Status
    $offlinePaid = ['order_count' => 0, 'amount' => 0.0];
    $offlinePartial = ['order_count' => 0, 'amount' => 0.0, 'received' => 0.0, 'balance' => 0.0];
    $offlineUnpaid = ['order_count' => 0, 'amount' => 0.0];
    if (api_table_exists($pdo, 'offline_sale_orders')) {
        $offlineStatusStmt = $pdo->prepare("
            SELECT 
                LOWER(COALESCE(status, 'unpaid')) AS payment_status,
                COUNT(*) AS order_count,
                COALESCE(SUM(total_amount), 0) AS net_sales,
                COALESCE(SUM(received_amount), 0) AS received_amount
            FROM offline_sale_orders
            WHERE DATE(sale_date) BETWEEN ? AND ?
              AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'canceled')
            GROUP BY LOWER(COALESCE(status, 'unpaid'))
        ");
        $offlineStatusStmt->execute([$from, $to]);
        foreach ($offlineStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $sRow) {
            $st = strtolower(trim((string)$sRow['payment_status']));
            $amt = (float)$sRow['net_sales'];
            $rec = (float)$sRow['received_amount'];
            if ($st === 'paid') {
                $offlinePaid['order_count'] += (int)$sRow['order_count'];
                $offlinePaid['amount'] += $amt;
            } elseif ($st === 'partial') {
                $offlinePartial['order_count'] += (int)$sRow['order_count'];
                $offlinePartial['amount'] += $amt;
                $offlinePartial['received'] += $rec;
                $offlinePartial['balance'] += max(0.0, $amt - $rec);
            } else {
                $offlineUnpaid['order_count'] += (int)$sRow['order_count'];
                $offlineUnpaid['amount'] += $amt;
            }
        }
    }

    // 3. Dealer Payment Status
    $dealerPaid = ['order_count' => 0, 'amount' => 0.0];
    $dealerPartial = ['order_count' => 0, 'amount' => 0.0, 'received' => 0.0, 'balance' => 0.0];
    $dealerUnpaid = ['order_count' => 0, 'amount' => 0.0];
    if (api_table_exists($pdo, 'dealer_orders')) {
        $dealerStatusStmt = $pdo->prepare("
            SELECT 
                LOWER(COALESCE(payment_status, 'unpaid')) AS payment_status,
                COUNT(*) AS order_count,
                COALESCE(SUM(grand_total), 0) AS net_sales,
                COALESCE(SUM(paid_amount), 0) AS paid_amount,
                COALESCE(SUM(balance_amount), 0) AS balance_amount
            FROM dealer_orders
            WHERE DATE(order_date) BETWEEN ? AND ?
              AND LOWER(COALESCE(status, '')) NOT IN ('draft', 'cancel', 'cancelled', 'canceled', 'return', 'returned')
            GROUP BY LOWER(COALESCE(payment_status, 'unpaid'))
        ");
        $dealerStatusStmt->execute([$from, $to]);
        foreach ($dealerStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $sRow) {
            $st = strtolower(trim((string)$sRow['payment_status']));
            $amt = (float)$sRow['net_sales'];
            $paid = (float)$sRow['paid_amount'];
            $bal = (float)$sRow['balance_amount'];
            if ($st === 'paid') {
                $dealerPaid['order_count'] += (int)$sRow['order_count'];
                $dealerPaid['amount'] += $amt;
            } elseif ($st === 'partial') {
                $dealerPartial['order_count'] += (int)$sRow['order_count'];
                $dealerPartial['amount'] += $amt;
                $dealerPartial['received'] += $paid;
                $dealerPartial['balance'] += $bal;
            } else {
                $dealerUnpaid['order_count'] += (int)$sRow['order_count'];
                $dealerUnpaid['amount'] += $amt;
            }
        }
    }

    $totalPaidAmount = $onlinePaid['amount'] + $offlinePaid['amount'] + $dealerPaid['amount'];
    $totalPaidCount = $onlinePaid['order_count'] + $offlinePaid['order_count'] + $dealerPaid['order_count'];

    $totalPartialAmount = $offlinePartial['amount'] + $dealerPartial['amount'];
    $totalPartialReceived = $offlinePartial['received'] + $dealerPartial['received'];
    $totalPartialBalance = $offlinePartial['balance'] + $dealerPartial['balance'];
    $totalPartialCount = $offlinePartial['order_count'] + $dealerPartial['order_count'];

    $totalUnpaidAmount = $onlineUnpaid['amount'] + $offlineUnpaid['amount'] + $dealerUnpaid['amount'];
    $totalUnpaidCount = $onlineUnpaid['order_count'] + $offlineUnpaid['order_count'] + $dealerUnpaid['order_count'];

    // GROSS PROFITS
    $onlineGrossProfit = $onlineRevenue - $onlineCogs;
    $offlineGrossProfit = $offlineRevenue - $offlineCogs;
    $dealerGrossProfit = $dealerRevenue - $dealerCogs;

    // Channel Filter
    $channel = strtolower(trim((string)($_GET['channel'] ?? 'all')));
    if (!in_array($channel, ['all', 'online', 'offline', 'dealer'], true)) {
        $channel = 'all';
    }

    // CONSOLIDATED TOTALS ACCORDING TO SELECTED CHANNEL
    if ($channel === 'online') {
        $filteredRevenue = $onlineRevenue;
        $filteredCogs = $onlineCogs;
        $filteredGrossProfit = $onlineGrossProfit;
        $filteredOrderCount = $onlineOrdersCount;
    } elseif ($channel === 'offline') {
        $filteredRevenue = $offlineRevenue;
        $filteredCogs = $offlineCogs;
        $filteredGrossProfit = $offlineGrossProfit;
        $filteredOrderCount = $offlineOrdersCount;
    } elseif ($channel === 'dealer') {
        $filteredRevenue = $dealerRevenue;
        $filteredCogs = $dealerCogs;
        $filteredGrossProfit = $dealerGrossProfit;
        $filteredOrderCount = $dealerOrdersCount;
    } else {
        $filteredRevenue = $onlineRevenue + $offlineRevenue + $dealerRevenue;
        $filteredCogs = $onlineCogs + $offlineCogs + $dealerCogs;
        $filteredGrossProfit = $filteredRevenue - $filteredCogs;
        $filteredOrderCount = $onlineOrdersCount + $offlineOrdersCount + $dealerOrdersCount;
    }

    $filteredNetProfit = $filteredGrossProfit - $totalExpenses;

    // Sort missing cost products by month_year DESC, sold_qty DESC, product_name ASC
    $missingCostProducts = array_values($missingCostsMap);
    usort($missingCostProducts, static function (array $a, array $b): int {
        $cmpMonth = strcmp((string)$b['month_year'], (string)$a['month_year']);
        if ($cmpMonth !== 0) {
            return $cmpMonth;
        }
        if ((float)$b['sold_qty'] !== (float)$a['sold_qty']) {
            return (float)$b['sold_qty'] <=> (float)$a['sold_qty'];
        }
        return strcmp((string)$a['product_name'], (string)$b['product_name']);
    });

    // Side-by-side KHR conversion
    $toKhr = static fn(float $usd): float => round($usd * $exchangeRate);

    $revenueBreakdown = [
        'total_revenue' => $filteredRevenue,
        'total_revenue_khr' => $toKhr($filteredRevenue),
        'by_channel' => [
            [
                'id' => 'online',
                'label_kh' => 'អនឡាញ',
                'label_en' => 'Online',
                'order_count' => $onlineOrdersCount,
                'revenue_usd' => $onlineRevenue,
                'revenue_khr' => $toKhr($onlineRevenue),
                'paid_usd' => $onlinePaid['amount'],
                'partial_usd' => 0.0,
                'unpaid_usd' => $onlineUnpaid['amount'],
            ],
            [
                'id' => 'offline',
                'label_kh' => 'ហាងផ្ទាល់',
                'label_en' => 'Offline Store',
                'order_count' => $offlineOrdersCount,
                'revenue_usd' => $offlineRevenue,
                'revenue_khr' => $toKhr($offlineRevenue),
                'paid_usd' => $offlinePaid['amount'],
                'partial_usd' => $offlinePartial['amount'],
                'unpaid_usd' => $offlineUnpaid['amount'],
            ],
            [
                'id' => 'dealer',
                'label_kh' => 'តំណាងចែកចាយ',
                'label_en' => 'Dealer',
                'order_count' => $dealerOrdersCount,
                'revenue_usd' => $dealerRevenue,
                'revenue_khr' => $toKhr($dealerRevenue),
                'paid_usd' => $dealerPaid['amount'],
                'partial_usd' => $dealerPartial['amount'],
                'unpaid_usd' => $dealerUnpaid['amount'],
            ],
        ],
        'by_payment_status' => [
            [
                'id' => 'paid',
                'label_kh' => 'បានបង់រួច',
                'label_en' => 'Paid',
                'badge' => 'paid',
                'order_count' => $totalPaidCount,
                'amount_usd' => $totalPaidAmount,
                'amount_khr' => $toKhr($totalPaidAmount),
                'online_usd' => $onlinePaid['amount'],
                'offline_usd' => $offlinePaid['amount'],
                'dealer_usd' => $dealerPaid['amount'],
            ],
            [
                'id' => 'partial',
                'label_kh' => 'បង់មួយផ្នែក',
                'label_en' => 'Partial',
                'badge' => 'partial',
                'order_count' => $totalPartialCount,
                'amount_usd' => $totalPartialAmount,
                'amount_khr' => $toKhr($totalPartialAmount),
                'received_usd' => $totalPartialReceived,
                'balance_usd' => $totalPartialBalance,
                'online_usd' => 0.0,
                'offline_usd' => $offlinePartial['amount'],
                'dealer_usd' => $dealerPartial['amount'],
            ],
            [
                'id' => 'unpaid',
                'label_kh' => 'មិនទាន់បង់',
                'label_en' => 'Unpaid',
                'badge' => 'unpaid',
                'order_count' => $totalUnpaidCount,
                'amount_usd' => $totalUnpaidAmount,
                'amount_khr' => $toKhr($totalUnpaidAmount),
                'online_usd' => $onlineUnpaid['amount'],
                'offline_usd' => $offlineUnpaid['amount'],
                'dealer_usd' => $dealerUnpaid['amount'],
            ],
        ],
    ];

    // EXACT 5 ROWS REQUIRED:
    // 1. Revenue
    // 2. COGS
    // 3. Gross Profit
    // 4. Total Expenses
    // 5. Net Profit / (Loss)
    $statementRows = [
        [
            'id' => 'revenue',
            'no' => 1,
            'label' => 'Revenue',
            'label_kh' => 'ចំណូល',
            'online' => $onlineRevenue,
            'offline' => $offlineRevenue,
            'dealer' => $dealerRevenue,
            'total_usd' => $filteredRevenue,
            'total_khr' => $toKhr($filteredRevenue),
            'kind' => 'revenue',
            'breakdown' => $revenueBreakdown,
        ],
        [
            'id' => 'cogs',
            'no' => 2,
            'label' => 'Cost of Goods Sold (COGS)',
            'label_kh' => 'ថ្លៃដើមទំនិញលក់',
            'online' => $onlineCogs,
            'offline' => $offlineCogs,
            'dealer' => $dealerCogs,
            'total_usd' => $filteredCogs,
            'total_khr' => $toKhr($filteredCogs),
            'kind' => 'cogs',
        ],
        [
            'id' => 'gross_profit',
            'no' => 3,
            'label' => 'Gross Profit',
            'label_kh' => 'ប្រាក់ចំណេញដុល',
            'online' => $onlineGrossProfit,
            'offline' => $offlineGrossProfit,
            'dealer' => $dealerGrossProfit,
            'total_usd' => $filteredGrossProfit,
            'total_khr' => $toKhr($filteredGrossProfit),
            'kind' => 'gross_profit',
        ],
        [
            'id' => 'total_expenses',
            'no' => 4,
            'label' => 'Total Expenses',
            'label_kh' => 'ចំណាយសរុប',
            'online' => null,
            'offline' => null,
            'dealer' => null,
            'total_usd' => $totalExpenses,
            'total_khr' => $toKhr($totalExpenses),
            'kind' => 'total_expenses',
        ],
        [
            'id' => 'net_profit',
            'no' => 5,
            'label' => 'Net Profit / (Loss)',
            'label_kh' => 'ប្រាក់ចំណេញសុទ្ធ / (ខាត)',
            'online' => null,
            'offline' => null,
            'dealer' => null,
            'total_usd' => $filteredNetProfit,
            'total_khr' => $toKhr($filteredNetProfit),
            'kind' => $filteredNetProfit >= 0 ? 'net_positive' : 'net_negative',
        ],
    ];

    api_json([
        'success' => true,
        'channel' => $channel,
        'period' => [
            'type' => $periodType,
            'from' => $from,
            'to' => $to,
            'label_en' => $periodLabelEn,
            'label_kh' => $periodLabelKh,
        ],
        'exchange_rate' => $exchangeRate,
        'summary' => [
            'total_revenue' => $filteredRevenue,
            'total_revenue_khr' => $toKhr($filteredRevenue),
            'total_cogs' => $filteredCogs,
            'total_cogs_khr' => $toKhr($filteredCogs),
            'gross_profit' => $filteredGrossProfit,
            'gross_profit_khr' => $toKhr($filteredGrossProfit),
            'total_expenses' => $totalExpenses,
            'total_expenses_khr' => $toKhr($totalExpenses),
            'net_profit' => $filteredNetProfit,
            'net_profit_khr' => $toKhr($filteredNetProfit),
            'gross_margin' => $filteredRevenue > 0 ? round(($filteredGrossProfit / $filteredRevenue) * 100, 2) : 0,
            'net_margin' => $filteredRevenue > 0 ? round(($filteredNetProfit / $filteredRevenue) * 100, 2) : 0,
            'order_count' => $filteredOrderCount,
            'expense_count' => $expenseCount,
            'missing_cost_count' => count($missingCostProducts),
        ],
        'missing_cost_products' => $missingCostProducts,
        'channels' => [
            'online' => [
                'order_count' => $onlineOrdersCount,
                'revenue' => $onlineRevenue,
                'cogs' => $onlineCogs,
                'gross_profit' => $onlineGrossProfit,
            ],
            'offline' => [
                'order_count' => $offlineOrdersCount,
                'revenue' => $offlineRevenue,
                'cogs' => $offlineCogs,
                'gross_profit' => $offlineGrossProfit,
            ],
            'dealer' => [
                'order_count' => $dealerOrdersCount,
                'revenue' => $dealerRevenue,
                'cogs' => $dealerCogs,
                'gross_profit' => $dealerGrossProfit,
            ],
        ],
        'statement_rows' => $statementRows,
        'revenue_breakdown' => $revenueBreakdown,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}
