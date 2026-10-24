<?php
$today      = date('Y-m-d');
$ws         = isset($week_start) ? $week_start : (isset($report_week_start) ? $report_week_start : date('Y-m-d'));
$we         = isset($week_end)   ? $week_end   : date('Y-m-d', strtotime($ws.' +6 days'));
$wkLabel    = date('d M', strtotime($ws)).' – '.date('d M Y', strtotime($we));
$inputWs    = $ws;

// Totals
$totalEq = $totalAvail = $totalDown = 0;
foreach ($report as $g) {
    $totalEq    += $g['total'];
    $totalAvail += $g['available'];
    $totalDown  += $g['down'];
}
$overallPct = $totalEq > 0 ? round($totalAvail / $totalEq * 100) : 0;
// One target for the header (Q1): the per-type targets below are settings /
// equipment_kpi_targets values, so the header reads the same source.
$fleetTarget = 88.0;
foreach ($report as $g) {
    if (isset($g['target']) && (float)$g['target'] > 0) { $fleetTarget = round((float)$g['target'] * 100); break; }
}

// Chart data for JS
$chartData = [];
foreach ($report as $type => $g) {
    $labels = []; $values = [];
    foreach ($g['machines'] as $m) {
        $labels[] = $m['machine_id'];
        $values[] = round($m['availability'] * 100);
    }
    $chartData[] = [
        'type'      => $g['type'],
        'kpi'       => round($g['weekly_kpi'] * 100),
        'available' => $g['available'],
        'down'      => $g['down'],
        'total'     => $g['total'],
        'target'    => round($g['target'] * 100),
        'labels'    => $labels,
        'values'    => $values,
    ];
}
?>

<!-- ── Toolbar ── -->
<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">Workshop Weekly Report</h1>
    <div class="cs"><?= $wkLabel ?></div>
  </div>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <form method="get" class="flex ai-c gap-10">
      <label style="font-size:11px;color:var(--t3);white-space:nowrap;">Week start</label>
      <input type="date" name="week_start" class="form-control" style="width:155px;"
             value="<?= $inputWs ?>" max="<?= $today ?>">
      <button type="submit" class="btn btn-primary btn-sm">Load</button>
    </form>
    <a href="<?= base_url('reports/export_workshop_weekly_xlsx?week_start='.$ws) ?>"
       class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Excel
    </a>
    <a href="<?= base_url('reports/export_workshop_weekly_pdf?week_start='.$ws) ?>"
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

<!-- ── KPI Strip ── -->
<div class="grid g-4 mb-4">
  <div class="kpi" style="animation-delay:.04s">
    <div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total Fleet</span>
    <div class="kpi-num"><?= $totalEq ?><sup>units</sup></div>
  </div>
  <div class="kpi" style="animation-delay:.08s">
    <div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Operational</span>
    <div class="kpi-num"><?= $totalAvail ?></div>
    <div class="kpi-chip chip-up">↑ <?= $totalEq>0?round($totalAvail/$totalEq*100).'%':'-' ?></div>
  </div>
  <div class="kpi" style="animation-delay:.12s">
    <div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Not Operational</span>
    <div class="kpi-num"><?= $totalDown ?></div>
    <?php if ($totalDown > 0): ?>
    <div class="kpi-chip chip-dn"><?= $totalEq>0?round($totalDown/$totalEq*100).'%':'-' ?></div>
    <?php endif; ?>
  </div>
  <div class="kpi" style="animation-delay:.16s">
    <div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Fleet Readiness (machines up)</span>
    <div class="kpi-num"><?= $overallPct ?><sup>%</sup></div>
    <div class="kpi-chip <?= $overallPct>=90?'chip-up':($overallPct>=70?'chip-warn':'chip-dn') ?>">
      machines up ÷ total
    </div>
  </div>
</div>

