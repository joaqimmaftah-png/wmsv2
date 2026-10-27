<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Shift Roster — weekly worker × day matrix (one shift assignment per
 * worker per week, repeated across the 7 day cells), rebuilt as an
 * enterprise table: KPI coverage strip, Assigned/Unassigned tabs, search,
 * shift filter, sorting, pagination, Table/Cards views and CSV export.
 * Assignment and shift-type CRUD post to the existing endpoints
 * (Team::assign_shift / shift_save / shift_delete, supervisor-only).
 *
 * Expects: $rows paged worker rows decorated with roster_shift_id /
 * roster_shift / full_name_roster, $shifts, $shift_by_id, $assignments,
 * $assignment_counts, $week_start, $tab_counts, $per_shift_counts,
 * $active_tab, $search_query, $shift_filter, $sort_by, $sort_dir,
 * $summary, $total_filtered, $per_page, $page, $total_pages,
 * $showing_from, $showing_to, $view_mode, $can_edit, $can_export.
 */
$week_dates = array();
for ($i = 0; $i < 7; $i++) { $week_dates[] = date('Y-m-d', strtotime($week_start . ' +' . $i . ' days')); }
$day_names  = array('Mon','Tue','Wed','Thu','Fri','Sat','Sun');
$can_edit   = !empty($can_edit);
$can_export = !empty($can_export);
$is_cards   = ($view_mode === 'cards');

$shift_colors = array('Morning' => 'b-warning', 'Afternoon' => 'b-primary', 'Night' => 'b-purple');
$shift_color  = function ($name) use ($shift_colors) {
    return isset($shift_colors[$name]) ? $shift_colors[$name] : 'b-secondary';
};
$week_input = date('o-\WW', strtotime($week_start));
$today = date('Y-m-d');

$base_params = array_filter(array(
    'week'  => $week_start,
    'q'     => $search_query,
    'shift' => $shift_filter,
    'tab'   => ($active_tab !== 'all') ? $active_tab : null,
    'sort'  => ($sort_by !== 'worker') ? $sort_by : null,
    'dir'   => ($sort_dir !== 'asc') ? $sort_dir : null,
    'view'  => $is_cards ? 'cards' : null,
));
$mk_url = function ($overrides = array()) use ($base_params) {
    return site_url('team/shift_roster') . '?' . http_build_query(array_merge($base_params, $overrides));
};
$sort_url = function ($key) use ($mk_url, $sort_by, $sort_dir) {
    if ($sort_by === $key) {
        return $mk_url(array('sort' => $key, 'dir' => ($sort_dir === 'asc' ? 'desc' : 'asc'), 'page' => null));
    }
    return $mk_url(array('sort' => $key, 'dir' => 'asc', 'page' => null));
};
$aria_sort = function ($key) use ($sort_by, $sort_dir) {
    if ($sort_by !== $key) return '';
    return ' aria-sort="' . ($sort_dir === 'asc' ? 'ascending' : 'descending') . '"';
};
$sort_mark = function ($key) use ($sort_by, $sort_dir) {
    if ($sort_by !== $key) return '';
    return $sort_dir === 'asc' ? ' ↑' : ' ↓';
};

