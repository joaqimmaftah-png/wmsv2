<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Team_model extends CI_Model {

    /** The `leave_requests.leave_type` enum, in the order the form offers it. */
    const LEAVE_TYPES = array(
        'Annual Leave', 'Sick Leave', 'Emergency', 'Unpaid',
        'Maternity', 'Paternity', 'Training',
    );

    /* The marker written on attendance rows created from an approved leave
       request, so un-approving removes exactly those rows and never a
       manually recorded day. Built from the request id. */
    const LEAVE_ATTENDANCE_PREFIX = 'Auto: approved leave request #';

    public function get_all() {
        return $this->db->select('u.*, r.name as role_name')->from('users u')
            ->join('roles r', 'r.id = u.role_id')->order_by('u.first_name')->get()->result();
    }

    public function get_all_active() {
        return $this->db->select('u.*, r.name as role_name')->from('users u')
            ->join('roles r', 'r.id = u.role_id')->where('u.status', 'Active')
            ->order_by('u.first_name')->get()->result();
    }

    public function get_technicians() {
        return $this->db->select('u.*')->from('users u')
            ->where_in('u.role_id', [1, 2])->where('u.status', 'Active')
            ->order_by('u.first_name')->get()->result();
    }

    public function get_by_id($id) {
        return $this->db->select('u.*, r.name as role_name')->from('users u')
            ->join('roles r', 'r.id = u.role_id')->where('u.id', $id)->get()->row();
    }

    public function create($data) {
        $this->db->insert('users', $data);
        return $this->db->insert_id();
    }

    public function get_attendance_by_date($date) {
        $records = $this->db->where('date', $date)->get('attendance')->result_array();
        $indexed = [];
        foreach ($records as $r) { $indexed[$r['user_id']] = $r; }
        return $indexed;
    }

    public function get_attendance_history($user_id, $days = 30) {
        return $this->db->where('user_id', $user_id)
            ->where('date >=', date('Y-m-d', strtotime("-{$days} days")))
            ->order_by('date', 'DESC')->get('attendance')->result();
    }

    public function get_attendance_range($from, $to) {
        return $this->db->select('a.*, CONCAT(u.first_name," ",u.last_name) as full_name')
            ->from('attendance a')->join('users u', 'u.id = a.user_id')
            ->where('a.date >=', $from)->where('a.date <=', $to)
            ->order_by('a.date', 'ASC')->get()->result();
    }

    public function get_monthly_report($month) {
        list($year, $mon) = explode('-', $month);
        $from = "{$month}-01";
        $to   = date('Y-m-t', strtotime($from));
        $days = date('t', strtotime($from));

        $workers = $this->get_all_active();
        $result  = [];

        foreach ($workers as $w) {
            $counts = $this->db->select('status, COUNT(*) as cnt')
                ->where('user_id', $w->id)->where('date >=', $from)->where('date <=', $to)
                ->group_by('status')->get('attendance')->result_array();

            $stats = ['Present' => 0, 'Absent' => 0, 'Leave' => 0, 'Rest' => 0, 'Holiday' => 0];
            foreach ($counts as $c) { $stats[$c['status']] = $c['cnt']; }

            $total = $days;
            $pct   = $total > 0 ? round($stats['Present'] / $total * 100, 0) : 0;
            $result[] = (object)[
                'employee_id' => $w->employee_id,
                'full_name'   => $w->first_name . ' ' . $w->last_name,
                'position'    => $w->position,
                'present'     => $stats['Present'],
                'absent'      => $stats['Absent'],
                'leave'       => $stats['Leave'],
                'rest'        => $stats['Rest'],
                'total'       => $total,
                'pct'         => $pct,
            ];
        }
        return $result;
    }

    public function get_certifications($user_id) {
        return $this->db->where('user_id', $user_id)->order_by('expiry_date', 'ASC')->get('certifications')->result();
    }

    /**
     * Certifications that need attention: everything expiring within $days
     * days AND everything already expired. The old query had a lower bound of
     * today, so an expired certificate silently disappeared from the tracker
     * even though the screen already knew how to render "days EXPIRED".
     */
    public function get_expiring_certs($days = 30) {
        return $this->db
            ->select('c.*, CONCAT(u.first_name," ",u.last_name) as worker_name, u.employee_id')
            ->from('certifications c')->join('users u', 'u.id = c.user_id')
            ->where('c.expiry_date IS NOT NULL', null, FALSE)
            ->where('c.expiry_date <=', date('Y-m-d', strtotime("+{$days} days")))
            ->order_by('c.expiry_date', 'ASC')->get()->result();
    }

    /**
     * Recompute the stored `certifications.status` from the expiry date.
     *
     * The column is written when a certificate is added and was never touched
     * again, so a certificate that expired last month still said "Valid".
     * The passed-in threshold is the `cert_alert_days` setting (seeded in the
     * dump, previously never read by any code).
     *
     * @return int number of rows whose status changed
     */
    public function refresh_cert_status($user_id = NULL, $days = NULL) {
        if (!$this->db->table_exists('certifications')) return 0;

        if ($days === NULL) {
            $days = $this->_setting_int('cert_alert_days', 30);
        }
        $days = max(0, (int)$days);

        $sql = "UPDATE {$this->db->dbprefix('certifications')} SET status = CASE
                    WHEN expiry_date IS NULL THEN 'Valid'
                    WHEN expiry_date < CURDATE() THEN 'Expired'
                    WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 'Expiring Soon'
                    ELSE 'Valid' END";
        $params = array($days);

        if ($user_id !== NULL) {
            $sql .= ' WHERE user_id = ?';
            $params[] = (int)$user_id;
        }

        $this->db->query($sql, $params);
        return (int)$this->db->affected_rows();
    }

    public function add_certification($data) {
        $this->db->insert('certifications', $data);
        return (int)$this->db->insert_id();
    }

    public function get_certification($id) {
        return $this->db->where('id', (int)$id)->get('certifications')->row();
    }

    public function delete_certification($id) {
        $this->db->where('id', (int)$id)->delete('certifications');
        return $this->db->affected_rows() > 0;
    }

    public function get_leave_history($user_id) {
        return $this->db->where('user_id', $user_id)->order_by('created_at', 'DESC')->get('leave_requests')->result();
    }

    public function get_leave_request($id) {
        return $this->db->where('id', (int)$id)->get('leave_requests')->row();
    }

    /** Requests waiting for a supervisor decision, oldest first. */
    public function get_pending_leave_requests() {
        return $this->db
            ->select('lr.*, CONCAT(u.first_name," ",u.last_name) AS worker_name, u.employee_id')
            ->from('leave_requests lr')
            ->join('users u', 'u.id = lr.user_id')
            ->where('lr.status', 'Pending')
            ->order_by('lr.start_date', 'ASC')
            ->get()->result();
    }

    /** Does this user already have a pending/approved request overlapping the range? */
    public function has_overlapping_leave($user_id, $start, $end, $exclude_id = NULL) {
        $this->db->where('user_id', (int)$user_id)
            ->where_in('status', array('Pending', 'Approved'))
            ->where('start_date <=', $end)
            ->where('end_date >=', $start);
        if ($exclude_id !== NULL) {
            $this->db->where('id !=', (int)$exclude_id);
        }
        return $this->db->count_all_results('leave_requests') > 0;
    }

    /**
     * Leave entitlement per type, from the `leave_entitlements` setting
     * (JSON map, e.g. {"Annual Leave":21,"Sick Leave":10}). A type that is
     * missing from the map is not tracked: it shows as "—" rather than 0.
     *
     * @return array type => entitled days
     */
    public function leave_entitlements() {
        $ci = get_instance();
        if (!isset($ci->Settings_model)) {
            $ci->load->model('Settings_model');
        }
        // No explicit default: Settings_model::get() then falls back to the
        // DEFAULTS entry (Annual 21 / Sick 10 / Emergency 3), which is what a
        // site that has never opened the settings screen should use.
        $raw = $ci->Settings_model->get('leave_entitlements');
        $map = json_decode((string)$raw, true);
        if (!is_array($map)) return array();

        $out = array();
        foreach ($map as $type => $days) {
            if (!is_string($type) || trim($type) === '') continue;
            $out[trim($type)] = max(0, (float)$days);
        }
        return $out;
    }

    /**
     * Approved / pending / rejected days per leave type for one year.
     * `days_count` is the value stored on the request (calendar days).
     *
     * @return array type => array(approved, pending, rejected)
     */
    public function get_leave_usage($user_id, $year = NULL) {
        $year = $year ? (int)$year : (int)date('Y');

        $rows = $this->db
            ->select('leave_type, status, SUM(COALESCE(days_count,0)) AS days')
            ->where('user_id', (int)$user_id)
            ->where('start_date >=', $year . '-01-01')
            ->where('start_date <=', $year . '-12-31')
            ->group_by(array('leave_type', 'status'))
            ->get('leave_requests')->result();

        $out = array();
        foreach ($rows as $r) {
            if (!isset($out[$r->leave_type])) {
                $out[$r->leave_type] = array('approved' => 0.0, 'pending' => 0.0, 'rejected' => 0.0);
            }
            $key = strtolower((string)$r->status);
            if (isset($out[$r->leave_type][$key])) {
                $out[$r->leave_type][$key] = (float)$r->days;
            }
        }
        return $out;
    }

    /**
     * Balance rows for one user and year: every type the form offers, with
     * entitled / approved / pending / remaining. `entitled` is NULL for a
     * type that has no entitlement on file.
     *
     * @return array<int,array{type:string,entitled:float|null,approved:float,pending:float,remaining:float|null}>
     */
    public function get_leave_balance($user_id, $year = NULL) {
        $year   = $year ? (int)$year : (int)date('Y');
        $ent    = $this->leave_entitlements();
        $usage  = $this->get_leave_usage($user_id, $year);
        $types  = self::LEAVE_TYPES;

        // A configured type that is not in the enum is still shown last, so a
        // typo in the setting is visible instead of being silently dropped.
        foreach (array_keys($ent) as $type) {
            if (!in_array($type, $types, TRUE)) $types[] = $type;
        }

        $rows = array();
        foreach ($types as $type) {
            $u        = isset($usage[$type]) ? $usage[$type] : array('approved' => 0, 'pending' => 0);
            $entitled = array_key_exists($type, $ent) ? (float)$ent[$type] : NULL;
            $rows[] = array(
                'type'      => $type,
                'entitled'  => $entitled,
                'approved'  => (float)$u['approved'],
                'pending'   => (float)(isset($u['pending']) ? $u['pending'] : 0),
                'remaining' => $entitled === NULL ? NULL : ($entitled - (float)$u['approved'] - (float)(isset($u['pending']) ? $u['pending'] : 0)),
            );
        }
        return $rows;
    }

    /* ── Leave ⇄ attendance ───────────────────────────────────────────
       Approving leave used to change one column of `leave_requests` and
       nothing else: the person still showed as "Not recorded" (or could be
       marked Absent) on every attendance screen for the days they were away.
       Approved leave now writes the attendance days, and reversing the
       decision removes exactly the rows it wrote. */

    public static function leave_attendance_marker($leave_id) {
        return self::LEAVE_ATTENDANCE_PREFIX . (int)$leave_id;
    }

    /**
     * Write `attendance` rows (status "Leave") for every day of an approved
     * request. A day where the person actually worked (Present with a
     * check-in time) is never overwritten.
     *
     * @param  object    $leave    row from `leave_requests`
     * @param  int|null  $actor_id user who approved it
     * @return int number of days written
     */
    public function apply_leave_to_attendance($leave, $actor_id = NULL) {
        if (!$leave || empty($leave->start_date) || empty($leave->end_date)) return 0;

        $start = strtotime($leave->start_date);
        $end   = strtotime($leave->end_date);
        if (!$start || !$end || $end < $start) return 0;

        $marker = self::leave_attendance_marker($leave->id);
        $days   = 0;

        for ($ts = $start; $ts <= $end; $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);

            $existing = $this->db
                ->where('user_id', (int)$leave->user_id)
                ->where('date', $date)
                ->get('attendance')->row();

            if ($existing) {
                // never replace a day the person really worked
                if ($existing->status === 'Present' && !empty($existing->check_in)) {
                    continue;
                }
                $this->db->where('id', $existing->id)->update('attendance', array(
                    'status'     => 'Leave',
                    'notes'      => $marker,
                    'created_by' => $actor_id,
                ));
            } else {
                $this->db->insert('attendance', array(
                    'user_id'    => (int)$leave->user_id,
                    'date'       => $date,
                    'status'     => 'Leave',
                    'notes'      => $marker,
                    'created_by' => $actor_id,
                ));
            }
            $days++;
        }
        return $days;
    }

    /** Remove the attendance rows written by one approved request. */
    public function revert_leave_attendance($leave) {
        if (!$leave) return 0;
        $this->db->where('notes', self::leave_attendance_marker($leave->id))->delete('attendance');
        return (int)$this->db->affected_rows();
    }

    /* ── Shifts / roster ─────────────────────────────────────────────── */

    /** ISO-Monday of the week containing $date (roster rows are keyed by it). */
    public function week_start_date($date = NULL) {
        $ts = $date ? strtotime($date) : time();
        if (!$ts) $ts = time();
        return date('Y-m-d', strtotime('monday this week', $ts));
    }

    public function get_shifts() {
        return $this->db->order_by('start_time', 'ASC')->get('shifts')->result();
    }

    public function get_shift($id) {
        return $this->db->where('id', (int)$id)->get('shifts')->row();
    }

    public function save_shift($data, $id = NULL) {
        if ($id) {
            $this->db->where('id', (int)$id)->update('shifts', $data);
            return (int)$id;
        }
        $this->db->insert('shifts', $data);
        return (int)$this->db->insert_id();
    }

    /** A shift type that is still assigned somewhere cannot be deleted. */
    public function delete_shift($id) {
        if ($this->db->where('shift_id', (int)$id)->count_all_results('shift_assignments') > 0) {
            return FALSE;
        }
        $this->db->where('id', (int)$id)->delete('shifts');
        return TRUE;
    }

    public function count_shift_assignments($shift_id) {
        return (int)$this->db->where('shift_id', (int)$shift_id)->count_all_results('shift_assignments');
    }

    /** @return array shift_id => number of roster rows using it */
    public function get_shift_assignment_counts() {
        $rows = $this->db->select('shift_id, COUNT(*) AS cnt')
            ->group_by('shift_id')->get('shift_assignments')->result();
        $out = array();
        foreach ($rows as $r) { $out[(int)$r->shift_id] = (int)$r->cnt; }
        return $out;
    }

    /**
     * Set (or clear) one worker's shift for a week. An empty $shift_id clears
     * the assignment. The table has no unique key on (user_id, week_start),
     * so the existing row is deleted first — repeated saves can never stack.
     */
    public function set_shift_assignment($user_id, $shift_id, $week_start) {
        $this->db->where('user_id', (int)$user_id)
            ->where('week_start', $week_start)
            ->delete('shift_assignments');

        if ($shift_id === NULL || $shift_id === '' || (int)$shift_id <= 0) {
            return TRUE;
        }

        return (bool)$this->db->insert('shift_assignments', array(
            'user_id'    => (int)$user_id,
            'shift_id'   => (int)$shift_id,
            'week_start' => $week_start,
        ));
    }

    public function get_shift_assignments($week_start) {
        $records = $this->db->where('week_start', $week_start)->get('shift_assignments')->result();
        $indexed = [];
        foreach ($records as $r) { $indexed[$r->user_id] = $r->shift_id; }
        return $indexed;
    }

    /* ── small helpers ───────────────────────────────────────────────── */

    private function _setting_int($key, $default) {
        $ci = get_instance();
        if (!isset($ci->Settings_model)) {
            $ci->load->model('Settings_model');
        }
        $v = $ci->Settings_model->get($key, $default);
        return ($v === NULL || $v === '') ? (int)$default : (int)$v;
    }
}
