<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../upload_paths.php';
require_role_or_permission(['admin'], 'reports_data.view');

try {
    $pdo = get_db_connection();

    $table = api_identifier((string)($_GET['table'] ?? ''));
    if ($table === null) {
        api_error('Invalid table.', 422);
    }
    $payload = api_table_payload($pdo, $table);

    // Inject full URLs for logos or tables with file paths
    if ($table === 'logos' || in_array('file_path', $payload['selected_columns'], true)) {
        foreach ($payload['rows'] as &$row) {
            if (isset($row['file_path']) && $row['file_path'] !== '') {
                $category = $table === 'logos' ? 'logos' : '';
                $row['url'] = uploaded_file_url($row['file_path'], $category);
            }
        }
        unset($row);
    }

    api_json([
        'success' => true,
        'table' => $payload['name'],
        'columns' => $payload['columns'],
        'selected_columns' => $payload['selected_columns'],
        'pagination' => $payload['pagination'],
        'sort' => $payload['sort'],
        'filters' => $payload['filters'],
        'rows' => $payload['rows'],
    ]);
} catch (Throwable $e) {
    error_log('table_data API error: ' . $e->getMessage());
    api_error('Unable to load table data.', 500);
}
