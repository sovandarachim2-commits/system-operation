<?php
/**
 * Creates the purchase_payment_summary view.
 * Run once via: php create_payment_summary_view.php
 * Or visit: http://localhost/Purchase/create_payment_summary_view.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db_connection();
    $pdo->exec("DROP VIEW IF EXISTS purchase_payment_summary");
    $pdo->exec("CREATE VIEW purchase_payment_summary AS
SELECT
    po.id,
    po.vendor_id,
    po.order_number,
    COALESCE(pv.name, '') AS vendor_name,
    po.order_date,
    COALESCE(po.expected_date, po.order_date) AS due_date,
    COALESCE(poi_ord.total_quantity_ordered, 0) AS total_quantity_ordered,
    COALESCE(pri_rec.total_quantity_received, 0) AS total_quantity_received,
    COALESCE(poi_ord.total_quantity_ordered, 0) - COALESCE(pri_rec.total_quantity_received, 0) AS quantity_not_received,
    COALESCE(pri_qty_ret.total_qty_return, 0) AS total_qty_return,
    COALESCE(po.total_amount, 0) AS total_amount,
    COALESCE(pr_ret.total_return, 0) AS total_return,
    COALESCE(po.total_paid, 0) AS total_paid,
    GREATEST(0, COALESCE(po.total_amount, 0) - COALESCE(po.total_paid, 0)) AS balance_due,
    CASE
        WHEN COALESCE(po.total_paid, 0) > COALESCE(po.total_amount, 0) THEN 'overpaid'
        WHEN COALESCE(po.total_paid, 0) >= COALESCE(po.total_amount, 0) THEN 'paid'
        WHEN COALESCE(po.total_paid, 0) > 0 THEN 'partial'
        ELSE 'unpaid'
    END AS payment_status,
    CASE
        WHEN COALESCE(po.total_amount, 0) - COALESCE(po.total_paid, 0) <= 0 THEN 'on_time'
        WHEN COALESCE(po.expected_date, po.order_date) IS NULL THEN 'on_time'
        WHEN COALESCE(po.expected_date, po.order_date) < CURDATE() THEN 'overdue'
        WHEN COALESCE(po.expected_date, po.order_date) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'due_soon'
        ELSE 'on_time'
    END AS urgency_status,
    COALESCE(pp_cnt.payment_count, 0) AS payment_count
FROM purchase_orders po
LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id
LEFT JOIN (
    SELECT purchase_order_id, SUM(quantity_ordered) AS total_quantity_ordered
    FROM purchase_order_items
    GROUP BY purchase_order_id
) poi_ord ON poi_ord.purchase_order_id = po.id
LEFT JOIN (
    SELECT poi.purchase_order_id, SUM(pri.quantity_received) AS total_quantity_received
    FROM purchase_receiving_items pri
    JOIN purchase_order_items poi ON poi.id = pri.purchase_order_item_id
    GROUP BY poi.purchase_order_id
) pri_rec ON pri_rec.purchase_order_id = po.id
LEFT JOIN (
    SELECT pr.purchase_order_id, SUM(pri.quantity_returned) AS total_qty_return
    FROM purchase_returns pr
    JOIN purchase_return_items pri ON pri.purchase_return_id = pr.id
    GROUP BY pr.purchase_order_id
) pri_qty_ret ON pri_qty_ret.purchase_order_id = po.id
LEFT JOIN (
    SELECT purchase_order_id, SUM(total_amount) AS total_return
    FROM purchase_returns
    GROUP BY purchase_order_id
) pr_ret ON pr_ret.purchase_order_id = po.id
LEFT JOIN (
    SELECT purchase_order_id, COUNT(*) AS payment_count
    FROM purchase_payments
    GROUP BY purchase_order_id
) pp_cnt ON pp_cnt.purchase_order_id = po.id");
    echo "SUCCESS: purchase_payment_summary view created.\n";
    $stmt = $pdo->query("SELECT COUNT(*) FROM purchase_payment_summary");
    $count = $stmt->fetchColumn();
    echo "Rows in view: $count\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
