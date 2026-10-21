-- =============================================================================
-- Workshop Management System v2 (WMSv2) — Complete Baseline Schema & Master Data
-- Compatible with MySQL 8.0+ and MariaDB 10.4+ (XAMPP / cPanel / Linux VPS)
-- Ordered cleanly:
--   1. Session settings & transaction start
--   2. All 60 CREATE TABLE definitions
--   3. All PRIMARY KEY, UNIQUE KEY & secondary indexes
--   4. All AUTO_INCREMENT definitions (active BEFORE any INSERT runs!)
--   5. All FOREIGN KEY constraints
--   6. Master reference data, 25 system settings & 14 EN/FR checklists (630 items)
--   7. Baseline schema_migrations ledger (0001..0007 SHA-256 checksums)
-- =============================================================================


SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

SET AUTOCOMMIT = 0;

SET NAMES utf8mb4;

START TRANSACTION;

SET time_zone = "+00:00";

CREATE TABLE `asset_import_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `mode` varchar(20) DEFAULT NULL COMMENT 'import | validate',
  `duplicate_action` varchar(20) DEFAULT NULL COMMENT 'skip | update | fail',
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `imported` int(11) NOT NULL DEFAULT 0,
  `updated` int(11) NOT NULL DEFAULT 0,
  `skipped` int(11) NOT NULL DEFAULT 0,
  `failed` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('Present','Absent','Rest','Leave','Holiday') DEFAULT 'Present',
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `hours_worked` decimal(5,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `backlogs` (
  `id` int(11) NOT NULL,
  `equipment_id` int(11) DEFAULT NULL COMMENT 'Resolved asset; NULL when the Excel row names an unknown machine',
  `asset_label` varchar(80) NOT NULL COMMENT 'Asset exactly as written in the sheet (DZ 003, LV017…)',
  `model` varchar(100) DEFAULT NULL,
  `serial_no` varchar(100) DEFAULT NULL,
  `start_odometer` varchar(40) DEFAULT NULL COMMENT 'Kept as text: the sheet mixes "24 609 Km" and "5956.6 Hr"',
  `start_date` date NOT NULL,
  `description` text NOT NULL COMMENT 'The defect(s); the sheet uses one line per defect',
  `source` enum('Inspection','Checklist','Job Card','Operator','Other') NOT NULL DEFAULT 'Other',
  `reported_by` varchar(100) DEFAULT NULL,
  `in_charge` varchar(100) DEFAULT NULL COMMENT 'Mechanic / supervisor who owns the item',
  `priority` enum('P1','P2','P3') DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `observation` text DEFAULT NULL COMMENT 'Action required / parts awaited',
  `machine_status` enum('OPERATIONAL','BREAKDOWN','STANDBY') DEFAULT NULL COMMENT 'Condition of the machine while the item is open',
  `status` enum('Open','In Progress','Closed','Cancelled') NOT NULL DEFAULT 'Open',
  `end_odometer` varchar(40) DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `closing_remarks` text DEFAULT NULL COMMENT '"Backlog done" OBSERVATION — what was actually done',
  `mechanic` varchar(150) DEFAULT NULL COMMENT '"Backlog done" MECHANIC IN CHARGE',
  `supervisor` varchar(100) DEFAULT NULL,
  `work_order_id` int(11) DEFAULT NULL COMMENT 'Optional link to the WO raised for this defect',
  `planned_date` date DEFAULT NULL,
  `estimated_duration` int(11) DEFAULT NULL COMMENT 'Duration in minutes',
  `source_ref` varchar(64) DEFAULT NULL COMMENT 'Idempotency key for the Excel migration',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL COMMENT 'users.id of the supervisor who verified the defect closure',
  `verified_at` datetime DEFAULT NULL,
  `verification_notes` varchar(300) DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL COMMENT 'users.id that closed the item (NULL on migrated history)',
  `inspection_item_id` int(11) DEFAULT NULL COMMENT 'inspection_items.id this defect came from — lets a later pass close it',
  `recurrence_of` int(11) DEFAULT NULL COMMENT 'backlogs.id of the earlier (closed) defect this one repeats'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `certifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `cert_name` varchar(200) NOT NULL,
  `cert_number` varchar(100) DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `issuing_body` varchar(200) DEFAULT NULL,
  `document` varchar(255) DEFAULT NULL,
  `status` enum('Valid','Expired','Expiring Soon') DEFAULT 'Valid',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ci_sessions` (
  `id` varchar(128) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `timestamp` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `data` blob NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `equipment` (
  `id` int(11) NOT NULL,
  `equipment_type_id` int(11) NOT NULL,
  `registration_no` varchar(50) NOT NULL,
  `legacy_code` varchar(50) DEFAULT NULL COMMENT 'Previous short code (ADT 002) kept so old references still resolve',
  `machine_type` varchar(100) DEFAULT NULL COMMENT 'Excel "Machine Type" group label as written by the workshop',
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `year` int(11) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `frame_no` varchar(100) DEFAULT NULL COMMENT 'Chassis / VIN / frame number (Excel "Frame")',
  `engine_model` varchar(100) DEFAULT NULL,
  `engine_description` varchar(150) DEFAULT NULL COMMENT 'Excel "Engine" free text (e.g. 1HZ 4164ml)',
  `engine_serial` varchar(100) DEFAULT NULL COMMENT 'Excel "Engine serial #" / "Engine S/N"',
  `current_hours` decimal(10,2) DEFAULT 0.00,
  `current_km` decimal(10,2) DEFAULT 0.00,
  `meter_type` enum('Hours','Kilometres') NOT NULL DEFAULT 'Hours' COMMENT 'Which meter is the primary one for this asset',
  `location` varchar(200) DEFAULT NULL,
  `masterdata_source` varchar(100) DEFAULT NULL COMMENT 'W0.5/W0.6: which sample row or rule produced legacy_code/location',
  `status` enum('Available','In Maintenance','Breakdown','Standby','Retired') DEFAULT 'Available',
  `report_group` varchar(50) DEFAULT NULL,
  `report_sort` int(11) NOT NULL DEFAULT 0,
  `include_in_weekly_report` tinyint(1) NOT NULL DEFAULT 1,
  `photo` varchar(255) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `manufacture_date` date DEFAULT NULL COMMENT 'Excel "Manf Date"',
  `commission_date` date DEFAULT NULL COMMENT 'Excel "Starting date" / "Start-Reg Date" — first day in service',
  `start_meter` decimal(10,2) DEFAULT NULL COMMENT 'Excel "Starting Index" / "Machine Hrs started" numeric part',
  `fatigue_system` enum('Yes','No','N/A') NOT NULL DEFAULT 'N/A' COMMENT 'Excel "Fatigue Mngt system installed"',
  `order_no` varchar(50) DEFAULT NULL COMMENT 'Excel "Order No" (purchase reference)',
  `warranty_expiry` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `equipment_kpi_targets` (
  `id` int(11) NOT NULL,
  `scope` enum('Equipment','Group') NOT NULL,
  `equipment_id` int(11) DEFAULT NULL COMMENT 'scope = Equipment',
  `group_code` varchar(50) DEFAULT NULL COMMENT 'scope = Group, references report_groups.code',
  `target_date` date DEFAULT NULL COMMENT 'NULL = standing default for the scope',
  `target_pct` decimal(5,4) NOT NULL COMMENT '0.9000 = 90 %',
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='W36 availability targets. Resolution order: equipment+date, equipment default, group+date, group default, settings.default_availability_target';

CREATE TABLE `equipment_meter_readings` (
  `id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `reading_date` date NOT NULL,
  `hours` decimal(10,1) DEFAULT NULL,
  `km` decimal(10,1) DEFAULT NULL,
  `source` enum('Manual','Import','WorkOrder') NOT NULL DEFAULT 'Manual',
  `work_order_id` int(11) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Daily/periodic meter readings; equipment.current_hours / current_km keep the latest value';

