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

// Default: show last 12 months
if (empty($_GET['from_year']) && empty($_GET['to_year']) && empty($_GET['month']) && empty($_GET['cost_month'])) {
    $where_conditions[] = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
} else {
    // From Year filter
    if (!empty($from_year)) {
        $where_conditions[] = "YEAR(o.created_at) >= ?";
        $params[] = $from_year;
    }
    
    // To Year filter
    if (!empty($to_year)) {
        $where_conditions[] = "YEAR(o.created_at) <= ?";
        $params[] = $to_year;
    }

    if (!empty($month)) {
        $where_conditions[] = "MONTH(o.created_at) = ?";
        $params[] = $month;
    }
}

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
    WHERE p.id IN (
        SELECT DISTINCT product_id FROM product_costs WHERE month_year <= ?
    )
    ORDER BY p.name
");
$cost_products_stmt->execute([$cost_month, $cost_month]);
$cost_products = $cost_products_stmt->fetchAll();

// Get monthly data for comprehensive analysis
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(o.created_at, '%Y-%m') as month,
        COUNT(DISTINCT o.id) as order_count,
        SUM(oi.quantity) as total_items,
        COALESCE(SUM(oi.line_total), 0) as total_revenue,
        COALESCE(SUM(o.discount), 0) as total_discount,
        COALESCE(SUM(oi.quantity * COALESCE(pc.total_cost, 0)), 0) as total_cost,
        COALESCE(SUM(oi.line_total - oi.quantity * COALESCE(pc.total_cost, 0)), 0) as total_profit
    FROM orders o
    INNER JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN product_costs pc ON oi.product_id = pc.product_id 
        AND pc.month_year = DATE_FORMAT(o.created_at, '%Y-%m')
    WHERE {$where_clause} AND o.status != 'cancelled'
    GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
    ORDER BY month DESC
");
$stmt->execute($params);
$monthly_data = $stmt->fetchAll();

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
                            <th class="text-end">Original Cost</th>
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
                        $total_net_profit_sum = 0;

                        foreach ($monthly_data as $month): 
                            $net_revenue = $month['total_revenue'] - $month['total_discount'];
                            $margin = $net_revenue > 0 ? (($net_revenue - $month['total_cost']) / $net_revenue) * 100 : 0;
                            $net_profit = $net_revenue - $month['total_cost'];
                            
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
                            $month_products = $month_products_stmt->fetch();
                            
                            $total_products_count += $month_products['product_count'];
                            $total_original_cost_sum += $month_products['total_original_cost'];
                            $total_selling_cost_sum += $month_products['total_selling_cost'];
                            $total_orders_sum += $month['order_count'];
                            $total_items_sum += $month['total_items'];
                            $total_revenue_sum += $month['total_revenue'];
                            $total_discount_sum += $month['total_discount'];
                            $total_net_revenue_sum += $net_revenue;
                            $total_cost_sum += $month['total_cost'];
                            $total_net_profit_sum += $net_profit;
                        ?>
                            <tr>
                                <td><strong><?= date('F Y', strtotime($month['month'] . '-01')) ?></strong></td>
                                <td class="text-end"><?= number_format($month_products['product_count']) ?></td>
                                <td class="text-end text-warning">$<?= number_format($month_products['total_original_cost'], 2) ?></td>
                                <td class="text-end text-info">$<?= number_format($month_products['total_selling_cost'], 2) ?></td>
                                <td class="text-end"><?= number_format($month['order_count']) ?></td>
                                <td class="text-end"><?= number_format($month['total_items']) ?></td>
                                <td class="text-end">$<?= number_format($month['total_revenue'], 2) ?></td>
                                <td class="text-end text-danger">-$<?= number_format($month['total_discount'], 2) ?></td>
                                <td class="text-end fw-bold">$<?= number_format($net_revenue, 2) ?></td>
                                <td class="text-end">$<?= number_format($month['total_cost'], 2) ?></td>
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
