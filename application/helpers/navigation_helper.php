<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Presentation-only navigation model.
 *
 * This helper decides which links to OFFER, never whether a request is allowed.
 * MY_Controller and the individual controllers remain the authorization boundary.
 * Keeping the tree here gives the layout, local navigation and regression tests
 * one canonical set of labels and route families.
 */

if (!function_exists('wms_nav_item')) {
    function wms_nav_item($key, $label, $path, array $roles, $icon, array $matches = array()) {
        return array(
            'key' => $key, 'label' => $label, 'path' => $path,
            'roles' => $roles, 'icon' => $icon,
            'matches' => $matches ?: array($path),
        );
    }
}

if (!function_exists('wms_nav_tree')) {
    function wms_nav_tree($role_id) {
        $role_id = (int)$role_id;
        $all = array(1, 2, 3, 4);
        $maintenance = array(1, 2, 4);
        $planning = array(1, 4);
        $purchasing = array(1, 3, 4);
        $lubricants = array(1, 2, 3);
        $personal = array(2, 3);

        $tree = array(
            'pinned' => array(
                wms_nav_item('dashboard', $role_id === 5 ? 'Executive Summary' : 'Dashboard', 'dashboard', array(1, 2, 3, 4, 5), 'dashboard'),
                wms_nav_item('punch', 'Check In / Out', 'attendance/punch', array(1, 2, 3), 'check-circle'),
            ),
            'groups' => array(
                array(
                    'key' => 'maintenance', 'label' => 'Maintenance', 'icon' => 'wrench', 'roles' => $maintenance,
                    'items' => array(
                        wms_nav_item('workorders', 'Work Orders', 'workorders', $maintenance, 'clipboard'),
                        wms_nav_item('equipment', 'Equipment', 'equipment', $maintenance, 'truck'),
                        wms_nav_item('pm', 'Preventive Maintenance', 'pm', $maintenance, 'calendar-check'),
                        wms_nav_item('inspections', 'Inspections', 'inspections', $maintenance, 'check-circle'),
                        wms_nav_item('backlog', 'Defect Backlog', 'backlog', $maintenance, 'list'),
                    ),
                ),
                array(
                    'key' => 'planning', 'label' => 'Planning', 'icon' => 'calendar', 'roles' => $planning,
                    'items' => array(
                        wms_nav_item('planned_availability', 'Planned Availability', 'forecast', $planning, 'trend', array('forecast', 'forecast/index', 'forecast/jobs')),
                        wms_nav_item('plan_vs_actual', 'Plan vs Actual', 'forecast/plan_vs_actual', $planning, 'chart'),
                        wms_nav_item('rolling_plan', '3-Week Plan', 'forecast/rolling', $planning, 'columns'),
                        wms_nav_item('pm_parts', 'PM Parts Forecast', 'forecast/spares', $planning, 'box', array('forecast/spares', 'forecast/parts')),
                        wms_nav_item('allowances', 'Planning Allowances', 'forecast/allowances', $planning, 'sliders'),
                        // Existing Reports route and permissions; planning is its working context.
                        wms_nav_item('availability_targets', 'Availability Targets', 'reports/weekly_targets', $planning, 'target'),
                    ),
                ),
                array(
                    'key' => 'inventory', 'label' => 'Inventory & Supplies', 'icon' => 'box', 'roles' => $all,
                    'items' => array(
                        wms_nav_item('inventory_overview', 'Inventory Overview', 'materials', $all, 'box', array('materials', 'materials/index', 'materials/report')),
                        wms_nav_item('stock_items', 'Stock Items', 'materials/inventory', $all, 'grid', array('materials/inventory', 'materials/create', 'materials/edit', 'materials/import_excel', 'materials/import_report')),
                        wms_nav_item('stock_movements', 'Stock Movements', 'materials/transactions', $all, 'transfer', array('materials/transactions', 'materials/receive', 'materials/issue')),
                        wms_nav_item('purchase_orders', 'Purchase Orders', 'orders', $purchasing, 'truck'),
                        wms_nav_item('workorder_parts', 'Work Order Parts', 'spareparts', $all, 'bell'),
                        wms_nav_item('suppliers', 'Suppliers', 'suppliers', $purchasing, 'building'),
                        wms_nav_item('lubricants', 'Lubricants', 'lubricants', $lubricants, 'droplet'),
                    ),
                ),
                array(
                    'key' => 'reports', 'label' => 'Reports & Analytics', 'icon' => 'chart', 'roles' => $planning,
                    'items' => array(
                        wms_nav_item('report_library', 'Report Library', 'reports', $planning, 'chart', array('reports')),
                        wms_nav_item('workshop_daily', 'Workshop Daily', 'reports/workshop_daily', $planning, 'file'),
                        wms_nav_item('workshop_weekly', 'Workshop Weekly', 'reports/workshop_weekly', $planning, 'calendar-check', array('reports/workshop_weekly', 'reports/weekly_w36')),
                    ),
                ),
                array(
                    'key' => 'workforce', 'label' => 'Workforce', 'icon' => 'users', 'roles' => $planning,
                    'items' => array(
                        wms_nav_item('team_directory', 'Team Directory', 'team', $planning, 'users', array('team', 'team/index', 'team/view', 'team/create', 'team/edit', 'team/leave_request')),
                        wms_nav_item('daily_attendance', 'Daily Attendance', 'team/attendance', array(1), 'user-check'),
                        wms_nav_item('attendance_report', 'Attendance & Timesheets', 'team/report', $planning, 'file', array('team/report', 'team/timesheet', 'team/timesheets_batch', 'attendance')),
                        wms_nav_item('annual_roster', 'Annual Workforce Plan', 'team/annual_roster', $planning, 'calendar', array('team/annual_roster', 'team/roster_day_edit')),
                        wms_nav_item('shift_roster', 'Shift Roster', 'team/shift_roster', $planning, 'clock', array('team/shift')),
                        wms_nav_item('certifications', 'Certifications', 'team/certifications', $planning, 'award'),
                    ),
                ),
                array(
                    'key' => 'my_work', 'label' => 'My Work', 'icon' => 'user', 'roles' => $personal,
                    'items' => array(
                        wms_nav_item('my_attendance', 'My Attendance & Timesheet', 'team/report', $personal, 'file', array('team/report', 'team/timesheet', 'team/timesheets_batch', 'team/attendance', 'team/leave_request')),
                        wms_nav_item('on_site', 'On-Site Status', 'attendance', $personal, 'map-pin', array('attendance', 'attendance/index')),
                        wms_nav_item('shift_roster', 'Shift Roster', 'team/shift_roster', $personal, 'clock', array('team/shift')),
                        wms_nav_item('my_annual_roster', 'My Annual Schedule', 'team/annual_roster', $personal, 'calendar'),
                        wms_nav_item('my_certifications', 'My Certifications', 'team/certifications', $personal, 'award'),
                    ),
                ),
                array(
                    'key' => 'administration', 'label' => 'Administration', 'icon' => 'settings', 'roles' => array(1),
                    'items' => array(
                        wms_nav_item('users', 'User Accounts', 'users', array(1), 'users', array('users', 'users/index', 'users/create', 'users/edit', 'users/reset_password')),
                        wms_nav_item('settings', 'System Settings', 'settings', array(1), 'settings', array('settings', 'settings/index')),
                        wms_nav_item('security', 'Sign-in Security', 'settings/security', array(1), 'shield'),
                    ),
                ),
            ),
        );

        $visible = array('pinned' => array(), 'groups' => array());
        foreach ($tree['pinned'] as $item) {
            if (in_array($role_id, $item['roles'], TRUE)) $visible['pinned'][] = $item;
        }
        foreach ($tree['groups'] as $group) {
            if (!in_array($role_id, $group['roles'], TRUE)) continue;
            $group['items'] = array_values(array_filter($group['items'], function ($item) use ($role_id) {
                return in_array($role_id, $item['roles'], TRUE);
            }));
            if ($group['items']) $visible['groups'][] = $group;
        }
        return $visible;
    }
}

