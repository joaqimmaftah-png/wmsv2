<?php
/**
 * Issue Register — the system replacement for the manual "Sortie" sheet.
 */
$f  = isset($filter) ? $filter : array();
$g  = function ($k) use ($f) { return isset($f[$k]) && $f[$k] !== null ? $f[$k] : ''; };
$qs = array_filter((array)$f, function ($v) { return $v !== null && $v !== ''; });
$t  = isset($totals) ? $totals : array('lines'=>0,'qty'=>0,'value'=>0,'items'=>0,'first'=>null,'last'=>null);
$limit = isset($row_limit) ? (int)$row_limit : 5000;
$pg = isset($pagination) && is_array($pagination) ? $pagination : array();
$page = isset($pg['page']) ? (int)$pg['page'] : 1;
$perPage = isset($pg['per_page']) ? (int)$pg['per_page'] : 25;
$totalPages = isset($pg['total_pages']) ? (int)$pg['total_pages'] : max(1, (int)ceil((int)$t['lines'] / max(1, $perPage)));
$showingFrom = isset($pg['showing_from']) ? (int)$pg['showing_from'] : (count($rows) > 0 ? (($page - 1) * $perPage + 1) : 0);
$showingTo = isset($pg['showing_to']) ? (int)$pg['showing_to'] : (count($rows) > 0 ? min((int)$t['lines'], $showingFrom + count($rows) - 1) : 0);
$exportTruncated = (int)$t['lines'] > $limit;
$pageUrl = function ($targetPage) use ($qs, $perPage) {
    $params = $qs;
    $params['per_page'] = $perPage;
    $params['page'] = (int)$targetPage;
    return base_url('reports/issue_register?'.http_build_query($params));
};
$qtyByUnit = isset($t['qty_by_unit']) && is_array($t['qty_by_unit']) ? $t['qty_by_unit'] : array();
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">Issue Register <span style="color:var(--t3);font-weight:400;">(Sortie)</span></h1>
    <div class="cs">Every item issued from the store — what, when, to which machine or department, and to whom</div>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('reports/export_issue_register_xlsx?'.http_build_query($qs)) ?>" class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Download Excel
    </a>
    <?php if (!empty($can_issue_stock)): ?>
    <a href="<?= base_url('materials/inventory') ?>" class="btn btn-ghost btn-sm"
       title="Choose an inventory item, then use its Issue action">Select item to issue</a>
    <?php endif; ?>
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
    <span class="kpi-tag">Quantity Issued</span>
    <div class="kpi-num" style="font-size:18px;">
      <?php if (empty($qtyByUnit)): ?>—<?php else: foreach ($qtyByUnit as $unit => $qty): ?>
        <div><?= $qty === null ? '—' : number_format((float)$qty) ?> <span style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)$unit) ?></span></div>
      <?php endforeach; endif; ?>
    </div>
    <div class="cs" style="margin-top:4px;">Full filtered scope · grouped by unit</div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Distinct Items</span><div class="kpi-num"><?= number_format((float)($t['items'])) ?></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--t3)"></div>
    <span class="kpi-tag">Period Covered</span>
    <div class="kpi-num" style="font-size:16px;"><?= $t['first'] ? htmlspecialchars((string)($t['first'].' → '.$t['last'])) : '—' ?></div></div>
</div>

<form method="get" class="flex ai-c gap-10 mb-4" style="flex-wrap:wrap;">
  <input type="hidden" name="per_page" value="<?= $perPage ?>">
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
    <div class="cs">Top cost centres and machines by movement lines · quantities grouped by unit</div></div></div>
  <div style="overflow-x:auto;margin-top:4px;">
    <table class="tbl">
      <thead><tr><th>Allocation</th><th>Type</th><th>Unit</th><th class="text-right">Lines</th><th class="text-right">Qty</th></tr></thead>
      <tbody>
      <?php foreach ($by_alloc as $a): ?>
        <tr>
          <td class="mono" style="font-weight:600;"><?= htmlspecialchars((string)($a->allocation)) ?></td>
          <td style="font-size:11px;"><span class="badge <?= $a->allocation_type === 'Equipment' ? 'b-primary' : 'b-secondary' ?>" style="font-size:10px;"><?= htmlspecialchars((string)($a->allocation_type)) ?></span></td>
          <td style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)($a->unit ?: '(unit not set)')) ?></td>
          <td class="mono text-right"><?= number_format((float)($a->lines)) ?></td>
          <td class="mono text-right"><?= number_format((float)($a->qty)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($exportTruncated): ?>
<div class="card mb-3" role="status" style="padding:14px 18px;border-left:3px solid var(--amber);">
  <strong>The screen is paginated across all <?= number_format((int)$t['lines']) ?> matching lines; Excel includes only the latest <?= number_format($limit) ?>.</strong>
  Narrow the filters for a complete Excel download. The workbook labels its capped scope and suppresses overall totals.
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">
    <div><div class="ct">Issues</div>
      <div class="cs">Showing <?= number_format($showingFrom) ?>–<?= number_format($showingTo) ?> of <?= number_format((int)$t['lines']) ?> matching lines</div></div>
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

<?php if ((int)$t['lines'] > 0):
  $pageNumbers = array_values(array_unique(array_filter(array(1, $page - 1, $page, $page + 1, $totalPages), function ($number) use ($totalPages) {
      return $number >= 1 && $number <= $totalPages;
  })));
  sort($pageNumbers);
  $previousPage = 0;
?>
<div class="flex ai-c jb gap-10 mt-3" style="flex-wrap:wrap;">
  <form method="get" class="flex ai-c gap-10" style="font-size:12px;">
    <?php foreach ($qs as $key => $value): ?>
    <input type="hidden" name="<?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>
    <label for="issuePerPage">Rows per page</label>
    <select id="issuePerPage" name="per_page" class="form-select" style="width:auto;">
      <?php foreach (array(10, 25, 50, 100) as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm">Update</button>
  </form>
  <nav class="flex ai-c gap-5" aria-label="Issue register pages">
    <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($pageUrl($page - 1), ENT_QUOTES, 'UTF-8') ?>">Previous</a><?php else: ?><span class="btn btn-ghost btn-sm" aria-disabled="true">Previous</span><?php endif; ?>
    <?php foreach ($pageNumbers as $number): ?>
      <?php if ($previousPage > 0 && $number > $previousPage + 1): ?><span aria-hidden="true">…</span><?php endif; ?>
      <?php if ($number === $page): ?><span class="btn btn-primary btn-sm" aria-current="page"><?= $number ?></span><?php else: ?><a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($pageUrl($number), ENT_QUOTES, 'UTF-8') ?>"><?= $number ?></a><?php endif; ?>
      <?php $previousPage = $number; ?>
    <?php endforeach; ?>
    <?php if ($page < $totalPages): ?><a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($pageUrl($page + 1), ENT_QUOTES, 'UTF-8') ?>">Next</a><?php else: ?><span class="btn btn-ghost btn-sm" aria-disabled="true">Next</span><?php endif; ?>
  </nav>
</div>
<?php endif; ?>
<?php endif; ?>
