<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'spending.view', 'sr_expense_records.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';
require_once __DIR__ . '/../api/reports/finance_balance_lib.php';

$pdo = get_db_connection();

function ensure_finance_spending_expense_columns(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            color VARCHAR(20) NULL DEFAULT '#6b7280',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_company_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $cols = $pdo->query("SHOW COLUMNS FROM finance_spending")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('payment_method', $cols, true)) {
            $pdo->exec("ALTER TABLE finance_spending ADD COLUMN payment_method VARCHAR(100) NULL AFTER currency");
        }
        if (!in_array('company_id', $cols, true)) {
            $pdo->exec("ALTER TABLE finance_spending ADD COLUMN company_id INT NULL AFTER payment_method");
        }
    } catch (Throwable $e) {
        error_log('finance_spending expense column check failed: ' . $e->getMessage());
    }
}

ensure_finance_spending_expense_columns($pdo);

// Image compression function
function compressImage($source, $extension, $quality = 80) {
    $max_width = 1200;
    $max_height = 1200;
    
    // Get image info
    $image_info = getimagesize($source);
    if (!$image_info) return false;
    
    list($width, $height) = $image_info;
    
    // Calculate new dimensions
    if ($width > $max_width || $height > $max_height) {
        $ratio = min($max_width / $width, $max_height / $height);
        $new_width = round($width * $ratio);
        $new_height = round($height * $ratio);
    } else {
        $new_width = $width;
        $new_height = $height;
    }
    
    // Create image resource based on extension
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'png':
            $image = imagecreatefrompng($source);
            break;
        case 'gif':
            $image = imagecreatefromgif($source);
            break;
        default:
            return false;
    }
    
    if (!$image) return false;
    
    // Create new image
    $new_image = imagecreatetruecolor($new_width, $new_height);
    
    // Handle transparency for PNG
    if ($extension === 'png') {
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
        $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
        imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
    }
    
    // Resize image
    imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    // Save to temporary file
    $temp_file = tempnam(sys_get_temp_dir(), 'compressed_');
    
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            imagejpeg($new_image, $temp_file, $quality);
            break;
        case 'png':
            imagepng($new_image, $temp_file, round($quality / 11));
            break;
        case 'gif':
            imagegif($new_image, $temp_file);
            break;
    }
    
    // Clean up
    imagedestroy($image);
    imagedestroy($new_image);
    
    return $temp_file;
}

function get_default_finance_bank(PDO $pdo): string {
    $stmt = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_admin_active = 1 AND is_finance_default = 1 ORDER BY id ASC LIMIT 1");
    $default_finance_bank = trim((string)$stmt->fetchColumn());

    if ($default_finance_bank === '') {
        $stmt = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order, option_text LIMIT 1");
        $default_finance_bank = trim((string)$stmt->fetchColumn());
    }

    return $default_finance_bank;
}

function get_finance_available_balance(PDO $pdo, string $toDate, ?string $bank = null): float {
    if (function_exists('finance_available_balance') && function_exists('api_table_exists') && function_exists('api_table_columns')) {
        $available = finance_available_balance($pdo, $toDate, $bank);
        return (float)($available['balance'] ?? 0);
    }

    $default_finance_bank = get_default_finance_bank($pdo);
    $balance_bank = trim((string)($bank ?? ''));
    if ($balance_bank === '') {
        $balance_bank = $default_finance_bank;
    }
    if ($balance_bank === '') {
        return 0.0;
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0)
        FROM orders
        WHERE status = 'paid'
          AND is_cancelled = 0
          AND is_returned = 0
          AND COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
          AND COALESCE(payment_date, DATE(created_at)) <= ?
    ");
    $stmt->execute([$balance_bank, $toDate]);
    $orders_in = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM cashflow_topups
        WHERE COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
          AND topup_date <= ?
    ");
    $stmt->execute([$balance_bank, $toDate]);
    $cashflow_topup_in = (float)$stmt->fetchColumn();

    $has_topup_status = false;
    try {
        $has_topup_status = (bool)$pdo->query("SHOW COLUMNS FROM finance_topups LIKE 'status'")->fetch();
    } catch (Throwable $e) {
        $has_topup_status = false;
    }
    $topup_status_sql = $has_topup_status
        ? " AND LOWER(COALESCE(NULLIF(TRIM(status), ''), 'completed')) IN ('approved', 'completed')"
        : "";

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM finance_topups
        WHERE COALESCE(NULLIF(TRIM(source), ''), '(No method)') = ?
          AND DATE(topup_date) <= ?
          {$topup_status_sql}
    ");
    $stmt->execute([$balance_bank, $toDate]);
    $finance_topup_in = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM cashflow_spending
        WHERE COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
          AND spending_date <= ?
    ");
    $stmt->execute([$balance_bank, $toDate]);
    $cashflow_spending_out = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM finance_spending
        WHERE DATE(spending_date) <= ?
          AND LOWER(COALESCE(status, '')) IN ('approved', 'completed')
          AND COALESCE(NULLIF(TRIM(payment_method), ''), ?) = ?
    ");
    $fallback_bank = $default_finance_bank !== '' ? $default_finance_bank : $balance_bank;
    $stmt->execute([$toDate, $fallback_bank, $balance_bank]);
    $finance_spending_out = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM bank_transfers WHERE to_bank = ? AND transfer_date <= ?");
    $stmt->execute([$balance_bank, $toDate]);
    $transfer_in = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM bank_transfers WHERE from_bank = ? AND transfer_date <= ?");
    $stmt->execute([$balance_bank, $toDate]);
    $transfer_out = (float)$stmt->fetchColumn();

    return $orders_in + $cashflow_topup_in + $finance_topup_in - $cashflow_spending_out - $finance_spending_out + $transfer_in - $transfer_out;
}

