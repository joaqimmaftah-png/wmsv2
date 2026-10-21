<?php
$open_wo       = isset($kpi['open_wo'])       ? $kpi['open_wo']       : 0;
$availability  = isset($kpi['availability'])  ? $kpi['availability']  : 0;
$manpower_data = isset($kpi['manpower']) ? $kpi['manpower'] : ['present'=>0,'total'=>0,'pct'=>0];
$manpower      = is_array($manpower_data) ? $manpower_data['present'] : (int)$manpower_data;
$total_workers = is_array($manpower_data) ? $manpower_data['total']   : (isset($kpi['total_workers']) ? $kpi['total_workers'] : 0);
$overdue_pm    = isset($kpi['overdue_pm'])    ? $kpi['overdue_pm']    : 0;
$mttr          = isset($kpi['mttr'])          ? number_format((float)($kpi['mttr']),1) : '0.0';
$mtbf          = isset($kpi['mtbf'])          ? round($kpi['mtbf'])   : 0;
$lube_month    = isset($kpi['lube_month'])    ? round($kpi['lube_month']) : 0;
$breakdowns    = isset($kpi['breakdowns'])    ? $kpi['breakdowns']    : 0;
$total_downtime= isset($kpi['total_downtime'])? round($kpi['total_downtime'],1) : 0;

// The target comes from the engine (Q1), not from this file: 88 % by default.
$avail_target = isset($kpi['avail_target']) && (float)$kpi['avail_target'] > 0 ? (float)$kpi['avail_target'] : (isset($avail_target) ? (float)$avail_target : 88.0);
$avail_watch  = $avail_target - 10;   // "watch" band, not a second target
$avail_class = $availability >= $avail_target ? 'chip-up' : ($availability >= $avail_watch ? 'chip-warn' : 'chip-dn');
$avail_color = $availability >= $avail_target ? 'var(--green)' : ($availability >= $avail_watch ? 'var(--amber)' : 'var(--red)');

$eq_available = 0; $eq_maint = 0; $eq_broken = 0;
if (isset($equipment)) {
    // W0.2: one status definition (application/helpers/equip_helper.php)
    foreach ($equipment as $eq) {
        if ($eq->status === 'Available')       $eq_available++;
        elseif ($eq->status === 'Breakdown')   $eq_broken++;
        elseif (equip_status_group($eq) === 'Down') $eq_maint++;   // In Maintenance
    }
}
?>

<!-- ═══ KPI STRIP ═══ -->
<div class="grid g-7 mb-4">

  <div class="kpi" style="animation-delay:.04s;">
    <div class="kpi-glow" style="background:var(--blue);"></div>
    <span class="kpi-tag">Open WOs</span>
    <div class="kpi-num"><span class="cu" data-to="<?= $open_wo ?>"><?= $open_wo ?></span></div>
    <div class="kpi-chip chip-warn">Active work orders</div>
  </div>

  <div class="kpi" style="animation-delay:.08s;">
    <div class="kpi-glow" style="background:<?= $avail_color ?>;"></div>
    <span class="kpi-tag">Availability</span>
    <div class="kpi-num"><span class="cu" data-to="<?= $availability ?>"><?= $availability ?></span><sup>%</sup></div>
    <div class="kpi-chip <?= $avail_class ?>">Target ≥ <?= $avail_target ?>%</div>
  </div>

  <div class="kpi" style="animation-delay:.12s;">
    <div class="kpi-glow" style="background:var(--purple);"></div>
    <span class="kpi-tag">Manpower</span>
    <div class="kpi-num"><span class="cu" data-to="<?= $manpower ?>"><?= $manpower ?></span><sup>/<?= $total_workers ?></sup></div>
    <div class="kpi-chip chip-up">Present today</div>
  </div>

  <div class="kpi" style="animation-delay:.16s;">
    <div class="kpi-glow" style="background:var(--red);"></div>
    <span class="kpi-tag">Overdue PM</span>
    <div class="kpi-num" style="color:<?= $overdue_pm > 0 ? 'var(--red)' : 'var(--green)' ?>;"><span class="cu" data-to="<?= $overdue_pm ?>"><?= $overdue_pm ?></span></div>
    <div class="kpi-chip <?= $overdue_pm > 0 ? 'chip-dn' : 'chip-up' ?>"><?= $overdue_pm > 0 ? 'Needs attention' : 'All on schedule' ?></div>
  </div>

  <div class="kpi" style="animation-delay:.20s;">
    <div class="kpi-glow" style="background:var(--amber);"></div>
    <span class="kpi-tag">MTTR</span>
    <div class="kpi-num"><span><?= $mttr ?></span><sup> hrs</sup></div>
    <div class="kpi-chip chip-warn">Mean time to repair</div>
  </div>

  <div class="kpi" style="animation-delay:.24s;">
    <div class="kpi-glow" style="background:var(--cyan);"></div>
    <span class="kpi-tag">MTBF</span>
    <div class="kpi-num"><span class="cu" data-to="<?= $mtbf ?>"><?= $mtbf ?></span><sup> hrs</sup></div>
    <div class="kpi-chip chip-up">Mean time between fail.</div>
  </div>

  <div class="kpi" style="animation-delay:.28s;">
    <div class="kpi-glow" style="background:var(--teal);"></div>
    <span class="kpi-tag">Lube / Month</span>
    <div class="kpi-num"><span class="cu" data-to="<?= $lube_month ?>"><?= $lube_month ?></span><sup> L</sup></div>
    <div class="kpi-chip chip-warn">Monthly consumption</div>
  </div>

