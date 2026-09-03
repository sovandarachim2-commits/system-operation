<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_role_or_permission(['admin'], 'telegram_bot_reminder.view', 'telegram_bot_remider.view');
require_once __DIR__ . '/../config.php';

$pdo = get_db_connection();
$errors = [];
$success = '';

// --- Table creation helper (run once, then remove or comment out) ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bot_reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        telegram VARCHAR(255),
        telegram_group VARCHAR(255),
        telegram_thread_id INT NULL,
        auto_overdue_days VARCHAR(50) DEFAULT '1+',
        auto_send_time TIME NULL,
        status TINYINT(1) DEFAULT 1,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {
    echo '<div class="alert alert-danger">Table creation failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Add telegram_thread_id for older tables
try {
    $threadColStmt = $pdo->query("SHOW COLUMNS FROM telegram_bot_reminders LIKE 'telegram_thread_id'");
    if (!$threadColStmt || !$threadColStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE telegram_bot_reminders ADD COLUMN telegram_thread_id INT NULL AFTER telegram_group");
    }
} catch (Throwable $e) {
    $errors[] = 'Schema update failed: ' . $e->getMessage();
}

// Add auto_overdue_days for older tables
try {
    $daysColStmt = $pdo->query("SHOW COLUMNS FROM telegram_bot_reminders LIKE 'auto_overdue_days'");
    if (!$daysColStmt || !$daysColStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE telegram_bot_reminders ADD COLUMN auto_overdue_days VARCHAR(50) DEFAULT '1+' AFTER telegram_thread_id");
    }
} catch (Throwable $e) {
    $errors[] = 'Schema update failed: ' . $e->getMessage();
}

// Add auto_send_time for older tables
try {
    $timeColStmt = $pdo->query("SHOW COLUMNS FROM telegram_bot_reminders LIKE 'auto_send_time'");
    if (!$timeColStmt || !$timeColStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE telegram_bot_reminders ADD COLUMN auto_send_time TIME NULL AFTER auto_overdue_days");
    }
} catch (Throwable $e) {
    $errors[] = 'Schema update failed: ' . $e->getMessage();
}
// --- End table creation helper ---

// Load "Delivery By" options from scanner config
$deliveryByNames = [];
try {
    $deliveryStmt = $pdo->query("SELECT name FROM scanner_out_items_delivery_by ORDER BY name ASC");
    if ($deliveryStmt) {
        $deliveryByNames = $deliveryStmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $e) {
    $deliveryByNames = [];
}

function sendTelegramReminderTest(string $message, ?string $chatId, ?string $threadId = null): array
{
    global $REMINDER_TELEGRAM_BOT_TOKEN, $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID;

    $token = trim((string)($REMINDER_TELEGRAM_BOT_TOKEN ?? ''));
    if ($token === '') {
        $token = trim((string)($TELEGRAM_BOT_TOKEN ?? ''));
    }
    if ($token === '') {
        return ['ok' => false, 'message' => 'Reminder Telegram bot token is missing in config.php'];
    }

    $targetChatId = trim((string)($chatId ?? ''));
    if ($targetChatId === '') {
        $targetChatId = trim((string)($TELEGRAM_CHAT_ID ?? ''));
    }
    if ($targetChatId === '') {
        return ['ok' => false, 'message' => 'Telegram group/chat is not set for this reminder.'];
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = [
        'chat_id' => $targetChatId,
        'text' => $message,
        'parse_mode' => 'HTML',
    ];

    $topicId = trim((string)($threadId ?? ''));
    if ($topicId !== '') {
        $payload['message_thread_id'] = (int)$topicId;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'message' => 'Telegram request failed: ' . $error];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
        $desc = is_array($decoded) ? ($decoded['description'] ?? 'Unknown Telegram error') : 'Invalid Telegram response';
        return ['ok' => false, 'message' => 'Telegram error: ' . $desc];
    }

    return ['ok' => true, 'message' => 'Test message sent successfully.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'toggle_telegram') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE telegram_bot_reminders SET telegram = CASE WHEN telegram = 1 THEN 0 ELSE 1 END WHERE id = ?");
                $stmt->execute([$id]);
            }
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } elseif ($action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE telegram_bot_reminders SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END WHERE id = ?");
                $stmt->execute([$id]);
            }
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } elseif ($action === 'add_reminder') {
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $telegram = isset($_POST['telegram']) ? 1 : 0;
            $telegram_group = trim((string)($_POST['telegram_group'] ?? ''));
            $telegramThreadRaw = trim((string)($_POST['telegram_thread_id'] ?? ''));
            $telegramThreadId = $telegramThreadRaw === '' ? null : (int)$telegramThreadRaw;
            $autoOverdueDays = trim((string)($_POST['auto_overdue_days'] ?? '1+'));
            $autoSendTimeRaw = trim((string)($_POST['auto_send_time'] ?? ''));
            $autoSendTime = $autoSendTimeRaw === '' ? null : $autoSendTimeRaw . ':00';
            $status = 1;

            if ($name === '') {
                $errors[] = 'Name is required.';
            }
            if (empty($deliveryByNames)) {
                $errors[] = 'No Delivery By names found. Please add them first in Out Items Delivery By.';
            } elseif (!in_array($name, $deliveryByNames, true)) {
                $errors[] = 'Please select a valid Delivery By name.';
            }
            if (empty($errors)) {
                $dupStmt = $pdo->prepare("SELECT id FROM telegram_bot_reminders WHERE name = ? LIMIT 1");
                $dupStmt->execute([$name]);
                if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
                    $errors[] = 'This name already exists.';
                }
            }
            if (empty($errors)) {
                $stmt = $pdo->prepare("INSERT INTO telegram_bot_reminders (name, description, telegram, telegram_group, telegram_thread_id, auto_overdue_days, auto_send_time, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $description, $telegram, $telegram_group, $telegramThreadId, $autoOverdueDays, $autoSendTime, $status]);
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
        } elseif ($action === 'edit_reminder') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $telegram = isset($_POST['telegram']) ? 1 : 0;
            $telegram_group = trim((string)($_POST['telegram_group'] ?? ''));
            $telegramThreadRaw = trim((string)($_POST['telegram_thread_id'] ?? ''));
            $telegramThreadId = $telegramThreadRaw === '' ? null : (int)$telegramThreadRaw;
            $autoOverdueDays = trim((string)($_POST['auto_overdue_days'] ?? '1+'));
            $autoSendTimeRaw = trim((string)($_POST['auto_send_time'] ?? ''));
            $autoSendTime = $autoSendTimeRaw === '' ? null : $autoSendTimeRaw . ':00';

            if ($id <= 0) {
                $errors[] = 'Invalid reminder ID.';
            }
            if ($name === '') {
                $errors[] = 'Name is required.';
            }
            if (empty($deliveryByNames)) {
                $errors[] = 'No Delivery By names found. Please add them first in Out Items Delivery By.';
            } elseif (!in_array($name, $deliveryByNames, true)) {
                $errors[] = 'Please select a valid Delivery By name.';
            }
            if (empty($errors)) {
                $dupStmt = $pdo->prepare("SELECT id FROM telegram_bot_reminders WHERE name = ? AND id != ? LIMIT 1");
                $dupStmt->execute([$name, $id]);
                if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
                    $errors[] = 'This name already exists.';
                }
            }
            if (empty($errors)) {
                $stmt = $pdo->prepare("UPDATE telegram_bot_reminders SET name = ?, description = ?, telegram = ?, telegram_group = ?, telegram_thread_id = ?, auto_overdue_days = ?, auto_send_time = ? WHERE id = ?");
                $stmt->execute([$name, $description, $telegram, $telegram_group, $telegramThreadId, $autoOverdueDays, $autoSendTime, $id]);
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
        } elseif ($action === 'delete_reminder') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid reminder ID.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM telegram_bot_reminders WHERE id = ?");
                $stmt->execute([$id]);
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
        } elseif ($action === 'test_bot') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, name, telegram, telegram_group, telegram_thread_id, status FROM telegram_bot_reminders WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $errors[] = 'Reminder not found.';
            } elseif ((int)($row['status'] ?? 0) !== 1) {
                $errors[] = 'Reminder is disabled. Enable status first, then test again.';
            } elseif ((int)($row['telegram'] ?? 0) !== 1) {
                $errors[] = 'Telegram switch is OFF for this reminder.';
            } else {
                $testText = "Test reminder from <b>Shadow</b>\nReminder: <b>" . htmlspecialchars((string)$row['name']) . "</b>\nTime: " . date('Y-m-d H:i:s');
                $result = sendTelegramReminderTest($testText, (string)($row['telegram_group'] ?? ''), (string)($row['telegram_thread_id'] ?? ''));
                if ($result['ok']) {
                    $success = $result['message'];
                } else {
                    $errors[] = $result['message'];
                }
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Action failed: ' . $e->getMessage();
    }
}

