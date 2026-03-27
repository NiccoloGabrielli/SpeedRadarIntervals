<?php
/**
 * api/route.php
 * POST  { departure: string, destination: string, car_id: int }
 * Returns { routes: [...], car: {...} }
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
$dep  = trim($body['departure']   ?? '');
$dst  = trim($body['destination'] ?? '');
$carId = (int)($body['car_id'] ?? 0);

if (!$dep || !$dst || !$carId) {
    json_out(['error' => 'Missing fields'], 400);
}

// --- Load car -------------------------------------------------------
$car = db()->prepare('SELECT * FROM cars WHERE id = ?');
$car->execute([$carId]);
$car = $car->fetch();
if (!$car) json_out(['error' => 'Car not found'], 404);

// --- Geocode addresses ----------------------------------------------
function geocode(string $address): ?array {
    $url = ORS_BASE . '/geocode/search?' . http_build_query([
        'api_key'          => ORS_API_KEY,
        'text'             => $address,
        'boundary.country' => 'ITA',
        'size'             => 1,
    ]);
    $r = @file_get_contents($url);
    if (!$r) return null;
    $data = json_decode($r, true);
    $feat = $data['features'][0] ?? null;
    if (!$feat) return null;
    [$lng, $lat] = $feat['geometry']['coordinates'];
    return [
        'lat'   => $lat,
        'lng'   => $lng,
        'label' => $feat['properties']['label'],
    ];
}

$from = geocode($dep);
$to   = geocode($dst);

if (!$from) json_out(['error' => "Could not geocode departure: $dep"], 422);
if (!$to)   json_out(['error' => "Could not geocode destination: $dst"], 422);

// --- Fetch alternative routes from ORS ------------------------------
$orsUrl = ORS_BASE . '/v2/directions/' . ORS_PROFILE;

$payload = json_encode([
    'coordinates'       => [[$from['lng'], $from['lat']], [$to['lng'], $to['lat']]],
    'instructions'      => false,
    'geometry'          => true,
    'geometry_simplify' => false,
    'alternative_routes'=> [
        'target_count'  => ORS_ALT_ROUTES,
        'weight_factor' => 1.6,
        'share_factor'  => 0.6,
    ],
]);

$ch = curl_init($orsUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: ' . ORS_API_KEY,
        'Content-Type: application/json',
    ],
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    $err = json_decode($resp, true)['error']['message'] ?? 'ORS error';
    json_out(['error' => $err], 502);
}

$orsData = json_decode($resp, true);
$routes  = [];

foreach (($orsData['routes'] ?? []) as $i => $r) {
    $summary = $r['summary'];
    $routes[] = [
        'index'       => $i,
        'distance_km' => round($summary['distance'] / 1000, 1),
        'duration_min'=> round($summary['duration']  / 60),
        'geometry'    => $r['geometry'],   // encoded polyline (ORS default)
    ];
}

json_out([
    'departure'   => $from,
    'destination' => $to,
    'car'         => $car,
    'routes'      => $routes,
]);
