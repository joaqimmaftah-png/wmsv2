<?php
/**
 * MAINTENANCE PLAN JOBS — one machine, one day (MP-2).
 *
 * Every job is its own row: an inspection and a service on the same day are
 * both listed and both counted (probe P4).  The hours shown per category are
 * the ledger's, i.e. what the availability figure actually uses — a manual
 * entry for that category still wins over the jobs (D31), and the screen says
 * so.  Nothing here computes a KPI.
 */
$hrs = function ($v) {
    return $v === null ? '—' : rtrim((string)(rtrim((string)(number_format((float)$v, 2, '.', '')), '0')), '.');
};
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$job_status_badge = array(
  'Planned'   => 'b-primary',
  'Issued'    => 'b-progress',
  'Done'      => 'b-completed',
  'Missed'    => 'b-danger',
  'Cancelled' => 'b-secondary',
);
$job_type_labels = array(
  'Inspection' => 'Inspection',
  'PMService'  => 'PM service',
  'Repair'     => 'Repair',
  'Rebuild'    => 'Rebuild',
  'Standby'    => 'Stand-by',
  'Other'      => 'Other',
);
$category_labels = array(
  'PM'          => 'PM',
  'Standing'    => 'Standing',
  'Unplanned'   => 'Unplanned',
  'Corrective'  => 'Corrective',
  'OutOfService'=> 'Out of service',
);
$hold_labels = function_exists('wo_hold_codes') ? wo_hold_codes() : array();

$cell       = ($machine && isset($machine['daily'][$date])) ? $machine['daily'][$date] : null;
$day_jobs   = $cell ? $cell['jobs'] : array();
$week_jobs  = array();
if ($machine) {
    foreach ($days as $d) {
        foreach ($machine['daily'][$d]['jobs'] as $j) $week_jobs[$j['id']] = $j;
    }
}
$editable   = $jobs_ready && !$frozen && $can_plan && $machine;
$e = function ($k, $default = '') use ($edit) {
    return ($edit && isset($edit->$k) && $edit->$k !== null) ? $edit->$k : $default;
};
$current_type = $e('job_type', 'Inspection');
$standby_hours = $current_type === 'Standby';
$day_warn = null;
foreach ($warnings as $w) {
    if ($machine && $w['equipment_id'] === $machine['equipment_id'] && $w['date'] === $date) $day_warn = $w;
}
$back = base_url('forecast?week=' . urlencode($iso_label));
$machine_day_caption = $machine
    ? $machine['registration_no'] . ' — ' . date('d M Y', strtotime($date))
    : 'Machine day';
?>

<style>
  .jobs-toolbar { display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap; }
  .jobs-toolbar-field { display:flex;flex-direction:column;gap:4px; }
  .jobs-toolbar-field select { width:100%; }
  .jobs-sr-only { position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important; }
  .jobs-table-wrap { overflow-x:auto; }
  .jobs-table { min-width:1020px; }
  .jobs-table th:first-child,.jobs-table td:first-child { position:sticky;left:0;z-index:2;width:58px;min-width:58px;max-width:58px;background:var(--surface);box-shadow:2px 0 6px -3px rgba(0,0,0,.35); }
  .jobs-table th:nth-child(2),.jobs-table td:nth-child(2) { position:sticky;left:58px;z-index:2;width:210px;min-width:210px;max-width:210px;background:var(--surface);box-shadow:2px 0 6px -3px rgba(0,0,0,.25); }
  .jobs-table thead th:first-child,.jobs-table thead th:nth-child(2) { z-index:4;background:var(--surface-2); }
  .jobs-table td:nth-child(2) { overflow-wrap:anywhere; }
  .jobs-editor-grid { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end; }
  .jobs-field { min-width:0; }
  .jobs-field--wide { grid-column:span 2; }
  .jobs-field .form-control { width:100%; }
  .jobs-field-help { display:block;margin-top:4px;color:var(--t3);font-size:10.5px;line-height:1.5; }
  .jobs-form-actions { display:flex;justify-content:flex-end;align-items:end; }
  .jobs-link:focus-visible { outline:2px solid var(--blue);outline-offset:2px;border-radius:3px; }
  @media (max-width:900px) {
    .jobs-editor-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
  }
  @media (max-width:560px) {
    .jobs-editor-grid { grid-template-columns:1fr; }
    .jobs-field--wide { grid-column:span 1; }
    .jobs-form-actions { justify-content:stretch; }
    .jobs-form-actions .btn { width:100%;justify-content:center; }
  }
</style>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">
      Maintenance jobs — <?= $machine ? $h($machine['registration_no']) : 'choose a machine' ?>
    </h1>
    <div class="cs"><?=$h($iso_label)?> · <?=date('D d M Y', strtotime($date))?></div>
  </div>
  <a class="btn btn-sm" href="<?=$back?>">Back to the planned week</a>
