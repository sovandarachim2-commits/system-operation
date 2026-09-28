<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../upload_paths.php';

function setup_actions_ensure_money_exchange(PDO $pdo): void
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$table = api_identifier($_GET['table'] ?? '');

if (!$table) {
    api_error('Table is required.', 422);
}

$allowedTables = ['pages', 'delivery_types', 'delivery_costs', 'logos', 'note_options', 'money_exchange'];
if (!in_array($table, $allowedTables, true)) {
    api_error('Invalid setup table.', 403);
}

$setupPermissions = [
    'pages' => ['sr_setup_pages', 'pages'],
    'delivery_types' => ['sr_setup_delivery_types', 'delivery_types'],
    'delivery_costs' => ['sr_setup_delivery_price', 'delivery_costs'],
    'logos' => ['sr_setup_logos', 'logos'],
    'note_options' => ['sr_setup_note_options', 'note_options'],
    'money_exchange' => ['sr_setup_money_exchange', 'money_exchange'],
];
$permissionAction = in_array($action, ['create', 'update', 'delete'], true) ? $action : 'view';
$permissionPrefixes = $setupPermissions[$table] ?? [];
$permissions = [];
foreach ($permissionPrefixes as $prefix) {
    $permissions[] = $prefix . '.' . $permissionAction;
}
require_role_or_permission(['admin'], ...$permissions);

try {
    $pdo = get_db_connection();
    $user = current_user();

    if ($table === 'money_exchange') {
        setup_actions_ensure_money_exchange($pdo);
    }

    if ($method === 'POST') {
        $data = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true);
        } else {
            $data = $_POST;
        }

        if (!is_array($data)) {
            api_error('Invalid payload.', 400);
        }

        // Filter data to only include columns that exist in the table
        $validColumns = array_column(api_table_columns($pdo, $table), 'name');
        
        // Handle File Upload for Logos
        if ($table === 'logos' && isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            try {
                $file = $_FILES['logo_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $filename = 'logo_' . uniqid('', true) . '.' . $ext;
                
                $path = upload_store_uploaded_file($file, 'logos', $filename, null, (string)($file['type'] ?? ''));
                if ($path !== '') {
                    $data['file_path'] = $path;
                    $data['url'] = uploaded_file_url($path, 'logos');
                }
            } catch (Throwable $e) {
                api_error('Failed to upload logo to storage: ' . $e->getMessage(), 500);
            }
        }

        // Apply filtering
        $filterData = function($data, $validColumns) {
            $filtered = [];
            foreach ($data as $key => $value) {
                if (in_array($key, $validColumns, true)) {
                    $filtered[$key] = $value;
                }
            }
            return $filtered;
        };

        if ($action === 'create') {
            unset($data['id']);
            $data['created_by'] = $user['id'];
            $data['updated_by'] = $user['id'];
            
            $data = $filterData($data, $validColumns);
            
            $cols = array_keys($data);
            $placeholders = array_fill(0, count($cols), '?');
            $sql = "INSERT INTO " . api_quote_identifier($table) . " (" . implode(',', array_map('api_quote_identifier', $cols)) . ") VALUES (" . implode(',', $placeholders) . ")";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($data));
            
            api_json(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Item created.']);
        } elseif ($action === 'update') {
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) api_error('ID is required for update.', 422);
            
            unset($data['id']);
            $data['updated_by'] = $user['id'];
            $data['updated_at'] = date('Y-m-d H:i:s');

            $data = $filterData($data, $validColumns);
            
            $sets = [];
            $params = [];
            foreach ($data as $col => $val) {
                $sets[] = api_quote_identifier($col) . " = ?";
                $params[] = $val;
            }
            $params[] = $id;
            
            $sql = "UPDATE " . api_quote_identifier($table) . " SET " . implode(',', $sets) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            api_json(['success' => true, 'message' => 'Item updated.']);
        } elseif ($action === 'delete') {
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) api_error('ID is required for delete.', 422);
            
            $sql = "DELETE FROM " . api_quote_identifier($table) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            
            api_json(['success' => true, 'message' => 'Item deleted.']);
        }
    } else {
        api_error('Method not allowed.', 405);
    }
} catch (Throwable $e) {
    error_log('setup_actions API error: ' . $e->getMessage());
    api_error('Setup action failed: ' . $e->getMessage(), 500);
}
