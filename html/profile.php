<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

$currentUserId = $_SESSION['user_id'] ?? null;
$viewUserId = isset($_GET['id']) ? (int)$_GET['id'] : $currentUserId;

if (!$viewUserId) {
    header('Location: login.php');
    exit;
}

$isOwnProfile = ($currentUserId && (int)$currentUserId === (int)$viewUserId);
$editMode = isset($_GET['edit']) && $isOwnProfile;

$msgSuccess = $_SESSION['success_msg'] ?? '';
$msgError = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Handle Profile Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwnProfile && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $age = (int)($_POST['age'] ?? 0);
    $gender = $_POST['gender'] ?? 'Male';
    $vehicleModel = trim($_POST['vehicle_model'] ?? '');
    $vehicleReg = trim($_POST['vehicle_reg'] ?? '');

    if (empty($name) || $age < 18) {
        $msgError = "Valid name and age (18+) are required.";
    } else {
        try {
            $pdo->beginTransaction();
            $upUser = $pdo->prepare("UPDATE `User` SET Name = ?, Age = ?, Gender = ? WHERE UserID = ?");
            $upUser->execute([$name, $age, $gender, $currentUserId]);
            $_SESSION['name'] = $name;

            // Update phone
            if (!empty($phone)) {
                $pdo->prepare("DELETE FROM `User_Phone` WHERE UserID = ?")->execute([$currentUserId]);
                $pdo->prepare("INSERT INTO `User_Phone` (UserID, Phone) VALUES (?, ?)")->execute([$currentUserId, $phone]);
            }

            // Update vehicle if user is a driver
            if ($_SESSION['user_type'] === 'Driver' && !empty($vehicleModel) && !empty($vehicleReg)) {
                $pdo->prepare("INSERT INTO `Vehicle` (RegNo, Model, UserID) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE Model = ?")
                    ->execute([$vehicleReg, $vehicleModel, $currentUserId, $vehicleModel]);
            }

            $pdo->commit();
            $msgSuccess = "Profile updated successfully!";
            $editMode = false;
        } catch (Exception $e) {
            $pdo->rollBack();
            $msgError = "Failed to update profile: " . $e->getMessage();
        }
    }
}

// Handle Adding Favorite Location
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwnProfile && isset($_POST['add_favorite'])) {
    $address = trim($_POST['fav_address'] ?? '');
    if (!empty($address)) {
        $pdo->prepare("INSERT INTO `FavoriteLocation` (`Address`, `UserID`) VALUES (?, ?)")->execute([$address, $currentUserId]);
        $msgSuccess = "Saved to favorite commute locations!";
    }
}

// Handle Deleting Favorite Location
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwnProfile && isset($_POST['delete_favorite'])) {
    $locId = (int)($_POST['loc_id'] ?? 0);
    if ($locId > 0) {
        $pdo->prepare("DELETE FROM `FavoriteLocation` WHERE `LocID` = ? AND `UserID` = ?")->execute([$locId, $currentUserId]);
        $msgSuccess = "Favorite location removed.";
    }
}

// Fetch Favorite Locations
$favStmt = $pdo->prepare("SELECT * FROM `FavoriteLocation` WHERE UserID = ?");
$favStmt->execute([$viewUserId]);
$favoriteLocations = $favStmt->fetchAll();

