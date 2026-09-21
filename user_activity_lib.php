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
            frontend_url VARCHAR(512) NULL,
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
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM user_activity_log LIKE 'frontend_url'");
        if ($chk && !$chk->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE user_activity_log ADD COLUMN frontend_url VARCHAR(512) NULL AFTER request_uri');
        }
    } catch (Throwable $e) {
        error_log('user_activity_ensure_table frontend_url: ' . $e->getMessage());
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
 * Parse UA (+ optional Client Hints) into device type, OS, model name, and model code.
 *
 * @return array{device_type: string|null, os: string|null, model_name: string|null, model_code: string|null}
 */
function user_activity_device_parse(?string $ua): array
{
    $chModel = isset($_SERVER['HTTP_SEC_CH_UA_MODEL']) ? trim((string)$_SERVER['HTTP_SEC_CH_UA_MODEL'], " \t\"") : '';
    if ($chModel === '' || strcasecmp($chModel, '?0') === 0) {
        $chModel = '';
    }

    $result = ['device_type' => null, 'os' => null, 'model_name' => null, 'model_code' => null];

    if ($ua === null || $ua === '') {
        if ($chModel !== '') {
            $result['model_code'] = substr($chModel, 0, 128);
        }
        return $result;
    }

    // ── iPhone ────────────────────────────────────────────────────────
    if (stripos($ua, 'iPhone') !== false && stripos($ua, 'iPad') === false) {
        $result['device_type'] = 'iPhone';
        if (preg_match('/CPU iPhone OS ([\d_]+)/i', $ua, $m)) {
            $result['os'] = 'iOS ' . str_replace('_', '.', $m[1]);
        }
        if (preg_match('/iPhone(\d+,\d+)/i', $ua, $mh)) {
            $hw = $mh[1];
            $result['model_code'] = 'iPhone' . $hw;
            $result['model_name'] = user_activity_iphone_marketing_name($hw);
        } elseif ($chModel !== '' && strcasecmp($chModel, 'iPhone') !== 0) {
            $result['model_code'] = substr($chModel, 0, 128);
        }
        return $result;
    }

    // ── iPad ──────────────────────────────────────────────────────────
    if (stripos($ua, 'iPad') !== false) {
        $result['device_type'] = 'iPad';
        if (preg_match('/CPU(?: iPhone)? OS ([\d_]+)/i', $ua, $m)) {
            $result['os'] = 'iPadOS ' . str_replace('_', '.', $m[1]);
        }
        if (preg_match('/iPad(\d+,\d+)/i', $ua, $mh)) {
            $hw = $mh[1];
            $result['model_code'] = 'iPad' . $hw;
        } elseif ($chModel !== '' && stripos($chModel, 'iPad') === false) {
            $result['model_code'] = substr($chModel, 0, 128);
        }
        return $result;
    }

    // ── Android ───────────────────────────────────────────────────────
    if (stripos($ua, 'Android') !== false) {
        $result['device_type'] = 'Android Phone';
        if (preg_match('/Android\s+([\d.]+)/i', $ua, $av)) {
            $result['os'] = 'Android ' . $av[1];
        }
        // Detect tablet
        if (preg_match('/\b(Tablet|Pad|Nexus\s*(?:7|9|10)|SM-T\d{3,}|Lenovo\s*Tab|Pixel\s*C)\b/i', $ua)) {
            $result['device_type'] = 'Tablet';
        }
        // Extract model code from Build string
        $modelCode = null;
        if (preg_match('/Android\s+[\d.]+;\s*([^;)]+?)(?:\s+Build|\))/i', $ua, $mm)) {
            $model = trim(preg_replace('/\s+/', ' ', $mm[1]));
            if (stripos($model, 'Linux') !== 0 && $model !== '') {
                $modelCode = $model;
            }
        }
        if ($modelCode === null && $chModel !== '') {
            $modelCode = $chModel;
        }
        if ($modelCode !== null) {
            $result['model_code'] = substr($modelCode, 0, 128);
            $result['model_name'] = user_activity_android_model_name($modelCode);
        }
        return $result;
    }

    // ── Windows ───────────────────────────────────────────────────────
    if (preg_match('/Windows NT ([\d.]+)/i', $ua, $w)) {
        $result['device_type'] = 'Windows PC';
        $result['os'] = 'Windows ' . $w[1];
        return $result;
    }

    // ── macOS ─────────────────────────────────────────────────────────
    if (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
        $result['device_type'] = 'Mac';
        $result['os'] = 'macOS';
        if (preg_match('/Mac OS X ([\d_]+)/i', $ua, $mm)) {
            $ver = str_replace('_', '.', $mm[1]);
            $result['os'] = 'macOS ' . $ver;
        }
        return $result;
    }

    // ── Chrome OS ─────────────────────────────────────────────────────
    if (stripos($ua, 'CrOS') !== false) {
        $result['device_type'] = 'Chromebook';
        $result['os'] = 'Chrome OS';
        return $result;
    }

    // ── Linux ─────────────────────────────────────────────────────────
    if (stripos($ua, 'Linux') !== false && stripos($ua, 'Android') === false) {
        $result['device_type'] = 'Linux PC';
        $result['os'] = 'Linux';
        return $result;
    }

    // ── Fallback ──────────────────────────────────────────────────────
    if ($chModel !== '') {
        $result['model_code'] = substr($chModel, 0, 128);
    }
    return $result;
}

/**
 * Map Android Build model identifier to a human-readable marketing name.
 * Returns null when the model cannot be reliably identified.
 */
function user_activity_android_model_name(string $modelCode): ?string
{
    static $map = [
        // Samsung Galaxy
        'SM-S928B' => 'Samsung Galaxy S24 Ultra', 'SM-S926B' => 'Samsung Galaxy S24+', 'SM-S921B' => 'Samsung Galaxy S24',
        'SM-S928U' => 'Samsung Galaxy S24 Ultra', 'SM-S926U' => 'Samsung Galaxy S24+', 'SM-S921U' => 'Samsung Galaxy S24',
        'SM-S918B' => 'Samsung Galaxy S23 Ultra', 'SM-S916B' => 'Samsung Galaxy S23+', 'SM-S911B' => 'Samsung Galaxy S23',
        'SM-S908B' => 'Samsung Galaxy S22 Ultra', 'SM-S906B' => 'Samsung Galaxy S22+', 'SM-S901B' => 'Samsung Galaxy S22',
        'SM-S918U' => 'Samsung Galaxy S23 Ultra', 'SM-S908U' => 'Samsung Galaxy S22 Ultra',
        'SM-A556B' => 'Samsung Galaxy A55', 'SM-A546B' => 'Samsung Galaxy A54', 'SM-A536B' => 'Samsung Galaxy A53',
        'SM-A356B' => 'Samsung Galaxy A35', 'SM-A346B' => 'Samsung Galaxy A34', 'SM-A336B' => 'Samsung Galaxy A33',
        'SM-A256B' => 'Samsung Galaxy A25', 'SM-A156B' => 'Samsung Galaxy A15', 'SM-A056F' => 'Samsung Galaxy A05',
        // Google Pixel
        'tokay' => 'Google Pixel 9 Pro', 'caiman' => 'Google Pixel 9', 'shiba' => 'Google Pixel 8 Pro', 'husky' => 'Google Pixel 8',
        'panther' => 'Google Pixel 7 Pro', 'cheetah' => 'Google Pixel 7', 'bluejay' => 'Google Pixel 6 Pro', 'raven' => 'Google Pixel 6 Pro',
        'oriole' => 'Google Pixel 6', 'bramble' => 'Google Pixel 5a', 'sunfish' => 'Google Pixel 4a',
        'Pixel 9 Pro Fold' => 'Google Pixel 9 Pro Fold', 'Pixel 9 Pro XL' => 'Google Pixel 9 Pro XL',
        'Pixel 9 Pro' => 'Google Pixel 9 Pro', 'Pixel 9' => 'Google Pixel 9',
        'Pixel 8 Pro' => 'Google Pixel 8 Pro', 'Pixel 8a' => 'Google Pixel 8a', 'Pixel 8' => 'Google Pixel 8',
        'Pixel 7 Pro' => 'Google Pixel 7 Pro', 'Pixel 7a' => 'Google Pixel 7a', 'Pixel 7' => 'Google Pixel 7',
        'Pixel 6 Pro' => 'Google Pixel 6 Pro', 'Pixel 6a' => 'Google Pixel 6a', 'Pixel 6' => 'Google Pixel 6',
        'Pixel 5' => 'Google Pixel 5', 'Pixel 4a (5G)' => 'Google Pixel 4a (5G)', 'Pixel 4a' => 'Google Pixel 4a', 'Pixel 4 XL' => 'Google Pixel 4 XL', 'Pixel 4' => 'Google Pixel 4',
        // OnePlus
        'CPH2583' => 'OnePlus 12', 'CPH2573' => 'OnePlus 12', 'PHZ110' => 'OnePlus 12',
        'CPH2451' => 'OnePlus 11', 'PHB110' => 'OnePlus 11',
        'CPH2415' => 'OnePlus Nord 3', 'CPH2399' => 'OnePlus Nord CE 3',
        // Xiaomi
        '23116PN5BC' => 'Xiaomi 14', '2210132C' => 'Xiaomi 12T Pro', '2107113SG' => 'Xiaomi 11T Pro',
        'M2012K11AC' => 'Xiaomi 11', 'M2011K2G' => 'Xiaomi 11T',
        'Redmi Note 13 Pro' => 'Xiaomi Redmi Note 13 Pro', 'Redmi Note 12 Pro' => 'Xiaomi Redmi Note 12 Pro',
        'Redmi Note 11 Pro' => 'Xiaomi Redmi Note 11 Pro',
        // Huawei
        'OCE-AN10' => 'Huawei P60 Pro', 'LIO-AN00' => 'Huawei Mate 40 Pro', 'NOH-AN00' => 'Huawei P50 Pro',
        // Oppo
        'CPH2513' => 'OPPO Find X7', 'CPH2451' => 'OPPO Find X6', 'CPH2373' => 'OPPO Reno 10 Pro',
        // Vivo
        'V2254A' => 'vivo X100 Pro', 'V2145A' => 'vivo X80 Pro',
        // Nothing
        'A065' => 'Nothing Phone (2)', 'A063' => 'Nothing Phone (1)',
        // Sony
        'XQ-DC72' => 'Sony Xperia 1 V', 'XQ-BQ42' => 'Sony Xperia 1 IV',
        // Motorola
        'moto g84' => 'Motorola Moto G84', 'moto g54' => 'Motorola Moto G54',
        // Nokia
        'Nokia G42' => 'Nokia G42', 'Nokia G22' => 'Nokia G22',
        // ASUS
        'ASUS_I005DA' => 'ASUS ROG Phone 7', 'ASUS_Z017DC' => 'ASUS ROG Phone 5',
    ];

    $normalized = preg_replace('/\s+/', ' ', trim($modelCode));
    if (isset($map[$normalized])) {
        return $map[$normalized];
    }
    if (isset($map[strtoupper($normalized)])) {
        return $map[strtoupper($normalized)];
    }
    return null;
}

function user_activity_device_label_from_parse(array $parsed): ?string
{
    $type = isset($parsed['device_type']) && $parsed['device_type'] !== '' ? (string)$parsed['device_type'] : null;
    $os = isset($parsed['os']) && $parsed['os'] !== '' ? (string)$parsed['os'] : null;
    if ($type === null && $os === null) {
        return null;
    }
    if ($type === null) {
        return substr($os, 0, 128);
    }
    if ($os === null) {
        return substr($type, 0, 128);
    }
    return substr($type . ' · ' . $os, 0, 128);
}

/**
 * Device type + OS (stored in user_activity_log.device).
 * e.g. "Android Phone · Android 15", "iPhone · iOS 18", "Windows PC · Windows 11"
 */
function user_activity_device_label(?string $ua): ?string
{
    return user_activity_device_label_from_parse(user_activity_device_parse($ua));
}

/**
 * Human-readable model name only (stored in user_activity_log.device_name).
 * e.g. "Google Pixel 9", "Samsung Galaxy S24 Ultra", "iPhone 16 Pro"
 * Returns null when model cannot be reliably determined.
 */
function user_activity_device_name(?string $ua): ?string
{
    $p = user_activity_device_parse($ua);
    $n = $p['model_name'] ?? null;
    return ($n !== null && $n !== '') ? substr($n, 0, 128) : null;
}

/**
 * Internal manufacturer model code (stored in user_activity_log.device_model).
 * e.g. "tokay", "SM-S928B", "iPhone17,2"
 * Returns null when no reliable model code is available.
 */
function user_activity_device_model_from_user_agent(?string $ua): ?string
{
    if ($ua === null || trim((string)$ua) === '') {
        return null;
    }
    $p = user_activity_device_parse($ua);
    return $p['model_code'] ?? null;
}

/**
 * Model code from UA + Client Hints (stored in user_activity_log.device_model).
 */
function user_activity_device_model(?string $ua): ?string
{
    return user_activity_device_model_from_user_agent($ua);
}

/**
 * Prefer stored device_model; if empty (legacy row), derive from saved user_agent.
 * Returns empty string if no reliable model code is available.
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
 * Returns empty string if no reliable model name is available.
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
    // Legacy: try to extract model name from "device · OS" format
    foreach ([' · iOS ', ' · iPadOS ', ' · Android '] as $sep) {
        $p = strpos($d, $sep);
        if ($p !== false) {
            return trim(substr($d, 0, $p));
        }
    }
    if (preg_match('/^(.+?)\s*·\s*Windows\s+[\d.]+/i', $d, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/^(.+?)\s*·\s*macOS/i', $d, $m)) {
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
    
    // Convert IPv6 loopback to IPv4
    if ($raw === '::1') {
        return '127.0.0.1';
    }

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
        $parsed = user_activity_device_parse($ua);
        $device = user_activity_device_label_from_parse($parsed);
        $deviceName = ($parsed['model_name'] ?? null) !== null ? substr((string)$parsed['model_name'], 0, 128) : null;
        $deviceModel = ($parsed['model_code'] ?? null) !== null ? substr((string)$parsed['model_code'], 0, 128) : null;
        $uri = isset($_SERVER['REQUEST_URI']) ? substr((string)$_SERVER['REQUEST_URI'], 0, 512) : null;
        $frontendUrl = isset($_SERVER['HTTP_X_FRONTEND_URL']) ? substr((string)$_SERVER['HTTP_X_FRONTEND_URL'], 0, 512) : null;
        
        // For login notifications, fallback to current URL if frontend_url is missing
        $logUrl = $frontendUrl;
        if ($logUrl === null || $logUrl === '') {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $logUrl = "{$protocol}://{$host}{$uri}";
        }
        
        $det = $details !== null && $details !== '' ? substr($details, 0, 500) : null;

        $stmt = $pdo->prepare("
            INSERT INTO user_activity_log (user_id, user_name, action, details, context, ip_address, device, device_name, device_model, user_agent, request_uri, frontend_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            $frontendUrl,
        ]);

        // Send Login Notification if configured
        if ($action === 'login_success' || $action === 'login_failed') {
            user_activity_send_login_notification($pdo, $user, $action, $ip, $device, $details, $context, $logUrl);
        }
    } catch (Throwable $e) {
        error_log('user_activity_log: ' . $e->getMessage());
    }
}

/**
 * Send Telegram notification for login events.
 */
function user_activity_send_login_notification(PDO $pdo, ?array $user, string $action, ?string $ip, ?string $device, ?string $details, ?array $context, ?string $url = null): void
{
    try {
        // Load settings for login_notification module
        $prefix = 'notification_login_telegram';
        
        $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
        
        $stmt->execute([$prefix . '_enabled']);
        if ($stmt->fetchColumn() !== '1') return;

        $isSuccess = ($action === 'login_success');
        $eventKey = $isSuccess ? 'notify_login_success' : 'notify_login_failed';
        
        $stmt->execute([$prefix . '_' . $eventKey]);
        if ($stmt->fetchColumn() === '0') return;

        $stmt->execute([$prefix . '_bot_token']);
        $botToken = trim((string)$stmt->fetchColumn());
        if ($botToken === '') {
            global $TELEGRAM_BOT_TOKEN;
            $botToken = trim((string)($TELEGRAM_BOT_TOKEN ?? ''));
        }
        
        $stmt->execute([$prefix . '_chat_id']);
        $chatId = trim((string)$stmt->fetchColumn());
        
        $stmt->execute([$prefix . '_thread_id']);
        $threadRaw = trim((string)$stmt->fetchColumn());
        $threadId = $threadRaw !== '' ? (int)$threadRaw : null;

        if ($botToken === '' || $chatId === '') return;

        // Build Message
        $statusIcon = $isSuccess ? '✅' : '❌';
        $statusText = $isSuccess ? 'LOGIN SUCCESS' : 'LOGIN FAILED';
        $datetime = date('Y-m-d H:i:s');
        
        $username = trim((string)($user['username'] ?? ($context['username'] ?? '')));
        if ($username === '' && !$isSuccess && $details) {
            // Try to extract username from details for failed logins
            if (preg_match('/username:\s*(\S+)/i', $details, $m)) {
                $username = $m[1];
            }
        }
        
        $name = trim((string)($user['name'] ?? ''));
        $userDisplay = $username;
        if ($name !== '' && $name !== $username) {
            $userDisplay .= " ({$name})";
        }

        $message = "{$statusIcon} *{$statusText}*\n";
        $message .= "👤 User: `{$userDisplay}`\n";
        $message .= "📅 Time: `{$datetime}`\n";
        $message .= "🌐 IP: `{$ip}`\n";
        $message .= "📱 Device: `{$device}`\n";
        
        if ($url !== null && $url !== '') {
            $message .= "🔗 URL: `{$url}`\n";
        }
        
        if (!$isSuccess) {
            $message .= "⚠️ Reason: `Invalid credentials`\n";
        }

        // Use helper if available, else manual curl
        if (function_exists('telegram_send_message_request')) {
            telegram_send_message_request($botToken, $chatId, $message, $threadId);
        } else {
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
            $postData = [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ];
            if ($threadId !== null) $postData['message_thread_id'] = $threadId;
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Throwable $e) {
        error_log('user_activity_send_login_notification: ' . $e->getMessage());
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
