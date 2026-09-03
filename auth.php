<?php

require_once __DIR__ . '/db.php';

function auth_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return !empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
}

function auth_start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $sessionPath = parse_url((string)($GLOBALS['BASE_URL'] ?? '/'), PHP_URL_PATH);
    if (!is_string($sessionPath) || $sessionPath === '') {
        $sessionPath = '/';
    }

    session_name('ORDER_SHADOW_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $sessionPath,
        'domain' => '',
        'secure' => auth_request_is_https(),
        'httponly' => true,
        'samesite' => auth_request_is_https() ? 'None' : 'Lax',
    ]);
    session_start();
}

auth_start_session();

/**
 * Cross-origin System Report (Vercel) support:
 * - Restore session from report token when PHP session cookie is missing.
 * - Emit CORS headers for allowed report frontends so admin AJAX can be read.
 */
function auth_apply_report_cors(): void
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $allowed = $GLOBALS['API_ALLOWED_ORIGINS'] ?? [];
    if ($origin === '' || !is_array($allowed) || !in_array($origin, $allowed, true)) {
        return;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Accept, Cache-Control, Authorization, X-Report-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Vary: Origin');
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function auth_restore_user_from_report_token(): void
{
    if (!empty($_SESSION['user_id'])) {
        return;
    }
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $token = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $match)) {
        $token = trim($match[1]);
    }
    if ($token === '') {
        $token = trim((string)($_SERVER['HTTP_X_REPORT_TOKEN'] ?? ''));
    }
    if ($token === '' || strpos($token, '.') === false) {
        return;
    }

    $secret = (string)($GLOBALS['REPORT_API_TOKEN_SECRET'] ?? '');
    if ($secret === '') {
        $secret = hash('sha256', __DIR__ . '|' . (string)($GLOBALS['DB_PASS'] ?? '') . '|ordershadow-report-api');
    }

    [$payload, $signature] = explode('.', $token, 2);
    $expected = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $signature)) {
        return;
    }

    $padding = strlen($payload) % 4;
    if ($padding > 0) {
        $payload .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
    $data = json_decode($decoded === false ? '' : $decoded, true);
    if (!is_array($data) || (int)($data['exp'] ?? 0) < time()) {
        return;
    }
    $userId = max(0, (int)($data['uid'] ?? 0));
    if ($userId > 0) {
        $_SESSION['user_id'] = $userId;
    }
}

auth_apply_report_cors();
auth_restore_user_from_report_token();

/**
 * RBAC / Permissions
 * - Roles are still stored on `users.role` (single role per user).
 * - Permissions are stored in DB tables:
 *     permissions(perm_key, label, description)
 *     role_permissions(role, permission_id)
 */
function rbac_is_enabled(PDO $pdo): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'permissions'");
        $hasPermissions = (bool)$stmt->fetchColumn();
        $stmt = $pdo->query("SHOW TABLES LIKE 'role_permissions'");
        $hasRolePerms = (bool)$stmt->fetchColumn();
        $stmt = $pdo->query("SHOW TABLES LIKE 'roles'");
        $hasRoles = (bool)$stmt->fetchColumn();
        $stmt = $pdo->query("SHOW TABLES LIKE 'user_roles'");
        $hasUserRoles = (bool)$stmt->fetchColumn();

        // Backwards compatible: permissions + role_permissions are minimum
        $enabled = $hasPermissions && $hasRolePerms;
        return $enabled;
    } catch (Throwable $e) {
        $enabled = false;
        return false;
    }
}

function rbac_supports_multi_roles(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'roles'");
        $hasRoles = (bool)$stmt->fetchColumn();
        $stmt = $pdo->query("SHOW TABLES LIKE 'user_roles'");
        $hasUserRoles = (bool)$stmt->fetchColumn();
        return $hasRoles && $hasUserRoles;
    } catch (Throwable $e) {
        return false;
    }
}

