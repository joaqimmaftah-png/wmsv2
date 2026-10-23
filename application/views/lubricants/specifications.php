<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Lubricants — OEM Fluid Specifications.
 *
 * Presentation follows the /team/report enterprise design system through the
 * shared lubricants partial. The save form, its validation, and the
 * edit-and-fill behaviour are unchanged.
 */

$lub_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$can_write = !empty($can_write);

include __DIR__ . '/_ui.php';

/* ── Presentation-only counts derived from the rows already loaded ──────── */
$spec_total   = count($specs);
$eq_seen      = array();
$lube_seen    = array();
$interval_cnt = 0;
foreach ($specs as $sp) {
    $eq_seen[(int)$sp->equipment_type_id] = TRUE;
    $lube_seen[(int)$sp->lube_type_id] = TRUE;
    if ($sp->service_interval_hours !== NULL) $interval_cnt++;
}
$lube_count = is_array($lube_types) ? count($lube_types) : 0;
$uncovered  = max(0, $lube_count - count($lube_seen));
?>
<style>
/* Page-specific layout for the specification editor. */
.lub-spec-split { display: grid; grid-template-columns: 360px minmax(0, 1fr); gap: 16px; align-items: start; }
.lub-spec-split.is-single { grid-template-columns: minmax(0, 1fr); }
.lub-spec-card {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-lg, 18px); box-shadow: var(--shadow-card); overflow: hidden;
}
.lub-spec-col-type { width: 21%; }
.lub-spec-col-lube { width: 24%; }
.lub-spec-col-qty  { width: 15%; }
.lub-spec-col-int  { width: 12%; }
.lub-spec-col-note { width: 20%; }
.lub-spec-col-act  { width: 100px; }
.lub-spec-row-hidden { display: none !important; }

@media (max-width: 900px) {
  .lub-spec-split, .lub-spec-split.is-single { grid-template-columns: minmax(0, 1fr); }
}
</style>

<?= $lub_print_banner('OEM Lubricant Specifications', 'Standards: <strong>' . (int)$spec_total . '</strong> &middot; Machine classes: <strong>' . count($eq_seen) . '</strong>') ?>