if (!function_exists('wms_nav_active')) {
    /** Longest route-family match; no accidental "settings" vs "settings/security" collision. */
    function wms_nav_active($uri, $role_id) {
        $uri = strtolower(trim((string)$uri, '/'));
        $tree = wms_nav_tree($role_id);
        $items = $tree['pinned'];
        foreach ($tree['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $item['group'] = $group['key'];
                $items[] = $item;
            }
        }
        $best = array('key' => '', 'group' => '', 'length' => -1);
        foreach ($items as $item) {
            foreach ($item['matches'] as $prefix) {
                $prefix = strtolower(rtrim($prefix, '/'));
                $exact = $uri === $prefix || $uri === $prefix . '/index';
                $child = strpos($uri, $prefix . '/') === 0;
                $len = strlen($prefix);
                if (($exact || $child) && $len > $best['length']) {
                    $best = array('key' => $item['key'], 'group' => isset($item['group']) ? $item['group'] : '', 'length' => $len);
                }
            }
        }
        // A specialist report belongs to the library, not to a second global leaf.
        if ($best['key'] === 'report_library' && strpos($uri, 'reports/') === 0) {
            $best['key'] = 'report_library';
        }
        return $best;
    }
}

if (!function_exists('wms_nav_link_allowed')) {
    /**
     * Presentation filter for contextual links. Reads the existing controller
     * matrix and the verified action exceptions; it does not grant access.
     */
    function wms_nav_link_allowed($path, $role_id) {
        $role_id = (int)$role_id;
        $path = strtolower(trim((string)parse_url((string)$path, PHP_URL_PATH), '/'));
        $parts = explode('/', $path);
        $controller = $parts[0];
        $method = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : 'index';
        $key = $controller . '/' . $method;

        if (in_array($key, MY_Controller::SELF_SERVICE_ACTIONS, TRUE)) return $role_id !== 5;
        $matrix = MY_Controller::ACCESS_MATRIX;
        $allowed = isset($matrix[$controller]) ? $matrix[$controller] : array(1);

        $exceptions = array(
            'team/index' => array(1, 4), 'team/roster_csv' => array(1, 4),
            'team/create' => array(1), 'team/edit' => array(1),
            'team/leave_request' => array(1, 2, 3),
            'team/timesheets_batch' => array(1, 4), 'team/shift_roster_csv' => array(1, 4),
            'team/approve_leave' => array(1), 'team/shift_save' => array(1),
            'team/shift_delete' => array(1), 'team/assign_shift' => array(1),
            'team/edit_certification' => array(1), 'team/delete_certification' => array(1),
            'attendance/log' => array(1, 4), 'attendance/log_csv' => array(1, 4),
            'attendance/board' => array(1), 'attendance/sites' => array(1),
            'attendance/print_qr' => array(1), 'attendance/token' => array(1),
            'attendance/policy' => array(1), 'attendance/correction' => array(1),
            'attendance/punch' => array(1, 2, 3), 'attendance/record' => array(1, 2, 3),
            'lubricants/budget' => array(1, 3),
            'reports/import_asset_register' => array(1),
            'reports/weekly_targets_save' => array(1), 'reports/weekly_targets_bulk' => array(1),
            'reports/weekly_targets_copy' => array(1), 'reports/weekly_targets_delete' => array(1),
        );
        if (isset($exceptions[$key])) $allowed = $exceptions[$key];
        if (!in_array($role_id, $allowed, TRUE)) return FALSE;

        if ($role_id === 4) {
            $whitelisted = in_array($key, MY_Controller::MANAGER_WRITE_WHITELIST, TRUE)
                || in_array($key, MY_Controller::SELF_SERVICE_ACTIONS, TRUE)
                || in_array($key, MY_Controller::MANAGER_READ_WHITELIST, TRUE);
            if (!$whitelisted && preg_match(MY_Controller::MUTATION_PATTERN, $method)) return FALSE;
        }
        return TRUE;
    }
}

