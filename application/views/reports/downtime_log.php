<?php
$report = isset($report) && is_array($report) ? $report : array();
$week = isset($report['week']) ? $report['week'] : array();
$week_start = isset($week['start']) ? $week['start'] : (isset($week_start) ? $week_start : '');
$week_label = isset($week['iso_label']) ? $week['iso_label'] : '';
$events = isset($events) && is_array($events) ? $events : array();
$esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$fmt = function ($v, $d = 2) { return $v === null || $v === '' ? '—' : number_format((float)$v, $d, '.', ','); };
$fleet_nav_active = 'downtime';
$live_query = empty($snapshot) && !empty($published) ? '&live=1' : '';
?>
<div class="fd-wrap">
  <?php $this->load->view('reports/_fleet_nav', array('fleet_nav_active' => $fleet_nav_active, 'week_start' => $week_start)); ?>
  <header class="fd-head">
    <div><p class="fd-eyebrow">WMS event records · <?= $esc($week_label) ?></p><h1>Log downtime</h1><p>Breakdown events are grouped from existing work orders and merged by the shared W36 event logic.</p></div>
    <div class="fd-actions">
      <a class="fd-btn fd-primary" href="<?= base_url('workorders/create?fleet_availability=1&fleet_kind=breakdown&week_start=' . rawurlencode($week_start)) ?>">Log a breakdown</a>
      <a class="fd-btn" href="<?= base_url('workorders/create?fleet_availability=1&fleet_kind=planned&week_start=' . rawurlencode($week_start)) ?>">Create preventive WO</a>
    </div>
  </header>
  <?php if (!empty($notice)): ?><div class="fd-note warn"><?= $esc($notice) ?></div><?php endif; ?>
  <?php if (!empty($snapshot)): ?>
    <div class="fd-note good"><strong>Published snapshot.</strong> The event list reflects the frozen weekly payload from <?= $esc(isset($snapshot['published_at']) ? $snapshot['published_at'] : '') ?>.</div>
  <?php elseif (!empty($published)): ?>
    <div class="fd-note warn"><strong>Live event list.</strong> This week has a published snapshot; the list below is recalculated from current records. <a href="<?= base_url('reports/downtime_log?week=' . rawurlencode($week_label)) ?>">Open frozen list</a>.</div>
  <?php else: ?>
    <div class="fd-note info"><strong>Live event list.</strong> Saved changes appear here after the report is refreshed; events are not stored in this page or browser session.</div>
  <?php endif; ?>

  <form class="fd-period" method="get" action="<?= base_url('reports/downtime_log') ?>">
    <?php if ($live_query !== ''): ?><input type="hidden" name="live" value="1"><?php endif; ?>
    <a class="fd-btn" href="<?= base_url('reports/downtime_log?week=' . rawurlencode(isset($prev_week) ? $prev_week : '') . $live_query) ?>">← <?= $esc(isset($prev_week) ? $prev_week : '') ?></a>
    <label>ISO week <input type="week" name="week" value="<?= $esc($week_label) ?>"></label>
    <button class="fd-btn fd-primary" type="submit">Show week</button>
    <a class="fd-btn" href="<?= base_url('reports/downtime_log?week=' . rawurlencode(isset($next_week) ? $next_week : '') . $live_query) ?>"><?= $esc(isset($next_week) ? $next_week : '') ?> →</a>
    <span class="fd-count"><?= count($events) ?> event record(s) in report window</span>
  </form>

  <div class="fd-note info"><strong>What this log counts.</strong> Rows below are distinct breakdown events built from Corrective Maintenance work orders. Linked tickets for the same event are shown together and overlapping intervals are not added twice. Preventive work orders and planned forecast rows are not represented as actual planned downtime here; WMS does not infer actual outage time from PM labor or a plan.</div>

  <section class="fd-panel">
    <div class="fd-filter"><label>Search events<input id="fd-search" type="search" placeholder="Equipment, model, location or work order"></label><label class="fd-check"><input type="checkbox" id="fd-open"> Open events only</label><span id="fd-filter-count" role="status" aria-live="polite"></span></div>
    <div class="fd-table-wrap" tabindex="0" role="region" aria-label="Breakdown event log">
      <table class="fd-table"><thead><tr><th>Equipment</th><th>Event start</th><th>Restored</th><th>Hours in week</th><th>Event state</th><th>Work order(s)</th></tr></thead><tbody id="fd-events">
      <?php foreach ($events as $event):
        $start = isset($event['start']) ? $event['start'] : null;
        $end = isset($event['end']) ? $event['end'] : null;
        $hours = isset($event['hours_in_period']) ? $event['hours_in_period'] : null;
        $wo_ids = isset($event['work_orders']) ? (array)$event['work_orders'] : array();
        $wo_labels = isset($event['work_order_numbers']) ? (array)$event['work_order_numbers'] : array();
        $words = $wo_labels;
        $search = (isset($event['machine_id']) ? $event['machine_id'] : '') . ' ' . (isset($event['model']) ? $event['model'] : '') . ' ' . (isset($event['location']) ? $event['location'] : '') . ' ' . implode(' ', $words);
      ?>
        <tr data-search="<?= $esc($search) ?>" data-open="<?= !empty($event['open']) ? '1' : '0' ?>">
          <td><a class="fd-asset" href="<?= base_url('reports/weekly_w36?week=' . rawurlencode($week_label)) ?>"><?= $esc(isset($event['machine_id']) ? $event['machine_id'] : '') ?></a><small><?= $esc(isset($event['model']) ? $event['model'] : '') ?> · <?= $esc(isset($event['location']) ? $event['location'] : 'Unspecified location') ?></small></td>
          <td><?= $esc($start ?: '—') ?><?php if (!empty($event['carried_in'])): ?><small>Started before this week</small><?php endif; ?></td>
          <td><?= !empty($event['open']) ? '<span class="fd-open">Still open</span>' : $esc($end ?: '—') ?></td>
          <td class="fd-num"><?= $esc($fmt($hours)) ?></td>
          <td><?= !empty($event['counts_as_breakdown']) ? 'New event this week' : 'Carried-in event' ?></td>
          <td><?php if ($wo_ids): foreach ($wo_ids as $wo_index => $wo_id): ?><a class="fd-wo" href="<?= base_url('workorders/view/' . (int)$wo_id) ?>"><?= $esc(isset($wo_labels[$wo_index]) ? $wo_labels[$wo_index] : ('WO #' . (int)$wo_id)) ?></a><?php endforeach; else: ?>—<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
    <?php if (!$events): ?><div class="fd-empty">No breakdown events were returned for this report period. This means no qualifying timestamped breakdown event was found—not that planned maintenance or unresolved defects are absent.</div><?php endif; ?>
  </section>
  <p class="fd-foot">To capture an event, create or update its existing work order. The event identity, equipment, classification and actual timestamps remain in the WMS work-order source; this page does not create a second downtime store. For a breakdown, enter the best-known start and leave restoration blank while the machine remains down.</p>
