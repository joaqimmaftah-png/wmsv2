<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Printable A4 fixed gate QR poster for sites without a permanent screen
 * (ATTENDANCE_WORKFLOW.md → "One-time site setup", step 3).
 *
 * Carries no technician information — only the signed static token for this
 * site (`v1.<site>.0.<HMAC>`). Punches still require a signed-in worker plus
 * server-side GPS geofence & accuracy verification, and rotating the site's
 * secret invalidates every printed sheet immediately.
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
<title>Printable Gate QR — <?= $site ? $h($site->name) : 'Attendance' ?> — <?= $h($company) ?></title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,system-ui,sans-serif;
       background:#f5f5f7;color:#1d1d1f;padding:24px;}
  .toolbar{max-width:700px;margin:0 auto 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
  .btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:999px;font:600 13px/1 inherit;
       text-decoration:none;border:1px solid rgba(0,0,0,.12);background:#fff;color:#1d1d1f;cursor:pointer;}
  .btn-primary{background:#0071e3;border-color:#0071e3;color:#fff;}
  .picker{display:flex;gap:6px;flex-wrap:wrap;}
  .picker a{font-size:12px;padding:6px 12px;border-radius:999px;border:1px solid rgba(0,0,0,.1);background:#fff;color:#6e6e73;text-decoration:none;}
  .picker a.on{background:#1d1d1f;color:#fff;border-color:#1d1d1f;}
  .poster{max-width:700px;margin:0 auto;background:#fff;border:1px solid rgba(0,0,0,.1);border-radius:20px;padding:38px 34px;text-align:center;}
  .org{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6e6e73;}
  h1{font-size:30px;font-weight:800;letter-spacing:-.025em;margin-top:6px;}
  .fence{font-size:13.5px;color:#6e6e73;margin-top:6px;}
  #qr{margin:24px auto;width:300px;max-width:100%;min-height:260px;display:flex;align-items:center;justify-content:center;}
  #qr svg{width:100%;height:auto;display:block;}
  .code{display:inline-block;margin-top:8px;padding:8px 14px;border-radius:8px;background:#f5f5f7;
        font:12px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;color:#6e6e73;word-break:break-all;}
  .steps{text-align:left;max-width:500px;margin:24px auto 0;font-size:14px;line-height:1.85;color:#1d1d1f;}
  .warn{max-width:700px;margin:0 auto 16px;padding:13px 16px;border-radius:12px;background:#fff8eb;border:1px solid #f3d19c;
        color:#92400e;font-size:12.5px;line-height:1.6;}
  .foot{margin-top:22px;font-size:11.5px;color:#86868b;line-height:1.6;}
  @media print{
    body{background:#fff;padding:0;}
    .no-print{display:none!important;}
    .poster{border:2px solid #1d1d1f;border-radius:12px;max-width:100%;padding:32px 24px;}
  }
</style>
</head>
<body>
<div class="toolbar no-print">
  <div class="picker">
    <a href="<?= base_url('attendance/sites') ?>">← Sites &amp; policy</a>
    <?php foreach ($sites as $s): ?>
      <a href="<?= base_url('attendance/print_qr?site_id=' . (int)$s->id) ?>"
         class="<?= ($site && (int)$site->id === (int)$s->id) ? 'on' : '' ?>"><?= $h($s->name) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($site && !empty($static_enabled)): ?>
    <button type="button" class="btn btn-primary" onclick="window.print()">Print Gate Poster</button>
  <?php endif; ?>
</div>

<?php if (!$site): ?>
  <div class="poster">
    <h1>No Active Attendance Site</h1>
    <p class="fence" style="margin-top:10px;">Configure an active site first on <a href="<?= base_url('attendance/sites') ?>">Attendance Sites</a>.</p>
  </div>
<?php else: ?>
  <?php if (empty($static_enabled)): ?>
    <div class="warn no-print">
      <strong>Printed QR fallback is currently disabled for <?= $h($site->name) ?>.</strong><br>
      Per <code>ATTENDANCE_WORKFLOW.md</code>, a rotating screen code (<a href="<?= base_url('attendance/board?site_id=' . (int)$site->id) ?>">Site Screen</a>) is recommended where a device is available at the gate because a fixed printed QR can be photographed. Enable printed QR fallback below if this worksite has no gate screen — live GPS geofence &amp; accuracy checks still apply on every punch.
      <div style="margin-top:10px;">
        <?= form_open('attendance/sites', array('style' => 'display:inline;'), array('action' => 'enable_static_qr', 'id' => (int)$site->id)) ?>
          <button type="submit" class="btn btn-primary">Enable Printed QR for <?= $h($site->name) ?></button>
        <?= form_close() ?>
      </div>
    </div>
  <?php else: ?>
    <div class="warn no-print">
      <strong>Security note:</strong> Fixed printed QR codes remain active until you uncheck <em>Allow printed / fixed QR fallback</em> or click <strong>Rotate code</strong> on <a href="<?= base_url('attendance/sites') ?>">Attendance Sites</a>. Every scan is still verified server-side against this site's live GPS radius (<strong><?= (int)$site->radius_m ?> m</strong>) and accuracy limit (<strong>&le; <?= (int)$site->max_accuracy_m ?> m</strong>).
    </div>
  <?php endif; ?>

  <div class="poster">
    <div class="org"><?= $h($company) ?> · Site Attendance Check-In / Check-Out</div>
    <h1><?= $h($site->name) ?></h1>
    <div class="fence">
      GPS Geofence Radius: <strong><?= (int)$site->radius_m ?> m</strong> · Required GPS Accuracy: <strong>&le; <?= (int)$site->max_accuracy_m ?> m</strong>
    </div>

    <div id="qr"></div>
    <div class="code"><?= $h($token) ?></div>

    <ol class="steps">
      <li>Sign in to <strong><?= $h($company) ?></strong> on your phone browser.</li>
      <li>Scan this QR code with your phone camera while standing inside the site gate.</li>
      <li>Allow single-use GPS location access when prompted and tap <strong>Confirm check-in</strong>.</li>
      <li>Scan this same QR code again before leaving the site to record your <strong>Check-out</strong>.</li>
    </ol>

    <div class="foot">
      Authoritative server timestamp · Coordinates checked at <?= number_format((float)$site->latitude, 6) ?>, <?= number_format((float)$site->longitude, 6) ?> · Printed <?= date('d M Y') ?>
    </div>
  </div>

  <script src="<?= base_url('assets/vendor/qrcode/qrcode.js') ?>"></script>
  <script>
  (function () {
    var box = document.getElementById('qr');
    try {
      var qr = qrcode(0, 'M');
      qr.addData(<?= json_encode($url) ?>);
      qr.make();
      box.innerHTML = qr.createSvgTag(8, 4, 'Site gate check-in QR code');
    } catch (e) {
      box.textContent = 'QR SVG could not render — use code below.';
    }
  })();
  </script>
<?php endif; ?>
</body>
</html>
