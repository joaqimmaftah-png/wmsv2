-- 0005: Deduplicate `lube_specifications(equipment_type_id, lube_type_id)` and add missing
-- UNIQUE constraints and foreign-key / reporting indexes across all modules.

-- Keep newest specification row if duplicates existed prior to adding unique constraint
DELETE ls1 FROM `lube_specifications` ls1
INNER JOIN `lube_specifications` ls2
  ON ls1.`equipment_type_id` = ls2.`equipment_type_id`
 AND ls1.`lube_type_id` = ls2.`lube_type_id`
 AND ls1.`id` < ls2.`id`;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lube_specifications' AND INDEX_NAME = 'uq_lube_spec_eq_lube');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `lube_specifications` ADD UNIQUE INDEX `uq_lube_spec_eq_lube` (`equipment_type_id`,`lube_type_id`)', 'SELECT ''lube_specifications.uq_lube_spec_eq_lube exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_orders' AND INDEX_NAME = 'idx_so_linked_wo');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_orders` ADD INDEX `idx_so_linked_wo` (`linked_wo_id`)', 'SELECT ''spare_orders.idx_so_linked_wo exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_orders' AND INDEX_NAME = 'idx_so_requested_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_orders` ADD INDEX `idx_so_requested_by` (`requested_by`)', 'SELECT ''spare_orders.idx_so_requested_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_orders' AND INDEX_NAME = 'idx_so_status');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_orders` ADD INDEX `idx_so_status` (`status`)', 'SELECT ''spare_orders.idx_so_status exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_orders' AND INDEX_NAME = 'idx_so_expected');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_orders` ADD INDEX `idx_so_expected` (`expected_delivery_date`)', 'SELECT ''spare_orders.idx_so_expected exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_order_items' AND INDEX_NAME = 'idx_soi_material');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_order_items` ADD INDEX `idx_soi_material` (`material_id`)', 'SELECT ''spare_order_items.idx_soi_material exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_materials' AND INDEX_NAME = 'idx_sm_material');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `supplier_materials` ADD INDEX `idx_sm_material` (`material_id`)', 'SELECT ''supplier_materials.idx_sm_material exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pm_part_requirements' AND INDEX_NAME = 'idx_pmpr_material');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `pm_part_requirements` ADD INDEX `idx_pmpr_material` (`material_id`)', 'SELECT ''pm_part_requirements.idx_pmpr_material exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND INDEX_NAME = 'idx_mat_supplier');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `materials` ADD INDEX `idx_mat_supplier` (`supplier_id`)', 'SELECT ''materials.idx_mat_supplier exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND INDEX_NAME = 'idx_mat_category');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `materials` ADD INDEX `idx_mat_category` (`category`)', 'SELECT ''materials.idx_mat_category exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_transactions' AND INDEX_NAME = 'idx_mt_supplier');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `material_transactions` ADD INDEX `idx_mt_supplier` (`supplier_id`)', 'SELECT ''material_transactions.idx_mt_supplier exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspection_records' AND INDEX_NAME = 'idx_ir_created_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `inspection_records` ADD INDEX `idx_ir_created_by` (`created_by`)', 'SELECT ''inspection_records.idx_ir_created_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspection_records' AND INDEX_NAME = 'idx_ir_date');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `inspection_records` ADD INDEX `idx_ir_date` (`inspection_date`)', 'SELECT ''inspection_records.idx_ir_date exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND INDEX_NAME = 'idx_backlog_wo');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD INDEX `idx_backlog_wo` (`work_order_id`)', 'SELECT ''backlogs.idx_backlog_wo exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backlogs' AND INDEX_NAME = 'idx_backlog_created_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `backlogs` ADD INDEX `idx_backlog_created_by` (`created_by`)', 'SELECT ''backlogs.idx_backlog_created_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_sessions' AND INDEX_NAME = 'idx_wos_technician');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `wo_sessions` ADD INDEX `idx_wos_technician` (`technician_id`)', 'SELECT ''wo_sessions.idx_wos_technician exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_sessions' AND INDEX_NAME = 'idx_wos_created_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `wo_sessions` ADD INDEX `idx_wos_created_by` (`created_by`)', 'SELECT ''wo_sessions.idx_wos_created_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND INDEX_NAME = 'idx_wo_bd_event');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD INDEX `idx_wo_bd_event` (`breakdown_event_id`)', 'SELECT ''work_orders.idx_wo_bd_event exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND INDEX_NAME = 'idx_wo_completed_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD INDEX `idx_wo_completed_by` (`completed_by`)', 'SELECT ''work_orders.idx_wo_completed_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND INDEX_NAME = 'idx_wo_verified_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD INDEX `idx_wo_verified_by` (`verified_by`)', 'SELECT ''work_orders.idx_wo_verified_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND INDEX_NAME = 'idx_wo_operator_id');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD INDEX `idx_wo_operator_id` (`operator_id`)', 'SELECT ''work_orders.idx_wo_operator_id exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND INDEX_NAME = 'idx_wo_status_start');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `work_orders` ADD INDEX `idx_wo_status_start` (`status`,`start_datetime`)', 'SELECT ''work_orders.idx_wo_status_start exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_plan_jobs' AND INDEX_NAME = 'idx_mpj_pm_schedule');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `maintenance_plan_jobs` ADD INDEX `idx_mpj_pm_schedule` (`pm_schedule_id`)', 'SELECT ''maintenance_plan_jobs.idx_mpj_pm_schedule exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_plan_jobs' AND INDEX_NAME = 'idx_mpj_work_order');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `maintenance_plan_jobs` ADD INDEX `idx_mpj_work_order` (`work_order_id`)', 'SELECT ''maintenance_plan_jobs.idx_mpj_work_order exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_plan_jobs' AND INDEX_NAME = 'idx_mpj_backlog');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `maintenance_plan_jobs` ADD INDEX `idx_mpj_backlog` (`backlog_id`)', 'SELECT ''maintenance_plan_jobs.idx_mpj_backlog exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_plan_jobs' AND INDEX_NAME = 'idx_mpj_created_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `maintenance_plan_jobs` ADD INDEX `idx_mpj_created_by` (`created_by`)', 'SELECT ''maintenance_plan_jobs.idx_mpj_created_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_plan_jobs' AND INDEX_NAME = 'idx_mpj_updated_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `maintenance_plan_jobs` ADD INDEX `idx_mpj_updated_by` (`updated_by`)', 'SELECT ''maintenance_plan_jobs.idx_mpj_updated_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_meter_readings' AND INDEX_NAME = 'idx_emr_work_order');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `equipment_meter_readings` ADD INDEX `idx_emr_work_order` (`work_order_id`)', 'SELECT ''equipment_meter_readings.idx_emr_work_order exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_meter_readings' AND INDEX_NAME = 'idx_emr_recorded_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `equipment_meter_readings` ADD INDEX `idx_emr_recorded_by` (`recorded_by`)', 'SELECT ''equipment_meter_readings.idx_emr_recorded_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_kpi_targets' AND INDEX_NAME = 'idx_ekt_equipment');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `equipment_kpi_targets` ADD INDEX `idx_ekt_equipment` (`equipment_id`)', 'SELECT ''equipment_kpi_targets.idx_ekt_equipment exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_kpi_targets' AND INDEX_NAME = 'idx_ekt_created_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `equipment_kpi_targets` ADD INDEX `idx_ekt_created_by` (`created_by`)', 'SELECT ''equipment_kpi_targets.idx_ekt_created_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_planned_downtime' AND INDEX_NAME = 'idx_epd_created_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `equipment_planned_downtime` ADD INDEX `idx_epd_created_by` (`created_by`)', 'SELECT ''equipment_planned_downtime.idx_epd_created_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forecast_snapshots' AND INDEX_NAME = 'idx_fs_published_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `forecast_snapshots` ADD INDEX `idx_fs_published_by` (`published_by`)', 'SELECT ''forecast_snapshots.idx_fs_published_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_report_snapshots' AND INDEX_NAME = 'idx_wrs_published_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `weekly_report_snapshots` ADD INDEX `idx_wrs_published_by` (`published_by`)', 'SELECT ''weekly_report_snapshots.idx_wrs_published_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_report_names' AND INDEX_NAME = 'idx_wrn_updated_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `weekly_report_names` ADD INDEX `idx_wrn_updated_by` (`updated_by`)', 'SELECT ''weekly_report_names.idx_wrn_updated_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goods_receipts' AND INDEX_NAME = 'idx_grn_received_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `goods_receipts` ADD INDEX `idx_grn_received_by` (`received_by`)', 'SELECT ''goods_receipts.idx_grn_received_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_part_alerts' AND INDEX_NAME = 'idx_spa_wo_part');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_part_alerts` ADD INDEX `idx_spa_wo_part` (`wo_spare_part_id`)', 'SELECT ''spare_part_alerts.idx_spa_wo_part exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_part_alerts' AND INDEX_NAME = 'idx_spa_received_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_part_alerts` ADD INDEX `idx_spa_received_by` (`received_by`)', 'SELECT ''spare_part_alerts.idx_spa_received_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spare_part_alerts' AND INDEX_NAME = 'idx_spa_read_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `spare_part_alerts` ADD INDEX `idx_spa_read_by` (`read_by`)', 'SELECT ''spare_part_alerts.idx_spa_read_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_spare_allocations' AND INDEX_NAME = 'idx_wsa_allocated_by');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `wo_spare_allocations` ADD INDEX `idx_wsa_allocated_by` (`allocated_by`)', 'SELECT ''wo_spare_allocations.idx_wsa_allocated_by exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asset_import_logs' AND INDEX_NAME = 'idx_ail_user');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `asset_import_logs` ADD INDEX `idx_ail_user` (`user_id`)', 'SELECT ''asset_import_logs.idx_ail_user exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;

SET @_wms_chk := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_import_logs' AND INDEX_NAME = 'idx_iil_user');
SET @_wms_ddl := IF(@_wms_chk = 0, 'ALTER TABLE `inventory_import_logs` ADD INDEX `idx_iil_user` (`user_id`)', 'SELECT ''inventory_import_logs.idx_iil_user exists'' AS migration_note');
PREPARE _wms_stmt FROM @_wms_ddl;
EXECUTE _wms_stmt;
DEALLOCATE PREPARE _wms_stmt;
