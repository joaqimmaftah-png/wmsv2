<?php
/**
 * Reports — the reporting home for Supervisor / Manager (roles 1, 4).
 *
 * This page is a *report catalogue*, not a second operational dashboard.  Its
 * job is to answer, in order:
 *
 *   1. What period am I reporting on, and how fresh is the data?
 *   2. What is the current operational situation?        (snapshot)
 *   3. What needs my attention, and where do I act on it? (exceptions)
 *   4. Is reliability moving in the right direction?      (one trend)
 *   5. Which report do I open, for which period?          (the library)
 *
 * Every number comes from the same models the report itself uses
 * (Dashboard_model -> Kpi_engine_model, Store_model, Materials_model,
 * W36_report_model).  Nothing is invented: an unreadable database shows an
 * honest "unavailable" state, MTTR/MTBF show a dash when the window had no
 * failures, and the one chart degrades to a "no data" message.
 *
 * The library is a set of ordinary GET forms.  One form per report carries its
 * period to the view AND to every export, so a chosen date/month/week is never
 * lost and the page works with JavaScript disabled.  No dead links, no
 * duplicate ids, no colour-only status, one h1.
 *
 * W36 publication follows a five-state ladder, computed in the controller
 * from the configured reporting week's own end (the only deadline the system
 * defines) and shown in words everywhere:
 *
 *   current, before the deadline  → Draft — Week in progress  → Informational
 *   current, deadline approaching → Draft — Publication due soon → Warning
 *   current, deadline passed      → Unpublished — Action required → High
 *   previous, published           → Published                  → No action
 *   previous, not published       → Unpublished — Overdue       → High
 *
 * The open week's draft stays visible at all times; it only becomes an
 * exception at the Warning / High steps.  A missing snapshot table or an
 * unreadable database is reported as unknown, never as a known draft.
 */

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$bu = function ($p) { return base_url($p); };

/* ── Context ─────────────────────────────────────────────────────────── */

$today      = date('Y-m-d');
$this_month = date('Y-m');
$this_year  = (int)date('Y');

$week_start = isset($report_week_start) && $report_week_start ? $report_week_start : $today;
$week_end   = isset($report_week_end) && $report_week_end
    ? $report_week_end : date('Y-m-d', strtotime($week_start . ' +6 days'));
$iso_week   = isset($report_iso_week) && $report_iso_week
    ? $report_iso_week : date('o-\WW', strtotime($week_start));
$week_short = 'W' . (int)date('W', strtotime($week_start));
$week_range = date('d M', strtotime($week_start)) . ' – ' . date('d M Y', strtotime($week_end));

$report_name  = isset($weekly_report_name) && $weekly_report_name !== ''
    ? $weekly_report_name : $week_short;


/* ── Telemetry (guarded, honest defaults) ────────────────────────────── */

$t = isset($telemetry) && is_array($telemetry) ? $telemetry : array();
$defaults = array(
    'available' => false, 'availability_pct' => 100.0, 'avail_target_pct' => 88.0,
    'avail_machines_with' => 0, 'avail_from' => null, 'avail_to' => null,
    'mttr_30d' => 0.0, 'mtbf_30d' => 0.0, 'breakdowns_30d' => 0, 'downtime_30d' => 0.0,
    'availability_trend' => array(), 'zero_failure_mode' => 'dash',
    'overdue_pm' => 0, 'pm_due_30d' => 0,
    'fleet_total' => 0, 'machines_down' => 0, 'equipment_list' => array(),
    'reorder_count' => 0, 'out_of_stock_count' => 0, 'reorder_units' => 0,
    'store_available' => false, 'targets_ready' => false,
    'w36_snapshot' => null,
    'w36_pub' => array(
        'checkable' => false, 'state' => 'unknown', 'published_at' => null,
        'warning_from' => null, 'deadline' => null, 'previous' => null,
        'older_count' => 0, 'older_latest' => null, 'window' => 4,
    ),
    'weekly_open_hours' => 168.0, 'daily_open_hours' => 24.0,
);
$t = array_merge($defaults, $t);
// The publication ladder is nested; merge it too so a partial telemetry
// payload can never leave a state/key undefined.
$t['w36_pub'] = array_merge(
    $defaults['w36_pub'],
    isset($t['w36_pub']) && is_array($t['w36_pub']) ? $t['w36_pub'] : array()
);
$live = !empty($t['available']);

$target       = (float)$t['avail_target_pct'];
$avail        = (float)$t['availability_pct'];
$watch_band   = $target - 10;
$avail_tone   = $avail >= $target ? 'neutral' : ($avail >= $watch_band ? 'warn' : 'crit');
$avail_delta  = round($avail - $target, 1);
$avail_delta_txt = ($avail_delta >= 0 ? '+' : '') . number_format($avail_delta, 1) . ' pp';

$has_failures = (int)$t['breakdowns_30d'] > 0;
// settings.mtbf_zero_failure_mode = 'uptime' makes MTBF meaningful even with
// no failures; 'dash' leaves it undefined.  MTTR is always undefined without
// a repair, so it keeps a dash in either mode.
$mtbf_defined = $has_failures || $t['zero_failure_mode'] === 'uptime';
$mttr_txt = $has_failures ? number_format((float)$t['mttr_30d'], 1) : '—';
$mtbf_txt = $mtbf_defined ? number_format((float)$t['mtbf_30d'], 1) : '—';
$reliability_meta = number_format((float)$t['downtime_30d'], 1) . ' h downtime';
if ($mtbf_defined) {
    $reliability_meta .= ' · MTTR ' . ($has_failures ? $mttr_txt . ' h' : '—') . ' · MTBF ' . $mtbf_txt . ' h';
} else {
    $reliability_meta .= ' · no breakdowns in window';
}
$store_note = $t['store_available'] ? '' : ' · register columns not installed';

$total_machines = (int)$t['fleet_total'];
$with_data      = (int)$t['avail_machines_with'];
$covered        = $total_machines > 0 ? min($with_data, $total_machines) : 0;

$window_from = !empty($t['avail_from']) ? $t['avail_from'] : null;
$window_to   = !empty($t['avail_to'])   ? $t['avail_to']   : null;
$window_txt  = $window_from && $window_to
    ? date('d M', strtotime($window_from)) . ' – ' . date('d M Y', strtotime($window_to))
    : 'last 30 days';

/* ── W36 publication ladder — states are computed in Reports::index() ─── */
$pub              = $t['w36_pub'];
$pub_checkable    = !empty($pub['checkable']);
$pub_state        = $live ? (string)$pub['state'] : 'unknown';
$pub_window       = max(1, (int)$pub['window']);
$pub_date         = '';
if (!empty($pub['published_at'])) {
    $pub_date = date('d M Y', strtotime($pub['published_at']));
}
$deadline_txt = !empty($pub['deadline'])
    ? date('d M Y', strtotime($pub['deadline']))
    : date('d M Y', strtotime($week_end));
$pub_previous = ($live && $pub_checkable && is_array($pub['previous'])
    && isset($pub['previous']['state'], $pub['previous']['start'],
             $pub['previous']['end'], $pub['previous']['iso_label']))
    ? $pub['previous'] : null;
$pub_older_count = ($live && $pub_checkable) ? (int)$pub['older_count'] : 0;
// Total includes the just-closed week when it is also unpublished, so the
// "N of the last 4 completed weeks" sentence can never understate the gap.
$pub_unpublished_total = $pub_older_count
    + ($pub_previous !== null && $pub_previous['state'] !== 'published' ? 1 : 0);
$pub_older = ($pub_older_count > 0 && is_array($pub['older_latest'])
    && isset($pub['older_latest']['start'], $pub['older_latest']['end'], $pub['older_latest']['iso_label']))
    ? $pub['older_latest'] : null;

/* One vocabulary for the whole page: display text, priority word, tone and
   short badge.  The words are always present — colour is never the signal. */
$pub_states = array(
    'unknown' => array(
        'display' => 'Status unavailable', 'priority' => 'Unknown',
        'class' => 'is-unknown', 'badge' => 'Status unavailable', 'badge_tone' => 'neutral',
    ),
    'published' => array(
        'display' => 'Published' . ($pub_date ? ' ' . $pub_date : ''), 'priority' => 'No action',
        'class' => 'is-ok', 'badge' => 'Published' . ($pub_date ? ' ' . $pub_date : ''), 'badge_tone' => 'ok',
    ),
    'draft_in_progress' => array(
        'display' => 'Draft — Week in progress', 'priority' => 'Informational',
        'class' => 'is-draft', 'badge' => 'Draft · in progress', 'badge_tone' => 'neutral',
    ),
    'draft_due_soon' => array(
        'display' => 'Draft — Publication due soon', 'priority' => 'Warning',
        'class' => 'is-due', 'badge' => 'Draft · due soon', 'badge_tone' => 'warn',
    ),
    'unpublished_action_required' => array(
        'display' => 'Unpublished — Action required', 'priority' => 'High',
        'class' => 'is-late', 'badge' => 'Unpublished · action required', 'badge_tone' => 'crit',
    ),
);
if (!isset($pub_states[$pub_state])) $pub_state = 'unknown';
$pub_meta = $pub_states[$pub_state];

