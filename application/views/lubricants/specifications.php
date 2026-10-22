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
  text-decoration: none; color: var(--t2); background: var(--card, #111827);
  border: 1px solid var(--sep, rgba(148,163,184,0.16));
}
.lub-subnav a.is-active { background: var(--blue); color: #ffffff; border-color: var(--blue); }
.lub-spec-split { display: grid; grid-template-columns: 360px minmax(0, 1fr); gap: 16px; align-items: start; }
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
        <div class="ct">Set OEM Fluid Standard</div>
        <div class="cs">Defines recommended refill quantity &amp; interval per machine class</div>
      </div>
    </div>
    <div style="padding:18px 20px 22px;">
      <?= form_open('lubricants/specifications') ?>
      <div style="margin-bottom:12px;">
        <label class="form-label">Equipment Type <span class="req">*</span></label>
        <select name="equipment_type_id" class="form-select" required>
          <option value="">Select Equipment Type…</option>
          <?php foreach ($eq_types as $et): ?>
          <option value="<?= (int)$et->id ?>"><?= $lub_esc($et->name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-bottom:12px;">
        <label class="form-label">Lube / Consumable <span class="req">*</span></label>
        <select name="lube_type_id" class="form-select" required>
          <option value="">Select Lubricant…</option>
          <?php foreach ($lube_types as $lt): ?>
          <option value="<?= (int)$lt->id ?>"><?= $lub_esc($lt->name) ?> (<?= $lub_esc($lt->unit) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid cols-2" style="margin-bottom:12px;">
        <div>
          <label class="form-label">Recommended Qty <span class="req">*</span></label>
          <input type="number" name="recommended_qty" class="form-control" step="0.1" min="0" required placeholder="e.g. 28">
        </div>
        <div>
          <label class="form-label">Interval (hrs)</label>
          <input type="number" name="service_interval_hours" class="form-control" min="0" placeholder="e.g. 250">
        </div>
      </div>
      <div style="margin-bottom:16px;">
        <label class="form-label">Notes</label>
        <input type="text" name="notes" class="form-control" placeholder="OEM manual reference or sump capacity note…">
      </div>
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
      <table class="tbl">
        <thead>
          <tr>
            <th>Equipment Type</th>
            <th>Lubricant</th>
            <th style="text-align:right;">Recommended Qty</th>
            <th style="text-align:center;">Interval</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($specs)): ?>
          <tr><td colspan="5" style="text-align:center;padding:36px;color:var(--t3);">No specifications defined yet.</td></tr>
          <?php else: foreach ($specs as $s): ?>
          <tr>
            <td style="font-weight:700;color:var(--t1);"><?= $lub_esc($s->type_name) ?></td>
            <td><?= $lub_esc($s->lube_name) ?><?= !empty($s->lube_grade) ? ' <span style="color:var(--t3);">(' . $lub_esc($s->lube_grade) . ')</span>' : '' ?></td>
            <td class="mono" style="text-align:right;font-weight:700;color:var(--t1);"><?= number_format((float)$s->recommended_qty, 1) ?> <?= $lub_esc($s->unit) ?></td>
            <td class="mono" style="text-align:center;color:var(--t2);"><?= $s->service_interval_hours ? ((int)$s->service_interval_hours . ' hrs') : '—' ?></td>
            <td style="font-size:12px;color:var(--t3);"><?= $lub_esc($s->notes ?: '—') ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
