<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Role-scoped operational exceptions for the shared attention disclosure.
 *
 * This is not a general activity feed. Every group represents work that needs
 * action and links to an existing authorized record or filtered queue.
 */
class Attention_model extends CI_Model {

    const PREVIEW_LIMIT = 3;

    public function for_user($user_id, $role_id, $new_parts_count = 0) {
        $role   = (int)$role_id;
        $groups = array();

        if (in_array($role, array(1, 2, 4), TRUE)) {
            $incidents = $this->_uncontrolled_incidents((int)$user_id, $role);
            if ($incidents) $groups[] = $incidents;

            $overdue_pm = $this->_overdue_pm();
            if ($overdue_pm) $groups[] = $overdue_pm;
        }

        // Verification is a supervisor action, not a read-only manager alert.
        if ($role === 1) {
            $verification = $this->_awaiting_verification();
            if ($verification) $groups[] = $verification;
        }

        if (in_array($role, array(1, 2, 3, 4), TRUE)) {
            $parts = $this->_new_parts((int)$user_id, $role, (int)$new_parts_count);
            if ($parts) $groups[] = $parts;
        }

        $count = 0;
        foreach ($groups as $group) $count += (int)$group['count'];

        return array('groups' => $groups, 'count' => $count);
    }

    private function _uncontrolled_incidents($user_id, $role_id) {
        if (!$this->db->table_exists('incident_details') || !$this->db->table_exists('work_orders')) return NULL;

        $rows = $this->_incident_query($user_id, $role_id)
            ->order_by('COALESCE(i.incident_datetime, i.created_at)', 'DESC', FALSE)
            ->order_by('i.id', 'DESC')
            ->limit(self::PREVIEW_LIMIT + 1)
            ->get()->result();
        $total = count($rows);
        if ($total > self::PREVIEW_LIMIT) {
            $total = (int)$this->_incident_query($user_id, $role_id)->count_all_results();
        }
        if ($total === 0) return NULL;

        $items = array();
        foreach (array_slice($rows, 0, self::PREVIEW_LIMIT) as $row) {
            $items[] = array(
                'label'  => (string)$row->wo_number,
                'detail' => $this->_context(array($row->registration_no, $row->title)),
                'meta'   => 'Uncontrolled · '.(string)$row->status,
                'url'    => base_url('workorders/view/'.(int)$row->wo_id),
            );
        }

        return array(
            'key'   => 'incidents',
            'label' => 'Uncontrolled incidents',
            'count' => $total,
            'tone'  => 'critical',
            'url'   => base_url('workorders?type=Incident'),
            'action'=> 'Incident queue',
            'items' => $items,
        );
    }

