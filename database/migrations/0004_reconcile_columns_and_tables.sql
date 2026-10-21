-- 0004: Reconcile tables, columns, and ENUM vocabularies added across Waves W0-W4 & MP1-MP3.
-- Ensures older production or local XAMPP databases upgrade cleanly without missing-table or missing-column errors.

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL COMMENT 'lower-cased address the attempt was made for',
  `ip_address` varchar(45) NOT NULL COMMENT 'IPv4 or IPv6 of the client',
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_pair` (`email`,`ip_address`,`attempted_at`),
  KEY `idx_login_attempts_account` (`email`,`attempted_at`),
  KEY `idx_login_attempts_prune` (`attempted_at`),
  KEY `idx_login_attempts_source` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `security_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(40) NOT NULL COMMENT 'account_lockout | ip_spray',
  `subject` varchar(150) NOT NULL COMMENT 'e-mail that was locked, or the source IP for a spray',
  `ip_address` varchar(45) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'failures inside the window when this was recorded',
  `distinct_accounts` int(11) DEFAULT NULL COMMENT 'how many different accounts the source tried (spray)',
  `window_seconds` int(11) NOT NULL DEFAULT 900,
  `details` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `acknowledged_by` int(11) DEFAULT NULL COMMENT 'users.id that looked at it',
  `acknowledged_at` datetime DEFAULT NULL,
  `acknowledged_note` varchar(255) DEFAULT NULL COMMENT 'what was done about it',
  PRIMARY KEY (`id`),
  KEY `idx_security_events_type` (`event_type`,`created_at`),
  KEY `idx_security_events_open` (`acknowledged_at`,`created_at`),
  KEY `idx_security_events_subj` (`subject`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wo_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `wo_id` int(11) NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'Incident Evidence',
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wo_attachments_wo_id` (`wo_id`),
  KEY `idx_wo_attachments_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wo_spare_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `wo_spare_part_id` int(11) NOT NULL COMMENT 'FK wo_spare_parts.id (the requested line)',
  `work_order_id` int(11) NOT NULL COMMENT 'Denormalised for fast lookups',
  `material_id` int(11) NOT NULL COMMENT 'FK materials.id (the stock item)',
  `qty_allocated` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Allocated','Issued','Released') NOT NULL DEFAULT 'Allocated' COMMENT 'Allocated = reserved in store, Issued = physically handed out, Released = reservation cancelled',
  `allocated_by` int(11) DEFAULT NULL,
  `allocated_at` datetime DEFAULT NULL,
  `issued_at` datetime DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wo_part` (`wo_spare_part_id`),
  KEY `idx_wo` (`work_order_id`),
  KEY `idx_material` (`material_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `spare_part_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `wo_spare_part_id` int(11) DEFAULT NULL COMMENT 'FK wo_spare_parts.id (NULL if the line was later deleted)',
  `work_order_id` int(11) NOT NULL,
  `material_id` int(11) DEFAULT NULL COMMENT 'FK materials.id that was received',
  `item_code` varchar(50) DEFAULT NULL COMMENT 'Snapshot of the matched item code',
  `part_name` varchar(200) DEFAULT NULL COMMENT 'Snapshot of the requested part name',
  `qty_received` decimal(10,2) DEFAULT NULL COMMENT 'Quantity received in the triggering receipt',
  `stock_after` decimal(10,2) DEFAULT NULL COMMENT 'Stock balance after the receipt',
  `alert_type` enum('Available','Partial') NOT NULL DEFAULT 'Available' COMMENT 'Available = demand fully covered by stock, Partial = receipt helped but demand remains',
  `message` varchar(500) DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL COMMENT 'User who booked the stock receipt',
  `status` enum('New','Read','Dismissed') NOT NULL DEFAULT 'New',
  `created_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `read_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wo` (`work_order_id`),
  KEY `idx_material` (`material_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goods_receipts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `grn_no` varchar(50) NOT NULL COMMENT 'Goods Received Note number, one per delivery',
  `submit_token` varchar(64) DEFAULT NULL COMMENT 'One-time token of the receive form: a replayed submit cannot create a second GRN',
  `receipt_date` date NOT NULL,
  `received_by` int(11) DEFAULT NULL COMMENT 'users.id of the storeman who booked the delivery',
  `discrepancy_flag` varchar(20) NOT NULL DEFAULT 'None',
  `discrepancy_notes` varchar(300) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grn_no` (`grn_no`),
  UNIQUE KEY `uq_grn_submit_token` (`submit_token`),
  KEY `idx_grn_order` (`order_id`,`receipt_date`),
  KEY `idx_grn_date` (`receipt_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goods_receipt_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_id` int(11) NOT NULL,
  `order_item_id` int(11) NOT NULL,
  `material_id` int(11) DEFAULT NULL COMMENT 'NULL when the ordered line is not in the inventory catalogue',
  `description` varchar(255) DEFAULT NULL COMMENT 'Copy of the ordered description at receipt time',
  `unit` varchar(20) DEFAULT NULL,
  `qty_ordered` decimal(12,2) NOT NULL DEFAULT 0.00,
  `qty_previously_received` decimal(12,2) NOT NULL DEFAULT 0.00,
  `qty_delivered` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'This delivery only',
  `qty_outstanding_after` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(14,2) DEFAULT NULL,
  `movement_id` int(11) DEFAULT NULL COMMENT 'material_transactions.id of the stock posting for this line',
  `notes` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_grl_receipt` (`receipt_id`),
  KEY `idx_grl_item` (`order_item_id`),
  KEY `idx_grl_material` (`material_id`),
  KEY `idx_grl_movement` (`movement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `equipment_specs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `equipment_id` int(11) NOT NULL,
  `spec_key` varchar(60) NOT NULL COMMENT 'Normalised key, e.g. rated_power',
  `spec_label` varchar(100) NOT NULL COMMENT 'Label exactly as the workshop writes it in Excel',
  `spec_value` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_equipment_spec` (`equipment_id`,`spec_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `equipment_meter_readings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `equipment_id` int(11) NOT NULL,
  `reading_date` date NOT NULL,
  `hours` decimal(10,1) DEFAULT NULL,
  `km` decimal(10,1) DEFAULT NULL,
  `source` enum('Manual','Import','WorkOrder') NOT NULL DEFAULT 'Manual',
  `work_order_id` int(11) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_meter_eq_date` (`equipment_id`,`reading_date`),
  KEY `idx_meter_date` (`reading_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Daily/periodic meter readings; equipment.current_hours / current_km keep the latest value';

CREATE TABLE IF NOT EXISTS `equipment_kpi_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope` enum('Equipment','Group') NOT NULL,
  `equipment_id` int(11) DEFAULT NULL COMMENT 'scope = Equipment',
  `group_code` varchar(50) DEFAULT NULL COMMENT 'scope = Group, references report_groups.code',
  `target_date` date DEFAULT NULL COMMENT 'NULL = standing default for the scope',
  `target_pct` decimal(5,4) NOT NULL COMMENT '0.9000 = 90 %',
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_target_eq` (`scope`,`equipment_id`,`target_date`),
  KEY `idx_target_grp` (`scope`,`group_code`,`target_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='W36 availability targets. Resolution order: equipment+date, equipment default, group+date, group default, settings.default_availability_target';

CREATE TABLE IF NOT EXISTS `equipment_planned_downtime` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `equipment_id` int(11) NOT NULL,
  `plan_date` date NOT NULL COMMENT 'the machine-day, same grain as the weekly report',
  `category` enum('PM','Standing','Unplanned','OutOfService','Corrective') NOT NULL COMMENT 'D32 + R-MP5 — Corrective = a known, dated repair (still downtime)',
  `hours` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'planned hours lost on that day, 0…24',
  `source` enum('Default','Manual','FromPM','Derived','FromPlan') NOT NULL DEFAULT 'Manual' COMMENT 'D31 + MP-2 — FromPlan = summed from maintenance_plan_jobs',
  `source_note` varchar(255) DEFAULT NULL COMMENT 'which PM schedule / which derivation produced these hours',
  `created_by` int(11) DEFAULT NULL COMMENT 'users.id',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planned_dt_machine_day_cat` (`equipment_id`,`plan_date`,`category`),
  KEY `idx_planned_dt_date` (`plan_date`),
  KEY `idx_planned_dt_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Planned downtime per machine-day (FORECAST_REVIEW task F2). Availability is derived from it by kpi_availability(), never by its own arithmetic (D29).';

CREATE TABLE IF NOT EXISTS `forecast_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `week_start` date NOT NULL COMMENT 'Monday of the ISO week the forecast covers',
  `week_end` date NOT NULL,
  `iso_label` varchar(10) NOT NULL COMMENT 'e.g. 2026-W40',
  `revision` int(11) NOT NULL DEFAULT 1 COMMENT '1 = the baseline plan-vs-actual is measured against',
  `is_baseline` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'exactly one row per week',
  `status` enum('Draft','Published') NOT NULL DEFAULT 'Published',
  `payload` longtext NOT NULL COMMENT 'Forecast_model::planned_availability() verbatim, JSON',
  `payload_checksum` char(64) NOT NULL COMMENT 'sha256 of payload — proves a re-read is byte-identical',
  `fleet_total` int(11) DEFAULT NULL COMMENT 'machines in the forecast (D32 unit view)',
  `fleet_out_of_service` int(11) DEFAULT NULL COMMENT 'machines planned out of service',
  `fleet_available` int(11) DEFAULT NULL COMMENT 'machines available to production',
  `fleet_availability` decimal(7,6) DEFAULT NULL COMMENT 'the headline capacity-weighted figure (D30)',
  `revision_note` varchar(255) DEFAULT NULL COMMENT 'why a revision was raised; NULL on the baseline',
  `published_by` int(11) DEFAULT NULL COMMENT 'users.id',
  `published_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_forecast_snapshot_week_rev` (`week_start`,`revision`),
  KEY `idx_forecast_snapshot_week` (`week_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Frozen planned-availability forecasts (D33). Revision 1 is the baseline; later revisions never overwrite it.';

CREATE TABLE IF NOT EXISTS `maintenance_plan_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `equipment_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL COMMENT 'multi-day repairs: DZ 001 runs 21 days',
  `hours_per_day` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '0…24; 24 = held all day; 0 = a stand-by marker',
  `job_type` enum('Inspection','PMService','Repair','Rebuild','Standby','Other') NOT NULL,
  `category` enum('PM','Standing','Unplanned','OutOfService','Corrective') NOT NULL COMMENT 'which ledger category the hours feed — R-MP5 for Corrective',
  `title` varchar(200) NOT NULL COMMENT 'what the sheet''s upper row said',
  `hold_code` enum('WSP','WQT','WCF','UD','UR') DEFAULT NULL COMMENT 'D17 vocabulary — why it waits',
  `expected_return` date DEFAULT NULL COMMENT 'ETA back to production',
  `pm_schedule_id` int(11) DEFAULT NULL,
  `work_order_id` int(11) DEFAULT NULL,
  `backlog_id` int(11) DEFAULT NULL,
  `status` enum('Planned','Issued','Done','Missed','Cancelled') NOT NULL DEFAULT 'Planned',
  `source` enum('Manual','FromPM','FromInspection','FromWorkOrder','FromBacklog') NOT NULL DEFAULT 'Manual',
  `created_by` int(11) DEFAULT NULL COMMENT 'users.id',
  `updated_by` int(11) DEFAULT NULL COMMENT 'users.id',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mpj_equipment_dates` (`equipment_id`,`start_date`,`end_date`),
  KEY `idx_mpj_dates` (`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Planned maintenance jobs (MAINTENANCE_PLAN_REVIEW R-MP1). Hours reach equipment_planned_downtime as source FromPlan; Manual ledger rows always win.';

CREATE TABLE IF NOT EXISTS `pm_part_requirements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pm_schedule_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000 COMMENT 'consumed per ONE occurrence of the schedule',
  `is_critical` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'the PM cannot proceed without it',
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pm_part` (`pm_schedule_id`,`material_id`),
  KEY `idx_pm_part_material` (`material_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Spare parts consumed by one occurrence of a PM schedule (FORECAST_REVIEW task F10)';

CREATE TABLE IF NOT EXISTS `report_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL COMMENT 'exactly as printed on sheet W36',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_group_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='W36 report groups (order and labels as printed on the sheet)';

CREATE TABLE IF NOT EXISTS `report_sites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL COMMENT 'stable key stored in equipment.location',
  `label` varchar(100) NOT NULL COMMENT 'what the report prints',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_site_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weekly_report_names` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `week_start` date NOT NULL,
  `report_name` varchar(22) NOT NULL,
  `updated_by` int(11) DEFAULT NULL COMMENT 'users.id',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_weekly_report_name_week` (`week_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dashboard-controlled display and workbook name for each report week';

CREATE TABLE IF NOT EXISTS `weekly_report_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `week_start` date NOT NULL COMMENT 'the ISO week this snapshot freezes',
  `week_end` date NOT NULL,
  `iso_label` varchar(10) NOT NULL COMMENT 'e.g. 2026-W36 — the picker label',
  `payload` longtext NOT NULL COMMENT 'W36_report_model::build() verbatim, JSON',
  `payload_checksum` char(64) NOT NULL COMMENT 'sha256 of payload — proves a re-download is byte-identical',
  `build_ms` int(11) DEFAULT NULL COMMENT 'how long the frozen build took',
  `published_by` int(11) DEFAULT NULL COMMENT 'users.id',
  `published_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_snapshot_week` (`week_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Frozen weekly report snapshots (G6): the report as published, for reproducible re-downloads';

CREATE TABLE IF NOT EXISTS `asset_import_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `mode` varchar(20) DEFAULT NULL COMMENT 'import | validate',
  `duplicate_action` varchar(20) DEFAULT NULL COMMENT 'skip | update | fail',
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `imported` int(11) NOT NULL DEFAULT 0,
  `updated` int(11) NOT NULL DEFAULT 0,
  `skipped` int(11) NOT NULL DEFAULT 0,
  `failed` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_import_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `mode` varchar(20) DEFAULT NULL COMMENT 'import | validate',
  `duplicate_action` varchar(20) DEFAULT NULL COMMENT 'skip | update | fail',
  `stock_action` varchar(20) DEFAULT NULL COMMENT 'ignore | receive | set',
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `inserted` int(11) NOT NULL DEFAULT 0,
  `updated` int(11) NOT NULL DEFAULT 0,
  `skipped` int(11) NOT NULL DEFAULT 0,
  `failed` int(11) NOT NULL DEFAULT 0,
  `duplicates` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pm_schedules' AND COLUMN_NAME = 'standard_hours');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `pm_schedules` ADD COLUMN `standard_hours` decimal(6,2) DEFAULT NULL COMMENT ''Planned service duration in hours''', 'SELECT ''pm_schedules.standard_hours exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pm_schedules' AND COLUMN_NAME = 'preferred_weekday');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `pm_schedules` ADD COLUMN `preferred_weekday` tinyint(4) DEFAULT NULL COMMENT ''1=Mon..7=Sun for weekly rounds''', 'SELECT ''pm_schedules.preferred_weekday exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'is_breakdown');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD COLUMN `is_breakdown` tinyint(1) NOT NULL DEFAULT 0', 'SELECT ''work_orders.is_breakdown exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'breakdown_event_id');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD COLUMN `breakdown_event_id` int(11) DEFAULT NULL', 'SELECT ''work_orders.breakdown_event_id exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'hold_code');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD COLUMN `hold_code` enum(''WSP'',''WQT'',''WCF'',''UD'',''UR'') DEFAULT NULL', 'SELECT ''work_orders.hold_code exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'supervisor_id');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD COLUMN `supervisor_id` int(11) DEFAULT NULL', 'SELECT ''work_orders.supervisor_id exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'expected_return_date');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD COLUMN `expected_return_date` date DEFAULT NULL', 'SELECT ''work_orders.expected_return_date exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'verified_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD COLUMN `verified_by` int(11) DEFAULT NULL', 'SELECT ''work_orders.verified_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'verified_at');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD COLUMN `verified_at` datetime DEFAULT NULL', 'SELECT ''work_orders.verified_at exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'verification_notes');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD COLUMN `verification_notes` varchar(300) DEFAULT NULL', 'SELECT ''work_orders.verification_notes exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'verified_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `verified_by` int(11) DEFAULT NULL', 'SELECT ''backlogs.verified_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'verified_at');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `verified_at` datetime DEFAULT NULL', 'SELECT ''backlogs.verified_at exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND COLUMN_NAME = 'verification_notes');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD COLUMN `verification_notes` varchar(300) DEFAULT NULL', 'SELECT ''backlogs.verification_notes exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND COLUMN_NAME = 'min_stock');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `materials` ADD COLUMN `min_stock` int(11) DEFAULT NULL', 'SELECT ''materials.min_stock exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND COLUMN_NAME = 'max_stock');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `materials` ADD COLUMN `max_stock` int(11) DEFAULT NULL', 'SELECT ''materials.max_stock exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND COLUMN_NAME = 'unit_price_ugx');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `materials` ADD COLUMN `unit_price_ugx` decimal(14,2) DEFAULT NULL', 'SELECT ''materials.unit_price_ugx exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND COLUMN_NAME = 'unit_price_usd');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `materials` ADD COLUMN `unit_price_usd` decimal(14,4) DEFAULT NULL', 'SELECT ''materials.unit_price_usd exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND COLUMN_NAME = 'supplier_id');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `materials` ADD COLUMN `supplier_id` int(11) DEFAULT NULL', 'SELECT ''materials.supplier_id exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND COLUMN_NAME = 'supplier_name');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `materials` ADD COLUMN `supplier_name` varchar(150) DEFAULT NULL', 'SELECT ''materials.supplier_name exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND COLUMN_NAME = 'is_active');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `materials` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1', 'SELECT ''materials.is_active exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'txn_date');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `txn_date` date DEFAULT NULL', 'SELECT ''material_transactions.txn_date exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'reference_no');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `reference_no` varchar(50) DEFAULT NULL', 'SELECT ''material_transactions.reference_no exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'allocation_type');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `allocation_type` enum(''Equipment'',''Department'',''Other'') NOT NULL DEFAULT ''Other''', 'SELECT ''material_transactions.allocation_type exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'equipment_id');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `equipment_id` int(11) DEFAULT NULL', 'SELECT ''material_transactions.equipment_id exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'allocation_label');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `allocation_label` varchar(150) DEFAULT NULL', 'SELECT ''material_transactions.allocation_label exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'person');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `person` varchar(100) DEFAULT NULL', 'SELECT ''material_transactions.person exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'supplier_id');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `supplier_id` int(11) DEFAULT NULL', 'SELECT ''material_transactions.supplier_id exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'supplier_name');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `supplier_name` varchar(150) DEFAULT NULL', 'SELECT ''material_transactions.supplier_name exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'qty_approved');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `qty_approved` decimal(12,2) DEFAULT NULL', 'SELECT ''material_transactions.qty_approved exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'unit_price');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `unit_price` decimal(14,2) DEFAULT NULL', 'SELECT ''material_transactions.unit_price exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'currency');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `currency` varchar(3) DEFAULT NULL', 'SELECT ''material_transactions.currency exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'source');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `source` enum(''Manual'',''Excel Import'',''Order GRN'',''Work Order'') NOT NULL DEFAULT ''Manual''', 'SELECT ''material_transactions.source exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'import_key');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `import_key` varchar(64) DEFAULT NULL', 'SELECT ''material_transactions.import_key exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND COLUMN_NAME = 'affects_balance');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD COLUMN `affects_balance` tinyint(1) NOT NULL DEFAULT 1', 'SELECT ''material_transactions.affects_balance exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

ALTER TABLE `equipment_planned_downtime`
  MODIFY COLUMN `category` enum('PM','Standing','Unplanned','OutOfService','Corrective') NOT NULL,
  MODIFY COLUMN `source` enum('Default','Manual','FromPM','Derived','FromPlan') NOT NULL DEFAULT 'Manual';
