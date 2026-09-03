<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'money_exchange.view');

$user = current_user();
if (!$user) {
    header('Location: ' . $BASE_URL . '/login.php');
    exit;
}

$pdo = get_db_connection();

// Create settings table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) UNIQUE NOT NULL,
    setting_value TEXT
)");

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rate = trim($_POST['rate'] ?? '');
    if (!is_numeric($rate) || $rate <= 0) {
        $errors[] = 'Please enter a valid positive exchange rate.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, created_by, updated_by) VALUES ('usd_to_khr_rate', ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$rate, $user['id'], $user['id']]);
        $success = 'Exchange rate updated successfully.';
    }
}

// Get current rate
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'usd_to_khr_rate'");
$stmt->execute();
$currentRate = $stmt->fetchColumn();
if (!$currentRate) {
    $currentRate = 4100; // default
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Money Exchange Settings</h1>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post">
                <div class="mb-3">
                    <label for="rate" class="form-label">Exchange Rate (1 USD = ? KHR)</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" id="rate" name="rate" value="<?= htmlspecialchars($currentRate) ?>" required>
                    <div class="form-text">Current rate: 1 USD = <?= htmlspecialchars($currentRate) ?> KHR</div>
                </div>
                <button type="submit" class="btn btn-primary">Save Exchange Rate</button>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../layout/footer.php'; ?>
