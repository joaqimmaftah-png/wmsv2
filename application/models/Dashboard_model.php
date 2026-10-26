<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Dashboard_model extends CI_Model {

    /**
     * The one availability / MTTR / MTBF engine (audit finding: three
     * divergent definitions).  Everything this model used to compute itself on
     * a 24/7 wall clock now comes from Kpi_engine_model, which calls the same
     * kpi_helper the weekly report is built on — so the dashboard, the
     * equipment page, the MTTR/MTBF report and the W36 all print the same
     * number, on the configured open hours (10 h × 6 days = 60 h/week here).
     */
    private function _engine() {
        $ci = get_instance();
        if (!isset($ci->Kpi_engine_model)) {
            $ci->load->model('Kpi_engine_model');
        }
        return $ci->Kpi_engine_model;
    }

    /**
     * The 30-day fleet window, computed once per request.
     *
     * One dashboard load used availability + MTTR + MTBF, and each of those
     * asked the engine for the same whole-fleet window — three full passes over
     * every machine's work orders. It is also the number the failure and
     * downtime cards must use, so caching it keeps the cards consistent as
     * well as making the page cheaper.
     */
    private $_fleet_window_cache = null;

    private function _fleet_window() {
        if ($this->_fleet_window_cache === null) {
            // Resolve the model first: the constant lives in its file, and the
            // argument must not be evaluated before that file is loaded.
            $engine = $this->_engine();
            $this->_fleet_window_cache = $engine->fleet_window(Kpi_engine_model::DASHBOARD_WINDOW_DAYS);
        }
        return $this->_fleet_window_cache;
    }

    public function count_open_wo() {
        // Waiting Tools is a waiting state too; omitting it under-reported
        // the open-work-order card next to a list that did show those jobs.
        return $this->db->where_in('status', ['Open','In Progress','Waiting Parts','Waiting Tools'])->count_all_results('work_orders');
    }

    public function get_availability_pct() {
        $data = $this->_fleet_window();
        $pct  = $data['fleet']['availability_pct'];
        // null means the window had no required time at all (no machines, or
        // every hour accounted for by planned work) — the card shows 100.
        return $pct === null ? 100.0 : $pct;
    }

    /**
     * Availability plus its provenance: the window it was computed over and how
     * many machines actually contributed data. A partial dataset (e.g. 14 of 16
     * machines reporting) is the one thing that can make an availability number
     * misleading, so the dashboard surfaces the count instead of hiding it —
     * trust comes from knowing what a figure is built on.
     */
    public function get_availability_window() {
        $data = $this->_fleet_window();
        $pct  = $data['fleet']['availability_pct'];
        return [
            'pct'            => $pct === null ? 100.0 : $pct,
            'machines_with'  => isset($data['fleet']['machines_with_data']) ? (int)$data['fleet']['machines_with_data'] : 0,
            'from'           => isset($data['period']['from']) ? $data['period']['from'] : null,
            'to'             => isset($data['period']['to']) ? $data['period']['to'] : null,
        ];
    }

    /**
     * The availability target in percent, for the screens that label a card or
     * draw a target line. Q1: one target for the whole system — the engine's
     * (equipment_kpi_targets → settings.default_availability_target → 88 %),
     * never a number typed into a view.
     */
    public function availability_target_pct() {
        return round($this->_engine()->target_for(null, date('Y-m-d')) * 100, 1);
    }

    public function get_manpower_today() {
        $today   = date('Y-m-d');
        $total   = $this->db->where('status', 'Active')->count_all_results('users');
        $present = $this->db->where('date', $today)->where('status', 'Present')->count_all_results('attendance');
        return ['present' => $present, 'total' => $total, 'pct' => $total ? round($present / $total * 100, 0) : 0];
    }

    public function count_overdue_pm() {
        // FIX: pm_schedules uses status='Active', not is_active=1
        return $this->db->where('next_due_date <', date('Y-m-d'))->where('status', 'Active')->count_all_results('pm_schedules');
    }

    public function get_mttr_30d() {
        $fleet = $this->_fleet_window();
        $mttr  = $fleet['fleet']['mttr_hours'];
        return $mttr === null ? 0.0 : round($mttr, 2);
    }

    public function get_mtbf_30d() {
        $fleet = $this->_fleet_window();
        $mtbf  = $fleet['fleet']['mtbf_hours'];
        // No failure in the window: the business rule (settings.
        // mtbf_zero_failure_mode) decides — 'dash' shows nothing, and the card
        // prints 0 for that.
        return $mtbf === null ? 0.0 : round($mtbf, 1);
    }

    // FIX: was get_lube_consumption_month — renamed to match Dashboard controller call
    public function get_lube_month_total() {
        $r = $this->db->select_sum('quantity_used', 'total')
            ->where('consumption_date >=', date('Y-m-01'))
            ->where('consumption_date <=', date('Y-m-t'))
            ->get('lube_consumption')->row();
        return $r ? (float)($r->total ?: 0) : 0;
    }

    // alias kept for backward compat
    public function get_lube_consumption_month() {
        return $this->get_lube_month_total();
    }

    /**
     * Failures in the same 30-day window the availability/MTTR/MTBF cards use.
     *
     * This used to be a raw COUNT of work orders, so a failure split over three
     * repair jobs read as three breakdowns here while the W36 and the MTTR card
     * counted it once (they group by `breakdown_event_id`). It now comes from
     * the KPI engine, i.e. the number the weekly report publishes.
     */
    public function count_breakdowns_30d() {
        $fleet = $this->_fleet_window();
        return isset($fleet['fleet']['breakdowns']) ? (int)$fleet['fleet']['breakdowns'] : 0;
    }

    /**
     * Downtime hours in the window — the union of breakdown intervals, clipped
     * to the configured open hours, exactly as W36 reports it. The old sum of
     * `work_orders.downtime_hours` was neither clipped nor non-overlapping, and
     * was NULL for every still-open work order (so a machine down right now
     * contributed nothing).
     */
    public function get_total_downtime_30d() {
        $fleet = $this->_fleet_window();
        return isset($fleet['fleet']['downtime_hours']) ? round((float)$fleet['fleet']['downtime_hours'], 1) : 0.0;
    }

    // FIX: was using is_active=1, table uses status='Active'
    public function count_overdue_pm_count() {
        return $this->db->where('next_due_date <', date('Y-m-d'))
            ->where('status', 'Active')
            ->count_all_results('pm_schedules');
    }

    public function get_availability_trend($days = 30) {
        // Same engine, per day: one pass over the window, not one query per day.
        $series = $this->_engine()->daily_fleet($days);
        $result = [];
        foreach ($series as $point) {
            $result[] = ['date' => $point['date'], 'pct' => $point['pct'] === null ? 100 : $point['pct']];
        }
        return $result;
    }

    public function get_attendance_trend($days = 14) {
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date    = date('Y-m-d', strtotime("-{$i} days"));
            $present = $this->db->where('date', $date)->where('status', 'Present')->count_all_results('attendance');
            $absent  = $this->db->where('date', $date)->where('status', 'Absent')->count_all_results('attendance');
            $result[] = ['date' => $date, 'present' => $present, 'absent' => $absent];
        }
        return $result;
    }

    public function get_wo_monthly_12() {
        $result = array();
        for ($i = 11; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-{$i} months"));
            $from  = $month . '-01';
            $to    = date('Y-m-t', strtotime($from)) . ' 23:59:59';
            // Use start_datetime (set when WO is created/dispatched) — populated by seed data
            $open   = $this->db->where('start_datetime >=', $from)->where('start_datetime <=', $to)->count_all_results('work_orders');
            $closed = $this->db->where('status', 'Closed')->where('end_datetime >=', $from)->where('end_datetime <=', $to)->count_all_results('work_orders');
            $result[] = array('month' => $month, 'open' => $open, 'closed' => $closed);
        }
        return $result;
    }

    /**
     * Work orders per reporting bucket (W0.1).
     *
     * 'Breakdown' comes from the is_breakdown flag, 'Preventive' and 'Incident'
     * from the real enum values. The historical 'Modification'/'Inspection'
     * buckets never existed in the enum, so they always read 0 in the UI;
     * unknown values now land in 'Other' and are still counted.
     */
    public function get_wo_ratio() {
        $rows = $this->db->select('type, is_breakdown, COUNT(*) AS cnt')
            ->group_by(['type', 'is_breakdown'])
            ->get('work_orders')->result();

        $out = wo_buckets();
        foreach ($rows as $r) {
            $out[wo_bucket($r)] += (int)$r->cnt;
        }
        return $out;
    }

    public function get_lube_by_equipment_type() {
        return $this->db
            ->select('et.name as type_name, SUM(lc.quantity_used) as total_qty')
            ->from('lube_consumption lc')
            ->join('equipment e', 'e.id = lc.equipment_id')
            ->join('equipment_types et', 'et.id = e.equipment_type_id')
            ->where('lc.consumption_date >=', date('Y-m-01'))
            ->group_by('et.id')
            ->get()->result();
    }

    public function get_due_pm_list() {
        return $this->db
            ->select('ps.*, e.registration_no, e.brand, e.model')
            ->from('pm_schedules ps')
            ->join('equipment e', 'e.id = ps.equipment_id')
            ->where('ps.next_due_date <=', date('Y-m-d', strtotime('+7 days')))
            ->where('ps.status', 'Active')
            ->order_by('ps.next_due_date', 'ASC')
            ->get()->result();
    }

    public function get_absent_today() {
        return $this->db
            ->select('u.first_name, u.last_name, u.position, a.status')
            ->from('attendance a')
            ->join('users u', 'u.id = a.user_id')
            ->where('a.date', date('Y-m-d'))
            ->where('a.status', 'Absent')
            ->get()->result();
    }

    public function get_on_leave_week() {
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_end   = date('Y-m-d', strtotime('sunday this week'));
        return $this->db
            ->select('u.first_name, u.last_name, lr.leave_type, lr.start_date, lr.end_date')
            ->from('leave_requests lr')
            ->join('users u', 'u.id = lr.user_id')
            ->where('lr.status', 'Approved')
            ->where('lr.end_date >=', $week_start)
            ->where('lr.start_date <=', $week_end)
            ->get()->result();
    }

    public function get_weekly_kpi($from, $to) {
        return $this->_get_kpi_for_range($from, $to);
    }

    public function get_monthly_kpi($from, $to) {
        return $this->_get_kpi_for_range($from, $to);
    }

    private function _get_kpi_for_range($from, $to) {
        $data  = $this->_engine()->fleet($from, $to);
        $fleet = $data['fleet'];
        return [
            'availability'    => $fleet['availability_pct'] === null ? 100 : $fleet['availability_pct'],
            'mttr'            => $fleet['mttr_hours'] === null ? 0 : round($fleet['mttr_hours'], 2),
            'mtbf'            => $fleet['mtbf_hours'] === null ? 0 : round($fleet['mtbf_hours'], 1),
            'breakdowns'      => (int)$fleet['breakdowns'],
            'total_downtime'  => round((float)$fleet['downtime_hours'], 2),
            // extra context the engine can supply and the views may use
            'required_hours'  => round((float)$fleet['required_time_hours'], 2),
            'open_hours'      => $data['period']['open_hours'],
            'target'          => $this->availability_target_pct(),
        ];
    }

    /**
     * Per equipment-type KPI for the dashboard fleet strip
     */
    public function get_fleet_kpi_by_type() {
        $equipment = $this->db
            ->select('e.id, e.status AS equip_status, et.name AS equip_type')
            ->from('equipment e')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->where('e.status !=', 'Retired')
            ->get()->result();

        $open_wos = $this->db
            ->select('equipment_id')
            ->where_in('status', ['Open','In Progress','Waiting Parts','Waiting Tools'])
            ->get('work_orders')->result();
        $down_ids = array_column($open_wos, 'equipment_id');

        $groups = [];
        foreach ($equipment as $eq) {
            $type = strtoupper((string)($eq->equip_type ?: 'OTHER'));
            if (!isset($groups[$type])) {
                $groups[$type] = ['type'=>$type,'total'=>0,'available'=>0,'down'=>0];
            }
            // W0.2: one status definition (application/helpers/equip_helper.php)
            $isDown = in_array($eq->id, $down_ids) || equip_is_down($eq);
            $groups[$type]['total']++;
            if ($isDown) $groups[$type]['down']++;
            else         $groups[$type]['available']++;
        }
        foreach ($groups as &$g) {
            $g['kpi_pct'] = $g['total'] > 0 ? round($g['available'] / $g['total'] * 100) : 0;
        }
        unset($g);
        return array_values($groups);
    }

    public function get_mttr_mtbf_trend($months = 12) {
        // Monthly, from the engine: same definition as every other screen, and
        // the availability column is now filled instead of implied.
        $result = [];
        foreach ($this->_engine()->monthly_fleet($months) as $m) {
            $result[] = [
                'month'        => $m['month'],
                'mttr'         => $m['mttr'] === null ? 0 : $m['mttr'],
                'mtbf'         => $m['mtbf'] === null ? 0 : $m['mtbf'],
                'availability' => $m['availability'] === null ? 100.0 : $m['availability'],
                'breakdowns'   => $m['breakdowns'],
            ];
        }
        return $result;
    }

    /* ─── MTTR / MTBF DETAILED REPORT ───────────────────── */

    public function get_mttr_mtbf_report($months = 12) {
        $trend  = $this->get_mttr_mtbf_trend($months);
        $eq_cnt = $this->db->where('status !=', 'Retired')->count_all_results('equipment');

        // Per-equipment breakdown
        $equipment = $this->db
            ->select('e.id, e.registration_no, et.name AS equip_type, e.brand, e.model, e.current_hours, e.location')
            ->from('equipment e')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->where('e.status !=', 'Retired')
            ->order_by('et.name, e.registration_no')
            ->get()->result();

        $from_date = date('Y-m-01', strtotime("-{$months} months"));
        $to_date   = date('Y-m-d');
        $engine    = $this->_engine();
        $window    = $engine->fleet($from_date, $to_date);   // one pass, same definition as the weekly report

        $per_eq = [];
        foreach ($equipment as $eq) {
            $k = isset($window['machines'][(int)$eq->id]) ? $window['machines'][(int)$eq->id] : null;
            if ($k === null) continue;

            // Longest single stop, from the same event set the engine used.
            $max_down = 0.0;
            if (!empty($k['events'])) {
                foreach ($k['events'] as $e) {
                    $hrs = isset($e['hours']) ? (float)$e['hours'] : 0.0;
                    if ($hrs > $max_down) $max_down = $hrs;
                }
            }

            $per_eq[] = [
                'id'          => $eq->id,
                'machine_id'  => $eq->registration_no,
                'type'        => strtoupper((string)($eq->equip_type ?: 'OTHER')),
                'model'       => ($eq->brand ? $eq->brand.' ' : '').$eq->model,
                'location'    => $eq->location,
                'hours'       => (float)$eq->current_hours,          // the meter, not an operating-time basis
                'breakdowns'  => (int)$k['breakdowns'],
                'total_down'  => round((float)$k['downtime_hours'], 1),
                'mttr'        => $k['mttr_hours'] === null ? 0 : round($k['mttr_hours'], 1),
                'mtbf'        => $k['mtbf_hours'] === null ? 0 : round($k['mtbf_hours'], 1),
                'availability'=> $k['availability_pct'] === null ? 100.0 : $k['availability_pct'],
                'max_down'    => round($max_down, 1),
                'target'      => round($engine->target_for($eq->id, $to_date) * 100, 1),
                'variance'    => $engine->variance_pp($k['availability'], $engine->target_for($eq->id, $to_date)),
            ];
        }

        // Summary stats — the fleet figures come from the rollup, not from
        // averaging per-machine percentages (a machine with one hour of
        // required time must not weigh as much as one with 600).
        $fleet    = $window['fleet'];
        $all_bd   = (int)$fleet['breakdowns'];
        $all_down = (float)$fleet['downtime_hours'];

        return [
            'trend'      => $trend,
            'per_eq'     => $per_eq,
            'summary'    => [
                'total_eq'    => $eq_cnt,
                'total_bd'    => $all_bd,
                'total_down'  => round($all_down, 1),
                'avg_mttr'    => $fleet['mttr_hours'] === null ? 0 : round($fleet['mttr_hours'], 1),
                'avg_mtbf'    => $fleet['mtbf_hours'] === null ? 0 : round($fleet['mtbf_hours'], 1),
                'avg_avail'   => $fleet['availability_pct'] === null ? 100.0 : $fleet['availability_pct'],
            ],
            'months'     => $months,
            'window'     => ['from' => $from_date, 'to' => $to_date,
                             'open_hours' => $window['period']['open_hours'],
                             'daily_open_hours' => $window['period']['daily_open_hours']],
            'target'     => round($engine->target_for(null, $to_date) * 100, 1),
        ];
    }

    /* ─── PM DUE FORECAST ───────────────────────────────────
       Named for what it actually computes: the PM schedules that fall due
       inside a horizon, and how urgent each one is.  It does NOT project an
       availability percentage — that is `Forecast_model` (D29).  The screen
       used to be titled "Availability Forecast" and promised an "availability
       prediction" it never made (task F1).
    ──────────────────────────────────────────────────────── */

    public function get_availability_forecast($horizon_days = 90) {
        $today    = date('Y-m-d');
        $horizon  = date('Y-m-d', strtotime("+{$horizon_days} days"));

        $equipment = $this->db
            ->select('e.id, e.registration_no, et.name AS equip_type, e.brand, e.model,
                      e.current_hours, e.location, e.status AS equip_status, e.warranty_expiry')
            ->from('equipment e')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->where('e.status !=', 'Retired')
            ->order_by('et.name, e.registration_no')
            ->get()->result();

        // Upcoming PM schedules within horizon
        $pm_due = $this->db
            ->select('ps.*, e.registration_no, e.current_hours, e.location,
                      et.name AS equip_type')
            ->from('pm_schedules ps')
            ->join('equipment e', 'e.id = ps.equipment_id')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->where('ps.status', 'Active')
            ->where('ps.next_due_date <=', $horizon)
            ->order_by('ps.next_due_date ASC')
            ->get()->result();

        // Build forecast per PM item
        $forecast = [];
        foreach ($pm_due as $pm) {
            $due_date   = $pm->next_due_date;
            $days_until = (int)ceil((strtotime($due_date) - strtotime($today)) / 86400);
            $overdue    = $days_until < 0;
            $urgent     = $days_until >= 0 && $days_until <= 14;

            // Hours until due (if hours-based)
            $hrs_until = null;
            if ($pm->interval_type === 'Hours' && $pm->next_due_hours && $pm->current_hours) {
                $hrs_until = max(0, round((float)$pm->next_due_hours - (float)$pm->current_hours, 0));
            }

            $forecast[] = [
                'machine_id'    => $pm->registration_no,
                'type'          => strtoupper((string)($pm->equip_type ?: 'OTHER')),
                'schedule_name' => $pm->schedule_name,
                'interval_type' => $pm->interval_type,
                'interval_value'=> $pm->interval_value,
                'last_done'     => $pm->last_done_date,
                'next_due_date' => $due_date,
                'next_due_hours'=> $pm->next_due_hours,
                'current_hours' => $pm->current_hours,
                'hrs_remaining' => $hrs_until,
                'days_until'    => $days_until,
                'overdue'       => $overdue,
                'urgent'        => $urgent,
                // Was `$pm->current_hours ? $pm->registration_no : ''` — it put
                // the fleet number in the location field (task F1).
                'location'      => $pm->location,
            ];
        }

        // Fleet status summary per type for the forecast period
        $type_summary = [];
        foreach ($equipment as $eq) {
            $type = strtoupper((string)($eq->equip_type ?: 'OTHER'));
            if (!isset($type_summary[$type])) {
                $type_summary[$type] = ['type'=>$type,'total'=>0,'operational'=>0,'breakdown'=>0,'pm_due'=>0];
            }
            $type_summary[$type]['total']++;
            $isDown = equip_is_down($eq);
            if ($isDown) $type_summary[$type]['breakdown']++;
            else         $type_summary[$type]['operational']++;
        }
        // Tag machines with upcoming PM
        foreach ($forecast as $f) {
            $t = $f['type'];
            if (isset($type_summary[$t])) $type_summary[$t]['pm_due']++;
        }

        return [
            'forecast'      => $forecast,
            'type_summary'  => array_values($type_summary),
            'total_eq'      => count($equipment),
            'pm_due_count'  => count($pm_due),
            'overdue_count' => count(array_filter($forecast, fn($f) => $f['overdue'])),
            'urgent_count'  => count(array_filter($forecast, fn($f) => $f['urgent'] && !$f['overdue'])),
            'horizon_days'  => $horizon_days,
            'horizon_date'  => $horizon,
        ];
    }

    /* ─── MONTHLY MAINTENANCE PLAN ───────────────────────── */

    public function get_monthly_maintenance_plan($month) {
        $from = $month.'-01';
        $to   = date('Y-m-t', strtotime($from));

        // PM schedules due this month
        $pm_items = $this->db
            ->select('ps.*, e.registration_no, e.current_hours, e.location, e.brand, e.model, et.name AS equip_type')
            ->from('pm_schedules ps')
            ->join('equipment e', 'e.id = ps.equipment_id')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->where('ps.status', 'Active')
            ->where('ps.next_due_date >=', $from)
            ->where('ps.next_due_date <=', $to)
            ->order_by('ps.next_due_date ASC')
            ->get()->result();

        // Open / in-progress corrective WOs that will likely extend into this month
        $corrective = $this->db
            ->select('wo.*, e.registration_no, e.location, e.brand, e.model, et.name AS equip_type')
            ->from('work_orders wo')
            ->join('equipment e', 'e.id = wo.equipment_id')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->where_in('wo.status', ['Open','In Progress','Waiting Parts','Waiting Tools'])
            ->order_by('wo.priority DESC, wo.created_at ASC')
            ->get()->result();

        // Group plan by week within the month
        $weeks = [];
        for ($w = 0; $w < 5; $w++) {
            $ws = date('Y-m-d', strtotime($from." +{$w} weeks"));
            $we = date('Y-m-d', strtotime($ws.' +6 days'));
            if ($ws > $to) break;
            $we = min($we, $to);
            $weeks[] = [
                'label'  => 'Week '.($w+1).' ('.date('d M',strtotime($ws)).' – '.date('d M',strtotime($we)).')',
                'from'   => $ws,
                'to'     => $we,
                'pm'     => [],
            ];
        }

        // Assign PM to weeks
        foreach ($pm_items as $pm) {
            $due = $pm->next_due_date;
            foreach ($weeks as &$wk) {
                if ($due >= $wk['from'] && $due <= $wk['to']) {
                    $wk['pm'][] = $pm;
                    break;
                }
            }
            unset($wk);
        }

        // Priority order map
        $prio = ['Critical'=>1,'High'=>2,'Medium'=>3,'Low'=>4];
        usort($corrective, function($a, $b) use ($prio) {
            return ($prio[$a->priority] ?? 5) - ($prio[$b->priority] ?? 5);
        });

        return [
            'month'       => $month,
            'from'        => $from,
            'to'          => $to,
            'weeks'       => $weeks,
            'pm_items'    => $pm_items,
            'corrective'  => $corrective,
            'pm_count'    => count($pm_items),
            'corrective_count' => count($corrective),
        ];
    }

    /* ─── ASSET REGISTER ─────────────────────────────────── */

    /**
     * Asset register — kept for backward compatibility.
     *
     * The implementation now lives in Asset_model (single source of truth for
     * the register, the Excel export and the Excel import).  Everything that
     * needs richer data (engine model, KM, notes, work-order breakdown…)
     * should call $this->Asset_model->get_register() directly.
     */
    public function get_asset_register() {
        $ci = get_instance();
        if (!isset($ci->Asset_model)) {
            $ci->load->model('Asset_model');
        }
        return $ci->Asset_model->get_register();
    }

    /* ─── ANNUAL REPORT ──────────────────────────────────── */

    public function get_annual_report($year) {
        $from = "{$year}-01-01";
        $to   = "{$year}-12-31 23:59:59";

        // ── Monthly KPI trend (12 months)
        // The work-order counts stay direct queries; every availability / MTTR
        // / MTBF figure comes from the engine, so a month's number here equals
        // the same month in the dashboard and in the weekly report.
        $engine  = $this->_engine();
        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $mFrom = sprintf('%04d-%02d-01', $year, $m);
            $mTo   = date('Y-m-t', strtotime($mFrom));   // a future month has no data yet, and the engine says so
            $eq_cnt= $this->db->where('status !=','Retired')->count_all_results('equipment');

            $wo = $this->db->select('COUNT(*) as cnt, SUM(downtime_hours) as dt,
                       COUNT(CASE WHEN is_breakdown = 1 THEN 1 END) as bd,
                       COUNT(CASE WHEN status IN ("Completed","Closed") THEN 1 END) as done')
                ->where('start_datetime >=', $mFrom)
                ->where('start_datetime <=', $mTo.' 23:59:59')
                ->get('work_orders')->row();

            $kpi = $engine->fleet($mFrom, $mTo);

            $lube = $this->db->select_sum('quantity_used','total')
                ->where('consumption_date >=', $mFrom)
                ->where('consumption_date <=', $mTo)
                ->get('lube_consumption')->row();

            $monthly[] = [
                'month'      => sprintf('%04d-%02d', $year, $m),
                'label'      => date('M', strtotime($mFrom)),
                'wo_total'   => (int)($wo->cnt ?? 0),
                'breakdowns' => (int)$kpi['fleet']['breakdowns'],
                'completed'  => (int)($wo->done ?? 0),
                'downtime'   => round((float)$kpi['fleet']['downtime_hours'], 1),
                'availability'=> $kpi['fleet']['availability_pct'] === null ? 100.0 : $kpi['fleet']['availability_pct'],
                'mttr'       => $kpi['fleet']['mttr_hours'] === null ? 0 : round($kpi['fleet']['mttr_hours'], 1),
                'mtbf'       => $kpi['fleet']['mtbf_hours'] === null ? 0 : round($kpi['fleet']['mtbf_hours'], 1),
                'lube_used'  => round((float)($lube->total ?? 0), 1),
            ];
        }

        // ── Annual totals
        $totals_wo = $this->db->select('COUNT(*) as total, SUM(downtime_hours) as total_dt,
                      COUNT(CASE WHEN is_breakdown = 1 THEN 1 END) as bd,
                      COUNT(CASE WHEN type="Preventive Maintenance" THEN 1 END) as pm,
                      COUNT(CASE WHEN status IN ("Completed","Closed") THEN 1 END) as done,
                      COUNT(CASE WHEN priority="Critical" THEN 1 END) as critical')
            ->where('start_datetime >=', $from)
            ->where('start_datetime <=', $to)
            ->get('work_orders')->row();

        // ── Equipment reliability ranking
        $equipment = $this->db
            ->select('e.id, e.registration_no, et.name AS equip_type, e.brand, e.model, e.current_hours, e.location')
            ->from('equipment e')
            ->join('equipment_types et','et.id=e.equipment_type_id','left')
            ->where('e.status !=','Retired')
            ->order_by('et.name, e.registration_no')
            ->get()->result();

        // Reliability ranking, on the engine (same definition as the weekly
        // report) — the year's window, one pass over the work orders.
        $year_window = $engine->fleet(substr($from, 0, 10), substr($to, 0, 10));
        $eq_reliability = [];
        foreach ($equipment as $eq) {
            $k = isset($year_window['machines'][(int)$eq->id]) ? $year_window['machines'][(int)$eq->id] : null;
            if ($k === null) continue;
            $eq_reliability[] = [
                'machine_id'  => $eq->registration_no,
                'type'        => strtoupper((string)($eq->equip_type ?: 'OTHER')),
                'model'       => ($eq->brand?' '.$eq->brand:'').' '.$eq->model,
                'location'    => $eq->location,
                'breakdowns'  => (int)$k['breakdowns'],
                'downtime'    => round((float)$k['downtime_hours'], 1),
                'mttr'        => $k['mttr_hours'] === null ? 0 : round($k['mttr_hours'], 1),
                'mtbf'        => $k['mtbf_hours'] === null ? 0 : round($k['mtbf_hours'], 1),
                'availability'=> $k['availability_pct'] === null ? 100.0 : $k['availability_pct'],
            ];
        }
        usort($eq_reliability, fn($a,$b) => $a['availability'] <=> $b['availability']);

        // ── Top recurring faults
        $faults = $this->db->select('symptoms, COUNT(*) as cnt')
            ->where('start_datetime >=', $from)
            ->where('start_datetime <=', $to)
            ->where('symptoms IS NOT NULL', null, false)
            ->where('symptoms !=', '')
            ->group_by('symptoms')
            ->order_by('cnt DESC')
            ->limit(10)
            ->get('work_orders')->result();

        // ── PM compliance
        $pm_total     = $this->db->count_all_results('pm_schedules');
        $pm_done_year = $this->db->select('COUNT(DISTINCT equipment_id) as done')
            ->where('type','Preventive Maintenance')
            ->where('status IN ("Completed","Closed")', null, false)
            ->where('start_datetime >=', $from)
            ->where('start_datetime <=', $to)
            ->get('work_orders')->row();

        // ── Lube annual total
        $lube_annual = $this->db->select('lt.category, SUM(lc.quantity_used) as total, lt.unit')
            ->from('lube_consumption lc')
            ->join('lube_types lt','lt.id=lc.lube_type_id','left')
            ->where('lc.consumption_date >=', $from)
            ->where('lc.consumption_date <=', $year.'-12-31')
            ->group_by('lt.category, lt.unit')
            ->order_by('total DESC')
            ->get()->result();

        // ── Spare parts spend
        $parts_spend = $this->db
            ->select('SUM(wsp.total_cost) as total, COUNT(*) as cnt')
            ->from('wo_spare_parts wsp')
            ->join('work_orders wo','wo.id=wsp.work_order_id')
            ->where('wo.start_datetime >=', $from)
            ->where('wo.start_datetime <=', $to)
            ->get()->row();

        $eq_cnt = $this->db->where('status !=','Retired')->count_all_results('equipment');
        $avg_avail = count($monthly) > 0
            ? round(array_sum(array_column($monthly,'availability'))/count($monthly),1) : 0;
        $total_dt = (float)($totals_wo->total_dt ?? 0);
        $total_bd = (int)($totals_wo->bd ?? 0);

        return [
            'year'          => $year,
            'monthly'       => $monthly,
            'eq_reliability'=> $eq_reliability,
            'faults'        => $faults,
            'lube_annual'   => $lube_annual,
            'target'        => round($engine->target_for(null, $year . '-12-31') * 100, 1),
            'totals' => [
                'wo_total'    => (int)($totals_wo->total ?? 0),
                'breakdowns'  => $total_bd,
                'pm_done'     => (int)($totals_wo->pm ?? 0),
                'completed'   => (int)($totals_wo->done ?? 0),
                'critical'    => (int)($totals_wo->critical ?? 0),
                'total_dt'    => round($total_dt, 1),
                'avg_mttr'    => $total_bd>0 ? round($total_dt/$total_bd,1) : 0,
                'avg_avail'   => $avg_avail,
                'fleet_size'  => $eq_cnt,
                'parts_spend' => round((float)($parts_spend->total ?? 0), 2),
                'parts_count' => (int)($parts_spend->cnt ?? 0),
                'pm_compliance'=> $pm_total>0
                    ? round((int)($pm_done_year->done??0)/$pm_total*100) : 0,
            ],
        ];
    }

    /* ─── ANNUAL MAINTENANCE PLAN ────────────────────────── */

    public function get_annual_plan($year) {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $mFrom  = sprintf('%04d-%02d-01', $year, $m);
            $mTo    = date('Y-m-t', strtotime($mFrom));
            $label  = date('F', strtotime($mFrom));

            // PM due this month
            $pm = $this->db
                ->select('ps.schedule_name, ps.interval_type, ps.interval_value,
                          ps.next_due_date, ps.next_due_hours, ps.last_done_date,
                          e.registration_no, e.brand, e.model, et.name AS equip_type, e.location')
                ->from('pm_schedules ps')
                ->join('equipment e','e.id=ps.equipment_id')
                ->join('equipment_types et','et.id=e.equipment_type_id','left')
                ->where('ps.status','Active')
                ->where('ps.next_due_date >=', $mFrom)
                ->where('ps.next_due_date <=', $mTo)
                ->order_by('ps.next_due_date ASC')
                ->get()->result();

            // Group by type
            $byType = [];
            foreach ($pm as $p) {
                $t = strtoupper((string)($p->equip_type ?: 'OTHER'));
                $byType[$t][] = $p;
            }

            $months[] = [
                'num'    => $m,
                'label'  => $label,
                'from'   => $mFrom,
                'to'     => $mTo,
                'pm'     => $pm,
                'byType' => $byType,
                'count'  => count($pm),
            ];
        }

        // Equipment with no PM scheduled this year
        $allEqIds = $this->db->select('id')->where('status !=','Retired')->get('equipment')->result();
        $scheduledIds = $this->db->select('equipment_id')->distinct()
            ->where('status','Active')
            ->where('next_due_date >=', "{$year}-01-01")
            ->where('next_due_date <=', "{$year}-12-31")
            ->get('pm_schedules')->result();
        $scheduledSet = array_column($scheduledIds, 'equipment_id');
        $unscheduled  = array_filter($allEqIds, fn($e) => !in_array($e->id, $scheduledSet));

        $total_pm = array_sum(array_column($months, 'count'));

        return [
            'year'        => $year,
            'months'      => $months,
            'total_pm'    => $total_pm,
            'unscheduled' => count($unscheduled),
        ];
    }

    /* ─── SERVICE KIT RECOMMENDATIONS ───────────────────── */

    public function get_service_kit_recommendations() {
        $equipment = $this->db
            ->select('e.id, e.registration_no, et.name AS equip_type, e.brand, e.model,
                      e.current_hours, e.location, e.status AS equip_status')
            ->from('equipment e')
            ->join('equipment_types et','et.id=e.equipment_type_id','left')
            ->where('e.status !=','Retired')
            ->order_by('et.name, e.registration_no')
            ->get()->result();

        $equipmentIds = array();
        foreach ($equipment as $eq) $equipmentIds[] = (int)$eq->id;
        if (empty($equipmentIds)) {
            return array('kits' => array(), 'grouped' => array(), 'total_eq' => 0,
                         'generated_at' => date('d F Y H:i'));
        }

        // Batch all per-machine histories into a fixed number of reads instead
        // of repeating the history queries for every non-retired machine (N+1 scaling).
        $schedulesByEquipment = array();
        $schedules = $this->db
            ->select('equipment_id, id, schedule_name, interval_type, interval_value, next_due_date, next_due_hours, tasks_checklist')
            ->where_in('equipment_id', $equipmentIds)
            ->where('status','Active')
            ->order_by('equipment_id', 'ASC')
            ->order_by('interval_value', 'ASC')
            ->get('pm_schedules')->result();
        foreach ($schedules as $schedule) {
            $schedulesByEquipment[(int)$schedule->equipment_id][] = $schedule;
        }

        $partsByEquipment = array();
        // MySQL 8+ / MariaDB 10.4+ support window functions (the project DB
        // baseline). Rank after grouping so only the existing top 15 groups per
        // machine cross the DB boundary; cast IDs before composing the IN list.
        $equipmentIdSql = implode(',', array_map('intval', $equipmentIds));
        $sparePartsTable = $this->db->dbprefix('wo_spare_parts');
        $workOrdersTable = $this->db->dbprefix('work_orders');
        $partsSql = "SELECT ranked_parts.* FROM (
            SELECT grouped_parts.*,
                   ROW_NUMBER() OVER (
                       PARTITION BY equipment_id
                       ORDER BY times_used DESC, total_qty DESC
                   ) AS equipment_part_rank
            FROM (
                SELECT wo.equipment_id, wsp.part_name, wsp.part_number, wsp.unit,
                       SUM(wsp.quantity) AS total_qty,
                       COUNT(*) AS times_used,
                       AVG(wsp.unit_cost) AS avg_cost
                FROM {$sparePartsTable} wsp
                INNER JOIN {$workOrdersTable} wo ON wo.id = wsp.work_order_id
                WHERE wo.equipment_id IN ({$equipmentIdSql})
                  AND wsp.spare_type = 'New'
                GROUP BY wo.equipment_id, wsp.part_name, wsp.part_number
            ) grouped_parts
        ) ranked_parts
        WHERE equipment_part_rank <= 15
        ORDER BY equipment_id ASC, times_used DESC, total_qty DESC";
        $parts = $this->db->query($partsSql)->result();
        foreach ($parts as $part) {
            $partsByEquipment[(int)$part->equipment_id][] = $part;
        }

        $lubesByEquipment = array();
        $lubes = $this->db
            ->select('lc.equipment_id, lt.name, lt.category, lt.grade, lt.unit,
                      SUM(lc.quantity_used) as total_used, COUNT(*) as times')
            ->from('lube_consumption lc')
            ->join('lube_types lt','lt.id=lc.lube_type_id','left')
            ->where_in('lc.equipment_id', $equipmentIds)
            ->group_by('lc.equipment_id, lt.id, lt.name, lt.category, lt.grade, lt.unit')
            ->order_by('lc.equipment_id', 'ASC')
            ->order_by('total_used', 'DESC')
            ->get()->result();
        foreach ($lubes as $lube) $lubesByEquipment[(int)$lube->equipment_id][] = $lube;

        $oilsByEquipment = array();
        $oils = $this->db
            ->select('wo.equipment_id, wor.system_location, wor.oil_type, wor.grade,
                      SUM(wor.quantity) as total_qty, COUNT(*) as times')
            ->from('wo_oil_records wor')
            ->join('work_orders wo','wo.id=wor.work_order_id')
            ->where_in('wo.equipment_id', $equipmentIds)
            ->where('wor.oil_type IS NOT NULL', null, false)
            ->group_by('wo.equipment_id, wor.oil_type, wor.grade, wor.system_location')
            ->order_by('wo.equipment_id', 'ASC')
            ->order_by('times', 'DESC')
            ->get()->result();
        foreach ($oils as $oil) $oilsByEquipment[(int)$oil->equipment_id][] = $oil;

        $kits = array();
        foreach ($equipment as $eq) {
            $id = (int)$eq->id;
            $eqSchedules = isset($schedulesByEquipment[$id]) ? $schedulesByEquipment[$id] : array();
            $eqParts = isset($partsByEquipment[$id]) ? $partsByEquipment[$id] : array();
            $eqLubes = isset($lubesByEquipment[$id]) ? $lubesByEquipment[$id] : array();
            $eqOils = isset($oilsByEquipment[$id]) ? $oilsByEquipment[$id] : array();

            // Only include machines with recommendation/history data.
            if (empty($eqParts) && empty($eqLubes) && empty($eqOils) && empty($eqSchedules)) continue;

            // Next PM is the first active schedule by interval, matching the
            // prior per-equipment query ordering.
            $nextPm = !empty($eqSchedules) ? $eqSchedules[0] : null;
            $daysUntil = null;
            if ($nextPm && $nextPm->next_due_date) {
                $daysUntil = (int)ceil((strtotime($nextPm->next_due_date) - time()) / 86400);
            }

            $kits[] = array(
                'equipment_id' => $id,
                'machine_id'  => $eq->registration_no,
                'type'        => strtoupper((string)($eq->equip_type ?: 'OTHER')),
                'model'       => ($eq->brand?$eq->brand.' ':'').$eq->model,
                'location'    => $eq->location,
                'hours'       => (float)$eq->current_hours,
                'status'      => $eq->equip_status,
                'schedules'   => $eqSchedules,
                'parts'       => $eqParts,
                'lubes'       => $eqLubes,
                'oils'        => $eqOils,
                'next_pm'     => $nextPm ? $nextPm->schedule_name : null,
                'next_pm_date'=> $nextPm ? $nextPm->next_due_date : null,
                'days_until'  => $daysUntil,
            );
        }

        $grouped = array();
        foreach ($kits as $kit) $grouped[$kit['type']][] = $kit;

        return array(
            'kits'         => $kits,
            'grouped'      => $grouped,
            'total_eq'     => count($kits),
            'generated_at' => date('d F Y H:i'),
        );
    }
}
