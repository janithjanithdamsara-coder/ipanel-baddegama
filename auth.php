<?php
// auth.php
// Authentication checks, secure session controllers, security headers, and CSRF protection

// Prevent direct execution via browser URL
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'auth.php') {
    http_response_code(403);
    exit('Direct access forbidden.');
}

// 1. Send Security Headers
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

// 2. Initialize Secure Session
if (session_status() === PHP_SESSION_NONE) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 3. Session Inactivity Timeout (30 Minutes)
if (isset($_SESSION['user_id'])) {
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity'] > 1800)) {
        // Log inactivity timeout if DB is loaded
        if (file_exists(__DIR__ . '/db.php')) {
            require_once __DIR__ . '/db.php';
            log_activity($db, 'session_timeout', 'User session expired after 30 minutes of inactivity', $_SESSION['user_id'], $_SESSION['username'] ?? null);
        }
        session_unset();
        session_destroy();
        header("Location: login.php?reason=timeout");
        exit;
    }
    $_SESSION['last_activity'] = $now;
}

$current_script = basename($_SERVER['PHP_SELF']);

// 4. Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    if ($current_script !== 'login.php') {
        header("Location: login.php");
        exit;
    }
} else {
    // Ensure role and name are populated if missing from an existing session
    if (!isset($_SESSION['user_role']) || !isset($_SESSION['user_name'])) {
        require_once 'db.php';
        try {
            $stmt_auth = $db->prepare("SELECT role, name, username FROM users WHERE id = ?");
            $stmt_auth->execute([$_SESSION['user_id']]);
            $u_auth = $stmt_auth->fetch();
            if ($u_auth) {
                $_SESSION['user_role'] = $u_auth['role'];
                $_SESSION['user_name'] = $u_auth['name'];
                $_SESSION['username'] = $u_auth['username'];
            } else {
                session_unset();
                session_destroy();
                header("Location: login.php");
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['user_role'] = 'billing';
            $_SESSION['user_name'] = 'User';
        }
    }

    // If logged in, block access to login page
    if ($current_script === 'login.php') {
        header("Location: index.php");
        exit;
    }
}

/**
 * Checks if the current logged-in user possesses the required role(s).
 * If not, halts execution and renders a beautiful Access Denied alert.
 * 
 * @param array|string $allowed_roles
 */
function check_role($allowed_roles) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_role'])) {
        header("Location: login.php");
        exit;
    }
    
    $role = $_SESSION['user_role'];
    $allowed = is_array($allowed_roles) ? $allowed_roles : [$allowed_roles];
    
    if (!in_array($role, $allowed)) {
        http_response_code(403);
        include_once 'header.php';
        ?>
        <div class="section-card" style="max-width: 600px; margin: 60px auto; border-left: 4px solid var(--danger); text-align: center; padding: 40px;">
            <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 20px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            <h2 style="color: var(--danger); text-transform: uppercase; margin-bottom: 12px;">Access Forbidden</h2>
            <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 24px;">
                You do not have the required permissions to view this resource. This area is reserved for <strong><?= htmlspecialchars(implode(' or ', array_map('ucfirst', $allowed))) ?></strong> level accounts.
            </p>
            <a href="index.php" class="btn btn-secondary">Return to Dashboard</a>
        </div>
        <?php
        include_once 'footer.php';
        exit;
    }
}

/**
 * Generate or retrieve CSRF token for the current session
 */
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden CSRF token form input field
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(get_csrf_token()) . '">';
}

/**
 * Verify submitted CSRF token
 */
function verify_csrf_token($token = null) {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? '');
    }
    $session_token = $_SESSION['csrf_token'] ?? '';
    if (empty($token) || empty($session_token)) {
        return false;
    }
    return hash_equals($session_token, $token);
}

/**
 * Enforce CSRF token verification; aborts execution if invalid
 */
function enforce_csrf() {
    if (!verify_csrf_token()) {
        http_response_code(403);
        die("Security validation failed (Invalid or missing CSRF token). Please return to the previous page and refresh.");
    }
}
?>
