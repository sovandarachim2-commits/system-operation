<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'return_report.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

// Handle date filtering
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$delivery_filter = $_GET['delivery_filter'] ?? '';

// Quick range helpers and month override
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekStart = date('Y-m-d', strtotime('-6 days'));
$monthStart30 = date('Y-m-d', strtotime('-29 days'));
$selected_month = $_GET['month'] ?? '';
if (preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $start_date = $selected_month . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));
}

$isToday = ($start_date === $today && $end_date === $today);
$isYesterday = ($start_date === $yesterday && $end_date === $yesterday);
$isWeek = ($start_date === $weekStart && $end_date === $today);
$isMonth30 = ($start_date === $monthStart30 && $end_date === $today);

// Build query with date filtering and optional delivery filtering
$query = "
    SELECT *
    FROM (
        SELECT
            CONCAT('scanner_', ri.id) AS row_id,
            ri.id,
            ri.inv,
            o.customer_name,
            ri.delivery_by,
            ri.reason,
            ri.username,
            ri.inv_photo,
            ri.full_photo,
            ri.date_time,
            o.phone,
            o.total_amount,
            o.status as order_status,
            'scanner' AS return_source,
            (
                SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.quantity) SEPARATOR '\n')
                FROM orders o2
                JOIN order_items oi ON oi.order_id = o2.id
                JOIN products p ON p.id = oi.product_id
                WHERE o2.order_code = ri.inv
            ) AS product_items,
            seller.name AS seller_name
        FROM return_items ri
        LEFT JOIN orders o ON ri.inv = o.order_code
        LEFT JOIN users seller ON seller.id = o.seller_id

        UNION ALL

        SELECT
            CONCAT('order_', o.id) AS row_id,
            NULL AS id,
            o.order_code AS inv,
            o.customer_name,
            COALESCE(outi.delivery_by, '') AS delivery_by,
            o.return_note AS reason,
            COALESCE(updater.name, '') AS username,
            '' AS inv_photo,
            '' AS full_photo,
            o.updated_at AS date_time,
            o.phone,
            o.total_amount,
            o.status as order_status,
            'order_management' AS return_source,
            (
                SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.quantity) SEPARATOR '\n')
                FROM order_items oi
                JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id = o.id
            ) AS product_items,
            seller.name AS seller_name
        FROM orders o
        LEFT JOIN (
            SELECT inv, MAX(delivery_by) AS delivery_by
            FROM out_items
            WHERE delivery_by IS NOT NULL
              AND delivery_by != ''
            GROUP BY inv
        ) outi ON outi.inv = o.order_code
        LEFT JOIN users updater ON updater.id = o.updated_by
        LEFT JOIN users seller ON seller.id = o.seller_id
        WHERE o.is_returned = 1
          AND NOT EXISTS (
              SELECT 1
              FROM return_items ri2
              WHERE ri2.inv = o.order_code
          )
    ) returns_combined
    WHERE DATE(date_time) BETWEEN ? AND ?
";

$params = [$start_date, $end_date];

if (!empty($delivery_filter)) {
    $query .= " AND delivery_by = ?";
    $params[] = $delivery_filter;
}

$query .= " ORDER BY date_time DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary stats
$total_returns = count($returns);
$total_value = array_sum(array_column($returns, 'total_amount'));