// Fetch all telegram bot reminders
$stmt = $pdo->query("SELECT id, name, description, telegram, telegram_group, telegram_thread_id, auto_overdue_days, auto_send_time, status, updated_at FROM telegram_bot_reminders ORDER BY id ASC");
$reminders = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$page_title = 'Telegram Bot Reminder Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .reminder-config-shell {
            max-width: 1080px;
            margin: 0 auto;
        }
        .reminder-hero {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            color: #f9fafb;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .reminder-hero .sub {
            color: #cbd5e1;
            font-size: 0.95rem;
        }
        .btn-add-reminder {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            border: none;
            color: #fff;
            font-weight: 600;
        }
        .btn-add-reminder:hover {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            color: #fff;
        }
        .btn-run-auto {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            border: none;
            color: #fff;
            font-weight: 600;
        }
        .btn-run-auto:hover {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #fff;
        }
        .btn-test-bot {
            background: #29b6f6;
            color: #fff;
            border: none;
        }
        .btn-test-bot:hover {
            background: #0288d1;
            color: #fff;
        }
        .inline-toggle-form { display: inline-block; margin: 0; }
        .inline-toggle-form .form-check-input {
            width: 2.8em;
            height: 1.5em;
            cursor: pointer;
            margin-top: 0;
        }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/../layout/header.php'; ?>
