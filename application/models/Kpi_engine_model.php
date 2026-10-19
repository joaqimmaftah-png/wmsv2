<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH.'helpers/kpi_helper.php';

/**
 * Kpi_engine_model — the one place that answers "what was availability, MTTR
 * and MTBF for this machine / this period".
 *
 * Why it exists (audit finding: three divergent availability definitions)
 * ─────────────────────────────────────────────────────────────────────
 * The application contained three different answers to the same question:
 *
 *   1. the approved workbook definition, implemented in
 *      `application/helpers/kpi_helper.php` — used by the W36 weekly report
 *      and the availability forecast:
 *          availability = (required − downtime) ÷ required
 *          required     = open hours − preventive labour hours
 *          open hours   = settings.daily_open_hours / weekly_open_hours
 *          downtime     = the UNION of breakdown intervals (one failure with
 *                         three repair work orders counts once)
 *   2. the dashboard's own arithmetic — a 24/7 wall clock (equipment count ×
 *      days × 24), downtime summed from `work_orders.downtime_hours`, no
 *      breakdown filter;
 *   3. the equipment page's arithmetic — lifetime `current_hours` against
 *      lifetime summed downtime.
 *
 * (2) and (3) are what an operator saw on the dashboard, and they disagree
 * with the weekly report the workshop is measured on. This model replaces all
 * of them: screens ask it for the figure, and it calls the helper — so every
 * screen prints the same number, on the same approved definition, with the
 * configured open hours (10 h × 6 days = 60 h/week on this site).
 *
 * The approved target is 88 % (0.88) unless `equipment_kpi_targets` says
 * otherwise for a machine; the table is the authority when it has rows.
 *
 * Nothing here computes a KPI itself: the arithmetic lives in kpi_helper, the
 * business rules live in Settings_model / Kpi_target_model.
 */
class Kpi_engine_model extends CI_Model {

    /** Approved standing target (Q1) — overridden by equipment_kpi_targets. */
    const TARGET_FALLBACK = 0.88;

    /** How long the dashboard cards look back, in days. */
    const DASHBOARD_WINDOW_DAYS = 30;

    private $_cache = array();

    public function __construct() {
        parent::__construct();
        $this->load->model('Settings_model');
        $this->load->model('Kpi_target_model');
    }

    /* ── the business settings this engine obeys ───────────────────────── */

    public function daily_open_hours() {
        return (float)$this->Settings_model->daily_open_hours();
    }

    /** Open hours over a span of days, on the approved weekly pattern. */
    public function open_hours_days($days) {
        return (float)$this->Settings_model->period_open_hours((int)$days);
    }

