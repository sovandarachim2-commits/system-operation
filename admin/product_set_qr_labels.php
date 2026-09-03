<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'product_set_qr_labels.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();
$user = current_user();
$isAdminUser = (($user['role'] ?? '') === 'admin');
$canPrintQrLabels = $isAdminUser || (function_exists('has_permission') && has_permission('product_set_qr_labels.create'));
$canViewQrLabelHistory = $isAdminUser || (function_exists('has_permission') && has_permission('product_set_qr_label_history.view'));
$labelLogo = get_default_logo($pdo);
$labelLogoUrl = ($labelLogo && !empty($labelLogo['file_path'])) ? uploaded_file_url($labelLogo['file_path'], 'logos') : '';

function product_set_qr_labels_normalize_prefix($prefix) {
    $prefix = strtoupper(trim((string)$prefix));
    return rtrim($prefix, '-');
}

function product_set_qr_labels_sequence_from_code($labelCode, $normalizedPrefix, $batchCode) {
    $code = strtoupper(trim((string)$labelCode));
    $normalizedPrefix = strtoupper((string)$normalizedPrefix);
    $batchCode = strtoupper((string)$batchCode);
    $legacyPrefix = $normalizedPrefix . '-';

    if (strpos($code, $normalizedPrefix . $batchCode) === 0) {
        $suffix = substr($code, strlen($normalizedPrefix . $batchCode));
    } elseif (strpos($code, $legacyPrefix . $batchCode) === 0) {
        $suffix = substr($code, strlen($legacyPrefix . $batchCode));
    } else {
        return 0;
    }

    return ctype_digit($suffix) ? (int)$suffix : 0;
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS product_set_qr_label_print_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_set_id INT NOT NULL,
        set_name VARCHAR(255) NOT NULL,
        set_sku VARCHAR(100) NOT NULL,
        label_code VARCHAR(150) NOT NULL,
        batch_code VARCHAR(50) NOT NULL,
        printed_by INT NULL,
        printed_by_name VARCHAR(255) NULL,
        printed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_label_code (label_code),
        INDEX idx_product_set_id (product_set_id),
        INDEX idx_printed_at (printed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$hasPrintStatus = (bool)$pdo->query("SHOW COLUMNS FROM product_set_qr_label_print_history LIKE 'print_status'")->fetchColumn();
if (!$hasPrintStatus) {
    $pdo->exec("ALTER TABLE product_set_qr_label_print_history ADD COLUMN print_status VARCHAR(20) NOT NULL DEFAULT 'printed' AFTER printed_by_name");
    $pdo->exec("ALTER TABLE product_set_qr_label_print_history ADD INDEX idx_print_status (print_status)");
    $pdo->exec("
        UPDATE product_set_qr_label_print_history h
        SET h.print_status = 'pending'
        WHERE (
            SELECT pal.action_type
            FROM product_set_audit_log pal
            WHERE pal.product_set_id = h.product_set_id
              AND pal.action_type IN ('stock_added', 'auto_created', 'created')
              AND pal.created_at <= h.printed_at
            ORDER BY pal.created_at DESC
            LIMIT 1
        ) = 'auto_created'
    ");
}
$pdo->exec("
    CREATE TABLE IF NOT EXISTS product_set_qr_code_settings (
        product_set_id INT PRIMARY KEY,
        code_prefix VARCHAR(80) NOT NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_code_prefix (code_prefix)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$selectedSetId = (int)($_GET['set_id'] ?? 0);
$batch = trim((string)($_GET['batch'] ?? date('Y')));
$batch = preg_replace('/[^A-Za-z0-9-]/', '', $batch);
if ($batch === '') {
    $batch = date('Y');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_print') {
    header('Content-Type: application/json');
    if (!$canPrintQrLabels) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No print permission (product_set_qr_labels.create).']);
        exit;
    }

    $postedBatch = trim((string)($_POST['batch'] ?? $batch));
    $postedBatch = preg_replace('/[^A-Za-z0-9-]/', '', $postedBatch);
    if ($postedBatch === '') {
        $postedBatch = date('Y');
    }

    $labelCodes = $_POST['label_codes'] ?? [];
    $labelSetIds = $_POST['label_set_ids'] ?? [];
    $labelSkus = $_POST['label_skus'] ?? [];
    if (!is_array($labelCodes)) {
        $labelCodes = [];
    }
    if (!is_array($labelSetIds)) {
        $labelSetIds = [];
    }
    if (!is_array($labelSkus)) {
        $labelSkus = [];
    }

    $selectedLabels = [];
    foreach ($labelCodes as $idx => $rawCode) {
        $code = trim((string)$rawCode);
        if ($code === '' || isset($selectedLabels[$code])) {
            continue;
        }
        $selectedLabels[$code] = [
            'set_id' => (int)($labelSetIds[$idx] ?? 0),
            'set_sku' => trim((string)($labelSkus[$idx] ?? '')),
        ];
    }

    if (empty($selectedLabels)) {
        echo json_encode(['success' => false, 'message' => 'No QR labels selected.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $setStmt = $pdo->prepare("SELECT id, set_name, CONCAT('SET-', id) AS set_sku FROM product_sets WHERE id = ? LIMIT 1");
        $insertStmt = $pdo->prepare("
            INSERT INTO product_set_qr_label_print_history
            (product_set_id, set_name, set_sku, label_code, batch_code, printed_by, printed_by_name, print_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'printed')
        ");
        $alreadyPrintedStmt = $pdo->prepare("
            SELECT id, print_status
            FROM product_set_qr_label_print_history
            WHERE label_code = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $markPrintedStmt = $pdo->prepare("
            UPDATE product_set_qr_label_print_history
            SET print_status = 'printed',
                printed_by = ?,
                printed_by_name = ?,
                printed_at = NOW()
            WHERE id = ?
        ");

        $saved = 0;
        $skipped = 0;
        foreach ($selectedLabels as $labelCode => $meta) {
            $alreadyPrintedStmt->execute([$labelCode]);
            $existingHistory = $alreadyPrintedStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingHistory) {
                if (($existingHistory['print_status'] ?? '') === 'pending') {
                    $markPrintedStmt->execute([
                        $user['id'] ?? null,
                        $user['name'] ?? ($user['username'] ?? null),
                        (int)$existingHistory['id'],
                    ]);
                    $saved++;
                    continue;
                }
                $skipped++;
                continue;
            }

            $setId = (int)$meta['set_id'];
            if ($setId <= 0) {
                if (preg_match('/^SET-(\d+)-/i', $labelCode, $matches)
                    || preg_match('/^SET-(\d+?)(\d{7})$/i', $labelCode, $matches)) {
                    $setId = (int)$matches[1];
                }
            }
            if ($setId <= 0) {
                continue;
            }

            $setStmt->execute([$setId]);
            $set = $setStmt->fetch(PDO::FETCH_ASSOC);
            if (!$set) {
                continue;
            }
            $historySku = product_set_qr_labels_normalize_prefix($meta['set_sku'] !== '' ? $meta['set_sku'] : (string)$set['set_sku']);

            $insertStmt->execute([
                (int)$set['id'],
                (string)$set['set_name'],
                $historySku,
                $labelCode,
                $postedBatch,
                $user['id'] ?? null,
                $user['name'] ?? ($user['username'] ?? null),
            ]);
            $saved++;
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'saved' => $saved, 'skipped' => $skipped]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$stmt = $pdo->query("
    SELECT
        ps.id,
        ps.set_name,
        COALESCE(NULLIF(qcs.code_prefix, ''), CONCAT('SET-', ps.id)) AS set_sku,
        ps.selling_price,
        ps.available_stock,
        COUNT(psi.id) AS product_count,
        GROUP_CONCAT(CONCAT(p.name, ' x', psi.quantity) ORDER BY p.name SEPARATOR ', ') AS products
    FROM product_sets ps
    LEFT JOIN product_set_qr_code_settings qcs ON qcs.product_set_id = ps.id
    LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
    LEFT JOIN products p ON p.id = psi.product_id
    WHERE ps.is_active = 1
    GROUP BY ps.id, ps.set_name, qcs.code_prefix, ps.selling_price, ps.available_stock
    ORDER BY ps.set_name
");
$sets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labels = [];
$labelSummary = [];
foreach ($sets as $set) {
    if ($selectedSetId > 0 && (int)$set['id'] !== $selectedSetId) {
        continue;
    }

    $set['set_sku'] = product_set_qr_labels_normalize_prefix($set['set_sku']);
    $labelCount = max(0, (int)$set['available_stock']);
    $sequenceStart = 1;

    if ($labelCount > 0) {
        $prefix = (string)$set['set_sku'] . $batch;
        $legacyPrefix = (string)$set['set_sku'] . '-' . $batch;
        $existingStmt = $pdo->prepare("
            SELECT label_code
            FROM product_set_qr_label_print_history
            WHERE product_set_id = ?
              AND batch_code = ?
              AND (label_code LIKE ? OR label_code LIKE ?)
        ");
        $existingStmt->execute([(int)$set['id'], $batch, $prefix . '%', $legacyPrefix . '%']);
        $maxSequence = 0;
        foreach ($existingStmt->fetchAll(PDO::FETCH_COLUMN) as $existingCode) {
            $maxSequence = max($maxSequence, product_set_qr_labels_sequence_from_code($existingCode, (string)$set['set_sku'], $batch));
        }
        $sequenceStart = $maxSequence + 1;
    }

    $labelSummary[] = [
        'id' => (int)$set['id'],
        'set_name' => (string)$set['set_name'],
        'set_sku' => (string)$set['set_sku'],
        'available_stock' => (int)$set['available_stock'],
        'label_count' => $labelCount,
    ];

    for ($i = 1; $i <= $labelCount; $i++) {
        $sequence = $sequenceStart + $i - 1;
        $set['label_code'] = $set['set_sku'] . $batch . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT);
        $labels[] = $set;
    }
}

$printedLabelCodes = [];
if (!empty($labels)) {
    $labelCodesForLookup = array_values(array_unique(array_map(static function ($label) {
        return (string)$label['label_code'];
    }, $labels)));
    $chunks = array_chunk($labelCodesForLookup, 100);
    foreach ($chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $printedStmt = $pdo->prepare("
            SELECT label_code, MAX(printed_at) AS printed_at
            FROM product_set_qr_label_print_history
            WHERE label_code IN ($placeholders)
            GROUP BY label_code
        ");
        $printedStmt->execute($chunk);
        foreach ($printedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $printedLabelCodes[(string)$row['label_code']] = (string)$row['printed_at'];
        }
    }
}
$labels = array_values(array_filter($labels, static function ($label) use ($printedLabelCodes) {
    return !isset($printedLabelCodes[(string)$label['label_code']]);
}));

require_once __DIR__ . '/../layout/header.php';
?>

<style>
    .qr-label-page .toolbar-card {
        border: 1px solid #e5e7eb;
    }
    .qr-label-grid {
        display: none;
        grid-template-columns: repeat(auto-fill, 216px);
        gap: 10px;
        align-items: start;
    }
    .qr-label {
        width: 216px;
        height: 170px;
        box-sizing: border-box;
        border: 1px solid #111827;
        border-radius: 6px;
        padding: 10px;
        background: #fff;
        color: #111827;
        display: grid;
        grid-template-columns: 86px 1fr;
        gap: 10px;
        align-items: center;
        break-inside: avoid;
        overflow: hidden;
        position: relative;
    }
    .qr-label-select {
        width: 18px;
        height: 18px;
    }
    .qr-label img {
        width: 86px;
        height: 86px;
        object-fit: contain;
    }
    .qr-label-title {
        font-size: 12px;
        font-weight: 700;
        line-height: 1.2;
        margin-bottom: 6px;
        overflow-wrap: anywhere;
    }
    .qr-label-code {
        font-size: 9px;
        line-height: 1.35;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        overflow-wrap: anywhere;
    }
    .qr-label-logo {
        display: none;
    }
    @media print {
        .admin-sidebar,
        .admin-topbar,
        .sidebar-overlay,
        .navbar,
        .btn,
        .toolbar-card,
        .label-list-card,
        .page-heading {
            display: none !important;
        }
        .qr-label-select {
            display: none !important;
        }
        body.print-selected-mode .qr-label:not(.is-selected) {
            display: none !important;
        }
        .qr-label,
        .qr-label.is-selected {
            opacity: 1 !important;
            box-shadow: none !important;
        }
        body {
            background: #fff !important;
        }
        .container-fluid {
            padding: 0 !important;
            max-width: none !important;
        }
        .qr-label-grid {
            display: grid !important;
            grid-template-columns: 60mm;
            gap: 0;
        }
        .qr-label {
            width: 60mm;
            height: 40mm;
            box-sizing: border-box;
            border: 0;
            border-radius: 0;
            padding: 4.2mm;
            color: #000;
            display: grid;
            grid-template-columns: 24mm 24mm;
            grid-template-rows: 24mm auto;
            column-gap: 3.5mm;
            row-gap: 1.8mm;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
            page-break-inside: avoid;
            page-break-after: always;
            break-inside: avoid;
            break-after: page;
        }
        .qr-label::before {
            content: "";
            position: absolute;
            inset: 1.25mm;
            border: 0.55mm solid #000;
            border-radius: 3mm;
            pointer-events: none;
        }
        .qr-label > * {
            position: relative;
            z-index: 1;
        }
        .qr-label:last-child {
            page-break-after: auto;
            break-after: auto;
        }
        .qr-label-qr img {
            width: 24mm;
            height: 24mm;
            margin: 0;
            object-fit: contain;
        }
        .qr-label-title {
            color: #000;
            font-size: 8pt;
            font-weight: 800;
            line-height: 1.12;
            margin-bottom: 1.2mm;
            max-height: 10mm;
            overflow: hidden;
        }
        .qr-label-code {
            color: #000;
            font-size: 6.7pt;
            font-weight: 700;
            line-height: 1.15;
            letter-spacing: 0;
            grid-column: 1 / -1;
            grid-row: 2;
            white-space: nowrap;
        }
        .qr-label-code strong {
            font-weight: 800;
        }
        .qr-label-text {
            display: contents;
        }
        .qr-label-logo {
            display: block;
            width: 24mm !important;
            height: 24mm !important;
            max-width: 24mm;
            max-height: 24mm;
            margin: 0;
            object-fit: contain;
            transform: scale(1.08);
            transform-origin: center;
            grid-column: 2;
            grid-row: 1;
            justify-self: center;
            align-self: center;
        }
        @page {
            size: 60mm 40mm;
            margin: 0;
        }
    }
</style>

<div class="container-fluid py-4 qr-label-page">
    <div class="d-flex justify-content-between align-items-center mb-4 page-heading">
        <div>
            <h2 class="mb-1"><i class="bi bi-qr-code me-2"></i>Product Set QR Labels</h2>
            <small class="text-muted">Print QR labels for product sets. Scan the QR into the scanner Set QR field.</small>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_management.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <button type="button" class="btn btn-primary" onclick="printSelectedLabels()" <?= $canPrintQrLabels ? '' : 'disabled title="No print permission"' ?>>
                <i class="bi bi-printer me-1"></i>Print Labels
            </button>
            <?php if ($canViewQrLabelHistory): ?>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_qr_label_history.php" class="btn btn-outline-primary">
                <i class="bi bi-clock-history me-1"></i>History
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card toolbar-card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label">Product Set</label>
                    <select name="set_id" class="form-select">
                        <option value="0">All product sets</option>
                        <?php foreach ($sets as $set): ?>
                            <option value="<?= (int)$set['id'] ?>" <?= $selectedSetId === (int)$set['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($set['set_name']) ?> - Stock: <?= number_format((float)$set['available_stock'], 0) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Batch Code</label>
                    <input type="text" name="batch" class="form-control" value="<?= htmlspecialchars($batch) ?>" maxlength="24">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-funnel me-1"></i>Apply
                    </button>
                    <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_qr_labels.php" class="btn btn-outline-secondary">
                        Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($labelSummary)): ?>
        <div class="card toolbar-card mb-4">
            <div class="card-header">
                <strong>Product Set Available Qty List</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Set</th>
                            <th>SKU</th>
                            <th class="text-end">Available Qty</th>
                            <th class="text-end">QR Labels</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($labelSummary as $summary): ?>
                            <tr>
                                <td><?= htmlspecialchars($summary['set_name']) ?></td>
                                <td><code><?= htmlspecialchars($summary['set_sku']) ?></code></td>
                                <td class="text-end"><?= number_format($summary['available_stock']) ?></td>
                                <td class="text-end"><?= number_format($summary['label_count']) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_qr_labels.php?<?= http_build_query(['set_id' => $summary['id'], 'batch' => date('Y')]) ?>">
                                        <i class="bi bi-qr-code me-1"></i>Available Qty
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($labels)): ?>
        <div class="alert alert-info">
            No active product sets found.
        </div>
    <?php else: ?>
        <div class="card toolbar-card mb-3">
            <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <div>
                    <strong>QR Label Selection</strong>
                    <div class="small text-muted">
                        Selected: <span id="selectedLabelCount">0</span> / <?= number_format(count($labels)) ?>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="setAllLabelsChecked(true)">
                        <i class="bi bi-check2-square me-1"></i>Select All
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setAllLabelsChecked(false)">
                        Clear
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="printSelectedLabels()" <?= $canPrintQrLabels ? '' : 'disabled title="No print permission"' ?>>
                        <i class="bi bi-printer me-1"></i>Print Selected
                    </button>
                </div>
            </div>
        </div>
        <div class="card label-list-card mb-3">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 56px;">No</th>
                            <th style="width: 48px;"><input type="checkbox" class="form-check-input" id="checkAllLabels"></th>
                            <th>Product Set</th>
                            <th>QR Code</th>
                            <th>SKU</th>
                            <th class="text-end">Stock Qty</th>
                            <th>Batch</th>
                            <th class="text-end">View</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($labels as $index => $set): ?>
                            <?php $qrValue = (string)$set['label_code']; ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td>
                                    <input type="checkbox"
                                           class="form-check-input qr-label-select"
                                           name="label_codes[]"
                                           value="<?= htmlspecialchars($qrValue) ?>"
                                           data-set-id="<?= (int)$set['id'] ?>"
                                           data-set-sku="<?= htmlspecialchars((string)$set['set_sku']) ?>"
                                           aria-label="Select <?= htmlspecialchars($qrValue) ?>">
                                </td>
                                <td><?= htmlspecialchars($set['set_name']) ?></td>
                                <td><code><?= htmlspecialchars($qrValue) ?></code></td>
                                <td><code><?= htmlspecialchars($set['set_sku']) ?></code></td>
                                <td class="text-end"><?= number_format((float)$set['available_stock'], 0) ?></td>
                                <td><?= htmlspecialchars($batch) ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="previewLabel('<?= htmlspecialchars($qrValue, ENT_QUOTES) ?>')">
                                        View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="qr-label-grid">
            <?php foreach ($labels as $index => $set): ?>
                <?php
                    $qrValue = (string)$set['label_code'];
                    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($qrValue);
                ?>
                <div class="qr-label" data-label-code="<?= htmlspecialchars($qrValue) ?>">
                    <div class="qr-label-qr">
                        <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR code for <?= htmlspecialchars($set['set_name']) ?>">
                    </div>
                    <div class="qr-label-text">
                        <div class="qr-label-code">Code: <?= htmlspecialchars($qrValue) ?></div>
                        <?php if ($labelLogoUrl !== ''): ?>
                            <img class="qr-label-logo" src="<?= htmlspecialchars($labelLogoUrl) ?>" alt="Logo">
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function refreshSelectedLabelCount() {
    const boxes = Array.from(document.querySelectorAll('.qr-label-select'));
    let selected = 0;
    boxes.forEach((box) => {
        const label = document.querySelector(`.qr-label[data-label-code="${CSS.escape(box.value)}"]`);
        if (!label) return;
        label.classList.toggle('is-selected', box.checked);
        if (box.checked) selected++;
    });
    const countEl = document.getElementById('selectedLabelCount');
    if (countEl) countEl.textContent = selected.toString();
    const checkAll = document.getElementById('checkAllLabels');
    if (checkAll) {
        checkAll.checked = boxes.length > 0 && selected === boxes.length;
        checkAll.indeterminate = selected > 0 && selected < boxes.length;
    }
}

function setAllLabelsChecked(checked) {
    document.querySelectorAll('.qr-label-select').forEach((box) => {
        box.checked = checked;
    });
    refreshSelectedLabelCount();
}

async function printSelectedLabels() {
    if (!<?= $canPrintQrLabels ? 'true' : 'false' ?>) {
        alert('No print permission (product_set_qr_labels.create).');
        return;
    }

    refreshSelectedLabelCount();
    const selectedBoxes = Array.from(document.querySelectorAll('.qr-label-select:checked'));
    if (selectedBoxes.length === 0) {
        alert('Please select at least one QR label to print.');
        return;
    }

    const payload = new FormData();
    payload.append('action', 'record_print');
    payload.append('batch', <?= json_encode($batch) ?>);
    selectedBoxes.forEach((box) => {
        payload.append('label_codes[]', box.value);
        payload.append('label_set_ids[]', box.dataset.setId || '');
        payload.append('label_skus[]', box.dataset.setSku || '');
    });

    try {
        const response = await fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: payload
        });
        const result = await response.json();
        if (!result.success) {
            alert('Could not save QR print history: ' + (result.message || 'Unknown error'));
            return;
        }
    } catch (error) {
        alert('Could not save QR print history: ' + error);
        return;
    }

    document.body.classList.add('print-selected-mode');
    window.print();
}

function previewLabel(code) {
    const label = document.querySelector(`.qr-label[data-label-code="${CSS.escape(code)}"]`);
    if (!label) return;
    const win = window.open('', '_blank', 'width=420,height=320');
    win.document.write('<!doctype html><html><head><title>QR Label</title><style>html,body{min-height:100%;margin:0}body{font-family:Arial,sans-serif;background:#f3f4f6;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}.qr-label{width:60mm;height:40mm;border:0;border-radius:0;padding:4.2mm;display:grid;grid-template-columns:24mm 24mm;grid-template-rows:24mm auto;column-gap:3.5mm;row-gap:1.8mm;align-items:center;justify-content:center;text-align:center;box-sizing:border-box;color:#000;overflow:hidden;position:relative;background:#fff}.qr-label::before{content:"";position:absolute;inset:1.25mm;border:0.55mm solid #000;border-radius:3mm;pointer-events:none}.qr-label>*{position:relative;z-index:1}.qr-label-qr img{width:24mm;height:24mm;object-fit:contain}.qr-label-text{display:contents}.qr-label-title{display:none}.qr-label-code{color:#000;font-size:6.7pt;font-weight:700;line-height:1.15;grid-column:1/-1;grid-row:2;white-space:nowrap;font-family:monospace;overflow-wrap:anywhere}.qr-label-logo{display:block!important;width:24mm!important;height:24mm!important;max-width:24mm!important;max-height:24mm!important;margin:0;object-fit:contain;transform:scale(1.08);transform-origin:center;grid-column:2;grid-row:1;justify-self:center;align-self:center}@media print{html,body{width:60mm;height:40mm;margin:0;padding:0;background:#fff;display:block}.qr-label{page-break-after:auto}@page{size:60mm 40mm;margin:0}}</style></head><body>' + label.outerHTML + '</body></html>');
    win.document.close();
}

window.addEventListener('afterprint', function() {
    document.body.classList.remove('print-selected-mode');
});

function initQrLabelSelection() {
    document.querySelectorAll('.qr-label-select').forEach((box) => {
        if (box.dataset.selectionBound === '1') return;
        box.dataset.selectionBound = '1';
        box.addEventListener('change', refreshSelectedLabelCount);
    });
    const checkAll = document.getElementById('checkAllLabels');
    if (checkAll && checkAll.dataset.selectionBound !== '1') {
        checkAll.dataset.selectionBound = '1';
        checkAll.addEventListener('change', () => setAllLabelsChecked(checkAll.checked));
    }
    refreshSelectedLabelCount();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initQrLabelSelection);
} else {
    initQrLabelSelection();
}
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
