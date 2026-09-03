<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_dashboard.view', 'sr_expense_topup.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role_or_permission(['admin'], 'finance_dashboard.delete', 'sr_expense_topup.delete');
    $id = (int)($_POST['id'] ?? 0);
    $redirect = $_POST['redirect'] ?? 'finance_dashboard.php';
    
    if ($id <= 0) {
        header('Location: finance_dashboard.php?topup_error=' . urlencode('Invalid top-up ID'));
        exit;
    }
    
    try {
        // Get the top-up to be deleted
        $stmt = $pdo->prepare("SELECT * FROM finance_topups WHERE id = ?");
        $stmt->execute([$id]);
        $topup = $stmt->fetch();
        
        if (!$topup) {
            $dest = ($redirect && $redirect !== 'finance_dashboard.php') ? $redirect : 'finance_dashboard.php';
            $sep = strpos($dest, '?') !== false ? '&' : '?';
            header('Location: ' . $dest . $sep . 'topup_error=' . urlencode('Top-up record not found'));
            exit;
        }
        
        // Calculate current totals
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) as total_topups FROM finance_topups');
        $total_topups = $stmt->fetch()['total_topups'] ?? 0;
        
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) as total_spending FROM finance_spending');
        $total_spending = $stmt->fetch()['total_spending'] ?? 0;
        
        $current_balance = $total_topups - $total_spending;
        $new_balance = ($total_topups - $topup['amount']) - $total_spending;
        
        // More flexible deletion logic - like real accounting
        // Allow deletion unless it would create a severely negative balance
        if ($new_balance < -1000) { // Allow up to $1000 negative
            $error_message = "Cannot delete this top-up! 
                            Current Balance: $" . number_format($current_balance, 2) . "
                            Top-up Amount: $" . number_format($topup['amount'], 2) . "
                            New Balance: $" . number_format($new_balance, 2) . "
                            
                            Deleting this top-up would make the balance too negative (over $1000).
                            Please add more funds or reduce spending first.";
            
            $dest = ($redirect && $redirect !== 'finance_dashboard.php') ? $redirect : 'finance_dashboard.php';
            $sep = strpos($dest, '?') !== false ? '&' : '?';
            header('Location: ' . $dest . $sep . 'topup_error=' . urlencode($error_message));
            exit;
        }
        
        // Allow deletion in most cases - real accounting allows some overdraft
        // Only block if it would create extreme negative balance
        if ($new_balance < -1000) {
            // Block extreme negative cases
        } else {
            // Allow deletion - even if it creates some negative balance
            // This is more realistic for business operations
        }
        
        // If safe to delete, proceed with deletion
        if ($topup['receipt_image'] && file_exists(__DIR__ . '/../' . $topup['receipt_image'])) {
            unlink(__DIR__ . '/../' . $topup['receipt_image']);
        }
        
        $stmt = $pdo->prepare("DELETE FROM finance_topups WHERE id = ?");
        $stmt->execute([$id]);
        
        $sep = strpos($redirect, '?') !== false ? '&' : '?';
        header('Location: ' . $redirect . $sep . 'topup_success=1&message=' . urlencode('Top-up deleted successfully!'));
        exit;
        
    } catch (PDOException $e) {
        $dest = ($redirect && $redirect !== 'finance_dashboard.php') ? $redirect : 'finance_dashboard.php';
        $sep = strpos($dest, '?') !== false ? '&' : '?';
        header('Location: ' . $dest . $sep . 'topup_error=' . urlencode('Database error: ' . $e->getMessage()));
        exit;
    }
}

// If not POST request, redirect to dashboard
header('Location: finance_dashboard.php');
exit;
?>
