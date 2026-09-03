<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take.create');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/marketing_take_functions.php';

$pdo = get_db_connection();
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);

$message = '';
$messageType = '';

// Get products (normal items only, not sets)
$products = $pdo->query("SELECT id, name FROM products WHERE COALESCE(product_type,'normal') != 'set' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_take'])) {
    $eventName = trim($_POST['event_name'] ?? '');
    $eventDate = trim($_POST['event_date'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $items = $_POST['items'] ?? [];

    if (empty($eventName) || empty($eventDate)) {
        $message = 'Event name and date are required.';
        $messageType = 'danger';
    } else {
        $validItems = [];
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (float)($it['quantity'] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $validItems[] = ['product_id' => $pid, 'quantity' => $qty];
            }
        }
        if (empty($validItems)) {
            $message = 'Add at least one product with quantity.';
            $messageType = 'danger';
        } else {
            try {
                $pdo->beginTransaction();
                $takeCode = generate_marketing_take_code($pdo);
                $stmt = $pdo->prepare("INSERT INTO marketing_takes (take_code, event_name, event_date, location, notes, created_by, status) VALUES (?, ?, ?, ?, ?, ?, 'pending_approval')");
                $stmt->execute([$takeCode, $eventName, $eventDate, $location, $notes, $userId]);
                $takeId = (int)$pdo->lastInsertId();

                $itemStmt = $pdo->prepare("INSERT INTO marketing_take_items (marketing_take_id, product_id, quantity_taken) VALUES (?, ?, ?)");
                foreach ($validItems as $v) {
                    $itemStmt->execute([$takeId, $v['product_id'], $v['quantity']]);
                }
                $pdo->commit();

                // Send notification to Telegram (approve on website)
                $createdByStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $createdByStmt->execute([$userId]);
                $createdByName = $createdByStmt->fetchColumn() ?: 'Unknown';
                $itemsForTelegram = $pdo->prepare("SELECT p.name as product_name, mti.quantity_taken FROM marketing_take_items mti JOIN products p ON mti.product_id = p.id WHERE mti.marketing_take_id = ?");
                $itemsForTelegram->execute([$takeId]);
                $itemsForTelegram = $itemsForTelegram->fetchAll(PDO::FETCH_ASSOC);
                send_marketing_suggest_to_telegram($pdo, $takeId, $takeCode, $eventName, $eventDate, $location ?: null, $notes ?: null, $itemsForTelegram, $createdByName);

                $_SESSION['marketing_take_flash'] = ['message' => 'Market take request created. Notification sent to Telegram. Approve on website.', 'type' => 'success'];
                header('Location: marketing_take_list.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

$current = 'marketing_take_create.php';
require_once __DIR__ . '/../layout/header.php';
?>
<style>
/* Create Market Take - Mobile-first responsive */
.marketing-create .form-label { font-weight: 600; color: #374151; }
.marketing-create .form-control, .marketing-create .form-select { border-radius: 8px; }
.marketing-create .form-control:focus, .marketing-create .form-select:focus {
    border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}
.marketing-create .card-event { border-left: 4px solid #2563eb; }
.marketing-create .card-products { border-left: 4px solid #059669; }
.marketing-create .item-row { background: #f8fafc; border-radius: 8px; padding: 0.75rem; margin-bottom: 0.75rem; }
.marketing-create .btn-submit { background: #2563eb; border-color: #2563eb; min-height: 48px; }
.marketing-create .btn-add-row { min-height: 44px; }
@media (max-width: 576px) {
    .marketing-create .item-row .col-6, .marketing-create .item-row .col-4 { flex: 0 0 100%; max-width: 100%; }
    .marketing-create .btn-remove-row { margin-top: 0.5rem; }
}
</style>

<div class="d-flex flex-column min-vh-100 marketing-create">
    <div class="container-fluid py-3 py-md-4 flex-grow-1">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-10 col-xl-8">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2 mb-3 mb-md-4">
                    <div class="d-flex gap-2">
                        <a href="marketing_take_list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
                        <a href="marketing_take_list.php" class="btn btn-outline-primary btn-sm" title="View History"><i class="bi bi-clock-history me-1"></i>History</a>
                    </div>
                </div>
                <div class="mb-3 mb-md-4">
                    <h2 class="h4 fw-bold text-dark mb-1"><i class="bi bi-plus-circle-fill text-primary me-2"></i>Create Market Take</h2>
                    <p class="text-muted mb-0 small">Submit a request for products. Stock Controller must approve before Stock Out.</p>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-<?= $messageType === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill' ?> me-2 fs-5"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="create_take" value="1">

                    <div class="card card-event shadow-sm mb-4">
                        <div class="card-header bg-primary bg-opacity-10 py-3">
                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-calendar-event me-2"></i>Event Details</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Event Name <span class="text-danger">*</span></label>
                                    <input type="text" name="event_name" class="form-control form-control-lg" value="<?= htmlspecialchars($_POST['event_name'] ?? '') ?>" required placeholder="e.g. Siem Reap Fair 2026" autocomplete="off">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Event Date <span class="text-danger">*</span></label>
                                    <input type="date" name="event_date" class="form-control form-control-lg" value="<?= htmlspecialchars($_POST['event_date'] ?? date('Y-m-d')) ?>" required>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Location</label>
                                    <input type="text" name="location" class="form-control form-control-lg" value="<?= htmlspecialchars($_POST['location'] ?? '') ?>" placeholder="Event location" autocomplete="off">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card card-products shadow-sm mb-4">
                        <div class="card-header bg-success bg-opacity-10 py-3">
                            <h6 class="mb-0 fw-bold text-success"><i class="bi bi-box-seam me-2"></i>Products</h6>
                        </div>
                        <div class="card-body">
                            <div id="itemsContainer">
                                <div class="item-row row g-2 align-items-end">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Product <span class="text-danger">*</span></label>
                                        <select name="items[0][product_id]" class="form-select product-select">
                                            <option value="">-- Select Product --</option>
                                            <?php foreach ($products as $p): ?>
                                            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-8 col-md-4">
                                        <label class="form-label">Quantity <span class="text-danger">*</span></label>
                                        <input type="number" name="items[0][quantity]" class="form-control" min="0.01" step="0.01" placeholder="0" inputmode="decimal">
                                    </div>
                                    <div class="col-4 col-md-2">
                                        <button type="button" class="btn btn-outline-danger btn-remove-row w-100" style="display:none;"><i class="bi bi-trash"></i></button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-add-row w-100 w-md-auto mt-2" id="addRow"><i class="bi bi-plus-lg me-1"></i>Add Product</button>
                        </div>
                    </div>

                    <div class="d-flex flex-column flex-sm-row gap-2">
                        <button type="submit" class="btn btn-primary btn-submit flex-grow-1 flex-sm-grow-0"><i class="bi bi-check-lg me-2"></i>Submit Request</button>
                        <a href="marketing_take_list.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let rowIdx = 1;
    const container = document.getElementById('itemsContainer');
    const addBtn = document.getElementById('addRow');
    const productOptions = <?= json_encode(array_map(fn($p) => ['id'=>$p['id'],'name'=>$p['name']], $products)) ?>;

    addBtn.addEventListener('click', function() {
        const html = `
            <div class="item-row row g-2 align-items-end">
                <div class="col-12 col-md-6">
                    <label class="form-label">Product <span class="text-danger">*</span></label>
                    <select name="items[${rowIdx}][product_id]" class="form-select product-select">
                        <option value="">-- Select Product --</option>
                        ${productOptions.map(p => '<option value="'+p.id+'">'+p.name+'</option>').join('')}
                    </select>
                </div>
                <div class="col-8 col-md-4">
                    <label class="form-label">Quantity <span class="text-danger">*</span></label>
                    <input type="number" name="items[${rowIdx}][quantity]" class="form-control" min="0.01" step="0.01" placeholder="0" inputmode="decimal">
                </div>
                <div class="col-4 col-md-2">
                    <button type="button" class="btn btn-outline-danger btn-remove-row w-100"><i class="bi bi-trash"></i></button>
                </div>
            </div>`;
        container.insertAdjacentHTML('beforeend', html);
        rowIdx++;
        toggleRemoveBtns();
    });

    container.addEventListener('click', function(e) {
        if (e.target.closest('.btn-remove-row')) {
            e.target.closest('.item-row').remove();
            toggleRemoveBtns();
        }
    });

    function toggleRemoveBtns() {
        const rows = container.querySelectorAll('.item-row');
        rows.forEach((r, i) => {
            const btn = r.querySelector('.btn-remove-row');
            if (btn) btn.style.display = rows.length > 1 ? 'inline-block' : 'none';
        });
    }
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
