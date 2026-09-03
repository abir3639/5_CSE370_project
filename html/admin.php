<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

// Strict Admin Gatekeeper
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'Admin') {
    $_SESSION['error_msg'] = "🛡️ Admin authentication required. Please log in with an administrator account.";
    header('Location: admin_login.php');
    exit;
}

$currentAdminId = (int)$_SESSION['user_id'];
$adminName = $_SESSION['name'] ?? 'Administrator';

$msgSuccess = $_SESSION['success_msg'] ?? '';
$msgError = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

$activeTab = $_GET['tab'] ?? 'overview';


// PLATFORM STATISTICS & METRICS

try {
    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM `User`")->fetchColumn();
    $totalVerified = (int)$pdo->query("SELECT COUNT(*) FROM `User` WHERE UniversityVerified = 1")->fetchColumn();
    $totalBanned = (int)$pdo->query("SELECT COUNT(*) FROM `User` WHERE IsBanned = 1")->fetchColumn();
    $totalDrivers = (int)$pdo->query("SELECT COUNT(*) FROM `Driver`")->fetchColumn();
    $totalPassengers = (int)$pdo->query("SELECT COUNT(*) FROM `Passenger`")->fetchColumn();
    
    $totalRides = (int)$pdo->query("SELECT COUNT(*) FROM `Ride`")->fetchColumn();
    $openRides = (int)$pdo->query("SELECT COUNT(*) FROM `Ride` WHERE Status IN ('Open', 'Full')")->fetchColumn();
    $completedRides = (int)$pdo->query("SELECT COUNT(*) FROM `Ride` WHERE Status = 'Completed'")->fetchColumn();
    $cancelledRides = (int)$pdo->query("SELECT COUNT(*) FROM `Ride` WHERE Status = 'Cancelled'")->fetchColumn();
    
    $totalLostFound = (int)$pdo->query("SELECT COUNT(*) FROM `LostItem`")->fetchColumn();
    $openLostFound = (int)$pdo->query("SELECT COUNT(*) FROM `LostItem` WHERE Status = 'Open'")->fetchColumn();
    $resolvedLostFound = (int)$pdo->query("SELECT COUNT(*) FROM `LostItem` WHERE Status = 'Resolved'")->fetchColumn();
    
    $totalRatings = (int)$pdo->query("SELECT COUNT(*) FROM `Rating`")->fetchColumn();
    $avgPlatformRating = $pdo->query("SELECT IFNULL(AVG(Rating), 5.0) FROM `Rating`")->fetchColumn();

} catch (PDOException $e) {
    die("Admin DB Error: " . htmlspecialchars($e->getMessage()));
}


// TAB 2: USER MANAGEMENT DATA

$userSearch = trim($_GET['user_q'] ?? '');
$userRoleFilter = trim($_GET['user_role'] ?? '');
$userStatusFilter = trim($_GET['user_status'] ?? '');

$userWhere = [];
$userParams = [];

if (!empty($userSearch)) {
    $userWhere[] = "(u.Name LIKE ? OR u.Email LIKE ? OR u.UserID = ?)";
    $like = '%' . $userSearch . '%';
    $userParams[] = $like;
    $userParams[] = $like;
    $userParams[] = is_numeric($userSearch) ? (int)$userSearch : 0;
}

if (!empty($userRoleFilter)) {
    $userWhere[] = "u.UserType = ?";
    $userParams[] = $userRoleFilter;
}

if ($userStatusFilter === 'banned') {
    $userWhere[] = "u.IsBanned = 1";
} elseif ($userStatusFilter === 'active') {
    $userWhere[] = "u.IsBanned = 0";
}

$userWhereSql = !empty($userWhere) ? "WHERE " . implode(" AND ", $userWhere) : "";

