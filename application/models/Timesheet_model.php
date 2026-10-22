<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Timesheet_model — builds live per-employee AML Individual Attendance Sheet
 * payloads strictly from operational WMS tables (`users`, `attendance`,
 * `attendance_punches`, `attendance_sites`, `leave_requests`, `settings`).
 *
 * Zero sample dates or static employee rows are ever stored or injected here.
 */
#[AllowDynamicProperties]
class Timesheet_model extends CI_Model {

    public function __construct() {
        parent::__construct();
        if (!function_exists('wms_timesheet_resolve_period')) {
            $this->load->helper('attendance');
        }
    }

    /**
     * Distinct known site names across active geofenced `attendance_sites`
     * and the 36 approved AML `report_sites`, sorted cleanly for UI pickers.
     *
     * @return string[]
     */
    public function site_options() {
        $seen = array();
        $out  = array();

        if ($this->db->table_exists('attendance_sites')) {
            $rows = $this->db->select('name')
                ->where('is_active', 1)
                ->order_by('name', 'ASC')
                ->get('attendance_sites')->result();
            foreach ($rows as $r) {
                $n = trim((string)$r->name);
                $k = strtoupper($n);
                if ($n !== '' && !isset($seen[$k])) {
                    $seen[$k] = TRUE;
                    $out[] = $n;
                }
            }
        }

        if ($this->db->table_exists('report_sites')) {
            $rows = $this->db->select('label')
                ->where('is_active', 1)
                ->order_by('sort_order', 'ASC')
                ->order_by('label', 'ASC')
                ->get('report_sites')->result();
            foreach ($rows as $r) {
                $n = trim((string)$r->label);
                $k = strtoupper($n);
                if ($n !== '' && !isset($seen[$k])) {
                    $seen[$k] = TRUE;
                    $out[] = $n;
                }
            }
        }

        return $out;
    }

    /**
     * Signatory labels & job titles from `settings`, falling back to the
     * documented AML defaults when unconfigured.
     *
     * @return array{prepared_by:string,prepared_title:string,checked_by:string,checked_title:string,approved_by:string,approved_title:string}
     */
    public function signatories() {
        $this->load->model('Settings_model');
        return array(
            'prepared_by'    => (string)$this->Settings_model->get('timesheet_prepared_by', 'AGANO Elie'),
            'prepared_title' => (string)$this->Settings_model->get('timesheet_prepared_title', 'Assistant Admin HR'),
            'checked_by'     => (string)$this->Settings_model->get('timesheet_checked_by', "Supervisor's"),
            'checked_title'  => trim((string)$this->Settings_model->get('timesheet_checked_title', '')),
            'approved_by'    => (string)$this->Settings_model->get('timesheet_approved_by', 'BUZINDU RUB. Serge'),
            'approved_title' => (string)$this->Settings_model->get('timesheet_approved_title', 'HR Manager'),
        );
    }

