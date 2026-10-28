<?php
$today = date('Y-m-d');
$displayDate = isset($date) ? $date : $today;
$machines      = isset($machines) ? $machines : array();
$tab_counts    = isset($tab_counts) ? $tab_counts : array('all' => 0, 'down' => 0, 'available' => 0);
$type_counts   = isset($type_counts) ? $type_counts : array();
$active_tab    = isset($active_tab) ? $active_tab : 'all';
$search_query  = isset($search_query) ? $search_query : '';
$type_filter   = isset($type_filter) ? $type_filter : '';
$sort_by       = isset($sort_by) ? $sort_by : 'machine';
$sort_dir      = isset($sort_dir) ? $sort_dir : 'asc';
$per_page      = isset($per_page) ? (int)$per_page : 25;
$page          = isset($page) ? (int)$page : 1;
$total_pages   = isset($total_pages) ? (int)$total_pages : 1;
$total_filtered = isset($total_filtered) ? (int)$total_filtered : count($machines);
$showing_from  = isset($showing_from) ? (int)$showing_from : ($total_filtered ? 1 : 0);
$showing_to    = isset($showing_to) ? (int)$showing_to : $total_filtered;
$view_mode     = isset($view_mode) ? $view_mode : 'table';
$is_cards      = ($view_mode === 'cards');
$wdh = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$base_params = array_filter(array(
    'date' => $displayDate,
    'q'    => $search_query,
    'type' => $type_filter,
    'tab'  => ($active_tab !== 'all') ? $active_tab : null,
    'sort' => ($sort_by !== 'machine') ? $sort_by : null,
    'dir'  => ($sort_dir !== 'asc') ? $sort_dir : null,
    'view' => $is_cards ? 'cards' : null,
));
$mk_url = function ($o = array()) use ($base_params) {
    return site_url('reports/workshop_daily') . '?' . http_build_query(array_merge($base_params, $o));
};
$sort_url = function ($key) use ($mk_url, $sort_by, $sort_dir) {
    if ($sort_by === $key) return $mk_url(array('sort' => $key, 'dir' => ($sort_dir === 'asc' ? 'desc' : 'asc'), 'page' => null));
    return $mk_url(array('sort' => $key, 'dir' => 'asc', 'page' => null));
};
$aria_sort = function ($key) use ($sort_by, $sort_dir) {
    return $sort_by === $key ? ' aria-sort="' . ($sort_dir === 'asc' ? 'ascending' : 'descending') . '"' : '';
};
$sort_mark = function ($key) use ($sort_by, $sort_dir) {
    return $sort_by === $key ? ($sort_dir === 'asc' ? ' ↑' : ' ↓') : '';
};
$tab_defs = array(
    'all'       => array('label' => 'All Machines', 'count' => (int)$tab_counts['all']),
    'down'      => array('label' => 'Down',         'count' => (int)$tab_counts['down']),
    'available' => array('label' => 'Available',    'count' => (int)$tab_counts['available']),
);
$has_filters = ($search_query !== '' || $type_filter !== '');
$avail_color = function ($a) { return $a >= 0.9 ? 'var(--green)' : ($a > 0 ? 'var(--amber)' : 'var(--red)'); };

// Build per-type chart data for JS
$chartData = [];
foreach ($report as $type => $g) {
    $labels = [];
    $values = [];
    foreach ($g['machines'] as $m) {
        $labels[] = $m['machine_id'];
        $values[] = round($m['availability'] * 100);
    }
    $chartData[] = [
        'type'      => $g['type'],
        'kpi'       => round($g['daily_kpi'] * 100),
        'available' => $g['available'],
        'down'      => $g['down'],
        'total'     => $g['total'],
        'labels'    => $labels,
        'values'    => $values,
    ];
}
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">Workshop Daily Report</h1>
    <div class="cs"><?= date('l, d F Y', strtotime($displayDate)) ?></div>
  </div>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <form method="get" class="flex ai-c gap-10">
      <input type="date" name="date" class="form-control" style="width:160px;" value="<?= $displayDate ?>" max="<?= $today ?>">
      <button type="submit" class="btn btn-primary btn-sm">Load</button>
    </form>
    <a href="<?= base_url('reports/export_workshop_daily_xlsx?date='.$displayDate) ?>"
       class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Excel
    </a>
    <a href="<?= base_url('reports/export_workshop_daily_pdf?date='.$displayDate) ?>"
       class="btn btn-ghost btn-icon" style="border:1px solid var(--g3);">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      PDF
    </a>
    <button onclick="window.print()" class="btn btn-ghost btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print
    </button>
  </div>
