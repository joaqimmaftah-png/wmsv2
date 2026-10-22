<?php
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$p_mode = isset($period['period_mode']) ? (string)$period['period_mode'] : 'full';
$p_from = isset($period['start_date'])  ? (string)$period['start_date']  : ($month . '-01');
$p_to   = isset($period['end_date'])    ? (string)$period['end_date']    : date('Y-m-t', strtotime($month . '-01'));
$p_lbl  = isset($period['period_label']) ? (string)$period['period_label'] : date('F Y', strtotime($month . '-01'));
$s_filt = isset($site_filter) ? (string)$site_filter : '';
$site_list = isset($sites) && is_array($sites) ? $sites : array();
$can_all   = !empty($can_batch);
$self_id   = isset($current_user_id) ? (int)$current_user_id : 0;

$base_q = array('month' => $month, 'period' => $p_mode);
if ($p_mode === 'custom') {
    $base_q['from'] = $p_from;
    $base_q['to']   = $p_to;
}
if ($s_filt !== '') {
    $base_q['site'] = $s_filt;
}
$qs_common = http_build_query($base_q);
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h4 class="fw-700 mb-0">Attendance Report &amp; Individual Timesheets</h4>
    <div style="font-size:12px;color:#64748b;margin-top:2px;">
      AML sign-off sheets &amp; attendance summary — <strong><?= $h($p_lbl) ?></strong>
      <?php if ($s_filt !== ''): ?> · Site: <strong><?= $h($s_filt) ?></strong><?php endif; ?>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($can_all): ?>
    <a href="<?= base_url('team/timesheets_batch?' . $qs_common) ?>" class="btn btn-success btn-sm"
       title="Download one multi-page A4 PDF containing every active worker's AML Individual Attendance Sheet ready to print &amp; sign">
      <i class="bi bi-file-earmark-pdf me-1"></i>All Timesheets (PDF)
    </a>
    <a href="<?= base_url('team/timesheets_batch?' . $qs_common . '&format=xlsx') ?>" class="btn btn-outline-success btn-sm"
       title="Download one Excel workbook with one AML sheet tab per active worker">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>All Timesheets (.xlsx)
    </a>
    <?php endif; ?>
    <a href="<?= base_url('reports/export_excel/attendance?month=' . rawurlencode($month)) ?>" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-filetype-csv me-1"></i>Summary CSV
    </a>
    <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-printer me-1"></i>Print Summary
    </button>
  </div>
</div>

