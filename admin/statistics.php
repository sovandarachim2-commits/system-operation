<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'statistics.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');

// Normalize empty
$from = $from === '' ? date('Y-m-d') : $from;
$to   = $to === ''   ? $from : $to;

$mode = $_GET['mode'] ?? '';
$printedOrdersJoin = '(SELECT order_id, MAX(printed_at) AS printed_at FROM print_jobs GROUP BY order_id)';
$printedOrderIdsJoin = '(SELECT DISTINCT order_id FROM print_jobs)';
?>
<?php require_once __DIR__ . '/../layout/header.php'; ?>

<style>
/* Mobile responsiveness improvements */
@media (max-width: 768px) {
    /* Smaller font sizes for mobile */
    .card-title {
        font-size: 1rem !important;
    }
    
    .card h3 {
        font-size: 1.5rem !important;
    }
    
    .card small {
        font-size: 0.75rem !important;
    }
    
    /* Adjust table padding and font size */
    .table th, .table td {
        padding: 0.5rem 0.25rem;
        font-size: 0.875rem;
    }
    
    /* Make date inputs stack on mobile */
    .d-flex.gap-2 {
        flex-direction: column;
        gap: 0.5rem !important;
    }
    
    .d-flex.gap-2 .form-control {
        width: 100%;
    }
    
    /* Adjust container padding */
    .container-fluid {
        padding-left: 15px;
        padding-right: 15px;
    }
    
    /* Hide cancelled/returned columns on mobile */
    .cancel-return-mobile-hide {
        display: table-cell;
    }
}

/* Very small screens */
@media (max-width: 576px) {
    /* Even smaller fonts */
    .card-title {
        font-size: 0.9rem !important;
    }
    
    .card h3 {
        font-size: 1.25rem !important;
    }
    
    /* Adjust table for better scrolling */
    .table-responsive {
        font-size: 0.8rem;
    }
    
    .table th, .table td {
        padding: 0.25rem;
    }
    
    /* Hide cancelled/returned quantity columns on very small screens */
    .cancel-return-mobile-hide {
        display: none;
    }
}

/* Ensure charts are responsive */
#statusChart, #revenueChart, #hourlyChart {
    max-width: 100%;
    height: auto !important;
}
</style>

