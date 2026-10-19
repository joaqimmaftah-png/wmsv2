<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="<?= isset($lang) && $lang === 'fr' ? 'fr' : 'en'; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? htmlspecialchars($page_title).' — ' : '' ?><?= isset($site_settings['company_name']) ? htmlspecialchars($site_settings['company_name']) : 'Workshop WMS' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Syne:wght@600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
/* ═══════════════════════════════════════════════════
   WMS — Apple Design Edition
   Fonts: Inter (UI) · Syne (headings) · JetBrains Mono (data)
   Same CSS variable names as Original — all views compatible
   ═══════════════════════════════════════════════════ */
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }

:root {
  /* ── Surfaces (slightly cooler/deeper than Original) ── */
  --void:    #050508;
  --base:    #09090f;
  --surface: #0f0f18;
  --raised:  #141420;
  --elevated:#1a1a2a;

  /* ── Glass layers ── */
  --g1: rgba(255,255,255,0.038);
  --g2: rgba(255,255,255,0.068);
  --g3: rgba(255,255,255,0.105);

  /* ── Borders ── */
  --border:   rgba(255,255,255,0.075);
  --border-b: rgba(255,255,255,0.14);

  /* ── Accent palette (same hues as Original, slightly richer) ── */
  --blue:   #0A84FF;
  --green:  #30D158;
  --amber:  #FF9F0A;
  --red:    #FF453A;
  --purple: #BF5AF2;
  --cyan:   #32ADE6;
  --teal:   #5AC8FA;

  /* ── Tinted backgrounds ── */
  --blue-d:   rgba(0,113,227,0.12);
  --green-d:  rgba(28,140,60,0.12);
  --amber-d:  rgba(180,83,9,0.12);
  --red-d:    rgba(192,57,43,0.12);
  --purple-d: rgba(109,63,192,0.12);

  /* ── Text ── */
  --t1: rgba(255,255,255,0.94);
  --t2: rgba(255,255,255,0.58);
  --t3: rgba(255,255,255,0.30);
  --t4: rgba(255,255,255,0.13);

  /* ── Radii (Apple's tighter, more refined corners) ── */
  --r1: 9px;
  --r2: 14px;
  --r3: 20px;
  --r4: 100px;

  /* ── Sidebar ── */
  --sb:    68px;
  --sb-x:  228px;
  --sb-dur:0.24s;

  /* ── Apple-exclusive tokens ── */
  --ui-font: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  --code-font:'JetBrains Mono', 'Menlo', monospace;
  --head-font:'Syne', sans-serif;
}

html, body {
  height: 100%;
  background: var(--bg);
  color: var(--t1);
  font-family: var(--ui-font);
  font-size: 14px;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  overflow: hidden;
}

/* Override font usage in specific classes */
.kpi-num, .mono { font-family: var(--code-font) !important; }
.btn-cta, .btn, .form-control, .form-select { font-family: var(--ui-font) !important; }
.ct, .page-heading, .ct, .kpi-tag, .nav-section-label,
.tbl th, .dt th, .table.wms-table th, .card-head { font-family: var(--head-font) !important; }

/* ── Ambient orbs (subtler, blue-only) ── */
.orb { position:fixed; border-radius:50%; filter:blur(130px); pointer-events:none; z-index:0; }
.orb-1 {
  width: 900px; height: 900px; top: -450px; left: -50px;
  background: radial-gradient(circle, rgba(0,113,227,0.055) 0%, transparent 65%);
  animation: orb-f 22s ease-in-out infinite alternate;
}
.orb-2 {
  width: 600px; height: 600px; bottom: -200px; right: 80px;
  background: radial-gradient(circle, rgba(0,113,227,0.03) 0%, transparent 65%);
  animation: orb-f 28s ease-in-out infinite alternate-reverse;
}
@keyframes orb-f { 0%{transform:translate(0,0)} 100%{transform:translate(20px,14px) scale(1.05)} }

/* ══════════════════════════════════
   SIDEBAR — Apple HIG rail style
   ══════════════════════════════════ */
