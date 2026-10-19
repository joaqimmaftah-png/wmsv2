<?php
$selMonth = isset($month) ? $month : date('Y-m');
$prio_color = ['Critical'=>'var(--red)','High'=>'var(--amber)','Medium'=>'var(--blue)','Low'=>'var(--t3)'];
$prio_bg    = ['Critical'=>'var(--red-d)','High'=>'var(--amber-d)','Medium'=>'var(--blue-d)','Low'=>'var(--g2)'];
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Monthly Maintenance Plan</div>
    <div class="cs"><?=date('F Y',strtotime($from))?></div>
  </div>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <form method="get" class="flex ai-c gap-10">
      <input type="month" name="month" class="form-control" style="width:155px;"
             value="<?=$selMonth?>" onchange="this.form.submit()">
    </form>
    <a href="<?=base_url('reports/export_monthly_plan_xlsx?month='.$selMonth)?>"
       class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Excel
    </a>
  </div>
</div>

<!-- KPI strip -->
<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">PM Scheduled</span>
    <div class="kpi-num"><?=$pm_count?></div>
    <div class="cs" style="margin-top:4px;">This month</div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Open Corrective</span>
    <div class="kpi-num"><?=$corrective_count?></div>
    <div class="cs" style="margin-top:4px;">Active work orders</div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total Jobs</span>
    <div class="kpi-num"><?=$pm_count+$corrective_count?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Critical WOs</span>
    <div class="kpi-num"><?=count(array_filter($corrective,fn($w)=>$w->priority==='Critical'))?></div>
  </div>
</div>

<!-- Weekly PM plan -->
<div class="card mb-4" style="padding:0;overflow:hidden;">
  <div class="ch" style="padding:14px 18px 12px;">
    <div><div class="ct">Preventive Maintenance Schedule</div>
    <div class="cs">PM jobs due this month, grouped by week</div></div>
  </div>
  <?php $hasPm = false;
  foreach($weeks as $wk) if(!empty($wk['pm'])) { $hasPm=true; break; }
  if(!$hasPm): ?>
  <div style="padding:40px;text-align:center;color:var(--t3);">No PM jobs scheduled this month.</div>
  <?php else: ?>
  <?php foreach($weeks as $wk): if(empty($wk['pm'])) continue; ?>
  <div style="border-top:1px solid var(--g2);">
    <div style="padding:10px 18px 8px;background:var(--blue-d);font-size:11px;font-weight:700;color:var(--blue);letter-spacing:.4px;">
      📅 <?=htmlspecialchars($wk['label'])?>
    </div>
    <table class="tbl" style="margin:0;border-top:none;">
      <thead>
        <tr><th>MACHINE</th><th>MODEL</th><th>LOCATION</th><th>PM TASK</th>
            <th style="text-align:center;">DUE DATE</th><th style="text-align:center;">DUE HOURS</th>
            <th style="text-align:center;">LAST DONE</th><th style="text-align:center;">INTERVAL</th></tr>
      </thead>
      <tbody>
      <?php foreach($wk['pm'] as $pm): ?>
      <tr style="background:rgba(28,140,60,0.03);">
        <td class="mono" style="font-weight:600;color:var(--blue);"><?=htmlspecialchars($pm->registration_no)?></td>
        <td style="color:var(--t2);font-size:10.5px;"><?=htmlspecialchars(($pm->brand??'').' '.($pm->model??''))?></td>
        <td style="color:var(--t3);font-size:10.5px;text-align:center;"><?=htmlspecialchars($pm->location??'')?></td>
        <td style="color:var(--t1);"><?=htmlspecialchars($pm->schedule_name)?></td>
        <td class="mono" style="text-align:center;font-weight:600;color:var(--green);">
          <?=$pm->next_due_date ? date('d/m/Y',strtotime($pm->next_due_date)) : '—'?>
        </td>
        <td class="mono" style="text-align:center;color:var(--t3);">
          <?=$pm->next_due_hours ? number_format($pm->next_due_hours,0).' h' : '—'?>
        </td>
        <td class="mono" style="text-align:center;color:var(--t3);font-size:10.5px;">
          <?=$pm->last_done_date ? date('d/m/Y',strtotime($pm->last_done_date)) : '—'?>
        </td>
        <td style="text-align:center;font-size:10.5px;color:var(--t3);">
          <?=$pm->interval_value?> <?=$pm->interval_type?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Open Corrective WOs -->
<?php if(!empty($corrective)): ?>
<div class="card" style="overflow-x:auto;">
  <div class="ch" style="padding:14px 18px 12px;">
    <div><div class="ct">Open / In-Progress Corrective Work Orders</div>
    <div class="cs">To be completed or tracked this month</div></div>
  </div>
  <table class="tbl" style="min-width:900px;">
    <thead>
      <tr><th>PRIORITY</th><th>WO #</th><th>MACHINE</th><th>MODEL</th><th>LOCATION</th>
          <th>TITLE / FAULT</th><th style="text-align:center;">STATUS</th>
          <th style="text-align:center;">STARTED</th><th style="text-align:center;">EST. COMPLETE</th></tr>
    </thead>
    <tbody>
    <?php foreach($corrective as $wo):
      $pc = $prio_color[$wo->priority] ?? 'var(--t2)';
      $pb = $prio_bg[$wo->priority]    ?? 'var(--g2)';
    ?>
    <tr>
      <td style="text-align:center;">
        <span style="padding:2px 8px;border-radius:4px;font-size:10.5px;font-weight:700;color:<?=$pc?>;background:<?=$pb?>;"><?=$wo->priority?></span>
      </td>
      <td class="mono" style="font-size:10.5px;color:var(--t3);"><?=htmlspecialchars($wo->wo_number)?></td>
      <td class="mono" style="font-weight:600;color:var(--blue);"><?=htmlspecialchars($wo->registration_no)?></td>
      <td style="color:var(--t2);font-size:10.5px;"><?=htmlspecialchars(($wo->brand??'').' '.($wo->model??''))?></td>
      <td style="color:var(--t3);font-size:10.5px;"><?=htmlspecialchars($wo->location??'')?></td>
      <td style="max-width:260px;white-space:pre-wrap;"><?=htmlspecialchars($wo->title)?></td>
      <td style="text-align:center;">
        <?php $sc=['Open'=>'var(--red)','In Progress'=>'var(--amber)','Waiting Parts'=>'var(--t3)','Waiting Tools'=>'var(--t3)'];
        $sb=['Open'=>'var(--red-d)','In Progress'=>'var(--amber-d)','Waiting Parts'=>'var(--g2)','Waiting Tools'=>'var(--g2)'];?>
        <span style="padding:2px 8px;border-radius:4px;font-size:10.5px;font-weight:600;color:<?=$sc[$wo->status]??'var(--t2)'?>;background:<?=$sb[$wo->status]??'var(--g2)'?>;"><?=$wo->status?></span>
      </td>
      <td class="mono" style="text-align:center;font-size:10.5px;color:var(--t3);">
        <?=$wo->start_datetime ? date('d/m/Y',strtotime($wo->start_datetime)) : '—'?>
      </td>
      <td class="mono" style="text-align:center;font-size:10.5px;color:var(--t3);">
        <?=$wo->end_datetime ? date('d/m/Y',strtotime($wo->end_datetime)) : 'TBD'?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
