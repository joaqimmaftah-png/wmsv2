<?php
$role_colors  = array('Supervisor'=>'b-critical','Technician'=>'b-primary','Store'=>'b-warning','Store Keeper'=>'b-warning','Manager'=>'b-info','CEO'=>'b-success');
$status_colors= array('Active'=>'b-available','Inactive'=>'b-danger','On Leave'=>'b-warning');
$grad_colors  = array('#1a6fff,#0a50cc','#5e5ce6,#3634a3','var(--purple),#9b59b6','var(--green),#20a044','var(--amber),#e08800');
?>

<!-- ── KPI strip ── -->
<div class="grid g-4 mb-4">
  <div class="kpi" style="animation-delay:.04s;">
    <div class="kpi-glow" style="background:var(--blue);"></div>
    <span class="kpi-tag">Total Accounts</span>
    <div class="kpi-num"><?= $stats['total'] ?></div>
    <div class="kpi-chip chip-warn">All users</div>
  </div>
  <div class="kpi" style="animation-delay:.08s;">
    <div class="kpi-glow" style="background:var(--green);"></div>
    <span class="kpi-tag">Active</span>
    <div class="kpi-num" style="color:var(--green);"><?= $stats['active'] ?></div>
    <div class="kpi-chip chip-up">Can log in</div>
  </div>
  <div class="kpi" style="animation-delay:.12s;">
    <div class="kpi-glow" style="background:var(--red);"></div>
    <span class="kpi-tag">Inactive</span>
    <div class="kpi-num" style="color:var(--red);"><?= $stats['inactive'] ?></div>
    <div class="kpi-chip chip-dn">Access disabled</div>
  </div>
  <div class="kpi" style="animation-delay:.16s;">
    <div class="kpi-glow" style="background:var(--purple);"></div>
    <span class="kpi-tag">Roles</span>
    <div class="kpi-num">4</div>
    <div class="kpi-chip chip-warn">Supervisor · Tech · Store · Mgr</div>
  </div>
</div>

