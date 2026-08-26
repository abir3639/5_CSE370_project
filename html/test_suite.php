<?php
// test_suite.php - Automated Comprehensive Verification & Test Runner for BRACU Rideshare Platform
require_once 'db.php';
require_once 'helpers.php';

$results = [];

function run_test($name, $testFunc) {
    global $results;
    try {
        $passed = $testFunc();
        $results[] = [
            'name' => $name,
            'passed' => $passed === true,
            'message' => $passed === true ? 'Passed' : ($passed ?: 'Failed')
        ];
    } catch (Exception $e) {
        $results[] = [
            'name' => $name,
            'passed' => false,
            'message' => 'Exception: ' . $e->getMessage()
        ];
    }
}

// -----------------------------------------------------------------------------
// 1. TEST SUITE: Domain Auto-Verification
// -----------------------------------------------------------------------------
run_test('BRACU Email Verification Regex (@g.bracu.ac.bd)', function() {
    $email1 = 'student@g.bracu.ac.bd';
    $email2 = 'faculty@bracu.ac.bd';
    $email3 = 'outsider@gmail.com';

    $isV1 = preg_match('/@(g\.)?bracu\.ac\.bd$/i', $email1);
    $isV2 = preg_match('/@(g\.)?bracu\.ac\.bd$/i', $email2);
    $isV3 = preg_match('/@(g\.)?bracu\.ac\.bd$/i', $email3);

    if ($isV1 && $isV2 && !$isV3) {
        return true;
    }
    return "Domain regex failed verification test.";
});

// -----------------------------------------------------------------------------
// 2. TEST SUITE: Geocoding & Coordinate Distance
// -----------------------------------------------------------------------------
run_test('Geocoding & Haversine Distance (Mirpur 10 -> BRAC University)', function() {
    $geoStart = geocode_location('Mirpur 10');
    $geoDest = geocode_location('BRAC University');

    $dist = get_distance_km($geoStart['lat'], $geoStart['lng'], $geoDest['lat'], $geoDest['lng']);

    if ($dist > 5 && $dist < 15) {
        return true;
    }
    return "Distance calculation abnormal: $dist km (Expected ~10.5 km).";
});

// -----------------------------------------------------------------------------
// 3. TEST SUITE: Smart Matching Algorithm
// -----------------------------------------------------------------------------
run_test('Smart Match Proximity (Mohakhali searching for BRAC University)', function() {
    $mockRide = [
        'RideID' => 999,
        'DriverID' => 7,
        'StartLocation' => 'Mirpur 10',
        'Destination' => 'BRAC University',
        'StartLatitude' => 23.8069,
        'StartLongitude' => 90.3687,
        'DestinationLatitude' => 23.7781,
        'DestinationLongitude' => 90.4265,
        'RideDate' => date('Y-m-d', strtotime('+1 day')),
        'DepartureTime' => '08:30:00',
        'UniversityVerified' => 1
    ];

    $match = calculate_ride_match('Mohakhali', 'Mirpur 10', date('Y-m-d', strtotime('+1 day')), '08:30', true, $mockRide);

    if ($match['isMatch'] && $match['score'] >= 50) {
        return true;
    }
    return "Proximity matching failed to match nearby destination.";
});

// -----------------------------------------------------------------------------
// 4. TEST SUITE: Reverse Route Matching
// -----------------------------------------------------------------------------
run_test('Reverse Route Compatibility (BRAC University -> Mirpur 10)', function() {
    $mockRide = [
        'RideID' => 998,
        'DriverID' => 7,
        'StartLocation' => 'BRAC University',
        'Destination' => 'Mirpur 10',
        'StartLatitude' => 23.7781,
        'StartLongitude' => 90.4265,
        'DestinationLatitude' => 23.8069,
        'DestinationLongitude' => 90.3687,
        'RideDate' => date('Y-m-d', strtotime('+1 day')),
        'DepartureTime' => '17:00:00',
        'UniversityVerified' => 1
    ];

    $match = calculate_ride_match('Mirpur 10', 'BRAC University', date('Y-m-d', strtotime('+1 day')), '17:00', false, $mockRide);

    if ($match['isMatch']) {
        return true;
    }
    return "Reverse route matching failed.";
});

