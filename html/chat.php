<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_msg'] = "Please log in to access ride chat.";
    header('Location: login.php');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$rideId = (int)($_GET['ride_id'] ?? 0);

if ($rideId <= 0) {
    header('Location: my_rides.php');
    exit;
}

// Fetch Ride Details
$rStmt = $pdo->prepare("SELECT * FROM `Ride` WHERE `RideID` = ?");
$rStmt->execute([$rideId]);
$ride = $rStmt->fetch();

if (!$ride) {
    die("Ride not found.");
}

// Verify User is Driver or Confirmed Passenger
$pStmt = $pdo->prepare("SELECT Role FROM `RideParticipant` WHERE `RideID` = ? AND `UserID` = ?");
$pStmt->execute([$rideId, $currentUserId]);
$myRole = $pStmt->fetch();

if (!$myRole && (int)$ride['DriverID'] !== $currentUserId) {
    $_SESSION['error_msg'] = "You must be a confirmed participant on this ride to view the group chat.";
    header("Location: ride_details.php?id=$rideId");
    exit;
}

// Handle New Message Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $msgText = trim($_POST['message_text'] ?? '');
    if (!empty($msgText)) {
        $ins = $pdo->prepare("INSERT INTO `RideMessage` (`RideID`, `SenderID`, `Message`, `created_at`) VALUES (?, ?, ?, NOW())");
        $ins->execute([$rideId, $currentUserId, $msgText]);

        // Notify other participants
        $recipStmt = $pdo->prepare("SELECT UserID FROM `RideParticipant` WHERE `RideID` = ? AND `UserID` != ?");
        $recipStmt->execute([$rideId, $currentUserId]);
        $recips = $recipStmt->fetchAll(PDO::FETCH_COLUMN);

        $senderName = $_SESSION['name'] ?? 'A member';
        $chatLink = "chat.php?ride_id=" . $rideId;
        foreach ($recips as $rcpId) {
            create_notification($pdo, $rcpId, 'chat', 'New Ride Message 💬', "$senderName: " . substr($msgText, 0, 50), $chatLink);
        }

        header("Location: chat.php?ride_id=$rideId");
        exit;
    }
}

// Fetch Messages
$mStmt = $pdo->prepare("
    SELECT m.*, u.Name AS SenderName, u.UniversityVerified, u.UserType
    FROM `RideMessage` m
    JOIN `User` u ON m.SenderID = u.UserID
    WHERE m.RideID = ?
    ORDER BY m.created_at ASC
");
$mStmt->execute([$rideId]);
$messages = $mStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ride Discussion - <?= htmlspecialchars($ride['StartLocation']) ?> → <?= htmlspecialchars($ride['Destination']) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .chat-container {
            max-width: 750px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            height: calc(80vh - 100px);
        }
        .chat-box {
            flex: 1;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-sm);
        }
        .message-bubble {
            max-width: 75%;
            padding: 0.85rem 1.15rem;
            border-radius: 12px;
            font-size: 0.95rem;
            position: relative;
        }
        .message-bubble.mine {
            align-self: flex-end;
            background: #0284c7;
            color: #ffffff;
            border-bottom-right-radius: 2px;
        }
        .message-bubble.other {
            align-self: flex-start;
            background: #f1f5f9;
            color: var(--text-main);
            border-bottom-left-radius: 2px;
            border: 1px solid var(--border-color);
        }
        .message-sender {
            font-size: 0.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }
        .message-bubble.mine .message-sender {
            color: #bae6fd;
            justify-content: flex-end;
        }
        .message-time {
            font-size: 0.7rem;
            margin-top: 0.35rem;
            opacity: 0.8;
            text-align: right;
        }
        .chat-input-form {
            display: flex;
            gap: 0.75rem;
            background: #ffffff;
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }
    </style>
</head>
<body>
    <?php render_navbar('my_rides'); ?>

    <div class="main-container">
        <div class="chat-container">
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <div>
                    <a href="ride_details.php?id=<?= $rideId ?>" style="color: var(--text-muted); font-size: 0.85rem; text-decoration: none; font-weight: 600;">
                        ← Back to Ride Details
                    </a>
                    <h2 style="font-size: 1.25rem; color: var(--primary); font-weight: 800; margin-top: 0.2rem;">
                        💬 Ride Discussion Board
                    </h2>
                    <p style="font-size: 0.85rem; color: var(--text-muted);">
                        <?= htmlspecialchars($ride['StartLocation']) ?> → <?= htmlspecialchars($ride['Destination']) ?> (<?= format_ride_date($ride['RideDate']) ?>)
                    </p>
                </div>
            </div>

            <!-- Chat Messages Box -->
            <div class="chat-box" id="chatBox">
                <?php if (empty($messages)): ?>
                    <div style="text-align: center; color: var(--text-muted); margin: auto; font-size: 0.9rem;">
                        👋 Start the conversation! Coordinate pickup spots, landmarks, or luggage with your ride partners.
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $msg): 
                        $isMine = (int)$msg['SenderID'] === $currentUserId;
                    ?>
                        <div class="message-bubble <?= $isMine ? 'mine' : 'other' ?>">
                            <div class="message-sender">
                                <?= htmlspecialchars($msg['SenderName']) ?>
                                <?= render_verification_badge($msg['UniversityVerified']) ?>
                            </div>
                            <div>
                                <?= nl2br(htmlspecialchars($msg['Message'])) ?>
                            </div>
                            <div class="message-time">
                                <?= format_message_time($msg['created_at']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Send Form -->
            <form method="POST" class="chat-input-form">
                <input type="hidden" name="send_message" value="1">
                <input type="text" name="message_text" class="form-control" placeholder="Type a message (e.g. I am standing near the main gate)..." required autocomplete="off" style="margin-bottom: 0;">
                <button type="submit" class="btn btn-primary" style="width: auto; padding: 0.75rem 1.5rem;">Send</button>
            </form>

        </div>
    </div>

    <script>
        // Auto scroll to bottom of chat
        var chatBox = document.getElementById('chatBox');
        chatBox.scrollTop = chatBox.scrollHeight;
    </script>

    <?php render_footer(); ?>
</body>
</html>
