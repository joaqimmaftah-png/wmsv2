<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Lubricants — Record Consumption.
 *
 * Presentation follows the /team/report enterprise design system through the
 * shared lubricants partial. Field names, validation, and the work-order
 * filter behaviour are unchanged.
 */

$lub_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$can_write = isset($can_write) ? (bool)$can_write : TRUE;

include __DIR__ . '/_ui.php';

$machine_count = is_array($equipment) ? count($equipment) : 0;
$type_count    = is_array($lube_types) ? count($lube_types) : 0;
$wo_count      = !empty($open_wos) && is_array($open_wos) ? count($open_wos) : 0;
?>
<style>
/* Page-specific layout for the recording form. */
.lub-record-split { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 16px; align-items: start; }
.lub-record-card, .lub-guide-card {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-lg, 18px); box-shadow: var(--shadow-card); overflow: hidden;
}
.lub-guide-step {
  display: flex; gap: 9px; padding: 10px 0;
  border-bottom: 1px solid var(--sep);
}
.lub-guide-step:last-child { border-bottom: none; }
.lub-guide-num {
  flex-shrink: 0; width: 20px; height: 20px; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  background: var(--lub-blue-lt); color: var(--blue);
  font-size: 10.5px; font-weight: 700;
}
.lub-guide-text { font-size: 12px; color: var(--t2); line-height: 1.5; }
.lub-guide-text strong { color: var(--t1); font-weight: 600; }

@media (max-width: 1024px) {
  .lub-record-split { grid-template-columns: minmax(0, 1fr); }
}
</style>

<?= $lub_print_banner('Record Lubricant Consumption', 'Form — ' . $machine_count . ' fleet asset(s) available') ?>

<div class="lub-page">

  <?= $lub_nav('ledger') ?>

  <?= $lub_page_head(array(
      'crumb'    => array(array('Lubricants', 'lubricants'), array('Record Consumption', NULL)),
      'title'    => 'Record Lubricant Consumption',
      'pill'     => 'New store issue',
      'subtitle' => 'Log fluid or grease dispensed to a fleet machine. OEM variance is computed on save when a standard exists for that machine class.',
      'actions'  => '<a href="' . base_url('lubricants') . '" class="btn btn-ghost btn-sm">&larr; Back to ledger</a>',
  )) ?>

  <?php if (!$can_write): ?>
    <div class="lub-surface">
      <?= $lub_empty(
          'Recording is available to supervisors and the store',
          'Your role can read the consumption ledger but cannot create new issues.',
          '<a href="' . base_url('lubricants') . '" class="btn btn-secondary">Back to ledger</a>'
      ) ?>
    </div>
  <?php else: ?>

  <div class="lub-record-split">

    <div class="lub-record-card">
      <div class="lub-panel-head">
        <div>
          <div class="lub-panel-title">Issue details</div>
          <p class="lub-panel-sub">Fields marked <span class="req">*</span> are required.</p>
        </div>
      </div>
      <div class="lub-panel-pad">
        <?= form_open('lubricants/record') ?>

        <div class="lub-form-grid cols-2" style="margin-bottom:14px;">
          <div>
            <label class="form-label" for="equipment-select">Fleet Machine <span class="req">*</span></label>
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
            <label class="form-label" for="lube-type-select">Lube / Consumable Type <span class="req">*</span></label>
            <select name="lube_type_id" id="lube-type-select" class="form-select" required>
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

        <div class="lub-form-grid cols-3" style="margin-bottom:14px;">
          <div>
            <label class="form-label" for="recordQty">Quantity Used <span class="req">*</span></label>
            <input type="number" id="recordQty" name="quantity_used" class="form-control" step="0.1" min="0.1" required placeholder="e.g. 18.0">
          </div>
          <div>
            <label class="form-label" for="recordHours">Equipment SMR Hours</label>
            <input type="number" id="recordHours" name="equipment_hours" class="form-control" step="0.1" min="0" placeholder="Hour meter reading">
          </div>
          <div>
            <label class="form-label" for="recordDate">Consumption Date <span class="req">*</span></label>
            <input type="date" id="recordDate" name="consumption_date" class="form-control" value="<?= $lub_esc(date('Y-m-d')) ?>" required>
          </div>
        </div>

        <div style="margin-bottom:14px;">
          <label class="form-label" for="work-order-select">
            Work Order <span style="font-weight:400;color:var(--t3);">(optional — link to an open maintenance job)</span>
          </label>
          <select name="work_order_id" id="work-order-select" class="form-select">
            <option value="">— Not linked to a work order —</option>
            <?php if (!empty($open_wos)): foreach ($open_wos as $w): ?>
            <option value="<?= (int)$w->id ?>" data-equipment="<?= (int)$w->equipment_id ?>">
              <?= $lub_esc($w->wo_number) ?> — <?= $lub_esc($w->registration_no) ?> — <?= $lub_esc($w->title) ?>
            </option>
            <?php endforeach; endif; ?>
          </select>
          <div style="font-size:11px;color:var(--t3);margin-top:5px;">
            Options narrow to the selected machine. <?= (int)$wo_count ?> open job(s) loaded.
          </div>
        </div>

        <div class="lub-form-grid cols-2">
          <div>
            <label class="form-label" for="recordVariance">Variance Reason</label>
            <input type="text" id="recordVariance" name="variance_reason" class="form-control" placeholder="e.g. Hose burst top-up, flushing service…">
          </div>
          <div>
            <label class="form-label" for="recordNotes">Notes</label>
            <input type="text" id="recordNotes" name="notes" class="form-control" placeholder="Optional store or mechanic notes…">
          </div>
        </div>

        <div class="lub-form-actions">
          <span class="lub-form-note">Saving appends the issue to the ledger for its consumption month.</span>
          <a href="<?= base_url('lubricants') ?>" class="btn btn-ghost">Cancel</a>
          <button type="submit" class="btn btn-primary">+ Save Consumption Issue</button>
        </div>
        <?= form_close() ?>
      </div>
    </div>

    <div class="lub-guide-card no-print">
      <div class="lub-panel-head">
        <div>
          <div class="lub-panel-title">How this is recorded</div>
        </div>
      </div>
      <div class="lub-panel-pad">
        <div class="lub-guide-step">
          <span class="lub-guide-num">1</span>
          <div class="lub-guide-text"><strong>OEM variance</strong> is the quantity used minus the standard for that machine class and fluid. Without a standard, the issue is stored without a baseline.</div>
        </div>
        <div class="lub-guide-step">
          <span class="lub-guide-num">2</span>
          <div class="lub-guide-text"><strong>Work orders</strong> are limited to open jobs on the machine you pick, so an issue cannot be booked against another asset.</div>
        </div>
        <div class="lub-guide-step">
          <span class="lub-guide-num">3</span>
          <div class="lub-guide-text"><strong>Line cost</strong> uses the fluid's current unit rate. It is an estimate of value issued, not a purchase cost.</div>
        </div>
        <div class="lub-guide-step">
          <span class="lub-guide-num">4</span>
          <div class="lub-guide-text">The ledger opens on the saved issue's month so you can confirm the entry immediately.</div>
        </div>
      </div>
    </div>

  </div>
  <?php endif; ?>
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
