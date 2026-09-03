<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take_reconcile.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/marketing_take_functions.php';

$pdo = get_db_connection();
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$storageLocations = $pdo->query("SELECT id, location_code, location_name FROM storage_locations WHERE is_active = 1 ORDER BY is_default DESC, location_code ASC, location_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$activeLocationIds = [];
$locationNameMap = [];
foreach ($storageLocations as $sl) {
    $sid = (int)($sl['id'] ?? 0);
    if ($sid > 0) {
        $activeLocationIds[$sid] = true;
        $locationNameMap[$sid] = trim((string)($sl['location_code'] ?? '') . ' - ' . (string)($sl['location_name'] ?? ''), ' -');
    }
}

$message = '';
$messageType = '';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// AJAX: Return modal body HTML for reconciliation form
if (isset($_GET['get_modal']) && $_GET['get_modal'] === '1' && $id > 0) {
    $take = $pdo->prepare("SELECT * FROM marketing_takes WHERE id = ? AND status IN ('pending','completed')");
    $take->execute([$id]);
    $take = $take->fetch(PDO::FETCH_ASSOC);
    if (!$take) {
        echo '<div class="alert alert-warning">Market take not found or not ready for reconciliation.</div>';
        exit;
    }
    $items = $pdo->prepare("SELECT mti.*, p.name as product_name FROM marketing_take_items mti JOIN products p ON mti.product_id = p.id WHERE mti.marketing_take_id = ?");
    $items->execute([$id]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);
    $locationId = (int)($take['storage_location_id'] ?? 0);
    if ($locationId <= 0) $locationId = getDefaultStorageLocationId($pdo);
    if ($locationId <= 0 && !empty($storageLocations)) {
        $locationId = (int)($storageLocations[0]['id'] ?? 0);
    }
    $soCols = [];
    try { $soCols = $pdo->query("SHOW COLUMNS FROM stock_operations")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    $hasProductId = in_array('product_id', $soCols);
    ?>
    <form method="POST" action="marketing_take_reconcile.php">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="reconcile" value="1">
        <div class="card mb-3 border-info">
            <div class="card-body py-2">
                <div class="row g-2 align-items-center">
                    <div class="col-12 col-md-4"><label for="return_location_id_modal" class="form-label mb-0 fw-semibold">Return Location</label></div>
                    <div class="col-12 col-md-8">
                        <select name="return_location_id" id="return_location_id_modal" class="form-select form-select-sm">
                            <option value="">Select location (required if Returned &gt; 0)</option>
                            <?php foreach ($storageLocations as $loc): $lid = (int)($loc['id'] ?? 0); ?>
                            <option value="<?= $lid ?>" <?= $lid === $locationId ? 'selected' : '' ?>>
                                <?= htmlspecialchars(($loc['location_code'] ?? 'LOC') . ' - ' . ($loc['location_name'] ?? '')) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div class="card mb-3 border-primary">
            <div class="card-header bg-primary text-white">
                <strong><?= htmlspecialchars($take['take_code'] ?? 'MT-#'.$id) ?>: <?= htmlspecialchars($take['event_name']) ?></strong>
                <span class="ms-2"><?= date('M j, Y', strtotime($take['event_date'])) ?></span>
            </div>
        </div>
        <div class="card mb-4 border-primary">
            <div class="card-header bg-light">
                <strong><i class="bi bi-box-seam me-2"></i>Products – Enter Returned (this step)</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Qty Taken</th>
                                <th class="text-end">Status</th>
                                <th class="text-end">Already Returned</th>
                                <th class="text-end">Remaining</th>
                                <th class="text-end">Returned (this step)</th>
                                <th class="text-end">Not Returned (optional)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $it):
                                $alreadyRet = (float)($it['quantity_returned'] ?? 0);
                                $alreadyNot = (float)($it['quantity_not_returned'] ?? 0);
                                $remaining = (float)$it['quantity_taken'] - $alreadyRet - $alreadyNot;
                                $taken = (float)$it['quantity_taken'];
                                $prodDone = $remaining < 0.0001;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($it['product_name']) ?></td>
                                <td class="text-end"><?= number_format($taken, 2) ?></td>
                                <td class="text-end"><?= $prodDone ? '<span class="badge bg-success">Done</span>' : '<span class="badge bg-warning text-dark">Partial (' . number_format($alreadyRet, 0) . '/' . number_format($taken, 0) . ')</span>' ?></td>
                                <td class="text-end"><?= number_format($alreadyRet, 2) ?></td>
                                <td class="text-end fw-bold"><?= number_format($remaining, 2) ?></td>
                                <?php if ($remaining > 0.0001): ?>
                                <td class="text-end">
                                    <input type="number" name="items[<?= (int)$it['id'] ?>][returned]" class="form-control form-control-sm text-end d-inline-block" style="width:80px" min="0" step="0.01" value="0" placeholder="0">
                                    <small class="text-muted">→ Stock In</small>
                                </td>
                                <td class="text-end">
                                    <input type="number" name="items[<?= (int)$it['id'] ?>][not_returned]" class="form-control form-control-sm text-end d-inline-block" style="width:80px" min="0" step="0.01" value="0" placeholder="0">
                                    <small class="text-muted">→ Write-off</small>
                                </td>
                                <?php else: ?>
                                <td class="text-end">—</td>
                                <td class="text-end">—</td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-light text-muted">
                <small><i class="bi bi-info-circle me-1"></i>Enter Returned (required). Not Returned is optional – leave 0 to keep rest for next step. Each product can be reconciled in many steps.</small>
            </div>
        </div>
        <div class="card mb-3 border-warning shadow-sm">
            <div class="card-header bg-warning-subtle">
                <strong><i class="bi bi-chat-left-text me-2"></i>Reconcile Note (Required)</strong>
            </div>
            <div class="card-body">
                <textarea name="reconcile_note" class="form-control" rows="3" placeholder="Type note for this reconciliation..." required></textarea>
            </div>
        </div>
        <div class="d-flex gap-2 justify-content-end">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Reconciliation</button>
        </div>
    </form>
    <?php
    exit;
}

// No ID: Show list of takes needing reconciliation
if ($id <= 0) {
    $takes = $pdo->query("
        SELECT mt.*, COALESCE(mt.take_code, CONCAT('MT-#', mt.id)) as display_code,
               u1.name as created_by_name, u2.name as approved_by_name,
               (SELECT COUNT(*) FROM marketing_take_items WHERE marketing_take_id = mt.id) as item_count,
               (SELECT COUNT(*) FROM marketing_take_items mti2 WHERE mti2.marketing_take_id = mt.id AND ABS((mti2.quantity_returned + mti2.quantity_not_returned) - mti2.quantity_taken) < 0.0001) as reconciled_count,
               (SELECT COUNT(*) FROM marketing_take_items mti3 WHERE mti3.marketing_take_id = mt.id AND (mti3.quantity_returned + mti3.quantity_not_returned) > 0.0001) as in_progress_count
        FROM marketing_takes mt
        LEFT JOIN users u1 ON mt.created_by = u1.id
        LEFT JOIN users u2 ON mt.approved_by = u2.id
        WHERE mt.status = 'pending'
        ORDER BY mt.approved_at ASC, mt.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_SESSION['marketing_take_flash'])) {
        $flash = $_SESSION['marketing_take_flash'];
        unset($_SESSION['marketing_take_flash']);
    }

    $current = 'marketing_take_reconcile.php';
    require_once __DIR__ . '/../layout/header.php';
    ?>
    <style>
    @media (max-width: 767.98px) {
        .mt-reconcile .btn-reconcile-modal { min-width: 44px; min-height: 44px; }
    }
    </style>
    <div class="d-flex flex-column min-vh-100 mt-reconcile">
        <div class="container-fluid py-3 py-md-4 flex-grow-1">
        <div class="mb-4 d-flex justify-content-between align-items-center">
            <a href="marketing_take_list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <a href="marketing_take_reconcile_history.php" class="btn btn-outline-primary"><i class="bi bi-clock-history me-1"></i>History</a>
        </div>
        <h2 class="h5 mb-1"><i class="bi bi-arrow-repeat me-2"></i>Reconcile Market Take</h2>
            <p class="text-muted mb-4 small">Select a market take below to enter returned quantities.</p>

            <?php if (isset($flash)): ?>
            <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
                <?= htmlspecialchars($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (empty($takes)): ?>
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    <i class="bi bi-inbox fs-1"></i>
                    <p class="mb-0 mt-2">No market takes waiting for reconciliation.</p>
                    <p class="small">Approved takes will appear here until you reconcile them.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-header bg-dark text-white py-2 py-md-3">
                    <strong>In Marketing – Needs Reconciliation</strong>
                    <span class="badge bg-light text-dark ms-2"><?= count($takes) ?> request(s)</span>
                </div>
                <!-- Mobile: Card layout -->
                <div class="d-md-none">
                    <?php foreach ($takes as $t): ?>
                    <div class="border-bottom">
                        <div class="p-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <code class="small"><?= htmlspecialchars($t['display_code']) ?></code>
                                <span class="text-muted small"><?= date('M j, H:i', strtotime($t['approved_at'])) ?></span>
                            </div>
                            <div class="mb-2 fw-medium"><?= htmlspecialchars($t['event_name']) ?></div>
                            <div class="small text-muted mb-2">
                                <?= date('M j, Y', strtotime($t['event_date'])) ?> &bull; <?= (int)$t['item_count'] ?> product(s)
                                <?php
                                $totalItems = (int)$t['item_count'];
                                $reconciledItems = (int)($t['reconciled_count'] ?? 0);
                                $inProgress = (int)($t['in_progress_count'] ?? 0);
                                $statusText = $reconciledItems >= $totalItems ? 'Completed' : ($reconciledItems > 0 ? "Partial ({$reconciledItems}/{$totalItems})" : ($inProgress > 0 ? 'In progress' : 'Not started'));
                                $statusBadge = $reconciledItems >= $totalItems ? 'bg-success' : ($inProgress > 0 || $reconciledItems > 0 ? 'bg-warning text-dark' : 'bg-secondary');
                                ?><br><span class="badge <?= $statusBadge ?>"><?= $statusText ?></span>
                            </div>
                            <div class="small text-muted mb-2">
                                Create: <?= htmlspecialchars($t['created_by_name'] ?? '-') ?> &bull; Approve: <?= htmlspecialchars($t['approved_by_name'] ?? '-') ?>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm w-100 btn-reconcile-modal" data-id="<?= (int)$t['id'] ?>" data-code="<?= htmlspecialchars($t['display_code']) ?>" data-event="<?= htmlspecialchars($t['event_name']) ?>">
                                <i class="bi bi-arrow-repeat me-1"></i>Reconcile
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <!-- Desktop: Table -->
                <div class="d-none d-md-block table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Event</th>
                                <th>Date</th>
                                <th>Items</th>
                                <th>Reconcile Status</th>
                                <th>Create By</th>
                                <th>Approve By</th>
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
                                <?php
                                $totalItems = (int)$t['item_count'];
                                $reconciledItems = (int)($t['reconciled_count'] ?? 0);
                                $inProgress = (int)($t['in_progress_count'] ?? 0);
                                $statusText = $reconciledItems >= $totalItems ? 'Completed' : ($reconciledItems > 0 ? "Partial ({$reconciledItems}/{$totalItems})" : ($inProgress > 0 ? 'In progress' : 'Not started'));
                                $statusBadge = $reconciledItems >= $totalItems ? 'bg-success' : ($inProgress > 0 || $reconciledItems > 0 ? 'bg-warning text-dark' : 'bg-secondary');
                                ?>
                                <td><span class="badge <?= $statusBadge ?>"><?= $statusText ?></span></td>
                                <td><small><?= htmlspecialchars($t['created_by_name'] ?? '-') ?><br><?= !empty($t['created_at']) ? date('M j, Y H:i', strtotime($t['created_at'])) : '-' ?></small></td>
                                <td><small><?= htmlspecialchars($t['approved_by_name'] ?? '-') ?><br><?= !empty($t['approved_at']) ? date('M j, Y H:i', strtotime($t['approved_at'])) : '-' ?></small></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary btn-reconcile-modal" data-id="<?= (int)$t['id'] ?>" data-code="<?= htmlspecialchars($t['display_code']) ?>" data-event="<?= htmlspecialchars($t['event_name']) ?>">
                                        <i class="bi bi-arrow-repeat me-1"></i>Reconcile
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reconcile Modal: colored popup -->
    <div class="modal fade" id="reconcileModal" tabindex="-1" aria-labelledby="reconcileModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content border-primary border-2 shadow-lg" style="background: linear-gradient(to bottom, rgba(13,110,253,0.04) 0%, #fff 80px);">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="reconcileModalLabel"><i class="bi bi-arrow-repeat me-2"></i><span id="reconcileModalTitle">Reconcile</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="reconcileModalBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 mb-0">Loading...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function (run) {
        (typeof window.adminRunWhenReady === 'function' ? window.adminRunWhenReady : run)(function () {
        const reconcileModalEl = document.getElementById('reconcileModal');
        if (!reconcileModalEl) return;

        const modal = bootstrap.Modal.getOrCreateInstance(reconcileModalEl);
        const modalTitle = document.getElementById('reconcileModalTitle');
        const modalBody = document.getElementById('reconcileModalBody');
        if (!modalTitle || !modalBody) return;

        document.querySelectorAll('.btn-reconcile-modal').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                const code = this.dataset.code;
                const event = this.dataset.event;
                modalTitle.textContent = 'Reconcile: ' + code + ' - ' + event;
                modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2 mb-0">Loading...</p></div>';
                modal.show();

                fetch('marketing_take_reconcile.php?get_modal=1&id=' + id)
                    .then(r => r.text())
                    .then(html => { modalBody.innerHTML = html; })
                    .catch(() => { modalBody.innerHTML = '<div class="alert alert-danger">Failed to load.</div>'; });
            });
        });

        reconcileModalEl.addEventListener('hidden.bs.modal', function() {
            modalBody.innerHTML = '';
        });
        });
    })(function (fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    });
    </script>
    <?php require_once __DIR__ . '/../layout/footer.php';
    exit;
}

