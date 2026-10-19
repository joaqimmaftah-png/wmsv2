<?php
$k = $kpi;
$avail = round($k['availability']);
// Q1: the target is the engine's (88 % by default), never a number in the view
$avail_target = isset($k['avail_target']) ? (float)$k['avail_target'] : 88.0;
$avail_watch  = $avail_target - 10;
$avail_color = $avail >= $avail_target ? 'var(--green)' : ($avail >= $avail_watch ? 'var(--amber)' : 'var(--red)');
$avail_chip  = $avail >= $avail_target ? 'chip-up' : ($avail >= $avail_watch ? 'chip-warn' : 'chip-dn');

$manpower_data = is_array($k['manpower']) ? $k['manpower'] : ['present'=>0,'total'=>0];
$manpower_pct  = $manpower_data['total'] > 0 ? round($manpower_data['present'] / $manpower_data['total'] * 100) : 0;

$order_kpi = $k['order_kpi'];
?>

<div class="mb-4">
  <div class="page-heading" style="font-size:17px;">Executive Summary</div>
  <div class="cs">High-level operational &amp; financial overview — <?= date('F Y') ?></div>
</div>

<!-- ═══ TOP-LINE KPIs ═══ -->
<div class="grid g-4 mb-4">

  <div class="kpi" style="animation-delay:.04s;">
    <div class="kpi-glow" style="background:<?= $avail_color ?>;"></div>
    <span class="kpi-tag">Fleet Availability</span>
    <div class="kpi-num"><?= $avail ?><sup>%</sup></div>
    <div class="kpi-chip <?= $avail_chip ?>">Target ≥ <?= $avail_target ?>%</div>
  </div>

  <div class="kpi" style="animation-delay:.08s;">
    <div class="kpi-glow" style="background:var(--purple);"></div>
    <span class="kpi-tag">Workforce Present</span>
    <div class="kpi-num"><?= $manpower_pct ?><sup>%</sup></div>
    <div class="cs" style="margin-top:4px;"><?= $manpower_data['present'] ?> of <?= $manpower_data['total'] ?> today</div>
  </div>

  <div class="kpi" style="animation-delay:.12s;">
    <div class="kpi-glow" style="background:var(--blue);"></div>
    <span class="kpi-tag">Open Work Orders</span>
    <div class="kpi-num"><?= $k['open_wo'] ?></div>
  </div>

  <div class="kpi" style="animation-delay:.16s;">
    <div class="kpi-glow" style="background:<?= $k['overdue_pm'] > 0 ? 'var(--red)' : 'var(--green)' ?>;"></div>
    <span class="kpi-tag">Overdue Maintenance</span>
    <div class="kpi-num"><?= $k['overdue_pm'] ?></div>
  </div>

</div>

<div class="grid g-4 mb-4">

  <div class="kpi">
    <div class="kpi-glow" style="background:var(--cyan);"></div>
    <span class="kpi-tag">MTTR / MTBF</span>
    <div class="kpi-num" style="font-size:18px;"><?= number_format($k['mttr'],1) ?>h <span style="color:var(--t3);font-size:13px;">/ <?= round($k['mtbf']) ?>h</span></div>
    <div class="cs" style="margin-top:4px;">Mean Time to Repair / Between Failures</div>
  </div>

  <div class="kpi">
    <div class="kpi-glow" style="background:<?= $k['breakdowns_30d'] > 0 ? 'var(--amber)' : 'var(--green)' ?>;"></div>
    <span class="kpi-tag">Breakdowns (30 days)</span>
    <div class="kpi-num"><?= $k['breakdowns_30d'] ?></div>
  </div>

  <div class="kpi">
    <div class="kpi-glow" style="background:<?= $k['critical_incidents_month'] > 0 ? 'var(--red)' : 'var(--green)' ?>;"></div>
    <span class="kpi-tag">Incidents This Month</span>
    <div class="kpi-num"><?= $k['incidents_month'] ?></div>
    <div class="cs" style="margin-top:4px;"><?= $k['critical_incidents_month'] ?> uncontrolled / critical</div>
  </div>

  <div class="kpi">
    <div class="kpi-glow" style="background:var(--green);"></div>
    <span class="kpi-tag">Spend YTD</span>
    <?php $spend_by_currency = is_array($k['spend_ytd']) ? $k['spend_ytd'] : array(); ?>
    <?php if (empty($spend_by_currency)): ?>
    <div class="kpi-num" style="font-size:18px;">0</div>
    <?php else: $first_currency = true; foreach ($spend_by_currency as $cur => $amount): ?>
    <div class="kpi-num" style="font-size:<?= $first_currency ? '18px' : '13px' ?>;<?= $first_currency ? '' : 'margin-top:2px;' ?>">
      <?= wms_money($amount, $cur, 0) ?>
    </div>
    <?php $first_currency = false; endforeach; endif; ?>
    <div class="cs" style="margin-top:4px;">Spare parts &amp; materials, <?= date('Y') ?><?= count($spend_by_currency) > 1 ? ' — per currency' : '' ?></div>
  </div>

