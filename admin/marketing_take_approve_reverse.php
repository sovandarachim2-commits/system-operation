<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take_approve.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/marketing_take_functions.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: marketing_take_approve_history.php');
    exit;
}

$pdo = get_db_connection();
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);

$stmt = $pdo->prepare("SELECT mt.*, COALESCE(mt.take_code, CONCAT('MT-#', mt.id)) as display_code FROM marketing_takes mt WHERE mt.id = ?");
$stmt->execute([$id]);
$take = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$take || $take['status'] !== 'pending') {
    $_SESSION['marketing_take_flash'] = ['message' => 'Only In Marketing requests can be reversed. Already reconciled or rejected.', 'type' => 'warning'];
    header('Location: marketing_take_approve_history.php');
    exit;
}

// Check no reconciliation happened
$check = $pdo->prepare("SELECT COUNT(*) FROM marketing_take_items WHERE marketing_take_id = ? AND (quantity_returned > 0 OR quantity_not_returned > 0)");
$check->execute([$id]);
if ((int)$check->fetchColumn() > 0) {
    $_SESSION['marketing_take_flash'] = ['message' => 'Cannot reverse: partial or full reconciliation already done.', 'type' => 'warning'];
    header('Location: marketing_take_approve_history.php');
    exit;
}

$items = $pdo->prepare("SELECT mti.*, p.name as product_name FROM marketing_take_items mti JOIN products p ON mti.product_id = p.id WHERE mti.marketing_take_id = ?");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$locationId = (int)($take['storage_location_id'] ?? 0);
if ($locationId <= 0) {
    $_SESSION['marketing_take_flash'] = ['message' => 'Storage location not set.', 'type' => 'danger'];
    header('Location: marketing_take_approve_history.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reverse'])) {
    $rejectReason = trim($_POST['reject_reason'] ?? '');
    if ($rejectReason === '') {
        $_SESSION['marketing_take_flash'] = ['message' => 'Please enter a reason for reversing.', 'type' => 'danger'];
        header('Location: marketing_take_approve_reverse.php?id=' . $id);
        exit;
    }
    try {
        $pdo->beginTransaction();
        $soCols = $pdo->query("SHOW COLUMNS FROM stock_operations")->fetchAll(PDO::FETCH_COLUMN);
        $hasProductId = in_array('product_id', $soCols);
        foreach ($items as $it) {
            $qty = (float)$it['quantity_taken'];
            $prodId = (int)$it['product_id'];
            $prodName = $it['product_name'];
            upsertInventoryQuantity($pdo, $prodId, $prodName, $qty, $locationId, $userId);
            $notes = "Marketing approval reversed " . ($take['take_code'] ?? 'MT-#'.$id) . " for {$take['event_name']}: {$prodName} (reason: {$rejectReason})";
            if ($hasProductId) {
                $pdo->prepare("INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, 'marketing_reversal', ?, 'marketing_take', ?, ?, ?)")
                    ->execute([$prodId, $locationId, $qty, $id, $notes, $userId]);
            } else {
                $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'marketing_reversal', ?, 'marketing_take', ?, ?, ?)")
                    ->execute([$locationId, $qty, $id, $notes, $userId]);
            }
        }
        $pdo->prepare("UPDATE marketing_takes SET status = 'rejected', approved_by = ?, approved_at = NOW(), reject_reason = ?, approve_note = NULL, storage_location_id = NULL WHERE id = ?")
            ->execute([$userId, $rejectReason, $id]);
        $pdo->commit();
        $reversedByName = $currentUser['name'] ?? $currentUser['username'] ?? 'User';
        send_marketing_approve_reply_to_telegram($pdo, $id, false, $reversedByName, 'Reversed: ' . $rejectReason);
        $_SESSION['marketing_take_flash'] = ['message' => 'Approval reversed. Stock returned. Reply sent to Telegram.', 'type' => 'success'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['marketing_take_flash'] = ['message' => 'Error: ' . $e->getMessage(), 'type' => 'danger'];
    }
    header('Location: marketing_take_approve_history.php');
    exit;
}

$current = 'marketing_take_approve_reverse.php';
require_once __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4">
        <div class="mb-4">
            <a href="marketing_take_approve_history.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <strong><i class="bi bi-arrow-counterclockwise me-2"></i>Reverse Approval</strong>
            </div>
            <div class="card-body">
                <p class="text-muted"><?= htmlspecialchars($take['display_code']) ?>: <?= htmlspecialchars($take['event_name']) ?></p>
                <p>This will return stock to storage and mark as <strong>Rejected</strong>. Only allowed when no reconciliation has been done.</p>
                <form method="POST">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="reverse" value="1">
                    <div class="mb-3">
                        <label class="form-label">Reason for reversing <span class="text-danger">*</span></label>
                        <textarea name="reject_reason" class="form-control" rows="3" required placeholder="e.g. Event cancelled"></textarea>
                    </div>
                    <button type="submit" class="btn btn-warning" onclick="return confirm('Reverse approval? Stock will be returned to storage.');">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reverse to Rejected
                    </button>
                    <a href="marketing_take_approve_history.php" class="btn btn-outline-secondary">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
