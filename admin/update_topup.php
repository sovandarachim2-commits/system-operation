<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_dashboard.view', 'sr_expense_topup.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role_or_permission(['admin'], 'finance_dashboard.update', 'sr_expense_topup.update');
    $id = (int)($_POST['id'] ?? 0);
    $redirect = $_POST['redirect'] ?? 'finance_dashboard.php';
    $amount = (float)($_POST['amount'] ?? 0);
    $source = $_POST['source'] ?? '';
    $description = $_POST['description'] ?? '';
    $person_name = $_POST['person_name'] ?? '';
    $topup_date = $_POST['topup_date'] ?? date('Y-m-d');
    
    if ($id <= 0) {
        header('Location: finance_dashboard.php?topup_error=' . urlencode('Invalid top-up ID'));
        exit;
    }
    
    // Handle image upload (optional)
    $receipt_image = null;
    if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['receipt_image'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (in_array($file['type'], $allowed_types) && $file['size'] <= $max_size) {
            $filename = 'receipt_' . time() . '_' . uniqid() . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
            try {
                $receipt_image = upload_store_uploaded_file($file, 'receipts', $filename, $topup_date, (string)($file['type'] ?? ''));
            } catch (Throwable $e) {
                $receipt_image = null;
            }
            if ($receipt_image) {
                
                // Delete old receipt if exists
                $stmt = $pdo->prepare("SELECT receipt_image FROM finance_topups WHERE id = ?");
                $stmt->execute([$id]);
                $old_receipt = $stmt->fetchColumn();
                
                upload_delete_local_file($old_receipt ?: null, 'receipts');
            }
        }
    }
    
    // Validation
    $errors = [];
    if ($amount <= 0) $errors[] = 'Amount must be greater than 0';
    if (empty($source)) $errors[] = 'Source is required';
    if (empty($person_name)) $errors[] = 'Person name is required';
    
    if (empty($errors)) {
        try {
            // Build update query
            $update_fields = [
                'amount = ?',
                'source = ?',
                'description = ?',
                'topup_date = ?',
                'person_name = ?',
                'updated_at = CURRENT_TIMESTAMP'
            ];
            $update_params = [$amount, $source, $description, $topup_date, $person_name];
            
            // Add receipt image if uploaded
            if ($receipt_image) {
                $update_fields[] = 'receipt_image = ?';
                $update_params[] = $receipt_image;
            }
            
            $update_params[] = $id; // WHERE clause
            
            $sql = "UPDATE finance_topups SET " . implode(', ', $update_fields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($update_params);
            
            $sep = strpos($redirect, '?') !== false ? '&' : '?';
            header('Location: ' . $redirect . $sep . 'topup_success=1&message=' . urlencode('Top-up updated successfully!'));
            exit;
            
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    
    // If there are errors, redirect back with error message
    if (!empty($errors)) {
        $dest = ($redirect && $redirect !== 'finance_dashboard.php') ? $redirect : 'finance_dashboard.php';
        $sep = strpos($dest, '?') !== false ? '&' : '?';
        header('Location: ' . $dest . $sep . 'topup_error=' . urlencode(implode(', ', $errors)));
        exit;
    }
}

// If not POST request, redirect to dashboard
header('Location: finance_dashboard.php');
exit;
?>
