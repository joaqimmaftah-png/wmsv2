<div class="d-flex justify-content-between align-items-center mb-3">
  <div><h4 class="fw-700 mb-0">Certifications Tracker</h4></div>
  <a href="<?= base_url('team') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Team</a>
</div>

<?php
$alert_days = isset($cert_alert_days) ? (int)$cert_alert_days : 30;
$expired = 0; $expiring = 0;
foreach ($certs as $c) {
    $d = $c->expiry_date ? (int)floor((strtotime($c->expiry_date) - strtotime(date('Y-m-d'))) / 86400) : null;
    if ($d === null) continue;
    if ($d < 0) $expired++;
    elseif ($d <= $alert_days) $expiring++;
}
?>

<!-- Expiring / Expired Alerts -->
<?php if (!empty($certs)): ?>
<div class="wms-card mb-3" style="border-left:4px solid <?= $expired > 0 ? '#dc2626' : '#f59e0b' ?>;">
  <div class="card-head" style="color:<?= $expired > 0 ? '#b91c1c' : '#b45309' ?>;">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Certifications Needing Attention
    <span style="font-weight:400;margin-left:8px;font-size:12px;">
      <?= $expired ?> expired · <?= $expiring ?> expiring within <?= $alert_days ?> days
    </span>
  </div>
  <div class="card-body p-0">
    <table class="table wms-table mb-0">
      <thead><tr><th>Worker</th><th>Employee ID</th><th>Certificate</th><th>Expiry Date</th><th>Days Left</th><th>Status</th><th>Document</th></tr></thead>
      <tbody>
        <?php foreach ($certs as $c): ?>
        <?php $days_left = $c->expiry_date ? (int)floor((strtotime($c->expiry_date) - strtotime(date('Y-m-d'))) / 86400) : null; ?>
        <tr>
          <td class="fw-500"><a href="<?= base_url('team/view/'.$c->user_id) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars((string)($c->worker_name)) ?></a></td>
          <td style="font-size:12px;"><?= htmlspecialchars((string)$c->employee_id) ?></td>
          <td><?= htmlspecialchars((string)($c->cert_name)) ?></td>
          <td><?= $c->expiry_date ? date('d M Y', strtotime($c->expiry_date)) : '—' ?></td>
          <td>
            <?php if ($days_left === null): ?>
              <span class="text-muted">—</span>
            <?php else: ?>
            <span class="fw-600 <?= $days_left < 0 ? 'text-danger' : ($days_left <= $alert_days ? 'text-warning' : 'text-muted') ?>">
              <?= $days_left < 0 ? abs($days_left) . ' days EXPIRED' : $days_left . ' days' ?>
            </span>
            <?php endif; ?>
          </td>
          <td><span class="badge <?= $c->status=='Valid'?'bg-success':($c->status=='Expiring Soon'?'bg-warning text-dark':'bg-danger') ?>"><?= htmlspecialchars((string)$c->status) ?></span></td>
          <td style="font-size:11.5px;">
            <?php if (!empty($c->document)): ?>
              <a href="<?= base_url(htmlspecialchars((string)($c->document), ENT_QUOTES, 'UTF-8')) ?>" target="_blank" rel="noopener">Open</a>
            <?php else: ?>
              <span style="color:#94a3b8;">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- All Workers with Certification Links -->
<div class="row g-3">
  <?php foreach ($workers as $w): ?>
  <div class="col-md-6 col-lg-4">
    <div class="wms-card">
      <div class="card-body py-3">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div style="width:36px;height:36px;border-radius:50%;background:#1a3c5e;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;">
            <?= strtoupper((string)(substr($w->first_name,0,1))) ?>
          </div>
          <div>
            <div class="fw-600" style="font-size:13px;"><?= $w->first_name . ' ' . $w->last_name ?></div>
            <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars((string)$w->position, ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>
        <a href="<?= base_url('team/view/' . $w->id) ?>" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-patch-check me-1"></i>View / Add Certifications
        </a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
