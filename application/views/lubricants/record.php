<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$lub_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$can_write = isset($can_write) ? (bool)$can_write : TRUE;
?>
<style>
.lub-subnav {
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
  margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--sep, rgba(148,163,184,0.16));
}
.lub-subnav a {
  padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;
  text-decoration: none; color: var(--t2); background: var(--card, #111827);
  border: 1px solid var(--sep, rgba(148,163,184,0.16));
}
.lub-subnav a.is-active { background: var(--blue); color: #ffffff; border-color: var(--blue); }
</style>

<nav class="lub-subnav" aria-label="Lubricants navigation">
  <a href="<?= base_url('lubricants') ?>">Consumption Ledger</a>
  <?php if ($can_write): ?>
  <a href="<?= base_url('lubricants/budget') ?>">Lube Budget</a>
  <?php endif; ?>
  <a href="<?= base_url('lubricants/specifications') ?>">OEM Specifications</a>
  <a href="<?= base_url('lubricants/types') ?>">Lube Types</a>
  <a href="<?= base_url('lubricants/forecast') ?>">PM Forecast</a>
</nav>

<div style="max-width:760px;margin:0 auto;">
  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Record Lubricant Consumption</div>
        <div class="cs">Log fluid or grease dispensed to a fleet machine; OEM variance computes automatically</div>
      </div>
      <a href="<?= base_url('lubricants') ?>" class="ca">&larr; Back</a>
    </div>
    <div style="padding:22px 26px 28px;">
      <?= form_open('lubricants/record') ?>
      <div class="form-grid cols-2" style="margin-bottom:16px;">
        <div>
          <label class="form-label">Fleet Machine <span class="req">*</span></label>
          <select name="equipment_id" id="equipment-select" class="form-select" required>
            <option value="">Select Equipment…</option>
            <?php foreach ($equipment as $eq): ?>
            <option value="<?= (int)$eq->id ?>">
              <?= $lub_esc($eq->registration_no) ?> — <?= $lub_esc(trim(($eq->brand ?? '') . ' ' . ($eq->model ?? ''))) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Lube / Consumable Type <span class="req">*</span></label>
          <select name="lube_type_id" class="form-select" required>
            <option value="">Select Type…</option>
            <?php
            $current_cat = NULL;
            foreach ($lube_types as $lt):
              if ($lt->category !== $current_cat) {
                if ($current_cat !== NULL) echo '</optgroup>';
                echo '<optgroup label="' . $lub_esc($lt->category) . '">';
                $current_cat = $lt->category;
              }
            ?>
            <option value="<?= (int)$lt->id ?>">
              <?= $lub_esc($lt->name) ?><?= !empty($lt->grade) ? ' (' . $lub_esc($lt->grade) . ')' : '' ?> — <?= $lub_esc($lt->unit) ?>
            </option>
            <?php endforeach; if ($current_cat !== NULL) echo '</optgroup>'; ?>
          </select>
        </div>
      </div>

      <div class="form-grid cols-3" style="margin-bottom:16px;">
        <div>
          <label class="form-label">Quantity Used <span class="req">*</span></label>
          <input type="number" name="quantity_used" class="form-control" step="0.1" min="0.1" required placeholder="e.g. 18.0">
        </div>
        <div>
          <label class="form-label">Equipment SMR Hours</label>
          <input type="number" name="equipment_hours" class="form-control" step="0.1" min="0" placeholder="Hour meter reading">
        </div>
        <div>
          <label class="form-label">Consumption Date <span class="req">*</span></label>
          <input type="date" name="consumption_date" class="form-control" value="<?= $lub_esc(date('Y-m-d')) ?>" required>
        </div>
      </div>

      <div style="margin-bottom:14px;">
        <label class="form-label">Work Order <span style="font-weight:400;color:var(--t3);">(optional — link to open maintenance job)</span></label>
        <select name="work_order_id" id="work-order-select" class="form-select">
          <option value="">— Not linked to a work order —</option>
          <?php if (!empty($open_wos)): foreach ($open_wos as $w): ?>
          <option value="<?= (int)$w->id ?>" data-equipment="<?= (int)$w->equipment_id ?>">
            <?= $lub_esc($w->wo_number) ?> — <?= $lub_esc($w->registration_no) ?> — <?= $lub_esc($w->title) ?>
          </option>
          <?php endforeach; endif; ?>
        </select>
        <div style="font-size:11px;color:var(--t3);margin-top:4px;">Work order options filter automatically to match the selected machine.</div>
      </div>

      <div class="form-grid cols-2" style="margin-bottom:22px;">
        <div>
          <label class="form-label">Variance Reason</label>
          <input type="text" name="variance_reason" class="form-control" placeholder="e.g. Hose burst top-up, flushing service…">
        </div>
        <div>
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" placeholder="Optional store or mechanic notes…">
        </div>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:10px;border-top:1px solid var(--sep);padding-top:16px;flex-wrap:wrap;">
        <a href="<?= base_url('lubricants') ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">+ Save Consumption Issue</button>
      </div>
      <?= form_close() ?>
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
