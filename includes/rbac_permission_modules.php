<?php
/**
 * Shared RBAC module definitions for admin UI and Vite API.
 * Operation System and System Report use DIFFERENT permission keys.
 * Operation: orders.view, sold_products.view, ...
 * System Report: sr_sold_products.view, sr_product_costing.view, ...
 */

function rbac_permission_actions(): array
{
    return ['view', 'create', 'update', 'delete', 'approve', 'manage'];
}

function rbac_operation_modules(): array
{
    // Category names + labels match Operation System sidebar (layout/header.php).
    return [
        'Dashboard' => [
            ['resource' => 'statistics', 'label' => 'Statistics'],
            ['resource' => 'daily_summary', 'label' => 'Daily Summary'],
            ['resource' => 'activity', 'label' => 'Daily Activity'],
        ],
        'Sales' => [
            ['resource' => 'orders', 'label' => 'Order Management'],
            ['resource' => 'sold_products', 'label' => 'Sold Products'],
            ['resource' => 'receipts', 'label' => 'Create Receipt / History Receipt'],
            ['resource' => 'print_orders', 'label' => 'Print Order'],
            ['resource' => 'print_history', 'label' => 'Print Sessions / Print History'],
            ['resource' => 'cashier_date', 'label' => 'Set Date'],
            ['resource' => 'order_filter', 'label' => 'Order Filter'],
            ['resource' => 'broadcast', 'label' => 'Send Message'],
            ['resource' => 'cancelled_orders', 'label' => 'Cancelled Orders (Cashier)'],
        ],
        'Report' => [
            ['resource' => 'daily_summary', 'label' => 'Daily Summary', 'actions' => ['view']],
            ['resource' => 'return_report', 'label' => 'Return Report', 'actions' => ['view']],
            ['resource' => 'product_return_report', 'label' => 'Product Return Report', 'actions' => ['view']],
            ['resource' => 'cashflow', 'label' => 'Cash Flow Report', 'actions' => ['view']],
            ['resource' => 'finance_dashboard', 'label' => 'Finance Dashboard', 'actions' => ['view']],
            ['resource' => 'finance_reports', 'label' => 'Finance Reports', 'actions' => ['view']],
            ['resource' => 'purchase_reports', 'label' => 'Purchase Reports', 'actions' => ['view']],
            ['resource' => 'stock_reports', 'label' => 'Stock Reports', 'actions' => ['view']],
            ['resource' => 'closing_report', 'label' => 'Closing Report', 'actions' => ['view']],
            ['resource' => 'accountant_daily', 'label' => 'Accountant Daily Reports', 'actions' => ['view']],
            ['resource' => 'accountant_product', 'label' => 'Accountant Product Reports', 'actions' => ['view']],
            ['resource' => 'delivery_report', 'label' => 'Delivery Report', 'actions' => ['view']],
            ['resource' => 'marketing_take_report', 'label' => 'Market Suggest Report', 'actions' => ['view']],
            ['resource' => 'reports_data', 'label' => 'Report Builder Data Access', 'actions' => ['view']],
        ],
        'Marketing' => [
            ['resource' => 'marketing_take_report', 'label' => 'Market Suggest Report', 'actions' => ['view']],
            ['resource' => 'marketing_take', 'label' => 'Create Market Take'],
            ['resource' => 'marketing_take_approve', 'label' => 'Approve Market Take'],
            ['resource' => 'marketing_take_reconcile', 'label' => 'Reconcile Market Take'],
            ['resource' => 'marketing_take_view_all', 'label' => 'View All Markets', 'actions' => ['view']],
        ],
        'Inventory' => [
            ['resource' => 'inventory', 'label' => 'Inventory & Cashier'],
            ['resource' => 'inventory_view', 'label' => 'Inventory View'],
            ['resource' => 'inventory_box_settings', 'label' => 'Set Box to Unit'],
            ['resource' => 'stock_dashboard', 'label' => 'Stock Dashboard', 'actions' => ['view']],
            ['resource' => 'stock_operations', 'label' => 'Stock In/Out / Product Movement'],
            ['resource' => 'storage_locations', 'label' => 'Storage Locations'],
            ['resource' => 'storage_receipts', 'label' => 'Storage Receipts'],
            ['resource' => 'categories', 'label' => 'Categories'],
            ['resource' => 'eod_eom_reports', 'label' => 'EOD/EOM Reports'],
            ['resource' => 'stock_reports', 'label' => 'Stock Reports'],
            ['resource' => 'order_audit', 'label' => 'Order Edit Audit'],
        ],
        'Offline Management' => [
            ['resource' => 'offline_stock', 'label' => 'Stock Offline', 'actions' => ['view']],
            ['resource' => 'offline_sales', 'label' => 'Sale Orders'],
            ['resource' => 'offline_sellers', 'label' => 'Offline Team', 'actions' => ['manage']],
            ['resource' => 'offline_daily_report', 'label' => 'Daily Offline Sales / Closing Report', 'actions' => ['view']],
        ],
        'Product Management' => [
            ['resource' => 'product_costs', 'label' => 'Product Cost'],
            ['resource' => 'product_sets', 'label' => 'Product Set / Product Set Report'],
            ['resource' => 'lucky_box_sets', 'label' => 'Lucky Box Sets'],
            ['resource' => 'product_set_qr_code_settings', 'label' => 'Set QR Custom Code', 'actions' => ['view', 'create', 'update']],
            ['resource' => 'product_set_qr_labels', 'label' => 'Set QR Labels', 'actions' => ['view', 'create']],
            ['resource' => 'product_set_qr_label_history', 'label' => 'QR Label History', 'actions' => ['view']],
            ['resource' => 'brands', 'label' => 'Brands'],
        ],
        'Purchasing' => [
            ['resource' => 'purchase_orders', 'label' => 'Purchase Orders / Invoice Settings'],
            ['resource' => 'purchase_vendors', 'label' => 'Vendors'],
            ['resource' => 'purchase_receiving', 'label' => 'Receiving / Returns to Vendor'],
            ['resource' => 'purchase_payments', 'label' => 'Purchase Payments'],
            ['resource' => 'purchase_reports', 'label' => 'Purchase Reports / Vendor Product Detail'],
        ],
        'Finance & Costs' => [
            ['resource' => 'finance_dashboard', 'label' => 'Dashboard / Top Up / Top Up History'],
            ['resource' => 'spending', 'label' => 'Add Spending'],
            ['resource' => 'finance_reports', 'label' => 'Spending History / by Sub-Category'],
            ['resource' => 'manage_categories', 'label' => 'Manage Categories'],
            ['resource' => 'cost_history', 'label' => 'Price History (Legacy)'],
            ['resource' => 'profit_analysis', 'label' => 'Profit Analysis (Legacy)'],
            ['resource' => 'cost_reports', 'label' => 'Cost Reports (Legacy)'],
        ],
        'Return Management' => [
            ['resource' => 'return_report', 'label' => 'Return Report'],
            ['resource' => 'product_return_report', 'label' => 'Product Return Report'],
        ],
        'Cash Flow' => [
            ['resource' => 'cashflow', 'label' => 'Summary / Report / By Payment Method'],
            ['resource' => 'cashflow_categories', 'label' => 'Categories'],
            ['resource' => 'cashflow_topup', 'label' => 'Top Up / Top Up History'],
            ['resource' => 'cashflow_spending', 'label' => 'Add Spending / Spending History'],
            ['resource' => 'bank_transfer', 'label' => 'Transfer to Other Bank / Transfer History'],
        ],
        'Accounting' => [
            ['resource' => 'accountant_dashboard', 'label' => 'Dashboard'],
            ['resource' => 'accountant_daily', 'label' => 'Daily Reports'],
            ['resource' => 'accountant_product', 'label' => 'Product Reports'],
            ['resource' => 'financial_summary', 'label' => 'Financial Summary'],
            ['resource' => 'closing_report', 'label' => 'Closing Report'],
            ['resource' => 'delivery_report', 'label' => 'Delivery Report'],
            ['resource' => 'commission_sales', 'label' => 'Commission Sales'],
            ['resource' => 'payment_management', 'label' => 'Payment Management'],
            ['resource' => 'telegram_bot_reminder', 'label' => 'Telegram Reminder Bot'],
        ],
        'Scanner Config' => [
            ['resource' => 'scanner_home', 'label' => 'AI Scan Home'],
            ['resource' => 'items_view', 'label' => 'View All Items'],
            ['resource' => 'out_items_delivery_by', 'label' => 'Delivery By Config'],
        ],
        'Seller' => [
            ['resource' => 'seller_orders', 'label' => 'New Order (Seller)'],
            ['resource' => 'seller_statistics', 'label' => 'Seller Statistics'],
            ['resource' => 'order_history', 'label' => 'Order History'],
        ],
        'Setup' => [
            ['resource' => 'pages', 'label' => 'Pages'],
            ['resource' => 'delivery_types', 'label' => 'Delivery Types'],
            ['resource' => 'delivery_costs', 'label' => 'Delivery Price'],
            ['resource' => 'logos', 'label' => 'Logos'],
            ['resource' => 'note_options', 'label' => 'Note Options'],
            ['resource' => 'money_exchange', 'label' => 'Money Exchange'],
        ],
        'Administration' => [
            ['resource' => 'users', 'label' => 'Users'],
            ['resource' => 'users_activity', 'label' => 'User activity', 'actions' => ['view']],
            ['resource' => 'roles', 'label' => 'Roles'],
            ['resource' => 'role_permissions', 'label' => 'Role Permissions'],
            ['resource' => 'maintenance', 'label' => 'Maintenance'],
            ['resource' => 'maintenance_bypass', 'label' => 'Bypass Maintenance'],
        ],
    ];
}

