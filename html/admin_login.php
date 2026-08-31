<?php
session_start();

if (isset($_SESSION['user_id']) && ($_SESSION['user_type'] ?? '') === 'Admin') {
    header('Location: admin.php');
    exit;
}

require_once 'db.php';
require_once 'helpers.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = trim($_POST['login_input'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($input) || empty($password)) {
        $error = "Please enter both administrator email/username and password.";
    } else {
        $stmt = $pdo->prepare("
            SELECT u.*, a.Role AS AdminRole 
            FROM `User` u
            LEFT JOIN `Admin` a ON u.AdminID = a.AdminID OR u.Email = a.Email
            WHERE LOWER(TRIM(u.Email)) = LOWER(:input) OR LOWER(TRIM(u.Name)) = LOWER(:input2)
            LIMIT 1
        ");
        $stmt->execute([
            'input' => $input,
            'input2' => $input
        ]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['Password'])) {
            // Verify Administrative Role
            if ($user['UserType'] !== 'Admin') {
                $error = "🚫 Access Denied: This account does not possess administrative privileges. Please log in through the student/driver portal.";
            } elseif (!empty($user['IsBanned'])) {
                $error = "🚫 This administrative account has been suspended.";
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['UserID'];
                $_SESSION['name'] = $user['Name'];
                $_SESSION['email'] = $user['Email'];
                $_SESSION['gender'] = $user['Gender'];
                $_SESSION['age'] = $user['Age'];
                $_SESSION['user_type'] = 'Admin';
                $_SESSION['admin_role'] = $user['AdminRole'] ?? 'SuperAdmin';
                $_SESSION['university_verified'] = $user['UniversityVerified'] ?? 0;

                header('Location: admin.php');
                exit;
            }
        } else {
            $error = "Invalid administrator credentials. Please verify your email and password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal Login - BRAC University Rideshare</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-auth-badge {
            background: linear-gradient(135deg, #1e3a8a, #1e40af);
            color: #ffffff;
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1.25rem auto;
            box-shadow: 0 8px 20px rgba(30, 64, 175, 0.35);
        }
        .admin-card-border {
            border: 2px solid #3b82f6;
            box-shadow: 0 15px 35px rgba(30, 58, 138, 0.12);
        }
        .admin-banner-chip {
            display: inline-block;
            background: #dbeafe;
            color: #1e40af;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <?php render_navbar('admin'); ?>

    <div class="auth-page-wrapper">
        <div class="auth-card-modern admin-card-border">
            
            <div class="auth-header">
                <div class="admin-auth-badge">
                    🛡️
                </div>
                <div>
                    <span class="admin-banner-chip">Authorized Staff Only</span>
                </div>
                <h1 class="auth-title" style="color: #1e3a8a;">Admin Control Portal</h1>
                <p class="auth-subtitle">Platform moderation, ride controls, user management & system analytics</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <svg style="width: 20px; height: 20px; flex-shrink: 0;" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="admin_login.php">
                
                <div class="form-group">
                    <label for="login_input">Administrator Email or Username</label>
                    <div class="input-icon-wrapper">
                        <svg class="input-icon-left" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                            <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
                        </svg>
                        <input type="text" id="login_input" name="login_input" class="form-control has-icon" placeholder="admin or admin@rideshare.com" value="<?= htmlspecialchars($_POST['login_input'] ?? '') ?>" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                        <label for="password" style="margin-bottom: 0;">Admin Master Password</label>
                        <a href="forgot_password.php" style="font-size: 0.82rem; color: #1e40af; font-weight: 600; text-decoration: none;">Forgot Password?</a>
                    </div>
                    <div class="input-icon-wrapper">
                        <svg class="input-icon-left" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                        </svg>
                        <input type="password" id="password" name="password" class="form-control has-icon has-icon-right" placeholder="••••••••" required>
                        <button type="button" class="btn-toggle-pwd" onclick="togglePasswordVisibility()" title="Show/Hide Password">
                            <svg id="eyeIcon" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
                                <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-size: 1rem; background: linear-gradient(135deg, #1e3a8a, #2563eb); border: none;">
                    🔐 Unlock Admin Control Panel
                </button>
            </form>

            <div class="auth-footer" style="margin-top: 1.75rem; font-size: 0.9rem;">
                Looking for student or driver login? <br>
                <a href="login.php" style="font-weight: 700; color: var(--accent);">← Return to Regular User Portal</a>
            </div>

        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            var pwdInput = document.getElementById('password');
            var eyeIcon = document.getElementById('eyeIcon');
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
                eyeIcon.innerHTML = '<path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd" /><path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.064 7 9.542 7 .847 0 1.669-.105 2.454-.303z" />';
            } else {
                pwdInput.type = 'password';
                eyeIcon.innerHTML = '<path d="M10 12a2 2 0 100-4 2 2 0 000 4z" /><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />';
            }
        }
    </script>

    <?php render_footer(); ?>
</body>
</html>
