<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/rbac_permission_modules.php';
require_role_or_permission(['admin'], 'role_permissions.view');

$pdo = get_db_connection();

/**
 * CRUD-style permissions: Module → Resource → View/Create/Update/Delete
 * perm_key format: resource.action (e.g. orders.view, orders.create)
 */
$ACTIONS = ['view', 'create', 'update', 'delete', 'approve', 'manage'];
$MODULES = rbac_permission_modules();
$PERMISSIONS = rbac_permission_defs();
$DEFAULT_ROLE_PERMS = rbac_default_role_permission_keys();
$sellerPerms = $DEFAULT_ROLE_PERMS['seller'] ?? [];
$cashierPerms = $DEFAULT_ROLE_PERMS['cashier'] ?? [];
$scannerPerms = $DEFAULT_ROLE_PERMS['scanner'] ?? [];

function ensure_rbac_tables(PDO $pdo): void
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            PRIMARY KEY(role_id, permission_id),
            CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_roles (
            user_id INT NOT NULL,
            role_id INT NOT NULL,
            PRIMARY KEY(user_id, role_id),
            CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    migrate_role_permissions_to_role_id($pdo);
}

/**
 * Migrate role_permissions from old schema (role VARCHAR) to new (role_id INT).
 */
function migrate_role_permissions_to_role_id(PDO $pdo): void
{
    $hasRoleId = (bool) $pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'role_id'")->fetchColumn();
    if ($hasRoleId) {
        return; // Already new schema
    }
    $hasRole = (bool) $pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'role'")->fetchColumn();
    if (!$hasRole) {
        return; // Unexpected schema, skip
    }
    $pdo->exec("
        CREATE TABLE role_permissions_new (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            PRIMARY KEY(role_id, permission_id),
            CONSTRAINT fk_rp_new_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT fk_rp_new_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        INSERT INTO role_permissions_new (role_id, permission_id)
        SELECT r.id, rp.permission_id
        FROM role_permissions rp
        JOIN roles r ON r.name = rp.role
    ");
    $pdo->exec("DROP TABLE role_permissions");
    $pdo->exec("RENAME TABLE role_permissions_new TO role_permissions");
}

function upsert_permissions(PDO $pdo, array $defs): void
{
    $stmt = $pdo->prepare("
        INSERT INTO permissions (perm_key, label, description)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description)
    ");
    foreach ($defs as $p) {
        $stmt->execute([(string)$p['key'], (string)$p['label'], $p['description'] ?? null]);
    }
}

function get_permissions_map(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id, perm_key FROM permissions")->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[$r['perm_key']] = $r;
    }
    return $map;
}

function get_role_permissions(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT r.name AS role, p.perm_key
            FROM role_permissions rp
            JOIN roles r ON r.id = rp.role_id
            JOIN permissions p ON p.id = rp.permission_id
        ");
    } catch (Throwable $e) {
        return [];
    }
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $role = (string)$r['role'];
        $map[$role] ??= [];
        $map[$role][(string)$r['perm_key']] = true;
    }
    return $map;
}

$ROLES = [
    ['name' => 'admin', 'label' => 'Admin'],
    ['name' => 'seller', 'label' => 'Seller'],
    ['name' => 'cashier', 'label' => 'Cashier'],
    ['name' => 'scanner', 'label' => 'Scanner'],
];

function upsert_roles(PDO $pdo, array $roles): void
{
    $stmt = $pdo->prepare("INSERT INTO roles(name, label) VALUES(?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label)");
    foreach ($roles as $r) {
        $stmt->execute([(string)$r['name'], (string)$r['label']]);
    }
}

