<div class="d-flex justify-content-between align-items-center mb-3">
  <div><h4 class="fw-700 mb-0">Certifications Tracker</h4></div>
  <a href="<?= base_url('team') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Team</a>
</div>

<!-- Expiring / Expired Alerts -->
<?php if (!empty($certs)): ?>
<div class="wms-card mb-3" style="border-left:4px solid #f59e0b;">
  <div class="card-head" style="color:#b45309;"><i class="bi bi-exclamation-triangle me-1"></i>Certifications Expiring Within 60 Days</div>
  <div class="card-body p-0">
    <table class="table wms-table mb-0">
      <thead><tr><th>Worker</th><th>Employee ID</th><th>Certificate</th><th>Expiry Date</th><th>Days Left</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($certs as $c): ?>
        <?php $days_left = round((strtotime($c->expiry_date) - time()) / 86400); ?>
        <tr>
          <td class="fw-500"><?= $c->worker_name ?></td>
          <td style="font-size:12px;"><?= $c->employee_id ?></td>
          <td><?= $c->cert_name ?></td>
          <td><?= date('d M Y', strtotime($c->expiry_date)) ?></td>
          <td>
            <span class="fw-600 <?= $days_left <= 0 ? 'text-danger' : 'text-warning' ?>">
              <?= $days_left <= 0 ? abs($days_left) . ' days EXPIRED' : $days_left . ' days' ?>
            </span>
          </td>
          <td><span class="badge <?= $c->status=='Valid'?'bg-success':($c->status=='Expiring Soon'?'bg-warning text-dark':'bg-danger') ?>"><?= $c->status ?></span></td>
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
            <?= strtoupper(substr($w->first_name,0,1)) ?>
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
