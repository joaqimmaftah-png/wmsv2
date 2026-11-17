-- 0017: User blacklist + safe hard-delete support.
--
-- Why:
--   * `users.status` only had Active / Inactive / On Leave, so a supervisor
--     could only "deactivate" someone. There was no way to mark an account
--     as blacklisted (misconduct / security risk) — a distinct, stronger
--     state than Inactive, mirroring the existing `suppliers.status`
--     Blacklisted value.
--   * `Users::delete()` existed but silently downgraded to a deactivate, so
--     a supervisor could never remove a zero-history test/duplicate account.
--     Hard delete is now allowed ONLY for accounts with no referencing rows
--     (enforced in the controller, because most FKs to `users` are RESTRICT
--     and a raw DELETE would fail with an opaque SQL error).
--
-- What:
--   * Add 'Blacklisted' to the `users.status` enum.
--   * Add blacklist audit columns (who, when, why) so a blacklist decision
--     is attributable — the same reason suppliers keep a status history.
--   * `blacklisted_by` is a self-FK to `users` with ON DELETE SET NULL so
--     deleting the supervisor who blacklisted someone never orphans the row.
--
-- Applied once via the schema_migrations ledger (`php scripts/migrate.php`).
-- Like 0006, the constraint add is not re-runnable under --force; the ledger
-- is what prevents double application. The column adds use IF NOT EXISTS so
-- a partially-applied run can be retried up to the constraint statement.
-- NOTE: MySQL ENUM widening via MODIFY may rebuild the table; `users` is a
-- staff table (hundreds of rows), so this is instant in practice.

ALTER TABLE `users`
  MODIFY `status` enum('Active','Inactive','On Leave','Blacklisted') NOT NULL DEFAULT 'Active';

ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `blacklist_reason` varchar(255) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `blacklisted_by` int(11) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `blacklisted_at` datetime DEFAULT NULL;

-- Self-referential audit FK: who blacklisted this account.
-- SET NULL so removing that supervisor never blocks or cascades.
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_blacklisted_by` FOREIGN KEY (`blacklisted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
