<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$sup_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$can_write        = !empty($can_write);
$linked_order_cnt = isset($linked_order_cnt) ? (int)$linked_order_cnt : count($recent_orders ?? array());
$stVal            = Supplier_model::clean_status($supplier->status ?? 'Active');
$badge            = $stVal === 'Active' ? 'var(--green)' : ($stVal === 'Blacklisted' ? 'var(--red)' : 'var(--t3)');
$bg               = $stVal === 'Active' ? 'var(--green-d)' : ($stVal === 'Blacklisted' ? 'var(--red-d)' : 'var(--g2)');
?>
<style>
.sup-profile-split { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.sup-profile-kpis  { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.sup-form-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.sup-form-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
@media (max-width: 900px) {
  .sup-profile-split { grid-template-columns: minmax(0, 1fr); }
  .sup-profile-kpis  { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .sup-form-2, .sup-form-3 { grid-template-columns: minmax(0, 1fr); }
}
</style>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
      <div class="page-heading" style="font-size:16px;"><?= $sup_esc($supplier->company_name) ?></div>
      <span style="padding:3px 8px;border-radius:5px;font-size:10.5px;font-weight:700;color:<?= $badge ?>;background:<?= $bg ?>;"><?= $sup_esc($stVal) ?></span>
    </div>
    <div class="cs">
      <?= $sup_esc($supplier->country) ?><?= $supplier->city ? ', ' . $sup_esc($supplier->city) : '' ?>
      &middot; <?= $sup_esc($supplier->currency) ?>
      &middot; <?= $sup_esc($supplier->payment_terms ?: 'No payment terms set') ?>
      <?= $supplier->tax_id ? ' &middot; TIN: ' . $sup_esc($supplier->tax_id) : '' ?>
    </div>
  </div>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <?php if ($can_write): ?>
      <a href="<?= base_url('orders/create') ?>" class="btn btn-primary btn-sm">+ New Order</a>
      <a href="<?= base_url('suppliers/edit/' . (int)$supplier->id) ?>" class="btn btn-ghost btn-sm">Edit</a>
      <?php if ($linked_order_cnt === 0): ?>
        <?= form_open('suppliers/delete/' . (int)$supplier->id, array('style' => 'display:inline;margin:0;', 'onsubmit' => "return confirm('Delete this supplier permanently?')")) ?>
          <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red);">Delete</button>
        <?= form_close() ?>
      <?php endif; ?>
    <?php endif; ?>
    <a href="<?= base_url('suppliers') ?>" class="btn btn-ghost btn-sm">&larr; Back</a>
  </div>
</div>

<div class="sup-profile-kpis">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Quoted Lead Time</span>
    <div class="kpi-num"><?= $supplier->lead_time_guarantee_days !== NULL && $supplier->lead_time_guarantee_days !== '' ? ((int)$supplier->lead_time_guarantee_days . 'd') : '—' ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">On-Time Delivery</span>
    <div class="kpi-num"><?= $on_time ? ((int)$on_time['rate'] . '%') : '—' ?></div>
    <div class="cs" style="margin-top:4px;"><?= $on_time ? ((int)$on_time['sample'] . ' closed orders') : 'Not enough order history yet' ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Catalog Items</span>
    <div class="kpi-num"><?= count($catalog) ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--cyan)"></div>
    <span class="kpi-tag">Total Orders</span>
    <div class="kpi-num"><?= (int)$linked_order_cnt ?></div>
  </div>
</div>

<div class="sup-profile-split">

  <!-- Contacts -->
  <div class="card">
    <div class="ch"><div><div class="ct">Contacts</div><div class="cs"><?= count($contacts) ?> contact(s)</div></div></div>
    <div style="padding:6px 18px 16px;">
      <?php if (empty($contacts)): ?>
      <div style="color:var(--t3);font-size:12px;padding:10px 0;">No contacts added yet.</div>
      <?php else: foreach ($contacts as $c): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--g2);">
        <div style="min-width:0;">
          <div style="font-weight:600;color:var(--t1);font-size:12.5px;"><?= $sup_esc($c->name) ?> <span style="color:var(--t3);font-weight:400;">— <?= $sup_esc($c->role ?: 'Contact') ?></span></div>
          <div style="font-size:11px;color:var(--t3);word-break:break-word;"><?= $sup_esc($c->phone ?: '') ?> <?= $c->phone && $c->email ? '&middot;' : '' ?> <?= $sup_esc($c->email ?: '') ?></div>
        </div>
        <?php if ($can_write): ?>
        <?= form_open('suppliers/delete_contact/' . (int)$c->id . '/' . (int)$supplier->id, array('style' => 'display:inline;margin:0;', 'onsubmit' => "return confirm('Remove this contact?')")) ?>
          <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red);">Remove</button>
        <?= form_close() ?>
        <?php endif; ?>
      </div>
      <?php endforeach; endif; ?>

      <?php if ($can_write): ?>
      <?= form_open('suppliers/add_contact/' . (int)$supplier->id) ?>
      <div class="sup-form-2" style="margin-top:14px;">
        <input type="text" name="name" class="form-control" placeholder="Name" required>
        <input type="text" name="role" class="form-control" placeholder="Role (e.g. Sales Manager)">
        <input type="text" name="phone" class="form-control" placeholder="Phone">
        <input type="email" name="email" class="form-control" placeholder="Email">
      </div>
      <button type="submit" class="btn btn-ghost btn-sm" style="margin-top:8px;">+ Add Contact</button>
      <?= form_close() ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Orders -->
  <div class="card">
    <div class="ch"><div><div class="ct">Recent Orders</div><div class="cs">Purchase orders &amp; linked fleet assets</div></div></div>
    <div style="overflow-x:auto;">
      <table class="tbl">
        <thead><tr><th>Order No</th><th>Date</th><th>Machine</th><th>Status</th><th style="text-align:right;">Cost</th></tr></thead>
        <tbody>
        <?php if (empty($recent_orders)): ?>
        <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--t3);">No orders yet</td></tr>
        <?php else: foreach (array_slice($recent_orders, 0, 8) as $o): ?>
        <tr>
          <td><a href="<?= base_url('orders/view/' . (int)$o->id) ?>" style="color:var(--blue);font-weight:600;"><?= $sup_esc($o->order_no) ?></a></td>
          <td class="mono"><?= !empty($o->order_date) ? date('d M Y', strtotime($o->order_date)) : '—' ?></td>
          <td class="mono" style="font-size:11px;"><?= !empty($o->registration_no) ? $sup_esc($o->registration_no) : '—' ?></td>
          <td style="font-size:11px;"><?= $sup_esc($o->status) ?></td>
          <td class="mono" style="text-align:right;"><?= number_format((float)$o->total_cost, 2) ?> <?= $sup_esc($o->currency) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Catalog -->
