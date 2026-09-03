<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['cashier', 'admin'], 'cashier_date.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../user_activity_lib.php';

$pdo  = get_db_connection();
$user = current_user();

// Handle QR effective date quick controls
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_date_action'])) {
    $action = $_POST['qr_date_action'];
    $today  = new DateTimeImmutable('today');

    if ($action === 'today') {
        set_qr_effective_date($pdo, $today);
        user_activity_log_module_mutation($user, 'cashier', 'update', __FILE__, 'qr_date today');
    } elseif ($action === 'tomorrow') {
        set_qr_effective_date($pdo, $today->modify('+1 day'));
        user_activity_log_module_mutation($user, 'cashier', 'update', __FILE__, 'qr_date tomorrow');
    }

    header('Location: dashboard.php');
    exit;
}

$qrEffective = get_qr_effective_date($pdo);
if ($qrEffective === null || $qrEffective < new DateTimeImmutable('today')) {
    $qrEffective = new DateTimeImmutable('today');
}

// Count orders that have not been printed yet
$stmt = $pdo->query('SELECT COUNT(*) FROM orders o LEFT JOIN print_jobs pj ON pj.order_id = o.id WHERE pj.id IS NULL');
$unprinted_count = (int)$stmt->fetchColumn();

include __DIR__ . '/../layout/header.php';
?>
<div class="row g-4 flex-grow-1">
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm gradient-card-primary text-white h-100">
            <div class="card-body d-flex flex-column justify-content-between">
                <div>
                    <h2 class="h4 mb-2">Unprinted Orders</h2>
                    <p class="mb-0 small">Orders that still need to be printed.</p>
                </div>
                <div class="display-5 fw-bold mt-3"><?= $unprinted_count ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column justify-content-between">
                <div>
                    <h2 class="h4 mb-2">QR Date for LKM Codes</h2>
                    <p class="mb-0 small">Controls the date part used in LKM-YYYYMMDDxxxx order codes (and QR text). Never uses yesterday.</p>
                </div>
                <div class="mt-3">
                    <div class="small text-muted mb-1">Current QR date</div>
                    <div class="h5 mb-3"><?= htmlspecialchars($qrEffective->format('Y-m-d')) ?></div>
                    <form method="post" class="d-flex gap-2 flex-wrap">
                        <button type="submit" name="qr_date_action" value="today" class="btn btn-outline-secondary btn-sm">Set to Today</button>
                        <button type="submit" name="qr_date_action" value="tomorrow" class="btn btn-outline-primary btn-sm">Set to Tomorrow</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column justify-content-center">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <a href="print_orders.php" class="btn btn-primary btn-lg w-100">Print Orders</a>
                    </div>
                    <div class="col-12 col-md-4">
                        <a href="print_history.php" class="btn btn-outline-primary btn-lg w-100">Print History</a>
                    </div>
                    <div class="col-12 col-md-4">
                        <a href="../profile.php" class="btn btn-outline-secondary btn-lg w-100">My Profile</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../layout/footer.php'; ?>
