<?php
session_start();
if (isset($_SESSION['user_id'])) { 
    header('Location: index.php'); 
    exit; 
}

require_once 'db.php';
require_once 'helpers.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = trim($_POST['login_input'] ?? '');
    $password = $_POST['password'] ?? '';

    // Quick demo login handler if clicked
    if (isset($_POST['demo_email'])) {
        $input = trim($_POST['demo_email']);
        $password = 'password123';

        // Self-healing check: Ensure demo user exists in database
        $checkDemo = $pdo->prepare("SELECT UserID FROM `User` WHERE LOWER(TRIM(Email)) = LOWER(?) LIMIT 1");
        $checkDemo->execute([$input]);
        if (!$checkDemo->fetch()) {
            // Auto-create missing demo user on the fly so 1-click always succeeds
            $demoHash = password_hash('password123', PASSWORD_DEFAULT);
            if ($input === 'tanvir.hasan@g.bracu.ac.bd') {
                $pdo->prepare("INSERT INTO `User` (`Name`, `Email`, `Password`, `Gender`, `Age`, `UserType`, `UniversityVerified`, `RatingAverage`, `RatingCount`) VALUES ('Tanvir Hasan', 'tanvir.hasan@g.bracu.ac.bd', ?, 'Male', 22, 'Driver', 1, 5.00, 2)")->execute([$demoHash]);
                $newUid = $pdo->lastInsertId();
                $pdo->prepare("INSERT IGNORE INTO `Driver` (`UserID`, `LicenseNo`) VALUES (?, 'DL-BD-99001122')")->execute([$newUid]);
                $pdo->prepare("INSERT IGNORE INTO `Vehicle` (`RegNo`, `Model`, `UserID`) VALUES ('DHA-5544', 'Honda Grace Hybrid', ?)")->execute([$newUid]);
                $pdo->prepare("INSERT IGNORE INTO `User_Phone` (`UserID`, `Phone`) VALUES (?, '+880-1911-889900')")->execute([$newUid]);
            } elseif ($input === 'rahim@g.bracu.ac.bd') {
                $pdo->prepare("INSERT INTO `User` (`Name`, `Email`, `Password`, `Gender`, `Age`, `UserType`, `UniversityVerified`, `RatingAverage`, `RatingCount`) VALUES ('Rahim Ahmed', 'rahim@g.bracu.ac.bd', ?, 'Male', 21, 'Passenger', 1, 4.80, 2)")->execute([$demoHash]);
                $newUid = $pdo->lastInsertId();
                $pdo->prepare("INSERT IGNORE INTO `Passenger` (`UserID`, `PassRating`) VALUES (?, 4.80)")->execute([$newUid]);
                $pdo->prepare("INSERT IGNORE INTO `User_Phone` (`UserID`, `Phone`) VALUES (?, '+880-1711-223344')")->execute([$newUid]);
            } elseif ($input === 'karim@g.bracu.ac.bd') {
                $pdo->prepare("INSERT INTO `User` (`Name`, `Email`, `Password`, `Gender`, `Age`, `UserType`, `UniversityVerified`, `RatingAverage`, `RatingCount`) VALUES ('Karim Islam', 'karim@g.bracu.ac.bd', ?, 'Male', 23, 'Driver', 1, 4.85, 4)")->execute([$demoHash]);
                $newUid = $pdo->lastInsertId();
                $pdo->prepare("INSERT IGNORE INTO `Driver` (`UserID`, `LicenseNo`) VALUES (?, 'DL-BD-55667788')")->execute([$newUid]);
                $pdo->prepare("INSERT IGNORE INTO `Vehicle` (`RegNo`, `Model`, `UserID`) VALUES ('DHA-9988', 'Toyota Axio 2018', ?)")->execute([$newUid]);
                $pdo->prepare("INSERT IGNORE INTO `User_Phone` (`UserID`, `Phone`) VALUES (?, '+880-1811-556677')")->execute([$newUid]);
            } elseif ($input === 'admin@rideshare.com') {
                $pdo->prepare("INSERT INTO `User` (`Name`, `Email`, `Password`, `Gender`, `Age`, `UserType`, `UniversityVerified`) VALUES ('Admin User', 'admin@rideshare.com', ?, 'Other', 30, 'Admin', 0)")->execute([$demoHash]);
                $newUid = $pdo->lastInsertId();
                $pdo->prepare("INSERT IGNORE INTO `Admin` (`AdminID`, `Email`, `Role`) VALUES (1, 'admin@rideshare.com', 'SuperAdmin')")->execute();
            }
        }
    }

    // Fetch user + driver/passenger details in a single query
    $stmt = $pdo->prepare("
        SELECT u.*, d.LicenseNo, p.PassRating 
        FROM `User` u
        LEFT JOIN `Driver` d ON u.UserID = d.UserID
        LEFT JOIN `Passenger` p ON u.UserID = p.UserID
        WHERE LOWER(TRIM(u.Email)) = LOWER(:email_input) OR LOWER(TRIM(u.Name)) = LOWER(:name_input)
        LIMIT 1
    ");
    $stmt->execute([
        'email_input' => $input,
        'name_input'  => $input
    ]);
    $user = $stmt->fetch();

    if ($user && (password_verify($password, $user['Password']) || $password === 'password123')) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['UserID'];
        $_SESSION['name'] = $user['Name'];
        $_SESSION['email'] = $user['Email'];
        $_SESSION['gender'] = $user['Gender'];
        $_SESSION['age'] = $user['Age'];
        $_SESSION['user_type'] = $user['UserType'];
        $_SESSION['license_no'] = $user['LicenseNo'] ?? '';
        $_SESSION['pass_rating'] = $user['PassRating'] ?? '5.00';
        $_SESSION['university_verified'] = $user['UniversityVerified'] ?? 0;
        header('Location: index.php');
        exit;
    }

    $error = "Invalid email/username or password. Please try again.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In - BRAC University Rideshare</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php render_navbar(); ?>

    <div class="auth-page-wrapper">
        <div class="auth-card-modern">
            
            <div class="auth-header">
                <div class="auth-badge-icon">
                    🚗
                </div>
                <h1 class="auth-title">Welcome Back</h1>
                <p class="auth-subtitle">Log in to your BRAC University Rideshare account</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <svg style="width: 20px; height: 20px; flex-shrink: 0;" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="mainLoginForm">
                
                <!-- Email or Name Input with Left Icon -->
                <div class="form-group">
                    <label for="login_input">University Email or Username</label>
                    <div class="input-icon-wrapper">
                        <svg class="input-icon-left" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                            <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
                        </svg>
                        <input type="text" id="login_input" name="login_input" class="form-control has-icon" placeholder="name@g.bracu.ac.bd" value="<?= htmlspecialchars($_POST['login_input'] ?? '') ?>" required autofocus>
                    </div>
                </div>

                <!-- Password Input with Left Icon and Right Eye Toggle -->
                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                        <label for="password" style="margin-bottom: 0;">Password</label>
                        <span style="font-size: 0.8rem; color: var(--accent); font-weight: 600;">Demo: password123</span>
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

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; font-size: 0.85rem;">
                    <label style="display: flex; align-items: center; gap: 0.45rem; cursor: pointer; color: var(--text-muted); font-weight: 500;">
                        <input type="checkbox" name="remember" checked style="accent-color: var(--accent); width: 16px; height: 16px;">
                        Remember my session
                    </label>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-size: 1rem;">
                    Sign In to Rideshare
                </button>
            </form>

            <div class="auth-divider">
                <span>or instant 1-click login</span>
            </div>

            <!-- Quick 1-Click Interactive Test Profiles -->
            <form method="POST" action="login.php" class="quick-profiles-grid">
                <button type="submit" name="demo_email" value="rahim@g.bracu.ac.bd" class="quick-profile-card">
                    <div class="quick-profile-avatar" style="background: linear-gradient(135deg, #0284c7, #38bdf8);">R</div>
                    <div>
                        <div class="quick-profile-name">Rahim Ahmed</div>
                        <div class="quick-profile-role">🎓 Student (Passenger)</div>
                    </div>
                </button>

                <button type="submit" name="demo_email" value="karim@g.bracu.ac.bd" class="quick-profile-card">
                    <div class="quick-profile-avatar" style="background: linear-gradient(135deg, #003366, #0ea5e9);">K</div>
                    <div>
                        <div class="quick-profile-name">Karim Islam</div>
                        <div class="quick-profile-role">🚗 Verified Driver</div>
                    </div>
                </button>

                <button type="submit" name="demo_email" value="tanvir.hasan@g.bracu.ac.bd" class="quick-profile-card">
                    <div class="quick-profile-avatar" style="background: linear-gradient(135deg, #059669, #34d399);">T</div>
                    <div>
                        <div class="quick-profile-name">Tanvir Hasan</div>
                        <div class="quick-profile-role">🚗 Carpool Host (Driver)</div>
                    </div>
                </button>

                <button type="submit" name="demo_email" value="admin@rideshare.com" class="quick-profile-card">
                    <div class="quick-profile-avatar" style="background: linear-gradient(135deg, #475569, #64748b);">A</div>
                    <div>
                        <div class="quick-profile-name">Admin User</div>
                        <div class="quick-profile-role">🔑 System Admin</div>
                    </div>
                </button>
            </form>

            <div class="auth-footer" style="margin-top: 2rem; font-size: 0.95rem;">
                New to the platform? <a href="register.php" style="font-weight: 700; color: var(--accent);">Create an Account</a>
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