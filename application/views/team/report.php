<div class="d-flex justify-content-between align-items-center mb-3">
  <div><h4 class="fw-700 mb-0">Monthly Attendance Report</h4></div>
  <div class="d-flex gap-2">
    <form method="get" class="d-flex gap-2">
      <input type="month" name="month" class="form-control form-control-sm" value="<?= $month ?>">
      <button class="btn btn-primary btn-sm">Load</button>
    </form>
    <a href="<?= base_url('reports/export_excel/attendance?month=' . $month) ?>" class="btn btn-outline-success btn-sm">
      <i class="bi bi-file-earmark-excel me-1"></i>Export CSV
    </a>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
  </div>
</div>

<div class="wms-card">
  <div class="card-head">
    <i class="bi bi-person-check text-primary me-1"></i>
    Attendance — <?= date('F Y', strtotime($month . '-01')) ?>
    <span class="badge bg-secondary ms-2"><?= count($workers) ?> workers</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table wms-table mb-0">
        <thead>
          <tr>
            <th>Employee ID</th>
            <th>Name</th>
            <th>Position</th>
            <th class="text-success">Present</th>
            <th class="text-danger">Absent</th>
            <th class="text-warning">Leave</th>
            <th>Rest</th>
            <th>Working Days</th>
            <th>Attendance %</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($report as $r): ?>
          <tr>
            <td style="font-size:12px;color:#64748b;"><?= $r->employee_id ?></td>
            <td class="fw-500"><?= $r->full_name ?></td>
            <td style="font-size:12px;"><?= htmlspecialchars((string)$r->position, ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-success fw-700 text-center"><?= $r->present ?></td>
            <td class="text-danger fw-700 text-center"><?= $r->absent ?></td>
            <td class="text-warning fw-700 text-center"><?= $r->leave ?></td>
            <td class="text-center"><?= $r->rest ?></td>
            <td class="text-center"><?= $r->total ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-fill" style="height:8px;">
                  <div class="progress-bar bg-<?= $r->pct >= 90 ? 'success' : ($r->pct >= 75 ? 'warning' : 'danger') ?>" style="width:<?= $r->pct ?>%"></div>
                </div>
                <strong style="min-width:36px;"><?= $r->pct ?>%</strong>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($report)): ?>
          <tr><td colspan="9" class="text-center py-4 text-muted">No attendance data</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<style>@media print { #sidebar, #topbar, .btn, form { display:none !important; } #main { margin-left:0 !important; } }</style>
