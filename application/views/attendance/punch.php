<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * The phone page a site QR code opens (ATTENDANCE_WORKFLOW.md → "Technician
 * check-in"). Deliberately standalone — no sidebar, no desktop chrome — and
 * deliberately dumb: it collects one position and posts it. Every decision
 * (token, geofence, accuracy, punch type) is made on the server.
 *
 * @var bool        $ready
 * @var string      $token
 * @var object|null $site
 * @var array|null  $verdict
 * @var string      $next_type
 * @var bool        $on_site
 * @var object|null $last_punch
 * @var array       $today
 * @var object|null $user
 * @var object|null $open_session
 */
$company = (isset($site_settings['company_name']) && $site_settings['company_name'])
         ? $site_settings['company_name'] : 'Workshop WMS';
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$site        = isset($site) ? $site : NULL;
$verdict     = isset($verdict) ? $verdict : NULL;
$next_type   = isset($next_type) ? $next_type : 'check_in';
$token       = isset($token) ? (string)$token : '';
$today       = isset($today) ? $today : array();
$open_session= isset($open_session) ? $open_session : NULL;
$verdict_ok  = is_array($verdict) && !empty($verdict['ok']);
$has_token   = $token !== '';
$label       = ($next_type === 'check_out') ? 'Check out' : 'Check in';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?= $h(isset($page_title) ? $page_title : 'Attendance') ?> — <?= $h($company) ?></title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
  :root{
    --blue:#0071e3;--green:#1c8c3c;--amber:#b45309;--red:#c0392b;
    --t1:#1d1d1f;--t2:#6e6e73;--t3:#86868b;--sep:rgba(0,0,0,0.09);
    --bg:#f5f5f7;--surface:#fff;
  }
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,system-ui,sans-serif;
       background:var(--bg);color:var(--t1);min-height:100vh;padding:18px 16px 34px;
       -webkit-font-smoothing:antialiased;}
  .wrap{max-width:460px;margin:0 auto;}
  .brand{display:flex;align-items:center;gap:9px;margin-bottom:18px;color:var(--t2);font-size:12px;font-weight:600;}
  .brand b{color:var(--t1);font-size:14px;}
  .card{background:var(--surface);border:1px solid var(--sep);border-radius:14px;padding:18px;margin-bottom:14px;}
  h1{font-size:21px;font-weight:700;letter-spacing:-.02em;margin-bottom:4px;}
  .sub{font-size:13px;color:var(--t2);line-height:1.55;}
  .site{margin-top:12px;padding:11px 13px;border-radius:10px;background:rgba(0,113,227,0.06);
        color:var(--blue);font-size:13px;font-weight:600;}
  .site small{display:block;color:var(--t3);font-weight:400;margin-top:2px;}
  .note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55;margin-top:12px;}
  .note.bad{background:rgba(192,57,43,0.07);color:var(--red);}
  .note.warn{background:rgba(180,83,9,0.08);color:var(--amber);}
  .note.ok{background:rgba(28,140,60,0.08);color:var(--green);}
  .status{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--t2);margin-top:14px;}
  .dot{width:8px;height:8px;border-radius:50%;background:var(--t3);flex-shrink:0;}
  .dot.ok{background:var(--green);} .dot.bad{background:var(--red);} .dot.wait{background:var(--amber);}
  label.f{display:block;font-size:12px;font-weight:600;color:var(--t2);margin:16px 0 6px;}
  input.code{width:100%;padding:13px 12px;border:1px solid var(--sep);border-radius:10px;
             font:14px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;background:#fff;color:var(--t1);}
  input.code:focus{outline:none;border-color:var(--blue);}
  button.go{width:100%;margin-top:16px;padding:16px;border:none;border-radius:12px;
            font:600 16px/1 inherit;color:#fff;background:var(--blue);cursor:pointer;}
  button.go:disabled{background:#c7c7cc;cursor:not-allowed;}
  button.go.out{background:var(--amber);}
  button.retry{width:100%;margin-top:10px;padding:12px;border:1px solid var(--sep);border-radius:10px;
               background:#fff;color:var(--t1);font:600 13px/1 inherit;cursor:pointer;}
  table{width:100%;border-collapse:collapse;margin-top:10px;}
  th{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--t3);text-align:left;padding:6px 0;}
  td{font-size:12.5px;padding:7px 0;border-top:1px solid var(--sep);}
  td.r{text-align:right;color:var(--t3);}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;}
  .pill.in{background:rgba(28,140,60,0.1);color:var(--green);}
  .pill.out{background:rgba(180,83,9,0.1);color:var(--amber);}
  .tiny{font-size:11px;color:var(--t3);line-height:1.5;margin-top:12px;}
  a{color:var(--blue);text-decoration:none;}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <b><?= $h($company) ?></b> · Attendance
  </div>

<?php if (empty($ready)): ?>
  <div class="card">
    <h1>Attendance is not set up</h1>
    <p class="sub">This installation has no attendance sites yet. A supervisor has to configure the site and its
      GPS radius before punches can be recorded.</p>
  </div>
<?php else: ?>

  <!-- ═══ 1. What this page is about to do ═══ -->
  <div class="card">
    <h1><?= $h($label) ?></h1>
    <p class="sub">
      <?= isset($user->first_name) ? $h($user->first_name . ' ' . $user->last_name) : 'Signed in'; ?> ·
      <?= date('D d M Y') ?>
    </p>

    <?php if ($has_token && $site): ?>
      <div class="site"><?= $h($site->name) ?>
        <small>Site code verified on the server · allowed radius <?= (int)$site->radius_m ?> m</small>
      </div>
    <?php endif; ?>

    <?php if ($has_token && is_array($verdict) && !$verdict_ok): ?>
      <div class="note bad">
        <strong>This site code cannot be used.</strong><br>
        <?= $h(isset($verdict['message']) ? $verdict['message'] : 'Scan the QR code on the site screen again.') ?>
      </div>
    <?php elseif (!$has_token): ?>
      <div class="note warn">
        <strong>Scan the QR code on the site screen.</strong><br>
        If your camera opened this page without the code, paste the code shown under the QR instead.
        A code is only valid for a short time and only at the site it belongs to.
      </div>
      <form method="get" action="<?= base_url('attendance/punch') ?>">
        <label class="f" for="code">Site code</label>
        <input class="code" id="code" name="t" type="text" inputmode="latin" autocomplete="off"
               spellcheck="false" placeholder="v1.3.4812345.9f2c…">
        <button class="go" type="submit">Use this code</button>
      </form>
    <?php endif; ?>

    <?php if (!empty($open_session)): ?>
      <div class="note warn">
        You still have an open work session<?= !empty($open_session->wo_number) ? ' on <strong>' . $h($open_session->wo_number) . '</strong>' : '' ?>.
        Close it when the job is done — a check-out with an open session is flagged to your supervisor.
      </div>
    <?php endif; ?>

    <div class="status">
      <span class="dot <?= $on_site ? 'ok' : 'bad' ?>"></span>
      <span><?= $on_site ? 'On site now' : 'Not on site' ?></span>
    </div>
  </div>

  <!-- ═══ 2. The punch itself ═══ -->
  <?php if ($has_token && $verdict_ok): ?>
    <div class="card">
      <p class="sub" style="margin-bottom:4px;">Send one position for this punch. The app does not track you
        and does not ask for location again until the next punch.</p>

      <div class="status" id="geo-status">
        <span class="dot wait" id="geo-dot"></span>
        <span id="geo-text">Reading your position…</span>
      </div>

      <?= form_open('attendance/record', array('id' => 'punchForm', 'autocomplete' => 'off')) ?>
        <input type="hidden" name="t"   value="<?= $h($token) ?>">
        <input type="hidden" name="lat" id="lat" value="">
        <input type="hidden" name="lng" id="lng" value="">
        <input type="hidden" name="acc" id="acc" value="">
        <button class="go <?= $next_type === 'check_out' ? 'out' : '' ?>" id="punch-btn" type="submit" disabled>
          <?= $next_type === 'check_out' ? 'Confirm check-out' : 'Confirm check-in' ?>
        </button>
      <?= form_close() ?>

      <button class="retry" type="button" id="retry">Retry location</button>

      <p class="tiny">
        Time is taken from the server, not from this phone. If location is blocked, allow it for this
        site in the browser settings and reload — a punch without a position is not accepted.
      </p>
    </div>
  <?php endif; ?>

  <!-- ═══ 3. Today, so far ═══ -->
  <div class="card">
    <p class="sub" style="font-weight:600;color:var(--t1);">Your punches today</p>
    <?php if (!empty($today)): ?>
      <table>
        <thead><tr><th>Punch</th><th>Time</th><th style="text-align:right;">Source</th></tr></thead>
        <tbody>
        <?php foreach ($today as $p): ?>
          <tr>
            <td><span class="pill <?= $p->punch_type === 'check_in' ? 'in' : 'out' ?>">
              <?= $p->punch_type === 'check_in' ? 'IN' : 'OUT' ?></span></td>
            <td class="mono"><?= date('H:i', strtotime((string)$p->punched_at)) ?></td>
            <td class="r"><?= $p->origin === 'supervisor' ? 'Supervisor' : 'Phone' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="tiny" style="margin-top:8px;">Nothing yet today.</p>
    <?php endif; ?>
    <p class="tiny"><a href="<?= base_url('attendance') ?>">Attendance home</a></p>
  </div>

<?php endif; ?>
</div>

<?php if (!empty($ready) && $has_token && $verdict_ok): ?>
<script>
(function () {
  var statusDot  = document.getElementById('geo-dot');
  var statusText = document.getElementById('geo-text');
  var button     = document.getElementById('punch-btn');
  var retry      = document.getElementById('retry');
  var latInput   = document.getElementById('lat');
  var lngInput   = document.getElementById('lng');
  var accInput   = document.getElementById('acc');
  var form       = document.getElementById('punchForm');

  function say(kind, text) {
    statusDot.className = 'dot ' + kind;
    statusText.textContent = text;
  }

  function locate() {
    button.disabled = true;
    if (!('geolocation' in navigator)) {
      say('bad', 'This browser cannot read a position, so it cannot punch. Use a modern browser on the phone.');
      return;
    }
    if (window.isSecureContext === false) {
      say('bad', 'Location needs HTTPS. Ask the site to serve the WMS over HTTPS.');
      return;
    }

    say('wait', 'Reading your position… this can take a few seconds.');

    function accept(pos) {
      var c = pos.coords;
      latInput.value = c.latitude.toFixed(7);
      lngInput.value = c.longitude.toFixed(7);
      accInput.value = Math.round(c.accuracy);
      say(c.accuracy > 100 ? 'wait' : 'ok',
          'Position found (±' + Math.round(c.accuracy) + ' m).');
      button.disabled = false;
    }

    navigator.geolocation.getCurrentPosition(accept, function (err) {
      if (err.code === 1) {
        say('bad', 'Location permission was denied. Allow it for this site in the browser settings, then retry.');
        return;
      }
      say('wait', 'Refining via network location…');
      navigator.geolocation.getCurrentPosition(accept, function (err2) {
        var map = {
          1: 'Location permission was denied. Allow it for this site in the browser settings, then retry.',
          2: 'The phone could not get a position. Move into the open, then retry.',
          3: 'Reading the position took too long. Retry, or move into the open.'
        };
        say('bad', map[err2.code] || 'Your position could not be read. Retry.');
      }, { enableHighAccuracy: false, timeout: 12000, maximumAge: 60000 });
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
  }

  form.addEventListener('submit', function (e) {
    if (!latInput.value || !lngInput.value) {
      e.preventDefault();
      say('bad', 'No position yet — retry location before confirming.');
    } else if (!button.disabled) {
      button.disabled = true;
      button.textContent = 'Saving…';
    }
  });

  retry.addEventListener('click', locate);
  locate();
})();
</script>
<?php endif; ?>
</body>
</html>