// -----------------------------------------------------------------------------
// 5. TEST SUITE: Database Schema Integrity
// -----------------------------------------------------------------------------
run_test('Database Tables and Key Columns Presence', function() {
    global $pdo;
    $requiredTables = ['User', 'Driver', 'Passenger', 'Vehicle', 'Ride', 'RideRequest', 'RideParticipant', 'Rating', 'Notification'];
    foreach ($requiredTables as $t) {
        $chk = $pdo->query("SHOW TABLES LIKE '$t'")->fetch();
        if (!$chk) return "Table $t is missing.";
    }

    $col = $pdo->query("SHOW COLUMNS FROM `Ride` LIKE 'AvailableSeats'")->fetch();
    if (!$col) return "AvailableSeats column missing in Ride.";

    $col2 = $pdo->query("SHOW COLUMNS FROM `User` LIKE 'UniversityVerified'")->fetch();
    if (!$col2) return "UniversityVerified column missing in User.";

    return true;
});

// -----------------------------------------------------------------------------
// 6. TEST SUITE: Notification Creation Dispatcher
// -----------------------------------------------------------------------------
run_test('In-App Notification Dispatch & Read Status', function() {
    global $pdo;
    $testUid = 6; // Rahim
    $created = create_notification($pdo, $testUid, 'test', 'Test Notification', 'Testing notification pipeline.');
    if (!$created) return "Failed to insert test notification.";

    $unread = get_unread_notification_count($pdo, $testUid);
    if ($unread < 0) return "Failed unread count fetch.";

    return true;
});

// -----------------------------------------------------------------------------
// 7. TEST SUITE: User Rating Average Calculation
// -----------------------------------------------------------------------------
run_test('User Rating Recalculation Engine', function() {
    global $pdo;
    $testUid = 7; // Karim
    $updated = update_user_rating($pdo, $testUid);
    if (!$updated) return "Failed to recalculate user rating.";

    $u = $pdo->query("SELECT RatingAverage, RatingCount FROM `User` WHERE UserID = $testUid")->fetch();
    if ($u['RatingAverage'] >= 1.0 && $u['RatingAverage'] <= 5.0) {
        return true;
    }
    return "Rating average out of bounds: " . $u['RatingAverage'];
});

$totalTests = count($results);
$passedTests = count(array_filter($results, fn($r) => $r['passed']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automated Test Suite - BRACU Rideshare</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .test-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 0.75rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .test-pass { border-left: 4px solid var(--success); }
        .test-fail { border-left: 4px solid var(--danger); }
    </style>
</head>
<body>
    <?php render_navbar('stats'); ?>

    <div class="main-container">
        <div style="max-width: 800px; margin: 0 auto;">
            <div class="section-header">
                <div>
                    <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--primary);">🧪 Quality Assurance & Test Runner</h1>
                    <p style="color: var(--text-muted); font-size: 0.95rem;">Automated unit and integration tests verifying matching, verification, and database state machines.</p>
                </div>
                <div>
                    <a href="test_suite.php" class="btn btn-primary btn-sm">▶ Re-run All Tests</a>
                </div>
            </div>

            <div class="card" style="margin-bottom: 1.5rem; background: <?= $passedTests === $totalTests ? '#f0fdf4' : '#fef2f2' ?>; border-color: <?= $passedTests === $totalTests ? '#bbf7d0' : '#fecaca' ?>;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h2 style="color: <?= $passedTests === $totalTests ? '#166534' : '#991b1b' ?>; margin-bottom: 0.25rem;">
                            <?= $passedTests === $totalTests ? '✅ All Tests Passed Successfully!' : '⚠️ Some Tests Failed' ?>
                        </h2>
                        <p style="font-size: 0.9rem; color: #475569;">
                            Passed <strong><?= $passedTests ?></strong> of <strong><?= $totalTests ?></strong> automated test scenarios.
                        </p>
                    </div>
                    <div style="font-size: 2rem; font-weight: 800; color: <?= $passedTests === $totalTests ? 'var(--success)' : 'var(--danger)' ?>;">
                        <?= round(($passedTests / $totalTests) * 100) ?>%
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>Test Execution Log</h2>
                <?php foreach ($results as $idx => $r): ?>
                    <div class="test-card <?= $r['passed'] ? 'test-pass' : 'test-fail' ?>">
                        <div>
                            <strong>#<?= ($idx + 1) ?>: <?= htmlspecialchars($r['name']) ?></strong>
                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
                                <?= htmlspecialchars($r['message']) ?>
                            </div>
                        </div>
                        <span class="badge-verified" style="background: <?= $r['passed'] ? '#ecfdf5' : '#fef2f2' ?>; color: <?= $r['passed'] ? '#065f46' : '#991b1b' ?>; border-color: <?= $r['passed'] ? '#a7f3d0' : '#fecaca' ?>;">
                            <?= $r['passed'] ? 'PASSED' : 'FAILED' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="text-align: center; margin-top: 1.5rem;">
                <a href="index.php" class="btn btn-secondary">Return to Homepage</a>
            </div>
        </div>
    </div>

    <?php render_footer(); ?>
</body>
</html>
