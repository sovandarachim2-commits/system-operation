<?php

require_once __DIR__ . '/db.php';

/**
 * Ensure append-only user activity table exists.
 */
function user_activity_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_activity_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            user_name VARCHAR(255) NULL,
            action VARCHAR(100) NOT NULL,
            details VARCHAR(500) NULL,
            context JSON NULL,
            ip_address VARCHAR(45) NULL,
            device VARCHAR(128) NULL,
            device_name VARCHAR(128) NULL,
            device_model VARCHAR(128) NULL,
            user_agent VARCHAR(512) NULL,
            request_uri VARCHAR(512) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_created (created_at),
            INDEX idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM user_activity_log LIKE 'device'");
        if ($chk && !$chk->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE user_activity_log ADD COLUMN device VARCHAR(128) NULL AFTER ip_address');
        }
    } catch (Throwable $e) {
        error_log('user_activity_ensure_table device: ' . $e->getMessage());
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM user_activity_log WHERE Field = 'device'")->fetch(PDO::FETCH_ASSOC);
        if ($col && isset($col['Type']) && preg_match('/varchar\((\d+)\)/i', (string)$col['Type'], $vm) && (int)$vm[1] < 128) {
            $pdo->exec('ALTER TABLE user_activity_log MODIFY COLUMN device VARCHAR(128) NULL');
        }
    } catch (Throwable $e) {
        error_log('user_activity_ensure_table device width: ' . $e->getMessage());
    }
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM user_activity_log LIKE 'device_name'");
        if ($chk && !$chk->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE user_activity_log ADD COLUMN device_name VARCHAR(128) NULL AFTER device');
        }
    } catch (Throwable $e) {
        error_log('user_activity_ensure_table device_name: ' . $e->getMessage());
    }
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM user_activity_log LIKE 'device_model'");
        if ($chk && !$chk->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE user_activity_log ADD COLUMN device_model VARCHAR(128) NULL AFTER device_name');
        }
    } catch (Throwable $e) {
        error_log('user_activity_ensure_table device_model: ' . $e->getMessage());
    }
}

/** Valid GET values for log type filter (dropdown). */
function user_activity_log_type_keys(): array
{
    return ['create', 'edit', 'delete', 'login', 'lockout', 'view'];
}

/** Value => label for filter dropdown. */
function user_activity_log_type_options(): array
{
    return [
        '' => 'All log types',
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'login' => 'Login',
        'lockout' => 'Lockout',
        'view' => 'View (open page)',
    ];
}

/**
 * Category key from stored action name (order matters).
 */
function user_activity_log_type_from_action(string $action): string
{
    $a = strtolower($action);
    if (strpos($a, 'lockout') !== false) {
        return 'lockout';
    }
    if (in_array($action, ['login_success', 'login_failed', 'logout'], true) || strpos($a, 'login') !== false) {
        return 'login';
    }
    if ($action === 'page_view') {
        return 'view';
    }
    if (strpos($a, 'delete') !== false) {
        return 'delete';
    }
    // Names like user_password_reset / user_active_set do not contain "update" or "edit"
    if (in_array($action, ['user_password_reset', 'user_active_set'], true)) {
        return 'edit';
    }
    if (strpos($a, 'update') !== false || strpos($a, 'edit') !== false) {
        return 'edit';
    }
    if (strpos($a, 'create') !== false) {
        return 'create';
    }
    return 'other';
}

/** Short label for table column. */
function user_activity_log_type_label(string $typeKey): string
{
    switch ($typeKey) {
        case 'create':
            return 'Create';
        case 'edit':
            return 'Edit';
        case 'delete':
            return 'Delete';
        case 'login':
            return 'Login';
        case 'lockout':
            return 'Lockout';
        case 'view':
            return 'View';
        default:
            return 'Other';
    }
}

/**
 * SQL condition + params for log_type filter (matches classification rules).
 * @return array{0: string, 1: array<int, mixed>}
 */
