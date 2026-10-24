<?php $f = isset($filter) ? $filter : []; ?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">Materials Movement</h1>
    <div class="cs">Inventory tracking — receipts and issues</div>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('reports/export_materials_xlsx?'.http_build_query($f)) ?>"
       class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Download Excel
    </a>
    <?php if ($role_id <= 2): ?>
    <a href="<?= base_url('materials/create') ?>" class="btn btn-primary btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
      Add Item
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Filters -->
<form method="get" class="flex ai-c gap-10 mb-4" style="flex-wrap:wrap;">
  <select name="category" class="form-select" style="width:160px;">
    <option value="">All Categories</option>
    <?php foreach ($categories as $cat): ?>
    <option value="<?= $cat->category ?>" <?= (!empty($f['category']) && $f['category']==$cat->category)?'selected':'' ?>><?= htmlspecialchars((string)($cat->category)) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="location" class="form-select" style="width:140px;">
    <option value="">All Locations</option>
    <?php foreach ($locations as $loc): ?>
    <option value="<?= $loc->location ?>" <?= (!empty($f['location']) && $f['location']==$loc->location)?'selected':'' ?>><?= htmlspecialchars((string)($loc->location)) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="search" class="form-control" style="width:200px;" placeholder="Search item…" value="<?= isset($f['search'])?htmlspecialchars((string)($f['search'])):'' ?>">
  <button type="submit" class="btn btn-primary btn-sm btn-icon">
    <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    Filter
  </button>
  <a href="<?= base_url('reports/materials_movement') ?>" class="btn btn-ghost btn-sm">Reset</a>
</form>

<!-- KPI strip -->
<?php
$totalItems  = count($materials);
$lowStock    = 0; $zeroStock = 0; $totalReceived = 0; $totalIssued = 0;
foreach ($materials as $m) {
    $finalQty = $m->initial_stock + $m->qty_received - $m->qty_sortie;
    if ($finalQty == 0) $zeroStock++;
    elseif ($finalQty <= 5) $lowStock++;
    $totalReceived += $m->qty_received;
    $totalIssued   += $m->qty_sortie;
}
?>
<div class="grid g-4 mb-4">
  <div class="kpi" style="animation-delay:.04s">
    <div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total Items</span>
    <div class="kpi-num"><?= $totalItems ?></div>
  </div>
  <div class="kpi" style="animation-delay:.08s">
    <div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Total Received</span>
    <div class="kpi-num"><?= number_format((float)($totalReceived)) ?></div>
  </div>
  <div class="kpi" style="animation-delay:.12s">
    <div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Total Issued</span>
    <div class="kpi-num"><?= number_format((float)($totalIssued)) ?></div>
  </div>
  <div class="kpi" style="animation-delay:.16s">
    <div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Low / Out of Stock</span>
    <div class="kpi-num"><?= $lowStock ?><sup style="font-size:11px;color:var(--t3);"> low</sup>&nbsp;<?= $zeroStock ?><sup style="font-size:11px;color:var(--red);">  out</sup></div>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="ch">
    <div><div class="ct">Inventory Items</div><div class="cs"><?= $totalItems ?> items</div></div>
  </div>
  <div style="overflow-x:auto;margin-top:4px;">
    <table class="tbl">
      <thead>
        <tr>
          <th>Item Code</th><th>Category</th><th>Description</th>
          <th>Part No / Size</th><th>Unit</th>
          <th style="text-align:right;">Initial</th>
          <th style="text-align:right;">Received</th>
          <th style="text-align:right;">Issued</th>
          <th style="text-align:right;">Final Qty</th>
          <th>Application</th><th>Location</th>
          <?php if ($role_id <= 2): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($materials)): ?>
        <tr><td colspan="12" style="text-align:center;padding:52px;color:var(--t3);">No materials found</td></tr>
        <?php else: foreach ($materials as $m):
          $finalQty = $m->initial_stock + $m->qty_received - $m->qty_sortie;
          $stockStyle = '';
          $stockBadge = '';
          if ($finalQty <= 0)  { $stockStyle='color:var(--red);font-weight:700;'; $stockBadge='<span class="badge b-danger" style="margin-left:4px;font-size:9px;">OUT</span>'; }
          elseif ($finalQty <= 5) { $stockStyle='color:var(--amber);font-weight:600;'; $stockBadge='<span class="badge b-warning" style="margin-left:4px;font-size:9px;">LOW</span>'; }
        ?>
        <tr>
          <td class="mono" style="font-size:11.5px;font-weight:500;color:var(--blue);"><?= htmlspecialchars((string)($m->item_code)) ?></td>
          <td><span class="badge b-primary" style="font-size:10px;"><?= htmlspecialchars((string)($m->category)) ?></span></td>
          <td style="font-weight:500;"><?= htmlspecialchars((string)($m->description)) ?></td>
          <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)($m->part_number_size ?? '')) ?></td>
          <td class="text-center" style="color:var(--t3);font-size:12px;"><?= htmlspecialchars((string)($m->unit)) ?></td>
          <td class="mono text-right" style="font-size:12px;"><?= number_format((float)($m->initial_stock)) ?></td>
          <td class="mono text-right text-green" style="font-size:12px;"><?= number_format((float)($m->qty_received)) ?></td>
          <td class="mono text-right text-amber" style="font-size:12px;"><?= number_format((float)($m->qty_sortie)) ?></td>
          <td class="mono text-right" style="font-size:13px;<?= $stockStyle ?>"><?= number_format((float)($finalQty)) ?><?= $stockBadge ?></td>
          <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)($m->application ?? '')) ?></td>
          <td style="font-size:12px;"><?= htmlspecialchars((string)($m->location ?? '')) ?></td>
          <?php if ($role_id <= 2): ?>
          <td>
            <a href="<?= base_url('materials/edit/'.$m->id) ?>" class="btn btn-ghost btn-xs">Edit</a>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if (!empty($materials)): ?>
      <tfoot>
        <tr style="background:var(--g2);border-top:1px solid var(--sep);">
          <td colspan="5" style="font-weight:700;font-size:12px;padding:10px 18px;">TOTAL</td>
          <td class="mono text-right" style="font-weight:700;"><?= number_format((float)(array_reduce((array)$materials, function($c,$m){ return $c + (int)$m->initial_stock; }, 0))) ?></td>
          <td class="mono text-right text-green fw-700"><?= number_format((float)(array_reduce((array)$materials, function($c,$m){ return $c + (int)$m->qty_received; }, 0))) ?></td>
          <td class="mono text-right text-amber fw-700"><?= number_format((float)(array_reduce((array)$materials, function($c,$m){ return $c + (int)$m->qty_sortie; }, 0))) ?></td>
          <td class="mono text-right fw-700"><?= number_format((float)(array_sum(array_map(function($m){ return $m->initial_stock + $m->qty_received - $m->qty_sortie; }, (array)$materials)))) ?></td>
          <td colspan="<?= $role_id<=2?3:2 ?>"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
