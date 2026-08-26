<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_msg'] = "Please log in to offer a ride.";
    header('Location: login.php');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_type'] ?? 'Passenger';

$error = '';
$startLocation = $_POST['start_location'] ?? 'Mirpur 10';
$destination = $_POST['destination'] ?? 'BRAC University';
$rideDate = $_POST['ride_date'] ?? date('Y-m-d', strtotime('+1 day'));
$departureTime = $_POST['departure_time'] ?? '08:00';
$availableSeats = (int)($_POST['available_seats'] ?? 3);
$sharedCost = $_POST['shared_cost'] ?? '120.00';
$vehicleInfo = $_POST['vehicle_info'] ?? '';
$notes = trim($_POST['notes'] ?? '');
$isWomenOnly = isset($_POST['is_women_only']) ? 1 : 0;

// Pre-fill vehicle info if driver has one
if (empty($vehicleInfo)) {
    $vStmt = $pdo->prepare("SELECT Model, RegNo FROM `Vehicle` WHERE UserID = ? LIMIT 1");
    $vStmt->execute([$currentUserId]);
    $veh = $vStmt->fetch();
    if ($veh) {
        $vehicleInfo = $veh['Model'] . ' (' . $veh['RegNo'] . ')';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ride'])) {
    if (empty($startLocation) || empty($destination) || empty($rideDate) || empty($departureTime)) {
        $error = "Please fill in all required route and timing fields.";
    } elseif ($availableSeats < 1 || $availableSeats > 8) {
        $error = "Available seats must be between 1 and 8.";
    } elseif (strtolower(trim($startLocation)) === strtolower(trim($destination))) {
        $error = "Starting location and destination cannot be identical.";
    } else {
        try {
            $pdo->beginTransaction();

            // Auto-promote/register user as Driver if not already in Driver table
            $dChk = $pdo->prepare("SELECT UserID FROM `Driver` WHERE UserID = ?");
            $dChk->execute([$currentUserId]);
            if (!$dChk->fetch()) {
                $license = 'DL-' . strtoupper(substr(md5(uniqid()), 0, 8));
                $pdo->prepare("INSERT IGNORE INTO `Driver` (`UserID`, `LicenseNo`) VALUES (?, ?)")->execute([$currentUserId, $license]);
                $pdo->prepare("UPDATE `User` SET `UserType` = 'Driver' WHERE `UserID` = ?")->execute([$currentUserId]);
                $_SESSION['user_type'] = 'Driver';
            }

            // Save vehicle if provided
            if (!empty($vehicleInfo)) {
                $regNo = 'REG-' . rand(1000, 9999);
                if (preg_match('/\((.*?)\)/', $vehicleInfo, $matches)) {
                    $regNo = trim($matches[1]);
                }
                $pdo->prepare("INSERT INTO `Vehicle` (`RegNo`, `Model`, `UserID`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE Model = ?")
                    ->execute([$regNo, $vehicleInfo, $currentUserId, $vehicleInfo]);
            }

            // Geocode start and destination locations
            $startGeo = geocode_location($startLocation);
            $destGeo = geocode_location($destination);

            $distanceKm = get_distance_km($startGeo['lat'], $startGeo['lng'], $destGeo['lat'], $destGeo['lng']);

            // Insert Ride
            $insRide = $pdo->prepare("
                INSERT INTO `Ride` (
                    `DriverID`, `StartLocation`, `Destination`, 
                    `StartLatitude`, `StartLongitude`, `DestinationLatitude`, `DestinationLongitude`, 
                    `RideDate`, `DepartureTime`, `AvailableSeats`, `TotalSeats`, 
                    `VehicleInfo`, `SharedCost`, `Notes`, `IsWomenOnly`, `Status`, `Distance`, `created_at`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?, NOW())
            ");
            $insRide->execute([
                $currentUserId,
                $startLocation,
                $destination,
                $startGeo['lat'],
                $startGeo['lng'],
                $destGeo['lat'],
                $destGeo['lng'],
                $rideDate,
                $departureTime,
                $availableSeats,
                $availableSeats,
                $vehicleInfo,
                floatval($sharedCost),
                $notes,
                $isWomenOnly,
                $distanceKm
            ]);
            $newRideId = $pdo->lastInsertId();

            // Insert Driver into RideParticipant
            $pdo->prepare("INSERT INTO `RideParticipant` (`RideID`, `UserID`, `Role`, `ArrivalStatus`, `JoinedAt`) VALUES (?, ?, 'Driver', 'Pending', NOW())")
                ->execute([$newRideId, $currentUserId]);

            $pdo->commit();
            $_SESSION['success_msg'] = "🎉 Your ride has been published! Other university students can now find and request to join it.";
            header("Location: ride_details.php?id=" . $newRideId);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to create ride: " . $e->getMessage();
        }
    }
}

$dhakaLocs = get_dhaka_locations();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offer a Ride - BRAC University Rideshare</title>
    <link rel="stylesheet" href="style.css">
    <!-- Leaflet OpenStreetMap CDN -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .offer-container {
            max-width: 850px;
            margin: 0 auto;
        }
        .route-toggle-buttons {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .route-preset-btn {
            flex: 1;
            min-width: 140px;
            padding: 0.6rem;
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            transition: all 0.15s ease;
        }
        .route-preset-btn:hover {
            background: #e2e8f0;
            border-color: var(--primary);
        }
        #previewMap {
            height: 200px;
            width: 100%;
            border-radius: 8px;
            margin-bottom: 1.25rem;
            border: 1px solid var(--border-color);
        }
    </style>
</head>
<body>
    <?php render_navbar('offer'); ?>

    <div class="main-container">
        <div class="offer-container">
            <div class="card">
                <h2>🚗 Offer / Share a Ride</h2>
                <p style="color: var(--text-muted); margin-bottom: 1.5rem; font-size: 0.95rem;">
                    Share your commute with verified fellow BRAC University students and split travel costs.
                </p>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="route-toggle-buttons">
                    <button type="button" class="route-preset-btn" onclick="setRoutePreset('Mirpur 10', 'BRAC University')">
                        Mirpur 10 → BRACU
                    </button>
                    <button type="button" class="route-preset-btn" onclick="setRoutePreset('BRAC University', 'Mirpur 10')">
                        BRACU → Mirpur 10
                    </button>
                    <button type="button" class="route-preset-btn" onclick="setRoutePreset('Uttara', 'BRAC University')">
                        Uttara → BRACU
                    </button>
                    <button type="button" class="route-preset-btn" onclick="setRoutePreset('BRAC University', 'Uttara')">
                        BRACU → Uttara
                    </button>
                    <button type="button" class="route-preset-btn" onclick="setRoutePreset('Dhanmondi', 'BRAC University')">
                        Dhanmondi → BRACU
                    </button>
                </div>

                <div id="previewMap"></div>

                <form method="POST">
                    <input type="hidden" name="create_ride" value="1">

                    <datalist id="dhakaLocations">
                        <?php foreach ($dhakaLocs as $name => $data): ?>
                            <option value="<?= htmlspecialchars($name) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label>Starting Location *</label>
                            <input type="text" id="start_location" name="start_location" list="dhakaLocations" class="form-control" placeholder="e.g. Mirpur 10, Dhanmondi" value="<?= htmlspecialchars($startLocation) ?>" onchange="updatePreviewMap()" required>
                        </div>
                        <div class="form-group">
                            <label>Destination *</label>
                            <input type="text" id="destination" name="destination" list="dhakaLocations" class="form-control" placeholder="e.g. BRAC University" value="<?= htmlspecialchars($destination) ?>" onchange="updatePreviewMap()" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label>Ride Date *</label>
                            <input type="date" name="ride_date" class="form-control" value="<?= htmlspecialchars($rideDate) ?>" min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Departure Time *</label>
                            <input type="time" name="departure_time" class="form-control" value="<?= htmlspecialchars($departureTime) ?>" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label>Available Seats *</label>
                            <select name="available_seats" class="form-control" required>
                                <?php for ($i = 1; $i <= 6; $i++): ?>
                                    <option value="<?= $i ?>" <?= $availableSeats == $i ? 'selected' : '' ?>><?= $i ?> seat<?= $i > 1 ? 's' : '' ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Shared Cost per Passenger (৳)</label>
                            <input type="number" step="10" name="shared_cost" class="form-control" placeholder="120 (0 for Free)" value="<?= htmlspecialchars($sharedCost) ?>">
                            <small style="color: var(--text-muted); font-size: 0.75rem;">Fair cost contribution, not a commercial taxi fare.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Vehicle Information</label>
                        <input type="text" name="vehicle_info" class="form-control" placeholder="e.g. Toyota Axio (DHA-1234), Honda Grace, Bike" value="<?= htmlspecialchars($vehicleInfo) ?>">
                    </div>

                    <div class="form-group">
                        <label>Notes & Route Flexibility (Optional)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. AC is on. Can pick up along Pragati Sarani or Mirpur Road. Please be on time!"><?= htmlspecialchars($notes) ?></textarea>
                    </div>

                    <div style="background: #fdf2f8; border: 1.5px solid #fbcfe8; padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
                        <input type="checkbox" id="is_women_only" name="is_women_only" value="1" <?= $isWomenOnly ? 'checked' : '' ?> style="width: 18px; height: 18px; accent-color: #db2777;">
                        <label for="is_women_only" style="margin-bottom: 0; font-size: 0.9rem; color: #9d174d; font-weight: 700; cursor: pointer;">
                            🌸 Women-Only Carpool (Seats restricted to female university riders)
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary" style="padding: 0.85rem; font-size: 1.05rem;">Publish Ride Offer</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        var map, startMarker, destMarker, polyline;
        var locData = <?= json_encode($dhakaLocs) ?>;

        document.addEventListener('DOMContentLoaded', function() {
            map = L.map('previewMap').setView([23.7781, 90.4265], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);

            updatePreviewMap();
        });

        function getCoords(name) {
            var n = name.trim();
            if (locData[n]) {
                return [locData[n].lat, locData[n].lng];
            }
            if (n.toLowerCase().includes('brac')) {
                return [23.7781, 90.4265];
            }
            if (n.toLowerCase().includes('mirpur')) {
                return [23.8069, 90.3687];
            }
            if (n.toLowerCase().includes('uttara')) {
                return [23.8759, 90.3795];
            }
            if (n.toLowerCase().includes('dhanmondi')) {
                return [23.7465, 90.3760];
            }
            return [23.7781, 90.4265];
        }

        function setRoutePreset(start, dest) {
            document.getElementById('start_location').value = start;
            document.getElementById('destination').value = dest;
            updatePreviewMap();
        }

        function updatePreviewMap() {
            var startName = document.getElementById('start_location').value || 'Mirpur 10';
            var destName = document.getElementById('destination').value || 'BRAC University';

            var startCoords = getCoords(startName);
            var destCoords = getCoords(destName);

            if (startMarker) map.removeLayer(startMarker);
            if (destMarker) map.removeLayer(destMarker);
            if (polyline) map.removeLayer(polyline);

            startMarker = L.marker(startCoords).addTo(map).bindPopup("<b>Start:</b> " + startName);
            destMarker = L.marker(destCoords).addTo(map).bindPopup("<b>Destination:</b> " + destName);

            polyline = L.polyline([startCoords, destCoords], {
                color: '#0284c7',
                weight: 4,
                opacity: 0.8,
                dashArray: '6, 6'
            }).addTo(map);

            var bounds = L.latLngBounds([startCoords, destCoords]);
            map.fitBounds(bounds, { padding: [30, 30] });
        }
    </script>

    <?php render_footer(); ?>
</body>
</html>
