<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Supervisor: geofence sites + the attendance policy
 * (ATTENDANCE_WORKFLOW.md → "One-time site setup" & "Failure handling").
 *
 * The coordinates entered here are the centre of the accepted radius. The
 * page can read the browser's own position to fill them in, and can show how
 * far the current phone is from the saved point — a check for the supervisor
 * standing on site, not a validation the server relies on.
 *
 * @var bool        $ready
 * @var array       $sites
 * @var object|null $editing
 * @var bool        $gate_enabled
 * @var string      $open_session_policy
 * @var int         $qr_ttl
 */
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$editing = isset($editing) ? $editing : NULL;
$has_saved_point = $editing && $editing->latitude !== NULL && $editing->longitude !== NULL;
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="ct" style="font-size:16px;">Attendance sites &amp; policy</div>
    <div class="cs">Where a punch is allowed, how accurate the position must be, who is assignable</div>
  </div>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <a href="<?= base_url('attendance/board') ?>" class="btn btn-ghost btn-sm">Site screen</a>
    <a href="<?= base_url('attendance/log') ?>" class="btn btn-ghost btn-sm">Punch log</a>
    <a href="<?= base_url('attendance') ?>" class="btn btn-ghost btn-sm">← Attendance</a>
  </div>
</div>

<?php if (empty($ready)): ?>
  <div class="card" style="border-left:3px solid var(--amber);">
    <div style="padding:16px 18px;font-size:12.5px;line-height:1.7;color:var(--t2);">
      <strong style="color:var(--t1);">The attendance tables do not exist yet.</strong><br>
      Run <code>php scripts/migrate.php</code> (or import <code>database/schema.sql</code> on a fresh
      installation) to create <code>attendance_sites</code> and <code>attendance_punches</code>.
    </div>
  </div>
<?php else: ?>

