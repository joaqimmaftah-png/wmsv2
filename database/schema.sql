-- ---------------------------------------------------------------------------
-- Workshop Management System — schema and reference data
--
-- Creates the 59 tables, their indexes and foreign keys, and seeds only the
-- master data the application needs to start: roles, equipment types,
-- lubricant types, report groups/sites, shift definitions and settings.
--
-- It contains NO staff accounts, NO password hashes, NO session rows, NO
-- equipment, NO suppliers, NO stock movements and NO inspection records --
-- those stay in the production database (and its backups) only.
-- Create the first supervisor after importing (README, step 6) and add the
-- rest through the UI.
--
-- Import:   mysql -u USER -p DATABASE < database/schema.sql
-- ---------------------------------------------------------------------------

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;


-- --------------------------------------------------------

--
-- Table structure for table `asset_import_logs`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `backlogs`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `certifications`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `ci_sessions`
--

CREATE TABLE `ci_sessions` (
  `id` varchar(128) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `timestamp` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `data` blob NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `equipment_kpi_targets`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `equipment_meter_readings`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `equipment_planned_downtime`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `equipment_specs`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `equipment_types`
--

CREATE TABLE `equipment_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `equipment_types`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `excel_migration_log`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `forecast_snapshots`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `goods_receipts`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `goods_receipt_lines`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `incident_details`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `inspection_items`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `inspection_records`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `inspection_record_items`
--

CREATE TABLE `inspection_record_items` (
  `id` int(11) NOT NULL,
  `record_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `value_json` text DEFAULT NULL,
  `comment` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inspection_templates`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `inventory_import_logs`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `kpi_log`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL COMMENT 'lower-cased address the attempt was made for',
  `ip_address` varchar(45) NOT NULL COMMENT 'IPv4 or IPv6 of the client',
  `attempted_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lube_budget`
--

CREATE TABLE `lube_budget` (
  `id` int(11) NOT NULL,
  `lube_type_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `budget_qty` decimal(10,2) DEFAULT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `budget_cost` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lube_consumption`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `lube_specifications`
--

CREATE TABLE `lube_specifications` (
  `id` int(11) NOT NULL,
  `equipment_type_id` int(11) NOT NULL,
  `lube_type_id` int(11) NOT NULL,
  `recommended_qty` decimal(10,2) NOT NULL,
  `service_interval_hours` int(11) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lube_types`
--

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

--
-- Dumping data for table `lube_types`
--

INSERT INTO `lube_types` (`id`, `name`, `category`, `grade`, `unit`, `unit_cost`, `description`, `status`) VALUES
(1, 'Engine Oil', 'Engine Oil', '15W-40', 'L', '520.00', NULL, 'Active'),
(2, 'Hydraulic Oil', 'Hydraulic Oil', 'ISO 46', 'L', '680.00', NULL, 'Active'),
(3, 'Gear Oil', 'Differential Oil', '80W-90', 'L', '590.00', NULL, 'Active'),
(4, 'Grease', 'Grease', 'NLGI 2', 'Kg', '1100.00', NULL, 'Active'),
(5, 'Coolant', 'Coolant', '50/50', 'L', '340.00', NULL, 'Active'),
(6, 'Transmission Oil', 'Transmission Oil', 'ATF Dex', 'L', '750.00', NULL, 'Active'),
(7, 'Compressor Oil', 'Compressor Oil', 'ISO 100', 'L', '820.00', NULL, 'Active');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_plan_jobs`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `materials`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `material_transactions`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `order_documents`
--

CREATE TABLE `order_documents` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `doc_type` varchar(50) DEFAULT 'Delivery Note',
  `file_name` varchar(200) NOT NULL,
  `original_name` varchar(200) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_status_history`
--

CREATE TABLE `order_status_history` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `status` varchar(50) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pm_completions`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `pm_part_requirements`
--

CREATE TABLE `pm_part_requirements` (
  `id` int(11) NOT NULL,
  `pm_schedule_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000 COMMENT 'consumed per ONE occurrence of the schedule',
  `is_critical` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'the PM cannot proceed without it',
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Spare parts consumed by one occurrence of a PM schedule (FORECAST_REVIEW task F10)';

-- --------------------------------------------------------

--
-- Table structure for table `pm_schedules`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `report_groups`
--

CREATE TABLE `report_groups` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL COMMENT 'exactly as printed on sheet W36',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='W36 report groups (order and labels as printed on the sheet)';

--
-- Dumping data for table `report_groups`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `report_sites`
--

CREATE TABLE `report_sites` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL COMMENT 'stable key stored in equipment.location',
  `label` varchar(100) NOT NULL COMMENT 'what the report prints',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `report_sites`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'Supervisor', 'Full access — all modules, user management, settings'),
