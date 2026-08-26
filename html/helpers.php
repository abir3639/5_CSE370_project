<?php
// helpers.php - Core helper utilities for BRAC University Rideshare Platform

// Set Default Timezone to Bangladesh Standard Time (BST, UTC+6)
date_default_timezone_set('Asia/Dhaka');

// Configurable Location & Matching Thresholds
if (!defined('PICKUP_MAX_RADIUS_KM')) define('PICKUP_MAX_RADIUS_KM', 4.5);
if (!defined('DEST_MAX_RADIUS_KM')) define('DEST_MAX_RADIUS_KM', 4.5);
if (!defined('CORRIDOR_MAX_DIST_KM')) define('CORRIDOR_MAX_DIST_KM', 3.0);
if (!defined('TIME_STANDARD_WINDOW_MIN')) define('TIME_STANDARD_WINDOW_MIN', 45);
if (!defined('TIME_FLEXIBLE_WINDOW_MIN')) define('TIME_FLEXIBLE_WINDOW_MIN', 90);

// Format Message / Comment Timestamps with Dhaka Timezone
function format_message_time($timestamp) {
    if (empty($timestamp)) return '';
    $ts = is_numeric($timestamp) ? (int)$timestamp : strtotime($timestamp);
    $msgDate = date('Y-m-d', $ts);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    if ($msgDate === $today) {
        return date('g:i A', $ts);
    } elseif ($msgDate === $yesterday) {
        return 'Yesterday ' . date('g:i A', $ts);
    } else {
        return date('M j, g:i A', $ts);
    }
}

