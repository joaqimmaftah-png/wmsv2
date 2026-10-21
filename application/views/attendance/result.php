<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * The answer to a punch attempt (ATTENDANCE_WORKFLOW.md → "Failure handling").
 *
 * A successful punch explains what was recorded and where; a rejected one
 * explains what to do next and states plainly that nothing was recorded. The
 * page keeps the technician's day visible so the result can be checked without
 * walking back to another screen.
 *
 * @var bool        $ok
 * @var string      $message
 * @var string      $warning
 * @var string      $punch
 * @var object|null $site
 * @var array       $today
 * @var bool        $on_site
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
<title><?= $h($ok ? 'Punch recorded' : 'Punch not recorded') ?> — <?= $h($company) ?></title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
  :root{--blue:#0071e3;--green:#1c8c3c;--amber:#b45309;--red:#c0392b;
        --t1:#1d1d1f;--t2:#6e6e73;--t3:#86868b;--sep:rgba(0,0,0,0.09);--bg:#f5f5f7;}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,system-ui,sans-serif;
       background:var(--bg);color:var(--t1);min-height:100vh;padding:18px 16px 34px;}
  .wrap{max-width:460px;margin:0 auto;}
  .brand{font-size:12px;font-weight:600;color:var(--t2);margin-bottom:18px;}
  .brand b{color:var(--t1);font-size:14px;}
  .card{background:#fff;border:1px solid var(--sep);border-radius:14px;padding:20px;margin-bottom:14px;}
  .mark{width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;
        font-size:24px;font-weight:700;margin-bottom:14px;}
  .mark.ok{background:rgba(28,140,60,0.1);color:var(--green);}
  .mark.bad{background:rgba(192,57,43,0.09);color:var(--red);}
  h1{font-size:20px;font-weight:700;letter-spacing:-.02em;margin-bottom:8px;}
  p{font-size:13.5px;line-height:1.6;color:var(--t2);}
  .note{margin-top:14px;padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55;
        background:rgba(180,83,9,0.08);color:var(--amber);}
  .state{margin-top:16px;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--t2);}
  .dot{width:8px;height:8px;border-radius:50%;background:var(--red);}
  .dot.ok{background:var(--green);}
  table{width:100%;border-collapse:collapse;margin-top:10px;}
  th{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--t3);text-align:left;padding:6px 0;}
  td{font-size:12.5px;padding:7px 0;border-top:1px solid var(--sep);}
  td.r{text-align:right;color:var(--t3);}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;}
  .pill.in{background:rgba(28,140,60,0.1);color:var(--green);}
  .pill.out{background:rgba(180,83,9,0.1);color:var(--amber);}
  .actions{display:flex;gap:10px;margin-top:4px;}
  .btn{flex:1;text-align:center;text-decoration:none;padding:14px;border-radius:12px;
       font:600 14px/1 inherit;background:var(--blue);color:#fff;}
  .btn.ghost{background:#fff;color:var(--t1);border:1px solid var(--sep);}
  a{color:var(--blue);text-decoration:none;}
  .tiny{font-size:11px;color:var(--t3);margin-top:10px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><b><?= $h($company) ?></b> · Attendance</div>

  <div class="card">
    <div class="mark <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '!' ?></div>
    <h1><?= $ok ? 'Punch recorded' : 'Punch not recorded' ?></h1>
    <p><?= $h($message) ?></p>

    <?php if (!empty($warning)): ?>
      <div class="note"><?= $h($warning) ?></div>
    <?php endif; ?>

    <div class="state">
      <span class="dot <?= !empty($on_site) ? 'ok' : '' ?>"></span>
      <span><?= !empty($on_site) ? 'You are on site' : 'You are not on site' ?></span>
    </div>
  </div>

  <?php if (!empty($today)): ?>
  <div class="card">
    <p style="font-size:13px;font-weight:600;color:var(--t1);">Today</p>
    <table>
      <thead><tr><th>Punch</th><th>Time</th><th style="text-align:right;">Source</th></tr></thead>
      <tbody>
      <?php foreach ($today as $p): ?>
        <tr>
          <td><span class="pill <?= $p->punch_type === 'check_in' ? 'in' : 'out' ?>">
            <?= $p->punch_type === 'check_in' ? 'IN' : 'OUT' ?></span></td>
          <td><?= date('H:i', strtotime((string)$p->punched_at)) ?></td>
          <td class="r"><?= $p->origin === 'supervisor' ? 'Supervisor' : 'Phone' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="actions">
    <a class="btn ghost" href="<?= base_url('attendance') ?>">Attendance home</a>
    <a class="btn" href="<?= base_url('attendance/punch') ?>">Scan another code</a>
  </div>
  <p class="tiny">Times come from the server. Punches are recorded, never edited — a supervisor correction is
    added on top and keeps the original.</p>
</div>
</body>
</html>
