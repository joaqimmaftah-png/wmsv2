-- =============================================================================
-- Migration 0014: Worker assigned site & AML Individual Attendance Sheet settings
-- =============================================================================
-- 1. Adds nullable `users.site` column so each staff member can carry a default
--    assigned site (e.g. WORKSHOP, KALIMVA, MSATA) when attendance is recorded
--    manually or before QR gate check-ins occur.
-- 2. Backfills `attendance.hours_worked` for any existing manual rows that
--    already have both `check_in` and `check_out` recorded with NULL hours.
-- 3. Seeds configurable HR signatory titles for the AML Individual Attendance
--    Sheet footer (`INSERT IGNORE` so custom edits in Settings are preserved).
--
-- Strictly schema & configuration only: zero sample dates or attendance rows.
-- Idempotent across MySQL 8.0+ and MariaDB 10.4+.
-- =============================================================================
SET NAMES utf8mb4;

SET @_wms_chk := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'site'
);
SET @_wms_ddl := IF(
    @_wms_chk = 0,
    'ALTER TABLE `users` ADD COLUMN `site` varchar(100) DEFAULT NULL AFTER `position`',
    'SELECT ''users.site exists'' AS migration_note'
);
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

UPDATE `attendance`
   SET `hours_worked` = ROUND(MOD(TIME_TO_SEC(`check_out`) - TIME_TO_SEC(`check_in`) + 86400, 86400) / 3600, 2)
 WHERE `check_in` IS NOT NULL
   AND `check_out` IS NOT NULL
   AND `hours_worked` IS NULL
   AND `check_in` <> `check_out`;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('timesheet_prepared_by', 'AGANO Elie', 'AML Individual Attendance Sheet: Prepared by name (left footer column)'),
('timesheet_prepared_title', 'Assistant Admin HR', 'AML Individual Attendance Sheet: Prepared by job title'),
('timesheet_checked_by', 'Supervisor''s', 'AML Individual Attendance Sheet: Checked by label/name (centre footer column)'),
('timesheet_checked_title', '', 'AML Individual Attendance Sheet: Checked by subtitle (blank prints dotted signature line)'),
('timesheet_approved_by', 'BUZINDU RUB. Serge', 'AML Individual Attendance Sheet: Approved by name (right footer column)'),
('timesheet_approved_title', 'HR Manager', 'AML Individual Attendance Sheet: Approved by job title');