</div>

<!-- ── Summary KPIs ── -->
<?php
$totalEq = 0; $totalAvail = 0; $totalDown = 0;
foreach ($report as $g) {
    $totalEq    += $g['total'];
    $totalAvail += $g['available'];
    $totalDown  += $g['down'];
}
$overallAvail = $totalEq > 0 ? round($totalAvail / $totalEq, 2) : 0;
?>
<div class="grid g-4 mb-4">
  <div class="kpi" style="animation-delay:.04s">
    <div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total Fleet</span>
    <div class="kpi-num"><?= $totalEq ?><sup>units</sup></div>
  </div>
  <div class="kpi" style="animation-delay:.08s">
    <div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Available</span>
    <div class="kpi-num"><?= $totalAvail ?></div>
    <div class="kpi-chip chip-up">↑ <?= $totalEq>0?round($totalAvail/$totalEq*100).'%':'-' ?></div>
  </div>
  <div class="kpi" style="animation-delay:.12s">
    <div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Down</span>
    <div class="kpi-num"><?= $totalDown ?></div>
    <?php if ($totalDown > 0): ?>
    <div class="kpi-chip chip-dn"><?= $totalEq>0?round($totalDown/$totalEq*100).'%':'-' ?></div>
    <?php endif; ?>
  </div>
  <div class="kpi" style="animation-delay:.16s">
    <div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Overall Availability</span>
    <div class="kpi-num"><?= round($overallAvail*100) ?><sup>%</sup></div>
    <div class="kpi-chip <?= $overallAvail>=0.8?'chip-up':($overallAvail>=0.6?'chip-warn':'chip-dn') ?>">
      <?= $overallAvail>=0.8?'Good':($overallAvail>=0.6?'Fair':'Critical') ?>
    </div>
  </div>
</div>

<!-- ── Fleet type summary strip (matches PDF header table) ── -->
<?php if (!empty($report)): ?>
<div class="card mb-4" style="padding:0;overflow:hidden;">
  <div class="ch" style="padding:12px 20px 10px;">
    <div class="ct" style="font-size:13px;">Fleet Summary by Type</div>
    <div class="cs"><?= date('d/m/Y', strtotime($displayDate)) ?></div>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:0;border-top:1px solid var(--g2);">
    <?php foreach ($chartData as $i => $ct):
      $kpi   = $ct['kpi'];
      $color = $kpi >= 90 ? 'var(--green)' : ($kpi >= 70 ? 'var(--amber)' : 'var(--red)');
    ?>
    <div style="flex:1;min-width:130px;padding:14px 16px;border-right:1px solid var(--g2);<?= $i===count($chartData)-1?'border-right:none':'' ?>">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);margin-bottom:4px;"><?= htmlspecialchars((string)($ct['type'])) ?>s</div>
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;">
        <div style="font-size:22px;font-weight:800;color:<?= $color ?>;line-height:1;"><?= $kpi ?>%</div>
        <div style="font-size:10px;color:var(--t3);line-height:1.4;">
          <div><span style="color:var(--green);font-weight:600;"><?= $ct['available'] ?></span> avail</div>
          <div><span style="color:var(--red);font-weight:600;"><?= $ct['down'] ?></span> down / <?= $ct['total'] ?></div>
        </div>
      </div>
      <div style="height:4px;background:var(--g2);border-radius:3px;overflow:hidden;">
        <div style="height:100%;width:<?= $kpi ?>%;background:<?= $color ?>;border-radius:3px;"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Main detail table ── -->
