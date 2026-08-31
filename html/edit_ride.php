<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_msg'] = "Please log in to edit your ride.";
    header('Location: login.php');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$rideId = (int)($_GET['id'] ?? $_POST['ride_id'] ?? 0);

if ($rideId <= 0) {
    $_SESSION['error_msg'] = "Invalid ride selection.";
    header('Location: my_rides.php');
    exit;
}

// Fetch ride details
$stmt = $pdo->prepare("SELECT * FROM `Ride` WHERE `RideID` = ? AND `DriverID` = ?");
$stmt->execute([$rideId, $currentUserId]);
$ride = $stmt->fetch();

if (!$ride) {
    $_SESSION['error_msg'] = "Ride not found or you are not authorized to edit this ride.";
    header('Location: my_rides.php');
    exit;
}

if ($ride['Status'] === 'Completed' || $ride['Status'] === 'Cancelled') {
    $_SESSION['error_msg'] = "Cannot edit a completed or cancelled ride.";
    header("Location: ride_details.php?id=$rideId");
    exit;
}

// Count confirmed/accepted passengers on this ride
$pCountStmt = $pdo->prepare("SELECT COUNT(*) FROM `RideParticipant` WHERE `RideID` = ? AND `Role` = 'Passenger'");
$pCountStmt->execute([$rideId]);
$occupiedSeats = (int)$pCountStmt->fetchColumn();

$error = '';
$startLocation = $_POST['start_location'] ?? $ride['StartLocation'];
$startLat = $_POST['start_lat'] ?? ($ride['StartLatitude'] ?? '23.8069');
$startLng = $_POST['start_lng'] ?? ($ride['StartLongitude'] ?? '90.3687');
$destination = $_POST['destination'] ?? $ride['Destination'];
$destLat = $_POST['dest_lat'] ?? ($ride['DestinationLatitude'] ?? '23.7781');
$destLng = $_POST['dest_lng'] ?? ($ride['DestinationLongitude'] ?? '90.4265');

