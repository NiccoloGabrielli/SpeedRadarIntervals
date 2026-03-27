# SpeedTrip

**A radar-aware route intelligence system for long drives.**

---

## Stack
- **Backend**: PHP 8.1+ / MySQL 8 (XAMPP)
- **Maps**: Leaflet.js + OpenStreetMap tiles (free, no key needed)
- **Routing**: OpenRouteService API (free tier: 2 000 req/day)
- **Radar data**: OpenStreetMap Overpass API (free, community-sourced)

---

## Setup

### 1. Copy to XAMPP htdocs
```
C:\xampp\htdocs\speedtrip\    (Windows)
/opt/lampp/htdocs/speedtrip/  (Linux/Mac)
```

### 2. Create the database
Open **phpMyAdmin** → Import → select `schema.sql`

Or via CLI:
```bash
mysql -u root -p < schema.sql
```

### 3. Get a free OpenRouteService key
1. Sign up at https://openrouteservice.org/dev/#/signup
2. Copy your API key
3. Open `config.php` and replace `YOUR_ORS_API_KEY_HERE`

### 4. Download radar data (Tuscany)
```bash
php scripts/fetch_radars.php
```
For a custom region (Italy-wide example):
```bash
php scripts/fetch_radars.php --bbox=36.0,6.0,47.5,19.0
```
Schedule this weekly to keep data fresh (OSM is community-updated like Waze).

### 5. Open in browser
```
http://localhost/speedtrip/
```

---

## How it works

1. **Setup page** (`index.php`):
   - Pick your car (sets the fun speed for radar-free intervals)
   - Enter departure + destination
   - Choose from up to 3 alternative routes
   - System geocodes addresses, fetches routes from ORS, saves trip + computes intervals

2. **Trip panel** (`trip.php`):
   - Displays the current radar-free interval's **distance**, **fun speed**, and **start location**
   - **Next Interval** button: marks current interval done, advances to next
   - **Boring Interval** button: marks current interval as "civil" — speed dial turns off

3. **Interval algorithm**:
   - Decodes ORS route polyline
   - Queries radars within 250 m of the route
   - Projects each radar onto the route and sorts by distance along it
   - Creates segments between consecutive radars = "free intervals"

---

## Adding your own cars
```sql
INSERT INTO cars (name, category, fun_speed, power_hp, description)
VALUES ('My Car', 'sports', 170, 400, 'My custom ride');
```

## Radar data quality
OSM speed camera data for Italy is maintained by the community and rivals Waze
for fixed cameras. For Tuscany + Lazio the coverage is very good.
The `scripts/fetch_radars.php` script also captures:
- Fixed speed cameras (`highway=speed_camera`)
- Average-speed / Tutor systems (`enforcement=average_speed`)
- Known police checkpoints (`highway=police`)

---

## File Structure
```
speedtrip/
├── config.php              ← DB + API keys
├── schema.sql              ← MySQL schema + seed data
├── index.php               ← Setup page (car + route)
├── trip.php                ← Interactive driving panel
├── api/
│   ├── route.php           ← Geocode + ORS routing
│   ├── intervals.php       ← Compute radar-free intervals
│   └── trip.php            ← Save/update trip state
├── scripts/
│   └── fetch_radars.php    ← Download OSM radar data
└── assets/
    ├── css/style.css
    └── js/
        ├── setup.js
        └── trip.js
```
