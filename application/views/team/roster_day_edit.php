<?php defined('BASEPATH') OR exit('No direct script access allowed');
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$year = (int)substr($date, 0, 4);
$published = $year_row && $year_row->status === 'Published';
$current = $cell['kind'] === 'Shift' ? 'shift:' . (int)$cell['shift_id'] : $cell['kind'];
?>
<style>
.rd-wrap{max-width:670px;margin:8px auto 40px}.rd-back{font-size:12px;color:var(--blue);text-decoration:none}
.rd-head{margin:22px 0}.rd-head h1{font-size:24px;color:var(--t1);letter-spacing:-.02em}.rd-head p{font-size:12px;color:var(--t2);margin-top:5px}
.rd-card{border:1px solid var(--sep);border-radius:var(--r-md);background:var(--surface);padding:24px}
.rd-info{padding:12px;border-radius:var(--r-sm);background:var(--blue-fill);color:var(--t1);font-size:12px;line-height:1.6;margin:14px 0 18px}
.rd-card label{display:block;font-size:12px;color:var(--t2);font-weight:700;margin:16px 0 5px}
.rd-card .form-select,.rd-card .form-control{width:100%}.rd-card footer{display:flex;gap:9px;justify-content:flex-end;margin-top:22px}
</style>
<div class="rd-wrap">
  <a class="rd-back" href="<?= site_url('team/annual_roster?year=' . $year . '&user=' . (int)$worker->id . '&view=month&month=' . (int)substr($date, 5, 2)) ?>#arCalendar">← Back to month</a>
  <header class="rd-head"><div style="font-size:11px;color:var(--blue);font-weight:700;letter-spacing:.1em;text-transform:uppercase">Date override / <?= (int)$year ?></div><h1><?= date('l, d F Y', strtotime($date)) ?></h1>
    <p><?= $e($worker->first_name . ' ' . $worker->last_name) ?> · <?= $e($worker->employee_id) ?> · <?= $published ? 'Published plan' : 'Draft plan' ?></p></header>
  <section class="rd-card" aria-label="Edit a single planned day">
    <h2 style="font-size:15px">Current expectation: <?= $e($cell['kind'] === 'Shift' ? 'Shift' : $cell['kind']) ?></h2>
    <div class="rd-info">A planned Off day means not expected on site. A shift means scheduled work; neither creates actual attendance. <?= $published ? 'Every published change requires a reason and is recorded in the audit trail.' : 'Draft changes become visible to staff only when the complete year is published.' ?></div>
    <?php if ($date < date('Y-m-d')): ?>
      <p>Historical schedule dates are locked. Use <a href="<?= site_url('team/report') ?>">actual attendance</a> for a past-day correction.</p>
    <?php elseif ($cell['kind'] === 'Leave' || !empty($cell['extra']) || !empty($cell['replacement'])): ?>
      <p><?= $cell['kind'] === 'Leave' ? 'This date is inside a planned/approved leave block.' : (!empty($cell['replacement']) ? 'This Off day replaces work on ' . $e($cell['replacement']->work_date) . '; it must be undone before changing its base day.' : 'This Off day has an authorised extra-work exception.') ?> Resolve it on the <a href="<?= site_url('team/annual_roster?year=' . (!empty($cell['replacement']) ? (int)substr($cell['replacement']->work_date, 0, 4) : $year) . '&user=' . (int)$worker->id) ?>#arExtra">annual plan</a> before changing the base day.</p>
    <?php else: ?>
      <?= form_open('team/roster_day_save') ?><input type="hidden" name="date" value="<?= $date ?>"><input type="hidden" name="user_id" value="<?= (int)$worker->id ?>">
        <label for="rdType">Plan for this date</label><select class="form-select" name="day_type" id="rdType" required>
          <?php if (!$published): ?><option value="Unplanned" <?= $current === 'Unplanned' ? 'selected' : '' ?>>Needs plan (clear draft day)</option><?php endif; ?>
          <option value="Off" <?= $current === 'Off' ? 'selected' : '' ?>>Off · unavailable on site</option>
          <option value="Holiday" <?= $current === 'Holiday' ? 'selected' : '' ?>>Public holiday</option>
          <?php foreach ($shifts as $shift): $value = 'shift:' . (int)$shift->id; ?><option value="<?= $value ?>" <?= $current === $value ? 'selected' : '' ?>><?= $e($shift->name) ?> · <?= substr($shift->start_time,0,5) ?>–<?= substr($shift->end_time,0,5) ?></option><?php endforeach; ?>
        </select>
        <label for="rdReason">Reason for change <?= $published ? '(required)' : '(optional in draft)' ?></label><input class="form-control" id="rdReason" name="reason" maxlength="255" <?= $published ? 'required minlength="5"' : '' ?> placeholder="What changed and why?">
        <footer><a class="btn btn-ghost btn-sm" href="<?= site_url('team/annual_roster?year=' . $year . '&user=' . (int)$worker->id) ?>">Cancel</a><button class="btn btn-primary btn-sm" type="submit">Save day</button></footer>
      <?= form_close() ?>
    <?php endif; ?>
  </section>
</div>
