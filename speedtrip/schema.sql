-- ============================================================
--  SpeedTrip — XAMPP MySQL Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS speedtrip CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE speedtrip;

-- ------------------------------------------------------------
-- Cars: each car has a "fun speed" (km/h) the driver aims for
-- in radar-free intervals.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cars (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)  NOT NULL,           -- e.g. "Ferrari 488"
    category    ENUM('citycar','hatchback','sedan','sports','supercar','suv','van') NOT NULL,
    fun_speed   SMALLINT      NOT NULL,           -- km/h target in free intervals
    power_hp    SMALLINT      DEFAULT NULL,
    description VARCHAR(255)  DEFAULT NULL,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default car catalogue
INSERT INTO cars (name, category, fun_speed, power_hp, description) VALUES
('Fiat 500',        'citycar',   105, 70,  'City runabout, keep it gentle'),
('VW Golf GTI',     'hatchback', 135, 245, 'Hot hatch sweetspot'),
('BMW 3 Series',    'sedan',     155, 258, 'Classic sports sedan'),
('Alfa Romeo Giulia QV', 'sports', 185, 510, 'Italian passion'),
('Porsche 911 GT3', 'sports',    200, 510, 'Track weapon, road legal'),
('Ferrari 488',     'supercar',  230, 660, 'Prancing horse at full gallop'),
('Lamborghini Huracán', 'supercar', 240, 610, 'Mad bull unleashed'),
('BMW X5 M',        'suv',       145, 530, 'Fast family hauler'),
('Ford Transit',    'van',        90, 130, 'Steady and sensible');

-- ------------------------------------------------------------
-- Radars: speed cameras & known police spots.
-- Populated by scripts/fetch_radars.php via Overpass API.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS radars (
    id          BIGINT       PRIMARY KEY,          -- OSM node id
    lat         DECIMAL(9,6) NOT NULL,
    lng         DECIMAL(9,6) NOT NULL,
    type        ENUM('fixed','average','mobile','police') DEFAULT 'fixed',
    direction   SMALLINT     DEFAULT NULL,         -- bearing in degrees, NULL = bidirectional
    max_speed   SMALLINT     DEFAULT NULL,         -- enforced limit (km/h)
    road_ref    VARCHAR(50)  DEFAULT NULL,         -- e.g. "A1", "SS1"
    source      VARCHAR(50)  DEFAULT 'osm',
    last_verified DATE       DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    -- Spatial index for fast bbox queries
    INDEX idx_location (lat, lng)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Trips: a saved planning session.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trips (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    car_id          INT          NOT NULL,
    departure_addr  VARCHAR(255) NOT NULL,
    destination_addr VARCHAR(255) NOT NULL,
    departure_lat   DECIMAL(9,6) NOT NULL,
    departure_lng   DECIMAL(9,6) NOT NULL,
    destination_lat DECIMAL(9,6) NOT NULL,
    destination_lng DECIMAL(9,6) NOT NULL,
    route_index     TINYINT      DEFAULT 0,        -- which alternative route was chosen
    route_polyline  MEDIUMTEXT   DEFAULT NULL,     -- encoded polyline from ORS
    total_distance  FLOAT        DEFAULT NULL,     -- km
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (car_id) REFERENCES cars(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Trip intervals: radar-free segments computed from route.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trip_intervals (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    trip_id         INT          NOT NULL,
    seq             SMALLINT     NOT NULL,         -- order along route (0-based)
    start_lat       DECIMAL(9,6) NOT NULL,
    start_lng       DECIMAL(9,6) NOT NULL,
    end_lat         DECIMAL(9,6) NOT NULL,
    end_lng         DECIMAL(9,6) NOT NULL,
    distance_km     FLOAT        NOT NULL,
    fun_speed       SMALLINT     NOT NULL,         -- copied from car at trip creation
    label           VARCHAR(100) DEFAULT NULL,     -- human readable "After A1/Firenze Nord"
    next_radar_id   BIGINT       DEFAULT NULL,     -- radar that ends this interval
    is_boring       TINYINT(1)   DEFAULT 0,        -- user marked "boring"
    is_completed    TINYINT(1)   DEFAULT 0,

    FOREIGN KEY (trip_id)       REFERENCES trips(id) ON DELETE CASCADE,
    FOREIGN KEY (next_radar_id) REFERENCES radars(id) ON DELETE SET NULL
) ENGINE=InnoDB;