<!-- ── Legend + Fleet Summary ── -->
<div class="flex gap-10 mb-4" style="flex-wrap:wrap;align-items:flex-start;">

  <!-- Legend card -->
  <div class="card" style="padding:14px 18px;min-width:170px;flex-shrink:0;">
    <div class="ct" style="font-size:11px;margin-bottom:10px;">Legends</div>
    <div style="display:flex;flex-direction:column;gap:6px;font-size:11.5px;">
      <div class="flex ai-c gap-8">
        <span style="width:30px;text-align:center;font-weight:700;color:var(--green);background:var(--green-d);border-radius:4px;padding:1px 4px;">O</span>
        <span style="color:var(--t2);">Operational</span>
      </div>
      <div class="flex ai-c gap-8">
        <span style="width:30px;text-align:center;font-weight:700;color:var(--red);background:var(--red-d);border-radius:4px;padding:1px 4px;">BD</span>
        <span style="color:var(--t2);">Breakdown</span>
      </div>
      <div class="flex ai-c gap-8">
        <span style="width:30px;text-align:center;font-weight:700;color:var(--amber);background:var(--amber-d);border-radius:4px;padding:1px 4px;">WSP</span>
        <span style="color:var(--t2);">Waiting Sparepart</span>
      </div>
      <div class="flex ai-c gap-8">
        <span style="width:30px;text-align:center;font-weight:700;color:var(--t2);background:var(--g2);border-radius:4px;padding:1px 4px;">UM</span>
        <span style="color:var(--t2);">Under Maintenance</span>
      </div>
    </div>
  </div>

  <!-- Fleet summary by type -->
  <div class="card" style="padding:0;overflow:hidden;flex:1;min-width:300px;">
    <div class="ch" style="padding:12px 18px 10px;">
      <div class="ct" style="font-size:12px;">Fleet Summary by Type</div>
      <div class="cs">Week of <?= $wkLabel ?> · Target ≥ <?= $fleetTarget ?>%</div>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:0;border-top:1px solid var(--g2);">
      <?php foreach ($chartData as $i => $ct):
        $kpi   = $ct['kpi'];
        $tgt   = $ct['target'];
        $color = $kpi >= $tgt ? 'var(--green)' : ($kpi >= $tgt - 10 ? 'var(--amber)' : 'var(--red)');
        $diff  = $kpi - $tgt;
      ?>
      <div style="flex:1;min-width:110px;padding:12px 14px;border-right:1px solid var(--g2);<?= $i===count($chartData)-1?'border-right:none':'' ?>">
        <div style="font-size:11px;font-weight:500;text-transform:none;letter-spacing:0;color:var(--t3);margin-bottom:4px;"><?= htmlspecialchars((string)($ct['type'])) ?>s</div>
        <div style="font-size:22px;font-weight:800;color:<?= $color ?>;line-height:1;margin-bottom:4px;"><?= $kpi ?>%</div>
        <div style="height:4px;background:var(--g2);border-radius:3px;overflow:hidden;margin-bottom:5px;">
          <div style="height:100%;width:<?= $kpi ?>%;background:<?= $color ?>;border-radius:3px;"></div>
        </div>
        <div style="font-size:10px;color:var(--t3);">
          <span style="color:var(--green);font-weight:600;"><?= $ct['available'] ?> op</span>
          <?php if ($ct['down']>0): ?>· <span style="color:var(--red);font-weight:600;"><?= $ct['down'] ?> dn</span><?php endif; ?>
          / <?= $ct['total'] ?>
        </div>
        <div style="font-size:9.5px;margin-top:2px;color:<?= $diff>=0?'var(--green)':'var(--red)' ?>;font-weight:600;">
          <?= $diff>=0?'↑ +':'↓ ' ?><?= abs($diff) ?>% vs <?= $tgt ?>% target
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- ── Main detail table ── -->
<div class="card mb-4" style="overflow-x:auto;">
  <table class="tbl" style="min-width:1400px;font-size:11px;">
    <thead>
      <tr style="background:var(--surface);">
        <th rowspan="2" style="width:80px;">TYPE</th>
        <th rowspan="2" style="width:45px;text-align:center;"># EQ</th>
        <th rowspan="2" style="width:50px;text-align:center;">AVAIL</th>
        <th rowspan="2" style="width:45px;text-align:center;">DOWN</th>
        <th rowspan="2" style="width:80px;">MACHINE ID</th>
        <th rowspan="2" style="width:110px;">MODEL</th>
        <th rowspan="2" style="width:80px;">LOCATION</th>
        <th rowspan="2" style="width:80px;text-align:center;">START DATE</th>
        <th rowspan="2" style="width:55px;text-align:center;">DAYS DOWN</th>
        <th rowspan="2" style="width:70px;text-align:center;">AVAIL%</th>
        <th rowspan="2" style="width:65px;text-align:center;">WEEKLY KPI</th>
        <th rowspan="2" style="width:60px;text-align:center;">TARGET</th>
        <th rowspan="2" style="width:65px;text-align:center;">ANALYSIS</th>
        <th colspan="2" style="text-align:center;">INTERVENTION</th>
        <th rowspan="2" style="width:80px;text-align:center;">DEADLINE</th>
        <th rowspan="2" style="width:90px;text-align:center;">STATUS</th>
      </tr>
      <tr style="background:var(--surface);">
        <th style="width:200px;">OBSERVATION</th>
        <th style="width:200px;">ACTION / DATES</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($report)): ?>
      <tr><td colspan="17" style="text-align:center;padding:52px;color:var(--t3);">No data found for this week</td></tr>
      <?php else: ?>
      <?php foreach ($report as $type => $g): ?>
      <?php $first = true; ?>
      <?php foreach ($g['machines'] as $m):
        $avail    = round($m['availability'] * 100);
        $tgt      = round($m['target'] * 100);
        $diff     = $avail - $tgt;
        $avColor  = $avail >= $tgt ? 'var(--green)' : ($avail >= $tgt - 10 ? 'var(--amber)' : 'var(--red)');
        $anaColor = $diff >= 0 ? 'var(--green)' : 'var(--red)';
        $stCode   = $m['status_code'];
        $stColor  = $stCode==='O' ? 'var(--green)' : ($stCode==='WSP' ? 'var(--amber)' : 'var(--red)');
        $stBg     = $stCode==='O' ? 'var(--green-d)' : ($stCode==='WSP' ? 'var(--amber-d)' : 'var(--red-d)');
      ?>
      <tr style="vertical-align:top;">
        <td style="font-weight:700;font-size:10px;background:var(--blue-d);color:var(--blue);text-align:center;vertical-align:middle;">
          <?= $first ? htmlspecialchars((string)($g['type'])) : '' ?>
        </td>
        <td style="text-align:center;font-weight:700;background:var(--g2);vertical-align:middle;"><?= $first ? $g['total'] : '' ?></td>
        <td style="text-align:center;color:var(--green);font-weight:600;background:var(--g2);vertical-align:middle;"><?= $first ? $g['available'] : '' ?></td>
        <td style="text-align:center;color:<?= $g['down']>0?'var(--red)':'var(--t3)' ?>;font-weight:600;background:var(--g2);vertical-align:middle;"><?= $first ? $g['down'] : '' ?></td>
        <td class="mono" style="font-weight:600;color:var(--blue);font-size:10.5px;vertical-align:middle;"><?= htmlspecialchars((string)($m['machine_id'])) ?></td>
        <td style="color:var(--t2);vertical-align:middle;"><?= htmlspecialchars((string)($m['model'])) ?></td>
        <td style="color:var(--t3);font-size:10.5px;vertical-align:middle;text-align:center;"><?= htmlspecialchars((string)($m['location'])) ?></td>
        <td class="mono" style="text-align:center;font-size:10.5px;color:var(--t3);vertical-align:middle;"><?= $m['start_date'] ?></td>
        <td style="text-align:center;font-weight:600;vertical-align:middle;<?= $m['days_down']>14?'color:var(--red)':($m['days_down']>7?'color:var(--amber)':'') ?>">
          <?= $m['days_down']>0 ? $m['days_down'] : '' ?>
        </td>
        <td style="text-align:center;font-weight:700;color:<?= $avColor ?>;vertical-align:middle;"><?= $avail ?>%</td>
        <td style="text-align:center;font-weight:700;color:var(--blue);background:var(--blue-d);vertical-align:middle;">
          <?= $first ? round($g['weekly_kpi']*100).'%' : '' ?>
        </td>
        <td style="text-align:center;font-weight:600;color:var(--t3);vertical-align:middle;"><?= $tgt ?>%</td>
        <td style="text-align:center;font-weight:700;color:<?= $anaColor ?>;vertical-align:middle;">
          <?= ($diff>=0?'↑ +':'↓ ').abs($diff).'%' ?>
        </td>
        <!-- Observations — numbered list -->
        <td style="padding:6px 8px;font-size:10.5px;line-height:1.5;">
          <?php if (empty($m['observations'])): ?>
          <span style="color:var(--t4);font-style:italic;">Operational</span>
          <?php else: ?>
          <?php foreach ($m['observations'] as $obs): ?>
          <div style="margin-bottom:2px;">• <?= htmlspecialchars((string)($obs)) ?></div>
          <?php endforeach; ?>
          <?php endif; ?>
        </td>
        <!-- Actions + dates -->
        <td style="padding:6px 8px;font-size:10.5px;line-height:1.5;">
          <?php if (empty($m['actions'])): ?>
          <span style="color:var(--t4);font-style:italic;">—</span>
          <?php else: ?>
          <?php foreach ($m['actions'] as $ai => $act): ?>
          <div style="margin-bottom:3px;">
            • <?= htmlspecialchars((string)($act)) ?>
            <?php if (!empty($m['wo_dates'][$ai]) && $m['wo_dates'][$ai] !== 'N/A'): ?>
            <span style="color:var(--t4);font-size:9.5px;margin-left:4px;"><?= $m['wo_dates'][$ai] ?></span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </td>
        <td class="mono" style="text-align:center;font-size:10.5px;color:var(--t3);vertical-align:middle;"><?= $m['deadline'] ?></td>
        <td style="text-align:center;vertical-align:middle;">
          <span style="display:inline-block;padding:3px 8px;border-radius:5px;font-weight:700;font-size:11px;color:<?= $stColor ?>;background:<?= $stBg ?>;"><?= $stCode ?></span>
          <div style="font-size:9.5px;color:var(--t3);margin-top:2px;"><?= $m['status_label'] ?></div>
        </td>
      </tr>
      <?php $first = false; endforeach; ?>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ── Per-type KPI bar charts ── -->
