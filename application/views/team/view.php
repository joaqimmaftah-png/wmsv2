<?php
$skill_colors = ['Junior'=>'secondary','Senior'=>'primary','Expert'=>'success','Specialist'=>'warning'];
$status_colors = ['Active'=>'success','Inactive'=>'danger','On Leave'=>'warning'];
$present_count = 0; $absent_count = 0; $leave_count = 0;
foreach ($attendance as $a) {
    if ($a->status == 'Present') $present_count++;
    elseif ($a->status == 'Absent') $absent_count++;
    elseif ($a->status == 'Leave') $leave_count++;
}
?>
<div class="d-flex mb-3" style="align-items:center;gap:10px;flex-wrap:wrap;">
  <a href="<?= base_url('team') ?>" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
  <?php if (!empty($is_supervisor)): ?>
  <a href="<?= base_url('team/edit/' . (int)$worker->id) ?>" class="btn btn-primary btn-sm ms-auto">Edit Profile</a>
  <?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <!-- Profile Card -->
    <div class="wms-card mb-3">
      <div class="card-body text-center py-4">
        <div style="width:80px;height:80px;border-radius:50%;background:#1a3c5e;color:#fff;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:700;margin:0 auto 16px;">
          <?php if ($worker->photo): ?>
            <img src="<?= base_url('uploads/photos/' . $worker->photo) ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;">
          <?php else: ?>
            <?= strtoupper((string)(substr($worker->first_name,0,1) . substr($worker->last_name,0,1))) ?>
          <?php endif; ?>
        </div>
        <h5 class="fw-700 mb-1"><?= $worker->first_name . ' ' . $worker->last_name ?></h5>
        <div class="text-muted mb-2" style="font-size:13px;"><?= htmlspecialchars((string)$worker->position, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="d-flex justify-content-center gap-2 mb-3">
          <span class="badge bg-<?= (isset($status_colors[$worker->status]) ? $status_colors[$worker->status] : 'secondary') ?>"><?= $worker->status ?></span>
          <span class="badge bg-<?= (isset($skill_colors[$worker->skill_level]) ? $skill_colors[$worker->skill_level] : 'secondary') ?>"><?= $worker->skill_level ?></span>
          <span class="badge b-secondary"><?= htmlspecialchars((string)$worker->role_name) ?></span>
        </div>
        <div style="font-size:12px;color:#64748b;text-align:left;">
          <div class="mb-1"><i class="bi bi-person-badge me-2"></i><?= $worker->employee_id ?></div>
          <div class="mb-1"><i class="bi bi-envelope me-2"></i><?= htmlspecialchars((string)$worker->email, ENT_QUOTES, 'UTF-8') ?></div>
          <?php if (!empty($worker_site)): ?><div class="mb-1"><i class="bi bi-geo-alt me-2"></i>Site: <strong><?= htmlspecialchars((string)$worker_site, ENT_QUOTES, 'UTF-8') ?></strong></div><?php endif; ?>
          <?php if ($worker->phone): ?><div class="mb-1"><i class="bi bi-phone me-2"></i><?= htmlspecialchars((string)$worker->phone, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
          <?php if ($worker->join_date): ?><div class="mb-1"><i class="bi bi-calendar me-2"></i>Joined <?= date('d M Y', strtotime($worker->join_date)) ?></div><?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!empty($can_download_timesheet)): ?>
    <!-- AML Individual Attendance Sheet Quick Download -->
    <div class="wms-card mb-3">
      <div class="card-head"><i class="bi bi-file-earmark-pdf text-danger me-1"></i>AML Attendance Sheet</div>
      <div class="card-body">
        <form method="get" action="<?= base_url('team/timesheet/' . (int)$worker->id) ?>" class="d-flex flex-column gap-2">
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label mb-1" style="font-size:11px;">Month</label>
              <input type="month" name="month" class="form-control form-control-sm" value="<?= date('Y-m') ?>">
            </div>
            <div class="col-6">
              <label class="form-label mb-1" style="font-size:11px;">Period</label>
              <?php $def_half = ((int)date('j') <= 15) ? 'h1' : 'h2'; ?>
              <select name="period" class="form-select form-select-sm">
                <option value="h1" <?= $def_half === 'h1' ? 'selected' : '' ?>>1st Half (01–15)</option>
                <option value="h2" <?= $def_half === 'h2' ? 'selected' : '' ?>>2nd Half (16–End)</option>
                <option value="full">Full Month (2 Sheets)</option>
                <option value="full_compact">Full Month (1 Page)</option>
              </select>
            </div>
          </div>
          <div class="d-flex gap-2 mt-1">
            <button type="submit" name="format" value="pdf" class="btn btn-danger btn-sm flex-fill">
              <i class="bi bi-file-earmark-pdf me-1"></i>Download PDF
            </button>
            <button type="submit" name="format" value="xlsx" class="btn btn-outline-success btn-sm flex-fill">
              <i class="bi bi-file-earmark-excel me-1"></i>Download XLSX
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Quick Stats -->
    <div class="wms-card mb-3">
      <div class="card-head"><i class="bi bi-bar-chart text-primary me-1"></i>Last 30 Days</div>
      <div class="card-body">
        <div class="row g-2 text-center">
          <div class="col-6">
            <div style="font-size:24px;font-weight:700;color:#16a34a;"><?= $present_count ?></div>
            <div style="font-size:12px;color:#64748b;">Days Present</div>
          </div>
          <div class="col-6">
            <div style="font-size:24px;font-weight:700;color:#dc2626;"><?= $absent_count ?></div>
            <div style="font-size:12px;color:#64748b;">Days Absent</div>
          </div>
        </div>
        <?php
        // Rate over the days that were judged, not every row: counting Rest and
        // Holiday days as absences capped the figure below 100% for no reason.
        $total_days = $present_count + $absent_count + $leave_count;
        $att_pct = $total_days > 0 ? round($present_count / $total_days * 100) : 0;
        ?>
        <div class="mt-3">
          <div class="d-flex justify-content-between mb-1" style="font-size:12px;"><span>Attendance Rate</span><span class="fw-600"><?= $att_pct ?>%</span></div>
          <div class="progress" style="height:8px;">
            <div class="progress-bar bg-<?= $att_pct >= 90 ? 'success' : ($att_pct >= 75 ? 'warning' : 'danger') ?>" style="width:<?= $att_pct ?>%"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Leave Balance (current year) -->
    <?php if (!empty($leave_balance)): ?>
    <div class="wms-card mb-3">
      <div class="card-head">
        <i class="bi bi-calendar-range text-primary me-1"></i>Leave Balance <?= isset($leave_year) ? (int)$leave_year : date('Y') ?>
      </div>
      <div class="card-body p-0">
        <table class="table wms-table mb-0" style="font-size:12px;">
          <thead><tr><th>Type</th><th style="text-align:right;">Entitled</th><th style="text-align:right;">Approved</th><th style="text-align:right;">Pending</th><th style="text-align:right;">Remaining</th></tr></thead>
          <tbody>
            <?php foreach ($leave_balance as $lb): ?>
            <?php if ($lb['entitled'] === null && $lb['approved'] <= 0 && $lb['pending'] <= 0) continue; ?>
            <tr>
              <td><?= htmlspecialchars((string)($lb['type'])) ?></td>
              <td class="mono" style="text-align:right;"><?= $lb['entitled'] === null ? '—' : rtrim(rtrim((string)(number_format((float)($lb['entitled']), 1)), '0'), '.') ?></td>
              <td class="mono" style="text-align:right;"><?= rtrim(rtrim((string)(number_format((float)($lb['approved']), 1)), '0'), '.') ?></td>
              <td class="mono" style="text-align:right;color:#b45309;"><?= rtrim(rtrim((string)(number_format((float)($lb['pending']), 1)), '0'), '.') ?></td>
              <td class="mono fw-600" style="text-align:right;color:<?= ($lb['remaining'] !== null && $lb['remaining'] < 0) ? '#dc2626' : '#16a34a' ?>;">
                <?= $lb['remaining'] === null ? '—' : rtrim(rtrim((string)(number_format((float)($lb['remaining']), 1)), '0'), '.') ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="font-size:10.5px;color:#94a3b8;padding:8px 12px;">
          Entitlements are configured under Settings → <code>leave_entitlements</code>. A dash means the type is not tracked.
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Leave History -->
    <div class="wms-card">
      <div class="card-head">
        <i class="bi bi-calendar-x text-primary me-1"></i>Leave History
        <a href="<?= base_url('team/leave_request') ?>" class="btn btn-sm btn-outline-primary ms-auto" style="font-size:11px;">Request Leave</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($leave_history)): ?>
          <div class="text-center py-3 text-muted" style="font-size:13px;">No leave records</div>
        <?php else: ?>
          <?php foreach ($leave_history as $lr): ?>
          <div class="px-3 py-2 border-bottom">
            <div class="d-flex justify-content-between">
              <span class="badge bg-primary bg-opacity-15 text-primary"><?= $lr->leave_type ?></span>
              <span class="badge <?= $lr->status=='Approved'?'bg-success':($lr->status=='Rejected'?'bg-danger':'bg-warning text-dark') ?>"><?= $lr->status ?></span>
            </div>
            <div style="font-size:12px;color:#64748b;margin-top:3px;">
              <?= date('d M Y', strtotime($lr->start_date)) ?> — <?= date('d M Y', strtotime($lr->end_date)) ?>
              (<?= $lr->days_count ?> days)
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <!-- Certifications -->
    <div class="wms-card mb-3">
      <div class="card-head">
        <i class="bi bi-patch-check text-primary me-1"></i>Certifications
        <?php if (!empty($is_supervisor)): ?>
        <button type="button" class="btn btn-sm btn-primary ms-auto"
                onclick="document.getElementById('addCertDialog').showModal()">
          <i class="bi bi-plus"></i> Add
        </button>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($certifications)): ?>
          <div class="text-center py-3 text-muted" style="font-size:13px;">No certifications recorded</div>
        <?php else: ?>
        <table class="table wms-table mb-0">
          <thead><tr><th>Certificate</th><th>Number</th><th>Issued By</th><th>Expiry</th><th>Status</th><th>Document</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($certifications as $c): ?>
            <?php
            $days_left = $c->expiry_date ? (int)floor((strtotime($c->expiry_date) - strtotime(date('Y-m-d'))) / 86400) : null;
            $cert_status_badge = $c->status == 'Valid' ? 'bg-success' : ($c->status == 'Expiring Soon' ? 'bg-warning text-dark' : 'bg-danger');
            $alert_days = isset($cert_alert_days) ? (int)$cert_alert_days : 30;
            ?>
            <tr>
              <td class="fw-500"><?= htmlspecialchars((string)($c->cert_name)) ?></td>
              <td style="font-size:12px;"><?= htmlspecialchars((string)($c->cert_number ?: '—')) ?></td>
              <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars((string)($c->issuing_body ?: '—')) ?></td>
              <td style="font-size:12px;">
                <?= $c->expiry_date ? date('d M Y', strtotime($c->expiry_date)) : '—' ?>
                <?php if ($days_left !== null && $days_left >= 0 && $days_left <= $alert_days): ?>
                  <div style="font-size:10px;color:#f59e0b;"><?= $days_left ?> days left</div>
                <?php elseif ($days_left !== null && $days_left < 0): ?>
                  <div style="font-size:10px;color:#dc2626;"><?= abs($days_left) ?> days expired</div>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= $cert_status_badge ?>"><?= htmlspecialchars((string)$c->status) ?></span></td>
              <td style="font-size:11.5px;">
                <?php if (!empty($c->document)): ?>
                  <a href="<?= base_url(htmlspecialchars((string)($c->document), ENT_QUOTES, 'UTF-8')) ?>" target="_blank" rel="noopener">Open</a>
                <?php else: ?>
                  <span style="color:#94a3b8;">—</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;">
                <?php if (!empty($is_supervisor)): ?>
                <?= form_open('team/delete_certification/'.$c->id, array('style' => 'display:inline;')) ?>
                  <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size:10.5px;padding:2px 8px;"
                          onclick="return confirm('Delete this certification? The attached document is removed too.');">Delete</button>
                <?= form_close() ?>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Attendance History -->
    <div class="wms-card">
      <div class="card-head"><i class="bi bi-calendar-check text-primary me-1"></i>Attendance (Last 30 Days)</div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-1">
          <?php
          $att_by_date = [];
          foreach ($attendance as $a) $att_by_date[$a->date] = $a->status;
          for ($i = 29; $i >= 0; $i--):
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $status = (isset($att_by_date[$date]) ? $att_by_date[$date] : null);
            $color = '#e2e8f0';
            $title = $date . ': Not recorded';
            if ($status == 'Present') { $color = '#16a34a'; $title = $date . ': Present'; }
            elseif ($status == 'Absent') { $color = '#dc2626'; $title = $date . ': Absent'; }
            elseif ($status == 'Leave') { $color = '#f59e0b'; $title = $date . ': On Leave'; }
            elseif ($status == 'Rest') { $color = '#64748b'; $title = $date . ': Rest Day'; }
            elseif ($status == 'Holiday') { $color = '#0c7b7b'; $title = $date . ': Public Holiday'; }
          ?>
          <div title="<?= $title ?>" style="width:20px;height:20px;border-radius:4px;background:<?= $color ?>;cursor:default;"></div>
          <?php endfor; ?>
        </div>
        <div class="d-flex gap-3 mt-2" style="font-size:11px;color:#64748b;">
          <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:#16a34a;margin-right:4px;"></span>Present</span>
          <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:#dc2626;margin-right:4px;"></span>Absent</span>
          <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:#f59e0b;margin-right:4px;"></span>Leave</span>
          <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:#64748b;margin-right:4px;"></span>Rest</span>
          <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:#0c7b7b;margin-right:4px;"></span>Holiday</span>
          <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:#e2e8f0;margin-right:4px;"></span>Not Recorded</span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add Certification dialog.
     This markup was a Bootstrap modal, but the layout loads no Bootstrap CSS or
     JS (only Chart.js), so data-bs-toggle did nothing and the "modal" body was
     simply rendered at the bottom of the page. A native <dialog> needs neither. -->
