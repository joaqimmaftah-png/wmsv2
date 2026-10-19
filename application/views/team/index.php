<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div class="flex ai-c gap-10">
    <div style="font-size:13px;color:var(--t3);"><?= isset($workers) ? count($workers) : 0 ?> workers</div>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('team/attendance') ?>" class="btn btn-ghost btn-sm btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
      Attendance
    </a>
    <a href="<?= base_url('team/leave_request') ?>" class="btn btn-ghost btn-sm btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Leave Request
    </a>
    <a href="<?= base_url('team/create') ?>" class="btn btn-primary btn-sm btn-icon">
      <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
      Add Worker
    </a>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;">
  <?php
  $skill_colors = ['Junior'=>'b-secondary','Senior'=>'b-primary','Expert'=>'b-success','Specialist'=>'b-info'];
  $status_colors= ['Active'=>'b-available','Inactive'=>'b-danger','On Leave'=>'b-warning'];
  if (isset($workers) && count($workers) > 0): foreach ($workers as $w):
    $sc = (isset($skill_colors[$w->skill_level]) ? $skill_colors[$w->skill_level] : 'b-secondary');
    $st = (isset($status_colors[$w->status]) ? $status_colors[$w->status] : 'b-secondary');
    $init = strtoupper(substr($w->first_name,0,1).substr($w->last_name,0,1));
    $grad_colors = ['#1a6fff,#0a50cc','#5e5ce6,#3634a3','var(--purple),#9b59b6','var(--green),#20a044','var(--amber),#e08800'];
    $grad = $grad_colors[abs(crc32($w->id)) % count($grad_colors)];
  ?>
  <a href="<?= base_url('team/view/'.$w->id) ?>" style="text-decoration:none;">
  <div class="card" style="cursor:pointer;padding:20px;transition:transform .25s cubic-bezier(0.34,1.56,0.64,1),box-shadow .25s;" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 14px 36px rgba(0,0,0,.35)';" onmouseout="this.style.transform='';this.style.boxShadow='';">
    <div style="text-align:center;margin-bottom:14px;">
      <?php if ($w->photo): ?>
      <img src="<?= base_url('uploads/photos/'.$w->photo) ?>" style="width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.1);">
      <?php else: ?>
      <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(145deg,<?= $grad ?>);display:flex;align-items:center;justify-content:center;font-family:inherit;font-size:18px;font-weight:700;color:var(--t1);margin:0 auto;border:2px solid rgba(255,255,255,0.1);">
        <?= $init ?>
      </div>
      <?php endif; ?>
    </div>
    <div style="text-align:center;">
      <div class="fw-600" style="font-size:14px;letter-spacing:-0.2px;"><?= $w->first_name . ' ' . $w->last_name ?></div>
      <div style="font-size:11px;color:var(--t3);margin-top:3px;"><?= htmlspecialchars((string)$w->position, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="flex ai-c" style="justify-content:center;gap:6px;margin-top:10px;flex-wrap:wrap;">
        <span class="badge <?= $st ?>" style="font-size:10px;"><?= $w->status ?></span>
        <span class="badge <?= $sc ?>" style="font-size:10px;"><?= $w->skill_level ?></span>
      </div>
      <div style="font-size:11px;color:var(--t3);margin-top:8px;"><?= (isset($w->role_name) ? $w->role_name : '') ?></div>
    </div>
  </div>
  </a>
  <?php endforeach; else: ?>
  <div style="grid-column:1/-1;padding:60px;text-align:center;color:var(--t3);">No workers found. <a href="<?= base_url('team/create') ?>" style="color:var(--blue);">Add one →</a></div>
  <?php endif; ?>
</div>
