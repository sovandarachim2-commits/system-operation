<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'order_filter.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'mark_printed') {
        $orderIds = array_filter(array_map('intval', explode(',', $_POST['order_ids'] ?? '')));
        
        if (!empty($orderIds)) {
            $user = current_user();
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            
            // Insert print jobs for selected orders
            $stmt = $pdo->prepare("INSERT IGNORE INTO print_jobs (order_id, cashier_id) VALUES (?, ?)");
            foreach ($orderIds as $orderId) {
                $stmt->execute([$orderId, $user['id']]);
            }
            
            echo json_encode(['success' => true, 'count' => count($orderIds)]);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// Handle Excel export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $orderIds = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
    
    if (!empty($orderIds)) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        
        $sql = "SELECT o.order_code, o.customer_name, o.phone, o.location, o.status, o.total_amount, 
                       o.created_at, u.name AS seller_name, p.name AS page_name,
                       dt.name AS delivery_type_name, dc.label AS delivery_cost_label,
                       CASE WHEN pj.id IS NOT NULL THEN 1 ELSE 0 END AS is_printed
                FROM orders o
                JOIN users u ON o.seller_id = u.id
                LEFT JOIN pages p ON o.page_id = p.id
                LEFT JOIN delivery_types dt ON o.delivery_type_id = dt.id
                LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
                LEFT JOIN print_jobs pj ON pj.order_id = o.id
                WHERE o.id IN ($placeholders)
                ORDER BY o.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($orderIds);
        $orders = $stmt->fetchAll();
        
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="orders_export_' . date('Y-m-d') . '.xls"');
        header('Cache-Control: max-age=0');
        
        echo "<table border='1'>";
        echo "<tr>
                <th>Order Code</th>
                <th>Customer Name</th>
                <th>Phone</th>
                <th>Location</th>
                <th>Seller</th>
                <th>Page</th>
                <th>Delivery Type</th>
                <th>Delivery Cost</th>
                <th>Status</th>
                <th>Total Amount</th>
                <th>Printed</th>
                <th>Created Date</th>
              </tr>";
        
        foreach ($orders as $order) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($order['order_code']) . "</td>";
            echo "<td>" . htmlspecialchars($order['customer_name']) . "</td>";
            echo "<td>" . htmlspecialchars($order['phone'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($order['location'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($order['seller_name']) . "</td>";
            echo "<td>" . htmlspecialchars($order['page_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($order['delivery_type_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($order['delivery_cost_label'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($order['status']) . "</td>";
            echo "<td>$" . number_format($order['total_amount'], 2) . "</td>";
            echo "<td>" . ($order['is_printed'] ? 'Yes' : 'No') . "</td>";
            echo "<td>" . htmlspecialchars($order['created_at']) . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        exit;
    }
}

// Get filter values
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$printed = $_GET['printed'] ?? '';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$scan_from_date = $_GET['scan_from_date'] ?? '';
$scan_to_date = $_GET['scan_to_date'] ?? '';
$seller_id = (int)($_GET['seller_id'] ?? 0);
$delivery_type = (int)($_GET['delivery_type'] ?? 0);
$delivered_by = $_GET['delivered_by'] ?? '';
$limit = (int)($_GET['limit'] ?? 100);
$order_status = $_GET['order_status'] ?? '';

// Build base query
$sql = "SELECT o.*, u.name AS seller_name, p.name AS page_name, 
               dt.name AS delivery_type_name, dc.label AS delivery_cost_label,
               CASE WHEN pj.id IS NOT NULL THEN 1 ELSE 0 END AS is_printed,
               oi.delivery_by as out_item_delivery_by,
               oi.date_time as scan_date_time,
               IFNULL(o.is_cancelled, 0) as is_cancelled
        FROM orders o
        JOIN users u ON o.seller_id = u.id
        LEFT JOIN pages p ON o.page_id = p.id
        LEFT JOIN delivery_types dt ON o.delivery_type_id = dt.id
        LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
        LEFT JOIN print_jobs pj ON pj.order_id = o.id
        LEFT JOIN out_items oi ON oi.inv = o.order_code
        WHERE 1=1";

$params = [];

// Unified search across multiple fields
if ($search !== '') {
    $sql .= " AND (o.order_code LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ? OR o.location LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}

if ($status !== '') {
    $sql .= " AND o.status = ?";
    $params[] = $status;
}

if ($printed !== '') {
    if ($printed === 'printed') {
        $sql .= " AND pj.id IS NOT NULL";
    } elseif ($printed === 'unprinted') {
        $sql .= " AND pj.id IS NULL";
    }
}

if ($delivery_type > 0) {
    $sql .= " AND o.delivery_type_id = ?";
    $params[] = $delivery_type;
}

if ($delivered_by !== '') {
    $sql .= " AND oi.delivery_by = ?";
    $params[] = $delivered_by;
}

if ($from_date !== '') {
    $sql .= " AND DATE(o.created_at) >= ?";
    $params[] = $from_date;
}

if ($to_date !== '') {
    $sql .= " AND DATE(o.created_at) <= ?";
    $params[] = $to_date;
}

if ($scan_from_date !== '') {
    $sql .= " AND DATE(oi.date_time) >= ?";
    $params[] = $scan_from_date;
}

if ($scan_to_date !== '') {
    $sql .= " AND DATE(oi.date_time) <= ?";
    $params[] = $scan_to_date;
}

if ($seller_id > 0) {
    $sql .= " AND o.seller_id = ?";
    $params[] = $seller_id;
}

if ($order_status !== '') {
    if ($order_status === 'cancelled') {
        $sql .= " AND IFNULL(o.is_cancelled, 0) = 1";
    } else {
        $sql .= " AND IFNULL(o.is_cancelled, 0) = 0 AND o.status = ?";
        $params[] = $order_status;
    }
}

$sql .= " ORDER BY o.created_at DESC";

// Apply limit
if ($limit > 0) {
    $sql .= " LIMIT " . $limit;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Get sellers for dropdown
$sellersStmt = $pdo->query("SELECT id, name FROM users WHERE role = 'seller' AND active = 1 ORDER BY name");
$sellers = $sellersStmt->fetchAll();

require_once __DIR__ . '/../layout/header.php';
?>

<div class="container-fluid py-3">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="h4 mb-3"><i class="bi bi-funnel me-2"></i>Order Filter</h2>
            
            <!-- Filter Form -->
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Search Orders</label>
                            <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Search by order code, customer name, phone, or location...">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="paid" <?= ($status ?? '') === 'paid' ? 'selected' : '' ?>>Paid</option>
                                <option value="unpaid" <?= ($status ?? '') === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Print Status</label>
                            <select name="printed" class="form-select">
                                <option value="">All</option>
                                <option value="printed" <?= ($printed ?? '') === 'printed' ? 'selected' : '' ?>>Printed</option>
                                <option value="unprinted" <?= ($printed ?? '') === 'unprinted' ? 'selected' : '' ?>>Unprinted</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Seller</label>
                            <select name="seller_id" class="form-select">
                                <option value="">All Sellers</option>
                                <?php foreach ($sellers as $seller): ?>
                                    <option value="<?= $seller['id'] ?>" <?= ($seller_id ?? 0) == $seller['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($seller['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Delivery Type</label>
                            <select name="delivery_type" class="form-select">
                                <option value="">All Types</option>
                                <?php
                                $deliveryTypes = $pdo->query("SELECT id, name FROM delivery_types ORDER BY name")->fetchAll();
                                foreach ($deliveryTypes as $type):
                                ?>
                                    <option value="<?= $type['id'] ?>" <?= ($delivery_type ?? 0) == $type['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($type['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Delivered By</label>
                            <select name="delivered_by" class="form-select">
                                <option value="">All</option>
                                <option value="self" <?= ($delivered_by ?? '') === 'self' ? 'selected' : '' ?>>Self</option>
                                <option value="customer" <?= ($delivered_by ?? '') === 'customer' ? 'selected' : '' ?>>Customer</option>
                                <option value="VET" <?= ($delivered_by ?? '') === 'VET' ? 'selected' : '' ?>>VET</option>
                                <option value="ក្រុមហ៊ុន" <?= ($delivered_by ?? '') === 'ក្រុមហ៊ុន' ? 'selected' : '' ?>>ក្រុមហ៊ុន</option>
                                <option value="J&T" <?= ($delivered_by ?? '') === 'J&T' ? 'selected' : '' ?>>J&T</option>
                                <option value="hou Express" <?= ($delivered_by ?? '') === 'hou Express' ? 'selected' : '' ?>>hou Express</option>
                                <option value="Jalat" <?= ($delivered_by ?? '') === 'Jalat' ? 'selected' : '' ?>>Jalat</option>
                                <option value="Banhjol" <?= ($delivered_by ?? '') === 'Banhjol' ? 'selected' : '' ?>>Banhjol</option>
                                <option value="ភ្លៀវមកយកដល់ហាង" <?= ($delivered_by ?? '') === 'ភ្លៀវមកយកដល់ហាង' ? 'selected' : '' ?>>ភ្លៀវមកយកដល់ហាង</option>
                                <option value="Kjill Express" <?= ($delivered_by ?? '') === 'Kjill Express' ? 'selected' : '' ?>>Kjill Express</option>
                                <option value="courier" <?= ($delivered_by ?? '') === 'courier' ? 'selected' : '' ?>>Courier</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date Range</label>
                            <div class="d-flex gap-1">
                                <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($from_date ?? '') ?>" placeholder="From">
                                <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($to_date ?? '') ?>" placeholder="To">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Scan Date</label>
                            <div class="d-flex gap-1">
                                <input type="date" name="scan_from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($scan_from_date ?? '') ?>" placeholder="From">
                                <input type="date" name="scan_to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($scan_to_date ?? '') ?>" placeholder="To">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Show Records</label>
                            <select name="limit" class="form-select" onchange="this.form.submit()">
                                <option value="100" <?= ($limit ?? 100) == 100 ? 'selected' : '' ?>>100</option>
                                <option value="200" <?= ($limit ?? 100) == 200 ? 'selected' : '' ?>>200</option>
                                <option value="300" <?= ($limit ?? 100) == 300 ? 'selected' : '' ?>>300</option>
                                <option value="0" <?= ($limit ?? 100) == 0 ? 'selected' : '' ?>>All</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Order Status</label>
                            <select name="order_status" class="form-select">
                                <option value="">All Status</option>
                                <option value="pending" <?= ($order_status ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="processing" <?= ($order_status ?? '') === 'processing' ? 'selected' : '' ?>>Processing</option>
                                <option value="completed" <?= ($order_status ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="cancelled" <?= ($order_status ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search me-1"></i>Search Orders
                                </button>
                                <a href="order_filter.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle me-1"></i>Clear
                                </a>
                                <button type="button" class="btn btn-success" onclick="printSelected()">
                                    <i class="bi bi-printer me-1"></i>Print Selected
                                </button>
                                <button type="button" class="btn btn-info text-white" onclick="exportToExcel()">
                                    <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                                </button>
                                <button type="button" class="btn btn-warning text-white" onclick="markAsPrinted()">
                                    <i class="bi bi-check-circle me-1"></i>Mark Printed
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Results Table -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">Results (<?= count($orders) ?> orders)</h5>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="selectAll" onchange="toggleAll()">
                            <label class="form-check-label" for="selectAll">
                                Select All
                            </label>
                        </div>
                    </div>
                    
                    <!-- Selected Orders Summary Table -->
                    <div id="selectedSummary" class="card mb-3 border-info" style="display: none;">
                        <div class="card-body bg-light">
                            <h6 class="card-title text-info mb-3">
                                <i class="bi bi-check-circle-fill me-1"></i>
                                Selected Orders (<span id="selectedCount">0</span>)
                            </h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-info">
                                        <tr>
                                            <th width="50">No</th>
                                            <th>Order Code</th>
                                            <th>Customer Name</th>
                                            <th>Phone</th>
                                            <th>Location</th>
                                            <th>Total</th>
                                            <th width="80">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="selectedOrdersBody">
                                        <!-- Selected orders will be added here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th width="40">
                                        <input class="form-check-input" type="checkbox" id="selectAllTop" onchange="toggleAll()">
                                    </th>
                                    <th>Order Code</th>
                                    <th>Customer Name</th>
                                    <th>Phone Number</th>
                                    <th>Location</th>
                                    <th>Seller</th>
                                    <th>Delivery Type</th>
                                    <th>Delivered By</th>
                                    <th>Status</th>
                                    <th>Printed</th>
                                    <th>Order Status</th>
                                    <th>Total</th>
                                    <th>Scan Time</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="15" class="text-center py-4">No orders found matching your criteria.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $index => $order): ?>
                                        <tr data-order-id="<?= $order['id'] ?>" 
                                            data-order-code="<?= htmlspecialchars($order['order_code']) ?>"
                                            data-customer-name="<?= htmlspecialchars($order['customer_name']) ?>"
                                            data-phone="<?= htmlspecialchars($order['phone'] ?? '') ?>"
                                            data-location="<?= htmlspecialchars($order['location'] ?? '') ?>"
                                            data-total="<?= $order['total_amount'] ?>">
                                            <td>
                                                <input class="form-check-input order-checkbox" type="checkbox" value="<?= $order['id'] ?>" onchange="updateSelectedSummary()">
                                            </td>
                                            <td><strong><?= htmlspecialchars($order['order_code']) ?></strong></td>
                                            <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                            <td><?= htmlspecialchars($order['phone'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($order['location'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($order['seller_name']) ?></td>
                                            <td><?= htmlspecialchars($order['delivery_type_name'] ?? 'N/A') ?></td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?= htmlspecialchars(ucfirst($order['out_item_delivery_by'] ?? 'N/A')) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= $order['status'] === 'paid' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                                    <?= strtoupper($order['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= $order['is_printed'] ? 'bg-success' : 'bg-secondary' ?>">
                                                    <?= $order['is_printed'] ? 'Printed' : 'Unprinted' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($order['is_cancelled']): ?>
                                                    <span class="badge bg-danger">CANCELLED</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">ACTIVE</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">$<?= number_format($order['total_amount'], 2) ?></td>
                                            <td><?= htmlspecialchars($order['scan_date_time'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($order['created_at']) ?></td>
                                            <td>
                                                <a href="../receipt.php?id=<?= $order['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i> View
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
    </div>
</div>

<script>
function toggleAll() {
    const selectAll = document.getElementById('selectAll').checked || document.getElementById('selectAllTop').checked;
    const checkboxes = document.querySelectorAll('.order-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll);
    
    // Sync both select all checkboxes
    document.getElementById('selectAll').checked = selectAll;
    document.getElementById('selectAllTop').checked = selectAll;
    
    // Update selected summary
    updateSelectedSummary();
}

function updateSelectedSummary() {
    const selected = document.querySelectorAll('.order-checkbox:checked');
    const summaryDiv = document.getElementById('selectedSummary');
    const tbody = document.getElementById('selectedOrdersBody');
    const countSpan = document.getElementById('selectedCount');
    
    if (selected.length === 0) {
        summaryDiv.style.display = 'none';
        return;
    }
    
    summaryDiv.style.display = 'block';
    countSpan.textContent = selected.length;
    
    // Clear existing rows
    tbody.innerHTML = '';
    
    // Add selected orders to summary table
    let totalAmount = 0;
    selected.forEach((checkbox, index) => {
        const row = checkbox.closest('tr');
        const orderId = row.dataset.orderId;
        const orderCode = row.dataset.orderCode;
        const customerName = row.dataset.customerName;
        const phone = row.dataset.phone;
        const location = row.dataset.location;
        const total = parseFloat(row.dataset.total);
        
        totalAmount += total;
        
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${index + 1}</td>
            <td><strong>${orderCode}</strong></td>
            <td>${customerName}</td>
            <td>${phone}</td>
            <td>${location}</td>
            <td class="text-end">$${total.toFixed(2)}</td>
            <td>
                <button class="btn btn-sm btn-outline-danger" onclick="removeFromSelection(${orderId})">
                    <i class="bi bi-x"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
    
    // Add total row
    const totalRow = document.createElement('tr');
    totalRow.innerHTML = `
        <td colspan="5" class="text-end fw-bold">Total:</td>
        <td class="text-end fw-bold">$${totalAmount.toFixed(2)}</td>
        <td></td>
    `;
    tbody.appendChild(totalRow);
}

function removeFromSelection(orderId) {
    const checkbox = document.querySelector(`.order-checkbox[value="${orderId}"]`);
    if (checkbox) {
        checkbox.checked = false;
        updateSelectedSummary();
    }
}

function printSelected() {
    const selected = document.querySelectorAll('.order-checkbox:checked');
    if (selected.length === 0) {
        alert('Please select at least one order to print.');
        return;
    }
    
    // Create print window and directly trigger print
    const printWindow = window.open('', '_blank', 'width=800,height=600');
    let html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Order Summary - ${new Date().toLocaleDateString()}</title>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    margin: 20px; 
                    background: white;
                    color: black;
                }
                h1 { 
                    text-align: center; 
                    margin-bottom: 20px; 
                    color: black;
                }
                table { 
                    width: 100%; 
                    border-collapse: collapse; 
                    margin-bottom: 20px; 
                }
                th, td { 
                    border: 1px solid black; 
                    padding: 8px; 
                    text-align: left; 
                    color: black;
                    background: white;
                }
                th { 
                    background-color: #f0f0f0; 
                    font-weight: bold; 
                    color: black;
                }
                .text-right { text-align: right; }
                .text-center { text-align: center; }
                .total-row { 
                    font-weight: bold; 
                    background-color: #f0f0f0; 
                    color: black;
                }
                @media print {
                    body { margin: 10px; }
                    th, td { border: 1px solid black; color: black; background: white; }
                    th { background-color: #f0f0f0 !important; }
                }
            </style>
        </head>
        <body>
            <h1>Order Summary Report</h1>
            <p class="text-center">Generated on: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}</p>
            
            <table>
                <thead>
                    <tr>
                        <th width="50">No</th>
                        <th>Order Code</th>
                        <th>Customer Name</th>
                        <th>Phone Number</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th class="text-right">Total</th>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    let totalAmount = 0;
    let paidAmount = 0;
    let unpaidAmount = 0;
    
    selected.forEach((checkbox, index) => {
        const row = checkbox.closest('tr');
        const orderCode = row.dataset.orderCode;
        const customerName = row.dataset.customerName;
        const phone = row.dataset.phone;
        const location = row.dataset.location;
        const total = parseFloat(row.dataset.total);
        const status = row.querySelector('td:nth-child(9) span').textContent;
        const statusColor = status.trim().toUpperCase() === 'PAID' ? 'red' : 'black';
        
        totalAmount += total;
        
        if (status.trim().toUpperCase() === 'PAID') {
            paidAmount += total;
        } else {
            unpaidAmount += total;
        }
        
        html += `
            <tr>
                <td class="text-center">${index + 1}</td>
                <td>${orderCode}</td>
                <td>${customerName}</td>
                <td>${phone}</td>
                <td>${location}</td>
                <td style="color: ${statusColor}; font-weight: ${statusColor === 'red' ? 'bold' : 'normal'}">${status}</td>
                <td class="text-right">$${total.toFixed(2)}</td>
                <td style="height: 30px; border: 1px solid black; background: white;"></td>
            </tr>
        `;
    });
    
    html += `
                </tbody>
            </table>
            
            <div style="margin-top: 20px; text-align: right;">
                <p style="margin: 5px 0; color: red; font-weight: bold;">Paid: $${paidAmount.toFixed(2)}</p>
                <p style="margin: 5px 0; color: black; font-weight: bold;">Unpaid: $${unpaidAmount.toFixed(2)}</p>
                <p style="margin: 5px 0; color: black; font-weight: bold;">Total: $${totalAmount.toFixed(2)}</p>
            </div>
        </body>
        </html>
    `;
    
    printWindow.document.write(html);
    printWindow.document.close();
    
    // Directly trigger print dialog
    printWindow.onload = function() {
        printWindow.print();
        printWindow.close();
    };
}

function exportToExcel() {
    const selected = document.querySelectorAll('.order-checkbox:checked');
    if (selected.length === 0) {
        alert('Please select at least one order to export.');
        return;
    }
    
    const orderIds = Array.from(selected).map(cb => cb.value).join(',');
    const url = new URL(window.location);
    url.searchParams.set('export', 'excel');
    url.searchParams.set('ids', orderIds);
    window.location.href = url.toString();
}

function markAsPrinted() {
    const selected = document.querySelectorAll('.order-checkbox:checked');
    if (selected.length === 0) {
        alert('Please select at least one order to mark as printed.');
        return;
    }
    
    if (!confirm(`Are you sure you want to mark ${selected.length} order(s) as printed?`)) {
        return;
    }
    
    const orderIds = Array.from(selected).map(cb => cb.value);
    
    fetch('order_filter.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            'action': 'mark_printed',
            'order_ids': orderIds.join(',')
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`${data.count} order(s) marked as printed successfully.`);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error marking orders as printed.');
        console.error('Error:', error);
    });
}

// Sync select all checkboxes
document.getElementById('selectAll').addEventListener('change', function() {
    document.getElementById('selectAllTop').checked = this.checked;
});

document.getElementById('selectAllTop').addEventListener('change', function() {
    document.getElementById('selectAll').checked = this.checked;
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
