<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * W36 weekly report — the aggregation model (plan task W1.1, with the roll-ups
 * of W1.4 and the target resolution of W1.5).
 *
 * `build()` answers one question: *what does the approved worksheet look like for
 * this week, from the database?* It returns a single nested array — machine rows
 * in sheet order with their seven day blocks, the per-machine weekly roll-up, the
 * group blocks, the per-type block and the fleet row, plus a data-quality list —
 * and nothing else in the application is allowed to compute a W36 number:
 * the preview screen and the Excel writer both read this array.
 *
 * Every rule lives in the pure helpers:
 *   kpi_helper.php     intervals, downtime, days down, availability, MTTR/MTBF,
 *                      the three breakdown KPIs and the roll-ups (W1.2/W1.3/W1.4/W1.6)
 *   Kpi_target_model   the target fallback chain (W1.5)
 *   Settings_model     open hours, the week start, the display modes (W0.7)
 *
 * Nothing is hard-coded: 168 h, 0.88 and the week start all come from settings,
 * the group list and order from `report_groups`, the site vocabulary from
 * `report_sites`, and every target from `equipment_kpi_targets`.
 */
class W36_report_model extends CI_Model {

    /** How the report labels the three breakdown KPIs (D1). */
    const KPI_LABELS = array(
        'breakdowns' => 'NUMBER OF BREAKDOWNS',
        'days_down'  => 'NUMBER OF DAYS DOWN',
        'hours_down' => 'NUMBER OF HOURS DOWN',
    );

    public function __construct() {
        parent::__construct();
        $ci = get_instance();
        if (!$this->db && isset($ci->db)) $this->db = $ci->db;

        // the two collaborators are injectable so the model can be tested
        // without booting the framework
        if (!isset($this->settings) || !$this->settings) {
            $this->settings = class_exists('Settings_model') ? new Settings_model() : null;
            if ($this->settings && !$this->settings->db) $this->settings->db = $this->db;
        }
        if (!isset($this->targets) || !$this->targets) {
            $this->targets = class_exists('Kpi_target_model') ? new Kpi_target_model() : null;
            if ($this->targets && !$this->targets->db) $this->targets->db = $this->db;
        }
    }

    public $settings = null;
    public $targets  = null;
    /** the week's work-order rows as loaded by build() — the action plan prints their text */
    public $work_orders = array();

