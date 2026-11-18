-- 0019: Supervisor-approved cover for a published sick worker's dated Shift.
-- Additive. One current assignment per absent worker/date; a cancelled row may
-- be reassigned, with every transition retained in workforce_plan_events.
-- Candidate/date double-booking is serialized by the annual year row FOR UPDATE.
-- An Off-day candidate also has a separate, authorised extra-work exception;
-- this table never changes their original Off plan or creates attendance.
CREATE TABLE IF NOT EXISTS `workforce_plan_cover` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `absent_user_id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `shift_id` int(11) NOT NULL COMMENT 'Original shift under scheduled sick leave',
  `cover_user_id` int(11) NOT NULL,
  `cover_mode` enum('OnShift','OffCallIn') NOT NULL,
  `extra_day_id` bigint(20) DEFAULT NULL COMMENT 'Authorised Off-day exception, if applicable',
  `extra_created_for_cover` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('Assigned','Cancelled') NOT NULL DEFAULT 'Assigned',
  `reason` varchar(255) NOT NULL,
  `availability_confirmed_at` datetime DEFAULT NULL COMMENT 'Supervisor attested Off-day worker agreed to the call-in',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime NOT NULL DEFAULT current_timestamp(),
  `cancel_reason` varchar(255) DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wpc_absent_date` (`absent_user_id`,`work_date`),
  KEY `idx_wpc_cover_date` (`cover_user_id`,`work_date`,`status`),
  KEY `idx_wpc_date_status` (`work_date`,`status`),
  KEY `fk_wpc_shift` (`shift_id`),
  KEY `fk_wpc_extra` (`extra_day_id`),
  KEY `fk_wpc_approved_by` (`approved_by`),
  KEY `fk_wpc_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_wpc_absent` FOREIGN KEY (`absent_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_wpc_cover` FOREIGN KEY (`cover_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_wpc_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`),
  CONSTRAINT `fk_wpc_extra` FOREIGN KEY (`extra_day_id`) REFERENCES `workforce_plan_extra_days` (`id`),
  CONSTRAINT `fk_wpc_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wpc_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