    /**
     * Bulk O(1)-query worker site resolution across an entire roster:
     *   1. Explicit override passed by caller (`override`)
     *   2. Worker profile `users.site` column (`profile`)
     *   3. Predominant QR punch site in `[start_date .. end_date]` (`qr_period`)
     *   4. Most recent historical QR punch site (`qr_recent`)
     *
     * Executes at most 2 SQL queries total regardless of worker count.
     *
     * @param  array       $workers
     * @param  string      $start_date
     * @param  string      $end_date
     * @param  string|null $site_override
     * @return array<int,array{site:string,source:string}>
     */
    public function resolve_sites_for_workers(array $workers, $start_date, $end_date, $site_override = NULL) {
        $result = array();
        $ovr = is_string($site_override) ? trim($site_override) : '';
        $need_punch_ids = array();

        foreach ($workers as $w) {
            $uid = (int)$w->id;
            if ($ovr !== '') {
                $result[$uid] = array('site' => $ovr, 'source' => 'override');
            } elseif (isset($w->site) && trim((string)$w->site) !== '') {
                $result[$uid] = array('site' => trim((string)$w->site), 'source' => 'profile');
            } else {
                $result[$uid] = array('site' => '', 'source' => '');
                $need_punch_ids[] = $uid;
            }
        }

        if (empty($need_punch_ids) || !$this->db->table_exists('attendance_punches') || !$this->db->table_exists('attendance_sites')) {
            return $result;
        }

        // Bulk query 1: predominant site inside [start_date .. end_date] per user
        $period_rows = $this->db->select('p.user_id, s.name, COUNT(*) AS cnt')
            ->from('attendance_punches p')
            ->join('attendance_sites s', 's.id = p.site_id', 'inner')
            ->where_in('p.user_id', $need_punch_ids)
            ->where('p.work_date >=', $start_date)
            ->where('p.work_date <=', $end_date)
            ->group_by(array('p.user_id', 'p.site_id', 's.name'))
            ->order_by('cnt', 'DESC')
            ->get()->result();

        $still_missing = array();
        foreach ($period_rows as $pr) {
            $uid = (int)$pr->user_id;
            $nm  = trim((string)$pr->name);
            if ($nm !== '' && isset($result[$uid]) && $result[$uid]['site'] === '') {
                $result[$uid] = array('site' => $nm, 'source' => 'qr_period');
            }
        }
        foreach ($need_punch_ids as $uid) {
            if ($result[$uid]['site'] === '') $still_missing[] = $uid;
        }

        // Bulk query 2: latest historical QR check-in site for any remaining workers
        if (!empty($still_missing)) {
            $recent_rows = $this->db->select('p.user_id, s.name')
                ->from('attendance_punches p')
                ->join('attendance_sites s', 's.id = p.site_id', 'inner')
                ->where_in('p.user_id', $still_missing)
                ->order_by('p.punched_at', 'DESC')
                ->get()->result();
            foreach ($recent_rows as $rr) {
                $uid = (int)$rr->user_id;
                $nm  = trim((string)$rr->name);
                if ($nm !== '' && isset($result[$uid]) && $result[$uid]['site'] === '') {
                    $result[$uid] = array('site' => $nm, 'source' => 'qr_recent');
                }
            }
        }

        return $result;
    }

    /**
     * Resolve a single worker's best known site for a date range.
     *
     * @param  object      $worker
     * @param  string      $start_date
     * @param  string      $end_date
     * @param  string|null $site_override
     * @return string
     */
    public function resolve_worker_site($worker, $start_date, $end_date, $site_override = NULL) {
        if (!is_object($worker) || !isset($worker->id)) return '';
        $map = $this->resolve_sites_for_workers(array($worker), $start_date, $end_date, $site_override);
        $uid = (int)$worker->id;
        return isset($map[$uid]) ? $map[$uid]['site'] : '';
    }

    /**
     * Build printable sheet payloads for one worker across the resolved period.
     *
     * @param  int         $user_id
     * @param  array       $period        from `wms_timesheet_resolve_period()`
     * @param  string|null $site_override optional site filter / override
     * @return array|null  list of printable sheet arrays, or NULL if worker missing
     */
    public function build_for_worker($user_id, array $period, $site_override = NULL) {
        $this->load->model('Team_model');
        $worker = $this->Team_model->get_by_id((int)$user_id);
        if (!$worker) return NULL;

        return $this->_sheets_for_workers(array($worker), $period, $site_override, FALSE);
    }

    /**
     * Build printable sheet payloads for active staff (optionally restricted to
     * specific `$only_ids` and/or filtered to a specific site).
     *
     * @param  array       $period
     * @param  string|null $site_filter
     * @param  array|null  $only_ids      optional array of integer `users.id`s
     * @return array       list of printable sheet arrays across matched workers
     */
    public function build_for_all_active(array $period, $site_filter = NULL, ?array $only_ids = NULL) {
        $this->load->model('Team_model');
        $workers = $this->Team_model->get_all_active();
        if (empty($workers)) return array();

        if (is_array($only_ids)) {
            $allow = array_fill_keys(array_map('intval', $only_ids), TRUE);
            $filtered = array();
            foreach ($workers as $w) {
                if (isset($allow[(int)$w->id])) $filtered[] = $w;
            }
            $workers = $filtered;
            if (empty($workers)) return array();
        }

        return $this->_sheets_for_workers($workers, $period, $site_filter, TRUE);
    }

