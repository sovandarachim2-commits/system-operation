<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'accountant_daily.view');

$pdo = get_db_connection();

// Get date filters
$from_date = $_GET['from_date'] ?? date('Y-m-d');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'custom';

// Build date range based on filters
$from = $from_date;
$to = $to_date;
$printedOrdersJoin = '(SELECT order_id, MAX(printed_at) AS printed_at FROM print_jobs GROUP BY order_id)';

// Generate title
if ($from_date === $to_date) {
    $title = "Daily Report - " . date('F j, Y', strtotime($from_date));
} else {
    $title = "Report from " . date('F j, Y', strtotime($from_date)) . " to " . date('F j, Y', strtotime($to_date));
}

// Get detailed order data (including cancelled orders)
$stmt = $pdo->prepare('
    SELECT 
        o.id,
        o.order_code,
        o.customer_name,
        o.phone,
        o.location,
        o.total_amount,
        o.discount,
        o.status,
        o.is_cancelled,
        o.is_returned,
        o.payment_method,
        o.created_at,
        o.updated_at,
        pj.printed_at,
        u.name as seller_name,
        p.name as page_name,
        COALESCE(dc.amount, 0) as delivery_cost_amount,
        (
            SELECT MAX(oi2.delivery_by)
            FROM out_items oi2
            WHERE oi2.inv = o.order_code
                AND oi2.delivery_by IS NOT NULL
                AND oi2.delivery_by != \'\'
        ) as delivery_by,
        COUNT(oi.id) as item_count,
        SUM(oi.quantity) as total_quantity,
        GROUP_CONCAT(DISTINCT
            CASE
                WHEN COALESCE(NULLIF(pr.product_type, \'\'), \'normal\') = \'set\' THEN
                    COALESCE((
                        SELECT
                            CASE
                                WHEN COUNT(DISTINCT cb.id) > 1 THEN \'Mix Brand\'
                                ELSE COALESCE(MAX(cb.name), \'No Brand\')
                            END
                        FROM product_sets ps2
                        JOIN product_set_items psi2 ON psi2.product_set_id = ps2.id
                        JOIN products cp ON cp.id = psi2.product_id
                        LEFT JOIN brands cb ON cb.id = cp.brand_id
                        WHERE ps2.set_name = pr.name
                    ), COALESCE(b.name, \'No Brand\'))
                ELSE COALESCE(b.name, \'No Brand\')
            END
            SEPARATOR \', \'
        ) as brand_names,
        GROUP_CONCAT(DISTINCT
            CASE
                WHEN COALESCE(NULLIF(pr.product_type, \'\'), \'normal\') = \'set\' THEN
                    COALESCE((
                        SELECT
                            CASE
                                WHEN COUNT(DISTINCT cb.id) > 1 THEN \'Mix Brand~~#6c757d\'
                                ELSE CONCAT(COALESCE(MAX(cb.name), \'No Brand\'), \'~~\', COALESCE(MAX(cb.color), \'#6c757d\'))
                            END
                        FROM product_sets ps2
                        JOIN product_set_items psi2 ON psi2.product_set_id = ps2.id
                        JOIN products cp ON cp.id = psi2.product_id
                        LEFT JOIN brands cb ON cb.id = cp.brand_id
                        WHERE ps2.set_name = pr.name
                    ), CONCAT(COALESCE(b.name, \'No Brand\'), \'~~\', COALESCE(b.color, \'#6c757d\')))
                ELSE CONCAT(COALESCE(b.name, \'No Brand\'), \'~~\', COALESCE(b.color, \'#6c757d\'))
            END
            SEPARATOR \'||\'
        ) as brand_color_names,
        GROUP_CONCAT(CONCAT(oi.product_id, ":", oi.quantity, ":", oi.line_total, ":", pr.name) ORDER BY oi.id) as items_detail
    FROM orders o
    LEFT JOIN users u ON o.seller_id = u.id
    LEFT JOIN pages p ON o.page_id = p.id
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN products pr ON oi.product_id = pr.id
    LEFT JOIN brands b ON b.id = pr.brand_id
    LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
    WHERE (
        (o.is_cancelled = 1 AND DATE(o.updated_at) BETWEEN ? AND ?)
        OR
        (o.is_cancelled = 0 AND DATE(pj.printed_at) BETWEEN ? AND ? AND pj.printed_at IS NOT NULL)
    )
    GROUP BY o.id
    ORDER BY CASE WHEN o.is_cancelled = 1 THEN o.updated_at ELSE pj.printed_at END DESC
');
$stmt->execute([$from, $to, $from, $to]);
$orders = $stmt->fetchAll();

$brand_filter_options = [];
foreach ($orders as $orderForBrandFilter) {
    $brandTokensForFilter = !empty($orderForBrandFilter['brand_color_names']) ? explode('||', (string)$orderForBrandFilter['brand_color_names']) : [];
    foreach ($brandTokensForFilter as $tokenForFilter) {
        [$brandNameForFilter, $brandColorForFilter] = array_pad(explode('~~', $tokenForFilter, 2), 2, '#6c757d');
        $brandNameForFilter = trim((string)$brandNameForFilter);
        $brandColorForFilter = trim((string)$brandColorForFilter);
        if ($brandNameForFilter === '') {
            continue;
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brandColorForFilter)) {
            $brandColorForFilter = '#6c757d';
        }
        $brand_filter_options[strtolower($brandNameForFilter)] = [
            'name' => $brandNameForFilter,
            'color' => $brandColorForFilter,
        ];
    }
}
uasort($brand_filter_options, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$total_delivery_cost_orders = array_sum(array_column($orders, 'delivery_cost_amount'));
$total_gross_amount_orders = array_sum(array_map(fn($o) => $o['total_amount'] + $o['discount'], $orders));
$total_discount_orders = array_sum(array_column($orders, 'discount'));
$total_amount_orders = array_sum(array_column($orders, 'total_amount'));

// Calculate summary statistics (excluding cancelled orders for totals to match performance tables)
$total_orders = 0; // Will count only non-cancelled orders
$paid_orders = 0;
$unpaid_orders = 0;
$cancelled_orders = 0;
$returned_orders = 0;
$total_revenue = 0;
$paid_revenue = 0;
$unpaid_revenue = 0;
$total_discounts = 0;
$net_revenue = 0; // Will be calculated directly from database
$total_items = 0;
$total_unique_products = [];

foreach ($orders as $order) {
    if ($order['is_cancelled']) {
        $cancelled_orders++;
    } elseif ($order['is_returned']) {
        $returned_orders++;
    } elseif ($order['status'] === 'paid') {
        $paid_orders++;
        $total_orders++; // Count only non-cancelled orders
        $paid_revenue += $order['total_amount'];
        // Revenue, discounts, and net revenue calculated from seller_totals to match tables
    } else {
        $unpaid_orders++;
        $total_orders++; // Count only non-cancelled orders
        $unpaid_revenue += $order['total_amount'];
        // Revenue, discounts, and net revenue calculated from seller_totals to match tables
    }
    
    if (!$order['is_cancelled']) {
        $total_items += $order['total_quantity'];
        
        // Parse items detail to get unique products
        $items = !empty($order['items_detail']) ? explode(',', (string)$order['items_detail']) : [];
        foreach ($items as $item) {
            if (!empty($item)) {
                $parts = explode(':', $item);
                if (isset($parts[0])) {
                    $total_unique_products[$parts[0]] = true;
                }
            }
        }
    }
}
$cancelled_unpaid_orders = 0;
$cancelled_paid_amount = 0;
$cancelled_unpaid_amount = 0;
$cancelled_total_amount = 0;
$cancelled_total_discounts = 0;

// Also get cancelled orders breakdown by payment status
$stmt_cancelled = $pdo->prepare('
    SELECT 
        o.status,
        COUNT(DISTINCT o.id) as cancelled_count,
        COALESCE(SUM(o.total_amount), 0) as total_amount,
        COALESCE(SUM(o.discount), 0) as total_discounts
    FROM orders o
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? 
        AND pj.printed_at IS NOT NULL
        AND o.is_cancelled = 1
    GROUP BY o.status
');
$stmt_cancelled->execute([$from_date, $to_date]);
$cancelled_breakdown = $stmt_cancelled->fetchAll();

$cancelled_total_net_loss = 0;

foreach ($cancelled_breakdown as $cancelled) {
    $cancelled_total_amount += $cancelled['total_amount'];
    $cancelled_total_discounts += $cancelled['total_discounts'];
    $cancelled_total_net_loss += ($cancelled['total_amount'] - $cancelled['total_discounts']);
    
    if ($cancelled['status'] === 'paid') {
        $cancelled_paid_orders += $cancelled['cancelled_count'];
        $cancelled_paid_amount += $cancelled['total_amount'];
    } else {
        $cancelled_unpaid_orders += $cancelled['cancelled_count'];
        $cancelled_unpaid_amount += $cancelled['total_amount'];
    }
}

// Also get returned orders breakdown by payment status
$stmt_returned = $pdo->prepare('
    SELECT 
        o.status,
        COUNT(DISTINCT o.id) as returned_count,
        COALESCE(SUM(o.total_amount), 0) as total_amount,
        COALESCE(SUM(o.discount), 0) as total_discounts
    FROM orders o
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? 
        AND pj.printed_at IS NOT NULL
        AND o.is_returned = 1
    GROUP BY o.status
');
$stmt_returned->execute([$from_date, $to_date]);
$returned_breakdown = $stmt_returned->fetchAll();

$returned_paid_orders = 0;
$returned_unpaid_orders = 0;
$returned_paid_amount = 0;
$returned_unpaid_amount = 0;
$returned_total_amount = 0;
$returned_total_discounts = 0;
$returned_total_net_loss = 0;

foreach ($returned_breakdown as $returned) {
    $returned_total_amount += $returned['total_amount'];
    $returned_total_discounts += $returned['total_discounts'];
    $returned_total_net_loss += ($returned['total_amount'] - $returned['total_discounts']);
    
    if ($returned['status'] === 'paid') {
        $returned_paid_orders += $returned['returned_count'];
        $returned_paid_amount += $returned['total_amount'];
    } else {
        $returned_unpaid_orders += $returned['returned_count'];
        $returned_unpaid_amount += $returned['total_amount'];
    }
}

$unique_products_count = count($total_unique_products);

// Get payment method totals (including cancelled and returned orders)
$stmt_payment_methods = $pdo->prepare('
    SELECT 
        COALESCE(o.payment_method, \'N/A\') as payment_method_name,
        COUNT(DISTINCT o.id) as order_count,
        COALESCE(SUM(CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 THEN o.total_amount ELSE 0 END), 0) as total_amount,
        COALESCE(SUM(CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 THEN o.discount ELSE 0 END), 0) as total_discounts,
        COALESCE(SUM(o.total_amount + o.discount), 0) as revenue,
        COALESCE(SUM(o.total_amount - o.discount), 0) as net_amount,
        COUNT(DISTINCT CASE WHEN o.is_cancelled = 1 THEN o.id END) as cancelled_count,
        COALESCE(SUM(CASE WHEN o.is_cancelled = 1 THEN o.total_amount ELSE 0 END), 0) as cancelled_amount,
        COUNT(DISTINCT CASE WHEN o.is_returned = 1 THEN o.id END) as returned_count,
        COALESCE(SUM(CASE WHEN o.is_returned = 1 THEN o.total_amount ELSE 0 END), 0) as returned_amount,
        COUNT(DISTINCT CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 AND o.status = \'paid\' THEN o.id END) as paid_count,
        COUNT(DISTINCT CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 AND o.status = \'unpaid\' THEN o.id END) as unpaid_count
    FROM orders o
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? 
        AND pj.printed_at IS NOT NULL
    GROUP BY COALESCE(o.payment_method, \'N/A\')
    ORDER BY net_amount DESC
');
$stmt_payment_methods->execute([$from_date, $to_date]);
$payment_method_totals = $stmt_payment_methods->fetchAll();

// Calculate grand totals for payment methods
$payment_grand_total_count = 0;
$payment_grand_total_amount = 0;
$payment_grand_total_discounts = 0;
$payment_grand_total_revenue = 0;
$payment_grand_total_cancelled_amount = 0;
$payment_grand_total_returned_amount = 0;
$payment_grand_total_net = 0;

foreach ($payment_method_totals as $pm) {
    $payment_grand_total_count += $pm['order_count'];
    $payment_grand_total_amount += $pm['total_amount'];
    $payment_grand_total_discounts += $pm['total_discounts'];
    $payment_grand_total_revenue += $pm['revenue'];
    $payment_grand_total_cancelled_amount += $pm['cancelled_amount'];
    $payment_grand_total_returned_amount += $pm['returned_amount'];
    $payment_grand_total_net += $pm['net_amount'];
}

// Get overall order status counts for summary table
$stmt_status_summary = $pdo->prepare('
    SELECT 
        COUNT(DISTINCT CASE WHEN o.is_cancelled = 1 THEN o.id END) as cancelled_count,
        COUNT(DISTINCT CASE WHEN o.is_returned = 1 THEN o.id END) as returned_count,
        COUNT(DISTINCT CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 AND o.status = \'paid\' THEN o.id END) as paid_count,
        COUNT(DISTINCT CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 AND o.status = \'unpaid\' THEN o.id END) as unpaid_count,
        COUNT(DISTINCT o.id) as total_count,
        COALESCE(SUM(CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 AND o.status = \'paid\' THEN o.total_amount ELSE 0 END), 0) as paid_amount,
        COALESCE(SUM(CASE WHEN o.is_cancelled = 0 AND o.is_returned = 0 AND o.status = \'unpaid\' THEN o.total_amount ELSE 0 END), 0) as unpaid_amount,
        COALESCE(SUM(CASE WHEN o.is_cancelled = 1 THEN o.total_amount ELSE 0 END), 0) as cancelled_amount,
        COALESCE(SUM(CASE WHEN o.is_returned = 1 THEN o.total_amount ELSE 0 END), 0) as returned_amount,
        COALESCE(SUM(o.total_amount), 0) as total_amount
    FROM orders o
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND pj.printed_at IS NOT NULL
');
$stmt_status_summary->execute([$from, $to]);
$status_summary = $stmt_status_summary->fetch();

// Get seller performance financial data (without multiplication issue)
$stmt = $pdo->prepare('
    SELECT 
        u.name as seller_name,
        COUNT(DISTINCT o.id) as order_count,
        COALESCE(SUM(o.total_amount + o.discount), 0) as total_revenue,
        COALESCE(SUM(o.discount), 0) as total_discounts,
        COALESCE(SUM(o.total_amount), 0) as net_revenue
        -- Note: total_amount = final amount (net revenue), revenue = total_amount + discount (pre-discount)
    FROM orders o
    LEFT JOIN users u ON o.seller_id = u.id
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? 
        AND o.is_cancelled = 0 
        AND o.is_returned = 0 
        AND pj.printed_at IS NOT NULL
    GROUP BY u.id, u.name
    ORDER BY total_revenue DESC
');
$stmt->execute([$from, $to]);
$seller_performance = $stmt->fetchAll();

// Get seller items and products data separately
$stmt_items = $pdo->prepare('
    SELECT 
        u.name as seller_name,
        COALESCE(SUM(oi.quantity), 0) as total_items,
        COUNT(DISTINCT oi.product_id) as unique_products
    FROM orders o
    LEFT JOIN users u ON o.seller_id = u.id
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? 
        AND o.is_cancelled = 0 
        AND o.is_returned = 0 
        AND pj.printed_at IS NOT NULL
    GROUP BY u.id, u.name
    ORDER BY u.name, unique_products DESC
');
$stmt_items->execute([$from, $to]);
$seller_items_data = $stmt_items->fetchAll();

// Combine financial and items data - clean method to avoid duplicates
$items_map = [];
foreach ($seller_items_data as $items) {
    $items_map[$items['seller_name']] = $items;
}

$combined_seller_performance = [];
foreach ($seller_performance as $financial) {
    $seller_name = $financial['seller_name'];
    
    $combined_entry = [
        'seller_name' => $seller_name,
        'order_count' => $financial['order_count'],
        'total_revenue' => $financial['total_revenue'],
        'total_discounts' => $financial['total_discounts'],
        'net_revenue' => $financial['net_revenue'],
        'total_items' => 0,
        'unique_products' => 0
    ];
    
    if (isset($items_map[$seller_name])) {
        $combined_entry['total_items'] = $items_map[$seller_name]['total_items'];
        $combined_entry['unique_products'] = $items_map[$seller_name]['unique_products'];
    }
    
    $combined_seller_performance[] = $combined_entry;
}

// Replace the original array with the clean combined data
$seller_performance = $combined_seller_performance;

// Calculate totals for seller performance table footer
$seller_totals = [
    'total_orders' => 0,
    'total_revenue' => 0,
    'total_discounts' => 0,
    'total_net_revenue' => 0,
    'total_items' => 0,
    'total_unique_products' => 0
];

foreach ($seller_performance as $seller) {
    $seller_totals['total_orders'] += $seller['order_count'];
    $seller_totals['total_revenue'] += $seller['total_revenue']; // revenue = pre-discount (total_amount + discount)
    $seller_totals['total_discounts'] += $seller['total_discounts'];
    $seller_totals['total_net_revenue'] += $seller['net_revenue']; // net revenue = final amount (total_amount)
    $seller_totals['total_items'] += $seller['total_items'];
    $seller_totals['total_unique_products'] += $seller['unique_products'];
}

$seller_avg_order = $seller_totals['total_orders'] > 0 ? $seller_totals['total_revenue'] / $seller_totals['total_orders'] : 0;

// Direct database calculations for accuracy and consistency
// Revenue = Net Revenue + Discount formula
$stmt_revenue = $pdo->prepare('
    SELECT COALESCE(SUM(o.total_amount + o.discount), 0) as direct_revenue
    FROM orders o
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? 
        AND o.is_cancelled = 0 
        AND o.is_returned = 0
        AND pj.printed_at IS NOT NULL
');
$stmt_revenue->execute([$from, $to]);
$revenue_result = $stmt_revenue->fetch();
$total_revenue = $revenue_result['direct_revenue'] ?? 0;

$stmt_discounts = $pdo->prepare('
    SELECT COALESCE(SUM(o.discount), 0) as direct_discounts
    FROM orders o
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? 
        AND o.is_cancelled = 0 
        AND o.is_returned = 0
        AND pj.printed_at IS NOT NULL
');
$stmt_discounts->execute([$from, $to]);
$discounts_result = $stmt_discounts->fetch();
$total_discounts = $discounts_result['direct_discounts'] ?? 0;

$stmt_net_revenue = $pdo->prepare('
    SELECT COALESCE(SUM(o.total_amount), 0) as direct_net_revenue
    FROM orders o
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? 
        AND o.is_cancelled = 0 
        AND o.is_returned = 0
        AND pj.printed_at IS NOT NULL
');
$stmt_net_revenue->execute([$from, $to]);
$net_revenue_result = $stmt_net_revenue->fetch();
$net_revenue = $net_revenue_result['direct_net_revenue'] ?? 0;

// Verify formula: Revenue = Net Revenue + Discount
$calculated_revenue = $net_revenue + $total_discounts;
if (abs($total_revenue - $calculated_revenue) > 0.01) {
    // Use calculated revenue to ensure formula consistency
    $total_revenue = $calculated_revenue;
}

// Get page performance financial data
$stmt = $pdo->prepare('
    SELECT 
        p.name as page_name,
        COUNT(DISTINCT o.id) as order_count,
        COUNT(DISTINCT CASE WHEN o.is_cancelled = 1 THEN o.id END) as cancelled_count,
        COUNT(DISTINCT CASE WHEN o.is_returned = 1 THEN o.id END) as returned_count,
        COALESCE(SUM(o.total_amount + o.discount), 0) as total_revenue,
        COALESCE(SUM(o.discount), 0) as total_discounts,
        COALESCE(SUM(o.total_amount), 0) as net_revenue
        -- Note: total_amount = final amount (net revenue), revenue = total_amount + discount (pre-discount)
    FROM orders o
    LEFT JOIN pages p ON o.page_id = p.id
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND pj.printed_at IS NOT NULL
    GROUP BY p.id, p.name
    ORDER BY total_revenue DESC
');
$stmt->execute([$from, $to]);
$page_performance = $stmt->fetchAll();

// Get page items data separately
$stmt_page_items = $pdo->prepare('
    SELECT 
        p.name as page_name,
        COALESCE(SUM(oi.quantity), 0) as total_items
    FROM orders o
    LEFT JOIN pages p ON o.page_id = p.id
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? AND o.is_cancelled = 0 AND pj.printed_at IS NOT NULL
    GROUP BY p.id, p.name
    ORDER BY total_items DESC
');
$stmt_page_items->execute([$from, $to]);
$page_items_data = $stmt_page_items->fetchAll();

// Combine page financial and items data - use associative array for proper matching
$page_items_map = [];
foreach ($page_items_data as $items) {
    $page_items_map[$items['page_name']] = $items;
}

foreach ($page_performance as $index => $page) {
    $page_performance[$index]['total_items'] = 0;
    
    if (isset($page_items_map[$page['page_name']])) {
        $page_performance[$index]['total_items'] = $page_items_map[$page['page_name']]['total_items'];
    }
}

// Calculate totals for page performance table
$page_totals = [
    'total_orders' => 0,
    'total_cancelled' => 0,
    'total_returned' => 0,
    'total_revenue' => 0,
    'total_discounts' => 0,
    'total_net_revenue' => 0,
    'total_items' => 0
];

foreach ($page_performance as $page) {
    $page_totals['total_orders'] += $page['order_count'];
    $page_totals['total_cancelled'] += $page['cancelled_count'] ?? 0;
    $page_totals['total_returned'] += $page['returned_count'] ?? 0;
    $page_totals['total_revenue'] += $page['total_revenue'];
    $page_totals['total_discounts'] += $page['total_discounts'];
    $page_totals['total_net_revenue'] += $page['net_revenue'];
    $page_totals['total_items'] += $page['total_items'];
}

$page_avg_order = $page_totals['total_orders'] > 0 ? $page_totals['total_revenue'] / $page_totals['total_orders'] : 0;

// Get seller performance by page financial data (excluding cancelled orders)
$stmt_seller_page = $pdo->prepare('
    SELECT 
        u.name as seller_name,
        p.name as page_name,
        COUNT(DISTINCT o.id) as order_count,
        COALESCE(SUM(o.total_amount + o.discount), 0) as total_amount,
        COALESCE(SUM(o.discount), 0) as total_discounts,
        COALESCE(SUM(o.total_amount), 0) as net_revenue
        -- Note: total_amount = final amount (net revenue), revenue = total_amount + discount (pre-discount)
    FROM orders o
    LEFT JOIN users u ON o.seller_id = u.id
    LEFT JOIN pages p ON o.page_id = p.id
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? 
        AND o.is_cancelled = 0 
        AND o.is_returned = 0 
        AND pj.printed_at IS NOT NULL
    GROUP BY u.id, u.name, p.id, p.name
    ORDER BY u.name, net_revenue DESC
');
$stmt_seller_page->execute([$from_date, $to_date]);
$seller_page_performance = $stmt_seller_page->fetchAll();

// Get seller-page items and products data separately
$stmt_seller_page_items = $pdo->prepare('
    SELECT 
        u.name as seller_name,
        p.name as page_name,
        COALESCE(SUM(oi.quantity), 0) as total_items,
        COUNT(DISTINCT oi.product_id) as unique_products
    FROM orders o
    LEFT JOIN users u ON o.seller_id = u.id
    LEFT JOIN pages p ON o.page_id = p.id
    LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE DATE(pj.printed_at) BETWEEN ? AND ? 
        AND o.is_cancelled = 0 
        AND o.is_returned = 0 
        AND pj.printed_at IS NOT NULL
    GROUP BY u.id, u.name, p.id, p.name
    ORDER BY u.name, unique_products DESC
');
$stmt_seller_page_items->execute([$from_date, $to_date]);
$seller_page_items_data = $stmt_seller_page_items->fetchAll();

// Combine seller-page financial and items data - use associative array for proper matching
$seller_page_items_map = [];
foreach ($seller_page_items_data as $items) {
    $key = $items['seller_name'] . '|' . $items['page_name'];
    $seller_page_items_map[$key] = $items;
}

foreach ($seller_page_performance as $index => $sp) {
    $seller_page_performance[$index]['total_items'] = 0;
    $seller_page_performance[$index]['unique_products'] = 0;
    
    $key = $sp['seller_name'] . '|' . $sp['page_name'];
    if (isset($seller_page_items_map[$key])) {
        $seller_page_performance[$index]['total_items'] = $seller_page_items_map[$key]['total_items'];
        $seller_page_performance[$index]['unique_products'] = $seller_page_items_map[$key]['unique_products'];
    }
}

// Calculate totals for seller-page performance table
$seller_page_totals = [
    'total_orders' => 0,
    'total_amount' => 0,
    'total_discounts' => 0,
    'total_net_revenue' => 0,
    'total_items' => 0,
    'total_unique_products' => []
];

foreach ($seller_page_performance as $sp) {
    $seller_page_totals['total_orders'] += $sp['order_count'];
    $seller_page_totals['total_amount'] += $sp['total_amount'];
    $seller_page_totals['total_discounts'] += $sp['total_discounts'];
    $seller_page_totals['total_net_revenue'] += $sp['net_revenue'];
    $seller_page_totals['total_items'] += $sp['total_items'];
    
    // Track unique products across all combinations
    if (!isset($seller_page_totals['total_unique_products'][$sp['unique_products']])) {
        $seller_page_totals['total_unique_products'][$sp['unique_products']] = 0;
    }
    $seller_page_totals['total_unique_products'][$sp['unique_products']] += $sp['unique_products'];
}

$total_unique_products_count = array_sum(array_keys($seller_page_totals['total_unique_products']));
$avg_order_value = $seller_page_totals['total_orders'] > 0 ? $seller_page_totals['total_net_revenue'] / $seller_page_totals['total_orders'] : 0;

// Get hourly breakdown (excluding cancelled orders)
$hourly_data = [];
if ($from_date === $to_date) {
    // Only show hourly breakdown for single day reports
    $stmt = $pdo->prepare('
        SELECT 
            HOUR(pj.printed_at) as hour,
            COUNT(DISTINCT o.id) as order_count,
            COALESCE(SUM(o.total_amount), 0) as revenue,
            COALESCE(SUM(o.discount), 0) as discounts,
            COALESCE(SUM(o.total_amount - o.discount), 0) as net_revenue,
            COALESCE(SUM(oi.quantity), 0) as items
        FROM orders o
        LEFT JOIN ' . $printedOrdersJoin . ' pj ON pj.order_id = o.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE DATE(pj.printed_at) = ? AND o.is_cancelled = 0 AND pj.printed_at IS NOT NULL
        GROUP BY HOUR(pj.printed_at)
        ORDER BY hour
    ');
    $stmt->execute([$from_date]);
    $hourly_data = $stmt->fetchAll();
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0"><?= htmlspecialchars($title) ?></h1>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-success btn-lg" onclick="window.print()">
                <i class="bi bi-printer me-2"></i>Print Report
            </button>
            <button class="btn btn-outline-info btn-lg" onclick="printAllTables()">
                <i class="bi bi-table me-2"></i>Print Detailed Order Report
            </button>
            <button class="btn btn-outline-primary btn-lg" onclick="exportToExcel()">
                <i class="bi bi-file-earmark-excel me-2"></i>Export Excel
            </button>
        </div>
    </div>

    <!-- Date Range Selector -->
    <form method="get" class="card shadow-sm mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label">From Date</label>
                <input type="date" name="from_date" class="form-control form-control-lg" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">To Date</label>
                <input type="date" name="to_date" class="form-control form-control-lg" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <div class="col-12 col-md-4">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-lg flex-fill">Generate Report</button>
                    <button type="button" class="btn btn-outline-secondary btn-lg" onclick="window.print()">Print</button>
                    <button type="button" class="btn btn-outline-danger btn-lg" onclick="window.location.href='<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>'">Cancel</button>
                </div>
            </div>
        </div>
    </form>
    
    <!-- Quick Date Range Buttons -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('today')">Today</button>
                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('yesterday')">Yesterday</button>
                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('thisWeek')">This Week</button>
                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('lastWeek')">Last Week</button>
                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('thisMonth')">This Month</button>
                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('lastMonth')">Last Month</button>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card bg-primary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Orders</h5>
                    <h3 class="mb-0"><?= number_format($total_orders) ?></h3>
                    <small>Total</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-success text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Paid</h5>
                    <h3 class="mb-0"><?= number_format($paid_orders) ?></h3>
                    <small><?= number_format($total_orders > 0 ? ($paid_orders / $total_orders) * 100 : 0, 1) ?>%</small>
                    <div class="mt-1">
                        <small class="fw-bold">$<?= number_format($status_summary['paid_amount'], 0) ?></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Unpaid</h5>
                    <h3 class="mb-0"><?= number_format($unpaid_orders) ?></h3>
                    <small><?= number_format($total_orders > 0 ? ($unpaid_orders / $total_orders) * 100 : 0, 1) ?>%</small>
                    <div class="mt-1">
                        <small class="fw-bold">$<?= number_format($status_summary['unpaid_amount'], 0) ?></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-danger text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Cancelled</h5>
                    <h3 class="mb-0"><?= number_format($cancelled_orders) ?></h3>
                    <small><?= number_format($total_orders > 0 ? ($cancelled_orders / $total_orders) * 100 : 0, 1) ?>%</small>
                    <div class="mt-1">
                        <small class="fw-bold">$<?= number_format($status_summary['cancelled_amount'], 0) ?></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-secondary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Returned</h5>
                    <h3 class="mb-0"><?= number_format($returned_orders) ?></h3>
                    <small><?= number_format($total_orders > 0 ? ($returned_orders / $total_orders) * 100 : 0, 1) ?>%</small>
                    <div class="mt-1">
                        <small class="fw-bold">$<?= number_format($status_summary['returned_amount'], 0) ?></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-info text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Revenue</h5>
                    <h3 class="mb-0">$<?= number_format($total_revenue, 0) ?></h3>
                    <small>Total</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-secondary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Discounts</h5>
                    <h3 class="mb-0">$<?= number_format($total_discounts, 0) ?></h3>
                    <small>Total</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-dark text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Net Revenue</h5>
                    <h3 class="mb-0">$<?= number_format($net_revenue, 0) ?></h3>
                    <small>After Discounts</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-secondary text-white shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Items</h5>
                    <h3 class="mb-0"><?= number_format($total_items) ?></h3>
                    <small>Sold</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Status Summary Table -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Order Status Summary</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="text-end">Count</th>
                            <th class="text-end">Percentage</th>
                            <th class="text-end">Total Amount</th>
                            <th class="text-center">Visual</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="badge bg-success">Paid</span></td>
                            <td class="text-end fw-bold"><?= number_format($status_summary['paid_count']) ?></td>
                            <td class="text-end"><?= number_format($status_summary['total_count'] > 0 ? ($status_summary['paid_count'] / $status_summary['total_count']) * 100 : 0, 1) ?>%</td>
                            <td class="text-end">$<?= number_format($status_summary['paid_amount'], 2) ?></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-success" style="width: <?= $status_summary['total_count'] > 0 ? ($status_summary['paid_count'] / $status_summary['total_count']) * 100 : 0 ?>%"></div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-warning">Unpaid</span></td>
                            <td class="text-end fw-bold"><?= number_format($status_summary['unpaid_count']) ?></td>
                            <td class="text-end"><?= number_format($status_summary['total_count'] > 0 ? ($status_summary['unpaid_count'] / $status_summary['total_count']) * 100 : 0, 1) ?>%</td>
                            <td class="text-end">$<?= number_format($status_summary['unpaid_amount'], 2) ?></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-warning" style="width: <?= $status_summary['total_count'] > 0 ? ($status_summary['unpaid_count'] / $status_summary['total_count']) * 100 : 0 ?>%"></div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-danger">Cancelled</span></td>
                            <td class="text-end fw-bold"><?= number_format($status_summary['cancelled_count']) ?></td>
                            <td class="text-end"><?= number_format($status_summary['total_count'] > 0 ? ($status_summary['cancelled_count'] / $status_summary['total_count']) * 100 : 0, 1) ?>%</td>
                            <td class="text-end">$<?= number_format($status_summary['cancelled_amount'], 2) ?></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-danger" style="width: <?= $status_summary['total_count'] > 0 ? ($status_summary['cancelled_count'] / $status_summary['total_count']) * 100 : 0 ?>%"></div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-secondary">Returned</span></td>
                            <td class="text-end fw-bold"><?= number_format($status_summary['returned_count']) ?></td>
                            <td class="text-end"><?= number_format($status_summary['total_count'] > 0 ? ($status_summary['returned_count'] / $status_summary['total_count']) * 100 : 0, 1) ?>%</td>
                            <td class="text-end">$<?= number_format($status_summary['returned_amount'], 2) ?></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-secondary" style="width: <?= $status_summary['total_count'] > 0 ? ($status_summary['returned_count'] / $status_summary['total_count']) * 100 : 0 ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary fw-bold">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-end"><?= number_format($status_summary['total_count']) ?></td>
                            <td class="text-end">100%</td>
                            <td class="text-end">$<?= number_format($status_summary['total_amount'], 2) ?></td>
                            <td class="text-center">
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-success" style="width: <?= $status_summary['total_count'] > 0 ? ($status_summary['paid_count'] / $status_summary['total_count']) * 100 : 0 ?>%"></div>
                                    <div class="progress-bar bg-warning" style="width: <?= $status_summary['total_count'] > 0 ? ($status_summary['unpaid_count'] / $status_summary['total_count']) * 100 : 0 ?>%"></div>
                                    <div class="progress-bar bg-danger" style="width: <?= $status_summary['total_count'] > 0 ? ($status_summary['cancelled_count'] / $status_summary['total_count']) * 100 : 0 ?>%"></div>
                                    <div class="progress-bar bg-secondary" style="width: <?= $status_summary['total_count'] > 0 ? ($status_summary['returned_count'] / $status_summary['total_count']) * 100 : 0 ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Cancelled Orders Breakdown -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Cancelled Orders Breakdown</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Payment Status</th>
                            <th class="text-end">Count</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Discounts</th>
                            <th class="text-end">Net Loss</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cancelled_breakdown)): ?>
                            <tr><td colspan="5" class="text-center py-3">No cancelled orders found</td></tr>
                        <?php else: ?>
                            <?php foreach ($cancelled_breakdown as $cancelled): ?>
                                <tr>
                                    <td>
                                        <?php if ($cancelled['status'] === 'paid'): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= number_format($cancelled['cancelled_count']) ?></td>
                                    <td class="text-end">$<?= number_format($cancelled['total_amount'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($cancelled['total_discounts'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($cancelled['total_amount'] - $cancelled['total_discounts'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary fw-bold">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-end"><?= number_format($cancelled_orders) ?></td>
                            <td class="text-end">$<?= number_format($cancelled_total_amount, 2) ?></td>
                            <td class="text-end">$<?= number_format($cancelled_total_discounts, 2) ?></td>
                            <td class="text-end">$<?= number_format($cancelled_total_net_loss, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Returned Orders Breakdown -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Returned Orders Breakdown</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="text-end">Count</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Discounts</th>
                            <th class="text-end">Net Loss</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($returned_breakdown)): ?>
                            <tr><td colspan="4" class="text-center py-3">No returned orders found</td></tr>
                        <?php else: ?>
                            <?php foreach ($returned_breakdown as $returned): ?>
                                <tr>
                                    <td>
                                        <?php if ($returned['status'] === 'paid'): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= number_format($returned['returned_count']) ?></td>
                                    <td class="text-end">$<?= number_format($returned['total_amount'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($returned['total_discounts'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($returned['total_amount'] - $returned['total_discounts'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary fw-bold">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-end"><?= number_format($returned_paid_orders + $returned_unpaid_orders) ?></td>
                            <td class="text-end">$<?= number_format($returned_total_amount, 2) ?></td>
                            <td class="text-end">$<?= number_format($returned_total_discounts, 2) ?></td>
                            <td class="text-end">$<?= number_format($returned_total_net_loss, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Hourly Breakdown (Daily Only) -->
    <?php if ($report_type === 'daily' && !empty($hourly_data)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Hourly Breakdown</h5>
        </div>
        <div class="card-body">
            <canvas id="hourlyChart" height="80"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- Seller Performance -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Seller Performance(អត់គណនា​ Cancel & Return)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Seller</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Discounts</th>
                            <th class="text-end">Net Revenue</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Products</th>
                            <th class="text-end">Avg Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($seller_performance)): ?>
                            <tr><td colspan="6" class="text-center py-3">No data available</td></tr>
                        <?php else: ?>
                            <?php foreach ($seller_performance as $seller): ?>
                                <tr>
                                    <td><?= htmlspecialchars($seller['seller_name']) ?></td>
                                    <td class="text-end"><?= number_format($seller['order_count']) ?></td>
                                    <td class="text-end">$<?= number_format($seller['total_revenue'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($seller['total_discounts'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($seller['net_revenue'], 2) ?></td>
                                    <td class="text-end"><?= number_format($seller['total_items']) ?></td>
                                    <td class="text-end"><?= number_format($seller['unique_products']) ?></td>
                                    <td class="text-end">$<?= number_format($seller['order_count'] > 0 ? $seller['total_revenue'] / $seller['order_count'] : 0, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary fw-bold">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-end"><?= number_format($seller_totals['total_orders']) ?></td>
                            <td class="text-end">$<?= number_format($seller_totals['total_revenue'], 2) ?></td>
                            <td class="text-end">$<?= number_format($seller_totals['total_discounts'], 2) ?></td>
                            <td class="text-end">$<?= number_format($seller_totals['total_net_revenue'], 2) ?></td>
                            <td class="text-end"><?= number_format($seller_totals['total_items']) ?></td>
                            <td class="text-end"><?= number_format($seller_totals['total_unique_products']) ?></td>
                            <td class="text-end">$<?= number_format($seller_avg_order, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Payment Method Summary -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Payment Method Summary (គណនា​ Cancel & Return)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Payment Method</th>
                            <th class="text-end">Total Orders</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Unpaid</th>
                            <th class="text-end">Cancelled</th>
                            <th class="text-end">Cancel Amount</th>
                            <th class="text-end">Returned</th>
                            <th class="text-end">Return Amount</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Discounts</th>
                            <th class="text-end">Total Amount</th>
                            <th class="text-end">Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payment_method_totals)): ?>
                            <tr><td colspan="12" class="text-center py-3">No payment data available</td></tr>
                        <?php else: ?>
                            <?php foreach ($payment_method_totals as $pm): ?>
                                <tr>
                                    <td>
                                        <?php 
                                        if ($pm['payment_method_name'] === 'N/A') {
                                            echo '<span class="text-muted">មិនទាន់ទូទាត់</span>';
                                        } else {
                                            echo htmlspecialchars($pm['payment_method_name']);
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end"><?= number_format($pm['order_count']) ?></td>
                                    <td class="text-end">
                                        <?php if ($pm['paid_count'] > 0): ?>
                                            <span class="badge bg-success"><?= number_format($pm['paid_count']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($pm['unpaid_count'] > 0): ?>
                                            <span class="badge bg-warning"><?= number_format($pm['unpaid_count']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($pm['cancelled_count'] > 0): ?>
                                            <span class="badge bg-danger"><?= number_format($pm['cancelled_count']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">$<?= number_format($pm['cancelled_amount'], 2) ?></td>
                                    <td class="text-end">
                                        <?php if ($pm['returned_count'] > 0): ?>
                                            <span class="badge bg-secondary"><?= number_format($pm['returned_count']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">$<?= number_format($pm['returned_amount'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($pm['revenue'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($pm['total_discounts'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($pm['total_amount'], 2) ?></td>
                                    <td class="text-end">
                                        <?php 
                                        $percentage = $payment_grand_total_net > 0 ? ($pm['net_amount'] / $payment_grand_total_net) * 100 : 0;
                                        echo number_format($percentage, 1) . '%';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td>Total</td>
                            <td class="text-end"><?= number_format($payment_grand_total_count) ?></td>
                            <td class="text-end">
                                <?php 
                                $total_paid = array_sum(array_column($payment_method_totals, 'paid_count'));
                                echo $total_paid > 0 ? '<span class="badge bg-success">' . number_format($total_paid) . '</span>' : '<span class="text-muted">0</span>';
                                ?>
                            </td>
                            <td class="text-end">
                                <?php 
                                $total_unpaid = array_sum(array_column($payment_method_totals, 'unpaid_count'));
                                echo $total_unpaid > 0 ? '<span class="badge bg-warning">' . number_format($total_unpaid) . '</span>' : '<span class="text-muted">0</span>';
                                ?>
                            </td>
                            <td class="text-end">
                                <?php 
                                $total_cancelled = array_sum(array_column($payment_method_totals, 'cancelled_count'));
                                echo $total_cancelled > 0 ? '<span class="badge bg-danger">' . number_format($total_cancelled) . '</span>' : '<span class="text-muted">0</span>';
                                ?>
                            </td>
                            <td class="text-end">$<?= number_format($payment_grand_total_cancelled_amount, 2) ?></td>
                            <td class="text-end">
                                <?php 
                                $total_returned = array_sum(array_column($payment_method_totals, 'returned_count'));
                                echo $total_returned > 0 ? '<span class="badge bg-secondary">' . number_format($total_returned) . '</span>' : '<span class="text-muted">0</span>';
                                ?>
                            </td>
                            <td class="text-end">$<?= number_format($payment_grand_total_returned_amount, 2) ?></td>
                            <td class="text-end">$<?= number_format($payment_grand_total_revenue, 2) ?></td>
                            <td class="text-end">$<?= number_format($payment_grand_total_discounts, 2) ?></td>
                            <td class="text-end">$<?= number_format($payment_grand_total_amount, 2) ?></td>
                            <td class="text-end">100.0%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Page Performance -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Page Performance(គណនា​ Cancel & Return)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Cancelled</th>
                            <th class="text-end">Returned</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Discounts</th>
                            <th class="text-end">Net Revenue</th>
                            <th class="text-end">Items</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($page_performance)): ?>
                            <tr><td colspan="8" class="text-center py-3">No data available</td></tr>
                        <?php else: ?>
                            <?php foreach ($page_performance as $page): ?>
                                <tr>
                                    <td><?= htmlspecialchars($page['page_name'] ?? 'Direct') ?></td>
                                    <td class="text-end"><?= number_format($page['order_count']) ?></td>
                                    <td class="text-end"><?= number_format($page['cancelled_count'] ?? 0) ?></td>
                                    <td class="text-end"><?= number_format($page['returned_count'] ?? 0) ?></td>
                                    <td class="text-end">$<?= number_format($page['total_revenue'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($page['total_discounts'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($page['net_revenue'], 2) ?></td>
                                    <td class="text-end"><?= number_format($page['total_items']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary fw-bold">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-end"><?= number_format($page_totals['total_orders']) ?></td>
                            <td class="text-end"><?= number_format($page_totals['total_cancelled'] ?? 0) ?></td>
                            <td class="text-end"><?= number_format($page_totals['total_returned'] ?? 0) ?></td>
                            <td class="text-end">$<?= number_format($page_totals['total_revenue'], 2) ?></td>
                            <td class="text-end">$<?= number_format($page_totals['total_discounts'], 2) ?></td>
                            <td class="text-end">$<?= number_format($page_totals['total_net_revenue'], 2) ?></td>
                            <td class="text-end"><?= number_format($page_totals['total_items']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Seller Performance by Page -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Seller Performance by Page(អត់គណនា​ Cancel & Return)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Seller</th>
                            <th>Page</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Total Amount</th>
                            <th class="text-end">Discounts</th>
                            <th class="text-end">Net Revenue</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Products</th>
                            <th class="text-end">Avg Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($seller_page_performance)): ?>
                            <tr><td colspan="9" class="text-center py-3">No data available</td></tr>
                        <?php else: ?>
                            <?php foreach ($seller_page_performance as $seller_page): ?>
                                <tr>
                                    <td><?= htmlspecialchars($seller_page['seller_name']) ?></td>
                                    <td><?= htmlspecialchars($seller_page['page_name'] ?? 'Direct') ?></td>
                                    <td class="text-end"><?= number_format($seller_page['order_count']) ?></td>
                                    <td class="text-end">$<?= number_format($seller_page['total_amount'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($seller_page['total_discounts'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($seller_page['net_revenue'], 2) ?></td>
                                    <td class="text-end"><?= number_format($seller_page['total_items']) ?></td>
                                    <td class="text-end"><?= number_format($seller_page['unique_products']) ?></td>
                                    <td class="text-end">$<?= number_format($seller_page['order_count'] > 0 ? $seller_page['net_revenue'] / $seller_page['order_count'] : 0, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary fw-bold">
                            <td><strong>TOTAL</strong></td>
                            <td></td>
                            <td class="text-end"><?= number_format($seller_page_totals['total_orders']) ?></td>
                            <td class="text-end">$<?= number_format($seller_page_totals['total_amount'], 2) ?></td>
                            <td class="text-end">$<?= number_format($seller_page_totals['total_discounts'], 2) ?></td>
                            <td class="text-end">$<?= number_format($seller_page_totals['total_net_revenue'], 2) ?></td>
                            <td class="text-end"><?= number_format($seller_page_totals['total_items']) ?></td>
                            <td class="text-end"><?= number_format($total_unique_products_count) ?></td>
                            <td class="text-end">$<?= number_format($avg_order_value, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Detailed Orders Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <div class="row align-items-center">
                <div class="col">
                    <h5 class="mb-0">Detailed Orders (<?= count($orders) ?> orders) (គណនា​ Cancel & Return)</h5>
                </div>
                <div class="col-auto">
                    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
                    <div class="btn-group" role="group" id="orderStatusFilterGroup">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="filterOrders('all')">All</button>
                        <button type="button" class="btn btn-success btn-sm" onclick="filterOrders('paid')">Paid</button>
                        <button type="button" class="btn btn-warning btn-sm" onclick="filterOrders('unpaid')">Unpaid</button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="filterOrders('cancelled')">Cancelled</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="filterOrders('returned')">Returned</button>
                    </div>
                    <select id="orderBrandFilter" class="form-select form-select-sm" style="width: 180px;" onchange="applyOrderFilters()">
                        <option value="all">All Brands</option>
                        <?php foreach ($brand_filter_options as $brandKey => $brandOption): ?>
                            <option value="<?= htmlspecialchars($brandKey) ?>" style="color: <?= htmlspecialchars($brandOption['color']) ?>;">
                                <?= htmlspecialchars($brandOption['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                        <button type="button" class="btn btn-info btn-sm" onclick="printAllTables()">Print Detailed Order Report</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive" style="overflow-x: auto;">
                <table class="table table-sm" id="ordersTable" style="min-width: 1400px;">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Order Date</th>
                            <th>Print Date</th>
                            <th>Order Code</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Seller</th>
                            <th>Page</th>
                            <th>Products</th>
                            <th>Brand Name</th>
                            <th>Qty</th>
                            <th>Delivery By</th>
                            <th class="text-end">Net Amount</th>
                            <th>Discount</th>
                            <th class="text-end">Delivery Cost</th>
                            <th>Status</th>
                            <th>Payment Method</th>
                            <th class="text-end">Amount</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="19" class="text-center py-3">No orders found</td></tr>
                        <?php else: ?>
                            <?php $row_number = 1; ?>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $rowBrandFilterNames = [];
                                $rowBrandTokens = !empty($order['brand_color_names']) ? explode('||', (string)$order['brand_color_names']) : [];
                                foreach ($rowBrandTokens as $rowBrandToken) {
                                    [$rowBrandName] = array_pad(explode('~~', $rowBrandToken, 2), 2, '');
                                    $rowBrandName = trim((string)$rowBrandName);
                                    if ($rowBrandName !== '') {
                                        $rowBrandFilterNames[] = strtolower($rowBrandName);
                                    }
                                }
                                $rowBrandFilterValue = '|' . implode('|', array_unique($rowBrandFilterNames)) . '|';
                                ?>
                                <tr class="<?= ($order['is_cancelled'] || $order['is_returned']) ? 'table-danger' : '' ?>" data-status="<?= $order['is_cancelled'] ? 'cancelled' : ($order['is_returned'] ? 'returned' : $order['status']) ?>" data-brands="<?= htmlspecialchars($rowBrandFilterValue) ?>">
                                    <td><?= $row_number ?></td>
                                <?php $row_number++; ?>
                                    <td><?= date('Y-m-d', strtotime($order['created_at'])) ?></td>
                                    <td><?= date('Y-m-d', strtotime($order['is_cancelled'] ? $order['updated_at'] : $order['printed_at'])) ?></td>
                                    <td><?= htmlspecialchars($order['order_code']) ?></td>
                                    <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                    <td><?= htmlspecialchars($order['phone'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($order['seller_name']) ?></td>
                                    <td><?= htmlspecialchars($order['page_name'] ?? 'Direct') ?></td>
                                    <td>
                                        <?php 
                                        $items = !empty($order['items_detail']) ? explode(',', (string)$order['items_detail']) : [];
                                        $product_details = [];
                                        foreach ($items as $item) {
                                            if (!empty($item)) {
                                                $parts = explode(':', $item);
                                                if (isset($parts[3]) && isset($parts[1]) && isset($parts[2])) {
                                                    $product_name = htmlspecialchars($parts[3]);
                                                    $quantity = (int)$parts[1];
                                                    $amount = (float)$parts[2];
                                                    $product_details[] = "{$product_name} ({$quantity}x, \${$amount})";
                                                }
                                            }
                                        }
                                        if (!empty($product_details)) {
                                            $display_products = array_slice($product_details, 0, 3);
                                            echo implode('<br>', $display_products);
                                            if (count($product_details) > 3) {
                                                echo '<br>...';
                                            }
                                        } else {
                                            echo 'No items';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $brandTokens = !empty($order['brand_color_names']) ? explode('||', (string)$order['brand_color_names']) : [];
                                        if (!empty($brandTokens)):
                                            foreach ($brandTokens as $token):
                                                [$brandName, $brandColor] = array_pad(explode('~~', $token, 2), 2, '#6c757d');
                                                $brandName = trim((string)$brandName);
                                                $brandColor = trim((string)$brandColor);
                                                if ($brandName === '') {
                                                    continue;
                                                }
                                                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor)) {
                                                    $brandColor = '#6c757d';
                                                }
                                        ?>
                                                <span class="fw-semibold me-2" style="color: <?= htmlspecialchars($brandColor) ?>;"><?= htmlspecialchars($brandName) ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= number_format($order['total_quantity']) ?></td>
                                    <td>
                                        <?php 
                                        $deliveryBy = $order['delivery_by'] ?? '';
                                        if (!empty($deliveryBy)): ?>
                                            <span class="badge bg-info"><?= htmlspecialchars($deliveryBy) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">Not delivered</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">$<?= number_format($order['total_amount'] + $order['discount'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($order['discount'], 2) ?></td>
                                    <td class="text-end">$<?= number_format((float)$order['delivery_cost_amount'], 2) ?></td>
                                    <td>
                                        <?php if ($order['is_cancelled']): ?>
                                            <span class="badge bg-danger">Cancelled</span>
                                        <?php elseif ($order['is_returned']): ?>
                                            <span class="badge bg-secondary">Returned</span>
                                        <?php elseif ($order['status'] === 'paid'): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $paymentMethod = $order['payment_method'] ?? '';
                                        if (!empty($paymentMethod)) {
                                            echo htmlspecialchars($paymentMethod);
                                        } else {
                                            echo '<span class="text-muted">មិនទាន់ទូទាត់</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end">$<?= number_format($order['total_amount'], 2) ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewReceipt(<?= $order['id'] ?>)" title="View Receipt">
                                            <i class="bi bi-receipt"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="12" class="text-end">Total:</th>
                            <th class="text-end">$<?= number_format($total_gross_amount_orders, 2) ?></th>
                            <th class="text-end">$<?= number_format($total_discount_orders, 2) ?></th>
                            <th class="text-end">$<?= number_format($total_delivery_cost_orders, 2) ?></th>
                            <th></th>
                            <th></th>
                            <th class="text-end">$<?= number_format($total_amount_orders, 2) ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function updateDateFields() {
    const reportType = document.querySelector('select[name="report_type"]').value;
    const dateField = document.getElementById('dateField');
    const monthField = document.getElementById('monthField');
    const yearField = document.getElementById('yearField');
    
    dateField.style.display = 'none';
    monthField.style.display = 'none';
    yearField.style.display = 'none';
    
    switch(reportType) {
        case 'daily':
            dateField.style.display = 'block';
            break;
        case 'monthly':
            monthField.style.display = 'block';
            break;
        case 'yearly':
            yearField.style.display = 'block';
            break;
    }
}

<?php if ($report_type === 'daily' && !empty($hourly_data)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const hourlyData = <?= json_encode($hourly_data) ?>;
    
    const ctx = document.getElementById('hourlyChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: hourlyData.map(d => d.hour + ':00'),
            datasets: [{
                label: 'Orders',
                data: hourlyData.map(d => d.order_count),
                backgroundColor: 'rgba(54, 162, 235, 0.8)',
                yAxisID: 'y'
            }, {
                label: 'Revenue ($)',
                data: hourlyData.map(d => d.revenue),
                backgroundColor: 'rgba(75, 192, 192, 0.8)',
                type: 'line',
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Orders'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Revenue ($)'
                    },
                    grid: {
                        drawOnChartArea: false,
                    }
                }
            }
        }
    });
});
<?php endif; ?>

function setDateRange(range) {
    const today = new Date();
    const form = document.querySelector('form');
    const fromInput = form.querySelector('input[name="from_date"]');
    const toInput = form.querySelector('input[name="to_date"]');
    
    switch(range) {
        case 'today':
            fromInput.value = today.toISOString().split('T')[0];
            toInput.value = today.toISOString().split('T')[0];
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            fromInput.value = yesterday.toISOString().split('T')[0];
            toInput.value = yesterday.toISOString().split('T')[0];
            break;
        case 'thisWeek':
            const startOfWeek = new Date(today);
            startOfWeek.setDate(today.getDate() - today.getDay());
            fromInput.value = startOfWeek.toISOString().split('T')[0];
            toInput.value = today.toISOString().split('T')[0];
            break;
        case 'lastWeek':
            const endOfLastWeek = new Date(today);
            endOfLastWeek.setDate(today.getDate() - today.getDay() - 1);
            const startOfLastWeek = new Date(endOfLastWeek);
            startOfLastWeek.setDate(endOfLastWeek.getDate() - 6);
            fromInput.value = startOfLastWeek.toISOString().split('T')[0];
            toInput.value = endOfLastWeek.toISOString().split('T')[0];
            break;
        case 'thisMonth':
            const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
            fromInput.value = startOfMonth.toISOString().split('T')[0];
            toInput.value = today.toISOString().split('T')[0];
            break;
        case 'lastMonth':
            const startOfLastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const endOfLastMonth = new Date(today.getFullYear(), today.getMonth(), 0);
            fromInput.value = startOfLastMonth.toISOString().split('T')[0];
            toInput.value = endOfLastMonth.toISOString().split('T')[0];
            break;
    }
    
    form.submit();
}

function exportToExcel() {
    const table = document.getElementById('ordersTable');
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        
        for (let j = 0; j < cols.length; j++) {
            const text = cols[j].innerText.replace(/,/g, '').replace(/"/g, '""');
            rowData.push('"' + text + '"');
        }
        
        csv.push(rowData.join(','));
    }
    
    // UTF-8 BOM so Excel (Windows) opens the file as Unicode and shows Khmer correctly
    const csvContent = '\uFEFF' + csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'daily_report_<?= str_replace('-', '_', $from_date) ?>_to_<?= str_replace('-', '_', $to_date) ?>.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

function viewReceipt(orderId) {
    // Open receipt in a new window/tab
    const receiptUrl = `../receipt.php?id=${orderId}`;
    window.open(receiptUrl, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
}

function printAllTables() {
    // Get date range from form inputs
    const fromDateInput = document.querySelector('input[name="from_date"]');
    const toDateInput = document.querySelector('input[name="to_date"]');
    const fromDate = fromDateInput ? fromDateInput.value : '';
    const toDate = toDateInput ? toDateInput.value : '';
    
    // Format date range for title
    let dateRangeText = '';
    if (fromDate && toDate) {
        const fromFormatted = new Date(fromDate).toLocaleDateString();
        const toFormatted = new Date(toDate).toLocaleDateString();
        dateRangeText = ` (${fromFormatted} to ${toFormatted})`;
    } else if (fromDate) {
        const fromFormatted = new Date(fromDate).toLocaleDateString();
        dateRangeText = ` (From ${fromFormatted})`;
    } else if (toDate) {
        const toFormatted = new Date(toDate).toLocaleDateString();
        dateRangeText = ` (To ${toFormatted})`;
    }
    
    // Get all tables in the document
    const allTables = document.querySelectorAll('table');
    let printContent = '';
    
    // Add seller performance table (first)
    const sellerPerformanceTable = Array.from(allTables).find(table => {
        const headers = table.querySelectorAll('th');
        return headers.length > 0 && headers[0].textContent.includes('Seller');
    });
    
    if (sellerPerformanceTable) {
        const sellerClone = sellerPerformanceTable.cloneNode(true);
        printContent += '<h2>Seller Performance</h2>';
        printContent += sellerClone.outerHTML;
    }
    
    // Add page performance table (second)
    const pagePerformanceTable = Array.from(allTables).find(table => {
        const headers = table.querySelectorAll('th');
        return headers.length > 0 && headers[0].textContent.includes('Page');
    });
    
    if (pagePerformanceTable) {
        const pageClone = pagePerformanceTable.cloneNode(true);
        printContent += '<h2>Page Performance</h2>';
        printContent += pageClone.outerHTML;
    }
    
    // Add seller performance by page table (third)
    const sellerPageTable = Array.from(allTables).find(table => {
        const headers = table.querySelectorAll('th');
        return headers.length > 0 && headers[0].textContent.includes('Seller') && headers[1] && headers[1].textContent.includes('Page');
    });
    
    if (sellerPageTable) {
        const sellerPageClone = sellerPageTable.cloneNode(true);
        printContent += '<h2>Seller Performance by Page</h2>';
        printContent += sellerPageClone.outerHTML;
    }
    
    // Add detailed orders table (last)
    const ordersTable = document.getElementById('ordersTable');
    
    if (!ordersTable) {
        alert('Detailed Orders table not found!');
        return;
    }
    
    // Clone the detailed orders table and remove Receipt column
    const tableClone = ordersTable.cloneNode(true);
    const rows = tableClone.querySelectorAll('tr');
    
    // Remove only the Receipt column (last column)
    rows.forEach(row => {
        const cells = row.querySelectorAll('td, th');
        // Remove the last cell (Receipt column)
        if (cells.length > 0) {
            cells[cells.length - 1].remove();
        }
    });
    
    // Update column headers - remove only Receipt header (last header)
    const headers = tableClone.querySelectorAll('th');
    if (headers.length > 0) {
        headers[headers.length - 1].remove();
    }
    
    printContent += '<h2>Detailed Orders</h2>';
    printContent += tableClone.outerHTML;
    
    // Build complete HTML content for printing
    const fullPrintContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Detailed Orders Report</title>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    margin: 20px; 
                    background: white;
                }
                h2 { 
                    color: #333; 
                    font-size: 18px; 
                    margin-top: 30px; 
                    margin-bottom: 15px;
                    border-bottom: 2px solid #333;
                    padding-bottom: 5px;
                }
                table { 
                    width: 100%; 
                    border-collapse: collapse; 
                    margin-bottom: 20px; 
                    background: white;
                }
                th, td { 
                    border: 1px solid #333; 
                    padding: 8px; 
                    text-align: left; 
                    vertical-align: top;
                }
                th { 
                    background-color: #f5f5f5; 
                    font-weight: bold; 
                    font-size: 12px;
                }
                td { 
                    font-size: 11px;
                    line-height: 1.3;
                }
                .text-end { text-align: right; }
                .badge { 
                    background-color: #6c757d; 
                    color: white; 
                    padding: 2px 6px; 
                    border-radius: 3px; 
                    font-size: 10px;
                    font-weight: bold;
                }
                .bg-success { background-color: #198754 !important; }
                .bg-warning { background-color: #ffc107 !important; color: #000 !important; }
                .bg-danger { background-color: #dc3545 !important; }
                h1 { 
                    color: #333; 
                    font-size: 20px; 
                    margin-bottom: 10px;
                }
                p { 
                    color: #666; 
                    font-size: 12px; 
                    margin-bottom: 20px;
                }
                @media print {
                    body { margin: 10px; }
                    table { page-break-inside: avoid; }
                    th, td { font-size: 10px; padding: 6px; }
                    h2 { font-size: 16px; margin-top: 20px; }
                }
            </style>
        </head>
        <body>
            <h1>Detailed Orders Report${dateRangeText}</h1>
            <p>Generated on: ${new Date().toLocaleString()}</p>
            ${printContent}
        </body>
        </html>`;
    
    // Create a new window and write content
    const printWindow = window.open('', '_blank');
    
    // Wait for the window to be ready, then write content
    printWindow.onload = function() {
        printWindow.document.write(fullPrintContent);
        printWindow.document.close();
        
        // Wait a moment for content to load, then trigger print
        setTimeout(() => {
            printWindow.focus();
            printWindow.print();
        }, 500);
    };
    
    // If onload doesn't work, fallback to immediate writing
    printWindow.document.write(fullPrintContent);
    printWindow.document.close();
    
    // Fallback print trigger
    setTimeout(() => {
        printWindow.focus();
        printWindow.print();
    }, 1000);
}

let currentOrderStatusFilter = 'all';

function filterOrders(status) {
    currentOrderStatusFilter = status;
    applyOrderFilters();
}

function applyOrderFilters() {
    const table = document.getElementById('ordersTable');
    if (!table) return;
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    const status = currentOrderStatusFilter || 'all';
    const brandSelect = document.getElementById('orderBrandFilter');
    const selectedBrand = brandSelect ? brandSelect.value : 'all';
    
    // Update button states
    const buttons = document.querySelectorAll('#orderStatusFilterGroup .btn');
    buttons.forEach(btn => {
        btn.classList.remove('active');
        if (btn.textContent.toLowerCase() === status) {
            btn.classList.add('active');
        }
    });
    
    // Filter rows
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const rowStatus = row.getAttribute('data-status');
        const rowBrands = row.getAttribute('data-brands') || '';
        const statusMatches = status === 'all' || rowStatus === status;
        const brandMatches = selectedBrand === 'all' || rowBrands.includes('|' + selectedBrand + '|');

        row.style.display = (statusMatches && brandMatches) ? '' : 'none';
    }
    
    // Update visible count
    updateVisibleCount();
}

function updateVisibleCount() {
    const table = document.getElementById('ordersTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    let visibleCount = 0;
    
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].style.display !== 'none') {
            visibleCount++;
        }
    }
    
    // Update the count in the header
    const countElement = table.closest('.card').querySelector('h5');
    const totalCount = rows.length;
    countElement.textContent = `Detailed Orders (${visibleCount} of ${totalCount} orders)`;
}

// Enhanced scrolling functionality
document.addEventListener('DOMContentLoaded', function() {
    const tableContainer = document.querySelector('.table-responsive');
    if (!tableContainer) return;
    
    // Add scroll indicators
    const leftIndicator = document.createElement('div');
    leftIndicator.className = 'scroll-indicator left';
    leftIndicator.innerHTML = '◀';
    leftIndicator.title = 'Scroll left to see more columns';
    
    const rightIndicator = document.createElement('div');
    rightIndicator.className = 'scroll-indicator right';
    rightIndicator.innerHTML = '▶';
    rightIndicator.title = 'Scroll right to see more columns';
    
    tableContainer.appendChild(leftIndicator);
    tableContainer.appendChild(rightIndicator);
    
    // Check scroll position and show/hide indicators
    function updateScrollIndicators() {
        const hasHorizontalScroll = tableContainer.scrollWidth > tableContainer.clientWidth;
        const canScrollLeft = tableContainer.scrollLeft > 0;
        const canScrollRight = tableContainer.scrollLeft < (tableContainer.scrollWidth - tableContainer.clientWidth);
        
        if (hasHorizontalScroll) {
            leftIndicator.classList.toggle('show', canScrollLeft);
            rightIndicator.classList.toggle('show', canScrollRight);
        } else {
            leftIndicator.classList.remove('show');
            rightIndicator.classList.remove('show');
        }
    }
    
    // Update indicators on scroll
    tableContainer.addEventListener('scroll', updateScrollIndicators);
    
    // Update indicators on window resize
    window.addEventListener('resize', updateScrollIndicators);
    
    // Initial check
    setTimeout(updateScrollIndicators, 100);
    
    // Add keyboard navigation
    tableContainer.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft') {
            tableContainer.scrollLeft -= 100;
            e.preventDefault();
        } else if (e.key === 'ArrowRight') {
            tableContainer.scrollLeft += 100;
            e.preventDefault();
        }
    });
    
    // Make table container focusable for keyboard navigation
    tableContainer.setAttribute('tabindex', '0');
    
    // Add smooth scrolling
    tableContainer.style.scrollBehavior = 'smooth';
});
</script>

<style>
/* Horizontal scrolling enhancements */
.table-responsive {
    position: relative;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    background: linear-gradient(to right, #f8f9fa 0%, #f8f9fa 95%, rgba(248, 249, 250, 0.8) 100%);
}

.table-responsive::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 30px;
    height: 100%;
    background: linear-gradient(to left, rgba(248, 249, 250, 0.9), transparent);
    pointer-events: none;
    z-index: 1;
}

.table-responsive:hover {
    overflow-x: auto !important;
}

.table {
    margin-bottom: 0;
}

.table th {
    position: sticky;
    top: 0;
    background-color: #f8f9fa;
    z-index: 10;
}

/* Scroll indicators */
.scroll-indicator {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(0, 0, 0, 0.7);
    color: white;
    padding: 5px 10px;
    border-radius: 50%;
    font-size: 12px;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.3s;
    z-index: 20;
}

.scroll-indicator.left {
    left: 10px;
}

.scroll-indicator.right {
    right: 10px;
}

.scroll-indicator.show {
    opacity: 1;
}

@media print {
    .btn, form, .card-header h5 {
        display: none !important;
    }
    
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
    }
    
    .table-responsive {
        overflow-x: visible !important;
        border: none !important;
    }
    
    .table-responsive::after {
        display: none !important;
    }
    
    .scroll-indicator {
        display: none !important;
    }
}

/* Mobile enhancements */
@media (max-width: 768px) {
    .table-responsive {
        border-radius: 0.375rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .table th, .table td {
        white-space: nowrap;
    }
}
</style>

<?php include __DIR__ . '/../layout/footer.php'; ?>
