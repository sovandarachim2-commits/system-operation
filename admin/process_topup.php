<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_dashboard.view', 'sr_expense_topup.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

function normalize_redirect_target(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return 'finance_dashboard.php';
    $parsedPath = parse_url($raw, PHP_URL_PATH);
    $parsedQuery = parse_url($raw, PHP_URL_QUERY);
    $base = basename((string)$parsedPath);
    $allowed = ['finance_dashboard.php', 'add_topup.php'];
    if (!in_array($base, $allowed, true)) return 'finance_dashboard.php';
    return $base . ($parsedQuery ? ('?' . $parsedQuery) : '');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role_or_permission(['admin'], 'finance_dashboard.create', 'sr_expense_topup.create');
    $amount = (float)($_POST['amount'] ?? 0);
    $source = $_POST['source'] ?? '';
    $description = $_POST['description'] ?? '';
    $person_name = $_POST['person_name'] ?? '';
    $topup_date = $_POST['topup_date'] ?? date('Y-m-d');
    
    // Debug: Show what we received
    error_log("DEBUG: Received source: " . var_export($source, true));
    error_log("DEBUG: POST data: " . print_r($_POST, true));
    
    // Handle image upload
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
        }
    }
    
    // Validation
    $errors = [];
    if ($amount <= 0) $errors[] = 'Amount must be greater than 0';
    if (empty($source)) $errors[] = 'Source is required';
    if (empty($person_name)) $errors[] = 'Person name is required';
    
    if (empty($errors)) {
        try {
            // Insert top-up record
            $stmt = $pdo->prepare('INSERT INTO finance_topups 
                (amount, source, description, topup_date, receipt_image, person_name, created_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)');
            
            $currentUser = function_exists('current_user') ? current_user() : null;
            // Store user ID so display JOIN resolves to correct name from users table
            $createdBy = ($currentUser && isset($currentUser['id'])) ? (int)$currentUser['id'] : 'Admin';
            
            $stmt->execute([
                $amount,
                $source,
                $description,
                $topup_date,
                $receipt_image,
                $person_name,
                $createdBy
            ]);
            
            $redirect = normalize_redirect_target($_POST['redirect'] ?? '');
            header('HTTP/1.1 303 See Other');
            $sep = strpos($redirect, '?') !== false ? '&' : '?';
            header('Location: ' . $redirect . $sep . 'topup_success=1');
            exit;
            
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    
    // If there are errors, redirect back with error message
    if (!empty($errors)) {
        $redirect = normalize_redirect_target($_POST['redirect'] ?? '');
        header('HTTP/1.1 303 See Other');
        $sep = strpos($redirect, '?') !== false ? '&' : '?';
        header('Location: ' . $redirect . $sep . 'topup_error=' . urlencode(implode(', ', $errors)));
        exit;
    }
}

// If not POST request, redirect to dashboard
header('Location: finance_dashboard.php');
exit;
?>