function user_activity_log_type_sql(string $logType): array
{
    $logType = strtolower(trim($logType));
    if ($logType === '' || !in_array($logType, user_activity_log_type_keys(), true)) {
        return ['', []];
    }
    if ($logType === 'lockout') {
        return ["action LIKE '%lockout%'", []];
    }
    if ($logType === 'login') {
        return ["(action IN ('login_success','login_failed','logout') OR (action LIKE 'login%' AND action NOT LIKE '%lockout%'))", []];
    }
    if ($logType === 'view') {
        return ['action = ?', ['page_view']];
    }
    if ($logType === 'delete') {
        return ["action LIKE '%delete%'", []];
    }
    if ($logType === 'edit') {
        return ["(action LIKE '%update%' OR action LIKE '%edit%' OR action IN ('user_password_reset','user_active_set'))", []];
    }
    if ($logType === 'create') {
        return ["action LIKE '%create%'", []];
    }
    return ['', []];
}

/**
 * Map Apple hardware id (e.g. "13,3" → iPhone 12 Pro) to marketing name. Safari often omits iPhoneX,Y; WebViews/embedded WK may include it.
 *
 * @see https://theiphonewiki.com/wiki/Models — identifiers like iPhone13,3 stored here as "13,3"
 */
function user_activity_iphone_marketing_name(string $hwId): ?string
{
    static $map = [
        // iPhone 16
        '17,1' => 'iPhone 16 Pro', '17,2' => 'iPhone 16 Pro Max', '17,3' => 'iPhone 16', '17,4' => 'iPhone 16 Plus',
        // iPhone 15
        '16,1' => 'iPhone 15 Pro', '16,2' => 'iPhone 15 Pro Max', '16,3' => 'iPhone 15', '16,4' => 'iPhone 15 Plus',
        '15,4' => 'iPhone 15', '15,5' => 'iPhone 15 Plus',
        // iPhone 14
        '14,7' => 'iPhone 14', '14,8' => 'iPhone 14 Plus', '15,2' => 'iPhone 14 Pro', '15,3' => 'iPhone 14 Pro Max',
        // iPhone 13 / SE 3
        '14,2' => 'iPhone 13 Pro', '14,3' => 'iPhone 13 Pro Max', '14,4' => 'iPhone 13 mini', '14,5' => 'iPhone 13',
        '14,6' => 'iPhone SE (3rd generation)',
        // iPhone 12
        '13,1' => 'iPhone 12 mini', '13,2' => 'iPhone 12', '13,3' => 'iPhone 12 Pro', '13,4' => 'iPhone 12 Pro Max',
        // iPhone 11 / SE 2
        '12,1' => 'iPhone 11', '12,3' => 'iPhone 11 Pro', '12,5' => 'iPhone 11 Pro Max',
        '12,8' => 'iPhone SE (2nd generation)',
        // iPhone XS / XR
        '11,2' => 'iPhone XS', '11,4' => 'iPhone XS Max', '11,6' => 'iPhone XS Max', '11,8' => 'iPhone XR',
        // iPhone X
        '10,3' => 'iPhone X', '10,6' => 'iPhone X',
        // iPhone 8
        '10,1' => 'iPhone 8', '10,2' => 'iPhone 8 Plus', '10,4' => 'iPhone 8', '10,5' => 'iPhone 8 Plus',
        // iPhone 7
        '9,1' => 'iPhone 7', '9,2' => 'iPhone 7 Plus', '9,3' => 'iPhone 7', '9,4' => 'iPhone 7 Plus',
        // iPhone 6s / SE (1st)
        '8,1' => 'iPhone 6s', '8,2' => 'iPhone 6s Plus', '8,4' => 'iPhone SE (1st generation)',
    ];
    return $map[$hwId] ?? null;
}

/**
 * Parse UA (+ optional Client Hints) into device/model name and OS line (no " · " join).
 *
 * @return array{name: string|null, os: string|null}
 */
