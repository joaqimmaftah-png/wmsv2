<?php
/**
 * PLANNED ALLOWANCES — task F9, decision D31.
 *
 * Read-only per-day allowance preview for the selected target week. Values
 * come from Forecast_model; this view only filters, sorts and paginates rows.
 */
$h = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$num = function ($value) {
    return rtrim((string)rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
};
$search_fold = function ($value) {
    $value = (string)$value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
};

$base_label = $iso_label;
$total_scope = count($rows);
$derived_count = 0;
foreach ($rows as $row) if ($row['source'] === 'derived') $derived_count++;
$flat_count = $total_scope - $derived_count;

$type_set = array();
foreach ($rows as $row) {
    $type_name = trim((string)$row['type']);
    if ($type_name !== '') $type_set[$type_name] = true;
}
$type_options = array_keys($type_set);
sort($type_options, SORT_NATURAL | SORT_FLAG_CASE);

$query = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string)$_GET['q']) : '';
$query = function_exists('mb_substr') ? mb_substr($query, 0, 160, 'UTF-8') : substr($query, 0, 160);
$query_folded = $search_fold($query);
$type_filter = isset($_GET['type']) && is_scalar($_GET['type']) ? trim((string)$_GET['type']) : '';
if ($type_filter !== '' && !in_array($type_filter, $type_options, true)) $type_filter = '';
$source_filter = isset($_GET['source']) && is_scalar($_GET['source']) ? trim((string)$_GET['source']) : 'all';
if (!in_array($source_filter, array('all', 'derived', 'flat'), true)) $source_filter = 'all';
$sort_by = isset($_GET['sort']) && is_scalar($_GET['sort']) ? trim((string)$_GET['sort']) : 'fleet';
if (!in_array($sort_by, array('fleet', 'unplanned', 'standing', 'events'), true)) $sort_by = 'fleet';
$sort_dir = isset($_GET['dir']) && is_scalar($_GET['dir']) ? strtolower(trim((string)$_GET['dir'])) : 'desc';
if (!in_array($sort_dir, array('asc', 'desc'), true)) $sort_dir = 'desc';
$page_sizes = array(10, 25, 50, 100);
$page_size_raw = isset($_GET['per_page']) && is_scalar($_GET['per_page']) ? trim((string)$_GET['per_page']) : '';
$per_page = ctype_digit($page_size_raw) ? (int)$page_size_raw : 50;
if (!in_array($per_page, $page_sizes, true)) $per_page = 50;

// Preserve the controller's equipment-type/registration order for the default.
$original_order = array();
foreach ($rows as $index => $row) $original_order[(string)$row['registration_no']] = $index;
$sorted_rows = $rows;
if ($sort_by !== 'fleet') {
    usort($sorted_rows, function ($a, $b) use ($sort_by, $sort_dir, $original_order) {
        $av = (float)$a[$sort_by];
        $bv = (float)$b[$sort_by];
        $cmp = $av <=> $bv;
        if ($sort_dir === 'desc') $cmp *= -1;
        if ($cmp !== 0) return $cmp;
        $ak = isset($original_order[(string)$a['registration_no']]) ? $original_order[(string)$a['registration_no']] : 0;
        $bk = isset($original_order[(string)$b['registration_no']]) ? $original_order[(string)$b['registration_no']] : 0;
        return $ak <=> $bk;
    });
}

