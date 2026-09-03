<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/finance_balance_lib.php';
require_role_or_permission(
    ['admin'],
    'sr_expense_records.view',
    'sr_expense_records.create',
    'sr_expense_categories.view',
    'sr_expense_reports.view',
    'sr_expense_settings.view',
    'spending.view',
    'spending.create',
    'manage_categories.view',
    'finance_reports.view'
);

function expense_form_label(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return ucfirst(str_replace('_', ' ', $value));
}

function expense_form_option_rows(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function expense_form_ensure_companies(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        color VARCHAR(20) NULL DEFAULT '#6b7280',
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_company_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function expense_form_next_spending_code(PDO $pdo, ?string $forDate = null): string
{
    $time = $forDate && strtotime($forDate) ? strtotime($forDate) : time();
    $prefix = 'EXP-' . date('ymd', $time);
    $stmt = $pdo->prepare("
        SELECT spending_code
        FROM finance_spending
        WHERE spending_code LIKE ?
        ORDER BY spending_code DESC
        LIMIT 1000
    ");
    $stmt->execute([$prefix . '%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string)$code, $match)) {
            $max = max($max, (int)$match[1]);
        }
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

try {
    $pdo = get_db_connection();
    expense_form_ensure_companies($pdo);

    $users = [];
    if (api_table_exists($pdo, 'users')) {
        foreach (expense_form_option_rows($pdo, "
            SELECT COALESCE(NULLIF(name, ''), username) AS value
            FROM users
            WHERE active = 1
            ORDER BY value
            LIMIT 500
        ") as $row) {
            $value = trim((string)($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $users[] = ['value' => $value, 'label' => $value];
        }
    }

    $categories = [];
    $subcategories = [];
    if (api_table_exists($pdo, 'finance_categories')) {
        foreach (expense_form_option_rows($pdo, "
            SELECT name
            FROM finance_categories
            WHERE type = 'main'
            ORDER BY name
            LIMIT 500
        ") as $row) {
            $value = trim((string)($row['name'] ?? ''));
            if ($value === '') {
                continue;
            }
            $categories[] = [
                'value' => $value,
                'label' => expense_form_label($value),
            ];
        }

        foreach (expense_form_option_rows($pdo, "
            SELECT name, parent_category
            FROM finance_categories
            WHERE type = 'sub'
            ORDER BY parent_category, name
            LIMIT 2000
        ") as $row) {
            $parent = trim((string)($row['parent_category'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            if ($parent === '' || $name === '') {
                continue;
            }
            if (!isset($subcategories[$parent])) {
                $subcategories[$parent] = [];
            }
            $label = expense_form_label($name);
            $subcategories[$parent][] = [
                'value' => strtolower(str_replace(' ', '_', $label)),
                'label' => $label,
            ];
        }
    }

    $paymentParts = [];
    if (api_table_exists($pdo, 'orders')) {
        $paymentParts[] = "
            SELECT DISTINCT payment_method AS value
            FROM orders
            WHERE payment_method IS NOT NULL AND payment_method <> ''
        ";
    }
    if (api_table_exists($pdo, 'note_options')) {
        $paymentParts[] = "
            SELECT DISTINCT option_text AS value
            FROM note_options
            WHERE option_text IS NOT NULL AND option_text <> '' AND is_active = 1 AND is_admin_active = 1
        ";
    }
    $paymentMethods = [];
    if ($paymentParts) {
        $paymentMethods = expense_form_option_rows($pdo, "
            SELECT value, value AS label
            FROM (" . implode(' UNION ', $paymentParts) . ") payment_options
            ORDER BY value
            LIMIT 150
        ");
    }

    $companies = api_table_exists($pdo, 'companies')
        ? expense_form_option_rows($pdo, "
            SELECT id AS value, name AS label, color AS company_color
            FROM companies
            WHERE active = 1
            ORDER BY name
            LIMIT 300
        ")
        : [];

    $balanceDate = trim((string)($_GET['date'] ?? $_GET['spending_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $balanceDate)) {
        $balanceDate = date('Y-m-d');
    }
    $spendingCode = expense_form_next_spending_code($pdo, $balanceDate);
    $bank = trim((string)($_GET['bank'] ?? $_GET['source'] ?? ''));
    $available = finance_available_balance($pdo, $balanceDate, $bank !== '' ? $bank : null);
    $includeBanks = in_array(strtolower(trim((string)($_GET['include_banks'] ?? ''))), ['1', 'true', 'yes'], true);
    $dashboard = $includeBanks ? finance_dashboard_balances($pdo, $balanceDate) : null;

    $payload = [
        'success' => true,
        'options' => [
            'users' => $users,
            'categories' => $categories,
            'subcategories' => $subcategories,
            'payment_methods' => $paymentMethods,
            'companies' => $companies,
            'spending_code' => $spendingCode,
            'available_balance' => (float)$available['balance'],
            'balance_bank' => (string)$available['bank'],
            'balance_as_of' => (string)$available['as_of'],
            'balance_is_today' => !empty($available['is_today']),
            'default_balance_bank' => finance_balance_default_bank($pdo),
        ],
    ];
    if (is_array($dashboard)) {
        $payload['options']['total_balance'] = (float)$dashboard['total_balance'];
        $payload['options']['bank_balances'] = $dashboard['banks'];
        $payload['options']['balance_dashboard_as_of'] = (string)$dashboard['as_of'];
        $payload['options']['balance_dashboard_is_today'] = !empty($dashboard['is_today']);
    }

    api_json($payload);
} catch (Throwable $e) {
    error_log('expense_form_options API error: ' . $e->getMessage());
    api_error('Unable to load expense form options.', 500);
}
