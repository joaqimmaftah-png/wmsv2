<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Lubricants — Lube Types Master.
 *
 * Presentation follows the /team/report enterprise design system through the
 * shared lubricants partial. The register form and its validation are
 * untouched; this view only arranges what the controller already provides.
 */

$lub_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$can_write        = !empty($can_write);
$can_manage_types = !empty($can_manage_types);
$ccy              = isset($currency_code) && $currency_code !== '' ? (string)$currency_code : 'TZS';
$cats             = isset($categories) && is_array($categories) ? $categories : Lubricant_model::$CATEGORIES;

include __DIR__ . '/_ui.php';

/* ── Presentation-only counts derived from the rows already loaded ──────── */
$grade_count   = count($lube_types);
$cat_seen      = array();
$unit_seen     = array();
$priced_count  = 0;
$rate_total    = 0.0;
$rate_count    = 0;
foreach ($lube_types as $lt_row) {
    $cat_seen[(string)$lt_row->category] = TRUE;
    $unit_seen[(string)$lt_row->unit] = TRUE;
    if ((float)($lt_row->unit_cost ?? 0) > 0) {
        $priced_count++;
        $rate_total += (float)$lt_row->unit_cost;
        $rate_count++;
    }
}
$avg_rate = $rate_count > 0 ? ($rate_total / $rate_count) : 0.0;
?>
<style>
/* Page-specific layout for the master catalogue. */
.lub-types-split { display: grid; grid-template-columns: 340px minmax(0, 1fr); gap: 16px; align-items: start; }
.lub-types-split.is-single { grid-template-columns: minmax(0, 1fr); }
.lub-types-card {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-lg, 18px); box-shadow: var(--shadow-card); overflow: hidden;
}
.lub-type-col-name { width: 26%; }
.lub-type-col-grade { width: 13%; }
.lub-type-col-cat { width: 16%; }
.lub-type-col-unit { width: 8%; }
.lub-type-col-cost { width: 14%; }
.lub-type-col-desc { width: 23%; }
.lub-type-row-hidden { display: none !important; }

@media (max-width: 900px) {
  .lub-types-split, .lub-types-split.is-single { grid-template-columns: minmax(0, 1fr); }
}
</style>

<?= $lub_print_banner('Lube Types Master', 'Grades: <strong>' . (int)$grade_count . '</strong> &middot; Categories: <strong>' . count($cat_seen) . '</strong>') ?>

