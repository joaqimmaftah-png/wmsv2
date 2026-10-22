<?php
defined('BASEPATH') OR exit('No direct script access allowed');

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
$exportQs = '?year=' . $year_i . '&month=' . $month_i;
?>
<style>
#lubBudgetShell { max-width: 100%; overflow-x: clip; }
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

.bgt-kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
.bgt-kpi {
  padding: 12px 14px; border-radius: 12px; background: var(--card, #111827);
  border: 1px solid var(--sep, rgba(148,163,184,0.16)); min-width: 0;
}
.bgt-kpi-k { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--t3); }
.bgt-kpi-v { font-size: 20px; font-weight: 800; color: var(--t1); margin-top: 4px; }
.bgt-kpi-s { font-size: 11px; color: var(--t3); margin-top: 3px; }

.bgt-table-wrap { width: 100%; overflow-x: hidden; }
.bgt-table { width: 100%; table-layout: fixed; border-collapse: collapse; }
.bgt-table th, .bgt-table td {
  padding: 10px 8px; vertical-align: middle; overflow: hidden; text-overflow: ellipsis;
  border-bottom: 1px solid var(--g2, rgba(148,163,184,0.12));
}
.bgt-table th {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
  color: var(--t3); text-align: left; white-space: nowrap;
}
.bgt-input { width: 100%; max-width: 112px; }

@media (max-width: 900px) {
  .bgt-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .bgt-table, .bgt-table tbody, .bgt-table tfoot { display: block; width: 100%; }
  .bgt-table thead { display: none; }
  .bgt-table tbody tr {
    display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px;
    padding: 12px; margin-bottom: 10px; border-radius: 10px;
    border: 1px solid var(--sep, rgba(148,163,184,0.18));
  }
  .bgt-table tbody td {
    padding: 4px 0; border-bottom: 0; overflow: visible;
  }
  .bgt-table tbody td::before {
    content: attr(data-label); display: block; font-size: 10px; font-weight: 700;
    text-transform: uppercase; color: var(--t3); margin-bottom: 2px;
  }
  .bgt-table tfoot tr {
    display: flex; justify-content: space-between; align-items: center; gap: 10px;
    padding: 12px; border-top: 1px solid var(--g2);
  }
  .bgt-input { max-width: 100%; }
}
</style>

