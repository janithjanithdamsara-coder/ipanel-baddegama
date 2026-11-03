<?php
// db.php
// Setup SQLite connection and initialize database schema

// Prevent direct execution via browser URL
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'db.php') {
    http_response_code(403);
    exit('Direct access forbidden.');
}

$db_file = __DIR__ . '/db.sqlite';
$db = null;

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("A database connection error occurred. Please contact the administrator.");
}

// Create tables if they do not exist
try {
    // 1. Inventory Table
    $db->exec("CREATE TABLE IF NOT EXISTS inventory (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        category TEXT NOT NULL, -- 'panel', 'profile', 'accessory', 'other'
        type TEXT,              -- e.g., 'LITE', 'HEAVY B', 'HEAVY Flat', 'Profile A', 'TOP Corner'
        width_mm REAL,          -- width in millimeters
        thickness_mm REAL,      -- thickness in millimeters
        length_ft REAL,         -- length in feet
        color TEXT,             -- color/design name
        price REAL NOT NULL,    -- price per unit
        stock INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Projects Table
    $db->exec("CREATE TABLE IF NOT EXISTS projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_name TEXT NOT NULL,
        client_phone TEXT,
        client_address TEXT,
        status TEXT NOT NULL DEFAULT 'Draft', -- 'Draft', 'Quotation Sent', 'Approved', 'In Progress', 'Completed'
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 3. Quotations Table
    $db->exec("CREATE TABLE IF NOT EXISTS quotations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        quotation_number TEXT UNIQUE NOT NULL,
        labor_cost REAL DEFAULT 0,
        transport_cost REAL DEFAULT 0,
        discount_percentage REAL DEFAULT 0,
        tax_percentage REAL DEFAULT 0,
        notes TEXT,
        status TEXT NOT NULL DEFAULT 'Draft', -- 'Draft', 'Sent', 'Accepted', 'Invoiced'
        is_direct_bill INTEGER DEFAULT 0,
        payment_method TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    )");

    // Run migrations to update existing databases
    try {
        $db->exec("ALTER TABLE quotations ADD COLUMN is_direct_bill INTEGER DEFAULT 0");
    } catch (PDOException $e) {
        // Column may already exist
    }
    try {
        $db->exec("ALTER TABLE quotations ADD COLUMN payment_method TEXT");
    } catch (PDOException $e) {
        // Column may already exist
    }

    // 4. Quotation Items Table
    $db->exec("CREATE TABLE IF NOT EXISTS quotation_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        quotation_id INTEGER NOT NULL,
        item_id INTEGER, -- Optional link to inventory
        item_name TEXT NOT NULL,
        item_category TEXT,
        unit_price REAL NOT NULL,
        quantity INTEGER NOT NULL,
        length_ft REAL, -- optional, if custom cut length used
        total_price REAL NOT NULL,
        FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE
    )");

    // 5. Users Table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL, -- 'admin', 'billing'
        name TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 6. System Settings Table
    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    )");

    // Seed default settings if empty
    $settings_count = $db->query("SELECT COUNT(*) FROM system_settings")->fetchColumn();
    if ($settings_count == 0) {
        $stmt_set = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt_set->execute(['company_name', 'i-Panel Baddegama Portal']);
        $stmt_set->execute(['company_phone', '077 123 4567 / 091 223 4567']);
        $stmt_set->execute(['company_address', 'Main Street, Baddegama, Galle']);
        $stmt_set->execute(['currency_symbol', 'Rs.']);
        $stmt_set->execute(['invoice_footer_note', 'Thank you for choosing i-Panel Baddegama! Highest quality ceiling & wall panels in Sri Lanka.']);
    }

    // Seed sample data only once on initial installation
    $seed_stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'db_initial_seeded'");
    $seed_stmt->execute();
    $seeded = $seed_stmt->fetchColumn();

    if ($seeded !== '1') {
        $count = $db->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
        if ($count == 0) {
            $stmt = $db->prepare("INSERT INTO inventory (name, category, type, width_mm, thickness_mm, length_ft, color, price, stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $samples = [
                // Panels
                ['i-Panel LITE White Wood', 'panel', 'LITE', 200, 7.5, 10, 'White Wood', 1250, 150],
                ['i-Panel LITE Teak', 'panel', 'LITE', 200, 7.5, 10, 'Teak Wood', 1300, 120],
                ['i-Panel LITE Golden Sand', 'panel', 'LITE', 200, 7.5, 12, 'Golden Sand', 1560, 80],
                ['i-Panel HEAVY B Bevelled Gray', 'panel', 'HEAVY B', 200, 9.0, 10, 'Slate Gray', 1650, 100],
                ['i-Panel HEAVY Flat Mahogany', 'panel', 'HEAVY Flat', 300, 9.0, 10, 'Mahogany', 2100, 90],
                ['i-Panel HEAVY Flat Pure White', 'panel', 'HEAVY Flat', 300, 9.0, 12, 'Pure White', 2450, 200],
                
                // Finishing series (Profiles)
                ['i-Panel Profile A Perimeter Trim', 'profile', 'Profile A', 100, 10, 10, 'White', 580, 250],
                ['i-Panel Profile B Transition', 'profile', 'Profile B', 50, 8, 10, 'Teak Wood', 480, 150],
                ['i-Panel Profile C Linear Trim', 'profile', 'Profile C', 30, 8, 10, 'Silver', 420, 180],
                
                // Accessories
                ['i-Panel TOP Corner Joint', 'accessory', 'TOP Corner', null, null, 10, 'White', 380, 300],
                ['i-Panel H Corner Joiner', 'accessory', 'H Corner', null, null, 10, 'Teak Wood', 400, 150],
                ['i-Panel L Corner Trim', 'accessory', 'L Corner', null, null, 10, 'Silver', 350, 200],
                ['i-Panel U Corner Finisher', 'accessory', 'U Corner', null, null, 10, 'Pure White', 370, 220]
            ];

            foreach ($samples as $sample) {
                $stmt->execute($sample);
            }
        }
        $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('db_initial_seeded', '1') ON CONFLICT(setting_key) DO UPDATE SET setting_value = '1'")->execute();
    }

    // 7. Activity & Audit Logs Table
    $db->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        username TEXT,
        action TEXT NOT NULL,
        details TEXT,
        ip_address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Seed default users if users table is empty
    $user_count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($user_count == 0) {
        $stmt_user = $db->prepare("INSERT INTO users (username, password_hash, role, name) VALUES (?, ?, ?, ?)");
        $stmt_user->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin', 'System Administrator']);
        $stmt_user->execute(['staff', password_hash('staff123', PASSWORD_DEFAULT), 'billing', 'Billing Desk']);
    }
} catch (PDOException $e) {
    error_log("Database initialization failed: " . $e->getMessage());
    die("A database configuration error occurred. Please contact the administrator.");
}

/**
 * Retrieve a system setting by key
 */
function get_system_setting($db, $key, $default = '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Save or update a system setting
 */
function set_system_setting($db, $key, $value) {
    try {
        $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
        return $stmt->execute([$key, $value]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Record an audit log entry
 */
function log_activity($db, $action, $details = '', $user_id = null, $username = null) {
    if (!$db) return false;
    try {
        $uid = $user_id !== null ? $user_id : ($_SESSION['user_id'] ?? null);
        $uname = $username !== null ? $username : ($_SESSION['username'] ?? ($_SESSION['user_name'] ?? 'System'));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$uid, $uname, $action, $details, $ip]);
    } catch (Exception $e) {
        error_log("log_activity failed: " . $e->getMessage());
        return false;
    }
}
?>
