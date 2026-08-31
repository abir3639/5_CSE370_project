<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

$error = '';
$success = '';

// Helper to normalize phone numbers for accurate comparison (stripping dashes, spaces, +88)
function normalize_phone_number($phone) {
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if (str_starts_with($digits, '880')) {
        $digits = substr($digits, 2);
    }
    return $digits;
}

$step = 1;
if (isset($_SESSION['reset_user_id']) && !empty($_SESSION['reset_user_id'])) {
    $step = 2;
}

// Reset step if requested
if (isset($_GET['restart'])) {
    unset($_SESSION['reset_user_id'], $_SESSION['reset_user_name'], $_SESSION['reset_user_email']);
    header('Location: forgot_password.php');
    exit;
}

// -----------------------------------------------------------------------------
// STEP 1: Verify Account & Registered Phone Number
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_phone_action'])) {
    $identity = trim($_POST['identity'] ?? '');
    $inputPhone = trim($_POST['phone_number'] ?? '');

    if (empty($identity) || empty($inputPhone)) {
        $error = "Please provide both your university email/username and registered phone number.";
    } else {
        // Find user by Email or Name
        $uStmt = $pdo->prepare("
            SELECT UserID, Name, Email, IsBanned 
            FROM `User` 
            WHERE LOWER(TRIM(Email)) = LOWER(:identity) OR LOWER(TRIM(Name)) = LOWER(:identity2)
            LIMIT 1
        ");
        $uStmt->execute([
            'identity' => $identity,
            'identity2' => $identity
        ]);
        $user = $uStmt->fetch();

        if (!$user) {
            $error = "No user account was found with that email or username.";
        } elseif (!empty($user['IsBanned'])) {
            $error = "🚫 This account has been suspended. Please contact platform administrators.";
        } else {
            // Fetch registered phone numbers for this user
            $pStmt = $pdo->prepare("SELECT Phone FROM `User_Phone` WHERE `UserID` = ?");
            $pStmt->execute([$user['UserID']]);
            $registeredPhones = $pStmt->fetchAll(PDO::FETCH_COLUMN);

            $normInput = normalize_phone_number($inputPhone);
            $phoneMatched = false;

            foreach ($registeredPhones as $regPhone) {
                if (normalize_phone_number($regPhone) === $normInput && !empty($normInput)) {
                    $phoneMatched = true;
                    break;
                }
            }

            if ($phoneMatched) {
                $_SESSION['reset_user_id'] = $user['UserID'];
                $_SESSION['reset_user_name'] = $user['Name'];
                $_SESSION['reset_user_email'] = $user['Email'];
                $step = 2;
            } else {
                $error = "The phone number entered does not match our records for this account. Please verify your phone number.";
            }
        }
    }
}

// -----------------------------------------------------------------------------
// STEP 2: Set New Password
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_new_password_action'])) {
    $userId = (int)($_SESSION['reset_user_id'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($userId <= 0) {
        $error = "Session expired. Please restart the password reset process.";
        $step = 1;
    } elseif (empty($newPassword) || empty($confirmPassword)) {
        $error = "Please enter and confirm your new password.";
        $step = 2;
    } elseif (strlen($newPassword) < 6) {
        $error = "Password must be at least 6 characters long.";
        $step = 2;
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match. Please re-enter.";
        $step = 2;
    } else {
        try {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $upStmt = $pdo->prepare("UPDATE `User` SET `Password` = ? WHERE `UserID` = ?");
            $upStmt->execute([$hashedPassword, $userId]);

            // Notify user of password change
            create_notification(
                $pdo,
                $userId,
                'security_alert',
                'Password Reset Successful 🔒',
                'Your account password was successfully reset using phone verification.',
                'profile.php'
            );

            unset($_SESSION['reset_user_id'], $_SESSION['reset_user_name'], $_SESSION['reset_user_email']);
            $_SESSION['success_msg'] = "🎉 Your password has been successfully updated! You can now log in with your new password.";
            header('Location: login.php');
            exit;
        } catch (Exception $e) {
            $error = "Failed to update password: " . $e->getMessage();
            $step = 2;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - BRAC University Rideshare</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .step-indicator-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            position: relative;
        }
        .step-indicator-bar::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 20%;
            right: 20%;
            height: 2px;
            background: var(--border-color);
            z-index: 0;
            transform: translateY(-50%);
        }
        .step-node {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.35rem;
            position: relative;
            z-index: 1;
        }
        .step-circle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.95rem;
            background: #f1f5f9;
            color: #64748b;
            border: 2px solid var(--border-color);
        }
        .step-node.active .step-circle {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px #e0f2fe;
        }
        .step-node.completed .step-circle {
            background: #16a34a;
            color: #ffffff;
            border-color: #16a34a;
        }
        .step-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
        }
        .step-node.active .step-label {
            color: var(--primary);
            font-weight: 700;
        }
    </style>