require_once __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1"><i class="bi bi-arrow-return-left me-2"></i>Return Report</h2>
                        <p class="text-muted mb-0">Track and manage returned items with user and date information</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary" onclick="exportToCSV()">
                            <i class="bi bi-download me-1"></i>Export CSV
                        </button>
                        <button class="btn btn-outline-secondary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>Print
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Date Filter -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Delivery Method</label>
                                <select name="delivery_filter" class="form-control" id="deliveryFilter">
                                    <option value="">All Delivery Methods</option>
                                    <option value="VET" <?= (($_GET['delivery_filter'] ?? '') === 'VET') ? 'selected' : '' ?>>VET</option>
                                    <option value="ក្រុមហ៊ុន" <?= (($_GET['delivery_filter'] ?? '') === 'ក្រុមហ៊ុន') ? 'selected' : '' ?>>ក្រុមហ៊ុន</option>
                                    <option value="J&T" <?= (($_GET['delivery_filter'] ?? '') === 'J&T') ? 'selected' : '' ?>>J&T</option>
                                    <option value="hou Express" <?= (($_GET['delivery_filter'] ?? '') === 'hou Express') ? 'selected' : '' ?>>hou Express</option>
                                    <option value="Jalat" <?= (($_GET['delivery_filter'] ?? '') === 'Jalat') ? 'selected' : '' ?>>Jalat</option>
                                    <option value="Banhjol" <?= (($_GET['delivery_filter'] ?? '') === 'Banhjol') ? 'selected' : '' ?>>Banhjol</option>
                                    <option value="Kjill Express" <?= (($_GET['delivery_filter'] ?? '') === 'Kjill Express') ? 'selected' : '' ?>>Kjill Express</option>
                                    <option value="ភ្លៀវមកយកដល់ហាង" <?= (($_GET['delivery_filter'] ?? '') === 'ភ្លៀវមកយកដល់ហាង') ? 'selected' : '' ?>>ភ្លៀវមកយកដល់ហាង</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search me-1"></i>Filter
                                </button>
                            </div>
                            <div class="col-md-2">
                                <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/return_report.php" class="btn btn-outline-secondary w-100">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                                </a>
                            </div>
                            <div class="col-12">
                                <?php $df = $delivery_filter !== '' ? '&delivery_filter=' . urlencode($delivery_filter) : ''; ?>
                                <div class="d-flex flex-wrap gap-2 align-items-end">
                                    <a class="btn <?= $isToday ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm" href="?start_date=<?= $today ?>&end_date=<?= $today ?><?= $df ?>">Today</a>
                                    <a class="btn <?= $isYesterday ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm" href="?start_date=<?= $yesterday ?>&end_date=<?= $yesterday ?><?= $df ?>">Yesterday</a>
                                    <a class="btn <?= $isWeek ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm" href="?start_date=<?= $weekStart ?>&end_date=<?= $today ?><?= $df ?>">Last 7 Days</a>
                                    <a class="btn <?= $isMonth30 ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm" href="?start_date=<?= $monthStart30 ?>&end_date=<?= $today ?><?= $df ?>">Last 30 Days</a>
                                    <div class="ms-2 d-flex align-items-center gap-2">
                                        <label class="form-label mb-0">Month</label>
                                        <input type="month" class="form-control form-control-sm" id="monthPicker" value="<?= htmlspecialchars($selected_month) ?>" style="max-width: 180px;">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="applyMonthFilter()">Apply</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-primary mb-1"><?= number_format($total_returns) ?></h3>
                        <p class="text-muted mb-0">Total Returns</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success mb-1">$<?= number_format($total_value, 2) ?></h3>
                        <p class="text-muted mb-0">Total Value</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-info mb-1"><?= htmlspecialchars($start_date) ?></h3>
                        <p class="text-muted mb-0">From Date</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-info mb-1"><?php echo !empty($delivery_filter) ? htmlspecialchars($delivery_filter) : 'All'; ?></h3>
                        <p class="text-muted mb-0">Delivery Filter</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Returns Table -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Return Details</h5>
                    </div>
                    <div class="card-body p-0">
                        <style>
                            /* Improve readability/alignment for multi-line product lists */
                            #returnsTable td { vertical-align: top; }
                            #returnsTable .product-list { margin: 0; padding-left: 1.25rem; list-style: disc; }
                            #returnsTable .product-list li { white-space: normal; word-break: break-word; line-height: 1.25rem; }
                        </style>

