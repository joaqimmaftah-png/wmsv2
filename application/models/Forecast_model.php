<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PLANNED AVAILABILITY FORECAST — the calculation engine.
 *
 * Task F3, on the business decisions D29…D33.
 *
 * ── The one rule that governs this whole file ────────────────────────────
 *
 * A forecast is a PLAN expressed in the SAME metric as the actual.  So this
 * model owns no availability arithmetic at all: it assembles planned hours
 * into the shape `kpi_helper.php` already consumes, and calls
 *
 *     kpi_availability($required, $downtime)   — D29, per machine and per day
 *     kpi_rollup($members)                     — D30, per group and for the fleet
 *
 * The historical workbook divided by the 24-hour calendar day with a typed
 * constant of 0.04166 and averaged machine percentages unweighted. Neither is
 * reproduced (defects 1–4 listed in `Forecast_excel`); both are offered as *labelled
 * reference* figures when `forecast_show_excel_reference` is on.
 *
 * ── How planned categories map onto the actual's formula ─────────────────
 *
 * `kpi_machine_period()` computes, for the actual week:
 *
 *     required   = open hours − preventive labour
 *     downtime   = breakdown hours
 *     availability = kpi_availability(required, downtime)
 *
 * The forecast mirrors that exactly, so plan and actual are the same number
 * measured at two moments:
 *
 *     preventive ← planned PM hours           (reduces the base, as D2 does)
 *     downtime   ← planned Standing + planned Unplanned
 *     required   = open hours − planned PM
 *
 * `OutOfService` (D32) is neither: the machine leaves the availability
 * population entirely and is counted in the unit view instead.
 *
 * CAVEAT, stated rather than hidden: the actuals never charge daily-check
 * ("Standing") time, because nothing records it.  A plan that charges it will
 * therefore read lower than its actual by roughly that amount.  That is why
 * the variance is split by category (task F6) — the bias is a named component
 * of the answer, not a surprise in the total.
 */

class Forecast_model extends CI_Model {

    /** D32 — the four planned-downtime categories. */
    const CAT_PM          = 'PM';
    const CAT_STANDING    = 'Standing';
    const CAT_UNPLANNED   = 'Unplanned';
    const CAT_OUT         = 'OutOfService';
    /**
     * MP-1 — a known, dated repair.  Same availability effect
     * as `Unplanned` (it is downtime), reported on its own line so the
     * breakdown *allowance* and the *known* repair are never one number
     * (R-MP5, OPEN-MP1).  Only accepted when the ENUM carries the value —
     * see `corrective_ready()`.
     */
    const CAT_CORRECTIVE  = 'Corrective';

    /** D31 — where a planned figure came from. */
    const SRC_DEFAULT = 'Default';
    const SRC_MANUAL  = 'Manual';
    const SRC_FROM_PM = 'FromPM';
    const SRC_DERIVED = 'Derived';
    /** MP-2 — hours synchronised from `maintenance_plan_jobs`. */
    const SRC_FROM_PLAN = 'FromPlan';

    /**
     * Sources a generator must never overwrite: the planner's hand edit (D31)
     * and the hours the planner's own jobs produced (MP-2).  Before MP-2 the
     * generators only protected `Manual`; a `FromPlan` row overwritten by
     * "Place PM from schedules" would be a job silently losing its hours.
     */
    public static $PROTECTED_SOURCES = array('Manual', 'FromPlan');

    const TABLE   = 'equipment_planned_downtime';
    const SNAPS   = 'forecast_snapshots';
    const PARTS   = 'pm_part_requirements';
    const JOBS    = 'maintenance_plan_jobs';

    /**
     * Collaborators, injectable so this model can be exercised without booting
     * the framework — the same convention `W36_report_model` uses.  In the
     * application they are loaded on first use.
     */
    public $spareparts = null;

    /**
     * `kpi_helper` is autoloaded (`application/config/autoload.php`), so this
     * model must not load it itself — the other models don't, and doing so
     * makes the model unconstructable outside the framework; the model must
     * stay constructable without booting the framework.
     */
    public function __construct() {
        parent::__construct();
        if (!$this->db) {
            $ci = get_instance();
            $this->db = $ci->db;
        }
    }

    /** False while the planned-downtime table is missing — callers degrade, not crash. */
    public function tables_ready() {
        return $this->db && $this->db->table_exists(self::TABLE);
    }

    /**
     * Whether maintenance-plan jobs are available: the job table must exist,
     * and with it the ENUM values `Corrective` / `FromPlan` (both arrive
     * together, so one probe answers both questions without reading
     * INFORMATION_SCHEMA).
     */
    public function jobs_ready() {
        return $this->db && $this->db->table_exists(self::JOBS);
    }

    /** Whether the `Corrective` category may be written (see CAT_CORRECTIVE). */
    public function corrective_ready() {
        return $this->jobs_ready();
    }

    /** The categories a planner may write today, in display order. */
    public function writable_categories() {
        $cats = array(self::CAT_PM, self::CAT_STANDING, self::CAT_UNPLANNED, self::CAT_OUT);
        if ($this->corrective_ready()) $cats[] = self::CAT_CORRECTIVE;
        return $cats;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  ISO WEEK
     * ──────────────────────────────────────────────────────────────── */

    /**
     * '2026-W40' (or any date) → the Monday that starts that ISO week.
     *
     * `DateTime::setISODate()` rather than "monday this week" arithmetic:
     * PHP's relative-week wording is resolved against the current locale week
     * and quietly returns the wrong Monday at a year boundary.
     *
     * @return string|null 'Y-m-d'
     */
    public function week_start($label) {
        $label = trim((string)$label);
        if ($label === '') return null;

        try {
            if (preg_match('/^(\d{4})-W(\d{1,2})$/', $label, $m)) {
                $year = (int)$m[1];
                $week = (int)$m[2];
                if ($week < 1 || $week > 53) return null;
                $d = new DateTime();
                $d->setISODate($year, $week, 1);          // 1 = Monday
                // week 53 does not exist in every year; setISODate rolls over,
                // so confirm the label round-trips before trusting it
                if ($d->format('o-\WW') !== sprintf('%04d-W%02d', $year, $week)) return null;
                return $d->format('Y-m-d');
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $label)) {
                $d = new DateTime($label);
                $d->setISODate((int)$d->format('o'), (int)$d->format('W'), 1);
                return $d->format('Y-m-d');
            }
        } catch (Exception $e) {
            return null;
        }
        return null;
    }

    /** '2026-09-28' → '2026-W40'. */
    public function iso_label($date) {
        try {
            return (new DateTime($date))->format('o-\WW');
        } catch (Exception $e) {
            return '';
        }
    }

    /** The seven 'Y-m-d' dates of a week, Monday first. */
    public function week_dates($week_start) {
        $out = array();
        for ($i = 0; $i < 7; $i++) {
            $out[] = date('Y-m-d', strtotime($week_start . ' +' . $i . ' days'));
        }
        return $out;
    }

    /** The last day of the week (Sunday). */
    public function week_end($week_start) {
        return date('Y-m-d', strtotime($week_start . ' +6 days'));
    }

    /* ─────────────────────────────────────────────────────────────────
     |  SETTINGS  (D31, F9)
     * ──────────────────────────────────────────────────────────────── */

    protected function _setting($key, $default) {
        if (!$this->db || !$this->db->table_exists('settings')) return $default;
        $row = $this->db->where('setting_key', $key)->get('settings')->row();
        if (!$row || $row->setting_value === null || $row->setting_value === '') return $default;
        return $row->setting_value;
    }

    public function daily_open_hours() {
        $v = (float)$this->_setting('daily_open_hours', 24);
        return $v > 0 ? $v : 24.0;
    }

    public function weekly_open_hours() {
        return $this->daily_open_hours() * 7;
    }

    /**
     * D31 — the standing ("Other") allowance per day, by equipment type.
     *
     * `standing_allowance_hours` is JSON keyed by equipment-type name; anything
     * not listed falls back to `standing_allowance_default_hours`.  The
     * historical workbook used one figure (2 h/day) for excavators and tower
     * lights alike, which is exactly what this replaces.
     *
     * @return array type name => hours per day
     */
    public function standing_allowances() {
        $raw     = $this->_setting('standing_allowance_hours', '{}');
        $default = (float)$this->_setting('standing_allowance_default_hours', 2);
        $map     = json_decode($raw, true);
        if (!is_array($map)) $map = array();

        $out = array('__default__' => $default);
        foreach ($map as $type => $hours) {
            $h = (float)$hours;
            $out[$type] = ($h >= 0 && $h <= 24) ? $h : $default;
        }
        return $out;
    }

    /** The standing allowance for one equipment type, per day. */
    public function standing_allowance_for($type_name) {
        $all = $this->standing_allowances();
        if ($type_name !== null && isset($all[$type_name])) return $all[$type_name];
        return $all['__default__'];
    }

    /**
     * MP-3 (R-MP2, OPEN-MP4 = D37): hours one weekly internal inspection takes.
     * 1 h for every machine type by decision; `inspection_hours_by_type` may
     * override a type, and nothing else changes.
     */
    public function inspection_hours_for($type_name) {
        $default = (float)$this->_setting('inspection_default_hours', 1);
        if ($default <= 0 || $default > 24) $default = 1.0;
        $map = json_decode((string)$this->_setting('inspection_hours_by_type', '{}'), true);
        if (is_array($map) && $type_name !== null && isset($map[$type_name])) {
            $h = (float)$map[$type_name];
            if ($h > 0 && $h <= 24) return $h;
        }
        return $default;
    }

    /** The reportable fleet, in report order (the same list the plan shows). */
    public function reportable_machines() {
        return $this->_machines();
    }

