<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'spending.view', 'sr_expense_records.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

// Handle POST request for deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role_or_permission(['admin'], 'spending.delete', 'sr_expense_records.delete');
    $spending_id = $_POST['spending_id'] ?? '';
    $redirect = $_POST['redirect'] ?? 'finance_dashboard.php';
    
    if (empty($spending_id)) {
        header('Location: finance_dashboard.php?error=Missing spending ID');
        exit;
    }
    
    try {
        $id = (int)$spending_id;
        $beforeStmt = $pdo->prepare('SELECT id, spending_code, amount, status FROM finance_spending WHERE id = ? LIMIT 1');
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($before) {
            require_once __DIR__ . '/finance_spending_history_lib.php';
            $currentUser = function_exists('current_user') ? current_user() : null;
            finance_spending_history_log(
                $pdo,
                $id,
                'Deleted',
                'Expense record deleted · $' . number_format((float)($before['amount'] ?? 0), 2) . ' · ' . (string)($before['status'] ?? ''),
                is_array($currentUser) ? $currentUser : null,
                (string)($before['spending_code'] ?? '')
            );
        }

        // Delete spending record
        $stmt = $pdo->prepare('DELETE FROM finance_spending WHERE id = ?');
        $result = $stmt->execute([$id]);
        
        if ($result) {
            $sep = strpos($redirect, '?') !== false ? '&' : '?';
            header('Location: ' . $redirect . $sep . 'success=' . urlencode('Spending deleted successfully'));
            exit;
        } else {
            $sep = strpos($redirect, '?') !== false ? '&' : '?';
            header('Location: ' . $redirect . $sep . 'error=' . urlencode('Failed to delete spending'));
            exit;
        }
        
    } catch (PDOException $e) {
        $base = ($redirect === 'finance_dashboard.php' || empty($redirect)) ? 'finance_dashboard.php' : $redirect;
        $sep = strpos($base, '?') !== false ? '&' : '?';
        header('Location: ' . $base . $sep . 'error=' . urlencode('Database error: ' . $e->getMessage()));
        exit;
    }
}

// If not POST request, redirect to dashboard
header('Location: finance_dashboard.php');
exit;
?>
