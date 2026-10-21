<?php
$week_dates = array();
for ($i = 0; $i < 7; $i++) {
  $week_dates[] = date('Y-m-d', strtotime($week_start . ' +' . $i . ' days'));
}
$day_names = array('Mon','Tue','Wed','Thu','Fri','Sat','Sun');
$can_edit  = !empty($can_edit);

$shift_by_id = array();
foreach ($shifts as $sh) { $shift_by_id[(int)$sh->id] = $sh; }

$shift_colors = array('Morning' => 'b-warning', 'Afternoon' => 'b-primary', 'Night' => 'b-secondary');
$shift_color  = function ($name) use ($shift_colors) {
    return isset($shift_colors[$name]) ? $shift_colors[$name] : 'b-secondary';
};
$week_input = date('o-\WW', strtotime($week_start));
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:12px;">
  <div>
    <div style="font-size:22px;font-weight:700;color:var(--t1);letter-spacing:-0.3px;">Shift Roster</div>
    <div style="font-size:12px;color:var(--t3);margin-top:2px;">
      Week of <?= date('d M', strtotime($week_start)) ?> — <?= date('d M Y', strtotime($week_start . ' +6 days')) ?>
    </div>
  </div>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <a href="<?= base_url('team/shift_roster?week=' . date('Y-m-d', strtotime($week_start . ' -7 days'))) ?>" class="btn btn-outline-secondary btn-sm" title="Previous week">&#8249;</a>
    <form method="get" action="<?= base_url('team/shift_roster') ?>" class="flex ai-c gap-10">
      <input type="week" name="week" class="form-control" style="width:auto;" value="<?= $week_input ?>">
      <button class="btn btn-primary btn-sm">Go</button>
    </form>
    <a href="<?= base_url('team/shift_roster?week=' . date('Y-m-d', strtotime($week_start . ' +7 days'))) ?>" class="btn btn-outline-secondary btn-sm" title="Next week">&#8250;</a>
    <a href="<?= base_url('team/shift_roster?week=' . date('Y-m-d', strtotime('monday this week'))) ?>" class="btn btn-ghost btn-sm">This week</a>
  </div>
</div>

<div class="wms-card">
  <div class="card-head">
    <span class="flex ai-c gap-10">
      <span>Roster</span>
      <span style="font-weight:400;color:var(--t3);font-size:12px;"><?= count($workers) ?> active staff</span>
    </span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table wms-table mb-0">
        <thead>
          <tr>
            <th>Worker</th>
            <?php foreach ($week_dates as $i => $d): ?>
            <th style="text-align:center;<?= $d == date('Y-m-d') ? 'background:var(--blue-fill);' : '' ?>">
              <?= $day_names[$i] ?><br>
              <span style="font-weight:400;font-size:11px;"><?= date('d M', strtotime($d)) ?></span>
            </th>
            <?php endforeach; ?>
            <?php if ($can_edit): ?><th style="text-align:right;"><?= $week_input ?></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($workers)): ?>
          <tr><td colspan="<?= $can_edit ? 9 : 8 ?>" style="text-align:center;padding:40px;color:var(--t3);">No active staff.</td></tr>
          <?php else: foreach ($workers as $w): ?>
          <?php
            $shift_id   = isset($assignments[$w->id]) ? (int)$assignments[$w->id] : 0;
            $shift      = isset($shift_by_id[$shift_id]) ? $shift_by_id[$shift_id] : null;
            $shift_name = $shift ? $shift->name : '';
          ?>
          <tr>
            <td>
              <div class="fw-600" style="font-size:13px;"><?= $w->first_name . ' ' . $w->last_name ?></div>
              <div style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)$w->position, ENT_QUOTES, 'UTF-8') ?></div>
            </td>
            <?php foreach ($week_dates as $d): ?>
            <td style="text-align:center;<?= $d == date('Y-m-d') ? 'background:rgba(0,113,227,0.03);' : '' ?>">
              <?php if ($shift): ?>
                <span class="badge <?= $shift_color($shift_name) ?>" style="font-size:10px;"><?= htmlspecialchars((string)($shift_name)) ?></span>
              <?php else: ?>
                <span class="text-muted" style="font-size:12px;">—</span>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
            <?php if ($can_edit): ?>
            <td style="text-align:right;">
              <?= form_open('team/assign_shift', array('class' => 'flex ai-c gap-10', 'style' => 'justify-content:flex-end;')) ?>
                <input type="hidden" name="user_id" value="<?= (int)$w->id ?>">
                <input type="hidden" name="week" value="<?= $week_start ?>">
                <select name="shift_id" class="form-select" style="width:130px;font-size:11.5px;padding:4px 8px;">
                  <option value="0">— none —</option>
                  <?php foreach ($shifts as $sh): ?>
                  <option value="<?= (int)$sh->id ?>" <?= ((int)$sh->id === $shift_id) ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)($sh->name)) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;padding:4px 10px;">Set</button>
              <?= form_close() ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Legend -->