</div>

<?php if (!$jobs_ready): ?>
<div class="card" style="padding:24px;border-left:3px solid var(--amber);">
  <div style="font-weight:700;margin-bottom:6px;">The maintenance-plan tables are not set up yet</div>
  <div class="cs" style="font-size:12px;">Import the database dump shipped with this repository to plan individual jobs.</div>
</div>
<?php else: ?>

<?php if ($machines): ?>
<form method="get" class="card mb-4 jobs-toolbar" style="padding:12px 18px;">
  <input type="hidden" name="week" value="<?=$h($iso_label)?>">
  <div class="jobs-toolbar-field">
    <label class="form-label" for="jobs-machine">Machine</label>
    <select id="jobs-machine" name="equipment_id" class="form-control" style="min-width:210px;" required>
      <option value=""<?= $machine ? '' : ' selected' ?> disabled>— choose a machine —</option>
      <?php foreach ($machines as $m): ?>
      <option value="<?=(int)$m['equipment_id']?>"<?= ($machine && $m['equipment_id'] === $machine['equipment_id']) ? ' selected' : '' ?>>
        <?=$h($m['registration_no'])?><?= !empty($m['location']) ? ' — ' . $h($m['location']) : '' ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="jobs-toolbar-field">
    <label class="form-label" for="jobs-date">Day</label>
    <select id="jobs-date" name="date" class="form-control" style="min-width:170px;">
      <?php foreach ($days as $d): ?>
      <option value="<?=$h($d)?>"<?= $d === $date ? ' selected' : '' ?>><?=date('D d M', strtotime($d))?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-sm btn-primary" type="submit">Open</button>
</form>
<?php else: ?>
<div class="card mb-4" role="status" style="padding:18px;">
  <div style="font-weight:600;">No reportable machines are available for this week.</div>
  <div class="cs" style="margin-top:5px;">There are no machine-day jobs to show.</div>
</div>
<?php endif; ?>

<?php if (!$machine && $machines): ?>
<div class="card" role="status" style="padding:18px;">
  Choose a machine and day above to view its planned hours and jobs.
</div>
<?php endif; ?>

<?php if ($machine): ?>

<?php if ($frozen): ?>
<div class="card mb-4" style="padding:12px 18px;border-left:3px solid var(--green);font-size:12px;">
  <?=$h($iso_label)?> is published and frozen — its jobs cannot be changed. A new revision preserves the baseline; it does not unlock these jobs.
</div>
<?php elseif (!$editable): ?>
<div class="card mb-4" role="status" style="padding:12px 18px;font-size:12px;">
  You have view-only access. A supervisor can change jobs on an unpublished plan.
</div>
<?php endif; ?>

<?php if ($day_warn): ?>
<div class="card mb-4 overbooked-warning" style="padding:12px 18px;border-left:3px solid var(--red);font-size:12px;color:var(--red);">
  <?=$h($day_warn['message'])?> — <?=$hrs($day_warn['excess_hours'])?> h more than the day has.
</div>
<?php endif; ?>

<!-- ── What the day counts as (the ledger the KPI reads) ─────────────── -->
<div class="card mb-4" style="padding:14px 18px;">
  <div style="font-weight:700;font-size:12px;margin-bottom:8px;">Planned hours on <?=date('D d M', strtotime($date))?> (open <?=$hrs($open_hours)?> h)</div>
  <?php if ($cell): ?>
  <div class="flex gap-10" style="flex-wrap:wrap;font-size:11.5px;">
    <span>PM <strong class="mono"><?=$hrs($cell['pm_hours'])?></strong> h</span>
    <span>Standing <strong class="mono"><?=$hrs($cell['standing_hours'])?></strong> h</span>
    <span>Unplanned <strong class="mono"><?=$hrs($cell['unplanned_hours'])?></strong> h</span>
    <span>Corrective <strong class="mono"><?=$hrs($cell['corrective_hours'])?></strong> h</span>
    <span>Out of service <strong class="mono"><?=$hrs($cell['out_hours'])?></strong> h</span>
    <span>· required <strong class="mono"><?=$hrs($cell['required_hours'])?></strong> h</span>
  </div>
  <?php else: ?>
  <div class="cs" style="font-size:11px;">No planned-ledger hours are recorded for this machine-day.</div>
  <?php endif; ?>
</div>

