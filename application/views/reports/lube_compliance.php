<?php
$selMonth = isset($month) ? $month : date('Y-m');

$totalReq = 0; $totalAct = 0;
foreach ($rows as $r) { $totalReq += $r['required_qty']; $totalAct += $r['actual_qty']; }
$overallVariancePct = $totalReq > 0 ? round(($totalAct - $totalReq) / $totalReq * 100, 1) : null;

$overCount  = count(array_filter($rows, fn($r) => $r['variance_pct'] !== null && $r['variance_pct'] > 10));
$underCount = count(array_filter($rows, fn($r) => $r['variance_pct'] !== null && $r['variance_pct'] < -10));
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">Lubricant Compliance Report</h1>
    <div class="cs">PM-driven requirement vs. actual consumption — <?=date('F Y',strtotime($from))?></div>
  </div>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <form method="get" class="flex ai-c gap-10">
      <input type="month" name="month" class="form-control" style="width:155px;"
             value="<?=$selMonth?>" onchange="this.form.submit()">
    </form>
    <a href="<?=base_url('reports/lube_compliance_yearly?year='.date('Y',strtotime($from)))?>" class="btn btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Yearly View
    </a>
  </div>
</div>

<!-- KPI strip -->
<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">PM-Required Qty</span>
    <div class="kpi-num"><?=number_format((float)($totalReq),1)?></div>
    <div class="cs" style="margin-top:4px;">Across all lube types</div>
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
    <span class="kpi-tag">Out of Range</span>
    <div class="kpi-num"><?=$overCount+$underCount?></div>
    <div class="cs" style="margin-top:4px;"><?=$overCount?> over · <?=$underCount?> under (&gt;10%)</div>
  </div>
</div>

<div class="card" style="overflow-x:auto;">
  <div class="ch" style="padding:14px 18px 12px;">
    <div><div class="ct">Required vs Actual — by Lube Type</div>
    <div class="cs">Required qty is derived from active PM schedules due this month (date-based and hours-based)</div></div>
  </div>
  <?php if (empty($rows)): ?>
  <div style="padding:48px;text-align:center;color:var(--t3);">No PM-driven lube requirement or consumption recorded for this month.</div>
  <?php else: ?>
  <table class="tbl">
    <thead>
      <tr>
        <th>LUBE TYPE</th><th>CATEGORY</th>
        <th style="text-align:center;">PM SERVICES DUE</th>
        <th style="text-align:center;">REQUIRED QTY</th>
        <th style="text-align:center;">ACTUAL QTY</th>
        <th style="text-align:center;">RECORDS</th>
        <th style="text-align:center;">VARIANCE</th>
        <th style="text-align:center;">STATUS</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
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
