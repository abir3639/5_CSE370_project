<?php
require_once 'db.php';
require_once 'helpers.php';

$message = '';
$sampleDataAdded = false;

if (isset($_POST['insert_seed_data'])) {
    try {
        $pdo->beginTransaction();

        // Safe truncation
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $tablesToTruncate = ['Notification', 'Rating', 'RideParticipant', 'RideRequest', 'Ride', 'FavoriteLocation', 'Vehicle', 'Driver', 'Passenger', 'User_Phone', 'User', 'Admin'];
        foreach ($tablesToTruncate as $tbl) {
            $pdo->exec("TRUNCATE TABLE `$tbl`");
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        $pdo->exec("INSERT IGNORE INTO `Admin` (`AdminID`, `Email`, `Role`) VALUES (1, 'admin@rideshare.com', 'SuperAdmin')");

        $defaultPassword = password_hash('password123', PASSWORD_DEFAULT);
        
        $pdo->prepare("INSERT INTO `User` (`Name`, `Email`, `Password`, `Gender`, `Age`, `UserType`, `AdminID`, `UniversityVerified`) VALUES ('Admin User', 'admin@rideshare.com', ?, 'Other', 30, 'Admin', 1, 0)")
            ->execute([$defaultPassword]);

        $users = [
            [1, 'Alice Johnson', 'alice@example.com', $defaultPassword, 'Female', 24, 'Passenger', 1, 0, 4.85, 2],
            [2, 'Bob Smith', 'bob@example.com', $defaultPassword, 'Male', 32, 'Driver', 1, 0, 4.90, 3],
            [3, 'Carol Williams', 'carol@example.com', $defaultPassword, 'Female', 29, 'Passenger', 1, 0, 4.90, 1],
            [4, 'David Miller', 'david@example.com', $defaultPassword, 'Male', 40, 'Driver', 1, 0, 5.00, 1],
            [6, 'Rahim Ahmed', 'rahim@g.bracu.ac.bd', $defaultPassword, 'Male', 21, 'Passenger', 1, 1, 4.80, 2],
            [7, 'Karim Islam', 'karim@g.bracu.ac.bd', $defaultPassword, 'Male', 23, 'Driver', 1, 1, 4.85, 4],
            [8, 'Tanvir Hasan', 'tanvir.hasan@g.bracu.ac.bd', $defaultPassword, 'Male', 22, 'Driver', 1, 1, 5.00, 2],
            [9, 'Nusrat Jahan', 'nusrat.jahan@bracu.ac.bd', $defaultPassword, 'Female', 21, 'Passenger', 1, 1, 4.95, 3]
        ];

        $stmt = $pdo->prepare("INSERT INTO `User` (`UserID`, `Name`, `Email`, `Password`, `Gender`, `Age`, `UserType`, `AdminID`, `UniversityVerified`, `RatingAverage`, `RatingCount`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($users as $u) {
            $stmt->execute($u);
        }

        $phones = [
            [1, '+1-555-0101'],
            [2, '+1-555-0201'],
            [3, '+1-555-0301'],
            [4, '+1-555-0401'],
            [6, '+880-1711-223344'],
            [7, '+880-1811-556677'],
            [8, '+880-1911-889900'],
            [9, '+880-1611-334455']
        ];
        $stmt = $pdo->prepare("INSERT INTO `User_Phone` (`UserID`, `Phone`) VALUES (?, ?)");
        foreach ($phones as $p) {
            $stmt->execute($p);
        }

        $passengers = [
            [1, 4.85],
            [3, 4.90],
            [6, 4.80],
            [9, 4.95]
        ];
        $stmt = $pdo->prepare("INSERT INTO `Passenger` (`UserID`, `PassRating`) VALUES (?, ?)");
        foreach ($passengers as $pass) {
            $stmt->execute($pass);
        }

        $drivers = [
            [2, 'DL-NY-98765432'],
            [4, 'DL-CA-12345678'],
            [7, 'DL-BD-55667788'],
            [8, 'DL-BD-99001122']
        ];
        $stmt = $pdo->prepare("INSERT INTO `Driver` (`UserID`, `LicenseNo`) VALUES (?, ?)");
        foreach ($drivers as $d) {
            $stmt->execute($d);
        }

        $vehicles = [
            ['ABC-1234', 'Toyota Camry 2022', 2],
            ['XYZ-5678', 'Honda Accord 2021', 4],
            ['DHA-9988', 'Toyota Axio 2018', 7],
            ['DHA-5544', 'Honda Grace Hybrid', 8]
        ];
        $stmt = $pdo->prepare("INSERT INTO `Vehicle` (`RegNo`, `Model`, `UserID`) VALUES (?, ?, ?)");
        foreach ($vehicles as $v) {
            $stmt->execute($v);
        }

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $rideStmt = $pdo->prepare("INSERT INTO `Ride` (`RideID`, `DriverID`, `StartLocation`, `Destination`, `StartLatitude`, `StartLongitude`, `DestinationLatitude`, `DestinationLongitude`, `RideDate`, `DepartureTime`, `AvailableSeats`, `TotalSeats`, `VehicleInfo`, `SharedCost`, `Notes`, `Status`, `Distance`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        // 1. Mirpur 10 -> BRAC University (Tomorrow 8:15 AM)
        $rideStmt->execute([1, 7, 'Mirpur 10', 'BRAC University', 23.8069, 90.3687, 23.7781, 90.4265, $tomorrow, '08:15:00', 2, 4, 'Toyota Axio (DHA-9988)', 120.00, 'Leaving from Mirpur 10 roundabout. AC is on.', 'Open', 10.5]);
        
        // 2. Uttara -> BRAC University (Tomorrow 8:00 AM)
        $rideStmt->execute([2, 8, 'Uttara', 'BRAC University', 23.8759, 90.3795, 23.7781, 90.4265, $tomorrow, '08:00:00', 3, 4, 'Honda Grace Hybrid (DHA-5544)', 150.00, 'Starting from Sector 3. Route via Airport Road & Pragati Sarani.', 'Open', 14.2]);
        
        // 3. Dhanmondi -> BRAC University (Tomorrow 8:30 AM)
        $rideStmt->execute([3, 2, 'Dhanmondi', 'BRAC University', 23.7465, 90.3760, 23.7781, 90.4265, $tomorrow, '08:30:00', 3, 4, 'Toyota Camry 2022 (ABC-1234)', 130.00, 'Pickup near Star Kabab Dhanmondi 27.', 'Open', 9.8]);
        
        // 4. REVERSE ROUTE: BRAC University -> Mirpur 10 (Tomorrow 5:00 PM)
        $rideStmt->execute([4, 7, 'BRAC University', 'Mirpur 10', 23.7781, 90.4265, 23.8069, 90.3687, $tomorrow, '17:00:00', 3, 4, 'Toyota Axio (DHA-9988)', 120.00, 'Returning home after 5 PM class. Drops off at Kazipara, Shewrapara & Mirpur 10.', 'Open', 10.5]);

        // 5. REVERSE ROUTE: BRAC University -> Uttara (Tomorrow 5:30 PM)
        $rideStmt->execute([5, 8, 'BRAC University', 'Uttara', 23.7781, 90.4265, 23.8759, 90.3795, $tomorrow, '17:30:00', 4, 4, 'Honda Grace Hybrid (DHA-5544)', 150.00, 'Evening return to Uttara via Kuril.', 'Open', 14.2]);

        // 6. COMPLETED RIDE: BRAC University -> Mirpur 10 (Yesterday)
        $rideStmt->execute([6, 7, 'BRAC University', 'Mirpur 10', 23.7781, 90.4265, 23.8069, 90.3687, $yesterday, '17:30:00', 0, 4, 'Toyota Axio (DHA-9988)', 120.00, 'Completed commute.', 'Completed', 10.5]);

        // Participants for Completed Ride
        $partStmt = $pdo->prepare("INSERT INTO `RideParticipant` (`RideID`, `UserID`, `Role`, `ArrivalStatus`) VALUES (?, ?, ?, ?)");
        $partStmt->execute([6, 7, 'Driver', 'Reached']);
        $partStmt->execute([6, 6, 'Passenger', 'Reached']);
        $partStmt->execute([6, 9, 'Passenger', 'Reached']);

        // Ratings for Completed Ride
        $rateStmt = $pdo->prepare("INSERT INTO `Rating` (`RatingID`, `RideID`, `ReviewerID`, `RecipientID`, `Rating`, `Review`) VALUES (?, ?, ?, ?, ?, ?)");
        $rateStmt->execute([1, 6, 6, 7, 5, 'Very punctual driver and safe driving throughout the commute!']);
        $rateStmt->execute([2, 6, 7, 6, 5, 'Great passenger, on time at pickup spot.']);
        $rateStmt->execute([3, 6, 9, 7, 5, 'Smooth ride back home from BRACU. Highly recommended!']);

        // Pending Request for Ride 1 (Alice requesting to join Karim)
        $reqStmt = $pdo->prepare("INSERT INTO `RideRequest` (`RequestID`, `RideID`, `PassengerID`, `Status`, `RequestedAt`) VALUES (?, ?, ?, ?, ?)");
        $reqStmt->execute([1, 1, 1, 'Pending', date('Y-m-d H:i:s')]);

        // Notification for Karim (Driver)
        $notifStmt = $pdo->prepare("INSERT INTO `Notification` (`NotificationID`, `UserID`, `Type`, `Title`, `Message`, `IsRead`) VALUES (?, ?, ?, ?, ?, ?)");
        $notifStmt->execute([1, 7, 'request', 'New Join Request 📬', 'Alice Johnson has requested to join your ride from Mirpur 10 to BRAC University.', 0]);
        $notifStmt->execute([2, 6, 'accepted', 'Request Accepted! 🎉', 'Karim Islam accepted your ride request for yesterday commute.', 1]);

        $pdo->commit();
        $message = "Sample seed data reloaded successfully! Dhaka routes, reverse directions, and ratings populated.";
        $sampleDataAdded = true;
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error inserting sample data: " . $e->getMessage();
    }
}

$tableStats = [];
$tableNames = [
    'Admin', 'User', 'User_Phone', 'Passenger', 'Driver', 
    'Vehicle', 'FavoriteLocation', 'Ride', 'RideRequest', 
    'RideParticipant', 'Rating', 'Notification'
];

foreach ($tableNames as $tbl) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
        $tableStats[$tbl] = [
            'status' => 'Active',
            'count' => $count
        ];
    } catch (Exception $e) {
        $tableStats[$tbl] = [
            'status' => 'Missing / Error: ' . $e->getMessage(),
            'count' => 0
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup & Verification - rideshare_db</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .table-grid {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        .table-grid th, .table-grid td {
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            text-align: left;
        }
        .table-grid th {
            background-color: #f8fafc;
            font-weight: 700;
        }
        .status-badge {
            background-color: #dcfce7;
            color: #166534;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .demo-box {
            background-color: #eff6ff;
            border: 1.5px solid #bfdbfe;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }
        .credential-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.75rem;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
        }
        .credential-table th, .credential-table td {
            padding: 0.65rem 0.85rem;
            border: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }
        .credential-table th {
            background: #f1f5f9;
        }
    </style>
</head>
<body>
    <?php render_navbar('stats'); ?>

    <div class="main-container">
        
        <div class="section-header">
            <div>
                <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--primary);">Database Schema & Verification</h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">Verify MySQL tables and reload realistic sample student rides and test accounts.</p>
            </div>
            <div>
                <form method="POST">
                    <button type="submit" name="insert_seed_data" class="btn btn-primary" onclick="return confirm('Reload seed data?');">
                        🔄 Reload Seed Data
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert <?= $sampleDataAdded ? 'alert-success' : 'alert-danger' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>📊 Database Tables & Record Counts</h2>
            <table class="table-grid">
                <thead>
                    <tr>
                        <th>Table Name</th>
                        <th>Status</th>
                        <th>Record Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tableStats as $tbl => $info): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($tbl) ?></strong></td>
                            <td><span class="status-badge"><?= htmlspecialchars($info['status']) ?></span></td>
                            <td><strong><?= $info['count'] ?></strong> records</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="demo-box">
            <h3 style="color: #1e40af; font-size: 1.2rem; font-weight: 800;">🔑 Test User Credentials</h3>
            <p style="color: #3b82f6; font-size: 0.9rem; margin-top: 0.25rem;">
                All test users share the password: <code>password123</code>.
            </p>

            <table class="credential-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Verification</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Rahim Ahmed</strong></td>
                        <td><code>rahim@g.bracu.ac.bd</code></td>
                        <td>Passenger</td>
                        <td><?= render_verification_badge(1) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Karim Islam</strong></td>
                        <td><code>karim@g.bracu.ac.bd</code></td>
                        <td>Driver</td>
                        <td><?= render_verification_badge(1) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Tanvir Hasan</strong></td>
                        <td><code>tanvir.hasan@g.bracu.ac.bd</code></td>
                        <td>Driver</td>
                        <td><?= render_verification_badge(1) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Alice Johnson</strong></td>
                        <td><code>alice@example.com</code></td>
                        <td>Passenger</td>
                        <td><?= render_verification_badge(0) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Admin User</strong></td>
                        <td><code>admin@rideshare.com</code></td>
                        <td>Admin</td>
                        <td><?= render_verification_badge(0) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div>

    <?php render_footer(); ?>
</body>
</html>
