<?php defined('BASEPATH') OR exit('No direct script access allowed');
/* One year at a glance, a readable month, and a path to the day-level week.
 * All inputs submit to supervisor-only POST endpoints; employees only see their
 * own published year and managers can preview but never mutate. */
$e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$today = date('Y-m-d');
$year_end = $year . '-12-31';
$can_plan = $can_edit && $ready && $year_end >= $today;
$published = $year_row && $year_row->status === 'Published';
$draft = $year_row && $year_row->status === 'Draft';
$work = $chosen && isset($year_days[(int)$chosen->id]) ? $year_days[(int)$chosen->id] : array();
$chosen_id = $chosen ? (int)$chosen->id : 0;
$shifts_by_id = array(); foreach ($shifts as $shift) $shifts_by_id[(int)$shift->id] = $shift;
$base = site_url('team/annual_roster');
$link = function ($changes = array()) use ($base, $year, $chosen_id, $calendar_view, $month) {
    $p = array('year' => $year, 'user' => $chosen_id ?: NULL, 'view' => $calendar_view === 'month' ? 'month' : NULL,
        'month' => $calendar_view === 'month' ? $month : NULL);
    return $base . '?' . http_build_query(array_filter(array_merge($p, $changes), function ($v) { return $v !== NULL && $v !== ''; }));
};
$label = function ($cell) use ($shifts_by_id) {
    if ($cell['kind'] === 'Shift') return isset($shifts_by_id[$cell['shift_id']]) ? $shifts_by_id[$cell['shift_id']]->name : 'Shift';
    if ($cell['kind'] === 'Leave') return $cell['leave_type'] ?: 'Leave';
    return $cell['kind'] === 'Unplanned' ? 'Needs plan' : $cell['kind'];
};
$kind_class = function ($cell) { return 'ar-' . strtolower($cell['kind']); };
$counts = array('Shift' => 0, 'Off' => 0, 'Leave' => 0, 'Holiday' => 0, 'Unplanned' => 0);
foreach ($work as $date => $cell) {
    if ($chosen && !empty($chosen->join_date) && $date < $chosen->join_date) continue;
    if (isset($counts[$cell['kind']])) $counts[$cell['kind']]++;
}
$percentage = isset($coverage['expected']) && $coverage['expected'] ? (int)round($coverage['planned'] / $coverage['expected'] * 100) : 0;
$first_shift = count($shifts) ? (int)$shifts[0]->id : 0;
$first_day = max($year . '-01-01', $today);
?>
<style>
.ar-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.ar-kicker{color:var(--blue);font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;margin-bottom:5px}
.ar-head h1{color:var(--t1);font-size:26px;letter-spacing:-.03em;line-height:1.2}
.ar-sub{color:var(--t2);font-size:12px;line-height:1.6;max-width:670px;margin-top:6px}
.ar-actions,.ar-yearnav,.ar-split{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ar-yearnav{border:1px solid var(--sep);background:var(--surface);border-radius:var(--r-sm);padding:3px}
.ar-yearnav a{padding:4px 10px;text-decoration:none;color:var(--t2);font-size:15px;border-radius:var(--r-xs)}
.ar-yearnav a:hover{background:var(--blue-fill);color:var(--blue)}.ar-yearnav strong{font-size:16px;color:var(--t1);padding:0 8px}
.ar-pill{display:inline-flex;align-items:center;border:1px solid var(--sep);border-radius:99px;padding:4px 10px;font-size:11px;font-weight:750;color:var(--t2);white-space:nowrap}
.ar-pill.draft{color:var(--amber);background:var(--amber-fill);border-color:transparent}.ar-pill.published{color:var(--green);background:var(--green-fill);border-color:transparent}
.ar-banner{background:var(--blue-fill);border:1px solid var(--sep);border-radius:var(--r-md);padding:12px 15px;font-size:12px;color:var(--t1);line-height:1.6;margin-bottom:15px}
.ar-banner.warn{background:var(--amber-fill)}.ar-banner a{font-weight:700;color:var(--blue)}
.ar-kpis{display:grid;grid-template-columns:repeat(5,minmax(115px,1fr));gap:10px;margin-bottom:16px}
.ar-kpi{background:var(--surface);border:1px solid var(--sep);border-radius:var(--r-md);padding:14px 16px}
.ar-kpi span{display:block;font-size:11px;color:var(--t3)}.ar-kpi strong{font-size:22px;color:var(--t1);display:block;letter-spacing:-.03em;margin-top:2px}
.ar-kpi small{font-size:10.5px;color:var(--t3);display:block;margin-top:2px}
.ar-progress{height:7px;border-radius:20px;background:var(--gray-fill);overflow:hidden;margin-top:8px}.ar-progress i{display:block;height:100%;background:var(--blue);border-radius:20px}
.ar-layout{display:grid;grid-template-columns:minmax(250px,300px) minmax(0,1fr);gap:16px;align-items:start}
.ar-layout>*{min-width:0}.ar-panel{background:var(--surface);border:1px solid var(--sep);border-radius:var(--r-md);overflow:hidden}
.ar-panel+.ar-panel{margin-top:16px}.ar-panel h2{font-size:14px;color:var(--t1);margin:0}.ar-panel-head{padding:15px 17px;display:flex;align-items:center;justify-content:space-between;gap:8px;border-bottom:1px solid var(--sep);flex-wrap:wrap}
.ar-panel-head p{color:var(--t3);font-size:11px;margin:3px 0 0}.ar-panel-body{padding:16px}
.ar-staff-form{display:flex;gap:7px;padding:13px 15px;border-bottom:1px solid var(--sep)}.ar-staff-form input{min-width:0;flex:1}
.ar-people{list-style:none;max-height:390px;overflow:auto;padding:4px 0;margin:0}
.ar-people li a{display:flex;align-items:center;gap:9px;justify-content:space-between;padding:9px 15px;text-decoration:none;color:var(--t1);border-left:3px solid transparent}
.ar-people li a:hover{background:var(--blue-fill)}.ar-people li a[aria-current="true"]{background:var(--blue-fill);border-color:var(--blue)}
.ar-person-initial{height:31px;width:31px;border-radius:50%;background:var(--gray-fill);color:var(--t2);font-size:11px;font-weight:750;display:grid;place-items:center;flex:none}
.ar-person-meta{min-width:0;flex:1}.ar-person-meta b{display:block;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ar-person-meta small{font-size:10px;color:var(--t3)}
.ar-pager{display:flex;justify-content:space-between;gap:8px;padding:10px 15px;border-top:1px solid var(--sep);font-size:11px;color:var(--t3)}
.ar-pager a{color:var(--blue);text-decoration:none;font-weight:700}.ar-pager a:hover{text-decoration:underline}
.ar-legend{display:flex;gap:10px;flex-wrap:wrap;padding:10px 16px;background:var(--surface-2);border-bottom:1px solid var(--sep);font-size:10.5px;color:var(--t2)}
.ar-dot{height:9px;width:9px;display:inline-block;border-radius:3px;margin-right:4px;vertical-align:middle}
.ar-shift{background:var(--blue-fill);color:var(--blue)}.ar-off{background:rgba(12,123,123,.11);color:var(--teal)}
.ar-leave{background:var(--purple-fill);color:var(--purple)}.ar-holiday{background:var(--amber-fill);color:var(--amber)}
.ar-unplanned{background:var(--red-fill);color:var(--red)}.ar-before{background:var(--gray-fill);color:var(--t3)}
.ar-switch{display:inline-flex;border:1px solid var(--sep);border-radius:var(--r-xs);padding:2px;gap:2px}
.ar-switch a{font-size:11px;font-weight:600;color:var(--t2);text-decoration:none;padding:5px 9px;border-radius:var(--r-xs)}.ar-switch a[aria-current="page"]{background:var(--blue-fill);color:var(--blue)}
.ar-month-nav{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.ar-month-nav a{font-size:11px;text-decoration:none;color:var(--t2);padding:5px 7px;border:1px solid var(--sep);border-radius:var(--r-xs)}
.ar-month-nav a[aria-current="page"]{background:var(--blue);color:white;border-color:var(--blue)}
.ar-year-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:16px}
.ar-mini{border:1px solid var(--sep);border-radius:var(--r-sm);padding:11px;background:var(--surface)}
.ar-mini-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;gap:7px}
.ar-mini-head a{font-size:12px;font-weight:750;color:var(--t1);text-decoration:none}.ar-mini-head a:hover{color:var(--blue)}
.ar-mini-head small{color:var(--t3);font-size:10px}.ar-mini-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.ar-mini-grid span,.ar-mini-grid a{min-width:0;text-align:center;font-size:10px;line-height:23px;height:23px;border-radius:4px;text-decoration:none}
.ar-mini-grid .ar-weekday{font-size:9px;color:var(--t3);font-weight:650}.ar-mini-grid .ar-mute{opacity:.24}
.ar-mini-grid a:hover{outline:2px solid var(--blue);outline-offset:-2px}.ar-mini-grid .ar-today{outline:2px solid var(--blue);outline-offset:-2px;font-weight:750}
.ar-month-wrap{padding:16px;overflow-x:auto}.ar-month-grid{min-width:680px;display:grid;grid-template-columns:45px repeat(7,minmax(82px,1fr));gap:5px}
.ar-month-grid .ar-weekday{font-size:10px;font-weight:700;color:var(--t3);text-align:center;padding:3px}
.ar-week-link{display:flex;align-items:flex-start;justify-content:center;padding-top:12px;font-size:10px;text-decoration:none;color:var(--blue);font-weight:700}
.ar-day{min-height:105px;border:1px solid var(--sep);border-radius:var(--r-sm);padding:8px;display:flex;flex-direction:column;gap:4px}
.ar-day.ar-outside{opacity:.3;background:var(--surface-2)}.ar-day.ar-today{outline:2px solid var(--blue);outline-offset:-2px}
.ar-day-num{font-size:11px;font-weight:700;color:var(--t1)}.ar-day-state{font-size:11px;font-weight:650;line-height:1.35}
.ar-day small{color:var(--t2);font-size:10px;line-height:1.35}.ar-day a{color:var(--blue);font-size:10px;font-weight:700;text-decoration:none;margin-top:auto}
.ar-day a:hover{text-decoration:underline}.ar-day.ar-shift,.ar-day.ar-off,.ar-day.ar-leave,.ar-day.ar-unplanned,.ar-day.ar-holiday,.ar-day.ar-before{border-color:transparent}
.ar-table{border-collapse:collapse;width:100%;font-size:11.5px}.ar-table th,.ar-table td{padding:9px 14px;text-align:left;border-bottom:1px solid var(--sep);vertical-align:middle}
.ar-table th{font-size:10px;color:var(--t3);font-weight:700;text-transform:uppercase;letter-spacing:.03em}
.ar-table td a,.ar-link{color:var(--blue);text-decoration:none;font-weight:700}.ar-table td a:hover,.ar-link:hover{text-decoration:underline}
.ar-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 12px}.ar-fields .wide{grid-column:1/-1}
.ar-fields label,.ar-pattern label{display:block;font-size:11px;font-weight:650;color:var(--t2);margin-bottom:5px}
.ar-fields .form-control,.ar-fields .form-select,.ar-pattern .form-select{width:100%;min-width:0}
.ar-help{font-size:11px;color:var(--t2);line-height:1.55;margin:0 0 12px}.ar-muted{font-size:11px;color:var(--t3)}
.ar-form-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:12px;flex-wrap:wrap}
.ar-pattern-people{border:1px solid var(--sep);border-radius:var(--r-sm);padding:8px;max-height:165px;overflow-y:auto;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:3px}
.ar-pattern-people label{margin:0;padding:5px 7px;display:flex;gap:7px;align-items:center;border-radius:4px;font-weight:500;color:var(--t1);cursor:pointer}
.ar-pattern-people label:hover{background:var(--blue-fill)}.ar-pattern-people label input{flex:none}
.ar-pattern-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px}.ar-pattern-day{border:1px solid var(--sep);border-radius:var(--r-xs);padding:7px;background:var(--surface-2);min-width:0}
.ar-pattern-day span{display:block;font-size:10px;color:var(--t3);margin-bottom:4px}.ar-pattern-day label{font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ar-pattern-day select{font-size:11px;padding:5px 2px;max-width:100%}
.ar-record{padding:12px 16px;border-top:1px solid var(--sep);font-size:12px}
.ar-record:first-child{border-top:0}.ar-record summary{cursor:pointer;color:var(--blue);font-weight:650}
.ar-record .ar-pill{margin-right:6px}.ar-record .ar-fields{margin:12px 0}.ar-record form{margin-top:8px}
.ar-focus{scroll-margin-top:90px}.ar-empty{padding:35px 16px;text-align:center;color:var(--t2);font-size:12px;line-height:1.55}
.ar-events{padding:2px 16px 12px}.ar-event{border-bottom:1px solid var(--sep);padding:9px 0;font-size:11px;color:var(--t2)}.ar-event b{color:var(--t1)}
@media(max-width:1150px){.ar-year-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:850px){.ar-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.ar-layout{grid-template-columns:1fr}.ar-people{max-height:240px}}
@media(max-width:590px){.ar-year-grid{grid-template-columns:1fr}.ar-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.ar-pattern-days{grid-template-columns:repeat(2,minmax(0,1fr))}.ar-pattern-people{grid-template-columns:1fr}.ar-head h1{font-size:22px}}
@media print{.ar-no-print,.ar-actions,.ar-staff-form,.ar-people,.ar-pager{display:none!important}.ar-layout{display:block}.ar-panel{break-inside:avoid}.ar-year-grid{grid-template-columns:repeat(3,1fr)}}
</style>
<header class="ar-head">
  <div><div class="ar-kicker">Workforce / Annual planning</div><h1><?= $is_planner ? 'Annual Workforce Plan' : 'My Annual Schedule' ?></h1>
    <p class="ar-sub">Date-specific shifts, Off days and leave for an entire year. The plan sets <strong>expected availability</strong>; actual punches and attendance remain independent.</p></div>
  <div class="ar-actions ar-no-print"><nav class="ar-yearnav" aria-label="Plan year">
      <a href="<?= $e($link(array('year' => $year - 1, 'user' => NULL, 'view' => NULL, 'month' => NULL))) ?>" aria-label="Previous year">‹</a>
      <strong><?= (int)$year ?></strong><a href="<?= $e($link(array('year' => $year + 1, 'user' => NULL, 'view' => NULL, 'month' => NULL))) ?>" aria-label="Next year">›</a></nav>
    <span class="ar-pill <?= $published ? 'published' : 'draft' ?>"><?= $published ? '● Published' : ($draft ? '○ Draft · planners only' : '○ Not started') ?></span>
    <a class="btn btn-ghost btn-sm" href="<?= site_url('team/shift_roster') ?>">Week view →</a>
    <?php if ($is_planner): ?><a class="btn btn-ghost btn-sm" href="<?= site_url('team/roster_cover') ?>">Sick Shift Cover →</a><?php endif; ?>
    <?php if ($ready && ($is_planner || $published)): ?><a class="btn btn-ghost btn-sm" href="<?= site_url('team/annual_roster_csv?year=' . $year . ($chosen ? '&user=' . $chosen_id : '')) ?>">Export <?= $chosen ? 'worker' : 'year' ?> CSV</a><?php endif; ?>
  </div>
</header>
<?php if (!$ready): ?>
  <div class="ar-banner warn" role="alert">Annual planning is not installed. Apply database migration <strong>0018</strong> before using this screen. <a href="<?= site_url('team/shift_roster') ?>">Existing weekly shifts</a> remain readable.</div>
<?php elseif (!$year_row): ?>
  <div class="ar-banner warn" role="status"><?= $is_planner ? 'No year has been published yet. Build a rotation to create a draft; staff cannot see it until publication.' : 'There is no published plan for this year yet. Ask your supervisor about your schedule.' ?></div>
<?php elseif ($draft): ?>
  <div class="ar-banner warn" role="status"><strong>Draft preview.</strong> Only supervisors and managers can see this work-in-progress. Fill every remaining worker-day and publish before employees and leave reminders rely on the plan. Existing weekly shifts are not carried automatically.</div>
<?php else: ?>
  <div class="ar-banner" role="status"><strong>Published <?= (int)$year ?> plan.</strong> Staff can now see their dates. Supervisor changes need a reason and appear in the audit trail; Off days remain unavailable unless an extra-work exception is approved.</div>
<?php endif; ?>
<?php if ($is_planner && $ready): ?>
<section class="ar-kpis" aria-label="Annual workforce plan totals">
  <div class="ar-kpi"><span>Remaining coverage</span><strong><?= $percentage ?>%</strong><small><?= (int)$coverage['planned'] ?> / <?= (int)$coverage['expected'] ?> eligible worker-days</small><div class="ar-progress" role="progressbar" aria-label="Remaining worker-days planned" aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100"><i style="width:<?= $percentage ?>%"></i></div></div>
  <div class="ar-kpi"><span>Still to plan</span><strong style="color:<?= $coverage['missing'] ? 'var(--red)' : 'var(--green)' ?>"><?= (int)$coverage['missing'] ?></strong><small><?= (int)$coverage['staff_missing'] ?> workers with gaps</small></div>
  <div class="ar-kpi"><span>Shift dates</span><strong><?= (int)$totals['Shift'] ?></strong><small>base scheduled work</small></div>
  <div class="ar-kpi"><span>Off / Holidays</span><strong><?= (int)$totals['Off'] + (int)$totals['Holiday'] ?></strong><small>explicitly unavailable dates</small></div>
  <div class="ar-kpi"><span>Leave / Extra work</span><strong><?= (int)$totals['leave_blocks'] ?> / <?= (int)$totals['extra'] ?></strong><small>blocks / off-day exceptions</small></div>
</section>
<?php if ($can_plan): ?>
  <div class="ar-split ar-no-print" style="justify-content:flex-end;margin-bottom:16px">
    <a class="btn btn-ghost btn-sm" href="#arPattern">Build rotation ↓</a><a class="btn btn-ghost btn-sm" href="#arLeave">Schedule leave ↓</a>
    <?php if ($draft): ?>
      <?= form_open('team/roster_publish', array('onsubmit' => "return confirm('Publish the full year? Staff will see their dated schedule and leave reminders will activate.');")) ?>
        <input type="hidden" name="year" value="<?= (int)$year ?>"><button class="btn btn-primary btn-sm" type="submit" <?= !$coverage['expected'] || $coverage['missing'] ? 'disabled title="Fill all remaining worker-days before publishing"' : '' ?>>Publish <?= (int)$year ?> plan</button>
      <?= form_close() ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php endif; ?>
<div class="ar-layout">
<aside>
  <?php if ($is_planner): ?>
  <section class="ar-panel ar-no-print" aria-labelledby="arDirectoryTitle">
    <div class="ar-panel-head"><div><h2 id="arDirectoryTitle">People</h2><p>Open a worker to inspect the whole year</p></div><span class="ar-pill"><?= (int)$total_staff ?></span></div>
    <form class="ar-staff-form" action="<?= site_url('team/annual_roster') ?>" method="get" role="search"><input type="hidden" name="year" value="<?= (int)$year ?>">
      <label for="arWorkerSearch" class="sr-only">Find a worker</label><input class="form-control" id="arWorkerSearch" type="search" name="q" placeholder="Name, ID, position…" value="<?= $e($search_query) ?>"><button class="btn btn-ghost btn-sm">Find</button>
    </form>
    <ul class="ar-people">
    <?php foreach ($staff as $person): $uid = (int)$person->id;
      $stats = isset($staff_totals[$uid]) ? $staff_totals[$uid] : array();
      $starts = max($first_day, $person->join_date ?: $first_day);
      $expected = $starts > $year_end ? 0 : ((int)(new DateTimeImmutable($starts))->diff(new DateTimeImmutable($year_end))->days + 1);
      $missing = max(0, $expected - (isset($stats['Future']) ? (int)$stats['Future'] : 0));
    ?>
      <li><a href="<?= $e($link(array('user' => $uid, 'q' => $search_query ?: NULL, 'page' => NULL))) ?>" <?= $uid === $chosen_id ? 'aria-current="true"' : '' ?>><span class="ar-person-initial" aria-hidden="true"><?= $e(strtoupper(substr($person->first_name,0,1) . substr($person->last_name,0,1))) ?></span>
        <span class="ar-person-meta"><b><?= $e($person->first_name . ' ' . $person->last_name) ?></b><small><?= $e($person->employee_id) ?> · <?= $e($person->position) ?></small></span>
        <?php if ($ready && $expected): ?><span class="ar-pill <?= $missing ? 'draft' : 'published' ?>" title="<?= (int)$missing ?> remaining worker-days need a base plan"><?= $missing ? (int)$missing . ' gaps' : 'Covered' ?></span><?php endif; ?></a></li>
    <?php endforeach; ?>
    </ul>
    <?php if (!$staff): ?><div class="ar-empty">No matching staff members. <a href="<?= $e($link(array('q' => NULL, 'page' => NULL))) ?>">Clear search</a>.</div><?php endif; ?>
    <div class="ar-pager"><span><?= (int)$total_staff ?> workers · page <?= (int)$page ?> / <?= (int)$total_pages ?></span>
      <span><?php if ($page > 1): ?><a href="<?= $e($link(array('page' => $page - 1, 'user' => NULL, 'q' => $search_query))) ?>">‹ Prev</a><?php endif; ?>
      <?php if ($page < $total_pages): ?><a href="<?= $e($link(array('page' => $page + 1, 'user' => NULL, 'q' => $search_query))) ?>">Next ›</a><?php endif; ?></span></div>
  </section>
  <?php endif; ?>
  <?php if ($can_plan): ?>
  <section id="arPattern" class="ar-panel ar-focus ar-no-print" style="margin-top:<?= $is_planner ? '16px' : '0' ?>" aria-labelledby="arPatternTitle">
    <div class="ar-panel-head"><div><h2 id="arPatternTitle">Build a rotation</h2><p>Generate a full year in one action</p></div></div>
    <div class="ar-panel-body">
    <p class="ar-help">Repeat a 2–56-day cycle over every remaining date. 7 / 14-day cycles align to Monday; other cycles start 1 January. Manual changes and carried-in weeks are protected. For a published plan, explain the revision.</p>
    <?php if (!$shifts): ?><p class="ar-banner warn">No shift types exist. <a href="<?= site_url('team/shift_roster') ?>">Create one</a> before planning work dates.</p><?php endif; ?>
    <?= form_open('team/roster_pattern_save', array('id' => 'arPatternForm')) ?>
      <input type="hidden" name="year" value="<?= (int)$year ?>">
      <label for="arCycle">Cycle length (days)</label>
      <div class="ar-split"><input type="number" class="form-control" style="width:92px" name="cycle_days" id="arCycle" min="2" max="56" value="7" required>
        <button type="button" class="btn btn-ghost btn-sm ar-preset" data-cycle="7" data-work="5">5 on / 2 Off</button>
        <button type="button" class="btn btn-ghost btn-sm ar-preset" data-cycle="6" data-work="4">4 on / 2 Off</button>
        <button type="button" class="btn btn-ghost btn-sm ar-preset" data-cycle="8" data-work="4">4 on / 4 Off</button>
      </div>
      <div style="margin-top:12px"><label>Assign this rotation to (up to 100 staff)</label>
      <div class="ar-pattern-people" role="group" aria-label="Workers to schedule">
        <?php foreach ($all_workers as $person): ?><label><input type="checkbox" name="user_ids[]" value="<?= (int)$person->id ?>" <?= $chosen_id === (int)$person->id ? 'checked' : '' ?>><span><?= $e($person->first_name . ' ' . $person->last_name) ?> <small class="ar-muted"><?= $e($person->employee_id) ?></small></span></label><?php endforeach; ?>
      </div><small class="ar-muted">Select multiple workers with the same rotation; inspect the first week before publishing. Large teams may need several batches.</small></div>
      <div style="margin-top:12px"><label>Day sequence <small id="arCycleHint" class="ar-muted" style="font-weight:400">Monday first · 7 days</small></label>
      <div class="ar-pattern-days" id="arPatternDays">
      <?php for ($i = 0; $i < 56; $i++): ?>
        <div class="ar-pattern-day" data-day="<?= $i ?>"><span>Day <?= $i + 1 ?><?= $i < 7 ? ' · ' . array('Mon','Tue','Wed','Thu','Fri','Sat','Sun')[$i] : '' ?></span>
          <label for="arPatternDay<?= $i ?>" class="sr-only">Day <?= $i + 1 ?> shift or Off</label>
          <select id="arPatternDay<?= $i ?>" class="form-select" name="pattern[<?= $i ?>]">
            <?php foreach ($shifts as $shift): ?><option value="<?= (int)$shift->id ?>" <?= $i % 7 < 5 && (int)$shift->id === $first_shift ? 'selected' : '' ?>><?= $e($shift->name) ?></option><?php endforeach; ?>
            <option value="Off" <?= $i % 7 >= 5 || !$first_shift ? 'selected' : '' ?>>Off — not on site</option>
          </select>
        </div>
      <?php endfor; ?>
      </div></div>
      <div style="margin-top:12px"><label for="arPatternReason">Revision reason <?= $published ? '(required)' : '(optional for draft)' ?></label><input type="text" id="arPatternReason" name="reason" maxlength="255" class="form-control" <?= $published ? 'required minlength="5"' : '' ?> placeholder="E.g. new operating cycle from January"></div>
      <div class="ar-form-actions"><button class="btn btn-primary btn-sm" type="submit" <?= !$all_workers ? 'disabled' : '' ?>>Generate dated plan</button></div>
    <?= form_close() ?>
    <?php if ($draft): ?>
      <hr style="border:0;border-top:1px solid var(--sep);margin:15px 0"><p class="ar-help"><strong>Existing weekly shifts?</strong> Optionally carry their assigned weeks into this draft as protected dates. A legacy weekly shift is assumed to apply to all seven dates; review and correct Off days before publishing. Gaps stay visible, and no Off days are invented.</p>
      <?= form_open('team/roster_carry_legacy') ?><input type="hidden" name="year" value="<?= (int)$year ?>"><button class="btn btn-ghost btn-sm" type="submit">Carry existing weeks into draft</button><?= form_close() ?>
    <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
</aside>
<main style="min-width:0">
  <?php if (!$chosen && $is_planner): ?>
    <section class="ar-panel" aria-label="Getting started"><div class="ar-panel-head"><h2>Start with a person</h2></div><div class="ar-empty">Choose a worker from the directory to see all 12 months, switch to a detailed month, and edit individual dates. You can generate a rotation for several people at once on the left.</div></section>
  <?php elseif ($chosen): ?>
    <section id="arCalendar" class="ar-panel ar-focus" aria-label="Annual calendar for <?= $e($chosen->first_name . ' ' . $chosen->last_name) ?>">
      <div class="ar-panel-head"><div><h2><?= $e($chosen->first_name . ' ' . $chosen->last_name) ?></h2><p><?= $e($chosen->employee_id) ?> · <?= $e($chosen->position) ?> · <?= (int)$counts['Shift'] ?> shifts · <?= (int)$counts['Off'] ?> Off · <?= (int)$counts['Leave'] ?> leave days</p></div>
        <div class="ar-switch ar-no-print" aria-label="Schedule granularity">
          <a href="<?= $e($link(array('view' => NULL, 'month' => NULL))) ?>#arCalendar" <?= $calendar_view === 'year' ? 'aria-current="page"' : '' ?>>Year</a>
          <a href="<?= $e($link(array('view' => 'month', 'month' => $month))) ?>#arCalendar" <?= $calendar_view === 'month' ? 'aria-current="page"' : '' ?>>Month</a>
          <a href="<?= site_url('team/shift_roster?week=' . $year . '-' . sprintf('%02d', $month) . '-01&q=' . rawurlencode($chosen->employee_id)) ?>">Week ↗</a>
        </div></div>
      <div class="ar-legend"><span><i class="ar-dot ar-shift"></i>Shift</span><span><i class="ar-dot ar-off"></i>Off (unavailable)</span><span><i class="ar-dot ar-leave"></i>Leave</span><span><i class="ar-dot ar-holiday"></i>Holiday</span><span><i class="ar-dot ar-unplanned"></i>Needs plan</span><span>● Extra work is shown <em>on top of</em> Off</span></div>
      <?php if (!$ready || (!$year_row && !$is_planner)): ?>
        <div class="ar-empty">No published plan is available for this year. The <a href="<?= site_url('team/shift_roster') ?>">weekly view</a> may show earlier legacy shifts.</div>
      <?php elseif ($calendar_view === 'year'): ?>
        <div class="ar-year-grid">
        <?php for ($m = 1; $m <= 12; $m++):
          $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
          $grid = $start->modify('monday this week');
          $last = (int)$start->format('t');
          $mini_shifts = 0; foreach (range(1, $last) as $day) { $d = sprintf('%04d-%02d-%02d', $year, $m, $day); if (isset($work[$d]) && $work[$d]['kind'] === 'Shift') $mini_shifts++; }
        ?>
          <article class="ar-mini"><div class="ar-mini-head"><a href="<?= $e($link(array('view' => 'month', 'month' => $m))) ?>#arCalendar"><?= $start->format('F') ?> ↗</a><small><?= $mini_shifts ?> shifts</small></div>
            <div class="ar-mini-grid"><?php foreach (array('M','T','W','T','F','S','S') as $weekday): ?><span class="ar-weekday"><?= $weekday ?></span><?php endforeach; ?>
              <?php for ($n = 0; $n < 42; $n++): $d = $grid->modify('+' . $n . ' days')->format('Y-m-d'); $is_in = substr($d,0,7) === $start->format('Y-m');
                $cell = $is_in && isset($work[$d]) ? $work[$d] : NULL; $before = $chosen->join_date && $d < $chosen->join_date;
              ?>
                <?php if (!$is_in): ?><span class="ar-mute"><?= (int)substr($d,-2) ?></span>
                <?php else: ?><a class="<?= $before ? 'ar-before' : ($cell ? $kind_class($cell) : 'ar-unplanned') ?> <?= $d === $today ? 'ar-today' : '' ?>" href="<?= $e($link(array('view' => 'month','month' => $m))) ?>#day-<?= $d ?>" title="<?= $e($d . ': ' . ($before ? 'Before joining' : ($cell ? $label($cell) . ($cell['extra'] ? ' · authorised extra work' : ($cell['replacement'] ? ' · replacement Off for ' . $cell['replacement']->work_date : '')) : 'Needs plan'))) ?>"><?= (int)substr($d,-2) ?><?= $cell && ($cell['extra'] || $cell['replacement']) ? '•' : '' ?></a><?php endif; ?>
              <?php endfor; ?>
            </div></article>
        <?php endfor; ?>
        </div>
      <?php else:
        $month_start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $grid_start = $month_start->modify('monday this week');
        $grid_end = $month_start->modify('last day of this month')->modify('sunday this week');
        $weeks = (int)($grid_start->diff($grid_end)->days / 7) + 1;
      ?>
        <nav class="ar-month-nav ar-no-print" style="padding:12px 16px;border-bottom:1px solid var(--sep)" aria-label="Months of <?= (int)$year ?>">
          <?php for ($m=1;$m<=12;$m++): ?><a href="<?= $e($link(array('view'=>'month','month'=>$m))) ?>#arCalendar" <?= $month === $m ? 'aria-current="page"' : '' ?>><?= date('M', mktime(12,0,0,$m,1,$year)) ?></a><?php endfor; ?>
        </nav>
        <div class="ar-month-wrap"><h3 style="font-size:17px;margin:0 0 12px;color:var(--t1)"><?= $month_start->format('F Y') ?></h3>
          <div class="ar-month-grid"><span class="ar-weekday">Week</span><?php foreach (array('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') as $weekday): ?><span class="ar-weekday"><?= $weekday ?></span><?php endforeach; ?>
            <?php for ($w = 0; $w < $weeks; $w++): $wk = $grid_start->modify('+' . ($w * 7) . ' days')->format('Y-m-d'); ?>
              <a class="ar-week-link" href="<?= site_url('team/shift_roster?week=' . $wk . '&q=' . rawurlencode($chosen->employee_id)) ?>" title="Open week starting <?= $wk ?>">W<?= date('W', strtotime($wk)) ?> ↗</a>
              <?php for ($n = 0; $n < 7; $n++): $d = $grid_start->modify('+' . ($w * 7 + $n) . ' days')->format('Y-m-d');
                $inside = substr($d,0,7) === $month_start->format('Y-m');
                $cell = isset($work[$d]) ? $work[$d] : NULL;
                $before = $chosen->join_date && $d < $chosen->join_date;
                $class = $before ? 'ar-before' : ($cell ? $kind_class($cell) : 'ar-unplanned');
                $sh = $cell && $cell['kind'] === 'Shift' && isset($shifts_by_id[$cell['shift_id']]) ? $shifts_by_id[$cell['shift_id']] : NULL;
                $extra = $cell ? $cell['extra'] : NULL;
                $replacement = $cell ? $cell['replacement'] : NULL;
              ?>
              <div id="day-<?= $d ?>" class="ar-day <?= $inside ? $class : 'ar-outside' ?> <?= $d === $today ? 'ar-today' : '' ?>"><span class="ar-day-num"><?= date('j', strtotime($d)) ?> <?= !$inside ? date('M',strtotime($d)) : '' ?></span>
                <?php if ($inside): ?><span class="ar-day-state"><?= $e($before ? 'Before joining' : ($cell ? $label($cell) : 'Needs plan')) ?></span>
                  <?php if ($sh): ?><small><?= substr($sh->start_time,0,5) ?>–<?= substr($sh->end_time,0,5) ?></small><?php endif; ?>
                  <?php if ($extra && !$before): ?><small><strong>Off · extra work <?= $extra->status === 'Confirmed' ? 'confirmed' : 'planned' ?></strong><br><?= isset($shifts_by_id[(int)$extra->shift_id]) ? $e($shifts_by_id[(int)$extra->shift_id]->name) : '' ?></small><?php endif; ?>
                  <?php if ($replacement && !$before): ?><small><strong>Replacement Off</strong><br>For work on <?= $e($replacement->work_date) ?> · <?= $e($replacement->settlement) ?></small><?php endif; ?>
                  <?php if ($cell && $cell['covering'] && !$before): ?><small><strong>Covering team Shift</strong> · <?= $cell['covering']->cover_mode === 'OffCallIn' ? 'authorised call-in' : 'on-shift duties' ?></small><?php endif; ?>
                  <?php if ($cell && $cell['covered_by'] && !$before): ?><small><strong>Cover arranged</strong><?= $is_planner ? ' · worker #' . (int)$cell['covered_by']->cover_user_id : '' ?></small><?php endif; ?>
                  <?php if ($is_planner && $cell && $cell['kind'] === 'Leave' && $cell['leave_type'] === 'Sick Leave' && $cell['base_kind'] === 'Shift' && $d >= $today && $published): ?><a href="<?= site_url('team/roster_cover?user=' . $chosen_id . '&date=' . $d) ?>"><?= $cell['covered_by'] ? 'Review cover ↗' : 'Find cover ↗' ?></a>
                  <?php elseif ($can_plan && $d >= $today && !$before && $cell && $cell['kind'] !== 'Leave' && !$extra && !$replacement): ?>
                    <a href="<?= site_url('team/roster_day_edit?user=' . $chosen_id . '&date=' . $d) ?>">Edit day ↗</a>
                  <?php elseif ($can_plan && $cell && $cell['kind'] === 'Leave'): ?><a href="#arLeave">Manage leave ↓</a>
                  <?php elseif ($can_plan && $extra): ?><a href="#arExtra">Manage extra work ↓</a>
                  <?php elseif ($can_plan && $replacement): ?><a href="<?= site_url('team/annual_roster?year=' . (int)substr($replacement->work_date,0,4) . '&user=' . $chosen_id) ?>#arExtra">Manage replacement ↗</a><?php endif; ?>
                <?php endif; ?>
              </div><?php endfor; ?>
            <?php endfor; ?>
          </div>
        </div>
      <?php endif; ?>
    </section>
    <?php if ($ready && $year_row): ?>
    <section id="arLeave" class="ar-panel ar-focus" aria-labelledby="arLeaveTitle"><div class="ar-panel-head"><div><h2 id="arLeaveTitle">Planned leave</h2><p>Scheduled by a supervisor · base shifts remain underneath</p></div><span class="ar-pill ar-leave"><?= count($leaves) ?> blocks</span></div>
      <?php if ($can_plan): ?><div class="ar-panel-body">
        <p class="ar-help">No employee request is required. Published leave removes expected availability and appears in start/return reminders. Only underlying Shift days count against the worker's leave balance; Off and Holiday dates do not.</p>
        <?= form_open('team/roster_leave_save') ?>
          <input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="user_id" value="<?= $chosen_id ?>">
          <div class="ar-fields"><div><label for="arLeaveType">Leave type</label><select id="arLeaveType" name="leave_type" class="form-select" required><?php foreach ($leave_types as $type): ?><option value="<?= $e($type) ?>"><?= $e($type) ?></option><?php endforeach; ?></select></div>
            <div><label for="arLeaveNote">Note (optional)</label><input id="arLeaveNote" name="note" class="form-control" maxlength="255" placeholder="E.g. scheduled annual break"></div>
            <div><label for="arLeaveStart">First day</label><input id="arLeaveStart" type="date" name="start_date" class="form-control" value="<?= $first_day ?>" min="<?= $today ?>" required></div>
            <div><label for="arLeaveEnd">Last day (inclusive)</label><input id="arLeaveEnd" type="date" name="end_date" class="form-control" value="<?= $first_day ?>" min="<?= $today ?>" required></div>
          </div><div class="ar-form-actions"><button type="submit" class="btn btn-primary btn-sm">Schedule leave</button></div>
        <?= form_close() ?>
      </div><?php endif; ?>
      <?php if (!$leaves): ?><div class="ar-empty">No supervisor-planned leave in this year. Underlying Off days are still unavailable.</div><?php endif; ?>
      <?php foreach ($leaves as $leave): ?><div class="ar-record"><span class="ar-pill ar-leave"><?= $e($leave->leave_type) ?></span><strong><?= date('d M Y', strtotime($leave->start_date)) ?> – <?= date('d M Y', strtotime($leave->end_date)) ?></strong>
        <?php if (!empty($leave->partial)): ?><small class="ar-muted"> · Published portion of this year; other dates appear when their year is published.</small><?php endif; ?>
        <?php if ($leave->note): ?><div class="ar-muted" style="margin-top:5px"><?= $e($leave->note) ?></div><?php endif; ?>
        <?php if ($is_planner && $published && $leave->leave_type === 'Sick Leave' && $leave->end_date >= $today): ?><div style="margin-top:8px"><a class="ar-link" href="<?= site_url('team/roster_cover?month=' . substr(max($leave->start_date, $today), 0, 7)) ?>">Find / review sick Shift cover ↗</a></div><?php endif; ?>
        <?php if ($can_plan && $leave->start_date >= $today): ?>
          <details style="margin-top:9px"><summary>Amend future leave</summary>
          <?= form_open('team/roster_leave_amend') ?><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="user_id" value="<?= $chosen_id ?>"><input type="hidden" name="leave_id" value="<?= (int)$leave->id ?>">
            <div class="ar-fields"><div><label for="arAmendType<?= (int)$leave->id ?>">Leave type</label><select id="arAmendType<?= (int)$leave->id ?>" class="form-select" name="leave_type" required><?php foreach ($leave_types as $type): ?><option value="<?= $e($type) ?>" <?= $leave->leave_type === $type ? 'selected' : '' ?>><?= $e($type) ?></option><?php endforeach; ?></select></div>
            <div><label for="arAmendNote<?= (int)$leave->id ?>">Note</label><input id="arAmendNote<?= (int)$leave->id ?>" class="form-control" name="note" maxlength="255" value="<?= $e($leave->note) ?>"></div>
            <div><label for="arAmendStart<?= (int)$leave->id ?>">First day</label><input id="arAmendStart<?= (int)$leave->id ?>" class="form-control" type="date" name="start_date" value="<?= $leave->start_date ?>" min="<?= $today ?>" required></div>
            <div><label for="arAmendEnd<?= (int)$leave->id ?>">Last day</label><input id="arAmendEnd<?= (int)$leave->id ?>" class="form-control" type="date" name="end_date" value="<?= $leave->end_date ?>" required></div>
            <div class="wide"><label for="arAmendReason<?= (int)$leave->id ?>">Reason for amendment</label><input id="arAmendReason<?= (int)$leave->id ?>" class="form-control" name="reason" minlength="5" maxlength="255" required></div></div>
            <div class="ar-form-actions"><button type="submit" class="btn btn-primary btn-sm">Save amendment</button></div><?= form_close() ?>
          </details>
        <?php endif; ?>
        <?php if ($can_edit && $leave->end_date >= $today): ?>
          <?= form_open('team/roster_leave_end', array('class' => 'ar-split', 'onsubmit' => "return confirm('Cancel future leave, or end this active leave early?');")) ?>
            <input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="user_id" value="<?= $chosen_id ?>"><input type="hidden" name="leave_id" value="<?= (int)$leave->id ?>">
            <label class="sr-only" for="arEndReason<?= (int)$leave->id ?>">Reason to cancel or end leave</label><input id="arEndReason<?= (int)$leave->id ?>" class="form-control" style="max-width:260px" name="reason" minlength="5" maxlength="255" placeholder="Reason to cancel/end early" required><button class="btn btn-ghost btn-sm" type="submit"><?= $leave->start_date < $today ? 'End leave early' : 'Cancel future leave' ?></button>
          <?= form_close() ?>
        <?php endif; ?>
      </div><?php endforeach; ?>
      <?php if ($leave_balance): ?><div class="ar-panel-body" style="border-top:1px solid var(--sep);font-size:11px;color:var(--t2)"><strong>Leave balance (<?= (int)$year ?>):</strong> <?php foreach ($leave_balance as $b): if ($b['entitled'] === NULL) continue; ?><?= $e($b['type']) ?> · <?= (int)$b['planned'] ?> planned / <?= $e($b['remaining']) ?> remaining &nbsp; <?php endforeach; ?><br><small>Reservations update when the underlying rotation changes. Actual attendance is recorded separately.</small></div><?php endif; ?>
    </section>
    <section id="arExtra" class="ar-panel ar-focus" aria-labelledby="arExtraTitle"><div class="ar-panel-head"><div><h2 id="arExtraTitle">Work on an Off day</h2><p>Separate exception · original Off remains on the plan</p></div><span class="ar-pill ar-off"><?= count($extras) ?> entries</span></div>
      <?php if ($can_plan && $published): ?><div class="ar-panel-body">
        <p class="ar-help">Authorise the call-in first; the worker is then expected for that extra shift. After a completed attendance day, confirm the work and settle either with external payroll or a dated replacement Off day.</p>
        <?= form_open('team/roster_extra_save') ?><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="user_id" value="<?= $chosen_id ?>">
          <div class="ar-fields"><div><label for="arExtraDate">Published Off date</label><input id="arExtraDate" class="form-control" type="date" name="work_date" min="<?= $today ?>" required></div>
            <div><label for="arExtraShift">Extra-work shift</label><select id="arExtraShift" class="form-select" name="shift_id" required><?php foreach ($shifts as $shift): ?><option value="<?= (int)$shift->id ?>"><?= $e($shift->name) ?> · <?= substr($shift->start_time,0,5) ?>–<?= substr($shift->end_time,0,5) ?></option><?php endforeach; ?></select></div>
            <div><label for="arExtraComp">Compensation route</label><select id="arExtraComp" class="form-select" name="compensation"><option value="TimeOff">Replacement Off day</option><option value="Pay">Overtime pay (external payroll)</option></select></div>
            <div><label for="arExtraReason">Reason for call-in</label><input id="arExtraReason" class="form-control" name="reason" minlength="5" maxlength="255" required placeholder="Reason (minimum 5 characters)"></div>
          </div><div class="ar-form-actions"><button class="btn btn-primary btn-sm" type="submit">Authorise extra work</button></div><?= form_close() ?>
      </div><?php elseif ($can_plan && !$published): ?><div class="ar-panel-body ar-muted">Publish this year before authorising Off-day work so the worker can see the exception.</div><?php endif; ?>
      <?php if (!$extras): ?><div class="ar-empty">No Off-day work exceptions for this worker this year.</div><?php endif; ?>
      <?php foreach ($extras as $ex): $ex_id = (int)$ex->id; $shift = isset($shifts_by_id[(int)$ex->shift_id]) ? $shifts_by_id[(int)$ex->shift_id] : NULL; ?>
        <div class="ar-record"><span class="ar-pill ar-off">Off · <?= $e($ex->status) ?></span>
          <strong><?= date('d M Y', strtotime($ex->work_date)) ?> · <?= $e($shift ? $shift->name : 'Shift') ?></strong>
          <div class="ar-muted" style="margin-top:5px"><?= $ex->compensation === 'Pay' ? 'Pay (external payroll)' : 'Replacement Off day' ?> · <?= $e($ex->settlement) ?><?= $ex->replacement_date ? ' · ' . date('d M Y', strtotime($ex->replacement_date)) : '' ?><?= $ex->confirmed_hours !== NULL ? ' · ' . number_format((float)$ex->confirmed_hours, 2) . 'h confirmed' : '' ?><?= $ex->payroll_reference ? ' · Payroll ref ' . $e($ex->payroll_reference) : '' ?> · <?= $e($ex->reason) ?></div>
          <?php if ($can_edit && $ex->status === 'Planned'): ?>
            <div class="ar-split" style="margin-top:9px">
            <?php if ($ex->work_date <= $today): ?><?= form_open('team/roster_extra_confirm') ?><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="user_id" value="<?= $chosen_id ?>"><input type="hidden" name="extra_id" value="<?= $ex_id ?>"><button type="submit" class="btn btn-primary btn-sm">Confirm from attendance</button><?= form_close() ?><?php endif; ?>
            <?= form_open('team/roster_extra_cancel', array('class' => 'ar-split')) ?><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="user_id" value="<?= $chosen_id ?>"><input type="hidden" name="extra_id" value="<?= $ex_id ?>"><label for="arCancelReason<?= $ex_id ?>" class="sr-only">Reason to cancel extra work</label><input class="form-control" id="arCancelReason<?= $ex_id ?>" style="max-width:215px" name="reason" minlength="5" maxlength="255" required placeholder="Cancellation reason"><button class="btn btn-ghost btn-sm" type="submit">Cancel booking</button><?= form_close() ?>
            </div>
          <?php elseif ($can_edit && $ex->status === 'Confirmed' && $ex->settlement === 'Open'): ?>
            <?= form_open('team/roster_extra_settle', array('class' => 'ar-split')) ?><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="user_id" value="<?= $chosen_id ?>"><input type="hidden" name="extra_id" value="<?= $ex_id ?>"><input type="hidden" name="action" value="<?= $ex->compensation === 'Pay' ? 'pay' : 'schedule' ?>">
              <?php if ($ex->compensation === 'TimeOff'): ?><div><label for="arReplace<?= $ex_id ?>" style="display:block;margin:10px 0 4px">Replacement date (must currently be a published Shift)</label><input type="date" class="form-control" id="arReplace<?= $ex_id ?>" name="replacement_date" min="<?= date('Y-m-d', strtotime($today . ' +1 day')) ?>" required></div><?php else: ?><div><label for="arPayRef<?= $ex_id ?>" style="display:block;margin:10px 0 4px">External payroll reference</label><input class="form-control" id="arPayRef<?= $ex_id ?>" name="reason" minlength="5" maxlength="100" required placeholder="Payroll reference / confirmation"></div><?php endif; ?>
              <button type="submit" class="btn btn-primary btn-sm" style="margin-top:9px"><?= $ex->compensation === 'Pay' ? 'Mark paid' : 'Schedule replacement Off' ?></button>
            <?= form_close() ?>
          <?php elseif ($can_edit && $ex->status === 'Confirmed' && $ex->settlement === 'Scheduled'): ?>
            <div class="ar-split" style="margin-top:9px">
            <?php if ($ex->replacement_date < $today): ?><?= form_open('team/roster_extra_settle') ?><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="user_id" value="<?= $chosen_id ?>"><input type="hidden" name="extra_id" value="<?= $ex_id ?>"><input type="hidden" name="action" value="take"><button type="submit" class="btn btn-primary btn-sm" title="Requires actual Rest recorded on the past replacement date">Mark replacement taken</button><?= form_close() ?><?php elseif ($ex->replacement_date > $today): ?>
              <?= form_open('team/roster_extra_settle') ?><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="user_id" value="<?= $chosen_id ?>"><input type="hidden" name="extra_id" value="<?= $ex_id ?>"><input type="hidden" name="action" value="undo"><input type="hidden" name="reason" value="Replacement rescheduled"><button type="submit" class="btn btn-ghost btn-sm">Undo replacement (restore shift)</button><?= form_close() ?><?php else: ?><small class="ar-muted">Replacement is scheduled for today; record actual Rest after the date passes.</small><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
  <?php endif; ?>
</main>
</div>
<?php if ($is_planner && $ready && $events): ?>
<section class="ar-panel ar-no-print" style="margin-top:16px" aria-labelledby="arAuditTitle"><div class="ar-panel-head"><div><h2 id="arAuditTitle">Planning history</h2><p>Latest <?= count($events) ?> audit events, most recent first</p></div></div>
  <div class="ar-events"><?php foreach ($events as $event): ?><div class="ar-event"><b><?= $e($event->event_type) ?></b> · <?= $e($event->work_date ?: ('Year ' . $event->plan_year)) ?> · <?= $e($event->created_at) ?> · <?= $e($event->actor_name ?: 'Former account') ?>
  <?php if ($event->details): ?><details><summary>Show change details</summary><code style="white-space:pre-wrap;overflow-wrap:anywhere"><?= $e($event->details) ?></code></details><?php endif; ?></div><?php endforeach; ?></div>
</section>
<?php endif; ?>
<script>
(function(){
  var form=document.getElementById('arPatternForm'); if(!form)return;
  var cycle=document.getElementById('arCycle'), days=Array.prototype.slice.call(document.querySelectorAll('#arPatternDays .ar-pattern-day'));
  var hint=document.getElementById('arCycleHint');
  function sync(){var n=Math.max(2,Math.min(56,parseInt(cycle.value,10)||7));
    days.forEach(function(row,i){row.hidden=i>=n;row.querySelector('select').disabled=i>=n;});
    hint.textContent=n%7===0?'Monday first · '+n+' days':'1 January first · '+n+'-day cycle';}
  cycle.addEventListener('input',sync);sync();
  document.querySelectorAll('.ar-preset').forEach(function(button){button.addEventListener('click',function(){
    var n=parseInt(button.dataset.cycle,10),work=parseInt(button.dataset.work,10);cycle.value=n;
    days.forEach(function(row,i){row.querySelector('select').value=(i%n)<work && row.querySelector('select option[value!="Off"]') ? row.querySelector('select option[value!="Off"]').value : 'Off';});sync();
  });});
  form.addEventListener('submit',function(ev){var selected=form.querySelectorAll('input[name="user_ids[]"]:checked').length;
    if(!selected||selected>100){ev.preventDefault();alert('Select between 1 and 100 workers.');}});
})();
</script>