    /**
     * MP-3 — a weekday-placed round (`preferred_weekday` set, every 7 days) is
     * placed by Maintenance_plan_model::generate_inspections(), never by the
     * date-based PM generator, and it is not an unplaceable PM either.
     */
    public function is_weekly_round($ps) {
        if (!isset($ps->preferred_weekday) || $ps->preferred_weekday === null || $ps->preferred_weekday === '') return false;
        if (!isset($ps->interval_type) || strcasecmp((string)$ps->interval_type, 'Days') !== 0) return false;
        return (int)$ps->interval_value === 7;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  READING AND WRITING PLANNED DOWNTIME
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Every planned-downtime row of a week, keyed [equipment_id][date][category].
     *
     * @return array
     */
    public function planned_rows($week_start, $week_end) {
        $out = array();
        if (!$this->tables_ready()) return $out;

        $rows = $this->db
            ->where('plan_date >=', $week_start)
            ->where('plan_date <=', $week_end)
            ->get(self::TABLE)->result();

        foreach ($rows as $r) {
            $eid = (int)$r->equipment_id;
            if (!isset($out[$eid])) $out[$eid] = array();
            if (!isset($out[$eid][$r->plan_date])) $out[$eid][$r->plan_date] = array();
            $out[$eid][$r->plan_date][$r->category] = array(
                'hours' => (float)$r->hours,
                'source'=> $r->source,
                'note'  => $r->source_note,
            );
        }
        return $out;
    }

    /**
     * Upsert one machine-day-category.  The unique key
     * (equipment_id, plan_date, category) makes this safe to re-run.
     */
    public function set_planned($equipment_id, $plan_date, $category, $hours, $source = self::SRC_MANUAL, $note = null, $user_id = null) {
        if (!$this->tables_ready()) return false;

        $hours = round(max(0.0, min(24.0, (float)$hours)), 2);
        $data  = array(
            'equipment_id' => (int)$equipment_id,
            'plan_date'    => $plan_date,
            'category'     => $category,
            'hours'        => $hours,
            'source'       => $source,
            'source_note'  => $note,
        );

        $existing = $this->db
            ->where('equipment_id', (int)$equipment_id)
            ->where('plan_date', $plan_date)
            ->where('category', $category)
            ->get(self::TABLE)->row();

        if ($existing) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->where('id', $existing->id)->update(self::TABLE, $data);
            return (int)$existing->id;
        }
        $data['created_by'] = $user_id;
        $this->db->insert(self::TABLE, $data);
        return (int)$this->db->insert_id();
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F4 — GENERATE THE PLAN FROM PM SCHEDULES
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Place every PM due inside the week on its due date, using
     * `pm_schedules.standard_hours` as the duration.
     *
     * Idempotent: re-running updates the same rows rather than duplicating
     * them.  Rows a planner has since edited by hand — or that the planner's
     * own jobs produced (MP-2, source FromPlan) — are left alone unless
     * $overwrite is set: the planner's word beats the generator's.
     *
     * MP-0 / G-MP9 — what cannot be placed is REPORTED, never dropped:
     *   not_placed  every Active schedule with no due date at all (an hours-based
     *               service that was never completed has none, so it could
     *               never reach any week — probe P3), see pm_schedule_issues();
     *   estimated   hours-based services that were placed on a date the PM
     *               module estimated (+30 days from the last service), not on
     *               a date projected from the hour meter.
     *
     * @return array{placed:int,skipped:int,missing_hours:int,not_placed:array,estimated:array}
     */
    public function generate_from_pm($week_start, $user_id = null, $overwrite = false) {
        $placed = $skipped = 0;

        if (!$this->tables_ready()) {
            return array('placed' => 0, 'skipped' => 0, 'missing_hours' => 0,
                         'not_placed' => array(), 'estimated' => array(), 'missing' => array(),
                         'error'  => 'the forecast tables are not set up on this database');
        }

        // MP-4 — one plan, read here and shown by pm_schedule_issues(), so the
        // placement and the screen cannot disagree
        $plan = $this->_plan_pm($week_start);

        foreach ($plan['candidates'] as $c) {
            if (!$overwrite) {
                $existing = $this->_ledger_cell($c['equipment_id'], $c['date'], self::CAT_PM);
                if ($existing && in_array($existing->source, self::$PROTECTED_SOURCES, true)) {
                    $skipped++; continue;
                }
            }
            $this->set_planned($c['equipment_id'], $c['date'], self::CAT_PM, $c['hours'],
                               self::SRC_FROM_PM, $c['note'], $user_id);
            $placed++;
        }

        return array('placed'        => $placed,
                     'skipped'       => $skipped,
                     'missing_hours' => count($plan['missing']),
                     'missing'       => $plan['missing'],
                     'not_placed'    => $plan['not_placed'],
                     'estimated'     => $plan['estimated'],
                     'replaced'      => $plan['replaced'],
                     'later'         => $plan['later'],
                     'overdue'       => $plan['overdue']);
    }

    /**
     * MP-4 — the whole PM placement for one week, read-only.
     *
     * Date-based schedules (every interval except hours) are placed on their
     * next_due_date, as before.  Hour-meter services are placed on the date the
     * meter projects (meter_projection()), never on the PM module's +30-day
     * placeholder.  Where two meter services of one machine fall on the same
     * day, the largest interval is the cycle and the others are replaced (D36).
     * Readings and staleness are judged as at the week's last day.
     */
    protected function _plan_pm($week_start) {
        $week_end = $this->week_end($week_start);
        $out = array('candidates' => array(), 'missing' => array(),
                     'not_placed' => $this->pm_unplaceable($week_end),
                     'estimated' => array(), 'replaced' => array(), 'later' => array(), 'overdue' => 0);
        if (!$this->tables_ready()) return $out;

        $has_std = $this->db->field_exists('standard_hours', 'pm_schedules');
        $has_wd  = $this->db->field_exists('preferred_weekday', 'pm_schedules');
        $schedules = $this->db
            ->select('ps.id, ps.equipment_id, ps.schedule_name, ps.interval_type, ps.interval_value,
                      ps.next_due_date, ps.next_due_hours, ps.last_done_hours'
                      . ($has_std ? ', ps.standard_hours' : '')
                      . ($has_wd ? ', ps.preferred_weekday' : ''))
            ->from('pm_schedules ps')
            ->where('ps.status', 'Active')
            ->get()->result();

        $rows = array();        // [schedule, date, is_meter, projection, overdue]
        $hours_ps = array();
        foreach ($schedules as $ps) {
            if ($this->is_weekly_round($ps)) continue;             // MP-3 — placed by weekday
            if ($this->_is_hours($ps)) { $hours_ps[] = $ps; continue; }
            $date = $ps->next_due_date;
            if ($date === null || $date === '' || $date < $week_start || $date > $week_end) continue;
            $rows[] = array($ps, $date, false);
        }

        $proj = $this->meter_projection($hours_ps, $week_end);
        foreach ($hours_ps as $ps) {
            $p = isset($proj[(int)$ps->id]) ? $proj[(int)$ps->id] : null;
            if (!$p || $p['status'] !== 'projected') continue;      // listed by pm_unplaceable()
            if ($p['date'] > $week_end) {
                $out['later'][] = $this->_pm_issue($ps, 'projected for ' . $p['date'] . ' — a later week');
                continue;
            }
            $date = $p['date'] < $week_start ? $week_start : $p['date'];   // overdue: the first week it can go
            $rows[] = array($ps, $date, true, $p, (bool)$p['overdue']);
        }

        // D36 — one service cycle per machine: among meter services due the same
        // day, the largest interval is kept; the others are replaced by it.
        $keep = array();
        foreach ($rows as $i => $row) {
            if (empty($row[2])) continue;
            $k = (int)$row[0]->equipment_id . '|' . $row[1];
            if (!isset($keep[$k]) || $this->_bigger_service($row[0], $rows[$keep[$k]][0])) $keep[$k] = $i;
        }
        $kept_idx = array_flip($keep);

        foreach ($rows as $i => $row) {
            list($ps, $date, $is_meter) = $row;
            if ($is_meter && !isset($kept_idx[$i])) {
                $winner = $rows[$keep[(int)$ps->equipment_id . '|' . $date]][0];
                $item = $this->_pm_issue($ps, 'replaced by "' . $winner->schedule_name . '" on the same day — one engine-hour cycle per machine (D36)');
                $item['date']        = $date;
                $item['replaced_by'] = (string)$winner->schedule_name;
                $out['replaced'][] = $item;
                continue;
            }

            $hours = $has_std ? $ps->standard_hours : null;
            if ($hours === null || (float)$hours <= 0) {
                // A PM with no standard duration cannot be placed.  Reported, never guessed.
                $out['missing'][] = $this->_pm_issue($ps, 'no standard_hours — the duration is unknown');
                continue;
            }

            $note = 'PM #' . $ps->id . ' ' . $ps->schedule_name;
            if ($is_meter) {
                $p = $row[3];
                $note .= ' — projected from meter: ' . $p['detail'] . (!empty($row[4]) ? ' (overdue)' : '');
                $out['estimated'][] = $this->_pm_issue($ps, 'projected from the meter (an estimate)');
                if (!empty($row[4])) $out['overdue']++;
            } elseif ($this->_is_meter_based($ps)) {
                $note .= ' (date estimated, not from meter)';
                $out['estimated'][] = $this->_pm_issue($ps, 'date estimated, not from meter');
            }
            $out['candidates'][] = array('schedule_id' => (int)$ps->id, 'equipment_id' => (int)$ps->equipment_id,
                                         'date' => $date, 'hours' => $hours, 'note' => $note);
        }
        return $out;
    }

    /** Is hours the schedule's meter (the projection covers hours only; km has no due value yet). */
    protected function _is_hours($ps) {
        return isset($ps->interval_type) && strtolower((string)$ps->interval_type) === 'hours';
    }

    /** D36 hierarchy: the larger interval is the cycle; ties keep the earlier schedule. */
    protected function _bigger_service($a, $b) {
        $x = (int)$a->interval_value; $y = (int)$b->interval_value;
        if ($x !== $y) return $x > $y;
        return (int)$a->id < (int)$b->id;
    }

    /**
     * MP-4 (R-MP3) — project the due date of hour-meter services from the
     * machine's own meter readings.  Read-only: nothing is written to the PM
     * schedule.  Every input schedule gets one entry, with status `projected`
     * (a date and the working shown) or `not_placed` (a reason).
     *
     *   due      next_due_hours, or last_done_hours + interval (no due value → not placed)
     *   current  the newest reading on or before $ref_date (no reading → not placed)
     *   stale    a reading older than pm_meter_stale_days before $ref_date is not judged (D22)
     *   rate     hours per day from the first and last reading in the lookback window,
     *            else the type's default (pm_default_hours_per_day); neither → not placed
     *   date     current reading's date + ceil((due − current) ÷ rate)
     *
     * @return array schedule id => entry
     */
    public function meter_projection(array $schedules, $ref_date) {
        $out      = array();
        $lookback = max(1, (int)$this->_setting('pm_utilisation_lookback_days', 28));
        $stale    = (int)$this->_setting('pm_meter_stale_days', 30);
        $defaults = json_decode((string)$this->_setting('pm_default_hours_per_day', '{}'), true);
        if (!is_array($defaults)) $defaults = array();

        $machines = array();
        foreach ($this->_machines() as $m) $machines[(int)$m->id] = $m;

        $readings = array();
        if ($this->db->table_exists('equipment_meter_readings')) {
            $this->db->where('reading_date <=', $ref_date);     // only what the reference date can see
            foreach ($this->db->get('equipment_meter_readings')->result() as $r) {
                $day = substr((string)$r->reading_date, 0, 10);
                if ($r->hours === null || (float)$r->hours <= 0 || $day > $ref_date) continue;
                $readings[(int)$r->equipment_id][] = array($day, (float)$r->hours);
            }
            foreach ($readings as $eid => $list) {
                usort($readings[$eid], function ($a, $b) {
                    if ($a[0] !== $b[0]) return strcmp($a[0], $b[0]);
                    return $a[1] <=> $b[1];
                });
            }
        }

        foreach ($schedules as $ps) {
            $id   = (int)$ps->id;
            $eid  = (int)$ps->equipment_id;
            $m    = isset($machines[$eid]) ? $machines[$eid] : null;
            $type = ($m && isset($m->equip_type)) ? $m->equip_type : null;
            $base = array('schedule_id' => $id, 'equipment_id' => $eid,
                          'schedule_name' => (string)$ps->schedule_name,
                          'registration_no' => isset($ps->registration_no) ? $ps->registration_no
                                             : ($m ? $m->registration_no : null),
                          'status' => 'not_placed', 'reason' => null, 'date' => null,
                          'detail' => null, 'overdue' => false);
            $not = function ($reason) use ($base) {
                $base['reason'] = 'meter-based service: ' . $reason;
                return $base;
            };

            if (!$this->_is_hours($ps)) {
                $out[$id] = $not('only hour-meter services are projected; a km service has no km due value on the schedule yet');
                continue;
            }

            $due = null;
            if (isset($ps->next_due_hours) && $ps->next_due_hours !== null && (float)$ps->next_due_hours > 0) {
                $due = (float)$ps->next_due_hours;
            } elseif (isset($ps->last_done_hours) && $ps->last_done_hours !== null && (float)$ps->last_done_hours > 0) {
                $due = (float)$ps->last_done_hours + (float)$ps->interval_value;
            }
            if ($due === null) {
                $out[$id] = $not('no due hours recorded — neither a next due hour nor a last service hour');
                continue;
            }

            $rd = isset($readings[$eid]) ? $readings[$eid] : array();
            if (!$rd) {
                $out[$id] = $not('no meter reading on file, so it cannot be projected — record a reading');
                continue;
            }
            $cur_date = $rd[count($rd) - 1][0];
            $cur      = $rd[count($rd) - 1][1];
            if ($stale > 0 && $cur_date < date('Y-m-d', strtotime($ref_date . ' -' . $stale . ' days'))) {
                $out[$id] = $not('the newest reading (' . $cur_date . ') is older than ' . $stale
                                 . ' days, so it is not judged (pm_meter_stale_days)');
                continue;
            }

            $from = date('Y-m-d', strtotime($cur_date . ' -' . $lookback . ' days'));
            $win  = array();
            foreach ($rd as $x) if ($x[0] >= $from && $x[0] <= $cur_date) $win[] = $x;
            $util = null; $src = null;
            if (count($win) >= 2) {
                $a = $win[0]; $b = $win[count($win) - 1];
                $span = (strtotime($b[0]) - strtotime($a[0])) / 86400;
                if ($span > 0 && $b[1] > $a[1]) { $util = ($b[1] - $a[1]) / $span; $src = 'readings'; }
            }
            if ($util === null && $type !== null && isset($defaults[$type])) {
                $d = (float)$defaults[$type];
                if ($d > 0 && $d <= 24) { $util = $d; $src = 'default'; }
            }
            if ($util === null) {
                $out[$id] = $not('no hours per day — fewer than two readings in the last ' . $lookback
                                 . ' days, and no default for this type (pm_default_hours_per_day)');
                continue;
            }

            $remaining = $due - $cur;
            $overdue   = $remaining <= 0;
            $days      = $overdue ? 0 : (int)ceil($remaining / $util - 1e-9);
            $date      = date('Y-m-d', strtotime($cur_date . ' +' . $days . ' days'));
            $detail    = number_format((float)($cur), 0) . ' h + ' . rtrim(rtrim((string)(number_format((float)($util), 2, '.', '')), '0'), '.')
                       . ' h/day' . ($src === 'default' ? ' (default)' : '')
                       . ' → due ≈ ' . $date . ' (' . number_format((float)($due), 0) . ' h)';

            $out[$id] = array_merge($base, array('status' => 'projected', 'reason' => null, 'date' => $date,
                                                 'detail' => $detail, 'overdue' => $overdue,
                                                 'current' => $cur, 'utilisation' => $util, 'due' => $due));
        }
        return $out;
    }

    /** Hours-based or km-based interval: the due date is not a calendar fact. */
    protected function _is_meter_based($ps) {
        $t = isset($ps->interval_type) ? strtolower((string)$ps->interval_type) : '';
        return in_array($t, array('hours', 'km', 'kilometres', 'kilometers'), true);
    }

    protected function _pm_issue($ps, $reason) {
        return array(
            'schedule_id'     => (int)$ps->id,
            'equipment_id'    => (int)$ps->equipment_id,
            'registration_no' => isset($ps->registration_no) ? $ps->registration_no : null,
            'schedule_name'   => (string)$ps->schedule_name,
            'interval'        => trim((string)((isset($ps->interval_value) ? $ps->interval_value : '') . ' '
                                      . (isset($ps->interval_type) ? $ps->interval_type : ''))),
            'next_due_date'   => isset($ps->next_due_date) ? $ps->next_due_date : null,
            'reason'          => $reason,
        );
    }

    /**
     * Active PM schedules that cannot be placed in any week: hour-meter services
     * that cannot be projected (with the reason), and date-based ones with no due
     * date.  $ref_date is the day the readings are judged on (default: today).
     *
     * @return array list of _pm_issue() arrays
     */
    public function pm_unplaceable($ref_date = null) {
        if (!$this->db->table_exists('pm_schedules')) return array();
        $ref_date = $ref_date ?: date('Y-m-d');
        $has_wd = $this->db->field_exists('preferred_weekday', 'pm_schedules');
        $rows = $this->db
            ->select('ps.id, ps.equipment_id, ps.schedule_name, ps.interval_type, ps.interval_value,
                      ps.next_due_date, ps.next_due_hours, ps.last_done_hours, ps.status, e.registration_no'
                      . ($has_wd ? ', ps.preferred_weekday' : ''))
            ->from('pm_schedules ps')
            ->join('equipment e', 'e.id = ps.equipment_id', 'left')
            ->where('ps.status', 'Active')
            ->get()->result();
        $out = array();
        $hours = array();
        foreach ($rows as $ps) {
            if (isset($ps->status) && $ps->status !== 'Active') continue;
            if ($this->is_weekly_round($ps)) continue;             // MP-3
            if ($this->_is_hours($ps)) { $hours[] = $ps; continue; }
            if (!empty($ps->next_due_date)) continue;
            $out[] = $this->_pm_issue($ps, $this->_is_meter_based($ps)
                ? 'meter-based service with no due date — no date can be given without a meter projection'
                : 'no due date — record when it was last done');
        }
        $proj = $this->meter_projection($hours, $ref_date);
        foreach ($hours as $ps) {
            $p = isset($proj[(int)$ps->id]) ? $proj[(int)$ps->id] : null;
            if ($p && $p['status'] === 'not_placed') $out[] = $this->_pm_issue($ps, $p['reason']);
        }
        return $out;
    }

    /**
     * Everything about PM placement the planner must see for a week: the
     * schedules that cannot be placed (with reasons), the ones due with no
     * duration, the meter services placed on a projected date, the services a
     * cycle replaced, and the ones projected for a later week.  Read-only.
     */
    public function pm_schedule_issues($week_start) {
        $plan = $this->_plan_pm($week_start);
        return array('not_placed' => $plan['not_placed'], 'missing' => $plan['missing'],
                     'estimated'  => $plan['estimated'], 'replaced' => $plan['replaced'],
                     'later'      => $plan['later'],     'overdue'  => $plan['overdue']);
    }

    /** The ledger row of one machine-day-category, whatever its source. */
    protected function _ledger_cell($equipment_id, $date, $category) {
        return $this->db
            ->where('equipment_id', (int)$equipment_id)
            ->where('plan_date', $date)
            ->where('category', $category)
            ->get(self::TABLE)->row();
    }

    /** Remove a generated row (Default / Derived) — never a protected one. */
    protected function _drop_generated($equipment_id, $date, $category) {
        $row = $this->_ledger_cell($equipment_id, $date, $category);
        if ($row && in_array($row->source, array(self::SRC_DEFAULT, self::SRC_DERIVED), true)) {
            $this->db->where('id', $row->id)->delete(self::TABLE);
            return true;
        }
        return false;
    }

    /**
     * MP-0 / G-MP10 — machine-days on which an allowance must not be charged.
     *
     * A day is HELD when the machine is already accounted for: PM, out of
     * service, known repair and any hand-entered or job-entered unplanned time
     * add up to the whole open day.  Charging a 2 h "standing" allowance on a
     * day the machine is in the workshop for 24 h is the overbooking the
     * workbook could not see (GEN 001, probe P2).  A day with a Standby job
     * (MP-2) is also held: the machine is reserve capacity that day.
     *
     * @return array eid => [date => reason]
     */
    public function held_days($week_start) {
        $week_end = $this->week_end($week_start);
        $open     = $this->daily_open_hours();
        $held     = array();
        foreach ($this->planned_rows($week_start, $week_end) as $eid => $by_day) {
            foreach ($by_day as $d => $cell) {
                $h = 0.0;
                foreach (array(self::CAT_PM, self::CAT_OUT, self::CAT_CORRECTIVE) as $c) {
                    if (isset($cell[$c])) $h += $cell[$c]['hours'];
                }
                if (isset($cell[self::CAT_UNPLANNED])
                    && in_array($cell[self::CAT_UNPLANNED]['source'], self::$PROTECTED_SOURCES, true)) {
                    $h += $cell[self::CAT_UNPLANNED]['hours'];
                }
                if ($h >= $open * 0.999) $held[$eid][$d] = 'fully booked (' . round($h, 2) . ' h)';
            }
        }
        foreach ($this->week_jobs($week_start, $week_end) as $j) {
            if ($j['job_type'] !== 'Standby') continue;
            foreach ($this->week_dates($week_start) as $d) {
                if ($d >= $j['start_date'] && $d <= $j['end_date'] && !isset($held[$j['equipment_id']][$d])) {
                    $held[$j['equipment_id']][$d] = 'stand-by';
                }
            }
        }
        return $held;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  THE ENGINE — planned availability for one week
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Build the whole planned week: machine → group → fleet.
     *
     * @param  string $week_start 'Y-m-d' (Monday)
     * @return array
     */
    public function planned_availability($week_start) {
        $week_end = $this->week_end($week_start);
        $days     = $this->week_dates($week_start);
        $daily_open = $this->daily_open_hours();
        $weekly_open = $daily_open * 7;

        $machines = $this->_machines();
        $planned  = $this->planned_rows($week_start, $week_end);
        $allows   = $this->standing_allowances();

        $members   = array();      // feeds kpi_rollup()
        $rows      = array();      // the screen's per-machine rows
        $warnings  = array();      // MP-0 — overbooked machine-days, never silently clamped

        // MP-2 — the planner's jobs for the week, read-only here, so a
        // published snapshot freezes the jobs together with their hours (D33)
        $week_jobs       = $this->week_jobs($week_start, $week_end);
        $jobs_by_day     = array();
        $jobs_by_machine = array();
        foreach ($week_jobs as $j) {
            $jid = (int)$j['equipment_id'];
            $jobs_by_machine[$jid] = (isset($jobs_by_machine[$jid]) ? $jobs_by_machine[$jid] : 0) + 1;
            foreach ($days as $d) {
                if ($d < $j['start_date'] || $d > $j['end_date']) continue;
                $jobs_by_day[$jid][$d][] = $j;
            }
        }
        $by_group  = array();
        $unit      = array('total' => 0, 'out_of_service' => 0, 'stand_by' => 0, 'available' => 0);

        foreach ($machines as $m) {
            $eid   = (int)$m->id;
            /*
             * Trimmed, because these come from user-entered name columns.
             *
             * The historical workbook's own type label is 'DOZERS ' with a
             * trailing space, so the same fleet can be filed under both
             * 'DOZERS' and 'DOZERS '.  Un-trimmed, those become two groups with
             * two roll-ups — the same class of split that once made a machine
             * fall out of its own group, and neither half would be the truth.
             */
            $type  = trim((string)$m->equip_type);
            if ($type === '') $type = 'OTHER';
            $group = trim((string)$m->report_group);
            if ($group === '') $group = $type;
            $plan  = isset($planned[$eid]) ? $planned[$eid] : array();

            $unit['total']++;

            // ── is this machine planned out of service for the WHOLE week? (D32)
            $out_hours = 0.0;
            foreach ($days as $d) {
                if (isset($plan[$d][self::CAT_OUT])) $out_hours += $plan[$d][self::CAT_OUT]['hours'];
            }
            $is_out = $out_hours >= $weekly_open * 0.999;

            // ── per-day planned figures
            $daily = array();
            $pm_h = $st_h = $un_h = $co_h = $req_h = $dt_h = 0.0;
            $out_days = 0;

            foreach ($days as $d) {
                $cell = isset($plan[$d]) ? $plan[$d] : array();
                $pm  = isset($cell[self::CAT_PM])         ? (float)$cell[self::CAT_PM]['hours']         : 0.0;
                $st  = isset($cell[self::CAT_STANDING])   ? (float)$cell[self::CAT_STANDING]['hours']   : 0.0;
                $un  = isset($cell[self::CAT_UNPLANNED])  ? (float)$cell[self::CAT_UNPLANNED]['hours']  : 0.0;
                $co  = isset($cell[self::CAT_CORRECTIVE]) ? (float)$cell[self::CAT_CORRECTIVE]['hours'] : 0.0;
                $out = isset($cell[self::CAT_OUT])        ? (float)$cell[self::CAT_OUT]['hours']        : 0.0;

                $pm_h += $pm; $st_h += $st; $un_h += $un; $co_h += $co;

                /*
                 * MP-0 / G-MP6 — out-of-service time is CAPACITY, per day.
                 *
                 * D32 was implemented for the whole week only: a day's out
                 * hours were read to decide whole-week out of service and then
                 * ignored, so LV 009 out Monday–Tuesday read 100 % available and
                 * counted as an available unit on Monday (probe P1).  The same
                 * rule D32 applies to the week now applies to each day: out time
                 * leaves the denominator, it is not downtime.
                 */
                $req = round(max(0.0, $daily_open - $pm - $out), 4);
                $dt  = round($st + $un + $co, 4);

                /*
                 * MP-0 / G-MP7 — a day cannot lose more hours than it has.
                 *
                 * The workbook at least printed "ERR"; the engine used to clamp
                 * silently (GEN 001: 24 h repair + 3 h checks = 27 h, probe P2).
                 * The booking is reported as a warning, and the downtime that
                 * counts is capped at the day's required time — the KPI never
                 * charges hours the day does not have.
                 */
                $booked = round($pm + $st + $un + $co + $out, 4);
                if ($booked > $daily_open + 0.0001) {
                    $warnings[] = array(
                        'type'            => 'overbooked',
                        'equipment_id'    => $eid,
                        'registration_no' => $m->registration_no,
                        'date'            => $d,
                        'booked_hours'    => $booked,
                        'open_hours'      => $daily_open,
                        'excess_hours'    => round($booked - $daily_open, 4),
                        'message'         => sprintf('%s on %s is booked %s h on a %s h day',
                                                     $m->registration_no, $d,
                                                     rtrim(rtrim((string)(number_format((float)($booked), 2, '.', '')), '0'), '.'),
                                                     rtrim(rtrim((string)(number_format((float)($daily_open), 2, '.', '')), '0'), '.')),
                    );
                }
                $dt_kpi = min($dt, $req);
                $is_out_day = $out >= $daily_open * 0.999;
                if ($is_out_day) $out_days++;

                $req_h += $req;
                $dt_h  += $dt_kpi;

                $daily[$d] = array(
                    'date'             => $d,
                    'pm_hours'         => $pm,
                    'standing_hours'   => $st,
                    'unplanned_hours'  => $un,
                    'corrective_hours' => $co,
                    'out_hours'        => $out,
                    'out_of_service'   => $is_out_day,
                    'booked_hours'     => $booked,
                    'overbooked'       => $booked > $daily_open + 0.0001,
                    'required_hours'   => $req,
                    'downtime_hours'   => round($dt_kpi, 4),
                    // D29 — the shipped function, never a re-implementation
                    'availability'     => kpi_availability($req, $dt_kpi),
                    'jobs'             => isset($jobs_by_day[$eid][$d]) ? $jobs_by_day[$eid][$d] : array(),
                );
            }

            /*
             * The weekly denominator is D32's own formula — open time minus PM
             * minus out-of-service time — now with partial-week out time in it.
             * It equals the sum of the daily figures on every day that is not
             * overbooked; on an overbooked day (already a warning) the week keeps
             * the hours the planner typed rather than inventing a different cap.
             */
            $required = round(max(0.0, $weekly_open - $pm_h - $out_hours), 4);
            $downtime = round($dt_h, 4);
            $avail    = kpi_availability($required, $downtime);

            /*
             * A plan that leaves no open time at all is a machine planned out of
             * service, whatever category the planner filed the hours under — and
             * `kpi_availability()` is undefined (null) on a zero base.  Folding
             * it into the OutOfService population keeps D32's rule honest and
             * stops a data-entry choice from printing "—" in the fleet figure.
             * This is also the workbook's out-of-service defect: it printed
             * 0.02 % for CR 001 because
             * it had no way to say "not in service at all".
             *
             * Two ways to get there, and both have to count.  `$required <= 0`
             * is PM (or out time) eating the whole week.  The other is downtime
             * eating it: the historical week had four machines — DZ 001, DZ 002,
             * TL 002, TL 004 — filed as 168 h of "Other" rather than as service,
             * which left `$required` at 168 and scored them 0 % *inside* the
             * availability population, dragging their groups down.  The test
             * that matters is not which column the hours were typed in but
             * whether the machine has a single open hour left in the week.
             */
            $has_open_day = false;
            foreach ($daily as $cell) {
                if ($cell['required_hours'] > 0
                    && ($cell['required_hours'] - $cell['downtime_hours']) > 0) {
                    $has_open_day = true;
                    break;
                }
            }
            if ($required <= 0 || !$has_open_day) {
                $is_out = true;
                $avail  = 0.0;
            }

            $status = isset($m->status) ? (string)$m->status : '';
            $is_standby = strcasecmp($status, 'Standby') === 0;
            $rows[] = array(
                'equipment_id'     => $eid,
                'registration_no'  => $m->registration_no,
                'type'             => $type,
                'group'            => $group,
                'location'         => $m->location,
                'status'           => $status,
                'standby'          => $is_standby,
                'out_of_service'   => $is_out,
                'out_days'         => $out_days,
                'pm_hours'         => round($pm_h, 2),
                'standing_hours'   => round($st_h, 2),
                'unplanned_hours'  => round($un_h, 2),
                'corrective_hours' => round($co_h, 2),
                'out_hours'        => round($out_hours, 2),
                'required_hours'   => $required,
                'downtime_hours'   => $downtime,
                'availability'     => $avail,
                'daily'            => $daily,
                'has_plan'         => ($pm_h + $st_h + $un_h + $co_h + $out_hours) > 0
                                      || !empty($jobs_by_machine[$eid]),
                'job_count'        => isset($jobs_by_machine[$eid]) ? $jobs_by_machine[$eid] : 0,
            );

            /*
             * The group is registered before the out-of-service test, not after.
             *
             * Registering it afterwards dropped any group whose machines were
             * *all* planned out of service — a crane fleet with its one crane
             * down for the week simply vanished from the group list, and with it
             * the machine, since both the screen and the export walk `$groups`.
             * That is the dropped-machine defect (EX 006 missing from its own
             * roll-up) arriving by a
             * different route, and D32 asks for the opposite: out of service is
             * excluded from the *availability population*, never from the view.
             *
             * So the group always exists; only its `members` — what feeds
             * kpi_rollup() — skips the machine.
             */
            if (!isset($by_group[$group])) {
                $by_group[$group] = array('group' => $group, 'members' => array(),
                                          'machines' => 0, 'out_of_service' => 0, 'stand_by' => 0);
            }
            $by_group[$group]['machines']++;

            if ($is_out) {
                $unit['out_of_service']++;
                $by_group[$group]['out_of_service']++;
                continue;                      // D32 — leaves the availability population
            }

            /*
             * OPEN-MP2 (answered: leave stand-by out of the percentage) — a
             * Standby machine is reserve capacity, not operating capacity. It
             * is counted and shown as reserve, but it does not enter the
             * availability population, so it can neither lift nor lower the
             * group or fleet figure. Its own row still shows the machine's
             * plan, and the badge says why it is not scored.
             */
            if ($is_standby) {
                $unit['stand_by']++;
                $by_group[$group]['stand_by']++;
                continue;
            }
            $unit['available']++;

            // kpi_rollup()'s own input shape, built from planned hours
            $members[] = array(
                'required_time_hours' => $required,
                'downtime_hours'      => $downtime,
                'preventive_hours'    => round($pm_h, 4),
                'hours_down'          => $downtime,
                'breakdowns'          => 0,        // a plan forecasts no events
                'days_down'           => 0,
                'weekly_target'       => null,
            );
            $by_group[$group]['members'][] = end($members);
        }

        // ── group roll-ups, capacity-weighted (D30)
        $zero_mode = $this->_setting('mtbf_zero_failure_mode', 'dash');
        $groups = array();
        foreach ($by_group as $g) {
            $roll = kpi_rollup($g['members'], $zero_mode);
            $unweighted = $this->unweighted_mean($g['members']);
            $groups[] = array(
                'group'            => $g['group'],
                'machines'         => $g['machines'],
                'out_of_service'   => $g['out_of_service'],   // D32 — shown, never scored
                'stand_by'         => $g['stand_by'],         // OPEN-MP2 — reserve, never scored
                'required_hours'   => $roll['required_time_hours'],
                'downtime_hours'   => $roll['downtime_hours'],
                'pm_hours'         => $roll['preventive_hours'],
                'availability'     => $roll['availability'],       // D30 — the primary KPI
                'availability_unweighted' => $unweighted,          // reference only
            );
        }
        usort($groups, function ($a, $b) {
            $x = $a['availability'] === null ? -1 : $a['availability'];
            $y = $b['availability'] === null ? -1 : $b['availability'];
            return $x <=> $y;                    // worst group first: where to send the crew
        });

        /* ── F7: the unit view — machines available per group per day ─────
         *
         * The workbook's `WEEK 40 PLANNED AVAILABILITIES` sheet was a headcount
         * (91 of 103 available), not a percentage — the figure production plans
         * a shift around.  It is a different question from availability: a
         * machine at 60 % is still a machine you can put an operator on.
         *
         * A machine counts as available on a day when it is in the availability
         * population (D32 — not planned out of service for the week) and that
         * day still has required time left after the planned downtime.  Nothing
         * here is recomputed: it is read back off the same `daily` cells the
         * KPI figures came from, so the two views cannot disagree.
         */
        $unit_by_group = array();
        foreach ($days as $d) {
            $unit_by_group['__fleet__'][$d] = array('available' => 0, 'total' => 0, 'out_of_service' => 0, 'stand_by' => 0);
        }
        foreach ($rows as $r) {
            $g = $r['group'];
            if (!isset($unit_by_group[$g])) {
                $unit_by_group[$g] = array();
                foreach ($days as $d) $unit_by_group[$g][$d] = array('available' => 0, 'total' => 0, 'out_of_service' => 0, 'stand_by' => 0);
            }
            foreach ($days as $d) {
                $cell = $r['daily'][$d];
                // remaining open time on that day, after the planned downtime
                $left = $cell['required_hours'] - $cell['downtime_hours'];
                $ok   = !$r['out_of_service'] && !$r['standby'] && $cell['required_hours'] > 0 && $left > 0;
                // MP-0 — a day planned out of service is a capacity fact on that
                // day too, not only when the whole week is out (G-MP6)
                $out_today = $r['out_of_service'] || !empty($cell['out_of_service']);

                $unit_by_group[$g][$d]['total']++;
                $unit_by_group['__fleet__'][$d]['total']++;
                if ($out_today) {
                    $unit_by_group[$g][$d]['out_of_service']++;
                    $unit_by_group['__fleet__'][$d]['out_of_service']++;
                } elseif ($r['standby']) {
                    $unit_by_group[$g][$d]['stand_by']++;
                    $unit_by_group['__fleet__'][$d]['stand_by']++;
                } elseif ($ok) {
                    $unit_by_group[$g][$d]['available']++;
                    $unit_by_group['__fleet__'][$d]['available']++;
                }
            }
        }

        // ── fleet roll-up, the same function
        $fleet = kpi_rollup($members, $zero_mode);
        // the same helper the groups use, so the two reference figures cannot
        // be computed two different ways
        $fleet_unweighted = $this->unweighted_mean($members);

        return array(
            'week_start'   => $week_start,
            'week_end'     => $week_end,
            'iso_label'    => $this->iso_label($week_start),
            'days'         => $days,
            'daily_open_hours'  => $daily_open,
            'weekly_open_hours' => $weekly_open,
            'rows'         => $rows,
            'groups'       => $groups,
            'unit'         => $unit,
            'units_by_day' => $unit_by_group,      // F7 — headcount per group per day
            'fleet'        => array(
                'availability'          => $fleet['availability'],
                'availability_unweighted' => $fleet_unweighted,
                'required_hours'        => $fleet['required_time_hours'],
                'downtime_hours'        => $fleet['downtime_hours'],
                'pm_hours'              => $fleet['preventive_hours'],
                'machines'              => $fleet['machines'],
            ),
            'machines_with_plan' => count(array_filter($rows, function ($r) { return $r['has_plan']; })),
            'corrective_hours'   => round(array_sum(array_map(function ($r) { return $r['corrective_hours']; }, $rows)), 2),
            'warnings'     => $warnings,                // MP-0 — overbooked machine-days
            'jobs'         => $week_jobs,               // MP-2 — frozen with the snapshot
            'jobs_ready'   => $this->jobs_ready(),
        );
    }

    /**
     * The plain average of the members' own availability percentages — the
     * historical workbook's group rule (BR-F5), kept only as a labelled
     * reference figure so the workshop can compare with the Excel it is used
     * to (D30).  It is never the primary KPI.
     *
     * Recomputed from each member's own hours rather than cached, so this
     * column can never disagree with the primary one's inputs.
     *
     * @param  array $members kpi_rollup() input rows
     * @return float|null  null when there is nothing to average
     */
    public function unweighted_mean(array $members) {
        $sum = 0.0;
        $n   = 0;
        foreach ($members as $m) {
            $a = kpi_availability($m['required_time_hours'], $m['downtime_hours']);
            if ($a === null) continue;               // no required time: not a member of the mean
            $sum += $a;
            $n++;
        }
        return $n > 0 ? round($sum / $n, 6) : null;
    }

    /**
     * The fleet the plan covers — the SAME machines, in the SAME order, as the
     * weekly report (MP-0 / G-MP14, R-MP10).
     *
     * It used to be every non-retired machine sorted by type name, while the
     * actual side of plan-vs-actual is the reportable fleet (`equip_is_reportable()`:
     * not retired and `include_in_weekly_report` not switched off) in sheet
     * order.  Two populations make two unit counts and two fleet figures, and
     * the comparison stops meaning anything.  The order is the weekly report's
     * own: `report_groups.sort_order`, then `report_sort`, then registration.
     * Every optional column is guarded, so a database without the W36 schema
     * still plans its whole non-retired fleet.
     */
    protected function _machines() {
        $has_group  = $this->db->field_exists('report_group', 'equipment');
        $has_flag   = $this->db->field_exists('include_in_weekly_report', 'equipment');
        $has_sort   = $this->db->field_exists('report_sort', 'equipment');
        $rows = $this->db
            ->select('e.id, e.registration_no, e.location, e.status, et.name AS equip_type'
                     . ($has_group ? ', e.report_group' : ', NULL AS report_group')
                     . ($has_flag  ? ', e.include_in_weekly_report' : '')
                     . ($has_sort  ? ', e.report_sort' : ''))
            ->from('equipment e')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->where('e.status !=', 'Retired')
            ->order_by('et.name, e.registration_no')
            ->get()->result();

        $out = array();
        foreach ($rows as $r) {
            if (function_exists('equip_is_reportable') && !equip_is_reportable($r)) continue;
            $out[] = $r;
        }

        $order = $this->_group_order();
        if ($order || $has_sort) {
            // usort is not stable before PHP 8, so the original position breaks ties
            $pos = array();
            foreach ($out as $i => $r) $pos[spl_object_hash($r)] = $i;
            usort($out, function ($a, $b) use ($order, $pos) {
                $ga = isset($a->report_group) ? trim((string)$a->report_group) : '';
                $gb = isset($b->report_group) ? trim((string)$b->report_group) : '';
                $oa = ($ga !== '' && isset($order[$ga])) ? $order[$ga] : 999999;
                $ob = ($gb !== '' && isset($order[$gb])) ? $order[$gb] : 999999;
                if ($oa !== $ob) return $oa < $ob ? -1 : 1;
                $sa = isset($a->report_sort) ? (int)$a->report_sort : 0;
                $sb = isset($b->report_sort) ? (int)$b->report_sort : 0;
                if ($sa !== $sb) return $sa < $sb ? -1 : 1;
                $c = strcmp((string)$a->registration_no, (string)$b->registration_no);
                if ($c !== 0) return $c;
                return $pos[spl_object_hash($a)] - $pos[spl_object_hash($b)];
            });
        }
        return $out;
    }

    /** report_groups code => sort position, or an empty array without the W36 schema. */
    protected function _group_order() {
        if (!$this->db->table_exists('report_groups')) return array();
        $out = array();
        foreach ($this->db->order_by('sort_order', 'ASC')->get('report_groups')->result() as $i => $g) {
            $code = isset($g->code) ? trim((string)$g->code) : '';
            if ($code === '') continue;
            $out[$code] = isset($g->sort_order) ? (int)$g->sort_order : $i;
        }
        return $out;
    }

    /**
     * Every job that touches the week (MP-2), as plain arrays — the shape that
     * goes into the JSON snapshot unchanged.  Read-only: writing and syncing
     * belong to `Maintenance_plan_model`.
     *
     * @return array
     */
    public function week_jobs($week_start, $week_end) {
        if (!$this->jobs_ready()) return array();
        $rows = $this->db
            ->where('start_date <=', $week_end)
            ->where('end_date >=', $week_start)
            ->order_by('start_date', 'ASC')
            ->get(self::JOBS)->result();
        $out = array();
        foreach ($rows as $r) {
            if ((string)$r->start_date > $week_end || (string)$r->end_date < $week_start) continue;
            if (isset($r->status) && $r->status === 'Cancelled') continue;
            $out[] = array(
                'id'             => (int)$r->id,
                'equipment_id'   => (int)$r->equipment_id,
                'start_date'     => (string)$r->start_date,
                'end_date'       => (string)$r->end_date,
                'hours_per_day'  => (float)$r->hours_per_day,
                'job_type'       => (string)$r->job_type,
                'category'       => (string)$r->category,
                'title'          => (string)$r->title,
                'hold_code'      => isset($r->hold_code) ? $r->hold_code : null,
                'expected_return'=> isset($r->expected_return) ? $r->expected_return : null,
                'status'         => isset($r->status) ? (string)$r->status : 'Planned',
                'source'         => isset($r->source) ? (string)$r->source : 'Manual',
            );
        }
        usort($out, function ($a, $b) {
            $c = strcmp($a['start_date'], $b['start_date']);
            return $c !== 0 ? $c : $a['id'] - $b['id'];
        });
        return $out;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  D33 — PUBLISH / FREEZE
     * ──────────────────────────────────────────────────────────────── */

    public function snapshots_ready() {
        return $this->db && $this->db->table_exists(self::SNAPS);
    }

    /** The baseline snapshot of a week, or null when the week is still a draft. */
    public function baseline_snapshot($week_start) {
        if (!$this->snapshots_ready()) return null;
        return $this->db->where('week_start', $week_start)
                        ->where('is_baseline', 1)
                        ->get(self::SNAPS)->row();
    }

    /** Every revision of a week, oldest first. */
    public function snapshot_history($week_start) {
        if (!$this->snapshots_ready()) return array();
        return $this->db->where('week_start', $week_start)
                        ->order_by('revision', 'ASC')
                        ->get(self::SNAPS)->result();
    }

    /**
     * Publish the plan for a week.
     *
     * Revision 1 becomes the baseline and is what plan-vs-actual is always
     * measured against.  A later publish never overwrites it: it preserves the
     * original and records why a revision was raised (D33).
     *
     * @param  string $note required from revision 2 onward
     * @return array|false  the stored row, or false with 'error'
     */
    public function publish($week_start, $user_id = null, $note = null) {
        if (!$this->snapshots_ready()) return array('error' => 'the forecast tables are not set up on this database');

        $plan     = $this->planned_availability($week_start);
        $existing = $this->snapshot_history($week_start);
        $revision = count($existing) + 1;

        if ($revision > 1 && (trim((string)$note) === '')) {
            return array('error' => 'A revision needs a note explaining what changed.');
        }

        /*
         * MP-0 — an overbooked plan can still be published (the planner may
         * know the extra hours are a second crew), but never silently: the
         * acknowledgement is written into the snapshot row, the baseline
         * included, so the frozen plan says it was published knowing.
         */
        $warn = isset($plan['warnings']) ? count($plan['warnings']) : 0;
        if ($warn > 0 && trim((string)$note) === '') {
            return array('error' => sprintf('%d overbooked machine-day%s — add a note acknowledging %s to publish.',
                                            $warn, $warn === 1 ? '' : 's', $warn === 1 ? 'it' : 'them'),
                         'warnings' => $plan['warnings']);
        }

        $payload  = json_encode($plan);
        $checksum = hash('sha256', $payload);

        $row = array(
            'week_start'         => $week_start,
            'week_end'           => $plan['week_end'],
            'iso_label'          => $plan['iso_label'],
            'revision'           => $revision,
            'is_baseline'        => $revision === 1 ? 1 : 0,
            'status'             => 'Published',
            'payload'            => $payload,
            'payload_checksum'   => $checksum,
            'fleet_total'        => $plan['unit']['total'],
            'fleet_out_of_service' => $plan['unit']['out_of_service'],
            'fleet_available'    => $plan['unit']['available'],
            'fleet_availability' => $plan['fleet']['availability'],
            'revision_note'      => ($revision === 1 && $warn === 0) ? null
                                    : ($revision === 1
                                        ? sprintf('Published with %d overbooked machine-day%s: %s', $warn, $warn === 1 ? '' : 's', trim((string)$note))
                                        : $note),
            'published_by'       => $user_id,
            // written explicitly rather than left to the column's CURRENT_TIMESTAMP
            // default: D33 requires the freeze to record *when*, and the row is
            // handed back to the caller (and to plan_vs_actual) immediately —
            // before any re-read could pick the default up.
            'published_at'       => date('Y-m-d H:i:s'),
        );

        $this->db->insert(self::SNAPS, $row);
        $row['id'] = (int)$this->db->insert_id();
        return $row;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F10 — WHAT A PM SERVICE CONSUMES
     |
     |  `spares_requirement()` reads these rows; without a way to write them
     |  the forecast screen can only ever report that nothing is configured.
     |  The workshop knows what a 250-hour service takes — this is where it
     |  says so, once, and the forecast then reads it every week.
     * ──────────────────────────────────────────────────────────────── */

    /**
     * The parts a PM schedule consumes, with the material's own details.
     *
     * @param  int $pm_schedule_id
     * @return array  one row per requirement
     */
    public function part_requirements($pm_schedule_id) {
        $pm_schedule_id = (int)$pm_schedule_id;
        if (!$pm_schedule_id) return array();
        if (!$this->db->table_exists(self::PARTS)) return array();

        $rows = $this->db->select('id, pm_schedule_id, material_id, qty, is_critical, note')
                         ->from(self::PARTS)
                         ->where('pm_schedule_id', $pm_schedule_id)
                         ->get()->result();
        if (!$rows) return array();

        /*
         * Two reads joined here rather than one SQL join.
         *
         * A LEFT JOIN would be tidier and is what this did first, but the test
         * harness's join is a no-op — only the `from()` table's rows come back
         * — so every material column arrived NULL and the rows looked empty.
         * `spares_requirement()` already reads this way for the same reason;
         * the two must agree or one of them is untestable.
         */
        $ids = array();
        foreach ((array)$rows as $r) $ids[] = (int)$r->material_id;

        $mats = array();
        $mrows = $this->db->select('id, item_code, description, unit, final_qty')
                          ->from('materials')
                          ->where_in('id', $ids)
                          ->get()->result();
        foreach ((array)$mrows as $m) $mats[(int)$m->id] = $m;

        $out = array();
        foreach ((array)$rows as $r) {
            $mid = (int)$r->material_id;
            $mat = isset($mats[$mid]) ? $mats[$mid] : null;
            $out[] = array(
                'id'             => (int)$r->id,
                'pm_schedule_id' => (int)$r->pm_schedule_id,
                'material_id'    => $mid,
                'item_code'      => $mat ? $mat->item_code : null,
                'description'    => $mat ? $mat->description : null,
                'unit'           => $mat ? $mat->unit : null,
                'on_hand'        => $mat ? (float)$mat->final_qty : 0.0,
                'qty'            => (float)$r->qty,
                'is_critical'    => (int)$r->is_critical === 1,
                'note'           => isset($r->note) ? $r->note : null,
            );
        }

        // ordered by the part's own code, as the join did
        usort($out, function ($a, $b) {
            return strcmp((string)$a['item_code'], (string)$b['item_code']);
        });
        return $out;
    }

    /**
     * Add or change one requirement.
     *
     * `qty` is consumed per ONE occurrence of the schedule — a 250-hour service
     * taking four filters is 4, not 4 × however many times it falls due.  The
     * forecast multiplies by the occurrences in the window, so entering the
     * total here would multiply twice.
     *
     * The UNIQUE (pm_schedule_id, material_id) key means a second entry for the
     * same part replaces the first rather than doubling it — a re-typed line
     * must not quietly double the requirement.
     *
     * @return array {ok:bool, error?:string, id?:int, action:'inserted'|'updated'}
     */
    public function save_part_requirement($pm_schedule_id, $material_id, $qty, $is_critical = false, $note = null) {
        $pm_schedule_id = (int)$pm_schedule_id;
        $material_id    = (int)$material_id;
        $qty            = (float)str_replace(',', '.', (string)$qty);

        if (!$this->db->table_exists(self::PARTS)) {
            return array('ok' => false, 'error' => 'The forecast tables are not set up on this database.');
        }
        if (!$pm_schedule_id || !$material_id) {
            return array('ok' => false, 'error' => 'A schedule and a part are both required.');
        }
        if (!is_finite($qty) || $qty <= 0) {
            return array('ok' => false, 'error' => 'Quantity must be a number greater than zero.');
        }
        if ($qty > 100000) {
            return array('ok' => false, 'error' => 'Quantity is implausibly large.');
        }

        $data = array(
            'pm_schedule_id' => $pm_schedule_id,
            'material_id'    => $material_id,
            'qty'            => round($qty, 3),
            'is_critical'    => $is_critical ? 1 : 0,
            'note'           => ($note !== null && trim((string)$note) !== '') ? mb_substr(trim((string)$note), 0, 255) : null,
        );

        $existing = $this->db->select('id')->where('pm_schedule_id', $pm_schedule_id)
                             ->where('material_id', $material_id)
                             ->get(self::PARTS)->row();

        if ($existing) {
            $this->db->update(self::PARTS, $data, array('id' => (int)$existing->id));
            return array('ok' => true, 'id' => (int)$existing->id, 'action' => 'updated');
        }

        $this->db->insert(self::PARTS, $data);
        return array('ok' => true, 'id' => (int)$this->db->insert_id(), 'action' => 'inserted');
    }

    /**
     * Remove one requirement. Scoped to the schedule, so a stale id cannot
     * delete another schedule's line.
     *
     * @return bool  whether a row was actually removed
     */
    public function delete_part_requirement($id, $pm_schedule_id) {
        $id             = (int)$id;
        $pm_schedule_id = (int)$pm_schedule_id;
        if (!$id || !$pm_schedule_id) return false;
        if (!$this->db->table_exists(self::PARTS)) return false;

        $this->db->where('id', $id)->where('pm_schedule_id', $pm_schedule_id);
        $this->db->delete(self::PARTS);
        return $this->db->affected_rows() > 0;
    }

    /** How many PM schedules in a window still have no parts list (F10's gap). */
    public function schedules_without_requirements($week_start, $weeks = 4) {
        $from = $week_start;
        $to   = date('Y-m-d', strtotime($week_start . ' +' . ((max(1, (int)$weeks) * 7) - 1) . ' days'));
        if (!$this->db->table_exists(self::PARTS)) return 0;

        $due = $this->db->select('id')
                        ->from('pm_schedules')
                        ->where('status', 'Active')
                        ->where('next_due_date >=', $from)
                        ->where('next_due_date <=', $to)
                        ->get()->result();

        if (!$due) return 0;

        $ids = array();
        foreach ((array)$due as $r) $ids[] = (int)$r->id;

        $with = $this->db->select('pm_schedule_id')->distinct()
                         ->from(self::PARTS)
                         ->where_in('pm_schedule_id', $ids)
                         ->get()->result();

        $have = array();
        foreach ((array)$with as $r) $have[(int)$r->pm_schedule_id] = true;

        $missing = 0;
        foreach ($ids as $id) if (!isset($have[$id])) $missing++;
        return $missing;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F10 — SPARE PARTS REQUIRED BY THE PM PLAN
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Turn the PMs falling due in a window into a parts bill, and compare it
     * with what the store holds and what is already on order:
     *
     *     required − on hand − on order = shortage
     *
     * This is the half of the workbook's forecast it never had: 675 hours of
     * planned PM across 44 machines is a parts requirement nobody had written
     * down.  It generalises the pattern `Lubricant_model` already proves
     * for lubricants.
     *
     * @param  string $week_start Monday of the first week
     * @param  int    $weeks      how many weeks ahead to look
     * @return array
     */
    public function spares_requirement($week_start, $weeks = 4) {
        $from = $week_start;
        $to   = date('Y-m-d', strtotime($week_start . ' +' . ((max(1, (int)$weeks) * 7) - 1) . ' days'));

        $out = array(
            'from' => $from, 'to' => $to, 'weeks' => (int)$weeks,
            'rows' => array(), 'ready' => false, 'schedules' => 0, 'no_requirements' => 0,
            // named, not just counted: the screen links each one to its parts
            // entry screen, and a count you cannot act on is only half a warning
            'no_requirements_list' => array(),
        );

        if (!$this->db->table_exists('pm_part_requirements')) return $out;

        $has_std = $this->db->field_exists('standard_hours', 'pm_schedules');

        $pm = $this->db
            ->select('id, equipment_id, schedule_name, next_due_date'
                     . ($has_std ? ', standard_hours' : ''))
            ->from('pm_schedules')
            ->where('status', 'Active')
            ->where('next_due_date >=', $from)
            ->where('next_due_date <=', $to)
            ->get()->result();

        $out['schedules'] = count($pm);
        if (!$pm) return $out;

        // fleet numbers for the schedules' machines, looked up rather than
        // joined — see the note on the requirements read below
        $eq_ids = array();
        foreach ($pm as $p) $eq_ids[(int)$p->equipment_id] = true;
        $reg = array();
        if ($eq_ids) {
            $erows = $this->db->select('id, registration_no')->from('equipment')
                              ->where_in('id', array_keys($eq_ids))->get()->result();
            foreach ($erows as $e) $reg[(int)$e->id] = $e->registration_no;
        }

        // requirements per schedule, and the material details behind them, in
        // two plain reads joined here.  Joining in PHP rather than in SQL keeps
        // the rule testable without a SQL engine (the project's harness has
        // none) — the same choice F9 makes for the loss rate.
        $ids  = array();
        foreach ($pm as $p) $ids[] = (int)$p->id;
        $reqs = $this->db
            ->select('pm_schedule_id, material_id, qty, is_critical')
            ->from('pm_part_requirements')
            ->where_in('pm_schedule_id', $ids)
            ->get()->result();

        $mids = array();
        foreach ($reqs as $r) $mids[(int)$r->material_id] = true;
        $materials = array();
        if ($mids) {
            $mrows = $this->db
                ->select('id, item_code, description, unit, final_qty')
                ->from('materials')
                ->where_in('id', array_keys($mids))
                ->get()->result();
            foreach ($mrows as $mm) $materials[(int)$mm->id] = $mm;
        }

        $by_schedule = array();
        foreach ($reqs as $r) $by_schedule[(int)$r->pm_schedule_id][] = $r;

        $on_order = $this->_on_order_by_material();

        // required qty per material, remembering which machines and dates need it
        $need = array();
        foreach ($pm as $p) {
            $sid = (int)$p->id;
            if (!isset($by_schedule[$sid])) {
                $out['no_requirements']++;
                $out['no_requirements_list'][] = array(
                    'pm_schedule_id' => $sid,
                    'schedule_name'  => $p->schedule_name,
                    'registration_no'=> isset($reg[(int)$p->equipment_id])
                                            ? $reg[(int)$p->equipment_id]
                                            : ('#' . $p->equipment_id),
                    'next_due_date'  => $p->next_due_date,
                    'has_hours'      => $has_std && $p->standard_hours !== null
                                            && (float)$p->standard_hours > 0,
                );
                continue;
            }
            foreach ($by_schedule[$sid] as $r) {
                $mid = (int)$r->material_id;
                $mat = isset($materials[$mid]) ? $materials[$mid] : null;
                if (!isset($need[$mid])) {
                    $need[$mid] = array(
                        'material_id'  => $mid,
                        'item_code'    => $mat ? $mat->item_code : null,
                        'description'  => $mat ? $mat->description : null,
                        'unit'         => $mat ? $mat->unit : null,
                        'on_hand'      => ($mat && $mat->final_qty !== null) ? (float)$mat->final_qty : 0.0,
                        'required'     => 0.0,
                        'is_critical'  => (int)$r->is_critical === 1,
                        'needed_by'    => array(),
                        'earliest'     => null,
                    );
                }
                $need[$mid]['required'] += (float)$r->qty;
                if ((int)$r->is_critical === 1) $need[$mid]['is_critical'] = true;
                $who = isset($reg[(int)$p->equipment_id]) ? $reg[(int)$p->equipment_id] : ('#' . $p->equipment_id);
                $need[$mid]['needed_by'][] = $who . ' · ' . date('d M', strtotime($p->next_due_date));
                if ($need[$mid]['earliest'] === null || $p->next_due_date < $need[$mid]['earliest']) {
                    $need[$mid]['earliest'] = $p->next_due_date;
                }
            }
        }

        foreach ($need as $mid => $n) {
            $ordered   = isset($on_order[$mid]) ? (float)$on_order[$mid] : 0.0;
            $shortage  = round($n['required'] - $n['on_hand'] - $ordered, 3);
            $n['on_order'] = $ordered;
            $n['shortage'] = $shortage > 0 ? $shortage : 0.0;
            $n['covered']  = $shortage <= 0;
            $n['needed_by'] = array_values(array_unique($n['needed_by']));
            $out['rows'][]  = $n;
        }

        // shortages first, critical parts above the rest
        usort($out['rows'], function ($a, $b) {
            if ($a['is_critical'] !== $b['is_critical']) return $b['is_critical'] ? 1 : -1;
            if ($a['shortage'] !== $b['shortage'])       return $b['shortage'] <=> $a['shortage'];
            return strcmp((string)$a['item_code'], (string)$b['item_code']);
        });

        $out['ready'] = true;
        $out['shortage_count'] = count(array_filter($out['rows'], function ($r) { return $r['shortage'] > 0; }));
        return $out;
    }

    /**
     * Outstanding ordered quantity per material, from `Spareparts_model`.
     *
     * Resolved through the injectable `$spareparts` property rather than
     * `$this->load->model()`, so the spares forecast is testable without the
     * framework — and so a caller can hand it a stub.
     *
     * @return array material_id => outstanding qty
     */
    protected function _on_order_by_material() {
        if ($this->spareparts === null) {
            if (!$this->load) return array();
            $this->load->model('Spareparts_model');
            $ci = get_instance();
            $this->spareparts = isset($ci->Spareparts_model) ? $ci->Spareparts_model : null;
        }
        if (!$this->spareparts || !method_exists($this->spareparts, 'get_on_order_by_material')) {
            return array();
        }
        return (array)$this->spareparts->get_on_order_by_material();
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F9 — RELIABILITY-BASED UNPLANNED ALLOWANCE
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Replace the workbook's flat 1 h/day on every machine with a per-machine
     * figure derived from its own failure history (rule R4).
     *
     *     allowance/day = (downtime over the lookback) / (days in the lookback)
     *
     * computed with the same `kpi_helper` the actuals use, so the number is the
     * machine's measured loss rate and not a guess.  A machine with no history
     * falls back to the flat setting, and says so.
     *
     * @return array equipment_id => ['hours'=>float,'source'=>string,'events'=>int]
     */
    public function derived_unplanned_allowances($week_start) {
        $weeks    = max(1, (int)$this->_setting('unplanned_allowance_lookback_weeks', 12));
        $flat     = (float)$this->_setting('unplanned_allowance_default_hours', 1);
        $mode     = $this->_setting('unplanned_allowance_mode', 'flat');
        $to       = date('Y-m-d', strtotime($week_start . ' -1 day'));
        $from     = date('Y-m-d', strtotime($to . ' -' . ($weeks * 7 - 1) . ' days'));
        $days     = max(1, (int)round((strtotime($to) - strtotime($from)) / 86400) + 1);

        $machines = $this->_machines();
        $ids      = array();
        foreach ($machines as $m) $ids[] = (int)$m->id;
        if (!$ids) return array();

        // Corrective downtime in the lookback window.  Aggregated here rather
        // than by SUM()/GROUP BY: the figure is a small per-machine total, and
        // doing it in PHP keeps the rule testable without a SQL engine (the
        // project's own harness has none) and independent of how a particular
        // server treats COALESCE inside an aggregate.
        $rows = $this->db
            ->select('wo.equipment_id, wo.id, wo.downtime_hours')
            ->from('work_orders wo')
            ->where_in('wo.equipment_id', $ids)
            ->where('wo.type', 'Corrective Maintenance')
            ->where('wo.start_datetime >=', $from . ' 00:00:00')
            ->where('wo.start_datetime <=', $to . ' 23:59:59')
            ->get()->result();

        $seen = array();
        foreach ($rows as $r) {
            $eid = (int)$r->equipment_id;
            if (!isset($seen[$eid])) $seen[$eid] = array('downtime' => 0.0, 'events' => 0);
            $seen[$eid]['downtime'] += (float)$r->downtime_hours;
            $seen[$eid]['events']++;
        }

        $out = array();
        foreach ($machines as $m) {
            $eid = (int)$m->id;
            if ($mode !== 'derived' || !isset($seen[$eid])) {
                $out[$eid] = array('hours' => $flat, 'source' => 'flat', 'events' => 0,
                                   'note' => $mode !== 'derived'
                                       ? 'flat setting (unplanned_allowance_mode = flat)'
                                       : 'no corrective history in the lookback window');
                continue;
            }
            $per_day = round($seen[$eid]['downtime'] / $days, 2);
            // a floor, so a machine that happened not to break is not forecast
            // as incapable of breaking; 0.5 h/day is a working assumption, not
            // a claim about the machine
            $per_day = max(0.5, min(24.0, $per_day));
            $out[$eid] = array('hours' => $per_day, 'source' => 'derived',
                               'events' => $seen[$eid]['events'],
                               'note'   => $weeks . '-week measured loss rate, floored at 0.5 h/day');
        }
        return $out;
    }

    /**
     * Write the unplanned allowance for a week, from the flat setting or from
     * each machine's own history depending on `unplanned_allowance_mode`.
     *
     * Only ever writes rows whose source is Default or Derived — a planner's
     * manual figure is left alone, for the same reason `generate_from_pm()`
     * leaves one alone.
     *
     * @return array{placed:int,left_alone:int}
     */
    public function generate_unplanned_allowance($week_start, $user_id = null) {
        $days     = $this->week_dates($week_start);
        $allows   = $this->derived_unplanned_allowances($week_start);
        $mode     = $this->_setting('unplanned_allowance_mode', 'flat');
        $held     = $this->held_days($week_start);
        $placed = $left = $skipped_held = $removed = 0;

        foreach ($allows as $eid => $a) {
            $source = $mode === 'derived' && $a['source'] === 'derived' ? self::SRC_DERIVED : self::SRC_DEFAULT;
            foreach ($days as $d) {
                $existing = $this->_ledger_cell($eid, $d, self::CAT_UNPLANNED);
                if ($existing && in_array($existing->source, self::$PROTECTED_SOURCES, true)) { $left++; continue; }

                // MP-0 — no breakdown allowance on a day the machine is already held
                if (isset($held[$eid][$d])) {
                    if ($this->_drop_generated($eid, $d, self::CAT_UNPLANNED)) $removed++;
                    $skipped_held++;
                    continue;
                }

                $this->set_planned($eid, $d, self::CAT_UNPLANNED, $a['hours'], $source, $a['note'], $user_id);
                $placed++;
            }
        }
        return array('placed' => $placed, 'left_alone' => $left,
                     'skipped_held' => $skipped_held, 'removed' => $removed);
    }

    /**
     * Write the standing allowance for a week, per equipment type (D31).
     *
     * @return array{placed:int,left_alone:int}
     */
    public function generate_standing_allowance($week_start, $user_id = null) {
        $days   = $this->week_dates($week_start);
        $held   = $this->held_days($week_start);
        $placed = $left = $skipped_held = $removed = $standby = 0;

        foreach ($this->_machines() as $m) {
            $eid   = (int)$m->id;
            $hours = $this->standing_allowance_for($m->equip_type);

            /*
             * MP-0 — a Standby machine is reserve capacity: it stands because
             * it is not needed, which is what it is for, not a loss to forecast.
             * The workbook's six standby units (W = 0) agree.  Any allowance a
             * previous run wrote is taken back.
             */
            $is_standby = isset($m->status) && strcasecmp((string)$m->status, 'Standby') === 0;
            if ($is_standby || $hours <= 0) {
                if ($is_standby) {
                    $standby++;
                    foreach ($days as $d) {
                        if ($this->_drop_generated($eid, $d, self::CAT_STANDING)) $removed++;
                    }
                }
                continue;
            }
            foreach ($days as $d) {
                $existing = $this->_ledger_cell($eid, $d, self::CAT_STANDING);
                if ($existing && in_array($existing->source, self::$PROTECTED_SOURCES, true)) { $left++; continue; }

                if (isset($held[$eid][$d])) {
                    if ($this->_drop_generated($eid, $d, self::CAT_STANDING)) $removed++;
                    $skipped_held++;
                    continue;
                }

                $this->set_planned($eid, $d, self::CAT_STANDING, $hours, self::SRC_DEFAULT,
                                   'standing allowance for ' . $m->equip_type, $user_id);
                $placed++;
            }
        }
        return array('placed' => $placed, 'left_alone' => $left, 'skipped_held' => $skipped_held,
                     'removed' => $removed, 'standby_machines' => $standby);
    }

    /* ─────────────────────────────────────────────────────────────────
     |  F6 — PLAN vs ACTUAL
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Compare the FROZEN baseline plan of a week with the actual the KPI
     * engine computes for the same week.
     *
     * Both sides are `kpi_availability()` on required time, so the variance is
     * a real difference and not a formula artefact like the workbook's
     * typed constant.
     *
     * @param  array $actual_by_group group code => kpi_rollup() output
     * @return array
     */
    public function plan_vs_actual($week_start, array $actual_by_group) {
        $snap = $this->baseline_snapshot($week_start);
        if (!$snap) {
            return array('published' => false, 'week_start' => $week_start, 'groups' => array());
        }

        $plan = json_decode($snap->payload, true);
        if (!is_array($plan) || !isset($plan['groups'])) {
            return array('published' => false, 'week_start' => $week_start,
                         'error' => 'the snapshot payload could not be read');
        }

        $planned_by_group = array();
        foreach ($plan['groups'] as $g) $planned_by_group[$g['group']] = $g;

        /*
         * Join the actuals on a trimmed key.
         *
         * The plan side trims `equipment.report_group` when it groups (the
         * historical workbook files the same fleet under both 'DOZERS' and
         * 'DOZERS ', and an untrimmed key splits one group into two).  The
         * weekly-report side does not trim, so a group whose name carries a
         * stray space would match on one side and not the other — the group
         * would then report a planned figure with no actual and no variance,
         * which reads as "no data yet" rather than as a broken join.
         *
         * Normalising here costs nothing and makes the two sides agree whatever
         * the source rows look like.
         */
        $actual_by_trimmed = array();
        foreach ($actual_by_group as $code => $rollup) {
            $actual_by_trimmed[trim((string)$code)] = $rollup;
        }

        $rows = array();
        foreach ($planned_by_group as $code => $p) {
            $key = trim((string)$code);
            $a   = isset($actual_by_trimmed[$key]) ? $actual_by_trimmed[$key] : null;
            $pa = isset($p['availability']) ? $p['availability'] : null;
            $aa = ($a && isset($a['availability'])) ? $a['availability'] : null;

            $rows[] = array(
                'group'             => $code,
                'machines'          => isset($p['machines']) ? $p['machines'] : null,
                'planned_availability' => $pa,
                'actual_availability'  => $aa,
                'variance_pp'       => ($pa !== null && $aa !== null) ? round(($aa - $pa) * 100, 2) : null,
                'planned_pm_hours'  => isset($p['pm_hours']) ? $p['pm_hours'] : null,
                'actual_pm_hours'   => ($a && isset($a['preventive_hours'])) ? $a['preventive_hours'] : null,
                'pm_variance_hours' => (isset($p['pm_hours']) && $a && isset($a['preventive_hours']))
                                        ? round($a['preventive_hours'] - $p['pm_hours'], 2) : null,
                'planned_downtime_hours' => isset($p['downtime_hours']) ? $p['downtime_hours'] : null,
                'actual_downtime_hours'  => ($a && isset($a['downtime_hours'])) ? $a['downtime_hours'] : null,
                'unplanned_variance_hours' => (isset($p['downtime_hours']) && $a && isset($a['downtime_hours']))
                                        ? round($a['downtime_hours'] - $p['downtime_hours'], 2) : null,
            );
        }

        // worst variance first — the groups that need the conversation
        usort($rows, function ($a, $b) {
            $x = $a['variance_pp'] === null ? 0 : $a['variance_pp'];
            $y = $b['variance_pp'] === null ? 0 : $b['variance_pp'];
            return $x <=> $y;
        });

        $plan_fleet  = isset($plan['fleet']['availability']) ? $plan['fleet']['availability'] : null;
        $actual_fleet = null;
        if (isset($actual_by_group['__fleet__']['availability'])) {
            $actual_fleet = $actual_by_group['__fleet__']['availability'];
        }

        return array(
            'published'    => true,
            'week_start'   => $week_start,
            'iso_label'    => $snap->iso_label,
            'revision'     => (int)$snap->revision,
            'published_at' => $snap->published_at,
            'published_by' => $snap->published_by,
            'groups'       => $rows,
            'fleet'        => array(
                'planned'    => $plan_fleet,
                'actual'     => $actual_fleet,
                'variance_pp'=> ($plan_fleet !== null && $actual_fleet !== null)
                                ? round(($actual_fleet - $plan_fleet) * 100, 2) : null,
            ),
            'unit'         => array(
                'total'          => (int)$snap->fleet_total,
                'out_of_service' => (int)$snap->fleet_out_of_service,
                'available'      => (int)$snap->fleet_available,
            ),
        );
    }
}
