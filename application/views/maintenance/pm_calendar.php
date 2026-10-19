<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-700">12-Month PM Forecast Calendar</h4>
    <div class="text-muted" style="font-size:13px;">Upcoming preventive maintenance schedule overview</div>
  </div>
  <a href="<?= base_url('pm') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left me-1"></i>PM Planner</a>
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
        <i class="bi bi-calendar3 me-1" style="color:<?= $is_current ? '#2563a8' : '#64748b' ?>;"></i>
        <span class="<?= $is_current ? 'fw-700 text-primary' : '' ?>"><?= $month_name ?></span>
        <?php if ($is_current): ?>
          <span class="badge bg-primary ms-1" style="font-size:10px;">CURRENT</span>
        <?php endif; ?>
        <span class="badge <?= $count > 0 ? 'bg-warning text-dark' : 'bg-light text-muted' ?> ms-auto"><?= $count ?> PM<?= $count != 1 ? 's' : '' ?></span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($pm_items)): ?>
          <div class="text-center py-3 text-muted" style="font-size:12px;">
            <i class="bi bi-check-circle text-success me-1"></i>No PM scheduled
          </div>
        <?php else: ?>
          <ul class="list-unstyled mb-0">
            <?php foreach ($pm_items as $pm): ?>
            <li class="px-3 py-2 border-bottom">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div style="font-weight:600;font-size:13px;"><?= $pm->registration_no ?></div>
                  <div style="font-size:11px;color:#64748b;"><?= $pm->brand . ' ' . $pm->model ?></div>
                </div>
                <span class="badge bg-primary bg-opacity-15 text-primary" style="font-size:10px;"><?= $pm->interval_value . $pm->interval_type[0] ?></span>
              </div>
              <div style="font-size:11px;color:#94a3b8;margin-top:2px;"><?= $pm->schedule_name ?></div>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
