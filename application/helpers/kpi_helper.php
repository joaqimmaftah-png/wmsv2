<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * W36 KPI engine — plan tasks W1.2 (interval merge / per-day splitter),
 * W1.3 (availability, MTTR, MTBF) and W1.6 (the three breakdown KPIs).
 *
 * Pure functions: no database, no settings model, no formatting. Everything the
 * weekly report computes is derived here from plain event intervals, so the
 * preview and the Excel writer all run the same arithmetic and the
 * rules live in exactly one file.
 *
 * The rules:
 *   · downtime            measure of the UNION of a machine's breakdown
 *                         intervals ∩ the reporting period — overlapping or
 *                         touching work orders of one failure never double
 *                         count (W1.2)
 *   · Number of Breakdowns distinct events whose start falls inside the period
 *                         (D1 / settings.breakdown_count_mode)
 *   · Number of Days Down  calendar days from the breakdown date to the
 *                         restoration date, or to the period's last day while
 *                         the machine is still down — same-day = 1, never capped
 *                         by the period (D4)
 *   · Number of Hours Down the downtime measure of the period
 *   · preventive time      actual labour hours booked on preventive work orders
 *                         (`wo_sessions.hours_worked`, D2)
 *   · required time        open hours − preventive time
 *   · MTTR / MTBF          downtime ÷ events and (required − downtime) ÷ events,
 *                         with the documented no-failure handling (D3/OPEN-3)
 *   · availability         (required − downtime) ÷ required, clamped to 0…1
 *
 * Timestamps are unix seconds; hours are floats. Numeric comparisons use a
 * 0.005 h tolerance.
 */

if (!function_exists('kpi_ts')) {
    /**
     * Anything date-like ("2026-09-05", "2026-09-05 12:30:00", a timestamp) to
     * a unix timestamp; null/''/'NULL' → null.
     */
    function kpi_ts($value)
    {
        if ($value === null) return null;
        if (is_int($value) || (is_float($value) && $value > 100000)) return (int)$value;

        $value = trim((string)$value);
        if ($value === '' || strcasecmp($value, 'NULL') === 0) return null;
        if (ctype_digit($value)) return (int)$value;

        $ts = strtotime($value);
        return $ts === false ? null : $ts;
    }
}

if (!function_exists('kpi_day_start')) {
    /** Midnight of the day $value falls on (unix seconds). */
    function kpi_day_start($value)
    {
        $ts = kpi_ts($value);
        return $ts === null ? null : (int)strtotime(date('Y-m-d', $ts) . ' 00:00:00');
    }
}