function role_permissions_uses_role_id(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'role_id'");
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function user_role_names(PDO $pdo, array $user): array
{
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) {
        return [];
    }

    // Multi-role mode
    if (rbac_supports_multi_roles($pdo)) {
        $stmt = $pdo->prepare("
            SELECT r.name
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ?
        ");
        $stmt->execute([$uid]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $names = array_values(array_unique(array_filter(array_map('strval', $names ?: []))));
        if ($names) {
            return $names;
        }

        // No user_roles found: sync from legacy users.role if set
        $role = trim((string)($user['role'] ?? ''));
        if ($role !== '') {
            $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
            $roleStmt->execute([$role]);
            $roleId = $roleStmt->fetchColumn();
            if ($roleId) {
                $pdo->prepare("INSERT IGNORE INTO user_roles(user_id, role_id) VALUES(?, ?)")
                    ->execute([$uid, (int)$roleId]);
            }
            return [$role];
        }
    }

    // Legacy single role in users.role
    $role = (string)($user['role'] ?? '');
    return $role !== '' ? [$role] : [];
}

function user_permissions(PDO $pdo, array $user): array
{
    static $cache = [];
    $uid = (int)($user['id'] ?? 0);
    if ($uid > 0 && array_key_exists($uid, $cache)) {
        return $cache[$uid];
    }

    if (!rbac_is_enabled($pdo)) {
        $cache[$uid] = [];
        return [];
    }

    $roles = user_role_names($pdo, $user);
    if (!$roles) {
        $cache[$uid] = [];
        return [];
    }

    // Support both schema styles:
    // - old role_permissions(role VARCHAR)
    // - new role_permissions(role_id INT) with roles table
    $perms = [];
    if (role_permissions_uses_role_id($pdo)) {
        // New schema: role_permissions.role_id Ã¢â€ â€™ roles.id
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.perm_key
            FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
            JOIN roles r ON r.id = rp.role_id
            WHERE r.name IN (" . implode(',', array_fill(0, count($roles), '?')) . ")
        ");
        $stmt->execute($roles);
        $perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // Old schema: role_permissions.role (role name string)
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.perm_key
            FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role IN (" . implode(',', array_fill(0, count($roles), '?')) . ")
        ");
        $stmt->execute($roles);
        $perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $cache[$uid] = array_values(array_unique(array_map('strval', $perms ?: [])));
    return $cache[$uid];
}

function has_permission(string $permKey): bool
{
    $permKey = trim($permKey);
    if ($permKey === '') {
        return false;
    }

    $user = current_user();
    if (!$user) {
        return false;
    }

    $pdo = get_db_connection();

    // If RBAC tables not installed yet, keep legacy behavior:
    // admins can access admin pages; non-admins cannot.
    if (!rbac_is_enabled($pdo)) {
        return ($user['role'] ?? '') === 'admin';
    }

    $perms = user_permissions($pdo, $user);
    return in_array($permKey, $perms, true);
}

function access_denied_response(): void
{
    http_response_code(403);
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $wantsJson = !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    if ($isAjax || $wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Access denied', 'message' => 'Access denied']);
        exit;
    }
    $baseUrl = '/';
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
        $baseUrl = $GLOBALS['BASE_URL'] ?? '/';
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f8f9fa; }
        .access-denied-card { max-width: 400px; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="modal show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content access-denied-card border-danger">
                <div class="modal-header border-danger bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-shield-exclamation me-2"></i>Access Denied</h5>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="bi bi-lock-fill text-danger" style="font-size: 3rem;"></i>
                    <p class="mb-0 mt-3">You do not have permission to access this resource.</p>
                </div>
                <div class="modal-footer justify-content-center border-0 pb-4">
                    <a href="<?= htmlspecialchars(rtrim($baseUrl, '/') . '/index.php') ?>" class="btn btn-primary"><i class="bi bi-house me-1"></i> Go to Home</a>
                    <button type="button" class="btn btn-outline-secondary" onclick="history.back()"><i class="bi bi-arrow-left me-1"></i> Go Back</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html><?php
    exit;
}

function require_permission(string $permKey): void
{
    require_login();
    if (!has_permission($permKey)) {
        access_denied_response();
    }
}

/**
 * Allow access if user has any of the given roles OR has any of the given permissions.
 * Use this when you want custom roles (e.g. "suerf") to access pages by permission.
 * @param array $roles e.g. ['admin']
 * @param string ...$permKeys e.g. 'marketing_take.view', 'marketing_take_report.view'
 */
function require_role_or_permission(array $roles, string ...$permKeys): void
{
    require_login();
    $user = current_user();
    if (!$user) {
        access_denied_response();
    }

    $pdo = get_db_connection();
    $userRoles = user_role_names($pdo, $user);
    foreach ($userRoles as $r) {
        if (in_array($r, $roles, true)) {
            return; // Has role, allow
        }
    }

    foreach ($permKeys as $permKey) {
        if (has_permission($permKey)) {
            return; // Has permission, allow
        }
    }

    access_denied_response();
}

function current_user(bool $refresh = false)
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    static $cached = null;
    static $loaded = false;
    if ($refresh) {
        $loaded = false;
        $cached = null;
    }
    if ($loaded) {
        return $cached;
    }
    $loaded = true;

    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
    $stmt->execute([$_SESSION['user_id']]);
    $cached = $stmt->fetch() ?: null;
    return $cached;
}

/** Path-absolute URL to login (works from /admin/* and other subpaths). */
function auth_login_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $base = '/';
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
        $base = $GLOBALS['BASE_URL'] ?? '/';
    }
    $path = rtrim($base, '/') . '/login.php';
    return $path;
}

