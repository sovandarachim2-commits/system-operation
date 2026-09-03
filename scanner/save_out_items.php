<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.create');
require_once __DIR__ . '/../user_activity_lib.php';
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['result' => 'error', 'message' => 'Not logged in']);
    exit;
}
$currentUserId = (int)$user['id'];

// --- Gather posted data ---
$inv = $conn->real_escape_string($_POST['inv'] ?? '');
$delivery_by = $conn->real_escape_string($_POST['delivery_by'] ?? '');
$user_phone = $conn->real_escape_string($_POST['user_phone'] ?? 'default_user');
$date_time = $conn->real_escape_string($_POST['date_time'] ?? date('Y-m-d H:i:s'));

function scanner_finish_json_response(array $payload): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    ignore_user_abort(true);
    echo json_encode($payload);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    flush();
}

function scanner_resolve_out_items_bot_token(): string
{
    if (defined('TELEGRAM_BOT_TOKEN')) {
        $token = trim((string)TELEGRAM_BOT_TOKEN);
        if ($token !== '') {
            return $token;
        }
    }

    $scannerToken = isset($GLOBALS['SCANNER_TELEGRAM_BOT_TOKEN']) ? trim((string)$GLOBALS['SCANNER_TELEGRAM_BOT_TOKEN']) : '';
    if ($scannerToken !== '') {
        return $scannerToken;
    }

    $globalToken = isset($GLOBALS['TELEGRAM_BOT_TOKEN']) ? trim((string)$GLOBALS['TELEGRAM_BOT_TOKEN']) : '';
    return $globalToken;
}

function scanner_out_items_send_telegram_photo(mysqli $conn, string $inv, string $deliveryBy, string $invPhotoRel, string $fullPhotoRel, string $dateTime): array
{
    $botToken = scanner_resolve_out_items_bot_token();
    if ($botToken === '') {
        error_log('[scanner][out_items][telegram] skipped: bot token missing');
        return ['sent' => false, 'reason' => 'bot token missing'];
    }

    $safeDelivery = $conn->real_escape_string($deliveryBy);
    $delRes = $conn->query("SELECT send_telegram, telegram_chat_id, telegram_thread_id FROM scanner_out_items_delivery_by WHERE name = '{$safeDelivery}' LIMIT 1");
    if (!$delRes || $delRes->num_rows === 0) {
        error_log('[scanner][out_items][telegram] skipped: delivery config not found for "' . $deliveryBy . '"');
        return ['sent' => false, 'reason' => 'delivery config not found'];
    }
    $delRow = $delRes->fetch_assoc();
    if ((int)($delRow['send_telegram'] ?? 0) !== 1) {
        error_log('[scanner][out_items][telegram] skipped: send_telegram OFF for "' . $deliveryBy . '"');
        return ['sent' => false, 'reason' => 'send_telegram off'];
    }

    $chatId = trim((string)($delRow['telegram_chat_id'] ?? ''));
    if ($chatId === '') {
        error_log('[scanner][out_items][telegram] skipped: chat_id empty for "' . $deliveryBy . '"');
        return ['sent' => false, 'reason' => 'chat id empty'];
    }

    $threadRaw = trim((string)($delRow['telegram_thread_id'] ?? ''));
    $threadId = $threadRaw === '' ? null : (int)$threadRaw;
    if ($threadId !== null && $threadId <= 0) {
        $threadId = null;
    }

    $phone = '';
    $orderStatus = '';
    $orderAmount = null;
    $safeInv = $conn->real_escape_string($inv);

    $peRes = $conn->query("SELECT phone, status, amount FROM product_entries WHERE inv = '{$safeInv}' ORDER BY id DESC LIMIT 1");
    if ($peRes && $peRes->num_rows > 0) {
        $peRow = $peRes->fetch_assoc();
        $phone = (string)($peRow['phone'] ?? '');
        $orderStatus = strtolower(trim((string)($peRow['status'] ?? '')));
        $orderAmount = isset($peRow['amount']) ? (float)$peRow['amount'] : null;
    }

    if ($phone === '' || $orderStatus === '' || $orderAmount === null) {
        $orderRes = $conn->query("SELECT phone, status, total_amount FROM orders WHERE order_code = '{$safeInv}' LIMIT 1");
        if ($orderRes && $orderRes->num_rows > 0) {
            $orderRow = $orderRes->fetch_assoc();
            if ($phone === '') {
                $phone = (string)($orderRow['phone'] ?? '');
            }
            if ($orderStatus === '') {
                $orderStatus = strtolower(trim((string)($orderRow['status'] ?? '')));
            }
            if ($orderAmount === null && isset($orderRow['total_amount'])) {
                $orderAmount = (float)$orderRow['total_amount'];
            }
        }
    }

    $photoRel = $invPhotoRel !== '' ? $invPhotoRel : $fullPhotoRel;
    if ($photoRel === '') {
        error_log('[scanner][out_items][telegram] skipped: photo missing for "' . $deliveryBy . '"');
        return ['sent' => false, 'reason' => 'photo missing'];
    }

    $resolvedPhoto = scanner_storage_resolve_public_url($photoRel);
    if (!scanner_storage_is_remote_path($resolvedPhoto)) {
        error_log('[scanner][out_items][telegram] skipped: photo url unavailable for "' . $deliveryBy . '"');
        return ['sent' => false, 'reason' => 'photo url unavailable'];
    }

    $statusLabel = 'Unknown';
    if ($orderStatus === 'paid') {
        $statusLabel = 'Paid';
    } elseif ($orderStatus === 'unpaid') {
        $statusLabel = 'Unpaid';
    }
    $statusEmoji = $orderStatus === 'paid' ? '✅' : ($orderStatus === 'unpaid' ? '🕒' : '❔');
    $amountText = $orderAmount !== null ? '$' . number_format($orderAmount, 2) : '-';

    $message = "📦 Out Package\n"
        . "🧾 Order Code: {$inv}\n"
        . '📞 Phone: ' . ($phone !== '' ? $phone : '-') . "\n"
        . "💰 Amount: {$amountText}\n"
        . "📌 Status: {$statusEmoji} {$statusLabel}\n"
        . "🚚 Delivery: {$deliveryBy}\n"
        . "🕐 Date: {$dateTime}\n"
        . '🖼️ Image: <a href="' . htmlspecialchars($resolvedPhoto, ENT_QUOTES, 'UTF-8') . '">Open (CfD)</a>';

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $postFields = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
    ];
    if ($threadId !== null && $threadId > 0) {
        $postFields['message_thread_id'] = $threadId;
    }

    $sendMessage = function (?int $messageThreadId) use ($url, $postFields): array {
        $fields = $postFields;
        if ($messageThreadId !== null && $messageThreadId > 0) {
            $fields['message_thread_id'] = $messageThreadId;
        } else {
            unset($fields['message_thread_id']);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'message' => $curlError !== '' ? $curlError : 'Failed to call Telegram API.'];
        }

        $decoded = json_decode($response, true);
        if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === true) {
            return ['ok' => true, 'message' => 'sent'];
        }

        $apiMessage = '';
        if (is_array($decoded) && isset($decoded['description']) && is_string($decoded['description'])) {
            $apiMessage = trim($decoded['description']);
        }
        return ['ok' => false, 'message' => $apiMessage !== '' ? $apiMessage : 'Telegram API error'];
    };

    $result = $sendMessage($threadId);
    if ($threadId !== null && !$result['ok'] && stripos((string)$result['message'], 'message thread not found') !== false) {
        $result = $sendMessage(null);
        if ($result['ok']) {
            error_log('[scanner][out_items][telegram] sent with fallback without thread for "' . $deliveryBy . '"');
            return ['sent' => true, 'reason' => 'sent with thread fallback'];
        }
    }

    if (!$result['ok']) {
        error_log('[scanner][out_items][telegram] failed for "' . $deliveryBy . '": ' . (string)$result['message']);
        return ['sent' => false, 'reason' => (string)$result['message']];
    }

    return ['sent' => true, 'reason' => 'sent'];
}

