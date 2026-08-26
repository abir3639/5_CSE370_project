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
            max-width: 800px;
            margin: 0 auto;
        }
        .notif-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem 1.4rem;
            margin-bottom: 0.85rem;
            display: flex;
            align-items: center;
            gap: 1.15rem;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            box-shadow: var(--shadow-sm);
        }
        .notif-card:hover {
            transform: translateY(-2px);
            border-color: #0284c7;
            box-shadow: 0 6px 18px rgba(2, 132, 199, 0.1);
        }
        .notif-card.unread {
            background: #f0f9ff;
            border-color: #bae6fd;
            border-left: 5px solid #0284c7;
        }
        .notif-icon-box {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
            background: #f1f5f9;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05);
        }
        .notif-card.unread .notif-icon-box {
            background: #e0f2fe;
            color: #0284c7;
        }
        .notif-body {
            flex: 1;
            overflow: hidden;
        }
        .notif-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
        }
        .notif-title {
            font-size: 1rem;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .notif-unread-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #0284c7;
            display: inline-block;
        }
        .notif-time {
            font-size: 0.75rem;
            color: var(--text-muted);
            white-space: nowrap;
        }
        .notif-msg {
            font-size: 0.9rem;
            color: #475569;
            margin-bottom: 0.4rem;
            line-height: 1.4;
        }
        .notif-action-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.78rem;
            font-weight: 700;
            color: #0284c7;
            background: #f0f9ff;
            padding: 0.25rem 0.65rem;
            border-radius: 6px;
            border: 1px solid #bae6fd;
            transition: all 0.15s ease;
        }
        .notif-card:hover .notif-action-badge {
            background: #0284c7;
            color: #ffffff;
            border-color: #0284c7;
        }
        .notif-arrow {
            font-size: 1.25rem;
            color: #94a3b8;
            transition: transform 0.15s ease, color 0.15s ease;
            margin-left: 0.5rem;
        }
        .notif-card:hover .notif-arrow {
            transform: translateX(3px);
            color: #0284c7;
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
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Click any notification to go directly to the chat, ride details, or report.</p>
                </div>
                <?php if ($unreadCount > 0): ?>
                    <div>
                        <form method="POST" action="api_actions.php">
                            <input type="hidden" name="action" value="mark_notifs_read">
                            <button type="submit" class="btn btn-secondary btn-sm">Mark All as Read (<?= $unreadCount ?>)</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="empty-state-card">
                    <div class="empty-state-icon">🔔</div>
                    <h3>No notifications yet</h3>
                    <p>You will receive real-time alerts here for new chat messages, ride requests, driver responses, and lost & found coordination.</p>
                    <div class="empty-state-actions">
                        <a href="index.php" class="btn btn-primary">Find a Ride</a>
                    </div>
                </div>
            <?php else: ?>
                <div>
                    <?php foreach ($notifications as $n): 
                        $icon = '🔔';
                        $actionText = 'View Details ›';
                        $targetUrl = !empty($n['Link']) ? $n['Link'] : 'my_rides.php';

                        if ($n['Type'] === 'chat') {
                            $icon = '💬';
                            $actionText = '💬 Open Ride Chat ›';
                        } elseif ($n['Type'] === 'request') {
                            $icon = '📬';
                            $actionText = '🚗 Review Request ›';
                        } elseif ($n['Type'] === 'accepted') {
                            $icon = '🎉';
                            $actionText = '🚗 View Ride Details ›';
                        } elseif ($n['Type'] === 'rejected') {
                            $icon = '❌';
                            $actionText = '🔍 Search Other Rides ›';
                        } elseif ($n['Type'] === 'cancelled') {
                            $icon = '⚠️';
                            $actionText = '🔍 Find Alternative Ride ›';
                        } elseif ($n['Type'] === 'rate_prompt') {
                            $icon = '⭐️';
                            $actionText = '⭐️ Rate Your Partner ›';
                        } elseif ($n['Type'] === 'rating') {
                            $icon = '🌟';
                            $actionText = '👤 View Profile & Reviews ›';
                        } elseif ($n['Type'] === 'leave') {
                            $icon = '🚶';
                            $actionText = '🚗 View Ride Status ›';
                        } elseif (strpos($n['Type'], 'lost_found') !== false) {
                            $icon = '📦';
                            $actionText = '🔍 View Lost & Found Item ›';
                        }
                    ?>
                        <a href="api_actions.php?action=open_notif&id=<?= $n['NotificationID'] ?>" class="notif-card <?= $n['IsRead'] ? '' : 'unread' ?>">
                            <div class="notif-icon-box">
                                <?= $icon ?>
                            </div>
                            <div class="notif-body">
                                <div class="notif-header-row">
                                    <div class="notif-title">
                                        <?php if (!$n['IsRead']): ?>
                                            <span class="notif-unread-dot" title="Unread"></span>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($n['Title']) ?>
                                    </div>
                                    <div class="notif-time">
                                        <?= format_message_time($n['created_at']) ?>
                                    </div>
                                </div>
                                <div class="notif-msg"><?= htmlspecialchars($n['Message']) ?></div>
                                <span class="notif-action-badge">
                                    <?= $actionText ?>
                                </span>
                            </div>
                            <div class="notif-arrow">›</div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <?php render_footer(); ?>
</body>
</html>
