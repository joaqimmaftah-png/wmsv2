<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('ni_active')) {
    function ni_active($path) {
        $uri = uri_string();
        return (strpos($uri, $path) === 0) ? 'on' : '';
    }
}

$uri       = uri_string();
$role_id   = isset($role_id) ? (int)$role_id : 4;
$sidebar_lang = isset($lang) && $lang === 'fr' ? 'fr' : 'en';

$fn = $ln = '';
if (isset($current_user)) {
    $fn = isset($current_user->first_name) ? $current_user->first_name : '';
    $ln = isset($current_user->last_name)  ? $current_user->last_name  : '';
}
$initials  = strtoupper((string)(substr($fn,0,1).substr($ln,0,1))) ?: 'WS';
$full_name = trim((string)($fn.' '.$ln)) ?: 'User';
$email     = (isset($current_user) && isset($current_user->email)) ? $current_user->email : '';
$rl        = array(1=>'Supervisor',2=>'Technician',3=>'Store Keeper',4=>'Manager',5=>'CEO');
$role_lbl  = isset($rl[$role_id]) ? $rl[$role_id] : 'User';
$company   = (isset($site_settings['company_name']) && $site_settings['company_name'])
           ? $site_settings['company_name'] : 'Workshop WMS';
$h = (int)date('H');
$greeting  = ($h<12) ? 'Good morning' : (($h<17) ? 'Good afternoon' : 'Good evening');