    /** Open hours between two dates, inclusive. */
    public function open_hours_between($from, $to) {
        $days = (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1;
        return $this->open_hours_days(max(1, $days));
    }

    /** settings.mtbf_zero_failure_mode — what to show when a machine never failed. */
    public function zero_failure_mode() {
        $mode = $this->Settings_model->get('mtbf_zero_failure_mode', 'dash');
        return strtolower(trim((string)$mode)) === 'uptime' ? 'uptime' : 'dash';
    }

    /* ── the row assembly the helper needs (loaded once per period) ────── */

    private function period_key($from, $to, $equipment_id = null) {
        return $from.'|'.$to.'|'.($equipment_id === null ? '*' : (int)$equipment_id);
    }

    /**
     * Every work order and labour session of the period, read ONCE.
     *
     * A screen that asks for 116 machines must not run 232 queries: the rows
     * are loaded for the period without an equipment filter and the per-machine
     * split happens in PHP (see machine()).
     */
    private function period_rows($from, $to) {
        $key = $from.'|'.$to;
        if (isset($this->_cache['rows'][$key])) return $this->_cache['rows'][$key];

        $rows = array(
            'work_orders' => $this->work_order_rows($from, $to, null),
            'sessions'    => $this->session_rows($from, $to, null),
        );
        $this->_cache['rows'][$key] = $rows;
        return $rows;
    }

    /**
     * Work orders touching the period — the same selection the weekly report
     * uses: it started before the period ended and was either still open at
     * the start of it or restored after (carried-over failures are exactly why
     * days/hours down exist).
     */
    public function work_order_rows($from, $to, $equipment_id = null) {
        if (!$this->db->table_exists('work_orders')) return array();

        $start = $from.' 00:00:00';
        $end   = date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00';

        $this->db->select('w.id, w.wo_number, w.equipment_id, w.type, w.is_breakdown, w.breakdown_event_id,
                           w.status, w.hold_code, w.priority, w.machine_status, w.equipment_systems,
                           w.title, w.description, w.symptoms, w.pending_work, w.action_taken,
                           w.start_datetime, w.end_datetime, w.downtime_hours,
                           w.actual_hours, w.hours_reading')
            ->from('work_orders w')
            ->where('w.start_datetime <', $end)
            ->group_start()
                ->or_where('w.end_datetime IS NULL', null, false)
                ->or_where('w.end_datetime >', $start)
            ->group_end();
        if ($equipment_id !== null) $this->db->where('w.equipment_id', (int)$equipment_id);
        return $this->db->get()->result();
    }

    /** Labour sessions in the period (preventive time, D2). */
    public function session_rows($from, $to, $equipment_id = null) {
        if (!$this->db->table_exists('wo_sessions') || !$this->db->table_exists('work_orders')) {
            return array();
        }
        $this->db->select('s.id, s.wo_id, s.technician_id, s.session_no, s.start_datetime,
                           s.end_datetime, s.hours_worked, s.status, s.work_performed,
                           w.equipment_id')
            ->from('wo_sessions s')
            ->join('work_orders w', 'w.id = s.wo_id')
            ->where('s.start_datetime >=', $from.' 00:00:00')
            ->where('s.start_datetime <=', $to.' 23:59:59');
        if ($equipment_id !== null) $this->db->where('w.equipment_id', (int)$equipment_id);
        return $this->db->get()->result();
    }

    /** Machines the KPI community covers (the same population as the register). */
    public function machine_rows() {
        if (isset($this->_cache['machines'])) return $this->_cache['machines'];
        if (!$this->db->table_exists('equipment')) return array();
        $this->_cache['machines'] = $this->db->select('e.id, e.registration_no, e.status, e.location, e.meter_type,
                                  e.equipment_type_id, et.name AS equip_type')
            ->from('equipment e')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->where('e.status !=', 'Retired')
            ->order_by('e.registration_no', 'ASC')
            ->get()->result();
        return $this->_cache['machines'];
    }

    /* ── one machine, one period ───────────────────────────────────────── */

    public function machine($equipment_id, $from, $to, $opts = array()) {
        $equipment_id = (int)$equipment_id;
        $key = $this->period_key($from, $to, $equipment_id);
        if (isset($this->_cache['machine'][$key])) return $this->_cache['machine'][$key];

        /*
         * The helper's day list defaults to SEVEN days (it was written for the
         * weekly report), so a 30-day or monthly window must say how long it
         * is — otherwise the per-day figures only ever cover the first week
         * while the totals cover the whole window. Both are passed explicitly.
         */
        $day_count = max(1, (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1);

        $opts = array_merge(array(
            'week_start'        => $from,
            'week_end'          => date('Y-m-d', strtotime($to.' +1 day')),
            'days'              => kpi_day_list($from, $day_count),
            'open_hours'        => $this->open_hours_between($from, $to),
            'daily_open_hours'  => $this->daily_open_hours(),
            'mtbf_zero_failure_mode' => $this->zero_failure_mode(),
        ), $opts);

        $rows = $this->period_rows($from, $to);
        $wos  = array();
        foreach ($rows['work_orders'] as $w) {
            if ((int)kpi_row($w, 'equipment_id', 0) === $equipment_id) $wos[] = $w;
        }
        $sessions = array();
        foreach ($rows['sessions'] as $sess) {
            if ((int)kpi_row($sess, 'equipment_id', -1) === $equipment_id) $sessions[] = $sess;
        }

        $result = kpi_machine_period($wos, $sessions, $opts);
        // Percent form, because every screen prints percentages.
        $result['availability_pct'] = isset($result['availability']) && $result['availability'] !== null
            ? round($result['availability'] * 100, 1) : null;
        $result['equipment_id'] = $equipment_id;

        $this->_cache['machine'][$key] = $result;
        return $result;
    }

    /* ── the fleet, one period ─────────────────────────────────────────── */

    /**
     * Every machine's figures for the period, plus the fleet rollup.
     *
     * @return array ['period' => [...], 'fleet' => [...], 'machines' => [id => rows]]
     */
    public function fleet($from, $to, $opts = array()) {
        $key = $this->period_key($from, $to);
        if (isset($this->_cache['fleet'][$key])) return $this->_cache['fleet'][$key];

        $machines = $this->machine_rows();
        $members  = array();
        $results  = array();

        foreach ($machines as $m) {
            $row = $this->machine($m->id, $from, $to, $opts);
            $results[(int)$m->id] = $row + array(
                'registration_no' => $m->registration_no,
                'equip_type'      => isset($m->equip_type) ? $m->equip_type : null,
                'location'        => $m->location,
            );
            // kpi_rollup() input: required and downtime, leaving the whole
            // fleet's availability to the helper.
            if (isset($row['required_time_hours']) && $row['required_time_hours'] > 0) {
                $members[] = array(
                    'required_time_hours' => $row['required_time_hours'],
                    'downtime_hours'      => isset($row['downtime_hours']) ? $row['downtime_hours'] : 0,
                    'breakdowns'          => isset($row['breakdowns']) ? $row['breakdowns'] : 0,
                );
            }
        }

        $fleet = kpi_rollup($members, $this->zero_failure_mode());
        $fleet['availability_pct'] = isset($fleet['availability']) && $fleet['availability'] !== null
            ? round($fleet['availability'] * 100, 1) : null;
        $fleet['machines_with_data'] = count($members);

        $out = array(
            'period'   => array('from' => $from, 'to' => $to,
                                'open_hours' => $this->open_hours_between($from, $to),
                                'daily_open_hours' => $this->daily_open_hours()),
            'fleet'    => $fleet,
            'machines' => $results,
        );
        $this->_cache['fleet'][$key] = $out;
        return $out;
    }

    /** Fleet figures for the dashboard window (one number per card). */
    public function fleet_window($days = null) {
        $days = $days === null ? self::DASHBOARD_WINDOW_DAYS : (int)$days;
        $to   = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-'.($days - 1).' days'));
        return $this->fleet($from, $to);
    }

    /* ── per-day fleet series (the dashboard trend) ────────────────────── */

    /**
     * One point per day: fleet availability on the approved definition.
     *
     * The machines are read once for the whole window and their per-day
     * figures (already computed by the helper) are aggregated — so this is one
     * pass over the data, not one query per day.
     */
    public function daily_fleet($days = 30) {
        $days = max(1, (int)$days);
        $to   = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-'.($days - 1).' days'));

        $per_day = array();
        foreach ($this->machine_rows() as $m) {
            $row = $this->machine($m->id, $from, $to);
            if (empty($row['daily'])) continue;
            foreach ($row['daily'] as $day => $d) {
                if (!isset($per_day[$day])) {
                    $per_day[$day] = array('required' => 0.0, 'downtime' => 0.0, 'failures' => 0);
                }
                $per_day[$day]['required'] += (float)$d['required_hours'];
                $per_day[$day]['downtime'] += (float)$d['downtime_hours'];
                $per_day[$day]['failures'] += (int)$d['failures'];
            }
        }

        ksort($per_day);
        $out = array();
        foreach ($per_day as $day => $d) {
            // Same arithmetic as kpi_availability(), aggregated over the fleet.
            $pct = $d['required'] > 0
                ? round((($d['required'] - $d['downtime']) / $d['required']) * 100, 1)
                : null;
            $out[] = array('date' => $day, 'pct' => $pct, 'failures' => $d['failures']);
        }
        return $out;
    }

    /* ── monthly series (dashboard + annual report) ────────────────────── */

    /**
     * The last $months months, newest last, on the same definition as the
     * weekly report — so a month's figure in the dashboard and the total in
     * the annual report come from the same engine.
     */
    public function monthly_fleet($months = 12) {
        $months = max(1, (int)$months);
        $out = array();
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = date('Y-m', strtotime('-'.$i.' months'));
            $from  = $month.'-01';
            $to    = date('Y-m-t', strtotime($from));
            $data  = $this->fleet($from, $to);

            $out[] = array(
                'month'        => $month,
                'from'         => $from,
                'to'           => $to,
                'label'        => date('M', strtotime($from)),
                'availability' => $data['fleet']['availability_pct'],
                'mttr'         => $data['fleet']['mttr_hours'] === null ? null : round($data['fleet']['mttr_hours'], 1),
                'mtbf'         => $data['fleet']['mtbf_hours'] === null ? null : round($data['fleet']['mtbf_hours'], 1),
                'breakdowns'   => isset($data['fleet']['breakdowns']) ? (int)$data['fleet']['breakdowns'] : 0,
                'downtime'     => isset($data['fleet']['downtime_hours']) ? round($data['fleet']['downtime_hours'], 1) : 0.0,
                'required'     => isset($data['fleet']['required_time_hours']) ? round($data['fleet']['required_time_hours'], 1) : 0.0,
                'open_hours'   => $data['period']['open_hours'],
            );
        }
        return $out;
    }

    /* ── targets ───────────────────────────────────────────────────────── */

    /**
     * The availability target that applies to a machine on a date.
     * equipment_kpi_targets is the authority; the chain then falls to
     * settings.default_availability_target, and only then to the approved
     * standing 88 % (which is also that setting's own default — so an operator
     * who changes the setting moves the target without touching code).
     */
    public function target_for($equipment_id, $date = null, $group_code = null) {
        $date = $date ?: date('Y-m-d');
        $pct  = null;
        if (method_exists($this->Kpi_target_model, 'resolve')) {
            $pct = $this->Kpi_target_model->resolve($equipment_id, $group_code, $date, null);
        }
        if ($pct === null || $pct === '' || (float)$pct <= 0) {
            $pct = $this->Settings_model->default_availability_target();   // BR-W13
        }
        if ($pct === null || $pct === '' || (float)$pct <= 0) return self::TARGET_FALLBACK;
        return (float)$pct;
    }

    /** Variance of a machine's availability to its target, in percentage points. */
    public function variance_pp($availability_fraction, $target_fraction) {
        if ($availability_fraction === null || $target_fraction === null) return null;
        return round(((float)$availability_fraction - (float)$target_fraction) * 100, 1);
    }

    /**
     * Roll a subset of machines up into one figure — required-weighted, so a
     * machine with one hour of required time does not weigh as much as one with
     * six hundred. A caller that wants a group (a fleet type, a location) must
     * use this rather than averaging the percentages itself: that average is
     * one of the ways the old screens disagreed with each other.
     *
     * @param array $machine_kpis  rows as returned in fleet()['machines']
     */
    public function rollup(array $machine_kpis, $zero_mode = null) {
        $members = array();
        foreach ($machine_kpis as $k) {
            if (!is_array($k)) continue;
            $members[] = array(
                'required_time_hours' => isset($k['required_time_hours']) ? $k['required_time_hours'] : 0,
                'downtime_hours'      => isset($k['downtime_hours']) ? $k['downtime_hours'] : 0,
                'preventive_hours'    => isset($k['preventive_hours']) ? $k['preventive_hours'] : 0,
                'breakdowns'          => isset($k['breakdowns']) ? $k['breakdowns'] : 0,
                'days_down'           => isset($k['days_down']) ? $k['days_down'] : 0,
                'hours_down'          => isset($k['hours_down']) ? $k['hours_down'] : $k['downtime_hours'],
                'weekly_target'       => isset($k['target']) && $k['target'] !== null ? $k['target'] : null,
            );
        }

        $out = kpi_rollup($members, $zero_mode === null ? $this->zero_failure_mode() : $zero_mode);
        $out['availability_pct'] = $out['availability'] === null ? null : round($out['availability'] * 100, 1);
        return $out;
    }
}
