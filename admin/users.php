<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../user_activity_lib.php';
require_role_or_permission(['admin'], 'users.view');

$pdo   = get_db_connection();
$me    = current_user();
$myId  = (int)($me['id'] ?? 0);
$isMainAdmin = ($me['username'] ?? '') === 'admin';

// Permission gate (fallback: main admin if RBAC not installed)
if (!has_permission('users.view') && !$isMainAdmin) {
    http_response_code(403);
    exit('Access denied');
}

// Handle create / update / delete
$errors = [];
$success = '';

// Ensure roles and user_roles tables exist
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
        CONSTRAINT fk_user_roles_user
            FOREIGN KEY (user_id) REFERENCES users(id)
            ON DELETE CASCADE,
        CONSTRAINT fk_user_roles_role
            FOREIGN KEY (role_id) REFERENCES roles(id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Ensure default roles exist (admin, seller, cashier, scanner)
$defaultRoles = [['admin','Admin'],['seller','Seller'],['cashier','Cashier'],['scanner','Scanner']];
$stmtRole = $pdo->prepare("INSERT INTO roles(name, label) VALUES(?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label)");
foreach ($defaultRoles as $dr) {
    $stmtRole->execute($dr);
}

$allRoles = $pdo->query("SELECT id, name, label FROM roles ORDER BY name ASC")->fetchAll();
$roleByName = [];
foreach ($allRoles as $r) { $roleByName[$r['name']] = $r; }

// Login and many screens require users.active = 1 — ensure column exists
$hasUsersActiveColumn = false;
try {
    $activeCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'active'")->fetch(PDO::FETCH_ASSOC);
    if ($activeCol) {
        $hasUsersActiveColumn = true;
    } else {
        $pdo->exec('ALTER TABLE users ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1');
        $hasUsersActiveColumn = true;
    }
} catch (PDOException $e) {
    try {
        $hasUsersActiveColumn = (bool) $pdo->query("SHOW COLUMNS FROM users LIKE 'active'")->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $hasUsersActiveColumn = false;
    }
}

auth_ensure_users_last_seen_column($pdo);

function get_user_roles_map(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT ur.user_id, r.name
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
    ")->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $uid = (int)$row['user_id'];
        $name = (string)$row['name'];
        $map[$uid] ??= [];
        $map[$uid][$name] = true;
    }
    return $map;
}

