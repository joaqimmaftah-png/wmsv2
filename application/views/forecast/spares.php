<?php
/**
 * PM SPARE-PARTS FORECAST — task F10.
 *
 * Read-only requirements preview for active PM schedules whose recorded next
 * due dates fall within the selected window. Quantities and shortage values
 * remain owned by Forecast_model; this view filters, sorts and paginates rows.
 */
$h = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$num = function ($value) {
    return rtrim((string)rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
};
$fold = function ($value) {
    $value = (string)$value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
};
$base_week = $iso_label;
$all_rows = $rows;
$total_scope = count($all_rows);
$shortage_scope = isset($shortage_count) ? (int)$shortage_count : 0;

$q = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string)$_GET['q']) : '';
$q = function_exists('mb_substr') ? mb_substr($q, 0, 160, 'UTF-8') : substr($q, 0, 160);
$q_folded = $fold($q);
$coverage = isset($_GET['coverage']) && is_scalar($_GET['coverage']) ? trim((string)$_GET['coverage']) : 'all';
if (!in_array($coverage, array('all', 'shortage', 'covered'), true)) $coverage = 'all';
$critical_filter = isset($_GET['critical']) && is_scalar($_GET['critical']) ? trim((string)$_GET['critical']) : 'all';
if (!in_array($critical_filter, array('all', 'critical', 'noncritical'), true)) $critical_filter = 'all';
$sort_by = isset($_GET['sort']) && is_scalar($_GET['sort']) ? trim((string)$_GET['sort']) : 'default';
if (!in_array($sort_by, array('default', 'shortage', 'required', 'on_hand', 'on_order', 'earliest'), true)) $sort_by = 'default';
$sort_dir = isset($_GET['dir']) && is_scalar($_GET['dir']) ? strtolower(trim((string)$_GET['dir'])) : 'desc';
if (!in_array($sort_dir, array('asc', 'desc'), true)) $sort_dir = 'desc';
$page_sizes = array(10, 25, 50, 100);
$page_size_raw = isset($_GET['per_page']) && is_scalar($_GET['per_page']) ? trim((string)$_GET['per_page']) : '';
$per_page = ctype_digit($page_size_raw) ? (int)$page_size_raw : 50;
if (!in_array($per_page, $page_sizes, true)) $per_page = 50;

// Keep the model's critical/shortage order as the default and as a stable tie-break.
$original_order = array();
foreach ($all_rows as $index => $row) $original_order[(string)$row['material_id']] = $index;
$sorted_rows = $all_rows;
if ($sort_by !== 'default') {
    usort($sorted_rows, function ($a, $b) use ($sort_by, $sort_dir, $original_order) {
        if ($sort_by === 'earliest') {
            $av = !empty($a['earliest']) ? (string)$a['earliest'] : '';
            $bv = !empty($b['earliest']) ? (string)$b['earliest'] : '';
            if ($av === '' && $bv !== '') $cmp = 1;
            elseif ($bv === '' && $av !== '') $cmp = -1;
            else {
                $cmp = strcmp($av, $bv);
                if ($sort_dir === 'desc') $cmp *= -1;
            }
        } else {
            $av = (float)$a[$sort_by];
            $bv = (float)$b[$sort_by];
            $cmp = $av <=> $bv;
            if ($sort_dir === 'desc') $cmp *= -1;
        }
        if ($cmp !== 0) return $cmp;
        $ak = isset($original_order[(string)$a['material_id']]) ? $original_order[(string)$a['material_id']] : 0;
        $bk = isset($original_order[(string)$b['material_id']]) ? $original_order[(string)$b['material_id']] : 0;
        return $ak <=> $bk;
    });
}

$filtered_rows = array();
foreach ($sorted_rows as $row) {
    $has_shortage = (float)$row['shortage'] > 0;
    if ($coverage === 'shortage' && !$has_shortage) continue;
    if ($coverage === 'covered' && $has_shortage) continue;
    if ($critical_filter === 'critical' && empty($row['is_critical'])) continue;
    if ($critical_filter === 'noncritical' && !empty($row['is_critical'])) continue;
    $searchable = $fold(implode(' ', array(
        $row['item_code'], $row['description'], $row['unit'], implode(' ', $row['needed_by']),
    )));
    if ($q_folded !== '' && strpos($searchable, $q_folded) === false) continue;
    $filtered_rows[] = $row;
}