<div class="grid g-2 mb-4" style="gap:16px;align-items:start;">

  <!-- ── the site being edited ─────────────────────────────────────── -->
  <div class="card">
    <div class="ch" style="padding:14px 18px 8px;">
      <div><div class="ct"><?= $editing ? 'Edit site' : 'Add a site' ?></div>
        <div class="cs">The QR code is tied to this point and radius</div></div>
      <?php if ($editing): ?>
        <a href="<?= base_url('attendance/sites') ?>" class="btn btn-ghost btn-sm">New site</a>
      <?php endif; ?>
    </div>

    <?= form_open('attendance/sites') ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editing ? (int)$editing->id : 0 ?>">

      <div style="padding:6px 18px 18px;">
        <label class="form-label">Site name</label>
        <input type="text" name="name" class="form-control" maxlength="100" required
               value="<?= $editing ? $h($editing->name) : '' ?>" placeholder="Main workshop gate">

        <div class="form-grid cols-2" style="margin-top:12px;">
          <div>
            <label class="form-label">Latitude</label>
            <input type="text" inputmode="decimal" name="latitude" id="lat" class="form-control" required
                   value="<?= $editing ? $h($editing->latitude) : '' ?>" placeholder="0.3475964">
          </div>
          <div>
            <label class="form-label">Longitude</label>
            <input type="text" inputmode="decimal" name="longitude" id="lng" class="form-control" required
                   value="<?= $editing ? $h($editing->longitude) : '' ?>" placeholder="32.5825197">
          </div>
        </div>

        <div class="flex ai-c gap-10" style="margin-top:10px;flex-wrap:wrap;">
          <button type="button" class="btn btn-ghost btn-sm" id="use-here">Use my current location</button>
          <?php if ($has_saved_point): ?>
            <button type="button" class="btn btn-ghost btn-sm" id="check-here">How far am I from this point?</button>
          <?php endif; ?>
          <span id="geo-out" style="font-size:11.5px;color:var(--t3);"></span>
        </div>

        <div class="form-grid cols-2" style="margin-top:12px;">
          <div>
            <label class="form-label">Geofence radius (m)</label>
            <input type="number" name="radius_m" class="form-control" min="25" max="5000" step="5" required
                   value="<?= $editing ? (int)$editing->radius_m : '' ?>" placeholder="e.g. 150">
            <div class="hint">How far from the point a punch is still accepted. Fit it to the worksite.</div>
          </div>
          <div>
            <label class="form-label">Worst GPS accuracy accepted (m)</label>
            <input type="number" name="max_accuracy_m" class="form-control" min="5" max="2000" step="5" required
                   value="<?= $editing ? (int)$editing->max_accuracy_m : '' ?>" placeholder="e.g. 100">
            <div class="hint">A phone reporting a worse accuracy is refused — indoors this is usually the limit.</div>
          </div>
        </div>

        <label class="flex ai-c gap-10" style="margin-top:14px;font-size:12.5px;color:var(--t2);">
          <input type="checkbox" name="is_active" value="1" <?= (!$editing || $editing->is_active) ? 'checked' : '' ?>>
          Site active (its QR code can be displayed and used)
        </label>

        <div class="flex ai-c gap-10" style="justify-content:flex-end;margin-top:16px;">
          <button type="submit" class="btn btn-primary"><?= $editing ? 'Save site' : 'Add site' ?></button>
        </div>
      </div>
    <?= form_close() ?>
  </div>

  <!-- ── policy ────────────────────────────────────────────────────── -->
  <div class="card">
    <div class="ch" style="padding:14px 18px 8px;">
      <div><div class="ct">Policy</div><div class="cs">Applies to every site</div></div>
    </div>
    <?= form_open('attendance/policy') ?>
      <div style="padding:6px 18px 18px;">
        <label class="flex ai-c gap-10" style="font-size:12.5px;color:var(--t2);line-height:1.5;">
          <input type="checkbox" name="attendance_gate_enabled" value="1" <?= !empty($gate_enabled) ? 'checked' : '' ?>>
          <span><strong style="color:var(--t1);">Only assign technicians who are on site</strong><br>
            The work-order picker hides anyone not checked in, already checked out, or still holding an
            unfinished work order — and the save handler refuses them even if the form is tampered with.</span>
        </label>

        <div style="margin-top:16px;">
          <label class="form-label">A check-out with an open work session</label>
          <select name="attendance_open_session_policy" class="form-select">
            <option value="warn" <?= $open_session_policy !== 'block' ? 'selected' : '' ?>>Warn and accept the check-out</option>
            <option value="block" <?= $open_session_policy === 'block' ? 'selected' : '' ?>>Refuse the check-out until the session is closed</option>
          </select>
          <div class="hint">Either way the session is never left silently open: a warning names the work order.</div>
        </div>

        <div style="margin-top:16px;">
          <label class="form-label">Site code lifetime (seconds)</label>
          <input type="number" name="attendance_qr_ttl_seconds" class="form-control" min="10" max="3600" step="10"
                 value="<?= (int)$qr_ttl ?>">
          <div class="hint">How long one QR token stays valid. Shorter is safer; 60 s is comfortable to scan.</div>
        </div>

        <div class="flex ai-c gap-10" style="justify-content:flex-end;margin-top:16px;">
          <button type="submit" class="btn btn-primary">Save policy</button>
        </div>
      </div>
    <?= form_close() ?>
  </div>
</div>

