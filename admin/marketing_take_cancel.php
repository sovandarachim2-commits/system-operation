<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take.create');
require_once __DIR__ . '/../db.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: marketing_take_list.php');
    exit;
}

$pdo = get_db_connection();
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$userRoles = $currentUser ? user_role_names($pdo, $currentUser) : [];
$isAdmin = in_array('admin', $userRoles, true);

$stmt = $pdo->prepare("SELECT id, status, created_by FROM marketing_takes WHERE id = ?");
$stmt->execute([$id]);
$take = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$take || $take['status'] !== 'pending_approval') {
    $_SESSION['marketing_take_flash'] = ['message' => 'Market take not found or cannot be cancelled.', 'type' => 'warning'];
    header('Location: marketing_take_list.php');
    exit;
}
if (!$isAdmin && $userId > 0 && (int)$take['created_by'] !== $userId) {
    $_SESSION['marketing_take_flash'] = ['message' => 'You can only cancel your own requests.', 'type' => 'warning'];
    header('Location: marketing_take_list.php');
    exit;
}

$pdo->prepare("UPDATE marketing_takes SET status = 'rejected', approved_by = NULL, approved_at = NOW() WHERE id = ?")->execute([$id]);
$_SESSION['marketing_take_flash'] = ['message' => 'Market take cancelled.', 'type' => 'info'];
header('Location: marketing_take_list.php');
exit;
