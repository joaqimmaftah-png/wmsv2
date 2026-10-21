<?php
/** Pending Spare Parts board — one place where the store and the workshop see
 *  which requested parts have arrived and which jobs can now proceed. */
$st_badge = array(
  'Allocated'    => array('b-success',  '🟢', 'Allocated'),
  'Available'    => array('b-success',  '🟢', 'Available'),
  'Partial'      => array('b-warning',  '🟡', 'Partial'),
  'Pending'      => array('b-critical', '🔴', 'Pending'),
  'Unlinked'     => array('b-secondary','⚪', 'Unlinked'),
  'From Machine' => array('b-secondary','🟡', 'From Machine'),
);
if (!function_exists('sp_status_badge')) {
  function sp_status_badge($status) {
    global $st_badge;
    $s = isset($st_badge[$status]) ? $st_badge[$status] : array('b-secondary', '⚪', $status);
    return '<span class="badge '.$s[0].'" style="font-size:10px;">'.$s[1].' '.$s[2].'</span>';
  }
}
if (!function_exists('sp_qty')) {
  function sp_qty($n) { return rtrim((string)(rtrim((string)(number_format((float)$n, 2, '.', '')), '0')), '.'); }
}

$cur_status = isset($filter['status']) && $filter['status'] ? $filter['status'] : 'all';
$cur_search = isset($filter['search']) ? $filter['search'] : '';
$has_rows = !empty($groups) || !empty($unlinked);
?>

<!-- ═══ NEW ARRIVAL ALERTS ═══ -->
<?php if (!empty($new_alerts)): ?>
<div class="card mb-3" style="border-left:3px solid var(--green);">
  <div class="ch">
    <div><div class="ct">🔔 Spare Parts Received — Work Orders Notified</div>
    <div class="cs">Latest availability alerts · <a href="<?= base_url('spareparts/alerts') ?>" style="color:var(--blue);">all alerts →</a></div></div>
    <?php if ($summary['new_alerts'] > 0): ?>
    <?= form_open('spareparts/mark_all_read', array('style' => 'display:inline;margin:0;', 'onsubmit' => "return confirm('Mark all availability alerts as read?')")) ?>
      <button type="submit" class="ca" style="border:0;background:none;padding:0;cursor:pointer;">Mark all read</button>
    <?= form_close() ?>
    <?php endif; ?>
  </div>
  <div>
    <?php foreach ($new_alerts as $al): ?>
    <div class="row-list-item">
      <div style="width:8px;height:8px;border-radius:50%;flex-shrink:0;background:<?= $al->status === 'New' ? 'var(--green)' : 'var(--t4)' ?>;"></div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:12.5px;font-weight:600;color:var(--t1);">
          <?= $al->alert_type === 'Partial' ? '🟡' : '🟢' ?>
          <?= htmlspecialchars((string)($al->message)) ?>
        </div>
        <div style="font-size:10.5px;color:var(--t3);margin-top:2px;">
          <?= date('d M Y, H:i', strtotime($al->created_at)) ?>
          <?php if (!empty($al->received_by_name)): ?> · received by <?= htmlspecialchars((string)($al->received_by_name)) ?><?php endif; ?>
          · <a href="<?= base_url('workorders/view/'.$al->work_order_id) ?>" style="color:var(--blue);font-weight:600;"><?= htmlspecialchars((string)($al->wo_number)) ?></a>
        </div>
      </div>
      <?php if ($al->status === 'New'): ?>
      <?= form_open('spareparts/mark_read/'.$al->id, array('style' => 'display:inline;margin:0;')) ?>
        <button type="submit" class="btn btn-ghost btn-sm">Mark read</button>
      <?= form_close() ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ═══ KPI STRIP ═══ -->