// Fetch Profile Data
$uStmt = $pdo->prepare("
    SELECT u.*, d.LicenseNo, p.PassRating 
    FROM `User` u
    LEFT JOIN `Driver` d ON u.UserID = d.UserID
    LEFT JOIN `Passenger` p ON u.UserID = p.UserID
    WHERE u.UserID = ?
");
$uStmt->execute([$viewUserId]);
$user = $uStmt->fetch();

if (!$user) {
    die("User profile not found.");
}

// Fetch Phone Numbers
$phoneStmt = $pdo->prepare("SELECT Phone FROM `User_Phone` WHERE UserID = ?");
$phoneStmt->execute([$viewUserId]);
$phones = $phoneStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch Vehicles
$vStmt = $pdo->prepare("SELECT * FROM `Vehicle` WHERE UserID = ?");
$vStmt->execute([$viewUserId]);
$vehicles = $vStmt->fetchAll();

// Fetch Ride Statistics
$driverRidesStmt = $pdo->prepare("SELECT COUNT(*) FROM `Ride` WHERE DriverID = ? AND Status = 'Completed'");
$driverRidesStmt->execute([$viewUserId]);
$ridesAsDriver = (int)$driverRidesStmt->fetchColumn();

$passRidesStmt = $pdo->prepare("SELECT COUNT(*) FROM `RideParticipant` rp JOIN `Ride` r ON rp.RideID = r.RideID WHERE rp.UserID = ? AND rp.Role = 'Passenger' AND r.Status = 'Completed'");
$passRidesStmt->execute([$viewUserId]);
$ridesAsPassenger = (int)$passRidesStmt->fetchColumn();

$totalCompletedRides = $ridesAsDriver + $ridesAsPassenger;

// Fetch Reviews Received
$revStmt = $pdo->prepare("
    SELECT r.*, u.Name AS ReviewerName, u.UniversityVerified AS ReviewerVerified, rd.StartLocation, rd.Destination, rd.RideDate
    FROM `Rating` r
    JOIN `User` u ON r.ReviewerID = u.UserID
    JOIN `Ride` rd ON r.RideID = rd.RideID
    WHERE r.RecipientID = ?
    ORDER BY r.created_at DESC
");
$revStmt->execute([$viewUserId]);
$reviews = $revStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($user['Name']) ?> - BRACU Rideshare Profile</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .profile-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .profile-header-card {
            background: #ffffff;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            padding: 2.25rem;
            display: flex;
            gap: 2rem;
            align-items: center;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }
        .profile-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.75rem;
            font-weight: 800;
            box-shadow: var(--shadow-md);
            flex-shrink: 0;
        }
        .profile-info {
            flex: 1;
        }
        .profile-name {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.35rem;
        }
        .profile-meta {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 0.75rem;
        }
        .stats-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 1rem;
            margin-top: 1.25rem;
        }
        .stat-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
        }
        .stat-box .num {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
        }
        .stat-box .lbl {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .review-item {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1rem;
        }
        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .review-author {
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .review-text {
            color: #334155;
            font-size: 0.95rem;
            font-style: italic;
        }
        .review-ride-tag {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
        }
        @media (max-width: 650px) {
            .profile-header-card {
                flex-direction: column;
                text-align: center;
            }
            .profile-name {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php render_navbar($isOwnProfile ? 'profile' : ''); ?>

    <div class="main-container">
        <div class="profile-container">

            <?php if (!empty($msgSuccess)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($msgSuccess) ?></div>
            <?php endif; ?>
            <?php if (!empty($msgError)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($msgError) ?></div>
            <?php endif; ?>

            <!-- Profile Header -->
            <div class="profile-header-card">
                <div class="profile-avatar-large">
                    <?= strtoupper(substr($user['Name'], 0, 1)) ?>
                </div>
                <div class="profile-info">
                    <div class="profile-name">
                        <?= htmlspecialchars($user['Name']) ?>
                        <?= render_verification_badge($user['UniversityVerified']) ?>
                    </div>
                    <div class="profile-meta">
                        <span>Role: <strong><?= htmlspecialchars($user['UserType']) ?></strong></span> · 
                        <span>Member since <?= date('M Y', strtotime($user['created_at'])) ?></span>
                    </div>

                    <div class="rating-badge" style="font-size: 1.1rem;">
                        ⭐️ <?= number_format($user['RatingAverage'], 2) ?> 
                        <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: normal;">(<?= $user['RatingCount'] ?> ratings)</span>
                    </div>

                    <div class="stats-summary-grid">
                        <div class="stat-box">
                            <div class="num"><?= $totalCompletedRides ?></div>
                            <div class="lbl">Total Rides</div>
                        </div>
                        <div class="stat-box">
                            <div class="num"><?= $ridesAsDriver ?></div>
                            <div class="lbl">As Driver</div>
                        </div>
                        <div class="stat-box">
                            <div class="num"><?= $ridesAsPassenger ?></div>
                            <div class="lbl">As Passenger</div>
                        </div>
                    </div>
                </div>

                <?php if ($isOwnProfile && !$editMode): ?>
                    <div>
                        <a href="profile.php?edit=1" class="btn btn-secondary btn-sm">Edit Profile</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($editMode): ?>
                <!-- Edit Profile Form -->
                <div class="card">
                    <h2>Edit Your Profile</h2>
                    <form method="POST">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['Name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($phones[0] ?? '') ?>" placeholder="+8801700000000">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label>Age</label>
                                <input type="number" name="age" class="form-control" min="18" value="<?= htmlspecialchars($user['Age']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Gender</label>
                                <select name="gender" class="form-control">
                                    <option value="Male" <?= $user['Gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= $user['Gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                                    <option value="Other" <?= $user['Gender'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                        </div>

                        <?php if ($user['UserType'] === 'Driver'): ?>
                            <h3 style="margin: 1.5rem 0 0.75rem 0; font-size: 1.1rem;">Vehicle Information</h3>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="form-group">
                                    <label>Vehicle Model (e.g. Toyota Axio 2018)</label>
                                    <input type="text" name="vehicle_model" class="form-control" value="<?= htmlspecialchars($vehicles[0]['Model'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Registration Number</label>
                                    <input type="text" name="vehicle_reg" class="form-control" value="<?= htmlspecialchars($vehicles[0]['RegNo'] ?? '') ?>">
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="actions-row">
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                            <a href="profile.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php else: ?>

                <!-- Vehicles Section for Drivers -->
                <?php if ($user['UserType'] === 'Driver' && !empty($vehicles)): ?>
                    <div class="card">
                        <h2>🚘 Registered Vehicle</h2>
                        <?php foreach ($vehicles as $v): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                                <div>
                                    <strong><?= htmlspecialchars($v['Model']) ?></strong>
                                    <p style="font-size: 0.85rem; color: var(--text-muted);">Plate: <?= htmlspecialchars($v['RegNo']) ?></p>
                                </div>
                                <span class="badge-verified">Active Vehicle</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Favorite Commute Locations Section -->
                <div class="card">
                    <h2>📍 Favorite Commute Locations (<?= count($favoriteLocations) ?>)</h2>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                        Save your daily home pickup spots or campus dropoffs for quick 1-click searches on the homepage.
                    </p>

                    <?php if (!empty($favoriteLocations)): ?>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1.25rem;">
                            <?php foreach ($favoriteLocations as $fav): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <span style="color: #f59e0b;">📍</span>
                                        <strong><?= htmlspecialchars($fav['Address']) ?></strong>
                                    </div>
                                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                                        <a href="index.php?search=1&dest=<?= urlencode($fav['Address']) ?>" class="btn btn-secondary btn-sm">Find Rides</a>
                                        <?php if ($isOwnProfile): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="delete_favorite" value="1">
                                                <input type="hidden" name="loc_id" value="<?= $fav['LocID'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">✕</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isOwnProfile): ?>
                        <form method="POST" style="display: flex; gap: 0.75rem;">
                            <input type="hidden" name="add_favorite" value="1">
                            <input type="text" name="fav_address" class="form-control" placeholder="Add new favorite location (e.g. Mirpur 10, Dhanmondi 27)..." required style="margin-bottom: 0;">
                            <button type="submit" class="btn btn-primary btn-sm" style="white-space: nowrap;">+ Add Commute</button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Reviews & Ratings Section -->
                <div class="card">
                    <h2>⭐️ Peer Reviews & Feedback (<?= count($reviews) ?>)</h2>
                    <?php if (empty($reviews)): ?>
                        <div class="empty-state-card" style="padding: 2rem 1rem; margin: 1rem 0;">
                            <div class="empty-state-icon">💬</div>
                            <h3>No reviews yet</h3>
                            <p>Reviews and star ratings from fellow university riders will appear here once rides are completed.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($reviews as $rev): ?>
                            <div class="review-item">
                                <div class="review-header">
                                    <div class="review-author">
                                        <?= htmlspecialchars($rev['ReviewerName']) ?>
                                        <?= render_verification_badge($rev['ReviewerVerified']) ?>
                                    </div>
                                    <div class="rating-badge">
                                        <?= str_repeat('★', (int)$rev['Rating']) . str_repeat('☆', 5 - (int)$rev['Rating']) ?>
                                    </div>
                                </div>
                                <?php if (!empty($rev['Review'])): ?>
                                    <p class="review-text">"<?= htmlspecialchars($rev['Review']) ?>"</p>
                                <?php else: ?>
                                    <p class="review-text" style="color: var(--text-muted);">(No written review provided)</p>
                                <?php endif; ?>
                                <div class="review-ride-tag">
                                    Ride: <?= htmlspecialchars($rev['StartLocation']) ?> → <?= htmlspecialchars($rev['Destination']) ?> · <?= date('M j, Y', strtotime($rev['RideDate'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <?php render_footer(); ?>
</body>
</html>