function rbac_system_report_modules(): array
{
    return [
        'Report' => [
            ['resource' => 'sr_sales_dashboard', 'label' => 'Sales Dashboard', 'actions' => ['view']],
            ['resource' => 'sr_financial_summary', 'label' => 'Revenue', 'actions' => ['view']],
            ['resource' => 'sr_inventory_sold_offline', 'label' => 'Stock Sold Offline', 'actions' => ['view']],
            ['resource' => 'sr_inventory_sold_online', 'label' => 'Stock Sold Online', 'actions' => ['view']],
            ['resource' => 'sr_purchase_reports', 'label' => 'Purchase report', 'actions' => ['view']],
            ['resource' => 'sr_expense_reports', 'label' => 'Expense Report', 'actions' => ['view']],
            ['resource' => 'sr_expense_topup', 'label' => 'Top Up Report', 'actions' => ['view', 'create', 'update', 'delete']],
            ['resource' => 'sr_cashflow', 'label' => 'Cash Flow', 'actions' => ['view']],
            ['resource' => 'sr_income_statement', 'label' => 'Income Statement', 'actions' => ['view']],
            ['resource' => 'sr_product_costing', 'label' => 'Online Product Costing', 'actions' => ['view', 'update']],
            ['resource' => 'sr_offline_product_costing', 'label' => 'Offline Product Costing', 'actions' => ['view', 'update']],
        ],
        'Sale' => [
            ['resource' => 'sr_orders', 'label' => 'Online Sale', 'actions' => ['view', 'create', 'update']],
            ['resource' => 'sr_offline_orders', 'label' => 'Offline New Order', 'actions' => ['view', 'create', 'update']],
            ['resource' => 'receipts', 'label' => 'Create Receipt'],
        ],
        'Operation' => [
            ['resource' => 'print_orders', 'label' => 'Print Order'],
            ['resource' => 'print_history', 'label' => 'Print Session / Print History', 'actions' => ['view']],
            ['resource' => 'broadcast', 'label' => 'Send Message'],
        ],
        'Marketing' => [
            ['resource' => 'sr_marketing_suggest_report', 'label' => 'Marketing Report', 'actions' => ['view']],
            ['resource' => 'sr_marketing_create_take', 'label' => 'Marketing Request', 'actions' => ['view', 'create']],
            ['resource' => 'sr_marketing_approve_take', 'label' => 'Marketing Approval', 'actions' => ['view', 'approve']],
            ['resource' => 'sr_marketing_reconcile_take', 'label' => 'Marketing Reconcile', 'actions' => ['view', 'update']],
            ['resource' => 'sr_marketing_type', 'label' => 'Marketing Type', 'actions' => ['view', 'create', 'update', 'delete']],
        ],
        'Expense' => [
            ['resource' => 'sr_expense_records', 'label' => 'Expense Records', 'actions' => ['view', 'create', 'update', 'delete']],
            ['resource' => 'sr_expense_categories', 'label' => 'Expense Categories', 'actions' => ['view', 'create', 'update', 'delete']],
            ['resource' => 'sr_expense_approvals', 'label' => 'Expense Approvals', 'actions' => ['view', 'update']],
            ['resource' => 'sr_expense_subcategory_report', 'label' => 'Expense Sub Category Report', 'actions' => ['view']],
            ['resource' => 'sr_expense_settings', 'label' => 'Expense Settings', 'actions' => ['view', 'create', 'update', 'delete']],
        ],
        'Dealer' => [
            ['resource' => 'sr_dealers', 'label' => 'Dealers', 'actions' => ['view', 'create', 'update', 'delete']],
            ['resource' => 'sr_dealer_orders', 'label' => 'Dealer Orders', 'actions' => ['view', 'create', 'update', 'delete']],
            ['resource' => 'sr_dealer_payments', 'label' => 'Dealer Payments', 'actions' => ['view', 'create', 'update', 'delete']],
            ['resource' => 'sr_dealer_reports', 'label' => 'Dealer Reports', 'actions' => ['view']],
        ],
        'Purchase' => [
            ['resource' => 'sr_purchase_orders', 'label' => 'Purchase Orders', 'actions' => ['view', 'create', 'update', 'delete']],
            ['resource' => 'sr_purchase_receiving', 'label' => 'Receiving', 'actions' => ['view', 'create', 'update']],
            ['resource' => 'sr_purchase_returns', 'label' => 'Return to Supplier', 'actions' => ['view', 'create', 'update', 'delete']],
            ['resource' => 'sr_purchase_suppliers', 'label' => 'Suppliers', 'actions' => ['view', 'create', 'update', 'delete']],
            ['resource' => 'sr_purchase_payments', 'label' => 'Supplier Payments', 'actions' => ['view', 'create', 'update', 'delete']],
        ],
        'Inventory' => [
            ['resource' => 'sr_inventory_onhand', 'label' => 'Stock On Hand', 'actions' => ['view']],
            ['resource' => 'sr_inventory_movements', 'label' => 'Stock Movements', 'actions' => ['view', 'create']],
            ['resource' => 'sr_inventory_adjustment', 'label' => 'Stock Adjustment', 'actions' => ['view', 'create']],
            ['resource' => 'sr_inventory_transfer', 'label' => 'Stock Transfer', 'actions' => ['view', 'create']],
            ['resource' => 'sr_inventory_delivery_notes', 'label' => 'Delivery Note Report', 'actions' => ['view', 'update', 'delete']],
            ['resource' => 'sr_inventory_closing', 'label' => 'Stock Closing', 'actions' => ['view', 'create', 'update', 'delete', 'approve']],
        ],
        'Return' => [
            ['resource' => 'sr_return_report', 'label' => 'Return Report', 'actions' => ['view']],
            ['resource' => 'sr_product_return_report', 'label' => 'Product Return Report', 'actions' => ['view']],
        ],
        'Financial' => [
            ['resource' => 'sr_expense_companies', 'label' => 'Companies', 'actions' => ['view', 'create', 'update', 'delete']],
            ['resource' => 'sr_bank_balances', 'label' => 'Bank Balances', 'actions' => ['view', 'create', 'update', 'delete']],
            ['resource' => 'sr_month_end_closing', 'label' => 'Month-End Closing', 'actions' => ['view', 'update', 'approve', 'close']],
        ],
        'Offline Management' => [
            ['resource' => 'sr_daily_offline_sale', 'label' => 'Offline Sales Report', 'actions' => ['view']],
            ['resource' => 'sr_offline_buy_report', 'label' => 'Offline Buy Report', 'actions' => ['view']],
        ],
        'Online Management' => [
            ['resource' => 'sr_sold_products', 'label' => 'Online Sold Products', 'actions' => ['view']],
        ],
        'Administration' => [
            ['resource' => 'sr_role_permissions', 'label' => 'Role Permissions', 'actions' => ['view', 'update']],
            ['resource' => 'sr_users', 'label' => 'User Management', 'actions' => ['view', 'create', 'update', 'delete']],
            ['resource' => 'sr_user_activity', 'label' => 'User Activity', 'actions' => ['view']],
            ['resource' => 'sr_pin_control', 'label' => 'PIN Code Control', 'actions' => ['view', 'update']],
        ],
        'Settings' => [
            ['resource' => 'sr_notification_settings', 'label' => 'Notification Settings', 'actions' => ['view', 'update']],
            ['resource' => 'logos', 'label' => 'App Logo'],
            ['resource' => 'sr_invoice_settings', 'label' => 'Invoice Settings', 'actions' => ['view', 'update']],
        ],
    ];
}

