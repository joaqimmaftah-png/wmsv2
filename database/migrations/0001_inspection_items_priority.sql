-- K-16: an explicit severity on each checklist row.
--
-- Fresh installations get this column from database/schema.sql. This file is
-- for a database created before the change (typically the production dump).
--
-- Idempotent on purpose: the runner records applied files, but DDL is not
-- transactional, so re-running a file after a partial failure must be safe.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'inspection_items'
      AND COLUMN_NAME  = 'priority'
);

SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `inspection_items` ADD COLUMN `priority` ENUM(''P1'',''P2'',''P3'') NULL DEFAULT NULL COMMENT ''Explicit defect severity; NULL = derive from the category'' AFTER `na_columns`',
    'SELECT ''inspection_items.priority already exists'' AS migration_note'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