<div id="lubBudgetShell">
  <nav class="lub-subnav" aria-label="Lubricants navigation">
    <a href="<?= base_url('lubricants') ?>">Consumption Ledger</a>
    <a href="<?= base_url('lubricants/budget') ?>" class="is-active">Lube Budget</a>
    <a href="<?= base_url('lubricants/specifications') ?>">OEM Specifications</a>
    <a href="<?= base_url('lubricants/types') ?>">Lube Types</a>
    <a href="<?= base_url('lubricants/forecast') ?>">PM Forecast</a>
  </nav>

  <!-- Header & Period Picker -->
  <div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:12px;">
    <div>
      <h1 style="margin:0;font-size:18px;font-weight:700;color:var(--t1);">Lubricant Budget — <?= $lub_esc($month_name) ?></h1>
      <div class="cs">Monthly volume &amp; cost allocation compared against scheduled PM demand and live store issues</div>
    </div>

    <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
      <form method="get" action="<?= base_url('lubricants/budget') ?>" class="flex ai-c gap-10" style="flex-wrap:wrap;">
        <select name="year" class="form-select" style="width:96px;" aria-label="Budget year">
          <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 2; $y--): ?>
          <option value="<?= (int)$y ?>" <?= $year_i === $y ? 'selected' : '' ?>><?= (int)$y ?></option>
          <?php endfor; ?>
        </select>
        <select name="month" class="form-select" style="width:132px;" aria-label="Budget month">
          <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= (int)$m ?>" <?= $month_i === $m ? 'selected' : '' ?>><?= $lub_esc(date('F', mktime(0, 0, 0, $m, 1))) ?></option>
          <?php endfor; ?>
        </select>
        <button type="submit" class="btn btn-ghost btn-sm">Load Period</button>
      </form>
      <a href="<?= $lub_esc(base_url('lubricants/budget_csv' . $exportQs)) ?>" class="btn btn-ghost btn-sm">Export CSV</a>
      <a href="<?= $lub_esc(base_url('lubricants/budget_xlsx' . $exportQs)) ?>" class="btn btn-ghost btn-sm">Export Excel</a>
    </div>
  </div>

  <!-- 4-Card KPI Strip -->
  <div class="bgt-kpi-grid">
    <div class="bgt-kpi">
      <div class="bgt-kpi-k">Planned Budget</div>
      <div class="bgt-kpi-v" id="kpiPlannedTotal"><?= $lub_esc($ccy) ?> <?= number_format((float)$totals['budget_cost'], 2) ?></div>
      <div class="bgt-kpi-s"><?= number_format((float)$totals['budget_qty'], 1) ?> units allocated</div>
    </div>
    <div class="bgt-kpi">
      <div class="bgt-kpi-k">MTD Actual Spend</div>
      <div class="bgt-kpi-v"><?= $lub_esc($ccy) ?> <?= number_format((float)$totals['actual_cost'], 2) ?></div>
      <div class="bgt-kpi-s"><?= number_format((float)$totals['actual_qty'], 1) ?> units dispensed</div>
    </div>
    <div class="bgt-kpi">
      <div class="bgt-kpi-k">Remaining Balance</div>
      <div class="bgt-kpi-v" style="color:<?= (float)$totals['remaining_cost'] < 0 ? 'var(--red)' : 'var(--green)' ?>;">
        <?= $lub_esc($ccy) ?> <?= number_format((float)$totals['remaining_cost'], 2) ?>
      </div>
      <div class="bgt-kpi-s">
        <?= $totals['utilization_pct'] !== NULL ? ($totals['utilization_pct'] . '% utilized') : 'Set budget quantities below' ?>
      </div>
    </div>
    <div class="bgt-kpi">
      <div class="bgt-kpi-k">PM Plan Demand</div>
      <div class="bgt-kpi-v"><?= number_format((float)$totals['pm_req_qty'], 1) ?></div>
      <div class="bgt-kpi-s">Projected scheduled service volume</div>
    </div>
  </div>

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Monthly Fluid Allocation Matrix (<?= $lub_esc($ccy) ?>)</div>
        <div class="cs">Enter monthly quantities and unit rates; budget totals calculate live</div>
      </div>
      <?php if ($can_write): ?>
      <button type="button" id="bgtFillPmBtn" class="btn btn-ghost btn-sm">Copy PM Demand to Empty Rows</button>
      <?php endif; ?>
    </div>

    <div style="padding:12px 16px 20px;">
      <?= form_open('lubricants/budget') ?>
      <input type="hidden" name="year" value="<?= (int)$year_i ?>">
      <input type="hidden" name="month" value="<?= (int)$month_i ?>">

      <div class="bgt-table-wrap">
        <table class="bgt-table">
          <thead>
            <tr>
              <th style="width:20%;">Lubricant &amp; Grade</th>
              <th style="width:13%;">Category</th>
              <th style="width:10%;text-align:right;">PM Demand</th>
              <th style="width:14%;">Budget Qty</th>
              <th style="width:14%;">Unit Cost (<?= $lub_esc($ccy) ?>)</th>
              <th style="width:14%;text-align:right;">Budget Cost (<?= $lub_esc($ccy) ?>)</th>
              <th style="width:15%;text-align:right;">MTD Actual</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($lines as $ln):
            $lid    = (int)$ln->id;
            $qtyVal = $ln->budget_qty !== NULL ? rtrim(rtrim(number_format((float)$ln->budget_qty, 2, '.', ''), '0'), '.') : '';
            $cstVal = number_format((float)$ln->unit_cost, 2, '.', '');
          ?>
            <tr>
              <td data-label="Lubricant" style="grid-column:1 / -1;">
                <div style="font-weight:700;color:var(--t1);font-size:13px;"><?= $lub_esc($ln->name) ?></div>
                <div style="font-size:11px;color:var(--t3);"><?= $lub_esc($ln->grade ?: 'Standard') ?> &middot; <?= $lub_esc($ln->unit) ?></div>
              </td>
              <td data-label="Category">
                <span style="font-size:11.5px;color:var(--t2);"><?= $lub_esc($ln->category) ?></span>
              </td>
              <td data-label="PM Demand" class="mono" style="text-align:right;font-size:12px;color:var(--t2);">
                <?= number_format((float)$ln->pm_req_qty, 1) ?> <?= $lub_esc($ln->unit) ?>
              </td>
              <td data-label="Budget Qty (<?= $lub_esc($ln->unit) ?>)">
                <input type="number" name="budget[<?= $lid ?>][qty]"
                       class="form-control bgt-input budget-qty"
                       step="0.1" min="0"
                       data-id="<?= $lid ?>"
                       data-pm="<?= number_format((float)$ln->pm_req_qty, 2, '.', '') ?>"
                       value="<?= $lub_esc($qtyVal) ?>"
                       aria-label="Budget quantity for <?= $lub_esc($ln->name) ?>">
              </td>
              <td data-label="Unit Cost (<?= $lub_esc($ccy) ?>)">
                <input type="number" name="budget[<?= $lid ?>][cost]"
                       class="form-control bgt-input budget-cost"
                       step="0.01" min="0"
                       data-id="<?= $lid ?>"
                       value="<?= $lub_esc($cstVal) ?>"
                       aria-label="Unit cost for <?= $lub_esc($ln->name) ?>">
              </td>
              <td data-label="Budget Cost" class="mono" style="text-align:right;font-weight:700;color:var(--t1);">
                <span class="budget-total" id="total-<?= $lid ?>">
                  <?= (float)$ln->budget_cost > 0 ? ($lub_esc($ccy) . ' ' . number_format((float)$ln->budget_cost, 2)) : '—' ?>
                </span>
              </td>
              <td data-label="MTD Actual" class="mono" style="text-align:right;font-size:12px;color:var(--t2);">
                <?= number_format((float)$ln->actual_qty, 1) ?> <?= $lub_esc($ln->unit) ?>
                <div style="font-size:10.5px;color:var(--t3);"><?= $lub_esc($ccy) ?> <?= number_format((float)$ln->actual_cost, 2) ?></div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="5" style="text-align:right;font-weight:700;color:var(--t2);">TOTAL MONTHLY BUDGET:</td>
              <td class="mono" style="text-align:right;font-weight:800;font-size:14px;color:var(--blue);" id="grandTotal">
                <?= $lub_esc($ccy) ?> <?= number_format((float)$totals['budget_cost'], 2) ?>
              </td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <?php if ($can_write): ?>
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid var(--g2);">
        <button type="submit" class="btn btn-primary" id="saveBudgetBtn">Save Monthly Budget</button>
      </div>
      <?php endif; ?>
      <?= form_close() ?>
    </div>
  </div>