/** Admin shell fetch() sets this header so we return JSON instead of following redirect into HTML. */
function auth_is_admin_nav_fetch(): bool
{
    $v = $_SERVER['HTTP_X_ADMIN_NAV_REQUEST'] ?? '';
    return $v === '1' || strcasecmp((string) $v, 'true') === 0;
}

function auth_respond_login_required(): void
{
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $wantsJson = !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    if (auth_is_admin_nav_fetch() || $isAjax || $wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'result' => 'error',
            'error' => 'session_expired',
            'message' => 'Session expired. Please log in again.',
            'redirect' => auth_login_path(),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Location: ' . auth_login_path());
    exit;
}

/** Treat user as Ã¢â‚¬Å“onlineÃ¢â‚¬Â in admin UI if last_seen_at is within this many seconds. */
if (!defined('AUTH_LAST_SEEN_ONLINE_THRESHOLD')) {
    define('AUTH_LAST_SEEN_ONLINE_THRESHOLD', 300);
}

/** Do not write last_seen_at to DB more often than this (per browser session). */
if (!defined('AUTH_LAST_SEEN_TOUCH_INTERVAL')) {
    define('AUTH_LAST_SEEN_TOUCH_INTERVAL', 90);
}

function auth_ensure_users_last_seen_column(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_seen_at'")->fetch(PDO::FETCH_ASSOC);
        if ($col) {
            $ok = true;
            return true;
        }
        $pdo->exec('ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL DEFAULT NULL');
        $ok = true;
        return true;
    } catch (Throwable $e) {
        error_log('auth_ensure_users_last_seen_column: ' . $e->getMessage());
        $ok = false;
        return false;
    }
}

/** Force last_seen_at = now (e.g. right after login). */
function auth_set_user_last_seen_now(PDO $pdo, int $userId): void
{
    if ($userId <= 0 || !auth_ensure_users_last_seen_column($pdo)) {
        return;
    }
    try {
        $pdo->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('auth_set_user_last_seen_now: ' . $e->getMessage());
    }
}

/**
 * Heartbeat: update current userÃ¢â‚¬â„¢s last_seen_at (throttled). Skipped for admin nav prefetch requests.
 */
function auth_touch_user_last_seen(PDO $pdo, array $loggedUser): void
{
    if (auth_is_admin_nav_fetch()) {
        return;
    }
    $uid = (int)($loggedUser['id'] ?? 0);
    if ($uid <= 0 || !auth_ensure_users_last_seen_column($pdo)) {
        return;
    }
    $now = time();
    $lastTouch = (int)($_SESSION['_last_seen_db_at'] ?? 0);
    if ($lastTouch > 0 && ($now - $lastTouch) < AUTH_LAST_SEEN_TOUCH_INTERVAL) {
        return;
    }
    try {
        $pdo->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = ?')->execute([$uid]);
        $_SESSION['_last_seen_db_at'] = $now;
    } catch (Throwable $e) {
        error_log('auth_touch_user_last_seen: ' . $e->getMessage());
    }
}

function auth_user_is_online_by_last_seen(?string $lastSeenAt, ?int $thresholdSeconds = null): bool
{
    if ($lastSeenAt === null || $lastSeenAt === '') {
        return false;
    }
    $t = strtotime($lastSeenAt);
    if ($t === false) {
        return false;
    }
    $thr = $thresholdSeconds ?? AUTH_LAST_SEEN_ONLINE_THRESHOLD;
    return (time() - $t) <= $thr;
}