</div>
<style>
.fd-wrap{max-width:1480px;margin:0 auto;padding-bottom:32px;color:var(--t1,#111827)}.fd-head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin:0 0 14px}.fd-head h1{font-size:25px;line-height:1.1;margin:0}.fd-head p{font-size:12px;color:var(--t3,#64748b);margin:5px 0 0}.fd-eyebrow{color:var(--blue,#0071e3)!important;text-transform:uppercase;letter-spacing:.07em;font-size:10px!important;font-weight:700}.fd-actions{display:flex;gap:8px;flex-wrap:wrap}.fd-btn{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 12px;border:1px solid var(--sep,#dbe1e8);border-radius:8px;background:var(--g1,#fff);color:var(--t1,#111827);font-size:12px;font-weight:600;text-decoration:none;cursor:pointer}.fd-btn:hover{border-color:var(--blue,#0071e3);color:var(--blue,#0071e3)}.fd-primary{background:var(--blue,#0071e3);border-color:var(--blue,#0071e3);color:#fff}.fd-primary:hover{color:#fff}.fd-note{padding:11px 14px;border:1px solid var(--sep,#dbe1e8);border-radius:9px;margin:9px 0;font-size:11.5px;line-height:1.5}.fd-note a{font-weight:600}.fd-note.info{background:var(--blue-d,#eff6ff);border-color:#bfdbfe}.fd-note.good{background:var(--green-d,#f0fdf4);border-color:#bbf7d0}.fd-note.warn{background:var(--amber-d,#fffbeb);border-color:#fde68a}.fd-period{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 12px;border:1px solid var(--sep,#dbe1e8);border-radius:10px;background:var(--g1,#fff);margin:13px 0}.fd-period label{display:flex;align-items:center;gap:7px;font-size:11px;font-weight:600}.fd-period input{height:34px;border:1px solid var(--sep,#dbe1e8);border-radius:7px;padding:4px 8px;color:inherit;background:var(--g1,#fff)}.fd-count{margin-left:auto;color:var(--t3,#64748b);font-size:10px}.fd-panel{border:1px solid var(--sep,#e2e8f0);border-radius:11px;background:var(--g1,#fff);padding:14px}.fd-filter{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-bottom:10px}.fd-filter label:first-child{display:grid;gap:4px;color:var(--t3,#64748b);font-size:10px;font-weight:600}.fd-filter input[type=search]{height:34px;min-width:240px;border:1px solid var(--sep,#dbe1e8);border-radius:7px;padding:5px 8px;background:var(--g1,#fff);color:inherit}.fd-check{display:flex;gap:6px;align-items:center;min-height:34px;font-size:11px}.fd-filter #fd-filter-count{margin-left:auto;color:var(--t3,#64748b);font-size:10px}.fd-table-wrap{overflow:auto;border:1px solid var(--sep,#e2e8f0);border-radius:8px}.fd-table{width:100%;border-collapse:collapse;min-width:760px;font-size:11px}.fd-table th{background:var(--g2,#f1f5f9);padding:9px;text-align:left;color:var(--t3,#64748b);font-size:9px;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap}.fd-table td{padding:9px;border-top:1px solid var(--sep,#edf0f4);vertical-align:top}.fd-table tr:hover td{background:var(--blue-d,#f8fbff)}.fd-table tr[hidden]{display:none!important}.fd-table small{display:block;color:var(--t3,#64748b);font-size:9.5px;margin-top:3px}.fd-asset,.fd-wo{color:var(--blue,#0071e3);font-weight:700;text-decoration:none;margin-right:8px}.fd-open{display:inline-flex;padding:3px 7px;border-radius:99px;background:var(--red-d,#fef2f2);color:#b42318;font-weight:700}.fd-num{text-align:right;font-variant-numeric:tabular-nums}.fd-empty{padding:20px;border:1px dashed var(--sep,#dbe1e8);border-radius:8px;text-align:center;margin-top:10px;color:var(--t3,#64748b);font-size:11px}.fd-foot{font-size:10px;line-height:1.5;color:var(--t3,#64748b);margin:12px 2px}@media(max-width:650px){.fd-head h1{font-size:22px}.fd-actions{width:100%}.fd-period{align-items:flex-start}.fd-count{margin-left:0;width:100%}.fd-filter input[type=search]{min-width:0;width:100%}}
</style>
<script>
(function(){var body=document.getElementById('fd-events');if(!body)return;var rows=Array.prototype.slice.call(body.querySelectorAll('tr[data-search]')),s=document.getElementById('fd-search'),o=document.getElementById('fd-open'),count=document.getElementById('fd-filter-count');function apply(){var q=(s.value||'').trim().toLocaleLowerCase(),n=0;rows.forEach(function(r){var ok=(!q||r.dataset.search.toLocaleLowerCase().indexOf(q)!==-1)&&(!o.checked||r.dataset.open==='1');r.hidden=!ok;if(ok)n++;});count.textContent=n+' of '+rows.length+' event rows';}s.addEventListener('input',apply);o.addEventListener('change',apply);apply();}());
</script>
