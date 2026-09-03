<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_role_or_permission(['admin'], 'reports_data.view');

try {
    $pdo = get_db_connection();

    api_json([
        'success' => true,
        'database' => api_current_database($pdo),
        'tables' => api_database_tables($pdo),
    ]);
} catch (Throwable $e) {
    error_log('schema API error: ' . $e->getMessage());
    api_error('Unable to load database schema.', 500);
}
