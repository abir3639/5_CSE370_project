<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

$isLoggedIn = isset($_SESSION['user_id']);
$currentUserId = $_SESSION['user_id'] ?? null;
$currentUserRole = $_SESSION['user_type'] ?? 'Passenger';

$msgSuccess = $_SESSION['success_msg'] ?? '';
$msgError = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Search Parameters
$searchDest = trim($_GET['dest'] ?? '');
$searchStart = trim($_GET['start'] ?? '');
$searchDate = trim($_GET['date'] ?? '');
$searchTime = trim($_GET['time'] ?? '');
$flexibleTime = isset($_GET['flexible_time']) && $_GET['flexible_time'] === '1';
$womenOnlyFilter = isset($_GET['women_only']) && $_GET['women_only'] === '1';

// Default date for pre-fill if not set
if (empty($searchDate) && isset($_GET['search'])) {
    $searchDate = date('Y-m-d');
}

$hasSearched = isset($_GET['search']) && (!empty($searchDest) || !empty($searchStart));

// Fetch Logged-in User Saved Favorite Locations
$userFavorites = [];
if ($isLoggedIn) {
    $favStmt = $pdo->prepare("SELECT * FROM `FavoriteLocation` WHERE UserID = ?");
    $favStmt->execute([$currentUserId]);
    $userFavorites = $favStmt->fetchAll();
}

// Fetch Rides from Database
$query = "
    SELECT 
        r.*, 
        u.Name AS DriverName, 
        u.Email AS DriverEmail, 
        u.UniversityVerified, 
        u.RatingAverage AS DriverRating, 
        u.RatingCount AS DriverRatingCount,
        u.ProfileImage,
        (SELECT COUNT(*) FROM `RideRequest` WHERE RideID = r.RideID AND PassengerID = ? AND Status = 'Pending') AS HasPendingRequest,
        (SELECT COUNT(*) FROM `RideParticipant` WHERE RideID = r.RideID AND UserID = ? AND Role = 'Passenger') AS IsAcceptedPassenger
    FROM `Ride` r
    JOIN `User` u ON r.DriverID = u.UserID
    WHERE r.Status IN ('Open', 'Full')
";

$params = [$currentUserId ?? 0, $currentUserId ?? 0];

if ($womenOnlyFilter) {
    $query .= " AND r.IsWomenOnly = 1";
}

if (!empty($searchDate)) {
    $query .= " AND r.RideDate = ?";
    $params[] = $searchDate;
} else {
    // By default show today and upcoming rides
    $query .= " AND r.RideDate >= CURDATE()";
}

$query .= " ORDER BY r.RideDate ASC, r.DepartureTime ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rawRides = $stmt->fetchAll();

// Apply Smart Matching Algorithm
$matchedRides = [];
if ($hasSearched) {
    foreach ($rawRides as $ride) {
        $match = calculate_ride_match($searchDest, $searchStart, $searchDate, $searchTime, $flexibleTime, $ride);
        if ($match['isMatch']) {
            $ride['matchScore'] = $match['score'];
            $ride['matchDetails'] = $match['details'];
            $matchedRides[] = $ride;
        }
    }
    // Sort by compatibility score descending
    usort($matchedRides, function($a, $b) {
        return ($b['matchScore'] ?? 0) <=> ($a['matchScore'] ?? 0);
    });
} else {
    $matchedRides = $rawRides;
}

$dhakaLocs = get_dhaka_locations();

