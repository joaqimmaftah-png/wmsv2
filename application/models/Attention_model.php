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

        // Certificate expiry: supervisors & managers see every active
        // worker's certificates; technicians & store keepers see only their
        // own (docs/team-certifications-view-ux-audit.md §8). Alerts are
        // state, not messages — they clear by themselves once the
        // certificate is renewed or removed, so there is no read state.
        if (in_array($role, array(1, 2, 3, 4), TRUE)) {
            foreach ($this->_certificate_alerts((int)$user_id, $role) as $cert_group) {
                $groups[] = $cert_group;
            }
        }

        // Time-sensitive leave notices are derived from PUBLISHED plans, not
        // stored messages. They appear to the worker and to planners in the
        // same attention bell, then disappear automatically after return.
        if (in_array($role, array(1, 2, 3, 4), TRUE)) {
            foreach ($this->_planned_leave_reminders((int)$user_id, $role) as $leave_group) {
                $groups[] = $leave_group;
            }
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

    /* ── Certificate expiry ──────────────────────────────────────────
       Tiered like the rest of the compliance tooling: already expired,
       expiring inside the configured alert window (Settings →
       cert_alert_days, default 30), and an early-warning band out to 90
       days for renewals with long lead times. Computed from expiry_date
       directly — the stored status column is never refreshed (written)
       here, because this model runs on every page render. */

    /** In-app reminders evaluated on each page load, not a mail queue. The
     * start and return windows are configured under Settings; each boundary
     * must fall in a published year (especially for cross-year leave). */
    private function _planned_leave_reminders($user_id, $role_id) {
        if (!$this->db->table_exists('workforce_plan_leaves') || !$this->db->table_exists('workforce_plan_years')) return array();
        $ci = get_instance();
        $ci->load->model('Settings_model');
        $begin = (int)$ci->Settings_model->get('roster_leave_notice_days', 7);
        $return = (int)$ci->Settings_model->get('roster_return_notice_days', 2);
        if ($begin < 1 || $begin > 30) $begin = 7;
        if ($return < 1 || $return > 14) $return = 2;
        $today = date('Y-m-d');
        $starts = $this->_planned_leave_group($user_id, $role_id, 'start', $today,
            date('Y-m-d', strtotime('+' . $begin . ' days')));
        // Return date = the day AFTER the final planned leave date.
        $ends = $this->_planned_leave_group($user_id, $role_id, 'return',
            date('Y-m-d', strtotime($today . ' -1 day')),
            date('Y-m-d', strtotime($today . ' +' . ($return - 1) . ' days')));
        return array_values(array_filter(array($starts, $ends)));
    }

    private function _planned_leave_group($user_id, $role_id, $kind, $from, $to) {
        $query = $this->_planned_leave_query($user_id, $role_id, $kind, $from, $to);
        $date_field = $kind === 'start' ? 'l.start_date' : 'l.end_date';
        $rows = $query->order_by($date_field, 'ASC')->order_by('l.id', 'ASC')
            ->limit(self::PREVIEW_LIMIT + 1)->get()->result();
        $total = count($rows);
        if ($total > self::PREVIEW_LIMIT) {
            $total = (int)$this->_planned_leave_query($user_id, $role_id, $kind, $from, $to)->count_all_results();
        }
        if (!$total) return NULL;
        $own = in_array((int)$role_id, array(2, 3), TRUE);
        $items = array();
        foreach (array_slice($rows, 0, self::PREVIEW_LIMIT) as $r) {
            $boundary = $kind === 'start' ? $r->start_date : date('Y-m-d', strtotime($r->end_date . ' +1 day'));
            $days = (int)((strtotime($boundary) - strtotime(date('Y-m-d'))) / 86400);
            $when = $days === 0 ? 'today' : 'in ' . $days . 'd';
            $meta = $kind === 'start' ? 'Starts ' . $when . ' · ' . date('d M', strtotime($boundary))
                : ($days === 0 ? 'Ended yesterday' : ($days === 1 ? 'Ends today' : 'Ends in ' . ($days - 1) . 'd'))
                    . ' · review next shift';
            // Show only the published boundary. A start on 31 Dec may extend
            // into an unpublished next-year draft; its provisional end is not
            // part of this reminder or the worker's published calendar yet.
            $detail = $kind === 'start' ? 'First leave day ' . date('d M Y', strtotime($r->start_date))
                : 'Final leave day ' . date('d M Y', strtotime($r->end_date));
            $items[] = array(
                'label' => $own ? (string)$r->leave_type : (string)$r->worker_name,
                'detail' => $own ? $detail : $r->leave_type . ' · ' . $detail,
                'meta' => $meta,
                'url' => base_url('team/annual_roster?year=' . (int)substr($boundary, 0, 4)
                    . ($own ? '' : '&user=' . (int)$r->user_id)),
            );
        }
        $first_boundary = $kind === 'start' ? $rows[0]->start_date
            : date('Y-m-d', strtotime($rows[0]->end_date . ' +1 day'));
        return array(
            'key' => $kind === 'start' ? 'leave_starts' : 'leave_returns',
            'label' => $kind === 'start' ? 'Planned leave starting soon' : 'Return from planned leave',
            'count' => $total, 'tone' => $kind === 'start' ? 'warning' : 'info',
            'url' => base_url('team/annual_roster?year=' . (int)substr($first_boundary, 0, 4)),
            'items' => $items,
        );
    }

    private function _planned_leave_query($user_id, $role_id, $kind, $from, $to) {
        $is_start = $kind === 'start';
        $now = date('Y-m-d H:i:s');
        // A return reminder requires BOTH the final leave date and the return
        // date to belong to published years. Joining only on the return year
        // exposed an unpublished prior-year draft at the New Year boundary.
        $year_field = $is_start ? 'YEAR(l.start_date)' : 'YEAR(l.end_date)';
        $this->db->select("l.id, l.user_id, l.leave_type, l.start_date, l.end_date, CONCAT(u.first_name, ' ', u.last_name) AS worker_name")
            ->from('workforce_plan_leaves l')
            ->join('users u', 'u.id = l.user_id', 'inner')
            ->join('workforce_plan_years y', 'y.plan_year = ' . $year_field, 'inner', FALSE)
            ->where('l.status', 'Scheduled')->where_in('u.status', array('Active', 'On Leave'))
            ->where('y.status', 'Published')->where('y.published_at <=', $now)
            ->where($is_start ? 'l.start_date >=' : 'l.end_date >=', $from)
            ->where($is_start ? 'l.start_date <=' : 'l.end_date <=', $to);
        if (!$is_start) {
            $next_day = 'DATE_ADD(l.end_date, INTERVAL 1 DAY)';
            $this->db->join('workforce_plan_years ry',
                'ry.plan_year = YEAR(' . $next_day . ')', 'inner', FALSE)
                ->where('ry.status', 'Published')->where('ry.published_at <=', $now)
                // Adjacent leave is not a return, even if the first block ends.
                ->where("NOT EXISTS (SELECT 1 FROM workforce_plan_leaves next_l
                    WHERE next_l.user_id = l.user_id AND next_l.id != l.id
                    AND next_l.status = 'Scheduled' AND next_l.start_date <= {$next_day}
                    AND next_l.end_date >= {$next_day})", NULL, FALSE)
                ->where("NOT EXISTS (SELECT 1 FROM leave_requests lr
                    WHERE lr.user_id = l.user_id AND lr.status = 'Approved'
                    AND lr.start_date <= {$next_day} AND lr.end_date >= {$next_day})", NULL, FALSE);
        }
        if (in_array((int)$role_id, array(2, 3), TRUE)) $this->db->where('l.user_id', (int)$user_id);
        return $this->db;
    }

    /** @return array[] attention groups, most urgent first */
    private function _certificate_alerts($user_id, $role_id) {
        if (!$this->db->table_exists('certifications') || !$this->db->table_exists('users')) return array();

        $today      = date('Y-m-d');
        $alert_days = $this->_cert_alert_days();
        $own        = in_array((int)$role_id, array(2, 3), TRUE);

        $tiers = array(
            array(
                'key'   => 'certs_expired',
                'label' => 'Certificates expired',
                'tone'  => 'critical',
                'where' => array('c.expiry_date <' => $today),
            ),
            array(
                'key'   => 'certs_expiring',
                'label' => 'Certificates expiring soon',
                'tone'  => 'warning',
                'where' => array(
                    'c.expiry_date >=' => $today,
                    'c.expiry_date <=' => date('Y-m-d', strtotime('+' . $alert_days . ' days')),
                ),
            ),
        );
        if ($alert_days < 90) {
            $tiers[] = array(
                'key'   => 'certs_upcoming',
                'label' => 'Certificates expiring in ' . ($alert_days + 1) . '–90 days',
                'tone'  => 'info',
                'where' => array(
                    'c.expiry_date >'  => date('Y-m-d', strtotime('+' . $alert_days . ' days')),
                    'c.expiry_date <=' => date('Y-m-d', strtotime('+90 days')),
                ),
            );
        }

        $groups = array();
        foreach ($tiers as $tier) {
            $group = $this->_certificate_group($tier, $user_id, (int)$role_id, $own);
            if ($group) $groups[] = $group;
        }
        return $groups;
    }

    private function _certificate_group($tier, $user_id, $role_id, $own) {
        $rows = $this->_certificate_query($user_id, $role_id, $tier['where'])
            ->order_by('c.expiry_date', 'ASC')
            ->order_by('c.id', 'ASC')
            ->limit(self::PREVIEW_LIMIT + 1)
            ->get()->result();
        $total = count($rows);
        if ($total > self::PREVIEW_LIMIT) {
            $total = (int)$this->_certificate_query($user_id, $role_id, $tier['where'])->count_all_results();
        }
        if ($total === 0) return NULL;

        $items = array();
        foreach (array_slice($rows, 0, self::PREVIEW_LIMIT) as $row) {
            $items[] = array(
                // Holders recognise their own certificate by name; supervisors
                // and managers triage by worker, certificate as context.
                'label'  => $own ? (string)$row->cert_name : (string)$row->worker_name,
                'detail' => $own
                    ? $this->_context(array($row->issuing_body, $row->cert_number))
                    : (string)$row->cert_name,
                'meta'   => $this->_certificate_meta($row->expiry_date),
                'url'    => base_url('team/view/' . (int)$row->user_id),
            );
        }

        return array(
            'key'    => $tier['key'],
            'label'  => $tier['label'],
            'count'  => $total,
            'tone'   => $tier['tone'],
            // The workforce tracker is supervisor/manager scope; a holder's
            // "view all" is their own profile, never the company-wide list.
            'url'    => $own ? base_url('team/view/' . (int)$user_id) : base_url('team/certifications'),
            'action' => $own ? 'My certifications' : 'View all',
            'items'  => $items,
        );
    }

    private function _certificate_query($user_id, $role_id, $where) {
        $this->db
            ->select('c.id, c.user_id, c.cert_name, c.cert_number, c.issuing_body, c.expiry_date,
                CONCAT(u.first_name, " ", u.last_name) AS worker_name')
            ->from('certifications c')
            ->join('users u', 'u.id = c.user_id', 'inner')
            ->where('c.expiry_date IS NOT NULL', NULL, FALSE);

        foreach ($where as $field => $value) {
            $this->db->where($field, $value);
        }

        if (in_array((int)$role_id, array(1, 4), TRUE)) {
            // Workforce scope: certificates of staff who have left are not
            // actionable exceptions (same population as the directory).
            $this->db->where('u.status', 'Active');
        } else {
            $this->db->where('c.user_id', (int)$user_id);
        }
        return $this->db;
    }

    private function _certificate_meta($expiry_date) {
        $days = (int)floor((strtotime(substr((string)$expiry_date, 0, 10)) - strtotime(date('Y-m-d'))) / 86400);
        $when = date('d M Y', strtotime((string)$expiry_date));
        if ($days < 0)  return 'Expired ' . abs($days) . 'd · ' . $when;
        if ($days === 0) return 'Expires today · ' . $when;
        return 'Expires in ' . $days . 'd · ' . $when;
    }

    /** `cert_alert_days` setting (seeded at 30), clamped like Team_model's copy. */
    private function _cert_alert_days() {
        try {
            $CI =& get_instance();
            if (!isset($CI->Settings_model)) $CI->load->model('Settings_model');
            $v = isset($CI->Settings_model) ? (int)$CI->Settings_model->get('cert_alert_days', 30) : 30;
        } catch (Throwable $e) {
            $v = 30;
        }
        return ($v > 0 && $v <= 365) ? $v : 30;
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
