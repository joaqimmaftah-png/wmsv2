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

$day_name = function ($d) { return date('D d/m', strtotime($d)); };

// the data-readiness panel buckets the model's notes
$by_level = array('error' => array(), 'warn' => array(), 'info' => array());
foreach ($quality as $q) {
    $lvl = isset($q['level']) ? $q['level'] : 'info';
    if (!isset($by_level[$lvl])) $by_level[$lvl] = array();
    $by_level[$lvl][] = $q;
}
$issue_count = count($by_level['error']) + count($by_level['warn']);
$with_data   = 0;
$down_machines = 0;
foreach ($order as $id) {
    if (empty($machines[$id])) continue;
    if (!empty($machines[$id]['weekly']['has_data'])) $with_data++;
    if ((float)$machines[$id]['weekly']['hours_down'] > 0) $down_machines++;
}
$machine_total = count($machines);

// the weekly block, in the sheet's own order (BG:BP).  OPEN-5/D28: the D4 days-down
// count is its first column, as on the sheet.
$weekly_cols = array(
    'days'      => 'NUMBER OF DAYS DOWN',
    'open'      => 'WEEKLY OPEN TIME',
    'pt'        => 'PREVENTIVE TIME',
    'rt'        => 'REQUIRED TIME',
    'dt'        => 'TOTAL DOWN TIME',
    'f'         => 'NUMBER OF FAILLURE',
    'mtbf'      => 'MTBF',
    'mttr'      => 'MTTR',
    'av'        => 'AVAIBILITY OPERATIONAL',
    'target'    => 'DAILY TARGET KPI',
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
  table.w36    { border-collapse:separate; border-spacing:0; width:100%; white-space:nowrap; }
  table.w36 th, table.w36 td { border-bottom:1px solid #eceff3; border-right:1px solid #eceff3; padding:4px 7px; }
  table.w36 thead th { position:sticky; top:0; background:#f3f4f6; font-size:11px; text-transform:uppercase;
                       letter-spacing:.02em; color:#374151; z-index:2; }
  table.w36 thead th.wk { background:#e8eefc; }
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
  .w36-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin:4px 0; }
  .w36-kpi  { border:1px solid #eceff3; border-radius:8px; padding:8px 10px; }
  .w36-kpi .n { display:block; font-size:20px; font-weight:700; }
  .w36-kpi .l { display:block; color:#6b7280; font-size:11px; text-transform:uppercase; letter-spacing:.02em; }
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
</style>

<div class="w36-wrap<?= $print_mode ? ' w36-print' : '' ?>">

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
    tr, .w36-chart, .w36-kpi, .w36-panel { page-break-inside:avoid; }
  </style>
  <div class="w36-print-head">
    <img src="<?= $esc(isset($logo_url) ? $logo_url : base_url('uploads/logoam.png')) ?>" alt="A&amp;M">
    <div>
      <h1 class="w36-print-title">WORKSHOP WEEKLY REPORT &mdash; <?= $esc(strtoupper($report_name)) ?> &mdash; <?= $esc($week_label) ?></h1>
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
        <strong>PUBLISHED</strong> <?= $esc($snapshot['published_at']) ?><br>
        checksum <?= $esc(substr((string)$snapshot['checksum'], 0, 12)) ?>&hellip;
      <?php elseif ($published !== null): ?>
        <strong>LIVE READING</strong> — published <?= $esc($published['published_at']) ?><br>
        figures recomputed to <?= $esc(date('d/m/Y', strtotime(isset($week['cutoff']) ? $week['cutoff'] : 'today'))) ?>
      <?php else: ?>
        <strong>DRAFT</strong> — not published yet
      <?php endif; ?>
      <br>printed <?= $esc($generated_at) ?>
    </div>
  </div>
  <div class="w36-print-hint">
    Print or save as PDF (A4 landscape). Sheet <code><?= $esc($report_name) ?></code> prints first, then
    <code><?= $esc($report_name . ' Synthese') ?></code> — the action plan, the two registers and the counters.
    The charts are here and on the screen only; the Excel download carries the workbook without them (D6).
  </div>
  <?php endif; ?>

  <?php if (!$print_mode): ?>
  <div class="w36-head">
    <div>
      <h2 class="w36-title">Weekly Report <?= $esc($report_name) ?> — preview</h2>
      <p class="w36-sub">
        <?= $esc($week_label) ?> ·
        <?= $esc(date('D d M Y', strtotime($week['start']))) ?> → <?= $esc(date('D d M Y', strtotime($week['end']))) ?> ·
        open time <?= $esc($num($week['open_hours'], 0)) ?> h (<?= $esc($num($week['daily_open_hours'], 0)) ?> h/day) ·
        <?= (int)$machine_total ?> reportable machines
        <?php if ($build_ms !== null): ?> · built in <?= (int)$build_ms ?> ms<?php endif; ?>
      </p>
    </div>
    <div>
      <a class="w36-btn w36-btn-primary"
         href="<?= $esc(base_url('reports/export_weekly_w36_xlsx?week=' . $week_label)) ?>">Download Excel</a>
      <a class="w36-btn" target="_blank" rel="noopener"
         href="<?= $esc(base_url('reports/export_weekly_w36_pdf?week=' . $week_label)) ?>">Print / PDF</a>
      <?php if ($may_publish && $published === null): ?>
        <form method="post" style="display:inline"
              action="<?= $esc(base_url('reports/publish_weekly_w36?week=' . $week_label)) ?>">
          <?= $csrf_field() ?>
          <button type="submit" class="w36-btn">Publish snapshot</button>
        </form>
      <?php elseif ($may_publish && $snapshot === null): ?>
        <?php /* live reading of a published week: only a forced re-freeze is left */ ?>
        <form method="post" style="display:inline"
              action="<?= $esc(base_url('reports/publish_weekly_w36?week=' . $week_label)) ?>">
          <?= $csrf_field() ?>
          <input type="hidden" name="force" value="1">
          <button type="submit" class="w36-btn">Re-publish from live</button>
        </form>
        <span class="w36-badge ok">published</span>
      <?php elseif ($published !== null): ?>
        <span class="w36-badge ok">published</span>
      <?php endif; ?>
      <div class="w36-small">same data as this page · <?= $esc($week_label) ?></div>
    </div>
  </div>
  <?php endif; /* !$print_mode — the toolbar is screen chrome */ ?>

  <?php if ($notice !== null): ?>
    <div class="w36-note warn"><?= $esc($notice) ?></div>
  <?php endif; ?>

  <?php if ($snapshot !== null): ?>
    <div class="w36-note ok">
      <strong>Published snapshot</strong> — frozen on <?= $esc($snapshot['published_at']) ?>
      <?php if (!empty($snapshot['checksum'])): ?>
        · checksum <code><?= $esc(substr((string)$snapshot['checksum'], 0, 12)) ?>…</code>
      <?php endif; ?>
      <?php if (empty($snapshot['complete'])): ?>
        <span class="w36-badge err">the stored payload does not match its checksum</span>
      <?php endif; ?>
      <div class="w36-small">
        This week is read <strong>as published</strong>: the numbers below — and every download of it — are the frozen ones,
        so later work orders cannot change them. Days down are frozen at the week's last day (D4).
        <a href="<?= $esc(base_url('reports/weekly_w36?week=' . $week_label . '&live=1')) ?>">See the live reading</a>
        <?php if ($may_publish): ?> ·
          <form method="post" style="display:inline"
                action="<?= $esc(base_url('reports/publish_weekly_w36?week=' . $week_label)) ?>">
            <?= $csrf_field() ?>
            <input type="hidden" name="force" value="1">
            <button type="submit" class="w36-btn" style="padding:2px 8px;font-size:12px">Re-publish</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php elseif ($is_live): ?>
    <div class="w36-note warn">
      <strong>Live reading</strong> — a machine that is still down keeps counting to <em>today</em> (D4).
      <div class="w36-small">
        <?php if ($published !== null): ?>
          This week was published on <?= $esc($published['published_at']) ?>; the figures below are today's, not the frozen ones.
          <a href="<?= $esc(base_url('reports/weekly_w36?week=' . $week_label)) ?>">Back to the published reading</a>
        <?php else: ?>
          Publish the week to freeze these figures at its last day and to make a re-download reproducible.
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$print_mode): /* the picker is screen chrome — paper has no links */ ?>
  <form class="w36-picker" method="get" action="<?= $esc(base_url('reports/weekly_w36')) ?>">
    <a class="w36-btn" href="<?= $esc(base_url('reports/weekly_w36?week=' . $prev_week)) ?>" title="previous week">◀ <?= $esc($prev_week) ?></a>
    <label>ISO week
      <input type="week" name="week" value="<?= $esc($week_label) ?>">
    </label>
    <span>or any date in the week</span>
    <label>
      <input type="date" name="week_start" value="<?= $esc($week['start']) ?>">
    </label>
    <button type="submit" class="w36-btn w36-btn-primary">Show week</button>
    <a class="w36-btn" href="<?= $esc(base_url('reports/weekly_w36?week=' . $next_week)) ?>" title="next week"><?= $esc($next_week) ?> ▶</a>
    <span class="w36-small">
      breakdown count: <?= $esc(isset($modes['breakdown_count_mode']) ? $modes['breakdown_count_mode'] : '—') ?> ·
      zero-failure MTBF: <?= $esc(isset($modes['mtbf_zero_failure_mode']) ? $modes['mtbf_zero_failure_mode'] : '—') ?>
    </span>
  </form>
  <form class="w36-picker" method="post" action="<?= $esc(base_url('reports/weekly_report_name')) ?>">
    <?= $csrf_field() ?>
    <input type="hidden" name="week" value="<?= $esc($week_label) ?>">
    <label>Report name
      <input type="text" name="report_name" value="<?= $esc($report_name) ?>" maxlength="22" required<?= $snapshot !== null ? ' readonly' : '' ?>>
    </label>
    <span class="w36-small">Used in this page, the Excel filename, both workbook sheet names and the print/PDF title<?= $snapshot !== null ? '; frozen with the published snapshot' : '' ?>.</span>
    <?php if ($may_publish && $snapshot === null): ?><button type="submit" class="w36-btn">Save name</button><?php endif; ?>
  </form>
  <?php endif; ?>

  <?php /* ── data readiness (W2.2): what the week can and cannot say ───── */ ?>
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
        No work order touches this week, so every machine reads an empty period.
        The Excel file would be structurally complete and operationally blank — check the week, or load the missing records first.
      </div>
    <?php endif; ?>

    <?php if ($issue_count === 0): ?>
      <div class="w36-note ok" style="margin-top:10px">The week is complete: every machine has a group, a site and a resolved daily target.</div>
    <?php endif; ?>

    <?php if (!empty($by_level['error'])): ?>
      <ul class="w36-qlist" style="margin-top:10px">
        <?php foreach ($by_level['error'] as $q): ?>
          <li><span class="w36-qcode"><?= $esc($q['code']) ?></span> <?= $esc($q['message']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if (!empty($by_level['warn'])): ?>
      <ul class="w36-qlist" style="margin-top:10px">
        <?php $shown = 0; foreach ($by_level['warn'] as $q): $shown++; if ($shown > 12) break; ?>
          <li><span class="w36-qcode"><?= $esc($q['code']) ?></span> <?= $esc($q['message']) ?></li>
        <?php endforeach; ?>
        <?php if (count($by_level['warn']) > 12): ?>
          <li>… and <?= count($by_level['warn']) - 12 ?> more</li>
        <?php endif; ?>
      </ul>
    <?php endif; ?>
  </div>

  <?php /* ── the grid: the sheet's machine rows ───────────────────────── */ ?>
  <div class="w36-tblwrap">
    <table class="w36">
      <thead>
        <tr>
          <th rowspan="2">TYPE</th>
          <th rowspan="2">EQUIPMENT</th>
          <th rowspan="2">AVAILABLE</th>
          <th rowspan="2">DOWN</th>
          <th rowspan="2">MACHINE ID</th>
          <th rowspan="2">MODEL</th>
          <th rowspan="2">LOCATION</th>
          <?php foreach ($days as $d): ?>
            <th colspan="1"><?= $esc($day_name($d)) ?></th>
          <?php endforeach; ?>
          <?php foreach ($weekly_cols as $key => $label): ?>
            <th class="wk"><?= $esc($label) ?></th>
          <?php endforeach; ?>
        </tr>
        <tr>
          <?php foreach ($days as $d): ?><th>AVAIBILITY<span class="w36-day-target">target</span></th><?php endforeach; ?>
          <?php foreach ($weekly_cols as $key => $label): ?><th class="wk"><?= $esc($label === 'NUMBER OF DAYS DOWN' ? 'd' : ($label === 'WEEKLY OPEN TIME' ? 'h' : ($label === 'AVAIBILITY OPERATIONAL' ? '%' : ''))) ?></th><?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php /* row 4 in the sheet: the fleet roll-up, data-driven (D-W3) */ ?>
        <?php if (!empty($fleet)): $fc = $weekly_cells($fleet, $week['open_hours']); ?>
          <tr class="fleet">
            <td colspan="3">FLEET</td>
            <td class="num"><?= (int)$machine_total ?></td>
            <td colspan="3" class="w36-small">all reportable machines</td>
            <?php foreach ($days as $i => $d): ?>
              <?php $fd = isset($fleet['daily'][$i]) ? $fleet['daily'][$i] : null; ?>
              <td class="num"><?= $esc($pct($fd && isset($fd['availability']) ? $fd['availability'] : null)) ?></td>
            <?php endforeach; ?>
            <?php foreach ($fc as $k => $v): ?>
              <td class="num wk"><?= $esc($v) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endif; ?>

        <?php foreach ($groups as $g): ?>
          <?php
            // a group with no machine in it is not printed (its rows would be empty)
            if ((int)$g['row_count'] === 0) continue;
            $pt = isset($per_type[$g['code']]) ? $per_type[$g['code']] : array();
            $gc = $weekly_cells($g, $week['open_hours']);
          ?>
          <tr class="grp">
            <td colspan="2"><?= $esc($g['label']) ?></td>
            <td class="num"><?= (int)(isset($pt['available']) ? $pt['available'] : 0) ?></td>
            <td class="num"><?= (int)(isset($pt['down']) ? $pt['down'] : 0) ?></td>
            <td colspan="2" class="w36-small"><?= (int)$g['row_count'] ?> machine(s)</td>
            <td class="w36-small">group roll-up</td>
            <?php foreach ($days as $i => $d): ?>
              <?php $gd = isset($g['daily'][$i]) ? $g['daily'][$i] : null; ?>
              <td class="num"><?= $esc($pct($gd && isset($gd['availability']) ? $gd['availability'] : null)) ?></td>
            <?php endforeach; ?>
            <?php foreach ($gc as $k => $v): ?>
              <td class="num wk"><?= $esc($v) ?></td>
            <?php endforeach; ?>
          </tr>

          <?php foreach ($g['machines'] as $id): if (empty($machines[$id])) continue; $m = $machines[$id]; $mc = $weekly_cells($m['weekly'], $week['open_hours']); $mw = $m['weekly']; ?>
            <tr class="machine">
              <td colspan="2"></td>
              <td></td>
              <td></td>
              <td class="w36-who"><?= $esc($m['machine_id']) ?></td>
              <td><?= $esc($m['model']) ?></td>
              <td>
                <?= $esc($m['site']['label']) ?>
                <?php if (empty($m['site']['approved'])): ?><span class="w36-badge wrn" title="not in the approved site list">unlisted</span><?php endif; ?>
              </td>
              <?php foreach ($days as $i => $d): ?>
                <?php $dd = isset($m['daily'][$i]) ? $m['daily'][$i] : null;
                      $av = $dd ? $dd['availability'] : null;
                      $tg = $dd ? $dd['target'] : null; ?>
                <td class="num <?= $misses($av, $tg) ? 'miss' : '' ?>">
                  <?= $esc($pct($av)) ?>
                  <span class="w36-day-target"><?= $esc($tg === null ? 'no target' : $pct($tg)) ?></span>
                </td>
              <?php endforeach; ?>
              <?php foreach ($mc as $k => $v): ?>
                <td class="num wk <?= ($k === 'av' && $misses($mw['availability'], isset($mw['weekly_target']) ? $mw['weekly_target'] : null)) ? 'miss' : '' ?>"><?= $esc($v) ?></td>
              <?php endforeach; ?>
            </tr>
            <tr class="detail">
              <td colspan="<?= 7 + count($days) + count($weekly_cols) ?>">
                <details<?= $print_mode ? ' open' : '' ?>>
                  <summary>
                    <?= $esc($m['machine_id']) ?> — the seven day blocks as the Excel prints them
                    <span class="w36-small">
                      · <?= $esc($m['status'] !== null ? $m['status'] : 'status unknown') ?>
                      <?php if (!empty($m['events'])): ?>
                        · <?= count($m['events']) ?> breakdown event(s)
                        · <?= (int)$mw['days_down'] ?> day(s) down · <?= $esc($num($mw['hours_down'], 2)) ?> h down
                      <?php else: ?>
                        · no breakdown this week
                      <?php endif; ?>
                      <?php if ((int)$m['unresolved_target_days'] > 0): ?>
                        · <?= (int)$m['unresolved_target_days'] ?> day(s) without a resolved target
                      <?php endif; ?>
                    </span>
                  </summary>

                  <table class="w36-detail-tbl">
                    <tr><th>DAY</th><?php foreach ($days as $d): ?><th><?= $esc($d) ?></th><?php endforeach; ?><th>WEEK</th></tr>
                    <?php
                      $rows_out = array(
                        'PREVENTIVE TIME (h)'      => 'preventive_hours',
                        'TOTAL DOWN TIME (h)'      => 'downtime_hours',
                        'NUMBER OF FAILLURE'       => 'failures',
                        'MTBF (h)'                 => 'mtbf_hours',
                        'MTTR (h)'                 => 'mttr_hours',
                        'AVAIBILITY (%)'           => 'availability',
                        'DAILY TARGET KPI (%)'     => 'target',
                      );
                      $week_key = array('preventive_hours' => 'preventive_hours', 'downtime_hours' => 'downtime_hours',
                                        'failures' => 'breakdowns', 'mtbf_hours' => 'mtbf_hours', 'mttr_hours' => 'mttr_hours',
                                        'availability' => 'availability', 'target' => 'weekly_target');
                    ?>
                    <?php foreach ($rows_out as $label => $key): ?>
                      <tr>
                        <th><?= $esc($label) ?></th>
                        <?php foreach ($days as $i => $d): ?>
                          <?php $dd = isset($m['daily'][$i]) ? $m['daily'][$i] : null;
                                $val = $dd && array_key_exists($key, $dd) ? $dd[$key] : null; ?>
                          <td class="<?= $misses($key === 'availability' ? $val : null, $key === 'availability' && $dd ? $dd['target'] : null) ? 'miss' : '' ?>">
                            <?= $esc($key === 'availability' || $key === 'target' ? $pct($val) : $num($val, 2)) ?>
                          </td>
                        <?php endforeach; ?>
                        <?php $wk = isset($mw[$week_key[$key]]) ? $mw[$week_key[$key]] : null; ?>
                        <td><strong><?= $esc($key === 'availability' || $key === 'target' ? $pct($wk) : $num($wk, 2)) ?></strong></td>
                      </tr>
                    <?php endforeach; ?>
                  </table>

                  <?php if (!empty($m['events'])): ?>
                    <div class="w36-small"><strong>Breakdown events in this period</strong></div>
                    <ul class="w36-evts">
                      <?php foreach ($m['events'] as $e): ?>
                        <li>
                          <?= $esc($e['start']) ?> → <?= $e['open'] ? '<span class="open">still down</span>' : $esc($e['end']) ?>
                          <?php if (!empty($e['carried_in'])): ?><em>(started before the week)</em><?php endif; ?>
                          <?php if (empty($e['counts_as_breakdown'])): ?><em>(does not count as a new breakdown)</em><?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>

        <?php if ($unclassified = array_filter($order, function ($id) use ($machines) { return isset($machines[$id]) && empty($machines[$id]['group_code']); })): ?>
          <?php /*
             machines with no group code cannot sit inside a group block, but they
             must still be visible (and counted in the fleet) or the report would
             silently lose them — that is what happened to the sample's rows 101-108
           */ ?>
          <tr class="grp">
            <td colspan="8">Unclassified machines — listed here, counted in the fleet row, printed in no group block</td>
          </tr>
          <?php foreach ($unclassified as $id): $m = $machines[$id]; $mc = $weekly_cells($m['weekly'], $week['open_hours']); ?>
            <tr class="machine">
              <td colspan="7"><?= $esc($m['machine_id']) ?> · <?= $esc($m['model']) ?> · <?= $esc($m['site']['label']) ?></td>
              <?php foreach ($days as $i => $d): ?>
                <?php $dd = isset($m['daily'][$i]) ? $m['daily'][$i] : null; ?>
                <td class="num"><?= $esc($pct($dd ? $dd['availability'] : null)) ?></td>
              <?php endforeach; ?>
              <?php foreach ($mc as $k => $v): ?><td class="num wk"><?= $esc($v) ?></td><?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php /* ── the per-type block (BQ:CB) ───────────────────────────────── */ ?>
  <?php
    $block_cols = array('Fleet Availible Nb', 'Fleet Breakdown Nb', 'APPLICATION',
                        'WEEKLY OPEN TIME h', 'PREVENTIVE TIME h', 'REQUIRED TIME h', 'TOTAL DOWN TIME h',
                        'NUMBER OF FAILLURE Nb', 'MTBF h', 'MTTR h', 'AVAIBILITY OPERATIONAL %', 'WEEKLY TARGET KPI %');
  ?>
  <div class="w36-tblwrap" style="margin-top:16px">
    <table class="w36">
      <thead>
        <tr><?php foreach ($block_cols as $c): ?><th class="wk"><?= $esc($c) ?></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
        <?php foreach ($groups as $g): if ((int)$g['row_count'] === 0) continue; $pt = isset($per_type[$g['code']]) ? $per_type[$g['code']] : array(); $r = $g['rollup']; ?>
          <tr>
            <td class="num wk"><?= (int)(isset($pt['available']) ? $pt['available'] : 0) ?></td>
            <td class="num wk"><?= (int)(isset($pt['down']) ? $pt['down'] : 0) ?></td>
            <td class="wk"><?= $esc($g['label']) ?></td>
            <td class="num wk"><?= $esc($num($week['open_hours'], 0)) ?></td>
            <td class="num wk"><?= $esc($num($r['preventive_hours'], 2)) ?></td>
            <td class="num wk"><?= $esc($num($r['required_time_hours'], 2)) ?></td>
            <td class="num wk"><?= $esc($num($r['downtime_hours'], 2)) ?></td>
            <td class="num wk"><?= $esc($num($r['breakdowns'], 0)) ?></td>
            <td class="num wk"><?= $esc($num($r['mtbf_hours'], 2)) ?></td>
            <td class="num wk"><?= $esc($num($r['mttr_hours'], 2)) ?></td>
            <td class="num wk"><?= $esc($pct($r['availability'])) ?></td>
            <td class="num wk"><?= $esc($pct($r['weekly_target'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="w36-legend">
    Red bold marks a value below its target (BR-W17). The weekly block is BG:BP, the table above it is BQ:CB — the same
    figures the downloaded Excel file carries, at preview density; expand a machine for its seven day blocks.
  </p>

  <?php /* ── W5.5 — sheet `W36 Synthese` on screen: the action plan, the two
           registers and their counters (decisions D16/D17) ──────────────── */ ?>
  <?php
  $sy     = isset($report['synthese']) ? $report['synthese'] : array();
  $plan   = isset($sy['action_plan']) ? $sy['action_plan'] : array();
  $reg    = isset($sy['register']) ? $sy['register'] : array();
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
  ?>
  <div class="w36-panel">
    <h3>Workshop action plan — sheet <code><?= $esc($report_name . ' Synthese') ?></code></h3>
    <p class="w36-legend">
      Every reportable machine, in sheet order. The comment, the action and the status are
      read from the machine's own work order — nothing is typed into the report.
      <?php if (!empty($legend)): ?>
        Legend:
        <?php $sep = ''; foreach ($legend as $code => $label): ?><?= $sep ?><span class="w36-hold"><?= $esc($code) ?></span> <?= $esc($label) ?><?php $sep = ' · '; endforeach; ?>
      <?php endif; ?>
    </p>
    <?php if (empty($plan)): ?>
      <div class="w36-note">No reportable machine in this week — nothing to plan.</div>
    <?php else: ?>
      <div class="w36-tblwrap w36-syn">
        <table class="w36">
          <thead>
            <tr>
              <th>CATEGORIE</th><th>MACHINE ID</th><th>MODEL</th><th>LOCATION</th><th>CURRENT HOURS/ KM</th>
              <th>EQUIPMENT STATUS</th><th>AVAIBILITY OPERATIONAL</th><th>DAILY TARGET KPI</th>
              <th>COMMENT</th><th>ACTION PLAN</th><th>DAYS DOWN</th><th>STATUS</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($plan as $r): ?>
            <?php if ($r['type'] === 'group'): ?>
              <tr class="grp">
                <td colspan="12">
                  <strong><?= $esc($r['label']) ?></strong>
                  · <?= (int)$r['machines'] ?> machine<?= (int)$r['machines'] === 1 ? '' : 's' ?>
                  · group availability <strong><?= $pct($r['availability']) ?></strong>
                  · group target <strong><?= $pct($r['target']) ?></strong>
                  <?php if ($r['available'] !== null && $r['down'] !== null): ?>
                    · <?= (int)$r['available'] ?> available / <?= (int)$r['down'] ?> down
                  <?php endif; ?>
                </td>
              </tr>
            <?php else: ?>
              <tr class="machine">
                <td><?= $esc($r['group_label'] !== null ? $r['group_label'] : '—') ?></td>
                <td class="w36-who"><?= $esc($r['machine_id']) ?></td>
                <td><?= $esc($r['model'] === '' ? '—' : $r['model']) ?></td>
                <td><?= $esc($r['location'] === '' || $r['location'] === null ? '—' : $r['location']) ?><?php if (!$r['location_approved']): ?> <span class="miss">?</span><?php endif; ?></td>
                <td class="num"><?= $r['current_hours'] !== null ? $num($r['current_hours'], 1) . ' h' : ($r['current_km'] !== null ? $num($r['current_km'], 1) . ' km' : '—') ?></td>
                <td><?= $esc($r['status'] === null || $r['status'] === '' ? '—' : $r['status']) ?></td>
                <td class="num"><?= $pct($r['availability']) ?></td>
                <td class="num"><?= $pct($r['target']) ?></td>
                <td class="wrap"><?= $r['comment'] === null ? '—' : nl2br($esc($r['comment'])) ?></td>
                <td class="wrap"><?= $r['action_plan'] === null ? '—' : nl2br($esc($r['action_plan'])) ?></td>
                <td class="num<?= (int)$r['days_down'] > 0 ? ' miss' : '' ?>"><?= (int)$r['days_down'] === 0 ? '—' : (int)$r['days_down'] ?></td>
                <td><?php if ($r['hold_code'] !== null): ?><span class="w36-hold" title="<?= $esc($r['hold_label']) ?>"><?= $esc($r['hold_code']) ?></span><?php else: ?>—<?php endif; ?></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="w36-panel">
    <h3>Weekly inspection report &amp; weekly planned PM services</h3>
    <p class="w36-legend">
      One row per machine: the inspection status and the inspector's comments on the left,
      the planned PM services, with the interval the database stores, on the right.
    </p>
    <?php if (empty($reg)): ?>
      <div class="w36-note">No reportable machine in this week — nothing to report.</div>
    <?php else: ?>
      <div class="w36-tblwrap w36-syn">
        <table class="w36">
          <thead>
            <tr>
              <th>CATEGORIE</th><th>MACHINE ID</th><th>INSPECTION COMMENTS</th><th>STATUS</th>
              <th>TYPE</th><th>STATUS</th><th>PM COMMENTS</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($reg as $r): ?>
            <?php if ($r['type'] === 'group'): ?>
              <tr class="grp"><td colspan="7"><strong><?= $esc($r['label']) ?></strong> · <?= (int)$r['machines'] ?> machine<?= (int)$r['machines'] === 1 ? '' : 's' ?></td></tr>
            <?php else: ?>
              <?php
              $insp_cls = strtolower($r['inspection']['status']);
              $types    = $svc_join($r['pm']['services'], 'type');
              $statuses = $svc_join($r['pm']['services'], 'status');
              $reasons  = $svc_join($r['pm']['services'], 'comment');
              ?>
              <tr class="machine">
                <td><?= $esc($r['group_label'] !== null ? $r['group_label'] : '—') ?></td>
                <td class="w36-who"><?= $esc($r['machine_id']) ?></td>
                <td class="wrap"><?= empty($r['inspection']['comments']) ? '—' : nl2br($esc(implode("\n", $r['inspection']['comments']))) ?></td>
                <td><span class="w36-insp <?= $esc($insp_cls) ?>"><?= $esc($r['inspection']['status']) ?></span></td>
                <td><?= empty($types) ? '—' : $esc(implode(', ', $types)) ?></td>
                <td class="num"><?= empty($statuses) ? '—' : $esc(implode(', ', $statuses)) ?></td>
                <td class="wrap"><?= empty($reasons) ? '—' : $esc(implode(', ', $reasons)) ?></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php $kpm = isset($skpi['pm']) ? $skpi['pm'] : array(); $kin = isset($skpi['inspection']) ? $skpi['inspection'] : array(); ?>
    <div class="w36-kpis">
      <div class="w36-kpi"><span class="n"><?= (int)$kpm['planned'] ?></span><span class="l">PM planned</span></div>
      <div class="w36-kpi"><span class="n"><?= (int)$kpm['done'] ?></span><span class="l">PM done</span></div>
      <div class="w36-kpi"><span class="n"><?= (int)$kpm['undone'] ?></span><span class="l">PM undone</span></div>
      <div class="w36-kpi"><span class="n"><?= $pct($kpm['ratio_done']) ?></span><span class="l">PM done ratio</span></div>
      <div class="w36-kpi"><span class="n"><?= (int)$kin['machines'] ?></span><span class="l">Machines inspected</span></div>
      <div class="w36-kpi"><span class="n"><?= (int)$kin['done'] ?></span><span class="l">Inspections DONE</span></div>
      <div class="w36-kpi"><span class="n"><?= (int)$kin['undone'] ?></span><span class="l">Inspections UNDONE</span></div>
      <div class="w36-kpi"><span class="n"><?= (int)$kin['breakdown'] ?></span><span class="l">Inspections BREAKDOWN</span></div>
      <div class="w36-kpi"><span class="n"><?= (int)$skpi['total_planned'] ?></span><span class="l">Total action planified</span></div>
    </div>
  </div>

  <?php /* ── W4.3 — the charts (decision D6: here and in the PDF, never in the Excel file) ── */ ?>
  <div class="w36-panel">
    <h3>Charts — this week, computed from the same figures as the sheet</h3>
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
            <?= $esc($chart['title']) ?>
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
      The nine charts of the sample workbook are drawn here (and in the PDF) from the same arrays the sheet is built
      from — the downloaded Excel carries none of them (decision D6). The black tick is the row's own target;
      green means at or above it, red below (BR-W23) — exactly the colour the sheet's cells carry.
    </p>
    <?php if (!empty($pm) || !empty($inspect)): ?>
      <p class="w36-legend">
        PM services: <?= (int)$pm['planned'] ?> planned, <?= (int)$pm['done'] ?> done, <?= (int)$pm['undone'] ?> undone
        (<?= (int)$pm['done'] ?>/<?= (int)$pm['planned'] ?> completed)
        <?php if (!empty($pm['not_judged'])): ?> · <strong><?= count($pm['not_judged']) ?> hours-based schedule(s) cannot be judged without a meter reading (gap G4)</strong><?php endif; ?>.
        Inspections: <?= (int)$inspect['planned'] ?> machines — <?= (int)$inspect['done'] ?> done,
        <?= (int)$inspect['undone'] ?> undone, <?= (int)$inspect['breakdown'] ?> down (BREAKDOWN comes first in the sample's rule, BR-23).
      </p>
    <?php endif; ?>
  </div>

  <?php /* ── the data-quality panel (W2.2 / V4, V8–V10, V13) ──────────── */ ?>
  <div class="w36-panel">
    <h3>Data quality — what to fix before publishing</h3>
    <?php if (empty($quality)): ?>
      <div class="w36-small">No problems found in this week.</div>
    <?php else: ?>
      <ul class="w36-qlist">
        <?php foreach (array('error', 'warn', 'info') as $lvl): ?>
          <?php foreach ($by_level[$lvl] as $q): ?>
            <li>
              <span class="w36-badge <?= $lvl === 'error' ? 'err' : ($lvl === 'warn' ? 'wrn' : 'inf') ?>"><?= $esc($lvl) ?></span>
              <?php if (!empty($q['machine'])): ?><strong><?= $esc($q['machine']) ?></strong><?php endif; ?>
              <?= $esc($q['message']) ?>
              <span class="w36-qcode"><?= $esc($q['code']) ?></span>
            </li>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </ul>
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
        <?php if ($snapshot !== null): ?>· published snapshot (frozen)<?php else: ?>· live reading<?php endif; ?>
        · printed <?= $esc($generated_at) ?>
      </div>
      <div class="w36-sign">Prepared by ______________________ · Checked by ______________________ · Approved by ______________________</div>
    </div>
  <?php endif; ?>

  <?php if (!$print_mode): ?>
  <div class="w36-panel">
    <h3>Preview vs the approved sample</h3>
    <ul class="w36-qlist">
      <li>The fleet row is computed over every reportable machine — the sample's own formula stopped at row 100 while its data ran to 108 (D-W3).</li>
      <li>Group, fleet and per-type figures are capacity-weighted, not an average of the machine values (D-W4 / D-W11 / BR-W18).</li>
      <li>The per-type block uses the same approved label list as the grid (D-W13: the sample spelled EXCAVITORs, FUEL BOWSERs, GENERATORSs differently).</li>
      <li>Every daily target is shown with its provenance on the targets screen; a day with no target anywhere is listed under data quality, never guessed.</li>
    </ul>
  </div>
  <?php endif; /* !$print_mode — the reviewer's note is not part of the report */ ?>

</div>
