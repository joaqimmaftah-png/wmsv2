<div class="flex ai-c jb mb-4">
  <div>
    <h1 class="page-heading" style="font-size:17px;">Change Your Password</h1>
    <div style="font-size:11px;color:var(--t3);margin-top:3px;">Update your personal login credentials</div>
  </div>
  <a href="<?= base_url('dashboard') ?>" class="btn btn-ghost">← Dashboard</a>
</div>

<div style="max-width:440px;">
<div class="card" style="padding:28px;">
  <form method="post" action="<?= base_url('users/change_password') ?>">
    <?= form_hidden('csrf_token_wms', $this->security->get_csrf_hash()) ?>
    <div style="margin-bottom:18px;">
      <label class="form-label">Current Password <span class="req">*</span></label>
      <input type="password" name="current_password" class="form-control" required placeholder="Your current password">
    </div>
    <div style="border-top:1px solid var(--sep);padding-top:18px;margin-bottom:18px;">
      <div style="font-size:11px;color:var(--t3);margin-bottom:14px;font-weight:500;">New Password</div>
      <div style="margin-bottom:14px;">
        <label class="form-label">New Password <span class="req">*</span></label>
        <input type="password" name="new_password" id="np" class="form-control" required minlength="8" placeholder="Minimum 8 characters">
      </div>
      <div>
        <label class="form-label">Confirm New Password <span class="req">*</span></label>
        <input type="password" name="confirm_password" id="cp" class="form-control" required placeholder="Repeat new password">
        <div id="match-msg" style="font-size:10.5px;margin-top:5px;display:none;"></div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">
      <svg viewBox="0 0 24 24" style="stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Update Password
    </button>
  </form>
</div>
</div>
<script>
var np=document.getElementById('np'),cp=document.getElementById('cp'),msg=document.getElementById('match-msg');
function chk(){if(!cp.value){msg.style.display='none';return;}if(np.value===cp.value){msg.style.display='block';msg.style.color='var(--green)';msg.textContent='✓ Passwords match';}else{msg.style.display='block';msg.style.color='var(--red)';msg.textContent='✗ Passwords do not match';}}
if(np&&cp){np.addEventListener('input',chk);cp.addEventListener('input',chk);}
</script>
