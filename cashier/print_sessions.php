<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['cashier', 'admin'], 'print_history.view');
require_once __DIR__ . '/../db.php';

$pdo  = get_db_connection();
$user = current_user();

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$cashier_id = $_GET['cashier_id'] ?? '';
$selected_printed_at = $_GET['printed_at'] ?? '';
$selected_cashier_id = $_GET['selected_cashier_id'] ?? '';
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

if ($isAjax) {
    $detailParams = [];
    $detailWhere = 'pj.printed_at = ?';
    $detailParams[] = $selected_printed_at;

    if ($selected_cashier_id !== '') {
        $detailWhere .= ' AND pj.cashier_id = ?';
        $detailParams[] = (int)$selected_cashier_id;
    }

    // Fetch all order items for the session
    $orderIdsStmt = $pdo->prepare("SELECT DISTINCT pj.order_id FROM print_jobs pj WHERE $detailWhere");
    $orderIdsStmt->execute($detailParams);
    $orderIds = array_column($orderIdsStmt->fetchAll(PDO::FETCH_ASSOC), 'order_id');

    $productItems = [];
    $setComponents = [];
    if ($orderIds) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $itemsStmt = $pdo->prepare("
            SELECT oi.product_id, oi.quantity, p.name AS product_name, COALESCE(p.product_type, 'normal') AS product_type, ps.id AS product_set_id
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            LEFT JOIN product_sets ps ON p.name = ps.set_name AND COALESCE(p.product_type, 'normal') = 'set'
            WHERE oi.order_id IN ($placeholders)
        ");
        $itemsStmt->execute($orderIds);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            if ($item['product_type'] === 'set' && $item['product_set_id']) {
                // Expand set into its components
                $componentsStmt = $pdo->prepare("
                    SELECT psi.product_id, psi.quantity, p.name AS component_name
                    FROM product_set_items psi
                    JOIN products p ON psi.product_id = p.id
                    WHERE psi.product_set_id = ?
                ");
                $componentsStmt->execute([$item['product_set_id']]);
                $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($components as $comp) {
                    $key = $comp['product_id'];
                    if (!isset($setComponents[$key])) {
                        $setComponents[$key] = [
                            'product_id' => $comp['product_id'],
                            'product_name' => $comp['component_name'],
                            'quantity' => 0
                        ];
                    }
                    $setComponents[$key]['quantity'] += $comp['quantity'] * $item['quantity'];
                }
            } else {
                $key = $item['product_id'];
                if (!isset($productItems[$key])) {
                    $productItems[$key] = [
                        'product_id' => $item['product_id'],
                        'product_name' => $item['product_name'],
                        'quantity' => 0
                    ];
                }
                $productItems[$key]['quantity'] += $item['quantity'];
            }
        }
    }

    // Merge set components into product items
    foreach ($setComponents as $key => $comp) {
        if (!isset($productItems[$key])) {
            $productItems[$key] = $comp;
        } else {
            $productItems[$key]['quantity'] += $comp['quantity'];
        }
    }

    // Prepare normal product table data
    $productItems = array_values($productItems);

    // Original session details (grouped by product, including sets as a single row)
    $detailQuery = "SELECT p.name AS product_name,
                           SUM(oi.quantity) AS total_quantity,
                           COUNT(DISTINCT pj.order_id) AS order_count
                    FROM print_jobs pj
                    JOIN order_items oi ON oi.order_id = pj.order_id
                    JOIN products p ON p.id = oi.product_id
                    WHERE $detailWhere
                    GROUP BY p.id, p.name
                    ORDER BY total_quantity DESC, p.name ASC";
    $detailStmt = $pdo->prepare($detailQuery);
    $detailStmt->execute($detailParams);
    $sessionDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

    $cashierName = '';
    if ($selected_cashier_id !== '') {
        $stmtCashier = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
        $stmtCashier->execute([(int)$selected_cashier_id]);
        $cashierName = $stmtCashier->fetchColumn() ?: '';
    }

    header('Content-Type: application/json');
    echo json_encode([
        'printed_at' => $selected_printed_at,
        'cashier_name' => $cashierName,
        'details' => $sessionDetails,
        'product_items' => $productItems,
    ]);
    exit;
}

