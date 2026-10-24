<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="fw-700 mb-0" style="font-size:18px;line-height:1.35;">Daily Report</h1>
    <div class="text-muted" style="font-size:13px;"><?= date('l, d F Y', strtotime($date)) ?></div>
  </div>
  <div class="d-flex gap-2">
    <form method="get" class="d-flex gap-2">
      <input type="date" name="date" class="form-control form-control-sm" value="<?= $date ?>" max="<?= date('Y-m-d') ?>">
      <button class="btn btn-primary btn-sm">Load</button>
    </form>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
  </div>
</div>

<!-- Work Orders -->
<div class="wms-card mb-3">
  <div class="card-head">
    <i class="bi bi-clipboard-check text-primary me-1"></i>Work Orders
    <span class="badge bg-secondary ms-2"><?= count($work_orders) ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table wms-table mb-0">
        <thead><tr><th>WO Number</th><th>Equipment</th><th>Type</th><th>Priority</th><th>Status</th><th>Title</th><th>Assigned To</th></tr></thead>
        <tbody>
          <?php foreach ($work_orders as $wo): ?>
          <tr>
            <td><a href="<?= base_url('workorders/view/' . $wo->id) ?>" style="color:#1a3c5e;font-weight:600;"><?= $wo->wo_number ?></a></td>
            <td><?= $wo->registration_no ?></td>
            <td><span class="badge bg-primary" style="font-size:11px;"><?= $wo->type ?></span></td>
            <td><span class="status-badge badge-<?= $wo->priority ?>"><?= $wo->priority ?></span></td>
            <td><span class="status-badge badge-<?= str_replace(' ','.', $wo->status) ?>"><?= $wo->status ?></span></td>
            <td style="font-size:12px;"><?= htmlspecialchars((string)(strlen((string)($wo->title))>50 ? substr($wo->title,0,50-3).'...' : $wo->title), ENT_QUOTES, 'UTF-8') ?></td>
            <td style="font-size:12px;"><?= (isset($wo->assigned_name) && $wo->assigned_name) ? $wo->assigned_name : '—' ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($work_orders)): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">No work orders for this date</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Attendance -->
<div class="wms-card">
  <div class="card-head"><i class="bi bi-person-check text-primary me-1"></i>Attendance — <?= date('d M Y', strtotime($date)) ?></div>
  <div class="card-body p-0">
    <?php if (empty($attendance)): ?>
      <div class="text-center py-4 text-muted" style="font-size:13px;">No attendance records for this date</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table wms-table mb-0">
        <thead><tr><th>Name</th><th>Status</th><th>Check In</th><th>Check Out</th></tr></thead>
        <tbody>
          <?php foreach ($attendance as $a): ?>
          <tr>
            <td class="fw-500"><?= isset($a->full_name) ? $a->full_name : ((isset($a['user_id']) ? $a['user_id'] : '')) ?></td>
            <td>
              <?php $status = isset($a->status) ? $a->status : ((isset($a['status']) ? $a['status'] : '')); ?>
              <span class="badge <?= $status == 'Present' ? 'bg-success' : ($status == 'Absent' ? 'bg-danger' : 'bg-warning text-dark') ?>"><?= $status ?></span>
            </td>
            <td style="font-size:12px;"><?= isset($a->check_in) ? $a->check_in : ((isset($a['check_in']) ? $a['check_in'] : '—')) ?></td>
            <td style="font-size:12px;"><?= isset($a->check_out) ? $a->check_out : ((isset($a['check_out']) ? $a['check_out'] : '—')) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<style>
@media print {
  #sidebar, #topbar, .btn, form { display: none !important; }
  #main { margin-left: 0 !important; }
}
</style>
