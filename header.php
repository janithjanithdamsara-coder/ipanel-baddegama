<?php
// header.php
// Global navigation and HTML layout start with fixed sidebar

require_once 'auth.php'; // enforces auth session controls

$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['user_role'] ?? 'billing';
$user_name = $_SESSION['user_name'] ?? 'User';
$user_initial = strtoupper(substr($user_name, 0, 1));
$user_role_label = ($user_role === 'admin') ? 'Administrator' : 'Billing Operator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>i-Panel Baddegama Portal</title>
    <!-- Custom Style Sheet -->
    <link rel="stylesheet" href="style.css?v=2.1">
</head>
<body>

    <!-- Mobile Top Header (Hidden on Desktop) -->
    <header class="mobile-header no-print">
        <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Toggle Sidebar Menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
        <div class="brand-logo" style="font-size: 16px;">i-Panel<span>Baddegama</span></div>
        <div class="brand-badge" style="font-size: 8px; margin: 0; padding: 2px 6px;">
            <?= ($user_role === 'admin') ? 'Admin' : 'Billing' ?>
        </div>
    </header>

    <div class="app-container">

        <!-- Sidebar Navigation Drawer -->
        <aside class="app-sidebar no-print" id="appSidebar">
            <div class="sidebar-top">
                <div class="sidebar-brand">
                    <div>
                        <div class="brand-logo">i-Panel<span>Baddegama</span></div>
                        <div class="brand-badge"><?= ($user_role === 'admin') ? 'Admin Portal' : 'Billing Portal' ?></div>
                    </div>
                    <button type="button" class="sidebar-close-btn no-print" onclick="toggleSidebar()" aria-label="Close Navigation Menu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
                
                <nav class="sidebar-nav">
                    <a href="index.php" class="sidebar-link <?= ($current_page == 'index.php') ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="7" height="9"></rect>
                            <rect x="14" y="3" width="7" height="5"></rect>
                            <rect x="14" y="12" width="7" height="9"></rect>
                            <rect x="3" y="16" width="7" height="5"></rect>
                        </svg>
                        Dashboard
                    </a>
                    
                    <a href="inventory.php" class="sidebar-link <?= ($current_page == 'inventory.php') ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                            <path d="M2 17l10 5 10-5"></path>
                            <path d="M2 12l10 5 10-5"></path>
                        </svg>
                        Inventory
                    </a>
                    
                    <a href="billing.php" class="sidebar-link <?= ($current_page == 'billing.php' || $current_page == 'print_invoice.php') ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="4" width="20" height="16" rx="2" ry="2"></rect>
                            <line x1="2" x2="22" y1="10" y2="10"></line>
                        </svg>
                        Direct Billing
                    </a>
                    
                    <a href="projects.php" class="sidebar-link <?= ($current_page == 'projects.php' || $current_page == 'quotation.php' || $current_page == 'print_quotation.php') ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" x2="8" y1="13" y2="13"></line>
                            <line x1="16" x2="8" y1="17" y2="17"></line>
                            <polyline points="10 9 9 9 8 9"></polyline>
                        </svg>
                        Projects & Billing
                    </a>
                    
                    <a href="reports.php" class="sidebar-link <?= ($current_page == 'reports.php') ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="20" x2="18" y2="10"></line>
                            <line x1="12" y1="20" x2="12" y2="4"></line>
                            <line x1="6" y1="20" x2="6" y2="14"></line>
                        </svg>
                        Reports & Analytics
                    </a>
                    
                    <?php if ($user_role === 'admin'): ?>
                    <a href="staff.php" class="sidebar-link <?= ($current_page == 'staff.php') ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        Staff Management
                    </a>

                    <a href="settings.php" class="sidebar-link <?= ($current_page == 'settings.php') ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                        </svg>
                        Settings & Reset
                    </a>
                    <?php endif; ?>
                </nav>
            </div>

            <!-- Profile Info & Logout -->
            <div class="sidebar-footer">
                <div class="sidebar-profile">
                    <div class="profile-avatar"><?= $user_initial ?></div>
                    <div class="profile-details">
                        <div class="profile-name"><?= htmlspecialchars($user_name) ?></div>
                        <div class="profile-role"><?= $user_role_label ?></div>
                    </div>
                </div>
                
                <a href="logout.php" class="btn btn-danger" style="width: 100%; border-radius: var(--radius-sm); font-size: 11px; padding: 10px 16px; box-shadow: none;">
                    Logout
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 2px;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" x2="9" y1="12" y2="12"></line></svg>
                </a>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <!-- Global Toast Notifications -->
            <div id="toast" class="toast">
                <span id="toast-message"></span>
            </div>