<div class="card mt-4">
  <div class="ch"><div><div class="ct">Supplier Catalog — What They Supply</div><div class="cs">Parts/materials this supplier provides, with price &amp; lead time</div></div></div>
  <div style="overflow-x:auto;">
    <table class="tbl">
      <thead><tr>
        <th>Item</th><th>Part No.</th><th style="text-align:right;">Unit Price</th><th style="text-align:center;">MOQ</th>
        <th style="text-align:center;">Lead Time</th><th style="text-align:center;">Preferred</th>
        <?php if ($can_write): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (empty($catalog)): ?>
      <tr><td colspan="<?= $can_write ? 7 : 6 ?>" style="text-align:center;padding:30px;color:var(--t3);">No catalog items mapped yet</td></tr>
      <?php else: foreach ($catalog as $c): ?>
      <tr>
        <td style="font-weight:600;color:var(--t1);"><?= $sup_esc($c->description) ?><?= !empty($c->item_code) ? ' <span style="color:var(--t3);font-weight:400;">(' . $sup_esc($c->item_code) . ')</span>' : '' ?></td>
        <td class="mono"><?= $sup_esc($c->part_number ?: '—') ?></td>
        <td class="mono" style="text-align:right;"><?= $c->unit_price !== NULL ? (number_format((float)$c->unit_price, 2) . ' ' . $sup_esc($c->currency)) : '—' ?></td>
        <td class="mono" style="text-align:center;"><?= $c->moq !== NULL ? $sup_esc($c->moq) : '—' ?></td>
        <td class="mono" style="text-align:center;"><?= $c->lead_time_days !== NULL ? ((int)$c->lead_time_days . 'd') : '—' ?></td>
        <td style="text-align:center;"><?= !empty($c->is_preferred) ? '&#9733;' : '' ?></td>
        <?php if ($can_write): ?>
        <td style="text-align:right;">
          <?= form_open('suppliers/delete_catalog_item/' . (int)$c->id . '/' . (int)$supplier->id, array('style' => 'display:inline;margin:0;', 'onsubmit' => "return confirm('Remove this catalog item?')")) ?>
            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red);">Remove</button>
          <?= form_close() ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($can_write): ?>
  <div style="padding:16px 18px 22px;border-top:1px solid var(--g2);">
    <?= form_open('suppliers/add_catalog_item/' . (int)$supplier->id) ?>
    <div class="sup-form-3">
      <select name="material_id" class="form-select">
        <option value="">— Not yet in inventory catalog —</option>
        <?php foreach ($materials as $m): ?>
        <option value="<?= (int)$m->id ?>"><?= $sup_esc($m->item_code . ' — ' . $m->description) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="description" class="form-control" placeholder="Description (required)" required>
      <input type="text" name="part_number" class="form-control" placeholder="Part Number">
      <input type="number" step="0.01" min="0" name="unit_price" class="form-control" placeholder="Unit Price">
      <select name="currency" class="form-select">
        <?php foreach (wms_currencies() as $cu): ?>
        <option value="<?= $sup_esc($cu) ?>" <?= $cu === $supplier->currency ? 'selected' : '' ?>><?= $sup_esc($cu) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="number" step="0.01" min="0" name="moq" class="form-control" placeholder="MOQ">
      <input type="number" min="0" name="lead_time_days" class="form-control" placeholder="Lead time (days)">
      <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--t3);">
        <input type="checkbox" name="is_preferred" value="1"> Preferred supplier for this item
      </label>
    </div>
    <button type="submit" class="btn btn-ghost btn-sm" style="margin-top:10px;">+ Add Catalog Item</button>
    <?= form_close() ?>
  </div>
  <?php endif; ?>
</div>
