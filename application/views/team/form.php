<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="wms-card">
      <div class="card-head"><i class="bi bi-person-plus text-success me-1"></i>Add New Worker</div>
      <div class="card-body">
        <?= form_open('team/create', ['enctype' => 'multipart/form-data']) ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-500">First Name <span class="text-danger">*</span></label>
            <input type="text" name="first_name" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-500">Last Name <span class="text-danger">*</span></label>
            <input type="text" name="last_name" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-500">Employee ID <span class="text-danger">*</span></label>
            <input type="text" name="employee_id" class="form-control" required placeholder="e.g. EMP007">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-500">Role <span class="text-danger">*</span></label>
            <select name="role_id" class="form-select" required>
              <?php foreach ($roles as $r): ?>
              <option value="<?= $r->id ?>"><?= htmlspecialchars((string)$r->name, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-500">Skill Level</label>
            <select name="skill_level" class="form-select">
              <?php foreach (['Junior','Senior','Expert','Specialist'] as $s): ?>
              <option value="<?= $s ?>"><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-500">Email <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-500">Phone</label>
            <input type="text" name="phone" class="form-control" placeholder="+1234567890">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-500">Password <span class="text-danger">*</span></label>
            <input type="password" name="password" class="form-control" required minlength="6">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-500">Join Date</label>
            <input type="date" name="join_date" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-12">
            <label class="form-label fw-500">Position / Job Title</label>
            <input type="text" name="position" class="form-control" placeholder="e.g. Senior Technician, Diesel Mechanic">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-500">Photo</label>
            <input type="file" name="photo" class="form-control" accept="image/*">
          </div>
        </div>
        <hr class="my-3">
        <div class="d-flex gap-2 justify-content-end">
          <a href="<?= base_url('team') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Cancel</a>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Add Worker</button>
        </div>
        <?= form_close() ?>
      </div>
    </div>
  </div>
</div>