<script>
function applyMonthFilter() {
    var m = document.getElementById('monthPicker').value; // format YYYY-MM
    if (!m) return;
    var params = new URLSearchParams(window.location.search);
    params.set('month', m);
    params.delete('start_date');
    params.delete('end_date');
    window.location.search = params.toString();
}
</script>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="returnsTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>Barcode</th>
                                        <th>Customer</th>
                                        <th>Phone</th>
                                        <th>Product Items</th>
                                        <th>Delivery By</th>
                                        <th>Reason</th>
                                        <th>Order Value</th>
                                        <th>Seller</th>
                                        <th>Processed By</th>
                                        <th>Return Date</th>
                                        <th>Photos</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($returns)): ?>
                                        <tr>
                                            <td colspan="13" class="text-center py-4">
                                                <i class="bi bi-info-circle text-muted fs-1 mb-2"></i>
                                                <p class="text-muted mb-0">No returns found for the selected date range.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($returns as $index => $return): ?>
                                            <tr>
                                                <td><?= $index + 1 ?></td>
                                                <td>
                                                    <code class="bg-light px-2 py-1 rounded"><?= htmlspecialchars($return['inv']) ?></code>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($return['customer_name'] ?? '') !== '' ? htmlspecialchars($return['customer_name']) : '<span class="text-muted">-</span>' ?>
                                                </td>
                                                <td>
                                                    <?php if ($return['phone']): ?>
                                                        <a href="tel:<?= htmlspecialchars($return['phone']) ?>" class="text-decoration-none">
                                                            <?= htmlspecialchars($return['phone']) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php $itemsText = trim((string)($return['product_items'] ?? '')); ?>
                                                    <?php if ($itemsText !== ''): ?>
                                                        <ul class="mb-0 small product-list">
                                                            <?php foreach (explode("\n", $itemsText) as $line): $line = trim($line); if ($line==='') continue; ?>
                                                                <li><?= htmlspecialchars($line) ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $deliveryLabel = trim((string)($return['delivery_by'] ?? ''));
                                                    if ($deliveryLabel === '' && ($return['return_source'] ?? '') === 'order_management') {
                                                        $deliveryLabel = 'Not prepare items yet';
                                                    }
                                                    ?>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($deliveryLabel !== '' ? $deliveryLabel : '-') ?></span>
                                                </td>
                                                <td>
                                                    <?= nl2br(htmlspecialchars($return['reason'] ?? '')) ?>
                                                </td>
                                                <td>
                                                    <?php if ($return['total_amount']): ?>
                                                        <span class="text-success fw-bold">$<?= number_format($return['total_amount'], 2) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($return['seller_name'] ?? '') !== '' ? htmlspecialchars($return['seller_name']) : '<span class="text-muted">-</span>' ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <i class="bi bi-person-circle me-1"></i>
                                                        <span><?= htmlspecialchars($return['username']) ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <div class="fw-bold"><?= date('M j, Y', strtotime($return['date_time'])) ?></div>
                                                        <div class="text-muted"><?= date('g:i A', strtotime($return['date_time'])) ?></div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-1">
                                                        <?php if ($return['inv_photo']): ?>
                                                            <a href="<?= htmlspecialchars($BASE_URL . '/scanner/' . $return['inv_photo']) ?>"
                                                               target="_blank" class="btn btn-sm btn-outline-primary">
                                                                <i class="bi bi-receipt"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($return['full_photo']): ?>
                                                            <a href="<?= htmlspecialchars($BASE_URL . '/scanner/' . $return['full_photo']) ?>"
                                                               target="_blank" class="btn btn-sm btn-outline-success">
                                                                <i class="bi bi-image"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                                                data-bs-toggle="dropdown">
                                                            <i class="bi bi-three-dots"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li>
                                                                <a class="dropdown-item" href="#"
                                                                   onclick="viewDetails(<?= $return['id'] ?>)">
                                                                    <i class="bi bi-eye me-2"></i>View Details
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <a class="dropdown-item text-danger" href="#"
                                                                   onclick="deleteReturn(<?= $return['id'] ?>, '<?= htmlspecialchars($return['inv']) ?>')">
                                                                    <i class="bi bi-trash me-2"></i>Delete Return
                                                                </a>
                                                            </li>
                                                        </ul>
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
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Return</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the return for barcode <strong id="deleteBarcode"></strong>?</p>
                <p class="text-warning small">This will also reset the order status back to not returned.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDelete">Delete Return</button>
            </div>
        </div>
    </div>
</div>

<!-- Detail View Modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header">
                <h5 class="modal-title">Return Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-warning mb-3">Return Information</h6>
                        <table class="table table-dark table-sm">
                            <tr>
                                <td><strong>Return ID:</strong></td>
                                <td id="detail-id">-</td>
                            </tr>
                            <tr>
                                <td><strong>Barcode:</strong></td>
                                <td><code id="detail-barcode">-</code></td>
                            </tr>
                            <tr>
                                <td><strong>Delivery By:</strong></td>
                                <td><span class="badge bg-secondary" id="detail-delivery">-</span></td>
                            </tr>
                            <tr>
                                <td><strong>Reason:</strong></td>
                                <td id="detail-reason">-</td>
                            </tr>
                            <tr>
                                <td><strong>Processed By:</strong></td>
                                <td id="detail-username">-</td>
                            </tr>
                            <tr>
                                <td><strong>Return Date:</strong></td>
                                <td id="detail-date">-</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-warning mb-3">Order Information</h6>
                        <table class="table table-dark table-sm">
                            <tr>
                                <td><strong>Order Value:</strong></td>
                                <td id="detail-order-value">-</td>
                            </tr>
                            <tr>
                                <td><strong>Phone:</strong></td>
                                <td id="detail-phone">-</td>
                            </tr>
                            <tr>
                                <td><strong>Order Status:</strong></td>
                                <td id="detail-order-status">-</td>
                            </tr>
                        </table>

                        <h6 class="text-warning mb-3 mt-4">Photos</h6>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="text-center">
                                    <small class="text-muted d-block mb-1">Invoice Photo</small>
                                    <div id="detail-inv-photo" class="border rounded p-2 bg-secondary">
                                        <i class="bi bi-receipt text-muted fs-2"></i>
                                        <small class="text-muted d-block">No photo</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center">
                                    <small class="text-muted d-block mb-1">Full Photo</small>
                                    <div id="detail-full-photo" class="border rounded p-2 bg-secondary">
                                        <i class="bi bi-image text-muted fs-2"></i>
                                        <small class="text-muted d-block">No photo</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Delete return functionality
