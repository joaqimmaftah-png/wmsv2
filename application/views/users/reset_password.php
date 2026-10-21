<div class="flex ai-c jb mb-4">
  <div>
    <div style="font-size:11px;color:var(--t3);margin-bottom:4px;"><a href="<?= base_url('users') ?>" style="color:var(--blue);text-decoration:none;">User Access</a> / Reset Password</div>
    <div class="page-heading" style="font-size:17px;">Reset Password</div>
  </div>
  <a href="<?= base_url('users') ?>" class="btn btn-ghost">← Back</a>
</div>

<div style="max-width:480px;">
<div class="card" style="padding:28px;">
  <!-- User identity -->
  <div style="display:flex;align-items:center;gap:14px;padding:16px;background:var(--g1);border:1px solid var(--sep);border-radius:14px;margin-bottom:24px;">
    <?php
    $initials = strtoupper((string)(substr($target_user->first_name,0,1).substr($target_user->last_name,0,1)));
    $colors = array('#1a6fff,#0a50cc','#5e5ce6,#3634a3','var(--purple),#9b59b6','var(--green),#20a044');
    $grad = $colors[abs(crc32($target_user->id)) % count($colors)];
    ?>
    <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(145deg,<?= $grad ?>);display:flex;align-items:center;justify-content:center;font-family:inherit;font-weight:700;color:#fff;font-size:14px;"><?= $initials ?></div>
    <div>
      <div style="font-size:14px;font-weight:600;color:var(--t1);"><?= htmlspecialchars((string)($target_user->first_name.' '.$target_user->last_name)) ?></div>
      <div style="font-size:11px;color:var(--t3);margin-top:2px;"><?= htmlspecialchars((string)($target_user->email)) ?> · <?= htmlspecialchars((string)($target_user->employee_id)) ?></div>
    </div>
  </div>

  <div style="background:var(--amber-d);border:1px solid rgba(180,83,9,0.2);border-radius:10px;padding:12px 14px;margin-bottom:22px;display:flex;gap:10px;align-items:flex-start;">
    <svg viewBox="0 0 24 24" width="15" height="15" stroke="var(--amber)" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div style="font-size:11.5px;color:var(--amber);line-height:1.55;">You are about to reset this user's password. They will need the new password to log in. Notify them securely after resetting.</div>
  </div>

  <form method="post" action="<?= base_url('users/reset_password/'.$target_user->id) ?>">
    <?= form_hidden('csrf_token_wms', $this->security->get_csrf_hash()) ?>
    <div style="margin-bottom:16px;">
      <label class="form-label">New Password <span class="req">*</span></label>
      <input type="password" name="new_password" class="form-control" required minlength="8" placeholder="Minimum 8 characters" autocomplete="new-password" id="pw-input">
    </div>
    <div style="margin-bottom:22px;">
      <label class="form-label">Confirm New Password <span class="req">*</span></label>
      <input type="password" name="confirm_password" class="form-control" required placeholder="Repeat password" autocomplete="new-password" id="pw-confirm">
      <div id="pw-match-msg" style="font-size:10.5px;margin-top:5px;display:none;"></div>
    </div>
    <div style="display:flex;gap:10px;">
      <button type="submit" class="btn btn-danger">
        <svg viewBox="0 0 24 24" style="stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        Reset Password
      </button>
      <a href="<?= base_url('users') ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
</div>

<script>
var pi=document.getElementById('pw-input'),pc=document.getElementById('pw-confirm'),msg=document.getElementById('pw-match-msg');
function checkMatch(){
  if(!pc.value){msg.style.display='none';return;}
  if(pi.value===pc.value){msg.style.display='block';msg.style.color='var(--green)';msg.textContent='✓ Passwords match';}
  else{msg.style.display='block';msg.style.color='var(--red)';msg.textContent='✗ Passwords do not match';}
}
if(pi&&pc){pi.addEventListener('input',checkMatch);pc.addEventListener('input',checkMatch);}
</script>
