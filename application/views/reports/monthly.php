<?php
$month_name = date('F Y', strtotime($date_from));
$kpi_avail  = isset($kpi['availability']) ? $kpi['availability'] : 0;
$kpi_mttr   = isset($kpi['mttr'])         ? $kpi['mttr']         : 0;
$kpi_mtbf   = isset($kpi['mtbf'])         ? $kpi['mtbf']         : 0;
$kpi_bkd    = isset($kpi['breakdowns'])   ? $kpi['breakdowns']   : 0;
$kpi_dt     = isset($kpi['total_downtime']) ? $kpi['total_downtime'] : 0;

// WO summary
$wo_by_status = array();
$wo_by_type   = array();
foreach ($work_orders as $wo_item) {
    $wo_by_status[$wo_item->status] = (isset($wo_by_status[$wo_item->status]) ? $wo_by_status[$wo_item->status] : 0) + 1;
    $wo_by_type[$wo_item->type]     = (isset($wo_by_type[$wo_item->type])     ? $wo_by_type[$wo_item->type]     : 0) + 1;
}
?>

<!-- ═══ REPORT TOOLBAR ═══ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:28px;">
  <div>
    <h1 style="font-family:inherit;font-size:20px;font-weight:800;letter-spacing:-.4px;color:var(--t1);">Monthly Report &mdash; <?= $month_name; ?></h1>
    <div style="font-size:12px;color:var(--t3);margin-top:2px;">Generated <?= date('d M Y, H:i'); ?></div>
  </div>
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
    <form method="get" style="display:flex;align-items:center;gap:8px;">
      <input type="month" name="month" class="form-control" style="width:160px;" value="<?= htmlspecialchars((string)($month)); ?>">
      <button type="submit" class="btn btn-primary btn-sm">Load</button>
    </form>
    <button onclick="window.print()" class="btn btn-ghost btn-sm btn-icon">
      <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print
    </button>
    <a href="<?= base_url('reports/export_excel/workorders?month='.$month); ?>" class="btn btn-ghost btn-sm btn-icon">
      <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export CSV
    </a>
  </div>
</div>

<!-- ═══ KPI STRIP ═══ -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px;">
  <?php
  // Q1: the target is the engine's, passed in with the KPI figures
  $avail_target = isset($kpi['target']) ? (float)$kpi['target'] : 88.0;
  $avail_color = ($kpi_avail >= $avail_target) ? 'var(--green)' : (($kpi_avail >= $avail_target - 10) ? 'var(--amber)' : 'var(--red)');
  ?>
  <div class="kpi" style="animation-delay:.04s;">
    <div class="kpi-glow" style="background:<?= $avail_color; ?>;"></div>
    <span class="kpi-tag">Availability</span>
    <div class="kpi-num" style="color:<?= $avail_color; ?>;font-size:28px;"><?= $kpi_avail; ?><sup>%</sup></div>
    <div style="font-size:10px;color:var(--t4);margin-top:6px;">Target ≥ <?= $avail_target ?>% · <?= (int)$kpi['open_hours'] ?> h open</div>
  </div>
  <div class="kpi" style="animation-delay:.08s;">
    <div class="kpi-glow" style="background:var(--amber);"></div>
    <span class="kpi-tag">MTTR</span>
    <div class="kpi-num" style="font-size:28px;"><?= $kpi_mttr; ?><sup>hrs</sup></div>
    <div style="font-size:10px;color:var(--t4);margin-top:6px;">Mean Time To Repair</div>
  </div>
  <div class="kpi" style="animation-delay:.12s;">
    <div class="kpi-glow" style="background:var(--blue);"></div>
    <span class="kpi-tag">MTBF</span>
    <div class="kpi-num" style="font-size:28px;"><?= $kpi_mtbf; ?><sup>hrs</sup></div>
    <div style="font-size:10px;color:var(--t4);margin-top:6px;">Mean Time Between Failures</div>
  </div>
  <div class="kpi" style="animation-delay:.16s;">
    <div class="kpi-glow" style="background:var(--red);"></div>
    <span class="kpi-tag">Breakdowns</span>
    <div class="kpi-num" style="font-size:28px;color:var(--red);"><?= $kpi_bkd; ?></div>
    <div style="font-size:10px;color:var(--t4);margin-top:6px;"><?= number_format((float)($kpi_dt),1); ?> hrs downtime</div>
  </div>
</div>