function user_activity_device_parse(?string $ua): array
{
    $chModel = isset($_SERVER['HTTP_SEC_CH_UA_MODEL']) ? trim((string)$_SERVER['HTTP_SEC_CH_UA_MODEL'], " \t\"") : '';
    if ($chModel === '' || strcasecmp($chModel, '?0') === 0) {
        $chModel = '';
    }

    if ($ua === null || $ua === '') {
        if ($chModel !== '') {
            return ['name' => substr($chModel, 0, 128), 'os' => null];
        }
        return ['name' => null, 'os' => null];
    }

    if (stripos($ua, 'iPhone') !== false && stripos($ua, 'iPad') === false) {
        $ios = null;
        if (preg_match('/CPU iPhone OS ([\d_]+)/i', $ua, $m)) {
            $ios = str_replace('_', '.', $m[1]);
        }
        $name = 'iPhone';
        if (preg_match('/iPhone(\d+,\d+)/i', $ua, $mh)) {
            $hw = $mh[1];
            $marketing = user_activity_iphone_marketing_name($hw);
            $name = $marketing ?? ('iPhone (' . $hw . ')');
        } elseif ($chModel !== '' && strcasecmp($chModel, 'iPhone') !== 0) {
            $name = substr($chModel, 0, 80);
        }
        $os = $ios !== null ? 'iOS ' . $ios : null;
        return ['name' => substr($name, 0, 128), 'os' => $os];
    }

    if (stripos($ua, 'iPad') !== false) {
        $ver = null;
        if (preg_match('/CPU(?: iPhone)? OS ([\d_]+)/i', $ua, $m)) {
            $ver = str_replace('_', '.', $m[1]);
        }
        $name = '';
        if ($chModel !== '' && stripos($chModel, 'iPad') === false) {
            $name = substr($chModel, 0, 80) . ' · ';
        }
        $name .= 'iPad';
        $os = $ver !== null ? 'iPadOS ' . $ver : null;
        return ['name' => substr($name, 0, 128), 'os' => $os];
    }

    if (stripos($ua, 'Android') !== false) {
        $ver = null;
        if (preg_match('/Android\s+([\d.]+)/i', $ua, $av)) {
            $ver = $av[1];
        }
        $model = null;
        if (preg_match('/Android\s+[\d.]+;\s*([^;)]+?)(?:\s+Build|\))/i', $ua, $mm)) {
            $model = trim(preg_replace('/\s+/', ' ', $mm[1]));
            if (stripos($model, 'Linux') === 0) {
                $model = null;
            }
        }
        if ($model === null || $model === '') {
            $model = $chModel !== '' ? $chModel : 'Android device';
        }
        $os = !empty($ver) ? 'Android ' . $ver : null;
        return ['name' => substr($model, 0, 128), 'os' => $os];
    }

    if (preg_match('/Windows NT ([\d.]+)/i', $ua, $w)) {
        return ['name' => 'Windows PC', 'os' => 'Windows ' . $w[1]];
    }
    if (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
        return ['name' => 'Mac', 'os' => 'macOS'];
    }
    if (stripos($ua, 'CrOS') !== false) {
        return ['name' => 'Chromebook', 'os' => 'Chrome OS'];
    }
    if (stripos($ua, 'Linux') !== false && stripos($ua, 'Android') === false) {
        return ['name' => 'Linux PC', 'os' => 'Linux'];
    }

    if ($chModel !== '') {
        return ['name' => substr($chModel, 0, 128), 'os' => null];
    }
    return ['name' => 'Computer', 'os' => null];
}

function user_activity_device_label_from_parse(array $parsed): ?string
{
    $name = isset($parsed['name']) && $parsed['name'] !== '' ? (string)$parsed['name'] : null;
    $os = isset($parsed['os']) && $parsed['os'] !== '' ? (string)$parsed['os'] : null;
    if ($name === null && $os === null) {
        return null;
    }
    if ($name === null) {
        return substr($os, 0, 128);
    }
    if ($os === null) {
        return substr($name, 0, 128);
    }
    return substr($name . ' · ' . $os, 0, 128);
}

/**
 * Human-readable device line: model + OS (stored in user_activity_log.device).
 */
function user_activity_device_label(?string $ua): ?string
{
    return user_activity_device_label_from_parse(user_activity_device_parse($ua));
}

/**
 * Friendly model name only, without OS version — e.g. iPhone 16 Pro, iPad (stored in user_activity_log.device_name).
 */
function user_activity_device_name(?string $ua): ?string
{
    $p = user_activity_device_parse($ua);
    $n = $p['name'] ?? null;
    return ($n !== null && $n !== '') ? substr($n, 0, 128) : null;
}

/**
 * Model code from User-Agent only (no Client Hints): hardware id or device token, not the marketing name.
 * iPhone Safari usually has no hardware id — returns "iPhone" instead of null.
 */
