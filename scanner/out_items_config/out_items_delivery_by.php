<?php
require_once __DIR__ . '/../../auth.php';
require_role_or_permission(['admin', 'scanner'], 'out_items_delivery_by.view');

$pdo = get_db_connection();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS scanner_out_items_delivery_by (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        send_telegram TINYINT(1) NOT NULL DEFAULT 1,
        telegram_chat_id VARCHAR(64) NULL,
        telegram_thread_id INT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$hasDescriptionColumn = false;
$colStmt = $pdo->query("SHOW COLUMNS FROM scanner_out_items_delivery_by LIKE 'description'");
if ($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC)) {
    $hasDescriptionColumn = true;
}
if (!$hasDescriptionColumn) {
    $pdo->exec("ALTER TABLE scanner_out_items_delivery_by ADD COLUMN description VARCHAR(255) NULL AFTER name");
}

$hasSendTelegramColumn = false;
$sendColStmt = $pdo->query("SHOW COLUMNS FROM scanner_out_items_delivery_by LIKE 'send_telegram'");
if ($sendColStmt && $sendColStmt->fetch(PDO::FETCH_ASSOC)) {
    $hasSendTelegramColumn = true;
}
if (!$hasSendTelegramColumn) {
    $pdo->exec("ALTER TABLE scanner_out_items_delivery_by ADD COLUMN send_telegram TINYINT(1) NOT NULL DEFAULT 1 AFTER description");
}

$hasTelegramChatIdColumn = false;
$tgChatColStmt = $pdo->query("SHOW COLUMNS FROM scanner_out_items_delivery_by LIKE 'telegram_chat_id'");
if ($tgChatColStmt && $tgChatColStmt->fetch(PDO::FETCH_ASSOC)) {
    $hasTelegramChatIdColumn = true;
}
if (!$hasTelegramChatIdColumn) {
    $pdo->exec("ALTER TABLE scanner_out_items_delivery_by ADD COLUMN telegram_chat_id VARCHAR(64) NULL AFTER send_telegram");
}

$hasTelegramThreadIdColumn = false;
$tgThreadColStmt = $pdo->query("SHOW COLUMNS FROM scanner_out_items_delivery_by LIKE 'telegram_thread_id'");
if ($tgThreadColStmt && $tgThreadColStmt->fetch(PDO::FETCH_ASSOC)) {
    $hasTelegramThreadIdColumn = true;
}
if (!$hasTelegramThreadIdColumn) {
    $pdo->exec("ALTER TABLE scanner_out_items_delivery_by ADD COLUMN telegram_thread_id INT NULL AFTER telegram_chat_id");
}

$errors = [];
$success = '';

function scanner_resolve_delivery_bot_token(): string
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

