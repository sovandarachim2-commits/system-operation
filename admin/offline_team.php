<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'offline_sellers.manage');
require_once __DIR__ . '/offline_lib.php';

$pdo = get_db_connection();
offline_ensure_schema($pdo);

if (!isset($_SESSION)) { session_start(); }

$errors  = [];
$success = '';

// Flash message from redirect
if (!empty($_SESSION['ot_flash'])) {
    $success = $_SESSION['ot_flash'];
    unset($_SESSION['ot_flash']);
}
if (!empty($_SESSION['ot_errors'])) {
    $errors = $_SESSION['ot_errors'];
    unset($_SESSION['ot_errors']);
}

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postErrors = [];

    if ($action === 'add_team') {
        $name        = trim((string)($_POST['name'] ?? ''));
        $code        = trim((string)($_POST['code'] ?? ''));
        $areaRoute   = trim((string)($_POST['area_route'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $isActive    = ($_POST['is_active'] ?? 'active') === 'active' ? 1 : 0;
        if ($name === '') {
            $postErrors[] = 'Team name is required.';
        } else {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM offline_teams WHERE LOWER(name) = LOWER(?)");
            $exists->execute([$name]);
            if ((int)$exists->fetchColumn() > 0) {
                $postErrors[] = "Team name \"$name\" already exists.";
            } else {
                $pdo->prepare("INSERT INTO offline_teams (name, code, area_route, description, is_active) VALUES (?,?,?,?,?)")
                    ->execute([$name, $code ?: null, $areaRoute ?: null, $description ?: null, $isActive]);
                $_SESSION['ot_flash'] = 'Team created successfully.';
                header('Location: offline_team.php'); exit;
            }
        }

    } elseif ($action === 'edit_team') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim((string)($_POST['name'] ?? ''));
        $code        = trim((string)($_POST['code'] ?? ''));
        $leaderId    = (int)($_POST['leader_id'] ?? 0) ?: null;
        $areaRoute   = trim((string)($_POST['area_route'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $isActive    = ($_POST['is_active'] ?? 'active') === 'active' ? 1 : 0;
        if ($id <= 0 || $name === '') {
            $postErrors[] = 'Team name is required.';
        } else {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM offline_teams WHERE LOWER(name) = LOWER(?) AND id <> ?");
            $exists->execute([$name, $id]);
            if ((int)$exists->fetchColumn() > 0) {
                $postErrors[] = "Team name \"$name\" already exists.";
            } else {
                $pdo->prepare("UPDATE offline_teams SET name=?, code=?, leader_id=?, area_route=?, description=?, is_active=? WHERE id=?")
                    ->execute([$name, $code ?: null, $leaderId, $areaRoute ?: null, $description ?: null, $isActive, $id]);
                $_SESSION['ot_flash'] = 'Team updated successfully.';
                header('Location: offline_team.php'); exit;
            }
        }

    } elseif ($action === 'delete_team') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE offline_sellers SET team_id = NULL WHERE team_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM offline_teams WHERE id = ?")->execute([$id]);
            $_SESSION['ot_flash'] = 'Team deleted. Members moved to Unassigned.';
            header('Location: offline_team.php'); exit;
        }

    } elseif ($action === 'add_member') {
        $name   = trim((string)($_POST['name'] ?? ''));
        $teamId = (int)($_POST['team_id'] ?? 0) ?: null;
        if ($name === '') {
            $postErrors[] = 'Member name is required.';
        } else {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM offline_sellers WHERE LOWER(name) = LOWER(?) AND team_id " . ($teamId ? "= ?" : "IS NULL"));
            $params = $teamId ? [$name, $teamId] : [$name];
            $exists->execute($params);
            if ((int)$exists->fetchColumn() > 0) {
                $postErrors[] = "Member \"$name\" already exists in this team.";
            } else {
                $pdo->prepare("INSERT INTO offline_sellers (team_id, name) VALUES (?, ?)")->execute([$teamId, $name]);
                $tName = $teamId ? $pdo->query("SELECT name FROM offline_teams WHERE id = $teamId")->fetchColumn() : '';
                $_SESSION['ot_flash'] = 'Member added' . ($tName ? " to $tName." : '.');
                header('Location: offline_team.php'); exit;
            }
        }

    } elseif ($action === 'edit_member') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim((string)($_POST['name'] ?? ''));
        $teamId   = (int)($_POST['team_id'] ?? 0) ?: null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($id <= 0 || $name === '') {
            $postErrors[] = 'Member name is required.';
        } else {
            $pdo->prepare("UPDATE offline_sellers SET name=?, team_id=?, is_active=? WHERE id=?")->execute([$name, $teamId, $isActive, $id]);
            $_SESSION['ot_flash'] = 'Member updated.';
            header('Location: offline_team.php'); exit;
        }

    } elseif ($action === 'delete_member') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM offline_sellers WHERE id = ?")->execute([$id]);
            $_SESSION['ot_flash'] = 'Member deleted.';
            header('Location: offline_team.php'); exit;
        }

    } elseif ($action === 'set_leader') {
        $teamId   = (int)($_POST['team_id'] ?? 0);
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($teamId > 0 && $memberId > 0) {
            $pdo->prepare("UPDATE offline_teams SET leader_id = ? WHERE id = ?")->execute([$memberId, $teamId]);
            $memberName = $pdo->query("SELECT name FROM offline_sellers WHERE id = $memberId")->fetchColumn();
            $_SESSION['ot_flash'] = $memberName . ' set as team leader.';
            header('Location: offline_team.php'); exit;
        }

    } elseif ($action === 'remove_leader') {
        $teamId = (int)($_POST['team_id'] ?? 0);
        if ($teamId > 0) {
            $pdo->prepare("UPDATE offline_teams SET leader_id = NULL WHERE id = ?")->execute([$teamId]);
            $_SESSION['ot_flash'] = 'Team leader removed.';
            header('Location: offline_team.php'); exit;
        }
    }

    // Validation errors — store in session and redirect back
    if ($postErrors) {
        $_SESSION['ot_errors'] = $postErrors;
        header('Location: offline_team.php'); exit;
    }
}

