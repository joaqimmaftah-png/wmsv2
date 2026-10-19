<?php
$today = date('Y-m-d');
$displayDate = isset($date) ? $date : $today;

// Build per-type chart data for JS
$chartData = [];
foreach ($report as $type => $g) {
    $labels = [];
    $values = [];
    foreach ($g['machines'] as $m) {
        $labels[] = $m['machine_id'];
        $values[] = round($m['availability'] * 100);
    }
    $chartData[] = [
        'type'      => $g['type'],
        'kpi'       => round($g['daily_kpi'] * 100),
        'available' => $g['available'],
        'down'      => $g['down'],
        'total'     => $g['total'],
        'labels'    => $labels,
        'values'    => $values,
    ];
}
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Workshop Daily Report</div>
    <div class="cs"><?= date('l, d F Y', strtotime($displayDate)) ?></div>
  </div>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <form method="get" class="flex ai-c gap-10">
      <input type="date" name="date" class="form-control" style="width:160px;" value="<?= $displayDate ?>" max="<?= $today ?>">
      <button type="submit" class="btn btn-primary btn-sm">Load</button>
    </form>
    <a href="<?= base_url('reports/export_workshop_daily_xlsx?date='.$displayDate) ?>"
       class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Excel
    </a>
    <a href="<?= base_url('reports/export_workshop_daily_pdf?date='.$displayDate) ?>"
       class="btn btn-ghost btn-icon" style="border:1px solid var(--g3);">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      PDF
    </a>
    <button onclick="window.print()" class="btn btn-ghost btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print
    </button>
  </div>
</div>

<!-- ── Summary KPIs ── -->
<?php
$totalEq = 0; $totalAvail = 0; $totalDown = 0;
foreach ($report as $g) {
    $totalEq    += $g['total'];
    $totalAvail += $g['available'];
    $totalDown  += $g['down'];
}
$overallAvail = $totalEq > 0 ? round($totalAvail / $totalEq, 2) : 0;
?>
<div class="grid g-4 mb-4">
  <div class="kpi" style="animation-delay:.04s">
    <div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total Fleet</span>
    <div class="kpi-num"><?= $totalEq ?><sup>units</sup></div>
  </div>
  <div class="kpi" style="animation-delay:.08s">
    <div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Available</span>
    <div class="kpi-num"><?= $totalAvail ?></div>
    <div class="kpi-chip chip-up">↑ <?= $totalEq>0?round($totalAvail/$totalEq*100).'%':'-' ?></div>
  </div>
  <div class="kpi" style="animation-delay:.12s">
    <div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Down</span>
    <div class="kpi-num"><?= $totalDown ?></div>
    <?php if ($totalDown > 0): ?>
    <div class="kpi-chip chip-dn"><?= $totalEq>0?round($totalDown/$totalEq*100).'%':'-' ?></div>
    <?php endif; ?>
  </div>
  <div class="kpi" style="animation-delay:.16s">
    <div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Overall Availability</span>
    <div class="kpi-num"><?= round($overallAvail*100) ?><sup>%</sup></div>
    <div class="kpi-chip <?= $overallAvail>=0.8?'chip-up':($overallAvail>=0.6?'chip-warn':'chip-dn') ?>">
      <?= $overallAvail>=0.8?'Good':($overallAvail>=0.6?'Fair':'Critical') ?>
    </div>
  </div>
</div>