// 1. Predefined Dhaka / University Locations & Coordinates
function get_dhaka_locations() {
    return [
        'Mohammadpur'       => ['lat' => 23.7542, 'lng' => 90.3587, 'aliases' => ['mohammadpur', 'town hall', 'japan garden city', 'ring road', 'taj mahal road', 'krishi market', 'nurjahan road']],
        'Mohammadpur Bus Stand' => ['lat' => 23.7589, 'lng' => 90.3541, 'aliases' => ['mohammadpur bus stand', 'allah karim', 'ring road stand']],
        'BRAC University'   => ['lat' => 23.7781, 'lng' => 90.4265, 'aliases' => ['brac university', 'bracu', 'kha 224 pragati sarani', 'merul badda', 'badda campus', 'pragati sarani']],
        'Mohakhali'         => ['lat' => 23.7776, 'lng' => 90.4054, 'aliases' => ['mohakhali', 'tb gate', 'wireless gate', 'amtoli', 'icddrb']],
        'Dhanmondi 27'      => ['lat' => 23.7525, 'lng' => 90.3705, 'aliases' => ['dhanmondi 27', 'star kabab dhanmondi', 'shankar', 'sankar', 'meena bazar']],
        'Dhanmondi 32'      => ['lat' => 23.7511, 'lng' => 90.3789, 'aliases' => ['dhanmondi 32', 'dhanmondi lake', 'bangabandhu museum']],
        'Dhanmondi'         => ['lat' => 23.7465, 'lng' => 90.3760, 'aliases' => ['dhanmondi', 'zigatola', 'jigatola', 'shimanto square', 'kalabagan']],
        'Mirpur 10'         => ['lat' => 23.8069, 'lng' => 90.3687, 'aliases' => ['mirpur 10', 'mirpur-10', 'mirpur 10 round about', 'mirpur', 'mirpur 10 metro']],
        'Mirpur 2'          => ['lat' => 23.8043, 'lng' => 90.3615, 'aliases' => ['mirpur 2', 'mirpur-2', 'sony cinema', 'mirpur stadium', 'commerce college']],
        'Mirpur 1'          => ['lat' => 23.7956, 'lng' => 90.3537, 'aliases' => ['mirpur 1', 'mirpur-1', 'mukti cinema', 'sony square']],
        'Mirpur 11'         => ['lat' => 23.8225, 'lng' => 90.3650, 'aliases' => ['mirpur 11', 'mirpur 12', 'pallabi', 'purobi']],
        'Kazipara'          => ['lat' => 23.7975, 'lng' => 90.3730, 'aliases' => ['kazipara', 'kazi para', 'west kazipara', 'east kazipara']],
        'Shewrapara'        => ['lat' => 23.7900, 'lng' => 90.3740, 'aliases' => ['shewrapara', 'shewra para', 'west shewrapara', 'east shewrapara']],
        'Kallyanpur'        => ['lat' => 23.7801, 'lng' => 90.3615, 'aliases' => ['kallyanpur', 'kalyanpur', 'kallyanpur bus stand', 'darussalam']],
        'Shyamoli'          => ['lat' => 23.7712, 'lng' => 90.3644, 'aliases' => ['shyamoli', 'shamoli', 'shishumela', 'shishu mela']],
        'Uttara'            => ['lat' => 23.8759, 'lng' => 90.3795, 'aliases' => ['uttara', 'uttara sector 3', 'house building', 'rajlakshmi', 'jasimuddin', 'azampur', 'uttara sector 7', 'uttara sector 10']],
        'Gulshan 1'         => ['lat' => 23.7785, 'lng' => 90.4172, 'aliases' => ['gulshan 1', 'gulshan-1', 'gulshan 1 circle', 'shooting club']],
        'Gulshan 2'         => ['lat' => 23.7925, 'lng' => 90.4078, 'aliases' => ['gulshan 2', 'gulshan-2', 'gulshan 2 circle', 'westin']],
        'Banani'            => ['lat' => 23.7937, 'lng' => 90.4066, 'aliases' => ['banani', 'banani 11', 'kemal ataturk', 'kamal ataturk', 'chairmanbari', 'kakoli']],
        'Bashundhara R/A'   => ['lat' => 23.8164, 'lng' => 90.4265, 'aliases' => ['bashundhara', 'bashundhara r/a', 'bashundhara residential area', 'block d', 'block c', 'evercare', 'nsu', 'iub']],
        'Kuril Flyover'     => ['lat' => 23.8188, 'lng' => 90.4206, 'aliases' => ['kuril', 'kuril flyover', 'kuril biswa road', 'jamuna future park', 'jfp']],
        'Badda'             => ['lat' => 23.7806, 'lng' => 90.4267, 'aliases' => ['badda', 'middle badda', 'north badda', 'south badda', 'link road']],
        'Rampura'           => ['lat' => 23.7610, 'lng' => 90.4203, 'aliases' => ['rampura', 'rampura bridge', 'tv center', 'hazipara', 'banasree']],
        'Farmgate'          => ['lat' => 23.7570, 'lng' => 90.3887, 'aliases' => ['farmgate', 'farm gate', 'ananda cinema', 'khamarbari', 'green road']],
        'Agargaon'          => ['lat' => 23.7788, 'lng' => 90.3801, 'aliases' => ['agargaon', 'passport office', 'election commission', 'idb bhaban', 'bcs computer city']],
        'Shahbagh'          => ['lat' => 23.7388, 'lng' => 90.3958, 'aliases' => ['shahbagh', 'shahbag', 'dhaka university', 'du', 'bsmmu', 'katabon']],
        'Motijheel'         => ['lat' => 23.7330, 'lng' => 90.4172, 'aliases' => ['motijheel', 'shapla chottor', 'dilkusha', 'dainik bangla']],
        'Tejgaon'           => ['lat' => 23.7690, 'lng' => 90.3995, 'aliases' => ['tejgaon', 'nabiketa', 'satrasta', 'love road', 'ahsanullah']],
        'Airport'           => ['lat' => 23.8512, 'lng' => 90.4007, 'aliases' => ['airport', 'hazrat shahjalal international airport', 'dhaka airport', 'railway station']]
    ];
}

// 2. Geocode Location Name & Coordinates
function geocode_location($locationName, $customLat = null, $customLng = null) {
    if ($customLat !== null && $customLng !== null && is_numeric($customLat) && is_numeric($customLng) && floatval($customLat) != 0) {
        return [
            'name' => !empty($locationName) ? trim($locationName) : 'Selected Location',
            'lat' => floatval($customLat),
            'lng' => floatval($customLng)
        ];
    }

    $locs = get_dhaka_locations();
    $nameLower = strtolower(trim($locationName));
    
    // Check exact or alias matches
    foreach ($locs as $canonicalName => $data) {
        if (strtolower($canonicalName) === $nameLower) {
            return ['name' => $canonicalName, 'lat' => $data['lat'], 'lng' => $data['lng']];
        }
        foreach ($data['aliases'] as $alias) {
            if (strpos($nameLower, $alias) !== false || strpos($alias, $nameLower) !== false) {
                return ['name' => $canonicalName, 'lat' => $data['lat'], 'lng' => $data['lng']];
            }
        }
    }
    
    // Default fallback to BRAC University central coords if name includes BRAC
    if (strpos($nameLower, 'brac') !== false) {
        return ['name' => 'BRAC University', 'lat' => 23.7781, 'lng' => 90.4265];
    }

    // Default neutral Dhaka coordinates
    return ['name' => $locationName, 'lat' => 23.7781, 'lng' => 90.4265];
}

