<div style="max-width:820px;margin:0 auto;">
<div class="card">
  <div class="ch">
    <div><div class="ct">Who Supplies This Part?</div><div class="cs">Search across all supplier catalogs by part name, part number, or item code</div></div>
    <a href="<?= base_url('suppliers') ?>" class="ca">&larr; Back</a>
  </div>
  <div style="padding:18px 24px;">
    <?= form_open('suppliers/find_part', array('method'=>'get')) ?>
    <div class="flex ai-c gap-10">
      <input type="text" name="q" class="form-control" style="flex:1;" placeholder="e.g. brake pad, oil filter, 15W-40…" value="<?= htmlspecialchars((string)($q ?? '')) ?>">
      <button type="submit" class="btn btn-primary btn-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Search
      </button>
    </div>
    <?= form_close() ?>
  </div>

  <?php if ($q): ?>
  <div style="overflow-x:auto;border-top:1px solid var(--g2);">
    <table class="tbl">
      <thead><tr>
        <th>Item</th><th>Supplier</th><th>Country</th><th style="text-align:right;">Price</th>
        <th style="text-align:center;">Lead Time</th><th style="text-align:center;">Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (empty($results)): ?>
      <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--t3);">No suppliers found for "<?= htmlspecialchars((string)($q)) ?>"</td></tr>
      <?php else: foreach ($results as $r):
        $badge = $r->supplier_status==='Active' ? 'var(--green)' : ($r->supplier_status==='Blacklisted' ? 'var(--red)' : 'var(--t3)');
        $bg    = $r->supplier_status==='Active' ? 'var(--green-d)' : ($r->supplier_status==='Blacklisted' ? 'var(--red-d)' : 'var(--g2)');
      ?>
      <tr>
        <td style="font-weight:600;color:var(--t1);"><?= htmlspecialchars((string)($r->description)) ?><?= $r->is_preferred ? ' ★' : '' ?><?= $r->item_code ? ' <span style="color:var(--t3);font-weight:400;">('.htmlspecialchars((string)($r->item_code)).')</span>' : '' ?></td>
        <td><a href="<?= base_url('suppliers/view/'.$r->supplier_id) ?>" style="color:var(--blue);"><?= htmlspecialchars((string)($r->company_name)) ?></a></td>
        <td><?= htmlspecialchars((string)($r->country)) ?></td>
        <td class="mono" style="text-align:right;"><?= $r->unit_price ? number_format((float)($r->unit_price),2).' '.htmlspecialchars((string)($r->currency)) : '—' ?></td>
        <td class="mono" style="text-align:center;"><?= $r->lead_time_days ? $r->lead_time_days.'d' : '—' ?></td>
        <td style="text-align:center;"><span style="padding:3px 8px;border-radius:5px;font-size:10.5px;font-weight:700;color:<?= $badge ?>;background:<?= $bg ?>;"><?= $r->supplier_status ?></span></td>
        <td style="text-align:right;"><a href="<?= base_url('orders/create') ?>" class="btn btn-ghost btn-sm">Order</a></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
</div>
