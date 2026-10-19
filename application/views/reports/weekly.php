<?php
$from_fmt = date('d M Y', strtotime($week_start));
$to_fmt   = date('d M Y', strtotime($week_end));
$avail    = (isset($kpi['availability']) ? $kpi['availability'] : 0);
$mttr     = (isset($kpi['mttr']) ? $kpi['mttr'] : 0);
$mtbf     = (isset($kpi['mtbf']) ? $kpi['mtbf'] : 0);
$bds      = (isset($kpi['breakdowns']) ? $kpi['breakdowns'] : 0);
$downtime = (isset($kpi['total_downtime']) ? $kpi['total_downtime'] : 0);
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:12px;">
  <div>
    <div class="page-title">Weekly Report</div>
    <div style="font-size:12px;color:var(--t3);margin-top:3px;"><?= $from_fmt ?> — <?= $to_fmt ?></div>
  </div>
  <div class="flex ai-c gap-10">
    <form method="get" class="flex ai-c gap-10">
      <input type="week" name="week" class="form-control" style="width:180px;"
        value="<?= date('Y-\WW', strtotime($week_start)) ?>"
        onchange="this.form.submit()">
    </form>
    <a href="<?= base_url('reports/daily') ?>" class="btn btn-ghost">Daily</a>
    <a href="<?= base_url('reports/monthly') ?>" class="btn btn-ghost">Monthly</a>
    <button class="btn btn-primary btn-icon" onclick="window.print()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print
    </button>
  </div>
</div>

<!-- KPI Strip -->
<div class="grid" style="grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:20px;">
  <div class="kpi" style="animation-delay:.04s;">
    <div class="kpi-glow" style="background:var(--green);"></div>
    <span class="kpi-tag">Availability</span>
    <div class="kpi-num"><?= $avail ?>%</div>
  </div>
  <div class="kpi" style="animation-delay:.08s;">
    <div class="kpi-glow" style="background:var(--red);"></div>
    <span class="kpi-tag">Breakdowns</span>
    <div class="kpi-num"><?= $bds ?></div>
  </div>
  <div class="kpi" style="animation-delay:.12s;">
    <div class="kpi-glow" style="background:var(--amber);"></div>
    <span class="kpi-tag">MTTR (hrs)</span>
    <div class="kpi-num"><?= number_format($mttr, 1) ?></div>
  </div>
  <div class="kpi" style="animation-delay:.16s;">
    <div class="kpi-glow" style="background:var(--blue);"></div>
    <span class="kpi-tag">MTBF (hrs)</span>
    <div class="kpi-num"><?= number_format($mtbf, 1) ?></div>
  </div>
  <div class="kpi" style="animation-delay:.20s;">
    <div class="kpi-glow" style="background:var(--purple);"></div>
    <span class="kpi-tag">Total Downtime</span>
    <div class="kpi-num"><?= number_format($downtime, 1) ?><span style="font-size:12px;">h</span></div>
  </div>
</div>

