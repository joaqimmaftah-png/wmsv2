<?php
/**
 * ROLLING THREE-WEEK MAINTENANCE PLAN — task F11.
 *
 * This is a comparison matrix: one fleet machine per row, three successive
 * weekly plan states across columns. The values come from Forecast_model; this
 * view only filters and presents those values.
 */
$pct = function ($v, $d = 1) { return $v === null ? '—' : number_format((float)($v * 100), $d) . '%'; };
$hrs = function ($v) { return $v === null ? '—' : rtrim((string)(rtrim((string)(number_format((float)$v, 2, '.', '')), '0')), '.'); };
$col = function ($v) {
    if ($v === null) return 'var(--t3)';
    return $v >= 0.90 ? 'var(--green)' : ($v >= 0.70 ? 'var(--amber)' : 'var(--red)');
};
$rolling_h = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };

// The status/category vocabulary below is the same plan-row rule as
// forecast/planned.php: out of service, stand-by, no plan, otherwise planned.
$rolling_statuses = array('all', 'out_of_service', 'standby', 'no_plan', 'planned_all');
$rolling_status = isset($_GET['status']) && is_scalar($_GET['status']) ? trim((string)$_GET['status']) : 'all';
if (!in_array($rolling_status, $rolling_statuses, true)) $rolling_status = 'all';
$rolling_query = isset($_GET['q']) && is_scalar($_GET['q']) ? substr(trim((string)$_GET['q']), 0, 160) : '';
$rolling_type = isset($_GET['equipment_type']) && is_scalar($_GET['equipment_type']) ? trim((string)$_GET['equipment_type']) : '';

$rolling_types = array();
foreach ($machines as $machine) $rolling_types[(string)$machine['type']] = true;
$rolling_types = array_keys($rolling_types);
sort($rolling_types, SORT_NATURAL | SORT_FLAG_CASE);
if ($rolling_type !== '' && !in_array($rolling_type, $rolling_types, true)) $rolling_type = '';

$rolling_counts = array('all' => count($machines), 'out_of_service' => 0, 'standby' => 0, 'no_plan' => 0, 'planned_all' => 0);
$rolling_filtered = array();
$rolling_search = function ($value) {
    $value = (string)$value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
};
$rolling_query_normalized = $rolling_search($rolling_query);

foreach ($machines as $machine) {
    $flags = array('out_of_service' => false, 'standby' => false, 'no_plan' => false, 'planned_all' => count($weeks) > 0);
    $machine_search_text = $rolling_search(implode(' ', array(
        $machine['registration_no'], $machine['type'], $machine['location'],
    )));

    foreach ($weeks as $week_row) {
        $week_offset = (int)$week_row['offset'];
        $week_machine = isset($machine['weeks'][$week_offset]) ? $machine['weeks'][$week_offset] : null;
        if (!$week_machine) {
            $flags['planned_all'] = false;
            continue;
        }
        if (!empty($week_machine['out_of_service']) || !empty($week_machine['out_days'])) {
            $flags['out_of_service'] = true;
            $flags['planned_all'] = false;
            $machine_search_text .= ' out of service out';
        } elseif (!empty($week_machine['standby'])) {
            $flags['standby'] = true;
            $flags['planned_all'] = false;
            $machine_search_text .= ' stand-by standby';
        } elseif (empty($week_machine['has_plan'])) {
            $flags['no_plan'] = true;
            $flags['planned_all'] = false;
            $machine_search_text .= ' no plan';
        } else {
            $machine_search_text .= ' planned';
        }
    }

    foreach (array('out_of_service', 'standby', 'no_plan') as $flag_name) {
        if ($flags[$flag_name]) $rolling_counts[$flag_name]++;
    }
    if ($flags['planned_all']) $rolling_counts['planned_all']++;

    $status_match = $rolling_status === 'all'
        || ($rolling_status === 'planned_all' ? $flags['planned_all'] : !empty($flags[$rolling_status]));
    $type_match = $rolling_type === '' || (string)$machine['type'] === $rolling_type;
    $query_match = $rolling_query_normalized === ''
        || strpos($machine_search_text, $rolling_query_normalized) !== false;

    if ($status_match && $type_match && $query_match) {
        $machine['_rolling_flags'] = $flags;
        $rolling_filtered[] = $machine;
    }
}
$rolling_status_labels = array(
    'all'            => 'All machines',
    'out_of_service' => 'Out-of-service time in any week',
    'standby'        => 'Stand-by reserve',
    'no_plan'        => 'Missing a plan in any week',
    'planned_all'    => 'Planned in all 3 weeks',
);
$rolling_status_params = function ($status_value) use ($base_label, $rolling_query, $rolling_type) {
    $params = array('week' => $base_label);
    if ($rolling_query !== '') $params['q'] = $rolling_query;
    if ($rolling_type !== '') $params['equipment_type'] = $rolling_type;
    if ($status_value !== 'all') $params['status'] = $status_value;
    return '?' . http_build_query($params);
};
?>