<div class="grid" style="grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px;">
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--red);"></div>
    <span class="kpi-tag">Waiting Part Lines</span>
    <div class="kpi-num"><?= (int)$summary['lines'] ?></div>
    <div class="kpi-chip <?= $summary['pending_lines'] > 0 ? 'chip-dn' : 'chip-up' ?>">across <?= (int)$summary['wos'] ?> pending WO(s)</div>
  </div>
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--green);"></div>
    <span class="kpi-tag">Fully Covered Lines</span>
    <div class="kpi-num" style="color:var(--green);"><?= (int)$summary['available_lines'] ?></div>
    <div class="kpi-chip chip-up">stock available or allocated</div>
  </div>
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--blue);"></div>
    <span class="kpi-tag">Jobs That Can Proceed</span>
    <div class="kpi-num" style="color:var(--blue);"><?= (int)$summary['ready_wos'] ?></div>
    <div class="kpi-chip chip-up">all required parts available</div>
  </div>
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--amber);"></div>
    <span class="kpi-tag">New Alerts</span>
    <div class="kpi-num" style="color:<?= $summary['new_alerts'] > 0 ? 'var(--amber)' : 'var(--t3)' ?>;"><?= (int)$summary['new_alerts'] ?></div>
    <div class="kpi-chip chip-warn">arrival notifications</div>
  </div>
</div>

<!-- ═══ READY-TO-PROCEED STRIP ═══ -->
<?php if (!empty($ready_wos)): ?>
<div class="card mb-3" style="background:var(--green-fill);border:1px solid rgba(28,140,60,0.18);">
  <div style="padding:14px 22px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <div style="font-size:13px;font-weight:700;color:var(--green);white-space:nowrap;">✓ Ready to proceed:</div>
    <?php foreach ($ready_wos as $rw): ?>
    <a href="<?= base_url('workorders/view/'.$rw['work_order_id']) ?>" class="badge b-success" style="font-size:11px;text-decoration:none;">
      <?= htmlspecialchars((string)($rw['wo_number'])) ?> · <?= htmlspecialchars((string)($rw['registration_no'])) ?> · <?= (int)$rw['parts'] ?> part(s)
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ═══ FILTER BAR ═══ -->
<div class="card mb-3">
  <div style="padding:14px 22px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <?= form_open('spareparts', array('method' => 'get', 'style' => 'display:flex;gap:10px;flex-wrap:wrap;align-items:center;flex:1;')) ?>
      <select name="status" class="form-select" style="max-width:190px;">
        <option value="all" <?= $cur_status === 'all' ? 'selected' : '' ?>>All statuses</option>
        <?php foreach ($statuses as $s): ?>
        <option value="<?= $s ?>" <?= $cur_status === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="search" class="form-control" style="max-width:280px;"
             value="<?= htmlspecialchars((string)($cur_search)) ?>" placeholder="Search WO, part, item code, equipment…">
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="<?= base_url('spareparts') ?>" class="btn btn-ghost btn-sm">Reset</a>
    </form>
  </div>
</div>

<?php if (!$has_rows): ?>
<div class="card">
  <div style="padding:48px;text-align:center;color:var(--t3);font-size:13px;">
    <?php if ($cur_search !== '' || $cur_status !== 'all'): ?>
      No pending spare part lines match this filter.
    <?php else: ?>
      🎉 No pending spare part requests — every open work order has what it needs.
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ═══ DEMAND BOARD — grouped by part ═══ -->
<?php foreach ($groups as $gid => $g): $m = $g['material'];
  $on_order_qty = isset($on_order[$gid]) ? $on_order[$gid] : 0;
  $stock_color = $g['stock'] <= 0 ? 'var(--red)' : ($g['free'] <= 0 ? 'var(--amber)' : 'var(--green)');
