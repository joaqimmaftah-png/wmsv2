-- 0015: Defect planning proposals, evidence, audit and idempotent reporting.
-- Additive only: existing defects, verification fields and plan jobs are not rewritten.

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'requested_date');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `requested_date` date DEFAULT NULL', 'SELECT ''backlogs.requested_date exists'' AS migration_note');
PREPARE wms_stmt FROM @_wms_ddl;
EXECUTE wms_stmt;
DEALLOCATE PREPARE wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'requested_duration');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `requested_duration` int(11) DEFAULT NULL', 'SELECT ''backlogs.requested_duration exists'' AS migration_note');
PREPARE wms_stmt FROM @_wms_ddl;
EXECUTE wms_stmt;
DEALLOCATE PREPARE wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'schedule_state');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `schedule_state` varchar(32) DEFAULT NULL', 'SELECT ''backlogs.schedule_state exists'' AS migration_note');
PREPARE wms_stmt FROM @_wms_ddl;
EXECUTE wms_stmt;
DEALLOCATE PREPARE wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'schedule_message');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `schedule_message` varchar(700) DEFAULT NULL', 'SELECT ''backlogs.schedule_message exists'' AS migration_note');
PREPARE wms_stmt FROM @_wms_ddl;
EXECUTE wms_stmt;
DEALLOCATE PREPARE wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'closure_kind');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `closure_kind` varchar(24) DEFAULT NULL', 'SELECT ''backlogs.closure_kind exists'' AS migration_note');
PREPARE wms_stmt FROM @_wms_ddl;
EXECUTE wms_stmt;
DEALLOCATE PREPARE wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'completion_source');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `completion_source` varchar(500) DEFAULT NULL', 'SELECT ''backlogs.completion_source exists'' AS migration_note');
PREPARE wms_stmt FROM @_wms_ddl;
EXECUTE wms_stmt;
DEALLOCATE PREPARE wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'reviewed_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `reviewed_by` int(11) DEFAULT NULL', 'SELECT ''backlogs.reviewed_by exists'' AS migration_note');
PREPARE wms_stmt FROM @_wms_ddl;
EXECUTE wms_stmt;
DEALLOCATE PREPARE wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'reviewed_at');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `reviewed_at` datetime DEFAULT NULL', 'SELECT ''backlogs.reviewed_at exists'' AS migration_note');
PREPARE wms_stmt FROM @_wms_ddl;
EXECUTE wms_stmt;
DEALLOCATE PREPARE wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'cancellation_reason');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `cancellation_reason` varchar(500) DEFAULT NULL', 'SELECT ''backlogs.cancellation_reason exists'' AS migration_note');
PREPARE wms_stmt FROM @_wms_ddl;
EXECUTE wms_stmt;
DEALLOCATE PREPARE wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'submit_token');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `submit_token` char(32) DEFAULT NULL', 'SELECT ''backlogs.submit_token exists'' AS migration_note');
PREPARE wms_stmt FROM @_wms_ddl;
EXECUTE wms_stmt;
DEALLOCATE PREPARE wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND INDEX_NAME = 'uq_backlog_submit_token');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD UNIQUE KEY `uq_backlog_submit_token` (`submit_token`)', 'SELECT ''uq_backlog_submit_token exists'' AS migration_note');
PREPARE wms_stmt FROM @_wms_ddl;
EXECUTE wms_stmt;
DEALLOCATE PREPARE wms_stmt;

-- Append-only defect activity. No imported historical record is rewritten.
CREATE TABLE IF NOT EXISTS `backlog_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `backlog_id` int(11) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `event_type` varchar(40) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_backlog_events_item` (`backlog_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
