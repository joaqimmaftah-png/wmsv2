<?php
/**
 * Receipt Register — the system replacement for the manual "Receipts" sheet.
 */
$f  = isset($filter) ? $filter : array();
$g  = function ($k) use ($f) { return isset($f[$k]) && $f[$k] !== null ? $f[$k] : ''; };
$qs = array_filter((array)$f, function ($v) { return $v !== null && $v !== ''; });
$t  = isset($totals) ? $totals : array('lines'=>0,'qty'=>0,'value'=>0,'items'=>0,'first'=>null,'last'=>null);
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Receipt Register</div>
    <div class="cs">Goods received into the store — delivery date, MRN, supplier, approved vs delivered quantity</div>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('reports/export_receipt_register_xlsx?'.http_build_query($qs)) ?>" class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Download Excel
    </a>
  </div>
</div>

<?php if (empty($available)): ?>
<div class="card" style="padding:18px;border-left:3px solid var(--amber);">
  <strong>The receipt register is not installed yet.</strong><br>
  The database is missing the register columns on <code>material_transactions</code>.
  Import the database dump shipped with this repository.
</div>
<?php else: ?>

<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Receipt Lines</span><div class="kpi-num"><?= number_format($t['lines']) ?></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Total Qty Received</span><div class="kpi-num"><?= number_format($t['qty']) ?></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Value (where priced)</span><div class="kpi-num" style="font-size:18px;"><?= number_format($t['value']) ?></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--t3)"></div>
    <span class="kpi-tag">Period Covered</span>
    <div class="kpi-num" style="font-size:16px;"><?= $t['first'] ? htmlspecialchars($t['first'].' → '.$t['last']) : '—' ?></div></div>
</div>

<form method="get" class="flex ai-c gap-10 mb-4" style="flex-wrap:wrap;">
  <input type="date" name="date_from" class="form-control" style="width:150px;" value="<?= htmlspecialchars($g('date_from')) ?>" title="From">
  <input type="date" name="date_to" class="form-control" style="width:150px;" value="<?= htmlspecialchars($g('date_to')) ?>" title="To">
  <select name="category" class="form-select" style="width:160px;">
    <option value="">All categories</option>
    <?php foreach ($categories as $c): ?>
    <option value="<?= htmlspecialchars($c->category) ?>" <?= $g('category') === $c->category ? 'selected' : '' ?>><?= htmlspecialchars($c->category) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="supplier" class="form-select" style="width:230px;">
    <option value="">All suppliers</option>
    <?php foreach ($suppliers as $s): ?>
    <option value="<?= htmlspecialchars($s->supplier_name) ?>" <?= $g('supplier') === $s->supplier_name ? 'selected' : '' ?>><?= htmlspecialchars($s->supplier_name) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="reference" class="form-control" style="width:140px;" placeholder="MRN / reference" value="<?= htmlspecialchars($g('reference')) ?>">
  <input type="text" name="search" class="form-control" style="width:190px;" placeholder="Item / part no…" value="<?= htmlspecialchars($g('search')) ?>">
  <button type="submit" class="btn btn-primary btn-sm">Filter</button>
  <a href="<?= base_url('reports/receipt_register') ?>" class="btn btn-ghost btn-sm">Reset</a>
</form>

<div class="card">
  <div class="ch">
    <div><div class="ct">Receipts</div>
      <div class="cs"><?= number_format(count($rows)) ?> line(s) shown</div></div>
  </div>
  <div style="overflow-x:auto;margin-top:4px;">
    <table class="tbl">
      <thead>
        <tr>
          <th>Delivery Date</th><th>MRN / Ref</th><th>Code</th><th>Category</th>
          <th>Description</th><th>Part No</th><th>Unit</th>
          <th class="text-right">Approved</th><th class="text-right">Delivered</th>
          <th class="text-right">Unit Price</th><th>Supplier</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="11" style="text-align:center;padding:52px;color:var(--t3);">No receipts match this filter</td></tr>
      <?php else: foreach ($rows as $x):
        $short = ($x->qty_approved !== null && (float)$x->qty < (float)$x->qty_approved);
      ?>
        <tr>
          <td style="font-size:12px;"><?= htmlspecialchars((string)$x->txn_date) ?></td>
          <td class="mono" style="font-size:12px;"><?= htmlspecialchars((string)$x->reference_no) ?></td>
          <td class="mono" style="font-size:11.5px;color:var(--blue);"><?= htmlspecialchars((string)$x->item_code) ?></td>
          <td style="font-size:11px;"><span class="badge b-primary" style="font-size:10px;"><?= htmlspecialchars((string)$x->category) ?></span></td>
          <td style="font-size:12px;font-weight:500;"><?= htmlspecialchars((string)$x->description) ?></td>
          <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)$x->part_number_size) ?></td>
          <td class="text-center" style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)$x->unit) ?></td>
          <td class="mono text-right"><?= $x->qty_approved === null ? '' : number_format((float)$x->qty_approved) ?></td>
          <td class="mono text-right" style="<?= $short ? 'color:var(--red);font-weight:700;' : '' ?>"><?= number_format((float)$x->qty) ?>
            <?php if ($short): ?><span class="badge b-danger" style="font-size:9px;">SHORT</span><?php endif; ?></td>
          <td class="mono text-right"><?= $x->unit_price === null ? '' : number_format((float)$x->unit_price, 2) ?></td>
          <td style="font-size:12px;"><?= htmlspecialchars((string)$x->supplier_name) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
