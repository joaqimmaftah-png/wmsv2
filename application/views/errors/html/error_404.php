<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>404 — WMS</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#0a0a14;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;color:#1d1d1f;}
.err{text-align:center;max-width:440px;padding:48px 32px;}
.err-num{font-size:96px;font-weight:800;color:var(--blue);letter-spacing:-6px;line-height:1;font-variant-numeric:tabular-nums;}
.err-line{width:40px;height:3px;background:var(--blue);border-radius:2px;margin:20px auto;}
.err-title{font-size:19px;font-weight:600;color:rgba(0,0,0,0.9);margin-bottom:10px;}
.err-msg{font-size:13px;color:rgba(0,0,0,0.38);line-height:1.65;margin-bottom:28px;}
.err-back{display:inline-block;background:var(--blue);color:#1d1d1f;padding:11px 26px;border-radius:100px;text-decoration:none;font-weight:600;font-size:13px;letter-spacing:.1px;}
.err-back:hover{background:#2b94ff;}
</style>
</head>
<body>
<div class="err">
  <div class="err-num">404</div>
  <div class="err-line"></div>
  <div class="err-title">Page Not Found</div>
  <div class="err-msg">The page you requested does not exist.</div>
  <?php
  /*
   * Absolute, but derived from the installation rather than written down:
   * the hard-coded `/wms/dashboard` this replaced was a development path, so on
   * the live site every 404 offered a link to a page that does not exist.
   */
  $home = function_exists('base_url') ? base_url('dashboard') : '/';
  ?>
  <a href="<?= htmlspecialchars($home, ENT_QUOTES, 'UTF-8') ?>" class="err-back">← Back to Dashboard</a>
</div>
</body>
</html>