$take = $pdo->prepare("SELECT * FROM marketing_takes WHERE id = ? AND status IN ('pending','completed')");
$take->execute([$id]);
$take = $take->fetch(PDO::FETCH_ASSOC);

if (!$take) {
    $_SESSION['marketing_take_flash'] = ['message' => 'Market take not found or not ready for reconciliation.', 'type' => 'warning'];
    header('Location: marketing_take_reconcile.php');
    exit;
}

$items = $pdo->prepare("
    SELECT mti.*, p.name as product_name
    FROM marketing_take_items mti
    JOIN products p ON mti.product_id = p.id
    WHERE mti.marketing_take_id = ?
");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$locationId = (int)($take['storage_location_id'] ?? 0);
if ($locationId <= 0) {
    $locationId = getDefaultStorageLocationId($pdo);
}

$soCols = [];
try {
    $soCols = $pdo->query("SHOW COLUMNS FROM stock_operations")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
$hasProductId = in_array('product_id', $soCols);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reconcile'])) {
    $reconcileItems = $_POST['items'] ?? [];
    $returnLocationId = (int)($_POST['return_location_id'] ?? 0);
    $reconcileNote = trim((string)($_POST['reconcile_note'] ?? ''));
    $errors = [];
    $hasAnyReturnedInput = false;

    foreach ($items as $it) {
        $itemId = (int)$it['id'];
        $alreadyRet = (float)($it['quantity_returned'] ?? 0);
        $alreadyNot = (float)($it['quantity_not_returned'] ?? 0);
        $remaining = (float)$it['quantity_taken'] - $alreadyRet - $alreadyNot;

        if ($remaining < 0.0001) continue; // Already done

        $newReturned = (float)($reconcileItems[$itemId]['returned'] ?? 0);
        $newNotReturned = (float)($reconcileItems[$itemId]['not_returned'] ?? 0);
        if ($newReturned > 0.0001) $hasAnyReturnedInput = true;

        if ($newReturned < 0 || $newNotReturned < 0 || $newReturned + $newNotReturned > $remaining + 0.0001) {
            $errors[] = "{$it['product_name']}: Returned + Not returned cannot exceed Remaining ({$remaining})";
        }
    }

    $hasAnyInput = false;
    foreach ($items as $it) {
        $itemId = (int)$it['id'];
        $alreadyRet = (float)($it['quantity_returned'] ?? 0);
        $alreadyNot = (float)($it['quantity_not_returned'] ?? 0);
        $remaining = (float)$it['quantity_taken'] - $alreadyRet - $alreadyNot;
        if ($remaining < 0.0001) continue;
        $newReturned = (float)($reconcileItems[$itemId]['returned'] ?? 0);
        $newNotReturned = (float)($reconcileItems[$itemId]['not_returned'] ?? 0);
        if ($newReturned > 0.0001 || $newNotReturned > 0.0001) { $hasAnyInput = true; break; }
    }
    if (!$hasAnyInput) {
        $errors[] = "Enter at least one quantity.";
    }
    if ($reconcileNote === '') {
        $errors[] = "Please enter Reconcile Note before saving.";
    }
    if ($hasAnyReturnedInput && ($returnLocationId <= 0 || !isset($activeLocationIds[$returnLocationId]))) {
        $errors[] = "Select a valid Return Location for product return.";
    }

    if (!empty($errors)) {
        $message = implode('<br>', $errors);
        $messageType = 'danger';
    } else {
        try {
            $pdo->beginTransaction();

            foreach ($items as $it) {
                $itemId = (int)$it['id'];
                $alreadyRet = (float)($it['quantity_returned'] ?? 0);
                $alreadyNot = (float)($it['quantity_not_returned'] ?? 0);
                $remaining = (float)$it['quantity_taken'] - $alreadyRet - $alreadyNot;

                if ($remaining < 0.0001) continue;

                $newReturned = (float)($reconcileItems[$itemId]['returned'] ?? 0);
                $newNotReturned = (float)($reconcileItems[$itemId]['not_returned'] ?? 0);

                if ($newReturned < 0.0001 && $newNotReturned < 0.0001) continue;

                $pdo->prepare("UPDATE marketing_take_items SET quantity_returned = quantity_returned + ?, quantity_not_returned = quantity_not_returned + ? WHERE id = ?")
                    ->execute([$newReturned, $newNotReturned, $itemId]);

                if ($newReturned > 0) {
                    $prodId = (int)$it['product_id'];
                    $prodName = $it['product_name'];
                    upsertInventoryQuantity($pdo, $prodId, $prodName, $newReturned, $returnLocationId, $userId);

                    if ($hasProductId) {
                        $stmt = $pdo->prepare("INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, 'marketing_return', ?, 'marketing_take', ?, ?, ?)");
                        $stmt->execute([$prodId, $returnLocationId, $newReturned, $id, "Marketing return (partial) " . ($take['take_code'] ?? 'MT-#'.$id) . " for {$take['event_name']}: {$prodName} | Note: {$reconcileNote}", $userId]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'marketing_return', ?, 'marketing_take', ?, ?, ?)");
                        $stmt->execute([$returnLocationId, $newReturned, $id, "Marketing return (partial) " . ($take['take_code'] ?? 'MT-#'.$id) . " for {$take['event_name']}: {$prodName} | Note: {$reconcileNote}", $userId]);
                    }
                }

                if ($newNotReturned > 0) {
                    $prodName = $it['product_name'];
                    if ($hasProductId) {
                        $stmt = $pdo->prepare("INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, 'marketing_writeoff', ?, 'marketing_take', ?, ?, ?)");
                        $stmt->execute([$it['product_id'], $locationId, -$newNotReturned, $id, "Marketing write-off (partial) " . ($take['take_code'] ?? 'MT-#'.$id) . " for {$take['event_name']}: {$prodName} (not returned) | Note: {$reconcileNote}", $userId]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'marketing_writeoff', ?, 'marketing_take', ?, ?, ?)");
                        $stmt->execute([$locationId, -$newNotReturned, $id, "Marketing write-off (partial) " . ($take['take_code'] ?? 'MT-#'.$id) . " for {$take['event_name']}: {$prodName} (not returned) | Note: {$reconcileNote}", $userId]);
                    }
                }

            }

            // Only mark completed when ALL products are fully reconciled (no remaining)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM marketing_take_items WHERE marketing_take_id = ? AND ABS((quantity_returned + quantity_not_returned) - quantity_taken) > 0.0001");
            $stmt->execute([$id]);
            $stillRemaining = (int)$stmt->fetchColumn();

            if ($stillRemaining === 0) {
                $pdo->prepare("UPDATE marketing_takes SET status = 'completed', reconciled_at = NOW(), reconciled_by = ? WHERE id = ?")->execute([$userId, $id]);
                $reconciledByName = $currentUser['name'] ?? $currentUser['username'] ?? 'User';
                $returnLocationName = $locationNameMap[$returnLocationId] ?? ('Location #' . $returnLocationId);
                $itemsForTg = $pdo->prepare("SELECT p.name as product_name, mti.quantity_taken, mti.quantity_returned, mti.quantity_not_returned FROM marketing_take_items mti JOIN products p ON mti.product_id = p.id WHERE mti.marketing_take_id = ?");
                $itemsForTg->execute([$id]);
                $itemsForTg = $itemsForTg->fetchAll(PDO::FETCH_ASSOC);
                send_marketing_reconcile_to_telegram($pdo, $id, $take['take_code'] ?? 'MT-#'.$id, $take['event_name'], $itemsForTg, $reconciledByName, $returnLocationName, $reconcileNote);
                $_SESSION['marketing_take_flash'] = ['message' => 'Reconciliation completed. Reply sent to Telegram.', 'type' => 'success'];
            } else {
                $_SESSION['marketing_take_flash'] = ['message' => 'Partial reconciliation saved. You can continue reconciling the remaining items.', 'type' => 'info'];
            }
            $pdo->commit();
            header('Location: marketing_take_reconcile.php');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

$current = 'marketing_take_reconcile.php';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-3 py-md-4 flex-grow-1">
        <div class="mb-4">
            <a href="marketing_take_reconcile.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>
        <h2 class="h5 mb-1"><i class="bi bi-arrow-repeat me-2"></i>Reconcile: <?= htmlspecialchars($take['take_code'] ?? 'MT-#'.$id) ?></h2>
        <p class="text-muted mb-4 small">Enter quantities returned to stock. Rest = not returned (sold, samples, damaged).</p>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header"><?= htmlspecialchars($take['take_code'] ?? 'MT-#'.$id) ?>: <?= htmlspecialchars($take['event_name']) ?> - <?= date('M j, Y', strtotime($take['event_date'])) ?></div>
        </div>

        <form method="POST">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="reconcile" value="1">
            <div class="card mb-3 border-info">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-center">
                        <div class="col-12 col-md-4"><label for="return_location_id_page" class="form-label mb-0 fw-semibold">Return Location</label></div>
                        <div class="col-12 col-md-8">
                            <select name="return_location_id" id="return_location_id_page" class="form-select form-select-sm">
                                <option value="">Select location (required if Returned &gt; 0)</option>
                                <?php foreach ($storageLocations as $loc): $lid = (int)($loc['id'] ?? 0); ?>
                                <option value="<?= $lid ?>" <?= $lid === (int)($_POST['return_location_id'] ?? $locationId) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(($loc['location_code'] ?? 'LOC') . ' - ' . ($loc['location_name'] ?? '')) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-header">Products – Enter Returned (this step)</div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Qty Taken</th>
                                <th class="text-end">Status</th>
                                <th class="text-end">Already Returned</th>
                                <th class="text-end">Remaining</th>
                                <th class="text-end">Returned (this step)</th>
                                <th class="text-end">Not Returned (optional)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $it):
                                $alreadyRet = (float)($it['quantity_returned'] ?? 0);
                                $alreadyNot = (float)($it['quantity_not_returned'] ?? 0);
                                $remaining = (float)$it['quantity_taken'] - $alreadyRet - $alreadyNot;
                                $taken = (float)$it['quantity_taken'];
                                $prodDone = $remaining < 0.0001;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($it['product_name']) ?></td>
                                <td class="text-end"><?= number_format($taken, 2) ?></td>
                                <td class="text-end"><?= $prodDone ? '<span class="badge bg-success">Done</span>' : '<span class="badge bg-warning text-dark">Partial (' . number_format($alreadyRet, 0) . '/' . number_format($taken, 0) . ')</span>' ?></td>
                                <td class="text-end"><?= number_format($alreadyRet, 2) ?></td>
                                <td class="text-end fw-bold"><?= number_format($remaining, 2) ?></td>
                                <?php if ($remaining > 0.0001): ?>
                                <td class="text-end">
                                    <input type="number" name="items[<?= (int)$it['id'] ?>][returned]" class="form-control form-control-sm text-end d-inline-block" style="width:80px" min="0" step="0.01" value="<?= htmlspecialchars($_POST['items'][$it['id']]['returned'] ?? '0') ?>" placeholder="0">
                                    <small class="text-muted">→ Stock In</small>
                                </td>
                                <td class="text-end">
                                    <input type="number" name="items[<?= (int)$it['id'] ?>][not_returned]" class="form-control form-control-sm text-end d-inline-block" style="width:80px" min="0" step="0.01" value="<?= htmlspecialchars($_POST['items'][$it['id']]['not_returned'] ?? '0') ?>" placeholder="0">
                                    <small class="text-muted">→ Write-off</small>
                                </td>
                                <?php else: ?>
                                <td class="text-end">—</td>
                                <td class="text-end">—</td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-muted">
                    <small><i class="bi bi-info-circle me-1"></i>Enter Returned (required). Not Returned is optional – leave 0 to keep rest for next step. Each product can be reconciled in many steps.</small>
                </div>
            </div>
            <div class="card mb-3 border-warning shadow-sm">
                <div class="card-header bg-warning-subtle">
                    <strong><i class="bi bi-chat-left-text me-2"></i>Reconcile Note (Required)</strong>
                </div>
                <div class="card-body">
                    <textarea name="reconcile_note" class="form-control" rows="3" placeholder="Type note for this reconciliation..." required><?= htmlspecialchars($_POST['reconcile_note'] ?? '') ?></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Reconciliation</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
