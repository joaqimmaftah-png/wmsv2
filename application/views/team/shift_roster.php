<?php
$week_dates = [];
for ($i = 0; $i < 7; $i++) {
  $week_dates[] = date('Y-m-d', strtotime($week_start . ' +' . $i . ' days'));
}
$day_names = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div><h4 class="fw-700 mb-0">Shift Roster</h4></div>
  <div class="d-flex gap-2">
    <a href="<?= base_url('team/shift_roster?week=' . date('Y-m-d', strtotime($week_start . ' -7 days'))) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
    <form method="get" class="d-flex gap-2">
      <input type="week" name="week" class="form-control form-control-sm" value="<?= date('Y', strtotime($week_start)) . '-W' . date('W', strtotime($week_start)) ?>">
      <button class="btn btn-primary btn-sm">Go</button>
    </form>
    <a href="<?= base_url('team/shift_roster?week=' . date('Y-m-d', strtotime($week_start . ' +7 days'))) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-right"></i></a>
  </div>
</div>

<div class="wms-card">
  <div class="card-head">
    <i class="bi bi-clock-history text-primary me-1"></i>
    Week of <?= date('d M', strtotime($week_start)) ?> — <?= date('d M Y', strtotime($week_start . ' +6 days')) ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table wms-table mb-0">
        <thead>
          <tr>
            <th>Worker</th>
            <?php foreach ($week_dates as $i => $d): ?>
            <th class="text-center <?= $d == date('Y-m-d') ? 'bg-primary bg-opacity-10' : '' ?>">
              <?= $day_names[$i] ?><br>
              <span style="font-weight:400;font-size:11px;"><?= date('d M', strtotime($d)) ?></span>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($workers as $w): ?>
          <tr>
            <td>
              <div class="fw-600" style="font-size:13px;"><?= $w->first_name . ' ' . $w->last_name ?></div>
              <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars((string)$w->position, ENT_QUOTES, 'UTF-8') ?></div>
            </td>
            <?php foreach ($week_dates as $d): ?>
            <?php
            $shift_id = (isset($assignments[$w->id]) ? $assignments[$w->id] : null);
            $shift_name = '';
            foreach ($shifts as $sh) {
              if ($sh->id == $shift_id) { $shift_name = $sh->name; break; }
            }
            $shift_colors = ['Morning' => 'bg-warning text-dark', 'Afternoon' => 'bg-primary', 'Night' => 'bg-dark'];
            $sc = (isset($shift_colors[$shift_name]) ? $shift_colors[$shift_name] : 'bg-secondary');
            ?>
            <td class="text-center <?= $d == date('Y-m-d') ? 'bg-primary bg-opacity-5' : '' ?>">
              <?php if ($shift_name): ?>
              <span class="badge <?= $sc ?>" style="font-size:10px;"><?= $shift_name ?></span>
              <?php else: ?>
              <span class="text-muted" style="font-size:12px;">—</span>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Legend -->
<div class="mt-3 d-flex gap-3">
  <?php foreach ($shifts as $sh): ?>
  <?php $shift_colors = ['Morning' => 'bg-warning text-dark', 'Afternoon' => 'bg-primary', 'Night' => 'bg-dark']; ?>
  <span class="badge <?= (isset($shift_colors[$sh->name]) ? $shift_colors[$sh->name] : 'bg-secondary') ?> px-3 py-2">
    <?= htmlspecialchars((string)$sh->name, ENT_QUOTES, 'UTF-8') ?>: <?= date('H:i', strtotime($sh->start_time)) ?> – <?= date('H:i', strtotime($sh->end_time)) ?>
  </span>
  <?php endforeach; ?>
</div>
