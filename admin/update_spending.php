<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'spending.view', 'sr_expense_records.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

function ensure_finance_spending_update_columns(PDO $pdo): void {
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
        if (!in_array('updated_by', $cols, true)) {
            $pdo->exec("ALTER TABLE finance_spending ADD COLUMN updated_by INT NULL AFTER created_by");
        }
        if (!in_array('updated_at', $cols, true)) {
            $pdo->exec("ALTER TABLE finance_spending ADD COLUMN updated_at DATETIME NULL AFTER updated_by");
        }
    } catch (Throwable $e) {
        error_log('finance_spending update column check failed: ' . $e->getMessage());
    }
}

ensure_finance_spending_update_columns($pdo);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role_or_permission(['admin'], 'spending.update', 'sr_expense_records.update');
    $spending_id = $_POST['spending_id'] ?? '';
    $currentUser = function_exists('current_user') ? current_user() : null;
    $updatedBy = ($currentUser && isset($currentUser['id'])) ? (int)$currentUser['id'] : null;
    $spending_code = $_POST['spending_code'] ?? '';
    $amount = (float)($_POST['amount'] ?? 0);
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
    
    // Filter out empty values and prepare for storage
    $sub_categories_list = array_filter($sub_categories_list, function($cat) {
        return !empty(trim($cat));
    });
    
    // For backward compatibility, use first subcategory as the main one
    $sub_category = !empty($sub_categories_list) ? $sub_categories_list[0] : '';
    $sub_categories = json_encode(array_values($sub_categories_list));
    $note = $_POST['note'] ?? '';
    
    // Handle image management
    $removed_images = $_POST['removed_images'] ?? '';
    $removed_images_array = !empty($removed_images) ? json_decode($removed_images, true) : [];
    
    // Get current images from database
    $stmt = $pdo->prepare('SELECT images FROM finance_spending WHERE id = ?');
    $stmt->execute([$spending_id]);
    $current_spending = $stmt->fetch();
    $current_images = !empty($current_spending['images']) ? json_decode($current_spending['images'], true) : [];
    
    // Remove specified images
    $final_images = array_diff($current_images, $removed_images_array);
    
    // Delete removed image files
    foreach ($removed_images_array as $removed_image) {
        upload_delete_local_file($removed_image, 'spending_images');
    }
    
    // Handle new image uploads
    $new_uploaded_images = [];
    if (!empty($_FILES['new_images']['name'][0])) {
        foreach ($_FILES['new_images']['name'] as $key => $name) {
            if (!empty($name)) {
                $file_tmp = $_FILES['new_images']['tmp_name'][$key];
                $file_size = $_FILES['new_images']['size'][$key];
                $file_error = $_FILES['new_images']['error'][$key];
                
                if ($file_error === UPLOAD_ERR_OK && $file_size > 0) {
                    // Check file size (max 2MB)
                    $max_size = 2 * 1024 * 1024; // 2MB
                    if ($file_size > $max_size) {
                        continue; // Skip large files
                    }
                    
                    $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
                    
                    if (in_array($file_ext, $allowed_extensions)) {
                        $new_filename = 'spending_' . time() . '_' . $key . '.' . $file_ext;
                        $storedPath = upload_store_uploaded_file([
                            'error' => $file_error,
                            'tmp_name' => $file_tmp,
                            'type' => $_FILES['new_images']['type'][$key] ?? '',
                        ], 'spending_images', $new_filename, $spending_date, (string)($_FILES['new_images']['type'][$key] ?? ''));
                        if ($storedPath !== '') {
                            $new_uploaded_images[] = preg_replace('#^uploads/spending_images/#', '', $storedPath);
                        }
                    }
                }
            }
        }
    }
    
    // Combine remaining images with new uploads
    $final_images = array_merge($final_images, $new_uploaded_images);
    $images_json = json_encode(array_values($final_images));
    
    // Validation
    $errors = [];
    if (empty($spending_id)) $errors[] = 'Spending ID is required';
    if (empty($spending_code)) $errors[] = 'Spending code is required';
    if ($amount <= 0) $errors[] = 'Amount must be greater than 0';
    if (empty($spending_date)) $errors[] = 'Spending date is required';
    if (empty($status)) $errors[] = 'Status is required';
    if (empty($spend_to)) $errors[] = 'Spend to is required';
    
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
    
    if (empty($errors)) {
        try {
            $beforeStmt = $pdo->prepare('SELECT * FROM finance_spending WHERE id = ? LIMIT 1');
            $beforeStmt->execute([(int)$spending_id]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // Update spending record
            $stmt = $pdo->prepare('UPDATE finance_spending 
                SET spending_code = ?, amount = ?, payment_method = ?, company_id = ?, paid_by = ?, receive_by = ?,
                    spending_date = ?, status = ?, category = ?, sub_category = ?, sub_categories = ?, note = ?, images = ?,
                    updated_by = ?, updated_at = NOW()
                WHERE id = ?');
            
            $result = $stmt->execute([
                $spending_code,
                $amount,
                $payment_method,
                $company_id,
                $paid_by,
                $receive_by,
                $spending_date,
                $status,
                $spend_to,
                $sub_category, // Keep for backward compatibility
                $sub_categories,
                $note,
                $images_json,
                $updatedBy,
                $spending_id
            ]);
            
            if ($result) {
                require_once __DIR__ . '/finance_spending_history_lib.php';
                $changes = [];
                $map = [
                    'amount' => [(float)($before['amount'] ?? 0), (float)$amount, static function ($v) {
                        return '$' . number_format((float)$v, 2);
                    }],
                    'payment_method' => [(string)($before['payment_method'] ?? ''), (string)$payment_method],
                    'company_id' => [(string)($before['company_id'] ?? ''), (string)($company_id ?? '')],
                    'paid_by' => [(string)($before['paid_by'] ?? ''), (string)$paid_by],
                    'receive_by' => [(string)($before['receive_by'] ?? ''), (string)$receive_by],
                    'spending_date' => [(string)($before['spending_date'] ?? ''), (string)$spending_date],
                    'status' => [(string)($before['status'] ?? ''), (string)$status],
                    'category' => [(string)($before['category'] ?? ''), (string)$spend_to],
                    'sub_categories' => [(string)($before['sub_categories'] ?? ''), (string)$sub_categories],
                    'note' => [(string)($before['note'] ?? ''), (string)$note],
                ];
                foreach ($map as $field => $pair) {
                    $old = $pair[0];
                    $new = $pair[1];
                    if ((string)$old === (string)$new) {
                        continue;
                    }
                    $fmt = $pair[2] ?? null;
                    $oldLabel = is_callable($fmt) ? $fmt($old) : (string)$old;
                    $newLabel = is_callable($fmt) ? $fmt($new) : (string)$new;
                    $changes[] = $field . ': ' . ($oldLabel !== '' ? $oldLabel : '-') . ' → ' . ($newLabel !== '' ? $newLabel : '-');
                }
                $imgBefore = !empty($before['images']) ? (json_decode((string)$before['images'], true) ?: []) : [];
                $imgAfter = !empty($images_json) ? (json_decode((string)$images_json, true) ?: []) : [];
                if (count($imgBefore) !== count($imgAfter)) {
                    $changes[] = 'images: ' . count($imgBefore) . ' → ' . count($imgAfter);
                }
                finance_spending_history_log(
                    $pdo,
                    (int)$spending_id,
                    'Updated',
                    $changes ? implode(' · ', $changes) : 'Expense record updated',
                    is_array($currentUser) ? $currentUser : null,
                    (string)$spending_code
                );
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Spending updated successfully']);
                exit;
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Failed to update spending']);
                exit;
            }
            
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        exit;
    }
}

// If not POST request, return error
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Invalid request method']);
?>









