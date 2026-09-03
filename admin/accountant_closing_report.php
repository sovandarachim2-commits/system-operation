<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'closing_report.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

// Get filters
$from_year = $_GET['from_year'] ?? '';
$to_year = $_GET['to_year'] ?? '';
$month = $_GET['month'] ?? '';
$cost_month = $_GET['cost_month'] ?? date('Y-m'); // Default to current month

// Build WHERE conditions
$where_conditions = [];
$params = [];

// Default: show last 12 months based on print dates
if (empty($_GET['from_year']) && empty($_GET['to_year']) && empty($_GET['month']) && empty($_GET['cost_month'])) {
    $where_conditions[] = "pj.printed_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
} else {
    // From Year filter
    if (!empty($from_year)) {
        $where_conditions[] = "YEAR(pj.printed_at) >= ?";
        $params[] = $from_year;
    }
    
    // To Year filter
    if (!empty($to_year)) {
        $where_conditions[] = "YEAR(pj.printed_at) <= ?";
        $params[] = $to_year;
    }

    if (!empty($month)) {
        $where_conditions[] = "MONTH(pj.printed_at) = ?";
        $params[] = $month;
    }
}

// Always include print_jobs join condition
$where_conditions[] = "pj.printed_at IS NOT NULL";

if (empty($where_conditions)) {
    $where_conditions[] = "1=1";
}

$where_clause = implode(' AND ', $where_conditions);

// Get products with costs for the selected month (matching Cost Management system)
$cost_products_stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.name,
        COALESCE(pc.selling_price, p.cost) as selling_price,
        COALESCE(pc.original_cost, 0) as original_cost,
        COALESCE(pc.supplier_cost, 0) as supplier_cost,
        COALESCE(pc.shipping_cost, 0) as shipping_cost,
        COALESCE(pc.other_costs, 0) as other_costs,
        COALESCE(pc.total_cost, 0) as total_cost,
        (COALESCE(pc.selling_price, p.cost) - COALESCE(pc.total_cost, 0)) as profit_per_unit,
        CASE 
            WHEN COALESCE(pc.total_cost, 0) > 0 THEN 
                ROUND(((COALESCE(pc.selling_price, p.cost) - COALESCE(pc.total_cost, 0)) / COALESCE(pc.total_cost, 0)) * 100, 2)
            ELSE 0 
        END as profit_percentage,
        pc.month_year,
        pc.cost_updated_at,
        u.name as updated_by_name,
        pc.notes,
        CASE 
            WHEN pc.original_cost = 0 AND pc.supplier_cost = 0 AND pc.shipping_cost = 0 AND pc.other_costs = 0 
            THEN 1 
            ELSE 0 
        END as needs_update
    FROM products p
    LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
    LEFT JOIN users u ON pc.updated_by = u.id
    ORDER BY p.name
");
$cost_products_stmt->execute([$cost_month]);
$cost_products = $cost_products_stmt->fetchAll();

// Get monthly data for comprehensive analysis
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(pj.printed_at, '%Y-%m') as month,
        COUNT(DISTINCT o.id) as order_count,
        COALESCE(SUM(o.total_amount), 0) as net_revenue, -- Net revenue = actual money received from customer (o.total_amount)
        COALESCE(SUM(o.discount), 0) as discount, -- Discount = sum of discount from each order
        COALESCE(SUM(o.total_amount + o.discount), 0) as revenue -- Revenue = net revenue + discount
    FROM orders o
    LEFT JOIN print_jobs pj ON pj.order_id = o.id
    WHERE {$where_clause} AND pj.printed_at IS NOT NULL
    GROUP BY DATE_FORMAT(pj.printed_at, '%Y-%m')
    ORDER BY month DESC
");
$stmt->execute($params);
// Get monthly report data (simplified version with spending included in profit calculation)
$monthly_report_stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(pj.printed_at, '%Y-%m') as month,
        COUNT(DISTINCT o.id) as order_count,
        COALESCE(SUM(o.total_amount), 0) as net_revenue, -- Net revenue = sum total_amount from database
        COALESCE(SUM(o.discount), 0) as discount, -- Discount = sum discount from orders
        COALESCE(SUM(o.total_amount + o.discount), 0) as revenue -- Revenue = net revenue + discount
    FROM orders o
    INNER JOIN print_jobs pj ON pj.order_id = o.id
    WHERE {$where_clause} AND pj.printed_at IS NOT NULL AND o.status != 'cancelled' AND o.is_cancelled = 0 AND o.is_returned = 0
    GROUP BY DATE_FORMAT(pj.printed_at, '%Y-%m')
    ORDER BY month DESC
");
$monthly_report_stmt->execute($params);
$monthly_report_data = $monthly_report_stmt->fetchAll();

// Get items sold separately to avoid double counting
$items_sold_stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(pj.printed_at, '%Y-%m') as month,
        COALESCE(SUM(oi.quantity), 0) as total_items_sold
    FROM orders o
    INNER JOIN print_jobs pj ON pj.order_id = o.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE {$where_clause} AND pj.printed_at IS NOT NULL AND o.status != 'cancelled' AND o.is_cancelled = 0 AND o.is_returned = 0
    GROUP BY DATE_FORMAT(pj.printed_at, '%Y-%m')
    ORDER BY month DESC
");
$items_sold_stmt->execute($params);
$items_sold_data = [];
foreach ($items_sold_stmt->fetchAll() as $row) {
    $items_sold_data[$row['month']] = $row['total_items_sold'];
}

