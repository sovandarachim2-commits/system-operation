<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.create');
require_once __DIR__ . '/../user_activity_lib.php';
require_once 'config.php';

function scanner_normalize_set_qr_code(string $raw): string
{
    $code = trim(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'));
    $code = preg_replace('/[[:cntrl:]]+/', '', $code) ?? $code;

    if (preg_match('/(?:^|\b)(?:code|qr|data)\s*[:=]\s*([A-Za-z0-9_-]+)/i', $code, $matches)) {
        return trim($matches[1]);
    }

    $parts = parse_url($code);
    if (is_array($parts) && !empty($parts['query'])) {
        parse_str((string)$parts['query'], $query);
        foreach (['data', 'qr', 'code', 'label_code'] as $key) {
            if (!empty($query[$key]) && is_scalar($query[$key])) {
                return trim((string)$query[$key]);
            }
        }
    }

    return $code;
}

$invRaw    = scanner_normalize_set_qr_code((string)($_POST["inv"] ?? ""));
$setRaw    = trim((string)($_POST["set"] ?? ""));
$user      = $conn->real_escape_string($_POST["user"] ?? "default_user");
$date_time = $conn->real_escape_string($_POST["date_time"] ?? date("Y-m-d H:i:s"));

if ($invRaw === '' || $setRaw === '') {
    echo json_encode(["result" => "error", "message" => "Scan the set QR (label code) and select a Set."]);
    $conn->close();
    exit;
}

// --- QR must exist in admin label print history; selected Set must match label ---
$histStmt = $conn->prepare("SELECT set_name, label_code FROM product_set_qr_label_print_history WHERE label_code = ? OR UPPER(label_code) = UPPER(?) ORDER BY printed_at DESC, id DESC LIMIT 1");
if (!$histStmt) {
    echo json_encode(["result" => "error", "message" => "Database error (label history)."]);
    $conn->close();
    exit;
}
$histStmt->bind_param("ss", $invRaw, $invRaw);
$histStmt->execute();
$hrow = $histStmt->get_result()->fetch_assoc();
$histStmt->close();

if (!$hrow) {
    echo json_encode([
        "result" => "error",
        "message" => "This QR code is not in label print history. Print the set label in Admin (Set QR Labels) first.",
    ]);
    $conn->close();
    exit;
}

$setNameFromLabel = (string)($hrow['set_name'] ?? '');
if ($setNameFromLabel === '') {
    echo json_encode(["result" => "error", "message" => "Label history row has no set name for this QR."]);
    $conn->close();
    exit;
}
if ($setNameFromLabel !== $setRaw) {
    echo json_encode([
        "result" => "error",
        "message" => 'This QR is for set "' . $setNameFromLabel . '" but you selected "' . $setRaw . '". Pick the matching Set.',
    ]);
    $conn->close();
    exit;
}

$inv       = $conn->real_escape_string((string)($hrow['label_code'] ?? $invRaw));
$set       = $conn->real_escape_string($setRaw);

// --- Duplicate check ---
$check = $conn->query("SELECT id FROM prepare_set WHERE inv='$inv' LIMIT 1");
if ($check && $check->num_rows > 0) {
    echo json_encode(["result" => "duplicate", "message" => "Barcode already exists."]);
    $conn->close();
    exit;
}

// Handle photo upload
$photo = '';
try {
    $photo = isset($_FILES['photo'])
        ? $conn->real_escape_string(scanner_storage_store_uploaded_file($_FILES['photo'], 'photo_', $date_time))
        : '';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["result" => "error", "message" => $e->getMessage()]);
    $conn->close();
    exit;
}

$query = "INSERT INTO prepare_set (inv, `set`, photo, user, date_time)
          VALUES ('$inv', '$set', '$photo', '$user', '$date_time')";

if ($conn->query($query)) {
    $det = user_activity_details_compact([
        'inv' => $_POST['inv'] ?? '',
        'set' => $_POST['set'] ?? '',
        'user' => $_POST['user'] ?? '',
        'date_time' => $_POST['date_time'] ?? '',
        'photo' => $photo !== '' ? 'yes' : 'no',
    ]);
    user_activity_log_scanner_mutation(function_exists('current_user') ? current_user() : null, 'create', __FILE__, $det !== '' ? $det : 'inv ' . (string)$inv);
    echo json_encode(["result" => "success"]);
} else {
    // If duplicate blocked by MySQL UNIQUE, respond nicely
    if (strpos($conn->error, "Duplicate entry") !== false) {
        echo json_encode(["result" => "duplicate", "message" => "Barcode already exists."]);
    } else {
        http_response_code(500);
        echo json_encode(["result" => "error", "message" => $conn->error]);
    }
}

$conn->close();
?>
