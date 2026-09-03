<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = $API_ALLOWED_ORIGINS ?? [];
if (!is_array($allowedOrigins)) {
    $allowedOrigins = [];
}

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Accept, Cache-Control, Authorization, X-Report-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function api_report_token_secret(): string
{
    $secret = (string)($GLOBALS['REPORT_API_TOKEN_SECRET'] ?? '');
    if ($secret !== '') {
        return $secret;
    }
    return hash('sha256', __DIR__ . '|' . (string)($GLOBALS['DB_PASS'] ?? '') . '|ordershadow-report-api');
}

function api_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function api_base64url_decode(string $value): string
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? '' : $decoded;
}

function api_issue_report_token(array $user): string
{
    $payload = api_base64url_encode(json_encode([
        'uid' => (int)($user['id'] ?? 0),
        'iat' => time(),
        'exp' => time() + (7 * 24 * 60 * 60),
    ], JSON_UNESCAPED_SLASHES));
    $signature = hash_hmac('sha256', $payload, api_report_token_secret());
    return $payload . '.' . $signature;
}

function api_report_token_user_id(?string $token): int
{
    $token = trim((string)$token);
    if ($token === '' || strpos($token, '.') === false) {
        return 0;
    }
    [$payload, $signature] = explode('.', $token, 2);
    $expected = hash_hmac('sha256', $payload, api_report_token_secret());
    if (!hash_equals($expected, $signature)) {
        return 0;
    }
    $data = json_decode(api_base64url_decode($payload), true);
    if (!is_array($data) || (int)($data['exp'] ?? 0) < time()) {
        return 0;
    }
    return max(0, (int)($data['uid'] ?? 0));
}

function api_restore_user_from_report_token(): void
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
        $token = (string)($_SERVER['HTTP_X_REPORT_TOKEN'] ?? '');
    }
    $userId = api_report_token_user_id($token);
    if ($userId > 0) {
        $_SESSION['user_id'] = $userId;
    }
}

api_restore_user_from_report_token();
function api_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_user_payload(array $user): array
{
    $permissions = [];
    try {
        $permissions = user_permissions(get_db_connection(), $user);
    } catch (Throwable $e) {
        $permissions = [];
    }

    return [
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($user['username'] ?? ''),
        'name' => (string)($user['name'] ?? ''),
        'role' => (string)($user['role'] ?? ''),
        'permissions' => array_values($permissions),
        'can_manage_role_permissions' => (
            (($user['username'] ?? '') === 'admin')
            || (($user['role'] ?? '') === 'admin')
            || in_array('role_permissions.view', $permissions, true)
            || in_array('sr_role_permissions.view', $permissions, true)
            || (function_exists('has_permission') && (
                has_permission('role_permissions.view') || has_permission('sr_role_permissions.view')
            ))
        ),
    ];
}

function api_error(string $message, int $status = 400, array $extra = []): void
{
    api_json(array_merge([
        'success' => false,
        'message' => $message,
    ], $extra), $status);
}

function api_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

function api_identifier(string $value): ?string
{
    $value = trim($value);
    return preg_match('/^[A-Za-z0-9_]+$/', $value) ? $value : null;
}