function scanner_send_delivery_test_message(string $deliveryName, string $chatId, ?int $threadId): array
{
    $botToken = scanner_resolve_delivery_bot_token();
    if ($botToken === '') {
        return ['ok' => false, 'message' => 'Telegram bot token is not configured.'];
    }

    $chatId = trim($chatId);
    if ($chatId === '') {
        return ['ok' => false, 'message' => 'Telegram Chat ID is empty.'];
    }

    if ($threadId !== null && $threadId <= 0) {
        $threadId = null;
    }

    $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';
    $text = "Test message from Out Items Delivery By\n"
        . 'Delivery: ' . $deliveryName . "\n"
        . 'Time: ' . date('Y-m-d H:i:s');

    $send = function (?int $messageThreadId) use ($url, $chatId, $text): array {
        $postFields = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        if ($messageThreadId !== null) {
            $postFields['message_thread_id'] = $messageThreadId;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'message' => $curlError !== '' ? $curlError : 'Failed to call Telegram API.'];
        }

        $decoded = json_decode($response, true);
        if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === true) {
            return ['ok' => true, 'message' => 'Message sent successfully.'];
        }

        $apiMessage = '';
        if (is_array($decoded) && isset($decoded['description']) && is_string($decoded['description'])) {
            $apiMessage = trim($decoded['description']);
        }

        return ['ok' => false, 'message' => $apiMessage !== '' ? $apiMessage : 'Telegram API returned an error.'];
    };

    $result = $send($threadId);
    if ($threadId !== null && !$result['ok'] && stripos((string)$result['message'], 'message thread not found') !== false) {
        $fallback = $send(null);
        if ($fallback['ok']) {
            return ['ok' => true, 'message' => 'Message sent (fallback without Topic ID).'];
        }
        return $fallback;
    }

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        require_role_or_permission(['admin'], 'out_items_delivery_by.create');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sendTelegram = isset($_POST['send_telegram']) ? 1 : 0;
        $telegramChatId = trim($_POST['telegram_chat_id'] ?? '');
        $telegramThreadRaw = trim($_POST['telegram_thread_id'] ?? '');
        $telegramThreadId = $telegramThreadRaw === '' ? null : (int)$telegramThreadRaw;
        if ($description === '') {
            $description = null;
        }
        if ($name === '') {
            $errors[] = 'Delivery By name is required.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM scanner_out_items_delivery_by WHERE name = ? LIMIT 1');
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                $errors[] = 'Delivery By already exists.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO scanner_out_items_delivery_by (name, description, send_telegram, telegram_chat_id, telegram_thread_id, is_active) VALUES (?, ?, ?, ?, ?, 1)');
                $stmt->execute([
                    $name,
                    $description,
                    $sendTelegram,
                    $telegramChatId !== '' ? $telegramChatId : null,
                    $telegramThreadId,
                ]);
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
        }
    } elseif ($action === 'toggle') {
        require_role_or_permission(['admin'], 'out_items_delivery_by.update');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE scanner_out_items_delivery_by SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?');
            $stmt->execute([$id]);
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } elseif ($action === 'update') {
        require_role_or_permission(['admin'], 'out_items_delivery_by.update');
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sendTelegram = isset($_POST['send_telegram']) ? 1 : 0;
        $telegramChatId = trim($_POST['telegram_chat_id'] ?? '');
        $telegramThreadRaw = trim($_POST['telegram_thread_id'] ?? '');
        $telegramThreadId = $telegramThreadRaw === '' ? null : (int)$telegramThreadRaw;
        $isActive = (int)($_POST['is_active'] ?? 1);
        if ($id > 0 && $name !== '') {
            $dup = $pdo->prepare('SELECT id FROM scanner_out_items_delivery_by WHERE name = ? AND id != ? LIMIT 1');
            $dup->execute([$name, $id]);
            if ($dup->fetch()) {
                $errors[] = 'Another delivery with this name already exists.';
            } else {
                $stmt = $pdo->prepare('UPDATE scanner_out_items_delivery_by SET name = ?, description = ?, send_telegram = ?, telegram_chat_id = ?, telegram_thread_id = ?, is_active = ? WHERE id = ?');
                $stmt->execute([
                    $name,
                    $description !== '' ? $description : null,
                    $sendTelegram,
                    $telegramChatId !== '' ? $telegramChatId : null,
                    $telegramThreadId,
                    $isActive,
                    $id,
                ]);
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
        }
    } elseif ($action === 'delete') {
        require_role_or_permission(['admin'], 'out_items_delivery_by.delete');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM scanner_out_items_delivery_by WHERE id = ?');
            $stmt->execute([$id]);
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } elseif ($action === 'toggle_delivery_telegram') {
        require_role_or_permission(['admin'], 'out_items_delivery_by.update');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE scanner_out_items_delivery_by SET send_telegram = CASE WHEN send_telegram = 1 THEN 0 ELSE 1 END WHERE id = ?');
            $stmt->execute([$id]);
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } elseif ($action === 'save_delivery_telegram_target') {
        require_role_or_permission(['admin'], 'out_items_delivery_by.update');
        $id = (int)($_POST['id'] ?? 0);
        $chatId = trim($_POST['telegram_chat_id'] ?? '');
        $threadRaw = trim($_POST['telegram_thread_id'] ?? '');
        $threadId = $threadRaw === '' ? null : (int)$threadRaw;
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE scanner_out_items_delivery_by SET telegram_chat_id = ?, telegram_thread_id = ? WHERE id = ?');
            $stmt->execute([
                $chatId !== '' ? $chatId : null,
                $threadId,
                $id,
            ]);
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } elseif ($action === 'test_bot') {
        require_role_or_permission(['admin'], 'out_items_delivery_by.update');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errors[] = 'Invalid delivery option.';
        } else {
            $stmt = $pdo->prepare('SELECT name, send_telegram, telegram_chat_id, telegram_thread_id FROM scanner_out_items_delivery_by WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $errors[] = 'Delivery option not found.';
            } else {
                if ((int)($row['send_telegram'] ?? 0) !== 1) {
                    $errors[] = 'Telegram is OFF for ' . (string)$row['name'] . '. Please enable it first.';
                } else {
                    $chatId = trim((string)($row['telegram_chat_id'] ?? ''));
                    $threadRaw = trim((string)($row['telegram_thread_id'] ?? ''));
                    $threadId = $threadRaw === '' ? null : (int)$threadRaw;

                    $result = scanner_send_delivery_test_message((string)$row['name'], $chatId, $threadId);
                    if ($result['ok']) {
                        $success = 'Test bot sent for ' . (string)$row['name'] . '. ' . (string)$result['message'];
                    } else {
                        $errors[] = 'Test bot failed for ' . (string)$row['name'] . ': ' . (string)$result['message'];
                    }
                }
            }
        }
    }
}

