<?php
/*
 * W36 weekly report — the preview screen   (plan tasks W2.1 + W2.2)
 *
 * This page is the report, before it becomes a file:
 *   Select week  →  Preview  →  Download Excel  →  Publish snapshot
 *
 * Every value on it comes out of W36_report_model::build() — the same call the
 * Excel writer uses — so what is read here is exactly what the download will
 * contain (decision D-W1). Nothing is typed in and nothing is hard-coded: the
 * week, the open hours, the targets, the groups and the thresholds all come
 * from the database and from settings.
 *
 * Layout (D-W2): group block rows in the approved order, the machine rows
 * under them, the seven day columns, the BG:BP weekly block and the per-type
 * block at the end — the same information the sheet carries, at preview
 * density. The sheet's day blocks hold seven figures each; expanding a machine
 * shows them.
 */
$report   = isset($report) && is_array($report) ? $report : array();
$week     = isset($report['week']) ? $report['week'] : array();
$days     = isset($week['days']) ? $week['days'] : array();
$groups   = isset($report['groups']) ? $report['groups'] : array();
$machines = isset($report['machines']) ? $report['machines'] : array();
$order    = isset($report['machine_order']) ? $report['machine_order'] : array();
$fleet    = isset($report['fleet']) ? $report['fleet'] : array();
$per_type = isset($report['per_type']) ? $report['per_type'] : array();
$charts   = isset($report['charts']) ? $report['charts'] : array();
$pm       = isset($report['pm']) ? $report['pm'] : array();
$inspect  = isset($report['inspection']) ? $report['inspection'] : array();
$quality  = isset($report['data_quality']) ? $report['data_quality'] : array();
$meta     = isset($report['meta']) ? $report['meta'] : array();
$modes    = isset($report['settings']) ? $report['settings'] : array();
$notice   = isset($notice) ? $notice : null;
$snapshot = isset($snapshot) && is_array($snapshot) ? $snapshot : null;
// the freeze this page is showing (or null) and the freeze that exists for the
// week (they differ on the live reading of a published week)
$published = isset($published) && is_array($published) ? $published : $snapshot;
$is_live  = !empty($is_live);
$live_query = $is_live && $published !== null ? '&live=1' : '';
$may_publish = isset($role_id) && in_array((int)$role_id, array(1, 4), true);   // supervisor / manager
// every POST form must carry the CSRF token (`config.php`: csrf_protection = TRUE).
// The controller passes it in — and `csrf_field()` (application/helpers) falls
// back to the security class, so the view also renders outside the application.
$csrf_token = isset($csrf_token) ? (string)$csrf_token : '';
$csrf_name  = isset($csrf_name) && $csrf_name !== '' ? (string)$csrf_name : null;
$csrf_field = function () use ($csrf_token, $csrf_name) { return csrf_field($csrf_token, $csrf_name); };
// the print/PDF document (plan task 4.3): the same page, without the screen
// chrome, with its own print stylesheet and — opened directly — an auto-print.
// `print_mode` also expands the day blocks, so the seven daily figures print.
$print_mode   = !empty($print_mode);
$auto_print   = !empty($auto_print);
$generated_at = isset($generated_at) ? $generated_at : date('Y-m-d H:i');
$build_ms = isset($build_ms) ? (int)$build_ms : null;
$prev_week = isset($prev_week) ? $prev_week : null;
$next_week = isset($next_week) ? $next_week : null;
$week_label = isset($week_label) ? $week_label : (isset($week['iso_label']) ? $week['iso_label'] : '');
$report_name = isset($report['report_name']) && trim((string)$report['report_name']) !== ''
    ? trim((string)$report['report_name'])
    : ('W' . (int)(isset($week['iso_week']) ? $week['iso_week'] : (isset($week_label) && preg_match('/-W([0-9]+)$/', $week_label, $wm) ? $wm[1] : date('W'))));

$esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
// Tidy historical and current quality messages for display without changing the stored report.
$quality_message = function (array $issue) {
    $message = isset($issue['message']) ? (string)$issue['message'] : '';
    if (isset($issue['code']) && $issue['code'] === 'reconcile') {
        return 'Fleet totals do not match the machine totals. Please review the report before publishing.';
    }
    $message = preg_replace('/\s*\((?:OPEN-\d+|D4|G4|BR-[A-Z0-9-]+)\)/', '', $message);
    $message = str_replace(array('NOT JUDGED', 'so the LOCATION column will be empty', 'so it cannot be printed inside a group block'),
        array('not assessed', 'so its location is missing', 'so it cannot be included in a category'), $message);
    return $message;
};
$chart_titles = array(
    'fleet_availability' => 'Fleet availability', 'availability_by_type' => 'Availability by equipment type',
    'fleet_state_by_type' => 'Available and down machines by type', 'downtime_by_type' => 'Downtime by equipment type',
    'breakdowns_by_type' => 'Breakdowns by equipment type', 'mttr_by_type' => 'Average repair time by type (MTTR)',
    'mtbf_by_type' => 'Average time between failures by type (MTBF)', 'worst_machines' => 'Machines with the most downtime',
    'pm_services' => 'Planned maintenance services', 'inspections' => 'Weekly inspections',
);

$num = function ($v, $dec = 2) {
    if ($v === null || $v === '' || $v === false) return '—';
    return number_format((float)$v, $dec, '.', ' ');
};

$pct = function ($v) use ($num) {
    if ($v === null || $v === '') return '—';
    return $num(((float)$v) * 100, 2) . ' %';
};

// the sheet marks a value that misses its target in red bold (emphasis rules);
// the preview does the same, from the same comparison BR-W17 defines
$misses = function ($value, $target) {
    return $value !== null && $target !== null && (float)$value < (float)$target;
};
$availability_tone = function ($value, $target) {
    if ($value === null || $target === null) return 'neutral';
    if ((float)$value >= (float)$target) return 'good';
    if ((float)$value >= (float)$target - .15) return 'warn';
    return 'bad';
};

$day_name = function ($d) { return date('D d/m', strtotime($d)); };
// Current W36 payloads key daily values by ISO date. Accept numeric keys as a
// compatibility fallback for any older published payload already stored.
$day_row = function ($daily, $day, $index) {
    if (!is_array($daily)) return null;
    if (isset($daily[$day]) && is_array($daily[$day])) return $daily[$day];
    return isset($daily[$index]) && is_array($daily[$index]) ? $daily[$index] : null;
};

$availability_bar = function ($value, $target) use ($esc, $pct, $availability_tone) {
    $tone = $availability_tone($value, $target);
    $width = $value === null ? 0 : max(0, min(100, (float)$value * 100));
    $label = $pct($value);
    return '<span class="w36-avbar" aria-label="Availability '.$esc($label).'"><span class="w36-av-track"><i class="w36-av-fill '.$esc($tone).'" style="width:'.$width.'%"></i></span><span class="w36-av-value '.$esc($tone).'">'.$esc($label).'</span></span>';
};
$daily_strip = function ($daily) use ($days, $day_row, $pct, $availability_tone, $esc) {
    $html = '<span class="w36-strip" aria-label="Daily availability for the selected week">';
    foreach ($days as $i => $day) {
        $cell = $day_row($daily, $day, $i);
        $value = $cell && isset($cell['availability']) ? $cell['availability'] : null;
        $target = $cell && array_key_exists('target', $cell) ? $cell['target'] : null;
        $tone = $availability_tone($value, $target);
        $preventive = $cell && isset($cell['preventive_hours']) ? (float)$cell['preventive_hours'] : 0;
        if ($value !== null && $preventive > 0 && (float)$value >= .99) $tone = 'prev';
        $title = $day_name = date('D d M', strtotime($day));
        $title .= ': '.$pct($value);
        if ($target !== null) $title .= ' vs target '.$pct($target);
        if ($cell && isset($cell['downtime_hours'])) $title .= ' · '.$cell['downtime_hours'].' h down';
        if ($preventive > 0) $title .= ' · '.$preventive.' h preventive';
        if ($value === null) $title .= ' · no reading';
        $html .= '<i class="'.$esc($tone).'" title="'.$esc($title).'"></i>';
    }
    return $html.'</span>';
};

// the data-readiness panel buckets the model's notes
$by_level = array('error' => array(), 'warn' => array(), 'info' => array());
foreach ($quality as $q) {
    $lvl = isset($q['level']) ? $q['level'] : 'info';
    if (!isset($by_level[$lvl])) $by_level[$lvl] = array();
    $by_level[$lvl][] = $q;
}
$issue_count = count($by_level['error']) + count($by_level['warn']);
$warning_counts = array();
foreach ($by_level['warn'] as $warning) {
    $code = isset($warning['code']) ? (string)$warning['code'] : 'other';
    $warning_counts[$code] = isset($warning_counts[$code]) ? $warning_counts[$code] + 1 : 1;
}
$status_without_wo_count = isset($warning_counts['status_without_wo']) ? $warning_counts['status_without_wo'] : 0;
$can_create_wo = isset($role_id) && in_array((int)$role_id, array(1, 2, 3), true);
$can_edit_equipment = isset($role_id) && (int)$role_id === 1;
$with_data   = 0;
$down_machines = 0;
foreach ($order as $id) {
    if (empty($machines[$id])) continue;
    if (!empty($machines[$id]['weekly']['has_data'])) $with_data++;
    if ((float)$machines[$id]['weekly']['hours_down'] > 0) $down_machines++;
}
$machine_total = count($machines);
$fleet_available = max(0, $machine_total - $down_machines);
$locations = array();
$unclassified_ids = array();
foreach ($order as $id) {
    if (empty($machines[$id])) continue;
    $site_label = trim((string)(isset($machines[$id]['site']['label']) ? $machines[$id]['site']['label'] : ''));
    if ($site_label !== '') $locations[$site_label] = $site_label;
    if (empty($machines[$id]['group_code'])) $unclassified_ids[] = $id;
}
ksort($locations, SORT_NATURAL | SORT_FLAG_CASE);

// the weekly block, in the sheet's own order (BG:BP).  OPEN-5/D28: the D4 days-down
// count is its first column, as on the sheet.
$weekly_cols = array(
    'days'      => 'Days down',
    'open'      => 'Weekly open time (h)',
    'pt'        => 'Preventive maintenance (h)',
    'rt'        => 'Required time (h)',
    'dt'        => 'Downtime (h)',
    'f'         => 'Failures',
    'mtbf'      => 'MTBF',
    'mttr'      => 'MTTR',
    'av'        => 'Operational availability',
    'target'    => 'Availability target',
);

$detail_rows = array(
    'Preventive h' => 'preventive_hours', 'Down h' => 'downtime_hours', 'Failures' => 'failures',
    'MTBF h' => 'mtbf_hours', 'MTTR h' => 'mttr_hours', 'Availability' => 'availability', 'Target' => 'target',
);
$detail_week_key = array(
    'preventive_hours' => 'preventive_hours', 'downtime_hours' => 'downtime_hours',
    'failures' => 'breakdowns', 'mtbf_hours' => 'mtbf_hours', 'mttr_hours' => 'mttr_hours',
    'availability' => 'availability', 'target' => 'weekly_target',
);