function api_current_database(PDO $pdo): string
{
    return (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
}

function api_table_columns(PDO $pdo, string $table): array
{
    $database = api_current_database($pdo);
    $stmt = $pdo->prepare("
        SELECT
            COLUMN_NAME AS name,
            DATA_TYPE AS data_type,
            COLUMN_TYPE AS column_type,
            IS_NULLABLE AS is_nullable,
            COLUMN_KEY AS column_key,
            COLUMN_DEFAULT AS column_default,
            EXTRA AS extra
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION
    ");
    $stmt->execute([$database, $table]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function api_table_exists(PDO $pdo, string $table): bool
{
    return api_table_columns($pdo, $table) !== [];
}

function api_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function api_database_tables(PDO $pdo): array
{
    $database = api_current_database($pdo);
    $tablesStmt = $pdo->prepare("
        SELECT
            t.TABLE_NAME AS name,
            t.TABLE_ROWS AS estimated_rows,
            t.CREATE_TIME AS created_at,
            t.UPDATE_TIME AS updated_at
        FROM INFORMATION_SCHEMA.TABLES t
        WHERE t.TABLE_SCHEMA = ? AND t.TABLE_TYPE = 'BASE TABLE'
        ORDER BY t.TABLE_NAME
    ");
    $tablesStmt->execute([$database]);
    $tables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

    $columnsStmt = $pdo->prepare("
        SELECT
            c.TABLE_NAME AS table_name,
            c.COLUMN_NAME AS name,
            c.DATA_TYPE AS data_type,
            c.COLUMN_TYPE AS column_type,
            c.IS_NULLABLE AS is_nullable,
            c.COLUMN_KEY AS column_key,
            c.COLUMN_DEFAULT AS column_default,
            c.EXTRA AS extra
        FROM INFORMATION_SCHEMA.COLUMNS c
        WHERE c.TABLE_SCHEMA = ?
        ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION
    ");
    $columnsStmt->execute([$database]);

    $columnsByTable = [];
    foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columnsByTable[(string)$column['table_name']][] = [
            'name' => $column['name'],
            'data_type' => $column['data_type'],
            'column_type' => $column['column_type'],
            'is_nullable' => $column['is_nullable'],
            'column_key' => $column['column_key'],
            'column_default' => $column['column_default'],
            'extra' => $column['extra'],
        ];
    }

    foreach ($tables as &$table) {
        $table['estimated_rows'] = $table['estimated_rows'] === null ? null : (int)$table['estimated_rows'];
        $table['columns'] = $columnsByTable[(string)$table['name']] ?? [];
    }
    unset($table);

    return $tables;
}

function api_table_payload(PDO $pdo, string $table, bool $allowSearch = true): array
{
    if (!api_table_exists($pdo, $table)) {
        api_error('Invalid table.', 422);
    }

    $columns = api_table_columns($pdo, $table);
    $columnNames = array_map(static fn(array $column): string => (string)$column['name'], $columns);
    $columnSet = array_fill_keys($columnNames, true);
    $requestedColumns = array_filter(array_map('trim', explode(',', (string)($_GET['columns'] ?? ''))));
    $selectedColumns = [];

    foreach ($requestedColumns as $column) {
        $column = api_identifier($column);
        if ($column !== null && isset($columnSet[$column])) {
            $selectedColumns[] = $column;
        }
    }
    $selectedColumns = array_values(array_unique($selectedColumns));

    $limit = api_int('limit', 100, 1, 500);
    $offset = api_int('offset', 0, 0, 1000000);

    $sort = api_identifier((string)($_GET['sort'] ?? ''));
    $sort = $sort !== null && isset($columnSet[$sort]) ? $sort : null;
    $direction = strtolower((string)($_GET['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $q = $allowSearch ? trim((string)($_GET['q'] ?? '')) : '';
    $where = '';
    $params = [];

    if ($q !== '') {
        $searchColumns = array_values(array_filter($columns, static function (array $column): bool {
            return in_array((string)$column['data_type'], [
                'char',
                'varchar',
                'text',
                'mediumtext',
                'longtext',
                'enum',
                'set',
            ], true);
        }));

        if ($searchColumns) {
            $parts = [];
            foreach ($searchColumns as $column) {
                $parts[] = api_quote_identifier((string)$column['name']) . ' LIKE ?';
                $params[] = '%' . $q . '%';
            }
            $where = ' WHERE ' . implode(' OR ', $parts);
        }
    }

    $orderSql = '';
    if ($sort !== null) {
        $orderSql = ' ORDER BY ' . api_quote_identifier($sort) . " {$direction}";
    } else {
        foreach ($columns as $column) {
            if ((string)$column['column_key'] === 'PRI') {
                $orderSql = ' ORDER BY ' . api_quote_identifier((string)$column['name']) . ' DESC';
                break;
            }
        }
    }

    $tableSql = api_quote_identifier($table);
    $selectSql = '*';
    if ($selectedColumns) {
        $selectSql = implode(', ', array_map('api_quote_identifier', $selectedColumns));
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$tableSql}{$where}");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT {$selectSql} FROM {$tableSql}{$where}{$orderSql} LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);

    return [
        'name' => $table,
        'columns' => $columns,
        'selected_columns' => $selectedColumns ?: $columnNames,
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'total_rows' => $totalRows,
            'has_more' => ($offset + $limit) < $totalRows,
        ],
        'sort' => [
            'column' => $sort,
            'direction' => strtolower($direction),
        ],
        'filters' => [
            'q' => $q,
        ],
        'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}
