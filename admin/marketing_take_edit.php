<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take.create');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/marketing_take_functions.php';

$pdo = get_db_connection();
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: marketing_take_list.php');
    exit;
}

$take = $pdo->prepare("SELECT * FROM marketing_takes WHERE id = ? AND status = 'pending_approval'");
$take->execute([$id]);
$take = $take->fetch(PDO::FETCH_ASSOC);
$userRoles = $currentUser ? user_role_names($pdo, $currentUser) : [];
$isAdmin = in_array('admin', $userRoles, true);
$canViewAllMarkets = $isAdmin || (function_exists('has_permission') && has_permission('marketing_take_view_all.view'));
if (!$take || (!$canViewAllMarkets && $userId > 0 && (int)$take['created_by'] !== $userId)) {
    $_SESSION['marketing_take_flash'] = ['message' => 'Market take not found or not editable.', 'type' => 'warning'];
    header('Location: marketing_take_list.php');
    exit;
}

$products = $pdo->query("SELECT id, name FROM products WHERE COALESCE(product_type,'normal') != 'set' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_take'])) {
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
                $pdo->prepare("UPDATE marketing_takes SET event_name = ?, event_date = ?, location = ?, notes = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$eventName, $eventDate, $location, $notes, $userId, $id]);
                $pdo->prepare("DELETE FROM marketing_take_items WHERE marketing_take_id = ?")->execute([$id]);
                $itemStmt = $pdo->prepare("INSERT INTO marketing_take_items (marketing_take_id, product_id, quantity_taken) VALUES (?, ?, ?)");
                foreach ($validItems as $v) {
                    $itemStmt->execute([$id, $v['product_id'], $v['quantity']]);
                }
                $pdo->commit();
                $_SESSION['marketing_take_flash'] = ['message' => 'Market take updated.', 'type' => 'success'];
                header('Location: marketing_take_list.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
} else {
    $items = $pdo->prepare("SELECT product_id, quantity_taken FROM marketing_take_items WHERE marketing_take_id = ? ORDER BY id");
    $items->execute([$id]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);
}

$current = 'marketing_take_edit.php';
require_once __DIR__ . '/../layout/header.php';

$eventName = $_POST['event_name'] ?? $take['event_name'] ?? '';
$eventDate = $_POST['event_date'] ?? $take['event_date'] ?? '';
$location = $_POST['location'] ?? $take['location'] ?? '';
$notes = $_POST['notes'] ?? $take['notes'] ?? '';
$postItems = $_POST['items'] ?? [];
$displayItems = !empty($postItems) ? $postItems : array_map(fn($r) => ['product_id' => $r['product_id'], 'quantity' => $r['quantity_taken']], $items);
?>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4 flex-grow-1">
        <div class="row">
            <div class="col-12">
                <div class="mb-4">
                    <a href="marketing_take_list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
                </div>
                <h2><i class="bi bi-pencil me-2"></i>Edit Market Take</h2>
                <p class="text-muted"><?= htmlspecialchars($take['take_code'] ?? 'MT-#'.$id) ?> — Editable until approved.</p>

                <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="id" value="<?= (int)$id ?>">
                            <input type="hidden" name="update_take" value="1">
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Event Name <span class="text-danger">*</span></label>
                                    <input type="text" name="event_name" class="form-control" value="<?= htmlspecialchars($eventName) ?>" required placeholder="e.g. Siem Reap Fair 2026">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Event Date <span class="text-danger">*</span></label>
                                    <input type="date" name="event_date" class="form-control" value="<?= htmlspecialchars($eventDate) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Location</label>
                                    <input type="text" name="location" class="form-control" value="<?= htmlspecialchars($location) ?>" placeholder="Event location">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($notes) ?></textarea>
                                </div>
                            </div>

                            <h6 class="mb-3">Products</h6>
                            <div id="itemsContainer">
                                <?php foreach (array_values($displayItems) as $i => $it): ?>
                                <div class="item-row row g-2 mb-2 align-items-end">
                                    <div class="col-md-6">
                                        <label class="form-label">Product</label>
                                        <select name="items[<?= $i ?>][product_id]" class="form-select product-select">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($products as $p): ?>
                                            <option value="<?= (int)$p['id'] ?>" <?= ($it['product_id'] ?? '') == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Quantity</label>
                                        <input type="number" name="items[<?= $i ?>][quantity]" class="form-control" min="0.01" step="0.01" value="<?= htmlspecialchars($it['quantity'] ?? '') ?>" placeholder="0">
                                    </div>
                                    <div class="col-md-3">
                                        <button type="button" class="btn btn-outline-danger btn-remove-row <?= count($displayItems) <= 1 ? 'd-none' : '' ?>"><i class="bi bi-trash"></i></button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm mb-4" id="addRow"><i class="bi bi-plus me-1"></i>Add Product</button>

                            <hr>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let rowIdx = <?= count($displayItems) ?>;
    const container = document.getElementById('itemsContainer');
    const addBtn = document.getElementById('addRow');
    const productOptions = <?= json_encode(array_map(fn($p) => ['id'=>$p['id'],'name'=>$p['name']], $products)) ?>;

    addBtn.addEventListener('click', function() {
        const html = `
            <div class="item-row row g-2 mb-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Product</label>
                    <select name="items[${rowIdx}][product_id]" class="form-select product-select">
                        <option value="">-- Select --</option>
                        ${productOptions.map(p => '<option value="'+p.id+'">'+p.name+'</option>').join('')}
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Quantity</label>
                    <input type="number" name="items[${rowIdx}][quantity]" class="form-control" min="0.01" step="0.01" placeholder="0">
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-outline-danger btn-remove-row"><i class="bi bi-trash"></i></button>
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
