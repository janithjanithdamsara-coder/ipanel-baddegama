<?php
// logout.php
// Log out system session

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Log activity before session is destroyed
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/db.php';
    log_activity($db, 'logout', "User signed out", $_SESSION['user_id'], $_SESSION['username'] ?? null);
}

// Unset all session values
$_SESSION = array();

// Destroy session cookie if set
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit;
?>