</head>
<body>
    <?php render_navbar(); ?>

    <div class="auth-page-wrapper">
        <div class="auth-card-modern">
            
            <div class="auth-header">
                <div class="auth-badge-icon" style="background: #e0f2fe; color: var(--accent);">
                    🔑
                </div>
                <h1 class="auth-title">Reset Your Password</h1>
                <p class="auth-subtitle">Verify your registered phone number to set a new password</p>
            </div>

            <!-- Step Progress Indicator -->
            <div class="step-indicator-bar">
                <div class="step-node <?= $step === 1 ? 'active' : 'completed' ?>">
                    <div class="step-circle">1</div>
                    <span class="step-label">Phone Verification</span>
                </div>
                <div class="step-node <?= $step === 2 ? 'active' : '' ?>">
                    <div class="step-circle">2</div>
                    <span class="step-label">Set New Password</span>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
                    <svg style="width: 20px; height: 20px; flex-shrink: 0;" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
                <!-- STEP 1: Phone Verification Form -->
                <form method="POST" action="forgot_password.php">
                    <input type="hidden" name="verify_phone_action" value="1">

                    <div class="form-group">
                        <label for="identity">University Email or Username *</label>
                        <div class="input-icon-wrapper">
                            <svg class="input-icon-left" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                                <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
                            </svg>
                            <input type="text" id="identity" name="identity" class="form-control has-icon" placeholder="e.g. rahim@g.bracu.ac.bd or Rahim Ahmed" value="<?= htmlspecialchars($_POST['identity'] ?? '') ?>" required autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="phone_number">Registered Phone Number *</label>
                        <div class="input-icon-wrapper">
                            <svg class="input-icon-left" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 4V3z"/>
                            </svg>
                            <input type="tel" id="phone_number" name="phone_number" class="form-control has-icon" placeholder="e.g. 01711223344 or +880-1711-223344" value="<?= htmlspecialchars($_POST['phone_number'] ?? '') ?>" required>
                        </div>
                        <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.25rem; display: block;">
                            Enter the phone number associated with your BRAC University rideshare account.
                        </small>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-size: 1rem; margin-top: 1rem;">
                        🔍 Verify Phone Number & Continue
                    </button>
                </form>

            <?php else: ?>
                <!-- STEP 2: Set New Password Form -->
                <div style="background: #f0fdf4; border: 1.5px solid #86efac; border-radius: var(--radius-sm); padding: 0.85rem 1rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between;">
                    <div style="font-size: 0.88rem; color: #166534;">
                        ✓ Verified: <strong><?= htmlspecialchars($_SESSION['reset_user_name'] ?? 'User') ?></strong> (<?= htmlspecialchars($_SESSION['reset_user_email'] ?? '') ?>)
                    </div>
                    <a href="forgot_password.php?restart=1" style="font-size: 0.8rem; color: #15803d; font-weight: 700;">Change Account</a>
                </div>

                <form method="POST" action="forgot_password.php">
                    <input type="hidden" name="set_new_password_action" value="1">

                    <div class="form-group">
                        <label for="new_password">New Password * (Min. 6 characters)</label>
                        <div class="input-icon-wrapper">
                            <svg class="input-icon-left" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                            </svg>
                            <input type="password" id="new_password" name="new_password" class="form-control has-icon" placeholder="Enter new password" required autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password *</label>
                        <div class="input-icon-wrapper">
                            <svg class="input-icon-left" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                            </svg>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control has-icon" placeholder="Re-enter new password" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-size: 1rem; margin-top: 1rem; background: #16a34a; border-color: #16a34a;">
                        💾 Save New Password & Log In
                    </button>
                </form>
            <?php endif; ?>

            <div class="auth-footer" style="margin-top: 1.5rem; text-align: center; font-size: 0.9rem;">
                Remembered your password? <a href="login.php" style="font-weight: 700; color: var(--accent);">Back to Log In</a>
            </div>

        </div>
    </div>

    <?php render_footer(); ?>
</body>
</html>