// Optional Admin Query execution (preserved from existing code)
$adminQueryResults = null;
$adminQueryError = '';
$adminExecutedQuery = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sql_query']) && $isLoggedIn && $currentUserRole === 'Admin') {
    $customQuery = trim($_POST['sql_query']);
    if (preg_match('/^\s*select\b/i', $customQuery)) {
        try {
            $qStmt = $pdo->query($customQuery);
            $adminQueryResults = $qStmt->fetchAll(PDO::FETCH_ASSOC);
            $adminExecutedQuery = $customQuery;
        } catch (PDOException $e) {
            $adminQueryError = $e->getMessage();
        }
    } else {
        $adminQueryError = "Only SELECT queries are allowed.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BRAC University Rideshare - Find & Share Rides</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .filter-banner {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.85rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
        }
        .filter-tags {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-tag {
            background: #e0f2fe;
            color: #0369a1;
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            font-weight: 600;
        }
        /* Modal for Requesting to Join */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            z-index: 200;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-box {
            background: #ffffff;
            border-radius: var(--radius);
            max-width: 480px;
            width: 100%;
            padding: 2rem;
            box-shadow: var(--shadow-lg);
        }
    </style>
</head>
<body>
    <?php render_navbar('find'); ?>

    <div class="main-container">

        <?php if (!empty($msgSuccess)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($msgSuccess) ?></div>
        <?php endif; ?>
        <?php if (!empty($msgError)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($msgError) ?></div>
        <?php endif; ?>

        <!-- Hero / Search Engine Section -->
        <div class="hero-search-card">
            <h1>Where are you going?</h1>
            <p class="hero-subtitle">Find verified BRAC University students and faculty heading in the same direction.</p>

            <form method="GET" action="index.php">
                <input type="hidden" name="search" value="1">

                <datalist id="dhakaLocations">
                    <?php foreach ($dhakaLocs as $locName => $data): ?>
                        <option value="<?= htmlspecialchars($locName) ?>"></option>
                    <?php endforeach; ?>
                </datalist>

                <div class="search-form-grid">
                    <div class="search-input-group">
                        <label>Destination (Where to?)</label>
                        <input type="text" id="destInput" name="dest" list="dhakaLocations" placeholder="e.g. BRAC University, Mirpur 10" value="<?= htmlspecialchars($searchDest) ?>" required>
                    </div>
                    <div class="search-input-group">
                        <label>Starting Location (Optional)</label>
                        <input type="text" id="startInput" name="start" list="dhakaLocations" placeholder="e.g. Mirpur, Uttara, Dhanmondi" value="<?= htmlspecialchars($searchStart) ?>">
                    </div>
                    <div class="search-input-group">
                        <label>Travel Date</label>
                        <input type="date" name="date" value="<?= htmlspecialchars($searchDate ?: date('Y-m-d')) ?>" min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="search-input-group">
                        <label>Departure Time</label>
                        <input type="time" name="time" value="<?= htmlspecialchars($searchTime) ?>">
                    </div>
                    <div style="display: flex; flex-direction: column; justify-content: flex-end;">
                        <button type="submit" class="btn-search-submit">
                            <svg style="width:18px;height:18px;" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                            Find Rides
                        </button>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-top: 0.5rem;">
                    <div style="display: flex; gap: 1.25rem; flex-wrap: wrap;">
                        <div class="search-checkbox-group">
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                                <input type="checkbox" name="flexible_time" value="1" <?= $flexibleTime ? 'checked' : '' ?>>
                                Flexible departure time (±45-90 mins window)
                            </label>
                        </div>
                        <div class="search-checkbox-group">
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; color: #fbcfe8; font-weight: 600;">
                                <input type="checkbox" name="women_only" value="1" <?= $womenOnlyFilter ? 'checked' : '' ?> style="accent-color: #db2777;">
                                🌸 Women-Only Carpools Only
                            </label>
                        </div>
                    </div>

                    <?php if (!empty($userFavorites)): ?>
                        <div class="quick-picks">
                            <span class="quick-picks-label">⭐ Your Commutes:</span>
                            <?php foreach ($userFavorites as $fav): ?>
                                <button type="button" class="quick-chip" style="background: rgba(251, 191, 36, 0.25); border-color: rgba(251, 191, 36, 0.5);" onclick="setDest('<?= htmlspecialchars(addslashes($fav['Address'])) ?>')">
                                    📍 <?= htmlspecialchars($fav['Address']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="quick-picks">
                        <span class="quick-picks-label">Quick Destinations:</span>
                        <button type="button" class="quick-chip" onclick="setDest('BRAC University')">🎓 BRAC University</button>
                        <button type="button" class="quick-chip" onclick="setDest('Mirpur 10')">Mirpur 10</button>
                        <button type="button" class="quick-chip" onclick="setDest('Mohakhali')">Mohakhali</button>
                        <button type="button" class="quick-chip" onclick="setDest('Uttara')">Uttara</button>
                        <button type="button" class="quick-chip" onclick="setDest('Dhanmondi')">Dhanmondi</button>
                        <button type="button" class="quick-chip" onclick="setDest('Bashundhara R/A')">Bashundhara</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Search Results / Feed Header -->
        <div class="section-header">
            <div>
                <h2 class="section-title">
                    <?= $hasSearched ? 'Compatible Rides Found (' . count($matchedRides) . ')' : 'Available Rides Today & Upcoming' ?>
                </h2>
            </div>
            <div>
                <a href="offer_ride.php" class="btn btn-primary btn-sm">
                    + Offer a Ride
                </a>
            </div>
        </div>

        <?php if ($hasSearched): ?>
            <div class="filter-banner">
                <div class="filter-tags">
                    <span>Searching for:</span>
                    <span class="filter-tag">To: <?= htmlspecialchars($searchDest) ?></span>
                    <?php if (!empty($searchStart)): ?>
                        <span class="filter-tag">From: <?= htmlspecialchars($searchStart) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($searchDate)): ?>
                        <span class="filter-tag">Date: <?= format_ride_date($searchDate) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($searchTime)): ?>
                        <span class="filter-tag">Time: <?= format_time_12h($searchTime) ?></span>
                    <?php endif; ?>
                    <?php if ($flexibleTime): ?>
                        <span class="filter-tag">Flexible Window</span>
                    <?php endif; ?>
                </div>
                <div>
                    <a href="index.php" style="color: var(--danger); text-decoration: none; font-size: 0.85rem; font-weight: 600;">Clear Filter ✕</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Ride Cards Grid -->
        <?php if (empty($matchedRides)): ?>
            <div class="empty-state-card">
                <div class="empty-state-icon">🔍</div>
                <h3>No rides found for this search</h3>
                <p>
                    <?php if ($hasSearched): ?>
                        We couldn't find an exact ride match for your destination/time. Try enabling the flexible time option, selecting a wider area, or be the first to offer a ride!
                    <?php else: ?>
                        No upcoming rides scheduled right now. You can offer the first ride for your fellow students!
                    <?php endif; ?>
                </p>
                <div class="empty-state-actions">
                    <?php if ($hasSearched && !$flexibleTime): ?>
                        <a href="index.php?search=1&dest=<?= urlencode($searchDest) ?>&start=<?= urlencode($searchStart) ?>&date=<?= urlencode($searchDate) ?>&time=<?= urlencode($searchTime) ?>&flexible_time=1" class="btn btn-secondary">
                            Try Wider Time Window
                        </a>
                    <?php endif; ?>
                    <a href="offer_ride.php" class="btn btn-primary">
                        Offer Your Own Ride
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="rides-grid">
                <?php foreach ($matchedRides as $ride): ?>
                    <div class="ride-card">
                        <div>
                            <!-- Header: Driver Info -->
                            <div class="ride-card-header">
                                <div class="driver-info">
                                    <div class="driver-avatar">
                                        <?= strtoupper(substr($ride['DriverName'], 0, 1)) ?>
                                    </div>
                                    <div class="driver-details">
                                        <a href="profile.php?id=<?= $ride['DriverID'] ?>" class="driver-name">
                                            <?= htmlspecialchars($ride['DriverName']) ?>
                                            <?= render_verification_badge($ride['UniversityVerified']) ?>
                                        </a>
                                        <div class="driver-meta">
                                            <span class="rating-badge">★ <?= number_format($ride['DriverRating'], 1) ?></span>
                                            <span>(<?= $ride['DriverRatingCount'] ?> reviews)</span>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($ride['matchScore'])): ?>
                                    <span class="match-score-badge" title="Matching compatibility score">
                                        <?= min(100, $ride['matchScore']) ?>% Match
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Route -->
                            <div class="ride-route">
                                <div class="route-point start">
                                    <span class="route-dot green"></span>
                                    <span><?= htmlspecialchars($ride['StartLocation']) ?></span>
                                </div>
                                <div class="route-arrow-connector"></div>
                                <div class="route-point dest">
                                    <span class="route-dot blue"></span>
                                    <span><?= htmlspecialchars($ride['Destination']) ?></span>
                                </div>
                            </div>

                            <!-- Meta details -->
                            <div class="ride-meta-row">
                                <span class="meta-chip">
                                    📅 <?= format_ride_date($ride['RideDate']) ?>
                                </span>
                                <span class="meta-chip">
                                    ⏰ <?= format_time_12h($ride['DepartureTime']) ?>
                                </span>
                                
                                <?php if ((int)$ride['AvailableSeats'] > 0): ?>
                                    <span class="meta-chip seats">
                                        💺 <?= $ride['AvailableSeats'] ?> seat<?= $ride['AvailableSeats'] > 1 ? 's' : '' ?> left
                                    </span>
                                <?php else: ?>
                                    <span class="meta-chip full">
                                        🔴 Ride Full
                                    </span>
                                <?php endif; ?>

                                <?php if (floatval($ride['SharedCost']) > 0): ?>
                                    <span class="meta-chip cost">
                                        ৳<?= number_format($ride['SharedCost'], 0) ?>/person
                                    </span>
                                <?php else: ?>
                                    <span class="meta-chip cost" style="background:#f0fdf4; color:#16a34a;">
                                        Free Ride
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($ride['VehicleInfo'])): ?>
                                    <span class="meta-chip" title="Vehicle">
                                        🚗 <?= htmlspecialchars($ride['VehicleInfo']) ?>
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($ride['IsWomenOnly'])): ?>
                                    <span class="meta-chip" style="background: #fdf2f8; color: #9d174d; font-weight: 700; border: 1px solid #fbcfe8;">
                                        🌸 Women-Only
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($ride['Notes'])): ?>
                                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem; font-style: italic;">
                                    "<?= htmlspecialchars($ride['Notes']) ?>"
                                </p>
                            <?php endif; ?>
                        </div>

                        <!-- Card Actions -->
                        <div class="ride-card-actions">
                            <a href="ride_details.php?id=<?= $ride['RideID'] ?>" class="btn btn-secondary btn-sm">
                                View Details
                            </a>

                            <?php if ($isLoggedIn && (int)$ride['DriverID'] === (int)$currentUserId): ?>
                                <a href="ride_details.php?id=<?= $ride['RideID'] ?>" class="btn btn-accent btn-sm">
                                    Your Ride
                                </a>
                            <?php elseif ($ride['IsAcceptedPassenger']): ?>
                                <span class="btn btn-success btn-sm" style="cursor: default;">
                                    ✓ Joined
                                </span>
                            <?php elseif ($ride['HasPendingRequest']): ?>
                                <span class="btn btn-secondary btn-sm" style="color: var(--warning); cursor: default;">
                                    ⏳ Requested
                                </span>
                            <?php elseif ((int)$ride['AvailableSeats'] <= 0): ?>
                                <button class="btn btn-secondary btn-sm" disabled style="opacity: 0.6;">
                                    Full
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openJoinModal(<?= $ride['RideID'] ?>, '<?= htmlspecialchars(addslashes($ride['DriverName'])) ?>', '<?= htmlspecialchars(addslashes($ride['StartLocation'])) ?>', '<?= htmlspecialchars(addslashes($ride['Destination'])) ?>')">
                                    Request to Join
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Admin Live SQL Viewer (Preserved for Administrative diagnostics) -->
        <?php if ($isLoggedIn && $currentUserRole === 'Admin'): ?>
            <div class="card" style="margin-top: 2rem;">
                <h2>🛠️ Admin Database Diagnostic Console</h2>
                <?php if (!empty($adminQueryError)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($adminQueryError) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <textarea name="sql_query" class="form-control" rows="2" placeholder="SELECT * FROM `User` LIMIT 10"><?= htmlspecialchars($adminExecutedQuery) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm">Execute Diagnostic Query</button>
                </form>

                <?php if ($adminQueryResults !== null): ?>
                    <div style="overflow-x: auto; margin-top: 1rem;">
                        <table class="user-table">
                            <thead>
                                <tr>
                                    <?php if (!empty($adminQueryResults)): ?>
                                        <?php foreach (array_keys($adminQueryResults[0]) as $col): ?>
                                            <th><?= htmlspecialchars($col) ?></th>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($adminQueryResults as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $val): ?>
                                            <td><?= htmlspecialchars($val ?? 'NULL') ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Request to Join Modal -->
    <div id="joinModal" class="modal-overlay">
        <div class="modal-box">
            <h3 style="font-size: 1.3rem; margin-bottom: 0.5rem; color: var(--primary);">Request to Join Ride</h3>
            <p id="modalRideInfo" style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1.25rem;"></p>

            <form method="POST" action="api_actions.php">
                <input type="hidden" name="action" value="request_join">
                <input type="hidden" id="modalRideId" name="ride_id" value="">

                <div class="form-group">
                    <label>Pickup Location / Note to Driver (Optional)</label>
                    <input type="text" name="pickup_note" class="form-control" placeholder="e.g. Can pick me up near Sony Cinema?">
                </div>

                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">Send Request</button>
                    <button type="button" class="btn btn-secondary" onclick="closeJoinModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function setDest(destination) {
            document.getElementById('destInput').value = destination;
        }

        function openJoinModal(rideId, driverName, start, dest) {
            <?php if (!$isLoggedIn): ?>
                window.location.href = 'login.php';
                return;
            <?php endif; ?>
            document.getElementById('modalRideId').value = rideId;
            document.getElementById('modalRideInfo').innerHTML = 'Ride with <strong>' + driverName + '</strong> (' + start + ' → ' + dest + ')';
            document.getElementById('joinModal').classList.add('active');
        }

        function closeJoinModal() {
            document.getElementById('joinModal').classList.remove('active');
        }
    </script>

    <?php render_footer(); ?>
</body>
</html>