<?php if (!empty($shifts)): ?>
<div class="flex ai-c gap-10 mb-4" style="flex-wrap:wrap;">
  <?php foreach ($shifts as $sh): ?>
  <span class="badge <?= $shift_color($sh->name) ?>" style="padding:6px 12px;">
    <?= htmlspecialchars((string)($sh->name)) ?>: <?= date('H:i', strtotime($sh->start_time)) ?> – <?= date('H:i', strtotime($sh->end_time)) ?>
  </span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($can_edit): ?>
<!-- Shift types -->
<div class="wms-card mb-4">
  <div class="card-head">Shift Types</div>

  <?php if (empty($shifts)): ?>
  <div style="padding:20px 24px;color:var(--t3);font-size:12.5px;">No shift types yet — add the first one below.</div>
  <?php else: foreach ($shifts as $sh): ?>
  <?php $used = isset($assignment_counts[(int)$sh->id]) ? (int)$assignment_counts[(int)$sh->id] : 0; ?>
  <!-- one form per row: a <form> cannot legally wrap <td>s -->
  <div style="padding:10px 24px;border-bottom:1px solid var(--sep);">
    <?= form_open('team/shift_save', array('class' => 'flex ai-c gap-10', 'style' => 'flex-wrap:wrap;')) ?>
      <input type="hidden" name="shift_id" value="<?= (int)$sh->id ?>">
      <input type="hidden" name="week" value="<?= $week_start ?>">
      <input type="text" name="name" class="form-control" style="width:160px;font-size:12px;padding:5px 8px;"
             value="<?= htmlspecialchars((string)($sh->name)) ?>" required maxlength="50">
      <label style="font-size:11px;color:var(--t3);">from</label>
      <input type="time" name="start_time" class="form-control" style="width:115px;font-size:12px;padding:5px 8px;"
             value="<?= date('H:i', strtotime($sh->start_time)) ?>" required>
      <label style="font-size:11px;color:var(--t3);">to</label>
      <input type="time" name="end_time" class="form-control" style="width:115px;font-size:12px;padding:5px 8px;"
             value="<?= date('H:i', strtotime($sh->end_time)) ?>" required>
      <input type="text" name="description" class="form-control" style="width:220px;font-size:12px;padding:5px 8px;"
             value="<?= htmlspecialchars((string)$sh->description) ?>" maxlength="200" placeholder="Description (optional)">
      <span class="mono" style="font-size:11.5px;color:var(--t3);white-space:nowrap;"><?= $used ?> roster row(s)</span>
      <button type="submit" class="btn btn-outline-primary btn-sm" style="font-size:11px;padding:4px 10px;">Save</button>
    <?= form_close() ?>
    <div style="margin-top:4px;">
      <?= form_open('team/shift_delete/'.$sh->id, array('style' => 'display:inline;')) ?>
        <input type="hidden" name="week" value="<?= $week_start ?>">
        <button type="submit" class="btn btn-outline-danger btn-sm" style="font-size:10.5px;padding:2px 8px;"
                onclick="return confirm('Delete shift “<?= htmlspecialchars((string)($sh->name), ENT_QUOTES, 'UTF-8') ?>”?');">Delete this shift</button>
      <?= form_close() ?>
      <?php if ($used > 0): ?>
      <span style="font-size:10.5px;color:var(--t3);margin-left:8px;">Still assigned — clear the roster first.</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <div style="padding:16px 24px;">
    <div style="font-size:12px;font-weight:600;color:var(--t2);margin-bottom:10px;">Add a shift type</div>
    <?= form_open('team/shift_save', array('class' => 'flex ai-c gap-10', 'style' => 'flex-wrap:wrap;')) ?>
      <input type="hidden" name="week" value="<?= $week_start ?>">
      <input type="text" name="name" class="form-control" style="width:170px;font-size:12px;" placeholder="Name (e.g. Morning)" required maxlength="50">
      <input type="time" name="start_time" class="form-control" style="width:120px;font-size:12px;" value="06:00" required>
      <input type="time" name="end_time" class="form-control" style="width:120px;font-size:12px;" value="14:00" required>
      <input type="text" name="description" class="form-control" style="width:260px;font-size:12px;" placeholder="Description (optional)" maxlength="200">
      <button type="submit" class="btn btn-primary btn-sm">Add Shift</button>
    <?= form_close() ?>
  </div>
</div>
<?php endif; ?>
