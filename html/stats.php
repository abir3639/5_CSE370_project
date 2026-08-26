<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

try {
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM `User`")->fetchColumn();
    $totalVerified = $pdo->query("SELECT COUNT(*) FROM `User` WHERE UniversityVerified = 1")->fetchColumn();
    $totalDrivers = $pdo->query("SELECT COUNT(*) FROM `Driver`")->fetchColumn();
    $totalRides = $pdo->query("SELECT COUNT(*) FROM `Ride`")->fetchColumn();
    $completedRides = $pdo->query("SELECT COUNT(*) FROM `Ride` WHERE Status = 'Completed'")->fetchColumn();
    $totalRatings = $pdo->query("SELECT COUNT(*) FROM `Rating`")->fetchColumn();

    $stmt = $pdo->query("
        SELECT u.UserID, u.Name, u.Email, u.Gender, u.Age, u.UserType, u.UniversityVerified, u.RatingAverage, u.RatingCount,
               IFNULL(GROUP_CONCAT(up.Phone SEPARATOR ', '), 'Private') AS Phones 
        FROM `User` u 
        LEFT JOIN `User_Phone` up ON u.UserID = up.UserID 
        GROUP BY u.UserID, u.Name, u.Email, u.Gender, u.Age, u.UserType, u.UniversityVerified, u.RatingAverage, u.RatingCount
        ORDER BY u.UserID ASC
    ");
    $usersList = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Metrics & Directory - BRACU Rideshare</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: #ffffff;
            border-radius: var(--radius);
            padding: 1.5rem;
            text-align: center;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        .stat-card .value {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }
        .stat-card .label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
        }
        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.9rem;
        }
        .user-table th, .user-table td {
            padding: 0.85rem 1rem;
            border: 1px solid var(--border-color);
            text-align: left;
        }
        .user-table th {
            background-color: #f8fafc;
            color: var(--text-main);
            font-weight: 700;
        }
        .user-table tr:hover {
            background-color: #f8fafc;
        }
    </style>
</head>
<body>
    <?php render_navbar('stats'); ?>

    <div class="main-container">
        
        <div class="section-header">
            <div>
                <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--primary);">Platform Community & Statistics</h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">Live university rideshare metrics and verified student community directory.</p>
            </div>
            <div>
                <a href="test_suite.php" class="btn btn-secondary btn-sm">🧪 Run Automated Tests</a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="value"><?= $totalUsers ?></div>
                <div class="label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="value" style="color: var(--accent);"><?= $totalVerified ?></div>
                <div class="label">BRACU Verified</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= $totalDrivers ?></div>
                <div class="label">Registered Drivers</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= $totalRides ?></div>
                <div class="label">Total Rides Created</div>
            </div>
            <div class="stat-card">
                <div class="value" style="color: var(--success);"><?= $completedRides ?></div>
                <div class="label">Completed Trips</div>
            </div>
            <div class="stat-card">
                <div class="value" style="color: #d97706;"><?= $totalRatings ?></div>
                <div class="label">Peer Reviews</div>
            </div>
        </div>

        <div class="card">
            <h2>👥 University Member Directory</h2>
            <div style="overflow-x: auto;">
                <table class="user-table">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Name & Verification</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Rating</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usersList as $u): ?>
                            <tr>
                                <td>#<?= htmlspecialchars($u['UserID']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($u['Name']) ?></strong>
                                    <?= render_verification_badge($u['UniversityVerified']) ?>
                                </td>
                                <td><?= htmlspecialchars($u['Email']) ?></td>
                                <td>
                                    <span class="meta-chip">
                                        <?= htmlspecialchars($u['UserType']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="rating-badge">★ <?= number_format($u['RatingAverage'], 1) ?></span> 
                                    <small style="color: var(--text-muted);">(<?= $u['RatingCount'] ?>)</small>
                                </td>
                                <td>
                                    <a href="profile.php?id=<?= $u['UserID'] ?>" class="btn btn-secondary btn-sm">View Profile</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <?php render_footer(); ?>
</body>
</html>
