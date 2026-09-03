<?php
/**
 * Shared functions for Marketing Take feature
 */

/**
 * Send marketing suggest notification to Telegram when created.
 * Approve/Reconcile done on website. No webhook needed.
 */
function send_marketing_suggest_to_telegram(PDO $pdo, int $takeId, string $takeCode, string $eventName, string $eventDate, ?string $location, ?string $notes, array $items, string $createdByName): void {
    global $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID, $TELEGRAM_TARGETS;
    global $MARKETING_TELEGRAM_BOT_TOKEN, $MARKETING_TELEGRAM_CHAT_ID, $MARKETING_TELEGRAM_TARGETS;

    $botToken = !empty($MARKETING_TELEGRAM_BOT_TOKEN) ? $MARKETING_TELEGRAM_BOT_TOKEN : $TELEGRAM_BOT_TOKEN;
    if (empty($botToken)) return;

    $targets = [];
    if (!empty($MARKETING_TELEGRAM_TARGETS) && is_array($MARKETING_TELEGRAM_TARGETS)) {
        $targets = $MARKETING_TELEGRAM_TARGETS;
    } elseif (!empty($MARKETING_TELEGRAM_CHAT_ID)) {
        $targets = [['chat_id' => $MARKETING_TELEGRAM_CHAT_ID, 'thread_id' => null]];
    } elseif (!empty($TELEGRAM_TARGETS) && is_array($TELEGRAM_TARGETS)) {
        $targets = $TELEGRAM_TARGETS;
    } elseif (!empty($TELEGRAM_CHAT_ID)) {
        $targets = [['chat_id' => $TELEGRAM_CHAT_ID, 'thread_id' => null]];
    }
    if (empty($targets)) return;

    $esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    $lines = [];
    $lines[] = "📋 <b>New Market Suggest</b>";
    $lines[] = "Code: " . $esc($takeCode);
    $lines[] = "Event: " . $esc($eventName);
    $lines[] = "Date: " . date('M j, Y', strtotime($eventDate));
    if ($location) $lines[] = "Location: " . $esc($location);
    $lines[] = "Created by: " . $esc($createdByName);
    $lines[] = "";
    $lines[] = "Products:";
    foreach ($items as $it) {
        $lines[] = "• " . $esc($it['product_name'] ?? '?') . " x " . number_format((float)($it['quantity_taken'] ?? 0), 2);
    }
    if ($notes) {
        $lines[] = "";
        $lines[] = "Note: " . $esc($notes);
    }

    $text = implode("\n", $lines);

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    foreach ($targets as $t) {
        if (empty($t['chat_id'])) continue;
        $chatId = trim($t['chat_id']);
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        $threadId = null;
        if (isset($t['thread_id']) && $t['thread_id'] !== null && $t['thread_id'] !== '') {
            $data['message_thread_id'] = (int)$t['thread_id'];
            $threadId = (int)$t['thread_id'];
        }
        $opts = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data),
                'timeout' => 2,
            ],
        ];
        $resp = @file_get_contents($url, false, stream_context_create($opts));
        if ($resp) {
            $json = @json_decode($resp, true);
            if (!empty($json['ok']) && !empty($json['result']['message_id'])) {
                $msgId = (int)$json['result']['message_id'];
                $pdo->prepare("UPDATE marketing_takes SET telegram_message_id = ?, telegram_chat_id = ?, telegram_thread_id = ? WHERE id = ?")
                    ->execute([$msgId, $chatId, $threadId ?: null, $takeId]);
                break; // Save first successful; reply will go to this chat
            }
        }
    }
}

/**
 * Send reply to Telegram when approve/reject on website.
 */
