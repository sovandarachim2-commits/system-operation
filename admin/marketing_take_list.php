<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$userRoles = $currentUser ? user_role_names($pdo, $currentUser) : [];
$isAdmin = in_array('admin', $userRoles, true);
$canViewAllMarkets = $isAdmin || (function_exists('has_permission') && has_permission('marketing_take_view_all.view'));

$status_filter = $_GET['status_filter'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$params = [$date_from, $date_to];
$sql = "
    SELECT mt.*, COALESCE(mt.take_code, CONCAT('MT-#', mt.id)) as display_code,
           u1.name as created_by_name, u2.name as approved_by_name, u3.name as updated_by_name,
           sl.location_code, sl.location_name
    FROM marketing_takes mt
    LEFT JOIN users u1 ON mt.created_by = u1.id
    LEFT JOIN users u2 ON mt.approved_by = u2.id
    LEFT JOIN users u3 ON mt.updated_by = u3.id
    LEFT JOIN storage_locations sl ON mt.storage_location_id = sl.id
    WHERE DATE(mt.created_at) BETWEEN ? AND ?
";
if (!$canViewAllMarkets && $userId > 0) {
    $sql .= " AND mt.created_by = ?";
    $params[] = $userId;
}
if ($status_filter !== '') {
    $sql .= " AND mt.status = ?";
    $params[] = $status_filter;
}
$sql .= " ORDER BY mt.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$takes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_SESSION['marketing_take_flash'])) {
    $flash = $_SESSION['marketing_take_flash'];
    unset($_SESSION['marketing_take_flash']);
}

$current = 'marketing_take_list.php';
require_once __DIR__ . '/../layout/header.php';
?>
<style>
/* Mobile-first responsive */
@media (max-width: 767.98px) {
    .mt-list .btn-action { min-width: 44px; min-height: 44px; }
}
</style>

