<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SpeedTrip — Plan Your Run</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=Share+Tech+Mono&family=Barlow:wght@300;400;500&display=swap">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<!-- ============================================================
     HEADER
============================================================ -->
<header class="site-header">
  <div class="logo">
    <span class="logo-icon">⬡</span>
    <span class="logo-text">SPEED<strong>TRIP</strong></span>
  </div>
  <div class="header-tag">I want 2 speed</div>
</header>

<!-- ============================================================
     STEP 1 — Choose Car
============================================================ -->
<section id="step-car" class="step active">
  <div class="step-header">
    <span class="step-num">01</span>
    <h2>Select Your Vehicle</h2>
    <p>Your car determines the target speed in radar-free intervals.</p>
  </div>

  <div class="car-grid" id="carGrid">
    <!-- populated by JS from DB via inline PHP -->
    <?php
    require_once 'config.php';
    $cars = db()->query('SELECT * FROM cars ORDER BY fun_speed')->fetchAll();
    foreach ($cars as $c):
    ?>
    <div class="car-card" data-id="<?= $c['id'] ?>" data-fun="<?= $c['fun_speed'] ?>">
      <div class="car-category"><?= htmlspecialchars($c['category']) ?></div>
      <div class="car-name"><?= htmlspecialchars($c['name']) ?></div>
      <div class="car-speed">
        <span class="spd-val"><?= $c['fun_speed'] ?></span>
        <span class="spd-unit">km/h</span>
      </div>
      <?php if ($c['power_hp']): ?>
      <div class="car-power"><?= $c['power_hp'] ?> hp</div>
      <?php endif; ?>
      <div class="car-desc"><?= htmlspecialchars($c['description'] ?? '') ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <button class="btn-next" id="btnCarNext" disabled>Next: Set Route →</button>
</section>

<!-- ============================================================
     STEP 2 — Route
============================================================ -->
<section id="step-route" class="step">
  <div class="step-header">
    <span class="step-num">02</span>
    <h2>Define Your Route</h2>
  </div>

  <div class="route-form">
    <div class="field">
      <label>Departure</label>
      <input type="text" id="inputFrom" placeholder="e.g. Firenze, Piazza della Repubblica" autocomplete="off">
    </div>
    <div class="field">
      <label>Destination</label>
      <input type="text" id="inputTo" placeholder="e.g. Roma, Piazza Venezia" autocomplete="off">
    </div>
    <button class="btn-primary" id="btnFindRoutes">
      <span id="btnFindLabel">Find Routes</span>
      <div class="spinner hidden" id="routeSpinner"></div>
    </button>
  </div>

  <div id="routeError" class="error-msg hidden"></div>

  <!-- Route alternatives -->
  <div id="routeList" class="route-list hidden"></div>

  <!-- Preview map -->
  <div id="previewMap" class="preview-map hidden"></div>

  <button class="btn-next hidden" id="btnStartTrip">Start Trip →</button>
</section>

<!-- ============================================================
     DATA passed to JS
============================================================ -->
<script>
window.SPEEDTRIP = {
  orsApiKey: '<?= ORS_API_KEY ?>',
};
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/@mapbox/polyline@1.2.1/src/polyline.js"></script>
<script src="assets/js/setup.js"></script>
</body>
</html>
