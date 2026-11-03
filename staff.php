<?php
// staff.php
// Staff Accounts Manager: Add/Delete cashier & admin logins. Admin access only.

require_once 'auth.php';
require_once 'db.php';

// Verify Admin role privileges
check_role('admin');

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    enforce_csrf();

    if ($_POST['form_action'] === 'add_staff') {
        $name = trim($_POST['name'] ?? '');
        $username = strtolower(trim($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'billing'; // 'admin' or 'billing'

        if (empty($name) || empty($username) || empty($password)) {
            $_SESSION['flash_msg'] = "All fields are required.";
            $_SESSION['flash_type'] = "danger";
        } elseif (strlen($password) < 6) {
            $_SESSION['flash_msg'] = "Password must be at least 6 characters long.";
            $_SESSION['flash_type'] = "danger";
        } else {
            try {
                // Check if username already exists
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $check_stmt->execute([$username]);
                $exists = $check_stmt->fetchColumn();

                if ($exists > 0) {
                    $_SESSION['flash_msg'] = "Username '" . htmlspecialchars($username) . "' is already taken. Choose another.";
                    $_SESSION['flash_type'] = "danger";
                } else {
                    // Hash password and insert
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO users (username, password_hash, role, name) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$username, $password_hash, $role, $name]);

                    log_activity($db, 'add_staff', "Added staff account '{$username}' ({$name}) as {$role}");

                    $_SESSION['flash_msg'] = "New staff account '" . htmlspecialchars($name) . "' created successfully.";
                    $_SESSION['flash_type'] = "success";
                }
            } catch (PDOException $e) {
                error_log("Error creating staff: " . $e->getMessage());
                $_SESSION['flash_msg'] = "A database error occurred while creating the account.";
                $_SESSION['flash_type'] = "danger";
            }
        }
        header("Location: staff.php");
        exit;
    }

    if ($_POST['form_action'] === 'delete_staff' && isset($_POST['id'])) {
        $target_id = intval($_POST['id']);
        
        // Prevent self-deletion
        if ($target_id === intval($_SESSION['user_id'])) {
            $_SESSION['flash_msg'] = "Access Denied: You cannot delete your own active administrator account.";
            $_SESSION['flash_type'] = "danger";
        } else {
            try {
                // Fetch username before deleting
                $stmt_u = $db->prepare("SELECT username, name FROM users WHERE id = ?");
                $stmt_u->execute([$target_id]);
                $del_user = $stmt_u->fetch();

                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$target_id]);

                $del_name = $del_user ? $del_user['username'] : "ID #$target_id";
                log_activity($db, 'delete_staff', "Deleted user account '{$del_name}'");

                $_SESSION['flash_msg'] = "Staff account deleted successfully.";
                $_SESSION['flash_type'] = "success";
            } catch (PDOException $e) {
                error_log("Error deleting staff: " . $e->getMessage());
                $_SESSION['flash_msg'] = "A database error occurred while deleting the account.";
                $_SESSION['flash_type'] = "danger";
            }
        }
        header("Location: staff.php");
        exit;
    }
}

// Fetch all staff users
try {
    $staff_list = $db->query("SELECT * FROM users ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Query failed: " . $e->getMessage());
    die("An error occurred while loading staff accounts.");
}

require_once 'header.php';
?>

<div class="section-card">
    <div class="section-header">
        <div>
            <h3 class="section-title">Staff & User Accounts</h3>
            <p style="color: var(--text-muted); font-size: 13px; margin-top: 4px;">Create and manage login access credentials for administrators and billing workers.</p>
        </div>
        <button class="btn btn-primary" onclick="openModal('addStaffModal')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <line x1="19" y1="8" x2="19" y2="14"></line>
                <line x1="16" y1="11" x2="22" y2="11"></line>
            </svg>
            Add Staff Member
        </button>
    </div>

    <!-- Responsive Staff Table -->
    <div style="overflow-x: auto;">
        <table class="app-table">
            <thead>
                <tr>
                    <th>Staff Name</th>
                    <th>Username</th>
                    <th>System Role</th>
                    <th>Date Registered</th>
                    <th style="text-align: right;" class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff_list as $staff): ?>
                    <tr>
                        <td data-label="Staff Name" style="font-weight: 600;">
                            <?= htmlspecialchars($staff['name']) ?>
                            <?php if ($staff['id'] === intval($_SESSION['user_id'])): ?>
                                <span style="font-size: 10px; color: var(--primary-brand); font-weight: bold; margin-left: 4px;">(You)</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Username"><code><?= htmlspecialchars($staff['username']) ?></code></td>
                        <td data-label="System Role">
                            <?php if ($staff['role'] === 'admin'): ?>
                                <span class="badge" style="background-color: var(--primary-brand); color: #ffffff;">Admin</span>
                            <?php else: ?>
                                <span class="badge badge-draft">Billing Operator</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Date Registered"><?= date('Y-m-d', strtotime($staff['created_at'])) ?></td>
                        <td data-label="Actions" style="text-align: right;" class="no-print">
                            <?php if ($staff['id'] !== intval($_SESSION['user_id'])): ?>
                                <form action="staff.php" method="POST" style="display: inline-block; margin: 0;" onsubmit="return confirm('Are you sure you want to delete this staff login? They will instantly lose access to the portal.');">
                                    <input type="hidden" name="form_action" value="delete_staff">
                                    <input type="hidden" name="id" value="<?= $staff['id'] ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-danger btn-sm" style="font-weight: 700;">Delete</button>
                                </form>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 12px; font-style: italic;">Active Session</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add Staff Member -->
<div id="addStaffModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 style="text-transform: uppercase;">Register Staff Login</h3>
            <button onclick="closeModal('addStaffModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="staff.php" method="POST">
            <input type="hidden" name="form_action" value="add_staff">
            <?= csrf_field() ?>
            
            <div class="form-group">
                <label for="name">Employee Full Name *</label>
                <input type="text" id="name" name="name" required placeholder="e.g. Ruwan Kumara">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="username">Login Username *</label>
                    <input type="text" id="username" name="username" required placeholder="e.g. ruwan" pattern="^[a-zA-Z0-9_]{3,20}$" title="Alphanumeric usernames only, 3-20 characters.">
                </div>
                <div class="form-group">
                    <label for="role">System Access Role</label>
                    <select id="role" name="role">
                        <option value="billing" selected>Billing Operator (Cashier)</option>
                        <option value="admin">Administrator (Full Access)</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Account Password *</label>
                <input type="password" id="password" name="password" required placeholder="Minimum 6 characters" style="width:100%; padding: 13px 16px; border: 1.5px solid var(--border-color); border-radius: var(--radius-sm); outline: none;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 28px;">
                <button type="button" onclick="closeModal('addStaffModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg>
                    Save Account
                </button>
            </div>
        </form>
    </div>
</div>

<?php
require_once 'footer.php';
?>