<!-- Work Orders -->
<div class="card mb-3">
  <div class="ch"><div class="ct">Work Orders This Week</div><div class="ca"><?= count($work_orders) ?> total</div></div>
  <?php if ($work_orders): ?>
  <table class="dt">
    <thead><tr>
      <th>WO Number</th><th>Equipment</th><th>Type</th><th>Priority</th>
      <th>Status</th><th>Assigned To</th><th>Created</th>
    </tr></thead>
    <tbody>
    <?php foreach ($work_orders as $wo):
      $sc = (array_key_exists($wo->status, array('Open'=>'b-open','In Progress'=>'b-progress','Waiting Parts'=>'b-waiting','Completed'=>'b-completed','Closed'=>'b-closed')) ? array('Open'=>'b-open','In Progress'=>'b-progress','Waiting Parts'=>'b-waiting','Completed'=>'b-completed','Closed'=>'b-closed')[$wo->status] : 'b-secondary');
      $pc = (array_key_exists($wo->priority, array('Critical'=>'b-critical','High'=>'b-high','Medium'=>'b-medium','Low'=>'b-low')) ? array('Critical'=>'b-critical','High'=>'b-high','Medium'=>'b-medium','Low'=>'b-low')[$wo->priority] : 'b-secondary');
      // W0.1: 'Breakdown'/'Modification'/'Inspection' are not work_orders.type values,
      // so the old map never matched a breakdown row. The flag decides now.
      $tc = (wo_is_breakdown($wo) ? 'b-danger' : ($wo->type === 'Preventive Maintenance' ? 'b-primary' : ($wo->type === 'Incident' ? 'b-warning' : 'b-secondary')));
    ?>
    <tr>
      <td><a href="<?= base_url('workorders/view/'.$wo->id) ?>" class="mono" style="color:var(--blue);"><?= $wo->wo_number ?></a></td>
      <td><?= htmlspecialchars((isset($wo->registration_no) ? $wo->registration_no : '—')) ?></td>
      <td><span class="badge <?= $tc ?>"><?= $wo->type ?></span></td>
      <td><span class="badge <?= $pc ?>"><?= $wo->priority ?></span></td>
      <td><span class="badge <?= $sc ?>"><?= $wo->status ?></span></td>
      <td><?= htmlspecialchars((isset($wo->assigned_name) ? $wo->assigned_name : '—')) ?></td>
      <td class="mono" style="font-size:11px;"><?= date('d/m', strtotime($wo->created_at)) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div style="padding:40px;text-align:center;color:var(--t3);">No work orders this week.</div>
  <?php endif; ?>
</div>

<!-- Attendance Summary -->
<div class="card mb-3">
  <div class="ch"><div class="ct">Attendance Summary</div></div>
  <?php
  $att_summary = [];
  if (is_array($attendance)) {
    foreach ($attendance as $a) {
      $day = date('D d/m', strtotime((isset($a->date) ? $a->date : (isset($a['date']) ? $a['date'] : date('Y-m-d')))));
      if (!isset($att_summary[$day])) $att_summary[$day] = ['present'=>0,'absent'=>0,'leave'=>0,'rest'=>0];
      $st = (isset($a->status) ? $a->status : (isset($a['status']) ? $a['status'] : 'Present'));
      if ($st === 'Present')      $att_summary[$day]['present']++;
      elseif ($st === 'Absent')   $att_summary[$day]['absent']++;
      elseif (in_array($st, ['Leave','Annual','Sick'])) $att_summary[$day]['leave']++;
      elseif ($st === 'Rest')     $att_summary[$day]['rest']++;
    }
  }
  ?>
  <?php if ($att_summary): ?>
  <table class="dt">
    <thead><tr><th>Day</th><th>Present</th><th>Absent</th><th>Leave/Rest</th></tr></thead>
    <tbody>
    <?php foreach ($att_summary as $day => $counts): ?>
    <tr>
      <td><?= $day ?></td>
      <td><span class="badge b-completed"><?= $counts['present'] ?></span></td>
      <td><span class="badge b-danger"><?= $counts['absent'] ?></span></td>
      <td><?= $counts['leave'] + $counts['rest'] ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div style="padding:40px;text-align:center;color:var(--t3);">No attendance records for this week.</div>
  <?php endif; ?>
</div>

<!-- PM Done This Week -->
<div class="card">
  <div class="ch"><div class="ct">PM Schedules Due This Week</div><div class="ca"><?= count($pm_done) ?> schedules</div></div>
  <?php if ($pm_done): ?>
  <table class="dt">
    <thead><tr><th>Schedule</th><th>Due Date</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($pm_done as $pm): ?>
    <tr>
      <td><?= htmlspecialchars((isset($pm->schedule_name) ? $pm->schedule_name : '—')) ?></td>
      <td class="mono"><?= $pm->next_due_date ? date('d/m/Y', strtotime($pm->next_due_date)) : '—' ?></td>
      <td><span class="badge b-primary"><?= (isset($pm->status) ? $pm->status : '—') ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div style="padding:40px;text-align:center;color:var(--t3);">No PM schedules due this week.</div>
  <?php endif; ?>
</div>
