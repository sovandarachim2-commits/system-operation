<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_role_or_permission(['admin'], 'sr_cashflow.view', 'sr_income_statement.view', 'sr_expense_records.view', 'sr_expense_reports.view', 'sr_expense_subcategory_report.view', 'sr_bank_balances.view', 'cashflow.view', 'daily_summary.view');

function cashflow_api_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function cashflow_bank_label(?string $value): string
{
    $value = trim((string)$value);
    return $value === '' ? '(No method)' : $value;
}

function cashflow_date_in_range(?string $value, ?string $from, ?string $to): bool
{
    $date = substr((string)$value, 0, 10);
    if ($date === '') {
        return false;
    }
    if ($from !== null && $date < $from) {
        return false;
    }
    if ($to !== null && $date > $to) {
        return false;
    }
    return true;
}

function cashflow_user_label(array $row, string $prefix): string
{
    return trim((string)($row[$prefix . '_name'] ?? $row[$prefix . '_username'] ?? $row[$prefix] ?? ''));
}

function cashflow_add_conditions(array &$where, array &$params, string $dateSql, ?string $from, ?string $to, string $bankSql = '', string $bank = ''): void
{
    if ($from !== null) {
        $where[] = "DATE({$dateSql}) >= ?";
        $params[] = $from;
    }
    if ($to !== null) {
        $where[] = "DATE({$dateSql}) <= ?";
        $params[] = $to;
    }
    if ($bank !== '' && $bankSql !== '') {
        $where[] = $bankSql . ' = ?';
        $params[] = $bank;
    }
}

function cashflow_fetch_all(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cashflow_table_has(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = array_fill_keys(array_map(static fn(array $row): string => (string)$row['name'], api_table_columns($pdo, $table)), true);
    }
    return isset($cache[$table][$column]);
}

function cashflow_table_exists_safe(PDO $pdo, string $table): bool
{
    try {
        return api_table_exists($pdo, $table);
    } catch (Throwable $e) {
        return false;
    }
}

function cashflow_month_label(?string $value): string
{
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? 'Selected Period' : date('M-Y', $timestamp);
}

