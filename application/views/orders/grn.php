<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GRN — <?= htmlspecialchars((string)($receipt ? $receipt->grn_no : $order->order_no)) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,Helvetica,sans-serif;font-size:9pt;background:#d1d5db;color:#000;
  -webkit-print-color-adjust:exact;print-color-adjust:exact;}
#tb{background:#080810;padding:11px 26px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200;gap:12px;}
.tb-brand{font-size:13px;font-weight:700;color:#fff;}
.tb-sub{font-size:10px;color:rgba(255,255,255,0.35);}
.btn-bk{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.65);
  border:1px solid rgba(255,255,255,0.12);padding:8px 16px;border-radius:100px;font-size:12px;cursor:pointer;text-decoration:none;}
.btn-pr{display:inline-flex;align-items:center;gap:7px;background:#0a84ff;color:#fff;border:none;padding:9px 20px;
  border-radius:100px;font-size:12px;font-weight:700;cursor:pointer;}
.wrap{padding:24px;display:flex;flex-direction:column;align-items:center;}
.doc{width:210mm;background:#fff;box-shadow:0 4px 40px rgba(0,0,0,0.2);padding:14mm;font-size:9pt;color:#000;}
table{border-collapse:collapse;width:100%;}
td,th{border:1px solid #444;padding:5px 7px;vertical-align:middle;font-size:8.5pt;}
.hdr{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;border-bottom:2px solid #000;padding-bottom:10px;}
.hdr h1{font-size:16pt;letter-spacing:1px;}
.hdr .meta{font-size:8.5pt;text-align:right;}
.sec{margin:16px 0 6px;font-weight:bold;font-size:9pt;text-transform:uppercase;letter-spacing:.4px;}
.kv{display:grid;grid-template-columns:1fr 1fr;gap:4px 24px;font-size:9pt;margin-bottom:10px;}
.kv div span{color:#555;}
.sig{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-top:36px;}
.sig div{text-align:center;}
.sig .line{border-top:1px solid #000;margin-top:36px;padding-top:4px;font-size:8pt;}
@media print{
  @page{size:A4 portrait;margin:0;}
  body{background:#fff;}
  #tb{display:none!important;}
  .wrap{padding:0;}
  .doc{box-shadow:none;width:100%;padding:14mm;}
}
</style>
</head>
<body>
<div id="tb">
  <div><div class="tb-brand">Goods Received Note</div><div class="tb-sub">Order <?= htmlspecialchars((string)($order->order_no)) ?><?= $receipt ? ' · '.htmlspecialchars((string)($receipt->grn_no)) : '' ?></div></div>
  <div style="display:flex;gap:10px;">
    <a href="<?= base_url('orders/view/'.$order->id) ?>" class="btn-bk">&larr; Back to Order</a>
    <button class="btn-pr" onclick="window.print()">🖨 Print / Save as PDF</button>
  </div>
</div>

<div class="wrap">
<div class="doc">
  <div class="hdr">
    <div>
      <h1>GOODS RECEIVED NOTE</h1>
      <div style="font-size:9pt;color:#444;">Workshop Store Department</div>
    </div>
    <div class="meta">
      <div><strong>GRN Ref:</strong> <?= htmlspecialchars((string)($receipt ? $receipt->grn_no : 'GRN-'.$order->order_no)) ?></div>
      <div><strong>Date Received:</strong> <?php
        $grn_date = $receipt ? $receipt->receipt_date : $order->actual_delivery_date;
        echo $grn_date ? date('d M Y', strtotime($grn_date)) : date('d M Y');
      ?></div>
      <?php if ($receipt): ?>
      <div><strong>Received By:</strong> <?= htmlspecialchars((string)(trim((string)(($receipt->first_name ?? '').' '.($receipt->last_name ?? ''))) ?: '—')) ?></div>
      <?php else: ?>
      <div style="color:#a00;"><strong>No delivery recorded</strong> — order-level document</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="sec">Order &amp; Supplier Details</div>
  <div class="kv">
    <div><span>Order No:</span> <strong><?= htmlspecialchars((string)($order->order_no)) ?></strong></div>
    <div><span>Supplier:</span> <strong><?= htmlspecialchars((string)(($receipt ? $receipt->supplier_name : $order->supplier_name) ?: '—')) ?></strong></div>
    <div><span>Country of Origin:</span> <?= htmlspecialchars((string)(($receipt ? $receipt->country : $order->country) ?: '—')) ?></div>
    <div><span>Shipping Method:</span> <?= htmlspecialchars((string)(($receipt ? $receipt->shipping_method : $order->shipping_method) ?: '—')) ?></div>
    <div><span>Order Date:</span> <?= date('d M Y', strtotime(($receipt ? $receipt->order_date : $order->order_date))) ?></div>
    <div><span>Tracking No:</span> <?= htmlspecialchars((string)(($receipt ? $receipt->tracking_number : $order->tracking_number) ?: '—')) ?></div>
  </div>

  <div class="sec">Items Received<?= $receipt ? ' — This Delivery' : '' ?></div>
  <?php if ($receipt): ?>
  <table>
    <thead><tr>
      <th>#</th><th>Description</th><th>Part No.</th>
      <th>Ordered</th><th>Received Before</th><th>Delivered Now</th><th>Outstanding After</th>
    </tr></thead>
    <tbody>
    <?php $t_ordered = 0; $t_before = 0; $t_now = 0; $t_out = 0; $any_out = false; ?>
    <?php foreach ($lines as $i => $l): ?>
    <?php $t_ordered += (float)$l->qty_ordered; $t_before += (float)$l->qty_previously_received;
          $t_now += (float)$l->qty_delivered; $t_out += (float)$l->qty_outstanding_after;
          if ((float)$l->qty_outstanding_after > 0) $any_out = true; ?>
    <tr>
      <td style="text-align:center;"><?= $i+1 ?></td>
      <td><?= htmlspecialchars((string)($l->description ?: '—')) ?></td>
      <td><?= htmlspecialchars((string)(isset($l->part_number) && $l->part_number !== null && $l->part_number !== '' ? $l->part_number : '—')) ?></td>
      <td style="text-align:center;"><?= number_format((float)($l->qty_ordered),2) ?> <?= htmlspecialchars((string)($l->unit ?: '')) ?></td>
      <td style="text-align:center;"><?= number_format((float)($l->qty_previously_received),2) ?></td>
      <td style="text-align:center;font-weight:bold;"><?= number_format((float)($l->qty_delivered),2) ?></td>
      <td style="text-align:center;<?= (float)$l->qty_outstanding_after > 0 ? 'color:#b00;font-weight:bold;' : '' ?>">
        <?= number_format((float)($l->qty_outstanding_after),2) ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($lines): ?>
    <tr style="font-weight:bold;background:#f3f4f6;">
      <td colspan="3" style="text-align:right;">Totals</td>
      <td style="text-align:center;"><?= number_format((float)($t_ordered),2) ?></td>
      <td style="text-align:center;"><?= number_format((float)($t_before),2) ?></td>
      <td style="text-align:center;"><?= number_format((float)($t_now),2) ?></td>
      <td style="text-align:center;<?= $any_out ? 'color:#b00;' : '' ?>"><?= number_format((float)($t_out),2) ?></td>
    </tr>
    <?php endif; ?>
    </tbody>
  </table>

  <?php if ($any_out): ?>
  <div style="margin-top:10px;font-size:9pt;border:1px solid #b00;padding:8px;background:#fff5f5;">
    <strong>Part delivery.</strong> This GRN covers the goods that have arrived. The quantities under
    "Outstanding After" have not been delivered yet and remain due on order <?= htmlspecialchars((string)($order->order_no)) ?>.
  </div>
  <?php else: ?>
  <div style="margin-top:10px;font-size:9pt;">Order received in full.</div>
  <?php endif; ?>

  <?php else: ?>
  <table>
    <thead><tr><th>#</th><th>Description</th><th>Part No.</th><th>Ordered</th><th>Received</th><th>Variance</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i => $it): $var = $it->qty_received - $it->qty_ordered; ?>
    <tr>
      <td style="text-align:center;"><?= $i+1 ?></td>
      <td><?= htmlspecialchars((string)($it->description)) ?></td>
      <td><?= htmlspecialchars((string)($it->part_number ?: '—')) ?></td>
      <td style="text-align:center;"><?= number_format((float)($it->qty_ordered),2) ?> <?= htmlspecialchars((string)($it->unit)) ?></td>
      <td style="text-align:center;"><?= number_format((float)($it->qty_received),2) ?></td>
      <td style="text-align:center;<?= $var<0?'color:#b00;font-weight:bold;':'' ?>"><?= $var==0?'—':($var>0?'+':'').number_format((float)($var),2) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($order->discrepancy_flag !== 'None'): ?>
  <div class="sec" style="color:#b00;">Discrepancy Reported: <?= htmlspecialchars((string)($order->discrepancy_flag)) ?></div>
  <div style="font-size:9pt;border:1px solid #b00;padding:8px;background:#fff5f5;"><?= htmlspecialchars((string)($order->discrepancy_notes ?: 'No further details provided.')) ?></div>
  <?php else: ?>
  <div class="sec">Discrepancy</div>
  <div style="font-size:9pt;">None — quantities received match the order.</div>
  <?php endif; ?>

  <div class="sig">
    <div><div class="line">Received By</div></div>
    <div><div class="line">Verified By</div></div>
    <div><div class="line">Approved By</div></div>
  </div>
</div>
</div>

<script>window.addEventListener('load', function(){ /* manual print — auto-print can surprise users on a record page */ });</script>
</body>
</html>
