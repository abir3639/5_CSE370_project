<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

$isLoggedIn = isset($_SESSION['user_id']);
$currentUserId = $_SESSION['user_id'] ?? null;
$currentUserRole = $_SESSION['user_type'] ?? 'Passenger';

$currentUserGender = null;
if ($isLoggedIn) {
    $genStmt = $pdo->prepare("SELECT Gender FROM `User` WHERE UserID = ?");
    $genStmt->execute([$currentUserId]);
    $currentUserGender = $genStmt->fetchColumn();
}

$msgSuccess = $_SESSION['success_msg'] ?? '';
$msgError = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Search Parameters
$searchDest = trim($_GET['dest'] ?? '');
$searchDestLat = isset($_GET['dest_lat']) && is_numeric($_GET['dest_lat']) ? floatval($_GET['dest_lat']) : null;
$searchDestLng = isset($_GET['dest_lng']) && is_numeric($_GET['dest_lng']) ? floatval($_GET['dest_lng']) : null;

$searchStart = trim($_GET['start'] ?? '');
$searchStartLat = isset($_GET['start_lat']) && is_numeric($_GET['start_lat']) ? floatval($_GET['start_lat']) : null;
$searchStartLng = isset($_GET['start_lng']) && is_numeric($_GET['start_lng']) ? floatval($_GET['start_lng']) : null;

$searchDate = trim($_GET['date'] ?? '');
$searchTime = trim($_GET['time'] ?? '');
$flexibleTime = isset($_GET['flexible_time']) && $_GET['flexible_time'] === '1';
$womenOnlyFilter = isset($_GET['women_only']) && $_GET['women_only'] === '1';

$hasSearched = isset($_GET['search']) || (!empty($searchDest) || !empty($searchStart) || !empty($searchDate));

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
    $query .= " AND r.RideDate >= ?";
    $params[] = $searchDate;
} else {
    // By default show today and upcoming rides
    $query .= " AND r.RideDate >= CURDATE()";
}

$query .= " ORDER BY r.RideDate ASC, r.DepartureTime ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rawRides = $stmt->fetchAll();

