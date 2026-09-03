<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_common.php';

$pdo = get_db_connection();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

const NOTIFICATION_MODULES = [
    'stock_closing' => [
        'label' => 'Stock Closing',
        'description' => 'EOD/EOM stock closing finalized alerts.',
        'prefix' => 'inventory_closing_telegram',
        'event_key' => 'notify_finalize',
        'event_label' => 'Notify when finalized',
        'test_message' => "Stock Closing test notification",
    ],
    'stock_movement' => [
        'label' => 'Stock Movement',
        'description' => 'Stock in, stock out, and adjustment alerts.',
        'prefix' => 'notification_stock_movement_telegram',
        'event_key' => 'notify_create',
        'event_label' => 'Notify when movement is created',
        'test_message' => "Stock Movement test notification",
    ],
    'stock_transfer' => [
        'label' => 'Stock Transfer',
        'description' => 'Transfer created and delivery workflow alerts.',
        'prefix' => 'notification_stock_transfer_telegram',
        'event_key' => 'notify_create',
        'event_label' => 'Notify when transfer is created',
        'test_message' => "Stock Transfer test notification",
    ],
    'marketing' => [
        'label' => 'Marketing',
        'description' => 'Marketing request, approve, and reconcile alerts.',
        'prefix' => 'notification_marketing_telegram',
        'event_key' => 'notify_create',
        'event_label' => 'Notify when request is created',
        'test_message' => "Marketing test notification",
    ],
];

function notification_settings_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            `key` VARCHAR(100) NOT NULL PRIMARY KEY,
            `value` TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function notification_settings_get(PDO $pdo, string $key, string $default = ''): string
{
    notification_settings_ensure_table($pdo);
    $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function notification_settings_set(PDO $pdo, string $key, string $value): void
{
    notification_settings_ensure_table($pdo);
    $stmt = $pdo->prepare('
        INSERT INTO app_settings(`key`, `value`) VALUES(?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ');
    $stmt->execute([$key, $value]);
}

function notification_settings_module_config(string $moduleKey): array
{
    $module = NOTIFICATION_MODULES[$moduleKey] ?? null;
    if (!$module) {
        throw new InvalidArgumentException('Invalid notification module.');
    }
    return $module;
}

function notification_settings_read_module(PDO $pdo, string $moduleKey): array
{
    $module = notification_settings_module_config($moduleKey);
    $prefix = $module['prefix'];
    $eventKey = $module['event_key'];
    return [
        'key' => $moduleKey,
        'label' => $module['label'],
        'description' => $module['description'],
        'event_key' => $eventKey,
        'event_label' => $module['event_label'],
        'enabled' => notification_settings_get($pdo, $prefix . '_enabled', '0') === '1',
        'bot_token' => notification_settings_get($pdo, $prefix . '_bot_token', ''),
        'chat_id' => notification_settings_get($pdo, $prefix . '_chat_id', ''),
        'thread_id' => notification_settings_get($pdo, $prefix . '_thread_id', ''),
        $eventKey => notification_settings_get($pdo, $prefix . '_' . $eventKey, '1') !== '0',
    ];
}

function notification_settings_save_module(PDO $pdo, string $moduleKey, array $payload): array
{
    $module = notification_settings_module_config($moduleKey);
    $prefix = $module['prefix'];
    $eventKey = $module['event_key'];
    notification_settings_set($pdo, $prefix . '_enabled', !empty($payload['enabled']) ? '1' : '0');
    notification_settings_set($pdo, $prefix . '_bot_token', inventory_api_str($payload['bot_token'] ?? ''));
    notification_settings_set($pdo, $prefix . '_chat_id', inventory_api_str($payload['chat_id'] ?? ''));
    notification_settings_set($pdo, $prefix . '_thread_id', inventory_api_str($payload['thread_id'] ?? ''));
    notification_settings_set($pdo, $prefix . '_' . $eventKey, array_key_exists($eventKey, $payload) && empty($payload[$eventKey]) ? '0' : '1');
    return notification_settings_read_module($pdo, $moduleKey);
}

function notification_settings_all(PDO $pdo): array
{
    $rows = [];
    foreach (array_keys(NOTIFICATION_MODULES) as $moduleKey) {
        $rows[] = notification_settings_read_module($pdo, $moduleKey);
    }
    return $rows;
}

function notification_settings_bot_token(string $moduleKey, array $settings): string
{
    global $TELEGRAM_BOT_TOKEN;
    global $MARKETING_TELEGRAM_BOT_TOKEN;

    $moduleToken = trim((string)($settings['bot_token'] ?? ''));
    if ($moduleToken !== '') {
        return $moduleToken;
    }

    if ($moduleKey === 'marketing' && !empty($MARKETING_TELEGRAM_BOT_TOKEN)) {
        return trim((string)$MARKETING_TELEGRAM_BOT_TOKEN);
    }

    return trim((string)($TELEGRAM_BOT_TOKEN ?? ''));
}

try {
    if ($method === 'POST') {
        require_role_or_permission(['admin'], 'sr_notification_settings.update');
        $raw = (string)file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        $action = strtolower(inventory_api_str($payload['action'] ?? ''));
        $moduleKey = inventory_api_str($payload['module'] ?? '');

        if ($action === 'save') {
            $settings = notification_settings_save_module($pdo, $moduleKey, $payload);
            api_json([
                'success' => true,
                'settings' => $settings,
                'modules' => notification_settings_all($pdo),
                'message' => $settings['label'] . ' notification settings saved.',
            ]);
        }

        if ($action === 'test') {
            $module = notification_settings_module_config($moduleKey);
            $settings = notification_settings_read_module($pdo, $moduleKey);
            $botToken = notification_settings_bot_token($moduleKey, $settings);
            $chatId = trim((string)($settings['chat_id'] ?? ''));
            if ($botToken === '') {
                throw new InvalidArgumentException('Telegram bot token is not configured.');
            }
            if ($chatId === '') {
                throw new InvalidArgumentException('Telegram Chat ID is required.');
            }
            $threadRaw = trim((string)($settings['thread_id'] ?? ''));
            $threadId = $threadRaw !== '' ? (int)$threadRaw : null;
            $text = (string)$module['test_message'] . "\nSent from System Report Notification Settings.";
            $result = telegram_send_message_request($botToken, $chatId, $text, $threadId);
            if (empty($result['ok'])) {
                $decoded = is_array($result['decoded'] ?? null) ? $result['decoded'] : [];
                throw new RuntimeException('Telegram test failed: ' . (string)($decoded['description'] ?? 'Unknown error'));
            }
            api_json([
                'success' => true,
                'notification' => ['ok' => true],
                'message' => $settings['label'] . ' test notification sent.',
            ]);
        }

        api_json(['success' => false, 'message' => 'Unsupported action.'], 400);
    }

    require_role_or_permission(['admin'], 'sr_notification_settings.view', 'sr_notification_settings.update');
    api_json([
        'success' => true,
        'modules' => notification_settings_all($pdo),
    ]);
} catch (InvalidArgumentException $e) {
    api_json(['success' => false, 'message' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
    api_json(['success' => false, 'message' => $e->getMessage()], 409);
} catch (Throwable $e) {
    api_json(['success' => false, 'message' => 'Unable to manage notification settings: ' . $e->getMessage()], 500);
}
