<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="wms-card">
      <div class="card-head"><i class="bi bi-droplet text-primary me-1"></i>Record Lubricant Consumption</div>
      <div class="card-body">
        <?= form_open('lubricants/record') ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-500">Equipment <span class="text-danger">*</span></label>
            <select name="equipment_id" id="equipment-select" class="form-select" required>
              <option value="">Select Equipment...</option>
              <?php foreach ($equipment as $eq): ?>
              <option value="<?= $eq->id ?>"><?= $eq->registration_no ?> — <?= $eq->brand . ' ' . $eq->model ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-500">Lube / Consumable Type <span class="text-danger">*</span></label>
            <select name="lube_type_id" class="form-select" required>
              <option value="">Select Type...</option>
              <?php
              $current_cat = '';
              foreach ($lube_types as $lt):
                if ($lt->category !== $current_cat) {
                  if ($current_cat) echo '</optgroup>';
                  echo '<optgroup label="' . $lt->category . '">';
                  $current_cat = $lt->category;
                }
              ?>
              <option value="<?= $lt->id ?>"><?= htmlspecialchars((string)$lt->name, ENT_QUOTES, 'UTF-8') ?> (<?= $lt->unit ?>)</option>
              <?php endforeach; ?>
              </optgroup>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-500">Quantity Used <span class="text-danger">*</span></label>
            <input type="number" name="quantity_used" class="form-control" step="0.1" min="0" required placeholder="e.g. 15.5">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-500">Equipment Hours</label>
            <input type="number" name="equipment_hours" class="form-control" step="0.1" placeholder="Hour meter reading">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-500">Date <span class="text-danger">*</span></label>
            <input type="date" name="consumption_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-12">
            <label class="form-label fw-500">Work Order <span style="font-weight:400;color:var(--t3);">(optional — attribute this top-up to a job)</span></label>
            <select name="work_order_id" id="work-order-select" class="form-select">
              <option value="">— not linked to a work order —</option>
              <?php if (isset($open_wos)): foreach ($open_wos as $w): ?>
              <option value="<?= (int)$w->id ?>" data-equipment="<?= (int)$w->equipment_id ?>">
                <?= htmlspecialchars((string)($w->wo_number)) ?> — <?= htmlspecialchars((string)$w->registration_no) ?> — <?= htmlspecialchars((string)($w->title)) ?>
              </option>
              <?php endforeach; endif; ?>
            </select>
            <div style="font-size:10.5px;color:var(--t3);margin-top:4px;">
              The work order must belong to the equipment selected above.
            </div>
          </div>
          <div class="col-12">
            <label class="form-label fw-500">Variance Reason (if used more/less than standard)</label>
            <input type="text" name="variance_reason" class="form-control" placeholder="Reason for over/under consumption...">
          </div>
        </div>
        <hr class="my-3">
        <div class="d-flex gap-2 justify-content-end">
          <a href="<?= base_url('lubricants') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Cancel</a>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Record Consumption</button>
        </div>
        <?= form_close() ?>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var eqSel = document.getElementById('equipment-select');
  var woSel = document.getElementById('work-order-select');
  if (!eqSel || !woSel) return;

  function filterWorkOrders() {
    var eq = eqSel.value;
    for (var i = 0; i < woSel.options.length; i++) {
      var opt = woSel.options[i];
      var own = opt.getAttribute('data-equipment');
      var off = (own !== null && eq !== '' && own !== eq);
      opt.hidden = off;
      opt.disabled = off;
    }
    if (woSel.selectedOptions && woSel.selectedOptions[0] && woSel.selectedOptions[0].disabled) {
      woSel.value = '';
    }
  }

  eqSel.addEventListener('change', filterWorkOrders);
  filterWorkOrders();
})();
</script>
