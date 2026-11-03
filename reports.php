<?php
// reports.php
// Business Analytics, Sales Reports, Inventory Valuation, and Printable Exports

require_once 'auth.php';
require_once 'db.php';

$company_name = get_system_setting($db, 'company_name', 'i-Panel Baddegama Portal');
$company_phone = get_system_setting($db, 'company_phone', '077 123 4567 / 091 223 4567');
$company_address = get_system_setting($db, 'company_address', 'Main Street, Baddegama, Galle');
$currency = get_system_setting($db, 'currency_symbol', 'Rs.');

// -------------------------------------------------------------
// Parameters & Filter Setup
// -------------------------------------------------------------
$tab = $_GET['tab'] ?? 'sales'; // 'sales', 'inventory', 'projects'

// Date ranges for sales report
$date_preset = $_GET['preset'] ?? '';
$today = date('Y-m-d');

if ($date_preset === 'today') {
    $date_from = $today;
    $date_to = $today;
} elseif ($date_preset === 'yesterday') {
    $date_from = date('Y-m-d', strtotime('-1 day'));
    $date_to = date('Y-m-d', strtotime('-1 day'));
} elseif ($date_preset === 'this_week') {
    $date_from = date('Y-m-d', strtotime('monday this week'));
    $date_to = $today;
} elseif ($date_preset === 'this_month') {
    $date_from = date('Y-m-01');
    $date_to = $today;
} elseif ($date_preset === 'last_month') {
    $date_from = date('Y-m-01', strtotime('first day of last month'));
    $date_to = date('Y-m-t', strtotime('last day of last month'));
} elseif ($date_preset === 'this_year') {
    $date_from = date('Y-01-01');
    $date_to = $today;
} elseif ($date_preset === 'all') {
    $date_from = '2020-01-01';
    $date_to = $today;
} else {
    // Default to first day of current month to today
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? $today;
}

