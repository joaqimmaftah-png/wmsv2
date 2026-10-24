<?php
/**
 * Worker form — one view for create and edit.
 *
 * It used to be the create form only: `Team::edit()` rendered this file with a
 * `$worker`, but the view ignored it, always posted to `team/create` and always
 * showed blank fields. Editing a worker therefore created a second account
 * instead of updating the one whose id was in the URL.
 */
$is_edit = !empty($is_edit);
$w       = (isset($worker) && $worker) ? $worker : NULL;
$errors  = isset($errors) ? $errors : array();
$roles   = isset($roles) ? $roles : array();

$val = function ($key, $default = '') use ($w) {
    return ($w && isset($w->$key) && $w->$key !== NULL) ? (string)$w->$key : $default;
};
$sel = function ($key, $value) use ($val) {
    return $val($key) === $value ? 'selected' : '';
};

$skills   = array('Junior', 'Senior', 'Expert', 'Specialist');
$statuses = array('Active', 'Inactive', 'On Leave');
$action   = $is_edit ? 'team/edit/' . (int)$w->id : 'team/create';

// A new account must not default to Supervisor just because role 1 sorts
// first: preselect Technician, else the first role listed.
$role_value = $val('role_id');
if ($role_value === '' && !empty($roles)) {
    $role_value = (string)$roles[0]->id;
    foreach ($roles as $r) {
        if ((string)$r->id === '2') { $role_value = '2'; break; }
    }
}
?>
<div class="form-page">

  <div class="form-page-head">
    <div>
      <h1 class="form-page-title"><?= $is_edit ? 'Edit Worker' : 'Add New Worker' ?></h1>
      <div class="form-page-sub">
        <?= $is_edit
            ? 'Changes apply to the account immediately — ' . htmlspecialchars((string)($val('employee_id'))) . '.'
            : 'Creates a sign-in account for a workshop staff member.' ?>
      </div>
    </div>
    <a href="<?= base_url($is_edit ? 'team/view/' . (int)$w->id : 'team') ?>" class="btn btn-ghost btn-sm">← Back</a>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="form-errors">
    <div class="form-errors-title">Nothing was saved:</div>
    <ul>
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars((string)($e)) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?= form_open($action, array('enctype' => 'multipart/form-data')) ?>

  <div class="card form-card">
    <div class="form-section-title">Identity</div>
    <div class="form-grid cols-2">
      <div>
        <label class="form-label">First Name <span class="req">*</span></label>
        <input type="text" name="first_name" class="form-control" required maxlength="100"
               value="<?= htmlspecialchars((string)($val('first_name'))) ?>">
      </div>
      <div>
        <label class="form-label">Last Name <span class="req">*</span></label>
        <input type="text" name="last_name" class="form-control" required maxlength="100"
               value="<?= htmlspecialchars((string)($val('last_name'))) ?>">
      </div>
      <div>
        <label class="form-label">Employee ID <?= $is_edit ? '' : '<span class="req">*</span>' ?></label>
        <?php if ($is_edit): ?>
          <input type="text" class="form-control" value="<?= htmlspecialchars((string)($val('employee_id'))) ?>" disabled>
          <div class="form-hint">The employee ID is printed on attendance and certificate reports and must stay unique, so it is fixed once the account exists.</div>
        <?php else: ?>
          <input type="text" name="employee_id" class="form-control" required maxlength="20"
                 placeholder="e.g. EMP007" value="<?= htmlspecialchars((string)($val('employee_id'))) ?>">
        <?php endif; ?>
      </div>
      <div>
        <label class="form-label">Role <span class="req">*</span></label>
        <select name="role_id" class="form-select" required>
          <?php foreach ($roles as $r): ?>
          <option value="<?= (int)$r->id ?>" <?= $role_value === (string)$r->id ? 'selected' : '' ?>>
            <?= htmlspecialchars((string)$r->name) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Email <span class="req">*</span></label>
        <input type="email" name="email" class="form-control" required maxlength="150"
               value="<?= htmlspecialchars((string)($val('email'))) ?>">
        <div class="form-hint">Used to sign in; must be unique.</div>
      </div>
      <div>
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" maxlength="30"
               value="<?= htmlspecialchars((string)($val('phone'))) ?>">
      </div>
      <div>
        <label class="form-label">Password <?= $is_edit ? '' : '<span class="req">*</span>' ?></label>
        <input type="password" name="password" class="form-control" autocomplete="new-password"
               <?= $is_edit ? '' : 'required' ?> minlength="8"
               placeholder="<?= $is_edit ? 'Leave blank to keep the current password' : 'At least 8 characters' ?>">
      </div>
      <div>
        <label class="form-label">Join Date</label>
        <input type="date" name="join_date" class="form-control" value="<?= htmlspecialchars((string)($val('join_date'))) ?>">
      </div>
    </div>
  </div>

  <div class="card form-card">
    <div class="form-section-title">Workshop</div>
    <div class="form-grid cols-2">
      <div>
        <label class="form-label">Position / Job Title</label>
        <input type="text" name="position" class="form-control" maxlength="100"
               placeholder="e.g. Senior Technician, Diesel Mechanic"
               value="<?= htmlspecialchars((string)($val('position'))) ?>">
      </div>
      <div>
        <label class="form-label">Assigned Site / Location</label>
        <input type="text" name="site" list="aml-worker-sites" class="form-control" maxlength="100"
               placeholder="e.g. WORKSHOP, KALIMVA, MSATA"
               value="<?= htmlspecialchars((string)($val('site'))) ?>">
        <datalist id="aml-worker-sites">
          <?php if (!empty($sites) && is_array($sites)): foreach ($sites as $sn): ?>
          <option value="<?= htmlspecialchars((string)$sn, ENT_QUOTES, 'UTF-8') ?>"></option>
          <?php endforeach; endif; ?>
        </datalist>
        <div class="form-hint">Printed on the AML Individual Attendance Sheet header (auto-falls back to QR check-in site if blank).</div>
      </div>
      <div>
        <label class="form-label">Skill Level</label>
        <select name="skill_level" class="form-select">
          <?php foreach ($skills as $s): ?>
          <option value="<?= $s ?>" <?= $sel('skill_level', $s) ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($is_edit): ?>
      <div>
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>" <?= $sel('status', $s) ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">An Inactive account cannot sign in; its history is kept.</div>
      </div>
      <?php endif; ?>
      <div>
        <label class="form-label">Photo</label>
        <input type="file" name="photo" class="form-control" accept="image/gif,image/jpeg,image/png">
        <div class="form-hint">JPG, PNG or GIF, up to 2 MB.<?= ($is_edit && $val('photo') !== '') ? ' Current photo is kept unless a new one is chosen.' : '' ?></div>
      </div>
      <div>
        <label class="form-label">Signature</label>
        <input type="file" name="signature" class="form-control" accept="image/gif,image/jpeg,image/png">
        <div class="form-hint">The dark background is removed; JPG, PNG or GIF up to 3 MB.</div>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <a href="<?= base_url($is_edit ? 'team/view/' . (int)$w->id : 'team') ?>" class="btn btn-ghost">Cancel</a>
    <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Save Changes' : 'Add Worker' ?></button>
  </div>

  <?= form_close() ?>
</div>

<style>
.form-page{max-width:860px;margin:0 auto;padding:4px 0 60px;}
.form-page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px;}
.form-page-title{font-size:22px;font-weight:700;color:var(--t1);letter-spacing:-.3px;}
.form-page-sub{font-size:12.5px;color:var(--t2);margin-top:3px;}
.form-card{padding:22px 24px;margin-bottom:16px;}
.form-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);margin-bottom:14px;}
.form-hint{font-size:10.5px;color:var(--t3);margin-top:4px;}
.form-errors{background:var(--red-fill);border:1px solid rgba(192,57,43,.22);border-radius:10px;padding:12px 16px;margin-bottom:16px;}
.form-errors-title{font-size:12.5px;font-weight:700;color:var(--red);margin-bottom:6px;}
.form-errors ul{margin:0;padding-left:18px;font-size:12.5px;color:var(--red);}
.form-actions{display:flex;justify-content:flex-end;gap:10px;}
</style>