// Fetch all data
$teams   = $pdo->query("SELECT t.*, s.name AS leader_name, sl.location_name AS loc_name, sl.location_code AS loc_code FROM offline_teams t LEFT JOIN offline_sellers s ON s.id = t.leader_id LEFT JOIN storage_locations sl ON sl.id = t.location_id ORDER BY t.name")->fetchAll(PDO::FETCH_ASSOC);
$members = $pdo->query("SELECT * FROM offline_sellers ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
$allSellers = $pdo->query("SELECT id, name FROM offline_sellers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$membersByTeam = [];
foreach ($members as $m) {
    $membersByTeam[$m['team_id'] ?? 'none'][] = $m;
}

// Avatar colors palette
$avatarColors = ['#e91e63','#9c27b0','#3f51b5','#0288d1','#00897b','#43a047','#f4511e','#8d6e63'];

include __DIR__ . '/../layout/header.php';
?>
<style>
/* Layout */
.ot-wrap        { display: flex; gap: 1.5rem; align-items: flex-start; }
.ot-left        { width: 320px; flex-shrink: 0; }
.ot-right       { flex: 1; min-width: 0; }
@media (max-width: 991px) {
    .ot-wrap    { flex-direction: column; }
    .ot-left    { width: 100%; }
}

/* Left form panel */
.ot-form-card   { background: #fffbeb; border: 0; border-radius: 20px; box-shadow: 0 2px 16px rgba(0,0,0,.07); }
.ot-form-title  { font-size: 1.05rem; font-weight: 800; color: #111; }
.ot-form-icon   { width: 40px; height: 40px; background: linear-gradient(135deg,#ffd5d7,#f8b8cf); border-radius: 10px; display:inline-flex; align-items:center; justify-content:center; color:#e91e63; }
.ot-label       { font-size: .82rem; font-weight: 700; color: #374151; margin-bottom: .3rem; }
.ot-input       { border-radius: 10px !important; border: 1.5px solid #e5e7eb; min-height: 42px; font-size: .9rem; }
.ot-input:focus { border-color: #e91e63 !important; box-shadow: 0 0 0 3px rgba(233,30,99,.1) !important; }
.ot-textarea    { border-radius: 10px !important; border: 1.5px solid #e5e7eb; font-size: .9rem; resize: none; }
.ot-textarea:focus { border-color: #e91e63 !important; box-shadow: 0 0 0 3px rgba(233,30,99,.1) !important; }
.ot-char-count  { font-size: .75rem; color: #9ca3af; text-align: right; }
.btn-create     { background: #e91e63; border: 0; border-radius: 10px; color: #fff; font-weight: 700; font-size: .95rem; min-height: 46px; transition: background .2s; }
.btn-create:hover { background: #c2185b; color: #fff; }

/* Right panel */
.ot-panel-header { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.ot-title        { font-size: 1.1rem; font-weight: 800; color: #111; }
.ot-count-badge  { background: #e91e63; color: #fff; border-radius: 999px; font-size: .78rem; font-weight: 700; padding: .15rem .6rem; }
.ot-search       { border-radius: 10px !important; border: 1.5px solid #e5e7eb; min-height: 38px; font-size: .875rem; padding-left: 2.2rem; }
.ot-search:focus { border-color: #e91e63 !important; box-shadow: 0 0 0 3px rgba(233,30,99,.1) !important; }
.ot-search-wrap  { position: relative; }
.ot-search-icon  { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: .95rem; }
.ot-filter       { border-radius: 10px !important; border: 1.5px solid #e5e7eb; min-height: 38px; font-size: .875rem; }

/* Team Card */
.ot-team-card    { background: #fff5f7; border: 0; border-radius: 16px; margin-bottom: 1rem; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
.ot-team-body    { padding: 1.25rem 1.5rem; }
.ot-team-icon    { width: 56px; height: 56px; background: linear-gradient(135deg,#ffd5d7,#f8b8cf); border-radius: 14px; display:inline-flex; align-items:center; justify-content:center; color:#e91e63; flex-shrink:0; }
.ot-team-name    { font-size: 1.05rem; font-weight: 800; color: #111; }
.ot-badge-active   { background: #dcfce7; color: #15803d; font-size: .75rem; font-weight: 700; border-radius: 999px; padding: .2rem .75rem; }
.ot-badge-inactive { background: #fef3c7; color: #b45309; font-size: .75rem; font-weight: 700; border-radius: 999px; padding: .2rem .75rem; }
.ot-meta-label   { font-size: .72rem; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.ot-meta-value   { font-size: .875rem; color: #374151; font-weight: 600; display: flex; align-items: center; gap: .3rem; }
.ot-divider      { border-top: 1px solid rgba(233,30,99,.1); margin: .75rem 0; }
.ot-members-row  { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.ot-members-label { font-size: .82rem; font-weight: 700; color: #6b7280; min-width: 70px; }
.ot-avatar       { width: 36px; height: 36px; border-radius: 50%; display:inline-flex; align-items:center; justify-content:center; font-weight:800; font-size:.85rem; color:#fff; border: 2px solid #fff; margin-left: -6px; flex-shrink: 0; }
.ot-avatar:first-of-type { margin-left: 0; }
.ot-avatar-more  { background: #e5e7eb; color: #374151; font-size: .75rem; font-weight: 700; }
.ot-btn-icon     { width: 34px; height: 34px; border-radius: 8px; display:inline-flex; align-items:center; justify-content:center; border: 1.5px solid #e5e7eb; background: #fff; color: #374151; transition: .15s; cursor: pointer; }
.ot-btn-icon:hover { background: #f3f4f6; }
.ot-btn-del      { border-color: #fecdd3; color: #e91e63; }
.ot-btn-del:hover { background: #fce7f0; }

/* Add member inline */
.ot-add-member-btn { font-size: .82rem; font-weight: 700; color: #e91e63; background: none; border: none; padding: 0; display: flex; align-items: center; gap: .3rem; }
.ot-add-member-btn:hover { color: #c2185b; }
.ot-inline-form  { background: rgba(255,255,255,.7); border-radius: 10px; padding: .65rem .85rem; margin-top: .5rem; display: flex; gap: .5rem; align-items: center; }

/* Pagination */
.ot-pagination   { display: flex; align-items: center; justify-content: space-between; margin-top: 1rem; }
.ot-page-info    { font-size: .85rem; color: #6b7280; }
.ot-page-btn     { width: 34px; height: 34px; border-radius: 8px; border: 1.5px solid #e5e7eb; background: #fff; color: #374151; display:inline-flex; align-items:center; justify-content:center; font-size: .85rem; font-weight: 700; cursor: pointer; }
.ot-page-btn.active { background: #e91e63; border-color: #e91e63; color: #fff; }
.ot-page-btn:hover:not(.active) { background: #f3f4f6; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <h1 class="h4 mb-0 fw-bold"><i class="bi bi-people-fill me-2 text-danger"></i>Offline Team</h1>
        <span class="text-muted small">/ Offline Management</span>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 mb-3" id="successAlert">
        <i class="bi bi-check-circle-fill text-success"></i>
        <span><?= htmlspecialchars($success) ?></span>
        <button type="button" class="btn-close ms-auto" onclick="document.getElementById('successAlert').remove()"></button>
    </div>
    <?php endif; ?>
    <?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <div class="ot-wrap">
        <!-- ======== LEFT: Create Team ======== -->
        <div class="ot-left">
            <div class="ot-form-card card">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-2 mb-4">
                        <div class="ot-form-icon"><i class="bi bi-people-fill fs-5"></i></div>
                        <span class="ot-form-title">Create New Team</span>
                    </div>
                    <form method="post" id="createTeamForm">
                        <input type="hidden" name="action" value="add_team">
                        <div class="mb-3">
                            <div class="ot-label">Team Name <span class="text-danger">*</span></div>
                            <input name="name" class="form-control ot-input" placeholder="e.g. Morning Shift, Shop A" required maxlength="255">
                        </div>
                        <div class="mb-3">
                            <div class="ot-label">Team Code (Optional)</div>
                            <input name="code" class="form-control ot-input" placeholder="e.g. TEAM-001" maxlength="50">
                        </div>
                        <div class="mb-3">
                            <div class="ot-label">Area / Route</div>
                            <input name="area_route" class="form-control ot-input" placeholder="e.g. Phnom Penh North" maxlength="255">
                        </div>
                        <div class="mb-3">
                            <div class="ot-label">Status</div>
                            <select name="is_active" class="form-select ot-input">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="ot-label">Description (Optional)</div>
                            <textarea name="description" id="descInput" class="form-control ot-textarea" rows="3" maxlength="200" placeholder="Enter description..."></textarea>
                            <div class="ot-char-count"><span id="descCount">0</span>/200</div>
                        </div>
                        <div class="d-grid">
                            <button class="btn btn-create"><i class="bi bi-plus me-1"></i>Create Team</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ======== RIGHT: Team List ======== -->
        <div class="ot-right">
            <!-- Header bar -->
            <div class="ot-panel-header">
                <span class="ot-title">All Teams</span>
                <span class="ot-count-badge"><?= count($teams) ?></span>
                <div class="ot-search-wrap ms-auto" style="width:220px;">
                    <i class="bi bi-search ot-search-icon"></i>
                    <input type="text" id="teamSearch" class="form-control ot-search" placeholder="Search team...">
                </div>
                <select id="statusFilter" class="form-select ot-filter" style="width:130px;">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <!-- Team Cards -->
            <div id="teamList">
            <?php if (!$teams): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-people fs-1 d-block mb-2"></i>
                    No teams yet. Create your first team.
                </div>
            <?php endif; ?>

            <?php foreach ($teams as $idx => $team):
                $teamMembers = $membersByTeam[$team['id']] ?? [];
                $statusClass = $team['is_active'] ? 'active' : 'inactive';
            ?>
            <div class="ot-team-card" data-name="<?= htmlspecialchars(strtolower($team['name'])) ?>" data-status="<?= $statusClass ?>">
                <div class="ot-team-body">
                    <div class="d-flex align-items-start gap-3">
                        <div class="ot-team-icon">
                            <i class="bi bi-people-fill fs-4"></i>
                        </div>
                        <div class="flex-grow-1 min-width-0">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="ot-team-name"><?= htmlspecialchars($team['name']) ?></span>
                                <?php if ($team['code']): ?>
                                    <span class="badge bg-light text-secondary border" style="font-size:.72rem;"><?= htmlspecialchars($team['code']) ?></span>
                                <?php endif; ?>
                                <span class="<?= $team['is_active'] ? 'ot-badge-active' : 'ot-badge-inactive' ?> ms-auto">
                                    <?= $team['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>
                            <div class="row g-3">
                                <div class="col-6 col-md-3">
                                    <div class="ot-meta-label">Leader</div>
                                    <div class="ot-meta-value"><i class="bi bi-person"></i><?= htmlspecialchars($team['leader_name'] ?? '—') ?></div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="ot-meta-label">Area / Route</div>
                                    <div class="ot-meta-value"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($team['area_route'] ?? '—') ?></div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="ot-meta-label">Stock Location</div>
                                    <div class="ot-meta-value"><i class="bi bi-box-seam"></i><?= htmlspecialchars($team['loc_name'] ?? '—') ?></div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="ot-meta-label">Members</div>
                                    <div class="ot-meta-value"><i class="bi bi-people"></i><?= count($teamMembers) ?> members</div>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-shrink-0">
                            <button class="ot-btn-icon" onclick="openEditTeam(<?= (int)$team['id'] ?>,<?= htmlspecialchars(json_encode($team['name'])) ?>,<?= htmlspecialchars(json_encode($team['code'] ?? '')) ?>,<?= (int)($team['leader_id'] ?? 0) ?>,<?= htmlspecialchars(json_encode($team['area_route'] ?? '')) ?>,<?= (int)$team['is_active'] ?>,<?= htmlspecialchars(json_encode($team['description'] ?? '')) ?>)" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="post" onsubmit="return confirm('Delete this team?')">
                                <input type="hidden" name="action" value="delete_team">
                                <input type="hidden" name="id" value="<?= (int)$team['id'] ?>">
                                <button type="submit" class="ot-btn-icon ot-btn-del" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>

                    <div class="ot-divider"></div>

                    <!-- Members avatars -->
                    <div class="ot-members-row">
                        <span class="ot-members-label">Members</span>
                        <?php
                        $maxShow = 5;
                        $shown   = array_slice($teamMembers, 0, $maxShow);
                        $extra   = count($teamMembers) - $maxShow;
                        foreach ($shown as $mi => $m):
                            $color = $avatarColors[$mi % count($avatarColors)];
                            $init  = mb_strtoupper(mb_substr($m['name'], 0, 1));
                        ?>
                            <div class="ot-avatar" style="background:<?= $color ?>;" title="<?= htmlspecialchars($m['name']) ?>"><?= htmlspecialchars($init) ?></div>
                        <?php endforeach; ?>
                        <?php if ($extra > 0): ?>
                            <div class="ot-avatar ot-avatar-more">+<?= $extra ?></div>
                        <?php endif; ?>
                        <?php if (!$teamMembers): ?>
                            <span class="text-muted small">No members</span>
                        <?php endif; ?>

                        <!-- Add Member -->
                        <button class="ot-add-member-btn ms-2" type="button"
                            data-bs-toggle="collapse" data-bs-target="#addMember<?= (int)$team['id'] ?>">
                            <i class="bi bi-plus-circle"></i> Add Member
                        </button>
                    </div>

                    <div class="collapse" id="addMember<?= (int)$team['id'] ?>">
                        <div class="ot-inline-form">
                            <form method="post" class="d-flex gap-2 align-items-center w-100">
                                <input type="hidden" name="action" value="add_member">
                                <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                                <input name="name" class="form-control form-control-sm ot-input" placeholder="Member name" required style="border-radius:8px!important;min-height:36px;">
                                <button class="btn btn-create btn-sm px-3" style="min-height:36px;border-radius:8px;white-space:nowrap;"><i class="bi bi-plus"></i> Add</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Member Management (click to expand) -->
                <?php if ($teamMembers): ?>
                <div class="px-4 pb-3">
                    <button class="ot-add-member-btn text-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#memberList<?= (int)$team['id'] ?>" style="color:#6b7280!important;">
                        <i class="bi bi-list-ul"></i> Manage members
                    </button>
                    <div class="collapse" id="memberList<?= (int)$team['id'] ?>">
                        <div class="mt-2">
                        <?php foreach ($teamMembers as $mi => $m):
                            $isLeader = (int)($team['leader_id'] ?? 0) === (int)$m['id'];
                        ?>
                            <div class="d-flex align-items-center gap-2 py-1 px-2 rounded mb-1" style="background:#fff;">
                                <div class="ot-avatar" style="width:30px;height:30px;font-size:.75rem;background:<?= $avatarColors[$mi % count($avatarColors)] ?>;flex-shrink:0;"><?= mb_strtoupper(mb_substr($m['name'],0,1)) ?></div>
                                <span class="fw-semibold small flex-grow-1">
                                    <?= htmlspecialchars($m['name']) ?>
                                    <?php if ($isLeader): ?>
                                        <span class="ms-1" title="Team Leader" style="color:#f59e0b;">&#9733; Leader</span>
                                    <?php endif; ?>
                                </span>
                                <span class="badge <?= $m['is_active'] ? 'ot-badge-active' : 'ot-badge-inactive' ?>" style="font-size:.7rem;"><?= $m['is_active'] ? 'Active' : 'Inactive' ?></span>
                                <?php if (!$isLeader): ?>
                                <form method="post" title="Set as Leader">
                                    <input type="hidden" name="action" value="set_leader">
                                    <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                                    <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                                    <button type="submit" class="ot-btn-icon" style="width:28px;height:28px;color:#f59e0b;border-color:#fde68a;" title="Set as Leader"><i class="bi bi-star" style="font-size:.75rem;"></i></button>
                                </form>
                                <?php else: ?>
                                <form method="post" title="Remove Leader" onsubmit="return confirm('Remove leader role?')">
                                    <input type="hidden" name="action" value="remove_leader">
                                    <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                                    <button type="submit" class="ot-btn-icon" style="width:28px;height:28px;color:#f59e0b;border-color:#fde68a;background:#fffbeb;" title="Remove Leader"><i class="bi bi-star-fill" style="font-size:.75rem;"></i></button>
                                </form>
                                <?php endif; ?>
                                <button class="ot-btn-icon" style="width:28px;height:28px;" onclick="openEditMember(<?= (int)$m['id'] ?>,<?= htmlspecialchars(json_encode($m['name'])) ?>,<?= (int)($m['team_id']??0) ?>,<?= (int)$m['is_active'] ?>)"><i class="bi bi-pencil" style="font-size:.75rem;"></i></button>
                                <form method="post" onsubmit="return confirm('Delete member?')">
                                    <input type="hidden" name="action" value="delete_member">
                                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                    <button type="submit" class="ot-btn-icon ot-btn-del" style="width:28px;height:28px;"><i class="bi bi-trash" style="font-size:.75rem;"></i></button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>

            <!-- Pagination info -->
            <?php if ($teams): ?>
            <div class="ot-pagination">
                <span class="ot-page-info" id="pageInfo">Showing 1 to <?= count($teams) ?> of <?= count($teams) ?> teams</span>
                <div class="d-flex gap-1" id="paginationBtns"></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Team Modal -->
<div class="modal fade" id="editTeamModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2 text-danger"></i>Edit Team</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body pt-3">
                    <input type="hidden" name="action" value="edit_team">
                    <input type="hidden" name="id" id="etId">
                    <div class="mb-3">
                        <div class="ot-label">Team Name <span class="text-danger">*</span></div>
                        <input name="name" id="etName" class="form-control ot-input" required>
                    </div>
                    <div class="mb-3">
                        <div class="ot-label">Team Code</div>
                        <input name="code" id="etCode" class="form-control ot-input" maxlength="50">
                    </div>
                    <div class="mb-3">
                        <div class="ot-label">Team Leader <span class="text-muted fw-normal">(from team members)</span></div>
                        <select name="leader_id" id="etLeader" class="form-select ot-input">
                            <option value="">-- No leader --</option>
                        </select>
                        <div class="small text-muted mt-1" id="etLeaderHint"></div>
                    </div>
                    <div class="mb-3">
                        <div class="ot-label">Area / Route</div>
                        <input name="area_route" id="etArea" class="form-control ot-input" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <div class="ot-label">Status</div>
                        <select name="is_active" id="etStatus" class="form-select ot-input">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <div class="ot-label">Description</div>
                        <textarea name="description" id="etDesc" class="form-control ot-textarea" rows="3" maxlength="200"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-create rounded-3 px-4"><i class="bi bi-save me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Member Modal -->
<div class="modal fade" id="editMemberModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2 text-danger"></i>Edit Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body pt-3">
                    <input type="hidden" name="action" value="edit_member">
                    <input type="hidden" name="id" id="emId">
                    <div class="mb-3">
                        <div class="ot-label">Member Name <span class="text-danger">*</span></div>
                        <input name="name" id="emName" class="form-control ot-input" required>
                    </div>
                    <div class="mb-3">
                        <div class="ot-label">Team</div>
                        <select name="team_id" id="emTeam" class="form-select ot-input">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="emActive" value="1">
                        <label class="form-check-label fw-semibold small" for="emActive">Active</label>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-create rounded-3 px-4"><i class="bi bi-save me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Character counter
const descInput = document.getElementById('descInput');
const descCount = document.getElementById('descCount');
if (descInput) {
    descInput.addEventListener('input', () => descCount.textContent = descInput.value.length);
}

// Search & filter
const searchInput  = document.getElementById('teamSearch');
const statusFilter = document.getElementById('statusFilter');
const cards        = document.querySelectorAll('.ot-team-card');
const pageInfo     = document.getElementById('pageInfo');

function filterTeams() {
    const q      = (searchInput?.value || '').toLowerCase();
    const status = statusFilter?.value || '';
    let visible  = 0;
    cards.forEach(card => {
        const nameMatch   = card.dataset.name.includes(q);
        const statusMatch = !status || card.dataset.status === status;
        const show        = nameMatch && statusMatch;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    if (pageInfo) pageInfo.textContent = `Showing 1 to ${visible} of ${cards.length} teams`;
}
searchInput?.addEventListener('input', filterTeams);
statusFilter?.addEventListener('change', filterTeams);

// Team members keyed by team id — injected from PHP
const teamMembersMap = <?= json_encode(array_map(fn($tid) => array_map(fn($m) => ['id' => (int)$m['id'], 'name' => $m['name']], $membersByTeam[$tid] ?? []), array_combine(array_column($teams, 'id'), array_column($teams, 'id'))), JSON_UNESCAPED_UNICODE) ?>;

// Edit Team Modal
function openEditTeam(id, name, code, leaderId, area, isActive, desc) {
    document.getElementById('etId').value     = id;
    document.getElementById('etName').value   = name;
    document.getElementById('etCode').value   = code || '';
    document.getElementById('etArea').value   = area || '';
    document.getElementById('etStatus').value = isActive ? 'active' : 'inactive';
    document.getElementById('etDesc').value   = desc || '';

    // Populate leader dropdown with this team's members only
    const leaderSel = document.getElementById('etLeader');
    const hint      = document.getElementById('etLeaderHint');
    const members   = teamMembersMap[id] || [];
    leaderSel.innerHTML = '<option value="">-- No leader --</option>';
    members.forEach(m => {
        const opt = document.createElement('option');
        opt.value       = m.id;
        opt.textContent = m.name;
        if (m.id === leaderId) opt.selected = true;
        leaderSel.appendChild(opt);
    });
    hint.textContent = members.length === 0 ? 'Add members to the team first to set a leader.' : '';

    new bootstrap.Modal(document.getElementById('editTeamModal')).show();
}

// Edit Member Modal
function openEditMember(id, name, teamId, isActive) {
    document.getElementById('emId').value       = id;
    document.getElementById('emName').value     = name;
    document.getElementById('emTeam').value     = teamId || '';
    document.getElementById('emActive').checked = isActive === 1;
    new bootstrap.Modal(document.getElementById('editMemberModal')).show();
}
</script>
<?php include __DIR__ . '/../layout/footer.php'; ?>
