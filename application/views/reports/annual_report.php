<?php
$selYear  = isset($year) ? (int)$year : (int)date('Y');
$t        = $totals;
$mLabels  = array_column($monthly,'label');
$mAvail   = array_column($monthly,'availability');
$mBd      = array_column($monthly,'breakdowns');
$mDt      = array_column($monthly,'downtime');
$mMttr    = array_column($monthly,'mttr');
// One availability target for the whole system (Q1): the engine's value, which
// reads `equipment_kpi_targets` and falls back to 88 %. No view invents its own.
$targetPct = isset($target) && (float)$target > 0 ? (float)$target : 88.0;
$amberPct  = 70.0;   // "watch" band, not a target
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">Annual Report <?= $selYear ?></h1>
    <div class="cs">Full-year fleet performance overview</div>
  </div>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <form method="get" class="flex ai-c gap-10">
      <select name="year" class="form-control" style="width:110px;" onchange="this.form.submit()">
        <?php foreach($years as $y): ?>
        <option value="<?=$y?>" <?=$y===$selYear?'selected':''?>><?=$y?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a href="<?=base_url('reports/export_annual_report_xlsx?year='.$selYear)?>"
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

<!-- ── KPI cards ── -->
<div class="grid g-4 mb-4">
  <?php $cards = [
    ['Fleet Size',     $t['fleet_size'].' units',   'var(--blue)',  ''],
    ['Total WOs',      $t['wo_total'],               'var(--amber)', 'All work orders raised'],
    ['Breakdowns',     $t['breakdowns'],             'var(--red)',   'Corrective interventions'],
    ['PM Completed',   $t['pm_done'],                'var(--green)', 'Preventive jobs done'],
    ['Avg MTTR',       $t['avg_mttr'].' h',          'var(--amber)', 'Mean time to repair'],
    ['Avg Avail.',     $t['avg_avail'].'%',          $t['avg_avail']>=$targetPct?'var(--green)':'var(--red)', 'Fleet average vs '.$targetPct.'% target'],
    ['Total Downtime', number_format((float)($t['total_dt']),0).' h','var(--red)',   'Hours lost'],
    ['Parts Spend',    '$'.number_format((float)($t['parts_spend']),0),'var(--purple)','Spare parts total'],
  ];
  foreach ($cards as $i=>$c): ?>
  <div class="kpi" style="animation-delay:<?=$i*.03?>s">
    <div class="kpi-glow" style="background:<?=$c[2]?>"></div>
    <span class="kpi-tag"><?=$c[0]?></span>
    <div class="kpi-num" style="font-size:<?=strlen((string)$c[1])>6?'20px':'26px'?>"><?=$c[1]?></div>
    <?php if($c[3]):?><div class="cs" style="margin-top:4px;"><?=$c[3]?></div><?php endif;?>
  </div>
  <?php endforeach;?>
</div>

<!-- ── Trend Charts row ── -->
<div class="grid g-2 mb-4" style="gap:16px;">
  <div class="card" style="padding:0;">
    <div class="ch" style="padding:14px 18px 10px;">
      <div class="ct">Monthly Availability %</div>
      <div class="cs">Target ≥ <?=$targetPct?>%</div>
    </div>
    <div style="padding:8px 16px 16px;"><canvas id="availChart" style="max-height:200px;"></canvas></div>
  </div>
  <div class="card" style="padding:0;">
    <div class="ch" style="padding:14px 18px 10px;">
      <div class="ct">Monthly Breakdowns vs Downtime</div>
      <div class="cs">Count + hours</div>
    </div>
    <div style="padding:8px 16px 16px;"><canvas id="bdChart" style="max-height:200px;"></canvas></div>
  </div>
</div>