function send_marketing_approve_reply_to_telegram(PDO $pdo, int $takeId, bool $approved, string $approvedByName, ?string $noteOrReason = null): void {
    global $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID, $TELEGRAM_TARGETS;
    global $MARKETING_TELEGRAM_BOT_TOKEN, $MARKETING_TELEGRAM_CHAT_ID, $MARKETING_TELEGRAM_TARGETS;

    $botToken = !empty($MARKETING_TELEGRAM_BOT_TOKEN) ? $MARKETING_TELEGRAM_BOT_TOKEN : $TELEGRAM_BOT_TOKEN;
    if (empty($botToken)) return;

    $targets = [];
    if (!empty($MARKETING_TELEGRAM_TARGETS) && is_array($MARKETING_TELEGRAM_TARGETS)) {
        $targets = $MARKETING_TELEGRAM_TARGETS;
    } elseif (!empty($MARKETING_TELEGRAM_CHAT_ID)) {
        $targets = [['chat_id' => trim($MARKETING_TELEGRAM_CHAT_ID), 'thread_id' => null]];
    } elseif (!empty($TELEGRAM_TARGETS) && is_array($TELEGRAM_TARGETS)) {
        $targets = $TELEGRAM_TARGETS;
    } elseif (!empty($TELEGRAM_CHAT_ID)) {
        $targets = [['chat_id' => trim($TELEGRAM_CHAT_ID), 'thread_id' => null]];
    }
    if (empty($targets)) return;

    $stmt = $pdo->prepare("SELECT telegram_message_id, telegram_chat_id, telegram_thread_id, take_code FROM marketing_takes WHERE id = ?");
    $stmt->execute([$takeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $code = $row ? $esc($row['take_code'] ?? 'MT-#' . $takeId) : $esc('MT-#' . $takeId);

    if ($approved) {
        $text = "✅ <b>Approved</b> — {$code}\nApproved by: " . $esc($approvedByName);
        if ($noteOrReason) $text .= "\nNote: " . $esc($noteOrReason);
    } else {
        $text = "❌ <b>Rejected</b> — {$code}\nRejected by: " . $esc($approvedByName);
        if ($noteOrReason) $text .= "\nReason: " . $esc($noteOrReason);
    }

    $replyToId = $row && !empty($row['telegram_message_id']) ? (int)$row['telegram_message_id'] : null;
    $origChatId = $row && !empty($row['telegram_chat_id']) ? trim($row['telegram_chat_id']) : null;
    $origThreadId = $row && !empty($row['telegram_thread_id']) ? (int)$row['telegram_thread_id'] : null;

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    foreach ($targets as $t) {
        if (empty($t['chat_id'])) continue;
        $c = trim($t['chat_id']);
        $tid = isset($t['thread_id']) && $t['thread_id'] !== null && $t['thread_id'] !== '' ? (int)$t['thread_id'] : null;

        $data = [
            'chat_id' => $c,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($tid !== null) {
            $data['message_thread_id'] = $tid;
        }
        $threadMatch = ($tid !== null && $tid === $origThreadId) || ($tid === null && ($origThreadId === null || $origThreadId === 0));
        $canReply = $replyToId && $origChatId && $origChatId === $c && $threadMatch;
        if ($canReply) {
            $data['reply_to_message_id'] = $replyToId;
        }
        $opts = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data),
                'timeout' => 2,
            ],
        ];
        @file_get_contents($url, false, stream_context_create($opts));
    }
}

/**
 * Send reconcile completed as reply to original suggest (same group/thread as approve).
 */
