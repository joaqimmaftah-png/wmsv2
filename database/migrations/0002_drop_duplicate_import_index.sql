-- K-20: `material_transactions.import_key` carried two UNIQUE indexes on the
-- same column (uq_mt_import_key and uq_txn_import_key), so every insert
-- maintained the same index twice. The remaining index enforces exactly the
-- same uniqueness.
--
-- Fresh installations get the single index from database/schema.sql.

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'material_transactions'
      AND INDEX_NAME   = 'uq_txn_import_key'
);

SET @ddl := IF(@idx_exists > 0,
    'ALTER TABLE `material_transactions` DROP INDEX `uq_txn_import_key`',
    'SELECT ''uq_txn_import_key already absent'' AS migration_note'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
