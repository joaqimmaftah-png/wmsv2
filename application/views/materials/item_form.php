<?php
$item    = isset($item) ? $item : (object)array();
$is_edit = !empty($item->id);
$form_errors   = isset($form_errors) ? $form_errors : array();
$form_warnings = isset($form_warnings) ? $form_warnings : array();
$role_id = isset($role_id) ? $role_id : 0;

// Category / unit options come from the shared lookups (existing values in the
// database + the system defaults) so manual entry accepts exactly the same
// values as the Excel import.
if (!isset($category_options) || !is_array($category_options) || empty($category_options)) {
    $category_options = array('CONSUMABLE','SPARE PART','LUBRICANT','ELECTRICAL','HYDRAULIC','TOOL','OTHER');
}
if (!isset($unit_options) || !is_array($unit_options) || empty($unit_options)) {
    $unit_options = array('pcs','L','Kg','m','Box','Cyl','Roll','Pair','Set','Tube');
}
$cur_cat = isset($item->category) && $item->category !== '' ? $item->category : 'CONSUMABLE';
if (!in_array($cur_cat, $category_options, true)) { array_unshift($category_options, $cur_cat); }
$cur_unit = isset($item->unit) && $item->unit !== '' ? $item->unit : 'pcs';
if (!in_array($cur_unit, $unit_options, true)) { array_unshift($unit_options, $cur_unit); }

