<?php $attendance_nav_active = 'daily_attendance'; include __DIR__ . '/../attendance/_nav.php'; ?>
<style>
/* Daily Attendance follows the compact, quiet data-table language of /team/report. */
.att-card{background:var(--surface);border:1px solid var(--sep);border-radius:var(--r-lg);box-shadow:var(--shadow-card);overflow:hidden}
.att-card-head{padding:17px 20px;border-bottom:1px solid var(--sep)}
.att-card-head h1{margin:0;color:var(--t1);font-size:21px;line-height:1.25;font-weight:700;letter-spacing:-.025em}
.att-card-head p{margin:4px 0 0;color:var(--t2);font-size:12px;line-height:1.5}
.att-toolbar{padding:10px 16px;background:var(--surface);border-bottom:1px solid var(--sep);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.att-toolbar[hidden],.att-pager[hidden]{display:none!important}
.att-toolbar-main{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.att-search{height:32px;width:min(300px,68vw);padding:0 10px;font-family:var(--font);font-size:12.5px;color:var(--t1);background:var(--surface-2);border:1px solid var(--sep-opaque);border-radius:var(--r-sm);outline:none;transition:border-color var(--ease),background var(--ease),box-shadow var(--ease)}
.att-search:focus,.att-page-size:focus{background:var(--surface);border-color:var(--blue);box-shadow:0 0 0 3px var(--blue-lt)}
.att-page-size{height:32px;padding:0 27px 0 9px;font-family:var(--font);font-size:12.5px;color:var(--t1);background:var(--surface);border:1px solid var(--sep-opaque);border-radius:var(--r-sm);outline:none}
.att-toolbar label{margin:0;color:var(--t3);font-size:11.5px}
.att-summary{color:var(--t3);font-size:12px;font-variant-numeric:tabular-nums}
.att-table-wrap{width:100%;overflow-x:auto}
.att-table{width:100%;min-width:970px;border-collapse:collapse;font-size:13px}
.att-table thead th{position:sticky;top:0;z-index:2;padding:9px 11px;background:var(--surface-2);border-bottom:1px solid var(--sep-opaque);color:var(--t3);font-size:10.5px;font-weight:650;letter-spacing:.04em;text-align:left;text-transform:uppercase;white-space:nowrap}
.att-table tbody tr{border-bottom:1px solid var(--sep);transition:background var(--ease)}
.att-table tbody tr:last-child{border-bottom:0}
.att-table tbody tr:hover{background:#f8f9fb}
.att-table tbody td{padding:9px 11px;vertical-align:middle;color:var(--t1)}
.att-table [hidden]{display:none!important}
.att-worker{display:flex;align-items:center;gap:9px;min-width:205px}
.att-avatar{width:31px;height:31px;flex:0 0 31px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:var(--blue-lt);color:var(--blue);font-size:10px;font-weight:700;letter-spacing:.02em}
.att-worker-name{display:block;color:var(--t1);font-size:12.5px;font-weight:650;line-height:1.25}
.att-worker-meta{display:block;margin-top:3px;color:var(--t3);font-size:10.5px;line-height:1.3}
.att-emp-code{font-family:var(--mono);font-variant-numeric:tabular-nums;color:var(--t2)}
.att-plan{display:inline-flex;align-items:center;padding:3px 8px;border-radius:var(--r-pill);font-size:10.5px;font-weight:600;line-height:1.3;white-space:nowrap;background:var(--fill);color:var(--t2)}
.att-plan-work{background:var(--blue-lt);color:var(--blue)}
.att-plan-off{background:rgba(12,123,123,.1);color:var(--teal)}
.att-plan-leave{background:var(--purple-fill);color:var(--purple)}
.att-plan-holiday{background:var(--amber-fill);color:var(--amber)}
.att-plan-gap{background:var(--red-fill);color:var(--red)}
.att-plan-detail{display:block;margin-top:4px;color:var(--t3);font-size:10px;line-height:1.35}
.att-table .att-status-select{min-width:132px;height:31px;padding:0 8px;font-size:12px}
.att-table .att-time-input{width:104px;height:31px;padding:0 8px;font-size:12px;font-variant-numeric:tabular-nums}
.att-site-yes{display:inline-flex;align-items:center;gap:5px;color:var(--green);font-size:10.5px;font-weight:700;white-space:nowrap}
.att-site-dot{width:7px;height:7px;border-radius:50%;background:var(--green);box-shadow:0 0 0 3px rgba(28,140,60,.1)}
.att-site-no{color:var(--t4);font-size:12px}
.att-no-matches{padding:28px!important;text-align:center;color:var(--t3)!important}
.att-pager{padding:10px 16px;border-top:1px solid var(--sep);background:var(--surface-2);display:flex;align-items:center;justify-content:center;gap:6px}
.att-page-btn{min-width:30px;height:28px;padding:0 9px;border:1px solid var(--sep-opaque);border-radius:6px;background:var(--surface);color:var(--t1);font-size:12px;font-weight:500;cursor:pointer}
.att-page-btn:hover:not(:disabled){background:var(--fill)}
.att-page-btn:disabled{opacity:.45;cursor:default}
.att-page-label{padding:0 6px;color:var(--t2);font-size:12px;font-variant-numeric:tabular-nums}
.att-footer{padding:12px 16px;border-top:1px solid var(--sep);background:var(--surface-2);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.att-footer-note{max-width:760px;color:var(--t3);font-size:11.5px;line-height:1.45}
@media(max-width:700px){.att-card-head{padding:14px 15px}.att-toolbar{padding:10px 12px}.att-footer{padding:12px}.att-footer-note{width:100%}}
@media print{.att-toolbar,.att-pager,.att-footer{display:none!important}.att-card{border:0;box-shadow:none}.att-table-wrap{overflow:visible}.att-table{min-width:0}.att-table th,.att-table td{padding:5px 7px!important;font-size:10px!important}}
</style>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <form method="get" class="flex ai-c gap-10">
    <input type="date" name="date" class="form-control" style="width:auto;" value="<?= $date ?>" max="<?= date('Y-m-d') ?>">
    <button type="submit" class="btn btn-primary btn-sm">Load</button>
    <a href="<?= base_url('team/attendance?date='.date('Y-m-d')) ?>" class="btn btn-ghost btn-sm">Today</a>
  </form>
  <div class="flex ai-c gap-10">
    <?php if (!empty($punching)): ?>
      <a href="<?= base_url('attendance/log?date='.rawurlencode((string)$date)) ?>" class="btn btn-ghost btn-sm">Check-in Log →</a>
    <?php endif; ?>
    <a href="<?= site_url('team/shift_roster?week=' . rawurlencode((string)$date)) ?>" class="btn btn-ghost btn-sm">Weekly plan →</a>
    <a href="<?= base_url('team/report?month=' . rawurlencode(substr((string)$date, 0, 7))) ?>" class="btn btn-primary btn-sm">AML Timesheets &amp; Report →</a>
  </div>
</div>

<div class="att-card">
  <div class="att-card-head">
    <h1>Attendance — <?= date('l, d F Y',strtotime($date)) ?></h1>
    <p><?= isset($workers)?count($workers):0 ?> workers · planned expectation is reference only. Select an actual outcome to record attendance.</p>
  </div>
  <?= form_open('team/attendance') ?>
  <input type="hidden" name="date" value="<?= htmlspecialchars((string)($date), ENT_QUOTES, 'UTF-8') ?>">
  <div id="attWorkerTools" class="att-toolbar" hidden>
    <div class="att-toolbar-main">
      <label for="attWorkerSearch" class="sr-only">Search team members</label>
      <input id="attWorkerSearch" type="search" class="att-search" placeholder="Search staff, ID, position…">
      <label for="attWorkerPageSize">Rows:</label>
      <select id="attWorkerPageSize" class="att-page-size">
        <option value="25" selected>25 / page</option><option value="50">50 / page</option><option value="100">100 / page</option><option value="all">All</option>
      </select>
    </div>
    <span id="attWorkerSummary" class="att-summary" aria-live="polite"></span>
  </div>
  <div class="att-table-wrap">
    <table class="att-table">
      <thead>
        <tr><th scope="col">Staff Member</th><th scope="col">Published Plan</th><th scope="col">Actual Status</th><th scope="col">Check In</th><th scope="col">Check Out</th><th scope="col">On Site</th></tr>
      </thead>
      <tbody>
        <?php if (isset($workers) && count($workers) > 0): foreach ($workers as $w):
          // get_attendance_by_date() returns rows as arrays keyed by user ID.
          // Cast the existing row once for this template; do not silently
          // default recorded attendance to Present (or to an empty state).
          $att = isset($attendance[(int)$w->id]) ? (object)$attendance[(int)$w->id] : NULL;
          $cell = isset($plan_days[(int)$w->id][$date]) ? $plan_days[(int)$w->id][$date] : NULL;
          $expected = $cell ? ($cell['kind'] === 'Leave' ? $cell['leave_type'] : $cell['kind']) : 'No published plan';
          if ($cell && $cell['plan_status'] === 'Legacy' && $cell['kind'] === 'Unplanned') $expected = 'No published plan';
          elseif ($cell && $cell['plan_status'] === 'Legacy') $expected .= ' · legacy';
          $initials = strtoupper(substr((string)$w->first_name, 0, 1) . substr((string)$w->last_name, 0, 1));
          $plan_class = 'att-plan';
          if ($cell && $cell['kind'] === 'Shift') $plan_class .= ' att-plan-work';
          elseif ($cell && $cell['kind'] === 'Off') $plan_class .= ' att-plan-off';
          elseif ($cell && $cell['kind'] === 'Leave') $plan_class .= ' att-plan-leave';
          elseif ($cell && $cell['kind'] === 'Holiday') $plan_class .= ' att-plan-holiday';
          elseif ($cell && $cell['kind'] === 'Unplanned') $plan_class .= ' att-plan-gap';
        ?>
        <tr data-attendance-worker data-search="<?= htmlspecialchars(trim((string)$w->employee_id . ' ' . (string)$w->first_name . ' ' . (string)$w->last_name . ' ' . (string)$w->position), ENT_QUOTES, 'UTF-8') ?>">
          <td><div class="att-worker"><span class="att-avatar" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span><span><strong class="att-worker-name"><?= htmlspecialchars($w->first_name.' '.$w->last_name, ENT_QUOTES, 'UTF-8') ?></strong><small class="att-worker-meta"><span class="att-emp-code"><?= htmlspecialchars((string)$w->employee_id, ENT_QUOTES, 'UTF-8') ?></span> · <?= htmlspecialchars((string)$w->position, ENT_QUOTES, 'UTF-8') ?></small></span></div></td>
          <td><span class="<?= $plan_class ?>" title="Scheduled expectation, not actual attendance"><?= htmlspecialchars((string)$expected, ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($cell && $cell['kind'] === 'Off' && $cell['extra']): ?><small class="att-plan-detail">Authorised extra work · <?= htmlspecialchars((string)$cell['extra']->status, ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
            <?php if ($cell && !empty($cell['covering'])): ?><small class="att-plan-detail">Covering sick Shift · <?= $cell['covering']->cover_mode === 'OffCallIn' ? 'authorised Off call-in' : 'on-shift duty' ?></small><?php endif; ?>
            <?php if ($cell && !empty($cell['covered_by'])): ?><small class="att-plan-detail" style="color:var(--green);font-weight:650">Cover arranged</small><?php endif; ?>
          </td>
          <td>
            <label class="sr-only" for="attStatus<?= (int)$w->id ?>">Actual status for <?= htmlspecialchars($w->first_name.' '.$w->last_name, ENT_QUOTES, 'UTF-8') ?></label>
            <select id="attStatus<?= (int)$w->id ?>" name="attendance[<?= (int)$w->id ?>][status]" class="form-select att-status-select">
              <option value="" <?= !$att ? 'selected' : 'disabled' ?>>Not recorded</option>
              <?php foreach (['Present','Absent','Leave','Rest','Holiday'] as $s): ?>
              <option value="<?= $s ?>" <?= ($att && $att->status === $s) ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="time" name="attendance[<?= (int)$w->id ?>][check_in]" class="form-control att-time-input" value="<?= $att && !empty($att->check_in) ? htmlspecialchars((string)(substr((string)$att->check_in, 0, 5)), ENT_QUOTES, 'UTF-8') : '' ?>"></td>
          <td><input type="time" name="attendance[<?= (int)$w->id ?>][check_out]" class="form-control att-time-input" value="<?= $att && !empty($att->check_out) ? htmlspecialchars((string)(substr((string)$att->check_out, 0, 5)), ENT_QUOTES, 'UTF-8') : '' ?>"></td>
          <td>
            <?php if (isset($on_site[(int)$w->id])): ?>
              <span class="att-site-yes"><i class="att-site-dot" aria-hidden="true"></i>ON SITE</span>
            <?php else: ?>
              <span class="att-site-no">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="6" class="att-no-matches">No workers found</td></tr>
        <?php endif; ?>
        <tr id="attNoWorkerMatches" hidden><td colspan="6" class="att-no-matches">No team members match this search.</td></tr>
      </tbody>
    </table>
  </div>
  <div id="attWorkerPager" class="att-pager" hidden>
    <button id="attWorkerPrev" type="button" class="att-page-btn" aria-label="Previous attendance page">‹</button>
    <span id="attWorkerPage" class="att-page-label" aria-live="polite"></span>
    <button id="attWorkerNext" type="button" class="att-page-btn" aria-label="Next attendance page">›</button>
  </div>
  <div class="att-footer">
    <small class="att-footer-note">Search and pages only change what is visible. Saving submits the full team form; blank “Not recorded” rows remain untouched.</small>
    <button type="submit" class="btn btn-primary">Save Attendance</button>
  </div>
  <?= form_close() ?>
</div>
<script>
(function(){
  var rows=Array.prototype.slice.call(document.querySelectorAll('tr[data-attendance-worker]'));
  if(!rows.length)return;
  var tools=document.getElementById('attWorkerTools'), search=document.getElementById('attWorkerSearch');
  var size=document.getElementById('attWorkerPageSize'), summary=document.getElementById('attWorkerSummary');
  var pager=document.getElementById('attWorkerPager'), previous=document.getElementById('attWorkerPrev');
  var next=document.getElementById('attWorkerNext'), pageLabel=document.getElementById('attWorkerPage');
  var noMatches=document.getElementById('attNoWorkerMatches'), page=1;
  tools.hidden=false;
  function render(){
    var query=search.value.trim().toLowerCase();
    var matches=rows.filter(function(row){return (row.getAttribute('data-search')||'').toLowerCase().indexOf(query)!==-1;});
    var pageSize=size.value==='all'?Math.max(matches.length,1):Math.max(1,parseInt(size.value,10)||25);
    var pageCount=Math.max(1,Math.ceil(matches.length/pageSize));
    page=Math.max(1,Math.min(page,pageCount));
    var start=(page-1)*pageSize, end=Math.min(start+pageSize,matches.length);
    rows.forEach(function(row){row.hidden=true;});
    matches.slice(start,end).forEach(function(row){row.hidden=false;});
    noMatches.hidden=matches.length!==0;
    summary.textContent=matches.length?'Showing '+(start+1)+'–'+end+' of '+matches.length+' team members':'Showing 0 of 0 team members';
    pager.hidden=pageCount<=1;
    pageLabel.textContent='Page '+page+' of '+pageCount;
    previous.disabled=page<=1;next.disabled=page>=pageCount;
  }
  search.addEventListener('input',function(){page=1;render();});
  size.addEventListener('change',function(){page=1;render();});
  previous.addEventListener('click',function(){page--;render();});
  next.addEventListener('click',function(){page++;render();});
  render();
})();
</script>
