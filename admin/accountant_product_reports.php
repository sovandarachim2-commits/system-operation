<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'accountant_product.view');

$pdo = get_db_connection();

// Get date filters
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$category_filter = (int)($_GET['category_filter'] ?? 0);

// Get product categories (if exists)
$categories = [];
try {
    $categoriesStmt = $pdo->query('SELECT id, name FROM stock_categories ORDER BY name');
    $categories = $categoriesStmt->fetchAll();
} catch (Exception $e) {
    // Stock categories table might not exist, continue without it
}

// Get comprehensive product performance data
$sql = '
    SELECT 
        p.id as product_id,
        p.name as product_name,
        p.cost as product_cost,
        COUNT(DISTINCT oi.order_id) as order_count,
        SUM(oi.quantity) as total_quantity,
        SUM(oi.line_total) as total_revenue,
        AVG(oi.quantity) as avg_quantity_per_order,
        MIN(oi.quantity) as min_quantity,
        MAX(oi.quantity) as max_quantity,
        COUNT(DISTINCT DATE(o.created_at)) as days_sold,
        MIN(o.created_at) as first_sold,
        MAX(o.created_at) as last_sold,
        SUM(oi.line_total) - SUM(oi.quantity * p.cost) as total_profit,
        AVG(oi.line_total) as avg_revenue_per_order,
        -- Calculate daily average
        CASE 
            WHEN COUNT(DISTINCT DATE(o.created_at)) > 0 
            THEN SUM(oi.quantity) / COUNT(DISTINCT DATE(o.created_at))
            ELSE 0 
        END as daily_avg_quantity
    FROM products p
    LEFT JOIN order_items oi ON p.id = oi.product_id
    LEFT JOIN orders o ON oi.order_id = o.id AND o.is_cancelled = 0
    WHERE (o.created_at IS NULL OR DATE(o.created_at) BETWEEN ? AND ?)
    GROUP BY p.id, p.name, p.cost
    ORDER BY total_revenue DESC
';

$params = [$from, $to];
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Calculate summary statistics
$total_products = count($products);
$active_products = 0;
$total_revenue = 0;
$total_profit = 0;
$total_quantity = 0;
$total_orders = 0;

foreach ($products as $product) {
    if ($product['order_count'] > 0) {
        $active_products++;
    }
    $total_revenue += $product['total_revenue'];
    $total_profit += $product['total_profit'];
    $total_quantity += $product['total_quantity'];
    $total_orders += $product['order_count'];
}

$avg_order_value = $total_orders > 0 ? $total_revenue / $total_orders : 0;
$profit_margin = $total_revenue > 0 ? ($total_profit / $total_revenue) * 100 : 0;

// Get top performing products by different metrics
$top_by_revenue = array_slice($products, 0, 10);
$top_by_quantity = $products;
usort($top_by_quantity, function($a, $b) {
    return $b['total_quantity'] <=> $a['total_quantity'];
});
$top_by_quantity = array_slice($top_by_quantity, 0, 10);

$top_by_orders = $products;
usort($top_by_orders, function($a, $b) {
    return $b['order_count'] <=> $a['order_count'];
});
$top_by_orders = array_slice($top_by_orders, 0, 10);

// Get product performance trend (last 7 days)
$trend_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $stmt = $pdo->prepare('
        SELECT 
            COUNT(DISTINCT oi.product_id) as products_sold,
            COUNT(DISTINCT oi.order_id) as orders,
            SUM(oi.quantity) as total_quantity,
            SUM(oi.line_total) as revenue
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE DATE(o.created_at) = ? AND o.is_cancelled = 0
    ');
    $stmt->execute([$date]);
    $day_data = $stmt->fetch();
    $day_data['date'] = $date;
    $trend_data[] = $day_data;
}