<?php if (!empty($chartData)): ?>
<div class="grid g-<?= min(count($chartData), 3) ?> mb-3" style="gap:16px;">
  <?php foreach ($chartData as $ct):
    $kpi   = $ct['kpi'];
    $tgt   = $ct['target'];
    $color = $kpi>=$tgt ? 'var(--green)' : ($kpi>=70?'var(--amber)':'var(--red)');
  ?>
  <div class="card" style="padding:0;">
    <div class="ch" style="padding:12px 16px 8px;">
      <div>
        <div class="ct" style="font-size:12px;">Weekly <?= htmlspecialchars((string)($ct['type'])) ?>s KPI</div>
        <div class="cs">Target <?= $tgt ?>%</div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:18px;font-weight:800;color:<?= $color ?>;"><?= $kpi ?>%</div>
        <div style="font-size:9.5px;color:<?= $kpi>=$tgt?'var(--green)':'var(--red)' ?>;">
          <?= $kpi>=$tgt?'↑ +'.($kpi-$tgt):'↓ '.($tgt-$kpi) ?>% vs target
        </div>
      </div>
    </div>
    <!-- Target line annotation -->
    <div style="padding:0 12px;font-size:9.5px;color:var(--t3);text-align:right;margin-bottom:2px;">
      — target <?= $tgt ?>%
    </div>
    <div style="padding:4px 12px 14px;position:relative;">
      <canvas id="wchart_<?= preg_replace('/\W/','',$ct['type']) ?>" style="max-height:150px;"></canvas>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