<!-- ── Fleet type summary strip (matches PDF header table) ── -->
<?php if (!empty($report)): ?>
<div class="card mb-4" style="padding:0;overflow:hidden;">
  <div class="ch" style="padding:12px 20px 10px;">
    <div class="ct" style="font-size:13px;">Fleet Summary by Type</div>
    <div class="cs"><?= date('d/m/Y', strtotime($displayDate)) ?></div>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:0;border-top:1px solid var(--g2);">
    <?php foreach ($chartData as $i => $ct):
      $kpi   = $ct['kpi'];
      $color = $kpi >= 90 ? 'var(--green)' : ($kpi >= 70 ? 'var(--amber)' : 'var(--red)');
    ?>
    <div style="flex:1;min-width:130px;padding:14px 16px;border-right:1px solid var(--g2);<?= $i===count($chartData)-1?'border-right:none':'' ?>">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);margin-bottom:4px;"><?= htmlspecialchars($ct['type']) ?>s</div>
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;">
        <div style="font-size:22px;font-weight:800;color:<?= $color ?>;line-height:1;"><?= $kpi ?>%</div>
        <div style="font-size:10px;color:var(--t3);line-height:1.4;">
          <div><span style="color:var(--green);font-weight:600;"><?= $ct['available'] ?></span> avail</div>
          <div><span style="color:var(--red);font-weight:600;"><?= $ct['down'] ?></span> down / <?= $ct['total'] ?></div>
        </div>
      </div>
      <div style="height:4px;background:var(--g2);border-radius:3px;overflow:hidden;">
        <div style="height:100%;width:<?= $kpi ?>%;background:<?= $color ?>;border-radius:3px;"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Main detail table ── -->
<div class="card mb-4" style="overflow-x:auto;">
  <table class="tbl" style="min-width:1200px;font-size:11.5px;">
    <thead>
      <tr style="background:var(--surface);">
        <th style="width:90px;">TYPE</th>
        <th style="width:50px;text-align:center;"># EQ</th>
        <th style="width:55px;text-align:center;">AVAIL</th>
        <th style="width:45px;text-align:center;">DOWN</th>
        <th style="width:80px;">MACHINE ID</th>
        <th style="width:120px;">MODEL</th>
        <th style="width:80px;">LOCATION</th>
        <th>OBSERVATION</th>
        <th style="width:130px;">ONGOING ACTION</th>
        <th style="width:70px;text-align:center;">E/T</th>
        <th style="width:90px;text-align:center;">STATUS</th>
        <th style="width:75px;text-align:center;">DOWN TIME</th>
        <th style="width:75px;text-align:center;">UP TIME</th>
        <th style="width:60px;text-align:center;">TOTAL DT</th>
        <th style="width:90px;text-align:center;">START DATE</th>
        <th style="width:55px;text-align:center;">DAYS↓</th>
        <th style="width:70px;text-align:center;">AVAIL%</th>
        <th style="width:65px;text-align:center;">DAILY KPI</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($report)): ?>
      <tr><td colspan="18" style="text-align:center;padding:52px;color:var(--t3);">No equipment data found</td></tr>
      <?php else: ?>
      <?php foreach ($report as $type => $g): ?>
      <?php $first = true; ?>
      <?php foreach ($g['machines'] as $m): ?>
      <tr>
        <td style="font-weight:700;font-size:11px;background:var(--blue-d);color:var(--blue);text-align:center;vertical-align:middle;">
          <?= $first ? htmlspecialchars($g['type']) : '' ?>
        </td>
        <td style="text-align:center;font-weight:700;background:var(--g2);"><?= $first ? $g['total'] : '' ?></td>
        <td style="text-align:center;color:var(--green);font-weight:600;background:var(--g2);"><?= $first ? $g['available'] : '' ?></td>
        <td style="text-align:center;color:<?= $g['down']>0?'var(--red)':'var(--t3)' ?>;font-weight:600;background:var(--g2);"><?= $first ? $g['down'] : '' ?></td>
        <td class="mono" style="font-weight:600;color:var(--blue);font-size:11px;"><?= htmlspecialchars($m['machine_id']) ?></td>
        <td style="color:var(--t2);"><?= htmlspecialchars($m['model']) ?></td>
        <td style="color:var(--t3);font-size:11px;"><?= htmlspecialchars($m['location']) ?></td>
        <td style="max-width:200px;white-space:pre-wrap;word-break:break-word;color:var(--t2);"><?= htmlspecialchars($m['observation']) ?></td>
        <td style="max-width:130px;white-space:pre-wrap;word-break:break-word;color:var(--t2);font-size:11px;"><?= htmlspecialchars($m['action_taken']) ?></td>
        <td style="text-align:center;font-size:11px;color:var(--t3);"><?= htmlspecialchars($m['est_time']) ?></td>
        <td style="text-align:center;">
          <?php if ($m['status']==='Down'): ?>
          <span class="badge b-danger">Down</span>
          <?php else: ?>
          <span class="badge b-success">Available</span>
          <?php endif; ?>
        </td>
        <td class="mono" style="text-align:center;font-size:11px;color:var(--t3);"><?= htmlspecialchars($m['down_time']) ?></td>
        <td class="mono" style="text-align:center;font-size:11px;color:var(--t3);"><?= htmlspecialchars($m['up_time']) ?></td>
        <td class="mono" style="text-align:center;font-weight:600;<?= $m['total_dt']>0?'color:var(--amber)':'' ?>">
          <?= $m['total_dt'] > 0 ? number_format($m['total_dt'],1) : '' ?>
        </td>
        <td class="mono" style="text-align:center;font-size:11px;color:var(--t3);"><?= $m['start_date'] ? date('d/m/Y',strtotime($m['start_date'])) : '' ?></td>
        <td style="text-align:center;font-weight:600;<?= $m['days_down']>7?'color:var(--red)':($m['days_down']>0?'color:var(--amber)':'') ?>">
          <?= $m['days_down'] > 0 ? $m['days_down'] : '' ?>
        </td>
        <td style="text-align:center;font-weight:600;color:<?= $m['availability']>=0.9?'var(--green)':($m['availability']>0?'var(--amber)':'var(--red)') ?>;">
          <?= round($m['availability']*100) ?>%
        </td>
        <td style="text-align:center;font-weight:700;<?= $first?'color:var(--blue)':'color:var(--t4)' ?>">
          <?= $first ? round($g['daily_kpi']*100).'%' : '' ?>
        </td>
      </tr>
      <?php $first = false; endforeach; ?>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ── Per-type KPI bar charts (matches PDF layout) ── -->