/* The library row explains the current state AND the previous week's state
   in words.  An unreadable database is never reported as a known "Draft". */
$note = array();
if (!$live) {
    $note[] = 'Publication status cannot be read on this request. Open the report to check it.';
} else {
    if ($pub_state === 'published') {
        $note[] = 'Published' . ($pub_date ? ' ' . $pub_date : '') . ' — the figures are frozen; no action needed.';
    } elseif ($pub_state === 'draft_in_progress') {
        $note[] = 'Draft — week in progress. The figures keep changing; publication is due by the end of '
                . $iso_week . ' on ' . $deadline_txt
                . '. It becomes a Warning on the final day and High once the deadline passes.';
    } elseif ($pub_state === 'draft_due_soon') {
        $note[] = 'Draft — publication due soon. ' . $iso_week . ' ends ' . $deadline_txt
                . '; review the draft now so it can be published on time.';
    } elseif ($pub_state === 'unpublished_action_required') {
        $note[] = 'Unpublished — action required. The publication deadline has passed; publish the frozen report now.';
    }
    if (!$pub_checkable) {
        $note[] = 'Publishing is unavailable because the snapshot table is not installed, so no publication reminder is raised.';
    } elseif ($pub_previous !== null) {
        if ($pub_previous['state'] === 'published') {
            $note[] = 'Last week (' . $pub_previous['iso_label'] . ') was published — no action needed.';
        } else {
            $note[] = 'Last week (' . $pub_previous['iso_label'] . ') is unpublished — Action required (see “Requires attention”).';
        }
    }
}
$w36_row_note = implode(' ', $note);

/* ── The 30-day series for the one chart (never fabricated) ──────────── */

$trend_labels = array();
$trend_vals   = array();
if (!empty($t['availability_trend']) && is_array($t['availability_trend'])) {
    foreach ($t['availability_trend'] as $p) {
        $d = isset($p['date']) ? $p['date'] : null;
        if (!$d) continue;
        $trend_labels[] = date('d M', strtotime($d));
        $trend_vals[]   = isset($p['pct']) ? round((float)$p['pct'], 1) : 100.0;
    }
}
$has_trend = count($trend_vals) >= 2;

/* A server-rendered SVG line chart.  It needs no chart library, prints on
   paper, and is never a blank canvas when a CDN is unreachable.  Coordinates
   are 0–100 on both axes, so the single series and the target line stay honest
   against a fixed 0–100% scale at every viewport width. */
$chart_pts  = array();
$chart_poly = '';
if ($has_trend) {
    $n = count($trend_vals);
    foreach ($trend_vals as $i => $v) {
        $cx = $n > 1 ? ($i / ($n - 1)) * 100 : 0;
        $cy = 100 - max(0, min(100, (float)$v));
        $chart_pts[] = array('x' => round($cx, 3), 'y' => round($cy, 3),
                             'v' => (float)$v, 'd' => $trend_labels[$i]);
    }
    $parts = array();
    foreach ($chart_pts as $p) $parts[] = $p['x'] . ',' . $p['y'];
    $chart_poly = implode(' ', $parts);
}
$chart_target_y = round(100 - max(0, min(100, $target)), 3);
$chart_step   = $has_trend ? 100 / max(1, count($chart_pts) - 1) : 100;
$latest_val   = $has_trend ? end($trend_vals) : null;
$latest_date  = $has_trend ? end($trend_labels) : '';

/* ── Exceptions: genuine, severity-ranked, never colour-only ─────────── */

$exceptions = array();
if ($live) {
    if ((int)$t['out_of_stock_count'] > 0) {
        $exceptions[] = array(
            'tone' => 'crit', 'label' => 'Items out of stock',
            'detail' => (int)$t['out_of_stock_count'] . ' stock item(s) at zero balance',
            'count' => (int)$t['out_of_stock_count'],
            'href' => $bu('reports/reorder'), 'action' => 'Re-order',
        );
    }
    $below_min = (int)$t['reorder_count'] - (int)$t['out_of_stock_count'];
    if ($below_min > 0) {
        $exceptions[] = array(
            'tone' => 'warn', 'label' => 'Below minimum stock',
            'detail' => $below_min . ' more item(s) at or below their minimum',
            'count' => $below_min,
            'href' => $bu('reports/reorder'), 'action' => 'Re-order',
        );
    }
    if ((int)$t['overdue_pm'] > 0) {
        $exceptions[] = array(
            'tone' => 'warn', 'label' => 'Overdue preventive maintenance',
            'detail' => (int)$t['overdue_pm'] . ' active PM schedule(s) past their due date',
            'count' => (int)$t['overdue_pm'],
            'href' => $bu('reports/availability_forecast?horizon=90'), 'action' => 'Forecast',
        );
    }
    if ((int)$t['machines_down'] > 0) {
        $exceptions[] = array(
            'tone' => 'info', 'label' => 'Machines out of service',
            'detail' => (int)$t['machines_down'] . ' of ' . $total_machines . ' machines currently down',
            'count' => (int)$t['machines_down'],
            'href' => $bu('reports/workshop_daily?date=' . $today), 'action' => 'Daily report',
        );
    }
    /* Publication ladder → exceptions.  The Informational draft is status
       only; Warning, High and Overdue are actionable and word-labelled.
       Every destination is an exact week-start date, not an ISO label, so a
       non-Monday report_week_start_day still resolves to the right week. */
    if ($pub_checkable && $pub_state === 'draft_due_soon') {
        $exceptions[] = array(
            'tone' => 'warn',
            'label' => $iso_week . ' weekly report — Publication due soon',
            'detail' => 'Reporting week ends ' . $deadline_txt
                      . ' · review the draft now so it can be published on time',
            'count' => null,
            'href' => $bu('reports/weekly_w36?week_start=' . $week_start),
            'action' => 'Review draft',
        );
    }
    if ($pub_checkable && $pub_state === 'unpublished_action_required') {
        $exceptions[] = array(
            'tone' => 'high',
            'label' => $iso_week . ' weekly report — Unpublished — Action required',
            'detail' => 'Publication deadline was ' . $deadline_txt . ' · publish the frozen report now',
            'count' => null,
            'href' => $bu('reports/weekly_w36?week_start=' . $week_start),
            'action' => 'Review & publish',
        );
    }
    if ($pub_previous !== null && $pub_previous['state'] !== 'published') {
        $exceptions[] = array(
            'tone' => 'high',
            'label' => $pub_previous['iso_label'] . ' weekly report is unpublished — Action required',
            'detail' => 'Week ended ' . date('d M Y', strtotime($pub_previous['end']))
                      . ' · deadline passed · publish to freeze the figures',
            'count' => null,
            'href' => $bu('reports/weekly_w36?week_start=' . $pub_previous['start']),
            'action' => 'Review & publish',
        );
    }
    if ($pub_older !== null) {
        $exceptions[] = array(
            'tone' => 'high',
            'label' => $pub_older_count . ' earlier weekly report' . ($pub_older_count === 1 ? '' : 's')
                     . ' unpublished — Overdue',
            'detail' => 'Most recent: ' . $pub_older['iso_label'] . ' ended '
                      . date('d M Y', strtotime($pub_older['end']))
                      . ' · ' . $pub_unpublished_total . ' of the last ' . $pub_window
                      . ' completed weeks are unpublished',
            'count' => $pub_older_count,
            'href' => $bu('reports/weekly_w36?week_start=' . $pub_older['start']),
            'action' => 'Review & publish',
        );
    }
}
$rank = array('crit' => 0, 'high' => 1, 'warn' => 2, 'info' => 3);
$severity_words = array('crit' => 'Critical', 'high' => 'High', 'warn' => 'Attention', 'info' => 'Information');
usort($exceptions, function ($a, $b) use ($rank) {
    return $rank[$a['tone']] - $rank[$b['tone']];
});

// The all-clear sentence states exactly what was checked — including when
// publication could not be checked at all — so it can never imply more than
// the data supports.
$calm_text = !$pub_checkable
    ? 'No out-of-stock items, overdue PM or out-of-service machines were found. '
      . 'Weekly publication could not be checked on this database.'
    : 'Stock, PM, machine status and the ' . $pub_window
      . ' most recent completed weekly reports show nothing that needs action.'
      . ($pub_previous !== null && $pub_previous['state'] === 'published'
          ? ' Last week (' . $pub_previous['iso_label'] . ') was published — no action needed.' : '');

