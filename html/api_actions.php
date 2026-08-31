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
    // 0. OPEN & REDIRECT NOTIFICATION
    // -------------------------------------------------------------------------
    case 'open_notif':
        $notifId = (int)($_GET['id'] ?? 0);
        $target = 'my_rides.php';
        if ($notifId > 0 && $currentUserId > 0) {
            $nStmt = $pdo->prepare("SELECT * FROM `Notification` WHERE `NotificationID` = ? AND `UserID` = ?");
            $nStmt->execute([$notifId, $currentUserId]);
            $notif = $nStmt->fetch();
            
            $pdo->prepare("UPDATE `Notification` SET `IsRead` = 1 WHERE `NotificationID` = ? AND `UserID` = ?")->execute([$notifId, $currentUserId]);
            
            if ($notif && !empty($notif['Link'])) {
                $target = $notif['Link'];
            } elseif ($notif) {
                $type = $notif['Type'];
                $title = $notif['Title'];

                if ($type === 'chat' || strpos($title, 'Message') !== false) {
                    $rId = $pdo->query("SELECT RideID FROM `RideParticipant` WHERE UserID = $currentUserId ORDER BY JoinedAt DESC LIMIT 1")->fetchColumn();
                    $target = $rId ? "chat.php?ride_id=$rId" : "my_rides.php";
                } elseif ($type === 'request' || strpos($title, 'Request') !== false) {
                    $rId = $pdo->query("SELECT RideID FROM `Ride` WHERE DriverID = $currentUserId ORDER BY RideDate DESC, DepartureTime DESC LIMIT 1")->fetchColumn();
                    $target = $rId ? "ride_details.php?id=$rId" : "my_rides.php";
                } elseif ($type === 'accepted') {
                    $rId = $pdo->query("SELECT RideID FROM `RideParticipant` WHERE UserID = $currentUserId AND Role = 'Passenger' ORDER BY JoinedAt DESC LIMIT 1")->fetchColumn();
                    $target = $rId ? "ride_details.php?id=$rId" : "my_rides.php";
                } elseif (strpos($type, 'lost_found') !== false) {
                    $target = "lost_found.php";
                } else {
                    $target = "my_rides.php";
                }
            }
        }
        header("Location: " . $target);
        exit;

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

        $userRole = $_SESSION['user_type'] ?? 'Passenger';
        if ($userRole === 'Admin') {
            $_SESSION['error_msg'] = "🛡️ Administrator accounts cannot join rides as passengers.";
            header("Location: ride_details.php?id=$rideId");
            exit;
        }

        // Check if user is banned
        $banCheck = $pdo->prepare("SELECT IsBanned FROM `User` WHERE UserID = ?");
        $banCheck->execute([$currentUserId]);
        if ($banCheck->fetchColumn()) {
            $_SESSION['error_msg'] = "Your account is suspended. You cannot request to join rides.";
            header("Location: ride_details.php?id=$rideId");
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

        // Strict Gender Check for Women-Only Rides
        if (!empty($ride['IsWomenOnly'])) {
            $uStmt = $pdo->prepare("SELECT Gender FROM `User` WHERE UserID = ?");
            $uStmt->execute([$currentUserId]);
            $userGender = $uStmt->fetchColumn();

            if ($userGender !== 'Female') {
                $_SESSION['error_msg'] = "🌸 This ride is designated as Women-Only. Only female university members can request to join.";
                header("Location: ride_details.php?id=$rideId");
                exit;
            }
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
                create_notification($pdo, $ride['DriverID'], 'request', 'New Join Request', "$passengerName requested to join your ride from {$ride['StartLocation']} to {$ride['Destination']}.", "ride_details.php?id=$rideId");
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
        create_notification($pdo, $ride['DriverID'], 'request', 'New Join Request', "$passengerName requested to join your ride to {$ride['Destination']}.", "ride_details.php?id=$rideId");

        $_SESSION['success_msg'] = "Join request sent to the driver!";
        header("Location: ride_details.php?id=$rideId");
        exit;

    // -------------------------------------------------------------------------
    // 2. ACCEPT JOIN REQUEST (Driver only)
    // -------------------------------------------------------------------------
    case 'accept_request':
        $requestId = (int)($_POST['request_id'] ?? 0);
        
        $stmt = $pdo->prepare("
            SELECT rr.*, r.DriverID, r.AvailableSeats, r.Status AS RideStatus, r.StartLocation, r.Destination, r.IsWomenOnly
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

        // Verify Passenger Gender for Women-Only Ride
        if (!empty($req['IsWomenOnly'])) {
            $pGenStmt = $pdo->prepare("SELECT Gender FROM `User` WHERE UserID = ?");
            $pGenStmt->execute([$req['PassengerID']]);
            $pGender = $pGenStmt->fetchColumn();
            if ($pGender !== 'Female') {
                $_SESSION['error_msg'] = "Cannot accept male passengers on a Women-Only carpool.";
                header('Location: my_rides.php');
                exit;
            }
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
            create_notification($pdo, $req['PassengerID'], 'accepted', 'Request Accepted! 🎉', "Your request to join the ride from {$req['StartLocation']} to {$req['Destination']} has been accepted.", "ride_details.php?id=" . $req['RideID']);

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
        create_notification($pdo, $req['PassengerID'], 'rejected', 'Request Update', "Your request to join the ride to {$req['Destination']} was not accepted.", "index.php");

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
                create_notification($pdo, $ride['DriverID'], 'leave', 'Passenger Left Ride', "$pName left the ride from {$ride['StartLocation']} to {$ride['Destination']}. Seat reopened.", "ride_details.php?id=$rideId");
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
            // Notify all confirmed participants & pending requesters
            $recipients = [];
            $partStmt = $pdo->prepare("SELECT UserID FROM `RideParticipant` WHERE `RideID` = ? AND `Role` = 'Passenger'");
            $partStmt->execute([$rideId]);
            while ($uid = $partStmt->fetchColumn()) {
                $recipients[] = (int)$uid;
            }

            $reqStmt = $pdo->prepare("SELECT PassengerID FROM `RideRequest` WHERE `RideID` = ? AND `Status` = 'Pending'");
            $reqStmt->execute([$rideId]);
            while ($uid = $reqStmt->fetchColumn()) {
                if (!in_array((int)$uid, $recipients)) {
                    $recipients[] = (int)$uid;
                }
            }

            $driverName = $_SESSION['name'] ?? 'The driver';
            foreach ($recipients as $pUid) {
                create_notification(
                    $pdo, 
                    $pUid, 
                    'cancelled', 
                    'Ride Cancelled ⚠️', 
                    "The ride from {$ride['StartLocation']} to {$ride['Destination']} on {$ride['RideDate']} was cancelled by the driver ({$driverName}).", 
                    "index.php"
                );
            }

            $pdo->commit();
            $_SESSION['success_msg'] = "Ride has been cancelled. " . (count($recipients) > 0 ? count($recipients) . " passenger(s) notified." : "");
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
        $redirect = trim($_POST['redirect'] ?? '');

        // Fetch ride details
        $rStmt = $pdo->prepare("SELECT * FROM `Ride` WHERE `RideID` = ?");
        $rStmt->execute([$rideId]);
        $ride = $rStmt->fetch();

        if (!$ride) {
            $_SESSION['error_msg'] = "Ride not found.";
            header('Location: my_rides.php');
            exit;
        }

        // Check user participation
        $pStmt = $pdo->prepare("SELECT * FROM `RideParticipant` WHERE `RideID` = ? AND `UserID` = ?");
        $pStmt->execute([$rideId, $currentUserId]);
        $participant = $pStmt->fetch();

        if (!$participant && (int)$ride['DriverID'] === $currentUserId) {
            // Auto-register driver in RideParticipant if missing
            $pdo->prepare("INSERT INTO `RideParticipant` (`RideID`, `UserID`, `Role`, `ArrivalStatus`, `JoinedAt`) VALUES (?, ?, 'Driver', ?, NOW()) ON DUPLICATE KEY UPDATE ArrivalStatus = ?")
                ->execute([$rideId, $currentUserId, $arrivalStatus, $arrivalStatus]);
            $participant = ['Role' => 'Driver', 'ArrivalStatus' => $arrivalStatus];
        }

        if (!$participant) {
            $_SESSION['error_msg'] = "You are not a participant on this ride.";
            header('Location: my_rides.php');
            exit;
        }

        $pdo->prepare("UPDATE `RideParticipant` SET `ArrivalStatus` = ? WHERE `RideID` = ? AND `UserID` = ?")
            ->execute([$arrivalStatus, $rideId, $currentUserId]);

        // If driver marks Reached, auto-mark ride as Completed
        if ($participant['Role'] === 'Driver' && $arrivalStatus === 'Reached') {
            $pdo->prepare("UPDATE `Ride` SET `Status` = 'Completed' WHERE `RideID` = ?")->execute([$rideId]);
        }

        // Check if all participants reached or if driver has ended the ride
        $allParts = $pdo->prepare("SELECT ArrivalStatus, Role, UserID FROM `RideParticipant` WHERE `RideID` = ?");
        $allParts->execute([$rideId]);
        $participantsList = $allParts->fetchAll();

        $allReached = true;
        foreach ($participantsList as $p) {
            if ($p['ArrivalStatus'] !== 'Reached') {
                $allReached = false;
                break;
            }
        }

        if ($allReached && count($participantsList) > 0) {
            $pdo->prepare("UPDATE `Ride` SET `Status` = 'Completed' WHERE `RideID` = ?")->execute([$rideId]);
        }

        // If passenger confirmed arrival, notify the driver
        if ($participant['Role'] === 'Passenger' && $arrivalStatus === 'Reached') {
            $passengerName = $_SESSION['name'] ?? 'A passenger';
            create_notification(
                $pdo,
                $ride['DriverID'],
                'passenger_reached',
                'Passenger Reached Destination 📍',
                "{$passengerName} has confirmed arrival at the destination for your ride from {$ride['StartLocation']} to {$ride['Destination']}.",
                "ride_details.php?id=$rideId"
            );
        }

        if ($arrivalStatus === 'Reached') {
            $_SESSION['success_msg'] = "✓ You have confirmed your arrival! The ride is now listed in your Completed rides.";
            $targetUrl = !empty($redirect) ? $redirect : "my_rides.php?tab=completed";
        } else {
            $_SESSION['success_msg'] = "Arrival status updated.";
            $targetUrl = !empty($redirect) ? $redirect : "my_rides.php?tab=active";
        }

        header("Location: $targetUrl");
        exit;

    // -------------------------------------------------------------------------
    // 7B. DRIVER END RIDE ("Driver Reached Destination & Ended Ride")
    // -------------------------------------------------------------------------
    case 'driver_end_ride':
        $rideId = (int)($_POST['ride_id'] ?? 0);
        $redirect = trim($_POST['redirect'] ?? '');

        // Fetch ride details
        $rStmt = $pdo->prepare("SELECT * FROM `Ride` WHERE `RideID` = ? AND `DriverID` = ?");
        $rStmt->execute([$rideId, $currentUserId]);
        $ride = $rStmt->fetch();

        if (!$ride) {
            $_SESSION['error_msg'] = "Ride not found or you are not the driver of this ride.";
            header('Location: my_rides.php');
            exit;
        }

        if ($ride['Status'] === 'Cancelled') {
            $_SESSION['error_msg'] = "Cannot end a cancelled ride.";
            header('Location: my_rides.php');
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Set ride status to Completed
            $pdo->prepare("UPDATE `Ride` SET `Status` = 'Completed' WHERE `RideID` = ?")->execute([$rideId]);

            // Ensure Driver is recorded as Reached in RideParticipant
            $pdo->prepare("INSERT INTO `RideParticipant` (`RideID`, `UserID`, `Role`, `ArrivalStatus`, `JoinedAt`) VALUES (?, ?, 'Driver', 'Reached', NOW()) ON DUPLICATE KEY UPDATE ArrivalStatus = 'Reached'")
                ->execute([$rideId, $currentUserId]);

            // Notify all joined passengers that ride has ended and to rate the driver
            $pStmt = $pdo->prepare("SELECT UserID FROM `RideParticipant` WHERE `RideID` = ? AND `Role` = 'Passenger'");
            $pStmt->execute([$rideId]);
            $passengers = $pStmt->fetchAll(PDO::FETCH_COLUMN);

            $driverName = $_SESSION['name'] ?? 'Your driver';
            foreach ($passengers as $pUid) {
                create_notification(
                    $pdo,
                    $pUid,
                    'rate_prompt',
                    'Ride Completed 🏁',
                    "{$driverName} reached the destination and ended the ride ({$ride['StartLocation']} → {$ride['Destination']}). Please confirm arrival and rate your trip!",
                    "ride_details.php?id=$rideId"
                );
            }

            $pdo->commit();
            $_SESSION['success_msg'] = "🏁 Ride ended successfully! It is now marked as Completed.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_msg'] = "Failed to end ride: " . $e->getMessage();
        }

        $targetUrl = !empty($redirect) ? $redirect : "my_rides.php?tab=completed";
        header("Location: $targetUrl");
        exit;

    // -------------------------------------------------------------------------
    // 7C. MAKE PAYMENT (Passenger pays for ride)
    // -------------------------------------------------------------------------
    case 'make_payment':
        $rideId = (int)($_POST['ride_id'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'bKash');
        $redirect = trim($_POST['redirect'] ?? '');

        // Fetch ride details
        $rStmt = $pdo->prepare("SELECT * FROM `Ride` WHERE `RideID` = ?");
        $rStmt->execute([$rideId]);
        $ride = $rStmt->fetch();

        if (!$ride) {
            $_SESSION['error_msg'] = "Ride not found.";
            header('Location: my_rides.php');
            exit;
        }

        // Verify that current user is an accepted passenger
        $pStmt = $pdo->prepare("SELECT * FROM `RideParticipant` WHERE `RideID` = ? AND `UserID` = ? AND `Role` = 'Passenger'");
        $pStmt->execute([$rideId, $currentUserId]);
        $participant = $pStmt->fetch();

        if (!$participant) {
            $_SESSION['error_msg'] = "You are not a registered passenger on this ride.";
            header("Location: ride_details.php?id=$rideId");
            exit;
        }

        $fareAmount = floatval($ride['SharedCost']);

        $pdo->beginTransaction();
        try {
            // Update RideParticipant PaymentStatus
            $upStmt = $pdo->prepare("
                UPDATE `RideParticipant` 
                SET `PaymentStatus` = 'Paid',
                    `PaymentMethod` = ?,
                    `PaidAmount` = ?,
                    `PaidAt` = NOW()
                WHERE `RideID` = ? AND `UserID` = ?
            ");
            $upStmt->execute([$paymentMethod, $fareAmount, $rideId, $currentUserId]);

            // Notify the driver that passenger made payment
            $passengerName = $_SESSION['name'] ?? 'A passenger';
            create_notification(
                $pdo,
                $ride['DriverID'],
                'payment_received',
                'Payment Received ৳',
                "{$passengerName} has paid ৳" . number_format($fareAmount, 0) . " ({$paymentMethod}) for your ride from {$ride['StartLocation']} to {$ride['Destination']}.",
                "ride_details.php?id=$rideId"
            );

            $pdo->commit();
            $_SESSION['success_msg'] = "💳 Payment of ৳" . number_format($fareAmount, 0) . " recorded successfully via {$paymentMethod}! The driver has been notified.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_msg'] = "Failed to record payment: " . $e->getMessage();
        }

        $targetUrl = !empty($redirect) ? $redirect : "ride_details.php?id=$rideId";
        header("Location: $targetUrl");
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
        create_notification($pdo, $recipientId, 'rating', 'New Rating Received ⭐', "$reviewerName gave you a $rating-star rating for your shared ride.", "profile.php?id=$recipientId");

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

    // -------------------------------------------------------------------------
    // 10. REPORT LOST OR FOUND ITEM
    // -------------------------------------------------------------------------
    case 'report_lost_item':
        $reportType = in_array($_POST['report_type'] ?? '', ['Lost', 'Found']) ? $_POST['report_type'] : 'Lost';
        $itemName = trim($_POST['item_name'] ?? '');
        $category = trim($_POST['category'] ?? 'Other');
        $description = trim($_POST['description'] ?? '');
        $locationDetails = trim($_POST['location_details'] ?? '');
        $dateLostFound = trim($_POST['date_lost_found'] ?? date('Y-m-d'));
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $rideId = (int)($_POST['ride_id'] ?? 0);
        $rideId = ($rideId > 0) ? $rideId : null;

        $validCategories = ['Electronics', 'Student ID & Cards', 'Bags & Wallets', 'Keys', 'Clothing & Accessories', 'Books & Documents', 'Other'];
        if (!in_array($category, $validCategories)) {
            $category = 'Other';
        }

        if (empty($itemName) || empty($description) || empty($locationDetails)) {
            $_SESSION['error_msg'] = "Please provide an item name, description, and location.";
            header('Location: lost_found.php');
            exit;
        }

        try {
            $insStmt = $pdo->prepare("
                INSERT INTO `LostItem` 
                (`ReportType`, `ItemName`, `Category`, `Description`, `RideID`, `LocationDetails`, `DateLostFound`, `ContactPhone`, `PosterID`, `Status`, `created_at`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', NOW())
            ");
            $insStmt->execute([
                $reportType,
                $itemName,
                $category,
                $description,
                $rideId,
                $locationDetails,
                $dateLostFound,
                $contactPhone,
                $currentUserId
            ]);

            $newItemId = $pdo->lastInsertId();
            $posterName = $_SESSION['name'] ?? 'A student';

            // If tied to a specific ride, notify all participants of that ride
            if ($rideId) {
                $rStmt = $pdo->prepare("SELECT StartLocation, Destination, DriverID FROM `Ride` WHERE `RideID` = ?");
                $rStmt->execute([$rideId]);
                $ride = $rStmt->fetch();

                if ($ride) {
                    $pStmt = $pdo->prepare("SELECT UserID FROM `RideParticipant` WHERE `RideID` = ? AND `UserID` != ?");
                    $pStmt->execute([$rideId, $currentUserId]);
                    $recipients = $pStmt->fetchAll(PDO::FETCH_COLUMN);

                    // Include driver if not already in participants and not current user
                    if ((int)$ride['DriverID'] !== $currentUserId && !in_array($ride['DriverID'], $recipients)) {
                        $recipients[] = $ride['DriverID'];
                    }

                    $tag = ($reportType === 'Found') ? '🟢 Found Item Alert' : '🔴 Lost Item Alert';
                    foreach ($recipients as $uid) {
                        create_notification(
                            $pdo, 
                            $uid, 
                            'lost_found', 
                            "$tag: $itemName", 
                            "$posterName reported a $reportType item on your shared ride ({$ride['StartLocation']} → {$ride['Destination']}). Check Lost & Found to coordinate return.",
                            "lost_found.php?item_id=$newItemId"
                        );
                    }
                }
            }

            $_SESSION['success_msg'] = ($reportType === 'Found' ? "Found item report published successfully!" : "Lost item report posted. We hope you recover it soon!");
            header("Location: lost_found.php?item_id=$newItemId");
            exit;
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Failed to report item: " . $e->getMessage();
            header('Location: lost_found.php');
            exit;
        }

    // -------------------------------------------------------------------------
    // 11. CLAIM LOST / FOUND ITEM OR POST INQUIRY COMMENT
    // -------------------------------------------------------------------------
    case 'add_lost_comment':
        $itemId = (int)($_POST['item_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        $isClaim = isset($_POST['is_claim']) && (int)$_POST['is_claim'] === 1 ? 1 : 0;

        if ($itemId <= 0 || empty($message)) {
            $_SESSION['error_msg'] = "Please provide a message or claim details.";
            header("Location: lost_found.php");
            exit;
        }

        // Fetch item details
        $itemStmt = $pdo->prepare("SELECT * FROM `LostItem` WHERE `ItemID` = ?");
        $itemStmt->execute([$itemId]);
        $item = $itemStmt->fetch();

        if (!$item) {
            $_SESSION['error_msg'] = "Item report not found.";
            header("Location: lost_found.php");
            exit;
        }

        try {
            $cStmt = $pdo->prepare("INSERT INTO `LostItemComment` (`ItemID`, `UserID`, `Message`, `IsClaim`, `created_at`) VALUES (?, ?, ?, ?, NOW())");
            $cStmt->execute([$itemId, $currentUserId, $message, $isClaim]);

            // If isClaim and item is open, update status to Claimed
            if ($isClaim && $item['Status'] === 'Open') {
                $pdo->prepare("UPDATE `LostItem` SET `Status` = 'Claimed' WHERE `ItemID` = ?")->execute([$itemId]);
            }

            // Notify item poster
            if ((int)$item['PosterID'] !== $currentUserId) {
                $senderName = $_SESSION['name'] ?? 'A member';
                $title = $isClaim ? "📦 Item Claim Request: {$item['ItemName']}" : "💬 New Comment on {$item['ItemName']}";
                $notifMsg = $isClaim 
                    ? "$senderName has submitted an ownership claim for '{$item['ItemName']}': \"$message\""
                    : "$senderName commented on your report for '{$item['ItemName']}': \"$message\"";

                create_notification($pdo, $item['PosterID'], 'lost_found_comment', $title, $notifMsg, "lost_found.php?item_id=$itemId#discussion");
            }

            $_SESSION['success_msg'] = $isClaim ? "Claim request sent to the reporter!" : "Message posted.";
            header("Location: lost_found.php?item_id=$itemId#discussion");
            exit;
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Failed to post message: " . $e->getMessage();
            header("Location: lost_found.php?item_id=$itemId");
            exit;
        }

    // -------------------------------------------------------------------------
    // 12. RESOLVE / MARK AS RETURNED (Poster or Admin)
    // -------------------------------------------------------------------------
    case 'resolve_lost_item':
        $itemId = (int)($_POST['item_id'] ?? 0);
        $resolvedById = (int)($_POST['resolved_by_user_id'] ?? 0);
        $notes = trim($_POST['resolution_notes'] ?? 'Returned / Recovered');

        $itemStmt = $pdo->prepare("SELECT * FROM `LostItem` WHERE `ItemID` = ?");
        $itemStmt->execute([$itemId]);
        $item = $itemStmt->fetch();

        $userRole = $_SESSION['user_type'] ?? 'Passenger';
        $isAdmin = ($userRole === 'Admin');

        if (!$item || ((int)$item['PosterID'] !== $currentUserId && !$isAdmin)) {
            $_SESSION['error_msg'] = "Unauthorized to resolve this item.";
            header("Location: lost_found.php");
            exit;
        }

        try {
            $resUser = ($resolvedById > 0) ? $resolvedById : $currentUserId;
            $pdo->prepare("
                UPDATE `LostItem` 
                SET `Status` = 'Resolved', `ResolvedBy` = ?, `ResolutionNotes` = ?, `updated_at` = NOW() 
                WHERE `ItemID` = ?
            ")->execute([$resUser, $notes, $itemId]);

            // Notify claimant if resolved with another user
            if ($resolvedById > 0 && $resolvedById !== $currentUserId) {
                create_notification(
                    $pdo, 
                    $resolvedById, 
                    'lost_found_resolved', 
                    "🎉 Item Marked Resolved: {$item['ItemName']}", 
                    "The report for '{$item['ItemName']}' has been marked as resolved and safely returned!",
                    "lost_found.php?item_id=$itemId"
                );
            }

            $_SESSION['success_msg'] = "Item successfully marked as Resolved & Returned!";
            header("Location: lost_found.php?item_id=$itemId");
            exit;
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Error updating status: " . $e->getMessage();
            header("Location: lost_found.php?item_id=$itemId");
            exit;
        }

    // -------------------------------------------------------------------------
    // 13. DELETE LOST & FOUND REPORT
    // -------------------------------------------------------------------------
    case 'delete_lost_item':
        $itemId = (int)($_POST['item_id'] ?? 0);

        $itemStmt = $pdo->prepare("SELECT * FROM `LostItem` WHERE `ItemID` = ?");
        $itemStmt->execute([$itemId]);
        $item = $itemStmt->fetch();

        $userRole = $_SESSION['user_type'] ?? 'Passenger';
        $isAdmin = ($userRole === 'Admin');

        if (!$item || ((int)$item['PosterID'] !== $currentUserId && !$isAdmin)) {
            $_SESSION['error_msg'] = "Unauthorized to delete this report.";
            header("Location: lost_found.php");
            exit;
        }

        try {
            $pdo->prepare("DELETE FROM `LostItem` WHERE `ItemID` = ?")->execute([$itemId]);
            $_SESSION['success_msg'] = "Report removed successfully.";
            header("Location: " . (!empty($_POST['redirect']) ? $_POST['redirect'] : "lost_found.php"));
            exit;
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Error deleting report: " . $e->getMessage();
            header("Location: lost_found.php");
            exit;
        }

    // -------------------------------------------------------------------------
    // 14. ADMIN: BAN USER
    // -------------------------------------------------------------------------
    case 'admin_ban_user':
        $userRole = $_SESSION['user_type'] ?? 'Passenger';
        if ($userRole !== 'Admin') {
            $_SESSION['error_msg'] = "Unauthorized: Admin privileges required.";
            header('Location: index.php');
            exit;
        }

        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            $_SESSION['error_msg'] = "Invalid user ID.";
            header('Location: admin.php?tab=users');
            exit;
        }

        if ($targetUserId === $currentUserId) {
            $_SESSION['error_msg'] = "You cannot ban your own administrator account.";
            header('Location: admin.php?tab=users');
            exit;
        }

        // Verify target is not an admin
        $targetUser = $pdo->prepare("SELECT Name, UserType FROM `User` WHERE UserID = ?");
        $targetUser->execute([$targetUserId]);
        $target = $targetUser->fetch();

        if (!$target) {
            $_SESSION['error_msg'] = "User not found.";
            header('Location: admin.php?tab=users');
            exit;
        }

        if ($target['UserType'] === 'Admin') {
            $_SESSION['error_msg'] = "Cannot ban an administrator.";
            header('Location: admin.php?tab=users');
            exit;
        }

        try {
            $pdo->prepare("UPDATE `User` SET `IsBanned` = 1 WHERE `UserID` = ?")->execute([$targetUserId]);
            create_notification($pdo, $targetUserId, 'admin_notice', 'Account Suspended', 'Your BRAC University Rideshare account has been suspended by an administrator.', 'login.php');
            $_SESSION['success_msg'] = "User '{$target['Name']}' (#$targetUserId) has been banned.";
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Failed to ban user: " . $e->getMessage();
        }

        header('Location: admin.php?tab=users');
        exit;

    // -------------------------------------------------------------------------
    // 15. ADMIN: UNBAN USER
    // -------------------------------------------------------------------------
    case 'admin_unban_user':
        $userRole = $_SESSION['user_type'] ?? 'Passenger';
        if ($userRole !== 'Admin') {
            $_SESSION['error_msg'] = "Unauthorized: Admin privileges required.";
            header('Location: index.php');
            exit;
        }

        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            $_SESSION['error_msg'] = "Invalid user ID.";
            header('Location: admin.php?tab=users');
            exit;
        }

        $targetUser = $pdo->prepare("SELECT Name FROM `User` WHERE UserID = ?");
        $targetUser->execute([$targetUserId]);
        $target = $targetUser->fetch();

        try {
            $pdo->prepare("UPDATE `User` SET `IsBanned` = 0 WHERE `UserID` = ?")->execute([$targetUserId]);
            create_notification($pdo, $targetUserId, 'admin_notice', 'Account Restored', 'Your account suspension has been lifted by an administrator.', 'index.php');
            $_SESSION['success_msg'] = "User '{$target['Name']}' (#$targetUserId) has been unbanned and restored.";
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Failed to unban user: " . $e->getMessage();
        }

        header('Location: admin.php?tab=users');
        exit;

    // -------------------------------------------------------------------------
    // 16. ADMIN: END RIDE
    // -------------------------------------------------------------------------
    case 'admin_end_ride':
        $userRole = $_SESSION['user_type'] ?? 'Passenger';
        if ($userRole !== 'Admin') {
            $_SESSION['error_msg'] = "Unauthorized: Admin privileges required.";
            header('Location: index.php');
            exit;
        }

        $rideId = (int)($_POST['ride_id'] ?? 0);
        $redirect = trim($_POST['redirect'] ?? 'admin.php?tab=rides');

        $rStmt = $pdo->prepare("SELECT * FROM `Ride` WHERE `RideID` = ?");
        $rStmt->execute([$rideId]);
        $ride = $rStmt->fetch();

        if (!$ride) {
            $_SESSION['error_msg'] = "Ride not found.";
            header("Location: $redirect");
            exit;
        }

        try {
            $pdo->prepare("UPDATE `Ride` SET `Status` = 'Completed' WHERE `RideID` = ?")->execute([$rideId]);

            // Notify driver
            create_notification($pdo, $ride['DriverID'], 'admin_notice', 'Ride Ended by Admin 🏁', "Your ride from {$ride['StartLocation']} to {$ride['Destination']} has been marked as Completed by platform administration.", "ride_details.php?id=$rideId");

            // Notify all participants
            $partStmt = $pdo->prepare("SELECT UserID FROM `RideParticipant` WHERE `RideID` = ? AND `Role` = 'Passenger'");
            $partStmt->execute([$rideId]);
            $passengers = $partStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($passengers as $pUid) {
                create_notification($pdo, $pUid, 'rate_prompt', 'Ride Completed 🏁', "Your ride from {$ride['StartLocation']} to {$ride['Destination']} has ended. Please rate your driver.", "ride_details.php?id=$rideId");
            }

            $_SESSION['success_msg'] = "Ride #$rideId has been ended and marked as Completed.";
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Failed to end ride: " . $e->getMessage();
        }

        header("Location: $redirect");
        exit;

    // -------------------------------------------------------------------------
    // 17. ADMIN: DELETE RIDE
    // -------------------------------------------------------------------------
    case 'admin_delete_ride':
        $userRole = $_SESSION['user_type'] ?? 'Passenger';
        if ($userRole !== 'Admin') {
            $_SESSION['error_msg'] = "Unauthorized: Admin privileges required.";
            header('Location: index.php');
            exit;
        }

        $rideId = (int)($_POST['ride_id'] ?? 0);

        $rStmt = $pdo->prepare("SELECT * FROM `Ride` WHERE `RideID` = ?");
        $rStmt->execute([$rideId]);
        $ride = $rStmt->fetch();

        if (!$ride) {
            $_SESSION['error_msg'] = "Ride not found.";
            header('Location: admin.php?tab=rides');
            exit;
        }

        try {
            $pdo->prepare("DELETE FROM `Ride` WHERE `RideID` = ?")->execute([$rideId]);
            $_SESSION['success_msg'] = "Ride #$rideId ({$ride['StartLocation']} → {$ride['Destination']}) permanently deleted.";
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Failed to delete ride: " . $e->getMessage();
        }

        header('Location: admin.php?tab=rides');
        exit;

    // -------------------------------------------------------------------------
    // 18. ADMIN: DELETE LOST & FOUND SUBMISSION
    // -------------------------------------------------------------------------
    case 'admin_delete_lost_item':
        $userRole = $_SESSION['user_type'] ?? 'Passenger';
        $isAdmin = ($userRole === 'Admin');

        $itemId = (int)($_POST['item_id'] ?? 0);
        $redirect = trim($_POST['redirect'] ?? ($isAdmin ? 'admin.php?tab=lost_found' : 'lost_found.php'));

        $itemStmt = $pdo->prepare("SELECT * FROM `LostItem` WHERE `ItemID` = ?");
        $itemStmt->execute([$itemId]);
        $item = $itemStmt->fetch();

        if (!$item || ((int)$item['PosterID'] !== $currentUserId && !$isAdmin)) {
            $_SESSION['error_msg'] = "Unauthorized to delete this lost & found report.";
            header("Location: $redirect");
            exit;
        }

        try {
            $pdo->prepare("DELETE FROM `LostItem` WHERE `ItemID` = ?")->execute([$itemId]);
            $_SESSION['success_msg'] = "Lost & Found submission for '{$item['ItemName']}' removed successfully.";
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Failed to remove submission: " . $e->getMessage();
        }

        header("Location: $redirect");
        exit;

    default:
        $_SESSION['error_msg'] = "Invalid action specified.";
        header('Location: index.php');
        exit;
}