/**
 * Flat modules map used by PHP admin + API (operation + system report).
 */
function rbac_permission_modules(): array
{
    $modules = rbac_operation_modules();
    foreach (rbac_system_report_modules() as $name => $resources) {
        $modules['System Report - ' . $name] = $resources;
    }
    return $modules;
}

function rbac_permission_defs(): array
{
    $actionsDefault = ['view', 'create', 'update', 'delete'];
    $defs = [];
    foreach (rbac_permission_modules() as $resources) {
        foreach ($resources as $resource) {
            $res = (string)$resource['resource'];
            $label = (string)$resource['label'];
            $actions = $resource['actions'] ?? $actionsDefault;
            foreach ($actions as $action) {
                $key = $res . '.' . $action;
                $prefix = (strpos($res, 'sr_') === 0) ? 'System Report - ' : 'Operation - ';
                $defs[$key] = [
                    'key' => $key,
                    'label' => $prefix . $label . ' - ' . ucfirst((string)$action),
                    'description' => '',
                ];
            }
        }
    }
    return array_values($defs);
}

function rbac_system_report_permission_keys(): array
{
    $keys = [];
    foreach (rbac_system_report_modules() as $resources) {
        foreach ($resources as $resource) {
            $res = (string)$resource['resource'];
            foreach (($resource['actions'] ?? ['view', 'create', 'update', 'delete']) as $action) {
                $keys[] = $res . '.' . $action;
            }
        }
    }
    return $keys;
}

function rbac_default_role_permission_keys(): array
{
    return [
        'seller' => [
            'seller_orders.view', 'seller_orders.create', 'seller_orders.update',
            'seller_statistics.view', 'order_history.view', 'items_view.view', 'scanner_home.view',
            'orders.view', 'sold_products.view', 'receipts.view', 'inventory_view.view', 'categories.view',
        ],
        'cashier' => [
            'print_orders.view', 'print_orders.create', 'print_history.view', 'cashier_date.view',
            'broadcast.view', 'cancelled_orders.view', 'inventory.view', 'inventory_view.view',
            'orders.view', 'sold_products.view', 'receipts.view', 'categories.view', 'scanner_home.view',
        ],
        'scanner' => [
            'scanner_home.view', 'scanner_home.create', 'scanner_home.update', 'scanner_home.delete',
            'items_view.view', 'seller_orders.view', 'order_history.view', 'inventory_view.view',
            'categories.view', 'out_items_delivery_by.view', 'out_items_delivery_by.create',
            'out_items_delivery_by.update', 'out_items_delivery_by.delete',
        ],
    ];
}