if (!function_exists('wms_nav_icon')) {
    function wms_nav_icon($name) {
        $paths = array(
            'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'check-circle' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>',
            'wrench' => '<path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.7-3.7a6 6 0 01-7.9 7.9l-6.9 6.9a2.1 2.1 0 01-3-3l6.9-6.9a6 6 0 017.9-7.9l-3.7 3.7z"/>',
            'clipboard' => '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/>',
            'truck' => '<rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'calendar-check' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M9 16l2 2 4-4"/>',
            'list' => '<path d="M10 6h11M10 12h11M10 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
            'trend' => '<path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
            'chart' => '<path d="M18 20V10M12 20V4M6 20v-6"/>',
            'columns' => '<rect x="2" y="4" width="6" height="16" rx="1"/><rect x="9" y="4" width="6" height="16" rx="1"/><rect x="16" y="4" width="6" height="16" rx="1"/>',
            'box' => '<path d="M21 16V8a2 2 0 00-1-1.7l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.7l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><path d="M3.3 7L12 12l8.7-5M12 22V12"/>',
            'sliders' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
            'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
            'grid' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
            'transfer' => '<path d="M17 1l4 4-4 4M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4M21 13v2a4 4 0 01-4 4H3"/>',
            'bell' => '<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/>',
            'building' => '<path d="M3 21h18M5 21V7l8-4 8 4v14M9 9h1M14 9h1M9 13h1M14 13h1M9 17h1M14 17h1"/>',
            'droplet' => '<path d="M12 2.7l5.7 5.7a8 8 0 11-11.4 0z"/>',
            'file' => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/>',
            'users' => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.9M16 3.1a4 4 0 010 7.8"/>',
            'user' => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'user-check' => '<path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M17 11l2 2 4-4"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'award' => '<circle cx="12" cy="8" r="6"/><path d="M15.5 12.9L17 22l-5-3-5 3 1.5-9.1"/>',
            'map-pin' => '<path d="M12 21s7-6.1 7-11a7 7 0 10-14 0c0 4.9 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.6 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.6-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
            'chevron' => '<path d="M6 9l6 6 6-6"/>',
            'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
        );
        $body = isset($paths[$name]) ? $paths[$name] : $paths['grid'];
        return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $body . '</svg>';
    }
}
