<?php
declare(strict_types=1);

function mec_income_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Allocate the order's net revenue once, in cents, across its product brands. */
function mec_income_allocate_order(float $revenue, array $items): array
{
    $brands = [];
    foreach ($items as $item) {
        $brand = trim((string)($item['brand'] ?? '')) ?: 'No Brand';
        if (!isset($brands[$brand])) {
            $brands[$brand] = ['brand' => $brand, 'weight' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'missing_cost_count' => 0];
        }
        $brands[$brand]['weight'] += max(0.0, (float)($item['line_total'] ?? 0));
        $brands[$brand]['cost'] += (float)($item['cost'] ?? 0);
        $brands[$brand]['missing_cost_count'] += (int)($item['missing_cost_count'] ?? 0);
    }
    if (!$brands) {
        $brands['No Brand'] = ['brand' => 'No Brand', 'weight' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'missing_cost_count' => 0];
    }
    ksort($brands, SORT_NATURAL | SORT_FLAG_CASE);
    $weight = array_sum(array_column($brands, 'weight'));
    $cents = (int)round($revenue * 100);
    $remaining = $cents;
    $fractions = [];
    foreach ($brands as $key => &$brand) {
        $share = $weight > 0 ? abs($cents) * $brand['weight'] / $weight : abs($cents) / count($brands);
        $allocated = (int)floor($share) * ($cents < 0 ? -1 : 1);
        $brand['revenue'] = $allocated / 100;
        $remaining -= $allocated;
        $fractions[$key] = $share - floor($share);
        unset($brand['weight']);
    }
    unset($brand);
    arsort($fractions, SORT_NUMERIC);
    foreach (array_keys($fractions) as $key) {
        if ($remaining === 0) break;
        $increment = $remaining < 0 ? -1 : 1;
        $brands[$key]['revenue'] += $increment / 100;
        $remaining -= $increment;
    }
    return array_values($brands);
}

