<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PLANNED AVAILABILITY FORECAST
 *
 * Tasks F5–F7, F10, F11 on decisions D29…D33.
 *
 * The business flow this controller walks, in the order the workshop asked for:
 *
 *     plan the week  →  publish (freeze)  →  compare with the actual  →  improve
 *
 * Every number on every screen here comes from `Forecast_model`, which calls
 * `kpi_availability()` / `kpi_rollup()` (D29/D30).  No view recomputes anything.
 */

class Forecast extends MY_Controller {

    public $Forecast_model;
    public $Maintenance_plan_model;

    public function __construct() {
        parent::__construct();
        $this->load->model('Forecast_model');
        $this->load->model('Maintenance_plan_model');
        $this->Maintenance_plan_model->forecast = $this->Forecast_model;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F5 / F7 — THE PLANNED AVAILABILITY WEEK
     * ──────────────────────────────────────────────────────────────── */

    public function index() {
        $data = $this->_week_data();
        $data['page_title'] = 'Planned Availability — ' . $data['iso_label'];
        $this->render('forecast/planned', $data);
    }

    /** Everything the planned-availability screen needs, for one week. */
    private function _week_data() {
        $week_start = $this->_resolve_week();
        $plan = $this->Forecast_model->planned_availability($week_start);

        $data = $plan;
        $data['tables_ready']    = $this->Forecast_model->tables_ready();
        $data['snapshot']        = $this->Forecast_model->baseline_snapshot($week_start);
        $data['history']         = $this->Forecast_model->snapshot_history($week_start);
        $data['standing']        = $this->Forecast_model->standing_allowances();
        $data['allowance_mode']  = $this->_setting_value('unplanned_allowance_mode', 'flat');
        /*
         * Whether the historical workbook's reference figures are shown at all.
         * The export already honoured this; the screen did not, so the setting
         * was a switch that changed only the download.  Both read it now.
         */
        $data['show_reference']  = $this->_setting_value('forecast_show_excel_reference', '1') === '1';
        $data['week_input']      = $this->input->get('week');
        // MP-0 — PM the plan cannot place, shown without generating anything
        $data['pm_issues']       = $this->Forecast_model->pm_schedule_issues($week_start);
        $data['inspections']     = $this->Maintenance_plan_model->inspection_summary();
        $data['job_codes']       = Maintenance_plan_model::$CODES;
        $data['can_plan']        = $this->can_write();
        return $data;
    }

    /**
     * Resolve the week from `?week=` (an ISO label or a date), defaulting to
     * the current week.  Anything unparseable falls back rather than 404ing,
     * so a mistyped link still shows a usable screen.
     */
    private function _resolve_week() {
        $raw = trim((string)$this->input->get('week'));
        if ($raw !== '') {
            $ws = $this->Forecast_model->week_start($raw);
            if ($ws) return $ws;
        }
        return $this->Forecast_model->week_start(date('Y-m-d'));
    }

    private function _setting_value($key, $default) {
        $row = $this->db->table_exists('settings')
            ? $this->db->where('setting_key', $key)->get('settings')->row()
            : null;
        return ($row && $row->setting_value !== '' && $row->setting_value !== null)
            ? $row->setting_value : $default;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F4 / F9 / D31 — BUILDING THE PLAN
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Generate the week's plan from the PM schedules, the standing allowance
     * and the unplanned allowance.  Each generator leaves a planner's manual
     * edit alone, so this is safe to press repeatedly.
     */
    public function generate() {
        if (!$this->require_post()) return;
        $week_start = $this->_resolve_week();
        $label      = $this->Forecast_model->iso_label($week_start);

        if (!$this->Forecast_model->tables_ready()) {
            $this->session->set_flashdata('error',
                'The planned-downtime tables do not exist on this database yet — import the database dump shipped with this repository.');
            redirect('forecast?week=' . $label);
        }

        if ($this->Forecast_model->baseline_snapshot($week_start)) {
            $this->session->set_flashdata('error',
                $label . ' is published and frozen. Raise a revision instead of regenerating it.');
            redirect('forecast?week=' . $label);
        }

        $what = (string)$this->input->post('what');
        $pm   = array('placed' => 0, 'skipped' => 0, 'missing_hours' => 0, 'not_placed' => array(), 'estimated' => array());
        $st   = $un = array('placed' => 0, 'left_alone' => 0, 'skipped_held' => 0, 'removed' => 0);
        $sync = null;

        // MP-3 — the weekly inspections are placed by weekday before the sync,
        // so their hours reach the ledger with the rest of the jobs
        $insp = null;
        if (($what === '' || $what === 'all' || $what === 'inspections') && $this->Maintenance_plan_model->tables_ready()) {
            $insp = $this->Maintenance_plan_model->generate_inspections($week_start, $this->user_id);
        }

        // MP-2 — the planner's jobs reach the ledger first, so the generators
        // see them (and leave their FromPlan rows alone)
        if ($this->Maintenance_plan_model->tables_ready()) {
            $sync = $this->Maintenance_plan_model->sync_week($week_start, $this->user_id);
        }
        if ($what === '' || $what === 'all' || $what === 'pm') {
            $pm = $this->Forecast_model->generate_from_pm($week_start, $this->user_id);
        }
        if ($what === '' || $what === 'all' || $what === 'standing') {
            $st = $this->Forecast_model->generate_standing_allowance($week_start, $this->user_id);
        }
        if ($what === '' || $what === 'all' || $what === 'unplanned') {
            $un = $this->Forecast_model->generate_unplanned_allowance($week_start, $this->user_id);
        }

        $placed = (int)$pm['placed'] + (int)$st['placed'] + (int)$un['placed'] + ($insp ? (int)$insp['placed'] : 0);
        $kept   = (int)$pm['skipped'] + (int)$st['left_alone'] + (int)$un['left_alone'];
        $held   = (int)$st['skipped_held'] + (int)$un['skipped_held'];
        $msg    = $label . ': ' . $placed . ' planned entr' . ($placed === 1 ? 'y' : 'ies') . ' written';
        if ($kept)   $msg .= ', ' . $kept . ' manual or job entr' . ($kept === 1 ? 'y' : 'ies') . ' left alone';
        if ($held)   $msg .= ', ' . $held . ' allowance' . ($held === 1 ? '' : 's') . ' not charged on held days';
        if ($sync && $sync['manual_kept']) {
            $msg .= ', ' . $sync['manual_kept'] . ' job cell' . ($sync['manual_kept'] === 1 ? '' : 's')
                  . ' overridden by a manual entry';
        }
        $problem = false;
        if ($insp && !empty($insp['out_days'])) {
            $problem = true;
            $n = count($insp['out_days']);
            $msg .= '. ' . $n . ' weekly inspection' . ($n === 1 ? ' was' : 's were')
                  . ' not placed: the machine is planned out of service that day (' . $this->_regs($insp['out_days']) . ')';
        }
        if ($insp && !empty($insp['orphans'])) {
            $problem = true;
            $msg .= '. ' . count($insp['orphans']) . ' inspection round' . (count($insp['orphans']) === 1 ? '' : 's')
                  . ' point' . (count($insp['orphans']) === 1 ? 's' : '') . ' at a machine that is no longer reportable';
        }
        if ($pm['missing_hours']) {
            $problem = true;
            $msg .= '. ' . $pm['missing_hours'] . ' PM schedule' . ($pm['missing_hours'] === 1 ? ' has' : 's have')
                  . ' no standard hours and could not be placed — set them on the PM schedule';
        }
        if (!empty($pm['not_placed'])) {
            $problem = true;
            $n = count($pm['not_placed']);
            $msg .= '. ' . $n . ' active PM schedule' . ($n === 1 ? ' has' : 's have')
                  . ' no due date or no meter reading and cannot be placed in any week — see the list on this page';
        }
        if (!empty($pm['estimated'])) {
            $n = count($pm['estimated']);
            $msg .= '. ' . $n . ' hour-meter service' . ($n === 1 ? ' was' : 's were')
                  . ' placed on an estimated date, not one projected from the meter';
        }
        $this->session->set_flashdata($problem ? 'error' : 'success', $msg . '.');

        redirect('forecast?week=' . $label);
    }

    /** MP-3 — create the weekly inspection round for every reportable machine that lacks one. */
    public function create_inspection_rounds() {
        if (!$this->require_post()) return;
        $week_start = $this->_resolve_week();
        $label      = $this->Forecast_model->iso_label($week_start);

        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'You do not have permission to change the plan.');
            redirect('forecast?week=' . $label);
        }

        $res = $this->Maintenance_plan_model->create_inspection_rounds($this->user_id);
        if (!empty($res['error'])) {
            $this->session->set_flashdata('error', $res['error']);
        } else {
            $load = array();
            $names = array(1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun');
            foreach ($res['by_day'] as $d => $n) $load[] = $names[$d] . ' ' . $n;
            $this->session->set_flashdata('success', 'Weekly inspection rounds: ' . $res['created'] . ' created, '
                . $res['existing'] . ' already existed. By weekday — ' . implode(', ', $load) . '. Each takes the inspection hours set in Settings.');
        }
        redirect('forecast?week=' . $label);
    }

    /** Registration numbers of a list of out-of-service days, for a message. */
    protected function _regs(array $rows) {
        $regs = array();
        foreach ($rows as $r) $regs[] = $r['registration_no'] . ' ' . $r['date'];
        return implode(', ', array_unique($regs));
    }

    /** Edit one machine-day-category by hand (the planner's override, D31). */
    public function save_cell() {
        if (!$this->require_post()) return;
        $week_start = $this->_resolve_week();
        $label      = $this->Forecast_model->iso_label($week_start);

        if ($this->Forecast_model->baseline_snapshot($week_start)) {
            $this->session->set_flashdata('error', $label . ' is published and frozen.');
            redirect('forecast?week=' . $label);
        }

        $equipment_id = (int)$this->input->post('equipment_id');
        $plan_date    = trim((string)$this->input->post('plan_date'));
        $category     = (string)$this->input->post('category');
        $hours        = (float)$this->input->post('hours');

        $allowed = $this->Forecast_model->writable_categories();
        if (!$equipment_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $plan_date) || !in_array($category, $allowed, true)) {
            $this->session->set_flashdata('error', 'That change could not be read.');
            redirect('forecast?week=' . $label);
        }

        // the date must be inside the week being planned
        $week_end = $this->Forecast_model->week_end($week_start);
        if ($plan_date < $week_start || $plan_date > $week_end) {
            $this->session->set_flashdata('error', $plan_date . ' is not inside ' . $label . '.');
            redirect('forecast?week=' . $label);
        }

        $this->Forecast_model->set_planned($equipment_id, $plan_date, $category, $hours,
                                           Forecast_model::SRC_MANUAL, 'planner override', $this->user_id);
        $this->session->set_flashdata('success', 'Planned ' . $category . ' hours updated.');
        redirect('forecast?week=' . $label);
    }

