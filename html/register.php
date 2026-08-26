<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'db.php';
require_once 'helpers.php';

$errors = [];
$name = '';
$email = '';
$phone = '';
$gender = 'Male';
$age = '';
$userType = 'Passenger';
$licenseNo = '';
$vehicleModel = '';
$vehicleReg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $gender = $_POST['gender'] ?? 'Male';
    $age = intval($_POST['age'] ?? 0);
    $userType = $_POST['userType'] ?? 'Passenger';
    $licenseNo = trim($_POST['licenseNo'] ?? '');
    $vehicleModel = trim($_POST['vehicleModel'] ?? '');
    $vehicleReg = trim($_POST['vehicleReg'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($email) || empty($phone) || empty($password) || empty($confirm_password) || empty($age)) {
        $errors[] = "Please fill in all required fields.";
    } else {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please provide a valid email address.";
        }
        if ($age < 18) {
            $errors[] = "You must be at least 18 years old to register.";
        }
        if ($userType === 'Driver' && empty($licenseNo)) {
            $errors[] = "Driver registration requires a Driver's License Number.";
        }
        if (strlen($password) < 6) {
            $errors[] = "Password must be at least 6 characters long.";
        }
        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match.";
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT UserID FROM `User` WHERE Email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                $errors[] = "An account with this email address already exists.";
            }

            if (empty($errors) && $userType === 'Driver') {
                $dStmt = $pdo->prepare("SELECT UserID FROM `Driver` WHERE LicenseNo = :license LIMIT 1");
                $dStmt->execute(['license' => $licenseNo]);
                if ($dStmt->fetch()) {
                    $errors[] = "This License Number is already registered.";
                }
            }

            if (empty($errors)) {
                $pdo->beginTransaction();

                // Auto-verify if BRAC University email
                $isVerified = 0;
                $emailLower = strtolower(trim($email));
                if (preg_match('/@(g\.)?bracu\.ac\.bd$/i', $emailLower)) {
                    $isVerified = 1;
                }

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $uStmt = $pdo->prepare("
                    INSERT INTO `User` (`Name`, `Email`, `Password`, `Gender`, `Age`, `UserType`, `UniversityVerified`, `RatingAverage`, `RatingCount`) 
                    VALUES (:name, :email, :password, :gender, :age, :userType, :univVerified, 5.00, 0)
                ");
                $uStmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'password' => $hashedPassword,
                    'gender' => $gender,
                    'age' => $age,
                    'userType' => $userType,
                    'univVerified' => $isVerified
                ]);
                $newUserId = $pdo->lastInsertId();

                // Save Phone
                $pStmt = $pdo->prepare("INSERT INTO `User_Phone` (`UserID`, `Phone`) VALUES (:userId, :phone)");
                $pStmt->execute([
                    'userId' => $newUserId,
                    'phone' => $phone
                ]);

                if ($userType === 'Driver') {
                    $driverStmt = $pdo->prepare("INSERT INTO `Driver` (`UserID`, `LicenseNo`) VALUES (:userId, :licenseNo)");
                    $driverStmt->execute([
                        'userId' => $newUserId,
                        'licenseNo' => $licenseNo
                    ]);

                    if (!empty($vehicleReg) && !empty($vehicleModel)) {
                        $vStmt = $pdo->prepare("INSERT INTO `Vehicle` (`RegNo`, `Model`, `UserID`) VALUES (:regNo, :model, :userId)");
                        $vStmt->execute([
                            'regNo' => $vehicleReg,
                            'model' => $vehicleModel,
                            'userId' => $newUserId
                        ]);
                    }
                } else {
                    $passengerStmt = $pdo->prepare("INSERT INTO `Passenger` (`UserID`, `PassRating`) VALUES (:userId, 5.00)");
                    $passengerStmt->execute(['userId' => $newUserId]);
                }

                // Welcome Notification
                create_notification($pdo, $newUserId, 'welcome', 'Welcome to BRACU Rideshare! 🎓', "Your account has been created. Start finding rides or offer seats to fellow students.");

                $pdo->commit();

                // Auto-login newly registered user
                session_regenerate_id(true);
                $_SESSION['user_id'] = $newUserId;
                $_SESSION['name'] = $name;
                $_SESSION['email'] = $email;
                $_SESSION['gender'] = $gender;
                $_SESSION['age'] = $age;
                $_SESSION['user_type'] = $userType;
                $_SESSION['license_no'] = $licenseNo;
                $_SESSION['pass_rating'] = '5.00';
                $_SESSION['university_verified'] = $isVerified;

                $_SESSION['success_msg'] = "Welcome to BRAC University Rideshare! " . ($isVerified ? "Your BRACU email has been verified ✓" : "");
                header('Location: index.php');
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - BRAC University Rideshare</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .univ-hint-box {
            background: #e0f2fe;
            border: 1px solid #bae6fd;
            border-radius: var(--radius-sm);
            padding: 0.85rem 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
            color: #0369a1;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
    </style>
</head>
<body>
    <?php render_navbar(); ?>

    <div class="auth-page-wrapper">
        <div class="auth-card-modern" style="max-width: 580px;">
            
            <div class="auth-header">
                <div class="auth-badge-icon" style="background: linear-gradient(135deg, #0ea5e9, #10b981);">
                    🎓
                </div>
                <h1 class="auth-title">Create Account</h1>
                <p class="auth-subtitle">Join the verified BRAC University student & faculty rideshare network</p>
            </div>

            <div class="univ-hint-box">
                <span style="font-size: 1.2rem;">💡</span>
                <span>Register with your <strong>@g.bracu.ac.bd</strong> or <strong>@bracu.ac.bd</strong> email to automatically receive the <strong>BRACU Verified Badge</strong>!</span>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul style="margin-left: 1.2rem; padding: 0;">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST">
                
                <div class="form-group">
                    <label>Full Name *</label>
                    <div class="input-icon-wrapper">
                        <svg class="input-icon-left" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                        </svg>
                        <input type="text" name="name" class="form-control has-icon" placeholder="e.g. Rahim Ahmed" value="<?= htmlspecialchars($name) ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>University / Contact Email *</label>
                    <div class="input-icon-wrapper">
                        <svg class="input-icon-left" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                            <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
                        </svg>
                        <input type="email" name="email" class="form-control has-icon" placeholder="rahim.ahmed@g.bracu.ac.bd" value="<?= htmlspecialchars($email) ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Phone Number *</label>
                    <div class="input-icon-wrapper">
                        <svg class="input-icon-left" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 4V3z" />
                        </svg>
                        <input type="text" name="phone" class="form-control has-icon" placeholder="+8801700000000" value="<?= htmlspecialchars($phone) ?>" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label>Age *</label>
                        <input type="number" name="age" class="form-control" min="18" placeholder="21" value="<?= htmlspecialchars($age) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="gender" class="form-control">
                            <option value="Male" <?= $gender === 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= $gender === 'Female' ? 'selected' : '' ?>>Female</option>
                            <option value="Other" <?= $gender === 'Other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Primary Role *</label>
                    <select name="userType" id="userType" class="form-control" onchange="toggleDriverFields()">
                        <option value="Passenger" <?= $userType === 'Passenger' ? 'selected' : '' ?>>Passenger (Looking for rides)</option>
                        <option value="Driver" <?= $userType === 'Driver' ? 'selected' : '' ?>>Driver (I have a vehicle & can offer rides)</option>
                    </select>
                </div>

                <div id="driverFields" style="display: <?= $userType === 'Driver' ? 'block' : 'none' ?>; background: #f8fafc; border: 1px solid var(--border-color); padding: 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.35rem;">
                    <div class="form-group">
                        <label>Driver's License Number *</label>
                        <input type="text" name="licenseNo" class="form-control" placeholder="DL-BD-12345678" value="<?= htmlspecialchars($licenseNo) ?>">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Vehicle Model</label>
                            <input type="text" name="vehicleModel" class="form-control" placeholder="e.g. Toyota Axio 2018" value="<?= htmlspecialchars($vehicleModel) ?>">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Registration Plate</label>
                            <input type="text" name="vehicleReg" class="form-control" placeholder="DHA-GA-1234" value="<?= htmlspecialchars($vehicleReg) ?>">
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label>Password (min 6 chars) *</label>
                        <div class="input-icon-wrapper">
                            <svg class="input-icon-left" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                            </svg>
                            <input type="password" id="regPassword" name="password" class="form-control has-icon" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <div class="input-icon-wrapper">
                            <svg class="input-icon-left" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                            </svg>
                            <input type="password" id="regConfirmPassword" name="confirm_password" class="form-control has-icon" required>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.9rem; font-size: 1.05rem; margin-top: 0.5rem;">
                    Create Verified Account
                </button>
            </form>

            <div class="auth-footer" style="margin-top: 2rem; font-size: 0.95rem;">
                Already have an account? <a href="login.php" style="font-weight: 700; color: var(--accent);">Log In here</a>
            </div>
        </div>
    </div>

    <script>
        function toggleDriverFields() {
            var role = document.getElementById('userType').value;
            document.getElementById('driverFields').style.display = (role === 'Driver') ? 'block' : 'none';
        }
    </script>

    <?php render_footer(); ?>
</body>
</html>
