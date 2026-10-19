<?php
$selYear = isset($year) ? (int)$year : (int)date('Y');
$monthColors = [
  1=>'#e74c3c',2=>'#e67e22',3=>'#f1c40f',4=>'#2ecc71',
  5=>'#1abc9c',6=>'#3498db',7=>'#9b59b6',8=>'#34495e',
  9=>'#e74c3c',10=>'#e67e22',11=>'#2ecc71',12=>'#3498db'
];
$monthHasData = array_filter($months, fn($m) => !empty($m['pm']));
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Annual Maintenance Plan</div>
    <div class="cs"><?= $selYear ?> — <?= $total_pm ?> PM jobs scheduled across <?= count($monthHasData) ?> months</div>
  </div>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <form method="get" class="flex ai-c gap-10">
      <select name="year" class="form-control" style="width:110px;" onchange="this.form.submit()">
        <?php foreach($years as $y): ?>
        <option value="<?=$y?>" <?=$y===$selYear?'selected':''?>><?=$y?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a href="<?=base_url('reports/export_annual_plan_xlsx?year='.$selYear)?>"
       class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Excel
    </a>
    <button onclick="window.print()" class="btn btn-ghost btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print
    </button>
  </div>
</div>

<!-- KPI strip -->
<div class="grid g-4 mb-4">
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total PM Jobs</span>
    <div class="kpi-num"><?= $total_pm ?></div>
    <div class="cs" style="margin-top:4px;"><?= $selYear ?> schedule</div>
  </div>
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Months with PM</span>
    <div class="kpi-num"><?= count($monthHasData) ?><sup>/12</sup></div>
  </div>
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Avg PM / Month</span>
    <div class="kpi-num"><?= count($monthHasData) > 0 ? round($total_pm / count($monthHasData), 1) : 0 ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Unscheduled Units</span>
    <div class="kpi-num"><?= $unscheduled ?></div>
    <?php if($unscheduled > 0): ?>
    <div class="kpi-chip chip-warn">No PM this year</div>
    <?php endif; ?>
  </div>
</div>

<!-- Year overview heatmap-style bar -->
<div class="card mb-4" style="padding:0;overflow:hidden;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Year Overview — PM Jobs per Month</div>
    <div class="cs"><?= $selYear ?></div>
  </div>
  <div style="display:flex;gap:0;border-top:1px solid var(--g2);overflow-x:auto;">
    <?php
    $maxPm = max(1, max(array_column($months,'count')));
    foreach ($months as $mo):
      $cnt  = $mo['count'];
      $pct  = round($cnt / $maxPm * 100);
      $col  = $cnt === 0 ? 'var(--g2)' : ($cnt > 5 ? 'var(--blue)' : ($cnt > 2 ? 'var(--green)' : 'var(--green)'));
      $isPast = strtotime($mo['from']) < strtotime(date('Y-m-01'));
    ?>
    <div style="flex:1;min-width:70px;padding:14px 8px 12px;text-align:center;border-right:1px solid var(--g2);position:relative;<?= $isPast&&$cnt>0?'opacity:.7':''?>">
      <div style="font-size:11px;font-weight:500;text-transform:none;letter-spacing:0;color:var(--t3);margin-bottom:8px;"><?= $mo['label'] ?></div>
      <!-- Mini bar -->
      <div style="height:50px;display:flex;align-items:flex-end;justify-content:center;margin-bottom:6px;">
        <?php if($cnt > 0): ?>
        <div style="width:28px;height:<?= max(6,$pct/2) ?>px;background:<?= $col ?>;border-radius:3px 3px 0 0;opacity:.85;"></div>
        <?php else: ?>
        <div style="width:28px;height:4px;background:var(--g2);border-radius:3px;"></div>
        <?php endif; ?>
      </div>
      <div style="font-size:18px;font-weight:800;color:<?= $cnt>0?$col:'var(--t4)' ?>;line-height:1;"><?= $cnt ?></div>
      <div style="font-size:9.5px;color:var(--t4);margin-top:2px;">PM job<?= $cnt===1?'':'s' ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Month-by-month PM tables -->
<?php if(empty($monthHasData)): ?>
<div class="card" style="padding:52px;text-align:center;color:var(--t3);">
  No PM schedules found for <?= $selYear ?>. Set up PM schedules in the PM Planner module.
</div>
<?php else: ?>