</div>

<!-- ═══ ROW 1: Availability + Fleet ═══ -->
<div class="grid g-2-1 mb-3">

  <div class="card col-2" style="animation-delay:.32s;">
    <div class="ch">
      <div><div class="ct">Equipment Availability Trend</div><div class="cs">30-day fleet average · target <?= $avail_target ?>%</div></div>
      <div class="flex ai-c" style="gap:14px;">
        <div class="flex ai-c gap-10" style="font-size:11px;color:var(--t3);">
          <div style="width:20px;height:2px;background:var(--blue);border-radius:1px;"></div>Actual
        </div>
        <div class="flex ai-c gap-10" style="font-size:11px;color:var(--t3);">
          <div style="width:20px;border-top:2px dashed rgba(0,0,0,0.10);"></div>Target
        </div>
        <a href="<?= base_url('reports/monthly') ?>" class="ca">Full report →</a>
      </div>
    </div>
    <div style="padding:14px 20px 18px;"><canvas id="cAvail" style="max-height:155px;"></canvas></div>
  </div>

  <div class="card" style="animation-delay:.38s;">
    <div class="ch">
      <div><div class="ct">Fleet Status</div><div class="cs">Real-time machine status</div></div>
      <a href="<?= base_url('equipment') ?>" class="ca">All →</a>
    </div>
    <div>
      <?php if (isset($equipment) && count($equipment) > 0):
        foreach (array_slice($equipment, 0, 5) as $eq):
          $sm  = equip_status_map($eq->status);   // W0.2
          $led = $sm['led'];
          $ibg = $sm['known'] ? $sm['bg'] : 'rgba(0,0,0,0.05)';
      ?>
      <div class="row-list-item">
        <div style="width:32px;height:32px;border-radius:9px;background:<?= $ibg ?>;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;">⚙️</div>
        <div style="flex:1;min-width:0;">
          <div class="fw-600" style="font-size:12.5px;"><?= $eq->registration_no ?></div>
          <div style="font-size:10.5px;color:var(--t3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars((string)($eq->brand . ' ' . $eq->model)) ?></div>
        </div>
        <div class="mono text-muted" style="font-size:10px;margin-right:8px;"><?= number_format((float)($eq->current_hours),0) ?> h</div>
        <div class="led <?= $led ?>"></div>
      </div>
      <?php endforeach; else: ?>
      <div style="padding:36px;text-align:center;color:var(--t3);font-size:13px;">No equipment records</div>
      <?php endif; ?>
    </div>
    <div class="flex" style="gap:8px;padding:12px 22px;">
      <div style="flex:1;background:var(--green-d);border:1px solid rgba(28,140,60,0.15);border-radius:10px;padding:8px;text-align:center;">
        <div class="mono fw-600" style="font-size:18px;color:var(--green);"><?= $eq_available ?></div>
        <div style="font-size:9.5px;color:var(--t3);font-weight:600;text-transform:none;letter-spacing:0;margin-top:3px;">Available</div>
      </div>
      <div style="flex:1;background:var(--amber-d);border:1px solid rgba(217,119,6,0.18);border-radius:10px;padding:8px;text-align:center;">
        <div class="mono fw-600" style="font-size:18px;color:var(--amber);"><?= $eq_maint ?></div>
        <div style="font-size:9.5px;color:var(--t3);font-weight:600;text-transform:none;letter-spacing:0;margin-top:3px;">Maint.</div>
      </div>
      <div style="flex:1;background:var(--red-d);border:1px solid rgba(220,53,69,0.18);border-radius:10px;padding:8px;text-align:center;">
        <div class="mono fw-600" style="font-size:18px;color:var(--red);"><?= $eq_broken ?></div>
        <div style="font-size:9.5px;color:var(--t3);font-weight:600;text-transform:none;letter-spacing:0;margin-top:3px;">Broken</div>
      </div>
    </div>
  </div>

</div>