(function(){
  var charts = <?= json_encode($chartData) ?>;
  charts.forEach(function(ct) {
    var id = 'wchart_' + ct.type.replace(/\W/g,'');
    var canvas = document.getElementById(id);
    if (!canvas) return;
    var colors = ct.values.map(function(v){
      return v >= 90 ? 'rgba(28,140,60,0.85)' : (v >= 70 ? 'rgba(180,83,9,0.85)' : 'rgba(192,57,43,0.85)');
    });
    new Chart(canvas, {
      type: 'bar',
      data: {
        labels: ct.labels,
        datasets: [
          {
            data: ct.values,
            backgroundColor: colors,
            borderRadius: 4,
            borderSkipped: false,
            order: 1,
          },
          {
            // Target line
            type: 'line',
            data: ct.labels.map(function(){ return ct.target; }),
            borderColor: 'rgba(255,255,255,0.5)',
            borderWidth: 1.5,
            borderDash: [4,3],
            pointRadius: 0,
            fill: false,
            order: 0,
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(ctx){
                if (ctx.datasetIndex===1) return 'Target: '+ct.target+'%';
                return ctx.parsed.y + '%';
              }
            }
          }
        },
        scales: {
          x: {
            ticks: { font:{size:9}, color:'#888', maxRotation:45 },
            grid: { display:false }
          },
          y: {
            min: 0, max: 100,
            ticks: { font:{size:9}, color:'#888', callback:function(v){return v+'%';}, stepSize:25 },
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
  #sidebar, #canvas .topbar, .btn, form, .gap-10 { display:none!important; }
  #canvas { margin-left:0!important; height:auto!important; overflow:visible!important; }
  .card { box-shadow:none!important; page-break-inside:avoid; }
  .tbl td, .tbl th { font-size:7.5pt!important; padding:2px 4px!important; }
}
</style>
