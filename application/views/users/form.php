<?php
$u = isset($user) ? $user : null;
$fd = isset($form_data) ? $form_data : array();
$v = function($field, $default='') use ($u, $fd) {
    if (!empty($fd[$field])) return htmlspecialchars((string)($fd[$field]));
    if ($u && isset($u->$field)) return htmlspecialchars((string)($u->$field));
    return $default;
};
?>

<div class="flex ai-c jb mb-4">
  <div>
    <div style="font-size:11px;color:var(--t3);margin-bottom:4px;">
      <a href="<?= base_url('users') ?>" style="color:var(--blue);text-decoration:none;">User Access</a> / <?= $edit_mode ? 'Edit User' : 'Create User' ?>
    </div>
    <div class="page-heading" style="font-size:17px;"><?= $edit_mode ? 'Edit: '.htmlspecialchars((string)($u->first_name.' '.$u->last_name)) : 'Create New User Account' ?></div>
  </div>
  <a href="<?= base_url('users') ?>" class="btn btn-ghost">← Back</a>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

<form method="post" action="<?= base_url($edit_mode ? 'users/edit/'.$u->id : 'users/create') ?>">
<?= form_hidden('csrf_token_wms', $this->security->get_csrf_hash()) ?>
<?php if (validation_errors()): ?>
<div class="flash flash-err" style="margin-bottom:16px;">
  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <?= validation_errors('<div>', '</div>') ?>
</div>
<?php endif; ?>

