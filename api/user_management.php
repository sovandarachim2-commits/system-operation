<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../user_activity_lib.php';

require_role_or_permission(['admin'], 'users.view', 'sr_users.view');

$pdo = get_db_connection();
$me = current_user(true);
$myId = (int)($me['id'] ?? 0);
$isMainAdmin = (($me['username'] ?? '') === 'admin');

function um_can(string $permission): bool
{
    global $isMainAdmin;
    if ($isMainAdmin) {
        return true;
    }
    if (!function_exists('has_permission')) {
        return false;
    }
    foreach (um_permission_aliases($permission) as $alias) {
        if (has_permission($alias)) {
            return true;
        }
    }
    return false;
}

function um_permission_aliases(string $permission): array
{
    $aliases = [
        'users.view' => ['users.view', 'sr_users.view'],
        'users.create' => ['users.create', 'sr_users.create'],
        'users.update' => ['users.update', 'sr_users.update'],
        'users.delete' => ['users.delete', 'sr_users.delete'],
    ];
    return $aliases[$permission] ?? [$permission];
}

function um_require(string $permission): void
{
    if (!um_can($permission)) {
        api_error('You do not have permission for this action.', 403);
    }
}

function um_post_payload(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function um_text(array $data, string $key): string
{
    return trim((string)($data[$key] ?? ''));
}

function um_int(array $data, string $key): int
{
    $value = filter_var($data[$key] ?? null, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? 0 : (int)$value;
}

function um_ensure_schema(PDO $pdo): array
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(32) NOT NULL UNIQUE,
            label VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_roles (
            user_id INT NOT NULL,
            role_id INT NOT NULL,
            PRIMARY KEY(user_id, role_id),
            CONSTRAINT fk_um_user_roles_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_um_user_roles_role
                FOREIGN KEY (role_id) REFERENCES roles(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $roleStmt = $pdo->prepare('INSERT INTO roles(name, label) VALUES(?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label)');
    foreach ([['admin', 'Admin'], ['seller', 'Seller'], ['cashier', 'Cashier'], ['scanner', 'Scanner']] as $role) {
        $roleStmt->execute($role);
    }

    $hasActive = false;
    try {
        $hasActive = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'active'")->fetch(PDO::FETCH_ASSOC);
        if (!$hasActive) {
            $pdo->exec('ALTER TABLE users ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1');
            $hasActive = true;
        }
    } catch (Throwable $e) {
        try {
            $hasActive = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'active'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $ignored) {
            $hasActive = false;
        }
    }

    if (function_exists('auth_ensure_users_last_seen_column')) {
        auth_ensure_users_last_seen_column($pdo);
    }

    return ['has_active' => $hasActive];
}

function um_roles(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, name, label FROM roles ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'value' => (string)$row['name'],
        'label' => (string)$row['label'],
    ], $rows);
}

function um_role_by_name(array $roles): array
{
    $map = [];
    foreach ($roles as $role) {
        $map[(string)$role['value']] = $role;
    }
    return $map;
}

function um_user_roles_map(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT ur.user_id, r.name
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $uid = (int)$row['user_id'];
        $map[$uid] ??= [];
        $map[$uid][] = (string)$row['name'];
    }
    return $map;
}

function um_sync_user_role(PDO $pdo, int $userId, string $role, array $rolesByName): void
{
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
    if (isset($rolesByName[$role])) {
        $pdo->prepare('INSERT IGNORE INTO user_roles(user_id, role_id) VALUES(?, ?)')->execute([
            $userId,
            (int)$rolesByName[$role]['id'],
        ]);
    }
}

