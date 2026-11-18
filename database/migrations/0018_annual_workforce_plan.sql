-- 0018: Annual workforce planning. Additive: historical weekly assignments,
-- leave requests, attendance and immutable punch events are never rewritten.
-- Re-runnable by --force on MySQL 8 / MariaDB 10.4 (CREATE IF NOT EXISTS,
-- INSERT IGNORE). The annual plan becomes live only after publication.

CREATE TABLE IF NOT EXISTS `workforce_plan_years` (
  `plan_year` smallint(5) unsigned NOT NULL,
  `status` enum('Draft','Published') NOT NULL DEFAULT 'Draft',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`plan_year`),
  KEY `fk_wpy_created_by` (`created_by`),
  KEY `fk_wpy_updated_by` (`updated_by`),
  KEY `fk_wpy_published_by` (`published_by`),
  CONSTRAINT `fk_wpy_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wpy_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wpy_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only an explicit shift has a shift_id. An off day is a planned state, never
-- the absence of a row or a fake shift with arbitrary start/end times.
CREATE TABLE IF NOT EXISTS `workforce_plan_days` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `day_type` enum('Shift','Off','Holiday') NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `source` enum('Pattern','Manual','Legacy') NOT NULL DEFAULT 'Manual',
  `reason` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wpd_worker_date` (`user_id`,`work_date`),
  KEY `idx_wpd_date_type` (`work_date`,`day_type`),
  KEY `fk_wpd_shift` (`shift_id`),
  KEY `fk_wpd_actor` (`updated_by`),
  CONSTRAINT `fk_wpd_worker` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_wpd_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`),
  CONSTRAINT `fk_wpd_actor` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leave is a dated block, not 365 future *actual attendance* rows. A block
-- overlays a worker's base pattern; cancellation restores that underlying plan.
CREATE TABLE IF NOT EXISTS `workforce_plan_leaves` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `leave_type` varchar(40) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('Scheduled','Cancelled') NOT NULL DEFAULT 'Scheduled',
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wpl_worker_range` (`user_id`,`status`,`start_date`,`end_date`),
  KEY `idx_wpl_alerts` (`status`,`start_date`,`end_date`),
  KEY `fk_wpl_created_by` (`created_by`),
  KEY `fk_wpl_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_wpl_worker` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_wpl_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wpl_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Off remains the base plan. Extra work is a separate authorised exception:
-- Planned -> Confirmed (only with recorded attendance) -> Paid/Taken.
-- No pay amount is calculated: payroll must settle any Pay entry separately.
CREATE TABLE IF NOT EXISTS `workforce_plan_extra_days` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `shift_id` int(11) NOT NULL,
  `status` enum('Planned','Confirmed','Cancelled') NOT NULL DEFAULT 'Planned',
  `compensation` enum('TimeOff','Pay') NOT NULL,
  `settlement` enum('Open','Scheduled','Taken','Paid') NOT NULL DEFAULT 'Open',
  `replacement_date` date DEFAULT NULL,
  `replaced_shift_id` int(11) DEFAULT NULL COMMENT 'Shift displaced by a replacement off day; restored if rescheduled',
  `reason` varchar(255) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime NOT NULL DEFAULT current_timestamp(),
  `confirmed_by` int(11) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_hours` decimal(6,2) DEFAULT NULL COMMENT 'Snapshot of actual completed attendance hours',
  `payroll_reference` varchar(100) DEFAULT NULL COMMENT 'External payroll confirmation; WMS does not calculate pay amounts',
  `settled_by` int(11) DEFAULT NULL,
  `settled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wpe_worker_date` (`user_id`,`work_date`),
  UNIQUE KEY `uq_wpe_replacement` (`user_id`,`replacement_date`),
  KEY `idx_wpe_status` (`status`,`settlement`),
  KEY `fk_wpe_shift` (`shift_id`),
  KEY `fk_wpe_replaced_shift` (`replaced_shift_id`),
  KEY `fk_wpe_approved_by` (`approved_by`),
  KEY `fk_wpe_confirmed_by` (`confirmed_by`),
  KEY `fk_wpe_settled_by` (`settled_by`),
  CONSTRAINT `fk_wpe_worker` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_wpe_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`),
  CONSTRAINT `fk_wpe_replaced_shift` FOREIGN KEY (`replaced_shift_id`) REFERENCES `shifts` (`id`),
  CONSTRAINT `fk_wpe_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wpe_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wpe_settled_by` FOREIGN KEY (`settled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `workforce_plan_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `plan_year` smallint(5) unsigned DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `work_date` date DEFAULT NULL,
  `event_type` varchar(40) NOT NULL,
  `details` text DEFAULT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wpev_year` (`plan_year`,`id`),
  KEY `idx_wpev_worker` (`user_id`,`id`),
  KEY `fk_wpev_actor` (`actor_id`),
  CONSTRAINT `fk_wpev_worker` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wpev_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`,`setting_value`,`description`) VALUES
('roster_leave_notice_days','7','Days before published planned leave starts to show an in-app reminder (1-30)'),
('roster_return_notice_days','2','Days before return from published planned leave to show an in-app reminder (1-14)');