function require_login()
{
    // Check maintenance mode first (except for maintenance page itself)
    $maintenanceFile = __DIR__ . '/.maintenance';
    $isMaintenancePage = strpos($_SERVER['REQUEST_URI'], 'maintenance.php') !== false || strpos($_SERVER['REQUEST_URI'], 'maintenance.html') !== false;
    
    if (file_exists($maintenanceFile) && !$isMaintenancePage) {
        // Allow users with permission to bypass maintenance mode
        if (!isset($_SESSION['user_id'])) {
            // Not logged in, show maintenance page
            include __DIR__ . '/maintenance.html';
            exit;
        }
        
        $user = current_user();
        $pdo = get_db_connection();
        $canBypass = false;
        if ($user) {
            if (rbac_is_enabled($pdo)) {
                $canBypass = has_permission('maintenance_bypass.view');
            } else {
                // Legacy: only main admin username bypass
                $canBypass = (($user['username'] ?? '') === 'admin');
            }
        }

        if (!$canBypass) {
            // Not the main admin, show maintenance page
            include __DIR__ . '/maintenance.html';
            exit;
        }
    }
    
    if (!isset($_SESSION['user_id'])) {
        auth_respond_login_required();
    }

    $loggedUser = current_user();
    if (!$loggedUser) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
            }
            session_destroy();
        }
        auth_respond_login_required();
    }

    $pdoSeen = get_db_connection();
    auth_touch_user_last_seen($pdoSeen, $loggedUser);

    // Log GET page opens (sidebar / menu navigation); skip admin nav prefetch and login screen
    if (!defined('USER_ACTIVITY_PAGE_VIEW_LOGGED')) {
        define('USER_ACTIVITY_PAGE_VIEW_LOGGED', true);
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'GET' && !auth_is_admin_nav_fetch()) {
            $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
            if ($script !== '' && $script !== 'login.php') {
                require_once __DIR__ . '/user_activity_lib.php';
                user_activity_log(get_db_connection(), $loggedUser, 'page_view', $script, [
                    'script' => $script,
                ]);
            }
        }
    }
}

