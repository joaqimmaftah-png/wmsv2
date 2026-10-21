<?php
$eq = $equipment;
$kpi = isset($kpi) ? $kpi : (isset($equipment_kpi) ? $equipment_kpi : []);
$status_color = equip_status_map($eq->status)['badge'];   // W0.2
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:12px;">
  <div>
    <div class="page-title"><?= htmlspecialchars((string)($eq->registration_no)) ?> — Equipment History</div>
    <div style="font-size:12px;color:var(--t3);margin-top:3px;"><?= htmlspecialchars((string)($eq->brand.' '.$eq->model)) ?> · <?= (isset($eq->type_name) ? $eq->type_name : '') ?></div>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('equipment/view/'.$eq->id) ?>" class="btn btn-ghost">← Equipment Profile</a>
    <a href="<?= base_url('reports') ?>" class="btn btn-ghost">← Reports</a>
    <button class="btn btn-primary btn-icon" onclick="window.print()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print
    </button>
  </div>
</div>

<!-- Equipment Summary Card -->
<div class="card mb-3">
  <div class="ch"><div class="ct">Equipment Overview</div><span class="badge <?= $status_color ?>"><?= $eq->status ?></span></div>
  <div style="padding:20px;">
    <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:16px;">
      <div>
        <div style="font-size:10px;color:var(--t3);text-transform:none;letter-spacing:0;margin-bottom:4px;">Reg. Number</div>
        <div style="font-size:15px;font-weight:700;color:var(--blue);"><?= htmlspecialchars((string)($eq->registration_no)) ?></div>
      </div>
      <div>
        <div style="font-size:10px;color:var(--t3);text-transform:none;letter-spacing:0;margin-bottom:4px;">Brand / Model</div>
        <div style="font-size:13px;font-weight:600;color:var(--t1);"><?= htmlspecialchars((string)($eq->brand.' '.$eq->model)) ?></div>
      </div>
      <div>
        <div style="font-size:10px;color:var(--t3);text-transform:none;letter-spacing:0;margin-bottom:4px;">Current Hours</div>
        <div style="font-size:15px;font-weight:700;color:var(--t1);" class="mono"><?= number_format((float)((isset($eq->current_hours) ? $eq->current_hours : 0)), 1) ?> h</div>
      </div>
      <div>
        <div style="font-size:10px;color:var(--t3);text-transform:none;letter-spacing:0;margin-bottom:4px;">Serial Number</div>
        <div style="font-size:13px;font-weight:600;color:var(--t1);"><?= htmlspecialchars((string)((isset($eq->serial_number) ? $eq->serial_number : '—'))) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- KPI Strip -->
<?php if (!empty($kpi)): ?>
<div class="grid" style="grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px;">
  <div class="kpi" style="animation-delay:.04s;">
    <div class="kpi-glow" style="background:var(--green);"></div>
    <span class="kpi-tag">Total WOs</span>
    <div class="kpi-num"><?= (isset($kpi['total_wo']) ? $kpi['total_wo'] : 0) ?></div>
  </div>
  <div class="kpi" style="animation-delay:.08s;">
    <div class="kpi-glow" style="background:var(--red);"></div>
    <span class="kpi-tag">Breakdowns</span>
    <div class="kpi-num"><?= (isset($kpi['breakdowns']) ? $kpi['breakdowns'] : 0) ?></div>
  </div>
  <div class="kpi" style="animation-delay:.12s;">
    <div class="kpi-glow" style="background:var(--amber);"></div>
    <span class="kpi-tag">Total Downtime</span>
    <div class="kpi-num"><?= number_format((float)((isset($kpi['total_downtime']) ? $kpi['total_downtime'] : 0)), 1) ?><span style="font-size:12px;">h</span></div>
  </div>
  <div class="kpi" style="animation-delay:.16s;">
    <div class="kpi-glow" style="background:var(--blue);"></div>
    <span class="kpi-tag">Avg MTTR</span>
    <div class="kpi-num"><?= number_format((float)((isset($kpi['avg_mttr']) ? $kpi['avg_mttr'] : 0)), 1) ?><span style="font-size:12px;">h</span></div>
  </div>
</div>
<?php endif; ?>

<!-- Work Order History -->
<div class="card mb-3">
  <div class="ch">
    <div class="ct">Work Order History</div>
    <div class="ca"><?= count($work_orders) ?> total records</div>
  </div>
  <?php if ($work_orders): ?>
  <table class="dt">
    <thead><tr>
      <th>WO Number</th><th>Type</th><th>Priority</th><th>Status</th>
      <th>Assigned</th><th>Start</th><th>End</th><th>Downtime</th>
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
      <td><a href="<?= base_url('workorders/view/'.$wo->id) ?>" class="mono" style="color:var(--blue);font-size:11px;"><?= $wo->wo_number ?></a></td>
      <td><span class="badge <?= $tc ?>"><?= $wo->type ?></span></td>
      <td><span class="badge <?= $pc ?>"><?= $wo->priority ?></span></td>
      <td><span class="badge <?= $sc ?>"><?= $wo->status ?></span></td>
      <td style="font-size:12px;"><?= htmlspecialchars((string)((isset($wo->assigned_name) ? $wo->assigned_name : '—'))) ?></td>
      <td class="mono" style="font-size:11px;"><?= $wo->start_datetime ? date('d/m/Y', strtotime($wo->start_datetime)) : '—' ?></td>
      <td class="mono" style="font-size:11px;"><?= $wo->end_datetime ? date('d/m/Y', strtotime($wo->end_datetime)) : '—' ?></td>
      <td class="mono" style="font-size:11px;"><?= $wo->downtime_hours ? number_format((float)($wo->downtime_hours),1).'h' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div style="padding:40px;text-align:center;color:var(--t3);">No work order history found for this equipment.</div>
  <?php endif; ?>
</div>

<!-- PM History -->
<?php if (!empty($pm_history)): ?>
<div class="card">
  <div class="ch"><div class="ct">PM Completion History</div></div>
  <table class="dt">
    <thead><tr><th>Schedule</th><th>Completed Date</th><th>Hours at PM</th><th>Completed By</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach ($pm_history as $pm): ?>
    <tr>
      <td><?= htmlspecialchars((string)((isset($pm->schedule_name) ? $pm->schedule_name : '—'))) ?></td>
      <td class="mono"><?= $pm->completed_date ? date('d/m/Y', strtotime($pm->completed_date)) : '—' ?></td>
      <td class="mono"><?= $pm->completed_hours ? number_format((float)($pm->completed_hours),1).'h' : '—' ?></td>
      <td style="font-size:12px;"><?= htmlspecialchars((string)((isset($pm->completed_by_name) ? $pm->completed_by_name : '—'))) ?></td>
      <td style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)((isset($pm->notes) ? $pm->notes : ''))) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
