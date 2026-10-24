<?php
$horizons = [30,60,90,120,180];
$sel = isset($horizon_days) ? (int)$horizon_days : 90;
$now = date('Y-m-d');

// Group forecast by type
$byType = [];
foreach($forecast as $f) { $byType[$f['type']][] = $f; }
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">PM Due Forecast</h1>
    <div class="cs">Preventive-maintenance schedules falling due by <?=date('d M Y',strtotime($horizon_date))?></div>
    <div class="cs" style="margin-top:2px;">For projected <em>availability</em>, see <a href="<?= base_url('forecast') ?>">Planned Availability</a>.</div>
  </div>
  <form method="get" class="flex ai-c gap-10">
    <label style="font-size:11px;color:var(--t3);">Horizon</label>
    <select name="horizon" class="form-control" style="width:130px;" onchange="this.form.submit()">
      <?php foreach($horizons as $h): ?>
      <option value="<?=$h?>" <?=$h===$sel?'selected':''?>><?=$h?> days</option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<!-- KPI Strip -->
<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total Fleet</span><div class="kpi-num"><?=$total_eq?><sup>units</sup></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">PM Due in <?=$sel?>d</span>
    <div class="kpi-num"><?=$pm_due_count?></div>
    <?php if($pm_due_count>0): ?>
    <div class="kpi-chip chip-warn">Action needed</div>
    <?php endif; ?>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Overdue PM</span>
    <div class="kpi-num"><?=$overdue_count?></div>
    <?php if($overdue_count>0): ?>
    <div class="kpi-chip chip-dn">Immediate action</div>
    <?php endif; ?>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--orange,var(--amber))"></div>
    <span class="kpi-tag">Due ≤14 Days</span>
    <div class="kpi-num"><?=$urgent_count?></div>
    <?php if($urgent_count>0): ?>
    <div class="kpi-chip chip-warn">Schedule soon</div>
    <?php endif; ?>
  </div>
</div>

<!-- Fleet type availability strip -->
<?php if(!empty($type_summary)): ?>
<div class="card mb-4" style="padding:0;overflow:hidden;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Fleet Operational Status</div>
    <div class="cs">Current + PM forecast for next <?=$sel?> days</div>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:0;border-top:1px solid var(--g2);">
    <?php foreach($type_summary as $i=>$ts):
      $opPct = $ts['total']>0 ? round($ts['operational']/$ts['total']*100) : 0;
      $col   = $opPct>=90?'var(--green)':($opPct>=70?'var(--amber)':'var(--red)');
    ?>
    <div style="flex:1;min-width:120px;padding:12px 16px;border-right:1px solid var(--g2);<?=$i===count($type_summary)-1?'border-right:none':''?>">
      <div style="font-size:11px;font-weight:500;text-transform:none;letter-spacing:0;color:var(--t3);margin-bottom:4px;"><?=htmlspecialchars((string)($ts['type']))?>s</div>
      <div style="font-size:22px;font-weight:800;color:<?=$col?>;line-height:1;margin-bottom:4px;"><?=$opPct?>%</div>
      <div style="height:4px;background:var(--g2);border-radius:3px;margin-bottom:6px;overflow:hidden;">
        <div style="height:100%;width:<?=$opPct?>%;background:<?=$col?>;border-radius:3px;"></div>
      </div>
      <div style="font-size:10px;color:var(--t3);">
        <span style="color:var(--green);font-weight:600;"><?=$ts['operational']?> op</span>
        <?php if($ts['breakdown']>0):?> · <span style="color:var(--red);font-weight:600;"><?=$ts['breakdown']?> dn</span><?php endif;?>
        <?php if($ts['pm_due']>0):?> · <span style="color:var(--amber);font-weight:600;"><?=$ts['pm_due']?> PM due</span><?php endif;?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Forecast table -->
<?php if(empty($forecast)): ?>
<div class="card" style="padding:52px;text-align:center;color:var(--t3);">
  No PM schedules due within <?=$sel?> days. Fleet is in good shape!
</div>
<?php else: ?>
<?php foreach($byType as $type => $items): ?>
<div class="card mb-3" style="overflow-x:auto;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;"><?=htmlspecialchars((string)($type))?>s — Upcoming PM</div>
    <div class="cs"><?=count($items)?> schedule<?=count($items)>1?'s':''?></div>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th>MACHINE ID</th><th>PM SCHEDULE</th><th>INTERVAL</th>
        <th style="text-align:center;">LAST DONE</th>
        <th style="text-align:center;">DUE DATE</th>
        <th style="text-align:center;">DAYS UNTIL</th>
        <th style="text-align:center;">DUE HOURS</th>
        <th style="text-align:center;">HRS REMAINING</th>
        <th style="text-align:center;">STATUS</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($items as $f):
      $du     = $f['days_until'];
      $overd  = $f['overdue'];
      $urgent = $f['urgent'];
      $statusLabel = $overd ? 'OVERDUE' : ($urgent ? 'DUE SOON' : 'SCHEDULED');
      $statusColor = $overd ? 'var(--red)' : ($urgent ? 'var(--amber)' : 'var(--green)');
      $statusBg    = $overd ? 'var(--red-d)' : ($urgent ? 'var(--amber-d)' : 'var(--green-d)');
      $rowBg       = $overd ? 'background:rgba(192,57,43,0.04);' : ($urgent ? 'background:rgba(180,83,9,0.04);' : '');
    ?>
    <tr style="<?=$rowBg?>">
      <td class="mono" style="font-weight:600;color:var(--blue);"><?=htmlspecialchars((string)($f['machine_id']))?></td>
      <td style="color:var(--t2);"><?=htmlspecialchars((string)($f['schedule_name']))?></td>
      <td style="color:var(--t3);font-size:10.5px;">Every <?=$f['interval_value']?> <?=$f['interval_type']?></td>
      <td style="text-align:center;color:var(--t3);font-size:10.5px;">
        <?=$f['last_done'] ? date('d/m/Y',strtotime($f['last_done'])) : '—'?>
      </td>
      <td class="mono" style="text-align:center;font-weight:600;">
        <?=$f['next_due_date'] ? date('d/m/Y',strtotime($f['next_due_date'])) : '—'?>
      </td>
      <td style="text-align:center;font-weight:700;color:<?=$statusColor?>;">
        <?=$overd ? abs($du).' d overdue' : $du.' days'?>
      </td>
      <td class="mono" style="text-align:center;color:var(--t3);">
        <?=$f['next_due_hours'] ? number_format((float)($f['next_due_hours']),0).' h' : '—'?>
      </td>
      <td style="text-align:center;font-weight:600;color:<?=$f['hrs_remaining']!==null&&$f['hrs_remaining']<100?'var(--red)':'var(--t2)'?>;">
        <?=$f['hrs_remaining']!==null ? number_format((float)($f['hrs_remaining']),0).' h' : '—'?>
      </td>
      <td style="text-align:center;">
        <span style="padding:3px 8px;border-radius:5px;font-size:10.5px;font-weight:700;color:<?=$statusColor?>;background:<?=$statusBg?>;"><?=$statusLabel?></span>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>
<?php endif; ?>
