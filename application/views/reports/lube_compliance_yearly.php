<?php
$selYear = isset($year) ? $year : date('Y');

$totalReq = 0; $totalAct = 0;
foreach ($totals as $t) { $totalReq += $t['required_qty']; $totalAct += $t['actual_qty']; }
$overallVariancePct = $totalReq > 0 ? round(($totalAct - $totalReq) / $totalReq * 100, 1) : null;

// Build per-month aggregate (sum across lube types) for the trend strip
$monthAgg = [];
foreach ($months as $m => $rows) {
  $req = 0; $act = 0;
  foreach ($rows as $r) { $req += $r['required_qty']; $act += $r['actual_qty']; }
  $monthAgg[$m] = ['required'=>$req,'actual'=>$act];
}
$maxVal = 1;
foreach ($monthAgg as $a) { $maxVal = max($maxVal, $a['required'], $a['actual']); }
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Lubricant Compliance Report</div>
    <div class="cs">PM-driven requirement vs. actual consumption — Annual <?=$selYear?></div>
  </div>
  <form method="get" class="flex ai-c gap-10">
    <select name="year" class="form-control" style="width:110px;" onchange="this.form.submit()">
      <?php for ($y = date('Y'); $y >= date('Y')-4; $y--): ?>
      <option value="<?=$y?>" <?=$y==$selYear?'selected':''?>><?=$y?></option>
      <?php endfor; ?>
    </select>
  </form>
</div>

<!-- KPI strip -->
<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">PM-Required Qty (YTD/Year)</span>
    <div class="kpi-num"><?=number_format((float)($totalReq),1)?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Actual Used</span>
    <div class="kpi-num"><?=number_format((float)($totalAct),1)?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:<?=$overallVariancePct===null?'var(--t3)':($overallVariancePct>10?'var(--red)':($overallVariancePct<-10?'var(--amber)':'var(--green)'))?>"></div>
    <span class="kpi-tag">Overall Variance</span>
    <div class="kpi-num"><?=$overallVariancePct===null?'—':($overallVariancePct>0?'+':'').$overallVariancePct.'%'?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Lube Types Tracked</span>
    <div class="kpi-num"><?=count($totals)?></div>
  </div>
</div>

<!-- Monthly trend strip -->
<div class="card mb-4" style="padding:0;overflow:hidden;">
  <div class="ch" style="padding:14px 18px 12px;">
    <div><div class="ct">Required vs Actual — Monthly Trend</div>
    <div class="cs">Total qty (all lube types combined) per month</div></div>
  </div>
  <div style="display:flex;align-items:flex-end;gap:6px;padding:18px 18px 14px;border-top:1px solid var(--g2);overflow-x:auto;">
    <?php foreach ($monthAgg as $m => $a):
      $reqH = $maxVal>0 ? round($a['required']/$maxVal*90) : 0;
      $actH = $maxVal>0 ? round($a['actual']/$maxVal*90) : 0;
      $label = date('M', strtotime($m.'-01'));
    ?>
    <div style="flex:1;min-width:46px;text-align:center;">
      <div style="display:flex;align-items:flex-end;justify-content:center;gap:3px;height:96px;">
        <div title="Required: <?=number_format((float)($a['required']),1)?>" style="width:9px;background:var(--blue);border-radius:2px 2px 0 0;height:<?=max(2,$reqH)?>px;"></div>
        <div title="Actual: <?=number_format((float)($a['actual']),1)?>" style="width:9px;background:var(--green);border-radius:2px 2px 0 0;height:<?=max(2,$actH)?>px;"></div>
      </div>
      <div style="font-size:9.5px;color:var(--t3);margin-top:6px;font-weight:600;"><?=$label?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="padding:0 18px 14px;font-size:10.5px;color:var(--t3);display:flex;gap:14px;">
    <span><span style="display:inline-block;width:8px;height:8px;background:var(--blue);border-radius:2px;margin-right:4px;"></span>Required (PM)</span>
    <span><span style="display:inline-block;width:8px;height:8px;background:var(--green);border-radius:2px;margin-right:4px;"></span>Actual Used</span>
  </div>
</div>

<!-- Annual totals by lube type -->
<div class="card" style="overflow-x:auto;">
  <div class="ch" style="padding:14px 18px 12px;">
    <div><div class="ct">Annual Totals — by Lube Type</div>
    <div class="cs">Full-year required (PM-driven) vs actual consumption</div></div>
  </div>
  <?php if (empty($totals)): ?>
  <div style="padding:48px;text-align:center;color:var(--t3);">No PM-driven lube requirement or consumption recorded for <?=$selYear?>.</div>
  <?php else: ?>
  <table class="tbl">
    <thead>
      <tr>
        <th>LUBE TYPE</th><th>CATEGORY</th>
        <th style="text-align:center;">PM SERVICES (YEAR)</th>
        <th style="text-align:center;">REQUIRED QTY</th>
        <th style="text-align:center;">ACTUAL QTY</th>
        <th style="text-align:center;">RECORDS</th>
        <th style="text-align:center;">VARIANCE</th>
        <th style="text-align:center;">STATUS</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($totals as $r):
      $pct = $r['variance_pct'];
      if ($pct === null)       { $label='N/A'; $col='var(--t3)'; $bg='var(--g2)'; }
      elseif ($pct > 10)       { $label='OVER-USING'; $col='var(--red)';  $bg='var(--red-d)'; }
      elseif ($pct < -10)      { $label='UNDER-USING'; $col='var(--amber)'; $bg='var(--amber-d)'; }
      else                     { $label='ON TARGET'; $col='var(--green)'; $bg='var(--green-d)'; }
    ?>
    <tr>
      <td style="color:var(--t1);font-weight:600;"><?=htmlspecialchars((string)($r['lube_name']))?></td>
      <td style="color:var(--t3);font-size:10.5px;"><?=htmlspecialchars((string)($r['category']))?></td>
      <td class="mono" style="text-align:center;"><?=$r['required_services']?></td>
      <td class="mono" style="text-align:center;font-weight:600;"><?=number_format((float)($r['required_qty']),1)?> <?=htmlspecialchars((string)($r['unit']))?></td>
      <td class="mono" style="text-align:center;font-weight:600;"><?=number_format((float)($r['actual_qty']),1)?> <?=htmlspecialchars((string)($r['unit']))?></td>
      <td class="mono" style="text-align:center;color:var(--t3);"><?=$r['actual_records']?></td>
      <td class="mono" style="text-align:center;font-weight:700;color:<?=$col?>;">
        <?=$pct===null?'—':(($pct>0?'+':'').$pct.'%')?>
      </td>
      <td style="text-align:center;">
        <span style="padding:3px 8px;border-radius:5px;font-size:10.5px;font-weight:700;color:<?=$col?>;background:<?=$bg?>;"><?=$label?></span>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
