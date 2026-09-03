<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'receipts.view');
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();

$errors = [];
$success = '';

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_receipt'])) {
    require_role_or_permission(['admin'], 'receipts.delete');
    $receipt_id = (int)$_POST['receipt_id'];
    try {
        $pdo->beginTransaction();

        // Delete receipt order items first
        $stmt = $pdo->prepare('DELETE FROM receipt_order_items WHERE receipt_order_id = ?');
        $stmt->execute([$receipt_id]);

        // Delete receipt order
        $stmt = $pdo->prepare('DELETE FROM receipt_orders WHERE id = ?');
        $stmt->execute([$receipt_id]);

        $pdo->commit();
        $success = 'Receipt order deleted successfully.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        $errors[] = 'Failed to delete receipt order: ' . $e->getMessage();
    }
}

// Build query with filters
$where = [];
$params = [];

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $where[] = '(ro.receipt_code LIKE ? OR ro.customer_name LIKE ? OR ro.phone LIKE ? OR ro.location LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$status_filter = $_GET['status'] ?? '';
if ($status_filter !== '') {
    $where[] = 'ro.status = ?';
    $params[] = $status_filter;
}

$seller_filter = $_GET['seller'] ?? '';
if ($seller_filter !== '') {
    $where[] = 'ro.seller_id = ?';
    $params[] = $seller_filter;
}

