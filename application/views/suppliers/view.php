<?php
$badge = $supplier->status==='Active' ? 'var(--green)' : ($supplier->status==='Blacklisted' ? 'var(--red)' : 'var(--t3)');
$bg    = $supplier->status==='Active' ? 'var(--green-d)' : ($supplier->status==='Blacklisted' ? 'var(--red-d)' : 'var(--g2)');
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="flex ai-c gap-10">
      <div class="page-heading" style="font-size:16px;"><?= htmlspecialchars((string)($supplier->company_name)) ?></div>
      <span style="padding:3px 8px;border-radius:5px;font-size:10.5px;font-weight:700;color:<?= $badge ?>;background:<?= $bg ?>;"><?= $supplier->status ?></span>
    </div>
    <div class="cs"><?= htmlspecialchars((string)($supplier->country)) ?><?= $supplier->city ? ', '.htmlspecialchars((string)($supplier->city)) : '' ?> &middot; <?= htmlspecialchars((string)($supplier->currency)) ?> &middot; <?= htmlspecialchars((string)($supplier->payment_terms ?: 'No payment terms set')) ?></div>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('orders/create') ?>" class="btn btn-primary btn-sm">+ New Order</a>
    <a href="<?= base_url('suppliers/edit/'.$supplier->id) ?>" class="btn btn-ghost btn-sm">Edit</a>
    <a href="<?= base_url('suppliers') ?>" class="btn btn-ghost btn-sm">&larr; Back</a>
  </div>
</div>

<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Quoted Lead Time</span>
    <div class="kpi-num"><?= $supplier->lead_time_guarantee_days ? $supplier->lead_time_guarantee_days.'d' : '—' ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">On-Time Delivery</span>
    <div class="kpi-num"><?= $on_time ? $on_time['rate'].'%' : '—' ?></div>
    <div class="cs" style="margin-top:4px;"><?= $on_time ? $on_time['sample'].' closed orders' : 'Not enough order history yet' ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Catalog Items</span>
    <div class="kpi-num"><?= count($catalog) ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--cyan)"></div>
    <span class="kpi-tag">Total Orders</span>
    <div class="kpi-num"><?= count($recent_orders) ?></div>
  </div>
</div>

<div class="grid cols-2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;" >

  <!-- Contacts -->
  <div class="card">
    <div class="ch"><div><div class="ct">Contacts</div><div class="cs"><?= count($contacts) ?> contact(s)</div></div></div>
    <div style="padding:6px 18px 16px;">
      <?php if (empty($contacts)): ?>
      <div style="color:var(--t3);font-size:12px;padding:10px 0;">No contacts added yet.</div>
      <?php else: foreach ($contacts as $c): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--g2);">
        <div>
          <div style="font-weight:600;color:var(--t1);font-size:12.5px;"><?= htmlspecialchars((string)($c->name)) ?> <span style="color:var(--t3);font-weight:400;">— <?= htmlspecialchars((string)($c->role ?: 'Contact')) ?></span></div>
          <div style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)($c->phone ?: '')) ?> <?= $c->phone && $c->email ? '·' : '' ?> <?= htmlspecialchars((string)($c->email ?: '')) ?></div>
        </div>
        <?= form_open('suppliers/delete_contact/'.$c->id.'/'.$supplier->id, array('style' => 'display:inline;margin:0;', 'onsubmit' => "return confirm('Remove this contact?')")) ?>
          <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red);">Remove</button>
        <?= form_close() ?>
      </div>
      <?php endforeach; endif; ?>

      <?= form_open('suppliers/add_contact/'.$supplier->id) ?>
      <div class="form-grid cols-2" style="margin-top:14px;gap:8px;">
        <input type="text" name="name" class="form-control" placeholder="Name" required>
        <input type="text" name="role" class="form-control" placeholder="Role (e.g. Sales Manager)">
        <input type="text" name="phone" class="form-control" placeholder="Phone">
        <input type="email" name="email" class="form-control" placeholder="Email">
      </div>
      <button type="submit" class="btn btn-ghost btn-sm" style="margin-top:8px;">+ Add Contact</button>
      <?= form_close() ?>
    </div>
  </div>

  <!-- Recent Orders -->
  <div class="card">
    <div class="ch"><div><div class="ct">Recent Orders</div><div class="cs">Last orders from this supplier</div></div></div>
    <div style="overflow-x:auto;">
      <table class="tbl">
        <thead><tr><th>Order No</th><th>Date</th><th>Status</th><th style="text-align:right;">Cost</th></tr></thead>
        <tbody>
        <?php if (empty($recent_orders)): ?>
        <tr><td colspan="4" style="text-align:center;padding:30px;color:var(--t3);">No orders yet</td></tr>
        <?php else: foreach (array_slice($recent_orders,0,8) as $o): ?>
        <tr>
          <td><a href="<?= base_url('orders/view/'.$o->id) ?>" style="color:var(--blue);font-weight:600;"><?= htmlspecialchars((string)($o->order_no)) ?></a></td>
          <td class="mono"><?= date('d M Y', strtotime($o->order_date)) ?></td>
          <td style="font-size:11px;"><?= htmlspecialchars((string)($o->status)) ?></td>
          <td class="mono" style="text-align:right;"><?= number_format((float)($o->total_cost),2) ?> <?= htmlspecialchars((string)($o->currency)) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Catalog -->