<!-- ═══ FLEET KPI BY TYPE STRIP ═══ -->
<?php if (!empty($fleet_kpi_by_type)): ?>
<div class="card mb-3" style="padding:0;overflow:hidden;">
  <div class="ch" style="padding:14px 20px 10px;">
    <div>
      <div class="ct">Fleet Readiness by Type</div>
      <div class="cs">Right now · machines up ÷ machines total (a count, not the <?= $avail_target ?>% time-based figure)</div>
    </div>
    <a href="<?= base_url('reports/workshop_daily') ?>" class="ca">Daily Report →</a>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:0;border-top:1px solid rgba(0,0,0,0.05);">
    <?php foreach ($fleet_kpi_by_type as $i => $ft):
      $kpi   = $ft['kpi_pct'];
      $color = $kpi >= 90 ? 'var(--green)' : ($kpi >= 70 ? 'var(--amber)' : 'var(--red)');
      $bgc   = $kpi >= 90 ? 'var(--green-d)' : ($kpi >= 70 ? 'var(--amber-d)' : 'var(--red-d)');
      $bdrc  = $kpi >= 90 ? 'rgba(28,140,60,0.18)' : ($kpi >= 70 ? 'rgba(180,83,9,0.18)' : 'rgba(192,57,43,0.18)');
    ?>
    <div style="flex:1;min-width:140px;padding:14px 18px;border-right:1px solid rgba(0,0,0,0.05);border-bottom:1px solid rgba(0,0,0,0.05);<?= $i===count($fleet_kpi_by_type)-1?'border-right:none':'' ?>">
      <div style="font-size:10px;font-weight:700;text-transform:none;letter-spacing:0;color:var(--t3);margin-bottom:6px;"><?= htmlspecialchars((string)($ft['type'])) ?>s</div>
      <div style="display:flex;align-items:baseline;gap:6px;margin-bottom:8px;">
        <div style="font-size:28px;font-weight:800;font-variant-numeric:tabular-nums;color:<?= $color ?>;line-height:1;"><?= $kpi ?>%</div>
        <div style="font-size:11px;color:var(--t3);">ready</div>
      </div>
      <!-- Progress bar -->
      <div style="height:5px;background:rgba(0,0,0,0.05);border-radius:3px;margin-bottom:8px;overflow:hidden;">
        <div style="height:100%;width:<?= $kpi ?>%;background:<?= $color ?>;border-radius:3px;transition:width .6s ease;"></div>
      </div>
      <div style="display:flex;gap:8px;font-size:10.5px;">
        <span style="color:var(--green);font-weight:600;">✓ <?= $ft['available'] ?> up</span>
        <?php if ($ft['down'] > 0): ?>
        <span style="color:var(--red);font-weight:600;">✗ <?= $ft['down'] ?> down</span>
        <?php endif; ?>
        <span style="color:var(--t3);">/ <?= $ft['total'] ?> total</span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <!-- Per-type bar charts -->
  <div style="padding:16px 20px 20px;border-top:1px solid rgba(0,0,0,0.05);">
    <div style="font-size:10.5px;color:var(--t3);font-weight:600;text-transform:none;letter-spacing:0;margin-bottom:12px;">Readiness per Fleet Type (machines up)</div>
    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end;height:70px;">
      <?php foreach ($fleet_kpi_by_type as $ft):
        $kpi   = $ft['kpi_pct'];
        $color = $kpi >= 90 ? 'var(--green)' : ($kpi >= 70 ? 'var(--amber)' : 'var(--red)');
        $h     = max(4, round($kpi * 0.6)); // max 60px bar
      ?>
      <div style="text-align:center;flex:1;min-width:60px;">
        <div style="font-size:9px;font-weight:700;color:<?= $color ?>;margin-bottom:3px;"><?= $kpi ?>%</div>
        <div style="height:<?= $h ?>px;background:<?= $color ?>;border-radius:3px 3px 0 0;opacity:.85;transition:height .6s;"></div>
        <div style="font-size:9px;color:var(--t3);margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars((string)($ft['type'])) ?>s</div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ PENDING SPARE PARTS — availability tracking ═══ -->
<?php if (isset($spares_summary) && $spares_summary['lines'] > 0):
  $sp_dot = array(
    'Allocated' => array('🟢','var(--green)'), 'Available' => array('🟢','var(--green)'),
    'Partial'   => array('🟡','var(--amber)'), 'Pending'   => array('🔴','var(--red)'),
    'Unlinked'  => array('⚪','var(--t3)'),
  );