<?php foreach($months as $mo): if(empty($mo['pm'])) continue; ?>
<div class="card mb-3" style="padding:0;overflow:hidden;">
  <!-- Month header -->
  <div style="padding:12px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--g2);background:var(--blue-d);">
    <div style="display:flex;align-items:center;gap:12px;">
      <div style="width:36px;height:36px;border-radius:8px;background:var(--blue);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;"><?= $mo['num'] ?></div>
      <div>
        <div class="ct" style="font-size:13px;color:var(--blue);"><?= $mo['label'] ?> <?= $selYear ?></div>
        <div class="cs"><?= date('d M',strtotime($mo['from'])) ?> – <?= date('d M',strtotime($mo['to'])) ?></div>
      </div>
    </div>
    <div style="text-align:right;">
      <div style="font-size:22px;font-weight:800;color:var(--blue);line-height:1;"><?= $mo['count'] ?></div>
      <div class="cs">PM job<?= $mo['count']===1?'':'s' ?></div>
    </div>
  </div>

  <?php if(!empty($mo['byType'])): ?>
  <div style="overflow-x:auto;">
  <table class="tbl" style="min-width:800px;">
    <thead>
      <tr>
        <th style="width:80px;">TYPE</th>
        <th style="width:100px;">MACHINE</th>
        <th style="width:160px;">MODEL</th>
        <th style="width:90px;">LOCATION</th>
        <th>PM TASK</th>
        <th style="width:70px;text-align:center;">INTERVAL</th>
        <th style="width:90px;text-align:center;">DUE DATE</th>
        <th style="width:80px;text-align:center;">DUE HOURS</th>
        <th style="width:90px;text-align:center;">LAST DONE</th>
        <th style="width:80px;text-align:center;">STATUS</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($mo['byType'] as $type => $items): ?>
    <?php foreach($items as $ii => $pm):
      $due    = $pm->next_due_date;
      $today  = date('Y-m-d');
      $overdue= $due && $due < $today;
      $soon   = $due && !$overdue && $due <= date('Y-m-d', strtotime('+14 days'));
      $rowBg  = $overdue ? 'rgba(192,57,43,0.04)' : ($soon ? 'rgba(180,83,9,0.04)' : '');
      $dueCol = $overdue ? 'var(--red)' : ($soon ? 'var(--amber)' : 'var(--green)');
    ?>
    <tr style="background:<?= $rowBg ?>;">
      <td style="font-size:10px;font-weight:700;color:var(--blue);text-align:center;"><?= $ii===0 ? htmlspecialchars($type) : '' ?></td>
      <td class="mono" style="font-weight:600;color:var(--blue);"><?= htmlspecialchars($pm->registration_no) ?></td>
      <td style="color:var(--t2);font-size:10.5px;"><?= htmlspecialchars(($pm->brand??'').' '.($pm->model??'')) ?></td>
      <td style="text-align:center;color:var(--t3);font-size:10.5px;"><?= htmlspecialchars($pm->location??'') ?></td>
      <td style="font-weight:500;"><?= htmlspecialchars($pm->schedule_name) ?></td>
      <td style="text-align:center;font-size:10.5px;color:var(--t3);">
        <?= $pm->interval_value ?> <?= $pm->interval_type ?>
      </td>
      <td class="mono" style="text-align:center;font-weight:600;color:<?= $dueCol ?>;">
        <?= $due ? date('d/m/Y',strtotime($due)) : '—' ?>
      </td>
      <td class="mono" style="text-align:center;color:var(--t3);">
        <?= $pm->next_due_hours ? number_format($pm->next_due_hours,0).' h' : '—' ?>
      </td>
      <td class="mono" style="text-align:center;color:var(--t3);font-size:10.5px;">
        <?= $pm->last_done_date ? date('d/m/Y',strtotime($pm->last_done_date)) : '—' ?>
      </td>
      <td style="text-align:center;">
        <?php if($overdue): ?>
        <span style="padding:2px 6px;border-radius:4px;font-size:11px;font-weight:500;color:var(--red);background:var(--red-d);">OVERDUE</span>
        <?php elseif($soon): ?>
        <span style="padding:2px 6px;border-radius:4px;font-size:11px;font-weight:500;color:var(--amber);background:var(--amber-d);">SOON</span>
        <?php else: ?>
        <span style="padding:2px 6px;border-radius:4px;font-size:9.5px;color:var(--green);background:var(--green-d);">OK</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<style>
@media print {
  #sidebar,#canvas .topbar,.btn,form,select{display:none!important;}
  #canvas{margin-left:0!important;}
  .card{box-shadow:none!important;page-break-inside:avoid;}
  .tbl td,.tbl th{font-size:7.5pt!important;padding:2px 4px!important;}
}
</style>
