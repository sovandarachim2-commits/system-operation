<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'product_set_qr_label_history.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();
$user = current_user();
$isAdminUser = (($user['role'] ?? '') === 'admin');
$canViewQrLabels = $isAdminUser || (function_exists('has_permission') && has_permission('product_set_qr_labels.view'));
$canPrintQrLabels = $isAdminUser || (function_exists('has_permission') && has_permission('product_set_qr_labels.create'));
$labelLogo = get_default_logo($pdo);
$labelLogoUrl = ($labelLogo && !empty($labelLogo['file_path'])) ? uploaded_file_url($labelLogo['file_path'], 'logos') : '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_printed') {
    header('Content-Type: application/json');
    if (!$canPrintQrLabels) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No print permission (product_set_qr_labels.create).']);
        exit;
    }

    $labelCodes = $_POST['label_codes'] ?? [];
    if (!is_array($labelCodes)) {
        $labelCodes = [];
    }
    $labelCodes = array_values(array_unique(array_filter(array_map(static function ($code) {
        return trim((string)$code);
    }, $labelCodes))));

    if (empty($labelCodes)) {
        echo json_encode(['success' => false, 'message' => 'No QR labels selected.']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($labelCodes), '?'));
    $params = array_merge([
        $user['id'] ?? null,
        $user['name'] ?? ($user['username'] ?? null),
    ], $labelCodes);
    $markStmt = $pdo->prepare("
        UPDATE product_set_qr_label_print_history
        SET print_status = 'printed',
            printed_by = ?,
            printed_by_name = ?,
            printed_at = NOW()
        WHERE label_code IN ($placeholders)
    ");
    $markStmt->execute($params);
    echo json_encode(['success' => true, 'updated' => $markStmt->rowCount()]);
    exit;
}

