-- 0003: Create `schema_migrations` tracking ledger and align `ci_sessions`
-- PRIMARY KEY with `$config['sess_match_ip'] = FALSE`.
--
-- Why `ci_sessions` needed fixing:
--   `database/schema.sql` defined `PRIMARY KEY (id, ip_address)` while
--   `application/config/config.php` sets `$config['sess_match_ip'] = FALSE`.
--   Under CodeIgniter 3's database session driver, switching between `::1` and
--   `127.0.0.1` (standard on Windows XAMPP) or roaming between networks inserted
--   a duplicate row with the same session `id`, causing split-brain reads and
--   intermittent CSRF 403 token mismatches.

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` varchar(20) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `checksum` char(64) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remove duplicate session rows (keep newest timestamp per session id) before tightening PK to (id)
DELETE s1 FROM `ci_sessions` s1
INNER JOIN `ci_sessions` s2
  ON s1.`id` = s2.`id`
 AND (s1.`timestamp` < s2.`timestamp` OR (s1.`timestamp` = s2.`timestamp` AND s1.`ip_address` > s2.`ip_address`));

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ci_sessions' AND INDEX_NAME = 'PRIMARY');
SET @_wms_ddl := IF(@_wms_chk > 1, 'ALTER TABLE `ci_sessions` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`)', 'SELECT ''ci_sessions PRIMARY KEY already single-column (id)'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ci_sessions' AND INDEX_NAME = 'PRIMARY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `ci_sessions` ADD PRIMARY KEY (`id`)', 'SELECT ''ci_sessions PRIMARY KEY present'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ci_sessions' AND INDEX_NAME = 'ci_sessions_ip');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `ci_sessions` ADD INDEX `ci_sessions_ip` (`ip_address`)', 'SELECT ''ci_sessions.ci_sessions_ip exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ci_sessions' AND INDEX_NAME = 'ci_sessions_timestamp');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `ci_sessions` ADD INDEX `ci_sessions_timestamp` (`timestamp`)', 'SELECT ''ci_sessions.ci_sessions_timestamp exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;