</div>

<!-- ═══ Supply Chain Snapshot ═══ -->
<div class="card mb-4">
  <div class="ch"><div><div class="ct">Supply Chain Snapshot</div><div class="cs">Spare parts order pipeline status</div></div></div>
  <div style="padding:18px 22px;display:grid;grid-template-columns:repeat(4,1fr);gap:18px;">
    <div>
      <div style="font-size:11px;color:var(--t3);text-transform:uppercase;font-weight:600;">Open Orders</div>
      <div style="font-size:22px;font-weight:700;color:var(--t1);margin-top:4px;"><?= $order_kpi['open_orders'] ?></div>
    </div>
    <div>
      <div style="font-size:11px;color:var(--t3);text-transform:uppercase;font-weight:600;">In Transit</div>
      <div style="font-size:22px;font-weight:700;color:var(--t1);margin-top:4px;"><?= $order_kpi['in_transit'] ?></div>
    </div>
    <div>
      <div style="font-size:11px;color:var(--t3);text-transform:uppercase;font-weight:600;">Due Soon (7d)</div>
      <div style="font-size:22px;font-weight:700;color:var(--amber);margin-top:4px;"><?= $order_kpi['due_soon'] ?></div>
    </div>
    <div>
      <div style="font-size:11px;color:var(--t3);text-transform:uppercase;font-weight:600;">Overdue</div>
      <div style="font-size:22px;font-weight:700;color:<?= $order_kpi['overdue']>0?'var(--red)':'var(--green)' ?>;margin-top:4px;"><?= $order_kpi['overdue'] ?></div>
    </div>
  </div>
</div>

<!-- ═══ Fleet Overview by Type ═══ -->
<?php if (!empty($fleet_kpi_by_type)): ?>
<div class="card mb-4">
  <div class="ch"><div><div class="ct">Fleet Overview</div><div class="cs">Readiness by equipment type — machines up ÷ machines total, not a time-based figure</div></div></div>
  <div style="overflow-x:auto;">
    <table class="tbl">
      <thead><tr><th>Equipment Type</th><th style="text-align:center;">Fleet Size</th><th style="text-align:center;">Ready</th></tr></thead>
      <tbody>
      <?php foreach ($fleet_kpi_by_type as $f):
        $a = round($f['kpi_pct']);
        $col = $a >= 90 ? 'var(--green)' : ($a >= 75 ? 'var(--amber)' : 'var(--red)');
      ?>
      <tr>
        <td style="font-weight:600;color:var(--t1);"><?= htmlspecialchars($f['type']) ?></td>
        <td class="mono" style="text-align:center;"><?= (int)$f['total'] ?></td>
        <td style="text-align:center;"><span style="padding:3px 8px;border-radius:5px;font-size:10.5px;font-weight:700;color:<?= $col ?>;background:<?= $col ?>22;"><?= $a ?>%</span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ═══ Equipment Status Breakdown ═══ -->