if (!function_exists('kpi_hours')) {
    /** Hours between two timestamps (may be fractional, never negative on null). */
    function kpi_hours($from, $to)
    {
        $a = kpi_ts($from);
        $b = kpi_ts($to);
        if ($a === null || $b === null) return 0.0;
        return max(0.0, ($b - $a) / 3600.0);
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 |  W1.2 — intervals
 * ───────────────────────────────────────────────────────────────────────── */

if (!function_exists('kpi_merge_intervals')) {
    /**
     * Merge a list of [start, end] intervals: overlapping AND touching ones
     * become one, so a repair split into consecutive work orders is measured
     * once. Open intervals (end = null) may be closed by $clamp_end.
     *
     * @param  array    $intervals list of [start, end|null]
     * @param  int|null $clamp_end value used for every open interval
     * @return array    list of [start, end] (ints), sorted, merged
     */
    function kpi_merge_intervals(array $intervals, $clamp_end = null)
    {
        $clean = array();
        foreach ($intervals as $iv) {
            if (!is_array($iv) || count($iv) < 2) continue;
            $start = kpi_ts($iv[0]);
            $end   = kpi_ts($iv[1]);
            if ($end === null) $end = kpi_ts($clamp_end);
            if ($start === null || $end === null || $end <= $start) continue;
            $clean[] = array($start, $end);
        }

        usort($clean, function ($a, $b) { return $a[0] - $b[0]; });

        $merged = array();
        foreach ($clean as $iv) {
            $last = count($merged) - 1;
            if ($last >= 0 && $iv[0] <= $merged[$last][1]) {          // overlap OR touch
                if ($iv[1] > $merged[$last][1]) $merged[$last][1] = $iv[1];
                continue;
            }
            $merged[] = $iv;
        }
        return $merged;
    }
}

if (!function_exists('kpi_union_hours')) {
    /**
     * Hours covered by the union of $intervals inside [$from, $to].
     *
     * $from / $to may be null, in which case that side is not clipped.
     */
    function kpi_union_hours(array $intervals, $from = null, $to = null)
    {
        $lo = kpi_ts($from);
        $hi = kpi_ts($to);

        $clipped = array();
        foreach ($intervals as $iv) {
            if (!is_array($iv) || count($iv) < 2) continue;
            $start = kpi_ts($iv[0]);
            $end   = kpi_ts($iv[1]);
            if ($end === null) $end = $hi;                 // open interval → until the cut-off
            if ($start === null || $end === null) continue;

            if ($lo !== null && $start < $lo) $start = $lo;
            if ($hi !== null && $end   > $hi) $end   = $hi;
            if ($end <= $start) continue;

            $clipped[] = array($start, $end);
        }

        $total = 0.0;
        foreach (kpi_merge_intervals($clipped) as $iv) {
            $total += ($iv[1] - $iv[0]) / 3600.0;
        }
        return $total;
    }
}

if (!function_exists('kpi_split_days')) {
    /**
     * Split the union of $intervals across calendar days.
     *
     * @param  array $intervals
     * @param  array $days      list of 'Y-m-d' (the report's seven days)
     * @return array date => hours (every day in $days is present, 0.0 included)
     */
    function kpi_split_days(array $intervals, array $days)
    {
        $out = array();
        foreach ($days as $day) {
            $start = kpi_ts($day . ' 00:00:00');
            $end   = $start + 86400;
            $out[$day] = round(kpi_union_hours($intervals, $start, $end), 4);
        }
        return $out;
    }
}

if (!function_exists('kpi_day_list')) {
    /** The $count dates starting at $week_start ('Y-m-d'). */
    function kpi_day_list($week_start, $count = 7)
    {
        $start = kpi_ts($week_start);
        if ($start === null) return array();

        $days = array();
        for ($i = 0; $i < (int)$count; $i++) {
            $days[] = date('Y-m-d', $start + $i * 86400);
        }
        return $days;
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 |  W1.6 — breakdown events, days down
 * ───────────────────────────────────────────────────────────────────────── */

if (!function_exists('kpi_row')) {
    /** Read $key from an array or object row (DB rows are objects). */
    function kpi_row($row, $key, $default = null)
    {
        if (is_object($row)) return isset($row->$key) ? $row->$key : $default;
        if (is_array($row))  return array_key_exists($key, $row) ? $row[$key] : $default;
        return $default;
    }
}

if (!function_exists('kpi_breakdown_events')) {
    /**
     * Group breakdown work orders into failure events.
     *
     * Several repair work orders of one failure share a `breakdown_event_id`
     * (the id of the first one), so they count as ONE breakdown however many
     * work orders and however long the repair took (D1).
     *
     * @param  array $wo_rows work-order rows (is_breakdown, breakdown_event_id,
     *                        start_datetime, end_datetime, and an id/wo_number)
     * @param  int   $clamp_end used to close still-open intervals
     * @return array event key => [
     *                 'key','start','end','open','intervals','work_orders'
     *               ]  ('end' is null while any of the work orders is open)
     */
    function kpi_breakdown_events(array $wo_rows, $clamp_end = null)
    {
        $events = array();

        foreach ($wo_rows as $w) {
            if (!kpi_is_breakdown_row($w)) continue;

            // The first work order of an event keeps breakdown_event_id = NULL
            // and its own id IS the event id the other work orders point at, so
            // both forms must produce the same key (otherwise one failure would
            // be counted twice).
            $key = kpi_row($w, 'breakdown_event_id');
            $own = kpi_row($w, 'id', kpi_row($w, 'wo_number', null));
            $key = ($key === null || $key === '' || (int)$key === 0) ? 'ev:' . $own : 'ev:' . (int)$key;

            $start = kpi_ts(kpi_row($w, 'start_datetime', kpi_row($w, 'created_at')));
            $end   = kpi_ts(kpi_row($w, 'end_datetime'));
            if ($start === null) continue;

            if (!isset($events[$key])) {
                $events[$key] = array('key' => $key, 'start' => $start, 'end' => $end,
                                      'open' => ($end === null), 'intervals' => array(),
                                      'work_orders' => array());
            }

            $e = &$events[$key];
            if ($start < $e['start']) $e['start'] = $start;
            if ($end === null) {
                $e['open'] = true;
                $e['end']  = null;
            } elseif ($e['end'] !== null && $end > $e['end']) {
                $e['end'] = $end;
            } elseif ($e['end'] === null && !$e['open']) {
                $e['end'] = $end;
            }

            $e['intervals'][]   = array($start, $end);
            $e['work_orders'][] = $own;
            unset($e);
        }

        // a stable order makes the report reproducible
        ksort($events);
        return $events;
    }
}

if (!function_exists('kpi_is_breakdown_row')) {
    /** The work-order classification, through wo_helper when it is loaded. */
    function kpi_is_breakdown_row($row)
    {
        if (function_exists('wo_is_breakdown')) return wo_is_breakdown($row);

        $flag = kpi_row($row, 'is_breakdown');
        if ($flag !== null && $flag !== '') return ((int)$flag) === 1;

        return strcasecmp(trim((string)kpi_row($row, 'type', '')), 'Corrective Maintenance') === 0;
    }
}

if (!function_exists('kpi_days_down')) {
    /**
     * D4 — Number of Days Down of ONE event: the calendar days from the
     * breakdown date to the restoration date, or to the reporting period's last
     * day while the machine is still down. A repair finished the same day counts
     * as 1 day, and the figure is never capped by the period: a machine that has
     * been down for months keeps counting (the snapshot freezes the number at
     * publish time).
     *
     * @param  mixed    $start   breakdown date/time
     * @param  mixed    $end     restoration date/time (null while still down)
     * @param  mixed    $cutoff  last day of the reporting period
     * @return int days (>= 1)
     */
    function kpi_days_down($start, $end, $cutoff)
    {
        $s = kpi_day_start($start);
        if ($s === null) return 0;

        $cut = kpi_day_start($cutoff);
        $fin = kpi_ts($end);
        $fin = ($fin === null) ? $cut : min(kpi_day_start($fin), $cut);
        if ($fin === null) return 0;

        $days = (int)round(($fin - $s) / 86400);
        return max(1, $days);
    }
}

if (!function_exists('kpi_events_days_down')) {
    /** Σ days down over the events that touch the period. */
    function kpi_events_days_down(array $events, $period_start, $period_end, $cutoff)
    {
        $days = 0;
        $from = kpi_ts($period_start);
        $to   = kpi_ts($period_end);

        foreach ($events as $e) {
            if (!kpi_event_touches($e, $from, $to)) continue;
            $days += kpi_days_down($e['start'], $e['open'] ? null : $e['end'], $cutoff);
        }
        return $days;
    }
}

if (!function_exists('kpi_event_touches')) {
    /** Did any part of the event fall inside the period (or was it still open)? */
    function kpi_event_touches($event, $from, $to)
    {
        if ($event['open']) {
            return $from === null || $event['start'] < $to;      // still down going into the period
        }
        foreach ($event['intervals'] as $iv) {
            $s = kpi_ts($iv[0]);
            $e = kpi_ts($iv[1]);
            if ($e === null) $e = $to;
            if ($s === null || $e === null) continue;
            if ($e > $from && $s < $to) return true;
        }
        return false;
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 |  W1.3 — derived KPIs
 * ───────────────────────────────────────────────────────────────────────── */

if (!function_exists('kpi_availability')) {
    /**
     * Availability over a period: (required − downtime) ÷ required.
     *
     * `required` is the open time that was NOT planned maintenance (open hours
     * − preventive labour), which is what the approved workbook computes and
     * equals MTBF ÷ (MTBF + MTTR) whenever a machine had failures.
     *
     * @return float|null null when there was no required time at all
     */
    function kpi_availability($required_hours, $downtime_hours)
    {
        $required = (float)$required_hours;
        if ($required <= 0) return null;

        $v = ($required - (float)$downtime_hours) / $required;
        return round(max(0.0, min(1.0, $v)), 6);
    }
}

if (!function_exists('kpi_mttr')) {
    /** MTTR = downtime ÷ number of breakdowns; null when there was no failure. */
    function kpi_mttr($downtime_hours, $breakdowns)
    {
        $breakdowns = (int)$breakdowns;
        if ($breakdowns <= 0) return null;
        return round((float)$downtime_hours / $breakdowns, 4);
    }
}

if (!function_exists('kpi_mtbf')) {
    /**
     * MTBF = (required − downtime) ÷ number of breakdowns.
     *
     * With no failure in the period the figure is undefined, and the business
     * rule (`settings.mtbf_zero_failure_mode`, OPEN-3) decides what is shown:
     *   'dash'   → null, the report prints "—"          (default)
     *   'uptime' → the whole required time (the machine never stopped)
     *
     * @return float|null
     */
    function kpi_mtbf($required_hours, $downtime_hours, $breakdowns, $zero_mode = 'dash')
    {
        $breakdowns = (int)$breakdowns;
        if ($breakdowns <= 0) {
            return strtolower((string)(trim((string)$zero_mode))) === 'uptime' ? round((float)$required_hours, 4) : null;
        }
        return round(((float)$required_hours - (float)$downtime_hours) / $breakdowns, 4);
    }
}

if (!function_exists('kpi_analysis')) {
    /** Variance to target, in percentage points of availability (e.g. −0.3175). */
    function kpi_analysis($availability, $target)
    {
        if ($availability === null || $target === null || $target === '') return null;
        return round((float)$availability - (float)$target, 4);
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 |  One machine, one period — the function the report calls
 * ───────────────────────────────────────────────────────────────────────── */

if (!function_exists('kpi_machine_period')) {
    /**
     * Every W36 figure for ONE machine over ONE period.
     *
     * @param  array $wo_rows     work orders of the machine (any period)
     * @param  array $session_rows labour sessions of those work orders
     *                            (wo_id + hours_worked + optional start_datetime)
     * @param  array $opts        week_start, week_end, open_hours, daily_open_hours,
     *                            breakdown_count_mode ('start_in_period'|'in_period'),
     *                            mtbf_zero_failure_mode ('dash'|'uptime'),
     *                            snapshot (defaults to week_end)
     * @return array
     */
    function kpi_machine_period(array $wo_rows, array $session_rows, array $opts)
    {
        $week_start = kpi_ts($opts['week_start']);
        $week_end   = kpi_ts($opts['week_end']);
        if ($week_start === null || $week_end === null || $week_end <= $week_start) {
            return array('error' => 'the reporting period is not a valid range');
        }

        $days       = isset($opts['days']) && $opts['days'] ? $opts['days'] : kpi_day_list($week_start, 7);
        $day_count  = count($days);
        $open_hours = isset($opts['open_hours'])
            ? (float)$opts['open_hours']
            : (isset($opts['daily_open_hours']) ? (float)$opts['daily_open_hours'] * $day_count : 24.0 * $day_count);
        $daily_open = isset($opts['daily_open_hours']) ? (float)$opts['daily_open_hours'] : 24.0;

        $count_mode  = isset($opts['breakdown_count_mode']) ? $opts['breakdown_count_mode'] : 'start_in_period';
        $zero_mode   = isset($opts['mtbf_zero_failure_mode']) ? $opts['mtbf_zero_failure_mode'] : 'dash';
        $snapshot    = isset($opts['snapshot']) ? kpi_ts($opts['snapshot']) : null;
        $time_cutoff = $snapshot === null ? $week_end : min($snapshot, $week_end);   // downtime is the week's

        // Days down (D4) is measured to the restoration, or to the day the report
        // is frozen: the period's last day by default, later when an explicit
        // snapshot date is passed (a machine still down keeps counting).
        $last_day   = $week_end - 86400;
        $day_cutoff = date('Y-m-d', $snapshot === null ? $last_day : max($last_day, kpi_day_start($snapshot)));

        // ── events and downtime
        $events = kpi_breakdown_events($wo_rows, $week_end);

        $all_intervals = array();
        foreach ($events as $e) $all_intervals = array_merge($all_intervals, $e['intervals']);

        $downtime = kpi_union_hours($all_intervals, $week_start, $time_cutoff);
        $per_day  = kpi_split_days($all_intervals, $days);

        // ── breakdowns: one per distinct event that started inside the period
        $breakdowns = 0;
        foreach ($events as $e) {
            $in_period = ($e['start'] >= $week_start && $e['start'] < $week_end);
            $carried   = kpi_event_touches($e, $week_start, $week_end) && $e['start'] < $week_start;
            if ($in_period) $breakdowns++;
            elseif ($carried && $count_mode !== 'start_in_period') $breakdowns++;
        }

        // ── days down (D4), never capped by the period
        $days_down = kpi_events_days_down($events, $week_start, $week_end, $day_cutoff);

        // ── preventive labour: actual hours on preventive work orders (D2)
        $preventive_by_day = array_fill_keys($days, 0.0);
        $preventive        = 0.0;
        $wo_index          = array();
        foreach ($wo_rows as $w) {
            $id = kpi_row($w, 'id', kpi_row($w, 'wo_number', null));
            if ($id !== null) $wo_index[(string)$id] = $w;
        }
        foreach ($session_rows as $s) {
            $wo_id = kpi_row($s, 'wo_id', kpi_row($s, 'work_order_id', null));
            $wo    = ($wo_id !== null && isset($wo_index[(string)$wo_id])) ? $wo_index[(string)$wo_id] : null;

            // a session without its work order cannot be classified → not preventive
            if ($wo === null || kpi_is_breakdown_row($wo)) continue;

            $bucket = function_exists('wo_bucket') ? wo_bucket($wo) : 'Other';
            if ($bucket !== 'Preventive') continue;

            $hrs = (float)kpi_row($s, 'hours_worked', 0);
            if ($hrs <= 0) continue;
            $preventive += $hrs;

            $when = kpi_ts(kpi_row($s, 'start_datetime', kpi_row($s, 'end_datetime')));
            if ($when !== null) {
                $day = date('Y-m-d', $when);
                if (isset($preventive_by_day[$day])) $preventive_by_day[$day] += $hrs;
            }
        }

        // ── derived
        $required = $open_hours - $preventive;
        $per_day_out = array();
        foreach ($days as $day) {
            $pt = round($preventive_by_day[$day], 4);
            $dt = round(isset($per_day[$day]) ? $per_day[$day] : 0.0, 4);
            $req = round($daily_open - $pt, 4);

            $failures = 0;
            $day_start = kpi_ts($day . ' 00:00:00');
            foreach ($events as $e) {
                if ($e['start'] >= $day_start && $e['start'] < $day_start + 86400) $failures++;
            }

            $per_day_out[$day] = array(
                'date'             => $day,
                'preventive_hours' => $pt,
                'downtime_hours'   => $dt,
                'failures'         => $failures,
                'required_hours'   => $req,
                'availability'     => kpi_availability($req, $dt),
            );
        }

        return array(
            'period' => array('start' => date('Y-m-d', $week_start), 'end' => date('Y-m-d', $week_end - 1),
                              'days' => $day_count, 'open_hours' => round($open_hours, 4)),
            'daily'      => $per_day_out,
            'events'     => $events,
            'breakdowns' => $breakdowns,
            'days_down'  => $days_down,
            'hours_down' => round($downtime, 4),
            'downtime_hours'   => round($downtime, 4),
            'preventive_hours' => round($preventive, 4),
            // the report prints "REQUIRED TIME" (BI), the engine's own name is
            // required_hours — both are exposed so callers need no mapping
            'required_hours'   => round($required, 4),
            'required_time_hours' => round($required, 4),
            'mttr_hours' => kpi_mttr($downtime, $breakdowns),
            'mtbf_hours' => kpi_mtbf($required, $downtime, $breakdowns, $zero_mode),
            'availability' => kpi_availability($required, $downtime),
            // "has data" = the week has something to say about this machine: a
            // breakdown event, preventive labour, or ANY work order or labour record
            // in the week.  The narrow version (events or preventive hours only) made
            // the `no_records` note contradict itself — a machine whose week was a
            // corrective repair (no failure, no PM) was told it had "no work order or
            // labour record in this week" while its work order sat in the window.
            'has_data'     => !empty($events) || $preventive > 0
                              || !empty($wo_rows) || !empty($session_rows),
        );
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 |  W1.4 — roll-ups (group, per-type block, fleet row)
 |
 |  Capacity-weighted, never a plain average of percentages (BR-W18/BR-W21):
 |    availability(g) = (Σ RT − Σ DT) / Σ RT
 |    MTTR(g)         = Σ DT / Σ F        · blank when Σ F = 0
 |    MTBF(g)         = (Σ RT − Σ DT) / Σ F
 |    target(g)       = Σ (target · RT) / Σ RT   (reduces to the mean when the
 |                      members share the same capacity, which is why the
 |                      sample's plain AVERAGE() looked right)
 * ───────────────────────────────────────────────────────────────────────── */

if (!function_exists('kpi_rollup')) {
    /**
     * Aggregate a set of machine roll-ups into one group / block / fleet row.
     *
     * Two different readings of "downtime" travel together and are never mixed
     * up (OPEN-6):
     *   days_down    — the **sum** of the members' days down (the value the
     *                  approved sheet's action plan prints: ADT 010 = 5 and
     *                  ADT 018 = 13 give the ADTs 18);
     *   machines_down — how many members had any downtime at all (the same two
     *                  machines give 2).
     * Both are exposed so a comparison can be shown; the policy question of
     * which one a new column would carry is a business decision, not a default.
     *
     * @param  array  $members list of machine 'weekly' arrays (see
     *                         kpi_machine_period + kpi_target_weekly)
     * @param  string $zero_mode settings.mtbf_zero_failure_mode
     * @return array
     */
    function kpi_rollup(array $members, $zero_mode = 'dash')
    {
        $out = array(
            'machines' => count($members), 'machines_down' => 0,
            'preventive_hours' => 0.0, 'required_time_hours' => 0.0,
            'downtime_hours' => 0.0, 'hours_down' => 0.0, 'breakdowns' => 0, 'days_down' => 0,
            'target_weight' => 0.0, 'target_sum' => 0.0,
            'mttr_hours' => null, 'mtbf_hours' => null, 'availability' => null,
            'weekly_target' => null, 'analysis_vs_target' => null,
        );

        foreach ($members as $m) {
            $rt = isset($m['required_time_hours']) ? (float)$m['required_time_hours'] : 0.0;
            $dt = isset($m['downtime_hours']) ? (float)$m['downtime_hours'] : 0.0;

            $out['preventive_hours']  += isset($m['preventive_hours']) ? (float)$m['preventive_hours'] : 0.0;
            $out['required_time_hours'] += $rt;
            $out['downtime_hours']    += $dt;
            $out['hours_down']        += isset($m['hours_down']) ? (float)$m['hours_down'] : $dt;
            $out['breakdowns']        += isset($m['breakdowns']) ? (int)$m['breakdowns'] : 0;
            $out['days_down']         += isset($m['days_down']) ? (int)$m['days_down'] : 0;
            if ($dt > 0) $out['machines_down']++;        // OPEN-6: the count, not the sum

            if (isset($m['weekly_target']) && $m['weekly_target'] !== null) {
                $weight = $rt > 0 ? $rt : 1.0;            // a member with no capacity still counts once
                $out['target_sum']  += (float)$m['weekly_target'] * $weight;
                $out['target_weight'] += $weight;
            }
        }

        $out['availability'] = kpi_availability($out['required_time_hours'], $out['downtime_hours']);
        $out['mttr_hours']   = kpi_mttr($out['downtime_hours'], $out['breakdowns']);
        $out['mtbf_hours']   = kpi_mtbf($out['required_time_hours'], $out['downtime_hours'], $out['breakdowns'], $zero_mode);

        if ($out['target_weight'] > 0) {
            $out['weekly_target'] = round($out['target_sum'] / $out['target_weight'], 4);
            $out['analysis_vs_target'] = kpi_analysis($out['availability'], $out['weekly_target']);
        }

        $out['preventive_hours']   = round($out['preventive_hours'], 4);
        $out['required_time_hours'] = round($out['required_time_hours'], 4);
        $out['downtime_hours']      = round($out['downtime_hours'], 4);
        $out['hours_down']          = round($out['hours_down'], 4);
        unset($out['target_sum'], $out['target_weight']);

        return $out;
    }
}

if (!function_exists('kpi_rollup_daily')) {
    /**
     * The same aggregation per day (the report's day columns for a group row or
     * the fleet row 4). $days is the report's seven dates.
     *
     * @param  array $machine_dailies list of machine 'daily' maps (date => row)
     * @param  array $days
     */
    function kpi_rollup_daily(array $machine_dailies, array $days, $zero_mode = 'dash')
    {
        $out = array();

        foreach ($days as $day) {
            $pt = 0.0; $dt = 0.0; $req = 0.0; $fail = 0; $weight = 0.0; $tsum = 0.0; $n = 0;

            foreach ($machine_dailies as $daily) {
                if (!isset($daily[$day])) continue;
                $row = $daily[$day];
                $n++;
                $pt   += isset($row['preventive_hours']) ? (float)$row['preventive_hours'] : 0.0;
                $dt   += isset($row['downtime_hours']) ? (float)$row['downtime_hours'] : 0.0;
                $req  += isset($row['required_hours']) ? (float)$row['required_hours'] : 0.0;
                $fail += isset($row['failures']) ? (int)$row['failures'] : 0;

                if (isset($row['target']) && $row['target'] !== null) {
                    $w = isset($row['required_hours']) && (float)$row['required_hours'] > 0
                        ? (float)$row['required_hours'] : 1.0;
                    $tsum += (float)$row['target'] * $w;
                    $weight += $w;
                }
            }

            $out[$day] = array(
                'date'             => $day,
                'machines'         => $n,
                'preventive_hours' => round($pt, 4),
                'downtime_hours'   => round($dt, 4),
                'required_hours'   => round($req, 4),
                'failures'         => $fail,
                'availability'     => kpi_availability($req, $dt),
                'mttr_hours'       => kpi_mttr($dt, $fail),
                'mtbf_hours'       => kpi_mtbf($req, $dt, $fail, $zero_mode),
                'target'           => $weight > 0 ? round($tsum / $weight, 4) : null,
            );
        }
        return $out;
    }
}

if (!function_exists('kpi_target_weekly')) {
    /**
     * BR-W17 — the machine's weekly target is the mean of its seven daily
     * targets, and the analysis is the availability minus that target.
     *
     * @param  array $daily_rows machine day blocks carrying 'target'
     * @return array ['weekly_target' => float|null, 'analysis_vs_target' => float|null]
     */
    function kpi_target_weekly(array $daily_rows, $availability)
    {
        $sum = 0.0; $n = 0;
        foreach ($daily_rows as $row) {
            if (isset($row['target']) && $row['target'] !== null) {
                $sum += (float)$row['target'];
                $n++;
            }
        }
        if ($n === 0) return array('weekly_target' => null, 'analysis_vs_target' => null);

        $weekly = round($sum / $n, 4);
        return array('weekly_target' => $weekly, 'analysis_vs_target' => kpi_analysis($availability, $weekly));
    }
}
