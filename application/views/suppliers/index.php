<?php $f = isset($filter) ? $filter : array(); ?>

<!-- Toolbar -->
<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <form method="get" class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <select name="country" class="form-select" style="width:160px;">
      <option value="">All Countries</option>
      <?php foreach ($countries as $c): ?>
      <option value="<?= htmlspecialchars($c) ?>" <?= (!empty($f['country']) && $f['country']==$c)?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="form-select" style="width:150px;">
      <option value="">All Status</option>
      <?php foreach (array('Active','Inactive','Blacklisted') as $s): ?>
      <option value="<?= $s ?>" <?= (!empty($f['status']) && $f['status']==$s)?'selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="search" class="form-control" style="width:190px;"
      placeholder="Search supplier or tax ID…" value="<?= htmlspecialchars($f['search']??'') ?>">
    <button type="submit" class="btn btn-primary btn-sm btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Filter
    </button>
    <a href="<?= base_url('suppliers') ?>" class="btn btn-ghost btn-sm">Reset</a>
  </form>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('suppliers/find_part') ?>" class="btn btn-ghost btn-sm btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Who Supplies This Part?
    </a>
    <a href="<?= base_url('suppliers/create') ?>" class="btn btn-primary btn-sm btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      Add Supplier
    </a>
  </div>
</div>

<!-- KPI strip -->
<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total Suppliers</span>
    <div class="kpi-num"><?= $kpi['total'] ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Active</span>
    <div class="kpi-num"><?= $kpi['active'] ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Blacklisted</span>
    <div class="kpi-num"><?= $kpi['blacklisted'] ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--cyan)"></div>
    <span class="kpi-tag">Countries</span>
    <div class="kpi-num"><?= $kpi['countries'] ?></div>
  </div>
</div>

<div class="card">
  <div class="ch">
    <div><div class="ct">Supplier Directory</div><div class="cs"><?= count($suppliers) ?> suppliers</div></div>
  </div>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:8px;">
      <thead><tr>
        <th>Company</th><th>Country / City</th><th>Currency</th><th>Payment Terms</th>
        <th style="text-align:center;">Lead Time (quoted)</th><th style="text-align:center;">Catalog Items</th>
        <th style="text-align:center;">Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (empty($suppliers)): ?>
      <tr><td colspan="8" style="text-align:center;padding:52px;color:var(--t3);">No suppliers found</td></tr>
      <?php else: foreach ($suppliers as $s):
        $badge = $s->status==='Active' ? 'var(--green)' : ($s->status==='Blacklisted' ? 'var(--red)' : 'var(--t3)');
        $bg    = $s->status==='Active' ? 'var(--green-d)' : ($s->status==='Blacklisted' ? 'var(--red-d)' : 'var(--g2)');
      ?>
      <tr>
        <td style="font-weight:600;color:var(--t1);"><a href="<?= base_url('suppliers/view/'.$s->id) ?>" style="color:inherit;"><?= htmlspecialchars($s->company_name) ?></a></td>
        <td><?= htmlspecialchars($s->country) ?><?= $s->city ? ', '.htmlspecialchars($s->city) : '' ?></td>
        <td class="mono"><?= htmlspecialchars($s->currency) ?></td>
        <td style="color:var(--t3);font-size:11.5px;"><?= htmlspecialchars($s->payment_terms ?: '—') ?></td>
        <td class="mono" style="text-align:center;"><?= $s->lead_time_guarantee_days ? $s->lead_time_guarantee_days.'d' : '—' ?></td>
        <td class="mono" style="text-align:center;"><?= $s->item_count ?></td>
        <td style="text-align:center;"><span style="padding:3px 8px;border-radius:5px;font-size:10.5px;font-weight:700;color:<?= $badge ?>;background:<?= $bg ?>;"><?= $s->status ?></span></td>
        <td style="text-align:right;"><a href="<?= base_url('suppliers/view/'.$s->id) ?>" class="btn btn-ghost btn-sm">View</a></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
