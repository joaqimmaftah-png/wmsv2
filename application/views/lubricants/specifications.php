<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$lub_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$can_write = !empty($can_write);
?>
<style>
.lub-subnav {
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
  margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--sep, rgba(148,163,184,0.16));
}
.lub-subnav a {
  padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;
  text-decoration: none; color: var(--t2); background: var(--surface);
  border: 1px solid var(--sep, rgba(148,163,184,0.16));
}
.lub-subnav a.is-active { background: var(--blue); color: #ffffff; border-color: var(--blue); }
.lub-spec-split { display: grid; grid-template-columns: 360px minmax(0, 1fr); gap: 16px; align-items: start; }
.lub-spec-table { min-width: 700px; }
.lub-spec-split :focus-visible, .lub-subnav :focus-visible { outline: 2px solid var(--blue); outline-offset: 2px; }
@media (max-width: 900px) {
  .lub-spec-split { grid-template-columns: minmax(0, 1fr); }
}
</style>

<nav class="lub-subnav" aria-label="Lubricants navigation">
  <a href="<?= base_url('lubricants') ?>">Consumption Ledger</a>
  <?php if ($can_write): ?>
  <a href="<?= base_url('lubricants/budget') ?>">Lube Budget</a>
  <?php endif; ?>
  <a href="<?= base_url('lubricants/specifications') ?>" class="is-active">OEM Specifications</a>
  <a href="<?= base_url('lubricants/types') ?>">Lube Types</a>
  <a href="<?= base_url('lubricants/forecast') ?>">PM Forecast</a>
</nav>

<div class="lub-spec-split" style="<?= $can_write ? '' : 'grid-template-columns:minmax(0,1fr);' ?>">
  <?php if ($can_write): ?>
  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Set or Update OEM Fluid Standard</div>
        <div class="cs">One standard per equipment type and lubricant. Quantity affects future PM estimates; interval is reference-only here. Recorded issues retain their original snapshot.</div>
      </div>
    </div>
    <div style="padding:18px 20px 22px;">
      <?= form_open('lubricants/specifications') ?>
      <div style="margin-bottom:12px;">
        <label class="form-label" for="specEquipment">Equipment Type <span class="req">*</span></label>
        <select id="specEquipment" name="equipment_type_id" class="form-select" required>
          <option value="">Select Equipment Type…</option>
          <?php foreach ($eq_types as $et): ?>
          <option value="<?= (int)$et->id ?>"><?= $lub_esc($et->name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-bottom:12px;">
        <label class="form-label" for="specLube">Lube / Consumable <span class="req">*</span></label>
        <select id="specLube" name="lube_type_id" class="form-select" required>
          <option value="">Select Lubricant…</option>
          <?php foreach ($lube_types as $lt): ?>
          <option value="<?= (int)$lt->id ?>"><?= $lub_esc($lt->name) ?> (<?= $lub_esc($lt->unit) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid cols-2" style="margin-bottom:12px;">
        <div>
          <label class="form-label" for="specQty">Recommended Qty <span class="req">*</span></label>
          <input type="number" id="specQty" name="recommended_qty" class="form-control" step="0.01" min="0" max="99999999.99" required placeholder="e.g. 28">
        </div>
        <div>
          <label class="form-label" for="specInterval">Interval (hrs)</label>
          <input type="number" id="specInterval" name="service_interval_hours" class="form-control" min="0" max="2147483647" step="1" placeholder="e.g. 250">
        </div>
      </div>
      <div style="margin-bottom:16px;">
        <label class="form-label" for="specNotes">Notes</label>
        <input type="text" id="specNotes" name="notes" class="form-control" maxlength="300" placeholder="OEM manual reference or sump capacity note…">
      </div>
      <p id="specSaveHint" role="status" style="font-size:12px;color:var(--t2);margin:0 0 12px;">Select a machine class and fluid to add or update its standard.</p>
      <button type="submit" class="btn btn-primary" style="width:100%;">Save Specification</button>
      <?= form_close() ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">OEM Lubricant Specifications Master</div>
        <div class="cs"><?= count($specs) ?> configured standard(s)</div>
      </div>
    </div>
    <div style="overflow-x:auto;">
      <table class="tbl lub-spec-table">
        <thead>
          <tr>
            <th>Equipment Type</th>
            <th>Lubricant</th>
            <th style="text-align:right;">Recommended Qty</th>
            <th style="text-align:center;">Interval</th>
            <th>Notes</th>
            <?php if ($can_write): ?><th style="text-align:right;">Action</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($specs)): ?>
          <tr><td colspan="<?= $can_write ? 6 : 5 ?>" style="text-align:center;padding:36px;color:var(--t3);">No specifications defined yet.</td></tr>
          <?php else: foreach ($specs as $s): ?>
          <tr>
            <td style="font-weight:700;color:var(--t1);"><?= $lub_esc($s->type_name) ?></td>
            <td><?= $lub_esc($s->lube_name) ?><?= !empty($s->lube_grade) ? ' <span style="color:var(--t3);">(' . $lub_esc($s->lube_grade) . ')</span>' : '' ?></td>
            <td class="mono" style="text-align:right;font-weight:700;color:var(--t1);"><?= number_format((float)$s->recommended_qty, 2) ?> <?= $lub_esc($s->unit) ?></td>
            <td class="mono" style="text-align:center;color:var(--t2);"><?= $s->service_interval_hours !== NULL ? ((int)$s->service_interval_hours . ' hrs') : '—' ?></td>
            <td style="font-size:12px;color:var(--t2);" title="<?= $lub_esc($s->notes ?: '') ?>"><?= $lub_esc($s->notes ?: '—') ?></td>
            <?php if ($can_write): ?>
            <td style="text-align:right;">
            <?php if (($s->lube_status ?? 'Active') === 'Active'): ?>
            <button type="button" class="btn btn-ghost btn-sm" data-spec-edit
                 data-eq="<?= (int)$s->equipment_type_id ?>" data-lube="<?= (int)$s->lube_type_id ?>"
                 data-qty="<?= $lub_esc($s->recommended_qty) ?>"
                 data-interval="<?= $lub_esc($s->service_interval_hours) ?>" data-notes="<?= $lub_esc($s->notes) ?>"
                 aria-label="Edit <?= $lub_esc($s->type_name . ' ' . $s->lube_name) ?> standard">Edit</button>
            <?php else: ?><span style="color:var(--t2);font-size:12px;">Discontinued fluid</span><?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($can_write): ?>
<script>
(function () {
  var eq = document.getElementById('specEquipment');
  var lube = document.getElementById('specLube');
  var qty = document.getElementById('specQty');
  var interval = document.getElementById('specInterval');
  var notes = document.getElementById('specNotes');
  var hint = document.getElementById('specSaveHint');
  var edits = Array.prototype.slice.call(document.querySelectorAll('[data-spec-edit]'));
  function updateHint() {
    var existing = edits.some(function (b) { return b.dataset.eq === eq.value && b.dataset.lube === lube.value; });
    hint.textContent = !eq.value || !lube.value ? 'Select a machine class and fluid to add or update its standard.'
      : existing ? 'This pair already has a standard. Saving will update it.' : 'This pair has no standard yet. Saving will add it.';
  }
  eq.addEventListener('change', updateHint);
  lube.addEventListener('change', updateHint);
  edits.forEach(function (b) {
    b.addEventListener('click', function () {
      eq.value = b.dataset.eq;
      lube.value = b.dataset.lube;
      qty.value = b.dataset.qty;
      interval.value = b.dataset.interval;
      notes.value = b.dataset.notes;
      updateHint();
      qty.focus();
    });
  });
})();
</script>
<?php endif; ?>