    /**
     * Batch-query sites, attendance & approved leave across all requested workers
     * in O(1) constant SQL queries (zero N+1 queries) and assemble printable sheets.
     *
     * @param  array       $workers
     * @param  array       $period
     * @param  string|null $site_param
     * @param  bool        $filter_by_site
     * @return array
     */
    private function _sheets_for_workers(array $workers, array $period, $site_param, $filter_by_site) {
        $start = $period['start_date'];
        $end   = $period['end_date'];
        $ids   = array();
        foreach ($workers as $w) $ids[] = (int)$w->id;
        if (empty($ids)) return array();

        // 1. Bulk site resolution across all workers (<= 2 SQL queries total)
        $site_trim = is_string($site_param) ? trim($site_param) : '';
        $sites_map = $this->resolve_sites_for_workers($workers, $start, $end, $filter_by_site ? NULL : $site_trim);

        // 2. Live `attendance` rows indexed as [user_id][date] => row array (1 SQL query)
        $att_map = array();
        if ($this->db->table_exists('attendance')) {
            $att_rows = $this->db->where_in('user_id', $ids)
                ->where('date >=', $start)
                ->where('date <=', $end)
                ->order_by('date', 'ASC')
                ->get('attendance')->result_array();
            foreach ($att_rows as $r) {
                $att_map[(int)$r['user_id']][(string)$r['date']] = $r;
            }
        }

        // 3. Approved `leave_requests` expanded to [user_id][date] => leave_type (1 SQL query)
        $leave_map = array();
        if ($this->db->table_exists('leave_requests')) {
            $leave_rows = $this->db->where_in('user_id', $ids)
                ->where('status', 'Approved')
                ->where('start_date <=', $end)
                ->where('end_date >=', $start)
                ->get('leave_requests')->result();
            foreach ($leave_rows as $lr) {
                $uid = (int)$lr->user_id;
                $s   = max(strtotime($start), strtotime((string)$lr->start_date));
                $e   = min(strtotime($end), strtotime((string)$lr->end_date));
                for ($t = $s; $t !== FALSE && $e !== FALSE && $t <= $e; $t = strtotime('+1 day', $t)) {
                    $leave_map[$uid][date('Y-m-d', $t)] = (string)$lr->leave_type;
                }
            }
        }

        $signatories = $this->signatories();
        $sheets      = array();

        foreach ($workers as $w) {
            $uid     = (int)$w->id;
            $s_info  = isset($sites_map[$uid]) ? $sites_map[$uid] : array('site' => '', 'source' => '');
            $site    = $s_info['site'];

            if ($filter_by_site && $site_trim !== '') {
                if (strcasecmp($site, $site_trim) !== 0) {
                    continue;
                }
            }

            $u_att   = isset($att_map[$uid])   ? $att_map[$uid]   : array();
            $u_leave = isset($leave_map[$uid]) ? $leave_map[$uid] : array();
            $name    = trim((string)$w->first_name . ' ' . (string)$w->last_name);
            $pos     = isset($w->position) && $w->position !== NULL ? trim((string)$w->position) : '';

            $seg_total = count($period['segments']);
            foreach ($period['segments'] as $idx => $seg) {
                $grid = wms_timesheet_build_weeks(
                    $seg['start_date'],
                    $seg['end_date'],
                    $u_att,
                    $u_leave,
                    Team_model::LEAVE_ATTENDANCE_PREFIX
                );

                $sheets[] = array(
                    'user_id'       => $uid,
                    'employee_id'   => (string)$w->employee_id,
                    'full_name'     => $name,
                    'position'      => $pos,
                    'role_name'     => isset($w->role_name) ? (string)$w->role_name : '',
                    'site'          => $site,
                    'site_source'   => $s_info['source'],
                    'month_heading' => $seg['month_heading'],
                    'segment_index' => $idx + 1,
                    'segment_count' => $seg_total,
                    'grid'          => $grid,
                    'signatories'   => $signatories,
                );
            }
        }

        return $sheets;
    }
}
