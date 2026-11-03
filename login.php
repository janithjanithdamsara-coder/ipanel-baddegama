<?php
// login.php
// Login page for system users with brute-force protection, CSRF check, and session hardening

require_once 'db.php';
require_once 'auth.php'; // handles redirect to index.php if already logged in

$error = '';
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

if (isset($_GET['reason']) && $_GET['reason'] === 'timeout') {
    $error = "Your session expired due to inactivity. Please log in again.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // 1. Verify CSRF Token
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed. Please refresh and try again.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($username) && !empty($password)) {
            try {
                // 2. Check Rate Limiting / Brute-Force Attacks (Max 5 attempts per 5 minutes)
                $time_limit = date('Y-m-d H:i:s', time() - 300);
                $stmt_fail = $db->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = 'login_failed' AND (ip_address = ? OR details LIKE ?) AND created_at > ?");
                $stmt_fail->execute([$client_ip, "%username: '{$username}'%", $time_limit]);
                $failed_attempts = intval($stmt_fail->fetchColumn() ?? 0);

                if ($failed_attempts >= 5) {
                    $error = "Too many failed login attempts. Access is locked for 5 minutes for security.";
                } else {
                    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($password, $user['password_hash'])) {
                        // 3. Prevent Session Fixation
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['last_activity'] = time();
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        $_SESSION['flash_msg'] = "Welcome back, " . htmlspecialchars($user['name']) . "!";
                        $_SESSION['flash_type'] = "success";

                        // Log successful login
                        log_activity($db, 'login_success', "User '{$user['username']}' ({$user['role']}) logged in successfully", $user['id'], $user['username']);

                        header("Location: index.php");
                        exit;
                    } else {
                        // Log failed attempt
                        log_activity($db, 'login_failed', "Failed login attempt for username: '{$username}'", null, $username);
                        $remaining = 4 - $failed_attempts;
                        if ($remaining > 0) {
                            $error = "Invalid username or password. ($remaining attempts remaining before lockout)";
                        } else {
                            $error = "Invalid username or password. Account lockout activated for 5 minutes.";
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = "A database error occurred. Please contact the administrator.";
            }
        } else {
            $error = "Please fill in all fields.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - i-Panel Baddegama Portal</title>
    <link rel="stylesheet" href="style.css?v=2.0">
    <style>
        body {
            background-color: var(--secondary-brand); /* Deep Slate */
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .login-card {
            background-color: #ffffff;
            width: 100%;
            max-width: 420px;
            padding: 40px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            text-align: left;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-logo {
            font-family: 'Inter Tight', sans-serif;
            font-weight: 800;
            font-size: clamp(20px, 5vw, 24px);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--secondary-brand);
            white-space: nowrap;
        }

        .login-logo span {
            color: var(--primary-brand);
            background: linear-gradient(135deg, #3b82f6, #0056FF);
            -webkit-background-clip: text;
            -webkit-fill-color: transparent;
        }

        .login-subtitle {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .login-error {
            background-color: var(--danger-glow);
            border-left: 4px solid var(--danger);
            color: #b91c1c;
            padding: 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
            border-radius: var(--radius-sm);
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="login-header">
            <div class="login-logo">i-Panel<span>Baddegama</span></div>
            <div class="login-subtitle">Installer Portal Login</div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="login-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <input type="hidden" name="login" value="1">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required placeholder="Enter username" autocomplete="username">
            </div>

            <div class="form-group" style="margin-bottom: 24px;">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="Enter password" autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; height: 48px;">
                Log In
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 2px;"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" x2="3" y1="12" y2="12"></line></svg>
            </button>
        </form>

        <div style="margin-top: 24px; text-align: center; font-size: 12px; color: var(--text-muted);">
            <p>Protected by i-Panel Security Hardening & Rate Limiting</p>
        </div>
    </div>

</body>
</html>
