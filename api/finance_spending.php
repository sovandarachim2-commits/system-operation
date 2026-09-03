<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function fs_report_date(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

function fs_report_table_has(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = array_fill_keys(array_map(static fn(array $row): string => (string)$row['name'], api_table_columns($pdo, $table)), true);
    }
    return isset($cache[$table][$column]);
}

function fs_report_column_exists(PDO $pdo, string $table, string $column): bool
{
    foreach (api_table_columns($pdo, $table) as $row) {
        if ((string)($row['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

function fs_report_expense_scope(?string $value): string
{
    $scope = strtolower(trim((string)$value));
    return in_array($scope, ['online', 'offline', 'shared'], true) ? $scope : 'shared';
}

try {
    require_role_or_permission(
        ['admin'],
        'sr_income_statement.view',
        'sr_expense_records.view',
        'sr_expense_reports.view',
        'sr_expense_subcategory_report.view',
        'finance_reports.view'
    );

    $pdo = get_db_connection();
    if (!api_table_exists($pdo, 'finance_spending')) {
        api_json([
            'success' => true,
            'rows' => [],
            'summary' => ['total_rows' => 0, 'total_amount' => 0],
        ]);
    }

    $from = fs_report_date($_GET['from'] ?? $_GET['from_date'] ?? null, date('Y-m-01'));
    $to = fs_report_date($_GET['to'] ?? $_GET['to_date'] ?? null, date('Y-m-d'));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $hasCompany = fs_report_table_has($pdo, 'finance_spending', 'company_id') && api_table_exists($pdo, 'companies');
    $companySelect = $hasCompany ? 'fs.company_id, COALESCE(c.name, \'\') AS company_name,' : 'NULL AS company_id, \'\' AS company_name,';
    $companyJoin = $hasCompany ? 'LEFT JOIN companies c ON c.id = fs.company_id' : '';
    $hasExpenseCategories = api_table_exists($pdo, 'finance_categories');
    if ($hasExpenseCategories && !fs_report_column_exists($pdo, 'finance_categories', 'expense_scope')) {
        $pdo->exec("ALTER TABLE finance_categories ADD COLUMN expense_scope VARCHAR(20) NOT NULL DEFAULT 'shared' AFTER parent_category");
    }
    $scopeSelect = $hasExpenseCategories ? "COALESCE(fc.expense_scope, 'shared') AS expense_scope," : "'shared' AS expense_scope,";
    $scopeJoin = $hasExpenseCategories ? "LEFT JOIN finance_categories fc ON fc.type = 'main' AND fc.name COLLATE utf8mb4_unicode_ci = fs.category COLLATE utf8mb4_unicode_ci" : '';

    $stmt = $pdo->prepare("
        SELECT
            fs.id,
            COALESCE(fs.spending_code, CONCAT('finance_spending#', fs.id)) AS spending_code,
            fs.amount,
            COALESCE(fs.original_amount, fs.amount) AS original_amount,
            COALESCE(fs.currency, 'USD') AS currency,
            COALESCE(fs.payment_method, '') AS payment_method,
            {$companySelect}
            COALESCE(fs.paid_by, '') AS paid_by,
            COALESCE(fs.receive_by, '') AS receive_by,
            fs.spending_date,
            COALESCE(fs.status, '') AS status,
            COALESCE(fs.category, '') AS expense_category,
            {$scopeSelect}
            COALESCE(fs.sub_category, '') AS expense_sub_category,
            COALESCE(fs.sub_categories, '') AS expense_sub_categories,
            COALESCE(fs.note, '') AS note,
            fs.created_at,
            COALESCE(u.name, u.username, fs.created_by, '') AS created_by_label
        FROM finance_spending fs
        LEFT JOIN users u ON (
            fs.created_by = u.id
            OR (fs.created_by COLLATE utf8mb4_unicode_ci = u.name COLLATE utf8mb4_unicode_ci)
            OR (fs.created_by COLLATE utf8mb4_unicode_ci = u.username COLLATE utf8mb4_unicode_ci)
        )
        {$companyJoin}
        {$scopeJoin}
        WHERE DATE(fs.spending_date) BETWEEN ? AND ?
        ORDER BY fs.spending_date ASC, fs.id ASC
    ");
    $stmt->execute([$from, $to]);

    $rows = [];
    $total = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $amount = (float)($item['amount'] ?? 0);
        $total += $amount;
        $id = (string)($item['id'] ?? '');
        $rows[] = [
            'spending_id' => $id,
            'id' => $id !== '' ? 'finance-spending-' . $id : '',
            'source' => 'finance_spending',
            'category' => 'Expense',
            'date' => (string)($item['spending_date'] ?? ''),
            'sort_date' => trim((string)($item['spending_date'] ?? '') . ' ' . (string)($item['created_at'] ?? '')),
            'bank_name' => (string)($item['payment_method'] ?? ''),
            'payment_method' => (string)($item['payment_method'] ?? ''),
            'company_id' => (string)($item['company_id'] ?? ''),
            'company_name' => (string)($item['company_name'] ?? ''),
            'description' => 'Expense - ' . ((string)($item['expense_category'] ?? '') ?: 'Uncategorized'),
            'expense_category' => (string)($item['expense_category'] ?? ''),
            'expense_scope' => fs_report_expense_scope((string)($item['expense_scope'] ?? 'shared')),
            'expense_sub_category' => (string)($item['expense_sub_category'] ?? ''),
            'expense_sub_categories' => (string)($item['expense_sub_categories'] ?? ''),
            'paid_by' => (string)($item['paid_by'] ?? ''),
            'receive_by' => (string)($item['receive_by'] ?? ''),
            'created_by_label' => (string)($item['created_by_label'] ?? ''),
            'debit' => $amount,
            'amount' => $amount,
            'credit' => 0,
            'balance' => 0,
            'cash_in' => 0,
            'unpaid' => 0,
            'discount' => 0,
            'status' => strtolower((string)($item['status'] ?? '')),
            'note' => (string)($item['note'] ?? ''),
            'remark' => (string)($item['note'] ?? ''),
            'reference' => (string)($item['spending_code'] ?? ''),
            'details' => [],
        ];
    }

    api_json([
        'success' => true,
        'rows' => $rows,
        'summary' => [
            'from' => $from,
            'to' => $to,
            'total_rows' => count($rows),
            'total_amount' => $total,
        ],
    ]);
} catch (Throwable $e) {
    error_log('finance_spending report API error: ' . $e->getMessage());
    api_error('Unable to load expense records.', 500);
}
