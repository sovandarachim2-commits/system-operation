<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_payments.view');
require_once __DIR__ . '/../upload_paths.php';

// Image compression function
function compressImage($source, $destination, $extension) {
    // Check if GD library is available
    if (!extension_loaded('gd')) {
        // GD library not available, just move the file
        return move_uploaded_file($source, $destination);
    }
    
    $quality = 60; // Lower quality for better storage optimization
    
    try {
        if ($extension === 'jpeg' || $extension === 'jpg') {
            if (!function_exists('imagecreatefromjpeg')) {
                return move_uploaded_file($source, $destination);
            }
            $image = imagecreatefromjpeg($source);
            if ($image) {
                imagejpeg($image, $destination, $quality);
                imagedestroy($image);
                return true;
            }
        } elseif ($extension === 'png') {
            if (!function_exists('imagecreatefrompng')) {
                return move_uploaded_file($source, $destination);
            }
            $image = imagecreatefrompng($source);
            if ($image) {
                // For PNG, quality is 0-9 (0 = no compression, 9 = max compression)
                $png_quality = 9 - round($quality / 11.11);
                imagepng($image, $destination, $png_quality);
                imagedestroy($image);
                return true;
            }
        } elseif ($extension === 'gif') {
            if (!function_exists('imagecreatefromgif')) {
                return move_uploaded_file($source, $destination);
            }
            $image = imagecreatefromgif($source);
            if ($image) {
                imagegif($image, $destination);
                imagedestroy($image);
                return true;
            }
        }
        
        // If we get here, compression failed, try to move the original file
        return move_uploaded_file($source, $destination);
        
    } catch (Exception $e) {
        // If compression fails, try to move the original file
        return move_uploaded_file($source, $destination);
    }
}

$pdo = get_db_connection();

$errors = [];
$success = '';