<!-- ── Monthly KPI table ── -->
<div class="card mb-4" style="overflow-x:auto;">
  <div class="ch" style="padding:14px 18px 12px;">
    <div class="ct">Monthly Performance Summary</div>
    <div class="cs"><?=$selYear?></div>
  </div>
  <table class="tbl" style="min-width:800px;">
    <thead>
      <tr>
        <th>MONTH</th>
        <th style="text-align:center;">WOs</th>
        <th style="text-align:center;">BREAKDOWNS</th>
        <th style="text-align:center;">PM DONE</th>
        <th style="text-align:center;">COMPLETED</th>
        <th style="text-align:center;">DOWNTIME (h)</th>
        <th style="text-align:center;">AVAIL%</th>
        <th style="text-align:center;">MTTR (h)</th>
        <th style="text-align:center;">MTBF (h)</th>
        <th style="text-align:center;">LUBE (L)</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($monthly as $m):
      $av = $m['availability'];
      $avC = $av>=$targetPct?'var(--green)':($av>=$amberPct?'var(--amber)':'var(--red)');
    ?>
    <tr>
      <td style="font-weight:600;color:var(--t1);"><?=date('F Y',strtotime($m['month'].'-01'))?></td>
      <td style="text-align:center;"><?=$m['wo_total']?></td>
      <td style="text-align:center;font-weight:700;color:<?=$m['breakdowns']>5?'var(--red)':($m['breakdowns']>2?'var(--amber)':'var(--t2)')?>;"><?=$m['breakdowns']?></td>
      <td style="text-align:center;color:var(--green);"><?=$m['wo_total']-$m['breakdowns']?></td>
      <td style="text-align:center;"><?=$m['completed']?></td>
      <td class="mono" style="text-align:center;color:<?=$m['downtime']>500?'var(--red)':'var(--t2)'?>;"><?=number_format((float)($m['downtime']),1)?></td>
      <td style="text-align:center;font-weight:700;color:<?=$avC?>;"><?=$av?>%</td>
      <td class="mono" style="text-align:center;"><?=$m['mttr']?></td>
      <td class="mono" style="text-align:center;"><?=number_format((float)($m['mtbf']),0)?></td>
      <td class="mono" style="text-align:center;color:var(--t3);"><?=number_format((float)($m['lube_used']),1)?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
    <tfoot>
      <tr style="background:var(--blue-d);">
        <td style="font-weight:700;color:var(--blue);">ANNUAL TOTAL</td>
        <td style="text-align:center;font-weight:700;"><?=$t['wo_total']?></td>
        <td style="text-align:center;font-weight:700;color:var(--red);"><?=$t['breakdowns']?></td>
        <td style="text-align:center;font-weight:700;color:var(--green);"><?=$t['pm_done']?></td>
        <td style="text-align:center;font-weight:700;"><?=$t['completed']?></td>
        <td class="mono" style="text-align:center;font-weight:700;"><?=number_format((float)($t['total_dt']),1)?></td>
        <td style="text-align:center;font-weight:700;color:<?=$t['avg_avail']>=$targetPct?'var(--green)':'var(--red)'?>;"><?=$t['avg_avail']?>%</td>
        <td class="mono" style="text-align:center;font-weight:700;"><?=$t['avg_mttr']?></td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
  </table>
</div>

<!-- ── Equipment Reliability Ranking ── -->
<div class="grid g-2 mb-4" style="gap:16px;">
  <!-- Worst performers -->
  <div class="card" style="padding:0;">
    <div class="ch" style="padding:14px 18px 10px;">
      <div class="ct" style="color:var(--red);">⚠ Worst Performers</div>
      <div class="cs">Lowest availability — needs attention</div>
    </div>
    <table class="tbl">
      <thead><tr><th>MACHINE</th><th style="text-align:center;">AVAIL%</th><th style="text-align:center;">BDs</th><th style="text-align:center;">DOWN HRS</th></tr></thead>
      <tbody>
      <?php foreach(array_slice($eq_reliability,0,8) as $eq):
        $avC=$eq['availability']>=$targetPct?'var(--green)':($eq['availability']>=$amberPct?'var(--amber)':'var(--red)');
      ?>
      <tr>
        <td><span class="mono" style="font-weight:600;color:var(--blue);"><?=htmlspecialchars((string)($eq['machine_id']))?></span><br><span style="font-size:9.5px;color:var(--t3);"><?=htmlspecialchars((string)(trim((string)($eq['model']))))?></span></td>
        <td style="text-align:center;font-weight:700;color:<?=$avC?>;"><?=$eq['availability']?>%</td>
        <td style="text-align:center;color:var(--red);font-weight:600;"><?=$eq['breakdowns']?></td>
        <td class="mono" style="text-align:center;color:var(--t3);"><?=number_format((float)($eq['downtime']),1)?></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
  <!-- Top faults + lube -->
  <div class="card" style="padding:0;">
    <div class="ch" style="padding:14px 18px 10px;">
      <div class="ct">Top Recurring Faults</div>
      <div class="cs">Most frequent breakdown causes</div>
    </div>
    <table class="tbl">
      <thead><tr><th>FAULT / SYMPTOM</th><th style="text-align:center;">COUNT</th></tr></thead>
      <tbody>
      <?php if(empty($faults)):?>
      <tr><td colspan="2" style="text-align:center;color:var(--t3);padding:20px;">No fault data for <?=$selYear?></td></tr>
      <?php else: foreach(array_slice($faults,0,10) as $f):?>
      <tr>
        <td style="font-size:10.5px;"><?=htmlspecialchars((string)($f->symptoms))?></td>
        <td style="text-align:center;font-weight:700;color:var(--amber);"><?=$f->cnt?></td>
      </tr>
      <?php endforeach; endif;?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Lubricant consumption ── -->
