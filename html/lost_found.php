<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

$isLoggedIn = isset($_SESSION['user_id']);
$currentUserId = $isLoggedIn ? (int)$_SESSION['user_id'] : 0;
$currentUserRole = $_SESSION['user_type'] ?? 'Passenger';
$isAdmin = ($currentUserRole === 'Admin');

$msgSuccess = $_SESSION['success_msg'] ?? '';
$msgError = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Query Filters
$filterType = trim($_GET['type'] ?? 'all'); // 'all', 'Lost', 'Found', 'mine'
$filterCategory = trim($_GET['category'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$searchQuery = trim($_GET['q'] ?? '');
$selectedItemId = (int)($_GET['item_id'] ?? 0);
$prefillRideId = (int)($_GET['ride_id'] ?? 0);
$openModal = isset($_GET['report']) ? $_GET['report'] : ''; // 'lost' or 'found'

// Fetch user's recent rides for the report form dropdown
$userRides = [];
if ($isLoggedIn) {
    $rideStmt = $pdo->prepare("
        SELECT r.RideID, r.StartLocation, r.Destination, r.RideDate, r.DepartureTime,
               CASE WHEN r.DriverID = ? THEN 'Driver' ELSE 'Passenger' END as UserRole
        FROM `Ride` r
        LEFT JOIN `RideParticipant` rp ON r.RideID = rp.RideID AND rp.UserID = ?
        WHERE r.DriverID = ? OR rp.UserID = ?
        ORDER BY r.RideDate DESC, r.DepartureTime DESC
        LIMIT 15
    ");
    $rideStmt->execute([$currentUserId, $currentUserId, $currentUserId, $currentUserId]);
    $userRides = $rideStmt->fetchAll();
}

// Build Main Query
$whereClauses = [];
$params = [];

if ($filterType === 'Lost' || $filterType === 'Found') {
    $whereClauses[] = "li.ReportType = ?";
    $params[] = $filterType;
} elseif ($filterType === 'mine' && $isLoggedIn) {
    $whereClauses[] = "li.PosterID = ?";
    $params[] = $currentUserId;
}

if (!empty($filterCategory)) {
    $whereClauses[] = "li.Category = ?";
    $params[] = $filterCategory;
}

if (!empty($filterStatus)) {
    $whereClauses[] = "li.Status = ?";
    $params[] = $filterStatus;
}

if (!empty($searchQuery)) {
    $whereClauses[] = "(li.ItemName LIKE ? OR li.Description LIKE ? OR li.LocationDetails LIKE ? OR u.Name LIKE ?)";
    $like = '%' . $searchQuery . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

$query = "
    SELECT 
        li.*,
        u.Name AS PosterName,
        u.Email AS PosterEmail,
        u.UniversityVerified AS PosterVerified,
        u.ProfileImage AS PosterImage,
        r.StartLocation,
        r.Destination,
        r.RideDate,
        r.DepartureTime,
        r.VehicleInfo,
        (SELECT COUNT(*) FROM `LostItemComment` WHERE ItemID = li.ItemID) AS CommentCount,
        (SELECT COUNT(*) FROM `LostItemComment` WHERE ItemID = li.ItemID AND IsClaim = 1) AS ClaimCount
    FROM `LostItem` li
    JOIN `User` u ON li.PosterID = u.UserID
    LEFT JOIN `Ride` r ON li.RideID = r.RideID
    $whereSql
    ORDER BY 
        CASE WHEN li.Status = 'Open' THEN 1 WHEN li.Status = 'Claimed' THEN 2 ELSE 3 END,
        li.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Fetch summary stats
$statTotal = (int)$pdo->query("SELECT COUNT(*) FROM `LostItem`")->fetchColumn();
$statLostOpen = (int)$pdo->query("SELECT COUNT(*) FROM `LostItem` WHERE ReportType = 'Lost' AND Status != 'Resolved'")->fetchColumn();
$statFoundOpen = (int)$pdo->query("SELECT COUNT(*) FROM `LostItem` WHERE ReportType = 'Found' AND Status != 'Resolved'")->fetchColumn();
$statResolved = (int)$pdo->query("SELECT COUNT(*) FROM `LostItem` WHERE Status = 'Resolved'")->fetchColumn();

// If an item is specifically selected, fetch full details and its comment trail
$selectedItem = null;
$itemComments = [];
if ($selectedItemId > 0) {
    $selStmt = $pdo->prepare("
        SELECT 
            li.*,
            u.Name AS PosterName,
            u.Email AS PosterEmail,
            u.UniversityVerified AS PosterVerified,
            u.ProfileImage AS PosterImage,
            r.StartLocation,
            r.Destination,
            r.RideDate,
            r.DepartureTime,
            r.VehicleInfo,
            resU.Name AS ResolverName
        FROM `LostItem` li
        JOIN `User` u ON li.PosterID = u.UserID
        LEFT JOIN `Ride` r ON li.RideID = r.RideID
        LEFT JOIN `User` resU ON li.ResolvedBy = resU.UserID
        WHERE li.ItemID = ?
    ");
    $selStmt->execute([$selectedItemId]);
    $selectedItem = $selStmt->fetch();

    if ($selectedItem) {
        $cStmt = $pdo->prepare("
            SELECT c.*, u.Name, u.UniversityVerified, u.UserType
            FROM `LostItemComment` c
            JOIN `User` u ON c.UserID = u.UserID
            WHERE c.ItemID = ?
            ORDER BY c.created_at ASC
        ");
        $cStmt->execute([$selectedItemId]);
        $itemComments = $cStmt->fetchAll();
    }
}

$categoriesList = [
    'Electronics' => '💻 Electronics & Gadgets',
    'Student ID & Cards' => '🪪 Student ID & Cards',
    'Bags & Wallets' => '🎒 Bags, Wallets & Purses',
    'Keys' => '🔑 Keys & Keychains',
    'Clothing & Accessories' => '🧥 Clothing & Accessories',
    'Books & Documents' => '📚 Books & Documents',
    'Other' => '📦 Other Items'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lost & Found - BRAC University Rideshare</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .lf-header {
            background: linear-gradient(135deg, #002244 0%, #003366 50%, #0d4a87 100%);
            color: #ffffff;
            border-radius: var(--radius-lg);
            padding: 2.2rem 2rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
            box-shadow: var(--shadow-md);
        }
        .lf-header-content h1 {
            font-size: 1.85rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .lf-header-content p {
            color: #cbd5e1;
            font-size: 0.95rem;
            margin-top: 0.35rem;
            max-width: 600px;
        }
        .lf-header-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .btn-report-lost {
            background: #ef4444;
            color: #ffffff;
            border: none;
            padding: 0.85rem 1.4rem;
            border-radius: var(--radius-sm);
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            text-decoration: none;
            box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3);
        }
        .btn-report-lost:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }
        .btn-report-found {
            background: #10b981;
            color: #ffffff;
            border: none;
            padding: 0.85rem 1.4rem;
            border-radius: var(--radius-sm);
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            text-decoration: none;
            box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);
        }
        .btn-report-found:hover {
            background: #059669;
            transform: translateY(-1px);
        }
        
        .lf-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .lf-stat-card {
            background: #ffffff;
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .lf-stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }
        .lf-stat-num {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1.1;
        }
        .lf-stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 0.15rem;
        }

        .filter-bar {
            background: #ffffff;
            padding: 1.25rem;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            overflow-x: auto;
            padding-bottom: 0.25rem;
        }
        .filter-tab {
            padding: 0.55rem 1.1rem;
            border-radius: 9999px;
            font-size: 0.85rem;
            font-weight: 700;
            text-decoration: none;
            color: var(--text-muted);
            background: #f1f5f9;
            white-space: nowrap;
            transition: all 0.15s ease;
        }
        .filter-tab:hover {
            background: #e2e8f0;
            color: var(--text-main);
        }
        .filter-tab.active {
            background: var(--primary);
            color: #ffffff;
        }
        .filter-tab.active-lost {
            background: #ef4444;
            color: #ffffff;
        }
        .filter-tab.active-found {
            background: #10b981;
            color: #ffffff;
        }

        .lf-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 1.5rem;
        }
        .lf-card {
            background: #ffffff;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
            position: relative;
        }
        .lf-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            border-color: #cbd5e1;
        }
        .badge-lost {
            background: #fee2e2;
            color: #b91c1c;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .badge-found {
            background: #dcfce7;
            color: #15803d;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .badge-status-open {
            background: #eff6ff;
            color: #1d4ed8;
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .badge-status-claimed {
            background: #fef3c7;
            color: #b45309;
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .badge-status-resolved {
            background: #f1f5f9;
            color: #475569;
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .lf-ride-tag {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            color: #475569;
            margin: 0.75rem 0;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        /* Modal styling */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-box {
            background: #ffffff;
            width: 100%;
            max-width: 650px;
            max-height: 90vh;
            border-radius: var(--radius-lg);
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: modalFadeIn 0.25s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--primary);
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            line-height: 1;
            color: #94a3b8;
            cursor: pointer;
            padding: 0.25rem;
        }
        .modal-close:hover {
            color: var(--text-main);
        }
        .modal-body {
            padding: 1.5rem;
        }

        .comment-bubble {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 0.85rem 1rem;
            margin-bottom: 0.75rem;
        }
        .comment-bubble.is-claim {
            background: #fffbeb;
            border-color: #fde68a;
        }
        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.35rem;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <?php render_navbar('lost_found'); ?>

    <div class="main-container">

        <!-- Alerts -->
        <?php if (!empty($msgSuccess)): ?>
            <div class="alert alert-success" style="margin-bottom: 1.5rem;">
                <?= htmlspecialchars($msgSuccess) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($msgError)): ?>
            <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
                <?= htmlspecialchars($msgError) ?>
            </div>
        <?php endif; ?>

        <!-- Hero Header -->
        <div class="lf-header">
            <div class="lf-header-content">
                <h1>🔍 BRAC University Lost & Found</h1>
                <p>Left something in a peer's car or found a fellow student's ID/belonging during your commute? Post and reconnect quickly within our verified campus network.</p>
            </div>
            <div class="lf-header-actions">
                <?php if ($isLoggedIn): ?>
                    <button type="button" class="btn-report-lost" onclick="openReportModal('Lost')">
                        🔴 Report Lost Item
                    </button>
                    <button type="button" class="btn-report-found" onclick="openReportModal('Found')">
                        🟢 Report Found Item
                    </button>
                <?php else: ?>
                    <a href="login.php" class="btn-report-lost">Log in to Report</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="lf-stats-grid">
            <div class="lf-stat-card">
                <div class="lf-stat-icon" style="background: #eff6ff; color: #1d4ed8;">📋</div>
                <div>
                    <div class="lf-stat-num"><?= $statTotal ?></div>
                    <div class="lf-stat-label">Total Reports</div>
                </div>
            </div>
            <div class="lf-stat-card">
                <div class="lf-stat-icon" style="background: #fee2e2; color: #dc2626;">🔴</div>
                <div>
                    <div class="lf-stat-num"><?= $statLostOpen ?></div>
                    <div class="lf-stat-label">Open Lost Belongings</div>
                </div>
            </div>
            <div class="lf-stat-card">
                <div class="lf-stat-icon" style="background: #dcfce7; color: #16a34a;">🟢</div>
                <div>
                    <div class="lf-stat-num"><?= $statFoundOpen ?></div>
                    <div class="lf-stat-label">Found Awaiting Owner</div>
                </div>
            </div>
            <div class="lf-stat-card">
                <div class="lf-stat-icon" style="background: #f1f5f9; color: #0284c7;">🎉</div>
                <div>
                    <div class="lf-stat-num"><?= $statResolved ?></div>
                    <div class="lf-stat-label">Returned & Resolved</div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="filter-bar">
            <!-- Tabs -->
            <div class="filter-tabs">
                <a href="lost_found.php?type=all<?= !empty($filterCategory) ? '&category='.urlencode($filterCategory) : '' ?>" class="filter-tab <?= $filterType === 'all' ? 'active' : '' ?>">All Reports</a>
                <a href="lost_found.php?type=Lost<?= !empty($filterCategory) ? '&category='.urlencode($filterCategory) : '' ?>" class="filter-tab <?= $filterType === 'Lost' ? 'active-lost' : '' ?>">🔴 Lost Items</a>
                <a href="lost_found.php?type=Found<?= !empty($filterCategory) ? '&category='.urlencode($filterCategory) : '' ?>" class="filter-tab <?= $filterType === 'Found' ? 'active-found' : '' ?>">🟢 Found Items</a>
                <?php if ($isLoggedIn): ?>
                    <a href="lost_found.php?type=mine" class="filter-tab <?= $filterType === 'mine' ? 'active' : '' ?>">👤 My Reports</a>
                <?php endif; ?>
            </div>

            <!-- Form Filters -->
            <form method="GET" action="lost_found.php" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
                <input type="hidden" name="type" value="<?= htmlspecialchars($filterType) ?>">

                <div style="flex: 2; min-width: 220px;">
                    <input type="text" name="q" class="form-control" placeholder="Search by item name, description, location..." value="<?= htmlspecialchars($searchQuery) ?>" style="margin-bottom: 0;">
                </div>

                <div style="flex: 1; min-width: 170px;">
                    <select name="category" class="form-control" style="margin-bottom: 0;">
                        <option value="">All Categories</option>
                        <?php foreach ($categoriesList as $catKey => $catLabel): ?>
                            <option value="<?= $catKey ?>" <?= $filterCategory === $catKey ? 'selected' : '' ?>><?= $catLabel ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="flex: 1; min-width: 140px;">
                    <select name="status" class="form-control" style="margin-bottom: 0;">
                        <option value="">All Statuses</option>
                        <option value="Open" <?= $filterStatus === 'Open' ? 'selected' : '' ?>>Open</option>
                        <option value="Claimed" <?= $filterStatus === 'Claimed' ? 'selected' : '' ?>>Claim Pending</option>
                        <option value="Resolved" <?= $filterStatus === 'Resolved' ? 'selected' : '' ?>>Resolved / Returned</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="width: auto; padding: 0.85rem 1.4rem;">
                    Search
                </button>
                <?php if (!empty($searchQuery) || !empty($filterCategory) || !empty($filterStatus) || $filterType !== 'all'): ?>
                    <a href="lost_found.php" class="btn btn-secondary" style="width: auto; padding: 0.85rem 1.2rem; text-decoration: none;">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Items Grid -->
        <?php if (empty($items)): ?>
            <div class="card" style="text-align: center; padding: 3rem 1.5rem;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">🔍</div>
                <h3 style="color: var(--primary); font-weight: 800; font-size: 1.3rem;">No Lost & Found Reports Match Your Search</h3>
                <p style="color: var(--text-muted); font-size: 0.95rem; max-width: 500px; margin: 0.5rem auto 1.5rem;">
                    Try adjusting your filters or search keywords, or post a new report if you have lost or found an item during a commute.
                </p>
                <?php if ($isLoggedIn): ?>
                    <div style="display: flex; gap: 0.75rem; justify-content: center;">
                        <button type="button" class="btn-report-lost" onclick="openReportModal('Lost')">Report Lost Item</button>
                        <button type="button" class="btn-report-found" onclick="openReportModal('Found')">Report Found Item</button>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="lf-grid">
                <?php foreach ($items as $it): 
                    $isLost = ($it['ReportType'] === 'Lost');
                    $isMyReport = ($isLoggedIn && (int)$it['PosterID'] === $currentUserId);
                ?>
                    <div class="lf-card">
                        <div>
                            <!-- Header / Badges -->
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem;">
                                <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
                                    <span class="<?= $isLost ? 'badge-lost' : 'badge-found' ?>">
                                        <?= $isLost ? '🔴 LOST' : '🟢 FOUND' ?>
                                    </span>
                                    <span style="font-size: 0.75rem; font-weight: 700; color: #475569; background: #f1f5f9; padding: 0.2rem 0.5rem; border-radius: 6px;">
                                        <?= htmlspecialchars($it['Category']) ?>
                                    </span>
                                </div>
                                <div>
                                    <?php if ($it['Status'] === 'Open'): ?>
                                        <span class="badge-status-open">Open</span>
                                    <?php elseif ($it['Status'] === 'Claimed'): ?>
                                        <span class="badge-status-claimed">Claim Pending</span>
                                    <?php else: ?>
                                        <span class="badge-status-resolved">✓ Returned</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Title -->
                            <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--primary); margin-bottom: 0.5rem; line-height: 1.3;">
                                <a href="lost_found.php?item_id=<?= $it['ItemID'] ?>" style="color: inherit; text-decoration: none;">
                                    <?= htmlspecialchars($it['ItemName']) ?>
                                </a>
                            </h3>

                            <!-- Description excerpt -->
                            <p style="font-size: 0.9rem; color: #334155; margin-bottom: 0.75rem; line-height: 1.45;">
                                <?= nl2br(htmlspecialchars(mb_strimwidth($it['Description'], 0, 160, '...'))) ?>
                            </p>

                            <!-- Linked Ride Information -->
                            <?php if (!empty($it['RideID'])): ?>
                                <div class="lf-ride-tag">
                                    <span>🚗</span>
                                    <div>
                                        <strong>Ride:</strong> <?= htmlspecialchars($it['StartLocation']) ?> → <?= htmlspecialchars($it['Destination']) ?> 
                                        <span style="color: var(--text-muted);">(<?= format_ride_date($it['RideDate']) ?>)</span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Location & Date -->
                            <div style="font-size: 0.8rem; color: var(--text-muted); display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 1rem;">
                                <div>📍 <strong>Location:</strong> <?= htmlspecialchars($it['LocationDetails']) ?></div>
                                <div>📅 <strong>Date <?= $isLost ? 'Lost' : 'Found' ?>:</strong> <?= date('M j, Y', strtotime($it['DateLostFound'])) ?></div>
                            </div>
                        </div>

                        <!-- Footer Actions & Poster Info -->
                        <div style="border-top: 1px solid #f1f5f9; padding-top: 0.85rem; margin-top: 0.5rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <div class="nav-avatar" style="width: 28px; height: 28px; font-size: 0.75rem;">
                                        <?= strtoupper(substr($it['PosterName'], 0, 1)) ?>
                                    </div>
                                    <span style="font-size: 0.85rem; font-weight: 700; color: var(--text-main);">
                                        <?= htmlspecialchars($it['PosterName']) ?>
                                    </span>
                                    <?= render_verification_badge($it['PosterVerified']) ?>
                                </div>
                                <span style="font-size: 0.75rem; color: var(--text-muted);">
                                    <?= date('M j', strtotime($it['created_at'])) ?>
                                </span>
                            </div>

                            <div style="display: flex; gap: 0.5rem;">
                                <a href="lost_found.php?item_id=<?= $it['ItemID'] ?>" class="btn btn-secondary" style="flex: 1; padding: 0.55rem; font-size: 0.85rem; text-align: center; text-decoration: none;">
                                    💬 View & Details (<?= $it['CommentCount'] ?>)
                                </a>
                                <?php if ($isMyReport && $it['Status'] !== 'Resolved'): ?>
                                    <form method="POST" action="api_actions.php" style="margin: 0;">
                                        <input type="hidden" name="action" value="resolve_lost_item">
                                        <input type="hidden" name="item_id" value="<?= $it['ItemID'] ?>">
                                        <button type="submit" class="btn btn-primary" style="padding: 0.55rem 0.85rem; font-size: 0.85rem;" title="Mark as Returned">
                                            ✓ Resolved
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- =======================================================================
         REPORT ITEM MODAL
         ======================================================================= -->
    <div class="modal-overlay <?= (!empty($openModal) || $prefillRideId > 0) ? 'active' : '' ?>" id="reportModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 id="modalTitle">📢 Report an Item</h3>
                <button type="button" class="modal-close" onclick="closeReportModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="api_actions.php">
                    <input type="hidden" name="action" value="report_lost_item">

                    <!-- Report Type Selector -->
                    <div class="form-group">
                        <label>Report Type *</label>
                        <div style="display: flex; gap: 1rem;">
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 700; color: #b91c1c;">
                                <input type="radio" name="report_type" value="Lost" id="typeLost" <?= ($openModal === 'found') ? '' : 'checked' ?>>
                                🔴 I Lost Something
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 700; color: #15803d;">
                                <input type="radio" name="report_type" value="Found" id="typeFound" <?= ($openModal === 'found') ? 'checked' : '' ?>>
                                🟢 I Found Something
                            </label>
                        </div>
                    </div>

                    <!-- Item Name -->
                    <div class="form-group">
                        <label>Item Name / Title *</label>
                        <input type="text" name="item_name" class="form-control" placeholder="e.g. Blue Spigen Case with AirPods Pro / Casio Calculator" required>
                    </div>

                    <!-- Category -->
                    <div class="form-group">
                        <label>Category *</label>
                        <select name="category" class="form-control" required>
                            <?php foreach ($categoriesList as $catKey => $catLabel): ?>
                                <option value="<?= $catKey ?>"><?= $catLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Associated Ride (Optional) -->
                    <div class="form-group">
                        <label>Was this item lost/found on a specific ride? (Optional)</label>
                        <select name="ride_id" class="form-control">
                            <option value="">-- Not tied to a specific ride / General Campus Area --</option>
                            <?php foreach ($userRides as $ur): ?>
                                <option value="<?= $ur['RideID'] ?>" <?= ($prefillRideId === (int)$ur['RideID']) ? 'selected' : '' ?>>
                                    [<?= $ur['UserRole'] ?>] <?= htmlspecialchars($ur['StartLocation']) ?> → <?= htmlspecialchars($ur['Destination']) ?> (<?= format_ride_date($ur['RideDate']) ?> <?= format_time_12h($ur['DepartureTime']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: var(--text-muted); display: block; margin-top: 0.25rem;">
                            Linking a ride will automatically send an alert to all fellow passengers and the driver!
                        </small>
                    </div>

                    <!-- Location Details -->
                    <div class="form-group">
                        <label>Location Details / Area *</label>
                        <input type="text" name="location_details" class="form-control" placeholder="e.g. Back seat of car, UB Building entrance, Merul Badda gate" required>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <!-- Date -->
                        <div class="form-group">
                            <label>Date Lost / Found *</label>
                            <input type="date" name="date_lost_found" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <!-- Contact Phone -->
                        <div class="form-group">
                            <label>Contact Phone (Optional)</label>
                            <input type="text" name="contact_phone" class="form-control" placeholder="e.g. +880-1711-XXXXXX">
                        </div>
                    </div>

                    <!-- Detailed Description -->
                    <div class="form-group">
                        <label>Description & Identifying Features *</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Provide distinct characteristics, color, brand, stickers, serial number hints, or circumstances..." required></textarea>
                    </div>

                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeReportModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary" style="width: auto; padding: 0.85rem 1.75rem;">Publish Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- =======================================================================
         ITEM DETAIL & DISCUSSION MODAL
         ======================================================================= -->
    <?php if ($selectedItem): 
        $isLost = ($selectedItem['ReportType'] === 'Lost');
        $isMyReport = ($isLoggedIn && (int)$selectedItem['PosterID'] === $currentUserId);
    ?>
    <div class="modal-overlay active" id="detailModal">
        <div class="modal-box" style="max-width: 750px;">
            <div class="modal-header">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span class="<?= $isLost ? 'badge-lost' : 'badge-found' ?>">
                        <?= $isLost ? '🔴 LOST' : '🟢 FOUND' ?>
                    </span>
                    <span style="font-size: 0.8rem; font-weight: 700; color: #475569; background: #f1f5f9; padding: 0.2rem 0.5rem; border-radius: 6px;">
                        <?= htmlspecialchars($selectedItem['Category']) ?>
                    </span>
                </div>
                <a href="lost_found.php" class="modal-close" style="text-decoration: none;">&times;</a>
            </div>

            <div class="modal-body">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--primary);">
                            <?= htmlspecialchars($selectedItem['ItemName']) ?>
                        </h2>
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.2rem;">
                            Reported on <?= date('F j, Y · g:i A', strtotime($selectedItem['created_at'])) ?>
                        </div>
                    </div>
                    <div>
                        <?php if ($selectedItem['Status'] === 'Open'): ?>
                            <span class="badge-status-open" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">Status: Open</span>
                        <?php elseif ($selectedItem['Status'] === 'Claimed'): ?>
                            <span class="badge-status-claimed" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">Status: Claim in Review</span>
                        <?php else: ?>
                            <span class="badge-status-resolved" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">Status: ✓ Resolved / Returned</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Description Box -->
                <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.85rem; text-transform: uppercase; color: var(--text-muted); font-weight: 800; margin-bottom: 0.5rem;">Description</h4>
                    <p style="font-size: 0.95rem; color: var(--text-main); line-height: 1.6;">
                        <?= nl2br(htmlspecialchars($selectedItem['Description'])) ?>
                    </p>
                </div>

                <!-- Metadata Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                    <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: 8px; padding: 0.85rem;">
                        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700; display: block;">LOCATION</span>
                        <span style="font-size: 0.9rem; font-weight: 600; color: var(--text-main);">
                            📍 <?= htmlspecialchars($selectedItem['LocationDetails']) ?>
                        </span>
                    </div>

                    <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: 8px; padding: 0.85rem;">
                        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700; display: block;">DATE OCCURRED</span>
                        <span style="font-size: 0.9rem; font-weight: 600; color: var(--text-main);">
                            📅 <?= date('M j, Y', strtotime($selectedItem['DateLostFound'])) ?>
                        </span>
                    </div>

                    <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: 8px; padding: 0.85rem;">
                        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700; display: block;">REPORTED BY</span>
                        <div style="display: flex; align-items: center; gap: 0.4rem; margin-top: 0.15rem;">
                            <span style="font-size: 0.9rem; font-weight: 700;"><?= htmlspecialchars($selectedItem['PosterName']) ?></span>
                            <?= render_verification_badge($selectedItem['PosterVerified']) ?>
                        </div>
                    </div>

                    <?php if (!empty($selectedItem['ContactPhone'])): ?>
                        <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: 8px; padding: 0.85rem;">
                            <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700; display: block;">CONTACT PHONE</span>
                            <span style="font-size: 0.9rem; font-weight: 700; color: var(--accent);">
                                📞 <?= htmlspecialchars($selectedItem['ContactPhone']) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Linked Ride Card if present -->
                <?php if (!empty($selectedItem['RideID'])): ?>
                    <div style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: var(--radius); padding: 1rem 1.25rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
                        <div>
                            <span style="font-size: 0.75rem; font-weight: 800; color: #1e40af; text-transform: uppercase;">Linked Shared Ride</span>
                            <div style="font-weight: 800; font-size: 1rem; color: #1e3a8a;">
                                <?= htmlspecialchars($selectedItem['StartLocation']) ?> → <?= htmlspecialchars($selectedItem['Destination']) ?>
                            </div>
                            <div style="font-size: 0.85rem; color: #3b82f6;">
                                <?= format_ride_date($selectedItem['RideDate']) ?> at <?= format_time_12h($selectedItem['DepartureTime']) ?> <?= !empty($selectedItem['VehicleInfo']) ? '• ' . htmlspecialchars($selectedItem['VehicleInfo']) : '' ?>
                            </div>
                        </div>
                        <a href="ride_details.php?id=<?= $selectedItem['RideID'] ?>" class="btn btn-secondary" style="font-size: 0.85rem; padding: 0.5rem 1rem; background: #ffffff;">
                            View Ride Details
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Reporter Actions (Resolve / Delete) -->
                <?php if (($isMyReport || $isAdmin) && $selectedItem['Status'] !== 'Resolved'): ?>
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1.5rem;">
                        <h4 style="font-size: 0.95rem; font-weight: 800; color: #166534; margin-bottom: 0.5rem;">Manage Report</h4>
                        <form method="POST" action="api_actions.php" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                            <input type="hidden" name="action" value="resolve_lost_item">
                            <input type="hidden" name="item_id" value="<?= $selectedItem['ItemID'] ?>">
                            <input type="text" name="resolution_notes" class="form-control" placeholder="Resolution notes (e.g. Handed back to Rahim in UB building)" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                            <button type="submit" class="btn btn-primary" style="background: #15803d; border-color: #15803d; width: auto;">
                                ✓ Mark Returned & Resolved
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Discussion & Claims Thread -->
                <div id="discussion" style="border-top: 1.5px solid var(--border-color); padding-top: 1.5rem;">
                    <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--primary); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                        💬 Coordination & Claims Discussion (<?= count($itemComments) ?>)
                    </h3>

                    <div style="max-height: 280px; overflow-y: auto; margin-bottom: 1.25rem; padding-right: 0.5rem;">
                        <?php if (empty($itemComments)): ?>
                            <div style="text-align: center; color: var(--text-muted); padding: 1.5rem; background: #f8fafc; border-radius: 8px; font-size: 0.9rem;">
                                No messages yet. Send a claim or message to coordinate returning this item.
                            </div>
                        <?php else: ?>
                            <?php foreach ($itemComments as $comm): ?>
                                <div class="comment-bubble <?= $comm['IsClaim'] ? 'is-claim' : '' ?>">
                                    <div class="comment-header">
                                        <div style="display: flex; align-items: center; gap: 0.35rem;">
                                            <strong><?= htmlspecialchars($comm['Name']) ?></strong>
                                            <?= render_verification_badge($comm['UniversityVerified']) ?>
                                            <?php if ($comm['IsClaim']): ?>
                                                <span style="background: #f59e0b; color: #ffffff; font-size: 0.7rem; font-weight: 800; padding: 0.1rem 0.45rem; border-radius: 4px;">
                                                    📦 Ownership Claim
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span style="color: var(--text-muted); font-size: 0.75rem;">
                                            <?= date('M j, g:i A', strtotime($comm['created_at'])) ?>
                                        </span>
                                    </div>
                                    <p style="font-size: 0.9rem; color: var(--text-main); margin: 0;">
                                        <?= nl2br(htmlspecialchars($comm['Message'])) ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Post Comment / Claim Form -->
                    <?php if ($isLoggedIn): ?>
                        <form method="POST" action="api_actions.php" style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius); padding: 1rem;">
                            <input type="hidden" name="action" value="add_lost_comment">
                            <input type="hidden" name="item_id" value="<?= $selectedItem['ItemID'] ?>">

                            <div style="margin-bottom: 0.75rem;">
                                <textarea name="message" class="form-control" rows="2" placeholder="<?= $isLost ? 'Found this item or have information? Write here...' : 'Is this your item? Describe identifying details to claim...' ?>" required style="margin-bottom: 0;"></textarea>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
                                <?php if (!$isMyReport && $selectedItem['Status'] !== 'Resolved'): ?>
                                    <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; font-weight: 700; color: #b45309; cursor: pointer;">
                                        <input type="checkbox" name="is_claim" value="1">
                                        Mark this as an official Ownership Claim 📦
                                    </label>
                                <?php else: ?>
                                    <span></span>
                                <?php endif; ?>

                                <button type="submit" class="btn btn-primary" style="width: auto; padding: 0.65rem 1.5rem;">
                                    Send Message
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div style="text-align: center; padding: 1rem; background: #f1f5f9; border-radius: 8px;">
                            <a href="login.php" style="color: var(--accent); font-weight: 700;">Log in</a> to send a message or claim this item.
                        </div>
                    <?php endif; ?>

                </div>

            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function openReportModal(type) {
            var modal = document.getElementById('reportModal');
            var title = document.getElementById('modalTitle');
            var radioLost = document.getElementById('typeLost');
            var radioFound = document.getElementById('typeFound');

            if (type === 'Found') {
                radioFound.checked = true;
                title.innerText = '🟢 Report a Found Item';
            } else {
                radioLost.checked = true;
                title.innerText = '🔴 Report a Lost Item';
            }

            modal.classList.add('active');
        }

        function closeReportModal() {
            var modal = document.getElementById('reportModal');
            modal.classList.remove('active');
        }

        // Close on backdrop click
        window.addEventListener('click', function(e) {
            var rModal = document.getElementById('reportModal');
            if (e.target === rModal) {
                closeReportModal();
            }
        });
    </script>

    <?php render_footer(); ?>
</body>
</html>
