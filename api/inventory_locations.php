<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_role_or_permission(
    ['admin'],
    'storage_locations.view',
    'storage_locations.update',
    'storage_locations.delete',
    'sr_inventory_onhand.view',
    'sr_inventory_movements.view',
    'sr_inventory_adjustment.view',
    'sr_inventory_transfer.view',
    'sr_inventory_closing.view'
);

$pdo = get_db_connection();

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM storage_locations LIKE 'is_default'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE storage_locations ADD COLUMN is_default BOOLEAN DEFAULT FALSE");
        $first = $pdo->query("SELECT id FROM storage_locations WHERE is_active = 1 AND location_type = 'warehouse' ORDER BY created_at ASC LIMIT 1")->fetch();
        if ($first) {
            $pdo->prepare("UPDATE storage_locations SET is_default = TRUE WHERE id = ?")->execute([$first['id']]);
        }
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM storage_locations LIKE 'is_offline_location'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE storage_locations ADD COLUMN is_offline_location TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default");
        $pdo->exec("ALTER TABLE storage_locations ADD INDEX idx_offline_location (is_offline_location)");
    }
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = $_GET['action'] ?? $data['action'] ?? '';

        if ($action === 'get_locations') {
            $limit = (int)($_GET['limit'] ?? 1000);
            $offset = (int)($_GET['offset'] ?? 0);
            $q = $_GET['q'] ?? '';
            $typeFilter = $_GET['type'] ?? '';
            $activeFilter = $_GET['active'] ?? '';

            $where = [];
            $params = [];
            if ($q !== '') {
                $where[] = "(location_code LIKE :q1 OR location_name LIKE :q2 OR description LIKE :q3)";
                $params[':q1'] = "%$q%";
                $params[':q2'] = "%$q%";
                $params[':q3'] = "%$q%";
            }
            if ($typeFilter !== '' && $typeFilter !== 'all') {
                $where[] = "location_type = :ltype";
                $params[':ltype'] = $typeFilter;
            }
            if ($activeFilter !== '' && $activeFilter !== 'all') {
                $where[] = "is_active = :active";
                $params[':active'] = $activeFilter === 'active' ? 1 : 0;
            }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM storage_locations $whereSql");
            foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
            $countStmt->execute();
            $totalRows = (int)$countStmt->fetchColumn();

            $sql = "
                SELECT *
                FROM storage_locations
                $whereSql
                ORDER BY is_default DESC, location_code
                LIMIT :limit OFFSET :offset
            ";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $itemCountByLoc = [];
            try {
                $itemRows = $pdo->query("SELECT storage_location_id, COUNT(*) as cnt FROM current_inventory WHERE storage_location_id IS NOT NULL GROUP BY storage_location_id")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($itemRows as $ir) {
                    $itemCountByLoc[(int)$ir['storage_location_id']] = (int)$ir['cnt'];
                }
            } catch (Throwable $e) {
            }
            foreach ($rows as &$r) {
                $lid = (int)($r['id'] ?? 0);
                $r['item_count'] = $itemCountByLoc[$lid] ?? 0;
                if (!isset($r['current_usage']) || $r['current_usage'] === null) $r['current_usage'] = 0;
            }
            unset($r);

            $offlineTeams = [];
            $teamByLocation = [];
            try {
                $offlineTeams = $pdo->query("SELECT id, name FROM offline_teams WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($pdo->query("SELECT id, location_id FROM offline_teams WHERE location_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $t) {
                    $teamByLocation[(int)$t['location_id']] = (int)$t['id'];
                }
            } catch (Throwable $e) {
            }

            $typeOptions = ['warehouse', 'storage_room', 'shelf', 'cabinet', 'outdoor', 'virtual'];

            api_json([
                'success' => true,
                'rows' => $rows,
                'total_rows' => $totalRows,
                'offline_teams' => $offlineTeams,
                'team_by_location' => $teamByLocation,
                'type_options' => $typeOptions,
            ]);

        } elseif ($action === 'get_options') {
            $activeOnly = ($_GET['active_only'] ?? '1') === '1';
            $where = $activeOnly ? 'WHERE is_active = 1' : '';
            $stmt = $pdo->query("SELECT id, location_code, location_name, location_type FROM storage_locations $where ORDER BY is_default DESC, location_code");
            api_json(['success' => true, 'locations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        } elseif ($action === 'add_location') {
            $code = trim((string)($data['location_code'] ?? ''));
            $name = trim((string)($data['location_name'] ?? ''));
            $type = trim((string)($data['location_type'] ?? 'warehouse'));
            $desc = trim((string)($data['description'] ?? ''));
            $capacity = (float)($data['capacity'] ?? 0);
            $isActive = ($data['is_active'] ?? 'active') === 'active' ? 1 : 0;

            if ($code === '' || $name === '') {
                api_error('Location code and name are required.', 422);
            }
            if ($capacity <= 0) {
                api_error('Capacity is required and must be greater than 0.', 422);
            }

            $exists = $pdo->prepare("SELECT COUNT(*) FROM storage_locations WHERE LOWER(location_code) = LOWER(?)");
            $exists->execute([$code]);
            if ((int)$exists->fetchColumn() > 0) {
                api_error("Location code \"$code\" already exists.", 409);
            }

            $user = current_user();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO storage_locations (location_code, location_name, location_type, description, capacity, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$code, $name, $type, $desc ?: null, $capacity, $isActive, (int)$user['id']]);
                $newId = (int)$pdo->lastInsertId();

                $defCheck = $pdo->query("SELECT COUNT(*) FROM storage_locations WHERE is_default = 1")->fetchColumn();
                if ((int)$defCheck === 0 && $type === 'warehouse') {
                    $pdo->prepare("UPDATE storage_locations SET is_default = TRUE WHERE id = ?")->execute([$newId]);
                }
                $pdo->commit();
                api_json(['success' => true, 'id' => $newId, 'message' => "Location '$code' added successfully."]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    api_error("Location code '$code' already exists.", 409);
                }
                throw $e;
            }

        } elseif ($action === 'edit_location') {
            $id = (int)($data['id'] ?? 0);
            $code = trim((string)($data['location_code'] ?? ''));
            $name = trim((string)($data['location_name'] ?? ''));
            $type = trim((string)($data['location_type'] ?? 'warehouse'));
            $desc = trim((string)($data['description'] ?? ''));
            $capacity = (float)($data['capacity'] ?? 0);
            $isActive = ($data['is_active'] ?? 'active') === 'active' ? 1 : 0;

            if ($id <= 0 || $code === '' || $name === '') {
                api_error('Location ID, code and name are required.', 422);
            }
            if ($capacity <= 0) {
                api_error('Capacity is required and must be greater than 0.', 422);
            }

            $exists = $pdo->prepare("SELECT COUNT(*) FROM storage_locations WHERE LOWER(location_code) = LOWER(?) AND id <> ?");
            $exists->execute([$code, $id]);
            if ((int)$exists->fetchColumn() > 0) {
                api_error("Location code \"$code\" already exists.", 409);
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE storage_locations SET location_code=?, location_name=?, location_type=?, description=?, capacity=?, is_active=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
                $stmt->execute([$code, $name, $type, $desc ?: null, $capacity, $isActive, $id]);
                $pdo->commit();
                api_json(['success' => true, 'message' => 'Location updated successfully.']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    api_error("Location code '$code' already exists.", 409);
                }
                throw $e;
            }

        } elseif ($action === 'delete_location') {
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) {
                api_error('Invalid location ID.', 422);
            }

            $check = $pdo->prepare("SELECT COUNT(*) FROM current_inventory WHERE storage_location_id = ?");
            $check->execute([$id]);
            $count = (int)$check->fetchColumn();
            if ($count > 0) {
                api_error("Cannot delete location. It contains $count inventory items.", 409);
            }

            $pdo->prepare("DELETE FROM storage_locations WHERE id = ?")->execute([$id]);
            api_json(['success' => true, 'message' => 'Storage location deleted.']);

        } elseif ($action === 'set_default') {
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) {
                api_error('Invalid location ID.', 422);
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec("UPDATE storage_locations SET is_default = FALSE");
                $pdo->prepare("UPDATE storage_locations SET is_default = TRUE, updated_at=CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                $pdo->commit();
                api_json(['success' => true, 'message' => 'Default location updated successfully.']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

        } elseif ($action === 'assign_team_location') {
            $id = (int)($data['id'] ?? 0);
            $teamId = (int)($data['team_id'] ?? 0);
            if ($id <= 0) {
                api_error('Invalid location ID.', 422);
            }

            $pdo->beginTransaction();
            try {
                if ($teamId > 0) {
                    $pdo->prepare("UPDATE storage_locations SET is_offline_location = 1, updated_at=CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                    $pdo->prepare("UPDATE offline_teams SET location_id = NULL WHERE location_id = ? AND id <> ?")->execute([$id, $teamId]);
                    $pdo->prepare("UPDATE offline_teams SET location_id = ? WHERE id = ?")->execute([$id, $teamId]);
                    $tName = $pdo->prepare("SELECT name FROM offline_teams WHERE id = ?");
                    $tName->execute([$teamId]);
                    $n = (string)$tName->fetchColumn();
                    $pdo->commit();
                    api_json(['success' => true, 'message' => "Location assigned to team \"$n\"."]);
                } else {
                    $pdo->prepare("UPDATE offline_teams SET location_id = NULL WHERE location_id = ?")->execute([$id]);
                    $pdo->prepare("UPDATE storage_locations SET is_offline_location = 0, updated_at=CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                    $pdo->commit();
                    api_json(['success' => true, 'message' => 'Team assignment removed.']);
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

        } else {
            api_error('Unknown action.', 400);
        }
    } catch (Throwable $e) {
        api_error('Server error: ' . $e->getMessage(), 500);
    }
} else {
    api_error('Method not allowed.', 405);
}