<!-- ═══ CHARTS ROW ═══ -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:24px;">

  <!-- Availability trend -->
  <div class="card">
    <div class="ch"><div class="ct">Equipment Availability Trend</div><div class="cs">Last 30 days</div></div>
    <div style="padding:16px 20px 20px;">
      <div style="height:180px;"><canvas id="chartAvail"></canvas></div>
    </div>
  </div>

  <!-- WO summary -->
  <div class="card">
    <div class="ch"><div class="ct">Work Orders Summary</div><div class="cs"><?= count($work_orders); ?> total this month</div></div>
    <div style="padding:16px 20px;">
      <?php if (!empty($wo_by_type)): ?>
      <div style="margin-bottom:14px;">
        <div style="font-family:inherit;font-size:9px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--t4);margin-bottom:8px;">By Type</div>
        <?php foreach ($wo_by_type as $type => $cnt): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:7px;font-size:12px;">
          <span style="color:var(--t2);"><?= htmlspecialchars((string)($type)); ?></span>
          <span style="font-family:inherit;font-weight:500;color:var(--t1);"><?= $cnt; ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="height:1px;background:var(--sep);margin-bottom:14px;"></div>
      <?php endif; ?>
      <div>
        <div style="font-family:inherit;font-size:9px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--t4);margin-bottom:8px;">By Status</div>
        <?php
        $st_colors = array('Open'=>'var(--blue)','In Progress'=>'var(--amber)','Waiting Parts'=>'var(--purple)','Waiting Tools'=>'var(--cyan)','Completed'=>'var(--green)','Closed'=>'var(--t3)');
        foreach ($wo_by_status as $status => $cnt):
          $sc = isset($st_colors[$status]) ? $st_colors[$status] : 'var(--t2)';
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:7px;">
          <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--t2);">
            <span style="width:6px;height:6px;border-radius:50%;background:<?= $sc; ?>;flex-shrink:0;"></span>
            <?= htmlspecialchars((string)($status)); ?>
          </span>
          <span style="font-family:inherit;font-size:12px;font-weight:500;color:var(--t1);"><?= $cnt; ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($wo_by_status)): ?>
        <div style="color:var(--t4);font-size:12px;text-align:center;padding:12px 0;">No work orders this month</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══ WORK ORDERS TABLE ═══ -->
<div class="card" style="margin-bottom:20px;">
  <div class="ch">
    <div>
      <div class="ct">Work Orders &mdash; <?= $month_name; ?></div>
      <div class="cs"><?= count($work_orders); ?> records</div>
    </div>
  </div>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:0;">
      <thead>
        <tr>
          <th>WO Number</th>
          <th>Equipment</th>
          <th>Type</th>
          <th>Priority</th>
          <th>Status</th>
          <th>Start</th>
          <th>End</th>
          <th>Downtime</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($work_orders as $wo_item):
          $pmap = array('Critical'=>'b-critical','High'=>'b-high','Medium'=>'b-medium','Low'=>'b-low');
          $smap = array('Open'=>'b-open','In Progress'=>'b-progress','Waiting Parts'=>'b-waiting','Waiting Tools'=>'b-waiting','Completed'=>'b-completed','Closed'=>'b-closed');
          $pc = isset($pmap[$wo_item->priority]) ? $pmap[$wo_item->priority] : 'b-secondary';
          $sc = isset($smap[$wo_item->status])   ? $smap[$wo_item->status]   : 'b-secondary';
        ?>
        <tr>
          <td class="mono" style="font-size:11.5px;font-weight:500;color:var(--blue);"><?= htmlspecialchars((string)($wo_item->wo_number)); ?></td>
          <td style="font-weight:600;font-size:12px;"><?= htmlspecialchars((string)($wo_item->registration_no??'—')); ?></td>
          <td><span class="badge b-primary" style="font-size:10px;"><?= htmlspecialchars((string)($wo_item->type)); ?></span></td>
          <td><span class="badge <?= $pc; ?>"><?= htmlspecialchars((string)($wo_item->priority)); ?></span></td>
          <td><span class="badge <?= $sc; ?>"><?= htmlspecialchars((string)($wo_item->status)); ?></span></td>
          <td class="mono" style="font-size:11px;color:var(--t3);"><?= $wo_item->start_datetime ? date('d M H:i', strtotime($wo_item->start_datetime)) : '—'; ?></td>
          <td class="mono" style="font-size:11px;color:var(--t3);"><?= $wo_item->end_datetime ? date('d M H:i', strtotime($wo_item->end_datetime)) : '—'; ?></td>
          <td class="mono" style="font-size:11px;"><?= $wo_item->downtime_hours ? number_format((float)($wo_item->downtime_hours),1).' hrs' : '—'; ?></td>
          <td><a href="<?= base_url('workorders/view/'.$wo_item->id); ?>" class="btn btn-ghost btn-sm" style="font-size:11px;padding:4px 12px;">View</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($work_orders)): ?>
        <tr><td colspan="9" style="text-align:center;padding:48px;color:var(--t3);">No work orders in <?= $month_name; ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ ATTENDANCE SUMMARY ═══ -->