// 3. Haversine Distance Calculator (in Kilometers)
function get_distance_km($lat1, $lon1, $lat2, $lon2) {
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
        return null;
    }
    $earthRadius = 6371; // km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($earthRadius * $c, 2);
}

// 4. Point-to-Segment Route Corridor / Cross-Track Distance Calculator
// Computes shortest perpendicular distance from Point P(lat, lng) to the driver route line segment S -> D
// and returns relative progress along route $t in [0.0, 1.0]
function get_cross_track_distance_km($startLat, $startLng, $destLat, $destLng, $pLat, $pLng) {
    if ($startLat === null || $startLng === null || $destLat === null || $destLng === null || $pLat === null || $pLng === null) {
        return ['distance' => null, 'progress' => 0.0];
    }

    $midLat = ($startLat + $destLat) / 2.0;
    $cosMid = cos(deg2rad($midLat));

    // Convert to local Cartesian km projection
    $dx = ($destLng - $startLng) * $cosMid * 111.32;
    $dy = ($destLat - $startLat) * 111.32;
    $segLenSq = ($dx * $dx) + ($dy * $dy);

    if ($segLenSq < 0.0001) {
        // Start and Destination are effectively same spot
        $dist = get_distance_km($startLat, $startLng, $pLat, $pLng);
        return ['distance' => $dist, 'progress' => 0.0];
    }

    $px = ($pLng - $startLng) * $cosMid * 111.32;
    $py = ($pLat - $startLat) * 111.32;

    $t = ($px * $dx + $py * $dy) / $segLenSq;
    $clampedT = max(0.0, min(1.0, $t));

    $projX = $clampedT * $dx;
    $projY = $clampedT * $dy;

    $crossDist = sqrt(pow($px - $projX, 2) + pow($py - $projY, 2));

    return [
        'distance' => round($crossDist, 2),
        'progress' => round($clampedT, 3)
    ];
}