<?php if (!empty($chartData)): ?>
<div class="grid g-<?= min(count($chartData), 3) ?> mb-3" style="gap:16px;">
  <?php foreach ($chartData as $ct): ?>
  <div class="card" style="padding:0;">
    <div class="ch" style="padding:12px 16px 8px;">
      <div>
        <div class="ct" style="font-size:12px;">Daily <?= htmlspecialchars($ct['type']) ?>s KPI</div>
        <div class="cs">Availability %</div>
      </div>
      <div style="font-size:18px;font-weight:800;color:<?= $ct['kpi']>=90?'var(--green)':($ct['kpi']>=70?'var(--amber)':'var(--red)') ?>;"><?= $ct['kpi'] ?>%</div>
    </div>
    <div style="padding:8px 12px 14px;">
      <canvas id="chart_<?= preg_replace('/\W/','',$ct['type']) ?>" style="max-height:140px;"></canvas>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
(function(){
  var charts = <?= json_encode($chartData) ?>;
  charts.forEach(function(ct) {
    var id = 'chart_' + ct.type.replace(/\W/g,'');
    var canvas = document.getElementById(id);
    if (!canvas) return;
    var colors = ct.values.map(function(v){
      return v >= 90 ? 'rgba(28,140,60,0.85)' : (v >= 70 ? 'rgba(180,83,9,0.85)' : 'rgba(192,57,43,0.85)');
    });
    new Chart(canvas, {
      type: 'bar',
      data: {
        labels: ct.labels,
        datasets: [{
          data: ct.values,
          backgroundColor: colors,
          borderRadius: 4,
          borderSkipped: false,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(ctx){ return ctx.parsed.y + '%'; }
            }
          }
        },
        scales: {
          x: {
            ticks: { font:{ size:9 }, color:'#888', maxRotation:45 },
            grid: { display:false }
          },
          y: {
            min: 0, max: 100,
            ticks: {
              font:{ size:9 }, color:'#888',
              callback: function(v){ return v+'%'; },
              stepSize: 25
            },
            grid: { color:'rgba(0,0,0,0.04)' }
          }
        }
      }
    });
  });
})();
</script>
<?php endif; ?>

<style>
@media print {
  #sidebar, #canvas .topbar, .btn, form { display:none!important; }
  #canvas { margin-left:0!important; height:auto!important; overflow:visible!important; }
  .card { box-shadow:none!important; }
  .tbl td, .tbl th { font-size:8pt!important; padding:3px 5px!important; }
}
</style>
