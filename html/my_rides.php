<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_msg'] = "Please log in to view your rides.";
    header('Location: login.php');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$currentUserRole = $_SESSION['user_type'] ?? 'Passenger';

$activeTab = $_GET['tab'] ?? 'upcoming'; // upcoming, active, completed, cancelled

$msgSuccess = $_SESSION['success_msg'] ?? '';
$msgError = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// 1. Fetch Pending Join Requests for rides offered by this user (Driver Management)
$reqStmt = $pdo->prepare("
    SELECT 
        rr.RequestID,
        rr.RequestedAt,
        rr.Status AS RequestStatus,
        r.RideID,
        r.StartLocation,
        r.Destination,
        r.RideDate,
        r.DepartureTime,
        r.AvailableSeats,
        u.UserID AS PassengerID,
        u.Name AS PassengerName,
        u.UniversityVerified,
        u.RatingAverage,
        u.RatingCount
    FROM `RideRequest` rr
    JOIN `Ride` r ON rr.RideID = r.RideID
    JOIN `User` u ON rr.PassengerID = u.UserID
    WHERE r.DriverID = ? AND rr.Status = 'Pending'
    ORDER BY rr.RequestedAt DESC
");
$reqStmt->execute([$currentUserId]);
$pendingRequests = $reqStmt->fetchAll();

// 2. Fetch User's Rides (as Driver or Passenger)
$rideQuery = "
    SELECT DISTINCT
        r.*,
        CASE WHEN r.DriverID = ? THEN 'Driver' ELSE 'Passenger' END AS UserRole,
        du.Name AS DriverName,
        du.UniversityVerified AS DriverVerified,
        du.RatingAverage AS DriverRating,
        rp.ArrivalStatus AS MyArrivalStatus,
        rp.PaymentStatus AS MyPaymentStatus,
        rp.PaidAmount AS MyPaidAmount,
        rp.PaymentMethod AS MyPaymentMethod,
        (SELECT COUNT(*) FROM `RideParticipant` WHERE RideID = r.RideID AND Role = 'Passenger') AS TotalPassengers
    FROM `Ride` r
    LEFT JOIN `RideParticipant` rp ON r.RideID = rp.RideID AND rp.UserID = ?
    JOIN `User` du ON r.DriverID = du.UserID
    WHERE (r.DriverID = ? OR rp.UserID = ?)
";

if ($activeTab === 'upcoming') {
    $rideQuery .= " AND r.Status IN ('Open', 'Full', 'Confirmed') AND (rp.ArrivalStatus IS NULL OR rp.ArrivalStatus != 'Reached') AND (r.RideDate > CURDATE() OR (r.RideDate = CURDATE() AND r.DepartureTime > CURTIME()))";
} elseif ($activeTab === 'active') {
    $rideQuery .= " AND r.Status NOT IN ('Completed', 'Cancelled') AND (rp.ArrivalStatus IS NULL OR rp.ArrivalStatus != 'Reached') AND (r.Status = 'In Progress' OR r.RideDate < CURDATE() OR (r.RideDate = CURDATE() AND r.DepartureTime <= CURTIME()))";
} elseif ($activeTab === 'completed') {
    $rideQuery .= " AND (r.Status = 'Completed' OR rp.ArrivalStatus = 'Reached') AND r.Status != 'Cancelled'";
} elseif ($activeTab === 'cancelled') {
    $rideQuery .= " AND r.Status = 'Cancelled'";
}

$rideQuery .= " ORDER BY r.RideDate DESC, r.DepartureTime DESC";

$stmt = $pdo->prepare($rideQuery);
$stmt->execute([$currentUserId, $currentUserId, $currentUserId, $currentUserId]);
$userRides = $stmt->fetchAll();

// Helper to fetch participant details for a ride
function get_ride_participants($pdo, $rideId) {
    $stmt = $pdo->prepare("
        SELECT u.UserID, u.Name, u.UniversityVerified, rp.Role, rp.ArrivalStatus, rp.PaymentStatus, rp.PaidAmount, rp.PaymentMethod
        FROM `RideParticipant` rp
        JOIN `User` u ON rp.UserID = u.UserID
        WHERE rp.RideID = ?
    ");
    $stmt->execute([$rideId]);
    return $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Rides - BRAC University Rideshare</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php render_navbar('my_rides'); ?>

    <div class="main-container">
        
        <?php if (!empty($msgSuccess)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($msgSuccess) ?></div>
        <?php endif; ?>
        <?php if (!empty($msgError)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($msgError) ?></div>
        <?php endif; ?>

        <div class="section-header">
            <div>
                <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--primary);">My Rides Dashboard</h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">Track your upcoming trips, confirm arrival, and manage passenger requests.</p>
            </div>
            <div>
                <a href="offer_ride.php" class="btn btn-primary btn-sm">+ Offer a New Ride</a>
            </div>
        </div>

        <!-- Driver Pending Requests Management Section -->
        <?php if (!empty($pendingRequests)): ?>
            <div class="card" style="border-left: 5px solid var(--accent); background: #f0f9ff;">
                <h2 style="color: #0369a1; display: flex; align-items: center; gap: 0.5rem;">
                    📬 Pending Join Requests (<?= count($pendingRequests) ?>)
                </h2>
                <p style="font-size: 0.9rem; color: #0284c7; margin-bottom: 1rem;">
                    Students requesting to join your offered rides. Review and accept them to confirm their seats.
                </p>

                <div class="requests-list">
                    <?php foreach ($pendingRequests as $req): ?>
                        <div class="request-card" style="background: #ffffff;">
                            <div class="request-user-info">
                                <div class="nav-avatar" style="width: 42px; height: 42px; font-size: 1.1rem;">
                                    <?= strtoupper(substr($req['PassengerName'], 0, 1)) ?>
                                </div>
                                <div class="request-user-meta">
                                    <h5>
                                        <a href="profile.php?id=<?= $req['PassengerID'] ?>" style="text-decoration: none; color: var(--text-main);">
                                            <?= htmlspecialchars($req['PassengerName']) ?>
                                        </a>
                                        <?= render_verification_badge($req['UniversityVerified']) ?>
                                    </h5>
                                    <p>
                                        ★ <?= number_format($req['RatingAverage'], 1) ?> · Requested to join: 
                                        <strong><?= htmlspecialchars($req['StartLocation']) ?> → <?= htmlspecialchars($req['Destination']) ?></strong>
                                    </p>
                                    <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">
                                        Ride on <?= format_ride_date($req['RideDate']) ?> at <?= format_time_12h($req['DepartureTime']) ?> 
                                        (<?= $req['AvailableSeats'] ?> seat<?= $req['AvailableSeats'] > 1 ? 's' : '' ?> remaining)
                                    </p>
                                </div>
                            </div>

                            <div class="request-actions">
                                <form method="POST" action="api_actions.php" style="display:inline;">
                                    <input type="hidden" name="action" value="accept_request">
                                    <input type="hidden" name="request_id" value="<?= $req['RequestID'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm">Accept</button>
                                </form>
                                <form method="POST" action="api_actions.php" style="display:inline;">
                                    <input type="hidden" name="action" value="reject_request">
                                    <input type="hidden" name="request_id" value="<?= $req['RequestID'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tabs Navigation -->
        <div class="tabs-nav">
            <a href="my_rides.php?tab=upcoming" class="tab-btn <?= $activeTab === 'upcoming' ? 'active' : '' ?>">
                📅 Upcoming
            </a>
            <a href="my_rides.php?tab=active" class="tab-btn <?= $activeTab === 'active' ? 'active' : '' ?>">
                🚗 Today / Active
            </a>
            <a href="my_rides.php?tab=completed" class="tab-btn <?= $activeTab === 'completed' ? 'active' : '' ?>">
                ✅ Completed
            </a>
            <a href="my_rides.php?tab=cancelled" class="tab-btn <?= $activeTab === 'cancelled' ? 'active' : '' ?>">
                ❌ Cancelled
            </a>
        </div>

        <!-- Rides List in Tab -->
        <?php if (empty($userRides)): ?>
            <div class="empty-state-card">
                <div class="empty-state-icon">🚘</div>
                <h3>No <?= htmlspecialchars($activeTab) ?> rides</h3>
                <p>You don't have any <?= htmlspecialchars($activeTab) ?> rides at the moment.</p>
                <div class="empty-state-actions">
                    <a href="index.php" class="btn btn-secondary">Find a Ride</a>
                    <a href="offer_ride.php" class="btn btn-primary">Offer a Ride</a>
                </div>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                <?php foreach ($userRides as $ride): 
                    $participants = get_ride_participants($pdo, $ride['RideID']);
                    $isCompletedOrReached = ($ride['Status'] === 'Completed' || $ride['MyArrivalStatus'] === 'Reached');
                ?>
                    <div class="card" style="margin-bottom: 0;">
                        
                        <!-- Arrival / End Ride Prompt for Active / Departed Rides -->
                        <?php if (!$isCompletedOrReached && $ride['Status'] !== 'Cancelled'): ?>
                            <?php if ($ride['UserRole'] === 'Driver'): ?>
                                <div class="arrival-prompt-box">
                                    <div class="arrival-prompt-content">
                                        <h4>🏁 Have you reached the destination?</h4>
                                        <p>End the ride once you have arrived. This will mark the trip as Completed and invite all riders to rate their commute.</p>
                                    </div>
                                    <div class="arrival-actions">
                                        <form method="POST" action="api_actions.php" onsubmit="return confirm('End this ride and mark as Reached?');">
                                            <input type="hidden" name="action" value="driver_end_ride">
                                            <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                            <button type="submit" class="btn btn-success">🏁 Yes, End Ride</button>
                                        </form>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="arrival-prompt-box">
                                    <div class="arrival-prompt-content">
                                        <h4>📍 Have you reached your destination?</h4>
                                        <p>Confirm your arrival so this trip moves to your Completed rides and you can rate your driver.</p>
                                        <p style="margin-top: 4px; font-size: 0.8rem; font-weight: 600;">
                                            Your arrival status: 
                                            <span style="color: <?= $ride['MyArrivalStatus'] === 'Reached' ? 'var(--success)' : '#d97706' ?>">
                                                <?= $ride['MyArrivalStatus'] === 'Reached' ? '✅ Reached' : '⏳ Pending confirmation' ?>
                                            </span>
                                        </p>
                                    </div>
                                    <div class="arrival-actions">
                                        <form method="POST" action="api_actions.php">
                                            <input type="hidden" name="action" value="confirm_arrival">
                                            <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                            <input type="hidden" name="arrival_status" value="Reached">
                                            <button type="submit" class="btn btn-success">✅ Yes, I reached</button>
                                        </form>
                                        <form method="POST" action="api_actions.php">
                                            <input type="hidden" name="action" value="confirm_arrival">
                                            <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                            <input type="hidden" name="arrival_status" value="Not Reached">
                                            <button type="submit" class="btn btn-secondary">Not yet</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.4rem;">
                                    <span class="meta-chip" style="background: <?= $ride['UserRole'] === 'Driver' ? '#fef3c7' : '#dbeafe' ?>; color: <?= $ride['UserRole'] === 'Driver' ? '#92400e' : '#1e40af' ?>; font-weight: 700;">
                                        <?= $ride['UserRole'] === 'Driver' ? '🚗 Driver' : '🎒 Passenger' ?>
                                    </span>
                                    <span class="meta-chip">
                                        Status: <strong><?= htmlspecialchars($ride['Status']) ?></strong>
                                    </span>
                                    <?php if ($ride['MyArrivalStatus'] === 'Reached'): ?>
                                        <span class="meta-chip" style="background: #ecfdf5; color: #065f46; font-weight: 700;">
                                            ✅ Reached
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($ride['UserRole'] === 'Passenger' && floatval($ride['SharedCost']) > 0): ?>
                                        <?php if (($ride['MyPaymentStatus'] ?? 'Unpaid') === 'Paid'): ?>
                                            <span class="meta-chip" style="background: #ecfdf5; color: #15803d; font-weight: 700;">
                                                💳 Fare Paid (৳<?= number_format(floatval($ride['MyPaidAmount'] ?: $ride['SharedCost']), 0) ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="meta-chip" style="background: #fef3c7; color: #92400e; font-weight: 600;">
                                                ⏳ Fare Unpaid (৳<?= number_format(floatval($ride['SharedCost']), 0) ?>)
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <span class="meta-chip">
                                        📅 <?= format_ride_date($ride['RideDate']) ?> · ⏰ <?= format_time_12h($ride['DepartureTime']) ?>
                                    </span>
                                </div>

                                <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--primary); margin-top: 0.4rem;">
                                    <?= htmlspecialchars($ride['StartLocation']) ?> → <?= htmlspecialchars($ride['Destination']) ?>
                                </h3>

                                <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                                    Driver: <strong><?= htmlspecialchars($ride['DriverName']) ?></strong> <?= render_verification_badge($ride['DriverVerified']) ?> · Shared Cost: <strong><?= floatval($ride['SharedCost']) > 0 ? '৳' . number_format($ride['SharedCost'], 0) : 'Free' ?></strong>
                                </p>
                            </div>

                            <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                                <?php if ($ride['UserRole'] === 'Passenger' && floatval($ride['SharedCost']) > 0 && ($ride['MyPaymentStatus'] ?? 'Unpaid') !== 'Paid' && $ride['Status'] !== 'Cancelled'): ?>
                                    <form method="POST" action="api_actions.php" style="margin: 0;">
                                        <input type="hidden" name="action" value="make_payment">
                                        <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                        <input type="hidden" name="payment_method" value="bKash">
                                        <input type="hidden" name="redirect" value="my_rides.php?tab=<?= htmlspecialchars($activeTab) ?>">
                                        <button type="submit" class="btn btn-primary btn-sm" style="background: #0284c7; border-color: #0284c7;">
                                            💳 Pay ৳<?= number_format($ride['SharedCost'], 0) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($ride['UserRole'] === 'Driver' && $ride['Status'] !== 'Completed' && $ride['Status'] !== 'Cancelled'): ?>
                                    <form method="POST" action="api_actions.php" onsubmit="return confirm('End this ride and mark as Reached?');" style="margin: 0;">
                                        <input type="hidden" name="action" value="driver_end_ride">
                                        <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">🏁 End Ride</button>
                                    </form>
                                    <a href="edit_ride.php?id=<?= $ride['RideID'] ?>" class="btn btn-primary btn-sm">
                                        ✏️ Edit
                                    </a>
                                <?php elseif ($ride['UserRole'] === 'Passenger' && $ride['Status'] !== 'Completed' && $ride['Status'] !== 'Cancelled' && $ride['MyArrivalStatus'] !== 'Reached'): ?>
                                    <form method="POST" action="api_actions.php" style="margin: 0;">
                                        <input type="hidden" name="action" value="confirm_arrival">
                                        <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                        <input type="hidden" name="arrival_status" value="Reached">
                                        <button type="submit" class="btn btn-success btn-sm">📍 Reached</button>
                                    </form>
                                <?php endif; ?>

                                <a href="ride_details.php?id=<?= $ride['RideID'] ?>" class="btn btn-secondary btn-sm">
                                    Ride Details
                                </a>
                                <a href="lost_found.php?ride_id=<?= $ride['RideID'] ?>" class="btn btn-secondary btn-sm" title="Report or check lost items for this ride">
                                    🔍 Lost & Found
                                </a>

                                <?php if ($isCompletedOrReached): ?>
                                    <a href="rate.php?ride_id=<?= $ride['RideID'] ?>" class="btn btn-accent btn-sm">
                                        ⭐️ Rate <?= $ride['UserRole'] === 'Driver' ? 'Passengers' : 'Driver' ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Participants Status Table -->
                        <?php if (!empty($participants)): ?>
                            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                                <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem;">
                                    Ride Participants, Arrival & Payment Status:
                                </div>
                                <div style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
                                    <?php foreach ($participants as $pt): ?>
                                        <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                            <strong><?= htmlspecialchars($pt['Name']) ?></strong> 
                                            <span style="color: var(--text-muted); font-size: 0.75rem;">(<?= $pt['Role'] ?>)</span>:
                                            <span style="color: <?= $pt['ArrivalStatus'] === 'Reached' ? 'var(--success)' : '#d97706' ?>; font-weight: 600;">
                                                <?= $pt['ArrivalStatus'] === 'Reached' ? '✓ Reached' : '⏳ ' . $pt['ArrivalStatus'] ?>
                                            </span>
                                            <?php if ($pt['Role'] === 'Passenger' && floatval($ride['SharedCost']) > 0): ?>
                                                <span style="color: var(--border-color);">|</span>
                                                <?php if (($pt['PaymentStatus'] ?? 'Unpaid') === 'Paid'): ?>
                                                    <span style="color: #15803d; font-weight: 700; font-size: 0.8rem;">
                                                        💳 Paid by <?= htmlspecialchars($pt['Name']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: #d97706; font-size: 0.8rem; font-weight: 600;">
                                                        ⏳ Unpaid
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <?php render_footer(); ?>
</body>
</html>
