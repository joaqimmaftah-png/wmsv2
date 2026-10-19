<?php
$issue_mode = isset($issue_mode) && $issue_mode;
$final_qty  = (int)$item->initial_stock + (int)$item->qty_received - (int)$item->qty_sortie;
$action_url = $issue_mode ? 'materials/issue/'.$item->id : 'materials/receive/'.$item->id;
?>
<div style="max-width:600px;margin:0 auto;">
<div class="card">
  <div class="ch">
    <div>
      <div class="ct"><?= $issue_mode ? '— Issue Stock' : '+ Receive Stock' ?></div>
      <div class="cs"><?= htmlspecialchars($item->item_code.' — '.$item->description) ?></div>
    </div>
    <a href="<?= base_url('materials/inventory') ?>" class="ca">&larr; Back</a>
  </div>
  <div style="padding:24px 28px 32px;">

    <!-- Item info strip -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:24px;">
      <div style="background:var(--g1);border:1px solid var(--sep);border-radius:var(--r2);padding:12px 14px;text-align:center;">
        <div style="font-size:9px;font-weight:700;text-transform:none;letter-spacing:0;color:var(--t3);margin-bottom:5px;">Category</div>
        <span class="badge b-primary" style="font-size:11px;"><?= htmlspecialchars($item->category) ?></span>
      </div>
      <div style="background:var(--g1);border:1px solid var(--sep);border-radius:var(--r2);padding:12px 14px;text-align:center;">
        <div style="font-size:9px;font-weight:700;text-transform:none;letter-spacing:0;color:var(--t3);margin-bottom:5px;">Current Stock</div>
        <div style="font-family:inherit,monospace;font-size:20px;font-weight:700;color:<?= $final_qty<=0?'var(--red)':($final_qty<=5?'var(--amber)':'var(--green)') ?>;">
          <?= $final_qty ?> <span style="font-size:11px;color:var(--t3);"><?= htmlspecialchars($item->unit) ?></span>
        </div>
      </div>
      <div style="background:var(--g1);border:1px solid var(--sep);border-radius:var(--r2);padding:12px 14px;text-align:center;">
        <div style="font-size:9px;font-weight:700;text-transform:none;letter-spacing:0;color:var(--t3);margin-bottom:5px;">Location</div>
        <div style="font-size:12.5px;font-weight:600;color:var(--t1);"><?= htmlspecialchars($item->location??'—') ?></div>
      </div>
    </div>

    <?= form_open($action_url) ?>

    <div style="margin-bottom:16px;">
      <label class="form-label">
        <?= $issue_mode ? 'Quantity to Issue' : 'Quantity Received' ?>
        <span class="req">*</span>
      </label>
      <div style="display:flex;align-items:center;gap:10px;">
        <input type="number" name="qty" class="form-control" id="qty-input"
          min="1" step="1" required autofocus placeholder="0"
          style="font-size:18px;font-weight:700;text-align:center;max-width:180px;"
          <?= $issue_mode ? 'max="'.$final_qty.'"' : '' ?>>
        <span style="font-size:14px;color:var(--t2);font-weight:600;"><?= htmlspecialchars($item->unit) ?></span>
      </div>
      <?php if ($issue_mode): ?>
      <div id="qty-warn" style="display:none;font-size:11.5px;color:var(--red);margin-top:6px;font-weight:600;">
        ⚠ Quantity exceeds available stock (<?= $final_qty ?> <?= htmlspecialchars($item->unit) ?>)
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($register_fields)): ?>
    <!-- Register fields — the information the manual Sortie / Receipts sheets
         carried and that makes the movement traceable afterwards. -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
      <div>
        <label class="form-label">Date</label>
        <input type="date" name="txn_date" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>
      <div>
        <label class="form-label"><?= $issue_mode ? 'Requisition No' : 'MRN / Delivery Note No' ?></label>
        <input type="text" name="reference_no" class="form-control" maxlength="50"
               placeholder="<?= $issue_mode ? 'e.g. 2684' : 'e.g. 476' ?>">
      </div>

      <?php if ($issue_mode): ?>
      <div>
        <label class="form-label">Allocated to (machine)</label>
        <select name="equipment_id" class="form-select">
          <option value="">— not a machine —</option>
          <?php foreach ((isset($equipment) ? $equipment : array()) as $eq): ?>
          <option value="<?= (int)$eq->id ?>"><?= htmlspecialchars($eq->registration_no) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">…or department / cost centre</label>
        <input type="text" name="allocation_label" class="form-control" maxlength="150"
               placeholder="e.g. WORKSHOP, HSE, WELDING BAY">
      </div>
      <div>
        <label class="form-label">Receiver</label>
        <input type="text" name="person" class="form-control" maxlength="100" placeholder="Who collected it">
      </div>
      <?php else: ?>
      <div>
        <label class="form-label">Supplier</label>
        <input type="text" name="supplier_name" class="form-control" maxlength="150"
               list="supplier-list" placeholder="Who delivered it">
        <datalist id="supplier-list">
          <?php foreach ((isset($suppliers) ? $suppliers : array()) as $sp): ?>
          <option value="<?= htmlspecialchars($sp->company_name) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div>
        <label class="form-label">Qty approved (if different)</label>
        <input type="number" name="qty_approved" class="form-control" min="0" step="1" placeholder="Ordered / approved qty">
      </div>
      <div>
        <label class="form-label">Unit price</label>
        <div style="display:flex;gap:8px;">
          <input type="number" name="unit_price" class="form-control" min="0" step="0.01">
          <select name="currency" class="form-select" style="width:96px;" title="Currency of the unit price"
                  aria-label="Currency of the unit price">
            <?php foreach (wms_currencies() as $c): ?>
            <option value="<?= $c ?>" <?= $c === wms_site_currency() ? 'selected' : '' ?>><?= $c ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="cs" style="margin-top:4px;">Recorded on the stock movement, so a receipt in another currency keeps its meaning.</div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="margin-bottom:24px;">
      <label class="form-label">Notes</label>
      <input type="text" name="notes" class="form-control"
        placeholder="<?= $issue_mode ? 'e.g. Used in WO-AML-023' : 'e.g. partial delivery, damaged packaging' ?>">
    </div>

    <!-- Preview -->
    <div style="background:var(--g1);border:1px solid var(--sep);border-radius:var(--r2);padding:14px 16px;margin-bottom:24px;">
      <div style="font-size:10.5px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Preview</div>
      <div style="display:flex;align-items:center;gap:8px;font-size:13px;">
        <span style="color:var(--t2);">Current:</span>
        <span style="font-family:inherit,monospace;font-weight:700;"><?= $final_qty ?></span>
        <span style="color:var(--t3);"><?= htmlspecialchars($item->unit) ?></span>
        <span style="color:var(--t3);margin:0 4px;"><?= $issue_mode ? '−' : '+' ?></span>
        <span id="preview-qty" style="font-family:inherit,monospace;font-weight:700;color:<?= $issue_mode?'var(--amber)':'var(--green)' ?>;">?</span>
        <span style="color:var(--t3);">=</span>
        <span id="preview-after" style="font-family:inherit,monospace;font-weight:700;font-size:15px;">?</span>
        <span style="color:var(--t3);"><?= htmlspecialchars($item->unit) ?></span>
      </div>
    </div>

    <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;border-top:1px solid var(--sep);padding-top:16px;">
      <a href="<?= base_url('materials/inventory') ?>" class="btn btn-ghost">Cancel</a>
      <button type="submit" id="submit-btn" class="btn <?= $issue_mode?'btn-amber':'btn-success' ?>"
        <?= ($issue_mode && $final_qty <= 0) ? 'disabled style="opacity:.4;cursor:not-allowed;"' : '' ?>>
        <?= $issue_mode ? '− Issue Stock' : '+ Receive Stock' ?>
      </button>
    </div>
    <?= form_close() ?>
  </div>
