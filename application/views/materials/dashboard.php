<?php
$kpi = array(
    'items'          => $total_items,
    'out'            => $out_of_stock,
    'low'            => $low_stock,
    'ok'             => $total_items - $out_of_stock - $low_stock,
    'total_received' => $total_received,
    'total_issued'   => $total_issued,
);
?>

<!-- Action bar -->
<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div></div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('materials/inventory') ?>" class="btn btn-ghost btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
      View Inventory
    </a>
    <a href="<?= base_url('materials/transactions') ?>" class="btn btn-ghost btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Transactions
    </a>
    <a href="<?= base_url('materials/export_xlsx') ?>" class="btn btn-success btn-icon" title="Export every inventory record to Excel">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export Excel
    </a>
    <a href="<?= base_url('materials/template_xlsx') ?>" class="btn btn-ghost btn-icon" title="Empty import workbook with the correct columns">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
      Template
    </a>
    <?php if ($role_id <= 2): ?>
    <a href="<?= base_url('materials/inventory#import') ?>" class="btn btn-ghost btn-icon" title="Import many items from Excel">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Import Excel
    </a>
    <a href="<?= base_url('materials/create') ?>" class="btn btn-primary btn-icon" title="Add one item manually">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      Add Manually
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- KPI strip -->
<div class="grid g-4 mb-4">
  <div class="kpi" style="animation-delay:.04s">
    <div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total Items</span>
    <div class="kpi-num"><?= $kpi['items'] ?></div>
    <div class="kpi-chip chip-up">All categories</div>
  </div>
  <div class="kpi" style="animation-delay:.08s">
    <div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">In Stock</span>
    <div class="kpi-num"><?= $kpi['ok'] ?></div>
    <div class="kpi-chip chip-up">Stock &gt; 5</div>
  </div>
  <div class="kpi" style="animation-delay:.12s">
    <div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Low Stock</span>
    <div class="kpi-num"><?= $kpi['low'] ?></div>
    <div class="kpi-chip chip-warn">&le; 5 remaining</div>
  </div>
  <div class="kpi" style="animation-delay:.16s">
    <div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Out of Stock</span>
    <div class="kpi-num"><?= $kpi['out'] ?></div>
    <?php if ($kpi['out'] > 0): ?>
    <div class="kpi-chip chip-dn">Needs restocking</div>
    <?php endif; ?>
  </div>
  <div class="kpi" style="animation-delay:.20s">
    <div class="kpi-glow" style="background:var(--cyan)"></div>
    <span class="kpi-tag">Total Received</span>
    <div class="kpi-num"><?= number_format((float)($kpi['total_received'])) ?></div>
  </div>
  <div class="kpi" style="animation-delay:.24s">
    <div class="kpi-glow" style="background:var(--purple)"></div>
    <span class="kpi-tag">Total Issued</span>
    <div class="kpi-num"><?= number_format((float)($kpi['total_issued'])) ?></div>
  </div>
  <div class="kpi" style="animation-delay:.28s" style="grid-column:span 2;">
    <div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Consumption Rate</span>
    <?php $rate = $kpi['total_received'] > 0 ? round($kpi['total_issued']/$kpi['total_received']*100) : 0; ?>
    <div class="kpi-num"><?= $rate ?><sup>%</sup></div>
    <div class="kpi-chip <?= $rate > 80 ? 'chip-dn' : 'chip-up' ?>">Issued / Received</div>
  </div>
</div>