    /* ─────────────────────────────────────────────────────────────────
     |  D33 — PUBLISH / FREEZE
     * ──────────────────────────────────────────────────────────────── */

    public function publish() {
        if (!$this->require_post()) return;
        $week_start = $this->_resolve_week();
        $label      = $this->Forecast_model->iso_label($week_start);
        $note       = trim((string)$this->input->post('revision_note'));

        $result = $this->Forecast_model->publish($week_start, $this->user_id, $note);

        if (isset($result['error'])) {
            $this->session->set_flashdata('error', $result['error']);
        } else {
            $rev = (int)$result['revision'];
            $this->session->set_flashdata('success', $rev === 1
                ? $label . ' published — the plan is now the frozen baseline plan-vs-actual is measured against.'
                : $label . ' revision ' . $rev . ' published. The original baseline is preserved.');
        }
        redirect('forecast?week=' . $label);
    }

    /* ─────────────────────────────────────────────────────────────────
     |  MP-2 — MAINTENANCE PLAN JOBS (one row per job, many per machine-day)
     * ──────────────────────────────────────────────────────────────── */

    /** The jobs of one machine on one day of the week, with the editor. */
    public function jobs() {
        $week_start = $this->_resolve_week();
        $data = $this->_jobs_data($week_start,
                                  (int)$this->input->get('equipment_id'),
                                  trim((string)$this->input->get('date')),
                                  (int)$this->input->get('edit'));
        $data['page_title'] = 'Maintenance jobs — ' . $data['iso_label'];
        $this->render('forecast/jobs', $data);
    }