(2, 'Technician', 'Work orders, attendance, own assignments'),
(3, 'Store', 'Lubricants, spare parts, inventory management'),
(4, 'Manager', 'View-only: dashboard, reports and KPIs'),
(5, 'CEO', 'Executive view — high-level KPIs and strategic reports only');

-- --------------------------------------------------------

--
-- Table structure for table `security_events`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(300) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `description`) VALUES
(1, 'company_name', 'A&ML', 'Company / Department name'),
(2, 'working_hours_per_day', '10', 'Standard working hours per day'),
(3, 'work_days_per_month', '26', 'Working days per month'),
(4, 'currency', 'USD', 'Currency code'),
(5, 'pm_alert_days', '7', 'Days before PM due to show alert'),
(6, 'cert_alert_days', '30', 'Days before cert expiry to show alert'),
(7, 'weekly_open_hours', '168', 'Open hours per 7-day reporting week'),
(8, 'daily_open_hours', '24', 'Open hours per day'),
(9, 'default_availability_target', '0.88', 'Fallback availability target when neither the equipment nor the group has one'),
(10, 'report_week_start_day', '1', '1 = Monday (ISO week)'),
(11, 'breakdown_count_mode', 'start_in_period', 'start_in_period = count breakdown events that START inside the week (recommended); overlapping = count every event that overlaps the week'),
(12, 'mtbf_zero_failure_mode', 'dash', 'dash = show \"-\" for MTBF/MTTR when a machine had no breakdown in the period; uptime = show uptime as MTBF'),
(13, 'pm_meter_stale_days', '30', 'G4-stale: a PM schedule with no due date is judged by its meter alone, so it is NOT JUDGED when its newest reading is older than this many days before the reported week (0 = judge from the equipment record alone, as before)'),
(14, 'standing_allowance_hours', '{}', 'D31: planned standing downtime per DAY as JSON keyed by equipment-type name, e.g. {\"Excavator\":2,\"Dozer\":2}. Empty object = every type uses the default below.'),
(15, 'standing_allowance_default_hours', '2', 'D31: fallback standing downtime per day (hours) for any equipment type not listed in standing_allowance_hours'),
(16, 'unplanned_allowance_mode', 'flat', 'flat = use unplanned_allowance_default_hours for every machine; derived = per-machine allowance from its own MTBF/MTTR (FORECAST_REVIEW task F9)'),
(17, 'unplanned_allowance_default_hours', '1', 'flat mode: planned breakdown allowance per day (hours). The historical workbook typed 1 h/day on 85 of 103 machines.'),
(18, 'unplanned_allowance_lookback_weeks', '12', 'derived mode: how many weeks of history the per-machine MTBF/MTTR allowance is computed from'),
(19, 'forecast_show_excel_reference', '1', 'D29/D30: 1 = also show the historical Excel figures (24-hour calendar availability and the unweighted group mean) as labelled reference columns; 0 = system figures only');

-- --------------------------------------------------------

--
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `description` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `shifts`
--

INSERT INTO `shifts` (`id`, `name`, `start_time`, `end_time`, `description`) VALUES
(1, 'Day Shift', '06:00:00', '18:00:00', '12-hour day rotation'),
(2, 'Night Shift', '18:00:00', '06:00:00', '12-hour night rotation'),
(3, 'Office', '08:00:00', '17:00:00', 'Standard office hours');

