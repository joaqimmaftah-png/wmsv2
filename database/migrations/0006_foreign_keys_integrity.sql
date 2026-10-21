-- 0006: Enforce complete foreign-key referential integrity across all 59 operational tables.
-- Cleans orphaned nullable foreign IDs safely before adding InnoDB FOREIGN KEY constraints.

DELETE FROM `spare_orders` WHERE `supplier_id` NOT IN (SELECT `id` FROM `suppliers`);
UPDATE `spare_orders` SET `linked_wo_id` = NULL WHERE `linked_wo_id` IS NOT NULL AND `linked_wo_id` NOT IN (SELECT `id` FROM `work_orders`);
UPDATE `spare_orders` SET `requested_by` = NULL WHERE `requested_by` IS NOT NULL AND `requested_by` NOT IN (SELECT `id` FROM `users`);
DELETE FROM `spare_order_items` WHERE `order_id` NOT IN (SELECT `id` FROM `spare_orders`);
UPDATE `spare_order_items` SET `material_id` = NULL WHERE `material_id` IS NOT NULL AND `material_id` NOT IN (SELECT `id` FROM `materials`);
DELETE FROM `order_documents` WHERE `order_id` NOT IN (SELECT `id` FROM `spare_orders`);
DELETE FROM `order_status_history` WHERE `order_id` NOT IN (SELECT `id` FROM `spare_orders`);
DELETE FROM `goods_receipts` WHERE `order_id` NOT IN (SELECT `id` FROM `spare_orders`);
UPDATE `goods_receipts` SET `received_by` = NULL WHERE `received_by` IS NOT NULL AND `received_by` NOT IN (SELECT `id` FROM `users`);
DELETE FROM `goods_receipt_lines` WHERE `receipt_id` NOT IN (SELECT `id` FROM `goods_receipts`);
DELETE FROM `goods_receipt_lines` WHERE `order_item_id` NOT IN (SELECT `id` FROM `spare_order_items`);
UPDATE `goods_receipt_lines` SET `material_id` = NULL WHERE `material_id` IS NOT NULL AND `material_id` NOT IN (SELECT `id` FROM `materials`);
UPDATE `goods_receipt_lines` SET `movement_id` = NULL WHERE `movement_id` IS NOT NULL AND `movement_id` NOT IN (SELECT `id` FROM `material_transactions`);
DELETE FROM `supplier_contacts` WHERE `supplier_id` NOT IN (SELECT `id` FROM `suppliers`);
DELETE FROM `supplier_materials` WHERE `supplier_id` NOT IN (SELECT `id` FROM `suppliers`);
UPDATE `supplier_materials` SET `material_id` = NULL WHERE `material_id` IS NOT NULL AND `material_id` NOT IN (SELECT `id` FROM `materials`);
DELETE FROM `wo_spare_allocations` WHERE `wo_spare_part_id` NOT IN (SELECT `id` FROM `wo_spare_parts`);
DELETE FROM `wo_spare_allocations` WHERE `work_order_id` NOT IN (SELECT `id` FROM `work_orders`);
DELETE FROM `wo_spare_allocations` WHERE `material_id` NOT IN (SELECT `id` FROM `materials`);
UPDATE `wo_spare_allocations` SET `allocated_by` = NULL WHERE `allocated_by` IS NOT NULL AND `allocated_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `spare_part_alerts` SET `wo_spare_part_id` = NULL WHERE `wo_spare_part_id` IS NOT NULL AND `wo_spare_part_id` NOT IN (SELECT `id` FROM `wo_spare_parts`);
DELETE FROM `spare_part_alerts` WHERE `work_order_id` NOT IN (SELECT `id` FROM `work_orders`);
UPDATE `spare_part_alerts` SET `material_id` = NULL WHERE `material_id` IS NOT NULL AND `material_id` NOT IN (SELECT `id` FROM `materials`);
UPDATE `spare_part_alerts` SET `received_by` = NULL WHERE `received_by` IS NOT NULL AND `received_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `spare_part_alerts` SET `read_by` = NULL WHERE `read_by` IS NOT NULL AND `read_by` NOT IN (SELECT `id` FROM `users`);
DELETE FROM `equipment_meter_readings` WHERE `equipment_id` NOT IN (SELECT `id` FROM `equipment`);
UPDATE `equipment_meter_readings` SET `work_order_id` = NULL WHERE `work_order_id` IS NOT NULL AND `work_order_id` NOT IN (SELECT `id` FROM `work_orders`);
UPDATE `equipment_meter_readings` SET `recorded_by` = NULL WHERE `recorded_by` IS NOT NULL AND `recorded_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `equipment_kpi_targets` SET `equipment_id` = NULL WHERE `equipment_id` IS NOT NULL AND `equipment_id` NOT IN (SELECT `id` FROM `equipment`);
UPDATE `equipment_kpi_targets` SET `created_by` = NULL WHERE `created_by` IS NOT NULL AND `created_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `equipment_planned_downtime` SET `created_by` = NULL WHERE `created_by` IS NOT NULL AND `created_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `forecast_snapshots` SET `published_by` = NULL WHERE `published_by` IS NOT NULL AND `published_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `weekly_report_snapshots` SET `published_by` = NULL WHERE `published_by` IS NOT NULL AND `published_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `weekly_report_names` SET `updated_by` = NULL WHERE `updated_by` IS NOT NULL AND `updated_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `maintenance_plan_jobs` SET `pm_schedule_id` = NULL WHERE `pm_schedule_id` IS NOT NULL AND `pm_schedule_id` NOT IN (SELECT `id` FROM `pm_schedules`);
UPDATE `maintenance_plan_jobs` SET `work_order_id` = NULL WHERE `work_order_id` IS NOT NULL AND `work_order_id` NOT IN (SELECT `id` FROM `work_orders`);
UPDATE `maintenance_plan_jobs` SET `backlog_id` = NULL WHERE `backlog_id` IS NOT NULL AND `backlog_id` NOT IN (SELECT `id` FROM `backlogs`);
UPDATE `maintenance_plan_jobs` SET `created_by` = NULL WHERE `created_by` IS NOT NULL AND `created_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `maintenance_plan_jobs` SET `updated_by` = NULL WHERE `updated_by` IS NOT NULL AND `updated_by` NOT IN (SELECT `id` FROM `users`);
DELETE FROM `pm_part_requirements` WHERE `material_id` NOT IN (SELECT `id` FROM `materials`);
UPDATE `materials` SET `supplier_id` = NULL WHERE `supplier_id` IS NOT NULL AND `supplier_id` NOT IN (SELECT `id` FROM `suppliers`);
UPDATE `material_transactions` SET `equipment_id` = NULL WHERE `equipment_id` IS NOT NULL AND `equipment_id` NOT IN (SELECT `id` FROM `equipment`);
UPDATE `material_transactions` SET `supplier_id` = NULL WHERE `supplier_id` IS NOT NULL AND `supplier_id` NOT IN (SELECT `id` FROM `suppliers`);
UPDATE `inspection_records` SET `equipment_id` = NULL WHERE `equipment_id` IS NOT NULL AND `equipment_id` NOT IN (SELECT `id` FROM `equipment`);
UPDATE `inspection_records` SET `created_by` = NULL WHERE `created_by` IS NOT NULL AND `created_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `backlogs` SET `work_order_id` = NULL WHERE `work_order_id` IS NOT NULL AND `work_order_id` NOT IN (SELECT `id` FROM `work_orders`);
UPDATE `backlogs` SET `inspection_item_id` = NULL WHERE `inspection_item_id` IS NOT NULL AND `inspection_item_id` NOT IN (SELECT `id` FROM `inspection_items`);
UPDATE `backlogs` SET `created_by` = NULL WHERE `created_by` IS NOT NULL AND `created_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `wo_sessions` SET `technician_id` = NULL WHERE `technician_id` IS NOT NULL AND `technician_id` NOT IN (SELECT `id` FROM `users`);
UPDATE `wo_sessions` SET `created_by` = NULL WHERE `created_by` IS NOT NULL AND `created_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `work_orders` SET `completed_by` = NULL WHERE `completed_by` IS NOT NULL AND `completed_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `work_orders` SET `verified_by` = NULL WHERE `verified_by` IS NOT NULL AND `verified_by` NOT IN (SELECT `id` FROM `users`);
UPDATE `work_orders` SET `operator_id` = NULL WHERE `operator_id` IS NOT NULL AND `operator_id` NOT IN (SELECT `id` FROM `users`);
UPDATE `asset_import_logs` SET `user_id` = NULL WHERE `user_id` IS NOT NULL AND `user_id` NOT IN (SELECT `id` FROM `users`);
UPDATE `inventory_import_logs` SET `user_id` = NULL WHERE `user_id` IS NOT NULL AND `user_id` NOT IN (SELECT `id` FROM `users`);

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_orders' AND CONSTRAINT_NAME = 'fk_so_supplier' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_orders` ADD CONSTRAINT `fk_so_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)', 'SELECT ''spare_orders.fk_so_supplier exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_orders' AND CONSTRAINT_NAME = 'fk_so_linked_wo' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_orders` ADD CONSTRAINT `fk_so_linked_wo` FOREIGN KEY (`linked_wo_id`) REFERENCES `work_orders` (`id`) ON DELETE SET NULL', 'SELECT ''spare_orders.fk_so_linked_wo exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_orders' AND CONSTRAINT_NAME = 'fk_so_requested_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_orders` ADD CONSTRAINT `fk_so_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''spare_orders.fk_so_requested_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_order_items' AND CONSTRAINT_NAME = 'fk_soi_order' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_order_items` ADD CONSTRAINT `fk_soi_order` FOREIGN KEY (`order_id`) REFERENCES `spare_orders` (`id`) ON DELETE CASCADE', 'SELECT ''spare_order_items.fk_soi_order exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_order_items' AND CONSTRAINT_NAME = 'fk_soi_material' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_order_items` ADD CONSTRAINT `fk_soi_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL', 'SELECT ''spare_order_items.fk_soi_material exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'order_documents' AND CONSTRAINT_NAME = 'fk_od_order' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `order_documents` ADD CONSTRAINT `fk_od_order` FOREIGN KEY (`order_id`) REFERENCES `spare_orders` (`id`) ON DELETE CASCADE', 'SELECT ''order_documents.fk_od_order exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'order_status_history' AND CONSTRAINT_NAME = 'fk_osh_order' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `order_status_history` ADD CONSTRAINT `fk_osh_order` FOREIGN KEY (`order_id`) REFERENCES `spare_orders` (`id`) ON DELETE CASCADE', 'SELECT ''order_status_history.fk_osh_order exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'goods_receipts' AND CONSTRAINT_NAME = 'fk_grn_order' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `goods_receipts` ADD CONSTRAINT `fk_grn_order` FOREIGN KEY (`order_id`) REFERENCES `spare_orders` (`id`) ON DELETE CASCADE', 'SELECT ''goods_receipts.fk_grn_order exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'goods_receipts' AND CONSTRAINT_NAME = 'fk_grn_received_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `goods_receipts` ADD CONSTRAINT `fk_grn_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''goods_receipts.fk_grn_received_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'goods_receipt_lines' AND CONSTRAINT_NAME = 'fk_grl_receipt' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `goods_receipt_lines` ADD CONSTRAINT `fk_grl_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE', 'SELECT ''goods_receipt_lines.fk_grl_receipt exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'goods_receipt_lines' AND CONSTRAINT_NAME = 'fk_grl_order_item' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `goods_receipt_lines` ADD CONSTRAINT `fk_grl_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `spare_order_items` (`id`) ON DELETE CASCADE', 'SELECT ''goods_receipt_lines.fk_grl_order_item exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'goods_receipt_lines' AND CONSTRAINT_NAME = 'fk_grl_material' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `goods_receipt_lines` ADD CONSTRAINT `fk_grl_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL', 'SELECT ''goods_receipt_lines.fk_grl_material exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'goods_receipt_lines' AND CONSTRAINT_NAME = 'fk_grl_movement' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `goods_receipt_lines` ADD CONSTRAINT `fk_grl_movement` FOREIGN KEY (`movement_id`) REFERENCES `material_transactions` (`id`) ON DELETE SET NULL', 'SELECT ''goods_receipt_lines.fk_grl_movement exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_contacts' AND CONSTRAINT_NAME = 'fk_sc_supplier' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `supplier_contacts` ADD CONSTRAINT `fk_sc_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE', 'SELECT ''supplier_contacts.fk_sc_supplier exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_materials' AND CONSTRAINT_NAME = 'fk_sm_supplier' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `supplier_materials` ADD CONSTRAINT `fk_sm_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE', 'SELECT ''supplier_materials.fk_sm_supplier exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_materials' AND CONSTRAINT_NAME = 'fk_sm_material' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `supplier_materials` ADD CONSTRAINT `fk_sm_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL', 'SELECT ''supplier_materials.fk_sm_material exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_spare_allocations' AND CONSTRAINT_NAME = 'fk_wsa_wo_part' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `wo_spare_allocations` ADD CONSTRAINT `fk_wsa_wo_part` FOREIGN KEY (`wo_spare_part_id`) REFERENCES `wo_spare_parts` (`id`) ON DELETE CASCADE', 'SELECT ''wo_spare_allocations.fk_wsa_wo_part exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_spare_allocations' AND CONSTRAINT_NAME = 'fk_wsa_wo' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `wo_spare_allocations` ADD CONSTRAINT `fk_wsa_wo` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE', 'SELECT ''wo_spare_allocations.fk_wsa_wo exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_spare_allocations' AND CONSTRAINT_NAME = 'fk_wsa_material' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `wo_spare_allocations` ADD CONSTRAINT `fk_wsa_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`)', 'SELECT ''wo_spare_allocations.fk_wsa_material exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_spare_allocations' AND CONSTRAINT_NAME = 'fk_wsa_allocated_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `wo_spare_allocations` ADD CONSTRAINT `fk_wsa_allocated_by` FOREIGN KEY (`allocated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''wo_spare_allocations.fk_wsa_allocated_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_part_alerts' AND CONSTRAINT_NAME = 'fk_spa_wo_part' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_part_alerts` ADD CONSTRAINT `fk_spa_wo_part` FOREIGN KEY (`wo_spare_part_id`) REFERENCES `wo_spare_parts` (`id`) ON DELETE SET NULL', 'SELECT ''spare_part_alerts.fk_spa_wo_part exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_part_alerts' AND CONSTRAINT_NAME = 'fk_spa_wo' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_part_alerts` ADD CONSTRAINT `fk_spa_wo` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE', 'SELECT ''spare_part_alerts.fk_spa_wo exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_part_alerts' AND CONSTRAINT_NAME = 'fk_spa_material' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_part_alerts` ADD CONSTRAINT `fk_spa_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL', 'SELECT ''spare_part_alerts.fk_spa_material exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_part_alerts' AND CONSTRAINT_NAME = 'fk_spa_received_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_part_alerts` ADD CONSTRAINT `fk_spa_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''spare_part_alerts.fk_spa_received_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_part_alerts' AND CONSTRAINT_NAME = 'fk_spa_read_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_part_alerts` ADD CONSTRAINT `fk_spa_read_by` FOREIGN KEY (`read_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''spare_part_alerts.fk_spa_read_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_meter_readings' AND CONSTRAINT_NAME = 'fk_emr_equipment' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `equipment_meter_readings` ADD CONSTRAINT `fk_emr_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE', 'SELECT ''equipment_meter_readings.fk_emr_equipment exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_meter_readings' AND CONSTRAINT_NAME = 'fk_emr_work_order' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `equipment_meter_readings` ADD CONSTRAINT `fk_emr_work_order` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE SET NULL', 'SELECT ''equipment_meter_readings.fk_emr_work_order exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_meter_readings' AND CONSTRAINT_NAME = 'fk_emr_recorded_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `equipment_meter_readings` ADD CONSTRAINT `fk_emr_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''equipment_meter_readings.fk_emr_recorded_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_kpi_targets' AND CONSTRAINT_NAME = 'fk_ekt_equipment' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `equipment_kpi_targets` ADD CONSTRAINT `fk_ekt_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE', 'SELECT ''equipment_kpi_targets.fk_ekt_equipment exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_kpi_targets' AND CONSTRAINT_NAME = 'fk_ekt_created_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `equipment_kpi_targets` ADD CONSTRAINT `fk_ekt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''equipment_kpi_targets.fk_ekt_created_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_planned_downtime' AND CONSTRAINT_NAME = 'fk_epd_created_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `equipment_planned_downtime` ADD CONSTRAINT `fk_epd_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''equipment_planned_downtime.fk_epd_created_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'forecast_snapshots' AND CONSTRAINT_NAME = 'fk_fs_published_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `forecast_snapshots` ADD CONSTRAINT `fk_fs_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''forecast_snapshots.fk_fs_published_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_report_snapshots' AND CONSTRAINT_NAME = 'fk_wrs_published_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `weekly_report_snapshots` ADD CONSTRAINT `fk_wrs_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''weekly_report_snapshots.fk_wrs_published_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_report_names' AND CONSTRAINT_NAME = 'fk_wrn_updated_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `weekly_report_names` ADD CONSTRAINT `fk_wrn_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''weekly_report_names.fk_wrn_updated_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_plan_jobs' AND CONSTRAINT_NAME = 'fk_mpj_pm_schedule' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `maintenance_plan_jobs` ADD CONSTRAINT `fk_mpj_pm_schedule` FOREIGN KEY (`pm_schedule_id`) REFERENCES `pm_schedules` (`id`) ON DELETE SET NULL', 'SELECT ''maintenance_plan_jobs.fk_mpj_pm_schedule exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_plan_jobs' AND CONSTRAINT_NAME = 'fk_mpj_work_order' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `maintenance_plan_jobs` ADD CONSTRAINT `fk_mpj_work_order` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE SET NULL', 'SELECT ''maintenance_plan_jobs.fk_mpj_work_order exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_plan_jobs' AND CONSTRAINT_NAME = 'fk_mpj_backlog' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `maintenance_plan_jobs` ADD CONSTRAINT `fk_mpj_backlog` FOREIGN KEY (`backlog_id`) REFERENCES `backlogs` (`id`) ON DELETE SET NULL', 'SELECT ''maintenance_plan_jobs.fk_mpj_backlog exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_plan_jobs' AND CONSTRAINT_NAME = 'fk_mpj_created_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `maintenance_plan_jobs` ADD CONSTRAINT `fk_mpj_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''maintenance_plan_jobs.fk_mpj_created_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_plan_jobs' AND CONSTRAINT_NAME = 'fk_mpj_updated_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `maintenance_plan_jobs` ADD CONSTRAINT `fk_mpj_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''maintenance_plan_jobs.fk_mpj_updated_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'pm_part_requirements' AND CONSTRAINT_NAME = 'fk_pmpr_material' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `pm_part_requirements` ADD CONSTRAINT `fk_pmpr_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE', 'SELECT ''pm_part_requirements.fk_pmpr_material exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND CONSTRAINT_NAME = 'fk_mat_supplier' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `materials` ADD CONSTRAINT `fk_mat_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL', 'SELECT ''materials.fk_mat_supplier exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND CONSTRAINT_NAME = 'fk_mt_equipment' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD CONSTRAINT `fk_mt_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE SET NULL', 'SELECT ''material_transactions.fk_mt_equipment exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND CONSTRAINT_NAME = 'fk_mt_supplier' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD CONSTRAINT `fk_mt_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL', 'SELECT ''material_transactions.fk_mt_supplier exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'inspection_records' AND CONSTRAINT_NAME = 'fk_ir_equipment' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `inspection_records` ADD CONSTRAINT `fk_ir_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE SET NULL', 'SELECT ''inspection_records.fk_ir_equipment exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'inspection_records' AND CONSTRAINT_NAME = 'fk_ir_created_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `inspection_records` ADD CONSTRAINT `fk_ir_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''inspection_records.fk_ir_created_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND CONSTRAINT_NAME = 'fk_backlog_wo' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD CONSTRAINT `fk_backlog_wo` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE SET NULL', 'SELECT ''backlogs.fk_backlog_wo exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND CONSTRAINT_NAME = 'fk_backlog_insp_item_fk' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD CONSTRAINT `fk_backlog_insp_item_fk` FOREIGN KEY (`inspection_item_id`) REFERENCES `inspection_items` (`id`) ON DELETE SET NULL', 'SELECT ''backlogs.fk_backlog_insp_item_fk exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND CONSTRAINT_NAME = 'fk_backlog_created_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD CONSTRAINT `fk_backlog_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''backlogs.fk_backlog_created_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_sessions' AND CONSTRAINT_NAME = 'fk_session_technician' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `wo_sessions` ADD CONSTRAINT `fk_session_technician` FOREIGN KEY (`technician_id`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''wo_sessions.fk_session_technician exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_sessions' AND CONSTRAINT_NAME = 'fk_session_created_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `wo_sessions` ADD CONSTRAINT `fk_session_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''wo_sessions.fk_session_created_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND CONSTRAINT_NAME = 'fk_wo_completed_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD CONSTRAINT `fk_wo_completed_by` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''work_orders.fk_wo_completed_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND CONSTRAINT_NAME = 'fk_wo_verified_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD CONSTRAINT `fk_wo_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''work_orders.fk_wo_verified_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND CONSTRAINT_NAME = 'fk_wo_operator' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD CONSTRAINT `fk_wo_operator` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''work_orders.fk_wo_operator exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'asset_import_logs' AND CONSTRAINT_NAME = 'fk_ail_user' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `asset_import_logs` ADD CONSTRAINT `fk_ail_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''asset_import_logs.fk_ail_user exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_import_logs' AND CONSTRAINT_NAME = 'fk_iil_user' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `inventory_import_logs` ADD CONSTRAINT `fk_iil_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT ''inventory_import_logs.fk_iil_user exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;