$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
if ($date_from !== '') {
    $where[] = 'DATE(ro.created_at) >= ?';
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = 'DATE(ro.created_at) <= ?';
    $params[] = $date_to;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Pagination
$page = (int)($_GET['page'] ?? 1);
$per_page = 25;
$offset = ($page - 1) * $per_page;

// Get total count
$countStmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM receipt_orders ro
    $whereClause
");
$countStmt->execute($params);
$total_records = $countStmt->fetch()['total'];
$total_pages = ceil($total_records / $per_page);

// Get receipt orders with seller info
$stmt = $pdo->prepare("
    SELECT ro.*, u.name as seller_name, uc.name as created_by_name, COUNT(roi.id) as item_count
    FROM receipt_orders ro
    LEFT JOIN users u ON ro.seller_id = u.id
    LEFT JOIN users uc ON ro.created_by = uc.id
    LEFT JOIN receipt_order_items roi ON ro.id = roi.receipt_order_id
    $whereClause
    GROUP BY ro.id
    ORDER BY ro.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$receipt_orders = $stmt->fetchAll();

// Get sellers for filter dropdown
$sellersStmt = $pdo->query('SELECT id, name FROM users WHERE role = "seller" ORDER BY name');
$sellers = $sellersStmt->fetchAll();

?>

<?php include __DIR__ . '/../layout/header.php'; ?>

<style>
    .status-badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
    }
    .filter-section {
        background: #f8f9fa;
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    .table-responsive {
        border-radius: 0.5rem;
        overflow: hidden;
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }
    .action-buttons {
        white-space: nowrap;
    }
    .print-select-cell {
        width: 44px;
        text-align: center;
    }
    .print-toolbar {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
    }
    </style>

<div class="row g-4 flex-grow-1">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-0"><i class="bi bi-receipt me-2"></i>Receipt Orders History</h2>
                <small class="text-muted">Manage all receipt orders</small>
            </div>
            <div>
                <a href="create_receipt_order.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1"></i>Create New Receipt
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Receipt code, customer, phone, location">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="">All Status</option>
                                <option value="preparing" <?php echo $status_filter === 'preparing' ? 'selected' : ''; ?>>Preparing</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Seller</label>
                            <select class="form-select" name="seller">
                                <option value="">All Sellers</option>
                                <?php foreach ($sellers as $seller): ?>
                                    <option value="<?php echo $seller['id']; ?>" <?php echo $seller_filter == $seller['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($seller['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-outline-primary me-1">
                                <i class="bi bi-search"></i>
                            </button>
                            <a href="history_receipt.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Results Summary -->
                <div class="mb-3">
                    <small class="text-muted">
                        Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $per_page, $total_records); ?> of <?php echo $total_records; ?> receipt orders
                    </small>
                </div>

                <div class="print-toolbar d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="selectAllPrint">
                        <label class="form-check-label" for="selectAllPrint">Select all for print</label>
                    </div>
                    <button type="button" class="btn btn-primary btn-pill" id="printSelectedReceipts">
                        <i class="bi bi-printer me-1"></i>Print selected
                    </button>
                </div>

                <!-- Receipt Orders Table -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="print-select-cell">Print</th>
                                <th>Receipt Code</th>
                                <th>Customer</th>
                                <th>Seller</th>
                                <th>Created By</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($receipt_orders)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4">
                                        <i class="bi bi-receipt-x display-4 text-muted mb-3"></i>
                                        <div class="text-muted">No receipt orders found</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($receipt_orders as $order): ?>
                                    <tr>
                                        <td class="print-select-cell">
                                            <input class="form-check-input receipt-print-check" type="checkbox" value="<?php echo (int)$order['id']; ?>" aria-label="Select <?php echo htmlspecialchars($order['receipt_code']); ?> for print">
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($order['receipt_code']); ?></strong>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($order['customer_name']); ?></div>
                                            <?php if (!empty($order['phone'])): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($order['phone']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($order['seller_name']); ?></td>
                                        <td><?php echo htmlspecialchars($order['created_by_name'] ?: 'Unknown'); ?></td>
                                        <td><?php echo $order['item_count']; ?> items</td>
                                        <td>$<?php echo number_format($order['total_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge status-badge
                                                <?php
                                                switch ($order['status']) {
                                                    case 'preparing': echo 'bg-warning text-dark'; break;
                                                    case 'completed': echo 'bg-success'; break;
                                                    case 'cancelled': echo 'bg-danger'; break;
                                                    default: echo 'bg-secondary';
                                                }
                                                ?>">
                                                <?php echo htmlspecialchars(ucfirst($order['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?php echo date('M d, Y', strtotime($order['created_at'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($order['created_at'])); ?></small>
                                        </td>
                                        <td class="text-center action-buttons">
                                            <div class="btn-group" role="group">
                                                <a href="receipt_order_receipt.php?id=<?php echo $order['id']; ?>&from=reprint" class="btn btn-sm btn-primary btn-pill" target="_blank" title="Print Receipt">
                                                    <i class="bi bi-printer"></i> Print
                                                </a>
                                                <a href="edit_receipt_order.php?id=<?php echo (int)$order['id']; ?>" class="btn btn-sm btn-outline-secondary btn-pill" title="Edit Receipt">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-pill" onclick="confirmDelete(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['receipt_code']); ?>')" title="Delete">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Receipt orders pagination" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            if ($start_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                                </li>
                                <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>"><?php echo $total_pages; ?></a>
                                </li>
                            <?php endif; ?>

                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete receipt order <strong id="deleteReceiptCode"></strong>?
                    <br><small class="text-muted">This action cannot be undone.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="receipt_id" id="deleteReceiptId">
                        <button type="submit" name="delete_receipt" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function confirmDelete(receiptId, receiptCode) {
            document.getElementById('deleteReceiptId').value = receiptId;
            document.getElementById('deleteReceiptCode').textContent = receiptCode;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        (function() {
            const selectAll = document.getElementById('selectAllPrint');
            const printBtn = document.getElementById('printSelectedReceipts');
            const checks = Array.from(document.querySelectorAll('.receipt-print-check'));

            function selectedIds() {
                return checks.filter(check => check.checked).map(check => check.value);
            }

            function updateSelectAll() {
                if (!selectAll) {
                    return;
                }
                const selectedCount = selectedIds().length;
                selectAll.checked = checks.length > 0 && selectedCount === checks.length;
                selectAll.indeterminate = selectedCount > 0 && selectedCount < checks.length;
            }

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    checks.forEach(check => {
                        check.checked = selectAll.checked;
                    });
                    updateSelectAll();
                });
            }

            checks.forEach(check => {
                check.addEventListener('change', updateSelectAll);
            });

            if (printBtn) {
                printBtn.addEventListener('click', function() {
                    const ids = selectedIds();
                    if (ids.length === 0) {
                        alert('Please select at least one receipt to print.');
                        return;
                    }

                    ids.forEach((id, index) => {
                        setTimeout(() => {
                            window.open('receipt_order_receipt.php?id=' + encodeURIComponent(id) + '&from=reprint', '_blank');
                        }, index * 350);
                    });
                });
            }
        })();
    </script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
