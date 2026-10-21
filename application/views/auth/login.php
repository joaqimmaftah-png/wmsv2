<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — Workshop Intelligence</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --blue:#0071e3;--red:#c0392b;
  --t1:#1d1d1f;--t2:#6e6e73;--t3:#86868b;--t4:#aeaeb2;
  --sep:rgba(0,0,0,0.08);
}
html,body{
  height:100%;
  background:#f5f5f7;
  color:var(--t1);
  font-family:'Inter',-apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",sans-serif;
  -webkit-font-smoothing:antialiased;
  overflow:hidden;
}
.stage{height:100vh;display:flex;align-items:center;justify-content:center;}

/* Card — Apple product page inspired: pure white, generous radius, clean shadow */
.login-card{
  width:380px;
  background:#ffffff;
  border:1px solid var(--sep);
  border-radius:22px;
  padding:48px 44px 44px;
  box-shadow:0 2px 8px rgba(0,0,0,0.06),0 16px 40px rgba(0,0,0,0.08);
  animation:rise .4s cubic-bezier(0.16,1,.3,1) both;
}
@keyframes rise{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);}}

/* Logo + brand */
.brand{text-align:center;margin-bottom:36px;}
.brand-logo{display:block;margin:0 auto 16px;max-width:160px;height:auto;}
.brand-name{font-size:19px;font-weight:700;color:var(--t1);letter-spacing:-0.4px;}
.brand-sub{font-size:12px;color:var(--t3);margin-top:4px;}

/* Error */
.err-box{
  background:rgba(192,57,43,0.07);border:1px solid rgba(192,57,43,0.15);
  border-radius:10px;padding:10px 14px;
  font-size:13px;color:var(--red);margin-bottom:20px;
  display:flex;align-items:center;gap:9px;
}
.err-box svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;flex-shrink:0;}

/* Form */
.field{margin-bottom:16px;}
.field label{display:block;font-size:12px;font-weight:500;color:var(--t2);margin-bottom:6px;}
.field input{
  width:100%;background:#ffffff;
  border:1px solid rgba(0,0,0,0.15);border-radius:10px;
  padding:11px 14px;color:var(--t1);
  font-family:inherit;font-size:14px;outline:none;
  transition:border-color .15s,box-shadow .15s;
}
.field input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,113,227,0.12);}
.field input::placeholder{color:var(--t4);}

.btn-login{
  width:100%;background:var(--blue);color:#fff;border:none;
  padding:12px;border-radius:100px;
  font-family:inherit;font-size:14px;font-weight:500;
  cursor:pointer;margin-top:10px;
  transition:background 0.15s,transform 0.15s;letter-spacing:0;
}
.btn-login:hover{background:#0077ed;transform:scale(1.02);}
.btn-login:active{transform:scale(0.99);}
</style>
</head>
<body>
<div class="stage">
  <div class="login-card">
    <div class="brand">
      <img src="<?= base_url('uploads/logoam.png') ?>" alt="A&M Logo" class="brand-logo">
      <div class="brand-name">Workshop Intelligence</div>
      <div class="brand-sub">Heavy Equipment Management System</div>
    </div>

    <?php
    /*
     * The controller passes `$error` for anything that went wrong on this
     * request; the session copy is kept for a redirect back to this screen.
     * Both are escaped: the message must never be able to carry markup, and
     * `$error` is the one place a sign-in failure — including "the database is
     * not answering" — actually reaches the person trying to sign in.
     */
    $login_error = isset($error) && $error !== '' ? $error : $this->session->flashdata('error');
    ?>
    <?php if ($login_error): ?>
    <div class="err-box">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars((string)$login_error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?= form_open('login') ?>
    <?php if (!empty($next)): ?>
      <input type="hidden" name="next" value="<?= htmlspecialchars((string)$next, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <div class="field">
      <label>Email address</label>
      <input type="email" name="email" required autofocus placeholder="you@workshop.com" value="<?= set_value('email') ?>">
    </div>
    <div class="field">
      <label>Password</label>
      <input type="password" name="password" required placeholder="••••••••">
    </div>
    <button type="submit" class="btn-login">Sign In →</button>
    <?= form_close() ?>
  </div>
</div>
</body>
</html>