function user_activity_device_model_from_user_agent(?string $ua): ?string
{
    if ($ua === null || trim((string)$ua) === '') {
        return null;
    }
    $ua = (string)$ua;

    if (stripos($ua, 'iPhone') !== false && stripos($ua, 'iPad') === false) {
        if (preg_match('/iPhone(\d+,\d+)/i', $ua, $m)) {
            return 'iPhone' . $m[1];
        }
        return 'iPhone';
    }

    if (stripos($ua, 'iPad') !== false) {
        if (preg_match('/iPad(\d+,\d+)/i', $ua, $m)) {
            return 'iPad' . $m[1];
        }
        return 'iPad';
    }

    if (stripos($ua, 'Android') !== false) {
        if (preg_match('/Android\s+[\d.]+;\s*([^;)]+?)(?:\s+Build|\))/i', $ua, $mm)) {
            $model = trim(preg_replace('/\s+/', ' ', $mm[1]));
            if (stripos($model, 'Linux') === 0) {
                $model = '';
            }
            if ($model !== '') {
                return substr($model, 0, 128);
            }
        }
        return 'Android device';
    }

    if (preg_match('/Windows NT ([\d.]+)/i', $ua, $w)) {
        return 'WinNT ' . $w[1];
    }
    if (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
        if (preg_match('/\bARM Mac OS X\b/i', $ua)) {
            return 'Mac (Apple silicon)';
        }
        if (preg_match('/\bIntel Mac OS X\b/i', $ua)) {
            return 'Mac (Intel)';
        }
        return 'Mac';
    }
    if (stripos($ua, 'CrOS') !== false) {
        return 'Chrome OS';
    }
    if (stripos($ua, 'Linux') !== false && stripos($ua, 'Android') === false) {
        return 'Linux PC';
    }

    return null;
}

/**
 * Model code from UA + optional Client Hints (stored in user_activity_log.device_model); not the same as device_name.
 */
function user_activity_device_model(?string $ua): ?string
{
    $chModel = isset($_SERVER['HTTP_SEC_CH_UA_MODEL']) ? trim((string)$_SERVER['HTTP_SEC_CH_UA_MODEL'], " \t\"") : '';
    if ($chModel === '' || strcasecmp($chModel, '?0') === 0) {
        $chModel = '';
    }

    if ($ua === null || trim((string)$ua) === '') {
        return $chModel !== '' ? substr($chModel, 0, 128) : null;
    }

    $fromUa = user_activity_device_model_from_user_agent($ua);
    if ($fromUa !== null && $fromUa !== '') {
        if (strcasecmp($fromUa, 'iPhone') === 0 && $chModel !== '' && strcasecmp($chModel, 'iPhone') !== 0) {
            return substr($chModel, 0, 128);
        }
        return $fromUa;
    }

    return $chModel !== '' ? substr($chModel, 0, 128) : null;
}

/**
 * Prefer stored device_model; if empty (legacy row), derive from saved user_agent.
 */
function user_activity_display_device_model(?string $device_model, ?string $user_agent): string
{
    $s = trim((string)$device_model);
    if ($s !== '' && strcasecmp($s, 'null') !== 0) {
        return $s;
    }
    $d = user_activity_device_model_from_user_agent($user_agent);
    return ($d !== null && $d !== '') ? $d : '';
}

/**
 * Prefer stored device_name; for older rows derive from combined device string.
 */
function user_activity_display_device_name(?string $device_name, ?string $device): string
{
    $dn = trim((string)$device_name);
    if ($dn !== '') {
        return $dn;
    }
    $d = trim((string)$device);
    if ($d === '') {
        return '';
    }
    foreach ([' · iOS ', ' · iPadOS ', ' · Android '] as $sep) {
        $p = strpos($d, $sep);
        if ($p !== false) {
            return trim(substr($d, 0, $p));
        }
    }
    if (preg_match('/^(.+?)\s*·\s*Windows\s+[\d.]+/i', $d, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/^(.+?)\s*·\s*macOS$/i', $d, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/^(.+?)\s*·\s*Chrome OS$/i', $d, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/^(.+?)\s*·\s*Linux$/i', $d, $m)) {
        return trim($m[1]);
    }
    return $d;
}

/**
 * Client IP for logging: prefer dotted IPv4; IPv4-mapped IPv6 (::ffff:x.x.x.x) → x.x.x.x;
 * otherwise store full IPv6 (max 45 chars). If REMOTE_ADDR is empty, uses first hop of X-Forwarded-For
 * (only when you trust your proxy to set it).
 */
function user_activity_client_ip(): ?string
{
    $raw = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($raw === '') {
        $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff !== '') {
            $first = trim(explode(',', $xff, 2)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $raw = $first;
            }
        }
    }
    if ($raw === '') {
        return null;
    }
    if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $raw;
    }
    if (!filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return null;
    }
    $bin = @inet_pton($raw);
    if ($bin !== false && strlen($bin) === 16) {
        $mappedPrefix = str_repeat("\x00", 10) . "\xff\xff";
        if (substr($bin, 0, 12) === $mappedPrefix) {
            $v4 = @inet_ntop(substr($bin, 12, 4));
            if ($v4 !== false && filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $v4;
            }
        }
    }
    return substr($raw, 0, 45);
}