<div class="card" style="padding:24px;">
  <div style="font-family:inherit;font-size:12px;font-weight:700;color:var(--t3);text-transform:none;letter-spacing:0;margin-bottom:20px;">Account Details</div>
  <div class="form-grid cols-2" style="margin-bottom:20px;">
    <div>
      <label class="form-label">First Name <span class="req">*</span></label>
      <input type="text" name="first_name" class="form-control" required value="<?= $v('first_name') ?>" placeholder="Ahmed">
    </div>
    <div>
      <label class="form-label">Last Name <span class="req">*</span></label>
      <input type="text" name="last_name" class="form-control" required value="<?= $v('last_name') ?>" placeholder="Boudiaf">
    </div>
    <div>
      <label class="form-label">Email Address <span class="req">*</span></label>
      <input type="email" name="email" class="form-control" required value="<?= $v('email') ?>" placeholder="ahmed@workshop.com">
    </div>
    <div>
      <label class="form-label">Employee ID <span class="req">*</span></label>
      <input type="text" name="employee_id" class="form-control" <?= $edit_mode ? 'readonly style="opacity:.5;"' : 'required' ?> value="<?= $v('employee_id') ?>" placeholder="EMP010">
      <?php if ($edit_mode): ?><div style="font-size:10px;color:var(--t3);margin-top:4px;">Employee ID cannot be changed after creation.</div><?php endif; ?>
    </div>
    <div>
      <label class="form-label">Phone</label>
      <input type="text" name="phone" class="form-control" value="<?= $v('phone') ?>" placeholder="+213 555 123 456">
    </div>
    <div>
      <label class="form-label">Position / Job Title</label>
      <input type="text" name="position" class="form-control" value="<?= $v('position') ?>" placeholder="Heavy Equipment Technician">
    </div>
    <div>
      <label class="form-label">Join Date</label>
      <input type="date" name="join_date" class="form-control" value="<?= $v('join_date') ?>">
    </div>
    <div>
      <label class="form-label">Skill Level</label>
      <select name="skill_level" class="form-select">
        <?php foreach (array('Junior','Senior','Expert','Specialist') as $sl): ?>
        <option value="<?= $sl ?>" <?= $v('skill_level') === $sl ? 'selected' : '' ?>><?= $sl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div style="border-top:1px solid var(--sep);padding-top:20px;margin-bottom:20px;">
    <div style="font-family:inherit;font-size:12px;font-weight:700;color:var(--t3);text-transform:none;letter-spacing:0;margin-bottom:16px;">Access Control</div>
    <div class="form-grid cols-2">
      <div>
        <label class="form-label">System Role <span class="req">*</span></label>
        <select name="role_id" class="form-select" required>
          <?php foreach ($roles as $r): ?>
          <option value="<?= $r->id ?>" <?= $v('role_id') == $r->id ? 'selected' : '' ?>><?= htmlspecialchars((string)$r->name, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($edit_mode): ?>
      <div>
        <label class="form-label">Account Status</label>
        <select name="status" class="form-select">
          <?php foreach (array('Active','Inactive','On Leave') as $st): ?>
          <option value="<?= $st ?>" <?= $v('status') === $st ? 'selected' : '' ?>><?= $st ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div style="border-top:1px solid var(--sep);padding-top:20px;">
    <div style="font-family:inherit;font-size:12px;font-weight:700;color:var(--t3);text-transform:none;letter-spacing:0;margin-bottom:16px;">
      <?= $edit_mode ? 'Change Password (leave blank to keep current)' : 'Set Password' ?>
    </div>
    <div class="form-grid cols-2">
      <div>
        <label class="form-label"><?= $edit_mode ? 'New Password' : 'Password' ?> <?= !$edit_mode ? '<span class="req">*</span>' : '' ?></label>
        <input type="password" name="<?= $edit_mode ? 'new_password' : 'password' ?>" class="form-control" <?= !$edit_mode ? 'required' : '' ?> placeholder="Minimum 8 characters" autocomplete="new-password">
      </div>
      <div>
        <label class="form-label">Confirm Password</label>
        <input type="password" name="confirm_password" class="form-control" placeholder="Repeat password" autocomplete="new-password">
      </div>
    </div>
    <div style="font-size:10.5px;color:var(--t3);margin-top:8px;">Minimum 8 characters. Use a mix of letters, numbers and symbols for security.</div>
  </div>
</div>

<div style="display:flex;gap:10px;margin-top:14px;">
  <button type="submit" class="btn btn-primary">
    <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:currentColor;"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
    <?= $edit_mode ? 'Save Changes' : 'Create User Account' ?>
  </button>
  <a href="<?= base_url('users') ?>" class="btn btn-ghost">Cancel</a>
</div>
</form>

<!-- Role info panel -->
<div>
  <div class="card" style="padding:20px;">
    <div style="font-family:inherit;font-size:12px;font-weight:700;color:var(--t3);text-transform:none;letter-spacing:0;margin-bottom:16px;">Role Permissions</div>
    <?php
    $role_info = array(
      1 => array('name'=>'Supervisor','color'=>'var(--red)','perms'=>array('All modules — full read/write','User management & settings','Delete & override capabilities','Reports & KPI access','PM schedule management')),
      2 => array('name'=>'Technician','color'=>'var(--blue)','perms'=>array('Own work orders (create/update)','Equipment view','PM completion recording','Own attendance management','Lubricant consumption entry')),
      3 => array('name'=>'Store','color'=>'var(--amber)','perms'=>array('Lubricants module (full)','Spare parts management','Stock entries & consumption','Budget tracking','View-only other modules')),
      4 => array('name'=>'Manager','color'=>'var(--cyan)','perms'=>array('Dashboard & KPI view','All reports (read-only)','Equipment status view','Work order view (no edit)','Attendance reports')),
    );
    foreach ($role_info as $rid => $ri):
    ?>
    <div style="margin-bottom:14px;padding:12px;border-radius:10px;background:var(--g1);border:1px solid var(--sep);">
      <div style="font-size:12px;font-weight:700;color:<?= $ri['color'] ?>;margin-bottom:8px;"><?= $ri['name'] ?></div>
      <?php foreach ($ri['perms'] as $perm): ?>
      <div style="display:flex;align-items:flex-start;gap:7px;margin-bottom:5px;">
        <svg viewBox="0 0 24 24" width="11" height="11" stroke="<?= $ri['color'] ?>" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-top:2px;flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg>
        <span style="font-size:11px;color:var(--t3);"><?= $perm ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

</div>
