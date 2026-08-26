<?php
// api_geocode.php - Real-world place search, geocoding & reverse geocoding for Dhaka & Bangladesh
header('Content-Type: application/json; charset=utf-8');
require_once 'helpers.php';

$action = $_GET['action'] ?? 'search';
$query = trim($_GET['q'] ?? '');
$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;

// Comprehensive Local Dhaka Database for ultra-fast, zero-latency matching & offline resilience
function get_local_dhaka_places() {
    return [
        ['name' => 'Mohammadpur', 'address' => 'Mohammadpur, Dhaka-1207, Bangladesh', 'lat' => 23.7542, 'lng' => 90.3587, 'type' => 'suburb', 'tags' => ['mohammadpur', 'town hall', 'japan garden city', 'ring road', 'taj mahal road', 'krishi market', 'nurjahan road']],
        ['name' => 'Mohammadpur Bus Stand', 'address' => 'Mohammadpur Bus Stand, Ring Road, Dhaka-1207', 'lat' => 23.7589, 'lng' => 90.3541, 'type' => 'transit', 'tags' => ['bus stand', 'mohammadpur stand', 'allah karim']],
        ['name' => 'BRAC University (New Campus)', 'address' => 'Kha 224, Pragati Sarani, Merul Badda, Dhaka-1212', 'lat' => 23.7781, 'lng' => 90.4265, 'type' => 'university', 'tags' => ['brac university', 'bracu', 'merul badda', 'badda campus', 'pragati sarani']],
        ['name' => 'BRAC University (Mohakhali)', 'address' => '66 Mohakhali C/A, Dhaka-1212', 'lat' => 23.7776, 'lng' => 90.4054, 'type' => 'university', 'tags' => ['mohakhali', 'tb gate', 'wireless gate', 'icddrb', 'amtoli']],
        ['name' => 'Dhanmondi 27', 'address' => 'Dhanmondi 27 (Old), Raziya Sultana Rd, Dhaka-1209', 'lat' => 23.7525, 'lng' => 90.3705, 'type' => 'suburb', 'tags' => ['dhanmondi 27', 'star kabab', 'shankar', 'sankar', 'meena bazar']],
        ['name' => 'Dhanmondi 32', 'address' => 'Mirpur Road, Dhanmondi 32, Dhaka-1209', 'lat' => 23.7511, 'lng' => 90.3789, 'type' => 'landmark', 'tags' => ['dhanmondi 32', 'dhanmondi lake', 'bangabandhu museum']],
        ['name' => 'Dhanmondi (General)', 'address' => 'Dhanmondi Residential Area, Dhaka-1205', 'lat' => 23.7465, 'lng' => 90.3760, 'type' => 'suburb', 'tags' => ['dhanmondi', 'zigatola', 'jigatola', 'shimanto square', 'kalabagan']],
        ['name' => 'Mirpur 10 Roundabout', 'address' => 'Mirpur 10 Circle, Begum Rokeya Sarani, Dhaka-1216', 'lat' => 23.8069, 'lng' => 90.3687, 'type' => 'suburb', 'tags' => ['mirpur 10', 'mirpur-10', 'metro station mirpur 10', 'roundabout', 'benarasi polli']],
        ['name' => 'Mirpur 1', 'address' => 'Mirpur 1, Sony Cinema Circle, Dhaka-1216', 'lat' => 23.7956, 'lng' => 90.3537, 'type' => 'suburb', 'tags' => ['mirpur 1', 'mirpur-1', 'sony square', 'mukti cinema', 'gabtoli road']],
        ['name' => 'Mirpur 2', 'address' => 'Mirpur 2, National Cricket Stadium Area, Dhaka-1216', 'lat' => 23.8043, 'lng' => 90.3615, 'type' => 'suburb', 'tags' => ['mirpur 2', 'mirpur-2', 'stadium', 'commerce college']],
        ['name' => 'Mirpur 11 / 12', 'address' => 'Mirpur 11 / 12, Pallabi, Dhaka-1216', 'lat' => 23.8225, 'lng' => 90.3650, 'type' => 'suburb', 'tags' => ['mirpur 11', 'mirpur 12', 'pallabi', 'purobi']],
        ['name' => 'Kazipara', 'address' => 'Kazipara Metro Station, Begum Rokeya Sarani, Dhaka-1216', 'lat' => 23.7975, 'lng' => 90.3730, 'type' => 'suburb', 'tags' => ['kazipara', 'kazi para', 'west kazipara', 'east kazipara']],
        ['name' => 'Shewrapara', 'address' => 'Shewrapara Metro Station, Mirpur, Dhaka-1216', 'lat' => 23.7900, 'lng' => 90.3740, 'type' => 'suburb', 'tags' => ['shewrapara', 'shewra para', 'west shewrapara', 'east shewrapara']],
        ['name' => 'Agargaon', 'address' => 'Agargaon, Election Commission & IDB Area, Dhaka-1207', 'lat' => 23.7788, 'lng' => 90.3801, 'type' => 'suburb', 'tags' => ['agargaon', 'passport office', 'idb bhaban', 'bcs computer city', 'parjatan']],
        ['name' => 'Kallyanpur', 'address' => 'Kallyanpur Bus Stand, Mirpur Road, Dhaka-1207', 'lat' => 23.7801, 'lng' => 90.3615, 'type' => 'transit', 'tags' => ['kallyanpur', 'kalyanpur', 'kallyanpur bus stand', 'darussalam']],
        ['name' => 'Shyamoli', 'address' => 'Shyamoli Cinema Hall / Square, Mirpur Road, Dhaka-1207', 'lat' => 23.7712, 'lng' => 90.3644, 'type' => 'suburb', 'tags' => ['shyamoli', 'shamoli', 'shishumela', 'shishu mela']],
        ['name' => 'Farmgate', 'address' => 'Farmgate, Kazi Nazrul Islam Ave, Dhaka-1215', 'lat' => 23.7570, 'lng' => 90.3887, 'type' => 'transit', 'tags' => ['farmgate', 'farm gate', 'ananda cinema', 'khamarbari', 'green road']],
        ['name' => 'Gulshan 1', 'address' => 'Gulshan 1 Circle, Gulshan Ave, Dhaka-1212', 'lat' => 23.7785, 'lng' => 90.4172, 'type' => 'suburb', 'tags' => ['gulshan 1', 'gulshan-1', 'gulshan 1 circle', 'shooting club']],
        ['name' => 'Gulshan 2', 'address' => 'Gulshan 2 Circle, Gulshan Ave, Dhaka-1212', 'lat' => 23.7925, 'lng' => 90.4078, 'type' => 'suburb', 'tags' => ['gulshan 2', 'gulshan-2', 'gulshan 2 circle', 'unicef', 'westin']],
        ['name' => 'Banani', 'address' => 'Banani Road 11 & Kemal Ataturk Ave, Dhaka-1213', 'lat' => 23.7937, 'lng' => 90.4066, 'type' => 'suburb', 'tags' => ['banani', 'banani 11', 'kemal ataturk', 'kamal ataturk', 'chairmanbari', 'kakoli']],
        ['name' => 'Uttara Sector 3', 'address' => 'Uttara Sector 3 (House Building / Rajlakshmi), Dhaka-1230', 'lat' => 23.8759, 'lng' => 90.3795, 'type' => 'suburb', 'tags' => ['uttara', 'uttara sector 3', 'rajlakshmi', 'house building', 'jasimuddin', 'azampur', 'sector 3']],
        ['name' => 'Uttara Sector 7 / 10', 'address' => 'Uttara Model Town, Sector 7, Dhaka-1230', 'lat' => 23.8680, 'lng' => 90.3950, 'type' => 'suburb', 'tags' => ['uttara sector 7', 'uttara sector 10', 'sector 7', 'sector 10', 'zamzam tower']],
        ['name' => 'Bashundhara R/A', 'address' => 'Bashundhara Residential Area (Main Gate / Block D), Dhaka-1229', 'lat' => 23.8164, 'lng' => 90.4265, 'type' => 'suburb', 'tags' => ['bashundhara', 'bashundhara r/a', 'block d', 'block c', 'evercare', 'nsu', 'iub']],
        ['name' => 'Kuril Flyover / Jamuna Future Park', 'address' => 'Kuril Biswa Road, Pragati Sarani, Dhaka-1229', 'lat' => 23.8188, 'lng' => 90.4206, 'type' => 'landmark', 'tags' => ['kuril', 'kuril flyover', 'jamuna future park', 'jfp', 'biswa road']],
        ['name' => 'Badda / Link Road', 'address' => 'Middle Badda / Gulshan Badda Link Road, Dhaka-1212', 'lat' => 23.7806, 'lng' => 90.4267, 'type' => 'suburb', 'tags' => ['badda', 'middle badda', 'north badda', 'south badda', 'link road', 'subastu']],
        ['name' => 'Rampura Bridge / TV Center', 'address' => 'DIT Road, Rampura Bridge, Dhaka-1219', 'lat' => 23.7610, 'lng' => 90.4203, 'type' => 'suburb', 'tags' => ['rampura', 'rampura bridge', 'tv center', 'hazipara', 'banasree']],
        ['name' => 'Shahbagh', 'address' => 'Shahbagh Square, Dhaka University Area, Dhaka-1000', 'lat' => 23.7388, 'lng' => 90.3958, 'type' => 'suburb', 'tags' => ['shahbagh', 'shahbag', 'dhaka university', 'du', 'bsmmu', 'katabon', 'nilkhet']],
        ['name' => 'Motijheel / Dilkusha', 'address' => 'Motijheel Commercial Area, Shapla Chottor, Dhaka-1000', 'lat' => 23.7330, 'lng' => 90.4172, 'type' => 'suburb', 'tags' => ['motijheel', 'shapla chottor', 'dilkusha', 'dainik bangla', 'gopibagh']],
        ['name' => 'Hazrat Shahjalal International Airport', 'address' => 'Airport Road, Kurmitola, Dhaka-1229', 'lat' => 23.8512, 'lng' => 90.4007, 'type' => 'airport', 'tags' => ['airport', 'dhaka airport', 'hazrat shahjalal', 'railway station', 'bimansbandar']],
        ['name' => 'Tejgaon / Nabiketa', 'address' => 'Tejgaon Industrial Area, Gulshan Link Road, Dhaka-1208', 'lat' => 23.7690, 'lng' => 90.3995, 'type' => 'suburb', 'tags' => ['tejgaon', 'nabiketa', 'satrasta', 'love road', 'ahsanullah']]
    ];
}

