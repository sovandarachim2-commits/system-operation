<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../admin/offline_lib.php';

require_role_or_permission(['admin'], 'offline_sellers.manage');

$pdo = get_db_connection();
offline_ensure_sellers_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $_GET['action'] ?? $data['action'] ?? '';

    if ($action === 'add_team') {
        $name = trim((string)($data['name'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));
        $areaRoute = trim((string)($data['area_route'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $isActive = ($data['is_active'] ?? 'active') === 'active' ? 1 : 0;
        $leaderId = !empty($data['leader_id']) ? (int)$data['leader_id'] : null;
        $locationId = !empty($data['location_id']) ? (int)$data['location_id'] : null;

        if ($name === '') {
            api_error('Team name is required.', 422);
        }

        $exists = $pdo->prepare("SELECT COUNT(*) FROM offline_teams WHERE LOWER(name) = LOWER(?)");
        $exists->execute([$name]);
        if ((int)$exists->fetchColumn() > 0) {
            api_error("Team name \"$name\" already exists.", 409);
        }

        $stmt = $pdo->prepare("INSERT INTO offline_teams (name, code, leader_id, area_route, description, location_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $code ?: null, $leaderId, $areaRoute ?: null, $description ?: null, $locationId, $isActive]);
        api_json(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Team created successfully.']);

    } elseif ($action === 'edit_team') {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));
        $areaRoute = trim((string)($data['area_route'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $isActive = ($data['is_active'] ?? 'active') === 'active' ? 1 : 0;
        $leaderId = !empty($data['leader_id']) ? (int)$data['leader_id'] : null;
        $locationId = !empty($data['location_id']) ? (int)$data['location_id'] : null;

        if ($id <= 0 || $name === '') {
            api_error('Team name is required.', 422);
        }

        $exists = $pdo->prepare("SELECT COUNT(*) FROM offline_teams WHERE LOWER(name) = LOWER(?) AND id <> ?");
        $exists->execute([$name, $id]);
        if ((int)$exists->fetchColumn() > 0) {
            api_error("Team name \"$name\" already exists.", 409);
        }

        $stmt = $pdo->prepare("UPDATE offline_teams SET name=?, code=?, leader_id=?, area_route=?, description=?, location_id=?, is_active=? WHERE id=?");
        $stmt->execute([$name, $code ?: null, $leaderId, $areaRoute ?: null, $description ?: null, $locationId, $isActive, $id]);
        api_json(['success' => true, 'message' => 'Team updated successfully.']);

    } elseif ($action === 'delete_team') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            api_error('Invalid team ID.', 422);
        }

        $pdo->prepare("UPDATE offline_sellers SET team_id = NULL WHERE team_id = ?")->execute([$id]);
        $pdo->prepare("UPDATE offline_sale_orders SET team_id = NULL WHERE team_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM offline_teams WHERE id = ?")->execute([$id]);
        api_json(['success' => true, 'message' => 'Team deleted. Members moved to Unassigned.']);

    } elseif ($action === 'get_teams') {
        $limit = (int)($_GET['limit'] ?? 1000);
        $offset = (int)($_GET['offset'] ?? 0);
        $q = $_GET['q'] ?? '';
        $activeFilter = $_GET['active'] ?? '';

        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = "(ot.name LIKE ? OR ot.code LIKE ? OR ot.area_route LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        if ($activeFilter !== '') {
            $where[] = "ot.is_active = ?";
            $params[] = $activeFilter === 'active' ? 1 : 0;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM offline_teams ot $whereSql");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT t.*, s.name AS leader_name, sl.location_name AS loc_name, sl.location_code AS loc_code
            FROM offline_teams t
            LEFT JOIN offline_sellers s ON s.id = t.leader_id
            LEFT JOIN storage_locations sl ON sl.id = t.location_id
            $whereSql
            ORDER BY t.name
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        api_json(['success' => true, 'rows' => $rows, 'total_rows' => $totalRows]);

    } elseif ($action === 'get_team_options') {
        $activeOnly = ($_GET['active_only'] ?? '1') === '1';
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        $stmt = $pdo->query("SELECT id, name, code, area_route FROM offline_teams $where ORDER BY name");
        api_json(['success' => true, 'teams' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } else {
        api_error('Unknown action.', 400);
    }
} else {
    api_error('Method not allowed.', 405);
}