<style>
.wd-tabs{display:flex;gap:2px;flex-wrap:wrap;border-bottom:1px solid var(--sep)}
.wd-tab{padding:8px 12px;font-size:12.5px;font-weight:500;color:var(--t2);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}
.wd-tab:hover{color:var(--t1)}
.wd-tab[aria-selected="true"]{color:var(--blue);border-bottom-color:var(--blue);font-weight:600}
.wd-tab .cnt{font-size:11px;background:var(--gray-fill);border-radius:999px;padding:0 6px;margin-left:4px}
.wd-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:12px 0 4px}
.wd-chips{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:6px 0 2px}
.wd-chip{font-size:11.5px;background:var(--blue-fill);color:var(--blue);border-radius:999px;padding:2px 6px 2px 10px;display:inline-flex;gap:4px;align-items:center}
.wd-chip a{color:inherit;text-decoration:none;font-weight:700;padding:0 4px}
.wd-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;padding:14px 0 4px}
.wd-card{border:1px solid var(--sep);border-radius:12px;padding:14px;background:var(--surface)}
.wd-pager{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;padding:12px 0 4px}
.wd-pg{display:inline-flex;gap:4px;align-items:center}
.wd-pg a,.wd-pg span.cur{font-size:12px;padding:4px 9px;border:1px solid var(--sep);border-radius:8px;color:var(--t2);text-decoration:none}
.wd-pg span.cur{background:var(--blue);color:#fff;border-color:var(--blue);font-weight:600}
.wd-pg span.dis{font-size:12px;padding:4px 9px;color:var(--t4)}
.sortable-th a{color:inherit;text-decoration:none}
.sortable-th a:hover{color:var(--blue)}
.wd-empty{padding:44px 20px;text-align:center;color:var(--t3)}
</style>

<div class="card mb-4" style="padding:0 16px 12px;">
  <div class="wd-tabs" role="tablist" aria-label="Machine status" style="margin-top:6px;">
    <?php foreach ($tab_defs as $key => $def): ?>
    <a role="tab" aria-selected="<?= $active_tab === $key ? 'true' : 'false' ?>" class="wd-tab"
       href="<?= $mk_url(array('tab' => ($key === 'all' ? null : $key), 'page' => null)) ?>">
      <?= $def['label'] ?><span class="cnt"><?= (int)$def['count'] ?></span>
    </a>
    <?php endforeach; ?>
    <span style="flex:1;"></span>
    <span style="font-size:11px;color:var(--t3);align-self:center;">Excel / PDF above always export the full day</span>
  </div>

  <form method="get" action="<?= site_url('reports/workshop_daily') ?>" class="wd-toolbar no-print" role="search">
    <input type="hidden" name="date" value="<?= $wdh($displayDate) ?>">
    <?php if ($active_tab !== 'all'): ?><input type="hidden" name="tab" value="<?= $active_tab ?>"><?php endif; ?>
    <?php if ($sort_by !== 'machine'): ?><input type="hidden" name="sort" value="<?= $sort_by ?>"><?php endif; ?>
    <?php if ($sort_dir !== 'asc'): ?><input type="hidden" name="dir" value="<?= $sort_dir ?>"><?php endif; ?>
    <?php if ($is_cards): ?><input type="hidden" name="view" value="cards"><?php endif; ?>
    <div style="position:relative;">
      <label class="sr-only" for="wdSearch">Search machines</label>
      <input type="search" id="wdSearch" name="q" class="form-control" style="width:250px;padding-left:30px;"
             placeholder="Search machine, model, location…  ( / )" value="<?= $wdh($search_query) ?>">
      <span aria-hidden="true" style="position:absolute;left:9px;top:7px;color:var(--t3);">&#128269;</span>
    </div>
    <label class="sr-only" for="wdTypeFilter">Filter by equipment type</label>
    <select id="wdTypeFilter" name="type" class="form-select wd-autosubmit" style="width:auto;">
      <option value="">All types</option>
      <?php foreach ($type_counts as $tn => $tc): ?>
      <option value="<?= $wdh($tn) ?>" <?= $type_filter === $tn ? 'selected' : '' ?>><?= $wdh($tn) ?> (<?= (int)$tc ?>)</option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    <?php if ($has_filters || $active_tab !== 'all'): ?>
    <a href="<?= site_url('reports/workshop_daily?date=' . rawurlencode($displayDate)) ?>" class="btn btn-ghost btn-sm">Clear</a>
    <?php endif; ?>
    <span style="flex:1;"></span>
    <div class="btn-group no-print" role="group" aria-label="View mode">
      <a href="<?= $mk_url(array('view' => null)) ?>" class="btn btn-ghost btn-sm" aria-pressed="<?= $is_cards ? 'false' : 'true' ?>">Table</a>
      <a href="<?= $mk_url(array('view' => 'cards')) ?>" class="btn btn-ghost btn-sm" aria-pressed="<?= $is_cards ? 'true' : 'false' ?>">Cards</a>
    </div>
  </form>

  <?php if ($has_filters): ?>
  <div class="wd-chips" aria-label="Active filters">
    <span style="font-size:11.5px;color:var(--t3);">Active filters:</span>
    <?php if ($search_query !== ''): ?><span class="wd-chip">Search: <?= $wdh($search_query) ?><a href="<?= $mk_url(array('q' => null, 'page' => null)) ?>" aria-label="Clear search">×</a></span><?php endif; ?>
    <?php if ($type_filter !== ''): ?><span class="wd-chip">Type: <?= $wdh($type_filter) ?><a href="<?= $mk_url(array('type' => null, 'page' => null)) ?>" aria-label="Clear type filter">×</a></span><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (empty($machines)): ?>
    <div class="wd-empty">
      <?php if (empty($report)): ?>
        <div style="font-size:15px;font-weight:600;color:var(--t1);">No equipment data found</div>
        <div style="font-size:12.5px;margin-top:4px;">No active fleet is registered for this report.</div>
      <?php elseif ($active_tab === 'down'): ?>
        <div style="font-size:15px;font-weight:600;color:var(--t1);">Nothing is down.</div>
        <div style="font-size:12.5px;margin-top:4px;">Every machine matching the current filters is available on <?= date('d M Y', strtotime($displayDate)) ?>.</div>
      <?php else: ?>
        <div style="font-size:15px;font-weight:600;color:var(--t1);">No machines match the current filters.</div>
        <div style="font-size:12.5px;margin-top:4px;">Try a different search term or <a href="<?= site_url('reports/workshop_daily?date=' . rawurlencode($displayDate)) ?>">clear the filters</a>.</div>
      <?php endif; ?>
    </div>
  <?php elseif ($is_cards): ?>
    <div class="wd-cards">
      <?php foreach ($machines as $m): ?>
      <div class="wd-card">
        <div class="flex ai-c jb" style="gap:8px;">
          <div>
            <a href="<?= site_url('equipment/view/' . (int)$m['equipment_id']) ?>" class="mono" style="font-weight:700;font-size:13.5px;color:var(--blue);text-decoration:none;"><?= $wdh($m['machine_id']) ?></a>
            <div style="font-size:11px;color:var(--t3);"><?= $wdh($m['type']) ?> · <?= $wdh($m['model']) ?> · <?= $wdh($m['location']) ?></div>
          </div>
          <?php if ($m['status'] === 'Down'): ?><span class="badge b-danger">Down</span><?php else: ?><span class="badge b-success">Available</span><?php endif; ?>
        </div>
        <div style="display:flex;gap:14px;margin-top:10px;font-size:12px;">
          <div><div style="color:var(--t3);font-size:10px;text-transform:uppercase;">Avail</div><strong style="color:<?= $avail_color($m['availability']) ?>;"><?= round($m['availability'] * 100) ?>%</strong></div>
          <div><div style="color:var(--t3);font-size:10px;text-transform:uppercase;">Downtime</div><strong><?= $m['total_dt'] > 0 ? number_format((float)$m['total_dt'], 1) . ' h' : '—' ?></strong></div>
          <div><div style="color:var(--t3);font-size:10px;text-transform:uppercase;">Days down</div><strong style="<?= $m['days_down'] > 7 ? 'color:var(--red)' : '' ?>"><?= $m['days_down'] > 0 ? (int)$m['days_down'] : '—' ?></strong></div>
          <div><div style="color:var(--t3);font-size:10px;text-transform:uppercase;">E/T</div><strong><?= $wdh($m['est_time'] ?: '—') ?></strong></div>
        </div>
        <?php if (!empty($m['observation'])): ?><div style="font-size:11.5px;color:var(--t2);margin-top:8px;white-space:pre-wrap;"><?= $wdh($m['observation']) ?></div><?php endif; ?>
        <?php if (!empty($m['action_taken'])): ?><div style="font-size:11px;color:var(--t3);margin-top:4px;white-space:pre-wrap;"><strong>Ongoing:</strong> <?= $wdh($m['action_taken']) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="tbl" style="min-width:1100px;font-size:11.5px;">
    <thead>
      <tr>
        <th scope="col" class="sortable-th"<?= $aria_sort('type') ?>><a href="<?= $sort_url('type') ?>">Type<?= $sort_mark('type') ?></a></th>
        <th scope="col" class="sortable-th"<?= $aria_sort('machine') ?>><a href="<?= $sort_url('machine') ?>">Machine ID<?= $sort_mark('machine') ?></a></th>
        <th scope="col">Model</th>
        <th scope="col">Location</th>
        <th scope="col">Observation</th>
        <th scope="col">Ongoing Action</th>
        <th scope="col" style="text-align:center;">E/T</th>
        <th scope="col" class="sortable-th" style="text-align:center;"<?= $aria_sort('status') ?>><a href="<?= $sort_url('status') ?>">Status<?= $sort_mark('status') ?></a></th>
        <th scope="col" style="text-align:center;">Down Time</th>
        <th scope="col" style="text-align:center;">Up Time</th>
        <th scope="col" class="sortable-th" style="text-align:right;"<?= $aria_sort('total_dt') ?>><a href="<?= $sort_url('total_dt') ?>">Total DT<?= $sort_mark('total_dt') ?></a></th>
        <th scope="col" style="text-align:center;">Start Date</th>
        <th scope="col" class="sortable-th" style="text-align:center;"<?= $aria_sort('days_down') ?>><a href="<?= $sort_url('days_down') ?>">Days ↓<?= $sort_mark('days_down') ?></a></th>
        <th scope="col" class="sortable-th" style="text-align:right;"<?= $aria_sort('availability') ?>><a href="<?= $sort_url('availability') ?>">Avail %<?= $sort_mark('availability') ?></a></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($machines as $m): ?>
      <tr>
        <td style="font-weight:700;font-size:11px;color:var(--blue);"><?= $wdh($m['type']) ?></td>
        <td class="mono" style="font-weight:600;font-size:11px;">
          <a href="<?= site_url('equipment/view/' . (int)$m['equipment_id']) ?>" style="color:var(--blue);text-decoration:none;" aria-label="Open equipment profile for <?= $wdh($m['machine_id']) ?>"><?= $wdh($m['machine_id']) ?></a>
        </td>
        <td style="color:var(--t2);"><?= $wdh($m['model']) ?></td>
        <td style="color:var(--t3);font-size:11px;"><?= $wdh($m['location']) ?></td>
        <td style="max-width:200px;white-space:pre-wrap;word-break:break-word;color:var(--t2);"><?= $wdh($m['observation']) ?></td>
        <td style="max-width:130px;white-space:pre-wrap;word-break:break-word;color:var(--t2);font-size:11px;"><?= $wdh($m['action_taken']) ?></td>
        <td style="text-align:center;font-size:11px;color:var(--t3);"><?= $wdh($m['est_time']) ?></td>
        <td style="text-align:center;">
          <?php if ($m['status'] === 'Down'): ?><span class="badge b-danger">Down</span><?php else: ?><span class="badge b-success">Available</span><?php endif; ?>
        </td>
        <td class="mono" style="text-align:center;font-size:11px;color:var(--t3);"><?= $wdh($m['down_time']) ?></td>
        <td class="mono" style="text-align:center;font-size:11px;color:var(--t3);"><?= $wdh($m['up_time']) ?></td>
        <td class="mono" style="text-align:right;font-weight:600;<?= $m['total_dt'] > 0 ? 'color:var(--amber)' : '' ?>"><?= $m['total_dt'] > 0 ? number_format((float)$m['total_dt'], 1) : '—' ?></td>
        <td class="mono" style="text-align:center;font-size:11px;color:var(--t3);"><?= $m['start_date'] ? date('d/m/Y', strtotime($m['start_date'])) : '—' ?></td>
        <td style="text-align:center;font-weight:600;<?= $m['days_down'] > 7 ? 'color:var(--red)' : ($m['days_down'] > 0 ? 'color:var(--amber)' : '') ?>"><?= $m['days_down'] > 0 ? (int)$m['days_down'] : '—' ?></td>
        <td style="text-align:right;font-weight:600;color:<?= $avail_color($m['availability']) ?>;"><?= round($m['availability'] * 100) ?>%</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>

  <?php if ($total_filtered > 0): ?>
  <div class="wd-pager no-print">
    <form method="get" action="<?= site_url('reports/workshop_daily') ?>" class="flex ai-c gap-10" style="font-size:12px;color:var(--t2);">
      <?php foreach ($base_params as $k => $v): ?><input type="hidden" name="<?= $wdh($k) ?>" value="<?= $wdh((string)$v) ?>"><?php endforeach; ?>
      <label for="wdPerPage">Show</label>
      <select id="wdPerPage" name="per_page" class="form-select wd-autosubmit" style="width:auto;font-size:12px;">
        <?php foreach (array(10, 25, 50, 100) as $pp): ?><option value="<?= $pp ?>" <?= (int)$per_page === $pp ? 'selected' : '' ?>><?= $pp ?></option><?php endforeach; ?>
      </select>
      <span>Showing <?= (int)$showing_from ?>–<?= (int)$showing_to ?> of <?= (int)$total_filtered ?> machines</span>
    </form>
    <nav class="wd-pg" aria-label="Machine pages">
      <?php if ($page > 1): ?><a href="<?= $mk_url(array('page' => $page - 1, 'per_page' => (int)$per_page)) ?>">‹ Prev</a><?php else: ?><span class="dis">‹ Prev</span><?php endif; ?>
      <?php
        $win = array();
        for ($i = 1; $i <= (int)$total_pages; $i++) { if ($i === 1 || $i === (int)$total_pages || abs($i - $page) <= 1) $win[] = $i; }
        $win = array_values(array_unique($win)); $prev_w = 0;
        foreach ($win as $i): if ($prev_w && $i > $prev_w + 1): ?><span class="dis">…</span><?php endif; $prev_w = $i; ?>
        <?php if ($i === $page): ?><span class="cur" aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= $mk_url(array('page' => $i, 'per_page' => (int)$per_page)) ?>"><?= $i ?></a><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($page < (int)$total_pages): ?><a href="<?= $mk_url(array('page' => $page + 1, 'per_page' => (int)$per_page)) ?>">Next ›</a><?php else: ?><span class="dis">Next ›</span><?php endif; ?>
    </nav>
  </div>
  <?php endif; ?>
</div>

<!-- ── Per-type KPI bar charts (matches PDF layout) ── -->
<?php if (!empty($chartData)): ?>
<div class="grid g-<?= min(count($chartData), 3) ?> mb-3" style="gap:16px;">
  <?php foreach ($chartData as $ct): ?>
  <div class="card" style="padding:0;">
    <div class="ch" style="padding:12px 16px 8px;">
      <div>
        <div class="ct" style="font-size:12px;">Daily <?= htmlspecialchars((string)($ct['type'])) ?>s KPI</div>
        <div class="cs">Availability %</div>
      </div>
      <div style="font-size:18px;font-weight:800;color:<?= $ct['kpi']>=90?'var(--green)':($ct['kpi']>=70?'var(--amber)':'var(--red)') ?>;"><?= $ct['kpi'] ?>%</div>
    </div>
    <div style="padding:8px 12px 14px;">
      <canvas id="chart_<?= preg_replace('/\W/','',$ct['type']) ?>" style="max-height:140px;"></canvas>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
(function(){
  var charts = <?= json_encode($chartData) ?>;
  charts.forEach(function(ct) {
    var id = 'chart_' + ct.type.replace(/\W/g,'');
    var canvas = document.getElementById(id);
    if (!canvas) return;
    var colors = ct.values.map(function(v){
      return v >= 90 ? 'rgba(28,140,60,0.85)' : (v >= 70 ? 'rgba(180,83,9,0.85)' : 'rgba(192,57,43,0.85)');
    });
    new Chart(canvas, {
      type: 'bar',
      data: {
        labels: ct.labels,
        datasets: [{
          data: ct.values,
          backgroundColor: colors,
          borderRadius: 4,
          borderSkipped: false,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(ctx){ return ctx.parsed.y + '%'; }
            }
          }
        },
        scales: {
          x: {
            ticks: { font:{ size:9 }, color:'#888', maxRotation:45 },
            grid: { display:false }
          },
          y: {
            min: 0, max: 100,
            ticks: {
              font:{ size:9 }, color:'#888',
              callback: function(v){ return v+'%'; },
              stepSize: 25
            },
            grid: { color:'rgba(0,0,0,0.04)' }
          }
        }
      }
    });
  });
})();
</script>
<?php endif; ?>

<style>
@media print {
  #sidebar, #canvas .topbar, .btn, form { display:none!important; }
  #canvas { margin-left:0!important; height:auto!important; overflow:visible!important; }
  .card { box-shadow:none!important; }
  .tbl td, .tbl th { font-size:8pt!important; padding:3px 5px!important; }
}
</style>

<script>
(function () {
  document.querySelectorAll('.wd-autosubmit').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });
  var search = document.getElementById('wdSearch');
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && search && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
      e.preventDefault(); search.focus(); search.select();
    }
  });
  var timer = null;
  if (search) {
    search.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { search.form.submit(); }, 450);
    });
  }
})();
</script>
