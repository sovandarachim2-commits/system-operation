<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed.', 405);
}

$raw = file_get_contents('php://input') ?: '';
$json = json_decode($raw, true);
$input = is_array($json) ? $json : $_POST;

$username = trim((string)($input['username'] ?? ''));
$password = trim((string)($input['password'] ?? ''));

if ($username === '' || $password === '') {
    api_error('Please enter username and password.', 422);
}

try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || hash('sha256', $password) !== (string)($user['password_hash'] ?? '')) {
        require_once __DIR__ . '/../user_activity_lib.php';
        user_activity_log($pdo, null, 'login_failed', 'username: ' . $username);
        api_error('Invalid username or password.', 401);
    }

    $_SESSION['user_id'] = (int)$user['id'];
    session_regenerate_id(true);
    auth_set_user_last_seen_now($pdo, (int)$user['id']);

    require_once __DIR__ . '/../user_activity_lib.php';
    user_activity_log($pdo, $user, 'login_success', null, ['username' => (string)($user['username'] ?? '')]);

    $sessionUser = current_user(true) ?: $user;

    api_json([
        'success' => true,
        'user' => api_user_payload($sessionUser),
        'report_token' => api_issue_report_token($sessionUser),
    ]);
} catch (Throwable $e) {
    error_log('api/login.php: ' . $e->getMessage());
    api_error('Unable to login right now.', 500);
}