/* ── Year / horizon option builders ─────────────────────────────────── */

$year_opts = function ($from, $to) {
    $o = array();
    for ($y = (int)$from; $y >= (int)$to; $y--) $o[(string)$y] = (string)$y;
    return $o;
};
$years_back  = $year_opts($this_year, 2020);
$years_plan  = $year_opts($this_year + 1, 2020);
$years_store = $year_opts($this_year, $this_year - 4);

/* ══════════════════════════════════════════════════════════════════
   REPORT LIBRARY SPECIFICATION
   One GET form per row: the chosen period reaches the view and every export.
   ══════════════════════════════════════════════════════════════════ */

$sections = array(
    array(
        'id' => 'operations', 'num' => '01',
        'title' => 'Daily & weekly operations',
        'desc' => 'What happened on the floor, and the weekly number the business signs off.',
        'items' => array(
            array(
                'title' => 'Workshop Daily Report',
                'desc' => 'Fleet availability, breakdowns and field interventions, grouped by machine type.',
                'meta' => 'Daily · Screen, Excel, print',
                'form' => array(
                    'action' => 'reports/workshop_daily',
                    'control' => array('type' => 'date', 'name' => 'date', 'label' => 'Date', 'value' => $today, 'max' => $today),
                    'buttons' => array(
                        array('action' => 'reports/export_workshop_daily_xlsx', 'label' => 'Excel'),
                        array('action' => 'reports/export_workshop_daily_pdf', 'label' => 'Print', 'target' => '_blank'),
                    ),
                ),
            ),
            array(
                'title' => 'Workshop Weekly Report',
                'desc' => 'Seven-day fleet performance, target analysis and KPI charts per machine type.',
                'meta' => 'Weekly · Screen, Excel, print',
                'form' => array(
                    'action' => 'reports/workshop_weekly',
                    'control' => array('type' => 'date', 'name' => 'week_start', 'label' => 'Week from', 'value' => $week_start, 'max' => $today),
                    'buttons' => array(
                        array('action' => 'reports/export_workshop_weekly_xlsx', 'label' => 'Excel'),
                        array('action' => 'reports/export_workshop_weekly_pdf', 'label' => 'Print', 'target' => '_blank'),
                    ),
                ),
            ),
            array(
                'title' => $report_name . ' Weekly Report',
                'desc' => 'The approved weekly sheet generated from the system, with data-quality checks and a frozen publication option.',
                'meta' => 'Weekly · ' . $iso_week . ' · Screen, Excel, print',
                'badge' => $pub_meta['badge'],
                'badge_tone' => $pub_meta['badge_tone'],
                'note' => $w36_row_note,
                'featured' => true,
                'form' => array(
                    'action' => 'reports/weekly_w36',
                    'control' => array('type' => 'date', 'name' => 'week_start', 'label' => 'Week from', 'value' => $week_start),
                    'buttons' => array(
                        array('action' => 'reports/export_weekly_w36_xlsx', 'label' => 'Excel'),
                        array('action' => 'reports/export_weekly_w36_pdf', 'label' => 'Print', 'target' => '_blank'),
                    ),
                ),
            ),
            array(
                'title' => 'Availability Targets',
                'desc' => 'The daily target KPI per machine and per group, with single-day overrides.',
                'meta' => 'Weekly · Screen' . ($t['targets_ready'] ? '' : ' · targets table not installed'),
                'form' => array(
                    'action' => 'reports/weekly_targets',
                    'control' => array('type' => 'date', 'name' => 'week_start', 'label' => 'Week from', 'value' => $week_start),
                    'buttons' => array(),
                ),
            ),
            array(
                'title' => 'Daily Activity',
                'desc' => 'Work orders, team attendance and PM completions for one selected day.',
                'meta' => 'Daily · Screen, print',
                'form' => array(
                    'action' => 'reports/daily',
                    'control' => array('type' => 'date', 'name' => 'date', 'label' => 'Date', 'value' => $today, 'max' => $today),
                    'buttons' => array(
                        array('action' => 'reports/pdf_daily', 'label' => 'Print', 'target' => '_blank'),
                    ),
                ),
            ),
            array(
                'title' => 'Weekly Snapshot',
                'desc' => 'Work-order count, team performance and PM status across a selected week.',
                'meta' => 'Weekly · Screen',
                'form' => array(
                    'action' => 'reports/weekly',
                    'control' => array('type' => 'date', 'name' => 'week', 'label' => 'Week from', 'value' => $week_start),
                    'buttons' => array(
                        array('href' => 'reports/weekly', 'label' => 'This week'),
                    ),
                ),
            ),
        ),
    ),
    array(
        'id' => 'reliability', 'num' => '02',
        'title' => 'Reliability & performance',
        'desc' => 'Is the fleet becoming more reliable, and which machines need attention?',
        'items' => array(
            array(
                'title' => 'MTTR / MTBF Report',
                'desc' => 'Repair and failure-interval trend with a per-equipment reliability ranking.',
                'meta' => '3–24 months · Screen',
                'form' => array(
                    'action' => 'reports/mttr_mtbf',
                    'control' => array('type' => 'select', 'name' => 'months', 'label' => 'Window',
                        'options' => array('3' => '3 months', '6' => '6 months', '12' => '12 months', '18' => '18 months', '24' => '24 months'),
                        'selected' => '12'),
                    'buttons' => array(),
                ),
            ),
            array(
                'title' => 'PM Due Forecast',
                'desc' => 'Preventive-maintenance schedules falling due over a chosen horizon, split by machine type.',
                'meta' => '30–180 days · Screen',
                'form' => array(
                    'action' => 'reports/availability_forecast',
                    'control' => array('type' => 'select', 'name' => 'horizon', 'label' => 'Horizon',
                        'options' => array('30' => '30 days', '60' => '60 days', '90' => '90 days', '120' => '120 days', '180' => '180 days'),
                        'selected' => '90'),
                    'buttons' => array(),
                ),
            ),
            array(
                'title' => 'Monthly Summary',
                'desc' => 'KPIs, availability trend, work-order breakdown, attendance and lubricant usage for a month.',
                'meta' => 'Monthly · Screen',
                'form' => array(
                    'action' => 'reports/monthly',
                    'control' => array('type' => 'month', 'name' => 'month', 'label' => 'Month', 'value' => $this_month),
                    'buttons' => array(),
                ),
            ),
            array(
                'title' => 'Annual Report',
                'desc' => 'Full-year executive summary: monthly KPI trend, reliability ranking, top faults, lube and parts spend.',
                'meta' => 'Annual · Screen, Excel',
                'form' => array(
                    'action' => 'reports/annual_report',
                    'control' => array('type' => 'select', 'name' => 'year', 'label' => 'Year', 'options' => $years_back, 'selected' => (string)$this_year),
                    'buttons' => array(
                        array('action' => 'reports/export_annual_report_xlsx', 'label' => 'Excel'),
                    ),
                ),
            ),
            array(
                'title' => 'Individual Attendance Sheets (AML)',
                'desc' => 'Print-ready per-worker and batch AML sign-off sheets for a month or half-month.',
                'meta' => 'Monthly · Screen, PDF, Excel',
                'form' => array(
                    'action' => 'team/report',
                    'control' => array('type' => 'month', 'name' => 'month', 'label' => 'Month', 'value' => $this_month),
                    'buttons' => array(
                        array('action' => 'team/timesheets_batch', 'label' => 'All PDF', 'target' => '_blank'),
                        array('action' => 'team/timesheets_batch', 'label' => 'All XLSX', 'name' => 'format', 'value' => 'xlsx'),
                    ),
                ),
            ),
        ),
    ),
    array(
        'id' => 'planning', 'num' => '03',
        'title' => 'Maintenance planning',
        'desc' => 'What work is scheduled, and what should be stocked to carry it out.',
        'items' => array(
            array(
                'title' => 'Monthly Maintenance Plan',
                'desc' => 'PM jobs grouped by week plus all open corrective work orders, ready for a team briefing.',
                'meta' => 'Monthly · Screen, Excel',
                'form' => array(
                    'action' => 'reports/monthly_plan',
                    'control' => array('type' => 'month', 'name' => 'month', 'label' => 'Month', 'value' => $this_month),
                    'buttons' => array(
                        array('action' => 'reports/export_monthly_plan_xlsx', 'label' => 'Excel'),
                    ),
                ),
            ),
            array(
                'title' => 'Annual Maintenance Plan',
                'desc' => 'All PM jobs across twelve months with due dates, hours and a heatmap overview.',
                'meta' => 'Annual · Screen, Excel',
                'form' => array(
                    'action' => 'reports/annual_plan',
                    'control' => array('type' => 'select', 'name' => 'year', 'label' => 'Year', 'options' => $years_plan, 'selected' => (string)$this_year),
                    'buttons' => array(
                        array('action' => 'reports/export_annual_plan_xlsx', 'label' => 'Excel'),
                    ),
                ),
            ),
            array(
                'title' => 'Service Kit Recommendations',
                'desc' => 'Per-machine spare parts and lubricants derived from historical work orders and oil records.',
                'meta' => 'On demand · Screen, Excel',
                'form' => array(
                    'action' => 'reports/service_kits',
                    'buttons' => array(
                        array('action' => 'reports/export_service_kits_xlsx', 'label' => 'Excel'),
                    ),
                ),
            ),
            array(
                'title' => 'Lubricant Compliance',
                'desc' => 'PM-driven lubricant requirement against actual consumption, by month or full year.',
                'meta' => 'Monthly / annual · Screen',
                'form' => array(
                    'action' => 'reports/lube_compliance',
                    'control' => array('type' => 'month', 'name' => 'month', 'label' => 'Month', 'value' => $this_month),
                    'buttons' => array(
                        array('href' => 'reports/lube_compliance_yearly?year=' . $this_year, 'label' => 'Yearly'),
                    ),
                ),
            ),
        ),
    ),
    array(
        'id' => 'store', 'num' => '04',
        'title' => 'Store & inventory',
        'desc' => 'What moved, what we spent, and what must be bought.',
        'items' => array(
            array(
                'title' => 'Store Analytics',
                'desc' => 'Supplier spend (one total per currency) and learned lead times by route.',
                'meta' => 'Annual · Screen',
                'form' => array(
                    'action' => 'reports/store_analytics',
                    'control' => array('type' => 'select', 'name' => 'year', 'label' => 'Year', 'options' => $years_store, 'selected' => (string)$this_year),
                    'buttons' => array(),
                ),
            ),
            array(
                'title' => 'Store Issue Register (Sortie)',
                'desc' => 'Every item issued, with requisition number, allocation, receiver and full filtering.',
                'meta' => 'Date range · Screen, Excel' . $store_note,
                'form' => array(
                    'action' => 'reports/issue_register',
                    'control' => array('type' => 'range', 'label' => 'Period',
                        'from_name' => 'date_from', 'from_value' => date('Y-m-01'),
                        'to_name' => 'date_to', 'to_value' => $today),
                    'buttons' => array(
                        array('action' => 'reports/export_issue_register_xlsx', 'label' => 'Excel'),
                    ),
                ),
            ),
            array(
                'title' => 'Store Receipt Register',
                'desc' => 'Goods received by MRN / delivery note, with approved vs delivered quantity and line value.',
                'meta' => 'Date range · Screen, Excel' . $store_note,
                'form' => array(
                    'action' => 'reports/receipt_register',
                    'control' => array('type' => 'range', 'label' => 'Period',
                        'from_name' => 'date_from', 'from_value' => date('Y-m-01'),
                        'to_name' => 'date_to', 'to_value' => $today),
                    'buttons' => array(
                        array('action' => 'reports/export_receipt_register_xlsx', 'label' => 'Excel'),
                    ),
                ),
            ),
            array(
                'title' => 'Re-order Report',
                'desc' => 'Items at or below minimum stock with the calculated quantity to order and preferred supplier.',
                'meta' => 'On demand · Screen, Excel',
                'form' => array(
                    'action' => 'reports/reorder',
                    'buttons' => array(
                        array('action' => 'reports/export_reorder_xlsx', 'label' => 'Excel'),
                    ),
                ),
            ),
            array(
                'title' => 'Materials Movement',
                'desc' => 'Stock levels, receipts and issues across all inventory categories.',
                'meta' => 'On demand · Screen, Excel',
                'form' => array(
                    'action' => 'reports/materials_movement',
                    'buttons' => array(
                        array('action' => 'reports/export_materials_xlsx', 'label' => 'Excel'),
                        array('href' => 'materials/inventory', 'label' => 'Inventory list'),
                    ),
                ),
            ),
        ),
    ),
    array(
        'id' => 'assets', 'num' => '05',
        'title' => 'Assets & records',
        'desc' => 'The master fleet data and the history behind one machine.',
        'items' => array(
            array(
                'title' => 'Asset Register',
                'desc' => 'Full fleet master — serial numbers, hours, warranty status, lifetime work orders and next PM. Import and template controls live on the register itself.',
                'meta' => 'On demand · Screen, Excel, template',
                'form' => array(
                    'action' => 'reports/asset_register',
                    'buttons' => array(
                        array('action' => 'reports/export_asset_register_xlsx', 'label' => 'Excel'),
                        array('action' => 'reports/asset_template_xlsx', 'label' => 'Template'),
                    ),
                ),
            ),
            array(
                'title' => 'Equipment History',
                'desc' => 'Maintenance record, oil history and downtime log for one machine.',
                'meta' => 'On demand · Screen',
                'form' => array(
                    'action' => 'reports/equipment_history',
                    'equipment_select' => array('name' => 'id', 'label' => 'Machine', 'options' => $t['equipment_list']),
                    'buttons' => array(
                        array('href' => 'equipment', 'label' => 'Browse fleet'),
                    ),
                ),
            ),
        ),
    ),
);
?>

