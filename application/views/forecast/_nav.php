<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/** Shared planning navigation. Routes and permissions are unchanged. */
$planning_week = isset($week_input) && $week_input ? (string)$week_input : (isset($iso_label) ? (string)$iso_label : (isset($base_label) ? (string)$base_label : ''));
$planning_week_qs = $planning_week !== '' ? '?week=' . rawurlencode($planning_week) : '';
$module_nav_label = 'Planning';
$module_nav_active = isset($planning_nav_active) ? $planning_nav_active : '';
$module_nav_items = array(
    array('key' => 'planned_availability', 'label' => 'Planned Availability', 'path' => 'forecast' . $planning_week_qs),
    array('key' => 'plan_vs_actual', 'label' => 'Plan vs Actual', 'path' => 'forecast/plan_vs_actual' . $planning_week_qs),
    array('key' => 'rolling_plan', 'label' => '3-Week Plan', 'path' => 'forecast/rolling' . $planning_week_qs),
    array('key' => 'pm_parts', 'label' => 'PM Parts Forecast', 'path' => 'forecast/spares'),
    array('key' => 'allowances', 'label' => 'Planning Allowances', 'path' => 'forecast/allowances' . $planning_week_qs),
    array('key' => 'availability_targets', 'label' => 'Availability Targets', 'path' => 'reports/weekly_targets' . (isset($week_start) ? '?week_start=' . rawurlencode((string)$week_start) : '')),
);
include __DIR__ . '/../shared/_module_nav.php';