/** Format DB datetime for display; returns empty string if invalid. */
function users_format_dt(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    $t = strtotime($raw);
    if ($t === false) {
        return $raw;
    }
    return date('Y-m-d H:i', $t);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        require_role_or_permission(['admin'], 'users.create');
        $username          = trim($_POST['username'] ?? '');
        $password          = trim($_POST['password'] ?? '');
        $name              = trim($_POST['name'] ?? '');
        $role              = $_POST['role'] ?? 'seller';
        $phone             = trim($_POST['phone'] ?? '');
        $telegram_chat_id  = trim($_POST['telegram_chat_id'] ?? '');
        $telegram_thread_id = trim($_POST['telegram_thread_id'] ?? '');

        if ($username === '' || $password === '' || $name === '') {
            $errors[] = 'Username, password and name are required.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, name, role, phone, telegram_chat_id, telegram_thread_id) VALUES (?, SHA2(?, 256), ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $username,
                    $password,
                    $name,
                    $role,
                    $phone,
                    $telegram_chat_id !== '' ? $telegram_chat_id : null,
                    $telegram_thread_id !== '' ? $telegram_thread_id : null,
                ]);
                $newUserId = (int)$pdo->lastInsertId();

                // Assign role in user_roles (for RBAC)
                if ($newUserId > 0 && isset($roleByName[$role])) {
                    $pdo->prepare("INSERT IGNORE INTO user_roles(user_id, role_id) VALUES(?, ?)")->execute([$newUserId, (int)$roleByName[$role]['id']]);
                }
                if ($newUserId > 0 && $me) {
                    user_activity_log($pdo, $me, 'user_create', $username . ' (id ' . $newUserId . ')', [
                        'target_user_id' => $newUserId,
                        'target_username' => $username,
                        'role' => $role,
                    ]);
                }
                $success = 'User added successfully.';
            } catch (PDOException $e) {
                $errors[] = 'Failed to add user. Maybe username already exists.';
            }
        }
    } elseif ($action === 'update') {
        require_role_or_permission(['admin'], 'users.update');
        $id                = (int)($_POST['id'] ?? 0);
        $name              = trim($_POST['name'] ?? '');
        $role              = $_POST['role'] ?? 'seller';
        $phone             = trim($_POST['phone'] ?? '');
        $telegram_chat_id  = trim($_POST['telegram_chat_id'] ?? '');
        $telegram_thread_id = trim($_POST['telegram_thread_id'] ?? '');

        if ($id <= 0) {
            $errors[] = 'Invalid user.';
        } elseif ($name === '') {
            $errors[] = 'Name is required.';
        } else {
            // Prevent non-main admins from updating the main admin user
            if (!$isMainAdmin && $id === 1) {
                $errors[] = 'You are not allowed to update the main admin.';
            } else {
            $uStmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $uStmt->execute([$id]);
            $beforeUsername = (string)($uStmt->fetchColumn() ?: '');

            $stmt = $pdo->prepare('UPDATE users SET name = ?, role = ?, phone = ?, telegram_chat_id = ?, telegram_thread_id = ? WHERE id = ?');
            $stmt->execute([
                $name,
                $role,
                $phone,
                $telegram_chat_id !== '' ? $telegram_chat_id : null,
                $telegram_thread_id !== '' ? $telegram_thread_id : null,
                $id,
            ]);

            // Sync user_roles (single role)
            if ($id > 0) {
                $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
                if (isset($roleByName[$role])) {
                    $pdo->prepare("INSERT IGNORE INTO user_roles(user_id, role_id) VALUES(?, ?)")->execute([$id, (int)$roleByName[$role]['id']]);
                }
            }
            if ($me) {
                user_activity_log($pdo, $me, 'user_update', ($beforeUsername !== '' ? $beforeUsername : 'id ' . $id) . ' → ' . $name, [
                    'target_user_id' => $id,
                    'target_username' => $beforeUsername,
                    'role' => $role,
                ]);
            }
            $success = 'User updated.';
            }
        }
    } elseif ($action === 'delete') {
        require_role_or_permission(['admin'], 'users.delete');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Prevent non-main admins from deleting the main admin user
            if (!$isMainAdmin && $id === 1) {
                $errors[] = 'You are not allowed to delete the main admin.';
            } else {
            $uStmt = $pdo->prepare('SELECT username, name FROM users WHERE id = ?');
            $uStmt->execute([$id]);
            $delRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $delUsername = (string)($delRow['username'] ?? '');
            $delName = (string)($delRow['name'] ?? '');

            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$id]);
            if ($me) {
                user_activity_log($pdo, $me, 'user_delete', $delUsername !== '' ? $delUsername . ' (id ' . $id . ')' : 'id ' . $id, [
                    'target_user_id' => $id,
                    'target_username' => $delUsername,
                    'target_name' => $delName,
                ]);
            }
            $success = 'User deleted.';
            }
        }
    } elseif ($action === 'reset_password') {
        $id       = (int)($_POST['id'] ?? 0);
        $password = trim($_POST['password'] ?? '');
        if ($id > 0 && $password !== '') {
            // Prevent non-main admins from resetting the main admin password
            if (!$isMainAdmin && $id === 1) {
                $errors[] = 'You are not allowed to reset the main admin password.';
            } else {
            $uStmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $uStmt->execute([$id]);
            $rpUsername = (string)($uStmt->fetchColumn() ?: '');

            $stmt = $pdo->prepare('UPDATE users SET password_hash = SHA2(?, 256) WHERE id = ?');
            $stmt->execute([$password, $id]);
            if ($me) {
                user_activity_log($pdo, $me, 'user_password_reset', ($rpUsername !== '' ? $rpUsername : 'id ' . $id), [
                    'target_user_id' => $id,
                    'target_username' => $rpUsername,
                ]);
            }
            $success = 'Password updated.';
            }
        }
    } elseif ($action === 'set_active') {
        require_role_or_permission(['admin'], 'users.update');
        if (!$hasUsersActiveColumn) {
            $errors[] = 'Account status is not available on this database.';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $toActive = ((int)($_POST['active'] ?? 0) === 1) ? 1 : 0;
            if ($id <= 0) {
                $errors[] = 'Invalid user.';
            } elseif ($id === 1 && $toActive === 0) {
                $errors[] = 'The primary admin account (ID 1) cannot be turned off.';
            } elseif ($id === $myId && $toActive === 0) {
                $errors[] = 'You cannot deactivate your own account.';
            } elseif (!$isMainAdmin && $id === 1) {
                $errors[] = 'You are not allowed to change the primary admin account.';
            } else {
                $uStmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
                $uStmt->execute([$id]);
                $actUsername = (string)($uStmt->fetchColumn() ?: '');

                $stmt = $pdo->prepare('UPDATE users SET active = ? WHERE id = ?');
                $stmt->execute([$toActive, $id]);
                if ($me) {
                    user_activity_log($pdo, $me, 'user_active_set', ($actUsername !== '' ? $actUsername : 'id ' . $id) . ' → ' . ($toActive === 1 ? 'on' : 'off'), [
                        'target_user_id' => $id,
                        'target_username' => $actUsername,
                        'active' => $toActive,
                    ]);
                }
                $success = $toActive === 1 ? 'User turned on (can sign in).' : 'User turned off (cannot sign in).';
            }
        }
    } elseif ($action === 'test_telegram_bot') {
        require_role_or_permission(['admin'], 'users.update');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errors[] = 'Invalid user.';
        } else {
            $uStmt = $pdo->prepare('SELECT name, username, telegram_chat_id, telegram_thread_id FROM users WHERE id = ? LIMIT 1');
            $uStmt->execute([$id]);
            $testUser = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$testUser) {
                $errors[] = 'User not found.';
            } else {
                $displayName = trim((string)($testUser['name'] ?? ''));
                if ($displayName === '') {
                    $displayName = (string)($testUser['username'] ?? 'User #' . $id);
                }
                $result = telegram_send_user_test_message(
                    $displayName,
                    $testUser['telegram_chat_id'] ?? null,
                    $testUser['telegram_thread_id'] ?? null
                );
                if ($result['ok']) {
                    $success = 'Test bot sent for ' . $displayName . '. ' . (string)$result['message'];
                } else {
                    $errors[] = 'Test bot failed for ' . $displayName . ': ' . (string)$result['message'];
                }
            }
        }
    }
}