function format_balance_date(string $date): string {
    $timestamp = strtotime($date);
    return $timestamp ? date('d-M-Y', $timestamp) : $date;
}

function next_finance_spending_code(PDO $pdo, ?string $forDate = null): string {
    $time = $forDate && strtotime($forDate) ? strtotime($forDate) : time();
    $prefix = 'EXP-' . date('ymd', $time);
    $stmt = $pdo->prepare("
        SELECT spending_code
        FROM finance_spending
        WHERE spending_code LIKE ?
        ORDER BY spending_code DESC
        LIMIT 1000
    ");
    $stmt->execute([$prefix . '%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string)$code, $match)) {
            $max = max($max, (int)$match[1]);
        }
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

function finance_spending_code_exists(PDO $pdo, string $code): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM finance_spending WHERE spending_code = ? LIMIT 1');
    $stmt->execute([$code]);
    return (bool)$stmt->fetchColumn();
}

function is_auto_finance_spending_code(string $code): bool {
    return $code === '' || preg_match('/^ONS-/i', $code) || preg_match('/^EXP-\d{9,}$/i', $code);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role_or_permission(['admin'], 'spending.create', 'sr_expense_records.create');
    $currentUser = function_exists('current_user') ? current_user() : null;
    // Store user ID so display JOIN resolves to correct name from users table
    $createdBy = ($currentUser && isset($currentUser['id'])) ? (int)$currentUser['id'] : 'Admin';
    $spending_code = trim((string)($_POST['spending_code'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $currency = $_POST['currency'] ?? 'USD';
    $payment_method = trim((string)($_POST['payment_method'] ?? ''));
    $company_id = filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT);
    if ($company_id === false) $company_id = null;
    $paid_by = $_POST['paid_by'] ?? '';
    $receive_by = $_POST['receive_by'] ?? '';
    $spending_date = $_POST['spending_date'] ?? date('Y-m-d');
    $status = $_POST['status'] ?? '';
    $spend_to = $_POST['spend_to'] ?? '';
    $sub_category = $_POST['sub_category'] ?? '';
    $sub_categories_list = $_POST['sub_categories'] ?? [];
    
    // Convert KHR to USD if needed (assuming 4100 KHR = 1 USD)
    if ($currency === 'KHR') {
        $amount_usd = $amount / 4100;
    } else {
        $amount_usd = $amount;
    }
    
    // Filter out empty values and prepare for storage
    $sub_categories_list = array_filter($sub_categories_list, function($cat) {
        return !empty(trim($cat));
    });
    
    // For backward compatibility, use first subcategory as the main one
    $sub_category = !empty($sub_categories_list) ? $sub_categories_list[0] : '';
    $sub_categories = json_encode(array_values($sub_categories_list));
    $note = $_POST['note'] ?? '';
    
    $useAutoSpendingCode = is_auto_finance_spending_code($spending_code);
    if ($useAutoSpendingCode) {
        $spending_code = next_finance_spending_code($pdo, $spending_date);
    }

    // Handle image uploads
    $uploaded_images = [];
    error_log("=== IMAGE UPLOAD DEBUG START ===");
    error_log("FILES data: " . print_r($_FILES, true));
    
    if (!empty($_FILES['spending_images']['name'][0])) {
        error_log("Found uploaded files");
        error_log("Upload storage: " . upload_storage_driver());
        
        foreach ($_FILES['spending_images']['name'] as $key => $name) {
            error_log("Processing file $key: $name");
            if (!empty($name)) {
                $file_tmp = $_FILES['spending_images']['tmp_name'][$key];
                $file_size = $_FILES['spending_images']['size'][$key];
                $file_error = $_FILES['spending_images']['error'][$key];
                
                error_log("File details - tmp: $file_tmp, size: $file_size, error: $file_error");
                
                if ($file_error === UPLOAD_ERR_OK && $file_size > 0) {
                    // Check file size (max 2MB)
                    $max_size = 2 * 1024 * 1024; // 2MB
                    if ($file_size > $max_size) {
                        error_log("File too large: $file_size bytes");
                        continue; // Skip large files
                    }
                    
                    $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
                    
                    error_log("File extension: $file_ext");
                    
                    if (in_array($file_ext, $allowed_extensions)) {
                        // For now, skip compression and just upload directly
                        $new_filename = 'spending_' . time() . '_' . $key . '.' . $file_ext;
                        
                        error_log("Attempting to store: $new_filename");
                        
                        $storedPath = upload_store_uploaded_file([
                            'error' => $file_error,
                            'tmp_name' => $file_tmp,
                            'type' => $_FILES['spending_images']['type'][$key] ?? '',
                        ], 'spending_images', $new_filename, $spending_date, (string)($_FILES['spending_images']['type'][$key] ?? ''));
                        if ($storedPath !== '') {
                            error_log("Successfully uploaded: $new_filename");
                            $uploaded_images[] = preg_replace('#^uploads/spending_images/#', '', $storedPath);
                        } else {
                            error_log("Failed to move uploaded file");
                        }
                    } else {
                        error_log("File extension not allowed: $file_ext");
                    }
                } else {
                    error_log("Upload error: $file_error");
                }
            }
        }
    } else {
        error_log("No files uploaded");
    }
    
    $images_json = json_encode($uploaded_images);
    error_log("Final images JSON: " . $images_json);
    error_log("=== IMAGE UPLOAD DEBUG END ===");
    
    // Debug: Show what we're trying to insert
    error_log("Attempting to insert spending: " . print_r([
        'spending_code' => $spending_code,
        'amount' => $amount_usd,
        'original_amount' => $amount,
        'currency' => $currency,
        'payment_method' => $payment_method,
        'company_id' => $company_id,
        'paid_by' => $paid_by,
        'receive_by' => $receive_by,
        'spending_date' => $spending_date,
        'status' => $status,
        'category' => $spend_to,
        'sub_category' => $sub_category,
        'sub_categories' => $sub_categories,
        'note' => $note,
        'images' => $images_json,
        'created_by' => $createdBy
    ], true));
    
    // Validation
    $errors = [];
    if (empty($spending_code)) $errors[] = 'Spending code is required';
    if (!empty($spending_code) && finance_spending_code_exists($pdo, $spending_code)) {
        if ($useAutoSpendingCode) {
            $spending_code = next_finance_spending_code($pdo, $spending_date);
        } else {
            $errors[] = 'Spending code already exists';
        }
    }
    if ($amount <= 0) $errors[] = 'Amount must be greater than 0';
    if (empty($currency)) $errors[] = 'Currency is required';
    if (empty($spending_date)) $errors[] = 'Spending date is required';
    if (empty($status)) $errors[] = 'Status is required';
    if (empty($spend_to)) $errors[] = 'Spend to is required';
    if (empty($paid_by)) $errors[] = 'Paid By is required';
    
    // Check for subcategories (new multiple format)
    $sub_categories_list = $_POST['sub_categories'] ?? [];
    if (empty($sub_categories_list) || !is_array($sub_categories_list)) {
        $errors[] = 'At least one sub category is required';
    } else {
        // Filter out empty values
        $sub_categories_list = array_filter($sub_categories_list, function($cat) {
            return !empty(trim($cat));
        });
        if (empty($sub_categories_list)) {
            $errors[] = 'At least one sub category is required';
        }
    }
    
    // Check balance before allowing spending; pending requests do not reduce balance yet.
    $current_balance = 0.0;
    try {
        $current_balance = get_finance_available_balance($pdo, $spending_date, $payment_method);
    } catch (PDOException $e) {
        $current_balance = 0.0;
    }
    
    $reduces_balance = in_array(strtolower((string)$status), ['approved', 'completed'], true);
    if ($reduces_balance && $current_balance <= 0) {
        $errors[] = 'Insufficient funds! Balance on ' . format_balance_date($spending_date) . ' is $' . number_format($current_balance, 2) . '. Please top up money first.';
    } elseif ($reduces_balance && $amount_usd > $current_balance) {
        $errors[] = 'Insufficient funds! You are trying to spend ' . ($currency === 'KHR' ? 'áŸ›' . number_format($amount, 0) : '$' . number_format($amount, 2)) . ' but only have $' . number_format($current_balance, 2) . ' available on ' . format_balance_date($spending_date) . '.';
    }
    
    if (empty($errors)) {
        try {
            // Insert spending record
            $stmt = $pdo->prepare('INSERT INTO finance_spending 
                (spending_code, amount, original_amount, currency, payment_method, company_id, paid_by, receive_by, spending_date, status, category, sub_category, sub_categories, note, images, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            
            $result = $stmt->execute([
                $spending_code,
                $amount_usd,
                $amount,
                $currency,
                $payment_method,
                $company_id,
                $paid_by,
                $receive_by,
                $spending_date,
                $status,
                $spend_to,
                $sub_category,
                $sub_categories,
                $note,
                $images_json,
                $createdBy
            ]);
            
            error_log("Insert result: " . ($result ? 'SUCCESS' : 'FAILED'));
            
            if ($result) {
                $newId = (int)$pdo->lastInsertId();
                require_once __DIR__ . '/finance_spending_history_lib.php';
                $currentUser = function_exists('current_user') ? current_user() : null;
                finance_spending_history_log(
                    $pdo,
                    $newId,
                    'Created',
                    'Expense record created · ' . ($currency === 'KHR' ? ('KHR ' . number_format($amount, 0)) : ('$' . number_format($amount_usd, 2))) . ' · ' . $status,
                    is_array($currentUser) ? $currentUser : null,
                    (string)$spending_code
                );
                header('Location: add_spending.php?success=1');
                exit;
            } else {
                $errors[] = 'Failed to save spending record';
            }
            
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
            error_log("Database error: " . $e->getMessage());
        }
    }
}

// Get users for dropdowns
$stmt = $pdo->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name");
$users = $stmt->fetchAll();
// Get all main categories from database
$stmt = $pdo->query("SELECT * FROM finance_categories WHERE type = 'main' ORDER BY name");
$main_categories = $stmt->fetchAll();

// Get all subcategories from database
$stmt = $pdo->query("SELECT * FROM finance_categories WHERE type = 'sub' ORDER BY parent_category, name");
$all_subcategories = $stmt->fetchAll();

// Organize subcategories by parent category
$subcategories_by_parent = [];
foreach ($all_subcategories as $subcat) {
    $subcategories_by_parent[$subcat['parent_category']][] = $subcat;
}

// Calculate balance for the visible date so the banner matches validation.
$balance_date = $_POST['spending_date'] ?? date('Y-m-d');
if (trim((string)$balance_date) === '') {
    $balance_date = date('Y-m-d');
}
$today = date('Y-m-d');
$balance_is_today = $balance_date === $today;
$balance_date_label = format_balance_date($balance_date);
$balance_label = $balance_is_today ? 'Current Balance' : 'Balance on ' . $balance_date_label;
$balance_hint = $balance_is_today ? 'Available for spending' : 'Available on selected spending date';
$current_balance = 0.0;
try {
    $current_balance = get_finance_available_balance($pdo, $balance_date);
} catch (PDOException $e) {
    $current_balance = 0.0;
}

// Generate spending code
$spending_code = next_finance_spending_code($pdo, $balance_date);

require_once __DIR__ . '/../layout/header.php';

// Add responsive CSS for mobile devices
echo "
<style>
/* Add Spending - Styled */
.add-spending-page .page-header {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 50%, #f87171 100%);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    color: #fff;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.add-spending-page .page-header h2 { margin: 0; font-weight: 600; }
.add-spending-page .page-header .subtitle { color: rgba(255,255,255,0.9); margin: 0.25rem 0 0; }
.add-spending-page .balance-card {
    background: linear-gradient(135deg, #fef2f2 0%, #fff5f5 100%);
    border: 1px solid #fecaca;
    border-radius: 12px;
    padding: 1rem 1.25rem;
}
.add-spending-page .balance-card.text-success { background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%); border-color: #bbf7d0; }
.add-spending-page .form-card {
    border-radius: 12px;
    overflow: hidden;
    border: none;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.12);
}
.add-spending-page .form-card .card-header {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
    color: #fff;
    padding: 1rem 1.25rem;
    font-weight: 600;
}
.add-spending-page .form-card .form-control:focus,
.add-spending-page .form-card .form-select:focus {
    border-color: #ef4444;
    box-shadow: 0 0 0 0.2rem rgba(239, 68, 68, 0.25);
}
.add-spending-page .form-card .form-control,
.add-spending-page .form-card .form-select,
.add-spending-page .form-card textarea.form-control {
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    background: #ffffff;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}
.add-spending-page .form-card .form-control:hover,
.add-spending-page .form-card .form-select:hover {
    border-color: #fca5a5;
}
.add-spending-page .form-card .form-control.is-filled,
.add-spending-page .form-card .form-select.is-filled,
.add-spending-page .form-card textarea.form-control.is-filled {
    background: #fff7f7;
    border-color: #fda4af;
}
.add-spending-page .form-card .form-control.is-touched:invalid,
.add-spending-page .form-card .form-select.is-touched:invalid {
    border-color: #dc2626;
    box-shadow: 0 0 0 0.14rem rgba(220, 38, 38, 0.15);
}
.add-spending-page .form-card .form-control.is-touched:valid,
.add-spending-page .form-card .form-select.is-touched:valid {
    border-color: #10b981;
    box-shadow: 0 0 0 0.14rem rgba(16, 185, 129, 0.14);
}
.add-spending-page .btn-primary {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
    border: none;
}
.add-spending-page .btn-primary:hover {
    background: linear-gradient(135deg, #b91c1c 0%, #dc2626 100%);
    border: none;
}
.add-spending-page .input-group-text { font-weight: 600; }
.add-spending-page .form-label { font-weight: 600; color: #374151; }
.add-spending-page .form-card .border.rounded { border-radius: 8px !important; }
.add-spending-page .text-muted { color: #212529 !important; }
.spending-success-toast {
    position: fixed;
    top: 50%;
    left: 50%;
    z-index: 1090;
    width: min(440px, calc(100vw - 24px));
    background: linear-gradient(135deg, #047857 0%, #10b981 100%);
    color: #ffffff;
    border-radius: 14px;
    box-shadow: 0 14px 30px rgba(16, 185, 129, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.25);
    overflow: hidden;
    transform: translate(-50%, calc(-50% - 10px));
    opacity: 0;
    animation: toastSlideIn 0.35s ease forwards;
}
.spending-success-toast.is-hiding {
    animation: toastFadeOut 0.4s ease forwards;
}
.spending-success-toast .toast-head {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 14px 16px 10px;
}
.spending-success-toast .toast-icon {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.18);
    flex-shrink: 0;
}
.spending-success-toast .toast-title {
    margin: 0;
    font-weight: 700;
    font-size: 1rem;
}
.spending-success-toast .toast-desc {
    margin: 2px 0 0;
    font-size: 0.9rem;
    opacity: 0.96;
}
.spending-success-toast .toast-close {
    margin-left: auto;
    border: 0;
    background: transparent;
    color: #ffffff;
    opacity: 0.9;
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
}
.spending-success-toast .toast-close:hover { opacity: 1; }
.spending-success-toast .toast-progress {
    height: 4px;
    background: rgba(255, 255, 255, 0.25);
}
.spending-success-toast .toast-progress span {
    display: block;
    height: 100%;
    width: 100%;
    background: rgba(255, 255, 255, 0.85);
    transform-origin: left;
    animation: toastProgress 4s linear forwards;
}
@keyframes toastSlideIn {
    from { transform: translate(-50%, calc(-50% - 12px)); opacity: 0; }
    to { transform: translate(-50%, -50%); opacity: 1; }
}
@keyframes toastFadeOut {
    from { transform: translate(-50%, -50%); opacity: 1; }
    to { transform: translate(-50%, calc(-50% - 8px)); opacity: 0; }
}
@keyframes toastProgress {
    from { transform: scaleX(1); }
    to { transform: scaleX(0); }
}
@media (max-width: 768px) {
    .modal-dialog { margin: 10px !important; max-width: 95% !important; }
    .modal-content, .modal-header { border-radius: 0 !important; }
    .spending-success-toast {
        width: min(92vw, 440px);
    }
}
</style>
";
?>

<div class="container-fluid py-3 add-spending-page">
    <?php if (isset($_GET['success'])): ?>
        <div class="spending-success-toast" id="spendingSuccessToast" role="status" aria-live="polite">
            <div class="toast-head">
                <span class="toast-icon"><i class="bi bi-check-lg"></i></span>
                <div>
                    <p class="toast-title">Saved Successfully</p>
                    <p class="toast-desc">Spending record has been created.</p>
                </div>
                <button type="button" class="toast-close" aria-label="Close" onclick="closeSpendingSuccessToast()">&times;</button>
            </div>
            <div class="toast-progress"><span></span></div>
        </div>
    <?php endif; ?>
    <!-- Balance Warning -->
    <?php if ($current_balance <= 0): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Insufficient Funds!</strong> <?= htmlspecialchars($balance_label) ?>: $<?= number_format($current_balance, 2) ?>. 
            You cannot add spending when there's no money in the account. Please top up money first.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <div class="mt-2">
                <a href="finance_dashboard.php" class="btn btn-success btn-sm">
                    <i class="bi bi-wallet2 me-1"></i>Go to Dashboard to Top Up
                </a>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="row mb-4">
        <div class="col-12">
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h2><i class="bi bi-cash-stack me-2"></i>Add New Spending</h2>
                        <p class="subtitle mb-0">Record a new financial expense</p>
                    </div>
                    <a href="finance_reports.php" class="btn btn-light btn-sm">
                        <i class="bi bi-clock-history me-1"></i>Spending History
                    </a>
                </div>
            </div>
            <div class="alert alert-info balance-card mt-2 <?= $current_balance >= 0 ? 'text-success' : '' ?>">
                <i class="bi bi-info-circle me-2"></i>
                <strong><?= htmlspecialchars($balance_label) ?>:</strong> 
                <span class="fw-bold <?= $current_balance >= 0 ? 'text-success' : 'text-danger' ?>">
                    $<?= number_format($current_balance, 2) ?>
                </span>
                <?php if ($current_balance > 0): ?>
                    <span class="text-muted">(<?= htmlspecialchars($balance_hint) ?>)</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>Error:</strong><br>
            <?php foreach ($errors as $error): ?>
                <?= htmlspecialchars($error) ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card form-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>New Spending Record</h5>
        </div>
        <div class="card-body">
            <?php if ($current_balance <= 0): ?>
                <div class="text-center py-5">
                    <i class="bi bi-exclamation-triangle text-danger" style="font-size: 4rem;"></i>
                    <h4 class="mt-3 text-danger">Cannot Add Spending</h4>
                    <p class="text-muted">You have insufficient funds in your account.</p>
                    <p class="fw-bold"><?= htmlspecialchars($balance_label) ?>: <span class="text-danger">$<?= number_format($current_balance, 2) ?></span></p>
                    <a href="finance_dashboard.php" class="btn btn-success">
                        <i class="bi bi-wallet2 me-2"></i>Top Up Money First
                    </a>
                </div>
            <?php else: ?>
                <form method="post" class="row g-3" enctype="multipart/form-data">
                <!-- Spending Code -->
                <div class="col-md-6">
                    <label class="form-label">Spending Code *</label>
                    <input type="text" name="spending_code" class="form-control" 
                           value="<?= htmlspecialchars($_POST['spending_code'] ?? $spending_code) ?>" 
                           placeholder="Auto-generated or manual entry" required>
                </div>

                <!-- Amount -->
                <div class="col-md-6">
                    <label class="form-label">Amount of Payment *</label>
                    <div class="input-group">
                        <select class="form-select" name="currency" style="max-width: 120px;" required>
                            <option value="USD" <?= (($_POST['currency'] ?? 'USD') === 'USD') ? 'selected' : '' ?>>USD ($)</option>
                            <option value="KHR" <?= (($_POST['currency'] ?? '') === 'KHR') ? 'selected' : '' ?>>KHR (áŸ›)</option>
                        </select>
                        <input type="number" name="amount" class="form-control" 
                               value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" 
                               step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                    <small class="text-muted">Select currency and enter amount</small>
                </div>

                <!-- Paid By -->
                <div class="col-md-6">
                    <label class="form-label">Paid By *</label>
                    <select name="paid_by" class="form-select" required>
                        <option value="">Select who paid</option>
                        <?php 
                        $sel_paid = $_POST['paid_by'] ?? '';
                        foreach ($users as $user): ?>
                            <option value="<?= htmlspecialchars($user['name']) ?>" <?= $sel_paid === $user['name'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Receive By -->
                <div class="col-md-6">
                    <label class="form-label">Receive By</label>
                    <select name="receive_by" class="form-select">
                        <option value="">Select who received (optional)</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= htmlspecialchars($user['name']) ?>">
                                <?= htmlspecialchars($user['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date -->
                <div class="col-md-6">
                    <label class="form-label">Date *</label>
                    <input type="date" name="spending_date" class="form-control" 
                           value="<?= htmlspecialchars($_POST['spending_date'] ?? date('Y-m-d')) ?>" 
                           required>
                </div>

                <!-- Status -->
                <div class="col-md-6">
                    <label class="form-label">Status *</label>
                    <select name="status" class="form-select" required>
                        <option value="">Select status</option>
                        <option value="pending" <?= (($_POST['status'] ?? '') === 'pending') ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= (($_POST['status'] ?? '') === 'approved') ? 'selected' : '' ?>>Approved</option>
                        <option value="completed" <?= (($_POST['status'] ?? '') === 'completed') ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= (($_POST['status'] ?? '') === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>

                <!-- Spend To (Main Category) -->
                <div class="col-md-6">
                    <label class="form-label">Spend To *</label>
                    <select name="spend_to" class="form-select" id="spendToSelect" required>
                        <option value="">Select main category</option>
                        <?php foreach ($main_categories as $category): ?>
                            <option value="<?= htmlspecialchars($category['name']) ?>" 
                                    <?= (($_POST['spend_to'] ?? '') === $category['name']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $category['name']))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Sub Categories (Dynamic rows like seller system) -->
                <div class="col-12">
                    <label class="form-label">Sub Categories *</label>
                    <div id="subCategoryRows" class="d-flex flex-column gap-2">
                        <!-- Subcategory rows will be added here dynamically -->
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addSubCategoryRow">
                        <i class="bi bi-plus-circle me-1"></i>Add More Subcategories
                    </button>
                    <small class="form-text text-muted">Add one or more sub categories for better expense tracking.</small>
                </div>

                <!-- Image Upload -->
                <div class="col-12">
                    <label class="form-label">Upload Images (Optional)</label>
                    <div class="border rounded p-3 bg-light">
                        <input type="file" name="spending_images[]" class="form-control mb-2" 
                               accept="image/*,.pdf,.doc,.docx" multiple>
                        <small class="form-text text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Upload multiple images, receipts, or documents (JPG, PNG, GIF, PDF, DOC, DOCX). Maximum file size: 2MB per file. Images will be compressed to save storage space.
                        </small>
                        <div id="imagePreview" class="mt-2 d-flex flex-wrap gap-2"></div>
                    </div>
                </div>

                <!-- Note -->
                <div class="col-12">
                    <label class="form-label">Note</label>
                    <textarea name="note" class="form-control" rows="3" 
                              placeholder="Add any additional notes or details..."><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                </div>

                <!-- Form Actions -->
                <div class="col-12">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>Save Spending
                        </button>
                        <a href="finance_dashboard.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-1"></i>Cancel
                        </a>
                    </div>
                </div>
            </form>
        <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Sub categories from database
const subCategories = <?php 
    $db_subcategories = [];
    foreach ($subcategories_by_parent as $parent => $subcats) {
        $db_subcategories[$parent] = array_map(function($subcat) {
            return ucfirst(str_replace('_', ' ', $subcat['name']));
        }, $subcats);
    }
    echo json_encode($db_subcategories);
?>;

const spendToSelectEl = document.getElementById('spendToSelect');
const subCategoryRowsEl = document.getElementById('subCategoryRows');
const addSubCategoryRowEl = document.getElementById('addSubCategoryRow');
const spendingImageInputEl = document.querySelector('input[name="spending_images[]"]');

// Update sub categories when main category changes (like seller system)
if (spendToSelectEl) spendToSelectEl.addEventListener('change', function() {
    const mainCategory = this.value;
    
    // Clear all existing rows
    const rowsContainer = subCategoryRowsEl;
    if (!rowsContainer) return;
    rowsContainer.innerHTML = '';
    
    if (mainCategory && subCategories[mainCategory]) {
        // Create initial row
        createSubCategoryRow();
    }
});

function createSubCategoryRow() {
    if (!spendToSelectEl || !subCategoryRowsEl) return;
    const mainCategory = spendToSelectEl.value;
    const rowsContainer = subCategoryRowsEl;
    
    if (!mainCategory || !subCategories[mainCategory]) return;
    
    const rowCount = rowsContainer.querySelectorAll('.subcategory-row').length;
    const row = document.createElement('div');
    row.className = 'row g-2 align-items-center subcategory-row';
    row.innerHTML = `
        <div class="col-md-8">
            <select name="sub_categories[]" class="form-select subcategory-select" required>
                <option value="">Select sub category</option>
                ${subCategories[mainCategory].map(subCat => 
                    `<option value="${subCat.toLowerCase().replace(/\s+/g, '_')}">${subCat}</option>`
                ).join('')}
            </select>
        </div>
        <div class="col-md-4">
            <button type="button" class="btn btn-outline-danger btn-sm remove-row" ${rowCount === 0 ? 'style="display:none;"' : ''}>
                <i class="bi bi-trash"></i> Remove
            </button>
        </div>
    `;
    
    rowsContainer.appendChild(row);
    
    // Show/hide remove buttons based on row count
    updateRemoveButtons();
}

function updateRemoveButtons() {
    const rows = document.querySelectorAll('.subcategory-row');
    rows.forEach((row, index) => {
        const removeBtn = row.querySelector('.remove-row');
        if (removeBtn) {
            removeBtn.style.display = rows.length > 1 ? 'inline-block' : 'none';
        }
    });
}

// Add more subcategories
 if (addSubCategoryRowEl) addSubCategoryRowEl.addEventListener('click', function() {
    createSubCategoryRow();
});

// Remove subcategory row
if (subCategoryRowsEl) subCategoryRowsEl.addEventListener('click', function(e) {
    if (e.target.closest('.remove-row')) {
        const rows = document.querySelectorAll('.subcategory-row');
        if (rows.length > 1) {
            e.target.closest('.subcategory-row').remove();
            updateRemoveButtons();
        }
    }
});

// Prevent duplicate subcategories
if (subCategoryRowsEl) subCategoryRowsEl.addEventListener('change', function(e) {
    if (e.target.classList.contains('subcategory-select')) {
        const currentSelect = e.target;
        const selectedValue = currentSelect.value;
        
        if (selectedValue) {
            const currentRow = currentSelect.closest('.subcategory-row');
            let duplicateFound = false;
            
            document.querySelectorAll('.subcategory-select').forEach(select => {
                if (select !== currentSelect && select.value === selectedValue) {
                    duplicateFound = true;
                }
            });
            
            if (duplicateFound) {
                alert('This subcategory is already selected. Please choose a different one.');
                currentSelect.value = '';
            }
        }
    }
});

// Initialize sub categories if main category is pre-selected
window.addEventListener('load', function() {
    if (!spendToSelectEl) return;
    const mainCategory = spendToSelectEl.value;
    if (mainCategory) {
        createSubCategoryRow();
    }
});

// Image preview functionality
if (spendingImageInputEl) spendingImageInputEl.addEventListener('change', function(e) {
    const files = e.target.files;
    const preview = document.getElementById('imagePreview');
    if (!preview) return;
    preview.innerHTML = '';
    
    Array.from(files).forEach((file, index) => {
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const div = document.createElement('div');
                div.className = 'position-relative d-inline-block';
                div.innerHTML = `
                    <img src="${e.target.result}" class="img-thumbnail" style="max-width: 100px; max-height: 100px;">
                    <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1" onclick="removeImage(${index})">
                        <i class="bi bi-x"></i>
                    </button>
                `;
                preview.appendChild(div);
            };
            reader.readAsDataURL(file);
        } else {
            const div = document.createElement('div');
            div.className = 'd-inline-block border rounded p-2 m-1 bg-white';
            div.innerHTML = `
                <i class="bi bi-file-earmark text-primary"></i>
                <small class="d-block">${file.name}</small>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeImage(${index})">
                    <i class="bi bi-x"></i>
                </button>
            `;
            preview.appendChild(div);
        }
    });
});

function removeImage(index) {
    const input = spendingImageInputEl;
    if (!input) return;
    const files = Array.from(input.files);
    files.splice(index, 1);
    
    // Create new FileList
    const dt = new DataTransfer();
    files.forEach(file => dt.items.add(file));
    input.files = dt.files;
    
    // Trigger change event to update preview
    input.dispatchEvent(new Event('change'));
}

function initFormFieldUX() {
    const fields = document.querySelectorAll('.add-spending-page form input:not([type=\"hidden\"]):not([type=\"file\"]), .add-spending-page form select, .add-spending-page form textarea');
    fields.forEach(function(field) {
        const refreshState = function() {
            const val = (field.value || '').toString().trim();
            field.classList.toggle('is-filled', val !== '');
        };
        refreshState();
        field.addEventListener('input', refreshState);
        field.addEventListener('change', refreshState);
        field.addEventListener('blur', function() {
            field.classList.add('is-touched');
            refreshState();
        });
    });
}

function closeSpendingSuccessToast() {
    const toast = document.getElementById('spendingSuccessToast');
    if (!toast || toast.classList.contains('is-hiding')) return;
    toast.classList.add('is-hiding');
    window.setTimeout(function() {
        toast.remove();
    }, 420);
}

window.addEventListener('DOMContentLoaded', function() {
    initFormFieldUX();
    const toast = document.getElementById('spendingSuccessToast');
    if (!toast) return;
    window.setTimeout(closeSpendingSuccessToast, 4000);
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
