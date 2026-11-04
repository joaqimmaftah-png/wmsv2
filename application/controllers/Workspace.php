<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Read-only launchpads for the sidebar's Maintenance, Inventory, Planning,
 * and Team workspaces. The destination controllers retain their own access
 * checks; these pages only expose links already permitted for the current role.
 */
class Workspace extends MY_Controller {

    public function index() {
        if ((int)$this->role_id === 3) {
            redirect('workspace/inventory');
            return;
        }
        redirect('workspace/maintenance');
    }

    public function maintenance() {
        $this->_show_workspace(
            'maintenance',
            'Maintenance',
            'Choose a maintenance task. Your existing role permissions apply on every destination.',
            array(1, 2, 4),
            array(
                $this->_item('New Work Order', 'workorders/create', 'Record a new maintenance job.', array(1, 2)),
                $this->_item('Work Orders', 'workorders', 'Review assigned and open work orders.', array(1, 2, 4)),
                $this->_item('Equipment Fleet', 'equipment', 'Find assets and review their current records.', array(1, 2, 4)),
                $this->_item('PM Planner', 'pm', 'Review preventive-maintenance schedules and work.', array(1, 2, 4)),
                $this->_item('Inspection Forms', 'inspections', 'Open equipment inspection forms and records.', array(1, 2, 4)),
                $this->_item('Backlog Register', 'backlog', 'Review reported defects and backlog work.', array(1, 2, 4)),
            )
        );
    }

    public function inventory() {
        $this->_show_workspace(
            'inventory',
            'Inventory',
            'Move from stock overview to catalogue, transactions, procurement, and supplier records.',
            array(1, 2, 3, 4),
            array(
                $this->_item('Inventory Dashboard', 'materials', 'Review stock position and inventory actions.', array(1, 2, 3, 4)),
                $this->_item('Inventory List', 'materials/inventory', 'Search items, balances, locations, and stock levels.', array(1, 2, 3, 4)),
                $this->_item('Transactions', 'materials/transactions', 'Review recorded stock movements.', array(1, 2, 3, 4)),
                $this->_item('Spare Orders', 'orders', 'Track spare-parts orders and their status.', array(1, 3, 4)),
                $this->_item('Pending Spares', 'spareparts', 'Review pending spare parts and availability alerts.', array(1, 2, 3, 4)),
                $this->_item('Suppliers', 'suppliers', 'Open supplier records and procurement details.', array(1, 3, 4)),
                $this->_item('Lubricants', 'lubricants', 'Review lubricant stock and usage records.', array(1, 2, 3)),
                $this->_item('Lubricant Budget', 'lubricants/budget', 'Review the lubricant budget and consumption.', array(1, 3)),
            )
        );
    }

    public function planning() {
        $this->_show_workspace(
            'planning',
            'Planning',
            'Follow the planned-availability workflow from weekly plan to comparison and supporting views.',
            array(1, 4),
            array(
                $this->_item('Planned Availability', 'forecast', 'Build and review the weekly availability plan.', array(1, 4)),
                $this->_item('Availability Targets', 'reports/weekly_targets', 'Review weekly machine and group targets; edits remain Supervisor-only.', array(1, 4)),
                $this->_item('Plan vs Actual', 'forecast/plan_vs_actual', 'Compare the published plan with actual availability.', array(1, 4)),
                $this->_item('Rolling Plan', 'forecast/rolling', 'Review the rolling three-week maintenance plan.', array(1, 4)),
                $this->_item('PM Spare Parts', 'forecast/spares', 'Review spare-parts requirements for planned work.', array(1, 4)),
                $this->_item('Planned Allowances', 'forecast/allowances', 'Review standing and unplanned-hour allowances.', array(1, 4)),
            )
        );
    }

    public function team() {
        $role = (int)$this->role_id;
        $selfRoute = 'team/view/' . (int)$this->user_id;
        $selfService = in_array($role, array(2, 3), TRUE);
        $title = $role === 3 ? 'My Record' : 'Team';
        $description = $role === 3
            ? 'Your own attendance and timesheet tools. Team records remain restricted to authorised roles.'
            : 'Open team records, attendance, timesheets, and shift or certification views available to your role.';

        $this->_show_workspace(
            'team',
            $title,
            $description,
            array(1, 2, 3, 4),
            array(
                $this->_item('Team Directory', 'team', 'Search the workforce directory and open staff profiles.', array(1, 4)),
                $this->_item($selfService ? 'My Attendance' : 'Daily Attendance', 'team/attendance', 'Open the attendance record view.', array(1, 2, 3, 4)),
                $this->_item($selfService ? 'My Timesheet' : 'Timesheets & Report', $selfService ? $selfRoute : 'team/report', 'Review the individual timesheet or authorised team report.', array(1, 2, 3, 4)),
                $this->_item('On-Site Status', 'attendance', 'View the current QR/GPS check-in status.', array(1, 2, 3, 4)),
                $this->_item('Check In / Out', 'attendance/punch', 'Record your own site check-in or check-out.', array(1, 2, 3)),
                $this->_item('Site QR Screen', 'attendance/board', 'Open the supervisor-controlled site check-in screen.', array(1)),
                $this->_item('Punch Log', 'attendance/log', 'Review the daily punch log and authorised corrections.', array(1, 4)),
                $this->_item('Shift Roster', 'team/shift_roster', 'Review the weekly shift assignments.', array(1, 2, 4)),
                $this->_item('Certifications', 'team/certifications', 'Review the certification records available to your role.', array(1, 2, 4)),
            )
        );
    }

    private function _show_workspace($key, $title, $description, $allowedRoles, $items) {
        $role = (int)$this->role_id;
        if (!in_array($role, $allowedRoles, TRUE)) {
            show_error('This workspace is not available for your role.', 403);
            return;
        }

        $links = array();
        foreach ($items as $item) {
            if (in_array($role, $item['roles'], TRUE)) {
                unset($item['roles']);
                $links[] = $item;
            }
        }

        $data = array(
            'workspace_key' => $key,
            'workspace_title' => $title,
            'workspace_description' => $description,
            'workspace_modules' => $this->_workspace_modules($role),
            'workspace_links' => $links,
            'page_title' => $title,
        );
        $this->render('workspace/index', $data);
    }

    private function _workspace_modules($role) {
        $modules = array(
            array('key' => 'maintenance', 'title' => 'Maintenance', 'route' => 'workspace/maintenance', 'roles' => array(1, 2, 4)),
            array('key' => 'inventory', 'title' => 'Inventory', 'route' => 'workspace/inventory', 'roles' => array(1, 2, 3, 4)),
            array('key' => 'planning', 'title' => 'Planning', 'route' => 'workspace/planning', 'roles' => array(1, 4)),
            array('key' => 'team', 'title' => $role === 3 ? 'My Record' : 'Team', 'route' => 'workspace/team', 'roles' => array(1, 2, 3, 4)),
        );
        $visible = array();
        foreach ($modules as $module) {
            if (in_array($role, $module['roles'], TRUE)) {
                unset($module['roles']);
                $visible[] = $module;
            }
        }
        return $visible;
    }

    private function _item($title, $route, $description, $roles) {
        return array(
            'title' => $title,
            'route' => $route,
            'description' => $description,
            'roles' => $roles,
        );
    }
}