</div>

<script>
(function () {
  var ccy = <?= json_encode($ccy) ?>;
  var qtyInputs  = Array.prototype.slice.call(document.querySelectorAll('.budget-qty'));
  var costInputs = Array.prototype.slice.call(document.querySelectorAll('.budget-cost'));
  var grandEl    = document.getElementById('grandTotal');
  var kpiEl      = document.getElementById('kpiPlannedTotal');
  var fillBtn    = document.getElementById('bgtFillPmBtn');

  function fmt(num) {
    return ccy + ' ' + Number(num || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function recalc() {
    var grand = 0;
    qtyInputs.forEach(function (qEl) {
      var id = qEl.getAttribute('data-id');
      var cEl = document.querySelector('.budget-cost[data-id="' + id + '"]');
      var qv = parseFloat(qEl.value) || 0;
      var cv = cEl ? (parseFloat(cEl.value) || 0) : 0;
      var line = qv * cv;
      grand += line;
      var tEl = document.getElementById('total-' + id);
      if (tEl) tEl.textContent = line > 0 ? fmt(line) : '—';
    });
    if (grandEl) grandEl.textContent = fmt(grand);
    if (kpiEl) kpiEl.textContent = fmt(grand);
  }

  qtyInputs.concat(costInputs).forEach(function (el) {
    el.addEventListener('input', recalc);
  });

  if (fillBtn) {
    fillBtn.addEventListener('click', function () {
      qtyInputs.forEach(function (qEl) {
        var pm = parseFloat(qEl.getAttribute('data-pm')) || 0;
        if ((!qEl.value || parseFloat(qEl.value) <= 0) && pm > 0) {
          qEl.value = pm.toFixed(1);
        }
      });
      recalc();
    });
  }
})();
</script>
