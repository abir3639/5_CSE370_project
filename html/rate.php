<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_msg'] = "Please log in to submit ratings.";
    header('Location: login.php');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$rideId = (int)($_GET['ride_id'] ?? 0);

if ($rideId <= 0) {
    header('Location: my_rides.php?tab=completed');
    exit;
}

// Fetch Ride Details
$rStmt = $pdo->prepare("SELECT * FROM `Ride` WHERE `RideID` = ?");
$rStmt->execute([$rideId]);
$ride = $rStmt->fetch();

if (!$ride || $ride['Status'] !== 'Completed') {
    $_SESSION['error_msg'] = "Ratings can only be given for completed rides.";
    header('Location: my_rides.php');
    exit;
}

// Verify Current User was a Participant
$chk = $pdo->prepare("SELECT Role FROM `RideParticipant` WHERE `RideID` = ? AND `UserID` = ?");
$chk->execute([$rideId, $currentUserId]);
$myParticipant = $chk->fetch();

if (!$myParticipant) {
    $_SESSION['error_msg'] = "You were not a participant on this ride.";
    header('Location: my_rides.php');
    exit;
}

$myRole = $myParticipant['Role']; // 'Driver' or 'Passenger'

// Fetch Eligible Recipients (other participants in this ride)
$recipStmt = $pdo->prepare("
    SELECT 
        rp.UserID, 
        rp.Role, 
        u.Name, 
        u.UniversityVerified, 
        u.RatingAverage, 
        u.RatingCount,
        rat.RatingID,
        rat.Rating,
        rat.Review
    FROM `RideParticipant` rp
    JOIN `User` u ON rp.UserID = u.UserID
    LEFT JOIN `Rating` rat ON rat.RideID = rp.RideID AND rat.ReviewerID = ? AND rat.RecipientID = rp.UserID
    WHERE rp.RideID = ? AND rp.UserID != ?
");
$recipStmt->execute([$currentUserId, $rideId, $currentUserId]);
$recipients = $recipStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Ride Partners - BRAC University Rideshare</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .rate-container {
            max-width: 650px;
            margin: 0 auto;
        }
        .rating-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
    </style>
</head>
<body>
    <?php render_navbar('my_rides'); ?>

    <div class="main-container">
        <div class="rate-container">
            
            <a href="my_rides.php?tab=completed" style="color: var(--text-muted); font-size: 0.9rem; text-decoration: none; font-weight: 600;">
                ← Back to Completed Rides
            </a>

            <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--primary); margin: 0.5rem 0 0.25rem 0;">
                ⭐️ Rate Your Ride Experience
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 1.5rem;">
                Ride: <strong><?= htmlspecialchars($ride['StartLocation']) ?> → <?= htmlspecialchars($ride['Destination']) ?></strong> on <?= format_ride_date($ride['RideDate']) ?>
            </p>

            <?php if (empty($recipients)): ?>
                <div class="empty-state-card">
                    <div class="empty-state-icon">👥</div>
                    <h3>No other participants</h3>
                    <p>There were no other registered participants to rate on this ride.</p>
                </div>
            <?php else: ?>
                <?php foreach ($recipients as $recip): ?>
                    <div class="rating-card">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <div class="driver-avatar" style="width: 44px; height: 44px; font-size: 1.1rem;">
                                    <?= strtoupper(substr($recip['Name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <h3 style="font-size: 1.1rem; color: var(--text-main); font-weight: 700;">
                                        <?= htmlspecialchars($recip['Name']) ?>
                                        <?= render_verification_badge($recip['UniversityVerified']) ?>
                                    </h3>
                                    <p style="font-size: 0.8rem; color: var(--text-muted);">
                                        Role on this ride: <strong><?= htmlspecialchars($recip['Role']) ?></strong>
                                    </p>
                                </div>
                            </div>

                            <?php if ($recip['RatingID']): ?>
                                <span class="badge-verified" style="background:#ecfdf5; color:#065f46; border-color:#a7f3d0;">
                                    ✓ Rating Submitted
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($recip['RatingID']): ?>
                            <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 1rem; border-radius: 8px;">
                                <div style="color: #d97706; font-size: 1.1rem; margin-bottom: 0.35rem;">
                                    <?= str_repeat('★', (int)$recip['Rating']) . str_repeat('☆', 5 - (int)$recip['Rating']) ?>
                                </div>
                                <p style="font-size: 0.95rem; color: #334155; font-style: italic;">
                                    "<?= htmlspecialchars($recip['Review'] ?: 'No written feedback provided.') ?>"
                                </p>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="api_actions.php">
                                <input type="hidden" name="action" value="submit_rating">
                                <input type="hidden" name="ride_id" value="<?= $ride['RideID'] ?>">
                                <input type="hidden" name="recipient_id" value="<?= $recip['UserID'] ?>">

                                <div class="form-group">
                                    <label style="font-size: 0.9rem;">
                                        <?= $myRole === 'Passenger' ? 'How was your ride with this driver?' : 'How was your experience with this passenger?' ?>
                                    </label>
                                    
                                    <!-- Star Picker -->
                                    <div class="star-rating-picker">
                                        <input type="radio" id="star5_<?= $recip['UserID'] ?>" name="rating" value="5" checked>
                                        <label for="star5_<?= $recip['UserID'] ?>" title="5 stars">★</label>
                                        <input type="radio" id="star4_<?= $recip['UserID'] ?>" name="rating" value="4">
                                        <label for="star4_<?= $recip['UserID'] ?>" title="4 stars">★</label>
                                        <input type="radio" id="star3_<?= $recip['UserID'] ?>" name="rating" value="3">
                                        <label for="star3_<?= $recip['UserID'] ?>" title="3 stars">★</label>
                                        <input type="radio" id="star2_<?= $recip['UserID'] ?>" name="rating" value="2">
                                        <label for="star2_<?= $recip['UserID'] ?>" title="2 stars">★</label>
                                        <input type="radio" id="star1_<?= $recip['UserID'] ?>" name="rating" value="1">
                                        <label for="star1_<?= $recip['UserID'] ?>" title="1 star">★</label>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Written Review / Feedback (Optional)</label>
                                    <textarea name="review" class="form-control" rows="2" placeholder="e.g. Great music, punctual, very friendly!"></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary btn-sm">Submit Rating</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </div>

    <?php render_footer(); ?>
</body>
</html>