<style>
  .rolling-kpi-grid { grid-template-columns:repeat(3,minmax(0,1fr)); }
  .rolling-toolbar { padding:12px 14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .rolling-toolbar form { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:0; }
  .rolling-search { display:flex; align-items:center; gap:7px; }
  .rolling-search svg { width:15px; height:15px; color:var(--t3); flex:none; }
  .rolling-search input { width:220px; }
  .rolling-type { width:180px; }
  .rolling-status-tabs { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin:0 0 7px; }
  .rolling-status-tab { border:1px solid var(--sep); border-radius:7px; padding:6px 9px; color:var(--t2); background:var(--surface); text-decoration:none; font-size:11px; font-weight:600; }
  .rolling-status-tab:hover { border-color:var(--blue); color:var(--blue); }
  .rolling-status-tab[aria-current="page"] { border-color:var(--blue); background:var(--blue-d); color:var(--blue); }
  .rolling-status-tab span { margin-left:4px; opacity:.8; font-variant-numeric:tabular-nums; }
  .rolling-filter-note,.rolling-summary { color:var(--t3); font-size:10.5px; line-height:1.55; }
  .rolling-summary { margin:8px 2px 10px; }
  .rolling-table-wrap { overflow-x:auto; overscroll-behavior-x:contain; }
  .rolling-table { min-width:1260px; table-layout:auto; }
  .rolling-table th,.rolling-table td { white-space:nowrap; }
  .rolling-table thead th { text-align:center; }
  .rolling-table thead th[rowspan="2"] { text-align:left; }
  .rolling-table thead tr:first-child th { padding-top:9px; padding-bottom:9px; }
  .rolling-table .rolling-week-head { border-left:2px solid var(--sep); color:var(--t1); }
  .rolling-table .rolling-week-start { border-left:2px solid var(--sep)!important; }
  .rolling-table .rolling-id { min-width:130px; }
  .rolling-table .rolling-type { min-width:120px; }
  .rolling-table .rolling-location { min-width:140px; }
  .rolling-table th:first-child,.rolling-table td:first-child { position:sticky; left:0; background:var(--surface); box-shadow:2px 0 6px -3px rgba(0,0,0,.32); }
  .rolling-table td:first-child { z-index:2; border-right:1px solid var(--sep); }
  .rolling-table thead th:first-child { z-index:5; }
  .rolling-table tbody tr:hover td:first-child { background:#f7f7f8; }
  .rolling-table .rolling-status { text-align:center; }
  .rolling-table .rolling-status .badge { font-size:9px; padding:3px 7px; }
  .rolling-empty { padding:34px 18px; text-align:center; color:var(--t2); }
  .rolling-help { margin:14px 2px 0; max-width:980px; font-size:10.5px; line-height:1.75; color:var(--t3); }
  .rolling-machine-link { color:var(--blue); text-decoration:none; font-weight:700; }
  .rolling-machine-link:hover { text-decoration:underline; }
  .rolling-sr-only { position:absolute!important; width:1px!important; height:1px!important; padding:0!important; margin:-1px!important; overflow:hidden!important; clip:rect(0,0,0,0)!important; white-space:nowrap!important; border:0!important; }
  @media(max-width:820px) { .rolling-kpi-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media(max-width:480px) { .rolling-kpi-grid { grid-template-columns:1fr; } }
  @media(max-width:700px) {
    .rolling-toolbar { align-items:stretch; }
    .rolling-toolbar form { width:100%; }
    .rolling-search { flex:1 1 100%; }
    .rolling-search input,.rolling-type { width:100%; }
    .rolling-status-tabs { overflow-x:auto; flex-wrap:nowrap; padding-bottom:4px; }
    .rolling-status-tab { flex:none; }
  }
  @media print {
    .rolling-toolbar,.rolling-status-tabs,.rolling-filter-note,.rolling-summary,.rolling-no-print { display:none!important; }
    .rolling-table-wrap { overflow:visible!important; }
    .rolling-table { min-width:0; width:100%; }
    .rolling-table th,.rolling-table td { white-space:normal; padding:4px!important; font-size:7pt!important; }
    .rolling-table th:first-child,.rolling-table td:first-child { position:static; box-shadow:none; }
  }
</style>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">Rolling Maintenance Plan</h1>
    <div class="cs">Three weeks from <?= $rolling_h($base_label) ?> — one row per machine, status and plan metrics by week</div>
  </div>
  <?php if (!empty($tables_ready)): ?>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <form method="get" action="<?= $rolling_h(base_url('forecast/rolling')) ?>" class="flex ai-c gap-10" style="flex-wrap:wrap;">
      <input type="hidden" name="q" value="<?= $rolling_h($rolling_query) ?>">
      <input type="hidden" name="equipment_type" value="<?= $rolling_h($rolling_type) ?>">
      <input type="hidden" name="status" value="<?= $rolling_h($rolling_status) ?>">
      <label for="rollingWeek" style="font-size:11px;color:var(--t3);">From week</label>
      <input type="week" id="rollingWeek" name="week" class="form-control" style="width:170px;" value="<?= $rolling_h($base_label) ?>">
      <button class="btn btn-sm btn-primary" type="submit">Open</button>
    </form>
    <a class="btn btn-sm" href="<?= $rolling_h(base_url('forecast/export/rolling?week=' . urlencode($base_label))) ?>" title="Exports the complete rolling plan, not only the rows currently filtered on screen">
      Export complete plan XLSX
    </a>
  </div>
  <?php endif; ?>
</div>

<?php if (empty($tables_ready)): ?>
<div class="card" role="alert" style="padding:28px;border-left:3px solid var(--amber);">
  <strong>The forecast tables are not set up yet.</strong>
  <div class="cs" style="margin-top:7px;line-height:1.7;">The rolling plan cannot distinguish an empty draft from an unconfigured database. Import the database dump shipped with this repository, then reload this page.</div>
</div>
<?php else: ?>

<div class="grid g-3 mb-4 rolling-kpi-grid">
  <?php foreach ($weeks as $w): $f = $w['plan']['fleet']; ?>
  <div class="kpi">
    <span class="kpi-tag">
      <?= $w['offset'] === 0 ? 'This week' : '+' . $w['offset'] . ' week' . ($w['offset'] > 1 ? 's' : '') ?>
      · <?= $rolling_h($w['iso_label']) ?>
    </span>
    <div class="kpi-num" style="color:<?= $col($f['availability']) ?>;"><?= $pct($f['availability']) ?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">
      <?= date('d M', strtotime($w['week_start'])) ?> – <?= date('d M', strtotime($w['plan']['week_end'])) ?> ·
      <?= (int)$w['plan']['unit']['available'] ?>/<?= (int)$w['plan']['unit']['total'] ?> units · <?= $hrs($f['pm_hours']) ?> h PM
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-3 rolling-toolbar">
  <form method="get" action="<?= $rolling_h(base_url('forecast/rolling')) ?>" role="search" aria-label="Filter rolling maintenance plan">
    <input type="hidden" name="week" value="<?= $rolling_h($base_label) ?>">
    <input type="hidden" name="status" value="<?= $rolling_h($rolling_status) ?>">
    <label class="rolling-search" for="rollingSearch">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input type="search" id="rollingSearch" name="q" value="<?= $rolling_h($rolling_query) ?>" class="form-control" placeholder="Machine, type or location…" aria-label="Search machine, equipment type, or location">
    </label>
    <label for="rollingType" class="rolling-sr-only">Filter by equipment type</label>
    <select id="rollingType" name="equipment_type" class="form-select rolling-type">
      <option value="">All equipment types</option>
      <?php foreach ($rolling_types as $optionType): ?>
      <option value="<?= $rolling_h($optionType) ?>" <?= $rolling_type === $optionType ? 'selected' : '' ?>><?= $rolling_h($optionType) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Apply filters</button>
    <a class="btn btn-ghost btn-sm" href="<?= $rolling_h(base_url('forecast/rolling?week=' . urlencode($base_label))) ?>">Clear filters</a>
  </form>
</div>

<nav class="rolling-status-tabs" aria-label="Filter machines by plan status across the three-week window">
  <?php foreach ($rolling_status_labels as $statusKey => $statusLabel): ?>
  <a class="rolling-status-tab" href="<?= $rolling_h($rolling_status_params($statusKey)) ?>" <?= $rolling_status === $statusKey ? 'aria-current="page"' : '' ?>>
    <?= $rolling_h($statusLabel) ?> <span><?= number_format($rolling_counts[$statusKey]) ?></span>
  </a>
  <?php endforeach; ?>
</nav>
<div class="rolling-filter-note">
  Status counts cover the full three-week machine set and are independent of search/type filters. “Any week” conditions can overlap, so exception counts need not add up to the total. Filters apply to this table only; the week summary cards and XLSX export remain full-scope.
</div>
<div class="rolling-summary" aria-live="polite">
  Showing <?= number_format(count($rolling_filtered)) ?> of <?= number_format(count($machines)) ?> machines<?= ($rolling_query !== '' || $rolling_type !== '' || $rolling_status !== 'all') ? ' matching current filters' : '' ?>.
</div>

<?php if (empty($machines)): ?>
<div class="card rolling-empty" role="status">No machines are available in this three-week forecast window.</div>
<?php elseif (empty($rolling_filtered)): ?>
<div class="card rolling-empty" role="status">
  <strong>No machines match these filters.</strong><br>
  Try another search or filter, or <a href="<?= $rolling_h(base_url('forecast/rolling?week=' . urlencode($base_label))) ?>">clear filters</a>.
</div>
<?php else: ?>
<div class="card" style="overflow:hidden;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Fleet across the three weeks</div>
    <div class="cs">One machine per row; each weekly block shows plan state, model downtime hours, and the machine-level planned availability.</div>
  </div>
  <div class="rolling-table-wrap">
    <table class="tbl tbl-sticky-first rolling-table" aria-describedby="rolling-help">
      <caption class="rolling-sr-only">Three-week maintenance plan for the selected machines. Each week has plan status, downtime hours, and planned availability.</caption>
      <colgroup><col><col><col></colgroup>
      <?php foreach ($weeks as $w): ?><colgroup span="3"></colgroup><?php endforeach; ?>
      <thead>
        <tr>
          <th scope="col" rowspan="2" class="rolling-id">MACHINE</th>
          <th scope="col" rowspan="2" class="rolling-type">TYPE</th>
          <th scope="col" rowspan="2" class="rolling-location">LOCATION</th>
          <?php foreach ($weeks as $w): ?>
          <th scope="colgroup" colspan="3" class="rolling-week-head">
            <?= $rolling_h($w['iso_label']) ?><br><span style="font-size:9.5px;font-weight:400;color:var(--t3);">
              <?= date('d M', strtotime($w['week_start'])) ?> – <?= date('d M', strtotime($w['plan']['week_end'])) ?>
            </span>
          </th>
          <?php endforeach; ?>
        </tr>
        <tr>
          <?php foreach ($weeks as $w): ?>
          <th scope="col" class="rolling-week-start">STATUS</th>
          <th scope="col" style="text-align:right;">DOWNTIME h</th>
          <th scope="col" style="text-align:right;">PLAN AVAIL</th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rolling_filtered as $machine): ?>
        <tr>
          <td class="mono">
            <a class="rolling-machine-link" href="<?= $rolling_h(base_url('equipment/view/' . (int)$machine['equipment_id'])) ?>"><?= $rolling_h($machine['registration_no']) ?></a>
          </td>
          <td style="color:var(--t3);font-size:10.5px;"><?= $rolling_h($machine['type']) ?></td>
          <td style="color:var(--t3);font-size:10.5px;"><?= $rolling_h($machine['location']) ?></td>
          <?php foreach ($weeks as $w):
              $r = isset($machine['weeks'][$w['offset']]) ? $machine['weeks'][$w['offset']] : null;
              if (!$r) {
                  $weekStatus = '—';
                  $weekBadge = 'b-secondary';
              } elseif (!empty($r['out_of_service'])) {
                  $weekStatus = 'OUT OF SERVICE';
                  $weekBadge = 'b-high';
              } elseif (!empty($r['out_days'])) {
                  $outDayCount = (int)$r['out_days'];
                  $weekStatus = 'OUT ' . $outDayCount . ' DAY' . ($outDayCount === 1 ? '' : 'S');
                  $weekBadge = 'b-high';
              } elseif (!empty($r['standby'])) {
                  $weekStatus = 'STAND-BY';
                  $weekBadge = 'b-standby';
              } elseif (empty($r['has_plan'])) {
                  $weekStatus = 'NO PLAN';
                  $weekBadge = 'b-secondary';
              } else {
                  $weekStatus = 'PLANNED';
                  $weekBadge = 'b-completed';
              }
              $dt = $r ? $r['downtime_hours'] : null;
          ?>
          <td class="rolling-week-start rolling-status"><span class="badge <?= $rolling_h($weekBadge) ?>"><?= $rolling_h($weekStatus) ?></span></td>
          <td class="mono" style="text-align:right;color:var(--t2);"> <?= $hrs($dt) ?> </td>
          <td class="mono" style="text-align:right;font-weight:700;color:<?= $col($r && !$r['out_of_service'] ? $r['availability'] : null) ?>;">
            <?= $r && !$r['out_of_service'] ? $pct($r['availability']) : '—' ?>
          </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div id="rolling-help" class="rolling-help">
  <strong>How to read the rolling plan.</strong> Downtime hours use the forecast model's own weekly downtime total (standing, unplanned and corrective categories, capped according to the model's availability rule). PLAN AVAIL is the machine-level result of the entered plan, not actual operating availability. An OUT OF SERVICE machine is not scored. Stand-by reserve machines remain visible but are excluded from group/fleet availability roll-ups. A NO PLAN week can still show a calculated value because no planned downtime has been entered; do not interpret that as actual uptime. A week without a published snapshot shows its current draft. Publish each week on <a href="<?= $rolling_h(base_url('forecast')) ?>">Planned Availability</a> to freeze it.
</div>

<?php endif; ?>
