<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_role_or_permission(['admin'], 'reports_data.view');

try {
    $pdo = get_db_connection();
    $database = api_current_database($pdo);

    $table = api_identifier((string)($_GET['table'] ?? ''));
    if ($table !== null) {
        api_json([
            'success' => true,
            'database' => $database,
            'table' => api_table_payload($pdo, $table),
        ]);
    }

    $includeRows = (string)($_GET['include_rows'] ?? '') === '1';
    $tables = api_database_tables($pdo);

    if (!$includeRows) {
        api_json([
            'success' => true,
            'database' => $database,
            'mode' => 'schema',
            'tables' => $tables,
        ]);
    }

    $limitPerTable = api_int('limit_per_table', 25, 1, 100);
    $tablePayloads = [];
    foreach ($tables as $tableInfo) {
        $tableName = (string)$tableInfo['name'];
        $tableSql = api_quote_identifier($tableName);
        $countStmt = $pdo->query("SELECT COUNT(*) FROM {$tableSql}");
        $totalRows = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM {$tableSql} LIMIT {$limitPerTable}");
        $stmt->execute();

        $tablePayloads[] = [
            'name' => $tableName,
            'estimated_rows' => $tableInfo['estimated_rows'],
            'total_rows' => $totalRows,
            'columns' => $tableInfo['columns'],
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    api_json([
        'success' => true,
        'database' => $database,
        'mode' => 'schema_with_row_samples',
        'limit_per_table' => $limitPerTable,
        'tables' => $tablePayloads,
    ]);
} catch (Throwable $e) {
    error_log('database API error: ' . $e->getMessage());
    api_error('Unable to load database.', 500);
}