<dialog id="addCertDialog" class="wms-dialog">
      <div class="wms-dialog-head">
        <span class="wms-dialog-title">Add Certification</span>
        <button type="button" class="wms-dialog-x" aria-label="Close"
                onclick="document.getElementById('addCertDialog').close()">×</button>
      </div>
      <?= form_open_multipart('team/certifications/' . $worker->id) ?>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12"><label class="form-label">Certificate Name *</label><input type="text" name="cert_name" class="form-control" required placeholder="e.g. Forklift Operator License"></div>
          <div class="col-6"><label class="form-label">Certificate Number</label><input type="text" name="cert_number" class="form-control"></div>
          <div class="col-6"><label class="form-label">Issuing Body</label><input type="text" name="issuing_body" class="form-control"></div>
          <div class="col-6"><label class="form-label">Issued Date</label><input type="date" name="issued_date" class="form-control"></div>
          <div class="col-6"><label class="form-label">Expiry Date</label><input type="date" name="expiry_date" class="form-control"></div>
          <div class="col-12">
            <label class="form-label">Document <span style="font-weight:400;color:#94a3b8;">(PDF / JPG / PNG, max 8 MB)</span></label>
            <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
          </div>
        </div>
      </div>
      <div class="wms-dialog-foot">
        <button type="button" class="btn btn-ghost btn-sm"
                onclick="document.getElementById('addCertDialog').close()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Add Certification</button>
      </div>
      <?= form_close() ?>
</dialog>

<style>
.wms-dialog{border:1px solid var(--sep);border-radius:14px;padding:0;max-width:560px;width:calc(100% - 32px);
  background:var(--surface);color:var(--t1);box-shadow:0 24px 60px rgba(0,0,0,.28);}
.wms-dialog::backdrop{background:rgba(0,0,0,.42);backdrop-filter:blur(2px);}
.wms-dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:16px 20px;border-bottom:1px solid var(--sep);}
.wms-dialog-title{font-size:14px;font-weight:600;}
.wms-dialog-x{border:0;background:transparent;font-size:20px;line-height:1;color:var(--t3);cursor:pointer;padding:0 2px;}
.wms-dialog-x:hover{color:var(--t1);}
.wms-dialog form .modal-body{padding:18px 20px;}
.wms-dialog-foot{display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid var(--sep);}
</style>
