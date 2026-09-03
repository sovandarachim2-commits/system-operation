<?php
require_once __DIR__ . '/auth.php';

$user = current_user();
if (!$user) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Location: ' . auth_login_path());
    exit;
}

$pdo = get_db_connection();
$roles = [];
if (function_exists('user_role_names')) {
    $roles = user_role_names($pdo, $user);
}

// Fallback to legacy single-role if multi-role tables are not present
if (!$roles) {
    $roles = [(string)($user['role'] ?? '')];
}

$hasPerm = static function (string $perm): bool {
    return function_exists('has_permission') ? has_permission($perm) : false;
};

$redirectTo = static function (string $path): void {
    header('Location: ' . $path);
    exit;
};

// First allowed cashier page based on real permissions (not just role name).
$cashierLandingByPermission = [
    'cashier_date.view' => 'cashier/dashboard.php',
    'print_orders.view' => 'cashier/print_orders.php',
    'print_history.view' => 'cashier/print_sessions.php',
    'cancelled_orders.view' => 'cashier/cancelled_orders.php',
    'broadcast.view' => 'cashier/broadcast.php',
    'inventory.view' => 'cashier/inventory.php',
    'scanner_home.view' => 'scanner/home.php',
];

$firstCashierLanding = static function () use ($hasPerm, $cashierLandingByPermission): ?string {
    foreach ($cashierLandingByPermission as $perm => $path) {
        if ($hasPerm($perm)) {
            return $path;
        }
    }
    return null;
};

// Route by explicit role first (seller/cashier/scanner must not be sent to admin)
if (in_array('seller', $roles, true)) {
    $redirectTo('seller/statistics.php');
}
if (in_array('cashier', $roles, true)) {
    // Exact legacy cashier role can open dashboard via role bypass.
    // Still prefer a page they are allowed to open if RBAC is used.
    $redirectTo($firstCashierLanding() ?: 'cashier/dashboard.php');
}
if (in_array('scanner', $roles, true)) {
    $redirectTo('scanner/home.php');
}

if (in_array('admin', $roles, true)) {
    $redirectTo('admin/statistics.php');
}

// For permission-based users (non-admin roles), choose a landing page they can access.
$adminLandingByPermission = [
    'statistics.view' => 'admin/statistics.php',
    'marketing_take_report.view' => 'admin/marketing_take_report.php',
    'marketing_take.create' => 'admin/marketing_take_create.php',
    'marketing_take.view' => 'admin/marketing_take_list.php',
    'marketing_take_approve.view' => 'admin/marketing_take_approve.php',
    'marketing_take_reconcile.view' => 'admin/marketing_take_reconcile.php',
    'orders.view' => 'admin/order_management.php',
    'inventory.view' => 'admin/products.php',
    'inventory_view.view' => 'admin/inventory_view.php',
    'stock_operations.view' => 'admin/stock_operations.php',
    'finance_dashboard.view' => 'admin/finance_dashboard.php',
    'finance_reports.view' => 'admin/finance_reports.php',
    'spending.view' => 'admin/add_spending.php',
    'cashflow.view' => 'admin/cashflow.php',
    'cashflow_spending.view' => 'admin/cashflow_add_spending.php',
    'cashflow_topup.view' => 'admin/cashflow_topup_add.php',
    'bank_transfer.view' => 'admin/bank_transfer_add.php',
    'users.view' => 'admin/users.php',
    'role_permissions.view' => 'admin/role_permissions.php',
];

foreach ($adminLandingByPermission as $perm => $path) {
    if ($hasPerm($perm)) {
        $redirectTo($path);
    }
}

// Custom roles with seller-style permissions
if ($hasPerm('seller_statistics.view') || $hasPerm('seller_orders.view') || $hasPerm('order_history.view')) {
    $redirectTo('seller/statistics.php');
}

// Cashier-style custom roles (e.g. cashier_002): land on first page they can open
$cashierPath = $firstCashierLanding();
if ($cashierPath) {
    $redirectTo($cashierPath);
}

if ($hasPerm('scanner_home.view') || $hasPerm('items_view.view')) {
    $redirectTo('scanner/home.php');
}

// Logged-in user but no allowed page: show explicit access-denied page instead of looping to login.
if (function_exists('access_denied_response')) {
    access_denied_response();
}
header('Location: ' . auth_login_path());
exit;
