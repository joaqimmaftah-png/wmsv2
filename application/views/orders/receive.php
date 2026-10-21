<div style="max-width:920px;margin:0 auto;">
<div class="card">
  <div class="ch">
    <div><div class="ct">Receive Order <?= htmlspecialchars((string)($order->order_no)) ?></div><div class="cs">Enter what arrived in <strong>this delivery</strong> — catalogued items go into stock, and the balance stays outstanding on the order</div></div>
    <a href="<?= base_url('orders/view/'.$order->id) ?>" class="ca">&larr; Back</a>
  </div>
  <div style="padding:24px 28px 32px;">
    <?= form_open('orders/receive/'.$order->id) ?>
    <input type="hidden" name="submit_token" value="<?= htmlspecialchars((string)($submit_token)) ?>">

    <table class="tbl" style="margin-bottom:12px;">
      <thead><tr>
        <th>Description</th><th>Part No.</th>
        <th style="text-align:right;">Ordered</th>
        <th style="text-align:right;">Already received</th>
        <th style="text-align:right;">Outstanding</th>
        <th style="text-align:right;width:150px;">This delivery</th>
      </tr></thead>
      <tbody>
      <?php $any_open = false; foreach ($items as $it): ?>
      <?php $open = (float)$it->qty_outstanding > 0; if ($open) $any_open = true; ?>
      <tr<?= $open ? '' : ' style="opacity:.55;"' ?>>
        <td>
          <?= htmlspecialchars((string)($it->description)) ?>
          <?php if (!$it->material_id): ?><span style="color:var(--amber);font-size:10px;"> (not in inventory catalog — won't update stock)</span><?php endif; ?>
        </td>
        <td class="mono"><?= htmlspecialchars((string)($it->part_number ?: '—')) ?></td>
        <td class="mono" style="text-align:right;"><?= number_format((float)($it->qty_ordered),2) ?> <?= htmlspecialchars((string)($it->unit)) ?></td>
        <td class="mono" style="text-align:right;"><?= number_format((float)($it->qty_previously_received),2) ?></td>
        <td class="mono" style="text-align:right;<?= $open ? 'font-weight:700;' : '' ?>">
          <?= number_format((float)($it->qty_outstanding),2) ?>
          <?php if ($open): ?><span style="font-size:10px;color:var(--t3);"> left</span><?php endif; ?>
        </td>
        <td style="text-align:right;">
          <?php if ($open): ?>
          <input type="number" step="1" min="0" max="<?= (float)$it->qty_outstanding ?>" name="qty_delivery[<?= $it->id ?>]" class="form-control" style="text-align:right;" placeholder="0" value="">
          <?php else: ?>
          <span style="font-size:11px;color:var(--green);font-weight:700;">✓ complete</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if (!$any_open): ?>
    <div style="padding:12px 14px;border:1px solid var(--g2);border-radius:8px;font-size:12.5px;margin-bottom:20px;">
      Every line on this order has been received in full. There is nothing left to book in.
    </div>
    <?php else: ?>
    <div style="font-size:11.5px;color:var(--t3);margin-bottom:20px;">
      Leave a line at 0 if it did not arrive in this delivery. A delivery cannot exceed the outstanding quantity —
      if more arrived than was ordered, record the ordered quantity now and raise the excess separately.
    </div>
    <?php endif; ?>

    <div class="sec-head">Discrepancy Check</div>
    <div class="form-grid cols-2" style="margin-bottom:24px;">
      <div>
        <label class="form-label">Any Issue with This Delivery?</label>
        <select name="discrepancy_flag" class="form-select">
          <option value="None">None — everything matches</option>
          <option value="Shortage">Shortage (received less than ordered)</option>
          <option value="Damage">Damage</option>
          <option value="Wrong Item">Wrong Item</option>
        </select>
      </div>
      <div>
        <label class="form-label">Discrepancy Notes</label>
        <input type="text" name="discrepancy_notes" class="form-control" placeholder="Describe the issue, if any">
      </div>
    </div>

    <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;border-top:1px solid var(--sep);padding-top:16px;">
      <a href="<?= base_url('orders/view/'.$order->id) ?>" class="btn btn-ghost">Cancel</a>
      <button type="submit" class="btn btn-primary"<?= $any_open ? '' : ' disabled' ?>>✓ Confirm Delivery &amp; Update Stock</button>
    </div>
    <?= form_close() ?>
  </div>
</div>
</div>
