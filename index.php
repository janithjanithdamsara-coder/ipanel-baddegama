<?php
// index.php
// Main Dashboard with quick metrics, recent projects, and stock warnings

require_once 'db.php';
require_once 'header.php';

// Fetch metrics
try {
    // 1. Total items and valuation in inventory
    $stock_stats = $db->query("SELECT SUM(stock) as total_stock, SUM(price * stock) as total_value FROM inventory")->fetch();
    $total_stock = $stock_stats['total_stock'] ?? 0;
    $total_value = $stock_stats['total_value'] ?? 0;

    // 2. Active projects count
    $active_projects_count = $db->query("SELECT COUNT(*) FROM projects WHERE status != 'Completed'")->fetchColumn();

    // 3. Total quotation count
    $total_quotes_count = $db->query("SELECT COUNT(*) FROM quotations")->fetchColumn();

    // 4. Project revenue pipeline (Sum of all accepted quotations total cost)
    // Formula: (sum of items price * qty) + labor + transport - discount + tax
    $approved_quotes = $db->query("SELECT q.id, q.labor_cost, q.transport_cost, q.discount_percentage, q.tax_percentage 
                                   FROM quotations q WHERE q.status = 'Accepted'")->fetchAll();
    
    $revenue_pipeline = 0;
    foreach ($approved_quotes as $quote) {
        $items_total = $db->query("SELECT SUM(total_price) FROM quotation_items WHERE quotation_id = " . intval($quote['id']))->fetchColumn() ?? 0;
        
        $subtotal = $items_total + $quote['labor_cost'] + $quote['transport_cost'];
        $discount = $subtotal * ($quote['discount_percentage'] / 100);
        $tax = ($subtotal - $discount) * ($quote['tax_percentage'] / 100);
        
        $revenue_pipeline += ($subtotal - $discount + $tax);
    }

    // Recent Projects
    $recent_projects = $db->query("SELECT * FROM projects ORDER BY created_at DESC LIMIT 5")->fetchAll();

    // Low stock items (stock <= 20)
    $low_stock_items = $db->query("SELECT * FROM inventory WHERE stock <= 20 ORDER BY stock ASC LIMIT 5")->fetchAll();

} catch (PDOException $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    echo "<div class='section-card' style='border-color: var(--danger);'><h3 style='color:var(--danger);'>An error occurred while loading dashboard analytics.</h3></div>";
}
?>

<div style="margin-bottom: 24px;">
    <h2 style="font-size: 24px; text-transform: uppercase;">Ceiling Operations Command</h2>
    <p style="color: var(--text-muted); font-size: 13px;">Manage inventory, customize panels, track client projects and issue billing.</p>
</div>

<!-- Dashboard Metrics -->
<div class="dashboard-grid">
    <div class="card card-primary">
        <div class="card-title">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary-brand);"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path></svg>
            Inventory Value
        </div>
        <div class="card-value">Rs. <?= number_format($total_value, 2) ?></div>
        <div class="card-footer"><?= number_format($total_stock) ?> total panels & items in stock</div>
    </div>
    
    <div class="card card-warning">
        <div class="card-title">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--warning);"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle></svg>
            Active Projects
        </div>
        <div class="card-value"><?= $active_projects_count ?> Sites</div>
        <div class="card-footer">Installations currently in progress</div>
    </div>
    
    <div class="card card-success">
        <div class="card-title">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--success);"><line x1="12" x2="12" y1="2" y2="22"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            Revenue pipeline
        </div>
        <div class="card-value">Rs. <?= number_format($revenue_pipeline, 2) ?></div>
        <div class="card-footer">Sum of accepted quotes & direct sales</div>
    </div>
    
    <div class="card card-dark">
        <div class="card-title">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--secondary-brand);"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" x2="8" y1="13" y2="13"></line><line x1="16" x2="8" y1="17" y2="17"></line><line x1="10" x2="8" y1="9" y2="9"></line></svg>
            Total Estimates
        </div>
        <div class="card-value"><?= $total_quotes_count ?> Quotes</div>
        <div class="card-footer">Quotations and invoices issued</div>
    </div>
