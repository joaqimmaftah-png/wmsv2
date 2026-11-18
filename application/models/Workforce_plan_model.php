<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Annual workforce plan: dated base shift/off/holiday, overlaid by planned leave,
 * and an independent, auditable off-day-work exception. Actual attendance is
 * never generated for future dates. A Draft is visible to planners only; a
 * Published year is used for today's availability and in-app reminders.
 */
class Workforce_plan_model extends CI_Model {
    private $tables_ready = NULL;

    public function ready() {
        if ($this->tables_ready !== NULL) return $this->tables_ready;
        $this->tables_ready = TRUE;
        foreach (array('workforce_plan_years', 'workforce_plan_days', 'workforce_plan_leaves',
                       'workforce_plan_extra_days', 'workforce_plan_events') as $table) {
            if (!$this->db->table_exists($table)) { $this->tables_ready = FALSE; break; }
        }
        return $this->tables_ready;
    }

    public function year($year) {
        if (!$this->ready()) return NULL;
        return $this->db->where('plan_year', (int)$year)->get('workforce_plan_years')->row();
    }

    public function is_published($date) {
        $year = $this->year((int)substr((string)$date, 0, 4));
        // Publication is a state of the whole year, not a minimum work date.
        // Publishing mid-year must not hide a date already materialised in the
        // draft; a future/empty timestamp must not expose an incomplete plan.
        return $year && $year->status === 'Published' && !empty($year->published_at)
            && $year->published_at <= date('Y-m-d H:i:s');
    }

