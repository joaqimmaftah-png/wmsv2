<div class="row g-3">
  <div class="col-lg-5">
    <div class="wms-card">
      <div class="card-head"><i class="bi bi-plus-circle text-success me-1"></i>Add Specification</div>
      <div class="card-body">
        <?= form_open('lubricants/specifications') ?>
        <div class="mb-3">
          <label class="form-label fw-500">Equipment Type <span class="text-danger">*</span></label>
          <select name="equipment_type_id" class="form-select" required>
            <option value="">Select...</option>
            <?php foreach ($eq_types as $et): ?>
            <option value="<?= $et->id ?>"><?= htmlspecialchars((string)$et->name, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-500">Lube Type <span class="text-danger">*</span></label>
          <select name="lube_type_id" class="form-select" required>
            <option value="">Select...</option>
            <?php foreach ($lube_types as $lt): ?>
            <option value="<?= $lt->id ?>"><?= htmlspecialchars((string)$lt->name, ENT_QUOTES, 'UTF-8') ?> (<?= $lt->unit ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label fw-500">Recommended Qty <span class="text-danger">*</span></label>
            <input type="number" name="recommended_qty" class="form-control" step="0.1" required placeholder="e.g. 18">
          </div>
          <div class="col-6">
            <label class="form-label fw-500">Service Interval (hrs)</label>
            <input type="number" name="service_interval_hours" class="form-control" placeholder="e.g. 250">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-500">Notes</label>
          <input type="text" name="notes" class="form-control" placeholder="Additional notes...">
        </div>
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i>Save Specification</button>
        <?= form_close() ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="wms-card">
      <div class="card-head"><i class="bi bi-list-check text-primary me-1"></i>Lube Specifications Master</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table wms-table mb-0">
            <thead>
              <tr>
                <th>Equipment Type</th>
                <th>Lube / Consumable</th>
                <th>Recommended Qty</th>
                <th>Interval (hrs)</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($specs as $s): ?>
              <tr>
                <td><span class="badge bg-primary bg-opacity-15 text-primary"><?= $s->type_name ?></span></td>
                <td>
                  <div class="fw-500" style="font-size:13px;"><?= $s->lube_name ?></div>
                </td>
                <td class="fw-600"><?= $s->recommended_qty ?> <span style="font-size:11px;color:#94a3b8;"><?= $s->unit ?></span></td>
                <td style="font-size:12px;"><?= $s->service_interval_hours ? $s->service_interval_hours . ' hrs' : '—' ?></td>
                <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars((string)($s->notes ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($specs)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No specifications defined yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