function require_role(array $roles)
{
    require_login();
    $user = current_user();
    if (!$user) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }

    $pdo = get_db_connection();
    $userRoles = user_role_names($pdo, $user);
    $ok = false;
    foreach ($userRoles as $r) {
        if (in_array($r, $roles, true)) {
            $ok = true;
            break;
        }
    }

    if (!$ok) {
        /**
         * Permission fallback:
         * Many pages were originally protected with `require_role(['admin'])`.
         * When you introduce new roles, we allow access by permission as well.
         *
         * We map current script path to a permission key.
         * Example: `admin/statistics.php` => `admin.statistics.view`
         */
        $permByScriptSuffix = [
            // Admin sidebar - resource.action format
            'admin/statistics.php' => 'statistics.view',
            'admin/daily_summary.php' => 'daily_summary.view',
            'admin/daily_seller.php' => 'daily_summary.view',
            'admin/activity.php' => 'activity.view',
            'admin/order_management.php' => 'orders.view',
            'admin/sold_products.php' => 'sold_products.view',
            'admin/create_receipt_order.php' => 'receipts.view',
            'admin/history_receipt.php' => 'receipts.view',
            'admin/edit_receipt_order.php' => 'receipts.view',
            'admin/order_filter.php' => 'order_filter.view',
            'admin/inventory.php' => 'inventory.view',
            'admin/inventory_view.php' => 'inventory_view.view',
            'admin/stock_operations.php' => 'stock_operations.view',
            'admin/product_movement_tracking.php' => 'stock_operations.view',
            'admin/marketing_take_list.php' => 'marketing_take.view',
            'admin/marketing_take_report.php' => 'marketing_take_report.view',
            'admin/marketing_take_create.php' => 'marketing_take.create',
            'admin/marketing_take_edit.php' => 'marketing_take.create',
            'admin/marketing_take_cancel.php' => 'marketing_take.create',
            'admin/marketing_take_approve.php' => 'marketing_take_approve.view',
            'admin/marketing_take_approve_history.php' => 'marketing_take_approve.view',
            'admin/marketing_take_approve_restore.php' => 'marketing_take_approve.view',
            'admin/marketing_take_approve_reverse.php' => 'marketing_take_approve.view',
            'admin/marketing_take_reconcile.php' => 'marketing_take_reconcile.view',
            'admin/marketing_take_reconcile_history.php' => 'marketing_take_reconcile.view',
            'admin/marketing_take_detail.php' => ['marketing_take.view', 'marketing_take_report.view'],
            'admin/generate_marketing_invoice.php' => ['marketing_take.view', 'marketing_take_report.view', 'marketing_take_reconcile.view'],
            'admin/product_set_management.php' => 'product_sets.view',
            'admin/lucky_box_sets.php' => 'lucky_box_sets.view',
            'admin/product_set_qr_code_settings.php' => 'product_set_qr_code_settings.view',
            'admin/product_set_qr_labels.php' => 'product_set_qr_labels.view',
            'admin/product_set_qr_label_history.php' => 'product_set_qr_label_history.view',
            'admin/product_set_report.php' => 'product_sets.view',
            'admin/storage_locations.php' => 'storage_locations.view',
            'admin/storage_receipts.php' => 'storage_receipts.view',
            'admin/stock_categories.php' => 'categories.view',
            'admin/manage_categories.php' => ['manage_categories.view', 'categories.view'],
            'admin/stock_dashboard.php' => 'stock_reports.view',
            'admin/dashboard.php' => 'statistics.view',
            'admin/eod_eom_stock_reports.php' => 'eod_eom_reports.view',
            'admin/stock_reports.php' => 'stock_reports.view',
            'admin/order_edit_audit.php' => 'order_audit.view',
            'admin/purchase_orders.php' => 'purchase_orders.view',
            'admin/purchase_vendors.php' => 'purchase_vendors.view',
            'admin/purchase_receiving.php' => 'purchase_receiving.view',
            'admin/purchase_receiving_history.php' => 'purchase_receiving.view',
            'admin/purchase_returns.php' => 'purchase_receiving.view',
            'admin/purchase_return_history.php' => 'purchase_receiving.view',
            'admin/get_return_details.php' => 'purchase_receiving.view',
            'admin/delete_purchase_return.php' => 'purchase_receiving.delete',
            'admin/get_purchase_order_items_for_return.php' => 'purchase_receiving.view',
            'admin/get_return_items_for_edit.php' => 'purchase_receiving.view',
            'admin/get_receiving_details.php' => 'purchase_receiving.view',
            'admin/purchase_payments.php' => 'purchase_payments.view',
            'admin/purchase_reports.php' => 'purchase_reports.view',
            'admin/vendor_product_detail.php' => 'purchase_reports.view',
            'admin/export_vendor_product_excel.php' => 'purchase_reports.view',
            'admin/get_order_codes.php' => 'purchase_reports.view',
            'admin/finance_dashboard.php' => 'finance_dashboard.view',
            'admin/add_topup.php' => 'finance_dashboard.view',
            'admin/add_spending.php' => 'spending.view',
            'admin/edit_spending.php' => 'spending.view',
            'admin/delete_spending.php' => 'spending.view',
            'admin/update_spending.php' => 'spending.view',
            'admin/finance_reports.php' => 'finance_reports.view',
            'admin/finance_spending_detail.php' => 'finance_reports.view',
            'admin/finance_spending_detail_export.php' => 'finance_reports.view',
            'admin/product_costs.php' => 'product_costs.view',
            'admin/cost_history.php' => 'cost_history.view',
            'admin/profit_analysis.php' => 'profit_analysis.view',
            'admin/cost_reports.php' => 'cost_reports.view',
            'admin/return_report.php' => 'return_report.view',
            'admin/product_return_report.php' => 'product_return_report.view',
            'admin/accountant_dashboard.php' => 'accountant_dashboard.view',
            'admin/accountant_daily_reports.php' => 'accountant_daily.view',
            'admin/accountant_product_reports.php' => 'accountant_product.view',
            'admin/accountant_financial_summary.php' => 'financial_summary.view',
            'admin/accountant_closing_report.php' => 'closing_report.view',
            'admin/closing_report.php' => 'closing_report.view',
            'admin/delivery_report.php' => 'delivery_report.view',
            'admin/commission_summary.php' => 'commission_sales.view',
            'admin/payment_management.php' => 'payment_management.view',
            'admin/telegram_bot_remider.php' => 'telegram_bot_reminder.view',
            'admin/pages.php' => 'pages.view',
            'admin/delivery_types.php' => 'delivery_types.view',
            'admin/delivery_costs.php' => 'delivery_costs.view',
            'admin/logos.php' => 'logos.view',
            'admin/manage_note_options.php' => 'note_options.view',
            'admin/money_exchange.php' => 'money_exchange.view',
            'admin/users.php' => 'users.view',
            'admin/user_activity/user_activity_log.php' => 'users_activity.view',
            'admin/role_permissions.php' => 'role_permissions.view',
            'admin/roles.php' => 'roles.view',
            'admin/products.php' => 'inventory.view',
            'admin/purchase_order_creator_optimized.php' => 'purchase_orders.view',
            'admin/get_purchase_order_items.php' => 'purchase_orders.view',
            'admin/get_purchase_order_code.php' => 'purchase_orders.view',
            'admin/get_purchase_order_details.php' => 'purchase_orders.view',
            'admin/get_purchase_payment.php' => 'purchase_payments.view',
            'admin/generate_order_invoice.php' => 'purchase_orders.view',
            'admin/generate_payment_invoice.php' => 'purchase_payments.view',
            'admin/receipt_order_receipt.php' => 'receipts.view',
            'admin/get_return_details.php' => 'return_report.view',
            'admin/get_topup.php' => 'finance_dashboard.view',
            'admin/get_spending.php' => 'spending.view',
            'admin/process_topup.php' => 'finance_dashboard.view',
            'admin/update_topup.php' => 'finance_dashboard.view',
            'admin/delete_topup_safe.php' => 'finance_dashboard.view',
            'admin/topup_report.php' => 'finance_dashboard.view',
            'admin/topup_report_export.php' => 'finance_dashboard.view',
            'admin/payment_methods.php' => 'finance_dashboard.view',
            'admin/cashflow.php' => 'cashflow.view',
            'admin/cashflow_summary.php' => 'cashflow.view',
            'admin/cashflow_report.php' => 'cashflow.view',
            'admin/cashflow_categories.php' => 'cashflow_categories.view',
            'admin/cashflow_add_spending.php' => 'cashflow_spending.view',
            'admin/cashflow_spending_history.php' => 'cashflow_spending.view',
            'admin/cashflow_edit_spending.php' => 'cashflow_spending.update',
            'admin/cashflow_topup_add.php' => 'cashflow_topup.view',
            'admin/cashflow_topup_history.php' => 'cashflow_topup.view',
            'admin/bank_transfer_add.php' => 'bank_transfer.view',
            'admin/bank_transfer_history.php' => 'bank_transfer.view',
            'maintenance.php' => 'maintenance.view',

            // Cashier pages
            'cashier/print_orders.php' => 'print_orders.view',
            'cashier/print_history.php' => 'print_history.view',
            'cashier/dashboard.php' => 'cashier_date.view',
            'cashier/cancelled_orders.php' => 'cancelled_orders.view',
            'cashier/broadcast.php' => 'broadcast.view',
            'cashier/bulk_print.php' => 'print_orders.view',
            'cashier/inventory.php' => 'inventory.view',

            // Seller pages
            'seller/order_new.php' => 'seller_orders.view',
            'seller/statistics.php' => 'seller_statistics.view',
            'seller/orders.php' => 'order_history.view',

            // Scanner pages
            'scanner/view_all_items.php' => 'items_view.view',
            'scanner/home.php' => 'scanner_home.view',
            'scanner/out_items_config/out_items_delivery_by.php' => 'out_items_delivery_by.view',
        ];

        $path = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $path = ltrim($path, '/');

        $rbacEnabled = rbac_is_enabled($pdo);

        if ($rbacEnabled) {
            foreach ($permByScriptSuffix as $suffix => $permKeyOrKeys) {
                if (!str_ends_with($path, $suffix)) continue;
                $keys = is_array($permKeyOrKeys) ? $permKeyOrKeys : [$permKeyOrKeys];
                foreach ($keys as $pk) {
                    if (has_permission($pk)) {
                        $ok = true;
                        break 2;
                    }
                }
            }
        }
    }

    if (!$ok) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}