<?php
$status_colors = array(
  'Available'      => array('var(--green)','var(--green-fill)'),
  'Breakdown'      => array('var(--red)','var(--red-fill)'),
  'In Maintenance' => array('var(--amber)','var(--amber-fill)'),
  'Standby'        => array('var(--t3)','var(--gray-fill)'),
);
?>
<div class="card mb-4">
  <div class="ch"><div><div class="ct">Equipment Status</div><div class="cs">Current status across the whole fleet</div></div></div>
  <div style="padding:18px 22px;display:grid;grid-template-columns:repeat(<?= max(1,count($equipment_status)) ?>,1fr);gap:18px;">
    <?php foreach ($equipment_status as $status => $cnt):
      $c = isset($status_colors[$status]) ? $status_colors[$status] : array('var(--t3)','var(--gray-fill)');
    ?>
    <div>
      <div style="font-size:11px;color:var(--t3);font-weight:500;"><?= htmlspecialchars($status) ?></div>
      <div style="font-size:24px;font-weight:700;color:<?= $c[0] ?>;margin-top:4px;"><?= $cnt ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;" class="mb-4">

  <!-- ═══ Active Work Orders ═══ -->
  <div class="card">
    <div class="ch"><div><div class="ct">Active Work Orders</div><div class="cs">Currently open across the workshop</div></div></div>
    <div style="overflow-x:auto;">
      <table class="tbl">
        <thead><tr><th>WO #</th><th>Equipment</th><th>Type</th><th style="text-align:center;">Status</th></tr></thead>
        <tbody>
        <?php if (empty($active_wo)): ?>
        <tr><td colspan="4" style="text-align:center;padding:30px;color:var(--t3);">No active work orders</td></tr>
        <?php else: foreach ($active_wo as $w): ?>
        <tr>
          <td style="font-weight:600;color:var(--t1);"><?= htmlspecialchars($w->wo_number) ?></td>
          <td><?= htmlspecialchars($w->registration_no ?: '—') ?></td>
          <td style="font-size:11px;color:var(--t3);"><?= htmlspecialchars($w->type) ?></td>
          <td style="text-align:center;font-size:11px;"><?= htmlspecialchars($w->status) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══ Service / PM Alerts ═══ -->
  <div class="card">
    <div class="ch"><div><div class="ct">Service Alerts</div><div class="cs">Preventive maintenance due within 7 days or overdue</div></div></div>
    <div style="overflow-x:auto;">
      <table class="tbl">
        <thead><tr><th>Equipment</th><th>Schedule</th><th style="text-align:center;">Due</th></tr></thead>
        <tbody>
        <?php if (empty($overdue_pm_list)): ?>
        <tr><td colspan="3" style="text-align:center;padding:30px;color:var(--t3);">Nothing due — all clear</td></tr>
        <?php else: foreach ($overdue_pm_list as $p):
          $isOverdue = $p->next_due_date < date('Y-m-d');
        ?>
        <tr>
          <td style="font-weight:600;color:var(--t1);"><?= htmlspecialchars($p->registration_no ?: '—') ?></td>
          <td style="font-size:11px;color:var(--t3);"><?= htmlspecialchars($p->schedule_name ?? '') ?></td>
          <td style="text-align:center;font-size:11px;font-weight:600;color:<?= $isOverdue ? 'var(--red)' : 'var(--amber)' ?>;"><?= date('d M', strtotime($p->next_due_date)) ?><?= $isOverdue ? ' (overdue)' : '' ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;" class="mb-4">

  <!-- ═══ Store Inventory Overview ═══ -->
  <div class="card">
    <div class="ch"><div><div class="ct">Store Inventory</div><div class="cs">Stock health across all materials</div></div></div>
    <div style="padding:16px 22px 6px;display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
      <div>
        <div style="font-size:11px;color:var(--t3);">Total Items</div>
        <div style="font-size:20px;font-weight:700;color:var(--t1);margin-top:3px;"><?= $mat_kpi['items'] ?? 0 ?></div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--t3);">Low Stock</div>
        <div style="font-size:20px;font-weight:700;color:var(--amber);margin-top:3px;"><?= $mat_kpi['low'] ?? 0 ?></div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--t3);">Out of Stock</div>
        <div style="font-size:20px;font-weight:700;color:var(--red);margin-top:3px;"><?= $mat_kpi['out'] ?? 0 ?></div>
      </div>
    </div>
    <div style="overflow-x:auto;margin-top:10px;">
      <table class="tbl">
        <thead><tr><th>Item</th><th style="text-align:center;">Remaining</th></tr></thead>
        <tbody>
        <?php if (empty($low_stock)): ?>
        <tr><td colspan="2" style="text-align:center;padding:24px;color:var(--t3);">No low-stock items</td></tr>
        <?php else: foreach ($low_stock as $m): ?>
        <tr>
          <td><?= htmlspecialchars($m->description) ?> <span style="color:var(--t4);font-size:10px;">(<?= htmlspecialchars($m->item_code) ?>)</span></td>
          <td style="text-align:center;font-weight:700;color:<?= $m->final_qty<=0?'var(--red)':'var(--amber)' ?>;"><?= (int)$m->final_qty ?> <?= htmlspecialchars($m->unit) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══ Lubricants: Consumption & Remaining ═══ -->
  <div class="card">
    <div class="ch"><div><div class="ct">Lubricants</div><div class="cs">Consumption vs. budget — <?= date('F Y') ?></div></div></div>
    <div style="overflow-x:auto;">
      <table class="tbl">
        <thead><tr><th>Lube</th><th style="text-align:center;">Used</th><th style="text-align:center;">Remaining</th></tr></thead>
        <tbody>
        <?php if (empty($lube_rows)): ?>
        <tr><td colspan="3" style="text-align:center;padding:24px;color:var(--t3);">No consumption recorded this month</td></tr>
        <?php else: foreach ($lube_rows as $l): ?>
        <tr>
          <td><?= htmlspecialchars($l['name']) ?></td>
          <td style="text-align:center;"><?= number_format($l['used'],1) ?> <?= htmlspecialchars($l['unit']) ?></td>
          <td style="text-align:center;font-weight:600;color:<?= $l['remaining']===null?'var(--t3)':($l['remaining']<0?'var(--red)':'var(--green)') ?>;">
            <?= $l['remaining']===null ? 'no budget set' : number_format($l['remaining'],1).' '.htmlspecialchars($l['unit']) ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:8px 22px 14px;font-size:10px;color:var(--t4);">"Remaining" reflects the monthly budget allowance, not physical stock on shelf.</div>
  </div>

