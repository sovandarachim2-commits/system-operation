<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'spending.view', 'sr_expense_records.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

function ensure_finance_spending_update_columns(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            color VARCHAR(20) NULL DEFAULT '#6b7280',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_company_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $cols = $pdo->query("SHOW COLUMNS FROM finance_spending")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('payment_method', $cols, true)) {
            $pdo->exec("ALTER TABLE finance_spending ADD COLUMN payment_method VARCHAR(100) NULL AFTER currency");
        }
        if (!in_array('company_id', $cols, true)) {
            $pdo->exec("ALTER TABLE finance_spending ADD COLUMN company_id INT NULL AFTER payment_method");
        }
        if (!in_array('updated_by', $cols, true)) {
            $pdo->exec("ALTER TABLE finance_spending ADD COLUMN updated_by INT NULL AFTER created_by");
        }
        if (!in_array('updated_at', $cols, true)) {
            $pdo->exec("ALTER TABLE finance_spending ADD COLUMN updated_at DATETIME NULL AFTER updated_by");
        }
    } catch (Throwable $e) {
        error_log('finance_spending update column check failed: ' . $e->getMessage());
    }
}

ensure_finance_spending_update_columns($pdo);

// Get spending ID from URL
$spending_id = $_GET['id'] ?? '';

if (empty($spending_id)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing spending ID']);
    exit;
}

try {
    // Avoid LEFT JOIN users ... OR ... here: old spending rows may store created_by
    // as either a numeric id or text, and mixed collations can break JSON loading.
    $stmt = $pdo->prepare("SELECT fs.*, COALESCE(c.name, '') AS company_name,
            COALESCE(
                NULLIF((SELECT ux.name COLLATE utf8mb4_unicode_ci FROM users ux WHERE ux.id = CAST(fs.created_by AS UNSIGNED) LIMIT 1), ''),
                NULLIF((SELECT ux.name COLLATE utf8mb4_unicode_ci FROM users ux WHERE ux.username COLLATE utf8mb4_unicode_ci = CAST(fs.created_by AS CHAR) COLLATE utf8mb4_unicode_ci LIMIT 1), ''),
                NULLIF((SELECT ux.name COLLATE utf8mb4_unicode_ci FROM users ux WHERE ux.name COLLATE utf8mb4_unicode_ci = CAST(fs.created_by AS CHAR) COLLATE utf8mb4_unicode_ci LIMIT 1), ''),
                CAST(fs.created_by AS CHAR) COLLATE utf8mb4_unicode_ci,
                ''
            ) AS created_by_name,
            COALESCE(NULLIF(uu.name, ''), uu.username, '') AS updated_by_name
        FROM finance_spending fs
        LEFT JOIN companies c ON c.id = fs.company_id
        LEFT JOIN users uu ON fs.updated_by = uu.id
        WHERE fs.id = ?");
    $stmt->execute([$spending_id]);
    $spending = $stmt->fetch();
} catch (Throwable $e) {
    error_log('get_spending.php: ' . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load spending data.']);
    exit;
}

if (!$spending) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Spending record not found']);
    exit;
}

require_once __DIR__ . '/finance_spending_history_lib.php';
$history = finance_spending_history_bootstrap($pdo, $spending);

// Get users for dropdowns
$stmt = $pdo->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'spending' => $spending,
    'history' => $history,
    'users' => $users
]);
?>