<div class="lub-page">

  <?= $lub_nav('types') ?>

  <?= $lub_page_head(array(
      'crumb'    => array(array('Lubricants', 'lubricants'), array('Lube Types', NULL)),
      'title'    => 'Lube Types Master',
      'pill'     => (int)$grade_count . ' active grade(s)',
      'subtitle' => 'The fluid and grease catalogue used by consumption entries, OEM standards, and monthly budgets.',
      'actions'  => '',
  )) ?>

  <div class="lub-kpi-grid no-print">
    <?= $lub_kpi('Registered Grades', (int)$grade_count, 'Active in the catalogue') ?>
    <?= $lub_kpi('Fluid Categories', count($cat_seen), 'Distinct categories in use') ?>
    <?= $lub_kpi('Priced Grades', (int)$priced_count, (int)($grade_count - $priced_count) . ' without a default rate', $priced_count === $grade_count ? 'good' : 'warn') ?>
    <?= $lub_kpi('Average Default Rate', $lub_esc($ccy) . ' ' . number_format($avg_rate, 0), 'Across ' . (int)$rate_count . ' priced grade(s)') ?>
  </div>

  <div class="lub-types-split <?= $can_manage_types ? '' : 'is-single' ?>">

    <?php if ($can_manage_types): ?>
    <div class="lub-types-card no-print">
      <div class="lub-panel-head">
        <div>
          <div class="lub-panel-title">Add Lubricant Type</div>
          <p class="lub-panel-sub">Register a new workshop oil, coolant, or grease grade.</p>
        </div>
      </div>
      <div class="lub-panel-pad">
        <?= form_open('lubricants/types') ?>
        <div class="lub-form-grid" style="margin-bottom:12px;">
          <div>
            <label class="form-label" for="lubeName">Name <span class="req">*</span></label>
            <input type="text" id="lubeName" name="name" class="form-control" maxlength="100" required placeholder="e.g. Heavy Duty Engine Oil">
          </div>
          <div>
            <label class="form-label" for="lubeCategory">Category <span class="req">*</span></label>
            <select id="lubeCategory" name="category" class="form-select" required>
              <?php foreach ($cats as $cat): ?>
              <option value="<?= $lub_esc($cat) ?>"><?= $lub_esc($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="lub-form-grid cols-2" style="margin-bottom:12px;">
          <div>
            <label class="form-label" for="lubeGrade">Viscosity / Grade</label>
            <input type="text" id="lubeGrade" name="grade" class="form-control" maxlength="50" placeholder="e.g. 15W-40, ISO 46">
          </div>
          <div>
            <label class="form-label" for="lubeUnit">Unit</label>
            <select id="lubeUnit" name="unit" class="form-select">
              <option value="L">L</option>
              <option value="Kg">Kg</option>
              <option value="Pcs">Pcs</option>
            </select>
          </div>
        </div>
        <div style="margin-bottom:12px;">
          <label class="form-label" for="lubeCost">Default Unit Cost (<?= $lub_esc($ccy) ?>)</label>
          <input type="number" id="lubeCost" name="unit_cost" class="form-control" step="0.01" min="0" max="99999999.99" placeholder="0.00">
        </div>
        <div style="margin-bottom:4px;">
          <label class="form-label" for="lubeDescription">Description</label>
          <input type="text" id="lubeDescription" name="description" class="form-control" maxlength="300" placeholder="Optional specification note…">
        </div>
        <div class="lub-form-actions">
          <button type="submit" class="btn btn-primary" style="width:100%;">+ Add Lube Type</button>
        </div>
        <?= form_close() ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="lub-types-card">
      <div class="lub-panel-head no-print">
        <div>
          <div class="lub-panel-title">Master Catalogue</div>
          <p class="lub-panel-sub"><?= (int)$grade_count ?> active fluid grade(s) · rates in <?= $lub_esc($ccy) ?></p>
        </div>
        <div class="lub-search-wrap">
          <svg class="lub-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search" id="lubTypeSearch" class="lub-search-input" style="width:210px;"
                 placeholder="Filter grades…" aria-label="Filter lubricant types">
          <kbd class="lub-kbd" aria-hidden="true">/</kbd>
        </div>
      </div>

      <?php if ($grade_count === 0): ?>
        <?= $lub_empty(
            'No lubricant types registered',
            'The catalogue is empty. Add the first engine oil, hydraulic oil, or grease grade to start recording consumption.',
            $can_manage_types ? '' : 'Ask a supervisor to add a fluid grade.'
        ) ?>
      <?php else: ?>
      <div class="lub-table-wrap" style="max-height:calc(100vh - 300px);">
        <table class="lub-table" style="min-width:900px;">
          <thead>
            <tr>
              <th scope="col" class="lub-type-col-name">Lubricant</th>
              <th scope="col" class="lub-type-col-grade">Grade</th>
              <th scope="col" class="lub-type-col-cat">Category</th>
              <th scope="col" class="lub-type-col-unit" style="text-align:center;">Unit</th>
              <th scope="col" class="lub-type-col-cost lub-num">Unit Cost (<?= $lub_esc($ccy) ?>)</th>
              <th scope="col" class="lub-type-col-desc">Description</th>
            </tr>
          </thead>
          <tbody id="lubTypeBody">
            <?php foreach ($lube_types as $lt):
              $lt_cost = (float)($lt->unit_cost ?? 0);
              $search_blob = strtolower(trim($lt->name . ' ' . ($lt->grade ?? '') . ' ' . $lt->category . ' ' . ($lt->description ?? '')));
            ?>
            <tr data-lube-row data-search="<?= $lub_esc($search_blob) ?>">
              <td class="lub-type-col-name">
                <div class="lub-entity">
                  <span class="lub-avatar" aria-hidden="true"><?= $lub_esc(strtoupper(function_exists('mb_substr') ? mb_substr((string)$lt->name, 0, 2, 'UTF-8') : substr((string)$lt->name, 0, 2))) ?></span>
                  <div class="lub-entity-info">
                    <span class="lub-entity-name is-plain"><?= $lub_esc($lt->name) ?></span>
                    <div class="lub-entity-meta"><span class="lub-code">#<?= (int)$lt->id ?></span></div>
                  </div>
                </div>
              </td>
              <td class="lub-type-col-grade">
                <span class="lub-mono" style="font-size:11.5px;color:var(--t2);"><?= $lub_esc($lt->grade ?: '—') ?></span>
              </td>
              <td class="lub-type-col-cat">
                <?= $lub_badge($lt->category, 'primary') ?>
              </td>
              <td class="lub-type-col-unit lub-num" style="text-align:center;">
                <span class="badge b-secondary"><?= $lub_esc($lt->unit) ?></span>
              </td>
              <td class="lub-type-col-cost lub-num">
                <?php if ($lt_cost > 0): ?>
                  <span class="lub-num-txt" style="font-weight:700;color:var(--t1);"><?= number_format($lt_cost, 2) ?></span>
                <?php else: ?>
                  <span class="lub-note-pill">No rate</span>
                <?php endif; ?>
              </td>
              <td class="lub-type-col-desc">
                <span class="lub-cell-sub" style="margin:0;" title="<?= $lub_esc($lt->description ?: '') ?>"><?= $lub_esc($lt->description ?: '—') ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
            <tr id="lubTypeNoMatch" hidden>
              <td colspan="6" style="padding:28px 16px;text-align:center;color:var(--t3);font-size:12.5px;">
                No grade matches that filter.
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
  var input = document.getElementById('lubTypeSearch');
  var rows  = Array.prototype.slice.call(document.querySelectorAll('[data-lube-row]'));
  var none  = document.getElementById('lubTypeNoMatch');
  if (!input || !rows.length) return;

  function applyFilter() {
    var q = input.value.trim().toLowerCase();
    var shown = 0;
    rows.forEach(function (row) {
      var hay = row.getAttribute('data-search') || '';
      var match = q === '' || hay.indexOf(q) !== -1;
      row.hidden = !match;
      row.classList.toggle('lub-type-row-hidden', !match);
      if (match) shown++;
    });
    if (none) none.hidden = (shown > 0);
  }

  input.addEventListener('input', applyFilter);

  document.addEventListener('keydown', function (ev) {
    var tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
    var inField = (tag === 'input' || tag === 'textarea' || tag === 'select');
    if (ev.key === '/' && !inField) {
      ev.preventDefault();
      input.focus();
    }
  });
})();
</script>