// Check for success message from URL parameter
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_payment') {
        require_role_or_permission(['admin'], 'purchase_payments.create');
        $purchase_order_id = (int)($_POST['purchase_order_id'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
        $payment_amount = (float)($_POST['payment_amount'] ?? '');
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $receipt_image = '';
        
        // Handle receipt image upload with compression
        $receipt_images = [];
        
        if (isset($_FILES['receipt_images']) && is_array($_FILES['receipt_images']['name'])) {
            foreach ($_FILES['receipt_images']['name'] as $key => $name) {
                if ($_FILES['receipt_images']['error'][$key] === UPLOAD_ERR_OK && !empty($name)) {
                    $file_extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
                    
                    if (in_array($file_extension, $allowed_extensions)) {
                        $file_name = 'receipt_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.' . $file_extension;
                        
                        // Compress image if it's not PDF
                        if ($file_extension !== 'pdf') {
                            $tempCompressed = tempnam(sys_get_temp_dir(), 'payment_receipt_');
                            $upload_path = $tempCompressed;
                            $compressed_path = compressImage($_FILES['receipt_images']['tmp_name'][$key], $upload_path, $file_extension);
                            if ($compressed_path) {
                                $storedPath = upload_store_file_path($tempCompressed, 'payment_receipts', $file_name, $payment_date, (string)($_FILES['receipt_images']['type'][$key] ?? 'image/jpeg'), false);
                                @unlink($tempCompressed);
                                $receipt_images[] = preg_replace('#^uploads/payment_receipts/#', '', $storedPath);
                            }
                        } else {
                            $storedPath = upload_store_uploaded_file([
                                'error' => $_FILES['receipt_images']['error'][$key],
                                'tmp_name' => $_FILES['receipt_images']['tmp_name'][$key],
                                'type' => $_FILES['receipt_images']['type'][$key] ?? 'application/pdf',
                            ], 'payment_receipts', $file_name, $payment_date, (string)($_FILES['receipt_images']['type'][$key] ?? 'application/pdf'));
                            if ($storedPath !== '') {
                                $receipt_images[] = preg_replace('#^uploads/payment_receipts/#', '', $storedPath);
                            }
                        }
                    } else {
                        $errors[] = 'Invalid file type: ' . $name . '. Allowed: JPG, PNG, GIF, PDF';
                    }
                }
            }
        }
        
        if (empty($receipt_images)) {
            $errors[] = 'At least one receipt image is required.';
        }
        
        if ($purchase_order_id <= 0 || $payment_amount <= 0) {
            $errors[] = 'Purchase order and payment amount are required.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Generate payment number
                $payment_number = 'PAY-' . date('Y') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                
                // Add payment
                $stmt = $pdo->prepare('INSERT INTO purchase_payments (purchase_order_id, payment_number, payment_date, payment_method, payment_amount, reference_number, notes, receipt_image, paid_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $user = current_user();
                $receipt_image_json = json_encode($receipt_images);
                $stmt->execute([$purchase_order_id, $payment_number, $payment_date, $payment_method, $payment_amount, $reference_number, $notes, $receipt_image_json, $user['id']]);
                
                // Update order payment status
                $stmt = $pdo->prepare('UPDATE purchase_orders SET total_paid = total_paid + ?, payment_status = CASE WHEN total_paid + ? >= total_amount THEN "paid" WHEN total_paid + ? > 0 THEN "partial" ELSE "unpaid" END WHERE id = ?');
                $stmt->execute([$payment_amount, $payment_amount, $payment_amount, $purchase_order_id]);
                
                $pdo->commit();
                $success = "Payment $payment_number recorded successfully.";
                
                // Redirect to prevent form resubmission on refresh
                header("Location: purchase_payments.php?success=" . urlencode($success));
                exit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Failed to add payment: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
    
    if ($action === 'edit_payment') {
        require_role_or_permission(['admin'], 'purchase_payments.update');
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? '';
        $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
        $payment_amount = (float)($_POST['payment_amount'] ?? 0);
        $payment_status = $_POST['payment_status'] ?? 'pending';
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($payment_id <= 0 || $payment_amount <= 0 || empty($payment_date)) {
            $errors[] = 'Payment ID, amount, and date are required.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get current payment details
                $stmt = $pdo->prepare('SELECT * FROM purchase_payments WHERE id = ?');
                $stmt->execute([$payment_id]);
                $current_payment = $stmt->fetch();
                
                if (!$current_payment) {
                    $errors[] = 'Payment not found.';
                } else {
                    // Update payment
                    $stmt = $pdo->prepare('UPDATE purchase_payments SET payment_date = ?, payment_method = ?, payment_amount = ?, payment_status = ?, reference_number = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                    $stmt->execute([$payment_date, $payment_method, $payment_amount, $payment_status, $reference_number, $notes, $payment_id]);
                    
                    // Recalculate order payment status
                    $stmt = $pdo->prepare('
                        UPDATE purchase_orders po SET 
                            total_paid = (
                                SELECT COALESCE(SUM(pp.payment_amount), 0) 
                                FROM purchase_payments pp 
                                WHERE pp.purchase_order_id = po.id AND pp.payment_status = "completed"
                            ),
                            payment_status = CASE 
                                WHEN total_paid >= total_amount THEN "paid"
                                WHEN total_paid > 0 THEN "partial"
                                ELSE "unpaid"
                            END
                        WHERE po.id = ?
                    ');
                    $stmt->execute([$current_payment['purchase_order_id']]);
                    
                    $pdo->commit();
                    $success = "Payment updated successfully.";
                    
                    // Redirect to prevent form resubmission on refresh
                    header("Location: purchase_payments.php?success=" . urlencode($success));
                    exit();
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Failed to update payment: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
    
    if ($action === 'delete_payment') {
        require_role_or_permission(['admin'], 'purchase_payments.delete');
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        
        if ($payment_id <= 0) {
            $errors[] = 'Payment ID is required.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get payment details
                $stmt = $pdo->prepare('SELECT * FROM purchase_payments WHERE id = ?');
                $stmt->execute([$payment_id]);
                $payment = $stmt->fetch();
                
                if ($payment) {
                    // Delete payment
                    $stmt = $pdo->prepare('DELETE FROM purchase_payments WHERE id = ?');
                    $stmt->execute([$payment_id]);
                    
                    // Update order payment status
                    $stmt = $pdo->prepare('UPDATE purchase_orders SET total_paid = total_paid - ?, payment_status = CASE WHEN total_paid - ? >= total_amount THEN "paid" WHEN total_paid - ? > 0 THEN "partial" ELSE "unpaid" END WHERE id = ?');
                    $stmt->execute([$payment['payment_amount'], $payment['payment_amount'], $payment['payment_amount'], $payment['purchase_order_id']]);
                    
                    $success = "Payment deleted successfully.";
                    
                    // Redirect to prevent form resubmission on refresh
                    header("Location: purchase_payments.php?success=" . urlencode($success));
                    exit();
                } else {
                    $errors[] = 'Payment not found.';
                }
                
                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Failed to delete payment: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Get filter parameters
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$filter_method = $_GET['filter_method'] ?? '';
$filter_vendor = $_GET['filter_vendor'] ?? '';
$filter_amount_min = $_GET['filter_amount_min'] ?? '';
$filter_amount_max = $_GET['filter_amount_max'] ?? '';
$filter_search = $_GET['filter_search'] ?? '';

// Build filter query
$filter_conditions = [];
$filter_params = [];

if (!empty($filter_date_from)) {
    $filter_conditions[] = 'pp.payment_date >= ?';
    $filter_params[] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $filter_conditions[] = 'pp.payment_date <= ?';
    $filter_params[] = $filter_date_to;
}

if (!empty($filter_status)) {
    $filter_conditions[] = 'pp.payment_status = ?';
    $filter_params[] = $filter_status;
}

if (!empty($filter_method)) {
    $filter_conditions[] = 'pp.payment_method = ?';
    $filter_params[] = $filter_method;
}

if (!empty($filter_vendor)) {
    $filter_conditions[] = 'po.vendor_id = ?';
    $filter_params[] = $filter_vendor;
}

if (!empty($filter_amount_min)) {
    $filter_conditions[] = 'pp.payment_amount >= ?';
    $filter_params[] = $filter_amount_min;
}

if (!empty($filter_amount_max)) {
    $filter_conditions[] = 'pp.payment_amount <= ?';
    $filter_params[] = $filter_amount_max;
}

if (!empty($filter_search)) {
    $filter_conditions[] = '(pp.payment_number LIKE ? OR pp.reference_number LIKE ? OR pp.notes LIKE ?)';
    $search_param = '%' . $filter_search . '%';
    $filter_params[] = $search_param;
    $filter_params[] = $search_param;
    $filter_params[] = $search_param;
}

$filter_where = !empty($filter_conditions) ? 'WHERE ' . implode(' AND ', $filter_conditions) : '';

// Build filter for payment summary (view has order-level columns only: order_date, vendor_id, payment_status)
$summary_filter_conditions = [];
$summary_filter_params = [];
if (!empty($filter_date_from)) {
    $summary_filter_conditions[] = 'order_date >= ?';
    $summary_filter_params[] = $filter_date_from;
}
if (!empty($filter_date_to)) {
    $summary_filter_conditions[] = 'order_date <= ?';
    $summary_filter_params[] = $filter_date_to;
}
if (!empty($filter_vendor)) {
    $summary_filter_conditions[] = 'vendor_id = ?';
    $summary_filter_params[] = $filter_vendor;
}
if (!empty($filter_status)) {
    $statusMap = ['completed' => 'paid', 'pending' => ['unpaid', 'partial']];
    $mapped = $statusMap[$filter_status] ?? null;
    if ($mapped !== null) {
        if (is_array($mapped)) {
            $summary_filter_conditions[] = 'payment_status IN (?, ?)';
            $summary_filter_params[] = $mapped[0];
            $summary_filter_params[] = $mapped[1];
        } else {
            $summary_filter_conditions[] = 'payment_status = ?';
            $summary_filter_params[] = $mapped;
        }
    }
}
$summary_filter_where = !empty($summary_filter_conditions) ? 'WHERE ' . implode(' AND ', $summary_filter_conditions) : '';

// Get purchase orders for dropdown
try {
    $ordersStmt = $pdo->query('SELECT po.*, pv.name as vendor_name FROM purchase_orders po LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id ORDER BY po.order_date DESC');
    $orders = $ordersStmt->fetchAll();
} catch (PDOException $e) {
    $orders = [];
    $errors[] = 'Purchase orders not available.';
}

// Get vendors for filter dropdown
try {
    $vendorsStmt = $pdo->query('SELECT DISTINCT pv.id, pv.name FROM purchase_vendors pv JOIN purchase_orders po ON pv.id = po.vendor_id ORDER BY pv.name');
    $vendors = $vendorsStmt->fetchAll();
} catch (PDOException $e) {
    $vendors = [];
}

// Get payment methods for filter dropdown
$payment_methods = [];
try {
    $paymentMethodsStmt = $pdo->query('SELECT method_code, method_name, icon, is_default FROM payment_methods WHERE is_active = TRUE ORDER BY sort_order, method_name');
    $payment_methods = $paymentMethodsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payment_methods = [];
    error_log("Database error loading payment methods: " . $e->getMessage());
}

// Get payment summary with filters (uses order-level columns only)
try {
    $summarySql = "SELECT * FROM purchase_payment_summary $summary_filter_where ORDER BY due_date ASC";
    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($summary_filter_params);
    $summary = $summaryStmt->fetchAll();
} catch (PDOException $e) {
    $summary = [];
    $errors[] = 'Payment summary not available.';
}

// Get recent payments with filters
try {
    $paymentsSql = "
        SELECT pp.*, po.order_number, pv.name as vendor_name, u.name as created_by_name
        FROM purchase_payments pp 
        LEFT JOIN purchase_orders po ON pp.purchase_order_id = po.id 
        LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id 
        LEFT JOIN users u ON pp.paid_by = u.id
        $filter_where
        ORDER BY pp.payment_date DESC 
        LIMIT 50
    ";
    $paymentsStmt = $pdo->prepare($paymentsSql);
    $paymentsStmt->execute($filter_params);
    $recent_payments = $paymentsStmt->fetchAll();
} catch (PDOException $e) {
    $recent_payments = [];
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Purchase Payments</h1>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-success btn-lg" onclick="openPaymentMethodsModal()">
                <i class="bi bi-credit-card me-2"></i>Payment Methods
            </button>
            <a href="purchase_orders.php" class="btn btn-outline-primary btn-lg">
                <i class="bi bi-file-earmark-text me-2"></i>Purchase Orders
            </a>
            <a href="purchase_reports.php" class="btn btn-outline-info btn-lg">
                <i class="bi bi-graph-up me-2"></i>Reports
            </a>
            <button type="button" class="btn btn-primary btn-lg" onclick="openPaymentModal()">
                <i class="bi bi-plus-circle me-2"></i>Add New Payment
            </button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <!-- Payment Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-funnel me-2"></i>Payment Filters
                </h5>
                <div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                        <i class="bi bi-arrow-clockwise me-1"></i>Clear Filters
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body" id="filterPanel">
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Date Range</label>
                    <div class="input-group">
                        <input type="date" name="filter_date_from" class="form-control" value="<?= htmlspecialchars($filter_date_from) ?>" placeholder="From">
                        <span class="input-group-text">to</span>
                        <input type="date" name="filter_date_to" class="form-control" value="<?= htmlspecialchars($filter_date_to) ?>" placeholder="To">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="filter_status" class="form-select">
                        <option value="">All Status</option>
                        <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>🟡 Pending</option>
                        <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>🟢 Completed</option>
                        <option value="failed" <?= $filter_status === 'failed' ? 'selected' : '' ?>>🔴 Failed</option>
                        <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>⚫ Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Payment Method</label>
                    <select name="filter_method" class="form-select">
                        <option value="">All Methods</option>
                        <?php if (!empty($payment_methods)): ?>
                            <?php foreach ($payment_methods as $method): ?>
                                <option value="<?= htmlspecialchars($method['method_code']) ?>" <?= $filter_method === $method['method_code'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($method['method_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="">No methods available</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Vendor</label>
                    <select name="filter_vendor" class="form-select">
                        <option value="">All Vendors</option>
                        <?php foreach ($vendors as $vendor): ?>
                            <option value="<?= $vendor['id'] ?>" <?= $filter_vendor == $vendor['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vendor['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" name="filter_search" class="form-control" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Payment #, Reference, Notes...">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Amount Range</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" name="filter_amount_min" class="form-control" value="<?= htmlspecialchars($filter_amount_min) ?>" placeholder="Min">
                        <span class="input-group-text">-</span>
                        <input type="number" step="0.01" name="filter_amount_max" class="form-control" value="<?= htmlspecialchars($filter_amount_max) ?>" placeholder="Max">
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search me-1"></i>Apply Filters
                    </button>
                    <?php if (!empty(array_filter($_GET, fn($k) => str_starts_with($k, 'filter_'), ARRAY_FILTER_USE_KEY))): ?>
                        <span class="badge bg-info ms-2">
                            <?= count(array_filter($_GET, fn($k) => str_starts_with($k, 'filter_') && !empty($_GET[$k]), ARRAY_FILTER_USE_KEY)) ?> filters active
                        </span>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Payment Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Total Orders</h5>
                    <h3 class="mb-0"><?= count($summary) ?></h3>
                    <small>Purchase orders</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Total Paid</h5>
                    <h3 class="mb-0">$<?= number_format(array_sum(array_column($summary, 'total_paid')), 2) ?></h3>
                    <small>Amount paid</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Balance Due</h5>
                    <h3 class="mb-0">$<?= number_format(array_sum(array_column($summary, 'balance_due')), 2) ?></h3>
                    <small>Outstanding</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Overdue</h5>
                    <h3 class="mb-0"><?= count(array_filter($summary, fn($s) => $s['urgency_status'] === 'overdue')) ?></h3>
                    <small>Overdue payments</small>
                </div>
            </div>
        </div>
    </div>

    <?php $total_return = array_sum(array_map(fn($s) => (float)($s['total_return'] ?? 0), $summary)); ?>
    <?php if ($total_return > 0): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-12">
            <div class="card bg-warning bg-opacity-25 border-warning shadow-sm">
                <div class="card-body">
                    <span class="fw-bold">Total Return:</span> $<?= number_format($total_return, 2) ?> <small class="text-muted">(total amount returned to vendors)</small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Payment Management Stats -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Payment Overview</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Total Orders</h6>
                            <h3 class="text-primary"><?= count($summary) ?></h3>
                            <small class="text-muted">Purchase orders</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Balance Due</h6>
                            <h3 class="text-warning">$<?= number_format(array_sum(array_column($summary, 'balance_due')), 2) ?></h3>
                            <small class="text-muted">Outstanding</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Total Paid</h6>
                            <h3 class="text-success">$<?= number_format(array_sum(array_column($summary, 'total_paid')), 2) ?></h3>
                            <small class="text-muted">Amount paid</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Summary -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Payment Summary</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th class="border-0 text-center">No</th>
                            <th class="border-0">Order #</th>
                            <th class="border-0">Vendor</th>
                            <th class="border-0">Order Date</th>
                            <th class="border-0">Due Date</th>
                            <th class="border-0 text-center">Total Ordered</th>
                            <th class="border-0 text-center">Total Received</th>
                            <th class="border-0 text-center">Not Received</th>
                            <th class="border-0 text-center">Total Qty Return</th>
                            <th class="border-0 text-end">Total Amount</th>
                            <th class="border-0 text-end">Total Return</th>
                            <th class="border-0 text-end">Total Paid</th>
                            <th class="border-0 text-end">Balance Due</th>
                            <th class="border-0 text-center">Payment Status</th>
                            <th class="border-0 text-center">Urgency</th>
                            <th class="border-0 text-center">Payments</th>
                            <th class="border-0 text-center">Invoice</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($summary)): ?>
                            <tr>
                                <td colspan="18" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                                        No purchase orders found
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $rowNo = 1; foreach ($summary as $item): ?>
                                <?php
                                $status_colors = [
                                    'unpaid' => 'danger',
                                    'partial' => 'warning', 
                                    'paid' => 'success',
                                    'overpaid' => 'info'
                                ];
                                $urgency_colors = [
                                    'overdue' => 'danger',
                                    'due_soon' => 'warning',
                                    'on_time' => 'success'
                                ];
                                $status_color = $status_colors[$item['payment_status']] ?? 'secondary';
                                $urgency_color = $urgency_colors[$item['urgency_status']] ?? 'secondary';
                                ?>
                                <tr class="align-middle">
                                    <td class="text-center"><?= $rowNo++ ?></td>
                                    <td>
                                        <span class="fw-semibold text-primary"><?= htmlspecialchars($item['order_number']) ?></span>
                                    </td>
                                    <td>
                                        <span class="text-dark"><?= htmlspecialchars($item['vendor_name']) ?></span>
                                    </td>
                                    <td>
                                        <span class="text-muted"><?= date('M j, Y', strtotime($item['order_date'])) ?></span>
                                    </td>
                                    <td>
                                        <span class="text-muted"><?= $item['due_date'] ? date('M j, Y', strtotime($item['due_date'])) : '<span class="badge bg-secondary">Not set</span>' ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-semibold text-primary"><?= number_format($item['total_quantity_ordered'], 2) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-semibold text-success"><?= number_format($item['total_quantity_received'], 2) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-semibold text-<?= $item['quantity_not_received'] > 0 ? 'danger' : 'success' ?>">
                                            <?= number_format($item['quantity_not_received'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-semibold text-warning"><?= number_format($item['total_qty_return'] ?? 0, 2) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <span class="fw-semibold">$<?= number_format($item['total_amount'], 2) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <span class="text-warning fw-semibold">$<?= number_format($item['total_return'] ?? 0, 2) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <span class="text-success fw-semibold">$<?= number_format($item['total_paid'], 2) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <span class="fw-semibold text-<?= $item['balance_due'] > 0 ? 'danger' : 'success' ?>">
                                            $<?= number_format($item['balance_due'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $status_color ?> px-3 py-2">
                                            <?= ucfirst($item['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $urgency_color ?> px-3 py-2">
                                            <?= ucfirst(str_replace('_', ' ', $item['urgency_status'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark px-3 py-2">
                                            <?= (int)$item['payment_count'] ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($item['payment_count'] > 0): ?>
                                            <button class="btn btn-outline-info btn-sm" onclick="generateOrderInvoice('<?= $item['id'] ?>')" title="Generate Invoice">
                                                <i class="bi bi-file-earmark-pdf"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-outline-secondary btn-sm" disabled title="No payments to invoice">
                                                <i class="bi bi-file-earmark-pdf"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Payments -->
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Payments</h5>
                <?php if (!empty(array_filter($_GET, fn($k) => str_starts_with($k, 'filter_'), ARRAY_FILTER_USE_KEY))): ?>
                    <div class="d-flex align-items-center">
                        <span class="badge bg-info me-2">
                            <i class="bi bi-funnel me-1"></i>
                            <?= count(array_filter($_GET, fn($k) => str_starts_with($k, 'filter_') && !empty($_GET[$k]), ARRAY_FILTER_USE_KEY)) ?> filters active
                        </span>
                        <small class="text-muted">
                            Showing <?= count($recent_payments) ?> filtered results
                        </small>
                    </div>
                <?php else: ?>
                    <small class="text-muted">
                        Showing last <?= count($recent_payments) ?> payments
                    </small>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th class="border-0">Payment #</th>
                            <th class="border-0">Order #</th>
                            <th class="border-0">Vendor</th>
                            <th class="border-0">Date</th>
                            <th class="border-0">Method</th>
                            <th class="border-0 text-end">Amount</th>
                            <th class="border-0 text-center">Status</th>
                            <th class="border-0">Reference</th>
                            <th class="border-0">Created By</th>
                            <th class="border-0">Created At</th>
                            <th class="border-0 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_payments)): ?>
                            <tr>
                                <td colspan="15" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="bi bi-cash-stack fs-4 d-block mb-2"></i>
                                        No payments recorded
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_payments as $payment): ?>
                                <?php
                                $status_colors = [
                                    'pending' => 'warning',
                                    'completed' => 'success',
                                    'failed' => 'danger',
                                    'cancelled' => 'secondary'
                                ];
                                $status_color = $status_colors[$payment['payment_status']] ?? 'secondary';
                                ?>
                                <tr class="align-middle">
                                    <td>
                                        <span class="fw-semibold text-primary"><?= htmlspecialchars($payment['payment_number']) ?></span>
                                    </td>
                                    <td>
                                        <span class="text-dark"><?= htmlspecialchars($payment['order_number']) ?></span>
                                    </td>
                                    <td>
                                        <span class="text-muted"><?= htmlspecialchars($payment['vendor_name']) ?></span>
                                    </td>
                                    <td>
                                        <span class="text-muted"><?= date('M j, Y', strtotime($payment['payment_date'])) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            <?= ucfirst(str_replace('_', ' ', $payment['payment_method'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <span class="fw-semibold text-success">$<?= number_format($payment['payment_amount'], 2) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $status_color ?> px-3 py-2">
                                            <?= ucfirst($payment['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-muted small"><?= htmlspecialchars($payment['reference_number'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <span class="text-muted"><?= htmlspecialchars($payment['created_by_name'] ?? 'Unknown') ?></span>
                                    </td>
                                    <td>
                                        <span class="text-muted small"><?= date('M j, Y H:i', strtotime($payment['created_at'])) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php 
                                            $receipt_images = !empty($payment['receipt_image']) ? json_decode($payment['receipt_image']) : [];
                                            if (!empty($receipt_images) && is_array($receipt_images)): 
                                                $image_count = count($receipt_images);
                                            ?>
                                                <button class="btn btn-outline-primary" onclick="viewReceipt('<?= htmlspecialchars($payment['receipt_image']) ?>')" title="View Receipts (<?= $image_count ?>)">
                                                    <i class="bi bi-images"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-outline-secondary" disabled title="No Receipts">
                                                    <i class="bi bi-images"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-outline-info" onclick="generateInvoice('<?= $payment['id'] ?>')" title="Generate Invoice">
                                                <i class="bi bi-file-earmark-pdf"></i>
                                            </button>
                                            <button class="btn btn-outline-warning" onclick="editPayment(<?= $payment['id'] ?>)" title="Edit Payment">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-outline-danger" onclick="deletePayment(<?= $payment['id'] ?>, '<?= htmlspecialchars($payment['payment_number']) ?>')" title="Delete Payment">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
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

<!-- Delete Payment Modal -->
<div class="modal fade" id="deletePaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="delete_payment">
                <input type="hidden" name="payment_id" id="deletePaymentId">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete payment <strong id="deletePaymentNumber"></strong>?</p>
                    <p class="text-danger">This will update the order's payment status and cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Payment Modal -->
<div class="modal fade" id="editPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="editPaymentForm">
                <input type="hidden" name="action" value="edit_payment">
                <input type="hidden" name="payment_id" id="editPaymentId">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i>Edit Payment
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Date *</label>
                            <input type="date" name="payment_date" class="form-control" id="editPaymentDate" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Method</label>
                            <select name="payment_method" class="form-select" id="editPaymentMethod" required>
                                <?php if (!empty($payment_methods)): ?>
                                    <?php foreach ($payment_methods as $method): ?>
                                        <option value="<?= htmlspecialchars($method['method_code']) ?>">
                                            <?= htmlspecialchars($method['method_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">No payment methods available</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Amount *</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" step="0.01" name="payment_amount" class="form-control" id="editPaymentAmount" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Reference #</label>
                            <input type="text" name="reference_number" class="form-control" id="editReferenceNumber" placeholder="e.g., TRF-001, CHK-001">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Payment Status</label>
                            <select name="payment_status" class="form-select" id="editPaymentStatus">
                                <option value="pending">🟡 Pending</option>
                                <option value="completed">🟢 Completed</option>
                                <option value="failed">🔴 Failed</option>
                                <option value="cancelled">⚫ Cancelled</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea name="notes" class="form-control" id="editNotes" rows="2" placeholder="Payment notes or additional details..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle me-1"></i>Update Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mobile Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-credit-card me-2"></i>Add New Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data" id="paymentForm">
                <input type="hidden" name="action" value="add_payment">
                <div class="modal-body">
                    <!-- Mobile-optimized form -->
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold">
                                <i class="bi bi-file-earmark-text me-1"></i>Purchase Order *
                            </label>
                            <select name="purchase_order_id" class="form-select form-select-lg" required onchange="updateBalance(this)">
                                <option value="">Select Order</option>
                                <?php foreach ($orders as $order): ?>
                                    <?php $balance = $order['total_amount'] - $order['total_paid']; ?>
                                    <?php if ($balance > 0): ?>
                                        <option value="<?= $order['id'] ?>" data-balance="<?= $balance ?>">
                                            <?= htmlspecialchars($order['order_number']) ?> - <?= htmlspecialchars($order['vendor_name']) ?> 
                                            <small>(Balance: $<?= number_format($balance, 2) ?>)</small>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="bi bi-calendar me-1"></i>Payment Date *
                            </label>
                            <input type="date" name="payment_date" class="form-control form-control-lg" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="bi bi-wallet2 me-1"></i>Payment Method
                            </label>
                            <select name="payment_method" class="form-select form-select-lg" required>
                                <?php if (!empty($payment_methods)): ?>
                                    <?php 
                                    $has_default = false;
                                    foreach ($payment_methods as $method): 
                                        if ($method['is_default']) $has_default = true;
                                    ?>
                                        <option value="<?= htmlspecialchars($method['method_code']) ?>" <?= $method['is_default'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($method['method_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (!$has_default && !empty($payment_methods)): ?>
                                        <?php $first_method = reset($payment_methods); ?>
                                        <script>
                                        document.addEventListener('DOMContentLoaded', function() {
                                            document.querySelector('select[name="payment_method"]').value = '<?= htmlspecialchars($first_method['method_code']) ?>';
                                        });
                                        </script>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <option value="">No payment methods available</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label fw-bold">
                                <i class="bi bi-currency-dollar me-1"></i>Payment Amount *
                            </label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text">$</span>
                                <input type="number" step="0.01" name="payment_amount" class="form-control" placeholder="Enter amount" required id="paymentAmount">
                                <span class="input-group-text" id="balanceInfo">Balance: $0.00</span>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="bi bi-hash me-1"></i>Reference #
                            </label>
                            <input type="text" name="reference_number" class="form-control form-control-lg" placeholder="e.g., TRF-001, CHK-001">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="bi bi-sticky-note me-1"></i>Notes
                            </label>
                            <input type="text" name="notes" class="form-control form-control-lg" placeholder="Payment notes...">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label fw-bold">
                                <i class="bi bi-images me-1"></i>Receipt Images *
                            </label>
                            <div class="border rounded p-3 bg-light">
                                <?php if (extension_loaded('gd') && function_exists('imagecreatefromjpeg')): ?>
                                    <div class="alert alert-success py-2 mb-2">
                                        <i class="bi bi-check-circle me-1"></i>
                                        <small>Image compression is enabled - images will be optimized for storage</small>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning py-2 mb-2">
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        <small>Image compression not available - images will be stored at original size</small>
                                    </div>
                                <?php endif; ?>
                                <input type="file" name="receipt_images[]" class="form-control" accept="image/*,.pdf" id="receiptFiles" multiple>
                                <small class="text-muted d-block">Upload multiple receipt images (JPG, PNG, GIF, PDF)</small>
                                <small class="text-muted d-block">Max 5MB per file<?php if (extension_loaded('gd')): ?>, images will be compressed<?php endif; ?></small>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-outline-info btn-sm" onclick="showReceiptPreview()">
                                        <i class="bi bi-eye me-1"></i>Preview Receipts
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addMoreImages()">
                                        <i class="bi bi-plus-circle me-1"></i>Add More
                                    </button>
                                </div>
                                <div id="imagePreviewContainer" class="mt-3">
                                    <!-- Image previews will be shown here -->
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Payment Summary:</strong>
                                <div id="paymentSummary" class="mt-2">
                                    Please select a purchase order to see payment details.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-lg" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-circle me-1"></i>Process Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Receipt Preview Modal -->
<div class="modal fade" id="receiptPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Receipt Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="receiptPreviewContent">
                    <!-- Receipt image will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="downloadReceipt" style="display: none;">
                    <i class="bi bi-download me-1"></i>Download
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Filter management functions
function clearFilters() {
    const url = new URL(window.location);
    // Remove all filter parameters
    Array.from(url.searchParams.keys()).forEach(key => {
        if (key.startsWith('filter_')) {
            url.searchParams.delete(key);
        }
    });
    window.location.href = url.toString();
}

function editPayment(paymentId) {
    // Load payment data via AJAX
    fetch(`get_purchase_payment.php?id=${paymentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Populate edit form
                document.getElementById('editPaymentId').value = data.payment.id;
                document.getElementById('editPaymentDate').value = data.payment.payment_date;
                document.getElementById('editPaymentMethod').value = data.payment.payment_method;
                document.getElementById('editPaymentAmount').value = data.payment.payment_amount;
                document.getElementById('editReferenceNumber').value = data.payment.reference_number || '';
                document.getElementById('editPaymentStatus').value = data.payment.payment_status;
                document.getElementById('editNotes').value = data.payment.notes || '';
                
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('editPaymentModal'));
                modal.show();
            } else {
                alert('Error loading payment: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading payment');
        });
}

function openPaymentModal() {
    const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
}

function openPaymentMethodsModal() {
    // Open the payment methods management page
    window.location.href = 'payment_methods.php';
}

function updateBalance(select) {
    const balance = select.options[select.selectedIndex].getAttribute('data-balance');
    const balanceInfo = document.getElementById('balanceInfo');
    const paymentAmount = document.getElementById('paymentAmount');
    const paymentSummary = document.getElementById('paymentSummary');
    
    if (balance) {
        const balanceValue = parseFloat(balance);
        balanceInfo.textContent = `Balance: $${balanceValue.toFixed(2)}`;
        paymentAmount.value = '0.00';
        
        // Update payment summary
        const selectedOption = select.options[select.selectedIndex];
        const orderText = selectedOption.text;
        
        paymentSummary.innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <strong>Order:</strong> ${orderText.split(' - ')[0]}<br>
                    <strong>Vendor:</strong> ${orderText.split(' - ')[1].split('(')[0].trim()}
                </div>
                <div class="col-md-6">
                    <strong>Balance Due:</strong> $${balanceValue.toFixed(2)}<br>
                    <strong>Payment Amount:</strong> $0.00
                </div>
            </div>
        `;
    } else {
        balanceInfo.textContent = 'Balance: $0.00';
        paymentAmount.value = '';
        paymentSummary.innerHTML = 'Please select a purchase order to see payment details.';
    }
}

function deletePayment(id, number) {
    document.getElementById('deletePaymentId').value = id;
    document.getElementById('deletePaymentNumber').textContent = number;
    
    const modal = new bootstrap.Modal(document.getElementById('deletePaymentModal'));
    modal.show();
}

function generateInvoice(paymentId) {
    // Open invoice in new window/tab for printing/saving
    const invoiceWindow = window.open(`generate_payment_invoice.php?payment_id=${paymentId}`, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
    
    // Focus on the new window
    if (invoiceWindow) {
        invoiceWindow.focus();
    } else {
        // If popup blocked, open in same tab
        window.location.href = `generate_payment_invoice.php?payment_id=${paymentId}`;
    }
}

function generateOrderInvoice(orderId) {
    // Open order invoice in new window/tab for printing/saving
    const invoiceWindow = window.open(`generate_order_invoice.php?order_id=${orderId}`, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
    
    // Focus on the new window
    if (invoiceWindow) {
        invoiceWindow.focus();
    } else {
        // If popup blocked, open in same tab
        window.location.href = `generate_order_invoice.php?order_id=${orderId}`;
    }
}

function clearForm() {
    document.getElementById('paymentForm').reset();
    document.getElementById('balanceInfo').textContent = 'Balance: $0.00';
    document.getElementById('paymentSummary').innerHTML = 'Please select a purchase order to see payment details.';
}

function showReceiptPreview() {
    const fileInput = document.querySelector('#receiptFiles');
    const previewContainer = document.getElementById('imagePreviewContainer');
    
    if (fileInput.files && fileInput.files.length > 0) {
        previewContainer.innerHTML = '<h6 class="mb-3">Receipt Previews:</h6><div class="row g-2">';
        
        Array.from(fileInput.files).forEach((file, index) => {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                const isImage = file.type.startsWith('image/');
                const fileExtension = file.name.split('.').pop().toLowerCase();
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                
                const previewHtml = `
                    <div class="col-md-4 col-sm-6">
                        <div class="card">
                            <div class="card-body p-2">
                                ${isImage ? 
                                    `<img src="${e.target.result}" class="img-fluid mb-2" alt="Receipt ${index + 1}" style="max-height: 150px; object-fit: cover;">` :
                                    `<div class="text-center p-3 bg-light">
                                        <i class="bi bi-file-pdf text-danger" style="font-size: 2rem;"></i>
                                        <div class="small">${file.name}</div>
                                    </div>`
                                }
                                <div class="small text-muted">
                                    <div>${file.name}</div>
                                    <div>Size: ${fileSize}MB</div>
                                    <div>Type: ${fileExtension.toUpperCase()}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                previewContainer.innerHTML += previewHtml;
            };
            
            reader.readAsDataURL(file);
        });
        
        previewContainer.innerHTML += '</div>';
    } else {
        previewContainer.innerHTML = '<div class="alert alert-warning">Please select at least one receipt image.</div>';
    }
}

function addMoreImages() {
    // Create a new file input
    const newInput = document.createElement('input');
    newInput.type = 'file';
    newInput.name = 'receipt_images[]';
    newInput.className = 'form-control mt-2';
    newInput.accept = 'image/*,.pdf';
    newInput.multiple = true;
    newInput.onchange = showReceiptPreview;
    
    // Insert after the existing input
    const container = document.querySelector('#receiptFiles').parentNode;
    container.appendChild(newInput);
    
    // Focus on the new input
    newInput.focus();
}

function clearForm() {
    document.getElementById('paymentForm').reset();
    document.getElementById('balanceInfo').textContent = 'Balance: $0.00';
    document.getElementById('paymentSummary').innerHTML = 'Please select a purchase order to see payment details.';
    document.getElementById('imagePreviewContainer').innerHTML = '';
    
    // Remove any additional file inputs
    const additionalInputs = document.querySelectorAll('input[name="receipt_images[]"]:not(:first-child)');
    additionalInputs.forEach(input => input.remove());
}

function viewReceipt(imageData) {
    const content = document.getElementById('receiptPreviewContent');
    
    // Handle multiple images (JSON array) or single image (string)
    let images = [];
    if (typeof imageData === 'string') {
        try {
            images = JSON.parse(imageData);
        } catch (e) {
            images = [imageData]; // Single image
        }
    } else {
        images = imageData;
    }
    const paymentReceiptBaseUrl = <?= json_encode(str_replace('__file__', '', uploaded_file_url('__file__', 'payment_receipts'))) ?>;
    
    if (images.length === 0) {
        content.innerHTML = '<div class="alert alert-warning">No receipt images available.</div>';
    } else if (images.length === 1) {
        // Single image - show full size
        const imageName = images[0];
        const imagePath = paymentReceiptBaseUrl + imageName.replace(/^uploads\/payment_receipts\//, '');
        const extension = imageName.split('.').pop().toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif'].includes(extension);
        
        if (isImage) {
            content.innerHTML = `
                <img src="${imagePath}" class="img-fluid" alt="Receipt" style="max-height: 500px;" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgdmlld0JveD0iMCAwIDIwMCAyMDAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxyZWN0IHdpZHRoPSIyMDAiIGhlaWdodD0iMjAwIiBmaWxsPSIjRjVGNUY1Ii8+CjxwYXRoIGQ9Ik04NSA3NUgxMTVWMTI1SDg1Vjc1WiIgZmlsbD0iI0NDQyIvPgo8cGF0aCBkPSJNMTA1IDg1SDEyMFYxMTVIMTA1Vjg1WiIgZmlsbD0iIzk5OTkiLz4KPHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTEwIDVWMTVNMTUgMTBIMVYxMFoiIHN0cm9rZT0iIzk5OTkiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIi8+Cjwvc3ZnPgo8L3N2Zz4K'">
            `;
        } else {
            content.innerHTML = `
                <div class="alert alert-info">
                    <i class="bi bi-file-pdf me-2"></i>
                    PDF Document: ${imageName}
                    <br><small>Click the Download button to view the PDF</small>
                </div>
            `;
        }
        
        document.getElementById('downloadReceipt').style.display = 'inline-block';
        document.getElementById('downloadReceipt').onclick = function() {
            window.open(imagePath, '_blank');
        };
    } else {
        // Multiple images - show gallery
        content.innerHTML = '<h5 class="mb-3">Receipt Images (' + images.length + ')</h5><div class="row g-2">';
        
        images.forEach((imageName, index) => {
            const imagePath = paymentReceiptBaseUrl + imageName.replace(/^uploads\/payment_receipts\//, '');
            const extension = imageName.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif'].includes(extension);
            
            const galleryHtml = `
                <div class="col-md-4 col-sm-6">
                    <div class="card">
                        <div class="card-body p-2">
                            ${isImage ? 
                                `<img src="${imagePath}" class="img-fluid mb-2" alt="Receipt ${index + 1}" style="max-height: 150px; object-fit: cover;" onclick="window.open('${imagePath}', '_blank')" style="cursor: pointer;">` :
                                `<div class="text-center p-3 bg-light" onclick="window.open('${imagePath}', '_blank')" style="cursor: pointer;">
                                    <i class="bi bi-file-pdf text-danger" style="font-size: 2rem;"></i>
                                    <div class="small">${imageName}</div>
                                </div>`
                            }
                            <div class="small text-muted">
                                <div>${imageName}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            content.innerHTML += galleryHtml;
        });
        
        content.innerHTML += '</div>';
        document.getElementById('downloadReceipt').style.display = 'none';
    }
    
    const modal = new bootstrap.Modal(document.getElementById('receiptPreviewModal'));
    modal.show();
}

// File input change handler for preview
document.querySelector('#receiptFiles').addEventListener('change', function() {
    if (this.files && this.files.length > 0) {
        let totalSize = 0;
        let hasInvalidFile = false;
        
        Array.from(this.files).forEach(file => {
            const fileSize = file.size / 1024 / 1024;
            totalSize += fileSize;
            
            if (fileSize > 5) {
                hasInvalidFile = true;
                alert('File ' + file.name + ' is too large (' + fileSize.toFixed(2) + 'MB). Maximum size is 5MB per file.');
                this.value = '';
                return;
            }
        });
        
        if (!hasInvalidFile) {
            console.log('Files selected:', this.files.length, 'Total size:', totalSize.toFixed(2) + 'MB');
            showReceiptPreview();
        }
    }
});

// Auto-fill payment amount with 0 when order is selected
const existingOrderSelect = document.querySelector('select[name="purchase_order_id"]');
if (existingOrderSelect) {
    existingOrderSelect.addEventListener('change', function() {
        const balance = this.options[this.selectedIndex].getAttribute('data-balance');
        if (balance) {
            // Set payment amount to 0 when order is selected
            document.querySelector('input[name="payment_amount"]').value = '0.00';
        }
    });
}
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
