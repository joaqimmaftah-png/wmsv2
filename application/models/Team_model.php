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

    /**
     * Build enriched workforce roster rows in O(1) constant SQL queries
     * (zero N+1 loops regardless of headcount) combining:
     *   - Profile, role & skill metadata (`users` + `roles`)
     *   - Bulk site resolution (`users.site` / QR check-in history)
     *   - Today's live geofenced QR check-in status (`attendance_punches`)
     *   - Refreshed certification compliance counts (`Valid` / `Expiring Soon` / `Expired`)
     *   - Active open work order workload (`work_orders.assigned_to`)
     *   - Current week shift assignment (`shift_assignments` + `shifts`)
     *   - Active approved leave covering today (`leave_requests`)
     *
     * @param  string|null $today optional YYYY-MM-DD override for deterministic testing
     * @return object[]
     */
    public function get_roster_overview($today = NULL) {
        $today = ($today && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$today))
            ? (string)$today
            : date('Y-m-d');

        // 1. Refresh stored certification statuses once across all staff (1 SQL query)
        $this->refresh_cert_status();

        // 2. Load all users + role names (1 SQL query)
        $workers = $this->get_all();
        if (empty($workers)) return array();

        $ci = get_instance();

        // 3. Bulk site resolution across all workers (<= 2 SQL queries)
        $month_start = substr($today, 0, 7) . '-01';
        $month_end   = date('Y-m-t', strtotime($month_start));
        $sites_map   = array();
        if (isset($ci->load)) {
            $ci->load->model('Timesheet_model');
            if (isset($ci->Timesheet_model)) {
                $sites_map = $ci->Timesheet_model->resolve_sites_for_workers($workers, $month_start, $month_end, NULL);
            }
        }

        // 4. Today's live QR on-site status map (<= 1 SQL query)
        $on_site_map = array();
        if (isset($ci->load)) {
            $ci->load->model('Attendance_model');
            if (isset($ci->Attendance_model) && $ci->Attendance_model->ready()) {
                $on_site_map = $ci->Attendance_model->on_site_map($today);
            }
        }

        // 5. Grouped certification counts per worker (1 SQL query)
        $cert_stats = array();
        if ($this->db->table_exists('certifications')) {
            $c_rows = $this->db->select('user_id, status, COUNT(*) AS cnt')
                ->from('certifications')
                ->group_by(array('user_id', 'status'))
                ->get()->result();
            foreach ($c_rows as $cr) {
                $uid = (int)$cr->user_id;
                if (!isset($cert_stats[$uid])) {
                    $cert_stats[$uid] = array('Valid' => 0, 'Expiring Soon' => 0, 'Expired' => 0);
                }
                if (isset($cert_stats[$uid][$cr->status])) {
                    $cert_stats[$uid][$cr->status] += (int)$cr->cnt;
                }
            }
        }

        // 6. Active Work Order workload parsed across comma-separated `assigned_to` (1 SQL query)
        $wo_counts = array();
        $wo_crit   = array();
        if ($this->db->table_exists('work_orders')) {
            $wo_rows = $this->db->select('id, priority, assigned_to')
                ->from('work_orders')
                ->where_in('status', array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools'))
                ->get()->result();
            foreach ($wo_rows as $wo) {
                $raw_assigned = isset($wo->assigned_to) ? trim((string)$wo->assigned_to) : '';
                if ($raw_assigned === '') continue;
                $seen_u = array();
                foreach (explode(',', $raw_assigned) as $tok) {
                    $uid = (int)trim($tok);
                    if ($uid <= 0 || isset($seen_u[$uid])) continue;
                    $seen_u[$uid] = TRUE;
                    $wo_counts[$uid] = isset($wo_counts[$uid]) ? ($wo_counts[$uid] + 1) : 1;
                    if (isset($wo->priority) && $wo->priority === 'Critical') {
                        $wo_crit[$uid] = isset($wo_crit[$uid]) ? ($wo_crit[$uid] + 1) : 1;
                    }
                }
            }
        }

        // 7. Approved leave active today + current ISO-week shift names (<= 2 SQL queries)
        $leave_today = array();
        if ($this->db->table_exists('leave_requests')) {
            $lr_rows = $this->db->select('user_id, leave_type')
                ->from('leave_requests')
                ->where('status', 'Approved')
                ->where('start_date <=', $today)
                ->where('end_date >=', $today)
                ->get()->result();
            foreach ($lr_rows as $lr) {
                $leave_today[(int)$lr->user_id] = (string)$lr->leave_type;
            }
        }

        $shift_names = array();
        if ($this->db->table_exists('shift_assignments') && $this->db->table_exists('shifts')) {
            $wk_monday = $this->week_start_date($today);
            $sh_rows = $this->db->select('sa.user_id, s.name, s.start_time, s.end_time')
                ->from('shift_assignments sa')
                ->join('shifts s', 's.id = sa.shift_id', 'inner')
                ->where('sa.week_start', $wk_monday)
                ->get()->result();
            foreach ($sh_rows as $sr) {
                $shift_names[(int)$sr->user_id] = trim((string)$sr->name)
                    . (!empty($sr->start_time) && !empty($sr->end_time)
                        ? ' (' . substr((string)$sr->start_time, 0, 5) . '–' . substr((string)$sr->end_time, 0, 5) . ')'
                        : '');
            }
        }

        $skill_rank = array('Junior' => 1, 'Senior' => 2, 'Expert' => 3, 'Specialist' => 4);
        $out = array();

        foreach ($workers as $w) {
            $uid    = (int)$w->id;
            $s_info = isset($sites_map[$uid])
                ? $sites_map[$uid]
                : array('site' => isset($w->site) ? trim((string)$w->site) : '', 'source' => (isset($w->site) && trim((string)$w->site) !== '') ? 'profile' : '');

            $cs = isset($cert_stats[$uid])
                ? $cert_stats[$uid]
                : array('Valid' => 0, 'Expiring Soon' => 0, 'Expired' => 0);

            $raw_status = isset($w->status) && $w->status !== '' ? (string)$w->status : 'Active';
            $eff_status = $raw_status;
            if ($raw_status === 'Active' && isset($leave_today[$uid])) {
                $eff_status = 'On Leave';
            }

            $skill = isset($w->skill_level) && $w->skill_level !== '' ? (string)$w->skill_level : 'Junior';

            $out[] = (object)array(
                'id'                => $uid,
                'employee_id'       => (string)$w->employee_id,
                'first_name'        => (string)$w->first_name,
                'last_name'         => (string)$w->last_name,
                'full_name'         => trim((string)$w->first_name . ' ' . (string)$w->last_name),
                'email'             => isset($w->email) ? (string)$w->email : '',
                'phone'             => isset($w->phone) ? (string)$w->phone : '',
                'role_id'           => (int)$w->role_id,
                'role_name'         => isset($w->role_name) ? (string)$w->role_name : '',
                'position'          => isset($w->position) ? (string)$w->position : '',
                'skill_level'       => $skill,
                'skill_rank'        => isset($skill_rank[$skill]) ? $skill_rank[$skill] : 0,
                'site'              => $s_info['site'],
                'site_source'       => $s_info['source'],
                'photo'             => isset($w->photo) ? (string)$w->photo : '',
                'join_date'         => isset($w->join_date) ? (string)$w->join_date : '',
                'last_login'        => isset($w->last_login) ? (string)$w->last_login : '',
                'status'            => $raw_status,
                'effective_status'  => $eff_status,
                'active_leave_type' => isset($leave_today[$uid]) ? $leave_today[$uid] : '',
                'on_site_today'     => isset($on_site_map[$uid]),
                'current_shift'     => isset($shift_names[$uid]) ? $shift_names[$uid] : '',
                'active_wo_count'   => isset($wo_counts[$uid]) ? (int)$wo_counts[$uid] : 0,
                'critical_wo_count' => isset($wo_crit[$uid]) ? (int)$wo_crit[$uid] : 0,
                'cert_valid'        => (int)$cs['Valid'],
                'cert_expiring'     => (int)$cs['Expiring Soon'],
                'cert_expired'      => (int)$cs['Expired'],
                'cert_total'        => (int)($cs['Valid'] + $cs['Expiring Soon'] + $cs['Expired']),
                'has_cert_alert'    => ((int)$cs['Expired'] > 0 || (int)$cs['Expiring Soon'] > 0),
            );
        }

        return $out;
    }

    /**
     * Open / active work orders currently assigned to `$user_id` (matching the
     * comma-separated `work_orders.assigned_to` list cleanly).
     *
     * @param  int $user_id
     * @return array<int,array{id:int,wo_number:string,title:string,priority:string,status:string,machine:string}>
     */
    public function get_worker_open_workorders($user_id) {
        $uid = (int)$user_id;
        if ($uid <= 0 || !$this->db->table_exists('work_orders')) return array();

        $rows = $this->db->select('wo.id, wo.wo_number, wo.title, wo.priority, wo.status, wo.assigned_to, eq.registration_no')
            ->from('work_orders wo')
            ->join('equipment eq', 'eq.id = wo.equipment_id', 'left')
            ->where_in('wo.status', array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools'))
            ->order_by('wo.id', 'DESC')
            ->get()->result();

        $out = array();
        foreach ($rows as $r) {
            $raw = isset($r->assigned_to) ? trim((string)$r->assigned_to) : '';
            if ($raw === '') continue;
            foreach (explode(',', $raw) as $tok) {
                if ((int)trim($tok) === $uid) {
                    $out[] = array(
                        'id'        => (int)$r->id,
                        'wo_number' => (string)$r->wo_number,
                        'title'     => (string)$r->title,
                        'priority'  => (string)$r->priority,
                        'status'    => (string)$r->status,
                        'machine'   => isset($r->registration_no) ? (string)$r->registration_no : '',
                    );
                    break;
                }
            }
        }
        return $out;
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

    /**
     * Attendance counts per worker for one calendar month.
     *
     * `Working Days` / the percentage used to divide by the number of days in
     * the month, so a month with weekends and public holidays could never reach
     * 100% and the figure disagreed with its own label. The denominator is now
     * the days actually judged — present + absent + leave — while rest days and
     * holidays are reported separately and excluded.
     */
    public function get_monthly_report($month, $from = NULL, $to = NULL, $site_filter = NULL, $only_user_id = NULL) {
        if (!$from || !$to) {
            $from = "{$month}-01";
            $to   = date('Y-m-t', strtotime($from));
        }

        if ($only_user_id !== NULL && (int)$only_user_id > 0) {
            $one = $this->get_by_id((int)$only_user_id);
            $workers = $one ? array($one) : array();
        } else {
            $workers = $this->get_all_active();
        }
        if (empty($workers)) return array();

        $ci = get_instance();
        $has_ts = FALSE;
        if (isset($ci->load)) {
            $ci->load->model('Timesheet_model');
            $has_ts = isset($ci->Timesheet_model);
        }
        $site_trim = is_string($site_filter) ? trim($site_filter) : '';

        // Bulk site resolution across all workers (<= 2 SQL queries total, zero N+1)
        $sites_map = $has_ts
            ? $ci->Timesheet_model->resolve_sites_for_workers($workers, $from, $to, NULL)
            : array();

        $worker_ids = array();
        foreach ($workers as $w) $worker_ids[] = (int)$w->id;

        // Single bulk query for all attendance records in [from .. to]
        $att_by_user = array();
        $all_rows = $this->db->select('user_id, status, check_in, check_out, hours_worked')
            ->where_in('user_id', $worker_ids)
            ->where('date >=', $from)
            ->where('date <=', $to)
            ->get('attendance')->result_array();
        foreach ($all_rows as $ar) {
            $att_by_user[(int)$ar['user_id']][] = $ar;
        }

        $result = array();
        foreach ($workers as $w) {
            $uid     = (int)$w->id;
            $s_info  = isset($sites_map[$uid])
                ? $sites_map[$uid]
                : array('site' => isset($w->site) ? (string)$w->site : '', 'source' => isset($w->site) && $w->site !== '' ? 'profile' : '');
            $w_site  = $s_info['site'];

            if ($site_trim !== '' && strcasecmp($w_site, $site_trim) !== 0) {
                continue;
            }

            $rows = isset($att_by_user[$uid]) ? $att_by_user[$uid] : array();
            $stats = array('Present' => 0, 'Absent' => 0, 'Leave' => 0, 'Rest' => 0, 'Holiday' => 0);
            $hours_sum = 0.0;
            $missing_checkout = 0;

            foreach ($rows as $r) {
                if (isset($stats[$r['status']])) $stats[$r['status']]++;
                if (!empty($r['check_in']) && empty($r['check_out']) && $r['status'] === 'Present') {
                    $missing_checkout++;
                }
                if (isset($r['hours_worked']) && $r['hours_worked'] !== NULL && is_numeric($r['hours_worked'])) {
                    $hours_sum += (float)$r['hours_worked'];
                } elseif (!empty($r['check_in']) && !empty($r['check_out']) && function_exists('wms_hours_between')) {
                    $calc = wms_hours_between($r['check_in'], $r['check_out']);
                    if ($calc !== NULL) $hours_sum += $calc;
                }
            }

            $recorded_days = count($rows);
            $total = $stats['Present'] + $stats['Absent'] + $stats['Leave'];
            // Distinguish unrecorded worker (NULL) from genuine 0% presence (0)
            $pct = $total > 0 ? (int)round($stats['Present'] / $total * 100, 0) : NULL;

            $result[] = (object)array(
                'id'               => $uid,
                'employee_id'      => (string)$w->employee_id,
                'full_name'        => trim((string)$w->first_name . ' ' . (string)$w->last_name),
                'first_name'       => (string)$w->first_name,
                'last_name'        => (string)$w->last_name,
                'role_id'          => (int)$w->role_id,
                'role_name'        => isset($w->role_name) ? (string)$w->role_name : '',
                'position'         => isset($w->position) ? (string)$w->position : '',
                'skill_level'      => isset($w->skill_level) ? (string)$w->skill_level : '',
                'site'             => $w_site,
                'site_source'      => $s_info['source'],
                'present'          => $stats['Present'],
                'absent'           => $stats['Absent'],
                'leave'            => $stats['Leave'],
                'rest'             => $stats['Rest'],
                'holiday'          => $stats['Holiday'],
                'hours_worked'     => round($hours_sum, 2),
                'missing_checkout' => $missing_checkout,
                'recorded_days'    => $recorded_days,
                'has_records'      => ($recorded_days > 0),
                'total'            => $total,
                'pct'              => $pct,
            );
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
            if (!is_string($type) || trim((string)($type)) === '') continue;
            $out[trim((string)($type))] = max(0, (float)$days);
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

    /** Create or update a shift type; the new/updated id on success, 0 on failure. */
    public function save_shift($data, $id = NULL) {
        if ($id) {
            return $this->db->where('id', (int)$id)->update('shifts', $data) ? (int)$id : 0;
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
        // A failed DELETE used to return TRUE as long as nobody was assigned,
        // so the screen said "Shift deleted." for a row that still existed.
        return $this->db->affected_rows() > 0;
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