    /* ────────────────────────────────────────────────────────────────────
     |  the entry point
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * @param  string $week_start 'Y-m-d' (any date in the week is accepted)
     * @param  array  $opts       optional overrides, mainly for tests:
     *                            equipment_rows, work_order_rows, session_rows,
     *                            target_rows, group_rows, site_rows, snapshot
     * @return array
     */
    public function build($week_start = null, array $opts = array()) {
        // ── 1. the period and the settings that shape it
        $start = $this->settings ? $this->settings->week_start($week_start) : date('Y-m-d', strtotime($week_start ?: 'today'));
        $bounds = $this->settings ? $this->settings->week_bounds($start)
                                  : array('start' => $start, 'end' => date('Y-m-d', strtotime($start . ' +6 days')), 'days' => 7);

        $days = array();
        for ($i = 0; $i < 7; $i++) $days[] = date('Y-m-d', strtotime($bounds['start'] . ' +' . $i . ' days'));

        $open_hours = $this->settings ? (float)$this->settings->period_open_hours(7) : 24.0 * 7;
        $daily_open = $this->settings ? (float)$this->settings->daily_open_hours() : 24.0;
        $zero_mode  = $this->settings ? $this->settings->mtbf_zero_failure_mode() : 'dash';
        $count_mode = $this->settings ? $this->settings->breakdown_count_mode() : 'start_in_period';
        $fallback   = $this->settings ? $this->settings->default_availability_target() : null;
        // W36 is only the sample's default label.  A report name belongs to the
        // selected week and is read from the dashboard's saved override when one
        // exists; otherwise it follows the ISO week automatically (W37, W38, ...).
        $report_name = $this->report_name($start, array_key_exists('report_name', $opts) ? $opts['report_name'] : null);

        $period = $this->period($bounds, $opts, $open_hours, $daily_open, $count_mode, $zero_mode);

        // ── 2. the reportable fleet, in sheet order
        $equipment = isset($opts['equipment_rows']) ? $opts['equipment_rows'] : $this->equipment_rows();
        $groups    = isset($opts['group_rows'])     ? $opts['group_rows']     : $this->group_rows();
        $sites     = isset($opts['site_rows'])      ? $opts['site_rows']      : $this->site_rows();

        $machines = $this->arrange($equipment, $groups);
        $ids      = array();
        foreach ($machines as $m) $ids[] = (int)$m->id;

        // ── 3. the records of the week
        $work_orders = isset($opts['work_order_rows']) ? $opts['work_order_rows'] : $this->work_order_rows($ids, $bounds);
        $sessions    = isset($opts['session_rows'])    ? $opts['session_rows']    : $this->session_rows($ids, $bounds);
        // sheet `W36 Synthese` prints the free text of the machine's own work
        // order: keep the week's rows so the action plan needs no second read
        $this->work_orders = $work_orders;

        // the SQL already limits the period; both lists are filtered again in PHP
        // so the behaviour cannot depend on how the driver wrote the query
        $work_orders = array_values(array_filter($work_orders, function ($w) use ($bounds) {
            return $this->wo_touches($w, $bounds);
        }));
        $sessions = array_values(array_filter($sessions, function ($s) use ($bounds) {
            $when = function_exists('kpi_ts') ? kpi_ts(kpi_row($s, 'start_datetime', kpi_row($s, 'end_datetime'))) : null;
            if ($when === null) return true;                       // undated → keep, the engine can still total it
            $from = kpi_ts($bounds['start'] . ' 00:00:00');
            $to   = kpi_ts($bounds['end'] . ' 23:59:59');
            return $when >= $from && $when <= $to;
        }));

        // ── 4. targets (W1.5) for every machine-day
        $target_rows = isset($opts['target_rows']) ? $opts['target_rows'] : $this->target_rows($bounds);
        $resolved    = ($this->targets && method_exists($this->targets, 'resolve_map'))
            ? $this->targets->resolve_map($machines, $days, $target_rows, $fallback)
            : array();

        // ── 5. one machine at a time
        $rows     = array();
        $quality  = array();
        $by_group = array();

        foreach ($machines as $m) {
            $id = (int)$m->id;
            list($wo, $ss) = $this->slice($id, $work_orders, $sessions);

            $kpi = kpi_machine_period($wo, $ss, $period);

            // the daily target column, then the weekly target (BR-W17)
            $tmap = isset($resolved[$id]) ? $resolved[$id] : array('standing' => null, 'days' => array());
            $unresolved = 0;
            foreach ($days as $d) {
                $hit = isset($tmap['days'][$d]) ? $tmap['days'][$d] : null;
                $kpi['daily'][$d]['target']        = $hit ? (float)$hit['pct'] : null;
                $kpi['daily'][$d]['target_source'] = $hit ? $hit['source'] : null;
                $kpi['daily'][$d]['mttr_hours']    = kpi_mttr($kpi['daily'][$d]['downtime_hours'], $kpi['daily'][$d]['failures']);
                $kpi['daily'][$d]['mtbf_hours']    = kpi_mtbf($kpi['daily'][$d]['required_hours'],
                                                              $kpi['daily'][$d]['downtime_hours'],
                                                              $kpi['daily'][$d]['failures'], $zero_mode);
                if ($hit === null) $unresolved++;
            }
            $kpi['weekly'] = array_merge($kpi, kpi_target_weekly($kpi['daily'], $kpi['availability']));
            $kpi['weekly']['target_source'] = isset($tmap['standing']) && $tmap['standing']
                ? $tmap['standing']['source'] : null;

            // sheet-facing identity (BR-W5 for the machine id, the site vocabulary for H)
            $entry = array(
                'equipment_id' => $id,
                'machine_id'   => function_exists('equip_machine_id') ? equip_machine_id($m) : (string)$m->registration_no,
                'registration_no' => $m->registration_no,
                'model'        => trim((string)$m->brand . ' ' . (string)$m->model),
                'group_code'   => isset($m->report_group) ? $m->report_group : null,
                'group_label'  => isset($m->report_group) && isset($groups[$m->report_group]) ? $groups[$m->report_group] : null,
                'report_sort'  => isset($m->report_sort) ? (int)$m->report_sort : 0,
                'status'       => isset($m->status) ? $m->status : null,
                'current_hours' => isset($m->current_hours) ? (float)$m->current_hours : null,
                'current_km'   => isset($m->current_km) ? (float)$m->current_km : null,
                'site'         => $this->site_of($m, $sites),
                'daily'        => $kpi['daily'],
                'weekly'       => $kpi['weekly'],
                'events'       => array(),
                'flags'        => array(),
                'unresolved_target_days' => $unresolved,
            );

            foreach ($kpi['events'] as $e) {
                $entry['events'][] = array(
                    'key'        => $e['key'],
                    'start'      => date('Y-m-d H:i', $e['start']),
                    'end'        => $e['open'] ? null : date('Y-m-d H:i', $e['end']),
                    'open'       => $e['open'],
                    'carried_in' => $e['start'] < kpi_ts($bounds['start'] . ' 00:00:00'),
                    'work_orders'=> $e['work_orders'],
                    'counts_as_breakdown' => ($e['start'] >= kpi_ts($bounds['start'] . ' 00:00:00') && $e['start'] < kpi_ts($bounds['end'] . ' 23:59:59')),
                );
            }

            $rows[$id] = $entry;
            $quality = array_merge($quality, $this->quality_notes($entry, $m, $sites, $unresolved, $days));

            $g = $entry['group_code'];
            if ($g !== null && $g !== '') {
                if (!isset($by_group[$g])) $by_group[$g] = array();
                $by_group[$g][] = $id;
            }
        }

        // ── 6. roll-ups (W1.4)
        $group_blocks = array();
        $weekly_by_machine = array();
        $daily_by_machine  = array();
        foreach ($rows as $id => $r) {
            $weekly_by_machine[$id] = $r['weekly'];
            $daily_by_machine[$id]  = $r['daily'];
        }

        foreach ($groups as $code => $label) {
            $members = isset($by_group[$code]) ? $by_group[$code] : array();
            $group_blocks[] = array(
                'code'     => $code,
                'label'    => $label,
                'machines' => $members,
                'row_count'=> count($members),
                'rollup'   => kpi_rollup($this->pick($weekly_by_machine, $members), $zero_mode),
                'daily'    => kpi_rollup_daily($this->pick($daily_by_machine, $members), $days, $zero_mode),
            );
        }

        $all_ids = array_keys($rows);
        $fleet = array(
            'code'   => 'FLEET',
            'label'  => 'FLEET',
            'machines' => $all_ids,
            'rollup' => kpi_rollup($this->pick($weekly_by_machine, $all_ids), $zero_mode),
            'daily'  => kpi_rollup_daily($this->pick($daily_by_machine, $all_ids), $days, $zero_mode),
            // the plain sums of the machine rows: the fleet row must equal them
            // (the row that reconciles is the protection against the sample's
            // defect D-W3, where rows 101-108 were outside the roll-up range)
            'totals' => array(
                'breakdowns'          => array_sum(array_map(function ($r) { return $r['weekly']['breakdowns']; }, $rows)),
                'days_down'           => array_sum(array_map(function ($r) { return $r['weekly']['days_down']; }, $rows)),
                'hours_down'          => round(array_sum(array_map(function ($r) { return $r['weekly']['hours_down']; }, $rows)), 4),
                'downtime_hours'      => round(array_sum(array_map(function ($r) { return $r['weekly']['downtime_hours']; }, $rows)), 4),
                'preventive_hours'    => round(array_sum(array_map(function ($r) { return $r['weekly']['preventive_hours']; }, $rows)), 4),
                'required_time_hours' => round(array_sum(array_map(function ($r) { return $r['weekly']['required_time_hours']; }, $rows)), 4),
            ),
        );

        // ── 7. the invariant the sample got wrong (D-W3: rows 101-108 dropped)
        $reconcile = array();
        foreach (array('breakdowns', 'days_down') as $k) {
            if ((int)$fleet['rollup'][$k] !== (int)$fleet['totals'][$k]) {
                $reconcile[] = $k . ': the fleet row does not equal the sum of its machines';
            }
        }
        foreach (array('preventive_hours', 'required_time_hours', 'downtime_hours', 'hours_down') as $k) {
            if (abs((float)$fleet['rollup'][$k] - (float)$fleet['totals'][$k]) > 0.01) {
                $reconcile[] = $k . ': the fleet row does not equal the sum of its machines';
            }
        }
        foreach ($reconcile as $msg) {
            $quality[] = array('level' => 'error', 'code' => 'reconcile', 'machine' => null,
                               'message' => $msg . ' — the report must never be published like this');
        }

        // ── 8. the two weekly registers (W4.3 / BR-23, BR-24) and the charts
        $per_type   = $this->per_type($group_blocks, $rows);
        $pm         = $this->pm_service_kpi($bounds, $rows);

        // the register's own warnings join the panel (S24 3/3b — the meter evidence)
        if (!empty($pm['notes'])) $quality = array_merge($quality, $pm['notes']);

        // G4 — a schedule the report cannot judge is a data-quality signal, never
        // a silent omission: name the machine, the schedule and the meter it is
        // missing, so the missing reading can be fixed in the workshop.
        foreach ($pm['not_judged'] as $nj) {
            $quality[] = array(
                'level'   => 'warn',
                'code'    => 'pm_not_judged',
                'machine' => $nj['machine_id'],
                'message' => $nj['machine_id'] . ': PM "' . $nj['schedule'] . '" (' . $nj['interval']
                             . ') is NOT JUDGED — ' . $nj['why'] . '.',
            );
        }
        $inspection = $this->inspection_register($bounds, $rows);
        // the inspection comments of the week (sheet `W36 Synthese`, column D:H)
        $comments   = isset($opts['inspection_comments']) ? $opts['inspection_comments']
                                                          : $this->inspection_comments($bounds);

        // ── 9. the array the preview and the Excel writer both read
        return array(
            // Consumers use this value for the page heading, workbook sheet
            // names, the download name and the print stamp.  Keep it in the
            // report payload so published weeks freeze the name as well as the
            // figures.
            'report_name' => $report_name,
            'week' => array(
                'start' => $bounds['start'], 'end' => $bounds['end'], 'days' => $days,
                'iso_week' => (int)date('W', strtotime($bounds['start'])),
                'iso_year' => (int)date('o', strtotime($bounds['start'])),
                // the label the picker, the page title and the file name all use
                'iso_label' => sprintf('%04d-W%02d', (int)date('o', strtotime($bounds['start'])), (int)date('W', strtotime($bounds['start']))),
                'label' => date('d M Y', strtotime($bounds['start'])) . ' – ' . date('d M Y', strtotime($bounds['end'])),
                'open_hours' => $open_hours, 'daily_open_hours' => $daily_open,
            ),
            'settings' => array(
                'breakdown_count_mode'   => $count_mode,
                'mtbf_zero_failure_mode' => $zero_mode,
                'default_availability_target' => $fallback,
                // G4-stale (D21): the age limit the meter judgements were held to,
                // so a frozen week can say which policy it was judged under
                'pm_meter_stale_days'    => $this->settings ? $this->settings->pm_meter_stale_days() : 30,
            ),
            'kpi_labels' => self::KPI_LABELS,
            'groups'     => $group_blocks,
            'machines'   => $rows,
            'machine_order' => array_keys($rows),
            // the approved site vocabulary, in its own order: the Excel LOCATION
            // dropdown and every site label read it
            'sites'        => array_keys($sites),
            'fleet'      => $fleet,
            'per_type'   => $per_type,
            'pm'         => $pm,
            'inspection' => $inspection,
            'charts'     => $this->charts($group_blocks, $rows, $fleet, $per_type, $pm, $inspection),
            // sheet `W36 Synthese` (Phase B, W5.1/W5.2 — D16/D17)
            'synthese'   => $this->synthese($group_blocks, $rows, $per_type, $pm, $inspection, $comments),
            'data_quality' => $quality,
            'meta' => array(
                'machines'   => count($rows),
                'groups'     => count(array_filter($group_blocks, function ($g) { return $g['row_count'] > 0; })),
                'unclassified' => count(array_filter($rows, function ($r) { return empty($r['group_code']); })),
                // deliberately NO "built at" timestamp: this array is what the
                // freeze stores and hashes, and a week's payload must be a pure
                // function of that week's data — two reads of the same week on
                // the same day must produce the same checksum (D18/AC7). The
                // moment a report was *read* is the controller's `generated_at`,
                // and the moment a week was *published* lives in the snapshot row.
            ),
        );
    }

    /* ────────────────────────────────────────────────────────────────────
     |  data access (override-able for tests through $opts)
     * ──────────────────────────────────────────────────────────────────── */

    private function period($bounds, array $opts, $open_hours, $daily_open, $count_mode, $zero_mode) {
        $p = array(
            'week_start' => $bounds['start'], 'week_end' => date('Y-m-d', strtotime($bounds['end'] . ' +1 day')),
            'open_hours' => $open_hours, 'daily_open_hours' => $daily_open,
            'breakdown_count_mode' => $count_mode, 'mtbf_zero_failure_mode' => $zero_mode,
        );
        if (!empty($opts['snapshot'])) $p['snapshot'] = $opts['snapshot'];
        return $p;
    }

    private function equipment_rows() {
        if (!$this->db || !$this->db->table_exists('equipment')) return array();

        $select = 'e.id, e.registration_no, e.machine_type, e.brand, e.model, e.status, e.location,
                   e.current_hours, e.current_km, e.equipment_type_id';
        // the reportability columns are probed: older databases may not have them
        foreach (array('legacy_code', 'report_group', 'report_sort', 'include_in_weekly_report') as $col) {
            if ($this->column_exists('equipment', $col)) $select .= ', e.' . $col;
        }

        return $this->db->select($select)
            ->from('equipment e')
            ->where('e.status !=', 'Retired')
            ->order_by('e.registration_no', 'ASC')
            ->get()->result();
    }

