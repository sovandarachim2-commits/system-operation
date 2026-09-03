<?php
/**
 * Scanner module configuration
 * Reuses main Order System config and DB, adds scanner-specific settings.
 */

// Load main config (DB settings, timezone, etc.)
require_once __DIR__ . '/../config.php';

// Map main config.php variables into constants for scanner usage
define('DB_HOST', $DB_HOST);
define('DB_USER', $DB_USER);
define('DB_PASS', $DB_PASS);
define('DB_NAME', $DB_NAME);

// DOMAIN for frontend JS: use main DOMAIN plus /scanner
define('DOMAIN', $DOMAIN . '/scanner');

// Upload paths for scanner images
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', DOMAIN . '/uploads/');

// Storage mode for scanner images: local or r2
$SCANNER_STORAGE_DRIVER = $SCANNER_STORAGE_DRIVER ?? 'local';

// Cloudflare R2 settings for scanner image storage
$SCANNER_R2_ACCOUNT_ID = $SCANNER_R2_ACCOUNT_ID ?? '';
$SCANNER_R2_BUCKET = $SCANNER_R2_BUCKET ?? '';
$SCANNER_R2_ACCESS_KEY_ID = $SCANNER_R2_ACCESS_KEY_ID ?? '';
$SCANNER_R2_SECRET_ACCESS_KEY = $SCANNER_R2_SECRET_ACCESS_KEY ?? '';
$SCANNER_R2_PUBLIC_BASE_URL = $SCANNER_R2_PUBLIC_BASE_URL ?? '';
$SCANNER_R2_OBJECT_PREFIX = $SCANNER_R2_OBJECT_PREFIX ?? 'scanner';

require_once __DIR__ . '/storage.php';

// Telegram Bot Config (scanner can reuse or override main settings)
$scannerToken = '';
if (isset($SCANNER_TELEGRAM_BOT_TOKEN) && is_string($SCANNER_TELEGRAM_BOT_TOKEN)) {
    $scannerToken = trim($SCANNER_TELEGRAM_BOT_TOKEN);
}
define('TELEGRAM_BOT_TOKEN', $scannerToken !== '' ? $scannerToken : $TELEGRAM_BOT_TOKEN);
define('TELEGRAM_CHAT_ID', '-1002942067666'); // scanner-specific chat id

// Create mysqli connection for existing scanner PHP APIs
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $conn->connect_error]);
    exit;
}
$conn->set_charset("utf8");

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
?>
