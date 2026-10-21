<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * The site screen — the part of the workflow that lives at the entrance
 * (ATTENDANCE_WORKFLOW.md → "One-time site setup", step 3).
 *
 * It shows a signed token as a QR code. The token changes every
 * `attendance_qr_ttl_seconds`; the page asks the server for a fresh one rather
 * than computing anything itself, so the signature never leaves the server's
 * control. Nothing here is personal data: the code is (site, time window, MAC).
 *
 * Standalone, because it is meant to stay open full-screen on a tablet or the
 * foreman's phone at the gate.
 *
 * @var array       $sites
 * @var object|null $site
 * @var int         $ttl
 */
$company = (isset($site_settings['company_name']) && $site_settings['company_name'])
         ? $site_settings['company_name'] : 'Workshop WMS';
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Site check-in screen — <?= $h($company) ?></title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
  :root{--blue:#0071e3;--green:#1c8c3c;--amber:#b45309;--red:#c0392b;
        --t1:#1d1d1f;--t2:#6e6e73;--t3:#86868b;--sep:rgba(0,0,0,0.09);--bg:#f5f5f7;}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,system-ui,sans-serif;
       background:var(--bg);color:var(--t1);min-height:100vh;padding:20px;}
  .wrap{max-width:820px;margin:0 auto;}
  .head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px;}
  .brand{font-size:12px;font-weight:600;color:var(--t2);}
  .brand b{display:block;color:var(--t1);font-size:17px;margin-bottom:3px;letter-spacing:-.02em;}
  .picker{display:flex;gap:6px;flex-wrap:wrap;}
  .picker a{font-size:12px;text-decoration:none;padding:6px 11px;border-radius:999px;
            border:1px solid var(--sep);color:var(--t2);background:#fff;}
  .picker a.on{background:var(--t1);color:#fff;border-color:var(--t1);}
  .grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;align-items:start;}
  @media (max-width:760px){.grid{grid-template-columns:1fr;}}
  .card{background:#fff;border:1px solid var(--sep);border-radius:16px;padding:20px;}
  #qr{display:flex;align-items:center;justify-content:center;padding:14px;border-radius:12px;
      background:#fff;min-height:280px;}
  #qr svg{width:100%;max-width:340px;height:auto;display:block;}
  .kw{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);font-weight:600;}
  .big{font-size:22px;font-weight:700;letter-spacing:-.02em;margin-top:3px;}
  .sub{font-size:12.5px;color:var(--t2);line-height:1.65;margin-top:8px;}
  .code{font:11.5px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--t3);
        word-break:break-all;margin-top:14px;padding:10px 12px;border-radius:8px;background:var(--bg);}
  .ring{margin-top:16px;font-size:12.5px;color:var(--t2);display:flex;align-items:center;gap:8px;}
  .bar{height:4px;border-radius:999px;background:var(--g2,rgba(0,0,0,.06));overflow:hidden;flex:1;}
  .bar i{display:block;height:100%;width:100%;background:var(--blue);transition:width .5s linear;}
  ol{margin:14px 0 0 18px;font-size:12.5px;line-height:1.8;color:var(--t2);}
  .note{margin-top:14px;padding:11px 13px;border-radius:10px;font-size:12px;line-height:1.6;
        background:rgba(180,83,9,0.07);color:var(--amber);}
  .err{background:rgba(192,57,43,0.07);color:var(--red);}
  .tiny{font-size:11px;color:var(--t3);margin-top:12px;line-height:1.6;}
  a{color:var(--blue);text-decoration:none;}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <div class="brand"><b><?= $h($company) ?></b> Site check-in screen</div>
    <?php if (count($sites) > 1): ?>
      <div class="picker">
        <?php foreach ($sites as $s): ?>
          <a href="<?= base_url('attendance/board?site_id=' . (int)$s->id) ?>"
             class="<?= ($site && (int)$site->id === (int)$s->id) ? 'on' : '' ?>"><?= $h($s->name) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

