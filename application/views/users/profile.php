<?php
$has_sig  = !empty($me->signature);
$has_photo= !empty($me->photo);
?>
<style>
.sig-canvas-wrap {
  position:relative; background:#fff;
  border:1px solid var(--sep-strong); border-radius:var(--r1);
  overflow:hidden; cursor:crosshair; user-select:none;
}
#profile-sig-canvas { display:block; width:100%; height:130px; touch-action:none; }
.sig-controls { display:flex;align-items:center;justify-content:space-between;margin-top:7px; }
.sig-hint {
  position:absolute; inset:0; display:flex; align-items:center;
  justify-content:center; pointer-events:none; opacity:.25;
}
.sig-hint svg { width:140px; height:50px; }
.sig-action {
  font-size:11px; padding:4px 13px; border-radius:20px; cursor:pointer;
  font-family:inherit; border:1px solid var(--sep); background:var(--g2);
  color:var(--t2); transition:all .15s;
}
.sig-action:hover { color:var(--t1); border-color:var(--sep-strong); }
.sig-action.danger { background:var(--red-d); color:var(--red); border-color:rgba(192,57,43,.2); }
.profile-avatar {
  width:88px; height:88px; border-radius:50%;
  background:linear-gradient(145deg,#5e5ce6,var(--blue));
  display:flex; align-items:center; justify-content:center;
  font-family:inherit; font-size:28px; font-weight:800; color:#fff;
  border:3px solid var(--sep); flex-shrink:0;
}
</style>

<div style="max-width:720px;margin:0 auto;">

  <!-- Flash -->
  <?php if ($this->session->flashdata('success')): ?>
  <div class="flash flash-ok mb-4">
    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    <?= $this->session->flashdata('success') ?>
  </div>
  <?php endif; ?>

  <?= form_open('users/profile', array('enctype'=>'multipart/form-data')) ?>

  <!-- ── Identity card ── -->
  <div class="card mb-4">
    <div style="padding:24px 26px;">
      <div style="display:flex;align-items:center;gap:20px;margin-bottom:24px;">
        <?php if ($has_photo): ?>
        <img src="<?= base_url('uploads/photos/'.$me->photo) ?>"
             style="width:88px;height:88px;border-radius:50%;object-fit:cover;border:3px solid var(--sep);">
        <?php else: ?>
        <div class="profile-avatar">
          <?= strtoupper((string)(substr($me->first_name,0,1).substr($me->last_name,0,1))) ?>
        </div>
        <?php endif; ?>
        <div style="flex:1;">
          <div style="font-family:inherit;font-size:20px;font-weight:700;color:var(--t1);letter-spacing:-.3px;">
            <?= htmlspecialchars((string)($me->first_name.' '.$me->last_name)) ?>
          </div>
          <div style="font-size:12.5px;color:var(--t3);margin-top:3px;">
            <?= htmlspecialchars((string)($me->position ?? '')) ?>
            &nbsp;·&nbsp; <?= htmlspecialchars((string)($me->email)) ?>
          </div>
          <div style="margin-top:10px;">
            <span class="badge b-primary" style="font-size:10px;">
              <?php $roles=array(1=>'Supervisor',2=>'Technician',3=>'Store',4=>'Manager',5=>'CEO');
              echo isset($roles[$me->role_id])?$roles[$me->role_id]:'User'; ?>
            </span>
            <span class="badge b-secondary" style="font-size:10px;margin-left:6px;">
              <?= htmlspecialchars((string)($me->employee_id ?? '')) ?>
            </span>
          </div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:9px;font-weight:700;text-transform:none;letter-spacing:0;color:var(--t4);margin-bottom:5px;">Photo</div>
          <input type="file" name="photo" accept="image/*"
                 style="font-size:11px;color:var(--t3);">
        </div>
      </div>

      <div class="form-grid cols-2">
        <div>
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control"
                 value="<?= htmlspecialchars((string)($me->phone ?? '')) ?>"
                 placeholder="+213 6xx xxx xxx">
        </div>
        <div>
          <label class="form-label">Position / Role Title</label>
          <input type="text" name="position" class="form-control"
                 value="<?= htmlspecialchars((string)($me->position ?? '')) ?>"
                 placeholder="e.g. Senior Technician">
        </div>
      </div>
    </div>
  </div>

  <!-- ── Signature ── -->
  <div class="card mb-4">
    <div class="ch">
      <div>
        <div class="ct">My Signature</div>
        <div class="cs">Used automatically on Job Cards when you are assigned as Supervisor or Intervention Responsible</div>
      </div>
    </div>
    <div style="padding:20px 26px 26px;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start;">

        <!-- Draw panel -->
        <div>
          <div style="font-size:11.5px;font-weight:600;color:var(--t2);margin-bottom:8px;">
            Draw your signature
            <span style="font-size:10px;color:var(--blue);font-weight:500;margin-left:6px;">
              Use mouse or finger
            </span>
          </div>
          <div class="sig-canvas-wrap" id="sig-wrap">
            <canvas id="profile-sig-canvas" style="height:130px;"></canvas>
            <div class="sig-hint" id="sig-hint">
              <svg viewBox="0 0 140 50" xmlns="http://www.w3.org/2000/svg">
                <path d="M10 38 Q28 8 46 28 Q64 48 82 18 Q100 -2 130 22"
                      fill="none" stroke="#1a2a6c" stroke-width="1.5" stroke-linecap="round"/>
              </svg>
            </div>
          </div>
          <div class="sig-controls">
            <span style="font-size:10px;color:var(--t4);">Sign in the box above</span>
            <div style="display:flex;gap:7px;">
              <button type="button" class="sig-action" onclick="profSigUndo()">↩ Undo</button>
              <button type="button" class="sig-action danger" onclick="profSigClear()">✕ Clear</button>
            </div>
          </div>
          <input type="hidden" name="signature_data" id="sig-data" value="">
        </div>

        <!-- Current signature -->
        <div>
          <div style="font-size:11.5px;font-weight:600;color:var(--t2);margin-bottom:8px;">
            Current signature on file
          </div>
          <?php if ($has_sig): ?>
          <div style="background:#fff;border-radius:var(--r1);padding:12px 16px;
                      border:1px solid var(--sep);min-height:80px;
                      display:flex;align-items:center;justify-content:center;">
            <img src="<?= base_url(htmlspecialchars((string)($me->signature))) ?>"
                 style="max-height:80px;max-width:100%;object-fit:contain;"
                 id="existing-sig" alt="Your signature">
          </div>
          <div style="font-size:10.5px;color:var(--t3);margin-top:6px;">
            Draw a new signature above to replace this one.
          </div>
          <?php else: ?>
          <div style="background:var(--g1);border:1px dashed var(--sep);border-radius:var(--r1);
                      min-height:80px;display:flex;align-items:center;justify-content:center;
                      flex-direction:column;gap:6px;">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="var(--t4)"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>
            </svg>
            <div style="font-size:11.5px;color:var(--t4);">No signature yet</div>
          </div>
          <div style="font-size:10.5px;color:var(--t4);margin-top:6px;">
            Draw your signature on the left to save it.
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- How it works -->
      <div style="background:var(--blue-d);border:1px solid rgba(0,113,227,.18);
                  border-radius:var(--r2);padding:12px 16px;margin-top:18px;
                  display:flex;align-items:flex-start;gap:10px;">
        <svg viewBox="0 0 24 24" width="16" height="16" style="flex-shrink:0;margin-top:1px;"
             fill="none" stroke="var(--blue)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <div style="font-size:12px;color:var(--blue);line-height:1.55;">
          Your signature is stored securely and placed automatically on Job Cards when you are assigned as
          <strong>Intervention Responsible</strong> or <strong>Supervisor</strong>.
          You only need to draw it once.
        </div>
      </div>
    </div>
  </div>

  <!-- ── Actions ── -->
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <a href="<?= base_url('users/change_password') ?>" class="btn btn-ghost btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
           stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
        <path d="M7 11V7a5 5 0 0110 0v4"/>
      </svg>
      Change Password
    </a>
    <button type="submit" class="btn btn-primary">
      Save Profile
    </button>
  </div>

  <?= form_close() ?>
</div>

<script>
(function() {
  var canvas  = document.getElementById('profile-sig-canvas');
  if (!canvas) return;
  var ctx     = canvas.getContext('2d');
  var drawing = false;
  var strokes = [];
  var current = [];
  var hint    = document.getElementById('sig-hint');
  var dataInp = document.getElementById('sig-data');
  var existImg= document.getElementById('existing-sig');

  function setup() {
    var rect = canvas.getBoundingClientRect();
    var dpr  = window.devicePixelRatio || 1;
    canvas.width  = rect.width  * dpr;
    canvas.height = rect.height * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.strokeStyle = '#0a1a5c';
    ctx.lineWidth   = 1.8;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';
    ctx.fillStyle   = '#ffffff';
    ctx.fillRect(0, 0, rect.width, rect.height);
    redraw();
  }

  function pt(e) {
    var r = canvas.getBoundingClientRect();
    var s = e.touches ? e.touches[0] : e;
    return { x: s.clientX - r.left, y: s.clientY - r.top };
  }

  function redraw() {
    var r = canvas.getBoundingClientRect();
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, r.width, r.height);
    strokes.forEach(function(s) {
      if (s.length < 2) return;
      ctx.beginPath();
      ctx.moveTo(s[0].x, s[0].y);
      for (var i = 1; i < s.length; i++) ctx.lineTo(s[i].x, s[i].y);
      ctx.stroke();
    });
  }

  function onStart(e) {
    e.preventDefault();
    drawing = true;
    current = [pt(e)];
    if (hint) hint.style.display = 'none';
    if (existImg) { existImg.style.opacity = '.15'; }
  }

  function onMove(e) {
    e.preventDefault();
    if (!drawing) return;
    var p = pt(e);
    current.push(p);
    if (current.length >= 2) {
      ctx.beginPath();
      ctx.moveTo(current[current.length-2].x, current[current.length-2].y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
    }
  }

  function onEnd(e) {
    if (!drawing) return;
    drawing = false;
    if (current.length) strokes.push(current.slice());
    current = [];
    sync();
  }

  function sync() {
    dataInp.value = strokes.length ? canvas.toDataURL('image/png') : '';
  }

  window.profSigClear = function() {
    strokes = []; current = [];
    redraw(); sync();
    if (hint) hint.style.display = 'flex';
    if (existImg) existImg.style.opacity = '1';
  };

  window.profSigUndo = function() {
    if (strokes.length) strokes.pop();
    redraw(); sync();
    if (!strokes.length && hint) hint.style.display = 'flex';
  };

  canvas.addEventListener('mousedown',  onStart);
  canvas.addEventListener('mousemove',  onMove);
  canvas.addEventListener('mouseup',    onEnd);
  canvas.addEventListener('mouseleave', onEnd);
  canvas.addEventListener('touchstart', onStart, { passive: false });
  canvas.addEventListener('touchmove',  onMove,  { passive: false });
  canvas.addEventListener('touchend',   onEnd);

  setup();
  window.addEventListener('resize', setup);
})();
</script>
