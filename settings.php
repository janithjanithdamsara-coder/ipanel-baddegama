<?php
// settings.php
// System Settings, Company Profile, Backup Management, and System Data Reset

require_once 'auth.php';
require_once 'db.php';

// Verify Admin role privileges
check_role('admin');

$db_file = __DIR__ . '/db.sqlite';
$backups_dir = __DIR__ . '/backups';

// Ensure backups directory exists
if (!is_dir($backups_dir)) {
    @mkdir($backups_dir, 0755, true);
    // Add protective .htaccess
    @file_put_contents($backups_dir . '/.htaccess', "Deny from all\n");
}

// -------------------------------------------------------------
// GET Action: Direct Backup Download
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_backup') {
    if (file_exists($db_file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="ipanel_baddegama_db_backup_' . date('Y-m-d_His') . '.sqlite"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($db_file));
        readfile($db_file);
        exit;
    } else {
        $_SESSION['flash_msg'] = "Database file could not be located.";
        $_SESSION['flash_type'] = "danger";
        header("Location: settings.php");
        exit;
    }
}

// -------------------------------------------------------------
// POST Actions
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    enforce_csrf();
    $action = $_POST['form_action'];

    // 1. Save Company & General Settings
    if ($action === 'save_settings') {
        $company_name = trim($_POST['company_name'] ?? 'i-Panel Baddegama Portal');
        $company_phone = trim($_POST['company_phone'] ?? '');
        $company_address = trim($_POST['company_address'] ?? '');
        $currency_symbol = trim($_POST['currency_symbol'] ?? 'Rs.');
        $invoice_footer_note = trim($_POST['invoice_footer_note'] ?? '');

        set_system_setting($db, 'company_name', $company_name);
        set_system_setting($db, 'company_phone', $company_phone);
        set_system_setting($db, 'company_address', $company_address);
        set_system_setting($db, 'currency_symbol', $currency_symbol);
        set_system_setting($db, 'invoice_footer_note', $invoice_footer_note);

        log_activity($db, 'update_settings', "Updated store settings (Name: {$company_name})");

        $_SESSION['flash_msg'] = "System settings updated successfully.";
        $_SESSION['flash_type'] = "success";
        header("Location: settings.php");
        exit;
    }

    // 2. Create Manual Snapshot Backup
    if ($action === 'create_backup') {
        try {
            $backup_name = 'backup_' . date('Y-m-d_His') . '.sqlite';
            if (copy($db_file, $backups_dir . '/' . $backup_name)) {
                log_activity($db, 'create_backup', "Created manual database snapshot: {$backup_name}");
                $_SESSION['flash_msg'] = "Database snapshot created successfully: " . $backup_name;
                $_SESSION['flash_type'] = "success";
            } else {
                $_SESSION['flash_msg'] = "Failed to copy database file.";
                $_SESSION['flash_type'] = "danger";
            }
        } catch (Exception $e) {
            error_log("Backup error: " . $e->getMessage());
            $_SESSION['flash_msg'] = "An error occurred while creating the backup.";
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: settings.php");
        exit;
    }

    // 3. Clear Invoices / Sales Data Only
    if ($action === 'clear_sales') {
        try {
            // Auto safety backup before clearing
            @copy($db_file, $backups_dir . '/auto_backup_before_clear_sales_' . date('Y-m-d_His') . '.sqlite');

            $db->beginTransaction();
            // Delete items for direct bills
            $db->exec("DELETE FROM quotation_items WHERE quotation_id IN (SELECT id FROM quotations WHERE is_direct_bill = 1)");
            
            // Delete direct bill projects
            $db->exec("DELETE FROM projects WHERE id IN (SELECT project_id FROM quotations WHERE is_direct_bill = 1)");
            
            // Delete direct bill quotations
            $db->exec("DELETE FROM quotations WHERE is_direct_bill = 1");

            $db->commit();
            $db->exec("VACUUM");

            log_activity($db, 'clear_sales', "Cleared all direct sales and invoice transactions");

            $_SESSION['flash_msg'] = "All direct sales and invoice transactions have been cleared successfully.";
            $_SESSION['flash_type'] = "success";
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error clearing sales: " . $e->getMessage());
            $_SESSION['flash_msg'] = "A database error occurred while clearing sales data.";
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: settings.php");
        exit;
    }

    // 4. Clear Projects & Quotations Only
    if ($action === 'clear_projects') {
        try {
            // Auto safety backup before clearing
            @copy($db_file, $backups_dir . '/auto_backup_before_clear_projects_' . date('Y-m-d_His') . '.sqlite');

            $db->beginTransaction();
            // Delete quotation items for project quotations
            $db->exec("DELETE FROM quotation_items WHERE quotation_id IN (SELECT id FROM quotations WHERE is_direct_bill = 0)");
            
            // Delete project quotations
            $db->exec("DELETE FROM quotations WHERE is_direct_bill = 0");
            
            // Delete projects that are not direct walk-ins
            $db->exec("DELETE FROM projects");

            $db->commit();
            $db->exec("VACUUM");

            log_activity($db, 'clear_projects', "Cleared all client projects and quotations");

            $_SESSION['flash_msg'] = "All client projects and quotations have been cleared successfully.";
            $_SESSION['flash_type'] = "success";
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error clearing projects: " . $e->getMessage());
            $_SESSION['flash_msg'] = "A database error occurred while clearing projects.";
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: settings.php");
        exit;
    }

    // 5. Reset Inventory Stock Counts to 0
    if ($action === 'reset_stock') {
        try {
            $db->exec("UPDATE inventory SET stock = 0");
            log_activity($db, 'reset_stock', "Reset all inventory quantities to 0");
            $_SESSION['flash_msg'] = "All inventory stock quantities have been reset to 0.";
            $_SESSION['flash_type'] = "success";
        } catch (PDOException $e) {
            error_log("Error resetting stock: " . $e->getMessage());
            $_SESSION['flash_msg'] = "A database error occurred while resetting stock.";
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: settings.php");
        exit;
    }

    // 6. Restore Default Inventory Catalog
    if ($action === 'restore_default_inventory') {
        try {
            @copy($db_file, $backups_dir . '/auto_backup_before_inventory_reset_' . date('Y-m-d_His') . '.sqlite');

            $db->beginTransaction();
            $db->exec("DELETE FROM inventory");

            $stmt = $db->prepare("INSERT INTO inventory (name, category, type, width_mm, thickness_mm, length_ft, color, price, stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $samples = [
                ['i-Panel LITE White Wood', 'panel', 'LITE', 200, 7.5, 10, 'White Wood', 1250, 150],
                ['i-Panel LITE Teak', 'panel', 'LITE', 200, 7.5, 10, 'Teak Wood', 1300, 120],
                ['i-Panel LITE Golden Sand', 'panel', 'LITE', 200, 7.5, 12, 'Golden Sand', 1560, 80],
                ['i-Panel HEAVY B Bevelled Gray', 'panel', 'HEAVY B', 200, 9.0, 10, 'Slate Gray', 1650, 100],
                ['i-Panel HEAVY Flat Mahogany', 'panel', 'HEAVY Flat', 300, 9.0, 10, 'Mahogany', 2100, 90],
                ['i-Panel HEAVY Flat Pure White', 'panel', 'HEAVY Flat', 300, 9.0, 12, 'Pure White', 2450, 200],
                ['i-Panel Profile A Perimeter Trim', 'profile', 'Profile A', 100, 10, 10, 'White', 580, 250],
                ['i-Panel Profile B Transition', 'profile', 'Profile B', 50, 8, 10, 'Teak Wood', 480, 150],
                ['i-Panel Profile C Linear Trim', 'profile', 'Profile C', 30, 8, 10, 'Silver', 420, 180],
                ['i-Panel TOP Corner Joint', 'accessory', 'TOP Corner', null, null, 10, 'White', 380, 300],
                ['i-Panel H Corner Joiner', 'accessory', 'H Corner', null, null, 10, 'Teak Wood', 400, 150],
                ['i-Panel L Corner Trim', 'accessory', 'L Corner', null, null, 10, 'Silver', 350, 200],
                ['i-Panel U Corner Finisher', 'accessory', 'U Corner', null, null, 10, 'Pure White', 370, 220]
            ];

            foreach ($samples as $sample) {
                $stmt->execute($sample);
            }

            $db->commit();
            $db->exec("VACUUM");

            log_activity($db, 'restore_default_inventory', "Restored default i-Panel materials and catalog");

            $_SESSION['flash_msg'] = "Default i-Panel materials and catalog have been successfully restored.";
            $_SESSION['flash_type'] = "success";
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error restoring inventory: " . $e->getMessage());
            $_SESSION['flash_msg'] = "A database error occurred while restoring inventory.";
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: settings.php");
        exit;
    }

    // 6b. Delete All Inventory Items (Start Empty)
    if ($action === 'clear_all_inventory') {
        try {
            @copy($db_file, $backups_dir . '/auto_backup_before_clear_inventory_' . date('Y-m-d_His') . '.sqlite');
            $db->exec("DELETE FROM inventory");
            $db->exec("VACUUM");

            log_activity($db, 'clear_all_inventory', "Permanently deleted all inventory catalog items");

            $_SESSION['flash_msg'] = "All inventory items have been deleted. You can now add your own items from the Inventory page.";
            $_SESSION['flash_type'] = "success";
        } catch (PDOException $e) {
            error_log("Error clearing inventory: " . $e->getMessage());
            $_SESSION['flash_msg'] = "A database error occurred while clearing inventory items.";
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: settings.php");
        exit;
    }

    // 7. COMPLETE SYSTEM WIPE / CLEAR ALL DATA (User's main request)
    if ($action === 'clear_all_data') {
        $confirm = trim($_POST['confirm_keyword'] ?? '');

        if ($confirm !== 'CLEAR') {
            $_SESSION['flash_msg'] = "Clear operation aborted: You must type 'CLEAR' in uppercase to confirm.";
            $_SESSION['flash_type'] = "danger";
            header("Location: settings.php");
            exit;
        }

        try {
            // 1. Mandatory Auto-Backup before system wipe
            $auto_backup = $backups_dir . '/auto_backup_before_full_wipe_' . date('Y-m-d_His') . '.sqlite';
            @copy($db_file, $auto_backup);

            $db->beginTransaction();

            // Clear all transaction tables
            $db->exec("DELETE FROM quotation_items");
            $db->exec("DELETE FROM quotations");
            $db->exec("DELETE FROM projects");

            // Check inventory action choice
            $inv_choice = $_POST['inventory_action'] ?? 'empty';
            if ($inv_choice === 'empty') {
                $db->exec("DELETE FROM inventory");
            } elseif ($inv_choice === 'restore_defaults') {
                $db->exec("DELETE FROM inventory");
                $stmt = $db->prepare("INSERT INTO inventory (name, category, type, width_mm, thickness_mm, length_ft, color, price, stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $samples = [
                    ['i-Panel LITE White Wood', 'panel', 'LITE', 200, 7.5, 10, 'White Wood', 1250, 150],
                    ['i-Panel LITE Teak', 'panel', 'LITE', 200, 7.5, 10, 'Teak Wood', 1300, 120],
                    ['i-Panel LITE Golden Sand', 'panel', 'LITE', 200, 7.5, 12, 'Golden Sand', 1560, 80],
                    ['i-Panel HEAVY B Bevelled Gray', 'panel', 'HEAVY B', 200, 9.0, 10, 'Slate Gray', 1650, 100],
                    ['i-Panel HEAVY Flat Mahogany', 'panel', 'HEAVY Flat', 300, 9.0, 10, 'Mahogany', 2100, 90],
                    ['i-Panel HEAVY Flat Pure White', 'panel', 'HEAVY Flat', 300, 9.0, 12, 'Pure White', 2450, 200],
                    ['i-Panel Profile A Perimeter Trim', 'profile', 'Profile A', 100, 10, 10, 'White', 580, 250],
                    ['i-Panel Profile B Transition', 'profile', 'Profile B', 50, 8, 10, 'Teak Wood', 480, 150],
                    ['i-Panel Profile C Linear Trim', 'profile', 'Profile C', 30, 8, 10, 'Silver', 420, 180],
                    ['i-Panel TOP Corner Joint', 'accessory', 'TOP Corner', null, null, 10, 'White', 380, 300],
                    ['i-Panel H Corner Joiner', 'accessory', 'H Corner', null, null, 10, 'Teak Wood', 400, 150],
                    ['i-Panel L Corner Trim', 'accessory', 'L Corner', null, null, 10, 'Silver', 350, 200],
                    ['i-Panel U Corner Finisher', 'accessory', 'U Corner', null, null, 10, 'Pure White', 370, 220]
                ];
                foreach ($samples as $sample) {
                    $stmt->execute($sample);
                }
            }

            $db->commit();
            $db->exec("VACUUM");

            log_activity($db, 'clear_all_data', "Performed full system wipe (Inventory choice: {$inv_choice})");

            $_SESSION['flash_msg'] = "All system data has been cleared successfully. A safety backup was saved automatically.";
            $_SESSION['flash_type'] = "success";

        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Critical error during data clear: " . $e->getMessage());
            $_SESSION['flash_msg'] = "A critical error occurred while clearing system data.";
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: settings.php");
        exit;
    }
}

// -------------------------------------------------------------
// Read Current Settings & System Statistics
// -------------------------------------------------------------
$company_name = get_system_setting($db, 'company_name', 'i-Panel Baddegama Portal');
$company_phone = get_system_setting($db, 'company_phone', '077 123 4567 / 091 223 4567');
$company_address = get_system_setting($db, 'company_address', 'Main Street, Baddegama, Galle');
$currency_symbol = get_system_setting($db, 'currency_symbol', 'Rs.');
$invoice_footer_note = get_system_setting($db, 'invoice_footer_note', 'Thank you for choosing i-Panel Baddegama! Highest quality ceiling & wall panels in Sri Lanka.');

// Metrics
$db_size_bytes = file_exists($db_file) ? filesize($db_file) : 0;
$db_size_kb = number_format($db_size_bytes / 1024, 2);

$total_invoices = $db->query("SELECT COUNT(*) FROM quotations WHERE is_direct_bill = 1")->fetchColumn();
$total_quotations = $db->query("SELECT COUNT(*) FROM quotations WHERE is_direct_bill = 0")->fetchColumn();
$total_projects = $db->query("SELECT COUNT(*) FROM projects")->fetchColumn();
$total_inventory_items = $db->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
$total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$recent_activity_logs = $db->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 25")->fetchAll();

// List existing snapshot backups
$existing_backups = [];
if (is_dir($backups_dir)) {
    $files = scandir($backups_dir);
    foreach ($files as $file) {
        if (substr($file, -7) === '.sqlite') {
            $existing_backups[] = [
                'name' => $file,
                'size' => number_format(filesize($backups_dir . '/' . $file) / 1024, 1) . ' KB',
                'date' => date('Y-m-d H:i:s', filemtime($backups_dir . '/' . $file))
            ];
        }
    }
    // Sort descending by date
    usort($existing_backups, fn($a, $b) => strcmp($b['date'], $a['date']));
}

require_once 'header.php';
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h2 style="font-size: 24px; text-transform: uppercase;">System Settings & Data Control</h2>
        <p style="color: var(--text-muted); font-size: 13px;">Manage store branding, download database snapshots, and perform data clearing & resets.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="settings.php?action=download_backup" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Download Database Backup
        </a>
    </div>
</div>

<!-- System Metrics Overview -->
<div class="dashboard-grid" style="margin-bottom: 28px;">
    <div class="card card-primary">
        <div class="card-title">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary-brand);"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path></svg>
            Database Storage
        </div>
        <div class="card-value"><?= $db_size_kb ?> <span style="font-size: 14px; font-weight: normal; color: var(--text-muted);">KB</span></div>
        <div class="card-desc">SQLite Database (db.sqlite)</div>
    </div>

    <div class="card">
        <div class="card-title">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--success);"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
            Direct Invoices
        </div>
        <div class="card-value"><?= number_format($total_invoices) ?></div>
        <div class="card-desc"><?= number_format($total_quotations) ?> project quotations</div>
    </div>

    <div class="card">
        <div class="card-title">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--warning);"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" x2="8" y1="13" y2="13"></line><line x1="16" x2="8" y1="17" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            Client Projects
        </div>
        <div class="card-value"><?= number_format($total_projects) ?></div>
        <div class="card-desc">Active and completed client sites</div>
    </div>

    <div class="card">
        <div class="card-title">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary-brand);"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path></svg>
            Inventory Items
        </div>
        <div class="card-value"><?= number_format($total_inventory_items) ?></div>
        <div class="card-desc"><?= number_format($total_users) ?> registered system user(s)</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start;">

    <!-- Left Column: Business Profile & General Settings -->
    <div>
        <div class="section-card">
            <div class="section-header" style="margin-bottom: 20px;">
                <div>
                    <h3 style="text-transform: uppercase; font-size: 17px;">Store Profile & Branding</h3>
                    <p style="color: var(--text-muted); font-size: 13px;">This info appears on print invoices, quotations, and official reports.</p>
                </div>
            </div>

            <form action="settings.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="save_settings">

                <div class="form-group">
                    <label for="company_name">Company / Store Name *</label>
                    <input type="text" id="company_name" name="company_name" required value="<?= htmlspecialchars($company_name) ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="company_phone">Contact Phone(s) *</label>
                        <input type="text" id="company_phone" name="company_phone" required value="<?= htmlspecialchars($company_phone) ?>">
                    </div>
                    <div class="form-group">
                        <label for="currency_symbol">Currency Display *</label>
                        <input type="text" id="currency_symbol" name="currency_symbol" required value="<?= htmlspecialchars($currency_symbol) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="company_address">Address / Branch Location *</label>
                    <textarea id="company_address" name="company_address" rows="2" required><?= htmlspecialchars($company_address) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="invoice_footer_note">Invoice / Quotation Note (Footer)</label>
                    <textarea id="invoice_footer_note" name="invoice_footer_note" rows="2"><?= htmlspecialchars($invoice_footer_note) ?></textarea>
                </div>

                <div style="text-align: right; margin-top: 15px;">
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </form>
        </div>

        <!-- Automated Backups Section -->
        <div class="section-card" style="margin-top: 24px;">
            <div class="section-header" style="margin-bottom: 16px;">
                <div>
                    <h3 style="text-transform: uppercase; font-size: 17px;">Safety Snapshots</h3>
                    <p style="color: var(--text-muted); font-size: 13px;">Backups created before any data reset or manual snapshot.</p>
                </div>
                <form action="settings.php" method="POST" style="margin: 0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="create_backup">
                    <button type="submit" class="btn btn-secondary" style="font-size: 12px; padding: 6px 12px;">Create Snapshot</button>
                </form>
            </div>

            <?php if (empty($existing_backups)): ?>
                <div style="text-align: center; padding: 25px; color: var(--text-muted); font-size: 13px;">
                    No stored safety snapshots yet. Click <strong>Create Snapshot</strong> above to save one now.
                </div>
            <?php else: ?>
                <div class="table-container" style="max-height: 250px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Snapshot File</th>
                                <th>Size</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($existing_backups as $bk): ?>
                            <tr>
                                <td style="font-family: monospace; font-size: 12px; word-break: break-all;"><?= htmlspecialchars($bk['name']) ?></td>
                                <td><?= $bk['size'] ?></td>
                                <td style="font-size: 12px; color: var(--text-muted);"><?= $bk['date'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Security Activity Logs Section -->
        <div class="section-card" style="margin-top: 24px;">
            <div class="section-header" style="margin-bottom: 16px;">
                <div>
                    <h3 style="text-transform: uppercase; font-size: 17px;">Security & Activity Audit Logs</h3>
                    <p style="color: var(--text-muted); font-size: 13px;">Recent user logins, data changes, and security events.</p>
                </div>
            </div>

            <?php if (empty($recent_activity_logs)): ?>
                <div style="text-align: center; padding: 25px; color: var(--text-muted); font-size: 13px;">
                    No recorded activity logs yet.
                </div>
            <?php else: ?>
                <div class="table-container" style="max-height: 280px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_activity_logs as $log): ?>
                            <tr>
                                <td style="font-size: 11px; white-space: nowrap; color: var(--text-muted);"><?= htmlspecialchars($log['created_at']) ?></td>
                                <td style="font-weight: 600; font-size: 12px;"><?= htmlspecialchars($log['username'] ?? 'System') ?></td>
                                <td>
                                    <span class="badge" style="font-size: 10px; text-transform: uppercase;"><?= htmlspecialchars($log['action']) ?></span>
                                </td>
                                <td style="font-size: 12px; max-width: 220px; word-break: break-word;"><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                                <td style="font-family: monospace; font-size: 11px;"><?= htmlspecialchars($log['ip_address'] ?? '127.0.0.1') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Column: Danger Zone / Clear Data Options -->
    <div>
        <div class="section-card" style="border: 2px solid var(--danger-glow); background-color: #fff;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid var(--border-color);">
                <div style="background-color: var(--danger-glow); color: var(--danger); width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                </div>
                <div>
                    <h3 style="color: var(--danger); text-transform: uppercase; font-size: 18px; margin: 0;">System Data Management & Clear</h3>
                    <p style="color: var(--text-muted); font-size: 13px; margin: 0;">Clean up old transactional records or perform a complete system factory reset.</p>
                </div>
            </div>

            <!-- Notice Banner -->
            <div style="background-color: #fef2f2; border-left: 4px solid var(--danger); padding: 14px 16px; border-radius: var(--radius-sm); margin-bottom: 20px; font-size: 13px; color: #991b1b; line-height: 1.5;">
                <strong>ආරක්ෂිත පණිවිඩය (Safety Notice):</strong> ඕනෑම Clear කිරීමක් සිදු කිරීමට පෙර පද්ධතිය විසින් ස්වයංක්‍රීයව Database එක Snapshot Backup කරගනු ලැබේ. අවශ්‍ය නම් ඔබට ඉහළ ඇති <strong>Download Database Backup</strong> බොත්තමෙන්ද backup එකක් ලබාගත හැක.
            </div>

            <!-- Option 1: Full Factory Reset (Meke data okkoma clear karanna) -->
            <div style="background: #fafafa; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px; margin-bottom: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
                    <div>
                        <div style="font-weight: 700; color: var(--danger); font-size: 15px; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            Complete System Data Wipe (සියලුම දත්ත මකා දැමීම)
                        </div>
                        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 0;">
                            පද්ධතියේ ඇති සියලුම විකුණුම් (Invoices), මිල ගණන් (Quotations) සහ Client Projects සම්පූර්ණයෙන්ම මකා දමා පද්ධතිය මුල සිට පිරිසිදු කරයි. (Admin user accounts ආරක්ෂිතව පවතී).
                        </p>
                    </div>
                    <button type="button" class="btn btn-danger" onclick="openModal('fullWipeModal')" style="white-space: nowrap;">
                        Clear All Data
                    </button>
                </div>
            </div>

            <!-- Option 2: Clear Sales & Invoices Only -->
            <div style="background: #fafafa; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px; margin-bottom: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
                    <div>
                        <div style="font-weight: 700; font-size: 15px; margin-bottom: 4px;">
                            Clear Sales & Direct Invoices Only (විකුණුම් දත්ත පමණක් මකන්න)
                        </div>
                        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 0;">
                            කෙලින්ම බිල් කළ විකුණුම් සහ Invoice ඉතිහාසය මකා දමයි. Inventory ද්‍රව්‍ය සහ Client Projects වලට බලපෑමක් නැත.
                        </p>
                    </div>
                    <form action="settings.php" method="POST" onsubmit="return confirm('Are you sure you want to clear all direct sales invoices? A backup will be taken.');" style="margin: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="clear_sales">
                        <button type="submit" class="btn btn-secondary" style="color: var(--danger); border-color: var(--danger); white-space: nowrap;">
                            Clear Sales Data
                        </button>
                    </form>
                </div>
            </div>

            <!-- Option 3: Clear Client Projects Only -->
            <div style="background: #fafafa; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px; margin-bottom: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
                    <div>
                        <div style="font-weight: 700; font-size: 15px; margin-bottom: 4px;">
                            Clear Projects & Quotations Only (ප්‍රොජෙක්ට් පමණක් මකන්න)
                        </div>
                        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 0;">
                            Client Projects සහ ඒවාට අදාළ Quotations ඉතිහාසය පමණක් මකා දමයි.
                        </p>
                    </div>
                    <form action="settings.php" method="POST" onsubmit="return confirm('Are you sure you want to clear all client projects and quotations? A backup will be taken.');" style="margin: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="clear_projects">
                        <button type="submit" class="btn btn-secondary" style="color: var(--danger); border-color: var(--danger); white-space: nowrap;">
                            Clear Projects
                        </button>
                    </form>
                </div>
            </div>

            <!-- Option 4: Reset Inventory Stock / Catalog -->
            <div style="background: #fafafa; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
                    <div>
                        <div style="font-weight: 700; font-size: 15px; margin-bottom: 4px;">
                            Inventory Stock Controls (තොග ප්‍රමාණ Reset කිරීම)
                        </div>
                        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 10px;">
                            දැනට ඇති බඩු වල තොග ප්‍රමාණ (Stock) 0 කරන්න හෝ පෙරනිමි (Default) i-Panel බඩු ලැයිස්තුව නැවත පිහිටුවන්න.
                        </p>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <form action="settings.php" method="POST" onsubmit="return confirm('Reset all stock quantities to 0?');" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="reset_stock">
                                <button type="submit" class="btn btn-secondary" style="font-size: 12px; padding: 6px 12px;">Reset Stock to 0</button>
                            </form>
                            <form action="settings.php" method="POST" onsubmit="return confirm('Restore default i-Panel materials catalog? Current custom items will be replaced.');" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="restore_default_inventory">
                                <button type="submit" class="btn btn-secondary" style="font-size: 12px; padding: 6px 12px;">Restore Default Catalog</button>
                            </form>
                            <form action="settings.php" method="POST" onsubmit="return confirm('Are you sure you want to permanently delete all inventory items so you can start empty? A backup will be taken.');" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="clear_all_inventory">
                                <button type="submit" class="btn btn-secondary" style="font-size: 12px; padding: 6px 12px; color: var(--danger); border-color: var(--danger);">Delete All Items (Start Empty)</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Complete System Wipe Confirmation Modal -->
<div id="fullWipeModal" class="modal-overlay">
    <div class="modal-container" style="border-top: 5px solid var(--danger);">
        <div class="modal-header">
            <h3 style="text-transform: uppercase; color: var(--danger); display: flex; align-items: center; gap: 8px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                Confirm Complete Data Wipe
            </h3>
            <button onclick="closeModal('fullWipeModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="settings.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="clear_all_data">

            <div style="background-color: #fef2f2; border: 1px solid #fecaca; padding: 14px; border-radius: var(--radius-sm); margin-bottom: 20px; font-size: 13px; color: #991b1b; line-height: 1.6;">
                <strong>අවවාදයයි (Warning):</strong> මෙමඟින් පද්ධතියේ ඇති සියලුම <strong>Direct Invoices, Quotations, සහ Client Projects</strong> සම්පූර්ණයෙන්ම මැකී යනු ඇත. මෙම ක්‍රියාව ආපසු හැරවිය නොහැක. (ආරක්ෂාව සඳහා Snapshot Backup එකක් ස්වයංක්‍රීයව සුරැකෙනු ඇත).
            </div>

            <div class="form-group" style="margin-bottom: 18px;">
                <label style="font-weight: 700; font-size: 14px; margin-bottom: 8px; display: block;">Inventory (බඩු ලැයිස්තුවට කළ යුතු දේ):</label>
                <div style="display: flex; flex-direction: column; gap: 8px; background: #f8fafc; padding: 12px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                        <input type="radio" name="inventory_action" value="empty" checked style="width: 16px; height: 16px;">
                        <span><strong>Delete all items (Start Empty)</strong> - සියලුම බඩු මකා දමා අලුතින් ඔබගේ බඩු පමණක් ඇතුළත් කිරීමට</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                        <input type="radio" name="inventory_action" value="keep" style="width: 16px; height: 16px;">
                        <span>Keep current items (දැනට ඇති බඩු එලෙසම තබන්න)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                        <input type="radio" name="inventory_action" value="restore_defaults" style="width: 16px; height: 16px;">
                        <span>Restore default i-Panel sample catalog (පෙරනිමි i-Panel බඩු ලැයිස්තුව නැවත පිහිටුවන්න)</span>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_keyword" style="font-weight: 700;">
                    තහවුරු කිරීම සඳහා පහත කොටුවේ <span style="color: var(--danger); font-family: monospace; font-size: 15px;">CLEAR</span> ලෙස Type කරන්න:
                </label>
                <input type="text" id="confirm_keyword" name="confirm_keyword" required placeholder="Type CLEAR here" autocomplete="off" style="border: 2px solid var(--danger); font-size: 16px; font-family: monospace; text-transform: uppercase;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('fullWipeModal')">Cancel</button>
                <button type="submit" class="btn btn-danger" style="display: flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    Permanently Clear All Data
                </button>
            </div>
        </form>
    </div>
</div>

<?php
require_once 'footer.php';
?>
