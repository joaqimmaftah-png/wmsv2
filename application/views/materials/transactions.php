<?php $f = isset($filter) ? $filter : array(); ?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <form method="get" class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <select name="type" class="form-select" style="width:140px;">
      <option value="">All Types</option>
      <option value="RECEIVE" <?= (!empty($f['type'])&&$f['type']==='RECEIVE')?'selected':'' ?>>Receive (+)</option>
      <option value="ISSUE"   <?= (!empty($f['type'])&&$f['type']==='ISSUE')  ?'selected':'' ?>>Issue (−)</option>
    </select>
    <input type="text" name="item_code" class="form-control" style="width:140px;"
      placeholder="Item Code…" value="<?= htmlspecialchars((string)($f['item_code']??'')) ?>">
    <input type="date" name="date_from" class="form-control" style="width:150px;"
      value="<?= htmlspecialchars((string)($f['date_from']??'')) ?>">
    <input type="date" name="date_to" class="form-control" style="width:150px;"
      value="<?= htmlspecialchars((string)($f['date_to']??'')) ?>">
    <button type="submit" class="btn btn-primary btn-sm btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Filter
    </button>
    <a href="<?= base_url('materials/transactions') ?>" class="btn btn-ghost btn-sm">Reset</a>
  </form>
  <a href="<?= base_url('materials/inventory') ?>" class="btn btn-ghost btn-sm">&larr; Back to Inventory</a>
</div>

<div class="card">
  <div class="ch">
    <div><div class="ct">Transaction History</div><div class="cs"><?= count($transactions) ?> movements<?= count($transactions) >= 200 ? ' (latest 200 — narrow the filter to see older ones)' : '' ?></div></div>
  </div>
  <?php if (empty($transactions)): ?>
  <div style="text-align:center;padding:64px;color:var(--t3);">
    <div style="font-size:36px;margin-bottom:12px;">📋</div>
    <div>No transactions found</div>
    <div style="font-size:11px;margin-top:4px;color:var(--t4);">
      Movements are recorded by Receive / Issue, by opening stock on a new item,
      by stock changes made in the item form, and by Excel imports.
    </div>
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:8px;">
      <thead>
        <tr>
          <th>Date / Time</th><th>Type</th><th>Item Code</th><th>Description</th>
          <th>Category</th><th style="text-align:right;">Qty</th>
          <th style="text-align:right;">Before</th><th style="text-align:right;">After</th>
          <th>Notes</th><th>By</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($transactions as $t): ?>
        <tr>
          <td class="mono" style="font-size:11px;color:var(--t3);white-space:nowrap;">
            <?php $ts = !empty($t->created_at) ? strtotime($t->created_at) : false; ?>
            <?php if ($ts): ?>
              <?= date('d M Y', $ts) ?>
              <div style="font-size:10px;color:var(--t4);"><?= date('H:i', $ts) ?></div>
            <?php else: ?>
              &mdash;
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $t->type==='RECEIVE'?'b-success':'b-warning' ?>">
              <?= $t->type==='RECEIVE' ? '+ Receive' : '− Issue' ?>
            </span>
          </td>
          <td style="font-weight:600;color:var(--blue);font-family:inherit,monospace;font-size:12px;">
            <?= htmlspecialchars((string)($t->item_code??'')) ?>
          </td>
          <td style="font-size:12.5px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= htmlspecialchars((string)($t->description??'')) ?>
          </td>
          <td><span class="badge b-primary" style="font-size:9.5px;"><?= htmlspecialchars((string)($t->category??'')) ?></span></td>
          <td style="text-align:right;font-family:inherit,monospace;font-weight:700;color:<?= $t->type==='RECEIVE'?'var(--green)':'var(--amber)' ?>;">
            <?= $t->type==='RECEIVE'?'+':'-' ?><?= abs($t->qty) ?>
            <span style="font-size:9px;color:var(--t4);"><?= htmlspecialchars((string)($t->unit??'')) ?></span>
          </td>
          <td style="text-align:right;font-family:inherit,monospace;font-size:12px;color:var(--t3);">
            <?= isset($t->stock_before) ? $t->stock_before : '—' ?>
          </td>
          <td style="text-align:right;font-family:inherit,monospace;font-size:12px;font-weight:600;">
            <?= isset($t->stock_after) ? $t->stock_after : '—' ?>
          </td>
          <td style="font-size:11.5px;color:var(--t3);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= htmlspecialchars((string)($t->notes??'')) ?>
          </td>
          <td style="font-size:11.5px;color:var(--t3);"><?= htmlspecialchars((string)($t->user_name??'')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
