<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-700">12-Month PM Forecast Calendar</h4>
    <div class="text-muted" style="font-size:13px;">Upcoming preventive maintenance schedule overview</div>
  </div>
  <a href="<?= base_url('pm') ?>" class="btn btn-outline-primary btn-sm">&larr; PM Planner</a>
</div>

<div class="row g-3">
  <?php foreach ($forecast as $month => $pm_items): ?>
  <?php
  $month_name = date('F Y', strtotime($month . '-01'));
  $is_current = $month === date('Y-m');
  $count = count($pm_items);
  ?>
  <div class="col-md-6 col-lg-4">
    <div class="wms-card h-100" style="border-top: 4px solid <?= $is_current ? '#2563a8' : ($count > 0 ? '#f59e0b' : '#e2e8f0') ?>;">
      <div class="card-head" style="background: <?= $is_current ? '#dbeafe' : 'transparent' ?>;">
        <span class="<?= $is_current ? 'fw-700 text-primary' : '' ?>"><?= htmlspecialchars((string)$month_name, ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($is_current): ?>
          <span class="badge b-primary ms-1" style="font-size:10px;">CURRENT</span>
        <?php endif; ?>
        <span class="badge <?= $count > 0 ? 'b-warning' : 'b-secondary' ?> ms-auto"><?= (int)$count ?> PM<?= $count != 1 ? 's' : '' ?></span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($pm_items)): ?>
          <div class="text-center py-3 text-muted" style="font-size:12px;">No PM scheduled</div>
        <?php else: ?>
          <ul class="list-unstyled mb-0">
            <?php foreach ($pm_items as $pm): ?>
            <li class="px-3 py-2 border-bottom">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars((string)$pm->registration_no, ENT_QUOTES, 'UTF-8') ?></div>
                  <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars(trim((string)$pm->brand . ' ' . (string)$pm->model), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <span class="badge b-primary" style="font-size:10px;"><?= (int)$pm->interval_value . htmlspecialchars(substr((string)$pm->interval_type, 0, 1), ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              <div style="font-size:11px;color:#94a3b8;margin-top:2px;"><?= htmlspecialchars((string)$pm->schedule_name, ENT_QUOTES, 'UTF-8') ?></div>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