$total_filtered = count($filtered_rows);
$total_pages = max(1, (int)ceil($total_filtered / $per_page));
$page_raw = isset($_GET['page']) && is_scalar($_GET['page']) ? trim((string)$_GET['page']) : '';
$page = ctype_digit($page_raw) ? max(1, (int)$page_raw) : 1;
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$page_rows = array_slice($filtered_rows, $offset, $per_page);
$showing_from = $total_filtered > 0 ? $offset + 1 : 0;
$showing_to = min($offset + count($page_rows), $total_filtered);
$filters_active = $q !== '' || $coverage !== 'all' || $critical_filter !== 'all';

$page_url = function ($page_number) use ($base_week, $horizon, $q, $coverage, $critical_filter, $sort_by, $sort_dir, $per_page) {
    $params = array(
        'week' => $base_week, 'weeks' => (int)$horizon, 'page' => (int)$page_number,
        'per_page' => $per_page, 'sort' => $sort_by, 'dir' => $sort_dir,
    );
    if ($q !== '') $params['q'] = $q;
    if ($coverage !== 'all') $params['coverage'] = $coverage;
    if ($critical_filter !== 'all') $params['critical'] = $critical_filter;
    return base_url('forecast/spares?' . http_build_query($params));
};
$clear_url = base_url('forecast/spares?' . http_build_query(array(
    'week' => $base_week, 'weeks' => (int)$horizon, 'per_page' => $per_page,
)));
$inventory_url = function ($row) {
    return base_url('materials/inventory?' . http_build_query(array(
        'q' => (string)$row['item_code'], 'item' => (int)$row['material_id'],
    )));
};
?>