<div class="lub-page">

  <?= $lub_nav('specifications') ?>

  <?= $lub_page_head(array(
      'crumb'    => array(array('Lubricants', 'lubricants'), array('OEM Specifications', NULL)),
      'title'    => 'OEM Fluid Specifications',
      'pill'     => (int)$spec_total . ' standard(s)',
      'subtitle' => 'The OEM standard quantity per machine class and fluid — the baseline every consumption issue is measured against.',
      'actions'  => '',
  )) ?>

  <div class="lub-kpi-grid no-print">
    <?= $lub_kpi('Configured Standards', (int)$spec_total, 'Machine class × fluid pairs') ?>
    <?= $lub_kpi('Machine Classes', count($eq_seen), 'Equipment types with a standard') ?>
    <?= $lub_kpi('Fluids Covered', count($lube_seen), $uncovered > 0 ? $uncovered . ' catalogued grade(s) without a standard' : 'Every catalogued grade is covered', $uncovered > 0 ? 'warn' : 'good') ?>
    <?= $lub_kpi('With Service Interval', (int)$interval_cnt, 'Standards carrying a reference interval') ?>
  </div>

  <div class="lub-spec-split <?= $can_write ? '' : 'is-single' ?>">

    <?php if ($can_write): ?>
    <div class="lub-spec-card no-print">
      <div class="lub-panel-head">
        <div>
          <div class="lub-panel-title">Set or Update a Standard</div>
          <p class="lub-panel-sub">One standard per machine class and fluid. Quantity drives PM estimates; the interval is reference-only here. Recorded issues keep their original snapshot.</p>
        </div>
      </div>
      <div class="lub-panel-pad">
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
        <div class="lub-form-grid cols-2" style="margin-bottom:12px;">
          <div>
            <label class="form-label" for="specQty">Recommended Qty <span class="req">*</span></label>
            <input type="number" id="specQty" name="recommended_qty" class="form-control" step="0.01" min="0" max="99999999.99" required placeholder="e.g. 28">
          </div>
          <div>
            <label class="form-label" for="specInterval">Interval (hrs)</label>
            <input type="number" id="specInterval" name="service_interval_hours" class="form-control" min="0" max="2147483647" step="1" placeholder="e.g. 250">
          </div>
        </div>
        <div style="margin-bottom:12px;">
          <label class="form-label" for="specNotes">Notes</label>
          <input type="text" id="specNotes" name="notes" class="form-control" maxlength="300" placeholder="OEM manual reference or sump capacity note…">
        </div>
        <p id="specSaveHint" role="status" style="font-size:11.5px;color:var(--t2);margin:0 0 4px;">Select a machine class and fluid to add or update its standard.</p>
        <div class="lub-form-actions">
          <button type="submit" class="btn btn-primary" style="width:100%;">Save Specification</button>
        </div>
        <?= form_close() ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="lub-spec-card">
      <div class="lub-panel-head no-print">
        <div>
          <div class="lub-panel-title">Specifications Master</div>
          <p class="lub-panel-sub"><?= (int)$spec_total ?> configured standard(s) across <?= count($eq_seen) ?> machine class(es)</p>
        </div>
        <div class="lub-search-wrap">
          <svg class="lub-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search" id="lubSpecSearch" class="lub-search-input" style="width:210px;"
                 placeholder="Filter standards…" aria-label="Filter specifications">
          <kbd class="lub-kbd" aria-hidden="true">/</kbd>
        </div>
      </div>

      <?php if ($spec_total === 0): ?>
        <?= $lub_empty(
            'No OEM standards configured',
            'Without a standard the ledger cannot measure over- or under-consumption. Set the first machine class and fluid quantity.',
            ''
        ) ?>
      <?php else: ?>
      <div class="lub-table-wrap" style="max-height:calc(100vh - 300px);">
        <table class="lub-table" style="min-width:880px;">
          <thead>
            <tr>
              <th scope="col" class="lub-spec-col-type">Equipment Type</th>
              <th scope="col" class="lub-spec-col-lube">Lubricant</th>
              <th scope="col" class="lub-spec-col-qty lub-num">Recommended Qty</th>
              <th scope="col" class="lub-spec-col-int lub-num">Interval</th>
              <th scope="col" class="lub-spec-col-note">Notes</th>
              <?php if ($can_write): ?><th scope="col" class="lub-spec-col-act" style="text-align:right;">Action</th><?php endif; ?>
            </tr>
          </thead>
          <tbody id="lubSpecBody">
          <?php foreach ($specs as $s):
            $spec_search = strtolower(trim($s->type_name . ' ' . $s->lube_name . ' ' . ($s->lube_grade ?? '') . ' ' . ($s->notes ?? '')));
            $is_active = (($s->lube_status ?? 'Active') === 'Active');
          ?>
            <tr data-spec-row data-search="<?= $lub_esc($spec_search) ?>">
              <td class="lub-spec-col-type">
                <div class="lub-entity">
                  <span class="lub-avatar" aria-hidden="true"><?= $lub_esc(strtoupper(function_exists('mb_substr') ? mb_substr((string)$s->type_name, 0, 2, 'UTF-8') : substr((string)$s->type_name, 0, 2))) ?></span>
                  <div class="lub-entity-info">
                    <span class="lub-entity-name is-plain"><?= $lub_esc($s->type_name) ?></span>
                  </div>
                </div>
              </td>
              <td class="lub-spec-col-lube">
                <div class="lub-cell-strong" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= $lub_esc($s->lube_name) ?></div>
                <div class="lub-cell-sub">
                  <?= !empty($s->lube_grade) ? $lub_esc($s->lube_grade) : 'Standard' ?>
                  <?php if (!$is_active): ?> · <span style="color:var(--t3);">Discontinued</span><?php endif; ?>
                </div>
              </td>
              <td class="lub-spec-col-qty lub-num">
                <span class="lub-val-strong"><?= number_format((float)$s->recommended_qty, 2) ?></span>
                <span class="lub-code"><?= $lub_esc($s->unit) ?></span>
              </td>
              <td class="lub-spec-col-int lub-num">
                <?php if ($s->service_interval_hours !== NULL): ?>
                  <span class="lub-num-txt" style="font-size:12px;color:var(--t1);"><?= (int)$s->service_interval_hours ?></span>
                  <span class="lub-code">hrs</span>
                <?php else: ?>
                  <span class="lub-muted-zero">—</span>
                <?php endif; ?>
              </td>
              <td class="lub-spec-col-note">
                <span class="lub-cell-sub" style="margin:0;" title="<?= $lub_esc($s->notes ?: '') ?>"><?= $lub_esc($s->notes ?: '—') ?></span>
              </td>
              <?php if ($can_write): ?>
              <td class="lub-spec-col-act" style="text-align:right;">
                <?php if ($is_active): ?>
                <div class="lub-row-actions">
                  <button type="button" class="lub-act-btn is-primary" data-spec-edit
                          data-eq="<?= (int)$s->equipment_type_id ?>" data-lube="<?= (int)$s->lube_type_id ?>"
                          data-qty="<?= $lub_esc($s->recommended_qty) ?>"
                          data-interval="<?= $lub_esc($s->service_interval_hours) ?>" data-notes="<?= $lub_esc($s->notes) ?>"
                          aria-label="Edit <?= $lub_esc($s->type_name . ' ' . $s->lube_name) ?> standard">Edit</button>
                </div>
                <?php else: ?>
                <span class="badge b-secondary">Discontinued</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
            <tr id="lubSpecNoMatch" hidden>
              <td colspan="<?= $can_write ? 6 : 5 ?>" style="padding:28px 16px;text-align:center;color:var(--t3);font-size:12.5px;">
                No standard matches that filter.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  // ── Filter the standards list (client-side; the dataset is already loaded) ──
  var search = document.getElementById('lubSpecSearch');
  var rows   = Array.prototype.slice.call(document.querySelectorAll('[data-spec-row]'));
  var none   = document.getElementById('lubSpecNoMatch');

  if (search && rows.length) {
    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      var shown = 0;
      rows.forEach(function (row) {
        var match = q === '' || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
        row.hidden = !match;
        row.classList.toggle('lub-spec-row-hidden', !match);
        if (match) shown++;
      });
      if (none) none.hidden = (shown > 0);
    });
  }
<?php if ($can_write): ?>

  // ── Fill the form from an existing standard ──
  var eq       = document.getElementById('specEquipment');
  var lube     = document.getElementById('specLube');
  var qty      = document.getElementById('specQty');
  var interval = document.getElementById('specInterval');
  var notes    = document.getElementById('specNotes');
  var hint     = document.getElementById('specSaveHint');
  var edits    = Array.prototype.slice.call(document.querySelectorAll('[data-spec-edit]'));
  if (!eq || !lube || !qty || !interval || !notes || !hint) return;

  function updateHint() {
    var existing = edits.some(function (b) { return b.dataset.eq === eq.value && b.dataset.lube === lube.value; });
    hint.textContent = (!eq.value || !lube.value)
      ? 'Select a machine class and fluid to add or update its standard.'
      : (existing ? 'This pair already has a standard. Saving will update it.' : 'This pair has no standard yet. Saving will add it.');
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
<?php endif; ?>

  document.addEventListener('keydown', function (ev) {
    var tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
    var inField = (tag === 'input' || tag === 'textarea' || tag === 'select');
    if (ev.key === '/' && !inField && search) {
      ev.preventDefault();
      search.focus();
    }
  });
})();
</script>
