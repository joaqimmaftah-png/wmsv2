<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="<?= isset($lang) && $lang === 'fr' ? 'fr' : 'en'; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= (isset($page_title) ? htmlspecialchars((string)($page_title)).' — ' : ''); echo (isset($site_settings['company_name']) ? htmlspecialchars((string)($site_settings['company_name'])) : 'Workshop WMS'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
/* ═══════════════════════════════════════════════════════
   APPLE DESIGN SYSTEM — Light
   Philosophy: clarity, deference, depth.
   Every element earns its place. Nothing decorative.
═══════════════════════════════════════════════════════ */

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

:root {
  /* Surfaces */
  --bg:        #f5f5f7;
  --surface:   #ffffff;
  --surface-2: #f9f9f9;
  --elevated:  #ffffff;

  /* Separators — Apple uses hairline dividers */
  --sep:       rgba(0,0,0,0.08);
  --sep-strong:rgba(0,0,0,0.13);

  /* Typography — Apple's text hierarchy */
  --t1: #1d1d1f;
  --t2: #6e6e73;
  --t3: #86868b;
  --t4: #aeaeb2;

  /* Apple system accent colors (light mode versions) */
  --blue:   #0071e3;
  --green:  #1c8c3c;
  --amber:  #b45309;
  --red:    #c0392b;
  --purple: #6d3fc0;
  --teal:   #0c7b7b;

  /* Tinted fills — very low opacity for badges/chips */
  --blue-fill:   rgba(0,113,227,0.08);
  --green-fill:  rgba(28,140,60,0.08);
  --amber-fill:  rgba(180,83,9,0.08);
  --red-fill:    rgba(192,57,43,0.08);
  --purple-fill: rgba(109,63,192,0.08);
  --gray-fill:   rgba(0,0,0,0.05);

  /* Backward compat aliases */
  --blue-d: var(--blue-fill);
  --green-d:var(--green-fill);
  --amber-d:var(--amber-fill);
  --red-d:  var(--red-fill);
  --purple-d:var(--purple-fill);
  --g1:rgba(0,0,0,0.03);
  --g2:rgba(0,0,0,0.05);
  --g3:rgba(0,0,0,0.08);
  --void:var(--bg);--base:var(--bg);--border:var(--sep);--border-b:var(--sep-strong);
  --cyan:#0c7b7b;

  /* Geometry */
  --r-xs: 6px;
  --r-sm: 10px;
  --r-md: 14px;
  --r-lg: 18px;
  --r-xl: 22px;
  /* backward compat */
  --r1:var(--r-sm);--r2:var(--r-md);--r3:var(--r-lg);--r4:var(--r-xl);

  /* Sidebar */
  --sb:    68px;
  --sb-x:  240px;
  --sb-dur:0.22s;

  /* Shadows — Apple-style: layered, extremely subtle */
  --shadow-header:0 2px 8px rgba(0,0,0,0.06);
  --shadow-card: 0 1px 3px rgba(0,0,0,0.06), 0 4px 12px rgba(0,0,0,0.04);
  --shadow-raise:0 2px 8px rgba(0,0,0,0.08), 0 12px 28px rgba(0,0,0,0.06);
  --shadow-float:0 4px 20px rgba(0,0,0,0.10), 0 1px 4px rgba(0,0,0,0.06);
}

/* ── FOUNDATION ── */
html,body {
  height:100%;background:var(--bg);color:var(--t1);
  font-family:'Inter',-apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",sans-serif;
  font-size:14px;line-height:1.5;
  -webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;
  overflow:hidden;
}

/* ─────────────────────────────────────────────────────
   SIDEBAR — clean white panel, no decoration
───────────────────────────────────────────────────── */
#sidebar {
  position:fixed;top:0;left:0;bottom:0;width:var(--sb);
  background:var(--surface);
  border-right:1px solid var(--sep);
  display:flex;flex-direction:column;align-items:flex-start;
  padding:16px 0 14px;
  z-index:200;
  transition:width var(--sb-dur) cubic-bezier(0.4,0,0.2,1);
  overflow:hidden;
}
#sidebar.expanded { width:var(--sb-x); }