    private function _incident_query($user_id, $role_id) {
        $this->db
            ->select('i.id, i.wo_id, i.incident_datetime, i.created_at,
                wo.wo_number, wo.title, wo.status, e.registration_no')
            ->from('incident_details i')
            ->join('work_orders wo', 'wo.id = i.wo_id', 'inner')
            ->join('equipment e', 'e.id = wo.equipment_id', 'left')
            ->where('i.activity_status', 'Uncontrolled');

        if ((int)$role_id === 2) $this->_scope_technician_work_orders($user_id);
        return $this->db;
    }

    private function _overdue_pm() {
        if (!$this->db->table_exists('pm_schedules') || !$this->db->table_exists('equipment')) return NULL;

        $rows = $this->_overdue_pm_query()
            ->order_by('ps.next_due_date', 'ASC')
            ->order_by('ps.id', 'ASC')
            ->limit(self::PREVIEW_LIMIT + 1)
            ->get()->result();
        $total = count($rows);
        if ($total > self::PREVIEW_LIMIT) {
            $total = (int)$this->_overdue_pm_query()->count_all_results();
        }
        if ($total === 0) return NULL;

        $items = array();
        foreach (array_slice($rows, 0, self::PREVIEW_LIMIT) as $row) {
            $items[] = array(
                'label'  => (string)($row->registration_no ?: ('PM #'.(int)$row->id)),
                'detail' => (string)$row->schedule_name,
                'meta'   => $this->_overdue_pm_label($row),
                'url'    => base_url('pm?tab=overdue&ids='.(int)$row->id),
            );
        }

        return array(
            'key'   => 'overdue_pm',
            'label' => 'Overdue preventive maintenance',
            'count' => $total,
            'tone'  => 'warning',
            'url'   => base_url('pm?tab=overdue'),
            'items' => $items,
        );
    }

    /** Same calendar/meter overdue rules as Pm_model, without hydrating the planner. */
    private function _overdue_pm_query() {
        return $this->db
            ->select('ps.id, ps.schedule_name, ps.interval_type, ps.next_due_date, ps.next_due_hours,
                e.registration_no, e.current_hours, e.current_km')
            ->from('pm_schedules ps')
            ->join('equipment e', 'e.id = ps.equipment_id', 'left')
            ->where('ps.status', 'Active')
            ->group_start()
                ->where('ps.next_due_date <', date('Y-m-d'))
                ->or_group_start()
                    ->where('ps.interval_type', 'Hours')
                    ->where('ps.next_due_hours >', 0)
                    ->where('COALESCE(e.current_hours, 0) >= ps.next_due_hours', NULL, FALSE)
                ->group_end()
                ->or_group_start()
                    ->where('ps.interval_type', 'Km')
                    ->where('ps.next_due_hours >', 0)
                    ->where('COALESCE(e.current_km, 0) >= ps.next_due_hours', NULL, FALSE)
                ->group_end()
            ->group_end();
    }

    private function _overdue_pm_label($row) {
        if (!empty($row->next_due_date)) {
            $due = strtotime(substr((string)$row->next_due_date, 0, 10).' 00:00:00');
            $today = strtotime(date('Y-m-d').' 00:00:00');
            if ($due !== FALSE && $due < $today) {
                return 'Overdue ('.(int)round(($today - $due) / 86400).'d)';
            }
        }

        $current = ((string)$row->interval_type === 'Km') ? (float)$row->current_km : (float)$row->current_hours;
        $unit    = ((string)$row->interval_type === 'Km') ? 'km' : 'h';
        $amount  = max(0, (float)$row->next_due_hours - $current);
        return 'Overdue ('.number_format($amount, 0).$unit.')';
    }

    private function _awaiting_verification() {
        if (!$this->db->table_exists('work_orders')) return NULL;

        $rows = $this->_verification_query()
            ->order_by('wo.updated_at', 'ASC')
            ->order_by('wo.id', 'ASC')
            ->limit(self::PREVIEW_LIMIT + 1)
            ->get()->result();
        $total = count($rows);
        if ($total > self::PREVIEW_LIMIT) {
            $total = (int)$this->_verification_query()->count_all_results();
        }
        if ($total === 0) return NULL;

        $items = array();
        foreach (array_slice($rows, 0, self::PREVIEW_LIMIT) as $row) {
            $items[] = array(
                'label'  => (string)$row->wo_number,
                'detail' => $this->_context(array($row->registration_no, $row->title)),
                'meta'   => (string)$row->priority.' priority',
                'url'    => base_url('workorders/view/'.(int)$row->id),
            );
        }

        return array(
            'key'   => 'verification',
            'label' => 'Awaiting supervisor verification',
            'count' => $total,
            'tone'  => 'info',
            'url'   => base_url('workorders?tab=completed'),
            'items' => $items,
        );
    }

    private function _verification_query() {
        return $this->db
            ->select('wo.id, wo.wo_number, wo.title, wo.priority, wo.updated_at, e.registration_no')
            ->from('work_orders wo')
            ->join('equipment e', 'e.id = wo.equipment_id', 'left')
            ->where('wo.status', 'Completed');
    }

    private function _new_parts($user_id, $role_id, $new_parts_count) {
        if ($new_parts_count <= 0) return NULL;

        $CI =& get_instance();
        $CI->load->model('Spareparts_model');
        $rows = $CI->Spareparts_model->get_recent_alerts($user_id, $role_id, self::PREVIEW_LIMIT, 'New');

        $items = array();
        foreach ($rows as $row) {
            $part = trim((string)($row->part_name ?: $row->item_code));
            if ($part === '') $part = 'Requested part';
            $items[] = array(
                'label'  => $part,
                'detail' => $this->_context(array($row->item_code, $row->wo_number, $row->registration_no)),
                'meta'   => ((string)$row->alert_type === 'Partial') ? 'Partially available' : 'Available now',
                // Store users do not have Work Orders access. Their destination
                // remains the filtered, authorized availability-alert queue.
                'url'    => ((int)$role_id === 3)
                    ? base_url('spareparts/alerts?status=New')
                    : base_url('workorders/view/'.(int)$row->work_order_id),
            );
        }

        return array(
            'key'   => 'parts',
            'label' => 'Parts newly available',
            'count' => $new_parts_count,
            'tone'  => 'success',
            'url'   => base_url('spareparts/alerts?status=New'),
            'items' => $items,
        );
    }

    /** Exact CSV membership, matching Search_model and Spareparts_model. */
    private function _scope_technician_work_orders($user_id) {
        $uid = (int)$user_id;
        $this->db->group_start()
            ->where('wo.created_by', $uid)
            ->or_where('wo.supervisor_id', $uid)
            ->or_where("(wo.assigned_to = '{$uid}' OR wo.assigned_to LIKE '{$uid},%'"
                ." OR wo.assigned_to LIKE '%,{$uid},%' OR wo.assigned_to LIKE '%,{$uid}')", null, false)
            ->group_end();
    }

    private function _context($parts) {
        $clean = array();
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') $clean[] = $part;
        }
        return implode(' · ', $clean);
    }
}
