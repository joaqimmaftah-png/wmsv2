<?php
$discrepancyPct = $orders_this_year > 0 ? round($discrepancy_count / $orders_this_year * 100, 1) : null;
$maxSpend = 1;
foreach ($spend as $s) $maxSpend = max($maxSpend, $s->total_spend);
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Store Analytics</div>
    <div class="cs">Supplier spend &amp; route lead-times — <?= $year ?></div>
  </div>
  <form method="get" class="flex ai-c gap-10">
    <select name="year" class="form-control" style="width:110px;" onchange="this.form.submit()">
      <?php for ($y = date('Y'); $y >= date('Y')-4; $y--): ?>
      <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
  </form>
</div>

<!-- KPI strip -->
<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total Spend (<?= $year ?>)</span>
    <div class="kpi-num"><?= number_format($total_spend,0) ?></div>
    <div class="cs" style="margin-top:4px;"><?= $orders_this_year ?> orders</div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--cyan)"></div>
    <span class="kpi-tag">Open Orders (all time)</span>
    <div class="kpi-num"><?= $order_kpi['open_orders'] ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Overdue</span>
    <div class="kpi-num"><?= $order_kpi['overdue'] ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:<?= $discrepancyPct===null ? 'var(--t3)' : ($discrepancyPct>15 ? 'var(--red)' : ($discrepancyPct>5 ? 'var(--amber)' : 'var(--green)')) ?>"></div>
    <span class="kpi-tag">Discrepancy Rate (<?= $year ?>)</span>
    <div class="kpi-num"><?= $discrepancyPct===null ? '—' : $discrepancyPct.'%' ?></div>
    <div class="cs" style="margin-top:4px;"><?= $discrepancy_count ?> of <?= $orders_this_year ?> orders</div>
  </div>
</div>

<!-- Spend by supplier -->
<div class="card mb-4">
  <div class="ch">
    <div><div class="ct">Spend by Supplier</div><div class="cs">Total order value placed with each supplier in <?= $year ?></div></div>
  </div>
  <?php if (empty($spend)): ?>
  <div style="padding:48px;text-align:center;color:var(--t3);">No orders recorded for <?= $year ?>.</div>
  <?php else: ?>
  <div style="padding:16px 18px 6px;">
    <?php foreach ($spend as $s): $pct = $maxSpend > 0 ? round($s->total_spend / $maxSpend * 100, 1) : 0; ?>
    <div style="margin-bottom:12px;">
      <div style="display:flex;justify-content:space-between;font-size:11.5px;margin-bottom:4px;">
        <span style="font-weight:600;color:var(--t1);"><?= htmlspecialchars($s->company_name) ?> <span style="color:var(--t3);font-weight:400;">(<?= htmlspecialchars($s->country) ?>)</span></span>
        <span class="mono" style="color:var(--t3);"><?= number_format($s->total_spend,0) ?> &middot; <?= $s->order_count ?> orders</span>
      </div>
      <div style="background:var(--g2);border-radius:5px;height:9px;overflow:hidden;">
        <div style="background:var(--blue);height:100%;width:<?= $pct ?>%;border-radius:5px;"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Lead time by route -->
<div class="card">
  <div class="ch">
    <div><div class="ct">Average Lead Time by Route</div><div class="cs">Learned from closed orders — country + shipping method combination</div></div>
  </div>
  <?php if (empty($lead_times)): ?>
  <div style="padding:48px;text-align:center;color:var(--t3);">Not enough closed orders yet to calculate route lead-times. This fills in automatically as orders are received and verified.</div>
  <?php else: ?>
  <table class="tbl">
    <thead><tr><th>Country</th><th>Shipping Method</th><th style="text-align:center;">Avg. Lead Time</th><th style="text-align:center;">Sample Size</th><th style="text-align:center;">Confidence</th></tr></thead>
    <tbody>
    <?php foreach ($lead_times as $lt):
      $conf = $lt->n >= 5 ? array('High','var(--green)','var(--green-d)') : ($lt->n >= 3 ? array('Medium','var(--amber)','var(--amber-d)') : array('Low','var(--t3)','var(--g2)'));
    ?>
    <tr>
      <td style="font-weight:600;color:var(--t1);"><?= htmlspecialchars($lt->country) ?></td>
      <td><?= htmlspecialchars($lt->shipping_method) ?></td>
      <td class="mono" style="text-align:center;font-weight:600;"><?= round($lt->avg_days) ?> days</td>
      <td class="mono" style="text-align:center;color:var(--t3);"><?= $lt->n ?> order(s)</td>
      <td style="text-align:center;"><span style="padding:3px 8px;border-radius:5px;font-size:10.5px;font-weight:700;color:<?= $conf[1] ?>;background:<?= $conf[2] ?>;"><?= $conf[0] ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div style="padding:10px 18px 16px;font-size:10.5px;color:var(--t3);">Confidence: Low &lt;3 orders · Medium 3–4 · High 5+. These figures directly feed the expected-delivery-date estimate when creating new orders.</div>
  <?php endif; ?>
</div>
