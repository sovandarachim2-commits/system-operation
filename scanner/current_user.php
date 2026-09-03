<?php
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

echo json_encode([
    'success'  => true,
    'id'       => (int)$user['id'],
    'username' => $user['username'],
]);
