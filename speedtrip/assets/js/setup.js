/* setup.js — SpeedTrip setup page logic */

(function () {
  'use strict';

  // ── State ──────────────────────────────────────────────────
  let selectedCarId   = null;
  let selectedCarFun  = null;
  let selectedRoute   = null;
  let routeData       = null;
  let previewMap      = null;
  let routeLayers     = [];

  // ── Car selection ──────────────────────────────────────────
  document.querySelectorAll('.car-card').forEach(card => {
    card.addEventListener('click', () => {
      document.querySelectorAll('.car-card').forEach(c => c.classList.remove('selected'));
      card.classList.add('selected');
      selectedCarId  = parseInt(card.dataset.id, 10);
      selectedCarFun = parseInt(card.dataset.fun, 10);
      document.getElementById('btnCarNext').disabled = false;
    });
  });

  document.getElementById('btnCarNext').addEventListener('click', () => {
    document.getElementById('step-car').classList.remove('active');
    document.getElementById('step-route').classList.add('active');
  });

  // ── Route fetch ────────────────────────────────────────────
  document.getElementById('btnFindRoutes').addEventListener('click', fetchRoutes);
  document.getElementById('inputTo').addEventListener('keydown', e => {
    if (e.key === 'Enter') fetchRoutes();
  });

  async function fetchRoutes() {
    const from = document.getElementById('inputFrom').value.trim();
    const to   = document.getElementById('inputTo').value.trim();
    if (!from || !to) return;

    setLoading(true);
    hideError();
    document.getElementById('routeList').classList.add('hidden');
    document.getElementById('previewMap').classList.add('hidden');
    document.getElementById('btnStartTrip').classList.add('hidden');

    try {
      const resp = await fetch('api/route.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ departure: from, destination: to, car_id: selectedCarId }),
      });
      const data = await resp.json();
      if (!resp.ok) throw new Error(data.error || 'Unknown error');

      routeData = data;
      renderRouteList(data.routes);
      initPreviewMap(data);
    } catch (err) {
      showError(err.message);
    } finally {
      setLoading(false);
    }
  }

  function renderRouteList(routes) {
    const list = document.getElementById('routeList');
    list.innerHTML = '';
    routes.forEach((r, i) => {
      const el = document.createElement('div');
      el.className = 'route-option';
      el.innerHTML = `
        <div class="route-option-label">Route ${i + 1}</div>
        <div class="route-meta">
          <span><strong>${r.distance_km}</strong> km</span>
          <span><strong>${r.duration_min}</strong> min</span>
        </div>`;
      el.addEventListener('click', () => selectRoute(i, el));
      list.appendChild(el);
    });
    list.classList.remove('hidden');
    // Auto-select first
    list.children[0].click();
  }

  function selectRoute(index, el) {
    document.querySelectorAll('.route-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    selectedRoute = index;
    drawRoute(routeData.routes[index]);
    document.getElementById('btnStartTrip').classList.remove('hidden');
  }

  // ── Preview map ────────────────────────────────────────────
  function initPreviewMap(data) {
    const mapEl = document.getElementById('previewMap');
    mapEl.classList.remove('hidden');

    if (!previewMap) {
      previewMap = L.map('previewMap', { zoomControl: true });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap',
        maxZoom: 18,
      }).addTo(previewMap);
    }

    // Clear old layers
    routeLayers.forEach(l => previewMap.removeLayer(l));
    routeLayers = [];
  }

  function drawRoute(route) {
    if (!previewMap) return;
    routeLayers.forEach(l => previewMap.removeLayer(l));
    routeLayers = [];

    // polyline is ORS encoded polyline (precision 5)
    const latlngs = polyline.decode(route.geometry, 5);

    const layer = L.polyline(latlngs, {
      color: '#f5a623',
      weight: 4,
      opacity: 0.85,
    }).addTo(previewMap);
    routeLayers.push(layer);
    previewMap.fitBounds(layer.getBounds(), { padding: [20, 20] });
  }

  // ── Start trip ─────────────────────────────────────────────
  document.getElementById('btnStartTrip').addEventListener('click', async () => {
    if (selectedRoute === null || !routeData) return;

    const route = routeData.routes[selectedRoute];
    setLoading(true);

    try {
      // 1. Save trip to DB
      const saveResp = await fetch('api/trip.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action:           'save',
          car_id:           selectedCarId,
          departure_addr:   routeData.departure.label,
          destination_addr: routeData.destination.label,
          departure_lat:    routeData.departure.lat,
          departure_lng:    routeData.departure.lng,
          destination_lat:  routeData.destination.lat,
          destination_lng:  routeData.destination.lng,
          route_index:      selectedRoute,
          route_polyline:   route.geometry,
          total_distance:   route.distance_km,
        }),
      });
      const { trip_id, error } = await saveResp.json();
      if (error) throw new Error(error);

      // 2. Compute intervals
      const ivResp = await fetch('api/intervals.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          trip_id:         trip_id,
          geometry:        route.geometry,
          car_id:          selectedCarId,
          departure_lat:   routeData.departure.lat,
          departure_lng:   routeData.departure.lng,
          destination_lat: routeData.destination.lat,
          destination_lng: routeData.destination.lng,
        }),
      });
      const ivData = await ivResp.json();
      if (ivData.error) throw new Error(ivData.error);

      // 3. Navigate to trip page
      window.location.href = `trip.php?trip=${trip_id}`;

    } catch (err) {
      showError(err.message);
      setLoading(false);
    }
  });

  // ── Helpers ─────────────────────────────────────────────────
  function setLoading(on) {
    document.getElementById('routeSpinner').classList.toggle('hidden', !on);
    document.getElementById('btnFindLabel').textContent = on ? 'Searching…' : 'Find Routes';
    document.getElementById('btnFindRoutes').disabled = on;
  }
  function showError(msg) {
    const el = document.getElementById('routeError');
    el.textContent = msg;
    el.classList.remove('hidden');
  }
  function hideError() {
    document.getElementById('routeError').classList.add('hidden');
  }
})();