$params = [];
$whereClauses = ['pj.printed_at IS NOT NULL'];

if ($start_date !== '') {
    $whereClauses[] = 'DATE(pj.printed_at) >= ?';
    $params[] = $start_date;
}
if ($end_date !== '') {
    $whereClauses[] = 'DATE(pj.printed_at) <= ?';
    $params[] = $end_date;
}
if ($cashier_id !== '') {
    $whereClauses[] = 'pj.cashier_id = ?';
    $params[] = (int)$cashier_id;
}

$whereSql = implode(' AND ', $whereClauses);

$query = "SELECT pj.printed_at,
                 pj.cashier_id,
                 u.name AS cashier_name,
                 COUNT(DISTINCT pj.order_id) AS orders_count,
                 SUM(oi.quantity) AS items_count
          FROM print_jobs pj
          JOIN users u ON u.id = pj.cashier_id
          JOIN order_items oi ON oi.order_id = pj.order_id
          WHERE $whereSql
          GROUP BY pj.printed_at, pj.cashier_id, u.name
          ORDER BY pj.printed_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$sessions = $stmt->fetchAll();

$cashiers = $pdo->query('SELECT DISTINCT u.id, u.name FROM users u JOIN print_jobs pj ON pj.cashier_id = u.id ORDER BY u.name')->fetchAll(PDO::FETCH_ASSOC);

$sessionDetails = [];
$detailsHeader = null;