/* Logo mark */
.sb-logo {
  display:flex;align-items:center;gap:10px;
  padding:0 14px;margin-bottom:24px;
  flex-shrink:0;width:100%;text-decoration:none;
}
.sb-logo-mark {
  width:38px;height:38px;min-width:38px;border-radius:10px;
  background:linear-gradient(160deg,#0077ed,#005ab5);
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;overflow:hidden;
}
.sb-logo-mark img { width:100%;height:100%;object-fit:contain; }
.sb-logo-text { opacity:0;transition:opacity calc(var(--sb-dur)*0.7) ease;white-space:nowrap;overflow:hidden; }
#sidebar.expanded .sb-logo-text { opacity:1; }
.sb-logo-name { font-size:13px;font-weight:700;letter-spacing:-0.3px;color:var(--t1);line-height:1.1; }
.sb-logo-sub  { font-size:10px;color:var(--t3);margin-top:1px;letter-spacing:0; }

/* Nav items */
.nav-wrap {
  flex:1;display:flex;flex-direction:column;gap:2px;
  width:100%;padding:0 8px;
  overflow-y:auto;overflow-x:hidden;scrollbar-width:none;
}
.nav-wrap::-webkit-scrollbar { display:none; }

.nav-sep { width:calc(100% - 16px);height:1px;background:var(--sep);margin:6px 8px;flex-shrink:0; }

.nav-section-label {
  font-size:11px;font-weight:600;letter-spacing:0.2px;
  color:var(--t4);padding:0 10px;
  white-space:nowrap;overflow:hidden;
  opacity:0;height:0;margin:0;
  transition:opacity calc(var(--sb-dur)*0.5) ease,height .18s ease,margin .18s ease;
}
#sidebar.expanded .nav-section-label { opacity:1;height:22px;margin:8px 0 2px; }

.ni {
  position:relative;
  display:flex;align-items:center;gap:10px;
  height:40px;border-radius:var(--r-sm);padding:0 10px;
  color:var(--t3);cursor:pointer;
  transition:background 0.15s ease,color 0.15s ease;
  text-decoration:none;flex-shrink:0;width:100%;
}
.ni:hover { color:var(--t1);background:var(--g2); }
.ni.on    { color:var(--blue);background:var(--blue-fill); font-weight:500; }

.ni-icon {
  width:22px;height:22px;min-width:22px;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.ni svg {
  width:17px;height:17px;stroke:currentColor;fill:none;
  stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;pointer-events:none;
}
.ni-label {
  font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;
  opacity:0;transition:opacity calc(var(--sb-dur)*0.55) ease;flex:1;
}
#sidebar.expanded .ni-label { opacity:1; }

/* Tooltip shown when collapsed */
.ni-tip {
  position:absolute;left:calc(var(--sb) + 6px);top:50%;
  transform:translateY(-50%) translateX(-4px);
  background:var(--surface);border:1px solid var(--sep-strong);color:var(--t1);
  padding:5px 11px;border-radius:var(--r-xs);font-size:12px;font-weight:500;
  white-space:nowrap;pointer-events:none;z-index:500;opacity:0;
  box-shadow:var(--shadow-float);
  transition:opacity 0.1s ease,transform 0.1s ease;
}
#sidebar:not(.expanded) .ni:hover .ni-tip {
  opacity:1;transform:translateY(-50%) translateX(0);
}

/* Bottom user card */
.sb-user { flex-shrink:0;width:100%;padding:0 8px;margin-top:4px; }
.sb-user-card {
  display:flex;align-items:center;gap:10px;padding:8px 10px;
  border-radius:var(--r-sm);
  cursor:pointer;text-decoration:none;
  transition:background 0.15s;width:100%;overflow:hidden;
}
.sb-user-card:hover { background:var(--g2); }
.sb-avatar {
  width:30px;height:30px;min-width:30px;border-radius:50%;
  background:linear-gradient(145deg,#5856d6,#0071e3);
  display:flex;align-items:center;justify-content:center;
  font-size:11px;font-weight:600;color:#fff;flex-shrink:0;
}
.sb-user-info { opacity:0;transition:opacity calc(var(--sb-dur)*0.5) ease;flex:1;min-width:0;overflow:hidden; }
#sidebar.expanded .sb-user-info { opacity:1; }
.sb-user-name { font-size:12px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.sb-user-role { font-size:10px;color:var(--t3);margin-top:1px; }
.sb-logout { opacity:0;transition:opacity calc(var(--sb-dur)*0.5) ease;color:var(--t3);flex-shrink:0; }
#sidebar.expanded .sb-logout { opacity:1; }
.sb-logout svg { width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round; }

/* Pin button */
.sb-pin {
  position:absolute;top:16px;right:-11px;width:22px;height:22px;border-radius:50%;
  background:var(--surface);border:1px solid var(--sep-strong);
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;color:var(--t3);opacity:0;transition:opacity 0.15s,color 0.15s;z-index:300;
}
#sidebar:hover .sb-pin { opacity:1; }
.sb-pin:hover { color:var(--blue); }
.sb-pin svg { width:10px;height:10px;stroke:currentColor;fill:none;stroke-width:2.5;stroke-linecap:round; }

/* ─────────────────────────────────────────────────────
   CANVAS + TOPBAR
───────────────────────────────────────────────────── */
#canvas {
  margin-left:var(--sb);height:100vh;
  overflow-y:auto;position:relative;z-index:1;
  transition:margin-left var(--sb-dur) cubic-bezier(0.4,0,0.2,1);
}
#canvas.expanded { margin-left:var(--sb-x); }
#canvas::-webkit-scrollbar { width:4px; }
#canvas::-webkit-scrollbar-thumb { background:rgba(0,0,0,0.10);border-radius:4px; }

.topbar {
  position:sticky;top:0;z-index:50;
  padding:20px 32px 0;
  background:var(--surface);
  border-bottom:1px solid var(--sep-strong);
  box-shadow:none;
}
.topbar.is-scrolled { box-shadow:var(--shadow-header); }
.topbar-row {
  display:flex;align-items:center;justify-content:space-between;gap:20px;
  padding-bottom:18px;
}
.topbar-heading { min-width:0; }
.topbar-greeting { font-size:11px;color:var(--t2);font-weight:400;margin-bottom:3px; }
.topbar-greeting strong { color:var(--blue);font-weight:600; }
.page-heading { font-size:20px;font-weight:700;letter-spacing:-0.5px;color:var(--t1); }
.topbar .page-heading { overflow-wrap:anywhere; }
.topbar-actions { display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap; }
.topbar a:focus-visible,.topbar button:focus-visible {
  outline:2px solid var(--blue);outline-offset:2px;
}

/* Status and notification pills */
.live-pill {
  display:inline-flex;align-items:center;gap:7px;
  background:var(--surface-2);border:1px solid var(--sep-strong);
  padding:6px 14px;border-radius:100px;
  font-size:12px;font-weight:500;color:var(--t2);
}

/* User button + dropdown */
.tb-user { position:relative;display:inline-block; }
.tb-user-trigger {
  display:inline-flex;align-items:center;gap:8px;
  background:var(--surface-2);border:1px solid var(--sep-strong);
  padding:4px 12px 4px 4px;border-radius:100px;
  cursor:pointer;font:inherit;color:inherit;text-align:left;
  transition:background 0.15s,border-color 0.15s;
}
.tb-user-trigger:hover { background:var(--g2);border-color:var(--sep-strong); }
.tb-user-copy { display:flex;flex-direction:column; }
.tb-avatar {
  width:27px;height:27px;min-width:27px;border-radius:50%;
  background:linear-gradient(145deg,#5856d6,#0071e3);
  display:flex;align-items:center;justify-content:center;
  font-size:10px;font-weight:600;color:#fff;
}
.tb-name { font-size:12.5px;font-weight:600;color:var(--t1); }
.tb-role { font-size:10px;color:var(--t2); }
.tb-chevron svg { width:11px;height:11px;stroke:var(--t3);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }

.tb-dropdown {
  position:absolute;top:calc(100% + 8px);right:0;
  background:var(--surface);border:1px solid var(--sep);
  border-radius:var(--r-md);min-width:200px;overflow:hidden;
  box-shadow:var(--shadow-float);
  display:none;z-index:400;animation:dropdown-in 0.14s ease;
}
@keyframes dropdown-in { from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:translateY(0);} }
.tb-dropdown.open { display:block; }
.tbd-head { padding:14px 16px;border-bottom:1px solid var(--sep); }
.tbd-name  { font-size:13px;font-weight:600;color:var(--t1); }
.tbd-email { font-size:11px;color:var(--t3);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.tbd-item {
  display:flex;align-items:center;gap:10px;padding:10px 16px;
  font-size:13px;font-weight:400;color:var(--t2);
  text-decoration:none;cursor:pointer;transition:background 0.1s;
}
.tbd-item:hover { background:var(--g1);color:var(--t1); }
.tbd-item svg { width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:1.7;stroke-linecap:round;flex-shrink:0; }
.tbd-item.danger { color:var(--red); }
.tbd-item.danger:hover { background:var(--red-fill); }
.tbd-sep { height:1px;background:var(--sep); }

/* ─────────────────────────────────────────────────────
   FLASH MESSAGES
───────────────────────────────────────────────────── */
.flash {
  display:flex;align-items:center;gap:10px;
  padding:12px 16px;border-radius:var(--r-md);
  font-size:13px;font-weight:400;margin-bottom:16px;
}
.flash-ok  { background:var(--green-fill);border:1px solid rgba(28,140,60,0.15);color:var(--green); }
.flash-err { background:var(--red-fill);border:1px solid rgba(192,57,43,0.15);color:var(--red); }
.flash svg { width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;flex-shrink:0; }

/* ─────────────────────────────────────────────────────
   PAGE WRAPPER
───────────────────────────────────────────────────── */
.page { padding:24px 32px 56px; }

/* ─────────────────────────────────────────────────────
   CARDS — thin border, micro-shadow, generous padding
───────────────────────────────────────────────────── */
.card {
  background:var(--surface);
  border:1px solid var(--sep);
  border-radius:var(--r-lg);
  overflow:hidden;
  position:relative;
  box-shadow:var(--shadow-card);
  animation:rise 0.4s cubic-bezier(0.16,1,0.3,1) both;
}
/* card header */
.ch {
  display:flex;align-items:flex-start;justify-content:space-between;
  padding:20px 24px 0;
}
.ct { font-size:14px;font-weight:600;color:var(--t1);letter-spacing:-0.1px; }
.cs { font-size:12px;color:var(--t3);margin-top:2px;font-weight:400; }
.ca { font-size:12px;color:var(--blue);font-weight:500;cursor:pointer;text-decoration:none;transition:opacity 0.15s; }
.ca:hover { opacity:0.7; }

/* ─────────────────────────────────────────────────────
   KPI CARDS — number is the hero
───────────────────────────────────────────────────── */
.kpi {
  background:var(--surface);
  border:1px solid var(--sep);
  border-radius:var(--r-md);
  padding:20px 22px;
  position:relative;
  box-shadow:var(--shadow-card);
  animation:rise 0.4s cubic-bezier(0.16,1,0.3,1) both;
  transition:box-shadow 0.2s,transform 0.2s;
}
.kpi:hover { box-shadow:var(--shadow-raise);transform:translateY(-2px); }
.kpi-glow { display:none; } /* removed — no decorative glows on light theme */
.kpi-tag {
  display:block;font-size:11px;font-weight:500;
  color:var(--t3);margin-bottom:10px;letter-spacing:0;text-transform:none;
}
.kpi-num {
  font-size:28px;font-weight:700;letter-spacing:-1px;color:var(--t1);
  line-height:1;display:flex;align-items:baseline;gap:2px;font-variant-numeric:tabular-nums;
}
.kpi-num sup { font-size:14px;letter-spacing:0;color:var(--t2);font-weight:500; }
.kpi-chip {
  display:inline-flex;align-items:center;gap:4px;
  font-size:11px;font-weight:500;margin-top:10px;
  padding:3px 9px;border-radius:100px;
}
.chip-up   { background:var(--green-fill);color:var(--green); }
.chip-dn   { background:var(--red-fill);color:var(--red); }
.chip-warn { background:var(--amber-fill);color:var(--amber); }

/* ─────────────────────────────────────────────────────
   TABLES — Apple uses clean rows, barely-there borders
───────────────────────────────────────────────────── */
.dt,.tbl { width:100%;border-collapse:collapse; }
.dt th,.tbl th {
  padding:10px 24px;
  font-size:11px;font-weight:600;color:var(--t3);
  text-align:left;border-bottom:1px solid var(--sep);
  background:var(--surface-2);letter-spacing:0;text-transform:none;
}
.dt td,.tbl td {
  padding:13px 24px;font-size:13px;color:var(--t2);
  border-bottom:1px solid var(--sep);vertical-align:middle;
}
.dt tbody tr:hover td,.tbl tbody tr:hover td { background:#f7f7f8;color:var(--t1); }
.dt tbody tr:last-child td,.tbl tbody tr:last-child td { border-bottom:none; }

/* ─────────────────────────────────────────────────────
   BADGES — minimal fills, no borders
───────────────────────────────────────────────────── */
.badge {
  display:inline-flex;align-items:center;
  padding:3px 9px;border-radius:100px;
  font-size:11px;font-weight:500;letter-spacing:0;
}
.b-open       { background:var(--blue-fill);color:var(--blue); }
.b-progress   { background:var(--amber-fill);color:var(--amber); }
.b-waiting    { background:var(--purple-fill);color:var(--purple); }
.b-completed,.b-success,.b-available { background:var(--green-fill);color:var(--green); }
.b-closed,.b-secondary,.b-standby { background:var(--gray-fill);color:var(--t3); }
.b-critical,.b-danger,.b-breakdown { background:var(--red-fill);color:var(--red); }
.b-high,.b-warning,.b-maintenance  { background:var(--amber-fill);color:var(--amber); }
.b-medium,.b-primary               { background:var(--blue-fill);color:var(--blue); }
.b-low                             { background:var(--gray-fill);color:var(--t3); }
.b-info                            { background:rgba(12,123,123,0.08);color:var(--teal); }

/* ─────────────────────────────────────────────────────
   FORMS — clean, minimal, Apple HIG–compliant
───────────────────────────────────────────────────── */
.form-label { display:block;font-size:12px;font-weight:500;color:var(--t2);margin-bottom:5px; }
.req { color:var(--red); }
.form-control,.form-select {
  width:100%;background:var(--surface);
  border:1px solid rgba(0,0,0,0.15);border-radius:var(--r-sm);
  padding:9px 13px;color:var(--t1);
  font-family:inherit;font-size:13px;outline:none;
  transition:border-color 0.15s,box-shadow 0.15s;-webkit-appearance:none;
}
.form-control:focus,.form-select:focus {
  border-color:var(--blue);
  box-shadow:0 0 0 3px rgba(0,113,227,0.12);
}
.form-control::placeholder { color:var(--t4); }
.form-select option { background:var(--surface);color:var(--t1); }
textarea.form-control { resize:vertical;min-height:80px; }
.form-grid { display:grid;gap:16px; }
.cols-2 { grid-template-columns:1fr 1fr; }
.cols-3 { grid-template-columns:1fr 1fr 1fr; }
.cols-4 { grid-template-columns:1fr 1fr 1fr 1fr; }

/* ─────────────────────────────────────────────────────
   BUTTONS — pill primary, text secondary
───────────────────────────────────────────────────── */
.btn {
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 18px;border-radius:100px;
  font-family:inherit;font-size:13px;font-weight:500;
  cursor:pointer;border:none;transition:background 0.15s,transform 0.15s,box-shadow 0.15s;
  text-decoration:none;white-space:nowrap;
}
.btn-primary { background:var(--blue);color:#fff; }
.btn-primary:hover { background:#0077ed;transform:scale(1.02);color:#fff; }
.btn-ghost {
  background:var(--surface);color:var(--t2);
  border:1px solid var(--sep);
}
.btn-ghost:hover { background:var(--g2);color:var(--t1);border-color:var(--sep-strong); }
/* Filled neutral variant: used by team/report, suppliers and lubricants for secondary
   calls to action. Previously referenced but never defined, so those buttons rendered
   as unstyled text. */
.btn-secondary {
  background:var(--fill, #f1f1f4);color:var(--t1);
  border:1px solid var(--sep);
}
.btn-secondary:hover { background:var(--g2);color:var(--t1);border-color:var(--sep-strong); }
.btn-danger  { background:var(--red-fill);color:var(--red);border:1px solid rgba(192,57,43,0.15); }
.btn-danger:hover { background:rgba(192,57,43,0.14);color:var(--red); }
.btn-success { background:var(--green-fill);color:var(--green);border:1px solid rgba(28,140,60,0.15); }
.btn-success:hover { background:rgba(28,140,60,0.14);color:var(--green); }
.btn-amber   { background:var(--amber-fill);color:var(--amber);border:1px solid rgba(180,83,9,0.15); }
.btn-amber:hover { background:rgba(180,83,9,0.14);color:var(--amber); }
.btn-sm { padding:5px 13px;font-size:12px; }
.btn svg { width:13px;height:13px;fill:currentColor;flex-shrink:0; }
.btn-icon svg { stroke:currentColor;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round; }

/* ─────────────────────────────────────────────────────
   LAYOUT HELPERS
───────────────────────────────────────────────────── */
.grid { display:grid;gap:14px; }
.g-2  { grid-template-columns:1fr 1fr; }
.g-3  { grid-template-columns:1fr 1fr 1fr; }
.g-4  { grid-template-columns:repeat(4,1fr); }
.g-7  { grid-template-columns:repeat(7,1fr); }
.g-2-1{ grid-template-columns:1fr 1fr 320px; }
.g-1-2{ grid-template-columns:320px 1fr 1fr; }
.col-2{ grid-column:span 2; }.col-3{ grid-column:span 3; }
.mb-3 { margin-bottom:12px; }.mb-4 { margin-bottom:16px; }.mb-5 { margin-bottom:24px; }
.mt-4 { margin-top:16px; }
.gap-sm { gap:8px; }
.flex { display:flex; }.ai-c { align-items:center; }.jb { justify-content:space-between; }
.gap-10 { gap:10px; }.gap-12 { gap:12px; }.ml-a { margin-left:auto; }
.fw-600 { font-weight:600; }.fw-700 { font-weight:700; }
.mono { font-variant-numeric:tabular-nums;letter-spacing:-0.3px; }
.text-muted  { color:var(--t3); }.text-blue  { color:var(--blue); }
.text-green  { color:var(--green); }.text-red  { color:var(--red); }
.text-amber  { color:var(--amber); }.text-purple{ color:var(--purple); }
.w-full { width:100%; }.text-center { text-align:center; }.text-right { text-align:right; }
.led     { width:6px;height:6px;border-radius:50%;flex-shrink:0; }
.led-ok  { background:var(--green); }
.led-warn{ background:var(--amber); }
.led-crit{ background:var(--red);animation:led-b 1.2s ease-in-out infinite; }
.led-off { background:var(--t4); }
@keyframes led-b { 0%,100%{opacity:1}50%{opacity:.25} }
.row-list-item { display:flex;align-items:center;gap:12px;padding:13px 24px;border-bottom:1px solid var(--sep);transition:background 0.1s; }
.row-list-item:last-child { border-bottom:none; }
.row-list-item:hover { background:#f7f7f8; }
.pbar { width:100%;height:3px;background:var(--sep);border-radius:2px;overflow:hidden; }
.pbar-fill { height:100%;border-radius:2px; }
@keyframes rise { from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);} }
@keyframes fade-in { from{opacity:0;}to{opacity:1;} }
.sec-head {
  font-size:11px;font-weight:600;color:var(--t3);
  margin:20px 0 12px;padding-bottom:8px;
  border-bottom:1px solid var(--sep);letter-spacing:0;text-transform:none;
}
@media (max-width:1100px){
  .topbar { padding-left:24px;padding-right:24px; }
  .topbar-row { align-items:flex-start;gap:16px; }
}
@media (max-width:760px){
  .topbar { padding:14px 16px 0; }
  .topbar-row { flex-direction:column;gap:12px;padding-bottom:14px; }
  .topbar-heading { width:100%; }
  .topbar-greeting { display:none; }
  .topbar .page-heading { font-size:18px;line-height:1.25; }
  .topbar-actions { width:100%;gap:8px; }
  .security-pill { padding-left:10px;padding-right:10px; }
  .security-pill-label { display:none; }
  .tb-name,.tb-role { display:none; }
  .tb-user-trigger { padding-right:8px; }
  .tb-dropdown { max-width:calc(100vw - 32px); }
}
@media (prefers-reduced-motion:reduce){
  .tb-dropdown { animation:none; }
}
@media print {
  #sidebar,#canvas .topbar,.btn,.no-print { display:none!important; }
  html,body { height:auto!important;overflow:visible!important;background:#fff!important; }
  #canvas,.page-body { margin-left:0!important;height:auto!important;overflow:visible!important; }
  .card,.wms-card { box-shadow:none!important;border:1px solid #e0e0e0!important; }
}

/* ─────────────────────────────────────────────────────
   COMPATIBILITY — Bootstrap & wms-card class aliases
───────────────────────────────────────────────────── */
.wms-card { background:var(--surface);border:1px solid var(--sep);border-radius:var(--r-lg);overflow:hidden;margin-bottom:16px;box-shadow:var(--shadow-card); }
.card-head { padding:16px 24px;font-size:13px;font-weight:600;color:var(--t2);border-bottom:1px solid var(--sep);background:var(--surface-2); }
.card-body { padding:24px; }
.card-body.p-0 { padding:0; }
.card-body.py-3 { padding:12px 24px; }
.d-flex{display:flex;}.flex-column{flex-direction:column;}
.justify-content-between{justify-content:space-between;}.justify-content-center{justify-content:center;}.justify-content-end{justify-content:flex-end;}
.align-items-center{align-items:center;}.align-items-start{align-items:flex-start;}
.gap-2{gap:8px;}.gap-3{gap:12px;}
.w-100{width:100%;}
.text-warning{color:var(--amber)!important;}.text-success{color:var(--green)!important;}.text-danger{color:var(--red)!important;}.text-primary{color:var(--blue)!important;}.text-muted{color:var(--t3)!important;}
.fw-500{font-weight:500;}.fw-600{font-weight:600;}.fw-700{font-weight:700;}
.me-1{margin-right:4px;}.me-2{margin-right:8px;}.ms-auto{margin-left:auto;}.ms-1{margin-left:4px;}.ms-2{margin-left:8px;}
.mb-2{margin-bottom:8px;}.my-3{margin:12px 0;}.mt-2{margin-top:8px;}.mt-3{margin-top:12px;}
.p-2{padding:8px;}.p-3{padding:12px;}.py-2{padding:8px 0;}.py-3{padding:12px 0;}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.row.g-3{gap:12px;}.row.g-4{gap:16px;}
.col-12,.col{grid-column:1/-1;}.col-md-4,.col-md-6,.col-md-8,.col-6{grid-column:span 1;}.col-lg-4,.col-lg-6,.col-lg-8,.col-lg-12{grid-column:1/-1;}
.btn-outline-primary{background:transparent;color:var(--blue);border:1px solid rgba(0,113,227,0.35);}
.btn-outline-primary:hover{background:var(--blue-fill);}
.btn-outline-secondary{background:transparent;color:var(--t2);border:1px solid var(--sep);}
.btn-outline-secondary:hover{background:var(--g2);}
.btn-outline-success{background:transparent;color:var(--green);border:1px solid rgba(28,140,60,0.30);}
.btn-outline-success:hover{background:var(--green-fill);}
.btn-outline-danger{background:transparent;color:var(--red);border:1px solid rgba(192,57,43,0.30);}
.btn-sm{padding:5px 13px;font-size:11.5px;}
.table-responsive{overflow-x:auto;}
.table.wms-table{width:100%;border-collapse:collapse;}
.table.wms-table th{padding:10px 24px;font-size:11px;font-weight:600;color:var(--t3);border-bottom:1px solid var(--sep);text-align:left;background:var(--surface-2);text-transform:none;letter-spacing:0;}
.table.wms-table td{padding:13px 24px;font-size:13px;color:var(--t2);border-bottom:1px solid var(--sep);vertical-align:middle;}
.table.wms-table tbody tr:hover td{background:#f7f7f8;color:var(--t1);}
.table.wms-table tbody tr:last-child td{border-bottom:none;}
.progress{background:var(--sep);border-radius:100px;overflow:hidden;}
.progress-bar{height:100%;border-radius:100px;}
.bg-success{background:var(--green)!important;}.bg-warning{background:var(--amber)!important;}.bg-danger{background:var(--red)!important;}.bg-primary{background:var(--blue)!important;}
.badge.bg-primary{background:var(--blue-fill)!important;color:var(--blue);border:none;}
.badge.bg-secondary{background:var(--gray-fill)!important;color:var(--t3);}
.status-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:100px;font-size:11px;font-weight:500;}
.chart-container{position:relative;height:220px;}
.bi::before{font-style:normal;}
.bi-printer::before{content:'🖨';font-size:11px;}.bi-file-earmark-excel::before{content:'📊';font-size:11px;}
.bi-graph-up::before{content:'📈';font-size:11px;}.bi-clipboard-check::before{content:'📋';font-size:11px;}
.bi-person-check::before{content:'👤';font-size:11px;}.bi-droplet::before{content:'💧';font-size:11px;}
.bi-table::before{content:'📊';font-size:11px;}.bi-arrow-left::before{content:'←';}.bi-send::before{content:'→';}
</style>
</head>
<body>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