<div class="card" style="margin-bottom:20px;">
  <div class="ch"><div class="ct">Attendance Summary</div><div class="cs"><?= $month_name; ?></div></div>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:0;">
      <thead>
        <tr><th>Employee</th><th>Position</th><th>Present</th><th>Absent</th><th>Leave</th><th>Rest</th><th>Holiday</th><th>Working Days</th><th>Rate</th></tr>
      </thead>
      <tbody>
        <?php foreach ($attendance as $a):
          $pct = isset($a->pct) ? $a->pct : 0;
          $bar_color = $pct >= 90 ? 'var(--green)' : ($pct >= 75 ? 'var(--amber)' : 'var(--red)');
        ?>
        <tr>
          <td style="font-weight:600;font-size:12.5px;"><?= htmlspecialchars((string)($a->full_name)); ?></td>
          <td style="font-size:11.5px;color:var(--t3);"><?= htmlspecialchars((string)($a->position)); ?></td>
          <td style="font-family:inherit;font-size:12px;color:var(--green);font-weight:500;"><?= $a->present; ?></td>
          <td style="font-family:inherit;font-size:12px;color:var(--red);font-weight:500;"><?= $a->absent; ?></td>
          <td style="font-family:inherit;font-size:12px;color:var(--amber);font-weight:500;"><?= $a->leave; ?></td>
          <td style="font-family:inherit;font-size:12px;color:var(--t3);"><?= $a->rest; ?></td>
          <td style="font-family:inherit;font-size:12px;color:var(--t3);"><?= isset($a->holiday) ? $a->holiday : 0; ?></td>
          <td style="font-family:inherit;font-size:12px;"><?= $a->total; ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="flex:1;height:5px;background:var(--sep);border-radius:100px;overflow:hidden;">
                <div style="width:<?= $pct; ?>%;height:100%;background:<?= $bar_color; ?>;border-radius:100px;"></div>
              </div>
              <span style="font-family:inherit;font-size:11px;font-weight:600;min-width:32px;color:<?= $bar_color; ?>;"><?= $pct; ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($attendance)): ?>
        <tr><td colspan="9" style="text-align:center;padding:48px;color:var(--t3);">No attendance records</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ LUBRICANT SUMMARY ═══ -->
<div class="card">
  <div class="ch"><div class="ct">Lubricant Consumption</div><div class="cs"><?= $month_name; ?></div></div>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:0;">
      <thead>
        <tr><th>Lubricant Type</th><th>Category</th><th>Total Used</th><th>Recommended</th><th>Variance</th><th>Records</th></tr>
      </thead>
      <tbody>
        <?php foreach ($lube as $l):
          $var = isset($l->total_rec) && $l->total_rec ? ($l->total_used - $l->total_rec) : null;
          $var_color = ($var === null) ? 'var(--t3)' : (($var > 0) ? 'var(--red)' : (($var < 0) ? 'var(--blue)' : 'var(--green)'));
        ?>
        <tr>
          <td style="font-weight:600;font-size:12.5px;"><?= htmlspecialchars((string)($l->lube_name)); ?></td>
          <td><span class="badge b-primary" style="font-size:10px;"><?= htmlspecialchars((string)($l->category??'')); ?></span></td>
          <td style="font-family:inherit;font-size:12px;font-weight:500;"><?= number_format((float)($l->total_used),1); ?> <span style="color:var(--t4);font-size:10px;"><?= htmlspecialchars((string)($l->unit??'')); ?></span></td>
          <td style="font-family:inherit;font-size:12px;color:var(--t3);"><?= isset($l->total_rec)&&$l->total_rec ? number_format((float)($l->total_rec),1) : '—'; ?></td>
          <td style="font-family:inherit;font-size:12px;font-weight:500;color:<?= $var_color; ?>;">
            <?= ($var !== null) ? (($var > 0 ? '+' : '') . number_format((float)($var),1)) : '—'; ?>
          </td>
          <td style="font-family:inherit;font-size:12px;"><?= isset($l->records) ? $l->records : 0; ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($lube)): ?>
        <tr><td colspan="6" style="text-align:center;padding:48px;color:var(--t3);">No lubricant records this month</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
var availData = <?= json_encode(isset($availability) ? $availability : array()); ?>;
var ctx = document.getElementById('chartAvail');
if (ctx && typeof Chart !== 'undefined' && availData.length > 0) {
  var labels = availData.map(function(d){ return d.date.slice(5); });
  var values = availData.map(function(d){ return d.pct; });
  var c = ctx.getContext('2d');
  var g = c.createLinearGradient(0,0,0,180);
  g.addColorStop(0,'rgba(0,113,227,0.15)');
  g.addColorStop(1,'rgba(0,113,227,0)');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        { label: 'Availability %', data: values, borderColor: 'var(--blue)', backgroundColor: g, fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 4, borderWidth: 2 },
        { label: 'Target <?= $avail_target ?>%', data: Array(values.length).fill(<?= (float)$avail_target ?>), borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1.5, borderDash: [5,5], fill: false, pointRadius: 0 }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { color: 'rgba(0,0,0,0.03)' }, border: { display: false }, ticks: { color: 'rgba(0,0,0,0.30)', maxTicksLimit: 8 } },
        y: { grid: { color: 'rgba(0,0,0,0.03)' }, border: { display: false }, min: 60, max: 100,
             ticks: { color: 'rgba(0,0,0,0.30)', callback: function(v){ return v+'%'; } } }
      }
    }
  });
}
</script>

<style>
@media print {
  #sidebar, #canvas .topbar, .btn, form { display: none !important; }
  #canvas { margin-left: 0 !important; }
  .card { box-shadow: none !important; border: 1px solid #ddd !important; }
}
</style>