<style>
/* ── Reports shell — the /materials / /dashboard Slate language, scoped ── */
#reportsShell {
  --rpt-text:#0F172A; --rpt-muted:#475569; --rpt-subtle:#64748B; --rpt-faint:#94A3B8;
  --rpt-border:#E2E8F0; --rpt-border-strong:#8593A5; --rpt-rule:#EEF2F7;
  --rpt-accent:#1D4ED8; --rpt-accent-soft:#EFF6FF;
  --rpt-ok-fg:#047857;   --rpt-ok-bg:#ECFDF5;   --rpt-ok-bd:#A7F3D0;
  --rpt-warn-fg:#B45309; --rpt-warn-bg:#FFFBEB; --rpt-warn-bd:#FDE68A;
  --rpt-crit-fg:#B91C1C; --rpt-crit-bg:#FEF2F2; --rpt-crit-bd:#FECACA;
  --rpt-info-fg:#0369A1; --rpt-info-bg:#F0F9FF; --rpt-info-bd:#BAE6FD;
  color:var(--rpt-text);
  font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
  max-width:1240px;
}
#reportsShell *, #reportsShell *::before, #reportsShell *::after { box-sizing:border-box; }
.rpt-sr { position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0; }
.rpt-sr caption { position:absolute;left:0;top:0;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap; }

/* Page identity + context */
.rpt-head { display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:18px; }
.rpt-subtitle { font-size:13px;color:var(--rpt-muted);line-height:1.55;margin-top:5px;max-width:640px; }
.rpt-status { display:inline-flex;align-items:center;gap:9px;padding:7px 11px;border:1px solid var(--rpt-border);
  border-radius:9px;background:#fff;font-size:12px;color:var(--rpt-muted);text-decoration:none;white-space:nowrap; }
.rpt-status:hover { border-color:var(--rpt-border-strong);color:var(--rpt-text); }
.rpt-status b { color:var(--rpt-text);font-weight:600; }
.rpt-status .dot { width:7px;height:7px;border-radius:50%;background:var(--rpt-faint);flex-shrink:0; }
.rpt-status.is-ok .dot { background:var(--rpt-ok-fg); }
.rpt-status.is-draft .dot { background:var(--rpt-info-fg); }
.rpt-status.is-due .dot { background:var(--rpt-warn-fg); }
.rpt-status.is-late .dot { background:var(--rpt-crit-fg); }
.rpt-status.is-unknown .dot { background:var(--rpt-faint); }
/* The display text and its priority word are two lines, not colour alone. */
.rpt-status-txt { display:flex;flex-direction:column;gap:2px;line-height:1.3;min-width:0; }
.rpt-status-display { min-width:0;overflow-wrap:anywhere; }
.rpt-status-pri { font-size:10px;font-weight:700;letter-spacing:.055em;text-transform:uppercase;color:var(--rpt-subtle); }
.rpt-status.is-ok .rpt-status-pri { color:var(--rpt-ok-fg); }
.rpt-status.is-due .rpt-status-pri { color:var(--rpt-warn-fg); }
.rpt-status.is-late .rpt-status-pri { color:var(--rpt-crit-fg); }

.rpt-context {
  display:flex;align-items:center;gap:8px 22px;flex-wrap:wrap;
  padding:12px 16px;margin-bottom:20px;
  background:#fff;border:1px solid var(--rpt-border);border-radius:12px;
}
.rpt-context-item { display:flex;align-items:baseline;gap:7px;font-size:12px;color:var(--rpt-muted);min-width:0; }
.rpt-context-item .k { font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--rpt-subtle); }
.rpt-context-item .v { color:var(--rpt-text);font-weight:600;font-variant-numeric:tabular-nums; }
.rpt-context-sep { width:1px;height:16px;background:var(--rpt-border); }
.rpt-context-note { margin-left:auto;font-size:11.5px;color:var(--rpt-subtle); }

