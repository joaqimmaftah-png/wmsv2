<?php
$nav_week = isset($week_start) ? (string)$week_start : (isset($report['week']['start']) ? (string)$report['week']['start'] : date('Y-m-d'));
$nav_active = isset($fleet_nav_active) ? (string)$fleet_nav_active : '';
$nav_query = '?week_start=' . rawurlencode($nav_week) . (!empty($is_live) && !empty($published) ? '&live=1' : '');
?>
<nav class="fleet-report-nav" aria-label="Fleet availability reports">
  <a class="<?= $nav_active === 'dashboard' ? 'is-active' : '' ?>" <?= $nav_active === 'dashboard' ? 'aria-current="page"' : '' ?> href="<?= base_url('reports/fleet_availability' . $nav_query) ?>">Availability</a>
  <a class="<?= $nav_active === 'weekly' ? 'is-active' : '' ?>" <?= $nav_active === 'weekly' ? 'aria-current="page"' : '' ?> href="<?= base_url('reports/weekly_w36' . $nav_query) ?>">Weekly report</a>
  <a class="<?= $nav_active === 'downtime' ? 'is-active' : '' ?>" <?= $nav_active === 'downtime' ? 'aria-current="page"' : '' ?> href="<?= base_url('reports/downtime_log' . $nav_query) ?>">Log downtime</a>
</nav>
<style>
.fleet-report-nav{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px;padding:5px;border:1px solid var(--sep,#e5e7eb);border-radius:12px;background:var(--g1,#f8fafc);width:max-content;max-width:100%}
.fleet-report-nav a{display:inline-flex;align-items:center;min-height:36px;padding:7px 13px;border-radius:8px;color:var(--t2,#475569);text-decoration:none;font-size:12.5px;font-weight:600;white-space:nowrap}
.fleet-report-nav a:hover{background:var(--g2,#eef2f7);color:var(--t1,#111827)}
.fleet-report-nav a.is-active{background:var(--blue,#0071e3);color:#fff;box-shadow:0 1px 2px rgba(0,0,0,.12)}
@media(max-width:520px){.fleet-report-nav{width:100%;display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}.fleet-report-nav a{justify-content:center;padding-inline:6px;font-size:11.5px}}
</style>
