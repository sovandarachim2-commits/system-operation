<?php
// --- STEP 1: SETUP YOUR BOT TOKEN AND CHAT ID ---
$token = "8321782817:AAHCd0WzYaOGVmXd8qWAuuBLtemAJa73eHk";  // <-- Replace with your Bot Token
$chat_id = "-5055882974";           // <-- Replace with your Telegram chat ID

// --- STEP 2: RECEIVE POSTED DATA FROM JAVASCRIPT ---
$content = file_get_contents('php://input');
$data = json_decode($content, true);

if (!isset($data['message'])) {
    echo json_encode(['ok' => false, 'description' => 'No message provided']);
    exit;
}

// --- STEP 3: SANITIZE MESSAGE (OPTIONAL) ---
$message = strip_tags($data['message']); // Remove any HTML tags

// --- STEP 4: SEND MESSAGE TO TELEGRAM ---
$url = "https://api.telegram.org/bot$token/sendMessage";
$params = [
    'chat_id' => $chat_id,
    'text' => $message,
    'parse_mode' => 'HTML' // You can use 'Markdown' or plain text if you prefer
];

// Use stream_context_create for POST request
$options = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type:application/x-www-form-urlencoded",
        'content' => http_build_query($params)
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

header('Content-Type: application/json');
echo $response; // This will return Telegram's API result (useful for frontend confirmation)
?>
