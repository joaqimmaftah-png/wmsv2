<div style="max-width:880px;margin:0 auto;">
<div class="card">
  <div class="ch">
    <div><div class="ct">New Spare Parts Order</div><div class="cs">Expected delivery date is estimated automatically from past orders on this route</div></div>
    <a href="<?= base_url('orders') ?>" class="ca">&larr; Back</a>
  </div>
  <div style="padding:24px 28px 32px;">
    <?= form_open('orders/create') ?>

    <div class="form-grid cols-2" style="margin-bottom:16px;">
      <div>
        <label class="form-label">Supplier <span class="req">*</span></label>
        <select name="supplier_id" id="supplier_id" class="form-select" required>
          <option value="">— Select Supplier —</option>
          <?php foreach ($suppliers as $s): ?>
          <option value="<?= $s->id ?>" data-country="<?= htmlspecialchars($s->country) ?>" data-currency="<?= htmlspecialchars($s->currency) ?>">
            <?= htmlspecialchars($s->company_name) ?> (<?= htmlspecialchars($s->country) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Shipping Method <span class="req">*</span></label>
        <select name="shipping_method" id="shipping_method" class="form-select" required>
          <?php foreach ($methods as $m): ?><option value="<?= $m ?>"><?= $m ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Order Date</label>
        <input type="date" name="order_date" id="order_date" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>
      <div>
        <label class="form-label">Expected Delivery Date</label>
        <input type="date" name="expected_delivery_date" id="expected_delivery_date" class="form-control">
        <div class="cs" id="lead_estimate_note" style="margin-top:4px;">Select supplier + shipping method to estimate</div>
      </div>
      <div>
        <label class="form-label">Currency</label>
        <select name="currency" id="currency" class="form-select">
          <?php $order_currency = isset($order->currency) && $order->currency !== '' ? strtoupper($order->currency) : wms_site_currency(); ?>
          <?php foreach (wms_currencies() as $c): ?><option value="<?= $c ?>" <?= $order_currency === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Linked Work Order # (optional)</label>
        <input type="number" name="linked_wo_id" class="form-control" placeholder="e.g. 145">
      </div>
    </div>

    <div class="sec-head">Items</div>
    <div id="item-rows">
      <div class="form-grid item-row" style="grid-template-columns:1.6fr 1fr 1fr 0.8fr 0.8fr auto;gap:8px;margin-bottom:8px;">
        <input type="text" name="item_description[]" class="form-control" placeholder="Item description *">
        <input type="text" name="item_part_number[]" class="form-control" placeholder="Part number">
        <select name="item_material_id[]" class="form-select">
          <option value="">Not in catalog</option>
          <?php foreach ($materials as $m): ?><option value="<?= $m->id ?>"><?= htmlspecialchars($m->item_code) ?></option><?php endforeach; ?>
        </select>
        <input type="number" step="0.01" name="item_qty[]" class="form-control" placeholder="Qty">
        <input type="number" step="0.01" name="item_price[]" class="form-control" placeholder="Unit Price">
        <input type="hidden" name="item_unit[]" value="pcs">
        <button type="button" class="btn btn-ghost btn-sm remove-row" style="color:var(--red);">&times;</button>
      </div>
    </div>
    <button type="button" id="add-row" class="btn btn-ghost btn-sm" style="margin-bottom:18px;">+ Add Item</button>

    <div class="form-grid cols-2" style="margin-bottom:16px;">
      <div>
        <label class="form-label">Total Order Cost</label>
        <input type="number" step="0.01" name="total_cost" class="form-control" placeholder="0.00">
      </div>
    </div>
    <div style="margin-bottom:24px;">
      <label class="form-label">Notes</label>
      <textarea name="notes" class="form-control" rows="2" placeholder="Any additional notes about this order"></textarea>
    </div>

    <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;border-top:1px solid var(--sep);padding-top:16px;">
      <a href="<?= base_url('orders') ?>" class="btn btn-ghost">Cancel</a>
      <button type="submit" class="btn btn-primary">+ Create Order</button>
    </div>
    <?= form_close() ?>
  </div>
</div>
</div>

<script>
(function(){
  var rows = document.getElementById('item-rows');
  document.getElementById('add-row').addEventListener('click', function(){
    var row = rows.firstElementChild.cloneNode(true);
    row.querySelectorAll('input').forEach(function(i){ if(i.type!=='hidden') i.value=''; });
    row.querySelector('select[name="item_material_id[]"]').selectedIndex = 0;
    rows.appendChild(row);
    bindRemove(row);
  });
  function bindRemove(row){
    row.querySelector('.remove-row').addEventListener('click', function(){
      if (rows.children.length > 1) row.remove();
    });
  }
  bindRemove(rows.firstElementChild);

  function updateEstimate(){
    var sup = document.getElementById('supplier_id');
    var method = document.getElementById('shipping_method').value;
    var opt = sup.options[sup.selectedIndex];
    var country = opt ? opt.getAttribute('data-country') : null;
    var currency = opt ? opt.getAttribute('data-currency') : null;
    if (currency) document.getElementById('currency').value = currency;
    if (!sup.value || !country || !method) return;
    var note = document.getElementById('lead_estimate_note');
    note.textContent = 'Estimating…';
    fetch('<?= base_url("orders/api_lead_time_estimate") ?>?country=' + encodeURIComponent(country) + '&method=' + encodeURIComponent(method) + '&supplier_id=' + sup.value)
      .then(function(r){ return r.json(); })
      .then(function(d){
        var orderDate = document.getElementById('order_date').value || '<?= date("Y-m-d") ?>';
        var dt = new Date(orderDate);
        dt.setDate(dt.getDate() + d.days);
        document.getElementById('expected_delivery_date').value = dt.toISOString().slice(0,10);
        var src = d.source === 'history' ? ('based on ' + d.sample + ' past orders on this route') : (d.source === 'supplier_quote' ? "based on supplier's quoted lead time" : 'using default estimate — no history yet');
        note.textContent = '~' + d.days + ' days, ' + src;
      })
      .catch(function(){ note.textContent = 'Could not estimate — set manually.'; });
  }
  document.getElementById('supplier_id').addEventListener('change', updateEstimate);
  document.getElementById('shipping_method').addEventListener('change', updateEstimate);
  document.getElementById('order_date').addEventListener('change', updateEstimate);
})();
</script>