// 1. REVERSE GEOCODING
if ($action === 'reverse') {
    if ($lat === null || $lng === null) {
        echo json_encode(['error' => 'Missing lat/lng coordinates']);
        exit;
    }

    $places = get_local_dhaka_places();
    $bestPlace = null;
    $minDist = 999999;

    foreach ($places as $p) {
        $d = get_distance_km($lat, $lng, $p['lat'], $p['lng']);
        if ($d !== null && $d < $minDist) {
            $minDist = $d;
            $bestPlace = $p;
        }
    }

    if ($bestPlace && $minDist <= 1.2) {
        echo json_encode([
            'name' => $bestPlace['name'],
            'address' => $bestPlace['address'],
            'lat' => $lat,
            'lng' => $lng,
            'matched_place' => $bestPlace['name'],
            'distance_km' => $minDist
        ]);
        exit;
    }

    // Try live OpenStreetMap Nominatim reverse geocode with short timeout
    $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}&zoom=17&addressdetails=1";
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: BRACU-Rideshare-Platform/2.0 (student-project@bracu.ac.bd)\r\n",
            'timeout' => 2.0
        ]
    ];
    $ctx = stream_context_create($opts);
    $response = @file_get_contents($url, false, $ctx);

    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data) && !empty($data['display_name'])) {
            $addr = $data['address'] ?? [];
            $name = $addr['road'] ?? $addr['suburb'] ?? $addr['neighbourhood'] ?? $addr['city_district'] ?? ($bestPlace['name'] ?? 'Selected Location');
            echo json_encode([
                'name' => $name,
                'address' => $data['display_name'],
                'lat' => $lat,
                'lng' => $lng
            ]);
            exit;
        }
    }

    // Fallback response using closest known point or default
    $locName = $bestPlace ? $bestPlace['name'] . ' Area' : 'Dhaka Location';
    echo json_encode([
        'name' => $locName,
        'address' => $locName . ', Dhaka, Bangladesh',
        'lat' => $lat,
        'lng' => $lng
    ]);
    exit;
}

