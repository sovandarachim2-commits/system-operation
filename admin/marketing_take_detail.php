<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take.view', 'marketing_take_report.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: marketing_take_list.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT mt.*, u1.name as created_by_name, u2.name as approved_by_name,
           sl.location_code, sl.location_name
    FROM marketing_takes mt
    LEFT JOIN users u1 ON mt.created_by = u1.id
    LEFT JOIN users u2 ON mt.approved_by = u2.id
    LEFT JOIN storage_locations sl ON mt.storage_location_id = sl.id
    WHERE mt.id = ?
");
$stmt->execute([$id]);
$take = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$take) {
    header('Location: marketing_take_list.php');
    exit;
}
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$userRoles = $currentUser ? user_role_names($pdo, $currentUser) : [];
$isAdmin = in_array('admin', $userRoles, true);
$canViewAllMarkets = $isAdmin || (function_exists('has_permission') && has_permission('marketing_take_view_all.view'));
if (!$canViewAllMarkets && $userId > 0 && (int)$take['created_by'] !== $userId) {
    header('Location: marketing_take_list.php');
    exit;
}

$items = $pdo->prepare("
    SELECT mti.*, p.name as product_name
    FROM marketing_take_items mti
    JOIN products p ON mti.product_id = p.id
    WHERE mti.marketing_take_id = ?
    ORDER BY p.name
");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$current = 'marketing_take_detail.php';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4 flex-grow-1">
        <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <a href="marketing_take_list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <?php if (in_array($take['status'], ['approved', 'pending', 'completed'], true)): ?>
            <a href="generate_marketing_invoice.php?id=<?= (int)$id ?>" class="btn btn-primary" target="_blank"><i class="bi bi-file-earmark-text me-1"></i>Generate Invoice</a>
            <?php endif; ?>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <strong><?= htmlspecialchars($take['take_code'] ?? 'MT-#'.$id) ?>: <?= htmlspecialchars($take['event_name']) ?></strong>
                <?php
                $badge = 'bg-secondary';
                if ($take['status'] === 'pending_approval') $badge = 'bg-warning text-dark';
                elseif ($take['status'] === 'approved' || $take['status'] === 'pending') $badge = 'bg-info';
                elseif ($take['status'] === 'rejected') $badge = 'bg-danger';
                elseif ($take['status'] === 'completed') $badge = 'bg-success';
                ?>
                <span class="badge <?= $badge ?>"><?= $take['status'] === 'pending' ? 'In Marketing' : ucfirst(str_replace('_', ' ', $take['status'])) ?></span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Code:</strong> <code><?= htmlspecialchars($take['take_code'] ?? 'MT-#'.$id) ?></code></p>
                        <p><strong>Event Date:</strong> <?= date('M j, Y', strtotime($take['event_date'])) ?></p>
                        <p><strong>Location:</strong> <?= htmlspecialchars($take['location'] ?: '-') ?></p>
                        <p><strong>Storage:</strong> <?= htmlspecialchars(($take['location_code'] ?? '') . ' ' . ($take['location_name'] ?? '-')) ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Created By:</strong> <?= htmlspecialchars($take['created_by_name'] ?? '-') ?></p>
                        <p><strong>Approved By:</strong> <?= htmlspecialchars($take['approved_by_name'] ?? '-') ?></p>
                        <p><strong>Approved At:</strong> <?= $take['approved_at'] ? date('M j, Y H:i', strtotime($take['approved_at'])) : '-' ?></p>
                        <p><strong>Reconciled At:</strong> <?= $take['reconciled_at'] ? date('M j, Y H:i', strtotime($take['reconciled_at'])) : '-' ?></p>
                    </div>
                </div>
                <?php if (!empty($take['notes'])): ?>
                <p><strong>Notes:</strong> <?= nl2br(htmlspecialchars($take['notes'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($take['approve_note'])): ?>
                <p><strong>Approval Note:</strong> <?= nl2br(htmlspecialchars($take['approve_note'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($take['reject_reason'])): ?>
                <p><strong>Rejection Reason:</strong> <span class="text-danger"><?= nl2br(htmlspecialchars($take['reject_reason'])) ?></span></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Products</div>
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th class="text-end">Qty Taken</th>
                            <th class="text-end">Qty Returned</th>
                            <th class="text-end">Qty Not Returned</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars($it['product_name']) ?></td>
                            <td class="text-end"><?= number_format($it['quantity_taken'], 2) ?></td>
                            <td class="text-end text-success"><?= number_format($it['quantity_returned'], 2) ?></td>
                            <td class="text-end text-danger"><?= number_format($it['quantity_not_returned'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
