<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'finance_reports.view');
require_once __DIR__ . '/../db.php';

// Don't include the layout header - this is a standalone print page

$pdo = get_db_connection();

// Get filter parameters
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$category = $_GET['category'] ?? '';

// Build base query
$sql = "SELECT * FROM finance_spending WHERE DATE(spending_date) BETWEEN ? AND ?";
$params = [$from_date, $to_date];

if ($category !== '') {
    $sql .= " AND category = ?";
    $params[] = $category;
}

$sql .= " ORDER BY category, spending_date";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$spending_records = $stmt->fetchAll();

// Group by main category and subcategory
$category_data = [];
$grand_total = 0;

foreach ($spending_records as $record) {
    $main_cat = $record['category'];
    
    // Initialize main category if not exists
    if (!isset($category_data[$main_cat])) {
        $category_data[$main_cat] = [
            'total' => 0,
            'subcategories' => []
        ];
    }
    
    // Process subcategories
    $subcategories = [];
    if (!empty($record['sub_categories'])) {
        $sub_cats = json_decode($record['sub_categories'], true);
        if (is_array($sub_cats)) {
            $subcategories = array_filter($sub_cats, function($cat) {
                return !empty(trim($cat));
            });
        }
    }
    
    if (empty($subcategories) && !empty($record['sub_category'])) {
        $subcategories = [$record['sub_category']];
    }
    
    // Distribute amount among subcategories
    $amount_per_subcat = count($subcategories) > 0 ? $record['amount'] / count($subcategories) : $record['amount'];
    
    if (empty($subcategories)) {
        $subcategories = ['Uncategorized'];
    }
    
    foreach ($subcategories as $subcat) {
        $subcat_key = strtolower(str_replace(' ', '_', $subcat));
        if (!isset($category_data[$main_cat]['subcategories'][$subcat_key])) {
            $category_data[$main_cat]['subcategories'][$subcat_key] = [
                'name' => ucfirst(str_replace('_', ' ', $subcat)),
                'amount' => 0,
                'count' => 0
            ];
        }
        
        $category_data[$main_cat]['subcategories'][$subcat_key]['amount'] += $amount_per_subcat;
        $category_data[$main_cat]['subcategories'][$subcat_key]['count']++;
    }
    
    $category_data[$main_cat]['total'] += $record['amount'];
    $grand_total += $record['amount'];
}

// Get main categories from database
$stmt = $pdo->query("SELECT name FROM finance_categories WHERE type = 'main' ORDER BY name");
$main_categories_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Convert to array format for compatibility
$main_categories = [];
foreach ($main_categories_data as $cat) {
    $main_categories[$cat['name']] = ucfirst(str_replace('_', ' ', $cat['name']));
}

?>
<html>
<head>
    <meta charset="UTF-8">
    <title>Finance Summary Report</title>
    <style>
        @page {
            size: A4;
            margin: 15mm;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            font-size: 12px;
            line-height: 1.4;
            margin: 0;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
        }
        
        .header h1 {
            font-size: 24px;
            margin: 0;
            color: #333;
        }
        
        .header .date-range {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        
        .category-section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        
        .category-header {
            background-color: #f8f9fa;
            padding: 12px;
            border: 1px solid #dee2e6;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 10px;
            border-radius: 4px;
        }
        
        .subcategory-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .subcategory-table th {
            background-color: #ffffff;
            border-bottom: 2px solid #dee2e6;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            color: #495057;
        }
        
        .subcategory-table td {
            border-bottom: 1px solid #e9ecef;
            padding: 8px 10px;
            font-size: 11px;
            color: #212529;
        }
        
        .subcategory-table tr:last-child td {
            border-bottom: none;
        }
        
        .subcategory-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .subcategory-table .amount {
            text-align: right;
            font-weight: 600;
            color: #dc3545;
        }
        
        .category-total {
            text-align: right;
            font-weight: bold;
            font-size: 13px;
            padding: 12px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            color: #212529;
        }
        
        .grand-total {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin-top: 30px;
            padding: 18px;
            background-color: #e7f3ff;
            border: 2px solid #0066cc;
            border-radius: 6px;
            color: #0066cc;
        }
        
        .no-data {
            text-align: center;
            font-style: italic;
            color: #666;
            padding: 20px;
        }
        
        @media print {
            body { margin: 0; }
            .no-print { display: none !important; }
            
            /* Hide all navigation and UI elements */
            nav, .navbar, .sidebar, .menu, .header-nav, 
            .top-nav, .side-nav, .navigation, .admin-menu,
            .breadcrumb, .footer, .admin-footer {
                display: none !important;
            }
            
            /* Ensure full width for printing */
            .container-fluid, .container {
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* Remove shadows and borders for clean print */
            * {
                box-shadow: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Finance Summary Report</h1>
        <div class="date-range">
            Period: <?= htmlspecialchars($from_date) ?> to <?= htmlspecialchars($to_date) ?>
            <?php if ($category !== ''): ?>
                | Category: <?= ucfirst($category) ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($category_data)): ?>
        <div class="no-data">
            No spending data found for the selected period.
        </div>
    <?php else: ?>
        <?php foreach ($main_categories as $cat_key => $cat_label): ?>
            <?php if (isset($category_data[$cat_key])): ?>
                <div class="category-section">
                    <div class="category-header">
                        <?= $cat_label ?>
                    </div>
                    
                    <table class="subcategory-table">
                        <thead>
                            <tr>
                                <th style="width: 60%;">Sub Category</th>
                                <th style="width: 15%;">Transactions</th>
                                <th style="width: 25%;">Amount ($)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $subcats = $category_data[$cat_key]['subcategories'];
                            // Sort by amount descending
                            uasort($subcats, function($a, $b) {
                                return $b['amount'] <=> $a['amount'];
                            });
                            
                            foreach ($subcats as $subcat): 
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($subcat['name']) ?></td>
                                    <td><?= $subcat['count'] ?></td>
                                    <td class="amount"><?= number_format($subcat['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="category-total">
                        <?= $cat_label ?> Total: $<?= number_format($category_data[$cat_key]['total'], 2) ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        
        <div class="grand-total">
            GRAND TOTAL: $<?= number_format($grand_total, 2) ?>
        </div>
    <?php endif; ?>

        <div class="no-print" style="margin-top: 30px; text-align: center;">
            <button onclick="window.print()" style="padding: 10px 20px; font-size: 14px; margin-bottom: 10px;">
                🖨️ Print / Save as PDF
            </button>
            <br>
            <small style="color: #666;">
                💡 Tip: Choose "Save as PDF" in print dialog to save as PDF file
            </small>
            <br><br>
            <button onclick="window.close()" style="padding: 8px 16px; font-size: 12px;">
                ❌ Close Window
            </button>
        </div>

    <script>
        // Auto-trigger print dialog when page loads
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });
        
        // Close window after printing (optional)
        window.addEventListener('afterprint', function() {
            setTimeout(function() {
                // Don't auto-close to allow user to save as PDF
                // window.close();
            }, 1000);
        });
        
        // Add keyboard shortcut for PDF saving
        document.addEventListener('keydown', function(e) {
            // Ctrl+S or Cmd+S to save as PDF
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                window.print();
            }
        });
    </script>
</body>
</html>