<style>
  .spares-kpis { grid-template-columns:repeat(4,minmax(0,1fr)); }
  .spares-sr-only { position:absolute!important; width:1px!important; height:1px!important; padding:0!important; margin:-1px!important; overflow:hidden!important; clip:rect(0,0,0,0)!important; white-space:nowrap!important; border:0!important; }
  .spares-toolbar { padding:12px 14px; display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
  .spares-toolbar form { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:0; }
  .spares-search { width:260px; }
  .spares-coverage { width:190px; }
  .spares-critical { width:170px; }
  .spares-sort { width:180px; }
  .spares-table-wrap { overflow-x:auto; overscroll-behavior-x:contain; }
  .spares-table { min-width:1160px; table-layout:auto; }
  .spares-table th,.spares-table td { white-space:nowrap; }
  .spares-table th:first-child,.spares-table td:first-child { position:sticky; left:0; z-index:2; background:var(--surface); box-shadow:2px 0 6px -3px rgba(0,0,0,.3); }
  .spares-table thead th:first-child { z-index:4; }
  .spares-table .spares-needed { min-width:200px; max-width:330px; white-space:normal; line-height:1.5; }
  .spares-table .spares-description { min-width:220px; max-width:360px; white-space:normal; }
  .spares-quantity { text-align:right; font-variant-numeric:tabular-nums; }
  .spares-toolbar-note,.spares-method { color:var(--t3); font-size:11px; line-height:1.7; }
  .spares-empty { padding:30px 18px; color:var(--t2); }
  .spares-footer {
    padding:11px 16px; border-top:1px solid var(--sep); background:var(--surface-2);
    border-radius:0 0 var(--r-lg) var(--r-lg); display:flex; align-items:center;
    justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:12.5px; color:var(--t2);
  }
  .spares-pages { display:inline-flex; align-items:center; gap:4px; }
  .spares-page-link {
    display:inline-flex; align-items:center; justify-content:center; min-width:30px; height:30px; padding:0 8px;
    border-radius:6px; border:1px solid var(--sep-opaque); background:var(--surface); color:var(--t1);
    font-size:12px; font-weight:500; text-decoration:none; font-variant-numeric:tabular-nums;
  }
  .spares-page-link:hover { background:var(--fill); }
  .spares-page-link.is-current { background:var(--blue); border-color:var(--blue); color:#fff; font-weight:600; }
  .spares-page-link.is-disabled { opacity:.42; pointer-events:none; }
  .spares-page-size { display:inline-flex; align-items:center; gap:6px; }
  .spares-page-size label { font-size:12px; color:var(--t3); margin:0; }
  .spares-page-size select { height:30px; font-size:12px; }
  .spares-table-wrap a:focus-visible,.spares-footer a:focus-visible { outline:2px solid var(--blue); outline-offset:2px; }
  @media(max-width:1000px) { .spares-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media(max-width:700px) {
    .spares-kpis { grid-template-columns:1fr; }
    .spares-toolbar form { width:100%; }
    .spares-search,.spares-coverage,.spares-critical,.spares-sort { width:100%; }
  }
  @media print {
    .spares-toolbar,.spares-footer { display:none!important; }
    .spares-table-wrap { overflow:visible!important; }
    .spares-table { min-width:0; width:100%; }
    .spares-table th,.spares-table td { white-space:normal; padding:4px!important; font-size:7pt!important; }
    .spares-table th:first-child,.spares-table td:first-child { position:static; box-shadow:none; }
  }
</style>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">PM Spare-Parts Forecast</h1>
    <div class="cs">Demand from active PM schedules with recorded next due dates in the selected window.</div>
  </div>
  <form method="get" action="<?= $h(base_url('forecast/spares')) ?>" class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <input type="hidden" name="q" value="<?= $h($q) ?>">
    <input type="hidden" name="coverage" value="<?= $h($coverage) ?>">
    <input type="hidden" name="critical" value="<?= $h($critical_filter) ?>">
    <input type="hidden" name="sort" value="<?= $h($sort_by) ?>">
    <input type="hidden" name="dir" value="<?= $h($sort_dir) ?>">
    <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
    <label for="sparesWeek" style="font-size:11px;color:var(--t3);">From week</label>
    <input type="week" id="sparesWeek" name="week" class="form-control" style="width:170px;" value="<?= $h($iso_label) ?>">
    <label for="sparesHorizon" style="font-size:11px;color:var(--t3);">Horizon</label>
    <select id="sparesHorizon" name="weeks" class="form-select" style="width:120px;">
      <?php for ($w = 1; $w <= 13; $w++): ?>
      <option value="<?= $w ?>" <?= $w === (int)$horizon ? 'selected' : '' ?>><?= $w ?> <?= $w === 1 ? 'week' : 'weeks' ?></option>
      <?php endfor; ?>
    </select>
    <button class="btn btn-sm btn-primary" type="submit">Open</button>
  </form>
</div>

<?php if (empty($table_ready)): ?>
<div class="card spares-empty" role="status" style="border-left:3px solid var(--amber);">
  <div style="font-weight:700;margin-bottom:6px;">Spare-parts requirements are not available</div>
  <div style="font-size:12px;line-height:1.7;">
    The <code>pm_part_requirements</code> table is missing from this database, so the forecast cannot read service-to-part requirements yet.
    Import the database schema before using this page.
  </div>
</div>
<?php elseif ((int)$schedules === 0): ?>
<div class="card spares-empty" role="status">
  <div style="font-weight:700;margin-bottom:6px;">No active PM schedules are due in this window</div>
  <div style="font-size:12px;line-height:1.7;">
    No active schedule has a recorded next due date from <?= date('d M Y', strtotime($from)) ?> through <?= date('d M Y', strtotime($to)) ?>.
    This is a zero-due-schedule state, not evidence that parts requirements are missing.
  </div>
</div>
<?php elseif (empty($ready)): ?>
<div class="card spares-empty" role="status" style="border-left:3px solid var(--amber);">
  <div style="font-weight:700;margin-bottom:6px;">The spares preview could not be assembled</div>
  <div style="font-size:12px;line-height:1.7;">PM schedules are due in this window, but their parts requirements could not be read. Check the schedule parts lists and database configuration.</div>
</div>
<?php else: ?>

<div class="grid g-4 mb-4 spares-kpis">
  <div class="kpi">
    <span class="kpi-tag">PM schedules due</span>
    <div class="kpi-num"><?= number_format((int)$schedules) ?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;"><?= date('d M Y', strtotime($from)) ?> – <?= date('d M Y', strtotime($to)) ?></div>
  </div>
  <div class="kpi">
    <span class="kpi-tag">Distinct parts required</span>
    <div class="kpi-num"><?= number_format($total_scope) ?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">across the full selected window</div>
  </div>
  <div class="kpi">
    <span class="kpi-tag">Parts with remaining shortage</span>
    <div class="kpi-num" style="color:<?= $shortage_scope > 0 ? 'var(--red)' : 'var(--green)' ?>;"><?= number_format($shortage_scope) ?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">after on-hand and outstanding on-order stock</div>
  </div>
  <div class="kpi">
    <span class="kpi-tag">PM schedules with no parts list</span>
    <div class="kpi-num"><?= number_format((int)$no_requirements) ?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">their part demand is not included</div>
  </div>
</div>

<?php if ((int)$no_requirements > 0): ?>
<?php $missing = isset($no_requirements_list) ? $no_requirements_list : array(); ?>
<div class="card mb-4" style="padding:12px 18px;border-left:3px solid var(--amber);">
  <div style="font-size:11.5px;color:var(--t2);line-height:1.7;">
    <strong><?= number_format((int)$no_requirements) ?> of <?= number_format((int)$schedules) ?> due PM schedules have no configured parts list.</strong>
    Demand for those services is absent from the part totals below; the listed requirements may therefore be incomplete.
  </div>
  <?php if ($missing): ?>
  <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px;">
    <?php foreach ($missing as $schedule): ?>
    <a href="<?= $h(base_url('forecast/parts/' . (int)$schedule['pm_schedule_id'])) ?>"
       class="btn btn-ghost btn-sm" style="font-size:11px;padding:5px 10px;">
      <?= $h($schedule['registration_no']) ?> · <?= $h($schedule['schedule_name']) ?>
      <span class="text-300"><?= date('d M Y', strtotime($schedule['next_due_date'])) ?></span>
      <?php if (empty($schedule['has_hours'])): ?><span class="badge b-secondary" style="font-size:9px;">NO STANDARD HOURS</span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="spares-toolbar card mb-3">
  <form method="get" action="<?= $h(base_url('forecast/spares')) ?>" role="search" aria-label="Search and filter PM spare-parts forecast">
    <input type="hidden" name="week" value="<?= $h($iso_label) ?>">
    <input type="hidden" name="weeks" value="<?= (int)$horizon ?>">
    <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
    <label class="spares-sr-only" for="sparesSearch">Search item code, description or needed-by entry</label>
    <input id="sparesSearch" class="form-control spares-search" type="search" name="q" value="<?= $h($q) ?>" placeholder="Item code, description or machine…">
    <label class="spares-sr-only" for="sparesCoverage">Filter by coverage status</label>
    <select id="sparesCoverage" name="coverage" class="form-select spares-coverage">
      <option value="all" <?= $coverage === 'all' ? 'selected' : '' ?>>All coverage statuses</option>
      <option value="shortage" <?= $coverage === 'shortage' ? 'selected' : '' ?>>Remaining shortage</option>
      <option value="covered" <?= $coverage === 'covered' ? 'selected' : '' ?>>Covered</option>
    </select>
    <label class="spares-sr-only" for="sparesCritical">Filter by criticality</label>
    <select id="sparesCritical" name="critical" class="form-select spares-critical">
      <option value="all" <?= $critical_filter === 'all' ? 'selected' : '' ?>>All criticality</option>
      <option value="critical" <?= $critical_filter === 'critical' ? 'selected' : '' ?>>Critical parts</option>
      <option value="noncritical" <?= $critical_filter === 'noncritical' ? 'selected' : '' ?>>Non-critical parts</option>
    </select>
    <label class="spares-sr-only" for="sparesSort">Sort parts forecast</label>
    <select id="sparesSort" name="sort" class="form-select spares-sort">
      <option value="default" <?= $sort_by === 'default' ? 'selected' : '' ?>>Default: critical / shortage</option>
      <option value="shortage" <?= $sort_by === 'shortage' ? 'selected' : '' ?>>Shortage quantity</option>
      <option value="required" <?= $sort_by === 'required' ? 'selected' : '' ?>>Required quantity</option>
      <option value="on_hand" <?= $sort_by === 'on_hand' ? 'selected' : '' ?>>On-hand quantity</option>
      <option value="on_order" <?= $sort_by === 'on_order' ? 'selected' : '' ?>>On-order quantity</option>
      <option value="earliest" <?= $sort_by === 'earliest' ? 'selected' : '' ?>>First needed date</option>
    </select>
    <select id="sparesSortDirection" name="dir" class="form-select" aria-label="Sort direction" style="width:110px;" <?= $sort_by === 'default' ? 'disabled' : '' ?>>
      <option value="desc" <?= $sort_dir === 'desc' ? 'selected' : '' ?>>High / latest first</option>
      <option value="asc" <?= $sort_dir === 'asc' ? 'selected' : '' ?>>Low / earliest first</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    <a class="btn btn-ghost btn-sm" href="<?= $h($clear_url) ?>">Clear</a>
  </form>
</div>

<div class="spares-toolbar-note mb-3">
  <?= number_format($total_filtered) ?> matching <?= $total_filtered === 1 ? 'part' : 'parts' ?><?= $filters_active ? ' of ' . number_format($total_scope) . ' in the full forecast' : ' in the full forecast' ?>.
  Headline counts above describe the full forecast and do not change with table filters.
</div>

<div class="card mb-4" style="overflow:hidden;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Parts requirement vs available supply</div>
    <div class="cs">Critical flags mean a PM service cannot proceed without the part. Default order: critical items first, then larger shortages within each criticality group. Shortage = required − on hand − outstanding on order, floored at zero. This preview does not place a purchase order.</div>
  </div>
  <?php if (empty($on_order_source_ready)): ?>
  <div role="status" style="padding:10px 18px;border-top:1px solid var(--g2);font-size:11px;color:var(--amber);">
    Outstanding-order tables are unavailable, so the displayed shortage cannot subtract open supplier-order quantities.
  </div>
  <?php endif; ?>
  <?php if ($total_filtered === 0): ?>
  <div class="spares-empty" role="status">
    <?php if ($total_scope === 0): ?>
      No part requirement rows were calculated for the due schedules in this window. Review their configured parts lists; any due schedules without a list are identified above.
    <?php else: ?>
      No parts match these filters. <a href="<?= $h($clear_url) ?>">Clear filters</a> to show the full forecast.
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="spares-table-wrap">
    <table class="tbl spares-table">
      <caption class="spares-sr-only">One row per distinct material required by active PM schedules in the selected date window. Quantities use the material unit shown in each row. The table is a read-only forecast.</caption>
      <thead>
        <tr>
          <th scope="col">ITEM</th>
          <th scope="col">DESCRIPTION</th>
          <th scope="col" style="text-align:right;">REQUIRED</th>
          <th scope="col" style="text-align:right;">ON HAND</th>
          <th scope="col" style="text-align:right;">ON ORDER</th>
          <th scope="col" style="text-align:right;">SHORTAGE</th>
          <th scope="col" style="text-align:center;">NEEDED BY</th>
          <th scope="col" style="text-align:center;">SUPPLY STATUS</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($page_rows as $row): ?>
        <tr style="<?= (float)$row['shortage'] > 0 ? 'background:rgba(192,57,43,0.04);' : '' ?>">
          <td class="mono" style="font-weight:600;">
            <?php if (!empty($row['item_code']) && !empty($row['material_id'])): ?>
            <a href="<?= $h($inventory_url($row)) ?>" title="Open this item in the inventory catalogue"><?= $h($row['item_code']) ?></a>
            <?php else: ?><?= $h($row['item_code'] !== null ? $row['item_code'] : '—') ?><?php endif; ?>
            <?php if (!empty($row['is_critical'])): ?><span class="badge b-critical" style="margin-left:6px;font-size:9px;">CRITICAL</span><?php endif; ?>
          </td>
          <td class="spares-description" style="color:var(--t2);font-size:11px;"><?= $h($row['description'] !== null ? $row['description'] : '—') ?></td>
          <td class="mono spares-quantity" style="font-weight:600;">
            <?= $num($row['required']) ?> <span style="color:var(--t3);font-size:9.5px;"><?= $h($row['unit'] !== null && $row['unit'] !== '' ? $row['unit'] : '—') ?></span>
          </td>
          <td class="mono spares-quantity" style="color:var(--t2);">
            <?= $num($row['on_hand']) ?> <span style="color:var(--t3);font-size:9.5px;"><?= $h($row['unit'] !== null && $row['unit'] !== '' ? $row['unit'] : '—') ?></span>
          </td>
          <td class="mono spares-quantity" style="color:var(--t2);">
            <?= $num($row['on_order']) ?> <span style="color:var(--t3);font-size:9.5px;"><?= $h($row['unit'] !== null && $row['unit'] !== '' ? $row['unit'] : '—') ?></span>
          </td>
          <td class="mono spares-quantity" style="font-weight:800;color:<?= (float)$row['shortage'] > 0 ? 'var(--red)' : 'var(--t3)' ?>;">
            <?= $num($row['shortage']) ?> <span style="color:var(--t3);font-size:9.5px;font-weight:400;"><?= $h($row['unit'] !== null && $row['unit'] !== '' ? $row['unit'] : '—') ?></span>
          </td>
          <td class="spares-needed" style="font-size:10px;color:var(--t3);text-align:center;">
            <?= $h(implode(', ', array_slice($row['needed_by'], 0, 4))) ?>
            <?= count($row['needed_by']) > 4 ? ' +' . (count($row['needed_by']) - 4) . ' more need dates' : '' ?>
            <?php if (!empty($row['earliest'])): ?>
              <br><span style="color:var(--amber);font-weight:600;">First needed <?= date('d M Y', strtotime($row['earliest'])) ?></span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;">
            <?php if ($row['covered']): ?>
              <span class="badge b-completed">COVERED</span>
            <?php else: ?>
              <span class="badge b-danger">SHORTAGE</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="spares-footer">
    <div aria-live="polite">
      Showing <strong class="mono"><?= number_format($showing_from) ?>–<?= number_format($showing_to) ?></strong>
      of <strong class="mono"><?= number_format($total_filtered) ?></strong> parts
      <?php if ($total_filtered < $total_scope): ?><span>(filtered from <?= number_format($total_scope) ?>)</span><?php endif; ?>
    </div>
    <?php if ($total_pages > 1): ?>
    <nav class="spares-pages" aria-label="Spares forecast pagination">
      <a class="spares-page-link <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= $h($page_url(max(1, $page - 1))) ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : 'rel="prev"' ?>>Prev</a>
      <?php
      $window_start = max(1, $page - 2);
      $window_end = min($total_pages, $page + 2);
      if ($window_start > 1): ?>
        <a class="spares-page-link" href="<?= $h($page_url(1)) ?>">1</a>
        <?php if ($window_start > 2): ?><span style="padding:0 4px;color:var(--t3);">&hellip;</span><?php endif; ?>
      <?php endif;
      for ($p = $window_start; $p <= $window_end; $p++): ?>
        <a class="spares-page-link <?= $p === $page ? 'is-current' : '' ?>" <?= $p === $page ? 'aria-current="page"' : '' ?> href="<?= $h($page_url($p)) ?>"><?= $p ?></a>
      <?php endfor;
      if ($window_end < $total_pages): ?>
        <?php if ($window_end < $total_pages - 1): ?><span style="padding:0 4px;color:var(--t3);">&hellip;</span><?php endif; ?>
        <a class="spares-page-link" href="<?= $h($page_url($total_pages)) ?>"><?= $total_pages ?></a>
      <?php endif; ?>
      <a class="spares-page-link <?= $page >= $total_pages ? 'is-disabled' : '' ?>" href="<?= $h($page_url(min($total_pages, $page + 1))) ?>" <?= $page >= $total_pages ? 'aria-disabled="true" tabindex="-1"' : 'rel="next"' ?>>Next</a>
    </nav>
    <?php endif; ?>
    <form method="get" action="<?= $h(base_url('forecast/spares')) ?>" class="spares-page-size">
      <input type="hidden" name="week" value="<?= $h($iso_label) ?>">
      <input type="hidden" name="weeks" value="<?= (int)$horizon ?>">
      <input type="hidden" name="q" value="<?= $h($q) ?>">
      <input type="hidden" name="coverage" value="<?= $h($coverage) ?>">
      <input type="hidden" name="critical" value="<?= $h($critical_filter) ?>">
      <input type="hidden" name="sort" value="<?= $h($sort_by) ?>">
      <input type="hidden" name="dir" value="<?= $h($sort_dir) ?>">
      <input type="hidden" name="page" value="1">
      <label for="sparesPageSize">Rows:</label>
      <select id="sparesPageSize" name="per_page" onchange="this.form.submit()">
        <?php foreach ($page_sizes as $size): ?>
        <option value="<?= (int)$size ?>" <?= $per_page === $size ? 'selected' : '' ?>><?= (int)$size ?> / page</option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn btn-sm">Apply</button></noscript>
    </form>
  </div>
  <?php endif; ?>
</div>

<div class="spares-method mb-4">
  <strong>Forecast scope and calculation.</strong>
  The preview includes one occurrence for each active PM schedule whose recorded next due date falls in the date window; it does not project later recurring services from a meter interval. Per-material required quantity is compared with <code>materials.final_qty</code> and outstanding supplier-order quantities; the displayed shortage is the positive remainder, rounded to three decimals. The coverage badge is informational—this page does not create or change purchase orders.
</div>
<?php endif; ?>

<?php if (!empty($lead_times)): ?>
<div class="card" style="overflow:hidden;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Historical actual delivery time by route</div>
    <div class="cs">Mean calendar days from order date to actual delivery, grouped by country and shipping method; completed orders with actual delivery dates only. This is not an item-specific ETA.</div>
  </div>
  <div class="spares-table-wrap">
    <table class="tbl spares-table" style="min-width:650px;">
      <caption class="spares-sr-only">Historical average supplier-order delivery time by country and shipping method, with the number of completed orders in each group. Not an item-specific delivery estimate.</caption>
      <thead>
        <tr>
          <th scope="col">COUNTRY</th>
          <th scope="col">SHIPPING METHOD</th>
          <th scope="col" style="text-align:right;">AVERAGE DAYS</th>
          <th scope="col" style="text-align:right;">COMPLETED ORDERS</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($lead_times as $lead_time): ?>
        <tr>
          <td><?= $h($lead_time->country) ?></td>
          <td style="color:var(--t2);"> <?= $h($lead_time->shipping_method) ?> </td>
          <td class="mono spares-quantity" style="font-weight:600;"><?= number_format((float)$lead_time->avg_days, 1) ?></td>
          <td class="mono spares-quantity" style="color:var(--t3);"><?= number_format((int)$lead_time->n) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="card spares-empty" role="status" style="margin-top:16px;">
  <strong>No route-level delivery-time average is available.</strong>
  <?php if (empty($lead_times_source_ready)): ?>
    The purchase-order table is unavailable, so lead-time history could not be read.
  <?php else: ?>
    No completed-order delivery results were returned. This should not be interpreted as a zero-day lead time.
  <?php endif; ?>
</div>
<?php endif; ?>
