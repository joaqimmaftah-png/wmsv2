<?php
$overdueFlag = $order->expected_delivery_date && $order->expected_delivery_date < date('Y-m-d') && !in_array($order->status, array('Received at Store','Verified & Closed','Cancelled'));
$col = $order->status==='Verified & Closed' ? 'var(--green)' : ($order->status==='Cancelled' ? 'var(--t3)' : ($overdueFlag ? 'var(--red)' : 'var(--blue)'));
$bg  = $order->status==='Verified & Closed' ? 'var(--green-d)' : ($order->status==='Cancelled' ? 'var(--g2)' : ($overdueFlag ? 'var(--red-d)' : 'rgba(0,113,227,0.12)'));
$pipeline = array('Pending Approval','Sent to Supplier','Confirmed','In Transit','Customs Clearance','Received at Store','Verified & Closed');
$curIdx = array_search($order->status, $pipeline);
// Anything still outstanding on an open order can be booked in, whatever
// stage the order has reached (orders received before receipt documents existed carry an
// outstanding balance on their item rows and have no receipt documents).
$outstanding_total = 0;
foreach ($items as $it) { $outstanding_total += (float)$it->qty_outstanding; }
$can_receive = $outstanding_total > 0.0001 && !in_array($order->status, array('Cancelled','Verified & Closed'));
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="flex ai-c gap-10">
      <div class="page-heading" style="font-size:16px;"><?= htmlspecialchars((string)($order->order_no)) ?></div>
      <span style="padding:3px 8px;border-radius:5px;font-size:10.5px;font-weight:700;color:<?= $col ?>;background:<?= $bg ?>;"><?= htmlspecialchars((string)($order->status)) ?></span>
      <?php if ($overdueFlag): ?><span style="padding:3px 8px;border-radius:5px;font-size:10.5px;font-weight:700;color:var(--red);background:var(--red-d);">OVERDUE</span><?php endif; ?>
    </div>
    <div class="cs"><a href="<?= base_url('suppliers/view/'.$order->supplier_id) ?>" style="color:var(--blue);"><?= htmlspecialchars((string)($order->supplier_name)) ?></a> &middot; <?= htmlspecialchars((string)($order->country)) ?> &middot; <?= htmlspecialchars((string)($order->shipping_method)) ?></div>
  </div>
  <a href="<?= base_url('orders') ?>" class="btn btn-ghost btn-sm">&larr; Back to Orders</a>
</div>

<!-- Status pipeline -->
<?php if ($order->status !== 'Cancelled'): ?>
<div class="card mb-4" style="padding:18px 20px;">
  <div style="display:flex;align-items:center;overflow-x:auto;gap:4px;">
    <?php foreach ($pipeline as $i => $st): $done = $i <= $curIdx; ?>
    <div style="display:flex;align-items:center;flex:1;min-width:90px;">
      <div style="text-align:center;flex:1;">
        <div style="width:22px;height:22px;border-radius:50%;margin:0 auto 4px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;background:<?= $done ? 'var(--blue)' : 'var(--g2)' ?>;<?= $done ? '' : 'color:var(--t3);' ?>"><?= $i+1 ?></div>
        <div style="font-size:9.5px;color:<?= $done ? 'var(--t1)' : 'var(--t3)' ?>;font-weight:<?= $done ? 600 : 400 ?>;white-space:nowrap;"><?= $st ?></div>
      </div>
      <?php if ($i < count($pipeline)-1): ?><div style="height:2px;flex:0.5;background:<?= $i < $curIdx ? 'var(--blue)' : 'var(--g2)' ?>;margin-bottom:14px;"></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Order Date</span><div class="kpi-num" style="font-size:16px;"><?= date('d M Y', strtotime($order->order_date)) ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:<?= $overdueFlag?'var(--red)':'var(--cyan)' ?>"></div>
    <span class="kpi-tag">Expected Delivery</span><div class="kpi-num" style="font-size:16px;"><?= $order->expected_delivery_date ? date('d M Y', strtotime($order->expected_delivery_date)) : '—' ?></div>
    <div class="cs" style="margin-top:4px;">Est. ~<?= $lead['days'] ?>d (<?= $lead['source']==='history' ? $lead['sample'].' past orders' : $lead['source'] ?>)</div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Total Cost</span><div class="kpi-num" style="font-size:16px;"><?= number_format((float)($order->total_cost),2) ?> <?= htmlspecialchars((string)($order->currency)) ?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Tracking #</span><div class="kpi-num" style="font-size:16px;"><?= htmlspecialchars((string)($order->tracking_number ?: '—')) ?></div>
    <div class="cs" style="margin-top:4px;"><?= htmlspecialchars((string)($order->carrier ?: 'No carrier set')) ?></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1.3fr 1fr;gap:16px;">