?>
<div class="card mb-3" style="animation-delay:.64s;">
  <div class="ch">
    <div>
      <div class="ct">Pending Spare Parts</div>
      <div class="cs">
        <?= (int)$spares_summary['lines'] ?> requested part line(s) on <?= (int)$spares_summary['wos'] ?> open work order(s)
        · <strong style="color:var(--green);"><?= (int)$spares_summary['available_lines'] ?> covered</strong>
        · <strong style="color:var(--red);"><?= (int)$spares_summary['pending_lines'] ?> waiting</strong>
        <?php if ($spares_summary['new_alerts'] > 0): ?> · <strong style="color:var(--blue);"><?= (int)$spares_summary['new_alerts'] ?> new arrival alert(s)</strong><?php endif; ?>
      </div>
    </div>
    <a href="<?= base_url('spareparts') ?>" class="ca">Open Pending Spares board →</a>
  </div>

  <?php if (!empty($spares_ready)): ?>
  <div style="margin:12px 22px 0;padding:9px 14px;background:var(--green-fill);border:1px solid rgba(28,140,60,0.18);border-radius:10px;font-size:12px;color:var(--green);">
    <strong>✓ <?= count($spares_ready) ?> job(s) can proceed now — all required parts are in stock:</strong>
    <?php foreach (array_slice($spares_ready, 0, 5) as $rw): ?>
      <a href="<?= base_url('workorders/view/'.$rw['work_order_id']) ?>" style="color:var(--green);font-weight:600;"><?= htmlspecialchars((string)($rw['wo_number'])) ?></a><?= $rw !== end($spares_ready) ? ',' : '' ?>
    <?php endforeach; ?>
    <?php if (count($spares_ready) > 5): ?> …<?php endif; ?>
  </div>
  <?php endif; ?>

  <table class="tbl" style="margin-top:12px;">
    <thead><tr><th>Status</th><th>Work Order</th><th>Equipment</th><th>Spare Part</th><th>Required</th><th>Available</th></tr></thead>
    <tbody>
      <?php foreach ($spares_lines as $l):
        $d = isset($sp_dot[$l->avail_status]) ? $sp_dot[$l->avail_status] : array('⚪','var(--t3)');
        $fmt = function ($n) { return rtrim((string)(rtrim((string)(number_format((float)$n, 2, '.', '')), '0')), '.'); };
      ?>
      <tr>
        <td style="white-space:nowrap;font-size:11.5px;font-weight:600;color:<?= $d[1] ?>;"><?= $d[0] ?> <?= htmlspecialchars((string)($l->avail_status)) ?></td>
        <td><a href="<?= base_url('workorders/view/'.$l->work_order_id) ?>" class="mono" style="color:var(--blue);font-weight:600;text-decoration:none;"><?= htmlspecialchars((string)($l->wo_number)) ?></a></td>
        <td style="font-weight:500;"><?= htmlspecialchars((string)($l->registration_no ?: '—')) ?></td>
        <td><?= htmlspecialchars((string)($l->part_name)) ?> <span class="mono" style="font-size:10px;color:var(--t3);"><?= htmlspecialchars((string)(!empty($l->material) ? $l->material->item_code : (!empty($l->item_code) ? $l->item_code : ''))) ?></span></td>
        <td class="mono fw-600"><?= $fmt($l->qty_required) ?></td>
        <td class="mono fw-600" style="color:<?= $l->qty_required > 0 && $l->qty_available >= $l->qty_required ? 'var(--green)' : ($l->qty_available > 0 ? 'var(--amber)' : 'var(--red)') ?>;"><?= $fmt($l->qty_available) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ═══ ROW 2: Donut + Gauges + Alerts ═══ -->