// Get daily breakdown data for each month
$daily_breakdown_stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(pj.printed_at, '%Y-%m') as month,
        DATE_FORMAT(pj.printed_at, '%Y-%m-%d') as day,
        DATE_FORMAT(pj.printed_at, '%d') as day_number,
        COUNT(DISTINCT o.id) as order_count,
        COALESCE(SUM(o.total_amount), 0) as net_revenue,
        COALESCE(SUM(o.discount), 0) as discount,   
        COALESCE(SUM(o.total_amount + o.discount), 0) as revenue
    FROM orders o
    INNER JOIN print_jobs pj ON pj.order_id = o.id
    WHERE {$where_clause} AND pj.printed_at IS NOT NULL
    GROUP BY DATE_FORMAT(pj.printed_at, '%Y-%m'), DATE_FORMAT(pj.printed_at, '%Y-%m-%d')
    ORDER BY month DESC, day_number ASC
");
$daily_breakdown_stmt->execute($params);
$daily_breakdown_data = $daily_breakdown_stmt->fetchAll();

// Group daily data by month
$daily_by_month = [];
foreach ($daily_breakdown_data as $daily) {
    $daily_by_month[$daily['month']][] = $daily;
}

// Get detailed order data for each day
$daily_order_details_stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(pj.printed_at, '%Y-%m-%d') as day,
        o.id as order_id,
        o.order_code,
        o.total_amount,
        o.discount,
        (o.total_amount + o.discount) as revenue,
        SUM(COALESCE(oi.quantity, 0)) as items_count,
        GROUP_CONCAT(DISTINCT pr.name SEPARATOR ', ') as product_names,
        pj.printed_at,
        o.status,
        o.is_cancelled,
        o.is_returned
    FROM orders o
    INNER JOIN print_jobs pj ON pj.order_id = o.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN products pr ON oi.product_id = pr.id
    WHERE {$where_clause} AND pj.printed_at IS NOT NULL
    GROUP BY o.id, DATE_FORMAT(pj.printed_at, '%Y-%m-%d'), pj.printed_at
    ORDER BY day DESC, pj.printed_at ASC
");
$daily_order_details_stmt->execute($params);
$daily_order_details = $daily_order_details_stmt->fetchAll();

// Group order details by day
$orders_by_day = [];
foreach ($daily_order_details as $order) {
    $orders_by_day[$order['day']][] = $order;
}
$delivery_stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(pj.printed_at, '%Y-%m') as month,
        COUNT(DISTINCT o.id) as order_count,
        COALESCE(SUM(o.total_amount), 0) as total_amount, -- Net amount (actual amount received from customer)
        COALESCE(SUM(dc.amount), 0) as total_delivery_cost,
        COALESCE(SUM(o.total_amount - COALESCE(dc.amount, 0)), 0) as net_total
    FROM orders o
    INNER JOIN print_jobs pj ON pj.order_id = o.id
    LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
    WHERE {$where_clause} AND pj.printed_at IS NOT NULL
    GROUP BY DATE_FORMAT(pj.printed_at, '%Y-%m')
    ORDER BY month DESC
");
$delivery_stmt->execute($params);
$delivery_data = $delivery_stmt->fetchAll();

// Get finance data (top-up spending) from real finance tables
$finance_stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(topup_date, '%Y-%m') as month,
        COALESCE(SUM(amount), 0) as total_topup_spending,
        COUNT(DISTINCT id) as transaction_count,
        COUNT(DISTINCT person_name) as unique_users
    FROM finance_topups 
    WHERE DATE(topup_date) BETWEEN DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND CURDATE()
    GROUP BY DATE_FORMAT(topup_date, '%Y-%m')
    ORDER BY month DESC
");
$finance_stmt->execute();
$finance_data = $finance_stmt->fetchAll();

// Get finance spending data (from finance_spending table, not orders)
$finance_spending_stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(spending_date, '%Y-%m') as month,
        COALESCE(SUM(amount), 0) as total_spending,
        COUNT(DISTINCT id) as transaction_count
    FROM finance_spending 
    WHERE DATE(spending_date) BETWEEN DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND CURDATE()
    GROUP BY DATE_FORMAT(spending_date, '%Y-%m')
    ORDER BY month DESC
");
$finance_spending_stmt->execute();
$finance_spending_data = $finance_spending_stmt->fetchAll();

// TODO: Uncomment and use this query when topup_transactions table is created
/*
$finance_stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COALESCE(SUM(amount), 0) as total_topup_spending,
        COUNT(DISTINCT id) as transaction_count,
        COUNT(DISTINCT user_id) as unique_users
    FROM topup_transactions 
    WHERE DATE(created_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND CURDATE()
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
");
$finance_stmt->execute();
$finance_data = $finance_stmt->fetchAll();
*/