/** Match Income Statement monthly product costs, including set components. */
function mec_income_unit_cost(PDO $pdo, int $productId, string $month, string $channel, array &$cache, array $trail = []): array
{
    if (!isset($cache['@loaded'])) {
        $cache['@loaded'] = true;
        $cache['@costs'] = [];
        $cache['@components'] = [];
        if (api_table_exists($pdo, 'products') && api_table_exists($pdo, 'product_sets') && api_table_exists($pdo, 'product_set_items')) {
            $components = mec_income_rows($pdo, "SELECT p.id AS set_product_id, psi.product_id, psi.quantity
                FROM products p JOIN product_sets ps ON ps.id = (SELECT MAX(s.id) FROM product_sets s WHERE s.set_name = p.name)
                JOIN product_set_items psi ON psi.product_set_id = ps.id
                WHERE LOWER(COALESCE(p.product_type, '')) = 'set'");
            foreach ($components as $component) $cache['@components'][(int)$component['set_product_id']][] = $component;
        }
        if (api_table_exists($pdo, 'product_costs')) {
            $fields = ['original_cost', 'supplier_cost', 'shipping_cost', 'other_costs', 'marketing_cost'];
            $terms = [];
            foreach ($fields as $field) {
                if (mec_has($pdo, 'product_costs', $field)) $terms[] = 'COALESCE(' . api_quote_identifier($field) . ', 0)';
            }
            $base = $terms ? implode(' + ', $terms) : '0';
            $onlineCommission = mec_has($pdo, 'product_costs', 'commission_amount') ? 'COALESCE(commission_amount, 0)' : '0';
            $offlineCommission = mec_has($pdo, 'product_costs', 'offline_commission_amount') ? 'COALESCE(offline_commission_amount, 0)' : '0';
            $dealerCommission = mec_has($pdo, 'product_costs', 'dealer_commission_amount') ? 'COALESCE(dealer_commission_amount, 0)' : '0';
            $records = mec_income_rows($pdo, "SELECT product_id, ($base) AS base_cost, $onlineCommission AS online_commission,
                $offlineCommission AS offline_commission, $dealerCommission AS dealer_commission
                FROM product_costs WHERE month_year = ? ORDER BY id ASC", [$month]);
            foreach ($records as $record) $cache['@costs'][(int)$record['product_id']] = $record;
        }
    }
    $key = $productId . '|' . $month . '|' . $channel;
    if (isset($cache[$key])) return $cache[$key];
    if ($productId <= 0 || isset($trail[$productId])) return ['amount' => 0.0, 'missing' => 1];
    $trail[$productId] = true;
    $components = $cache['@components'][$productId] ?? [];
    if ($components) {
            $result = ['amount' => 0.0, 'missing' => 0];
            foreach ($components as $component) {
                if ((float)$component['quantity'] <= 0) continue;
                $cost = mec_income_unit_cost($pdo, (int)$component['product_id'], $month, $channel, $cache, $trail);
                $result['amount'] += (float)$component['quantity'] * $cost['amount'];
                $result['missing'] += $cost['missing'];
            }
            return $cache[$key] = $result;
    }
    $record = $cache['@costs'][$productId] ?? [];
    $commissionKey = ['online' => 'online_commission', 'offline' => 'offline_commission', 'dealer' => 'dealer_commission'][$channel] ?? 'online_commission';
    $cost = (float)($record['base_cost'] ?? 0) + (float)($record[$commissionKey] ?? 0);
    return $cache[$key] = ['amount' => $cost, 'missing' => !$record || $cost <= 0 ? 1 : 0];
}

function mec_income_data(PDO $pdo, string $month): array
{
    $from = mec_month_start($month);
    $to = mec_month_end($month);
    $cache = [];
    $productSummary = [];
    $online = [];
    if (api_table_exists($pdo, 'orders')) {
        $printJoin = mec_online_print_join_sql($pdo);
        $date = mec_online_print_date_expr($pdo);
        $amountExpr = mec_online_amount_expr($pdo);
        $orders = mec_income_rows($pdo, 'SELECT o.id, ' . $amountExpr . " AS revenue,
            CASE WHEN COALESCE(o.is_paid, 0) = 1 OR LOWER(COALESCE(o.status, '')) = 'paid' THEN " . $amountExpr . " ELSE 0 END AS paid,
            CASE WHEN COALESCE(o.is_paid, 0) = 1 OR LOWER(COALESCE(o.status, '')) = 'paid' THEN 0 ELSE " . $amountExpr . " END AS unpaid
            FROM orders o $printJoin WHERE DATE($date) BETWEEN ? AND ? AND " . mec_online_active_sql(), [$from, $to]);
        $itemsByOrder = [];
        if (api_table_exists($pdo, 'order_items')) {
            $hasProducts = api_table_exists($pdo, 'products');
            $hasBrands = $hasProducts && mec_has($pdo, 'products', 'brand_id') && api_table_exists($pdo, 'brands');
            $hasSets = api_table_exists($pdo, 'product_sets') && api_table_exists($pdo, 'product_set_items');
            $setBrandExpr = ($hasSets && $hasBrands)
                ? "(SELECT cb.name FROM product_sets ps JOIN product_set_items psi ON psi.product_set_id = ps.id JOIN products cp ON cp.id = psi.product_id JOIN brands cb ON cb.id = cp.brand_id WHERE ps.set_name = p.name AND NULLIF(TRIM(cb.name), '') IS NOT NULL LIMIT 1)"
                : "NULL";
            $brandExpr = $hasBrands
                ? "COALESCE(NULLIF(TRIM(b.name), ''), $setBrandExpr, 'No Brand')"
                : "COALESCE($setBrandExpr, 'No Brand')";
            $joins = ($hasProducts ? ' LEFT JOIN products p ON p.id = oi.product_id' : '') . ($hasBrands ? ' LEFT JOIN brands b ON b.id = p.brand_id' : '');
            $items = mec_income_rows($pdo, "SELECT oi.order_id, oi.product_id, " . ($hasProducts ? 'p.name AS product_name,' : "'' AS product_name,") . " oi.quantity, oi.line_total, $brandExpr AS brand
                FROM order_items oi JOIN orders o ON o.id = oi.order_id $printJoin $joins
                WHERE DATE($date) BETWEEN ? AND ? AND " . mec_online_active_sql(), [$from, $to]);
            foreach ($items as $item) {
                $cost = mec_income_unit_cost($pdo, (int)$item['product_id'], $month, 'online', $cache);
                $item['cost'] = (float)$item['quantity'] * $cost['amount'];
                $item['missing_cost_count'] = (float)$item['quantity'] > 0 ? $cost['missing'] : 0;
                $itemsByOrder[(int)$item['order_id']][] = $item;

                $pid = (int)$item['product_id'];
                $pName = trim((string)($item['product_name'] ?? '')) ?: ('Product #' . $pid);
                $pBrand = (string)($item['brand'] ?? 'No Brand');
                if (!isset($productSummary[$pid])) {
                    $productSummary[$pid] = [
                        'product_id' => $pid,
                        'product_name' => $pName,
                        'brand' => $pBrand,
                        'online_qty' => 0.0,
                        'offline_qty' => 0.0,
                        'dealer_qty' => 0.0,
                        'total_qty' => 0.0,
                        'unit_cost' => $cost['amount'],
                        'total_cost' => 0.0,
                        'revenue' => 0.0,
                        'missing_cost_count' => 0,
                    ];
                }
                $qty = (float)$item['quantity'];
                $productSummary[$pid]['online_qty'] += $qty;
                $productSummary[$pid]['total_qty'] += $qty;
                $productSummary[$pid]['total_cost'] += $qty * $cost['amount'];
                $productSummary[$pid]['revenue'] += (float)$item['line_total'];
                if ($qty > 0 && $cost['missing']) $productSummary[$pid]['missing_cost_count'] += 1;
            }
        }
        foreach ($orders as $order) {
            $orderRev = (float)$order['revenue'];
            $orderPaid = (float)$order['paid'];
            $orderUnpaid = (float)$order['unpaid'];
            $allocated = mec_income_allocate_order($orderRev, $itemsByOrder[(int)$order['id']] ?? []);
            foreach ($allocated as $brand) {
                $key = $brand['brand'];
                if (!isset($online[$key])) $online[$key] = ['brand' => $key, 'revenue' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0, 'cost' => 0.0, 'missing_cost_count' => 0];
                $ratio = $orderRev > 0.0001 ? $brand['revenue'] / $orderRev : (count($allocated) > 0 ? 1 / count($allocated) : 1);
                $online[$key]['revenue'] += $brand['revenue'];
                $online[$key]['paid'] += $orderPaid * $ratio;
                $online[$key]['unpaid'] += $orderUnpaid * $ratio;
                $online[$key]['cost'] += $brand['cost'];
                $online[$key]['missing_cost_count'] += $brand['missing_cost_count'];
            }
        }
        uasort($online, static fn(array $a, array $b): int => strnatcasecmp($a['brand'], $b['brand']));
    }
    $offline = ['revenue' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0, 'cost' => 0.0, 'missing_cost_count' => 0];
    $offlineTeams = [];
    if (api_table_exists($pdo, 'offline_sale_orders')) {
        $dateExpr = mec_date_expr($pdo, 'offline_sale_orders', ['order_date', 'sale_date', 'created_at']);
        $date = preg_replace('/`([^`]+)`/', 'o.`$1`', (string)$dateExpr);
        $active = "LOWER(COALESCE(o.status, '')) NOT IN ('cancel', 'cancelled', 'canceled', 'return', 'returned', 'draft')";
        $hasTeamId = mec_has($pdo, 'offline_sale_orders', 'team_id');
        $hasTeams = $hasTeamId && api_table_exists($pdo, 'offline_teams');
        $teamId = $hasTeamId ? 'COALESCE(o.team_id, 0)' : '0';
        $teamName = $hasTeams ? "COALESCE(NULLIF(TRIM(t.name), ''), 'No Team')" : "'No Team'";
        $teamJoin = $hasTeams ? 'LEFT JOIN offline_teams t ON t.id = o.team_id' : '';
        // total_amount is net of buyback. Use sold goods after discount instead.
        $teams = mec_income_rows($pdo, "SELECT $teamId AS team_key, $teamName AS team_label,
            COALESCE(SUM(GREATEST(0, COALESCE(o.subtotal, 0) - COALESCE(o.discount, 0))), 0) AS revenue,
            COALESCE(SUM(LEAST(COALESCE(o.received_amount, 0), GREATEST(0, COALESCE(o.subtotal, 0) - COALESCE(o.discount, 0)))), 0) AS paid,
            COALESCE(SUM(GREATEST(0, GREATEST(0, COALESCE(o.subtotal, 0) - COALESCE(o.discount, 0)) - COALESCE(o.received_amount, 0))), 0) AS unpaid
            FROM offline_sale_orders o $teamJoin WHERE DATE($date) BETWEEN ? AND ? AND $active
            GROUP BY team_key, team_label ORDER BY team_label ASC, team_key ASC", [$from, $to]);
        foreach ($teams as $team) {
            $offlineTeams[(int)$team['team_key']] = [
                'team_id' => (int)$team['team_key'],
                'team' => (string)$team['team_label'],
                'revenue' => (float)$team['revenue'],
                'paid' => (float)$team['paid'],
                'unpaid' => (float)$team['unpaid'],
                'cost' => 0.0,
                'missing_cost_count' => 0,
            ];
        }
        if (api_table_exists($pdo, 'offline_sale_order_items')) {
            $hasProducts = api_table_exists($pdo, 'products');
            $hasBrands = $hasProducts && mec_has($pdo, 'products', 'brand_id') && api_table_exists($pdo, 'brands');
            $brandExpr = $hasBrands ? "COALESCE(NULLIF(TRIM(b.name), ''), 'No Brand')" : "'No Brand'";
            $prodJoin = ($hasProducts ? 'LEFT JOIN products p ON p.id = oi.product_id' : '') . ($hasBrands ? ' LEFT JOIN brands b ON b.id = p.brand_id' : '');
            $items = mec_income_rows($pdo, "SELECT $teamId AS team_key, oi.product_id, " . ($hasProducts ? 'p.name AS product_name,' : "'' AS product_name,") . " $brandExpr AS brand, oi.quantity, COALESCE(oi.line_total, oi.quantity * oi.unit_price, 0) AS line_total FROM offline_sale_order_items oi
                JOIN offline_sale_orders o ON o.id = oi.order_id $prodJoin WHERE DATE($date) BETWEEN ? AND ? AND $active", [$from, $to]);
            foreach ($items as $item) {
                $cost = mec_income_unit_cost($pdo, (int)$item['product_id'], $month, 'offline', $cache);
                $key = (int)$item['team_key'];
                $offlineTeams[$key]['cost'] += (float)$item['quantity'] * $cost['amount'];
                $offlineTeams[$key]['missing_cost_count'] += (float)$item['quantity'] > 0 ? $cost['missing'] : 0;

                $pid = (int)$item['product_id'];
                $pName = trim((string)($item['product_name'] ?? '')) ?: ('Product #' . $pid);
                $pBrand = trim((string)($item['brand'] ?? '')) ?: 'No Brand';
                if (!isset($productSummary[$pid])) {
                    $productSummary[$pid] = [
                        'product_id' => $pid,
                        'product_name' => $pName,
                        'brand' => $pBrand,
                        'online_qty' => 0.0,
                        'offline_qty' => 0.0,
                        'dealer_qty' => 0.0,
                        'total_qty' => 0.0,
                        'unit_cost' => $cost['amount'],
                        'total_cost' => 0.0,
                        'revenue' => 0.0,
                        'missing_cost_count' => 0,
                    ];
                } elseif ($pBrand !== 'No Brand' && ($productSummary[$pid]['brand'] === 'No Brand' || empty($productSummary[$pid]['brand']))) {
                    $productSummary[$pid]['brand'] = $pBrand;
                }
                $qty = (float)$item['quantity'];
                $productSummary[$pid]['offline_qty'] += $qty;
                $productSummary[$pid]['total_qty'] += $qty;
                $productSummary[$pid]['total_cost'] += $qty * $cost['amount'];
                $productSummary[$pid]['revenue'] += (float)($item['line_total'] ?? 0);
                if ($qty > 0 && $cost['missing']) $productSummary[$pid]['missing_cost_count'] += 1;
            }
        }
        foreach ($offlineTeams as &$team) {
            $team['revenue'] = round($team['revenue'], 2);
            $team['paid'] = round($team['paid'] ?? 0, 2);
            $team['unpaid'] = round($team['unpaid'] ?? 0, 2);
            $team['cost'] = round($team['cost'], 2);
            $offline['revenue'] += $team['revenue'];
            $offline['paid'] += $team['paid'];
            $offline['unpaid'] += $team['unpaid'];
            $offline['cost'] += $team['cost'];
            $offline['missing_cost_count'] += $team['missing_cost_count'];
        }
        unset($team);
    }
    $dealer = ['revenue' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0, 'cost' => 0.0, 'missing_cost_count' => 0];
    $dealersList = [];
    if (api_table_exists($pdo, 'dealer_orders')) {
        $dateExpr = mec_date_expr($pdo, 'dealer_orders', ['order_date', 'created_at']);
        $date = preg_replace('/`([^`]+)`/', 'o.`$1`', (string)$dateExpr);
        $active = "LOWER(COALESCE(o.status, '')) NOT IN ('draft', 'cancel', 'cancelled', 'canceled', 'return', 'returned')";
        $amountCol = mec_pick($pdo, 'dealer_orders', ['grand_total', 'total_amount', 'amount']);
        $hasDealerId = mec_has($pdo, 'dealer_orders', 'dealer_id');
        $hasDealersTable = api_table_exists($pdo, 'dealers');
        $hasDealerNameCol = mec_has($pdo, 'dealer_orders', 'dealer_name');
        $dealerIdExpr = $hasDealerId ? 'COALESCE(o.dealer_id, 0)' : '0';
        $dealerNameFallback = $hasDealerNameCol ? "NULLIF(TRIM(o.dealer_name), '')" : "NULL";
        $dealerNameExpr = $hasDealersTable
            ? "COALESCE(NULLIF(TRIM(d.name), ''), $dealerNameFallback, 'Unknown Dealer')"
            : "COALESCE($dealerNameFallback, 'Unknown Dealer')";
        $dealerJoin = $hasDealersTable ? 'LEFT JOIN dealers d ON d.id = o.dealer_id' : '';

        if ($amountCol) {
            $quotedCol = 'o.' . api_quote_identifier($amountCol);
            $dealerStats = mec_income_rows($pdo, "SELECT
                $dealerIdExpr AS dealer_key,
                $dealerNameExpr AS dealer_label,
                COALESCE(SUM($quotedCol), 0) AS revenue,
                COALESCE(SUM(COALESCE(o.paid_amount, 0)), 0) AS paid,
                COALESCE(SUM(GREATEST(0, COALESCE(o.balance_amount, $quotedCol - COALESCE(o.paid_amount, 0)))), 0) AS unpaid
                FROM dealer_orders o $dealerJoin
                WHERE DATE($date) BETWEEN ? AND ? AND $active
                GROUP BY dealer_key, dealer_label
                ORDER BY dealer_label ASC, dealer_key ASC", [$from, $to]);
            foreach ($dealerStats as $ds) {
                $k = (int)$ds['dealer_key'];
                $dKey = $k > 0 ? (string)$k : 'name_' . md5((string)$ds['dealer_label']);
                $dealersList[$dKey] = [
                    'dealer_id' => $k,
                    'dealer' => (string)$ds['dealer_label'],
                    'revenue' => (float)$ds['revenue'],
                    'paid' => (float)$ds['paid'],
                    'unpaid' => (float)$ds['unpaid'],
                    'cost' => 0.0,
                    'missing_cost_count' => 0,
                ];
            }
        }
        if (api_table_exists($pdo, 'dealer_order_items')) {
            $productId = mec_has($pdo, 'dealer_order_items', 'movement_item_id')
                ? 'COALESCE(NULLIF(oi.movement_item_id, 0), CAST(oi.product_id AS UNSIGNED))'
                : 'CAST(oi.product_id AS UNSIGNED)';
            $hasProducts = api_table_exists($pdo, 'products');
            $hasBrands = $hasProducts && mec_has($pdo, 'products', 'brand_id') && api_table_exists($pdo, 'brands');
            $brandExpr = $hasBrands ? "COALESCE(NULLIF(TRIM(b.name), ''), 'No Brand')" : "'No Brand'";
            $prodJoin = ($hasProducts ? "LEFT JOIN products p ON p.id = $productId" : '') . ($hasBrands ? ' LEFT JOIN brands b ON b.id = p.brand_id' : '');
            $items = mec_income_rows($pdo, "SELECT $dealerIdExpr AS dealer_key, $dealerNameExpr AS dealer_label, $productId AS product_id, " . ($hasProducts ? 'p.name AS product_name,' : "'' AS product_name,") . " $brandExpr AS brand, oi.quantity, COALESCE(oi.line_total, oi.quantity * oi.dealer_price, 0) AS line_total FROM dealer_order_items oi
                JOIN dealer_orders o ON o.id = oi.order_id $dealerJoin $prodJoin WHERE DATE($date) BETWEEN ? AND ? AND $active", [$from, $to]);
            foreach ($items as $item) {
                $cost = mec_income_unit_cost($pdo, (int)$item['product_id'], $month, 'dealer', $cache);
                $k = (int)$item['dealer_key'];
                $dKey = $k > 0 ? (string)$k : 'name_' . md5((string)$item['dealer_label']);
                if (isset($dealersList[$dKey])) {
                    $dealersList[$dKey]['cost'] += (float)$item['quantity'] * $cost['amount'];
                    $dealersList[$dKey]['missing_cost_count'] += (float)$item['quantity'] > 0 ? $cost['missing'] : 0;
                }

                $pid = (int)$item['product_id'];
                $pName = trim((string)($item['product_name'] ?? '')) ?: ('Product #' . $pid);
                $pBrand = trim((string)($item['brand'] ?? '')) ?: 'No Brand';
                if (!isset($productSummary[$pid])) {
                    $productSummary[$pid] = [
                        'product_id' => $pid,
                        'product_name' => $pName,
                        'brand' => $pBrand,
                        'online_qty' => 0.0,
                        'offline_qty' => 0.0,
                        'dealer_qty' => 0.0,
                        'total_qty' => 0.0,
                        'unit_cost' => $cost['amount'],
                        'total_cost' => 0.0,
                        'revenue' => 0.0,
                        'missing_cost_count' => 0,
                    ];
                } elseif ($pBrand !== 'No Brand' && ($productSummary[$pid]['brand'] === 'No Brand' || empty($productSummary[$pid]['brand']))) {
                    $productSummary[$pid]['brand'] = $pBrand;
                }
                $qty = (float)$item['quantity'];
                $productSummary[$pid]['dealer_qty'] += $qty;
                $productSummary[$pid]['total_qty'] += $qty;
                $productSummary[$pid]['total_cost'] += $qty * $cost['amount'];
                $productSummary[$pid]['revenue'] += (float)($item['line_total'] ?? 0);
                if ($qty > 0 && $cost['missing']) $productSummary[$pid]['missing_cost_count'] += 1;
            }
        }
        foreach ($dealersList as &$dealerItem) {
            $dealerItem['revenue'] = round($dealerItem['revenue'], 2);
            $dealerItem['paid'] = round($dealerItem['paid'] ?? 0, 2);
            $dealerItem['unpaid'] = round($dealerItem['unpaid'] ?? 0, 2);
            $dealerItem['cost'] = round($dealerItem['cost'], 2);
            $dealer['revenue'] += $dealerItem['revenue'];
            $dealer['paid'] += $dealerItem['paid'];
            $dealer['unpaid'] += $dealerItem['unpaid'];
            $dealer['cost'] += $dealerItem['cost'];
            $dealer['missing_cost_count'] += $dealerItem['missing_cost_count'];
        }
        unset($dealerItem);
    }
    $expenseGroups = [];
    if (api_table_exists($pdo, 'finance_spending')) {
        $records = mec_income_rows($pdo, "SELECT COALESCE(NULLIF(TRIM(category), ''), 'Uncategorized') AS category,
            COALESCE(NULLIF(TRIM(SUBSTRING_INDEX(sub_category, ',', 1)), ''), 'General') AS sub_category,
            COALESCE(SUM(amount), 0) AS amount FROM finance_spending
            WHERE DATE(spending_date) BETWEEN ? AND ?
              AND (TRIM(COALESCE(status, '')) = '' OR LOWER(TRIM(status)) IN ('approved', 'completed'))
            GROUP BY category, COALESCE(NULLIF(TRIM(SUBSTRING_INDEX(sub_category, ',', 1)), ''), 'General')
            ORDER BY category ASC, sub_category ASC", [$from, $to]);
        foreach ($records as $record) {
            $key = $record['category'];
            if (!isset($expenseGroups[$key])) $expenseGroups[$key] = ['category' => $key, 'total' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0, 'rows' => []];
            $expenseGroups[$key]['total'] += (float)$record['amount'];
            $expenseGroups[$key]['paid'] += (float)$record['amount'];
            $expenseGroups[$key]['rows'][] = [
                'label' => $record['sub_category'],
                'amount' => (float)$record['amount'],
                'paid' => (float)$record['amount'],
                'unpaid' => 0.0,
            ];
        }
    }
    foreach ($online as &$brand) {
        $brand['revenue'] = round($brand['revenue'], 2);
        $brand['paid'] = round($brand['paid'] ?? 0, 2);
        $brand['unpaid'] = round($brand['unpaid'] ?? 0, 2);
        $brand['cost'] = round($brand['cost'], 2);
    }
    unset($brand);
    $offline['revenue'] = round($offline['revenue'], 2);
    $offline['paid'] = round($offline['paid'] ?? 0, 2);
    $offline['unpaid'] = round($offline['unpaid'] ?? 0, 2);
    $offline['cost'] = round($offline['cost'], 2);
    $dealer['revenue'] = round($dealer['revenue'], 2);
    $dealer['paid'] = round($dealer['paid'] ?? 0, 2);
    $dealer['unpaid'] = round($dealer['unpaid'] ?? 0, 2);
    $dealer['cost'] = round($dealer['cost'], 2);
    $revenue = array_sum(array_column($online, 'revenue')) + $offline['revenue'] + $dealer['revenue'];
    $totalPaid = array_sum(array_column($online, 'paid')) + $offline['paid'] + $dealer['paid'];
    $totalUnpaid = array_sum(array_column($online, 'unpaid')) + $offline['unpaid'] + $dealer['unpaid'];
    $cost = array_sum(array_column($online, 'cost')) + $offline['cost'] + $dealer['cost'];
    $expenses = array_sum(array_column($expenseGroups, 'total'));
    $onlineSummary = [
        'revenue' => round(array_sum(array_column($online, 'revenue')), 2),
        'paid' => round(array_sum(array_column($online, 'paid')), 2),
        'unpaid' => round(array_sum(array_column($online, 'unpaid')), 2),
        'cost' => round(array_sum(array_column($online, 'cost')), 2),
        'missing_cost_count' => (int)array_sum(array_column($online, 'missing_cost_count')),
    ];
    foreach ($productSummary as &$prod) {
        $prod['total_qty'] = round($prod['total_qty'], 2);
        $prod['online_qty'] = round($prod['online_qty'], 2);
        $prod['offline_qty'] = round($prod['offline_qty'], 2);
        $prod['dealer_qty'] = round($prod['dealer_qty'], 2);
        $prod['unit_cost'] = round($prod['unit_cost'], 2);
        $prod['total_cost'] = round($prod['total_cost'], 2);
        $prod['revenue'] = round($prod['revenue'], 2);
        $prod['profit'] = round($prod['revenue'] - $prod['total_cost'], 2);
        $prod['margin_percent'] = $prod['revenue'] > 0.001 ? round(($prod['profit'] / $prod['revenue']) * 100, 2) : 0.0;
    }
    unset($prod);
    uasort($productSummary, static fn($a, $b) => strnatcasecmp($a['product_name'], $b['product_name']));

    return [
        'online' => $onlineSummary,
        'online_brands' => array_values($online),
        'offline' => $offline,
        'offline_teams' => array_values($offlineTeams),
        'dealer' => $dealer,
        'dealers' => array_values($dealersList),
        'products' => array_values($productSummary),
        'expense_groups' => array_values($expenseGroups),
        'summary' => [
            'revenue' => round($revenue, 2),
            'paid' => round($totalPaid, 2),
            'unpaid' => round($totalUnpaid, 2),
            'product_cost' => round($cost, 2),
            'expenses' => round($expenses, 2),
            'profit' => round($revenue - $cost - $expenses, 2),
            'missing_cost_count' => array_sum(array_column($online, 'missing_cost_count')) + $offline['missing_cost_count'] + $dealer['missing_cost_count'],
        ],
    ];
}