/* KPI strip */
.rpt-kpis { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:20px; }
.rpt-kpi {
  display:flex;flex-direction:column;gap:5px;padding:15px 17px;
  background:#fff;border:1px solid var(--rpt-border);border-radius:12px;
  text-decoration:none;color:var(--rpt-text);
  box-shadow:0 1px 2px rgba(15,23,42,.03);
  transition:border-color .14s ease,box-shadow .14s ease,transform .14s ease;
}
.rpt-kpi:hover { border-color:var(--rpt-border-strong);box-shadow:0 4px 12px rgba(15,23,42,.06);transform:translateY(-1px); }
.rpt-kpi-lbl { font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--rpt-subtle); }
.rpt-kpi-val { font-size:27px;font-weight:700;letter-spacing:-.02em;line-height:1.12;font-variant-numeric:tabular-nums; }
.rpt-kpi-val sup { font-size:14px;font-weight:600;color:var(--rpt-muted);letter-spacing:0; }
.rpt-kpi-val.is-crit { color:var(--rpt-crit-fg); }
.rpt-kpi-val.is-warn { color:var(--rpt-warn-fg); }
.rpt-kpi-val.is-accent { color:var(--rpt-accent); }
.rpt-kpi-meta { font-size:11.5px;line-height:1.4;color:var(--rpt-muted);font-variant-numeric:tabular-nums; }
.rpt-kpi-action { display:block;margin-top:3px;font-size:11px;font-weight:600;color:var(--rpt-accent); }

/* Analysis: exceptions + one trend */
.rpt-analysis { display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:26px;align-items:stretch; }
.rpt-panel { background:#fff;border:1px solid var(--rpt-border);border-radius:12px;overflow:hidden;display:flex;flex-direction:column; }
.rpt-panel-head { display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:15px 17px 12px;border-bottom:1px solid var(--rpt-rule); }
.rpt-panel-title { font-size:13px;font-weight:700;letter-spacing:-.01em; }
.rpt-panel-sub { font-size:11.5px;color:var(--rpt-subtle);margin-top:2px; }
/* Both inline links are real controls: keep a 24px minimum target (WCAG 2.2
   2.5.8) rather than relying on a text-sized hit area. */
.rpt-panel-link { display:inline-flex;align-items:center;min-height:24px;
  font-size:11.5px;font-weight:600;color:var(--rpt-accent);text-decoration:none;white-space:nowrap; }
.rpt-panel-link:hover { text-decoration:underline; }
.rpt-panel-body { flex:1;padding:6px 17px 14px; }

.rpt-exc { display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--rpt-rule); }
.rpt-exc:last-child { border-bottom:0; }
.rpt-exc-mark { width:8px;height:8px;border-radius:50%;flex-shrink:0;background:var(--rpt-faint); }
.rpt-exc.crit .rpt-exc-mark, .rpt-exc.high .rpt-exc-mark { background:var(--rpt-crit-fg); }
.rpt-exc.warn .rpt-exc-mark { background:var(--rpt-warn-fg); }
.rpt-exc.info .rpt-exc-mark { background:var(--rpt-info-fg); }
.rpt-exc-main { flex:1;min-width:0; }
.rpt-exc-t { font-size:12.5px;font-weight:600;color:var(--rpt-text); }
.rpt-exc.crit .rpt-exc-t, .rpt-exc.high .rpt-exc-t { color:var(--rpt-crit-fg); }
/* High sits between Critical and Attention.  It shares the red family but
   gets its own left rule and its own word, so no two priorities rely on hue. */
.rpt-exc.high { border-left:3px solid var(--rpt-crit-fg);padding-left:9px; }
.rpt-exc-d { font-size:11.5px;color:var(--rpt-muted);margin-top:1px;line-height:1.4; }
.rpt-exc-n { font-size:18px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--rpt-text); }
.rpt-exc-act { display:inline-flex;align-items:center;min-height:24px;padding:4px 2px;
  font-size:11.5px;font-weight:600;color:var(--rpt-accent);text-decoration:none;white-space:nowrap; }
.rpt-exc-act:hover { text-decoration:underline; }

.rpt-empty { display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;
  min-height:150px;text-align:center;padding:22px 16px;color:var(--rpt-muted); }
.rpt-empty strong { font-size:13px;font-weight:700;color:var(--rpt-text); }
.rpt-empty span { font-size:11.5px;line-height:1.45;max-width:340px; }

.rpt-chart-wrap { padding:14px 17px 8px;flex:1;min-height:224px;display:flex;flex-direction:column; }
.rpt-chart { flex:1;display:grid;grid-template-columns:38px minmax(0,1fr);grid-template-rows:minmax(170px,1fr) 18px;min-height:196px;max-height:300px; }
.rpt-chart-y { grid-column:1;grid-row:1;display:flex;flex-direction:column;justify-content:space-between;align-items:flex-end;
  padding-right:7px;font-size:9.5px;color:var(--rpt-subtle);font-variant-numeric:tabular-nums; }
.rpt-chart-y span { transform:translateY(-.5em); }
.rpt-chart-y span:last-child { transform:translateY(.4em); }
.rpt-chart-svg { grid-column:2;grid-row:1;display:block;width:100%;height:100%;overflow:hidden; }
.rpt-chart-x { grid-column:2;grid-row:2;display:flex;justify-content:space-between;padding-top:4px;
  font-size:10px;color:var(--rpt-subtle);font-variant-numeric:tabular-nums; }
.rpt-chart-note { padding:0 17px 13px;font-size:11px;line-height:1.45;color:var(--rpt-subtle); }