$rideDate = $_POST['ride_date'] ?? $ride['RideDate'];
$departureTime = $_POST['departure_time'] ?? substr($ride['DepartureTime'], 0, 5);
$totalSeats = (int)($_POST['total_seats'] ?? $ride['TotalSeats']);
$sharedCost = $_POST['shared_cost'] ?? $ride['SharedCost'];
$vehicleInfo = $_POST['vehicle_info'] ?? $ride['VehicleInfo'];
$notes = trim($_POST['notes'] ?? $ride['Notes']);
$isWomenOnly = isset($_POST['is_women_only']) ? 1 : (isset($_POST['update_ride']) ? 0 : (int)$ride['IsWomenOnly']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ride'])) {
    if (empty($startLocation) || empty($destination) || empty($rideDate) || empty($departureTime)) {
        $error = "Please fill in all required route and timing fields.";
    } elseif ($totalSeats < 1 || $totalSeats > 8) {
        $error = "Total seats must be between 1 and 8.";
    } elseif ($totalSeats < $occupiedSeats) {
        $error = "Total seats cannot be less than the $occupiedSeats passenger(s) already accepted on this ride.";
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

            // Calculate new available seats
            $newAvailableSeats = $totalSeats - $occupiedSeats;
            $newStatus = ($newAvailableSeats <= 0) ? 'Full' : 'Open';

            // Geocode start and destination locations
            $startGeo = geocode_location($startLocation, $startLat, $startLng);
            $destGeo = geocode_location($destination, $destLat, $destLng);
            $distanceKm = get_distance_km($startGeo['lat'], $startGeo['lng'], $destGeo['lat'], $destGeo['lng']);

            // Update Ride in Database
            $upRide = $pdo->prepare("
                UPDATE `Ride` SET
                    `StartLocation` = ?,
                    `Destination` = ?,
                    `StartLatitude` = ?,
                    `StartLongitude` = ?,
                    `DestinationLatitude` = ?,
                    `DestinationLongitude` = ?,
                    `RideDate` = ?,
                    `DepartureTime` = ?,
                    `AvailableSeats` = ?,
                    `TotalSeats` = ?,
                    `VehicleInfo` = ?,
                    `SharedCost` = ?,
                    `Notes` = ?,
                    `IsWomenOnly` = ?,
                    `Status` = ?,
                    `Distance` = ?,
                    `updated_at` = NOW()
                WHERE `RideID` = ? AND `DriverID` = ?
            ");
            $upRide->execute([
                $startLocation,
                $destination,
                $startGeo['lat'],
                $startGeo['lng'],
                $destGeo['lat'],
                $destGeo['lng'],
                $rideDate,
                $departureTime,
                $newAvailableSeats,
                $totalSeats,
                $vehicleInfo,
                floatval($sharedCost),
                $notes,
                $isWomenOnly,
                $newStatus,
                $distanceKm,
                $rideId,
                $currentUserId
            ]);

            // Notify all joined passengers & pending requesters
            $recipients = [];

            // 1. Accepted/Joined Passengers
            $partStmt = $pdo->prepare("SELECT UserID FROM `RideParticipant` WHERE `RideID` = ? AND `Role` = 'Passenger'");
            $partStmt->execute([$rideId]);
            while ($uid = $partStmt->fetchColumn()) {
                $recipients[] = (int)$uid;
            }

            // 2. Pending Requesters
            $reqStmt = $pdo->prepare("SELECT PassengerID FROM `RideRequest` WHERE `RideID` = ? AND `Status` = 'Pending'");
            $reqStmt->execute([$rideId]);
            while ($uid = $reqStmt->fetchColumn()) {
                if (!in_array((int)$uid, $recipients)) {
                    $recipients[] = (int)$uid;
                }
            }

            $driverName = $_SESSION['name'] ?? 'The driver';
            $formattedDate = date('M j', strtotime($rideDate));
            $formattedTime = format_time_12h($departureTime);

            foreach ($recipients as $recipientId) {
                create_notification(
                    $pdo,
                    $recipientId,
                    'ride_update',
                    'Ride Details Updated ✏️',
                    "{$driverName} updated details for your ride ({$startLocation} → {$destination}) on {$formattedDate} at {$formattedTime}. Tap to review the updated schedule.",
                    "ride_details.php?id=$rideId"
                );
            }

            $pdo->commit();
            $_SESSION['success_msg'] = "✓ Ride details updated successfully! " . (count($recipients) > 0 ? count($recipients) . " passenger(s) have been notified of the changes." : "");
            header("Location: ride_details.php?id=$rideId");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to update ride: " . $e->getMessage();
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
    <title>Edit Ride #<?= $rideId ?> - BRAC University Rideshare</title>
    <link rel="stylesheet" href="style.css">
    <!-- Leaflet OpenStreetMap CDN -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .offer-container {
            max-width: 850px;
            margin: 0 auto;
        }
        .route-presets {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.45rem;
            margin-bottom: 1rem;
        }
        .preset-chip {
            background: #f1f5f9;
            border: 1px solid var(--border-color);
            border-radius: 9999px;
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .preset-chip:hover {
            background: #e2e8f0;
            border-color: #cbd5e1;
        }
        .preset-chip.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        @media (max-width: 640px) {
            .grid-2 { grid-template-columns: 1fr; }
        }
        .map-wrapper {
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        #routePickerMap {
            height: 280px;
            width: 100%;
            z-index: 1;
        }
        .map-instructions {
            background: #f8fafc;
            padding: 0.65rem 1rem;
            font-size: 0.82rem;
            color: var(--text-muted);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .women-only-box {
            background: #fdf2f8;
            border: 1.5px solid #fbcfe8;
            border-radius: var(--radius-sm);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .women-only-box.active {
            background: #fce7f3;
            border-color: #f472b6;
        }
        .passenger-notice-badge {
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            border-radius: var(--radius-sm);
            padding: 0.85rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.88rem;
            color: #1e40af;
        }
    </style>
</head>
<body>
    <?php render_navbar('my_rides'); ?>

    <div class="main-container">
        <div class="offer-container">
            
            <div class="section-header">
                <div>
                    <h1 style="font-size: 1.85rem; font-weight: 800; color: var(--primary);">✏️ Edit Ride #<?= $rideId ?></h1>
                    <p style="color: var(--text-muted); font-size: 0.95rem;">Update your commute schedule, route, seats, or cost. All joined passengers will automatically receive a notification.</p>
                </div>
                <div>
                    <a href="ride_details.php?id=<?= $rideId ?>" class="btn btn-secondary btn-sm">← Back to Ride</a>
                </div>
            </div>

            <?php if ($occupiedSeats > 0): ?>
                <div class="passenger-notice-badge">
                    <span style="font-size: 1.3rem;">👥</span>
                    <div>
                        <strong><?= $occupiedSeats ?> passenger(s) currently joined:</strong> When you save these changes, an automated alert will notify each passenger of the updated departure time and route.
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
                    <svg style="width: 20px; height: 20px; flex-shrink: 0;" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <div class="card">
                <form method="POST" action="edit_ride.php?id=<?= $rideId ?>" id="editRideForm">
                    <input type="hidden" name="ride_id" value="<?= $rideId ?>">
                    <input type="hidden" id="start_lat" name="start_lat" value="<?= htmlspecialchars($startLat) ?>">
                    <input type="hidden" id="start_lng" name="start_lng" value="<?= htmlspecialchars($startLng) ?>">
                    <input type="hidden" id="dest_lat" name="dest_lat" value="<?= htmlspecialchars($destLat) ?>">
                    <input type="hidden" id="dest_lng" name="dest_lng" value="<?= htmlspecialchars($destLng) ?>">

                    <!-- Route Section -->
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="start_location">Starting / Pickup Location *</label>
                            <input type="text" id="start_location" name="start_location" class="form-control" placeholder="e.g. Mirpur 10, Dhanmondi 27, Uttara" value="<?= htmlspecialchars($startLocation) ?>" required onchange="handleLocationChange('start')">
                            
                            <div class="route-presets">
                                <span class="preset-chip" onclick="setPreset('start', 'Mirpur 10')">Mirpur 10</span>
                                <span class="preset-chip" onclick="setPreset('start', 'Uttara')">Uttara</span>
                                <span class="preset-chip" onclick="setPreset('start', 'Dhanmondi')">Dhanmondi</span>
                                <span class="preset-chip" onclick="setPreset('start', 'Mohammadpur')">Mohammadpur</span>
                                <span class="preset-chip" onclick="setPreset('start', 'BRAC University')">BRAC University</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="destination">Destination *</label>
                            <input type="text" id="destination" name="destination" class="form-control" placeholder="e.g. BRAC University, Pragati Sarani" value="<?= htmlspecialchars($destination) ?>" required onchange="handleLocationChange('dest')">
                            
                            <div class="route-presets">
                                <span class="preset-chip" onclick="setPreset('dest', 'BRAC University')">BRAC University</span>
                                <span class="preset-chip" onclick="setPreset('dest', 'Mirpur 10')">Mirpur 10</span>
                                <span class="preset-chip" onclick="setPreset('dest', 'Uttara')">Uttara</span>
                                <span class="preset-chip" onclick="setPreset('dest', 'Dhanmondi')">Dhanmondi</span>
                                <span class="preset-chip" onclick="setPreset('dest', 'Mohakhali')">Mohakhali</span>
                            </div>
                        </div>
                    </div>

                    <!-- Interactive Route Preview Map -->
                    <div class="map-wrapper">
                        <div id="routePickerMap"></div>
                        <div class="map-instructions">
                            <span>📍 <strong>Blue Pin:</strong> Pickup · <strong>Red Pin:</strong> Destination</span>
                            <span>Drag markers or click map to fine-tune coords</span>
                        </div>
                    </div>

                    <!-- Date & Time Section -->
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="ride_date">Ride Date *</label>
                            <input type="date" id="ride_date" name="ride_date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($rideDate) ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="departure_time">Departure Time *</label>
                            <input type="time" id="departure_time" name="departure_time" class="form-control" value="<?= htmlspecialchars($departureTime) ?>" required>
                        </div>
                    </div>

                    <!-- Seats & Pricing Section -->
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="total_seats">Total Passenger Seats * (Min: <?= max(1, $occupiedSeats) ?>)</label>
                            <input type="number" id="total_seats" name="total_seats" class="form-control" min="<?= max(1, $occupiedSeats) ?>" max="8" value="<?= htmlspecialchars($totalSeats) ?>" required>
                            <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.2rem; display: block;">
                                <?= $occupiedSeats ?> seat(s) currently reserved by passengers.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="shared_cost">Shared Fare per Passenger (৳ BDT)</label>
                            <input type="number" id="shared_cost" name="shared_cost" class="form-control" step="10" min="0" max="1000" value="<?= htmlspecialchars($sharedCost) ?>" placeholder="120.00">
                        </div>
                    </div>

                    <!-- Vehicle Info -->
                    <div class="form-group">
                        <label for="vehicle_info">Vehicle Details</label>
                        <input type="text" id="vehicle_info" name="vehicle_info" class="form-control" placeholder="e.g. Toyota Axio (DHA-9988), White Honda Grace" value="<?= htmlspecialchars($vehicleInfo) ?>">
                    </div>

                    <!-- Notes -->
                    <div class="form-group">
                        <label for="notes">Notes / Landmark / Pickup Specifics</label>
                        <textarea id="notes" name="notes" class="form-control" rows="2" placeholder="e.g. Leaving from round about. AC on. Please arrive 5 mins early."><?= htmlspecialchars($notes) ?></textarea>
                    </div>

                    <!-- Women-Only Carpool Toggle -->
                    <div class="women-only-box <?= $isWomenOnly ? 'active' : '' ?>">
                        <div>
                            <strong style="color: #9d174d; font-size: 0.95rem;">🌸 Women-Only Carpool Designation</strong>
                            <p style="color: #be185d; font-size: 0.82rem; margin-top: 0.2rem;">Restrict this ride exclusively to verified female BRAC University students and faculty.</p>
                        </div>
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 700; color: #9d174d;">
                            <input type="checkbox" name="is_women_only" value="1" <?= $isWomenOnly ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: #db2777;">
                            Enable
                        </label>
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="submit" name="update_ride" class="btn btn-primary" style="flex: 2; padding: 0.85rem; font-size: 1rem;">
                            💾 Save & Notify Joined Passengers
                        </button>
                        <a href="ride_details.php?id=<?= $rideId ?>" class="btn btn-secondary" style="flex: 1; padding: 0.85rem; text-align: center; font-size: 1rem;">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <!-- Interactive Location Helper Script & Leaflet Map -->
    <script>
        var map, startMarker, destMarker, routeLine;
        var startLatInput = document.getElementById('start_lat');
        var startLngInput = document.getElementById('start_lng');
        var destLatInput = document.getElementById('dest_lat');
        var destLngInput = document.getElementById('dest_lng');

        var locations = <?= json_encode($dhakaLocs) ?>;

        document.addEventListener('DOMContentLoaded', function() {
            var initStart = [parseFloat(startLatInput.value) || 23.8069, parseFloat(startLngInput.value) || 90.3687];
            var initDest = [parseFloat(destLatInput.value) || 23.7781, parseFloat(destLngInput.value) || 90.4265];

            map = L.map('routePickerMap').setView(initStart, 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);

            startMarker = L.marker(initStart, { draggable: true }).addTo(map).bindPopup("<b>Pickup Location</b>").openPopup();
            destMarker = L.marker(initDest, { draggable: true }).addTo(map).bindPopup("<b>Destination</b>");

            updatePolyline();

            startMarker.on('dragend', function(e) {
                var pos = e.target.getLatLng();
                startLatInput.value = pos.lat.toFixed(6);
                startLngInput.value = pos.lng.toFixed(6);
                updatePolyline();
            });

            destMarker.on('dragend', function(e) {
                var pos = e.target.getLatLng();
                destLatInput.value = pos.lat.toFixed(6);
                destLngInput.value = pos.lng.toFixed(6);
                updatePolyline();
            });
        });

        function updatePolyline() {
            if (routeLine) map.removeLayer(routeLine);
            var startPos = startMarker.getLatLng();
            var destPos = destMarker.getLatLng();

            routeLine = L.polyline([startPos, destPos], {
                color: '#0284c7',
                weight: 4,
                opacity: 0.8,
                dashArray: '8, 8'
            }).addTo(map);

            var bounds = L.latLngBounds([startPos, destPos]);
            map.fitBounds(bounds, { padding: [30, 30] });
        }

        function setPreset(type, placeName) {
            if (type === 'start') {
                document.getElementById('start_location').value = placeName;
                handleLocationChange('start');
            } else {
                document.getElementById('destination').value = placeName;
                handleLocationChange('dest');
            }
        }

        function handleLocationChange(type) {
            var inputVal = (type === 'start' ? document.getElementById('start_location').value : document.getElementById('destination').value).trim().toLowerCase();
            
            for (var locName in locations) {
                var data = locations[locName];
                var match = false;
                if (locName.toLowerCase() === inputVal) match = true;
                if (!match && data.aliases) {
                    for (var i = 0; i < data.aliases.length; i++) {
                        if (inputVal.includes(data.aliases[i]) || data.aliases[i].includes(inputVal)) {
                            match = true;
                            break;
                        }
                    }
                }

                if (match) {
                    if (type === 'start') {
                        startLatInput.value = data.lat;
                        startLngInput.value = data.lng;
                        startMarker.setLatLng([data.lat, data.lng]);
                    } else {
                        destLatInput.value = data.lat;
                        destLngInput.value = data.lng;
                        destMarker.setLatLng([data.lat, data.lng]);
                    }
                    updatePolyline();
                    break;
                }
            }
        }
    </script>

    <?php render_footer(); ?>
</body>
</html>
