<?php
// Maintenance mode page
session_start();
require_once __DIR__ . '/auth.php';
require_role_or_permission(['admin'], 'maintenance.view');
if (!has_permission('maintenance.toggle') && (current_user()['username'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied');
}

// Toggle maintenance mode
$maintenanceFile = __DIR__ . '/.maintenance';
$maintenanceEnabled = file_exists($maintenanceFile);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'enable' && !$maintenanceEnabled) {
        file_put_contents($maintenanceFile, '1');
        $maintenanceEnabled = true;
    } elseif ($_POST['action'] === 'disable' && $maintenanceEnabled) {
        unlink($maintenanceFile);
        $maintenanceEnabled = false;
    }
    
    // Redirect to avoid form resubmission
    header('Location: maintenance.php');
    exit;
}

// Get base URL for navigation
$current = 'maintenance.php';

require_once __DIR__ . '/layout/header.php';

?>

<div class="container-fluid py-3">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">
                        <i class="bi bi-tools me-2"></i>
                        Website Maintenance
                    </h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-info d-flex align-items-center" role="alert">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <div>
                            Maintenance mode will display a maintenance page to all non-admin users. 
                            Admins can still access the system normally.
                        </div>
                    </div>
                    
                    <div class="text-center mb-4">
                        <div class="mb-3">
                            <i class="bi bi-gear-fill display-1 text-<?= $maintenanceEnabled ? 'danger' : 'success' ?>"></i>
                        </div>
                        <h5 class="mb-2">
                            Maintenance Mode is 
                            <span class="badge bg-<?= $maintenanceEnabled ? 'danger' : 'success' ?> fs-6">
                                <?= $maintenanceEnabled ? 'ENABLED' : 'DISABLED' ?>
                            </span>
                        </h5>
                        <p class="text-muted">
                            <?= $maintenanceEnabled 
                                ? 'The website is currently under maintenance. Regular users cannot access the system.' 
                                : 'The website is running normally. All users can access the system.' 
                            ?>
                        </p>
                    </div>
                    
                    <form method="post" class="d-grid">
                        <?php if ($maintenanceEnabled): ?>
                            <button type="submit" name="action" value="disable" class="btn btn-success btn-lg">
                                <i class="bi bi-unlock me-2"></i>
                                Disable Maintenance Mode
                            </button>
                        <?php else: ?>
                            <button type="submit" name="action" value="enable" class="btn btn-danger btn-lg">
                                <i class="bi bi-lock me-2"></i>
                                Enable Maintenance Mode
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between">
                        <a href="admin/daily_summary.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>
                            Back to Dashboard
                        </a>
                        <small class="text-muted">
                            Logged in as: <?= htmlspecialchars(current_user()['username'] ?? 'Unknown') ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