<div class="grid g-2-1 mb-3">

  <div class="card" style="animation-delay:.44s;">
    <div class="ch"><div><div class="ct">WO Distribution</div><div class="cs">This month by type</div></div></div>
    <div class="flex ai-c" style="gap:20px;padding:14px 22px 20px;">
      <div style="position:relative;width:115px;height:115px;flex-shrink:0;">
        <canvas id="cDonut" width="115" height="115"></canvas>
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none;">
          <div class="mono" style="font-size:22px;font-weight:500;color:var(--t1);"><?= isset($wo_total) ? $wo_total : 0 ?></div>
          <div style="font-size:9px;font-weight:700;text-transform:none;letter-spacing:0;color:var(--t3);">Total</div>
        </div>
      </div>
      <div style="flex:1;display:flex;flex-direction:column;gap:9px;">
        <?php
        // W0.1: the buckets are the real classifications — 'Modification' and
        // 'Inspection' are not work_orders.type values and always read 0 before.
        $wt_list=[
          ['Breakdown','var(--red)'],['Preventive','var(--blue)'],
          ['Incident','var(--amber)'],['Other','var(--t3)'],
        ];
        foreach ($wt_list as $wt):
          $cnt = isset($wo_by_type[$wt[0]]) ? $wo_by_type[$wt[0]] : 0;
          if ($cnt === 0 && $wt[0] === 'Other') continue;
        ?>
        <div class="flex ai-c" style="gap:9px;font-size:12px;color:var(--t2);">
          <div style="width:7px;height:7px;border-radius:50%;background:<?= $wt[1] ?>;flex-shrink:0;"></div>
          <?= $wt[0] ?>
          <span class="mono ml-a" style="font-size:12px;font-weight:500;color:var(--t1);"><?= $cnt ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card" style="animation-delay:.50s;">
    <div class="ch"><div><div class="ct">Reliability KPIs</div><div class="cs">Last 30 days</div></div></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--sep);margin-top:16px;">
      <?php
      $gauges=[
        ['MTTR',$mttr,'hrs avg','var(--amber)',min(100,floatval($mttr)/20*100)],
        ['MTBF',$mtbf,'hrs avg','var(--green)',min(100,$mtbf/500*100)],
        ['Downtime',$total_downtime,'hrs total','var(--red)',min(100,$total_downtime/200*100)],
        ['Failures',$breakdowns,'breakdown events','var(--purple)',min(100,$breakdowns/20*100)],
      ];
      foreach ($gauges as $g):
      ?>
      <div style="background:var(--surface);padding:14px 16px;display:flex;flex-direction:column;align-items:center;text-align:center;">
        <div style="font-family:inherit;font-size:11px;font-weight:500;text-transform:none;letter-spacing:0;color:var(--t3);margin-bottom:7px;"><?= $g[0] ?></div>
        <div class="mono" style="font-size:26px;font-weight:500;letter-spacing:-1.5px;color:<?= $g[3] ?>;"><?= $g[1] ?></div>
        <div style="font-size:10px;color:var(--t3);margin-top:2px;"><?= $g[2] ?></div>
        <div class="pbar" style="margin-top:10px;"><div class="pbar-fill" style="width:0%;background:<?= $g[3] ?>;transition:width 1.8s cubic-bezier(0.25,.46,.45,.94);" data-w="<?= $g[4] ?>%"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card" style="animation-delay:.56s;">
    <div class="ch">
      <div><div class="ct">Alerts</div><div class="cs">Requires action</div></div>
      <?php $ac=(isset($overdue_pm_list)?count($overdue_pm_list):0)+(isset($absent_today)?count($absent_today):0); ?>
      <?php if ($ac>0): ?><div style="background:var(--red-d);color:var(--red);font-size:10.5px;font-weight:700;padding:3px 10px;border-radius:100px;"><?= $ac ?></div><?php endif; ?>
    </div>
    <div>
      <?php if (isset($overdue_pm_list) && count($overdue_pm_list)>0): foreach (array_slice($overdue_pm_list,0,3) as $pm): ?>
      <a href="<?= base_url('pm') ?>" class="row-list-item" style="text-decoration:none;">
        <div style="width:7px;height:7px;border-radius:50%;background:var(--red);box-shadow:0 0 7px rgba(192,57,43,0.5);animation:led-b 1.1s infinite;flex-shrink:0;margin-top:3px;"></div>
        <div>
          <div style="font-size:12px;font-weight:600;color:var(--t1);">PM Overdue — <?= (isset($pm->registration_no) ? $pm->registration_no : '') ?></div>
          <div style="font-size:11px;color:var(--t3);margin-top:1px;"><?= (isset($pm->schedule_name) ? $pm->schedule_name : '') ?></div>
          <div class="mono" style="font-size:10px;color:var(--t4);margin-top:3px;">Due: <?= isset($pm->next_due_date)?date('d M Y',strtotime($pm->next_due_date)):'' ?></div>
        </div>
      </a>
      <?php endforeach; endif; ?>
      <?php if (isset($absent_today) && count($absent_today)>0): foreach (array_slice($absent_today,0,2) as $abs): ?>
      <div class="row-list-item">
        <div style="width:7px;height:7px;border-radius:50%;background:var(--amber);box-shadow:0 0 7px rgba(180,83,9,0.4);flex-shrink:0;margin-top:3px;"></div>
        <div>
          <div style="font-size:12px;font-weight:600;color:var(--t1);">Absent Today</div>
          <div style="font-size:11px;color:var(--t3);margin-top:1px;"><?= (isset($abs->full_name) ? $abs->full_name : '') ?></div>
        </div>
      </div>
      <?php endforeach; endif; ?>
      <?php if ($ac===0): ?>
      <div style="padding:28px;text-align:center;">
        <div style="font-size:28px;margin-bottom:8px;">✅</div>
        <div style="font-size:13px;font-weight:600;color:var(--t1);">All clear</div>
        <div style="font-size:11px;color:var(--t3);margin-top:4px;">No alerts at this time</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ═══ ROW 3: Live WO + PM Due ═══ -->
