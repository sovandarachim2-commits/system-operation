<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'product_sets.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();
$errors = [];

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$set_name_filter = trim($_GET['set_name'] ?? '');
$event_type = trim($_GET['event_type'] ?? '');

$setNames = [];
$reportRows = [];

try {
    $stmtSetNames = $pdo->query("SELECT set_name FROM product_sets ORDER BY set_name");
    $setNames = $stmtSetNames->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $errors[] = 'Failed to load product sets: ' . $e->getMessage();
}

try {
    $reportSql = "
        SELECT 
            pal.created_at AS event_time,
            ps.set_name,
            pal.action_type AS raw_event_type,
            pal.user_name,
            pal.user_id,
            pal.action_details,
            NULL AS quantity,
            'audit' AS source_type
        FROM product_set_audit_log pal
        JOIN product_sets ps ON ps.id = pal.product_set_id
        WHERE pal.action_type IN ('created', 'stock_added', 'auto_created')
          AND DATE(pal.created_at) BETWEEN ? AND ?
    ";

    $reportParams = [$date_from, $date_to];
    if ($set_name_filter !== '') {
        $reportSql .= " AND ps.set_name = ?";
        $reportParams[] = $set_name_filter;
    }
    if ($event_type === 'manual_created') {
        $reportSql .= " AND pal.action_type = 'created'";
    } elseif ($event_type === 'manual_stock_added') {
        $reportSql .= " AND pal.action_type = 'stock_added'";
    } elseif ($event_type === 'print_auto_created') {
        $reportSql .= " AND pal.action_type = 'auto_created'";
    }

    $stmtReport = $pdo->prepare($reportSql);
    $stmtReport->execute($reportParams);
    $reportRows = $stmtReport->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reportRows as &$row) {
        if ($row['raw_event_type'] === 'created') {
            $row['event_label'] = 'Create';
        } elseif ($row['raw_event_type'] === 'stock_added') {
            $row['event_label'] = 'Add More';
        } elseif ($row['raw_event_type'] === 'auto_created') {
            $row['event_label'] = 'Auto Create During Print';
        } else {
            $row['event_label'] = ucfirst(str_replace('_', ' ', (string)$row['raw_event_type']));
        }

        if ($row['quantity'] === null) {
            $parsedQuantity = null;
            if (preg_match('/created with\s+([0-9]+(?:\.[0-9]+)?)\s+sets/i', (string)$row['action_details'], $matches)) {
                $parsedQuantity = (float)$matches[1];
            } elseif (preg_match('/Added\s+([0-9]+(?:\.[0-9]+)?)\s+more\s+sets/i', (string)$row['action_details'], $matches)) {
                $parsedQuantity = (float)$matches[1];
            } elseif (preg_match('/Auto-created\s+([0-9]+(?:\.[0-9]+)?)\s+sets/i', (string)$row['action_details'], $matches)) {
                $parsedQuantity = (float)$matches[1];
            }
            $row['quantity'] = $parsedQuantity;
        } else {
            $row['quantity'] = (float)$row['quantity'];
        }

        $location_name = '';
        if (preg_match('/storage_location_id:([0-9]+)/', (string)$row['action_details'], $matches)) {
            $locId = (int)$matches[1];
            if ($locId > 0) {
                try {
                    $locStmt = $pdo->prepare("SELECT location_name FROM storage_locations WHERE id = ? LIMIT 1");
                    $locStmt->execute([$locId]);
                    $location_name = $locStmt->fetchColumn() ?: '';
                } catch (Exception $e) {
                }
            }
        }
        $row['location_name'] = $location_name;

        if (!empty($location_name)) {
            $row['action_details'] = str_replace($matches[0] ?? '', 'storage location: ' . $location_name, (string)$row['action_details']);
        }
    }
    unset($row);

    usort($reportRows, static function ($a, $b) {
        return strcmp((string)$b['event_time'], (string)$a['event_time']);
    });
} catch (Exception $e) {
    $errors[] = 'Failed to generate report: ' . $e->getMessage();
}

include __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Product Set Report</h1>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">
                <i class="bi bi-funnel me-2"></i>Filters
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Product Set</label>
                    <select name="set_name" class="form-select">
                        <option value="">All Product Sets</option>
                        <?php foreach ($setNames as $setName): ?>
                            <option value="<?= htmlspecialchars($setName) ?>" <?= $set_name_filter === $setName ? 'selected' : '' ?>>
                                <?= htmlspecialchars($setName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Event Type</label>
                    <select name="event_type" class="form-select">
                        <option value="">All Events</option>
                        <option value="manual_created" <?= $event_type === 'manual_created' ? 'selected' : '' ?>>Create</option>
                        <option value="manual_stock_added" <?= $event_type === 'manual_stock_added' ? 'selected' : '' ?>>Add More</option>
                        <option value="print_auto_created" <?= $event_type === 'print_auto_created' ? 'selected' : '' ?>>Auto Create During Print</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search me-2"></i>Search
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="product_set_report.php" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-arrow-clockwise me-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0">Product Set Creation Tracking</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Date Time</th>
                            <th>Product Set</th>
                            <th>Storage Location</th>
                            <th>Event</th>
                            <th class="text-end">Quantity</th>
                            <th>User</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reportRows)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-3">No product set activity found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reportRows as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($row['event_time']))) ?></td>
                                    <td><?= htmlspecialchars($row['set_name']) ?></td>
                                    <td><?= htmlspecialchars($row['location_name'] ?: '-') ?></td>
                                    <td>
                                        <?php if ($row['event_label'] === 'Auto Create During Print'): ?>
                                            <span class="badge bg-warning text-dark"><?= htmlspecialchars($row['event_label']) ?></span>
                                        <?php elseif ($row['event_label'] === 'Create'): ?>
                                            <span class="badge bg-success"><?= htmlspecialchars($row['event_label']) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-info text-dark"><?= htmlspecialchars($row['event_label']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= $row['quantity'] !== null ? htmlspecialchars(number_format((float)$row['quantity'], 2)) : '-' ?></td>
                                    <td><?= htmlspecialchars($row['user_name'] ?: 'Unknown User') ?></td>
                                    <td><?= htmlspecialchars($row['action_details'] ?: '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
