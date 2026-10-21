<?php
/**
 * Issue Register — the system replacement for the manual "Sortie" sheet.
 */
$f  = isset($filter) ? $filter : array();
$g  = function ($k) use ($f) { return isset($f[$k]) && $f[$k] !== null ? $f[$k] : ''; };
$qs = array_filter((array)$f, function ($v) { return $v !== null && $v !== ''; });
$t  = isset($totals) ? $totals : array('lines'=>0,'qty'=>0,'value'=>0,'items'=>0,'first'=>null,'last'=>null);
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Issue Register <span style="color:var(--t3);font-weight:400;">(Sortie)</span></div>
    <div class="cs">Every item issued from the store — what, when, to which machine or department, and to whom</div>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('reports/export_issue_register_xlsx?'.http_build_query($qs)) ?>" class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Download Excel
    </a>
    <a href="<?= base_url('materials/issue') ?>" class="btn btn-ghost btn-sm">Issue stock</a>
  </div>
</div>

<?php if (empty($available)): ?>
<div class="card" style="padding:18px;border-left:3px solid var(--amber);">
  <strong>The issue register is not installed yet.</strong><br>
  The database is missing the register columns on <code>material_transactions</code>.
  Import the database dump shipped with this repository.
</div>
<?php else: ?>

<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Issue Lines</span><div class="kpi-num"><?= number_format((float)($t['lines'])) ?></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Total Qty Issued</span><div class="kpi-num"><?= number_format((float)($t['qty'])) ?></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Distinct Items</span><div class="kpi-num"><?= number_format((float)($t['items'])) ?></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--t3)"></div>
    <span class="kpi-tag">Period Covered</span>
    <div class="kpi-num" style="font-size:16px;"><?= $t['first'] ? htmlspecialchars((string)($t['first'].' → '.$t['last'])) : '—' ?></div></div>
</div>

<form method="get" class="flex ai-c gap-10 mb-4" style="flex-wrap:wrap;">
  <input type="date" name="date_from" class="form-control" style="width:150px;" value="<?= htmlspecialchars((string)($g('date_from'))) ?>" title="From">
  <input type="date" name="date_to" class="form-control" style="width:150px;" value="<?= htmlspecialchars((string)($g('date_to'))) ?>" title="To">
  <select name="category" class="form-select" style="width:160px;">
    <option value="">All categories</option>
    <?php foreach ($categories as $c): ?>
    <option value="<?= htmlspecialchars((string)($c->category)) ?>" <?= $g('category') === $c->category ? 'selected' : '' ?>><?= htmlspecialchars((string)($c->category)) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="equipment_id" class="form-select" style="width:150px;">
    <option value="">All machines</option>
    <?php foreach ($equipment as $e): ?>
    <option value="<?= (int)$e->id ?>" <?= (string)$g('equipment_id') === (string)$e->id ? 'selected' : '' ?>><?= htmlspecialchars((string)($e->registration_no)) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="allocation" class="form-control" style="width:160px;" placeholder="Allocation (e.g. HSE)" value="<?= htmlspecialchars((string)($g('allocation'))) ?>">
  <input type="text" name="person" class="form-control" style="width:140px;" placeholder="Receiver" value="<?= htmlspecialchars((string)($g('person'))) ?>">
  <input type="text" name="reference" class="form-control" style="width:130px;" placeholder="Requisition no" value="<?= htmlspecialchars((string)($g('reference'))) ?>">
  <input type="text" name="search" class="form-control" style="width:190px;" placeholder="Item / part no…" value="<?= htmlspecialchars((string)($g('search'))) ?>">
  <button type="submit" class="btn btn-primary btn-sm">Filter</button>
  <a href="<?= base_url('reports/issue_register') ?>" class="btn btn-ghost btn-sm">Reset</a>
</form>

<?php if (!empty($by_alloc)): ?>
<div class="card mb-4">
  <div class="ch"><div><div class="ct">Consumption by Allocation</div>
    <div class="cs">Top cost centres and machines for the selected period</div></div></div>
  <div style="overflow-x:auto;margin-top:4px;">
    <table class="tbl">
      <thead><tr><th>Allocation</th><th>Type</th><th class="text-right">Lines</th><th class="text-right">Qty</th></tr></thead>
      <tbody>
      <?php foreach ($by_alloc as $a): ?>
        <tr>
          <td class="mono" style="font-weight:600;"><?= htmlspecialchars((string)($a->allocation)) ?></td>
          <td style="font-size:11px;"><span class="badge <?= $a->allocation_type === 'Equipment' ? 'b-primary' : 'b-secondary' ?>" style="font-size:10px;"><?= htmlspecialchars((string)($a->allocation_type)) ?></span></td>
          <td class="mono text-right"><?= number_format((float)($a->lines)) ?></td>
          <td class="mono text-right"><?= number_format((float)($a->qty)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">
    <div><div class="ct">Issues</div>
      <div class="cs"><?= number_format((float)(count($rows))) ?> line(s) shown<?= count($rows) >= 5000 ? ' — narrow the filter to see more' : '' ?></div></div>
  </div>
  <div style="overflow-x:auto;margin-top:4px;">
    <table class="tbl">
      <thead>
        <tr>
          <th>Date</th><th>Code</th><th>Category</th><th>Description</th><th>Part No</th>
          <th>Unit</th><th class="text-right">Qty</th><th>Allocation</th><th>Charged To</th>
          <th>Receiver</th><th>Req. No</th><th>Source</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="12" style="text-align:center;padding:52px;color:var(--t3);">No issues match this filter</td></tr>
      <?php else: foreach ($rows as $x): ?>
        <tr>
          <td style="font-size:12px;"><?= htmlspecialchars((string)$x->txn_date) ?></td>
          <td class="mono" style="font-size:11.5px;color:var(--blue);"><?= htmlspecialchars((string)$x->item_code) ?></td>
          <td style="font-size:11px;"><span class="badge b-primary" style="font-size:10px;"><?= htmlspecialchars((string)$x->category) ?></span></td>
          <td style="font-size:12px;font-weight:500;"><?= htmlspecialchars((string)$x->description) ?></td>
          <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)$x->part_number_size) ?></td>
          <td class="text-center" style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)$x->unit) ?></td>
          <td class="mono text-right"><?= number_format((float)$x->qty) ?></td>
          <td class="mono" style="font-size:12px;font-weight:600;"><?= htmlspecialchars((string)($x->equipment_code ?: $x->allocation_label)) ?></td>
          <td style="font-size:11px;"><?= htmlspecialchars((string)$x->allocation_type) ?></td>
          <td style="font-size:12px;"><?= htmlspecialchars((string)$x->person) ?></td>
          <td class="mono" style="font-size:12px;"><?= htmlspecialchars((string)$x->reference_no) ?></td>
          <td style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)$x->source) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
