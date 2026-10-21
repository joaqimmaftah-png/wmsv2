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

        <div class="flex ai-c jb" style="margin-top:14px;gap:8px;flex-wrap:wrap;">
          <span class="form-label" style="margin:0;">Site GPS coordinates</span>
          <div class="flex ai-c" style="gap:6px;flex-wrap:wrap;">
            <button type="button" class="btn btn-primary btn-sm btn-icon" id="use-here"
                    title="Read your device's current GPS latitude &amp; longitude automatically">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;">
                <circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2"/>
                <line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/>
                <line x1="2" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="22" y2="12"/>
              </svg>
              Capture current location
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="toggle-map"
                    title="Pick or fine-tune the exact gate pin on an interactive map">
              Pick on map
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="paste-coords"
                    title="Paste '-6.7924, 39.2083', DMS coordinates, or a Google Maps link">
              Paste Maps link
            </button>
          </div>
        </div>

        <div class="form-grid cols-2" style="margin-top:8px;">
          <div>
            <label class="form-label">Latitude</label>
            <input type="text" inputmode="decimal" name="latitude" id="lat" class="form-control mono" required
                   value="<?= $editing ? $h($editing->latitude) : '' ?>" placeholder="-6.7924123">
          </div>
          <div>
            <label class="form-label">Longitude</label>
            <input type="text" inputmode="decimal" name="longitude" id="lng" class="form-control mono" required
                   value="<?= $editing ? $h($editing->longitude) : '' ?>" placeholder="39.2083456">
          </div>
        </div>

        <div class="flex ai-c gap-10" style="margin-top:8px;flex-wrap:wrap;">
          <?php if ($has_saved_point): ?>
            <button type="button" class="btn btn-ghost btn-sm" id="check-here">How far am I from this point?</button>
          <?php endif; ?>
          <a href="#" target="_blank" rel="noopener noreferrer" id="open-map-link"
             style="display:none;font-size:11.5px;color:var(--blue);text-decoration:none;font-weight:500;">
            Open in Google Maps ↗
          </a>
        </div>

        <div id="geo-out" style="display:none;margin-top:8px;padding:9px 12px;border-radius:9px;font-size:12px;line-height:1.5;border:1px solid var(--border);background:var(--g1);color:var(--t2);"></div>

        <div id="map-wrap" style="display:none;margin-top:10px;border-radius:11px;overflow:hidden;border:1px solid var(--border);">
          <div id="site-map" style="height:250px;width:100%;background:#141420;"></div>
          <div style="padding:7px 11px;font-size:11px;color:var(--t3);background:var(--g1);display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <span>Click anywhere on the map or drag the pin to adjust Latitude &amp; Longitude.</span>
            <span id="map-radius-note">Blue circle = geofence radius</span>
          </div>
        </div>

        <div class="form-grid cols-2" style="margin-top:12px;">
          <div>
            <label class="form-label">Geofence radius (m)</label>
            <input type="number" name="radius_m" id="radius_m" class="form-control" min="25" max="5000" step="5" required
                   value="<?= $editing ? (int)$editing->radius_m : '' ?>" placeholder="e.g. 150">
            <div class="hint">How far from the point a punch is still accepted. Fit it to the worksite.</div>
          </div>
          <div>
            <label class="form-label">Worst GPS accuracy accepted (m)</label>
            <input type="number" name="max_accuracy_m" id="max_accuracy_m" class="form-control" min="5" max="2000" step="5" required
                   value="<?= $editing ? (int)$editing->max_accuracy_m : '' ?>" placeholder="e.g. 100">
            <div class="hint">A phone reporting a worse accuracy is refused — indoors this is usually the limit.</div>
          </div>
        </div>

        <label class="flex ai-c gap-10" style="margin-top:14px;font-size:12.5px;color:var(--t2);">
          <input type="checkbox" name="is_active" value="1" <?= (!$editing || $editing->is_active) ? 'checked' : '' ?>>
          Site active (its QR code can be displayed and used)
        </label>

        <label class="flex ai-c gap-10" style="margin-top:10px;font-size:12.5px;color:var(--t2);line-height:1.5;">
          <input type="checkbox" name="allow_static_qr" value="1"
                 <?= ($editing && !empty($static_site_map[(int)$editing->id])) ? 'checked' : '' ?>>
          <span>Allow printed / fixed QR fallback (for remote sites with no gate screen — live GPS radius &amp; accuracy still apply; rotated secret invalidates prints)</span>
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

        <div class="form-grid cols-2" style="margin-top:16px;">
          <div>
            <label class="form-label">Site code lifetime (seconds)</label>
            <input type="number" name="attendance_qr_ttl_seconds" class="form-control" min="10" max="3600" step="10"
                   value="<?= (int)$qr_ttl ?>">
            <div class="hint">How long one rotating QR token stays valid (default 60 s).</div>
          </div>
          <div>
            <label class="form-label">Refresh grace window (seconds)</label>
            <input type="number" name="attendance_qr_grace_seconds" class="form-control" min="0" max="3600" step="10"
                   value="<?= isset($qr_grace) ? (int)$qr_grace : 120 ?>">
            <div class="hint">Accepts a just-refreshed screen code while scanning (default 120 s).</div>
          </div>
        </div>

        <div style="margin-top:16px;">
          <label class="form-label">Location evidence retention (days)</label>
          <input type="number" name="attendance_location_retention_days" class="form-control" min="0" max="3650" step="1"
                 value="<?= isset($retention_days) ? (int)$retention_days : 365 ?>">
          <div class="hint">Scrubs raw GPS coordinates, IP &amp; browser strings on punches older than this window while keeping all daily attendance records &amp; audit events (<code>0</code> = retain indefinitely).</div>
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
            <a href="<?= base_url('attendance/print_qr?site_id=' . (int)$s->id) ?>" class="btn btn-ghost btn-sm"
               title="Printable gate QR poster for sites without a permanent screen">Print QR</a>
            <a href="<?= base_url('attendance/sites?edit=' . (int)$s->id . '&autolocate=1') ?>"
               class="btn btn-ghost btn-sm" title="Load this site and fill Latitude &amp; Longitude from your current GPS position">
              Set location
            </a>
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
  var radInput = document.getElementById('radius_m');
  var accInput = document.getElementById('max_accuracy_m');
  var out = document.getElementById('geo-out');
  var use = document.getElementById('use-here');
  var chk = document.getElementById('check-here');
  var pasteBtn = document.getElementById('paste-coords');
  var toggleMapBtn = document.getElementById('toggle-map');
  var mapWrap = document.getElementById('map-wrap');
  var openMapLink = document.getElementById('open-map-link');
  var savedLat = <?= $has_saved_point ? json_encode((float)$editing->latitude) : 'null' ?>;
  var savedLng = <?= $has_saved_point ? json_encode((float)$editing->longitude) : 'null' ?>;

  var mapInstance = null, mapMarker = null, mapCircle = null, leafletLoading = false;

  function showStatus(tone, html) {
    if (!out) return;
    out.style.display = 'block';
    if (tone === 'ok') {
      out.style.borderColor = 'rgba(48,209,88,0.35)';
      out.style.background = 'rgba(28,140,60,0.12)';
      out.style.color = 'var(--green)';
    } else if (tone === 'warn') {
      out.style.borderColor = 'rgba(255,159,10,0.35)';
      out.style.background = 'rgba(180,83,9,0.12)';
      out.style.color = 'var(--amber)';
    } else if (tone === 'bad') {
      out.style.borderColor = 'rgba(255,69,58,0.35)';
      out.style.background = 'rgba(192,57,43,0.12)';
      out.style.color = 'var(--red)';
    } else {
      out.style.borderColor = 'var(--border)';
      out.style.background = 'var(--g1)';
      out.style.color = 'var(--t2)';
    }
    out.innerHTML = html;
  }

  function validPair(a, o) {
    return isFinite(a) && isFinite(o) && a >= -90 && a <= 90 && o >= -180 && o <= 180;
  }

  function updateExternalMapLink() {
    if (!openMapLink) return;
    var a = parseFloat(lat.value), o = parseFloat(lng.value);
    if (validPair(a, o)) {
      openMapLink.href = 'https://www.google.com/maps?q=' + a.toFixed(7) + ',' + o.toFixed(7);
      openMapLink.style.display = 'inline';
    } else {
      openMapLink.style.display = 'none';
    }
  }

  function flashInputs() {
    [lat, lng].forEach(function (el) {
      if (!el) return;
      el.style.transition = 'border-color .25s, box-shadow .25s';
      el.style.borderColor = 'var(--green)';
      el.style.boxShadow = '0 0 0 3px rgba(48,209,88,0.18)';
      setTimeout(function () { el.style.borderColor = ''; el.style.boxShadow = ''; }, 1400);
    });
  }

  // Per ATTENDANCE_WORKFLOW.md ("One-time site setup", step 1), never assume a default
  // geofence radius — fill coordinates only so the supervisor explicitly chooses the site radius.
  function setCoordinates(la, lo, sourceNote) {
    lat.value = Number(la).toFixed(7);
    lng.value = Number(lo).toFixed(7);
    flashInputs();
    updateExternalMapLink();
    syncMapPin(true);
    if (sourceNote) showStatus('ok', sourceNote);
  }

  // Parse decimal pairs ("-6.7924, 39.2083"), Google/Apple Maps links, or DMS ("6°47'32.7\"S 39°12'30.0\"E")
  function parseCoordinateText(raw) {
    if (!raw || typeof raw !== 'string') return null;
    var s = decodeURIComponent(raw).trim();

    // 1. Google Maps !3d<lat>!4d<lng> exact place pin
    var m3d = s.match(/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/);
    if (m3d && validPair(parseFloat(m3d[1]), parseFloat(m3d[2]))) {
      return [parseFloat(m3d[1]), parseFloat(m3d[2])];
    }

    // 2. Query / @ parameters (?q=lat,lng | ?ll=lat,lng | @lat,lng)
    var mq = s.match(/(?:[?&](?:q|ll|query|center)=|@)(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/i);
    if (mq && validPair(parseFloat(mq[1]), parseFloat(mq[2]))) {
      return [parseFloat(mq[1]), parseFloat(mq[2])];
    }

    // 3. DMS notation e.g. 6°47'32.7"S 39°12'30.0"E
    var dmsRe = /(\d{1,3})\s*[°ºd]\s*(\d{1,2})\s*['’′m]\s*(\d{1,2}(?:\.\d+)?)\s*["”″s]?\s*([NSEW])/gi;
    var parts = [], dm;
    while ((dm = dmsRe.exec(s)) !== null) {
      var dec = parseFloat(dm[1]) + parseFloat(dm[2]) / 60 + parseFloat(dm[3]) / 3600;
      var dir = dm[4].toUpperCase();
      if (dir === 'S' || dir === 'W') dec = -dec;
      parts.push({ val: dec, dir: dir });
    }
    if (parts.length >= 2) {
      var la = (parts[0].dir === 'E' || parts[0].dir === 'W') ? parts[1].val : parts[0].val;
      var lo = (parts[0].dir === 'E' || parts[0].dir === 'W') ? parts[0].val : parts[1].val;
      if (validPair(la, lo)) return [la, lo];
    }

    // 4. Plain pair "-6.7924123, 39.2083456" or "-6.7924123 39.2083456"
    var mp = s.match(/(-?\d{1,2}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)/);
    if (mp && s.indexOf(',') !== -1 || (mp && mp[1].indexOf('.') !== -1 && mp[2].indexOf('.') !== -1)) {
      var a = parseFloat(mp[1]), o = parseFloat(mp[2]);
      if (validPair(a, o)) return [a, o];
    }
    return null;
  }

  function distanceM(a1, o1, a2, o2) {
    var R = 6371008.8, rad = Math.PI / 180;
    var dLat = (a2 - a1) * rad, dLon = (o2 - o1) * rad;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(a1 * rad) * Math.cos(a2 * rad) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    return Math.round(R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h)));
  }

  // Two-tier GPS acquisition:
  //   1. Hardware GNSS (`enableHighAccuracy: true`) with optional 3s satellite refinement if initial fix > 25m
  //   2. Automatic fallback to OS / Wi-Fi positioning (`enableHighAccuracy: false`) so laptops & desktops without GPS chips succeed immediately
  function here(cb) {
    if (!('geolocation' in navigator)) {
      showStatus('bad', 'This browser does not support GPS geolocation. Use <strong>Pick on map</strong> or <strong>Paste Maps link</strong> above.');
      return;
    }
    if (window.isSecureContext === false) {
      var localUrl = 'http://localhost' + window.location.pathname + window.location.search;
      showStatus('warn',
        'Browsers block direct GPS on plain HTTP LAN addresses (<code>' + window.location.host + '</code>). ' +
        'Open <a href="' + localUrl + '" style="color:var(--blue);text-decoration:underline;">' + localUrl + '</a> on the XAMPP host, serve over HTTPS, ' +
        'or click <strong>Pick on map</strong> / <strong>Paste Maps link</strong> above.');
      return;
    }

    if (use) use.disabled = true;
    showStatus('info', 'Capturing current GPS coordinates…');

    var settled = false;
    function finish(pos) {
      if (settled) return;
      settled = true;
      if (use) use.disabled = false;
      cb(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
    }

    navigator.geolocation.getCurrentPosition(function (p) {
      // If first fix is already sharp (<= 25m), use it right away; otherwise briefly listen for a tighter GPS lock
      if (p.coords.accuracy <= 25 || !navigator.geolocation.watchPosition) {
        finish(p);
        return;
      }
      var best = p;
      var wid = navigator.geolocation.watchPosition(function (p2) {
        if (p2.coords.accuracy < best.coords.accuracy) best = p2;
        if (best.coords.accuracy <= 15) {
          navigator.geolocation.clearWatch(wid);
          finish(best);
        }
      }, function () {}, { enableHighAccuracy: true, maximumAge: 0 });
      setTimeout(function () {
        navigator.geolocation.clearWatch(wid);
        finish(best);
      }, 2500);
    }, function (err) {
      if (err.code === 1) {
        if (use) use.disabled = false;
        showStatus('bad', 'Location permission was blocked in the browser. Click the site lock/icon in the address bar → Allow Location, or use <strong>Pick on map</strong> / <strong>Paste Maps link</strong>.');
        return;
      }
      // Fallback to OS / Wi-Fi network positioning for desktops & indoor laptops without GNSS hardware
      showStatus('info', 'Refining via network / Wi-Fi location…');
      navigator.geolocation.getCurrentPosition(function (p2) {
        finish(p2);
      }, function (err2) {
        if (use) use.disabled = false;
        showStatus('bad',
          (err2.code === 1
            ? 'Location permission denied. Allow Location in browser site settings, '
            : 'Device could not determine current position (check Windows/Phone Location Services), ') +
          'or use <strong>Pick on map</strong> / <strong>Paste Maps link</strong>.');
      }, { enableHighAccuracy: false, timeout: 12000, maximumAge: 60000 });
    }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 });
  }

  if (use) use.addEventListener('click', function () {
    here(function (la, lo, ac) {
      var roundedAcc = Math.round(ac);
      var maxAcc = accInput && accInput.value ? parseInt(accInput.value, 10) : 100;
      var extraWarn = roundedAcc > maxAcc
        ? ' <span style="color:var(--amber);">(Note: this device\'s indoor/Wi-Fi accuracy is ±' + roundedAcc + ' m — stand outdoors on a phone for a tighter center point if needed.)</span>'
        : '';
      setCoordinates(
        la,
        lo,
        '✓ Captured current location: <strong>' + la.toFixed(7) + ', ' + lo.toFixed(7) + '</strong> (±' + roundedAcc + ' m). Click <strong><?= $editing ? 'Save site' : 'Add site' ?></strong> below to save.' + extraWarn
      );
    });
  });

  if (chk) chk.addEventListener('click', function () {
    here(function (la, lo, ac) {
      var d = distanceM(savedLat, savedLng, la, lo);
      showStatus('info', 'This device is <strong>' + d + ' m</strong> from the saved site center (±' + Math.round(ac) + ' m).');
    });
  });

  // Auto-split pasted "lat, lng" or Google Maps URLs into both inputs automatically
  [lat, lng].forEach(function (inp) {
    if (!inp) return;
    inp.addEventListener('paste', function (ev) {
      var clip = (ev.clipboardData || window.clipboardData);
      if (!clip) return;
      var text = clip.getData('text');
      var pair = parseCoordinateText(text);
      if (pair) {
        ev.preventDefault();
        setCoordinates(pair[0], pair[1], '✓ Extracted Latitude &amp; Longitude from pasted text: <strong>' + pair[0].toFixed(7) + ', ' + pair[1].toFixed(7) + '</strong>.');
      }
    });
    inp.addEventListener('input', function () {
      updateExternalMapLink();
      syncMapPin(false);
    });
  });

  if (pasteBtn) pasteBtn.addEventListener('click', function () {
    var applyText = function (raw) {
      if (!raw) return;
      var pair = parseCoordinateText(raw);
      if (pair) {
        setCoordinates(pair[0], pair[1], '✓ Filled Latitude &amp; Longitude: <strong>' + pair[0].toFixed(7) + ', ' + pair[1].toFixed(7) + '</strong>.');
      } else {
        showStatus('warn', 'Could not find valid coordinates in that text. Paste a Google Maps URL or coordinates like <code>-6.7924123, 39.2083456</code>.');
      }
    };
    if (navigator.clipboard && navigator.clipboard.readText && window.isSecureContext) {
      navigator.clipboard.readText().then(function (txt) {
        if (txt && parseCoordinateText(txt)) applyText(txt);
        else applyText(window.prompt('Paste Google Maps link or "latitude, longitude" coordinates:', ''));
      }).catch(function () {
        applyText(window.prompt('Paste Google Maps link or "latitude, longitude" coordinates:', ''));
      });
    } else {
      applyText(window.prompt('Paste Google Maps link or "latitude, longitude" coordinates:', ''));
    }
  });

  // Optional interactive OpenStreetMap pin & geofence circle picker
  function syncMapPin(recenter) {
    if (!mapInstance || !window.L) return;
    var a = parseFloat(lat.value), o = parseFloat(lng.value);
    if (!validPair(a, o)) return;
    var r = (radInput && parseFloat(radInput.value) > 0) ? parseFloat(radInput.value) : 150;
    if (!mapMarker) {
      mapMarker = window.L.marker([a, o], { draggable: true }).addTo(mapInstance);
      mapMarker.on('dragend', function () {
        var p = mapMarker.getLatLng();
        setCoordinates(p.lat, p.lng, '✓ Updated pin position: <strong>' + p.lat.toFixed(7) + ', ' + p.lng.toFixed(7) + '</strong>.');
      });
    } else {
      mapMarker.setLatLng([a, o]);
    }
    if (!mapCircle) {
      mapCircle = window.L.circle([a, o], { radius: r, color: '#0A84FF', fillColor: '#0A84FF', fillOpacity: 0.14, weight: 2 }).addTo(mapInstance);
    } else {
      mapCircle.setLatLng([a, o]);
      mapCircle.setRadius(r);
    }
    if (recenter) mapInstance.setView([a, o], Math.max(mapInstance.getZoom(), 17));
  }

  function initMap() {
    if (mapInstance || !window.L) return;
    var startLat = validPair(parseFloat(lat.value), parseFloat(lng.value)) ? parseFloat(lat.value) : -6.7924;
    var startLng = validPair(parseFloat(lat.value), parseFloat(lng.value)) ? parseFloat(lng.value) : 39.2083;
    var startZoom = validPair(parseFloat(lat.value), parseFloat(lng.value)) ? 17 : 12;
    mapInstance = window.L.map('site-map').setView([startLat, startLng], startZoom);
    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap'
    }).addTo(mapInstance);
    mapInstance.on('click', function (ev) {
      setCoordinates(ev.latlng.lat, ev.latlng.lng, '✓ Selected point on map: <strong>' + ev.latlng.lat.toFixed(7) + ', ' + ev.latlng.lng.toFixed(7) + '</strong>.');
    });
    syncMapPin(false);
    setTimeout(function () { mapInstance.invalidateSize(); }, 120);
  }

  if (radInput) radInput.addEventListener('input', function () { syncMapPin(false); });

  if (toggleMapBtn && mapWrap) toggleMapBtn.addEventListener('click', function () {
    var open = mapWrap.style.display === 'none';
    mapWrap.style.display = open ? 'block' : 'none';
    toggleMapBtn.textContent = open ? 'Hide map' : 'Pick on map';
    if (!open) return;
    if (window.L) {
      initMap();
      setTimeout(function () { if (mapInstance) mapInstance.invalidateSize(); }, 80);
      return;
    }
    if (leafletLoading) return;
    leafletLoading = true;
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.css';
    document.head.appendChild(link);
    var sc = document.createElement('script');
    sc.src = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js';
    sc.onload = function () { leafletLoading = false; initMap(); };
    sc.onerror = function () {
      leafletLoading = false;
      mapWrap.style.display = 'none';
      toggleMapBtn.textContent = 'Pick on map';
      showStatus('warn', 'Interactive map tiles require internet access — use <strong>Capture current location</strong> or paste coordinates directly.');
    };
    document.head.appendChild(sc);
  });

  updateExternalMapLink();

  // Trigger automatic GPS capture when arriving via "Set location" (?edit=X&autolocate=1)
  if (/[?&]autolocate=1(?:&|$)/.test(window.location.search) && use) {
    use.click();
  }
})();
</script>

<?php endif; ?>