// 2. PLACE SEARCH / AUTOCOMPLETE
if (empty($query)) {
    // Return top popular Dhaka university hubs
    $topPlaces = array_slice(get_local_dhaka_places(), 0, 8);
    echo json_encode($topPlaces);
    exit;
}

$queryLower = strtolower($query);
$results = [];
$seenCoords = [];

// Step A: Local Dhaka Places search (instant, priority)
$places = get_local_dhaka_places();
foreach ($places as $p) {
    $score = 0;
    $pNameLower = strtolower($p['name']);
    $pAddrLower = strtolower($p['address']);

    if (strpos($pNameLower, $queryLower) === 0) {
        $score += 100; // Exact prefix match
    } elseif (strpos($pNameLower, $queryLower) !== false) {
        $score += 80; // Substring in name
    } elseif (strpos($pAddrLower, $queryLower) !== false) {
        $score += 50; // In address
    } else {
        foreach ($p['tags'] as $tag) {
            if (strpos($tag, $queryLower) !== false || strpos($queryLower, $tag) !== false) {
                $score += 60;
                break;
            }
        }
    }

    if ($score > 0) {
        $coordKey = round($p['lat'], 3) . '_' . round($p['lng'], 3);
        $seenCoords[$coordKey] = true;
        $p['searchScore'] = $score;
        $results[] = $p;
    }
}

