<?php
/**
 * Mark which product sets are offered as "Lucky box" on seller new order.
 */

require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'lucky_box_sets.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();
$errors = [];
$success = '';

try {
    $check = $pdo->query("SHOW COLUMNS FROM product_sets LIKE 'is_lucky_box'");
    if ($check->rowCount() === 0) {
        $pdo->exec("ALTER TABLE product_sets ADD COLUMN is_lucky_box TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Seller lucky box picker' AFTER is_active");
    }
} catch (PDOException $e) {
    $errors[] = 'Could not ensure is_lucky_box column: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_lucky_box') {
    require_role_or_permission(['admin'], 'lucky_box_sets.update');
    $setId = (int)($_POST['set_id'] ?? 0);
            $isLucky = (($_POST['is_lucky'] ?? '') === '1') ? 1 : 0;
            if ($setId > 0) {
                try {
                    $stmt = $pdo->prepare('UPDATE product_sets SET is_lucky_box = ? WHERE id = ?');
                    $stmt->execute([$isLucky, $setId]);
                    $retMonth = trim((string)($_POST['return_month'] ?? ''));
                    $monthQ = '';
                    if (preg_match('/^\d{4}-\d{2}$/', $retMonth)) {
                        $monthQ = '&month=' . rawurlencode($retMonth);
                    }
                    header('Location: lucky_box_sets.php?saved=1' . $monthQ);
                    exit;
        } catch (PDOException $e) {
            $errors[] = 'Update failed: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $success = 'Lucky box settings saved.';
}

$monthRaw = isset($_GET['month']) ? trim((string)$_GET['month']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monthRaw)) {
    $costMonth = date('Y-m');
} else {
    $costMonth = $monthRaw;
}
$costMonthLabel = date('F Y', strtotime($costMonth . '-01'));

$sets = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            ps.id,
            ps.set_name,
            ps.selling_price AS set_selling_price,
            COALESCE(pc.selling_price, p.cost) AS month_selling_price,
            ps.is_active,
            COALESCE(ps.is_lucky_box, 0) AS is_lucky_box,
            p.id AS sellable_product_id,
            pc.month_year AS cost_month
        FROM product_sets ps
        INNER JOIN products p
            ON p.name = ps.set_name
            AND COALESCE(NULLIF(p.product_type, ''), 'normal') = 'set'
        INNER JOIN product_costs pc
            ON p.id = pc.product_id AND pc.month_year = :m1
        WHERE COALESCE(ps.is_active, 1) = 1
        AND p.active = 1
        AND p.id IN (
            SELECT DISTINCT product_id FROM product_costs WHERE month_year <= :m2
        )
        ORDER BY ps.set_name ASC
    ");
    $stmt->execute(['m1' => $costMonth, 'm2' => $costMonth]);
    $sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = 'Could not load product sets: ' . $e->getMessage();
}

require_once __DIR__ . '/../layout/header.php';
?>
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-12 col-xl-10">
            <h1 class="h4 mb-3"><i class="bi bi-gift me-2"></i>Lucky box sets</h1>
            <p class="text-muted mb-3">
                Only <strong>active product sets</strong> that have <strong>Product costs</strong> for the selected month are listed (same rule as the seller Lucky box picker).
                Choose which of these appear when a seller picks <strong>Lucky box</strong> on new order.
            </p>

            <form method="get" class="row row-cols-sm-auto g-2 align-items-end mb-4">
                <div class="col-12 col-md-auto">
                    <label class="form-label small text-muted mb-1" for="costMonth">Product costs month</label>
                    <input type="month" class="form-control" id="costMonth" name="month" value="<?= htmlspecialchars($costMonth, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-outline-primary">Show sets</button>
                </div>
                <div class="col-12 col-md text-muted small align-self-center">
                    Viewing: <strong><?= htmlspecialchars($costMonthLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
            </form>

            <?php foreach ($errors as $err): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width:3.5rem;">No</th>
                                    <th>Set name</th>
                                    <th class="text-end">Price (<?= htmlspecialchars($costMonth, ENT_QUOTES, 'UTF-8') ?>)</th>
                                    <th class="text-end text-muted small">Set table price</th>
                                    <th class="text-center">Active</th>
                                    <th class="text-center">Sellable product</th>
                                    <th class="text-center">Lucky box</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$sets): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            No product sets with costs for <strong><?= htmlspecialchars($costMonthLabel, ENT_QUOTES, 'UTF-8') ?></strong>.
                                            Add month costs for set products under Product costs, or choose another month above.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $rowNo = 1; foreach ($sets as $row): ?>
                                        <tr>
                                            <td class="text-center text-muted"><?= $rowNo++ ?></td>
                                            <td><?= htmlspecialchars($row['set_name']) ?></td>
                                            <td class="text-end fw-semibold">$<?= htmlspecialchars(number_format((float)($row['month_selling_price'] ?? 0), 2)) ?></td>
                                            <td class="text-end text-muted small">$<?= htmlspecialchars(number_format((float)($row['set_selling_price'] ?? 0), 2)) ?></td>
                                            <td class="text-center">
                                                <?php if (!empty($row['is_active'])): ?>
                                                    <span class="badge bg-success">Yes</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">No</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if (!empty($row['sellable_product_id'])): ?>
                                                    <span class="badge bg-primary">#<?= (int)$row['sellable_product_id'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark" title="No matching products row with product_type=set and same name">Missing</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="action" value="set_lucky_box">
                                                    <input type="hidden" name="set_id" value="<?= (int)$row['id'] ?>">
                                                    <input type="hidden" name="is_lucky" value="<?= !empty($row['is_lucky_box']) ? '0' : '1' ?>">
                                                    <input type="hidden" name="return_month" value="<?= htmlspecialchars($costMonth, ENT_QUOTES, 'UTF-8') ?>">
                                                    <button type="submit" class="btn btn-sm <?= !empty($row['is_lucky_box']) ? 'btn-warning' : 'btn-outline-primary' ?>">
                                                        <?= !empty($row['is_lucky_box']) ? 'Remove lucky box' : 'Set as lucky box' ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