/**
 * Build a single-line details string for user_activity_log.details (truncated to DB limit).
 *
 * @param array<string, scalar|null> $parts Keys become labels; empty/null values skipped.
 */
function user_activity_details_compact(array $parts, int $maxTotal = 500, int $maxValueLen = 120): string
{
    $chunks = [];
    foreach ($parts as $k => $v) {
        if ($v === null) {
            continue;
        }
        $label = preg_replace('/[^a-z0-9_]/i', '_', trim((string)$k));
        $label = trim($label, '_');
        if ($label === '') {
            continue;
        }
        $s = str_replace(["\r", "\n", "\t"], ' ', trim((string)$v));
        if ($s === '') {
            continue;
        }
        if (strlen($s) > $maxValueLen) {
            $s = substr($s, 0, $maxValueLen) . '…';
        }
        $chunks[] = $label . '=' . $s;
    }
    $out = implode(' · ', $chunks);
    if (strlen($out) > $maxTotal) {
        $out = substr($out, 0, max(0, $maxTotal - 1)) . '…';
    }
    return $out;
}

/**
 * One-line summary of order line items for user_activity_log.details (name, qty, unit, line amount).
 *
 * @param list<array{name?:string, product_id?:int, quantity?:float|int|string, unit_cost?:float|int|string, line_total?:float|int|string}> $orderItems
 */
function user_activity_format_order_items_for_log(array $orderItems, int $maxLen = 500): string
{
    $fmtN = static function ($n): string {
        $x = round((float)$n, 4);
        $s = (string)$x;
        return rtrim(rtrim($s, '0'), '.') ?: '0';
    };
    $segments = [];
    foreach ($orderItems as $it) {
        if (!is_array($it)) {
            continue;
        }
        $name = trim((string)($it['name'] ?? ''));
        if ($name === '') {
            $name = 'product#' . (int)($it['product_id'] ?? 0);
        }
        $name = str_replace(["\r", "\n", "\t", ';', '·'], ' ', $name);
        if (strlen($name) > 48) {
            $name = substr($name, 0, 45) . '…';
        }
        $qty = (float)($it['quantity'] ?? 0);
        $unit = isset($it['unit_cost']) ? (float)$it['unit_cost'] : null;
        $line = isset($it['line_total']) ? (float)$it['line_total'] : ($unit !== null ? $unit * $qty : 0.0);
        if ($unit !== null) {
            $segments[] = $name . ' x' . $fmtN($qty) . '@' . $fmtN($unit) . '=' . $fmtN($line);
        } else {
            $segments[] = $name . ' x' . $fmtN($qty) . '=' . $fmtN($line);
        }
    }
    $out = implode('; ', $segments);
    if (strlen($out) > $maxLen) {
        $out = substr($out, 0, max(0, $maxLen - 1)) . '…';
    }
    return $out;
}

/**
 * Header fields + product lines for seller order create/update logs (fits VARCHAR(500)).
 *
 * @param array<string, scalar|null> $headerParts e.g. order_id, code, customer, …
 * @param list<array<string, mixed>> $orderItems rows with name, quantity, unit_cost, line_total
 */