-- --------------------------------------------------------

--
-- Table structure for table `shift_assignments`
--

CREATE TABLE `shift_assignments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `week_start` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `spare_orders`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `spare_order_items`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `spare_part_alerts`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `supplier_contacts`
--

CREATE TABLE `supplier_contacts` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` varchar(80) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_materials`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `weekly_report_names`
--

CREATE TABLE `weekly_report_names` (
  `id` int(11) NOT NULL,
  `week_start` date NOT NULL,
  `report_name` varchar(22) NOT NULL,
  `updated_by` int(11) DEFAULT NULL COMMENT 'users.id',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dashboard-controlled display and workbook name for each report week';

-- --------------------------------------------------------

--
-- Table structure for table `weekly_report_snapshots`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `work_orders`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `wo_attachments`
--

CREATE TABLE `wo_attachments` (
  `id` int(11) NOT NULL,
  `wo_id` int(11) NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'Incident Evidence',
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wo_oil_records`
--

CREATE TABLE `wo_oil_records` (
  `id` int(11) NOT NULL,
  `work_order_id` int(11) NOT NULL,
  `system_location` varchar(150) DEFAULT NULL,
  `oil_type` varchar(150) DEFAULT NULL,
  `grade` varchar(80) DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wo_sessions`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `wo_spare_allocations`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `wo_spare_parts`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `wo_status_history`
--

CREATE TABLE `wo_status_history` (
  `id` int(11) NOT NULL,
  `work_order_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `changed_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `asset_import_logs`
--
ALTER TABLE `asset_import_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`user_id`,`date`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `backlogs`
--
ALTER TABLE `backlogs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_backlog_source_ref` (`source_ref`),
  ADD KEY `idx_backlog_equipment` (`equipment_id`),
  ADD KEY `idx_backlog_status` (`status`),
  ADD KEY `idx_backlog_start` (`start_date`),
  ADD KEY `idx_backlog_priority` (`priority`),
  ADD KEY `idx_backlog_insp_item` (`inspection_item_id`,`equipment_id`),
  ADD KEY `idx_backlog_equipment_status` (`equipment_id`,`status`);

--
-- Indexes for table `certifications`
--
ALTER TABLE `certifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `ci_sessions`
--
ALTER TABLE `ci_sessions`
  ADD PRIMARY KEY (`id`,`ip_address`),
  ADD KEY `ci_sessions_timestamp` (`timestamp`);

--
-- Indexes for table `equipment`
--
ALTER TABLE `equipment`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `registration_no` (`registration_no`),
  ADD KEY `equipment_type_id` (`equipment_type_id`),
  ADD KEY `idx_equipment_legacy` (`legacy_code`),
  ADD KEY `idx_equipment_frame` (`frame_no`),
  ADD KEY `idx_equipment_mtype` (`machine_type`),
  ADD KEY `idx_equipment_report` (`include_in_weekly_report`,`report_group`,`report_sort`);

--
-- Indexes for table `equipment_kpi_targets`
--
ALTER TABLE `equipment_kpi_targets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_target_eq` (`scope`,`equipment_id`,`target_date`),
  ADD KEY `idx_target_grp` (`scope`,`group_code`,`target_date`);

--
-- Indexes for table `equipment_meter_readings`
--
ALTER TABLE `equipment_meter_readings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_meter_eq_date` (`equipment_id`,`reading_date`),
  ADD KEY `idx_meter_date` (`reading_date`);

--
-- Indexes for table `equipment_planned_downtime`
--
ALTER TABLE `equipment_planned_downtime`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_planned_dt_machine_day_cat` (`equipment_id`,`plan_date`,`category`),
  ADD KEY `idx_planned_dt_date` (`plan_date`),
  ADD KEY `idx_planned_dt_category` (`category`);

--
-- Indexes for table `equipment_specs`
--
ALTER TABLE `equipment_specs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_equipment_spec` (`equipment_id`,`spec_key`);

--
-- Indexes for table `equipment_types`
--
ALTER TABLE `equipment_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `excel_migration_log`
--
ALTER TABLE `excel_migration_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_excel_batch` (`batch`);

--
-- Indexes for table `forecast_snapshots`
--
ALTER TABLE `forecast_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_forecast_snapshot_week_rev` (`week_start`,`revision`),
  ADD KEY `idx_forecast_snapshot_week` (`week_start`);

--
-- Indexes for table `goods_receipts`
--
ALTER TABLE `goods_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_grn_no` (`grn_no`),
  ADD UNIQUE KEY `uq_grn_submit_token` (`submit_token`),
  ADD KEY `idx_grn_order` (`order_id`,`receipt_date`),
  ADD KEY `idx_grn_date` (`receipt_date`);

--
-- Indexes for table `goods_receipt_lines`
--
ALTER TABLE `goods_receipt_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_grl_receipt` (`receipt_id`),
  ADD KEY `idx_grl_item` (`order_item_id`),
  ADD KEY `idx_grl_material` (`material_id`),
  ADD KEY `idx_grl_movement` (`movement_id`);

--
-- Indexes for table `incident_details`
--
ALTER TABLE `incident_details`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `wo_id` (`wo_id`);

--
-- Indexes for table `inspection_items`
--
ALTER TABLE `inspection_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `template_id` (`template_id`);

--
-- Indexes for table `inspection_records`
--
ALTER TABLE `inspection_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `template_id` (`template_id`),
  ADD KEY `equipment_id` (`equipment_id`);

--
-- Indexes for table `inspection_record_items`
--
ALTER TABLE `inspection_record_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `record_id` (`record_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `inspection_templates`
--
ALTER TABLE `inspection_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `inventory_import_logs`
--
ALTER TABLE `inventory_import_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `kpi_log`
--
ALTER TABLE `kpi_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `equipment_id` (`equipment_id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_login_attempts_pair` (`email`,`ip_address`,`attempted_at`),
  ADD KEY `idx_login_attempts_account` (`email`,`attempted_at`),
  ADD KEY `idx_login_attempts_prune` (`attempted_at`),
  ADD KEY `idx_login_attempts_source` (`ip_address`,`attempted_at`);

--
-- Indexes for table `lube_budget`
--
ALTER TABLE `lube_budget`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_budget` (`lube_type_id`,`year`,`month`);

--
-- Indexes for table `lube_consumption`
--
ALTER TABLE `lube_consumption`
  ADD PRIMARY KEY (`id`),
  ADD KEY `work_order_id` (`work_order_id`),
  ADD KEY `equipment_id` (`equipment_id`),
  ADD KEY `lube_type_id` (`lube_type_id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `lube_specifications`
--
ALTER TABLE `lube_specifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `equipment_type_id` (`equipment_type_id`),
  ADD KEY `lube_type_id` (`lube_type_id`);

--
-- Indexes for table `lube_types`
--
ALTER TABLE `lube_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `maintenance_plan_jobs`
--
ALTER TABLE `maintenance_plan_jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mpj_equipment_dates` (`equipment_id`,`start_date`,`end_date`),
  ADD KEY `idx_mpj_dates` (`start_date`,`end_date`);

--
-- Indexes for table `materials`
--
ALTER TABLE `materials`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `item_code` (`item_code`),
  ADD KEY `idx_materials_category` (`category`),
  ADD KEY `idx_materials_supplier` (`supplier_id`),
  ADD KEY `idx_materials_final` (`final_qty`);

--
-- Indexes for table `material_transactions`
--
ALTER TABLE `material_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mt_import_key` (`import_key`),
  ADD KEY `material_id` (`material_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_mt_date` (`txn_date`),
  ADD KEY `idx_mt_type_date` (`type`,`txn_date`),
  ADD KEY `idx_mt_equipment` (`equipment_id`),
  ADD KEY `idx_mt_ref` (`reference_no`);

--
-- Indexes for table `order_documents`
--
ALTER TABLE `order_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `pm_completions`
--
ALTER TABLE `pm_completions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pm_schedule_id` (`pm_schedule_id`),
  ADD KEY `work_order_id` (`work_order_id`);

--
-- Indexes for table `pm_part_requirements`
--
ALTER TABLE `pm_part_requirements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pm_part` (`pm_schedule_id`,`material_id`),
  ADD KEY `idx_pm_part_material` (`material_id`);

--
-- Indexes for table `pm_schedules`
--
ALTER TABLE `pm_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `equipment_id` (`equipment_id`);

--
-- Indexes for table `report_groups`
--
ALTER TABLE `report_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_report_group_code` (`code`);

--
-- Indexes for table `report_sites`
--
ALTER TABLE `report_sites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_report_site_code` (`code`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `security_events`
--
ALTER TABLE `security_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_events_type` (`event_type`,`created_at`),
  ADD KEY `idx_security_events_open` (`acknowledged_at`,`created_at`),
  ADD KEY `idx_security_events_subj` (`subject`,`created_at`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `shift_assignments`
--
ALTER TABLE `shift_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `shift_id` (`shift_id`);

--
-- Indexes for table `spare_orders`
--
ALTER TABLE `spare_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_no` (`order_no`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `spare_order_items`
--
ALTER TABLE `spare_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `spare_part_alerts`
--
ALTER TABLE `spare_part_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wo` (`work_order_id`),
  ADD KEY `idx_material` (`material_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `supplier_contacts`
--
ALTER TABLE `supplier_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `supplier_materials`
--
ALTER TABLE `supplier_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `role_id` (`role_id`);

--
-- Indexes for table `weekly_report_names`
--
ALTER TABLE `weekly_report_names`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_weekly_report_name_week` (`week_start`);

--
-- Indexes for table `weekly_report_snapshots`
--
ALTER TABLE `weekly_report_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_snapshot_week` (`week_start`);

--
-- Indexes for table `work_orders`
--
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

--
-- Indexes for table `wo_attachments`
--
ALTER TABLE `wo_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wo_attachments_wo_id` (`wo_id`),
  ADD KEY `idx_wo_attachments_uploaded_by` (`uploaded_by`);

--
-- Indexes for table `wo_oil_records`
--
ALTER TABLE `wo_oil_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `work_order_id` (`work_order_id`);

--
-- Indexes for table `wo_sessions`
--
ALTER TABLE `wo_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wo` (`wo_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_switched_to` (`switched_to_wo_id`);

--
-- Indexes for table `wo_spare_allocations`
--
ALTER TABLE `wo_spare_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wo_part` (`wo_spare_part_id`),
  ADD KEY `idx_wo` (`work_order_id`),
  ADD KEY `idx_material` (`material_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `wo_spare_parts`
--
ALTER TABLE `wo_spare_parts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `work_order_id` (`work_order_id`);

--
-- Indexes for table `wo_status_history`
--
ALTER TABLE `wo_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `work_order_id` (`work_order_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `asset_import_logs`
--
ALTER TABLE `asset_import_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `backlogs`
--
ALTER TABLE `backlogs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `certifications`
--
ALTER TABLE `certifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `equipment`
--
ALTER TABLE `equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=175;

--
-- AUTO_INCREMENT for table `equipment_kpi_targets`
--
ALTER TABLE `equipment_kpi_targets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `equipment_meter_readings`
--
ALTER TABLE `equipment_meter_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `equipment_planned_downtime`
--
ALTER TABLE `equipment_planned_downtime`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `equipment_specs`
--
ALTER TABLE `equipment_specs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=512;

--
-- AUTO_INCREMENT for table `equipment_types`
--
ALTER TABLE `equipment_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `excel_migration_log`
--
ALTER TABLE `excel_migration_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `forecast_snapshots`
--
ALTER TABLE `forecast_snapshots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `goods_receipts`
--
ALTER TABLE `goods_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `goods_receipt_lines`
--
ALTER TABLE `goods_receipt_lines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `incident_details`
--
ALTER TABLE `incident_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inspection_items`
--
ALTER TABLE `inspection_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=631;

--
-- AUTO_INCREMENT for table `inspection_records`
--
ALTER TABLE `inspection_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inspection_record_items`
--
ALTER TABLE `inspection_record_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inspection_templates`
--
ALTER TABLE `inspection_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `inventory_import_logs`
--
ALTER TABLE `inventory_import_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kpi_log`
--
ALTER TABLE `kpi_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lube_budget`
--
ALTER TABLE `lube_budget`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lube_consumption`
--
ALTER TABLE `lube_consumption`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lube_specifications`
--
ALTER TABLE `lube_specifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lube_types`
--
ALTER TABLE `lube_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `maintenance_plan_jobs`
--
ALTER TABLE `maintenance_plan_jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `materials`
--
ALTER TABLE `materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1744;

--
-- AUTO_INCREMENT for table `material_transactions`
--
ALTER TABLE `material_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16384;

--
-- AUTO_INCREMENT for table `order_documents`
--
ALTER TABLE `order_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_status_history`
--
ALTER TABLE `order_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pm_completions`
--
ALTER TABLE `pm_completions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pm_part_requirements`
--
ALTER TABLE `pm_part_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pm_schedules`
--
ALTER TABLE `pm_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `report_groups`
--
ALTER TABLE `report_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `report_sites`
--
ALTER TABLE `report_sites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `security_events`
--
ALTER TABLE `security_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `shift_assignments`
--
ALTER TABLE `shift_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `spare_orders`
--
ALTER TABLE `spare_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `spare_order_items`
--
ALTER TABLE `spare_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `spare_part_alerts`
--
ALTER TABLE `spare_part_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

--
-- AUTO_INCREMENT for table `supplier_contacts`
--
ALTER TABLE `supplier_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_materials`
--
ALTER TABLE `supplier_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT for table `weekly_report_names`
--
ALTER TABLE `weekly_report_names`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `weekly_report_snapshots`
--
ALTER TABLE `weekly_report_snapshots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `work_orders`
--
ALTER TABLE `work_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `wo_attachments`
--
ALTER TABLE `wo_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wo_oil_records`
--
ALTER TABLE `wo_oil_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wo_sessions`
--
ALTER TABLE `wo_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `wo_spare_allocations`
--
ALTER TABLE `wo_spare_allocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wo_spare_parts`
--
ALTER TABLE `wo_spare_parts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wo_status_history`
--
ALTER TABLE `wo_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `backlogs`
--
ALTER TABLE `backlogs`
  ADD CONSTRAINT `fk_backlog_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `certifications`
--
ALTER TABLE `certifications`
  ADD CONSTRAINT `certifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `equipment`
--
ALTER TABLE `equipment`
  ADD CONSTRAINT `equipment_ibfk_1` FOREIGN KEY (`equipment_type_id`) REFERENCES `equipment_types` (`id`);

--
-- Constraints for table `equipment_planned_downtime`
--
ALTER TABLE `equipment_planned_downtime`
  ADD CONSTRAINT `fk_planned_dt_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `equipment_specs`
--
ALTER TABLE `equipment_specs`
  ADD CONSTRAINT `fk_equipment_specs_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `incident_details`
--
ALTER TABLE `incident_details`
  ADD CONSTRAINT `fk_incident_wo` FOREIGN KEY (`wo_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inspection_items`
--
ALTER TABLE `inspection_items`
  ADD CONSTRAINT `fk_insp_items_template` FOREIGN KEY (`template_id`) REFERENCES `inspection_templates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inspection_records`
--
ALTER TABLE `inspection_records`
  ADD CONSTRAINT `fk_insp_records_template` FOREIGN KEY (`template_id`) REFERENCES `inspection_templates` (`id`);

--
-- Constraints for table `inspection_record_items`
--
ALTER TABLE `inspection_record_items`
  ADD CONSTRAINT `fk_insp_record_items_item` FOREIGN KEY (`item_id`) REFERENCES `inspection_items` (`id`),
  ADD CONSTRAINT `fk_insp_record_items_record` FOREIGN KEY (`record_id`) REFERENCES `inspection_records` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `kpi_log`
--
ALTER TABLE `kpi_log`
  ADD CONSTRAINT `kpi_log_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`);

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `leave_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `leave_requests_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `lube_budget`
--
ALTER TABLE `lube_budget`
  ADD CONSTRAINT `lube_budget_ibfk_1` FOREIGN KEY (`lube_type_id`) REFERENCES `lube_types` (`id`);

--
-- Constraints for table `lube_consumption`
--
ALTER TABLE `lube_consumption`
  ADD CONSTRAINT `lube_consumption_ibfk_1` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`),
  ADD CONSTRAINT `lube_consumption_ibfk_2` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  ADD CONSTRAINT `lube_consumption_ibfk_3` FOREIGN KEY (`lube_type_id`) REFERENCES `lube_types` (`id`),
  ADD CONSTRAINT `lube_consumption_ibfk_4` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `lube_specifications`
--
ALTER TABLE `lube_specifications`
  ADD CONSTRAINT `lube_specifications_ibfk_1` FOREIGN KEY (`equipment_type_id`) REFERENCES `equipment_types` (`id`),
  ADD CONSTRAINT `lube_specifications_ibfk_2` FOREIGN KEY (`lube_type_id`) REFERENCES `lube_types` (`id`);

--
-- Constraints for table `maintenance_plan_jobs`
--
ALTER TABLE `maintenance_plan_jobs`
  ADD CONSTRAINT `fk_mpj_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `material_transactions`
--
ALTER TABLE `material_transactions`
  ADD CONSTRAINT `material_transactions_ibfk_1` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `material_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `pm_completions`
--
ALTER TABLE `pm_completions`
  ADD CONSTRAINT `pm_completions_ibfk_1` FOREIGN KEY (`pm_schedule_id`) REFERENCES `pm_schedules` (`id`),
  ADD CONSTRAINT `pm_completions_ibfk_2` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`);

--
-- Constraints for table `pm_part_requirements`
--
ALTER TABLE `pm_part_requirements`
  ADD CONSTRAINT `fk_pm_part_schedule` FOREIGN KEY (`pm_schedule_id`) REFERENCES `pm_schedules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pm_schedules`
--
ALTER TABLE `pm_schedules`
  ADD CONSTRAINT `pm_schedules_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`);

--
-- Constraints for table `shift_assignments`
--
ALTER TABLE `shift_assignments`
  ADD CONSTRAINT `shift_assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `shift_assignments_ibfk_2` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

--
-- Constraints for table `work_orders`
--
ALTER TABLE `work_orders`
  ADD CONSTRAINT `fk_wo_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `work_orders_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  ADD CONSTRAINT `work_orders_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `wo_attachments`
--
ALTER TABLE `wo_attachments`
  ADD CONSTRAINT `fk_wo_attachments_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_wo_attachments_work_order` FOREIGN KEY (`wo_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `wo_oil_records`
--
ALTER TABLE `wo_oil_records`
  ADD CONSTRAINT `wo_oil_records_ibfk_1` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`);

--
-- Constraints for table `wo_sessions`
--
ALTER TABLE `wo_sessions`
  ADD CONSTRAINT `fk_session_switched_to` FOREIGN KEY (`switched_to_wo_id`) REFERENCES `work_orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_session_wo` FOREIGN KEY (`wo_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `wo_spare_parts`
--
ALTER TABLE `wo_spare_parts`
  ADD CONSTRAINT `wo_spare_parts_ibfk_1` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`);

--
-- Constraints for table `wo_status_history`
--
ALTER TABLE `wo_status_history`
  ADD CONSTRAINT `wo_status_history_ibfk_1` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
