<?php
/**
 * @deprecated Open admin/user_activity/user_activity_log.php instead.
 */
require_once __DIR__ . '/../config.php';
$q = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . rtrim($BASE_URL, '/') . '/admin/user_activity/user_activity_log.php' . $q, true, 302);
exit;