function user_activity_seller_order_log_details(array $headerParts, array $orderItems, int $maxTotal = 500): string
{
    $sep = ' · items: ';
    $headBudget = min(260, $maxTotal - 100);
    $head = user_activity_details_compact($headerParts, max(80, $headBudget), 120);
    $room = $maxTotal - strlen($head) - strlen($sep);
    if ($room < 50) {
        $head = user_activity_details_compact($headerParts, (int)($maxTotal * 0.38), 80);
        $room = $maxTotal - strlen($head) - strlen($sep);
    }
    $items = user_activity_format_order_items_for_log($orderItems, max(40, $room));
    if ($items === '') {
        return substr(user_activity_details_compact($headerParts, $maxTotal, 120), 0, $maxTotal);
    }
    $out = $head . $sep . $items;
    if (strlen($out) > $maxTotal) {
        $room2 = $maxTotal - strlen($head) - strlen($sep);
        $items = user_activity_format_order_items_for_log($orderItems, max(40, $room2));
        $out = $head . $sep . $items;
    }
    if (strlen($out) > $maxTotal) {
        $out = substr($out, 0, max(0, $maxTotal - 1)) . '…';
    }
    return $out;
}

/**
 * Record a user activity row. Safe to call from anywhere; failures go to error_log only.
 *
 * @param array|null $user Row from users table or current_user(), or null (e.g. failed login)
 * @param string $action Short code, e.g. login_success, logout, order_updated
 * @param string|null $details Human-readable line (max 500 chars); use user_activity_details_compact() for field lists
 * @param array|null $context Optional extra fields (stored as JSON)
 */
function user_activity_log(PDO $pdo, ?array $user, string $action, ?string $details = null, ?array $context = null): void
{
    try {
        user_activity_ensure_table($pdo);
        $uid = $user ? (int)($user['id'] ?? 0) : 0;
        $uname = '';
        if ($user) {
            $uname = trim((string)($user['name'] ?? ''));
            if ($uname === '') {
                $uname = trim((string)($user['username'] ?? ''));
            }
        }
        $ctxJson = $context !== null ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $ip = user_activity_client_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
        $device = user_activity_device_label($ua);
        $deviceName = user_activity_device_name($ua);
        $deviceModel = user_activity_device_model($ua);
        $uri = isset($_SERVER['REQUEST_URI']) ? substr((string)$_SERVER['REQUEST_URI'], 0, 512) : null;
        $det = $details !== null && $details !== '' ? substr($details, 0, 500) : null;

        $stmt = $pdo->prepare("
            INSERT INTO user_activity_log (user_id, user_name, action, details, context, ip_address, device, device_name, device_model, user_agent, request_uri)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $uid > 0 ? $uid : null,
            $uname !== '' ? $uname : null,
            $action,
            $det,
            $ctxJson,
            $ip,
            $device,
            $deviceName,
            $deviceModel,
            $ua,
            $uri,
        ]);
    } catch (Throwable $e) {
        error_log('user_activity_log: ' . $e->getMessage());
    }
}

/**
 * Log role-area mutations (scanner / seller / cashier) for User activity Create|Edit|Delete columns.
 *
 * @param 'scanner'|'seller'|'cashier'     $module
 * @param 'create'|'update'|'delete'       $kind
 */
function user_activity_log_module_mutation(?array $user, string $module, string $kind, string $scriptFile, ?string $details = null, ?array $context = null): void
{
    $module = strtolower(preg_replace('/[^a-z]/', '', $module));
    if (!in_array($module, ['scanner', 'seller', 'cashier'], true)) {
        return;
    }
    $kind = strtolower($kind);
    if (!in_array($kind, ['create', 'update', 'delete'], true)) {
        return;
    }
    $base = preg_replace('/\.php$/i', '', basename($scriptFile));
    if ($base === '') {
        return;
    }
    $action = $module . '_' . $kind . '_' . $base;
    try {
        $pdo = get_db_connection();
        user_activity_log(
            $pdo,
            $user,
            $action,
            $details !== null && $details !== '' ? substr($details, 0, 500) : null,
            $context
        );
    } catch (Throwable $e) {
        // do not break calling scripts
    }
}

/**
 * @param 'create'|'update'|'delete' $kind
 */
function user_activity_log_scanner_mutation(?array $user, string $kind, string $scriptFile, ?string $details = null, ?array $context = null): void
{
    user_activity_log_module_mutation($user, 'scanner', $kind, $scriptFile, $details, $context);
}
