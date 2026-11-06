<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function bs_is_date(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

function bs_format_khmer_date(string $dateStr): string
{
    $time = strtotime($dateStr);
    if (!$time) {
        return $dateStr;
    }
    $day = date('d', $time);
    $monthNum = (int)date('m', $time);
    $year = date('Y', $time);

    $khmerMonths = [
        1 => 'មករា',
        2 => 'កុម្ភៈ',
        3 => 'មីនា',
        4 => 'មេសា',
        5 => 'ឧសភា',
        6 => 'មិថុនា',
        7 => 'កក្កដា',
        8 => 'សីហា',
        9 => 'កញ្ញា',
        10 => 'តុលា',
        11 => 'វិច្ឆិកា',
        12 => 'ធ្នូ',
    ];

    $monthName = $khmerMonths[$monthNum] ?? date('m', $time);
    return "ថ្ងៃទី {$day} ខែ{$monthName} ឆ្នាំ {$year}";
}

try {
    require_role_or_permission(
        ['admin'],
        'sr_balance_sheet.view',
        'sr_income_statement.view',
        'finance_reports.view'
    );

    $pdo = get_db_connection();

    // Determine period parameters matching Income Statement
    $periodType = strtolower(trim((string)($_GET['period_type'] ?? '')));
    $yearParam = trim((string)($_GET['year'] ?? ''));
    $monthParam = trim((string)($_GET['month'] ?? ''));
    $fromParam = trim((string)($_GET['from'] ?? $_GET['from_date'] ?? ''));
    $toParam = trim((string)($_GET['to'] ?? $_GET['to_date'] ?? ''));
    $asOfParam = trim((string)($_GET['as_of'] ?? $_GET['as_of_date'] ?? ''));

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
        $from = bs_is_date($fromParam, date('Y-m-01'));
        $to = bs_is_date($toParam, $today);
        if ($from > $to) {
            $temp = $from;
            $from = $to;
            $to = $temp;
        }
        $periodLabelEn = "{$from} to {$to}";
        $periodLabelKh = "{$from} ដល់ {$to}";
    }

    // As of date: default to $to date
    $asOfDate = bs_is_date($asOfParam, $to);
    $asOfLabelEn = "As of " . date('F j, Y', strtotime($asOfDate));
    $asOfLabelKh = "គិតត្រឹម" . bs_format_khmer_date($asOfDate);
    $asOfMonthYear = substr($asOfDate, 0, 7);

    // Active Exchange Rate
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

    $toKhr = static fn(float $usd): float => round($usd * $exchangeRate, 2);

    // ==========================================
    // SECTION A. ASSETS – ទ្រព្យសកម្ម
    // ==========================================

    // 1. Inventory Valuation as of $asOfDate
    $inventoryUsd = 0.0;
    $hasEom = api_table_exists($pdo, 'eom_stock_reports') && api_table_exists($pdo, 'eom_stock_report_details');
    $eomReportId = 0;

    if ($hasEom) {
        $eomStmt = $pdo->prepare("
            SELECT id 
            FROM eom_stock_reports 
            WHERE report_date <= ? AND LOWER(status) = 'finalized' 
            ORDER BY report_date DESC 
            LIMIT 1
        ");
        $eomStmt->execute([$asOfDate]);
        $eomReportId = (int)$eomStmt->fetchColumn();
    }

    if ($eomReportId > 0) {
        $invDetailsStmt = $pdo->prepare("
            SELECT 
                d.sku,
                d.item_name,
                COALESCE(d.final_quantity, d.closing_quantity, 0) AS qty,
                p.id AS product_id,
                COALESCE(
                    (SELECT (COALESCE(pc.original_cost, 0) + COALESCE(pc.supplier_cost, 0) + COALESCE(pc.shipping_cost, 0) + COALESCE(pc.other_costs, 0) + COALESCE(pc.marketing_cost, 0))
                     FROM product_costs pc WHERE pc.product_id = p.id AND pc.month_year <= ? ORDER BY pc.month_year DESC LIMIT 1),
                    0
                ) AS unit_cost
            FROM eom_stock_report_details d
            LEFT JOIN products p ON (p.sku = d.sku OR p.name = d.item_name)
            WHERE d.eom_report_id = ?
        ");
        $invDetailsStmt->execute([$asOfMonthYear, $eomReportId]);
        foreach ($invDetailsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qty = (float)($row['qty'] ?? 0);
            $cost = (float)($row['unit_cost'] ?? 0);
            if ($qty > 0 && $cost > 0) {
                $inventoryUsd += ($qty * $cost);
            }
        }
    } elseif (api_table_exists($pdo, 'current_inventory')) {
        $ciStmt = $pdo->prepare("
            SELECT 
                ci.product_id,
                ci.quantity_on_hand AS qty,
                COALESCE(
                    (SELECT (COALESCE(pc.original_cost, 0) + COALESCE(pc.supplier_cost, 0) + COALESCE(pc.shipping_cost, 0) + COALESCE(pc.other_costs, 0) + COALESCE(pc.marketing_cost, 0))
                     FROM product_costs pc WHERE pc.product_id = ci.product_id AND pc.month_year <= ? ORDER BY pc.month_year DESC LIMIT 1),
                    0
                ) AS unit_cost
            FROM current_inventory ci
        ");
        $ciStmt->execute([$asOfMonthYear]);
        foreach ($ciStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qty = (float)($row['qty'] ?? 0);
            $cost = (float)($row['unit_cost'] ?? 0);
            if ($qty > 0 && $cost > 0) {
                $inventoryUsd += ($qty * $cost);
            }
        }
    }
    $inventoryUsd = round($inventoryUsd, 2);

    // 2. Online Receivables (unpaid & partial orders from Online)
    $onlineReceivablesUsd = 0.0;
    if (api_table_exists($pdo, 'orders')) {
        $onlineRecStmt = $pdo->prepare("
            SELECT COALESCE(SUM(o.total_amount), 0)
            FROM orders o
            WHERE DATE(o.created_at) <= ?
              AND COALESCE(o.is_cancelled, 0) = 0
              AND COALESCE(o.is_returned, 0) = 0
              AND (COALESCE(o.is_paid, 0) = 0 OR LOWER(COALESCE(o.status, '')) != 'paid')
        ");
        $onlineRecStmt->execute([$asOfDate]);
        $onlineReceivablesUsd = round((float)$onlineRecStmt->fetchColumn(), 2);
    }

    // 3. Offline Receivables (unpaid & partial orders from Offline)
    $offlineReceivablesUsd = 0.0;
    if (api_table_exists($pdo, 'offline_sale_orders')) {
        $offlineRecStmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount - COALESCE(received_amount, 0)), 0)
            FROM offline_sale_orders
            WHERE DATE(sale_date) <= ?
              AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'canceled')
              AND total_amount > COALESCE(received_amount, 0)
        ");
        $offlineRecStmt->execute([$asOfDate]);
        $offlineReceivablesUsd = round((float)$offlineRecStmt->fetchColumn(), 2);
    }

    // 4. Dealer Receivables (unpaid & partial orders from dealer_orders)
    $dealerReceivablesUsd = 0.0;
    if (api_table_exists($pdo, 'dealer_orders')) {
        $dealerRecStmt = $pdo->prepare("
            SELECT COALESCE(SUM(grand_total - COALESCE(paid_amount, 0)), 0)
            FROM dealer_orders
            WHERE DATE(order_date) <= ?
              AND LOWER(COALESCE(status, '')) NOT IN ('draft', 'cancel', 'cancelled', 'canceled', 'return', 'returned')
              AND grand_total > COALESCE(paid_amount, 0)
        ");
        $dealerRecStmt->execute([$asOfDate]);
        $dealerReceivablesUsd = round((float)$dealerRecStmt->fetchColumn(), 2);
    }

    // 5. Cash (cash and bank balances as of date)
    $cashUsd = 0.0;
    if (api_table_exists($pdo, 'cashflow_bank_balances')) {
        $cashStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM cashflow_bank_balances
            WHERE balance_date = (
                SELECT MAX(balance_date) FROM cashflow_bank_balances WHERE balance_date <= ?
            )
            AND balance_type = 'ending'
        ");
        $cashStmt->execute([$asOfDate]);
        $cashUsd = round((float)$cashStmt->fetchColumn(), 2);
    }

    // 6. Current Assets (subtotal)
    $currentAssetsUsd = round($inventoryUsd + $onlineReceivablesUsd + $offlineReceivablesUsd + $dealerReceivablesUsd + $cashUsd, 2);

    // 7. TOTAL ASSETS
    $totalAssetsUsd = $currentAssetsUsd;

    // ==========================================
    // SECTION B. LIABILITIES – បំណុល
    // ==========================================

    // 1. Supplier Payables (unpaid/partial purchase_orders as of date)
    $supplierPayablesUsd = 0.0;
    if (api_table_exists($pdo, 'purchase_orders')) {
        $poStmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount - COALESCE(total_paid, 0)), 0)
            FROM purchase_orders
            WHERE DATE(order_date) <= ?
              AND LOWER(COALESCE(status, '')) != 'cancelled'
              AND (LOWER(COALESCE(payment_status, '')) != 'paid' OR total_amount > COALESCE(total_paid, 0))
        ");
        $poStmt->execute([$asOfDate]);
        $supplierPayablesUsd = round((float)$poStmt->fetchColumn(), 2);
    }

    // 2. TOTAL LIABILITIES
    $totalLiabilitiesUsd = round($supplierPayablesUsd, 2);

    // ==========================================
    // SECTION C. EQUITY – មូលធន
    // ==========================================

    // 1. Owner's Capital (from capital contributions)
    $ownersCapitalUsd = 0.0;
    if (api_table_exists($pdo, 'finance_topups')) {
        $capStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM finance_topups
            WHERE (LOWER(source) LIKE '%capital%' OR LOWER(description) LIKE '%capital%' OR LOWER(source) LIKE '%boost%')
              AND DATE(topup_date) <= ?
              AND LOWER(status) = 'completed'
        ");
        $capStmt->execute([$asOfDate]);
        $ownersCapitalUsd = round((float)$capStmt->fetchColumn(), 2);
    }
    if (api_table_exists($pdo, 'app_settings')) {
        $settingStmt = $pdo->prepare("SELECT value FROM app_settings WHERE `key` = 'owners_capital' LIMIT 1");
        $settingStmt->execute();
        $overrideVal = $settingStmt->fetchColumn();
        if ($overrideVal !== false && is_numeric($overrideVal)) {
            $ownersCapitalUsd = round((float)$overrideVal, 2);
        }
    }

    // 2. Retained Earnings (from historical accounting periods prior to reporting year)
    $retainedEarningsUsd = 0.0;
    $asOfYear = (int)substr($asOfDate, 0, 4);
    $priorYearEnd = ($asOfYear - 1) . "-12-31";
    // If prior year records exist, aggregate net income up to priorYearEnd
    if (api_table_exists($pdo, 'app_settings')) {
        $settingStmt = $pdo->prepare("SELECT value FROM app_settings WHERE `key` = 'retained_earnings' LIMIT 1");
        $settingStmt->execute();
        $overrideVal = $settingStmt->fetchColumn();
        if ($overrideVal !== false && is_numeric($overrideVal)) {
            $retainedEarningsUsd = round((float)$overrideVal, 2);
        }
    }

    // 3. Current Net Profit / (Loss) (from the Income Statement for current period)
    // Reuse Income Statement calculation for from..to
    $currentNetProfitUsd = 0.0;
    $periodRevenueUsd = 0.0;
    $periodGrossProfitUsd = 0.0;
    $periodExpensesUsd = 0.0;

    // Fast calculation of period figures matching income_statement.php
    // Revenue:
    $onlineRev = 0.0;
    if (api_table_exists($pdo, 'orders')) {
        $s = $pdo->prepare("
            SELECT COALESCE(SUM(o.total_amount), 0)
            FROM orders o
            INNER JOIN (SELECT order_id, MAX(printed_at) AS printed_at FROM print_jobs GROUP BY order_id) pj ON pj.order_id = o.id
            WHERE COALESCE(o.is_cancelled, 0) = 0 AND COALESCE(o.is_returned, 0) = 0 AND DATE(pj.printed_at) BETWEEN ? AND ?
        ");
        $s->execute([$from, $to]);
        $onlineRev = (float)$s->fetchColumn();
    }

    $offlineRev = 0.0;
    if (api_table_exists($pdo, 'offline_sale_orders')) {
        $s = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM offline_sale_orders WHERE DATE(sale_date) BETWEEN ? AND ? AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'canceled')");
        $s->execute([$from, $to]);
        $offlineRev = (float)$s->fetchColumn();
    }

    $dealerRev = 0.0;
    if (api_table_exists($pdo, 'dealer_orders')) {
        $s = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM dealer_orders WHERE DATE(order_date) BETWEEN ? AND ? AND LOWER(COALESCE(status, '')) NOT IN ('draft', 'cancel', 'cancelled', 'canceled', 'return', 'returned')");
        $s->execute([$from, $to]);
        $dealerRev = (float)$s->fetchColumn();
    }

    $periodRevenueUsd = round($onlineRev + $offlineRev + $dealerRev, 2);

    // COGS:
    $hasProductCosts = api_table_exists($pdo, 'product_costs');
    $cogsTotal = 0.0;
    if ($hasProductCosts) {
        // Online items
        $s = $pdo->prepare("
            SELECT oi.product_id, oi.quantity, DATE(pj.printed_at) AS sale_date
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN (SELECT order_id, MAX(printed_at) AS printed_at FROM print_jobs GROUP BY order_id) pj ON pj.order_id = o.id
            WHERE COALESCE(o.is_cancelled, 0) = 0 AND COALESCE(o.is_returned, 0) = 0 AND DATE(pj.printed_at) BETWEEN ? AND ?
        ");
        $s->execute([$from, $to]);
        $onlineItems = $s->fetchAll(PDO::FETCH_ASSOC);

        // Dealer items
        $s = $pdo->prepare("
            SELECT oi.product_id, oi.quantity, DATE(o.order_date) AS sale_date
            FROM dealer_order_items oi
            JOIN dealer_orders o ON o.id = oi.order_id
            WHERE DATE(o.order_date) BETWEEN ? AND ? AND LOWER(COALESCE(o.status, '')) NOT IN ('draft', 'cancel', 'cancelled', 'canceled', 'return', 'returned')
        ");
        $s->execute([$from, $to]);
        $dealerItems = $s->fetchAll(PDO::FETCH_ASSOC);

        $costMap = [];
        $costStmt = $pdo->query("
            SELECT product_id, month_year,
                   (COALESCE(original_cost,0)+COALESCE(supplier_cost,0)+COALESCE(shipping_cost,0)+COALESCE(other_costs,0)+COALESCE(marketing_cost,0)) AS base_cost,
                   COALESCE(commission_amount,0) AS online_comm,
                   COALESCE(dealer_commission_amount,0) AS dealer_comm
            FROM product_costs
        ");
        foreach ($costStmt->fetchAll(PDO::FETCH_ASSOC) as $crow) {
            $k = "{$crow['product_id']}|{$crow['month_year']}";
            $costMap[$k] = $crow;
        }

        foreach ($onlineItems as $it) {
            $m = substr((string)$it['sale_date'], 0, 7);
            $k = "{$it['product_id']}|{$m}";
            $c = $costMap[$k] ?? null;
            if ($c) {
                $cogsTotal += ((float)$c['base_cost'] + (float)$c['online_comm']) * (float)$it['quantity'];
            }
        }
        foreach ($dealerItems as $it) {
            $m = substr((string)$it['sale_date'], 0, 7);
            $k = "{$it['product_id']}|{$m}";
            $c = $costMap[$k] ?? null;
            if ($c) {
                $cogsTotal += ((float)$c['base_cost'] + (float)$c['dealer_comm']) * (float)$it['quantity'];
            }
        }
    }
    $periodCogsUsd = round($cogsTotal, 2);
    $periodGrossProfitUsd = round($periodRevenueUsd - $periodCogsUsd, 2);

    // Expenses:
    if (api_table_exists($pdo, 'finance_spending')) {
        $expStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM finance_spending
            WHERE DATE(spending_date) BETWEEN ? AND ?
              AND (TRIM(COALESCE(status, '')) = '' OR LOWER(status) IN ('approved', 'completed'))
        ");
        $expStmt->execute([$from, $to]);
        $periodExpensesUsd = round((float)$expStmt->fetchColumn(), 2);
    }

    $currentNetProfitUsd = round($periodGrossProfitUsd - $periodExpensesUsd, 2);

    // 4. TOTAL EQUITY
    $totalEquityUsd = round($ownersCapitalUsd + $retainedEarningsUsd + $currentNetProfitUsd, 2);

    // ==========================================
    // SECTION 2. BALANCE SHEET RECONCILIATION
    // ==========================================
    // Accounting Equation: Total Assets = Total Liabilities + Total Equity
    // Balance Difference = Total Assets - Total Liabilities - Total Equity
    $balanceDifferenceUsd = round($totalAssetsUsd - ($totalLiabilitiesUsd + $totalEquityUsd), 2);
    $isBalanced = abs($balanceDifferenceUsd) < 0.01;

    // Structured row definitions for the Balance Sheet view
    $assetsRows = [
        [
            'id' => 'inventory',
            'no' => 1,
            'label' => 'Inventory',
            'label_kh' => 'ស្តុកទំនិញ',
            'usd' => $inventoryUsd,
            'khr' => $toKhr($inventoryUsd),
            'is_subtotal' => false,
            'is_total' => false,
        ],
        [
            'id' => 'online_receivables',
            'no' => 2,
            'label' => 'Online Receivables',
            'label_kh' => 'បំណុលត្រូវទារពីការលក់ Online',
            'usd' => $onlineReceivablesUsd,
            'khr' => $toKhr($onlineReceivablesUsd),
            'is_subtotal' => false,
            'is_total' => false,
        ],
        [
            'id' => 'offline_receivables',
            'no' => 3,
            'label' => 'Offline Receivables',
            'label_kh' => 'បំណុលត្រូវទារពីការលក់ Offline',
            'usd' => $offlineReceivablesUsd,
            'khr' => $toKhr($offlineReceivablesUsd),
            'is_subtotal' => false,
            'is_total' => false,
        ],
        [
            'id' => 'dealer_receivables',
            'no' => 4,
            'label' => 'Dealer Receivables',
            'label_kh' => 'បំណុលត្រូវទារពីតំណាងចែកចាយ',
            'usd' => $dealerReceivablesUsd,
            'khr' => $toKhr($dealerReceivablesUsd),
            'is_subtotal' => false,
            'is_total' => false,
        ],
        [
            'id' => 'cash',
            'no' => 5,
            'label' => 'Cash',
            'label_kh' => 'សាច់ប្រាក់ និងធនាគារ',
            'usd' => $cashUsd,
            'khr' => $toKhr($cashUsd),
            'is_subtotal' => false,
            'is_total' => false,
        ],
        [
            'id' => 'current_assets',
            'no' => 6,
            'label' => 'Current Assets',
            'label_kh' => 'ទ្រព្យសកម្មចរន្ត (សរុបរង)',
            'usd' => $currentAssetsUsd,
            'khr' => $toKhr($currentAssetsUsd),
            'is_subtotal' => true,
            'is_total' => false,
        ],
        [
            'id' => 'total_assets',
            'no' => 7,
            'label' => 'TOTAL ASSETS',
            'label_kh' => 'ទ្រព្យសកម្មសរុប',
            'usd' => $totalAssetsUsd,
            'khr' => $toKhr($totalAssetsUsd),
            'is_subtotal' => false,
            'is_total' => true,
        ],
    ];

    $liabilitiesRows = [
        [
            'id' => 'supplier_payables',
            'no' => 1,
            'label' => 'Supplier Payables',
            'label_kh' => 'បំណុលត្រូវសងអ្នកផ្គត់ផ្គង់',
            'usd' => $supplierPayablesUsd,
            'khr' => $toKhr($supplierPayablesUsd),
            'is_subtotal' => false,
            'is_total' => false,
        ],
        [
            'id' => 'total_liabilities',
            'no' => 2,
            'label' => 'TOTAL LIABILITIES',
            'label_kh' => 'បំណុលសរុប',
            'usd' => $totalLiabilitiesUsd,
            'khr' => $toKhr($totalLiabilitiesUsd),
            'is_subtotal' => false,
            'is_total' => true,
        ],
    ];

    $equityRows = [
        [
            'id' => 'owners_capital',
            'no' => 1,
            'label' => "Owner's Capital",
            'label_kh' => 'ដើមទុនម្ចាស់អាជីវកម្ម',
            'usd' => $ownersCapitalUsd,
            'khr' => $toKhr($ownersCapitalUsd),
            'is_subtotal' => false,
            'is_total' => false,
        ],
        [
            'id' => 'retained_earnings',
            'no' => 2,
            'label' => 'Retained Earnings',
            'label_kh' => 'ប្រាក់ចំណេញរក្សាទុក',
            'usd' => $retainedEarningsUsd,
            'khr' => $toKhr($retainedEarningsUsd),
            'is_subtotal' => false,
            'is_total' => false,
        ],
        [
            'id' => 'current_net_profit',
            'no' => 3,
            'label' => 'Current Net Profit / (Loss)',
            'label_kh' => 'ប្រាក់ចំណេញសុទ្ធ / (ខាត) ក្នុងគ្រា',
            'usd' => $currentNetProfitUsd,
            'khr' => $toKhr($currentNetProfitUsd),
            'is_subtotal' => false,
            'is_total' => false,
        ],
        [
            'id' => 'total_equity',
            'no' => 4,
            'label' => 'TOTAL EQUITY',
            'label_kh' => 'មូលធនសរុប',
            'usd' => $totalEquityUsd,
            'khr' => $toKhr($totalEquityUsd),
            'is_subtotal' => false,
            'is_total' => true,
        ],
    ];

    api_json([
        'success' => true,
        'as_of' => $asOfDate,
        'as_of_label_en' => $asOfLabelEn,
        'as_of_label_kh' => $asOfLabelKh,
        'period' => [
            'type' => $periodType,
            'from' => $from,
            'to' => $to,
            'label_en' => $periodLabelEn,
            'label_kh' => $periodLabelKh,
        ],
        'exchange_rate' => $exchangeRate,
        'sections' => [
            'assets' => [
                'title_en' => 'A. Assets',
                'title_kh' => 'A. ទ្រព្យសកម្ម',
                'rows' => $assetsRows,
                'total_usd' => $totalAssetsUsd,
                'total_khr' => $toKhr($totalAssetsUsd),
            ],
            'liabilities' => [
                'title_en' => 'B. Liabilities',
                'title_kh' => 'B. បំណុល',
                'rows' => $liabilitiesRows,
                'total_usd' => $totalLiabilitiesUsd,
                'total_khr' => $toKhr($totalLiabilitiesUsd),
            ],
            'equity' => [
                'title_en' => 'C. Equity',
                'title_kh' => 'C. មូលធន',
                'rows' => $equityRows,
                'total_usd' => $totalEquityUsd,
                'total_khr' => $toKhr($totalEquityUsd),
            ],
        ],
        'reconciliation' => [
            'total_assets_usd' => $totalAssetsUsd,
            'total_assets_khr' => $toKhr($totalAssetsUsd),
            'total_liabilities_usd' => $totalLiabilitiesUsd,
            'total_liabilities_khr' => $toKhr($totalLiabilitiesUsd),
            'total_equity_usd' => $totalEquityUsd,
            'total_equity_khr' => $toKhr($totalEquityUsd),
            'liabilities_and_equity_usd' => round($totalLiabilitiesUsd + $totalEquityUsd, 2),
            'liabilities_and_equity_khr' => $toKhr(round($totalLiabilitiesUsd + $totalEquityUsd, 2)),
            'balance_difference_usd' => $balanceDifferenceUsd,
            'balance_difference_khr' => $toKhr($balanceDifferenceUsd),
            'is_balanced' => $isBalanced,
            'status_label_en' => $isBalanced ? 'Balanced' : 'Unbalanced',
            'status_label_kh' => $isBalanced ? 'មានតុល្យភាព' : 'គ្មានតុល្យភាព (មានគម្លាត)',
            'note' => $isBalanced 
                ? 'The balance sheet is fully balanced according to the fundamental accounting equation.'
                : 'Accounting discrepancy detected. Verified real transaction data is displayed without artificial adjustments.',
        ],
        'financial_summary' => [
            'total_revenue_usd' => $periodRevenueUsd,
            'total_revenue_khr' => $toKhr($periodRevenueUsd),
            'gross_profit_usd' => $periodGrossProfitUsd,
            'gross_profit_khr' => $toKhr($periodGrossProfitUsd),
            'total_expenses_usd' => $periodExpensesUsd,
            'total_expenses_khr' => $toKhr($periodExpensesUsd),
            'net_profit_usd' => $currentNetProfitUsd,
            'net_profit_khr' => $toKhr($currentNetProfitUsd),
        ],
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}

