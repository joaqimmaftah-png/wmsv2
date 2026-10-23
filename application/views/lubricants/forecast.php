<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Lubricants — PM Consumption Forecast.
 *
 * Presentation follows the /team/report enterprise design system through the
 * shared lubricants partial. The forecast figures and the "no dated demand"
 * determination come straight from the controller; this view only arranges
 * them. Dated active PM schedules × OEM quantities for this month and next.
 */

$lub_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$can_write = !empty($can_write);

include __DIR__ . '/_ui.php';

$lube_map = array();
foreach (($lube_types ?? array()) as $lt) {
    $lube_map[(int)$lt->id] = $lt;
}

$month_keys = array();
foreach (($forecast ?? array()) as $m_key => $m_lubes) {
    $month_keys[] = $m_key;
}
$this_month  = isset($month_keys[0]) ? $month_keys[0] : date('Y-m');
$next_month  = isset($month_keys[1]) ? $month_keys[1] : date('Y-m', strtotime('+1 month'));

/* Per-month demand — counts of grades, never a sum across mixed units. */
$demand_this  = 0;
$demand_next  = 0;
$units_active = array();
foreach ($lube_map as $lid => $lt) {
    $this_qty = (!empty($forecast[$this_month][$lid])) ? (float)$forecast[$this_month][$lid] : 0.0;
    $next_qty = (!empty($forecast[$next_month][$lid])) ? (float)$forecast[$next_month][$lid] : 0.0;
    if ($this_qty > 0) { $demand_this++; $units_active[(string)$lt->unit] = TRUE; }
    if ($next_qty > 0) { $demand_next++; $units_active[(string)$lt->unit] = TRUE; }
}
$has_visible_demand = ($demand_this + $demand_next) > 0;
?>
<style>
/* Page-specific layout for the two-month demand matrix. */
.lub-forecast-wrap { overflow: auto; max-height: calc(100vh - 300px); }
.lub-forecast-table { min-width: max-content; table-layout: auto; }
.lub-forecast-table th:first-child,
.lub-forecast-table td:first-child {
  position: sticky; left: 0; z-index: 3;
  background: var(--surface); box-shadow: 1px 0 0 var(--sep);
}
.lub-forecast-table thead th:first-child { background: var(--surface-2); z-index: 4; }
.lub-forecast-month-col { min-width: 150px; }
.lub-forecast-lube-col { min-width: 150px; }
.lub-forecast-val { font-variant-numeric: tabular-nums; }
.lub-current-pill {
  display: inline-flex; align-items: center; margin-left: 6px; padding: 1px 7px;
  border-radius: 100px; font-size: 10px; font-weight: 600;
  background: var(--lub-blue-lt); color: var(--blue);
}
.lub-forecast-months { display: none; padding: 14px; gap: 12px; }
.lub-forecast-month {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-md, 14px); padding: 16px; min-width: 0;
}
.lub-forecast-month h2 { font-size: 13.5px; margin: 0 0 10px; color: var(--t1); font-weight: 600; }
.lub-forecast-item {
  display: flex; align-items: baseline; justify-content: space-between; gap: 12px;
  padding: 8px 0; border-top: 1px solid var(--sep); color: var(--t2); font-size: 12px;
}
.lub-forecast-item strong { color: var(--t1); white-space: nowrap; font-variant-numeric: tabular-nums; }

@media (max-width: 900px) {
  /* The matrix becomes month cards — horizontal scrolling on a phone is worse. */
  .lub-forecast-wrap { display: none; }
  .lub-forecast-months { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr)); }
}
</style>

<?= $lub_print_banner('Lubricant Consumption Forecast', 'Horizon: <strong>' . $lub_esc(date('M Y', strtotime($this_month . '-01')) . ' – ' . date('M Y', strtotime($next_month . '-01'))) . '</strong>') ?>

