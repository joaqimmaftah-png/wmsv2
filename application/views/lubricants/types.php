<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$lub_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$can_write        = !empty($can_write);
$can_manage_types = !empty($can_manage_types);
$ccy              = isset($currency_code) && $currency_code !== '' ? (string)$currency_code : 'TZS';
$cats             = isset($categories) && is_array($categories) ? $categories : Lubricant_model::$CATEGORIES;
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
.lub-types-split { display: grid; grid-template-columns: 340px minmax(0, 1fr); gap: 16px; align-items: start; }
@media (max-width: 900px) {
  .lub-types-split { grid-template-columns: minmax(0, 1fr); }
}
</style>

<nav class="lub-subnav" aria-label="Lubricants navigation">
  <a href="<?= base_url('lubricants') ?>">Consumption Ledger</a>
  <?php if ($can_write): ?>
  <a href="<?= base_url('lubricants/budget') ?>">Lube Budget</a>
  <?php endif; ?>
  <a href="<?= base_url('lubricants/specifications') ?>">OEM Specifications</a>
  <a href="<?= base_url('lubricants/types') ?>" class="is-active">Lube Types</a>
  <a href="<?= base_url('lubricants/forecast') ?>">PM Forecast</a>
</nav>

<div class="lub-types-split" style="<?= $can_manage_types ? '' : 'grid-template-columns:minmax(0,1fr);' ?>">
  <?php if ($can_manage_types): ?>
  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Add Lubricant Type</div>
        <div class="cs">Register a new workshop oil, coolant, or grease grade</div>
      </div>
    </div>
    <div style="padding:18px 20px 22px;">
      <?= form_open('lubricants/types') ?>
      <div style="margin-bottom:12px;">
        <label class="form-label">Name <span class="req">*</span></label>
        <input type="text" name="name" class="form-control" required placeholder="e.g. Heavy Duty Engine Oil">
      </div>
      <div style="margin-bottom:12px;">
        <label class="form-label">Category <span class="req">*</span></label>
        <select name="category" class="form-select" required>
          <?php foreach ($cats as $cat): ?>
          <option value="<?= $lub_esc($cat) ?>"><?= $lub_esc($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid cols-2" style="margin-bottom:12px;">
        <div>
          <label class="form-label">Viscosity / Grade</label>
          <input type="text" name="grade" class="form-control" placeholder="e.g. 15W-40, ISO 46">
        </div>
        <div>
          <label class="form-label">Unit</label>
          <select name="unit" class="form-select">
            <option value="L">L</option>
            <option value="Kg">Kg</option>
            <option value="Pcs">Pcs</option>
          </select>
        </div>
      </div>
      <div style="margin-bottom:12px;">
        <label class="form-label">Default Unit Cost (<?= $lub_esc($ccy) ?>)</label>
        <input type="number" name="unit_cost" class="form-control" step="0.01" min="0" placeholder="0.00">
      </div>
      <div style="margin-bottom:16px;">
        <label class="form-label">Description</label>
        <input type="text" name="description" class="form-control" placeholder="Optional specification note…">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;">+ Add Lube Type</button>
      <?= form_close() ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Lube Types Master Catalogue</div>
        <div class="cs"><?= count($lube_types) ?> active fluid grade(s)</div>
      </div>
    </div>
    <div style="overflow-x:auto;">
      <table class="tbl">
        <thead>
          <tr>
            <th>Name</th>
            <th>Grade</th>
            <th>Category</th>
            <th style="text-align:center;">Unit</th>
            <th style="text-align:right;">Unit Cost (<?= $lub_esc($ccy) ?>)</th>
            <th>Description</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lube_types as $lt): ?>
          <tr>
            <td style="font-weight:700;color:var(--t1);"><?= $lub_esc($lt->name) ?></td>
            <td class="mono" style="color:var(--t2);"><?= $lub_esc($lt->grade ?: '—') ?></td>
            <td><?= $lub_esc($lt->category) ?></td>
            <td class="mono" style="text-align:center;"><?= $lub_esc($lt->unit) ?></td>
            <td class="mono" style="text-align:right;"><?= number_format((float)($lt->unit_cost ?? 0), 2) ?></td>
            <td style="font-size:12px;color:var(--t3);"><?= $lub_esc($lt->description ?: '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
