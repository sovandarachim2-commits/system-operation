<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config.php';
$user = current_user();
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($BASE_URL) ?>/public/css/app.css" rel="stylesheet">
    <style>
        /* Simple Responsive Admin Layout */
        .admin-sidebar {
            background: #232323;
            min-height: 100vh;
            color: #fdb04c;
        }
        /* Desktop styles */
        @media (min-width: 768px) {
            .admin-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                width: 250px;
                height: 100vh;
                z-index: 1000;
                overflow-y: auto;
                transition: transform 0.3s ease;
            }
            .admin-sidebar.hidden {
                transform: translateX(-100%);
            }
            .admin-content {
                margin-left: 250px;
                width: calc(100% - 250px);
                transition: margin-left 0.3s ease, width 0.3s ease;
            }
            .admin-content.expanded {
                margin-left: 0;
                width: 100%;
            }
        }
        .admin-sidebar .nav-link {
            color: #fdb04c;
            padding: 12px 20px;
            border-radius: 0;
        }
        .admin-sidebar .nav-link:hover,
        .admin-sidebar .nav-link.active {
            background: #fdb04c;
            color: #232323;
        }
        .admin-sidebar .navbar-brand {
            color: #fdb04c;
            padding: 15px 20px;
            font-weight: bold;
        }
        .admin-topbar {
            background: #232323;
            color: #fdb04c;
            padding: 10px 20px;
        }
        .admin-topbar .btn {
            background: #ffb44c;
            color: #232323;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
        }
        .admin-topbar .btn:hover {
            background: #fdb04c;
        }
        @media (max-width: 767px) {
            .admin-sidebar {
                position: fixed;
                top: 0;
                left: -250px;
                width: 250px;
                height: 100vh;
                z-index: 1050;
                transition: left 0.3s ease;
            }
            .admin-sidebar.show {
                left: 0;
            }
            .admin-content {
                margin-left: 0 !important;
            }
            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 1040;
                display: none;
            }
            .sidebar-overlay.show {
                display: block;
            }
        }
    </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<?php if ($user && $user['role'] === 'admin'): ?>
    <!-- Mobile overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <div class="d-flex flex-grow-1">
        <!-- Admin Sidebar -->
        <nav class="admin-sidebar flex-shrink-0" id="adminSidebar">
            <div class="d-flex flex-column h-100">
                <div class="p-3 border-bottom border-secondary">
                    <a class="navbar-brand text-white text-decoration-none" href="<?= htmlspecialchars($BASE_URL) ?>/index.php">Order System</a>
                </div>
                <div class="flex-grow-1 overflow-auto pt-3">
                    <ul class="nav nav-pills flex-column mb-auto">
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white <?= $current === 'statistics.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/statistics.php">
                                <i class="bi bi-bar-chart-line me-2"></i>
                                <span>Statistics</span>
                            </a>
                        </li>
                        <?php $isMainAdmin = isset($user['username']) && $user['username'] === 'admin'; ?>
                        <?php if ($isMainAdmin): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white <?= $current === 'users.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/users.php">
                                <i class="bi bi-people me-2"></i>
                                <span>User Management</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white <?= $current === 'maintenance.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/maintenance.php">
                                <i class="bi bi-tools me-2"></i>
                                <span>Maintenance</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white <?= in_array($current, ['pages.php', 'delivery_types.php', 'delivery_costs.php', 'logos.php', 'manage_note_options.php']) ? 'active' : '' ?>" href="#" data-bs-toggle="collapse" data-bs-target="#provideOrderCollapse">
                                <i class="bi bi-box-seam me-2"></i>
                                <span>Provide Order</span>
                                <i class="bi bi-chevron-down ms-auto"></i>
                            </a>
                            <div class="collapse <?= in_array($current, ['pages.php', 'delivery_types.php', 'delivery_costs.php', 'logos.php', 'manage_note_options.php']) ? 'show' : '' ?>" id="provideOrderCollapse">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'pages.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/pages.php">
                                            <i class="bi bi-file-text me-2"></i>
                                            <span>Pages</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'delivery_types.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/delivery_types.php">
                                            <i class="bi bi-truck me-2"></i>
                                            <span>Delivery Types</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'delivery_costs.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/delivery_costs.php">
                                            <i class="bi bi-currency-dollar me-2"></i>
                                            <span>Delivery Price</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'logos.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/logos.php">
                                            <i class="bi bi-image me-2"></i>
                                            <span>Logos</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'manage_note_options.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/manage_note_options.php">
                                            <i class="bi bi-sticky-note me-2"></i>
                                            <span>Manage Note Options</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#stockCollapse" role="button" aria-expanded="<?= in_array($current, ['stock_categories.php', 'stock_items.php', 'stock_movements.php', 'stock_reports.php']) ? 'true' : 'false' ?>">
                                <i class="bi bi-archive me-2"></i>
                                <span>Stock Management</span>
                            </a>
                            <div class="collapse <?= in_array($current, ['stock_categories.php', 'stock_items.php', 'stock_movements.php', 'stock_reports.php']) ? 'show' : '' ?>" id="stockCollapse">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'stock_categories.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/stock_categories.php">
                                            <i class="bi bi-tags me-2"></i>
                                            <span>Categories</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'stock_items.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/stock_items.php">
                                            <i class="bi bi-box-seam me-2"></i>
                                            <span>Stock Items</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'stock_movements.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/stock_movements.php">
                                            <i class="bi bi-arrow-left-right me-2"></i>
                                            <span>Stock Movements</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'stock_reports.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/stock_reports.php">
                                            <i class="bi bi-file-earmark-text me-2"></i>
                                            <span>Stock Reports</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#purchaseCollapse" role="button" aria-expanded="<?= in_array($current, ['purchase_orders.php', 'purchase_vendors.php', 'purchase_receiving.php', 'purchase_reports.php']) ? 'true' : 'false' ?>">
                                <i class="bi bi-cart-plus me-2"></i>
                                <span>Purchase Management</span>
                            </a>
                            <div class="collapse <?= in_array($current, ['purchase_orders.php', 'purchase_vendors.php', 'purchase_receiving.php', 'purchase_reports.php']) ? 'show' : '' ?>" id="purchaseCollapse">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_orders.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_orders.php">
                                            <i class="bi bi-file-earmark-text me-2"></i>
                                            <span>Purchase Orders</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_vendors.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_vendors.php">
                                            <i class="bi bi-building me-2"></i>
                                            <span>Vendors</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_receiving.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_receiving.php">
                                            <i class="bi bi-truck me-2"></i>
                                            <span>Receiving</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_reports.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_reports.php">
                                            <i class="bi bi-graph-up me-2"></i>
                                            <span>Purchase Reports</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#costCollapse" role="button" aria-expanded="<?= in_array($current, ['product_costs.php', 'profit_analysis.php', 'cost_reports.php', 'cost_history.php']) ? 'true' : 'false' ?>">
                                <i class="bi bi-currency-dollar me-2"></i>
                                <span>Cost Management</span>
                            </a>
                            <div class="collapse <?= in_array($current, ['product_costs.php', 'profit_analysis.php', 'cost_reports.php', 'cost_history.php']) ? 'show' : '' ?>" id="costCollapse">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'product_costs.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_costs.php">
                                            <i class="bi bi-tag me-2"></i>
                                            <span>Product Costs</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'cost_history.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cost_history.php">
                                            <i class="bi bi-clock-history me-2"></i>
                                            <span>Price History</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'profit_analysis.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/profit_analysis.php">
                                            <i class="bi bi-graph-up-arrow me-2"></i>
                                            <span>Profit Analysis</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'cost_reports.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cost_reports.php">
                                            <i class="bi bi-file-earmark-bar-graph me-2"></i>
                                            <span>Cost Reports</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#accountantCollapse" role="button" aria-expanded="<?= in_array($current, ['accountant_dashboard.php', 'accountant_daily_reports.php', 'accountant_product_reports.php', 'accountant_financial_summary.php', 'closing_report.php']) ? 'true' : 'false' ?>">
                                <i class="bi bi-calculator me-2"></i>
                                <span>Accountant</span>
                            </a>
                            <div class="collapse <?= in_array($current, ['accountant_dashboard.php', 'accountant_daily_reports.php', 'accountant_product_reports.php', 'accountant_financial_summary.php', 'closing_report.php']) ? 'show' : '' ?>" id="accountantCollapse">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'accountant_dashboard.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/accountant_dashboard.php">
                                            <i class="bi bi-speedometer2 me-2"></i>
                                            <span>Dashboard</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'accountant_daily_reports.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/accountant_daily_reports.php">
                                            <i class="bi bi-calendar-day me-2"></i>
                                            <span>Daily Reports</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'accountant_product_reports.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/accountant_product_reports.php">
                                            <i class="bi bi-box-seam me-2"></i>
                                            <span>Product Reports</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'accountant_financial_summary.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/accountant_financial_summary.php">
                                            <i class="bi bi-file-earmark-text me-2"></i>
                                            <span>Financial Summary</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'closing_report.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/closing_report.php">
                                            <i class="bi bi-file-earmark-bar-graph me-2"></i>
                                            <span>Closing Report</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#financeCollapse" role="button" aria-expanded="<?= in_array($current, ['finance_dashboard.php', 'add_spending.php', 'finance_reports.php']) ? 'true' : 'false' ?>">
                                <i class="bi bi-cash-stack me-2"></i>
                                <span>Finance</span>
                            </a>
                            <div class="collapse <?= in_array($current, ['finance_dashboard.php', 'add_spending.php', 'finance_reports.php']) ? 'show' : '' ?>" id="financeCollapse">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'finance_dashboard.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/finance_dashboard.php">
                                            <i class="bi bi-speedometer2 me-2"></i>
                                            <span>Dashboard</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'add_spending.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/add_spending.php">
                                            <i class="bi bi-plus-circle me-2"></i>
                                            <span>Add New Spending</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'finance_reports.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/finance_reports.php">
                                            <i class="bi bi-file-earmark-bar-graph me-2"></i>
                                            <span>Reports</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white <?= $current === 'activity.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/activity.php">
                                <i class="bi bi-calendar-check me-2"></i>
                                <span>Daily Activity</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white <?= $current === 'daily_summary.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/daily_summary.php">
                                <i class="bi bi-graph-up me-2"></i>
                                <span>Daily Summary</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white <?= $current === 'inventory.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/inventory.php">
                                <i class="bi bi-box-seam me-2"></i>
                                <span>Inventory &amp; Cashier</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white <?= $current === 'order_filter.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/order_filter.php">
                                <i class="bi bi-funnel me-2"></i>
                                <span>Order Filter</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white <?= $current === 'cancelled_orders.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cancelled_orders.php">
                                <i class="bi bi-x-circle me-2"></i>
                                <span>Manage Orders</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white <?= $current === 'broadcast.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/broadcast.php">
                                <i class="bi bi-envelope me-2"></i>
                                <span>Send Message</span>
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="p-3 border-top border-secondary small">
                    <div class="mb-2">
                        <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)
                    </div>
                    <div class="d-flex flex-column gap-1">
                        <a class="text-white text-decoration-none" href="<?= htmlspecialchars($BASE_URL) ?>/profile.php">Profile</a>
                        <a class="text-white text-decoration-none" href="<?= htmlspecialchars($BASE_URL) ?>/logout.php">Logout</a>
                    </div>
                </div>
            </div>
        </nav>
        
        <!-- Admin Content -->
        <div class="admin-content flex-grow-1" id="adminContent">
            <!-- Top Bar -->
            <div class="admin-topbar d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <button class="btn" id="sidebarToggleDesktop">
                        <i class="bi bi-list"></i>
                    </button>
                    <span>Admin Dashboard</span>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <!-- Print Orders and Print History buttons -->
                    <a href="<?= htmlspecialchars($BASE_URL) ?>/cashier/print_orders.php" class="btn">
                        <i class="bi bi-printer me-1"></i>
                        Print Orders
                    </a>
                    <a href="<?= htmlspecialchars($BASE_URL) ?>/cashier/print_history.php" class="btn">
                        <i class="bi bi-clock-history me-1"></i>
                        Print History
                    </a>
                    <!-- Set Date button -->
                    <a href="<?= htmlspecialchars($BASE_URL) ?>/cashier/dashboard.php" class="btn">
                        <i class="bi bi-calendar-date me-1"></i>
                        Set Date
                    </a>
                    <!-- AI Scan button -->
                    <a href="<?= htmlspecialchars($BASE_URL) ?>/scanner/home.php" class="btn">
                        <i class="bi bi-qr-code-scan me-1"></i>
                        AI Scan
                    </a>
                    <span><?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
                    <a href="<?= htmlspecialchars($BASE_URL) ?>/profile.php" class="btn btn-sm">Profile</a>
                    <a href="<?= htmlspecialchars($BASE_URL) ?>/logout.php" class="btn btn-sm">Logout</a>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="container-fluid py-3 flex-grow-1 d-flex flex-column">
