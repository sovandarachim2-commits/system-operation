<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

require_once __DIR__ . '/auth.php';
auth_start_session();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && hash('sha256', $password) === $user['password_hash']) {
            $_SESSION['user_id'] = $user['id'];
            session_regenerate_id(true);
            auth_set_user_last_seen_now($pdo, (int)$user['id']);
            require_once __DIR__ . '/user_activity_lib.php';
            user_activity_log($pdo, $user, 'login_success', null, ['username' => (string)($user['username'] ?? '')]);
            header('Location: index.php', true, 303);
            header('Cache-Control: no-store, no-cache, must-revalidate');
            exit;
        } else {
            require_once __DIR__ . '/user_activity_lib.php';
            user_activity_log($pdo, null, 'login_failed', 'username: ' . $username);
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ONS-Shadow</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($BASE_URL) ?>/public/image.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($BASE_URL) ?>/public/image.png">
    <link rel="apple-touch-icon-precomposed" href="<?= htmlspecialchars($BASE_URL) ?>/public/image.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="public/css/app.css" rel="stylesheet">
</head>
<body class="bg-dark text-light fullscreen-center" style="overflow:hidden;">
<?php require_once __DIR__ . '/layout/page_loader.php'; ?>
<div id="pageContent" class="card card-fullscreen shadow-lg" style="visibility:hidden;">
    <div class="card-body p-4">
        <div class="text-center mb-4">
            <img src="<?= htmlspecialchars($BASE_URL) ?>/public/image.png" alt="Order System Logo" class="mb-3" style="height: 64px; width: auto;">
        </div>
        <h1 class="h3 text-center mb-4">MURU System</h1>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" class="d-flex flex-column gap-3">
            <div>
                <label for="username" class="form-label">Username</label>
                <input type="text" id="username" name="username" class="form-control form-control-lg" autofocus required>
            </div>
            <div>
                <label for="password" class="form-label">Password</label>
                <input type="password" id="password" name="password" class="form-control form-control-lg" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100 mt-2">Login</button>
        </form>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

