<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['cashier', 'admin'], 'broadcast.view');
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../sold_products_report_lib.php';
require_once __DIR__ . '/../user_activity_lib.php';

$pdo  = get_db_connection();
$user = current_user();

$errors  = [];
$success = '';
$overdueItems = [];
$missingPreparedOrders = [];
$reportSendErrors = [];

$today = date('Y-m-d');
$defaultMessage = 'បិទស្តុកថ្ងៃទី ' . $today;

// Load all active sellers that have Telegram configured for selection UI
$stmtAll = $pdo->query("SELECT id, name, username FROM users WHERE role = 'seller' AND active = 1 ORDER BY name ASC");
$allSellers = $stmtAll->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message      = trim($_POST['message'] ?? '');
    $target_mode  = $_POST['target_mode'] ?? 'all'; // 'all' or 'selected'
    $target_ids   = $_POST['target_ids'] ?? [];

    if ($message === '') {
        $errors[] = 'Message is required.';
    } else {
        // If the message contains #stop, enforce pending prepared items rule and adjust QR date
        if (stripos($message, '#stop') !== false) {
            // Check for prepared items (product_entries) that have not been scanned out (out_items)
            // and are older than 24 hours. If any exist, block the broadcast.
            try {
                $enforcementStart = '2026-03-13 00:00:00';
                $stmtChk = $pdo->prepare(
                    "SELECT pe.inv, pe.datetime\n"
                  . "FROM product_entries pe\n"
                  . "LEFT JOIN out_items oi ON oi.inv = pe.inv\n"
                  . "LEFT JOIN orders o ON o.order_code = pe.inv\n"
                  . "WHERE oi.id IS NULL\n"
                  . "  AND TIMESTAMPDIFF(HOUR, pe.datetime, NOW()) >= 24\n"
                  . "  AND pe.datetime >= :start_date\n"
                  . "  AND (o.id IS NULL OR o.is_returned = 0)\n"
                  . "ORDER BY pe.datetime ASC\n"
                  . "LIMIT 1000"
                );
                $stmtChk->execute([':start_date' => $enforcementStart]);
                $overdue = $stmtChk->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $overdue = [];
            }

            try {
                // Enforce missing-prepared-orders check only for print jobs from the enforcement start date onward
                $missingEnforcementStart = '2026-03-16 00:00:00';

                $stmtMissing = $pdo->prepare(
                    "SELECT o.order_code AS inv, pj.printed_at\n"
                  . "FROM print_jobs pj\n"
                  . "JOIN orders o ON o.id = pj.order_id\n"
                  . "LEFT JOIN product_entries pe ON pe.inv = o.order_code\n"
                  . "WHERE pj.printed_at <= (NOW() - INTERVAL 24 HOUR)\n"
                  . "  AND pj.printed_at >= :start_date\n"
                  . "  AND o.is_cancelled = 0\n"
                  . "  AND o.is_returned = 0\n"
                  . "  AND pe.id IS NULL\n"
                  . "ORDER BY pj.printed_at ASC\n"
                  . "LIMIT 1000"
                );
                $stmtMissing->execute([':start_date' => $missingEnforcementStart]);
                $missingPreparedOrders = $stmtMissing->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $missingPreparedOrders = [];
            }

            if (!empty($overdue)) {
                $overdueItems = $overdue;
                $errors[] = 'Cannot send #stop: Some prepared items exceed 24h without Out scan.';
            } elseif (!empty($missingPreparedOrders)) {
                $errors[] = 'Cannot send #stop: Some printed orders are missing in Prepared Items.';
            } else {
                // No overdue prepared items; proceed to adjust QR effective date
                $today = new DateTimeImmutable('today');
                $current = get_qr_effective_date($pdo);

                if ($current === null || $current < $today) {
                    $newDate = $today->modify('+1 day');
                } elseif ($current->format('Y-m-d') === $today->format('Y-m-d')) {
                    $newDate = $today->modify('+1 day');
                } else {
                    $newDate = $current;
                }

                set_qr_effective_date($pdo, $newDate);

                // Send sold products report to Telegram immediately on #stop.
                try {
                    $reportDate = date('Y-m-d');
                    $autoSendResult = sprlSendSoldProductsReport($pdo, $reportDate, $reportDate);
                    if (!empty($autoSendResult['errors'])) {
                        // If the bot is kicked/forbidden for the report supergroup,
                        // do NOT block the main seller broadcast.
                        foreach ((array)$autoSendResult['errors'] as $errLine) {
                            $errLower = strtolower((string)$errLine);
                            $isKickedOrForbidden = (
                                strpos($errLower, 'forbidden') !== false ||
                                strpos($errLower, 'bot was kicked') !== false ||
                                strpos($errLower, 'kicked') !== false
                            );

                            if ($isKickedOrForbidden) {
                                $reportSendErrors[] = (string)$errLine;
                            } else {
                                $errors[] = (string)$errLine;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Failed to auto send sold products report: ' . $e->getMessage();
                }
            }
        }

        global $TELEGRAM_BOT_TOKEN;
        if (empty($TELEGRAM_BOT_TOKEN)) {
            $errors[] = 'Telegram bot is not configured.';
        } else {
            // Build base query for sellers with Telegram configured
            $sql = "SELECT id, telegram_chat_id, telegram_thread_id FROM users WHERE role = 'seller' AND active = 1 AND telegram_chat_id IS NOT NULL AND telegram_chat_id <> ''";
            $params = [];

            if ($target_mode === 'selected') {
                // Filter by selected seller IDs
                $cleanIds = [];
                foreach ($target_ids as $id) {
                    $id = (int)$id;
                    if ($id > 0) {
                        $cleanIds[] = $id;
                    }
                }

                if ($cleanIds) {
                    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                    $sql .= " AND id IN (" . $placeholders . ")";
                    $params = $cleanIds;
                } else {
                    $errors[] = 'Please select at least one seller.';
                }
            }

            if (!$errors) {
                $stmt    = $pdo->prepare($sql);
                $stmt->execute($params);
                $sellers = $stmt->fetchAll();

                if (!$sellers) {
                    $errors[] = 'No sellers have Telegram chat IDs configured for this selection.';
                } else {
                    $url = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage";

                    foreach ($sellers as $s) {
                        $chatId   = $s['telegram_chat_id'];
                        $threadId = $s['telegram_thread_id'] ?? null;

                        // For #stop, use cashier-name prefix format as requested.
                        if (stripos($message, '#stop') !== false) {
                            $textToSend = '[' . $user['name'] . '] ' . $message;
                        } else {
                            // Prefix regular messages with cashier name for context.
                            $textToSend = '[' . $user['name'] . '] ' . $message;
                        }

                        $data = [
                            'chat_id' => $chatId,
                            'text'    => $textToSend,
                        ];
                        if ($threadId !== null && $threadId !== '') {
                            $data['message_thread_id'] = (int)$threadId;
                        }

                        $options = [
                            'http' => [
                                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                                'method'  => 'POST',
                                'content' => http_build_query($data),
                                'timeout' => 5,
                            ],
                        ];
                        @file_get_contents($url, false, stream_context_create($options));
                    }

                    user_activity_log_module_mutation(
                        $user,
                        'cashier',
                        'create',
                        __FILE__,
                        ($target_mode === 'selected' ? 'selected' : 'all') . ' · ' . count($sellers) . ' seller(s)'
                    );

                    if ($target_mode === 'selected') {
                        $success = 'Message sent to selected sellers.';
                    } else {
                        $success = 'Message sent to all sellers.';
                    }
                }
            }
        }
    }
}

include __DIR__ . '/../layout/header.php';
?>
<?php if (!empty($overdueItems)): ?>
    <div id="topStopAlert" style="position:fixed;top:0;left:0;right:0;z-index:1055;background:#dc3545;color:#fff;padding:12px 16px;box-shadow:0 2px 6px rgba(0,0,0,.2);">
        <div class="container d-flex justify-content-between align-items-center">
            <div><strong>Cannot send #stop</strong>: Overdue prepared items require Out scan. See list below.</div>
            <button type="button" class="btn btn-light btn-sm" onclick="document.getElementById('topStopAlert').remove();">Close</button>
        </div>
    </div>
    <div style="height:56px"></div>
<?php endif; ?>
<?php if (!empty($missingPreparedOrders)): ?>
    <div id="topMissingPreparedAlert" style="position:fixed;top:0;left:0;right:0;z-index:1055;background:#dc3545;color:#fff;padding:12px 16px;box-shadow:0 2px 6px rgba(0,0,0,.2);">
        <div class="container d-flex justify-content-between align-items-center">
            <div><strong>Cannot send #stop</strong>: Some printed orders are missing in Prepared Items. See list below.</div>
            <button type="button" class="btn btn-light btn-sm" onclick="document.getElementById('topMissingPreparedAlert').remove();">Close</button>
        </div>
    </div>
    <div style="height:56px"></div>
<?php endif; ?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Send Broadcast Message</h1>
    </div>

    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-body">
            <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
                <?php foreach ($reportSendErrors as $e): ?><div class="alert alert-warning"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

            <?php if (!empty($overdueItems)): ?>
                <div class="alert alert-warning">
                    <div class="fw-bold mb-2">Overdue prepared items (require Out scan)</div>
                    <ul class="mb-0">
                        <?php foreach ($overdueItems as $row): ?>
                            <li><code><?= htmlspecialchars($row['inv']) ?></code> — <?= htmlspecialchars($row['datetime']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="small text-muted mt-2">Only the first few items are shown.</div>
                </div>
            <?php endif; ?>

            <?php if (!empty($missingPreparedOrders)): ?>
                <div class="alert alert-warning">
                    <div class="fw-bold mb-2">Printed orders missing in Prepared Items (today)</div>
                    <ul class="mb-0">
                        <?php foreach ($missingPreparedOrders as $row): ?>
                            <li><code><?= htmlspecialchars($row['inv']) ?></code> — <?= htmlspecialchars($row['printed_at']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="small text-muted mt-2">Only the first few items are shown.</div>
                </div>
            <?php endif; ?>

            <div id="broadcastLoadingOverlay" style="display:none;position:fixed;inset:0;z-index:3000;background:rgba(0,0,0,.35);">
                <div class="d-flex h-100 align-items-center justify-content-center px-3">
                    <div class="card shadow-lg border-0" style="min-width:280px;max-width:520px;">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <div>
                                    <div class="fw-bold">Sending...</div>
                                    <div class="small text-muted">Please wait until Telegram finishes.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <form id="broadcastForm" method="post" class="d-flex flex-column gap-3">
                <div>
                    <label class="form-label">Message</label>
                    <textarea name="message" class="form-control form-control-lg" rows="3"><?= htmlspecialchars($_POST['message'] ?? $defaultMessage) ?></textarea>
                    <div class="form-text">This will be sent to the selected sellers' Telegram groups/topics.</div>
                </div>

                <div>
                    <label class="form-label">Send to</label>
                    <?php $selectedMode = $_POST['target_mode'] ?? 'all'; ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="target_mode" id="target_all" value="all" <?= $selectedMode === 'selected' ? '' : 'checked' ?>>
                        <label class="form-check-label" for="target_all">
                            All sellers with Telegram configured
                        </label>
                    </div>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="radio" name="target_mode" id="target_selected" value="selected" <?= $selectedMode === 'selected' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="target_selected">
                            Only selected sellers
                        </label>
                    </div>
                </div>

                <div>
                    <label class="form-label">Select sellers (optional)</label>
                    <?php $postedTargets = $_POST['target_ids'] ?? []; ?>
                    <select name="target_ids[]" class="form-select form-select-lg" multiple size="5">
                        <?php foreach ($allSellers as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= in_array($s['id'], (array)$postedTargets) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['name'] . ' (' . $s['username'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Hold Ctrl (Windows) or Cmd (Mac) to select more than one seller.</div>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <button type="submit" id="broadcastSubmitBtn" class="btn btn-primary btn-lg">Send Message</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function () {
        var formEl = document.getElementById('broadcastForm');
        var overlayEl = document.getElementById('broadcastLoadingOverlay');
        var btnEl = document.getElementById('broadcastSubmitBtn');
        if (!formEl || !overlayEl) return;

        formEl.addEventListener('submit', function () {
            if (overlayEl) overlayEl.style.display = 'block';
            if (btnEl) {
                btnEl.disabled = true;
                btnEl.textContent = 'Sending...';
            }
        });
    })();
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