// Role helpers
$is_supervisor = ($role_id == 1);
$is_technician = ($role_id == 2);
$is_store      = ($role_id == 3);
$is_manager    = ($role_id == 4);
$is_ceo        = ($role_id == 5);
?>
<!-- ═══ SIDEBAR ═══ -->
<nav id="sidebar">
  <a href="<?= base_url('dashboard'); ?>" class="sb-logo">
    <div class="sb-logo-mark" style="background:transparent;box-shadow:none;overflow:hidden;padding:2px;">
      <img src="<?= base_url('uploads/logoam.png') ?>" alt="Logo"
           style="width:100%;height:100%;object-fit:contain;display:block;">
    </div>
    <div class="sb-logo-text">
      <div class="sb-logo-name"><?= htmlspecialchars((string)($company)); ?></div>
      <div class="sb-logo-sub">Control Center</div>
    </div>
  </a>
  <div class="nav-wrap">

    <!-- ── 1. DASHBOARD — all roles ── -->
    <a href="<?= base_url('dashboard'); ?>" class="ni <?= ni_active('dashboard'); ?>" title="<?= $is_ceo ? 'Executive Summary' : 'Dashboard' ?>">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></div>
      <span class="ni-label"><?= $is_ceo ? 'Executive Summary' : 'Dashboard' ?></span>
      <span class="ni-tip"><?= $is_ceo ? 'Key Company KPIs' : 'Dashboard' ?></span>
    </a>

    <?php if (!$is_ceo): // CEO gets the Executive Summary only — everything below is operational detail ?>

    <!-- ── 2. MAINTENANCE — Supervisor, Technician, Manager ── -->
    <?php if ($is_supervisor || $is_technician || $is_manager): ?>
    <div class="nav-sep"></div>
    <div class="nav-section-label">Maintenance</div>

    <?php if ($is_supervisor || $is_technician): ?>
    <a href="<?= base_url('workorders/create'); ?>" class="ni <?= ni_active('workorders/create'); ?>" title="New Work Order">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg></div>
      <span class="ni-label">New Work Order</span>
      <span class="ni-tip">New Work Order</span>
    </a>
    <?php endif; ?>

    <a href="<?= base_url('workorders'); ?>" class="ni <?= (ni_active('workorders') && !ni_active('workorders/create')) ? 'on' : ''; ?>" title="Work Orders">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg></div>
      <span class="ni-label"><?= $is_technician ? 'My Work Orders' : 'All Work Orders'; ?></span>
      <span class="ni-tip"><?= $is_technician ? 'My Assigned Work Orders' : 'Work Orders List'; ?></span>
    </a>

    <a href="<?= base_url('equipment'); ?>" class="ni <?= ni_active('equipment'); ?>" title="Equipment">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8l4 1 3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
      <span class="ni-label">Equipment</span>
      <span class="ni-tip">Equipment Fleet</span>
    </a>

    <a href="<?= base_url('pm'); ?>" class="ni <?= ni_active('pm'); ?>" title="PM Planner">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg></div>
      <span class="ni-label">PM Planner</span>
      <span class="ni-tip">Preventive Maintenance</span>
    </a>

    <a href="<?= base_url('inspections'); ?>" class="ni <?= ni_active('inspections'); ?>" title="<?= $sidebar_lang === 'fr' ? 'Formulaires d’inspection' : 'Inspection Forms'; ?>">
      <div class="ni-icon"><svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></div>
      <span class="ni-label">Inspections</span>
      <span class="ni-tip"><?= $sidebar_lang === 'fr' ? 'Formulaires d’inspection' : 'Inspection Forms'; ?></span>
    </a>

    <a href="<?= base_url('backlog'); ?>" class="ni <?= ni_active('backlog'); ?>" title="Backlog Register">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10 6h11M10 12h11M10 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/></svg></div>
      <span class="ni-label">Backlog</span>
      <span class="ni-tip">Defect / Backlog Register</span>
    </a>
    <?php endif; ?>

    <!-- ── 3. INVENTORY — all roles ── -->
    <div class="nav-sep"></div>
    <div class="nav-section-label">Inventory</div>
    <a href="<?= base_url('materials'); ?>" class="ni <?= (ni_active('materials') && !ni_active('materials/inventory') && !ni_active('materials/transactions')) ? 'on' : ''; ?>" title="Inv. Dashboard">
      <div class="ni-icon"><svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></div>
      <span class="ni-label">Inv. Dashboard</span>
      <span class="ni-tip">Inventory Overview</span>
    </a>
    <a href="<?= base_url('materials/inventory'); ?>" class="ni <?= ni_active('materials/inventory'); ?>" title="Inventory">
      <div class="ni-icon"><svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg></div>
      <span class="ni-label">Inventory List</span>
      <span class="ni-tip">View & Manage Inventory</span>
    </a>
    <a href="<?= base_url('materials/transactions'); ?>" class="ni <?= ni_active('materials/transactions'); ?>" title="Transactions">
      <div class="ni-icon"><svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg></div>
      <span class="ni-label">Transactions</span>
      <span class="ni-tip">Stock In / Out History</span>
    </a>
    <a href="<?= base_url('orders'); ?>" class="ni <?= ni_active('orders'); ?>" title="Spare Orders">
      <div class="ni-icon"><svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
      <span class="ni-label">Spare Orders</span>
      <span class="ni-tip">Spare Parts Order Tracking</span>
    </a>
    <a href="<?= base_url('spareparts'); ?>" class="ni <?= ni_active('spareparts'); ?>" title="Pending Spares">
      <div class="ni-icon" style="position:relative;"><svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        <?php if (!empty($new_spares_alerts)): ?><span style="position:absolute;top:2px;right:2px;min-width:14px;height:14px;border-radius:7px;background:var(--red);color:#fff;font-size:8.5px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 3px;"><?= (int)$new_spares_alerts ?></span><?php endif; ?>
      </div>
      <span class="ni-label">Pending Spares<?= !empty($new_spares_alerts) ? ' ('.(int)$new_spares_alerts.')' : '' ?></span>
      <span class="ni-tip">Pending Spare Parts &amp; Availability Alerts</span>
    </a>
    <a href="<?= base_url('suppliers'); ?>" class="ni <?= ni_active('suppliers'); ?>" title="Suppliers">
      <div class="ni-icon"><svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V7l8-4 8 4v14M9 9h1m4 0h1m-6 4h1m4 0h1m-6 4h1m4 0h1"/></svg></div>
      <span class="ni-label">Suppliers</span>
      <span class="ni-tip">Supplier Database</span>
    </a>

    <!-- Lubricants — Supervisor, Technician, Store (not Manager) -->
    <?php if ($is_supervisor || $is_technician || $is_store): ?>
    <a href="<?= base_url('lubricants'); ?>" class="ni <?= ($uri==='lubricants')?'on':''; ?>" title="Lubricants">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><path d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z"/></svg></div>
      <span class="ni-label">Lubricants</span>
      <span class="ni-tip">Lubricant Records</span>
    </a>
    <?php endif; ?>

    <!-- Lube Budget — Supervisor, Store only -->
    <?php if ($is_supervisor || $is_store): ?>
    <a href="<?= base_url('lubricants/budget'); ?>" class="ni <?= ni_active('lubricants/budget'); ?>" title="Lube Budget">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
      <span class="ni-label">Lube Budget</span>
      <span class="ni-tip">Lubricant Budget</span>
    </a>
    <?php endif; ?>

    <!-- ── 3b. PLANNING — Supervisor, Manager ─────────────────────────
         The planned-availability forecast (tasks F5-F7, F10, F11).
         Kept in its own section because it is a *forward-looking plan*, not a
         report about the past. -->
    <?php if ($is_supervisor || $is_manager): ?>
    <div class="nav-sep"></div>
    <div class="nav-section-label">Planning</div>
    <a href="<?= base_url('forecast'); ?>" class="ni <?= (ni_active('forecast') && !ni_active('forecast/plan_vs_actual') && !ni_active('forecast/rolling') && !ni_active('forecast/spares') && !ni_active('forecast/allowances')) ? 'on' : ''; ?>" title="Planned Availability">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></div>
      <span class="ni-label">Planned Availability</span>
      <span class="ni-tip">Forecast the week ahead</span>
    </a>
    <a href="<?= base_url('forecast/plan_vs_actual'); ?>" class="ni <?= ni_active('forecast/plan_vs_actual'); ?>" title="Plan vs Actual">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="8" x2="22" y2="8" stroke-dasharray="3 2"/></svg></div>
      <span class="ni-label">Plan vs Actual</span>
      <span class="ni-tip">Variance and its cause</span>
    </a>
    <a href="<?= base_url('forecast/rolling'); ?>" class="ni <?= ni_active('forecast/rolling'); ?>" title="Rolling Plan">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="6" height="16" rx="1"/><rect x="9" y="4" width="6" height="16" rx="1"/><rect x="16" y="4" width="6" height="16" rx="1"/></svg></div>
      <span class="ni-label">Rolling 3-Week Plan</span>
      <span class="ni-tip">Weeks +1, +2, +3</span>
    </a>
    <a href="<?= base_url('forecast/spares'); ?>" class="ni <?= ni_active('forecast/spares'); ?>" title="PM Spare Parts">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></div>
      <span class="ni-label">PM Spare Parts</span>
      <span class="ni-tip">What the plan needs to buy</span>
    </a>
    <a href="<?= base_url('forecast/allowances'); ?>" class="ni <?= ni_active('forecast/allowances'); ?>" title="Planned Allowances">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 008 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06A1.65 1.65 0 004.6 15a1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9"/></svg></div>
      <span class="ni-label">Planned Allowances</span>
      <span class="ni-tip">Standing and unplanned hours</span>
    </a>
    <?php endif; ?>

    <!-- ── 4. REPORTS — Supervisor, Manager ── -->
    <?php if ($is_supervisor || $is_manager): ?>
    <div class="nav-sep"></div>
    <div class="nav-section-label">Reports</div>
    <a href="<?= base_url('reports'); ?>" class="ni <?= (ni_active('reports') && !ni_active('reports/workshop_daily') && !ni_active('reports/workshop_weekly') && !ni_active('reports/mttr_mtbf') && !ni_active('reports/availability_forecast') && !ni_active('reports/monthly_plan') && !ni_active('reports/asset_register') && !ni_active('reports/lube_compliance') && !ni_active('reports/store_analytics') && !ni_active('reports/issue_register') && !ni_active('reports/receipt_register') && !ni_active('reports/reorder')) ? 'on' : ''; ?>" title="Reports">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
      <span class="ni-label">Reports</span>
      <span class="ni-tip">All Reports</span>
    </a>
    <a href="<?= base_url('reports/workshop_daily'); ?>" class="ni <?= ni_active('reports/workshop_daily'); ?>" title="Workshop Daily">
      <div class="ni-icon"><svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
      <span class="ni-label">Daily Report</span>
      <span class="ni-tip">Workshop Daily Report</span>
    </a>
    <a href="<?= base_url('reports/workshop_weekly'); ?>" class="ni <?= ni_active('reports/workshop_weekly'); ?>" title="Workshop Weekly">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg></div>
      <span class="ni-label">Weekly Report</span>
      <span class="ni-tip">Workshop Weekly Report</span>
    </a>
    <a href="<?= base_url('reports/mttr_mtbf'); ?>" class="ni <?= ni_active('reports/mttr_mtbf'); ?>" title="MTTR / MTBF">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
      <span class="ni-label">MTTR / MTBF</span>
      <span class="ni-tip">Reliability Report</span>
    </a>
    <a href="<?= base_url('reports/availability_forecast'); ?>" class="ni <?= ni_active('reports/availability_forecast'); ?>" title="Availability Forecast">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
      <span class="ni-label">PM Due Forecast</span>
      <span class="ni-tip">PM due within a horizon</span>
    </a>
    <a href="<?= base_url('reports/monthly_plan'); ?>" class="ni <?= ni_active('reports/monthly_plan'); ?>" title="Monthly Plan">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
      <span class="ni-label">Monthly Plan</span>
      <span class="ni-tip">Monthly Maintenance Plan</span>
    </a>
    <a href="<?= base_url('reports/lube_compliance'); ?>" class="ni <?= ni_active('reports/lube_compliance'); ?>" title="Lube Compliance">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z"/><polyline points="9 12 11 14 15 10"/></svg></div>
      <span class="ni-label">Lube Compliance</span>
      <span class="ni-tip">Lubricant Usage vs PM Requirement</span>
    </a>
    <a href="<?= base_url('reports/store_analytics'); ?>" class="ni <?= ni_active('reports/store_analytics'); ?>" title="Store Analytics">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
      <span class="ni-label">Store Analytics</span>
      <span class="ni-tip">Supplier Spend &amp; Lead Times</span>
    </a>
    <a href="<?= base_url('reports/issue_register'); ?>" class="ni <?= ni_active('reports/issue_register'); ?>" title="Issue Register">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8"/><polyline points="7 21 12 17 17 21"/><line x1="12" y1="17" x2="12" y2="9"/></svg></div>
      <span class="ni-label">Issue Register</span>
      <span class="ni-tip">Stock Issued (Sortie)</span>
    </a>
    <a href="<?= base_url('reports/receipt_register'); ?>" class="ni <?= ni_active('reports/receipt_register'); ?>" title="Receipt Register">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16V8"/><polyline points="7 7 12 11 17 7"/><line x1="12" y1="11" x2="12" y2="3"/></svg></div>
      <span class="ni-label">Receipt Register</span>
      <span class="ni-tip">Goods Received (MRN)</span>
    </a>
    <a href="<?= base_url('reports/reorder'); ?>" class="ni <?= ni_active('reports/reorder'); ?>" title="Re-order Report">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
      <span class="ni-label">Re-order Report</span>
      <span class="ni-tip">Items at or below minimum stock</span>
    </a>
    <a href="<?= base_url('reports/asset_register'); ?>" class="ni <?= ni_active('reports/asset_register'); ?>" title="Asset Register">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8l4 1 3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
      <span class="ni-label">Asset Register</span>
      <span class="ni-tip">Fleet Asset Register</span>
    </a>
    <a href="<?= base_url('reports/annual_report'); ?>" class="ni <?= ni_active('reports/annual_report'); ?>" title="Annual Report">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><polyline points="4 20 20 20"/></svg></div>
      <span class="ni-label">Annual Report</span>
      <span class="ni-tip">Annual Performance Report</span>
    </a>
    <a href="<?= base_url('reports/annual_plan'); ?>" class="ni <?= ni_active('reports/annual_plan'); ?>" title="Annual Plan">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h8M8 18h5"/></svg></div>
      <span class="ni-label">Annual Plan</span>
      <span class="ni-tip">Annual Maintenance Plan</span>
    </a>
    <a href="<?= base_url('reports/service_kits'); ?>" class="ni <?= ni_active('reports/service_kits'); ?>" title="Service Kits">
      <div class="ni-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg></div>
      <span class="ni-label">Service Kits</span>
      <span class="ni-tip">Service Kit Recommendations</span>
    </a>
    <?php endif; ?>

    <!-- ── 5. TEAM ── -->
    <?php if ($is_supervisor || $is_technician || $is_manager): ?>
    <div class="nav-sep"></div>
    <div class="nav-section-label">Team</div>

    <!-- Team list — Supervisor & Manager only -->
    <?php if ($is_supervisor || $is_manager): ?>
    <a href="<?= base_url('team'); ?>" class="ni <?= ($uri==='team'||$uri==='team/index')?'on':''; ?>" title="Team">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></div>
      <span class="ni-label">Team</span>
      <span class="ni-tip">Team Members</span>
    </a>
    <?php endif; ?>

    <!-- Attendance — Supervisor, Technician (own), Manager -->
    <a href="<?= base_url('team/attendance'); ?>" class="ni <?= ni_active('team/attendance'); ?>" title="Attendance">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg></div>
      <span class="ni-label"><?= $is_technician ? 'My Attendance' : 'Attendance'; ?></span>
      <span class="ni-tip"><?= $is_technician ? 'My Attendance Records' : 'Attendance Records'; ?></span>
    </a>

    <!-- Shifts — Supervisor, Technician (view), Manager -->
    <a href="<?= base_url('team/shift_roster'); ?>" class="ni <?= ni_active('team/shift'); ?>" title="Shift Roster">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
      <span class="ni-label">Shifts</span>
      <span class="ni-tip">Shift Roster</span>
    </a>

    <!-- Certifications — Supervisor, Technician (own), Manager -->
    <a href="<?= base_url('team/certifications'); ?>" class="ni <?= ni_active('team/certifications'); ?>" title="Certifications">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg></div>
      <span class="ni-label"><?= $is_technician ? 'My Certifications' : 'Certifications'; ?></span>
      <span class="ni-tip"><?= $is_technician ? 'My Certifications' : 'Team Certifications'; ?></span>
    </a>
    <?php endif; ?>

    <!-- Store keeper: show their own attendance link -->
    <?php if ($is_store): ?>
    <div class="nav-sep"></div>
    <div class="nav-section-label">My Record</div>
    <a href="<?= base_url('team/attendance'); ?>" class="ni <?= ni_active('team/attendance'); ?>" title="My Attendance">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg></div>
      <span class="ni-label">My Attendance</span>
      <span class="ni-tip">My Attendance Records</span>
    </a>
    <?php endif; ?>

    <!-- ── 6. ADMIN — Supervisor only ── -->
    <?php if ($is_supervisor): ?>
    <div class="nav-sep"></div>
    <div class="nav-section-label">Admin</div>
    <a href="<?= base_url('users'); ?>" class="ni <?= ni_active('users'); ?>" title="User Management">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div>
      <span class="ni-label">User Management</span>
      <span class="ni-tip">Manage Users</span>
    </a>
    <a href="<?= base_url('settings'); ?>" class="ni <?= ni_active('settings'); ?>" title="Settings">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M5.34 18.66l-1.41 1.41M21 12h-2M5 12H3M18.66 5.34l1.41-1.41M4.93 19.07l1.41-1.41"/></svg></div>
      <span class="ni-label">Settings</span>
      <span class="ni-tip">System Settings</span>
    </a>
    <?php
      // F-19: a throttle that fires silently is a throttle nobody acts on.
      $seo = isset($security_events_open) ? (int)$security_events_open : 0;
    ?>
    <a href="<?= base_url('settings/security'); ?>" class="ni <?= ni_active('settings/security'); ?>" title="Sign-in Security"
       style="position:relative;">
      <div class="ni-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg></div>
      <span class="ni-label">Sign-in Security<?= $seo > 0 ? ' ('.$seo.')' : '' ?></span>
      <span class="ni-tip">Lockouts &amp; password spraying</span>
      <?php if ($seo > 0): ?><span style="position:absolute;top:2px;right:2px;min-width:14px;height:14px;border-radius:7px;background:var(--red);color:#fff;font-size:8.5px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 3px;"><?= $seo ?></span><?php endif; ?>
    </a>
    <?php endif; ?>

    <?php endif; // end !$is_ceo ?>

  </div><!-- /nav-wrap -->
  <div class="sb-user">
    <div class="sb-user-card">
      <div class="sb-avatar"><?= $initials; ?></div>
      <div class="sb-user-info">
        <div class="sb-user-name"><?= htmlspecialchars((string)($full_name)); ?></div>
        <div class="sb-user-role"><?= $role_lbl; ?></div>
      </div>
      <?= form_open('logout', array('style' => 'display:inline-flex;margin:0;')) ?>
        <button type="submit" class="sb-logout" title="Sign out" style="border:0;background:none;padding:0;cursor:pointer;color:inherit;">
          <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </button>
      <?= form_close() ?>
    </div>
  </div>
