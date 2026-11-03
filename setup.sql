-- Create the database used by the train ETA page.
CREATE DATABASE IF NOT EXISTS rail_simple;
USE rail_simple;

-- Store each train and its current delay in minutes.
CREATE TABLE IF NOT EXISTS trains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    train_number VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    current_station VARCHAR(100) NOT NULL,
    delay_minutes INT NOT NULL DEFAULT 0
);

-- Store the ordered stations scheduled for each train.
CREATE TABLE IF NOT EXISTS stops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    train_id INT NOT NULL,
    station_name VARCHAR(100) NOT NULL,
    `sequence` INT NOT NULL,
    scheduled_time TIME NOT NULL,
    UNIQUE KEY unique_train_stop (train_id, sequence),
    CONSTRAINT fk_stops_train FOREIGN KEY (train_id)
        REFERENCES trains(id) ON DELETE CASCADE
);

-- Keep an operational history instead of storing only the latest train state.
CREATE TABLE IF NOT EXISTS train_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    train_id INT NOT NULL,
    event_type VARCHAR(30) NOT NULL,
    title VARCHAR(120) NOT NULL,
    details VARCHAR(255) NOT NULL,
    delay_change INT NOT NULL DEFAULT 0,
    station_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_train_events_created (created_at),
    CONSTRAINT fk_train_events_train FOREIGN KEY (train_id)
        REFERENCES trains(id) ON DELETE CASCADE
);

-- Add sample trains once, without resetting delays if this file is run again.
INSERT IGNORE INTO trains (train_number, name, current_station, delay_minutes) VALUES
    ('12001', 'Mumbai Pune Express', 'Mumbai', 18),
    ('12002', 'Deccan Connection', 'Pune', 9),
    ('12003', 'Sahyadri Mail', 'Nashik', 24),
    ('12004', 'Corridor Express 04', 'Pune', 12),
    ('12005', 'Corridor Express 05', 'Aurangabad', 0),
    ('12006', 'Corridor Express 06', 'Nashik', 31);

-- Add five scheduled stops to each sample train once.
INSERT IGNORE INTO stops (train_id, station_name, `sequence`, scheduled_time)
SELECT trains.id, sample_stops.station_name, sample_stops.`sequence`, sample_stops.scheduled_time
FROM trains
JOIN (
    SELECT '12001' AS train_number, 'Mumbai' AS station_name, 1 AS `sequence`, '06:00:00' AS scheduled_time
    UNION ALL SELECT '12001', 'Pune', 2, '09:15:00'
    UNION ALL SELECT '12001', 'Aurangabad', 3, '13:30:00'
    UNION ALL SELECT '12001', 'Nashik', 4, '17:00:00'
    UNION ALL SELECT '12001', 'Delhi', 5, '23:45:00'
    UNION ALL SELECT '12002', 'Mumbai', 1, '07:30:00'
    UNION ALL SELECT '12002', 'Pune', 2, '10:00:00'
    UNION ALL SELECT '12002', 'Aurangabad', 3, '14:15:00'
    UNION ALL SELECT '12002', 'Nashik', 4, '18:30:00'
    UNION ALL SELECT '12002', 'Delhi', 5, '23:00:00'
    UNION ALL SELECT '12003', 'Mumbai', 1, '05:45:00'
    UNION ALL SELECT '12003', 'Pune', 2, '09:30:00'
    UNION ALL SELECT '12003', 'Aurangabad', 3, '14:00:00'
    UNION ALL SELECT '12003', 'Nashik', 4, '18:00:00'
    UNION ALL SELECT '12003', 'Delhi', 5, '23:30:00'
    UNION ALL SELECT '12004', 'Mumbai', 1, '06:20:00'
    UNION ALL SELECT '12004', 'Pune', 2, '09:40:00'
    UNION ALL SELECT '12004', 'Aurangabad', 3, '14:05:00'
    UNION ALL SELECT '12004', 'Nashik', 4, '17:20:00'
    UNION ALL SELECT '12004', 'Delhi', 5, '23:55:00'
    UNION ALL SELECT '12005', 'Mumbai', 1, '07:10:00'
    UNION ALL SELECT '12005', 'Pune', 2, '10:30:00'
    UNION ALL SELECT '12005', 'Aurangabad', 3, '14:50:00'
    UNION ALL SELECT '12005', 'Nashik', 4, '19:10:00'
    UNION ALL SELECT '12005', 'Delhi', 5, '23:20:00'
    UNION ALL SELECT '12006', 'Mumbai', 1, '04:55:00'
    UNION ALL SELECT '12006', 'Pune', 2, '08:40:00'
    UNION ALL SELECT '12006', 'Aurangabad', 3, '13:15:00'
    UNION ALL SELECT '12006', 'Nashik', 4, '17:45:00'
    UNION ALL SELECT '12006', 'Delhi', 5, '22:50:00'
) AS sample_stops ON sample_stops.train_number = trains.train_number;