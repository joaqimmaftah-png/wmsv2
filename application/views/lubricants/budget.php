<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Lubricants — Monthly Budget.
 *
 * Presentation follows the /team/report enterprise design system through the
 * shared lubricants partial. The live budget calculator is unchanged in
 * behaviour: same input names, same element ids, same arithmetic.
 */

$lub_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$year_i     = max(2000, min(2100, (int)($year ?? date('Y'))));
$month_i    = max(1, min(12, (int)($month ?? date('m'))));
$month_name = date('F', mktime(0, 0, 0, $month_i, 1, $year_i)) . ' ' . $year_i;
$ccy        = isset($currency_code) && $currency_code !== '' ? (string)$currency_code : 'TZS';
$can_write  = isset($can_write) ? (bool)$can_write : TRUE;

if (!isset($lines) || !is_array($lines)) {
    $lines = array();
    foreach (($lube_types ?? array()) as $lt) {
        $lid = (int)$lt->id;
        $b   = isset($budget_data[$lid]) ? $budget_data[$lid] : NULL;
        $lines[] = (object)array(
            'id'              => $lid,
            'name'            => (string)$lt->name,
            'grade'           => (string)($lt->grade ?? ''),
            'category'        => (string)$lt->category,
            'unit'            => (string)$lt->unit,
            'budget_qty'      => $b ? (float)$b->budget_qty : NULL,
            'unit_cost'       => $b ? (float)$b->unit_cost : (float)($lt->unit_cost ?? 0),
            'budget_cost'     => $b ? (float)$b->budget_cost : 0.0,
            'pm_req_qty'      => 0.0,
            'pm_services'     => 0,
            'actual_qty'      => 0.0,
            'actual_cost'     => 0.0,
            'remaining_cost'  => 0.0,
            'utilization_pct' => NULL,
        );
    }
}
$totals = isset($totals) && is_array($totals) ? $totals : array(
    'budget_qty' => 0.0, 'budget_cost' => 0.0, 'pm_req_qty' => 0.0,
    'actual_qty' => 0.0, 'actual_cost' => 0.0, 'remaining_cost' => 0.0, 'utilization_pct' => NULL,
);
$allocated_grades = 0;
$pm_grades = 0;
$issue_records = 0;
foreach ($lines as $line) {
    if ((float)$line->budget_qty > 0) $allocated_grades++;
    if ((float)$line->pm_req_qty > 0) $pm_grades++;
    $issue_records += (int)($line->actual_records ?? 0);
}
$exportQs = '?year=' . $year_i . '&month=' . $month_i;

include __DIR__ . '/_ui.php';

$print_meta = 'Period: <strong>' . $lub_esc($month_name) . '</strong>'
    . ' &middot; Grades: <strong>' . count($lines) . '</strong>'
    . ' &middot; Planned: <strong>' . $lub_esc($ccy . ' ' . number_format((float)$totals['budget_cost'], 2)) . '</strong>';

$header_actions = '<a href="' . $lub_esc(base_url('lubricants/budget_csv' . $exportQs)) . '" class="btn btn-ghost btn-sm">Export CSV</a>'
    . '<a href="' . $lub_esc(base_url('lubricants/budget_xlsx' . $exportQs)) . '" class="btn btn-ghost btn-sm">Export Excel</a>';
?>
<style>
/* Page-specific layout for the budget matrix. */
.bgt-kpi {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-md, 14px); box-shadow: var(--shadow-card);
  padding: 12px 14px; min-width: 0;
}
.bgt-col-name  { width: 20%; }
.bgt-col-cat   { width: 12%; }
.bgt-col-pm    { width: 10%; }
.bgt-col-qty   { width: 13%; }
.bgt-col-rate  { width: 13%; }
.bgt-col-total { width: 13%; }
.bgt-col-util  { width: 10%; }
.bgt-col-issue { width: 11%; }
.bgt-input { width: 100%; max-width: 118px; }