$filtered_rows = array();
foreach ($sorted_rows as $row) {
    $row_source = $row['source'] === 'derived' ? 'derived' : 'flat';
    if ($source_filter !== 'all' && $row_source !== $source_filter) continue;
    if ($type_filter !== '' && (string)$row['type'] !== $type_filter) continue;
    $searchable = $search_fold(implode(' ', array(
        $row['registration_no'], $row['type'], $row['note'],
        $row_source === 'derived' ? 'derived corrective history' : 'flat fallback default',
    )));
    if ($query_folded !== '' && strpos($searchable, $query_folded) === false) continue;
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
$filters_active = $query !== '' || $type_filter !== '' || $source_filter !== 'all';

$page_url = function ($page_number) use ($base_label, $query, $type_filter, $source_filter, $sort_by, $sort_dir, $per_page) {
    $params = array(
        'week' => $base_label,
        'page' => (int)$page_number,
        'per_page' => $per_page,
        'sort' => $sort_by,
        'dir' => $sort_dir,
    );
    if ($query !== '') $params['q'] = $query;
    if ($type_filter !== '') $params['type'] = $type_filter;
    if ($source_filter !== 'all') $params['source'] = $source_filter;
    return base_url('forecast/allowances?' . http_build_query($params));
};
$clear_url = base_url('forecast/allowances?' . http_build_query(array(
    'week' => $base_label, 'per_page' => $per_page,
)));
?>

<style>
  .allowance-kpis { grid-template-columns:repeat(3,minmax(0,1fr)); }
  .allowance-sr-only { position:absolute!important; width:1px!important; height:1px!important; padding:0!important; margin:-1px!important; overflow:hidden!important; clip:rect(0,0,0,0)!important; white-space:nowrap!important; border:0!important; }
  .allowance-toolbar { padding:12px 14px; display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
  .allowance-toolbar form { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:0; }
  .allowance-search { width:220px; }
  .allowance-type-filter { width:180px; }
  .allowance-source-filter { width:160px; }
  .allowance-sort-filter { width:170px; }
  .allowance-type-grid { display:flex; flex-wrap:wrap; border-top:1px solid var(--g2); }
  .allowance-type-card { flex:1; min-width:145px; padding:12px 16px; border-right:1px solid var(--g2); }
  .allowance-table-wrap { overflow-x:auto; overscroll-behavior-x:contain; }
  .allowance-table { min-width:1060px; table-layout:auto; }
  .allowance-table th,.allowance-table td { white-space:nowrap; }
  .allowance-table thead th { vertical-align:bottom; }
  .allowance-table th:first-child,.allowance-table td:first-child { position:sticky; left:0; z-index:2; background:var(--surface); box-shadow:2px 0 6px -3px rgba(0,0,0,.3); }
  .allowance-table thead th:first-child { z-index:4; }
  .allowance-table .allowance-note { min-width:280px; max-width:460px; white-space:normal; line-height:1.5; }
  .allowance-table .allowance-rate { text-align:right; font-variant-numeric:tabular-nums; }
  .allowance-toolbar-note,.allowance-method { color:var(--t3); font-size:11px; line-height:1.7; }
  .allowance-empty { padding:32px 18px; text-align:center; color:var(--t2); }
  .allowance-footer {
    padding:11px 16px; border-top:1px solid var(--sep); background:var(--surface-2);
    border-radius:0 0 var(--r-lg) var(--r-lg); display:flex; align-items:center;
    justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:12.5px; color:var(--t2);
  }
  .allowance-pages { display:inline-flex; align-items:center; gap:4px; }
  .allowance-page-link {
    display:inline-flex; align-items:center; justify-content:center; min-width:30px; height:28px; padding:0 8px;
    border-radius:6px; border:1px solid var(--sep-opaque); background:var(--surface); color:var(--t1);
    font-size:12px; font-weight:500; text-decoration:none; font-variant-numeric:tabular-nums;
  }
  .allowance-page-link:hover { background:var(--fill); }
  .allowance-page-link.is-current { background:var(--blue); border-color:var(--blue); color:#fff; font-weight:600; }
  .allowance-page-link.is-disabled { opacity:.42; pointer-events:none; }
  .allowance-page-size { display:inline-flex; align-items:center; gap:6px; }
  .allowance-page-size label { font-size:12px; color:var(--t3); margin:0; }
  .allowance-page-size select { height:28px; font-size:12px; }
  .allowance-print-note { display:none; }
  .allowance-table-wrap a:focus-visible,.allowance-footer a:focus-visible { outline:2px solid var(--blue); outline-offset:2px; }
  @media(max-width:820px) { .allowance-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media(max-width:480px) { .allowance-kpis { grid-template-columns:1fr; } }
  @media(max-width:700px) {
    .allowance-toolbar form { width:100%; }
    .allowance-search,.allowance-type-filter,.allowance-source-filter,.allowance-sort-filter { width:100%; }
  }
  @media print {
    .allowance-week-tools,.allowance-toolbar,.allowance-footer,.allowance-print-hide { display:none!important; }
    .allowance-print-note { display:block; margin:8px 0; font-size:9pt; color:#333; }
    .allowance-table-wrap { overflow:visible!important; }
    .allowance-table { min-width:0; width:100%; }
    .allowance-table th,.allowance-table td { white-space:normal; padding:4px!important; font-size:7pt!important; }
    .allowance-table th:first-child,.allowance-table td:first-child { position:static; box-shadow:none; }
  }
</style>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">Planned Allowances</h1>
    <div class="cs">Per-day rates for target week <strong><?= $h($iso_label) ?></strong>. <?= $mode === 'derived' ? 'The history window ends before this week.' : 'Flat mode is active; no history-derived rate is applied.' ?></div>
  </div>
  <div class="flex ai-c gap-10 allowance-week-tools" style="flex-wrap:wrap;">
    <form method="get" action="<?= $h(base_url('forecast/allowances')) ?>" class="flex ai-c gap-10">
      <input type="hidden" name="q" value="<?= $h($query) ?>">
      <input type="hidden" name="type" value="<?= $h($type_filter) ?>">
      <input type="hidden" name="source" value="<?= $h($source_filter) ?>">
      <input type="hidden" name="sort" value="<?= $h($sort_by) ?>">
      <input type="hidden" name="dir" value="<?= $h($sort_dir) ?>">
      <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
      <label for="allowanceWeek" style="font-size:11px;color:var(--t3);">Target week</label>
      <input type="week" id="allowanceWeek" name="week" class="form-control" style="width:170px;" value="<?= $h($iso_label) ?>">
      <button class="btn btn-sm btn-primary" type="submit">Open</button>
    </form>
    <?php if (!empty($can_edit_settings)): ?>
    <a class="btn btn-sm btn-ghost" href="<?= $h(base_url('settings')) ?>">Allowance settings</a>
    <?php endif; ?>
  </div>
</div>

<div class="grid g-3 mb-4 allowance-kpis">
  <div class="kpi">
    <span class="kpi-tag">Unplanned rate mode</span>
    <div class="kpi-num" style="font-size:20px;text-transform:capitalize;"><?= $h($mode) ?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">
      <?= $mode === 'derived' ? 'Uses each machine’s corrective-history rate; no-history machines use the flat default.' : 'Uses the configured flat daily rate for all machines.' ?>
    </div>
  </div>
  <div class="kpi">
    <span class="kpi-tag">Rates derived from history</span>
    <div class="kpi-num"><?= $total_scope > 0 ? number_format($derived_count) . ' / ' . number_format($total_scope) : '—' ?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">
      <?= number_format($flat_count) ?> flat or fallback · <?= $mode === 'derived' ? (int)$lookback . '-week history window' : 'flat default in use' ?>
    </div>
  </div>
  <div class="kpi">
    <span class="kpi-tag">Configured type overrides</span>
    <div class="kpi-num"><?= max(0, count($standing) - 1) ?><sup>types</sup></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">
      <?= isset($standing['__default__']) ? 'Default ' . $num($standing['__default__']) . ' h/day; unlisted types use this rate.' : 'No default value is available.' ?>
    </div>
  </div>
</div>

<div class="card mb-4" style="overflow:hidden;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Standing allowance by equipment type</div>
    <div class="cs">Type-specific daily rates are stored in Settings; any unlisted type uses the default.</div>
  </div>
  <?php if (count($standing) > 1): ?>
  <div class="allowance-type-grid">
    <?php foreach ($standing as $type => $hours): if ($type === '__default__') continue; ?>
    <div class="allowance-type-card">
      <div style="font-size:11px;color:var(--t3);margin-bottom:4px;"><?= $h($type) ?></div>
      <div style="font-size:20px;font-weight:800;line-height:1;">
        <?= $num($hours) ?><span style="font-size:11px;font-weight:500;color:var(--t3);"> h/day</span>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (isset($standing['__default__'])): ?>
    <div class="allowance-type-card" style="border-right:none;">
      <div style="font-size:11px;color:var(--t3);margin-bottom:4px;"><em>Default</em></div>
      <div style="font-size:20px;font-weight:800;line-height:1;">
        <?= $num($standing['__default__']) ?><span style="font-size:11px;font-weight:500;color:var(--t3);"> h/day</span>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="cs" style="padding:14px 18px;border-top:1px solid var(--g2);">No type-specific overrides; all equipment types use the default.</div>
  <?php endif; ?>
</div>

<div class="allowance-toolbar card mb-3">
  <form method="get" action="<?= $h(base_url('forecast/allowances')) ?>" role="search" aria-label="Filter per-machine allowances">
    <input type="hidden" name="week" value="<?= $h($iso_label) ?>">
    <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
    <label class="allowance-sr-only" for="allowanceSearch">Search registration, type or derivation</label>
    <input id="allowanceSearch" class="form-control allowance-search" type="search" name="q" value="<?= $h($query) ?>" placeholder="Machine, type or derivation…">
    <label class="allowance-sr-only" for="allowanceType">Filter equipment type</label>
    <select id="allowanceType" name="type" class="form-select allowance-type-filter">
      <option value="">All equipment types</option>
      <?php foreach ($type_options as $type_name): ?>
      <option value="<?= $h($type_name) ?>" <?= $type_filter === $type_name ? 'selected' : '' ?>><?= $h($type_name) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="allowance-sr-only" for="allowanceSource">Filter rate source</label>
    <select id="allowanceSource" name="source" class="form-select allowance-source-filter">
      <option value="all" <?= $source_filter === 'all' ? 'selected' : '' ?>>All rate sources</option>
      <option value="derived" <?= $source_filter === 'derived' ? 'selected' : '' ?>>Derived</option>
      <option value="flat" <?= $source_filter === 'flat' ? 'selected' : '' ?>>Flat / fallback</option>
    </select>
    <label class="allowance-sr-only" for="allowanceSort">Sort allowance rows</label>
    <select id="allowanceSort" name="sort" class="form-select allowance-sort-filter">
      <option value="fleet" <?= $sort_by === 'fleet' ? 'selected' : '' ?>>Default: type / machine</option>
      <option value="unplanned" <?= $sort_by === 'unplanned' ? 'selected' : '' ?>>Unplanned rate</option>
      <option value="standing" <?= $sort_by === 'standing' ? 'selected' : '' ?>>Standing rate</option>
      <option value="events" <?= $sort_by === 'events' ? 'selected' : '' ?>>Corrective work orders</option>
    </select>
    <select id="allowanceSortDirection" name="dir" class="form-select" aria-label="Sort direction" style="width:108px;" <?= $sort_by === 'fleet' ? 'disabled' : '' ?>>
      <option value="desc" <?= $sort_dir === 'desc' ? 'selected' : '' ?>>High to low</option>
      <option value="asc" <?= $sort_dir === 'asc' ? 'selected' : '' ?>>Low to high</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    <a class="btn btn-ghost btn-sm" href="<?= $h($clear_url) ?>">Clear</a>
  </form>
</div>

<div class="allowance-toolbar-note mb-3">
  <?= number_format($total_filtered) ?> matching <?= $total_filtered === 1 ? 'machine' : 'machines' ?><?= $filters_active ? ' across ' . number_format($total_scope) . ' in the full report' : ' in the full report' ?>.
  The counts and standing-type settings above are unaffected by these table filters.
</div>
<div class="allowance-print-note">Printed rows contain only this page. The rates are per eligible day; see the generated weekly plan for actual entries.</div>

<?php if ($total_scope === 0): ?>
<div class="card allowance-empty" role="status">
  <strong>No reportable machines are available for this target week.</strong>
</div>
<?php elseif ($total_filtered === 0): ?>
<div class="card allowance-empty" role="status">
  <strong>No machines match these filters.</strong><br>
  Try another search/filter or <a href="<?= $h($clear_url) ?>">clear filters</a>.
</div>
<?php else: ?>
<div class="card" style="overflow:hidden;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Per-machine allowance preview</div>
    <div class="cs">Daily rate applied by the generator on eligible days—not a promise that every day receives an entry.</div>
  </div>
  <div class="allowance-table-wrap">
    <table class="tbl allowance-table">
      <caption class="allowance-sr-only">Per-machine daily allowance rates for target week <?= $h($iso_label) ?>. Corrective work-order counts are shown only in derived mode and use its displayed history window.</caption>
      <thead>
        <tr>
          <th scope="col">MACHINE</th>
          <th scope="col">TYPE</th>
          <th scope="col" style="text-align:right;">STANDING h/day</th>
          <th scope="col" style="text-align:right;">UNPLANNED h/day</th>
          <th scope="col" style="text-align:center;">RATE SOURCE</th>
          <th scope="col" style="text-align:center;">CORRECTIVE WOs<?= $mode === 'derived' ? ' IN WINDOW' : ' (N/A IN FLAT MODE)' ?></th>
          <th scope="col">DERIVATION</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($page_rows as $row): ?>
        <tr>
          <td class="mono" style="font-weight:600;">
            <a href="<?= $h(base_url('equipment/view/' . (int)$row['equipment_id'])) ?>" title="Open equipment profile">
              <?= $h($row['registration_no']) ?>
            </a>
          </td>
          <td style="color:var(--t3);font-size:10.5px;"><?= $h($row['type'] !== null && $row['type'] !== '' ? $row['type'] : 'Unspecified type') ?></td>
          <td class="mono allowance-rate" style="color:var(--t2);"> <?= $num($row['standing']) ?> </td>
          <td class="mono allowance-rate" style="font-weight:600;color:<?= $row['source'] === 'derived' ? 'var(--t1)' : 'var(--t3)' ?>;">
            <?= $num($row['unplanned']) ?>
          </td>
          <td style="text-align:center;">
            <?php if ($row['source'] === 'derived'): ?>
              <span class="badge b-info" style="font-size:9.5px;">DERIVED</span>
            <?php elseif ($mode === 'derived'): ?>
              <span class="badge b-secondary" style="font-size:9.5px;">FLAT FALLBACK</span>
            <?php else: ?>
              <span class="badge b-secondary" style="font-size:9.5px;">FLAT MODE</span>
            <?php endif; ?>
          </td>
          <td class="mono" style="text-align:center;color:var(--t3);">
            <?= $mode === 'derived' && (int)$row['events'] > 0 ? number_format((int)$row['events']) : '—' ?>
          </td>
          <td class="allowance-note" style="font-size:10px;color:var(--t3);">
            <?= $h($row['note']) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="allowance-footer allowance-print-hide">
    <div aria-live="polite">
      Showing <strong class="mono"><?= number_format($showing_from) ?>–<?= number_format($showing_to) ?></strong>
      of <strong class="mono"><?= number_format($total_filtered) ?></strong> machines
      <?php if ($total_filtered < $total_scope): ?><span>(filtered from <?= number_format($total_scope) ?>)</span><?php endif; ?>
    </div>
    <?php if ($total_pages > 1): ?>
    <nav class="allowance-pages" aria-label="Allowance pagination">
      <a class="allowance-page-link <?= $page <= 1 ? 'is-disabled' : '' ?>"
         href="<?= $h($page_url(max(1, $page - 1))) ?>"
         <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : 'rel="prev"' ?>>Prev</a>
      <?php
      $window_start = max(1, $page - 2);
      $window_end = min($total_pages, $page + 2);
      if ($window_start > 1): ?>
        <a class="allowance-page-link" href="<?= $h($page_url(1)) ?>">1</a>
        <?php if ($window_start > 2): ?><span style="padding:0 4px;color:var(--t3);">&hellip;</span><?php endif; ?>
      <?php endif;
      for ($p = $window_start; $p <= $window_end; $p++): ?>
        <a class="allowance-page-link <?= $p === $page ? 'is-current' : '' ?>"
           <?= $p === $page ? 'aria-current="page"' : '' ?>
           href="<?= $h($page_url($p)) ?>"><?= $p ?></a>
      <?php endfor;
      if ($window_end < $total_pages): ?>
        <?php if ($window_end < $total_pages - 1): ?><span style="padding:0 4px;color:var(--t3);">&hellip;</span><?php endif; ?>
        <a class="allowance-page-link" href="<?= $h($page_url($total_pages)) ?>"><?= $total_pages ?></a>
      <?php endif; ?>
      <a class="allowance-page-link <?= $page >= $total_pages ? 'is-disabled' : '' ?>"
         href="<?= $h($page_url(min($total_pages, $page + 1))) ?>"
         <?= $page >= $total_pages ? 'aria-disabled="true" tabindex="-1"' : 'rel="next"' ?>>Next</a>
    </nav>
    <?php endif; ?>

    <form method="get" action="<?= $h(base_url('forecast/allowances')) ?>" class="allowance-page-size">
      <input type="hidden" name="week" value="<?= $h($iso_label) ?>">
      <input type="hidden" name="q" value="<?= $h($query) ?>">
      <input type="hidden" name="type" value="<?= $h($type_filter) ?>">
      <input type="hidden" name="source" value="<?= $h($source_filter) ?>">
      <input type="hidden" name="sort" value="<?= $h($sort_by) ?>">
      <input type="hidden" name="dir" value="<?= $h($sort_dir) ?>">
      <input type="hidden" name="page" value="1">
      <label for="allowancePageSize">Rows:</label>
      <select id="allowancePageSize" name="per_page" onchange="this.form.submit()">
        <?php foreach ($page_sizes as $size): ?>
        <option value="<?= (int)$size ?>" <?= $per_page === $size ? 'selected' : '' ?>><?= (int)$size ?> / page</option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn btn-sm">Apply</button></noscript>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="allowance-method mt-4">
  <strong>How the rates are calculated.</strong>
  In derived mode, recorded downtime from Corrective Maintenance work orders starting in the displayed history window is divided by the window’s calendar days, rounded to two decimals, then bounded to 0.5–24 h/day. If no corrective history exists, the unplanned flat default is used.
  Standing rates use the matching equipment-type override or the displayed default.
  Both generators skip held days and preserve protected manual entries; standing generation also skips standby machines. This page shows daily rates, not the final ledger rows.
  <?php if ($mode === 'derived'): ?>
  <br><strong>History window:</strong> <?= date('d M Y', strtotime($lookback_from)) ?>–<?= date('d M Y', strtotime($lookback_to)) ?>
  (<?= (int)$lookback ?> complete weeks, ending the day before <?= $h($iso_label) ?>).
  <?php endif; ?>
  <a href="<?= $h(base_url('forecast?week=' . urlencode($iso_label))) ?>">Review the weekly plan</a>.
</div>