// Get product category performance (if stock categories exist)
$category_performance = [];
if (!empty($categories)) {
    $stmt = $pdo->prepare('
        SELECT 
            sc.name as category_name,
            COUNT(DISTINCT p.id) as total_products,
            COUNT(DISTINCT oi.product_id) as sold_products,
            SUM(oi.quantity) as total_quantity,
            SUM(oi.line_total) as total_revenue,
            AVG(oi.line_total) as avg_revenue
        FROM stock_categories sc
        LEFT JOIN stock_items si ON sc.id = si.category_id
        LEFT JOIN products p ON si.name = p.name
        LEFT JOIN order_items oi ON p.id = oi.product_id
        LEFT JOIN orders o ON oi.order_id = o.id AND o.is_cancelled = 0 AND DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY sc.id, sc.name
        HAVING sold_products > 0
        ORDER BY total_revenue DESC
    ');
    $stmt->execute([$from, $to]);
    $category_performance = $stmt->fetchAll();
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Product Reports</h1>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-success btn-lg" onclick="window.print()">
                <i class="bi bi-printer me-2"></i>Print Report
            </button>
            <button class="btn btn-outline-primary btn-lg" onclick="exportToExcel()">
                <i class="bi bi-file-earmark-excel me-2"></i>Export Excel
            </button>
        </div>
    </div>

    <!-- Date Filter -->
    <form method="get" class="card shadow-sm mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label">From Date</label>
                <input type="date" name="from" class="form-control form-control-lg" value="<?= htmlspecialchars($from) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">To Date</label>
                <input type="date" name="to" class="form-control form-control-lg" value="<?= htmlspecialchars($to) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <button type="submit" class="btn btn-primary btn-lg w-100">Update Report</button>
            </div>
            <div class="col-12 col-md-2">
                <button type="button" class="btn btn-outline-secondary btn-lg w-100" onclick="setDateRange('month')">This Month</button>
            </div>
        </div>
    </form>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card bg-primary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Products</h5>
                    <h3 class="mb-0"><?= number_format($total_products) ?></h3>
                    <small><?= number_format($active_products) ?> active</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-success text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Revenue</h5>
                    <h3 class="mb-0">$<?= number_format($total_revenue, 0) ?></h3>
                    <small>Total sales</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-info text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Profit</h5>
                    <h3 class="mb-0">$<?= number_format($total_profit, 0) ?></h3>
                    <small><?= number_format($profit_margin, 1) ?>% margin</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Items Sold</h5>
                    <h3 class="mb-0"><?= number_format($total_quantity) ?></h3>
                    <small>Total quantity</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-secondary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Orders</h5>
                    <h3 class="mb-0"><?= number_format($total_orders) ?></h3>
                    <small>Product orders</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-dark text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Avg Order</h5>
                    <h3 class="mb-0">$<?= number_format($avg_order_value, 0) ?></h3>
                    <small>Per order</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Performance Trend -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">7-Day Performance Trend</h5>
        </div>
        <div class="card-body">
            <canvas id="trendChart" height="100"></canvas>
        </div>
    </div>

    <!-- Category Performance (if available) -->
    <?php if (!empty($category_performance)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Category Performance</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="text-end">Products</th>
                            <th class="text-end">Sold</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Avg Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($category_performance as $category): ?>
                            <tr>
                                <td><?= htmlspecialchars($category['category_name']) ?></td>
                                <td class="text-end"><?= number_format($category['total_products']) ?></td>
                                <td class="text-end"><?= number_format($category['sold_products']) ?></td>
                                <td class="text-end"><?= number_format($category['total_quantity']) ?></td>
                                <td class="text-end">$<?= number_format($category['total_revenue'], 2) ?></td>
                                <td class="text-end">$<?= number_format($category['avg_revenue'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Top Products Grid -->
    <div class="row g-4 mb-4">
        <!-- Top by Revenue -->
        <div class="col-lg-4">
            <div class="card shadow">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Top by Revenue</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Revenue</th>
                                    <th class="text-end">Orders</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($top_by_revenue, 0, 5) as $product): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($product['product_name']) ?></td>
                                        <td class="text-end">$<?= number_format($product['total_revenue'], 2) ?></td>
                                        <td class="text-end"><?= number_format($product['order_count']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top by Quantity -->
        <div class="col-lg-4">
            <div class="card shadow">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Top by Quantity</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Orders</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($top_by_quantity, 0, 5) as $product): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($product['product_name']) ?></td>
                                        <td class="text-end"><?= number_format($product['total_quantity']) ?></td>
                                        <td class="text-end"><?= number_format($product['order_count']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top by Orders -->
        <div class="col-lg-4">
            <div class="card shadow">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Top by Orders</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Orders</th>
                                    <th class="text-end">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($top_by_orders, 0, 5) as $product): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($product['product_name']) ?></td>
                                        <td class="text-end"><?= number_format($product['order_count']) ?></td>
                                        <td class="text-end">$<?= number_format($product['total_revenue'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Products Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0">Detailed Product Performance (<?= count($products) ?> products)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm" id="productsTable">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Profit</th>
                            <th class="text-end">Margin %</th>
                            <th class="text-end">Avg Qty</th>
                            <th class="text-end">Days Sold</th>
                            <th class="text-end">Daily Avg</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr><td colspan="9" class="text-center py-3">No products found</td></tr>
                        <?php else: ?>
                            <?php foreach ($products as $product): ?>
                                <?php
                                $margin_percent = $product['total_revenue'] > 0 ? ($product['total_profit'] / $product['total_revenue']) * 100 : 0;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($product['product_name']) ?></td>
                                    <td class="text-end"><?= number_format($product['order_count']) ?></td>
                                    <td class="text-end"><?= number_format($product['total_quantity']) ?></td>
                                    <td class="text-end">$<?= number_format($product['total_revenue'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($product['total_profit'], 2) ?></td>
                                    <td class="text-end"><?= number_format($margin_percent, 1) ?>%</td>
                                    <td class="text-end"><?= number_format($product['avg_quantity_per_order'], 1) ?></td>
                                    <td class="text-end"><?= number_format($product['days_sold']) ?></td>
                                    <td class="text-end"><?= number_format($product['daily_avg_quantity'], 1) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th class="text-end">Total:</th>
                            <th class="text-end"><?= number_format($total_orders) ?></th>
                            <th class="text-end"><?= number_format($total_quantity) ?></th>
                            <th class="text-end">$<?= number_format($total_revenue, 2) ?></th>
                            <th class="text-end">$<?= number_format($total_profit, 2) ?></th>
                            <th class="text-end"><?= number_format($profit_margin, 1) ?>%</th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const trendData = <?= json_encode($trend_data) ?>;
    
    const ctx = document.getElementById('trendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: trendData.map(d => new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
            datasets: [{
                label: 'Revenue',
                data: trendData.map(d => d.revenue),
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y'
            }, {
                label: 'Quantity',
                data: trendData.map(d => d.total_quantity),
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y1'
            }, {
                label: 'Products Sold',
                data: trendData.map(d => d.products_sold),
                borderColor: '#ffc107',
                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y2'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Revenue ($)'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Quantity'
                    },
                    grid: {
                        drawOnChartArea: false,
                    }
                },
                y2: {
                    type: 'linear',
                    display: false,
                    position: 'right',
                    grid: {
                        drawOnChartArea: false,
                    }
                }
            }
        }
    });
});

function setDateRange(range) {
    const form = document.querySelector('form');
    
    if (range === 'month') {
        const today = new Date();
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        
        form.querySelector('input[name="from"]').value = firstDay.toISOString().split('T')[0];
        form.querySelector('input[name="to"]').value = today.toISOString().split('T')[0];
    }
    
    form.submit();
}

function exportToExcel() {
    const table = document.getElementById('productsTable');
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        
        for (let j = 0; j < cols.length; j++) {
            const text = cols[j].innerText.replace(/,/g, '');
            rowData.push('"' + text + '"');
        }
        
        csv.push(rowData.join(','));
    }
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'product_report_' + document.querySelector('input[name="from"]').value + '_to_' + document.querySelector('input[name="to"]').value + '.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<style>
@media print {
    .btn, form, .card-header h5 {
        display: none !important;
    }
    
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
        page-break-inside: avoid;
    }
    
    .col-lg-4 {
        float: left;
        width: 33.333%;
    }
}
</style>

<?php include __DIR__ . '/../layout/footer.php'; ?>