// Search users
$search = trim($_GET['search'] ?? '');
$params = [];
$sql = 'SELECT * FROM users WHERE 1=1';
if ($search !== '') {
    $sql .= ' AND (username LIKE ? OR name LIKE ? OR phone LIKE ?)';
    $like = "%{$search}%";
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
$userRolesMap = get_user_roles_map($pdo);
$userListColspan = $hasUsersActiveColumn ? 9 : 8;

include __DIR__ . '/../layout/header.php';
?>
<style>
    .users-page .user-active-track {
        display: block;
        width: 58px;
        height: 28px;
        border-radius: 999px;
        position: relative;
        background: #94a3b8;
        transition: background 0.2s ease;
        box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.12);
    }
    .users-page .user-active-input:focus-visible + .user-active-track {
        outline: 2px solid rgba(34, 197, 94, 0.65);
        outline-offset: 2px;
    }
    .users-page .user-active-input:checked + .user-active-track {
        background: #22c55e;
    }
    .users-page .user-active-knob {
        position: absolute;
        top: 3px;
        left: 3px;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: #fff;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
        transition: left 0.22s ease;
        pointer-events: none;
    }
    .users-page .user-active-input:checked + .user-active-track .user-active-knob {
        left: 33px;
    }
    .users-page .user-active-text {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        font-size: 0.55rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        color: #fff;
        pointer-events: none;
        line-height: 1;
    }
    .users-page .user-active-text--on { left: 7px; opacity: 0; }
    .users-page .user-active-text--off { right: 4px; opacity: 1; }
    .users-page .user-active-input:checked + .user-active-track .user-active-text--on { opacity: 1; }
    .users-page .user-active-input:checked + .user-active-track .user-active-text--off { opacity: 0; }
    .users-page .user-active-track.is-on {
        background: #22c55e;
    }
    .users-page .user-active-track.is-on .user-active-knob {
        left: 33px;
    }
    .users-page .user-active-track.is-on .user-active-text--on { opacity: 1; }
    .users-page .user-active-track.is-on .user-active-text--off { opacity: 0; }
    .users-page .user-active-track.is-off {
        background: #94a3b8;
    }
    .users-page .user-active-track.is-off .user-active-knob {
        left: 3px;
    }
    .users-page .user-active-track.is-off .user-active-text--on { opacity: 0; }
    .users-page .user-active-track.is-off .user-active-text--off { opacity: 1; }
    .users-page label.user-active-toggle {
        cursor: pointer;
        margin: 0;
        vertical-align: middle;
    }
    .users-page .user-active-toggle--static .user-active-track {
        opacity: 0.88;
    }
    .users-page .user-active-hint {
        font-size: 0.7rem;
        color: #6c757d;
        margin-top: 0.25rem;
        max-width: 5.5rem;
        line-height: 1.2;
    }
    .users-page .user-table-avatar {
        width: 44px;
        height: 44px;
        object-fit: cover;
        vertical-align: middle;
    }
    .users-page .user-table-avatar-placeholder {
        width: 44px;
        height: 44px;
    }
    .users-page .user-profile-thumb-trigger {
        cursor: zoom-in;
        border-radius: 50%;
        line-height: 0;
        border: none;
        background: none;
    }
    .users-page .user-profile-thumb-trigger:focus-visible {
        outline: 2px solid rgba(253, 176, 76, 0.85);
        outline-offset: 2px;
    }
    .users-page .user-profile-thumb-trigger:hover img {
        box-shadow: 0 0 0 3px rgba(253, 176, 76, 0.4);
    }
    .users-page .user-avatar-wrap {
        position: relative;
        display: inline-block;
        line-height: 0;
    }
    .users-page .user-online-indicator {
        position: absolute;
        bottom: 1px;
        right: 1px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        border: 2px solid #fff;
        box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.12);
        pointer-events: none;
    }
    .users-page .user-online-indicator--on {
        background: #22c55e;
    }
    .users-page .user-online-indicator--off {
        background: #cbd5e1;
    }
    .users-page .user-online-indicator--modal {
        width: 16px;
        height: 16px;
        bottom: 4px;
        right: 4px;
        border-width: 3px;
    }
