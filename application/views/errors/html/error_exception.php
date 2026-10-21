<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>An Uncaught Exception was Encountered</title>
<style>
body { background:#fff; margin:40px; font:13px/20px normal Helvetica,Arial,sans-serif; color:#333; }
.box { border:1px solid #d0d7de; border-radius:8px; padding:20px 24px; max-width:960px; margin:0 auto; }
h1 { color:#b91c1c; font-size:18px; margin:0 0 12px; }
p { margin:6px 0; }
.trace { margin-top:14px; padding:12px; background:#f6f8fa; border-radius:6px; font-family:monospace; font-size:12px; }
</style>
</head>
<body>
<div class="box">
  <h1>An uncaught Exception was encountered</h1>
  <p><strong>Type:</strong> <?= htmlspecialchars((string)(get_class($exception)), ENT_QUOTES, 'UTF-8'); ?></p>
  <p><strong>Message:</strong> <?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8'); ?></p>
  <p><strong>Filename:</strong> <?= htmlspecialchars((string)($exception->getFile()), ENT_QUOTES, 'UTF-8'); ?> (Line <?= (int)$exception->getLine(); ?>)</p>
  <?php if (defined('SHOW_DEBUG_BACKTRACE') && SHOW_DEBUG_BACKTRACE === TRUE): ?>
  <div class="trace">
    <?php foreach ($exception->getTrace() as $error): ?>
      <?php if (isset($error['file']) && strpos($error['file'], realpath(BASEPATH)) !== 0): ?>
        <div><?= htmlspecialchars((string)($error['file']), ENT_QUOTES, 'UTF-8'); ?>:<?= (int)$error['line']; ?> — <?= htmlspecialchars((string)($error['function']), ENT_QUOTES, 'UTF-8'); ?>()</div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