$stmt = $pdo->query('SELECT id, name, description, send_telegram, telegram_chat_id, telegram_thread_id, is_active, updated_at FROM scanner_out_items_delivery_by ORDER BY name ASC');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../layout/header.php';
?>
<style>
    .delivery-config-shell {
        max-width: 1080px;
        margin: 0 auto;
    }
    .delivery-hero {
        background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
        color: #f9fafb;
        border-radius: 14px;
        border: 1px solid rgba(255, 255, 255, 0.08);
    }
    .delivery-hero .sub {
        color: #cbd5e1;
        font-size: 0.95rem;
    }
    .btn-add-delivery {
        background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
        border: none;
        color: #fff;
        font-weight: 600;
    }
    .btn-add-delivery:hover {
        background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
        color: #fff;
    }
    .status-pill {
        min-width: 80px;
        text-align: center;
        font-weight: 600;
    }
    .inline-toggle-form { display: inline-block; margin: 0; }
    .inline-toggle-form .form-check-input {
        width: 2.8em;
        height: 1.5em;
        cursor: pointer;
        margin-top: 0;
    }
</style>
<div class="d-flex flex-column h-100">
    <div class="delivery-config-shell w-100">
    <div class="delivery-hero p-3 p-md-4 mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1 class="h4 mb-1">Out Items Delivery By Config</h1>
            <div class="sub">Manage delivery options shown in Out Items dropdown.</div>
        </div>
        <button type="button" class="btn btn-add-delivery" data-bs-toggle="modal" data-bs-target="#addDeliveryByModal">
            + Add Delivery By
        </button>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

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
                            <th>Status</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" class="text-center py-4">No delivery options configured yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $index => $r): ?>
                        <tr>
                            <td><?= (int)$index + 1 ?></td>
                            <td><?= htmlspecialchars($r['name']) ?></td>
                            <td><?= htmlspecialchars((string)($r['description'] ?? '')) ?></td>
                            <td>
                                <form method="post" class="inline-toggle-form">
                                    <input type="hidden" name="action" value="toggle_delivery_telegram">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox"
                                            <?= ((int)$r['send_telegram'] === 1) ? 'checked' : '' ?>
                                            onchange="this.form.submit()">
                                    </div>
                                </form>
                            </td>
                            <td>
                                <?php if (!empty($r['telegram_chat_id'])): ?>
                                    <div><?= htmlspecialchars((string)$r['telegram_chat_id']) ?></div>
                                    <?php if (!empty($r['telegram_thread_id'])): ?>
                                        <small class="text-muted">Topic: <?= htmlspecialchars((string)$r['telegram_thread_id']) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="inline-toggle-form">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox"
                                            <?= ((int)$r['is_active'] === 1) ? 'checked' : '' ?>
                                            onchange="this.form.submit()">
                                    </div>
                                </form>
                            </td>
                            <td><?= htmlspecialchars((string)$r['updated_at']) ?></td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <form method="post">
                                        <input type="hidden" name="action" value="test_bot">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-info btn-sm text-white" <?= empty($r['telegram_chat_id']) ? 'disabled title="Set Telegram Chat ID first"' : '' ?>>
                                            <i class="bi bi-send-check-fill me-1"></i>Test Bot
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-warning btn-sm"
                                        data-bs-toggle="modal" data-bs-target="#editDeliveryModal<?= (int)$r['id'] ?>">
                                        <i class="bi bi-pencil-fill me-1"></i>Edit
                                    </button>
                                    <form method="post" onsubmit="return confirm('Delete this delivery option?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="bi bi-trash-fill me-1"></i>Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit Modal -->
                        <div class="modal fade" id="editDeliveryModal<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Delivery By: <?= htmlspecialchars((string)$r['name']) ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <label class="form-label fw-semibold">Delivery Name</label>
                                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars((string)$r['name']) ?>" required>
                                            <label class="form-label fw-semibold mt-3">Description</label>
                                            <textarea name="description" class="form-control" rows="2" placeholder="Optional description"><?= htmlspecialchars((string)($r['description'] ?? '')) ?></textarea>
                                            <div class="form-check form-switch mt-3">
                                                <input class="form-check-input" type="checkbox" id="edit_send_telegram_<?= (int)$r['id'] ?>" name="send_telegram" <?= ((int)$r['send_telegram'] === 1) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="edit_send_telegram_<?= (int)$r['id'] ?>">Send to Telegram</label>
                                            </div>
                                            <label class="form-label fw-semibold mt-3">Telegram Chat ID</label>
                                            <input type="text" name="telegram_chat_id" class="form-control" value="<?= htmlspecialchars((string)($r['telegram_chat_id'] ?? '')) ?>" placeholder="e.g. -1003261380002">
                                            <label class="form-label fw-semibold mt-3">Telegram Topic/Thread ID</label>
                                            <input type="text" name="telegram_thread_id" class="form-control" value="<?= htmlspecialchars((string)($r['telegram_thread_id'] ?? '')) ?>" placeholder="optional">
                                            <label class="form-label fw-semibold mt-3">Status</label>
                                            <select name="is_active" class="form-select">
                                                <option value="1" <?= ((int)$r['is_active'] === 1) ? 'selected' : '' ?>>Active</option>
                                                <option value="0" <?= ((int)$r['is_active'] === 0) ? 'selected' : '' ?>>Inactive</option>
                                            </select>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-warning text-white fw-semibold">Save Changes</button>
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

<div class="modal fade" id="addDeliveryByModal" tabindex="-1" aria-labelledby="addDeliveryByModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title" id="addDeliveryByModalLabel">Add Delivery By</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <label class="form-label">Delivery Name</label>
                    <input type="text" name="name" class="form-control" placeholder="Enter delivery name" required>
                    <label class="form-label mt-3">Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Optional description"></textarea>
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" id="send_telegram" name="send_telegram" checked>
                        <label class="form-check-label" for="send_telegram">Send this delivery option to Telegram</label>
                    </div>
                    <label class="form-label mt-3">Telegram Chat ID</label>
                    <input type="text" name="telegram_chat_id" class="form-control" placeholder="e.g. -1003261380002">
                    <label class="form-label mt-3">Telegram Topic/Thread ID</label>
                    <input type="text" name="telegram_thread_id" class="form-control" placeholder="optional">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../layout/footer.php'; ?>