<div class="d-flex flex-column min-vh-100 mt-list">
    <div class="container-fluid py-3 py-md-4 flex-grow-1">
        <div class="row">
            <div class="col-12">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2 mb-3 mb-md-4">
                    <h2 class="h5 mb-0"><i class="bi bi-clock-history me-2"></i>Market Take History<?php if (!$canViewAllMarkets): ?> <span class="badge bg-secondary">My Requests</span><?php endif; ?></h2>
                    <a href="marketing_take_create.php" class="btn btn-primary w-100 w-sm-auto">
                        <i class="bi bi-plus-lg me-1"></i>Create Market Take
                    </a>
                </div>

                <p class="text-muted mb-3 mb-md-4 small">History of all market takes. Create requests, approve, and reconcile.</p>

                <?php if (isset($flash)): ?>
                <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-4">
                    <?= htmlspecialchars($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-12 col-sm-6 col-md-2">
                                <label class="form-label">Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                            </div>
                            <div class="col-12 col-sm-6 col-md-2">
                                <label class="form-label">Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                            </div>
                            <div class="col-12 col-sm-6 col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status_filter" class="form-select">
                                    <option value="">All</option>
                                    <option value="pending_approval" <?= $status_filter === 'pending_approval' ? 'selected' : '' ?>>Pending Approval</option>
                                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>In Marketing</option>
                                    <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-md-2">
                                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- List -->
                <div class="card">
                    <div class="card-header bg-dark text-white py-2 py-md-3">
                        <strong><i class="bi bi-clock-history me-2"></i>Market Take History</strong>
                        <span class="badge bg-light text-dark ms-2"><?= count($takes) ?> records</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($takes)): ?>
                            <p class="text-muted text-center py-5 mb-0">No market takes found.</p>
                        <?php else: ?>
                            <!-- Mobile: Card layout -->
                            <div class="d-md-none card-mobile">
                                <?php foreach ($takes as $t):
                                    $badge = 'bg-secondary';
                                    if ($t['status'] === 'pending_approval') $badge = 'bg-warning text-dark';
                                    elseif ($t['status'] === 'approved' || $t['status'] === 'pending') $badge = 'bg-info';
                                    elseif ($t['status'] === 'rejected') $badge = 'bg-danger';
                                    elseif ($t['status'] === 'completed') $badge = 'bg-success';
                                ?>
                                <div class="border-bottom">
                                    <div class="p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <code class="small"><?= htmlspecialchars($t['display_code'] ?? $t['take_code'] ?? 'MT-#'.$t['id']) ?></code>
                                            <span class="badge <?= $badge ?>"><?= $t['status'] === 'pending' ? 'In Marketing' : ucfirst(str_replace('_', ' ', $t['status'])) ?></span>
                                        </div>
                                        <div class="mb-2 fw-medium"><?= htmlspecialchars($t['event_name']) ?></div>
                                        <div class="small text-muted mb-2">
                                            <?= date('M j, Y', strtotime($t['event_date'])) ?> &bull; Create: <?= htmlspecialchars($t['created_by_name'] ?? '-') ?> <?= $t['created_at'] ? date('M j, H:i', strtotime($t['created_at'])) : '' ?>
                                            <?php if (!empty($t['updated_by_name'])): ?> &bull; Update: <?= htmlspecialchars($t['updated_by_name']) ?> <?= !empty($t['updated_at']) ? date('M j, H:i', strtotime($t['updated_at'])) : '' ?><?php endif; ?>
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <?php if ($t['status'] === 'pending_approval'): ?>
                                                <a href="marketing_take_edit.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary btn-action" title="Edit"><i class="bi bi-pencil"></i></a>
                                                <a href="marketing_take_cancel.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-danger btn-action" title="Cancel" onclick="return confirm('Cancel this market take request?');"><i class="bi bi-x-circle"></i></a>
                                            <?php endif; ?>
                                            <a href="marketing_take_detail.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary btn-action" title="View"><i class="bi bi-eye"></i></a>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Desktop: Table -->
                            <div class="d-none d-md-block table-responsive">
                                <table class="table table-striped table-hover mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Code</th>
                                            <th>Event</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Create By</th>
                                            <th>Update By</th>
                                            <th>Approved By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($takes as $t): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars($t['display_code'] ?? $t['take_code'] ?? 'MT-#'.$t['id']) ?></code></td>
                                            <td><?= htmlspecialchars($t['event_name']) ?></td>
                                            <td><?= date('M j, Y', strtotime($t['event_date'])) ?></td>
                                            <td>
                                                <?php
                                                $badge = 'bg-secondary';
                                                if ($t['status'] === 'pending_approval') $badge = 'bg-warning text-dark';
                                                elseif ($t['status'] === 'approved' || $t['status'] === 'pending') $badge = 'bg-info';
                                                elseif ($t['status'] === 'rejected') $badge = 'bg-danger';
                                                elseif ($t['status'] === 'completed') $badge = 'bg-success';
                                                ?>
                                                <span class="badge <?= $badge ?>"><?= $t['status'] === 'pending' ? 'In Marketing' : ucfirst(str_replace('_', ' ', $t['status'])) ?></span>
                                            </td>
                                            <td><small><?= htmlspecialchars($t['created_by_name'] ?? '-') ?><br><?= $t['created_at'] ? date('M j, Y H:i', strtotime($t['created_at'])) : '-' ?></small></td>
                                            <td><small><?= !empty($t['updated_by_name']) ? htmlspecialchars($t['updated_by_name']) . '<br>' . (!empty($t['updated_at']) ? date('M j, Y H:i', strtotime($t['updated_at'])) : '-') : '-' ?></small></td>
                                            <td><?= htmlspecialchars($t['approved_by_name'] ?? '-') ?></td>
                                            <td class="text-nowrap">
                                                <?php if ($t['status'] === 'pending_approval'): ?>
                                                    <a href="marketing_take_edit.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                                    <a href="marketing_take_cancel.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-danger" title="Cancel" onclick="return confirm('Cancel this market take request?');"><i class="bi bi-x-circle"></i></a>
                                                <?php endif; ?>
                                                <a href="marketing_take_detail.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
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
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
