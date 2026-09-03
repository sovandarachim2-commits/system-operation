<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = current_user(true);

if (!$user) {
    api_json([
        'success' => true,
        'user' => null,
    ]);
}

$pdo = get_db_connection();

api_json([
    'success' => true,
    'user' => api_user_payload($user),
]);