include __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Closing Report</h1>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-primary btn-lg" onclick="printReport()">
                <i class="bi bi-printer me-2"></i>Print Report
            </button>
            <form class="d-flex align-items-center gap-2" method="get">
                <label class="fw-bold">Cost Month:</label>
                <input type="month" name="cost_month" class="form-control form-control-lg" value="<?= htmlspecialchars($cost_month) ?>">
                <label class="fw-bold">From:</label>
                <select name="from_year" class="form-select form-control-lg">
                    <option value="">All Years</option>
                    <?php for ($y = 2020; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $from_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
                <label class="fw-bold">To:</label>
                <select name="to_year" class="form-select form-control-lg">
                    <option value="">All Years</option>
                    <?php for ($y = 2020; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $to_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
                <select name="month" class="form-select form-control-lg">
                    <option value="">All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                    <?php endfor; ?>
                </select>
                <button class="btn btn-outline-primary btn-lg" type="submit">Filter</button>
            </form>
        </div>
    </div>

    <!-- Cost Products Section -->
    <div class="card shadow-sm mb-3" id="costProductsSection">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                Products for Cost Month: <?= date('F Y', strtotime($cost_month . '-01')) ?>
                <span class="badge bg-info ms-2"><?= count($cost_products) ?> Products</span>
            </h5>
            <button class="btn btn-outline-secondary btn-sm" onclick="toggleCostProducts()">
                <i class="bi bi-eye-slash" id="toggleIcon"></i> Hide
            </button>
        </div>
        <div class="card-body p-0" id="costProductsBody">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Product Name</th>
                            <th class="text-end">Selling Price</th>
                            <th class="text-end">Original Cost</th>
                            <th class="text-end">Supplier Cost</th>
                            <th class="text-end">Shipping Cost</th>
                            <th class="text-end">Other Costs</th>
                            <th class="text-end">Total Cost</th>
                            <th class="text-end">Profit/Unit</th>
                            <th class="text-end">Margin %</th>
                            <th>Status</th>
                            <th>Updated By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cost_products as $product): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?= htmlspecialchars($product['name']) ?></div>
                                    <?php if (!empty($product['notes'])): ?>
                                        <small class="text-muted"><?= htmlspecialchars(substr($product['notes'], 0, 50)) ?>...</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">$<?= number_format($product['selling_price'], 2) ?></td>
                                <td class="text-end">
                                    <span class="<?= $product['original_cost'] > 0 ? 'text-success' : 'text-muted' ?>">
                                        $<?= number_format($product['original_cost'], 2) ?>
                                    </span>
                                </td>
                                <td class="text-end">$<?= number_format($product['supplier_cost'], 2) ?></td>
                                <td class="text-end">$<?= number_format($product['shipping_cost'], 2) ?></td>
                                <td class="text-end">$<?= number_format($product['other_costs'], 2) ?></td>
                                <td class="text-end fw-semibold">$<?= number_format($product['total_cost'], 2) ?></td>
                                <td class="text-end">
                                    <span class="<?= $product['profit_per_unit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                        $<?= number_format($product['profit_per_unit'], 2) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <span class="badge bg-<?= $product['profit_percentage'] >= 30 ? 'success' : ($product['profit_percentage'] >= 15 ? 'warning' : 'danger') ?>">
                                        <?= number_format($product['profit_percentage'], 1) ?>%
                                    </span>
                                </td>
                                <td>
                                    <?php if ($product['needs_update']): ?>
                                        <span class="badge bg-warning">Needs Update</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Updated</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($product['updated_by_name'] ?? '-') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td>TOTALS</td>
                            <td class="text-end">
                                $<?= number_format(array_sum(array_column($cost_products, 'selling_price')), 2) ?>
                            </td>
                            <td class="text-end">
                                $<?= number_format(array_sum(array_column($cost_products, 'original_cost')), 2) ?>
                            </td>
                            <td class="text-end">
                                $<?= number_format(array_sum(array_column($cost_products, 'supplier_cost')), 2) ?>
                            </td>
                            <td class="text-end">
                                $<?= number_format(array_sum(array_column($cost_products, 'shipping_cost')), 2) ?>
                            </td>
                            <td class="text-end">
                                $<?= number_format(array_sum(array_column($cost_products, 'other_costs')), 2) ?>
                            </td>
                            <td class="text-end fw-semibold">
                                $<?= number_format(array_sum(array_column($cost_products, 'total_cost')), 2) ?>
                            </td>
                            <td class="text-end">
                                $<?= number_format(array_sum(array_column($cost_products, 'profit_per_unit')), 2) ?>
                            </td>
                            <td class="text-end">
                                <?= number_format(array_sum(array_column($cost_products, 'profit_percentage')) / count($cost_products), 1) ?>%
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                        
                        <?php if (empty($cost_products)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
                                    <i class="bi bi-info-circle me-2"></i>
                                    No products found for the selected cost month. 
                                    <a href="product_costs.php?month=<?= htmlspecialchars($cost_month) ?>">Add products here</a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Delivery Cost Summary -->
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light">
            <h5 class="mb-0">Delivery Cost Summary(អត់គណនាcancel&return)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-end">Total Amount</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Delivery Cost</th>
                            <th class="text-end">Net Total (Amount - Delivery)</th>
                            <th class="text-end">Delivery %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_total_amount = 0;
                        $grand_order_count = 0;
                        $grand_delivery_cost = 0;
                        $grand_net_total = 0;

                        foreach ($delivery_data as $delivery): 
                            $delivery_percentage = $delivery['total_amount'] > 0 ? ($delivery['total_delivery_cost'] / $delivery['total_amount']) * 100 : 0;
                            
                            $grand_total_amount += $delivery['total_amount'];
                            $grand_order_count += $delivery['order_count'];
                            $grand_delivery_cost += $delivery['total_delivery_cost'];
                            $grand_net_total += $delivery['net_total'];
                        ?>
                            <tr>
                                <td><strong><?= date('F Y', strtotime($delivery['month'] . '-01')) ?></strong></td>
                                <td class="text-end fw-bold">$<?= number_format($delivery['total_amount'], 2) ?></td>
                                <td class="text-end"><?= number_format($delivery['order_count']) ?></td>
                                <td class="text-end text-warning">$<?= number_format($delivery['total_delivery_cost'], 2) ?></td>
                                <td class="text-end text-success fw-bold">$<?= number_format($delivery['net_total'], 2) ?></td>
                                <td class="text-end">
                                    <span class="badge bg-<?= $delivery_percentage <= 5 ? 'success' : ($delivery_percentage <= 10 ? 'warning' : 'danger') ?>">
                                        <?= number_format($delivery_percentage, 1) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($delivery_data)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    <i class="bi bi-info-circle me-2"></i>
                                    No delivery data found for the selected period.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold" style="font-size: 15px;">
                            <td>GRAND TOTALS</td>
                            <td class="text-end">$<?= number_format($grand_total_amount, 2) ?></td>
                            <td class="text-end"><?= number_format($grand_order_count) ?></td>
                            <td class="text-end text-warning">$<?= number_format($grand_delivery_cost, 2) ?></td>
                            <td class="text-end text-success">$<?= number_format($grand_net_total, 2) ?></td>
                            <td class="text-end">
                                <?php 
                                $grand_delivery_percentage = $grand_total_amount > 0 ? ($grand_delivery_cost / $grand_total_amount) * 100 : 0;
                                ?>
                                <span class="badge bg-<?= $grand_delivery_percentage <= 5 ? 'success' : ($grand_delivery_percentage <= 10 ? 'warning' : 'danger') ?>">
                                    <?= number_format($grand_delivery_percentage, 1) ?>%
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Finance Summary -->
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light">
            <h5 class="mb-0">Finance Summary - Top-up Spending</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-end">Total Top-up Spending</th>
                            <th class="text-end">Transactions</th>
                            <th class="text-end">Unique Users</th>
                            <th class="text-end">Avg per Transaction</th>
                            <th class="text-end">Avg per User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_topup_spending = 0;
                        $grand_transaction_count = 0;
                        $grand_unique_users = 0;

                        foreach ($finance_data as $finance): 
                            $avg_per_transaction = $finance['transaction_count'] > 0 ? $finance['total_topup_spending'] / $finance['transaction_count'] : 0;
                            $avg_per_user = $finance['unique_users'] > 0 ? $finance['total_topup_spending'] / $finance['unique_users'] : 0;
                            
                            $grand_topup_spending += $finance['total_topup_spending'];
                            $grand_transaction_count += $finance['transaction_count'];
                            $grand_unique_users += $finance['unique_users'];
                        ?>
                            <tr>
                                <td><strong><?= date('F Y', strtotime($finance['month'] . '-01')) ?></strong></td>
                                <td class="text-end fw-bold text-info">$<?= number_format($finance['total_topup_spending'], 2) ?></td>
                                <td class="text-end"><?= number_format($finance['transaction_count']) ?></td>
                                <td class="text-end"><?= number_format($finance['unique_users']) ?></td>
                                <td class="text-end">$<?= number_format($avg_per_transaction, 2) ?></td>
                                <td class="text-end">$<?= number_format($avg_per_user, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($finance_data)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    <i class="bi bi-info-circle me-2"></i>
                                    No top-up data found for the selected period.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold" style="font-size: 15px;">
                            <td>GRAND TOTALS</td>
                            <td class="text-end text-info">$<?= number_format($grand_topup_spending, 2) ?></td>
                            <td class="text-end"><?= number_format($grand_transaction_count) ?></td>
                            <td class="text-end"><?= number_format($grand_unique_users) ?></td>
                            <td class="text-end">
                                <?php 
                                $grand_avg_per_transaction = $grand_transaction_count > 0 ? $grand_topup_spending / $grand_transaction_count : 0;
                                echo '$' . number_format($grand_avg_per_transaction, 2);
                                ?>
                            </td>
                            <td class="text-end">
                                <?php 
                                $grand_avg_per_user = $grand_unique_users > 0 ? $grand_topup_spending / $grand_unique_users : 0;
                                echo '$' . number_format($grand_avg_per_user, 2);
                                ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Monthly Finance Summary -->
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light">
            <h5 class="mb-0">Monthly Finance Summary (Top-up vs Spending)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-end">Total Top-up</th>
                            <th class="text-end">Total Spending</th>
                            <th class="text-end">Balance Each Month</th>
                            <th class="text-end">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_topup = 0;
                        $grand_spending = 0;
                        $grand_balance = 0;

                        // Create associative arrays for easier merging
                        $finance_by_month = [];
                        foreach ($finance_data as $finance) {
                            $finance_by_month[$finance['month']] = $finance;
                        }

                        $spending_by_month = [];
                        foreach ($finance_spending_data as $spending) {
                            $spending_by_month[$spending['month']] = $spending;
                        }

                        // Get all unique months
                        $all_months = array_unique(array_merge(
                            array_keys($finance_by_month),
                            array_keys($spending_by_month)
                        ));
                        rsort($all_months); // Sort descending (newest first)

                        // Calculate running balance (cumulative from oldest to newest)
                        $running_balance = 0;
                        $sorted_months_asc = $all_months;
                        sort($sorted_months_asc); // Sort ascending for running balance calculation
                        
                        $monthly_balances = [];
                        foreach ($sorted_months_asc as $month) {
                            $monthly_topup = $finance_by_month[$month]['total_topup_spending'] ?? 0;
                            $monthly_spending = $spending_by_month[$month]['total_spending'] ?? 0;
                            $monthly_change = $monthly_topup - $monthly_spending;
                            $running_balance += $monthly_change;
                            $monthly_balances[$month] = [
                                'topup' => $monthly_topup,
                                'spending' => $monthly_spending,
                                'change' => $monthly_change,
                                'running_balance' => $running_balance
                            ];
                        }

                        // Display in descending order (newest first)
                        foreach ($all_months as $month): 
                            $data = $monthly_balances[$month];
                            $grand_topup += $data['topup'];
                            $grand_spending += $data['spending'];
                        ?>
                            <tr>
                                <td><strong><?= date('F Y', strtotime($month . '-01')) ?></strong></td>
                                <td class="text-end text-info">$<?= number_format($data['topup'], 2) ?></td>
                                <td class="text-end text-warning">$<?= number_format($data['spending'], 2) ?></td>
                                <td class="text-end fw-bold <?= $data['running_balance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                    $<?= number_format($data['running_balance'], 2) ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($data['running_balance'] >= 0): ?>
                                        <span class="badge bg-success">Positive</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Negative</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($all_months)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="bi bi-info-circle me-2"></i>
                                    No finance data found for the selected period.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold" style="font-size: 15px;">
                            <td>GRAND TOTALS</td>
                            <td class="text-end text-info">$<?= number_format($grand_topup, 2) ?></td>
                            <td class="text-end text-warning">$<?= number_format($grand_spending, 2) ?></td>
                            <td class="text-end fw-bold <?= end($monthly_balances)['running_balance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                $<?= number_format(end($monthly_balances)['running_balance'], 2) ?>
                            </td>
                            <td class="text-end">
                                <?php 
                                $final_balance = end($monthly_balances)['running_balance'] ?? 0;
                                if ($final_balance >= 0): ?>
                                    <span class="badge bg-success">Positive</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Negative</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Monthly Report -->
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light">
            <h5 class="mb-0">Monthly Report</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-end">Products</th>
                            <th class="text-end">Original Cost</th>
                            <th class="text-end">Selling Cost</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Items Sold</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Net Revenue</th>
                            <th class="text-end">Total Original Cost</th>
                            <th class="text-end">Total Spending</th>
                            <th class="text-end">Net Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_products = 0;
                        $total_original_cost = 0;
                        $total_selling_cost = 0;
                        $total_orders = 0;
                        $total_items = 0;
                        $total_revenue = 0;
                        $total_discount = 0;
                        $total_net_revenue = 0;
                        $total_original_cost_sum = 0;
                        $total_spending_sum = 0;
                        $total_net_profit = 0;

                        foreach ($monthly_report_data as $month): 
                            // Get product costs for this month
                            $month_products_stmt = $pdo->prepare("
                                SELECT 
                                    COUNT(DISTINCT p.id) as product_count,
                                    COALESCE(SUM(pc.original_cost), 0) as total_original_cost,
                                    COALESCE(SUM(COALESCE(pc.selling_price, p.cost)), 0) as total_selling_cost,
                                    COALESCE(SUM(pc.original_cost * COALESCE(oi_agg.total_quantity, 0)), 0) as cost_of_goods_sold
                                FROM products p
                                LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
                                LEFT JOIN (
                                    SELECT oi.product_id, SUM(oi.quantity) as total_quantity
                                    FROM order_items oi
                                    INNER JOIN orders o ON oi.order_id = o.id
                                    INNER JOIN print_jobs pj ON pj.order_id = o.id
                                    WHERE DATE_FORMAT(pj.printed_at, '%Y-%m') = ?
                                    AND o.status != 'cancelled' AND o.is_cancelled = 0 AND o.is_returned = 0
                                    GROUP BY oi.product_id
                                ) oi_agg ON p.id = oi_agg.product_id
                                WHERE p.id IN (
                                    SELECT DISTINCT product_id FROM product_costs WHERE month_year <= ?
                                )
                            ");
                            $month_products_stmt->execute([$month['month'], $month['month'], $month['month']]);
                            $month_products = $month_products_stmt->fetch() ?: ['product_count' => 0, 'total_original_cost' => 0, 'total_selling_cost' => 0, 'cost_of_goods_sold' => 0];

                            // Get spending for this month
                            $month_spending_stmt = $pdo->prepare("
                                SELECT COALESCE(SUM(amount), 0) as total_spending
                                FROM finance_spending
                                WHERE DATE_FORMAT(spending_date, '%Y-%m') = ?
                            ");
                            $month_spending_stmt->execute([$month['month']]);
                            $month_spending = $month_spending_stmt->fetch()['total_spending'] ?? 0;

                            // Calculate values
                            $revenue = $month['revenue'];
                            $net_revenue = $month['net_revenue'];
                            $net_profit = $net_revenue - ($month_products['cost_of_goods_sold'] ?? 0) - $month_spending;

                            // Accumulate totals
                            $total_products += $month_products['product_count'];
                            $total_original_cost += $month_products['total_original_cost'];
                            $total_selling_cost += $month_products['total_selling_cost'];
                            $total_orders += $month['order_count'];
                            $total_items += 0; // We'll need to fix this
                            $total_revenue += $revenue;
                            $total_discount += $month['discount'];
                            $total_net_revenue += $net_revenue;
                            $total_original_cost_sum += $month_products['cost_of_goods_sold'] ?? 0;
                            $total_spending_sum += $month_spending;
                            $total_net_profit += $net_profit;
                        ?>
                            <tr>
                                <td><strong><?= date('F Y', strtotime($month['month'] . '-01')) ?></strong></td>
                                <td class="text-end"><?= number_format($month_products['product_count']) ?></td>
                                <td class="text-end">$<?= number_format($month_products['total_original_cost'], 2) ?></td>
                                <td class="text-end">$<?= number_format($month_products['total_selling_cost'], 2) ?></td>
                                <td class="text-end"><?= number_format($month['order_count']) ?></td>
                                <td class="text-end"><?= number_format($items_sold_data[$month['month']] ?? 0) ?></td>
                                <td class="text-end">$<?= number_format($revenue, 2) ?></td>
                                <td class="text-end text-danger">-$<?= number_format($month['discount'], 2) ?></td>
                                <td class="text-end fw-bold">$<?= number_format($net_revenue, 2) ?></td>
                                <td class="text-end">$<?= number_format($month_products['cost_of_goods_sold'] ?? 0, 2) ?></td>
                                <td class="text-end">$<?= number_format($month_spending, 2) ?></td>
                                <td class="text-end text-success fw-bold">$<?= number_format($net_profit, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold" style="font-size: 15px;">
                            <td>TOTALS</td>
                            <td class="text-end"><?= number_format($total_products) ?></td>
                            <td class="text-end">$<?= number_format($total_original_cost, 2) ?></td>
                            <td class="text-end">$<?= number_format($total_selling_cost, 2) ?></td>
                            <td class="text-end"><?= number_format($total_orders) ?></td>
                            <td class="text-end"><?= number_format($total_items) ?></td>
                            <td class="text-end">$<?= number_format($total_revenue, 2) ?></td>
                            <td class="text-end text-danger">-$<?= number_format($total_discount, 2) ?></td>
                            <td class="text-end">$<?= number_format($total_net_revenue, 2) ?></td>
                            <td class="text-end">$<?= number_format($total_original_cost_sum, 2) ?></td>
                            <td class="text-end">$<?= number_format($total_spending_sum, 2) ?></td>
                            <td class="text-end text-success">$<?= number_format($total_net_profit, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Daily Breakdown for Monthly Report -->
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Daily Breakdown</h5>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleDailyBreakdown()">
                <i class="bi bi-eye" id="dailyToggleIcon"></i> Show/Hide
            </button>
        </div>
        <div class="card-body p-0" id="dailyBreakdownBody" style="display: none;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th></th>
                            <th>Month</th>
                            <th>Day</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Net Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $daily_total_orders = 0;
                        $daily_total_revenue = 0;
                        $daily_total_discount = 0;
                        $daily_total_net_revenue = 0;

                        foreach ($daily_by_month as $month => $daily_data): 
                            $month_rowspan = count($daily_data);
                        ?>
                            <tr class="table-secondary">
                                <td colspan="8" class="fw-bold">
                                    <i class="bi bi-calendar3 me-2"></i><?= date('F Y', strtotime($month . '-01')) ?>
                                </td>
                            </tr>
                            <?php foreach ($daily_data as $daily): 
                                // Accumulate totals
                                $daily_total_orders += $daily['order_count'];
                                $daily_total_revenue += $daily['revenue'];
                                $daily_total_discount += $daily['discount'];
                                $daily_total_net_revenue += $daily['net_revenue'];
                                
                                $has_orders = isset($orders_by_day[$daily['day']]) && count($orders_by_day[$daily['day']]) > 0;
                                $row_id = 'daily_row_' . str_replace('-', '_', $daily['day']);
                                $details_id = 'daily_details_' . str_replace('-', '_', $daily['day']);
                            ?>
                                <tr>
                                    <td>
                                        <?php if ($has_orders): ?>
                                            <button class="btn btn-sm btn-outline-secondary" onclick="toggleDailyDetails('<?= $details_id ?>', this)">
                                                <i class="bi bi-plus-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td></td>
                                    <td><?= date('M d', strtotime($daily['day'])) ?></td>
                                    <td class="text-end"><?= number_format($daily['order_count']) ?></td>
                                    <td class="text-end">$<?= number_format($daily['revenue'], 2) ?></td>
                                    <td class="text-end text-danger">-$<?= number_format($daily['discount'], 2) ?></td>
                                    <td class="text-end fw-bold">$<?= number_format($daily['net_revenue'], 2) ?></td>
                                </tr>
                                <?php if ($has_orders): ?>
                                    <tr id="<?= $details_id ?>" style="display: none;">
                                        <td></td>
                                        <td colspan="8">
                                            <div class="table-responsive">
                                                <table class="table table-sm table-borderless mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Order Code</th>
                                                            <th>Products</th>
                                                            <th class="text-end">Items</th>
                                                            <th class="text-end">Revenue</th>
                                                            <th class="text-end">Discount</th>
                                                            <th class="text-end">Net Amount</th>
                                                            <th class="text-center">Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($orders_by_day[$daily['day']] as $order): ?>
                                                            <tr class="<?= ($order['status'] == 'cancelled' || $order['is_cancelled'] == 1 || $order['is_returned'] == 1) ? 'table-danger' : '' ?>">
                                                                <td><small class="text-muted">#<?= htmlspecialchars($order['order_code']) ?></small></td>
                                                                <td><small title="<?= htmlspecialchars($order['product_names']) ?>"><?= htmlspecialchars(substr($order['product_names'], 0, 50) . (strlen($order['product_names']) > 50 ? '...' : '')) ?></small></td>
                                                                <td class="text-end"><small><?= number_format($order['items_count']) ?></small></td>
                                                                <td class="text-end"><small>$<?= number_format($order['revenue'], 2) ?></small></td>
                                                                <td class="text-end text-danger"><small>-$<?= number_format($order['discount'], 2) ?></small></td>
                                                                <td class="text-end fw-bold"><small>$<?= number_format($order['total_amount'], 2) ?></small></td>
                                                                <td class="text-center">
                                                                    <?php if ($order['status'] == 'cancelled' || $order['is_cancelled'] == 1): ?>
                                                                        <span class="badge bg-danger">Cancelled</span>
                                                                    <?php elseif ($order['is_returned'] == 1): ?>
                                                                        <span class="badge bg-warning">Returned</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-success">Valid</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold" style="font-size: 15px;">
                            <td colspan="3">DAILY TOTALS</td>
                            <td class="text-end"><?= number_format($daily_total_orders) ?></td>
                            <td class="text-end">$<?= number_format($daily_total_revenue, 2) ?></td>
                            <td class="text-end text-danger">-$<?= number_format($daily_total_discount, 2) ?></td>
                            <td class="text-end">$<?= number_format($daily_total_net_revenue, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-header bg-light">
            <h5 class="mb-0">Comprehensive Monthly Closing Report</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-end">Products</th>
                            <th class="text-end">Original Cost</th>
                            <th class="text-end">Selling Cost</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Items Sold</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Net Revenue</th>
                            <th class="text-end">Total Original Cost</th>
                            <th class="text-end">Total Spending</th>
                            <th class="text-end">Net Profit</th>
                            <th class="text-end">Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_products_count = 0;
                        $total_original_cost_sum = 0;
                        $total_selling_cost_sum = 0;
                        $total_orders_sum = 0;
                        $total_items_sum = 0;
                        $total_revenue_sum = 0;
                        $total_discount_sum = 0;
                        $total_net_revenue_sum = 0;
                        $total_cost_sum = 0;
                        $total_spending_sum = 0;
                        $total_net_profit_sum = 0;

                        foreach ($monthly_report_data as $month): 
                            // Revenue calculations (already correct from SQL)
                            $revenue = $month['revenue']; // Revenue = net revenue + discount (gross revenue)
                            $net_revenue = $month['net_revenue']; // Net revenue = actual money received from customer
                            
                            // Get spending for this month
                            $month_spending_stmt = $pdo->prepare("
                                SELECT COALESCE(SUM(amount), 0) as total_spending
                                FROM finance_spending
                                WHERE DATE_FORMAT(spending_date, '%Y-%m') = ?
                            ");
                            $month_spending_stmt->execute([$month['month']]);
                            $month_spending = $month_spending_stmt->fetch()['total_spending'] ?? 0;
                            
                            $margin = $net_revenue > 0 ? (($net_revenue - ($month_products['cost_of_goods_sold'] ?? 0) - $month_spending) / $net_revenue) * 100 : 0;
                            $net_profit = $net_revenue - ($month_products['cost_of_goods_sold'] ?? 0) - $month_spending;
                            
                            // Get product counts and costs for this month (matching Cost Management system)
                            $month_products_stmt = $pdo->prepare("
                                SELECT 
                                    COUNT(DISTINCT p.id) as product_count,
                                    COALESCE(SUM(pc.original_cost), 0) as total_original_cost,
                                    COALESCE(SUM(COALESCE(pc.selling_price, p.cost)), 0) as total_selling_cost
                                FROM products p
                                LEFT JOIN product_costs pc ON p.id = pc.product_id 
                                    AND pc.month_year = ?
                                WHERE p.id IN (
                                    SELECT DISTINCT product_id FROM product_costs WHERE month_year <= ?
                                )
                            ");
                            $month_products_stmt->execute([$month['month'], $month['month']]);
                            $month_products = $month_products_stmt->fetch() ?: ['product_count' => 0, 'total_original_cost' => 0, 'total_selling_cost' => 0, 'cost_of_goods_sold' => 0];
                            
                            $total_products_count += $month_products['product_count'];
                            $total_original_cost_sum += $month_products['cost_of_goods_sold'] ?? 0;
                            $total_selling_cost_sum += $month_products['total_selling_cost'];
                            $total_orders_sum += $month['order_count'];
                            $total_items_sum += $items_sold_data[$month['month']] ?? 0;
                            $total_revenue_sum += $revenue; // Sum of gross revenue
                            $total_discount_sum += $month['discount']; // Sum of discounts
                            $total_net_revenue_sum += $net_revenue; // Sum of actual money received
                            $total_cost_sum += $month_products['total_original_cost'];
                            $total_spending_sum += $month_spending;
                            $total_net_profit_sum += $net_profit;
                        ?>
                            <tr>
                                <td><strong><?= date('F Y', strtotime($month['month'] . '-01')) ?></strong></td>
                                <td class="text-end"><?= number_format($month_products['product_count']) ?></td>
                                <td class="text-end text-warning">$<?= number_format($month_products['total_original_cost'], 2) ?></td>
                                <td class="text-end text-info">$<?= number_format($month_products['total_selling_cost'], 2) ?></td>
                                <td class="text-end"><?= number_format($month['order_count']) ?></td>
                                <td class="text-end"><?= number_format($items_sold_data[$month['month']] ?? 0) ?></td>
                                <td class="text-end">$<?= number_format($revenue, 2) ?></td> <!-- Revenue (gross) -->
                                <td class="text-end text-danger">-$<?= number_format($month['discount'], 2) ?></td> <!-- Discount -->
                                <td class="text-end fw-bold">$<?= number_format($net_revenue, 2) ?></td> <!-- Net Revenue (actual money received) -->
                                <td class="text-end">$<?= number_format($month_products['cost_of_goods_sold'] ?? 0, 2) ?></td>
                                <td class="text-end">$<?= number_format($month_spending, 2) ?></td>
                                <td class="text-end text-success fw-bold">$<?= number_format($net_profit, 2) ?></td>
                                <td class="text-end">
                                    <span class="badge bg-<?= $margin >= 30 ? 'success' : ($margin >= 15 ? 'warning' : 'danger') ?>">
                                        <?= number_format($margin, 1) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold" style="font-size: 15px;">
                            <td>TOTALS</td>
                            <td class="text-end"><?= number_format($total_products_count) ?></td>
                            <td class="text-end text-warning">$<?= number_format($total_original_cost_sum, 2) ?></td>
                            <td class="text-end text-info">$<?= number_format($total_selling_cost_sum, 2) ?></td>
                            <td class="text-end"><?= number_format($total_orders_sum) ?></td>
                            <td class="text-end"><?= number_format($total_items_sum) ?></td>
                            <td class="text-end">$<?= number_format($total_revenue_sum, 2) ?></td>
                            <td class="text-end text-danger">-$<?= number_format($total_discount_sum, 2) ?></td>
                            <td class="text-end">$<?= number_format($total_net_revenue_sum, 2) ?></td>
                            <td class="text-end">$<?= number_format($total_cost_sum, 2) ?></td>
                            <td class="text-end">$<?= number_format($total_spending_sum, 2) ?></td>
                            <td class="text-end text-success">$<?= number_format($total_net_profit_sum, 2) ?></td>
                            <td class="text-end">
                                <?php 
                                $overall_margin = $total_net_revenue_sum > 0 ? ($total_net_profit_sum / $total_net_revenue_sum) * 100 : 0;
                                ?>
                                <span class="badge bg-<?= $overall_margin >= 30 ? 'success' : ($overall_margin >= 15 ? 'warning' : 'danger') ?>">
                                    <?= number_format($overall_margin, 1) ?>%
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function toggleCostProducts() {
    const body = document.getElementById('costProductsBody');
    const icon = document.getElementById('toggleIcon');
    const button = icon.parentElement;
    
    if (body.style.display === 'none') {
        body.style.display = 'block';
        icon.className = 'bi bi-eye-slash';
        button.innerHTML = '<i class="bi bi-eye-slash" id="toggleIcon"></i> Hide';
    } else {
        body.style.display = 'none';
        icon.className = 'bi bi-eye';
        button.innerHTML = '<i class="bi bi-eye" id="toggleIcon"></i> Show';
    }
}

function toggleDailyBreakdown() {
    const body = document.getElementById('dailyBreakdownBody');
    const icon = document.getElementById('dailyToggleIcon');
    const button = icon.parentElement;
    
    if (body.style.display === 'none') {
        body.style.display = 'block';
        icon.className = 'bi bi-eye-slash';
        button.innerHTML = '<i class="bi bi-eye-slash" id="dailyToggleIcon"></i> Hide';
    } else {
        body.style.display = 'none';
        icon.className = 'bi bi-eye';
        button.innerHTML = '<i class="bi bi-eye" id="dailyToggleIcon"></i> Show';
    }
}

function toggleDailyDetails(detailsId, button) {
    const detailsRow = document.getElementById(detailsId);
    const icon = button.querySelector('i');
    
    if (detailsRow.style.display === 'none') {
        detailsRow.style.display = '';
        icon.className = 'bi bi-dash-circle';
    } else {
        detailsRow.style.display = 'none';
        icon.className = 'bi bi-plus-circle';
    }
}

function printReport() {
    const table = document.querySelector('table');
    const rows = table.querySelectorAll('tr');
    let tableHTML = '<table>';
    
    rows.forEach((row, index) => {
        const cells = row.querySelectorAll('th, td');
        let rowHTML = '<tr>';
        
        cells.forEach(cell => {
            rowHTML += cell.outerHTML;
        });
        
        rowHTML += '</tr>';
        tableHTML += rowHTML;
    });
    
    tableHTML += '</table>';
    
    const html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Closing Report</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 20px;
                    font-size: 12px;
                }
                h2 {
                    text-align: center;
                    margin-bottom: 20px;
                    color: #333;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                th, td {
                    border: 1px solid #000 !important;
                    padding: 8px;
                    text-align: left;
                    vertical-align: top;
                }
                th {
                    background-color: #f0f0f0 !important;
                    font-weight: bold;
                }
                .text-end {
                    text-align: right !important;
                }
                .fw-bold {
                    font-weight: bold !important;
                }
                .badge {
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-size: 10px;
                    font-weight: bold;
                }
                .bg-success {
                    background-color: #28a745 !important;
                    color: white !important;
                }
                .bg-warning {
                    background-color: #ffc107 !important;
                    color: black !important;
                }
                .bg-danger {
                    background-color: #dc3545 !important;
                    color: white !important;
                }
                @media print {
                    body { margin: 10px; }
                    table { page-break-inside: auto; }
                    tr { page-break-inside: avoid; page-break-after: auto; }
                }
            </style>
        </head>
        <body>
            <h2>Closing Report</h2>
            ${tableHTML}
        </body>
        </html>
    `;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
    printWindow.close();
}
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
