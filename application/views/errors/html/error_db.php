<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Database Error — WMS</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;color:#1d1d1f;padding:20px;}
.err{background:#fff;border:1px solid #e5e5e7;border-radius:16px;padding:40px;max-width:700px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,0.06);}
.err-icon{font-size:48px;margin-bottom:20px;text-align:center;}
.err-title{font-size:22px;font-weight:700;color:#c0392b;margin-bottom:12px;text-align:center;}
.err-msg{font-size:14px;color:#6e6e73;line-height:1.6;margin-bottom:20px;text-align:center;}
.err-detail{background:#f5f5f7;border:1px solid #e5e5e7;border-radius:8px;padding:16px;text-align:left;font-family:'Courier New',monospace;font-size:12px;color:#333;word-break:break-all;margin-bottom:20px;max-height:200px;overflow:auto;}
.err-steps{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:16px;margin-bottom:20px;text-align:left;}
.err-steps h4{font-size:13px;font-weight:700;margin-bottom:8px;color:#92400e;}
.err-steps ol{font-size:12px;color:#78350f;padding-left:18px;line-height:1.7;}
.err-steps code{background:#fef3c7;padding:2px 6px;border-radius:4px;font-size:11px;}
.err-back{display:inline-block;background:#007aff;color:#fff;padding:10px 24px;border-radius:100px;text-decoration:none;font-weight:600;font-size:13px;text-align:center;}
.err-actions{text-align:center;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}
</style>
</head>
<body>
<div class="err">
  <div class="err-icon">🗄️</div>
  <div class="err-title">Database Connection Failed</div>
  <div class="err-msg">Unable to connect to the database. This is the #1 cause of HTTP 500 on cPanel.</div>
  <div class="err-detail"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>

  <div class="err-steps">
    <h4>🔧 How to fix on cPanel (2 min):</h4>
    <ol>
      <li>Check <code>application/config/database.local.php</code> exists - copy from <code>database.local.php.example</code> if missing</li>
      <li>Edit it with your cPanel credentials:
        <br><code>hostname = localhost</code>
        <br><code>username = cpaneluser_dbuser</code>
        <br><code>database = cpaneluser_dbname</code>
        <br><code>password = your password</code>
      </li>
      <li>In cPanel > MySQL Databases, ensure you <strong>added user to database</strong> with ALL PRIVILEGES</li>
      <li>Import <code>database/workshop_full_import.sql</code> via phpMyAdmin if tables missing</li>
      <li>Upload <code>cpanel_check.php</code> and visit it for full diagnosis</li>
    </ol>
  </div>

  <div class="err-actions">
    <a href="javascript:history.back()" class="err-back">← Go Back</a>
    <a href="<?= htmlspecialchars(base_url('cpanel_check.php'), ENT_QUOTES, 'UTF-8'); ?>" class="err-back" style="background:#34c759">Run Diagnostic</a>
  </div>
  <p style="font-size:11px;color:#86868b;margin-top:16px;text-align:center">Check cPanel > Errors for full log. Delete cpanel_check.php after use.</p>
</div>
</body>
</html>