<div class="lub-page">

  <?= $lub_nav('forecast') ?>

  <?= $lub_page_head(array(
      'crumb'    => array(array('Lubricants', 'lubricants'), array('PM Forecast', NULL)),
      'title'    => 'Lubricant Consumption Forecast',
      'pill'     => '2-month PM horizon',
      'subtitle' => 'Dated active PM schedules multiplied by OEM quantities, for this month and next. Hour-based projections and specification intervals are not included.',
      'actions'  => '<a href="' . base_url('lubricants/budget') . '" class="btn btn-ghost btn-sm">Open Lube Budget</a>',
  )) ?>

  <div class="lub-kpi-grid no-print">
    <?= $lub_kpi('Tracked Grades', count($lube_map), 'Active lubricant types') ?>
    <?= $lub_kpi('Demand This Month', (int)$demand_this, date('F Y', strtotime($this_month . '-01')) . ' · grade(s) with demand', $demand_this > 0 ? 'warn' : '') ?>
    <?= $lub_kpi('Demand Next Month', (int)$demand_next, date('F Y', strtotime($next_month . '-01')) . ' · grade(s) with demand', $demand_next > 0 ? 'warn' : '') ?>
    <?= $lub_kpi('Units in Play', count($units_active), 'Distinct fluid units across demand') ?>
  </div>

  <div class="lub-surface">
    <div class="lub-panel-head no-print">
      <div>
        <div class="lub-panel-title">Two-Month Fluid Demand</div>
        <p class="lub-panel-sub">Quantities are shown per fluid unit — a dash means no dated demand for that grade.</p>
      </div>
    </div>

    <?php if (!$has_visible_demand): ?>
      <?= $lub_empty(
          'No dated PM demand in this horizon',
          'No active PM schedule with a due date falls in these two months for the current fluid grades. Check PM due dates and OEM specifications.',
          '<a href="' . base_url('lubricants/specifications') . '" class="btn btn-secondary">Review OEM Specifications</a>'
      ) ?>
    <?php else: ?>

      <div class="lub-forecast-wrap">
        <table class="lub-table lub-forecast-table">
          <thead>
            <tr>
              <th scope="col" class="lub-forecast-month-col">Month</th>
              <?php foreach ($lube_map as $lt): ?>
              <th scope="col" class="lub-num lub-forecast-lube-col">
                <?= $lub_esc($lt->name) ?>
                <div style="font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;color:var(--t3);"><?= $lub_esc($lt->unit) ?></div>
              </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($forecast as $month => $lubes): ?>
            <tr>
              <td class="lub-forecast-month-col" style="font-weight:600;color:var(--t1);white-space:nowrap;">
                <?= $lub_esc(date('M Y', strtotime($month . '-01'))) ?>
                <?php if ($month === date('Y-m')): ?>
                <span class="lub-current-pill">Current</span>
                <?php endif; ?>
              </td>
              <?php foreach ($lube_map as $lid => $lt): ?>
              <td class="lub-num lub-forecast-val">
                <?php if (isset($lubes[$lid]) && (float)$lubes[$lid] > 0): ?>
                  <span class="lub-val-strong"><?= number_format((float)$lubes[$lid], 2) ?></span>
                  <span class="lub-code"><?= $lub_esc($lt->unit) ?></span>
                <?php else: ?>
                  <span class="lub-muted-zero">—</span>
                <?php endif; ?>
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="lub-forecast-months">
        <?php foreach ($forecast as $month => $lubes): ?>
        <section class="lub-forecast-month" aria-label="<?= $lub_esc(date('F Y', strtotime($month . '-01'))) ?> fluid forecast">
          <h2>
            <?= $lub_esc(date('F Y', strtotime($month . '-01'))) ?>
            <?php if ($month === date('Y-m')): ?><span class="lub-current-pill">Current</span><?php endif; ?>
          </h2>
          <?php $month_has_demand = FALSE; foreach ($lube_map as $lid => $lt):
            if (empty($lubes[$lid]) || (float)$lubes[$lid] <= 0) continue;
            $month_has_demand = TRUE;
          ?>
          <div class="lub-forecast-item">
            <span><?= $lub_esc($lt->name) ?><?= !empty($lt->grade) ? ' · ' . $lub_esc($lt->grade) : '' ?></span>
            <strong><?= number_format((float)$lubes[$lid], 2) ?> <?= $lub_esc($lt->unit) ?></strong>
          </div>
          <?php endforeach; ?>
          <?php if (!$month_has_demand): ?>
          <p style="color:var(--t2);font-size:12px;">No dated PM demand this month.</p>
          <?php endif; ?>
        </section>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>
  </div>
</div>
