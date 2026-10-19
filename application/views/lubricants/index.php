<?php $month_name = date('F Y', strtotime($filter['month'] . '-01')); ?>

<!-- Filters -->
<div class="wms-card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label mb-1" style="font-size:11px;">Month</label>
        <input type="month" name="month" class="form-control form-control-sm" value="<?= $filter['month'] ?>">
      </div>
      <div class="col-auto">
        <label class="form-label mb-1" style="font-size:11px;">Equipment</label>
        <select name="equipment_id" class="form-select form-select-sm">
          <option value="">All Equipment</option>
          <?php foreach ($equipment as $eq): ?>
          <option value="<?= $eq->id ?>" <?= ((isset($filter['equipment_id']) ? $filter['equipment_id'] : '')) == $eq->id ? 'selected' : '' ?>><?= $eq->registration_no ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label mb-1" style="font-size:11px;">Lube Type</label>
        <select name="lube_type_id" class="form-select form-select-sm">
          <option value="">All Types</option>
          <?php foreach ($lube_types as $lt): ?>
          <option value="<?= $lt->id ?>" <?= ((isset($filter['lube_type_id']) ? $filter['lube_type_id'] : '')) == $lt->id ? 'selected' : '' ?>><?= htmlspecialchars((string)$lt->name, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
        <a href="<?= base_url('lubricants') ?>" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
      </div>
      <div class="col-auto ms-auto">
        <a href="<?= base_url('lubricants/record') ?>" class="btn btn-success btn-sm me-1">
          <i class="bi bi-plus-circle me-1"></i>Record Consumption
        </a>
        <a href="<?= base_url('reports/export_excel/lubricants?month=' . $filter['month']) ?>" class="btn btn-outline-success btn-sm">
          <i class="bi bi-file-earmark-excel me-1"></i>Export CSV
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Monthly Summary Cards -->
<div class="row g-3 mb-3">
  <?php foreach ($summary as $s): ?>
  <div class="col-md-4 col-lg-3">
    <div class="wms-card">
      <div class="card-body py-2">
        <div style="font-size:11px;text-transform:uppercase;color:#94a3b8;letter-spacing:0.5px;"><?= $s->category ?></div>
        <div style="font-weight:700;font-size:18px;color:#1a3c5e;"><?= number_format($s->total_used, 1) ?> <span style="font-size:12px;font-weight:400;color:#64748b;"><?= $s->unit ?></span></div>
        <div style="font-size:12px;color:#64748b;"><?= $s->lube_name ?></div>
        <div style="font-size:11px;color:#94a3b8;"><?= $s->records ?> records</div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (empty($summary)): ?>
  <div class="col-12">
    <div class="alert alert-info mb-0" style="font-size:13px;">No consumption data for <?= $month_name ?>.</div>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($compliance)): ?>
<!-- PM-Driven Compliance Summary -->
<div class="wms-card mb-3">
  <div class="card-head d-flex justify-content-between align-items-center">
    <span><i class="bi bi-clipboard2-check text-primary me-1"></i>PM Compliance — <?= $month_name ?></span>
    <a href="<?= base_url('reports/lube_compliance?month='.$filter['month']) ?>" class="btn btn-sm btn-outline-primary">
      Full Report <i class="bi bi-arrow-right ms-1"></i>
    </a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table wms-table mb-0">
        <thead>
          <tr>
            <th>Lube Type</th>
            <th class="text-center">PM Services Due</th>
            <th class="text-center">Required Qty</th>
            <th class="text-center">Actual Used</th>
            <th class="text-center">Variance</th>
            <th class="text-center">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($compliance as $c):
            $pct = $c['variance_pct'];
            if ($pct === null)      { $label='N/A'; $cls='secondary'; }
            elseif ($pct > 10)      { $label='Over-using'; $cls='danger'; }
            elseif ($pct < -10)     { $label='Under-using'; $cls='warning'; }
            else                    { $label='On target'; $cls='success'; }
          ?>
          <tr>
            <td><?= htmlspecialchars($c['lube_name']) ?></td>
            <td class="text-center"><?= $c['required_services'] ?></td>
            <td class="text-center"><?= number_format($c['required_qty'],1) ?> <?= htmlspecialchars($c['unit']) ?></td>
            <td class="text-center"><?= number_format($c['actual_qty'],1) ?> <?= htmlspecialchars($c['unit']) ?></td>
            <td class="text-center"><?= $pct===null?'—':(($pct>0?'+':'').$pct.'%') ?></td>
            <td class="text-center"><span class="badge bg-<?= $cls ?>"><?= $label ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Consumption Log Table -->
<div class="wms-card">
  <div class="card-head">
    <i class="bi bi-droplet-half text-primary me-1"></i>Consumption Log — <?= $month_name ?>
    <span class="badge bg-secondary ms-2"><?= count($consumption) ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table wms-table mb-0">
        <thead>
          <tr>
            <th>Date</th>
            <th>Equipment</th>
            <th>Lube Type</th>
            <th>Category</th>
            <th>Qty Used</th>
            <th>Recommended</th>
            <th>Variance</th>
            <th>WO#</th>
            <th>Recorded By</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($consumption as $c): ?>
          <?php
          $variance_class = '';
          if ($c->variance !== null) {
            $variance_class = $c->variance > 0 ? 'text-danger' : ($c->variance < 0 ? 'text-primary' : 'text-success');
          }
          ?>
          <tr>
            <td style="font-size:12px;white-space:nowrap;"><?= date('d M Y', strtotime($c->consumption_date)) ?></td>
            <td>
              <div class="fw-600" style="font-size:13px;"><?= $c->registration_no ?></div>
            </td>
            <td style="font-size:13px;"><?= $c->lube_name ?></td>
            <td>
              <span class="badge bg-primary bg-opacity-15 text-primary" style="font-size:11px;"><?= $c->category ?></span>
            </td>
            <td class="fw-600"><?= number_format($c->quantity_used, 1) ?> <span style="font-size:11px;color:#64748b;"><?= $c->unit ?></span></td>
            <td style="font-size:12px;color:#64748b;"><?= $c->recommended_qty ? number_format($c->recommended_qty, 1) . ' ' . $c->unit : '—' ?></td>
            <td class="fw-500 <?= $variance_class ?>" style="font-size:12px;">
              <?php if ($c->variance !== null): ?>
                <?= ($c->variance > 0 ? '+' : '') . number_format($c->variance, 1) . ' ' . $c->unit ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="font-size:12px;">
              <?= $c->wo_number ? '<a href="' . base_url('workorders/view/' . $c->work_order_id) . '" style="color:#1a3c5e;">' . $c->wo_number . '</a>' : '—' ?>
            </td>
            <td style="font-size:12px;color:#64748b;"><?= $c->recorded_by_name ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($consumption)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No consumption records found</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
