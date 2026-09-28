<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../upload_paths.php';

function setup_table_data_ensure_money_exchange(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS money_exchange (
            id INT AUTO_INCREMENT PRIMARY KEY,
            base_currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            target_currency VARCHAR(10) NOT NULL DEFAULT 'KHR',
            rate DECIMAL(15,4) NOT NULL,
            effective_date DATE NOT NULL,
            description TEXT DEFAULT NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            created_by INT DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $count = (int)$pdo->query('SELECT COUNT(*) FROM money_exchange')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $rate = 4100.0;
    if (api_table_exists($pdo, 'settings')) {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'usd_to_khr_rate' LIMIT 1");
        $stmt->execute();
        $storedRate = $stmt->fetchColumn();
        if (is_numeric($storedRate) && (float)$storedRate > 0) {
            $rate = (float)$storedRate;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO money_exchange (base_currency, target_currency, rate, effective_date, description, status)
        VALUES ('USD', 'KHR', ?, CURDATE(), 'Initial rate from settings', 'active')
    ");
    $stmt->execute([$rate]);
}

try {
    $pdo = get_db_connection();

    $table = api_identifier((string)($_GET['table'] ?? ''));
    if ($table === null) {
        api_error('Invalid table.', 422);
    }

    $setupPermissions = [
        'pages' => ['sr_setup_pages.view', 'pages.view'],
        'delivery_types' => ['sr_setup_delivery_types.view', 'delivery_types.view'],
        'delivery_costs' => ['sr_setup_delivery_price.view', 'delivery_costs.view'],
        'logos' => ['sr_setup_logos.view', 'logos.view'],
        'note_options' => ['sr_setup_note_options.view', 'note_options.view'],
        'money_exchange' => ['sr_setup_money_exchange.view', 'money_exchange.view'],
    ];
    if (isset($setupPermissions[$table])) {
        require_role_or_permission(['admin'], ...$setupPermissions[$table]);
    } else {
        require_role_or_permission(['admin'], 'reports_data.view');
    }

    if ($table === 'money_exchange') {
        setup_table_data_ensure_money_exchange($pdo);
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
