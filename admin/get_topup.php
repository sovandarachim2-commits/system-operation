<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_dashboard.view', 'sr_expense_topup.view');
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

$pdo = get_db_connection();

$id = $_GET['id'] ?? '';

if (empty($id)) {
    echo json_encode(['success' => false, 'message' => 'Top-up ID is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM finance_topups WHERE id = ?");
    $stmt->execute([$id]);
    $topup = $stmt->fetch();
    
    if ($topup) {
        echo json_encode([
            'success' => true,
            'topup' => $topup
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Top-up record not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
