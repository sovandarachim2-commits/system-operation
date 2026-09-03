<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/user_activity_lib.php';

$u = current_user();
if ($u) {
    user_activity_log(get_db_connection(), $u, 'logout');
}

session_unset();
session_destroy();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: ' . auth_login_path());
exit;
