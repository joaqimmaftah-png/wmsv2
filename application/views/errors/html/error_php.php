<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Application Error — WMS</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#f5f5f7;font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;color:#1d1d1f;}
.err{background:rgba(192,57,43,0.1);border:1px solid rgba(192,57,43,0.3);border-radius:16px;padding:40px 48px;max-width:600px;text-align:center;}
.err-icon{font-size:48px;margin-bottom:20px;}
.err-title{font-size:22px;font-weight:700;color:var(--red);margin-bottom:12px;}
.err-msg{font-size:14px;color:rgba(0,0,0,0.6);line-height:1.6;margin-bottom:20px;}
.err-detail{background:rgba(0,0,0,0.3);border-radius:8px;padding:16px;text-align:left;font-family:'Courier New',monospace;font-size:12px;color:rgba(0,0,0,0.5);word-break:break-all;}
.err-back{display:inline-block;margin-top:20px;background:var(--blue);color:#1d1d1f;padding:10px 24px;border-radius:100px;text-decoration:none;font-weight:600;font-size:13px;}
</style>
</head>
<body>
<div class="err">
  <div class="err-icon">⚠️</div>
  <div class="err-title">PHP Error</div>
  <div class="err-msg">A PHP error was encountered in the WMS application.</div>
  <div class="err-detail">
    <strong style="color:var(--amber);">Severity:</strong> <?= $severity; ?><br>
    <strong style="color:var(--amber);">Message:</strong>  <?= $message; ?><br>
    <strong style="color:var(--amber);">File:</strong>     <?= $filepath; ?><br>
    <strong style="color:var(--amber);">Line:</strong>     <?= $line; ?>
  </div>
  <a href="javascript:history.back()" class="err-back">← Go Back</a>
</div>
</body>
</html>
