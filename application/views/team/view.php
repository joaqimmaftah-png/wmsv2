<?php
$skill_colors = ['Junior'=>'secondary','Senior'=>'primary','Expert'=>'success','Specialist'=>'warning'];
$status_colors = ['Active'=>'success','Inactive'=>'danger','On Leave'=>'warning'];
$vp_id = (int)$worker->id;
$cert_counts  = isset($cert_counts) ? $cert_counts : array('all' => count($certifications), 'expired' => 0, 'expiring' => 0, 'valid' => 0, 'no_expiry' => 0);
$cert_tab     = isset($cert_tab) ? $cert_tab : 'all';
$cert_total   = isset($cert_total) ? (int)$cert_total : count($certifications);
$cper_page    = isset($cper_page) ? (int)$cper_page : 10;
$cpage        = isset($cpage) ? (int)$cpage : 1;
$ctotal_pages = isset($ctotal_pages) ? (int)$ctotal_pages : 1;
$cshowing_from = isset($cshowing_from) ? (int)$cshowing_from : ($cert_total ? 1 : 0);
$cshowing_to   = isset($cshowing_to) ? (int)$cshowing_to : $cert_total;
$leave_counts  = isset($leave_counts) ? $leave_counts : array('all' => count($leave_history), 'approved' => 0, 'pending' => 0, 'rejected' => 0);
$leave_tab     = isset($leave_tab) ? $leave_tab : 'all';
$leave_total   = isset($leave_total) ? (int)$leave_total : count($leave_history);
$lper_page     = isset($lper_page) ? (int)$lper_page : 5;
$lpage         = isset($lpage) ? (int)$lpage : 1;
$ltotal_pages  = isset($ltotal_pages) ? (int)$ltotal_pages : 1;
$lshowing_from = isset($lshowing_from) ? (int)$lshowing_from : ($leave_total ? 1 : 0);
$lshowing_to   = isset($lshowing_to) ? (int)$lshowing_to : $leave_total;
$vp_cert_url = function ($o = array()) use ($vp_id, $cert_tab, $leave_tab, $lpage) {
    return site_url('team/view/' . $vp_id) . '?' . http_build_query(array_merge(array_filter(array(
        'ctab' => ($cert_tab !== 'all') ? $cert_tab : null,
        'ltab' => ($leave_tab !== 'all') ? $leave_tab : null,
        'lpage' => ($lpage > 1) ? $lpage : null,
    )), $o));
};
$vp_leave_url = function ($o = array()) use ($vp_id, $cert_tab, $leave_tab, $cpage) {
    return site_url('team/view/' . $vp_id) . '?' . http_build_query(array_merge(array_filter(array(
        'ctab' => ($cert_tab !== 'all') ? $cert_tab : null,
        'cpage' => ($cpage > 1) ? $cpage : null,
        'ltab' => ($leave_tab !== 'all') ? $leave_tab : null,
    )), $o));
};
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
            <img src="<?= base_url('uploads/photos/' . rawurlencode(basename((string)$worker->photo))) ?>" alt="<?= htmlspecialchars($worker->first_name . ' ' . $worker->last_name, ENT_QUOTES, 'UTF-8') ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;">
          <?php else: ?>
            <?= htmlspecialchars(strtoupper((string)(substr($worker->first_name,0,1) . substr($worker->last_name,0,1))), ENT_QUOTES, 'UTF-8') ?>
          <?php endif; ?>
        </div>
        <h1 class="fw-700 mb-1" style="font-size:18px;line-height:1.35;"><?= htmlspecialchars($worker->first_name . ' ' . $worker->last_name, ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="text-muted mb-2" style="font-size:13px;"><?= htmlspecialchars((string)$worker->position, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="d-flex justify-content-center gap-2 mb-3">
          <span class="badge bg-<?= (isset($status_colors[$worker->status]) ? $status_colors[$worker->status] : 'secondary') ?>"><?= htmlspecialchars((string)$worker->status, ENT_QUOTES, 'UTF-8') ?></span>
          <span class="badge bg-<?= (isset($skill_colors[$worker->skill_level]) ? $skill_colors[$worker->skill_level] : 'secondary') ?>"><?= htmlspecialchars((string)$worker->skill_level, ENT_QUOTES, 'UTF-8') ?></span>
          <span class="badge b-secondary"><?= htmlspecialchars((string)$worker->role_name, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div style="font-size:12px;color:#64748b;text-align:left;">
          <div class="mb-1"><i class="bi bi-person-badge me-2"></i><?= htmlspecialchars((string)$worker->employee_id, ENT_QUOTES, 'UTF-8') ?></div>
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
              <label class="form-label mb-1" style="font-size:11px;" for="vpTsMonth">Month</label>
              <input type="month" id="vpTsMonth" name="month" class="form-control form-control-sm" value="<?= date('Y-m') ?>">
            </div>
            <div class="col-6">
              <label class="form-label mb-1" style="font-size:11px;" for="vpTsPeriod">Period</label>
              <?php $def_half = ((int)date('j') <= 15) ? 'h1' : 'h2'; ?>
              <select id="vpTsPeriod" name="period" class="form-select form-select-sm">
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
        $att_pct = $total_days > 0 ? (int)round($present_count / $total_days * 100) : NULL;
        ?>
        <div class="mt-3">
          <div class="d-flex justify-content-between mb-1" style="font-size:12px;">
            <span>Attendance Rate</span>
            <span class="fw-600"><?= $att_pct === NULL ? 'No records' : ($att_pct . '%') ?></span>
          </div>
          <div class="progress" style="height:8px;">
            <?php if ($att_pct !== NULL): ?>
              <div class="progress-bar bg-<?= $att_pct >= 90 ? 'success' : ($att_pct >= 75 ? 'warning' : 'danger') ?>" style="width:<?= $att_pct ?>%"></div>
            <?php endif; ?>
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
          <thead><tr><th scope="col">Type</th><th scope="col" style="text-align:right;">Entitled</th><th scope="col" style="text-align:right;">Approved</th><th scope="col" style="text-align:right;">Pending</th><th scope="col" style="text-align:right;">Remaining</th></tr></thead>
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
      <div style="padding:0 14px;">
        <div class="vp-tabs" role="tablist" aria-label="Leave status">
          <?php foreach (array('all' => 'All', 'approved' => 'Approved', 'pending' => 'Pending', 'rejected' => 'Rejected') as $k => $l): ?>
          <a role="tab" aria-selected="<?= $leave_tab === $k ? 'true' : 'false' ?>" class="vp-tab"
             href="<?= $vp_leave_url(array('ltab' => ($k === 'all' ? null : $k), 'lpage' => null)) ?>"><?= $l ?><span class="cnt"><?= (int)$leave_counts[$k] ?></span></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card-body p-0">
        <?php if (empty($leave_history)): ?>
          <div class="text-center py-3 text-muted" style="font-size:13px;">
            <?php if ((int)$leave_counts['all'] === 0): ?>No leave records
            <?php elseif ($leave_tab === 'pending'): ?>No pending leave requests.
            <?php elseif ($leave_tab === 'rejected'): ?>No rejected leave requests.
            <?php else: ?>No leave records in this status.<?php endif; ?>
          </div>
        <?php else: ?>
          <?php foreach ($leave_history as $lr): ?>
          <div class="px-3 py-2 border-bottom">
            <div class="d-flex justify-content-between">
              <span class="badge b-primary"><?= htmlspecialchars((string)$lr->leave_type, ENT_QUOTES, 'UTF-8') ?></span>
              <span class="badge <?= $lr->status=='Approved'?'b-available':($lr->status=='Rejected'?'b-danger':'b-warning') ?>"><?= htmlspecialchars((string)$lr->status, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div style="font-size:12px;color:#64748b;margin-top:3px;">
              <?= date('d M Y', strtotime($lr->start_date)) ?> — <?= date('d M Y', strtotime($lr->end_date)) ?>
              (<?= $lr->days_count ?> days)
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($leave_total > 0): ?>
        <div class="vp-pager">
          <span>Showing <?= (int)$lshowing_from ?>–<?= (int)$lshowing_to ?> of <?= (int)$leave_total ?></span>
          <nav class="vp-pg" aria-label="Leave history pages">
            <?php if ($lpage > 1): ?><a href="<?= $vp_leave_url(array('lpage' => $lpage - 1, 'lper_page' => (int)$lper_page)) ?>">‹ Prev</a><?php else: ?><span class="dis">‹ Prev</span><?php endif; ?>
            <?php if ($lpage < $ltotal_pages): ?><a href="<?= $vp_leave_url(array('lpage' => $lpage + 1, 'lper_page' => (int)$lper_page)) ?>">Next ›</a><?php else: ?><span class="dis">Next ›</span><?php endif; ?>
          </nav>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <!-- Certifications -->
    <div class="wms-card mb-3">
      <div class="card-head">
        <i class="bi bi-patch-check text-primary me-1"></i>Certifications
        <span style="font-weight:400;color:var(--t3);font-size:12px;"><?= (int)$cert_counts['all'] ?> recorded<?php if ((int)$cert_counts['expired'] > 0): ?> · <span style="color:var(--red);font-weight:600;"><?= (int)$cert_counts['expired'] ?> expired</span><?php endif; ?><?php if ((int)$cert_counts['expiring'] > 0): ?> · <span style="color:var(--amber);font-weight:600;"><?= (int)$cert_counts['expiring'] ?> expiring soon</span><?php endif; ?></span>
        <?php if (!empty($is_supervisor)): ?>
        <button type="button" class="btn btn-sm btn-primary ms-auto"
                onclick="document.getElementById('addCertDialog').showModal()">
          <i class="bi bi-plus"></i> Add
        </button>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <div style="padding:0 14px;">
          <div class="vp-tabs" role="tablist" aria-label="Certification status">
            <?php foreach (array('all' => 'All', 'expired' => 'Expired', 'expiring' => 'Expiring Soon', 'valid' => 'Valid', 'no_expiry' => 'No Expiry Date') as $k => $l): ?>
            <a role="tab" aria-selected="<?= $cert_tab === $k ? 'true' : 'false' ?>" class="vp-tab"
               href="<?= $vp_cert_url(array('ctab' => ($k === 'all' ? null : $k), 'cpage' => null)) ?>"><?= $l ?><span class="cnt"><?= (int)$cert_counts[$k] ?></span></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if (empty($certifications)): ?>
          <div class="text-center py-3 text-muted" style="font-size:13px;">
            <?php if ((int)$cert_counts['all'] === 0): ?>No certifications recorded
            <?php elseif ($cert_tab === 'expired'): ?>No expired certifications — good.
            <?php elseif ($cert_tab === 'no_expiry'): ?>Every certificate has an expiry date.
            <?php else: ?>No certifications in this status.<?php endif; ?>
          </div>
        <?php else: ?>
        <table class="table wms-table mb-0">
          <thead><tr><th scope="col">Certificate</th><th scope="col">Number</th><th scope="col">Issued By</th><th scope="col">Expiry</th><th scope="col">Status</th><th scope="col">Document</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
            <?php foreach ($certifications as $c): ?>
            <?php
            $days_left = isset($c->days_left_profile) ? $c->days_left_profile
                : ($c->expiry_date ? (int)floor((strtotime($c->expiry_date) - strtotime(date('Y-m-d'))) / 86400) : null);
            $display_status = isset($c->display_status) ? $c->display_status
                : ($days_left === null ? 'No expiry date' : ($days_left < 0 ? 'Expired' : ($days_left <= (isset($cert_alert_days) ? (int)$cert_alert_days : 30) ? 'Expiring Soon' : 'Valid')));
            $cert_status_badge = $display_status === 'Valid' ? 'b-available' : ($display_status === 'Expiring Soon' ? 'b-warning' : ($display_status === 'Expired' ? 'b-danger' : 'b-secondary'));
            $alert_days = isset($cert_alert_days) ? (int)$cert_alert_days : 30;
            ?>
            <tr>
              <td class="fw-500"><?= htmlspecialchars((string)($c->cert_name)) ?></td>
              <td style="font-size:12px;"><?= htmlspecialchars((string)($c->cert_number ?: '—')) ?></td>
              <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars((string)($c->issuing_body ?: '—')) ?></td>
              <td style="font-size:12px;">
                <?= $c->expiry_date ? date('d M Y', strtotime($c->expiry_date)) : '—' ?>
                <?php if ($days_left !== null && $days_left >= 0 && $days_left <= $alert_days): ?>
                  <div style="font-size:10px;color:var(--amber);"><?= $days_left ?> days left</div>
                <?php elseif ($days_left !== null && $days_left < 0): ?>
                  <div style="font-size:10px;color:var(--red);"><?= abs($days_left) ?> days expired</div>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= $cert_status_badge ?>"><?= htmlspecialchars($display_status, ENT_QUOTES, 'UTF-8') ?></span></td>
              <td style="font-size:11.5px;">
                <?php if (!empty($c->document)): ?>
                  <a href="<?= base_url(htmlspecialchars((string)($c->document), ENT_QUOTES, 'UTF-8')) ?>" target="_blank" rel="noopener" aria-label="Open document for <?= htmlspecialchars((string)$c->cert_name, ENT_QUOTES, 'UTF-8') ?>">Open document</a>
                <?php else: ?>
                  <span style="color:var(--t3);">No document</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;white-space:nowrap;">
                <?php if (!empty($is_supervisor)): ?>
                <button type="button" class="btn btn-sm btn-outline-primary" style="font-size:10.5px;padding:2px 8px;"
                        data-cert-edit-btn
                        data-cert-id="<?= (int)$c->id ?>"
                        data-cert-name="<?= htmlspecialchars((string)$c->cert_name, ENT_QUOTES, 'UTF-8') ?>"
                        data-cert-number="<?= htmlspecialchars((string)(isset($c->cert_number) ? $c->cert_number : ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-issuing-body="<?= htmlspecialchars((string)(isset($c->issuing_body) ? $c->issuing_body : ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-issued="<?= htmlspecialchars((string)(isset($c->issued_date) ? $c->issued_date : ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-expiry="<?= htmlspecialchars((string)(isset($c->expiry_date) ? $c->expiry_date : ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-document="<?= htmlspecialchars((string)(isset($c->document) ? basename((string)$c->document) : ''), ENT_QUOTES, 'UTF-8') ?>">Renew / Edit</button>
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
        <?php if ($cert_total > 0): ?>
        <div class="vp-pager">
          <span>Showing <?= (int)$cshowing_from ?>–<?= (int)$cshowing_to ?> of <?= (int)$cert_total ?> in this tab · ordered by urgency</span>
          <nav class="vp-pg" aria-label="Certification pages">
            <?php if ($cpage > 1): ?><a href="<?= $vp_cert_url(array('cpage' => $cpage - 1, 'cper_page' => (int)$cper_page)) ?>">‹ Prev</a><?php else: ?><span class="dis">‹ Prev</span><?php endif; ?>
            <?php if ($cpage < $ctotal_pages): ?><a href="<?= $vp_cert_url(array('cpage' => $cpage + 1, 'cper_page' => (int)$cper_page)) ?>">Next ›</a><?php else: ?><span class="dis">Next ›</span><?php endif; ?>
          </nav>
        </div>
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
          <span role="img" aria-label="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>" title="<?= $title ?>" style="display:inline-block;width:20px;height:20px;border-radius:4px;background:<?= $color ?>;"></span>
          <?php endfor; ?>
        </div>
        <?php
          $att_summary = array('Present' => 0, 'Absent' => 0, 'Leave' => 0, 'Rest' => 0, 'Holiday' => 0, 'Not recorded' => 0);
          for ($i = 29; $i >= 0; $i--) {
              $d2 = date('Y-m-d', strtotime("-{$i} days"));
              $k2 = isset($att_by_date[$d2]) ? $att_by_date[$d2] : 'Not recorded';
              if (isset($att_summary[$k2])) $att_summary[$k2]++; else $att_summary['Not recorded']++;
          }
        ?>
        <div style="font-size:11.5px;color:var(--t2);margin-top:8px;">
          Last 30 days: <?php $first = true; foreach ($att_summary as $k2 => $v2): if ($v2 === 0) continue; ?><?= $first ? '' : ' · ' ?><strong><?= (int)$v2 ?></strong> <?= htmlspecialchars($k2, ENT_QUOTES, 'UTF-8') ?><?php $first = false; endforeach; ?>
        </div>
        <div class="d-flex gap-3 mt-2" style="font-size:11px;color:var(--t3);">
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
          <div class="col-12"><label class="form-label" for="addCert_cert_name">Certificate Name *</label><input type="text" id="addCert_cert_name" name="cert_name" class="form-control" required placeholder="e.g. Forklift Operator License"></div>
          <div class="col-6"><label class="form-label" for="addCert_cert_number">Certificate Number</label><input type="text" id="addCert_cert_number" name="cert_number" class="form-control"></div>
          <div class="col-6"><label class="form-label" for="addCert_issuing_body">Issuing Body</label><input type="text" id="addCert_issuing_body" name="issuing_body" class="form-control"></div>
          <div class="col-6"><label class="form-label" for="addCert_issued_date">Issued Date</label><input type="date" id="addCert_issued_date" name="issued_date" class="form-control"></div>
          <div class="col-6"><label class="form-label" for="addCert_expiry_date">Expiry Date</label><input type="date" id="addCert_expiry_date" name="expiry_date" class="form-control"></div>
          <div class="col-12">
            <label class="form-label" for="addCert_document">Document <span style="font-weight:400;color:var(--t3);">(PDF / JPG / PNG, max 8 MB)</span></label>
            <input type="file" id="addCert_document" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
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


<!-- Renew / Edit Certification dialog (supervisor only; posts to
     Team::edit_certification, shared with the Certifications Tracker). -->
<?php if (!empty($is_supervisor)): ?>
<dialog id="editCertDialog" class="wms-dialog">
      <div class="wms-dialog-head">
        <span class="wms-dialog-title">Renew / Edit Certification</span>
        <button type="button" class="wms-dialog-x" aria-label="Close"
                onclick="document.getElementById('editCertDialog').close()">×</button>
      </div>
      <?= form_open_multipart('', array('id' => 'editCertForm')) ?>
      <input type="hidden" name="return_to" value="profile">
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12"><label class="form-label" for="editCertName">Certificate Name *</label><input type="text" id="editCertName" name="cert_name" class="form-control" required></div>
          <div class="col-6"><label class="form-label" for="editCertNumber">Certificate Number</label><input type="text" id="editCertNumber" name="cert_number" class="form-control"></div>
          <div class="col-6"><label class="form-label" for="editCertBody">Issuing Body</label><input type="text" id="editCertBody" name="issuing_body" class="form-control"></div>
          <div class="col-6"><label class="form-label" for="editCertIssued">Issued Date</label><input type="date" id="editCertIssued" name="issued_date" class="form-control"></div>
          <div class="col-6"><label class="form-label" for="editCertExpiry">Expiry Date</label><input type="date" id="editCertExpiry" name="expiry_date" class="form-control"></div>
          <div class="col-12">
            <label class="form-label" for="editCertDoc">Replacement Document <span style="font-weight:400;color:#94a3b8;">(PDF / JPG / PNG, max 8 MB — leave empty to keep current)</span></label>
            <input type="file" id="editCertDoc" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
            <div style="font-size:11.5px;color:#94a3b8;margin-top:4px;" id="editCertCurrentDoc"></div>
            <label style="display:flex;gap:6px;align-items:center;font-size:12px;margin-top:6px;"><input type="checkbox" name="remove_document" value="1"> Remove current document without replacement</label>
          </div>
        </div>
      </div>
      <div class="wms-dialog-foot">
        <button type="button" class="btn btn-ghost btn-sm"
                onclick="document.getElementById('editCertDialog').close()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
      </div>
      <?= form_close() ?>
</dialog>
<script>
(function () {
  var dialog = document.getElementById('editCertDialog');
  if (!dialog) return;
  var form = document.getElementById('editCertForm');
  document.querySelectorAll('[data-cert-edit-btn]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      form.action = <?= json_encode(site_url('team/edit_certification/')) ?> + btn.getAttribute('data-cert-id');
      document.getElementById('editCertName').value = btn.getAttribute('data-cert-name') || '';
      document.getElementById('editCertNumber').value = btn.getAttribute('data-cert-number') || '';
      document.getElementById('editCertBody').value = btn.getAttribute('data-issuing-body') || '';
      document.getElementById('editCertIssued').value = btn.getAttribute('data-issued') || '';
      document.getElementById('editCertExpiry').value = btn.getAttribute('data-expiry') || '';
      document.getElementById('editCertDoc').value = '';
      var doc = btn.getAttribute('data-document') || '';
      document.getElementById('editCertCurrentDoc').textContent = doc
        ? ('Current document: ' + doc + ' — uploading a replacement removes the old file after saving.')
        : 'No document currently on file.';
      if (typeof dialog.showModal === 'function') dialog.showModal();
    });
  });
})();
</script>
<?php endif; ?>

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
.vp-tabs{display:flex;gap:2px;flex-wrap:wrap;border-bottom:1px solid var(--sep)}
.vp-tab{padding:7px 10px;font-size:12px;font-weight:500;color:var(--t2);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}
.vp-tab:hover{color:var(--t1)}
.vp-tab[aria-selected="true"]{color:var(--blue);border-bottom-color:var(--blue);font-weight:600}
.vp-tab .cnt{font-size:10.5px;background:var(--gray-fill);border-radius:999px;padding:0 5px;margin-left:3px}
.vp-pager{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;padding:10px 14px;font-size:11.5px;color:var(--t3);border-top:1px solid var(--sep)}
.vp-pg{display:inline-flex;gap:4px;align-items:center}
.vp-pg a{font-size:11.5px;padding:3px 8px;border:1px solid var(--sep);border-radius:6px;color:var(--t2);text-decoration:none}
.vp-pg span.dis{font-size:11.5px;padding:3px 8px;color:var(--t4)}
</style>
