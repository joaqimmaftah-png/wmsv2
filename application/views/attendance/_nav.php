<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/** One attendance suite across the Team and Attendance controllers. */
$attendance_nav_role = isset($role_id) ? (int)$role_id : 0;
$attendance_nav_personal = in_array($attendance_nav_role, array(2, 3), TRUE);
$attendance_nav_date = isset($date) ? (string)$date : date('Y-m-d');
$attendance_nav_month = isset($month) ? (string)$month : substr($attendance_nav_date, 0, 7);
$module_nav_label = $attendance_nav_personal ? 'My attendance' : 'Workforce attendance';
$module_nav_active = isset($attendance_nav_active) ? $attendance_nav_active : '';
$module_nav_items = array();
if ($attendance_nav_personal) {
    $module_nav_items[] = array('key' => 'my_attendance', 'label' => 'My Attendance & Timesheet', 'path' => 'team/report?month=' . rawurlencode($attendance_nav_month));
    $module_nav_items[] = array('key' => 'on_site', 'label' => 'On-Site Status', 'path' => 'attendance');
    $module_nav_items[] = array('key' => 'my_annual_roster', 'label' => 'My Annual Schedule', 'path' => 'team/annual_roster');
    $module_nav_items[] = array('key' => 'leave_request', 'label' => 'Leave Request', 'path' => 'team/leave_request');
} else {
    if ($attendance_nav_role === 1) {
        $module_nav_items[] = array('key' => 'daily_attendance', 'label' => 'Daily Attendance', 'path' => 'team/attendance?date=' . rawurlencode($attendance_nav_date));
    }
    $module_nav_items[] = array('key' => 'annual_roster', 'label' => 'Annual Workforce Plan', 'path' => 'team/annual_roster');
    $module_nav_items[] = array('key' => 'sick_shift_cover', 'label' => 'Sick Shift Cover', 'path' => 'team/roster_cover');
    $module_nav_items[] = array('key' => 'attendance_report', 'label' => 'Attendance & Timesheets', 'path' => 'team/report?month=' . rawurlencode($attendance_nav_month));
    $module_nav_items[] = array('key' => 'on_site', 'label' => 'On-Site Status', 'path' => 'attendance');
    $module_nav_items[] = array('key' => 'checkin_log', 'label' => 'Check-in Log', 'path' => 'attendance/log?date=' . rawurlencode($attendance_nav_date));
    $module_nav_items[] = array('key' => 'site_screen', 'label' => 'Site Screen', 'path' => 'attendance/board');
    $module_nav_items[] = array('key' => 'attendance_sites', 'label' => 'Attendance Sites', 'path' => 'attendance/sites');
}
include __DIR__ . '/../shared/_module_nav.php';
