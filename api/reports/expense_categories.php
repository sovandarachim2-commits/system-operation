<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role_or_permission(
    ['admin'],
    'sr_expense_categories.view',
    'sr_expense_settings.view',
    'sr_cashflow.view',
    'finance_reports.view',
    'manage_categories.view',
    'categories.view'
);

function expense_categories_is_admin(): bool
{
    $user = current_user() ?: [];
    return in_array((string)($user['role'] ?? ''), ['admin'], true);
}

function expense_categories_can(string $action): bool
{
    if (expense_categories_is_admin()) {
        return true;
    }
    $keys = [
        "sr_expense_categories.$action",
        "sr_expense_settings.$action",
        "manage_categories.$action",
        "categories.$action",
    ];
    foreach ($keys as $key) {
        if (has_permission($key)) {
            return true;
        }
    }
    return false;
}

function expense_categories_ensure_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'main',
        parent_category VARCHAR(150) NULL,
        created_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        KEY idx_finance_categories_type (type),
        KEY idx_finance_categories_parent (parent_category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function expense_categories_user_name(): string
{
    $user = current_user() ?: [];
    return trim((string)($user['name'] ?? '')) ?: (trim((string)($user['username'] ?? '')) ?: 'Admin');
}

function expense_categories_payload(PDO $pdo, string $success = ''): array
{
    $mainStmt = $pdo->query("
        SELECT id, name, created_at
        FROM finance_categories
        WHERE type = 'main'
        ORDER BY name
        LIMIT 1000
    ");
    $subStmt = $pdo->query("
        SELECT id, name, parent_category
        FROM finance_categories
        WHERE type = 'sub'
        ORDER BY parent_category, name
        LIMIT 3000
    ");

    return [
        'success' => $success,
        'mainCategories' => array_map(static function (array $row): array {
            return [
                'id' => (string)($row['id'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : '',
                'type' => 'main',
            ];
        }, $mainStmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
        'subcategories' => array_map(static function (array $row): array {
            return [
                'id' => (string)($row['id'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'parent_category' => (string)($row['parent_category'] ?? ''),
                'type' => 'sub',
            ];
        }, $subStmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
        'canCreate' => expense_categories_can('create'),
        'canUpdate' => expense_categories_can('update'),
        'canDelete' => expense_categories_can('delete'),
    ];
}

function expense_categories_find_name(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare("SELECT name FROM finance_categories WHERE id = ?");
    $stmt->execute([$id]);
    return (string)($stmt->fetchColumn() ?: '');
}

try {
    $pdo = get_db_connection();
    expense_categories_ensure_table($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        api_json(expense_categories_payload($pdo));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        api_error('Method not allowed.', 405);
    }

    $action = trim((string)($_POST['action'] ?? ''));
    if (in_array($action, ['add_main_category', 'add_subcategory'], true) && !expense_categories_can('create')) {
        api_error('You do not have permission to create categories.', 403);
    }
    if (in_array($action, ['edit_main_category', 'edit_subcategory'], true) && !expense_categories_can('update')) {
        api_error('You do not have permission to edit categories.', 403);
    }
    if ($action === 'delete_category' && !expense_categories_can('delete')) {
        api_error('You do not have permission to delete categories.', 403);
    }

    if ($action === 'add_main_category') {
        $name = trim((string)($_POST['category_name'] ?? ''));
        if ($name === '') {
            api_error('Category name cannot be empty.');
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_categories WHERE name = ? AND type = 'main'");
        $stmt->execute([$name]);
        if ((int)$stmt->fetchColumn() > 0) {
            api_error("Main category '$name' already exists.");
        }
        $stmt = $pdo->prepare("INSERT INTO finance_categories (name, type, created_by) VALUES (?, 'main', ?)");
        $stmt->execute([$name, expense_categories_user_name()]);
        api_json(expense_categories_payload($pdo, "Main category '$name' added successfully."));
    }

    if ($action === 'add_subcategory') {
        $name = trim((string)($_POST['subcategory_name'] ?? ''));
        $parent = trim((string)($_POST['main_category'] ?? ''));
        if ($name === '' || $parent === '') {
            api_error('Both subcategory name and main category are required.');
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_categories WHERE name = ? AND type = 'sub' AND parent_category = ?");
        $stmt->execute([$name, $parent]);
        if ((int)$stmt->fetchColumn() > 0) {
            api_error("Subcategory '$name' already exists for '$parent'.");
        }
        $stmt = $pdo->prepare("INSERT INTO finance_categories (name, type, parent_category, created_by) VALUES (?, 'sub', ?, ?)");
        $stmt->execute([$name, $parent, expense_categories_user_name()]);
        api_json(expense_categories_payload($pdo, "Subcategory '$name' added successfully."));
    }

    if ($action === 'edit_main_category') {
        $id = max(0, (int)($_POST['category_id'] ?? 0));
        $name = trim((string)($_POST['category_name'] ?? ''));
        if ($id <= 0 || $name === '') {
            api_error('Category ID and name are required.');
        }
        $oldName = expense_categories_find_name($pdo, $id);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_categories WHERE name = ? AND type = 'main' AND id <> ?");
        $stmt->execute([$name, $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            api_error("Main category '$name' already exists.");
        }
        $pdo->prepare("UPDATE finance_categories SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND type = 'main'")
            ->execute([$name, $id]);
        if ($oldName !== '') {
            $pdo->prepare("UPDATE finance_spending SET category = ? WHERE category = ?")->execute([$name, $oldName]);
        }
        api_json(expense_categories_payload($pdo, 'Main category updated successfully.'));
    }

    if ($action === 'edit_subcategory') {
        $id = max(0, (int)($_POST['category_id'] ?? 0));
        $name = trim((string)($_POST['subcategory_name'] ?? ''));
        $parent = trim((string)($_POST['main_category'] ?? ''));
        if ($id <= 0 || $name === '' || $parent === '') {
            api_error('All fields are required.');
        }
        $oldName = expense_categories_find_name($pdo, $id);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_categories WHERE name = ? AND type = 'sub' AND parent_category = ? AND id <> ?");
        $stmt->execute([$name, $parent, $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            api_error("Subcategory '$name' already exists for '$parent'.");
        }
        $pdo->prepare("UPDATE finance_categories SET name = ?, parent_category = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND type = 'sub'")
            ->execute([$name, $parent, $id]);
        if ($oldName !== '') {
            $pdo->prepare("UPDATE finance_spending SET sub_category = ? WHERE sub_category = ?")->execute([$name, $oldName]);
        }
        api_json(expense_categories_payload($pdo, 'Subcategory updated successfully.'));
    }

    if ($action === 'delete_category') {
        $id = max(0, (int)($_POST['category_id'] ?? 0));
        if ($id <= 0) {
            api_error('Category ID is required.');
        }
        $stmt = $pdo->prepare("SELECT name, type FROM finance_categories WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            api_error('Category not found.', 404);
        }
        $name = (string)($row['name'] ?? '');
        $type = (string)($row['type'] ?? '');
        if ($type === 'main') {
            $pdo->prepare("DELETE FROM finance_categories WHERE parent_category = ? AND type = 'sub'")->execute([$name]);
        }
        $pdo->prepare("DELETE FROM finance_categories WHERE id = ?")->execute([$id]);
        api_json(expense_categories_payload($pdo, 'Category deleted successfully.'));
    }

    api_error('Unknown category action.', 400);
} catch (Throwable $e) {
    error_log('expense_categories API error: ' . $e->getMessage());
    api_error('Unable to manage expense categories.', 500);
}
