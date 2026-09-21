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

    if ($action === 'add_member') {
        $name = trim((string)($data['name'] ?? ''));
        $teamId = !empty($data['team_id']) ? (int)$data['team_id'] : null;
        $isActive = isset($data['is_active']) ? 1 : 0;

        if ($name === '') {
            api_error('Member name is required.', 422);
        }

        if ($teamId) {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM offline_sellers WHERE LOWER(name) = LOWER(?) AND team_id = ?");
            $exists->execute([$name, $teamId]);
        } else {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM offline_sellers WHERE LOWER(name) = LOWER(?) AND team_id IS NULL");
            $exists->execute([$name]);
        }
        if ((int)$exists->fetchColumn() > 0) {
            api_error("Member \"$name\" already exists in this team.", 409);
        }

        $stmt = $pdo->prepare("INSERT INTO offline_sellers (team_id, name, is_active) VALUES (?, ?, ?)");
        $stmt->execute([$teamId, $name, $isActive]);
        api_json(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Member added successfully.']);

    } elseif ($action === 'edit_member') {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $teamId = !empty($data['team_id']) ? (int)$data['team_id'] : null;
        $isActive = isset($data['is_active']) ? 1 : 0;

        if ($id <= 0 || $name === '') {
            api_error('Member name is required.', 422);
        }

        $stmt = $pdo->prepare("UPDATE offline_sellers SET name=?, team_id=?, is_active=? WHERE id=?");
        $stmt->execute([$name, $teamId, $isActive, $id]);
        api_json(['success' => true, 'message' => 'Member updated successfully.']);

    } elseif ($action === 'delete_member') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            api_error('Invalid member ID.', 422);
        }
        $pdo->prepare("DELETE FROM offline_sellers WHERE id = ?")->execute([$id]);
        api_json(['success' => true, 'message' => 'Member deleted successfully.']);

    } elseif ($action === 'set_leader') {
        $teamId = (int)($data['team_id'] ?? 0);
        $memberId = (int)($data['member_id'] ?? 0);
        if ($teamId <= 0 || $memberId <= 0) {
            api_error('Invalid team or member ID.', 422);
        }
        $pdo->prepare("UPDATE offline_teams SET leader_id = ? WHERE id = ?")->execute([$memberId, $teamId]);
        api_json(['success' => true, 'message' => 'Leader set successfully.']);

    } elseif ($action === 'remove_leader') {
        $teamId = (int)($data['team_id'] ?? 0);
        if ($teamId <= 0) {
            api_error('Invalid team ID.', 422);
        }
        $pdo->prepare("UPDATE offline_teams SET leader_id = NULL WHERE id = ?")->execute([$teamId]);
        api_json(['success' => true, 'message' => 'Leader removed successfully.']);

    } elseif ($action === 'get_members') {
        $limit = (int)($_GET['limit'] ?? 1000);
        $offset = (int)($_GET['offset'] ?? 0);
        $q = $_GET['q'] ?? '';
        $teamFilter = $_GET['team_id'] ?? '';

        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = "(os.name LIKE ?)";
            $params[] = "%$q%";
        }
        if ($teamFilter !== '') {
            $where[] = "os.team_id = ?";
            $params[] = (int)$teamFilter;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM offline_sellers os $whereSql");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT os.*, t.name AS team_name
            FROM offline_sellers os
            LEFT JOIN offline_teams t ON t.id = os.team_id
            $whereSql
            ORDER BY os.sort_order, os.name
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        api_json(['success' => true, 'rows' => $rows, 'total_rows' => $totalRows]);

    } elseif ($action === 'get_all_sellers') {
        $activeOnly = ($_GET['active_only'] ?? '1') === '1';
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        $stmt = $pdo->query("SELECT os.*, t.name AS team_name FROM offline_sellers os LEFT JOIN offline_teams t ON t.id = os.team_id $where ORDER BY os.name");
        api_json(['success' => true, 'sellers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } else {
        api_error('Unknown action.', 400);
    }
} else {
    api_error('Method not allowed.', 405);
}