// --- Step 1: Check duplicate in out_items ---
$check = $conn->query("SELECT id FROM out_items WHERE inv='$inv' LIMIT 1");
if ($check && $check->num_rows > 0) {
    echo json_encode(['result' => 'duplicate', 'message' => 'Error! Barcode already scanned to Accountant']);
    $conn->close();
    exit;
}

// --- Step 2: Check if INV exists in product_entries (prepare_items) ---
$prepare_check = $conn->query("SELECT id FROM product_entries WHERE inv='$inv' LIMIT 1");
if ($prepare_check->num_rows === 0) {
    echo json_encode(['result' => 'notfound', 'message' => 'Error! Barcode must be scanned in prepare items first.']);
    $conn->close();
    exit;
}

// --- Step 3: Handle inv_photo upload ---
$inv_photo = '';
$full_photo = '';
try {
    $inv_photo = isset($_FILES['inv_photo'])
        ? $conn->real_escape_string(scanner_storage_store_uploaded_file($_FILES['inv_photo'], 'inv_', $date_time))
        : '';

    // --- Step 4: Handle full_photo upload ---
    $full_photo = isset($_FILES['full_photo'])
        ? $conn->real_escape_string(scanner_storage_store_uploaded_file($_FILES['full_photo'], 'full_', $date_time))
        : '';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['result' => 'error', 'message' => $e->getMessage()]);
    $conn->close();
    exit;
}

// --- Step 5: Insert into out_items ---
$query = "INSERT INTO out_items (inv, delivery_by, inv_photo, full_photo, user_phone, date_time, user_id)
          VALUES ('$inv', '$delivery_by', '$inv_photo', '$full_photo', '$user_phone', '$date_time', $currentUserId)";

if ($conn->query($query)) {
    $det = user_activity_details_compact([
        'inv' => $_POST['inv'] ?? '',
        'delivery_by' => $_POST['delivery_by'] ?? '',
        'user_phone' => $_POST['user_phone'] ?? '',
        'date_time' => $_POST['date_time'] ?? '',
        'photos' => ($inv_photo !== '' || $full_photo !== '') ? 'yes' : 'no',
    ]);
    user_activity_log_scanner_mutation($user, 'create', __FILE__, $det !== '' ? $det : 'inv ' . (string)$inv);

    scanner_finish_json_response([
        'result' => 'success',
        'telegram_sent' => false,
        'telegram_reason' => 'processing',
    ]);

    $telegram = ['sent' => false, 'reason' => 'not attempted'];
    try {
        $telegram = scanner_out_items_send_telegram_photo($conn, $inv, $delivery_by, $inv_photo, $full_photo, $date_time);
    } catch (Throwable $e) {
        error_log('[scanner][out_items][telegram] exception: ' . $e->getMessage());
        $telegram = ['sent' => false, 'reason' => 'exception'];
    }
} else {
    if (strpos($conn->error, 'Duplicate entry') !== false) {
        echo json_encode(['result' => 'duplicate', 'message' => 'Error! Barcode already scanned to Accountant']);
    } else {
        http_response_code(500);
        echo json_encode(['result' => 'error', 'message' => $conn->error]);
    }
}

$conn->close();
?>
