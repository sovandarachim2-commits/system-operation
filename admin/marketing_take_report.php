<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take_report.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$userRoles = $currentUser ? user_role_names($pdo, $currentUser) : [];
$isAdmin = in_array('admin', $userRoles, true);
$canViewAllMarkets = $isAdmin || (function_exists('has_permission') && has_permission('marketing_take_view_all.view'));
$canViewSuggest = $isAdmin || (function_exists('has_permission') && has_permission('marketing_take.view'));

$status_filter = $_GET['status_filter'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$countParams = [$date_from, $date_to];
$countWhere = "DATE(created_at) BETWEEN ? AND ?";
if (!$canViewAllMarkets && $userId > 0) {
    $countWhere .= " AND created_by = ?";
    $countParams[] = $userId;
}
$countStmt = $pdo->prepare("
    SELECT status, COUNT(*) as cnt
    FROM marketing_takes
    WHERE $countWhere
    GROUP BY status
");
$countStmt->execute($countParams);
$statusCounts = [];
while ($row = $countStmt->fetch(PDO::FETCH_ASSOC)) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
}
$statusLabels = [
    'pending_approval' => 'Pending Approval',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'pending' => 'In Marketing',
    'completed' => 'Completed',
];
$statusBadges = [
    'pending_approval' => 'bg-warning text-dark',
    'approved' => 'bg-info',
    'rejected' => 'bg-danger',
    'pending' => 'bg-info',
    'completed' => 'bg-success',
];

$params = [$date_from, $date_to];
$sql = "
    SELECT mt.*, COALESCE(mt.take_code, CONCAT('MT-#', mt.id)) as display_code,
           u1.name as created_by_name, u2.name as approved_by_name, u3.name as reconciled_by_name,
           sl.location_code, sl.location_name
    FROM marketing_takes mt
    LEFT JOIN users u1 ON mt.created_by = u1.id
    LEFT JOIN users u2 ON mt.approved_by = u2.id
    LEFT JOIN users u3 ON mt.reconciled_by = u3.id
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

$itemsByTake = [];
if (!empty($takes)) {
    $takeIds = array_map(function($t) { return (int)$t['id']; }, $takes);
    $placeholders = implode(',', array_fill(0, count($takeIds), '?'));
    $itemsStmt = $pdo->prepare("SELECT mti.*, p.name as product_name FROM marketing_take_items mti JOIN products p ON mti.product_id = p.id WHERE mti.marketing_take_id IN ($placeholders) ORDER BY mti.marketing_take_id, p.name");
    $itemsStmt->execute($takeIds);
    while ($row = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$row['marketing_take_id'];
        if (!isset($itemsByTake[$tid])) $itemsByTake[$tid] = [];
        $itemsByTake[$tid][] = $row;
    }
}

$current = 'marketing_take_report.php';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4 flex-grow-1">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-graph-up me-2"></i>Market Suggest Report<?php if (!$canViewAllMarkets): ?> <span class="badge bg-secondary">My Requests</span><?php endif; ?></h2>
                    <?php if ($canViewSuggest): ?>
                    <a href="marketing_take_list.php" class="btn btn-outline-secondary">
                        <i class="bi bi-list-ul me-1"></i>View History
                    </a>
                    <?php endif; ?>
                </div>

                <p class="text-muted mb-4">Report of market suggest requests with status summary.</p>

                <!-- Filters -->
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
                                <label class="form-label">Status</label>
                                <select name="status_filter" class="form-select">
                                    <option value="">All</option>
                                    <?php foreach ($statusLabels as $st => $label): ?>
                                    <option value="<?= htmlspecialchars($st) ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Status Summary Cards -->
                <div class="row g-3 mb-4">
                    <?php foreach ($statusLabels as $st => $label):
                        $cnt = $statusCounts[$st] ?? 0;
                        $isActive = $status_filter === $st;
                        $badge = $statusBadges[$st] ?? 'bg-secondary';
                        $url = '?date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&status_filter=' . urlencode($st);
                        if ($isActive) {
                            $url = '?date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to);
                        }
                    ?>
                    <div class="col-md col-6">
                        <a href="marketing_take_report.php<?= htmlspecialchars($url) ?>" class="text-decoration-none">
                            <div class="card <?= $isActive ? 'border-primary border-2' : '' ?> h-100">
                                <div class="card-body py-3 text-center">
                                    <span class="badge <?= $badge ?> mb-2"><?= htmlspecialchars($label) ?></span>
                                    <h4 class="mb-0"><?= number_format($cnt) ?></h4>
                                    <small class="text-muted"><?= $isActive ? 'Clear filter' : 'Filter' ?></small>
                                </div>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- List -->
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <strong><i class="bi bi-table me-2"></i>Market Suggest by Status</strong>
                        <span class="badge bg-light text-dark ms-2"><?= count($takes) ?> records</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($takes)): ?>
                            <p class="text-muted text-center py-5 mb-0">No market suggests found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th class="text-center" style="width:40px">No</th>
                                            <th style="width:30px"></th>
                                            <th>Code</th>
                                            <th>Event</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Created By</th>
                                            <th>Approved By</th>
                                            <th>Reconciled By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($takes as $idx => $t):
                                            $tid = (int)$t['id'];
                                            $items = $itemsByTake[$tid] ?? [];
                                            $no = $idx + 1;
                                        ?>
                                        <tr class="report-row" data-id="<?= $tid ?>">
                                            <td class="text-center"><?= $no ?></td>
                                            <td><button type="button" class="btn btn-sm btn-link p-0 text-dark toggle-detail" data-id="<?= $tid ?>" title="Toggle products"><i class="bi bi-chevron-down"></i></button></td>
                                            <td><code><?= htmlspecialchars($t['display_code'] ?? $t['take_code'] ?? 'MT-#'.$t['id']) ?></code></td>
                                            <td><?= htmlspecialchars($t['event_name']) ?></td>
                                            <td><?= date('M j, Y', strtotime($t['event_date'])) ?></td>
                                            <td>
                                                <?php
                                                $badge = $statusBadges[$t['status']] ?? 'bg-secondary';
                                                ?>
                                                <span class="badge <?= $badge ?>"><?= htmlspecialchars($statusLabels[$t['status']] ?? ucfirst(str_replace('_', ' ', $t['status']))) ?></span>
                                            </td>
                                            <td><small><?= htmlspecialchars($t['created_by_name'] ?? '-') ?><br><?= !empty($t['created_at']) ? date('M j, Y H:i', strtotime($t['created_at'])) : '-' ?></small></td>
                                            <td><small><?= htmlspecialchars($t['approved_by_name'] ?? '-') ?><br><?= !empty($t['approved_at']) ? date('M j, Y H:i', strtotime($t['approved_at'])) : '-' ?></small></td>
                                            <td><small><?= htmlspecialchars($t['reconciled_by_name'] ?? '-') ?><br><?= !empty($t['reconciled_at']) ? date('M j, Y H:i', strtotime($t['reconciled_at'])) : '-' ?></small></td>
                                            <td>
                                                <a href="marketing_take_detail.php?id=<?= $tid ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                            </td>
                                        </tr>
                                        <tr class="detail-row d-none" id="detail-<?= $tid ?>">
                                            <td colspan="10" class="p-0 bg-light">
                                                <div class="p-3">
                                                    <table class="table table-sm table-bordered mb-0">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th>Product</th>
                                                                <th class="text-end">Qty Taken</th>
                                                                <th class="text-end">Returned</th>
                                                                <th class="text-end">Not Returned</th>
                                                                <th class="text-end">Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($items as $it):
                                                                $ret = (float)($it['quantity_returned'] ?? 0);
                                                                $notRet = (float)($it['quantity_not_returned'] ?? 0);
                                                                $taken = (float)$it['quantity_taken'];
                                                                $remaining = $taken - $ret - $notRet;
                                                                $prodDone = $remaining < 0.0001;
                                                            ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($it['product_name']) ?></td>
                                                                <td class="text-end"><?= number_format($taken, 2) ?></td>
                                                                <td class="text-end"><?= number_format($ret, 2) ?></td>
                                                                <td class="text-end"><?= number_format($notRet, 2) ?></td>
                                                                <td class="text-end"><?= $prodDone ? '<span class="badge bg-success">Done</span>' : '<span class="badge bg-warning text-dark">Partial</span>' ?></td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
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
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.toggle-detail').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = this.getAttribute('data-id');
        var row = document.getElementById('detail-' + id);
        var icon = this.querySelector('i');
        if (row) {
            row.classList.toggle('d-none');
            icon.classList.toggle('bi-chevron-down', row.classList.contains('d-none'));
            icon.classList.toggle('bi-chevron-up', !row.classList.contains('d-none'));
        }
    });
});
</script>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