$weekly_cells = function (array $r, $open_hours = null) use ($num, $pct) {
    $w = isset($r['rollup']) ? $r['rollup'] : $r;   // a group / fleet row wraps it
    return array(
        'days'   => $num($w['days_down'], 0),
        'open'   => $num($open_hours, 0),
        'pt'     => $num($w['preventive_hours'], 2),
        'rt'     => $num($w['required_time_hours'], 2),
        'dt'     => $num($w['downtime_hours'], 2),
        'f'      => $num($w['breakdowns'], 0),
        'mtbf'   => $num($w['mtbf_hours'], 2),
        'mttr'   => $num($w['mttr_hours'], 2),
        'av'     => $pct($w['availability']),
        'target' => $pct(isset($w['weekly_target']) ? $w['weekly_target'] : null),
    );
};
?>
<style>
  .w36-wrap    { max-width: 1500px; margin: 0 auto; font-size: 13px; }
  .w36-head    { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; }
  .w36-title   { font-size:22px; font-weight:600; margin:0; }
  .w36-sub     { color:#6b7280; margin:4px 0 0; }
  .w36-picker  { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:14px 0; padding:12px 14px;
                 background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; }
  .w36-picker input[type=week], .w36-picker input[type=date] {
                 padding:6px 8px; border:1px solid #d1d5db; border-radius:6px; background:#fff; }
  .w36-btn     { display:inline-block; padding:6px 12px; border-radius:6px; border:1px solid #d1d5db; background:#fff;
                 color:#111827; text-decoration:none; }
  .w36-btn-primary { background:var(--blue,#0071e3); border-color:var(--blue,#0071e3); color:#fff; }
  .w36-btn[disabled], .w36-btn.is-disabled { opacity:.5; cursor:not-allowed; }
  .w36-note    { padding:10px 12px; border-radius:8px; margin:10px 0; }
  .w36-note.warn { background:#fffbeb; border:1px solid #fde68a; }
  .w36-note.error{ background:#fef2f2; border:1px solid #fecaca; }
  .w36-note.ok   { background:#f0fdf4; border:1px solid #bbf7d0; }
  .w36-panel   { border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; margin:14px 0; background:#fff; }
  .w36-panel h3{ margin:0 0 8px; font-size:14px; }
  .w36-badges  { display:flex; gap:8px; flex-wrap:wrap; }
  .w36-badge   { padding:3px 9px; border-radius:999px; font-size:12px; border:1px solid transparent; }
  .w36-badge.err { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
  .w36-badge.wrn { background:#fffbeb; border-color:#fde68a; color:#92400e; }
  .w36-badge.inf { background:#eff6ff; border-color:#bfdbfe; color:#1e40af; }
  .w36-badge.ok  { background:#f0fdf4; border-color:#bbf7d0; color:#166534; }
  .w36-tblwrap { overflow-x:auto; border:1px solid #e5e7eb; border-radius:10px; background:#fff; }
  .w36-tblwrap:focus-visible, .w36-wrap :focus-visible { outline:3px solid #2563eb; outline-offset:2px; }
  .w36-table-tools { display:flex; flex-wrap:wrap; align-items:center; gap:8px 16px; margin:14px 0 8px; }
  .w36-table-tools input { padding:7px 9px; border:1px solid #9ca3af; border-radius:6px; max-width:100%; }
  .w36-scroll-hint { margin:6px 0; color:#4b5563; }
  table.w36    { border-collapse:separate; border-spacing:0; width:100%; white-space:nowrap; }
  table.w36 th, table.w36 td { border-bottom:1px solid #eceff3; border-right:1px solid #eceff3; padding:4px 7px; }
  table.w36 thead th { position:sticky; top:0; background:#f3f4f6; font-size:11px; text-transform:uppercase;
                       letter-spacing:.02em; color:#374151; z-index:2; }
  table.w36 tbody tr.fleet td { background:#eef2ff; font-weight:600; }
  table.w36 tbody tr.grp td { background:#f8fafc; font-weight:600; }
  table.w36 tbody tr.machine:hover td { background:#fbfdff; }
  table.w36 tbody tr.detail > td { background:#fcfcfd; white-space:normal; }
  table.w36 td.num { text-align:right; }
  table.w36 td.miss, table.w36 span.miss { color:#b91c1c; font-weight:700; }
  .w36-day-target { display:block; color:#6b7280; font-size:11px; font-weight:400; }
  .w36-who     { font-weight:600; }
  .w36-status  { display:inline-block; padding:1px 6px; border-radius:999px; font-size:11px; background:#eef2f7; color:#374151; }
  .w36-detail-tbl { border-collapse:collapse; margin:6px 0 10px; }
  .w36-detail-tbl th, .w36-detail-tbl td { border:1px solid #e5e7eb; padding:3px 8px; text-align:right; font-size:12px; }
  .w36-detail-tbl th { background:#f3f4f6; text-align:left; font-weight:600; }
  .w36-evts    { margin:0; padding-left:18px; color:#374151; }
  .w36-evts .open { color:#b91c1c; font-weight:600; }
  .w36-qlist   { margin:0; padding-left:18px; }
  .w36-qlist li{ margin:2px 0; }
  .w36-qcode   { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:11px; color:#6b7280; }
  .w36-legend  { color:#6b7280; margin:8px 2px; }
  .w36-charts  { display:grid; grid-template-columns:repeat(auto-fit,minmax(330px,1fr)); gap:14px; }
  /* sheet `W36 Synthese` on screen (W5.5) */
  .w36-hold    { display:inline-block; min-width:34px; text-align:center; padding:1px 6px; border-radius:4px;
                 font-weight:700; font-size:11px; background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
  .w36-insp    { display:inline-block; padding:1px 7px; border-radius:999px; font-size:11px; font-weight:600; }
  .w36-insp.done { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
  .w36-insp.undone { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
  .w36-insp.breakdown { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
  .w36-syn  td.wrap { white-space:normal; min-width:220px; max-width:420px; }
  .w36-chart   { border:1px solid #eceff3; border-radius:8px; padding:10px 12px; }
  .w36-chart-title { font-weight:600; margin-bottom:8px; }
  .w36-bar-row { display:flex; align-items:center; gap:8px; margin:3px 0; }
  .w36-bar-label { width:92px; flex:0 0 92px; color:#374151; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .w36-bar-track { position:relative; flex:1 1 auto; height:14px; background:#f3f4f6; border-radius:4px; overflow:hidden; display:flex; }
  .w36-bar-fill  { height:100%; display:block; }
  .w36-bar-second{ opacity:.8; }
  .w36-bar-tick  { position:absolute; top:-2px; bottom:-2px; width:2px; background:#111827; }
  .w36-bar-value { width:80px; flex:0 0 80px; text-align:right; font-variant-numeric:tabular-nums; }
  .w36-meter     { position:relative; height:22px; background:#f3f4f6; border-radius:6px; overflow:hidden; }
  .w36-meter-fill{ display:block; height:100%; }
  .w36-meter-tick{ position:absolute; top:-3px; bottom:-3px; width:2px; background:#111827; }
  .w36-chart-note{ color:#6b7280; font-size:12px; margin-top:6px; }
  .w36-small   { font-size:12px; color:#6b7280; }

  /* Main W36 grid follows the Workshop Weekly table hierarchy: one compact
     summary row per type, a seven-day strip, and details on demand. */
  .w36-main-wrap { overflow:auto; border:1px solid #d0d8db; border-radius:8px; background:#f8f9f9; }
  .w36-table-tools { align-items:center; gap:8px; margin:14px 0 8px; }
  .w36-table-tools[hidden] { display:none!important; }
  .w36-table-tools label { font-size:12px; color:#586a72; }
  .w36-table-tools input[type=search], .w36-table-tools select { height:36px; padding:0 10px; border:1px solid #d0d8db; border-radius:6px; background:#f8f9f9; color:#14232b; font:inherit; }
  .w36-table-tools input[type=search] { width:min(310px,70vw); }
  .w36-table-tools select { min-width:145px; }
  .w36-filter-below { display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
  .w36-filter-below input { accent-color:#0b6e7f; }
  #w36-search-count { margin-left:auto; color:#586a72; font-size:11px; white-space:nowrap; }
  .w36-table-actions { display:flex; gap:7px; margin-left:auto; }
  .w36-table-actions .w36-btn { padding:7px 11px; background:#f8f9f9; color:#14232b; border-color:#d0d8db; font-size:12px; font-weight:600; cursor:pointer; }
  .w36-table-actions .w36-btn:hover { border-color:#0b6e7f; color:#0b6e7f; }
  .w36-sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
  .w36-main-wrap table.w36-summary-table { min-width:1040px; width:100%; border-collapse:collapse; border-spacing:0; white-space:nowrap; font:inherit; font-size:12px; color:#14232b; }
  .w36-main-wrap table.w36-summary-table th { position:sticky; top:0; z-index:2; padding:9px 10px; border:0; border-bottom:1px solid #d0d8db; background:#f8f9f9; color:#586a72; font:inherit; font-size:10px; font-weight:600; letter-spacing:.065em; text-transform:uppercase; text-align:right; }
  .w36-main-wrap table.w36-summary-table th.w36-left, .w36-main-wrap table.w36-summary-table td.w36-left { text-align:left; }
  .w36-main-wrap table.w36-summary-table td { padding:8px 10px; border:0; border-bottom:1px solid #d0d8db; text-align:right; vertical-align:middle; }
  .w36-main-wrap table.w36-summary-table tbody tr.fleet td { background:#e7eff1; font-weight:600; }
  .w36-main-wrap table.w36-summary-table tbody tr.grp td { background:#e2e8ea; font-weight:600; }
  .w36-main-wrap table.w36-summary-table tbody tr.machine td { background:#f8f9f9; }
  .w36-main-wrap table.w36-summary-table tbody tr.fleet:hover td,
  .w36-main-wrap table.w36-summary-table tbody tr.grp:hover td,
  .w36-main-wrap table.w36-summary-table tbody tr.machine:hover td { background:#d5eaee; }
  .w36-main-wrap table.w36-summary-table tbody tr.detail > td { padding:0 12px 14px; border-bottom:1px solid #d0d8db; background:#eef3f4; white-space:normal; }
  .w36-summary-table caption { padding:7px 10px; color:#586a72; text-align:left; }
  .w36-fleet-row td:first-child strong { display:block; font-size:14px; }
  .w36-fleet-row td:first-child small { display:block; margin-top:2px; color:#586a72; font-size:11px; font-weight:400; }
  .w36-group-toggle, .w36-machine-toggle { display:inline-flex; align-items:center; gap:8px; padding:0; border:0; background:transparent; color:#14232b; font:inherit; text-align:left; cursor:pointer; }
  .w36-group-toggle svg, .w36-machine-toggle svg { width:14px; height:14px; flex:none; fill:none; stroke:currentColor; stroke-width:2.4; stroke-linecap:round; stroke-linejoin:round; transition:transform .15s; }
  .w36-group-toggle[aria-expanded="true"] svg, .w36-machine-toggle[aria-expanded="true"] svg { transform:rotate(90deg); }
  .w36-group-toggle span { font-weight:700; }
  .w36-group-toggle small { margin-left:2px; color:#586a72; font-size:11px; font-weight:400; }
  .w36-machine-toggle span { display:flex; flex-direction:column; gap:1px; }
  .w36-machine-toggle b { color:#0b6e7f; font-weight:600; font-variant-numeric:tabular-nums; }
  .w36-machine-toggle small { color:#586a72; font-size:10.5px; font-weight:400; }
  .w36-status { padding:2px 7px; border-radius:5px; background:#e2e8ea; color:#586a72; font-size:10px; font-weight:500; }
  .w36-strip { display:inline-flex; gap:2px; vertical-align:middle; }
  .w36-strip i { display:block; width:13px; height:18px; border-radius:2px; background:#e2e8ea; }
  .w36-strip i.good { background:#d4ecdf; box-shadow:inset 0 0 0 1px #1f8a55; }
  .w36-strip i.warn { background:#c28a12; }
  .w36-strip i.bad { background:#c0392b; }
  .w36-strip i.prev { background:#3a6fd0; }
  .w36-strip i.neutral { background:#e2e8ea; background-image:repeating-linear-gradient(135deg,transparent,transparent 3px,#d0d8db 3px,#d0d8db 4px); }
  .w36-avbar { display:inline-flex; align-items:center; justify-content:flex-end; gap:8px; min-width:96px; }
  .w36-av-track { position:relative; display:inline-block; width:54px; height:6px; flex:none; overflow:hidden; border-radius:3px; background:#e2e8ea; }
  .w36-av-fill { position:absolute; inset:0 auto 0 0; border-radius:3px; }
  .w36-av-fill.good { background:#1f8a55; }.w36-av-fill.warn { background:#c28a12; }.w36-av-fill.bad { background:#c0392b; }.w36-av-fill.neutral { background:#586a72; }
  .w36-av-value { min-width:48px; text-align:right; font-size:11px; font-variant-numeric:tabular-nums; }
  .w36-av-value.good { color:#1f8a55; }.w36-av-value.warn { color:#80570a; }.w36-av-value.bad { color:#c0392b; }.w36-av-value.neutral { color:#586a72; }
  .w36-machine-detail { padding-top:12px; }
  .w36-detail-heading { display:flex; align-items:baseline; justify-content:space-between; gap:8px 16px; flex-wrap:wrap; margin:0 0 10px; }
  .w36-detail-heading strong { font-size:14px; }.w36-detail-heading span { color:#586a72; font-size:11px; }
  .w36-detail-grid { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(260px,1fr); gap:14px; align-items:start; }
  .w36-detail-grid h4, .w36-event-details h4 { margin:0 0 6px; font-size:12px; font-weight:700; }
  .w36-detail-scroll { overflow-x:auto; }
  .w36-detail-tbl { width:100%; border-collapse:collapse; margin:0; background:#f8f9f9; white-space:nowrap; }
  .w36-detail-tbl th, .w36-detail-tbl td { border:1px solid #d0d8db; padding:5px 7px; text-align:right; font-size:11px; }
  .w36-detail-tbl th:first-child { text-align:left; }
  .w36-detail-tbl thead th { background:#e2e8ea; color:#586a72; font-size:10px; font-weight:600; text-transform:uppercase; }
  .w36-weekly-detail { display:grid; grid-template-columns:1fr 1fr; margin:0; border:1px solid #d0d8db; border-radius:6px; overflow:hidden; }
  .w36-weekly-detail div { display:flex; flex-direction:column; gap:2px; padding:7px 9px; border-right:1px solid #d0d8db; border-bottom:1px solid #d0d8db; background:#f8f9f9; }
  .w36-weekly-detail dt { color:#586a72; font-size:9px; letter-spacing:.04em; text-transform:uppercase; }
  .w36-weekly-detail dd { margin:0; font-size:12px; font-weight:600; font-variant-numeric:tabular-nums; }
  .w36-event-details { margin-top:12px; }
  .w36-main-wrap [hidden], #w36-no-matches[hidden] { display:none!important; }
  .w36-no-matches { margin:8px 0; padding:16px; border:1px dashed #d0d8db; color:#586a72; text-align:center; }
  @media(max-width:760px) { .w36-detail-grid { grid-template-columns:1fr; }.w36-table-actions { margin-left:0; }.w36-table-tools #w36-search-count { margin-left:0; } }
  @media print { .w36-table-tools,.w36-scroll-hint,.w36-no-matches { display:none!important; }.w36-main-wrap { overflow:visible; border:0; }.w36-main-wrap table.w36-summary-table { min-width:0; font-size:8px; }.w36-main-wrap table.w36-summary-table th,.w36-main-wrap table.w36-summary-table td { position:static; padding:3px; font-size:8px; }.w36-machine-toggle svg,.w36-group-toggle svg { display:none; }.w36-weekly-detail { grid-template-columns:repeat(2,1fr); } }

  /* sheet `Synthese` — the Workshop Weekly design rules for both panels, the
     action plan and the inspection/PM registers: summary counters, a screen
     filter bar, collapsible category summary rows whose values stay aligned with
     the machine columns, text cells that wrap, and cues that never rely on
     colour alone */
  .w36-syn-kpis, .w36-reg-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin:10px 0 4px; }
  .w36-syn-kpi, .w36-reg-kpi { min-width:0; padding:11px 14px; background:#f8f9f9; border:1px solid #d0d8db; border-radius:8px; }
  .w36-syn-kpi .l, .w36-reg-kpi .l { display:block; color:#586a72; font-size:10px; font-weight:600; letter-spacing:.09em; text-transform:uppercase; }
  .w36-syn-kpi .k, .w36-reg-kpi .k { display:block; margin-top:2px; color:#14232b; font-size:24px; font-weight:600; line-height:1.12; font-variant-numeric:tabular-nums; }
  .w36-syn-kpi .k.good, .w36-reg-kpi .k.good { color:#1f8a55; } .w36-syn-kpi .k.warn, .w36-reg-kpi .k.warn { color:#c28a12; } .w36-syn-kpi .k.bad, .w36-reg-kpi .k.bad { color:#c0392b; }
  .w36-syn-kpi .s, .w36-reg-kpi .s { display:block; margin-top:3px; color:#586a72; font-size:11px; line-height:1.4; }
  .w36-syn-wrap, .w36-reg-wrap { overflow:auto; border:1px solid #d0d8db; border-radius:8px; background:#f8f9f9; }
  .w36-syn-wrap table.w36-syn-table, .w36-reg-wrap table.w36-reg-table { min-width:1060px; width:100%; border-collapse:collapse; border-spacing:0; white-space:nowrap; font:inherit; font-size:12px; color:#14232b; }
  .w36-reg-wrap table.w36-reg-table { min-width:960px; }
  .w36-syn-wrap table.w36-syn-table thead th, .w36-reg-wrap table.w36-reg-table thead th { position:sticky; top:0; z-index:2; padding:9px 10px; border:0; border-bottom:1px solid #d0d8db; background:#f8f9f9; color:#586a72; font:inherit; font-size:10px; font-weight:600; letter-spacing:.065em; text-transform:uppercase; text-align:right; }
  .w36-syn-wrap table.w36-syn-table th.w36-left, .w36-syn-wrap table.w36-syn-table td.w36-left, .w36-reg-wrap table.w36-reg-table th.w36-left, .w36-reg-wrap table.w36-reg-table td.w36-left { text-align:left; }
  .w36-syn-wrap table.w36-syn-table td, .w36-reg-wrap table.w36-reg-table td { padding:8px 10px; border:0; border-bottom:1px solid #d0d8db; text-align:right; vertical-align:top; }
  .w36-syn-wrap table.w36-syn-table tbody tr:last-child td, .w36-reg-wrap table.w36-reg-table tbody tr:last-child td { border-bottom:0; }
  .w36-syn-wrap table.w36-syn-table tbody tr.grp td, .w36-reg-wrap table.w36-reg-table tbody tr.grp td { background:#e2e8ea; font-weight:600; vertical-align:middle; }
  .w36-syn-wrap table.w36-syn-table tbody tr.machine td, .w36-reg-wrap table.w36-reg-table tbody tr.machine td { background:#f8f9f9; }
  .w36-syn-wrap table.w36-syn-table tbody tr.grp:hover td, .w36-syn-wrap table.w36-syn-table tbody tr.machine:hover td, .w36-reg-wrap table.w36-reg-table tbody tr.grp:hover td, .w36-reg-wrap table.w36-reg-table tbody tr.machine:hover td { background:#d5eaee; }
  .w36-syn-wrap table.w36-syn-table tr.machine td:first-child, .w36-reg-wrap table.w36-reg-table tr.machine td:first-child { padding-left:28px; }
  .w36-syn-wrap table.w36-syn-table td.w36-wrap, .w36-reg-wrap table.w36-reg-table td.w36-wrap { white-space:normal; text-align:left; min-width:200px; max-width:400px; }
  .w36-syn-table .w36-group-toggle span, .w36-reg-table .w36-group-toggle span { font-weight:700; }
  .w36-syn-table caption, .w36-reg-table caption { caption-side:top; padding:7px 10px; color:#586a72; text-align:left; }
  .w36-syn-machine { display:flex; flex-direction:column; align-items:flex-start; gap:1px; max-width:240px; white-space:normal; }
  .w36-syn-machine b { color:#0b6e7f; font-weight:600; font-variant-numeric:tabular-nums; }
  .w36-syn-machine small { color:#586a72; font-size:10.5px; font-weight:400; }
  .w36-syn-machine .w36-status { margin-top:3px; font-style:normal; }
  .w36-syn-wo { display:block; margin-top:6px; color:#586a72; font-size:10px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; }
  .w36-days { display:inline-block; min-width:32px; padding:2px 7px; border-radius:5px; background:#f5d5d0; color:#c0392b; font-weight:700; font-variant-numeric:tabular-nums; }
  .w36-syn-foot, .w36-reg-foot { display:flex; align-items:flex-start; gap:6px 16px; flex-wrap:wrap; margin-top:10px; color:#586a72; font-size:11px; }
  .w36-syn-foot > span, .w36-reg-foot > span { max-width:520px; }
  .w36-syn-wrap [hidden], .w36-reg-wrap [hidden] { display:none!important; }
  /* the registers: status and date stacked in one cell, and one line per service
     so a status and the reason that belongs to it never drift apart */
  .w36-reg-insp { display:flex; flex-direction:column; align-items:flex-start; gap:3px; white-space:normal; max-width:190px; }
  .w36-reg-insp small { color:#586a72; font-size:10.5px; }
  .w36-reg-counts { display:inline-block; color:#2f444d; font-size:11px; font-weight:600; letter-spacing:.02em; white-space:normal; }
  .w36-reg-pms { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:5px; min-width:220px; }
  .w36-reg-pm { display:flex; align-items:baseline; flex-wrap:wrap; gap:3px 6px; }
  .w36-reg-svc { color:#14232b; font-weight:600; font-variant-numeric:tabular-nums; }
  .w36-reg-why { color:#92400e; font-size:11px; font-style:normal; }
  .w36-reg-ev { display:block; flex-basis:100%; color:#586a72; font-size:10px; font-style:normal; }
  .w36-reg-none { color:#586a72; font-size:11px; }
  @media(max-width:760px) { .w36-syn-wrap table.w36-syn-table td.w36-wrap, .w36-reg-wrap table.w36-reg-table td.w36-wrap { min-width:170px; }.w36-syn-wrap table.w36-syn-table tr.machine td:first-child, .w36-reg-wrap table.w36-reg-table tr.machine td:first-child { padding-left:18px; }.w36-reg-pms { min-width:0; } }
  .w36-print .w36-syn-kpis, .w36-print .w36-reg-kpis { grid-template-columns:repeat(4,minmax(0,1fr)); gap:6px; margin-bottom:8px; }
  .w36-print .w36-syn-kpi, .w36-print .w36-reg-kpi { padding:6px 8px; }
  .w36-print .w36-syn-kpi .k, .w36-print .w36-reg-kpi .k { font-size:15px; }
  .w36-print .w36-syn-kpi .s, .w36-print .w36-reg-kpi .s { font-size:8px; }
  .w36-print .w36-syn-wrap, .w36-print .w36-reg-wrap { overflow:visible; border:0; border-radius:0; background:transparent; }
  .w36-print .w36-syn-wrap table.w36-syn-table, .w36-print .w36-reg-wrap table.w36-reg-table { min-width:0; font-size:8px; }
  .w36-print .w36-syn-wrap table.w36-syn-table thead th, .w36-print .w36-reg-wrap table.w36-reg-table thead th { position:static; font-size:8px; }
  .w36-print .w36-syn-wrap table.w36-syn-table td, .w36-print .w36-reg-wrap table.w36-reg-table td { padding:2px 4px; font-size:8px; }
  .w36-print .w36-syn-wrap table.w36-syn-table tr.machine td:first-child, .w36-print .w36-reg-wrap table.w36-reg-table tr.machine td:first-child { padding-left:10px; }
  .w36-print .w36-reg-insp, .w36-print .w36-reg-pms { white-space:normal; }
  .w36-print .w36-reg-insp small, .w36-print .w36-reg-why, .w36-print .w36-reg-ev { font-size:7px; }

  /* the per-type weekly block (BQ:CB): the sheet's twelve figures with the type
     leading its row, the same sticky header and availability bar as the rest of
     the page, and a computed fleet row */
  .w36-pt-panel { margin-top:16px; }
  .w36-pt-sheet { color:#586a72; font-size:10px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; }
  .w36-pt-wrap { overflow:auto; border:1px solid #d0d8db; border-radius:8px; background:#f8f9f9; }
  .w36-pt-wrap table.w36-pt-table { min-width:1080px; width:100%; border-collapse:collapse; border-spacing:0; white-space:nowrap; font:inherit; font-size:12px; color:#14232b; }
  .w36-pt-wrap table.w36-pt-table thead th { position:sticky; top:0; z-index:2; padding:9px 10px; border:0; border-bottom:1px solid #d0d8db; background:#f8f9f9; color:#586a72; font:inherit; font-size:10px; font-weight:600; letter-spacing:.065em; text-transform:uppercase; text-align:right; }
  .w36-pt-wrap table.w36-pt-table th.w36-left, .w36-pt-wrap table.w36-pt-table td.w36-left { text-align:left; }
  .w36-pt-wrap table.w36-pt-table td, .w36-pt-wrap table.w36-pt-table tbody th { padding:8px 10px; border:0; border-bottom:1px solid #d0d8db; text-align:right; vertical-align:middle; font-variant-numeric:tabular-nums; }
  .w36-pt-wrap table.w36-pt-table tbody th { font-weight:400; }
  .w36-pt-wrap table.w36-pt-table tbody tr:last-child td, .w36-pt-wrap table.w36-pt-table tbody tr:last-child th { border-bottom:0; }
  .w36-pt-wrap table.w36-pt-table tbody tr.type:hover td, .w36-pt-wrap table.w36-pt-table tbody tr.type:hover th { background:#d5eaee; }
  .w36-pt-wrap table.w36-pt-table tbody tr.fleet td, .w36-pt-wrap table.w36-pt-table tbody tr.fleet th { background:#e2e8ea; font-weight:600; }
  .w36-pt-wrap table.w36-pt-table caption { caption-side:top; padding:7px 10px; color:#586a72; text-align:left; white-space:normal; }
  .w36-pt-unit { color:#8a9aa1; font-size:9px; font-weight:600; }
  .w36-pt-type span { display:block; color:#0b6e7f; font-weight:600; }
  .w36-pt-type small { display:block; margin-top:1px; color:#586a72; font-size:10.5px; }
  .w36-pt-flag { display:inline-block; margin-top:3px; padding:0 6px; border-radius:999px; background:#f5d5d0; color:#c0392b;
                 font-size:10px; font-style:normal; font-weight:700; letter-spacing:.02em; }
  .w36-pt-foot { display:flex; align-items:flex-start; gap:6px 16px; flex-wrap:wrap; margin-top:10px; color:#586a72; font-size:11px; }
  .w36-pt-foot > span { max-width:560px; }
  .w36-pt-foot em { font-style:normal; color:#2f444d; }
  .w36-pt-wrap [hidden] { display:none!important; }
  @media(max-width:760px) { .w36-pt-wrap table.w36-pt-table { min-width:900px; } }
  .w36-print .w36-pt-panel { page-break-inside:avoid; }
  .w36-print .w36-pt-wrap { overflow:visible; border:0; border-radius:0; background:transparent; }
  .w36-print .w36-pt-wrap table.w36-pt-table { min-width:0; font-size:8px; }
  .w36-print .w36-pt-wrap table.w36-pt-table thead th { position:static; font-size:8px; }
  .w36-print .w36-pt-wrap table.w36-pt-table td, .w36-print .w36-pt-wrap table.w36-pt-table tbody th { padding:2px 4px; font-size:8px; }
  .w36-print .w36-pt-type small, .w36-print .w36-pt-flag, .w36-print .w36-pt-foot { font-size:7px; }

  /* Screen-only slate surfaces and typography, aligned with Dashboard and Transactions.
     Keep the standalone print layout and semantic status colours independent. */
  @media screen {
    .w36-screen { max-width:100%; color:#0F172A; font-family:inherit; font-size:13px; }
    .w36-screen .w36-title { font-size:20px; font-weight:700; letter-spacing:-.02em; color:#0F172A; }
    .w36-screen .w36-sub, .w36-screen .w36-small, .w36-screen .w36-legend,
    .w36-screen .w36-scroll-hint { color:#64748B; }
    .w36-screen .w36-panel { background:#fff; border:1px solid #E2E8F0; border-radius:14px; padding:16px; }
    .w36-screen .w36-panel h3 { color:#0F172A; font-size:14px; font-weight:700; }
    .w36-screen .w36-picker { background:#fff; border:1px solid #E2E8F0; border-radius:12px; }
    .w36-screen .w36-picker label, .w36-screen .w36-table-tools label { color:#475569; font-size:12px; font-weight:600; }
    .w36-screen .w36-picker input, .w36-screen .w36-table-tools input[type=search],
    .w36-screen .w36-table-tools select { min-height:35px; padding:0 10px; border:1px solid #CBD5E1; border-radius:8px; background:#fff; color:#0F172A; font:inherit; }
    .w36-screen .w36-btn, .w36-screen .w36-table-actions .w36-btn {
      display:inline-flex; align-items:center; justify-content:center; min-height:35px; padding:7px 12px;
      border:1px solid #CBD5E1; border-radius:8px; background:#fff; color:#1E293B;
      font:inherit; font-size:12.5px; font-weight:600; text-decoration:none; cursor:pointer;
    }
    .w36-screen .w36-btn:hover, .w36-screen .w36-table-actions .w36-btn:hover { background:#F8FAFC; border-color:#94A3B8; color:#0F172A; }
    .w36-screen .w36-btn-primary, .w36-screen .w36-btn-primary:hover { background:#1D4ED8; border-color:#1D4ED8; color:#fff; }
    .w36-screen .w36-btn-primary:hover { background:#1E40AF; border-color:#1E40AF; }
    .w36-screen :focus-visible { outline:3px solid #1D4ED8; outline-offset:2px; }
    .w36-screen .w36-syn-kpi, .w36-screen .w36-reg-kpi { background:#fff; border:1px solid #E2E8F0; border-radius:12px; padding:13px 15px; }
    .w36-screen .w36-syn-kpi .l, .w36-screen .w36-reg-kpi .l { color:#64748B; font-size:11px; letter-spacing:.04em; }
    .w36-screen .w36-syn-kpi .k, .w36-screen .w36-reg-kpi .k { color:#0F172A; font-size:22px; font-weight:700; line-height:1.15; }
    .w36-screen .w36-syn-kpi .k.good, .w36-screen .w36-reg-kpi .k.good { color:#047857; }
    .w36-screen .w36-syn-kpi .k.warn, .w36-screen .w36-reg-kpi .k.warn { color:#B45309; }
    .w36-screen .w36-syn-kpi .k.bad, .w36-screen .w36-reg-kpi .k.bad { color:#B91C1C; }
    .w36-screen .w36-syn-kpi .s, .w36-screen .w36-reg-kpi .s { color:#64748B; font-size:11.5px; }
    .w36-screen .w36-chart { background:#fff; border:1px solid #E2E8F0; border-radius:12px; padding:13px 15px; }
    .w36-screen .w36-chart-title { color:#0F172A; font-weight:700; }
    .w36-screen :is(.w36-main-wrap,.w36-pt-wrap,.w36-syn-wrap,.w36-reg-wrap) { background:#fff; border-color:#E2E8F0; border-radius:12px; }
    .w36-screen :is(.w36-main-wrap,.w36-pt-wrap,.w36-syn-wrap,.w36-reg-wrap) table { color:#1E293B; font-size:12.5px; }
    .w36-screen :is(.w36-main-wrap,.w36-pt-wrap,.w36-syn-wrap,.w36-reg-wrap) table thead th { background:#F8FAFC; border-color:#E2E8F0; color:#475569; font-size:11px; font-weight:700; letter-spacing:.04em; }
    .w36-screen :is(.w36-main-wrap,.w36-pt-wrap,.w36-syn-wrap,.w36-reg-wrap) table tbody :is(td,th) { border-color:#F1F5F9; }
    .w36-screen :is(.w36-main-wrap,.w36-syn-wrap,.w36-reg-wrap) table tbody tr.machine td { background:#fff; }
    .w36-screen :is(.w36-main-wrap,.w36-syn-wrap,.w36-reg-wrap) table tbody tr.grp td,
    .w36-screen :is(.w36-main-wrap,.w36-pt-wrap) table tbody tr.fleet :is(td,th) { background:#F1F5F9; }
    .w36-screen :is(.w36-main-wrap,.w36-pt-wrap,.w36-syn-wrap,.w36-reg-wrap) table.w36 tbody tr:is(.machine,.grp,.fleet,.type):hover :is(td,th) { background:#F8FAFC; }
    .w36-screen .w36-main-wrap table tbody tr.detail > td { background:#F8FAFC; border-color:#E2E8F0; }
    .w36-screen :is(.w36-machine-toggle b,.w36-syn-machine b,.w36-pt-type span) { color:#1D4ED8; }
    .w36-screen :is(.w36-group-toggle,.w36-machine-toggle) { color:#0F172A; }
    .w36-screen .w36-table-tools input[type=checkbox] { accent-color:#1D4ED8; }
    .w36-screen :is(.w36-detail-tbl,.w36-weekly-detail div) { background:#fff; }
    .w36-screen .w36-detail-tbl thead th { background:#F8FAFC; color:#475569; }
    .w36-screen :is(.w36-detail-tbl th,.w36-detail-tbl td,.w36-weekly-detail,.w36-weekly-detail div) { border-color:#E2E8F0; }
    .w36-screen :is(.w36-strip i.neutral,.w36-av-track,.w36-bar-track,.w36-meter) { background-color:#E2E8F0; }
    .w36-screen .w36-no-matches { border-color:#CBD5E1; color:#64748B; border-radius:8px; }
  }
  @media screen and (max-width:760px) { .w36-screen .w36-panel { padding:12px; } .w36-screen .w36-charts { grid-template-columns:repeat(auto-fit,minmax(min(100%, 330px),1fr)); } }
</style>

<div class="w36-wrap<?= $print_mode ? ' w36-print' : ' w36-screen' ?>">

  <?php if ($print_mode): ?>
  <style>
    /* the print/PDF sheet: the sample's own page (A4 landscape) and the same
       colours, which only survive when the browser is told to keep them */
    @page { size: A4 landscape; margin: 8mm; }
    html, body { margin:0; padding:0; background:#fff; }
    body { font-family: Arial, Helvetica, sans-serif; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .w36-print { max-width:none; margin:0; font-size:10px; }
    .w36-print-head { display:flex; align-items:center; gap:12px; border-bottom:2px solid #111827; padding-bottom:6px; margin-bottom:10px; }
    .w36-print-head img { height:42px; }
    .w36-print-title { margin:0; font-size:15px; letter-spacing:.3px; }
    .w36-print-sub { margin:3px 0 0; font-size:10px; color:#374151; }
    .w36-print-stamp { margin-left:auto; text-align:right; font-size:9px; color:#374151; line-height:1.5; }
    .w36-print-hint { border:1px dashed #9ca3af; padding:4px 6px; font-size:9px; color:#4b5563; margin-bottom:8px; }
    .w36-print table.w36 { font-size:8px; }
    .w36-print table.w36 thead th { position:static; font-size:8px; }
    .w36-print th, .w36-print td { padding:1px 3px; }
    .w36-print .w36-tblwrap { overflow:visible; border-radius:0; }
    .w36-print .w36-panel { page-break-inside:avoid; }
    .w36-print details > summary { display:none; }
    .w36-print .w36-detail-tbl { font-size:7px; }
    .w36-print .w36-small, .w36-print .w36-legend { font-size:9px; }
    .w36-print-foot { border-top:1px solid #9ca3af; margin-top:10px; padding-top:5px; font-size:9px; color:#374151; }
    .w36-print-foot .w36-sign { margin-top:6px; font-size:9px; }
    thead { display:table-header-group; }
    tr, .w36-chart, .w36-panel, .w36-syn-kpi, .w36-reg-kpi { page-break-inside:avoid; }
  </style>
  <div class="w36-print-head">
    <img src="<?= $esc(isset($logo_url) ? $logo_url : base_url('uploads/logoam.png')) ?>" alt="A&amp;M">
    <div>
      <h1 class="w36-print-title">WORKSHOP WEEKLY REPORT &mdash; <?= $esc(strtoupper((string)($report_name))) ?> &mdash; <?= $esc($week_label) ?></h1>
      <p class="w36-print-sub">
        <?= $esc(date('d/m/Y', strtotime($week['start']))) ?> &rarr; <?= $esc(date('d/m/Y', strtotime($week['end']))) ?> ·
        ISO week <?= $esc(isset($week['iso_week']) ? $week['iso_week'] : '') ?> ·
        open time <?= $esc($num(isset($week['open_hours']) ? $week['open_hours'] : null, 0)) ?> h ·
        <?= (int)$machine_total ?> reportable machines ·
        <?php $ph = $weekly_cells($fleet, isset($week['open_hours']) ? $week['open_hours'] : null); ?>
        <?= $esc($ph['f']) ?> breakdown(s) · <?= $esc($ph['dt']) ?> h down · fleet availability <?= $esc($ph['av']) ?> vs target <?= $esc($ph['target']) ?>
      </p>
    </div>
    <div class="w36-print-stamp">
      Breakdowns <?= (int)(isset($fleet['rollup']['breakdowns']) ? $fleet['rollup']['breakdowns'] : 0) ?>
      · days down <?= (int)(isset($fleet['rollup']['days_down']) ? $fleet['rollup']['days_down'] : 0) ?>
      · hours down <?= $num(isset($fleet['rollup']['hours_down']) ? $fleet['rollup']['hours_down'] : null, 2) ?><br>
      <?php if ($snapshot !== null): ?>
        <strong>PUBLISHED</strong> <?= $esc($snapshot['published_at']) ?>
      <?php elseif ($published !== null): ?>
        <strong>LIVE READING</strong> — published <?= $esc($published['published_at']) ?><br>
        figures recomputed to <?= $esc(date('d/m/Y', strtotime(isset($week['cutoff']) ? $week['cutoff'] : 'today'))) ?>
      <?php else: ?>
        <strong>DRAFT</strong> — not published yet
      <?php endif; ?>
      <br>printed <?= $esc($generated_at) ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$print_mode): ?>
  <?php $this->load->view('reports/_fleet_nav', array('fleet_nav_active' => 'weekly', 'week_start' => $week['start'])); ?>
  <div class="w36-head">
    <div>
      <h1 class="w36-title">Workshop Weekly Report — <?= $esc($report_name) ?></h1>
      <p class="w36-sub">
        <?= $esc($week_label) ?> ·
        <?= $esc(date('D d M Y', strtotime($week['start']))) ?> → <?= $esc(date('D d M Y', strtotime($week['end']))) ?> ·
        open time <?= $esc($num($week['open_hours'], 0)) ?> h (<?= $esc($num($week['daily_open_hours'], 0)) ?> h/day) ·
        <?= (int)$machine_total ?> reportable machines
      </p>
    </div>
    <div>
      <a class="w36-btn w36-btn-primary"
         href="<?= $esc(base_url('reports/export_workshop_weekly_xlsx?week=' . $week_label . ($snapshot === null && $published !== null ? '&live=1' : ''))) ?>">Download Excel</a>
      <a class="w36-btn" target="_blank" rel="noopener"
         href="<?= $esc(base_url('reports/export_workshop_weekly_pdf?week=' . $week_label . ($snapshot === null && $published !== null ? '&live=1' : ''))) ?>">Print / PDF</a>
      <?php if ($may_publish && $published === null): ?>
        <form method="post" style="display:inline"
              action="<?= $esc(base_url('reports/publish_workshop_weekly?week=' . $week_label)) ?>">
          <?= $csrf_field() ?>
          <button type="submit" class="w36-btn">Publish report</button>
        </form>
      <?php elseif ($may_publish && $snapshot === null): ?>
        <?php /* live reading of a published week: only a forced re-freeze is left */ ?>
        <form method="post" style="display:inline"
              action="<?= $esc(base_url('reports/publish_workshop_weekly?week=' . $week_label)) ?>">
          <?= $csrf_field() ?>
          <input type="hidden" name="force" value="1">
          <button type="submit" class="w36-btn">Publish current figures</button>
        </form>
        <span class="w36-badge ok">published</span>
      <?php elseif ($published !== null): ?>
        <span class="w36-badge ok">published</span>
      <?php endif; ?>
      <div class="w36-small"><?php if ($snapshot === null && $published !== null): ?>Excel downloads the published report; Print / PDF uses this live reading.<?php else: ?>Downloads use this week’s <?= $snapshot !== null ? 'published report' : 'live reading' ?>.<?php endif; ?> · <?= $esc($week_label) ?></div>
    </div>
  </div>
  <?php endif; /* !$print_mode — the toolbar is screen chrome */ ?>

  <?php if ($notice !== null): ?>
    <div class="w36-note warn"><?= $esc($notice) ?></div>
  <?php endif; ?>

  <?php if ($snapshot !== null): ?>
    <div class="w36-note ok">
      <strong>Published report</strong> — saved on <?= $esc($snapshot['published_at']) ?>
      <?php if (empty($snapshot['complete'])): ?>
        <span class="w36-badge err">The saved report could not be verified. Please contact an administrator.</span>
      <?php endif; ?>
      <div class="w36-small">
        This week is read <strong>as published</strong>: the numbers below — and every download of it — are the frozen ones,
        so later work orders cannot change them. Days down are counted up to the week's last day.
        <a href="<?= $esc(base_url('reports/workshop_weekly?week=' . $week_label . '&live=1')) ?>">See the live reading</a>
        <?php if ($may_publish): ?> ·
          <form method="post" style="display:inline"
                action="<?= $esc(base_url('reports/publish_workshop_weekly?week=' . $week_label)) ?>">
            <?= $csrf_field() ?>
            <input type="hidden" name="force" value="1">
            <button type="submit" class="w36-btn" style="padding:2px 8px;font-size:12px">Re-publish</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php elseif ($is_live): ?>
    <div class="w36-note warn">
      <strong>Live reading</strong> — a machine that is still down keeps counting to <em>today</em>.
      <div class="w36-small">
        <?php if ($published !== null): ?>
          This week was published on <?= $esc($published['published_at']) ?>; the figures below are today's, not the frozen ones.
          <a href="<?= $esc(base_url('reports/workshop_weekly?week=' . $week_label)) ?>">Back to the published reading</a>
        <?php else: ?>
          Publish the week to freeze these figures at its last day and to make a re-download reproducible.
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$print_mode): /* the picker is screen chrome — paper has no links */ ?>
  <form class="w36-picker" method="get" action="<?= $esc(base_url('reports/workshop_weekly')) ?>">
    <?php if ($live_query !== ''): ?><input type="hidden" name="live" value="1"><?php endif; ?>
    <a class="w36-btn" href="<?= $esc(base_url('reports/workshop_weekly?week=' . $prev_week . $live_query)) ?>" title="previous week">◀ <?= $esc($prev_week) ?></a>
    <label>Week
      <input type="week" name="week" value="<?= $esc($week_label) ?>">
    </label>
    <button type="submit" class="w36-btn w36-btn-primary">Show week</button>
    <a class="w36-btn" href="<?= $esc(base_url('reports/workshop_weekly?week=' . $next_week . $live_query)) ?>" title="next week"><?= $esc($next_week) ?> ▶</a>
  </form>
  <form class="w36-picker" method="get" action="<?= $esc(base_url('reports/workshop_weekly')) ?>">
    <?php if ($live_query !== ''): ?><input type="hidden" name="live" value="1"><?php endif; ?>
    <label>Or select any date in the week <input type="date" name="week_start" value="<?= $esc($week['start']) ?>"></label>
    <button type="submit" class="w36-btn">Show date’s week</button>
  </form>
  <form class="w36-picker" method="post" action="<?= $esc(base_url('reports/weekly_report_name')) ?>">
    <?= $csrf_field() ?>
    <input type="hidden" name="week" value="<?= $esc($week_label) ?>">
    <label>Report name
      <input type="text" name="report_name" value="<?= $esc($report_name) ?>" maxlength="22" required<?= $snapshot !== null ? ' readonly' : '' ?>>
    </label>
    <span class="w36-small">Used on the report and downloads<?= $snapshot !== null ? '; saved with the published report' : '' ?>.</span>
    <?php if ($may_publish && $snapshot === null): ?><button type="submit" class="w36-btn">Save name</button><?php endif; ?>
  </form>
  <?php endif; ?>

  <?php /* ── data readiness: concise summary with actionable, collapsed details ─ */ ?>
  <div class="w36-panel">
    <h3>Data readiness</h3>
    <div class="w36-badges">
      <?php if (!empty($by_level['error'])): ?>
        <span class="w36-badge err"><?= count($by_level['error']) ?> error(s)</span>
      <?php endif; ?>
      <span class="w36-badge <?= count($by_level['warn']) ? 'wrn' : 'ok' ?>"><?= count($by_level['warn']) ?> warning(s)</span>
      <span class="w36-badge inf"><?= count($by_level['info']) ?> note(s)</span>
      <span class="w36-badge <?= ($machine_total && $with_data === 0) ? 'wrn' : 'ok' ?>"><?= (int)$with_data ?> of <?= (int)$machine_total ?> machines have records this week</span>
      <span class="w36-badge inf"><?= (int)$down_machines ?> machine(s) down at some point</span>
      <?php if (!empty($meta['unclassified'])): ?>
        <span class="w36-badge wrn"><?= (int)$meta['unclassified'] ?> unclassified machine(s)</span>
      <?php endif; ?>
    </div>

    <?php if ($machine_total > 0 && $with_data === 0): ?>
      <div class="w36-note warn" style="margin-top:10px">
        <strong>No work-order or labour records overlap <?= $esc($week_label) ?>.</strong>
        The report will not infer uptime from current equipment status. Confirm the selected week, then enter real events with their equipment and timestamps.
        <?php if ($status_without_wo_count > 0): ?><br><strong><?= (int)$status_without_wo_count ?> machine(s) are currently marked down without a matching open breakdown work order.</strong><?php endif; ?>
        <span style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
          <a class="w36-btn" href="<?= $esc(base_url('reports/downtime_log?week=' . rawurlencode($week_label))) ?>">Open downtime log</a>
          <?php if ($can_create_wo): ?><a class="w36-btn w36-btn-primary" href="<?= $esc(base_url('workorders/create?fleet_availability=1&fleet_kind=breakdown&week_start=' . rawurlencode($week['start']))) ?>">Create a Corrective WO</a><?php endif; ?>
        </span>
      </div>
    <?php elseif ($status_without_wo_count > 0): ?>
      <div class="w36-note warn" style="margin-top:10px">
        <strong><?= (int)$status_without_wo_count ?> machine(s) are marked down without a matching open breakdown work order.</strong>
        For each real failure, log a Corrective WO with the actual start time; if the machine is not down, reconcile its equipment status. Neither action is applied automatically.
      </div>
    <?php endif; ?>

    <?php if ($issue_count === 0): ?>
      <div class="w36-note ok" style="margin-top:10px">No errors or warnings were found. Notes are available below.</div>
    <?php endif; ?>

    <?php foreach (array('error' => 'Errors', 'warn' => 'Warnings', 'info' => 'Notes') as $level => $label): if (empty($by_level[$level])) continue; ?>
      <details class="w36-quality-details" <?= $print_mode ? 'open' : '' ?> style="margin-top:10px;border-top:1px solid #e5e7eb;padding-top:8px;">
        <summary style="cursor:pointer;font-weight:700;font-size:12px;">
          <?= (int)count($by_level[$level]) ?> <?= $esc(strtolower($label)) ?> — review affected records and available fixes
        </summary>
        <ul class="w36-qlist" style="margin-top:8px;max-height:340px;overflow:auto;padding-right:8px;">
          <?php foreach ($by_level[$level] as $q):
            $equipment_id = isset($q['equipment_id']) ? (int)$q['equipment_id'] : 0;
            $issue_code = isset($q['code']) ? (string)$q['code'] : '';
            $action_url = null; $action_label = null;
            if ($issue_code === 'status_without_wo' && $can_create_wo && $equipment_id > 0) {
                $action_url = 'workorders/create?fleet_availability=1&fleet_kind=breakdown&equipment_id=' . $equipment_id . '&week_start=' . rawurlencode($week['start']);
                $action_label = 'Create breakdown WO';
            } elseif ($issue_code === 'wo_without_status' && !empty($q['work_order_ids'][0])) {
                $action_url = 'workorders/' . ($can_create_wo ? 'edit/' : 'view/') . (int)$q['work_order_ids'][0];
                $action_label = $can_create_wo ? 'Correct open WO' : 'View open WO';
            } elseif ($issue_code === 'no_target' && $can_edit_equipment) {
                $action_url = 'reports/weekly_targets?week_start=' . rawurlencode($week['start']);
                $action_label = 'Review targets';
            } elseif (in_array($issue_code, array('no_group', 'no_site', 'site_not_approved'), true) && $can_edit_equipment && $equipment_id > 0) {
                $action_url = 'equipment/edit/' . $equipment_id;
                $action_label = 'Edit equipment';
            }
          ?>
            <li style="padding:4px 0;">
              <?= $esc($quality_message($q)) ?>
              <?php if ($action_url): ?> <a class="w36-btn" style="min-height:28px;padding:3px 8px;margin-left:5px;" href="<?= $esc(base_url($action_url)) ?>"><?= $esc($action_label) ?> <span aria-hidden="true">→</span></a><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endforeach; ?>
  </div>

  <?php /* ── the machine table, restyled to match Workshop Weekly ─────── */ ?>
  <?php if (!$print_mode): ?>
  <div class="w36-table-tools" id="w36-search-tools" hidden>
    <label class="w36-search-label" for="w36-machine-search">Find a machine</label>
    <input id="w36-machine-search" type="search" placeholder="ID, model or location" autocomplete="off">
    <label class="w36-filter-location"><span class="w36-sr-only">Filter by location</span>
      <select id="w36-location-filter"><option value="">All locations</option><?php foreach ($locations as $location): ?><option value="<?= $esc(function_exists('mb_strtolower') ? mb_strtolower($location, 'UTF-8') : strtolower($location)) ?>"><?= $esc($location) ?></option><?php endforeach; ?></select>
    </label>
    <label class="w36-filter-below"><input id="w36-below-target" type="checkbox"> <span>Below target only</span></label>
    <span id="w36-search-count" role="status" aria-live="polite"></span>
    <span class="w36-table-actions"><button type="button" class="w36-btn" data-w36-action="expand">Expand all</button><button type="button" class="w36-btn" data-w36-action="collapse">Collapse all</button></span>
  </div>
  <p class="w36-scroll-hint">Filter equipment and expand a type or machine for daily and weekly detail. Filters do not change totals or downloads.</p>
  <?php endif; ?>
  <p class="w36-legend">MTBF: average time between failures. MTTR: average time to repair. Hours are shown as h.</p>
  <div class="w36-tblwrap w36-main-wrap" tabindex="0" role="region" aria-label="Weekly machine report">
    <table class="w36 w36-summary-table" id="w36-machine-table">
      <caption class="w36-small">Fleet, group and machine availability for <?= $esc($week_label) ?></caption>
      <thead><tr>
        <th class="w36-left">Equipment</th>
        <th class="w36-left">Daily availability · 7 days</th>
        <th>Preventive h</th><th>Down h</th><th>Failures</th><th>MTBF h</th><th>MTTR h</th><th>Availability</th><th>Target</th>
      </tr></thead>
      <tbody>
        <?php if (!empty($fleet)): $fc = $weekly_cells($fleet, isset($week['open_hours']) ? $week['open_hours'] : null); $fr = isset($fleet['rollup']) ? $fleet['rollup'] : array(); ?>
          <tr class="fleet w36-fleet-row">
            <td class="w36-left"><strong>Fleet</strong><small><?= (int)$machine_total ?> reportable machines · <?= (int)$fleet_available ?> available · <?= (int)$down_machines ?> down</small></td>
            <td class="w36-left"><?= $daily_strip(isset($fleet['daily']) ? $fleet['daily'] : array()) ?></td>
            <td><?= $esc(isset($fc['pt']) ? $fc['pt'] : '—') ?></td>
            <td><?= $esc(isset($fc['dt']) ? $fc['dt'] : '—') ?></td>
            <td><?= $esc(isset($fc['f']) ? $fc['f'] : '—') ?></td>
            <td><?= $esc(isset($fc['mtbf']) ? $fc['mtbf'] : '—') ?></td>
            <td><?= $esc(isset($fc['mttr']) ? $fc['mttr'] : '—') ?></td>
            <td><?= $availability_bar(isset($fr['availability']) ? $fr['availability'] : null, isset($fr['weekly_target']) ? $fr['weekly_target'] : null) ?></td>
            <td><?= $esc(isset($fc['target']) ? $fc['target'] : '—') ?></td>
          </tr>
        <?php endif; ?>

        <?php foreach ($groups as $g): ?>
          <?php
            if ((int)$g['row_count'] === 0) continue;
            $pt = isset($per_type[$g['code']]) ? $per_type[$g['code']] : array();
            $roll = isset($g['rollup']) ? $g['rollup'] : array();
            $gc = $weekly_cells($g, isset($week['open_hours']) ? $week['open_hours'] : null);
            $group_id = 'w36-group-'.substr(sha1((string)$g['code']), 0, 10);
          ?>
          <tr class="grp w36-group-row" data-group-row="<?= $esc($group_id) ?>" data-open="<?= $print_mode ? '1' : '0' ?>">
            <td class="w36-left"><button type="button" class="w36-group-toggle" data-w36-action="toggle-group" data-group="<?= $esc($group_id) ?>" aria-expanded="<?= $print_mode ? 'true' : 'false' ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg><span><?= $esc($g['label']) ?></span><small><?= (int)$g['row_count'] ?> machines · <?= (int)(isset($pt['available']) ? $pt['available'] : 0) ?> available · <?= (int)(isset($pt['down']) ? $pt['down'] : 0) ?> down</small>
            </button></td>
            <td class="w36-left"><?= $daily_strip(isset($g['daily']) ? $g['daily'] : array()) ?></td>
            <td><?= $esc(isset($gc['pt']) ? $gc['pt'] : '—') ?></td>
            <td><?= $esc(isset($gc['dt']) ? $gc['dt'] : '—') ?></td>
            <td><?= $esc(isset($gc['f']) ? $gc['f'] : '—') ?></td>
            <td><?= $esc(isset($gc['mtbf']) ? $gc['mtbf'] : '—') ?></td>
            <td><?= $esc(isset($gc['mttr']) ? $gc['mttr'] : '—') ?></td>
            <td><?= $availability_bar(isset($roll['availability']) ? $roll['availability'] : null, isset($roll['weekly_target']) ? $roll['weekly_target'] : null) ?></td>
            <td><?= $esc(isset($gc['target']) ? $gc['target'] : '—') ?></td>
          </tr>

          <?php foreach ($g['machines'] as $id): if (empty($machines[$id])) continue; $m = $machines[$id]; $mw = isset($m['weekly']) ? $m['weekly'] : array(); $mc = $weekly_cells($mw, isset($week['open_hours']) ? $week['open_hours'] : null); $machine_id = 'w36-machine-'.$id; $detail_id = 'w36-detail-'.$id; $machine_target = isset($mw['weekly_target']) ? $mw['weekly_target'] : null; $av = isset($mw['availability']) ? $mw['availability'] : null; $search_text = $m['machine_id'].' '.$m['model'].' '.(isset($m['site']['label']) ? $m['site']['label'] : ''); $location_text = trim((string)(isset($m['site']['label']) ? $m['site']['label'] : '')); $machine_down = isset($mw['hours_down']) ? (float)$mw['hours_down'] > 0 : (isset($mw['downtime_hours']) && (float)$mw['downtime_hours'] > 0); ?>
            <tr class="machine w36-machine-row" id="<?= $esc($machine_id) ?>" data-machine-search="<?= $esc($search_text) ?>" data-location="<?= $esc(function_exists('mb_strtolower') ? mb_strtolower($location_text, 'UTF-8') : strtolower($location_text)) ?>" data-below="<?= $misses($av, $machine_target) ? '1' : '0' ?>" data-parent="<?= $esc($group_id) ?>" data-detail="<?= $esc($detail_id) ?>" data-detail-open="0" <?= $print_mode ? '' : 'hidden' ?>>
              <td class="w36-left w36-equipment-cell"><button type="button" class="w36-machine-toggle" data-w36-action="toggle-machine" data-detail="<?= $esc($detail_id) ?>" aria-expanded="false" aria-controls="<?= $esc($detail_id) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg><span><b><?= $esc($m['machine_id']) ?></b><small><?= $esc($m['model']) ?> · <?= $esc($location_text !== '' ? $location_text : 'Site not set') ?></small></span><em class="w36-status"><?= $esc($m['status'] !== null ? $m['status'] : 'Status unknown') ?></em>
              </button></td>
              <td class="w36-left"><?= $daily_strip(isset($m['daily']) ? $m['daily'] : array()) ?></td>
              <td><?= $esc(isset($mc['pt']) ? $mc['pt'] : '—') ?></td>
              <td><?= $esc(isset($mc['dt']) ? $mc['dt'] : '—') ?></td>
              <td><?= $esc(isset($mc['f']) ? $mc['f'] : '—') ?></td>
              <td><?= $esc(isset($mc['mtbf']) ? $mc['mtbf'] : '—') ?></td>
              <td><?= $esc(isset($mc['mttr']) ? $mc['mttr'] : '—') ?></td>
              <td><?= $availability_bar($av, $machine_target) ?></td>
              <td><?= $esc(isset($mc['target']) ? $mc['target'] : '—') ?></td>
            </tr>
            <tr class="detail w36-detail-row" id="<?= $esc($detail_id) ?>" data-detail-for="<?= $esc($machine_id) ?>" <?= $print_mode ? '' : 'hidden' ?>>
              <td colspan="9">
                <div class="w36-machine-detail">
                  <div class="w36-detail-heading"><strong><?= $esc($m['machine_id']) ?> · Weekly details</strong><span>
                    <?php if (!empty($m['events'])): ?><?= count($m['events']) ?> breakdown event(s) · <?= (int)(isset($mw['days_down']) ? $mw['days_down'] : 0) ?> day(s) down · <?= $esc($num(isset($mw['hours_down']) ? $mw['hours_down'] : null, 2)) ?> h down<?php else: ?>No breakdown events this week<?php endif; ?>
                    <?php if ((int)(isset($m['unresolved_target_days']) ? $m['unresolved_target_days'] : 0) > 0): ?> · <?= (int)$m['unresolved_target_days'] ?> day(s) without a resolved target<?php endif; ?>
                  </span></div>
                  <div class="w36-detail-grid">
                    <section><h4>Daily figures</h4><div class="w36-detail-scroll"><table class="w36-detail-tbl">
                      <thead><tr><th>Measure</th><?php foreach ($days as $d): ?><th><?= $esc($day_name($d)) ?></th><?php endforeach; ?><th>Week</th></tr></thead>
                      <tbody>
                      <?php foreach ($detail_rows as $label => $key): ?>
                        <tr><th><?= $esc($label) ?></th>
                        <?php foreach ($days as $i => $d): $dd = $day_row(isset($m['daily']) ? $m['daily'] : array(), $d, $i); $val = $dd && array_key_exists($key, $dd) ? $dd[$key] : null; ?>
                          <td class="<?= $misses($key === 'availability' ? $val : null, $key === 'availability' && $dd ? $dd['target'] : null) ? 'miss' : '' ?>"><?= $esc($key === 'availability' || $key === 'target' ? $pct($val) : $num($val, 2)) ?></td>
                        <?php endforeach; ?>
                        <?php $wk = isset($mw[$detail_week_key[$key]]) ? $mw[$detail_week_key[$key]] : null; ?>
                        <td><strong><?= $esc($key === 'availability' || $key === 'target' ? $pct($wk) : $num($wk, 2)) ?></strong></td></tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table></div></section>
                    <section><h4>Weekly figures</h4><dl class="w36-weekly-detail">
                      <?php foreach ($weekly_cols as $key => $label): $cell_key = array('days'=>'days','open'=>'open','pt'=>'pt','rt'=>'rt','dt'=>'dt','f'=>'f','mtbf'=>'mtbf','mttr'=>'mttr','av'=>'av','target'=>'target'); ?>
                        <div><dt><?= $esc($label) ?></dt><dd><?= $esc(isset($mc[$cell_key[$key]]) ? $mc[$cell_key[$key]] : '—') ?></dd></div>
                      <?php endforeach; ?>
                    </dl></section>
                  </div>
                  <?php if (!empty($m['events'])): ?><section class="w36-event-details"><h4>Breakdown events in this period</h4><ul class="w36-evts">
                    <?php foreach ($m['events'] as $e): ?><li><?= $esc($e['start']) ?> → <?= $e['open'] ? '<span class="open">still down</span>' : $esc($e['end']) ?><?php if (!empty($e['carried_in'])): ?> <em>(started before the week)</em><?php endif; ?><?php if (empty($e['counts_as_breakdown'])): ?> <em>(not counted as a new breakdown)</em><?php endif; ?></li><?php endforeach; ?>
                  </ul></section><?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>

        <?php if (!empty($unclassified_ids)): $unclassified_group = 'w36-group-unclassified'; ?>
          <tr class="grp w36-group-row" data-group-row="<?= $esc($unclassified_group) ?>" data-open="<?= $print_mode ? '1' : '0' ?>">
            <td class="w36-left"><button type="button" class="w36-group-toggle" data-w36-action="toggle-group" data-group="<?= $esc($unclassified_group) ?>" aria-expanded="<?= $print_mode ? 'true' : 'false' ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg><span>Unclassified</span><small><?= count($unclassified_ids) ?> machines · no type roll-up</small>
            </button></td>
            <td class="w36-left"><span class="w36-muted">Daily values appear on each machine</span></td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td>
          </tr>
          <?php foreach ($unclassified_ids as $id): $m = $machines[$id]; $mw = isset($m['weekly']) ? $m['weekly'] : array(); $mc = $weekly_cells($mw, isset($week['open_hours']) ? $week['open_hours'] : null); $machine_id = 'w36-machine-'.$id; $detail_id = 'w36-detail-'.$id; $machine_target = isset($mw['weekly_target']) ? $mw['weekly_target'] : null; $av = isset($mw['availability']) ? $mw['availability'] : null; $search_text = $m['machine_id'].' '.$m['model'].' '.(isset($m['site']['label']) ? $m['site']['label'] : ''); $location_text = trim((string)(isset($m['site']['label']) ? $m['site']['label'] : '')); ?>
            <tr class="machine w36-machine-row" id="<?= $esc($machine_id) ?>" data-machine-search="<?= $esc($search_text) ?>" data-location="<?= $esc(function_exists('mb_strtolower') ? mb_strtolower($location_text, 'UTF-8') : strtolower($location_text)) ?>" data-below="<?= $misses($av, $machine_target) ? '1' : '0' ?>" data-parent="<?= $esc($unclassified_group) ?>" data-detail="<?= $esc($detail_id) ?>" data-detail-open="0" <?= $print_mode ? '' : 'hidden' ?>>
              <td class="w36-left w36-equipment-cell"><button type="button" class="w36-machine-toggle" data-w36-action="toggle-machine" data-detail="<?= $esc($detail_id) ?>" aria-expanded="false" aria-controls="<?= $esc($detail_id) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg><span><b><?= $esc($m['machine_id']) ?></b><small><?= $esc($m['model']) ?> · <?= $esc($location_text !== '' ? $location_text : 'Site not set') ?></small></span><em class="w36-status"><?= $esc($m['status'] !== null ? $m['status'] : 'Status unknown') ?></em></button></td>
              <td class="w36-left"><?= $daily_strip(isset($m['daily']) ? $m['daily'] : array()) ?></td><td><?= $esc(isset($mc['pt']) ? $mc['pt'] : '—') ?></td><td><?= $esc(isset($mc['dt']) ? $mc['dt'] : '—') ?></td><td><?= $esc(isset($mc['f']) ? $mc['f'] : '—') ?></td><td><?= $esc(isset($mc['mtbf']) ? $mc['mtbf'] : '—') ?></td><td><?= $esc(isset($mc['mttr']) ? $mc['mttr'] : '—') ?></td><td><?= $availability_bar($av, $machine_target) ?></td><td><?= $esc(isset($mc['target']) ? $mc['target'] : '—') ?></td>
            </tr>
            <tr class="detail w36-detail-row" id="<?= $esc($detail_id) ?>" data-detail-for="<?= $esc($machine_id) ?>" <?= $print_mode ? '' : 'hidden' ?>><td colspan="9"><div class="w36-machine-detail"><div class="w36-detail-heading"><strong><?= $esc($m['machine_id']) ?> · Weekly details</strong><span><?= !empty($m['events']) ? count($m['events']).' breakdown event(s)' : 'No breakdown events this week' ?><?php if ((int)(isset($m['unresolved_target_days']) ? $m['unresolved_target_days'] : 0) > 0): ?> · <?= (int)$m['unresolved_target_days'] ?> day(s) without a resolved target<?php endif; ?></span></div><div class="w36-detail-grid"><section><h4>Daily figures</h4><div class="w36-detail-scroll"><table class="w36-detail-tbl"><thead><tr><th>Measure</th><?php foreach ($days as $d): ?><th><?= $esc($day_name($d)) ?></th><?php endforeach; ?><th>Week</th></tr></thead><tbody><?php foreach ($detail_rows as $label => $key): ?><tr><th><?= $esc($label) ?></th><?php foreach ($days as $i => $d): $dd = $day_row(isset($m['daily']) ? $m['daily'] : array(), $d, $i); $val = $dd && array_key_exists($key, $dd) ? $dd[$key] : null; ?><td><?= $esc($key === 'availability' || $key === 'target' ? $pct($val) : $num($val, 2)) ?></td><?php endforeach; ?><?php $wk = isset($mw[$detail_week_key[$key]]) ? $mw[$detail_week_key[$key]] : null; ?><td><strong><?= $esc($key === 'availability' || $key === 'target' ? $pct($wk) : $num($wk, 2)) ?></strong></td></tr><?php endforeach; ?></tbody></table></div></section><section><h4>Weekly figures</h4><dl class="w36-weekly-detail"><?php foreach ($weekly_cols as $key => $label): ?><div><dt><?= $esc($label) ?></dt><dd><?= $esc(isset($mc[$key]) ? $mc[$key] : '—') ?></dd></div><?php endforeach; ?></dl></section></div><?php if (!empty($m['events'])): ?><section class="w36-event-details"><h4>Breakdown events in this period</h4><ul class="w36-evts"><?php foreach ($m['events'] as $e): ?><li><?= $esc($e['start']) ?> → <?= $e['open'] ? '<span class="open">still down</span>' : $esc($e['end']) ?></li><?php endforeach; ?></ul></section><?php endif; ?></div></td></tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div id="w36-no-matches" class="w36-no-matches" hidden>No machines match these filters.</div>

  <?php /* ── the per-type block (BQ:CB), in the same design language as the two
           `Synthese` panels below: the type leads its row, the sheet's own headers
           are spelled out, availability is a bar against its target, and a computed
           fleet row closes the table so nothing has to be added up by eye */ ?>
  <?php
    $pt_rows = array();
    foreach ($groups as $g) {
        if ((int)$g['row_count'] === 0) continue;
        $pt_rows[] = $g;
    }
    $pt_fleet = isset($fleet['rollup']) ? $fleet['rollup'] : null;
  ?>
  <div class="w36-panel w36-pt-panel">
    <h3>Weekly performance by equipment type</h3>
    <p class="w36-legend" style="margin-top:4px">Compare each equipment type with its availability target. The fleet row summarizes all types.</p>
    <?php if (empty($pt_rows)): ?>
      <div class="w36-note">No machines to report for this week.</div>
    <?php else: ?>
      <div class="w36-tblwrap w36-pt-wrap" tabindex="0" role="region" aria-label="Per-type weekly block — scroll horizontally for every column">
        <table class="w36 w36-pt-table" id="w36-pt-table">
          <caption class="w36-small">One row per equipment type: fleet counts, open time, preventive and required hours, downtime, failures, MTBF, MTTR, and operational availability against the weekly target</caption>
          <thead>
            <tr>
              <th class="w36-left" scope="col">Equipment type</th>
              <th scope="col">Available</th>
              <th scope="col">Down</th>
              <th scope="col">Open time <span class="w36-pt-unit">h</span></th>
              <th scope="col">Preventive <span class="w36-pt-unit">h</span></th>
              <th scope="col">Required <span class="w36-pt-unit">h</span></th>
              <th scope="col">Down <span class="w36-pt-unit">h</span></th>
              <th scope="col">Failures</th>
              <th scope="col">MTBF <span class="w36-pt-unit">h</span></th>
              <th scope="col">MTTR <span class="w36-pt-unit">h</span></th>
              <th scope="col">Availability</th>
              <th scope="col">Target</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($pt_rows as $g):
            $pt     = isset($per_type[$g['code']]) ? $per_type[$g['code']] : array();
            $r      = $g['rollup'];
            $pt_below = $misses(isset($r['availability']) ? $r['availability'] : null, isset($r['weekly_target']) ? $r['weekly_target'] : null);
          ?>
            <tr class="type w36-pt-row" data-type-search="<?= $esc($g['label']) ?>">
              <th class="w36-left w36-pt-type" scope="row">
                <span><?= $esc($g['label']) ?></span>
                <small><?= (int)$g['row_count'] ?> machine<?= (int)$g['row_count'] === 1 ? '' : 's' ?></small>
                <?php if ($pt_below): ?><em class="w36-pt-flag" title="Availability is below this type's weekly target KPI">below target</em><?php endif; ?>
              </th>
              <td><?= (int)(isset($pt['available']) ? $pt['available'] : 0) ?></td>
              <td><?= (int)(isset($pt['down']) ? $pt['down'] : 0) ?></td>
              <td title="The week's open hours per machine — the same for every type"><?= $esc($num($week['open_hours'], 0)) ?></td>
              <td><?= $esc($num($r['preventive_hours'], 2)) ?></td>
              <td><?= $esc($num($r['required_time_hours'], 2)) ?></td>
              <td><?php if ((float)$r['downtime_hours'] > 0): ?><span class="w36-days" title="Hours of downtime recorded this week"><?= $esc($num($r['downtime_hours'], 2)) ?></span><?php else: ?><?= $esc($num(isset($r['downtime_hours']) ? $r['downtime_hours'] : null, 2)) ?><?php endif; ?></td>
              <td><?= $esc($num($r['breakdowns'], 0)) ?></td>
              <td><?= $esc($num($r['mtbf_hours'], 2)) ?></td>
              <td><?= $esc($num($r['mttr_hours'], 2)) ?></td>
              <td><?= $availability_bar(isset($r['availability']) ? $r['availability'] : null, isset($r['weekly_target']) ? $r['weekly_target'] : null) ?></td>
              <td><?= $esc($pct(isset($r['weekly_target']) ? $r['weekly_target'] : null)) ?></td>
            </tr>
          <?php endforeach; ?>

          <?php if ($pt_fleet !== null): $pt_fleet_below = $misses(isset($pt_fleet['availability']) ? $pt_fleet['availability'] : null, isset($pt_fleet['weekly_target']) ? $pt_fleet['weekly_target'] : null); ?>
            <tr class="fleet w36-pt-row w36-pt-fleet">
              <th class="w36-left w36-pt-type" scope="row">
                <span>Fleet</span>
                <small><?= (int)$machine_total ?> machines · computed by the report</small>
                <?php if ($pt_fleet_below): ?><em class="w36-pt-flag" title="Fleet availability is below the fleet weekly target KPI">below target</em><?php endif; ?>
              </th>
              <td><?= (int)$fleet_available ?></td>
              <td><?= (int)$down_machines ?></td>
              <td><?= $esc($num($week['open_hours'], 0)) ?></td>
              <td><?= $esc($num(isset($pt_fleet['preventive_hours']) ? $pt_fleet['preventive_hours'] : null, 2)) ?></td>
              <td><?= $esc($num(isset($pt_fleet['required_time_hours']) ? $pt_fleet['required_time_hours'] : null, 2)) ?></td>
              <td><?php if ((float)(isset($pt_fleet['downtime_hours']) ? $pt_fleet['downtime_hours'] : 0) > 0): ?><span class="w36-days" title="Hours of downtime recorded this week"><?= $esc($num($pt_fleet['downtime_hours'], 2)) ?></span><?php else: ?><?= $esc($num(isset($pt_fleet['downtime_hours']) ? $pt_fleet['downtime_hours'] : null, 2)) ?><?php endif; ?></td>
              <td><?= $esc($num(isset($pt_fleet['breakdowns']) ? $pt_fleet['breakdowns'] : null, 0)) ?></td>
              <td><?= $esc($num(isset($pt_fleet['mtbf_hours']) ? $pt_fleet['mtbf_hours'] : null, 2)) ?></td>
              <td><?= $esc($num(isset($pt_fleet['mttr_hours']) ? $pt_fleet['mttr_hours'] : null, 2)) ?></td>
              <td><?= $availability_bar(isset($pt_fleet['availability']) ? $pt_fleet['availability'] : null, isset($pt_fleet['weekly_target']) ? $pt_fleet['weekly_target'] : null) ?></td>
              <td><?= $esc($pct(isset($pt_fleet['weekly_target']) ? $pt_fleet['weekly_target'] : null)) ?></td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="w36-pt-foot"><span>Availability below target is marked in red. Fleet availability is calculated for the whole fleet, not averaged across types.</span></div>
    <?php endif; ?>
  </div>

  <?php /* ── W5.5 — sheet `W36 Synthese` on screen: the action plan, the two
           registers and their counters (decisions D16/D17) ──────────────── */ ?>
  <?php
  $sy     = isset($report['synthese']) ? $report['synthese'] : array();
  $plan   = isset($sy['action_plan']) ? $sy['action_plan'] : array();
  $reg    = isset($sy['register']) ? $sy['register'] : array();
  // the model's own KPI block is what the workbook writes; every screen counter
  // below is instead counted from the rows the screen prints (BR-27)
  $skpi   = isset($sy['kpi']) ? $sy['kpi'] : array();
  $legend = isset($sy['legend']) ? $sy['legend'] : array();
  $svc_join = function ($services, $key) {
      $out = array();
      foreach ((array)$services as $sv) {
          $v = isset($sv[$key]) ? $sv[$key] : null;
          if ($v !== null && $v !== '') $out[] = $v;
      }
      return $out;
  };
  // header counters for the action plan: counted from the very rows the screen
  // prints (the BR-27 habit) — never typed in, and never altered by the screen filters
  $syn_total = 0; $syn_actions = 0; $syn_hold = 0; $syn_below = 0; $syn_days = 0;
  foreach ($plan as $syn_row) {
      if (!is_array($syn_row) || (isset($syn_row['type']) ? $syn_row['type'] : '') !== 'machine') continue;
      $syn_total++;
      if (isset($syn_row['action_plan']) && trim((string)$syn_row['action_plan']) !== '') $syn_actions++;
      if (isset($syn_row['hold_code']) && $syn_row['hold_code'] !== null) $syn_hold++;
      if ($misses(isset($syn_row['availability']) ? $syn_row['availability'] : null,
                  isset($syn_row['target']) ? $syn_row['target'] : null)) $syn_below++;
      $syn_days += isset($syn_row['days_down']) ? (int)$syn_row['days_down'] : 0;
  }
  $low = function ($s) { $s = (string)$s; return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); };

  // header counters for the two registers — same BR-27 habit as the plan above:
  // counted from the very rows the screen prints, never typed in, never touched by
  // a screen filter. $reg_groups holds the same tallies per category for its
  // summary row, so a category's figures are the sum of the machines beneath it.
  $reg_total = 0; $reg_insp_done = 0; $reg_insp_undone = 0; $reg_insp_breakdown = 0;
  $reg_insp_on_file = 0; $reg_pm_total = 0; $reg_pm_done = 0; $reg_pm_undone = 0;
  $reg_groups = array(); $reg_code = null;
  foreach ($reg as $reg_row) {
      if (!is_array($reg_row)) continue;
      if ((isset($reg_row['type']) ? $reg_row['type'] : '') === 'group') {
          $reg_code = (string)(isset($reg_row['code']) ? $reg_row['code'] : '');
          $reg_groups[$reg_code] = array('machines' => 0, 'done' => 0, 'undone' => 0,
              'breakdown' => 0, 'pm_total' => 0, 'pm_done' => 0, 'pm_undone' => 0, 'hours_down' => 0.0);
          continue;
      }
      $reg_status = strtoupper(trim((string)(isset($reg_row['inspection']['status']) ? $reg_row['inspection']['status'] : '')));
      $reg_is_done = $reg_status === 'DONE';
      $reg_is_bd   = $reg_status === 'BREAKDOWN';
      $reg_undone  = $reg_is_done || $reg_is_bd ? 0 : 1;      // anything unrecognised counts as outstanding
      if (isset($reg_row['inspection']['inspected_on']) && $reg_row['inspection']['inspected_on'] !== null) $reg_insp_on_file++;

      $row_pm_total = 0; $row_pm_done = 0;
      foreach ((array)(isset($reg_row['pm']['services']) ? $reg_row['pm']['services'] : array()) as $reg_svc) {
          $row_pm_total++;
          if (strtoupper(trim((string)(isset($reg_svc['status']) ? $reg_svc['status'] : ''))) === 'DONE') $row_pm_done++;
      }
      $row_pm_undone = $row_pm_total - $row_pm_done;

      $reg_total++; $reg_pm_total += $row_pm_total; $reg_pm_done += $row_pm_done; $reg_pm_undone += $row_pm_undone;
      if ($reg_is_done) $reg_insp_done++;
      elseif ($reg_is_bd) $reg_insp_breakdown++;
      else $reg_insp_undone += $reg_undone;

      if ($reg_code !== null && isset($reg_groups[$reg_code])) {
          $reg_groups[$reg_code]['machines']++;
          if ($reg_is_done) $reg_groups[$reg_code]['done']++;
          elseif ($reg_is_bd) $reg_groups[$reg_code]['breakdown']++;
          else $reg_groups[$reg_code]['undone'] += $reg_undone;
          $reg_groups[$reg_code]['pm_total']  += $row_pm_total;
          $reg_groups[$reg_code]['pm_done']   += $row_pm_done;
          $reg_groups[$reg_code]['pm_undone'] += $row_pm_undone;
          $reg_groups[$reg_code]['hours_down'] += (float)(isset($reg_row['hours_down']) ? $reg_row['hours_down'] : 0);
      }
  }
  // not inspected = nothing on file, however the row is labelled (a down machine
  // cannot be inspected, so BREAKDOWN is outstanding too)
  $reg_insp_out = $reg_insp_undone + $reg_insp_breakdown;
  $reg_pm_ratio = $reg_pm_total > 0 ? $reg_pm_done / $reg_pm_total : null;
  // the sheet's own M198: the machines inspected plus the PM services planned
  $reg_planified = $reg_total + $reg_pm_total;
  ?>
  <?php /* the action plan panel follows the Workshop Weekly table design: summary
           counters, a screen-only filter bar, collapsible category summaries whose
           availability/target stay aligned with the machine columns, wrapping text
           cells and text-with-colour cues (never colour alone) */ ?>
  <div class="w36-panel">
    <h3>Workshop action plan</h3>
    <p class="w36-legend" style="margin-top:4px">Expand a category to see its machines, work-order actions and any holds.</p>
    <?php if (empty($plan)): ?>
      <div class="w36-note">No reportable machine in this week — nothing to plan.</div>
    <?php else: ?>
      <div class="w36-syn-kpis" aria-label="Action plan summary for the week">
        <div class="w36-syn-kpi">
          <span class="l">Machines on the plan</span>
          <span class="k"><?= number_format((float)$syn_total, 0, '.', ' ') ?></span>
          <span class="s"><?= (int)$syn_actions ?> with a work-order action described</span>
        </div>
        <div class="w36-syn-kpi">
          <span class="l">Below daily target</span>
          <span class="k <?= $syn_below > 0 ? 'bad' : 'good' ?>"><?= (int)$syn_below ?></span>
          <span class="s">availability under the machine's target KPI</span>
        </div>
        <div class="w36-syn-kpi">
          <span class="l">On hold</span>
          <span class="k <?= $syn_hold > 0 ? 'warn' : '' ?>"><?= (int)$syn_hold ?></span>
          <span class="s">codes explained under the table</span>
        </div>
        <div class="w36-syn-kpi">
          <span class="l">Machine days down</span>
          <span class="k <?= $syn_days > 0 ? 'bad' : 'good' ?>"><?= (int)$syn_days ?></span>
          <span class="s">summed across the fleet, this week</span>
        </div>
      </div>

      <?php if (!$print_mode): ?>
      <div class="w36-table-tools" id="w36-syn-tools" hidden>
        <label class="w36-search-label" for="w36-syn-search">Search the plan</label>
        <input id="w36-syn-search" type="search" placeholder="ID, model, location, comment or action" autocomplete="off">
        <label class="w36-filter-location"><span class="w36-sr-only">Filter by location</span>
          <select id="w36-syn-location"><option value="">All locations</option><?php foreach ($locations as $location): ?><option value="<?= $esc($low($location)) ?>"><?= $esc($location) ?></option><?php endforeach; ?></select>
        </label>
        <label class="w36-filter-below"><input id="w36-syn-below" type="checkbox"> <span>Below target only</span></label>
        <label class="w36-filter-below"><input id="w36-syn-hold" type="checkbox"> <span>On hold only</span></label>
        <span id="w36-syn-count" role="status" aria-live="polite"></span>
        <span class="w36-table-actions"><button type="button" class="w36-btn" data-w36-action="syn-expand">Expand all</button><button type="button" class="w36-btn" data-w36-action="syn-collapse">Collapse all</button></span>
      </div>
      <p class="w36-scroll-hint">Search or narrow the plan, then expand a category for its machines. Screen filters never change the counters above, the printed report or Excel download.</p>
      <?php endif; ?>

      <div class="w36-tblwrap w36-syn-wrap" tabindex="0" role="region" aria-label="Workshop action plan — scroll horizontally for every column">
        <table class="w36 w36-syn-table" id="w36-syn-table">
          <caption class="w36-small">One row per machine: meter reading, availability against the daily target KPI, the failure comment, the planned action, days down and the hold status</caption>
          <thead>
            <tr>
              <th class="w36-left">Equipment</th>
              <th>Meter</th>
              <th>Availability</th>
              <th>Target</th>
              <th class="w36-left">Comment</th>
              <th class="w36-left">Action plan</th>
              <th>Days down</th>
              <th class="w36-left">Hold</th>
            </tr>
          </thead>
          <tbody>
          <?php $syn_gid = 'w36-syn-group-none'; foreach ($plan as $r): ?>
            <?php if ($r['type'] === 'group'): ?>
              <?php $syn_gid = 'w36-syn-group-'.substr(sha1((string)$r['code']), 0, 10); ?>
              <tr class="grp w36-syn-group" data-group-row="<?= $esc($syn_gid) ?>" data-open="<?= $print_mode ? '1' : '0' ?>">
                <td class="w36-left"><button type="button" class="w36-group-toggle" data-w36-action="syn-toggle-group" data-group="<?= $esc($syn_gid) ?>" aria-expanded="<?= $print_mode ? 'true' : 'false' ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg><span><?= $esc($r['label']) ?></span><small><?= (int)$r['machines'] ?> machine<?= (int)$r['machines'] === 1 ? '' : 's' ?><?php if ($r['available'] !== null && $r['down'] !== null): ?> · <?= (int)$r['available'] ?> available · <?= (int)$r['down'] ?> down<?php endif; ?></small>
                </button></td>
                <td aria-hidden="true">—</td>
                <td><?= $availability_bar($r['availability'], $r['target']) ?></td>
                <td><?= $esc($pct($r['target'])) ?></td>
                <td class="w36-left w36-wrap" aria-hidden="true">—</td>
                <td class="w36-left w36-wrap" aria-hidden="true">—</td>
                <td aria-hidden="true">—</td>
                <td class="w36-left" aria-hidden="true">—</td>
              </tr>
            <?php else: ?>
              <?php
              $r_av   = isset($r['availability']) ? $r['availability'] : null;
              $r_tg   = isset($r['target']) ? $r['target'] : null;
              $r_loc  = trim((string)(isset($r['location']) ? $r['location'] : ''));
              // everything the finder can match, one line, case handled by the script
              $r_text = trim((string)preg_replace('/\s+/', ' ', implode(' ', array(
                  (string)$r['machine_id'], (string)$r['model'], $r_loc, (string)$r['group_label'],
                  (string)$r['status'], (string)$r['hold_code'], (string)$r['work_order'],
                  (string)$r['comment'], (string)$r['action_plan']
              ))));
              $r_meter = $r['current_hours'] !== null ? $num($r['current_hours'], 1).' h' : ($r['current_km'] !== null ? $num($r['current_km'], 1).' km' : '—');
              $r_days  = isset($r['days_down']) ? (int)$r['days_down'] : 0;
              ?>
              <tr class="machine w36-syn-row" data-machine-search="<?= $esc($r_text) ?>" data-location="<?= $esc($low($r_loc)) ?>" data-below="<?= $misses($r_av, $r_tg) ? '1' : '0' ?>" data-hold="<?= $r['hold_code'] !== null ? '1' : '0' ?>" data-parent="<?= $esc($syn_gid) ?>" <?= $print_mode ? '' : 'hidden' ?>>
                <td class="w36-left"><div class="w36-syn-machine">
                  <b><?= $esc($r['machine_id']) ?></b>
                  <small><?= $esc($r['model'] === '' ? 'Model not set' : $r['model']) ?> · <?= $esc($r_loc === '' ? 'Location not set' : $r_loc) ?><?php if (!$r['location_approved']): ?> <span class="miss" title="This site is not approved for the report">?</span><?php endif; ?></small>
                  <em class="w36-status"><?= $esc($r['status'] === null || $r['status'] === '' ? 'Status unknown' : $r['status']) ?></em>
                </div></td>
                <td><?= $esc($r_meter) ?></td>
                <td><?= $availability_bar($r_av, $r_tg) ?></td>
                <td><?= $esc($pct($r_tg)) ?></td>
                <td class="w36-left w36-wrap"><?= $r['comment'] === null ? '—' : nl2br((string)($esc($r['comment']))) ?></td>
                <td class="w36-left w36-wrap"><?= $r['action_plan'] === null ? '—' : nl2br((string)($esc($r['action_plan']))) ?><?php if ($r['work_order'] !== null): ?><span class="w36-syn-wo">Work order <?= $esc($r['work_order']) ?></span><?php endif; ?></td>
                <td><?php if ($r_days > 0): ?><span class="w36-days" title="<?= (int)$r_days ?> calendar day(s) with downtime this week"><?= (int)$r_days ?>&nbsp;d</span><?php else: ?>—<?php endif; ?></td>
                <td class="w36-left"><?php if ($r['hold_code'] !== null): ?><span class="w36-hold" title="<?= $esc($r['hold_label']) ?>"><?= $esc($r['hold_code']) ?></span><?php else: ?>—<?php endif; ?></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div id="w36-syn-no-matches" class="w36-no-matches" hidden>No machines match these filters.</div>
      <div class="w36-syn-foot">
        <?php if (!empty($legend)): ?>
          <span>Hold codes: <?php $sep = ''; foreach ($legend as $code => $label): ?><?= $sep ?><span class="w36-hold"><?= $esc($code) ?></span> <?= $esc($label) ?><?php $sep = ' · '; endforeach; ?></span>
        <?php endif; ?>
        <span>Availability bars compare each machine with its daily target KPI — red is below it. A red “<span class="w36-days" style="min-width:0;padding:0 4px">n d</span>” badge counts calendar days down; a dash means none.</span>
      </div>
    <?php endif; ?>
  </div>

  <?php /* the two registers follow the same Workshop Weekly design as the plan
           above: summary counters, a screen filter bar, collapsible category rows,
           each PM service paired with its own status and reason instead of two
           comma lists that lost the pairing, and cues that never rely on colour */ ?>
  <div class="w36-panel">
    <h3>Weekly inspections and planned maintenance</h3>
    <p class="w36-legend" style="margin-top:4px">Expand a category to see inspection results and planned maintenance for each machine.</p>
    <?php if (empty($reg)): ?>
      <div class="w36-note">No reportable machine in this week — nothing to report.</div>
    <?php else: ?>
      <div class="w36-reg-kpis" aria-label="Inspection and planned PM summary for the week">
        <div class="w36-reg-kpi">
          <span class="l">Machines inspected</span>
          <span class="k<?= $reg_total > 0 && $reg_insp_done === $reg_total ? ' good' : '' ?>"><?= (int)$reg_insp_done ?></span>
          <span class="s">of <?= (int)$reg_total ?> on the register<?php if ($reg_insp_on_file > 0): ?> · <?= (int)$reg_insp_on_file ?> with an inspection dated this week<?php endif; ?></span>
        </div>
        <div class="w36-reg-kpi">
          <span class="l">Not inspected</span>
          <span class="k <?= $reg_insp_out > 0 ? 'bad' : 'good' ?>"><?= (int)$reg_insp_out ?></span>
          <span class="s"><?= (int)$reg_insp_undone ?> UNDONE · <?= (int)$reg_insp_breakdown ?> BREAKDOWN (machine down)</span>
        </div>
        <div class="w36-reg-kpi">
          <span class="l">PM services due</span>
          <span class="k"><?= (int)$reg_pm_total ?></span>
          <span class="s"><?= (int)$reg_pm_done ?> done · <?= (int)$reg_pm_undone ?> not done</span>
        </div>
        <div class="w36-reg-kpi">
          <span class="l">PM done ratio</span>
          <span class="k <?= $reg_pm_ratio === null ? '' : ($reg_pm_undone > 0 ? 'warn' : 'good') ?>"><?= $reg_pm_ratio === null ? '—' : $esc($pct($reg_pm_ratio)) ?></span>
          <span class="s">of the services the week asked for · <?= (int)$reg_planified ?> planned actions</span>
        </div>
      </div>

      <?php if (!$print_mode): ?>
      <div class="w36-table-tools" id="w36-reg-tools" hidden>
        <label class="w36-search-label" for="w36-reg-search">Search the registers</label>
        <input id="w36-reg-search" type="search" placeholder="ID, category, inspection, comment or service" autocomplete="off">
        <label class="w36-filter-below"><input id="w36-reg-insp" type="checkbox"> <span>Not inspected only</span></label>
        <label class="w36-filter-below"><input id="w36-reg-pm" type="checkbox"> <span>PM not done only</span></label>
        <span id="w36-reg-count" role="status" aria-live="polite"></span>
        <span class="w36-table-actions"><button type="button" class="w36-btn" data-w36-action="reg-expand">Expand all</button><button type="button" class="w36-btn" data-w36-action="reg-collapse">Collapse all</button></span>
      </div>
      <p class="w36-scroll-hint">Search or narrow the registers, then expand a category for its machines. Screen filters never change the counters above, the printed report or Excel download.</p>
      <?php endif; ?>

      <div class="w36-tblwrap w36-reg-wrap" tabindex="0" role="region" aria-label="Weekly inspection report and planned PM services — scroll horizontally for every column">
        <table class="w36 w36-reg-table" id="w36-reg-table">
          <caption class="w36-small">One row per machine: the inspection status and date, the inspector's comments, and every planned PM service with its interval, its own status and the computed reason</caption>
          <thead>
            <tr>
              <th class="w36-left">Equipment</th>
              <th class="w36-left">Inspection</th>
              <th class="w36-left">Inspection comments</th>
              <th class="w36-left">Planned PM services</th>
              <th>Down h</th>
            </tr>
          </thead>
          <tbody>
          <?php $reg_gid = 'w36-reg-group-none'; foreach ($reg as $r): ?>
            <?php if ($r['type'] === 'group'): ?>
              <?php
              $reg_gid = 'w36-reg-group-'.substr(sha1((string)$r['code']), 0, 10);
              $rg      = isset($reg_groups[$r['code']]) ? $reg_groups[$r['code']] : array();
              $rg_out  = (int)(isset($rg['undone']) ? $rg['undone'] : 0) + (int)(isset($rg['breakdown']) ? $rg['breakdown'] : 0);
              ?>
              <tr class="grp w36-reg-group" data-group-row="<?= $esc($reg_gid) ?>" data-open="<?= $print_mode ? '1' : '0' ?>">
                <td class="w36-left"><button type="button" class="w36-group-toggle" data-w36-action="reg-toggle-group" data-group="<?= $esc($reg_gid) ?>" aria-expanded="<?= $print_mode ? 'true' : 'false' ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg><span><?= $esc($r['label']) ?></span><small><?= (int)$r['machines'] ?> machine<?= (int)$r['machines'] === 1 ? '' : 's' ?><?php if (!empty($rg)): ?> · <?= (int)$rg['done'] ?> inspected · <?= $rg_out ?> not inspected<?php endif; ?></small>
                </button></td>
                <td class="w36-left"><?php if (empty($rg)): ?>—<?php else: ?><span class="w36-reg-counts"><?= (int)$rg['done'] ?> DONE · <?= (int)$rg['undone'] ?> UNDONE · <?= (int)$rg['breakdown'] ?> BREAKDOWN</span><?php endif; ?></td>
                <td class="w36-left w36-wrap" aria-hidden="true">—</td>
                <td class="w36-left w36-wrap"><?php if (empty($rg)): ?>—<?php else: ?><span class="w36-reg-counts"><?= (int)$rg['pm_total'] ?> service<?= (int)$rg['pm_total'] === 1 ? '' : 's' ?> · <?= (int)$rg['pm_done'] ?> done<?php if ((int)$rg['pm_undone'] > 0): ?> · <?= (int)$rg['pm_undone'] ?> not done<?php endif; ?></span><?php endif; ?></td>
                <td><?php if (!empty($rg) && (float)$rg['hours_down'] > 0): ?><span class="w36-days"><?= $esc($num($rg['hours_down'], 1)) ?>&nbsp;h</span><?php else: ?>—<?php endif; ?></td>
              </tr>
            <?php else: ?>
              <?php
              $insp_status = (string)$r['inspection']['status'];
              $insp_cls    = strtolower($insp_status);
              $insp_on     = isset($r['inspection']['inspected_on']) ? $r['inspection']['inspected_on'] : null;
              $comments    = isset($r['inspection']['comments']) ? (array)$r['inspection']['comments'] : array();
              $services    = isset($r['pm']['services']) ? (array)$r['pm']['services'] : array();
              $types       = $svc_join($services, 'type');
              $statuses    = $svc_join($services, 'status');
              $reasons     = $svc_join($services, 'comment');
              $row_pm_undone = 0;
              foreach ($services as $svc_n) {
                  if (strtoupper(trim((string)(isset($svc_n['status']) ? $svc_n['status'] : ''))) !== 'DONE') $row_pm_undone++;
              }
              $reg_loc  = trim((string)($r['group_label'] !== null ? $r['group_label'] : ''));
              $reg_dn   = (float)(isset($r['hours_down']) ? $r['hours_down'] : 0);
              // everything the finder can match, one line, case handled by the script
              $reg_text = trim((string)preg_replace('/\s+/', ' ', implode(' ', array_merge(
                  array((string)$r['machine_id'], $reg_loc, $insp_status, (string)$insp_on),
                  $comments, $types, $statuses, $reasons
              ))));
              ?>
              <tr class="machine w36-reg-row" data-machine-search="<?= $esc($reg_text) ?>" data-insp="<?= $esc($insp_cls) ?>" data-pm="<?= $row_pm_undone > 0 ? '1' : '0' ?>" data-parent="<?= $esc($reg_gid) ?>" <?= $print_mode ? '' : 'hidden' ?>>
                <td class="w36-left"><div class="w36-syn-machine">
                  <b><?= $esc($r['machine_id']) ?></b>
                  <small><?= $esc($reg_loc === '' ? 'Category not set' : $reg_loc) ?></small>
                </div></td>
                <td class="w36-left"><div class="w36-reg-insp">
                  <span class="w36-insp <?= $esc($insp_cls) ?>"><?= $esc($insp_status === '' ? 'UNKNOWN' : $insp_status) ?></span>
                  <small><?= $insp_on !== null && $insp_on !== '' ? 'Inspected '.$esc($insp_on) : 'No inspection on file this week' ?></small>
                </div></td>
                <td class="w36-left w36-wrap"><?= empty($comments) ? '—' : nl2br((string)($esc(implode("\n", $comments)))) ?></td>
                <td class="w36-left w36-wrap">
                  <?php if (empty($services)): ?>
                    <span class="w36-reg-none">No PM service due this week</span>
                  <?php else: ?>
                    <ul class="w36-reg-pms">
                      <?php foreach ($services as $svc_r):
                        $svc_status = (string)(isset($svc_r['status']) ? $svc_r['status'] : '');
                        $svc_done   = strtoupper(trim($svc_status)) === 'DONE';
                        $svc_type   = (string)(isset($svc_r['type']) ? $svc_r['type'] : '');
                        $svc_why    = isset($svc_r['comment']) ? $svc_r['comment'] : null;
                        $svc_due    = (string)(isset($svc_r['due_by']) ? $svc_r['due_by'] : '');
                        $svc_meter  = isset($svc_r['meter_value']) ? $svc_r['meter_value'] : null;
                        $svc_seen   = isset($svc_r['meter_reading_on']) ? $svc_r['meter_reading_on'] : null;
                        $svc_ev     = '';
                        if ($svc_due !== '' && $svc_due !== 'date' && $svc_meter !== null) {
                            $svc_ev = 'judged at ' . $num($svc_meter, 1) . ($svc_due === 'km' ? ' km' : ' h')
                                    . ($svc_seen !== null && $svc_seen !== '' ? ' — reading dated ' . $svc_seen : ' — no reading on file');
                        }
                      ?>
                        <li class="w36-reg-pm">
                          <span class="w36-reg-svc"><?= $esc($svc_type === '' ? 'Interval not set' : $svc_type) ?></span>
                          <span class="w36-insp <?= $svc_done ? 'done' : 'undone' ?>"><?= $esc($svc_status === '' ? 'UNKNOWN' : $svc_status) ?></span>
                          <?php if ($svc_why !== null && $svc_why !== ''): ?><em class="w36-reg-why"><?= $esc($svc_why) ?></em><?php endif; ?>
                          <?php if ($svc_ev !== ''): ?><em class="w36-reg-ev"><?= $esc($svc_ev) ?></em><?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </td>
                <td><?php if ($reg_dn > 0): ?><span class="w36-days" title="<?= $esc($num($reg_dn, 2)) ?> hours of downtime recorded this week"><?= $esc($num($reg_dn, 1)) ?>&nbsp;h</span><?php else: ?>—<?php endif; ?></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div id="w36-reg-no-matches" class="w36-no-matches" hidden>No machines match these filters.</div>
      <div class="w36-reg-foot">
        <span>Inspection status: <span class="w36-insp breakdown">BREAKDOWN</span> wins over <span class="w36-insp done">DONE</span> when the machine is down now or was still down at the week's end, and <span class="w36-insp done">DONE</span> needs a submitted inspection inside the week — otherwise the row is <span class="w36-insp undone">UNDONE</span>. A dash means nothing was recorded.</span>
        <span>Each planned service shows its status and, where available, why it was not completed. “Down h” is the downtime recorded during the week.</span>
      </div>
    <?php endif; ?>

    <?php /* the counters the panel used to print as a nine-tile strip live in the
             four summary cards above now — each figure once, each one counted from
             the rows beneath instead of a second, separately maintained tally */ ?>
  </div>

  <?php /* ── W4.3 — the charts (decision D6: here and in the PDF, never in the Excel file) ── */ ?>
  <div class="w36-panel">
    <h3>Weekly charts</h3>
    <?php
      // one renderer for every chart: the bar colours follow the metric's meaning
      $bar_colour = function (array $chart, $which) {
          if ($chart['kind'] === 'stack') return $which === 0 ? '#006100' : '#C00000';
          if ($chart['kind'] === 'pair')  return $which === 0 ? '#166534' : '#b45309';
          return '#1e40af';
      };
    ?>
    <div class="w36-charts">
      <?php foreach ($charts as $chart): ?>
        <?php
          $kind  = isset($chart['kind']) ? $chart['kind'] : 'bars';
          $unit  = isset($chart['unit']) ? $chart['unit'] : '';
          $bars  = isset($chart['bars']) ? $chart['bars'] : array();
          $whole = in_array($unit, array('machines', 'services', 'inspections', 'breakdowns'), true);
          $max   = ($unit === '%') ? 1.0 : 0.0;
          foreach ($bars as $b) {
              $max = max($max, (float)$b['value'], isset($b['second']) ? (float)$b['second'] : 0.0);
          }
        ?>
        <div class="w36-chart">
          <div class="w36-chart-title">
            <?= $esc(isset($chart['key'], $chart_titles[$chart['key']]) ? $chart_titles[$chart['key']] : $chart['title']) ?>
            <?php if ($unit !== '' && $unit !== '%'): ?><span class="w36-small">(<?= $esc($unit) ?>)</span><?php endif; ?>
          </div>

          <?php if ($kind === 'meter'): ?>
            <?php
              $v  = isset($chart['value']) ? $chart['value'] : null;
              $t  = isset($chart['target']) ? $chart['target'] : null;
              $ok = ($v !== null && $t !== null) ? ((float)$v >= (float)$t) : null;
            ?>
            <div class="w36-meter">
              <span class="w36-meter-fill" style="width:<?= $v === null ? 0 : round(max(0, min(1, (float)$v)) * 100, 1) ?>%;
                    background:<?= $ok === null ? '#9ca3af' : ($ok ? '#006100' : '#C00000') ?>"></span>
              <?php if ($t !== null): ?>
                <span class="w36-meter-tick" style="left:<?= round(max(0, min(1, (float)$t)) * 100, 1) ?>%"></span>
              <?php endif; ?>
            </div>
            <div class="w36-chart-note">
              <strong><?= $esc($pct($v)) ?></strong>
              <?php if ($t !== null): ?> against the fleet target <?= $esc($pct($t)) ?><?php endif; ?>
              <?php if (!empty($chart['note'])): ?> · <?= $esc($chart['note']) ?><?php endif; ?>
            </div>
          <?php else: ?>
            <?php foreach ($bars as $b): ?>
              <?php
                $v  = isset($b['value']) ? $b['value'] : null;
                $s  = isset($b['second']) ? $b['second'] : null;
                $t  = isset($b['target']) ? $b['target'] : null;
                $ok = ($t !== null && $v !== null) ? ((float)$v >= (float)$t) : null;
                $w  = ($max > 0 && $v !== null) ? max(0, min(100, (float)$v / $max * 100)) : 0;
                $sw = ($max > 0 && $s !== null) ? max(0, min(100, (float)$s / $max * 100)) : 0;
              ?>
              <div class="w36-bar-row">
                <span class="w36-bar-label"><?= $esc($b['label']) ?></span>
                <span class="w36-bar-track">
                  <span class="w36-bar-fill" style="width:<?= round($w, 1) ?>%;
                        background:<?= ($unit === '%' && $ok !== null) ? ($ok ? '#006100' : '#C00000') : $bar_colour($chart, 0) ?>"></span>
                  <?php if ($sw > 0): ?>
                    <span class="w36-bar-fill w36-bar-second" style="width:<?= round($sw, 1) ?>%;background:<?= $bar_colour($chart, 1) ?>"></span>
                  <?php endif; ?>
                  <?php if ($t !== null && $max > 0): ?>
                    <span class="w36-bar-tick" style="left:<?= round((float)$t / $max * 100, 1) ?>%"></span>
                  <?php endif; ?>
                </span>
                <span class="w36-bar-value">
                  <?= $esc($unit === '%' ? $pct($v) : $num($v, $whole ? 0 : 2)) ?><?php if ($s !== null): ?> + <?= $esc($num($s, $whole ? 0 : 2)) ?><?php endif; ?>
                </span>
              </div>
            <?php endforeach; ?>
            <div class="w36-chart-note">
              <?php if (isset($chart['ratio'])): ?>KPI <strong><?= $esc($pct($chart['ratio'])) ?></strong><?php if (!empty($chart['ratio_label'])): ?> <?= $esc($chart['ratio_label']) ?><?php endif; ?> · <?php endif; ?>
              <?php if (!empty($chart['note'])): ?><?= $esc($chart['note']) ?><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="w36-legend">
      On availability charts, the black tick marks the target. Green means the target was met; red means it was not.
    </p>
    <?php if (!empty($pm) || !empty($inspect)): ?>
      <p class="w36-legend">
        PM services: <?= (int)$pm['planned'] ?> planned, <?= (int)$pm['done'] ?> done, <?= (int)$pm['undone'] ?> undone
        (<?= (int)$pm['done'] ?>/<?= (int)$pm['planned'] ?> completed)
        <?php if (!empty($pm['not_judged'])): ?> · <strong><?= count($pm['not_judged']) ?> hours-based schedule(s) cannot be judged without a meter reading</strong><?php endif; ?>.
        Inspections: <?= (int)$inspect['planned'] ?> machines — <?= (int)$inspect['done'] ?> done,
        <?= (int)$inspect['undone'] ?> undone, <?= (int)$inspect['breakdown'] ?> down.
      </p>
    <?php endif; ?>
  </div>

  <?php if ($print_mode): ?>
    <div class="w36-print-foot">
      <div>
        <strong>Fleet</strong> <?= $esc($ph['av']) ?> availability vs target <?= $esc($ph['target']) ?> ·
        preventive <?= $esc($ph['pt']) ?> h · required <?= $esc($ph['rt']) ?> h · down <?= $esc($ph['dt']) ?> h ·
        <?= $esc($ph['f']) ?> breakdown(s) ·
        <?= (int)(isset($fleet['rollup']['days_down']) ? $fleet['rollup']['days_down'] : 0) ?> day(s) down ·
        <?= $num(isset($fleet['rollup']['hours_down']) ? $fleet['rollup']['hours_down'] : null, 2) ?> h down ·
        MTTR <?= $esc($ph['mttr']) ?> · MTBF <?= $esc($ph['mtbf']) ?>
      </div>
      <div class="w36-sign">
        Source: WMS weekly report <?= $esc($week_label) ?>
        <?php if ($snapshot !== null): ?>· published report<?php else: ?>· live reading<?php endif; ?>
        · printed <?= $esc($generated_at) ?>
      </div>
      <div class="w36-sign">Prepared by ______________________ · Checked by ______________________ · Approved by ______________________</div>
    </div>
  <?php endif; ?>


</div>

<?php if (!$print_mode): ?>
<script>
(function () {
  var tools = document.getElementById('w36-search-tools');
  var input = document.getElementById('w36-machine-search');
  var location = document.getElementById('w36-location-filter');
  var below = document.getElementById('w36-below-target');
  var count = document.getElementById('w36-search-count');
  var noMatches = document.getElementById('w36-no-matches');
  var table = document.getElementById('w36-machine-table');
  if (!tools || !input || !table) return;

  var rows = Array.prototype.slice.call(table.querySelectorAll('tr.machine[data-machine-search]'));
  var groups = Array.prototype.slice.call(table.querySelectorAll('tr.w36-group-row[data-group-row]'));
  tools.hidden = false;

  function activeFilter() {
    return !!(input.value.trim() || (location && location.value) || (below && below.checked));
  }
  function matches(row, term, selectedLocation, onlyBelow) {
    return (!term || row.getAttribute('data-machine-search').toLocaleLowerCase().indexOf(term) !== -1) &&
      (!selectedLocation || row.getAttribute('data-location') === selectedLocation) &&
      (!onlyBelow || row.getAttribute('data-below') === '1');
  }
  function applyFilters() {
    var term = input.value.trim().toLocaleLowerCase();
    var selectedLocation = location ? location.value : '';
    var onlyBelow = below ? below.checked : false;
    var filtered = activeFilter();
    var matching = 0;

    rows.forEach(function (row) {
      var yes = matches(row, term, selectedLocation, onlyBelow);
      row.dataset.matches = yes ? '1' : '0';
      if (yes) matching++;
    });

    groups.forEach(function (group) {
      var groupId = group.getAttribute('data-group-row');
      var children = rows.filter(function (row) { return row.getAttribute('data-parent') === groupId; });
      var hasMatch = children.some(function (row) { return row.dataset.matches === '1'; });
      var open = filtered ? hasMatch : group.dataset.open === '1';
      group.hidden = filtered && !hasMatch;
      var toggle = group.querySelector('[data-w36-action="toggle-group"]');
      if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      children.forEach(function (row) {
        var visible = open && row.dataset.matches === '1';
        row.hidden = !visible;
        var detail = row.nextElementSibling;
        var machineToggle = row.querySelector('[data-w36-action="toggle-machine"]');
        var detailOpen = row.dataset.detailOpen === '1';
        if (detail && detail.classList.contains('detail')) detail.hidden = !(visible && detailOpen);
        if (machineToggle) machineToggle.setAttribute('aria-expanded', detailOpen && visible ? 'true' : 'false');
      });
    });
    if (count) count.textContent = matching + ' of ' + rows.length + ' machine rows match';
    if (noMatches) noMatches.hidden = matching !== 0;
  }
  function setAll(open) {
    groups.forEach(function (group) { group.dataset.open = open ? '1' : '0'; });
    applyFilters();
  }

  tools.addEventListener('click', function (event) {
    var action = event.target.closest('[data-w36-action]');
    if (!action) return;
    if (action.dataset.w36Action === 'expand') setAll(true);
    else if (action.dataset.w36Action === 'collapse') setAll(false);
  });
  table.addEventListener('click', function (event) {
    var action = event.target.closest('[data-w36-action]');
    if (!action) return;
    if (action.dataset.w36Action === 'toggle-group') {
      var group = groups.find(function (row) { return row.getAttribute('data-group-row') === action.dataset.group; });
      if (!group) return;
      group.dataset.open = group.dataset.open === '1' ? '0' : '1';
      applyFilters();
    } else if (action.dataset.w36Action === 'toggle-machine') {
      var machine = action.closest('tr.machine');
      if (!machine) return;
      machine.dataset.detailOpen = machine.dataset.detailOpen === '1' ? '0' : '1';
      applyFilters();
    }
  });
  input.addEventListener('input', applyFilters);
  if (location) location.addEventListener('change', applyFilters);
  if (below) below.addEventListener('change', applyFilters);
  applyFilters();
}());

/* sheet `Synthese` action plan — its own ids, the same interaction rules as the
   availability grid: filters only touch screen rows, categories start collapsed,
   and every machine row keeps its category summary above it. */
(function () {
  var tools = document.getElementById('w36-syn-tools');
  var input = document.getElementById('w36-syn-search');
  var location = document.getElementById('w36-syn-location');
  var below = document.getElementById('w36-syn-below');
  var hold = document.getElementById('w36-syn-hold');
  var count = document.getElementById('w36-syn-count');
  var noMatches = document.getElementById('w36-syn-no-matches');
  var table = document.getElementById('w36-syn-table');
  if (!input || !table) return;

  var rows = Array.prototype.slice.call(table.querySelectorAll('tr.machine[data-machine-search]'));
  var groups = Array.prototype.slice.call(table.querySelectorAll('tr.w36-syn-group[data-group-row]'));
  if (tools) tools.hidden = false;

  function activeFilter() {
    return !!(input.value.trim() || (location && location.value) || (below && below.checked) || (hold && hold.checked));
  }
  function matches(row, term, selectedLocation, onlyBelow, onlyHold) {
    return (!term || (row.getAttribute('data-machine-search') || '').toLocaleLowerCase().indexOf(term) !== -1) &&
      (!selectedLocation || row.getAttribute('data-location') === selectedLocation) &&
      (!onlyBelow || row.getAttribute('data-below') === '1') &&
      (!onlyHold || row.getAttribute('data-hold') === '1');
  }
  function applyFilters() {
    var term = input.value.trim().toLocaleLowerCase();
    var selectedLocation = location ? location.value : '';
    var onlyBelow = below ? below.checked : false;
    var onlyHold = hold ? hold.checked : false;
    var filtered = activeFilter();
    var matching = 0;

    rows.forEach(function (row) {
      var yes = matches(row, term, selectedLocation, onlyBelow, onlyHold);
      row.dataset.matches = yes ? '1' : '0';
      if (yes) matching++;
    });

    groups.forEach(function (group) {
      var groupId = group.getAttribute('data-group-row');
      var children = rows.filter(function (row) { return row.getAttribute('data-parent') === groupId; });
      var hasMatch = children.some(function (row) { return row.dataset.matches === '1'; });
      var open = filtered ? hasMatch : group.dataset.open === '1';
      group.hidden = filtered && !hasMatch;
      var toggle = group.querySelector('[data-w36-action="syn-toggle-group"]');
      if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      children.forEach(function (row) { row.hidden = !(open && row.dataset.matches === '1'); });
    });
    if (count) count.textContent = matching + ' of ' + rows.length + ' machines shown';
    if (noMatches) noMatches.hidden = matching !== 0;
  }
  function setAll(open) {
    groups.forEach(function (group) { group.dataset.open = open ? '1' : '0'; });
    applyFilters();
  }

  if (tools) tools.addEventListener('click', function (event) {
    var action = event.target.closest('[data-w36-action]');
    if (!action) return;
    if (action.dataset.w36Action === 'syn-expand') setAll(true);
    else if (action.dataset.w36Action === 'syn-collapse') setAll(false);
  });
  table.addEventListener('click', function (event) {
    var action = event.target.closest('[data-w36-action]');
    if (!action || action.dataset.w36Action !== 'syn-toggle-group') return;
    var group = groups.find(function (row) { return row.getAttribute('data-group-row') === action.dataset.group; });
    if (!group) return;
    group.dataset.open = group.dataset.open === '1' ? '0' : '1';
    applyFilters();
  });
  input.addEventListener('input', applyFilters);
  if (location) location.addEventListener('change', applyFilters);
  if (below) below.addEventListener('change', applyFilters);
  if (hold) hold.addEventListener('change', applyFilters);
  applyFilters();
}());

/* the inspection/PM registers — same interaction rules again, its own ids: the
   filters only touch screen rows, categories start collapsed, a category that
   matches opens itself so a search never hides its own result. */
(function () {
  var tools = document.getElementById('w36-reg-tools');
  var input = document.getElementById('w36-reg-search');
  var insp = document.getElementById('w36-reg-insp');
  var pm = document.getElementById('w36-reg-pm');
  var count = document.getElementById('w36-reg-count');
  var noMatches = document.getElementById('w36-reg-no-matches');
  var table = document.getElementById('w36-reg-table');
  if (!input || !table) return;

  var rows = Array.prototype.slice.call(table.querySelectorAll('tr.machine[data-machine-search]'));
  var groups = Array.prototype.slice.call(table.querySelectorAll('tr.w36-reg-group[data-group-row]'));
  if (tools) tools.hidden = false;

  function activeFilter() {
    return !!(input.value.trim() || (insp && insp.checked) || (pm && pm.checked));
  }
  function matches(row, term, onlyInsp, onlyPm) {
    return (!term || (row.getAttribute('data-machine-search') || '').toLocaleLowerCase().indexOf(term) !== -1) &&
      (!onlyInsp || (row.getAttribute('data-insp') || '') !== 'done') &&
      (!onlyPm || row.getAttribute('data-pm') === '1');
  }
  function applyFilters() {
    var term = input.value.trim().toLocaleLowerCase();
    var onlyInsp = insp ? insp.checked : false;
    var onlyPm = pm ? pm.checked : false;
    var filtered = activeFilter();
    var matching = 0;

    rows.forEach(function (row) {
      var yes = matches(row, term, onlyInsp, onlyPm);
      row.dataset.matches = yes ? '1' : '0';
      if (yes) matching++;
    });

    groups.forEach(function (group) {
      var groupId = group.getAttribute('data-group-row');
      var children = rows.filter(function (row) { return row.getAttribute('data-parent') === groupId; });
      var hasMatch = children.some(function (row) { return row.dataset.matches === '1'; });
      var open = filtered ? hasMatch : group.dataset.open === '1';
      group.hidden = filtered && !hasMatch;
      var toggle = group.querySelector('[data-w36-action="reg-toggle-group"]');
      if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      children.forEach(function (row) { row.hidden = !(open && row.dataset.matches === '1'); });
    });
    if (count) count.textContent = matching + ' of ' + rows.length + ' machines shown';
    if (noMatches) noMatches.hidden = matching !== 0;
  }
  function setAll(open) {
    groups.forEach(function (group) { group.dataset.open = open ? '1' : '0'; });
    applyFilters();
  }

  if (tools) tools.addEventListener('click', function (event) {
    var action = event.target.closest('[data-w36-action]');
    if (!action) return;
    if (action.dataset.w36Action === 'reg-expand') setAll(true);
    else if (action.dataset.w36Action === 'reg-collapse') setAll(false);
  });
  table.addEventListener('click', function (event) {
    var action = event.target.closest('[data-w36-action]');
    if (!action || action.dataset.w36Action !== 'reg-toggle-group') return;
    var group = groups.find(function (row) { return row.getAttribute('data-group-row') === action.dataset.group; });
    if (!group) return;
    group.dataset.open = group.dataset.open === '1' ? '0' : '1';
    applyFilters();
  });
  input.addEventListener('input', applyFilters);
  if (insp) insp.addEventListener('change', applyFilters);
  if (pm) pm.addEventListener('change', applyFilters);
  applyFilters();
}());
</script>
<?php endif; ?>