<div class="container-fluid py-3">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h2><i class="bi bi-bar-chart-line me-2"></i>Statistics & Analytics</h2>
                <div class="d-flex gap-2">
                    <input type="date" id="fromDate" class="form-control" value="<?= htmlspecialchars($from) ?>">
                    <input type="date" id="toDate" class="form-control" value="<?= htmlspecialchars($to) ?>">
                    <button class="btn btn-primary" onclick="updateStats()">Update</button>
                    <div class="btn-group btn-group-sm ms-2" role="group">
                        <button class="btn btn-outline-secondary" onclick="setToday()">Today</button>
                        <button class="btn btn-outline-secondary" onclick="setYesterday()">Yesterday</button>
                        <button class="btn btn-outline-secondary" onclick="setThisWeek()">This Week</button>
                        <button class="btn btn-outline-secondary" onclick="setThisMonth()">This Month</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Key Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card bg-success text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-check-circle me-2"></i>Active Orders</h5>
                    <h3 class="mb-0" id="activeOrders">
                        <?php
                        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT o.id) as count
                                               FROM orders o
                                               JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
                                               WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND o.is_cancelled = 0 AND o.is_returned = 0');
                        $stmt->execute([$from, $to]);
                        echo $stmt->fetch()['count'];
                        ?>
                    </h3>
                    <small>Count Without Cancel&Return</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-info text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-cash me-2"></i>Total Revenue</h5>
                    <h3 class="mb-0" id="totalRevenue">
                        <?php
                        $stmt = $pdo->prepare('SELECT SUM(o.total_amount + o.discount) as total
                                               FROM orders o
                                               JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
                                               WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND o.is_cancelled = 0 AND o.is_returned = 0');
                        $stmt->execute([$from, $to]);
                        $total = $stmt->fetch()['total'] ?? 0;
                        echo '$' . number_format($total, 2);
                        ?>
                    </h3>
                    <small>Revenue Without Cancel&Return</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-warning text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-percent me-2"></i>Total Discounts</h5>
                    <h3 class="mb-0" id="totalDiscounts">
                        <?php
                        $stmt = $pdo->prepare('SELECT SUM(o.discount) as total
                                               FROM orders o
                                               JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
                                               WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND o.is_cancelled = 0 AND o.is_returned = 0');
                        $stmt->execute([$from, $to]);
                        $discounts = $stmt->fetch()['total'] ?? 0;
                        echo '$' . number_format($discounts, 2);
                        ?>
                    </h3>
                    <small>Discounts on active orders</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-primary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-graph-up-arrow me-2"></i>Total Net Revenue</h5>
                    <h3 class="mb-0" id="netRevenue">
                        <?php
                        $stmt = $pdo->prepare('SELECT SUM(o.total_amount) as total
                                               FROM orders o
                                               JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
                                               WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND o.is_cancelled = 0 AND o.is_returned = 0');
                        $stmt->execute([$from, $to]);
                        $netRevenue = $stmt->fetch()['total'] ?? 0;
                        echo '$' . number_format($netRevenue, 2);
                        ?>
                    </h3>
                    <small>Net revenue after discounts</small>
                </div>
            </div>
        </div>
    </div>
    <!-- Unprinted Orders -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-6">
            <div class="card bg-secondary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-printer me-2"></i>New Order</h5>
                    <h3 class="mb-0" id="unprintedOrders">
                        <?php
                        $stmt = $pdo->prepare('SELECT COUNT(*) as count
                                               FROM orders o
                                               LEFT JOIN ' . $printedOrderIdsJoin . ' pj ON pj.order_id = o.id
                                               WHERE pj.order_id IS NULL AND IFNULL(o.is_cancelled, 0) = 0');
                        $stmt->execute();
                        echo $stmt->fetch()['count'];
                        ?>
                    </h3>
                    <small>(អត់ទាន់Print)</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6">
            <div class="card bg-info text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-clock-history me-2"></i>New Order Revenue</h5>
                    <h3 class="mb-0" id="unprintedRevenue">
                        <?php
                        $stmt = $pdo->prepare('SELECT SUM(total_amount) as total
                                               FROM orders o
                                               LEFT JOIN ' . $printedOrderIdsJoin . ' pj ON pj.order_id = o.id
                                               WHERE pj.order_id IS NULL AND IFNULL(o.is_cancelled, 0) = 0');
                        $stmt->execute();
                        $unprinted = $stmt->fetch()['total'] ?? 0;
                        echo '$' . number_format($unprinted, 2);
                        ?>
                    </h3>
                    <small>(អត់ទាន់Print)</small>
                </div>
            </div>
        </div>
    </div>
    <!-- Problem Orders Summary -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-6">
            <div class="card bg-danger text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-x-circle me-2"></i>Cancelled Orders</h5>
                    <h3 class="mb-0">
                        <?php
                        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT o.id) as count, SUM(o.total_amount) as total
                                               FROM orders o
                                               LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
                                               WHERE DATE(SUBSTRING(o.order_code, 5, 8)) BETWEEN ? AND ? AND o.is_cancelled = 1');
                        $stmt->execute([$from, $to]);
                        $cancelled = $stmt->fetch();
                        echo $cancelled['count'] . ' / $' . number_format($cancelled['total'] ?? 0, 2);
                        ?>
                    </h3>
                    <small>Count / Total amount cancelled</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6">
            <div class="card bg-secondary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-arrow-left-circle me-2"></i>Returned Orders</h5>
                    <h3 class="mb-0">
                        <?php
                        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT o.id) as count, SUM(o.total_amount) as total
                                               FROM orders o
                                               JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
                                               WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND o.is_returned = 1');
                        $stmt->execute([$from, $to]);
                        $returned = $stmt->fetch();
                        echo $returned['count'] . ' / $' . number_format($returned['total'] ?? 0, 2);
                        ?>
                    </h3>
                    <small>Count / Total amount returned</small>
                </div>
            </div>
        </div>
    </div>

    

    <!-- Revenue Breakdown -->

    <!-- Charts and Tables Row -->
    <div class="row g-4">
        <!-- Top Selling Products -->
        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Top Selling Products</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Sold</th>
                                    <th class="text-end">Per Product</th>
                                    <th class="text-end">Revenue</th>
                                </tr>
                            </thead>
                            <tbody id="topProductsTable">
                                <?php
                                $stmt = $pdo->prepare('SELECT p.name, SUM(oi.quantity) as qty, SUM(oi.line_total) as revenue, SUM(oi.line_total) / SUM(oi.quantity) as avg_price
                                                     FROM order_items oi
                                                     JOIN products p ON oi.product_id = p.id
                                                     JOIN orders o ON oi.order_id = o.id
                                                     JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
                                                     WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND o.is_cancelled = 0 AND o.is_returned = 0
                                                     GROUP BY p.id, p.name
                                                     ORDER BY qty DESC');
                                $stmt->execute([$from, $to]);
                                $topProducts = $stmt->fetchAll();
                                if (empty($topProducts)): ?>
                                    <tr><td colspan="4" class="text-center py-4">No sales data</td></tr>
                                <?php else:
                                    foreach ($topProducts as $product): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($product['name']) ?></td>
                                            <td class="text-end"><?= (int)$product['qty'] ?></td>
                                            <td class="text-end">$<?= number_format($product['avg_price'], 2) ?></td>
                                            <td class="text-end">$<?= number_format($product['revenue'], 2) ?></td>
                                        </tr>
                                    <?php endforeach;
                                endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-active fw-bold">
                                    <td><strong>Total</strong></td>
                                    <td class="text-end">
                                        <strong><?= number_format(array_sum(array_column($topProducts, 'qty'))) ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong>$<?= number_format(array_sum(array_column($topProducts, 'avg_price')), 2) ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong>$<?= number_format(array_sum(array_column($topProducts, 'revenue')), 2) ?></strong>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Cancelled/Returned Products -->
        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Top Cancelled/Returned Products</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end cancel-return-mobile-hide">Cancelled Qty</th>
                                    <th class="text-end cancel-return-mobile-hide">Returned Qty</th>
                                    <th class="text-end">Total Lost Qty</th>
                                    <th class="text-end">Lost Revenue</th>
                                </tr>
                            </thead>
                            <tbody id="cancelReturnProductsTable">
                                <?php
                                $stmt = $pdo->prepare('
                                    SELECT 
                                        p.name,
                                        COALESCE(SUM(CASE WHEN o.is_cancelled = 1 THEN oi.quantity ELSE 0 END), 0) as cancelled_qty,
                                        COALESCE(SUM(CASE WHEN o.is_returned = 1 THEN oi.quantity ELSE 0 END), 0) as returned_qty,
                                        COALESCE(SUM(oi.quantity), 0) as total_lost_qty,
                                        COALESCE(SUM(oi.line_total), 0) as lost_revenue
                                    FROM order_items oi
                                    JOIN products p ON oi.product_id = p.id
                                    JOIN orders o ON oi.order_id = o.id
                                    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
                                    WHERE (DATE(o.created_at) BETWEEN ? AND ? OR (pj.printed_at IS NOT NULL AND DATE(pj.printed_at) BETWEEN ? AND ?) OR DATE(SUBSTRING(o.order_code, 5, 8)) BETWEEN ? AND ?)
                                    AND (o.is_cancelled = 1 OR o.is_returned = 1)
                                    GROUP BY p.id, p.name
                                    HAVING total_lost_qty > 0
                                    ORDER BY total_lost_qty DESC
                                    LIMIT 10
                                ');
                                $stmt->execute([$from, $to, $from, $to, $from, $to]);
                                $cancelReturnProducts = $stmt->fetchAll();
                                
                                if (empty($cancelReturnProducts)): ?>
                                    <tr><td colspan="5" class="text-center py-4">No cancelled/returned products</td></tr>
                                <?php else:
                                    foreach ($cancelReturnProducts as $product): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($product['name']) ?></td>
                                            <td class="text-end">
                                                <?php if ($product['cancelled_qty'] > 0): ?>
                                                    <span class="badge bg-danger"><?= number_format($product['cancelled_qty']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($product['returned_qty'] > 0): ?>
                                                    <span class="badge bg-warning text-dark"><?= number_format($product['returned_qty']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><strong><?= number_format($product['total_lost_qty']) ?></strong></td>
                                            <td class="text-end">$<?= number_format($product['lost_revenue'], 2) ?></td>
                                        </tr>
                                    <?php endforeach;
                                endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-active fw-bold">
                                    <td><strong>Total</strong></td>
                                    <td class="text-end">
                                        <strong><span class="badge bg-danger"><?= number_format(array_sum(array_column($cancelReturnProducts, 'cancelled_qty'))) ?></span></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong><span class="badge bg-warning text-dark"><?= number_format(array_sum(array_column($cancelReturnProducts, 'returned_qty'))) ?></span></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong><?= number_format(array_sum(array_column($cancelReturnProducts, 'total_lost_qty'))) ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong>$<?= number_format(array_sum(array_column($cancelReturnProducts, 'lost_revenue')), 2) ?></strong>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts and Tables Row -->
    <div class="row g-4">
        <!-- Top Seller Performance -->
        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Top Seller Performance (Cancel & Return)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Seller</th>
                                    <th class="text-end">Total Orders</th>
                                    <th class="text-end top-seller-mobile-hide">Active Orders</th>
                                    <th class="text-end top-seller-mobile-hide">Cancelled Orders</th>
                                    <th class="text-end top-seller-mobile-hide">Returned Orders</th>
                                    <th class="text-end">Total Revenue</th>
                                    <th class="text-end">Lost Revenue</th>
                                </tr>
                            </thead>
                            <tbody id="topSellersTable">
                                <?php
                                $stmt = $pdo->prepare('
                                    SELECT 
                                        u.name as seller_name,
                                        COUNT(DISTINCT o.id) as total_orders,
                                        COUNT(DISTINCT CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 THEN o.id END) as active_orders,
                                        COUNT(DISTINCT CASE WHEN o.is_cancelled = 1 THEN o.id END) as cancelled_orders,
                                        COUNT(DISTINCT CASE WHEN o.is_returned = 1 THEN o.id END) as returned_orders,
                                        COALESCE(SUM(CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 THEN o.total_amount ELSE 0 END), 0) as total_revenue,
                                        COALESCE(SUM(CASE WHEN o.is_cancelled = 1 OR o.is_returned = 1 THEN o.total_amount ELSE 0 END), 0) as lost_revenue
                                    FROM orders o
                                    JOIN users u ON o.seller_id = u.id
                                    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
                                    WHERE DATE(o.created_at) BETWEEN ? AND ? OR (pj.printed_at IS NOT NULL AND DATE(pj.printed_at) BETWEEN ? AND ?) OR DATE(SUBSTRING(o.order_code, 5, 8)) BETWEEN ? AND ?
                                    GROUP BY u.id, u.name
                                    HAVING total_orders > 0
                                    ORDER BY total_revenue DESC
                                    LIMIT 10
                                ');
                                $stmt->execute([$from, $to, $from, $to, $from, $to]);
                                $topSellers = $stmt->fetchAll();
                                
                                if (empty($topSellers)): ?>
                                    <tr><td colspan="6" class="text-center py-4">No sales data available</td></tr>
                                <?php else:
                                    foreach ($topSellers as $seller):
                                        $cancelRate = $seller['total_orders'] > 0 ? ($seller['cancelled_orders'] / $seller['total_orders']) * 100 : 0;
                                        $returnRate = $seller['total_orders'] > 0 ? ($seller['returned_orders'] / $seller['total_orders']) * 100 : 0;
                                ?>
                                        <tr>
                                            <td><?= htmlspecialchars($seller['seller_name']) ?></td>
                                            <td class="text-end"><?= number_format($seller['total_orders']) ?></td>
                                            <td class="text-end top-seller-mobile-hide"><?= number_format($seller['active_orders']) ?></td>
                                            <td class="text-end top-seller-mobile-hide">
                                                <span class="badge bg-danger"><?= number_format($seller['cancelled_orders']) ?></span>
                                            </td>
                                            <td class="text-end top-seller-mobile-hide">
                                                <span class="badge bg-secondary"><?= number_format($seller['returned_orders']) ?></span>
                                            </td>
                                            <td class="text-end">$<?= number_format($seller['total_revenue'], 2) ?></td>
                                            <td class="text-end"><span class="text-danger">$<?= number_format($seller['lost_revenue'], 2) ?></span></td>
                                        </tr>
                                    <?php endforeach;
                                endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-active fw-bold">
                                    <td><strong>Total</strong></td>
                                    <td class="text-end">
                                        <strong><?= number_format(array_sum(array_column($topSellers, 'total_orders'))) ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong><?= number_format(array_sum(array_column($topSellers, 'active_orders'))) ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong><?= number_format(array_sum(array_column($topSellers, 'cancelled_orders'))) ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong><?= number_format(array_sum(array_column($topSellers, 'returned_orders'))) ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong>$<?= number_format(array_sum(array_column($topSellers, 'total_revenue')), 2) ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong><span class="text-danger">$<?= number_format(array_sum(array_column($topSellers, 'lost_revenue')), 2) ?></span></strong>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delivery Summary -->
    <div class="row g-4 mt-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-truck me-2"></i>Delivery Summary(ថ្ងៃវេចខ្ចប់ឥវ៉ាន់&Date of Scan out items)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>No</th>
                                    <th>Delivery By</th>
                                    <th class="text-end">Amount Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Get delivered items (unique orders only)
                                $stmt = $pdo->prepare('
                                    SELECT 
                                        o.delivery_by,
                                        COUNT(DISTINCT o.inv) as count
                                    FROM out_items o
                                    WHERE DATE(o.date_time) BETWEEN ? AND ? AND o.delivery_by IS NOT NULL AND o.delivery_by != ""
                                    GROUP BY o.delivery_by
                                    ORDER BY count DESC
                                ');
                                $stmt->execute([$from, $to]);
                                $deliveryData = $stmt->fetchAll();
                                
                                // Get not delivered items (unique orders only)
                                $stmtNotDelivered = $pdo->prepare('
                                    SELECT 
                                        COUNT(DISTINCT p.inv) as count
                                    FROM product_entries p
                                    LEFT JOIN out_items o ON o.inv = p.inv
                                    WHERE DATE(p.datetime) BETWEEN ? AND ? AND (o.delivery_by IS NULL OR o.delivery_by = "")
                                ');
                                $stmtNotDelivered->execute([$from, $to]);
                                $notDeliveredCount = $stmtNotDelivered->fetch()['count'];
                                
                                if (empty($deliveryData) && $notDeliveredCount == 0): ?>
                                    <tr><td colspan="3" class="text-center py-4">No delivery data available</td></tr>
                                <?php else:
                                    $rowNumber = 1;
                                    
                                    // Show "No Delivery" row if there are undelivered items
                                    if ($notDeliveredCount > 0):
                                ?>
                                        <tr>
                                            <td><?= $rowNumber ?></td>
                                            <td>ឥវ៉ាន់អត់ទាន់ចេញ</td>
                                            <td class="text-end"><?= number_format($notDeliveredCount) ?></td>
                                        </tr>
                                    <?php 
                                    $rowNumber++;
                                    endif;
                                    
                                    foreach ($deliveryData as $delivery):
                                ?>
                                        <tr>
                                            <td><?= $rowNumber ?></td>
                                            <td><?= htmlspecialchars($delivery['delivery_by']) ?></td>
                                            <td class="text-end"><?= number_format($delivery['count']) ?></td>
                                        </tr>
                                    <?php 
                                    $rowNumber++;
                                    endforeach;
                                endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-active fw-bold">
                                    <td colspan="2"><strong>Total</strong></td>
                                    <td class="text-end">
                                        <strong><?= number_format(array_sum(array_column($deliveryData, 'count')) + $notDeliveredCount) ?></strong>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize charts
    updateCharts();

    // Update button handler
    window.updateStats = function() {
        const fromDate = document.getElementById('fromDate').value;
        const toDate = document.getElementById('toDate').value;

        // Update URL without page reload
        const url = new URL(window.location);
        url.searchParams.set('from', fromDate);
        url.searchParams.set('to', toDate);
        window.location.href = url.toString();
    };
});

function updateCharts() {
    const fromDate = '<?= $from ?>';
    const toDate = '<?= $to ?>';

    // Revenue Trend Chart (simplified daily breakdown)
    const ctxRevenue = document.getElementById('revenueChart').getContext('2d');
    new Chart(ctxRevenue, {
        type: 'line',
        data: {
            labels: ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Day 7'],
            datasets: [{
                label: 'Revenue',
                data: [1200, 1900, 3000, 5000, 2000, 3000, 4500],
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value;
                        }
                    }
                }
            }
        }
    });

    // Hourly Order Volume
    const ctxHourly = document.getElementById('hourlyChart').getContext('2d');
    new Chart(ctxHourly, {
        type: 'bar',
        data: {
            labels: ['8AM', '9AM', '10AM', '11AM', '12PM', '1PM', '2PM', '3PM', '4PM', '5PM', '6PM'],
            datasets: [{
                label: 'Orders',
                data: [2, 5, 8, 12, 15, 18, 22, 25, 20, 15, 8],
                backgroundColor: '#17a2b8',
                borderColor: '#138496',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

// Function to update statistics with new date range
function updateStats() {
    const fromDate = document.getElementById('fromDate').value;
    const toDate = document.getElementById('toDate').value;
    
    // Build the URL with new date parameters
    let url = window.location.pathname;
    const params = new URLSearchParams();
    
    if (fromDate) params.append('from', fromDate);
    if (toDate) params.append('to', toDate);
    
    // Add mode parameter if it exists
    const mode = new URLSearchParams(window.location.search).get('mode');
    if (mode) params.append('mode', mode);
    
    // Redirect to the same page with new parameters
    if (params.toString()) {
        url += '?' + params.toString();
    }
    
    window.location.href = url;
}

// Quick filter functions
function setToday() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('fromDate').value = today;
    document.getElementById('toDate').value = today;
    updateStats();
}

function setYesterday() {
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    const dateStr = yesterday.toISOString().split('T')[0];
    document.getElementById('fromDate').value = dateStr;
    document.getElementById('toDate').value = dateStr;
    updateStats();
}

function setThisWeek() {
    const today = new Date();
    const startOfWeek = new Date(today);
    const dayOfWeek = today.getDay(); // 0 = Sunday, 1 = Monday, etc.
    const daysToSubtract = dayOfWeek === 0 ? 6 : dayOfWeek - 1; // Monday as start of week
    startOfWeek.setDate(today.getDate() - daysToSubtract);
    
    const fromDate = startOfWeek.toISOString().split('T')[0];
    const toDate = today.toISOString().split('T')[0];
    
    document.getElementById('fromDate').value = fromDate;
    document.getElementById('toDate').value = toDate;
    updateStats();
}

function setThisMonth() {
    const today = new Date();
    const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    
    const fromDate = startOfMonth.toISOString().split('T')[0];
    const toDate = today.toISOString().split('T')[0];
    
    document.getElementById('fromDate').value = fromDate;
    document.getElementById('toDate').value = toDate;
    updateStats();
}
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
