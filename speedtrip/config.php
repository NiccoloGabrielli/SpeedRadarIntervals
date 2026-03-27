<?php
// ============================================================
//  SpeedTrip — Configuration
// ============================================================

// --- Database (XAMPP defaults) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'speedtrip');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// --- OpenRouteService ---
// Free tier: 2 000 req/day, 40 req/min.
// Sign up at https://openrouteservice.org/dev/#/signup
define('ORS_API_KEY', 'YOUR_API_KEY');
define('ORS_BASE',    'https://api.openrouteservice.org');

// --- Routing ---
define('ORS_PROFILE',       'driving-car');   // or 'driving-hgv'
define('ORS_ALT_ROUTES',    3);               // how many route alternatives to offer
define('RADAR_BUFFER_M',    250);             // metres either side of route to scan for radars

// --- Overpass (radar data download) ---
define('OVERPASS_URL', 'https://overpass-api.de/api/interpreter');

// --- App ---
define('APP_NAME',     'SpeedTrip');
define('DEFAULT_REGION', 'Tuscany, Italy');   // used as geocoding hint

// ============================================================
//  PDO helper — call db() anywhere to get a connection.
// ============================================================
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ============================================================
//  JSON response helper
// ============================================================
function json_out(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