function cashflow_group_summary_rows(array $rows): array
{
    $grouped = [];
    $output = [];

    foreach ($rows as $row) {
        $isSale = ($row['category'] ?? '') === 'Sale';
        $isExpense = ($row['category'] ?? '') === 'Expense' && (float)($row['debit'] ?? 0) > 0;
        if (!$isSale && !$isExpense) {
            $output[] = $row;
            continue;
        }

        $date = substr((string)($row['date'] ?? ''), 0, 10);
        $bankName = cashflow_bank_label((string)($row['bank_name'] ?? ''));
        $description = trim((string)($row['description'] ?? ''));
        $groupType = $isExpense ? 'expense' : 'sale';
        $key = implode('|', [$groupType, $date, $bankName, $description]);
        $rowCredit = (float)($row['credit'] ?? 0);
        $rowDebit = (float)($row['debit'] ?? 0);
        $rowCashIn = (float)($row['cash_in'] ?? $rowCredit);
        $rowUnpaid = (float)($row['unpaid'] ?? 0);
        $rowDiscount = (float)($row['discount'] ?? 0);

        if (!isset($grouped[$key])) {
            $row['id'] = $groupType . '-' . md5($key);
            $row['date'] = $date;
            $row['sort_date'] = $date;
            $row['bank_name'] = $bankName;
            $row['debit'] = 0.0;
            $row['credit'] = 0.0;
            $row['cash_in'] = 0.0;
            $row['unpaid'] = 0.0;
            $row['discount'] = 0.0;
            $row['transaction_count'] = 0;
            $row['references'] = [];
            $row['details'] = [];
            $grouped[$key] = $row;
        }

        $grouped[$key]['credit'] += $rowCredit;
        $grouped[$key]['debit'] += $rowDebit;
        $grouped[$key]['cash_in'] += $rowCashIn;
        $grouped[$key]['unpaid'] += $rowUnpaid;
        $grouped[$key]['discount'] += $rowDiscount;
        $grouped[$key]['transaction_count']++;

        $reference = trim((string)($row['reference'] ?? ''));
        if ($reference !== '') {
            $grouped[$key]['references'][] = $reference;
        }
        $grouped[$key]['details'][] = [
            'date' => (string)($row['date'] ?? ''),
            'reference' => (string)($row['reference'] ?? ''),
            'customer' => (string)($row['customer_name'] ?? ''),
            'seller' => (string)($row['seller'] ?? ''),
            'payment_status' => (string)($row['payment_status'] ?? ''),
            'bank_name' => (string)($row['bank_name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'expense_category' => (string)($row['expense_category'] ?? ''),
            'expense_sub_category' => (string)($row['expense_sub_category'] ?? ''),
            'paid_by' => (string)($row['paid_by'] ?? ''),
            'created_by' => (string)($row['created_by_label'] ?? ''),
            'credit' => $rowCredit,
            'debit' => $rowDebit,
            'unpaid' => $rowUnpaid,
            'remark' => (string)($row['remark'] ?? ''),
        ];
    }

    foreach ($grouped as &$row) {
        $count = (int)($row['transaction_count'] ?? 1);
        $references = array_values(array_unique($row['references'] ?? []));
        $row['reference'] = $count === 1 ? ($references[0] ?? '') : $count . ' transactions';
        $row['remark'] = $count . ' transactions';
        unset($row['references']);
        $output[] = $row;
    }
    unset($row);

    return $output;
}

try {
    $pdo = get_db_connection();
    $from = cashflow_api_date($_GET['from'] ?? null);
    $to = cashflow_api_date($_GET['to'] ?? null);
    $payment = trim((string)($_GET['payment'] ?? ''));
    $sellerId = filter_var($_GET['seller_id'] ?? null, FILTER_VALIDATE_INT);
    $status = strtolower(trim((string)($_GET['status'] ?? '')));
    $includeNonOrderMovements = !in_array($status, ['paid', 'unpaid', 'cancelled'], true);

    $rows = [];
    $excludedOrders = 0;

    $orderDateSql = "CASE
        WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid' THEN o.payment_date
        ELSE o.created_at
    END";
    $orderWhere = [];
    $orderParams = [];
    if ($from !== null || $to !== null) {
        $dateParts = [];
        $dateParams = [];
        if ($from !== null && $to !== null) {
            $dateParts[] = '(DATE(o.created_at) BETWEEN ? AND ?)';
            array_push($dateParams, $from, $to);
            $dateParts[] = '(o.payment_date IS NOT NULL AND DATE(o.payment_date) BETWEEN ? AND ?)';
            array_push($dateParams, $from, $to);
        } elseif ($from !== null) {
            $dateParts[] = 'DATE(o.created_at) >= ?';
            $dateParams[] = $from;
            $dateParts[] = '(o.payment_date IS NOT NULL AND DATE(o.payment_date) >= ?)';
            $dateParams[] = $from;
        } elseif ($to !== null) {
            $dateParts[] = 'DATE(o.created_at) <= ?';
            $dateParams[] = $to;
            $dateParts[] = '(o.payment_date IS NOT NULL AND DATE(o.payment_date) <= ?)';
            $dateParams[] = $to;
        }
        $orderWhere[] = '(' . implode(' OR ', $dateParts) . ')';
        array_push($orderParams, ...$dateParams);
    }
    if ($payment !== '') {
        $orderWhere[] = "COALESCE(NULLIF(TRIM(o.payment_method), ''), '(No method)') = ?";
        $orderParams[] = $payment;
    }
    if ($sellerId !== false && $sellerId !== null) {
        $orderWhere[] = 'o.seller_id = ?';
        $orderParams[] = (int)$sellerId;
    }
    if ($status === 'cancelled') {
        $orderWhere[] = 'COALESCE(o.is_cancelled, 0) = 1';
    } elseif ($status === 'paid') {
        $orderWhere[] = 'COALESCE(o.is_cancelled, 0) = 0';
        $orderWhere[] = 'COALESCE(o.is_returned, 0) = 0';
        $orderWhere[] = "(COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid')";
    } elseif ($status === 'unpaid') {
        $orderWhere[] = 'COALESCE(o.is_cancelled, 0) = 0';
        $orderWhere[] = 'COALESCE(o.is_returned, 0) = 0';
        $orderWhere[] = "NOT (COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid')";
    } elseif ($status === 'active') {
        $orderWhere[] = 'COALESCE(o.is_cancelled, 0) = 0';
    }
    $orderWhereSql = $orderWhere ? 'WHERE ' . implode(' AND ', $orderWhere) : '';

    $orders = cashflow_fetch_all($pdo, "
        SELECT
            o.id,
            DATE({$orderDateSql}) AS ledger_date,
            {$orderDateSql} AS ledger_datetime,
            o.created_at,
            o.payment_date,
            o.order_code,
            COALESCE(o.customer_name, '') AS customer_name,
            COALESCE(o.payment_method, '') AS payment_method,
            COALESCE(o.discount, 0) AS discount,
            COALESCE(o.total_amount, 0) AS total_amount,
            COALESCE(u.name, u.username, '') AS seller,
            CASE WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid' THEN 'Paid' ELSE 'Unpaid' END AS payment_status,
            CASE
                WHEN COALESCE(o.is_cancelled, 0) = 1 THEN 'Cancel'
                WHEN COALESCE(o.is_returned, 0) = 1 THEN 'Return'
                WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid' THEN 'Paid'
                ELSE 'Unpaid'
            END AS order_status
        FROM orders o
        LEFT JOIN users u ON u.id = o.seller_id
        {$orderWhereSql}
    ", $orderParams);

    foreach ($orders as $order) {
        $orderStatus = strtolower((string)$order['order_status']);
        if (str_contains($orderStatus, 'cancel') || str_contains($orderStatus, 'return')) {
            $excludedOrders++;
            continue;
        }
        $paid = strtolower((string)$order['payment_status']) === 'paid';
        $amount = (float)$order['total_amount'];
        $createdDate = substr((string)($order['created_at'] ?? ''), 0, 10);
        $paymentDate = substr((string)($order['payment_date'] ?? ''), 0, 10);
        $effectivePaymentDate = $paymentDate !== '' ? $paymentDate : $createdDate;
        $createdDateTime = (string)($order['created_at'] ?? $order['ledger_datetime'] ?? $order['ledger_date'] ?? '');
        $paymentDateTime = $effectivePaymentDate !== '' ? $effectivePaymentDate . ' 23:59:59' : (string)($order['ledger_datetime'] ?? $order['ledger_date'] ?? '');
        $bankName = cashflow_bank_label((string)$order['payment_method']);
        $baseRemark = implode(' / ', array_values(array_filter([
            (string)$order['order_code'],
            (string)$order['customer_name'],
            (string)$order['seller'],
        ], static fn(string $value): bool => trim($value) !== '')));

        if ((!$paid || ($createdDate !== '' && $effectivePaymentDate !== '' && $createdDate < $effectivePaymentDate)) && cashflow_date_in_range($createdDateTime, $from, $to)) {
            $rows[] = [
                'id' => 'sale-unpaid-' . $order['id'],
                'source' => 'orders',
                'category' => 'Sale',
                'date' => $createdDateTime,
                'sort_date' => $createdDateTime,
                'bank_name' => 'Unpaid',
                'payment_method' => 'Unpaid',
                'description' => 'Unpaid on ' . cashflow_month_label($createdDateTime),
                'customer_name' => (string)$order['customer_name'],
                'seller' => (string)$order['seller'],
                'payment_status' => $paid ? 'Unpaid then Paid' : 'Unpaid',
                'debit' => 0.0,
                'credit' => 0.0,
                'balance' => 0.0,
                'cash_in' => 0.0,
                'unpaid' => $amount,
                'discount' => (float)$order['discount'],
                'remark' => trim($baseRemark . ' / Unpaid'),
                'reference' => (string)$order['order_code'],
            ];
        }

        if ($paid) {
            if (cashflow_date_in_range($paymentDateTime, $from, $to)) {
                $rows[] = [
                'id' => 'sale-paid-' . $order['id'],
                'source' => 'orders',
                'category' => 'Sale',
                'date' => $paymentDateTime,
                'sort_date' => $paymentDateTime,
                'bank_name' => $bankName,
                'payment_method' => $bankName,
                'description' => 'Income on ' . cashflow_month_label($paymentDateTime),
                'customer_name' => (string)$order['customer_name'],
                'seller' => (string)$order['seller'],
                'payment_status' => 'Paid',
                'debit' => 0.0,
                'credit' => $amount,
                'balance' => 0.0,
                'cash_in' => $amount,
                'unpaid' => 0.0,
                'discount' => (float)$order['discount'],
                'remark' => trim($baseRemark . ' / Paid'),
                'reference' => (string)$order['order_code'],
                ];
            }

            if ($createdDate !== '' && $effectivePaymentDate !== '' && $createdDate < $effectivePaymentDate && cashflow_date_in_range($paymentDateTime, $from, $to)) {
                $rows[] = [
                    'id' => 'sale-unpaid-cleared-' . $order['id'],
                    'source' => 'orders',
                    'category' => 'Sale',
                    'date' => $paymentDateTime,
                    'sort_date' => $paymentDateTime . '.1',
                    'bank_name' => 'Unpaid',
                    'payment_method' => 'Unpaid',
                    'description' => 'Unpaid Cleared on ' . cashflow_month_label($paymentDateTime),
                    'customer_name' => (string)$order['customer_name'],
                    'seller' => (string)$order['seller'],
                    'payment_status' => 'Paid',
                    'debit' => 0.0,
                    'credit' => 0.0,
                    'balance' => 0.0,
                    'cash_in' => 0.0,
                    'unpaid' => -$amount,
                    'discount' => 0.0,
                    'remark' => trim($baseRemark . ' / Unpaid cleared'),
                    'reference' => (string)$order['order_code'],
                ];
            }
        }
    }

    if ($includeNonOrderMovements && cashflow_table_exists_safe($pdo, 'cashflow_topups')) {
        $where = [];
        $params = [];
        cashflow_add_conditions($where, $params, 'ct.topup_date', $from, $to, "COALESCE(NULLIF(TRIM(ct.payment_method), ''), '(No method)')", $payment);
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $topups = cashflow_fetch_all($pdo, "
            SELECT ct.*, COALESCE(u.name, u.username, '') AS created_by_name
            FROM cashflow_topups ct
            LEFT JOIN users u ON u.id = ct.created_by
            {$whereSql}
        ", $params);
        foreach ($topups as $topup) {
            $bankName = cashflow_bank_label((string)($topup['payment_method'] ?? ''));
            $rows[] = [
                'id' => 'cashflow-topup-' . $topup['id'],
                'source' => 'cashflow_topups',
                'category' => 'Top Up',
                'date' => $topup['topup_date'],
                'sort_date' => ($topup['topup_date'] ?? '') . ' ' . ($topup['created_at'] ?? '00:00:00'),
                'bank_name' => $bankName,
                'payment_method' => $bankName,
                'description' => 'Top Up',
                'debit' => 0.0,
                'credit' => (float)$topup['amount'],
                'balance' => 0.0,
                'cash_in' => (float)$topup['amount'],
                'unpaid' => 0.0,
                'discount' => 0.0,
                'remark' => implode(' / ', array_values(array_filter([(string)($topup['note'] ?? ''), 'Created by ' . (string)($topup['created_by_name'] ?? '')], static fn(string $value): bool => trim($value) !== '' && trim($value) !== 'Created by'))),
                'reference' => 'cashflow_topups#' . $topup['id'],
            ];
        }
    }

    if ($includeNonOrderMovements && cashflow_table_exists_safe($pdo, 'finance_topups')) {
        $where = [];
        $params = [];
        cashflow_add_conditions($where, $params, 'ft.topup_date', $from, $to, "COALESCE(NULLIF(TRIM(ft.source), ''), '(No method)')", $payment);
        if (cashflow_table_has($pdo, 'finance_topups', 'status')) {
            $where[] = "LOWER(COALESCE(NULLIF(TRIM(ft.status), ''), 'completed')) IN ('approved', 'completed')";
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $topups = cashflow_fetch_all($pdo, "
            SELECT ft.*, COALESCE(u.name, u.username, '') AS created_by_name
            FROM finance_topups ft
            LEFT JOIN users u ON u.id = ft.created_by
            {$whereSql}
        ", $params);
        foreach ($topups as $topup) {
            $bankName = cashflow_bank_label((string)($topup['source'] ?? ''));
            $rows[] = [
                'id' => 'finance-topup-' . $topup['id'],
                'source' => 'finance_topups',
                'category' => 'Top Up',
                'date' => $topup['topup_date'],
                'sort_date' => ($topup['topup_date'] ?? '') . ' ' . ($topup['created_at'] ?? '00:00:00'),
                'bank_name' => $bankName,
                'payment_method' => $bankName,
                'description' => 'Top Up',
                'debit' => 0.0,
                'credit' => (float)$topup['amount'],
                'balance' => 0.0,
                'cash_in' => (float)$topup['amount'],
                'unpaid' => 0.0,
                'discount' => 0.0,
                'remark' => implode(' / ', array_values(array_filter([(string)($topup['person_name'] ?? ''), (string)($topup['description'] ?? ''), 'Created by ' . (string)($topup['created_by_name'] ?? '')], static fn(string $value): bool => trim($value) !== '' && trim($value) !== 'Created by'))),
                'reference' => 'finance_topups#' . $topup['id'],
            ];
        }
    }

    if ($includeNonOrderMovements && cashflow_table_exists_safe($pdo, 'cashflow_spending')) {
        $where = [];
        $params = [];
        cashflow_add_conditions($where, $params, 'cs.spending_date', $from, $to, "COALESCE(NULLIF(TRIM(cs.payment_method), ''), '(No method)')", $payment);
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $spending = cashflow_fetch_all($pdo, "
            SELECT cs.*, COALESCE(spent.name, spent.username, '') AS spent_by_name, COALESCE(created.name, created.username, '') AS created_by_name
            FROM cashflow_spending cs
            LEFT JOIN users spent ON spent.id = cs.spent_by
            LEFT JOIN users created ON created.id = cs.created_by
            {$whereSql}
        ", $params);
        foreach ($spending as $item) {
            $bankName = cashflow_bank_label((string)($item['payment_method'] ?? ''));
            $category = trim((string)($item['category'] ?? ''));
            $subCategory = trim((string)($item['sub_category'] ?? ''));
            $rows[] = [
                'id' => 'cashflow-spending-' . $item['id'],
                'source' => 'cashflow_spending',
                'category' => 'Expense',
                'date' => $item['spending_date'],
                'sort_date' => ($item['spending_date'] ?? '') . ' ' . ($item['created_at'] ?? '00:00:00'),
                'bank_name' => $bankName,
                'payment_method' => $bankName,
                'description' => trim('Expense - ' . ($category !== '' ? $category : 'Uncategorized')),
                'expense_category' => $category,
                'expense_sub_category' => $subCategory,
                'paid_by' => (string)($item['spent_by_name'] ?? ''),
                'created_by_label' => (string)($item['created_by_name'] ?? ''),
                'debit' => (float)$item['amount'],
                'credit' => 0.0,
                'balance' => 0.0,
                'cash_in' => 0.0,
                'unpaid' => 0.0,
                'discount' => 0.0,
                'remark' => implode(' / ', array_values(array_filter([(string)($item['note'] ?? ''), (string)($item['spent_by_name'] ?? ''), 'Created by ' . (string)($item['created_by_name'] ?? '')], static fn(string $value): bool => trim($value) !== '' && trim($value) !== 'Created by'))),
                'reference' => 'cashflow_spending#' . $item['id'],
            ];
        }
    }

    if ($includeNonOrderMovements && cashflow_table_exists_safe($pdo, 'finance_spending')) {
        $where = [];
        $params = [];
        cashflow_add_conditions($where, $params, 'fs.spending_date', $from, $to, "COALESCE(NULLIF(TRIM(fs.payment_method), ''), '(No method)')", $payment);
        $where[] = "LOWER(COALESCE(fs.status, '')) IN ('approved', 'completed')";
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $spending = cashflow_fetch_all($pdo, "
            SELECT fs.*, COALESCE(u.name, u.username, '') AS created_by_name
            FROM finance_spending fs
            LEFT JOIN users u ON u.id = fs.created_by
            {$whereSql}
        ", $params);
        foreach ($spending as $item) {
            $category = trim((string)($item['category'] ?? ''));
            $subCategory = trim((string)($item['sub_category'] ?? ''));
            $bankName = cashflow_bank_label((string)($item['payment_method'] ?? ''));
            $rows[] = [
                'id' => 'finance-spending-' . $item['id'],
                'source' => 'finance_spending',
                'category' => 'Expense',
                'date' => $item['spending_date'],
                'sort_date' => ($item['spending_date'] ?? '') . ' ' . ($item['created_at'] ?? '00:00:00'),
                'bank_name' => $bankName,
                'payment_method' => $bankName,
                'description' => trim('Expense - ' . ($category !== '' ? $category : 'Uncategorized')),
                'expense_category' => $category,
                'expense_sub_category' => $subCategory,
                'paid_by' => (string)($item['paid_by'] ?? ''),
                'created_by_label' => (string)($item['created_by_name'] ?? ''),
                'debit' => (float)$item['amount'],
                'credit' => 0.0,
                'balance' => 0.0,
                'cash_in' => 0.0,
                'unpaid' => 0.0,
                'discount' => 0.0,
                'remark' => implode(' / ', array_values(array_filter([(string)($item['spending_code'] ?? ''), (string)($item['paid_by'] ?? ''), (string)($item['receive_by'] ?? ''), (string)($item['note'] ?? ''), 'Created by ' . (string)($item['created_by_name'] ?? '')], static fn(string $value): bool => trim($value) !== '' && trim($value) !== 'Created by'))),
                'reference' => (string)($item['spending_code'] ?? ('finance_spending#' . $item['id'])),
            ];
        }
    }

    if ($includeNonOrderMovements && cashflow_table_exists_safe($pdo, 'bank_transfers')) {
        $where = [];
        $params = [];
        cashflow_add_conditions($where, $params, 'bt.transfer_date', $from, $to);
        if ($payment !== '') {
            $where[] = '(bt.from_bank = ? OR bt.to_bank = ?)';
            array_push($params, $payment, $payment);
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $transfers = cashflow_fetch_all($pdo, "
            SELECT bt.*, COALESCE(u.name, u.username, '') AS created_by_name
            FROM bank_transfers bt
            LEFT JOIN users u ON u.id = bt.created_by
            {$whereSql}
        ", $params);
        foreach ($transfers as $transfer) {
            $amount = (float)$transfer['amount'];
            $fromBank = cashflow_bank_label((string)$transfer['from_bank']);
            $toBank = cashflow_bank_label((string)$transfer['to_bank']);
            if ($payment === '' || $payment === $fromBank) {
                $rows[] = [
                    'id' => 'transfer-out-' . $transfer['id'],
                    'source' => 'bank_transfers',
                    'category' => 'Bank Transfer',
                    'date' => $transfer['transfer_date'],
                    'sort_date' => ($transfer['transfer_date'] ?? '') . ' ' . ($transfer['created_at'] ?? '00:00:00') . '.1',
                    'bank_name' => $fromBank,
                    'payment_method' => $fromBank,
                    'description' => 'Bank Transfer ' . $fromBank . ' to ' . $toBank,
                    'debit' => $amount,
                    'credit' => 0.0,
                    'balance' => 0.0,
                    'cash_in' => 0.0,
                    'unpaid' => 0.0,
                    'discount' => 0.0,
                'remark' => '1 transaction',
                'reference' => 'bank_transfers#' . $transfer['id'],
                'details' => [[
                    'date' => (string)($transfer['transfer_date'] ?? ''),
                    'reference' => 'bank_transfers#' . $transfer['id'],
                    'bank_name' => $fromBank,
                    'description' => 'Bank Transfer ' . $fromBank . ' to ' . $toBank,
                    'payment_status' => 'Transfer Out',
                    'debit' => $amount,
                    'credit' => 0.0,
                    'remark' => implode(' / ', array_values(array_filter([(string)($transfer['note'] ?? ''), 'To ' . $toBank, 'Created by ' . (string)($transfer['created_by_name'] ?? '')], static fn(string $value): bool => trim($value) !== '' && trim($value) !== 'Created by'))),
                ]],
            ];
            }
            if ($payment === '' || $payment === $toBank) {
                $rows[] = [
                    'id' => 'transfer-in-' . $transfer['id'],
                    'source' => 'bank_transfers',
                    'category' => 'Bank Transfer',
                    'date' => $transfer['transfer_date'],
                    'sort_date' => ($transfer['transfer_date'] ?? '') . ' ' . ($transfer['created_at'] ?? '00:00:00') . '.2',
                    'bank_name' => $toBank,
                    'payment_method' => $toBank,
                    'description' => 'Bank Transfer ' . $fromBank . ' to ' . $toBank,
                    'debit' => 0.0,
                    'credit' => $amount,
                    'balance' => 0.0,
                    'cash_in' => $amount,
                    'unpaid' => 0.0,
                    'discount' => 0.0,
                    'remark' => '1 transaction',
                    'reference' => 'bank_transfers#' . $transfer['id'],
                    'details' => [[
                        'date' => (string)($transfer['transfer_date'] ?? ''),
                        'reference' => 'bank_transfers#' . $transfer['id'],
                        'bank_name' => $toBank,
                        'description' => 'Bank Transfer ' . $fromBank . ' to ' . $toBank,
                        'payment_status' => 'Transfer In',
                        'debit' => 0.0,
                        'credit' => $amount,
                        'remark' => implode(' / ', array_values(array_filter([(string)($transfer['note'] ?? ''), 'From ' . $fromBank, 'Created by ' . (string)($transfer['created_by_name'] ?? '')], static fn(string $value): bool => trim($value) !== '' && trim($value) !== 'Created by'))),
                    ]],
                ];
            }
        }
    }

    $rows = cashflow_group_summary_rows($rows);

    usort($rows, static function (array $a, array $b): int {
        $dateCompare = strcmp((string)($a['sort_date'] ?? $a['date']), (string)($b['sort_date'] ?? $b['date']));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return strcmp((string)$a['id'], (string)$b['id']);
    });

    $bankBalances = [];
    $dailyRows = [];
    $methodRows = [];
    $runningTotal = 0.0;
    $totalCredit = 0.0;
    $totalDebit = 0.0;
    $totalCashIn = 0.0;
    $totalUnpaid = 0.0;
    $totalDiscount = 0.0;
    $paidOrders = 0;
    $unpaidOrders = 0;
    $creditRows = 0;
    $debitRows = 0;

    foreach ($rows as &$row) {
        $bankName = cashflow_bank_label((string)$row['bank_name']);
        $row['bank_name'] = $bankName;
        $credit = (float)$row['credit'];
        $debit = (float)$row['debit'];
        $bankBalances[$bankName] = ($bankBalances[$bankName] ?? 0.0) + $credit - $debit;
        $row['balance'] = $bankBalances[$bankName];
        $runningTotal += $credit - $debit;

        $date = substr((string)$row['date'], 0, 10);
        if (!isset($dailyRows[$date])) {
            $dailyRows[$date] = [
                'date' => $date,
                'orders' => 0,
                'cash_in' => 0.0,
                'unpaid' => 0.0,
                'discount' => 0.0,
                'net_movement' => 0.0,
                'closing_balance' => 0.0,
            ];
        }
        $transactionCount = max(1, (int)($row['transaction_count'] ?? 1));
        $dailyRows[$date]['orders'] += $transactionCount;
        $dailyRows[$date]['cash_in'] += (float)$row['cash_in'];
        $dailyRows[$date]['unpaid'] += (float)$row['unpaid'];
        $dailyRows[$date]['discount'] += (float)$row['discount'];
        $dailyRows[$date]['net_movement'] += $credit - $debit;
        $dailyRows[$date]['closing_balance'] = $runningTotal;

        if ($credit > 0) {
            if (!isset($methodRows[$bankName])) {
                $methodRows[$bankName] = ['payment_method' => $bankName, 'orders' => 0, 'cash_in' => 0.0, 'percentage' => 0.0];
            }
            $methodRows[$bankName]['orders'] += $transactionCount;
            $methodRows[$bankName]['cash_in'] += $credit;
            $creditRows++;
        }
        if ($debit > 0) {
            $debitRows++;
        }
        if (($row['category'] ?? '') === 'Sale' && (float)$row['cash_in'] > 0) {
            $paidOrders += $transactionCount;
        }
        if (($row['category'] ?? '') === 'Sale' && (float)$row['unpaid'] > 0) {
            $unpaidOrders++;
        }
        $totalCredit += $credit;
        $totalDebit += $debit;
        $totalCashIn += (float)$row['cash_in'];
        $totalUnpaid += (float)$row['unpaid'];
        $totalDiscount += (float)$row['discount'];
        unset($row['sort_date']);
    }
    unset($row);

    $dailyRows = array_values($dailyRows);
    usort($dailyRows, static fn(array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));
    $methodRows = array_values(array_map(static function (array $row) use ($totalCredit): array {
        $row['percentage'] = $totalCredit > 0 ? ((float)$row['cash_in'] / $totalCredit) * 100 : 0.0;
        return $row;
    }, $methodRows));
    usort($methodRows, static fn(array $a, array $b): int => ((float)$b['cash_in'] <=> (float)$a['cash_in']));

    $netSales = $totalCashIn + $totalUnpaid;


    api_json([
        'success' => true,
        'summary' => [
            'total_rows' => count($rows),
            'paid_orders' => $paidOrders,
            'unpaid_orders' => $unpaidOrders,
            'excluded_orders' => $excludedOrders,
            'gross_sales' => $netSales + $totalDiscount,
            'net_sales' => $netSales,
            'total_cash_in' => $totalCashIn,
            'total_credit' => $totalCredit,
            'total_debit' => $totalDebit,
            'total_unpaid' => $totalUnpaid,
            'total_discount' => $totalDiscount,
            'closing_balance' => $totalCredit - $totalDebit,
            'credit_rows' => $creditRows,
            'debit_rows' => $debitRows,
        ],
        'rows' => $rows,
        'daily_rows' => $dailyRows,
        'method_rows' => $methodRows,
        'pagination' => [
            'limit' => count($rows),
            'offset' => 0,
            'total_rows' => count($rows),
            'has_more' => false,
        ],
    ]);
} catch (Throwable $e) {
    error_log('cashflow_ledger API error: ' . $e->getMessage());
    api_error('Unable to load cashflow ledger.', 500);
}
