/* trip.js — SpeedTrip interactive driving panel */

(function () {
  'use strict';

  const { tripId, funSpeed, geometry, intervals } = window.SPEEDTRIP;

  // ── State ──────────────────────────────────────────────────
  let currentIndex = 0;   // which interval we're in RIGHT NOW
  const state = intervals.map(iv => ({
    ...iv,
    boring: !!iv.is_boring,
    done:   !!iv.is_completed,
  }));

  // Find first non-completed interval at startup
  currentIndex = state.findIndex(iv => !iv.done && !iv.boring);
  if (currentIndex === -1) currentIndex = 0;

  // ── Map setup ──────────────────────────────────────────────
  const map = L.map('driveMap', { zoomControl: false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap',
    maxZoom: 18,
  }).addTo(map);

  // Draw route polyline
  const routePoints = polyline.decode(geometry, 5);
  const routeLayer  = L.polyline(routePoints, {
    color: '#f5a623',
    weight: 4,
    opacity: 0.6,
  }).addTo(map);
  map.fitBounds(routeLayer.getBounds(), { padding: [30, 30] });

  // Radar markers
  const radarIcon = L.divIcon({
    className: '',
    html: `<div style="
      width:14px;height:14px;border-radius:50%;
      background:#ff4d6a;border:2px solid #fff;
      box-shadow:0 0 8px rgba(255,77,106,0.7);
    "></div>`,
    iconAnchor: [7, 7],
  });

  // Draw radar endpoints
  state.forEach(iv => {
    if (iv.next_radar_id) {
      L.marker([iv.end_lat, iv.end_lng], { icon: radarIcon })
       .bindPopup(`<b>${iv.next_radar_type || 'Radar'}</b>`)
       .addTo(map);
    }
  });

  // Current interval highlight layer
  let currentHighlight = null;

  // ── Interval list ──────────────────────────────────────────
  const listEl = document.getElementById('intervalList');

  function buildList() {
    listEl.innerHTML = '';
    state.forEach((iv, i) => {
      const el = document.createElement('div');
      let cls = 'iv-item';
      if (i === currentIndex) cls += ' current';
      else if (iv.boring)     cls += ' boring-done';
      else if (iv.done)       cls += ' done';

      const statusIcon = iv.boring ? '💤' : iv.done ? '✓' : i === currentIndex ? '▶' : '';

      el.className = cls;
      el.innerHTML = `
        <span class="iv-seq">${String(iv.seq + 1).padStart(2,'0')}</span>
        <span class="iv-dist">${iv.distance_km} km</span>
        <span class="iv-label">${escHtml(iv.label || '')}</span>
        <span class="iv-status">${statusIcon}</span>`;
      listEl.appendChild(el);
    });

    // Scroll current into view
    const cur = listEl.querySelector('.current');
    if (cur) cur.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  // ── Info panel update ──────────────────────────────────────
  function updatePanel() {
    if (currentIndex >= state.length) {
      // Trip finished
      document.getElementById('valDistance').textContent = '0';
      document.getElementById('valSpeed').textContent    = '—';
      document.getElementById('valLocation').textContent = 'You have arrived!';
      document.getElementById('statusText').textContent  = '🏁 Trip complete';
      document.getElementById('btnNextInterval').disabled = true;
      document.getElementById('btnBoring').disabled       = true;
      buildList();
      return;
    }

    const iv      = state[currentIndex];
    const isBoring = iv.boring;
    const total    = state.length;

    // Progress
    const pct = Math.round((currentIndex / total) * 100);
    document.getElementById('progressFill').style.width = pct + '%';
    document.getElementById('progressLabel').textContent =
      `Interval ${currentIndex + 1} / ${total}`;

    // Distance
    document.getElementById('valDistance').textContent =
      iv.distance_km.toFixed(1);

    // Speed
    const speedEl = document.getElementById('valSpeed');
    speedEl.textContent = isBoring ? '—' : iv.fun_speed;
    speedEl.classList.toggle('boring', isBoring);

    // Location
    document.getElementById('valLocation').textContent =
      iv.label || `Segment ${iv.seq + 1}`;

    // Status strip
    let status = '';
    if (isBoring) {
      status = `💤 Boring mode — legal speed only`;
    } else if (iv.next_radar_type === 'average') {
      status = `⚠ Average-speed zone ahead — ${iv.distance_km} km free`;
    } else if (iv.next_radar_type === 'police') {
      status = `⚠ Police checkpoint ahead`;
    } else if (!iv.next_radar_id) {
      status = `✓ Clear to destination — ${iv.distance_km} km`;
    } else {
      status = `⬡ Free interval — ${iv.distance_km} km until next radar`;
    }
    document.getElementById('statusText').textContent = status;

    // Highlight current interval on map
    if (currentHighlight) map.removeLayer(currentHighlight);
    currentHighlight = L.polyline(
      [[iv.start_lat, iv.start_lng], [iv.end_lat, iv.end_lng]],
      { color: isBoring ? '#888' : '#3dffa0', weight: 5, opacity: 0.9 }
    ).addTo(map);
    map.panTo([iv.start_lat, iv.start_lng], { animate: true });

    // Buttons
    document.getElementById('btnNextInterval').disabled = false;
    document.getElementById('btnBoring').disabled       = false;

    buildList();
  }

  // ── Button handlers ────────────────────────────────────────
  document.getElementById('btnNextInterval').addEventListener('click', async () => {
    if (currentIndex >= state.length) return;
    const iv = state[currentIndex];

    // Mark as done in DB
    await api('advance', { interval_id: iv.id });
    iv.done = true;

    currentIndex++;
    // Skip already-done intervals
    while (currentIndex < state.length && (state[currentIndex].done || state[currentIndex].boring)) {
      currentIndex++;
    }
    updatePanel();
  });

  document.getElementById('btnBoring').addEventListener('click', async () => {
    if (currentIndex >= state.length) return;
    const iv = state[currentIndex];

    // Mark as boring in DB
    await api('boring', { interval_id: iv.id });
    iv.boring = true;

    // Don't advance — stay on the same interval but show boring mode
    updatePanel();
  });

  // ── API helper ─────────────────────────────────────────────
  async function api(action, extra = {}) {
    try {
      await fetch('api/trip.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, trip_id: tripId, ...extra }),
      });
    } catch (_) { /* non-blocking */ }
  }

  // ── Util ───────────────────────────────────────────────────
  function escHtml(str) {
    return str.replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  // ── Init ───────────────────────────────────────────────────
  if (state.length === 0) {
    document.getElementById('statusText').textContent =
      'No radars found on this route — enjoy the freedom! 🏎';
    document.getElementById('btnNextInterval').disabled = true;
    document.getElementById('btnBoring').disabled       = true;
  } else {
    updatePanel();
  }
})();