<?php if(!empty($lube_annual)):?>
<div class="card mb-4" style="padding:0;">
  <div class="ch" style="padding:14px 18px 10px;">
    <div class="ct">Annual Lubricant Consumption</div>
    <div class="cs">By category — <?=$selYear?></div>
  </div>
  <table class="tbl">
    <thead><tr><th>CATEGORY</th><th style="text-align:center;">TOTAL CONSUMED</th><th style="text-align:center;">UNIT</th></tr></thead>
    <tbody>
    <?php foreach($lube_annual as $l):?>
    <tr>
      <td style="font-weight:600;color:var(--t1);"><?=htmlspecialchars((string)($l->category??'Other'))?></td>
      <td class="mono" style="text-align:center;font-weight:600;"><?=number_format((float)$l->total,1)?></td>
      <td style="text-align:center;color:var(--t3);"><?=htmlspecialchars((string)($l->unit??'L'))?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
</div>
<?php endif;?>

<script>
var mLabels=<?=json_encode($mLabels)?>;
var mAvail =<?=json_encode($mAvail)?>;
var mBd    =<?=json_encode($mBd)?>;
var mDt    =<?=json_encode($mDt)?>;

new Chart(document.getElementById('availChart'),{
  type:'line',
  data:{labels:mLabels,datasets:[
    {label:'Availability%',data:mAvail,borderColor:'rgb(48,209,88)',backgroundColor:'rgba(28,140,60,0.1)',
     borderWidth:2,pointRadius:4,pointBackgroundColor:'rgb(48,209,88)',tension:0.35,fill:true},
    {label:'Target <?=$targetPct?>%',data:mLabels.map(()=>+<?=$targetPct?>),borderColor:'rgba(0,0,0,0.35)',
     borderWidth:1.5,borderDash:[4,3],pointRadius:0,fill:false}
  ]},
  options:{responsive:true,maintainAspectRatio:true,
    plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.parsed.y+'%'}}},
    scales:{x:{ticks:{font:{size:9},color:'#888'},grid:{display:false}},
            y:{min:0,max:100,ticks:{font:{size:9},color:'#888',callback:v=>v+'%'},
               grid:{color:'rgba(0,0,0,0.04)'}}}}
});

new Chart(document.getElementById('bdChart'),{
  type:'bar',
  data:{labels:mLabels,datasets:[
    {label:'Breakdowns',data:mBd,backgroundColor:'rgba(192,57,43,0.8)',borderRadius:3,yAxisID:'y'},
    {label:'Downtime (h)',data:mDt,type:'line',borderColor:'rgb(255,159,10)',
     backgroundColor:'transparent',borderWidth:2,pointRadius:3,tension:0.35,yAxisID:'y2'}
  ]},
  options:{responsive:true,maintainAspectRatio:true,
    plugins:{legend:{labels:{font:{size:9},color:'#aaa'}}},
    scales:{
      x:{ticks:{font:{size:9},color:'#888'},grid:{display:false}},
      y:{ticks:{font:{size:9},color:'#888'},grid:{color:'rgba(0,0,0,0.04)'},beginAtZero:true},
      y2:{position:'right',ticks:{font:{size:9},color:'var(--amber)',callback:v=>v+'h'},grid:{display:false},beginAtZero:true}
    }}
});
</script>

<style>
@media print{
  #sidebar,#canvas .topbar,.btn,form,select{display:none!important;}
  #canvas{margin-left:0!important;}
  .card{box-shadow:none!important;page-break-inside:avoid;}
  .tbl td,.tbl th{font-size:7.5pt!important;padding:2px 4px!important;}
  .grid{display:block!important;}
  .grid>*{margin-bottom:12px;}
}
</style>
