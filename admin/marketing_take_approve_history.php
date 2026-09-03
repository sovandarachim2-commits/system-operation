<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take_approve.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

$status_filter = $_GET['status_filter'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$params = [$date_from, $date_to];
$sql = "
    SELECT mt.*, COALESCE(mt.take_code, CONCAT('MT-#', mt.id)) as display_code,
           u1.name as created_by_name, u2.name as approved_by_name
    FROM marketing_takes mt
    LEFT JOIN users u1 ON mt.created_by = u1.id
    LEFT JOIN users u2 ON mt.approved_by = u2.id
    WHERE DATE(mt.approved_at) BETWEEN ? AND ? AND mt.approved_at IS NOT NULL
";
if ($status_filter !== '') {
    $sql .= " AND mt.status = ?";
    $params[] = $status_filter;
}
$sql .= " ORDER BY mt.approved_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$takes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$current = 'marketing_take_approve_history.php';
require_once __DIR__ . '/../layout/header.php';
?>
<style>
@media (max-width: 767.98px) {
    .mt-history .btn-action { min-width: 44px; min-height: 44px; }
}
</style>

<div class="d-flex flex-column min-vh-100 mt-history">
    <div class="container-fluid py-3 py-md-4 flex-grow-1">
        <div class="mb-4">
            <a href="marketing_take_approve.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>
        <h2 class="h5 mb-1"><i class="bi bi-clock-history me-2"></i>Approve History</h2>
        <p class="text-muted mb-4 small">History of approved and rejected market takes.</p>

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

        <div class="card">
            <div class="card-header bg-dark text-white">
                <strong>Approve History</strong>
                <span class="badge bg-light text-dark ms-2"><?= count($takes) ?> record(s)</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($takes)): ?>
                    <p class="text-muted text-center py-5 mb-0">No records found.</p>
                <?php else: ?>
                    <!-- Mobile: Card layout -->
                    <div class="d-md-none">
                        <?php foreach ($takes as $t):
                            $badge = 'bg-secondary';
                            if ($t['status'] === 'rejected') $badge = 'bg-danger';
                            elseif ($t['status'] === 'pending') $badge = 'bg-info';
                            elseif ($t['status'] === 'completed') $badge = 'bg-success';
                            else $badge = 'bg-info';
                            $note = ($t['status'] === 'rejected' ? ($t['reject_reason'] ?? '') : ($t['approve_note'] ?? ''));
                        ?>
                        <div class="border-bottom">
                            <div class="p-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <code class="small"><?= htmlspecialchars($t['display_code']) ?></code>
                                    <span class="badge <?= $badge ?>"><?= $t['status'] === 'pending' ? 'In Marketing' : ucfirst(str_replace('_', ' ', $t['status'])) ?></span>
                                </div>
                                <div class="mb-2 fw-medium"><?= htmlspecialchars($t['event_name']) ?></div>
                                <div class="small text-muted mb-1">
                                    <?= date('M j, Y', strtotime($t['event_date'])) ?>
                                </div>
                                <div class="small text-muted mb-1">
                                    <strong>Created:</strong> <?= htmlspecialchars($t['created_by_name'] ?? '-') ?> &bull; <?= !empty($t['created_at']) ? date('M j, H:i', strtotime($t['created_at'])) : '-' ?>
                                </div>
                                <div class="small text-muted mb-1">
                                    <strong>Approved:</strong> <?= htmlspecialchars($t['approved_by_name'] ?? '-') ?> &bull; <?= date('M j, H:i', strtotime($t['approved_at'])) ?>
                                </div>
                                <?php if ($note): ?>
                                <div class="small text-muted mb-2"><strong>Note:</strong> <?= htmlspecialchars(mb_strlen($note) > 60 ? mb_substr($note, 0, 60) . '…' : $note) ?></div>
                                <?php endif; ?>
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php if ($t['status'] === 'rejected'): ?>
                                    <a href="marketing_take_approve_restore.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-warning btn-action" title="Re-open to approve" onclick="return confirm('Re-open this rejected request?');"><i class="bi bi-pencil"></i></a>
                                    <?php elseif ($t['status'] === 'pending'): ?>
                                    <a href="marketing_take_approve_reverse.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-danger btn-action" title="Reverse to reject"><i class="bi bi-arrow-counterclockwise"></i></a>
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
                                    <th>Note</th>
                                    <th>Created By</th>
                                    <th>Approved By</th>
                                    <th>Approved At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($takes as $t):
                                    $badge = 'bg-secondary';
                                    if ($t['status'] === 'rejected') $badge = 'bg-danger';
                                    elseif ($t['status'] === 'pending') $badge = 'bg-info';
                                    elseif ($t['status'] === 'completed') $badge = 'bg-success';
                                    else $badge = 'bg-info';
                                ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($t['display_code']) ?></code></td>
                                    <td><?= htmlspecialchars($t['event_name']) ?></td>
                                    <td><?= date('M j, Y', strtotime($t['event_date'])) ?></td>
                                    <td><span class="badge <?= $badge ?>"><?= $t['status'] === 'pending' ? 'In Marketing' : ucfirst(str_replace('_', ' ', $t['status'])) ?></span></td>
                                    <?php $note = ($t['status'] === 'rejected' ? ($t['reject_reason'] ?? '') : ($t['approve_note'] ?? '')); ?>
                                    <td class="text-break" style="max-width:200px;" title="<?= htmlspecialchars($note) ?>"><?= $note ? htmlspecialchars(mb_strlen($note) > 50 ? mb_substr($note, 0, 50) . '…' : $note) : '-' ?></td>
                                    <td><small><?= htmlspecialchars($t['created_by_name'] ?? '-') ?><br><?= !empty($t['created_at']) ? date('M j, Y H:i', strtotime($t['created_at'])) : '-' ?></small></td>
                                    <td><?= htmlspecialchars($t['approved_by_name'] ?? '-') ?></td>
                                    <td><?= date('M j, Y H:i', strtotime($t['approved_at'])) ?></td>
                                    <td class="text-nowrap">
                                        <?php if ($t['status'] === 'rejected'): ?>
                                        <a href="marketing_take_approve_restore.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-warning" title="Re-open to approve" onclick="return confirm('Re-open this rejected request?');"><i class="bi bi-pencil"></i></a>
                                        <?php elseif ($t['status'] === 'pending'): ?>
                                        <a href="marketing_take_approve_reverse.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-danger" title="Reverse to reject"><i class="bi bi-arrow-counterclockwise"></i></a>
                                        <?php endif; ?>
                                        <a href="marketing_take_detail.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye me-1"></i>View</a>
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

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