<div class="card mt-4">
  <div class="ch"><div><div class="ct">Supplier Catalog — What They Supply</div><div class="cs">Parts/materials this supplier provides, with price & lead time</div></div></div>
  <div style="overflow-x:auto;">
    <table class="tbl">
      <thead><tr>
        <th>Item</th><th>Part No.</th><th style="text-align:right;">Unit Price</th><th style="text-align:center;">MOQ</th>
        <th style="text-align:center;">Lead Time</th><th style="text-align:center;">Preferred</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (empty($catalog)): ?>
      <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--t3);">No catalog items mapped yet</td></tr>
      <?php else: foreach ($catalog as $c): ?>
      <tr>
        <td style="font-weight:600;color:var(--t1);"><?= htmlspecialchars((string)($c->description)) ?><?= $c->item_code ? ' <span style="color:var(--t3);font-weight:400;">('.htmlspecialchars((string)($c->item_code)).')</span>' : '' ?></td>
        <td class="mono"><?= htmlspecialchars((string)($c->part_number ?: '—')) ?></td>
        <td class="mono" style="text-align:right;"><?= $c->unit_price ? number_format((float)($c->unit_price),2).' '.htmlspecialchars((string)($c->currency)) : '—' ?></td>
        <td class="mono" style="text-align:center;"><?= $c->moq ?: '—' ?></td>
        <td class="mono" style="text-align:center;"><?= $c->lead_time_days ? $c->lead_time_days.'d' : '—' ?></td>
        <td style="text-align:center;"><?= $c->is_preferred ? '★' : '' ?></td>
        <td style="text-align:right;">
          <?= form_open('suppliers/delete_catalog_item/'.$c->id.'/'.$supplier->id, array('style' => 'display:inline;margin:0;', 'onsubmit' => "return confirm('Remove this catalog item?')")) ?>
            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red);">Remove</button>
          <?= form_close() ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div style="padding:16px 18px 22px;border-top:1px solid var(--g2);">
    <?= form_open('suppliers/add_catalog_item/'.$supplier->id) ?>
    <div class="form-grid cols-3" style="gap:8px;">
      <select name="material_id" class="form-select">
        <option value="">— Not yet in inventory catalog —</option>
        <?php foreach ($materials as $m): ?>
        <option value="<?= $m->id ?>"><?= htmlspecialchars((string)($m->item_code.' — '.$m->description)) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="description" class="form-control" placeholder="Description (required)" required>
      <input type="text" name="part_number" class="form-control" placeholder="Part Number">
      <input type="number" step="0.01" name="unit_price" class="form-control" placeholder="Unit Price">
      <select name="currency" class="form-select">
        <?php foreach (wms_currencies() as $cu): ?><option value="<?= $cu ?>" <?= $cu===$supplier->currency?'selected':'' ?>><?= $cu ?></option><?php endforeach; ?>
      </select>
      <input type="number" step="0.01" name="moq" class="form-control" placeholder="MOQ">
      <input type="number" name="lead_time_days" class="form-control" placeholder="Lead time (days)">
      <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--t3);">
        <input type="checkbox" name="is_preferred" value="1"> Preferred supplier for this item
      </label>
    </div>
    <button type="submit" class="btn btn-ghost btn-sm" style="margin-top:10px;">+ Add Catalog Item</button>
    <?= form_close() ?>
  </div>
</div>
