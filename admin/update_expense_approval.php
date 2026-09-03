<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'spending.update', 'sr_expense_approvals.update');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$spendingId = filter_var($_POST['spending_id'] ?? null, FILTER_VALIDATE_INT);
$status = strtolower(trim((string)($_POST['status'] ?? '')));
$allowedStatuses = ['pending', 'approved', 'completed', 'cancelled'];

if (!$spendingId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Spending ID is required.']);
    exit;
}

if (!in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid approval status.']);
    exit;
}

$currentUser = function_exists('current_user') ? current_user() : null;
$updatedBy = ($currentUser && isset($currentUser['id'])) ? (int)$currentUser['id'] : null;

try {
    try {
        $stmt = $pdo->prepare("UPDATE finance_spending SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $updatedBy, $spendingId]);
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'updated_by') === false && strpos($e->getMessage(), 'updated_at') === false) {
            throw $e;
        }
        $stmt = $pdo->prepare("UPDATE finance_spending SET status = ? WHERE id = ?");
        $stmt->execute([$status, $spendingId]);
    }
    if ($stmt->rowCount() < 1) {
        $exists = $pdo->prepare("SELECT id FROM finance_spending WHERE id = ? LIMIT 1");
        $exists->execute([$spendingId]);
        if (!$exists->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Expense record not found.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Expense status already saved.', 'status' => $status, 'updated_at' => date('c')]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Expense status updated.', 'status' => $status, 'updated_at' => date('c')]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
