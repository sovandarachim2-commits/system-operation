<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'offline_daily_report.view');
require_once __DIR__ . '/offline_lib.php';

$pdo = get_db_connection();
offline_ensure_schema($pdo);
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$locations = offline_locations($pdo, true);
$locId = (int)($_GET['location_id'] ?? 0);

$params = [$date . ' 00:00:00', $date . ' 23:59:59'];
$locFilter = '';
if ($locId > 0) {
    $locFilter = ' AND (sm.from_storage_location_id = ? OR sm.to_storage_location_id = ? OR sm.notes LIKE ?)';
    $params[] = $locId;
    $params[] = $locId;
    $params[] = '%[Location:' . $locId . ']%';
}

$stmt = $pdo->prepare("
    SELECT p.name AS product_name,
           SUM(CASE WHEN sm.reference_type = 'transfer_to_offline' THEN sm.quantity ELSE 0 END) AS transfer_in,
           SUM(CASE WHEN sm.reference_type = 'transfer_from_offline' THEN sm.quantity ELSE 0 END) AS transfer_out,
           SUM(CASE WHEN sm.reference_type = 'offline_sale' THEN sm.quantity ELSE 0 END) AS sold_qty,
           SUM(CASE WHEN sm.reference_type = 'offline_adjustment_in' THEN sm.quantity ELSE 0 END) AS adjustment_in,
           SUM(CASE WHEN sm.reference_type = 'offline_adjustment_out' THEN sm.quantity ELSE 0 END) AS adjustment_out
    FROM stock_movements sm
    JOIN products p ON p.id = sm.item_id
    WHERE sm.created_at BETWEEN ? AND ?
      AND sm.reference_type IN ('transfer_to_offline', 'transfer_from_offline', 'offline_sale', 'offline_adjustment_in', 'offline_adjustment_out')
      {$locFilter}
    GROUP BY p.id, p.name
    ORDER BY p.name
");
$stmt->execute($params);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stockParams = [];
$stockWhere = 'COALESCE(sl.is_offline_location, 0) = 1';
if ($locId > 0) {
    $stockWhere .= ' AND sl.id = ?';
    $stockParams[] = $locId;
}
$stockStmt = $pdo->prepare("
    SELECT ci.item_name, sl.location_code, sl.location_name, SUM(ci.quantity_on_hand) AS ending_qty
    FROM current_inventory ci
    JOIN storage_locations sl ON sl.id = ci.storage_location_id
    WHERE {$stockWhere}
    GROUP BY ci.item_name, sl.location_code, sl.location_name
    ORDER BY sl.location_code, ci.item_name
");
$stockStmt->execute($stockParams);
$stockRows = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../layout/header.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-clipboard-check me-2"></i>Offline Closing Report</h1>
        <form method="get" class="d-flex gap-2">
            <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($date) ?>">
            <select name="location_id" class="form-select"><option value="0">All offline locations</option><?php foreach ($locations as $loc): ?><option value="<?= (int)$loc['id'] ?>" <?= $locId === (int)$loc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['location_code']) ?></option><?php endforeach; ?></select>
            <button class="btn btn-primary">Show</button>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i></button>
        </form>
    </div>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm"><div class="card-header bg-light fw-semibold">Daily Offline Movement Closing</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Product</th><th class="text-end">Transfer In</th><th class="text-end">Transfer Out</th><th class="text-end">Sold</th><th class="text-end">Adj In</th><th class="text-end">Adj Out</th></tr></thead><tbody>
                <?php foreach ($movements as $m): ?><tr><td><?= htmlspecialchars($m['product_name']) ?></td><td class="text-end"><?= number_format((float)$m['transfer_in'], 2) ?></td><td class="text-end"><?= number_format((float)$m['transfer_out'], 2) ?></td><td class="text-end"><?= number_format((float)$m['sold_qty'], 2) ?></td><td class="text-end"><?= number_format((float)$m['adjustment_in'], 2) ?></td><td class="text-end"><?= number_format((float)$m['adjustment_out'], 2) ?></td></tr><?php endforeach; ?>
                <?php if (!$movements): ?><tr><td colspan="6" class="text-center text-muted py-4">No offline movements for this date.</td></tr><?php endif; ?>
            </tbody></table></div></div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm"><div class="card-header bg-light fw-semibold">Current Ending Offline Stock</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Location</th><th>Product</th><th class="text-end">Ending Qty</th></tr></thead><tbody>
                <?php foreach ($stockRows as $row): ?><tr><td><span class="badge bg-dark"><?= htmlspecialchars($row['location_code']) ?></span></td><td><?= htmlspecialchars($row['item_name']) ?></td><td class="text-end"><?= number_format((float)$row['ending_qty'], 2) ?></td></tr><?php endforeach; ?>
                <?php if (!$stockRows): ?><tr><td colspan="3" class="text-center text-muted py-4">No offline stock found.</td></tr><?php endif; ?>
            </tbody></table></div></div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../layout/footer.php'; ?>
