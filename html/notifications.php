<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_msg'] = "Please log in to view notifications.";
    header('Location: login.php');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];

$msgSuccess = $_SESSION['success_msg'] ?? '';
$msgError = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Fetch Notifications for Current User
$stmt = $pdo->prepare("SELECT * FROM `Notification` WHERE UserID = ? ORDER BY created_at DESC");
$stmt->execute([$currentUserId]);
$notifications = $stmt->fetchAll();

// Auto mark as read or provide button
$unreadCount = get_unread_notification_count($pdo, $currentUserId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - BRAC University Rideshare</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .notif-container {
            max-width: 750px;
            margin: 0 auto;
        }
        .notif-item {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 0.85rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            transition: all 0.15s ease;
        }
        .notif-item.unread {
            background: #f0f9ff;
            border-left: 4px solid var(--accent);
        }
        .notif-icon-box {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            background: #e2e8f0;
        }
        .notif-item.unread .notif-icon-box {
            background: #bae6fd;
        }
        .notif-body {
            flex: 1;
        }
        .notif-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.25rem;
        }
        .notif-msg {
            font-size: 0.9rem;
            color: #475569;
            margin-bottom: 0.5rem;
        }
        .notif-time {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <?php render_navbar('notifications'); ?>

    <div class="main-container">
        <div class="notif-container">

            <?php if (!empty($msgSuccess)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($msgSuccess) ?></div>
            <?php endif; ?>
            <?php if (!empty($msgError)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($msgError) ?></div>
            <?php endif; ?>

            <div class="section-header">
                <div>
                    <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--primary);">Notifications</h1>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Updates about your ride requests, confirmations, and arrival status.</p>
                </div>
                <?php if ($unreadCount > 0): ?>
                    <div>
                        <form method="POST" action="api_actions.php">
                            <input type="hidden" name="action" value="mark_notifs_read">
                            <button type="submit" class="btn btn-secondary btn-sm">Mark All as Read</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="empty-state-card">
                    <div class="empty-state-icon">🔔</div>
                    <h3>No notifications yet</h3>
                    <p>You will receive updates here whenever someone requests to join your ride, accepts your request, or submits a rating.</p>
                    <div class="empty-state-actions">
                        <a href="index.php" class="btn btn-primary">Find a Ride</a>
                    </div>
                </div>
            <?php else: ?>
                <div>
                    <?php foreach ($notifications as $n): 
                        $icon = '🔔';
                        if ($n['Type'] === 'request') $icon = '📬';
                        elseif ($n['Type'] === 'accepted') $icon = '🎉';
                        elseif ($n['Type'] === 'rejected') $icon = '❌';
                        elseif ($n['Type'] === 'cancelled') $icon = '⚠️';
                        elseif ($n['Type'] === 'rate_prompt' || $n['Type'] === 'rating') $icon = '⭐️';
                        elseif ($n['Type'] === 'leave') $icon = '🚶';
                    ?>
                        <div class="notif-item <?= $n['IsRead'] ? '' : 'unread' ?>">
                            <div class="notif-icon-box">
                                <?= $icon ?>
                            </div>
                            <div class="notif-body">
                                <div class="notif-title"><?= htmlspecialchars($n['Title']) ?></div>
                                <div class="notif-msg"><?= htmlspecialchars($n['Message']) ?></div>
                                <div class="notif-time"><?= date('M j, Y \a\t g:i A', strtotime($n['created_at'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <?php render_footer(); ?>
</body>
</html>