</style>
<div class="d-flex flex-column h-100 users-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">User Management</h1>
        <div class="d-flex flex-wrap gap-2">
            <form class="d-flex" method="get">
                <input type="text" name="search" class="form-control form-control-lg me-2" placeholder="Search users" value="<?= htmlspecialchars($search) ?>">
                <button class="btn btn-outline-primary btn-lg" type="submit">Search</button>
            </form>
            <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addUserModal">+ Add User</button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive table-responsive-full">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th class="text-center">Profile</th>
                            <th>Username</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Phone</th>
                            <th>Telegram</th>
                            <?php if ($hasUsersActiveColumn): ?><th>Active</th><?php endif; ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$users): ?>
                        <tr><td colspan="<?= (int)$userListColspan ?>" class="text-center py-4">No users found.</td></tr>
                    <?php else: ?>
                        <?php $rowNo = 0; foreach ($users as $u): $rowNo++; ?>
                        <?php
                        $uid = (int)$u['id'];
                        $rbacNames = isset($userRolesMap[$uid]) ? array_keys($userRolesMap[$uid]) : [];
                        $rbacLines = [];
                        foreach ($allRoles as $r) {
                            if (in_array($r['name'], $rbacNames, true)) {
                                $rbacLines[] = $r['label'] . ' (' . $r['name'] . ')';
                            }
                        }
                        $detailAvatar = user_profile_image_url($u);
                        $isRowMain = $u['username'] === 'admin' && (int)$u['id'] === 1;
                        $rowOnline = auth_user_is_online_by_last_seen(isset($u['last_seen_at']) ? (string)$u['last_seen_at'] : null);
                        $rowDotClass = $rowOnline ? 'user-online-indicator--on' : 'user-online-indicator--off';
                        $rowDotLabel = $rowOnline ? 'Online (active in the last 5 minutes)' : 'Offline or inactive recently';
                        ?>
                        <tr>
                            <td><?= (int)$rowNo ?></td>
                            <td class="text-center align-middle">
                                <div class="user-avatar-wrap">
                                <?php if ($detailAvatar !== ''): ?>
                                    <button type="button" class="user-profile-thumb-trigger p-0" data-bs-toggle="modal" data-bs-target="#userProfileImageModal" data-profile-full="<?= htmlspecialchars($detailAvatar, ENT_QUOTES, 'UTF-8') ?>" data-profile-user="<?= htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') ?>" title="View full photo">
                                        <img src="<?= htmlspecialchars($detailAvatar) ?>" alt="" class="rounded-circle border user-table-avatar" width="44" height="44">
                                    </button>
                                <?php else: ?>
                                    <span class="rounded-circle bg-light border d-inline-flex align-items-center justify-content-center text-secondary user-table-avatar-placeholder" aria-hidden="true"><i class="bi bi-person-fill fs-5"></i></span>
                                <?php endif; ?>
                                    <span class="user-online-indicator <?= $rowDotClass ?>" title="<?= htmlspecialchars($rowDotLabel) ?>" aria-label="<?= htmlspecialchars($rowDotLabel) ?>"></span>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['name']) ?></td>
                            <td><?= htmlspecialchars($u['role']) ?></td>
                            <td><?= htmlspecialchars($u['phone'] ?? '') ?></td>
                            <td class="small">
                                <?php if (!empty($u['telegram_chat_id'])): ?>
                                    <div>ID: <?= htmlspecialchars($u['telegram_chat_id']) ?></div>
                                    <?php if (!empty($u['telegram_thread_id'])): ?>
                                        <div>Topic: <?= htmlspecialchars($u['telegram_thread_id']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">(default)</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($hasUsersActiveColumn): ?>
                            <?php
                            $isActive = ((int)($u['active'] ?? 1) === 1);
                            $canSeeActiveToggle = !($isRowMain && !$isMainAdmin);
                            $showTurnOff = $canSeeActiveToggle && $isActive && $uid !== 1 && $uid !== $myId;
                            $showTurnOn = $canSeeActiveToggle && !$isActive && ($uid !== 1 || $isMainAdmin);
                            $interactiveActive = $showTurnOff || $showTurnOn;
                            ?>
                            <td class="small">
                                <div class="d-flex flex-column align-items-start gap-1">
                                    <?php if ($interactiveActive): ?>
                                    <form method="post" class="m-0 user-active-form">
                                        <input type="hidden" name="action" value="set_active">
                                        <input type="hidden" name="id" value="<?= $uid ?>">
                                        <input type="hidden" name="active" value="<?= $isActive ? '1' : '0' ?>" class="user-active-hidden-val">
                                        <label class="user-active-toggle">
                                            <input type="checkbox" class="visually-hidden user-active-input" <?= $isActive ? 'checked' : '' ?> aria-label="Allow this user to sign in">
                                            <span class="user-active-track" aria-hidden="true">
                                                <span class="user-active-text user-active-text--on">ON</span>
                                                <span class="user-active-text user-active-text--off">OFF</span>
                                                <span class="user-active-knob"></span>
                                            </span>
                                        </label>
                                    </form>
                                    <?php else: ?>
                                    <div class="user-active-toggle user-active-toggle--static">
                                        <span class="user-active-track <?= $isActive ? 'is-on' : 'is-off' ?>" role="img" aria-label="<?= $isActive ? 'On' : 'Off' ?>">
                                            <span class="user-active-text user-active-text--on">ON</span>
                                            <span class="user-active-text user-active-text--off">OFF</span>
                                            <span class="user-active-knob"></span>
                                        </span>
                                    </div>
                                    <?php if ($canSeeActiveToggle && $uid === $myId && $isActive): ?>
                                    <span class="user-active-hint">Your account</span>
                                    <?php elseif ($canSeeActiveToggle && $uid === 1 && $isActive): ?>
                                    <span class="user-active-hint">Always on</span>
                                    <?php elseif (!$canSeeActiveToggle): ?>
                                    <span class="user-active-hint">View only</span>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php endif; ?>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#userDetailModal<?= $uid ?>">Details</button>
                                    <?php if ($isRowMain && !$isMainAdmin): ?>
                                        <!-- Sub-admins can only view main admin, no actions -->
                                        <span class="text-muted small">Main admin (view only)</span>
                                    <?php else: ?>
                                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editUserModal<?= (int)$u['id'] ?>">Edit</button>
                                        <form method="post" class="m-0">
                                            <input type="hidden" name="action" value="test_telegram_bot">
                                            <input type="hidden" name="id" value="<?= $uid ?>">
                                            <button type="submit" class="btn btn-info btn-sm text-white" title="Send test message to this user's Telegram target (or global default)">
                                                <i class="bi bi-send-check-fill me-1"></i>Test Bot
                                            </button>
                                        </form>
                                        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#resetPassModal<?= (int)$u['id'] ?>">Reset Password</button>
                                        <?php if ($u['username'] !== 'admin'): ?>
                                        <form method="post" onsubmit="return confirm('Delete this user?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <!-- User detail (read-only) -->
                        <div class="modal fade" id="userDetailModal<?= $uid ?>" tabindex="-1" aria-labelledby="userDetailModalLabel<?= $uid ?>">
                            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="userDetailModalLabel<?= $uid ?>">User details</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row g-4">
                                            <div class="col-sm-4 text-center">
                                                <?php
                                                $modalOnline = auth_user_is_online_by_last_seen(isset($u['last_seen_at']) ? (string)$u['last_seen_at'] : null);
                                                $modalDotClass = $modalOnline ? 'user-online-indicator--on' : 'user-online-indicator--off';
                                                $modalDotLabel = $modalOnline ? 'Online (recent activity)' : 'Offline or inactive recently';
                                                ?>
                                                <div class="user-avatar-wrap mx-auto" style="width:fit-content">
                                                <?php if ($detailAvatar !== ''): ?>
                                                    <img src="<?= htmlspecialchars($detailAvatar) ?>" alt="" class="rounded-circle border shadow-sm" width="140" height="140" style="object-fit:cover">
                                                <?php else: ?>
                                                    <span class="rounded-circle bg-light border d-inline-flex align-items-center justify-content-center text-secondary" style="width:140px;height:140px" aria-hidden="true"><i class="bi bi-person-fill" style="font-size:3rem"></i></span>
                                                <?php endif; ?>
                                                    <span class="user-online-indicator user-online-indicator--modal <?= $modalDotClass ?>" title="<?= htmlspecialchars($modalDotLabel) ?>" aria-label="<?= htmlspecialchars($modalDotLabel) ?>"></span>
                                                </div>
                                                <div class="mt-2 text-muted small text-break"><?= htmlspecialchars($u['username']) ?></div>
                                            </div>
                                            <div class="col-sm-8">
                                                <dl class="row mb-0 small">
                                                    <dt class="col-sm-4 text-muted">User ID</dt>
                                                    <dd class="col-sm-8"><?= $uid ?></dd>
                                                    <dt class="col-sm-4 text-muted">Username</dt>
                                                    <dd class="col-sm-8 text-break"><?= htmlspecialchars($u['username']) ?></dd>
                                                    <dt class="col-sm-4 text-muted">Name</dt>
                                                    <dd class="col-sm-8"><?= htmlspecialchars($u['name']) ?></dd>
                                                    <dt class="col-sm-4 text-muted">Role (account)</dt>
                                                    <dd class="col-sm-8"><?= htmlspecialchars((string)($u['role'] ?? '')) ?: '—' ?></dd>
                                                    <dt class="col-sm-4 text-muted">Roles (RBAC)</dt>
                                                    <dd class="col-sm-8"><?= $rbacLines ? htmlspecialchars(implode(', ', $rbacLines)) : '<span class="text-muted">None linked</span>' ?></dd>
                                                    <?php if ($hasUsersActiveColumn): ?>
                                                    <dt class="col-sm-4 text-muted">Account status</dt>
                                                    <dd class="col-sm-8"><?= ((int)($u['active'] ?? 1) === 1) ? '<span class="text-success">On (can sign in)</span>' : '<span class="text-danger">Off (cannot sign in)</span>' ?></dd>
                                                    <?php endif; ?>
                                                    <dt class="col-sm-4 text-muted">Phone</dt>
                                                    <dd class="col-sm-8"><?= htmlspecialchars((string)($u['phone'] ?? '')) ?: '—' ?></dd>
                                                    <dt class="col-sm-4 text-muted">Telegram chat ID</dt>
                                                    <dd class="col-sm-8 text-break"><?= !empty($u['telegram_chat_id']) ? htmlspecialchars((string)$u['telegram_chat_id']) : '<span class="text-muted">Default / not set</span>' ?></dd>
                                                    <dt class="col-sm-4 text-muted">Telegram topic ID</dt>
                                                    <dd class="col-sm-8"><?= !empty($u['telegram_thread_id']) ? htmlspecialchars((string)$u['telegram_thread_id']) : '—' ?></dd>
                                                    <dt class="col-sm-4 text-muted">Password</dt>
                                                    <dd class="col-sm-8 text-muted">Hidden (use Reset Password to change)</dd>
                                                    <?php
                                                    $extraTs = ['created_at' => 'Created', 'updated_at' => 'Last updated', 'last_login' => 'Last login', 'last_seen_at' => 'Last activity'];
                                                    foreach ($extraTs as $col => $label):
                                                        if (!array_key_exists($col, $u) || $u[$col] === null || $u[$col] === '') {
                                                            continue;
                                                        }
                                                        $formatted = users_format_dt((string)$u[$col]);
                                                    ?>
                                                    <dt class="col-sm-4 text-muted"><?= htmlspecialchars($label) ?></dt>
                                                    <dd class="col-sm-8"><?= htmlspecialchars($formatted) ?></dd>
                                                    <?php endforeach; ?>
                                                </dl>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        <?php if (!($isRowMain && !$isMainAdmin)): ?>
                                        <span class="text-muted small me-auto d-none d-sm-inline">Use <strong>Edit</strong> in the row to change this user.</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Edit User Modal -->
                        <div class="modal fade" id="editUserModal<?= (int)$u['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="post" id="editUserForm<?= $uid ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit User</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body d-flex flex-column gap-3">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <div>
                                                <label class="form-label">Username</label>
                                                <input type="text" class="form-control form-control-lg" value="<?= htmlspecialchars($u['username']) ?>" disabled>
                                            </div>
                                            <div>
                                                <label class="form-label">Name</label>
                                                <input type="text" name="name" class="form-control form-control-lg" value="<?= htmlspecialchars($u['name']) ?>" required>
                                            </div>
                                            <div>
                                                <label class="form-label">Role</label>
                                                <select name="role" class="form-select form-select-lg">
                                                    <?php 
                                                    $userRole = (string)($u['role'] ?? '');
                                                    if ($userRole === '' && !empty($userRolesMap[(int)$u['id']])) {
                                                        $userRole = (string)array_key_first($userRolesMap[(int)$u['id']]);
                                                    }
                                                    foreach ($allRoles as $r): ?>
                                                        <option value="<?= htmlspecialchars($r['name']) ?>" <?= $userRole === $r['name'] ? 'selected' : '' ?>><?= htmlspecialchars($r['label']) ?> (<?= htmlspecialchars($r['name']) ?>)</option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Phone</label>
                                                <input type="text" name="phone" class="form-control form-control-lg" value="<?= htmlspecialchars($u['phone'] ?? '') ?>">
                                            </div>
                                            <div>
                                                <label class="form-label">Telegram Chat ID (optional)</label>
                                                <input type="text" name="telegram_chat_id" class="form-control form-control-lg" value="<?= htmlspecialchars($u['telegram_chat_id'] ?? '') ?>" placeholder="e.g. -1003261380002">
                                                <div class="form-text">Telegram group for this seller’s orders. Leave empty only if config.php fallback is set.</div>
                                            </div>
                                            <div>
                                                <label class="form-label">Telegram Topic ID (optional)</label>
                                                <input type="text" name="telegram_thread_id" class="form-control form-control-lg" value="<?= htmlspecialchars($u['telegram_thread_id'] ?? '') ?>" placeholder="e.g. 2 or 3">
                                                <div class="form-text">Forum topic inside the group. Leave empty to post in the group general chat.</div>
                                            </div>
                                        </div>
                                    </form>
                                    <div class="modal-footer d-flex flex-wrap gap-2 justify-content-between">
                                        <form method="post" class="m-0">
                                            <input type="hidden" name="action" value="test_telegram_bot">
                                            <input type="hidden" name="id" value="<?= $uid ?>">
                                            <button type="submit" class="btn btn-info text-white">
                                                <i class="bi bi-send-check-fill me-1"></i>Test Bot
                                            </button>
                                        </form>
                                        <div class="d-flex gap-2 ms-auto">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" form="editUserForm<?= $uid ?>" class="btn btn-primary btn-lg">Save Changes</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Reset Password Modal -->
                        <div class="modal fade" id="resetPassModal<?= (int)$u['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reset Password</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body d-flex flex-column gap-3">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <div>
                                                <label class="form-label">New Password</label>
                                                <input type="password" name="password" class="form-control form-control-lg" required>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary btn-lg">Update Password</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control form-control-lg" required>
                    </div>
                    <div>
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control form-control-lg" required>
                    </div>
                    <div>
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control form-control-lg" required>
                    </div>
                    <div>
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select form-select-lg">
                            <?php foreach ($allRoles as $r): ?>
                                <option value="<?= htmlspecialchars($r['name']) ?>" <?= $r['name'] === 'seller' ? 'selected' : '' ?>><?= htmlspecialchars($r['label']) ?> (<?= htmlspecialchars($r['name']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control form-control-lg">
                    </div>
                    <div>
                        <label class="form-label">Telegram Chat ID</label>
                        <input type="text" name="telegram_chat_id" class="form-control form-control-lg" placeholder="e.g. -1003261380002">
                        <div class="form-text">Group where this seller’s orders are sent.</div>
                    </div>
                    <div>
                        <label class="form-label">Telegram Topic ID (optional)</label>
                        <input type="text" name="telegram_thread_id" class="form-control form-control-lg" placeholder="e.g. 2">
                        <div class="form-text">Forum topic in that group (optional).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-lg">Save User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="userProfileImageModal" tabindex="-1" aria-labelledby="userProfileImageModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-dark border border-secondary shadow-lg">
            <div class="modal-header border-secondary py-2">
                <h5 class="modal-title text-white" id="userProfileImageModalTitle">Profile photo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-3">
                <img src="" alt="" id="userProfileImageModalImg" class="img-fluid rounded shadow" style="max-height: min(85vh, 900px); max-width: 100%;">
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    function initUsersPage() {
    var root = document.querySelector('.users-page');
    if (!root) return;

    var modalEl = document.getElementById('userProfileImageModal');
    if (modalEl && modalEl.dataset.usersProfileBound !== '1') {
        modalEl.dataset.usersProfileBound = '1';
        modalEl.addEventListener('show.bs.modal', function (e) {
            var trigger = e.relatedTarget;
            if (!trigger || !trigger.classList.contains('user-profile-thumb-trigger')) return;
            var url = trigger.getAttribute('data-profile-full') || '';
            var name = trigger.getAttribute('data-profile-user') || '';
            var img = document.getElementById('userProfileImageModalImg');
            var title = document.getElementById('userProfileImageModalTitle');
            if (img) {
                img.src = url;
                img.alt = name ? ('Profile photo — ' + name) : 'Profile photo';
            }
            if (title) title.textContent = name ? (name + ' — profile photo') : 'Profile photo';
        });
        modalEl.addEventListener('hidden.bs.modal', function () {
            var img = document.getElementById('userProfileImageModalImg');
            if (img) img.removeAttribute('src');
        });
    }
    document.querySelectorAll('.users-page .user-active-form').forEach(function (form) {
        if (form.dataset.usersActiveBound === '1') return;
        form.dataset.usersActiveBound = '1';
        var cb = form.querySelector('.user-active-input');
        var hidden = form.querySelector('.user-active-hidden-val');
        if (!cb || !hidden) return;
        cb.addEventListener('change', function () {
            hidden.value = this.checked ? '1' : '0';
            if (!this.checked && !window.confirm('Turn this user off? They will not be able to sign in.')) {
                this.checked = true;
                hidden.value = '1';
                return;
            }
            form.submit();
        });
    });
    }

    window.initUsersPage = initUsersPage;

    if (typeof window.adminRunWhenReady === 'function') {
        window.adminRunWhenReady(initUsersPage);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUsersPage);
    } else {
        initUsersPage();
    }

    var mainContent = document.getElementById('mainPageContent');
    if (mainContent && mainContent.dataset.usersContentLoadedBound !== '1') {
        mainContent.dataset.usersContentLoadedBound = '1';
        mainContent.addEventListener('admin:content-loaded', initUsersPage);
    }
})();
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
