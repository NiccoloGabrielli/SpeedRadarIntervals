<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SpeedTrip — Drive</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=Share+Tech+Mono&family=Barlow:wght@300;400;500&display=swap">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="drive-mode">

<?php
require_once 'config.php';
$tripId = (int)($_GET['trip'] ?? 0);
if (!$tripId) { header('Location: index.php'); exit; }

$trip = db()->prepare(
    'SELECT t.*, c.name AS car_name, c.fun_speed, c.category AS car_category
     FROM trips t JOIN cars c ON c.id = t.car_id WHERE t.id = ?'
);
$trip->execute([$tripId]);
$trip = $trip->fetch();
if (!$trip) { header('Location: index.php'); exit; }

$intervals = db()->prepare(
    'SELECT * FROM trip_intervals WHERE trip_id = ? ORDER BY seq'
);
$intervals->execute([$tripId]);
$intervals = $intervals->fetchAll();
?>

<!-- ============================================================
     TOP BAR
============================================================ -->
<div class="drive-topbar">
  <div class="topbar-left">
    <span class="logo-icon">⬡</span>
    <span class="route-label">
      <?= htmlspecialchars($trip['departure_addr']) ?>
      <span class="arrow">→</span>
      <?= htmlspecialchars($trip['destination_addr']) ?>
    </span>
  </div>
  <div class="topbar-right">
    <span class="car-badge"><?= htmlspecialchars($trip['car_name']) ?></span>
    <a href="index.php" class="btn-small">New Trip</a>
  </div>
</div>

<!-- ============================================================
     MAIN LAYOUT: map left, panel right
============================================================ -->
<div class="drive-layout">

  <!-- Map -->
  <div id="driveMap" class="drive-map"></div>

  <!-- Control Panel -->
  <aside class="drive-panel">

    <!-- Progress bar -->
    <div class="progress-bar">
      <div class="progress-fill" id="progressFill"></div>
      <span class="progress-label" id="progressLabel">Interval 1 / <?= count($intervals) ?></span>
    </div>

    <!-- ── PRIMARY INFO ──────────────────────────────────── -->
    <div class="info-cluster">

      <div class="info-card main-card" id="cardDistance">
        <div class="info-label">Distance to next radar</div>
        <div class="info-value" id="valDistance">—</div>
        <div class="info-unit">km</div>
      </div>

      <div class="info-card speed-card" id="cardSpeed">
        <div class="info-label">Fun speed</div>
        <div class="info-value speed-dial" id="valSpeed">—</div>
        <div class="info-unit">km/h</div>
      </div>

      <div class="info-card loc-card" id="cardLocation">
        <div class="info-label">Start of next interval</div>
        <div class="info-value small-val" id="valLocation">—</div>
      </div>

    </div>

    <!-- ── STATUS ────────────────────────────────────────── -->
    <div class="status-strip" id="statusStrip">
      <span id="statusText">Loading intervals…</span>
    </div>

    <!-- ── BUTTONS ───────────────────────────────────────── -->
    <div class="btn-cluster">
      <button class="drive-btn btn-next-iv" id="btnNextInterval" disabled>
        <span class="btn-icon">▶</span>
        <span class="btn-label">Next Interval</span>
        <span class="btn-sub">I've passed the radar</span>
      </button>

      <button class="drive-btn btn-boring" id="btnBoring" disabled>
        <span class="btn-icon">💤</span>
        <span class="btn-label">Boring Interval</span>
        <span class="btn-sub">I'll keep it civil</span>
      </button>
    </div>

    <!-- ── INTERVAL LIST ─────────────────────────────────── -->
    <div class="interval-list" id="intervalList"></div>

  </aside>
</div>

<!-- ============================================================
     DATA
============================================================ -->
<script>
window.SPEEDTRIP = {
  tripId:    <?= $tripId ?>,
  funSpeed:  <?= (int)$trip['fun_speed'] ?>,
  geometry:  <?= json_encode($trip['route_polyline']) ?>,
  intervals: <?= json_encode(array_values($intervals)) ?>,
};
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/@mapbox/polyline@1.2.1/src/polyline.js"></script>
<script src="assets/js/trip.js"></script>
</body>
</html>
