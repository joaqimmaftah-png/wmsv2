<?php defined('BASEPATH') OR exit('No direct script access allowed');
/** Published day-level plan for staff; draft preview + edit controls for planners. */
$esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$week_dates = array();
for ($i = 0; $i < 7; $i++) $week_dates[] = date('Y-m-d', strtotime($week_start . ' +' . $i . ' days'));
$week_input = date('o-\WW', strtotime($week_start));
$today = date('Y-m-d');
$is_cards = $view_mode === 'cards';
$base_params = array_filter(array(
    'week' => $week_start, 'q' => $search_query, 'shift' => $shift_filter,
    'tab' => $active_tab === 'all' ? NULL : $active_tab,
    'sort' => $sort_by === 'worker' ? NULL : $sort_by,
    'dir' => $sort_dir === 'asc' ? NULL : $sort_dir,
    'view' => $is_cards ? 'cards' : NULL,
));
$mk_url = function ($updates = array()) use ($base_params) {
    return site_url('team/shift_roster') . '?' . http_build_query(array_merge($base_params, $updates));
};
$tabs = array(
    'all' => 'All Staff', 'assigned' => 'Fully Planned', 'unassigned' => 'Needs Plan',
    'off' => 'Off Days', 'leave' => 'On Leave',
);
$badge = function ($cell) {
    if ($cell['kind'] === 'Shift') return 'sr-shift';
    if ($cell['kind'] === 'Off') return 'sr-off';
    if ($cell['kind'] === 'Leave') return 'sr-leave';
    if ($cell['kind'] === 'Holiday') return 'sr-holiday';
    if ($cell['kind'] === 'BeforeStart') return 'sr-before';
    return 'sr-unplanned';
};
$cell_name = function ($cell) use ($shift_by_id) {
    if ($cell['kind'] === 'Shift') {
        return isset($shift_by_id[(int)$cell['shift_id']]) ? $shift_by_id[(int)$cell['shift_id']]->name : 'Shift unavailable';
    }
    if ($cell['kind'] === 'Off' && $cell['extra']) return 'Off · ' . ($cell['extra']->status === 'Confirmed' ? 'worked extra' : 'extra work planned');
    if ($cell['kind'] === 'Off' && $cell['replacement']) return 'Off · replacement';
    if ($cell['kind'] === 'Leave') return $cell['leave_type'] ?: 'Leave';
    if ($cell['kind'] === 'BeforeStart') return 'Before joining';
    return $cell['kind'] === 'Unplanned' ? 'Needs plan' : $cell['kind'];
};
$day_state = function ($cell) {
    return $cell['kind'] === 'Shift' ? 'shift:' . (int)$cell['shift_id'] : $cell['kind'];
};
$all_days = $summary['worker_days'];
?>
<style>
.sr-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.sr-eyebrow{font-size:11px;font-weight:700;letter-spacing:.11em;text-transform:uppercase;color:var(--blue);margin-bottom:5px}
.sr-head h1{font-size:24px;line-height:1.2;letter-spacing:-.025em;color:var(--t1)}
.sr-sub{font-size:12px;color:var(--t2);margin-top:6px;max-width:720px;line-height:1.55}
.sr-head-actions{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
.sr-kpis{display:grid;grid-template-columns:repeat(6,minmax(112px,1fr));gap:10px;margin-bottom:16px}
.sr-kpi{background:var(--surface);border:1px solid var(--sep);border-radius:var(--r-md);padding:13px 15px;min-height:80px}
.sr-kpi span{display:block;font-size:11px;font-weight:600;color:var(--t3)}
.sr-kpi strong{display:block;font-size:22px;letter-spacing:-.03em;color:var(--t1);margin-top:3px;line-height:1.1}
.sr-kpi small{display:block;font-size:10.5px;color:var(--t3);margin-top:5px}
.sr-status{display:inline-flex;align-items:center;font-size:11px;line-height:1.3;font-weight:650;border-radius:999px;padding:4px 9px;white-space:normal}
.sr-status.sr-shift{color:var(--blue);background:var(--blue-fill)}
.sr-status.sr-off{color:var(--teal);background:rgba(12,123,123,.1)}
.sr-status.sr-leave{color:var(--purple);background:var(--purple-fill)}
.sr-status.sr-holiday{color:var(--amber);background:var(--amber-fill)}
.sr-status.sr-unplanned{color:var(--red);background:var(--red-fill)}
.sr-status.sr-before{color:var(--t3);background:var(--gray-fill)}
.sr-info{border-radius:var(--r-sm);background:var(--blue-fill);padding:12px 15px;color:var(--t1);font-size:12px;line-height:1.5;margin-bottom:14px}
.sr-info.warning{background:var(--amber-fill)}
.sr-info a{color:var(--blue);font-weight:650}
.sr-tabs{display:flex;gap:2px;flex-wrap:wrap;border-bottom:1px solid var(--sep);padding:0 14px}
.sr-tabs a{font-size:12px;color:var(--t2);text-decoration:none;padding:10px 11px;border-bottom:2px solid transparent;margin-bottom:-1px}
.sr-tabs a[aria-current="page"]{border-color:var(--blue);color:var(--blue);font-weight:700}
.sr-tabs b{background:var(--gray-fill);border-radius:30px;padding:2px 6px;font-size:10px;margin-left:4px}
.sr-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:13px 16px}
.sr-toolbar .form-control{width:220px}.sr-toolbar .form-select{width:auto;min-width:125px}
.sr-toolbar .spacer{flex:1}
.sr-toggle{display:inline-flex;gap:3px;border:1px solid var(--sep);padding:3px;border-radius:var(--r-sm)}
.sr-toggle a{font-size:12px;color:var(--t2);padding:4px 9px;border-radius:var(--r-xs);text-decoration:none}
.sr-toggle a[aria-current="page"]{background:var(--blue-fill);color:var(--blue);font-weight:700}
.sr-grid{width:100%;border-collapse:separate;border-spacing:0;min-width:1030px}
.sr-grid th,.sr-grid td{border-bottom:1px solid var(--sep);padding:10px 8px;text-align:left;vertical-align:top;font-size:12px}
.sr-grid th{background:var(--surface-2);color:var(--t2);font-size:11px;font-weight:650;white-space:nowrap}
.sr-grid th:first-child,.sr-grid td:first-child{position:sticky;left:0;z-index:2;background:var(--surface);min-width:185px;max-width:230px;border-right:1px solid var(--sep)}
.sr-grid th:first-child{background:var(--surface-2);z-index:3}
.sr-grid tbody tr:hover td{background:var(--g1)}
.sr-grid tbody tr:hover td:first-child{background:var(--surface-2)}
.sr-grid .sr-today{box-shadow:inset 0 3px var(--blue)}
.sr-person{font-weight:650;color:var(--t1);text-decoration:none;font-size:12.5px}
.sr-person:hover,.sr-edit:hover{color:var(--blue);text-decoration:underline}
.sr-micro{font-size:10.5px;color:var(--t3);line-height:1.45;margin-top:4px}
.sr-cell{min-width:112px;display:flex;flex-direction:column;align-items:flex-start;gap:4px}
.sr-edit{font-size:10.5px;color:var(--blue);text-decoration:none;font-weight:600;display:inline-block;padding:2px 0}
.sr-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:12px;padding:16px}
.sr-card{border:1px solid var(--sep);border-radius:var(--r-md);padding:14px;background:var(--surface)}
.sr-card-head{display:flex;align-items:center;justify-content:space-between;gap:8px}
.sr-card-days{margin-top:10px;display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:3px}
.sr-card-day{min-width:0;border:1px solid var(--sep);border-radius:var(--r-xs);padding:5px 3px;text-align:center;font-size:9px;line-height:1.35}
.sr-card-day b{display:block;font-size:10px;color:var(--t1)}.sr-card-day span{display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;margin-top:3px}
.sr-card-day.off{background:rgba(12,123,123,.07)}.sr-card-day.leave{background:var(--purple-fill)}
.sr-card-day.gap{background:var(--red-fill)}.sr-card-day.today{outline:2px solid var(--blue);outline-offset:-2px}
.sr-pager{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:13px 16px;font-size:12px;color:var(--t2)}
.sr-pager nav{display:flex;gap:5px}.sr-pager nav a,.sr-pager nav span{border:1px solid var(--sep);border-radius:var(--r-xs);padding:5px 8px;text-decoration:none;color:var(--t2)}
.sr-pager nav [aria-current="page"]{background:var(--blue-fill);color:var(--blue);font-weight:700}
.sr-empty{padding:46px 20px;text-align:center;color:var(--t2);font-size:13px}
.sr-shift-row{padding:12px 16px;border-top:1px solid var(--sep);display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.sr-shift-row .form-control{font-size:12px}.sr-shift-row label{font-size:11px;color:var(--t2)}
.sr-shift-row .time{max-width:110px}.sr-shift-row .name{max-width:160px}.sr-shift-row .desc{max-width:210px}
.sr-dialog{border:1px solid var(--sep);border-radius:var(--r-lg);padding:24px;max-width:420px;width:calc(100vw - 28px);box-shadow:0 20px 65px rgba(20,30,50,.22);margin:auto;color:var(--t1)}
.sr-dialog::backdrop{background:rgba(20,30,50,.42)}
.sr-dialog h2{font-size:18px;margin-bottom:5px}.sr-dialog p{font-size:12px;color:var(--t2);margin-bottom:15px;line-height:1.5}
.sr-dialog label{font-size:12px;font-weight:600;display:block;margin-top:12px;margin-bottom:4px}
.sr-dialog .form-control,.sr-dialog .form-select{width:100%}
.sr-print-banner{display:none}
@media(max-width:1150px){.sr-kpis{grid-template-columns:repeat(3,1fr)}}
@media(max-width:640px){.sr-kpis{grid-template-columns:repeat(2,1fr)}.sr-toolbar .form-control{width:100%}.sr-toolbar .spacer{display:none}}
@media print{.sr-head-actions,.sr-toolbar,.sr-tabs,.sr-pager,.sr-edit,.sr-no-print,.no-print{display:none!important}.sr-print-banner{display:block;margin-bottom:10px}.sr-grid{min-width:0}.sr-grid th,.sr-grid td{padding:6px 4px}}
</style>
<div class="sr-print-banner"><strong>Shift Roster</strong> · <?= $esc($week_start) ?> — <?= $esc(end($week_dates)) ?> · printed <?= date('d M Y H:i') ?></div>
<header class="sr-head">
  <div><div class="sr-eyebrow">Workforce planning / Weekly detail</div>
    <h1>Shift Roster</h1>
    <p class="sr-sub">Week of <?= date('d M Y', strtotime($week_start)) ?> — <?= date('d M Y', strtotime(end($week_dates))) ?>. Each day has its own shift, Off, Leave or Holiday. An empty day means it still needs a plan.</p>
  </div>
  <div class="sr-head-actions sr-no-print">
    <a class="btn btn-ghost btn-sm" href="<?= $mk_url(array('week' => date('Y-m-d', strtotime($week_start . ' -7 days')))) ?>" aria-label="Previous week">‹ Prev</a>
    <form method="get" action="<?= site_url('team/shift_roster') ?>" class="flex ai-c gap-10">
      <label for="srWeek" class="sr-only">Week</label><input class="form-control" type="week" id="srWeek" name="week" value="<?= $week_input ?>" style="width:auto">
      <button class="btn btn-ghost btn-sm" type="submit">Go</button>
    </form>
    <a class="btn btn-ghost btn-sm" href="<?= $mk_url(array('week' => date('Y-m-d', strtotime($week_start . ' +7 days')))) ?>" aria-label="Next week">Next ›</a>
    <a class="btn btn-ghost btn-sm" href="<?= site_url('team/shift_roster') ?>">Today</a>
    <a class="btn btn-primary btn-sm" href="<?= site_url('team/annual_roster?year=' . (int)substr($week_start, 0, 4)) ?>"><?= $can_export ? 'Annual plan →' : 'My annual schedule →' ?></a>
  </div>
</header>
<?php if (!$plan_ready): ?>
<div class="sr-info warning" role="alert">Annual planning is not installed yet. A supervisor must apply database migration 0018. Existing weekly assignments remain readable; no new annual days can be edited.</div>
<?php elseif ($year_row && $year_row->status === 'Draft'): ?>
<div class="sr-info warning" role="status"><strong>Draft year:</strong> You are previewing <?= (int)$year_row->plan_year ?>. Workers still see the previous published/legacy schedule until a supervisor publishes the complete year. <a href="<?= site_url('team/annual_roster?year=' . (int)$year_row->plan_year) ?>">Review annual plan</a>.</div>
<?php elseif (!$year_row && $can_edit): ?>
<div class="sr-info" role="status">No annual plan has been started for <?= (int)substr($week_start, 0, 4) ?>. Existing weekly entries are shown where available. <a href="<?= site_url('team/annual_roster?year=' . (int)substr($week_start, 0, 4)) ?>">Build the year</a>; new day changes stay in draft until you publish it.</div>
<?php endif; ?>
<div class="sr-kpis" role="region" aria-label="Coverage by worker-day">
  <div class="sr-kpi"><span>Active staff</span><strong><?= (int)$summary['total'] ?></strong><small>visible this week</small></div>
  <div class="sr-kpi"><span>Shift days</span><strong><?= (int)$summary['shift_days'] ?></strong><small>scheduled work</small></div>
  <div class="sr-kpi"><span>Off / Holiday</span><strong><?= (int)$summary['off_days'] ?></strong><small>planned unavailable</small></div>
  <div class="sr-kpi"><span>Leave days</span><strong><?= (int)$summary['leave_days'] ?></strong><small>planned or approved</small></div>
  <div class="sr-kpi"><span>Needs plan</span><strong style="color:<?= $summary['unplanned_days'] ? 'var(--red)' : 'var(--green)' ?>"><?= (int)$summary['unplanned_days'] ?></strong><small>unplanned worker-days</small></div>
  <div class="sr-kpi"><span>Coverage</span><strong><?= $summary['coverage'] === NULL ? '—' : (int)$summary['coverage'] . '%' ?></strong><small><?= (int)$summary['extra_days'] ?> off-day work exception<?= (int)$summary['extra_days'] === 1 ? '' : 's' ?></small></div>
</div>
<section class="wms-card" aria-label="Weekly shift roster">
  <nav class="sr-tabs" aria-label="Weekly planning filters">
    <?php foreach ($tabs as $key => $label): ?><a href="<?= $mk_url(array('tab' => $key === 'all' ? NULL : $key, 'page' => NULL)) ?>" <?= $active_tab === $key ? 'aria-current="page"' : '' ?>><?= $label ?><b><?= (int)$tab_counts[$key] ?></b></a><?php endforeach; ?>
  </nav>
  <form method="get" action="<?= site_url('team/shift_roster') ?>" class="sr-toolbar sr-no-print" role="search">
    <input type="hidden" name="week" value="<?= $esc($week_start) ?>">
    <?php if ($active_tab !== 'all'): ?><input type="hidden" name="tab" value="<?= $esc($active_tab) ?>"><?php endif; ?>
    <?php if ($is_cards): ?><input type="hidden" name="view" value="cards"><?php endif; ?>
    <label class="sr-only" for="srSearch">Search workers</label>
    <input type="search" id="srSearch" name="q" class="form-control" placeholder="Search name, ID, shift… ( / )" value="<?= $esc($search_query) ?>">
    <label class="sr-only" for="srShiftFilter">Filter by shift or gap</label>
    <select id="srShiftFilter" name="shift" class="form-select sr-autosubmit"><option value="">All days</option><option value="none" <?= $shift_filter === 'none' ? 'selected' : '' ?>>Needs plan</option>
      <?php foreach ($shifts as $sh): ?><option value="<?= (int)$sh->id ?>" <?= $shift_filter === (string)(int)$sh->id ? 'selected' : '' ?>><?= $esc($sh->name) ?></option><?php endforeach; ?>
    </select>
    <label class="sr-only" for="srSort">Sort by</label><select id="srSort" class="form-select" name="sort"><option value="worker" <?= $sort_by === 'worker' ? 'selected' : '' ?>>Worker A–Z</option><option value="position" <?= $sort_by === 'position' ? 'selected' : '' ?>>Position</option><option value="shift" <?= $sort_by === 'shift' ? 'selected' : '' ?>>First shift</option></select>
    <button class="btn btn-ghost btn-sm">Apply</button>
    <?php if ($search_query !== '' || $shift_filter !== '' || $active_tab !== 'all'): ?><a class="btn btn-ghost btn-sm" href="<?= site_url('team/shift_roster?week=' . $week_start) ?>">Clear</a><?php endif; ?>
    <span class="spacer"></span>
    <div class="sr-toggle" aria-label="View mode"><a href="<?= $mk_url(array('view' => NULL)) ?>" <?= !$is_cards ? 'aria-current="page"' : '' ?>>Table</a><a href="<?= $mk_url(array('view' => 'cards')) ?>" <?= $is_cards ? 'aria-current="page"' : '' ?>>Cards</a></div>
    <?php if ($can_export): ?><a class="btn btn-ghost btn-sm" href="<?= site_url('team/shift_roster_csv') . '?' . http_build_query($base_params) ?>">Export week CSV</a><?php endif; ?>
  </form>
  <?php if (!$workers): ?>
    <div class="sr-empty"><?php if (!$summary['total']): ?>No active staff to roster.<?php elseif ($active_tab === 'unassigned' && !$tab_counts['unassigned']): ?>Everyone is planned this week.<?php else: ?>No workers match the current filters.<?php endif; ?></div>
  <?php elseif ($is_cards): ?>
    <div class="sr-cards">
    <?php foreach ($workers as $w): ?><article class="sr-card">
      <div class="sr-card-head"><div><a class="sr-person" href="<?= site_url('team/view/' . (int)$w->id) ?>"><?= $esc($w->full_name_roster) ?></a><div class="sr-micro"><?= $esc($w->employee_id) ?> · <?= $esc($w->position) ?></div></div><small><?= (int)$w->week_unplanned ?> gaps</small></div>
      <div class="sr-card-days">
        <?php foreach ($week_dates as $i => $d): $cell = $w->roster_days[$d]; ?>
        <div class="sr-card-day <?= $cell['kind'] === 'Off' ? 'off' : ($cell['kind'] === 'Leave' ? 'leave' : ($cell['kind'] === 'Unplanned' ? 'gap' : '')) ?> <?= $d === $today ? 'today' : '' ?>" title="<?= $esc($d . ' · ' . $cell_name($cell)) ?>"><b><?= date('D', strtotime($d)) ?><br><?= date('d', strtotime($d)) ?></b><span><?= $esc($cell_name($cell)) ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php if ($can_edit && $plan_ready): ?><div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:11px">
        <?php foreach ($week_dates as $d): $cell = $w->roster_days[$d]; if ($d < $today || $cell['kind'] === 'Leave' || $cell['kind'] === 'BeforeStart' || $cell['extra'] || $cell['replacement']) continue; ?>
        <a class="sr-edit" href="<?= site_url('team/roster_day_edit?user=' . (int)$w->id . '&date=' . $d) ?>" data-user="<?= (int)$w->id ?>" data-worker="<?= $esc($w->full_name_roster) ?>" data-date="<?= $d ?>" data-value="<?= $esc($day_state($cell)) ?>" data-plan="<?= $esc($cell['plan_status']) ?>">Edit <?= date('D', strtotime($d)) ?></a>
        <?php endforeach; ?></div><?php endif; ?>
    </article><?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="table-responsive" style="overflow-x:auto">
      <table class="sr-grid"><thead><tr><th scope="col">Worker</th>
      <?php foreach ($week_dates as $d): ?><th scope="col" class="<?= $d === $today ? 'sr-today' : '' ?>"><?= date('D', strtotime($d)) ?><br><?= date('d M', strtotime($d)) ?><?= $d === $today ? ' · Today' : '' ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($workers as $w): ?><tr><td><a class="sr-person" href="<?= site_url('team/view/' . (int)$w->id) ?>"><?= $esc($w->full_name_roster) ?></a><div class="sr-micro"><?= $esc($w->employee_id) ?><br><?= $esc($w->position) ?></div></td>
        <?php foreach ($week_dates as $d): $cell = $w->roster_days[$d]; $sh = isset($shift_by_id[(int)$cell['shift_id']]) ? $shift_by_id[(int)$cell['shift_id']] : NULL; $ex = $cell['extra']; $extra_shift = $ex && isset($shift_by_id[(int)$ex->shift_id]) ? $shift_by_id[(int)$ex->shift_id] : NULL; ?>
        <td class="<?= $d === $today ? 'sr-today' : '' ?>"><div class="sr-cell"><span class="sr-status <?= $badge($cell) ?>"><?= $esc($cell_name($cell)) ?></span>
          <?php if ($sh): ?><span class="sr-micro" style="margin:0"><?= substr($sh->start_time,0,5) ?> – <?= substr($sh->end_time,0,5) ?></span><?php endif; ?>
          <?php if ($extra_shift): ?><span class="sr-micro" style="margin:0"><?= $esc($extra_shift->name) ?> · <?= substr($extra_shift->start_time,0,5) ?>–<?= substr($extra_shift->end_time,0,5) ?></span><?php endif; ?>
          <?php if ($cell['replacement']): ?><span class="sr-micro" style="margin:0">For work on <?= $esc($cell['replacement']->work_date) ?></span><?php endif; ?>
          <?php if ($can_edit && $plan_ready && $d >= $today && $cell['kind'] !== 'Leave' && $cell['kind'] !== 'BeforeStart' && !$ex && !$cell['replacement']): ?>
          <a class="sr-edit" href="<?= site_url('team/roster_day_edit?user=' . (int)$w->id . '&date=' . $d) ?>" data-user="<?= (int)$w->id ?>" data-worker="<?= $esc($w->full_name_roster) ?>" data-date="<?= $d ?>" data-value="<?= $esc($day_state($cell)) ?>" data-plan="<?= $esc($cell['plan_status']) ?>">Edit day</a>
          <?php elseif ($can_edit && $plan_ready && ($cell['kind'] === 'Leave' || $ex || $cell['replacement'])): ?>
          <a class="sr-edit" href="<?= site_url('team/annual_roster?year=' . (int)substr($cell['replacement'] ? $cell['replacement']->work_date : $d,0,4) . '&user=' . (int)$w->id) ?>"><?= $ex || $cell['replacement'] ? 'Manage extra work' : 'Manage leave' ?></a>
          <?php endif; ?>
        </div></td><?php endforeach; ?></tr><?php endforeach; ?>
      </tbody></table>
    </div>
  <?php endif; ?>
  <?php if ($total_filtered): ?>
  <footer class="sr-pager sr-no-print"><span>Showing <?= (int)$showing_from ?>–<?= (int)$showing_to ?> of <?= (int)$total_filtered ?> workers · <?= $all_days ?> worker-days this week</span>
    <nav aria-label="Roster pages"><?php if ($page > 1): ?><a href="<?= $mk_url(array('page' => $page - 1, 'per_page' => $per_page)) ?>">‹ Previous</a><?php endif; ?>
      <span aria-current="page"><?= (int)$page ?> / <?= (int)$total_pages ?></span>
      <?php if ($page < $total_pages): ?><a href="<?= $mk_url(array('page' => $page + 1, 'per_page' => $per_page)) ?>">Next ›</a><?php endif; ?></nav>
  </footer><?php endif; ?>
</section>
<div class="sr-info" style="margin-top:14px"><strong>Legend:</strong> Shift = scheduled work · Off = not expected on site · Leave = planned absence · Holiday = public holiday · Needs plan = scheduling gap. Extra work stays marked <strong>Off · extra work</strong> until attendance is confirmed. The roster plans availability; <a href="<?= site_url('team/report') ?>">attendance</a> records what actually happened.</div>
<?php if ($can_edit && $plan_ready): ?>
<dialog id="srEditor" class="sr-dialog" aria-labelledby="srEditorTitle">
  <h2 id="srEditorTitle">Edit roster day</h2><p id="srEditorContext">Select this worker's plan for this date.</p>
  <?= form_open('team/roster_day_save') ?>
    <input type="hidden" id="srEditorUser" name="user_id"><input type="hidden" id="srEditorDate" name="date">
    <label for="srEditorType">Plan for this date</label>
    <select class="form-select" id="srEditorType" name="day_type" required>
      <option value="Unplanned">Needs plan (clear)</option><option value="Off">Off day</option><option value="Holiday">Public holiday</option>
      <?php foreach ($shifts as $sh): ?><option value="shift:<?= (int)$sh->id ?>"><?= $esc($sh->name) ?> · <?= substr($sh->start_time,0,5) ?>–<?= substr($sh->end_time,0,5) ?></option><?php endforeach; ?>
    </select>
    <label for="srEditorReason">Reason for change <span style="font-weight:400">(required for published plans)</span></label>
    <input class="form-control" id="srEditorReason" name="reason" maxlength="255" placeholder="E.g. swapped with Friday's shift">
    <div class="flex ai-c" style="justify-content:flex-end;gap:8px;margin-top:20px"><button type="button" id="srEditorClose" class="btn btn-ghost btn-sm">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Save day</button></div>
  <?= form_close() ?>
</dialog>
<?php endif; ?>
<?php if ($can_edit): ?>
<section class="wms-card sr-no-print" style="margin-top:20px" aria-labelledby="srTypesTitle"><div class="card-head" id="srTypesTitle">Shift types <span style="font-size:11px;font-weight:400;color:var(--t3);margin-left:8px">Shared time definitions · changing a used shift is blocked; create a new type instead.</span></div>
  <?php foreach ($shifts as $sh): $sid = (int)$sh->id; $used = isset($assignment_counts[$sid]) ? (int)$assignment_counts[$sid] : 0; ?>
  <div class="sr-shift-row">
    <?php if ($used): ?>
      <strong><?= $esc($sh->name) ?></strong><span class="sr-micro" style="margin:0"><?= substr($sh->start_time, 0, 5) ?>–<?= substr($sh->end_time, 0, 5) ?> · <?= $esc($sh->description) ?></span>
      <small style="color:var(--t3)">Locked · <?= $used ?> schedule reference<?= $used === 1 ? '' : 's' ?>. Create a new shift for different hours.</small>
    <?php else: ?>
    <?= form_open('team/shift_save', array('class' => 'flex ai-c gap-10', 'style' => 'flex-wrap:wrap')) ?>
      <input type="hidden" name="shift_id" value="<?= $sid ?>"><input type="hidden" name="week" value="<?= $week_start ?>">
      <label for="shName<?= $sid ?>">Name</label><input id="shName<?= $sid ?>" class="form-control name" type="text" name="name" value="<?= $esc($sh->name) ?>" required maxlength="50">
      <label for="shStart<?= $sid ?>">From</label><input id="shStart<?= $sid ?>" class="form-control time" type="time" name="start_time" value="<?= substr($sh->start_time,0,5) ?>" required>
      <label for="shEnd<?= $sid ?>">To</label><input id="shEnd<?= $sid ?>" class="form-control time" type="time" name="end_time" value="<?= substr($sh->end_time,0,5) ?>" required>
      <label for="shDesc<?= $sid ?>" class="sr-only">Description</label><input id="shDesc<?= $sid ?>" class="form-control desc" type="text" name="description" placeholder="Description" value="<?= $esc($sh->description) ?>" maxlength="200">
      <button class="btn btn-ghost btn-sm" type="submit">Save</button>
    <?= form_close() ?>
    <?= form_open('team/shift_delete/' . $sid) ?><input type="hidden" name="week" value="<?= $week_start ?>"><button class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this shift type? Any existing roster assignment blocks deletion.');">Delete</button><?= form_close() ?>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <div class="sr-shift-row"><?= form_open('team/shift_save', array('class' => 'flex ai-c gap-10', 'style' => 'flex-wrap:wrap')) ?>
    <input type="hidden" name="week" value="<?= $week_start ?>">
    <label for="shNewName">New shift</label><input id="shNewName" class="form-control name" type="text" name="name" placeholder="E.g. Morning" required maxlength="50">
    <label for="shNewStart">From</label><input id="shNewStart" class="form-control time" type="time" name="start_time" value="06:00" required>
    <label for="shNewEnd">To</label><input id="shNewEnd" class="form-control time" type="time" name="end_time" value="14:00" required>
    <label for="shNewDesc" class="sr-only">Description</label><input id="shNewDesc" class="form-control desc" type="text" name="description" placeholder="Description (optional)" maxlength="200">
    <button class="btn btn-primary btn-sm" type="submit">Add shift type</button><?= form_close() ?>
  </div>
</section>
<?php endif; ?>
<script>
(function(){
  document.querySelectorAll('.sr-autosubmit').forEach(function(el){el.addEventListener('change',function(){el.form.submit();});});
  var search=document.getElementById('srSearch'),timer;
  document.addEventListener('keydown',function(e){if(e.key==='/'&&search&&!/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)){e.preventDefault();search.focus();search.select();}});
  if(search)search.addEventListener('input',function(){clearTimeout(timer);timer=setTimeout(function(){search.form.submit();},500);});
  var dlg=document.getElementById('srEditor');
  if(!dlg||!dlg.showModal)return;
  document.querySelectorAll('.sr-edit[data-user]').forEach(function(a){a.addEventListener('click',function(e){
    e.preventDefault();document.getElementById('srEditorUser').value=a.dataset.user;
    document.getElementById('srEditorDate').value=a.dataset.date;
    document.getElementById('srEditorContext').textContent=a.dataset.worker+' · '+a.dataset.date;
    var type=document.getElementById('srEditorType');
    type.querySelector('option[value="Unplanned"]').disabled=(a.dataset.plan==='Published');
    type.value=a.dataset.value;
    var reason=document.getElementById('srEditorReason');reason.value='';reason.required=(a.dataset.plan==='Published');reason.minLength=reason.required?5:0;
    dlg.showModal();document.getElementById('srEditorType').focus();
  });});
  document.getElementById('srEditorClose').addEventListener('click',function(){dlg.close();});
})();
</script>