// 5. Calculate Ride Compatibility Score with Route Corridor Matching
// Evaluates destination proximity, starting location proximity, route corridor alignment, and time window
function calculate_ride_match($searchDest, $searchStart, $searchDate, $searchTime, $flexibleTime, $ride, $searchStartLat = null, $searchStartLng = null, $searchDestLat = null, $searchDestLng = null) {
    $score = 0;
    $details = [];

    // Date Matching: Exact date gets top priority, upcoming dates are included with label
    if (!empty($searchDate)) {
        if ($ride['RideDate'] === $searchDate) {
            $score += 25;
            $details[] = 'Date Match (' . date('M j', strtotime($ride['RideDate'])) . ')';
        } else {
            // Check if upcoming
            $rideTs = strtotime($ride['RideDate']);
            $searchTs = strtotime($searchDate);
            $dayDiff = round(($rideTs - $searchTs) / 86400);

            if ($dayDiff >= 0 && $dayDiff <= 7) {
                $score += 10;
                $details[] = 'Upcoming Ride (' . date('M j', strtotime($ride['RideDate'])) . ')';
            } elseif ($flexibleTime) {
                $score += 5;
                $details[] = 'Date: ' . date('M j', strtotime($ride['RideDate']));
            } else {
                return ['isMatch' => false, 'score' => 0, 'details' => ['Different date']];
            }
        }
    } else {
        $score += 15;
    }

    // Driver Coordinates
    $driverStartLat = $ride['StartLatitude'] ?? 23.7781;
    $driverStartLng = $ride['StartLongitude'] ?? 90.4265;
    $driverDestLat = $ride['DestinationLatitude'] ?? 23.7781;
    $driverDestLng = $ride['DestinationLongitude'] ?? 90.4265;

    // 1. Destination Matching
    $hasDestSearch = !empty($searchDest) || ($searchDestLat !== null && $searchDestLng !== null);
    if ($hasDestSearch) {
        $searchDestGeo = geocode_location($searchDest, $searchDestLat, $searchDestLng);
        $destDist = get_distance_km($searchDestGeo['lat'], $searchDestGeo['lng'], $driverDestLat, $driverDestLng);
        $destCorridor = get_cross_track_distance_km($driverStartLat, $driverStartLng, $driverDestLat, $driverDestLng, $searchDestGeo['lat'], $searchDestGeo['lng']);

        $destTextOverlap = !empty($searchDest) && (stripos($ride['Destination'], $searchDest) !== false || stripos($searchDest, $ride['Destination']) !== false);

        if ($destTextOverlap || ($destDist !== null && $destDist <= 1.5)) {
            $score += 45; // Exact destination match
            $details[] = 'Exact Destination Match';
        } elseif ($destDist !== null && $destDist <= DEST_MAX_RADIUS_KM) {
            $score += max(20, round(40 - ($destDist * 4)));
            $details[] = 'Nearby Destination (~' . $destDist . 'km)';
        } elseif ($destCorridor['distance'] !== null && $destCorridor['distance'] <= CORRIDOR_MAX_DIST_KM && $destCorridor['progress'] >= 0.7) {
            $score += 25;
            $details[] = 'Along Destination Corridor (~' . $destCorridor['distance'] . 'km)';
        } else {
            // Destination too far
            return ['isMatch' => false, 'score' => 0, 'details' => ['Destination outside corridor']];
        }
    } else {
        // No specific destination entered, broad search
        $score += 20;
    }

    // 2. Starting / Pickup Location Matching
    $hasStartSearch = !empty($searchStart) || ($searchStartLat !== null && $searchStartLng !== null);
    if ($hasStartSearch) {
        $searchStartGeo = geocode_location($searchStart, $searchStartLat, $searchStartLng);
        $startDist = get_distance_km($searchStartGeo['lat'], $searchStartGeo['lng'], $driverStartLat, $driverStartLng);
        $startCorridor = get_cross_track_distance_km($driverStartLat, $driverStartLng, $driverDestLat, $driverDestLng, $searchStartGeo['lat'], $searchStartGeo['lng']);
        $passengerStartProgress = $startCorridor['progress'];

        $startTextOverlap = !empty($searchStart) && (stripos($ride['StartLocation'], $searchStart) !== false || stripos($searchStart, $ride['StartLocation']) !== false);

        if ($startTextOverlap || ($startDist !== null && $startDist <= 1.5)) {
            $score += 35;
            $details[] = 'Exact Pickup Match';
        } elseif ($startDist !== null && $startDist <= PICKUP_MAX_RADIUS_KM) {
            $score += max(15, round(30 - ($startDist * 3)));
            $details[] = 'Pickup Nearby (~' . $startDist . 'km)';
        } elseif ($startCorridor['distance'] !== null && $startCorridor['distance'] <= CORRIDOR_MAX_DIST_KM && $startCorridor['progress'] <= 0.85) {
            // Pickup is along the driver's route corridor (e.g. Kazipara along Mirpur -> BRACU)
            $score += 25;
            $details[] = 'Pickup Along Corridor (~' . $startCorridor['distance'] . 'km)';
        } else {
            return ['isMatch' => false, 'score' => 0, 'details' => ['Pickup area mismatch']];
        }

        // Verify Directional Consistency along Corridor if both start and dest searched
        if ($hasDestSearch && isset($destCorridor)) {
            if ($destCorridor['progress'] < $passengerStartProgress - 0.15) {
                return ['isMatch' => false, 'score' => 0, 'details' => ['Opposite route direction']];
            }
        }
    } else {
        // Broad search without pickup specified
        $score += 20;
    }

    // 3. Time Proximity Matching
    if (!empty($searchTime)) {
        $searchMinutes = strtotime("1970-01-01 " . $searchTime);
        $rideMinutes = strtotime("1970-01-01 " . $ride['DepartureTime']);
        $diffMinutes = abs($searchMinutes - $rideMinutes) / 60;

        $maxWindow = $flexibleTime ? TIME_FLEXIBLE_WINDOW_MIN : TIME_STANDARD_WINDOW_MIN;

        if ($diffMinutes <= 15) {
            $score += 20;
            $details[] = 'Close Time (±' . round($diffMinutes) . 'm)';
        } elseif ($diffMinutes <= 30) {
            $score += 15;
            $details[] = 'Within 30 mins (±' . round($diffMinutes) . 'm)';
        } elseif ($diffMinutes <= $maxWindow) {
            $score += 10;
            $details[] = 'Within window (±' . round($diffMinutes) . 'm)';
        } else {
            // If flexible, still show with reduced score
            if ($flexibleTime) {
                $score += 5;
            } else {
                return ['isMatch' => false, 'score' => 0, 'details' => ['Outside time window']];
            }
        }
    } else {
        $score += 10;
    }

    // University verification bonus
    if (!empty($ride['UniversityVerified'])) {
        $score += 10;
    }

    return [
        'isMatch' => true,
        'score' => min(100, $score),
        'details' => $details,
        'destDistance' => $destDist ?? null
    ];
}