$setId = (int)($_GET['set_id'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$printStatus = trim((string)($_GET['print_status'] ?? ''));
if (!in_array($printStatus, ['pending', 'printed'], true)) {
    $printStatus = '';
}

$setsStmt = $pdo->query("SELECT id, set_name FROM product_sets WHERE is_active = 1 ORDER BY set_name");
$sets = $setsStmt->fetchAll(PDO::FETCH_ASSOC);

$where = [];
$params = [];
if ($setId > 0) {
    $where[] = 'product_set_id = ?';
    $params[] = $setId;
}
if ($search !== '') {
    $where[] = '(set_name LIKE ? OR set_sku LIKE ? OR label_code LIKE ? OR batch_code LIKE ? OR printed_by_name LIKE ?)';
    for ($i = 0; $i < 5; $i++) {
        $params[] = '%' . $search . '%';
    }
}
if ($printStatus !== '') {
    $where[] = 'print_status = ?';
    $params[] = $printStatus;
}
if ($dateFrom !== '') {
    $where[] = 'DATE(printed_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(printed_at) <= ?';
    $params[] = $dateTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("
    SELECT *
    FROM product_set_qr_label_print_history
    $whereSql
    ORDER BY printed_at DESC, id DESC
    LIMIT 500
");
$stmt->execute($params);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$eventByHistoryId = [];
if (!empty($history)) {
    $eventStmt = $pdo->prepare("
        SELECT action_type, created_at
        FROM product_set_audit_log
        WHERE product_set_id = ?
          AND action_type IN ('stock_added', 'auto_created', 'created')
          AND created_at <= ?
        ORDER BY created_at DESC
        LIMIT 1
    ");

    foreach ($history as $row) {
        $eventStmt->execute([(int)$row['product_set_id'], (string)$row['printed_at']]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        $eventByHistoryId[(int)$row['id']] = $event['action_type'] ?? '';
    }
}

function product_set_qr_event_label(string $actionType): array
{
    switch ($actionType) {
        case 'stock_added':
            return ['Add More', 'bg-info text-dark'];
        case 'auto_created':
            return ['Auto Create During Print', 'bg-warning text-dark'];
        case 'created':
            return ['Initial Create', 'bg-success'];
        default:
            return ['-', 'bg-secondary'];
    }
}

function product_set_qr_print_status_label(string $printStatus): array
{
    switch ($printStatus) {
        case 'pending':
            return ['Pending Print', 'bg-warning text-dark'];
        case 'printed':
            return ['Printed', 'bg-success'];
        default:
            return ['-', 'bg-secondary'];
    }
}

require_once __DIR__ . '/../layout/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="bi bi-clock-history me-2"></i>QR Label Print History</h2>
            <small class="text-muted">History of product set QR labels printed by users.</small>
        </div>
        <div class="d-flex gap-2">
            <?php if ($canViewQrLabels): ?>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_qr_labels.php" class="btn btn-outline-primary">
                <i class="bi bi-qr-code me-1"></i>QR Labels
            </a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_management.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Product Sets
            </a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="QR code, SKU, batch, user">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Product Set</label>
                    <select name="set_id" class="form-select">
                        <option value="0">All product sets</option>
                        <?php foreach ($sets as $set): ?>
                            <option value="<?= (int)$set['id'] ?>" <?= $setId === (int)$set['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($set['set_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Print Status</label>
                    <select name="print_status" class="form-select">
                        <option value="" <?= $printStatus === '' ? 'selected' : '' ?>>All statuses</option>
                        <option value="pending" <?= $printStatus === 'pending' ? 'selected' : '' ?>>Pending Print</option>
                        <option value="printed" <?= $printStatus === 'printed' ? 'selected' : '' ?>>Printed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="col-md-12 d-flex gap-2">
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_qr_label_history.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div>
                <strong>Print History</strong>
                <span class="small text-muted ms-2"><?= number_format(count($history)) ?> records</span>
                <span class="small text-muted ms-2">Selected: <span id="selectedHistoryLabelCount">0</span></span>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="setAllHistoryLabelsChecked(true)">
                    <i class="bi bi-check2-square me-1"></i>Select All
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setAllHistoryLabelsChecked(false)">
                    Clear
                </button>
                <button type="button" class="btn btn-sm btn-primary" onclick="printSelectedHistoryLabels()">
                    <i class="bi bi-printer me-1"></i>Reprint Selected
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>No</th>
                        <th style="width: 48px;"><input type="checkbox" class="form-check-input" id="checkAllHistoryLabels"></th>
                        <th>QR Code</th>
                        <th>Product Set</th>
                        <th>SKU</th>
                        <th>Event</th>
                        <th>Print Status</th>
                        <th>Batch</th>
                        <th>Printed At</th>
                        <th>Printed By</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">No QR label print history found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $index => $row): ?>
                            <?php [$eventLabel, $eventClass] = product_set_qr_event_label((string)($eventByHistoryId[(int)$row['id']] ?? '')); ?>
                            <?php [$printStatusLabel, $printStatusClass] = product_set_qr_print_status_label((string)($row['print_status'] ?? 'printed')); ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td>
                                    <input type="checkbox"
                                           class="form-check-input history-label-select"
                                           value="<?= htmlspecialchars((string)$row['label_code']) ?>"
                                           data-set-name="<?= htmlspecialchars((string)$row['set_name'], ENT_QUOTES) ?>"
                                           data-label-code="<?= htmlspecialchars((string)$row['label_code'], ENT_QUOTES) ?>"
                                           data-history-id="<?= (int)$row['id'] ?>"
                                           aria-label="Select <?= htmlspecialchars((string)$row['label_code']) ?>">
                                </td>
                                <td><code><?= htmlspecialchars((string)$row['label_code']) ?></code></td>
                                <td><?= htmlspecialchars((string)$row['set_name']) ?></td>
                                <td><code><?= htmlspecialchars((string)$row['set_sku']) ?></code></td>
                                <td><span class="badge rounded-pill <?= htmlspecialchars($eventClass) ?>"><?= htmlspecialchars($eventLabel) ?></span></td>
                                <td><span class="badge rounded-pill <?= htmlspecialchars($printStatusClass) ?>" data-print-status-label="<?= htmlspecialchars((string)$row['label_code'], ENT_QUOTES) ?>"><?= htmlspecialchars($printStatusLabel) ?></span></td>
                                <td><?= htmlspecialchars((string)$row['batch_code']) ?></td>
                                <td><?= htmlspecialchars((string)$row['printed_at']) ?></td>
                                <td><?= htmlspecialchars((string)($row['printed_by_name'] ?: '-')) ?></td>
                                <td class="text-end">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary"
                                            onclick="viewQrLabel('<?= htmlspecialchars((string)$row['set_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars((string)$row['label_code'], ENT_QUOTES) ?>')">
                                        <i class="bi bi-eye me-1"></i>View
                                    </button>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            onclick="reprintQrLabel('<?= htmlspecialchars((string)$row['set_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars((string)$row['label_code'], ENT_QUOTES) ?>')">
                                        <i class="bi bi-printer me-1"></i>Reprint
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="qrLabelViewModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">QR Label</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body qr-label-view-body d-flex justify-content-center" id="qrLabelViewBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="qrLabelViewReprintBtn">
                    <i class="bi bi-printer me-1"></i>Reprint
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const labelLogoUrl = <?= json_encode($labelLogoUrl) ?>;
const canMarkPrinted = <?= $canPrintQrLabels ? 'true' : 'false' ?>;

function buildQrLabelHtml(setName, labelCode, qrUrl) {
    const logoHtml = labelLogoUrl ? `<img class="qr-label-logo" src="${escapeHtml(labelLogoUrl)}" alt="Logo">` : '';
    return `<div class="qr-label-preview">
        <div class="qr-label-qr"><img src="${qrUrl}" alt="QR"></div>
        <div class="qr-label-text">
            <div class="qr-label-code">Code: ${escapeHtml(labelCode)}</div>
            ${logoHtml}
        </div>
    </div>`;
}

function getQrUrl(labelCode) {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(labelCode);
}

function refreshSelectedHistoryLabelCount() {
    const boxes = Array.from(document.querySelectorAll('.history-label-select'));
    const selected = boxes.filter((box) => box.checked).length;
    const countEl = document.getElementById('selectedHistoryLabelCount');
    if (countEl) countEl.textContent = selected.toString();

    const checkAll = document.getElementById('checkAllHistoryLabels');
    if (checkAll) {
        checkAll.checked = boxes.length > 0 && selected === boxes.length;
        checkAll.indeterminate = selected > 0 && selected < boxes.length;
    }
}

function setAllHistoryLabelsChecked(checked) {
    document.querySelectorAll('.history-label-select').forEach((box) => {
        box.checked = checked;
    });
    refreshSelectedHistoryLabelCount();
}

function viewQrLabel(setName, labelCode) {
    const qrUrl = getQrUrl(labelCode);
    const body = document.getElementById('qrLabelViewBody');
    const reprintBtn = document.getElementById('qrLabelViewReprintBtn');
    body.innerHTML = buildQrLabelHtml(setName, labelCode, qrUrl);
    reprintBtn.onclick = function() {
        reprintQrLabel(setName, labelCode);
    };
    new bootstrap.Modal(document.getElementById('qrLabelViewModal')).show();
}

function reprintQrLabel(setName, labelCode) {
    printQrLabels([{ setName, labelCode }]);
}

function printSelectedHistoryLabels() {
    const selected = Array.from(document.querySelectorAll('.history-label-select:checked')).map((box) => ({
        setName: box.dataset.setName || '',
        labelCode: box.dataset.labelCode || box.value
    }));

    if (selected.length === 0) {
        alert('Please select at least one QR label to reprint.');
        return;
    }

    printQrLabels(selected);
}

function printQrLabels(labels) {
    markHistoryLabelsPrinted(labels.map((label) => label.labelCode));
    const win = window.open('', '_blank', 'width=420,height=320');
    const labelHtml = labels.map((label) => buildQrLabelHtml(label.setName, label.labelCode, getQrUrl(label.labelCode))).join('');
    win.document.write(`<!doctype html>
<html>
<head>
    <title>Reprint QR Labels</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 0; margin: 0; }
        .qr-label-preview {
            width: 60mm;
            height: 40mm;
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
            overflow: hidden;
            box-sizing: border-box;
            position: relative;
            page-break-after: always;
            break-after: page;
        }
        .qr-label-preview::before {
            content: "";
            position: absolute;
            inset: 1.25mm;
            border: 0.55mm solid #000;
            border-radius: 3mm;
            pointer-events: none;
        }
        .qr-label-preview > * {
            position: relative;
            z-index: 1;
        }
        .qr-label-preview:last-child {
            page-break-after: auto;
            break-after: auto;
        }
        .qr-label-qr img { width: 24mm; height: 24mm; object-fit: contain; }
        .qr-label-text { display: contents; }
        .qr-label-title { color: #000; font-size: 8pt; font-weight: 800; line-height: 1.12; margin-bottom: 1.2mm; max-height: 10mm; overflow: hidden; overflow-wrap: anywhere; }
        .qr-label-code { color: #000; font-size: 6.7pt; font-weight: 700; line-height: 1.15; letter-spacing: 0; grid-column: 1 / -1; grid-row: 2; white-space: nowrap; font-family: monospace; overflow-wrap: anywhere; }
        .qr-label-logo { display: block; width: 24mm !important; height: 24mm !important; max-width: 24mm; max-height: 24mm; margin: 0; object-fit: contain; transform: scale(1.08); transform-origin: center; grid-column: 2; grid-row: 1; justify-self: center; align-self: center; }
        @media print {
            body { padding: 0; margin: 0; }
            @page { size: 60mm 40mm; margin: 0; }
        }
    </style>
</head>
<body>
    ${labelHtml}
    <script>
        window.onload = function() { window.print(); };
    <\/script>
</body>
</html>`);
    win.document.close();
}

function markHistoryLabelsPrinted(labelCodes) {
    if (!canMarkPrinted || !labelCodes.length) {
        return;
    }

    const payload = new FormData();
    payload.append('action', 'mark_printed');
    Array.from(new Set(labelCodes)).forEach((labelCode) => {
        payload.append('label_codes[]', labelCode);
    });

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: payload,
        keepalive: true
    }).then((response) => {
        if (!response.ok) return;
        labelCodes.forEach((labelCode) => {
            document.querySelectorAll(`[data-print-status-label="${CSS.escape(labelCode)}"]`).forEach((badge) => {
                badge.textContent = 'Printed';
                badge.className = 'badge rounded-pill bg-success';
            });
        });
    }).catch(() => {});
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function initHistoryLabelSelection() {
    document.querySelectorAll('.history-label-select').forEach((box) => {
        if (box.dataset.selectionBound === '1') return;
        box.dataset.selectionBound = '1';
        box.addEventListener('change', refreshSelectedHistoryLabelCount);
    });
    const checkAll = document.getElementById('checkAllHistoryLabels');
    if (checkAll && checkAll.dataset.selectionBound !== '1') {
        checkAll.dataset.selectionBound = '1';
        checkAll.addEventListener('change', () => setAllHistoryLabelsChecked(checkAll.checked));
    }
    refreshSelectedHistoryLabelCount();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initHistoryLabelSelection);
} else {
    initHistoryLabelSelection();
}
</script>

<style>
    .qr-label-preview {
        width: 60mm;
        height: 40mm;
        border: 0;
        border-radius: 0;
        padding: 4.2mm;
        display: grid;
        grid-template-columns: 24mm 24mm;
        grid-template-rows: 24mm auto;
        column-gap: 3.5mm;
        row-gap: 1.8mm;
        align-items: center;
        justify-content: center;
        text-align: center;
        overflow: hidden;
        box-sizing: border-box;
        background: #fff;
        color: #000;
        position: relative;
    }
    .qr-label-view-body {
        background: #f3f4f6;
        padding: 16px;
    }
    .qr-label-preview::before {
        content: "";
        position: absolute;
        inset: 1.25mm;
        border: 0.55mm solid #000;
        border-radius: 3mm;
        pointer-events: none;
    }
    .qr-label-preview > * {
        position: relative;
        z-index: 1;
    }
    .qr-label-qr img {
        width: 24mm;
        height: 24mm;
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
        overflow-wrap: anywhere;
    }
    .qr-label-code {
        color: #000;
        font-size: 7pt;
        font-weight: 700;
        line-height: 1.15;
        letter-spacing: 0;
        grid-column: 1 / -1;
        grid-row: 2;
        white-space: nowrap;
        font-family: monospace;
        overflow-wrap: anywhere;
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
</style>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