?>
<div class="card mb-3">
  <div class="ch">
    <div>
      <div class="ct" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span class="mono" style="color:var(--blue);"><?= htmlspecialchars((string)($m->item_code)) ?></span>
        <?= htmlspecialchars((string)($m->description)) ?>
        <?php if (!empty($m->part_number_size)): ?><span class="mono" style="font-size:10.5px;color:var(--t3);font-weight:400;">P/N <?= htmlspecialchars((string)($m->part_number_size)) ?></span><?php endif; ?>
      </div>
      <div class="cs">
        Stock: <strong style="color:<?= $stock_color ?>;"><?= sp_qty($g['stock']) ?> <?= htmlspecialchars((string)($m->unit)) ?></strong>
        · Free (unreserved): <strong><?= sp_qty($g['free']) ?></strong>
        · Pending demand: <strong><?= sp_qty($g['demand']) ?></strong>
        <?php if ($on_order_qty > 0): ?> · On order: <strong style="color:var(--blue);"><?= sp_qty($on_order_qty) ?></strong><?php endif; ?>
        · Required by <?= count($g['lines']) ?> work order(s)
      </div>
    </div>
  </div>
  <table class="tbl">
    <thead>
      <tr><th>Work Order</th><th>Equipment</th><th>Spare Part</th><th>Required</th><th>Available</th><th>Status</th><th style="text-align:right;">Action</th></tr>
    </thead>
    <tbody>
      <?php foreach ($g['lines'] as $l):
        $needed = max(0, $l->qty_required - $l->qty_allocated - $l->qty_issued);
      ?>
      <tr>
        <td>
          <a href="<?= base_url('workorders/view/'.$l->work_order_id) ?>" style="color:var(--blue);font-weight:600;text-decoration:none;" class="mono"><?= htmlspecialchars((string)($l->wo_number)) ?></a>
          <div style="font-size:10px;color:var(--t3);"><?= htmlspecialchars((string)($l->wo_status)) ?> · <?= htmlspecialchars((string)($l->priority)) ?></div>
        </td>
        <td style="font-weight:500;"><?= htmlspecialchars((string)($l->registration_no ?: '—')) ?><div style="font-size:10px;color:var(--t3);"><?= htmlspecialchars((string)(trim((string)($l->brand.' '.$l->model)))) ?></div></td>
        <td><?= htmlspecialchars((string)($l->part_name)) ?><?php if (!empty($l->part_number)): ?> <span class="mono" style="font-size:10px;color:var(--t3);">P/N <?= htmlspecialchars((string)($l->part_number)) ?></span><?php endif; ?></td>
        <td class="mono fw-600"><?= sp_qty($l->qty_required) ?> <span style="color:var(--t4);font-size:10px;"><?= htmlspecialchars((string)($l->item_unit)) ?></span></td>
        <td class="mono fw-600" style="color:<?= $l->qty_available >= $l->qty_required && $l->qty_required > 0 ? 'var(--green)' : ($l->qty_available > 0 ? 'var(--amber)' : 'var(--red)') ?>;">
          <?= sp_qty($l->qty_available) ?>
        </td>
        <td>
          <?= sp_status_badge($l->avail_status) ?>
          <?php if ($l->qty_allocated > 0): ?><div style="font-size:10px;color:var(--t3);margin-top:3px;">reserved: <?= sp_qty($l->qty_allocated) ?><?php if ($l->qty_issued > 0): ?> · issued: <?= sp_qty($l->qty_issued) ?><?php endif; ?></div><?php endif; ?>
        </td>
        <td style="text-align:right;">
          <?php if ($can_write && $needed > 0 && $l->free_qty > 0): ?>
            <?= form_open('spareparts/allocate/'.$l->id, array('style' => 'display:flex;gap:6px;justify-content:flex-end;align-items:center;')) ?>
              <input type="number" name="qty" class="form-control" style="width:74px;padding:5px 8px;font-size:12px;"
                     min="0.01" max="<?= min($needed, $l->free_qty) ?>" step="0.01"
                     value="<?= sp_qty(min($needed, $l->free_qty)) ?>">
              <button type="submit" class="btn btn-primary btn-sm" title="Reserve this quantity from stock for this WO">Allocate</button>
            </form>
          <?php elseif ($needed > 0): ?>
            <span style="font-size:10.5px;color:var(--t3);">awaiting stock</span>
          <?php else: ?>
            <span style="font-size:10.5px;color:var(--green);font-weight:600;">covered</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<!-- ═══ ACTIVE ALLOCATIONS (issue / release) ═══ -->
