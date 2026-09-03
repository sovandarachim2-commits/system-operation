<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'storage_locations.view');

$pdo = get_db_connection();

$errors = [];
$success = '';

// Add default column if it doesn't exist
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM storage_locations LIKE 'is_default'");
    $exists = $stmt->fetch();

    if (!$exists) {
        $pdo->exec("ALTER TABLE storage_locations ADD COLUMN is_default BOOLEAN DEFAULT FALSE");
        $success = "Database updated: Added default storage location functionality.";

        // Set first warehouse as default if none exists
        $stmt = $pdo->query("SELECT id FROM storage_locations WHERE is_active = 1 AND location_type = 'warehouse' ORDER BY created_at ASC LIMIT 1");
        $firstWarehouse = $stmt->fetch();
        if ($firstWarehouse) {
            $pdo->prepare("UPDATE storage_locations SET is_default = TRUE WHERE id = ?")->execute([$firstWarehouse['id']]);
        }
    }
} catch (PDOException $e) {
    $errors[] = "Database error: " . htmlspecialchars($e->getMessage());
}

// Add offline location flag if it doesn't exist
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM storage_locations LIKE 'is_offline_location'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE storage_locations ADD COLUMN is_offline_location TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default");
        $pdo->exec("ALTER TABLE storage_locations ADD INDEX idx_offline_location (is_offline_location)");
    }
} catch (PDOException $e) {
    $errors[] = "Database error: " . htmlspecialchars($e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'set_default') {
        require_role_or_permission(['admin'], 'storage_locations.update');
        $location_id = (int)($_POST['location_id'] ?? 0);

        if ($location_id > 0) {
            try {
                // Remove current default
                $pdo->exec("UPDATE storage_locations SET is_default = FALSE");
                // Set new default
                $stmt = $pdo->prepare("UPDATE storage_locations SET is_default = TRUE WHERE id = ?");
                $stmt->execute([$location_id]);
                $success = "Default storage location updated successfully.";
            } catch (PDOException $e) {
                $errors[] = "Failed to update default location: " . htmlspecialchars($e->getMessage());
            }
        } else {
            $errors[] = "Invalid location ID.";
        }
    }

    if ($action === 'set_offline') {
        require_role_or_permission(['admin'], 'storage_locations.update');
        $location_id = (int)($_POST['location_id'] ?? 0);

        if ($location_id > 0) {
            try {
                $pdo->beginTransaction();
                $pdo->exec("UPDATE storage_locations SET is_offline_location = 0");
                $stmt = $pdo->prepare("UPDATE storage_locations SET is_offline_location = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$location_id]);
                $pdo->commit();
                $success = "Offline default location updated successfully.";
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "Failed to update offline location: " . htmlspecialchars($e->getMessage());
            }
        } else {
            $errors[] = "Invalid location ID.";
        }
    }
    
    if ($action === 'assign_team_location') {
        require_role_or_permission(['admin'], 'storage_locations.update');
        $location_id = (int)($_POST['location_id'] ?? 0);
        $team_id     = (int)($_POST['team_id'] ?? 0);

        if ($location_id > 0) {
            try {
                $pdo->beginTransaction();
                if ($team_id > 0) {
                    $pdo->prepare("UPDATE storage_locations SET is_offline_location = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$location_id]);
                    $pdo->prepare("UPDATE offline_teams SET location_id = NULL WHERE location_id = ? AND id <> ?")->execute([$location_id, $team_id]);
                    $pdo->prepare("UPDATE offline_teams SET location_id = ? WHERE id = ?")->execute([$location_id, $team_id]);
                    $tNameStmt = $pdo->prepare("SELECT name FROM offline_teams WHERE id = ?");
                    $tNameStmt->execute([$team_id]);
                    $success = 'Location assigned to team "' . htmlspecialchars((string)$tNameStmt->fetchColumn()) . '".';
                } else {
                    $pdo->prepare("UPDATE offline_teams SET location_id = NULL WHERE location_id = ?")->execute([$location_id]);
                    $pdo->prepare("UPDATE storage_locations SET is_offline_location = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$location_id]);
                    $success = 'Team assignment removed.';
                }
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Failed to assign team: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    if ($action === 'add_location') {
        $location_code = trim($_POST['location_code'] ?? '');
        $location_name = trim($_POST['location_name'] ?? '');
        $location_type = $_POST['location_type'] ?? 'warehouse';
        $description = trim($_POST['description'] ?? '');
        $capacity = (float)($_POST['capacity'] ?? 0);

        if (empty($location_code) || empty($location_name)) {
            $errors[] = 'Location code and name are required.';
        } elseif ($capacity <= 0) {
            $errors[] = 'Capacity is required and must be greater than 0.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO storage_locations (location_code, location_name, location_type, description, capacity, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                $user = current_user();
                $stmt->execute([$location_code, $location_name, $location_type, $description, $capacity, $user['id']]);
                $pdo->commit();
                $success = "Storage location '$location_code' added successfully.";
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->errorInfo[1] == 1062) {
                    $errors[] = "Location code '$location_code' already exists.";
                } else {
                    $errors[] = 'Failed to add location: ' . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
    
    if ($action === 'edit_location') {
        require_role_or_permission(['admin'], 'storage_locations.update');
        $id = (int)($_POST['id'] ?? 0);
        $location_code = trim($_POST['location_code'] ?? '');
        $location_name = trim($_POST['location_name'] ?? '');
        $location_type = $_POST['location_type'] ?? 'warehouse';
        $description = trim($_POST['description'] ?? '');
        $capacity = (float)($_POST['capacity'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($id <= 0 || empty($location_code) || empty($location_name)) {
            $errors[] = 'Location ID, code and name are required.';
        } elseif ($capacity <= 0) {
            $errors[] = 'Capacity is required and must be greater than 0.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE storage_locations SET location_code = ?, location_name = ?, location_type = ?, description = ?, capacity = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $stmt->execute([$location_code, $location_name, $location_type, $description, $capacity, $is_active, $id]);
                $pdo->commit();
                $success = "Storage location updated successfully.";
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->errorInfo[1] == 1062) {
                    $errors[] = "Location code '$location_code' already exists.";
                } else {
                    $errors[] = 'Failed to update location: ' . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
    
    if ($action === 'delete_location') {
        require_role_or_permission(['admin'], 'storage_locations.delete');
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            $errors[] = 'Location ID is required.';
        } else {
            try {
                // Check if location has inventory
                $checkStmt = $pdo->prepare('SELECT COUNT(*) as count FROM current_inventory WHERE storage_location_id = ?');
                $checkStmt->execute([$id]);
                $count = $checkStmt->fetch()['count'];
                
                if ($count > 0) {
                    $errors[] = "Cannot delete location. It contains $count inventory items.";
                } else {
                    $stmt = $pdo->prepare('DELETE FROM storage_locations WHERE id = ?');
                    $stmt->execute([$id]);
                    $success = "Storage location deleted successfully.";
                }
            } catch (PDOException $e) {
                $errors[] = 'Failed to delete location: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Get all storage locations
try {
    $stmt = $pdo->query('SELECT sl.*, COUNT(ci.id) as item_count FROM storage_locations sl LEFT JOIN current_inventory ci ON sl.id = ci.storage_location_id GROUP BY sl.id ORDER BY sl.location_code');
    $locations = $stmt->fetchAll();
} catch (PDOException $e) {
    $locations = [];
    $errors[] = 'Storage locations table not found. Please run storage setup script first.';
}

// Load offline teams for the Offline Team column
$offlineTeams    = [];
$teamByLocation  = []; // locationId → teamId
try {
    $offlineTeams = $pdo->query("SELECT id, name FROM offline_teams WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pdo->query("SELECT id, location_id FROM offline_teams WHERE location_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $teamByLocation[(int)$t['location_id']] = (int)$t['id'];
    }
} catch (Throwable $e) {
    // offline_teams table may not exist yet
}

include __DIR__ . '/../layout/header.php';
?>
<style>
/* Pill button */
.team-assign-btn {
    border-radius: 20px;
    font-size: .78rem;
    padding: 3px 10px 3px 8px;
    font-weight: 600;
    white-space: nowrap;
    box-shadow: none !important;
    transition: all .15s;
    border-width: 1.5px;
}
.team-assign-btn.assigned  { background:#e3f2fd; border-color:#90caf9; color:#1565c0; }
.team-assign-btn.unassigned{ background:#f5f5f5; border-color:#e0e0e0; color:#9e9e9e; }
.team-assign-btn.assigned:hover  { background:#bbdefb; border-color:#64b5f6; color:#0d47a1; }
.team-assign-btn.unassigned:hover{ background:#e9e9e9; color:#616161; }

.team-select {
    border-radius: 20px;
    font-size: .8rem;
    font-weight: 600;
    padding: 4px 8px;
    border: 1.5px solid #90caf9;
    color: #1565c0;
    background-color: #e3f2fd;
    cursor: pointer;
    min-width: 130px;
    box-shadow: none;
}
.team-select:focus { outline: none; box-shadow: 0 0 0 .2rem rgba(21,101,192,.2); }
.team-select.unassigned { border-color: #e0e0e0; color: #9e9e9e; background-color: #f5f5f5; }
</style>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Storage Locations</h1>
        <div class="d-flex gap-2">
            <a href="storage_receipts.php" class="btn btn-outline-success btn-lg">
                <i class="bi bi-archive me-2"></i>Storage Receipts
            </a>
            <a href="inventory_view.php" class="btn btn-outline-info btn-lg">
                <i class="bi bi-box-seam me-2"></i>View Inventory
            </a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <!-- Add Location Form -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Add New Storage Location</h5>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="add_location">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Location Code *</label>
                        <input type="text" name="location_code" class="form-control" placeholder="e.g., WH-A" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Location Name *</label>
                        <input type="text" name="location_name" class="form-control" placeholder="e.g., Main Warehouse A" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Type</label>
                        <select name="location_type" class="form-select">
                            <option value="warehouse">Warehouse</option>
                            <option value="storage_room">Storage Room</option>
                            <option value="shelf">Shelf</option>
                            <option value="cabinet">Cabinet</option>
                            <option value="outdoor">Outdoor</option>
                            <option value="virtual">Virtual</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Capacity *</label>
                        <input type="number" step="0.01" name="capacity" class="form-control" placeholder="0.00" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" placeholder="Purpose or details">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-2"></i>Add Location
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Locations -->
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0">Existing Storage Locations</h5>
        </div>
        <div class="card-body">
            <?php if (empty($locations)): ?>
                <div class="alert alert-info">
                    No storage locations found. Please run the storage setup script or add locations above.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Capacity</th>
                                <th>Usage</th>
                                <th>Items</th>
                                <th>Status</th>
                                <th>Default</th>
                                <th>Offline Team</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($locations as $location): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($location['location_code']) ?></strong></td>
                                    <td><?= htmlspecialchars($location['location_name']) ?></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?= ucfirst(str_replace('_', ' ', $location['location_type'])) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($location['description'] ?? '') ?></td>
                                    <td><?= $location['capacity'] ? number_format($location['capacity']) : 'N/A' ?></td>
                                    <td>
                                        <?php if ($location['capacity'] > 0): ?>
                                            <?php 
                                            $usage = $location['current_usage'] ?? 0;
                                            $percentage = ($usage / $location['capacity']) * 100;
                                            $color = $percentage > 80 ? 'danger' : ($percentage > 60 ? 'warning' : 'success');
                                            ?>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-<?= $color ?>" style="width: <?= min($percentage, 100) ?>%">
                                                    <?= number_format($percentage, 1) ?>%
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?= (int)$location['item_count'] ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $location['is_active'] ? 'success' : 'secondary' ?>">
                                            <?= $location['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($location['is_default']): ?>
                                            <span class="badge bg-primary">
                                                <i class="bi bi-star-fill me-1"></i>Default
                                            </span>
                                        <?php else: ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="set_default">
                                                <input type="hidden" name="location_id" value="<?= $location['id'] ?>">
                                                <button type="submit" class="btn btn-outline-warning btn-sm" onclick="return confirm('Set this as the default storage location?')">
                                                    <i class="bi bi-star me-1"></i>Set Default
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $assignedId   = $teamByLocation[(int)$location['id']] ?? 0;
                                        $assignedName = '';
                                        foreach ($offlineTeams as $ot) {
                                            if ((int)$ot['id'] === $assignedId) { $assignedName = $ot['name']; break; }
                                        }
                                        $locId   = (int)$location['id'];
                                        $locName = htmlspecialchars(json_encode($location['location_name']));
                                        ?>
                                        <?php if (!empty($offlineTeams)): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="assign_team_location">
                                            <input type="hidden" name="location_id" value="<?= $locId ?>">
                                            <select name="team_id"
                                                class="team-select <?= $assignedId ? '' : 'unassigned' ?>"
                                                data-location="<?= htmlspecialchars($location['location_name']) ?>"
                                                onchange="confirmTeamAssign(this)">
                                                <option value="0">-- None --</option>
                                                <?php foreach ($offlineTeams as $ot): ?>
                                                    <option value="<?= (int)$ot['id'] ?>" <?= $assignedId === (int)$ot['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($ot['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                        <?php else: ?>
                                            <span class="text-muted small">No teams</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button class="btn btn-outline-primary btn-sm" onclick="editLocation(<?= $location['id'] ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-outline-danger btn-sm" onclick="deleteLocation(<?= $location['id'] ?>, '<?= htmlspecialchars($location['location_code']) ?>')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- Edit Location Modal -->
<div class="modal fade" id="editLocationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="edit_location">
                <input type="hidden" name="id" id="editLocationId">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Storage Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Location Code *</label>
                        <input type="text" name="location_code" id="editLocationCode" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Location Name *</label>
                        <input type="text" name="location_name" id="editLocationName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="location_type" id="editLocationType" class="form-select">
                            <option value="warehouse">Warehouse</option>
                            <option value="storage_room">Storage Room</option>
                            <option value="shelf">Shelf</option>
                            <option value="cabinet">Cabinet</option>
                            <option value="outdoor">Outdoor</option>
                            <option value="virtual">Virtual</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Capacity *</label>
                        <input type="number" step="0.01" name="capacity" id="editLocationCapacity" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" id="editLocationDescription" class="form-control">
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" id="editLocationActive" class="form-check-input" checked>
                            <label class="form-check-label" for="editLocationActive">
                                Active
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Location</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Location Modal -->
<div class="modal fade" id="deleteLocationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="delete_location">
                <input type="hidden" name="id" id="deleteLocationId">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Storage Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete storage location <strong id="deleteLocationCode"></strong>?</p>
                    <p class="text-danger">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Location</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editLocation(id) {
    // Load location data via AJAX
    fetch(`get_storage_location.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editLocationId').value = data.location.id;
                document.getElementById('editLocationCode').value = data.location.location_code;
                document.getElementById('editLocationName').value = data.location.location_name;
                document.getElementById('editLocationType').value = data.location.location_type;
                document.getElementById('editLocationCapacity').value = data.location.capacity || '';
                document.getElementById('editLocationDescription').value = data.location.description || '';
                document.getElementById('editLocationActive').checked = !!Number(data.location.is_active);
                
                const modal = new bootstrap.Modal(document.getElementById('editLocationModal'));
                modal.show();
            } else {
                alert('Error loading location: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading location');
        });
}

function deleteLocation(id, code) {
    document.getElementById('deleteLocationId').value = id;
    document.getElementById('deleteLocationCode').textContent = code;

    const modal = new bootstrap.Modal(document.getElementById('deleteLocationModal'));
    modal.show();
}

/* ── Team select confirmation ────────────────────────── */
function confirmTeamAssign(select) {
    const locationName = select.dataset.location;
    const teamName     = select.options[select.selectedIndex].text;
    const isRemoving   = select.value === '0';

    const msg = isRemoving
        ? `Remove team assignment from "${locationName}"?`
        : `Assign "${teamName}" to "${locationName}"?`;

    if (confirm(msg)) {
        select.closest('form').submit();
    } else {
        select.value = select.defaultValue;
    }
}
</script>


<?php include __DIR__ . '/../layout/footer.php'; ?>