<!-- ── sites ───────────────────────────────────────────────────────── -->
<div class="card">
  <div class="ch" style="padding:14px 18px 8px;">
    <div><div class="ct">Sites</div><div class="cs"><?= count($sites) ?> configured</div></div>
  </div>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:12px;">
      <thead>
        <tr><th>Site</th><th>Coordinates</th><th>Radius</th><th>Accuracy</th><th>State</th>
            <th>Code secret</th><th style="text-align:right;">Actions</th></tr>
      </thead>
      <tbody>
      <?php if (empty($sites)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--t3);">
          No site yet. Add one on the left — attendance punching stays off until you do.</td></tr>
      <?php else: foreach ($sites as $s): ?>
        <tr>
          <td style="font-size:12.5px;font-weight:600;"><?= $h($s->name) ?></td>
          <td class="mono" style="font-size:11.5px;color:var(--t3);">
            <?= number_format((float)$s->latitude, 6) ?>, <?= number_format((float)$s->longitude, 6) ?>
          </td>
          <td style="font-size:12px;"><?= (int)$s->radius_m ?> m</td>
          <td style="font-size:12px;">≤ <?= (int)$s->max_accuracy_m ?> m</td>
          <td>
            <span class="badge" style="background:<?= $s->is_active ? 'rgba(28,140,60,0.1);color:var(--green)' : 'rgba(0,0,0,0.05);color:var(--t3)' ?>">
              <?= $s->is_active ? 'ACTIVE' : 'OFF' ?>
            </span>
          </td>
          <td class="mono" style="font-size:10.5px;color:var(--t4);">
            <?= $h(substr((string)$s->qr_secret, 0, 8)) ?>…
          </td>
          <td style="text-align:right;white-space:nowrap;">
            <a href="<?= base_url('attendance/board?site_id=' . (int)$s->id) ?>" class="btn btn-ghost btn-sm">Screen</a>
            <a href="<?= base_url('attendance/sites?edit=' . (int)$s->id) ?>" class="btn btn-ghost btn-sm">Edit</a>
            <?= form_open('attendance/sites', array('style' => 'display:inline;'), array('action' => 'rotate', 'id' => (int)$s->id)) ?>
              <button type="submit" class="btn btn-amber btn-sm"
                onclick="return confirm('Rotate this site\'s code secret? Every code already displayed or photographed stops working.');">
                Rotate code
              </button>
            <?= form_close() ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div style="padding:12px 20px 18px;font-size:11.5px;color:var(--t3);line-height:1.7;">
    Rotating a secret is the emergency button: it invalidates every screenshot of the old code. It does not
    delete any punch. A punch is never edited or deleted — supervisor corrections are added as new events
    with their reason and author.
  </div>
</div>

<script>
(function () {
  var lat = document.getElementById('lat');
  var lng = document.getElementById('lng');
  var out = document.getElementById('geo-out');
  var use = document.getElementById('use-here');
  var chk = document.getElementById('check-here');
  var savedLat = <?= $has_saved_point ? json_encode((float)$editing->latitude) : 'null' ?>;
  var savedLng = <?= $has_saved_point ? json_encode((float)$editing->longitude) : 'null' ?>;

  function distanceM(a1, o1, a2, o2) {
    var R = 6371008.8, rad = Math.PI / 180;
    var dLat = (a2 - a1) * rad, dLon = (o2 - o1) * rad;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(a1 * rad) * Math.cos(a2 * rad) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    return Math.round(R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h)));
  }

  function here(cb) {
    if (!('geolocation' in navigator)) { out.textContent = 'This browser cannot read a position.'; return; }
    out.textContent = 'Reading the position…';
    navigator.geolocation.getCurrentPosition(function (p) {
      cb(p.coords.latitude, p.coords.longitude, p.coords.accuracy);
    }, function (e) {
      out.textContent = e.code === 1 ? 'Location permission denied.' : 'No position available.';
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  }

  if (use) use.addEventListener('click', function () {
    here(function (la, lo, ac) {
      lat.value = la.toFixed(7);
      lng.value = lo.toFixed(7);
      out.textContent = 'Filled from this device (±' + Math.round(ac) + ' m). Save to apply.';
    });
  });

  if (chk) chk.addEventListener('click', function () {
    here(function (la, lo, ac) {
      var d = distanceM(savedLat, savedLng, la, lo);
      out.textContent = 'This device is ' + d + ' m from the saved point (±' + Math.round(ac) + ' m).';
    });
  });
})();
</script>

<?php endif; ?>