<?php
$has_alloc_actions = false;
foreach ($groups as $g) foreach ($g['lines'] as $l) {
  if ($l->qty_allocated > 0) { $has_alloc_actions = true; break 2; }
}
?>
<?php if ($has_alloc_actions): ?>
<div class="card mb-3">
  <div class="ch"><div><div class="ct">Reserved Stock — Issue or Release</div>
  <div class="cs">Allocations made against pending work orders. Issuing books the stock movement; releasing frees the stock for other work orders.</div></div></div>
  <table class="tbl">
    <thead><tr><th>Part</th><th>Work Order</th><th>Qty Reserved</th><th>Reserved On</th><th style="text-align:right;">Action</th></tr></thead>
    <tbody>
    <?php foreach ($groups as $g) foreach ($g['lines'] as $l): if ($l->qty_allocated <= 0) continue;
      $alloc_rows = isset($line_allocs[(int)$l->id]) ? $line_allocs[(int)$l->id] : array();
      foreach ($alloc_rows as $a): if ($a->status !== 'Allocated') continue; ?>
      <tr>
        <td class="mono"><?= htmlspecialchars((string)($l->material->item_code)) ?> — <?= htmlspecialchars((string)($l->part_name)) ?></td>
        <td><a href="<?= base_url('workorders/view/'.$l->work_order_id) ?>" style="color:var(--blue);font-weight:600;" class="mono"><?= htmlspecialchars((string)($l->wo_number)) ?></a></td>
        <td class="mono fw-600"><?= sp_qty($a->qty_allocated) ?> <?= htmlspecialchars((string)($l->item_unit)) ?></td>
        <td style="font-size:11px;color:var(--t3);"><?= $a->allocated_at ? date('d M Y, H:i', strtotime($a->allocated_at)) : '—' ?></td>
        <td style="text-align:right;white-space:nowrap;">
          <?php if ($can_write): ?>
          <?= form_open('spareparts/issue/'.$a->id, array('style' => 'display:inline;margin:0;', 'onsubmit' => "return confirm('Issue the reserved stock against this work order? This books an ISSUE movement in inventory.')")) ?>
            <button type="submit" class="btn btn-primary btn-sm">Issue</button>
          <?= form_close() ?>
          <?= form_open('spareparts/release/'.$a->id, array('style' => 'display:inline;margin:0;', 'onsubmit' => "return confirm('Release this reservation? The stock becomes available for other work orders.')")) ?>
            <button type="submit" class="btn btn-ghost btn-sm">Release</button>
          <?= form_close() ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ═══ UNLINKED LINES ═══ -->
<?php if (!empty($unlinked)): ?>
<div class="card mb-3" style="border-left:3px solid var(--amber);">
  <div class="ch"><div><div class="ct">⚪ Not Linked to Inventory</div>
  <div class="cs">These requested parts have no matching inventory item (item code missing or not in the catalogue), so arrivals cannot be matched automatically. Edit the work order and set the correct item code.</div></div></div>
  <table class="tbl">
    <thead><tr><th>Work Order</th><th>Equipment</th><th>Spare Part</th><th>Entered Code</th><th>Required</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($unlinked as $l): ?>
      <tr>
        <td><a href="<?= base_url('workorders/view/'.$l->work_order_id) ?>" style="color:var(--blue);font-weight:600;" class="mono"><?= htmlspecialchars((string)($l->wo_number)) ?></a></td>
        <td><?= htmlspecialchars((string)($l->registration_no ?: '—')) ?></td>
        <td><?= htmlspecialchars((string)($l->part_name)) ?></td>
        <td class="mono" style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)($l->item_code ?: '—')) ?><?php if (!empty($l->part_number)): ?> / <?= htmlspecialchars((string)($l->part_number)) ?><?php endif; ?></td>
        <td class="mono fw-600"><?= sp_qty($l->qty_required) ?></td>
        <td><?= sp_status_badge('Unlinked') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
