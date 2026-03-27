#!/usr/bin/env php
<?php
/**
 * SpeedTrip — Radar Fetcher
 * ============================================================
 * Downloads speed cameras from OpenStreetMap via Overpass API
 * and upserts them into the `radars` table.
 *
 * Usage:
 *   php scripts/fetch_radars.php [--bbox=minLat,minLng,maxLat,maxLng]
 *
 * Default bbox covers all of Tuscany + a 50 km margin.
 * For a full Italy run use: --bbox=36.0,6.0,47.5,19.0
 *
 * Schedule with Windows Task Scheduler or cron:
 *   0 3 * * 1  php /path/to/speedtrip/scripts/fetch_radars.php
 * ============================================================
 */

require_once __DIR__ . '/../config.php';

// --- Parse CLI args -------------------------------------------------
$opts    = getopt('', ['bbox:']);
$rawBbox = $opts['bbox'] ?? '42.2,9.5,44.6,12.4';   // Tuscany default
[$minLat, $minLng, $maxLat, $maxLng] = array_map('floatval', explode(',', $rawBbox));

// --- Build Overpass query -------------------------------------------
// We query for:
//   • highway=speed_camera   (fixed speed cameras)
//   • enforcement=average_speed (average-speed / tutor systems)
//   • highway=police         (known police checkpoints)

// --- New query --
// I'm only going to search for nodes, not ways
// searching for ways is too expensive
// I took this out:
    //  way["enforcement"="average_speed"]({$minLat},{$minLng},{$maxLat},{$maxLng});
$query = <<<OVERPASS
[out:json][timeout:60];
(
  node["highway"="speed_camera"]({$minLat},{$minLng},{$maxLat},{$maxLng});
  node["enforcement"="average_speed"]({$minLat},{$minLng},{$maxLat},{$maxLng});
  node["highway"="police"]({$minLat},{$minLng},{$maxLat},{$maxLng});
);
out center body;
OVERPASS;

echo "Querying Overpass API for bbox {$rawBbox}...\n";

$ch = curl_init(OVERPASS_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    // This safely URL-encodes the query and prepends "data="
    CURLOPT_POSTFIELDS     => http_build_query(['data' => $query]), 
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 90,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    fwrite(STDERR, "Overpass request failed (HTTP {$httpCode})\n");
    // Print the actual rejection reason from the Overpass server
    fwrite(STDERR, "Server Response: " . $response . "\n"); 
    exit(1);
}

$data = json_decode($response, true);
if (!isset($data['elements'])) {
    fwrite(STDERR, "Unexpected Overpass response.\n");
    exit(1);
}

echo "Received " . count($data['elements']) . " elements.\n";

// --- Upsert into DB -------------------------------------------------
$db = db();
$sql = <<<SQL
INSERT INTO radars (id, lat, lng, type, direction, max_speed, road_ref, source, last_verified)
VALUES (:id, :lat, :lng, :type, :dir, :speed, :road, 'osm', CURDATE())
ON DUPLICATE KEY UPDATE
    lat           = VALUES(lat),
    lng           = VALUES(lng),
    type          = VALUES(type),
    direction     = VALUES(direction),
    max_speed     = VALUES(max_speed),
    road_ref      = VALUES(road_ref),
    last_verified = CURDATE()
SQL;

$stmt   = $db->prepare($sql);
$saved  = 0;
$errors = 0;

foreach ($data['elements'] as $el) {
    // Ways have a "center" lat/lng
    $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
    $lng = $el['lon'] ?? $el['center']['lon'] ?? null;
    if ($lat === null || $lng === null) continue;

    $tags = $el['tags'] ?? [];

    // Determine camera type
    $type = 'fixed';
    if (($tags['enforcement'] ?? '') === 'average_speed') $type = 'average';
    if (($tags['highway']     ?? '') === 'police')        $type = 'police';

    // Direction: OSM stores it as bearing in degrees
    $dir = isset($tags['direction']) ? (int)$tags['direction'] : null;

    // Max speed
    $maxSpeed = null;
    if (isset($tags['maxspeed'])) {
        $maxSpeed = (int)preg_replace('/\D.*/', '', $tags['maxspeed']);
    }

    // Road reference
    $roadRef = $tags['ref'] ?? $tags['road_ref'] ?? null;

    try {
        $stmt->execute([
            ':id'    => (int)$el['id'],
            ':lat'   => $lat,
            ':lng'   => $lng,
            ':type'  => $type,
            ':dir'   => $dir,
            ':speed' => $maxSpeed,
            ':road'  => $roadRef,
        ]);
        $saved++;
    } catch (PDOException $e) {
        fwrite(STDERR, "Error saving node {$el['id']}: {$e->getMessage()}\n");
        $errors++;
    }
}

echo "Done. Saved/updated: {$saved}  Errors: {$errors}\n";
echo "Total radars in DB: " . $db->query('SELECT COUNT(*) FROM radars')->fetchColumn() . "\n";
