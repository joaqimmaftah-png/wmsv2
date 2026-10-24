<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$sup_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$can_write = !empty($can_write);
?>
<div style="max-width:860px;margin:0 auto;">
<div class="card">
  <div class="ch">
    <div><h1 class="ct">Who Supplies This Part?</h1><div class="cs">Search across all supplier catalogs by part name, part number, or store item code</div></div>
    <a href="<?= base_url('suppliers') ?>" class="ca">&larr; Back</a>
  </div>
  <div style="padding:18px 24px;">
    <?= form_open('suppliers/find_part', array('method' => 'get')) ?>
    <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
      <input type="text" name="q" class="form-control" style="flex:1 1 220px;" placeholder="e.g. brake pad, oil filter, 15W-40…" value="<?= $sup_esc($q ?? '') ?>">
      <button type="submit" class="btn btn-primary btn-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Search
      </button>
    </div>
    <?= form_close() ?>
  </div>

  <?php if (!empty($q)): ?>
  <div style="overflow-x:auto;border-top:1px solid var(--g2);">
    <table class="tbl">
      <thead><tr>
        <th>Item</th><th>Supplier</th><th>Country</th><th style="text-align:right;">Price</th>
        <th style="text-align:center;">Lead Time</th><th style="text-align:center;">Status</th>
        <?php if ($can_write): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (empty($results)): ?>
      <tr><td colspan="<?= $can_write ? 7 : 6 ?>" style="text-align:center;padding:40px;color:var(--t3);">No suppliers found for "<?= $sup_esc($q) ?>"</td></tr>
      <?php else: foreach ($results as $r):
        $rst   = Supplier_model::clean_status($r->supplier_status ?? 'Active');
        $badge = $rst === 'Active' ? 'var(--green)' : ($rst === 'Blacklisted' ? 'var(--red)' : 'var(--t3)');
        $bg    = $rst === 'Active' ? 'var(--green-d)' : ($rst === 'Blacklisted' ? 'var(--red-d)' : 'var(--g2)');
      ?>
      <tr>
        <td style="font-weight:600;color:var(--t1);">
          <?= $sup_esc($r->description) ?><?= !empty($r->is_preferred) ? ' &#9733;' : '' ?>
          <?= !empty($r->item_code) ? ' <span style="color:var(--t3);font-weight:400;">(' . $sup_esc($r->item_code) . ')</span>' : '' ?>
        </td>
        <td><a href="<?= base_url('suppliers/view/' . (int)$r->supplier_id) ?>" style="color:var(--blue);"><?= $sup_esc($r->company_name) ?></a></td>
        <td><?= $sup_esc($r->country) ?></td>
        <td class="mono" style="text-align:right;"><?= $r->unit_price !== NULL ? (number_format((float)$r->unit_price, 2) . ' ' . $sup_esc($r->currency)) : '—' ?></td>
        <td class="mono" style="text-align:center;"><?= $r->lead_time_days !== NULL ? ((int)$r->lead_time_days . 'd') : '—' ?></td>
        <td style="text-align:center;"><span style="padding:3px 8px;border-radius:5px;font-size:10.5px;font-weight:700;color:<?= $badge ?>;background:<?= $bg ?>;"><?= $sup_esc($rst) ?></span></td>
        <?php if ($can_write): ?>
        <td style="text-align:right;"><a href="<?= base_url('orders/create') ?>" class="btn btn-ghost btn-sm">Order</a></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
</div>