#sidebar {
  position: fixed; top:0; left:0; bottom:0;
  width: var(--sb);
  background: rgba(5,5,8,0.96);
  backdrop-filter: saturate(200%) blur(36px);
  -webkit-backdrop-filter: saturate(200%) blur(36px);
  border-right: 1px solid var(--sep);
  display: flex; flex-direction: column; align-items: flex-start;
  padding: 16px 0 14px;
  z-index: 200;
  transition: width var(--sb-dur) cubic-bezier(0.4,0,0.2,1);
  overflow: hidden;
}
#sidebar.expanded { width: var(--sb-x); }

/* Logo */
.sb-logo {
  display:flex; align-items:center; gap:10px;
  padding:0 13px; margin-bottom:22px;
  flex-shrink:0; width:100%; text-decoration:none;
}
.sb-logo-mark {
  width:38px; height:38px; min-width:38px;
  border-radius:11px;
  background: linear-gradient(150deg, #1870ff 0%, #0047cc 100%);
  box-shadow: 0 4px 16px rgba(0,113,227,0.35), inset 0 1px 0 rgba(255,255,255,0.16);
  display:flex; align-items:center; justify-content:center;
  color:#fff; font-family:var(--head-font); font-weight:800; font-size:12px;
  flex-shrink:0; letter-spacing:-0.5px;
}
.sb-logo-text {
  opacity:0;
  transition: opacity calc(var(--sb-dur) * 0.6) ease;
  white-space:nowrap; overflow:hidden;
}
#sidebar.expanded .sb-logo-text { opacity:1; }
.sb-logo-name { font-family:var(--head-font); font-weight:800; font-size:13px; letter-spacing:-0.3px; color:var(--t1); line-height:1.15; }
.sb-logo-sub  { font-size:9px; color:var(--t3); letter-spacing:.7px; text-transform:uppercase; margin-top:2px; }

/* Nav area */
.nav-wrap {
  flex:1; display:flex; flex-direction:column; gap:1px;
  width:100%; padding:0 9px;
  overflow-y:auto; overflow-x:hidden; scrollbar-width:none;
}
.nav-wrap::-webkit-scrollbar { display:none; }
.nav-sep { width:100%; height:1px; background:var(--sep); margin:4px 0; flex-shrink:0; }
.nav-section-label {
  font-family: var(--head-font);
  font-size:8px; font-weight:700; letter-spacing:1.2px;
  text-transform:uppercase; color:var(--t4); padding:0 11px;
  white-space:nowrap; overflow:hidden;
  opacity:0; height:0; margin:0;
  transition: opacity calc(var(--sb-dur)*0.5) ease, height .2s ease, margin .2s ease;
}
#sidebar.expanded .nav-section-label { opacity:1; height:18px; margin:5px 0 1px; }

/* Nav items */
.ni {
  position:relative;
  display:flex; align-items:center; gap:10px;
  min-height:40px;
  border-radius:10px; padding:0 10px;
  color:var(--t3);
  cursor:pointer; border:1px solid transparent;
  transition: color .15s, background .15s, border-color .15s;
  text-decoration:none; flex-shrink:0; width:100%;
}
.ni:hover { color:var(--t1); background:var(--g2); border-color:var(--sep); }
.ni.on    { color:var(--blue); background:var(--blue-d); border-color:rgba(0,113,227,0.22); }

.ni-icon { width:22px; height:22px; min-width:22px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.ni svg  { width:17px; height:17px; stroke:currentColor; fill:none; stroke-width:1.75; stroke-linecap:round; stroke-linejoin:round; pointer-events:none; }
.ni-label {
  font-size:12.5px; font-weight:500;
  white-space:nowrap; overflow:hidden;
  opacity:0; transition:opacity calc(var(--sb-dur)*0.5) ease;
  letter-spacing:-0.1px; flex:1;
  font-family: var(--ui-font);
}
#sidebar.expanded .ni-label { opacity:1; }

/* Tooltip */
.ni-tip {
  position:absolute; left:calc(var(--sb) + 7px);
  top:50%; transform:translateY(-50%) translateX(-4px);
  background:var(--surface); border:1px solid var(--sep-strong);
  color:var(--t1); padding:5px 11px;
  border-radius:8px; font-size:11.5px; font-weight:500;
  white-space:nowrap; pointer-events:none; z-index:500;
  opacity:0; box-shadow:0 8px 28px rgba(0,0,0,.55);
  transition: opacity .12s, transform .12s;
  font-family: var(--ui-font);
}
.ni-tip::before { content:''; position:absolute; right:100%; top:50%; transform:translateY(-50%); border:5px solid transparent; border-right-color:var(--sep-strong); }
.ni-tip::after  { content:''; position:absolute; right:calc(100% - 1px); top:50%; transform:translateY(-50%); border:5px solid transparent; border-right-color:var(--surface); }
#sidebar:not(.expanded) .ni:hover .ni-tip { opacity:1; transform:translateY(-50%) translateX(0); }

/* User card */
.sb-user { flex-shrink:0; width:100%; padding:0 9px; margin-top:4px; }
.sb-user-card {
  display:flex; align-items:center; gap:9px; padding:8px 10px;
  border-radius:10px; background:var(--g1); border:1px solid var(--sep);
  cursor:pointer; text-decoration:none;
  transition: background .15s, border-color .15s; width:100%; overflow:hidden;
}
.sb-user-card:hover { background:var(--g2); border-color:var(--sep-strong); }
.sb-avatar {
  width:30px; height:30px; min-width:30px; border-radius:50%;
  background:linear-gradient(145deg, #5e5ce6, var(--blue));
  display:flex; align-items:center; justify-content:center;
  font-family:var(--head-font); font-size:10px; font-weight:700; color:#fff;
  border:1.5px solid rgba(255,255,255,0.11); flex-shrink:0;
}
.sb-user-info { opacity:0; transition:opacity calc(var(--sb-dur)*0.5) ease; flex:1; min-width:0; overflow:hidden; }
#sidebar.expanded .sb-user-info { opacity:1; }
.sb-user-name { font-size:11.5px; font-weight:600; color:var(--t1); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-family:var(--ui-font); }
.sb-user-role { font-size:9px; color:var(--t3); margin-top:1px; }
.sb-logout { opacity:0; transition:opacity calc(var(--sb-dur)*0.5) ease; color:var(--t3); flex-shrink:0; }
#sidebar.expanded .sb-logout { opacity:1; }
.sb-logout svg { width:13px; height:13px; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }

/* Pin button */
.sb-pin {
  position:absolute; top:14px; right:-11px;
  width:20px; height:20px; border-radius:50%;
  background:var(--surface); border:1px solid var(--sep-strong);
  display:flex; align-items:center; justify-content:center;
  cursor:pointer; color:var(--t3);
  opacity:0; transition:opacity .14s, color .14s; z-index:300;
}
#sidebar:hover .sb-pin { opacity:1; }
.sb-pin:hover { color:var(--blue); }
.sb-pin svg { width:9px; height:9px; stroke:currentColor; fill:none; stroke-width:2.5; stroke-linecap:round; }

/* ══════════════════════════════════
   CANVAS / TOPBAR
   ══════════════════════════════════ */
#canvas {
  margin-left:var(--sb); height:100vh;
  overflow-y:auto; position:relative; z-index:1;
  transition:margin-left var(--sb-dur) cubic-bezier(0.4,0,0.2,1);
}
#canvas.expanded { margin-left:var(--sb-x); }
#canvas::-webkit-scrollbar { width:2px; }
#canvas::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.07); border-radius:2px; }

.topbar {
  position:sticky; top:0; z-index:50;
  padding:16px 26px 0;
  background:linear-gradient(to bottom, var(--bg) 52%, transparent);
}
.topbar-row {
  display:flex; align-items:center; justify-content:space-between;
  padding-bottom:16px;
  border-bottom:1px solid var(--sep);
}
.topbar-greeting { font-size:11px; color:var(--t3); font-weight:500; margin-bottom:3px; letter-spacing:.05px; }
.topbar-greeting strong { color:var(--blue); font-weight:700; }
.page-heading { font-family:var(--head-font); font-size:18px; font-weight:700; letter-spacing:-0.5px; color:var(--t1); }
.topbar-actions { display:flex; align-items:center; gap:9px; }

/* Clock pill */
.live-pill {
  display:inline-flex; align-items:center; gap:7px;
  background:var(--g1); border:1px solid var(--sep);
  padding:6px 14px; border-radius:var(--r4);
  font-size:12px; font-weight:500; color:var(--t2);
  font-family:var(--ui-font);
}
.sonar { width:6px; height:6px; border-radius:50%; background:var(--green); box-shadow:0 0 0 0 rgba(28,140,60,0.5); animation:sonar 2.5s ease-out infinite; }
@keyframes sonar { 0%{box-shadow:0 0 0 0 rgba(28,140,60,0.5)} 70%{box-shadow:0 0 0 7px rgba(28,140,60,0)} 100%{box-shadow:0 0 0 0 rgba(28,140,60,0)} }

/* Topbar user pill */
.tb-user {
  display:inline-flex; align-items:center; gap:8px;
  background:var(--g1); border:1px solid var(--sep);
  padding:5px 13px 5px 5px; border-radius:var(--r4);
  cursor:pointer; position:relative;
  transition:background .15s, border-color .15s;
}
.tb-user:hover { background:var(--g2); border-color:var(--sep-strong); }
.tb-avatar {
  width:27px; height:27px; min-width:27px; border-radius:50%;
  background:linear-gradient(145deg, #5e5ce6, var(--blue));
  display:flex; align-items:center; justify-content:center;
  font-family:var(--head-font); font-size:9.5px; font-weight:700; color:#fff;
}
.tb-name { font-size:12px; font-weight:600; color:var(--t1); letter-spacing:-.15px; font-family:var(--ui-font); }
.tb-role { font-size:9px; color:var(--t3); }
.tb-chevron svg { width:10px; height:10px; stroke:var(--t3); fill:none; stroke-width:2.5; stroke-linecap:round; stroke-linejoin:round; }

.tb-dropdown {
  position:absolute; top:calc(100% + 9px); right:0;
  background:rgba(14,14,22,0.98);
  backdrop-filter:saturate(200%) blur(28px);
  -webkit-backdrop-filter:saturate(200%) blur(28px);
  border:1px solid var(--sep-strong); border-radius:16px;
  min-width:208px; overflow:hidden;
  box-shadow:0 24px 60px rgba(0,0,0,.70);
  display:none; z-index:400;
  animation:pop-in .15s cubic-bezier(0.34,1.56,0.64,1);
}
@keyframes pop-in { from{opacity:0;transform:scale(.93) translateY(-5px)} to{opacity:1;transform:scale(1) translateY(0)} }
.tb-dropdown.open { display:block; }
.tbd-head { padding:13px 15px 11px; border-bottom:1px solid var(--sep); }
.tbd-name  { font-size:13px; font-weight:700; color:var(--t1); font-family:var(--head-font); }
.tbd-email { font-size:9.5px; color:var(--t3); margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.tbd-item  {
  display:flex; align-items:center; gap:9px; padding:10px 15px;
  font-size:12.5px; font-weight:500; color:var(--t2);
  text-decoration:none; cursor:pointer; font-family:var(--ui-font);
  transition:background .14s, color .14s;
}
.tbd-item:hover { background:var(--g2); color:var(--t1); }
.tbd-item svg { width:13px; height:13px; stroke:currentColor; fill:none; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0; }
.tbd-item.danger { color:var(--red); }
.tbd-item.danger:hover { background:var(--red-d); }
.tbd-sep { height:1px; background:var(--sep); }

/* CTA button */
.btn-cta {
  display:inline-flex; align-items:center; gap:7px;
  background:var(--blue); color:#fff; border:none;
  padding:9px 18px; border-radius:var(--r4);
  font-family:var(--ui-font); font-size:13px; font-weight:600;
  cursor:pointer; letter-spacing:-.1px;
  box-shadow:0 4px 18px rgba(0,113,227,0.3);
  transition:all .2s cubic-bezier(0.34,1.56,0.64,1); text-decoration:none;
}
.btn-cta:hover { background:#2b94ff; transform:scale(1.04) translateY(-1px); box-shadow:0 8px 28px rgba(0,113,227,0.42); color:#fff; }
.btn-cta svg { width:13px; height:13px; fill:currentColor; }

/* ══════════════════════════════════
   FLASH MESSAGES
   ══════════════════════════════════ */
.flash { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:var(--r2); font-size:13px; font-weight:500; margin-bottom:16px; }
.flash-ok  { background:var(--green-d); border:1px solid rgba(28,140,60,0.2); color:var(--green); }
.flash-err { background:var(--red-d);   border:1px solid rgba(192,57,43,0.2); color:var(--red); }
.flash svg { width:16px; height:16px; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0; }

.page { padding:20px 26px 52px; }

/* ══════════════════════════════════
   GLASS CARD
   ══════════════════════════════════ */
.card {
  background:var(--g1);
  border:1px solid var(--sep);
  border-radius:var(--r3);
  overflow:hidden; position:relative;
  animation:rise .45s cubic-bezier(0.16,1,.3,1) both;
}
.card::after {
  content:''; position:absolute;
  top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg, transparent, rgba(255,255,255,0.085), transparent);
  pointer-events:none; z-index:2;
}
.ch { display:flex; align-items:flex-start; justify-content:space-between; padding:18px 20px 0; }
.ct { font-family:var(--head-font); font-size:13px; font-weight:700; color:var(--t1); letter-spacing:-0.1px; }
.cs { font-size:11px; color:var(--t3); margin-top:3px; }
.ca { font-size:11.5px; color:var(--blue); font-weight:600; opacity:.8; transition:opacity .14s; text-decoration:none; }
.ca:hover { opacity:1; }

/* ══════════════════════════════════
   KPI TILES
   ══════════════════════════════════ */
.kpi {
  background:var(--g1); border:1px solid var(--sep);
  border-radius:var(--r2); padding:16px 17px;
  position:relative; overflow:hidden;
  transition:background .18s, border-color .18s, transform .28s cubic-bezier(0.34,1.56,0.64,1), box-shadow .28s;
  animation:rise .5s cubic-bezier(0.16,1,.3,1) both;
}
.kpi::after {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg, transparent, rgba(255,255,255,0.10), transparent);
}
.kpi:hover { background:var(--g2); border-color:var(--sep-strong); transform:translateY(-3px); box-shadow:0 16px 40px rgba(0,0,0,.32); }
.kpi-glow { position:absolute; bottom:-32px; right:-32px; width:88px; height:88px; border-radius:50%; opacity:.10; pointer-events:none; }
.kpi-tag  { font-family:var(--head-font); font-size:9px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:var(--t3); margin-bottom:10px; display:block; }
.kpi-num  { font-family:var(--code-font); font-size:24px; font-weight:500; letter-spacing:-1px; color:var(--t1); line-height:1; display:flex; align-items:baseline; gap:3px; }
.kpi-num sup { font-size:11px; color:var(--t2); font-weight:400; letter-spacing:0; }
.kpi-chip { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:600; margin-top:9px; padding:3px 9px; border-radius:var(--r4); letter-spacing:.1px; }
.chip-up   { background:var(--green-d); color:var(--green); }
.chip-dn   { background:var(--red-d);   color:var(--red); }
.chip-warn { background:var(--amber-d); color:var(--amber); }

/* ══════════════════════════════════
   TABLE — Apple SF-style headers
   ══════════════════════════════════ */
.dt,.tbl { width:100%; border-collapse:collapse; }
.dt th,.tbl th {
  padding:9px 18px;
  font-family:var(--head-font); font-size:9px; font-weight:700;
  text-transform:uppercase; letter-spacing:.9px;
  color:var(--t3); text-align:left;
  border-bottom:1px solid var(--sep); white-space:nowrap;
}
.dt td,.tbl td { padding:11px 18px; font-size:13px; color:var(--t2); border-bottom:1px solid var(--sep); vertical-align:middle; }
.dt tbody tr:hover td,.tbl tbody tr:hover td { background:var(--g1); color:var(--t1); }
.dt tbody tr:last-child td,.tbl tbody tr:last-child td { border-bottom:none; }

/* ══════════════════════════════════
   BADGES
   ══════════════════════════════════ */
.badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:var(--r4); font-size:11px; font-weight:600; letter-spacing:.15px; white-space:nowrap; }
.b-open      { background:var(--blue-d);   color:var(--blue); }
.b-progress  { background:var(--amber-d);  color:var(--amber); }
.b-waiting   { background:var(--purple-d); color:var(--purple); }
.b-completed { background:var(--green-d);  color:var(--green); }
.b-closed    { background:var(--g2); color:var(--t3); border:1px solid var(--sep); }
.b-critical  { background:var(--red-d);    color:var(--red); }
.b-high      { background:var(--amber-d);  color:var(--amber); }
.b-medium    { background:var(--blue-d);   color:var(--blue); }
.b-low       { background:var(--g2); color:var(--t3); }
.b-available   { background:var(--green-d);  color:var(--green); }
.b-breakdown   { background:var(--red-d);    color:var(--red); }
.b-maintenance { background:var(--amber-d);  color:var(--amber); }
.b-standby     { background:var(--g2); color:var(--t3); }
.b-primary   { background:var(--blue-d);   color:var(--blue); }
.b-success   { background:var(--green-d);  color:var(--green); }
.b-danger    { background:var(--red-d);    color:var(--red); }
.b-warning   { background:var(--amber-d);  color:var(--amber); }
.b-info      { background:rgba(50,173,230,0.11); color:var(--cyan); }
.b-secondary { background:var(--g2); color:var(--t3); border:1px solid var(--sep); }

/* ══════════════════════════════════
   FORMS — Apple HIG inputs
   ══════════════════════════════════ */
.form-label { display:block; font-size:11.5px; font-weight:600; color:var(--t2); margin-bottom:6px; letter-spacing:.05px; font-family:var(--ui-font); }
.req { color:var(--red); }
.form-control,.form-select {
  width:100%;
  background:rgba(255,255,255,0.042);
  border:1px solid var(--sep);
  border-radius:var(--r1);
  padding:10px 13px;
  color:var(--t1);
  font-family:var(--ui-font); font-size:13px;
  outline:none;
  transition:border-color .18s, box-shadow .18s, background .18s;
  -webkit-appearance:none;
}
.form-control:focus,.form-select:focus {
  border-color:var(--blue);
  box-shadow:0 0 0 3px rgba(0,113,227,0.14);
  background:rgba(0,113,227,0.035);
}
.form-control::placeholder { color:var(--t4); }
.form-select option { background:#131320; color:var(--t1); }
textarea.form-control { resize:vertical; min-height:82px; }
.form-grid { display:grid; gap:14px; }
.cols-2 { grid-template-columns:1fr 1fr; }
.cols-3 { grid-template-columns:1fr 1fr 1fr; }
.cols-4 { grid-template-columns:1fr 1fr 1fr 1fr; }

/* ══════════════════════════════════
   BUTTONS — Apple HIG style
   ══════════════════════════════════ */
.btn {
  display:inline-flex; align-items:center; gap:6px;
  padding:9px 17px;
  border-radius:var(--r4);
  font-family:var(--ui-font); font-size:13px; font-weight:600;
  cursor:pointer; border:none;
  transition:all .18s cubic-bezier(0.4,0,0.2,1);
  text-decoration:none; white-space:nowrap; letter-spacing:-.1px;
  -webkit-appearance:none;
}
.btn-primary { background:var(--blue); color:#fff; box-shadow:0 2px 12px rgba(0,113,227,0.28); }
.btn-primary:hover { background:#2b94ff; transform:translateY(-1px); color:#fff; box-shadow:0 4px 20px rgba(0,113,227,0.38); }
.btn-ghost   { background:var(--g1); color:var(--t2); border:1px solid var(--sep); }
.btn-ghost:hover   { background:var(--g2); color:var(--t1); border-color:var(--sep-strong); }
.btn-danger  { background:var(--red-d);   color:var(--red);   border:1px solid rgba(192,57,43,0.18); }
.btn-danger:hover  { background:rgba(192,57,43,0.20); color:var(--red); }
.btn-success { background:var(--green-d); color:var(--green); border:1px solid rgba(28,140,60,0.18); }
.btn-success:hover { background:rgba(28,140,60,0.20); color:var(--green); }
.btn-amber   { background:var(--amber-d); color:var(--amber); border:1px solid rgba(180,83,9,0.18); }
.btn-amber:hover   { background:rgba(180,83,9,0.20); color:var(--amber); }
.btn-sm { padding:5px 13px; font-size:12px; }
.btn svg { width:14px; height:14px; fill:currentColor; flex-shrink:0; }
.btn-icon svg { stroke:currentColor; fill:none; stroke-width:1.75; stroke-linecap:round; stroke-linejoin:round; }

/* ══════════════════════════════════
   LAYOUT HELPERS (same as Original)
   ══════════════════════════════════ */
.grid{display:grid;gap:12px;}
.g-2{grid-template-columns:1fr 1fr;}
.g-3{grid-template-columns:1fr 1fr 1fr;}
.g-4{grid-template-columns:repeat(4,1fr);}
.g-7{grid-template-columns:repeat(7,1fr);}
.g-2-1{grid-template-columns:1fr 1fr 320px;}
.g-1-2{grid-template-columns:320px 1fr 1fr;}
.col-2{grid-column:span 2;}
.col-3{grid-column:span 3;}
.mb-3{margin-bottom:12px;}.mb-4{margin-bottom:16px;}.mb-5{margin-bottom:24px;}
.gap-sm{gap:8px;}
.flex{display:flex;}.ai-c{align-items:center;}.jb{justify-content:space-between;}.gap-10{gap:10px;}.gap-12{gap:12px;}.ml-a{margin-left:auto;}
.fw-500{font-weight:500;}.fw-600{font-weight:600;}.fw-700{font-weight:700;}
.mono{font-family:var(--code-font);}
.text-muted{color:var(--t3);}.text-blue{color:var(--blue);}.text-green{color:var(--green);}.text-red{color:var(--red);}.text-amber{color:var(--amber);}.text-purple{color:var(--purple);}
.w-full{width:100%;}.text-center{text-align:center;}.text-right{text-align:right;}
.led{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.led-ok{background:var(--green);box-shadow:0 0 7px rgba(28,140,60,0.5);}
.led-warn{background:var(--amber);box-shadow:0 0 7px rgba(180,83,9,0.4);}
.led-crit{background:var(--red);box-shadow:0 0 7px rgba(192,57,43,0.5);animation:led-b 1.1s ease-in-out infinite;}
.led-off{background:var(--t4);}
@keyframes led-b{0%,100%{opacity:1}50%{opacity:.2}}
.row-list-item{display:flex;align-items:center;gap:12px;padding:12px 22px;border-bottom:1px solid var(--sep);transition:background .15s;cursor:pointer;}
.row-list-item:last-child{border-bottom:none;}
.row-list-item:hover{background:var(--g2);}
.pbar{width:100%;height:3px;background:var(--sep);border-radius:2px;overflow:hidden;}
.pbar-fill{height:100%;border-radius:2px;}
@keyframes rise{from{opacity:0;transform:translateY(14px) scale(.98);}to{opacity:1;transform:translateY(0) scale(1);}}
@keyframes fade-in{from{opacity:0;}to{opacity:1;}}
@media print{#sidebar,#canvas .topbar,.btn,.btn-cta{display:none!important;}#canvas{margin-left:0!important;height:auto!important;overflow:visible!important;}.card{box-shadow:none!important;border:1px solid #ddd!important;}}

/* ══════════════════════════════════
   COMPATIBILITY LAYER (same as Original)
   ══════════════════════════════════ */
.wms-card{background:var(--g1);border:1px solid var(--sep);border-radius:var(--r3);overflow:hidden;margin-bottom:16px;}
.card-head{padding:16px 22px;font-family:var(--head-font);font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.7px;border-bottom:1px solid var(--sep);}
.card-body{padding:22px;}
.card-body.p-0{padding:0;}
.card-body.py-3{padding:12px 22px;}
.d-flex{display:flex;}.flex-column{flex-direction:column;}
.justify-content-between{justify-content:space-between;}.justify-content-center{justify-content:center;}.justify-content-end{justify-content:flex-end;}
.align-items-center{align-items:center;}.align-items-start{align-items:flex-start;}
.gap-2{gap:8px;}.gap-3{gap:12px;}
.w-100{width:100%;}
.text-warning{color:var(--amber)!important;}.text-success{color:var(--green)!important;}.text-danger{color:var(--red)!important;}.text-primary{color:var(--blue)!important;}.text-muted{color:var(--t3)!important;}
.me-1{margin-right:4px;}.me-2{margin-right:8px;}.ms-auto{margin-left:auto;}.ms-1{margin-left:4px;}.ms-2{margin-left:8px;}
.mb-2{margin-bottom:8px;}.my-3{margin:12px 0;}.mt-2{margin-top:8px;}.mt-3{margin-top:12px;}
.p-2{padding:8px;}.p-3{padding:12px;}.py-2{padding:8px 0;}.py-3{padding:12px 0;}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.row.g-3{gap:12px;}.row.g-4{gap:16px;}
.col-12,.col{grid-column:1/-1;}
.col-md-4,.col-6{grid-column:span 1;}
.col-md-6{grid-column:span 1;}
.col-md-8{grid-column:span 1;}
.col-lg-4,.col-lg-6,.col-lg-8,.col-lg-12{grid-column:1/-1;}
.btn-outline-primary{background:transparent;color:var(--blue);border:1px solid var(--blue);}
.btn-outline-primary:hover{background:rgba(0,113,227,0.12);}
.btn-outline-secondary{background:transparent;color:var(--t2);border:1px solid var(--sep);}
.btn-outline-secondary:hover{background:var(--g2);}
.btn-outline-success{background:transparent;color:var(--green);border:1px solid rgba(28,140,60,0.35);}
.btn-outline-success:hover{background:var(--green-d);}
.btn-outline-danger{background:transparent;color:var(--red);border:1px solid rgba(192,57,43,0.4);}
.table-responsive{overflow-x:auto;}
.table.wms-table{width:100%;border-collapse:collapse;}
.table.wms-table th{padding:9px 18px;font-family:var(--head-font);font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.9px;color:var(--t3);border-bottom:1px solid var(--sep);text-align:left;}
.table.wms-table td{padding:11px 18px;font-size:13px;color:var(--t2);border-bottom:1px solid var(--sep);vertical-align:middle;}
.table.wms-table tbody tr:hover td{background:var(--g1);}
.table.wms-table tbody tr:last-child td{border-bottom:none;}
.progress{background:var(--sep);border-radius:var(--r4);overflow:hidden;}
.progress-bar{height:100%;border-radius:var(--r4);}
.bg-success{background:var(--green)!important;}.bg-warning{background:var(--amber)!important;}.bg-danger{background:var(--red)!important;}.bg-primary{background:var(--blue)!important;}
.badge.bg-primary{background:var(--blue-d)!important;color:var(--blue);border:none;}
.badge.bg-secondary{background:var(--g2)!important;color:var(--t3);}
.status-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:var(--r4);font-size:11px;font-weight:600;}
.chart-container{position:relative;height:220px;}
/* Bootstrap icon fallbacks */
.bi::before{font-style:normal;}
.bi-printer::before{content:'🖨';font-size:11px;}
.bi-file-earmark-excel::before{content:'📊';font-size:11px;}
.bi-graph-up::before{content:'📈';font-size:11px;}
.bi-clipboard-check::before{content:'📋';font-size:11px;}
.bi-person-check::before{content:'👤';font-size:11px;}
.bi-droplet::before{content:'💧';font-size:11px;}
.bi-table::before{content:'📊';font-size:11px;}
.bi-arrow-left::before{content:'←';}
.bi-send::before{content:'→';}
</style>
</head>
<body>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