    /** Everything forecast/jobs needs; public-shaped so the view test can build it. */
    private function _jobs_data($week_start, $equipment_id, $date, $edit_id) {
        $fc   = $this->Forecast_model;
        $plan = $fc->planned_availability($week_start);
        $days = $fc->week_dates($week_start);
        if (!in_array($date, $days, true)) $date = $days[0];

        $machine = null;
        foreach ($plan['rows'] as $r) {
            if ($r['equipment_id'] === $equipment_id) { $machine = $r; break; }
        }
        $edit = $edit_id ? $this->Maintenance_plan_model->get($edit_id) : null;

        return array(
            'week_start'   => $week_start,
            'week_end'     => $plan['week_end'],
            'iso_label'    => $plan['iso_label'],
            'days'         => $days,
            'date'         => $date,
            'machine'      => $machine,
            'machines'     => $plan['rows'],
            'jobs_ready'   => $this->Maintenance_plan_model->tables_ready(),
            'frozen'       => (bool)$fc->baseline_snapshot($week_start),
            'can_plan'     => $this->can_write(),
            'edit'         => $edit,
            'job_types'    => Maintenance_plan_model::$JOB_TYPES,
            'categories'   => $fc->writable_categories(),
            'default_category' => Maintenance_plan_model::$DEFAULT_CATEGORY,
            'hold_codes'   => Maintenance_plan_model::$HOLD_CODES,
            'statuses'     => Maintenance_plan_model::$STATUSES,
            'codes'        => Maintenance_plan_model::$CODES,
            'open_hours'   => $fc->daily_open_hours(),
            'warnings'     => $plan['warnings'],
        );
    }

