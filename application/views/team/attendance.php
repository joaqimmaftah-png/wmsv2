<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <form method="get" class="flex ai-c gap-10">
    <input type="date" name="date" class="form-control" style="width:auto;" value="<?= $date ?>" max="<?= date('Y-m-d') ?>">
    <button type="submit" class="btn btn-primary btn-sm">Load</button>
    <a href="<?= base_url('team/attendance?date='.date('Y-m-d')) ?>" class="btn btn-ghost btn-sm">Today</a>
  </form>
  <div class="flex ai-c gap-10">
    <?php if (!empty($punching)): ?>
      <a href="<?= base_url('attendance/log?date='.rawurlencode((string)$date)) ?>" class="btn btn-ghost btn-sm">Punch log →</a>
    <?php endif; ?>
    <a href="<?= base_url('team/report?month=' . rawurlencode(substr((string)$date, 0, 7))) ?>" class="btn btn-primary btn-sm">AML Timesheets &amp; Report →</a>
  </div>
</div>

<div class="card">
  <div class="ch">
    <div><h1 class="ct">Attendance — <?= date('l, d F Y',strtotime($date)) ?></h1><div class="cs"><?= isset($workers)?count($workers):0 ?> workers</div></div>
  </div>
  <?= form_open('team/attendance') ?>
  <input type="hidden" name="date" value="<?= htmlspecialchars((string)($date), ENT_QUOTES, 'UTF-8') ?>">
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:12px;">
      <thead>
        <tr><th>Employee ID</th><th>Name</th><th>Position</th><th>Status</th><th>Check In</th><th>Check Out</th><th>On site</th></tr>
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
              <?php foreach (['Present','Absent','Leave','Rest','Holiday'] as $s): ?>
              <option value="<?= $s ?>" <?= ($att&&$att->status==$s)?'selected':($s==='Present'?'selected':'') ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="time" name="attendance[<?= (int)$w->id ?>][check_in]" class="form-control" style="width:110px;" value="<?= $att && !empty($att->check_in) ? htmlspecialchars((string)(substr((string)$att->check_in, 0, 5)), ENT_QUOTES, 'UTF-8') : '' ?>"></td>
          <td><input type="time" name="attendance[<?= (int)$w->id ?>][check_out]" class="form-control" style="width:110px;" value="<?= $att && !empty($att->check_out) ? htmlspecialchars((string)(substr((string)$att->check_out, 0, 5)), ENT_QUOTES, 'UTF-8') : '' ?>"></td>
          <td>
            <?php if (isset($on_site[(int)$w->id])): ?>
              <span class="badge" style="background:rgba(28,140,60,0.1);color:var(--green);">ON SITE</span>
            <?php else: ?>
              <span style="font-size:11.5px;color:var(--t4);">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="7" style="text-align:center;padding:48px;color:var(--t3);">No workers found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="flex ai-c gap-10" style="justify-content:flex-end;padding:20px 22px 22px;">
    <button type="submit" class="btn btn-primary">Save Attendance</button>
  </div>
  <?= form_close() ?>
</div>