<div>
  <!-- Items -->
  <div class="card mb-4">
    <div class="ch"><div><div class="ct">Order Items</div><div class="cs"><?= count($items) ?> item(s)</div></div></div>
    <div style="overflow-x:auto;">
      <table class="tbl">
        <thead><tr><th>Description</th><th>Part No.</th><th style="text-align:right;">Ordered</th><th style="text-align:right;">Received</th><th style="text-align:right;">Outstanding</th><th style="text-align:right;">Unit Price</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): $out = (float)$it->qty_outstanding; ?>
        <tr>
          <td><?= htmlspecialchars((string)($it->description)) ?></td>
          <td class="mono"><?= htmlspecialchars((string)($it->part_number ?: '—')) ?></td>
          <td class="mono" style="text-align:right;"><?= number_format((float)($it->qty_ordered),2) ?> <?= htmlspecialchars((string)($it->unit)) ?></td>
          <td class="mono" style="text-align:right;<?= $out > 0 ? 'color:var(--amber);' : '' ?>"><?= number_format((float)($it->qty_previously_received),2) ?></td>
          <td class="mono" style="text-align:right;<?= $out > 0 ? 'font-weight:700;' : '' ?>"><?= $out > 0 ? number_format((float)($out),2) : '✓' ?></td>
          <td class="mono" style="text-align:right;"><?= $it->unit_price ? number_format((float)($it->unit_price),2) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Goods Receipts: one row per delivery -->
  <?php if (!empty($receipts)): ?>
  <div class="card mb-4">
    <div class="ch"><div><div class="ct">Goods Receipts</div><div class="cs"><?= count($receipts) ?> delivery(ies) booked in</div></div></div>
    <div style="overflow-x:auto;">
      <table class="tbl">
        <thead><tr><th>GRN</th><th>Date</th><th>Received By</th><th>Discrepancy</th><th style="text-align:right;"></th></tr></thead>
        <tbody>
        <?php foreach ($receipts as $r): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars((string)($r->grn_no)) ?></td>
          <td class="mono"><?= date('d M Y', strtotime($r->receipt_date)) ?></td>
          <td><?= htmlspecialchars((string)(trim((string)(($r->first_name ?? '').' '.($r->last_name ?? ''))) ?: '—')) ?></td>
          <td><?= $r->discrepancy_flag !== 'None'
                ? '<span style="color:var(--amber);font-weight:700;">'.htmlspecialchars((string)($r->discrepancy_flag)).'</span>'
                : '<span style="color:var(--t3);">None</span>' ?></td>
          <td style="text-align:right;"><a href="<?= base_url('orders/grn/'.$order->id.'/'.$r->id) ?>" target="_blank" class="btn btn-ghost btn-sm">Print GRN</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($order->discrepancy_flag !== 'None'): ?>
  <div class="card mb-4" style="border-color:var(--amber);">
    <div style="padding:14px 18px;">
      <div style="font-weight:700;color:var(--amber);font-size:12.5px;">⚠ Discrepancy Reported: <?= htmlspecialchars((string)($order->discrepancy_flag)) ?></div>
      <div style="font-size:12px;color:var(--t3);margin-top:4px;"><?= htmlspecialchars((string)($order->discrepancy_notes ?: '')) ?></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Documents -->
  <div class="card">
    <div class="ch"><div><div class="ct">Documents</div><div class="cs">Delivery notes, invoices, GRN</div></div></div>
    <div style="padding:6px 18px 16px;">
      <a href="<?= base_url('orders/grn/'.$order->id) ?>" target="_blank" class="btn btn-ghost btn-sm" style="margin-bottom:10px;">📄 View / Print GRN</a>
      <?php if (empty($documents)): ?>
      <div style="color:var(--t3);font-size:12px;padding:6px 0;">No documents uploaded yet.</div>
      <?php else: foreach ($documents as $d): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--g2);font-size:12px;">
        <span><strong><?= htmlspecialchars((string)($d->doc_type)) ?></strong> — <?= htmlspecialchars((string)($d->original_name)) ?></span>
        <a href="<?= base_url('orders/download_document/'.$d->id) ?>" class="btn btn-ghost btn-sm">Download</a>
      </div>
      <?php endforeach; endif; ?>

      <?= form_open_multipart('orders/upload_document/'.$order->id) ?>
      <div class="flex ai-c gap-10" style="margin-top:12px;flex-wrap:wrap;">
        <select name="doc_type" class="form-select" style="width:140px;">
          <option>Delivery Note</option><option>Invoice</option><option>Other</option>
        </select>
        <input type="file" name="document" required>
        <button type="submit" class="btn btn-ghost btn-sm">Upload</button>
      </div>
      <?= form_close() ?>
    </div>
  </div>
