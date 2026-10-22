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
  <a href="<?= base_url('lubricants/forecast') ?>" class="is-active">PM Forecast</a>
</nav>

<div class="card">
  <div class="ch">
    <div>
      <div class="ct">Lubricant Consumption Forecast (2-Month PM Horizon)</div>
      <div class="cs">Projected fluid demand computed from scheduled PM services &amp; OEM sump specifications</div>
    </div>
  </div>
  <div style="overflow-x:auto;">
    <?php if (empty($forecast) || empty($lube_map)): ?>
      <div style="text-align:center;padding:42px 16px;color:var(--t3);">
        No scheduled PM fluid forecast available yet. Define PM schedules and OEM specifications to populate projections.
      </div>
    <?php else: ?>
      <table class="tbl">
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
              <span style="margin-left:6px;padding:2px 6px;border-radius:999px;font-size:10px;background:rgba(59,130,246,0.2);color:#93c5fd;">Current</span>
              <?php endif; ?>
            </td>
            <?php foreach ($lube_map as $lid => $lt): ?>
            <td class="mono" style="text-align:right;color:var(--t1);">
              <?= isset($lubes[$lid]) && (float)$lubes[$lid] > 0
                  ? ('<strong>' . number_format((float)$lubes[$lid], 1) . '</strong> ' . $lub_esc($lt->unit))
                  : '<span style="color:var(--t3);">—</span>' ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