<div class="grid g-2 mb-4">
  <!-- By Category breakdown -->
  <div class="card">
    <div class="ch"><div><div class="ct">Stock by Category</div><div class="cs"><?= count($by_category) ?> categories</div></div></div>
    <div style="padding:0 0 8px;">
      <?php foreach ($by_category as $cat => $d):
        $pct = $kpi['items'] > 0 ? round($d['count']/$kpi['items']*100) : 0;
      ?>
      <div class="row-list-item">
        <div style="flex:1;">
          <div style="font-size:12.5px;font-weight:600;color:var(--t1);"><?= htmlspecialchars((string)($cat)) ?></div>
          <div style="font-size:10.5px;color:var(--t3);margin-top:2px;"><?= $d['count'] ?> items · <?= number_format((float)($d['stock'])) ?> in stock · <?= number_format((float)($d['issued'])) ?> issued</div>
          <div class="pbar" style="margin-top:5px;"><div class="pbar-fill" style="width:<?= $pct ?>%;background:var(--blue);"></div></div>
        </div>
        <div style="font-size:11px;color:var(--blue);font-weight:600;min-width:30px;text-align:right;"><?= $pct ?>%</div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Low stock alerts -->
  <div class="card">
    <div class="ch">
      <div><div class="ct">Low / Out of Stock Alerts</div><div class="cs">Items needing attention</div></div>
      <a href="<?= base_url('materials/inventory?stock=low') ?>" class="ca">View all →</a>
    </div>
    <div style="padding:0 0 8px;">
      <?php if (empty($low_items)): ?>
      <div style="text-align:center;padding:32px;color:var(--t3);">
        <div style="font-size:28px;margin-bottom:8px;">✅</div>
        All items have sufficient stock
      </div>
      <?php else: foreach ($low_items as $m):
        $final = (int)$m->initial_stock + (int)$m->qty_received - (int)$m->qty_sortie;
        $color = $final <= 0 ? 'var(--red)' : 'var(--amber)';
      ?>
      <div class="row-list-item">
        <div class="led <?= $final <= 0 ? 'led-crit' : 'led-warn' ?>"></div>
        <div style="flex:1;">
          <div style="font-size:12px;font-weight:600;color:var(--t1);"><?= htmlspecialchars((string)($m->item_code)) ?></div>
          <div style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)($m->description)) ?></div>
        </div>
        <div style="font-size:13px;font-weight:700;color:<?= $color ?>;"><?= $final ?> <span style="font-size:9px;color:var(--t4);"><?= htmlspecialchars((string)($m->unit)) ?></span></div>
        <?php if ($role_id <= 2): ?>
        <a href="<?= base_url('materials/receive/'.$m->id) ?>" class="btn btn-success btn-xs">Receive</a>
        <?php endif; ?>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<!-- Recent movements -->
<div class="card">
  <div class="ch">
    <div><div class="ct">Recent Movements</div><div class="cs">Latest stock transactions</div></div>
    <a href="<?= base_url('materials/transactions') ?>" class="ca">View all →</a>
  </div>
  <?php if (empty($recent)): ?>
  <div style="text-align:center;padding:40px;color:var(--t3);font-size:13px;">No transactions recorded yet</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:8px;">
      <thead><tr><th>Date</th><th>Item Code</th><th>Description</th><th>Type</th><th>Qty</th><th>Stock After</th><th>By</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $t): ?>
        <tr>
          <td class="mono" style="font-size:11px;color:var(--t3);"><?= date('d M Y H:i', strtotime($t->created_at)) ?></td>
          <td style="font-weight:600;color:var(--blue);"><?= htmlspecialchars((string)($t->item_code??'')) ?></td>
          <td style="font-size:12px;"><?= htmlspecialchars((string)($t->description??'')) ?></td>
          <td><span class="badge <?= $t->type==='RECEIVE'?'b-success':'b-warning' ?>"><?= $t->type ?></span></td>
          <td class="mono" style="font-weight:600;"><?= ($t->type==='RECEIVE'?'+':'-').abs($t->qty) ?> <span style="color:var(--t4);font-size:9px;"><?= htmlspecialchars((string)($t->unit??'')) ?></span></td>
          <td class="mono" style="font-size:12px;"><?= $t->stock_after ?? '' ?></td>
          <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)($t->user_name??'')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