<!-- Filter & Period Toolbar -->
<div class="wms-card mb-3 no-print">
  <div class="card-body py-2 px-3">
    <form method="get" action="<?= base_url('team/report') ?>" class="d-flex flex-wrap align-items-end gap-2">
      <div>
        <label class="form-label mb-1" style="font-size:11px;">Month</label>
        <input type="month" name="month" class="form-control form-control-sm" value="<?= $h($month) ?>">
      </div>
      <div>
        <label class="form-label mb-1" style="font-size:11px;">Sheet Period</label>
        <select name="period" id="tsPeriodSelect" class="form-select form-select-sm" onchange="toggleCustomDates()">
          <option value="full" <?= $p_mode === 'full' ? 'selected' : '' ?>>Full Month (01–End · 2 Half-Month A4 Sheets)</option>
          <option value="h1" <?= $p_mode === 'h1' ? 'selected' : '' ?>>1st Half (01 – 15 · 1 A4 Sheet)</option>
          <option value="h2" <?= $p_mode === 'h2' ? 'selected' : '' ?>>2nd Half (16 – End · 1 A4 Sheet)</option>
          <option value="full_compact" <?= $p_mode === 'full_compact' ? 'selected' : '' ?>>Full Month (01–End · Single Compact A4 Page)</option>
          <option value="custom" <?= $p_mode === 'custom' ? 'selected' : '' ?>>Custom Date Range</option>
        </select>
      </div>
      <div id="tsCustomDates" class="d-flex gap-2" style="<?= $p_mode === 'custom' ? '' : 'display:none!important;' ?>">
        <div>
          <label class="form-label mb-1" style="font-size:11px;">From</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?= $h($p_from) ?>">
        </div>
        <div>
          <label class="form-label mb-1" style="font-size:11px;">To</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?= $h($p_to) ?>">
        </div>
      </div>
      <div>
        <label class="form-label mb-1" style="font-size:11px;">Site Filter (optional)</label>
        <input type="text" name="site" list="aml-report-sites" class="form-control form-control-sm"
               placeholder="All sites" value="<?= $h($s_filt) ?>" style="min-width:150px;">
        <datalist id="aml-report-sites">
          <?php foreach ($site_list as $sn): ?>
          <option value="<?= $h($sn) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        <?php if ($s_filt !== '' || $p_mode !== 'full'): ?>
        <a href="<?= base_url('team/report?month=' . rawurlencode($month)) ?>" class="btn btn-ghost btn-sm">Reset</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="wms-card">
  <div class="card-head">
    <i class="bi bi-person-check text-primary me-1"></i>
    Attendance — <?= $h($p_lbl) ?>
    <span class="badge bg-secondary ms-2"><?= count($report) ?> workers</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table wms-table mb-0">
        <thead>
          <tr>
            <th>Employee ID</th>
            <th>Name</th>
            <th>Position</th>
            <th>Site</th>
            <th class="text-success text-center">Present</th>
            <th class="text-danger text-center">Absent</th>
            <th class="text-warning text-center">Leave</th>
            <th class="text-center">Rest</th>
            <th class="text-center">Holiday</th>
            <th class="text-center">Hours</th>
            <th class="text-center">Working Days</th>
            <th>Attendance %</th>
            <th class="text-end no-print">AML Attendance Sheet</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($report as $r):
            $can_row = $can_all || ((int)$r->id === $self_id);
          ?>
          <tr>
            <td style="font-size:12px;color:#64748b;"><?= $h($r->employee_id) ?></td>
            <td class="fw-500">
              <a href="<?= base_url('team/view/' . (int)$r->id) ?>" style="color:inherit;text-decoration:none;">
                <?= $h($r->full_name) ?>
              </a>
            </td>
            <td style="font-size:12px;"><?= $h($r->position) ?></td>
            <td style="font-size:12px;color:#475569;"><?= $r->site !== '' ? $h($r->site) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-success fw-700 text-center"><?= (int)$r->present ?></td>
            <td class="text-danger fw-700 text-center"><?= (int)$r->absent ?></td>
            <td class="text-warning fw-700 text-center"><?= (int)$r->leave ?></td>
            <td class="text-center"><?= (int)$r->rest ?></td>
            <td class="text-center"><?= (int)$r->holiday ?></td>
            <td class="text-center mono" style="font-size:12px;"><?= $r->hours_worked > 0 ? $h(wms_timesheet_format_hours($r->hours_worked)) . 'h' : '—' ?></td>
            <td class="text-center"><?= (int)$r->total ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-fill" style="height:8px;min-width:50px;">
                  <div class="progress-bar bg-<?= $r->pct >= 90 ? 'success' : ($r->pct >= 75 ? 'warning' : 'danger') ?>" style="width:<?= (int)$r->pct ?>%"></div>
                </div>
                <strong style="min-width:36px;"><?= (int)$r->pct ?>%</strong>
              </div>
            </td>
            <td class="text-end no-print" style="white-space:nowrap;">
              <?php if ($can_row): ?>
              <a href="<?= base_url('team/timesheet/' . (int)$r->id . '?' . $qs_common) ?>"
                 class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:11.5px;"
                 title="Download AML Individual Attendance Sheet PDF for <?= $h($r->full_name) ?>">
                <i class="bi bi-file-earmark-pdf"></i> PDF
              </a>
              <a href="<?= base_url('team/timesheet/' . (int)$r->id . '?' . $qs_common . '&preview=1') ?>"
                 target="_blank" rel="noopener"
                 class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11.5px;"
                 title="Open print preview in browser tab">
                <i class="bi bi-printer"></i>
              </a>
              <a href="<?= base_url('team/timesheet/' . (int)$r->id . '?' . $qs_common . '&format=xlsx') ?>"
                 class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11.5px;"
                 title="Download editable Excel (.xlsx) AML Attendance Sheet for <?= $h($r->full_name) ?>">
                <i class="bi bi-file-earmark-excel"></i> XLSX
              </a>
              <?php else: ?>
              <span class="text-muted" style="font-size:11px;">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($report)): ?>
          <tr><td colspan="13" class="text-center py-4 text-muted">No active workers found for this filter</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
function toggleCustomDates() {
  var sel = document.getElementById('tsPeriodSelect');
  var box = document.getElementById('tsCustomDates');
  if (!sel || !box) return;
  box.style.display = (sel.value === 'custom') ? '' : 'none';
  if (sel.value !== 'custom') box.style.setProperty('display', 'none', 'important');
}
</script>
<style>@media print { #sidebar, #topbar, .btn, form, .no-print { display:none !important; } #main { margin-left:0 !important; } }</style>