<?php if (!$site): ?>
  <div class="card">
    <div class="kw">Not configured</div>
    <div class="big" style="margin-top:6px;">No active attendance site</div>
    <p class="sub">A supervisor has to add the site's coordinates and allowed GPS radius first, then this
      screen can show its check-in code.</p>
    <p class="sub"><a href="<?= base_url('attendance/sites') ?>">Configure a site →</a></p>
  </div>
<?php else: ?>
  <div class="grid">
    <div class="card">
      <div id="qr"><span class="sub">Loading the site code…</span></div>
      <div class="ring">
        <span id="countdown">—</span>
        <span class="bar"><i id="bar"></i></span>
      </div>
      <div class="code" id="code-text">—</div>
    </div>

    <div class="card">
      <div class="kw">Site</div>
      <div class="big"><?= $h($site->name) ?></div>
      <p class="sub">
        Geofence radius <strong><?= (int)$site->radius_m ?> m</strong> ·
        best GPS accuracy accepted <strong><?= (int)$site->max_accuracy_m ?> m</strong><br>
        <?= number_format((float)$site->latitude, 6) ?>, <?= number_format((float)$site->longitude, 6) ?>
      </p>
      <ol>
        <li>Sign in to the WMS on your own phone.</li>
        <li>Point the camera at this code, or open the link it gives.</li>
        <li>Allow location for that page, then confirm the check-in or check-out.</li>
        <li>Check out here again before you leave.</li>
      </ol>
      <div class="note" id="status-note">
        The code refreshes itself every <?= (int)$ttl ?> seconds. Keep this page open while the site is working.
      </div>
      <p class="tiny">
        Anyone with a camera can read this code — it proves presence at this site only together with the
        GPS check, which is why the radius matters. Someone on a phone call with a colleague on site can
        still be talked through it: this is a practical presence check, not absolute proof.
      </p>
      <p class="tiny"><a href="<?= base_url('attendance') ?>">← Attendance home</a></p>
    </div>
  </div>
<?php endif; ?>
</div>

<?php if ($site): ?>
<script src="<?= base_url('assets/vendor/qrcode/qrcode.js') ?>"></script>
<script>
(function () {
  var SITE_ID = <?= (int)$site->id ?>;
  var TTL     = <?= max(10, (int)$ttl) ?>;
  var qrBox   = document.getElementById('qr');
  var codeEl  = document.getElementById('code-text');
  var cdEl    = document.getElementById('countdown');
  var barEl   = document.getElementById('bar');
  var noteEl  = document.getElementById('status-note');

  var expiresAt = 0;
  var timer     = null;
  var loading   = false;

  function draw(data) {
    if (!data || !data.ok) {
      qrBox.innerHTML = '<span class="sub" style="color:#c0392b;">' +
        ((data && data.error) ? data.error : 'The site code could not be loaded.') + '</span>';
      return;
    }
    codeEl.textContent = data.token;
    expiresAt = Date.now() + (data.expires_in * 1000);

    try {
      var qr = qrcode(0, 'M');
      qr.addData(data.url);
      qr.make();
      qrBox.innerHTML = qr.createSvgTag(7, 4, 'Site check-in code');
    } catch (e) {
      qrBox.innerHTML = '<span class="sub" style="color:#c0392b;">The QR image could not be drawn, but the ' +
        'code below is valid: type it into the check-in page.</span>';
    }

    noteEl.className = 'note';
    noteEl.textContent = 'The code refreshes itself every ' + data.ttl + ' seconds. Keep this page open.';
  }

  function tick() {
    var left = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
    cdEl.textContent = left + ' s';
    barEl.style.width = Math.min(100, (left / TTL) * 100) + '%';
    if (left <= 0) fetchToken();
  }

  function fetchToken() {
    if (loading) return;
    loading = true;
    var url = <?= json_encode(base_url('attendance/token')) ?> + '?site_id=' + SITE_ID;
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) { loading = false; draw(d); })
      .catch(function () {
        loading = false;
        noteEl.className = 'note err';
        noteEl.textContent = 'The screen lost contact with the server. Retrying…';
      });
  }

  fetchToken();
  timer = setInterval(tick, 500);
})();
</script>
<?php endif; ?>
</body>
</html>
