<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take_approve.view');
require_once __DIR__ . '/../db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: marketing_take_approve_history.php');
    exit;
}

$pdo = get_db_connection();
$stmt = $pdo->prepare("SELECT id, status FROM marketing_takes WHERE id = ?");
$stmt->execute([$id]);
$take = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$take || $take['status'] !== 'rejected') {
    $_SESSION['marketing_take_flash'] = ['message' => 'Request not found or cannot be re-opened.', 'type' => 'warning'];
    header('Location: marketing_take_approve_history.php');
    exit;
}

$pdo->prepare("UPDATE marketing_takes SET status = 'pending_approval', approved_by = NULL, approved_at = NULL WHERE id = ?")->execute([$id]);
$_SESSION['marketing_take_flash'] = ['message' => 'Request re-opened. It is now pending approval again.', 'type' => 'success'];
header('Location: marketing_take_approve.php');
exit;