@media (max-width: 900px) {
  /* Stack each allocation line into a labelled card. */
  .bgt-table, .bgt-table tbody, .bgt-table tfoot { display: block; width: 100%; min-width: 0; }
  .bgt-table thead { display: none; }
  .bgt-table tbody tr {
    display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px;
    padding: 12px; margin-bottom: 10px; border-radius: 10px;
    border: 1px solid var(--sep); background: var(--surface);
  }
  .bgt-table tbody td { padding: 4px 0; border-bottom: 0; overflow: visible; display: block; }
  .bgt-table tbody td::before {
    content: attr(data-label); display: block; font-size: 10px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.03em; color: var(--t3); margin-bottom: 2px;
  }
  .bgt-table tbody tr:hover { background: var(--surface); }
  .bgt-table tfoot tr { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 12px; }
  .bgt-input { max-width: 100%; }
  .bgt-table tfoot td { display: block; padding: 0; }
  .bgt-table tfoot td:first-child { text-align: left !important; }
}
</style>

<?= $lub_print_banner('Lubricant Budget — ' . $month_name, $print_meta) ?>

<div id="lubBudgetShell" class="lub-page">

  <?= $lub_nav('budget') ?>

  <?php
  $period_form = '<form method="get" action="' . base_url('lubricants/budget') . '" style="display:contents;">'
      . '<div class="lub-field"><label for="bgtYear">Year</label>'
      . '<input type="number" id="bgtYear" name="year" class="lub-input" style="max-width:100px;" min="2000" max="2100" step="1"'
      . ' value="' . (int)$year_i . '" aria-label="Budget year" required'
      . ' onchange="this.form.submit()"></div>'
      . '<div class="lub-field"><label for="bgtMonth">Month</label>'
      . '<select id="bgtMonth" name="month" class="lub-select" style="max-width:150px;" aria-label="Budget month" onchange="this.form.submit()">';
  for ($m = 1; $m <= 12; $m++) {
      $period_form .= '<option value="' . (int)$m . '"' . ($month_i === $m ? ' selected' : '') . '>'
          . $lub_esc(date('F', mktime(0, 0, 0, $m, 1))) . '</option>';
  }
  $period_form .= '</select></div>'
      . '<div class="lub-scope-actions"><button type="submit" class="btn btn-ghost btn-sm">Load period</button></div>'
      . '</form>';
  ?>

  <?= $lub_page_head(array(
      'crumb'    => array(array('Lubricants', 'lubricants'), array('Lube Budget', NULL)),
      'title'    => 'Lubricant Budget',
      'pill'     => $month_name,
      'subtitle' => 'Monthly allocations measured against PM demand and the issues actually recorded from the store.',
      'actions'  => $header_actions,
  )) ?>

  <!-- ── KPI strip ── -->
  <div class="lub-kpi-grid no-print">
    <div class="bgt-kpi">
      <div class="lub-kpi-k">Planned Budget</div>
      <div class="lub-kpi-v" id="kpiPlannedTotal"><?= $lub_esc($ccy) ?> <?= number_format((float)$totals['budget_cost'], 2) ?></div>
      <div class="lub-kpi-s"><?= (int)$allocated_grades ?> fluid grade(s) allocated</div>
    </div>
    <div class="bgt-kpi">
      <div class="lub-kpi-k">Estimated Issue Value</div>
      <div class="lub-kpi-v" id="budgetIssueValue"><?= $lub_esc($ccy) ?> <?= number_format((float)$totals['actual_cost'], 2) ?></div>
      <div class="lub-kpi-s"><?= (int)$issue_records ?> issue(s) recorded this month</div>
    </div>
    <div class="bgt-kpi">
      <div class="lub-kpi-k">Estimated Balance</div>
      <div class="lub-kpi-v" id="budgetRemaining" style="color:<?= (float)$totals['remaining_cost'] < 0 ? 'var(--red)' : 'var(--green)' ?>;">
        <?= $lub_esc($ccy) ?> <?= number_format((float)$totals['remaining_cost'], 2) ?>
      </div>
      <div class="lub-kpi-s" id="budgetUtilization">
        <?= $totals['utilization_pct'] !== NULL ? ($totals['utilization_pct'] . '% utilized') : 'Set budget quantities below' ?>
      </div>
    </div>
    <div class="bgt-kpi">
      <div class="lub-kpi-k">PM Plan Coverage</div>
      <div class="lub-kpi-v"><?= (int)$pm_grades ?></div>
      <div class="lub-kpi-s">Fluid grade(s) with PM demand</div>
    </div>
  </div>

  <div class="lub-surface">

    <!-- Scope: budget period -->
    <div class="lub-scope-bar no-print">
      <?= $period_form ?>
    </div>

    <!-- Panel heading -->
    <div class="lub-panel-head no-print">
      <div>
        <div class="lub-panel-title">Monthly Fluid Allocation Matrix (<?= $lub_esc($ccy) ?>)</div>
        <p class="lub-panel-sub">
          Enter monthly quantities and unit rates — totals recalculate as you type. PM demand includes dated and
          projected hour-based schedules (20 running hours/day). Issue value uses this month's budget rate, or the
          current fluid rate when unset — never historical purchase cost.
        </p>
      </div>
      <?php if ($can_write && !empty($lines)): ?>
      <button type="button" id="bgtFillPmBtn" class="btn btn-ghost btn-sm">Copy PM Demand to Empty Rows</button>
      <?php endif; ?>
    </div>

    <?php if (empty($lines)): ?>
      <div class="lub-empty">
        <div class="lub-empty-ico" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </div>
        <h3>No lubricant types available</h3>
        <p>Add a lubricant type before creating a budget for <?= $lub_esc($month_name) ?>.</p>
        <a href="<?= base_url('lubricants/types') ?>" class="btn btn-secondary">Go to Lube Types</a>
      </div>
    <?php else: ?>

      <?= form_open('lubricants/budget') ?>
      <input type="hidden" name="year" value="<?= (int)$year_i ?>">
      <input type="hidden" name="month" value="<?= (int)$month_i ?>">

      <div class="lub-table-wrap">
        <table class="lub-table bgt-table" style="min-width:1020px;">
          <thead>
            <tr>
              <th scope="col" class="bgt-col-name">Lubricant &amp; Grade</th>
              <th scope="col" class="bgt-col-cat">Category</th>
              <th scope="col" class="bgt-col-pm lub-num">PM Demand</th>
              <th scope="col" class="bgt-col-qty">Budget Qty</th>
              <th scope="col" class="bgt-col-rate">Unit Cost (<?= $lub_esc($ccy) ?>)</th>
              <th scope="col" class="bgt-col-total lub-num">Budget Cost (<?= $lub_esc($ccy) ?>)</th>
              <th scope="col" class="bgt-col-util lub-num">Utilisation</th>
              <th scope="col" class="bgt-col-issue lub-num">Period Issues</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($lines as $ln):
            $lid    = (int)$ln->id;
            $qtyVal = $ln->budget_qty !== NULL ? rtrim(rtrim(number_format((float)$ln->budget_qty, 2, '.', ''), '0'), '.') : '';
            $cstVal = number_format((float)$ln->unit_cost, 2, '.', '');
            $utilPct = isset($ln->utilization_pct) ? $ln->utilization_pct : NULL;
            $utilTone = ($utilPct === NULL) ? 'good' : ((float)$utilPct > 100 ? 'bad' : ((float)$utilPct >= 75 ? 'warn' : 'good'));
          ?>
            <tr>
              <td class="bgt-col-name" data-label="Lubricant">
                <div class="lub-entity">
                  <span class="lub-avatar" aria-hidden="true"><?= $lub_esc(strtoupper(function_exists('mb_substr') ? mb_substr((string)$ln->name, 0, 2, 'UTF-8') : substr((string)$ln->name, 0, 2))) ?></span>
                  <div class="lub-entity-info">
                    <span class="lub-entity-name is-plain"><?= $lub_esc($ln->name) ?></span>
                    <div class="lub-entity-meta">
                      <span><?= $lub_esc($ln->grade ?: 'Standard') ?></span>
                      <span aria-hidden="true">·</span>
                      <span class="lub-code"><?= $lub_esc($ln->unit) ?></span>
                    </div>
                  </div>
                </div>
              </td>
              <td class="bgt-col-cat" data-label="Category">
                <span style="font-size:11.5px;color:var(--t2);"><?= $lub_esc($ln->category) ?></span>
              </td>
              <td class="bgt-col-pm lub-num" data-label="PM Demand">
                <span class="lub-num-txt" style="font-size:12px;color:var(--t2);"><?= number_format((float)$ln->pm_req_qty, 2) ?></span>
                <span class="lub-code"><?= $lub_esc($ln->unit) ?></span>
              </td>
              <td class="bgt-col-qty" data-label="Budget Qty (<?= $lub_esc($ln->unit) ?>)">
                <input type="number" name="budget[<?= $lid ?>][qty]"
                       class="form-control lub-input bgt-input budget-qty"
                       <?= !$can_write ? 'readonly' : '' ?>
                       step="0.01" min="0"
                       data-id="<?= $lid ?>"
                       data-pm="<?= number_format((float)$ln->pm_req_qty, 2, '.', '') ?>"
                       value="<?= $lub_esc($qtyVal) ?>"
                       aria-label="Budget quantity for <?= $lub_esc($ln->name) ?>">
              </td>
              <td class="bgt-col-rate" data-label="Unit Cost (<?= $lub_esc($ccy) ?>)">
                <input type="number" name="budget[<?= $lid ?>][cost]"
                       class="form-control lub-input bgt-input budget-cost"
                       <?= !$can_write ? 'readonly' : '' ?>
                       step="0.01" min="0"
                       data-id="<?= $lid ?>"
                       data-actual-qty="<?= number_format((float)$ln->actual_qty, 2, '.', '') ?>"
                       data-default-rate="<?= number_format((float)($ln->default_cost ?? 0), 2, '.', '') ?>"
                       value="<?= $lub_esc($cstVal) ?>"
                       aria-label="Unit cost for <?= $lub_esc($ln->name) ?>">
              </td>
              <td class="bgt-col-total lub-num" data-label="Budget Cost">
                <span class="lub-num-txt" id="total-<?= $lid ?>" style="font-weight:700;color:var(--t1);">
                  <?= !empty($ln->has_saved_row) ? ($lub_esc($ccy) . ' ' . number_format((float)$ln->budget_cost, 2)) : '—' ?>
                </span>
              </td>
              <td class="bgt-col-util lub-num" data-label="Utilisation">
                <span id="util-<?= $lid ?>">
                  <?php if ($utilPct === NULL): ?>
                    <span class="lub-note-pill">—</span>
                  <?php else: ?>
                    <?= $lub_bar($utilPct, $utilTone, number_format((float)$utilPct, 0) . '%', 34) ?>
                  <?php endif; ?>
                </span>
              </td>
              <td class="bgt-col-issue lub-num" data-label="Period Issues">
                <span class="lub-num-txt" style="font-size:12px;color:var(--t2);"><?= number_format((float)$ln->actual_qty, 2) ?></span>
                <span class="lub-code"><?= $lub_esc($ln->unit) ?></span>
                <div class="lub-cell-sub" id="issue-value-<?= $lid ?>" style="font-size:10.5px;">
                  <?= $lub_esc($ccy) ?> <?= number_format((float)$ln->actual_cost, 2) ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="5" style="text-align:right;font-weight:600;color:var(--t2);font-size:11.5px;text-transform:uppercase;letter-spacing:0.03em;">
                Total monthly budget
              </td>
              <td class="lub-num" style="text-align:right;">
                <span class="lub-num-txt" id="grandTotal" style="font-weight:800;font-size:14px;color:var(--blue);">
                  <?= $lub_esc($ccy) ?> <?= number_format((float)$totals['budget_cost'], 2) ?>
                </span>
              </td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <?php if ($can_write && !empty($lines)): ?>
      <div class="lub-form-actions no-print" style="margin:0;padding:14px 16px;border-top:1px solid var(--sep);">
        <span class="lub-form-note">Totals above are unsaved until you store this month's allocation.</span>
        <button type="submit" class="btn btn-primary" id="saveBudgetBtn">Save Monthly Budget</button>
      </div>
      <?php endif; ?>
      <?= form_close() ?>

    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var ccy = <?= json_encode($ccy) ?>;
  var qtyInputs  = Array.prototype.slice.call(document.querySelectorAll('.budget-qty'));
  var costInputs = Array.prototype.slice.call(document.querySelectorAll('.budget-cost'));
  var grandEl    = document.getElementById('grandTotal');
  var remainingEl = document.getElementById('budgetRemaining');
  var utilizationEl = document.getElementById('budgetUtilization');
  var issueValueEl = document.getElementById('budgetIssueValue');
  var kpiEl      = document.getElementById('kpiPlannedTotal');
  var fillBtn    = document.getElementById('bgtFillPmBtn');

  function fmt(num) {
    return ccy + ' ' + Number(num || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function recalc() {
    var grand = 0;
    var actualCost = 0;
    qtyInputs.forEach(function (qEl) {
      var id = qEl.getAttribute('data-id');
      var cEl = document.querySelector('.budget-cost[data-id="' + id + '"]');
      var qv = parseFloat(qEl.value) || 0;
      var cv = cEl ? (parseFloat(cEl.value) || 0) : 0;
      var line = qv * cv;
      grand += Math.round(line * 100) / 100;
      // The persisted month rate determines the value of this month's already-issued
      // quantity. A rate edit changes that estimate too; it is never booked spend.
      var issueQty = cEl ? (parseFloat(cEl.getAttribute('data-actual-qty')) || 0) : 0;
      var defaultRate = cEl ? (parseFloat(cEl.getAttribute('data-default-rate')) || 0) : 0;
      var issueCost = Math.round(issueQty * (cv > 0 ? cv : defaultRate) * 100) / 100;
      actualCost += issueCost;
      var issueEl = document.getElementById('issue-value-' + id);
      if (issueEl) issueEl.textContent = fmt(issueCost);
      var tEl = document.getElementById('total-' + id);
      if (tEl) tEl.textContent = fmt(Math.round(line * 100) / 100);
      // Utilisation is only meaningful once a budget exists for the line.
      var utilEl = document.getElementById('util-' + id);
      if (utilEl) {
        if (line > 0) {
          var pct = Math.round(issueCost / line * 100);
          utilEl.innerHTML = '<span class="lub-rate-wrap lub-rate-' + (pct > 100 ? 'bad' : (pct >= 75 ? 'warn' : 'good')) + '">'
            + '<span class="lub-rate-pct">' + pct + '%</span>'
            + '<span class="lub-rate-bar" style="width:34px" aria-hidden="true">'
            + '<span class="lub-rate-fill" style="width:' + Math.max(4, Math.min(100, pct)) + '%"></span></span></span>';
        } else {
          utilEl.innerHTML = '<span class="lub-note-pill">—</span>';
        }
      }
    });
    if (grandEl) grandEl.textContent = fmt(grand);
    if (issueValueEl) issueValueEl.textContent = fmt(actualCost);
    if (kpiEl) kpiEl.textContent = fmt(grand);
    if (remainingEl) {
      remainingEl.textContent = fmt(grand - actualCost);
      remainingEl.style.color = grand - actualCost < 0 ? 'var(--red)' : 'var(--green)';
    }
    if (utilizationEl) utilizationEl.textContent = grand > 0 ? (Math.round(actualCost / grand * 1000) / 10) + '% utilized · Unsaved' : 'Set budget quantities below · Unsaved';
  }

  qtyInputs.concat(costInputs).forEach(function (el) {
    el.addEventListener('input', recalc);
  });

  if (fillBtn) {
    fillBtn.addEventListener('click', function () {
      qtyInputs.forEach(function (qEl) {
        var pm = parseFloat(qEl.getAttribute('data-pm')) || 0;
        if ((!qEl.value || parseFloat(qEl.value) <= 0) && pm > 0) {
          qEl.value = pm.toFixed(2);
        }
      });
      recalc();
    });
  }
})();
</script>