function send_marketing_reconcile_to_telegram(PDO $pdo, int $takeId, string $takeCode, string $eventName, array $itemsSummary, string $reconciledByName, ?string $returnLocationName = null, ?string $reconcileNote = null): void {
    global $TELEGRAM_BOT_TOKEN;
    global $MARKETING_TELEGRAM_BOT_TOKEN;

    $botToken = !empty($MARKETING_TELEGRAM_BOT_TOKEN) ? $MARKETING_TELEGRAM_BOT_TOKEN : $TELEGRAM_BOT_TOKEN;
    if (empty($botToken)) return;

    $stmt = $pdo->prepare("SELECT telegram_message_id, telegram_chat_id, telegram_thread_id FROM marketing_takes WHERE id = ?");
    $stmt->execute([$takeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['telegram_message_id']) || empty($row['telegram_chat_id'])) return;

    $esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    $lines = [];
    $lines[] = "🔄 <b>Reconcile Completed</b> — " . $esc($takeCode);
    $lines[] = "Event: " . $esc($eventName);
    if (!empty($returnLocationName)) {
        $lines[] = "Return Location: " . $esc($returnLocationName);
    }
    $lines[] = "Reconciled by: " . $esc($reconciledByName) . " at " . date('M j, Y H:i');
    $lines[] = "";
    foreach ($itemsSummary as $r) {
        $name = $esc($r['product_name'] ?? '?');
        $taken = number_format((float)($r['quantity_taken'] ?? 0), 2);
        $ret = number_format((float)($r['quantity_returned'] ?? 0), 2);
        $notRet = number_format((float)($r['quantity_not_returned'] ?? 0), 2);
        $lines[] = "• {$name}: {$taken} taken | {$ret} returned | {$notRet} not returned";
    }
    if (!empty($reconcileNote)) {
        $lines[] = "";
        $lines[] = "Note: " . $esc($reconcileNote);
    }

    $text = implode("\n", $lines);
    $data = [
        'chat_id' => $row['telegram_chat_id'],
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_to_message_id' => (int)$row['telegram_message_id'],
    ];
    if (!empty($row['telegram_thread_id'])) {
        $data['message_thread_id'] = (int)$row['telegram_thread_id'];
    }
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $opts = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
            'timeout' => 2,
        ],
    ];
    @file_get_contents($url, false, stream_context_create($opts));
}

function getDefaultStorageLocationId(PDO $pdo): int {
    $stmt = $pdo->query("SELECT id FROM storage_locations WHERE is_default = 1 LIMIT 1");
    return (int)($stmt->fetchColumn() ?: 0);
}

function getInventoryQuantity(PDO $pdo, string $itemName, int $locationId): float {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_on_hand), 0) as total
        FROM current_inventory
        WHERE item_name = ? AND storage_location_id = ?
    ");
    $stmt->execute([$itemName, $locationId]);
    return (float)$stmt->fetchColumn();
}

function upsertInventoryQuantity(PDO $pdo, int $productId, string $productName, float $quantityDelta, int $locationId, $userId): void {
    if ($quantityDelta < 0) {
        $toRemove = abs($quantityDelta);
        $stmt = $pdo->prepare("
            SELECT id, quantity_on_hand FROM current_inventory
            WHERE item_name = ? AND storage_location_id = ? AND quantity_on_hand > 0
            ORDER BY last_updated ASC, id ASC
        ");
        $stmt->execute([$productName, $locationId]);
        $invRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $remaining = $toRemove;
        $upd = $pdo->prepare("UPDATE current_inventory SET quantity_on_hand = ?, last_updated = NOW(), updated_by = ? WHERE id = ?");
        foreach ($invRows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $q = (float)$row['quantity_on_hand'];
            $reduce = min($remaining, $q);
            $newQty = $q - $reduce;
            $upd->execute([max(0, $newQty), $userId, $row['id']]);
            $remaining -= $reduce;
        }
        if ($remaining > 0) {
            throw new Exception("Insufficient inventory for {$productName}");
        }
        return;
    }

    $stmt = $pdo->prepare("SELECT id, quantity_on_hand FROM current_inventory WHERE item_name = ? AND storage_location_id = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$productName, $locationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $newQty = (float)$row['quantity_on_hand'] + $quantityDelta;
        $upd = $pdo->prepare("UPDATE current_inventory SET quantity_on_hand = ?, last_updated = NOW(), updated_by = ? WHERE id = ?");
        $upd->execute([$newQty, $userId, $row['id']]);
    } else {
        $prod = $pdo->prepare("SELECT sku, cost FROM products WHERE id = ?");
        $prod->execute([$productId]);
        $p = $prod->fetch(PDO::FETCH_ASSOC) ?: [];
        $ins = $pdo->prepare("INSERT INTO current_inventory (item_name, sku, storage_location_id, quantity_on_hand, unit_cost, updated_by) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$productName, $p['sku'] ?? null, $locationId, $quantityDelta, $p['cost'] ?? 0, $userId]);
    }
}
