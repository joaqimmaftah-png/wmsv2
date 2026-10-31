<?php
$report = isset($report) && is_array($report) ? $report : array();
$week = isset($report['week']) ? $report['week'] : array();
$fleet = isset($report['fleet']) ? $report['fleet'] : array();
$roll = isset($fleet['rollup']) ? $fleet['rollup'] : array();
$groups = isset($report['groups']) ? $report['groups'] : array();
$machines = isset($report['machines']) ? $report['machines'] : array();
$days = isset($week['days']) ? $week['days'] : array();
$quality = isset($report['data_quality']) ? $report['data_quality'] : array();
$report_name = isset($report['report_name']) ? $report['report_name'] : 'Weekly report';
$week_label = isset($week['iso_label']) ? $week['iso_label'] : '';
$week_start = isset($week['start']) ? $week['start'] : (isset($week_start) ? $week_start : '');
$esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$fmt = function ($v, $d = 1) { return $v === null || $v === '' ? '—' : number_format((float)$v, $d, '.', ','); };
$pct = function ($v, $d = 1) { return $v === null || $v === '' ? '—' : number_format((float)$v * 100, $d, '.', ',') . '%'; };
$target = isset($roll['weekly_target']) ? $roll['weekly_target'] : null;
$availability = isset($roll['availability']) ? $roll['availability'] : null;
$live_export = empty($snapshot) && !empty($published) ? '&live=1' : '';
$quality_errors = 0; $quality_warns = 0;
foreach ($quality as $issue) {
    if (isset($issue['level']) && $issue['level'] === 'error') $quality_errors++;
    elseif (isset($issue['level']) && $issue['level'] === 'warn') $quality_warns++;
}
$fleet_nav_active = 'dashboard';
?>
<div class="fa-wrap">
  <?php $this->load->view('reports/_fleet_nav', array('fleet_nav_active' => $fleet_nav_active, 'week_start' => $week_start)); ?>
  <header class="fa-head">
    <div>
      <p class="fa-eyebrow">Fleet performance · <?= $esc($week_label) ?><?= !empty($snapshot) ? ' · Published snapshot' : ' · Live reading' ?></p>
      <h1>Fleet availability</h1>
      <p class="fa-sub"><?= $esc(isset($week['label']) ? $week['label'] : '') ?> · <?= (int)(isset($report['meta']['machines']) ? $report['meta']['machines'] : count($machines)) ?> reportable assets · based on the W36 KPI calculation</p>
    </div>
    <div class="fa-actions">
      <a class="fa-button" href="<?= base_url('reports/export_weekly_w36_xlsx?week=' . rawurlencode($week_label) . $live_export) ?>">Download Excel</a>
      <a class="fa-button" target="_blank" rel="noopener" href="<?= base_url('reports/export_weekly_w36_pdf?week=' . rawurlencode($week_label) . (empty($snapshot) && !empty($published) ? '&live=1' : '')) ?>">Print / PDF</a>
    </div>
  </header>

  <?php if (!empty($notice)): ?><div class="fa-notice fa-notice-warn" role="status"><?= $esc($notice) ?></div><?php endif; ?>
  <?php if (!empty($snapshot)): ?>
    <div class="fa-notice fa-notice-good"><strong>Published report.</strong> Values are frozen from <?= $esc(isset($snapshot['published_at']) ? $snapshot['published_at'] : '') ?>. <a href="<?= base_url('reports/fleet_availability?week=' . rawurlencode($week_label) . '&live=1') ?>">View live reading</a>.</div>
  <?php elseif (!empty($published)): ?>
    <div class="fa-notice fa-notice-warn"><strong>Live recalculation.</strong> This period has a published snapshot; this page is showing current data. <a href="<?= base_url('reports/fleet_availability?week=' . rawurlencode($week_label)) ?>">Open published version</a>.</div>
  <?php else: ?>
    <div class="fa-notice fa-notice-info"><strong>Draft live report.</strong> This report is recalculated from current WMS records and has not been published.</div>
  <?php endif; ?>

  <form class="fa-period" method="get" action="<?= base_url('reports/fleet_availability') ?>">
    <?php if ($live_export !== ''): ?><input type="hidden" name="live" value="1"><?php endif; ?>
    <a class="fa-button fa-button-quiet" href="<?= base_url('reports/fleet_availability?week=' . rawurlencode(isset($prev_week) ? $prev_week : '') . $live_export) ?>" aria-label="Previous reporting week">← <?= $esc(isset($prev_week) ? $prev_week : '') ?></a>
    <label>Reporting week <input type="week" name="week" value="<?= $esc($week_label) ?>"></label>
    <button class="fa-button fa-button-primary" type="submit">Show week</button>
    <a class="fa-button fa-button-quiet" href="<?= base_url('reports/fleet_availability?week=' . rawurlencode(isset($next_week) ? $next_week : '') . $live_export) ?>" aria-label="Next reporting week"><?= $esc(isset($next_week) ? $next_week : '') ?> →</a>
    <span class="fa-period-meta"><?= $esc($fmt(isset($week['open_hours']) ? $week['open_hours'] : null, 0)) ?> configured open h · <?= $esc($fmt(isset($week['daily_open_hours']) ? $week['daily_open_hours'] : null, 0)) ?> h/day</span>
  </form>

  <?php if ($availability === null): ?>
    <div class="fa-notice fa-notice-warn"><strong>Availability is not defined for this period.</strong> The WMS has no positive required-time denominator for the reportable fleet. The report does not substitute 100%.</div>
  <?php endif; ?>
  <section class="fa-kpis" aria-label="Fleet weekly key performance indicators">
    <article class="fa-kpi fa-kpi-lead"><span>Operational availability</span><strong><?= $esc($pct($availability)) ?></strong><div class="fa-kpi-meta">Target <?= $esc($pct($target)) ?><?php if ($availability !== null && $target !== null): ?> · <?= $availability >= $target ? 'on or above target' : 'below target' ?><?php endif; ?></div>
      <?php if ($availability !== null): ?><div class="fa-meter" aria-label="Availability <?= $esc($pct($availability)) ?>"><i style="width:<?= max(0, min(100, (float)$availability * 100)) ?>%"></i><?php if ($target !== null): ?><b style="left:<?= max(0, min(100, (float)$target * 100)) ?>%" title="Target"></b><?php endif; ?></div><?php endif; ?>
    </article>
    <article class="fa-kpi"><span>Breakdown downtime</span><strong><?= $esc($fmt(isset($roll['downtime_hours']) ? $roll['downtime_hours'] : null)) ?> <small>h</small></strong><div class="fa-kpi-meta">Merged event intervals within the selected week</div></article>
    <article class="fa-kpi"><span>Breakdown events</span><strong><?= isset($roll['breakdowns']) ? number_format((int)$roll['breakdowns']) : '—' ?></strong><div class="fa-kpi-meta">Distinct events, not work-order count</div></article>
    <article class="fa-kpi"><span>MTBF</span><strong><?= $esc($fmt(isset($roll['mtbf_hours']) ? $roll['mtbf_hours'] : null)) ?> <small>h</small></strong><div class="fa-kpi-meta">Mean time between counted events</div></article>
    <article class="fa-kpi"><span>MTTR</span><strong><?= $esc($fmt(isset($roll['mttr_hours']) ? $roll['mttr_hours'] : null)) ?> <small>h</small></strong><div class="fa-kpi-meta">Merged downtime ÷ counted events</div></article>
  </section>

  <section class="fa-grid">
    <article class="fa-panel">
      <div class="fa-panel-head"><div><h2>Availability by equipment group</h2><p>Required-hour-weighted rollups. Select a group to filter the asset list below.</p></div></div>
      <?php $has_group_rows = false; foreach ($groups as $g) if (!empty($g['row_count'])) $has_group_rows = true; ?>
      <?php if ($has_group_rows): ?>
        <div class="fa-bars">
          <?php foreach ($groups as $g): if (empty($g['row_count'])) continue;
            $groll = isset($g['rollup']) ? $g['rollup'] : array();
            $gav = isset($groll['availability']) ? $groll['availability'] : null;
            $gt = isset($groll['weekly_target']) ? $groll['weekly_target'] : null;
            $label = isset($g['label']) ? $g['label'] : $g['code']; ?>
            <button type="button" class="fa-group-row" data-filter-group="<?= $esc($label) ?>" aria-label="Filter assets by <?= $esc($label) ?>">
              <span class="fa-group-name"><?= $esc($label) ?></span><span class="fa-group-track"><i style="width:<?= $gav === null ? 0 : max(0,min(100,(float)$gav*100)) ?>%" class="<?= $gav === null ? 'is-unknown' : (($gt !== null && $gav >= $gt) ? 'is-good' : 'is-low') ?>"></i><?php if ($gt !== null): ?><b style="left:<?= max(0,min(100,(float)$gt*100)) ?>%"></b><?php endif; ?></span><span class="fa-group-value"><?= $esc($pct($gav, 0)) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      <?php else: ?><div class="fa-empty">No reportable equipment groups are available for this period.</div><?php endif; ?>
    </article>
    <article class="fa-panel">
      <div class="fa-panel-head"><div><h2>Daily fleet trend</h2><p>Same required-time-weighted KPI, resolved from the selected report week.</p></div></div>
      <?php if (!empty($days)): ?><div class="fa-trend" role="img" aria-label="Daily fleet availability chart">
        <?php foreach ($days as $day): $daily = isset($fleet['daily'][$day]) ? $fleet['daily'][$day] : null; $dav = $daily && isset($daily['availability']) ? $daily['availability'] : null; ?>
          <div class="fa-day"><div class="fa-day-track"><i style="height:<?= $dav === null ? 0 : max(0,min(100,(float)$dav*100)) ?>%"></i></div><strong><?= $esc($pct($dav, 0)) ?></strong><span><?= $esc(date('D', strtotime($day))) ?></span></div>
        <?php endforeach; ?>
      </div><div class="fa-chart-note">Daily availability is shown as “—” when the denominator is unavailable.</div><?php else: ?><div class="fa-empty">No daily values are available.</div><?php endif; ?>
    </article>
  </section>

  <?php if ($quality_errors || $quality_warns): ?>
    <section class="fa-quality <?= $quality_errors ? 'has-error' : '' ?>" aria-label="Data quality summary">
      <div><strong><?= $quality_errors ? 'Data quality needs review' : 'Data quality notes' ?></strong><p><?= $quality_errors ?> error(s) · <?= $quality_warns ?> warning(s). Review the detailed notes in the weekly report before publication.</p></div>
      <a class="fa-button" href="<?= base_url('reports/weekly_w36?week=' . rawurlencode($week_label)) ?>">Review weekly report</a>
    </section>
  <?php endif; ?>

  <section class="fa-panel fa-equipment-panel">
    <div class="fa-panel-head"><div><h2>Equipment performance</h2><p>Availability, downtime and distinct failures for each reportable asset.</p></div><a class="fa-text-link" href="<?= base_url('reports/downtime_log?week=' . rawurlencode($week_label)) ?>">View downtime log →</a></div>
    <div class="fa-filters">
      <label>Search equipment<input id="fa-search" type="search" placeholder="Asset, model or location" autocomplete="off"></label>
      <label>Group<select id="fa-group"><option value="">All groups</option><?php foreach ($groups as $g): if (empty($g['row_count'])) continue; ?><option><?= $esc(isset($g['label']) ? $g['label'] : $g['code']) ?></option><?php endforeach; ?></select></label>
      <label>Location<select id="fa-location"><option value="">All locations</option><?php foreach ((array)(isset($report['sites']) ? $report['sites'] : array()) as $site): ?><option><?= $esc($site) ?></option><?php endforeach; ?></select></label>
      <label class="fa-check"><input id="fa-below" type="checkbox"> Below target only</label>
      <button type="button" id="fa-clear" class="fa-button fa-button-quiet">Clear filters</button>
      <span id="fa-count" role="status" aria-live="polite"></span>
    </div>
    <div class="fa-table-scroll" tabindex="0" role="region" aria-label="Equipment performance table, horizontally scrollable">
      <table class="fa-table"><thead><tr><th>Equipment</th><th>Group</th><th>Location</th><th>Weekly availability</th><th>Target</th><th>Downtime h</th><th>Breakdowns</th><th>MTBF h</th><th>MTTR h</th></tr></thead><tbody id="fa-machines">
      <?php foreach ($machines as $id => $m):
        $w = isset($m['weekly']) ? $m['weekly'] : array();
        $av = isset($w['availability']) ? $w['availability'] : null;
        $tg = isset($w['weekly_target']) ? $w['weekly_target'] : null;
        $loc = isset($m['site']['label']) ? $m['site']['label'] : '';
        $group = isset($m['group_label']) && $m['group_label'] !== null ? $m['group_label'] : '';
        $down = isset($w['downtime_hours']) ? $w['downtime_hours'] : null;
        $fails = isset($w['breakdowns']) ? (int)$w['breakdowns'] : null;
        $search = (isset($m['machine_id']) ? $m['machine_id'] : '') . ' ' . (isset($m['model']) ? $m['model'] : '') . ' ' . $loc;
        $below = $av !== null && $tg !== null && $av < $tg;
      ?>
        <tr data-machine="<?= $esc($search) ?>" data-group="<?= $esc($group) ?>" data-location="<?= $esc($loc) ?>" data-below="<?= $below ? '1' : '0' ?>">
          <td><a href="<?= base_url('equipment/view/' . (int)$id) ?>" class="fa-asset"><?= $esc(isset($m['machine_id']) ? $m['machine_id'] : '') ?></a><small><?= $esc(isset($m['model']) ? $m['model'] : '') ?></small></td>
          <td><?= $esc($group ?: 'Unclassified') ?></td><td><?= $esc($loc ?: '—') ?></td>
          <td><span class="fa-value-pill <?= $below ? 'is-below' : '' ?>"><?= $esc($pct($av)) ?></span></td>
          <td><?= $esc($pct($tg)) ?></td><td><?= $esc($fmt($down)) ?></td><td><?= $fails === null ? '—' : number_format($fails) ?></td>
          <td><?= $esc($fmt(isset($w['mtbf_hours']) ? $w['mtbf_hours'] : null)) ?></td><td><?= $esc($fmt(isset($w['mttr_hours']) ? $w['mttr_hours'] : null)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
    <?php if (empty($machines)): ?><div class="fa-empty">No reportable equipment rows were returned. No fleet KPI is shown.</div><?php endif; ?>
  </section>
  <p class="fa-footnote">Availability = (required time − union of breakdown intervals) ÷ required time. Required time and preventive labor follow WMS settings and KPI rules; target resolution, zero-failure handling and event boundaries are shared with Weekly W36. This is not a separate calculation.</p>
</div>
<style>
.fa-wrap{max-width:1480px;margin:0 auto;padding:0 0 32px;color:var(--t1,#111827)}
.fa-head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin:0 0 16px}.fa-head h1{font-size:26px;line-height:1.1;margin:0}.fa-eyebrow{margin:0 0 5px;color:var(--blue,#0071e3);font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.fa-sub{color:var(--t3,#64748b);margin:5px 0 0;font-size:12px}.fa-actions{display:flex;gap:8px;flex-wrap:wrap}
.fa-button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 12px;border:1px solid var(--sep,#dbe1e8);border-radius:8px;background:var(--g1,#fff);color:var(--t1,#111827);font-size:12px;font-weight:600;text-decoration:none;cursor:pointer}.fa-button:hover{border-color:var(--blue,#0071e3);color:var(--blue,#0071e3)}.fa-button-primary{background:var(--blue,#0071e3);border-color:var(--blue,#0071e3);color:#fff}.fa-button-primary:hover{color:#fff;filter:brightness(1.04)}.fa-button-quiet{background:transparent}
.fa-notice{padding:11px 14px;border-radius:9px;margin:10px 0;border:1px solid var(--sep,#dbe1e8);font-size:12px}.fa-notice a{font-weight:600}.fa-notice-info{background:var(--blue-d,#eff6ff);border-color:#bfdbfe}.fa-notice-good{background:var(--green-d,#f0fdf4);border-color:#bbf7d0}.fa-notice-warn{background:var(--amber-d,#fffbeb);border-color:#fde68a}
.fa-period{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 12px;border:1px solid var(--sep,#dbe1e8);border-radius:10px;background:var(--g1,#fff);margin:12px 0 16px}.fa-period label{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:600}.fa-period input{height:36px;border:1px solid var(--sep,#dbe1e8);border-radius:7px;padding:4px 8px;background:var(--g1,#fff);color:inherit}.fa-period-meta{margin-left:auto;color:var(--t3,#64748b);font-size:11px}
.fa-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:0 0 14px}.fa-kpi{padding:15px 16px;border:1px solid var(--sep,#e2e8f0);border-radius:11px;background:var(--g1,#fff);min-width:0}.fa-kpi>span{display:block;color:var(--t3,#64748b);font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase}.fa-kpi strong{display:block;margin:8px 0 4px;font-size:27px;line-height:1.05;font-variant-numeric:tabular-nums}.fa-kpi strong small{font-size:13px;color:var(--t3,#64748b);font-weight:500}.fa-kpi-meta{font-size:10.5px;color:var(--t3,#64748b);line-height:1.45}.fa-kpi-lead{background:linear-gradient(135deg,var(--blue-d,#eff6ff),var(--g1,#fff));border-color:#bfdbfe}.fa-meter{height:7px;background:var(--g2,#e5e7eb);border-radius:99px;position:relative;margin-top:12px}.fa-meter i{position:absolute;inset:0 auto 0 0;background:var(--blue,#0071e3);border-radius:99px}.fa-meter b{position:absolute;top:-4px;bottom:-4px;width:2px;background:var(--t1,#111827)}
.fa-grid{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(320px,.95fr);gap:12px;margin-bottom:14px}.fa-panel{border:1px solid var(--sep,#e2e8f0);border-radius:11px;background:var(--g1,#fff);padding:15px;min-width:0}.fa-panel-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px}.fa-panel h2{font-size:15px;margin:0}.fa-panel-head p{font-size:11px;color:var(--t3,#64748b);margin:4px 0 0;line-height:1.45}.fa-bars{display:grid;gap:8px}.fa-group-row{display:grid;grid-template-columns:minmax(90px,130px) minmax(80px,1fr) 54px;align-items:center;gap:9px;padding:3px 0;background:transparent;border:0;text-align:left;color:inherit;cursor:pointer}.fa-group-row:hover .fa-group-name{color:var(--blue,#0071e3)}.fa-group-name{font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.fa-group-track{height:12px;background:var(--g2,#e5e7eb);border-radius:3px;position:relative}.fa-group-track i{position:absolute;inset:0 auto 0 0;border-radius:3px;background:#0b8063}.fa-group-track i.is-low{background:#c84b42}.fa-group-track i.is-unknown{background:#94a3b8}.fa-group-track b{position:absolute;top:-3px;bottom:-3px;width:2px;background:var(--t1,#111827)}.fa-group-value{text-align:right;font-size:11px;font-variant-numeric:tabular-nums}
.fa-trend{height:170px;display:grid;grid-template-columns:repeat(7,minmax(24px,1fr));gap:8px;align-items:end;padding:0 3px}.fa-day{height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:4px;font-size:9px;color:var(--t3,#64748b)}.fa-day-track{height:112px;width:min(100%,34px);background:var(--g2,#e5e7eb);border-radius:4px 4px 2px 2px;display:flex;align-items:flex-end;overflow:hidden}.fa-day-track i{width:100%;background:var(--blue,#0071e3);min-height:0}.fa-day strong{font-size:10px;color:var(--t1,#111827);font-variant-numeric:tabular-nums}.fa-chart-note{font-size:10px;color:var(--t3,#64748b);margin-top:8px}.fa-quality{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;border:1px solid #fde68a;background:var(--amber-d,#fffbeb);border-radius:10px;margin:0 0 14px}.fa-quality.has-error{border-color:#fecaca;background:var(--red-d,#fef2f2)}.fa-quality strong{font-size:12px}.fa-quality p{margin:3px 0 0;font-size:11px;color:var(--t3,#64748b)}.fa-text-link{font-size:11px;color:var(--blue,#0071e3);font-weight:600;text-decoration:none;white-space:nowrap}
.fa-filters{display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;margin:8px 0 10px}.fa-filters label{display:grid;gap:4px;font-size:10px;font-weight:600;color:var(--t3,#64748b)}.fa-filters input[type=search],.fa-filters select{height:34px;min-width:135px;max-width:230px;border:1px solid var(--sep,#dbe1e8);border-radius:7px;padding:5px 8px;background:var(--g1,#fff);color:var(--t1,#111827);font:inherit}.fa-filters .fa-check{display:flex;align-items:center;gap:6px;min-height:34px}.fa-filters #fa-count{font-size:10px;color:var(--t3,#64748b);margin-left:auto}.fa-table-scroll{overflow:auto;border:1px solid var(--sep,#e2e8f0);border-radius:8px}.fa-table{width:100%;border-collapse:collapse;font-size:11px;min-width:800px}.fa-table th{position:sticky;top:0;background:var(--g2,#f1f5f9);text-align:right;padding:9px 8px;color:var(--t3,#64748b);font-size:9px;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap}.fa-table th:first-child,.fa-table td:first-child{text-align:left}.fa-table td{text-align:right;padding:8px;border-top:1px solid var(--sep,#edf0f4);font-variant-numeric:tabular-nums;white-space:nowrap}.fa-table td:first-child{white-space:normal}.fa-table tr:hover td{background:var(--blue-d,#f8fbff)}.fa-table small{display:block;color:var(--t3,#64748b);font-size:9.5px;margin-top:2px}.fa-asset{color:var(--blue,#0071e3);font-family:monospace;font-weight:700;text-decoration:none}.fa-value-pill{font-weight:700}.fa-value-pill.is-below{color:#b42318}.fa-empty{padding:18px;border:1px dashed var(--sep,#dbe1e8);border-radius:8px;text-align:center;color:var(--t3,#64748b);font-size:12px}.fa-footnote{font-size:10px;color:var(--t3,#64748b);line-height:1.55;margin:12px 2px}.fa-table tr[hidden]{display:none!important}
@media(max-width:1050px){.fa-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.fa-grid{grid-template-columns:1fr}}
@media(max-width:650px){.fa-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.fa-kpi{padding:12px}.fa-kpi-lead{grid-column:span 2}.fa-kpi strong{font-size:23px}.fa-head h1{font-size:23px}.fa-period-meta{width:100%;margin-left:0}.fa-quality{align-items:flex-start;flex-direction:column}.fa-group-row{grid-template-columns:90px minmax(50px,1fr) 47px}.fa-wrap{padding-inline:0}}
@media(prefers-reduced-motion:reduce){.fa-wrap *{scroll-behavior:auto!important;transition:none!important}}
</style>
<script>
(function(){
  var body=document.getElementById('fa-machines'); if(!body)return;
  var rows=Array.prototype.slice.call(body.querySelectorAll('tr[data-machine]'));
  var search=document.getElementById('fa-search'),group=document.getElementById('fa-group'),loc=document.getElementById('fa-location'),below=document.getElementById('fa-below'),count=document.getElementById('fa-count');
  function apply(){var q=(search.value||'').trim().toLocaleLowerCase(),g=group.value,l=loc.value,b=below.checked,n=0;rows.forEach(function(r){var ok=(!q||r.dataset.machine.toLocaleLowerCase().indexOf(q)!==-1)&&(!g||r.dataset.group===g)&&(!l||r.dataset.location===l)&&(!b||r.dataset.below==='1');r.hidden=!ok;if(ok)n++;});count.textContent=n+' of '+rows.length+' equipment rows';}
  [search,group,loc,below].forEach(function(x){x.addEventListener('input',apply);x.addEventListener('change',apply);});
  document.getElementById('fa-clear').addEventListener('click',function(){search.value='';group.value='';loc.value='';below.checked=false;apply();search.focus();});
  document.querySelectorAll('[data-filter-group]').forEach(function(x){x.addEventListener('click',function(){group.value=x.dataset.filterGroup;apply();document.querySelector('.fa-equipment-panel').scrollIntoView({behavior:'smooth',block:'start'});});});
  apply();
}());
</script>
