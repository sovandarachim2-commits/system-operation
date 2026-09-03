<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take_reconcile.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT mt.*, COALESCE(mt.take_code, CONCAT('MT-#', mt.id)) as display_code,
           u1.name as created_by_name, u2.name as approved_by_name, u3.name as reconciled_by_name,
           (SELECT COUNT(*) FROM marketing_take_items WHERE marketing_take_id = mt.id) as item_count
    FROM marketing_takes mt
    LEFT JOIN users u1 ON mt.created_by = u1.id
    LEFT JOIN users u2 ON mt.approved_by = u2.id
    LEFT JOIN users u3 ON mt.reconciled_by = u3.id
    WHERE mt.status = 'completed' AND DATE(mt.reconciled_at) BETWEEN ? AND ?
    ORDER BY mt.reconciled_at DESC
");
$stmt->execute([$date_from, $date_to]);
$takes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$current = 'marketing_take_reconcile_history.php';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4 flex-grow-1">
        <div class="mb-4">
            <a href="marketing_take_reconcile.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>
        <h2><i class="bi bi-clock-history me-2"></i>Reconcile History</h2>
        <p class="text-muted">History of reconciled market takes (returned / not returned recorded).</p>

        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-dark text-white">
                <strong>Reconcile History</strong>
                <span class="badge bg-light text-dark ms-2"><?= count($takes) ?> record(s)</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($takes)): ?>
                    <p class="text-muted text-center py-5 mb-0">No reconciled records found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Code</th>
                                    <th>Event</th>
                                    <th>Event Date</th>
                                    <th>Items</th>
                                    <th>Create By</th>
                                    <th>Approve By</th>
                                    <th>Reconciled By</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($takes as $t): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($t['display_code']) ?></code></td>
                                    <td><?= htmlspecialchars($t['event_name']) ?></td>
                                    <td><?= date('M j, Y', strtotime($t['event_date'])) ?></td>
                                    <td><?= (int)$t['item_count'] ?> product(s)</td>
                                    <td><small><?= htmlspecialchars($t['created_by_name'] ?? '-') ?><br><?= !empty($t['created_at']) ? date('M j, Y H:i', strtotime($t['created_at'])) : '-' ?></small></td>
                                    <td><small><?= htmlspecialchars($t['approved_by_name'] ?? '-') ?><br><?= !empty($t['approved_at']) ? date('M j, Y H:i', strtotime($t['approved_at'])) : '-' ?></small></td>
                                    <td><small><?= htmlspecialchars($t['reconciled_by_name'] ?? '-') ?><br><?= !empty($t['reconciled_at']) ? date('M j, Y H:i', strtotime($t['reconciled_at'])) : '-' ?></small></td>
                                    <td>
                                        <a href="marketing_take_detail.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
                                        <a href="marketing_take_reconcile.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <a href="generate_marketing_invoice.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-success" title="Reconcile Invoice" target="_blank"><i class="bi bi-file-earmark-text"></i></a>
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
