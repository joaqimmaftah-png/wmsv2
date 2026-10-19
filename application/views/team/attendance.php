<div class="flex ai-c jb mb-4">
  <form method="get" class="flex ai-c gap-10">
    <input type="date" name="date" class="form-control" style="width:auto;" value="<?= $date ?>" max="<?= date('Y-m-d') ?>">
    <button type="submit" class="btn btn-primary btn-sm">Load</button>
    <a href="<?= base_url('team/attendance?date='.date('Y-m-d')) ?>" class="btn btn-ghost btn-sm">Today</a>
  </form>
  <a href="<?= base_url('team/report') ?>" class="btn btn-ghost btn-sm">Monthly Report →</a>
</div>

<div class="card">
  <div class="ch">
    <div><div class="ct">Attendance — <?= date('l, d F Y',strtotime($date)) ?></div><div class="cs"><?= isset($workers)?count($workers):0 ?> workers</div></div>
  </div>
  <?= form_open('team/attendance') ?>
  <input type="hidden" name="date" value="<?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?>">
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:12px;">
      <thead>
        <tr><th>Employee ID</th><th>Name</th><th>Position</th><th>Status</th><th>Check In</th><th>Check Out</th></tr>
      </thead>
      <tbody>
        <?php if (isset($workers) && count($workers) > 0): foreach ($workers as $w):
          $att = null;
          if (isset($attendance) && is_array($attendance)) {
            foreach ($attendance as $a) { if ($a->user_id == $w->id) { $att = $a; break; } }
          }
        ?>
        <tr>
          <td class="mono" style="font-size:11px;color:var(--t3);"><?= $w->employee_id ?></td>
          <td class="fw-600" style="font-size:12.5px;"><?= $w->first_name.' '.$w->last_name ?></td>
          <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)$w->position, ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <select name="attendance[<?= $w->id ?>][status]" class="form-select" style="width:130px;">
              <?php foreach (['Present','Absent','Leave','Rest'] as $s): ?>
              <option value="<?= $s ?>" <?= ($att&&$att->status==$s)?'selected':($s==='Present'?'selected':'') ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="time" name="attendance[<?= (int)$w->id ?>][check_in]" class="form-control" style="width:110px;" value="<?= $att && !empty($att->check_in) ? htmlspecialchars(substr((string)$att->check_in, 0, 5), ENT_QUOTES, 'UTF-8') : '' ?>"></td>
          <td><input type="time" name="attendance[<?= (int)$w->id ?>][check_out]" class="form-control" style="width:110px;" value="<?= $att && !empty($att->check_out) ? htmlspecialchars(substr((string)$att->check_out, 0, 5), ENT_QUOTES, 'UTF-8') : '' ?>"></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="6" style="text-align:center;padding:48px;color:var(--t3);">No workers found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="flex ai-c gap-10" style="justify-content:flex-end;padding:20px 22px 22px;">
    <button type="submit" class="btn btn-primary">Save Attendance</button>
  </div>
  <?= form_close() ?>
</div>