<div class="d-flex flex-column h-100">
    <div class="reminder-config-shell w-100">
        <div class="reminder-hero p-3 p-md-4 mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h1 class="h4 mb-1">Telegram Bot Reminder Config</h1>
                <div class="sub">Manage reminder targets and quickly test Telegram notifications.</div>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-run-auto" id="runAutoSendBtn">
                    <i class="bi bi-play-circle me-1"></i>Run Auto Send Now
                </button>
                <button type="button" class="btn btn-add-reminder" data-bs-toggle="modal" data-bs-target="#addReminderModal">
                    + Add Reminder
                </button>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <div class="card shadow-sm flex-grow-1 d-flex flex-column">
            <div class="card-body d-flex flex-column p-0">
                <div class="table-responsive table-responsive-full">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>No</th>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Telegram</th>
                                <th>Telegram Group</th>
                                <th>Auto Days</th>
                                <th>Auto Time</th>
                                <th>Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reminders)): ?>
                                <tr><td colspan="9" class="text-center py-4">No reminders found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($reminders as $index => $row): ?>
                                <tr>
                                    <td><?= (int)$index + 1 ?></td>
                                    <td><?= htmlspecialchars((string)($row['name'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['description'] ?? '')) ?></td>
                                    <td>
                                        <form method="post" class="inline-toggle-form">
                                            <input type="hidden" name="action" value="toggle_telegram">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox"
                                                    <?= ((int)($row['telegram'] ?? 0) === 1) ? 'checked' : '' ?>
                                                    onchange="this.form.submit()">
                                            </div>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['telegram_group'])): ?>
                                            <div><?= htmlspecialchars((string)$row['telegram_group']) ?></div>
                                            <?php if (!empty($row['telegram_thread_id'])): ?>
                                                <small class="text-muted">Topic: <?= htmlspecialchars((string)$row['telegram_thread_id']) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)($row['auto_overdue_days'] ?? '1+')) ?></td>
                                    <td><?= !empty($row['auto_send_time']) ? htmlspecialchars(substr((string)$row['auto_send_time'], 0, 5)) : '<span class="text-muted">Any</span>' ?></td>
                                    <td><?= !empty($row['updated_at']) ? date('Y-m-d H:i:s', strtotime((string)$row['updated_at'])) : '-' ?></td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-2">
                                            <form method="post">
                                                <input type="hidden" name="action" value="test_bot">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-info btn-sm text-white" <?= empty($row['telegram_group']) ? 'disabled title="Set Telegram Group first"' : '' ?>>
                                                    <i class="bi bi-send-check-fill me-1"></i>Test Bot
                                                </button>
                                            </form>
                                            <button
                                                type="button"
                                                class="btn btn-warning btn-sm"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editReminderModal<?= (int)$row['id'] ?>"
                                            >
                                                <i class="bi bi-pencil-fill me-1"></i>Edit
                                            </button>
                                            <form method="post" onsubmit="return confirm('Delete this reminder?');">
                                                <input type="hidden" name="action" value="delete_reminder">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">
                                                    <i class="bi bi-trash-fill me-1"></i>Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <div class="modal fade" id="editReminderModal<?= (int)$row['id'] ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                            <form method="post">
                                                <input type="hidden" name="action" value="edit_reminder">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Edit Reminder: <?= htmlspecialchars((string)($row['name'] ?? '')) ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label class="form-label">Name</label>
                                                        <select name="name" class="form-select" required>
                                                            <option value="">Select Delivery By</option>
                                                            <?php foreach ($deliveryByNames as $deliveryName): ?>
                                                                <option value="<?= htmlspecialchars((string)$deliveryName) ?>" <?= ((string)$deliveryName === (string)($row['name'] ?? '')) ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars((string)$deliveryName) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                            <?php if (!empty($row['name']) && !in_array((string)$row['name'], $deliveryByNames, true)): ?>
                                                                <option value="<?= htmlspecialchars((string)$row['name']) ?>" selected>
                                                                    <?= htmlspecialchars((string)$row['name']) ?> (existing)
                                                                </option>
                                                            <?php endif; ?>
                                                        </select>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Description</label>
                                                        <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars((string)($row['description'] ?? '')) ?></textarea>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Telegram Group / Chat ID</label>
                                                        <input type="text" name="telegram_group" class="form-control" value="<?= htmlspecialchars((string)($row['telegram_group'] ?? '')) ?>" placeholder="-100xxxxxxxxxx">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Telegram Topic/Thread ID</label>
                                                        <input type="text" name="telegram_thread_id" class="form-control" value="<?= htmlspecialchars((string)($row['telegram_thread_id'] ?? '')) ?>" placeholder="optional">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Auto Overdue Days</label>
                                                        <input type="text" name="auto_overdue_days" class="form-control" value="<?= htmlspecialchars((string)($row['auto_overdue_days'] ?? '1+')) ?>" placeholder="1+">
                                                        <small class="text-muted">Examples: 1+ (all unpaid from 1 day up), 5+, or exact days 2,5</small>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Auto Send Time</label>
                                                        <input type="time" name="auto_send_time" class="form-control" value="<?= !empty($row['auto_send_time']) ? htmlspecialchars(substr((string)$row['auto_send_time'], 0, 5)) : '' ?>">
                                                        <small class="text-muted">Leave empty to send whenever cron runs.</small>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="telegram" id="editTelegram<?= (int)$row['id'] ?>" <?= ((int)($row['telegram'] ?? 0) === 1) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="editTelegram<?= (int)$row['id'] ?>">Enable Telegram</label>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-warning text-white">Save Changes</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addReminderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="add_reminder">
                <div class="modal-header">
                    <h5 class="modal-title">Add Reminder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <select name="name" class="form-select" required>
                            <option value="">Select Delivery By</option>
                            <?php foreach ($deliveryByNames as $deliveryName): ?>
                                <option value="<?= htmlspecialchars((string)$deliveryName) ?>">
                                    <?= htmlspecialchars((string)$deliveryName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Optional description"></textarea>
                    </div>
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" id="addTelegram" name="telegram" checked>
                        <label class="form-check-label" for="addTelegram">Send this reminder to Telegram</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label mt-3">Telegram Chat ID</label>
                        <input type="text" name="telegram_group" class="form-control" placeholder="e.g. -1003261380002">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Telegram Topic/Thread ID</label>
                        <input type="text" name="telegram_thread_id" class="form-control" placeholder="optional">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Auto Overdue Days</label>
                        <input type="text" name="auto_overdue_days" class="form-control" value="1+" placeholder="1+">
                        <small class="text-muted">Examples: 1+ (all unpaid from 1 day up), 5+, or exact days 2,5</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Auto Send Time</label>
                        <input type="time" name="auto_send_time" class="form-control" value="">
                        <small class="text-muted">Leave empty to send whenever cron runs.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
<script>
document.getElementById('runAutoSendBtn')?.addEventListener('click', async function () {
    const btn = this;
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Running...';

    try {
        const res = await fetch('cron_send_unpaid_payment_delivery.php', { method: 'GET' });
        const data = await res.json();

        if (!data.success) {
            alert('Auto send failed: ' + (data.error || 'Unknown error'));
            return;
        }

        const sent = (data.summary?.sent || []).length;
        const skipped = (data.summary?.skipped || []).length;
        const failed = (data.summary?.failed || []).length;
        alert(`Auto send completed.\nSent: ${sent}\nSkipped: ${skipped}\nFailed: ${failed}`);
    } catch (err) {
        alert('Auto send failed: ' + err);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
});
</script>
</body>
</html>