$init = isset($item->initial_stock) ? (int)$item->initial_stock : 0;
$recv = isset($item->qty_received)  ? (int)$item->qty_received  : 0;
$iss  = isset($item->qty_sortie)    ? (int)$item->qty_sortie    : 0;
$final = $init + $recv - $iss;
?>
<div style="max-width:760px;margin:0 auto;">
<div class="card">
  <div class="ch">
    <div>
      <div class="ct"><?= $is_edit ? 'Edit: '.htmlspecialchars($item->item_code) : 'Add Inventory Item' ?></div>
      <div class="cs"><?= $is_edit ? 'Update item details and stock quantities' : 'Add one item manually — for many items use “Import from Excel”' ?></div>
    </div>
    <a href="<?= base_url('materials/inventory') ?>" class="ca">&larr; Back</a>
  </div>
  <div style="padding:24px 28px 32px;">

    <?php if (!empty($form_errors)): ?>
    <div style="background:var(--red-d);border:1px solid var(--red);border-radius:var(--r2);padding:12px 16px;margin-bottom:18px;color:var(--red);font-size:12.5px;">
      <div style="font-weight:700;margin-bottom:6px;">The item was not saved:</div>
      <?php foreach ($form_errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
      <?php foreach ($form_warnings as $w): ?>
      <div style="color:var(--amber);">• <?= htmlspecialchars($w) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?= form_open($is_edit ? 'materials/edit/'.$item->id : 'materials/create') ?>

    <div class="form-grid cols-2" style="margin-bottom:16px;">
      <div>
        <label class="form-label">Item Code <span class="req">*</span></label>
        <input type="text" name="item_code" class="form-control" required
          value="<?= htmlspecialchars($item->item_code??'') ?>"
          placeholder="e.g. SPR001, LUB002" style="text-transform:uppercase;">
        <div class="cs" style="margin-top:4px;">Must be unique — letters, numbers, spaces and . _ - / ( ).</div>
      </div>
      <div>
        <label class="form-label">Category <span class="req">*</span></label>
        <select name="category" class="form-select" required>
          <?php foreach ($category_options as $cat): ?>
          <option value="<?= htmlspecialchars($cat) ?>" <?= ($cur_cat === $cat) ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-2">
        <label class="form-label">Description <span class="req">*</span></label>
        <input type="text" name="description" class="form-control" required
          value="<?= htmlspecialchars($item->description??'') ?>"
          placeholder="Full item description" maxlength="255">
      </div>
      <div>
        <label class="form-label">Part Number / Size / Grade</label>
        <input type="text" name="part_number_size" class="form-control" maxlength="100"
          value="<?= htmlspecialchars($item->part_number_size??'') ?>"
          placeholder="e.g. 15W-40, M20, ISO46">
      </div>
      <div>
        <label class="form-label">Unit of Measure</label>
        <select name="unit" class="form-select">
          <?php foreach ($unit_options as $un): ?>
          <option value="<?= htmlspecialchars($un) ?>" <?= ($cur_unit === $un) ? 'selected' : '' ?>><?= htmlspecialchars($un) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="sec-head">Stock Quantities</div>
    <div class="form-grid cols-3" style="margin-bottom:8px;">
      <div>
        <label class="form-label">Initial Stock (Opening Balance)</label>
        <input type="number" name="initial_stock" class="form-control" id="init-stock" min="0" step="1"
          value="<?= (int)($item->initial_stock ?? 0) ?>" placeholder="0">
      </div>
      <?php if ($is_edit): ?>
      <div>
        <label class="form-label">Total Received</label>
        <input type="number" name="qty_received" class="form-control" id="recv-stock" min="0" step="1"
          value="<?= (int)($item->qty_received??0) ?>">
      </div>
      <div>
        <label class="form-label">Total Issued</label>
        <input type="number" name="qty_sortie" class="form-control" id="iss-stock" min="0" step="1"
          value="<?= (int)($item->qty_sortie??0) ?>">
      </div>
      <?php else: ?>
      <div style="grid-column:span 2;">
        <label class="form-label" style="color:var(--t4);">Qty Received &amp; Issued</label>
        <div style="font-size:12px;color:var(--t4);padding:10px 0;">
          These are updated automatically via Receive / Issue transactions.
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div style="background:var(--g1);border:1px solid var(--sep);border-radius:var(--r2);padding:10px 14px;margin-bottom:16px;font-size:12px;color:var(--t2);">
      In stock after saving:
      <span id="stock-preview" style="font-family:inherit,monospace;font-weight:700;color:<?= $final <= 0 ? 'var(--red)' : ($final <= 5 ? 'var(--amber)' : 'var(--green)') ?>;"><?= $final ?></span>
      <span style="color:var(--t3);"><?= htmlspecialchars($cur_unit) ?></span>
      <span style="color:var(--t4);"> (opening + received − issued)</span>
      <?php if ($is_edit): ?>
      <div style="color:var(--t3);margin-top:4px;">
        Day-to-day stock changes should use
        <a href="<?= base_url('materials/receive/'.$item->id) ?>">Receive</a> /
        <a href="<?= base_url('materials/issue/'.$item->id) ?>">Issue</a> — correcting the totals here is logged
        as an adjustment movement.
      </div>
      <?php endif; ?>
    </div>

    <div class="sec-head">Location &amp; Application</div>
    <div class="form-grid cols-2" style="margin-bottom:28px;">
      <div>
        <label class="form-label">Storage Location</label>
        <input type="text" name="location" class="form-control" maxlength="100"
          value="<?= htmlspecialchars($item->location??'') ?>"
          placeholder="e.g. Store Room A, Shelf B3">
      </div>
      <div>
        <label class="form-label">Application / Equipment</label>
        <input type="text" name="application" class="form-control" maxlength="150"
          value="<?= htmlspecialchars($item->application??'') ?>"
          placeholder="e.g. ENGINE, HYDRAULIC, ALL">
      </div>
    </div>

    <?php if (!empty($has_extended)): ?>
    <div class="sec-head">Re-order Control &amp; Price</div>
    <div class="cs" style="margin:-6px 0 12px;">
      The Re-order Report lists every item whose stock has fallen to the minimum and
      calculates the quantity to order (up to the maximum) — those figures are never typed in.
    </div>
    <div class="form-grid cols-2" style="margin-bottom:28px;">
      <div>
        <label class="form-label">Minimum Stock (re-order level)</label>
        <input type="number" name="min_stock" class="form-control" min="0" step="1"
          value="<?= htmlspecialchars(isset($item->min_stock) && $item->min_stock !== null ? $item->min_stock : '') ?>"
          placeholder="e.g. 5">
      </div>
      <div>
        <label class="form-label">Maximum Stock (target level)</label>
        <input type="number" name="max_stock" class="form-control" min="0" step="1"
          value="<?= htmlspecialchars(isset($item->max_stock) && $item->max_stock !== null ? $item->max_stock : '') ?>"
          placeholder="e.g. 20">
      </div>
      <div>
        <label class="form-label">Unit Price (UGX)</label>
        <input type="number" name="unit_price_ugx" class="form-control" min="0" step="0.01"
          value="<?= htmlspecialchars(isset($item->unit_price_ugx) && $item->unit_price_ugx !== null ? $item->unit_price_ugx : '') ?>">
      </div>
      <div>
        <label class="form-label">Preferred Supplier</label>
        <input type="text" name="supplier_name" class="form-control" maxlength="150"
          value="<?= htmlspecialchars(isset($item->supplier_name) ? $item->supplier_name : '') ?>"
          placeholder="e.g. FUCHS AUTO PARTS LTD">
      </div>
    </div>
    <?php endif; ?>

    <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;border-top:1px solid var(--sep);padding-top:16px;">
      <a href="<?= base_url('materials/inventory') ?>" class="btn btn-ghost">Cancel</a>
      <?php if ($is_edit && $role_id == 1): ?>
      <button type="submit" form="delete-material-form"
         onclick="return confirm('Delete this item? This cannot be undone.')"
         class="btn btn-danger">Delete</button>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary">
        <?= $is_edit ? '✓ Save Changes' : '+ Add Item' ?>
      </button>
    </div>
    <?= form_close() ?>
    <?php if ($is_edit && $role_id == 1): ?>
    <?= form_open('materials/delete/'.$item->id, array('id' => 'delete-material-form', 'style' => 'display:none;')) ?>
    <?= form_close() ?>
    <?php endif; ?>
  </div>
</div>
</div>

<script>
(function () {
  var init = document.getElementById('init-stock');
  var recv = document.getElementById('recv-stock');
  var iss  = document.getElementById('iss-stock');
  var out  = document.getElementById('stock-preview');
  if (!out) return;
  function num(el) { return el ? (parseInt(el.value, 10) || 0) : 0; }
  function refresh() {
    var v = num(init) + num(recv) - num(iss);
    out.textContent = v;
    out.style.color = v < 0 ? 'var(--red)' : (v <= 5 ? 'var(--amber)' : 'var(--green)');
  }
  [init, recv, iss].forEach(function (el) { if (el) el.addEventListener('input', refresh); });
  refresh();
})();
</script>