</div>

<div class="content-layout two-col">
    <!-- Left Column: Recent Projects -->
    <div>
        <div class="section-card">
            <div class="section-header">
                <h3 class="section-title">Recent Client Projects</h3>
                <a href="projects.php" class="btn btn-secondary btn-sm">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 2px;"><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle></svg>
                    Manage Projects
                </a>
            </div>
            
            <?php if (empty($recent_projects)): ?>
                <p style="color: var(--text-muted); text-align: center; padding: 20px 0;">No projects created yet. Start by adding a project.</p>
            <?php else: ?>
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>Client Name</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Date Created</th>
                            <th class="no-print" style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_projects as $proj): ?>
                            <tr>
                                <td data-label="Client Name" style="font-weight: 600;"><?= htmlspecialchars($proj['client_name']) ?></td>
                                <td data-label="Phone"><?= htmlspecialchars($proj['client_phone']) ?></td>
                                <td data-label="Status">
                                    <?php
                                    $status_class = '';
                                    switch ($proj['status']) {
                                        case 'Draft': $status_class = 'badge-draft'; break;
                                        case 'Quotation Sent': $status_class = 'badge-sent'; break;
                                        case 'Approved': $status_class = 'badge-approved'; break;
                                        case 'In Progress': $status_class = 'badge-progress'; break;
                                        case 'Completed': $status_class = 'badge-completed'; break;
                                    }
                                    ?>
                                    <span class="badge <?= $status_class ?>"><?= $proj['status'] ?></span>
                                </td>
                                <td data-label="Date Created"><?= date('Y-m-d', strtotime($proj['created_at'])) ?></td>
                                <td data-label="Action" class="no-print" style="text-align: right;">
                                    <a href="projects.php?view_id=<?= $proj['id'] ?>" class="btn btn-secondary btn-sm" style="font-weight:700;">
                                        View Details &rarr;
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Column: Alerts & Quick Actions -->
    <div>
        <!-- Quick Actions -->
        <div class="section-card" style="margin-bottom: 24px;">
            <div class="section-header" style="margin-bottom: 16px; padding-bottom: 8px;">
                <h3 class="section-title">Quick Actions</h3>
            </div>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <a href="billing.php" class="btn btn-primary" style="width: 100%;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"></rect><line x1="2" x2="22" y1="10" y2="10"></line></svg>
                    New Direct Bill
                </a>
                <a href="projects.php?action=new" class="btn btn-secondary" style="width: 100%;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><line x1="12" x2="12" y1="5" y2="19"></line><line x1="5" x2="19" y1="12" y2="12"></line></svg>
                    Create New Project
                </a>
                <a href="inventory.php?action=add" class="btn btn-secondary" style="width: 100%;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
                    Add Inventory Item
                </a>
            </div>
        </div>

        <!-- Low Stock Alerts -->
        <div class="section-card">
            <div class="section-header" style="margin-bottom: 16px; padding-bottom: 8px;">
                <h3 class="section-title" style="color: var(--danger);">Low Stock Alerts</h3>
            </div>
            
            <?php if (empty($low_stock_items)): ?>
                <p style="color: var(--text-muted); font-size: 13px;">All items are well stocked.</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <?php foreach ($low_stock_items as $item): ?>
                        <div style="padding: 10px; border-left: 3px solid var(--danger); background: var(--bg-main); font-size: 13px;">
                            <div style="font-weight: 600; color: var(--secondary-brand);"><?= htmlspecialchars($item['name']) ?></div>
                            <div style="display:flex; justify-content: space-between; margin-top: 4px; color: var(--text-muted);">
                                <span><?= htmlspecialchars($item['color'] ?: 'Standard') ?></span>
                                <span style="font-weight: 700; color: var(--danger);"><?= $item['stock'] ?> units left</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