    public function save_job() {
        if (!$this->require_post()) return;
        $week_start = $this->_resolve_week();
        $label      = $this->Forecast_model->iso_label($week_start);
        $back = 'forecast/jobs?week=' . $label . '&equipment_id=' . (int)$this->input->post('equipment_id')
              . '&date=' . rawurlencode((string)$this->input->post('start_date'));

        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'You do not have permission to change the plan.');
            redirect($back);
        }
        $fields = array('equipment_id', 'start_date', 'end_date', 'hours_per_day', 'job_type', 'category',
                        'title', 'hold_code', 'expected_return', 'status');
        $in = array();
        foreach ($fields as $f) $in[$f] = $this->input->post($f);
        $id = (int)$this->input->post('id');

        $res = $this->Maintenance_plan_model->save_job($in, $this->user_id, $id ?: null);
        if (!empty($res['errors'])) {
            $this->session->set_flashdata('error', implode(' ', $res['errors']));
        } else {
            $msg = $id ? 'Job updated.' : 'Job added.';
            $kept = 0;
            foreach ($res['synced'] as $s) $kept += (int)$s['manual_kept'];
            if ($kept) $msg .= ' ' . $kept . ' day' . ($kept === 1 ? ' has' : 's have')
                             . ' a manual entry for that category, which still wins.';
            $this->session->set_flashdata('success', $msg);
        }
        redirect($back);
    }

    public function delete_job() {
        if (!$this->require_post()) return;
        $week_start = $this->_resolve_week();
        $label      = $this->Forecast_model->iso_label($week_start);
        $back = 'forecast/jobs?week=' . $label . '&equipment_id=' . (int)$this->input->post('equipment_id')
              . '&date=' . rawurlencode((string)$this->input->post('date'));
        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'You do not have permission to change the plan.');
            redirect($back);
        }
        $res = $this->Maintenance_plan_model->delete_job((int)$this->input->post('id'), $this->user_id);
        $this->session->set_flashdata(!empty($res['errors']) ? 'error' : 'success',
            !empty($res['errors']) ? implode(' ', $res['errors']) : 'Job removed.');
        redirect($back);
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F6 — PLAN vs ACTUAL
     * ──────────────────────────────────────────────────────────────── */

    public function plan_vs_actual() {
        $week_start = $this->_resolve_week();
        $label      = $this->Forecast_model->iso_label($week_start);

        $actual = $this->_actual_by_group($week_start);
        $data   = $this->Forecast_model->plan_vs_actual($week_start, $actual);

        $data['iso_label']      = $label;
        $data['week_start']     = $week_start;
        $data['week_end']       = $this->Forecast_model->week_end($week_start);
        $data['week_input']     = $this->input->get('week');
        $data['actual_missing'] = $actual === null;
        $data['page_title']     = 'Plan vs Actual — ' . $label;
        $this->render('forecast/plan_vs_actual', $data);
    }

    /**
     * The actual side of the comparison, by group, from the same KPI engine the
     * weekly report uses — so both halves of the variance are one metric.
     *
     * Returns null when the weekly report cannot be built (tables missing,
     * no data), and the screen says so rather than showing zeros.
     *
     * @return array|null group code => kpi_rollup() output, plus '__fleet__'
     */
    private function _actual_by_group($week_start) {
        if (!$this->db->table_exists('weekly_report_snapshots')) return null;

        $this->load->model('W36_report_model');
        try {
            $report = $this->W36_report_model->report_for_week($week_start);
        } catch (Exception $e) {
            return null;
        }
        if (!$report || empty($report['groups'])) return null;

        $out = array();
        foreach ($report['groups'] as $g) {
            if (!isset($g['code'], $g['rollup'])) continue;
            $out[$g['code']] = $g['rollup'];
        }
        if (isset($report['fleet']['rollup'])) $out['__fleet__'] = $report['fleet']['rollup'];
        return $out;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F11 — THE ROLLING THREE-WEEK PLAN
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Weeks +1, +2, +3 in one grid — the database-driven replacement for the
     * `WEEK 40 SCHEDULE` sheet, whose week-41 and week-42 blocks are `#REF!`.
     */
    public function rolling() {
        $base = $this->_resolve_week();
        $tables_ready = $this->Forecast_model->tables_ready();
        if ($tables_ready) {
            list($weeks, $machines) = $this->_rolling_data($base);
        } else {
            $weeks = $machines = array();
        }

        $this->render('forecast/rolling', array(
            'weeks'        => $weeks,
            'machines'     => $machines,
            'tables_ready' => $tables_ready,
            'base_label'   => $this->Forecast_model->iso_label($base),
            'week_input'  => $this->input->get('week'),
            'page_title'  => 'Rolling Maintenance Plan — 3 weeks from ' . $this->Forecast_model->iso_label($base),
        ));
    }

    /**
     * The three-week grid, reshaped so one row reads across the weeks.
     *
     * Shared by the screen and the export (F11/F8) so the two cannot show
     * different weeks or different machines.
     *
     * @param  string $base a week start
     * @return array        [weeks, machines]
     */
    private function _rolling_data($base) {
        $weeks = array();
        for ($i = 0; $i < 3; $i++) {
            $ws   = date('Y-m-d', strtotime($base . ' +' . ($i * 7) . ' days'));
            $plan = $this->Forecast_model->planned_availability($ws);
            $weeks[] = array(
                'offset'     => $i,
                'week_start' => $ws,
                'iso_label'  => $plan['iso_label'],
                'plan'       => $plan,
            );
        }

        $machines = array();
        foreach ($weeks as $w) {
            foreach ($w['plan']['rows'] as $r) {
                $key = $r['registration_no'];
                if (!isset($machines[$key])) {
                    $machines[$key] = array(
                        'equipment_id'    => (int)$r['equipment_id'],
                        'registration_no' => $r['registration_no'],
                        'type'            => $r['type'],
                        'location'        => $r['location'],
                        'weeks'           => array(),
                    );
                }
                $machines[$key]['weeks'][$w['offset']] = $r;
            }
        }
        ksort($machines);

        return array($weeks, array_values($machines));
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F10 — WHAT A PM SERVICE CONSUMES
     |
     |  The entry path for `pm_part_requirements`.  Without it the spares
     |  forecast is a screen that can only ever say "nothing is configured":
     |  the workshop knows what a 250-hour service takes, and this is where it
     |  says so once, after which every weekly forecast reads it.
     |
     |  Reached from the PM planner, where the schedules already live, rather
     |  than from the forecast — you fill in a service's parts where you define
     |  the service.
     * ──────────────────────────────────────────────────────────────── */

    /**
     * The parts a PM schedule consumes, and the form to add another.
     *
     * @param int $id the PM schedule
     */
    public function parts($id) {
        $id = (int)$id;
        $this->load->model('Pm_model');
        $this->load->model('Materials_model');

        $schedule = $id ? $this->Pm_model->get_by_id($id) : null;
        if (!$schedule) {
            $this->session->set_flashdata('error', 'That PM schedule does not exist.');
            redirect('pm');
        }

        $this->render('forecast/parts', array(
            'schedule'        => $schedule,
            'requirements'    => $this->Forecast_model->part_requirements($id),
            'materials'       => $this->Materials_model->get_all(array()),
            'materials_ready' => $this->db->table_exists('materials'),
            'table_ready'     => $this->db->table_exists('pm_part_requirements'),
            'page_title'      => 'Parts for ' . $schedule->schedule_name,
        ));
    }

    /** Add or change one requirement. POST only. */
    public function save_part() {
        if (!$this->require_post()) return;
        $id = (int)$this->input->post('pm_schedule_id');

        if (!$this->Forecast_model->tables_ready()) {
            $this->session->set_flashdata('error',
                'The forecast tables are not set up on this database yet — there is nowhere to store this.');
            redirect('forecast/parts/' . $id);
        }

        $result = $this->Forecast_model->save_part_requirement(
            $id,
            (int)$this->input->post('material_id'),
            $this->input->post('qty'),
            (bool)$this->input->post('is_critical'),
            $this->input->post('note', true)
        );

        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $result['error']);
        } else {
            $this->session->set_flashdata('success', $result['action'] === 'updated'
                ? 'Quantity updated.'
                : 'Part added to the schedule.');
        }
        redirect('forecast/parts/' . $id);
    }

    /** Remove one requirement. POST only, scoped to the schedule. */
    public function delete_part() {
        if (!$this->require_post()) return;
        $id  = (int)$this->input->post('pm_schedule_id');
        $row = (int)$this->input->post('requirement_id');

        if ($this->Forecast_model->delete_part_requirement($row, $id)) {
            $this->session->set_flashdata('success', 'Part removed from the schedule.');
        } else {
            $this->session->set_flashdata('error', 'That part was not on this schedule.');
        }
        redirect('forecast/parts/' . $id);
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F8 — THE WORKBOOK, GENERATED
     |
     |  Built after the dashboard, not before it (the workshop's own
     |  constraint): the export is a reporting format for figures that are
     |  already computed and on screen, never a second place the arithmetic
     |  lives.  Every cell comes from the same Forecast_model call the screen
     |  used, so the workbook cannot disagree with the page.
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Download the week's forecast as a workbook.
     *
     * @param string $which planned (default) | rolling
     */
    public function export($which = 'planned') {
        $week_start = $this->_resolve_week();
        $label      = $this->Forecast_model->iso_label($week_start);

        if (!$this->Forecast_model->tables_ready()) {
            $this->session->set_flashdata('error',
                'The forecast tables are not set up on this database yet — there is no plan to export.');
            redirect('forecast?week=' . $label);
        }

        $this->load->library('Forecast_excel');
        $xl     = new Forecast_excel();
        $sheets = array();

        if ($which === 'rolling') {
            list($weeks, $machines) = $this->_rolling_data($week_start);
            $sheets[] = $xl->build_rolling($weeks, $machines);
            $fname    = $xl->filename($label, 'rolling_plan');
        } else {
            $plan = $this->Forecast_model->planned_availability($week_start);
            $sheets[] = $xl->build_planned($plan, array(
                'show_reference' => $this->_setting_value('forecast_show_excel_reference', '1') === '1',
            ));

            // the comparison sheet only exists once the week is published (D33)
            $pva = $this->Forecast_model->plan_vs_actual($week_start, $this->_actual_by_group($week_start));
            if (!empty($pva['published'])) {
                $sheets[] = $xl->build_plan_vs_actual($pva);
            }
            $fname = $xl->filename($label, 'planned_availability');
        }

        $tmp = wms_temp_path($fname);
        $xl->save($sheets, $tmp);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F10 — SPARE PARTS REQUIRED BY THE PLAN
     * ──────────────────────────────────────────────────────────────── */

    public function spares() {
        $week_start = $this->_resolve_week();
        $horizon    = max(1, min(13, (int)($this->input->get('weeks') ?: 4)));

        $data = $this->Forecast_model->spares_requirement($week_start, $horizon);
        // a view's $this is the Loader in CI3, not the controller, so the table
        // check is done here and passed down
        $data['table_ready']  = $this->db->table_exists('pm_part_requirements');
        $data['lead_times'] = $this->_lead_times();
        $data['lead_times_source_ready'] = $this->db->table_exists('spare_orders');
        $data['on_order_source_ready'] = $this->db->table_exists('spare_orders')
            && $this->db->table_exists('spare_order_items');
        $data['horizon']    = $horizon;
        $data['week_input'] = $this->input->get('week');
        $data['iso_label']  = $this->Forecast_model->iso_label($week_start);
        $data['page_title'] = 'PM Spare-Parts Forecast — ' . $horizon . ' weeks from ' . $data['iso_label'];
        $this->render('forecast/spares', $data);
    }

    /** Measured supplier lead times, so "will it arrive?" is answerable. */
    private function _lead_times() {
        if (!$this->db->table_exists('spare_orders')) return array();
        $this->load->model('Order_model');
        try {
            return $this->Order_model->get_lead_time_by_route();
        } catch (Exception $e) {
            return array();
        }
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F9 — the derived allowance, shown before it is applied
     * ──────────────────────────────────────────────────────────────── */

    /**
     * What the reliability-based unplanned allowance would be, per machine —
     * shown before it is written, so the planner can see the derivation
     * instead of trusting a number that appeared (rule R4).
     */
    public function allowances() {
        $week_start = $this->_resolve_week();
        $derived    = $this->Forecast_model->derived_unplanned_allowances($week_start);
        $history_window = $this->Forecast_model->unplanned_allowance_history_window($week_start);
        $mode = $this->_setting_value('unplanned_allowance_mode', 'flat') === 'derived' ? 'derived' : 'flat';

        $rows = array();
        foreach ($this->db->select('e.id, e.registration_no, et.name AS equip_type')
                              ->from('equipment e')
                              ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
                              ->where('e.status !=', 'Retired')
                              ->order_by('et.name, e.registration_no')
                              ->get()->result() as $m) {
            $eid = (int)$m->id;
            if (!isset($derived[$eid])) continue;
            $rows[] = array(
                'equipment_id'    => $eid,
                'registration_no' => $m->registration_no,
                'type'            => $m->equip_type,
                'standing'        => $this->Forecast_model->standing_allowance_for($m->equip_type),
                'unplanned'       => $derived[$eid]['hours'],
                'source'          => $derived[$eid]['source'],
                'events'          => $derived[$eid]['events'],
                'note'            => $derived[$eid]['note'],
            );
        }

        $this->render('forecast/allowances', array(
            'rows'                 => $rows,
            'mode'                 => $mode,
            'lookback'             => $history_window['weeks'],
            'lookback_from'        => $history_window['from'],
            'lookback_to'          => $history_window['to'],
            'standing'             => $this->Forecast_model->standing_allowances(),
            'iso_label'            => $this->Forecast_model->iso_label($week_start),
            'week_input'           => $this->input->get('week'),
            'can_edit_settings'    => $this->is_supervisor(),
            'page_title'           => 'Planned Allowances — ' . $this->Forecast_model->iso_label($week_start),
        ));
    }
}
