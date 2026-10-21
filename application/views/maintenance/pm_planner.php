<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div class="flex ai-c gap-10">
    <form method="get" class="flex ai-c gap-10">
      <select name="equipment_id" class="form-select" style="width:auto;min-width:160px;">
        <option value="">All Equipment</option>
        <?php if (isset($equipment)) foreach ($equipment as $eq): ?>
        <option value="<?= $eq->id ?>" <?= (isset($filter['equipment_id'])&&$filter['equipment_id']==$eq->id)?'selected':'' ?>><?= $eq->registration_no ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" class="form-select" style="width:auto;min-width:140px;">
        <option value="">All Status</option>
        <option value="overdue" <?= (isset($filter['status'])&&$filter['status']==='overdue')?'selected':'' ?>>Overdue</option>
        <option value="due_soon" <?= (isset($filter['status'])&&$filter['status']==='due_soon')?'selected':'' ?>>Due Soon</option>
        <option value="ok" <?= (isset($filter['status'])&&$filter['status']==='ok')?'selected':'' ?>>On Schedule</option>
      </select>
      <button type="submit" class="btn btn-primary btn-sm btn-icon">
        <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Filter
      </button>
    </form>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('pm/calendar') ?>" class="btn btn-ghost btn-sm">12-Month Calendar</a>
    <a href="<?= base_url('pm/create') ?>" class="btn btn-primary btn-sm btn-icon">
      <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
      New PM Schedule
    </a>
  </div>
</div>

<div class="card">
  <div class="ch"><div><div class="ct">PM Schedules</div><div class="cs"><?= isset($pm_schedules)?count($pm_schedules):0 ?> schedules</div></div></div>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:12px;">
      <thead>
        <tr>
          <th>Equipment</th><th>Schedule Name</th><th>Interval</th><th>Last Done</th><th>Next Due</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (isset($pm_schedules) && count($pm_schedules) > 0): foreach ($pm_schedules as $pm):
          $dl = null; $stat = ''; $sc = 'b-available'; $dc = 'var(--green)';
          if (isset($pm->next_due_date)) {
            $dl = round((strtotime($pm->next_due_date) - time()) / 86400);
            if ($dl < 0)     { $stat = 'Overdue ('.abs($dl).'d)'; $sc='b-breakdown'; $dc='var(--red)'; }
            elseif ($dl <= 7){ $stat = 'Due in '.$dl.'d';         $sc='b-warning';   $dc='var(--amber)'; }
            else              { $stat = 'On schedule';              $sc='b-available'; $dc='var(--green)'; }
          }
        ?>
        <tr>
          <td class="fw-600" style="font-size:12.5px;"><?= (isset($pm->registration_no) ? $pm->registration_no : '—') ?></td>
          <td><?= htmlspecialchars((string)($pm->schedule_name)) ?></td>
          <td class="mono" style="font-size:12px;">Every <?= $pm->interval_value ?> <?= $pm->interval_type ?></td>
          <td class="mono" style="font-size:11px;color:var(--t3);"><?= isset($pm->last_done_date)&&$pm->last_done_date ? date('d M Y',strtotime($pm->last_done_date)) : '—' ?></td>
          <td class="mono" style="font-size:11.5px;color:<?= $dc ?>;"><?= isset($pm->next_due_date)&&$pm->next_due_date ? date('d M Y',strtotime($pm->next_due_date)) : '—' ?></td>
          <td><span class="badge <?= $sc ?>" style="font-size:10px;"><?= $stat ?></span></td>
          <td>
            <div class="flex ai-c gap-10">
              <button class="btn btn-ghost btn-sm" style="font-size:11px;padding:5px 12px;" onclick="markDone(<?= $pm->id ?>, <?= (int)$pm->equipment_id ?>)">✓ Done</button>
              <a href="<?= base_url('pm/edit/'.$pm->id) ?>" class="btn btn-ghost btn-sm" style="font-size:11px;padding:5px 12px;">Edit</a>
              <a href="<?= base_url('forecast/parts/'.$pm->id) ?>" class="btn btn-ghost btn-sm" style="font-size:11px;padding:5px 12px;"
                 title="Parts this service consumes — feeds the PM spare-parts forecast">Parts</a>
            </div>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="7" style="text-align:center;padding:48px;color:var(--t3);">No PM schedules. <a href="<?= base_url('pm/create') ?>" style="color:var(--blue);">Create one →</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Mark Done Modal -->
<div id="doneModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);z-index:500;align-items:center;justify-content:center;">
  <div style="background:var(--surface);border:1px solid var(--sep-strong);border-radius:var(--r3);padding:32px;width:400px;max-width:90vw;">
    <div style="font-family:inherit;font-size:17px;font-weight:700;margin-bottom:20px;">Mark PM as Complete</div>
    <?= form_open('pm/complete') ?>
    <input type="hidden" name="pm_id" id="doneId">
    <div class="field" style="margin-bottom:16px;">
      <label class="form-label">Completion Date</label>
      <input type="date" name="completed_date" class="form-control" value="<?= date('Y-m-d') ?>">
    </div>
    <div class="field" style="margin-bottom:16px;">
      <label class="form-label">Meter Reading <span style="font-weight:400;color:var(--t3);">(hours or km per the schedule)</span></label>
      <input type="number" name="completed_hours" class="form-control" step="0.1" placeholder="Hour / odometer reading">
    </div>
    <div class="field" style="margin-bottom:20px;">
      <label class="form-label">Work Order <span style="font-weight:400;color:var(--t3);">(optional — the job that did the service)</span></label>
      <select name="work_order_id" class="form-select">
        <option value="">— not linked to a work order —</option>
        <?php if (isset($open_wos)): foreach ($open_wos as $w): ?>
        <option value="<?= (int)$w->id ?>" data-equipment="<?= (int)$w->equipment_id ?>">
          <?= htmlspecialchars((string)($w->wo_number)) ?> — <?= htmlspecialchars((string)$w->registration_no) ?> — <?= htmlspecialchars((string)($w->title)) ?>
        </option>
        <?php endforeach; endif; ?>
      </select>
      <div style="font-size:10.5px;color:var(--t3);margin-top:4px;">Only a work order on the schedule's machine is accepted.</div>
    </div>
    <div class="field" style="margin-bottom:20px;">
      <label class="form-label">Notes</label>
      <textarea name="notes" class="form-control" rows="2" placeholder="Completion notes..."></textarea>
    </div>
    <div class="flex ai-c gap-10" style="justify-content:flex-end;">
      <button type="button" onclick="document.getElementById('doneModal').style.display='none'" class="btn btn-ghost">Cancel</button>
      <button type="submit" class="btn btn-primary">✓ Mark Complete</button>
    </div>
    <?= form_close() ?>
  </div>
</div>

<script>
function markDone(id, equipmentId) {
  document.getElementById('doneId').value = id;
  // Offer only work orders on this schedule's machine; the server rejects a
  // mismatch, but the modal should never present one in the first place.
  var sel = document.querySelector('#doneModal select[name="work_order_id"]');
  if (sel) {
    for (var i = 0; i < sel.options.length; i++) {
      var opt = sel.options[i];
      var eq  = opt.getAttribute('data-equipment');
      var off = (eq !== null && eq !== '' && String(eq) !== String(equipmentId));
      opt.hidden = off;
      opt.disabled = off;
    }
    sel.value = '';
  }
  document.getElementById('doneModal').style.display = 'flex';
}
</script>
