-- =============================================================================
-- Migration 0010: Attendance check-in / check-out (QR + GPS)
-- =============================================================================
-- Implements the storage side of ATTENDANCE_WORKFLOW.md:
--
--   attendance_sites    one row per geofenced site: the point every punch is
--                       measured from, the accepted radius, the worst GPS
--                       accuracy the site tolerates, and the per-site secret
--                       that signs its short-lived QR tokens.
--
--   attendance_punches  one row per ACCEPTED punch, exactly as it happened.
--                       Events are never updated or deleted: a supervisor
--                       correction is another row with `origin = 'supervisor'`,
--                       a reason and an author. The daily `attendance` row is
--                       derived from these events by
--                       Attendance_model::sync_attendance_row().
--
-- Nothing is seeded into either table: a fresh installation starts with no
-- site, and attendance punching stays off until a supervisor configures one.
-- The manual daily attendance screen keeps working the whole time.
--
-- Idempotent: safe to re-run on MySQL 8.0+ and MariaDB 10.4+.
-- =============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `attendance_sites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `latitude` decimal(10,7) NOT NULL COMMENT 'Geofence centre — punch distance is measured from here',
  `longitude` decimal(10,7) NOT NULL,
  `radius_m` int(11) NOT NULL DEFAULT 150 COMMENT 'Accepted distance from the centre, metres (the form never pre-fills it: the site decides)',
  `max_accuracy_m` int(11) NOT NULL DEFAULT 100 COMMENT 'Worst reported GPS accuracy accepted, metres',
  `qr_secret` char(64) NOT NULL COMMENT '32 random bytes, hex — signs this site''s rotating QR tokens',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attendance_sites_active` (`is_active`),
  KEY `fk_attendance_sites_created_by` (`created_by`),
  CONSTRAINT `fk_attendance_sites_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_punches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'The worker who punched (or whose punch was corrected)',
  `site_id` int(11) DEFAULT NULL COMMENT 'NULL on a correction that is not tied to a site',
  `work_date` date NOT NULL COMMENT 'Local work day the punch belongs to',
  `punch_type` enum('check_in','check_out') NOT NULL,
  `punched_at` datetime NOT NULL COMMENT 'Server time for a self punch, corrected time for a correction',
  `origin` enum('self','supervisor') NOT NULL DEFAULT 'self',
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `accuracy_m` decimal(7,1) DEFAULT NULL COMMENT 'Accuracy the phone reported, metres',
  `distance_m` decimal(8,1) DEFAULT NULL COMMENT 'Server-computed distance from the site point',
  `radius_m` int(11) DEFAULT NULL COMMENT 'Radius in force when the punch was accepted',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `reason` varchar(300) DEFAULT NULL COMMENT 'Required for a supervisor correction',
  `created_by` int(11) DEFAULT NULL COMMENT 'Who recorded the event (self punch: the worker)',
  `created_at` datetime NOT NULL COMMENT 'When the row was written (audit clock)',
  PRIMARY KEY (`id`),
  KEY `idx_punch_user_date` (`user_id`,`work_date`,`punched_at`),
  KEY `idx_punch_date` (`work_date`,`user_id`),
  KEY `fk_attendance_punches_site` (`site_id`),
  KEY `fk_attendance_punches_created_by` (`created_by`),
  CONSTRAINT `fk_attendance_punches_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_attendance_punches_site` FOREIGN KEY (`site_id`) REFERENCES `attendance_sites` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_attendance_punches_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attendance policy. Defaults keep an upgraded installation behaving exactly as
-- before: the assignment gate is OFF and a check-out with an open work session
-- only warns. A supervisor turns the gate on after configuring a site.
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('attendance_gate_enabled', '0', 'Attendance: 1 = only offer and accept technicians who are checked in on site and free of unfinished work orders - 0 = assignable as before'),
('attendance_open_session_policy', 'warn', 'Attendance: warn = accept a check-out with an open work session and name it - block = refuse the check-out until the session is closed'),
('attendance_qr_ttl_seconds', '60', 'Attendance: how long one signed site QR token stays valid, seconds (10-3600)'),
('attendance_qr_grace_seconds', '120', 'Attendance: how long a just-expired site QR token is still accepted, seconds (0-3600)');