<?php else: ?>
    <!-- Regular navbar for non-admin roles -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= htmlspecialchars($BASE_URL) ?>/index.php">Order System</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <?php if ($user): ?>
                        <?php if ($user['role'] === 'seller'): ?>
                            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/seller/order_new.php">New Order</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/seller/statistics.php">Statistics</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/seller/orders.php">Order History</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cancelled_orders.php">Manage Orders</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/scanner/view_all_items.php">View All Items</a></li>
                        <?php elseif ($user['role'] === 'cashier'): ?>
                            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/dashboard.php">Dashboard</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/print_orders.php">Print Orders</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/print_history.php">Print History</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/cancelled_orders.php">Cancelled Orders</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/broadcast.php">Send Message</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/admin/inventory.php">Inventory &amp; Cashier</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/scanner/home.php">AI Scan</a></li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <?php if ($user): ?>
                        <li class="nav-item">
                            <span class="navbar-text me-3">
                                <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)
                            </span>
                        </li>
                        <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/profile.php">Profile</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/logout.php">Logout</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <main class="flex-grow-1 d-flex flex-column">
        <div class="container-fluid py-3 flex-grow-1 d-flex flex-column">
<?php endif; ?>

<?php if ($user && $user['role'] === 'admin'): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarToggleDesktop = document.getElementById('sidebarToggleDesktop');
        const sidebarClose = document.getElementById('sidebarClose');
        const adminSidebar = document.getElementById('adminSidebar');
        const adminContent = document.getElementById('adminContent');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        function openSidebar() {
            adminSidebar.classList.remove('hidden');
            adminSidebar.classList.add('show');
            adminContent.classList.remove('expanded');
            sidebarOverlay.classList.add('show');
        }
        
        function closeSidebar() {
            adminSidebar.classList.remove('show');
            adminSidebar.classList.add('hidden');
            adminContent.classList.add('expanded');
            sidebarOverlay.classList.remove('show');
        }
        
        function toggleDesktopSidebar() {
            if (window.innerWidth >= 768) {
                if (adminSidebar.classList.contains('hidden')) {
                    openSidebar();
                    adminSidebar.classList.remove('hidden');
                    adminContent.classList.remove('expanded');
                } else {
                    closeSidebar();
                }
            } else {
                if (adminSidebar.classList.contains('show')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            }
        }
        
        // Event listeners
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                if (adminSidebar.classList.contains('show')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }
        
        if (sidebarToggleDesktop) {
            sidebarToggleDesktop.addEventListener('click', toggleDesktopSidebar);
        }
        
        if (sidebarClose) {
            sidebarClose.addEventListener('click', closeSidebar);
        }
        
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeSidebar);
        }
        
        // Close sidebar on window resize > 768px
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 768) {
                closeSidebar();
            }
        });
        
        // Close sidebar on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && adminSidebar.classList.contains('show')) {
                closeSidebar();
            }
        });
    });
</script>
<?php endif; ?>
