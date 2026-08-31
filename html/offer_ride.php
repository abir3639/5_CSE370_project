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

if ($userRole === 'Admin') {
    $_SESSION['error_msg'] = "🛡️ Administrator accounts cannot offer rides. Please switch to a driver account to post rides.";
    header('Location: admin.php');
    exit;
}

$error = '';
$startLocation = $_POST['start_location'] ?? 'Mirpur 10';
$startLat = $_POST['start_lat'] ?? '23.8069';
$startLng = $_POST['start_lng'] ?? '90.3687';
$destination = $_POST['destination'] ?? 'BRAC University';
$destLat = $_POST['dest_lat'] ?? '23.7781';
$destLng = $_POST['dest_lng'] ?? '90.4265';

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
    } elseif ($isWomenOnly) {
        $uGenStmt = $pdo->prepare("SELECT Gender FROM `User` WHERE UserID = ?");
        $uGenStmt->execute([$currentUserId]);
        $driverGender = $uGenStmt->fetchColumn();
        if ($driverGender !== 'Female') {
            $error = "🌸 Only verified female drivers can offer Women-Only carpools.";
        }
    }
    
    if (empty($error)) {
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

            // Geocode start and destination locations using provided coordinates or fallback lookup
            $startGeo = geocode_location($startLocation, $startLat, $startLng);
            $destGeo = geocode_location($destination, $destLat, $destLng);

            $distanceKm = get_distance_km($startGeo['lat'], $startGeo['lng'], $destGeo['lat'], $destGeo['lng']);

            // Insert Ride with verified coordinates
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
            flex: 1 1 calc(33.333% - 0.5rem);
            min-width: 110px;
            padding: 0.6rem 0.5rem;
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            transition: all 0.15s ease;
        }
        @media (max-width: 600px) {
            .route-preset-btn {
                flex: 1 1 calc(50% - 0.5rem);
            }
        }
        @media (max-width: 400px) {
            .route-preset-btn {
                flex: 1 1 100%;
            }
        }
        .route-preset-btn:hover {
            background: #e2e8f0;
            border-color: var(--primary);
        }
        #previewMap {
            height: 240px;
            width: 100%;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
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

                <!-- Quick Presets -->
                <div class="route-toggle-buttons">
                    <button type="button" class="route-preset-btn" onclick="setPreset('Mohammadpur', 23.7542, 90.3587, 'BRAC University', 23.7781, 90.4265)">
                        Mohammadpur → BRACU
                    </button>
                    <button type="button" class="route-preset-btn" onclick="setPreset('Mirpur 10', 23.8069, 90.3687, 'BRAC University', 23.7781, 90.4265)">
                        Mirpur 10 → BRACU
                    </button>
                    <button type="button" class="route-preset-btn" onclick="setPreset('BRAC University', 23.7781, 90.4265, 'Mirpur 10', 23.8069, 90.3687)">
                        BRACU → Mirpur 10
                    </button>
                    <button type="button" class="route-preset-btn" onclick="setPreset('Uttara', 23.8759, 90.3795, 'BRAC University', 23.7781, 90.4265)">
                        Uttara → BRACU
                    </button>
                    <button type="button" class="route-preset-btn" onclick="setPreset('Dhanmondi', 23.7465, 90.3760, 'BRAC University', 23.7781, 90.4265)">
                        Dhanmondi → BRACU
                    </button>
                </div>

                <!-- Interactive Route Map Preview -->
                <div id="previewMap"></div>

                <form method="POST" id="offerRideForm">
                    <input type="hidden" name="create_ride" value="1">
                    <input type="hidden" id="start_lat" name="start_lat" value="<?= htmlspecialchars($startLat) ?>">
                    <input type="hidden" id="start_lng" name="start_lng" value="<?= htmlspecialchars($startLng) ?>">
                    <input type="hidden" id="dest_lat" name="dest_lat" value="<?= htmlspecialchars($destLat) ?>">
                    <input type="hidden" id="dest_lng" name="dest_lng" value="<?= htmlspecialchars($destLng) ?>">

                    <!-- Uber-Style Location Inputs -->
                    <div class="form-row-2col" style="margin-bottom: 1.25rem;">
                        <div>
                            <label style="display: block; margin-bottom: 0.45rem; font-weight: 700; font-size: 0.85rem; color: #334155;">
                                Starting Location (Pickup) *
                            </label>
                            <div class="location-input-card" id="startLocCard">
                                <span style="font-size: 1.3rem;">📍</span>
                                <div class="loc-card-text">
                                    <div class="loc-card-label">From</div>
                                    <input type="text" id="start_location" name="start_location" class="form-control" style="border: none; padding: 0; font-weight: 700; background: transparent; cursor: pointer;" value="<?= htmlspecialchars($startLocation) ?>" readonly required>
                                </div>
                                <span class="loc-card-action-chip">Change on Map 🗺️</span>
                            </div>
                        </div>

                        <div>
                            <label style="display: block; margin-bottom: 0.45rem; font-weight: 700; font-size: 0.85rem; color: #334155;">
                                Destination (Drop-off) *
                            </label>
                            <div class="location-input-card" id="destLocCard">
                                <span style="font-size: 1.3rem;">🎯</span>
                                <div class="loc-card-text">
                                    <div class="loc-card-label">To</div>
                                    <input type="text" id="destination" name="destination" class="form-control" style="border: none; padding: 0; font-weight: 700; background: transparent; cursor: pointer;" value="<?= htmlspecialchars($destination) ?>" readonly required>
                                </div>
                                <span class="loc-card-action-chip">Change on Map 🗺️</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-row-2col">
                        <div class="form-group">
                            <label>Ride Date *</label>
                            <input type="date" name="ride_date" class="form-control" value="<?= htmlspecialchars($rideDate) ?>" min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Departure Time *</label>
                            <input type="time" name="departure_time" class="form-control" value="<?= htmlspecialchars($departureTime) ?>" required>
                        </div>
                    </div>

                    <div class="form-row-2col">
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
                        <label>Pickup Landmark / Additional Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Will wait in front of Star Kabab. AC is on."><?= htmlspecialchars($notes) ?></textarea>
                    </div>

                    <div class="form-group" style="background: #fdf2f8; border: 1px solid #fbcfe8; padding: 1rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: #9d174d; font-weight: 700; margin-bottom: 0;">
                            <input type="checkbox" name="is_women_only" value="1" <?= $isWomenOnly ? 'checked' : '' ?>>
                            🌸 Women-Only Ride (Only female university students can request to join)
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; font-size: 1.05rem; padding: 1rem; font-weight: 800;">
                        Publish Ride Offer 🚀
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Reusable Location Picker Script -->
    <script src="location_picker.js"></script>
    <script>
        let previewMap = null;
        let startMarker = null;
        let destMarker = null;
        let routePolyline = null;

        function initPreviewMap() {
            if (typeof L === 'undefined') return;

            const sLat = parseFloat(document.getElementById('start_lat').value) || 23.8069;
            const sLng = parseFloat(document.getElementById('start_lng').value) || 90.3687;
            const dLat = parseFloat(document.getElementById('dest_lat').value) || 23.7781;
            const dLng = parseFloat(document.getElementById('dest_lng').value) || 90.4265;

            previewMap = L.map('previewMap').setView([23.7781, 90.4000], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(previewMap);

            updatePreviewRoute();
        }

        function updatePreviewRoute() {
            if (!previewMap) return;

            const sLat = parseFloat(document.getElementById('start_lat').value) || 23.8069;
            const sLng = parseFloat(document.getElementById('start_lng').value) || 90.3687;
            const dLat = parseFloat(document.getElementById('dest_lat').value) || 23.7781;
            const dLng = parseFloat(document.getElementById('dest_lng').value) || 90.4265;

            if (startMarker) previewMap.removeLayer(startMarker);
            if (destMarker) previewMap.removeLayer(destMarker);
            if (routePolyline) previewMap.removeLayer(routePolyline);

            const startIcon = L.divIcon({
                className: 'uber-custom-map-pin',
                html: `<div class="pin-marker-body">📍</div>`,
                iconSize: [30, 30],
                iconAnchor: [15, 30]
            });

            const destIcon = L.divIcon({
                className: 'uber-custom-map-pin',
                html: `<div class="pin-marker-body">🎯</div>`,
                iconSize: [30, 30],
                iconAnchor: [15, 30]
            });

            startMarker = L.marker([sLat, sLng], { icon: startIcon }).addTo(previewMap)
                .bindPopup("<b>Pickup:</b> " + document.getElementById('start_location').value);
            
            destMarker = L.marker([dLat, dLng], { icon: destIcon }).addTo(previewMap)
                .bindPopup("<b>Destination:</b> " + document.getElementById('destination').value);

            routePolyline = L.polyline([[sLat, sLng], [dLat, dLng]], {
                color: '#0284c7',
                weight: 4,
                opacity: 0.85,
                dashArray: '8, 8'
            }).addTo(previewMap);

            const group = L.featureGroup([startMarker, destMarker]);
            previewMap.fitBounds(group.getBounds().pad(0.2));
        }

        function setPreset(sName, sLat, sLng, dName, dLat, dLng) {
            document.getElementById('start_location').value = sName;
            document.getElementById('start_lat').value = sLat;
            document.getElementById('start_lng').value = sLng;

            document.getElementById('destination').value = dName;
            document.getElementById('dest_lat').value = dLat;
            document.getElementById('dest_lng').value = dLng;

            updatePreviewRoute();
        }

        document.addEventListener('DOMContentLoaded', function () {
            initPreviewMap();

            // Bind Start Location Picker
            attachUberLocationPicker({
                triggerId: 'startLocCard',
                displayInputId: 'start_location',
                hiddenLatId: 'start_lat',
                hiddenLngId: 'start_lng',
                title: 'Where are you starting from?',
                isDestination: false,
                onChange: function () {
                    updatePreviewRoute();
                }
            });

            // Bind Destination Picker
            attachUberLocationPicker({
                triggerId: 'destLocCard',
                displayInputId: 'destination',
                hiddenLatId: 'dest_lat',
                hiddenLngId: 'dest_lng',
                title: 'Where are you going?',
                isDestination: true,
                onChange: function () {
                    updatePreviewRoute();
                }
            });
        });
    </script>

    <?php render_footer(); ?>
</body>
</html>
