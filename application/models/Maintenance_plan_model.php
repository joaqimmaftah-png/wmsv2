<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MAINTENANCE PLAN JOBS — MP-2 (R-MP1).
 *
 * The Excel maintenance plan holds JOBS — "Inspection 2 h", "Service 500 Hrs
 * 3 h", "Waiting for parts 24 h" — and several of them on one machine on one
 * day.  The planned-downtime ledger holds one row per machine · day ·
 * category, so writing jobs straight into it keeps only the last one (probe
 * P4: an inspection and a service on the same day became "PM 3 h, Service
 * 500Hrs").  Jobs therefore live in `maintenance_plan_jobs`, one row each, and
 * this model DERIVES the ledger from them:
 *
 *     ledger hours (machine, day, category) = Σ jobs.hours_per_day,  source = FromPlan
 *
 * Rules:
 *
 *   • a Manual ledger row always wins (D31) — the sync leaves it alone and
 *     reports it;
 *   • a FromPlan row whose jobs are gone is deleted on the next sync;
 *   • generated rows (Default, Derived, FromPM) are replaced by the jobs for
 *     that category on that day — the planner's explicit job is more specific
 *     than a generator's allowance;
 *   • a week that is published is frozen (D33): no job touching it can be
 *     added, changed or removed — raise a revision instead.
 *
 * Availability is still computed only by `Forecast_model` → `kpi_availability()`
 * (D29).  Nothing here does availability arithmetic.
 */
class Maintenance_plan_model extends CI_Model {

    const TABLE = 'maintenance_plan_jobs';

    public static $JOB_TYPES  = array('Inspection', 'PMService', 'Repair', 'Rebuild', 'Standby', 'Other');
    public static $STATUSES   = array('Planned', 'Issued', 'Done', 'Missed', 'Cancelled');
    /** D17 hold-code vocabulary — why a machine waits. */
    public static $HOLD_CODES = array('WSP', 'WQT', 'WCF', 'UD', 'UR');

    /** The ledger category a job type feeds unless the planner says otherwise. */
    public static $DEFAULT_CATEGORY = array(
        'Inspection' => 'PM',
        'PMService'  => 'PM',
        'Repair'     => 'Corrective',
        'Rebuild'    => 'OutOfService',
        'Standby'    => 'Standing',
        'Other'      => 'Unplanned',
    );

    /** Short code shown in a planned-grid cell. */
    public static $CODES = array(
        'Inspection' => 'INSP', 'PMService' => 'PM', 'Repair' => 'REP',
        'Rebuild'    => 'RBLD', 'Standby'   => 'SBY', 'Other' => 'OTH',
    );

    /** Longest range one job may span — a guard against a typo'd year. */
    const MAX_SPAN_DAYS = 92;

    /** @var Forecast_model */
    public $forecast;

    public function __construct() {
        parent::__construct();
    }

    /** The Forecast_model this model writes the ledger through. */
    protected function fc() {
        if (!$this->forecast) {
            $this->load->model('Forecast_model');
            $this->forecast = $this->Forecast_model;
        }
        return $this->forecast;
    }

    public function tables_ready() {
        return $this->db && $this->db->table_exists(self::TABLE);
    }

    /* ─────────────────────────────────────────────────────────────────
     |  READ
     * ──────────────────────────────────────────────────────────────── */

    public function get($id) {
        if (!$this->tables_ready()) return null;
        return $this->db->where('id', (int)$id)->get(self::TABLE)->row();
    }

    /** Every job touching the week, as Forecast_model::week_jobs() returns them. */
    public function jobs_for_week($week_start) {
        $fc = $this->fc();
        $ws = $fc->week_start($week_start);
        return $fc->week_jobs($ws, $fc->week_end($ws));
    }

    /** Jobs of one machine that touch one date, oldest first. */
    public function jobs_on($equipment_id, $date) {
        $out = array();
        foreach ($this->jobs_for_week($date) as $j) {
            if ($j['equipment_id'] === (int)$equipment_id && $date >= $j['start_date'] && $date <= $j['end_date']) {
                $out[] = $j;
            }
        }
        return $out;
    }

    /** Monday of every ISO week the range [start, end] touches. */
    public function weeks_touched($start, $end) {
        $fc = $this->fc();
        $out = array();
        $ws = $fc->week_start($start);
        $we = $fc->week_start($end);
        if (!$ws || !$we) return $out;
        for ($w = $ws; $w <= $we; $w = date('Y-m-d', strtotime($w . ' +7 days'))) {
            $out[] = $w;
            if (count($out) > 60) break;
        }
        return $out;
    }

    /** Published weeks among those a range touches (D33 — they are frozen). */
    public function frozen_weeks($start, $end) {
        $fc = $this->fc();
        $out = array();
        foreach ($this->weeks_touched($start, $end) as $w) {
            if ($fc->baseline_snapshot($w)) $out[] = $fc->iso_label($w);
        }
        return $out;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  WRITE
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Validate a job as typed.  Returns the cleaned row and a list of errors;
     * a non-empty error list means nothing may be written.
     *
     * @return array{0:array,1:string[]}
     */
    public function validate(array $in) {
        $errors = array();
        $fc     = $this->fc();

        $type  = isset($in['job_type']) ? trim((string)$in['job_type']) : '';
        if (!in_array($type, self::$JOB_TYPES, true)) $errors[] = 'Choose a job type.';

        $cat = isset($in['category']) ? trim((string)$in['category']) : '';
        if ($cat === '' && isset(self::$DEFAULT_CATEGORY[$type])) $cat = self::$DEFAULT_CATEGORY[$type];
        if (!in_array($cat, $fc->writable_categories(), true)) $errors[] = 'Choose what the hours count as.';

        $eid = isset($in['equipment_id']) ? (int)$in['equipment_id'] : 0;
        if ($eid <= 0) {
            $errors[] = 'Choose a machine.';
        } elseif (!$this->db->where('id', $eid)->get('equipment')->row()) {
            $errors[] = 'That machine does not exist.';
        }

        $start = isset($in['start_date']) ? trim((string)$in['start_date']) : '';
        $end   = isset($in['end_date']) && trim((string)$in['end_date']) !== '' ? trim((string)$in['end_date']) : $start;
        $ok_dates = $this->_is_date($start) && $this->_is_date($end);
        if (!$ok_dates) {
            $errors[] = 'Give a valid start date (and end date for a multi-day job).';
        } elseif ($end < $start) {
            $errors[] = 'The end date is before the start date.';
        } elseif ((strtotime($end) - strtotime($start)) / 86400 + 1 > self::MAX_SPAN_DAYS) {
            $errors[] = 'One job can span at most ' . self::MAX_SPAN_DAYS . ' days — split longer work.';
        }

        $raw_hours = isset($in['hours_per_day']) ? trim((string)$in['hours_per_day']) : '';
        $hours = is_numeric($raw_hours) ? round((float)$raw_hours, 2) : null;
        if ($type === 'Standby') {
            $hours = 0.0;  // reserve capacity: a marker, never lost hours
        } elseif ($hours === null || $hours <= 0 || $hours > 24) {
            $errors[] = 'Hours per day must be more than 0 and at most 24.';
        }

        $title = isset($in['title']) ? trim((string)$in['title']) : '';
        if ($title === '') $errors[] = 'Describe the job.';
        if (strlen((string)($title)) > 200) $errors[] = 'The description is longer than 200 characters.';

        $hold = isset($in['hold_code']) ? trim((string)$in['hold_code']) : '';
        if ($hold !== '' && !in_array($hold, self::$HOLD_CODES, true)) $errors[] = 'Unknown hold code.';

        $eta = isset($in['expected_return']) ? trim((string)$in['expected_return']) : '';
        if ($eta !== '' && !$this->_is_date($eta)) $errors[] = 'The expected return is not a date.';

        $status = isset($in['status']) && trim((string)$in['status']) !== '' ? trim((string)$in['status']) : 'Planned';
        if (!in_array($status, self::$STATUSES, true)) $errors[] = 'Unknown status.';

        $source = isset($in['source']) && in_array($in['source'], ['Manual','FromPM','FromInspection','FromWorkOrder','FromBacklog']) 
                    ? $in['source'] : 'Manual';
        $backlog_id = isset($in['backlog_id']) && $in['backlog_id'] > 0 ? (int)$in['backlog_id'] : null;
        $pm_id = isset($in['pm_schedule_id']) && $in['pm_schedule_id'] > 0 ? (int)$in['pm_schedule_id'] : null;

        $row = array(
            'equipment_id'    => $eid,
            'start_date'      => $start,
            'end_date'        => $end,
            'hours_per_day'   => $hours === null ? 0.0 : $hours,
            'job_type'        => $type,
            'category'        => $cat,
            'title'           => $title,
            'hold_code'       => $hold === '' ? null : $hold,
            'expected_return' => $eta === '' ? null : $eta,
            'status'          => $status,
            'source'          => $source,
            'backlog_id'      => $backlog_id,
            'pm_schedule_id'  => $pm_id
        );
        return array($row, $errors);
    }

    /**
     * Create ($id null) or update a job, then re-sync every week it touches
     * (before and after an edit, so a moved job leaves nothing behind).
     *
     * @return array{id?:int,errors?:string[],synced?:array}
     */
    public function save_job(array $in, $user_id = null, $id = null) {
        if (!$this->tables_ready()) return array('errors' => array('The maintenance-plan tables are not set up on this database yet.'));

        list($row, $errors) = $this->validate($in);
        if ($errors) return array('errors' => $errors);

        $old = null;
        if ($id) {
            $old = $this->get($id);
            if (!$old) return array('errors' => array('That job no longer exists.'));
        }

        // D33 — neither the old nor the new range may touch a published week
        $frozen = $this->frozen_weeks($row['start_date'], $row['end_date']);
        if ($old) $frozen = array_values(array_unique(array_merge(
            $frozen, $this->frozen_weeks($old->start_date, $old->end_date))));
        if ($frozen) {
            return array('errors' => array(implode(', ', $frozen) . (count($frozen) === 1 ? ' is' : ' are')
                . ' published and frozen — raise a revision instead of changing its jobs.'));
        }

        if ($old) {
            $row['updated_by'] = $user_id;
            $row['updated_at'] = date('Y-m-d H:i:s');
            // source, backlog_id, pm_schedule_id shouldn't be overwritten blindly if we aren't providing them
            if (!isset($in['source']) && isset($old->source)) $row['source'] = $old->source;
            if (!isset($in['backlog_id']) && isset($old->backlog_id)) $row['backlog_id'] = $old->backlog_id;
            if (!isset($in['pm_schedule_id']) && isset($old->pm_schedule_id)) $row['pm_schedule_id'] = $old->pm_schedule_id;
            
            $this->db->where('id', (int)$id)->update(self::TABLE, $row);
            $jid = (int)$id;
        } else {
            if (!isset($row['source'])) $row['source'] = 'Manual';
            $row['created_by'] = $user_id;
            $this->db->insert(self::TABLE, $row);
            $jid = (int)$this->db->insert_id();
        }

        $weeks = $this->weeks_touched($row['start_date'], $row['end_date']);
        if ($old) $weeks = array_values(array_unique(array_merge(
            $weeks, $this->weeks_touched($old->start_date, $old->end_date))));
        $synced = array();
        foreach ($weeks as $w) $synced[$w] = $this->sync_week($w, $user_id);

        return array('id' => $jid, 'synced' => $synced);
    }

    /** Remove a job and take its hours out of the ledger. */
    public function delete_job($id, $user_id = null) {
        if (!$this->tables_ready()) return array('errors' => array('The maintenance-plan tables are not set up on this database yet.'));
        $old = $this->get($id);
        if (!$old) return array('errors' => array('That job no longer exists.'));
        $frozen = $this->frozen_weeks($old->start_date, $old->end_date);
        if ($frozen) {
            return array('errors' => array(implode(', ', $frozen) . (count($frozen) === 1 ? ' is' : ' are')
                . ' published and frozen — raise a revision instead of removing its jobs.'));
        }
        $this->db->where('id', (int)$id)->delete(self::TABLE);
        $synced = array();
        foreach ($this->weeks_touched($old->start_date, $old->end_date) as $w) {
            $synced[$w] = $this->sync_week($w, $user_id);
        }
        return array('deleted' => (int)$id, 'synced' => $synced);
    }

    /* ─────────────────────────────────────────────────────────────────
     |  SYNC — jobs → ledger
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Rewrite the week's FromPlan ledger rows from its jobs.
     *
     * Idempotent: the result depends only on the jobs and the Manual rows, so
     * running it twice changes nothing the second time.  A frozen week is
     * never touched.
     *
     * @return array{written:int,removed:int,manual_kept:int,manual_cells:array,overbooked:array,frozen?:bool}
     */
    public function sync_week($week_start, $user_id = null) {
        $fc  = $this->fc();
        $ws  = $fc->week_start($week_start);
        $out = array('written' => 0, 'removed' => 0, 'manual_kept' => 0,
                     'manual_cells' => array(), 'overbooked' => array());
        if (!$this->tables_ready() || !$fc->tables_ready()) return $out;
        if ($fc->baseline_snapshot($ws)) { $out['frozen'] = true; return $out; }

        $we   = $fc->week_end($ws);
        $days = $fc->week_dates($ws);
        $open = $fc->daily_open_hours();

        // Σ hours and titles per machine · day · category
        $sum = array();
        $day_total = array();
        foreach ($fc->week_jobs($ws, $we) as $j) {
            if ($j['hours_per_day'] <= 0) continue;
            foreach ($days as $d) {
                if ($d < $j['start_date'] || $d > $j['end_date']) continue;
                $k = $j['equipment_id'] . '|' . $d . '|' . $j['category'];
                if (!isset($sum[$k])) $sum[$k] = array('hours' => 0.0, 'titles' => array());
                $sum[$k]['hours'] += $j['hours_per_day'];
                $sum[$k]['titles'][] = $j['title'];
                $dk = $j['equipment_id'] . '|' . $d;
                $day_total[$dk] = (isset($day_total[$dk]) ? $day_total[$dk] : 0.0) + $j['hours_per_day'];
            }
        }

        // jobs alone booking more than the day: reported (the ledger clamps a row at 24 h)
        foreach ($day_total as $dk => $h) {
            if ($h > $open + 0.0001) {
                list($eid, $d) = explode('|', $dk);
                $out['overbooked'][] = array('equipment_id' => (int)$eid, 'date' => $d,
                                             'job_hours' => round($h, 2), 'open_hours' => $open);
            }
        }

        // the week's existing ledger, keyed the same way
        $existing = array();
        foreach ($this->db->where('plan_date >=', $ws)->where('plan_date <=', $we)
                          ->get(Forecast_model::TABLE)->result() as $r) {
            if ((string)$r->plan_date < $ws || (string)$r->plan_date > $we) continue;
            $existing[(int)$r->equipment_id . '|' . $r->plan_date . '|' . $r->category] = $r;
        }

        foreach ($sum as $k => $s) {
            list($eid, $d, $cat) = explode('|', $k);
            $row = isset($existing[$k]) ? $existing[$k] : null;
            if ($row && $row->source === Forecast_model::SRC_MANUAL) {
                // D31 — the planner's hand edit wins; say so rather than hide it
                $out['manual_kept']++;
                $out['manual_cells'][] = array('equipment_id' => (int)$eid, 'date' => $d, 'category' => $cat,
                                               'manual_hours' => (float)$row->hours, 'job_hours' => round($s['hours'], 2));
                continue;
            }
            $note = 'jobs: ' . implode(' + ', $s['titles']);
            if (strlen((string)($note)) > 255) $note = substr($note, 0, 252) . '...';
            $fc->set_planned((int)$eid, $d, $cat, $s['hours'], Forecast_model::SRC_FROM_PLAN, $note, $user_id);
            $out['written']++;
        }

        // FromPlan rows whose jobs are gone
        foreach ($existing as $k => $row) {
            if ($row->source !== Forecast_model::SRC_FROM_PLAN || isset($sum[$k])) continue;
            $this->db->where('id', $row->id)->delete(Forecast_model::TABLE);
            $out['removed']++;
        }

        return $out;
    }

    /* ─────────────────────────────────────────────────────────────────
     |  MP-3 — the weekly internal inspection round (R-MP2)
     |
     |  One round per reportable machine: an ordinary `pm_schedules` row with
     |  interval 7 Days, a `preferred_weekday` (1 = Mon … 7 = Sun) and the
     |  inspection duration. The weekday is what places it; the date-based PM
     |  generator leaves rounds alone (Forecast_model::is_weekly_round()).
     |  Each week the round becomes a `maintenance_plan_jobs` row
     |  (job_type Inspection, source FromInspection) whose hours reach the
     |  ledger through sync_week(), like any other job. A submitted
     |  inspection_records row for the machine inside the week marks it Done.
     * ──────────────────────────────────────────────────────────────── */

    const SCHEDULE_NAME     = 'Weekly inspection';
    const SOURCE_INSPECTION = 'FromInspection';

    /** Whether the plan tables and the weekday column all exist. */
    protected function rounds_ready() {
        return $this->tables_ready() && $this->db->table_exists('pm_schedules')
            && $this->db->field_exists('preferred_weekday', 'pm_schedules');
    }

    /** Active weekday-placed rounds (any machine; the caller checks reportability). */
    public function inspection_rounds() {
        if (!$this->rounds_ready()) return array();
        $fc  = $this->fc();
        $out = array();
        foreach ($this->db->where('status', 'Active')->get('pm_schedules')->result() as $ps) {
            if (!$fc->is_weekly_round($ps)) continue;
            $out[] = $ps;
        }
        return $out;
    }

    /** The plan screen's view of the rounds: how many machines have one, and who does not. */
    public function inspection_summary() {
        $fc  = $this->fc();
        $out = array('ready' => false, 'machines' => 0, 'rounds' => 0, 'missing' => array(),
                     'default_hours' => $fc->inspection_hours_for(null));
        if (!$this->rounds_ready()) return $out;
        $out['ready'] = true;
        $have = array();
        foreach ($this->inspection_rounds() as $r) { $have[(int)$r->equipment_id] = true; $out['rounds']++; }
        foreach ($fc->reportable_machines() as $m) {
            $out['machines']++;
            if (!isset($have[(int)$m->id])) $out['missing'][] = (string)$m->registration_no;
        }
        return $out;
    }

    /**
     * Create one weekly round per reportable machine that lacks one. Existing
     * rounds are never touched, so running it again creates nothing new. The
     * weekday of each new round is the one with the fewest rounds (ties go to
     * the earliest weekday), which levels the load across the week.
     *
     * @return array created, existing, by_day[1..7], error?
     */
    public function create_inspection_rounds($user_id = null) {
        $out = array('created' => 0, 'existing' => 0, 'by_day' => array_fill(1, 7, 0));
        if (!$this->rounds_ready()) {
            $out['error'] = 'The maintenance-plan tables are not set up on this database yet.';
            return $out;
        }
        $fc = $this->fc();
        $has_std = $this->db->field_exists('standard_hours', 'pm_schedules');

        $have = array();
        $load = array_fill(1, 7, 0);
        foreach ($this->inspection_rounds() as $r) {
            $have[(int)$r->equipment_id] = true;
            $load[(int)$r->preferred_weekday]++;
        }

        foreach ($fc->reportable_machines() as $m) {
            $eid = (int)$m->id;
            if (isset($have[$eid])) { $out['existing']++; continue; }

            $wd = 1;
            for ($d = 2; $d <= 7; $d++) if ($load[$d] < $load[$wd]) $wd = $d;

            $row = array(
                'equipment_id'      => $eid,
                'schedule_name'     => self::SCHEDULE_NAME,
                'description'       => 'Weekly internal inspection, placed on its weekday by the maintenance plan (MP-3).',
                'interval_type'     => 'Days',
                'interval_value'    => 7,
                'status'            => 'Active',
                'preferred_weekday' => $wd,
            );
            if ($has_std) $row['standard_hours'] = $fc->inspection_hours_for(isset($m->equip_type) ? $m->equip_type : null);
            $this->db->insert('pm_schedules', $row);

            $have[$eid] = true;
            $load[$wd]++;
            $out['created']++;
            $out['by_day'][$wd]++;
        }
        return $out;
    }

    /**
     * Place the week's inspections. One job per round whose machine is
     * reportable, not on Standby, and not planned out of service that day or
     * for the whole week. Each skip is reported by machine, never dropped
     * silently. Running it again places nothing new. A published (frozen) week
     * is refused.
     *
     * @return array placed, already, standby[], out_week[], out_days[], orphans[],
     *               frozen, sync, status, error?
     */
    public function generate_inspections($week_start, $user_id = null) {
        $fc  = $this->fc();
        $ws  = $fc->week_start($week_start);
        $we  = $fc->week_end($ws);
        $out = array('placed' => 0, 'already' => 0, 'standby' => array(), 'out_week' => array(),
                     'out_days' => array(), 'orphans' => array(), 'frozen' => false);
        if (!$this->rounds_ready() || !$fc->tables_ready()) {
            $out['error'] = 'The maintenance-plan tables are not set up on this database yet.';
            return $out;
        }
        if ($fc->baseline_snapshot($ws)) { $out['frozen'] = true; return $out; }

        $days        = $fc->week_dates($ws);
        $daily_open  = $fc->daily_open_hours();
        $weekly_open = $fc->weekly_open_hours();
        $planned     = $fc->planned_rows($ws, $we);

        $machines = array();
        foreach ($fc->reportable_machines() as $m) $machines[(int)$m->id] = $m;

        // jobs already placed for this week, keyed round|date
        $placed = array();
        foreach ($this->db->where('source', self::SOURCE_INSPECTION)->get(self::TABLE)->result() as $j) {
            if ((string)$j->start_date < $ws || (string)$j->start_date > $we) continue;
            $placed[(int)$j->pm_schedule_id . '|' . (string)$j->start_date] = true;
        }

        foreach ($this->inspection_rounds() as $r) {
            $eid = (int)$r->equipment_id;
            if (!isset($machines[$eid])) { $out['orphans'][] = (string)$r->schedule_name . ' #' . (int)$r->id; continue; }
            $m   = $machines[$eid];
            $reg = (string)$m->registration_no;

            if (strcasecmp((string)$m->status, 'Standby') === 0) { $out['standby'][] = $reg; continue; }

            $out_week = 0.0;
            foreach ($days as $d) {
                if (isset($planned[$eid][$d][Forecast_model::CAT_OUT])) $out_week += (float)$planned[$eid][$d][Forecast_model::CAT_OUT]['hours'];
            }
            if ($out_week >= $weekly_open * 0.999) { $out['out_week'][] = $reg; continue; }

            $date = date('Y-m-d', strtotime($ws . ' +' . ((int)$r->preferred_weekday - 1) . ' days'));
            if (isset($placed[(int)$r->id . '|' . $date])) { $out['already']++; continue; }

            $out_day = isset($planned[$eid][$date][Forecast_model::CAT_OUT])
                     ? (float)$planned[$eid][$date][Forecast_model::CAT_OUT]['hours'] : 0.0;
            if ($out_day >= $daily_open * 0.999) { $out['out_days'][] = array('registration_no' => $reg, 'date' => $date); continue; }

            $this->db->insert(self::TABLE, array(
                'equipment_id'  => $eid,
                'start_date'    => $date,
                'end_date'      => $date,
                'hours_per_day' => $fc->inspection_hours_for(isset($m->equip_type) ? $m->equip_type : null),
                'job_type'      => 'Inspection',
                'category'      => 'PM',
                'title'         => self::SCHEDULE_NAME,
                'hold_code'     => null,
                'expected_return' => null,
                'pm_schedule_id' => (int)$r->id,
                'work_order_id' => null,
                'backlog_id'    => null,
                'status'        => 'Planned',
                'source'        => self::SOURCE_INSPECTION,
                'created_by'    => $user_id,
            ));
            $out['placed']++;
        }

        $out['sync']   = $this->sync_week($ws, $user_id);
        $out['status'] = $this->sync_inspection_status($ws);
        return $out;
    }

    /**
     * Completion (R-MP2): a submitted inspection for the machine inside the week
     * marks its inspection job Done and records the schedule's last-done date.
     * A Done job whose inspection has since gone is reopened to Planned. Drafts
     * do not count (the same rule as the weekly report's register, BR-23).
     */
    public function sync_inspection_status($week_start) {
        $fc  = $this->fc();
        $ws  = $fc->week_start($week_start);
        $we  = $fc->week_end($ws);
        $out = array('done' => 0, 'reopened' => 0);
        if (!$this->tables_ready()) return $out;

        $last = array();
        if ($this->db->table_exists('inspection_records')) {
            foreach ($this->db->where('status', 'submitted')->get('inspection_records')->result() as $rec) {
                $when = substr((string)$rec->inspection_date, 0, 10);
                if ($when < $ws || $when > $we || empty($rec->equipment_id)) continue;
                $eid = (int)$rec->equipment_id;
                if (!isset($last[$eid]) || $when > $last[$eid]) $last[$eid] = $when;
            }
        }

        foreach ($this->db->where('source', self::SOURCE_INSPECTION)->get(self::TABLE)->result() as $j) {
            if ((string)$j->start_date < $ws || (string)$j->start_date > $we) continue;
            if (in_array($j->status, array('Cancelled', 'Missed'), true)) continue;
            $eid  = (int)$j->equipment_id;
            $done = isset($last[$eid]);

            if ($done && $j->status !== 'Done') {
                $this->db->where('id', (int)$j->id)->update(self::TABLE, array('status' => 'Done'));
                $out['done']++;
            } elseif (!$done && $j->status === 'Done') {
                $this->db->where('id', (int)$j->id)->update(self::TABLE, array('status' => 'Planned'));
                $out['reopened']++;
            }

            if ($done && (int)$j->pm_schedule_id > 0 && $this->db->table_exists('pm_schedules')) {
                $ps = $this->db->where('id', (int)$j->pm_schedule_id)->get('pm_schedules')->row();
                if ($ps && ($ps->last_done_date === null || (string)$ps->last_done_date < $last[$eid])) {
                    $this->db->where('id', (int)$ps->id)->update('pm_schedules', array('last_done_date' => $last[$eid]));
                }
            }
        }
        return $out;
    }

    protected function _is_date($s) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s)) return false;
        list($y, $m, $d) = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y);
    }
}