function deleteReturn(id, barcode) {
    document.getElementById('deleteBarcode').textContent = barcode;
    document.getElementById('confirmDelete').onclick = function() {
        fetch('scanner/delete_return_items.php', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'id=' + id
        })
        .then(response => response.json())
        .then(data => {
            if (data.result === 'success') {
                location.reload();
            } else {
                alert('Error deleting return: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            alert('Network error: ' + error);
        });
    };
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function viewDetails(id) {
    // Clear previous data
    document.getElementById('detail-id').textContent = '-';
    document.getElementById('detail-barcode').textContent = '-';
    document.getElementById('detail-delivery').textContent = '-';
    document.getElementById('detail-reason').textContent = '-';
    document.getElementById('detail-username').textContent = '-';
    document.getElementById('detail-date').textContent = '-';
    document.getElementById('detail-order-value').textContent = '-';
    document.getElementById('detail-phone').textContent = '-';
    document.getElementById('detail-order-status').textContent = '-';

    // Reset photo placeholders
    document.getElementById('detail-inv-photo').innerHTML = '<i class="bi bi-receipt text-muted fs-2"></i><small class="text-muted d-block">No photo</small>';
    document.getElementById('detail-full-photo').innerHTML = '<i class="bi bi-image text-muted fs-2"></i><small class="text-muted d-block">No photo</small>';

    // Show the modal first
    const modal = new bootstrap.Modal(document.getElementById('detailModal'));
    modal.show();

    // Fetch return details from API
    fetch(`get_return_details.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const returnData = data.data;

                // Populate return information
                document.getElementById('detail-id').textContent = returnData.id;
                document.getElementById('detail-barcode').textContent = returnData.inv;
                document.getElementById('detail-delivery').textContent = returnData.delivery_by;
                document.getElementById('detail-reason').textContent = returnData.reason || 'No reason provided';
                document.getElementById('detail-username').textContent = returnData.username;

                // Format date
                const returnDate = new Date(returnData.date_time);
                document.getElementById('detail-date').textContent = returnDate.toLocaleString();

                // Populate order information
                if (returnData.total_amount) {
                    document.getElementById('detail-order-value').textContent = '$' + parseFloat(returnData.total_amount).toFixed(2);
                } else {
                    document.getElementById('detail-order-value').textContent = 'Not available';
                }

                if (returnData.phone) {
                    document.getElementById('detail-phone').innerHTML = `<a href="tel:${returnData.phone}" class="text-decoration-none">${returnData.phone}</a>`;
                } else {
                    document.getElementById('detail-phone').textContent = 'Not available';
                }

                document.getElementById('detail-order-status').textContent = returnData.order_status || 'Unknown';

                // Handle photos
                if (returnData.inv_photo) {
                    const invPhotoUrl = '<?= $BASE_URL ?>/scanner/' + returnData.inv_photo;
                    document.getElementById('detail-inv-photo').innerHTML = `<img src="${invPhotoUrl}" class="img-fluid rounded" style="max-height: 150px;" alt="Invoice Photo">`;
                }

                if (returnData.full_photo) {
                    const fullPhotoUrl = '<?= $BASE_URL ?>/scanner/' + returnData.full_photo;
                    document.getElementById('detail-full-photo').innerHTML = `<img src="${fullPhotoUrl}" class="img-fluid rounded" style="max-height: 150px;" alt="Full Photo">`;
                }

            } else {
                document.getElementById('detail-reason').textContent = 'Error loading details: ' + (data.message || 'Unknown error');
            }
        })
        .catch(error => {
            document.getElementById('detail-reason').textContent = 'Network error: ' + error;
        });
}

function exportToCSV() {
    const table = document.getElementById('returnsTable');
    const rows = table.querySelectorAll('tr');

    let csv = [];
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length; j++) {
            let text = cols[j].textContent.trim();
            // Remove icons and format dates
            text = text.replace(/[\u{1F300}-\u{1F9FF}]/gu, ''); // Remove emojis
            text = text.replace(/\s+/g, ' '); // Normalize whitespace
            row.push('"' + text + '"');
        }
        csv.push(row.join(','));
    }

    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    if (link.download !== undefined) {
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `return_report_${new Date().toISOString().split('T')[0]}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
}
</script>

<style>
@media print {
    /* Hide unnecessary elements */
    .btn, .dropdown, .card-header, .admin-sidebar, .admin-topbar, .sidebar-overlay,
    .navbar, .modal, .alert, .breadcrumb {
        display: none !important;
    }

    /* Make table print-friendly */
    .table-responsive {
        overflow: visible !important;
    }

    .table {
        font-size: 10px !important;
        margin-bottom: 20px !important;
    }

    .table th, .table td {
        padding: 4px 6px !important;
        border: 1px solid #333 !important;
    }

    .table th {
        background-color: #f8f9fa !important;
        color: #000 !important;
        font-weight: bold !important;
    }

    /* Page setup */
    body {
        margin: 0 !important;
        padding: 10mm !important;
        background: white !important;
        color: black !important;
    }

    .container-fluid {
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    /* Report header */
    .print-header {
        display: block !important;
        text-align: center;
        border-bottom: 2px solid #333;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }

    .print-header h2 {
        font-size: 18px !important;
        margin-bottom: 5px !important;
    }

    .print-header p {
        font-size: 12px !important;
        margin: 0 !important;
    }

    /* Summary cards for print */
    .row.mb-4 .card {
        border: 1px solid #333 !important;
        margin-bottom: 10px !important;
        break-inside: avoid;
    }

    .row.mb-4 .card .card-body {
        padding: 8px !important;
    }

    .row.mb-4 .card h3 {
        font-size: 14px !important;
        margin-bottom: 2px !important;
    }

    .row.mb-4 .card p {
        font-size: 10px !important;
    }

    /* Table styling */
    .card-body.p-0 {
        border: 1px solid #333 !important;
    }

    /* Page breaks */
    .card {
        page-break-inside: avoid;
        margin-bottom: 20px !important;
    }

    .table {
        page-break-inside: auto;
    }

    .table tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }

    .table thead {
        display: table-header-group;
    }

    /* Footer with page numbers */
    @page {
        margin: 10mm;
        @bottom-right {
            content: "Page " counter(page) " of " counter(pages);
            font-size: 10px;
        }
    }

    /* Hide Photos and Actions columns when printing (now columns 12 and 13) */
    .table th:nth-child(12), .table td:nth-child(12),
    .table th:nth-child(13), .table td:nth-child(13) {
        display: none !important;
    }

    /* Adjust remaining columns for better print layout (final order) */
    .table th:nth-child(1), .table td:nth-child(1) { width: 4%; }  /* No */
    .table th:nth-child(2), .table td:nth-child(2) { width: 12%; } /* Barcode */
    .table th:nth-child(3), .table td:nth-child(3) { width: 12%; } /* Customer */
    .table th:nth-child(4), .table td:nth-child(4) { width: 12%; } /* Phone */
    .table th:nth-child(5), .table td:nth-child(5) { width: 22%; } /* Product Items */
    .table th:nth-child(6), .table td:nth-child(6) { width: 10%; } /* Delivery By */
    .table th:nth-child(7), .table td:nth-child(7) { width: 14%; } /* Reason */
    .table th:nth-child(8), .table td:nth-child(8) { width: 10%; } /* Order Value */
    .table th:nth-child(9), .table td:nth-child(9) { width: 10%; } /* Seller */
    .table th:nth-child(10), .table td:nth-child(10) { width: 10%; } /* Processed By */
    .table th:nth-child(11), .table td:nth-child(11) { width: 14%; } /* Return Date */

    /* Make badges more readable in print */
    .badge {
        background-color: transparent !important;
        color: #000 !important;
        border: 1px solid #333 !important;
        padding: 2px 6px !important;
    }

    /* Print-specific header */
    .print-only {
        display: block !important;
    }
}
</style>

<!-- Print Header (hidden on screen, shown when printing) -->
<div class="print-only print-header">
    <h2>Return Report</h2>
    <p>Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></p>
    <?php if (!empty($delivery_filter)): ?>
        <p>Filtered by Delivery Method: <?php echo htmlspecialchars($delivery_filter); ?></p>
    <?php endif; ?>
    <p>Period: <?php echo htmlspecialchars($start_date); ?> to <?php echo htmlspecialchars($end_date); ?></p>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