$payment_method = $_GET['payment_method'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$stock_status = $_GET['stock_status'] ?? 'all';
$project_status = $_GET['project_status'] ?? 'all';

// -------------------------------------------------------------
// TAB 1: Sales / Invoices Data Fetching
// -------------------------------------------------------------
$sales_data = [];
$total_sales_revenue = 0;
$total_sales_count = 0;
$cash_total = 0;
$card_total = 0;
$bank_total = 0;

if ($tab === 'sales') {
    $query = "SELECT q.*, p.client_name, p.client_phone, 
              (SELECT SUM(total_price) FROM quotation_items WHERE quotation_id = q.id) as items_subtotal,
              (SELECT SUM(quantity) FROM quotation_items WHERE quotation_id = q.id) as items_qty
              FROM quotations q
              LEFT JOIN projects p ON q.project_id = p.id
              WHERE date(q.created_at) >= :date_from AND date(q.created_at) <= :date_to";

    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];

    if ($payment_method !== 'all') {
        $query .= " AND q.payment_method = :payment_method";
        $params[':payment_method'] = $payment_method;
    }

    $query .= " ORDER BY q.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $raw_sales = $stmt->fetchAll();

    foreach ($raw_sales as $row) {
        $items_subtotal = floatval($row['items_subtotal'] ?? 0);
        $subtotal = $items_subtotal + floatval($row['labor_cost'] ?? 0) + floatval($row['transport_cost'] ?? 0);
        $discount = $subtotal * (floatval($row['discount_percentage'] ?? 0) / 100);
        $taxable = $subtotal - $discount;
        $tax = $taxable * (floatval($row['tax_percentage'] ?? 0) / 100);
        $net_total = $taxable + $tax;

        $row['computed_total'] = $net_total;
        $row['items_count'] = intval($row['items_qty'] ?? 0);
        $sales_data[] = $row;

        $total_sales_revenue += $net_total;
        $total_sales_count++;

        $method = strtolower($row['payment_method'] ?? 'cash');
        if (strpos($method, 'cash') !== false) {
            $cash_total += $net_total;
        } elseif (strpos($method, 'card') !== false) {
            $card_total += $net_total;
        } else {
            $bank_total += $net_total;
        }
    }

    // CSV Export Handler
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sales_report_' . $date_from . '_to_' . $date_to . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Invoice/Quote #', 'Date & Time', 'Customer Name', 'Contact Phone', 'Type', 'Payment Method', 'Items Qty', 'Net Total (' . $currency . ')']);
        foreach ($sales_data as $s) {
            fputcsv($out, [
                $s['quotation_number'],
                $s['created_at'],
                $s['client_name'] ?? 'Walk-in Customer',
                $s['client_phone'] ?? '-',
                $s['is_direct_bill'] ? 'Direct Bill' : 'Project Quotation',
                $s['payment_method'] ?? 'Cash',
                $s['items_count'],
                number_format($s['computed_total'], 2, '.', '')
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Total Revenue', '', '', '', '', '', '', number_format($total_sales_revenue, 2, '.', '')]);
        fclose($out);
        exit;
    }
}

// -------------------------------------------------------------
// TAB 2: Inventory Stock Valuation Data Fetching
// -------------------------------------------------------------
$inventory_data = [];
$total_inventory_val = 0;
$total_stock_units = 0;
$low_stock_count = 0;
$out_of_stock_count = 0;

if ($tab === 'inventory') {
    $inv_query = "SELECT * FROM inventory WHERE 1=1";
    $inv_params = [];

    if ($category_filter !== 'all') {
        $inv_query .= " AND category = :category";
        $inv_params[':category'] = $category_filter;
    }

    if ($stock_status === 'normal') {
        $inv_query .= " AND stock > 20";
    } elseif ($stock_status === 'low') {
        $inv_query .= " AND stock <= 20 AND stock > 0";
    } elseif ($stock_status === 'out') {
        $inv_query .= " AND stock <= 0";
    }

    $inv_query .= " ORDER BY category ASC, name ASC";

    $stmt_inv = $db->prepare($inv_query);
    $stmt_inv->execute($inv_params);
    $inventory_data = $stmt_inv->fetchAll();

    foreach ($inventory_data as $item) {
        $val = floatval($item['price']) * intval($item['stock']);
        $total_inventory_val += $val;
        $total_stock_units += intval($item['stock']);
        if ($item['stock'] <= 0) {
            $out_of_stock_count++;
        } elseif ($item['stock'] <= 20) {
            $low_stock_count++;
        }
    }

    // CSV Export Handler
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inventory_stock_report_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Material Name', 'Category', 'Profile/Type', 'Width (mm)', 'Length (ft)', 'Color', 'Unit Price (' . $currency . ')', 'Stock Units', 'Stock Valuation (' . $currency . ')', 'Status']);
        foreach ($inventory_data as $it) {
            $val = floatval($it['price']) * intval($it['stock']);
            $status = ($it['stock'] <= 0) ? 'Out of Stock' : (($it['stock'] <= 20) ? 'Low Stock' : 'In Stock');
            fputcsv($out, [
                $it['name'],
                ucfirst($it['category']),
                $it['type'] ?? '-',
                $it['width_mm'] ?? '-',
                $it['length_ft'] ?? '-',
                $it['color'] ?? '-',
                number_format($it['price'], 2, '.', ''),
                $it['stock'],
                number_format($val, 2, '.', ''),
                $status
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Total Inventory Value', '', '', '', '', '', '', $total_stock_units, number_format($total_inventory_val, 2, '.', ''), '']);
        fclose($out);
        exit;
    }
}

// -------------------------------------------------------------
// TAB 3: Client Projects Data Fetching
// -------------------------------------------------------------
$projects_data = [];
$total_project_count = 0;
$completed_project_count = 0;
$active_project_count = 0;
$total_quoted_pipeline = 0;

if ($tab === 'projects') {
    $proj_query = "SELECT p.*, 
                  (SELECT COUNT(*) FROM quotations WHERE project_id = p.id) as quote_count,
                  (SELECT SUM((SELECT SUM(total_price) FROM quotation_items WHERE quotation_id = q.id)) 
                   FROM quotations q WHERE q.project_id = p.id) as total_quoted
                   FROM projects p WHERE 1=1";
    $proj_params = [];

    if ($project_status !== 'all') {
        $proj_query .= " AND p.status = :status";
        $proj_params[':status'] = $project_status;
    }

    $proj_query .= " ORDER BY p.created_at DESC";

    $stmt_p = $db->prepare($proj_query);
    $stmt_p->execute($proj_params);
    $projects_data = $stmt_p->fetchAll();

    foreach ($projects_data as $p) {
        $total_project_count++;
        if ($p['status'] === 'Completed') {
            $completed_project_count++;
        } else {
            $active_project_count++;
        }
        $total_quoted_pipeline += floatval($p['total_quoted'] ?? 0);
    }
}

require_once 'header.php';
?>

<style>
/* Print-specific formatting */
@media print {
    .no-print, .app-sidebar, .mobile-header, .btn, form, .filter-bar, .report-tabs {
        display: none !important;
    }
    body, .main-content {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        color: #000 !important;
    }
    .print-only-header {
        display: block !important;
        margin-bottom: 24px;
        border-bottom: 2px solid #000;
        padding-bottom: 12px;
    }
    .section-card {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
    }
    table {
        width: 100% !important;
        border-collapse: collapse !important;
    }
    th, td {
        border: 1px solid #ddd !important;
        padding: 8px 10px !important;
        font-size: 11px !important;
        color: #000 !important;
    }
    th {
        background-color: #f1f5f9 !important;
    }
}
.print-only-header {
    display: none;
}
.report-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--border-color);
    padding-bottom: 10px;
    flex-wrap: wrap;
}
.report-tab-btn {
    padding: 10px 18px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 14px;
    color: var(--text-muted);
    background: transparent;
    border: 1px solid transparent;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: var(--transition-normal);
}
.report-tab-btn:hover {
    color: var(--primary-brand);
    background: var(--accent-light);
}
.report-tab-btn.active {
    background-color: var(--primary-brand);
    color: #ffffff;
    box-shadow: var(--shadow-sm);
}
.filter-bar {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 18px 20px;
    margin-bottom: 24px;
}
.preset-chips {
    display: flex;
    gap: 6px;
    margin-top: 10px;
    flex-wrap: wrap;
}
.chip {
    padding: 4px 10px;
    font-size: 12px;
    border-radius: 20px;
    background: var(--accent-light);
    color: var(--text-muted);
    text-decoration: none;
    font-weight: 500;
}
.chip:hover, .chip.active {
    background: var(--primary-brand);
    color: #fff;
}
</style>

<!-- Printable Letterhead (Only visible in Print) -->
<div class="print-only-header">
    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
        <div>
            <h1 style="font-size: 20px; margin: 0; color: #000; text-transform: uppercase;"><?= htmlspecialchars($company_name) ?></h1>
            <p style="margin: 2px 0; font-size: 11px;"><?= htmlspecialchars($company_address) ?> | Tel: <?= htmlspecialchars($company_phone) ?></p>
        </div>
        <div style="text-align: right;">
            <div style="font-weight: bold; font-size: 13px; text-transform: uppercase;">
                <?= $tab === 'sales' ? 'Sales & Revenue Report' : ($tab === 'inventory' ? 'Inventory Stock Valuation Report' : 'Client Projects Report') ?>
            </div>
            <div style="font-size: 10px; color: #555;">Generated: <?= date('Y-m-d H:i') ?> | By: <?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></div>
            <?php if ($tab === 'sales'): ?>
                <div style="font-size: 10px; color: #555;">Period: <?= htmlspecialchars($date_from) ?> to <?= htmlspecialchars($date_to) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Header Toolbar -->
<div class="no-print" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h2 style="font-size: 24px; text-transform: uppercase;">Reports & Business Analytics</h2>
        <p style="color: var(--text-muted); font-size: 13px;">View sales breakdowns, track material stock valuation, and print official reports.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <button type="button" class="btn btn-secondary" onclick="window.print()" style="display: inline-flex; align-items: center; gap: 6px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Print / Export PDF
        </button>
        <?php if ($tab === 'sales'): ?>
            <a href="reports.php?tab=sales&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&payment_method=<?= urlencode($payment_method) ?>&export=csv" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Export CSV
            </a>
        <?php elseif ($tab === 'inventory'): ?>
            <a href="reports.php?tab=inventory&category=<?= urlencode($category_filter) ?>&stock_status=<?= urlencode($stock_status) ?>&export=csv" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Export CSV
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Navigation Tabs -->
<div class="report-tabs no-print">
    <a href="reports.php?tab=sales" class="report-tab-btn <?= ($tab === 'sales') ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
        Sales & Invoices Report (විකුණුම්)
    </a>
    <a href="reports.php?tab=inventory" class="report-tab-btn <?= ($tab === 'inventory') ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path></svg>
        Inventory Stock Valuation (තොග වාර්තාව)
    </a>
    <a href="reports.php?tab=projects" class="report-tab-btn <?= ($tab === 'projects') ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
        Client Projects Report (ප්‍රොජෙක්ට්)
    </a>
</div>

<?php if ($tab === 'sales'): ?>
    <!-- TAB 1: Sales & Direct Invoices -->

    <!-- Metrics Cards -->
    <div class="dashboard-grid" style="margin-bottom: 24px;">
        <div class="card card-primary">
            <div class="card-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                Total Revenue (තෝරාගත් කාලය)
            </div>
            <div class="card-value"><?= $currency ?> <?= number_format($total_sales_revenue, 2) ?></div>
            <div class="card-desc"><?= number_format($total_sales_count) ?> completed transaction(s)</div>
        </div>

        <div class="card">
            <div class="card-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: var(--success);"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                Cash Collections
            </div>
            <div class="card-value"><?= $currency ?> <?= number_format($cash_total, 2) ?></div>
            <div class="card-desc">Cash counter transactions</div>
        </div>

        <div class="card">
            <div class="card-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: var(--primary-brand);"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                Card / Digital Payments
            </div>
            <div class="card-value"><?= $currency ?> <?= number_format($card_total, 2) ?></div>
            <div class="card-desc">Visa / MasterCard / POS</div>
        </div>

        <div class="card">
            <div class="card-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: var(--warning);"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v4M12 14v4M16 14v4"></path></svg>
                Bank Transfer / Cheques
            </div>
            <div class="card-value"><?= $currency ?> <?= number_format($bank_total, 2) ?></div>
            <div class="card-desc">Direct deposits & cheques</div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="filter-bar no-print">
        <form action="reports.php" method="GET" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
            <input type="hidden" name="tab" value="sales">

            <div class="form-group" style="margin: 0; min-width: 170px;">
                <label for="date_from" style="font-size: 13px; font-weight: 600;">From Date (සිට)</label>
                <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
            </div>

            <div class="form-group" style="margin: 0; min-width: 170px;">
                <label for="date_to" style="font-size: 13px; font-weight: 600;">To Date (දක්වා)</label>
                <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
            </div>

            <div class="form-group" style="margin: 0; min-width: 180px;">
                <label for="payment_method" style="font-size: 13px; font-weight: 600;">Payment Method</label>
                <select id="payment_method" name="payment_method">
                    <option value="all" <?= ($payment_method === 'all') ? 'selected' : '' ?>>All Methods</option>
                    <option value="Cash" <?= ($payment_method === 'Cash') ? 'selected' : '' ?>>Cash</option>
                    <option value="Card" <?= ($payment_method === 'Card') ? 'selected' : '' ?>>Card</option>
                    <option value="Bank Transfer" <?= ($payment_method === 'Bank Transfer') ? 'selected' : '' ?>>Bank Transfer</option>
                    <option value="Cheque" <?= ($payment_method === 'Cheque') ? 'selected' : '' ?>>Cheque</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="height: 48px;">Filter Report</button>
            <a href="reports.php?tab=sales" class="btn btn-secondary" style="height: 48px; display: inline-flex; align-items: center;">Reset</a>
        </form>

        <div class="preset-chips">
            <span style="font-size: 12px; color: var(--text-muted); align-self: center; margin-right: 4px;">Quick Presets:</span>
            <a href="reports.php?tab=sales&preset=today" class="chip <?= ($date_preset === 'today') ? 'active' : '' ?>">Today</a>
            <a href="reports.php?tab=sales&preset=yesterday" class="chip <?= ($date_preset === 'yesterday') ? 'active' : '' ?>">Yesterday</a>
            <a href="reports.php?tab=sales&preset=this_week" class="chip <?= ($date_preset === 'this_week') ? 'active' : '' ?>">This Week</a>
            <a href="reports.php?tab=sales&preset=this_month" class="chip <?= ($date_preset === 'this_month' || empty($date_preset)) ? 'active' : '' ?>">This Month</a>
            <a href="reports.php?tab=sales&preset=last_month" class="chip <?= ($date_preset === 'last_month') ? 'active' : '' ?>">Last Month</a>
            <a href="reports.php?tab=sales&preset=this_year" class="chip <?= ($date_preset === 'this_year') ? 'active' : '' ?>">This Year</a>
            <a href="reports.php?tab=sales&preset=all" class="chip <?= ($date_preset === 'all') ? 'active' : '' ?>">All Time</a>
        </div>
    </div>

    <!-- Sales Table -->
    <div class="section-card">
        <div class="section-header" style="margin-bottom: 16px;">
            <div>
                <h3 style="text-transform: uppercase; font-size: 16px;">Invoices & Sales Transactions</h3>
                <p style="color: var(--text-muted); font-size: 13px;">Showing transactions between <?= htmlspecialchars($date_from) ?> and <?= htmlspecialchars($date_to) ?></p>
            </div>
            <div style="font-size: 13px; font-weight: 700; color: var(--primary-brand);">
                Records: <?= count($sales_data) ?>
            </div>
        </div>

        <?php if (empty($sales_data)): ?>
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 10px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <p style="font-size: 15px;">No sales transactions found for the selected date range and filter criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Invoice / Quote #</th>
                            <th>Client / Customer</th>
                            <th>Type</th>
                            <th>Payment</th>
                            <th>Items</th>
                            <th style="text-align: right;">Amount (<?= $currency ?>)</th>
                            <th class="no-print" style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sales_data as $sale): ?>
                        <tr>
                            <td style="font-size: 13px; color: var(--text-muted); white-space: nowrap;"><?= date('Y-m-d H:i', strtotime($sale['created_at'])) ?></td>
                            <td style="font-weight: 700; font-family: monospace;"><?= htmlspecialchars($sale['quotation_number']) ?></td>
                            <td>
                                <div style="font-weight: 600;"><?= htmlspecialchars($sale['client_name'] ?? 'Walk-in Customer') ?></div>
                                <?php if (!empty($sale['client_phone'])): ?>
                                    <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($sale['client_phone']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sale['is_direct_bill']): ?>
                                    <span class="badge" style="background: #e0e7ff; color: #3730a3; font-size: 11px;">Direct Bill</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #f1f5f9; color: #475569; font-size: 11px;">Project Quote</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge" style="background: #dcfce7; color: #166534; font-size: 11px;">
                                    <?= htmlspecialchars($sale['payment_method'] ?? 'Cash') ?>
                                </span>
                            </td>
                            <td><?= $sale['items_count'] ?> pcs</td>
                            <td style="text-align: right; font-weight: 700; color: var(--secondary-brand);">
                                <?= number_format($sale['computed_total'], 2) ?>
                            </td>
                            <td class="no-print" style="text-align: center;">
                                <?php if ($sale['is_direct_bill']): ?>
                                    <a href="print_invoice.php?id=<?= $sale['id'] ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 4px 8px;">View Receipt</a>
                                <?php else: ?>
                                    <a href="print_quotation.php?id=<?= $sale['id'] ?>" target="_blank" class="btn btn-secondary" style="font-size: 11px; padding: 4px 8px;">View Quote</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background-color: var(--accent-light); font-weight: 700;">
                            <td colspan="6" style="text-align: right; text-transform: uppercase;">Total Sales Summary:</td>
                            <td style="text-align: right; font-size: 16px; color: var(--primary-brand);">
                                <?= $currency ?> <?= number_format($total_sales_revenue, 2) ?>
                            </td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'inventory'): ?>
    <!-- TAB 2: Inventory Valuation & Stock Report -->

    <!-- Metrics Cards -->
    <div class="dashboard-grid" style="margin-bottom: 24px;">
        <div class="card card-primary">
            <div class="card-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                Total Inventory Valuation
            </div>
            <div class="card-value"><?= $currency ?> <?= number_format($total_inventory_val, 2) ?></div>
            <div class="card-desc">Asset value based on unit price</div>
        </div>

        <div class="card">
            <div class="card-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: var(--success);"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path></svg>
                Total Units In Stock
            </div>
            <div class="card-value"><?= number_format($total_stock_units) ?> <span style="font-size: 14px; font-weight: normal; color: var(--text-muted);">units</span></div>
            <div class="card-desc">Across all materials</div>
        </div>

        <div class="card">
            <div class="card-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: var(--warning);"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                Low Stock Alert
            </div>
            <div class="card-value"><?= number_format($low_stock_count) ?> <span style="font-size: 14px; font-weight: normal; color: var(--text-muted);">items</span></div>
            <div class="card-desc">Stock between 1 and 20 units</div>
        </div>

        <div class="card">
            <div class="card-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: var(--danger);"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                Out of Stock
            </div>
            <div class="card-value"><?= number_format($out_of_stock_count) ?> <span style="font-size: 14px; font-weight: normal; color: var(--text-muted);">items</span></div>
            <div class="card-desc">Items requiring immediate reorder</div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar no-print">
        <form action="reports.php" method="GET" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
            <input type="hidden" name="tab" value="inventory">

            <div class="form-group" style="margin: 0; min-width: 200px;">
                <label for="category" style="font-size: 13px; font-weight: 600;">Material Category</label>
                <select id="category" name="category">
                    <option value="all" <?= ($category_filter === 'all') ? 'selected' : '' ?>>All Categories</option>
                    <option value="panel" <?= ($category_filter === 'panel') ? 'selected' : '' ?>>Ceiling & Wall Panels</option>
                    <option value="profile" <?= ($category_filter === 'profile') ? 'selected' : '' ?>>Finishing Profiles</option>
                    <option value="accessory" <?= ($category_filter === 'accessory') ? 'selected' : '' ?>>Accessories & Joiners</option>
                    <option value="other" <?= ($category_filter === 'other') ? 'selected' : '' ?>>Other Materials</option>
                </select>
            </div>

            <div class="form-group" style="margin: 0; min-width: 200px;">
                <label for="stock_status" style="font-size: 13px; font-weight: 600;">Stock Availability</label>
                <select id="stock_status" name="stock_status">
                    <option value="all" <?= ($stock_status === 'all') ? 'selected' : '' ?>>All Statuses</option>
                    <option value="normal" <?= ($stock_status === 'normal') ? 'selected' : '' ?>>In Stock (> 20)</option>
                    <option value="low" <?= ($stock_status === 'low') ? 'selected' : '' ?>>Low Stock (1 - 20)</option>
                    <option value="out" <?= ($stock_status === 'out') ? 'selected' : '' ?>>Out of Stock (0)</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="height: 48px;">Filter Stock</button>
            <a href="reports.php?tab=inventory" class="btn btn-secondary" style="height: 48px; display: inline-flex; align-items: center;">Reset</a>
        </form>
    </div>

    <!-- Inventory Table -->
    <div class="section-card">
        <div class="section-header" style="margin-bottom: 16px;">
            <div>
                <h3 style="text-transform: uppercase; font-size: 16px;">Material Stock Ledger</h3>
                <p style="color: var(--text-muted); font-size: 13px;">Official stock inventory balance as of <?= date('Y-m-d') ?></p>
            </div>
            <div style="font-size: 13px; font-weight: 700; color: var(--primary-brand);">
                Items: <?= count($inventory_data) ?>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Material Name</th>
                        <th>Category</th>
                        <th>Type / Specs</th>
                        <th>Dimensions</th>
                        <th style="text-align: right;">Unit Price (<?= $currency ?>)</th>
                        <th style="text-align: center;">Stock</th>
                        <th style="text-align: right;">Valuation (<?= $currency ?>)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventory_data as $it): 
                        $val = floatval($it['price']) * intval($it['stock']);
                    ?>
                    <tr>
                        <td style="font-weight: 600;"><?= htmlspecialchars($it['name']) ?></td>
                        <td><span class="badge" style="background: var(--accent-light); color: var(--text-dark);"><?= ucfirst(htmlspecialchars($it['category'])) ?></span></td>
                        <td><?= htmlspecialchars($it['type'] ?? '-') ?></td>
                        <td style="font-size: 12px; color: var(--text-muted);">
                            <?php if (!empty($it['width_mm'])): ?>
                                <?= $it['width_mm'] ?>mm &times; <?= $it['thickness_mm'] ?? '0' ?>mm (<?= $it['length_ft'] ?? '10' ?>ft)
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;"><?= number_format($it['price'], 2) ?></td>
                        <td style="text-align: center; font-weight: 700; font-size: 15px;"><?= $it['stock'] ?></td>
                        <td style="text-align: right; font-weight: 700; color: var(--secondary-brand);"><?= number_format($val, 2) ?></td>
                        <td>
                            <?php if ($it['stock'] <= 0): ?>
                                <span class="badge" style="background: #fee2e2; color: #991b1b;">Out of Stock</span>
                            <?php elseif ($it['stock'] <= 20): ?>
                                <span class="badge" style="background: #fef3c7; color: #92400e;">Low Stock</span>
                            <?php else: ?>
                                <span class="badge" style="background: #dcfce7; color: #166534;">In Stock</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background-color: var(--accent-light); font-weight: 700;">
                        <td colspan="5" style="text-align: right; text-transform: uppercase;">Total Units & Valuation:</td>
                        <td style="text-align: center; font-size: 16px;"><?= number_format($total_stock_units) ?></td>
                        <td style="text-align: right; font-size: 16px; color: var(--primary-brand);"><?= $currency ?> <?= number_format($total_inventory_val, 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'projects'): ?>
    <!-- TAB 3: Client Projects Progress Report -->

    <!-- Metrics Cards -->
    <div class="dashboard-grid" style="margin-bottom: 24px;">
        <div class="card card-primary">
            <div class="card-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                Total Registered Projects
            </div>
            <div class="card-value"><?= number_format($total_project_count) ?></div>
            <div class="card-desc">All ceiling installations & jobs</div>
        </div>

        <div class="card">
            <div class="card-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: var(--warning);"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                Active Projects
            </div>
            <div class="card-value"><?= number_format($active_project_count) ?></div>
            <div class="card-desc">Drafts, in progress & approved</div>
        </div>

        <div class="card">
            <div class="card-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: var(--success);"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Completed Projects
            </div>
            <div class="card-value"><?= number_format($completed_project_count) ?></div>
            <div class="card-desc">Successfully finished work</div>
        </div>

        <div class="card">
            <div class="card-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: var(--primary-brand);"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                Quoted Pipeline Value
            </div>
            <div class="card-value"><?= $currency ?> <?= number_format($total_quoted_pipeline, 2) ?></div>
            <div class="card-desc">Combined estimates of all quotations</div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar no-print">
        <form action="reports.php" method="GET" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
            <input type="hidden" name="tab" value="projects">

            <div class="form-group" style="margin: 0; min-width: 220px;">
                <label for="project_status" style="font-size: 13px; font-weight: 600;">Project Status</label>
                <select id="project_status" name="project_status">
                    <option value="all" <?= ($project_status === 'all') ? 'selected' : '' ?>>All Statuses</option>
                    <option value="Draft" <?= ($project_status === 'Draft') ? 'selected' : '' ?>>Draft</option>
                    <option value="Quotation Sent" <?= ($project_status === 'Quotation Sent') ? 'selected' : '' ?>>Quotation Sent</option>
                    <option value="Approved" <?= ($project_status === 'Approved') ? 'selected' : '' ?>>Approved</option>
                    <option value="In Progress" <?= ($project_status === 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                    <option value="Completed" <?= ($project_status === 'Completed') ? 'selected' : '' ?>>Completed</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="height: 48px;">Filter Projects</button>
            <a href="reports.php?tab=projects" class="btn btn-secondary" style="height: 48px; display: inline-flex; align-items: center;">Reset</a>
        </form>
    </div>

    <!-- Projects Table -->
    <div class="section-card">
        <div class="section-header" style="margin-bottom: 16px;">
            <div>
                <h3 style="text-transform: uppercase; font-size: 16px;">Client Projects Directory</h3>
                <p style="color: var(--text-muted); font-size: 13px;">Overview of client installations, quotes, and progress</p>
            </div>
            <div style="font-size: 13px; font-weight: 700; color: var(--primary-brand);">
                Projects: <?= count($projects_data) ?>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Client Name</th>
                        <th>Contact Phone</th>
                        <th>Site Address</th>
                        <th>Status</th>
                        <th>Quotes</th>
                        <th style="text-align: right;">Estimated Total (<?= $currency ?>)</th>
                        <th>Created Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects_data as $prj): ?>
                    <tr>
                        <td style="font-weight: 700; font-family: monospace;">#<?= $prj['id'] ?></td>
                        <td style="font-weight: 600;"><?= htmlspecialchars($prj['client_name']) ?></td>
                        <td><?= htmlspecialchars($prj['client_phone'] ?? '-') ?></td>
                        <td style="font-size: 12px; color: var(--text-muted); max-width: 250px;"><?= htmlspecialchars($prj['client_address'] ?? '-') ?></td>
                        <td>
                            <?php 
                                $badge_bg = '#f1f5f9'; $badge_color = '#475569';
                                if ($prj['status'] === 'Completed') { $badge_bg = '#dcfce7'; $badge_color = '#166534'; }
                                elseif ($prj['status'] === 'Approved') { $badge_bg = '#e0e7ff'; $badge_color = '#3730a3'; }
                                elseif ($prj['status'] === 'In Progress') { $badge_bg = '#fef3c7'; $badge_color = '#92400e'; }
                            ?>
                            <span class="badge" style="background: <?= $badge_bg ?>; color: <?= $badge_color ?>;">
                                <?= htmlspecialchars($prj['status']) ?>
                            </span>
                        </td>
                        <td style="text-align: center; font-weight: 600;"><?= $prj['quote_count'] ?></td>
                        <td style="text-align: right; font-weight: 700; color: var(--secondary-brand);">
                            <?= number_format(floatval($prj['total_quoted'] ?? 0), 2) ?>
                        </td>
                        <td style="font-size: 12px; color: var(--text-muted); white-space: nowrap;"><?= date('Y-m-d', strtotime($prj['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
require_once 'footer.php';
?>
