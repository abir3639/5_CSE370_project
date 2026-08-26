<?php
// api_actions.php - Centralized Server-Side Action Handler for Rideshare Operations
session_start();
require_once 'db.php';
require_once 'helpers.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_msg'] = "Please log in to perform this action.";
    header('Location: login.php');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    // -------------------------------------------------------------------------
    // 1. REQUEST TO JOIN A RIDE (Passenger)
    // -------------------------------------------------------------------------
    case 'request_join':
        $rideId = (int)($_POST['ride_id'] ?? 0);
        $pickupNote = trim($_POST['pickup_note'] ?? '');

        if ($rideId <= 0) {
            $_SESSION['error_msg'] = "Invalid ride selection.";
            header('Location: index.php');
            exit;
        }

        // Fetch ride details
        $rStmt = $pdo->prepare("SELECT * FROM `Ride` WHERE `RideID` = ?");
        $rStmt->execute([$rideId]);
        $ride = $rStmt->fetch();

        if (!$ride) {
            $_SESSION['error_msg'] = "Ride not found.";
            header('Location: index.php');
            exit;
        }

        if ((int)$ride['DriverID'] === $currentUserId) {
            $_SESSION['error_msg'] = "You cannot join your own ride as a passenger.";
            header("Location: ride_details.php?id=$rideId");
            exit;
        }

        if ($ride['Status'] !== 'Open' || (int)$ride['AvailableSeats'] <= 0) {
            $_SESSION['error_msg'] = "This ride is full or no longer accepting passengers.";
            header("Location: ride_details.php?id=$rideId");
            exit;
        }

        // Ensure Passenger record exists for this user
        $pdo->prepare("INSERT IGNORE INTO `Passenger` (`UserID`, `PassRating`) VALUES (?, 5.00)")->execute([$currentUserId]);

        // Check if existing request exists
        $chkReq = $pdo->prepare("SELECT * FROM `RideRequest` WHERE `RideID` = ? AND `PassengerID` = ?");
        $chkReq->execute([$rideId, $currentUserId]);
        $existingReq = $chkReq->fetch();

        if ($existingReq) {
            if ($existingReq['Status'] === 'Pending') {
                $_SESSION['error_msg'] = "You already have a pending request for this ride.";
            } elseif ($existingReq['Status'] === 'Accepted') {
                $_SESSION['error_msg'] = "You are already an accepted passenger on this ride.";
            } else {
                // Was rejected previously, allow re-requesting
                $pdo->prepare("UPDATE `RideRequest` SET `Status` = 'Pending', `RequestedAt` = NOW() WHERE `RequestID` = ?")
                    ->execute([$existingReq['RequestID']]);
                
                // Notify driver
                $passengerName = $_SESSION['name'] ?? 'A student';
                create_notification($pdo, $ride['DriverID'], 'request', 'New Join Request', "$passengerName requested to join your ride from {$ride['StartLocation']} to {$ride['Destination']}.");
                $_SESSION['success_msg'] = "Join request sent successfully to the driver!";
            }
            header("Location: ride_details.php?id=$rideId");
            exit;
        }

        // Insert new request
        $insStmt = $pdo->prepare("INSERT INTO `RideRequest` (`RideID`, `PassengerID`, `Status`, `RequestedAt`) VALUES (?, ?, 'Pending', NOW())");
        $insStmt->execute([$rideId, $currentUserId]);

        // Notify Driver
        $passengerName = $_SESSION['name'] ?? 'A student';
        create_notification($pdo, $ride['DriverID'], 'request', 'New Join Request', "$passengerName requested to join your ride to {$ride['Destination']}.");

        $_SESSION['success_msg'] = "Join request sent to the driver!";
        header("Location: ride_details.php?id=$rideId");
        exit;

    // -------------------------------------------------------------------------
    // 2. ACCEPT JOIN REQUEST (Driver only)
    // -------------------------------------------------------------------------
    case 'accept_request':
        $requestId = (int)($_POST['request_id'] ?? 0);
        
        $stmt = $pdo->prepare("
            SELECT rr.*, r.DriverID, r.AvailableSeats, r.Status AS RideStatus, r.StartLocation, r.Destination
            FROM `RideRequest` rr
            JOIN `Ride` r ON rr.RideID = r.RideID
            WHERE rr.RequestID = ?
        ");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();

        if (!$req || (int)$req['DriverID'] !== $currentUserId) {
            $_SESSION['error_msg'] = "Unauthorized or request not found.";
            header('Location: my_rides.php');
            exit;
        }

        if ((int)$req['AvailableSeats'] <= 0) {
            $_SESSION['error_msg'] = "Cannot accept: No available seats left on this ride.";
            header('Location: my_rides.php');
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Update request status
            $pdo->prepare("UPDATE `RideRequest` SET `Status` = 'Accepted', `RespondedAt` = NOW() WHERE `RequestID` = ?")
                ->execute([$requestId]);

            // Add to RideParticipant
            $pdo->prepare("INSERT IGNORE INTO `RideParticipant` (`RideID`, `UserID`, `Role`, `ArrivalStatus`, `JoinedAt`) VALUES (?, ?, 'Passenger', 'Pending', NOW())")
                ->execute([$req['RideID'], $req['PassengerID']]);

            // Decrement available seats
            $newSeats = (int)$req['AvailableSeats'] - 1;
            $newStatus = ($newSeats === 0) ? 'Full' : 'Open';
            $pdo->prepare("UPDATE `Ride` SET `AvailableSeats` = ?, `Status` = ? WHERE `RideID` = ?")
                ->execute([$newSeats, $newStatus, $req['RideID']]);

            // Notify Passenger
            create_notification($pdo, $req['PassengerID'], 'accepted', 'Request Accepted! 🎉', "Your request to join the ride from {$req['StartLocation']} to {$req['Destination']} has been accepted.");

            $pdo->commit();
            $_SESSION['success_msg'] = "Passenger accepted! Seat count updated.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_msg'] = "Error accepting request: " . $e->getMessage();
        }

        header('Location: my_rides.php');
        exit;

    // -------------------------------------------------------------------------
    // 3. REJECT JOIN REQUEST (Driver only)
    // -------------------------------------------------------------------------
    case 'reject_request':
        $requestId = (int)($_POST['request_id'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT rr.*, r.DriverID, r.StartLocation, r.Destination
            FROM `RideRequest` rr
            JOIN `Ride` r ON rr.RideID = r.RideID
            WHERE rr.RequestID = ?
        ");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();

        if (!$req || (int)$req['DriverID'] !== $currentUserId) {
            $_SESSION['error_msg'] = "Unauthorized or request not found.";
            header('Location: my_rides.php');
            exit;
        }

        $pdo->prepare("UPDATE `RideRequest` SET `Status` = 'Rejected', `RespondedAt` = NOW() WHERE `RequestID` = ?")
            ->execute([$requestId]);

        // Notify Passenger
        create_notification($pdo, $req['PassengerID'], 'rejected', 'Request Update', "Your request to join the ride to {$req['Destination']} was not accepted.");

        $_SESSION['success_msg'] = "Request rejected.";
        header('Location: my_rides.php');
        exit;

    // -------------------------------------------------------------------------
    // 4. CANCEL REQUEST (Passenger)
    // -------------------------------------------------------------------------
    case 'cancel_request':
        $rideId = (int)($_POST['ride_id'] ?? 0);
        $pdo->prepare("DELETE FROM `RideRequest` WHERE `RideID` = ? AND `PassengerID` = ? AND `Status` = 'Pending'")
            ->execute([$rideId, $currentUserId]);

        $_SESSION['success_msg'] = "Your join request has been cancelled.";
        header("Location: ride_details.php?id=$rideId");
        exit;

    // -------------------------------------------------------------------------
    // 5. LEAVE RIDE (Accepted Passenger)
    // -------------------------------------------------------------------------
    case 'leave_ride':
        $rideId = (int)($_POST['ride_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            // Remove from RideParticipant
            $delStmt = $pdo->prepare("DELETE FROM `RideParticipant` WHERE `RideID` = ? AND `UserID` = ? AND `Role` = 'Passenger'");
            $delStmt->execute([$rideId, $currentUserId]);

            // Update RideRequest
            $pdo->prepare("DELETE FROM `RideRequest` WHERE `RideID` = ? AND `PassengerID` = ?")
                ->execute([$rideId, $currentUserId]);

            // Increment available seats
            $pdo->prepare("UPDATE `Ride` SET `AvailableSeats` = `AvailableSeats` + 1, `Status` = 'Open' WHERE `RideID` = ?")
                ->execute([$rideId]);

            // Notify Driver
            $rStmt = $pdo->prepare("SELECT DriverID, StartLocation, Destination FROM `Ride` WHERE `RideID` = ?");
            $rStmt->execute([$rideId]);
            $ride = $rStmt->fetch();
            if ($ride) {
                $pName = $_SESSION['name'] ?? 'A passenger';
                create_notification($pdo, $ride['DriverID'], 'leave', 'Passenger Left Ride', "$pName left the ride from {$ride['StartLocation']} to {$ride['Destination']}. Seat reopened.");
            }

            $pdo->commit();
            $_SESSION['success_msg'] = "You have left the ride.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_msg'] = "Error leaving ride: " . $e->getMessage();
        }

        header('Location: my_rides.php');
        exit;

    // -------------------------------------------------------------------------
    // 6. CANCEL RIDE (Driver only)
    // -------------------------------------------------------------------------
    case 'cancel_ride':
        $rideId = (int)($_POST['ride_id'] ?? 0);

        $rStmt = $pdo->prepare("SELECT * FROM `Ride` WHERE `RideID` = ? AND `DriverID` = ?");
        $rStmt->execute([$rideId, $currentUserId]);
        $ride = $rStmt->fetch();

        if (!$ride) {
            $_SESSION['error_msg'] = "Unauthorized or ride not found.";
            header('Location: my_rides.php');
            exit;
        }

        if ($ride['Status'] === 'Completed') {
            $_SESSION['error_msg'] = "A completed ride cannot be cancelled.";
            header('Location: my_rides.php');
            exit;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE `Ride` SET `Status` = 'Cancelled' WHERE `RideID` = ?")->execute([$rideId]);

            // Notify all confirmed participants
            $partStmt = $pdo->prepare("SELECT UserID FROM `RideParticipant` WHERE `RideID` = ? AND `Role` = 'Passenger'");
            $partStmt->execute([$rideId]);
            $passengers = $partStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($passengers as $pUid) {
                create_notification($pdo, $pUid, 'cancelled', 'Ride Cancelled ⚠️', "The ride from {$ride['StartLocation']} to {$ride['Destination']} on {$ride['RideDate']} was cancelled by the driver.");
            }

            $pdo->commit();
            $_SESSION['success_msg'] = "Ride has been cancelled.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_msg'] = "Error cancelling ride: " . $e->getMessage();
        }

        header('Location: my_rides.php');
        exit;

    // -------------------------------------------------------------------------
    // 7. CONFIRM ARRIVAL ("Have You Reached Your Destination?")
    // -------------------------------------------------------------------------
    case 'confirm_arrival':
        $rideId = (int)($_POST['ride_id'] ?? 0);
        $arrivalStatus = ($_POST['arrival_status'] ?? '') === 'Reached' ? 'Reached' : 'Not Reached';

        // Check user participation
        $pStmt = $pdo->prepare("SELECT * FROM `RideParticipant` WHERE `RideID` = ? AND `UserID` = ?");
        $pStmt->execute([$rideId, $currentUserId]);
        $participant = $pStmt->fetch();

        if (!$participant) {
            $_SESSION['error_msg'] = "You are not a participant on this ride.";
            header('Location: my_rides.php');
            exit;
        }

        $pdo->prepare("UPDATE `RideParticipant` SET `ArrivalStatus` = ? WHERE `RideID` = ? AND `UserID` = ?")
            ->execute([$arrivalStatus, $rideId, $currentUserId]);

        // Check if all participants (or driver) reached to auto-complete ride
        $allParts = $pdo->prepare("SELECT ArrivalStatus FROM `RideParticipant` WHERE `RideID` = ?");
        $allParts->execute([$rideId]);
        $statuses = $allParts->fetchAll(PDO::FETCH_COLUMN);

        $allReached = true;
        foreach ($statuses as $st) {
            if ($st !== 'Reached') {
                $allReached = false;
                break;
            }
        }

        if ($allReached && count($statuses) > 0) {
            $pdo->prepare("UPDATE `Ride` SET `Status` = 'Completed' WHERE `RideID` = ?")->execute([$rideId]);
            
            // Notify all participants to leave a rating!
            $uStmt = $pdo->prepare("SELECT UserID FROM `RideParticipant` WHERE `RideID` = ?");
            $uStmt->execute([$rideId]);
            $uids = $uStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($uids as $u) {
                create_notification($pdo, $u, 'rate_prompt', 'Rate Your Ride ⭐️', "Your ride has ended! Please take a moment to rate your ride partner.");
            }
        }

        $_SESSION['success_msg'] = "Arrival status updated: " . $arrivalStatus;
        header("Location: my_rides.php?tab=active");
        exit;

    // -------------------------------------------------------------------------
    // 8. SUBMIT TWO-WAY RATING
    // -------------------------------------------------------------------------
    case 'submit_rating':
        $rideId = (int)($_POST['ride_id'] ?? 0);
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $rating = min(5, max(1, (int)($_POST['rating'] ?? 5)));
        $review = trim($_POST['review'] ?? '');

        if ($recipientId === $currentUserId) {
            $_SESSION['error_msg'] = "You cannot rate yourself.";
            header('Location: my_rides.php');
            exit;
        }

        // Verify ride is Completed and both are participants
        $chk1 = $pdo->prepare("SELECT Status FROM `Ride` WHERE `RideID` = ?");
        $chk1->execute([$rideId]);
        $rStatus = $chk1->fetchColumn();

        if ($rStatus !== 'Completed') {
            $_SESSION['error_msg'] = "Ratings can only be submitted for completed rides.";
            header('Location: my_rides.php');
            exit;
        }

        // Check duplicate rating
        $dupChk = $pdo->prepare("SELECT RatingID FROM `Rating` WHERE `RideID` = ? AND `ReviewerID` = ? AND `RecipientID` = ?");
        $dupChk->execute([$rideId, $currentUserId, $recipientId]);
        if ($dupChk->fetch()) {
            $_SESSION['error_msg'] = "You have already submitted a rating for this user on this ride.";
            header('Location: my_rides.php?tab=completed');
            exit;
        }

        // Insert rating
        $stmt = $pdo->prepare("INSERT INTO `Rating` (`RideID`, `ReviewerID`, `RecipientID`, `Rating`, `Review`, `created_at`) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$rideId, $currentUserId, $recipientId, $rating, $review]);

        // Recalculate recipient average rating
        update_user_rating($pdo, $recipientId);

        // Notify recipient
        $reviewerName = $_SESSION['name'] ?? 'A peer';
        create_notification($pdo, $recipientId, 'rating', 'New Rating Received ⭐', "$reviewerName gave you a $rating-star rating for your shared ride.");

        $_SESSION['success_msg'] = "Thank you! Your rating has been submitted.";
        header('Location: my_rides.php?tab=completed');
        exit;

    // -------------------------------------------------------------------------
    // 9. MARK ALL NOTIFICATIONS AS READ
    // -------------------------------------------------------------------------
    case 'mark_notifs_read':
        $pdo->prepare("UPDATE `Notification` SET `IsRead` = 1 WHERE `UserID` = ?")->execute([$currentUserId]);
        $_SESSION['success_msg'] = "All notifications marked as read.";
        header('Location: notifications.php');
        exit;

    default:
        $_SESSION['error_msg'] = "Invalid action specified.";
        header('Location: index.php');
        exit;
}
