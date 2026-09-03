<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/rbac_permission_modules.php';

require_role_or_permission(['admin'], 'role_permissions.view', 'sr_role_permissions.view');

$pdo = get_db_connection();
$me = current_user(true);
$isMainAdmin = (($me['username'] ?? '') === 'admin');
$canUpdate = $isMainAdmin
    || (($me['role'] ?? '') === 'admin')
    || (function_exists('has_permission') && (
        has_permission('role_permissions.update') || has_permission('sr_role_permissions.update')
    ));

$ACTIONS = rbac_permission_actions();
$MODULES = rbac_permission_modules();
$PERMISSIONS = rbac_permission_defs();
$DEFAULT_ROLE_PERMS = rbac_default_role_permission_keys();

function rp_api_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(32) NOT NULL UNIQUE,
            label VARCHAR(100) NOT NULL,
            description VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            perm_key VARCHAR(100) NOT NULL UNIQUE,
            label VARCHAR(150) NOT NULL,
            description VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $hasRolePerms = (bool)$pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn();
    if (!$hasRolePerms) {
        $pdo->exec("
            CREATE TABLE role_permissions (
                role_id INT NOT NULL,
                permission_id INT NOT NULL,
                PRIMARY KEY(role_id, permission_id),
                CONSTRAINT fk_rp_api_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                CONSTRAINT fk_rp_api_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        return;
    }

    $hasRoleId = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'role_id'")->fetchColumn();
    if ($hasRoleId) {
        return;
    }

    $hasRole = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'role'")->fetchColumn();
    if (!$hasRole) {
        return;
    }

    $pdo->exec("
        CREATE TABLE role_permissions_new (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            PRIMARY KEY(role_id, permission_id),
            CONSTRAINT fk_rp_api_new_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT fk_rp_api_new_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        INSERT INTO role_permissions_new (role_id, permission_id)
        SELECT r.id, rp.permission_id
        FROM role_permissions rp
        JOIN roles r ON r.name = rp.role
    ");
    $pdo->exec('DROP TABLE role_permissions');
    $pdo->exec('RENAME TABLE role_permissions_new TO role_permissions');
}

function rp_api_upsert_roles(PDO $pdo): void
{
    $roles = [
        ['name' => 'admin', 'label' => 'Admin'],
        ['name' => 'seller', 'label' => 'Seller'],
        ['name' => 'cashier', 'label' => 'Cashier'],
        ['name' => 'scanner', 'label' => 'Scanner'],
    ];
    $stmt = $pdo->prepare('INSERT INTO roles(name, label) VALUES(?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label)');
    foreach ($roles as $role) {
        $stmt->execute([$role['name'], $role['label']]);
    }
}

function rp_api_upsert_permissions(PDO $pdo, array $defs): void
{
    $stmt = $pdo->prepare('
        INSERT INTO permissions (perm_key, label, description)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description)
    ');
    foreach ($defs as $perm) {
        $stmt->execute([(string)$perm['key'], (string)$perm['label'], $perm['description'] ?? null]);
    }
}

function rp_api_permissions_map(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, perm_key FROM permissions')->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['perm_key']] = $row;
    }
    return $map;
}

function rp_api_seed_delivery_note_permissions(PDO $pdo): void
{
    $permMap = rp_api_permissions_map($pdo);
    $viewId = (int)($permMap['sr_inventory_delivery_notes.view']['id'] ?? 0);
    if ($viewId <= 0) {
        return;
    }
    $assigned = (int)$pdo->query('SELECT COUNT(*) FROM role_permissions WHERE permission_id = ' . $viewId)->fetchColumn();
    if ($assigned > 0) {
        return;
    }
    $pairs = [
        ['sr_inventory_delivery_notes.view', 'sr_inventory_transfer.view'],
        ['sr_inventory_delivery_notes.update', 'sr_inventory_transfer.create'],
        ['sr_inventory_delivery_notes.delete', 'sr_inventory_transfer.create'],
    ];
    $stmt = $pdo->prepare('
        INSERT IGNORE INTO role_permissions (role_id, permission_id)
        SELECT rp.role_id, ?
        FROM role_permissions rp
        WHERE rp.permission_id = ?
    ');
    foreach ($pairs as [$toKey, $fromKey]) {
        if (!isset($permMap[$toKey], $permMap[$fromKey])) {
            continue;
        }
        $stmt->execute([(int)$permMap[$toKey]['id'], (int)$permMap[$fromKey]['id']]);
    }
}

function rp_api_seed_sales_dashboard_permissions(PDO $pdo): void
{
    $permMap = rp_api_permissions_map($pdo);
    $dashboardId = (int)($permMap['sr_sales_dashboard.view']['id'] ?? 0);
    if ($dashboardId <= 0 || !isset($permMap['sr_financial_summary.view'])) {
        return;
    }
    $assigned = (int)$pdo->query('SELECT COUNT(*) FROM role_permissions WHERE permission_id = ' . $dashboardId)->fetchColumn();
    if ($assigned > 0) {
        return;
    }
    $stmt = $pdo->prepare('
        INSERT IGNORE INTO role_permissions (role_id, permission_id)
        SELECT rp.role_id, ?
        FROM role_permissions rp
        WHERE rp.permission_id = ?
    ');
    $stmt->execute([$dashboardId, (int)$permMap['sr_financial_summary.view']['id']]);
}

function rp_api_roles(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT id, name, label, description FROM roles ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function rp_api_role_permissions(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('
            SELECT r.name AS role, p.perm_key
            FROM role_permissions rp
            JOIN roles r ON r.id = rp.role_id
            JOIN permissions p ON p.id = rp.permission_id
        ');
    } catch (Throwable $e) {
        return [];
    }
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $role = (string)$row['role'];
        $map[$role] ??= [];
        $map[$role][] = (string)$row['perm_key'];
    }
    foreach ($map as $role => $keys) {
        $map[$role] = array_values(array_unique($keys));
    }
    return $map;
}

function rp_api_modules_payload(array $modules, array $actions): array
{
    $out = [];
    foreach ($modules as $moduleName => $resources) {
        $rows = [];
        $isReport = false;
        foreach ($resources as $resource) {
            $resourceName = (string)$resource['resource'];
            if (strpos($resourceName, 'sr_') === 0) {
                $isReport = true;
            }
            $rows[] = [
                'resource' => $resourceName,
                'label' => (string)$resource['label'],
                'actions' => array_values($resource['actions'] ?? ['view', 'create', 'update', 'delete']),
            ];
        }
        $system = $isReport || strpos((string)$moduleName, 'System Report') === 0 ? 'report' : 'operation';
        $out[] = [
            'name' => (string)$moduleName,
            'system' => $system,
            'resources' => $rows,
        ];
    }
    return [
        'actions' => array_values($actions),
        'modules' => $out,
    ];
}

function rp_api_payload(PDO $pdo, array $modules, array $actions, bool $canUpdate, bool $isMainAdmin): array
{
    $roles = rp_api_roles($pdo);
    $assigned = rp_api_role_permissions($pdo);
    $catalog = rp_api_modules_payload($modules, $actions);
    return [
        'success' => true,
        'can_update' => $canUpdate,
        'is_main_admin' => $isMainAdmin,
        'roles' => array_map(static function (array $role) use ($assigned): array {
            $name = (string)$role['name'];
            return [
                'id' => (int)$role['id'],
                'name' => $name,
                'label' => (string)($role['label'] ?? $name),
                'description' => (string)($role['description'] ?? ''),
                'permissions' => $assigned[$name] ?? [],
            ];
        }, $roles),
        'actions' => $catalog['actions'],
        'modules' => $catalog['modules'],
    ];
}

try {
    rp_api_ensure_tables($pdo);
    rp_api_upsert_roles($pdo);
    rp_api_upsert_permissions($pdo, $PERMISSIONS);
    rp_api_seed_delivery_note_permissions($pdo);
    rp_api_seed_sales_dashboard_permissions($pdo);
} catch (Throwable $e) {
    api_error('Unable to initialize role permissions.', 500);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string)$raw, true);
    $input = is_array($decoded) ? $decoded : $_POST;
}

if ($method === 'GET') {
    api_json(rp_api_payload($pdo, $MODULES, $ACTIONS, $canUpdate, $isMainAdmin));
}

if ($method !== 'POST') {
    api_error('Method not allowed.', 405);
}

if (!$canUpdate) {
    api_error('You do not have permission to update role permissions.', 403);
}

$action = trim((string)($input['action'] ?? 'save'));
$roleName = trim((string)($input['role'] ?? $input['save_role'] ?? ''));
$roles = rp_api_roles($pdo);
$rolesByName = [];
foreach ($roles as $role) {
    $rolesByName[(string)$role['name']] = $role;
}

if ($roleName === '' || !isset($rolesByName[$roleName])) {
    api_error('Invalid role.', 422);
}

$roleId = (int)$rolesByName[$roleName]['id'];
$permMap = rp_api_permissions_map($pdo);

try {
    $pdo->beginTransaction();

    if ($action === 'merge_defaults') {
        if (!isset($DEFAULT_ROLE_PERMS[$roleName])) {
            throw new RuntimeException('merge_defaults only applies to seller, cashier, or scanner.');
        }
        $stmt = $pdo->prepare('INSERT IGNORE INTO role_permissions(role_id, permission_id) VALUES(?, ?)');
        foreach ($DEFAULT_ROLE_PERMS[$roleName] as $permKey) {
            if (isset($permMap[$permKey])) {
                $stmt->execute([$roleId, (int)$permMap[$permKey]['id']]);
            }
        }
        $message = 'Added missing default permissions for ' . ($rolesByName[$roleName]['label'] ?? $roleName) . '.';
    } else {
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);

        $keys = [];
        if ($action === 'save') {
            $selected = $input['permissions'] ?? [];
            if (!is_array($selected)) {
                $selected = [];
            }
            $keys = array_values(array_unique(array_map('strval', $selected)));
            $toAdd = [];
            foreach ($keys as $permKey) {
                if (preg_match('/^([^.]+)\.(create|update|delete|approve|manage)$/', $permKey, $match)) {
                    $viewKey = $match[1] . '.view';
                    if (!in_array($viewKey, $keys, true) && isset($permMap[$viewKey])) {
                        $toAdd[$viewKey] = true;
                    }
                }
            }
            $keys = array_values(array_unique(array_merge($keys, array_keys($toAdd))));
            $message = 'Permissions saved for ' . ($rolesByName[$roleName]['label'] ?? $roleName) . '.';
        } elseif ($action === 'reset') {
            if ($roleName === 'admin') {
                $keys = array_keys($permMap);
            } else {
                $keys = $DEFAULT_ROLE_PERMS[$roleName] ?? [];
            }
            $message = count($keys) > 0
                ? 'Role ' . ($rolesByName[$roleName]['label'] ?? $roleName) . ' reset to default permissions.'
                : 'Role ' . ($rolesByName[$roleName]['label'] ?? $roleName) . ' permissions cleared.';
        } else {
            throw new RuntimeException('Unsupported action.');
        }

        $stmt = $pdo->prepare('INSERT INTO role_permissions(role_id, permission_id) VALUES(?, ?)');
        foreach ($keys as $permKey) {
            if (isset($permMap[$permKey])) {
                $stmt->execute([$roleId, (int)$permMap[$permKey]['id']]);
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e->getMessage() ?: 'Failed to save role permissions.', 400);
}

$payload = rp_api_payload($pdo, $MODULES, $ACTIONS, $canUpdate, $isMainAdmin);
$payload['message'] = $message ?? 'Saved.';
api_json($payload);
