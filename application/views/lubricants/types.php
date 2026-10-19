<div class="row g-3">
  <div class="col-lg-4">
    <div class="wms-card">
      <div class="card-head"><i class="bi bi-plus-circle text-success me-1"></i>Add Lube Type</div>
      <div class="card-body">
        <?= form_open('lubricants/types') ?>
        <div class="mb-3">
          <label class="form-label fw-500">Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required placeholder="e.g. Engine Oil 15W-40">
        </div>
        <div class="mb-3">
          <label class="form-label fw-500">Category <span class="text-danger">*</span></label>
          <select name="category" class="form-select" required>
            <?php foreach (['Engine Oil','Hydraulic Oil','Transmission Oil','Differential Oil','Grease','Coolant','Brake Fluid','Filter','Other'] as $cat): ?>
            <option value="<?= $cat ?>"><?= $cat ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-500">Unit</label>
          <select name="unit" class="form-select">
            <option value="Liters">Liters</option>
            <option value="Kg">Kg</option>
            <option value="Pcs">Pcs</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-500">Description</label>
          <input type="text" name="description" class="form-control" placeholder="Optional notes...">
        </div>
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i>Add Type</button>
        <?= form_close() ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="wms-card">
      <div class="card-head"><i class="bi bi-list text-primary me-1"></i>Lube Types Master</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table wms-table mb-0">
            <thead><tr><th>Name</th><th>Category</th><th>Unit</th><th>Description</th></tr></thead>
            <tbody>
              <?php foreach ($lube_types as $lt): ?>
              <tr>
                <td class="fw-500"><?= htmlspecialchars((string)$lt->name, ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="badge bg-primary bg-opacity-15 text-primary" style="font-size:11px;"><?= $lt->category ?></span></td>
                <td style="font-size:12px;"><?= $lt->unit ?></td>
                <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars((string)($lt->description ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
