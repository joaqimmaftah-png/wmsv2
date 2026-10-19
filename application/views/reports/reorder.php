<?php
/**
 * Re-order report — replaces the "Alerte", "Variance" and "Qty to order"
 * columns the store had to maintain by hand in the Stock List.
 */
$f  = isset($filter) ? $filter : array();
$g  = function ($k) use ($f) { return isset($f[$k]) && $f[$k] !== null ? $f[$k] : ''; };
$qs = array_filter((array)$f, function ($v) { return $v !== null && $v !== ''; });
$out = 0; $toOrder = 0;
foreach ($rows as $m) { if ((int)$m->final_qty <= 0) $out++; $toOrder += (int)$m->qty_to_order; }
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Re-order Report</div>
    <div class="cs">Items at or below their minimum stock — the quantity to order is calculated, never typed</div>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('reports/export_reorder_xlsx?'.http_build_query($qs)) ?>" class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Download Excel
    </a>
    <a href="<?= base_url('orders') ?>" class="btn btn-ghost btn-sm">Spare orders</a>
  </div>
</div>

<?php if (empty($extended)): ?>
<div class="card mb-4" style="padding:14px;border-left:3px solid var(--amber);font-size:13px;">
  Minimum / maximum stock levels are not installed yet — the report falls back to the
  generic low-stock threshold. The level columns are part of the database dump shipped
  with this repository; the levels set in the Stock List come in with the inventory Excel import.
</div>
<?php endif; ?>

<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Out of Stock</span><div class="kpi-num"><?= number_format($out) ?></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Items to Re-order</span><div class="kpi-num"><?= number_format(count($rows)) ?></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total Qty to Order</span><div class="kpi-num"><?= number_format($toOrder) ?></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Rule</span><div class="kpi-num" style="font-size:14px;">stock &le; min &rarr; order up to max</div></div>
</div>

<form method="get" class="flex ai-c gap-10 mb-4" style="flex-wrap:wrap;">
  <select name="category" class="form-select" style="width:170px;">
    <option value="">All categories</option>
    <?php foreach ($categories as $c): ?>
    <option value="<?= htmlspecialchars($c->category) ?>" <?= $g('category') === $c->category ? 'selected' : '' ?>><?= htmlspecialchars($c->category) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="location" class="form-select" style="width:150px;">
    <option value="">All locations</option>
    <?php foreach ($locations as $l): ?>
    <option value="<?= htmlspecialchars($l->location) ?>" <?= $g('location') === $l->location ? 'selected' : '' ?>><?= htmlspecialchars($l->location) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="search" class="form-control" style="width:220px;" placeholder="Item code / description…" value="<?= htmlspecialchars($g('search')) ?>">
  <button type="submit" class="btn btn-primary btn-sm">Filter</button>
  <a href="<?= base_url('reports/reorder') ?>" class="btn btn-ghost btn-sm">Reset</a>
</form>

<div class="card">
  <div class="ch"><div><div class="ct">Items to Order</div>
    <div class="cs"><?= number_format(count($rows)) ?> item(s)</div></div></div>
  <div style="overflow-x:auto;margin-top:4px;">
    <table class="tbl">
      <thead>
        <tr>
          <th>Item Code</th><th>Category</th><th>Description</th><th>Part No / Size</th><th>Unit</th>
          <th class="text-right">In Stock</th><th class="text-right">Min</th><th class="text-right">Max</th>
          <th class="text-right">Qty to Order</th><th>Alert</th><th class="text-right">Price UGX</th>
          <th>Location</th><th>Supplier</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="13" style="text-align:center;padding:52px;color:var(--t3);">Nothing needs re-ordering — every item is above its minimum level</td></tr>
      <?php else: foreach ($rows as $m):
        $isOut = (int)$m->final_qty <= 0;
      ?>
        <tr>
          <td class="mono" style="font-size:11.5px;color:var(--blue);font-weight:500;"><?= htmlspecialchars($m->item_code) ?></td>
          <td style="font-size:11px;"><span class="badge b-primary" style="font-size:10px;"><?= htmlspecialchars($m->category) ?></span></td>
          <td style="font-size:12px;font-weight:500;"><?= htmlspecialchars($m->description) ?></td>
          <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)$m->part_number_size) ?></td>
          <td class="text-center" style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)$m->unit) ?></td>
          <td class="mono text-right" style="<?= $isOut ? 'color:var(--red);font-weight:700;' : 'color:var(--amber);font-weight:600;' ?>"><?= number_format((int)$m->final_qty) ?></td>
          <td class="mono text-right"><?= isset($m->min_stock) && $m->min_stock !== null ? number_format((int)$m->min_stock) : '—' ?></td>
          <td class="mono text-right"><?= isset($m->max_stock) && $m->max_stock !== null ? number_format((int)$m->max_stock) : '—' ?></td>
          <td class="mono text-right" style="font-weight:700;"><?= number_format((int)$m->qty_to_order) ?></td>
          <td><span class="badge <?= $isOut ? 'b-danger' : 'b-warning' ?>" style="font-size:10px;"><?= $isOut ? 'OUT OF STOCK' : 'ORDER' ?></span></td>
          <td class="mono text-right"><?= isset($m->unit_price_ugx) && $m->unit_price_ugx !== null ? number_format((float)$m->unit_price_ugx) : '' ?></td>
          <td style="font-size:12px;"><?= htmlspecialchars((string)$m->location) ?></td>
          <td style="font-size:12px;color:var(--t3);"><?= isset($m->supplier_name) ? htmlspecialchars((string)$m->supplier_name) : '' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <?php if (!empty($rows)): ?>
      <tfoot>
        <tr style="background:var(--g2);border-top:1px solid var(--sep);">
          <td colspan="8" style="font-weight:700;font-size:12px;padding:10px 18px;text-align:right;">TOTAL QTY TO ORDER</td>
          <td class="mono text-right" style="font-weight:700;"><?= number_format($toOrder) ?></td>
          <td colspan="4"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