<div class="grid g-2-1 mb-3">

  <div class="card col-2" style="animation-delay:.62s;">
    <div class="ch">
      <div><div class="ct">Live Work Orders</div><div class="cs">Active and recent</div></div>
      <a href="<?= base_url('workorders') ?>" class="ca">View all →</a>
    </div>
    <div>
      <?php if (isset($recent_wo) && count($recent_wo)>0):
        foreach (array_slice($recent_wo,0,5) as $wo):
          $pc='badge b-medium'; if($wo->priority==='Critical')$pc='badge b-critical'; elseif($wo->priority==='High')$pc='badge b-high'; elseif($wo->priority==='Low')$pc='badge b-low';
          $sc='var(--t3)'; if($wo->status==='In Progress')$sc='var(--amber)'; elseif(in_array($wo->status,['Completed','Closed']))$sc='var(--green)'; elseif($wo->status==='Waiting Parts')$sc='var(--purple)';
          $ibg='var(--blue-d)'; $em='📋'; if(wo_is_breakdown($wo)){$ibg='var(--red-d)';$em='🔧';} elseif(isset($wo->type)&&$wo->type==='Incident'){$ibg='var(--amber-d)';$em='⚡';}
      ?>
      <a href="<?= base_url('workorders/view/'.$wo->id) ?>" style="text-decoration:none;display:grid;grid-template-columns:38px 1fr auto auto 110px;align-items:center;gap:14px;padding:13px 22px;border-bottom:1px solid var(--sep);transition:background .15s;cursor:pointer;" onmouseover="this.style.background='rgba(0,0,0,0.05)'" onmouseout="this.style.background='transparent'">
        <div style="width:38px;height:38px;border-radius:11px;background:<?= $ibg ?>;display:flex;align-items:center;justify-content:center;font-size:16px;"><?= $em ?></div>
        <div style="min-width:0;">
          <div class="mono" style="font-size:11px;color:var(--blue);font-weight:500;"><?= $wo->wo_number ?></div>
          <div style="font-size:13px;font-weight:500;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars((string)($wo->title)) ?></div>
          <div style="font-size:11px;color:var(--t3);"><?= (isset($wo->registration_no) ? $wo->registration_no : '') ?></div>
        </div>
        <div><span class="<?= $pc ?>"><?= $wo->priority ?></span></div>
        <div>
          <div style="font-size:11.5px;font-weight:500;color:<?= $sc ?>;">● <?= $wo->status ?></div>
          <div class="mono" style="font-size:10.5px;color:var(--t3);margin-top:2px;"><?= $wo->created_at?date('d M',strtotime($wo->created_at)):'' ?></div>
        </div>
      </a>
      <?php endforeach; else: ?>
      <div style="padding:40px;text-align:center;color:var(--t3);font-size:13px;">No work orders found</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="animation-delay:.68s;">
    <div class="ch">
      <div><div class="ct">Upcoming PM</div><div class="cs">Next 14 days</div></div>
      <a href="<?= base_url('pm') ?>" class="ca">Planner →</a>
    </div>
    <div>
      <?php if (isset($due_pm) && count($due_pm)>0):
        foreach (array_slice($due_pm,0,6) as $pm):
          $dl=null; if(isset($pm->next_due_date)) $dl=round((strtotime($pm->next_due_date)-time())/86400);
          $dc='var(--green)'; $dp='+';
          if($dl!==null&&$dl<0){$dc='var(--red)';$dp='';} elseif($dl!==null&&$dl<=7){$dc='var(--amber)';}
      ?>
      <div class="row-list-item">
        <div style="flex:1;">
          <div class="fw-600" style="font-size:12.5px;"><?= (isset($pm->registration_no) ? $pm->registration_no : '') ?></div>
          <div style="font-size:10.5px;color:var(--t3);"><?= (isset($pm->schedule_name) ? $pm->schedule_name : '') ?></div>
        </div>
        <div class="mono" style="font-size:11px;font-weight:500;color:<?= $dc ?>;"><?= $dl!==null?$dp.$dl.' d':'—' ?></div>
      </div>
      <?php endforeach; else: ?>
      <div style="padding:36px;text-align:center;color:var(--t3);font-size:13px;">No PM scheduled soon</div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ═══ ROW 4: Charts ═══ -->
<div class="grid g-3">

  <div class="card" style="animation-delay:.74s;">
    <div class="ch"><div><div class="ct">Lube Consumption</div><div class="cs">This month vs budget</div></div></div>
    <div style="padding:12px 20px 18px;"><canvas id="cLube" style="max-height:160px;"></canvas></div>
  </div>

  <div class="card" style="animation-delay:.80s;">
    <div class="ch"><div><div class="ct">Attendance Trend</div><div class="cs">Last 14 days</div></div></div>
    <div style="padding:12px 20px 18px;"><canvas id="cAttend" style="max-height:160px;"></canvas></div>
  </div>

  <div class="card" style="animation-delay:.86s;">
    <div class="ch"><div><div class="ct">Monthly WOs Closed</div><div class="cs">12-month history</div></div></div>
    <div style="padding:12px 20px 18px;"><canvas id="cMonthly" style="max-height:160px;"></canvas></div>
  </div>

