<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'stock_operations.view');
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

$pdo = get_db_connection();
$currentUser = current_user();

function ensureDeliverySlipHistoryPageTable(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_delivery_slip_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slip_code VARCHAR(40) NOT NULL UNIQUE,
            receiver_name VARCHAR(255) NOT NULL,
            receiver_phone VARCHAR(80) NOT NULL,
            transfer_to VARCHAR(255) NULL,
            slip_title VARCHAR(120) NOT NULL,
            movement_type_label VARCHAR(120) NOT NULL,
            location_label VARCHAR(255) NOT NULL,
            filter_label VARCHAR(120) NULL,
            item_count INT NOT NULL DEFAULT 0,
            total_qty DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_in DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_out DECIMAL(15,2) NOT NULL DEFAULT 0,
            movement_ids TEXT NULL,
            items_json LONGTEXT NULL,
            qr_payload LONGTEXT NULL,
            created_by_user_id INT NULL,
            created_by_name VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created_at (created_at),
            INDEX idx_receiver_phone (receiver_phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

ensureDeliverySlipHistoryPageTable($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_delivery_slip_receiver') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $slipCode = trim((string)($_POST['slip_code'] ?? ''));
        $receiverName = trim((string)($_POST['receiver_name'] ?? ''));
        $receiverPhone = trim((string)($_POST['receiver_phone'] ?? ''));
        $transferTo = trim((string)($_POST['transfer_to'] ?? ''));
        $qrPayload = (string)($_POST['qr_payload'] ?? '');

        if ($slipCode === '') {
            throw new RuntimeException('Delivery slip code is required.');
        }
        if ($receiverName === '' || $receiverPhone === '') {
            throw new RuntimeException('Name and phone number are required.');
        }

        $stmt = $pdo->prepare("
            UPDATE stock_delivery_slip_history
            SET receiver_name = ?, receiver_phone = ?, transfer_to = ?, qr_payload = ?
            WHERE slip_code = ?
        ");
        $stmt->execute([
            $receiverName,
            $receiverPhone,
            $transferTo !== '' ? $transferTo : null,
            $qrPayload,
            $slipCode,
        ]);

        echo json_encode([
            'success' => true,
            'slip_code' => $slipCode,
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
}

$stockPrintLogo = get_default_logo($pdo);
$stockPrintLogoUrl = $stockPrintLogo && !empty($stockPrintLogo['file_path'])
    ? uploaded_file_url($stockPrintLogo['file_path'], 'logos')
    : rtrim($BASE_URL ?? '', '/') . '/public/image.png';

$deliverySlipHistoryStmt = $pdo->query("
    SELECT *
    FROM stock_delivery_slip_history
    ORDER BY created_at DESC, id DESC
    LIMIT 100
");
$deliverySlipHistory = $deliverySlipHistoryStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4 flex-grow-1">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h2 class="mb-1"><i class="bi bi-clock-history me-2"></i>Delivery Slip History</h2>
                <div class="text-muted">View, edit, and reprint stock transfer delivery slips.</div>
            </div>
            <a href="stock_operations.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Stock Operations
            </a>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Delivery Slips</h5>
                <span class="badge bg-light text-dark border"><?= count($deliverySlipHistory) ?> recent</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Slip Code</th>
                                <th>Receiver</th>
                                <th>Location</th>
                                <th>Type</th>
                                <th>Stock Location</th>
                                <th class="text-center">Items</th>
                                <th class="text-end">Qty</th>
                                <th>Created</th>
                                <th>Created By</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="stockDeliverySlipHistoryBody">
                            <?php if (empty($deliverySlipHistory)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">No delivery slip history yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($deliverySlipHistory as $slip): ?>
                                    <?php
                                    $slipItems = json_decode((string)($slip['items_json'] ?? '[]'), true);
                                    if (!is_array($slipItems)) {
                                        $slipItems = [];
                                    }
                                    $slipPayload = [
                                        'slip_code' => (string)($slip['slip_code'] ?? ''),
                                        'receiver_name' => (string)($slip['receiver_name'] ?? ''),
                                        'receiver_phone' => (string)($slip['receiver_phone'] ?? ''),
                                        'transfer_to' => (string)($slip['transfer_to'] ?? ''),
                                        'slip_title' => (string)($slip['slip_title'] ?? ''),
                                        'movement_type_label' => (string)($slip['movement_type_label'] ?? ''),
                                        'location_label' => (string)($slip['location_label'] ?? ''),
                                        'filter_label' => (string)($slip['filter_label'] ?? ''),
                                        'item_count' => (int)($slip['item_count'] ?? 0),
                                        'total_qty' => (float)($slip['total_qty'] ?? 0),
                                        'total_in' => (float)($slip['total_in'] ?? 0),
                                        'total_out' => (float)($slip['total_out'] ?? 0),
                                        'items' => $slipItems,
                                        'qr_payload' => (string)($slip['qr_payload'] ?? ''),
                                        'created_at' => (string)($slip['created_at'] ?? ''),
                                        'created_by_name' => (string)($slip['created_by_name'] ?? ''),
                                    ];
                                    $slipPayloadJson = htmlspecialchars(json_encode($slipPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars((string)$slip['slip_code']) ?></code></td>
                                        <td class="stock-slip-receiver-cell">
                                            <div class="fw-semibold stock-slip-receiver-name"><?= htmlspecialchars((string)$slip['receiver_name']) ?></div>
                                            <div class="text-muted small stock-slip-receiver-phone"><?= htmlspecialchars((string)$slip['receiver_phone']) ?></div>
                                        </td>
                                        <td class="stock-slip-transfer-cell"><?= htmlspecialchars((string)($slip['transfer_to'] ?? '-')) ?></td>
                                        <td><?= htmlspecialchars((string)$slip['movement_type_label']) ?></td>
                                        <td class="small"><?= htmlspecialchars((string)$slip['location_label']) ?></td>
                                        <td class="text-center"><?= number_format((int)$slip['item_count']) ?></td>
                                        <td class="text-end fw-semibold"><?= number_format((float)$slip['total_qty'], 2) ?></td>
                                        <td class="small"><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$slip['created_at']))) ?></td>
                                        <td><?= htmlspecialchars((string)($slip['created_by_name'] ?? '')) ?></td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm" role="group" aria-label="Delivery slip actions">
                                                <button type="button" class="btn btn-outline-primary stock-slip-view-btn" data-slip="<?= $slipPayloadJson ?>">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary stock-slip-edit-btn" data-slip="<?= $slipPayloadJson ?>">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-success stock-slip-reprint-btn" data-slip="<?= $slipPayloadJson ?>">
                                                    <i class="bi bi-printer"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="stockSlipHistoryModal" tabindex="-1" aria-labelledby="stockSlipHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="stockSlipHistoryModalLabel">
                        <i class="bi bi-receipt me-2"></i>Delivery Slip
                    </h5>
                    <div class="text-muted small" id="stockSlipHistorySubtitle"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="border rounded p-3 bg-light mb-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Receiver</div>
                            <div class="fw-semibold" id="stockSlipHistoryReceiver">-</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Phone</div>
                            <div class="fw-semibold" id="stockSlipHistoryPhone">-</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Location</div>
                            <div class="fw-semibold" id="stockSlipHistoryTransferTo">-</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Type</div>
                            <div class="fw-semibold" id="stockSlipHistoryType">-</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Stock Location</div>
                            <div class="fw-semibold" id="stockSlipHistoryLocation">-</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Created By</div>
                            <div class="fw-semibold" id="stockSlipHistoryCreatedBy">-</div>
                        </div>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <div class="border rounded p-2 text-center">
                            <div class="text-muted small">Items</div>
                            <div class="fw-bold" id="stockSlipHistoryItemCount">0</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-2 text-center">
                            <div class="text-muted small">Total In</div>
                            <div class="fw-bold text-success" id="stockSlipHistoryTotalIn">0</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-2 text-center">
                            <div class="text-muted small">Total Out</div>
                            <div class="fw-bold text-danger" id="stockSlipHistoryTotalOut">0</div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:52px;">No</th>
                                <th>Product</th>
                                <th class="text-center">Qty</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody id="stockSlipHistoryItems"></tbody>
                    </table>
                </div>
                <div>
                    <div class="text-muted small mb-1">QR Data</div>
                    <pre class="border rounded bg-light p-2 small mb-0" id="stockSlipHistoryQrPayload" style="white-space:pre-wrap;"></pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="stockSlipHistoryEditBtn">
                    <i class="bi bi-pencil-square me-1"></i>Edit
                </button>
                <button type="button" class="btn btn-success" id="stockSlipHistoryReprintBtn">
                    <i class="bi bi-printer me-1"></i>Reprint
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="stockPrintReceiverModal" tabindex="-1" aria-labelledby="stockPrintReceiverModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="stockPrintReceiverForm">
            <div class="modal-header">
                <h5 class="modal-title" id="stockPrintReceiverModalLabel">
                    <i class="bi bi-pencil-square me-2"></i>Edit Receiver
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="stockPrintReceiverName">Name Receive</label>
                    <input type="text" class="form-control form-control-lg" id="stockPrintReceiverName" autocomplete="name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="stockPrintReceiverPhone">Phone Number</label>
                    <input type="tel" class="form-control form-control-lg" id="stockPrintReceiverPhone" autocomplete="tel" required>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="stockPrintReceiverPlace">Location</label>
                    <input type="text" class="form-control form-control-lg" id="stockPrintReceiverPlace" placeholder="Branch, shop, warehouse, or customer place">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="stockPrintReceiverSubmitBtn">
                    <i class="bi bi-check2-circle me-1"></i>Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const stockPrintLogoUrl = <?= json_encode($stockPrintLogoUrl, JSON_UNESCAPED_SLASHES) ?>;
let stockSlipHistoryCurrent = null;
let stockPrintEditingSlip = null;

function stockPrintEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function stockPrintFormatQty(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '0';
    return n.toLocaleString('en-US', { maximumFractionDigits: 2 });
}

function stockSlipHistoryFormatDate(value) {
    if (!value) return '';
    const parsed = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return String(value);
    return parsed.toLocaleString();
}

function stockSlipNormalizeData(slip) {
    const data = slip || {};
    const items = Array.isArray(data.items) ? data.items : [];
    const rows = items.map((item) => ({
        movementId: Number(item.movement_id || 0),
        product: item.product || '',
        qty: item.qty || '0',
        qtyValue: Number(item.qty || 0),
        type: item.type || data.movement_type_label || '',
        location: item.location || data.location_label || '',
        date: item.date || '',
        reference: item.reference || ''
    }));
    const totalQty = Number(data.total_qty || rows.reduce((sum, row) => sum + (Number.isFinite(row.qtyValue) ? row.qtyValue : 0), 0));

    return {
        ...data,
        items,
        rows,
        receiver: {
            name: data.receiver_name || '',
            phone: data.receiver_phone || '',
            place: data.transfer_to || ''
        },
        title: data.slip_title || data.movement_type_label || 'TRANSFER SLIP',
        totalQty,
        slipCode: data.slip_code || '',
        createdAt: data.created_at || ''
    };
}

function stockSlipHistoryBuildQrPayload(data) {
    const slip = stockSlipNormalizeData(data);
    return [
        slip.title,
        'Slip: ' + slip.slipCode,
        slip.createdAt ? 'Date: ' + slip.createdAt : '',
        slip.location_label ? 'Stock Location: ' + slip.location_label : '',
        slip.movement_type_label ? 'Type: ' + slip.movement_type_label : '',
        'Name Receive: ' + slip.receiver.name,
        'Phone Number: ' + slip.receiver.phone,
        slip.receiver.place ? 'Location: ' + slip.receiver.place : '',
        'Items: ' + (slip.item_count || slip.rows.length),
        'In: ' + stockPrintFormatQty(slip.total_in || 0),
        'Out: ' + stockPrintFormatQty(slip.total_out || 0)
    ].filter(Boolean).join('\n');
}

function stockSlipHistoryOpen(slip) {
    const data = stockSlipNormalizeData(slip);
    stockSlipHistoryCurrent = data;
    document.getElementById('stockSlipHistoryModalLabel').innerHTML =
        '<i class="bi bi-receipt me-2"></i>Delivery Slip ' + stockPrintEscapeHtml(data.slip_code || '');
    document.getElementById('stockSlipHistorySubtitle').textContent = stockSlipHistoryFormatDate(data.created_at || '');
    document.getElementById('stockSlipHistoryReceiver').textContent = data.receiver_name || '-';
    document.getElementById('stockSlipHistoryPhone').textContent = data.receiver_phone || '-';
    document.getElementById('stockSlipHistoryTransferTo').textContent = data.transfer_to || '-';
    document.getElementById('stockSlipHistoryType').textContent = data.movement_type_label || '-';
    document.getElementById('stockSlipHistoryLocation').textContent = data.location_label || '-';
    document.getElementById('stockSlipHistoryCreatedBy').textContent = data.created_by_name || '-';
    document.getElementById('stockSlipHistoryItemCount').textContent = String(data.item_count || (data.items || []).length || 0);
    document.getElementById('stockSlipHistoryTotalIn').textContent = stockPrintFormatQty(data.total_in || 0);
    document.getElementById('stockSlipHistoryTotalOut').textContent = stockPrintFormatQty(data.total_out || 0);
    document.getElementById('stockSlipHistoryQrPayload').textContent = data.qr_payload || '';

    const itemsBody = document.getElementById('stockSlipHistoryItems');
    const items = Array.isArray(data.items) ? data.items : [];
    if (!items.length) {
        itemsBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No item detail saved.</td></tr>';
    } else {
        itemsBody.innerHTML = items.map((item, index) => `
            <tr>
                <td class="text-center">${index + 1}</td>
                <td>${stockPrintEscapeHtml(item.product || '')}</td>
                <td class="text-center">${stockPrintEscapeHtml(item.qty || '')}</td>
                <td>${stockPrintEscapeHtml(item.reference || '-')}</td>
            </tr>
        `).join('');
    }

    const modalEl = document.getElementById('stockSlipHistoryModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function stockSlipHistorySlipFromButton(button) {
    return stockSlipNormalizeData(JSON.parse(button.getAttribute('data-slip') || '{}'));
}

function stockPrintOpenReceiverModal(slip) {
    stockPrintEditingSlip = stockSlipNormalizeData(slip || {});
    const modalEl = document.getElementById('stockPrintReceiverModal');
    const nameInput = document.getElementById('stockPrintReceiverName');
    const phoneInput = document.getElementById('stockPrintReceiverPhone');
    const placeInput = document.getElementById('stockPrintReceiverPlace');
    if (!modalEl || typeof bootstrap === 'undefined') {
        alert('Unable to open receiver form. Please refresh the page and try again.');
        return;
    }

    if (nameInput) nameInput.value = stockPrintEditingSlip.receiver.name || '';
    if (phoneInput) phoneInput.value = stockPrintEditingSlip.receiver.phone || '';
    if (placeInput) placeInput.value = stockPrintEditingSlip.receiver.place || '';

    const detailModalEl = document.getElementById('stockSlipHistoryModal');
    if (detailModalEl) {
        bootstrap.Modal.getOrCreateInstance(detailModalEl).hide();
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalEl.addEventListener('shown.bs.modal', function focusNameOnce() {
        modalEl.removeEventListener('shown.bs.modal', focusNameOnce);
        if (nameInput) nameInput.focus();
    });
    modal.show();
}

function stockSlipHistoryUpdateSlip(updatedSlip) {
    const slip = stockSlipNormalizeData(updatedSlip);
    const slipCode = slip.slipCode || slip.slip_code || '';
    if (!slipCode) return;

    const storedSlip = {
        ...slip,
        slip_code: slipCode,
        receiver_name: slip.receiver.name,
        receiver_phone: slip.receiver.phone,
        transfer_to: slip.receiver.place,
        qr_payload: slip.qr_payload || stockSlipHistoryBuildQrPayload(slip)
    };
    const serialized = JSON.stringify(storedSlip);

    document.querySelectorAll('[data-slip]').forEach((button) => {
        try {
            const buttonSlip = JSON.parse(button.getAttribute('data-slip') || '{}');
            if ((buttonSlip.slip_code || '') !== slipCode) return;
            button.setAttribute('data-slip', serialized);
            const row = button.closest('tr');
            if (row) {
                const receiverName = row.querySelector('.stock-slip-receiver-name');
                const receiverPhone = row.querySelector('.stock-slip-receiver-phone');
                const transferCell = row.querySelector('.stock-slip-transfer-cell');
                if (receiverName) receiverName.textContent = slip.receiver.name || '-';
                if (receiverPhone) receiverPhone.textContent = slip.receiver.phone || '';
                if (transferCell) transferCell.textContent = slip.receiver.place || '-';
            }
        } catch (error) {}
    });

    stockSlipHistoryCurrent = storedSlip;
}

async function stockSlipHistorySaveReceiverEdit() {
    if (!stockPrintEditingSlip || !stockPrintEditingSlip.slipCode) {
        throw new Error('Delivery slip is not selected.');
    }

    const receiver = {
        name: document.getElementById('stockPrintReceiverName')?.value.trim() || '',
        phone: document.getElementById('stockPrintReceiverPhone')?.value.trim() || '',
        place: document.getElementById('stockPrintReceiverPlace')?.value.trim() || ''
    };
    const updatedSlip = stockSlipNormalizeData({
        ...stockPrintEditingSlip,
        receiver_name: receiver.name,
        receiver_phone: receiver.phone,
        transfer_to: receiver.place
    });
    updatedSlip.qr_payload = stockSlipHistoryBuildQrPayload(updatedSlip);

    const body = new URLSearchParams();
    body.set('action', 'update_delivery_slip_receiver');
    body.set('slip_code', updatedSlip.slipCode);
    body.set('receiver_name', receiver.name);
    body.set('receiver_phone', receiver.phone);
    body.set('transfer_to', receiver.place);
    body.set('qr_payload', updatedSlip.qr_payload);

    const response = await fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result || !result.success) {
        throw new Error((result && result.message) ? result.message : 'Unable to update delivery slip.');
    }

    stockSlipHistoryUpdateSlip(updatedSlip);
    stockSlipHistoryOpen(updatedSlip);
}

function stockPrintPrintHtml(printHtml) {
    try {
        const printIframe = document.createElement('iframe');
        printIframe.style.position = 'fixed';
        printIframe.style.right = '0';
        printIframe.style.bottom = '0';
        printIframe.style.width = '0';
        printIframe.style.height = '0';
        printIframe.style.border = '0';
        printIframe.style.visibility = 'hidden';
        document.body.appendChild(printIframe);
        const win = printIframe.contentWindow;
        const doc = win ? win.document : (printIframe.contentDocument || printIframe);
        doc.open();
        doc.write(printHtml);
        doc.close();
        if (win) {
            win.focus && win.focus();
            setTimeout(() => {
                try {
                    win.print();
                } catch (err) {
                    console.error('Print failed on iframe window:', err);
                }
                setTimeout(() => { try { document.body.removeChild(printIframe); } catch (e) {} }, 800);
            }, 200);
        } else {
            const fallbackWin = window.open('', '_blank');
            if (!fallbackWin) {
                alert('Please allow popup windows to print selected stock.');
                try { document.body.removeChild(printIframe); } catch (e) {}
                return;
            }
            fallbackWin.document.open();
            fallbackWin.document.write(printHtml);
            fallbackWin.document.close();
        }
    } catch (e) {
        console.error('Printing failed:', e);
        alert('Printing failed. Please try allowing popups or try again.');
    }
}

function stockPrintReceiptHtml(payload) {
    const rows = payload.rows || [];
    const receiver = payload.receiver || {};
    const title = payload.title || 'TRANSFER SLIP';
    const slipCode = payload.slipCode || '';
    const createdText = payload.createdText || new Date().toLocaleString();
    const totalQty = Number(payload.totalQty || 0);
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(slipCode);
    const itemRows = rows.map((row, index) => `
        <tr>
            <td class="tc">${index + 1}</td>
            <td>${stockPrintEscapeHtml(row.product)}</td>
            <td class="tc">${stockPrintEscapeHtml(row.qty)}</td>
        </tr>
    `).join('');

    return `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${stockPrintEscapeHtml(title)}</title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Khmer:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 10px; background: #e5e7eb; color: #000; font-family: Arial, "Noto Sans Khmer", sans-serif; }
        .slip { width: 80mm; max-width: 80mm; margin: 0 auto; background: #fff; padding: 4mm 3mm; border: 1px solid #e5e7eb; box-shadow: 0 18px 40px rgba(15,23,42,.18); }
        .logo { text-align: center; min-height: 24px; margin-bottom: 4px; }
        .logo img { max-width: 28mm; max-height: 18mm; object-fit: contain; }
        .title { text-align: center; font-size: 11px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 8px; }
        .kh { font-family: "Noto Sans Khmer", Arial, sans-serif; }
        .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 3mm; }
        .meta { flex: 1; min-width: 0; font-size: 16px; line-height: 1.45; font-weight: 600; overflow-wrap: anywhere; }
        .meta strong { font-weight: 900; }
        .qr { width: 20mm; height: 20mm; object-fit: contain; flex: 0 0 20mm; }
        .qr-wrap { flex: 0 0 20mm; text-align: center; }
        .qr-text { margin-top: 2px; font-size: 7px; font-weight: 800; line-height: 1.15; overflow-wrap: anywhere; }
        .divider { border-top: 1px solid #000; margin: 8px 0 6px; }
        table { width: 100%; border-collapse: collapse; font-family: "Noto Sans Khmer", Arial, sans-serif; }
        th, td { border: 1px solid #000; padding: 4px 3px; font-size: 10px; vertical-align: middle; line-height: 1.25; overflow-wrap: anywhere; }
        th { background: #d1d5db; text-align: center; font-weight: 800; line-height: 1.2; }
        th span { display: block; font-size: 8px; font-weight: 700; }
        .tc { text-align: center; }
        tfoot td { font-weight: 900; background: #fff; }
        .total-label { text-align: right; }
        .total-value { text-align: center; font-size: 12px; }
        .footer { border-top: 1px solid #000; margin-top: 7px; padding-top: 7px; font-size: 9px; font-weight: 700; line-height: 1.45; overflow-wrap: anywhere; }
        @media print {
            @page { size: 80mm auto; margin: 0; }
            html, body { width: 80mm; margin: 0; padding: 0; background: #fff; }
            .slip { width: 80mm; max-width: 80mm; margin: 0; box-shadow: none; border: 0; }
        }
    </style>
</head>
<body>
    <div class="slip">
        <div class="logo">${stockPrintLogoUrl ? `<img src="${stockPrintEscapeHtml(stockPrintLogoUrl)}" alt="Logo">` : ''}</div>
        <div class="title kh">&#x1794;&#x17D0;&#x178E;&#x17D2;&#x178E;&#x179F;&#x17D2;&#x178F;&#x17BB;&#x1780;</div>
        <div class="top">
            <div class="meta">
                <div><span class="kh">&#x17A2;&#x17D2;&#x1793;&#x1780;&#x1791;&#x1791;&#x17BD;&#x179B;</span>: <strong>${stockPrintEscapeHtml(receiver.name)}</strong></div>
                <div><span class="kh">&#x179B;&#x17C1;&#x1781;&#x1791;&#x17BC;&#x179A;&#x179F;&#x17D0;&#x1796;&#x17D2;&#x1791;</span>: <strong>${stockPrintEscapeHtml(receiver.phone)}</strong></div>
                ${receiver.place ? `<div><span class="kh">&#x1791;&#x17B8;&#x178F;&#x17B6;&#x17C6;&#x1784;</span>: <strong>${stockPrintEscapeHtml(receiver.place)}</strong></div>` : ''}
            </div>
            <div class="qr-wrap">
                <img class="qr" src="${qrUrl}" alt="QR">
                <div class="qr-text">${stockPrintEscapeHtml(slipCode)}</div>
            </div>
        </div>
        <div class="divider"></div>
        <table>
            <thead>
                <tr>
                    <th style="width:12%;">&#x179B;.&#x179A;<span>No</span></th>
                    <th style="width:62%;">&#x1798;&#x17BB;&#x1781;&#x1791;&#x17C6;&#x1793;&#x17B7;&#x1789;<span>Product Name</span></th>
                    <th style="width:26%;">&#x1785;&#x17C6;&#x1793;&#x17BD;&#x1793;<span>Qty</span></th>
                </tr>
            </thead>
            <tbody>${itemRows}</tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="total-label kh">&#x179F;&#x179A;&#x17BB;&#x1794;&#x1785;&#x17C6;&#x1793;&#x17BD;&#x1793;</td>
                    <td class="total-value">${stockPrintFormatQty(totalQty)}</td>
                </tr>
            </tfoot>
        </table>
        <div class="footer">
            <div>Created: ${stockPrintEscapeHtml(createdText)}</div>
            <div>Powered by : One Night Solution</div>
        </div>
    </div>
</body>
</html>`;
}

function stockPrintReprintHistorySlip(slip) {
    const data = stockSlipNormalizeData(slip);
    if (!data.slipCode) {
        alert('Delivery slip code is missing.');
        return;
    }
    const createdAt = data.createdAt ? new Date(String(data.createdAt).replace(' ', 'T')) : null;
    const createdText = createdAt && !Number.isNaN(createdAt.getTime()) ? createdAt.toLocaleString() : (data.createdAt || new Date().toLocaleString());
    stockPrintPrintHtml(stockPrintReceiptHtml({
        rows: data.rows,
        receiver: data.receiver,
        title: data.title,
        totalQty: data.totalQty,
        slipCode: data.slipCode,
        createdText
    }));
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.stock-slip-view-btn').forEach((button) => {
        button.addEventListener('click', function() {
            try {
                stockSlipHistoryOpen(stockSlipHistorySlipFromButton(button));
            } catch (error) {
                alert('Unable to open delivery slip detail.');
            }
        });
    });
    document.querySelectorAll('.stock-slip-edit-btn').forEach((button) => {
        button.addEventListener('click', function() {
            try {
                stockPrintOpenReceiverModal(stockSlipHistorySlipFromButton(button));
            } catch (error) {
                alert('Unable to edit delivery slip.');
            }
        });
    });
    document.querySelectorAll('.stock-slip-reprint-btn').forEach((button) => {
        button.addEventListener('click', function() {
            try {
                stockPrintReprintHistorySlip(stockSlipHistorySlipFromButton(button));
            } catch (error) {
                alert('Unable to reprint delivery slip.');
            }
        });
    });

    const historyEditBtn = document.getElementById('stockSlipHistoryEditBtn');
    const historyReprintBtn = document.getElementById('stockSlipHistoryReprintBtn');
    if (historyEditBtn) {
        historyEditBtn.addEventListener('click', function() {
            if (stockSlipHistoryCurrent) {
                stockPrintOpenReceiverModal(stockSlipHistoryCurrent);
            }
        });
    }
    if (historyReprintBtn) {
        historyReprintBtn.addEventListener('click', function() {
            if (stockSlipHistoryCurrent) {
                stockPrintReprintHistorySlip(stockSlipHistoryCurrent);
            }
        });
    }

    const receiverForm = document.getElementById('stockPrintReceiverForm');
    if (receiverForm) {
        receiverForm.addEventListener('submit', async function(event) {
            event.preventDefault();
            if (!receiverForm.checkValidity()) {
                receiverForm.reportValidity();
                return;
            }
            const submitBtn = document.getElementById('stockPrintReceiverSubmitBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Saving...';
            }
            const modalEl = document.getElementById('stockPrintReceiverModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }
            try {
                await stockSlipHistorySaveReceiverEdit();
            } catch (error) {
                alert(error.message || 'Unable to save delivery slip.');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Save Changes';
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
