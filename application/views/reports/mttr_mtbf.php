<?php
$months_options = [3,6,12,18,24];
$sel = isset($months) ? (int)$months : 12;
$trendLabels = array_column($trend, 'month');
$trendMttr   = array_column($trend, 'mttr');
$trendMtbf   = array_column($trend, 'mtbf');
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">MTTR / MTBF Report</div>
    <div class="cs">Reliability performance over the last <?= $sel ?> months</div>
  </div>
  <form method="get" class="flex ai-c gap-10">
    <label style="font-size:11px;color:var(--t3);">Period</label>
    <select name="months" class="form-control" style="width:120px;" onchange="this.form.submit()">
      <?php foreach($months_options as $m): ?>
      <option value="<?=$m?>" <?=$m===$sel?'selected':''?>><?=$m?> months</option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<!-- KPI Strip -->
<div class="grid g-4 mb-4">
  <?php
  $kpis = [
    ['label'=>'Avg MTTR','val'=>$summary['avg_mttr'].' h','sub'=>'Mean Time To Repair','color'=>'var(--amber)','icon'=>'<line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/><circle cx="12" cy="12" r="10"/>'],
    ['label'=>'Avg MTBF','val'=>$summary['avg_mtbf'].' h','sub'=>'Mean Time Between Failures','color'=>'var(--green)','icon'=>'<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>'],
    ['label'=>'Total Breakdowns','val'=>$summary['total_bd'],'sub'=>'Last '.$sel.' months','color'=>'var(--red)','icon'=>'<polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>'],
    ['label'=>'Fleet Availability','val'=>$summary['avg_avail'].'%','sub'=>'Average across all equipment','color'=>'var(--blue)','icon'=>'<path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'],
  ];
  foreach ($kpis as $i=>$k): ?>
  <div class="kpi" style="animation-delay:<?=$i*.04?>s">
    <div class="kpi-glow" style="background:<?=$k['color']?>"></div>
    <span class="kpi-tag"><?=$k['label']?></span>
    <div class="kpi-num" style="font-size:22px;"><?=$k['val']?></div>
    <div class="cs" style="margin-top:4px;"><?=$k['sub']?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Trend Charts -->
<div class="grid g-2 mb-4" style="gap:16px;">
  <div class="card" style="padding:0;">
    <div class="ch" style="padding:14px 18px 10px;">
      <div><div class="ct">MTTR Trend</div><div class="cs">Avg hours to repair per month</div></div>
    </div>
    <div style="padding:8px 16px 16px;"><canvas id="mttrChart" style="max-height:200px;"></canvas></div>
  </div>
  <div class="card" style="padding:0;">
    <div class="ch" style="padding:14px 18px 10px;">
      <div><div class="ct">MTBF Trend</div><div class="cs">Avg hours between failures per month</div></div>
    </div>
    <div style="padding:8px 16px 16px;"><canvas id="mtbfChart" style="max-height:200px;"></canvas></div>
  </div>
</div>

<!-- Per-equipment table -->
<div class="card" style="overflow-x:auto;">
  <div class="ch" style="padding:14px 18px 12px;">
    <div><div class="ct">Per-Equipment Reliability</div><div class="cs">Last <?=$sel?> months</div></div>
  </div>
  <table class="tbl" style="min-width:900px;">
    <thead>
      <tr>
        <th>TYPE</th><th>MACHINE ID</th><th>MODEL</th><th>LOCATION</th>
        <th style="text-align:center;">HOURS</th>
        <th style="text-align:center;">BREAKDOWNS</th>
        <th style="text-align:center;">TOTAL DOWNTIME</th>
        <th style="text-align:center;">MTTR (h)</th>
        <th style="text-align:center;">MTBF (h)</th>
        <th style="text-align:center;">AVAILABILITY</th>
        <th style="text-align:center;">WORST EVENT</th>
      </tr>
    </thead>
    <tbody>
    <?php if(empty($per_eq)): ?>
    <tr><td colspan="11" style="text-align:center;padding:40px;color:var(--t3);">No breakdown data found</td></tr>
    <?php else: ?>
    <?php foreach($per_eq as $eq):
      $av      = $eq['availability'];
      // Q1: compare a machine to ITS target (equipment_kpi_targets → 88 %), not to 90
      $avTgt   = isset($eq['target']) ? (float)$eq['target'] : (isset($target) ? (float)$target : 88.0);
      $avColor = $av>=$avTgt?'var(--green)':($av>=$avTgt-10?'var(--amber)':'var(--red)');
      $mttrBad = $eq['mttr'] > $summary['avg_mttr'] * 1.5;
    ?>
    <tr>
      <td style="font-size:10.5px;color:var(--blue);font-weight:600;"><?=htmlspecialchars($eq['type'])?></td>
      <td class="mono" style="font-weight:600;color:var(--blue);"><?=htmlspecialchars($eq['machine_id'])?></td>
      <td style="color:var(--t2);"><?=htmlspecialchars($eq['model'])?></td>
      <td style="color:var(--t3);font-size:10.5px;"><?=htmlspecialchars($eq['location'])?></td>
      <td class="mono" style="text-align:center;"><?=number_format($eq['hours'],0)?></td>
      <td style="text-align:center;font-weight:700;color:<?=$eq['breakdowns']>3?'var(--red)':($eq['breakdowns']>1?'var(--amber)':'var(--t2)')?>;"><?=$eq['breakdowns']?></td>
      <td class="mono" style="text-align:center;color:var(--t2);"><?=number_format($eq['total_down'],1)?> h</td>
      <td style="text-align:center;font-weight:700;color:<?=$mttrBad?'var(--red)':'var(--green)'?>;"><?=$eq['mttr']?> h</td>
      <td class="mono" style="text-align:center;"><?=number_format($eq['mtbf'],0)?> h</td>
      <td style="text-align:center;font-weight:700;color:<?=$avColor?>;"><?=$eq['availability']?>%</td>
      <td class="mono" style="text-align:center;color:var(--t3);"><?=$eq['max_down']?> h</td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
var trendLabels = <?=json_encode($trendLabels)?>;
var trendMttr   = <?=json_encode($trendMttr)?>;
var trendMtbf   = <?=json_encode($trendMtbf)?>;
var avgMttr     = <?=$summary['avg_mttr']?>;

function makeChart(id, label, data, color, unit) {
  var ctx = document.getElementById(id);
  if (!ctx) return;
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: trendLabels,
      datasets: [{
        label: label,
        data: data,
        borderColor: color,
        backgroundColor: color.replace(')',',0.12)').replace('rgb','rgba'),
        borderWidth: 2,
        pointRadius: 4,
        pointBackgroundColor: color,
        tension: 0.35,
        fill: true,
      }]
    },
    options: {
      responsive:true, maintainAspectRatio:true,
      plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:function(c){return c.parsed.y+' '+unit; }}}},
      scales:{
        x:{ ticks:{font:{size:9},color:'#888'}, grid:{display:false} },
        y:{ ticks:{font:{size:9},color:'#888', callback:function(v){return v+' '+unit;}}, grid:{color:'rgba(0,0,0,0.04)'}, beginAtZero:true }
      }
    }
  });
}
makeChart('mttrChart','MTTR',trendMttr,'rgb(255,159,10)','h');
makeChart('mtbfChart','MTBF',trendMtbf,'rgb(48,209,88)','h');
</script>
