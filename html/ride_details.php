<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

$rideId = (int)($_GET['id'] ?? 0);
if ($rideId <= 0) {
    header('Location: index.php');
    exit;
}

$currentUserId = $_SESSION['user_id'] ?? null;
$isLoggedIn = isset($_SESSION['user_id']);
$currentUserRole = $_SESSION['user_type'] ?? 'Passenger';
$isAdmin = ($isLoggedIn && $currentUserRole === 'Admin');

$msgSuccess = $_SESSION['success_msg'] ?? '';
$msgError = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Fetch Ride and Driver Details
$stmt = $pdo->prepare("
    SELECT 
        r.*, 
        u.Name AS DriverName, 
        u.Email AS DriverEmail, 
        u.UniversityVerified, 
        u.RatingAverage AS DriverRating, 
        u.RatingCount AS DriverRatingCount,
        u.ProfileImage AS DriverImage
    FROM `Ride` r
    JOIN `User` u ON r.DriverID = u.UserID
    WHERE r.RideID = ?
");
$stmt->execute([$rideId]);
$ride = $stmt->fetch();

if (!$ride) {
    die("Ride not found.");
}

$isDriver = ($currentUserId && (int)$ride['DriverID'] === (int)$currentUserId);

$currentUserGender = null;
if ($isLoggedIn) {
    $genStmt = $pdo->prepare("SELECT Gender FROM `User` WHERE UserID = ?");
    $genStmt->execute([$currentUserId]);
    $currentUserGender = $genStmt->fetchColumn();
}

// Fetch Driver Phone if user is driver or confirmed passenger
$driverPhone = null;
$phoneStmt = $pdo->prepare("SELECT Phone FROM `User_Phone` WHERE UserID = ? LIMIT 1");
$phoneStmt->execute([$ride['DriverID']]);
$driverPhone = $phoneStmt->fetchColumn();

// Fetch Accepted Passengers
$pStmt = $pdo->prepare("
    SELECT 
        rp.*, 
        u.Name, 
        u.Email, 
        u.UniversityVerified, 
        u.RatingAverage, 
        u.RatingCount,
        up.Phone
    FROM `RideParticipant` rp
    JOIN `User` u ON rp.UserID = u.UserID
    LEFT JOIN `User_Phone` up ON u.UserID = up.UserID
    WHERE rp.RideID = ? AND rp.Role = 'Passenger'
    GROUP BY rp.UserID
");
$pStmt->execute([$rideId]);
$passengers = $pStmt->fetchAll();

$isAcceptedPassenger = false;
$myArrivalStatus = 'Pending';
$myPaymentStatus = 'Unpaid';
$myPaymentMethod = '';
$myPaidAmount = 0.00;
foreach ($passengers as $p) {
    if ($currentUserId && (int)$p['UserID'] === (int)$currentUserId) {
        $isAcceptedPassenger = true;
        $myArrivalStatus = $p['ArrivalStatus'] ?? 'Pending';
        $myPaymentStatus = $p['PaymentStatus'] ?? 'Unpaid';
        $myPaymentMethod = $p['PaymentMethod'] ?? '';
        $myPaidAmount = floatval($p['PaidAmount'] ?? 0.00);
        break;
    }
}

$driverArrivalStatus = 'Pending';
$dArrStmt = $pdo->prepare("SELECT ArrivalStatus FROM `RideParticipant` WHERE RideID = ? AND UserID = ?");
$dArrStmt->execute([$rideId, $ride['DriverID']]);
$driverArrivalStatus = $dArrStmt->fetchColumn() ?: 'Pending';

// Fetch Pending Request status for current user
$hasPendingRequest = false;
if ($isLoggedIn && !$isDriver && !$isAcceptedPassenger) {
    $reqStmt = $pdo->prepare("SELECT RequestID FROM `RideRequest` WHERE RideID = ? AND PassengerID = ? AND Status = 'Pending'");
    $reqStmt->execute([$rideId, $currentUserId]);
    $hasPendingRequest = (bool)$reqStmt->fetch();
}

// Fetch Existing Rating from Current User if completed
$hasRated = false;
if ($isLoggedIn && $ride['Status'] === 'Completed') {
    $rChk = $pdo->prepare("SELECT RatingID FROM `Rating` WHERE RideID = ? AND ReviewerID = ?");
    $rChk->execute([$rideId, $currentUserId]);
    $hasRated = (bool)$rChk->fetch();
}

// Ensure coordinates fallback
$startLat = $ride['StartLatitude'] ?? 23.8069;
$startLng = $ride['StartLongitude'] ?? 90.3687;
$destLat = $ride['DestinationLatitude'] ?? 23.7781;
$destLng = $ride['DestinationLongitude'] ?? 90.4265;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ride Details: <?= htmlspecialchars($ride['StartLocation']) ?> → <?= htmlspecialchars($ride['Destination']) ?></title>
    <link rel="stylesheet" href="style.css">
    <!-- Leaflet OpenStreetMap CDN (No API keys required) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .details-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .details-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }
        .status-tag {
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-Open { background: #dcfce7; color: #15803d; }
        .status-Full { background: #fee2e2; color: #b91c1c; }
        .status-Completed { background: #e0e7ff; color: #4338ca; }
        .status-Cancelled { background: #f3f4f6; color: #6b7280; }
        
        .route-banner {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .route-stop {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }
        .route-stop-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #fff;
            flex-shrink: 0;
        }
        .route-stop.start .route-stop-icon { background: var(--success); }
        .route-stop.dest .route-stop-icon { background: var(--accent); }
        .route-connector-line {
            width: 3px;
            height: 25px;
            background: #cbd5e1;
            margin-left: 14px;
            margin-top: 4px;
            margin-bottom: 4px;
        }
        .passenger-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.85rem 1rem;
            margin-bottom: 0.75rem;
        }
        #routeMap {
            height: 240px;
            width: 100%;
            border-radius: 8px;
            margin-top: 1.25rem;
            border: 1px solid var(--border-color);
            z-index: 10;
        }
    </style>
</head>
<body>
    <?php render_navbar(); ?>

    <div class="main-container">
        <div class="details-container">

            <?php if (!empty($msgSuccess)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($msgSuccess) ?></div>
            <?php endif; ?>
            <?php if (!empty($msgError)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($msgError) ?></div>
            <?php endif; ?>

            <div class="details-header">
                <div>
                    <a href="index.php" style="color: var(--text-muted); font-size: 0.9rem; text-decoration: none; font-weight: 600;">← Back to Rides</a>
                    <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--primary); margin-top: 0.5rem;">
                        <?= htmlspecialchars($ride['StartLocation']) ?> → <?= htmlspecialchars($ride['Destination']) ?>
                    </h1>
                </div>
                <div>
                    <span class="status-tag status-<?= $ride['Status'] ?>">
                        ● <?= htmlspecialchars($ride['Status']) ?>
                    </span>
                </div>
            </div>

            <!-- Route & Interactive Map Banner -->
            <div class="route-banner">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div>
                        <div class="route-stop start">
                            <div class="route-stop-icon">A</div>
                            <div>
                                <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Departure Point</div>
                                <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($ride['StartLocation']) ?></div>
                            </div>
                        </div>

                        <div class="route-connector-line"></div>

                        <div class="route-stop dest">
                            <div class="route-stop-icon">B</div>
                            <div>
                                <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Destination</div>
                                <div style="font-size: 1.1rem; font-weight: 700; color: var(--primary);"><?= htmlspecialchars($ride['Destination']) ?></div>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                            <div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700;">DATE</div>
                                <div style="font-weight: 700; font-size: 0.95rem;">📅 <?= format_ride_date($ride['RideDate']) ?></div>
                            </div>
                            <div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700;">TIME</div>
                                <div style="font-weight: 700; font-size: 0.95rem;">⏰ <?= format_time_12h($ride['DepartureTime']) ?></div>
                            </div>
                            <div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700;">SEATS</div>
                                <div style="font-weight: 700; font-size: 0.95rem;">💺 <?= $ride['AvailableSeats'] ?> of <?= $ride['TotalSeats'] ?> left</div>
                            </div>
                            <div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700;">SHARED COST</div>
                                <div style="font-weight: 800; font-size: 1rem; color: var(--success);">
                                    <?= floatval($ride['SharedCost']) > 0 ? '৳' . number_format($ride['SharedCost'], 0) . ' / person' : 'Free' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Interactive OpenStreetMap -->
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">
                            🗺️ Route Map Visualization
                        </div>
                        <div id="routeMap"></div>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                
                <!-- Driver Card -->
                <div class="card">
                    <h2>Driver Information</h2>
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
                        <div class="driver-avatar" style="width: 54px; height: 54px; font-size: 1.3rem;">
                            <?= strtoupper(substr($ride['DriverName'], 0, 1)) ?>
                        </div>
                        <div>
                            <a href="profile.php?id=<?= $ride['DriverID'] ?>" style="font-size: 1.15rem; font-weight: 700; color: var(--text-main); text-decoration: none; display: flex; align-items: center; gap: 0.4rem;">
                                <?= htmlspecialchars($ride['DriverName']) ?>
                                <?= render_verification_badge($ride['UniversityVerified']) ?>
                            </a>
                            <div class="rating-badge" style="margin-top: 0.2rem;">
                                ★ <?= number_format($ride['DriverRating'], 1) ?> (<?= $ride['DriverRatingCount'] ?> reviews)
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($ride['VehicleInfo'])): ?>
                        <div style="background: #f8fafc; padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1rem;">
                            🚗 <strong>Vehicle:</strong> <?= htmlspecialchars($ride['VehicleInfo']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($ride['Notes'])): ?>
                        <div style="background: #f8fafc; padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1rem;">
                            💬 <strong>Driver Notes:</strong> "<?= htmlspecialchars($ride['Notes']) ?>"
                        </div>
                    <?php endif; ?>

                    <!-- Privacy & Contact Safety Policy -->
                    <?php if ($isDriver || $isAcceptedPassenger): ?>
                        <div style="background: #ecfdf5; border: 1px solid #a7f3d0; padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.9rem; color: #065f46;">
                            📞 <strong>Driver Contact:</strong> <?= htmlspecialchars($driverPhone ?: 'Verified student account') ?>
                        </div>
                    <?php else: ?>
                        <div style="background: #f8fafc; border: 1px dashed var(--border-color); padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.85rem; color: var(--text-muted);">
                            🔒 <em>Driver contact number is revealed once your join request is accepted.</em>
                        </div>
                    <?php endif; ?>

                    <!-- Safety & Report Option -->
                    <div style="margin-top: 1rem; text-align: right;">
                        <button type="button" class="btn btn-secondary btn-sm" style="color: var(--text-muted); font-size: 0.75rem;" onclick="alert('Safety Report submitted to University Transport Admin.');">
                            🚩 Report Ride / User
                        </button>
                    </div>
                </div>

                <!-- Accepted Passengers & Actions -->
                <div class="card">
                    <h2>Confirmed Passengers (<?= count($passengers) ?>)</h2>
                    <?php if (empty($passengers)): ?>
                        <p style="color: var(--text-muted); font-size: 0.9rem; font-style: italic; margin-bottom: 1.5rem;">
                            No passengers have joined this ride yet.
                        </p>
                    <?php else: ?>
                        <div style="margin-bottom: 1.5rem;">
                            <?php foreach ($passengers as $p): ?>
                                <div class="passenger-item" style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                                        <div class="nav-avatar"><?= strtoupper(substr($p['Name'], 0, 1)) ?></div>
                                        <div>
                                            <a href="profile.php?id=<?= $p['UserID'] ?>" style="font-weight: 700; font-size: 0.95rem; text-decoration: none; color: var(--text-main);">
                                                <?= htmlspecialchars($p['Name']) ?>
                                            </a>
                                            <?= render_verification_badge($p['UniversityVerified']) ?>
                                            
                                            <!-- Payment Status Indicator -->
                                            <div style="margin-top: 4px;">
                                                <?php if (($p['PaymentStatus'] ?? 'Unpaid') === 'Paid'): ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.75rem; font-weight: 700; color: #15803d; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 0.15rem 0.5rem; border-radius: 9999px;">
                                                        💳 Paid by <?= htmlspecialchars($p['Name']) ?> (৳<?= number_format(floatval($p['PaidAmount'] ?: $ride['SharedCost']), 0) ?><?= !empty($p['PaymentMethod']) ? ' via ' . htmlspecialchars($p['PaymentMethod']) : '' ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.75rem; font-weight: 600; color: #92400e; background: #fef3c7; border: 1px solid #fde68a; padding: 0.15rem 0.5rem; border-radius: 9999px;">
                                                        ⏳ Unpaid (৳<?= number_format(floatval($ride['SharedCost']), 0) ?>)
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <span style="font-size: 0.8rem; color: var(--text-muted); display: block;">
                                            Arrival: <strong><?= htmlspecialchars($p['ArrivalStatus']) ?></strong>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div style="margin-top: 1.5rem; display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php if ($isDriver || $isAcceptedPassenger): ?>
                            <div style="display: flex; gap: 0.5rem;">
                                <a href="chat.php?ride_id=<?= $ride['RideID'] ?>" class="btn btn-accent" style="background:#0284c7; flex: 1;">
                                    💬 Ride Chat
                                </a>
                                <button type="button" class="btn btn-secondary" onclick="openPassModal()">
                                    🎫 View Ride Pass
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($ride['IsWomenOnly'])): ?>
                            <div style="background: #fdf2f8; border: 1.5px solid #fbcfe8; padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.6rem; color: #9d174d;">
                                <span style="font-size: 1.3rem;">🌸</span>
                                <div>
                                    <strong style="font-size: 0.92rem;">Women-Only Carpool</strong>
                                    <p style="font-size: 0.8rem; margin: 0.15rem 0 0 0; color: #be185d;">Exclusively for verified female BRAC University students and faculty.</p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!$isLoggedIn): ?>
                            <a href="login.php" class="btn btn-primary">Log In to Join Ride</a>
                        <?php elseif ($isAdmin): ?>
                            <div style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;">
                                <div style="font-weight: 700; color: #1e40af; font-size: 0.95rem; margin-bottom: 0.35rem; display: flex; align-items: center; gap: 0.4rem;">
                                    <span>🛡️</span> Administrative Controls
                                </div>
                                <p style="font-size: 0.82rem; color: #3b82f6; margin-bottom: 0.85rem; line-height: 1.4;">
                                    As an administrator, you cannot join or offer rides, but you can moderate, end, or delete this ride.
                                </p>
                                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                    <?php if ($ride['Status'] !== 'Completed' && $ride['Status'] !== 'Cancelled'): ?>
                                        <form method="POST" action="api_actions.php" onsubmit="return confirm('End this ride immediately and mark it as Completed?');">
                                            <input type="hidden" name="action" value="admin_end_ride">
                                            <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                            <input type="hidden" name="redirect" value="ride_details.php?id=<?= $ride['RideID'] ?>">
                                            <button type="submit" class="btn btn-warning" style="width: 100%;">🏁 End Ride (Admin)</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" action="api_actions.php" onsubmit="return confirm('Permanently delete this ride and all associated data? This action cannot be undone.');">
                                        <input type="hidden" name="action" value="admin_delete_ride">
                                        <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                        <button type="submit" class="btn btn-danger" style="width: 100%;">🗑️ Delete Ride (Admin)</button>
                                    </form>
                                    <a href="admin.php?tab=rides" class="btn btn-secondary" style="font-size: 0.85rem; text-align: center;">Go to Admin Dashboard</a>
                                </div>
                            </div>
                        <?php elseif ($isDriver): ?>
                            <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                                <?php if ($ride['Status'] !== 'Cancelled' && $ride['Status'] !== 'Completed'): ?>
                                    <form method="POST" action="api_actions.php" onsubmit="return confirm('🏁 Have you reached the destination? This will mark the ride as Completed and prompt all passengers to rate the commute.');" style="margin: 0;">
                                        <input type="hidden" name="action" value="driver_end_ride">
                                        <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                        <input type="hidden" name="redirect" value="ride_details.php?id=<?= $ride['RideID'] ?>">
                                        <button type="submit" class="btn btn-success" style="width: 100%; padding: 0.75rem; font-weight: 800; background: #15803d; border-color: #15803d;">
                                            🏁 End Ride (Mark as Reached)
                                        </button>
                                    </form>

                                    <div style="display: flex; gap: 0.5rem;">
                                        <a href="edit_ride.php?id=<?= $ride['RideID'] ?>" class="btn btn-primary" style="flex: 1; text-align: center;">
                                            ✏️ Edit Ride
                                        </a>
                                        <form method="POST" action="api_actions.php" onsubmit="return confirm('Are you sure you want to cancel this ride? All joined passengers will be notified.');" style="margin: 0;">
                                            <input type="hidden" name="action" value="cancel_ride">
                                            <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                            <button type="submit" class="btn btn-danger">Cancel</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div style="background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 8px; padding: 0.75rem; text-align: center; color: #166534; font-weight: 700; font-size: 0.9rem;">
                                        🏁 Destination Reached · Ride Completed
                                    </div>
                                <?php endif; ?>
                                <a href="my_rides.php" class="btn btn-secondary" style="width: 100%; text-align: center;">Manage Requests & Passengers</a>
                            </div>
                        <?php elseif ($isAcceptedPassenger): ?>
                            <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                                
                                <!-- Passenger Payment Box -->
                                <?php if (floatval($ride['SharedCost']) > 0): ?>
                                    <div style="background: <?= $myPaymentStatus === 'Paid' ? '#ecfdf5' : '#eff6ff' ?>; border: 1.5px solid <?= $myPaymentStatus === 'Paid' ? '#a7f3d0' : '#bfdbfe' ?>; border-radius: 8px; padding: 0.85rem 1rem;">
                                        <?php if ($myPaymentStatus === 'Paid'): ?>
                                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                                <div>
                                                    <strong style="color: #15803d; font-size: 0.92rem;">✅ Fare Paid Successfully</strong>
                                                    <p style="font-size: 0.8rem; color: #166534; margin: 0.15rem 0 0 0;">
                                                        You paid <strong>৳<?= number_format($myPaidAmount ?: $ride['SharedCost'], 0) ?></strong> via <?= htmlspecialchars($myPaymentMethod ?: 'bKash') ?>. The driver can see your payment.
                                                    </p>
                                                </div>
                                                <span style="font-size: 1.3rem;">💳</span>
                                            </div>
                                        <?php else: ?>
                                            <div style="margin-bottom: 0.6rem;">
                                                <strong style="color: #1e40af; font-size: 0.92rem;">💳 Pay Shared Fare (৳<?= number_format($ride['SharedCost'], 0) ?>)</strong>
                                                <p style="font-size: 0.8rem; color: #2563eb; margin: 0.15rem 0 0 0;">
                                                    Select your payment method to pay the driver:
                                                </p>
                                            </div>
                                            <form method="POST" action="api_actions.php" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <input type="hidden" name="action" value="make_payment">
                                                <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                                <input type="hidden" name="redirect" value="ride_details.php?id=<?= $ride['RideID'] ?>">
                                                
                                                <select name="payment_method" class="form-control" style="flex: 1; min-width: 110px; padding: 0.45rem 0.65rem; font-size: 0.85rem;">
                                                    <option value="bKash">bKash</option>
                                                    <option value="Nagad">Nagad</option>
                                                    <option value="Rocket">Rocket</option>
                                                    <option value="Cash">Cash to Driver</option>
                                                </select>
                                                <button type="submit" class="btn btn-primary" style="padding: 0.45rem 1rem; font-weight: 700; font-size: 0.88rem;">
                                                    💳 Pay Now
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($myArrivalStatus !== 'Reached' && $ride['Status'] !== 'Cancelled'): ?>
                                    <div style="background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px; padding: 1rem; text-align: center;">
                                        <p style="font-size: 0.88rem; color: #166534; font-weight: 700; margin-bottom: 0.6rem;">
                                            📍 Have you arrived at your destination?
                                        </p>
                                        <form method="POST" action="api_actions.php" style="margin-bottom: 0.5rem;">
                                            <input type="hidden" name="action" value="confirm_arrival">
                                            <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                            <input type="hidden" name="arrival_status" value="Reached">
                                            <input type="hidden" name="redirect" value="ride_details.php?id=<?= $ride['RideID'] ?>">
                                            <button type="submit" class="btn btn-success" style="width: 100%; padding: 0.7rem; font-weight: 800; background: #16a34a; border-color: #16a34a;">
                                                ✅ Yes, Mark as Reached
                                            </button>
                                        </form>
                                    </div>
                                <?php elseif ($myArrivalStatus === 'Reached'): ?>
                                    <div style="background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 8px; padding: 0.75rem; text-align: center; color: #166534; font-weight: 700; font-size: 0.9rem;">
                                        ✅ You have confirmed arrival at destination
                                    </div>
                                <?php endif; ?>

                                <?php if ($ride['Status'] !== 'Completed' && $ride['Status'] !== 'Cancelled' && $myArrivalStatus !== 'Reached'): ?>
                                    <form method="POST" action="api_actions.php" onsubmit="return confirm('Are you sure you want to leave this ride?');">
                                        <input type="hidden" name="action" value="leave_ride">
                                        <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                        <button type="submit" class="btn btn-danger" style="width: 100%;">Leave Ride</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($hasPendingRequest): ?>
                            <form method="POST" action="api_actions.php">
                                <input type="hidden" name="action" value="cancel_request">
                                <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                <button type="submit" class="btn btn-secondary" style="color: var(--warning);">
                                    ⏳ Request Pending · Cancel Request
                                </button>
                            </form>
                        <?php elseif (!empty($ride['IsWomenOnly']) && $currentUserGender !== 'Female'): ?>
                            <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.75rem; border-radius: 8px; font-size: 0.85rem; font-weight: 700; text-align: center;">
                                🚫 Male passengers cannot join Women-Only carpools.
                            </div>
                            <button class="btn btn-secondary" disabled style="opacity: 0.5; cursor: not-allowed;">🌸 Restricted (Women-Only)</button>
                        <?php elseif ($ride['Status'] === 'Open' && (int)$ride['AvailableSeats'] > 0): ?>
                            <form method="POST" action="api_actions.php">
                                <input type="hidden" name="action" value="request_join">
                                <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <input type="text" name="pickup_note" class="form-control" placeholder="Optional pickup note / landmark">
                                </div>
                                <button type="submit" class="btn btn-primary">Request to Join</button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-secondary" disabled style="opacity: 0.6;">Ride Full / Closed</button>
                        <?php endif; ?>

                        <?php if ($ride['Status'] === 'Completed' && ($isDriver || $isAcceptedPassenger)): ?>
                            <a href="rate.php?ride_id=<?= $ride['RideID'] ?>" class="btn btn-accent">
                                <?= $hasRated ? '⭐️ View / Update Rating' : '⭐️ Rate Your Ride Partner' ?>
                            </a>
                        <?php endif; ?>

                        <?php if ($isDriver || $isAcceptedPassenger): ?>
                            <a href="lost_found.php?ride_id=<?= $ride['RideID'] ?>" class="btn btn-secondary" style="font-size: 0.85rem; padding: 0.6rem; text-align: center; text-decoration: none;">
                                🔍 Lost / Found Something on this Ride?
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

    </div>

    <!-- Official Ride Pass Modal -->
    <div id="passModal" class="modal-overlay">
        <div class="modal-box" style="border-top: 6px solid var(--primary);">
            <div style="text-align: center; margin-bottom: 1.25rem;">
                <div style="font-size: 2rem;">🎓🚗</div>
                <h3 style="font-size: 1.35rem; color: var(--primary); font-weight: 800; margin-top: 0.25rem;">BRAC University Commute Pass</h3>
                <span class="badge-verified" style="margin-top: 0.25rem;">✓ Security Verified Carpool</span>
            </div>

            <div style="background: #f8fafc; border: 1.5px dashed var(--border-color); border-radius: 8px; padding: 1.25rem; font-size: 0.9rem; margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span style="color: var(--text-muted);">Pass ID:</span>
                    <strong>#BRACU-RIDE-<?= $ride['RideID'] ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span style="color: var(--text-muted);">Route:</span>
                    <strong><?= htmlspecialchars($ride['StartLocation']) ?> → <?= htmlspecialchars($ride['Destination']) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span style="color: var(--text-muted);">Date & Time:</span>
                    <strong><?= format_ride_date($ride['RideDate']) ?> at <?= format_time_12h($ride['DepartureTime']) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span style="color: var(--text-muted);">Driver:</span>
                    <strong><?= htmlspecialchars($ride['DriverName']) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span style="color: var(--text-muted);">Vehicle:</span>
                    <strong><?= htmlspecialchars($ride['VehicleInfo'] ?: 'Private University Carpool') ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Confirmed Passenger:</span>
                    <strong><?= htmlspecialchars($_SESSION['name'] ?? 'Verified Rider') ?></strong>
                </div>
            </div>

            <div style="display: flex; gap: 0.75rem;">
                <button type="button" class="btn btn-primary" style="flex: 1;" onclick="window.print()">🖨️ Print Pass</button>
                <button type="button" class="btn btn-secondary" onclick="closePassModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        function openPassModal() {
            document.getElementById('passModal').classList.add('active');
        }
        function closePassModal() {
            document.getElementById('passModal').classList.remove('active');
        }

        // Initialize Leaflet Map
        document.addEventListener('DOMContentLoaded', function() {
            var startPos = [<?= floatval($startLat) ?>, <?= floatval($startLng) ?>];
            var destPos = [<?= floatval($destLat) ?>, <?= floatval($destLng) ?>];

            var map = L.map('routeMap').setView(startPos, 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);

            var startMarker = L.marker(startPos).addTo(map).bindPopup("<b>Start:</b> <?= htmlspecialchars(addslashes($ride['StartLocation'])) ?>").openPopup();
            var destMarker = L.marker(destPos).addTo(map).bindPopup("<b>Destination:</b> <?= htmlspecialchars(addslashes($ride['Destination'])) ?>");

            // Draw connecting route line
            var polyline = L.polyline([startPos, destPos], {
                color: '#0284c7',
                weight: 4,
                opacity: 0.8,
                dashArray: '8, 8'
            }).addTo(map);

            // Fit map bounds to show both markers nicely
            var bounds = L.latLngBounds([startPos, destPos]);
            map.fitBounds(bounds, { padding: [30, 30] });
        });
    </script>

    <?php render_footer(); ?>
</body>
</html>