</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;" class="mb-4">

  <!-- ═══ Spare Parts On Order ═══ -->
  <div class="card">
    <div class="ch"><div><div class="ct">Spare Parts On Order</div><div class="cs">Orders needing attention</div></div></div>
    <div style="overflow-x:auto;">
      <table class="tbl">
        <thead><tr><th>Order</th><th>Supplier</th><th style="text-align:center;">Expected</th></tr></thead>
        <tbody>
        <?php if (empty($orders_overdue) && empty($orders_due_soon)): ?>
        <tr><td colspan="3" style="text-align:center;padding:30px;color:var(--t3);">No orders due soon or overdue</td></tr>
        <?php else: foreach (array_merge($orders_overdue, $orders_due_soon) as $o):
          $isOverdue = $o->expected_delivery_date < date('Y-m-d');
        ?>
        <tr>
          <td style="font-weight:600;color:var(--t1);"><?= htmlspecialchars($o->order_no) ?></td>
          <td style="font-size:11px;color:var(--t3);"><?= htmlspecialchars($o->supplier_name) ?></td>
          <td style="text-align:center;font-size:11px;font-weight:600;color:<?= $isOverdue?'var(--red)':'var(--amber)' ?>;"><?= date('d M', strtotime($o->expected_delivery_date)) ?><?= $isOverdue?' (overdue)':'' ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══ Incidents ═══ -->
  <div class="card">
    <div class="ch"><div><div class="ct">Recent Incidents</div><div class="cs">Most recent near-miss / incident reports</div></div></div>
    <div style="overflow-x:auto;">
      <table class="tbl">
        <thead><tr><th>WO #</th><th>Equipment</th><th style="text-align:center;">Status</th></tr></thead>
        <tbody>
        <?php if (empty($recent_incidents)): ?>
        <tr><td colspan="3" style="text-align:center;padding:30px;color:var(--t3);">No incidents recorded</td></tr>
        <?php else: foreach ($recent_incidents as $inc): ?>
        <tr>
          <td style="font-weight:600;color:var(--t1);"><?= htmlspecialchars($inc->wo_number) ?></td>
          <td><?= htmlspecialchars($inc->registration_no ?: '—') ?></td>
          <td style="text-align:center;font-size:10.5px;">
            <?php $st = $inc->activity_status ?: '—'; $col = $st==='Uncontrolled'?'var(--red)':($st==='Monitored'?'var(--amber)':'var(--green)'); ?>
            <span style="font-weight:700;color:<?= $col ?>;"><?= htmlspecialchars($st) ?></span>
          </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ═══ Attendance ═══ -->
<div class="card">
  <div class="ch">
    <div><div class="ct">Workforce Attendance — Today</div><div class="cs"><?= $manpower_data['present'] ?> present of <?= $manpower_data['total'] ?> total</div></div>
  </div>
  <?php if (!empty($absent_today)): ?>
  <div style="overflow-x:auto;">
    <table class="tbl">
      <thead><tr><th>Name</th><th>Position</th></tr></thead>
      <tbody>
      <?php foreach ($absent_today as $a): ?>
      <tr>
        <td style="font-weight:600;color:var(--t1);"><?= htmlspecialchars($a->first_name.' '.$a->last_name) ?></td>
        <td style="font-size:11px;color:var(--t3);"><?= htmlspecialchars($a->position ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div style="padding:30px;text-align:center;color:var(--t3);">Full attendance today — nobody marked absent.</div>
  <?php endif; ?>
</div>