// Sort local results by match score
usort($results, fn($a, $b) => ($b['searchScore'] ?? 0) <=> ($a['searchScore'] ?? 0));

// Step B: If query is 3+ chars, query OpenStreetMap Nominatim for additional real locations in Dhaka/Bangladesh
if (strlen($query) >= 3) {
    $encodedQ = urlencode($query . ' Dhaka Bangladesh');
    $osmUrl = "https://nominatim.openstreetmap.org/search?format=json&q={$encodedQ}&countrycodes=bd&viewbox=90.20,23.60,90.60,24.00&bounded=0&addressdetails=1&limit=5";
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: BRACU-Rideshare-Platform/2.0 (student-project@bracu.ac.bd)\r\n",
            'timeout' => 2.0
        ]
    ];
    $ctx = stream_context_create($opts);
    $response = @file_get_contents($osmUrl, false, $ctx);

    if ($response) {
        $osmData = json_decode($response, true);
        if (is_array($osmData)) {
            foreach ($osmData as $item) {
                $itemLat = floatval($item['lat']);
                $itemLng = floatval($item['lon']);
                $coordKey = round($itemLat, 3) . '_' . round($itemLng, 3);

                if (!isset($seenCoords[$coordKey])) {
                    $seenCoords[$coordKey] = true;
                    $shortName = explode(',', $item['display_name'])[0];
                    $results[] = [
                        'name' => trim($shortName),
                        'address' => $item['display_name'],
                        'lat' => $itemLat,
                        'lng' => $itemLng,
                        'type' => $item['type'] ?? 'place'
                    ];
                }
            }
        }
    }
}

// Return top 8 suggestions
$finalResults = array_slice($results, 0, 8);
echo json_encode($finalResults);