// 5. In-App Notification Dispatcher (Supports Direct Redirection Links)
function create_notification($pdo, $userId, $type, $title, $message, $link = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO `Notification` (`UserID`, `Type`, `Title`, `Message`, `IsRead`, `Link`, `created_at`) VALUES (?, ?, ?, ?, 0, ?, NOW())");
        return $stmt->execute([$userId, $type, $title, $message, $link]);
    } catch (PDOException $e) {
        error_log("Failed to create notification: " . $e->getMessage());
        return false;
    }
}

// 6. Recalculate User Average Rating & Count
function update_user_rating($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS totalRatings, IFNULL(AVG(Rating), 5.00) AS avgRating 
            FROM `Rating` 
            WHERE RecipientID = ?
        ");
        $stmt->execute([$userId]);
        $res = $stmt->fetch();
        
        $ratingCount = (int)($res['totalRatings'] ?? 0);
        $ratingAvg = round((float)($res['avgRating'] ?? 5.00), 2);

        $upStmt = $pdo->prepare("
            UPDATE `User` 
            SET RatingAverage = ?, RatingCount = ? 
            WHERE UserID = ?
        ");
        $upStmt->execute([$ratingAvg, $ratingCount, $userId]);

        // Also update passenger rating if applicable
        $pdo->prepare("UPDATE `Passenger` SET PassRating = ? WHERE UserID = ?")->execute([$ratingAvg, $userId]);

        return true;
    } catch (PDOException $e) {
        error_log("Failed to update user rating: " . $e->getMessage());
        return false;
    }
}