</div>

<div>
  <!-- Status update -->
  <div class="card mb-4">
    <div class="ch"><div><div class="ct">Update Status</div></div></div>
    <div style="padding:6px 18px 16px;">
      <?php if (in_array($order->status, array('Received at Store','Verified & Closed','Cancelled'))): ?>
        <?php if ($order->status === 'Received at Store'): ?>
        <?php if ($can_receive): ?>
        <div style="border:1px solid var(--amber);border-radius:8px;padding:10px 12px;margin-bottom:10px;font-size:11.5px;color:var(--t2);">
          <?= number_format((float)($outstanding_total),2) ?> unit(s) are still outstanding on this order — book them in before verifying and closing it.
        </div>
        <a href="<?= base_url('orders/receive/'.$order->id) ?>" class="btn btn-ghost btn-sm" style="width:100%;display:block;text-align:center;margin-bottom:10px;">Receive Outstanding Balance</a>
        <?php endif; ?>
        <?= form_open('orders/update_status/'.$order->id) ?>
        <input type="hidden" name="status" value="Verified & Closed">
        <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Verify &amp; Close Order</button>
        <?= form_close() ?>
        <?php else: ?>
        <div style="color:var(--t3);font-size:12px;">This order is <?= strtolower((string)($order->status)) ?>.</div>
        <?php endif; ?>
      <?php elseif ($order->status === 'Customs Clearance' || $order->status === 'In Transit'): ?>
        <a href="<?= base_url('orders/receive/'.$order->id) ?>" class="btn btn-primary btn-sm" style="width:100%;display:block;text-align:center;margin-bottom:10px;">Receive Items at Store</a>
        <?= form_open('orders/update_status/'.$order->id) ?>
        <?php /* Guided pipeline (Wave 3): only the statuses the process allows next.
                 "Received at Store" is reached by booking a delivery in, above. */ ?>
        <select name="status" class="form-select" style="margin-bottom:8px;">
          <?php foreach ($next_statuses as $s): if ($s === 'Received at Store') continue; ?>
          <option value="<?= $s ?>" <?= $s===$order->status?'selected':'' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="tracking_number" class="form-control" placeholder="Tracking Number" value="<?= htmlspecialchars((string)($order->tracking_number ?? '')) ?>" style="margin-bottom:8px;">
        <input type="text" name="carrier" class="form-control" placeholder="Carrier" value="<?= htmlspecialchars((string)($order->carrier ?? '')) ?>" style="margin-bottom:8px;">
        <input type="text" name="notes" class="form-control" placeholder="Notes (optional)" style="margin-bottom:8px;">
        <button type="submit" class="btn btn-ghost btn-sm" style="width:100%;">Update Status</button>
        <?= form_close() ?>
      <?php else: ?>
        <?php if (empty($next_statuses)): ?>
        <div style="color:var(--t3);font-size:12px;">This order's pipeline is complete — its status no longer changes.</div>
        <?php else: ?>
        <?= form_open('orders/update_status/'.$order->id) ?>
        <select name="status" class="form-select" style="margin-bottom:8px;">
          <?php foreach ($next_statuses as $s): ?>
          <option value="<?= $s ?>"><?= $s ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="tracking_number" class="form-control" placeholder="Tracking Number" value="<?= htmlspecialchars((string)($order->tracking_number ?? '')) ?>" style="margin-bottom:8px;">
        <input type="text" name="carrier" class="form-control" placeholder="Carrier" value="<?= htmlspecialchars((string)($order->carrier ?? '')) ?>" style="margin-bottom:8px;">
        <input type="text" name="notes" class="form-control" placeholder="Notes (optional)" style="margin-bottom:8px;">
        <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Update Status</button>
        <?= form_close() ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- History -->
  <div class="card">
    <div class="ch"><div><div class="ct">Status History</div></div></div>
    <div style="padding:6px 18px 16px;">
      <?php foreach ($history as $h): ?>
      <div style="padding:8px 0;border-bottom:1px solid var(--g2);">
        <div style="font-size:12px;font-weight:600;color:var(--t1);"><?= htmlspecialchars((string)($h->status)) ?></div>
        <div style="font-size:10.5px;color:var(--t3);"><?= date('d M Y, H:i', strtotime($h->changed_at)) ?><?= $h->first_name ? ' · '.htmlspecialchars((string)($h->first_name)) : '' ?></div>
        <?php if ($h->notes): ?><div style="font-size:11px;color:var(--t3);margin-top:2px;"><?= htmlspecialchars((string)($h->notes)) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>
