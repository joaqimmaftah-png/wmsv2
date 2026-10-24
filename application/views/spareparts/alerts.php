<?php
/** Spare part availability alerts — the notification history generated when
 *  received stock matches a part that a pending work order is waiting for. */
$cur_status = isset($filter['status']) && $filter['status'] !== '' ? $filter['status'] : '';
?>

<div class="card mb-3">
  <div style="padding:14px 22px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
    <?= form_open('spareparts/alerts', array('method' => 'get', 'style' => 'display:flex;gap:10px;align-items:center;')) ?>
      <select name="status" class="form-select" style="max-width:180px;">
        <option value="" <?= $cur_status === '' ? 'selected' : '' ?>>All alerts</option>
        <option value="New" <?= $cur_status === 'New' ? 'selected' : '' ?>>New</option>
        <option value="Read" <?= $cur_status === 'Read' ? 'selected' : '' ?>>Read</option>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    </form>
    <?php if ($new_count > 0): ?>
    <?= form_open('spareparts/mark_all_read', array('style' => 'display:inline;margin:0;', 'onsubmit' => "return confirm('Mark all new alerts as read?')")) ?>
      <button type="submit" class="btn btn-ghost btn-sm">✓ Mark all as read (<?= (int)$new_count ?>)</button>
    <?= form_close() ?>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="ch">
    <div><h1 class="ct">Availability Alerts</h1>
    <div class="cs">Generated automatically when a stock receipt matches a spare part required by a pending work order (matched on item code)</div></div>
  </div>

  <?php if (empty($alerts)): ?>
  <div style="padding:48px;text-align:center;color:var(--t3);font-size:13px;">
    No availability alerts yet.<br>
    <span style="font-size:11.5px;">When the store receives an item that a pending work order is waiting for, the notification appears here.</span>
  </div>
  <?php else: ?>
  <div>
    <?php foreach ($alerts as $al): ?>
    <div class="row-list-item" style="align-items:flex-start;">
      <div style="width:34px;height:34px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:15px;
                  background:<?= $al->alert_type === 'Partial' ? 'var(--amber-fill)' : 'var(--green-fill)' ?>;">
        <?= $al->alert_type === 'Partial' ? '🟡' : '🟢' ?>
      </div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:12.5px;font-weight:600;color:var(--t1);">
          <?= $al->alert_type === 'Partial' ? 'Spare Part Partially Available' : 'Spare Part Available' ?>
          <?php if ($al->status === 'New'): ?><span class="badge b-primary" style="font-size:9px;margin-left:6px;">NEW</span><?php endif; ?>
        </div>
        <div style="font-size:12px;color:var(--t2);margin-top:2px;"><?= htmlspecialchars((string)($al->message)) ?></div>
        <div style="font-size:10.5px;color:var(--t3);margin-top:4px;">
          <?= date('d M Y, H:i', strtotime($al->created_at)) ?>
          · received qty <?= rtrim((string)(rtrim((string)(number_format((float)$al->qty_received, 2, '.', '')), '0')), '.') ?>
          · stock after <?= rtrim((string)(rtrim((string)(number_format((float)$al->stock_after, 2, '.', '')), '0')), '.') ?>
          <?php if (!empty($al->received_by_name)): ?> · received by <?= htmlspecialchars((string)($al->received_by_name)) ?><?php endif; ?>
          <?php if ($al->status === 'Read' && $al->read_at): ?> · read <?= date('d M, H:i', strtotime($al->read_at)) ?><?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
        <a href="<?= base_url('workorders/view/'.$al->work_order_id) ?>" class="btn btn-ghost btn-sm mono"><?= htmlspecialchars((string)($al->wo_number ?: 'WO')) ?></a>
        <?php if ($al->status === 'New'): ?>
        <?= form_open('spareparts/mark_read/'.$al->id, array('style' => 'display:inline;margin:0;')) ?>
          <button type="submit" class="btn btn-primary btn-sm">Mark read</button>
        <?= form_close() ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