// 7. Get Unread Notification Count
function get_unread_notification_count($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `Notification` WHERE UserID = ? AND IsRead = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

// 8. Render Verification Badge HTML
function render_verification_badge($isVerified, $customClass = '') {
    if ($isVerified) {
        return '<span class="badge-verified ' . htmlspecialchars($customClass) . '" title="Verified BRAC University Student/Faculty"><svg class="badge-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg> BRACU Verified</span>';
    }
    return '<span class="badge-unverified ' . htmlspecialchars($customClass) . '" title="Standard Account">Unverified</span>';
}

// 9. Format 12-hour Time
function format_time_12h($timeStr) {
    if (empty($timeStr)) return '';
    return date("g:i A", strtotime("1970-01-01 " . $timeStr));
}

// 10. Format Date (e.g. Tomorrow · Aug 27)
function format_ride_date($dateStr) {
    if (empty($dateStr)) return '';
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    if ($dateStr === $today) {
        return 'Today (' . date('M j', strtotime($dateStr)) . ')';
    } elseif ($dateStr === $tomorrow) {
        return 'Tomorrow (' . date('M j', strtotime($dateStr)) . ')';
    } elseif ($dateStr === $yesterday) {
        return 'Yesterday (' . date('M j', strtotime($dateStr)) . ')';
    } else {
        return date('D, M j, Y', strtotime($dateStr));
    }
}

// 11. Render Universal Header Navigation
function render_navbar($activeTab = '') {
    global $pdo;
    $isLoggedIn = isset($_SESSION['user_id']);
    $userId = $_SESSION['user_id'] ?? null;
    $userName = $_SESSION['name'] ?? 'User';
    $userRole = $_SESSION['user_type'] ?? 'Passenger';
    $isVerified = $_SESSION['university_verified'] ?? 0;
    $unreadCount = ($isLoggedIn && $userId) ? get_unread_notification_count($pdo, $userId) : 0;
    ?>
    <nav class="navbar">
        <div class="nav-container">
            <a href="index.php" class="brand">
                <span class="brand-logo">🚗</span>
                <div class="brand-text">
                    <span class="brand-title">BRAC University</span>
                    <span class="brand-subtitle">Rideshare Platform</span>
                </div>
            </a>

            <div class="nav-links">
                <a href="index.php" class="nav-item <?= $activeTab === 'find' ? 'active' : '' ?>">
                    <svg class="nav-svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                    Find Ride
                </a>
                <a href="offer_ride.php" class="nav-item <?= $activeTab === 'offer' ? 'active' : '' ?>">
                    <svg class="nav-svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
                    Offer Ride
                </a>
                <a href="lost_found.php" class="nav-item <?= $activeTab === 'lost_found' ? 'active' : '' ?>">
                    <svg class="nav-svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1.323l3.954 1.582 1.599-.8a1 1 0 01.894 1.79l-1.233.616 1.738 5.42a1 1 0 01-.285 1.05A3.989 3.989 0 0115 15a3.989 3.989 0 01-2.667-1.019 1 1 0 01-.285-1.05l1.715-5.349L11 6.477V16h2a1 1 0 110 2H7a1 1 0 110-2h2V6.477L6.237 7.582l1.715 5.349a1 1 0 01-.285 1.05A3.989 3.989 0 015 15a3.989 3.989 0 01-2.667-1.019 1 1 0 01-.285-1.05l1.738-5.42-1.233-.617a1 1 0 01.894-1.788l1.599.799L9 4.323V3a1 1 0 011-1z" clip-rule="evenodd"/></svg>
                    Lost & Found
                </a>
                
                <?php if ($isLoggedIn): ?>
                    <a href="my_rides.php" class="nav-item <?= $activeTab === 'my_rides' ? 'active' : '' ?>">
                        <svg class="nav-svg" viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/></svg>
                        My Rides
                    </a>
                    <a href="notifications.php" class="nav-item <?= $activeTab === 'notifications' ? 'active' : '' ?>" title="Notifications">
                        <svg class="nav-svg" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                        Notifications
                        <?php if ($unreadCount > 0): ?>
                            <span class="badge-notif-pill"><?= $unreadCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="profile.php" class="nav-item <?= $activeTab === 'profile' ? 'active' : '' ?>">
                        <div class="nav-avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                        <span><?= htmlspecialchars(explode(' ', $userName)[0]) ?></span>
                        <?php if ($isVerified): ?>
                            <span class="nav-verified-dot" title="BRACU Verified">✓</span>
                        <?php endif; ?>
                    </a>
                    <a href="logout.php" class="nav-btn-logout" title="Log Out">Exit</a>
                <?php else: ?>
                    <a href="stats.php" class="nav-item <?= $activeTab === 'stats' ? 'active' : '' ?>">Stats</a>
                    <a href="login.php" class="nav-btn-login">Log In</a>
                    <a href="register.php" class="nav-btn-signup">Sign Up</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>
    <?php
}

// 12. Render Global Footer
function render_footer() {
    ?>
    <footer class="footer">
        <div class="footer-container">
            <div class="footer-col">
                <h3>🚗 BRACU Rideshare</h3>
                <p>A safe, verified, and friendly ridesharing network for BRAC University students and faculty.</p>
            </div>
            <div class="footer-col">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="index.php">Find a Ride</a></li>
                    <li><a href="offer_ride.php">Offer a Ride</a></li>
                    <li><a href="lost_found.php">🔍 Lost & Found</a></li>
                    <li><a href="stats.php">Platform Stats</a></li>
                    <li><a href="register.php">Create Account</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Safety First</h4>
                <p>Only verified university members can join and share rides. Always confirm arrival and rate your peer drivers & passengers!</p>
            </div>
        </div>
        <div class="footer-bottom">
            &copy; <?= date('Y') ?> BRAC University Community Rideshare. Designed for student commutes.
        </div>
    </footer>
    <?php
}
?>
