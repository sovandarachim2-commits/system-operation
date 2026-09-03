<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'spending.view', 'sr_expense_companies.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

function ensure_expense_companies(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        color VARCHAR(20) NULL DEFAULT '#6b7280',
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_company_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function company_rows(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, name, color, active, created_at FROM companies WHERE active = 1 ORDER BY name ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function company_json(PDO $pdo, array $extra = []): void
{
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => true,
        'companies' => company_rows($pdo),
        'canCreate' => true,
        'canUpdate' => true,
        'canDelete' => true,
    ], $extra));
    exit;
}

ensure_expense_companies($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_company') {
            require_role_or_permission(['admin'], 'spending.create', 'sr_expense_companies.create');
            $name = trim((string)($_POST['name'] ?? ''));
            $color = trim((string)($_POST['color'] ?? '#6b7280')) ?: '#6b7280';
            if ($name === '') {
                throw new RuntimeException('Company name is required.');
            }
            $stmt = $pdo->prepare("INSERT INTO companies (name, color, active) VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE color = VALUES(color), active = 1");
            $stmt->execute([$name, $color]);
            company_json($pdo, ['message' => 'Company created.']);
        }

        if ($action === 'update_company') {
            require_role_or_permission(['admin'], 'spending.update', 'sr_expense_companies.update');
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string)($_POST['name'] ?? ''));
            $color = trim((string)($_POST['color'] ?? '#6b7280')) ?: '#6b7280';
            $active = isset($_POST['active']) ? (int)!!$_POST['active'] : 1;
            if (!$id || $name === '') {
                throw new RuntimeException('Company name is required.');
            }
            $stmt = $pdo->prepare("UPDATE companies SET name = ?, color = ?, active = ? WHERE id = ?");
            $stmt->execute([$name, $color, $active, $id]);
            company_json($pdo, ['message' => 'Company updated.']);
        }

        if ($action === 'delete_company') {
            require_role_or_permission(['admin'], 'spending.delete', 'sr_expense_companies.delete');
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new RuntimeException('Company ID is required.');
            }
            $stmt = $pdo->prepare("UPDATE companies SET active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            company_json($pdo, ['message' => 'Company deleted.']);
        }

        throw new RuntimeException('Invalid action.');
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

company_json($pdo);
?>