</div>
</div>

<script>
var currentStock = <?= $final_qty ?>;
var issueMode    = <?= $issue_mode ? 'true' : 'false' ?>;
var qtyInput     = document.getElementById('qty-input');
var warnDiv      = document.getElementById('qty-warn');
var prevQty      = document.getElementById('preview-qty');
var prevAfter    = document.getElementById('preview-after');
var submitBtn    = document.getElementById('submit-btn');

qtyInput.addEventListener('input', function() {
  var qty  = parseFloat(this.value) || 0;
  var after= issueMode ? currentStock - qty : currentStock + qty;

  if (prevQty)   prevQty.textContent   = qty > 0 ? qty : '?';
  if (prevAfter) prevAfter.textContent = qty > 0 ? after : '?';

  if (prevAfter && qty > 0) {
    prevAfter.style.color = after < 0 ? 'var(--red)' : (after <= 5 ? 'var(--amber)' : 'var(--green)');
  }

  if (issueMode && warnDiv && submitBtn) {
    if (qty > currentStock) {
      warnDiv.style.display = 'block';
      submitBtn.disabled    = true;
      submitBtn.style.opacity = '.4';
    } else {
      warnDiv.style.display = 'none';
      submitBtn.disabled    = false;
      submitBtn.style.opacity = '1';
    }
  }
});
</script>
