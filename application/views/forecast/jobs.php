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
$h = function ($s) { return htmlspecialchars((string)$s); };

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
$day_warn = null;
foreach ($warnings as $w) {
    if ($machine && $w['equipment_id'] === $machine['equipment_id'] && $w['date'] === $date) $day_warn = $w;
}
$back = base_url('forecast?week=' . urlencode($iso_label));
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">
      Maintenance jobs — <?= $machine ? $h($machine['registration_no']) : 'choose a machine' ?>
    </div>
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

<form method="get" class="card mb-4 flex ai-c gap-10" style="padding:12px 18px;flex-wrap:wrap;">
  <input type="hidden" name="week" value="<?=$h($iso_label)?>">
  <select name="equipment_id" class="form-control" style="width:200px;">
    <?php foreach ($machines as $m): ?>
    <option value="<?=$m['equipment_id']?>"<?= ($machine && $m['equipment_id'] === $machine['equipment_id']) ? ' selected' : '' ?>><?=$h($m['registration_no'])?></option>
    <?php endforeach; ?>
  </select>
  <select name="date" class="form-control" style="width:170px;">
    <?php foreach ($days as $d): ?>
    <option value="<?=$d?>"<?= $d === $date ? ' selected' : '' ?>><?=date('D d/m', strtotime($d))?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-sm btn-primary" type="submit">Open</button>
</form>

<?php if ($machine): ?>

<?php if ($frozen): ?>
<div class="card mb-4" style="padding:12px 18px;border-left:3px solid var(--green);font-size:12px;">
  <?=$h($iso_label)?> is published and frozen — its jobs cannot be changed. Raise a revision on the planned screen.
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
  <?php endif; ?>
</div>

<!-- ── The day's jobs ────────────────────────────────────────────────── -->
<div class="card mb-4" style="overflow-x:auto;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;"><?=count($day_jobs)?> job<?=count($day_jobs) === 1 ? '' : 's'?> on this day</div>
    <div class="cs">Every job is kept; their hours add up per category.</div>
  </div>
  <table class="tbl">
    <thead><tr><th>CODE</th><th>JOB</th><th>DATES</th><th style="text-align:right;">H/DAY</th><th>COUNTS AS</th><th>HOLD</th><th>ETA</th><th>STATUS</th><th></th></tr></thead>
    <tbody>
    <?php if (!$day_jobs): ?>
      <tr><td colspan="9" style="color:var(--t3);text-align:center;">No jobs planned on this day.</td></tr>
    <?php endif; ?>
    <?php foreach ($day_jobs as $j): ?>
      <tr class="job-row">
        <td class="mono" style="font-weight:700;"><?=$h(isset($codes[$j['job_type']]) ? $codes[$j['job_type']] : $j['job_type'])?></td>
        <td><?=$h($j['title'])?></td>
        <td class="mono" style="font-size:10.5px;"><?=$h($j['start_date'])?><?= $j['end_date'] !== $j['start_date'] ? ' → ' . $h($j['end_date']) : '' ?></td>
        <td class="mono" style="text-align:right;"><?=$hrs($j['hours_per_day'])?></td>
        <td><?=$h($j['category'])?></td>
        <td><?=$h($j['hold_code'] ?: '—')?></td>
        <td><?=$h($j['expected_return'] ?: '—')?></td>
        <td><?=$h($j['status'])?></td>
        <td style="white-space:nowrap;">
          <?php if ($editable): ?>
          <a class="btn btn-sm" href="<?=base_url('forecast/jobs?week=' . urlencode($iso_label) . '&equipment_id=' . $machine['equipment_id'] . '&date=' . $date . '&edit=' . $j['id'])?>">Edit</a>
          <form method="post" action="<?=base_url('forecast/delete_job?week=' . urlencode($iso_label))?>" style="display:inline;"
                onsubmit="return confirm('Remove this job? Its hours leave the plan on every day it covers.');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?=$j['id']?>">
            <input type="hidden" name="equipment_id" value="<?=$machine['equipment_id']?>">
            <input type="hidden" name="date" value="<?=$date?>">
            <button class="btn btn-sm" type="submit">Remove</button>
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
  <?php $bits = array(); foreach ($week_jobs as $j) $bits[] = $h((isset($codes[$j['job_type']]) ? $codes[$j['job_type']] : $j['job_type']) . ' ' . $j['start_date']); echo implode(' · ', $bits); ?>
</div>
<?php endif; ?>

<?php if ($editable): ?>
<!-- ── Add / edit ────────────────────────────────────────────────────── -->
<div class="card" style="padding:16px 18px;">
  <div style="font-weight:700;font-size:12px;margin-bottom:10px;"><?= $edit ? 'Edit job #' . (int)$edit->id : 'Add a job' ?></div>
  <form method="post" action="<?=base_url('forecast/save_job?week=' . urlencode($iso_label))?>"
        class="flex gap-10 ai-c" style="flex-wrap:wrap;" id="job-form">
    <?= csrf_field() ?>
    <?php if ($edit): ?><input type="hidden" name="id" value="<?=(int)$edit->id?>"><?php endif; ?>
    <input type="hidden" name="equipment_id" value="<?=$machine['equipment_id']?>">
    <select name="job_type" class="form-control" style="width:140px;" required>
      <?php foreach ($job_types as $t): ?>
      <option value="<?=$t?>" data-category="<?=$h($default_category[$t])?>"<?= $e('job_type') === $t ? ' selected' : '' ?>><?=$h($t)?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="title" class="form-control" style="width:240px;" maxlength="200" required
           placeholder="e.g. Service 500 Hrs" value="<?=$h($e('title'))?>">
    <label class="cs" style="font-size:11px;">from</label>
    <input type="date" name="start_date" class="form-control" style="width:150px;" required value="<?=$h($e('start_date', $date))?>">
    <label class="cs" style="font-size:11px;">to</label>
    <input type="date" name="end_date" class="form-control" style="width:150px;" value="<?=$h($e('end_date', $date))?>">
    <input type="number" name="hours_per_day" class="form-control" style="width:100px;" step="0.25" min="0" max="24"
           placeholder="h / day" value="<?=$h($e('hours_per_day'))?>">
    <select name="category" class="form-control" style="width:160px;" title="What the hours count as in availability">
      <option value="">counts as: by type</option>
      <?php foreach ($categories as $c): ?>
      <option value="<?=$c?>"<?= $e('category') === $c ? ' selected' : '' ?>><?=$h($c)?></option>
      <?php endforeach; ?>
    </select>
    <select name="hold_code" class="form-control" style="width:110px;" title="Why it waits (D17)">
      <option value="">no hold</option>
      <?php foreach ($hold_codes as $c): ?>
      <option value="<?=$c?>"<?= $e('hold_code') === $c ? ' selected' : '' ?>><?=$c?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="expected_return" class="form-control" style="width:150px;" title="Expected back in production"
           value="<?=$h($e('expected_return'))?>">
    <select name="status" class="form-control" style="width:120px;">
      <?php foreach ($statuses as $st): ?>
      <option value="<?=$st?>"<?= $e('status', 'Planned') === $st ? ' selected' : '' ?>><?=$st?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-primary" type="submit"><?= $edit ? 'Save job' : 'Add job' ?></button>
  </form>
  <div class="cs" style="font-size:11px;margin-top:8px;line-height:1.7;">
    A multi-day repair is one job with an end date. Stand-by jobs carry no hours — the machine is reserve
    capacity that day and gets no standing allowance. A manual entry for the same category on the planned
    screen still wins over the jobs.
  </div>
</div>
<?php endif; ?>

<?php endif; ?>
<?php endif; ?>