<!-- ── Role cards ── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px;">
<?php
$role_info = array(
  'Supervisor' => array('desc'=>'Full system access — all modules, user management, settings','color'=>'var(--red)','icon'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'),
  'Technician' => array('desc'=>'Work orders, equipment, own attendance & assignments','color'=>'var(--blue)','icon'=>'<path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/>'),
  'Store'      => array('desc'=>'Lubricants, spare parts, inventory & budget management','color'=>'var(--amber)','icon'=>'<path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4zM3 6h18M16 10a4 4 0 01-8 0"/>'),
  'Manager'    => array('desc'=>'Dashboard KPIs, all reports read-only, no edit access','color'=>'var(--cyan)','icon'=>'<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>'),
);
$counts = array();
foreach ($stats['by_role'] as $r) $counts[$r->name] = $r->cnt;
foreach ($role_info as $rname => $ri):
  $cnt = isset($counts[$rname]) ? $counts[$rname] : 0;
?>
<div class="card" style="padding:16px 18px;">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
    <div style="width:34px;height:34px;border-radius:10px;background:rgba(0,0,0,0.04);border:1px solid var(--sep);display:flex;align-items:center;justify-content:center;color:<?= $ri['color'] ?>;">
      <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $ri['icon'] ?></svg>
    </div>
    <div>
      <div style="font-family:inherit;font-size:12.5px;font-weight:700;color:var(--t1);"><?= $rname ?></div>
      <div style="font-size:10px;color:var(--t3);"><?= $cnt ?> user<?= $cnt !== 1 ? 's' : '' ?></div>
    </div>
  </div>
  <div style="font-size:10.5px;color:var(--t3);line-height:1.5;"><?= $ri['desc'] ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- ── User table ── -->
<div class="card">
  <div class="ch">
    <div>
      <div class="ct">All User Accounts</div>
      <div class="cs"><?= count($users) ?> user<?= count($users) !== 1 ? 's' : '' ?></div>
    </div>
    <a href="<?= base_url('users/create') ?>" class="btn btn-primary btn-icon btn-sm">
      <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
      Add User
    </a>
  </div>

  <!-- Filters -->
  <form method="get" style="display:flex;gap:10px;padding:16px 22px 0;flex-wrap:wrap;">
    <input type="text" name="q" class="form-control" style="width:220px;" placeholder="Search name, email, ID..." value="<?= htmlspecialchars(isset($filter['search']) ? $filter['search'] : '') ?>">
    <select name="role" class="form-select" style="width:160px;">
      <option value="">All Roles</option>
      <?php foreach ($roles as $r): ?>
      <option value="<?= $r->id ?>" <?= (isset($filter['role_id']) && $filter['role_id'] == $r->id) ? 'selected' : '' ?>><?= htmlspecialchars((string)$r->name, ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="form-select" style="width:150px;">
      <option value="">All Status</option>
      <option value="Active"   <?= (isset($filter['status']) && $filter['status'] === 'Active')   ? 'selected' : '' ?>>Active</option>
      <option value="Inactive" <?= (isset($filter['status']) && $filter['status'] === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
      <option value="On Leave" <?= (isset($filter['status']) && $filter['status'] === 'On Leave') ? 'selected' : '' ?>>On Leave</option>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
    <a href="<?= base_url('users') ?>" class="btn btn-ghost btn-sm">Reset</a>
  </form>

  <div style="margin-top:16px;overflow-x:auto;">
  <table class="dt">
    <thead>
      <tr>
        <th>User</th>
        <th>Employee ID</th>
        <th>Role</th>
        <th>Status</th>
        <th>Skill</th>
        <th>Last Login</th>
        <th style="text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($users)): ?>
    <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--t3);">No users found.</td></tr>
    <?php else: foreach ($users as $u):
      $initials = strtoupper(substr($u->first_name,0,1).substr($u->last_name,0,1));
      $grad     = $grad_colors[abs(crc32($u->id)) % count($grad_colors)];
      $rc       = isset($role_colors[$u->role_name])  ? $role_colors[$u->role_name]  : 'b-secondary';
      $sc       = isset($status_colors[$u->status])   ? $status_colors[$u->status]   : 'b-secondary';
      $is_me    = ($u->id == $this->session->userdata('user_id'));
      // Format last login
      $last_login_txt = '—';
      $last_login_sub = '';
      if (!empty($u->last_login)) {
        $ts = strtotime($u->last_login);
        $diff = time() - $ts;
        if ($diff < 3600)       $last_login_txt = round($diff/60).'m ago';
        elseif ($diff < 86400)  $last_login_txt = round($diff/3600).'h ago';
        elseif ($diff < 604800) $last_login_txt = round($diff/86400).'d ago';
        else                    $last_login_txt = date('d M Y', $ts);
        $last_login_sub = date('H:i', $ts);
      }
    ?>
    <tr>
      <!-- Avatar + name + email -->
      <td>
        <div style="display:flex;align-items:center;gap:11px;">
          <div style="width:36px;height:36px;min-width:36px;border-radius:50%;background:linear-gradient(145deg,<?= $grad ?>);display:flex;align-items:center;justify-content:center;font-family:inherit;font-size:11px;font-weight:700;color:var(--t1);position:relative;">
            <?= $initials ?>
            <?php if ($is_me): ?>
            <div style="position:absolute;bottom:-2px;right:-2px;width:10px;height:10px;border-radius:50%;background:var(--green);border:2px solid var(--bg);" title="You"></div>
            <?php endif; ?>
          </div>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--t1);"><?= htmlspecialchars($u->first_name.' '.$u->last_name) ?><?php if ($is_me): ?> <span style="font-size:9.5px;color:var(--blue);font-weight:700;"> YOU</span><?php endif; ?></div>
            <div style="font-size:10.5px;color:var(--t3);"><?= htmlspecialchars($u->email) ?></div>
          </div>
        </div>
      </td>
      <td><span class="mono" style="font-size:12px;color:var(--t2);"><?= htmlspecialchars($u->employee_id) ?></span></td>
      <td>
        <!-- Quick role change (inline dropdown) -->
        <form method="post" action="<?= base_url('users/change_role/'.$u->id) ?>" style="display:inline-flex;align-items:center;gap:6px;">
          <?= form_hidden('csrf_token_wms', $this->security->get_csrf_hash()) ?>
          <select name="role_id" class="form-select" style="width:130px;padding:5px 10px;font-size:11px;" <?= $is_me ? 'disabled title="Cannot change your own role"' : 'onchange="this.form.submit()"' ?>>
            <?php foreach ($roles as $r): ?>
            <option value="<?= $r->id ?>" <?= $u->role_id == $r->id ? 'selected' : '' ?>><?= htmlspecialchars((string)$r->name, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td><span class="badge <?= $sc ?>"><?= $u->status ?></span></td>
      <td style="font-size:12px;color:var(--t3);"><?= isset($u->skill_level) ? $u->skill_level : '—' ?></td>
      <td>
        <?php if ($last_login_txt !== '—'): ?>
        <div style="font-size:12px;font-weight:600;color:var(--t2);"><?= $last_login_txt ?></div>
        <div style="font-size:10px;color:var(--t4);"><?= $last_login_sub ?></div>
        <?php else: ?>
        <span style="font-size:11px;color:var(--t4);">Never logged in</span>
        <?php endif; ?>
      </td>
      <td style="text-align:right;">
        <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;">
          <a href="<?= base_url('users/edit/'.$u->id) ?>" class="btn btn-ghost btn-sm btn-icon" title="Edit user">
            <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </a>
          <a href="<?= base_url('users/reset_password/'.$u->id) ?>" class="btn btn-ghost btn-sm btn-icon" title="Reset password">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
          </a>
          <?php if (!$is_me):
            // F-04: a status change is a POST. As a link it could be triggered
            // by an <img src> on any page; as a form it carries the CSRF token.
            $verb = ($u->status === 'Active') ? 'Deactivate' : 'Activate';
          ?>
          <?= form_open('users/toggle_status/'.$u->id, array(
                'style'   => 'display:inline;margin:0;',
                'onsubmit'=> "return confirm('".$verb." ".htmlspecialchars($u->first_name, ENT_QUOTES, 'UTF-8')."?')",
              )) ?>
          <button type="submit"
             class="btn btn-sm btn-icon <?= $u->status === 'Active' ? 'btn-danger' : 'btn-success' ?>"
             title="<?= $verb ?>">
            <?php if ($u->status === 'Active'): ?>
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            <?php else: ?>
            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <?php endif; ?>
          </button>
          <?= form_close() ?>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>
