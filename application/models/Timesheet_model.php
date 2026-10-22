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
     * Resolve a worker's best known site for a date range:
     *   1. Explicit override passed by supervisor on export (if non-empty)
     *   2. Worker profile `users.site` column (if non-empty)
     *   3. Predominant QR punch site in `[start_date .. end_date]`
     *   4. Most recent historical QR punch site
     *
     * @param  object      $worker
     * @param  string      $start_date
     * @param  string      $end_date
     * @param  string|null $site_override
     * @return string
     */
    public function resolve_worker_site($worker, $start_date, $end_date, $site_override = NULL) {
        if (is_string($site_override) && trim($site_override) !== '') {
            return trim($site_override);
        }
        if (is_object($worker) && isset($worker->site) && trim((string)$worker->site) !== '') {
            return trim((string)$worker->site);
        }

        if ($this->db->table_exists('attendance_punches') && $this->db->table_exists('attendance_sites')) {
            $in_period = $this->db->select('s.name, COUNT(*) AS cnt')
                ->from('attendance_punches p')
                ->join('attendance_sites s', 's.id = p.site_id', 'inner')
                ->where('p.user_id', (int)$worker->id)
                ->where('p.work_date >=', $start_date)
                ->where('p.work_date <=', $end_date)
                ->group_by(array('p.site_id', 's.name'))
                ->order_by('cnt', 'DESC')
                ->limit(1)
                ->get()->row();
            if ($in_period && trim((string)$in_period->name) !== '') {
                return trim((string)$in_period->name);
            }

            $latest = $this->db->select('s.name')
                ->from('attendance_punches p')
                ->join('attendance_sites s', 's.id = p.site_id', 'inner')
                ->where('p.user_id', (int)$worker->id)
                ->order_by('p.punched_at', 'DESC')
                ->limit(1)
                ->get()->row();
            if ($latest && trim((string)$latest->name) !== '') {
                return trim((string)$latest->name);
            }
        }

        return '';
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
     * Build printable sheet payloads for all active staff (optionally filtered
     * to a specific assigned/punched site).
     *
     * @param  array       $period
     * @param  string|null $site_filter
     * @return array       list of printable sheet arrays across matched workers
     */
    public function build_for_all_active(array $period, $site_filter = NULL) {
        $this->load->model('Team_model');
        $workers = $this->Team_model->get_all_active();
        if (empty($workers)) return array();

        return $this->_sheets_for_workers($workers, $period, $site_filter, TRUE);
    }

    /**
     * Batch-query attendance & approved leave across all requested workers in 2
     * SQL queries (zero N+1 queries) and assemble printable sheet arrays.
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

        // 1. Live `attendance` rows indexed as [user_id][date] => row array
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

        // 2. Approved `leave_requests` expanded to [user_id][date] => leave_type
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
        $site_trim   = is_string($site_param) ? trim($site_param) : '';
        $sheets      = array();

        foreach ($workers as $w) {
            $uid  = (int)$w->id;
            $site = $this->resolve_worker_site($w, $start, $end, $filter_by_site ? NULL : $site_trim);

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
                    'site'          => $site,
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
