<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take_approve.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/marketing_take_functions.php';

$pdo = get_db_connection();
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);

$message = '';
$messageType = '';

// AJAX: Return availability JSON for take + location
if (isset($_GET['get_availability']) && $_GET['get_availability'] === '1') {
    header('Content-Type: application/json');
    $availId = (int)($_GET['id'] ?? 0);
    $availLocId = (int)($_GET['location_id'] ?? 0);
    if ($availId <= 0 || $availLocId <= 0) {
        echo json_encode(['items' => []]);
        exit;
    }
    $items = $pdo->prepare("
        SELECT p.name as product_name, mti.quantity_taken
        FROM marketing_take_items mti
        JOIN products p ON mti.product_id = p.id
        JOIN marketing_takes mt ON mti.marketing_take_id = mt.id
        WHERE mt.id = ? AND mt.status = 'pending_approval'
    ");
    $items->execute([$availId]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($items as $it) {
        $avail = getInventoryQuantity($pdo, $it['product_name'], $availLocId);
        $qty = (float)$it['quantity_taken'];
        $out[] = ['product_name' => $it['product_name'], 'quantity_taken' => $qty, 'available' => $avail, 'ok' => $avail >= $qty];
    }
    echo json_encode(['items' => $out]);
    exit;
}

// AJAX: Return modal body HTML for a specific take
if (isset($_GET['get_modal']) && $_GET['get_modal'] === '1') {
    $modalId = (int)($_GET['id'] ?? 0);
    if ($modalId <= 0) {
        echo '<div class="alert alert-warning">Invalid request.</div>';
        exit;
    }
    $take = $pdo->prepare("SELECT * FROM marketing_takes WHERE id = ? AND status = 'pending_approval'");
    $take->execute([$modalId]);
    $take = $take->fetch(PDO::FETCH_ASSOC);
    if (!$take) {
        echo '<div class="alert alert-warning">Request not found or already processed.</div>';
        exit;
    }
    $items = $pdo->prepare("
        SELECT mti.*, p.name as product_name
        FROM marketing_take_items mti
        JOIN products p ON mti.product_id = p.id
        WHERE mti.marketing_take_id = ?
    ");
    $items->execute([$modalId]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);
    $locations = $pdo->query("SELECT id, location_code, location_name FROM storage_locations WHERE is_active = 1 ORDER BY location_code")->fetchAll(PDO::FETCH_ASSOC);
    $defaultLocId = getDefaultStorageLocationId($pdo);
    $locationId = $defaultLocId;
    $insufficient = [];
    foreach ($items as $it) {
        $avail = getInventoryQuantity($pdo, $it['product_name'], $locationId);
        if ($avail < (float)$it['quantity_taken']) {
            $insufficient[] = $it['product_name'];
        }
    }
    ?>
    <form method="POST" action="marketing_take_approve.php">
        <input type="hidden" name="id" value="<?= (int)$modalId ?>">

        <div class="card mb-4 border shadow-sm">
            <div class="card-header bg-light py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2"></i>Request Details</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="p-3 bg-white rounded border">
                            <small class="text-muted d-block">Event Date</small>
                            <span class="fs-5 fw-semibold"><?= date('M j, Y', strtotime($take['event_date'])) ?></span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-white rounded border">
                            <small class="text-muted d-block">Event Location</small>
                            <span class="fs-5"><?= htmlspecialchars($take['location'] ?: '-') ?></span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-white rounded border">
                            <small class="text-muted d-block">Products</small>
                            <span class="fs-5 fw-semibold"><?= count($items) ?> item(s)</span>
                        </div>
                    </div>
                </div>
                <?php if (!empty($take['notes'])): ?>
                <div class="mt-3 p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1">Notes</small>
                    <p class="mb-0 fs-6"><?= nl2br(htmlspecialchars($take['notes'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4 border shadow-sm">
            <div class="card-header bg-light py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-geo-alt me-2"></i>Storage Location (for Stock Out)</h6>
            </div>
            <div class="card-body">
                <select name="storage_location_id" class="form-select form-select-lg storage-loc-select" required data-take-id="<?= (int)$modalId ?>">
                    <option value="">-- Select Storage Location --</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= (int)$loc['id'] ?>" <?= $loc['id'] == $defaultLocId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($loc['location_code'] . ' - ' . $loc['location_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Stock will be taken from this location.</small>
            </div>
        </div>

        <div class="card mb-4 border shadow-sm">
            <div class="card-header bg-light py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-box-seam me-2"></i>Products &amp; Stock Availability</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="py-3">Product</th>
                                <th class="text-end py-3">Quantity Requested</th>
                                <th class="text-end py-3">Available</th>
                                <th class="text-center py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="modal-products-body">
                            <?php foreach ($items as $it):
                                $avail = getInventoryQuantity($pdo, $it['product_name'], $locationId);
                                $ok = $avail >= (float)$it['quantity_taken'];
                            ?>
                            <tr data-product="<?= htmlspecialchars($it['product_name']) ?>" data-qty="<?= (float)$it['quantity_taken'] ?>" class="<?= !$ok ? 'table-warning' : '' ?>">
                                <td class="py-3 fw-medium"><?= htmlspecialchars($it['product_name']) ?></td>
                                <td class="text-end py-3"><?= number_format($it['quantity_taken'], 2) ?></td>
                                <td class="text-end py-3 avail-cell"><?= number_format($avail, 2) ?></td>
                                <td class="text-center py-3 status-cell"><?= $ok ? '<span class="badge bg-success fs-6">OK</span>' : '<span class="badge bg-danger fs-6">Insufficient</span>' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalInsufficientAlert" class="alert alert-danger py-3" style="<?= empty($insufficient) ? 'display:none' : '' ?>">
            <i class="bi bi-exclamation-triangle me-2"></i>Insufficient stock at selected location.
        </div>
        <input type="hidden" name="reject_reason" id="modalRejectReason" value="">
        <input type="hidden" name="approve_note" id="modalApproveNote" value="">
        <input type="hidden" name="action" id="modalFormAction" value="">
        <div class="d-flex gap-3 justify-content-end pt-2">
            <button type="button" class="btn btn-danger btn-lg btn-modal-reject"><i class="bi bi-x-lg me-2"></i>Reject</button>
            <button type="button" id="modalApproveBtn" class="btn btn-success btn-lg btn-modal-approve" <?= !empty($insufficient) ? 'disabled' : '' ?>><i class="bi bi-check-lg me-2"></i>Approve</button>
        </div>
    </form>
    <?php
    exit;
}

// Handle POST (approve/reject from modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id <= 0) {
        $_SESSION['marketing_take_flash'] = ['message' => 'Invalid request.', 'type' => 'danger'];
        header('Location: marketing_take_approve.php');
        exit;
    }
    $take = $pdo->prepare("SELECT * FROM marketing_takes WHERE id = ? AND status = 'pending_approval'");
    $take->execute([$id]);
    $take = $take->fetch(PDO::FETCH_ASSOC);
    if (!$take) {
        $_SESSION['marketing_take_flash'] = ['message' => 'Request not found or already processed.', 'type' => 'warning'];
        header('Location: marketing_take_approve.php');
        exit;
    }
    $items = $pdo->prepare("SELECT mti.*, p.name as product_name FROM marketing_take_items mti JOIN products p ON mti.product_id = p.id WHERE mti.marketing_take_id = ?");
    $items->execute([$id]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);

    if ($action === 'approve') {
        $locationId = (int)($_POST['storage_location_id'] ?? 0);
        if ($locationId <= 0) {
            $_SESSION['marketing_take_flash'] = ['message' => 'Please select Storage Location.', 'type' => 'danger'];
            header('Location: marketing_take_approve.php');
            exit;
        }
        $approveNote = trim($_POST['approve_note'] ?? '');
        if ($approveNote === '') {
            $_SESSION['marketing_take_flash'] = ['message' => 'Please enter an approval note.', 'type' => 'danger'];
            header('Location: marketing_take_approve.php');
            exit;
        }
        $insufficientForLoc = [];
        foreach ($items as $it) {
            $avail = getInventoryQuantity($pdo, $it['product_name'], $locationId);
            if ($avail < (float)$it['quantity_taken']) {
                $insufficientForLoc[] = "{$it['product_name']} (need {$it['quantity_taken']}, have {$avail})";
            }
        }
        if (!empty($insufficientForLoc)) {
            $_SESSION['marketing_take_flash'] = ['message' => 'Insufficient stock: ' . implode(', ', $insufficientForLoc), 'type' => 'danger'];
            header('Location: marketing_take_approve.php');
            exit;
        }
        try {
            $pdo->beginTransaction();
            foreach ($items as $it) {
                $qty = (float)$it['quantity_taken'];
                $prodId = (int)$it['product_id'];
                $prodName = $it['product_name'];
                upsertInventoryQuantity($pdo, $prodId, $prodName, -$qty, $locationId, $userId);
                $soCols = $pdo->query("SHOW COLUMNS FROM stock_operations")->fetchAll(PDO::FETCH_COLUMN);
                $hasProductId = in_array('product_id', $soCols);
                if ($hasProductId) {
                    $stmt = $pdo->prepare("INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, 'marketing_outbound', ?, 'marketing_take', ?, ?, ?)");
                    $stmt->execute([$prodId, $locationId, $qty, $id, "Marketing take " . ($take['take_code'] ?? 'MT-#'.$id) . " for {$take['event_name']}: {$prodName}", $userId]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'marketing_outbound', ?, 'marketing_take', ?, ?, ?)");
                    $stmt->execute([$locationId, $qty, $id, "Marketing take " . ($take['take_code'] ?? 'MT-#'.$id) . " for {$take['event_name']}: {$prodName}", $userId]);
                }
            }
            $pdo->prepare("UPDATE marketing_takes SET status = 'pending', storage_location_id = ?, approved_by = ?, approved_at = NOW(), approve_note = ? WHERE id = ?")->execute([$locationId, $userId, $approveNote, $id]);
            $pdo->commit();
            $approvedByName = $currentUser['name'] ?? $currentUser['username'] ?? 'User';
            send_marketing_approve_reply_to_telegram($pdo, $id, true, $approvedByName, $approveNote);
            $_SESSION['marketing_take_flash'] = ['message' => 'Market take approved. Stock Out completed. Reply sent to Telegram.', 'type' => 'success'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['marketing_take_flash'] = ['message' => 'Error: ' . $e->getMessage(), 'type' => 'danger'];
        }
    } elseif ($action === 'reject') {
        $rejectReason = trim($_POST['reject_reason'] ?? '');
        if ($rejectReason === '') {
            $_SESSION['marketing_take_flash'] = ['message' => 'Please enter a rejection reason.', 'type' => 'danger'];
            header('Location: marketing_take_approve.php');
            exit;
        }
        $pdo->prepare("UPDATE marketing_takes SET status = 'rejected', approved_by = ?, approved_at = NOW(), reject_reason = ? WHERE id = ?")->execute([$userId, $rejectReason, $id]);
        $approvedByName = $currentUser['name'] ?? $currentUser['username'] ?? 'User';
        send_marketing_approve_reply_to_telegram($pdo, $id, false, $approvedByName, $rejectReason);
        $_SESSION['marketing_take_flash'] = ['message' => 'Market take rejected. Reply sent to Telegram.', 'type' => 'info'];
    }
    header('Location: marketing_take_approve.php');
    exit;
}

// Main: List all pending suggests
$takes = $pdo->query("
    SELECT mt.*, COALESCE(mt.take_code, CONCAT('MT-#', mt.id)) as display_code, u.name as created_by_name,
           (SELECT COUNT(*) FROM marketing_take_items WHERE marketing_take_id = mt.id) as item_count
    FROM marketing_takes mt
    LEFT JOIN users u ON mt.created_by = u.id
    WHERE mt.status = 'pending_approval'
    ORDER BY mt.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

if (isset($_SESSION['marketing_take_flash'])) {
    $flash = $_SESSION['marketing_take_flash'];
    unset($_SESSION['marketing_take_flash']);
}

$current = 'marketing_take_approve.php';
require_once __DIR__ . '/../layout/header.php';
?>
<style>
@media (max-width: 767.98px) {
    .mt-approve .btn-view-modal { min-width: 44px; min-height: 44px; }
}
</style>

<div class="d-flex flex-column min-vh-100 mt-approve">
    <div class="container-fluid py-3 py-md-4 flex-grow-1">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start gap-2 mb-3 mb-md-4">
            <a href="marketing_take_list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <a href="marketing_take_approve_history.php" class="btn btn-outline-primary btn-sm ms-sm-auto"><i class="bi bi-clock-history me-1"></i>History</a>
        </div>
        <h2 class="h5 mb-1"><i class="bi bi-check-circle me-2"></i>Approve / Reject Market Take</h2>
        <p class="text-muted mb-4 small">New market suggest requests below. Click View to see details and approve or reject.</p>

        <?php if (isset($flash)): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-4">
            <?= htmlspecialchars($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (empty($takes)): ?>
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-inbox fs-1"></i>
                <p class="mb-0 mt-2">No new market suggest requests.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-header bg-dark text-white py-2 py-md-3">
                <strong>New Suggest</strong>
                <span class="badge bg-light text-dark ms-2"><?= count($takes) ?> request(s)</span>
            </div>
            <!-- Mobile: Card layout -->
            <div class="d-md-none">
                <?php foreach ($takes as $t): ?>
                <div class="border-bottom">
                    <div class="p-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <code class="small"><?= htmlspecialchars($t['display_code']) ?></code>
                            <span class="text-muted small"><?= date('M j, H:i', strtotime($t['created_at'])) ?></span>
                        </div>
                        <div class="mb-2 fw-medium"><?= htmlspecialchars($t['event_name']) ?></div>
                        <div class="small text-muted mb-2">
                            <?= date('M j, Y', strtotime($t['event_date'])) ?> &bull; <?= (int)$t['item_count'] ?> product(s)
                        </div>
                        <div class="small text-muted mb-2">
                            <strong>Suggest By:</strong> <?= htmlspecialchars($t['created_by_name'] ?? '-') ?><br>
                            <?= $t['created_at'] ? date('M j, Y H:i', strtotime($t['created_at'])) : '-' ?>
                        </div>
                        <button type="button" class="btn btn-primary btn-view-modal w-100" data-id="<?= (int)$t['id'] ?>" data-code="<?= htmlspecialchars($t['display_code']) ?>" data-event="<?= htmlspecialchars($t['event_name']) ?>">
                            <i class="bi bi-eye me-1"></i>View
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
                                            <th>Suggest By</th>
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
                                            <td><small><?= htmlspecialchars($t['created_by_name'] ?? '-') ?><br><?= $t['created_at'] ? date('M j, Y H:i', strtotime($t['created_at'])) : '-' ?></small></td>
                                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary btn-view-modal" data-id="<?= (int)$t['id'] ?>" data-code="<?= htmlspecialchars($t['display_code']) ?>" data-event="<?= htmlspecialchars($t['event_name']) ?>">
                                    <i class="bi bi-eye me-1"></i>View
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

<!-- Modal: fullscreen on mobile for easier use -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewModalLabel"><span id="modalTitle">View Request</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 mb-0">Loading...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Popup: Reject / Approve reason/note -->
<div class="modal fade" id="reasonNoteModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" id="reasonNoteModalHeader">
                <h5 class="modal-title" id="reasonNoteModalTitle">Rejection Reason</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <textarea id="reasonNoteInput" class="form-control" rows="4" placeholder="Enter your message..."></textarea>
                <div class="invalid-feedback">This field is required.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="reasonNoteConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script>
(function (run) {
    (typeof window.adminRunWhenReady === 'function' ? window.adminRunWhenReady : run)(function () {
    const viewModalEl = document.getElementById('viewModal');
    const reasonNoteModalEl = document.getElementById('reasonNoteModal');
    if (!viewModalEl || !reasonNoteModalEl) return;

    const modal = bootstrap.Modal.getOrCreateInstance(viewModalEl);
    const modalTitle = document.getElementById('modalTitle');
    const modalBody = document.getElementById('modalBody');

    document.querySelectorAll('.btn-view-modal').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const code = this.dataset.code;
            const event = this.dataset.event;
            modalTitle.textContent = code + ': ' + event;
            modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2 mb-0">Loading...</p></div>';
            modal.show();

            fetch('marketing_take_approve.php?get_modal=1&id=' + id)
                .then(r => r.text())
                .then(html => {
                    modalBody.innerHTML = html;
                })
                .catch(() => {
                    modalBody.innerHTML = '<div class="alert alert-danger">Failed to load.</div>';
                });
        });
    });

    viewModalEl.addEventListener('hidden.bs.modal', function() {
        modalBody.innerHTML = '';
    });

    // Popup when Reject or Approve clicked
    const reasonNoteModal = bootstrap.Modal.getOrCreateInstance(reasonNoteModalEl);
    const reasonNoteInput = document.getElementById('reasonNoteInput');
    const reasonNoteTitle = document.getElementById('reasonNoteModalTitle');
    const reasonNoteHeader = document.getElementById('reasonNoteModalHeader');
    const reasonNoteConfirmBtn = document.getElementById('reasonNoteConfirmBtn');
    let pendingForm = null;
    let pendingAction = null;

    function openReasonNotePopup(form, action) {
        pendingForm = form;
        pendingAction = action;
        reasonNoteInput.value = '';
        reasonNoteInput.classList.remove('is-invalid');
        if (action === 'reject') {
            reasonNoteTitle.textContent = 'Rejection Reason';
            reasonNoteInput.placeholder = 'Please enter the reason for rejection (required)...';
            reasonNoteHeader.className = 'modal-header border-danger';
            reasonNoteConfirmBtn.className = 'btn btn-danger';
        } else {
            reasonNoteTitle.textContent = 'Approval Note';
            reasonNoteInput.placeholder = 'Please enter a note for this approval (required)...';
            reasonNoteHeader.className = 'modal-header border-success';
            reasonNoteConfirmBtn.className = 'btn btn-success';
        }
        reasonNoteModal.show();
        setTimeout(function() { reasonNoteInput.focus(); }, 300);
    }

    reasonNoteConfirmBtn.addEventListener('click', function() {
        const val = reasonNoteInput.value.trim();
        if (!val) {
            reasonNoteInput.classList.add('is-invalid');
            reasonNoteInput.focus();
            return;
        }
        if (!pendingForm) return;
        const rejectHidden = pendingForm.querySelector('#modalRejectReason');
        const approveHidden = pendingForm.querySelector('#modalApproveNote');
        const actionHidden = pendingForm.querySelector('#modalFormAction');
        if (pendingAction === 'reject') {
            if (rejectHidden) rejectHidden.value = val;
            if (approveHidden) approveHidden.value = '';
        } else {
            if (approveHidden) approveHidden.value = val;
            if (rejectHidden) rejectHidden.value = '';
        }
        if (actionHidden) actionHidden.value = pendingAction;
        reasonNoteModal.hide();
        const msg = pendingAction === 'reject'
            ? 'Reject this market take request? The request will be declined.'
            : 'Approve this market take request? Stock will be taken out from the selected location.';
        if (confirm(msg)) {
            pendingForm.submit();
        }
    });

    reasonNoteModalEl.addEventListener('hidden.bs.modal', function() {
        pendingForm = null;
        pendingAction = null;
    });

    modalBody.addEventListener('click', function(e) {
        const form = e.target.closest('form');
        if (!form) return;
        const approveBtn = e.target.closest('.btn-modal-approve');
        const rejectBtn = e.target.closest('.btn-modal-reject');
        if (approveBtn && !approveBtn.disabled) {
            e.preventDefault();
            openReasonNotePopup(form, 'approve');
        } else if (rejectBtn) {
            e.preventDefault();
            openReasonNotePopup(form, 'reject');
        }
    });

    // When storage location changes, fetch availability and update table
    modalBody.addEventListener('change', function(e) {
        const sel = e.target.closest('.storage-loc-select');
        if (!sel) return;
        const takeId = sel.dataset.takeId;
        const locId = sel.value;
        if (!takeId || !locId) return;

        const tbody = modalBody.querySelector('.modal-products-body');
        const rows = tbody ? tbody.querySelectorAll('tr') : [];
        const approveBtn = modalBody.querySelector('#modalApproveBtn');
        const insufficientAlert = modalBody.querySelector('#modalInsufficientAlert');

        fetch('marketing_take_approve.php?get_availability=1&id=' + takeId + '&location_id=' + locId)
            .then(r => r.json())
            .then(data => {
                let anyInsufficient = false;
                const items = data.items || [];
                rows.forEach(function(row) {
                    const product = row.dataset.product;
                    const item = items.find(i => i.product_name === product);
                    if (item) {
                        const availCell = row.querySelector('.avail-cell');
                        const statusCell = row.querySelector('.status-cell');
                        if (availCell) availCell.textContent = parseFloat(item.available).toLocaleString(undefined, {minimumFractionDigits: 2});
                        if (statusCell) {
                            statusCell.innerHTML = item.ok ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Insufficient</span>';
                            if (!item.ok) anyInsufficient = true;
                        }
                    }
                });
                if (approveBtn) approveBtn.disabled = anyInsufficient;
                if (insufficientAlert) insufficientAlert.style.display = anyInsufficient ? 'block' : 'none';
            })
            .catch(function() {
                if (insufficientAlert) {
                    insufficientAlert.textContent = 'Failed to load availability.';
                    insufficientAlert.style.display = 'block';
                }
                if (approveBtn) approveBtn.disabled = true;
            });
    });
    });
})(function (fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