<!-- ── The day's jobs ────────────────────────────────────────────────── -->
<div class="card mb-4 jobs-table-wrap">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;"><?=count($day_jobs)?> job<?=count($day_jobs) === 1 ? '' : 's'?> on this day</div>
    <div class="cs">Every job is kept; their hours add up per category.</div>
  </div>
  <table class="tbl jobs-table">
    <caption class="jobs-sr-only">Jobs for <?=$h($machine_day_caption)?></caption>
    <thead><tr>
      <th scope="col">CODE</th><th scope="col">JOB</th><th scope="col">DATES</th>
      <th scope="col" style="text-align:right;">H/DAY</th><th scope="col">COUNTS AS</th>
      <th scope="col">HOLD</th><th scope="col">ETA</th><th scope="col">STATUS</th>
      <th scope="col">ACTIONS</th>
    </tr></thead>
    <tbody>
    <?php if (!$day_jobs): ?>
      <tr><td colspan="9" style="color:var(--t3);text-align:center;">No jobs planned on this day.</td></tr>
    <?php endif; ?>
    <?php foreach ($day_jobs as $j): ?>
      <?php
        $job_code = isset($codes[$j['job_type']]) ? $codes[$j['job_type']] : $j['job_type'];
        $hold_code = isset($j['hold_code']) ? (string)$j['hold_code'] : '';
        $hold_text = $hold_code !== ''
            ? $hold_code . (isset($hold_labels[$hold_code]) ? ' — ' . $hold_labels[$hold_code] : '')
            : '—';
      ?>
      <tr class="job-row">
        <td class="mono" style="font-weight:700;"><?=$h($job_code)?></td>
        <td><?=$h($j['title'])?></td>
        <td class="mono" style="font-size:10.5px;">
          <?=$h(date('d M Y', strtotime($j['start_date'])))?><?= $j['end_date'] !== $j['start_date'] ? ' → ' . $h(date('d M Y', strtotime($j['end_date']))) : '' ?>
        </td>
        <td class="mono" style="text-align:right;"><?=$hrs($j['hours_per_day'])?></td>
        <td><?=$h(isset($category_labels[$j['category']]) ? $category_labels[$j['category']] : $j['category'])?></td>
        <td><?=$h($hold_text)?></td>
        <td><?=$j['expected_return'] ? $h(date('d M Y', strtotime($j['expected_return']))) : '—'?></td>
        <td style="text-align:center;"><span class="badge <?=isset($job_status_badge[$j['status']]) ? $job_status_badge[$j['status']] : 'b-secondary'?>"><?=$h($j['status'])?></span></td>
        <td style="white-space:nowrap;">
          <?php if ($editable): ?>
          <a class="btn btn-sm jobs-link" aria-label="Edit job: <?=$h($j['title'])?>" href="<?=base_url('forecast/jobs?week=' . urlencode($iso_label) . '&equipment_id=' . (int)$machine['equipment_id'] . '&date=' . rawurlencode($date) . '&edit=' . (int)$j['id'])?>">Edit</a>
          <form method="post" action="<?=base_url('forecast/delete_job?week=' . urlencode($iso_label))?>" style="display:inline;"
                onsubmit="return confirm('Remove this job? Its hours leave the plan on every day it covers.');">
            <?=csrf_field()?>
            <input type="hidden" name="id" value="<?=(int)$j['id']?>">
            <input type="hidden" name="equipment_id" value="<?=(int)$machine['equipment_id']?>">
            <input type="hidden" name="date" value="<?=$h($date)?>">
            <button class="btn btn-sm jobs-link" type="submit" aria-label="Remove job: <?=$h($j['title'])?>">Remove</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (count($week_jobs) > count($day_jobs)): ?>
<div class="cs mb-4" style="font-size:11px;">
  This machine has <?=count($week_jobs)?> job<?=count($week_jobs) === 1 ? '' : 's'?> in <?=$h($iso_label)?>:
  <?php $bits = array(); foreach ($week_jobs as $j) $bits[] = $h((isset($codes[$j['job_type']]) ? $codes[$j['job_type']] : $j['job_type']) . ' ' . date('d M', strtotime($j['start_date']))); echo implode(' · ', $bits); ?>
</div>
<?php endif; ?>