$tab_defs = array(
    'all'        => array('label' => 'All Staff',  'count' => (int)$tab_counts['all']),
    'assigned'   => array('label' => 'Assigned',   'count' => (int)$tab_counts['assigned']),
    'unassigned' => array('label' => 'Unassigned', 'count' => (int)$tab_counts['unassigned']),
);
$has_filters = ($search_query !== '' || $shift_filter !== '');
$assign_select_id = 0;
?>
<style>
.sr-kpis{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:10px;margin-bottom:14px}
.sr-kpi{background:var(--surface);border:1px solid var(--sep);border-radius:var(--r-md);padding:12px 14px}
.sr-kpi .k-l{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--t3)}
.sr-kpi .k-v{font-size:20px;font-weight:700;color:var(--t1);margin-top:2px}
.sr-kpi .k-m{font-size:11px;color:var(--t3);margin-top:1px}
.sr-tabs{display:flex;gap:2px;flex-wrap:wrap;border-bottom:1px solid var(--sep);margin-bottom:0}
.sr-tab{padding:8px 12px;font-size:12.5px;font-weight:500;color:var(--t2);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}
.sr-tab:hover{color:var(--t1)}
.sr-tab[aria-selected="true"]{color:var(--blue);border-bottom-color:var(--blue);font-weight:600}
.sr-tab .cnt{font-size:11px;background:var(--gray-fill);border-radius:999px;padding:0 6px;margin-left:4px}
.sr-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:12px 0}
.sr-chips{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:0 0 10px}
.sr-chip{font-size:11.5px;background:var(--blue-fill);color:var(--blue);border-radius:999px;padding:2px 6px 2px 10px;display:inline-flex;gap:4px;align-items:center}
.sr-chip a{color:inherit;text-decoration:none;font-weight:700;padding:0 4px}
.sr-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;padding:14px 0}
.sr-card{background:var(--surface);border:1px solid var(--sep);border-radius:var(--r-md);padding:14px}
.sr-days{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-top:10px}
.sr-day{text-align:center;font-size:10px;color:var(--t3);border:1px solid var(--sep);border-radius:var(--r-xs);padding:4px 2px}
.sr-day.today{border-color:var(--blue);color:var(--blue);font-weight:700}
.sr-day .dl{display:block;font-weight:600;color:var(--t2);font-size:10.5px}
.sr-pager{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;padding:12px 0}
.sr-pg{display:inline-flex;gap:4px;align-items:center}
.sr-pg a,.sr-pg span.cur{font-size:12px;padding:4px 9px;border:1px solid var(--sep);border-radius:var(--r-sm);color:var(--t2);text-decoration:none}
.sr-pg span.cur{background:var(--blue);color:#fff;border-color:var(--blue);font-weight:600}
.sr-pg span.dis{font-size:12px;padding:4px 9px;color:var(--t4)}
.sortable-th a{color:inherit;text-decoration:none}
.sortable-th a:hover{color:var(--blue)}
.sr-only{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden;clip:rect(0,0,0,0)!important;white-space:nowrap}
.sr-print-banner{display:none}
.sr-empty{padding:44px 20px;text-align:center;color:var(--t3)}
@media(max-width:900px){.sr-kpis{grid-template-columns:repeat(2,1fr)}}
@media print{
  .sr-toolbar,.sr-tabs,.sr-chips,.sr-pager,.no-print{display:none!important}
  .sr-print-banner{display:block;margin-bottom:10px}
  .sr-print-banner h2{font-size:16px;margin:0}
}
</style>

<div class="sr-print-banner"><h2>Shift Roster — week of <?= date('d M Y', strtotime($week_start)) ?> (printed <?= date('d M Y H:i') ?>)</h2></div>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="font-size:22px;font-weight:700;color:var(--t1);letter-spacing:-0.3px;">Shift Roster</h1>
    <div style="font-size:12px;color:var(--t3);margin-top:2px;">
      Week of <?= date('d M', strtotime($week_start)) ?> — <?= date('d M Y', strtotime($week_start . ' +6 days')) ?> · one shift per worker for the week
    </div>
  </div>
  <div class="flex ai-c gap-10 no-print" style="flex-wrap:wrap;">
    <a href="<?= site_url('team/shift_roster?week=' . date('Y-m-d', strtotime($week_start . ' -7 days'))) ?>" class="btn btn-ghost btn-sm" aria-label="Previous week">&#8249; Prev</a>
    <form method="get" action="<?= site_url('team/shift_roster') ?>" class="flex ai-c gap-10">
      <label class="sr-only" for="srWeek">Week</label>
      <input type="week" id="srWeek" name="week" class="form-control" style="width:auto;" value="<?= $week_input ?>">
      <button class="btn btn-primary btn-sm">Go</button>
    </form>
    <a href="<?= site_url('team/shift_roster?week=' . date('Y-m-d', strtotime($week_start . ' +7 days'))) ?>" class="btn btn-ghost btn-sm" aria-label="Next week">Next &#8250;</a>
    <a href="<?= site_url('team/shift_roster') ?>" class="btn btn-ghost btn-sm">This week</a>
    <?php if ($can_export): ?>
    <a href="<?= site_url('team/shift_roster_csv') . '?' . http_build_query($base_params) ?>" class="btn btn-ghost btn-sm">Export CSV</a>
    <?php endif; ?>
  </div>
</div>

<!-- KPI strip: full-week coverage (before tab narrowing) -->
<div class="sr-kpis" role="region" aria-label="Roster coverage summary">
  <div class="sr-kpi"><div class="k-l">Active Staff</div><div class="k-v"><?= (int)$summary['total'] ?></div><div class="k-m">on the roster</div></div>
  <div class="sr-kpi"><div class="k-l">Assigned</div><div class="k-v"><?= (int)$summary['assigned'] ?></div><div class="k-m">have a shift this week</div></div>
  <div class="sr-kpi"><div class="k-l">Unassigned</div><div class="k-v"><?= (int)$summary['unassigned'] ?></div><div class="k-m">still need a shift</div></div>
  <div class="sr-kpi"><div class="k-l">Coverage</div><div class="k-v"><?= $summary['coverage'] === null ? '—' : (int)$summary['coverage'] . '%' ?></div><div class="k-m">assigned of active staff</div></div>
  <div class="sr-kpi"><div class="k-l">Shift Types</div><div class="k-v"><?= (int)$summary['shift_types'] ?></div><div class="k-m"><?php foreach ($shifts as $i => $sh): ?><?= $i ? ' · ' : '' ?><?= htmlspecialchars((string)$sh->name, ENT_QUOTES, 'UTF-8') ?> <?= (int)(isset($per_shift_counts[(int)$sh->id]) ? $per_shift_counts[(int)$sh->id] : 0) ?><?php endforeach; ?></div></div>
</div>

<div class="wms-card">
  <div style="padding:0 16px;">
    <div class="sr-tabs" role="tablist" aria-label="Assignment status">
      <?php foreach ($tab_defs as $key => $def): ?>
      <a role="tab" aria-selected="<?= $active_tab === $key ? 'true' : 'false' ?>"
         class="sr-tab" href="<?= $mk_url(array('tab' => ($key === 'all' ? null : $key), 'page' => null)) ?>">
        <?= $def['label'] ?><span class="cnt"><?= (int)$def['count'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <form method="get" action="<?= site_url('team/shift_roster') ?>" class="sr-toolbar no-print" role="search">
      <input type="hidden" name="week" value="<?= $week_start ?>">
      <?php if ($active_tab !== 'all'): ?><input type="hidden" name="tab" value="<?= $active_tab ?>"><?php endif; ?>
      <?php if ($sort_by !== 'worker'): ?><input type="hidden" name="sort" value="<?= $sort_by ?>"><?php endif; ?>
      <?php if ($sort_dir !== 'asc'): ?><input type="hidden" name="dir" value="<?= $sort_dir ?>"><?php endif; ?>
      <?php if ($is_cards): ?><input type="hidden" name="view" value="cards"><?php endif; ?>
      <div style="position:relative;">
        <label class="sr-only" for="srSearch">Search workers</label>
        <input type="search" id="srSearch" name="q" class="form-control" style="width:240px;padding-left:30px;"
               placeholder="Search name, ID, position, shift…  ( / )" value="<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>">
        <span aria-hidden="true" style="position:absolute;left:9px;top:7px;color:var(--t3);">&#128269;</span>
      </div>
      <label class="sr-only" for="srShiftFilter">Filter by shift</label>
      <select id="srShiftFilter" name="shift" class="form-select sr-autosubmit" style="width:auto;">
        <option value="">All shifts</option>
        <option value="none" <?= $shift_filter === 'none' ? 'selected' : '' ?>>Unassigned only</option>
        <?php foreach ($shifts as $sh): ?>
        <option value="<?= (int)$sh->id ?>" <?= $shift_filter === (string)(int)$sh->id ? 'selected' : '' ?>><?= htmlspecialchars((string)$sh->name, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
      <label class="sr-only" for="srSort">Sort by</label>
      <select id="srSort" name="sortsel" class="form-select" style="width:auto;" onchange="if(this.value){var p=this.value.split(':');var u=new URL(window.location.href);u.searchParams.set('sort',p[0]);u.searchParams.set('dir',p[1]);u.searchParams.delete('page');window.location.href=u.toString();}">
        <?php foreach (array('worker:asc' => 'Worker A–Z', 'worker:desc' => 'Worker Z–A', 'position:asc' => 'Position A–Z', 'shift:asc' => 'Shift A–Z') as $v => $l): ?>
        <option value="<?= $v ?>" <?= ($sort_by . ':' . $sort_dir) === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <?php if ($has_filters || $active_tab !== 'all'): ?>
      <a href="<?= site_url('team/shift_roster?week=' . $week_start) ?>" class="btn btn-ghost btn-sm">Clear</a>
      <?php endif; ?>
      <span style="flex:1;"></span>
      <div class="btn-group no-print" role="group" aria-label="View mode">
        <a href="<?= $mk_url(array('view' => null)) ?>" class="btn btn-ghost btn-sm" aria-pressed="<?= $is_cards ? 'false' : 'true' ?>">Table</a>
        <a href="<?= $mk_url(array('view' => 'cards')) ?>" class="btn btn-ghost btn-sm" aria-pressed="<?= $is_cards ? 'true' : 'false' ?>">Cards</a>
      </div>
    </form>

    <?php if ($has_filters): ?>
    <div class="sr-chips" aria-label="Active filters">
      <span style="font-size:11.5px;color:var(--t3);">Active filters:</span>
      <?php if ($search_query !== ''): ?><span class="sr-chip">Search: <?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?><a href="<?= $mk_url(array('q' => null, 'page' => null)) ?>" aria-label="Clear search">×</a></span><?php endif; ?>
      <?php if ($shift_filter !== ''): ?><span class="sr-chip">Shift: <?= $shift_filter === 'none' ? 'Unassigned only' : htmlspecialchars((string)$shift_by_id[(int)$shift_filter]->name, ENT_QUOTES, 'UTF-8') ?><a href="<?= $mk_url(array('shift' => null, 'page' => null)) ?>" aria-label="Clear shift filter">×</a></span><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="card-body p-0">
  <?php if (empty($workers)): ?>
    <div class="sr-empty">
      <?php if ((int)$summary['total'] === 0): ?>
        <div style="font-size:15px;font-weight:600;color:var(--t1);">No active staff.</div>
        <div style="font-size:12.5px;margin-top:4px;">Active workers appear here automatically.</div>
      <?php elseif ($active_tab === 'unassigned'): ?>
        <div style="font-size:15px;font-weight:600;color:var(--t1);">Everyone is assigned this week.</div>
        <div style="font-size:12.5px;margin-top:4px;">No unassigned workers match the current filters.</div>
      <?php else: ?>
        <div style="font-size:15px;font-weight:600;color:var(--t1);">No workers match the current filters.</div>
        <div style="font-size:12.5px;margin-top:4px;">Try a different search term or <a href="<?= site_url('team/shift_roster?week=' . $week_start) ?>">clear the filters</a>.</div>
      <?php endif; ?>
    </div>
  <?php elseif ($is_cards): ?>
    <div class="sr-cards">
      <?php foreach ($workers as $w): $shift = $w->roster_shift; ?>
      <div class="sr-card">
        <div class="flex ai-c jb" style="gap:8px;">
          <div>
            <a href="<?= site_url('team/view/' . (int)$w->id) ?>" style="font-weight:600;font-size:13.5px;color:var(--t1);text-decoration:none;"><?= htmlspecialchars((string)$w->full_name_roster, ENT_QUOTES, 'UTF-8') ?></a>
            <div style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)(isset($w->employee_id) ? $w->employee_id : ''), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)(isset($w->position) ? $w->position : ''), ENT_QUOTES, 'UTF-8') ?></div>
          </div>
          <?php if ($shift): ?><span class="badge <?= $shift_color($shift->name) ?>"><?= htmlspecialchars((string)$shift->name, ENT_QUOTES, 'UTF-8') ?></span>
          <?php else: ?><span class="badge b-secondary">Unassigned</span><?php endif; ?>
        </div>
        <div class="sr-days" aria-label="Week days">
          <?php foreach ($week_dates as $i => $d): ?>
          <div class="sr-day <?= $d === $today ? 'today' : '' ?>"><span class="dl"><?= $day_names[$i] ?></span><?= date('d', strtotime($d)) ?><br><?= $shift ? htmlspecialchars((string)$shift->name, ENT_QUOTES, 'UTF-8') : '—' ?></div>
          <?php endforeach; ?>
        </div>
        <?php if ($shift): ?><div style="font-size:11px;color:var(--t3);margin-top:8px;"><?= date('H:i', strtotime($shift->start_time)) ?> – <?= date('H:i', strtotime($shift->end_time)) ?> all week</div><?php endif; ?>
        <?php if ($can_edit): ?>
        <div style="margin-top:10px;">
          <?= form_open('team/assign_shift', array('class' => 'flex ai-c gap-10')) ?>
            <input type="hidden" name="user_id" value="<?= (int)$w->id ?>">
            <input type="hidden" name="week" value="<?= $week_start ?>">
            <?php $assign_select_id++; ?>
            <label class="sr-only" for="srAssign<?= $assign_select_id ?>">Assign shift for <?= htmlspecialchars((string)$w->full_name_roster, ENT_QUOTES, 'UTF-8') ?></label>
            <select id="srAssign<?= $assign_select_id ?>" name="shift_id" class="form-select" style="flex:1;font-size:11.5px;padding:4px 8px;">
              <option value="0">— none —</option>
              <?php foreach ($shifts as $sh): ?><option value="<?= (int)$sh->id ?>" <?= (int)$sh->id === (int)$w->roster_shift_id ? 'selected' : '' ?>><?= htmlspecialchars((string)$sh->name, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;padding:4px 10px;">Set</button>
          <?= form_close() ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table wms-table mb-0">
        <thead>
          <tr>
            <th scope="col" class="sortable-th"<?= $aria_sort('worker') ?>><a href="<?= $sort_url('worker') ?>">Worker<?= $sort_mark('worker') ?></a></th>
            <?php foreach ($week_dates as $i => $d): ?>
            <th scope="col" style="text-align:center;<?= $d === $today ? 'background:var(--blue-fill);' : '' ?>">
              <?= $day_names[$i] ?><br>
              <span style="font-weight:400;font-size:11px;"><?= date('d M', strtotime($d)) ?><?= $d === $today ? ' · today' : '' ?></span>
            </th>
            <?php endforeach; ?>
            <?php if ($can_edit): ?><th scope="col" style="text-align:right;">Assign (<?= $week_input ?>)</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($workers as $w): $shift = $w->roster_shift; ?>
          <tr>
            <td>
              <a href="<?= site_url('team/view/' . (int)$w->id) ?>" class="fw-600" style="font-size:13px;color:var(--t1);text-decoration:none;"><?= htmlspecialchars((string)$w->full_name_roster, ENT_QUOTES, 'UTF-8') ?></a>
              <div style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)(isset($w->employee_id) ? $w->employee_id : ''), ENT_QUOTES, 'UTF-8') ?><?= !empty($w->employee_id) && !empty($w->position) ? ' · ' : '' ?><?= htmlspecialchars((string)(isset($w->position) ? $w->position : ''), ENT_QUOTES, 'UTF-8') ?></div>
            </td>
            <?php foreach ($week_dates as $d): ?>
            <td style="text-align:center;<?= $d === $today ? 'background:var(--blue-fill);' : '' ?>">
              <?php if ($shift): ?>
                <span class="badge <?= $shift_color($shift->name) ?>" style="font-size:10px;"><?= htmlspecialchars((string)$shift->name, ENT_QUOTES, 'UTF-8') ?></span>
              <?php else: ?>
                <span style="font-size:12px;color:var(--t3);">—</span>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
            <?php if ($can_edit): ?>
            <td style="text-align:right;">
              <?= form_open('team/assign_shift', array('class' => 'flex ai-c gap-10', 'style' => 'justify-content:flex-end;')) ?>
                <input type="hidden" name="user_id" value="<?= (int)$w->id ?>">
                <input type="hidden" name="week" value="<?= $week_start ?>">
                <?php $assign_select_id++; ?>
                <label class="sr-only" for="srAssign<?= $assign_select_id ?>">Assign shift for <?= htmlspecialchars((string)$w->full_name_roster, ENT_QUOTES, 'UTF-8') ?></label>
                <select id="srAssign<?= $assign_select_id ?>" name="shift_id" class="form-select" style="width:130px;font-size:11.5px;padding:4px 8px;">
                  <option value="0">— none —</option>
                  <?php foreach ($shifts as $sh): ?>
                  <option value="<?= (int)$sh->id ?>" <?= (int)$sh->id === (int)$w->roster_shift_id ? 'selected' : '' ?>><?= htmlspecialchars((string)$sh->name, ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;padding:4px 10px;">Set</button>
              <?= form_close() ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  </div>

  <?php if ((int)$total_filtered > 0): ?>
  <div style="padding:0 16px;">
    <div class="sr-pager no-print">
      <form method="get" action="<?= site_url('team/shift_roster') ?>" class="flex ai-c gap-10" style="font-size:12px;color:var(--t2);">
        <?php foreach ($base_params as $k => $v): ?><input type="hidden" name="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') ?>"><?php endforeach; ?>
        <label for="srPerPage">Show</label>
        <select id="srPerPage" name="per_page" class="form-select sr-autosubmit" style="width:auto;font-size:12px;">
          <?php foreach (array(10, 25, 50, 100) as $pp): ?><option value="<?= $pp ?>" <?= (int)$per_page === $pp ? 'selected' : '' ?>><?= $pp ?></option><?php endforeach; ?>
        </select>
        <span>Showing <?= (int)$showing_from ?>–<?= (int)$showing_to ?> of <?= (int)$total_filtered ?> workers</span>
      </form>
      <nav class="sr-pg" aria-label="Roster pages">
        <?php if ($page > 1): ?><a href="<?= $mk_url(array('page' => $page - 1, 'per_page' => (int)$per_page)) ?>">‹ Prev</a><?php else: ?><span class="dis">‹ Prev</span><?php endif; ?>
        <?php
          $win = array();
          for ($i = 1; $i <= (int)$total_pages; $i++) { if ($i === 1 || $i === (int)$total_pages || abs($i - $page) <= 1) $win[] = $i; }
          $win = array_unique($win); $prev_w = 0;
          foreach ($win as $i): if ($prev_w && $i > $prev_w + 1): ?><span class="dis">…</span><?php endif; $prev_w = $i; ?>
          <?php if ($i === $page): ?><span class="cur" aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= $mk_url(array('page' => $i, 'per_page' => (int)$per_page)) ?>"><?= $i ?></a><?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < (int)$total_pages): ?><a href="<?= $mk_url(array('page' => $page + 1, 'per_page' => (int)$per_page)) ?>">Next ›</a><?php else: ?><span class="dis">Next ›</span><?php endif; ?>
      </nav>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Legend -->
<?php if (!empty($shifts)): ?>
<div class="flex ai-c gap-10 mb-4" style="flex-wrap:wrap;" aria-label="Shift legend">
  <?php foreach ($shifts as $sh): ?>
  <span class="badge <?= $shift_color($sh->name) ?>" style="padding:6px 12px;">
    <?= htmlspecialchars((string)$sh->name, ENT_QUOTES, 'UTF-8') ?>: <?= date('H:i', strtotime($sh->start_time)) ?> – <?= date('H:i', strtotime($sh->end_time)) ?><?= !empty($sh->description) ? ' · ' . htmlspecialchars((string)$sh->description, ENT_QUOTES, 'UTF-8') : '' ?>
  </span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($can_edit): ?>
<!-- Shift types -->
<div class="wms-card mb-4">
  <div class="card-head">Shift Types</div>

  <?php if (empty($shifts)): ?>
  <div style="padding:20px 24px;color:var(--t3);font-size:12.5px;">No shift types yet — add the first one below.</div>
  <?php else: foreach ($shifts as $sh): ?>
  <?php $used = isset($assignment_counts[(int)$sh->id]) ? (int)$assignment_counts[(int)$sh->id] : 0; $sid = (int)$sh->id; ?>
  <div style="padding:10px 24px;border-bottom:1px solid var(--sep);">
    <?= form_open('team/shift_save', array('class' => 'flex ai-c gap-10', 'style' => 'flex-wrap:wrap;')) ?>
      <input type="hidden" name="shift_id" value="<?= $sid ?>">
      <input type="hidden" name="week" value="<?= $week_start ?>">
      <label class="sr-only" for="shName<?= $sid ?>">Shift name</label>
      <input type="text" id="shName<?= $sid ?>" name="name" class="form-control" style="width:160px;font-size:12px;padding:5px 8px;"
             value="<?= htmlspecialchars((string)$sh->name, ENT_QUOTES, 'UTF-8') ?>" required maxlength="50">
      <label style="font-size:11px;color:var(--t3);" for="shStart<?= $sid ?>">from</label>
      <input type="time" id="shStart<?= $sid ?>" name="start_time" class="form-control" style="width:115px;font-size:12px;padding:5px 8px;"
             value="<?= date('H:i', strtotime($sh->start_time)) ?>" required>
      <label style="font-size:11px;color:var(--t3);" for="shEnd<?= $sid ?>">to</label>
      <input type="time" id="shEnd<?= $sid ?>" name="end_time" class="form-control" style="width:115px;font-size:12px;padding:5px 8px;"
             value="<?= date('H:i', strtotime($sh->end_time)) ?>" required>
      <label class="sr-only" for="shDesc<?= $sid ?>">Description (optional)</label>
      <input type="text" id="shDesc<?= $sid ?>" name="description" class="form-control" style="width:220px;font-size:12px;padding:5px 8px;"
             value="<?= htmlspecialchars((string)(isset($sh->description) ? $sh->description : ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="200" placeholder="Description (optional)">
      <span style="font-size:11.5px;color:var(--t3);white-space:nowrap;"><?= $used ?> roster row(s)</span>
      <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;padding:4px 10px;">Save</button>
    <?= form_close() ?>
    <div style="margin-top:4px;">
      <?= form_open('team/shift_delete/' . $sid, array('style' => 'display:inline;')) ?>
        <input type="hidden" name="week" value="<?= $week_start ?>">
        <button type="submit" class="btn btn-outline-danger btn-sm" style="font-size:10.5px;padding:2px 8px;"
                onclick="return confirm('Delete shift “<?= htmlspecialchars((string)$sh->name, ENT_QUOTES, 'UTF-8') ?>”? It cannot be deleted while it is assigned on any roster week.');">Delete this shift</button>
      <?= form_close() ?>
      <?php if ($used > 0): ?>
      <span style="font-size:10.5px;color:var(--t3);margin-left:8px;">Still assigned — clear the roster first.</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <div style="padding:16px 24px;">
    <div style="font-size:12px;font-weight:600;color:var(--t2);margin-bottom:10px;">Add a shift type</div>
    <?= form_open('team/shift_save', array('class' => 'flex ai-c gap-10', 'style' => 'flex-wrap:wrap;')) ?>
      <input type="hidden" name="week" value="<?= $week_start ?>">
      <label class="sr-only" for="shNewName">New shift name</label>
      <input type="text" id="shNewName" name="name" class="form-control" style="width:170px;font-size:12px;" placeholder="Name (e.g. Morning)" required maxlength="50">
      <label class="sr-only" for="shNewStart">New shift start time</label>
      <input type="time" id="shNewStart" name="start_time" class="form-control" style="width:120px;font-size:12px;" value="06:00" required>
      <label class="sr-only" for="shNewEnd">New shift end time</label>
      <input type="time" id="shNewEnd" name="end_time" class="form-control" style="width:120px;font-size:12px;" value="14:00" required>
      <label class="sr-only" for="shNewDesc">New shift description (optional)</label>
      <input type="text" id="shNewDesc" name="description" class="form-control" style="width:260px;font-size:12px;" placeholder="Description (optional)" maxlength="200">
      <button type="submit" class="btn btn-primary btn-sm">Add Shift</button>
    <?= form_close() ?>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  document.querySelectorAll('.sr-autosubmit').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });
  var search = document.getElementById('srSearch');
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
