<?php $f = isset($filter) ? $filter : array(); ?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <form method="get" class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <select name="status" class="form-select" style="width:170px;">
      <option value="">All Status</option>
      <?php foreach ($statuses as $s): ?>
      <option value="<?= $s ?>" <?= (!empty($f['status']) && $f['status']==$s)?'selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <select name="supplier" class="form-select" style="width:170px;">
      <option value="">All Suppliers</option>
      <?php foreach ($suppliers as $s): ?>
      <option value="<?= $s->id ?>" <?= (!empty($f['supplier']) && $f['supplier']==$s->id)?'selected':'' ?>><?= htmlspecialchars($s->company_name) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="search" class="form-control" style="width:180px;" placeholder="Order no / tracking…" value="<?= htmlspecialchars($f['search']??'') ?>">
    <button type="submit" class="btn btn-primary btn-sm btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Filter
    </button>
    <a href="<?= base_url('orders') ?>" class="btn btn-ghost btn-sm">Reset</a>
  </form>
  <a href="<?= base_url('orders/create') ?>" class="btn btn-primary btn-sm btn-icon">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    New Order
  </a>
</div>

<!-- KPI strip -->
<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Open Orders</span><div class="kpi-num"><?= $kpi['open_orders'] ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--cyan)"></div>
    <span class="kpi-tag">In Transit / Customs</span><div class="kpi-num"><?= $kpi['in_transit'] ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Due in 7 Days</span><div class="kpi-num"><?= $kpi['due_soon'] ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Overdue</span><div class="kpi-num"><?= $kpi['overdue'] ?></div>
  </div>
</div>

<?php if (!empty($overdue) || !empty($due_soon)): ?>
<div class="grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
  <div class="card">
    <div class="ch"><div><div class="ct" style="color:var(--red);">⚠ Overdue Orders</div><div class="cs">Past expected delivery date, not yet received</div></div></div>
    <div style="padding:4px 18px 14px;">
      <?php if (empty($overdue)): ?><div style="color:var(--t3);font-size:12px;padding:10px 0;">None — nothing overdue.</div><?php endif; ?>
      <?php foreach ($overdue as $o): ?>
      <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--g2);font-size:12px;">
        <a href="<?= base_url('orders/view/'.$o->id) ?>" style="color:var(--blue);font-weight:600;"><?= htmlspecialchars($o->order_no) ?></a>
        <span style="color:var(--t3);"><?= htmlspecialchars($o->supplier_name) ?></span>
        <span style="color:var(--red);font-weight:600;">due <?= date('d M', strtotime($o->expected_delivery_date)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <div class="ch"><div><div class="ct" style="color:var(--amber);">⏱ Due Soon</div><div class="cs">Expected within 7 days</div></div></div>
    <div style="padding:4px 18px 14px;">
      <?php if (empty($due_soon)): ?><div style="color:var(--t3);font-size:12px;padding:10px 0;">None due soon.</div><?php endif; ?>
      <?php foreach ($due_soon as $o): ?>
      <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--g2);font-size:12px;">
        <a href="<?= base_url('orders/view/'.$o->id) ?>" style="color:var(--blue);font-weight:600;"><?= htmlspecialchars($o->order_no) ?></a>
        <span style="color:var(--t3);"><?= htmlspecialchars($o->supplier_name) ?></span>
        <span style="color:var(--amber);font-weight:600;">due <?= date('d M', strtotime($o->expected_delivery_date)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch"><div><div class="ct">All Orders</div><div class="cs"><?= count($orders) ?> orders</div></div></div>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:8px;">
      <thead><tr>
        <th>Order No</th><th>Supplier</th><th>Country</th><th>Shipping</th>
        <th>Order Date</th><th>Expected</th><th style="text-align:center;">Status</th><th style="text-align:right;">Cost</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (empty($orders)): ?>
      <tr><td colspan="9" style="text-align:center;padding:52px;color:var(--t3);">No orders found</td></tr>
      <?php else: foreach ($orders as $o):
        $overdueFlag = $o->expected_delivery_date && $o->expected_delivery_date < date('Y-m-d') && !in_array($o->status, array('Received at Store','Verified & Closed','Cancelled'));
        $col = $o->status==='Verified & Closed' ? 'var(--green)' : ($o->status==='Cancelled' ? 'var(--t3)' : ($overdueFlag ? 'var(--red)' : 'var(--blue)'));
        $bg  = $o->status==='Verified & Closed' ? 'var(--green-d)' : ($o->status==='Cancelled' ? 'var(--g2)' : ($overdueFlag ? 'var(--red-d)' : 'rgba(0,113,227,0.12)'));
      ?>
      <tr>
        <td><a href="<?= base_url('orders/view/'.$o->id) ?>" style="color:var(--t1);font-weight:600;"><?= htmlspecialchars($o->order_no) ?></a></td>
        <td><?= htmlspecialchars($o->supplier_name) ?></td>
        <td><?= htmlspecialchars($o->country) ?></td>
        <td style="font-size:11px;color:var(--t3);"><?= htmlspecialchars($o->shipping_method) ?></td>
        <td class="mono"><?= date('d M Y', strtotime($o->order_date)) ?></td>
        <td class="mono"><?= $o->expected_delivery_date ? date('d M Y', strtotime($o->expected_delivery_date)) : '—' ?></td>
        <td style="text-align:center;"><span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;color:<?= $col ?>;background:<?= $bg ?>;white-space:nowrap;"><?= htmlspecialchars($o->status) ?></span></td>
        <td class="mono" style="text-align:right;"><?= number_format($o->total_cost,2) ?> <?= htmlspecialchars($o->currency) ?></td>
        <td style="text-align:right;"><a href="<?= base_url('orders/view/'.$o->id) ?>" class="btn btn-ghost btn-sm">View</a></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
