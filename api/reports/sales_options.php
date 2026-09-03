<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_role_or_permission(
    ['admin'],
    'sr_sales_dashboard.view',
    'sr_financial_summary.view',
    'sr_income_statement.view',
    'sr_orders.view',
    'sr_sold_products.view',
    'sr_cashflow.view',
    'sr_expense_records.view',
    'sr_expense_categories.view',
    'sr_expense_approvals.view',
    'sr_expense_reports.view',
    'sr_expense_subcategory_report.view',
    'sr_expense_settings.view',
    'sr_expense_companies.view',
    'sr_bank_balances.view',
    'financial_summary.view',
    'daily_summary.view',
    'sold_products.view',
    'cashflow.view'
);

function report_option_rows(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function ensure_report_companies(PDO $pdo): void
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

try {
    $pdo = get_db_connection();
    ensure_report_companies($pdo);

    api_json([
        'success' => true,
        'options' => [
            'branches' => report_option_rows($pdo, "
                SELECT DISTINCT location AS value, location AS label
                FROM orders
                WHERE location IS NOT NULL AND location <> ''
                ORDER BY location
                LIMIT 200
            "),
            'customers' => report_option_rows($pdo, "
                SELECT DISTINCT customer_name AS value, customer_name AS label
                FROM orders
                WHERE customer_name IS NOT NULL AND customer_name <> ''
                ORDER BY customer_name
                LIMIT 300
            "),
            'payment_methods' => report_option_rows($pdo, "
                SELECT value, value AS label
                FROM (
                    SELECT DISTINCT payment_method AS value
                    FROM orders
                    WHERE payment_method IS NOT NULL AND payment_method <> ''
                    UNION
                    SELECT DISTINCT option_text AS value
                    FROM note_options
                    WHERE option_text IS NOT NULL AND option_text <> '' AND is_active = 1 AND is_admin_active = 1
                ) payment_options
                ORDER BY value
                LIMIT 150
            "),
            'delivery_by' => report_option_rows($pdo, "
                SELECT value, value AS label
                FROM (
                    SELECT DISTINCT delivery_by AS value
                    FROM out_items
                    WHERE delivery_by IS NOT NULL AND delivery_by <> ''
                    UNION
                    SELECT 'not_delivered' AS value
                ) delivery_options
                ORDER BY CASE WHEN value = 'not_delivered' THEN 0 ELSE 1 END, value
                LIMIT 200
            "),
            'sellers' => report_option_rows($pdo, "
                SELECT id AS value, COALESCE(NULLIF(name, ''), username) AS label
                FROM users
                WHERE active = 1
                ORDER BY label
                LIMIT 300
            "),
            'products' => report_option_rows($pdo, "
                SELECT id AS value, CONCAT(name, CASE WHEN sku IS NULL OR sku = '' THEN '' ELSE CONCAT(' (', sku, ')') END) AS label
                FROM products
                WHERE active = 1
                ORDER BY name
                LIMIT 500
            "),
            'brands' => report_option_rows($pdo, "
                SELECT id AS value, name AS label, color AS brand_color
                FROM brands
                WHERE active = 1
                ORDER BY name
                LIMIT 300
            "),
            'companies' => report_option_rows($pdo, "
                SELECT id AS value, name AS label, color AS company_color
                FROM companies
                WHERE active = 1
                ORDER BY name
                LIMIT 300
            "),
            'categories' => report_option_rows($pdo, "
                SELECT id AS value, category_name AS label
                FROM stock_categories
                ORDER BY category_name
                LIMIT 300
            "),
        ],
    ]);
} catch (Throwable $e) {
    error_log('sales_options API error: ' . $e->getMessage());
    api_error('Unable to load sales report options.', 500);
}