    private function work_order_rows(array $ids, $bounds) {
        if (!$this->db || !$this->db->table_exists('work_orders')) return array();

        $week_start = $bounds['start'] . ' 00:00:00';
        $week_end   = date('Y-m-d', strtotime($bounds['end'] . ' +1 day')) . ' 00:00:00';

        // an event matters when it touches the week: it started before the end
        // of the week and was either still open at the start of it or restored
        // after (carried-over failures are why days/hours down exist, D4)
        return $this->db->select('w.id, w.wo_number, w.equipment_id, w.type, w.is_breakdown, w.breakdown_event_id,
                                  w.status, w.hold_code, w.priority, w.machine_status, w.equipment_systems,
                                  w.title, w.description, w.symptoms, w.pending_work, w.action_taken,
                                  w.start_datetime, w.end_datetime, w.downtime_hours,
                                  w.actual_hours, w.hours_reading')
            ->from('work_orders w')
            ->where('w.start_datetime <', $week_end)
            ->group_start()
                ->or_where('w.end_datetime IS NULL', null, false)
                ->or_where('w.end_datetime >', $week_start)
            ->group_end()
            ->get()->result();
    }

    private function session_rows(array $ids, $bounds) {
        if (!$this->db || !$this->db->table_exists('wo_sessions')) return array();

        return $this->db->select('s.id, s.wo_id, s.technician_id, s.session_no, s.start_datetime,
                                  s.end_datetime, s.hours_worked, s.status, s.work_performed')
            ->from('wo_sessions s')
            ->where('s.start_datetime >=', $bounds['start'] . ' 00:00:00')
            ->where('s.start_datetime <=', $bounds['end'] . ' 23:59:59')
            ->get()->result();
    }

    private function target_rows($bounds) {
        if (!$this->targets || !method_exists($this->targets, 'for_week')) return array();
        return $this->targets->for_week($bounds['start'], $bounds['end']);
    }

    private function group_rows() {
        if (!$this->db || !$this->db->table_exists('report_groups')) return array();
        $rows = $this->db->order_by('sort_order', 'ASC')->get('report_groups')->result();
        $out = array();
        foreach ($rows as $r) $out[$r->code] = $r->label;
        return $out;
    }

    private function site_rows() {
        if (!$this->db || !$this->db->table_exists('report_sites')) return array();
        $rows = $this->db->order_by('sort_order', 'ASC')->get('report_sites')->result();
        $out = array();
        foreach ($rows as $r) $out[$r->label] = $r->code;
        return $out;
    }

    private function column_exists($table, $column) {
        static $cache = array();
        $key = $table . '.' . $column;
        if (isset($cache[$key])) return $cache[$key];

        $exists = false;
        if ($this->db && method_exists($this->db, 'table_exists') && $this->db->table_exists($table)
            && method_exists($this->db, 'field_exists')) {
            $exists = (bool)$this->db->field_exists($column, $table);
        }
        return $cache[$key] = $exists;
    }

    /* ────────────────────────────────────────────────────────────────────
     |  ordering, slicing, quality
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * The reportable fleet in sheet order: group sort_order, then the machine's
     * report position, then the registration number. A machine without a report
     * group sorts last — it cannot be printed inside a group block and is
     * reported in the data-quality panel instead (W0.3).
     */
    private function arrange(array $equipment, array $groups) {
        $order = array();
        $i = 0;
        foreach ($groups as $code => $label) $order[$code] = $i++;

        $rows = array();
        foreach ($equipment as $e) {
            if (function_exists('equip_is_reportable') && !equip_is_reportable($e)) continue;
            $rows[] = $e;
        }

        usort($rows, function ($a, $b) use ($order) {
            $ga = isset($a->report_group) ? $a->report_group : '';
            $gb = isset($b->report_group) ? $b->report_group : '';
            $oa = ($ga !== '' && isset($order[$ga])) ? $order[$ga] : 9999;
            $ob = ($gb !== '' && isset($order[$gb])) ? $order[$gb] : 9999;
            if ($oa !== $ob) return $oa - $ob;

            $sa = isset($a->report_sort) ? (int)$a->report_sort : 0;
            $sb = isset($b->report_sort) ? (int)$b->report_sort : 0;
            if ($sa !== $sb) return $sa - $sb;

            return strcmp((string)$a->registration_no, (string)$b->registration_no);
        });
        return $rows;
    }

    private function slice($id, array $work_orders, array $sessions) {
        $wo = array();
        foreach ($work_orders as $w) {
            if ((int)kpi_row($w, 'equipment_id') === (int)$id) $wo[] = $w;
        }
        $wo_ids = array();
        foreach ($wo as $w) $wo_ids[] = (string)kpi_row($w, 'id', kpi_row($w, 'wo_number', ''));

        $ss = array();
        foreach ($sessions as $s) {
            $wo_id = (string)kpi_row($s, 'wo_id', kpi_row($s, 'work_order_id', ''));
            if (in_array($wo_id, $wo_ids, true)) $ss[] = $s;
        }
        return array($wo, $ss);
    }

    private function wo_touches($w, $bounds) {
        $start = kpi_ts(kpi_row($w, 'start_datetime', kpi_row($w, 'created_at')));
        if ($start === null) return false;

        $week_start = kpi_ts($bounds['start'] . ' 00:00:00');
        $week_end   = kpi_ts($bounds['end'] . ' 23:59:59');
        if ($start > $week_end) return false;

        $end = kpi_ts(kpi_row($w, 'end_datetime'));
        if ($end === null) return true;                     // still open → covers the week
        return $end >= $week_start;
    }

    private function site_of($machine, array $sites) {
        $loc = trim((string)(isset($machine->location) ? $machine->location : ''));
        $approved = $loc !== '' && function_exists('equip_is_approved_site') && equip_is_approved_site($loc, $sites);
        return array(
            'stored'   => $loc,
            'approved' => $approved,
            'label'    => $loc === '' ? '' : (function_exists('equip_site_label') ? equip_site_label($loc, $sites) : $loc),
        );
    }

    /** Everything the panel must tell the reader about one machine (W1.1/V-checks). */
    private function quality_notes(array $entry, $machine, array $sites, $unresolved_days, array $days) {
        $notes = array();
        $who = $entry['machine_id'];

        if (empty($entry['group_code'])) {
            $notes[] = array('level' => 'warn', 'code' => 'no_group', 'machine' => $who,
                             'message' => $who . ' has no weekly-report group, so it cannot be printed inside a group block.');
        }

        if ($unresolved_days > 0) {
            $notes[] = array('level' => $unresolved_days >= count($days) ? 'warn' : 'info',
                             'code' => 'no_target', 'machine' => $who,
                             'message' => $who . ': ' . $unresolved_days . ' of ' . count($days)
                                        . ' day(s) have no availability target (no machine, group or fallback value).');
        }

        if ($entry['site']['stored'] === '') {
            $notes[] = array('level' => 'info', 'code' => 'no_site', 'machine' => $who,
                             'message' => $who . ' has no location, so the LOCATION column will be empty.');
        } elseif (!$entry['site']['approved']) {
            $notes[] = array('level' => 'warn', 'code' => 'site_not_approved', 'machine' => $who,
                             'message' => $who . ': "' . $entry['site']['stored'] . '" is not one of the approved sites.');
        }

        // OPEN-7: the period figures come from work orders, the current status
        // from the equipment record — a disagreement is worth showing
        $down_status = function_exists('equip_is_down') && equip_is_down($machine);
        $open_wo = false;
        foreach ($entry['events'] as $e) if ($e['open']) $open_wo = true;

        if ($down_status && !$open_wo) {
            $notes[] = array('level' => 'warn', 'code' => 'status_without_wo', 'machine' => $who,
                             'message' => $who . ' reads "' . $entry['status'] . '" but has no open breakdown work order.');
        } elseif (!$down_status && $open_wo) {
            $notes[] = array('level' => 'warn', 'code' => 'wo_without_status', 'machine' => $who,
                             'message' => $who . ' has an open breakdown work order but its status reads "'
                                        . $entry['status'] . '".');
        }

        foreach ($entry['events'] as $e) {
            if ($e['carried_in']) {
                $notes[] = array('level' => 'info', 'code' => 'carried_in', 'machine' => $who,
                                 'message' => $who . ': a breakdown started ' . $e['start']
                                            . ', before this week — it shows through days/hours down, not in the failure count (OPEN-2).');
            }
        }

        if (!$entry['weekly']['has_data']) {
            $notes[] = array('level' => 'info', 'code' => 'no_records', 'machine' => $who,
                             'message' => $who . ' has no work order or labour record in this week.');
        }

        if (isset($entry['weekly']['days_down']) && $entry['weekly']['days_down'] > 7) {
            $notes[] = array('level' => 'info', 'code' => 'days_down_over_week', 'machine' => $who,
                             'message' => $who . ': days down (' . $entry['weekly']['days_down']
                                        . ') exceeds the week — the machine was already down before it (D4).');
        }

        return $notes;
    }

    private function pick(array $by_machine, array $ids) {
        $out = array();
        foreach ($ids as $id) if (isset($by_machine[$id])) $out[$id] = $by_machine[$id];
        return $out;
    }

    /**
     * The per-type block (BQ:CB) — same numbers as the group rows, keyed by group
     * code and carrying the two counters the block prints first.
     */
    /* ────────────────────────────────────────────────────────────────────
     |  W4.3 — the weekly PM and inspection registers (BR-23, BR-24)
     |
     |  Two figures the preview needs and sheet `W36` itself does not carry.
     |  They are read from their own tables and restricted to the machines the
     |  report prints, so a schedule or an inspection of a machine outside the
     |  reportable fleet can never move a KPI.
     |
     |  * PM services (BR-24): an **Active** `pm_schedules` row is *planned* when
     |    it falls due inside the week. The date rule (`next_due_date` inside the
     |    week) is always applied; the hours rule (`next_due_hours` reached by the
     |    machine's current reading) is applied when the machine has a reading and
     |    is reported as *not judged* otherwise — the meters are stale (gap G4),
     |    and a schedule nobody can judge must not be counted as undone.
     |    *Done* = a `pm_completions` row with `completed_date` inside the week.
     |
     |  * Inspection register (BR-23): one row per reportable machine, with the
     |    sample's precedence — **BREAKDOWN** (down now, or still down at the week
     |    end) beats **DONE** (an inspection inside the week) beats **UNDONE**.
     * ──────────────────────────────────────────────────────────────────── */

    public function pm_service_kpi(array $bounds, array $rows) {
        $out = array(
            'planned' => 0, 'done' => 0, 'undone' => 0, 'ratio' => null,
            'by_interval' => array(), 'not_judged' => array(), 'schedules' => array(),
            'notes' => array(),
        );
        if (!$this->db || !$this->db->table_exists('pm_schedules')) return $out;

        // ── the evidence behind a meter judgement (S24 3/3b, gap G4-stale) ────
        // The register judges with the DENORMALISED meter (`equipment.current_hours`
        // / `current_km`), so a value nobody has refreshed looks exactly like a fresh
        // one.  The dated readings are read HERE FOR EVIDENCE ONLY — the judgement
        // does not change — and the panel says when the value cannot be corroborated
        // with a reading from the week it is reported for.
        $meter_seen = array();                    // "equipment|meter" => newest reading date
        if ($this->db->table_exists('equipment_meter_readings')) {
            $readings = $this->db->select('r.equipment_id, r.reading_date, r.hours, r.km')
                ->from('equipment_meter_readings r')
                ->get()->result();
            foreach ($readings as $r) {
                $eq = (int)kpi_row($r, 'equipment_id', 0);
                $day = substr((string)kpi_row($r, 'reading_date', ''), 0, 10);
                if ($eq <= 0 || $day === '') continue;
                foreach (array('hours', 'km') as $meter) {
                    $v = kpi_row($r, $meter, null);
                    if ($v === null || (float)$v <= 0) continue;
                    $key = $eq . '|' . $meter;
                    if (!isset($meter_seen[$key]) || $day > $meter_seen[$key]) $meter_seen[$key] = $day;
                }
            }
        }

        // ── G4-stale: how old the reading behind a meter judgement may be ─────
        // A schedule whose due date is empty is judged by its meter alone, so the
        // judgement is only as good as the reading behind it.  `pm_meter_stale_days`
        // (settings, default 30) is the age limit: when the newest reading is older
        // than that many days before the reported week — or there is none on file —
        // the schedule is NOT JUDGED, never guessed.  0 switches the check off
        // (D21; the policy was the business's to set, the default is
        // the recommended one).
        $stale_days   = $this->settings ? (int)$this->settings->pm_meter_stale_days() : 30;
        $stale_before = $stale_days > 0
            ? date('Y-m-d', strtotime($bounds['start'] . ' -' . $stale_days . ' days')) : null;

        // the machines this report prints, by id
        $ids = array();
        foreach ($rows as $id => $r) $ids[(int)$id] = $r;

        $done_ids = array();
        if ($this->db->table_exists('pm_completions')) {
            $completions = $this->db->select('c.id, c.pm_schedule_id, c.completed_date')
                ->from('pm_completions c')
                ->where('c.completed_date >=', $bounds['start'])
                ->where('c.completed_date <=', $bounds['end'])
                ->get()->result();
            foreach ($completions as $c) {
                $when = substr((string)$c->completed_date, 0, 10);
                if ($when !== '' && $when >= $bounds['start'] && $when <= $bounds['end']) {
                    $done_ids[(int)$c->pm_schedule_id] = true;
                }
            }
        }

        $schedules = $this->db->select('s.id, s.equipment_id, s.schedule_name, s.interval_type,
                                        s.interval_value, s.status, s.next_due_date, s.next_due_hours')
            // `interval_type` decides which meter judges a schedule: Hours →
            // equipment.current_hours, Km → current_km (G4).  A kilometre
            // schedule keeps its threshold in `next_due_hours` — the column the
            // ENUM extension was made for.
            ->from('pm_schedules s')
            ->where('s.status', 'Active')
            ->get()->result();

        foreach ($schedules as $s) {
            $equipment_id = (int)$s->equipment_id;
            if (!isset($ids[$equipment_id])) continue;               // not a machine this report prints

            $due_date  = isset($s->next_due_date) && $s->next_due_date !== null && $s->next_due_date !== ''
                ? substr((string)$s->next_due_date, 0, 10) : null;
            $date_due  = ($due_date !== null && $due_date >= $bounds['start'] && $due_date <= $bounds['end']);

            // which meter judges this schedule — hours by default, kilometres for
            // the km-based intervals of light vehicles and bowsers (G4)
            $is_km = isset($s->interval_type) && strtoupper((string)(trim((string)$s->interval_type))) === 'KM';
            $meter = $is_km ? 'km' : 'hours';
            $current = $is_km
                ? (isset($ids[$equipment_id]['current_km']) ? $ids[$equipment_id]['current_km'] : null)
                : (isset($ids[$equipment_id]['current_hours']) ? $ids[$equipment_id]['current_hours'] : null);
            // a reading of 0/absent is not a reading: the due condition cannot be
            // judged, and the report says so instead of guessing (G4)
            if ($current !== null && (float)$current <= 0) $current = null;

            $hours_due = null;                                       // null = cannot be judged (G4)
            if (isset($s->next_due_hours) && $s->next_due_hours !== null && $current !== null) {
                $hours_due = ((float)$s->next_due_hours <= (float)$current);
            }

            // the newest reading on file behind the meter value (G4-stale evidence)
            $seen = isset($meter_seen[$equipment_id . '|' . $meter])
                ? $meter_seen[$equipment_id . '|' . $meter] : null;

            $judged = $date_due || $hours_due === true;
            if (!$judged && $hours_due === null && $due_date === null) {
                // interval-based, with no reading to judge it against
                $out['not_judged'][] = array(
                    'equipment_id' => $equipment_id,
                    'machine_id'   => $ids[$equipment_id]['machine_id'],
                    'schedule'     => (string)$s->schedule_name,
                    'interval'     => trim((string)$s->interval_value . ' ' . (string)$s->interval_type),
                    'meter'        => $meter,
                    'why'          => ($is_km ? 'kilometre' : 'hours') . '-based schedule without a current '
                                      . $meter . ' reading (gap G4)',
                );
                continue;
            }

            // G4-stale: only the meter can judge this schedule, and the reading
            // ON FILE behind the meter value is older than the policy allows — so
            // the schedule is NOT JUDGED, and it is not quietly counted either way.
            // When there is no reading at all the age cannot be known: the record
            // alone judges it, exactly as before, and the `pm_meter_age` note below
            // says so (extending the rule to that case is a separate decision —
            // it would stop judging every installation without reading history).
            if ($due_date === null && $stale_before !== null
                && $seen !== null && $seen < $stale_before) {
                $out['not_judged'][] = array(
                    'equipment_id' => $equipment_id,
                    'machine_id'   => $ids[$equipment_id]['machine_id'],
                    'schedule'     => (string)$s->schedule_name,
                    'interval'     => trim((string)$s->interval_value . ' ' . (string)$s->interval_type),
                    'meter'        => $meter,
                    'stale'        => true,
                    'why'          => ($is_km ? 'kilometre' : 'hours') . '-based schedule: its newest '
                                      . $meter . ' reading is dated ' . $seen . ', more than '
                                      . $stale_days . ' days before the week (gap G4-stale).',
                );
                continue;
            }
            if (!$judged) continue;                                  // due later, not this week

            $done = isset($done_ids[(int)$s->id]);
            $label = trim((string)$s->interval_value . ' ' . (string)$s->interval_type);

            $out['planned']++;
            if ($done) $out['done']++; else $out['undone']++;
            if (!isset($out['by_interval'][$label])) {
                $out['by_interval'][$label] = array('label' => $label, 'planned' => 0, 'done' => 0, 'undone' => 0);
            }
            $out['by_interval'][$label]['planned']++;
            $out['by_interval'][$label][$done ? 'done' : 'undone']++;

            // the reading the judgement rests on, and the newest one on file
            $out['schedules'][] = array(
                'equipment_id' => $equipment_id,
                'machine_id'   => $ids[$equipment_id]['machine_id'],
                'group_label'  => $ids[$equipment_id]['group_label'],
                'schedule'     => (string)$s->schedule_name,
                'interval'     => $label,          // the database's own label (`750 Hours`)
                'due_date'     => $due_date,
                'status'       => $done ? 'DONE' : 'UNDONE',
                'due_by'       => $date_due ? 'date' : $meter,
                'interval_type'=> (string)$s->interval_type,
                'meter_value'  => $current,
                'meter_reading_on' => $seen,
            );

            // the meter judged it: is the value corroborated inside this week?
            if (!$date_due) {
                $unit = $is_km ? 'km' : 'h';
                if ($seen === null) {
                    $out['notes'][] = array('level' => 'info', 'code' => 'pm_meter_age',
                        'machine' => $ids[$equipment_id]['machine_id'],
                        'message' => $ids[$equipment_id]['machine_id'] . ': the ' . $label
                                     . ' service was judged with ' . $current . ' ' . $unit
                                     . ', and no ' . $meter . ' reading is on file for it — the'
                                     . ' judgement rests on the equipment record alone (gap G4).');
                } elseif ($seen < $bounds['start']) {
                    $out['notes'][] = array('level' => 'info', 'code' => 'pm_meter_age',
                        'machine' => $ids[$equipment_id]['machine_id'],
                        'message' => $ids[$equipment_id]['machine_id'] . ': the ' . $label
                                     . ' service was judged with ' . $current . ' ' . $unit
                                     . ', but the newest ' . $meter . ' reading on file is dated '
                                     . $seen . ' — before this week, so the meter may have moved'
                                     . ' since (gap G4).');
                }
            }
        }

        $out['ratio'] = $out['planned'] > 0 ? round($out['done'] / $out['planned'], 4) : null;
        return $out;
    }

    public function inspection_register(array $bounds, array $rows) {
        $out = array(
            'planned' => count($rows), 'done' => 0, 'undone' => 0, 'breakdown' => 0,
            'ratios' => array('done' => null, 'undone' => null, 'breakdown' => null),
            'rows' => array(),
        );

        $inspected = array();
        if ($this->db && $this->db->table_exists('inspection_records')) {
            $records = $this->db->select('r.id, r.equipment_id, r.inspection_date, r.status')
                ->from('inspection_records r')
                ->where('r.inspection_date >=', $bounds['start'])
                ->where('r.inspection_date <=', $bounds['end'])
                ->get()->result();
            foreach ($records as $rec) {
                if ((int)$rec->equipment_id <= 0) continue;
                $when = substr((string)$rec->inspection_date, 0, 10);
                if ($when < $bounds['start'] || $when > $bounds['end']) continue;
                $status = isset($rec->status) ? strtolower((string)$rec->status) : 'submitted';
                if ($status !== '' && $status !== 'submitted') continue;   // a draft is not an inspection yet
                $id = (int)$rec->equipment_id;
                if (!isset($inspected[$id]) || $when > $inspected[$id]) $inspected[$id] = $when;
            }
        }

        foreach ($rows as $id => $r) {
            $down = $this->machine_down_now($r) || $this->machine_still_down($r);
            if ($down)                 $status = 'BREAKDOWN';
            elseif (isset($inspected[(int)$id])) $status = 'DONE';
            else                       $status = 'UNDONE';

            $out[strtolower((string)($status))]++;
            $out['rows'][] = array(
                'equipment_id' => (int)$id,
                'machine_id'   => $r['machine_id'],
                'group_label'  => $r['group_label'],
                'status'       => $status,
                'inspected_on' => isset($inspected[(int)$id]) ? $inspected[(int)$id] : null,
                'hours_down'   => $r['weekly']['hours_down'],
            );
        }

        if ($out['planned'] > 0) {
            foreach (array('done', 'undone', 'breakdown') as $k) {
                $out['ratios'][$k] = round($out[$k] / $out['planned'], 4);
            }
        }
        return $out;
    }

    /* ────────────────────────────────────────────────────────────────────
     |  sheet `W36 Synthese` (Phase B — W5.1/W5.2, decisions D16/D17)
     |
     |  Three sections, all read from the array build() already produced:
     |    * the workshop action plan — one row per reportable machine (BR-25)
     |    * the inspection + PM registers — one row per machine (BR-26)
     |    * their counters and ratios — counted from those rows (BR-27)
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * The whole `W36 Synthese` content, ready for the sheet and the preview.
     *
     * @param  array $group_blocks the report groups in sheet order
     * @param  array $rows         the machine entries of build()
     * @param  array $per_type     the per-type block (available/down counters)
     * @param  array $pm           pm_service_kpi()
     * @param  array $inspection   inspection_register()
     * @param  array $comments     equipment_id => array of comment lines
     * @return array action_plan, register, kpi, legend
     */
    public function synthese(array $group_blocks, array $rows, array $per_type, array $pm, array $inspection, array $comments = array()) {
        $ap = $this->action_plan($group_blocks, $rows, $per_type);
        return array(
            'action_plan' => $ap['rows'],
            'register'    => $this->synthese_register($group_blocks, $rows, $pm, $inspection, $comments),
            'kpi'         => $this->synthese_kpi($pm, $inspection),
            'legend'      => $ap['legend'],
        );
    }

    /**
     * The WORKSHOP ACTION PLAN of sheet `W36 Synthese` (D16, rule BR-25):
     * every reportable machine, in sheet order, under one group row per report
     * group. The comment / action / status columns are read from the machine's
     * own breakdown work order — the report never asks anyone to type them.
     */
    public function action_plan(array $group_blocks, array $rows, array $per_type) {
        $legend = function_exists('wo_hold_codes') ? wo_hold_codes() : array();
        $out    = array();

        foreach ($group_blocks as $g) {
            $members = isset($g['machines']) ? $g['machines'] : array();
            if (empty($members)) continue;                     // a group with no machine keeps no block

            $pt = isset($per_type[$g['code']]) ? $per_type[$g['code']] : array();
            $out[] = array(
                'type'         => 'group',
                'code'         => $g['code'],
                'label'        => $g['label'],
                'machines'     => count($members),
                'availability' => $this->num($g['rollup'], 'availability'),
                'target'       => $this->num($g['rollup'], 'weekly_target'),
                'available'    => isset($pt['available']) ? $pt['available'] : null,
                'down'         => isset($pt['down']) ? $pt['down'] : null,
            );

            foreach ($members as $id) {
                if (!isset($rows[$id])) continue;
                $m      = $rows[$id];
                $wo     = $this->breakdown_work_order($id);              // what is being done
                $anchor = $this->breakdown_work_order($id, 'anchor');    // what failed

                $hold = ($wo && isset($wo->hold_code)) ? strtoupper((string)(trim((string)$wo->hold_code))) : '';
                // the comment describes the failure: the anchor work order's text,
                // falling back to the current one when the anchor has none
                $comment = $anchor ? $this->wo_text($anchor, array('description', 'title', 'symptoms')) : null;
                if ($comment === null && $wo) $comment = $this->wo_text($wo, array('description', 'title', 'symptoms'));
                $out[] = array(
                    'type'         => 'machine',
                    'equipment_id' => (int)$id,
                    'machine_id'   => $m['machine_id'],
                    'group_label'  => $m['group_label'],
                    'model'        => $m['model'],
                    'location'     => isset($m['site']['label']) ? $m['site']['label'] : null,
                    'location_approved' => !empty($m['site']['approved']),
                    'current_hours'=> isset($m['current_hours']) ? $m['current_hours'] : null,
                    'current_km'   => isset($m['current_km']) ? $m['current_km'] : null,
                    'status'       => $m['status'],
                    'availability' => $this->num($m['weekly'], 'availability'),
                    // the machine's weekly target is the KPI engine's own key
                    'target'       => $this->num($m['weekly'], 'weekly_target') !== null
                                      ? $this->num($m['weekly'], 'weekly_target')
                                      : $this->num($m['weekly'], 'target'),
                    'comment'      => $comment,
                    'action_plan'  => $wo ? $this->wo_text($wo, array('pending_work', 'action_taken')) : null,
                    'days_down'    => isset($m['weekly']['days_down']) ? (int)$m['weekly']['days_down'] : 0,
                    'hours_down'   => $this->num($m['weekly'], 'hours_down'),
                    'hold_code'    => $hold !== '' ? $hold : null,
                    'hold_label'   => function_exists('wo_hold_label') ? wo_hold_label($hold) : ($hold !== '' ? $hold : null),
                    'work_order'   => $wo ? (string)$wo->wo_number : null,
                );
            }
        }

        return array('rows' => $out, 'legend' => $legend);
    }

    /**
     * The two registers of sheet `W36 Synthese`, one row per machine: the
     * inspection status and comments on the left, the planned PM services
     * (with their DATABASE interval label) on the right.
     */
    public function synthese_register(array $group_blocks, array $rows, array $pm, array $inspection, array $comments = array()) {
        $insp = array();
        foreach ((array)$inspection['rows'] as $r) $insp[(int)$r['equipment_id']] = $r;

        $pms = array();
        foreach ((array)$pm['schedules'] as $sc) {
            $id = (int)$sc['equipment_id'];
            if (!isset($pms[$id])) $pms[$id] = array();
            $pms[$id][] = $sc;
        }

        $out = array();
        foreach ($group_blocks as $g) {
            $members = isset($g['machines']) ? $g['machines'] : array();
            if (empty($members)) continue;

            $out[] = array('type' => 'group', 'code' => $g['code'], 'label' => $g['label'], 'machines' => count($members));

            foreach ($members as $id) {
                if (!isset($rows[$id])) continue;
                $m    = $rows[$id];
                $down = $this->machine_down_now($m) || $this->machine_still_down($m);

                $services = array();
                foreach (isset($pms[$id]) ? $pms[$id] : array() as $sc) {
                    $services[] = array(
                        'type'    => isset($sc['interval']) ? $sc['interval'] : $sc['schedule'],
                        'status'  => $sc['status'],
                        'comment' => $this->pm_comment($sc, $down),
                        // the evidence the verdict rests on (G4-stale): the meter value
                        // judged and the newest dated reading behind it, so a screen can
                        // show the age instead of passing an old reading off as fresh
                        'due_by'           => isset($sc['due_by']) ? $sc['due_by'] : null,
                        'meter_value'      => isset($sc['meter_value']) ? $sc['meter_value'] : null,
                        'meter_reading_on' => isset($sc['meter_reading_on']) ? $sc['meter_reading_on'] : null,
                    );
                }

                $out[] = array(
                    'type'            => 'machine',
                    'equipment_id'    => (int)$id,
                    'machine_id'      => $m['machine_id'],
                    'group_label'     => $m['group_label'],
                    'inspection'      => array(
                        'status'     => isset($insp[$id]['status']) ? $insp[$id]['status'] : 'UNDONE',
                        'inspected_on'=> isset($insp[$id]['inspected_on']) ? $insp[$id]['inspected_on'] : null,
                        'comments'   => isset($comments[$id]) ? array_values((array)$comments[$id]) : array(),
                    ),
                    'pm'              => array('services' => $services),
                    'hours_down'      => $this->num($m['weekly'], 'hours_down'),
                );
            }
        }
        return $out;
    }

    /**
     * The `M`/`N` counters of sheet `W36 Synthese` (rule BR-27): every figure is
     * counted from the rows the registers actually wrote — the sample's fixed
     * `181:281` windows would silently miss rows as the fleet grows.
     */
    public function synthese_kpi(array $pm, array $inspection) {
        $pm_planned = (int)$pm['planned'];
        $machines   = (int)$inspection['planned'];

        return array(
            'pm' => array(
                'planned'      => $pm_planned,
                'done'         => (int)$pm['done'],
                'undone'       => (int)$pm['undone'],
                'ratio_done'   => $pm_planned > 0 ? round((int)$pm['done'] / $pm_planned, 4) : null,
                'ratio_undone' => $pm_planned > 0 ? round((int)$pm['undone'] / $pm_planned, 4) : null,
            ),
            'inspection' => array(
                'machines'        => $machines,
                'done'            => (int)$inspection['done'],
                'undone'          => (int)$inspection['undone'],
                'breakdown'       => (int)$inspection['breakdown'],
                'ratio_done'      => $machines > 0 ? round((int)$inspection['done'] / $machines, 4) : null,
                'ratio_undone'    => $machines > 0 ? round((int)$inspection['undone'] / $machines, 4) : null,
                'ratio_breakdown' => $machines > 0 ? round((int)$inspection['breakdown'] / $machines, 4) : null,
            ),
            // the sample's M198: the machines inspected plus the PM services planned
            'total_planned' => $machines + $pm_planned,
        );
    }

    /**
     * The inspection comments of the week, per machine (sheet `W36 Synthese`,
     * column D:H) — the free text the inspection recorded, nothing invented.
     */
    private function inspection_comments($bounds) {
        $out = array();
        if (!$this->db || !$this->db->table_exists('inspection_records')) return $out;

        // the remark of a submitted inspection: its items' comments, or the
        // record's own general comment when the template has no items
        $has_items = $this->db->table_exists('inspection_record_items');
        $select = $has_items
            ? 'r.equipment_id, i.comment, r.general_comments'
            : 'r.equipment_id, r.general_comments AS comment';

        $q = $this->db->select($select)
            ->from('inspection_records r')
            ->where('r.inspection_date >=', $bounds['start'])
            ->where('r.inspection_date <=', $bounds['end'])
            ->where('r.status', 'submitted');
        if ($has_items) $q = $q->join('inspection_record_items i', 'i.record_id = r.id', 'left');

        foreach ($q->get()->result() as $row) {
            $id = (int)$row->equipment_id;
            if ($id <= 0) continue;
            // the item remarks first, then the record's own general comment —
            // both were written by the inspector, neither is invented
            $texts = array();
            if (isset($row->comment))          $texts[] = $row->comment;
            if ($has_items && isset($row->general_comments)) $texts[] = $row->general_comments;
            foreach ($texts as $txt) {
                $txt = trim((string)$txt);
                if ($txt === '') continue;
                if (!isset($out[$id])) $out[$id] = array();
                if (!in_array($txt, $out[$id], true)) $out[$id][] = $txt;
            }
        }
        return $out;
    }

    /**
     * The work order the action plan prints for a machine (D16).
     *
     * Two of them are needed, because the sheet tells two different stories in
     * one row:
     *   * `anchor`  — the work order that started the failure; its text is the
     *                 COMMENT ("what failed"), like the sample's
     *                 "A/C System faillure".
     *   * `current` — the still-open work order when there is one, otherwise the
     *                 most recent; its text is the ACTION PLAN and its
     *                 `hold_code` the STATUS ("what are we doing about it").
     *
     * Returns null when the machine had no breakdown work in the week.
     */
    private function breakdown_work_order($equipment_id, $mode = 'current') {
        $best = null;
        $best_open = false;
        foreach ((array)$this->work_orders as $w) {
            if ((int)$w->equipment_id !== (int)$equipment_id) continue;
            if (function_exists('wo_is_breakdown') && !wo_is_breakdown($w)) continue;

            $open = empty($w->end_datetime)
                 || in_array(strtolower((string)$w->status), array('open', 'in progress', 'waiting parts', 'waiting tools'), true);
            $when = substr((string)$w->start_datetime, 0, 19);

            if ($mode === 'anchor') {
                // the earliest work order of the machine's breakdown history
                if ($best === null || $when < substr((string)$best->start_datetime, 0, 19)) $best = $w;
                continue;
            }

            if ($best === null
                || ($open && !$best_open)
                || ($open === $best_open && $when > substr((string)$best->start_datetime, 0, 19))) {
                $best = $w;
                $best_open = $open;
            }
        }
        return $best;
    }

    /** The first non-empty text of a work order among $fields, or null (OPEN-10). */
    private function wo_text($wo, array $fields) {
        foreach ($fields as $f) {
            if (!isset($wo->$f)) continue;
            $txt = trim((string)$wo->$f);
            if ($txt !== '' && $txt !== '-') return $txt;
        }
        return null;
    }

    /** Why a planned PM service is still undone — derived, never typed (BR-29). */
    private function pm_comment(array $schedule, $machine_down) {
        if (isset($schedule['status']) && strtoupper((string)$schedule['status']) === 'DONE') return null;
        return $machine_down ? 'Equipment currently down' : 'Service interval not reached';
    }

    /** A float from an array, or null when the key is missing. */
    private function num(array $a, $key) {
        return isset($a[$key]) && $a[$key] !== null ? (float)$a[$key] : null;
    }

    /** Is this machine down right now, by the machine's own status? (BR-18) */
    private function machine_down_now(array $r) {
        if (!function_exists('equip_is_down')) return false;
        return equip_is_down(isset($r['status']) ? $r['status'] : null);
    }

    /** Did this machine still have an open breakdown running at the week's end? */
    private function machine_still_down(array $r) {
        foreach ((array)$r['events'] as $e) {
            if (!empty($e['open'])) return true;
        }
        return false;
    }

    /* ────────────────────────────────────────────────────────────────────
     |  W4.3 — the charts of the preview (decision D6: the workbook carries
     |  none). One uniform shape, so the view has one renderer: a list of bars,
     |  each with a value and (where meaningful) the target it is measured
     |  against. Every number here comes from the arrays above.
     * ──────────────────────────────────────────────────────────────────── */

    private function charts(array $group_blocks, array $rows, array $fleet, array $per_type, array $pm, array $inspection) {
        $types = array();
        foreach ($group_blocks as $g) {
            if ((int)$g['row_count'] === 0) continue;
            $pt = isset($per_type[$g['code']]) ? $per_type[$g['code']] : array();
            $types[] = array(
                'label'     => $g['label'],
                'short'     => $g['code'],
                'machines'  => (int)$g['row_count'],
                'available' => isset($pt['available']) ? (int)$pt['available'] : 0,
                'down'      => isset($pt['down']) ? (int)$pt['down'] : 0,
                'availability' => $g['rollup']['availability'],
                'target'    => $g['rollup']['weekly_target'],
                'downtime'  => $g['rollup']['downtime_hours'],
                'breakdowns'=> $g['rollup']['breakdowns'],
                'mttr'      => $g['rollup']['mttr_hours'],
                'mtbf'      => $g['rollup']['mtbf_hours'],
            );
        }

        // the machines that lost the most hours, worst first
        $worst = array();
        foreach ($rows as $r) {
            if ((float)$r['weekly']['hours_down'] <= 0) continue;
            $worst[] = array('label' => $r['machine_id'], 'value' => $r['weekly']['hours_down'],
                             'note' => $r['weekly']['days_down'] . ' d down');
        }
        usort($worst, function ($a, $b) { return $b['value'] < $a['value'] ? -1 : ($b['value'] > $a['value'] ? 1 : 0); });
        $worst = array_slice($worst, 0, 10);

        $pm_bars = array();
        foreach ($pm['by_interval'] as $b) {
            $pm_bars[] = array('label' => $b['label'], 'value' => $b['done'], 'second' => $b['undone'],
                               'note' => $b['done'] . ' done · ' . $b['undone'] . ' undone');
        }

        return array(
            array(
                'key' => 'fleet_availability', 'kind' => 'meter', 'unit' => '%',
                'title' => 'OPERATIONAL FLEET AVAIBILITY',
                'value' => $fleet['rollup']['availability'], 'target' => $fleet['rollup']['weekly_target'],
                'note'  => $fleet['rollup']['breakdowns'] . ' breakdowns · ' . round((float)$fleet['rollup']['downtime_hours'], 2) . ' h down',
            ),
            array(
                'key' => 'availability_by_type', 'kind' => 'bars', 'unit' => '%',
                'title' => 'AVAIBILITY OPERATIONAL BY TYPE',
                'bars' => array_map(function ($t) {
                    return array('label' => $t['label'], 'value' => $t['availability'], 'target' => $t['target'],
                                 'note' => $t['machines'] . ' machines');
                }, $types),
            ),
            array(
                'key' => 'fleet_state_by_type', 'kind' => 'stack', 'unit' => 'machines',
                'title' => 'AVAILABLE / DOWN MACHINES BY TYPE',
                'bars' => array_map(function ($t) {
                    return array('label' => $t['label'], 'value' => $t['available'], 'second' => $t['down'],
                                 'note' => $t['available'] . ' available · ' . $t['down'] . ' down');
                }, $types),
            ),
            array(
                'key' => 'downtime_by_type', 'kind' => 'bars', 'unit' => 'h',
                'title' => 'TOTAL DOWN TIME BY TYPE',
                'bars' => array_map(function ($t) {
                    return array('label' => $t['label'], 'value' => $t['downtime']);
                }, $types),
            ),
            array(
                'key' => 'breakdowns_by_type', 'kind' => 'bars', 'unit' => 'breakdowns',
                'title' => 'NUMBER OF BREAKDOWNS BY TYPE',
                'bars' => array_map(function ($t) {
                    return array('label' => $t['label'], 'value' => $t['breakdowns']);
                }, $types),
            ),
            array(
                'key' => 'mttr_by_type', 'kind' => 'bars', 'unit' => 'h',
                'title' => 'MTTR BY TYPE',
                'bars' => array_map(function ($t) {
                    return array('label' => $t['label'], 'value' => $t['mttr']);
                }, $types),
            ),
            array(
                'key' => 'mtbf_by_type', 'kind' => 'bars', 'unit' => 'h',
                'title' => 'MTBF BY TYPE',
                'bars' => array_map(function ($t) {
                    return array('label' => $t['label'], 'value' => $t['mtbf']);
                }, $types),
            ),
            array(
                'key' => 'worst_machines', 'kind' => 'bars', 'unit' => 'h',
                'title' => 'HOURS DOWN BY MACHINE (WORST FIRST)',
                'bars' => $worst,
            ),
            array(
                'key' => 'pm_services', 'kind' => 'pair', 'unit' => 'services',
                'title' => 'WEEKLY PLANNED PM SERVICES',
                'bars' => $pm_bars,
                'ratio' => $pm['ratio'], 'ratio_label' => 'done',
                'note' => $pm['planned'] . ' planned · ' . $pm['done'] . ' done · ' . $pm['undone'] . ' undone'
                          . (empty($pm['not_judged']) ? '' : ' · ' . count($pm['not_judged']) . ' not judged (no meter reading)'),
            ),
            array(
                'key' => 'inspections', 'kind' => 'pair', 'unit' => 'inspections',
                'title' => 'WEEKLY INSPECTION KPI',
                'bars' => array(
                    array('label' => 'DONE', 'value' => $inspection['done'], 'note' => 'inspection in the week'),
                    array('label' => 'UNDONE', 'value' => $inspection['undone'], 'note' => 'no inspection found'),
                    array('label' => 'BREAKDOWN', 'value' => $inspection['breakdown'], 'note' => 'machine down'),
                ),
                'ratio' => $inspection['ratios']['done'], 'ratio_label' => 'done',
                'note' => $inspection['planned'] . ' reportable machines',
            ),
        );
    }

    /**
     * OPEN-5 — the `NUMBER OF DAYS DOWN` column, as signed off and implemented.
     *
     * The workshop approved three recommendations (D28): the D4 rule
     * unchanged, the column as the
     * **first column of the weekly block on sheet `W36`** (`BG`; the rest of the
     * block shifts to `BH:BP`, the per-type block `BQ:CB` unchanged), and the
     * group/fleet rows carrying **Σ days down beside machines with downtime**,
     * never merged.  The writer prints it (`W36_excel`, `weekly_metrics`); this
     * method remains the standalone view of the same values — machine by machine,
     * group by group, fleet — derived from the same arrays the report prints, with
     * both readings of OPEN-6 side by side.
     *
     * @param  array $report a W36_report_model::build() result
     * @return array
     */
    public function days_down_column(array $report) {
        $machines = array();
        foreach ((array)$report['machine_order'] as $id) {
            $m = $report['machines'][$id];
            $machines[] = array(
                'equipment_id' => (int)$id,
                'machine_id'   => $m['machine_id'],
                'group_code'   => $m['group_code'],
                'days_down'    => (int)$m['weekly']['days_down'],
                'hours_down'   => $this->num($m['weekly'], 'hours_down'),
                'breakdowns'   => (int)$m['weekly']['breakdowns'],
            );
        }

        $groups = array();
        foreach ((array)$report['groups'] as $g) {
            $groups[] = array(
                'code'          => $g['code'],
                'label'         => $g['label'],
                'machines'      => (int)$g['row_count'],
                'days_down'     => (int)$g['rollup']['days_down'],
                'machines_down' => isset($g['rollup']['machines_down']) ? (int)$g['rollup']['machines_down'] : null,
            );
        }

        return array(
            'label'  => 'NUMBER OF DAYS DOWN',
            'source' => 'W36_report_model::days_down_column() — the same weekly roll-ups the sheet prints',
            // where the column would land, and what that does to the approved frame
            'layout_effect' => array(
                'machine_grid'        => 'BG (the first column of the weekly block; BH:BP hold what BG:BO held)',
                'weekly_block'        => 'BG:BP',
                'per_type_block'      => 'BQ:CB unchanged — `Fleet Availible Nb` already carries the machines-down count',
                'current_carrier'     => 'sheet `W36 Synthese`, action plan column N',
                'decision'            => 'OPEN-5 approved as recommended (D28): the column is printed on sheet `W36`, and the group/fleet rows carry Σ days down beside machines with downtime',
            ),
            'machines' => $machines,
            'groups'   => $groups,
            'fleet'    => array(
                'machines'      => count($machines),
                'days_down'     => (int)$report['fleet']['rollup']['days_down'],
                'machines_down' => isset($report['fleet']['rollup']['machines_down'])
                                   ? (int)$report['fleet']['rollup']['machines_down'] : null,
            ),
            'alternatives' => array(
                'sum_of_days_down'          => 'the approved action-plan reading: ADT 010 (5) + ADT 018 (13) = 18',
                'machines_with_downtime'    => 'the same two machines read as 2 — never the same number, never merged',
            ),
        );
    }

    private function per_type(array $group_blocks, array $rows) {
        $out = array();
        foreach ($group_blocks as $g) {
            // D/E of the grid and BQ/BR of the block: how many machines were
            // never down in the week, and how many were down at least once.
            $available = 0; $down = 0;
            foreach ($g['machines'] as $id) {
                if (!isset($rows[$id])) continue;
                if ((float)$rows[$id]['weekly']['hours_down'] > 0) $down++;
                else $available++;
            }

            $out[$g['code']] = array(
                'label'     => $g['label'],
                'machines'  => $g['machines'],
                'row_count' => $g['row_count'],
                'available' => $available,
                'down'      => $down,
                // the two readings of OPEN-6, side by side and never merged:
                // the sum of the days the members were down, and how many
                // members were down at all
                'days_down'     => isset($g['rollup']['days_down']) ? (int)$g['rollup']['days_down'] : 0,
                'machines_down' => $down,
                'rollup'    => $g['rollup'],
                'daily'     => $g['daily'],
            );
        }
        return $out;
    }

    /* ────────────────────────────────────────────────────────────────────
     |  report names — dashboard controlled, week-aware
     * ──────────────────────────────────────────────────────────────────── */

    /** The optional per-week names saved from the weekly report dashboard. */
    const NAME_TABLE = 'weekly_report_names';

    /**
     * The automatic name is deliberately derived from the selected ISO week.
     * W36 therefore means "week 36" only; it is not a business constant.
     *
     * @param string $week_start a date in the selected reporting week
     * @param mixed  $override   optional dashboard value
     * @return string             a safe name for an Excel sheet and filename
     */
    public function report_name($week_start = null, $override = null) {
        $start = $this->settings
            ? $this->settings->week_start($week_start)
            : date('Y-m-d', strtotime($week_start ?: 'today'));
        if ($start === null) $start = date('Y-m-d');

        $fallback = $this->default_report_name($start);
        if ($override !== null && trim((string)$override) !== '') {
            return $this->sanitize_report_name($override, $fallback);
        }

        if ($this->db && $this->db->table_exists(self::NAME_TABLE)) {
            $row = $this->db->select('report_name')->from(self::NAME_TABLE)
                ->where('week_start', $start)->get()->row();
            if ($row && isset($row->report_name) && trim((string)$row->report_name) !== '') {
                return $this->sanitize_report_name($row->report_name, $fallback);
            }
        }
        return $fallback;
    }

    /** Save or replace the selected week's name from the dashboard. */
    public function save_report_name($week_start, $name, $user_id = null) {
        if (!$this->db || !$this->db->table_exists(self::NAME_TABLE)) return false;
        $start = $this->settings
            ? $this->settings->week_start($week_start)
            : date('Y-m-d', strtotime($week_start ?: 'today'));
        if ($start === null) return false;

        $safe = $this->sanitize_report_name($name, '');
        if ($safe === '') return false;

        $row = $this->db->select('id')->from(self::NAME_TABLE)
            ->where('week_start', $start)->get()->row();
        $data = array('report_name' => $safe, 'updated_by' => $user_id);
        if ($row) {
            return (bool)$this->db->where('id', (int)$row->id)->update(self::NAME_TABLE, $data);
        }
        $data['week_start'] = $start;
        return (bool)$this->db->insert(self::NAME_TABLE, $data);
    }

    /** A week-aware fallback used when the new name table is not installed yet. */
    private function default_report_name($week_start) {
        $ts = strtotime((string)$week_start);
        return $ts === false ? 'Weekly Report' : 'W' . (int)date('W', $ts);
    }

    /**
     * Excel sheet names may not contain these characters.  Keep the stored name
     * short enough that the companion "<name> Synthese" sheet also fits.
     */
    public function sanitize_report_name($name, $fallback = 'Weekly Report') {
        $name = trim((string)$name);
        $name = str_replace(array('[', ']', ':', '*', '?', '/'), ' ', $name);
        $name = str_replace(chr(92), ' ', $name);
        $name = preg_replace('/[[:space:]]+/u', ' ', $name);
        $name = trim((string)$name);
        if ($name === '') $name = (string)$fallback;
        if (function_exists('mb_substr')) return mb_substr($name, 0, 22, 'UTF-8');
        return substr($name, 0, 22);
    }

    /* ────────────────────────────────────────────────────────────────────
     |  published snapshots — the freeze  (gap G6 / plan task 1.4)
     |
     |  D4 splits the two readings of a week:
     |    live      — days down keep counting to *today* (a machine still in
     |                the workshop is 44 days down, not 13);
     |    published — the week is frozen. `publish()` stores the whole report
     |                array verbatim in `weekly_report_snapshots`, so re-opening
     |                or re-downloading the week returns the values as they were
     |                published, however much the work orders, the targets or
     |                the statuses change afterwards.
     |
     |  Only the *week's own* figures are frozen: the week is always the ISO
     |  Monday–Sunday the generator resolved, and `checksum` proves a payload
     |  never changed under the same week.
     * ──────────────────────────────────────────────────────────────────── */

    /** The table the published weeks live in. */
    const SNAPSHOT_TABLE = 'weekly_report_snapshots';

    /** Is the freeze available on this database? (a fresh install may not have it yet) */
    public function snapshots_available() {
        return $this->db && $this->db->table_exists(self::SNAPSHOT_TABLE);
    }

    /** The week's row, or null. */
    private function snapshot_row($week_start) {
        if (!$this->snapshots_available()) return null;
        $rows = $this->db->select('*')->from(self::SNAPSHOT_TABLE)
                         ->where('week_start', $week_start)
                         ->get()->result();
        return empty($rows) ? null : $rows[0];
    }

    /**
     * The published snapshot of a week — the frozen report plus its provenance.
     *
     * @return array|null null when the week was never published (or the payload
     *                    cannot be read — a corrupt row must never look like a report)
     */
    public function snapshot_of($week_start) {
        $start = $this->settings ? $this->settings->week_start($week_start) : date('Y-m-d', strtotime($week_start));
        if ($start === null) return null;

        $row = $this->snapshot_row($start);
        if ($row === null) return null;

        $payload = isset($row->payload) ? (string)$row->payload : '';
        $report  = json_decode($payload, true);
        if (!is_array($report) || empty($report['week'])) return null;
        // Older frozen payloads pre-date dashboard-controlled names.  Derive
        // their label from the frozen week, never from today's override.
        if (!isset($report['report_name']) || trim((string)$report['report_name']) === '') {
            $frozen_start = isset($report['week']['start']) ? $report['week']['start'] : $start;
            $report['report_name'] = $this->default_report_name($frozen_start);
        }

        return array_merge(
            array('report' => $report),
            $this->snapshot_meta($row, $payload, $start)
        );
    }

    /**
     * What the screen needs to know about a frozen week — the provenance, the
     * checksum and whether the stored payload still verifies. No payload: a row
     * reads as published even when its figures cannot be served.
     */
    private function snapshot_meta($row, $payload, $start) {
        return array(
            'id'         => isset($row->id) ? (int)$row->id : null,
            'week_start' => $start,
            'week_end'   => isset($row->week_end) ? $row->week_end : null,
            'iso_label'  => isset($row->iso_label) ? $row->iso_label : null,
            'checksum'   => isset($row->payload_checksum) ? $row->payload_checksum : null,
            'build_ms'   => isset($row->build_ms) ? (int)$row->build_ms : null,
            'published_by' => isset($row->published_by) ? $row->published_by : null,
            'published_at' => isset($row->published_at) ? $row->published_at : null,
            'complete'   => $this->payload_matches($payload, isset($row->payload_checksum) ? $row->payload_checksum : ''),
        );
    }

    /** Was this week published? */
    public function is_published($week_start) {
        return $this->snapshot_of($week_start) !== null;
    }

    /**
     * A light, read-only publication census for a range of week starts.
     *
     * Unlike is_published(), this reads the *snapshot row*, not the decoded
     * payload: a week whose stored payload is unreadable is still published —
     * its problem is data quality, and telling the user to publish it again
     * would be wrong.  The /reports hub uses it to find completed weeks that
     * are still due for publication without decoding four full reports.
     *
     * @param  string $from 'Y-m-d', inclusive
     * @param  string $to   'Y-m-d', inclusive
     * @return array<string,true>|null  map of published week starts; null when
     *         the snapshot table is not installed (so a caller can tell
     *         "cannot check" apart from "nothing published")
     */
    public function published_week_starts($from, $to) {
        if (!$this->snapshots_available()) return null;

        $rows = $this->db->select('week_start')
                         ->from(self::SNAPSHOT_TABLE)
                         ->where('week_start >=', $from)
                         ->where('week_start <=', $to)
                         ->get()->result();

        $out = array();
        foreach ($rows as $row) {
            $start = isset($row->week_start) ? substr((string)$row->week_start, 0, 10) : '';
            if ($start !== '') $out[$start] = true;
        }
        return $out;
    }

    /**
     * Publish (freeze) a week.
     *
     * The report is built with its **own cut-off** — the last day of the week —
     * and stored verbatim: that is the figure the business signs off, and it is
     * what D4 means by "the live screen keeps counting, the snapshot freezes".
     * Re-publishing an already published week is refused unless `$force` is set:
     * the freeze is the audit trail, not a cache.
     *
     * @param  string   $week_start any date inside the week
     * @param  int|null $user_id    who is publishing
     * @param  bool     $force      replace an existing snapshot
     * @return array    array('published' => bool, 'reason' => string|null, 'snapshot' => array|null)
     */
    public function publish($week_start = null, $user_id = null, $force = false) {
        $start = $this->settings ? $this->settings->week_start($week_start) : date('Y-m-d', strtotime($week_start ?: 'today'));
        if ($start === null) return array('published' => false, 'reason' => 'not_a_week', 'snapshot' => null);
        if (!$this->snapshots_available()) return array('published' => false, 'reason' => 'no_table', 'snapshot' => null);

        $existing = $this->snapshot_of($start);
        if ($existing !== null && !$force) {
            return array('published' => false, 'reason' => 'already_published', 'snapshot' => $existing);
        }

        $t0     = microtime(true);
        $report = $this->build($start);
        $build_ms = (int)round((microtime(true) - $t0) * 1000);

        $payload  = json_encode($report);
        $checksum = hash('sha256', $payload);

        $row = array(
            'week_start'       => $report['week']['start'],
            'week_end'         => $report['week']['end'],
            'iso_label'        => isset($report['week']['iso_label']) ? $report['week']['iso_label'] : $start,
            'payload'          => $payload,
            'payload_checksum' => $checksum,
            'build_ms'         => $build_ms,
            'published_by'     => $user_id,
            'published_at'     => date('Y-m-d H:i:s'),
        );

        if ($existing !== null) {
            $this->db->where('id', $existing['id'])->update(self::SNAPSHOT_TABLE, $row);
        } else {
            $this->db->insert(self::SNAPSHOT_TABLE, $row);
        }

        // read it back: the freeze is only real once it can be read (a failed
        // write must say so instead of reporting success)
        $snap = $this->snapshot_of($start);
        return array(
            'published' => $snap !== null,
            'reason'    => $snap === null ? 'write_failed' : null,
            'snapshot'  => $snap,
        );
    }

    /**
     * The report of a week, for reading or for the download.
     *
     * A published week is served **frozen** — that is what makes the re-download
     * reproducible (AC7). `$opts['live'] = true` asks for a fresh build instead,
     * measured to today (D4: the live screen keeps counting).
     *
     * @param  string $week_start any date inside the week
     * @param  array  $opts       live (bool), snapshot (Y-m-d, the live cut-off)
     * @return array
     */
    public function report_for_week($week_start = null, array $opts = array()) {
        $start = $this->settings ? $this->settings->week_start($week_start) : date('Y-m-d', strtotime($week_start ?: 'today'));

        // an explicit cut-off is a *live* question ("what does it look like as of
        // today?"), so it never reads the freeze — only the frozen answer does
        $ask_live = !empty($opts['live']) || isset($opts['snapshot']);
        $report_published = null;

        if (!$ask_live) {
            $snap = $this->snapshot_of($start);
            if ($snap !== null) {
                $report = $snap['report'];
                $meta   = $snap;
                unset($meta['report']);
                $meta['frozen'] = true;
                $report['snapshot']  = $meta;
                $report['published'] = $meta;
                return $report;
            }

            // the week IS published, but this call asked for a live reading: the
            // screen still needs to know, so it can offer the way back
            $row = $this->snapshot_row($start);
            if ($row !== null) {
                $payload = isset($row->payload) ? (string)$row->payload : '';
                $report_published = $this->snapshot_meta($row, $payload, $start);
            }
        }

        $build_opts = array();
        if (array_key_exists('report_name', $opts)) $build_opts['report_name'] = $opts['report_name'];
        if ($ask_live && !isset($opts['snapshot'])) {
            // the live cut-off: today, never beyond the week's own figures
            $build_opts['snapshot'] = date('Y-m-d');
        } elseif (isset($opts['snapshot'])) {
            $build_opts['snapshot'] = $opts['snapshot'];
        }

        $report = $this->build($start, $build_opts);
        $report['snapshot']  = null;
        $report['published'] = isset($report_published) ? $report_published : null;
        return $report;
    }

    /** Does a payload still hash to the checksum stored with it? */
    private function payload_matches($payload, $checksum) {
        if ($checksum === '' || $checksum === null) return false;
        return hash('sha256', (string)$payload) === (string)$checksum;
    }
}