CREATE TABLE `equipment_planned_downtime` (
  `id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `plan_date` date NOT NULL COMMENT 'the machine-day, same grain as the weekly report',
  `category` enum('PM','Standing','Unplanned','OutOfService','Corrective') NOT NULL COMMENT 'D32 + R-MP5 — Corrective = a known, dated repair (still downtime)',
  `hours` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'planned hours lost on that day, 0…24',
  `source` enum('Default','Manual','FromPM','Derived','FromPlan') NOT NULL DEFAULT 'Manual' COMMENT 'D31 + MP-2 — FromPlan = summed from maintenance_plan_jobs',
  `source_note` varchar(255) DEFAULT NULL COMMENT 'which PM schedule / which derivation produced these hours',
  `created_by` int(11) DEFAULT NULL COMMENT 'users.id',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Planned downtime per machine-day (FORECAST_REVIEW task F2). Availability is derived from it by kpi_availability(), never by its own arithmetic (D29).';

CREATE TABLE `equipment_specs` (
  `id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `spec_key` varchar(60) NOT NULL COMMENT 'Normalised key, e.g. rated_power',
  `spec_label` varchar(100) NOT NULL COMMENT 'Label exactly as the workshop writes it in Excel',
  `spec_value` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `equipment_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `excel_migration_log` (
  `id` int(11) NOT NULL,
  `batch` varchar(40) NOT NULL,
  `source_file` varchar(120) NOT NULL,
  `source_sheet` varchar(80) NOT NULL,
  `target_table` varchar(80) NOT NULL,
  `rows_read` int(11) NOT NULL DEFAULT 0,
  `rows_loaded` int(11) NOT NULL DEFAULT 0,
  `rows_skipped` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `run_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `forecast_snapshots` (
  `id` int(11) NOT NULL,
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
  `published_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Frozen planned-availability forecasts (D33). Revision 1 is the baseline; later revisions never overwrite it.';

CREATE TABLE `goods_receipts` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `grn_no` varchar(50) NOT NULL COMMENT 'Goods Received Note number, one per delivery',
  `submit_token` varchar(64) DEFAULT NULL COMMENT 'One-time token of the receive form: a replayed submit cannot create a second GRN',
  `receipt_date` date NOT NULL,
  `received_by` int(11) DEFAULT NULL COMMENT 'users.id of the storeman who booked the delivery',
  `discrepancy_flag` varchar(20) NOT NULL DEFAULT 'None',
  `discrepancy_notes` varchar(300) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `goods_receipt_lines` (
  `id` int(11) NOT NULL,
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
  `notes` varchar(300) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `incident_details` (
  `id` int(11) NOT NULL,
  `wo_id` int(11) NOT NULL,
  `incident_datetime` datetime DEFAULT NULL,
  `site` varchar(100) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `involved_party_type` enum('Employee','Contractor','Third Party') DEFAULT NULL,
  `staff_id` varchar(50) DEFAULT NULL,
  `person_name` varchar(100) DEFAULT NULL,
  `person_function` varchar(100) DEFAULT NULL,
  `injured` tinyint(1) NOT NULL DEFAULT 0,
  `fleet_no` varchar(50) DEFAULT NULL,
  `part_affected` varchar(150) DEFAULT NULL,
  `equipment_damaged` tinyint(1) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `injury_damage_details` text DEFAULT NULL,
  `actions_taken` text DEFAULT NULL,
  `activity_status` enum('Controlled','Monitored','Uncontrolled') DEFAULT NULL,
  `review_status` enum('Submitted','Reviewed') NOT NULL DEFAULT 'Submitted',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inspection_items` (
  `id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `row_type` enum('section','item') NOT NULL DEFAULT 'item',
  `item_no` int(11) DEFAULT NULL,
  `category_en` varchar(100) DEFAULT NULL,
  `category_fr` varchar(100) DEFAULT NULL,
  `text_en` text DEFAULT NULL,
  `text_fr` text DEFAULT NULL,
  `na_columns` varchar(255) DEFAULT NULL,
  `priority` enum('P1','P2','P3') DEFAULT NULL COMMENT 'Explicit defect severity; NULL = derive from the category'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inspection_records` (
  `id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `language` enum('en','fr') NOT NULL DEFAULT 'en',
  `inspection_date` date DEFAULT NULL,
  `fleet_number` varchar(50) DEFAULT NULL,
  `equipment_type` varchar(100) DEFAULT NULL,
  `smr` varchar(50) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `equipment_id` int(11) DEFAULT NULL,
  `general_comments` text DEFAULT NULL,
  `mechanic_name` varchar(150) DEFAULT NULL,
  `mechanic_signature` varchar(255) DEFAULT NULL,
  `mechanic_date` date DEFAULT NULL,
  `supervisor_name` varchar(150) DEFAULT NULL,
  `supervisor_signature` varchar(255) DEFAULT NULL,
  `supervisor_date` date DEFAULT NULL,
  `operator_name` varchar(150) DEFAULT NULL,
  `operator_signature` varchar(255) DEFAULT NULL,
  `operator_date` date DEFAULT NULL,
  `prod_supervisor_name` varchar(150) DEFAULT NULL,
  `prod_supervisor_signature` varchar(255) DEFAULT NULL,
  `prod_supervisor_date` date DEFAULT NULL,
  `status` enum('draft','submitted') NOT NULL DEFAULT 'submitted',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inspection_record_items` (
  `id` int(11) NOT NULL,
  `record_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `value_json` text DEFAULT NULL,
  `comment` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inspection_templates` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name_en` varchar(255) NOT NULL,
  `name_fr` varchar(255) NOT NULL,
  `category_en` varchar(100) DEFAULT 'General',
  `category_fr` varchar(100) DEFAULT 'Général',
  `layout_type` enum('matrix','compliance') NOT NULL DEFAULT 'compliance',
  `columns_json` text DEFAULT NULL,
  `meter_label_en` varchar(20) NOT NULL DEFAULT 'SMR',
  `meter_label_fr` varchar(20) NOT NULL DEFAULT 'SMR',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_import_logs` (
  `id` int(11) NOT NULL,
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
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kpi_log` (
  `id` int(11) NOT NULL,
  `equipment_id` int(11) DEFAULT NULL,
  `log_date` date NOT NULL,
  `availability_pct` decimal(5,2) DEFAULT NULL,
  `mttr_hours` decimal(8,2) DEFAULT NULL,
  `mtbf_hours` decimal(8,2) DEFAULT NULL,
  `total_downtime` decimal(8,2) DEFAULT NULL,
  `breakdown_count` int(11) DEFAULT 0,
  `pm_count` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leave_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `leave_type` enum('Annual Leave','Sick Leave','Emergency','Unpaid','Maternity','Paternity','Training') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days_count` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL COMMENT 'lower-cased address the attempt was made for',
  `ip_address` varchar(45) NOT NULL COMMENT 'IPv4 or IPv6 of the client',
  `attempted_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lube_budget` (
  `id` int(11) NOT NULL,
  `lube_type_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `budget_qty` decimal(10,2) DEFAULT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `budget_cost` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lube_consumption` (
  `id` int(11) NOT NULL,
  `work_order_id` int(11) DEFAULT NULL,
  `equipment_id` int(11) NOT NULL,
  `lube_type_id` int(11) NOT NULL,
  `quantity_used` decimal(10,2) NOT NULL,
  `recommended_qty` decimal(10,2) DEFAULT NULL,
  `variance` decimal(10,2) DEFAULT NULL,
  `variance_reason` text DEFAULT NULL,
  `consumption_date` date NOT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `equipment_hours` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lube_specifications` (
  `id` int(11) NOT NULL,
  `equipment_type_id` int(11) NOT NULL,
  `lube_type_id` int(11) NOT NULL,
  `recommended_qty` decimal(10,2) NOT NULL,
  `service_interval_hours` int(11) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lube_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` enum('Engine Oil','Hydraulic Oil','Transmission Oil','Differential Oil','Grease','Coolant','Compressor Oil','Filter','Other') NOT NULL,
  `grade` varchar(50) DEFAULT NULL,
  `unit` varchar(20) DEFAULT 'L',
  `unit_cost` decimal(10,2) DEFAULT 0.00,
  `description` varchar(300) DEFAULT NULL,
  `status` enum('Active','Discontinued') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `maintenance_plan_jobs` (
  `id` int(11) NOT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Planned maintenance jobs (MAINTENANCE_PLAN_REVIEW R-MP1). Hours reach equipment_planned_downtime as source FromPlan; Manual ledger rows always win.';

CREATE TABLE `materials` (
  `id` int(11) NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'CONSUMABLE',
  `description` varchar(255) NOT NULL,
  `part_number_size` varchar(100) DEFAULT NULL,
  `unit` varchar(20) DEFAULT 'pcs',
  `initial_stock` int(11) NOT NULL DEFAULT 0,
  `qty_received` int(11) NOT NULL DEFAULT 0,
  `qty_sortie` int(11) NOT NULL DEFAULT 0,
  `final_qty` int(11) NOT NULL DEFAULT 0,
  `min_stock` int(11) DEFAULT NULL COMMENT 'Re-order level (Excel "Min stock")',
  `max_stock` int(11) DEFAULT NULL COMMENT 'Target / maximum level (Excel "Max stock")',
  `unit_price_ugx` decimal(14,2) DEFAULT NULL COMMENT 'Excel "Price UGX"',
  `unit_price_usd` decimal(14,4) DEFAULT NULL COMMENT 'Excel "Price USD"',
  `supplier_id` int(11) DEFAULT NULL COMMENT 'Preferred supplier, when it is on file',
  `supplier_name` varchar(150) DEFAULT NULL COMMENT 'Excel "Vendor/Supplier" as written',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'De-listed items stay for history',
  `application` varchar(150) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `material_transactions` (
  `id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `type` enum('RECEIVE','ISSUE') NOT NULL,
  `txn_date` date DEFAULT NULL COMMENT 'Business date of the movement (Excel Date / Delivery date)',
  `reference_no` varchar(50) DEFAULT NULL COMMENT 'Requisition no (Sortie "R.non") or MRN no (Receipts)',
  `qty` decimal(10,2) NOT NULL,
  `allocation_type` enum('Equipment','Department','Other') NOT NULL DEFAULT 'Other' COMMENT 'What the issue was charged to',
  `equipment_id` int(11) DEFAULT NULL COMMENT 'Set when the allocation resolves to a fleet asset',
  `allocation_label` varchar(150) DEFAULT NULL COMMENT 'Allocation exactly as written (ADT015, WELDING BAY, HSE…)',
  `person` varchar(100) DEFAULT NULL COMMENT 'Receiver (issue) or receiving storeman (receipt)',
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(150) DEFAULT NULL COMMENT 'Supplier as written on the receipt',
  `qty_approved` decimal(12,2) DEFAULT NULL COMMENT 'Receipts: approved vs actually delivered',
  `unit_price` decimal(14,2) DEFAULT NULL,
  `currency` varchar(3) DEFAULT NULL,
  `source` enum('Manual','Excel Import','Order GRN','Work Order') NOT NULL DEFAULT 'Manual',
  `import_key` varchar(64) DEFAULT NULL COMMENT 'Idempotency key — a migrated Excel row can never load twice',
  `affects_balance` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = history row loaded from Excel; the balance already includes it',
  `stock_before` decimal(10,2) DEFAULT NULL,
  `stock_after` decimal(10,2) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_documents` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `doc_type` varchar(50) DEFAULT 'Delivery Note',
  `file_name` varchar(200) NOT NULL,
  `original_name` varchar(200) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_status_history` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `status` varchar(50) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pm_completions` (
  `id` int(11) NOT NULL,
  `pm_schedule_id` int(11) NOT NULL,
  `work_order_id` int(11) DEFAULT NULL,
  `completed_date` date NOT NULL,
  `completed_hours` decimal(10,2) DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pm_part_requirements` (
  `id` int(11) NOT NULL,
  `pm_schedule_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000 COMMENT 'consumed per ONE occurrence of the schedule',
  `is_critical` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'the PM cannot proceed without it',
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Spare parts consumed by one occurrence of a PM schedule (FORECAST_REVIEW task F10)';

CREATE TABLE `pm_schedules` (
  `id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `schedule_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `interval_type` enum('Hours','Days','Months','Km') NOT NULL DEFAULT 'Hours',
  `interval_value` int(11) NOT NULL DEFAULT 250,
  `standard_hours` decimal(5,2) DEFAULT NULL COMMENT 'planned duration of ONE occurrence, hours (FORECAST_REVIEW gap G-F4)',
  `last_done_date` date DEFAULT NULL,
  `last_done_hours` decimal(10,2) DEFAULT NULL,
  `next_due_date` date DEFAULT NULL,
  `next_due_hours` decimal(10,2) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `tasks_checklist` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `preferred_weekday` tinyint(4) DEFAULT NULL COMMENT '1 = Mon … 7 = Sun — where a weekly schedule lands inside the week (R-MP2)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `report_groups` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL COMMENT 'exactly as printed on sheet W36',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='W36 report groups (order and labels as printed on the sheet)';

CREATE TABLE `report_sites` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL COMMENT 'stable key stored in equipment.location',
  `label` varchar(100) NOT NULL COMMENT 'what the report prints',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `security_events` (
  `id` int(11) NOT NULL,
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
  `acknowledged_note` varchar(255) DEFAULT NULL COMMENT 'what was done about it'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(300) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shifts` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `description` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shift_assignments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `week_start` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `spare_orders` (
  `id` int(11) NOT NULL,
  `order_no` varchar(30) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `country` varchar(80) NOT NULL,
  `shipping_method` enum('Air Freight','Sea Freight','Road Transport','Rail') NOT NULL DEFAULT 'Road Transport',
  `order_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `actual_delivery_date` date DEFAULT NULL,
  `status` enum('Pending Approval','Sent to Supplier','Confirmed','In Transit','Customs Clearance','Received at Store','Verified & Closed','Cancelled') NOT NULL DEFAULT 'Pending Approval',
  `tracking_number` varchar(100) DEFAULT NULL,
  `carrier` varchar(100) DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'TZS',
  `total_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `linked_wo_id` int(11) DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `discrepancy_flag` enum('None','Shortage','Damage','Wrong Item') NOT NULL DEFAULT 'None',
  `discrepancy_notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `spare_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `material_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `part_number` varchar(100) DEFAULT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `qty_ordered` decimal(12,2) NOT NULL DEFAULT 0.00,
  `qty_received` decimal(12,2) DEFAULT NULL,
  `unit_price` decimal(14,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `spare_part_alerts` (
  `id` int(11) NOT NULL,
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
  `read_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `company_name` varchar(150) NOT NULL,
  `tax_id` varchar(50) DEFAULT NULL,
  `country` varchar(80) NOT NULL,
  `city` varchar(80) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'TZS',
  `payment_terms` varchar(100) DEFAULT NULL,
  `lead_time_guarantee_days` int(11) DEFAULT NULL,
  `status` enum('Active','Inactive','Blacklisted') NOT NULL DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `supplier_contacts` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` varchar(80) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `supplier_materials` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `material_id` int(11) DEFAULT NULL,
  `part_number` varchar(100) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `unit_price` decimal(14,2) DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'TZS',
  `moq` decimal(12,2) DEFAULT NULL,
  `lead_time_days` int(11) DEFAULT NULL,
  `is_preferred` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `skill_level` enum('Junior','Senior','Expert','Specialist') DEFAULT 'Junior',
  `photo` varchar(255) DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `status` enum('Active','Inactive','On Leave') DEFAULT 'Active',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `signature` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `weekly_report_names` (
  `id` int(11) NOT NULL,
  `week_start` date NOT NULL,
  `report_name` varchar(22) NOT NULL,
  `updated_by` int(11) DEFAULT NULL COMMENT 'users.id',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dashboard-controlled display and workbook name for each report week';

CREATE TABLE `weekly_report_snapshots` (
  `id` int(11) NOT NULL,
  `week_start` date NOT NULL COMMENT 'the ISO week this snapshot freezes',
  `week_end` date NOT NULL,
  `iso_label` varchar(10) NOT NULL COMMENT 'e.g. 2026-W36 — the picker label',
  `payload` longtext NOT NULL COMMENT 'W36_report_model::build() verbatim, JSON',
  `payload_checksum` char(64) NOT NULL COMMENT 'sha256 of payload — proves a re-download is byte-identical',
  `build_ms` int(11) DEFAULT NULL COMMENT 'how long the frozen build took',
  `published_by` int(11) DEFAULT NULL COMMENT 'users.id',
  `published_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Frozen weekly report snapshots (G6): the report as published, for reproducible re-downloads';

CREATE TABLE `work_orders` (
  `id` int(11) NOT NULL,
  `wo_number` varchar(30) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `type` enum('Corrective Maintenance','Preventive Maintenance','Incident') NOT NULL,
  `is_breakdown` tinyint(1) NOT NULL DEFAULT 0,
  `breakdown_event_id` int(11) DEFAULT NULL,
  `priority` enum('Critical','High','Medium','Low') DEFAULT 'Medium',
  `status` enum('Open','In Progress','Waiting Parts','Waiting Tools','Completed','Closed') DEFAULT 'Open',
  `hold_code` enum('WSP','WQT','WCF','UD','UR') DEFAULT NULL,
  `machine_status` varchar(20) DEFAULT 'Operational',
  `equipment_systems` varchar(100) DEFAULT NULL,
  `title` varchar(300) NOT NULL,
  `description` text DEFAULT NULL,
  `symptoms` text DEFAULT NULL,
  `root_cause` text DEFAULT NULL,
  `pending_work` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `planner` varchar(100) DEFAULT NULL,
  `purchase_order` varchar(100) DEFAULT NULL,
  `approval` varchar(100) DEFAULT NULL,
  `workplace` varchar(150) DEFAULT NULL,
  `action_taken` text DEFAULT NULL,
  `hours_reading` decimal(10,1) DEFAULT NULL,
  `start_datetime` datetime DEFAULT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `downtime_hours` decimal(8,2) DEFAULT NULL,
  `assigned_to` varchar(255) DEFAULT NULL COMMENT 'Comma-separated user IDs, e.g. 3,5,7',
  `created_by` int(11) NOT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `estimated_hours` decimal(8,2) DEFAULT NULL,
  `actual_hours` decimal(8,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `supervisor_id` int(11) DEFAULT NULL,
  `operator_id` int(11) DEFAULT NULL,
  `operator_name` varchar(200) DEFAULT NULL COMMENT 'Free-text operator/driver name (can be external)',
  `operator_signature` varchar(255) DEFAULT NULL COMMENT 'Path to drawn operator signature PNG file',
  `expected_return_date` date DEFAULT NULL COMMENT 'when the machine is expected back in production (R-MP4)',
  `verified_by` int(11) DEFAULT NULL COMMENT 'users.id of the supervisor who verified the repair',
  `verified_at` datetime DEFAULT NULL COMMENT 'When the repair was verified',
  `verification_notes` varchar(300) DEFAULT NULL COMMENT 'What the verifier checked / found'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wo_attachments` (
  `id` int(11) NOT NULL,
  `wo_id` int(11) NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'Incident Evidence',
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wo_oil_records` (
  `id` int(11) NOT NULL,
  `work_order_id` int(11) NOT NULL,
  `system_location` varchar(150) DEFAULT NULL,
  `oil_type` varchar(150) DEFAULT NULL,
  `grade` varchar(80) DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wo_sessions` (
  `id` int(11) NOT NULL,
  `wo_id` int(11) NOT NULL,
  `session_no` int(11) NOT NULL,
  `technician_id` int(11) DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `hours_worked` decimal(8,2) DEFAULT NULL,
  `work_performed` text DEFAULT NULL,
  `interruption_reason` varchar(255) DEFAULT NULL,
  `switched_to_wo_id` int(11) DEFAULT NULL,
  `status` enum('Open','Closed') NOT NULL DEFAULT 'Open',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wo_spare_allocations` (
  `id` int(11) NOT NULL,
  `wo_spare_part_id` int(11) NOT NULL COMMENT 'FK wo_spare_parts.id (the requested line)',
  `work_order_id` int(11) NOT NULL COMMENT 'Denormalised for fast lookups',
  `material_id` int(11) NOT NULL COMMENT 'FK materials.id (the stock item)',
  `qty_allocated` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Allocated','Issued','Released') NOT NULL DEFAULT 'Allocated' COMMENT 'Allocated = reserved in store, Issued = physically handed out, Released = reservation cancelled',
  `allocated_by` int(11) DEFAULT NULL,
  `allocated_at` datetime DEFAULT NULL,
  `issued_at` datetime DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wo_spare_parts` (
  `id` int(11) NOT NULL,
  `work_order_id` int(11) NOT NULL,
  `item_code` varchar(50) DEFAULT NULL,
  `part_name` varchar(200) NOT NULL,
  `part_number` varchar(100) DEFAULT NULL,
  `spare_type` enum('New','From Machine') DEFAULT 'New',
  `source_machine` varchar(100) DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT NULL,
  `unit` varchar(20) DEFAULT 'pcs',
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `total_cost` decimal(10,2) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wo_status_history` (
  `id` int(11) NOT NULL,
  `work_order_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `changed_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` varchar(20) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `checksum` char(64) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `asset_import_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_user` (`user_id`);

ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`user_id`,`date`),
  ADD KEY `created_by` (`created_by`);

ALTER TABLE `backlogs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_backlog_source_ref` (`source_ref`),
  ADD KEY `idx_backlog_equipment` (`equipment_id`),
  ADD KEY `idx_backlog_status` (`status`),
  ADD KEY `idx_backlog_start` (`start_date`),
  ADD KEY `idx_backlog_priority` (`priority`),
  ADD KEY `idx_backlog_insp_item` (`inspection_item_id`,`equipment_id`),
  ADD KEY `idx_backlog_equipment_status` (`equipment_id`,`status`);

ALTER TABLE `certifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `ci_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ci_sessions_ip` (`ip_address`),
  ADD KEY `ci_sessions_timestamp` (`timestamp`);

ALTER TABLE `equipment`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `registration_no` (`registration_no`),
  ADD KEY `equipment_type_id` (`equipment_type_id`),
  ADD KEY `idx_equipment_legacy` (`legacy_code`),
  ADD KEY `idx_equipment_frame` (`frame_no`),
  ADD KEY `idx_equipment_mtype` (`machine_type`),
  ADD KEY `idx_equipment_report` (`include_in_weekly_report`,`report_group`,`report_sort`);

ALTER TABLE `equipment_kpi_targets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_target_eq` (`scope`,`equipment_id`,`target_date`),
  ADD KEY `idx_target_grp` (`scope`,`group_code`,`target_date`);

ALTER TABLE `equipment_meter_readings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_meter_eq_date` (`equipment_id`,`reading_date`),
  ADD KEY `idx_meter_date` (`reading_date`);

ALTER TABLE `equipment_planned_downtime`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_planned_dt_machine_day_cat` (`equipment_id`,`plan_date`,`category`),
  ADD KEY `idx_planned_dt_date` (`plan_date`),
  ADD KEY `idx_planned_dt_category` (`category`);

ALTER TABLE `equipment_specs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_equipment_spec` (`equipment_id`,`spec_key`);

ALTER TABLE `equipment_types`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `excel_migration_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_excel_batch` (`batch`);

ALTER TABLE `forecast_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_forecast_snapshot_week_rev` (`week_start`,`revision`),
  ADD KEY `idx_forecast_snapshot_week` (`week_start`);

ALTER TABLE `goods_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_grn_no` (`grn_no`),
  ADD UNIQUE KEY `uq_grn_submit_token` (`submit_token`),
  ADD KEY `idx_grn_order` (`order_id`,`receipt_date`),
  ADD KEY `idx_grn_date` (`receipt_date`);

ALTER TABLE `goods_receipt_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_grl_receipt` (`receipt_id`),
  ADD KEY `idx_grl_item` (`order_item_id`),
  ADD KEY `idx_grl_material` (`material_id`),
  ADD KEY `idx_grl_movement` (`movement_id`);

ALTER TABLE `incident_details`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `wo_id` (`wo_id`);

ALTER TABLE `inspection_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `template_id` (`template_id`);

ALTER TABLE `inspection_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `template_id` (`template_id`),
  ADD KEY `equipment_id` (`equipment_id`);

ALTER TABLE `inspection_record_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `record_id` (`record_id`),
  ADD KEY `item_id` (`item_id`);

ALTER TABLE `inspection_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

ALTER TABLE `inventory_import_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_user` (`user_id`);

ALTER TABLE `kpi_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `equipment_id` (`equipment_id`);

ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `approved_by` (`approved_by`);

ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_login_attempts_pair` (`email`,`ip_address`,`attempted_at`),
  ADD KEY `idx_login_attempts_account` (`email`,`attempted_at`),
  ADD KEY `idx_login_attempts_prune` (`attempted_at`),
  ADD KEY `idx_login_attempts_source` (`ip_address`,`attempted_at`);

ALTER TABLE `lube_budget`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_budget` (`lube_type_id`,`year`,`month`);

ALTER TABLE `lube_consumption`
  ADD PRIMARY KEY (`id`),
  ADD KEY `work_order_id` (`work_order_id`),
  ADD KEY `equipment_id` (`equipment_id`),
  ADD KEY `lube_type_id` (`lube_type_id`),
  ADD KEY `recorded_by` (`recorded_by`);

ALTER TABLE `lube_specifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `equipment_type_id` (`equipment_type_id`),
  ADD KEY `lube_type_id` (`lube_type_id`);

ALTER TABLE `lube_types`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `maintenance_plan_jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mpj_equipment_dates` (`equipment_id`,`start_date`,`end_date`),
  ADD KEY `idx_mpj_dates` (`start_date`,`end_date`);

ALTER TABLE `materials`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `item_code` (`item_code`),
  ADD KEY `idx_materials_category` (`category`),
  ADD KEY `idx_materials_supplier` (`supplier_id`),
  ADD KEY `idx_materials_final` (`final_qty`);

ALTER TABLE `material_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mt_import_key` (`import_key`),
  ADD KEY `material_id` (`material_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_mt_date` (`txn_date`),
  ADD KEY `idx_mt_type_date` (`type`,`txn_date`),
  ADD KEY `idx_mt_equipment` (`equipment_id`),
  ADD KEY `idx_mt_ref` (`reference_no`);

ALTER TABLE `order_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

ALTER TABLE `order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

ALTER TABLE `pm_completions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pm_schedule_id` (`pm_schedule_id`),
  ADD KEY `work_order_id` (`work_order_id`);

ALTER TABLE `pm_part_requirements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pm_part` (`pm_schedule_id`,`material_id`),
  ADD KEY `idx_pm_part_material` (`material_id`);

ALTER TABLE `pm_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `equipment_id` (`equipment_id`);

ALTER TABLE `report_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_report_group_code` (`code`);

ALTER TABLE `report_sites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_report_site_code` (`code`);

ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `security_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_events_type` (`event_type`,`created_at`),
  ADD KEY `idx_security_events_open` (`acknowledged_at`,`created_at`),
  ADD KEY `idx_security_events_subj` (`subject`,`created_at`);

ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `shift_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `shift_id` (`shift_id`);

ALTER TABLE `spare_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_no` (`order_no`),
  ADD KEY `supplier_id` (`supplier_id`);

ALTER TABLE `spare_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

ALTER TABLE `spare_part_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wo` (`work_order_id`),
  ADD KEY `idx_material` (`material_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `supplier_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`);

ALTER TABLE `supplier_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `role_id` (`role_id`);

ALTER TABLE `weekly_report_names`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_weekly_report_name_week` (`week_start`);

ALTER TABLE `weekly_report_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_snapshot_week` (`week_start`);

ALTER TABLE `work_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `wo_number` (`wo_number`),
  ADD KEY `equipment_id` (`equipment_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_priority` (`priority`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_equipment_status` (`equipment_id`,`status`),
  ADD KEY `fk_wo_supervisor` (`supervisor_id`),
  ADD KEY `idx_wo_breakdown` (`is_breakdown`,`start_datetime`),
  ADD KEY `idx_wo_event` (`breakdown_event_id`);

ALTER TABLE `wo_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wo_attachments_wo_id` (`wo_id`),
  ADD KEY `idx_wo_attachments_uploaded_by` (`uploaded_by`);

ALTER TABLE `wo_oil_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `work_order_id` (`work_order_id`);

ALTER TABLE `wo_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wo` (`wo_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_switched_to` (`switched_to_wo_id`);

ALTER TABLE `wo_spare_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wo_part` (`wo_spare_part_id`),
  ADD KEY `idx_wo` (`work_order_id`),
  ADD KEY `idx_material` (`material_id`),
  ADD KEY `idx_status` (`status`);

ALTER TABLE `wo_spare_parts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `work_order_id` (`work_order_id`);

ALTER TABLE `wo_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `work_order_id` (`work_order_id`);

ALTER TABLE `lube_specifications` ADD UNIQUE INDEX `uq_lube_spec_eq_lube` (`equipment_type_id`,`lube_type_id`);

ALTER TABLE `spare_orders` ADD INDEX `idx_so_linked_wo` (`linked_wo_id`);

ALTER TABLE `spare_orders` ADD INDEX `idx_so_requested_by` (`requested_by`);

ALTER TABLE `spare_orders` ADD INDEX `idx_so_status` (`status`);

ALTER TABLE `spare_orders` ADD INDEX `idx_so_expected` (`expected_delivery_date`);

ALTER TABLE `spare_order_items` ADD INDEX `idx_soi_material` (`material_id`);

ALTER TABLE `supplier_materials` ADD INDEX `idx_sm_material` (`material_id`);

ALTER TABLE `pm_part_requirements` ADD INDEX `idx_pmpr_material` (`material_id`);

ALTER TABLE `materials` ADD INDEX `idx_mat_supplier` (`supplier_id`);

ALTER TABLE `materials` ADD INDEX `idx_mat_category` (`category`);

ALTER TABLE `material_transactions` ADD INDEX `idx_mt_supplier` (`supplier_id`);

ALTER TABLE `inspection_records` ADD INDEX `idx_ir_created_by` (`created_by`);

ALTER TABLE `inspection_records` ADD INDEX `idx_ir_date` (`inspection_date`);

ALTER TABLE `backlogs` ADD INDEX `idx_backlog_wo` (`work_order_id`);

ALTER TABLE `backlogs` ADD INDEX `idx_backlog_created_by` (`created_by`);

ALTER TABLE `wo_sessions` ADD INDEX `idx_wos_technician` (`technician_id`);

ALTER TABLE `wo_sessions` ADD INDEX `idx_wos_created_by` (`created_by`);

ALTER TABLE `work_orders` ADD INDEX `idx_wo_bd_event` (`breakdown_event_id`);

ALTER TABLE `work_orders` ADD INDEX `idx_wo_completed_by` (`completed_by`);

ALTER TABLE `work_orders` ADD INDEX `idx_wo_verified_by` (`verified_by`);

ALTER TABLE `work_orders` ADD INDEX `idx_wo_operator_id` (`operator_id`);

ALTER TABLE `work_orders` ADD INDEX `idx_wo_status_start` (`status`,`start_datetime`);

ALTER TABLE `maintenance_plan_jobs` ADD INDEX `idx_mpj_pm_schedule` (`pm_schedule_id`);

ALTER TABLE `maintenance_plan_jobs` ADD INDEX `idx_mpj_work_order` (`work_order_id`);

ALTER TABLE `maintenance_plan_jobs` ADD INDEX `idx_mpj_backlog` (`backlog_id`);

ALTER TABLE `maintenance_plan_jobs` ADD INDEX `idx_mpj_created_by` (`created_by`);

ALTER TABLE `maintenance_plan_jobs` ADD INDEX `idx_mpj_updated_by` (`updated_by`);

ALTER TABLE `equipment_meter_readings` ADD INDEX `idx_emr_work_order` (`work_order_id`);

ALTER TABLE `equipment_meter_readings` ADD INDEX `idx_emr_recorded_by` (`recorded_by`);

ALTER TABLE `equipment_kpi_targets` ADD INDEX `idx_ekt_equipment` (`equipment_id`);

ALTER TABLE `equipment_kpi_targets` ADD INDEX `idx_ekt_created_by` (`created_by`);

ALTER TABLE `equipment_planned_downtime` ADD INDEX `idx_epd_created_by` (`created_by`);

ALTER TABLE `forecast_snapshots` ADD INDEX `idx_fs_published_by` (`published_by`);

ALTER TABLE `weekly_report_snapshots` ADD INDEX `idx_wrs_published_by` (`published_by`);

ALTER TABLE `weekly_report_names` ADD INDEX `idx_wrn_updated_by` (`updated_by`);

ALTER TABLE `goods_receipts` ADD INDEX `idx_grn_received_by` (`received_by`);

ALTER TABLE `spare_part_alerts` ADD INDEX `idx_spa_wo_part` (`wo_spare_part_id`);

ALTER TABLE `spare_part_alerts` ADD INDEX `idx_spa_received_by` (`received_by`);

ALTER TABLE `spare_part_alerts` ADD INDEX `idx_spa_read_by` (`read_by`);

ALTER TABLE `wo_spare_allocations` ADD INDEX `idx_wsa_allocated_by` (`allocated_by`);

ALTER TABLE `asset_import_logs` ADD INDEX `idx_ail_user` (`user_id`);

ALTER TABLE `inventory_import_logs` ADD INDEX `idx_iil_user` (`user_id`);

ALTER TABLE `asset_import_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `backlogs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

ALTER TABLE `certifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

ALTER TABLE `equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=175;

ALTER TABLE `equipment_kpi_targets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `equipment_meter_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

ALTER TABLE `equipment_planned_downtime`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `equipment_specs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=512;

ALTER TABLE `equipment_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

ALTER TABLE `excel_migration_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

ALTER TABLE `forecast_snapshots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `goods_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `goods_receipt_lines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `incident_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `inspection_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=631;

ALTER TABLE `inspection_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `inspection_record_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `inspection_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

ALTER TABLE `inventory_import_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `kpi_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `leave_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `lube_budget`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `lube_consumption`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `lube_specifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `lube_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

ALTER TABLE `maintenance_plan_jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1744;

ALTER TABLE `material_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16384;

ALTER TABLE `order_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `pm_completions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `pm_part_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `pm_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `report_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

ALTER TABLE `report_sites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

ALTER TABLE `security_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

ALTER TABLE `shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `shift_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `spare_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `spare_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `spare_part_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

ALTER TABLE `supplier_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `supplier_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

ALTER TABLE `weekly_report_names`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `weekly_report_snapshots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `work_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

ALTER TABLE `wo_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `wo_oil_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `wo_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `wo_spare_allocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `wo_spare_parts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `wo_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `inspection_templates` AUTO_INCREMENT = 15;

ALTER TABLE `inspection_items` AUTO_INCREMENT = 631;

ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

ALTER TABLE `backlogs`
  ADD CONSTRAINT `fk_backlog_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE SET NULL;

ALTER TABLE `certifications`
  ADD CONSTRAINT `certifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

ALTER TABLE `equipment`
  ADD CONSTRAINT `equipment_ibfk_1` FOREIGN KEY (`equipment_type_id`) REFERENCES `equipment_types` (`id`);

ALTER TABLE `equipment_planned_downtime`
  ADD CONSTRAINT `fk_planned_dt_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE;

ALTER TABLE `equipment_specs`
  ADD CONSTRAINT `fk_equipment_specs_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE;

ALTER TABLE `incident_details`
  ADD CONSTRAINT `fk_incident_wo` FOREIGN KEY (`wo_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `inspection_items`
  ADD CONSTRAINT `fk_insp_items_template` FOREIGN KEY (`template_id`) REFERENCES `inspection_templates` (`id`) ON DELETE CASCADE;

ALTER TABLE `inspection_records`
  ADD CONSTRAINT `fk_insp_records_template` FOREIGN KEY (`template_id`) REFERENCES `inspection_templates` (`id`);

ALTER TABLE `inspection_record_items`
  ADD CONSTRAINT `fk_insp_record_items_item` FOREIGN KEY (`item_id`) REFERENCES `inspection_items` (`id`),
  ADD CONSTRAINT `fk_insp_record_items_record` FOREIGN KEY (`record_id`) REFERENCES `inspection_records` (`id`) ON DELETE CASCADE;

ALTER TABLE `kpi_log`
  ADD CONSTRAINT `kpi_log_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`);

ALTER TABLE `leave_requests`
  ADD CONSTRAINT `leave_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `leave_requests_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

ALTER TABLE `lube_budget`
  ADD CONSTRAINT `lube_budget_ibfk_1` FOREIGN KEY (`lube_type_id`) REFERENCES `lube_types` (`id`);

ALTER TABLE `lube_consumption`
  ADD CONSTRAINT `lube_consumption_ibfk_1` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`),
  ADD CONSTRAINT `lube_consumption_ibfk_2` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  ADD CONSTRAINT `lube_consumption_ibfk_3` FOREIGN KEY (`lube_type_id`) REFERENCES `lube_types` (`id`),
  ADD CONSTRAINT `lube_consumption_ibfk_4` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`);

ALTER TABLE `lube_specifications`
  ADD CONSTRAINT `lube_specifications_ibfk_1` FOREIGN KEY (`equipment_type_id`) REFERENCES `equipment_types` (`id`),
  ADD CONSTRAINT `lube_specifications_ibfk_2` FOREIGN KEY (`lube_type_id`) REFERENCES `lube_types` (`id`);

ALTER TABLE `maintenance_plan_jobs`
  ADD CONSTRAINT `fk_mpj_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE;

ALTER TABLE `material_transactions`
  ADD CONSTRAINT `material_transactions_ibfk_1` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `material_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

ALTER TABLE `pm_completions`
  ADD CONSTRAINT `pm_completions_ibfk_1` FOREIGN KEY (`pm_schedule_id`) REFERENCES `pm_schedules` (`id`),
  ADD CONSTRAINT `pm_completions_ibfk_2` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`);

ALTER TABLE `pm_part_requirements`
  ADD CONSTRAINT `fk_pm_part_schedule` FOREIGN KEY (`pm_schedule_id`) REFERENCES `pm_schedules` (`id`) ON DELETE CASCADE;

ALTER TABLE `pm_schedules`
  ADD CONSTRAINT `pm_schedules_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`);

ALTER TABLE `shift_assignments`
  ADD CONSTRAINT `shift_assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `shift_assignments_ibfk_2` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`);

ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

ALTER TABLE `work_orders`
  ADD CONSTRAINT `fk_wo_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `work_orders_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  ADD CONSTRAINT `work_orders_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

ALTER TABLE `wo_attachments`
  ADD CONSTRAINT `fk_wo_attachments_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_wo_attachments_work_order` FOREIGN KEY (`wo_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `wo_oil_records`
  ADD CONSTRAINT `wo_oil_records_ibfk_1` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`);

ALTER TABLE `wo_sessions`
  ADD CONSTRAINT `fk_session_switched_to` FOREIGN KEY (`switched_to_wo_id`) REFERENCES `work_orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_session_wo` FOREIGN KEY (`wo_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `wo_spare_parts`
  ADD CONSTRAINT `wo_spare_parts_ibfk_1` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`);

ALTER TABLE `wo_status_history`
  ADD CONSTRAINT `wo_status_history_ibfk_1` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`);

ALTER TABLE `spare_orders` ADD CONSTRAINT `fk_so_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

ALTER TABLE `spare_orders` ADD CONSTRAINT `fk_so_linked_wo` FOREIGN KEY (`linked_wo_id`) REFERENCES `work_orders` (`id`) ON DELETE SET NULL;

ALTER TABLE `spare_orders` ADD CONSTRAINT `fk_so_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `spare_order_items` ADD CONSTRAINT `fk_soi_order` FOREIGN KEY (`order_id`) REFERENCES `spare_orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `spare_order_items` ADD CONSTRAINT `fk_soi_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL;

ALTER TABLE `order_documents` ADD CONSTRAINT `fk_od_order` FOREIGN KEY (`order_id`) REFERENCES `spare_orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `order_status_history` ADD CONSTRAINT `fk_osh_order` FOREIGN KEY (`order_id`) REFERENCES `spare_orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `goods_receipts` ADD CONSTRAINT `fk_grn_order` FOREIGN KEY (`order_id`) REFERENCES `spare_orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `goods_receipts` ADD CONSTRAINT `fk_grn_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `goods_receipt_lines` ADD CONSTRAINT `fk_grl_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE;

ALTER TABLE `goods_receipt_lines` ADD CONSTRAINT `fk_grl_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `spare_order_items` (`id`) ON DELETE CASCADE;

ALTER TABLE `goods_receipt_lines` ADD CONSTRAINT `fk_grl_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL;

ALTER TABLE `goods_receipt_lines` ADD CONSTRAINT `fk_grl_movement` FOREIGN KEY (`movement_id`) REFERENCES `material_transactions` (`id`) ON DELETE SET NULL;

ALTER TABLE `supplier_contacts` ADD CONSTRAINT `fk_sc_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

ALTER TABLE `supplier_materials` ADD CONSTRAINT `fk_sm_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

ALTER TABLE `supplier_materials` ADD CONSTRAINT `fk_sm_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL;

ALTER TABLE `wo_spare_allocations` ADD CONSTRAINT `fk_wsa_wo_part` FOREIGN KEY (`wo_spare_part_id`) REFERENCES `wo_spare_parts` (`id`) ON DELETE CASCADE;

ALTER TABLE `wo_spare_allocations` ADD CONSTRAINT `fk_wsa_wo` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `wo_spare_allocations` ADD CONSTRAINT `fk_wsa_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`);

ALTER TABLE `wo_spare_allocations` ADD CONSTRAINT `fk_wsa_allocated_by` FOREIGN KEY (`allocated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `spare_part_alerts` ADD CONSTRAINT `fk_spa_wo_part` FOREIGN KEY (`wo_spare_part_id`) REFERENCES `wo_spare_parts` (`id`) ON DELETE SET NULL;

ALTER TABLE `spare_part_alerts` ADD CONSTRAINT `fk_spa_wo` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `spare_part_alerts` ADD CONSTRAINT `fk_spa_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL;

ALTER TABLE `spare_part_alerts` ADD CONSTRAINT `fk_spa_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `spare_part_alerts` ADD CONSTRAINT `fk_spa_read_by` FOREIGN KEY (`read_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `equipment_meter_readings` ADD CONSTRAINT `fk_emr_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE;

ALTER TABLE `equipment_meter_readings` ADD CONSTRAINT `fk_emr_work_order` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE SET NULL;

ALTER TABLE `equipment_meter_readings` ADD CONSTRAINT `fk_emr_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `equipment_kpi_targets` ADD CONSTRAINT `fk_ekt_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE;

ALTER TABLE `equipment_kpi_targets` ADD CONSTRAINT `fk_ekt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `equipment_planned_downtime` ADD CONSTRAINT `fk_epd_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `forecast_snapshots` ADD CONSTRAINT `fk_fs_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `weekly_report_snapshots` ADD CONSTRAINT `fk_wrs_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `weekly_report_names` ADD CONSTRAINT `fk_wrn_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `maintenance_plan_jobs` ADD CONSTRAINT `fk_mpj_pm_schedule` FOREIGN KEY (`pm_schedule_id`) REFERENCES `pm_schedules` (`id`) ON DELETE SET NULL;

ALTER TABLE `maintenance_plan_jobs` ADD CONSTRAINT `fk_mpj_work_order` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE SET NULL;

ALTER TABLE `maintenance_plan_jobs` ADD CONSTRAINT `fk_mpj_backlog` FOREIGN KEY (`backlog_id`) REFERENCES `backlogs` (`id`) ON DELETE SET NULL;

ALTER TABLE `maintenance_plan_jobs` ADD CONSTRAINT `fk_mpj_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `maintenance_plan_jobs` ADD CONSTRAINT `fk_mpj_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `pm_part_requirements` ADD CONSTRAINT `fk_pmpr_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE;

ALTER TABLE `materials` ADD CONSTRAINT `fk_mat_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL;

ALTER TABLE `material_transactions` ADD CONSTRAINT `fk_mt_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE SET NULL;

ALTER TABLE `material_transactions` ADD CONSTRAINT `fk_mt_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL;

ALTER TABLE `inspection_records` ADD CONSTRAINT `fk_ir_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE SET NULL;

ALTER TABLE `inspection_records` ADD CONSTRAINT `fk_ir_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `backlogs` ADD CONSTRAINT `fk_backlog_wo` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE SET NULL;

ALTER TABLE `backlogs` ADD CONSTRAINT `fk_backlog_insp_item_fk` FOREIGN KEY (`inspection_item_id`) REFERENCES `inspection_items` (`id`) ON DELETE SET NULL;

ALTER TABLE `backlogs` ADD CONSTRAINT `fk_backlog_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `wo_sessions` ADD CONSTRAINT `fk_session_technician` FOREIGN KEY (`technician_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `wo_sessions` ADD CONSTRAINT `fk_session_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `work_orders` ADD CONSTRAINT `fk_wo_completed_by` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `work_orders` ADD CONSTRAINT `fk_wo_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `work_orders` ADD CONSTRAINT `fk_wo_operator` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `asset_import_logs` ADD CONSTRAINT `fk_ail_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `inventory_import_logs` ADD CONSTRAINT `fk_iil_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

INSERT INTO `equipment_types` (`id`, `name`, `category`, `description`) VALUES
(1, 'Heavy Truck', 'Transport', 'On-road and off-road heavy transport vehicles'),
(2, 'Excavator', 'Earth Moving', 'Hydraulic tracked excavators'),
(3, 'Wheel Loader', 'Loading', 'Front-loading rubber-tyre loaders'),
(4, 'Bulldozer', 'Earth Moving', 'Track-type dozing and pushing machines'),
(5, 'Crane', 'Lifting', 'Mobile and overhead lifting equipment'),
(6, 'Compressor', 'Utilities', 'Air and gas compression equipment'),
(7, 'Generator', 'Utilities', 'Diesel power generation sets'),
(8, 'Forklift', 'Material Handling', 'Warehouse and yard material handling'),
(9, 'Service Truck', 'Support', 'Field maintenance and support vehicles'),
(10, 'Light Vehicle', 'Transport', 'Pickup trucks and utility vehicles'),
(11, 'ADT', 'Hauling', 'Articulated Dump Trucks'),
(12, 'Dozer', 'Earth Moving', 'Track-type bulldozers'),
(13, 'Grader', 'Road Works', 'Motor graders for road maintenance'),
(14, 'Bowser', 'Support', 'Water and fuel bowser trucks'),
(15, 'Rig', 'Mining', 'Drill rigs'),
(28, 'Concrete Mixer', 'Support', 'Imported from the workshop asset list'),
(29, 'Bus', 'Transport', 'Imported from the workshop asset list'),
(30, 'Attachment', 'Support', 'Imported from the workshop asset list'),
(31, 'Water Pump', 'Utilities', 'Imported from the workshop asset list'),
(32, 'Tower Light', 'Utilities', 'Imported from the workshop asset list'),
(33, 'Welding Machine', 'Utilities', 'Imported from the workshop asset list');

INSERT INTO `lube_types` (`id`, `name`, `category`, `grade`, `unit`, `unit_cost`, `description`, `status`) VALUES
(1, 'Engine Oil', 'Engine Oil', '15W-40', 'L', '520.00', NULL, 'Active'),
(2, 'Hydraulic Oil', 'Hydraulic Oil', 'ISO 46', 'L', '680.00', NULL, 'Active'),
(3, 'Gear Oil', 'Differential Oil', '80W-90', 'L', '590.00', NULL, 'Active'),
(4, 'Grease', 'Grease', 'NLGI 2', 'Kg', '1100.00', NULL, 'Active'),
(5, 'Coolant', 'Coolant', '50/50', 'L', '340.00', NULL, 'Active'),
(6, 'Transmission Oil', 'Transmission Oil', 'ATF Dex', 'L', '750.00', NULL, 'Active'),
(7, 'Compressor Oil', 'Compressor Oil', 'ISO 100', 'L', '820.00', NULL, 'Active');

INSERT INTO `report_groups` (`id`, `code`, `label`, `sort_order`, `active`) VALUES
(1, 'EXCAVATOR', 'EXCAVATORs', 10, 1),
(2, 'DOZER', 'DOZERs', 20, 1),
(3, 'ADT', 'ADTs', 30, 1),
(4, 'GRADER', 'GRADERs', 40, 1),
(5, 'BOWSER_DB', 'BOWSERs DB', 50, 1),
(6, 'BOWSER_WB', 'BOWSERs WB', 60, 1),
(7, 'RIG', 'RIG', 70, 1),
(8, 'CRANE', 'CRANE', 80, 1),
(9, 'FORKLIFT', 'FORKLIFT', 90, 1),
(10, 'GENERATOR', 'GENERATOR', 100, 1),
(11, 'TOWER_LIGHT', 'TOWER LIGHT', 110, 1),
(12, 'BUS', 'BUS', 120, 1),
(13, 'LV', 'LV', 130, 1),
(14, 'WATER_PUMP', 'WATER PUMP', 140, 1),
(15, 'COMPRESSOR', 'COMPRESSOR', 150, 1),
(16, 'WELDING_PLANT', 'WELDING PLANT', 160, 1),
(17, 'SERVICE_VAN', 'SERVICE VAN', 170, 1);

INSERT INTO `report_sites` (`id`, `code`, `label`, `sort_order`, `is_active`, `created_at`) VALUES
(1, 'WORKSHOP', 'WORKSHOP', 10, 1, '2026-10-19 07:22:49'),
(2, 'MET_PLANT', 'MET PLANT', 20, 1, '2026-10-19 07:22:49'),
(3, 'KALIMVA', 'KALIMVA', 30, 1, '2026-10-19 07:22:49'),
(4, 'TTB_PAMAO', 'TTB PAMAO', 40, 1, '2026-10-19 07:22:49'),
(5, 'RD', 'RD', 50, 1, '2026-10-19 07:22:49'),
(6, 'DOKO', 'DOKO', 60, 1, '2026-10-19 07:22:49'),
(7, 'IKAMVA', 'IKAMVA', 70, 1, '2026-10-19 07:22:49'),
(8, 'BANDAYI', 'BANDAYI', 80, 1, '2026-10-19 07:22:49'),
(9, 'CEO', 'CEO', 90, 1, '2026-10-19 07:22:49'),
(10, 'STORE', 'STORE', 100, 1, '2026-10-19 07:22:49'),
(11, 'PAKAKA', 'PAKAKA', 110, 1, '2026-10-19 07:22:49'),
(12, 'AMS_WORKSHOP', 'AMS WORKSHOP', 120, 1, '2026-10-19 07:22:49'),
(13, 'RIG 001', 'RIG 001', 130, 1, '2026-10-19 07:22:49'),
(14, 'RIG 002', 'RIG 002', 140, 1, '2026-10-19 07:22:49'),
(15, 'RIG 003', 'RIG 003', 150, 1, '2026-10-19 07:22:49'),
(16, 'SECURITY', 'SECURITY', 160, 1, '2026-10-19 07:22:49'),
(17, 'DRILLING', 'DRILLING', 170, 1, '2026-10-19 07:22:49'),
(18, 'DRILLING (Operation)', 'DRILLING (Operation)', 180, 1, '2026-10-19 07:22:49'),
(19, 'FINANCE', 'FINANCE', 190, 1, '2026-10-19 07:22:49'),
(20, 'FLEET MANAGER', 'FLEET MANAGER', 200, 1, '2026-10-19 07:22:49'),
(21, 'FUEL & SECURITY MANAGER', 'FUEL & SECURITY MANAGER', 210, 1, '2026-10-19 07:22:49'),
(22, 'GENERAL MANAGER', 'GENERAL MANAGER', 220, 1, '2026-10-19 07:22:49'),
(23, 'LOGISTIC', 'LOGISTIC', 230, 1, '2026-10-19 07:22:49'),
(24, 'LOGISTICS MANAGER', 'LOGISTICS MANAGER', 240, 1, '2026-10-19 07:22:49'),
(25, 'MAINTENANCE (Operation)', 'MAINTENANCE (Operation)', 250, 1, '2026-10-19 07:22:49'),
(26, 'MINING', 'MINING', 260, 1, '2026-10-19 07:22:49'),
(27, 'NDALA', 'NDALA', 270, 1, '2026-10-19 07:22:49'),
(28, 'PROCUREMENT MANAGER', 'PROCUREMENT MANAGER', 280, 1, '2026-10-19 07:22:49'),
(29, 'PRODUCTION (Operation)', 'PRODUCTION (Operation)', 290, 1, '2026-10-19 07:22:49'),
(30, 'PRODUCTION MANAGER', 'PRODUCTION MANAGER', 300, 1, '2026-10-19 07:22:49'),
(31, 'SAFETY MANAGER', 'SAFETY MANAGER', 310, 1, '2026-10-19 07:22:49'),
(32, 'SUPER ATTENDANT', 'SUPER ATTENDANT', 320, 1, '2026-10-19 07:22:49'),
(33, 'SUPPLY CHAIN', 'SUPPLY CHAIN', 330, 1, '2026-10-19 07:22:49'),
(34, 'WORKSHOP MANAGER', 'WORKSHOP MANAGER', 340, 1, '2026-10-19 07:22:49'),
(35, 'XCMG TEAM', 'XCMG TEAM', 350, 1, '2026-10-19 07:22:49');

INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'Supervisor', 'Full access — all modules, user management, settings'),
(2, 'Technician', 'Work orders, attendance, own assignments'),
(3, 'Store', 'Lubricants, spare parts, inventory management'),
(4, 'Manager', 'View-only: dashboard, reports and KPIs'),
(5, 'CEO', 'Executive view — high-level KPIs and strategic reports only');

INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('company_name', 'A&ML', 'Company / Department name'),
('working_hours_per_day', '10', 'Standard working hours per day'),
('work_days_per_month', '26', 'Working days per month'),
('currency', 'USD', 'Site currency: code used for spend totals and as the default in currency pickers'),
('pm_alert_days', '7', 'Days before PM due to show alert'),
('cert_alert_days', '30', 'Days before a certification expiry at which it is flagged Expiring Soon'),
('weekly_open_hours', '168', 'Open hours in a 7-day reporting week'),
('daily_open_hours', '24', 'Open hours per day'),
('default_availability_target', '0.88', 'Fallback availability target when neither the equipment nor the group has one'),
('report_week_start_day', '1', '1 = Monday … 7 = Sunday'),
('breakdown_count_mode', 'start_in_period', 'start_in_period = count breakdown events that start inside the week - overlapping = count every event overlapping the week'),
('mtbf_zero_failure_mode', 'dash', 'dash = show - for MTBF/MTTR when a machine had no breakdown in the period - uptime = show uptime as MTBF'),
('pm_meter_stale_days', '30', 'G4-stale: age limit in days for meter-only PM judgements (0 = off)'),
('login_ip_cap_enabled', '1', 'F-19: 1 = limit failed sign-ins from one source across many accounts (credential stuffing) - 0 = off'),
('standing_allowance_hours', '{}', 'D31: planned standing downtime per day as JSON keyed by equipment-type name'),
('standing_allowance_default_hours', '2', 'D31: fallback standing downtime per day (hours)'),
('inspection_default_hours', '1', 'MP-3: hours one weekly internal inspection takes across machine types'),
('inspection_hours_by_type', '{}', 'MP-3: optional per-type weekly inspection duration override as JSON'),
('pm_utilisation_lookback_days', '28', 'MP-4: lookback window in days for meter hours-per-day calculation'),
('pm_default_hours_per_day', '{}', 'MP-4: fallback hours per day per equipment type as JSON'),
('unplanned_allowance_mode', 'flat', 'flat = fixed daily breakdown allowance - derived = computed from corrective history'),
('unplanned_allowance_default_hours', '1', 'flat mode: planned breakdown allowance per day (hours)'),
('unplanned_allowance_lookback_weeks', '12', 'derived mode: lookback history in weeks'),
('forecast_show_excel_reference', '1', 'D29/D30: 1 = show historical Excel reference columns alongside approved KPI figures - 0 = hide'),
('leave_entitlements', '{"Annual Leave":21,"Sick Leave":10,"Emergency":3}', 'Annual leave days per leave type as JSON');

INSERT INTO `shifts` (`id`, `name`, `start_time`, `end_time`, `description`) VALUES
(1, 'Day Shift', '06:00:00', '18:00:00', '12-hour day rotation'),
(2, 'Night Shift', '18:00:00', '06:00:00', '12-hour night rotation'),
(3, 'Office', '08:00:00', '17:00:00', 'Standard office hours');

INSERT IGNORE INTO `inspection_templates` (`id`, `code`, `name_en`, `name_fr`, `category_en`, `category_fr`, `layout_type`, `columns_json`, `meter_label_en`, `meter_label_fr`, `active`, `created_at`) VALUES
(1, 'heavy_equipment', 'Heavy Equipment Permitting Checklist', 'Liste de contrôle d’autorisation des engins lourds', 'General Checklists', 'Listes de contrôle générales', 'matrix', '[{\"key\": \"H\", \"label_en\": \"Excavator\", \"label_fr\": \"Excavatrice\"}, {\"key\": \"I\", \"label_en\": \"Dump Truck/ ADT\", \"label_fr\": \"Camion-benne/ ADT\"}, {\"key\": \"J\", \"label_en\": \"Grader\", \"label_fr\": \"Niveleuse\"}, {\"key\": \"K\", \"label_en\": \"Wheel-Loader/ Dozer\", \"label_fr\": \"Chargeuse sur pneus/ Bulldozer\"}, {\"key\": \"L\", \"label_en\": \"Track Dozer\", \"label_fr\": \"Bulldozer sur chenilles\"}, {\"key\": \"M\", \"label_en\": \"Drill Rigg\", \"label_fr\": \"Foreuse\"}, {\"key\": \"N\", \"label_en\": \"Compactor\", \"label_fr\": \"Compacteur\"}, {\"key\": \"O\", \"label_en\": \"Skid Steer\", \"label_fr\": \"Chargeuse compacte\"}]', 'SMR', 'SMR', 1, '2026-08-10 11:56:53'),
(2, 'lv_bus', 'Light Vehicle & Bus Permitting Checklist', 'Liste de contrôle pour l’autorisation des véhicules légers et des bus', 'General Checklists', 'Listes de contrôle générales', 'compliance', NULL, 'SMR', 'SMR', 1, '2026-08-10 11:56:53'),
(3, 'heavy_vehicle', 'Heavy Vehicle Permitting Checklist', 'Liste de contrôle d’autorisation des véhicules lourds', 'General Checklists', 'Listes de contrôle générales', 'compliance', NULL, 'Km', 'Km', 1, '2026-08-10 11:56:53'),
(4, 'excavator', 'Excavator Inspection Form', 'Formulaire d\'inspection Excavatrice', 'Heavy Equipment', 'Engins lourds', 'compliance', NULL, 'SMR', 'SMR', 1, '2026-10-13 09:00:00'),
(5, 'adt', 'ADT Inspection Form', 'Formulaire d\'inspection ADT', 'Heavy Equipment', 'Engins lourds', 'compliance', NULL, 'SMR', 'SMR', 1, '2026-10-13 09:00:00'),
(6, 'grader', 'Grader Inspection Form', 'Formulaire d\'inspection Niveleuse', 'Heavy Equipment', 'Engins lourds', 'compliance', NULL, 'SMR', 'SMR', 1, '2026-10-13 09:00:00'),
(7, 'dozer', 'Bulldozer Inspection Form', 'Formulaire d\'inspection Bulldozer', 'Heavy Equipment', 'Engins lourds', 'compliance', NULL, 'SMR', 'SMR', 1, '2026-10-13 09:00:00'),
(8, 'drill_rigg', 'Drill Rigg Inspection Form', 'Formulaire d\'inspection Foreuse', 'Heavy Equipment', 'Engins lourds', 'compliance', NULL, 'SMR', 'SMR', 1, '2026-10-13 09:00:00'),
(9, 'bus', 'Bus Inspection Form', 'Formulaire d\'inspection Bus', 'Transport & Light Vehicles', 'Transport & Véhicules légers', 'compliance', NULL, 'SMR', 'SMR', 1, '2026-10-13 09:00:00'),
(10, 'hiab', 'HIAB truck Inspection Form', 'Formulaire d\'inspection Camion HIAB', 'Service & Utility Trucks', 'Camions de service & utilitaires', 'compliance', NULL, 'SMR', 'SMR', 1, '2026-10-13 09:00:00'),
(11, 'light_vehicle', 'Light Vehicle Inspection Form', 'Formulaire d\'inspection Véhicule léger', 'Transport & Light Vehicles', 'Transport & Véhicules légers', 'compliance', NULL, 'SMR', 'SMR', 1, '2026-10-13 09:00:00'),
(12, 'bowser', 'Fuel & Water truck Inspection Form', 'Formulaire d\'inspection Camion-citerne carburant et eau', 'Service & Utility Trucks', 'Camions de service & utilitaires', 'compliance', NULL, 'SMR', 'SMR', 1, '2026-10-13 09:00:00'),
(13, 'service_van', 'Service Van Inspection Form', 'Formulaire d\'inspection du fourgon de service', 'Service & Utility Trucks', 'Camions de service & utilitaires', 'compliance', NULL, 'SMR', 'SMR (heures)', 1, '2026-10-13 09:00:00'),
(14, 'forklift', 'Forklift Inspection Form', 'Formulaire d\'inspection du chariot élévateur', 'Heavy Equipment', 'Engins lourds', 'compliance', NULL, 'SMR', 'SMR (heures)', 1, '2026-10-13 09:00:00');

INSERT IGNORE INTO `inspection_items` (`id`, `template_id`, `sort_order`, `row_type`, `item_no`, `category_en`, `category_fr`, `text_en`, `text_fr`, `na_columns`) VALUES
(1, 1, 1, 'section', NULL, NULL, NULL, 'Safety', 'Sécurité', NULL),
(2, 1, 2, 'item', 1, 'No-Go', 'Arrêt', 'Ensure a Machine Specific Pre-Check Book is Available', 'S\'assurer qu\'un registre de pré-inspection spécifique à la machine est disponible', ''),
(3, 1, 3, 'item', 2, 'No-Go', 'Arrêt', 'Two 9Kg DCP Fire Extinguisher Must be Present, if no AFFF is fitted (Opposite sides)', 'Deux extincteurs DCP de 9 kg doivent être présents si aucun AFFF n\'est installé (côtés opposés)', ''),
(4, 1, 4, 'item', 3, 'Go & Fix', 'Continuer & Corriger', 'Ensure reflective strips are fitted (Front-White, Side-Yellow & Rear -Red)', 'S\'assurer que des bandes réfléchissantes sont installées (avant-blanc, côtés-jaune et arrière-rouge)', ''),
(5, 1, 5, 'item', 4, 'Go & Fix', 'Continuer & Corriger', 'Ensure AML Logo is Fitted to Two Sides of The Equipment', 'S\'assurer que le logo AML est installé sur deux côtés de l’équipement', ''),
(6, 1, 6, 'item', 5, 'Go & Fix', 'Continuer & Corriger', 'Ensure Asset Number Is Displayed With Reflective Sticker', 'S\'assurer que le numéro d’actif est affiché avec un autocollant réfléchissant', ''),
(7, 1, 7, 'item', 6, 'Go & Fix', 'Continuer & Corriger', 'Ensure High Visible Flag Pole is Fitted (where Applicable)', 'S\'assurer qu’un mât avec drapeau haute visibilité est installé (si applicable)', ''),
(8, 1, 8, 'item', 7, 'Go & Fix', 'Continuer & Corriger', 'Dash Camera Fitted', 'Caméra embarquée installée', ''),
(9, 1, 9, 'section', NULL, NULL, NULL, 'Cabine', 'Cabine', NULL),
(10, 1, 10, 'item', 8, 'No-Go', 'Arrêt', 'Ensure The Operators Cab is in a Good Condition', 'S\'assurer que la cabine de l’opérateur est en bon état', ''),
(11, 1, 11, 'item', 9, 'No-Go', 'Arrêt', 'Ensure all Seat Belts are Present and in a Good Working Condition', 'S\'assurer que toutes les ceintures de sécurité sont présentes et en bon état', ''),
(12, 1, 12, 'item', 10, 'No-Go', 'Arrêt', 'Ensure All Windows is in a Good Condition and No Tinting is Allowed', 'S\'assurer que toutes les vitres sont en bon état et qu’aucun film teinté n’est autorisé', ''),
(13, 1, 13, 'item', 11, 'Go & Fix', 'Continuer & Corriger', 'The Wipers Must be in a Working Condition', 'Les essuie-glaces doivent être fonctionnels', ''),
(14, 1, 14, 'item', 12, 'Go & Fix', 'Continuer & Corriger', 'Air Conditioning Must Be Fonctional', 'La climatisation doit être fonctionnelle', ''),
(15, 1, 15, 'section', NULL, NULL, NULL, 'Electrical', 'Électrique', NULL),
(16, 1, 16, 'item', 13, 'No-Go', 'Arrêt', 'All lights on The Machine Must Be Operational', 'Toutes les lumières de la machine doivent être opérationnelles', ''),
(17, 1, 17, 'item', 14, 'No-Go', 'Arrêt', 'Ensure Reverse alarm is Working', 'S\'assurer que l’alarme de recul fonctionne', ''),
(18, 1, 18, 'item', 15, 'No-Go', 'Arrêt', 'Ensure rotating light is Working', 'S\'assurer que le gyrophare fonctionne', ''),
(19, 1, 19, 'item', 16, 'No-Go', 'Arrêt', 'Ensure The Horn is Working', 'S\'assurer que le klaxon fonctionne', ''),
(20, 1, 20, 'item', 17, 'Go & Fix', 'Continuer & Corriger', 'Ensure There are no Fault Codes on the Dashboard', 'S\'assurer qu’il n’y a aucun code défaut sur le tableau de bord', ''),
(21, 1, 21, 'item', 18, 'No-Go', 'Arrêt', 'Ensure Isolation Lockout lock is Functional and Lockable (Zero Energy When Applied)', 'S\'assurer que le verrou d’isolement est fonctionnel et verrouillable (énergie zéro lorsqu’il est appliqué)', ''),
(22, 1, 22, 'item', 19, 'No-Go', 'Arrêt', 'Ensure The Emergency Stop Button is Working (Cutting the Engine)', 'S\'assurer que le bouton d’arrêt d’urgence fonctionne (arrêt moteur)', ''),
(23, 1, 23, 'item', 20, 'No-Go', 'Arrêt', 'Ensure the Bin up Alarm is Working (Automatically)', 'S\'assurer que l’alarme de benne levée fonctionne (automatiquement)', 'H,J,K,L,M,N,O'),
(24, 1, 24, 'item', 21, 'Go & Fix', 'Continuer & Corriger', 'Ensure Two Way Radio is Functional (Where Applicable)', 'S\'assurer que la radio bidirectionnelle est fonctionnelle (si applicable)', ''),
(25, 1, 25, 'item', 22, 'No-Go', 'Arrêt', 'Ensure Travel Alarm is working Forward and Reverse', 'S\'assurer que l’alarme de déplacement fonctionne en marche avant et arrière', 'I,J,K,L,N,O'),
(26, 1, 26, 'section', NULL, NULL, NULL, 'Steering', 'Direction', NULL),
(27, 1, 27, 'item', 23, 'No-Go', 'Arrêt', 'Ensure the Steering System is Operational and in Good Order', 'S\'assurer que le système de direction est opérationnel et en bon état', ''),
(28, 1, 28, 'item', 24, 'No-Go', 'Arrêt', 'Inspect all ball joints, Steering Links and Steering Column for Excessive Wear', 'Inspecter toutes les rotules, biellettes et colonne de direction pour détecter une usure excessive', ''),
(29, 1, 29, 'item', 25, 'No-Go', 'Arrêt', 'Ensure the Slew System is Working and no Excessive Play on the Slew Bearing', 'S\'assurer que le système de rotation fonctionne et qu’il n’y a pas de jeu excessif sur la couronne d’orientation', 'I,J,K,L,M,N,O'),
(30, 1, 30, 'section', NULL, NULL, NULL, 'Brake', 'Frein', NULL),
(31, 1, 31, 'item', 26, 'No-Go', 'Arrêt', 'Ensure the Service and Park Brake are Operational', 'S\'assurer que les freins de service et de stationnement sont opérationnels', 'H'),
(32, 1, 32, 'item', 27, 'No-Go', 'Arrêt', 'Ensure the Travel And Swing Brake are Operational', 'S\'assurer que les freins de déplacement et de rotation sont opérationnels', 'I,J,K,L,M,N,O'),
(33, 1, 33, 'section', NULL, NULL, NULL, 'Tire / Track', 'Pneus / Chenilles', NULL),
(34, 1, 34, 'item', 28, 'No-Go', 'Arrêt', 'All Tires on The Machine Must be In Good Condition', 'Tous les pneus de la machine doivent être en bon état', 'H,L,M'),
(35, 1, 35, 'item', 29, 'No-Go', 'Arrêt', 'All Wheel Nuts and Studs Must be Present', 'Tous les écrous et goujons de roue doivent être présents', 'H,L,M'),
(36, 1, 36, 'item', 30, 'Go & Fix', 'Continuer & Corriger', 'Ensure Tracks on the Machine is in Good Condition and no Shoes or Bolts are Missing', 'S\'assurer que les chenilles sont en bon état et qu’aucun patin ou boulon ne manque', 'I,J,K,N,O'),
(37, 1, 37, 'item', 31, 'Go & Fix', 'Continuer & Corriger', 'Ensure all Rollers, Idles and Sprockets are in a Good Condition', 'S\'assurer que tous les galets, roues folles et pignons sont en bon état', 'I,J,K,N,O'),
(38, 1, 38, 'section', NULL, NULL, NULL, 'Structural', 'Structurel', NULL),
(39, 1, 39, 'item', 32, 'Go & Fix', 'Continuer & Corriger', 'Ensure Push Blade, Bucket or Bin is in a Good Working Condition', 'S\'assurer que la lame, le godet ou la benne sont en bon état de fonctionnement', 'M,N'),
(40, 1, 40, 'item', 33, 'No-Go', 'Arrêt', 'Ensure There are no Visible Damage or Structural Cracks', 'S\'assurer qu’il n’y a aucun dommage visible ou fissure structurelle', ''),
(41, 1, 41, 'item', 34, 'No-Go', 'Arrêt', 'Ensure The Tower/ Mast is in a Good Working Condition, No Excessive wear and Damage', 'S\'assurer que la tour / le mât est en bon état, sans usure excessive ni dommage', 'H,I,J,K,L,N,O'),
(42, 1, 42, 'item', 35, 'No-Go', 'Arrêt', 'Ensure all Guarding is Fitted Around Moving Components', 'S\'assurer que toutes les protections sont installées autour des composants mobiles', ''),
(43, 1, 43, 'section', NULL, NULL, NULL, 'General', 'Général', NULL),
(44, 1, 44, 'item', 36, 'No-Go', 'Arrêt', 'Ensure There no Visible Oil, Fuel or Water Leaks Present', 'S\'assurer qu’il n’y a aucune fuite visible d’huile, carburant ou eau', ''),
(45, 1, 45, 'item', 37, 'No-Go', 'Arrêt', 'No Modifications are Allowed to the Machine', 'Aucune modification n’est autorisée sur la machine', ''),
(46, 1, 46, 'item', 38, 'Go & Fix', 'Continuer & Corriger', 'Ensure The Equipment is Clean, Well Maintained and Safe for Use', 'S\'assurer que l’équipement est propre, bien entretenu et sûr à utiliser', ''),
(47, 2, 1, 'section', NULL, NULL, NULL, 'General checks', 'Vérifications générales', NULL),
(48, 2, 2, 'item', 1, 'No-Go', 'Non conforme', 'All front and rear working', 'Toutes les lumières avant et arrière fonctionnent', NULL),
(49, 2, 3, 'item', 2, 'No-Go', 'Non conforme', 'Brake Light Working', 'Feu de frein fonctionne', NULL),
(50, 2, 4, 'item', 3, 'No-Go', 'Non conforme', 'Indicator Light Working', 'Feu clignotant fonctionne', NULL),
(51, 2, 5, 'item', 4, 'No-Go', 'Non conforme', 'Vehicule Has Functional rotating flashing beacon', 'Le véhicule est équipé d’un gyrophare fonctionnel', NULL),
(52, 2, 6, 'item', 5, 'No-Go', 'Non conforme', 'Vehicule Has Audible Reverse Alarm', 'Le véhicule est équipé d’une alarme de recul sonore', NULL),
(53, 2, 7, 'item', 6, 'No-Go', 'Non conforme', 'Fixed Two Way Radio Fitted (Where Applicable)', 'Radio bidirectionnelle fixe installée (le cas échéant)', NULL),
(54, 2, 8, 'item', 7, 'No-Go', 'Non conforme', 'Inspected and appropriately charged Fire Extinguisher Available', 'Extincteur inspecté et correctement chargé disponible', NULL),
(55, 2, 9, 'item', 8, 'No-Go', 'Non conforme', 'Vehicle Rims (two piece/split type not allowed) (N/A for Busses)', 'Jantes du véhicule (type deux pièces / démontables non autorisé) (S/O pour les bus)', NULL),
(56, 2, 10, 'item', 9, 'No-Go', 'Non conforme', 'Tyres have more than 2mm tread depth, no exposed wires or worn-out threads', 'Les pneus ont une profondeur de sculpture supérieure à 2 mm, sans fils apparents ni sculptures usées', NULL),
(57, 2, 11, 'item', 10, 'No-Go', 'Non conforme', 'All Wheel nut in place torqued tp OEM Specification', 'Tous les écrous de roue en place et serrés au couple selon les spécifications constructeur (OEM)', NULL),
(58, 2, 12, 'item', 11, 'Go & Fix', 'Conforme & À réparer', 'Side Mirors Visible & Free From Cracks', 'Rétroviseurs latéraux visibles et exempts de fissures', NULL),
(59, 2, 13, 'item', 12, 'Go & Fix', 'Conforme & À réparer', 'Registration & insuarance Documentation Valid', 'Documents d’immatriculation et d’assurance valides', NULL),
(60, 2, 14, 'item', 13, 'Go & Fix', 'Conforme & À réparer', 'First Aid Kit Available (Where Applicable)', 'Trousse de premiers secours disponible (le cas échéant)', NULL),
(61, 2, 15, 'item', 14, 'Go & Fix', 'Conforme & À réparer', 'Vehicle Has Reflective Tape On All Four Sides', 'Le véhicule est équipé de bandes réfléchissantes sur les quatre côtés', NULL),
(62, 2, 16, 'item', 15, 'Go & Fix', 'Conforme & À réparer', 'Vehicle Has Legible Fleet Numbers On Four sides', 'Le véhicule a des numéros de flotte lisibles sur les quatre côtés', NULL),
(63, 2, 17, 'item', 16, 'Go & Fix', 'Conforme & À réparer', 'Vehicle Has An Appropriate High-Visibility Flag (Where Application)', 'Le véhicule est équipé d’un drapeau haute visibilité approprié (le cas échéant)', NULL),
(64, 2, 18, 'item', 17, 'Go & Fix', 'Conforme & À réparer', 'Windscreen Free From Cracks which can Obstruct Clear Visibility', 'Pare-brise exempt de fissures pouvant gêner la visibilité', NULL),
(65, 2, 19, 'item', 18, 'Go & Fix', 'Conforme & À réparer', 'Whipers In Good Condition & Fully Functional', 'Essuie-glaces en bon état et pleinement fonctionnels', NULL),
(66, 2, 20, 'item', 19, 'No-Go', 'Non conforme', 'Vehicle Has An Appropriate Pre-Start Checklist Conforming With the Standards', 'Le véhicule dispose d’une liste de contrôle pré-démarrage appropriée conforme aux normes', NULL),
(67, 2, 21, 'item', 20, 'Go & Fix', 'Conforme & À réparer', 'Inspect Towbar Bolts (High Tensile) And Structure (Where Aplicable)', 'Inspecter les boulons de l’attelage (haute résistance) et la structure (le cas échéant)', NULL),
(68, 2, 22, 'section', NULL, NULL, NULL, 'In Cab and Interior Of Vehicle', 'À l’intérieur de la cabine et du véhicule', NULL),
(69, 2, 23, 'item', 21, 'No-Go', 'Non conforme', 'Seatbelt Functional and Secure On Each Seat of the Vehicle', 'La ceinture de sécurité fonctionne et est bien fixée à chaque siège du véhicule', NULL),
(70, 2, 24, 'item', 22, 'No-Go', 'Non conforme', 'Horn Functional', 'Klaxon fonctionnel', NULL),
(71, 2, 25, 'item', 23, 'No-Go', 'Non conforme', 'Door Latches Functional', 'Loquets de porte fonctionnels', NULL),
(72, 2, 26, 'item', 24, 'Go & Fix', 'Conforme & À réparer', 'Dashboard, Gauges and Indicators Display Functioning', 'Tableau de bord, jauges et voyants fonctionnels', NULL),
(73, 2, 27, 'item', 25, 'Go & Fix', 'Conforme & À réparer', 'Air conditioner Functional', 'Climatisation fonctionnelle', NULL),
(74, 2, 28, 'item', 26, 'Go & Fix', 'Conforme & À réparer', 'Vehicule Has Functional in-cab Camera (Front & Cab Camera) Monitoring System', 'Le véhicule est équipé d’un système de caméras embarquées fonctionnel (caméra avant et caméra cabine)', NULL),
(75, 2, 29, 'section', NULL, NULL, NULL, 'Braking System', 'Système de freinage', NULL),
(76, 2, 30, 'item', 27, 'No-Go', 'Non conforme', 'Vehicle Passes Service & Park Brake Test', 'Le véhicule réussit le test du frein de service et du frein de stationnement', NULL),
(77, 2, 31, 'item', 28, 'No-Go', 'Non conforme', 'Perform Dynamic Brake Efficiency Test - Within Specs', 'Effectuer le test d’efficacité du frein dynamique – conforme aux spécifications', NULL),
(78, 2, 32, 'item', 29, 'No-Go', 'Non conforme', 'Accumulators Free From Leakage', 'Accumulateurs exempts de fuites', NULL),
(79, 2, 33, 'section', NULL, NULL, NULL, 'Steering & Suspension', 'Direction et suspension', NULL),
(80, 2, 34, 'item', 30, 'No-Go', 'Non conforme', 'Steering Mechanism In Good Condition And Fully Functional', 'Mécanisme de direction en bon état et pleinement fonctionnel', NULL),
(81, 2, 35, 'item', 31, 'Go & Fix', 'Conforme & À réparer', 'Inspect Steering Arms, Ball Joints And Steering Column For Free Play', 'Inspecter les bras de direction, les rotules et la colonne de direction pour tout jeu', NULL),
(82, 2, 36, 'section', NULL, NULL, NULL, 'Hydraulic & Pneumatic System', 'Système hydraulique et pneumatique', NULL),
(83, 2, 37, 'item', 32, 'Go & Fix', 'Conforme & À réparer', 'Pumps/ Compressors/ Valves Free From Leakages', 'Pompes / compresseurs / valves exempts de fuites', NULL),
(84, 2, 38, 'item', 33, 'Go & Fix', 'Conforme & À réparer', 'Hose Clamps in place', 'Colliers de serrage des tuyaux en place', NULL),
(85, 2, 39, 'section', NULL, NULL, NULL, 'Engine/ Battery Compartments', 'Compartiments moteur / batterie', NULL),
(86, 2, 40, 'item', 34, 'Go & Fix', 'Conforme & À réparer', 'Covers/ Bonnet Has Appropriate Means To Be Secured In An Open And Closed Position', 'Le capot / les caches disposent d’un système approprié pour être sécurisés en position ouverte et fermée', NULL),
(87, 2, 41, 'item', 35, 'Go & Fix', 'Conforme & À réparer', 'Battery & Battery Terminals Secure', 'Batterie et bornes de batterie sécurisées', NULL),
(88, 2, 42, 'item', 36, 'Go & Fix', 'Conforme & À réparer', 'No Exposed Electrical Cables', 'Aucun câble électrique apparent', NULL),
(89, 3, 1, 'section', NULL, NULL, NULL, 'General checks', 'Vérifications générales', NULL),
(90, 3, 2, 'item', 1, 'No-Go', 'Non conforme', 'All front and rear working', 'Toutes les lumières avant et arrière fonctionnent', NULL),
(91, 3, 3, 'item', 2, 'No-Go', 'Non conforme', 'Brake Light Working', 'Feu de frein fonctionne', NULL),
(92, 3, 4, 'item', 3, 'No-Go', 'Non conforme', 'Indicator Light Working', 'Feu clignotant fonctionne', NULL),
(93, 3, 5, 'item', 4, 'No-Go', 'Non conforme', 'Vehicule Has Functional rotating flashing beacon', 'Le véhicule est équipé d’un gyrophare fonctionnel', NULL),
(94, 3, 6, 'item', 5, 'No-Go', 'Non conforme', 'Vehicule Has Audible Reverse Alarm', 'Le véhicule est équipé d’une alarme de recul sonore', NULL),
(95, 3, 7, 'item', 6, 'No-Go', 'Non conforme', 'Fixed Two Way Radio Fitted (Where Applicable)', 'Radio bidirectionnelle fixe installée (le cas échéant)', NULL),
(96, 3, 8, 'item', 7, 'No-Go', 'Non conforme', 'Inspected and appropriately charged Fire Extinguisher Available', 'Extincteur inspecté et correctement chargé disponible', NULL),
(97, 3, 9, 'item', 8, 'No-Go', 'Non conforme', 'Vehicle Rims (two piece/split type not allowed) (N/A for Busses)', 'Jantes du véhicule (type deux pièces / démontables non autorisé) (S/O pour les bus)', NULL),
(98, 3, 10, 'item', 9, 'No-Go', 'Non conforme', 'Tyres have more than 2mm tread depth, no exposed wires or worn-out threads', 'Les pneus ont une profondeur de sculpture supérieure à 2 mm, sans fils apparents ni sculptures usées', NULL),
(99, 3, 11, 'item', 10, 'No-Go', 'Non conforme', 'All Wheel nut in place torqued tp OEM Specification', 'Tous les écrous de roue en place et serrés au couple selon les spécifications constructeur (OEM)', NULL),
(100, 3, 12, 'item', 11, 'Go & Fix', 'Conforme & À réparer', 'Side Mirors Visible & Free From Cracks', 'Rétroviseurs latéraux visibles et exempts de fissures', NULL),
(101, 3, 13, 'item', 12, 'Go & Fix', 'Conforme & À réparer', 'Registration & insuarance Documentation Valid', 'Documents d’immatriculation et d’assurance valides', NULL),
(102, 3, 14, 'item', 13, 'Go & Fix', 'Conforme & À réparer', 'First Aid Kit Available (Where Applicable)', 'Trousse de premiers secours disponible (le cas échéant)', NULL),
(103, 3, 15, 'item', 14, 'Go & Fix', 'Conforme & À réparer', 'Vehicle Has Reflective Tape On All Four Sides', 'Le véhicule est équipé de bandes réfléchissantes sur les quatre côtés', NULL),
(104, 3, 16, 'item', 15, 'Go & Fix', 'Conforme & À réparer', 'Vehicle Has Legible Fleet Numbers On Four sides', 'Le véhicule a des numéros de flotte lisibles sur les quatre côtés', NULL),
(105, 3, 17, 'item', 16, 'Go & Fix', 'Conforme & À réparer', 'Vehicle Has An Appropriate High-Visibility Flag (Where Application)', 'Le véhicule est équipé d’un drapeau haute visibilité approprié (le cas échéant)', NULL),
(106, 3, 18, 'item', 17, 'Go & Fix', 'Conforme & À réparer', 'Windscreen Free From Cracks which can Obstruct Clear Visibility', 'Pare-brise exempt de fissures pouvant gêner la visibilité', NULL),
(107, 3, 19, 'item', 18, 'Go & Fix', 'Conforme & À réparer', 'Whipers In Good Condition & Fully Functional', 'Essuie-glaces en bon état et pleinement fonctionnels', NULL),
(108, 3, 20, 'item', 19, 'No-Go', 'Non conforme', 'Vehicle Has An Appropriate Pre-Start Checklist Conforming With the Standards', 'Le véhicule dispose d’une liste de contrôle pré-démarrage appropriée conforme aux normes', NULL),
(109, 3, 21, 'item', 20, 'Go & Fix', 'Conforme & À réparer', 'Inspect Towbar Bolts (High Tensile) And Structure (Where Aplicable)', 'Inspecter les boulons de l’attelage (haute résistance) et la structure (le cas échéant)', NULL),
(110, 3, 22, 'section', NULL, NULL, NULL, 'In Cab and Interior Of Vehicle', 'À l’intérieur de la cabine et du véhicule', NULL),
(111, 3, 23, 'item', 21, 'No-Go', 'Non conforme', 'Seatbelt Functional and Secure On Each Seat of the Vehicle', 'La ceinture de sécurité fonctionne et est bien fixée à chaque siège du véhicule', NULL),
(112, 3, 24, 'item', 22, 'No-Go', 'Non conforme', 'Horn Functional', 'Klaxon fonctionnel', NULL),
(113, 3, 25, 'item', 23, 'No-Go', 'Non conforme', 'Door Latches Functional', 'Loquets de porte fonctionnels', NULL),
(114, 3, 26, 'item', 24, 'Go & Fix', 'Conforme & À réparer', 'Dashboard, Gauges and Indicators Display Functioning', 'Tableau de bord, jauges et voyants fonctionnels', NULL),
(115, 3, 27, 'item', 25, 'Go & Fix', 'Conforme & À réparer', 'Air conditioner Functional', 'Climatisation fonctionnelle', NULL),
(116, 3, 28, 'item', 26, 'Go & Fix', 'Conforme & À réparer', 'Vehicule Has Functional in-cab Camera (Front & Cab Camera) Monitoring System', 'Le véhicule est équipé d’un système de caméras embarquées fonctionnel (caméra avant et caméra cabine)', NULL),
(117, 3, 29, 'section', NULL, NULL, NULL, 'Braking System', 'Système de freinage', NULL),
(118, 3, 30, 'item', 27, 'No-Go', 'Non conforme', 'Vehicle Passes Service & Park Brake Test', 'Le véhicule réussit le test du frein de service et du frein de stationnement', NULL),
(119, 3, 31, 'item', 28, 'No-Go', 'Non conforme', 'Perform Dynamic Brake Efficiency Test - Within Specs', 'Effectuer le test d’efficacité du frein dynamique – conforme aux spécifications', NULL),
(120, 3, 32, 'item', 29, 'No-Go', 'Non conforme', 'Accumulators Free From Leakage', 'Accumulateurs exempts de fuites', NULL);

INSERT IGNORE INTO `inspection_items` (`id`, `template_id`, `sort_order`, `row_type`, `item_no`, `category_en`, `category_fr`, `text_en`, `text_fr`, `na_columns`) VALUES
(121, 3, 33, 'section', NULL, NULL, NULL, 'Steering & Suspension', 'Direction et suspension', NULL),
(122, 3, 34, 'item', 30, 'No-Go', 'Non conforme', 'Steering Mechanism In Good Condition And Fully Functional', 'Mécanisme de direction en bon état et pleinement fonctionnel', NULL),
(123, 3, 35, 'item', 31, 'Go & Fix', 'Conforme & À réparer', 'Inspect Steering Arms, Ball Joints And Steering Column For Free Play', 'Inspecter les bras de direction, les rotules et la colonne de direction pour tout jeu', NULL),
(124, 3, 36, 'section', NULL, NULL, NULL, 'Hydraulic & Pneumatic System', 'Système hydraulique et pneumatique', NULL),
(125, 3, 37, 'item', 32, 'Go & Fix', 'Conforme & À réparer', 'Pumps/ Compressors/ Valves Free From Leakages', 'Pompes / compresseurs / valves exempts de fuites', NULL),
(126, 3, 38, 'item', 33, 'Go & Fix', 'Conforme & À réparer', 'Hose Clamps in place', 'Colliers de serrage des tuyaux en place', NULL),
(127, 3, 39, 'section', NULL, NULL, NULL, 'Engine/ Battery Compartments', 'Compartiments moteur / batterie', NULL),
(128, 3, 40, 'item', 34, 'Go & Fix', 'Conforme & À réparer', 'Covers/ Bonnet Has Appropriate Means To Be Secured In An Open And Closed Position', 'Le capot / les caches disposent d’un système approprié pour être sécurisés en position ouverte et fermée', NULL),
(129, 3, 41, 'item', 35, 'Go & Fix', 'Conforme & À réparer', 'Battery & Battery Terminals Secure', 'Batterie et bornes de batterie sécurisées', NULL),
(130, 3, 42, 'item', 36, 'Go & Fix', 'Conforme & À réparer', 'No Exposed Electrical Cables', 'Aucun câble électrique apparent', NULL),
(131, 4, 1, 'section', NULL, NULL, NULL, 'Safety', 'Sécurité', NULL),
(132, 4, 2, 'item', 1, 'No-Go', 'Interdit de circuler', 'Ensure a Machine Specific Pre-Check Book is Available', 'S\'assurer qu\'un carnet de pré-contrôle spécifique à la machine est disponible', NULL),
(133, 4, 3, 'item', 2, 'No-Go', 'Interdit de circuler', 'Two 9Kg DCP Fire Extinguisher Must be Present, if no AFFF is fitted (Opposite sides)', 'Deux extincteurs DCP de 9 kg doivent être présents si aucun système AFFF n\'est installé (côtés opposés)', NULL),
(134, 4, 4, 'item', 3, 'Go & Fix', 'Autorisé avec correction', 'Ensure reflective strips are fitted (Front-White, Side-Yellow & Rear -Red)', 'S\'assurer que les bandes réfléchissantes sont installées (avant-blanc, côtés-jaune et arrière-rouge)', NULL),
(135, 4, 5, 'item', 4, 'Go & Fix', 'Autorisé avec correction', 'Ensure AML Logo is Fitted to Two Sides of The Equipment', 'S\'assurer que le logo AML est apposé sur les deux côtés de l\'équipement', NULL),
(136, 4, 6, 'item', 5, 'Go & Fix', 'Autorisé avec correction', 'Ensure Asset Number Is Displayed With Reflective Sticker', 'S\'assurer que le numéro de l\'équipement est affiché avec un autocollant réfléchissant', NULL),
(137, 4, 7, 'item', 6, 'Go & Fix', 'Autorisé avec correction', 'Ensure High Visible Flag Pole is Fitted (where Applicable)', 'S\'assurer qu\'un mât avec drapeau haute visibilité est installé (si applicable)', NULL),
(138, 4, 8, 'item', 7, 'Go & Fix', 'Autorisé avec correction', 'Dash Camera Fitted', 'Caméra de tableau de bord installée', NULL),
(139, 4, 9, 'section', NULL, NULL, NULL, 'Cabine', 'Cabine', NULL),
(140, 4, 10, 'item', 8, 'No-Go', 'Interdit de circuler', 'Ensure The Operators Cab is in a Good Condition', 'S\'assurer que la cabine de l\'opérateur est en bon état', NULL),
(141, 4, 11, 'item', 9, 'No-Go', 'Interdit de circuler', 'Ensure all Seat Belts are Present and in a Good Working Condition', 'S\'assurer que toutes les ceintures de sécurité sont présentes et en bon état de fonctionnement', NULL),
(142, 4, 12, 'item', 10, 'No-Go', 'Interdit de circuler', 'Ensure All Windows is in a Good Condition and No Tinting is Allowed', 'S\'assurer que toutes les vitres sont en bon état et qu\'aucun film teinté n\'est autorisé', NULL),
(143, 4, 13, 'item', 11, 'Go & Fix', 'Autorisé avec correction', 'The Wipers Must be in a Working Condition', 'Les essuie-glaces doivent être en état de fonctionnement', NULL),
(144, 4, 14, 'item', 12, 'Go & Fix', 'Autorisé avec correction', 'Air Conditioning Must Be Fonctional', 'La climatisation doit être fonctionnelle', NULL),
(145, 4, 15, 'section', NULL, NULL, NULL, 'Electrical', 'Électricité', NULL),
(146, 4, 16, 'item', 13, 'No-Go', 'Interdit de circuler', 'All lights on The Machine Must Be Operational', 'Tous les feux de la machine doivent être opérationnels', NULL),
(147, 4, 17, 'item', 14, 'No-Go', 'Interdit de circuler', 'Ensure rotating light is Working', 'S\'assurer que le gyrophare fonctionne', NULL),
(148, 4, 18, 'item', 15, 'No-Go', 'Interdit de circuler', 'Ensure The Horn is Working', 'S\'assurer que le klaxon fonctionne', NULL),
(149, 4, 19, 'item', 16, 'Go & Fix', 'Autorisé avec correction', 'Ensure There are no Fault Codes on the Dashboard', 'S\'assurer qu\'aucun code défaut n\'est affiché sur le tableau de bord', NULL),
(150, 4, 20, 'item', 17, 'No-Go', 'Interdit de circuler', 'Ensure Isolation Lockout lock is Functional and Lockable (Zero Energy When Applied)', 'S\'assurer que le dispositif de verrouillage d\'isolement est fonctionnel et verrouillable (énergie nulle lorsqu\'il est appliqué)', NULL),
(151, 4, 21, 'item', 18, 'No-Go', 'Interdit de circuler', 'Ensure The Emergency Stop Button is Working (Cutting the Engine)', 'S\'assurer que le bouton d\'arrêt d\'urgence fonctionne (arrêt du moteur)', NULL),
(152, 4, 22, 'item', 19, 'Go & Fix', 'Autorisé avec correction', 'Ensure Two Way Radio is Functional (Where Applicable)', 'S\'assurer que la radio bidirectionnelle est fonctionnelle (si applicable)', NULL),
(153, 4, 23, 'item', 20, 'No-Go', 'Interdit de circuler', 'Ensure Travel Alarm is working Forward and Reverse', 'S\'assurer que l\'alarme de déplacement fonctionne en marche avant et en marche arrière', NULL),
(154, 4, 24, 'section', NULL, NULL, NULL, 'Steering', 'Direction', NULL),
(155, 4, 25, 'item', 21, 'No-Go', 'Interdit de circuler', 'Ensure the Steering System is Operational and in Good Order', 'S\'assurer que le système de direction est opérationnel et en bon état', NULL),
(156, 4, 26, 'item', 22, 'No-Go', 'Interdit de circuler', 'Inspect all ball joints, Steering Links and Steering Column for Excessive Wear', 'Inspecter toutes les rotules, les biellettes de direction et la colonne de direction pour détecter toute usure excessive', NULL),
(157, 4, 27, 'item', 23, 'No-Go', 'Interdit de circuler', 'Ensure the Slew System is Working and no Excessive Play on the Slew Bearing', 'S\'assurer que le système d\'orientation fonctionne et qu\'il n\'y a pas de jeu excessif au niveau du roulement d\'orientation', NULL),
(158, 4, 28, 'section', NULL, NULL, NULL, 'Brake', 'Freins', NULL),
(159, 4, 29, 'item', 24, 'No-Go', 'Interdit de circuler', 'Ensure the Travel And Swing Brake are Operational', 'S\'assurer que les freins de déplacement et d\'orientation sont opérationnels', NULL),
(160, 4, 30, 'section', NULL, NULL, NULL, 'Track', 'Chenilles', NULL),
(161, 4, 31, 'item', 25, 'Go & Fix', 'Autorisé avec correction', 'Ensure Tracks on the Machine is in Good Condition and no Shoes or Bolts are Missing', 'S\'assurer que les chenilles de la machine sont en bon état et qu\'aucune plaque ou aucun boulon ne manque', NULL),
(162, 4, 32, 'item', 26, 'Go & Fix', 'Autorisé avec correction', 'Ensure all Rollers, Idles and Sprockets are in a Good Condition', 'S\'assurer que tous les galets, roues folles et barbotins sont en bon état', NULL),
(163, 4, 33, 'section', NULL, NULL, NULL, 'Structural', 'Structure', NULL),
(164, 4, 34, 'item', 27, 'Go & Fix', 'Autorisé avec correction', 'Ensure Push Blade, Bucket or Bin is in a Good Working Condition', 'S\'assurer que la lame, le godet ou la benne est en bon état de fonctionnement', NULL),
(165, 4, 35, 'item', 28, 'No-Go', 'Interdit de circuler', 'Ensure There are no Visible Damage or Structural Cracks', 'S\'assurer qu\'il n\'y a aucun dommage visible ni fissure structurelle', NULL),
(166, 4, 36, 'item', 29, 'No-Go', 'Interdit de circuler', 'Ensure all Guarding is Fitted Around Moving Components', 'S\'assurer que toutes les protections sont installées autour des composants en mouvement', NULL),
(167, 4, 37, 'section', NULL, NULL, NULL, 'General', 'Général', NULL),
(168, 4, 38, 'item', 30, 'No-Go', 'Interdit de circuler', 'Ensure There no Visible Oil, Fuel or Water Leaks Present', 'S\'assurer qu\'il n\'y a aucune fuite visible d\'huile, de carburant ou d\'eau', NULL),
(169, 4, 39, 'item', 31, 'No-Go', 'Interdit de circuler', 'No Modifications are Allowed to the Machine', 'Aucune modification n\'est autorisée sur la machine', NULL),
(170, 4, 40, 'item', 32, 'Go & Fix', 'Autorisé avec correction', 'Ensure The Equipment is Clean, Well Maintained and Safe for Use', 'S\'assurer que l\'équipement est propre, bien entretenu et sûr à utiliser', NULL),
(171, 5, 1, 'section', NULL, NULL, NULL, 'Safety', 'Sécurité', NULL),
(172, 5, 2, 'item', 1, 'No-Go', 'Interdit de circuler', 'Ensure a Machine Specific Pre-Check Book is Available', 'S\'assurer qu\'un carnet de pré-contrôle spécifique à la machine est disponible', NULL),
(173, 5, 3, 'item', 2, 'No-Go', 'Interdit de circuler', 'Two 9Kg DCP Fire Extinguisher Must be Present, if no AFFF is fitted (Opposite sides)', 'Deux extincteurs DCP de 9 kg doivent être présents si aucun système AFFF n\'est installé (côtés opposés)', NULL),
(174, 5, 4, 'item', 3, 'Go & Fix', 'Autorisé avec correction', 'Ensure reflective strips are fitted (Front-White, Side-Yellow & Rear -Red)', 'S\'assurer que les bandes réfléchissantes sont installées (avant-blanc, côtés-jaune et arrière-rouge)', NULL),
(175, 5, 5, 'item', 4, 'Go & Fix', 'Autorisé avec correction', 'Ensure AML Logo is Fitted to Two Sides of The Equipment', 'S\'assurer que le logo AML est apposé sur les deux côtés de l\'équipement', NULL),
(176, 5, 6, 'item', 5, 'Go & Fix', 'Autorisé avec correction', 'Ensure Asset Number Is Displayed With Reflective Sticker', 'S\'assurer que le numéro de l\'équipement est affiché avec un autocollant réfléchissant', NULL),
(177, 5, 7, 'item', 6, 'Go & Fix', 'Autorisé avec correction', 'Ensure High Visible Flag Pole is Fitted (where Applicable)', 'S\'assurer qu\'un mât avec drapeau haute visibilité est installé (si applicable)', NULL),
(178, 5, 8, 'item', 7, 'Go & Fix', 'Autorisé avec correction', 'Dash Camera Fitted', 'Caméra de tableau de bord installée', NULL),
(179, 5, 9, 'section', NULL, NULL, NULL, 'Cabine', 'Cabine', NULL),
(180, 5, 10, 'item', 8, 'No-Go', 'Interdit de circuler', 'Ensure The Operators Cab is in a Good Condition', 'S\'assurer que la cabine de l\'opérateur est en bon état', NULL),
(181, 5, 11, 'item', 9, 'No-Go', 'Interdit de circuler', 'Ensure all Seat Belts are Present and in a Good Working Condition', 'S\'assurer que toutes les ceintures de sécurité sont présentes et en bon état de fonctionnement', NULL),
(182, 5, 12, 'item', 10, 'No-Go', 'Interdit de circuler', 'Ensure All Windows is in a Good Condition and No Tinting is Allowed', 'S\'assurer que toutes les vitres sont en bon état et qu\'aucun film teinté n\'est autorisé', NULL),
(183, 5, 13, 'item', 11, 'Go & Fix', 'Autorisé avec correction', 'The Wipers Must be in a Working Condition', 'Les essuie-glaces doivent être en état de fonctionnement', NULL),
(184, 5, 14, 'item', 12, 'Go & Fix', 'Autorisé avec correction', 'Air Conditioning Must Be Fonctional', 'La climatisation doit être fonctionnelle', NULL),
(185, 5, 15, 'section', NULL, NULL, NULL, 'Electrical', 'Électricité', NULL),
(186, 5, 16, 'item', 13, 'No-Go', 'Interdit de circuler', 'All lights on The Machine Must Be Operational', 'Tous les feux de la machine doivent être opérationnels', NULL),
(187, 5, 17, 'item', 14, 'No-Go', 'Interdit de circuler', 'Ensure Reverse alarm is Working', 'S\'assurer que l\'alarme de marche arrière fonctionne', NULL),
(188, 5, 18, 'item', 15, 'No-Go', 'Interdit de circuler', 'Ensure rotating light is Working', 'S\'assurer que le gyrophare fonctionne', NULL),
(189, 5, 19, 'item', 16, 'No-Go', 'Interdit de circuler', 'Ensure The Horn is Working', 'S\'assurer que le klaxon fonctionne', NULL),
(190, 5, 20, 'item', 17, 'Go & Fix', 'Autorisé avec correction', 'Ensure There are no Fault Codes on the Dashboard', 'S\'assurer qu\'aucun code défaut n\'est affiché sur le tableau de bord', NULL),
(191, 5, 21, 'item', 18, 'No-Go', 'Interdit de circuler', 'Ensure Isolation Lockout lock is Functional and Lockable (Zero Energy When Applied)', 'S\'assurer que le dispositif de verrouillage d\'isolement est fonctionnel et verrouillable (énergie nulle lorsqu\'il est appliqué)', NULL),
(192, 5, 22, 'item', 19, 'No-Go', 'Interdit de circuler', 'Ensure The Emergency Stop Button is Working (Cutting the Engine)', 'S\'assurer que le bouton d\'arrêt d\'urgence fonctionne (arrêt du moteur)', NULL),
(193, 5, 23, 'item', 20, 'No-Go', 'Interdit de circuler', 'Ensure the Bin up Alarm is Working (Automatically)', 'S\'assurer que l\'alarme de benne levée fonctionne automatiquement', NULL),
(194, 5, 24, 'item', 21, 'Go & Fix', 'Autorisé avec correction', 'Ensure Two Way Radio is Functional (Where Applicable)', 'S\'assurer que la radio bidirectionnelle est fonctionnelle (si applicable)', NULL),
(195, 5, 25, 'section', NULL, NULL, NULL, 'Steering', 'Direction', NULL),
(196, 5, 26, 'item', 22, 'No-Go', 'Interdit de circuler', 'Ensure the Steering System is Operational and in Good Order', 'S\'assurer que le système de direction est opérationnel et en bon état', NULL),
(197, 5, 27, 'item', 23, 'No-Go', 'Interdit de circuler', 'Inspect all ball joints, Steering Links and Steering Column for Excessive Wear', 'Inspecter toutes les rotules, les biellettes de direction et la colonne de direction pour détecter toute usure excessive', NULL),
(198, 5, 28, 'section', NULL, NULL, NULL, 'Brake', 'Freins', NULL),
(199, 5, 29, 'item', 24, 'No-Go', 'Interdit de circuler', 'Ensure the Service and Park Brake are Operational', 'S\'assurer que le frein de service et le frein de stationnement sont opérationnels', NULL),
(200, 5, 30, 'section', NULL, NULL, NULL, 'Tires', 'Pneus', NULL),
(201, 5, 31, 'item', 25, 'No-Go', 'Interdit de circuler', 'All Tires on The Machine Must be In Good Condition', 'Tous les pneus de la machine doivent être en bon état', NULL),
(202, 5, 32, 'item', 26, 'No-Go', 'Interdit de circuler', 'All Wheel Nuts and Studs Must be Present', 'Tous les écrous et goujons de roue doivent être présents', NULL),
(203, 5, 33, 'section', NULL, NULL, NULL, 'Structural', 'Structure', NULL),
(204, 5, 34, 'item', 27, 'Go & Fix', 'Autorisé avec correction', 'Ensure Push Blade, Bucket or Bin is in a Good Working Condition', 'S\'assurer que la lame, le godet ou la benne est en bon état de fonctionnement', NULL),
(205, 5, 35, 'item', 28, 'No-Go', 'Interdit de circuler', 'Ensure There are no Visible Damage or Structural Cracks', 'S\'assurer qu\'il n\'y a aucun dommage visible ni fissure structurelle', NULL),
(206, 5, 36, 'item', 29, 'No-Go', 'Interdit de circuler', 'Ensure all Guarding is Fitted Around Moving Components', 'S\'assurer que toutes les protections sont installées autour des composants en mouvement', NULL),
(207, 5, 37, 'section', NULL, NULL, NULL, 'General', 'Général', NULL),
(208, 5, 38, 'item', 30, 'No-Go', 'Interdit de circuler', 'Ensure There no Visible Oil, Fuel or Water Leaks Present', 'S\'assurer qu\'il n\'y a aucune fuite visible d\'huile, de carburant ou d\'eau', NULL),
(209, 5, 39, 'item', 31, 'No-Go', 'Interdit de circuler', 'No Modifications are Allowed to the Machine', 'Aucune modification n\'est autorisée sur la machine', NULL),
(210, 5, 40, 'item', 32, 'Go & Fix', 'Autorisé avec correction', 'Ensure The Equipment is Clean, Well Maintained and Safe for Use', 'S\'assurer que l\'équipement est propre, bien entretenu et sûr à utiliser', NULL),
(211, 6, 1, 'section', NULL, NULL, NULL, 'Safety', 'Sécurité', NULL),
(212, 6, 2, 'item', 1, 'No-Go', 'Interdit de circuler', 'Ensure a Machine Specific Pre-Check Book is Available', 'S\'assurer qu\'un carnet de pré-contrôle spécifique à la machine est disponible', NULL),
(213, 6, 3, 'item', 2, 'No-Go', 'Interdit de circuler', 'Two 9Kg DCP Fire Extinguisher Must be Present, if no AFFF is fitted (Opposite sides)', 'Deux extincteurs DCP de 9 kg doivent être présents si aucun système AFFF n\'est installé (côtés opposés)', NULL),
(214, 6, 4, 'item', 3, 'Go & Fix', 'Autorisé avec correction', 'Ensure reflective strips are fitted (Front-White, Side-Yellow & Rear -Red)', 'S\'assurer que les bandes réfléchissantes sont installées (avant-blanc, côtés-jaune et arrière-rouge)', NULL),
(215, 6, 5, 'item', 4, 'Go & Fix', 'Autorisé avec correction', 'Ensure AML Logo is Fitted to Two Sides of The Equipment', 'S\'assurer que le logo AML est apposé sur les deux côtés de l\'équipement', NULL),
(216, 6, 6, 'item', 5, 'Go & Fix', 'Autorisé avec correction', 'Ensure Asset Number Is Displayed With Reflective Sticker', 'S\'assurer que le numéro de l\'équipement est affiché avec un autocollant réfléchissant', NULL),
(217, 6, 7, 'item', 6, 'Go & Fix', 'Autorisé avec correction', 'Ensure High Visible Flag Pole is Fitted (where Applicable)', 'S\'assurer qu\'un mât avec drapeau haute visibilité est installé (si applicable)', NULL),
(218, 6, 8, 'item', 7, 'Go & Fix', 'Autorisé avec correction', 'Dash Camera Fitted', 'Caméra de tableau de bord installée', NULL),
(219, 6, 9, 'section', NULL, NULL, NULL, 'Cabine', 'Cabine', NULL),
(220, 6, 10, 'item', 8, 'No-Go', 'Interdit de circuler', 'Ensure The Operators Cab is in a Good Condition', 'S\'assurer que la cabine de l\'opérateur est en bon état', NULL),
(221, 6, 11, 'item', 9, 'No-Go', 'Interdit de circuler', 'Ensure all Seat Belts are Present and in a Good Working Condition', 'S\'assurer que toutes les ceintures de sécurité sont présentes et en bon état de fonctionnement', NULL),
(222, 6, 12, 'item', 10, 'No-Go', 'Interdit de circuler', 'Ensure All Windows is in a Good Condition and No Tinting is Allowed', 'S\'assurer que toutes les vitres sont en bon état et qu\'aucun film teinté n\'est autorisé', NULL),
(223, 6, 13, 'item', 11, 'Go & Fix', 'Autorisé avec correction', 'The Wipers Must be in a Working Condition', 'Les essuie-glaces doivent être en état de fonctionnement', NULL),
(224, 6, 14, 'item', 12, 'Go & Fix', 'Autorisé avec correction', 'Air Conditioning Must Be Fonctional', 'La climatisation doit être fonctionnelle', NULL),
(225, 6, 15, 'section', NULL, NULL, NULL, 'Electrical', 'Électricité', NULL),
(226, 6, 16, 'item', 13, 'No-Go', 'Interdit de circuler', 'All lights on The Machine Must Be Operational', 'Tous les feux de la machine doivent être opérationnels', NULL),
(227, 6, 17, 'item', 14, 'No-Go', 'Interdit de circuler', 'Ensure Reverse alarm is Working', 'S\'assurer que l\'alarme de marche arrière fonctionne', NULL),
(228, 6, 18, 'item', 15, 'No-Go', 'Interdit de circuler', 'Ensure rotating light is Working', 'S\'assurer que le gyrophare fonctionne', NULL),
(229, 6, 19, 'item', 16, 'No-Go', 'Interdit de circuler', 'Ensure The Horn is Working', 'S\'assurer que le klaxon fonctionne', NULL),
(230, 6, 20, 'item', 17, 'Go & Fix', 'Autorisé avec correction', 'Ensure There are no Fault Codes on the Dashboard', 'S\'assurer qu\'aucun code défaut n\'est affiché sur le tableau de bord', NULL),
(231, 6, 21, 'item', 18, 'No-Go', 'Interdit de circuler', 'Ensure Isolation Lockout lock is Functional and Lockable (Zero Energy When Applied)', 'S\'assurer que le dispositif de verrouillage d\'isolement est fonctionnel et verrouillable (énergie nulle lorsqu\'il est appliqué)', NULL),
(232, 6, 22, 'item', 19, 'No-Go', 'Interdit de circuler', 'Ensure The Emergency Stop Button is Working (Cutting the Engine)', 'S\'assurer que le bouton d\'arrêt d\'urgence fonctionne (arrêt du moteur)', NULL),
(233, 6, 23, 'item', 20, 'Go & Fix', 'Autorisé avec correction', 'Ensure Two Way Radio is Functional (Where Applicable)', 'S\'assurer que la radio bidirectionnelle est fonctionnelle (si applicable)', NULL);

INSERT IGNORE INTO `inspection_items` (`id`, `template_id`, `sort_order`, `row_type`, `item_no`, `category_en`, `category_fr`, `text_en`, `text_fr`, `na_columns`) VALUES
(234, 6, 24, 'section', NULL, NULL, NULL, 'Steering', 'Direction', NULL),
(235, 6, 25, 'item', 21, 'No-Go', 'Interdit de circuler', 'Ensure the Steering System is Operational and in Good Order', 'S\'assurer que le système de direction est opérationnel et en bon état', NULL),
(236, 6, 26, 'item', 22, 'No-Go', 'Interdit de circuler', 'Inspect all ball joints, Steering Links and Steering Column for Excessive Wear', 'Inspecter toutes les rotules, les biellettes de direction et la colonne de direction pour détecter toute usure excessive', NULL),
(237, 6, 27, 'section', NULL, NULL, NULL, 'Brake', 'Freins', NULL),
(238, 6, 28, 'item', 23, 'No-Go', 'Interdit de circuler', 'Ensure the Service and Park Brake are Operational', 'S\'assurer que le frein de service et le frein de stationnement sont opérationnels', NULL),
(239, 6, 29, 'section', NULL, NULL, NULL, 'Tires', 'Pneus', NULL),
(240, 6, 30, 'item', 24, 'No-Go', 'Interdit de circuler', 'All Tires on The Machine Must be In Good Condition', 'Tous les pneus de la machine doivent être en bon état', NULL),
(241, 6, 31, 'item', 25, 'No-Go', 'Interdit de circuler', 'All Wheel Nuts and Studs Must be Present', 'Tous les écrous et goujons de roue doivent être présents', NULL),
(242, 6, 32, 'section', NULL, NULL, NULL, 'Structural', 'Structure', NULL),
(243, 6, 33, 'item', 26, 'Go & Fix', 'Autorisé avec correction', 'Ensure Push Blade, Bucket or Bin is in a Good Working Condition', 'S\'assurer que la lame, le godet ou la benne est en bon état de fonctionnement', NULL),
(244, 6, 34, 'item', 27, 'No-Go', 'Interdit de circuler', 'Ensure There are no Visible Damage or Structural Cracks', 'S\'assurer qu\'il n\'y a aucun dommage visible ni fissure structurelle', NULL),
(245, 6, 35, 'item', 28, 'No-Go', 'Interdit de circuler', 'Ensure all Guarding is Fitted Around Moving Components', 'S\'assurer que toutes les protections sont installées autour des composants en mouvement', NULL),
(246, 6, 36, 'section', NULL, NULL, NULL, 'General', 'Général', NULL),
(247, 6, 37, 'item', 29, 'No-Go', 'Interdit de circuler', 'Ensure There no Visible Oil, Fuel or Water Leaks Present', 'S\'assurer qu\'il n\'y a aucune fuite visible d\'huile, de carburant ou d\'eau', NULL),
(248, 6, 38, 'item', 30, 'No-Go', 'Interdit de circuler', 'No Modifications are Allowed to the Machine', 'Aucune modification n\'est autorisée sur la machine', NULL),
(249, 6, 39, 'item', 31, 'Go & Fix', 'Autorisé avec correction', 'Ensure The Equipment is Clean, Well Maintained and Safe for Use', 'S\'assurer que l\'équipement est propre, bien entretenu et sûr à utiliser', NULL),
(250, 7, 1, 'section', NULL, NULL, NULL, 'Safety', 'Sécurité', NULL),
(251, 7, 2, 'item', 1, 'No-Go', 'Interdit de circuler', 'Ensure a Machine Specific Pre-Check Book is Available', 'S\'assurer qu\'un carnet de pré-contrôle spécifique à la machine est disponible', NULL),
(252, 7, 3, 'item', 2, 'No-Go', 'Interdit de circuler', 'Two 9Kg DCP Fire Extinguisher Must be Present, if no AFFF is fitted (Opposite sides)', 'Deux extincteurs DCP de 9 kg doivent être présents si aucun système AFFF n\'est installé (côtés opposés)', NULL),
(253, 7, 4, 'item', 3, 'Go & Fix', 'Autorisé avec correction', 'Ensure reflective strips are fitted (Front-White, Side-Yellow & Rear -Red)', 'S\'assurer que les bandes réfléchissantes sont installées (avant-blanc, côtés-jaune et arrière-rouge)', NULL),
(254, 7, 5, 'item', 4, 'Go & Fix', 'Autorisé avec correction', 'Ensure AML Logo is Fitted to Two Sides of The Equipment', 'S\'assurer que le logo AML est apposé sur les deux côtés de l\'équipement', NULL),
(255, 7, 6, 'item', 5, 'Go & Fix', 'Autorisé avec correction', 'Ensure Asset Number Is Displayed With Reflective Sticker', 'S\'assurer que le numéro de l\'équipement est affiché avec un autocollant réfléchissant', NULL),
(256, 7, 7, 'item', 6, 'Go & Fix', 'Autorisé avec correction', 'Ensure High Visible Flag Pole is Fitted (where Applicable)', 'S\'assurer qu\'un mât avec drapeau haute visibilité est installé (si applicable)', NULL),
(257, 7, 8, 'item', 7, 'Go & Fix', 'Autorisé avec correction', 'Dash Camera Fitted', 'Caméra de tableau de bord installée', NULL),
(258, 7, 9, 'section', NULL, NULL, NULL, 'Cabine', 'Cabine', NULL),
(259, 7, 10, 'item', 8, 'No-Go', 'Interdit de circuler', 'Ensure The Operators Cab is in a Good Condition', 'S\'assurer que la cabine de l\'opérateur est en bon état', NULL),
(260, 7, 11, 'item', 9, 'No-Go', 'Interdit de circuler', 'Ensure all Seat Belts are Present and in a Good Working Condition', 'S\'assurer que toutes les ceintures de sécurité sont présentes et en bon état de fonctionnement', NULL),
(261, 7, 12, 'item', 10, 'No-Go', 'Interdit de circuler', 'Ensure All Windows is in a Good Condition and No Tinting is Allowed', 'S\'assurer que toutes les vitres sont en bon état et qu\'aucun film teinté n\'est autorisé', NULL),
(262, 7, 13, 'item', 11, 'Go & Fix', 'Autorisé avec correction', 'The Wipers Must be in a Working Condition', 'Les essuie-glaces doivent être en état de fonctionnement', NULL),
(263, 7, 14, 'item', 12, 'Go & Fix', 'Autorisé avec correction', 'Air Conditioning Must Be Fonctional', 'La climatisation doit être fonctionnelle', NULL),
(264, 7, 15, 'section', NULL, NULL, NULL, 'Electrical', 'Électricité', NULL),
(265, 7, 16, 'item', 13, 'No-Go', 'Interdit de circuler', 'All lights on The Machine Must Be Operational', 'Tous les feux de la machine doivent être opérationnels', NULL),
(266, 7, 17, 'item', 14, 'No-Go', 'Interdit de circuler', 'Ensure Reverse alarm is Working', 'S\'assurer que l\'alarme de marche arrière fonctionne', NULL),
(267, 7, 18, 'item', 15, 'No-Go', 'Interdit de circuler', 'Ensure rotating light is Working', 'S\'assurer que le gyrophare fonctionne', NULL),
(268, 7, 19, 'item', 16, 'No-Go', 'Interdit de circuler', 'Ensure The Horn is Working', 'S\'assurer que le klaxon fonctionne', NULL),
(269, 7, 20, 'item', 17, 'Go & Fix', 'Autorisé avec correction', 'Ensure There are no Fault Codes on the Dashboard', 'S\'assurer qu\'aucun code défaut n\'est affiché sur le tableau de bord', NULL),
(270, 7, 21, 'item', 18, 'No-Go', 'Interdit de circuler', 'Ensure Isolation Lockout lock is Functional and Lockable (Zero Energy When Applied)', 'S\'assurer que le dispositif de verrouillage d\'isolement est fonctionnel et verrouillable (énergie nulle lorsqu\'il est appliqué)', NULL),
(271, 7, 22, 'item', 19, 'No-Go', 'Interdit de circuler', 'Ensure The Emergency Stop Button is Working (Cutting the Engine)', 'S\'assurer que le bouton d\'arrêt d\'urgence fonctionne (arrêt du moteur)', NULL),
(272, 7, 23, 'item', 20, 'Go & Fix', 'Autorisé avec correction', 'Ensure Two Way Radio is Functional (Where Applicable)', 'S\'assurer que la radio bidirectionnelle est fonctionnelle (si applicable)', NULL),
(273, 7, 24, 'section', NULL, NULL, NULL, 'Steering', 'Direction', NULL),
(274, 7, 25, 'item', 21, 'No-Go', 'Interdit de circuler', 'Ensure the Steering System is Operational and in Good Order', 'S\'assurer que le système de direction est opérationnel et en bon état', NULL),
(275, 7, 26, 'item', 22, 'No-Go', 'Interdit de circuler', 'Inspect all ball joints, Steering Links and Steering Column for Excessive Wear', 'Inspecter toutes les rotules, les biellettes de direction et la colonne de direction pour détecter toute usure excessive', NULL),
(276, 7, 27, 'section', NULL, NULL, NULL, 'Brake', 'Freins', NULL),
(277, 7, 28, 'item', 23, 'No-Go', 'Interdit de circuler', 'Ensure the Service and Park Brake are Operational', 'S\'assurer que le frein de service et le frein de stationnement sont opérationnels', NULL),
(278, 7, 29, 'section', NULL, NULL, NULL, 'Track', 'Chenilles', NULL),
(279, 7, 30, 'item', 24, 'Go & Fix', 'Autorisé avec correction', 'Ensure Tracks on the Machine is in Good Condition and no Shoes or Bolts are Missing', 'S\'assurer que les chenilles de la machine sont en bon état et qu\'aucune plaque ou aucun boulon ne manque', NULL),
(280, 7, 31, 'item', 25, 'Go & Fix', 'Autorisé avec correction', 'Ensure all Rollers, Idles and Sprockets are in a Good Condition', 'S\'assurer que tous les galets, roues folles et barbotins sont en bon état', NULL),
(281, 7, 32, 'section', NULL, NULL, NULL, 'Structural', 'Structure', NULL),
(282, 7, 33, 'item', 26, 'Go & Fix', 'Autorisé avec correction', 'Ensure Push Blade, Bucket or Bin is in a Good Working Condition', 'S\'assurer que la lame, le godet ou la benne est en bon état de fonctionnement', NULL),
(283, 7, 34, 'item', 27, 'No-Go', 'Interdit de circuler', 'Ensure There are no Visible Damage or Structural Cracks', 'S\'assurer qu\'il n\'y a aucun dommage visible ni fissure structurelle', NULL),
(284, 7, 35, 'item', 28, 'No-Go', 'Interdit de circuler', 'Ensure all Guarding is Fitted Around Moving Components', 'S\'assurer que toutes les protections sont installées autour des composants en mouvement', NULL),
(285, 7, 36, 'section', NULL, NULL, NULL, 'General', 'Général', NULL),
(286, 7, 37, 'item', 29, 'No-Go', 'Interdit de circuler', 'Ensure There no Visible Oil, Fuel or Water Leaks Present', 'S\'assurer qu\'il n\'y a aucune fuite visible d\'huile, de carburant ou d\'eau', NULL),
(287, 7, 38, 'item', 30, 'No-Go', 'Interdit de circuler', 'No Modifications are Allowed to the Machine', 'Aucune modification n\'est autorisée sur la machine', NULL),
(288, 7, 39, 'item', 31, 'Go & Fix', 'Autorisé avec correction', 'Ensure The Equipment is Clean, Well Maintained and Safe for Use', 'S\'assurer que l\'équipement est propre, bien entretenu et sûr à utiliser', NULL),
(289, 8, 1, 'section', NULL, NULL, NULL, 'Safety', 'Sécurité', NULL),
(290, 8, 2, 'item', 1, 'No-Go', 'Interdit de circuler', 'Ensure a Machine Specific Pre-Check Book is Available', 'S\'assurer qu\'un carnet de pré-contrôle spécifique à la machine est disponible', NULL),
(291, 8, 3, 'item', 2, 'No-Go', 'Interdit de circuler', 'Two 9Kg DCP Fire Extinguisher Must be Present, if no AFFF is fitted (Opposite sides)', 'Deux extincteurs DCP de 9 kg doivent être présents si aucun système AFFF n\'est installé (côtés opposés)', NULL),
(292, 8, 4, 'item', 3, 'Go & Fix', 'Autorisé avec correction', 'Ensure reflective strips are fitted (Front-White, Side-Yellow & Rear -Red)', 'S\'assurer que les bandes réfléchissantes sont installées (avant-blanc, côtés-jaune et arrière-rouge)', NULL),
(293, 8, 5, 'item', 4, 'Go & Fix', 'Autorisé avec correction', 'Ensure AML Logo is Fitted to Two Sides of The Equipment', 'S\'assurer que le logo AML est apposé sur les deux côtés de l\'équipement', NULL),
(294, 8, 6, 'item', 5, 'Go & Fix', 'Autorisé avec correction', 'Ensure Asset Number Is Displayed With Reflective Sticker', 'S\'assurer que le numéro de l\'équipement est affiché avec un autocollant réfléchissant', NULL),
(295, 8, 7, 'item', 6, 'Go & Fix', 'Autorisé avec correction', 'Ensure High Visible Flag Pole is Fitted (where Applicable)', 'S\'assurer qu\'un mât avec drapeau haute visibilité est installé (si applicable)', NULL),
(296, 8, 8, 'item', 7, 'Go & Fix', 'Autorisé avec correction', 'Dash Camera Fitted', 'Caméra de tableau de bord installée', NULL),
(297, 8, 9, 'section', NULL, NULL, NULL, 'Cabine', 'Cabine', NULL),
(298, 8, 10, 'item', 8, 'No-Go', 'Interdit de circuler', 'Ensure The Operators Cab is in a Good Condition', 'S\'assurer que la cabine de l\'opérateur est en bon état', NULL),
(299, 8, 11, 'item', 9, 'No-Go', 'Interdit de circuler', 'Ensure all Seat Belts are Present and in a Good Working Condition', 'S\'assurer que toutes les ceintures de sécurité sont présentes et en bon état de fonctionnement', NULL),
(300, 8, 12, 'item', 10, 'No-Go', 'Interdit de circuler', 'Ensure All Windows is in a Good Condition and No Tinting is Allowed', 'S\'assurer que toutes les vitres sont en bon état et qu\'aucun film teinté n\'est autorisé', NULL),
(301, 8, 13, 'item', 11, 'Go & Fix', 'Autorisé avec correction', 'The Wipers Must be in a Working Condition', 'Les essuie-glaces doivent être en état de fonctionnement', NULL),
(302, 8, 14, 'item', 12, 'Go & Fix', 'Autorisé avec correction', 'Air Conditioning Must Be Fonctional', 'La climatisation doit être fonctionnelle', NULL),
(303, 8, 15, 'section', NULL, NULL, NULL, 'Electrical', 'Électricité', NULL),
(304, 8, 16, 'item', 13, 'No-Go', 'Interdit de circuler', 'All lights on The Machine Must Be Operational', 'Tous les feux de la machine doivent être opérationnels', NULL),
(305, 8, 17, 'item', 14, 'No-Go', 'Interdit de circuler', 'Ensure Reverse alarm is Working', 'S\'assurer que l\'alarme de marche arrière fonctionne', NULL),
(306, 8, 18, 'item', 15, 'No-Go', 'Interdit de circuler', 'Ensure rotating light is Working', 'S\'assurer que le gyrophare fonctionne', NULL),
(307, 8, 19, 'item', 16, 'No-Go', 'Interdit de circuler', 'Ensure The Horn is Working', 'S\'assurer que le klaxon fonctionne', NULL),
(308, 8, 20, 'item', 17, 'Go & Fix', 'Autorisé avec correction', 'Ensure There are no Fault Codes on the Dashboard', 'S\'assurer qu\'aucun code défaut n\'est affiché sur le tableau de bord', NULL),
(309, 8, 21, 'item', 18, 'No-Go', 'Interdit de circuler', 'Ensure Isolation Lockout lock is Functional and Lockable (Zero Energy When Applied)', 'S\'assurer que le dispositif de verrouillage d\'isolement est fonctionnel et verrouillable (énergie nulle lorsqu\'il est appliqué)', NULL),
(310, 8, 22, 'item', 19, 'No-Go', 'Interdit de circuler', 'Ensure The Emergency Stop Button is Working (Cutting the Engine)', 'S\'assurer que le bouton d\'arrêt d\'urgence fonctionne (arrêt du moteur)', NULL),
(311, 8, 23, 'item', 20, 'Go & Fix', 'Autorisé avec correction', 'Ensure Two Way Radio is Functional (Where Applicable)', 'S\'assurer que la radio bidirectionnelle est fonctionnelle (si applicable)', NULL),
(312, 8, 24, 'section', NULL, NULL, NULL, 'Steering', 'Direction', NULL),
(313, 8, 25, 'item', 21, 'No-Go', 'Interdit de circuler', 'Ensure the Steering System is Operational and in Good Order', 'S\'assurer que le système de direction est opérationnel et en bon état', NULL),
(314, 8, 26, 'item', 22, 'No-Go', 'Interdit de circuler', 'Inspect all ball joints, Steering Links and Steering Column for Excessive Wear', 'Inspecter toutes les rotules, les biellettes de direction et la colonne de direction pour détecter toute usure excessive', NULL),
(315, 8, 27, 'section', NULL, NULL, NULL, 'Brake', 'Freins', NULL),
(316, 8, 28, 'item', 23, 'No-Go', 'Interdit de circuler', 'Ensure the Service and Park Brake are Operational', 'S\'assurer que le frein de service et le frein de stationnement sont opérationnels', NULL),
(317, 8, 29, 'section', NULL, NULL, NULL, 'Track', 'Chenilles', NULL),
(318, 8, 30, 'item', 24, 'Go & Fix', 'Autorisé avec correction', 'Ensure Tracks on the Machine is in Good Condition and no Shoes or Bolts are Missing', 'S\'assurer que les chenilles de la machine sont en bon état et qu\'aucune plaque ou aucun boulon ne manque', NULL),
(319, 8, 31, 'item', 25, 'Go & Fix', 'Autorisé avec correction', 'Ensure all Rollers, Idles and Sprockets are in a Good Condition', 'S\'assurer que tous les galets, roues folles et barbotins sont en bon état', NULL),
(320, 8, 32, 'section', NULL, NULL, NULL, 'Structural', 'Structure', NULL),
(321, 8, 33, 'item', 26, 'No-Go', 'Interdit de circuler', 'Ensure There are no Visible Damage or Structural Cracks', 'S\'assurer qu\'il n\'y a aucun dommage visible ni fissure structurelle', NULL),
(322, 8, 34, 'item', 27, 'No-Go', 'Interdit de circuler', 'Ensure The Tower/ Mast is in a Good Working Condition, No Excessive wear and Damage', 'S\'assurer que la tour / le mât est en bon état de fonctionnement, sans usure excessive ni dommage', NULL),
(323, 8, 35, 'item', 28, 'No-Go', 'Interdit de circuler', 'Ensure all Guarding is Fitted Around Moving Components', 'S\'assurer que toutes les protections sont installées autour des composants en mouvement', NULL),
(324, 8, 36, 'section', NULL, NULL, NULL, 'General', 'Général', NULL),
(325, 8, 37, 'item', 29, 'No-Go', 'Interdit de circuler', 'Ensure There no Visible Oil, Fuel or Water Leaks Present', 'S\'assurer qu\'il n\'y a aucune fuite visible d\'huile, de carburant ou d\'eau', NULL),
(326, 8, 38, 'item', 30, 'No-Go', 'Interdit de circuler', 'No Modifications are Allowed to the Machine', 'Aucune modification n\'est autorisée sur la machine', NULL),
(327, 8, 39, 'item', 31, 'Go & Fix', 'Autorisé avec correction', 'Ensure The Equipment is Clean, Well Maintained and Safe for Use', 'S\'assurer que l\'équipement est propre, bien entretenu et sûr à utiliser', NULL),
(328, 9, 1, 'section', NULL, NULL, NULL, 'General checks', 'Contrôles généraux', NULL),
(329, 9, 2, 'item', 1, 'No-Go', 'Interdit de circuler', 'All front and rear working', 'Tous les feux avant et arrière fonctionnent', NULL),
(330, 9, 3, 'item', 2, 'No-Go', 'Interdit de circuler', 'Brake Light Working', 'Feu stop fonctionnel', NULL),
(331, 9, 4, 'item', 3, 'No-Go', 'Interdit de circuler', 'Indicator Light Working', 'Clignotants fonctionnels', NULL),
(332, 9, 5, 'item', 4, 'No-Go', 'Interdit de circuler', 'Vehicule Has Functional rotating flashing beacon', 'Le véhicule dispose d\'un gyrophare clignotant fonctionnel', NULL),
(333, 9, 6, 'item', 5, 'No-Go', 'Interdit de circuler', 'Vehicule Has Audible Reverse Alarm', 'Le véhicule dispose d\'une alarme sonore de marche arrière', NULL),
(334, 9, 7, 'item', 6, 'No-Go', 'Interdit de circuler', 'Fixed Two Way Radio Fitted (Where Applicable)', 'Radio bidirectionnelle fixe installée (si applicable)', NULL),
(335, 9, 8, 'item', 7, 'No-Go', 'Interdit de circuler', 'Inspected and appropriately charged Fire Extinguisher Available', 'Extincteur inspecté, disponible et correctement chargé', NULL),
(336, 9, 9, 'item', 8, 'No-Go', 'Interdit de circuler', 'Check engine, hydraulic, gearbox oil levels', 'Vérifier les niveaux d\'huile moteur, hydraulique et de boîte de vitesses', NULL),
(337, 9, 10, 'item', 9, 'No-Go', 'Interdit de circuler', 'Vehicle Rims (two piece/split type not allowed) (N/A for Busses)', 'Jantes du véhicule (type en deux parties/fendues interdit) (N/A pour les bus)', NULL),
(338, 9, 11, 'item', 10, 'No-Go', 'Interdit de circuler', 'Tyres have more than 2mm tread depth, no exposed wires or worn-out threads', 'Les pneus ont une profondeur de sculpture supérieure à 2 mm, sans fils apparents ni sculpture usée', NULL),
(339, 9, 12, 'item', 11, 'No-Go', 'Interdit de circuler', 'All Wheel nut in place torqued tp OEM Specification', 'Tous les écrous de roue sont en place et serrés au couple selon les spécifications du fabricant', NULL),
(340, 9, 13, 'item', 12, 'Go & Fix', 'Autorisé avec correction', 'Side Mirors Visible & Free From Cracks', 'Rétroviseurs latéraux visibles et exempts de fissures', NULL),
(341, 9, 14, 'item', 13, 'Go & Fix', 'Autorisé avec correction', 'Registration & insuarance Documentation Valid', 'Documents d\'immatriculation et d\'assurance valides', NULL),
(342, 9, 15, 'item', 14, 'Go & Fix', 'Autorisé avec correction', 'First Aid Kit Available (Where Applicable)', 'Trousse de premiers secours disponible (si applicable)', NULL),
(343, 9, 16, 'item', 15, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has Reflective Tape On All Four Sides', 'Le véhicule possède des bandes réfléchissantes sur les quatre côtés', NULL),
(344, 9, 17, 'item', 16, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has Legible Fleet Numbers On Four sides', 'Le véhicule possède des numéros de parc lisibles sur les quatre côtés', NULL),
(345, 9, 18, 'item', 17, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has An Appropriate High-Visibility Flag (Where Application)', 'Le véhicule possède un drapeau haute visibilité approprié (si applicable)', NULL),
(346, 9, 19, 'item', 18, 'Go & Fix', 'Autorisé avec correction', 'Windscreen Free From Cracks which can Obstruct Clear Visibility', 'Pare-brise exempt de fissures pouvant gêner la visibilité', NULL),
(347, 9, 20, 'item', 19, 'Go & Fix', 'Autorisé avec correction', 'Whipers In Good Condition & Fully Functional', 'Essuie-glaces en bon état et pleinement fonctionnels', NULL);

INSERT IGNORE INTO `inspection_items` (`id`, `template_id`, `sort_order`, `row_type`, `item_no`, `category_en`, `category_fr`, `text_en`, `text_fr`, `na_columns`) VALUES
(348, 9, 21, 'item', 20, 'No-Go', 'Interdit de circuler', 'Vehicle Has An Appropriate Pre-Start Checklist Conforming With the Standards', 'Le véhicule dispose d\'une checklist de pré-démarrage appropriée et conforme aux normes', NULL),
(349, 9, 22, 'item', 21, 'Go & Fix', 'Autorisé avec correction', 'Inspect Towbar Bolts (High Tensile) And Structure (Where Aplicable)', 'Inspecter les boulons de la barre d\'attelage (haute résistance) et la structure (si applicable)', NULL),
(350, 9, 23, 'section', NULL, NULL, NULL, 'In Cab and Interior Of Vehicle', 'Cabine et intérieur du véhicule', NULL),
(351, 9, 24, 'item', 22, 'No-Go', 'Interdit de circuler', 'Seatbelt Functional and Secure On Each Seat of the Vehicle', 'Ceinture de sécurité fonctionnelle et correctement fixée sur chaque siège du véhicule', NULL),
(352, 9, 25, 'item', 23, 'No-Go', 'Interdit de circuler', 'Horn Functional', 'Klaxon fonctionnel', NULL),
(353, 9, 26, 'item', 24, 'No-Go', 'Interdit de circuler', 'Door Latches Functional', 'Serrures de portes fonctionnelles', NULL),
(354, 9, 27, 'item', 25, 'Go & Fix', 'Autorisé avec correction', 'Dashboard, Gauges and Indicators Display Functioning', 'Tableau de bord, jauges et voyants fonctionnels', NULL),
(355, 9, 28, 'item', 26, 'Go & Fix', 'Autorisé avec correction', 'Air conditioner Functional', 'Climatisation fonctionnelle', NULL),
(356, 9, 29, 'item', 27, 'Go & Fix', 'Autorisé avec correction', 'Vehicule Has Functional in-cab Camera (Front & Cab Camera) Monitoring System', 'Le véhicule dispose d\'un système de surveillance par caméra fonctionnel dans la cabine (caméra avant et cabine)', NULL),
(357, 9, 30, 'section', NULL, NULL, NULL, 'Braking System', 'Système de freinage', NULL),
(358, 9, 31, 'item', 28, 'No-Go', 'Interdit de circuler', 'Vehicle Passes Service & Park Brake Test', 'Le véhicule réussit le test du frein de service et du frein de stationnement', NULL),
(359, 9, 32, 'item', 29, 'No-Go', 'Interdit de circuler', 'Perform Dynamic Brake Efficiency Test - Within Specs', 'Effectuer le test dynamique d\'efficacité du freinage - conforme aux spécifications', NULL),
(360, 9, 33, 'item', 30, 'No-Go', 'Interdit de circuler', 'Accumulators Free From Leakage', 'Accumulateurs exempts de fuites', NULL),
(361, 9, 34, 'section', NULL, NULL, NULL, 'Steering & Suspension', 'Direction et suspension', NULL),
(362, 9, 35, 'item', 31, 'No-Go', 'Interdit de circuler', 'Steering Mechanism In Good Condition And Fully Functional', 'Mécanisme de direction en bon état et pleinement fonctionnel', NULL),
(363, 9, 36, 'item', 32, 'Go & Fix', 'Autorisé avec correction', 'Inspect Steering Arms, Ball Joints And Steering Column For Free Play', 'Inspecter les bras de direction, les rotules et la colonne de direction pour détecter tout jeu', NULL),
(364, 9, 37, 'item', 33, 'Go & Fix', 'Autorisé avec correction', 'Inspect wheel nuts', 'Inspecter les écrous de roue', NULL),
(365, 9, 38, 'section', NULL, NULL, NULL, 'Hydraulic & Pneumatic System', 'Système hydraulique et pneumatique', NULL),
(366, 9, 39, 'item', 34, 'Go & Fix', 'Autorisé avec correction', 'Pumps/ Compressors/ Valves Free From Leakages', 'Pompes / compresseurs / vannes exempts de fuites', NULL),
(367, 9, 40, 'item', 35, 'Go & Fix', 'Autorisé avec correction', 'Hose Clamps in place', 'Colliers de flexibles en place', NULL),
(368, 9, 41, 'section', NULL, NULL, NULL, 'Engine/ Battery Compartments', 'Compartiments moteur / batterie', NULL),
(369, 9, 42, 'item', 36, 'Go & Fix', 'Autorisé avec correction', 'Covers/ Bonnet Has Appropriate Means To Be Secured In An Open And Closed Position', 'Capots / couvercles dotés d\'un dispositif approprié de maintien en position ouverte et fermée', NULL),
(370, 9, 43, 'item', 37, 'Go & Fix', 'Autorisé avec correction', 'Battery & Battery Terminals Secure', 'Batterie et bornes de batterie correctement fixées', NULL),
(371, 9, 44, 'item', 38, 'Go & Fix', 'Autorisé avec correction', 'No Exposed Electrical Cables', 'Aucun câble électrique exposé', NULL),
(372, 9, 45, 'item', 39, 'No-Go', 'Interdit de circuler', 'Check all V-belts for damage', 'Vérifier toutes les courroies trapézoïdales pour détecter d\'éventuels dommages', NULL),
(373, 10, 1, 'section', NULL, NULL, NULL, 'General checks', 'Contrôles généraux', NULL),
(374, 10, 2, 'item', 1, 'No-Go', 'Interdit de circuler', 'All front and rear working', 'Tous les feux avant et arrière fonctionnent', NULL),
(375, 10, 3, 'item', 2, 'No-Go', 'Interdit de circuler', 'Brake Light Working', 'Feu stop fonctionnel', NULL),
(376, 10, 4, 'item', 3, 'No-Go', 'Interdit de circuler', 'Indicator Light Working', 'Clignotants fonctionnels', NULL),
(377, 10, 5, 'item', 4, 'No-Go', 'Interdit de circuler', 'Vehicule Has Functional rotating flashing beacon', 'Le véhicule dispose d\'un gyrophare clignotant fonctionnel', NULL),
(378, 10, 6, 'item', 5, 'No-Go', 'Interdit de circuler', 'Vehicule Has Audible Reverse Alarm', 'Le véhicule dispose d\'une alarme sonore de marche arrière', NULL),
(379, 10, 7, 'item', 6, 'No-Go', 'Interdit de circuler', 'Fixed Two Way Radio Fitted (Where Applicable)', 'Radio bidirectionnelle fixe installée (si applicable)', NULL),
(380, 10, 8, 'item', 7, 'No-Go', 'Interdit de circuler', 'Inspected and appropriately charged Fire Extinguisher Available', 'Extincteur inspecté, disponible et correctement chargé', NULL),
(381, 10, 9, 'item', 8, 'No-Go', 'Interdit de circuler', 'Check engine, hydraulic, gearbox oil levels', 'Vérifier les niveaux d\'huile moteur, hydraulique et de boîte de vitesses', NULL),
(382, 10, 10, 'item', 9, 'No-Go', 'Interdit de circuler', 'Vehicle Rims (two piece/split type not allowed) (N/A for Busses)', 'Jantes du véhicule (type en deux parties/fendues interdit)', NULL),
(383, 10, 11, 'item', 10, 'No-Go', 'Interdit de circuler', 'Tyres have more than 2mm tread depth, no exposed wires or worn-out threads', 'Les pneus ont une profondeur de sculpture supérieure à 2 mm, sans fils apparents ni sculpture usée', NULL),
(384, 10, 12, 'item', 11, 'No-Go', 'Interdit de circuler', 'All Wheel nut in place torqued tp OEM Specification', 'Tous les écrous de roue sont en place et serrés au couple selon les spécifications du fabricant', NULL),
(385, 10, 13, 'item', 12, 'Go & Fix', 'Autorisé avec correction', 'Side Mirors Visible & Free From Cracks', 'Rétroviseurs latéraux visibles et exempts de fissures', NULL),
(386, 10, 14, 'item', 13, 'Go & Fix', 'Autorisé avec correction', 'Registration & insuarance Documentation Valid', 'Documents d\'immatriculation et d\'assurance valides', NULL),
(387, 10, 15, 'item', 14, 'Go & Fix', 'Autorisé avec correction', 'First Aid Kit Available (Where Applicable)', 'Trousse de premiers secours disponible (si applicable)', NULL),
(388, 10, 16, 'item', 15, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has Reflective Tape On All Four Sides', 'Le véhicule possède des bandes réfléchissantes sur les quatre côtés', NULL),
(389, 10, 17, 'item', 16, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has Legible Fleet Numbers On Four sides', 'Le véhicule possède des numéros de parc lisibles sur les quatre côtés', NULL),
(390, 10, 18, 'item', 17, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has An Appropriate High-Visibility Flag (Where Application)', 'Le véhicule possède un drapeau haute visibilité approprié (si applicable)', NULL),
(391, 10, 19, 'item', 18, 'Go & Fix', 'Autorisé avec correction', 'Windscreen Free From Cracks which can Obstruct Clear Visibility', 'Pare-brise exempt de fissures pouvant gêner la visibilité', NULL),
(392, 10, 20, 'item', 19, 'Go & Fix', 'Autorisé avec correction', 'Whipers In Good Condition & Fully Functional', 'Essuie-glaces en bon état et pleinement fonctionnels', NULL),
(393, 10, 21, 'item', 20, 'No-Go', 'Interdit de circuler', 'Vehicle Has An Appropriate Pre-Start Checklist Conforming With the Standards', 'Le véhicule dispose d\'une checklist de pré-démarrage appropriée et conforme aux normes', NULL),
(394, 10, 22, 'item', 21, 'Go & Fix', 'Autorisé avec correction', 'Inspect Towbar Bolts (High Tensile) And Structure (Where Aplicable)', 'Inspecter les boulons de la barre d\'attelage (haute résistance) et la structure (si applicable)', NULL),
(395, 10, 23, 'section', NULL, NULL, NULL, 'In Cab and Interior Of Vehicle', 'Cabine et intérieur du véhicule', NULL),
(396, 10, 24, 'item', 21, 'No-Go', 'Interdit de circuler', 'Seatbelt Functional and Secure On Each Seat of the Vehicle', 'Ceinture de sécurité fonctionnelle et correctement fixée sur chaque siège du véhicule', NULL),
(397, 10, 25, 'item', 22, 'No-Go', 'Interdit de circuler', 'Horn Functional', 'Klaxon fonctionnel', NULL),
(398, 10, 26, 'item', 23, 'No-Go', 'Interdit de circuler', 'Door Latches Functional', 'Serrures de portes fonctionnelles', NULL),
(399, 10, 27, 'item', 24, 'Go & Fix', 'Autorisé avec correction', 'Dashboard, Gauges and Indicators Display Functioning', 'Tableau de bord, jauges et voyants fonctionnels', NULL),
(400, 10, 28, 'item', 25, 'Go & Fix', 'Autorisé avec correction', 'Air conditioner Functional', 'Climatisation fonctionnelle', NULL),
(401, 10, 29, 'item', 26, 'Go & Fix', 'Autorisé avec correction', 'Vehicule Has Functional in-cab Camera (Front & Cab Camera) Monitoring System', 'Le véhicule dispose d\'un système de surveillance par caméra fonctionnel dans la cabine (caméra avant et cabine)', NULL),
(402, 10, 30, 'section', NULL, NULL, NULL, 'Braking System', 'Système de freinage', NULL),
(403, 10, 31, 'item', 27, 'No-Go', 'Interdit de circuler', 'Vehicle Passes Service & Park Brake Test', 'Le véhicule réussit le test du frein de service et du frein de stationnement', NULL),
(404, 10, 32, 'item', 28, 'No-Go', 'Interdit de circuler', 'Perform Dynamic Brake Efficiency Test - Within Specs', 'Effectuer le test dynamique d\'efficacité du freinage - conforme aux spécifications', NULL),
(405, 10, 33, 'item', 29, 'No-Go', 'Interdit de circuler', 'Accumulators Free From Leakage', 'Accumulateurs exempts de fuites', NULL),
(406, 10, 34, 'section', NULL, NULL, NULL, 'Steering & Suspension', 'Direction et suspension', NULL),
(407, 10, 35, 'item', 30, 'No-Go', 'Interdit de circuler', 'Steering Mechanism In Good Condition And Fully Functional', 'Mécanisme de direction en bon état et pleinement fonctionnel', NULL),
(408, 10, 36, 'item', 31, 'Go & Fix', 'Autorisé avec correction', 'Inspect Steering Arms, Ball Joints And Steering Column For Free Play', 'Inspecter les bras de direction, les rotules et la colonne de direction pour détecter tout jeu', NULL),
(409, 10, 37, 'item', 32, 'Go & Fix', 'Autorisé avec correction', 'Inspect wheel nuts', 'Inspecter les écrous de roue', NULL),
(410, 10, 38, 'section', NULL, NULL, NULL, 'Hydraulic & Pneumatic System', 'Système hydraulique et pneumatique', NULL),
(411, 10, 39, 'item', 33, 'Go & Fix', 'Autorisé avec correction', 'Pumps/ Compressors/ Valves Free From Leakages', 'Pompes / compresseurs / vannes exempts de fuites', NULL),
(412, 10, 40, 'item', 34, 'Go & Fix', 'Autorisé avec correction', 'Hose Clamps in place', 'Colliers de flexibles en place', NULL),
(413, 10, 41, 'section', NULL, NULL, NULL, 'Engine/ Battery Compartments', 'Compartiments moteur / batterie', NULL),
(414, 10, 42, 'item', 35, 'Go & Fix', 'Autorisé avec correction', 'Covers/ Bonnet Has Appropriate Means To Be Secured In An Open And Closed Position', 'Capots / couvercles dotés d\'un dispositif approprié de maintien en position ouverte et fermée', NULL),
(415, 10, 43, 'item', 36, 'Go & Fix', 'Autorisé avec correction', 'Battery & Battery Terminals Secure', 'Batterie et bornes de batterie correctement fixées', NULL),
(416, 10, 44, 'item', 37, 'Go & Fix', 'Autorisé avec correction', 'No Exposed Electrical Cables', 'Aucun câble électrique exposé', NULL),
(417, 10, 45, 'item', 38, 'No-Go', 'Interdit de circuler', 'Check all V-belts for damage', 'Vérifier toutes les courroies trapézoïdales pour détecter d\'éventuels dommages', NULL),
(418, 10, 46, 'section', NULL, NULL, NULL, 'Boom', 'Flèche', NULL),
(419, 10, 47, 'item', 39, 'No-Go', 'Interdit de circuler', 'Check if load chart is available', 'Vérifier que le tableau de charges est disponible', NULL),
(420, 10, 48, 'item', 40, 'No-Go', 'Interdit de circuler', 'Inspect boom for defects', 'Inspecter la flèche pour détecter d\'éventuels défauts', NULL),
(421, 10, 49, 'item', 41, 'No-Go', 'Interdit de circuler', 'Inspect man cage', 'Inspecter la nacelle pour personnes', NULL),
(422, 10, 50, 'item', 42, 'No-Go', 'Interdit de circuler', 'Inspect lifting hook & safety latch', 'Inspecter le crochet de levage et le linguet de sécurité', NULL),
(423, 10, 51, 'item', 43, 'No-Go', 'Interdit de circuler', 'Inspect operating station for defects', 'Inspecter le poste de commande pour détecter d\'éventuels défauts', NULL),
(424, 10, 52, 'item', 44, 'No-Go', 'Interdit de circuler', 'Ensure emergency switch is working', 'S\'assurer que l\'interrupteur d\'urgence fonctionne', NULL),
(425, 11, 1, 'section', NULL, NULL, NULL, 'General checks', 'Contrôles généraux', NULL),
(426, 11, 2, 'item', 1, 'No-Go', 'Interdit de circuler', 'All front and rear working', 'Tous les feux avant et arrière fonctionnent', NULL),
(427, 11, 3, 'item', 2, 'No-Go', 'Interdit de circuler', 'Brake Light Working', 'Feu stop fonctionnel', NULL),
(428, 11, 4, 'item', 3, 'No-Go', 'Interdit de circuler', 'Indicator Light Working', 'Clignotants fonctionnels', NULL),
(429, 11, 5, 'item', 4, 'No-Go', 'Interdit de circuler', 'Vehicule Has Functional rotating flashing beacon', 'Le véhicule dispose d\'un gyrophare clignotant fonctionnel', NULL),
(430, 11, 6, 'item', 5, 'No-Go', 'Interdit de circuler', 'Vehicule Has Audible Reverse Alarm', 'Le véhicule dispose d\'une alarme sonore de marche arrière', NULL),
(431, 11, 7, 'item', 6, 'No-Go', 'Interdit de circuler', 'Fixed Two Way Radio Fitted (Where Applicable)', 'Radio bidirectionnelle fixe installée (si applicable)', NULL),
(432, 11, 8, 'item', 7, 'No-Go', 'Interdit de circuler', 'Inspected and appropriately charged Fire Extinguisher Available', 'Extincteur inspecté, disponible et correctement chargé', NULL),
(433, 11, 9, 'item', 8, 'No-Go', 'Interdit de circuler', 'Check engine, hydraulic, gearbox oil levels', 'Vérifier les niveaux d\'huile moteur, hydraulique et de boîte de vitesses', NULL),
(434, 11, 10, 'item', 9, 'No-Go', 'Interdit de circuler', 'Vehicle Rims (two piece/split type not allowed) (N/A for Busses)', 'Jantes du véhicule (type en deux parties/fendues interdit)', NULL),
(435, 11, 11, 'item', 10, 'No-Go', 'Interdit de circuler', 'Tyres have more than 2mm tread depth, no exposed wires or worn-out threads', 'Les pneus ont une profondeur de sculpture supérieure à 2 mm, sans fils apparents ni sculpture usée', NULL),
(436, 11, 12, 'item', 11, 'No-Go', 'Interdit de circuler', 'All Wheel nut in place torqued tp OEM Specification', 'Tous les écrous de roue sont en place et serrés au couple selon les spécifications du fabricant', NULL),
(437, 11, 13, 'item', 12, 'Go & Fix', 'Autorisé avec correction', 'Side Mirors Visible & Free From Cracks', 'Rétroviseurs latéraux visibles et exempts de fissures', NULL),
(438, 11, 14, 'item', 13, 'Go & Fix', 'Autorisé avec correction', 'Registration & insuarance Documentation Valid', 'Documents d\'immatriculation et d\'assurance valides', NULL),
(439, 11, 15, 'item', 14, 'Go & Fix', 'Autorisé avec correction', 'First Aid Kit Available (Where Applicable)', 'Trousse de premiers secours disponible (si applicable)', NULL),
(440, 11, 16, 'item', 15, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has Reflective Tape On All Four Sides', 'Le véhicule possède des bandes réfléchissantes sur les quatre côtés', NULL),
(441, 11, 17, 'item', 16, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has Legible Fleet Numbers On Four sides', 'Le véhicule possède des numéros de parc lisibles sur les quatre côtés', NULL),
(442, 11, 18, 'item', 17, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has An Appropriate High-Visibility Flag (Where Application)', 'Le véhicule possède un drapeau haute visibilité approprié (si applicable)', NULL),
(443, 11, 19, 'item', 18, 'Go & Fix', 'Autorisé avec correction', 'Windscreen Free From Cracks which can Obstruct Clear Visibility', 'Pare-brise exempt de fissures pouvant gêner la visibilité', NULL),
(444, 11, 20, 'item', 19, 'Go & Fix', 'Autorisé avec correction', 'Whipers In Good Condition & Fully Functional', 'Essuie-glaces en bon état et pleinement fonctionnels', NULL),
(445, 11, 21, 'item', 20, 'No-Go', 'Interdit de circuler', 'Vehicle Has An Appropriate Pre-Start Checklist Conforming With the Standards', 'Le véhicule dispose d\'une checklist de pré-démarrage appropriée et conforme aux normes', NULL),
(446, 11, 22, 'item', 21, 'Go & Fix', 'Autorisé avec correction', 'Inspect Towbar Bolts (High Tensile) And Structure (Where Aplicable)', 'Inspecter les boulons de la barre d\'attelage (haute résistance) et la structure (si applicable)', NULL),
(447, 11, 23, 'section', NULL, NULL, NULL, 'In Cab and Interior Of Vehicle', 'Cabine et intérieur du véhicule', NULL),
(448, 11, 24, 'item', 21, 'No-Go', 'Interdit de circuler', 'Seatbelt Functional and Secure On Each Seat of the Vehicle', 'Ceinture de sécurité fonctionnelle et correctement fixée sur chaque siège du véhicule', NULL),
(449, 11, 25, 'item', 22, 'No-Go', 'Interdit de circuler', 'Horn Functional', 'Klaxon fonctionnel', NULL),
(450, 11, 26, 'item', 23, 'No-Go', 'Interdit de circuler', 'Door Latches Functional', 'Serrures de portes fonctionnelles', NULL),
(451, 11, 27, 'item', 24, 'Go & Fix', 'Autorisé avec correction', 'Dashboard, Gauges and Indicators Display Functioning', 'Tableau de bord, jauges et voyants fonctionnels', NULL),
(452, 11, 28, 'item', 25, 'Go & Fix', 'Autorisé avec correction', 'Air conditioner Functional', 'Climatisation fonctionnelle', NULL),
(453, 11, 29, 'item', 26, 'Go & Fix', 'Autorisé avec correction', 'Vehicule Has Functional in-cab Camera (Front & Cab Camera) Monitoring System', 'Le véhicule dispose d\'un système de surveillance par caméra fonctionnel dans la cabine (caméra avant et cabine)', NULL),
(454, 11, 30, 'section', NULL, NULL, NULL, 'Braking System', 'Système de freinage', NULL),
(455, 11, 31, 'item', 27, 'No-Go', 'Interdit de circuler', 'Vehicle Passes Service & Park Brake Test', 'Le véhicule réussit le test du frein de service et du frein de stationnement', NULL),
(456, 11, 32, 'item', 28, 'No-Go', 'Interdit de circuler', 'Perform Dynamic Brake Efficiency Test - Within Specs', 'Effectuer le test dynamique d\'efficacité du freinage - conforme aux spécifications', NULL),
(457, 11, 33, 'item', 29, 'No-Go', 'Interdit de circuler', 'Accumulators Free From Leakage', 'Accumulateurs exempts de fuites', NULL),
(458, 11, 34, 'section', NULL, NULL, NULL, 'Steering & Suspension', 'Direction et suspension', NULL),
(459, 11, 35, 'item', 30, 'No-Go', 'Interdit de circuler', 'Steering Mechanism In Good Condition And Fully Functional', 'Mécanisme de direction en bon état et pleinement fonctionnel', NULL),
(460, 11, 36, 'item', 31, 'Go & Fix', 'Autorisé avec correction', 'Inspect Steering Arms, Ball Joints And Steering Column For Free Play', 'Inspecter les bras de direction, les rotules et la colonne de direction pour détecter tout jeu', NULL),
(461, 11, 37, 'section', NULL, NULL, NULL, 'Hydraulic & Pneumatic System', 'Système hydraulique et pneumatique', NULL),
(462, 11, 38, 'item', 32, 'Go & Fix', 'Autorisé avec correction', 'Pumps/ Compressors/ Valves Free From Leakages', 'Pompes / compresseurs / vannes exempts de fuites', NULL),
(463, 11, 39, 'item', 33, 'Go & Fix', 'Autorisé avec correction', 'Hose Clamps in place', 'Colliers de flexibles en place', NULL),
(464, 11, 40, 'section', NULL, NULL, NULL, 'Engine/ Battery Compartments', 'Compartiments moteur / batterie', NULL),
(465, 11, 41, 'item', 34, 'Go & Fix', 'Autorisé avec correction', 'Covers/ Bonnet Has Appropriate Means To Be Secured In An Open And Closed Position', 'Capots / couvercles dotés d\'un dispositif approprié de maintien en position ouverte et fermée', NULL);

INSERT IGNORE INTO `inspection_items` (`id`, `template_id`, `sort_order`, `row_type`, `item_no`, `category_en`, `category_fr`, `text_en`, `text_fr`, `na_columns`) VALUES
(466, 11, 42, 'item', 35, 'Go & Fix', 'Autorisé avec correction', 'Battery & Battery Terminals Secure', 'Batterie et bornes de batterie correctement fixées', NULL),
(467, 11, 43, 'item', 36, 'Go & Fix', 'Autorisé avec correction', 'No Exposed Electrical Cables', 'Aucun câble électrique exposé', NULL),
(468, 12, 1, 'section', NULL, NULL, NULL, 'General checks', 'Contrôles généraux', NULL),
(469, 12, 2, 'item', 1, 'No-Go', 'Interdit de circuler', 'All front and rear working', 'Tous les feux avant et arrière fonctionnent', NULL),
(470, 12, 3, 'item', 2, 'No-Go', 'Interdit de circuler', 'Brake Light Working', 'Feu stop fonctionnel', NULL),
(471, 12, 4, 'item', 3, 'No-Go', 'Interdit de circuler', 'Indicator Light Working', 'Clignotants fonctionnels', NULL),
(472, 12, 5, 'item', 4, 'No-Go', 'Interdit de circuler', 'Vehicule Has Functional rotating flashing beacon', 'Le véhicule dispose d\'un gyrophare clignotant fonctionnel', NULL),
(473, 12, 6, 'item', 5, 'No-Go', 'Interdit de circuler', 'Vehicule Has Audible Reverse Alarm', 'Le véhicule dispose d\'une alarme sonore de marche arrière', NULL),
(474, 12, 7, 'item', 6, 'No-Go', 'Interdit de circuler', 'Fixed Two Way Radio Fitted (Where Applicable)', 'Radio bidirectionnelle fixe installée (si applicable)', NULL),
(475, 12, 8, 'item', 7, 'No-Go', 'Interdit de circuler', 'Inspected and appropriately charged Fire Extinguisher Available', 'Extincteur inspecté, disponible et correctement chargé', NULL),
(476, 12, 9, 'item', 8, 'No-Go', 'Interdit de circuler', 'Check engine, hydraulic, gearbox oil levels', 'Vérifier les niveaux d\'huile moteur, hydraulique et de boîte de vitesses', NULL),
(477, 12, 10, 'item', 9, 'No-Go', 'Interdit de circuler', 'Vehicle Rims (two piece/split type not allowed) (N/A for Busses)', 'Jantes du véhicule (type en deux parties/fendues interdit)', NULL),
(478, 12, 11, 'item', 10, 'No-Go', 'Interdit de circuler', 'Tyres have more than 2mm tread depth, no exposed wires or worn-out threads', 'Les pneus ont une profondeur de sculpture supérieure à 2 mm, sans fils apparents ni sculpture usée', NULL),
(479, 12, 12, 'item', 11, 'No-Go', 'Interdit de circuler', 'All Wheel nut in place torqued tp OEM Specification', 'Tous les écrous de roue sont en place et serrés au couple selon les spécifications du fabricant', NULL),
(480, 12, 13, 'item', 12, 'Go & Fix', 'Autorisé avec correction', 'Side Mirors Visible & Free From Cracks', 'Rétroviseurs latéraux visibles et exempts de fissures', NULL),
(481, 12, 14, 'item', 13, 'Go & Fix', 'Autorisé avec correction', 'Registration & insuarance Documentation Valid', 'Documents d\'immatriculation et d\'assurance valides', NULL),
(482, 12, 15, 'item', 14, 'Go & Fix', 'Autorisé avec correction', 'First Aid Kit Available (Where Applicable)', 'Trousse de premiers secours disponible (si applicable)', NULL),
(483, 12, 16, 'item', 15, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has Reflective Tape On All Four Sides', 'Le véhicule possède des bandes réfléchissantes sur les quatre côtés', NULL),
(484, 12, 17, 'item', 16, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has Legible Fleet Numbers On Four sides', 'Le véhicule possède des numéros de parc lisibles sur les quatre côtés', NULL),
(485, 12, 18, 'item', 17, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has An Appropriate High-Visibility Flag (Where Application)', 'Le véhicule possède un drapeau haute visibilité approprié (si applicable)', NULL),
(486, 12, 19, 'item', 18, 'Go & Fix', 'Autorisé avec correction', 'Windscreen Free From Cracks which can Obstruct Clear Visibility', 'Pare-brise exempt de fissures pouvant gêner la visibilité', NULL),
(487, 12, 20, 'item', 19, 'Go & Fix', 'Autorisé avec correction', 'Whipers In Good Condition & Fully Functional', 'Essuie-glaces en bon état et pleinement fonctionnels', NULL),
(488, 12, 21, 'item', 20, 'No-Go', 'Interdit de circuler', 'Vehicle Has An Appropriate Pre-Start Checklist Conforming With the Standards', 'Le véhicule dispose d\'une checklist de pré-démarrage appropriée et conforme aux normes', NULL),
(489, 12, 22, 'item', 21, 'Go & Fix', 'Autorisé avec correction', 'Inspect Towbar Bolts (High Tensile) And Structure (Where Aplicable)', 'Inspecter les boulons de la barre d\'attelage (haute résistance) et la structure (si applicable)', NULL),
(490, 12, 23, 'section', NULL, NULL, NULL, 'In Cab and Interior Of Vehicle', 'Cabine et intérieur du véhicule', NULL),
(491, 12, 24, 'item', 21, 'No-Go', 'Interdit de circuler', 'Seatbelt Functional and Secure On Each Seat of the Vehicle', 'Ceinture de sécurité fonctionnelle et correctement fixée sur chaque siège du véhicule', NULL),
(492, 12, 25, 'item', 22, 'No-Go', 'Interdit de circuler', 'Horn Functional', 'Klaxon fonctionnel', NULL),
(493, 12, 26, 'item', 23, 'No-Go', 'Interdit de circuler', 'Door Latches Functional', 'Serrures de portes fonctionnelles', NULL),
(494, 12, 27, 'item', 24, 'Go & Fix', 'Autorisé avec correction', 'Dashboard, Gauges and Indicators Display Functioning', 'Tableau de bord, jauges et voyants fonctionnels', NULL),
(495, 12, 28, 'item', 25, 'Go & Fix', 'Autorisé avec correction', 'Air conditioner Functional', 'Climatisation fonctionnelle', NULL),
(496, 12, 29, 'item', 26, 'Go & Fix', 'Autorisé avec correction', 'Vehicule Has Functional in-cab Camera (Front & Cab Camera) Monitoring System', 'Le véhicule dispose d\'un système de surveillance par caméra fonctionnel dans la cabine (caméra avant et cabine)', NULL),
(497, 12, 30, 'section', NULL, NULL, NULL, 'Braking System', 'Système de freinage', NULL),
(498, 12, 31, 'item', 27, 'No-Go', 'Interdit de circuler', 'Vehicle Passes Service & Park Brake Test', 'Le véhicule réussit le test du frein de service et du frein de stationnement', NULL),
(499, 12, 32, 'item', 28, 'No-Go', 'Interdit de circuler', 'Perform Dynamic Brake Efficiency Test - Within Specs', 'Effectuer le test dynamique d\'efficacité du freinage - conforme aux spécifications', NULL),
(500, 12, 33, 'item', 29, 'No-Go', 'Interdit de circuler', 'Accumulators Free From Leakage', 'Accumulateurs exempts de fuites', NULL),
(501, 12, 34, 'section', NULL, NULL, NULL, 'Steering & Suspension', 'Direction et suspension', NULL),
(502, 12, 35, 'item', 30, 'No-Go', 'Interdit de circuler', 'Steering Mechanism In Good Condition And Fully Functional', 'Mécanisme de direction en bon état et pleinement fonctionnel', NULL),
(503, 12, 36, 'item', 31, 'Go & Fix', 'Autorisé avec correction', 'Inspect Steering Arms, Ball Joints And Steering Column For Free Play', 'Inspecter les bras de direction, les rotules et la colonne de direction pour détecter tout jeu', NULL),
(504, 12, 37, 'item', 32, 'Go & Fix', 'Autorisé avec correction', 'Inspect wheel nuts', 'Inspecter les écrous de roue', NULL),
(505, 12, 38, 'section', NULL, NULL, NULL, 'Hydraulic & Pneumatic System', 'Système hydraulique et pneumatique', NULL),
(506, 12, 39, 'item', 33, 'Go & Fix', 'Autorisé avec correction', 'Pumps/ Compressors/ Valves Free From Leakages', 'Pompes / compresseurs / vannes exempts de fuites', NULL),
(507, 12, 40, 'item', 34, 'Go & Fix', 'Autorisé avec correction', 'Hose Clamps in place', 'Colliers de flexibles en place', NULL),
(508, 12, 41, 'section', NULL, NULL, NULL, 'Engine/ Battery Compartments', 'Compartiments moteur / batterie', NULL),
(509, 12, 42, 'item', 35, 'Go & Fix', 'Autorisé avec correction', 'Covers/ Bonnet Has Appropriate Means To Be Secured In An Open And Closed Position', 'Capots / couvercles dotés d\'un dispositif approprié de maintien en position ouverte et fermée', NULL),
(510, 12, 43, 'item', 36, 'Go & Fix', 'Autorisé avec correction', 'Battery & Battery Terminals Secure', 'Batterie et bornes de batterie correctement fixées', NULL),
(511, 12, 44, 'item', 37, 'Go & Fix', 'Autorisé avec correction', 'No Exposed Electrical Cables', 'Aucun câble électrique exposé', NULL),
(512, 12, 45, 'item', 38, 'No-Go', 'Interdit de circuler', 'Check all V-belts for damage', 'Vérifier toutes les courroies trapézoïdales pour détecter d\'éventuels dommages', NULL),
(513, 12, 46, 'section', NULL, NULL, NULL, 'Tank', 'Citerne', NULL),
(514, 12, 47, 'item', 39, 'No-Go', 'Interdit de circuler', 'Inspect Operating station for defects', 'Inspecter le poste de commande pour détecter d\'éventuels défauts', NULL),
(515, 12, 48, 'item', 40, 'No-Go', 'Interdit de circuler', 'Ensure Tanks is secured', 'S\'assurer que les réservoirs sont correctement fixés', NULL),
(516, 12, 49, 'item', 41, 'No-Go', 'Interdit de circuler', 'Inspect hoses and reels', 'Inspecter les flexibles et les enrouleurs', NULL),
(517, 12, 50, 'item', 42, 'No-Go', 'Interdit de circuler', 'Inpect Pump and Valves', 'Inspecter la pompe et les vannes', NULL),
(518, 12, 51, 'item', 43, 'No-Go', 'Interdit de circuler', 'Inspect Ladders', 'Inspecter les échelles', NULL),
(519, 12, 52, 'item', 44, 'No-Go', 'Interdit de circuler', 'Ensure emergency switch is working', 'S\'assurer que l\'interrupteur d\'urgence fonctionne', NULL),
(520, 13, 1, 'section', NULL, NULL, NULL, 'General checks', 'Contrôles Généraux', NULL),
(521, 13, 2, 'item', 1, 'No-Go', 'Interdit de circuler', 'All front and rear working', 'Tous les feux avant et arrière fonctionnent', NULL),
(522, 13, 3, 'item', 2, 'No-Go', 'Interdit de circuler', 'Brake Light Working', 'Feux de stop fonctionnels', NULL),
(523, 13, 4, 'item', 3, 'No-Go', 'Interdit de circuler', 'Indicator Light Working', 'Clignotants fonctionnels', NULL),
(524, 13, 5, 'item', 4, 'No-Go', 'Interdit de circuler', 'Vehicule Has Functional rotating flashing beacon', 'Le véhicule possède un gyrophare clignotant fonctionnel', NULL),
(525, 13, 6, 'item', 5, 'No-Go', 'Interdit de circuler', 'Vehicule Has Audible Reverse Alarm', 'Le véhicule possède une alarme sonore de recul', NULL),
(526, 13, 7, 'item', 6, 'No-Go', 'Interdit de circuler', 'Fixed Two Way Radio Fitted (Where Applicable)', 'Radio bidirectionnelle fixe installée (si applicable)', NULL),
(527, 13, 8, 'item', 7, 'No-Go', 'Interdit de circuler', 'Inspected and appropriately charged Fire Extinguisher Available', 'Extincteur inspecté et correctement chargé disponible', NULL),
(528, 13, 9, 'item', 8, 'No-Go', 'Interdit de circuler', 'Check engine, hydraulic, gearbox oil levels', 'Vérifier les niveaux d\'huile moteur, hydraulique et boîte de vitesses', NULL),
(529, 13, 10, 'item', 9, 'No-Go', 'Interdit de circuler', 'Vehicle Rims (two piece/split type not allowed) (N/A for Busses)', 'Jantes du véhicule (type deux pièces/fendues non autorisées) (S/O pour les bus)', NULL),
(530, 13, 11, 'item', 10, 'No-Go', 'Interdit de circuler', 'Tyres have more than 2mm tread depth, no exposed wires or worn-out threads', 'Les pneus ont plus de 2 mm de profondeur de sculpture, pas de fils exposés ni de bandes usées', NULL),
(531, 13, 12, 'item', 11, 'No-Go', 'Interdit de circuler', 'All Wheel nut in place torqued tp OEM Specification', 'Tous les écrous de roue sont en place et serrés selon les spécifications du fabricant', NULL),
(532, 13, 13, 'item', 12, 'Go & Fix', 'Autorisé avec correction', 'Side Mirors Visible & Free From Cracks', 'Rétroviseurs latéraux visibles et exempts de fissures', NULL),
(533, 13, 14, 'item', 13, 'Go & Fix', 'Autorisé avec correction', 'Registration & insuarance Documentation Valid', 'Documents d\'immatriculation et d\'assurance valides', NULL),
(534, 13, 15, 'item', 14, 'Go & Fix', 'Autorisé avec correction', 'First Aid Kit Available (Where Applicable)', 'Trousse de premiers secours disponible (si applicable)', NULL),
(535, 13, 16, 'item', 15, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has Reflective Tape On All Four Sides', 'Le véhicule a de la bande réfléchissante sur les quatre côtés', NULL),
(536, 13, 17, 'item', 16, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has Legible Fleet Numbers On Four sides', 'Le véhicule a des numéros de parc lisibles sur les quatre côtés', NULL),
(537, 13, 18, 'item', 17, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has An Appropriate High-Visibility Flag (Where Application)', 'Le véhicule a un fanion haute visibilité approprié (si applicable)', NULL),
(538, 13, 19, 'item', 18, 'Go & Fix', 'Autorisé avec correction', 'Windscreen Free From Cracks which can Obstruct Clear Visibility', 'Pare-brise exempt de fissures pouvant obstruer la visibilité claire', NULL),
(539, 13, 20, 'item', 19, 'Go & Fix', 'Autorisé avec correction', 'Whipers In Good Condition & Fully Functional', 'Essuie-glaces en bon état et entièrement fonctionnels', NULL),
(540, 13, 21, 'item', 20, 'No-Go', 'Interdit de circuler', 'Vehicle Has An Appropriate Pre-Start Checklist Conforming With the Standards', 'Le véhicule possède une liste de contrôle d\'avant-démarrage conforme aux normes', NULL),
(541, 13, 22, 'item', 21, 'Go & Fix', 'Autorisé avec correction', 'Inspect Towbar Bolts (High Tensile) And Structure (Where Aplicable)', 'Inspecter les boulons de la barre d\'attelage (haute résistance) et la structure (si applicable)', NULL),
(542, 13, 23, 'section', NULL, NULL, NULL, 'In Cab and Interior Of Vehicle', 'Dans la Cabine et Intérieur du Véhicule', NULL),
(543, 13, 24, 'item', 21, 'No-Go', 'Interdit de circuler', 'Seatbelt Functional and Secure On Each Seat of the Vehicle', 'Ceinture de sécurité fonctionnelle et fixée à chaque siège du véhicule', NULL),
(544, 13, 25, 'item', 22, 'No-Go', 'Interdit de circuler', 'Horn Functional', 'Klaxon fonctionnel', NULL),
(545, 13, 26, 'item', 23, 'No-Go', 'Interdit de circuler', 'Door Latches Functional', 'Loquets de porte fonctionnels', NULL),
(546, 13, 27, 'item', 24, 'Go & Fix', 'Autorisé avec correction', 'Dashboard, Gauges and Indicators Display Functioning', 'Affichage du tableau de bord, des jauges et des indicateurs fonctionnel', NULL),
(547, 13, 28, 'item', 25, 'Go & Fix', 'Autorisé avec correction', 'Air conditioner Functional', 'Climatisation fonctionnelle', NULL),
(548, 13, 29, 'item', 26, 'Go & Fix', 'Autorisé avec correction', 'Vehicule Has Functional in-cab Camera (Front & Cab Camera) Monitoring System', 'Le véhicule possède un système de surveillance par caméra en cabine fonctionnel (caméra avant & cabine)', NULL),
(549, 13, 30, 'section', NULL, NULL, NULL, 'Braking System', 'Système de Freinage', NULL),
(550, 13, 31, 'item', 27, 'No-Go', 'Interdit de circuler', 'Vehicle Passes Service & Park Brake Test', 'Le véhicule réussit les tests de frein de service et de stationnement', NULL),
(551, 13, 32, 'item', 28, 'No-Go', 'Interdit de circuler', 'Perform Dynamic Brake Efficiency Test - Within Specs', 'Effectuer un test d\'efficacité dynamique des freins - Conforme aux spécifications', NULL),
(552, 13, 33, 'item', 29, 'No-Go', 'Interdit de circuler', 'Accumulators Free From Leakage', 'Accumulateurs exempts de fuites', NULL),
(553, 13, 34, 'section', NULL, NULL, NULL, 'Steering & Suspension', 'Direction & Suspension', NULL),
(554, 13, 35, 'item', 30, 'No-Go', 'Interdit de circuler', 'Steering Mechanism In Good Condition And Fully Functional', 'Mécanisme de direction en bon état et entièrement fonctionnel', NULL),
(555, 13, 36, 'item', 31, 'Go & Fix', 'Autorisé avec correction', 'Inspect Steering Arms, Ball Joints And Steering Column For Free Play', 'Inspecter les bras de direction, les rotules et la colonne de direction pour vérifier le jeu libre', NULL),
(556, 13, 37, 'item', 32, 'Go & Fix', 'Autorisé avec correction', 'Inspect wheel nuts', 'Inspecter les écrous de roue', NULL),
(557, 13, 38, 'section', NULL, NULL, NULL, 'Hydraulic & Pneumatic System', 'Système Hydraulique & Pneumatique', NULL),
(558, 13, 39, 'item', 33, 'Go & Fix', 'Autorisé avec correction', 'Pumps/ Compressors/ Valves Free From Leakages', 'Pompes / Compresseurs / Soupapes exempts de fuites', NULL),
(559, 13, 40, 'item', 34, 'Go & Fix', 'Autorisé avec correction', 'Hose Clamps in place', 'Colliers de serrage en place', NULL),
(560, 13, 41, 'section', NULL, NULL, NULL, 'Engine/ Battery Compartments', 'Compartiments Moteur / Batterie', NULL),
(561, 13, 42, 'item', 35, 'Go & Fix', 'Autorisé avec correction', 'Covers/ Bonnet Has Appropriate Means To Be Secured In An Open And Closed Position', 'Les capots disposent de moyens appropriés pour être sécurisés en position ouverte et fermée', NULL),
(562, 13, 43, 'item', 36, 'Go & Fix', 'Autorisé avec correction', 'Battery & Battery Terminals Secure', 'Batterie et cosses de batterie bien fixées', NULL),
(563, 13, 44, 'item', 37, 'Go & Fix', 'Autorisé avec correction', 'No Exposed Electrical Cables', 'Pas de câbles électriques dénudés', NULL),
(564, 13, 45, 'item', 38, 'No-Go', 'Interdit de circuler', 'Check all V-belts for damage', 'Vérifier l\'état de toutes les courroies trapézoïdales', NULL),
(565, 13, 46, 'section', NULL, NULL, NULL, 'Tank', 'Citerne / Réservoir', NULL),
(566, 13, 47, 'item', 39, 'No-Go', 'Interdit de circuler', 'Inspect Operating station for defects', 'Inspecter le poste de commande pour détecter les défauts', NULL),
(567, 13, 48, 'item', 40, 'No-Go', 'Interdit de circuler', 'Ensure Tanks is secured', 'S\'assurer que les citernes/réservoirs sont bien fixés', NULL),
(568, 13, 49, 'item', 41, 'No-Go', 'Interdit de circuler', 'Inspect hoses and reels', 'Inspecter les tuyaux et enrouleurs', NULL),
(569, 13, 50, 'item', 42, 'No-Go', 'Interdit de circuler', 'Inpect Pump and Valves', 'Inspecter la pompe et les soupapes', NULL),
(570, 13, 51, 'item', 43, 'No-Go', 'Interdit de circuler', 'Inspect Ladders', 'Inspecter les échelles', NULL),
(571, 13, 52, 'item', 44, 'No-Go', 'Interdit de circuler', 'Ensure emergency switch is working', 'S\'assurer que l\'interrupteur d\'urgence fonctionne', NULL),
(572, 14, 1, 'section', NULL, NULL, NULL, 'General checks', 'Contrôles Généraux', NULL),
(573, 14, 2, 'item', 1, 'No-Go', 'Interdit de circuler', 'All front and rear working', 'Tous les feux avant et arrière fonctionnent', NULL),
(574, 14, 3, 'item', 2, 'No-Go', 'Interdit de circuler', 'Brake Light Working', 'Feux de stop fonctionnels', NULL),
(575, 14, 4, 'item', 3, 'No-Go', 'Interdit de circuler', 'Indicator Light Working', 'Clignotants fonctionnels', NULL),
(576, 14, 5, 'item', 4, 'No-Go', 'Interdit de circuler', 'Vehicule Has Functional rotating flashing beacon', 'Le véhicule possède un gyrophare clignotant fonctionnel', NULL),
(577, 14, 6, 'item', 5, 'No-Go', 'Interdit de circuler', 'Vehicule Has Audible Reverse Alarm', 'Le véhicule possède une alarme sonore de recul', NULL),
(578, 14, 7, 'item', 6, 'No-Go', 'Interdit de circuler', 'Fixed Two Way Radio Fitted (Where Applicable)', 'Radio bidirectionnelle fixe installée (si applicable)', NULL),
(579, 14, 8, 'item', 7, 'No-Go', 'Interdit de circuler', 'Inspected and appropriately charged Fire Extinguisher Available', 'Extincteur inspecté et correctement chargé disponible', NULL),
(580, 14, 9, 'item', 8, 'No-Go', 'Interdit de circuler', 'Check engine, hydraulic, gearbox oil levels', 'Vérifier les niveaux d\'huile moteur, hydraulique et boîte de vitesses', NULL),
(581, 14, 10, 'item', 9, 'No-Go', 'Interdit de circuler', 'Vehicle Rims (two piece/split type not allowed) (N/A for Busses)', 'Jantes du véhicule (type deux pièces/fendues non autorisées) (S/O pour les bus)', NULL),
(582, 14, 11, 'item', 10, 'No-Go', 'Interdit de circuler', 'Tyres have more than 2mm tread depth, no exposed wires or worn-out threads', 'Les pneus ont plus de 2 mm de profondeur de sculpture, pas de fils exposés ni de bandes usées', NULL),
(583, 14, 12, 'item', 11, 'No-Go', 'Interdit de circuler', 'All Wheel nut in place torqued tp OEM Specification', 'Tous les écrous de roue sont en place et serrés selon les spécifications du fabricant', NULL),
(584, 14, 13, 'item', 12, 'Go & Fix', 'Autorisé avec correction', 'Side Mirors Visible & Free From Cracks', 'Rétroviseurs latéraux visibles et exempts de fissures', NULL),
(585, 14, 14, 'item', 13, 'Go & Fix', 'Autorisé avec correction', 'Registration & insuarance Documentation Valid', 'Documents d\'immatriculation et d\'assurance valides', NULL);

INSERT IGNORE INTO `inspection_items` (`id`, `template_id`, `sort_order`, `row_type`, `item_no`, `category_en`, `category_fr`, `text_en`, `text_fr`, `na_columns`) VALUES
(586, 14, 15, 'item', 14, 'Go & Fix', 'Autorisé avec correction', 'Ensure Licenses & permits are valid', 'S\'assurer que les licences et permis sont valides', NULL),
(587, 14, 16, 'item', 15, 'Go & Fix', 'Autorisé avec correction', 'First Aid Kit Available (Where Applicable)', 'Trousse de premiers secours disponible (si applicable)', NULL),
(588, 14, 17, 'item', 16, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has Reflective Tape On All Four Sides', 'Le véhicule a de la bande réfléchissante sur les quatre côtés', NULL),
(589, 14, 18, 'item', 17, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has Legible Fleet Numbers On Four sides', 'Le véhicule a des numéros de parc lisibles sur les quatre côtés', NULL),
(590, 14, 19, 'item', 18, 'Go & Fix', 'Autorisé avec correction', 'Vehicle Has An Appropriate High-Visibility Flag (Where Application)', 'Le véhicule a un fanion haute visibilité approprié (si applicable)', NULL),
(591, 14, 20, 'item', 19, 'Go & Fix', 'Autorisé avec correction', 'Windscreen Free From Cracks which can Obstruct Clear Visibility', 'Pare-brise exempt de fissures pouvant obstruer la visibilité claire', NULL),
(592, 14, 21, 'item', 20, 'Go & Fix', 'Autorisé avec correction', 'Whipers In Good Condition & Fully Functional', 'Essuie-glaces en bon état et entièrement fonctionnels', NULL),
(593, 14, 22, 'item', 21, 'No-Go', 'Interdit de circuler', 'Vehicle Has An Appropriate Pre-Start Checklist Conforming With the Standards', 'Le véhicule possède une liste de contrôle d\'avant-démarrage conforme aux normes', NULL),
(594, 14, 23, 'item', 22, 'Go & Fix', 'Autorisé avec correction', 'Inspect Towbar Bolts (High Tensile) And Structure (Where Aplicable)', 'Inspecter les boulons de la barre d\'attelage (haute résistance) et la structure (si applicable)', NULL),
(595, 14, 24, 'section', NULL, NULL, NULL, 'In Cab and Interior Of Vehicle', 'Dans la Cabine et Intérieur du Véhicule', NULL),
(596, 14, 25, 'item', 23, 'No-Go', 'Interdit de circuler', 'Seatbelt Functional and Secure On Each Seat of the Vehicle', 'Ceinture de sécurité fonctionnelle et fixée à chaque siège du véhicule', NULL),
(597, 14, 26, 'item', 24, 'No-Go', 'Interdit de circuler', 'Horn Functional', 'Klaxon fonctionnel', NULL),
(598, 14, 27, 'item', 25, 'No-Go', 'Interdit de circuler', 'Door Latches Functional', 'Loquets de porte fonctionnels', NULL),
(599, 14, 28, 'item', 26, 'Go & Fix', 'Autorisé avec correction', 'Dashboard, Gauges and Indicators Display Functioning', 'Affichage du tableau de bord, des jauges et des indicateurs fonctionnel', NULL),
(600, 14, 29, 'item', 27, 'Go & Fix', 'Autorisé avec correction', 'Air conditioner Functional', 'Climatisation fonctionnelle', NULL),
(601, 14, 30, 'item', 28, 'Go & Fix', 'Autorisé avec correction', 'Vehicule Has Functional in-cab Camera (Front & Cab Camera) Monitoring System', 'Le véhicule possède un système de surveillance par caméra en cabine fonctionnel (caméra avant & cabine)', NULL),
(602, 14, 31, 'section', NULL, NULL, NULL, 'Braking System', 'Système de Freinage', NULL),
(603, 14, 32, 'item', 29, 'No-Go', 'Interdit de circuler', 'Vehicle Passes Service & Park Brake Test', 'Le véhicule réussit les tests de frein de service et de stationnement', NULL),
(604, 14, 33, 'item', 30, 'No-Go', 'Interdit de circuler', 'Perform Dynamic Brake Efficiency Test - Within Specs', 'Effectuer un test d\'efficacité dynamique des freins - Conforme aux spécifications', NULL),
(605, 14, 34, 'item', 31, 'No-Go', 'Interdit de circuler', 'Accumulators Free From Leakage', 'Accumulateurs exempts de fuites', NULL),
(606, 14, 35, 'item', 32, 'No-Go', 'Interdit de circuler', 'Ensure park brake is working', 'S\'assurer que le frein de stationnement fonctionne', NULL),
(607, 14, 36, 'section', NULL, NULL, NULL, 'Steering & Suspension', 'Direction & Suspension', NULL),
(608, 14, 37, 'item', 33, 'No-Go', 'Interdit de circuler', 'Steering Mechanism In Good Condition And Fully Functional', 'Mécanisme de direction en bon état et entièrement fonctionnel', NULL),
(609, 14, 38, 'item', 34, 'Go & Fix', 'Autorisé avec correction', 'Inspect Steering Arms, Ball Joints And Steering Column For Free Play', 'Inspecter les bras de direction, les rotules et la colonne de direction pour vérifier le jeu libre', NULL),
(610, 14, 39, 'item', 35, 'Go & Fix', 'Autorisé avec correction', 'Inspect wheel nuts', 'Inspecter les écrous de roue', NULL),
(611, 14, 40, 'item', 36, 'Go & Fix', 'Autorisé avec correction', 'Inspect wheel nuts indicator', 'Inspecter les indicateurs d\'écrous de roue', NULL),
(612, 14, 41, 'item', 37, 'Go & Fix', 'Autorisé avec correction', 'Inspect tires conditions', 'Inspecter l\'état des pneus', NULL),
(613, 14, 42, 'section', NULL, NULL, NULL, 'Hydraulic & Pneumatic System', 'Système Hydraulique & Pneumatique', NULL),
(614, 14, 43, 'item', 38, 'No-Go', 'Interdit de circuler', 'Pumps/ Compressors/ Valves Free From Leakages', 'Pompes / Compresseurs / Soupapes exempts de fuites', NULL),
(615, 14, 44, 'item', 39, 'No-Go', 'Interdit de circuler', 'Hose Clamps in place', 'Colliers de serrage en place', NULL),
(616, 14, 45, 'item', 40, 'No-Go', 'Interdit de circuler', 'Inspect all Hydraulic cylinders for defects', 'Inspecter tous les vérins hydrauliques pour détecter les défauts', NULL),
(617, 14, 46, 'section', NULL, NULL, NULL, 'Engine/ Battery Compartments', 'Compartiments Moteur / Batterie', NULL),
(618, 14, 47, 'item', 41, 'Go & Fix', 'Autorisé avec correction', 'Covers/ Bonnet Has Appropriate Means To Be Secured In An Open And Closed Position', 'Les capots disposent de moyens appropriés pour être sécurisés en position ouverte et fermée', NULL),
(619, 14, 48, 'item', 42, 'Go & Fix', 'Autorisé avec correction', 'Battery & Battery Terminals Secure', 'Batterie et cosses de batterie bien fixées', NULL),
(620, 14, 49, 'item', 43, 'Go & Fix', 'Autorisé avec correction', 'No Exposed Electrical Cables', 'Pas de câbles électriques dénudés', NULL),
(621, 14, 50, 'item', 44, 'No-Go', 'Interdit de circuler', 'Check all V-belts for damage', 'Vérifier l\'état de toutes les courroies trapézoïdales', NULL),
(622, 14, 51, 'section', NULL, NULL, NULL, 'Lifting Equipment', 'Équipement de levage', NULL),
(623, 14, 52, 'item', 45, 'No-Go', 'Interdit de circuler', 'Check if load chart is available', 'Vérifier si le tableau des charges est disponible', NULL),
(624, 14, 53, 'item', 46, 'No-Go', 'Interdit de circuler', 'inspect boom for defects', 'Inspecter la flèche/le mât pour détecter les défauts', NULL),
(625, 14, 54, 'item', 47, 'No-Go', 'Interdit de circuler', 'inspect outriggers for defects', 'Inspecter les stabilisateurs pour détecter les défauts', NULL),
(626, 14, 55, 'item', 48, 'No-Go', 'Interdit de circuler', 'inspect lifting hook & safety latch', 'Inspecter le crochet de levage et le linguet de sécurité', NULL),
(627, 14, 56, 'item', 49, 'No-Go', 'Interdit de circuler', 'inspect operating station for defects', 'Inspecter le poste de commande pour détecter les défauts', NULL),
(628, 14, 57, 'item', 50, 'No-Go', 'Interdit de circuler', 'ensure emergency stop switch is working', 'S\'assurer que le bouton d\'arrêt d\'urgence fonctionne', NULL),
(629, 14, 58, 'item', 51, 'No-Go', 'Interdit de circuler', 'inspect forks & attachments for damage', 'Inspecter les fourches et accessoires pour détecter les dommages', NULL),
(630, 14, 59, 'item', 52, 'No-Go', 'Interdit de circuler', 'Inspect the mast for defects', 'Inspecter le mât pour détecter les défauts', NULL);

UPDATE `inspection_items`
   SET `priority` = 'P1'
 WHERE `row_type` = 'item'
   AND `priority` IS NULL
   AND (
        LOWER(COALESCE(`text_en`, '')) REGEXP 'service brake|parking brake|emergency stop|seat ?belt|fire extinguisher|steering|rops|fops|reverse alarm|horn'
     OR LOWER(COALESCE(`category_en`, '')) REGEXP 'safety|brake|steering'
   );

INSERT INTO `schema_migrations` (`version`, `filename`, `checksum`, `applied_at`) VALUES
  ('0001', '0001_inspection_items_priority.sql', '91d93048f9afec3f351f2db87e30b2f469bc61081a1e514e7ded1b0c8b4c5cf1', NOW()),
  ('0002', '0002_drop_duplicate_import_index.sql', 'e428ca8f64020b5d3b7a2e495071c5e8312be2f9364d407a3166e242af628ddc', NOW()),
  ('0003', '0003_schema_migrations_and_sessions_pk.sql', '3b42d9777bf721695db2d1b4f24134e9d0c786bffe9593907d8f5299f375c045', NOW()),
  ('0004', '0004_reconcile_columns_and_tables.sql', '44d0a40e52d57e7939e5399ac8049c734e0c7e2a73c2e9e0e6ec96d4f9d2abdf', NOW()),
  ('0005', '0005_unique_constraints_and_indexes.sql', 'e99e3aca0732b08abbbd8d1e794825132c4f9a113e6e2bd6e539461df7821f1b', NOW()),
  ('0006', '0006_foreign_keys_integrity.sql', 'e2fb6e00b0bc193cffd5750de1bd1f5dd5cf98cd775e4e93a84921951637107e', NOW()),
  ('0007', '0007_seed_master_data_and_checklists.sql', 'dc0ab3217d181b6d26fccd8d27f8390dd09c51bee37ac57fafc20b7ebb10324a', NOW())
ON DUPLICATE KEY UPDATE `filename` = VALUES(`filename`), `checksum` = VALUES(`checksum`);

COMMIT;
