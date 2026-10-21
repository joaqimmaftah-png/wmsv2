<?php $kpi = $kpi; ?>

<div class="row mb-3">
  <div class="col">
    <h4 class="fw-700"><?= $equipment->registration_no ?> — <?= $equipment->brand . ' ' . $equipment->model ?></h4>
    <div style="font-size:13px;color:#64748b;"><?= $equipment->type_name ?> | <?= $equipment->year ?> | SN: <?= $equipment->serial_number ?></div>
  </div>
  <div class="col-auto d-flex gap-2">
    <?php if ($role_id == 1): ?>
    <a href="<?= base_url('equipment/edit/' . $equipment->id) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
    <?php endif; ?>
    <a href="<?= base_url('workorders/create') ?>" class="btn btn-success btn-sm"><i class="bi bi-plus-circle me-1"></i>New WO</a>
    <a href="<?= base_url('equipment') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="kpi-card <?= $kpi['availability'] >= 90 ? 'green' : ($kpi['availability'] >= 75 ? 'amber' : 'red') ?>">
      <div class="kpi-value"><?= $kpi['availability'] ?>%</div>
      <div class="kpi-label">Availability</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card purple">
      <div class="kpi-value"><?= $kpi['mttr'] ?><span style="font-size:14px;font-weight:400;"> hrs</span></div>
      <div class="kpi-label">MTTR</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card blue">
      <div class="kpi-value"><?= $kpi['mtbf'] ?><span style="font-size:14px;font-weight:400;"> hrs</span></div>
      <div class="kpi-label">MTBF</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card red">
      <div class="kpi-value"><?= $kpi['breakdowns'] ?></div>
      <div class="kpi-label">Total Breakdowns</div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <!-- Equipment Details -->
    <div class="wms-card mb-3">
      <div class="card-head"><i class="bi bi-info-circle text-primary me-1"></i>Equipment Details</div>
      <div class="card-body">
        <table style="width:100%;font-size:13px;">
          <?php
          // W0.3 — weekly-report position (columns may be absent on older databases)
          $w36_group = isset($equipment->report_group) ? $equipment->report_group : '';
          $w36_sort  = isset($equipment->report_sort)  ? (int)$equipment->report_sort  : 0;
          $w36_in    = equip_is_reportable($equipment);
          // W0.5 / W0.6 — the short id the weekly report prints, and whether the
          // stored location is part of the approved site list
          $w36_sites = isset($report_sites) ? $report_sites : array();
          $w36_loc   = trim((string)$equipment->location);
          $w36_site  = $w36_loc === '' ? '<span style="color:var(--amber);">no site</span>'
                     : (!empty($w36_sites) && equip_is_approved_site($w36_loc, $w36_sites)
                        ? htmlspecialchars((string)(equip_site_label($w36_loc, $w36_sites)))
                        : htmlspecialchars((string)($w36_loc)) . ' <span class="badge b-warning">not approved</span>');
          $fields = [
            'Status' => $equipment->status,
            'Machine ID (report)' => htmlspecialchars((string)(equip_machine_id($equipment))),
            'Location' => $w36_site,
            'Weekly Report' => $w36_in
                ? ($w36_group ? $w36_group . ($w36_sort ? ' / ' . $w36_sort : '') : 'included — no group yet')
                : 'excluded',
            'Brand' => $equipment->brand,
            'Model' => $equipment->model,
            'Engine Model' => $equipment->engine_model ?: '—',
            'Year' => $equipment->year,
            'Serial No.' => $equipment->serial_number ?: '—',
            'Frame No.' => isset($equipment->frame_no) && $equipment->frame_no ? $equipment->frame_no : '—',
            'Engine Serial' => isset($equipment->engine_serial) && $equipment->engine_serial ? $equipment->engine_serial : '—',
            'Machine Type' => isset($equipment->machine_type) && $equipment->machine_type ? $equipment->machine_type : '—',
            'Old / Legacy Code' => isset($equipment->legacy_code) && $equipment->legacy_code ? $equipment->legacy_code : '—',
            'Purchase Date' => $equipment->purchase_date ? date('d M Y', strtotime($equipment->purchase_date)) : '—',
            'Warranty Expiry' => $equipment->warranty_expiry ? date('d M Y', strtotime($equipment->warranty_expiry)) : '—',
          ];
          foreach ($fields as $label => $val): ?>
          <tr>
            <td class="text-muted py-1" style="width:45%"><?= $label ?></td>
            <td class="fw-500"><?= $val ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <hr class="my-2">
        <div class="row text-center">
          <div class="col-6">
            <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;">Hours Meter</div>
            <div style="font-weight:700;font-size:20px;color:#1a3c5e;"><?= number_format((float)($equipment->current_hours), 0) ?></div>
            <div style="font-size:11px;color:#94a3b8;">hours</div>
          </div>
          <div class="col-6">
            <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;">Odometer</div>
            <div style="font-weight:700;font-size:20px;color:#1a3c5e;"><?= number_format((float)($equipment->current_km), 0) ?></div>
            <div style="font-size:11px;color:#94a3b8;">km</div>
          </div>
        </div>

        <?php if ($role_id == 1): ?>
        <hr class="my-2">
        <?= form_open('equipment/update_hours/' . $equipment->id) ?>
        <div class="row g-2">
          <div class="col-6"><input type="number" name="current_hours" class="form-control form-control-sm" step="0.1" value="<?= $equipment->current_hours ?>" placeholder="Hours"></div>
          <div class="col-6"><input type="number" name="current_km" class="form-control form-control-sm" step="0.1" value="<?= $equipment->current_km ?>" placeholder="KM"></div>
          <div class="col-12">
            <div class="form-check" style="font-size:11px;">
              <input class="form-check-input" type="checkbox" name="meter_override" value="1" id="meterOverride<?= $equipment->id ?>">
              <label class="form-check-label text-muted" for="meterOverride<?= $equipment->id ?>">
                This is a correction — the previous reading was wrong (allows a lower value)
              </label>
            </div>
          </div>
          <div class="col-12"><button type="submit" class="btn btn-sm btn-outline-primary w-100">Update Readings</button></div>
        </div>
        <?= form_close() ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($specs)): ?>
    <!-- Specification sheet — the manufacturer data the asset list carried -->
    <div class="wms-card mb-3">
      <div class="card-head">
        <i class="bi bi-card-list text-primary me-1"></i>Specifications
        <span class="text-muted" style="font-weight:400;font-size:11px;">(<?= count($specs) ?> recorded)</span>
      </div>
      <div class="card-body p-0">
        <table style="width:100%;font-size:12.5px;">
          <?php foreach ($specs as $sp): ?>
          <tr>
            <td class="text-muted py-1 px-3" style="width:48%"><?= htmlspecialchars((string)($sp->spec_label !== '' ? $sp->spec_label : $sp->spec_key)) ?></td>
            <td class="fw-500 py-1 px-3"><?= htmlspecialchars((string)($sp->spec_value)) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- PM Schedules -->
    <div class="wms-card">
      <div class="card-head">
        <i class="bi bi-calendar-check text-primary me-1"></i>PM Schedules
        <a href="<?= base_url('pm/create') ?>" class="btn btn-sm btn-outline-primary ms-auto" style="font-size:11px;"><i class="bi bi-plus"></i> Add PM</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($pm_list)): ?>
          <div class="text-center py-3 text-muted" style="font-size:13px;">No PM schedules</div>
        <?php else: ?>
          <?php foreach ($pm_list as $pm): ?>
          <?php $overdue = $pm->next_due_date && strtotime($pm->next_due_date) < time(); ?>
          <div class="px-3 py-2 border-bottom <?= $overdue ? 'bg-danger bg-opacity-5' : '' ?>">
            <div class="d-flex justify-content-between">
              <div style="font-size:13px;font-weight:600;"><?= $pm->schedule_name ?></div>
              <span class="badge <?= $overdue ? 'bg-danger' : 'bg-primary' ?>" style="font-size:10px;">
                <?= $pm->interval_value . ' ' . $pm->interval_type ?>
              </span>
            </div>
            <div style="font-size:11px;color:<?= $overdue ? '#dc2626' : '#64748b' ?>;">
              Next: <?= $pm->next_due_date ? date('d M Y', strtotime($pm->next_due_date)) : '—' ?>
              <?= $overdue ? ' ⚠ OVERDUE' : '' ?>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- WO History -->
  <div class="col-lg-8">
    <div class="wms-card">
      <div class="card-head"><i class="bi bi-clock-history text-primary me-1"></i>Work Order History</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table wms-table mb-0">
            <thead><tr><th>WO Number</th><th>Type</th><th>Status</th><th>Title</th><th>Date</th><th>Downtime</th></tr></thead>
            <tbody>
              <?php foreach ($wo_history as $wo): ?>
              <tr>
                <td><a href="<?= base_url('workorders/view/' . $wo->id) ?>" style="font-weight:600;color:#1a3c5e;"><?= $wo->wo_number ?></a></td>
                <td><span class="badge bg-primary" style="font-size:11px;"><?= $wo->type ?></span></td>
                <td><span class="status-badge badge-<?= str_replace(' ', '.', $wo->status) ?>"><?= $wo->status ?></span></td>
                <td style="font-size:12px;"><?= htmlspecialchars((string)(strlen((string)($wo->title))>40 ? substr($wo->title,0,37).'...' : $wo->title), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="font-size:12px;white-space:nowrap;"><?= date('d M Y', strtotime($wo->created_at)) ?></td>
                <td style="font-size:12px;"><?= $wo->downtime_hours ? number_format((float)($wo->downtime_hours),1).' hrs' : '—' ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($wo_history)): ?>
              <tr><td colspan="6" class="text-center text-muted py-3">No work orders found</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