function um_list_payload(PDO $pdo, array $schema): array
{
    global $myId, $isMainAdmin;
    $q = trim((string)($_GET['q'] ?? ''));
    $status = strtolower(trim((string)($_GET['status'] ?? '')));
    $role = trim((string)($_GET['role'] ?? ''));
    $params = [];
    $where = ['1=1'];

    if ($q !== '') {
        $where[] = '(username LIKE ? OR name LIKE ? OR phone LIKE ? OR telegram_chat_id LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($role !== '') {
        $where[] = 'role = ?';
        $params[] = $role;
    }
    if (($schema['has_active'] ?? false) && in_array($status, ['active', 'inactive'], true)) {
        $where[] = 'active = ?';
        $params[] = $status === 'active' ? 1 : 0;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC');
    $stmt->execute($params);
    $roleMap = um_user_roles_map($pdo);

    $users = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)($row['id'] ?? 0);
        $lastSeen = (string)($row['last_seen_at'] ?? '');
        $lastSeenTs = $lastSeen !== '' ? strtotime($lastSeen) : false;
        $isOnline = $lastSeenTs !== false && $lastSeenTs >= (time() - 300);
        $isPrimaryAdmin = $id === 1 || (string)($row['username'] ?? '') === 'admin';
        $users[] = [
            'id' => $id,
            'username' => (string)($row['username'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'role' => (string)($row['role'] ?? ''),
            'roles' => $roleMap[$id] ?? [],
            'phone' => (string)($row['phone'] ?? ''),
            'telegram_chat_id' => (string)($row['telegram_chat_id'] ?? ''),
            'telegram_thread_id' => (string)($row['telegram_thread_id'] ?? ''),
            'active' => (int)($row['active'] ?? 1) === 1,
            'last_seen_at' => $lastSeen,
            'online' => $isOnline,
            'profile_image_url' => function_exists('user_profile_image_url') ? user_profile_image_url($row) : '',
            'can_update' => um_can('users.update') && ($isMainAdmin || !$isPrimaryAdmin),
            'can_delete' => um_can('users.delete') && !$isPrimaryAdmin && $id !== $myId,
            'can_toggle_active' => um_can('users.update') && !$isPrimaryAdmin && $id !== $myId,
            'is_current_user' => $id === $myId,
            'is_primary_admin' => $isPrimaryAdmin,
        ];
    }

    $summary = [
        'total' => count($users),
        'active' => count(array_filter($users, static fn(array $user): bool => (bool)$user['active'])),
        'inactive' => count(array_filter($users, static fn(array $user): bool => !(bool)$user['active'])),
        'online' => count(array_filter($users, static fn(array $user): bool => (bool)$user['online'])),
    ];

    return [
        'success' => true,
        'users' => $users,
        'summary' => $summary,
        'roles' => um_roles($pdo),
        'can' => [
            'create' => um_can('users.create'),
            'update' => um_can('users.update'),
            'delete' => um_can('users.delete'),
            'has_active' => (bool)($schema['has_active'] ?? false),
        ],
    ];
}

try {
    $schema = um_ensure_schema($pdo);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        api_json(um_list_payload($pdo, $schema));
    }
    if ($method !== 'POST') {
        api_error('Method not allowed.', 405);
    }

    $input = um_post_payload();
    $action = um_text($input, 'action');
    $roles = um_roles($pdo);
    $rolesByName = um_role_by_name($roles);

    if ($action === 'create') {
        um_require('users.create');
        $username = um_text($input, 'username');
        $password = um_text($input, 'password');
        $name = um_text($input, 'name');
        $role = um_text($input, 'role') ?: 'seller';
        $phone = um_text($input, 'phone');
        $telegramChatId = um_text($input, 'telegram_chat_id');
        $telegramThreadId = um_text($input, 'telegram_thread_id');

        if ($username === '' || $password === '' || $name === '') {
            api_error('Username, password and name are required.', 422);
        }
        if (!isset($rolesByName[$role])) {
            api_error('Select a valid role.', 422);
        }

        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, name, role, phone, telegram_chat_id, telegram_thread_id) VALUES (?, SHA2(?, 256), ?, ?, ?, ?, ?)');
        $stmt->execute([
            $username,
            $password,
            $name,
            $role,
            $phone,
            $telegramChatId !== '' ? $telegramChatId : null,
            $telegramThreadId !== '' ? $telegramThreadId : null,
        ]);
        $newUserId = (int)$pdo->lastInsertId();
        if ($newUserId > 0) {
            um_sync_user_role($pdo, $newUserId, $role, $rolesByName);
            user_activity_log($pdo, $me, 'user_create', $username . ' (id ' . $newUserId . ')', [
                'target_user_id' => $newUserId,
                'target_username' => $username,
                'role' => $role,
            ]);
        }
        api_json(array_merge(um_list_payload($pdo, $schema), ['message' => 'User added successfully.']));
    }

    if ($action === 'update') {
        um_require('users.update');
        $id = um_int($input, 'id');
        $name = um_text($input, 'name');
        $role = um_text($input, 'role') ?: 'seller';
        $phone = um_text($input, 'phone');
        $telegramChatId = um_text($input, 'telegram_chat_id');
        $telegramThreadId = um_text($input, 'telegram_thread_id');

        if ($id <= 0) {
            api_error('Invalid user.', 422);
        }
        if (!$isMainAdmin && $id === 1) {
            api_error('You are not allowed to update the main admin.', 403);
        }
        if ($name === '') {
            api_error('Name is required.', 422);
        }
        if (!isset($rolesByName[$role])) {
            api_error('Select a valid role.', 422);
        }

        $beforeStmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $beforeStmt->execute([$id]);
        $beforeUsername = (string)($beforeStmt->fetchColumn() ?: '');
        if ($beforeUsername === '') {
            api_error('User not found.', 404);
        }

        $stmt = $pdo->prepare('UPDATE users SET name = ?, role = ?, phone = ?, telegram_chat_id = ?, telegram_thread_id = ? WHERE id = ?');
        $stmt->execute([
            $name,
            $role,
            $phone,
            $telegramChatId !== '' ? $telegramChatId : null,
            $telegramThreadId !== '' ? $telegramThreadId : null,
            $id,
        ]);
        um_sync_user_role($pdo, $id, $role, $rolesByName);
        user_activity_log($pdo, $me, 'user_update', $beforeUsername . ' -> ' . $name, [
            'target_user_id' => $id,
            'target_username' => $beforeUsername,
            'role' => $role,
        ]);
        api_json(array_merge(um_list_payload($pdo, $schema), ['message' => 'User updated.']));
    }

    if ($action === 'set_active') {
        um_require('users.update');
        if (!($schema['has_active'] ?? false)) {
            api_error('Account status is not available on this database.', 422);
        }
        $id = um_int($input, 'id');
        $active = um_int($input, 'active') === 1 ? 1 : 0;
        if ($id <= 0) {
            api_error('Invalid user.', 422);
        }
        if ($id === 1 && $active === 0) {
            api_error('The primary admin account cannot be turned off.', 403);
        }
        if ($id === $myId && $active === 0) {
            api_error('You cannot deactivate your own account.', 403);
        }
        if (!$isMainAdmin && $id === 1) {
            api_error('You are not allowed to change the primary admin account.', 403);
        }
        $beforeStmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $beforeStmt->execute([$id]);
        $username = (string)($beforeStmt->fetchColumn() ?: '');
        if ($username === '') {
            api_error('User not found.', 404);
        }
        $pdo->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$active, $id]);
        user_activity_log($pdo, $me, 'user_active_set', $username . ' -> ' . ($active === 1 ? 'on' : 'off'), [
            'target_user_id' => $id,
            'target_username' => $username,
            'active' => $active,
        ]);
        api_json(array_merge(um_list_payload($pdo, $schema), ['message' => $active === 1 ? 'User turned on.' : 'User turned off.']));
    }

    if ($action === 'reset_password') {
        um_require('users.update');
        $id = um_int($input, 'id');
        $password = um_text($input, 'password');
        if ($id <= 0 || $password === '') {
            api_error('User and password are required.', 422);
        }
        if (!$isMainAdmin && $id === 1) {
            api_error('You are not allowed to reset the main admin password.', 403);
        }
        $beforeStmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $beforeStmt->execute([$id]);
        $username = (string)($beforeStmt->fetchColumn() ?: '');
        if ($username === '') {
            api_error('User not found.', 404);
        }
        $pdo->prepare('UPDATE users SET password_hash = SHA2(?, 256) WHERE id = ?')->execute([$password, $id]);
        user_activity_log($pdo, $me, 'user_password_reset', $username, [
            'target_user_id' => $id,
            'target_username' => $username,
        ]);
        api_json(array_merge(um_list_payload($pdo, $schema), ['message' => 'Password updated.']));
    }

    if ($action === 'delete') {
        um_require('users.delete');
        $id = um_int($input, 'id');
        if ($id <= 0) {
            api_error('Invalid user.', 422);
        }
        if ($id === 1 || $id === $myId) {
            api_error('This user cannot be deleted.', 403);
        }
        $beforeStmt = $pdo->prepare('SELECT username, name FROM users WHERE id = ?');
        $beforeStmt->execute([$id]);
        $row = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$row) {
            api_error('User not found.', 404);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        user_activity_log($pdo, $me, 'user_delete', (string)$row['username'] . ' (id ' . $id . ')', [
            'target_user_id' => $id,
            'target_username' => (string)$row['username'],
            'target_name' => (string)($row['name'] ?? ''),
        ]);
        api_json(array_merge(um_list_payload($pdo, $schema), ['message' => 'User deleted.']));
    }

    if ($action === 'test_telegram_bot') {
        um_require('users.update');
        $id = um_int($input, 'id');
        if ($id <= 0) {
            api_error('Invalid user.', 422);
        }
        $stmt = $pdo->prepare('SELECT name, username, telegram_chat_id, telegram_thread_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            api_error('User not found.', 404);
        }
        $displayName = trim((string)($user['name'] ?? '')) ?: (string)($user['username'] ?? 'User #' . $id);
        $result = telegram_send_user_test_message(
            $displayName,
            $user['telegram_chat_id'] ?? null,
            $user['telegram_thread_id'] ?? null
        );
        if (!($result['ok'] ?? false)) {
            api_error('Test bot failed for ' . $displayName . ': ' . (string)($result['message'] ?? 'Unknown error'), 422);
        }
        api_json(array_merge(um_list_payload($pdo, $schema), ['message' => 'Test bot sent for ' . $displayName . '. ' . (string)($result['message'] ?? '')]));
    }

    api_error('Unsupported action.', 400);
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? null) === 1062) {
        api_error('Username already exists.', 422);
    }
    error_log('user_management API error: ' . $e->getMessage());
    api_error('Unable to manage users.', 500);
} catch (Throwable $e) {
    error_log('user_management API error: ' . $e->getMessage());
    api_error('Unable to manage users.', 500);
}