</nav><!-- /#sidebar -->

<!-- ═══ CANVAS ═══ -->
<div id="canvas">
  <div class="topbar">
    <div class="topbar-row">
      <div>
        <div class="topbar-greeting"><?= $greeting; ?>, <strong><?= htmlspecialchars((string)($fn ?: 'there')); ?></strong> &mdash; <?= date('l, d F Y'); ?></div>
        <div class="page-heading"><?= isset($page_title) ? htmlspecialchars((string)($page_title)) : 'Dashboard'; ?></div>
      </div>
      <div class="topbar-actions">
        <div class="live-pill">
          <div class="sonar"></div>
          <span id="clock-display">--:--:--</span>
        </div>
        <?php if ($is_supervisor && !empty($security_events_open)): ?>
        <a href="<?= base_url('settings/security') ?>" class="live-pill"
           title="<?= (int)$security_events_open ?> sign-in security event(s) awaiting review"
           style="text-decoration:none;color:var(--red);border-color:var(--red);font-weight:700;">
          <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <?= (int)$security_events_open ?> sign-in alert<?= (int)$security_events_open === 1 ? '' : 's' ?>
        </a>
        <?php endif; ?>
        <?php if (!$is_ceo): ?>
        <a href="<?= base_url('spareparts/alerts') ?>" class="live-pill" title="Spare part availability alerts<?= !empty($new_spares_alerts) ? ' — '.(int)$new_spares_alerts.' new' : '' ?>" style="text-decoration:none;position:relative;">
          <svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
          <?php if (!empty($new_spares_alerts)): ?>
          <span style="position:absolute;top:-5px;right:-5px;min-width:16px;height:16px;border-radius:8px;background:var(--red);color:#fff;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 4px;"><?= (int)$new_spares_alerts ?></span>
          <?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if ($is_supervisor || $is_technician): ?>
        <a href="<?= base_url('workorders/create'); ?>" class="btn-cta">
          <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> New WO
        </a>
        <?php endif; ?>
        <div class="tb-user" id="tb-user-pill" onclick="wmsToggleDD(event)">
          <div class="tb-avatar"><?= $initials; ?></div>
          <div>
            <div class="tb-name"><?= htmlspecialchars((string)($full_name)); ?></div>
            <div class="tb-role"><?= $role_lbl; ?></div>
          </div>
          <div class="tb-chevron"><svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></div>
          <div class="tb-dropdown" id="tb-dropdown">
            <div class="tbd-head">
              <div class="tbd-name"><?= htmlspecialchars((string)($full_name)); ?></div>
              <div class="tbd-email"><?= htmlspecialchars((string)($email)); ?></div>
            </div>
            <?php if ($is_supervisor): ?>
            <a href="<?= base_url('users'); ?>" class="tbd-item">
              <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg> Manage Users
            </a>
            <a href="<?= base_url('settings'); ?>" class="tbd-item">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M5.34 18.66l-1.41 1.41M21 12h-2M5 12H3"/></svg> Settings
            </a>
            <div class="tbd-sep"></div>
            <?php endif; ?>
            <a href="<?= base_url('users/profile'); ?>" class="tbd-item">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              My Profile &amp; Signature
            </a>
            <a href="<?= base_url('users/change_password'); ?>" class="tbd-item">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg> Change Password
            </a>
            <div class="tbd-sep"></div>
            <?= form_open('logout', array('style' => 'margin:0;')) ?>
              <button type="submit" class="tbd-item danger" style="width:100%;border:0;background:transparent;text-align:left;cursor:pointer;font:inherit;">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Sign Out
              </button>
            <?= form_close() ?>
          </div>
        </div>
      </div>
    </div>
  </div><!-- /.topbar -->

  <div class="page">
  <?php if ($this->session->flashdata('success')): ?>
  <div class="flash flash-ok"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> <?= $this->session->flashdata('success'); ?></div>
  <?php endif; ?>
  <?php if ($this->session->flashdata('error')): ?>
  <div class="flash flash-err"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> <?= $this->session->flashdata('error'); ?></div>
  <?php endif; ?>

<script>
(function(){
  function tick(){
    var n=new Date(),h=n.getHours(),m=n.getMinutes(),s=n.getSeconds();
    var p=function(v){return v<10?'0'+v:v;};
    var el=document.getElementById('clock-display');
    if(el) el.textContent=p(h)+':'+p(m)+':'+p(s);
  }
  tick(); setInterval(tick,1000);
})();
function wmsToggleDD(e){
  if(e) e.stopPropagation();
  var d=document.getElementById('tb-dropdown');
  if(d) d.classList.toggle('open');
}
document.addEventListener('click',function(e){
  var pill=document.getElementById('tb-user-pill');
  if(pill && !pill.contains(e.target)){
    var d=document.getElementById('tb-dropdown');
    if(d) d.classList.remove('open');
  }
});
(function(){
  var sb=document.getElementById('sidebar');
  var cv=document.getElementById('canvas');
  if(!sb||!cv) return;
  sb.addEventListener('mouseenter',function(){ sb.classList.add('expanded'); cv.style.marginLeft='224px'; });
  sb.addEventListener('mouseleave',function(){ sb.classList.remove('expanded'); cv.style.marginLeft='72px'; });
})();
</script>