/* Library */
.rpt-lib-head { display:flex;align-items:baseline;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px; }
.rpt-lib-title { font-size:15px;font-weight:700;letter-spacing:-.02em; }
.rpt-lib-sub { font-size:11.5px;color:var(--rpt-subtle); }
.rpt-jumps { display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px; }
.rpt-jump { font-size:11.5px;font-weight:600;color:var(--rpt-muted);text-decoration:none;
  padding:5px 10px;border:1px solid var(--rpt-border);border-radius:999px;background:#fff; }
.rpt-jump:hover { border-color:var(--rpt-border-strong);color:var(--rpt-text); }

.rpt-section { margin-bottom:22px;scroll-margin-top:86px; }
.rpt-section-head { display:flex;align-items:baseline;gap:11px;padding:0 2px 9px;border-bottom:1px solid var(--rpt-border);margin-bottom:0; }
.rpt-section-num { font-size:11px;font-weight:700;color:var(--rpt-subtle);font-variant-numeric:tabular-nums; }
.rpt-section-title { font-size:13px;font-weight:700;letter-spacing:-.01em; }
.rpt-section-desc { font-size:11.5px;color:var(--rpt-subtle);margin-left:auto;text-align:right; }
.rpt-section-count { font-size:11px;color:var(--rpt-subtle);font-variant-numeric:tabular-nums; }

.rpt-surface { background:#fff;border:1px solid var(--rpt-border);border-radius:12px;border-top-left-radius:0;border-top-right-radius:0;overflow:hidden; }
.rpt-row { display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;align-items:center;
  padding:15px 18px;border-bottom:1px solid var(--rpt-rule); }
.rpt-row:last-child { border-bottom:0; }
.rpt-row.is-featured { background:#FAFCFF; }
.rpt-row-main { min-width:0; }
.rpt-row-titleline { display:flex;align-items:center;gap:9px;flex-wrap:wrap; }
.rpt-row-title { font-size:13.5px;font-weight:650;letter-spacing:-.01em;color:var(--rpt-text);margin:0; }
.rpt-badge { display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;
  border:1px solid var(--rpt-border);background:#F8FAFC;color:var(--rpt-muted);white-space:nowrap; }
.rpt-badge.ok { background:var(--rpt-ok-bg);border-color:var(--rpt-ok-bd);color:var(--rpt-ok-fg); }
/* "Draft — Week in progress" is informational (blue); "due soon" is Warning
   (amber); "action required" is High (red).  The word is always present. */
.rpt-badge.neutral { background:var(--rpt-info-bg);border-color:var(--rpt-info-bd);color:var(--rpt-info-fg); }
.rpt-badge.warn { background:var(--rpt-warn-bg);border-color:var(--rpt-warn-bd);color:var(--rpt-warn-fg); }
.rpt-badge.crit { background:var(--rpt-crit-bg);border-color:var(--rpt-crit-bd);color:var(--rpt-crit-fg); }
.rpt-row-desc { font-size:12px;line-height:1.5;color:var(--rpt-muted);margin-top:3px;max-width:620px; }
.rpt-row-meta { font-size:10.5px;color:var(--rpt-subtle);margin-top:6px;letter-spacing:.01em; }
.rpt-row-note { font-size:11.5px;line-height:1.55;color:var(--rpt-muted);margin:7px 0 0;max-width:680px; }

.rpt-row-ctrl { display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;justify-content:flex-end; }
.rpt-form { display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;justify-content:flex-end; }
.rpt-period { display:flex;flex-direction:column;gap:4px; }
.rpt-period-label { font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--rpt-subtle); }
.rpt-input {
  height:34px;min-width:132px;padding:0 10px;
  background:#fff;border:1px solid var(--rpt-border-strong);border-radius:8px;
  color:var(--rpt-text);font:inherit;font-size:12.5px;line-height:1;font-variant-numeric:tabular-nums;
}
.rpt-input:focus-visible, .rpt-btn:focus-visible, .rpt-status:focus-visible,
.rpt-jump:focus-visible, .rpt-panel-link:focus-visible, .rpt-exc-act:focus-visible,
.rpt-kpi:focus-visible, .rpt-quick select:focus-visible {
  outline:2px solid var(--rpt-accent);outline-offset:2px;
}
.rpt-range { display:flex;align-items:center;gap:5px; }
.rpt-range .rpt-input { min-width:126px; }
.rpt-range-sep { color:var(--rpt-faint);font-size:12px; }
.rpt-select { min-width:150px; }

.rpt-btn {
  display:inline-flex;align-items:center;justify-content:center;gap:6px;height:34px;padding:0 12px;
  border-radius:8px;border:1px solid var(--rpt-border-strong);background:#fff;
  color:var(--rpt-text);font:inherit;font-size:12px;font-weight:600;
  text-decoration:none;cursor:pointer;white-space:nowrap;
  transition:background .14s ease,border-color .14s ease,color .14s ease;
}
.rpt-btn:hover { background:#F1F5F9;border-color:#94A3B8; }
.rpt-btn-primary { background:var(--rpt-accent);border-color:var(--rpt-accent);color:#fff; }
.rpt-btn-primary:hover { background:#1E40AF;border-color:#1E40AF;color:#fff; }
.rpt-btn svg { width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }

/* Quick exports */
.rpt-quick { display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  padding:14px 17px;background:#fff;border:1px solid var(--rpt-border);border-radius:12px; }
.rpt-quick-label { font-size:12px;font-weight:700;color:var(--rpt-text);margin-right:4px; }
.rpt-quick-note { font-size:11.5px;color:var(--rpt-subtle);margin-left:auto; }

.rpt-source { margin-top:20px;padding-top:14px;border-top:1px solid var(--rpt-border);
  font-size:11px;line-height:1.6;color:var(--rpt-subtle); }

@media (max-width:1080px){
  .rpt-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .rpt-analysis { grid-template-columns:1fr; }
  .rpt-section-desc { display:none; }
}
@media (max-width:820px){
  .rpt-row { grid-template-columns:1fr;gap:12px;align-items:start; }
  .rpt-row-ctrl, .rpt-form { justify-content:flex-start; }
  .rpt-context-note { margin-left:0;width:100%; }
  .rpt-context-sep { display:none; }
  /* The status pill carries two lines of text; give it its own row and let a
     long report name wrap instead of pushing the page sideways. */
  .rpt-head { flex-direction:column;align-items:stretch;gap:12px; }
  .rpt-status { align-self:flex-start;max-width:100%;white-space:normal; }
}
@media (max-width:560px){
  .rpt-kpis { grid-template-columns:1fr; }
  .rpt-head { align-items:flex-start; }
  .rpt-row { padding:14px; }
  .rpt-input, .rpt-btn { height:40px; }
  .rpt-range { flex-wrap:wrap; }
  .rpt-quick-note { margin-left:0;width:100%; }
}
@media (prefers-reduced-motion:reduce){
  #reportsShell * { transition:none!important;animation:none!important; }
}
@media print {
  .rpt-jumps,.rpt-row-ctrl,.rpt-status,.rpt-quick { display:none!important; }
  .rpt-panel,.rpt-surface,.rpt-kpi { box-shadow:none!important; }
}
</style>

<div id="reportsShell">

  <!-- ══ 1. IDENTITY + CONTEXT ══ -->
  <header class="main-page-head" style="margin-bottom:14px;">
    <h1 class="main-page-title"><?= $h(isset($page_title) ? $page_title : 'Reports') ?></h1>
    <p class="rpt-subtitle">The reporting home for the workshop. Read the current situation, then open the report that explains it.</p>
  </header>
  <div class="rpt-head">
    <div class="rpt-context" style="flex:1;margin-bottom:0;">
      <div class="rpt-context-item"><span class="k">Reporting week</span>
        <span class="v"><?= $h($iso_week) ?></span>
        <time datetime="<?= $h($week_start) ?>"><?= $h($week_range) ?></time>
      </div>
      <div class="rpt-context-sep" aria-hidden="true"></div>
      <div class="rpt-context-item"><span class="k">Availability window</span><span class="v">30 days</span>
        <span><?= $h($window_txt) ?></span></div>
      <div class="rpt-context-sep" aria-hidden="true"></div>
      <div class="rpt-context-item"><span class="k">Target</span><span class="v"><?= $h(number_format($target, 1)) ?>%</span>
        <span>· <?= $h(number_format((float)$t['daily_open_hours'], 0)) ?> h/day · <?= $h(number_format((float)$t['weekly_open_hours'], 0)) ?> h/week</span></div>
      <div class="rpt-context-note">Figures read <?= $h(date('d M Y, H:i')) ?> · server time</div>
    </div>
    <a class="rpt-status <?= $h($pub_meta['class']) ?>"
       href="<?= $h($bu('reports/weekly_w36?week=' . $iso_week)) ?>">
      <span class="dot" aria-hidden="true"></span>
      <span class="rpt-status-txt">
        <span class="rpt-status-display"><b><?= $h($report_name) ?></b> · <?= $h($pub_meta['display']) ?></span>
        <span class="rpt-status-pri"><?= $h($pub_meta['priority']) ?></span>
      </span>
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
    </a>
  </div>

  <?php if (!$live): ?>
  <div class="rpt-panel" style="border-color:var(--rpt-warn-bd);background:var(--rpt-warn-bg);margin-bottom:20px;">
    <div class="rpt-panel-body" style="padding:14px 17px;color:var(--rpt-warn-fg);font-size:12.5px;line-height:1.5;">
      <strong>Operational figures are unavailable.</strong> The reporting database could not be read on this request, so the
      snapshot, exceptions and trend below are shown as “—” rather than as zeros. The report library is unaffected.
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ 2. EXECUTIVE SNAPSHOT ══ -->
  <section aria-labelledby="rpt-snapshot-h">
    <h2 class="rpt-sr" id="rpt-snapshot-h">Operational snapshot, last 30 days</h2>
    <div class="rpt-kpis">
      <a class="rpt-kpi" href="<?= $h($bu('reports/mttr_mtbf?months=12')) ?>">
        <span class="rpt-kpi-lbl">Fleet availability · 30 d</span>
        <span class="rpt-kpi-val <?= $live ? ($avail_tone === 'crit' ? 'is-crit' : ($avail_tone === 'warn' ? 'is-warn' : '')) : '' ?>">
          <?= $live ? $h(number_format($avail, 1)) . '<sup>%</sup>' : '—' ?>
        </span>
        <span class="rpt-kpi-meta"><?= $live
            ? $h('Target ≥' . number_format($target, 1) . '% · ' . $avail_delta_txt . ' · ' . $covered . '/' . $total_machines . ' machines')
            : 'Not available' ?></span>
        <span class="rpt-kpi-action">Reliability report →</span>
      </a>
      <a class="rpt-kpi" href="<?= $h($bu('reports/workshop_weekly?week_start=' . $week_start)) ?>">
        <span class="rpt-kpi-lbl">Breakdowns · 30 d</span>
        <span class="rpt-kpi-val"><?= $live ? $h(number_format((int)$t['breakdowns_30d'])) : '—' ?></span>
        <span class="rpt-kpi-meta"><?= $live ? $h($reliability_meta) : 'Not available' ?></span>
        <span class="rpt-kpi-action">Workshop weekly →</span>
      </a>
      <a class="rpt-kpi" href="<?= $h($bu('reports/availability_forecast?horizon=90')) ?>">
        <span class="rpt-kpi-lbl">Overdue PM</span>
        <span class="rpt-kpi-val <?= ($live && (int)$t['overdue_pm'] > 0) ? 'is-crit' : '' ?>"><?= $live ? $h(number_format((int)$t['overdue_pm'])) : '—' ?></span>
        <span class="rpt-kpi-meta"><?= $live
            ? $h(number_format((int)$t['pm_due_30d']) . ' PM jobs due in the next 30 days')
            : 'Not available' ?></span>
        <span class="rpt-kpi-action">PM due forecast →</span>
      </a>
      <a class="rpt-kpi" href="<?= $h($bu('reports/reorder')) ?>">
        <span class="rpt-kpi-lbl">Items to re-order</span>
        <span class="rpt-kpi-val <?= ($live && (int)$t['out_of_stock_count'] > 0) ? 'is-crit' : (($live && (int)$t['reorder_count'] > 0) ? 'is-warn' : '') ?>">
          <?= $live ? $h(number_format((int)$t['reorder_count'])) : '—' ?>
        </span>
        <span class="rpt-kpi-meta"><?= $live
            ? $h(number_format((int)$t['out_of_stock_count']) . ' out of stock · ' . number_format((int)$t['reorder_units']) . ' units to order')
            : 'Not available' ?></span>
        <span class="rpt-kpi-action">Re-order report →</span>
      </a>
    </div>
  </section>

  <!-- ══ 3. EXCEPTIONS + 4. TREND ══ -->
  <div class="rpt-analysis">
    <section class="rpt-panel" aria-labelledby="rpt-exc-h">
      <div class="rpt-panel-head">
        <div>
          <h2 class="rpt-panel-title" id="rpt-exc-h">Requires attention</h2>
          <div class="rpt-panel-sub">Ranked by priority: High, Warning, Information. An in-progress draft is status, not an alert.</div>
        </div>
        <?php if ($live && !empty($exceptions)): ?>
        <span class="rpt-badge"><?= count($exceptions) ?> item<?= count($exceptions) === 1 ? '' : 's' ?></span>
        <?php endif; ?>
      </div>
      <div class="rpt-panel-body">
        <?php if (!$live): ?>
          <div class="rpt-empty">
            <strong>Operational figures unavailable</strong>
            <span>Exceptions cannot be calculated until the reporting database can be read. Reports are still available below.</span>
          </div>
        <?php elseif (empty($exceptions)): ?>
          <div class="rpt-empty">
            <strong>Nothing needs attention</strong>
            <span><?= $h($calm_text) ?></span>
          </div>
        <?php else: ?>
          <?php foreach ($exceptions as $ex): ?>
          <div class="rpt-exc <?= $h($ex['tone']) ?>">
            <span class="rpt-exc-mark" aria-hidden="true"></span>
            <div class="rpt-exc-main">
              <div class="rpt-exc-t"><?= $h($ex['label']) ?></div>
              <div class="rpt-exc-d">
                <?= $h(isset($severity_words[$ex['tone']]) ? $severity_words[$ex['tone']] : 'Information') ?> ·
                <?= $h($ex['detail']) ?>
              </div>
            </div>
            <?php if ($ex['count'] !== null): ?><div class="rpt-exc-n"><?= (int)$ex['count'] ?></div><?php endif; ?>
            <a class="rpt-exc-act" href="<?= $h($ex['href']) ?>"><?= $h($ex['action']) ?> →</a>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <section class="rpt-panel" aria-labelledby="rpt-trend-h">
      <div class="rpt-panel-head">
        <div>
          <h2 class="rpt-panel-title" id="rpt-trend-h">Fleet availability · last 30 days</h2>
          <div class="rpt-panel-sub">Daily availability against the <?= $h(number_format($target, 1)) ?>% target</div>
        </div>
        <a class="rpt-panel-link" href="<?= $h($bu('reports/monthly?month=' . $this_month)) ?>">Monthly summary →</a>
      </div>
      <?php if ($live && $has_trend): ?>
      <div class="rpt-chart-wrap">
        <div class="rpt-chart">
          <div class="rpt-chart-y" aria-hidden="true"><span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0%</span></div>
          <svg class="rpt-chart-svg" viewBox="0 0 100 100" preserveAspectRatio="none" role="img"
               aria-label="Line chart of daily fleet availability over the last 30 days. The dashed horizontal line marks the <?= $h(number_format($target, 1)) ?> percent target. Latest available reading: <?= $h(number_format((float)$latest_val, 1)) ?> percent on <?= $h($latest_date) ?>.">
            <g stroke="rgba(15,23,42,0.08)" stroke-width="1" vector-effect="non-scaling-stroke">
              <line x1="0" y1="0" x2="100" y2="0"/><line x1="0" y1="25" x2="100" y2="25"/>
              <line x1="0" y1="50" x2="100" y2="50"/><line x1="0" y1="75" x2="100" y2="75"/>
              <line x1="0" y1="100" x2="100" y2="100"/>
            </g>
            <line x1="0" y1="<?= $h($chart_target_y) ?>" x2="100" y2="<?= $h($chart_target_y) ?>"
                  stroke="rgba(15,23,42,0.32)" stroke-width="1.5" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/>
            <polyline points="<?= $h($chart_poly) ?>" fill="none" stroke="#1D4ED8" stroke-width="2"
                      stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
            <?php foreach ($chart_pts as $i => $p):
              $bx = max(0, $p['x'] - $chart_step / 2);
              $bw = $chart_step; ?>
            <rect x="<?= $h(round($bx, 3)) ?>" y="0" width="<?= $h(round($bw, 3)) ?>" height="100"
                  fill="transparent" pointer-events="all">
              <title><?= $h($p['d'] . ': ' . number_format($p['v'], 1) . '% availability') ?></title>
            </rect>
            <?php endforeach; ?>
          </svg>
          <div class="rpt-chart-x" aria-hidden="true">
            <span><?= $h($trend_labels[0]) ?></span>
            <span><?= $h($trend_labels[(int)floor((count($trend_labels) - 1) / 2)]) ?></span>
            <span><?= $h(end($trend_labels)) ?></span>
          </div>
        </div>
        <table class="rpt-sr">
          <caption>Daily fleet availability over the last 30 days, percent</caption>
          <thead><tr><th scope="col">Date</th><th scope="col">Availability</th></tr></thead>
          <tbody>
          <?php foreach ($t['availability_trend'] as $p): ?>
            <tr><td><?= $h(isset($p['date']) ? $p['date'] : '') ?></td><td><?= $h(number_format(isset($p['pct']) ? (float)$p['pct'] : 100, 1)) ?>%</td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="rpt-chart-note">Latest: <?= $h(number_format((float)$latest_val, 1)) ?>% on <?= $h($latest_date) ?>.
        Dashed line = <?= $h(number_format($target, 1)) ?>% target, on a fixed 0–100% scale. From the KPI engine on the
        configured open hours<?= ($total_machines > 0 && $covered < $total_machines)
            ? ', with data from ' . $h($covered) . ' of ' . $h($total_machines) . ' machines' : '' ?>;
        days with no scheduled operating time are reported as 100% by that engine.</p>
      <?php else: ?>
      <div class="rpt-empty">
        <strong><?= $live ? 'Not enough data for a trend' : 'Trend unavailable' ?></strong>
        <span><?= $live
            ? 'Daily availability needs at least two days of engine output. Open the monthly summary for the underlying figures.'
            : 'The reporting database could not be read on this request.' ?></span>
      </div>
      <?php endif; ?>
    </section>
  </div>

  <!-- ══ 5. REPORT LIBRARY ══ -->
  <section aria-labelledby="rpt-lib-h">
    <div class="rpt-lib-head">
      <div>
        <h2 class="rpt-lib-title" id="rpt-lib-h">Report library</h2>
        <div class="rpt-lib-sub">Choose a period, then open the report or download it. The same period is applied to every export.</div>
      </div>
    </div>
    <nav class="rpt-jumps" aria-label="Jump to a report group">
      <?php foreach ($sections as $s): ?>
      <a class="rpt-jump" href="#<?= $h($s['id']) ?>"><?= $h($s['title']) ?></a>
      <?php endforeach; ?>
      <a class="rpt-jump" href="#quick-exports">Quick exports</a>
    </nav>

    <?php foreach ($sections as $s): ?>
    <section class="rpt-section" id="<?= $h($s['id']) ?>" aria-labelledby="rpt-sec-<?= $h($s['id']) ?>">
      <div class="rpt-section-head">
        <span class="rpt-section-num" aria-hidden="true"><?= $h($s['num']) ?></span>
        <h3 class="rpt-section-title" id="rpt-sec-<?= $h($s['id']) ?>"><?= $h($s['title']) ?></h3>
        <span class="rpt-section-count"><?= count($s['items']) ?></span>
        <span class="rpt-section-desc"><?= $h($s['desc']) ?></span>
      </div>
      <div class="rpt-surface">
        <?php foreach ($s['items'] as $item): ?>
        <?php $form = isset($item['form']) ? $item['form'] : array(); ?>
        <div class="rpt-row <?= !empty($item['featured']) ? 'is-featured' : '' ?>">
          <div class="rpt-row-main">
            <div class="rpt-row-titleline">
              <h4 class="rpt-row-title"><?= $h($item['title']) ?></h4>
              <?php if (!empty($item['badge'])): ?>
              <span class="rpt-badge <?= $h(isset($item['badge_tone']) ? $item['badge_tone'] : '') ?>"><?= $h($item['badge']) ?></span>
              <?php endif; ?>
            </div>
            <div class="rpt-row-desc"><?= $h($item['desc']) ?></div>
            <div class="rpt-row-meta"><?= $h($item['meta']) ?></div>
            <?php if (!empty($item['note'])): ?>
            <p class="rpt-row-note"><?= $h($item['note']) ?></p>
            <?php endif; ?>
          </div>
          <div class="rpt-row-ctrl">
            <form method="get" action="<?= $h($bu($form['action'])) ?>" class="rpt-form">
              <?php
              $c = isset($form['control']) ? $form['control'] : null;
              if ($c && $c['type'] === 'date'): ?>
              <span class="rpt-period">
                <label class="rpt-period-label" for="rpt-<?= $h($s['id']) . '-' . $h($c['name']) ?>-<?= $h(substr(md5($item['title']),0,6)) ?>"><?= $h($c['label']) ?></label>
                <input class="rpt-input" type="date" id="rpt-<?= $h($s['id']) . '-' . $h($c['name']) ?>-<?= $h(substr(md5($item['title']),0,6)) ?>"
                       name="<?= $h($c['name']) ?>" value="<?= $h($c['value']) ?>"<?= !empty($c['max']) ? ' max="' . $h($c['max']) . '"' : '' ?>>
              </span>
              <?php elseif ($c && $c['type'] === 'month'): ?>
              <span class="rpt-period">
                <label class="rpt-period-label" for="rpt-<?= $h($s['id']) . '-' . $h($c['name']) ?>-<?= $h(substr(md5($item['title']),0,6)) ?>"><?= $h($c['label']) ?></label>
                <input class="rpt-input" type="month" id="rpt-<?= $h($s['id']) . '-' . $h($c['name']) ?>-<?= $h(substr(md5($item['title']),0,6)) ?>"
                       name="<?= $h($c['name']) ?>" value="<?= $h($c['value']) ?>">
              </span>
              <?php elseif ($c && $c['type'] === 'select'): ?>
              <span class="rpt-period">
                <label class="rpt-period-label" for="rpt-<?= $h($s['id']) . '-' . $h($c['name']) ?>-<?= $h(substr(md5($item['title']),0,6)) ?>"><?= $h($c['label']) ?></label>
                <select class="rpt-input rpt-select" id="rpt-<?= $h($s['id']) . '-' . $h($c['name']) ?>-<?= $h(substr(md5($item['title']),0,6)) ?>" name="<?= $h($c['name']) ?>">
                  <?php foreach ($c['options'] as $ov => $ol): ?>
                  <option value="<?= $h($ov) ?>" <?= (string)$ov === (string)$c['selected'] ? 'selected' : '' ?>><?= $h($ol) ?></option>
                  <?php endforeach; ?>
                </select>
              </span>
              <?php elseif ($c && $c['type'] === 'range'):
                $uid = substr(md5($item['title']), 0, 6); ?>
              <span class="rpt-period">
                <span class="rpt-period-label" id="rpt-range-<?= $h($uid) ?>"><?= $h($c['label']) ?></span>
                <span class="rpt-range" role="group" aria-labelledby="rpt-range-<?= $h($uid) ?>">
                  <label class="rpt-sr" for="rpt-from-<?= $h($uid) ?>">From date</label>
                  <input class="rpt-input" type="date" id="rpt-from-<?= $h($uid) ?>" name="<?= $h($c['from_name']) ?>" value="<?= $h($c['from_value']) ?>">
                  <span class="rpt-range-sep" aria-hidden="true">–</span>
                  <label class="rpt-sr" for="rpt-to-<?= $h($uid) ?>">To date</label>
                  <input class="rpt-input" type="date" id="rpt-to-<?= $h($uid) ?>" name="<?= $h($c['to_name']) ?>" value="<?= $h($c['to_value']) ?>">
                </span>
              </span>
              <?php endif; ?>

              <?php if (!empty($form['equipment_select'])):
                $es = $form['equipment_select']; $euid = substr(md5($item['title']), 0, 6); ?>
              <span class="rpt-period">
                <label class="rpt-period-label" for="rpt-eq-<?= $h($euid) ?>"><?= $h($es['label']) ?></label>
                <select class="rpt-input rpt-select" id="rpt-eq-<?= $h($euid) ?>" name="<?= $h($es['name']) ?>" required>
                  <option value="">Choose a machine…</option>
                  <?php foreach ($es['options'] as $eq): ?>
                  <?php $lbl = trim((string)$eq->registration_no . ($eq->brand || $eq->model ? ' · ' . trim($eq->brand . ' ' . $eq->model) : '')); ?>
                  <option value="<?= (int)$eq->id ?>"><?= $h($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </span>
              <button type="submit" class="rpt-btn rpt-btn-primary">Open history
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg></button>
              <?php else: ?>
              <button type="submit" class="rpt-btn rpt-btn-primary">Open
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg></button>
              <?php endif; ?>

              <?php if (!empty($form['buttons'])): foreach ($form['buttons'] as $b): if ($b === null) continue; ?>
                <?php if (!empty($b['href'])): ?>
              <a class="rpt-btn" href="<?= $h($bu($b['href'])) ?>"<?= !empty($b['target']) ? ' target="' . $h($b['target']) . '" rel="noopener"' : '' ?>><?= $h($b['label']) ?></a>
                <?php else: ?>
              <button type="submit" class="rpt-btn"
                      formaction="<?= $h($bu($b['action'])) ?>"
                      <?= !empty($b['name']) ? 'name="' . $h($b['name']) . '" value="' . $h($b['value']) . '"' : '' ?>
                      <?= !empty($b['target']) ? 'formtarget="' . $h($b['target']) . '"' : '' ?>><?= $h($b['label']) ?></button>
                <?php endif; ?>
              <?php endforeach; endif; ?>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>

    <!-- Quick exports -->
    <section class="rpt-section" id="quick-exports" aria-labelledby="rpt-quick-h" style="scroll-margin-top:86px;">
      <div class="rpt-section-head">
        <span class="rpt-section-num" aria-hidden="true">06</span>
        <h3 class="rpt-section-title" id="rpt-quick-h">Quick exports</h3>
        <span class="rpt-section-count">3</span>
        <span class="rpt-section-desc">Raw CSV extracts for a chosen month</span>
      </div>
      <form method="get" action="<?= $h($bu('reports/export_excel/workorders')) ?>" class="rpt-quick">
        <span class="rpt-quick-label">Download</span>
        <span class="rpt-period">
          <label class="rpt-period-label" for="rpt-quick-month">Month</label>
          <input class="rpt-input" type="month" id="rpt-quick-month" name="month" value="<?= $h($this_month) ?>">
        </span>
        <button type="submit" class="rpt-btn">Work orders</button>
        <button type="submit" class="rpt-btn" formaction="<?= $h($bu('reports/export_excel/attendance')) ?>">Attendance</button>
        <button type="submit" class="rpt-btn" formaction="<?= $h($bu('reports/export_excel/lubricants')) ?>">Lubricants</button>
        <span class="rpt-quick-note">These three endpoints return CSV files, despite their historical “excel” URL.</span>
      </form>
    </section>
  </section>

  <p class="rpt-source">
    Figures on this page come from the same KPI engine, store ledger, inventory catalogue and weekly-report model the
    individual reports use. Availability = (required time − downtime) ÷ required time on the configured open hours;
    MTTR / MTBF show “—” when the 30-day window had no breakdown events. A published weekly report is frozen and will
    not change when work orders do. Publication priority follows the configured reporting week: an open week is a Draft
    (Informational), its final day is Publication due soon (Warning), and a closed week without a snapshot is Unpublished
    (High). The hub checks the <?= (int)$pub_window ?> most recent completed weeks; the week’s own last day is the only
    deadline the system defines — there is no separate deadline setting and publishing is never blocked by this status.
    Nothing here is a cached or sample value.
  </p>

</div>