    /** One query per category, independent of workforce size. Draft data is
     * never exposed to a technician/store keeper (caller must authorize preview).
     * An unconverted week in a year without a live annual plan keeps its old
     * weekly assignment. Approved historical leave always takes precedence.
     * @return array [user_id][Y-m-d] => {kind,shift_id,source,leave_type,extra,replacement,plan_status}
     */
    public function days($from, $to, array $user_ids, $preview = FALSE) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), function ($n) { return $n > 0; })));
        if (!$ids || $from > $to || !$this->ready()) return array();
        $years = array();
        foreach ($this->db->where('plan_year >=', (int)substr($from, 0, 4))
                  ->where('plan_year <=', (int)substr($to, 0, 4))
                  ->get('workforce_plan_years')->result() as $r) $years[(int)$r->plan_year] = $r;

        $base = array();
        foreach ($this->db->where_in('user_id', $ids)->where('work_date >=', $from)
                  ->where('work_date <=', $to)->get('workforce_plan_days')->result() as $r) {
            $base[(int)$r->user_id][$r->work_date] = $r;
        }
        $planned_leave = array();
        foreach ($this->db->where_in('user_id', $ids)->where('status', 'Scheduled')
                  ->where('start_date <=', $to)->where('end_date >=', $from)
                  ->get('workforce_plan_leaves')->result() as $r) {
            for ($d = max($from, $r->start_date); $d <= min($to, $r->end_date); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                $planned_leave[(int)$r->user_id][$d] = $r;
            }
        }
        $legacy_leave = array();
        foreach ($this->db->where_in('user_id', $ids)->where('status', 'Approved')
                  ->where('start_date <=', $to)->where('end_date >=', $from)
                  ->get('leave_requests')->result() as $r) {
            for ($d = max($from, $r->start_date); $d <= min($to, $r->end_date); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                $legacy_leave[(int)$r->user_id][$d] = $r;
            }
        }
        $extras = array();
        foreach ($this->db->where_in('user_id', $ids)->where('status !=', 'Cancelled')
                  ->where('work_date >=', $from)->where('work_date <=', $to)
                  ->get('workforce_plan_extra_days')->result() as $r) {
            $extras[(int)$r->user_id][$r->work_date] = $r;
        }
        $replacements = array();
        foreach ($this->db->where_in('user_id', $ids)->where_in('settlement', array('Scheduled', 'Taken'))
                  ->where('replacement_date >=', $from)->where('replacement_date <=', $to)
                  ->get('workforce_plan_extra_days')->result() as $r) {
            $replacements[(int)$r->user_id][$r->replacement_date] = $r;
        }
        // Read-only compatibility for a year that has not yet been published.
        $old = array();
        $old_from = date('Y-m-d', strtotime($from . ' -6 days'));
        foreach ($this->db->where_in('user_id', $ids)->where('week_start >=', $old_from)
                  ->where('week_start <=', $to)->order_by('id', 'ASC')
                  ->get('shift_assignments')->result() as $r) {
            $old[(int)$r->user_id][$r->week_start] = (int)$r->shift_id;
        }

        $out = array();
        $now = date('Y-m-d H:i:s');
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $year = (int)substr($d, 0, 4);
            $yr = isset($years[$year]) ? $years[$year] : NULL;
            $live = $yr && $yr->status === 'Published' && !empty($yr->published_at) && $yr->published_at <= $now;
            $draft = $preview && $yr && $yr->status === 'Draft';
            $wk = date('Y-m-d', strtotime('monday this week', strtotime($d)));
            foreach ($ids as $uid) {
                $row = ($live || $draft) && isset($base[$uid][$d]) ? $base[$uid][$d] : NULL;
                $kind = $row ? $row->day_type : 'Unplanned';
                $sid = $row ? (int)$row->shift_id : 0;
                $source = $row ? $row->source : '';
                // Publishing the remaining year does not manufacture historic
                // base days. Preserve read-only weekly history for a missing
                // date before publication; show a real gap for future dates.
                $legacy = !$row && isset($old[$uid][$wk]) && !$draft
                    && (!$live || $d < substr((string)$yr->published_at, 0, 10));
                if ($legacy) {
                    $kind = 'Shift'; $sid = $old[$uid][$wk]; $source = 'Legacy';
                }
                $leave = NULL;
                if (($live || $draft) && isset($planned_leave[$uid][$d])) $leave = $planned_leave[$uid][$d];
                if (isset($legacy_leave[$uid][$d])) $leave = $legacy_leave[$uid][$d];
                if ($leave) { $kind = 'Leave'; $sid = 0; }
                $out[$uid][$d] = array(
                    'kind' => $kind, 'shift_id' => $sid, 'source' => $source,
                    'leave_type' => $leave ? (string)$leave->leave_type : '',
                    'leave_id' => $leave ? (int)$leave->id : 0,
                    'extra' => ($kind === 'Off' && $live && isset($extras[$uid][$d])) ? $extras[$uid][$d] : NULL,
                    'replacement' => ($kind === 'Off' && $live && isset($replacements[$uid][$d])) ? $replacements[$uid][$d] : NULL,
                    'plan_status' => $legacy ? 'Legacy' : ($live ? 'Published' : ($draft ? 'Draft' : 'Legacy')),
                );
            }
        }
        return $out;
    }

    /** Base shift days under scheduled leave are chargeable; OFF and holidays
     * do not consume entitlement. Recompute from live/draft base rows instead
     * of storing a count that becomes stale when a rotation is corrected. */
    public function leave_usage($user_id, $year) {
        if (!$this->ready()) return array();
        $rows = $this->db->select('l.leave_type, COUNT(d.id) AS days')
            ->from('workforce_plan_leaves l')
            ->join('workforce_plan_days d', 'd.user_id = l.user_id AND d.work_date >= l.start_date AND d.work_date <= l.end_date', 'left')
            ->where('l.user_id', (int)$user_id)->where('l.status', 'Scheduled')
            ->where('d.day_type', 'Shift')->where('d.work_date >=', $year . '-01-01')
            ->where('d.work_date <=', $year . '-12-31')->group_by('l.leave_type')->get()->result();
        $out = array();
        foreach ($rows as $r) $out[$r->leave_type] = (int)$r->days;
        return $out;
    }

    public function leaves($year, $user_id = NULL, $clip_to_year = FALSE) {
        if (!$this->ready()) return array();
        $this->db->where('start_date <=', $year . '-12-31')->where('end_date >=', $year . '-01-01');
        if ($user_id) $this->db->where('user_id', (int)$user_id);
        $rows = $this->db->where('status', 'Scheduled')->order_by('start_date', 'ASC')
            ->get('workforce_plan_leaves')->result();
        // A published 2026 plan can include a leave block that extends into
        // an unpublished 2027 draft. Do not expose that draft period to staff.
        if ($clip_to_year) foreach ($rows as $r) {
            $r->partial = $r->start_date < $year . '-01-01' || $r->end_date > $year . '-12-31';
            $r->start_date = max($r->start_date, $year . '-01-01');
            $r->end_date = min($r->end_date, $year . '-12-31');
        }
        return $rows;
    }

    public function extras($year, $user_id = NULL) {
        if (!$this->ready()) return array();
        $this->db->where('work_date >=', $year . '-01-01')->where('work_date <=', $year . '-12-31')
            ->where('status !=', 'Cancelled');
        if ($user_id) $this->db->where('user_id', (int)$user_id);
        return $this->db->order_by('work_date', 'DESC')->get('workforce_plan_extra_days')->result();
    }

    public function events($year, $user_id = NULL) {
        if (!$this->ready()) return array();
        $this->db->select("e.*, CONCAT(u.first_name, ' ', u.last_name) AS actor_name")
            ->from('workforce_plan_events e')->join('users u', 'u.id = e.actor_id', 'left')
            ->where('e.plan_year', (int)$year);
        if ($user_id) $this->db->group_start()->where('e.user_id', (int)$user_id)
            ->or_where('e.user_id IS NULL', NULL, FALSE)->group_end();
        return $this->db->order_by('e.id', 'DESC')->limit(25)->get()->result();
    }

    /** Month totals for a single worker; gaps remain visible in the year UI. */
    public function monthly_totals($year, $user_id) {
        if (!$this->ready()) return array();
        $rows = $this->db->select('MONTH(work_date) AS month_no, day_type, COUNT(*) AS days', FALSE)
            ->where('user_id', (int)$user_id)->where('work_date >=', $year . '-01-01')
            ->where('work_date <=', $year . '-12-31')->group_by(array('MONTH(work_date)', 'day_type'))
            ->get('workforce_plan_days')->result();
        $out = array();
        foreach ($rows as $r) $out[(int)$r->month_no][$r->day_type] = (int)$r->days;
        return $out;
    }

    public function year_totals($year) {
        $counts = array('Shift' => 0, 'Off' => 0, 'Holiday' => 0, 'leave_blocks' => 0, 'extra' => 0);
        if (!$this->ready()) return $counts;
        foreach ($this->db->select('day_type, COUNT(*) AS cnt')->where('work_date >=', $year . '-01-01')
                     ->where('work_date <=', $year . '-12-31')->group_by('day_type')
                     ->get('workforce_plan_days')->result() as $r) $counts[$r->day_type] = (int)$r->cnt;
        $counts['leave_blocks'] = $this->db->where('status', 'Scheduled')->where('start_date <=', $year . '-12-31')
            ->where('end_date >=', $year . '-01-01')->count_all_results('workforce_plan_leaves');
        $counts['extra'] = $this->db->where('status !=', 'Cancelled')->where('work_date >=', $year . '-01-01')
            ->where('work_date <=', $year . '-12-31')->count_all_results('workforce_plan_extra_days');
        return $counts;
    }

    /** Coverage of active workers from today (or Jan 1) and their join date.
     * Returned counts are worker-days, not workers with one weekly shift. */
    public function coverage($year) {
        $out = array('staff' => 0, 'expected' => 0, 'planned' => 0, 'missing' => 0, 'staff_missing' => 0);
        if (!$this->ready()) return $out;
        $start = max($year . '-01-01', date('Y-m-d'));
        $end = $year . '-12-31';
        if ($start > $end) return $out;
        $people = $this->db->select('id, join_date')->where_in('status', array('Active', 'On Leave'))->get('users')->result();
        $out['staff'] = count($people);
        $planned = array();
        foreach ($this->db->select('d.user_id, COUNT(*) AS n')->from('workforce_plan_days d')
                     ->join('users u', 'u.id = d.user_id', 'inner')->where_in('u.status', array('Active', 'On Leave'))
                     ->where('d.work_date >=', $start)->where('d.work_date <=', $end)
                     ->where('d.work_date >= COALESCE(u.join_date, ' . $this->db->escape($start) . ')', NULL, FALSE)
                     ->group_by('d.user_id')->get()->result() as $r)
            $planned[(int)$r->user_id] = (int)$r->n;
        foreach ($people as $p) {
            $first = max($start, !empty($p->join_date) ? (string)$p->join_date : $start);
            if ($first > $end) continue;
            $expected = (int)(new DateTimeImmutable($first))->diff(new DateTimeImmutable($end))->days + 1;
            $have = min($expected, isset($planned[(int)$p->id]) ? $planned[(int)$p->id] : 0);
            $out['expected'] += $expected;
            $out['planned'] += $have;
            if ($have < $expected) { $out['missing'] += $expected - $have; $out['staff_missing']++; }
        }
        return $out;
    }

    /** One grouped query for the annual directory page (no N+1 per worker). */
    public function staff_totals($year, array $ids) {
        if (!$this->ready() || !$ids) return array();
        $rows = $this->db->select('user_id, day_type, COUNT(*) AS n')
            ->where_in('user_id', $ids)->where('work_date >=', $year . '-01-01')
            ->where('work_date <=', $year . '-12-31')
            ->group_by(array('user_id', 'day_type'))->get('workforce_plan_days')->result();
        $out = array();
        foreach ($rows as $r) $out[(int)$r->user_id][$r->day_type] = (int)$r->n;
        $from = max($year . '-01-01', date('Y-m-d'));
        if ($from <= $year . '-12-31') {
            foreach ($this->db->select('d.user_id, COUNT(*) AS n')->from('workforce_plan_days d')
                         ->join('users u', 'u.id = d.user_id')->where_in('d.user_id', $ids)
                         ->where('d.work_date >=', $from)->where('d.work_date <=', $year . '-12-31')
                         ->where('d.work_date >= COALESCE(u.join_date, ' . $this->db->escape($from) . ')', NULL, FALSE)
                         ->group_by('d.user_id')->get()->result() as $r)
                $out[(int)$r->user_id]['Future'] = (int)$r->n;
        }
        return $out;
    }

    public static function pattern_day($date, $year, array $pattern) {
        $anchor = new DateTimeImmutable($year . '-01-01');
        // Whole-week cycles start on Monday; non-week cycles (4-on/2-off,
        // 4-on/4-off) start on Jan 1. Every day is materialised, so a manager
        // can always override dates individually without changing the cycle.
        if (count($pattern) % 7 === 0) $anchor = $anchor->modify('monday this week');
        $offset = (int)$anchor->diff(new DateTimeImmutable($date))->format('%a');
        return $pattern[$offset % count($pattern)];
    }

    private function begin_years(array $years, $actor) {
        $years = array_unique(array_map('intval', $years));
        foreach ($years as $y) if ($y < 2020 || $y > 2100) return FALSE;
        if (!$this->ready() || !$this->db->trans_begin()) return FALSE;
        sort($years, SORT_NUMERIC); // deterministic lock order, including cross-year leave
        foreach ($years as $year) {
            $this->db->query('INSERT IGNORE INTO workforce_plan_years (plan_year,created_by) VALUES (?,?)', array($year, $actor));
            $lock = $this->db->query('SELECT plan_year FROM workforce_plan_years WHERE plan_year = ? FOR UPDATE', array($year));
            if (!$lock || !$lock->row()) { $this->db->trans_rollback(); return FALSE; }
        }
        return TRUE;
    }

    private function done($message) {
        if ($this->db->trans_status() === FALSE || !$this->db->trans_commit()) {
            $this->db->trans_rollback();
            return array('ok' => FALSE, 'message' => 'Database error; no planning changes were saved.');
        }
        return array('ok' => TRUE, 'message' => $message);
    }

    private function fail($message) {
        $this->db->trans_rollback();
        return array('ok' => FALSE, 'message' => $message);
    }

    private function event($year, $uid, $date, $type, $details, $actor) {
        $this->db->insert('workforce_plan_events', array(
            'plan_year' => (int)$year, 'user_id' => $uid, 'work_date' => $date,
            'event_type' => $type, 'details' => is_string($details) ? $details : json_encode($details),
            'actor_id' => $actor,
        ));
    }

    private function leave_events(array $years, $uid, $date, $type, $details, $actor) {
        // Each annual view must show a cross-year block's creation/correction.
        foreach (array_unique($years) as $year) $this->event($year, $uid, $date, $type, $details, $actor);
    }

    private function leave_conflict($uid, $start, $end, $exclude_id = NULL) {
        $this->db->where('user_id', (int)$uid)->where('status', 'Scheduled')
            ->where('start_date <=', $end)->where('end_date >=', $start);
        if ($exclude_id !== NULL) $this->db->where('id !=', (int)$exclude_id);
        $planned = $this->db->count_all_results('workforce_plan_leaves');
        $existing = $this->db->where('user_id', (int)$uid)
            ->where_in('status', array('Pending', 'Approved'))
            ->where('start_date <=', $end)->where('end_date >=', $start)
            ->count_all_results('leave_requests');
        return $planned > 0 || $existing > 0;
    }

    public function save_day($uid, $date, $type, $shift_id, $reason, $actor) {
        if ($date < date('Y-m-d')) return array('ok' => FALSE, 'message' => 'Past roster dates are locked; attendance is the historical record.');
        if (!$this->begin_years(array((int)substr($date, 0, 4)), $actor)) return array('ok' => FALSE, 'message' => 'Annual planning is unavailable; run migration 0018.');
        $year = (int)substr($date, 0, 4);
        $yr = $this->year($year);
        if ($yr->status === 'Published' && $type === 'Unplanned') return $this->fail('A published day cannot be cleared; replace it with a shift, Off or Holiday.');
        if ($yr->status === 'Published' && strlen(trim($reason)) < 5) return $this->fail('Give a reason (at least 5 characters) for changing a published plan.');
        if ($this->leave_conflict($uid, $date, $date)) return $this->fail('This date is on leave. Change the leave block before editing its schedule.');
        $extra = $this->db->where('user_id', (int)$uid)->where('work_date', $date)
            ->where('status !=', 'Cancelled')->get('workforce_plan_extra_days')->row();
        $replaced = $this->db->where('user_id', (int)$uid)->where('replacement_date', $date)
            ->where_in('settlement', array('Scheduled', 'Taken'))->get('workforce_plan_extra_days')->row();
        if ($extra && $type !== 'Off') return $this->fail('Off-day extra work is recorded here. Cancel it before changing this day.');
        if ($replaced && $type !== 'Off') return $this->fail('This is a replacement Off day. Undo a scheduled replacement before changing it; a taken day is historical.');
        $before = $this->db->where('user_id', (int)$uid)->where('work_date', $date)
            ->get('workforce_plan_days')->row();
        if ($type === 'Unplanned') {
            if ($extra || $replaced) return $this->fail('Resolve the extra-day or replacement record before clearing this date.');
            if ($before) $this->db->where('id', $before->id)->delete('workforce_plan_days');
        } else {
            $data = array('day_type' => $type, 'shift_id' => $type === 'Shift' ? (int)$shift_id : NULL,
                          'source' => 'Manual', 'reason' => trim($reason) ?: NULL,
                          'updated_by' => $actor, 'updated_at' => date('Y-m-d H:i:s'));
            if ($before) $this->db->where('id', $before->id)->update('workforce_plan_days', $data);
            else {
                $data['user_id'] = (int)$uid; $data['work_date'] = $date;
                $this->db->insert('workforce_plan_days', $data);
            }
        }
        $this->event($year, (int)$uid, $date, 'Day changed', array(
            'from' => $before ? $before->day_type . ($before->shift_id ? ':' . $before->shift_id : '') : 'Unplanned',
            'to' => $type . ($type === 'Shift' ? ':' . $shift_id : ''), 'reason' => trim($reason),
        ), $actor);
        $this->db->where('plan_year', $year)->update('workforce_plan_years', array('updated_by' => $actor, 'updated_at' => date('Y-m-d H:i:s')));
        return $this->done('Day updated. ' . ($yr->status === 'Draft' ? 'Publish the year to make it live.' : 'The published plan is updated.'));
    }

    /** Apply a 2–56-day rotation across the *entire remaining year*.
     * Manual/imported days are preserved. One lock, batched inserts and one
     * event per worker; never one DB round-trip per date. */
    public function apply_pattern($year, array $ids, array $pattern, $actor, $reason = '') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || count($ids) > 100 || count($pattern) < 2 || count($pattern) > 56)
            return array('ok' => FALSE, 'message' => 'Select 1–100 workers and a 2–56-day cycle.');
        $start = max($year . '-01-01', date('Y-m-d'));
        $end = $year . '-12-31';
        if ($start > $end) return array('ok' => FALSE, 'message' => 'Past years cannot be regenerated.');
        if (!$this->begin_years(array($year), $actor)) return array('ok' => FALSE, 'message' => 'Annual planning is unavailable; run migration 0018.');
        $yr = $this->year($year);
        if ($yr->status === 'Published' && strlen(trim($reason)) < 5) return $this->fail('Give a reason (at least 5 characters) for replacing a published pattern.');
        $join_dates = array();
        foreach ($this->db->select('id, join_date')->where_in('id', $ids)
                     ->where_in('status', array('Active', 'On Leave'))->get('users')->result() as $person) {
            $join_dates[(int)$person->id] = $person->join_date ?: $start;
        }
        if (count($join_dates) !== count($ids)) return $this->fail('One or more workers are no longer active. Reload the selection.');
        $protected = array();
        foreach ($this->db->select('user_id, work_date, source, day_type')->where_in('user_id', $ids)
                     ->where('work_date >=', $start)->where('work_date <=', $end)
                     ->get('workforce_plan_days')->result() as $r) {
            if ($r->source !== 'Pattern') $protected[(int)$r->user_id][$r->work_date] = TRUE;
        }
        $extra = array();
        foreach ($this->db->select('user_id, work_date')->where_in('user_id', $ids)
                     ->where('status !=', 'Cancelled')->where('work_date >=', $start)
                     ->where('work_date <=', $end)->get('workforce_plan_extra_days')->result() as $r) {
            $extra[(int)$r->user_id][$r->work_date] = TRUE;
        }
        foreach ($ids as $uid) {
            foreach ($extra[$uid] ?? array() as $d => $_) {
                if (!isset($protected[$uid][$d]) && self::pattern_day($d, $year, $pattern) !== 'Off')
                    return $this->fail('A new pattern would replace an off day with approved extra work on ' . $d . '. Cancel the exception first.');
            }
        }
        $this->db->where_in('user_id', $ids)->where('work_date >=', $start)->where('work_date <=', $end)
            ->where('source', 'Pattern')->delete('workforce_plan_days');
        $now = date('Y-m-d H:i:s');
        $batch = array(); $written = 0; $per_worker = array();
        for ($d = $start; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $value = self::pattern_day($d, $year, $pattern);
            foreach ($ids as $uid) {
                if ($d < $join_dates[$uid] || isset($protected[$uid][$d])) continue;
                $batch[] = array('user_id' => $uid, 'work_date' => $d,
                    'day_type' => $value === 'Off' ? 'Off' : 'Shift',
                    'shift_id' => $value === 'Off' ? NULL : (int)$value,
                    'source' => 'Pattern', 'reason' => trim($reason) ?: NULL,
                    'updated_by' => $actor, 'updated_at' => $now);
                $written++;
                $per_worker[$uid] = isset($per_worker[$uid]) ? $per_worker[$uid] + 1 : 1;
                if (count($batch) >= 400) { $this->db->insert_batch('workforce_plan_days', $batch); $batch = array(); }
            }
        }
        if ($batch) $this->db->insert_batch('workforce_plan_days', $batch);
        foreach ($ids as $uid) $this->event($year, $uid, NULL, 'Pattern applied', array(
            'cycle_days' => count($pattern), 'days' => isset($per_worker[$uid]) ? $per_worker[$uid] : 0,
            'from' => $start, 'to' => $end, 'pattern' => $pattern, 'reason' => trim($reason),
        ), $actor);
        $this->db->where('plan_year', $year)->update('workforce_plan_years', array('updated_by' => $actor, 'updated_at' => $now));
        return $this->done('Rotation applied to ' . count($ids) . ' worker(s); ' . $written . ' future dates filled. Manual changes were kept.');
    }

    /** Convert only remaining *existing* weekly assignments into protected
     * draft dates. No synthetic leave, off days or shifts are invented. */
    public function carry_legacy($year, $actor) {
        $start = max($year . '-01-01', date('Y-m-d'));
        $end = $year . '-12-31';
        if ($start > $end) return array('ok' => FALSE, 'message' => 'Only current/future weeks can be carried forward.');
        if (!$this->begin_years(array($year), $actor)) return array('ok' => FALSE, 'message' => 'Annual planning is unavailable; run migration 0018.');
        if ($this->year($year)->status !== 'Draft') return $this->fail('Existing weekly shifts can only be carried into a draft year.');
        $old = $this->db->select('sa.user_id, sa.shift_id, sa.week_start, u.join_date')
            ->from('shift_assignments sa')->join('users u', 'u.id = sa.user_id')
            ->where_in('u.status', array('Active', 'On Leave'))
            ->where('sa.week_start >=', date('Y-m-d', strtotime($start . ' -6 days')))
            ->where('sa.week_start <=', $end)->order_by('sa.id', 'ASC')->get()->result();
        $rows = array(); $batch = array();
        foreach ($old as $r) {
            for ($i = 0; $i < 7; $i++) {
                $d = date('Y-m-d', strtotime($r->week_start . ' +' . $i . ' days'));
                if ($d < $start || $d > $end || ($r->join_date && $d < $r->join_date)) continue;
                $rows[(int)$r->user_id][$d] = (int)$r->shift_id;
            }
        }
        $present = array();
        if ($rows) foreach ($this->db->select('user_id, work_date')->where_in('user_id', array_keys($rows))
                              ->where('work_date >=', $start)->where('work_date <=', $end)
                              ->get('workforce_plan_days')->result() as $r) {
            $present[(int)$r->user_id][$r->work_date] = TRUE;
        }
        $now = date('Y-m-d H:i:s'); $count = 0;
        foreach ($rows as $uid => $dates) foreach ($dates as $d => $sid) {
            if (isset($present[$uid][$d])) continue;
            $batch[] = array('user_id' => $uid, 'work_date' => $d, 'day_type' => 'Shift',
                'shift_id' => $sid, 'source' => 'Legacy', 'updated_by' => $actor, 'updated_at' => $now);
            if (count($batch) >= 400) { $this->db->insert_batch('workforce_plan_days', $batch); $batch = array(); }
            $count++;
        }
        if ($batch) $this->db->insert_batch('workforce_plan_days', $batch);
        $this->event($year, NULL, NULL, 'Weekly shifts carried', array('imported_days' => $count), $actor);
        return $this->done($count . ' existing worker-days carried into the annual plan; existing annual dates were left untouched.');
    }

    /** Only publish when every active worker has a base plan for remaining
     * dates since join_date. No phantom coverage or silent weekly fallback. */
    public function publish($year, $actor) {
        $start = max($year . '-01-01', date('Y-m-d'));
        $end = $year . '-12-31';
        if ($start > $end) return array('ok' => FALSE, 'message' => 'Past years cannot be published now.');
        if (!$this->begin_years(array($year), $actor)) return array('ok' => FALSE, 'message' => 'Annual planning is unavailable; run migration 0018.');
        $yr = $this->year($year);
        if ($yr->status === 'Published') return $this->fail('This year is already published. Edit individual dates with a reason instead.');
        $coverage = $this->coverage($year);
        if (!$coverage['expected']) return $this->fail('There are no eligible worker-days to publish. Add or reactivate staff first.');
        if ($coverage['missing']) return $this->fail('Cannot publish: ' . $coverage['missing'] . ' unplanned worker-days across ' . $coverage['staff_missing'] . ' active people. Apply a pattern and fill remaining gaps first.');
        $now = date('Y-m-d H:i:s');
        $this->db->where('plan_year', (int)$year)->update('workforce_plan_years', array(
            'status' => 'Published', 'published_by' => $actor, 'published_at' => $now,
            'updated_by' => $actor, 'updated_at' => $now,
        ));
        $this->event($year, NULL, NULL, 'Year published', array('staff' => $coverage['staff'], 'effective_from' => $start), $actor);
        return $this->done($year . ' plan published. Leave reminders and day-level availability are now live.');
    }

    public function add_leave($uid, $type, $start, $end, $note, $actor) {
        if ($start < date('Y-m-d')) return array('ok' => FALSE, 'message' => 'Past leave belongs in actual attendance, not a future plan.');
        $years = range((int)substr($start, 0, 4), (int)substr($end, 0, 4));
        if (!$this->begin_years($years, $actor)) return array('ok' => FALSE, 'message' => 'Annual planning is unavailable; run migration 0018.');
        if ($this->leave_conflict($uid, $start, $end)) return $this->fail('This period overlaps existing planned, approved or pending leave.');
        if ($this->db->where('user_id', (int)$uid)->where('status !=', 'Cancelled')
            ->where('work_date >=', $start)->where('work_date <=', $end)
            ->count_all_results('workforce_plan_extra_days')) return $this->fail('Cancel off-day extra work in this period before planning leave.');
        if ($this->db->where('user_id', (int)$uid)->where('settlement', 'Scheduled')
            ->where('replacement_date >=', $start)->where('replacement_date <=', $end)
            ->count_all_results('workforce_plan_extra_days')) return $this->fail('Undo the replacement off day in this period before planning leave.');
        $this->db->insert('workforce_plan_leaves', array('user_id' => (int)$uid,
            'leave_type' => $type, 'start_date' => $start, 'end_date' => $end,
            'note' => trim($note) ?: NULL, 'created_by' => $actor));
        $id = (int)$this->db->insert_id();
        $this->leave_events($years, $uid, $start, 'Leave planned', array(
            'id' => $id, 'type' => $type, 'end' => $end, 'note' => trim($note),
        ), $actor);
        return $this->done($type . ' planned through ' . $end . '. It is active only in a published year.');
    }

    /** Amend a future planned block in place, retaining its id and an event
     * with both versions. Active leave can only be ended early, not rewritten. */
    public function amend_leave($id, $type, $start, $end, $note, $reason, $actor) {
        $item = $this->db->where('id', (int)$id)->get('workforce_plan_leaves')->row();
        if (!$item || $item->status !== 'Scheduled') return array('ok' => FALSE, 'message' => 'Planned leave not found.');
        if ($item->start_date < date('Y-m-d') || $start < date('Y-m-d'))
            return array('ok' => FALSE, 'message' => 'Leave that has started cannot be moved. End it early if necessary.');
        if (strlen(trim($reason)) < 5) return array('ok' => FALSE, 'message' => 'Give a reason for amending leave.');
        $years = array_unique(array_merge(
            range((int)substr($item->start_date, 0, 4), (int)substr($item->end_date, 0, 4)),
            range((int)substr($start, 0, 4), (int)substr($end, 0, 4))));
        if (!$this->begin_years($years, $actor)) return array('ok' => FALSE, 'message' => 'Annual planning is unavailable.');
        $current = $this->db->where('id', (int)$id)->get('workforce_plan_leaves')->row();
        if (!$current || $current->status !== 'Scheduled' || $current->start_date !== $item->start_date || $current->end_date !== $item->end_date)
            return $this->fail('Leave was already changed; refresh the page.');
        $worker = $this->db->select('join_date')->where('id', (int)$current->user_id)->get('users')->row();
        if (!$worker || ($worker->join_date && $start < $worker->join_date))
            return $this->fail('Planned leave must start on or after the worker’s join date.');
        if ($this->leave_conflict($item->user_id, $start, $end, $id))
            return $this->fail('The new period overlaps another planned, approved or pending leave.');
        if ($this->db->where('user_id', (int)$item->user_id)->where('status !=', 'Cancelled')
            ->where('work_date >=', $start)->where('work_date <=', $end)
            ->count_all_results('workforce_plan_extra_days')) return $this->fail('The new period overlaps off-day extra work.');
        if ($this->db->where('user_id', (int)$item->user_id)->where('settlement', 'Scheduled')
            ->where('replacement_date >=', $start)->where('replacement_date <=', $end)
            ->count_all_results('workforce_plan_extra_days')) return $this->fail('The new period overlaps a replacement Off day.');
        $this->db->where('id', (int)$id)->update('workforce_plan_leaves', array(
            'leave_type' => $type, 'start_date' => $start, 'end_date' => $end, 'note' => trim($note) ?: NULL));
        $this->leave_events($years, (int)$item->user_id, $start, 'Leave amended', array(
            'id' => (int)$id, 'before' => array($item->leave_type, $item->start_date, $item->end_date, $item->note),
            'after' => array($type, $start, $end, trim($note)), 'reason' => trim($reason)), $actor);
        return $this->done('Planned leave amended. Published dates and reminders now follow the new period.');
    }

    public function end_leave($id, $reason, $actor) {
        $item = $this->db->where('id', (int)$id)->get('workforce_plan_leaves')->row();
        if (!$item || $item->status !== 'Scheduled') return array('ok' => FALSE, 'message' => 'Active leave plan not found.');
        if ($item->end_date < date('Y-m-d')) return array('ok' => FALSE, 'message' => 'Completed leave is historical and cannot be removed.');
        if (strlen(trim($reason)) < 5) return array('ok' => FALSE, 'message' => 'Give a reason for changing planned leave.');
        $years = range((int)substr($item->start_date, 0, 4), (int)substr($item->end_date, 0, 4));
        if (!$this->begin_years($years, $actor)) return array('ok' => FALSE, 'message' => 'Annual planning is unavailable.');
        // Re-read after locking; two planners cannot shorten the same period at once.
        $item = $this->db->where('id', (int)$id)->get('workforce_plan_leaves')->row();
        if (!$item || $item->status !== 'Scheduled' || $item->end_date < date('Y-m-d')) return $this->fail('Leave was already changed; refresh the page.');
        if ($item->start_date >= date('Y-m-d')) {
            $this->db->where('id', (int)$id)->update('workforce_plan_leaves', array(
                'status' => 'Cancelled', 'cancelled_by' => $actor, 'cancelled_at' => date('Y-m-d H:i:s'),
                'cancel_reason' => trim($reason),
            ));
        } else {
            // Keep past days visible; only the future portion is cancelled.
            $this->db->where('id', (int)$id)->update('workforce_plan_leaves', array(
                'end_date' => date('Y-m-d', strtotime('yesterday')), 'cancel_reason' => trim($reason),
            ));
        }
        $this->leave_events($years, (int)$item->user_id, $item->start_date,
            'Leave changed', array('old_end' => $item->end_date, 'reason' => trim($reason)), $actor);
        return $this->done($item->start_date < date('Y-m-d') ? 'Leave ended early; past days remain on the record.' : 'Future leave cancelled.');
    }

    public function plan_extra($uid, $date, $shift_id, $compensation, $reason, $actor) {
        if ($date < date('Y-m-d')) return array('ok' => FALSE, 'message' => 'Extra work must be planned on or before the off day.');
        if (strlen(trim($reason)) < 5) return array('ok' => FALSE, 'message' => 'Explain why work is needed on the off day.');
        if (!$this->begin_years(array((int)substr($date, 0, 4)), $actor)) return array('ok' => FALSE, 'message' => 'Annual planning is unavailable.');
        if (!$this->is_published($date)) return $this->fail('Publish this year first; an off-day call-in must be visible to the worker.');
        $base = $this->db->where('user_id', (int)$uid)->where('work_date', $date)->get('workforce_plan_days')->row();
        if (!$base || $base->day_type !== 'Off') return $this->fail('Choose a planned Off day before arranging extra work.');
        if ($this->leave_conflict($uid, $date, $date)) return $this->fail('Leave takes precedence; change it before planning extra work.');
        if ($this->db->where('user_id', (int)$uid)->where('replacement_date', $date)
                ->where_in('settlement', array('Scheduled', 'Taken'))
                ->count_all_results('workforce_plan_extra_days'))
            return $this->fail('This Off day replaces earlier extra work; it cannot also be an extra-work day.');
        $current = $this->db->where('user_id', (int)$uid)->where('work_date', $date)->get('workforce_plan_extra_days')->row();
        if ($current && $current->status === 'Confirmed') return $this->fail('Confirmed work cannot be re-planned.');
        $fields = array('shift_id' => (int)$shift_id, 'status' => 'Planned', 'compensation' => $compensation,
            'settlement' => 'Open', 'replacement_date' => NULL, 'replaced_shift_id' => NULL,
            'reason' => trim($reason), 'approved_by' => $actor, 'approved_at' => date('Y-m-d H:i:s'),
            'confirmed_by' => NULL, 'confirmed_at' => NULL, 'confirmed_hours' => NULL,
            'payroll_reference' => NULL, 'settled_by' => NULL, 'settled_at' => NULL);
        if ($current) $this->db->where('id', $current->id)->update('workforce_plan_extra_days', $fields);
        else { $fields['user_id'] = (int)$uid; $fields['work_date'] = $date; $this->db->insert('workforce_plan_extra_days', $fields); }
        $this->event((int)substr($date, 0, 4), $uid, $date, 'Off-day work planned',
            array('shift_id' => $shift_id, 'compensation' => $compensation, 'reason' => trim($reason)), $actor);
        return $this->done('Extra work planned. The day remains Off until the actual shift is confirmed.');
    }

    public function confirm_extra($id, $actor) {
        $row = $this->db->where('id', (int)$id)->get('workforce_plan_extra_days')->row();
        if (!$row || $row->status !== 'Planned') return array('ok' => FALSE, 'message' => 'Planned extra work not found.');
        if ($row->work_date > date('Y-m-d')) return array('ok' => FALSE, 'message' => 'Confirm extra work after the shift has happened.');
        if (!$this->begin_years(array((int)substr($row->work_date, 0, 4)), $actor)) return array('ok' => FALSE, 'message' => 'Annual planning is unavailable.');
        $row = $this->db->where('id', (int)$id)->get('workforce_plan_extra_days')->row();
        if (!$row || $row->status !== 'Planned') return $this->fail('This entry was changed; refresh the page.');
        $att = $this->db->where('user_id', (int)$row->user_id)->where('date', $row->work_date)->get('attendance')->row();
        if (!$att || $att->status !== 'Present' || empty($att->check_in) || empty($att->check_out)
            || (float)$att->hours_worked <= 0) return $this->fail('Record completed attendance with check-in, check-out and hours before confirming extra work.');
        $this->db->where('id', $row->id)->update('workforce_plan_extra_days', array(
            'status' => 'Confirmed', 'confirmed_by' => $actor, 'confirmed_at' => date('Y-m-d H:i:s'),
            'confirmed_hours' => (float)$att->hours_worked));
        $this->event((int)substr($row->work_date, 0, 4), (int)$row->user_id, $row->work_date,
            'Off-day work confirmed', array('hours' => $att->hours_worked, 'compensation' => $row->compensation), $actor);
        return $this->done('Off-day work confirmed. Compensation is open until it is settled.');
    }

    public function settle_extra($id, $action, $replacement, $reason, $actor) {
        $row = $this->db->where('id', (int)$id)->get('workforce_plan_extra_days')->row();
        if (!$row || $row->status !== 'Confirmed') return array('ok' => FALSE, 'message' => 'Confirm the worked day first.');
        $years = array((int)substr($row->work_date, 0, 4));
        if ($action === 'schedule' && $replacement) $years[] = (int)substr($replacement, 0, 4);
        if ($row->replacement_date) $years[] = (int)substr($row->replacement_date, 0, 4);
        if (!$this->begin_years($years, $actor)) return array('ok' => FALSE, 'message' => 'Annual planning is unavailable.');
        $row = $this->db->where('id', (int)$id)->get('workforce_plan_extra_days')->row();
        if (!$row || $row->status !== 'Confirmed') return $this->fail('This entry was changed; refresh the page.');
        if ($action === 'pay' && $row->compensation === 'Pay' && $row->settlement === 'Open') {
            if (strlen(trim($reason)) < 5) return $this->fail('Give the payroll reference or settlement note.');
            $this->db->where('id', $row->id)->update('workforce_plan_extra_days', array(
                'settlement' => 'Paid', 'payroll_reference' => substr(trim($reason), 0, 100),
                'settled_by' => $actor, 'settled_at' => date('Y-m-d H:i:s')));
            $message = 'Marked as settled by payroll. No pay amount is calculated in WMS.';
        } elseif ($action === 'schedule' && $row->compensation === 'TimeOff' && $row->settlement === 'Open') {
            if (!$replacement || $replacement <= date('Y-m-d') || $replacement <= $row->work_date)
                return $this->fail('Choose a future replacement date after the worked off day.');
            if (!$this->is_published($replacement)) return $this->fail('Publish the replacement year before assigning a compensatory Off day.');
            if ($this->leave_conflict($row->user_id, $replacement, $replacement))
                return $this->fail('Replacement cannot overlap leave.');
            $day = $this->db->where('user_id', $row->user_id)->where('work_date', $replacement)
                ->get('workforce_plan_days')->row();
            if (!$day || $day->day_type !== 'Shift') return $this->fail('Replacement must displace a scheduled work shift.');
            $other = $this->db->where('user_id', $row->user_id)->where('work_date', $replacement)
                ->where('status !=', 'Cancelled')->count_all_results('workforce_plan_extra_days');
            $taken = $this->db->where('user_id', $row->user_id)->where('replacement_date', $replacement)
                ->where_in('settlement', array('Scheduled', 'Taken'))
                ->count_all_results('workforce_plan_extra_days');
            if ($other || $taken) return $this->fail('Replacement date already has an extra-work or compensatory Off entry.');
            // Existing attendance is independent of the plan; never replace
            // a recorded Present day with a retrospective-looking Off day.
            $attendance = $this->db->where('user_id', (int)$row->user_id)->where('date', $replacement)
                ->get('attendance')->row();
            if ($attendance) return $this->fail('Replacement date already has actual attendance. Resolve it before rescheduling.');
            $this->db->where('id', $day->id)->update('workforce_plan_days', array(
                'day_type' => 'Off', 'shift_id' => NULL, 'source' => 'Manual',
                'reason' => 'Replacement off day for ' . $row->work_date,
                'updated_by' => $actor, 'updated_at' => date('Y-m-d H:i:s')));
            $this->db->where('id', $row->id)->update('workforce_plan_extra_days', array(
                'settlement' => 'Scheduled', 'replacement_date' => $replacement,
                'replaced_shift_id' => (int)$day->shift_id,
            ));
            $message = 'Replacement Off day scheduled for ' . $replacement . '. Mark it taken after the date passes.';
        } elseif ($action === 'take' && $row->compensation === 'TimeOff' && $row->settlement === 'Scheduled') {
            if ($row->replacement_date >= date('Y-m-d')) return $this->fail('Mark the replacement taken after its date has passed.');
            $att = $this->db->where('user_id', (int)$row->user_id)->where('date', $row->replacement_date)->get('attendance')->row();
            if (!$att || $att->status !== 'Rest' || !empty($att->check_in) || !empty($att->check_out))
                return $this->fail('Record actual Rest with no work times before marking this replacement day taken.');
            $this->db->where('id', $row->id)->update('workforce_plan_extra_days', array(
                'settlement' => 'Taken', 'settled_by' => $actor, 'settled_at' => date('Y-m-d H:i:s')));
            $message = 'Replacement day marked as taken.';
        } elseif ($action === 'undo' && $row->compensation === 'TimeOff' && $row->settlement === 'Scheduled') {
            if ($row->replacement_date <= date('Y-m-d')) return $this->fail('Only future replacement dates can be undone.');
            $day = $this->db->where('user_id', (int)$row->user_id)->where('work_date', $row->replacement_date)
                ->get('workforce_plan_days')->row();
            if (!$day || $day->day_type !== 'Off' || !$row->replaced_shift_id)
                return $this->fail('The original shift cannot be restored automatically.');
            $this->db->where('id', $day->id)->update('workforce_plan_days', array(
                'day_type' => 'Shift', 'shift_id' => (int)$row->replaced_shift_id, 'source' => 'Manual',
                'reason' => 'Restored after replacement was cancelled',
                'updated_by' => $actor, 'updated_at' => date('Y-m-d H:i:s')));
            $this->db->where('id', $row->id)->update('workforce_plan_extra_days', array(
                'settlement' => 'Open', 'replacement_date' => NULL, 'replaced_shift_id' => NULL));
            $message = 'Replacement reversed; the former shift is restored.';
        } else {
            return $this->fail('This settlement action is not allowed for the current state.');
        }
        $this->event((int)substr($row->work_date, 0, 4), (int)$row->user_id, $row->work_date,
            'Extra work settlement', array('action' => $action, 'replacement' => $replacement,
                'reason' => trim($reason)), $actor);
        $affected_date = $action === 'schedule' ? $replacement : $row->replacement_date;
        if ($affected_date && substr($affected_date, 0, 4) !== substr($row->work_date, 0, 4)) {
            $this->event((int)substr($affected_date, 0, 4), (int)$row->user_id, $affected_date,
                'Replacement Off ' . $action, array('worked_off_date' => $row->work_date,
                    'reason' => trim($reason)), $actor);
        }
        return $this->done($message);
    }

    public function cancel_extra($id, $reason, $actor) {
        $row = $this->db->where('id', (int)$id)->get('workforce_plan_extra_days')->row();
        if (!$row || $row->status === 'Cancelled') return array('ok' => FALSE, 'message' => 'Active extra-work entry not found.');
        if ($row->status === 'Confirmed') return array('ok' => FALSE, 'message' => 'Confirmed work has attendance and must be settled, not cancelled.');
        if ($row->settlement !== 'Open') return array('ok' => FALSE, 'message' => 'Undo/settle its replacement before cancelling extra work.');
        if (strlen(trim($reason)) < 5) return array('ok' => FALSE, 'message' => 'Give a reason for cancelling extra work.');
        if (!$this->begin_years(array((int)substr($row->work_date, 0, 4)), $actor)) return array('ok' => FALSE, 'message' => 'Annual planning is unavailable.');
        $row = $this->db->where('id', (int)$id)->get('workforce_plan_extra_days')->row();
        if (!$row || $row->status !== 'Planned' || $row->settlement !== 'Open') return $this->fail('This entry was changed; refresh the page.');
        $att = $this->db->where('user_id', (int)$row->user_id)->where('date', $row->work_date)
            ->get('attendance')->row();
        if ($att && $att->status === 'Present')
            return $this->fail('Actual attendance records work on this Off day. Confirm it and settle the compensation instead of cancelling it.');
        $this->db->where('id', $row->id)->update('workforce_plan_extra_days', array('status' => 'Cancelled'));
        $this->event((int)substr($row->work_date, 0, 4), (int)$row->user_id, $row->work_date,
            'Extra work cancelled', array('reason' => trim($reason)), $actor);
        return $this->done('Extra-work exception cancelled. The Off day remains on the plan.');
    }
}