<?php if ($editable): ?>
<!-- ── Add / edit ────────────────────────────────────────────────────── -->
<div class="card" style="padding:16px 18px;">
  <div style="font-weight:700;font-size:12px;margin-bottom:10px;"><?= $edit ? 'Edit job #' . (int)$edit->id : 'Add a job' ?></div>
  <form method="post" action="<?=base_url('forecast/save_job?week=' . urlencode($iso_label))?>" id="job-form">
    <?=csrf_field()?>
    <?php if ($edit): ?><input type="hidden" name="id" value="<?=(int)$edit->id?>"><?php endif; ?>
    <input type="hidden" name="equipment_id" value="<?=(int)$machine['equipment_id']?>">
    <div class="jobs-editor-grid">
      <div class="jobs-field">
        <label class="form-label" for="job-type">Job type</label>
        <select id="job-type" name="job_type" class="form-control" required>
          <?php foreach ($job_types as $t): ?>
          <option value="<?=$h($t)?>" data-category="<?=$h($default_category[$t])?>"<?= $current_type === $t ? ' selected' : '' ?>><?=$h(isset($job_type_labels[$t]) ? $job_type_labels[$t] : $t)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="jobs-field jobs-field--wide">
        <label class="form-label" for="job-title">Job description</label>
        <input id="job-title" type="text" name="title" class="form-control" maxlength="200" required
               placeholder="e.g. Service 500 Hrs" value="<?=$h($e('title'))?>">
      </div>
      <div class="jobs-field">
        <label class="form-label" for="job-start-date">Start date</label>
        <input id="job-start-date" type="date" name="start_date" class="form-control" required value="<?=$h($e('start_date', $date))?>">
      </div>
      <div class="jobs-field">
        <label class="form-label" for="job-end-date">End date <span style="font-weight:400;color:var(--t3);">(optional)</span></label>
        <input id="job-end-date" type="date" name="end_date" class="form-control" value="<?=$h($e('end_date', $date))?>">
        <span class="jobs-field-help">Leave unchanged for a single-day job.</span>
      </div>
      <div class="jobs-field">
        <label class="form-label" for="job-hours">Hours per day</label>
        <input id="job-hours" type="number" name="hours_per_day" class="form-control" step="0.25" min="0.25" max="24"
               value="<?=$standby_hours ? '0' : $h($e('hours_per_day'))?>"<?=$standby_hours ? ' disabled' : ' required'?> aria-describedby="job-hours-help">
        <span id="job-hours-help" class="jobs-field-help" aria-live="polite"><?=$standby_hours ? 'Stand-by is always recorded as 0 hours.' : 'Required for this job type; stand-by jobs are recorded as 0 hours.'?></span>
      </div>
      <div class="jobs-field">
        <label class="form-label" for="job-category">Counts as</label>
        <select id="job-category" name="category" class="form-control" aria-describedby="job-category-help">
          <option value="">By job type (default)</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?=$h($c)?>"<?= $e('category') === $c ? ' selected' : '' ?>><?=$h(isset($category_labels[$c]) ? $category_labels[$c] : $c)?></option>
          <?php endforeach; ?>
        </select>
        <span id="job-category-help" class="jobs-field-help">Leave on the default to use the selected job type’s category.</span>
      </div>
      <div class="jobs-field">
        <label class="form-label" for="job-hold-code">Hold reason <span style="font-weight:400;color:var(--t3);">(optional)</span></label>
        <select id="job-hold-code" name="hold_code" class="form-control">
          <option value="">No hold</option>
          <?php foreach ($hold_codes as $c): ?>
          <option value="<?=$h($c)?>"<?= $e('hold_code') === $c ? ' selected' : '' ?>><?=$h($c)?><?=isset($hold_labels[$c]) ? ' — ' . $h($hold_labels[$c]) : ''?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="jobs-field">
        <label class="form-label" for="job-expected-return">Expected return <span style="font-weight:400;color:var(--t3);">(optional)</span></label>
        <input id="job-expected-return" type="date" name="expected_return" class="form-control" value="<?=$h($e('expected_return'))?>">
        <span class="jobs-field-help">Expected back in production.</span>
      </div>
      <div class="jobs-field">
        <label class="form-label" for="job-status">Status</label>
        <select id="job-status" name="status" class="form-control">
          <?php foreach ($statuses as $st): ?>
          <option value="<?=$h($st)?>"<?= $e('status', 'Planned') === $st ? ' selected' : '' ?>><?=$h($st)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="jobs-form-actions">
        <button class="btn btn-sm btn-primary" type="submit"><?= $edit ? 'Save job' : 'Add job' ?></button>
      </div>
    </div>
  </form>
  <div class="cs" style="font-size:11px;margin-top:12px;line-height:1.7;">
    A multi-day repair is one job with an end date. Stand-by jobs carry no hours — the machine is reserve
    capacity that day and gets no standing allowance. A manual entry for the same category on the planned
    screen still wins over the jobs.
  </div>
</div>
<?php endif; ?>

<?php endif; ?>
<?php endif; ?>

<script>
(function () {
  var type = document.getElementById('job-type');
  var hours = document.getElementById('job-hours');
  var help = document.getElementById('job-hours-help');
  if (!type || !hours || !help) return;

  function updateHoursRequirement() {
    var isStandby = type.value === 'Standby';
    hours.disabled = isStandby;
    hours.required = !isStandby;
    if (isStandby) {
      hours.value = '0';
      help.textContent = 'Stand-by is always recorded as 0 hours.';
    } else {
      if (hours.value === '0') hours.value = '';
      help.textContent = 'Required for this job type; stand-by jobs are recorded as 0 hours.';
    }
  }

  type.addEventListener('change', updateHoursRequirement);
  updateHoursRequirement();
}());
</script>
