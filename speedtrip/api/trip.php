<?php
/**
 * api/trip.php
 *
 * POST /api/trip.php   { action: 'save', ... }   → save a trip, returns trip_id
 * POST /api/trip.php   { action: 'advance', trip_id, interval_id }
 * POST /api/trip.php   { action: 'boring',  trip_id, interval_id }
 * GET  /api/trip.php?id=N  → load full trip state
 */

require_once __DIR__ . '/../config.php';

// --- GET: load trip state ------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_out(['error' => 'Missing id'], 400);

    $trip = db()->prepare(
        'SELECT t.*, c.name AS car_name, c.fun_speed, c.category AS car_category
         FROM trips t JOIN cars c ON c.id = t.car_id
         WHERE t.id = ?'
    );
    $trip->execute([$id]);
    $trip = $trip->fetch();
    if (!$trip) json_out(['error' => 'Trip not found'], 404);

    $intervals = db()->prepare(
        'SELECT * FROM trip_intervals WHERE trip_id = ? ORDER BY seq'
    );
    $intervals->execute([$id]);

    json_out(['trip' => $trip, 'intervals' => $intervals->fetchAll()]);
}

// --- POST ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Method not allowed'], 405);

$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';

// ---- Save a new trip
if ($action === 'save') {
    $required = ['car_id','departure_addr','destination_addr',
                 'departure_lat','departure_lng','destination_lat','destination_lng',
                 'route_index','route_polyline','total_distance'];
    foreach ($required as $k) {
        if (!isset($body[$k])) json_out(['error' => "Missing: $k"], 400);
    }

    $stmt = db()->prepare(
        'INSERT INTO trips
         (car_id, departure_addr, destination_addr,
          departure_lat, departure_lng, destination_lat, destination_lng,
          route_index, route_polyline, total_distance)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $body['car_id'],
        $body['departure_addr'],  $body['destination_addr'],
        $body['departure_lat'],   $body['departure_lng'],
        $body['destination_lat'], $body['destination_lng'],
        $body['route_index'],
        $body['route_polyline'],
        $body['total_distance'],
    ]);
    json_out(['trip_id' => (int)db()->lastInsertId()]);
}

// ---- Mark interval completed (user pressed "Next interval")
if ($action === 'advance') {
    $tripId = (int)($body['trip_id'] ?? 0);
    $ivId   = (int)($body['interval_id'] ?? 0);
    if (!$tripId || !$ivId) json_out(['error' => 'Missing ids'], 400);

    db()->prepare(
        'UPDATE trip_intervals SET is_completed = 1 WHERE id = ? AND trip_id = ?'
    )->execute([$ivId, $tripId]);

    json_out(['ok' => true]);
}

// ---- Mark interval boring (user pressed "Boring interval")
if ($action === 'boring') {
    $tripId = (int)($body['trip_id'] ?? 0);
    $ivId   = (int)($body['interval_id'] ?? 0);
    if (!$tripId || !$ivId) json_out(['error' => 'Missing ids'], 400);

    db()->prepare(
        'UPDATE trip_intervals SET is_boring = 1 WHERE id = ? AND trip_id = ?'
    )->execute([$ivId, $tripId]);

    json_out(['ok' => true]);
}

json_out(['error' => 'Unknown action'], 400);