function get_roles(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT id, name, label FROM roles ORDER BY name ASC")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

$me = current_user();
$isMainAdmin = (($me['username'] ?? '') === 'admin');

ensure_rbac_tables($pdo);
upsert_roles($pdo, $ROLES);
upsert_permissions($pdo, $PERMISSIONS);

$permMap = get_permissions_map($pdo);

// Keep behavior after splitting Lucky Box from Product Sets: roles that already have
// product_sets.view / .update also get lucky_box_sets.view / .update (idempotent).
if (isset($permMap['lucky_box_sets.view'], $permMap['product_sets.view'])) {
    $align = $pdo->prepare('
        INSERT IGNORE INTO role_permissions(role_id, permission_id)
        SELECT rp.role_id, ?
        FROM role_permissions rp
        WHERE rp.permission_id = ?
    ');
    $align->execute([(int)$permMap['lucky_box_sets.view']['id'], (int)$permMap['product_sets.view']['id']]);
}
if (isset($permMap['lucky_box_sets.update'], $permMap['product_sets.update'])) {
    $align = $pdo->prepare('
        INSERT IGNORE INTO role_permissions(role_id, permission_id)
        SELECT rp.role_id, ?
        FROM role_permissions rp
        WHERE rp.permission_id = ?
    ');
    $align->execute([(int)$permMap['lucky_box_sets.update']['id'], (int)$permMap['product_sets.update']['id']]);
}

// Stock Closing (System Report) mirrors Operation EOD/EOM Reports permissions.
$closingAlignPairs = [
    ['sr_inventory_closing.view', 'eod_eom_reports.view'],
    ['sr_inventory_closing.create', 'eod_eom_reports.create'],
    ['sr_inventory_closing.update', 'eod_eom_reports.update'],
    ['sr_inventory_closing.delete', 'eod_eom_reports.delete'],
    ['sr_inventory_closing.approve', 'eod_eom_reports.update'],
];
foreach ($closingAlignPairs as [$toKey, $fromKey]) {
    if (!isset($permMap[$toKey], $permMap[$fromKey])) {
        continue;
    }
    $align = $pdo->prepare('
        INSERT IGNORE INTO role_permissions(role_id, permission_id)
        SELECT rp.role_id, ?
        FROM role_permissions rp
        WHERE rp.permission_id = ?
    ');
    $align->execute([(int)$permMap[$toKey]['id'], (int)$permMap[$fromKey]['id']]);
}

$viewId = (int)($permMap['sr_inventory_delivery_notes.view']['id'] ?? 0);
if ($viewId > 0) {
    $assignedDeliveryNotes = (int)$pdo->query('SELECT COUNT(*) FROM role_permissions WHERE permission_id = ' . $viewId)->fetchColumn();
    if ($assignedDeliveryNotes === 0) {
        $deliveryNotePairs = [
            ['sr_inventory_delivery_notes.view', 'sr_inventory_transfer.view'],
            ['sr_inventory_delivery_notes.update', 'sr_inventory_transfer.create'],
            ['sr_inventory_delivery_notes.delete', 'sr_inventory_transfer.create'],
        ];
        $seedDelivery = $pdo->prepare('
            INSERT IGNORE INTO role_permissions(role_id, permission_id)
            SELECT rp.role_id, ?
            FROM role_permissions rp
            WHERE rp.permission_id = ?
        ');
        foreach ($deliveryNotePairs as [$toKey, $fromKey]) {
            if (!isset($permMap[$toKey], $permMap[$fromKey])) {
                continue;
            }
            $seedDelivery->execute([(int)$permMap[$toKey]['id'], (int)$permMap[$fromKey]['id']]);
        }
    }
}

$roles = get_roles($pdo);
$rolesByName = [];
foreach ($roles as $r) { $rolesByName[$r['name']] = $r; }

$existing = get_role_permissions($pdo);

// Seed default permissions for built-in roles if empty
$ins = $pdo->prepare("INSERT IGNORE INTO role_permissions(role_id, permission_id) VALUES(?, ?)");
if (empty($existing['admin']) && isset($rolesByName['admin'])) {
    foreach ($permMap as $row) {
        $ins->execute([(int)$rolesByName['admin']['id'], (int)$row['id']]);
    }
}
if (isset($rolesByName['admin'], $permMap['users_activity.view'])) {
    $ins->execute([(int)$rolesByName['admin']['id'], (int)$permMap['users_activity.view']['id']]);
}
// Seller: statistics, orders, receipts, etc.
if (empty($existing['seller']) && isset($rolesByName['seller'])) {
    foreach ($sellerPerms as $pk) {
        if (isset($permMap[$pk])) $ins->execute([(int)$rolesByName['seller']['id'], (int)$permMap[$pk]['id']]);
    }
}
// Cashier: print, broadcast, etc.
if (empty($existing['cashier']) && isset($rolesByName['cashier'])) {
    foreach ($cashierPerms as $pk) {
        if (isset($permMap[$pk])) $ins->execute([(int)$rolesByName['cashier']['id'], (int)$permMap[$pk]['id']]);
    }
}
// Scanner: scanner home, items view
if (empty($existing['scanner']) && isset($rolesByName['scanner'])) {
    foreach ($scannerPerms as $pk) {
        if (isset($permMap[$pk])) $ins->execute([(int)$rolesByName['scanner']['id'], (int)$permMap[$pk]['id']]);
    }
}
$existing = get_role_permissions($pdo);

if (!$isMainAdmin && !has_permission('roles.view') && !has_permission('role_permissions.view')) {
    http_response_code(403);
    exit('Access denied');
}

$success = '';
$errors = [];
$focusRole = isset($_GET['role']) ? trim((string)$_GET['role']) : null;
if (!$focusRole || !isset($rolesByName[$focusRole])) {
    $focusRole = !empty($roles) ? $roles[0]['name'] : 'admin';
    if (!isset($_GET['role']) && !empty($roles)) {
        header('Location: role_permissions.php?role=' . urlencode($focusRole));
        exit;
    }
}
if (!isset($rolesByName[$focusRole])) {
    $focusRole = !empty($roles) ? $roles[0]['name'] : null;
}
$focusRoleId = $focusRole ? (int)($rolesByName[$focusRole]['id'] ?? 0) : 0;
$focusRoleLabel = $focusRole ? ($rolesByName[$focusRole]['label'] ?? $focusRole) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $postAction = $_POST['action'];
    $saveRole = trim((string)($_POST['save_role'] ?? ''));
    if ($saveRole && isset($rolesByName[$saveRole])) {
        $roleId = (int)$rolesByName[$saveRole]['id'];
        try {
            $pdo->beginTransaction();
            if ($postAction === 'merge_defaults') {
                $mergeLists = [
                    'seller' => $sellerPerms,
                    'cashier' => $cashierPerms,
                    'scanner' => $scannerPerms,
                ];
                if (!isset($mergeLists[$saveRole])) {
                    throw new RuntimeException('merge_defaults only applies to seller, cashier, or scanner.');
                }
                $stmtMerge = $pdo->prepare('INSERT IGNORE INTO role_permissions(role_id, permission_id) VALUES(?, ?)');
                foreach ($mergeLists[$saveRole] as $pk) {
                    if (isset($permMap[$pk])) {
                        $stmtMerge->execute([$roleId, (int)$permMap[$pk]['id']]);
                    }
                }
                $success = 'Added any missing default permissions for ' . ($rolesByName[$saveRole]['label'] ?? $saveRole) . '. Existing permissions were kept.';
            } else {
                $stmtDel = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?');
                $stmtDel->execute([$roleId]);
                if ($postAction === 'save') {
                    $selected = $_POST['perm'] ?? [];
                    $keys = array_keys($selected[$saveRole] ?? []);
                    // Require at least View for menu: if an action is assigned, auto-add View
                    $toAdd = [];
                    foreach ($keys as $permKey) {
                        if (!isset($permMap[$permKey])) {
                            continue;
                        }
                        if (preg_match('/^([^.]+)\.(create|update|delete|approve|manage)$/', $permKey, $m)) {
                            $viewKey = $m[1] . '.view';
                            if (!in_array($viewKey, $keys) && isset($permMap[$viewKey])) {
                                $toAdd[$viewKey] = true;
                            }
                        }
                    }
                    $keys = array_unique(array_merge($keys, array_keys($toAdd)));
                    $stmtIns = $pdo->prepare('INSERT INTO role_permissions(role_id, permission_id) VALUES(?, ?)');
                    foreach ($keys as $permKey) {
                        if (isset($permMap[$permKey])) {
                            $stmtIns->execute([$roleId, (int)$permMap[$permKey]['id']]);
                        }
                    }
                    $success = 'Permissions saved for ' . ($rolesByName[$saveRole]['label'] ?? $saveRole) . '.';
                } elseif ($postAction === 'reset') {
                    $permsToApply = [];
                    if ($saveRole === 'admin') {
                        $permsToApply = array_keys($permMap);
                    } elseif ($saveRole === 'seller') {
                        $permsToApply = $sellerPerms;
                    } elseif ($saveRole === 'cashier') {
                        $permsToApply = $cashierPerms;
                    } elseif ($saveRole === 'scanner') {
                        $permsToApply = $scannerPerms;
                    }
                    $stmtIns = $pdo->prepare('INSERT INTO role_permissions(role_id, permission_id) VALUES(?, ?)');
                    foreach ($permsToApply as $pk) {
                        if (isset($permMap[$pk])) {
                            $stmtIns->execute([$roleId, (int)$permMap[$pk]['id']]);
                        }
                    }
                    $label = $rolesByName[$saveRole]['label'] ?? $saveRole;
                    $success = count($permsToApply) > 0
                        ? "Role {$label} reset to default permissions."
                        : "Role {$label} permissions cleared.";
                }
            }
            $pdo->commit();
            $existing = get_role_permissions($pdo);
            $focusRole = $saveRole;
            $focusRoleId = $roleId;
            $focusRoleLabel = $rolesByName[$saveRole]['label'] ?? $saveRole;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Failed to save.';
        }
    }
}

require_once __DIR__ . '/../layout/header.php';
?>
<style>
    .role-permissions-page .perm-switch-track {
        display: block;
        width: 58px;
        height: 28px;
        border-radius: 999px;
        position: relative;
        background: #94a3b8;
        transition: background 0.2s ease;
        box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.12);
        margin: 0 auto;
    }
    .role-permissions-page .perm-cb:focus-visible + .perm-switch-track {
        outline: 2px solid rgba(34, 197, 94, 0.65);
        outline-offset: 2px;
    }
    .role-permissions-page .perm-cb:checked + .perm-switch-track {
        background: #22c55e;
    }
    .role-permissions-page .perm-switch-knob {
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
    .role-permissions-page .perm-cb:checked + .perm-switch-track .perm-switch-knob {
        left: 33px;
    }
    .role-permissions-page .perm-switch-text {
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
    .role-permissions-page .perm-switch-text--on { left: 7px; opacity: 0; }
    .role-permissions-page .perm-switch-text--off { right: 4px; opacity: 1; }
    .role-permissions-page .perm-cb:checked + .perm-switch-track .perm-switch-text--on { opacity: 1; }
    .role-permissions-page .perm-cb:checked + .perm-switch-track .perm-switch-text--off { opacity: 0; }
    .role-permissions-page label.perm-switch {
        cursor: pointer;
        margin: 0;
        vertical-align: middle;
        display: inline-flex;
        justify-content: center;
    }
    .role-permissions-page label.perm-switch:has(.perm-cb:disabled) {
        cursor: not-allowed;
        opacity: 0.55;
    }
</style>

<div class="d-flex flex-column h-100 role-permissions-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0"><i class="bi bi-shield-lock me-2"></i>Role Permissions</h1>
        <a href="roles.php" class="btn btn-outline-secondary btn-sm">← Back to Roles</a>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <?php if (empty($roles)): ?>
        <div class="alert alert-warning">No roles found. <a href="roles.php">Create roles first</a>.</div>
    <?php else: ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="get" class="d-flex flex-wrap align-items-center gap-3">
                <label class="fw-semibold mb-0">Select role to edit:</label>
                <select name="role" class="form-select form-select-lg" style="max-width:220px;" onchange="this.form.submit()">
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= htmlspecialchars($r['name']) ?>" <?= $focusRole === $r['name'] ? 'selected' : '' ?>><?= htmlspecialchars($r['label']) ?> (<?= htmlspecialchars($r['name']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if ($focusRole): ?>
    <?php if (in_array($focusRole, ['seller', 'cashier', 'scanner'], true)): ?>
    <div class="alert alert-info py-2 small mb-3">
      These roles use <strong>Create / Update / Delete</strong> on specific resources (e.g. <strong>Create Orders (Seller)</strong>, <strong>Scanner / AI Scan</strong>, <strong>Print Orders</strong>).
      Expand the <strong>Seller / Scanner</strong> and other modules to see those columns.
      If the role only has View (e.g. after using “View only”), click <strong>Merge default permissions</strong> below to add back missing defaults without removing anything you already granted.
    </div>
    <?php endif; ?>
    <form method="post" id="resetForm" class="d-none">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="save_role" value="<?= htmlspecialchars($focusRole) ?>">
    </form>
    <?php if (in_array($focusRole, ['seller', 'cashier', 'scanner'], true)): ?>
    <form method="post" id="mergeDefaultsForm" class="d-none">
        <input type="hidden" name="action" value="merge_defaults">
        <input type="hidden" name="save_role" value="<?= htmlspecialchars($focusRole) ?>">
    </form>
    <?php endif; ?>
    <form method="post" id="permForm">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="save_role" value="<?= htmlspecialchars($focusRole) ?>">
        <div class="mb-2 d-flex flex-wrap gap-2 align-items-center">
            <span class="text-muted small">Quick:</span>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-preset="all">All actions</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-preset="view">View only</button>
            <span class="text-muted small ms-2">|</span>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-expand="all"><i class="bi bi-chevron-double-down me-1"></i>Expand all</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-expand="none"><i class="bi bi-chevron-double-up me-1"></i>Collapse all</button>
            <span class="text-muted small ms-2">|</span>
            <?php if (in_array($focusRole, ['seller', 'cashier', 'scanner'], true)): ?>
            <button type="submit" form="mergeDefaultsForm" class="btn btn-outline-info btn-sm" onclick="return confirm('Add any missing default permissions for <?= htmlspecialchars($focusRoleLabel) ?>? Current permissions stay; only defaults that are not already checked will be added.');"><i class="bi bi-plus-square me-1"></i>Merge default permissions</button>
            <?php endif; ?>
            <button type="submit" form="resetForm" class="btn btn-outline-warning btn-sm" onclick="return confirm('Reset <?= htmlspecialchars($focusRoleLabel) ?> to default permissions?');"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset role</button>
        </div>
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="px-3 py-2 bg-light border-bottom small text-muted">
                    Set which actions <strong><?= htmlspecialchars($focusRoleLabel) ?></strong> can do for each resource. V=View, C=Create, U=Update, D=Delete, A=Approve, M=Manage.
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th style="min-width: 240px;">Module / Resource</th>
                                <th class="text-center" style="width: 70px;">View</th>
                                <th class="text-center" style="width: 70px;">Create</th>
                                <th class="text-center" style="width: 70px;">Update</th>
                                <th class="text-center" style="width: 70px;">Delete</th>
                                <th class="text-center" style="width: 70px;">Approve</th>
                                <th class="text-center" style="width: 70px;">Manage</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        foreach ($MODULES as $moduleName => $resources): 
                        ?>
                            <tr class="table-secondary module-row" data-module="<?= htmlspecialchars($moduleName) ?>" role="button" tabindex="0" style="cursor: pointer;">
                                <td colspan="7" class="fw-bold py-2">
                                    <i class="bi bi-chevron-down me-1 module-chevron" id="chev-<?= htmlspecialchars($moduleName) ?>"></i>
                                    <i class="bi bi-folder2 me-1"></i><?= htmlspecialchars($moduleName) ?>
                                </td>
                            </tr>
                            <?php foreach ($resources as $res): 
                                $resKey = $res['resource'];
                                $resLabel = $res['label'];
                                $resActions = $res['actions'] ?? $ACTIONS;
                                $disabled = ($focusRole === 'admin' && in_array($resKey, ['roles','role_permissions','users']) && !$isMainAdmin);
                            ?>
                                <tr class="module-rows" data-module="<?= htmlspecialchars($moduleName) ?>">
                                    <td class="ps-4">
                                        <span class="text-muted small"><?= htmlspecialchars($resKey) ?></span>
                                        <div class="fw-semibold"><?= htmlspecialchars($resLabel) ?></div>
                                    </td>
                                    <?php foreach ($ACTIONS as $act): 
                                        $hasAct = in_array($act, $resActions);
                                        $permKey = $resKey . '.' . $act;
                                        $checked = $hasAct && !empty($existing[$focusRole][$permKey]);
                                    ?>
                                        <td class="text-center">
                                            <?php if ($hasAct): ?>
                                            <?php
                                            $permAria = ucfirst($act) . ' — ' . $resLabel;
                                            ?>
                                            <label class="perm-switch">
                                                <input class="visually-hidden perm-cb" type="checkbox"
                                                    data-role="<?= htmlspecialchars($focusRole) ?>"
                                                    data-perm="<?= htmlspecialchars($permKey) ?>"
                                                    data-action="<?= htmlspecialchars($act) ?>"
                                                    name="perm[<?= htmlspecialchars($focusRole) ?>][<?= htmlspecialchars($permKey) ?>]"
                                                    value="1" <?= $checked ? 'checked' : '' ?> <?= $disabled ? 'disabled' : '' ?>
                                                    aria-label="<?= htmlspecialchars($permAria) ?>">
                                                <span class="perm-switch-track" aria-hidden="true">
                                                    <span class="perm-switch-text perm-switch-text--on">ON</span>
                                                    <span class="perm-switch-text perm-switch-text--off">OFF</span>
                                                    <span class="perm-switch-knob"></span>
                                                </span>
                                            </label>
                                            <?php else: ?>
                                            <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-lg me-1"></i> Save Permissions</button>
            </div>
        </div>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function() {
    const form = document.getElementById('permForm');
    if (!form) return;

    // Preset: All = check all, View = check View only
    form.querySelectorAll('[data-preset]').forEach(btn => {
        btn.addEventListener('click', function() {
            const preset = this.dataset.preset;
            form.querySelectorAll('.perm-cb').forEach(cb => {
                if (cb.disabled) return;
                cb.checked = (preset === 'all') || (preset === 'view' && cb.dataset.action === 'view');
            });
        });
    });

    // Menu requires at least View: when an action is checked, auto-check View
    form.querySelectorAll('.perm-cb').forEach(cb => {
        cb.addEventListener('change', function() {
            if (cb.disabled) return;
            const action = cb.dataset.action;
            if (action === 'create' || action === 'update' || action === 'delete' || action === 'approve' || action === 'manage') {
                if (cb.checked) {
                    const perm = cb.dataset.perm;
                    const res = perm ? perm.replace(/\.[^.]+$/, '') : '';
                    const viewCb = form.querySelector('.perm-cb[data-perm="' + res + '.view"]');
                    if (viewCb && !viewCb.disabled) viewCb.checked = true;
                }
            }
        });
    });

    function toggleModule(module) {
        const rows = document.querySelectorAll('.module-rows[data-module="' + module + '"]');
        const chev = document.getElementById('chev-' + module);
        if (!rows.length) return;
        const isCollapsed = rows[0].getAttribute('data-collapsed') === '1';
        rows.forEach(r => {
            r.style.display = isCollapsed ? '' : 'none';
            r.setAttribute('data-collapsed', isCollapsed ? '0' : '1');
        });
        if (chev) chev.className = 'bi me-1 module-chevron ' + (isCollapsed ? 'bi-chevron-down' : 'bi-chevron-right');
    }

    document.querySelector('.table-responsive table')?.addEventListener('click', function(e) {
        const row = e.target.closest('.module-row');
        if (row) {
            e.preventDefault();
            toggleModule(row.getAttribute('data-module'));
        }
    });

    // Expand all / Collapse all
    form.querySelectorAll('[data-expand]').forEach(btn => {
        btn.addEventListener('click', function() {
            const expand = this.dataset.expand;
            const expandAll = expand === 'all';
            document.querySelectorAll('.module-rows').forEach(r => {
                r.style.display = expandAll ? '' : 'none';
                r.setAttribute('data-collapsed', expandAll ? '0' : '1');
            });
            document.querySelectorAll('.module-chevron').forEach(chev => {
                chev.className = 'bi me-1 module-chevron ' + (expandAll ? 'bi-chevron-down' : 'bi-chevron-right');
            });
        });
    });
})();
</script>
<?php require_once __DIR__ . '/../layout/footer.php';
