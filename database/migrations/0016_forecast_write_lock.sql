-- 0016: Serialize forecast publication and plan/ledger mutations.
-- One InnoDB row deliberately protects every week, including multi-week jobs.
-- A transaction locks this row before its first consistent read and releases it
-- automatically on COMMIT, ROLLBACK or loss of the database connection.
CREATE TABLE IF NOT EXISTS `forecast_write_lock` (
  `lock_id` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`lock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `forecast_write_lock` (`lock_id`) VALUES (1);