$userStmt = $pdo->prepare("
    SELECT u.UserID, u.Name, u.Email, u.Gender, u.Age, u.UserType, u.UniversityVerified, u.IsBanned, u.RatingAverage, u.RatingCount, u.created_at,
           IFNULL(GROUP_CONCAT(up.Phone SEPARATOR ', '), 'None') AS Phones 
    FROM `User` u 
    LEFT JOIN `User_Phone` up ON u.UserID = up.UserID 
    $userWhereSql
    GROUP BY u.UserID, u.Name, u.Email, u.Gender, u.Age, u.UserType, u.UniversityVerified, u.IsBanned, u.RatingAverage, u.RatingCount, u.created_at
    ORDER BY u.UserID DESC
");
$userStmt->execute($userParams);
$allUsersList = $userStmt->fetchAll(PDO::FETCH_ASSOC);


// TAB 3: RIDE MANAGEMENT DATA

$rideSearch = trim($_GET['ride_q'] ?? '');
$rideStatusFilter = trim($_GET['ride_status'] ?? '');

$rideWhere = [];
$rideParams = [];

if (!empty($rideSearch)) {
    $rideWhere[] = "(r.StartLocation LIKE ? OR r.Destination LIKE ? OR u.Name LIKE ? OR r.RideID = ?)";
    $likeR = '%' . $rideSearch . '%';
    $rideParams[] = $likeR;
    $rideParams[] = $likeR;
    $rideParams[] = $likeR;
    $rideParams[] = is_numeric($rideSearch) ? (int)$rideSearch : 0;
}

if (!empty($rideStatusFilter)) {
    $rideWhere[] = "r.Status = ?";
    $rideParams[] = $rideStatusFilter;
}

$rideWhereSql = !empty($rideWhere) ? "WHERE " . implode(" AND ", $rideWhere) : "";

$rideStmt = $pdo->prepare("
    SELECT r.*, u.Name AS DriverName, u.Email AS DriverEmail, u.UniversityVerified AS DriverVerified,
           (SELECT COUNT(*) FROM `RideParticipant` WHERE RideID = r.RideID AND Role = 'Passenger') AS PassengerCount
    FROM `Ride` r
    JOIN `User` u ON r.DriverID = u.UserID
    $rideWhereSql
    ORDER BY r.RideDate DESC, r.DepartureTime DESC
");
$rideStmt->execute($rideParams);
$allRidesList = $rideStmt->fetchAll(PDO::FETCH_ASSOC);


// TAB 4: LOST & FOUND DATA

$lfSearch = trim($_GET['lf_q'] ?? '');
$lfTypeFilter = trim($_GET['lf_type'] ?? '');
$lfStatusFilter = trim($_GET['lf_status'] ?? '');

$lfWhere = [];
$lfParams = [];

if (!empty($lfSearch)) {
    $lfWhere[] = "(li.ItemName LIKE ? OR li.Description LIKE ? OR li.LocationDetails LIKE ? OR u.Name LIKE ?)";
    $likeLF = '%' . $lfSearch . '%';
    $lfParams[] = $likeLF;
    $lfParams[] = $likeLF;
    $lfParams[] = $likeLF;
    $lfParams[] = $likeLF;
}

if (!empty($lfTypeFilter)) {
    $lfWhere[] = "li.ReportType = ?";
    $lfParams[] = $lfTypeFilter;
}

if (!empty($lfStatusFilter)) {
    $lfWhere[] = "li.Status = ?";
    $lfParams[] = $lfStatusFilter;
}

$lfWhereSql = !empty($lfWhere) ? "WHERE " . implode(" AND ", $lfWhere) : "";

$lfStmt = $pdo->prepare("
    SELECT li.*, u.Name AS PosterName, u.Email AS PosterEmail, u.UniversityVerified AS PosterVerified,
           (SELECT COUNT(*) FROM `LostItemComment` WHERE ItemID = li.ItemID) AS CommentCount
    FROM `LostItem` li
    JOIN `User` u ON li.PosterID = u.UserID
    $lfWhereSql
    ORDER BY li.created_at DESC
");
$lfStmt->execute($lfParams);
$allLostFoundList = $lfStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Control Center - BRACU Rideshare</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-header-banner {
            background: linear-gradient(135deg, #002244, #1e3a8a);
            color: #ffffff;
            border-radius: var(--radius);
            padding: 1.75rem 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px rgba(0, 34, 68, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .admin-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: rgba(255, 255, 255, 0.15);
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .admin-nav-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.75rem;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 0.5rem;
            flex-wrap: wrap;
        }
        .admin-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius-sm);
            font-weight: 700;
            font-size: 0.95rem;
            text-decoration: none;
            color: var(--text-muted);
            background: transparent;
            transition: all 0.2s ease;
        }
        .admin-tab-btn:hover {
            background: #f1f5f9;
            color: var(--text-main);
        }
        .admin-tab-btn.active {
            background: #1e40af;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(30, 64, 175, 0.25);
        }
        .admin-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .admin-stat-card {
            background: #ffffff;
            border-radius: var(--radius);
            padding: 1.4rem 1.25rem;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            text-align: center;
            transition: transform 0.2s ease;
        }
        .admin-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .admin-stat-card .val {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--primary);
            line-height: 1.1;
            margin-bottom: 0.35rem;
        }
        .admin-stat-card .lbl {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.04em;
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            margin-top: 1rem;
        }
        .admin-table th, .admin-table td {
            padding: 0.85rem 1rem;
            border: 1px solid var(--border-color);
            text-align: left;
            vertical-align: middle;
        }
        .admin-table th {
            background-color: #f8fafc;
            color: #1e293b;
            font-weight: 700;
        }
        .admin-table tr:hover {
            background-color: #f8fafc;
        }
        .badge-banned {
            background: #fee2e2;
            color: #991b1b;
            padding: 0.2rem 0.55rem;
            border-radius: 9999px;
            font-weight: 800;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border: 1px solid #fecaca;
        }
        .badge-active-user {
            background: #dcfce7;
            color: #166534;
            padding: 0.2rem 0.55rem;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 0.75rem;
        }
        .search-filter-bar {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .search-filter-bar .form-control {
            margin-bottom: 0;
        }
        .admin-policy-alert {
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            border-radius: var(--radius-sm);
            padding: 0.85rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.88rem;
            color: #1e40af;
        }
    </style>
</head>
<body>
    <?php render_navbar('admin'); ?>

    <div class="main-container">

        <!-- Top Admin Banner -->
        <div class="admin-header-banner">
            <div>
                <span class="admin-tag">🛡️ Platform Administration Mode</span>
                <h1 style="font-size: 1.85rem; font-weight: 800; margin: 0.4rem 0 0.2rem 0;">Control & Moderation Panel</h1>
                <p style="opacity: 0.9; font-size: 0.95rem; margin: 0;">Manage university users, monitor safety, moderate rides, and review community Lost & Found reports.</p>
            </div>
            <div style="text-align: right;">
                <span style="font-size: 0.85rem; opacity: 0.85;">Logged in as:</span>
                <div style="font-weight: 800; font-size: 1.1rem;"><?= htmlspecialchars($adminName) ?></div>
                <a href="logout.php" class="btn btn-secondary btn-sm" style="margin-top: 0.5rem; background: rgba(255,255,255,0.2); color: #fff; border: 1px solid rgba(255,255,255,0.3);">Log Out</a>
            </div>
        </div>

        <!-- Admin Governance Policy Banner -->
        <div class="admin-policy-alert">
            <span style="font-size: 1.25rem;">ℹ️</span>
            <div>
                <strong>Administrative Governance Policy:</strong> Platform administrators operate strictly in supervisory and moderation mode. Administrative accounts cannot create (offer) rides or request to join student carpools.
            </div>
        </div>

        <!-- Success & Error Alerts -->
        <?php if (!empty($msgSuccess)): ?>
            <div class="alert alert-success" style="margin-bottom: 1.5rem;">
                <svg style="width: 20px; height: 20px; flex-shrink: 0;" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                <span><?= htmlspecialchars($msgSuccess) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($msgError)): ?>
            <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
                <svg style="width: 20px; height: 20px; flex-shrink: 0;" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                <span><?= htmlspecialchars($msgError) ?></span>
            </div>
        <?php endif; ?>

        <!-- Nav Tabs -->
        <div class="admin-nav-tabs">
            <a href="admin.php?tab=overview" class="admin-tab-btn <?= $activeTab === 'overview' ? 'active' : '' ?>">
                📊 Analytics & Overview
            </a>
            <a href="admin.php?tab=users" class="admin-tab-btn <?= $activeTab === 'users' ? 'active' : '' ?>">
                👥 User Management & Bans (<?= $totalUsers ?>)
            </a>
            <a href="admin.php?tab=rides" class="admin-tab-btn <?= $activeTab === 'rides' ? 'active' : '' ?>">
                🚗 Ride Moderation (<?= $totalRides ?>)
            </a>
            <a href="admin.php?tab=lost_found" class="admin-tab-btn <?= $activeTab === 'lost_found' ? 'active' : '' ?>">
                🔍 Lost & Found Control (<?= $totalLostFound ?>)
            </a>
        </div>

        <!-- TAB 1: OVERVIEW & STATS -->
        <?php if ($activeTab === 'overview'): ?>
            <div class="admin-stats-grid">
                <div class="admin-stat-card">
                    <div class="val"><?= $totalUsers ?></div>
                    <div class="lbl">Total Users</div>
                </div>
                <div class="admin-stat-card">
                    <div class="val" style="color: var(--accent);"><?= $totalVerified ?></div>
                    <div class="lbl">BRACU Verified</div>
                </div>
                <div class="admin-stat-card">
                    <div class="val" style="color: <?= $totalBanned > 0 ? 'var(--danger)' : '#64748b' ?>;"><?= $totalBanned ?></div>
                    <div class="lbl">Banned Accounts</div>
                </div>
                <div class="admin-stat-card">
                    <div class="val" style="color: #0284c7;"><?= $totalDrivers ?></div>
                    <div class="lbl">Drivers</div>
                </div>
                <div class="admin-stat-card">
                    <div class="val" style="color: #475569;"><?= $totalPassengers ?></div>
                    <div class="lbl">Passengers</div>
                </div>
                <div class="admin-stat-card">
                    <div class="val" style="color: #10b981;"><?= $openRides ?></div>
                    <div class="lbl">Active Rides</div>
                </div>
                <div class="admin-stat-card">
                    <div class="val" style="color: var(--primary);"><?= $completedRides ?></div>
                    <div class="lbl">Completed Trips</div>
                </div>
                <div class="admin-stat-card">
                    <div class="val" style="color: #ef4444;"><?= $cancelledRides ?></div>
                    <div class="lbl">Cancelled Rides</div>
                </div>
                <div class="admin-stat-card">
                    <div class="val" style="color: #d97706;"><?= $openLostFound ?></div>
                    <div class="lbl">Open Lost Items</div>
                </div>
                <div class="admin-stat-card">
                    <div class="val" style="color: #059669;"><?= $resolvedLostFound ?></div>
                    <div class="lbl">Resolved Returns</div>
                </div>
                <div class="admin-stat-card">
                    <div class="val" style="color: #8b5cf6;">★ <?= number_format((float)$avgPlatformRating, 2) ?></div>
                    <div class="lbl">Avg Review Rating</div>
                </div>
            </div>

            <!-- Quick Action Hub Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-top: 1rem;">
                <div class="card">
                    <h3 style="color: #1e3a8a; font-size: 1.15rem; margin-bottom: 0.5rem;">👥 User Moderation Quick Link</h3>
                    <p style="color: var(--text-muted); font-size: 0.88rem; margin-bottom: 1rem;">View registered university members, check domain verifications, and enforce account suspensions for violators.</p>
                    <a href="admin.php?tab=users" class="btn btn-secondary btn-sm">Manage Users & Bans →</a>
                </div>

                <div class="card">
                    <h3 style="color: #1e3a8a; font-size: 1.15rem; margin-bottom: 0.5rem;">🚗 Ride Operations</h3>
                    <p style="color: var(--text-muted); font-size: 0.88rem; margin-bottom: 1rem;">Inspect live commutes across Dhaka city, forcibly end completed or inactive rides, and delete corrupted/fraudulent posts.</p>
                    <a href="admin.php?tab=rides" class="btn btn-secondary btn-sm">Manage All Rides →</a>
                </div>

                <div class="card">
                    <h3 style="color: #1e3a8a; font-size: 1.15rem; margin-bottom: 0.5rem;">🔍 Lost & Found Content Moderation</h3>
                    <p style="color: var(--text-muted); font-size: 0.88rem; margin-bottom: 1rem;">Review campus lost item reports, monitor student ownership claims, and remove spam or completed submissions.</p>
                    <a href="admin.php?tab=lost_found" class="btn btn-secondary btn-sm">Moderate Submissions →</a>
                </div>
            </div>

        <!--
             TAB 2: USER MANAGEMENT & BANS
             -->
        <?php elseif ($activeTab === 'users'): ?>
            <div class="card">
                <div class="section-header">
                    <div>
                        <h2>👥 Platform User Directory & Access Control</h2>
                        <p style="color: var(--text-muted); font-size: 0.9rem;">Inspect member profiles, university verifications, and manage account suspensions.</p>
                    </div>
                </div>

                <form method="GET" class="search-filter-bar">
                    <input type="hidden" name="tab" value="users">
                    <input type="text" name="user_q" class="form-control" placeholder="Search by name, email, or User ID..." value="<?= htmlspecialchars($userSearch) ?>" style="flex: 2; min-width: 200px;">
                    
                    <select name="user_role" class="form-control" style="flex: 1; min-width: 140px;">
                        <option value="">All Roles</option>
                        <option value="Passenger" <?= $userRoleFilter === 'Passenger' ? 'selected' : '' ?>>Passengers</option>
                        <option value="Driver" <?= $userRoleFilter === 'Driver' ? 'selected' : '' ?>>Drivers</option>
                        <option value="Admin" <?= $userRoleFilter === 'Admin' ? 'selected' : '' ?>>Admins</option>
                    </select>

                    <select name="user_status" class="form-control" style="flex: 1; min-width: 140px;">
                        <option value="">All Status</option>
                        <option value="active" <?= $userStatusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                        <option value="banned" <?= $userStatusFilter === 'banned' ? 'selected' : '' ?>>Banned Only</option>
                    </select>

                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <?php if (!empty($userSearch) || !empty($userRoleFilter) || !empty($userStatusFilter)): ?>
                        <a href="admin.php?tab=users" class="btn btn-secondary btn-sm">Reset</a>
                    <?php endif; ?>
                </form>

                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Phones</th>
                                <th>Rating</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allUsersList)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                                        No users found matching the filter criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($allUsersList as $u): ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <div class="nav-avatar" style="width: 32px; height: 32px; font-size: 0.85rem; background: <?= $u['UserType'] === 'Admin' ? '#1e3a8a' : ($u['UserType'] === 'Driver' ? '#0284c7' : '#059669') ?>;">
                                                    <?= strtoupper(substr($u['Name'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <strong><?= htmlspecialchars($u['Name']) ?></strong>
                                                    <div style="font-size: 0.75rem; color: var(--text-muted);">ID: #<?= $u['UserID'] ?> · <?= htmlspecialchars($u['Gender']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div><?= htmlspecialchars($u['Email']) ?></div>
                                            <?= render_verification_badge($u['UniversityVerified']) ?>
                                        </td>
                                        <td>
                                            <span class="meta-chip" style="font-size: 0.75rem;">
                                                <?= htmlspecialchars($u['UserType']) ?>
                                            </span>
                                        </td>
                                        <td style="font-size: 0.82rem; color: #475569;">
                                            <?= htmlspecialchars($u['Phones']) ?>
                                        </td>
                                        <td>
                                            <span class="rating-badge" style="font-size: 0.8rem;">★ <?= number_format($u['RatingAverage'], 1) ?></span>
                                            <small style="color: var(--text-muted);">(<?= $u['RatingCount'] ?>)</small>
                                        </td>
                                        <td>
                                            <?php if (!empty($u['IsBanned'])): ?>
                                                <span class="badge-banned">🚫 BANNED</span>
                                            <?php else: ?>
                                                <span class="badge-active-user">✓ Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.4rem; align-items: center;">
                                                <a href="profile.php?id=<?= $u['UserID'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;">
                                                    Profile
                                                </a>
                                                
                                                <?php if ($u['UserType'] === 'Admin'): ?>
                                                    <span style="font-size: 0.75rem; color: #64748b; font-weight: 700; padding: 0.35rem;">Protected</span>
                                                <?php elseif (!empty($u['IsBanned'])): ?>
                                                    <form method="POST" action="api_actions.php" onsubmit="return confirm('Lift ban and restore account for <?= htmlspecialchars(addslashes($u['Name'])) ?>?');" style="margin: 0;">
                                                        <input type="hidden" name="action" value="admin_unban_user">
                                                        <input type="hidden" name="user_id" value="<?= $u['UserID'] ?>">
                                                        <button type="submit" class="btn btn-success btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem; background: #15803d; border-color: #15803d;">
                                                            ✓ Unban
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" action="api_actions.php" onsubmit="return confirm('Are you sure you want to BAN <?= htmlspecialchars(addslashes($u['Name'])) ?> (#<?= $u['UserID'] ?>)? This will prevent them from logging in.');" style="margin: 0;">
                                                        <input type="hidden" name="action" value="admin_ban_user">
                                                        <input type="hidden" name="user_id" value="<?= $u['UserID'] ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;">
                                                            🚫 Ban User
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- 
             TAB 3: RIDE MANAGEMENT (END & DELETE RIDES)
            -->
        <?php elseif ($activeTab === 'rides'): ?>
            <div class="card">
                <div class="section-header">
                    <div>
                        <h2>🚗 Platform Ride Moderation & Controls</h2>
                        <p style="color: var(--text-muted); font-size: 0.9rem;">Oversee all active and past rides. Administrators can force-end active trips or permanently delete rides.</p>
                    </div>
                </div>

                <form method="GET" class="search-filter-bar">
                    <input type="hidden" name="tab" value="rides">
                    <input type="text" name="ride_q" class="form-control" placeholder="Search by route, driver name, or Ride ID..." value="<?= htmlspecialchars($rideSearch) ?>" style="flex: 2; min-width: 200px;">
                    
                    <select name="ride_status" class="form-control" style="flex: 1; min-width: 140px;">
                        <option value="">All Status</option>
                        <option value="Open" <?= $rideStatusFilter === 'Open' ? 'selected' : '' ?>>Open</option>
                        <option value="Full" <?= $rideStatusFilter === 'Full' ? 'selected' : '' ?>>Full</option>
                        <option value="Completed" <?= $rideStatusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Cancelled" <?= $rideStatusFilter === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>

                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <?php if (!empty($rideSearch) || !empty($rideStatusFilter)): ?>
                        <a href="admin.php?tab=rides" class="btn btn-secondary btn-sm">Reset</a>
                    <?php endif; ?>
                </form>

                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Ride ID</th>
                                <th>Driver</th>
                                <th>Route</th>
                                <th>Schedule</th>
                                <th>Seats & Cost</th>
                                <th>Status</th>
                                <th>Admin Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allRidesList)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                                        No rides found matching search criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($allRidesList as $r): ?>
                                    <tr>
                                        <td>
                                            <strong>#<?= $r['RideID'] ?></strong>
                                            <?php if (!empty($r['IsWomenOnly'])): ?>
                                                <div><span style="font-size: 0.72rem; color: #db2777; font-weight: 800;">🌸 Women-Only</span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($r['DriverName']) ?></strong>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($r['DriverEmail']) ?></div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 700; color: var(--primary);">
                                                <?= htmlspecialchars($r['StartLocation']) ?> → <?= htmlspecialchars($r['Destination']) ?>
                                            </div>
                                            <?php if (!empty($r['VehicleInfo'])): ?>
                                                <div style="font-size: 0.78rem; color: var(--text-muted);"><?= htmlspecialchars($r['VehicleInfo']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.85rem;">
                                            <div><strong><?= date('M j, Y', strtotime($r['RideDate'])) ?></strong></div>
                                            <div style="color: var(--text-muted);"><?= format_time_12h($r['DepartureTime']) ?></div>
                                        </td>
                                        <td style="font-size: 0.85rem;">
                                            <div><strong><?= $r['AvailableSeats'] ?> / <?= $r['TotalSeats'] ?> seats</strong></div>
                                            <div style="color: #059669; font-weight: 700;">৳ <?= number_format($r['SharedCost'], 2) ?></div>
                                        </td>
                                        <td>
                                            <?php if ($r['Status'] === 'Completed'): ?>
                                                <span class="status-badge" style="background: #dcfce7; color: #166534;">Completed</span>
                                            <?php elseif ($r['Status'] === 'Open'): ?>
                                                <span class="status-badge" style="background: #e0f2fe; color: #0369a1;">Open</span>
                                            <?php elseif ($r['Status'] === 'Full'): ?>
                                                <span class="status-badge" style="background: #fef3c7; color: #92400e;">Full</span>
                                            <?php elseif ($r['Status'] === 'Cancelled'): ?>
                                                <span class="status-badge" style="background: #fee2e2; color: #991b1b;">Cancelled</span>
                                            <?php else: ?>
                                                <span class="status-badge"><?= htmlspecialchars($r['Status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                                <a href="ride_details.php?id=<?= $r['RideID'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;">
                                                    View
                                                </a>

                                                <?php if ($r['Status'] !== 'Completed' && $r['Status'] !== 'Cancelled'): ?>
                                                    <form method="POST" action="api_actions.php" onsubmit="return confirm('Admin: Mark Ride #<?= $r['RideID'] ?> as Completed/Ended?');" style="margin: 0;">
                                                        <input type="hidden" name="action" value="admin_end_ride">
                                                        <input type="hidden" name="ride_id" value="<?= $r['RideID'] ?>">
                                                        <input type="hidden" name="redirect" value="admin.php?tab=rides">
                                                        <button type="submit" class="btn btn-warning btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem; background: #f59e0b; color: #fff; border: none;" title="Force end this ride">
                                                            🏁 End
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <form method="POST" action="api_actions.php" onsubmit="return confirm('Admin: Permanently DELETE Ride #<?= $r['RideID'] ?>? All bookings, participants, and chat logs will be removed.');" style="margin: 0;">
                                                    <input type="hidden" name="action" value="admin_delete_ride">
                                                    <input type="hidden" name="ride_id" value="<?= $r['RideID'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;" title="Delete ride">
                                                        🗑️ Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!--
             TAB 4: LOST & FOUND MODERATION
            -->
        <?php elseif ($activeTab === 'lost_found'): ?>
            <div class="card">
                <div class="section-header">
                    <div>
                        <h2>🔍 Lost & Found Submission Moderation</h2>
                        <p style="color: var(--text-muted); font-size: 0.9rem;">Review reported items, check discussion threads, and remove fraudulent or resolved submissions.</p>
                    </div>
                </div>

                <form method="GET" class="search-filter-bar">
                    <input type="hidden" name="tab" value="lost_found">
                    <input type="text" name="lf_q" class="form-control" placeholder="Search item name, description, reporter..." value="<?= htmlspecialchars($lfSearch) ?>" style="flex: 2; min-width: 200px;">
                    
                    <select name="lf_type" class="form-control" style="flex: 1; min-width: 130px;">
                        <option value="">All Types</option>
                        <option value="Lost" <?= $lfTypeFilter === 'Lost' ? 'selected' : '' ?>>Lost Only</option>
                        <option value="Found" <?= $lfTypeFilter === 'Found' ? 'selected' : '' ?>>Found Only</option>
                    </select>

                    <select name="lf_status" class="form-control" style="flex: 1; min-width: 130px;">
                        <option value="">All Status</option>
                        <option value="Open" <?= $lfStatusFilter === 'Open' ? 'selected' : '' ?>>Open</option>
                        <option value="Claimed" <?= $lfStatusFilter === 'Claimed' ? 'selected' : '' ?>>Claimed</option>
                        <option value="Resolved" <?= $lfStatusFilter === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                    </select>

                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <?php if (!empty($lfSearch) || !empty($lfTypeFilter) || !empty($lfStatusFilter)): ?>
                        <a href="admin.php?tab=lost_found" class="btn btn-secondary btn-sm">Reset</a>
                    <?php endif; ?>
                </form>

                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Type & Category</th>
                                <th>Reported By</th>
                                <th>Location & Date</th>
                                <th>Status</th>
                                <th>Admin Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allLostFoundList)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                                        No Lost & Found reports match the criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($allLostFoundList as $item): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($item['ItemName']) ?></strong>
                                            <div style="font-size: 0.8rem; color: #475569; max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                <?= htmlspecialchars($item['Description']) ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">
                                                💬 <?= $item['CommentCount'] ?> messages / claims
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($item['ReportType'] === 'Found'): ?>
                                                <span style="background: #dcfce7; color: #166534; font-weight: 800; font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 4px;">🟢 FOUND</span>
                                            <?php else: ?>
                                                <span style="background: #fee2e2; color: #991b1b; font-weight: 800; font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 4px;">🔴 LOST</span>
                                            <?php endif; ?>
                                            <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 0.25rem;">
                                                <?= htmlspecialchars($item['Category']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($item['PosterName']) ?></strong>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($item['PosterEmail']) ?></div>
                                            <?= render_verification_badge($item['PosterVerified']) ?>
                                        </td>
                                        <td style="font-size: 0.82rem;">
                                            <div>📍 <?= htmlspecialchars($item['LocationDetails']) ?></div>
                                            <div style="color: var(--text-muted);"><?= date('M j, Y', strtotime($item['DateLostFound'])) ?></div>
                                        </td>
                                        <td>
                                            <?php if ($item['Status'] === 'Resolved'): ?>
                                                <span class="status-badge" style="background: #dcfce7; color: #166534;">Resolved</span>
                                            <?php elseif ($item['Status'] === 'Claimed'): ?>
                                                <span class="status-badge" style="background: #fef3c7; color: #92400e;">Claimed</span>
                                            <?php else: ?>
                                                <span class="status-badge" style="background: #e0f2fe; color: #0369a1;">Open</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.4rem;">
                                                <a href="lost_found.php?item_id=<?= $item['ItemID'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;">
                                                    View
                                                </a>
                                                <form method="POST" action="api_actions.php" onsubmit="return confirm('Admin: Permanently remove report for \'<?= htmlspecialchars(addslashes($item['ItemName'])) ?>\'?');" style="margin: 0;">
                                                    <input type="hidden" name="action" value="admin_delete_lost_item">
                                                    <input type="hidden" name="item_id" value="<?= $item['ItemID'] ?>">
                                                    <input type="hidden" name="redirect" value="admin.php?tab=lost_found">
                                                    <button type="submit" class="btn btn-danger btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;">
                                                        🗑️ Remove
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <?php render_footer(); ?>
</body>
</html>
