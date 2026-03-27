<?php
/**
 * api/intervals.php
 * POST {
 *   trip_id: int,          // already saved trip
 *   geometry: string,      // ORS encoded polyline
 *   car_id: int,
 *   departure_lat, departure_lng, destination_lat, destination_lng
 * }
 *
 * Returns { intervals: [...], radars_on_route: [...] }
 *
 * Algorithm:
 *  1. Decode the polyline into lat/lng pairs
 *  2. Build a tight bbox from the route
 *  3. Query radars inside bbox from DB (+ optionally Overpass if empty)
 *  4. For each radar find the closest point on the route polyline
 *  5. Sort radars by their distance along the route
 *  6. Create intervals between consecutive radars
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST only'], 405);

$body   = json_decode(file_get_contents('php://input'), true);
$tripId = (int)($body['trip_id'] ?? 0);
$geo    = $body['geometry'] ?? '';
$carId  = (int)($body['car_id'] ?? 0);

if (!$tripId || !$geo || !$carId) json_out(['error' => 'Missing fields'], 400);

$car = db()->prepare('SELECT fun_speed FROM cars WHERE id = ?');
$car->execute([$carId]);
$funSpeed = (int)$car->fetchColumn();
if (!$funSpeed) json_out(['error' => 'Car not found'], 404);

// ============================================================
//  Decode ORS/Google encoded polyline → [[lat,lng], ...]
// ============================================================
function decodePolyline(string $encoded, int $precision = 5): array {
    $index = 0;
    $lat   = 0;
    $lng   = 0;
    $points = [];
    $len    = strlen($encoded);
    $factor = 10 ** $precision;

    while ($index < $len) {
        $result = 1; $shift = 0;
        do {
            $b = ord($encoded[$index++]) - 63 - 1;
            $result += $b << $shift;
            $shift += 5;
        } while ($b >= 0x1f);
        $lat += ($result & 1) ? (~$result >> 1) : ($result >> 1);

        $result = 1; $shift = 0;
        do {
            $b = ord($encoded[$index++]) - 63 - 1;
            $result += $b << $shift;
            $shift += 5;
        } while ($b >= 0x1f);
        $lng += ($result & 1) ? (~$result >> 1) : ($result >> 1);

        $points[] = [$lat / $factor, $lng / $factor];
    }
    return $points;
}

// ============================================================
//  Haversine distance in km between two lat/lng pairs
// ============================================================
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R  = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

// ============================================================
//  Cumulative distance array along polyline (km)
// ============================================================
function cumulativeDistances(array $points): array {
    $dist = [0.0];
    for ($i = 1; $i < count($points); $i++) {
        $dist[$i] = $dist[$i-1] + haversine(...$points[$i-1], ...$points[$i]);
    }
    return $dist;
}

// ============================================================
//  Project a radar onto the closest segment of the polyline.
//  Returns the distance along the route in km.
// ============================================================
function projectOnRoute(float $rLat, float $rLng, array $pts, array $cumDist): float {
    $best = PHP_FLOAT_MAX;
    $bestDist = 0.0;

    for ($i = 0; $i < count($pts) - 1; $i++) {
        $d = haversine($rLat, $rLng, $pts[$i][0], $pts[$i][1]);
        if ($d < $best) {
            $best     = $d;
            $bestDist = $cumDist[$i];
        }
    }
    return $bestDist;
}

// ============================================================
//  Main
// ============================================================
$points  = decodePolyline($geo);
$cumDist = cumulativeDistances($points);
$totalKm = end($cumDist);

// Build bbox with RADAR_BUFFER_M metres margin
$lats = array_column($points, 0);
$lngs = array_column($points, 1);
$margin = RADAR_BUFFER_M / 111000;   // ~degrees
$minLat = min($lats) - $margin;
$maxLat = max($lats) + $margin;
$minLng = min($lngs) - $margin;
$maxLng = max($lngs) + $margin;

// Query radars in bbox
$radarsRaw = db()->prepare(
    'SELECT * FROM radars WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?'
);
$radarsRaw->execute([$minLat, $maxLat, $minLng, $maxLng]);
$radarsRaw = $radarsRaw->fetchAll();

// Filter to radars within RADAR_BUFFER_M of any route point (rough check already done via bbox)
// and project each onto the route
$projected = [];
$bufKm     = RADAR_BUFFER_M / 1000;

foreach ($radarsRaw as $r) {
    // Check if closer than buffer to any segment endpoint
    $minDist = PHP_FLOAT_MAX;
    foreach ($points as $p) {
        $d = haversine($r['lat'], $r['lng'], $p[0], $p[1]);
        if ($d < $minDist) $minDist = $d;
    }
    if ($minDist > $bufKm) continue;

    $alongRoute = projectOnRoute($r['lat'], $r['lng'], $points, $cumDist);
    $projected[] = array_merge($r, ['along_km' => $alongRoute]);
}

// Sort by position along route
usort($projected, fn($a, $b) => $a['along_km'] <=> $b['along_km']);

// ============================================================
//  Build intervals
//  An interval = [prev_radar_end → next_radar]
//  Waypoint info: use the route point closest to the interval start
// ============================================================
function closestPoint(array $points, array $cumDist, float $targetKm): array {
    $best = 0;
    $bestDiff = PHP_FLOAT_MAX;
    foreach ($cumDist as $i => $d) {
        $diff = abs($d - $targetKm);
        if ($diff < $bestDiff) { $bestDiff = $diff; $best = $i; }
    }
    return $points[$best];
}

$intervals    = [];
$prevKm       = 0.0;
$prevLabel    = 'Departure';

foreach ($projected as $radar) {
    $startPt    = closestPoint($points, $cumDist, $prevKm);
    $intervalKm = round($radar['along_km'] - $prevKm, 2);

    if ($intervalKm < 0.2) {
        // Too short to be meaningful — skip and advance cursor
        $prevKm    = $radar['along_km'];
        $prevLabel = 'After radar';
        continue;
    }

    $intervals[] = [
        'seq'           => count($intervals),
        'start_lat'     => $startPt[0],
        'start_lng'     => $startPt[1],
        'end_lat'       => $radar['lat'],
        'end_lng'       => $radar['lng'],
        'distance_km'   => $intervalKm,
        'fun_speed'     => $funSpeed,
        'label'         => $prevLabel,
        'next_radar_id' => $radar['id'],
        'next_radar_type' => $radar['type'],
    ];

    $prevKm    = $radar['along_km'];
    $prevLabel = 'After ' . ($radar['road_ref'] ? $radar['road_ref'] . ' ' : '') . ucfirst($radar['type']);
}

// Final interval: last radar to destination
$finalStart = closestPoint($points, $cumDist, $prevKm);
$finalKm    = round($totalKm - $prevKm, 2);
if ($finalKm > 0.2) {
    $intervals[] = [
        'seq'             => count($intervals),
        'start_lat'       => $finalStart[0],
        'start_lng'       => $finalStart[1],
        'end_lat'         => end($points)[0],
        'end_lng'         => end($points)[1],
        'distance_km'     => $finalKm,
        'fun_speed'       => $funSpeed,
        'label'           => $prevLabel,
        'next_radar_id'   => null,
        'next_radar_type' => null,
    ];
}

// ============================================================
//  Persist intervals
// ============================================================
$db = db();
$db->prepare('DELETE FROM trip_intervals WHERE trip_id = ?')->execute([$tripId]);

$ins = $db->prepare(
    'INSERT INTO trip_intervals
     (trip_id, seq, start_lat, start_lng, end_lat, end_lng,
      distance_km, fun_speed, label, next_radar_id)
     VALUES (?,?,?,?,?,?,?,?,?,?)'
);
foreach ($intervals as &$iv) {
    $ins->execute([
        $tripId, $iv['seq'],
        $iv['start_lat'], $iv['start_lng'],
        $iv['end_lat'],   $iv['end_lng'],
        $iv['distance_km'], $iv['fun_speed'],
        $iv['label'],       $iv['next_radar_id'],
    ]);
    $iv['id'] = (int)$db->lastInsertId();
}

json_out([
    'intervals'       => $intervals,
    'radars_on_route' => array_values(array_map(fn($r) => [
        'id'   => $r['id'],
        'lat'  => (float)$r['lat'],
        'lng'  => (float)$r['lng'],
        'type' => $r['type'],
    ], $projected)),
    'total_km'        => round($totalKm, 1),
]);