</div>
<!-- CHARTS -->
<script>
(function() {
  // Wait for DOM to be ready (Chart.js already loaded in <head>)
  if (typeof Chart === 'undefined') {
    console.error('Chart.js not loaded');
    return;
  }

  // Shared style objects (no spread operator - use Object.assign or copy manually)
  var GRID = { color: 'rgba(0,0,0,0.04)', drawBorder: false };
  var TIP  = {
    backgroundColor: '#1d1d1f',
    borderColor: 'rgba(0,0,0,0)',
    borderWidth: 0,
    padding: 11,
    cornerRadius: 8,
    titleFont: { size: 11, weight: '600', family: 'Inter,-apple-system,sans-serif' },
    bodyFont:  { size: 12, family: 'Inter,-apple-system,sans-serif' }
  };

  // Count-up animation for KPI numbers
  document.querySelectorAll('.cu').forEach(function(el) {
    var to = parseFloat(el.dataset.to) || 0;
    var dur = 1200;
    var start = null;
    function ease(t) { return 1 - Math.pow(1 - t, 4); }
    function frame(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      el.textContent = Math.round(ease(p) * to).toLocaleString();
      if (p < 1) requestAnimationFrame(frame);
    }
    setTimeout(function() { requestAnimationFrame(frame); }, 300);
  });

  // Progress bar animation
  setTimeout(function() {
    document.querySelectorAll('.pbar-fill[data-w]').forEach(function(b) {
      b.style.width = b.dataset.w;
    });
  }, 700);

  // ── Availability trend (30-day line) ──
  var availTarget = <?= json_encode((float)$avail_target) ?>;
  var availData   = <?= json_encode(isset($availability_trend) ? array_column($availability_trend,'pct') : array_fill(0,30,$avail_target)); ?>;
  var availLabels = <?= json_encode(isset($availability_trend) ? array_map(function($d){return date('M d',strtotime($d['date']));}, $availability_trend) : array_map(function($i){$d=new DateTime();$d->modify('-'.(29-$i).' days');return $d->format('M d');}, range(0,29))); ?>;
  var ctx1 = document.getElementById('cAvail');
  if (ctx1) {
    var c1 = ctx1.getContext('2d');
    var g1 = c1.createLinearGradient(0,0,0,155);
    g1.addColorStop(0, 'rgba(0,113,227,0.18)');
    g1.addColorStop(1, 'rgba(0,113,227,0)');
    new Chart(c1, {
      type: 'line',
      data: {
        labels: availLabels,
        datasets: [
          { label: 'Availability %', data: availData, borderColor: 'var(--blue)', backgroundColor: g1,
            fill: true, borderWidth: 2, tension: 0.42, pointRadius: 0, pointHoverRadius: 5 },
          { label: 'Target '+availTarget+'%', data: Array(availData.length).fill(availTarget),
            borderColor: 'rgba(0,0,0,0.10)', fill: false, borderWidth: 1.5,
            borderDash: [5,5], pointRadius: 0, tension: 0 }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: Object.assign({}, TIP, { callbacks: {
            label: function(c) { return c.datasetIndex===0 ? ' '+c.raw+'% availability' : ' '+c.raw+'% target'; }
          }})
        },
        scales: {
          x: { grid: GRID, border: { display: false }, ticks: { maxTicksLimit: 7, color: 'rgba(0,0,0,0.30)' } },
          y: { grid: GRID, border: { display: false }, min: 60, max: 100,
               ticks: { stepSize: 10, color: 'rgba(0,0,0,0.30)', callback: function(v){ return v+'%'; } } }
        }
      }
    });
  }

  // ── WO type donut ──
  var woTypes = <?= json_encode(isset($wo_by_type) ? array_values($wo_by_type) : array(0,0,0,0)); ?>;
  var cDonut = document.getElementById('cDonut');
  if (cDonut) {
    new Chart(cDonut.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: ['Breakdown','Preventive','Incident','Other'],
        datasets: [{ data: woTypes,
          backgroundColor: ['rgba(192,57,43,0.82)','rgba(0,113,227,0.82)','rgba(180,83,9,0.82)','rgba(109,63,192,0.82)'],
          borderColor: 'rgba(0,0,0,0)', borderWidth: 0, hoverOffset: 10 }]
      },
      options: {
        responsive: false, cutout: '76%',
        plugins: {
          legend: { display: false },
          tooltip: Object.assign({}, TIP, { callbacks: {
            label: function(c) { return ' ' + c.label + ': ' + c.raw + ' WOs'; }
          }})
        }
      }
    });
  }

  // ── Lube consumption by equipment type (horizontal bar) ──
  var lubeData = <?= json_encode(isset($lube_summary) ? $lube_summary : array()); ?>;
  var lubeL = lubeData.length > 0
    ? lubeData.map(function(l){ return l.type_name; })
    : ['Engine Oil','Hydraulic','Trans. Oil','Grease','Coolant'];
  var lubeV = lubeData.length > 0
    ? lubeData.map(function(l){ return parseFloat(l.total_qty) || 0; })
    : [820,640,410,95,320];
  var cLube = document.getElementById('cLube');
  if (cLube) {
    new Chart(cLube.getContext('2d'), {
      type: 'bar',
      data: { labels: lubeL, datasets: [{
        label: 'Used (L)', data: lubeV,
        backgroundColor: 'rgba(0,113,227,.72)', borderRadius: 4, borderSkipped: false
      }]},
      options: {
        responsive: true, maintainAspectRatio: false, indexAxis: 'y',
        plugins: {
          legend: { display: false },
          tooltip: Object.assign({}, TIP, { callbacks: { label: function(c){ return ' '+c.raw+' L'; } }})
        },
        scales: {
          x: { grid: GRID, border: { display: false }, ticks: { color: 'rgba(0,0,0,0.30)' } },
          y: { grid: { display: false }, border: { display: false }, ticks: { color: 'rgba(0,0,0,0.45)', font: { size: 10.5 } } }
        }
      }
    });
  }

  // ── Attendance trend (14-day bar) ──
  var attData = <?= json_encode(isset($attendance_trend) ? $attendance_trend : array()); ?>;
  var attL, attP, attA;
  if (attData.length > 0) {
    attL = attData.map(function(d){ return d.date; });
    attP = attData.map(function(d){ return d.present; });
    attA = attData.map(function(d){ return d.absent; });
  } else {
    attL = ['Feb 10','Feb 11','Feb 12','Feb 13','Feb 14','Feb 15','Feb 16','Feb 17','Feb 18','Feb 19','Feb 20','Feb 21','Feb 22','Feb 23'];
    attP = [18,20,19,17,22,21,18,19,20,21,18,19,20,18];
    attA = [4,2,3,5,0,1,4,3,2,1,4,3,2,4];
  }
  var cAttend = document.getElementById('cAttend');
  if (cAttend) {
    new Chart(cAttend.getContext('2d'), {
      type: 'bar',
      data: { labels: attL, datasets: [
        { label: 'Present', data: attP, backgroundColor: 'rgba(28,140,60,.70)', borderRadius: 4, borderSkipped: false },
        { label: 'Absent',  data: attA, backgroundColor: 'rgba(192,57,43,0.45)', borderRadius: 4, borderSkipped: false }
      ]},
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: true, position: 'top', labels: { color: 'rgba(0,0,0,0.35)', font: { size: 10.5 }, boxWidth: 9, boxHeight: 9 } },
          tooltip: TIP
        },
        scales: {
          x: { grid: GRID, border: { display: false }, ticks: { color: 'rgba(0,0,0,0.30)', maxRotation: 45, font: { size: 9.5 } } },
          y: { grid: GRID, border: { display: false }, ticks: { color: 'rgba(0,0,0,0.30)', stepSize: 5 } }
        }
      }
    });
  }

  // ── Monthly WOs closed (12-month line) ──
  var moData = <?= json_encode(isset($wo_monthly) ? $wo_monthly : array()); ?>;
  var moL, moV;
  if (moData.length > 0) {
    moL = moData.map(function(m){ return m.month; });
    moV = moData.map(function(m){ return m.closed; });
  } else {
    moL = ['Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec','Jan','Feb'];
    moV = [28,32,26,40,35,38,42,30,36,44,38,50];
  }
  var ctx5 = document.getElementById('cMonthly');
  if (ctx5) {
    var c5 = ctx5.getContext('2d');
    var g5 = c5.createLinearGradient(0,0,0,160);
    g5.addColorStop(0,'rgba(28,140,60,0.18)');
    g5.addColorStop(1,'rgba(28,140,60,0)');
    new Chart(c5, {
      type: 'line',
      data: { labels: moL, datasets: [{
        label: 'WOs Closed', data: moV, borderColor: 'var(--green)', backgroundColor: g5,
        fill: true, borderWidth: 2, tension: 0.4, pointRadius: 0, pointHoverRadius: 5
      }]},
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: Object.assign({}, TIP, { callbacks: { label: function(c){ return ' '+c.raw+' WOs'; } }})
        },
        scales: {
          x: { grid: GRID, border: { display: false }, ticks: { color: 'rgba(0,0,0,0.30)' } },
          y: { grid: GRID, border: { display: false }, ticks: { color: 'rgba(0,0,0,0.30)' } }
        }
      }
    });
  }

})(); // end IIFE
</script>