if ($selected_printed_at !== '') {
    $detailParams = [$selected_printed_at];
    $detailWhere = 'pj.printed_at = ?';

    if ($selected_cashier_id !== '') {
        $detailWhere .= ' AND pj.cashier_id = ?';
        $detailParams[] = (int)$selected_cashier_id;
    }

    $detailQuery = "SELECT p.name AS product_name,
                           SUM(oi.quantity) AS total_quantity,
                           COUNT(DISTINCT pj.order_id) AS order_count
                    FROM print_jobs pj
                    JOIN order_items oi ON oi.order_id = pj.order_id
                    JOIN products p ON p.id = oi.product_id
                    WHERE $detailWhere
                    GROUP BY p.id, p.name
                    ORDER BY total_quantity DESC, p.name ASC";

    $detailStmt = $pdo->prepare($detailQuery);
    $detailStmt->execute($detailParams);
    $sessionDetails = $detailStmt->fetchAll();

    $detailsHeader = [
        'printed_at' => $selected_printed_at,
        'cashier_name' => '',
    ];
    if ($selected_cashier_id !== '') {
        $stmtCashier = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
        $stmtCashier->execute([(int)$selected_cashier_id]);
        $detailsHeader['cashier_name'] = $stmtCashier->fetchColumn() ?: '';
    }
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Print Sessions</h1>
        <div class="small text-muted">Each session groups orders printed at the same time.</div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cashier</label>
                    <select name="cashier_id" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($cashiers as $cashier): ?>
                            <option value="<?= (int)$cashier['id'] ?>" <?= $cashier_id == $cashier['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cashier['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-light">Session Summary</div>
                <div class="card-body p-0">
                    <div class="table-responsive table-responsive-full">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>No</th>
                                    <th>Printed At</th>
                                    <th>Cashier</th>
                                    <th>Orders</th>
                                    <th>Items</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$sessions): ?>
                                    <tr><td colspan="6" class="text-center py-4">No print sessions found.</td></tr>
                                <?php else: ?>
                                    <?php $no = 1; foreach ($sessions as $session): ?>
                                        <tr>
                                            <td><?= $no++; ?></td>
                                            <td><?= htmlspecialchars($session['printed_at']) ?></td>
                                            <td><?= htmlspecialchars($session['cashier_name']) ?></td>
                                            <td><?= (int)$session['orders_count'] ?></td>
                                            <td><?= (int)$session['items_count'] ?></td>
                                            <td>
                                                <button type="button" class="btn btn-outline-primary btn-sm view-session-details"
                                                    data-printed-at="<?= htmlspecialchars($session['printed_at']) ?>"
                                                    data-cashier-id="<?= (int)$session['cashier_id'] ?>"
                                                    data-cashier-name="<?= htmlspecialchars($session['cashier_name']) ?>"
                                                    data-start-date="<?= htmlspecialchars($start_date) ?>"
                                                    data-end-date="<?= htmlspecialchars($end_date) ?>"
                                                    >
                                                    View Products
                                                </button>
                                                <a href="print_session_report.php?printed_at=<?= urlencode($session['printed_at']) ?>&cashier_id=<?= (int)$session['cashier_id'] ?>" target="_blank" class="btn btn-outline-success btn-sm ms-2 print-session">
                                                    Print
                                                </a>
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

        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-light">Session Info</div>
                <div class="card-body">
                    <p class="mb-2"><strong>Total Sessions</strong></p>
                    <h3 class="display-6"><?= count($sessions) ?></h3>
                    <?php if ($selected_printed_at): ?>
                        <p class="mt-3 mb-1 text-muted">Selected session</p>
                        <p class="mb-1"><strong>Printed At:</strong> <?= htmlspecialchars($selected_printed_at) ?></p>
                        <?php if (!empty($detailsHeader['cashier_name'])): ?>
                            <p class="mb-0"><strong>Cashier:</strong> <?= htmlspecialchars($detailsHeader['cashier_name']) ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="mt-3 text-muted">Select a session to see products printed in that batch.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Print Session Details Popup -->
<div class="modal fade" id="sessionDetailsModal" tabindex="-1" aria-labelledby="sessionDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <div>
                    <h5 class="modal-title" id="sessionDetailsModalLabel"><i class="bi bi-box-seam me-2"></i>Printed Products</h5>
                    <div class="small">Review the batch details before closing.</div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="bg-light p-4 border-bottom">
                    <div class="row gy-2">
                        <div class="col-md-6">
                            <div class="text-uppercase text-muted small mb-1">Printed At</div>
                            <div id="sessionModalPrintedAt" class="fw-semibold text-dark"></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-uppercase text-muted small mb-1">Cashier</div>
                            <div id="sessionModalCashierName" class="fw-semibold text-dark"></div>
                        </div>
                    </div>
                </div>
                <div class="p-4">
                    <div class="table-responsive mb-3">
                        <table class="table table-bordered mb-0">
                            <thead>
                                <tr class="text-uppercase text-muted small">
                                    <th style="width: 60px;">No</th>
                                    <th>Product Name</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Orders</th>
                                </tr>
                            </thead>
                            <tbody id="sessionModalProductsBody">
                                <tr><td colspan="4" class="text-center py-4">Loading session details...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead>
                                <tr class="text-uppercase text-muted small">
                                    <th style="width: 60px;">No</th>
                                    <th>Product Name (Set converted)</th>
                                    <th class="text-end">Total Quantity</th>
                                </tr>
                            </thead>
                            <tbody id="sessionModalProductItemsBody">
                                <tr><td colspan="3" class="text-center py-4">Loading product items...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="row gx-3 mt-3 border-top pt-3">
                        <div class="col-md-6">
                            <p class="mb-1 text-uppercase text-muted small">Total quantity</p>
                            <p id="sessionModalTotalQuantity" class="h5 mb-0">0</p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-1 text-uppercase text-muted small">Total orders</p>
                            <p id="sessionModalTotalOrders" class="h5 mb-0">0</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-top-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const sessionModalEl = document.getElementById('sessionDetailsModal');
    let sessionModal = null;
    const printedAtEl = document.getElementById('sessionModalPrintedAt');
    const cashierNameEl = document.getElementById('sessionModalCashierName');
    const productsBody = document.getElementById('sessionModalProductsBody');
    const totalQuantityEl = document.getElementById('sessionModalTotalQuantity');
    const totalOrdersEl = document.getElementById('sessionModalTotalOrders');

    function getSessionModal() {
        if (sessionModal) return sessionModal;
        if (!sessionModalEl) return null;
        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return null;
        }
        sessionModal = new bootstrap.Modal(sessionModalEl);
        return sessionModal;
    }

    function setLoading() {
        if (!productsBody) return;
        productsBody.innerHTML = '<tr><td colspan="4" class="text-center py-4">Loading session details...</td></tr>';
        if (totalQuantityEl) totalQuantityEl.textContent = '0';
        if (totalOrdersEl) totalOrdersEl.textContent = '0';
    }

    function setError(message) {
        if (!productsBody) return;
        productsBody.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-4">${message}</td></tr>`;
        if (totalQuantityEl) totalQuantityEl.textContent = '0';
        if (totalOrdersEl) totalOrdersEl.textContent = '0';
    }

    function renderProducts(details, productItems) {
        if (!productsBody) return;
        if (!details || !details.length) {
            productsBody.innerHTML = '<tr><td colspan="4" class="text-center py-4">No product details available for this session.</td></tr>';
            if (totalQuantityEl) totalQuantityEl.textContent = '0';
            if (totalOrdersEl) totalOrdersEl.textContent = '0';
        } else {
            let quantitySum = 0;
            let ordersSum = 0;
            productsBody.innerHTML = details.map((item, index) => {
                const qty = parseFloat(item.total_quantity) || 0;
                const orders = parseInt(item.order_count, 10) || 0;
                quantitySum += qty;
                ordersSum += orders;
                return `
                    <tr class="${index % 2 === 0 ? 'bg-white' : 'bg-light'}">
                        <td class="py-3 text-center fw-semibold">${index + 1}</td>
                        <td class="py-3">${item.product_name || ''}</td>
                        <td class="py-3 text-end fw-semibold">${qty}</td>
                        <td class="py-3 text-end">${orders}</td>
                    </tr>
                `;
            }).join('');
            if (totalQuantityEl) totalQuantityEl.textContent = quantitySum;
            if (totalOrdersEl) totalOrdersEl.textContent = ordersSum;
        }
        // Render product items (set converted)
        const productItemsBody = document.getElementById('sessionModalProductItemsBody');
        if (!productItemsBody) return;
        if (!productItems || !productItems.length) {
            productItemsBody.innerHTML = '<tr><td colspan="3" class="text-center py-4">No product items found.</td></tr>';
        } else {
            productItemsBody.innerHTML = productItems.map((item, idx) => `
                <tr class="${idx % 2 === 0 ? 'bg-white' : 'bg-light'}">
                    <td class="py-3 text-center fw-semibold">${idx + 1}</td>
                    <td class="py-3">${item.product_name || ''}</td>
                    <td class="py-3 text-end fw-semibold">${item.quantity}</td>
                </tr>
            `).join('');
        }
    }

    document.querySelectorAll('.view-session-details').forEach(button => {
        button.addEventListener('click', function() {
            const printedAt = this.dataset.printedAt || '';
            const cashierId = this.dataset.cashierId || '';
            const cashierName = this.dataset.cashierName || 'Unknown';

            if (!printedAt) return;
            if (printedAtEl) printedAtEl.textContent = printedAt;
            if (cashierNameEl) cashierNameEl.textContent = cashierName;

            setLoading();
            const modal = getSessionModal();
            if (modal) modal.show();

            const params = new URLSearchParams({
                ajax: '1',
                printed_at: printedAt,
                selected_cashier_id: cashierId
            });

            fetch('print_sessions.php?' + params.toString(), {
                credentials: 'same-origin'
            })
                .then(res => res.ok ? res.json() : Promise.reject(new Error('Unable to load details')))
                .then(data => {
                    renderProducts(data.details || [], data.product_items || []);
                    if (cashierNameEl && data.cashier_name) {
                        cashierNameEl.textContent = data.cashier_name;
                    }
                })
                .catch(err => {
                    setError('Could not load session details.');
                    console.error(err);
                });
        });
    });
})();
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
