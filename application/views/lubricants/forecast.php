<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$lub_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$can_write = !empty($can_write);
$lube_map  = array();
foreach (($lube_types ?? array()) as $lt) {
    $lube_map[(int)$lt->id] = $lt;
}
// The model always returns two month keys, even when no dated PM demand exists.
$has_visible_demand = FALSE;
foreach (($forecast ?? array()) as $monthly_lubes) {
    foreach ($lube_map as $lid => $lt) {
        if (!empty($monthly_lubes[$lid]) && (float)$monthly_lubes[$lid] > 0) $has_visible_demand = TRUE;
    }
}
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
.lub-forecast-scroll { overflow-x: auto; }
.lub-forecast-table { min-width: max-content; }
.lub-forecast-table th:first-child, .lub-forecast-table td:first-child { position: sticky; left: 0; background: var(--surface); z-index: 1; }
.lub-forecast-months { display: none; padding: 16px; gap: 12px; }
.lub-forecast-month { background: var(--surface); border: 1px solid var(--sep); border-radius: 12px; padding: 16px; min-width: 0; }
.lub-forecast-month h2 { font-size: 14px; margin: 0 0 12px; color: var(--t1); }
.lub-forecast-item { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: 8px 0; border-top: 1px solid var(--sep); color: var(--t2); font-size: 12px; }
.lub-forecast-item strong { color: var(--t1); white-space: nowrap; }
.lub-subnav :focus-visible { outline: 2px solid var(--blue); outline-offset: 2px; }
@media (max-width: 900px) {
  .lub-forecast-scroll { display: none; }
  .lub-forecast-months { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr)); }
}
</style>

<nav class="lub-subnav" aria-label="Lubricants navigation">
  <a href="<?= base_url('lubricants') ?>">Consumption Ledger</a>
  <?php if ($can_write): ?>
  <a href="<?= base_url('lubricants/budget') ?>">Lube Budget</a>
  <?php endif; ?>
  <a href="<?= base_url('lubricants/specifications') ?>">OEM Specifications</a>
  <a href="<?= base_url('lubricants/types') ?>">Lube Types</a>
  <a href="<?= base_url('lubricants/forecast') ?>" class="is-active">PM Forecast</a>
</nav>

<div class="card">
  <div class="ch">
    <div>
      <div class="ct">Lubricant Consumption Forecast (2-Month PM Horizon)</div>
      <div class="cs">Dated active PM schedules × OEM quantities, for this month and next. Hour-based projections and specification intervals are not included here. A dash means no dated demand for that fluid.</div>
    </div>
  </div>
  <?php if (!$has_visible_demand): ?>
      <div style="text-align:center;padding:42px 16px;color:var(--t3);">
        No dated PM demand for active fluid grades in this two-month period. Check active PM due dates and OEM specifications.
      </div>
    <?php else: ?>
      <div class="lub-forecast-scroll">
      <table class="tbl lub-forecast-table">
        <thead>
          <tr>
            <th>Month</th>
            <?php foreach ($lube_map as $lt): ?>
            <th style="text-align:right;">
              <?= $lub_esc($lt->name) ?>
              <div style="font-size:10px;font-weight:400;color:var(--t3);"><?= $lub_esc($lt->unit) ?></div>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($forecast as $month => $lubes): ?>
          <tr>
            <td style="font-weight:700;color:var(--t1);white-space:nowrap;">
              <?= $lub_esc(date('M Y', strtotime($month . '-01'))) ?>
              <?php if ($month === date('Y-m')): ?>
              <span style="margin-left:6px;padding:2px 6px;border-radius:999px;font-size:10px;background:var(--blue-d);color:var(--blue);">Current</span>
              <?php endif; ?>
            </td>
            <?php foreach ($lube_map as $lid => $lt): ?>
            <td class="mono" style="text-align:right;color:var(--t1);">
              <?= isset($lubes[$lid]) && (float)$lubes[$lid] > 0
                  ? ('<strong>' . number_format((float)$lubes[$lid], 2) . '</strong> ' . $lub_esc($lt->unit))
                  : '<span style="color:var(--t3);">—</span>' ?>
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
          <h2><?= $lub_esc(date('F Y', strtotime($month . '-01'))) ?></h2>
          <?php $month_has_demand = FALSE; foreach ($lube_map as $lid => $lt):
            if (empty($lubes[$lid]) || (float)$lubes[$lid] <= 0) continue;
            $month_has_demand = TRUE;
          ?>
          <div class="lub-forecast-item">
            <span><?= $lub_esc($lt->name) ?><?= !empty($lt->grade) ? ' · ' . $lub_esc($lt->grade) : '' ?></span>
            <strong class="mono"><?= number_format((float)$lubes[$lid], 2) ?> <?= $lub_esc($lt->unit) ?></strong>
          </div>
          <?php endforeach; ?>
          <?php if (!$month_has_demand): ?><p style="color:var(--t2);font-size:12px;">No dated PM demand this month.</p><?php endif; ?>
        </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
</div>