// Apply Smart Corridor & Coordinate Matching Algorithm
$matchedRides = [];
if ($hasSearched) {
    foreach ($rawRides as $ride) {
        $match = calculate_ride_match(
            $searchDest, 
            $searchStart, 
            $searchDate, 
            $searchTime, 
            $flexibleTime, 
            $ride, 
            $searchStartLat, 
            $searchStartLng, 
            $searchDestLat, 
            $searchDestLng
        );
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BRAC University Rideshare - Find & Share Rides</title>
    <link rel="stylesheet" href="style.css">
    <!-- Leaflet OpenStreetMap CDN -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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

        <!-- Uber-Style Search Engine Card -->
        <div class="hero-search-card">
            <h1>Where are you going?</h1>
            <p class="hero-subtitle">Search real Dhaka destinations or drop a pin on the map to find student carpools.</p>

            <form method="GET" action="index.php" id="heroSearchForm">
                <input type="hidden" name="search" value="1">
                <input type="hidden" id="search_dest_lat" name="dest_lat" value="<?= htmlspecialchars($searchDestLat ?? '') ?>">
                <input type="hidden" id="search_dest_lng" name="dest_lng" value="<?= htmlspecialchars($searchDestLng ?? '') ?>">
                <input type="hidden" id="search_start_lat" name="start_lat" value="<?= htmlspecialchars($searchStartLat ?? '') ?>">
                <input type="hidden" id="search_start_lng" name="start_lng" value="<?= htmlspecialchars($searchStartLng ?? '') ?>">

                <div class="search-form-grid">
                    
                    <!-- Destination Field (Primary) -->
                    <div class="search-input-group">
                        <label>Destination (Where to?)</label>
                        <div class="location-input-card" id="searchDestCard" style="background: rgba(255,255,255,0.95); padding: 0.65rem 0.85rem;">
                            <span style="font-size: 1.15rem;">🎯</span>
                            <div class="loc-card-text" style="margin-left: 0.5rem;">
                                <input type="text" id="destInput" name="dest" class="form-control" placeholder="Search destination (BRACU, Mirpur...)" value="<?= htmlspecialchars($searchDest) ?>" style="border: none; padding: 0; font-weight: 700; background: transparent; cursor: pointer; color: var(--text-main);" readonly>
                            </div>
                            <span class="loc-card-action-chip" style="font-size: 0.72rem; padding: 0.2rem 0.45rem;">🗺️ Map</span>
                        </div>
                    </div>

                    <!-- Starting Location Field -->
                    <div class="search-input-group">
                        <label>Pickup Location (Optional)</label>
                        <div class="location-input-card" id="searchStartCard" style="background: rgba(255,255,255,0.95); padding: 0.65rem 0.85rem;">
                            <span style="font-size: 1.15rem;">📍</span>
                            <div class="loc-card-text" style="margin-left: 0.5rem;">
                                <input type="text" id="startInput" name="start" class="form-control" placeholder="Search pickup (Mohammadpur, Dhanmondi...)" value="<?= htmlspecialchars($searchStart) ?>" style="border: none; padding: 0; font-weight: 700; background: transparent; cursor: pointer; color: var(--text-main);" readonly>
                            </div>
                            <span class="loc-card-action-chip" style="font-size: 0.72rem; padding: 0.2rem 0.45rem;">🗺️ Map</span>
                        </div>
                    </div>

                    <!-- Date -->
                    <div class="search-input-group">
                        <label>Travel Date (Optional)</label>
                        <div class="location-input-card" style="background: rgba(255,255,255,0.95); padding: 0.65rem 0.85rem;">
                            <span style="font-size: 1.15rem;">📅</span>
                            <div class="loc-card-text" style="margin-left: 0.5rem;">
                                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($searchDate) ?>" min="<?= date('Y-m-d') ?>" style="border: none; padding: 0; font-weight: 700; background: transparent; cursor: pointer; color: var(--text-main); width: 100%;">
                            </div>
                        </div>
                    </div>

                    <!-- Time -->
                    <div class="search-input-group">
                        <label>Departure Time</label>
                        <div class="location-input-card" style="background: rgba(255,255,255,0.95); padding: 0.65rem 0.85rem;">
                            <span style="font-size: 1.15rem;">⏰</span>
                            <div class="loc-card-text" style="margin-left: 0.5rem;">
                                <input type="time" name="time" class="form-control" value="<?= htmlspecialchars($searchTime) ?>" style="border: none; padding: 0; font-weight: 700; background: transparent; cursor: pointer; color: var(--text-main); width: 100%;">
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div style="display: flex; flex-direction: column; justify-content: flex-end;">
                        <button type="submit" class="btn-search-submit">
                            <svg style="width:18px;height:18px;" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                            Find Rides
                        </button>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-top: 0.75rem;">
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

                    <!-- Quick Destination Chips -->
                    <div class="quick-picks">
                        <span class="quick-picks-label">Quick Destinations:</span>
                        <button type="button" class="quick-chip" onclick="setQuickDest('BRAC University', 23.7781, 90.4265)">🎓 BRACU</button>
                        <button type="button" class="quick-chip" onclick="setQuickDest('Mohammadpur', 23.7542, 90.3587)">Mohammadpur</button>
                        <button type="button" class="quick-chip" onclick="setQuickDest('Mirpur 10', 23.8069, 90.3687)">Mirpur 10</button>
                        <button type="button" class="quick-chip" onclick="setQuickDest('Dhanmondi', 23.7465, 90.3760)">Dhanmondi</button>
                        <button type="button" class="quick-chip" onclick="setQuickDest('Uttara', 23.8759, 90.3795)">Uttara</button>
                        <button type="button" class="quick-chip" onclick="setQuickDest('Gulshan 1', 23.7785, 90.4172)">Gulshan 1</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Interactive Dhaka Rides & Routes Map Section -->
        <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1.75rem; box-shadow: var(--shadow-sm);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; flex-wrap: wrap; gap: 0.5rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1.25rem;">🗺️</span>
                    <h3 style="font-size: 1.1rem; font-weight: 800; color: var(--primary); margin: 0;">Live Dhaka Rides & Route Map</h3>
                </div>
                <span style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); background: #f1f5f9; padding: 0.25rem 0.65rem; border-radius: 20px;">
                    📍 Tap anywhere or click cars to view rides
                </span>
            </div>
            <div id="homepageRidesMap" style="height: 280px; width: 100%; border-radius: 12px; border: 1px solid #cbd5e1; z-index: 1;"></div>
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
                        <a href="index.php?search=1&dest=<?= urlencode($searchDest) ?>&dest_lat=<?= urlencode($searchDestLat ?? '') ?>&dest_lng=<?= urlencode($searchDestLng ?? '') ?>&start=<?= urlencode($searchStart) ?>&start_lat=<?= urlencode($searchStartLat ?? '') ?>&start_lng=<?= urlencode($searchStartLng ?? '') ?>&date=<?= urlencode($searchDate) ?>&time=<?= urlencode($searchTime) ?>&flexible_time=1" class="btn btn-secondary">
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

                            <!-- Match Details Tags if present -->
                            <?php if (!empty($ride['matchDetails'])): ?>
                                <div style="display: flex; gap: 0.35rem; flex-wrap: wrap; margin-bottom: 0.75rem;">
                                    <?php foreach ($ride['matchDetails'] as $det): ?>
                                        <span style="font-size: 0.72rem; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 0.15rem 0.45rem; border-radius: 4px;">
                                            ✓ <?= htmlspecialchars($det) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

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

                            <?php if ($isLoggedIn && $currentUserRole === 'Admin'): ?>
                                <span class="btn btn-secondary btn-sm" style="opacity: 0.8;" title="Admins can view and manage rides">
                                    🛡️ Admin View
                                </span>
                            <?php elseif ($isLoggedIn && (int)$ride['DriverID'] === (int)$currentUserId): ?>
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
                            <?php elseif (!empty($ride['IsWomenOnly']) && $currentUserGender !== 'Female'): ?>
                                <button class="btn btn-secondary btn-sm" disabled style="opacity: 0.5; cursor: not-allowed;" title="Restricted to female passengers only">
                                    🌸 Women-Only
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openJoinModal(<?= $ride['RideID'] ?>, '<?= htmlspecialchars(addslashes($ride['DriverName'])) ?>', '<?= htmlspecialchars(addslashes($ride['StartLocation'])) ?>', '<?= htmlspecialchars(addslashes($ride['Destination'])) ?>', <?= !empty($ride['IsWomenOnly']) ? 1 : 0 ?>)">
                                    Request to Join
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
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

    <!-- Reusable Location Picker Script -->
    <script src="location_picker.js"></script>
    <script>
        function setQuickDest(destination, lat, lng) {
            document.getElementById('destInput').value = destination;
            if (lat && lng) {
                document.getElementById('search_dest_lat').value = lat;
                document.getElementById('search_dest_lng').value = lng;
            }
        }

        function openJoinModal(rideId, driverName, start, dest, isWomenOnly = 0) {
            <?php if (!$isLoggedIn): ?>
                window.location.href = 'login.php';
                return;
            <?php endif; ?>

            <?php if ($isLoggedIn && $currentUserGender !== 'Female'): ?>
                if (isWomenOnly) {
                    alert("🌸 This ride is designated as Women-Only. Only verified female university members can request to join.");
                    return;
                }
            <?php endif; ?>

            document.getElementById('modalRideId').value = rideId;
            document.getElementById('modalRideInfo').innerHTML = (isWomenOnly ? '<span style="color:#db2777; font-weight:800;">🌸 Women-Only Ride: </span>' : '') + 'Ride with <strong>' + driverName + '</strong> (' + start + ' → ' + dest + ')';
            document.getElementById('joinModal').classList.add('active');
        }

        function closeJoinModal() {
            document.getElementById('joinModal').classList.remove('active');
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Bind Destination Location Picker
            attachUberLocationPicker({
                triggerId: 'searchDestCard',
                displayInputId: 'destInput',
                hiddenLatId: 'search_dest_lat',
                hiddenLngId: 'search_dest_lng',
                title: 'Where are you going?',
                isDestination: true,
                onChange: function(loc) {
                    if (homeMap && loc.lat && loc.lng) {
                        homeMap.setView([loc.lat, loc.lng], 14);
                    }
                }
            });

            // Bind Start Location Picker
            attachUberLocationPicker({
                triggerId: 'searchStartCard',
                displayInputId: 'startInput',
                hiddenLatId: 'search_start_lat',
                hiddenLngId: 'search_start_lng',
                title: 'Where are you starting from?',
                isDestination: false,
                onChange: function(loc) {
                    if (homeMap && loc.lat && loc.lng) {
                        homeMap.setView([loc.lat, loc.lng], 14);
                    }
                }
            });

            // Initialize Live Dhaka Homepage Rides Map
            let homeMap = null;
            if (typeof L !== 'undefined' && document.getElementById('homepageRidesMap')) {
                homeMap = L.map('homepageRidesMap', {
                    center: [23.7781, 90.4000],
                    zoom: 12,
                    zoomControl: true
                });

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap'
                }).addTo(homeMap);

                // Auto invalidate sizes after DOM paint
                setTimeout(() => { if (homeMap) homeMap.invalidateSize(); }, 150);
                setTimeout(() => { if (homeMap) homeMap.invalidateSize(); }, 400);

                // BRAC University Campus Marker
                const bracuIcon = L.divIcon({
                    className: 'uber-custom-map-pin',
                    html: '<div style="background:#003366;color:#ffffff;font-size:0.78rem;font-weight:800;padding:0.3rem 0.6rem;border-radius:14px;box-shadow:0 3px 8px rgba(0,0,0,0.3);white-space:nowrap;border:1.5px solid #ffffff;">🎓 BRAC University</div>',
                    iconAnchor: [60, 20]
                });
                L.marker([23.7781, 90.4265], { icon: bracuIcon }).addTo(homeMap).bindPopup('<strong>BRAC University (New Campus)</strong><br>Merul Badda, Dhaka');

                // Plot Active Rides on Map
                <?php 
                $activeRidesJson = [];
                foreach ($rawRides as $r) {
                    $sCoord = geocode_location($r['StartLocation'], $r['StartLatitude'] ?? null, $r['StartLongitude'] ?? null);
                    $dCoord = geocode_location($r['Destination'], $r['DestinationLatitude'] ?? null, $r['DestinationLongitude'] ?? null);
                    if ($sCoord && $dCoord && !empty($sCoord['lat']) && !empty($dCoord['lat'])) {
                        $activeRidesJson[] = [
                            'id' => $r['RideID'],
                            'driver' => $r['DriverName'],
                            'start' => $r['StartLocation'],
                            'dest' => $r['Destination'],
                            'sLat' => $sCoord['lat'],
                            'sLng' => $sCoord['lng'],
                            'dLat' => $dCoord['lat'],
                            'dLng' => $dCoord['lng'],
                            'time' => format_time_12h($r['DepartureTime']),
                            'date' => format_ride_date($r['RideDate']),
                            'seats' => $r['AvailableSeats'],
                            'cost' => $r['SharedCost'],
                            'isWomenOnly' => (int)($r['IsWomenOnly'] ?? 0)
                        ];
                    }
                }
                ?>
                const ridesData = <?= json_encode($activeRidesJson) ?>;
                ridesData.forEach(ride => {
                    const isWomenOnlyRide = ride.isWomenOnly === 1;
                    const carIcon = L.divIcon({
                        className: 'uber-custom-map-pin',
                        html: '<div style="background:' + (isWomenOnlyRide ? '#fdf2f8' : '#ffffff') + ';border:2px solid ' + (isWomenOnlyRide ? '#db2777' : '#0284c7') + ';color:#0f172a;font-size:1.1rem;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 3px 8px rgba(0,0,0,0.2);cursor:pointer;" title="' + (isWomenOnlyRide ? '🌸 Women-Only · Driver: ' : 'Driver: ') + ride.driver + '">' + (isWomenOnlyRide ? '🌸' : '🚗') + '</div>',
                        iconSize: [32, 32],
                        iconAnchor: [16, 16]
                    });

                    const popupHtml = `
                        <div style="min-width:180px; font-family:sans-serif;">
                            <strong style="color:${isWomenOnlyRide ? '#be185d' : '#003366'}; font-size:0.95rem;">${isWomenOnlyRide ? '🌸 Women-Only Ride' : '🚗 Ride'} with ${ride.driver}</strong>
                            <div style="font-size:0.8rem; margin:0.3rem 0; color:#475569;">
                                📍 <b>From:</b> ${ride.start}<br>
                                🎯 <b>To:</b> ${ride.dest}<br>
                                ⏰ <b>Departure:</b> ${ride.date}, ${ride.time}<br>
                                💺 <b>Seats Left:</b> ${ride.seats} | <b>Cost:</b> ৳${ride.cost}
                            </div>
                            <button onclick="openJoinModal(${ride.id}, '${ride.driver}', '${ride.start}', '${ride.dest}', ${ride.isWomenOnly})" class="btn btn-primary btn-sm" style="width:100%; padding:0.35rem 0.5rem; font-size:0.8rem; margin-top:0.3rem; ${isWomenOnlyRide ? 'background:#db2777; border-color:#db2777;' : ''}">Request to Join</button>
                        </div>
                    `;

                    L.marker([ride.sLat, ride.sLng], { icon: carIcon }).addTo(homeMap).bindPopup(popupHtml);

                    // Route Polyline
                    L.polyline([[ride.sLat, ride.sLng], [ride.dLat, ride.dLng]], {
                        color: '#0284c7',
                        weight: 3.5,
                        opacity: 0.65,
                        dashArray: '6, 8'
                    }).addTo(homeMap);
                });

                // User Searched Start & Destination Markers
                <?php if (!empty($searchStartLat) && !empty($searchStartLng)): ?>
                    const startPin = L.divIcon({
                        className: 'uber-custom-map-pin',
                        html: '<div style="background:#0284c7;color:#fff;font-weight:800;padding:0.25rem 0.6rem;border-radius:12px;box-shadow:0 3px 8px rgba(0,0,0,0.3);font-size:0.75rem;">📍 <?= htmlspecialchars($searchStart ?: "Pickup") ?></div>',
                        iconAnchor: [30, 25]
                    });
                    L.marker([<?= (float)$searchStartLat ?>, <?= (float)$searchStartLng ?>], { icon: startPin }).addTo(homeMap);
                <?php endif; ?>

                <?php if (!empty($searchDestLat) && !empty($searchDestLng)): ?>
                    const destPin = L.divIcon({
                        className: 'uber-custom-map-pin',
                        html: '<div style="background:#10b981;color:#fff;font-weight:800;padding:0.25rem 0.6rem;border-radius:12px;box-shadow:0 3px 8px rgba(0,0,0,0.3);font-size:0.75rem;">🎯 <?= htmlspecialchars($searchDest ?: "Destination") ?></div>',
                        iconAnchor: [30, 25]
                    });
                    L.marker([<?= (float)$searchDestLat ?>, <?= (float)$searchDestLng ?>], { icon: destPin }).addTo(homeMap);
                    homeMap.setView([<?= (float)$searchDestLat ?>, <?= (float)$searchDestLng ?>], 13);
                <?php endif; ?>

                // Interactive click on homepage map
                homeMap.on('click', function(e) {
                    const lat = e.latlng.lat;
                    const lng = e.latlng.lng;

                    fetch(`api_geocode.php?action=reverse&lat=${lat}&lng=${lng}`)
                        .then(r => r.json())
                        .then(data => {
                            const name = data && data.name ? data.name : `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
                            const popup = L.popup()
                                .setLatLng([lat, lng])
                                .setContent(`
                                    <div style="font-family:sans-serif; font-size:0.85rem;">
                                        <strong>📍 ${name}</strong><br>
                                        <div style="display:flex; gap:0.4rem; margin-top:0.4rem;">
                                            <button onclick="setQuickDest('${name.replace(/'/g, "\\'")}', ${lat}, ${lng}); homeMap.closePopup();" class="btn btn-primary btn-sm" style="font-size:0.75rem; padding:0.25rem 0.5rem;">Set as Destination</button>
                                        </div>
                                    </div>
                                `)
                                .openOn(homeMap);
                        });
                });
            }
        });
    </script>

    <?php render_footer(); ?>
</body>
</html>
