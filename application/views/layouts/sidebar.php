<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('wms_sidebar_route_matches')) {
    function wms_sidebar_route_matches($uri, $route) {
        $uri = trim((string)$uri, '/');
        $route = trim((string)$route, '/');
        return $uri === $route || ($route !== '' && strpos($uri, $route . '/') === 0);
    }
}

$uri = trim((string)uri_string(), '/');
// Keep specific paths before broader controller prefixes so each URL has one
// navigation owner (notably Attendance Sites vs Attendance, Planning targets vs
// Reports, and Sign-in Security vs Settings).
$nav_route_owners = array(
    'attendance/sites'       => 'admin-sites',
    'settings/security'      => 'admin-security',
    'users/profile'          => 'account-profile',
    'users/change_password'  => 'account-password',
    'workspace/maintenance'  => 'maintenance',
    'workorders'             => 'maintenance',
    'equipment'              => 'maintenance',
    'pm'                     => 'maintenance',
    'inspections'            => 'maintenance',
    'backlog'                => 'maintenance',
    'workspace/inventory'    => 'inventory',
    'materials'              => 'inventory',
    'orders'                 => 'inventory',
    'spareparts'             => 'inventory',
    'suppliers'              => 'inventory',
    'lubricants'             => 'inventory',
    'workspace/planning'     => 'planning',
    'forecast'                       => 'planning',
    'reports/weekly_targets'         => 'planning',
    'reports/weekly_targets_save'    => 'planning',
    'reports/weekly_targets_bulk'    => 'planning',
    'reports/weekly_targets_copy'    => 'planning',
    'reports/weekly_targets_delete'  => 'planning',
    'reports'                        => 'reports',
    'workspace/team'         => 'team',
    'team'                   => 'team',
    'attendance'             => 'team',
    'users'                  => 'admin-users',
    'settings'               => 'admin-settings',
    'dashboard'              => 'dashboard',
);
$nav_current_owner = $uri === '' ? 'dashboard' : null;
foreach ($nav_route_owners as $route => $owner) {
    if ($nav_current_owner !== null) break;
    if (wms_sidebar_route_matches($uri, $route)) {
        $nav_current_owner = $owner;
        break;
    }
}
$nav_active = function ($owner) use ($nav_current_owner) {
    return $nav_current_owner === $owner ? 'on' : '';
};
$nav_aria_current = function ($owner, $target_route) use ($nav_current_owner, $uri) {
    if ($nav_current_owner !== $owner) return '';
    $target_route = trim((string)$target_route, '/');
    $current_route = ($uri === '' && $target_route === 'dashboard') ? 'dashboard' : $uri;
    return 'aria-current="' . ($current_route === $target_route ? 'page' : 'location') . '"';
};
$dashboard_active = $nav_active('dashboard');
$maintenance_active = $nav_active('maintenance');
$inventory_active = $nav_active('inventory');
$planning_active = $nav_active('planning');
$reports_active = $nav_active('reports');
$team_active = $nav_active('team');
$users_active = $nav_active('admin-users');
$sites_active = $nav_active('admin-sites');
$settings_active = $nav_active('admin-settings');
$security_active = $nav_active('admin-security');
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

// Role helpers
$is_supervisor = ($role_id == 1);
$is_technician = ($role_id == 2);
$is_store      = ($role_id == 3);
$is_manager    = ($role_id == 4);
$is_ceo        = ($role_id == 5);

// Header state: operational attention is separate from sign-in security.
$is_security      = (strpos($uri, 'settings/security') === 0);
$spare_alert_count = isset($new_spares_alerts) ? (int)$new_spares_alerts : 0;
$security_alert_count = isset($security_events_open) ? (int)$security_events_open : 0;
$attention = isset($operational_attention) && is_array($operational_attention)
    ? $operational_attention : array('groups' => array(), 'count' => $spare_alert_count);
$attention_groups = isset($attention['groups']) && is_array($attention['groups']) ? $attention['groups'] : array();
$attention_count = isset($attention['count']) ? (int)$attention['count'] : $spare_alert_count;
// Direct layout renders and a defensive model fallback still retain the old
// parts destination rather than leaving a badge with nowhere useful to go.
if (empty($attention_groups) && $spare_alert_count > 0) {
    $attention_groups[] = array(
        'key' => 'parts', 'label' => 'Parts newly available', 'count' => $spare_alert_count,
        'tone' => 'success', 'url' => base_url('spareparts/alerts?status=New'), 'items' => array(),
    );
    $attention_count = max($attention_count, $spare_alert_count);
}
$attention_badge = $attention_count > 99 ? '99+' : (string)$attention_count;
$attention_label = $attention_count > 0
    ? 'Operational attention: ' . $attention_count . ' actionable exception' . ($attention_count === 1 ? '' : 's')
    : 'Operational attention: no actionable exceptions';
$security_alert_label = $security_alert_count . ' security event' . ($security_alert_count === 1 ? '' : 's') . ' to review';
?>
<!-- ═══ SIDEBAR ═══ -->
<nav id="sidebar" aria-label="Primary navigation">
  <button type="button" class="sb-toggle" id="sidebar-toggle"
          aria-label="Expand navigation" aria-expanded="false" aria-controls="sidebar" title="Expand navigation">
    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
    <span class="sb-toggle-label">Expand navigation</span>
  </button>
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
    <a href="<?= base_url('dashboard'); ?>" class="ni <?= $dashboard_active; ?>"
       <?= $nav_aria_current('dashboard', 'dashboard'); ?> title="<?= $is_ceo ? 'Executive Summary' : 'Dashboard' ?>">
      <div class="ni-icon"><svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></div>
      <span class="ni-label"><?= $is_ceo ? 'Executive Summary' : 'Dashboard' ?></span>
    </a>

    <?php if (!$is_ceo): // CEO gets the Executive Summary only — everything below is operational detail ?>

    <!-- ── 2. MAINTENANCE — Supervisor, Technician, Manager ── -->
    <?php if ($is_supervisor || $is_technician || $is_manager): ?>
    <div class="nav-sep"></div>
    <div class="nav-section-label">Maintenance</div>
    <a href="<?= base_url('workspace/maintenance'); ?>" class="ni <?= $maintenance_active; ?>"
       <?= $nav_aria_current('maintenance', 'workspace/maintenance'); ?> title="Maintenance">
      <div class="ni-icon"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg></div>
      <span class="ni-label">Maintenance</span>
    </a>
    <?php endif; ?>

    <!-- ── 3. INVENTORY — all non-CEO roles; destinations remain role-filtered ── -->
    <div class="nav-sep"></div>
    <div class="nav-section-label">Inventory</div>
    <a href="<?= base_url('workspace/inventory'); ?>" class="ni <?= $inventory_active; ?>"
       <?= $nav_aria_current('inventory', 'workspace/inventory'); ?> title="Inventory">
      <div class="ni-icon"><svg aria-hidden="true" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></div>
      <span class="ni-label">Inventory</span>
    </a>

    <!-- ── 3b. PLANNING — Supervisor, Manager ─────────────────────────
         Planning links preserve the existing Forecast controller access boundary. -->
    <?php if ($is_supervisor || $is_manager): ?>
    <div class="nav-sep"></div>
    <div class="nav-section-label">Planning</div>
    <a href="<?= base_url('workspace/planning'); ?>" class="ni <?= $planning_active; ?>"
       <?= $nav_aria_current('planning', 'workspace/planning'); ?> title="Planning">
      <div class="ni-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></div>
      <span class="ni-label">Planning</span>
    </a>
    <?php endif; ?>

    <!-- ── 4. REPORTS — Supervisor, Manager ── -->
    <?php if ($is_supervisor || $is_manager): ?>
    <div class="nav-sep"></div>
    <div class="nav-section-label">Reports</div>
    <a href="<?= base_url('reports'); ?>" class="ni <?= $reports_active; ?>"
       <?= $nav_aria_current('reports', 'reports'); ?> title="Reports">
      <div class="ni-icon"><svg aria-hidden="true" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
      <span class="ni-label">Reports</span>
    </a>
    <?php endif; ?>

    <!-- ── 5. TEAM / MY RECORD — role-filtered hub ── -->
    <?php if ($is_supervisor || $is_technician || $is_store || $is_manager): ?>
    <div class="nav-sep"></div>
    <div class="nav-section-label"><?= $is_store ? 'My Record' : 'Team'; ?></div>
    <a href="<?= base_url('workspace/team'); ?>" class="ni <?= $team_active; ?>"
       <?= $nav_aria_current('team', 'workspace/team'); ?> title="<?= $is_store ? 'My Record' : 'Team'; ?>">
      <div class="ni-icon"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></div>
      <span class="ni-label"><?= $is_store ? 'My Record' : 'Team'; ?></span>
    </a>
    <?php endif; ?>

    <!-- ── 6. ADMIN — Supervisor only ── -->
    <?php if ($is_supervisor): ?>
    <div class="nav-sep"></div>
    <div class="nav-section-label">Admin</div>
    <a href="<?= base_url('users'); ?>" class="ni <?= $users_active; ?>"
       <?= $nav_aria_current('admin-users', 'users'); ?> title="User Management">
      <div class="ni-icon"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div>
      <span class="ni-label">User Management</span>
    </a>
    <a href="<?= base_url('attendance/sites'); ?>" class="ni <?= $sites_active; ?>"
       <?= $nav_aria_current('admin-sites', 'attendance/sites'); ?> title="Attendance Sites">
      <div class="ni-icon"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 21s7-6.1 7-11a7 7 0 10-14 0c0 4.9 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg></div>
      <span class="ni-label">Attendance Sites</span>
    </a>
    <a href="<?= base_url('settings'); ?>" class="ni <?= $settings_active; ?>"
       <?= $nav_aria_current('admin-settings', 'settings'); ?> title="Settings">
      <div class="ni-icon"><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M5.34 18.66l-1.41 1.41M21 12h-2M5 12H3M18.66 5.34l1.41-1.41M4.93 19.07l1.41-1.41"/></svg></div>
      <span class="ni-label">Settings</span>
    </a>
    <?php
      // F-19: a throttle that fires silently is a throttle nobody acts on.
      $seo = isset($security_events_open) ? (int)$security_events_open : 0;
    ?>
    <a href="<?= base_url('settings/security'); ?>" class="ni <?= $security_active; ?>"
       <?= $nav_aria_current('admin-security', 'settings/security'); ?> title="Sign-in Security"
       style="position:relative;">
      <div class="ni-icon"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg></div>
      <span class="ni-label">Sign-in Security<?= $seo > 0 ? ' ('.$seo.')' : '' ?></span>
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
<div class="sidebar-backdrop" id="sidebar-backdrop" aria-hidden="true" hidden></div>

<!-- ═══ CANVAS ═══ -->
<div id="canvas">
  <header class="topbar">
    <div class="topbar-row">
      <div class="topbar-actions">
        <button type="button" class="sidebar-menu-trigger" id="sidebar-menu-trigger"
                aria-label="Open primary navigation" aria-expanded="false" aria-controls="sidebar" title="Open navigation">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
          <span>Menu</span>
        </button>
        <?php if (!$is_ceo): ?>
        <button type="button" class="global-find-trigger" id="global-find-trigger"
                data-search-url="<?= htmlspecialchars((string)base_url('search'), ENT_QUOTES, 'UTF-8'); ?>"
                aria-label="Find records, Control or Command K" aria-haspopup="dialog"
                aria-expanded="false" aria-controls="global-find" title="Find records · Ctrl/⌘ K">
          <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>
          <span class="global-find-trigger-label">Find records</span>
          <kbd class="global-find-shortcut" aria-hidden="true"><span data-find-modifier>Ctrl</span> K</kbd>
        </button>
        <?php endif; ?>
        <?php if ($is_supervisor && $security_alert_count > 0 && !$is_security): ?>
        <a href="<?= base_url('settings/security') ?>" class="live-pill security-pill"
           aria-label="<?= htmlspecialchars((string)$security_alert_label, ENT_QUOTES, 'UTF-8'); ?>"
           title="<?= htmlspecialchars((string)$security_alert_label, ENT_QUOTES, 'UTF-8'); ?>"
           style="text-decoration:none;color:var(--red);border-color:var(--red);font-weight:700;">
          <svg aria-hidden="true" viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span class="security-pill-count"><?= $security_alert_count ?></span>
          <span class="security-pill-label">security event<?= $security_alert_count === 1 ? '' : 's' ?> to review</span>
        </a>
        <?php endif; ?>
        <?php if (!$is_ceo): ?>
        <details class="operational-attention" id="operational-attention">
          <summary class="live-pill attention-trigger" id="attention-trigger"
                   aria-label="<?= htmlspecialchars((string)$attention_label, ENT_QUOTES, 'UTF-8'); ?>"
                   title="<?= htmlspecialchars((string)$attention_label, ENT_QUOTES, 'UTF-8'); ?>">
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            <?php if ($attention_count > 0): ?><span class="utility-count" aria-hidden="true"><?= $attention_badge ?></span><?php endif; ?>
          </summary>
          <div class="attention-panel" role="region" aria-label="Operational attention">
            <div class="attention-head">
              <h2 class="attention-title">Operational attention</h2>
              <div class="attention-subtitle">Actionable exceptions in your queues</div>
            </div>
            <?php if (empty($attention_groups)): ?>
            <div class="attention-empty">
              <strong>No actionable exceptions</strong>
              <span>Your operational queues are clear.</span>
            </div>
            <?php else: ?>
              <?php foreach ($attention_groups as $group): ?>
              <section class="attention-group">
                <div class="attention-group-head">
                  <h3 class="attention-group-label attention-tone-<?= htmlspecialchars((string)(isset($group['tone']) ? $group['tone'] : 'neutral'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?= htmlspecialchars((string)$group['label']); ?> <span class="attention-group-count"><?= (int)$group['count'] ?></span>
                  </h3>
                  <a href="<?= htmlspecialchars((string)$group['url'], ENT_QUOTES, 'UTF-8'); ?>" class="attention-view-all">
                    <?= !empty($group['action']) ? htmlspecialchars((string)$group['action']) : 'View all'; ?>
                  </a>
                </div>
                <?php if (!empty($group['items'])): ?>
                <div class="attention-items">
                  <?php foreach ($group['items'] as $item): ?>
                  <a href="<?= htmlspecialchars((string)$item['url'], ENT_QUOTES, 'UTF-8'); ?>" class="attention-item">
                    <span class="attention-item-line">
                      <strong><?= htmlspecialchars((string)$item['label']); ?></strong>
                      <?php if (!empty($item['meta'])): ?><span class="attention-item-meta"><?= htmlspecialchars((string)$item['meta']); ?></span><?php endif; ?>
                    </span>
                    <?php if (!empty($item['detail'])): ?><span class="attention-item-detail"><?= htmlspecialchars((string)$item['detail']); ?></span><?php endif; ?>
                  </a>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </section>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </details>
        <?php endif; ?>
        <div class="tb-user" id="tb-user-pill">
          <button type="button" class="tb-user-trigger" id="tb-user-trigger"
                  aria-label="Account menu" aria-expanded="false" aria-controls="tb-dropdown">
            <span class="tb-avatar" aria-hidden="true"><?= $initials; ?></span>
            <span class="tb-user-copy">
              <span class="tb-name"><?= htmlspecialchars((string)($full_name)); ?></span>
              <span class="tb-role"><?= $role_lbl; ?></span>
            </span>
            <span class="tb-chevron" aria-hidden="true"><svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></span>
          </button>
          <div class="tb-dropdown" id="tb-dropdown" role="group" aria-label="Account actions">
            <div class="tbd-head">
              <div class="tbd-name"><?= htmlspecialchars((string)($full_name)); ?></div>
              <div class="tbd-email"><?= htmlspecialchars((string)($email)); ?></div>
            </div>
            <?php if ($is_supervisor): ?>
            <a href="<?= base_url('users'); ?>" class="tbd-item">
              <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg> Manage Users
            </a>
            <a href="<?= base_url('settings'); ?>" class="tbd-item">
              <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M5.34 18.66l-1.41 1.41M21 12h-2M5 12H3"/></svg> Settings
            </a>
            <div class="tbd-sep"></div>
            <?php endif; ?>
            <?php if (!$is_ceo): ?>
            <a href="<?= base_url('users/profile'); ?>" class="tbd-item"
               <?= $nav_aria_current('account-profile', 'users/profile'); ?>>
              <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              My Profile &amp; Signature
            </a>
            <a href="<?= base_url('users/change_password'); ?>" class="tbd-item"
               <?= $nav_aria_current('account-password', 'users/change_password'); ?>>
              <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg> Change Password
            </a>
            <?php endif; ?>
            <div class="tbd-sep"></div>
            <?= form_open('logout', array('style' => 'margin:0;')) ?>
              <button type="submit" class="tbd-item danger" style="width:100%;border:0;background:transparent;text-align:left;cursor:pointer;font:inherit;">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Sign Out
              </button>
            <?= form_close() ?>
          </div>
        </div>
      </div>
    </div>
  </header><!-- /.topbar -->

<?php if (!$is_ceo): ?>
<script src="<?= base_url('assets/js/global-find.js') ?>" defer></script>
<?php endif; ?>

  <main class="page" id="main-content">
  <?php if ($this->session->flashdata('success')): ?>
  <div class="flash flash-ok"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> <?= $this->session->flashdata('success'); ?></div>
  <?php endif; ?>
  <?php if ($this->session->flashdata('error')): ?>
  <div class="flash flash-err"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> <?= $this->session->flashdata('error'); ?></div>
  <?php endif; ?>

<script>
(function(){
  var disclosure=document.getElementById('operational-attention');
  if(!disclosure) return;
  var summary=disclosure.querySelector('summary');

  function closeAttention(restoreFocus){
    disclosure.removeAttribute('open');
    if(restoreFocus && summary) summary.focus();
  }

  document.addEventListener('click',function(e){
    if(disclosure.hasAttribute('open') && !disclosure.contains(e.target)) closeAttention(false);
  });
  document.addEventListener('keydown',function(e){
    if(e.key==='Escape' && disclosure.hasAttribute('open')){
      e.preventDefault();
      closeAttention(true);
    }
  });
  disclosure.addEventListener('focusout',function(e){
    if(!disclosure.contains(e.relatedTarget)) closeAttention(false);
  });
  if(summary){
    summary.addEventListener('keydown',function(e){
      if(e.key==='ArrowDown'){
        e.preventDefault();
        disclosure.setAttribute('open','');
        var first=disclosure.querySelector('.attention-panel a[href]');
        if(first) first.focus();
      }
    });
  }
})();
(function(){
  var pill=document.getElementById('tb-user-pill');
  var trigger=document.getElementById('tb-user-trigger');
  var dropdown=document.getElementById('tb-dropdown');
  if(!pill||!trigger||!dropdown) return;

  function closeDropdown(restoreFocus){
    dropdown.classList.remove('open');
    trigger.setAttribute('aria-expanded','false');
    if(restoreFocus) trigger.focus();
  }

  trigger.addEventListener('click',function(e){
    e.stopPropagation();
    var attention=document.getElementById('operational-attention');
    if(attention) attention.removeAttribute('open');
    var open=!dropdown.classList.contains('open');
    dropdown.classList.toggle('open',open);
    trigger.setAttribute('aria-expanded',open?'true':'false');

    // Keyboard activation benefits from moving into the disclosed links;
    // pointer users keep their current focus and visual context.
    if(open && e.detail===0){
      var first=dropdown.querySelector('a[href],button');
      if(first) first.focus();
    }
  });

  document.addEventListener('click',function(e){
    if(!pill.contains(e.target)){
      closeDropdown(dropdown.contains(document.activeElement));
    }
  });
  document.addEventListener('keydown',function(e){
    if(e.key==='Escape' && dropdown.classList.contains('open')){
      e.preventDefault();
      closeDropdown(true);
    }
  });
  pill.addEventListener('focusout',function(e){
    if(!pill.contains(e.relatedTarget)) closeDropdown(false);
  });
})();
(function(){
  var sb=document.getElementById('sidebar');
  var canvas=document.getElementById('canvas');
  var toggle=document.getElementById('sidebar-toggle');
  var menuTrigger=document.getElementById('sidebar-menu-trigger');
  var backdrop=document.getElementById('sidebar-backdrop');
  if(!sb||!canvas||!toggle||!menuTrigger||!backdrop) return;

  var media=window.matchMedia('(max-width: 760px)');
  var wasMobile=media.matches;
  var manuallyExpanded=false;
  var focusExpanded=false;
  var drawerOpen=false;
  var originalCanvasAriaHidden=canvas.getAttribute('aria-hidden');
  var originalCanvasInert=canvas.hasAttribute('inert');
  var toggleLabel=toggle.querySelector('.sb-toggle-label');

  function setCanvasInert(inert){
    if(inert){
      canvas.setAttribute('inert','');
      canvas.setAttribute('aria-hidden','true');
      return;
    }
    if(!originalCanvasInert) canvas.removeAttribute('inert');
    if(originalCanvasAriaHidden===null) canvas.removeAttribute('aria-hidden');
    else canvas.setAttribute('aria-hidden',originalCanvasAriaHidden);
  }

  function setToggleState(open,mobile){
    var label=mobile ? (open?'Close navigation':'Expand navigation') : (open?'Collapse navigation':'Expand navigation');
    toggle.setAttribute('aria-expanded',open?'true':'false');
    toggle.setAttribute('aria-label',label);
    toggle.setAttribute('title',label);
    if(toggleLabel) toggleLabel.textContent=label;
    menuTrigger.setAttribute('aria-expanded',mobile&&open?'true':'false');
    menuTrigger.setAttribute('aria-label',mobile&&open?'Close primary navigation':'Open primary navigation');
    menuTrigger.setAttribute('title',mobile&&open?'Close navigation':'Open navigation');
  }

  function applyDesktopState(){
    sb.removeAttribute('inert');
    sb.removeAttribute('aria-hidden');
    backdrop.hidden=true;
    setCanvasInert(false);
    var open=manuallyExpanded||focusExpanded;
    sb.classList.toggle('expanded',open);
    setToggleState(open,false);
  }

  function applyMobileClosedState(){
    drawerOpen=false;
    sb.classList.remove('expanded');
    sb.setAttribute('inert','');
    sb.setAttribute('aria-hidden','true');
    backdrop.hidden=true;
    setCanvasInert(false);
    setToggleState(false,true);
  }

  function openDrawer(){
    drawerOpen=true;
    sb.removeAttribute('inert');
    sb.removeAttribute('aria-hidden');
    sb.classList.add('expanded');
    backdrop.hidden=false;
    setToggleState(true,true);
    // Move focus into the drawer before making the underlying canvas inert.
    toggle.focus();
    setCanvasInert(true);
  }

  function closeDrawer(restoreFocus){
    drawerOpen=false;
    sb.classList.remove('expanded');
    sb.setAttribute('inert','');
    sb.setAttribute('aria-hidden','true');
    backdrop.hidden=true;
    setCanvasInert(false);
    setToggleState(false,true);
    if(restoreFocus) menuTrigger.focus();
  }

  function focusableItems(){
    return Array.prototype.slice.call(sb.querySelectorAll('a[href],button:not([disabled]),[tabindex]:not([tabindex="-1"])'))
      .filter(function(item){ return !item.hasAttribute('hidden') && item.getClientRects().length>0; });
  }

  toggle.addEventListener('click',function(){
    if(media.matches){
      if(drawerOpen) closeDrawer(true);
      return;
    }
    var open=!sb.classList.contains('expanded');
    manuallyExpanded=open;
    focusExpanded=false;
    applyDesktopState();
  });

  menuTrigger.addEventListener('click',function(){
    if(!media.matches) return;
    if(drawerOpen) closeDrawer(true);
    else openDrawer();
  });

  backdrop.addEventListener('click',function(){
    if(drawerOpen) closeDrawer(true);
  });

  sb.addEventListener('focusin',function(e){
    if(media.matches || e.target===toggle) return;
    focusExpanded=true;
    applyDesktopState();
  });
  sb.addEventListener('focusout',function(e){
    if(media.matches || sb.contains(e.relatedTarget)) return;
    focusExpanded=false;
    applyDesktopState();
  });

  document.addEventListener('keydown',function(e){
    if(e.defaultPrevented) return;
    if(e.key==='Escape'){
      if(media.matches && drawerOpen){
        e.preventDefault();
        closeDrawer(true);
      } else if(!media.matches && sb.classList.contains('expanded')){
        e.preventDefault();
        manuallyExpanded=false;
        focusExpanded=false;
        applyDesktopState();
        if(sb.contains(document.activeElement)) toggle.focus();
      }
      return;
    }

    if(!media.matches || !drawerOpen || e.key!=='Tab') return;
    var items=focusableItems();
    if(!items.length){
      e.preventDefault();
      toggle.focus();
      return;
    }
    var first=items[0];
    var last=items[items.length-1];
    if(e.shiftKey && (document.activeElement===first || !sb.contains(document.activeElement))){
      e.preventDefault();
      last.focus();
    } else if(!e.shiftKey && (document.activeElement===last || !sb.contains(document.activeElement))){
      e.preventDefault();
      first.focus();
    }
  });

  function handleViewportChange(e){
    var mobile=e.matches;
    if(mobile===wasMobile) return;
    var focusWasInSidebar=sb.contains(document.activeElement);
    var triggerHadFocus=document.activeElement===menuTrigger;
    wasMobile=mobile;
    manuallyExpanded=false;
    focusExpanded=false;
    drawerOpen=false;
    if(mobile){
      if(focusWasInSidebar) menuTrigger.focus();
      applyMobileClosedState();
    } else {
      applyDesktopState();
      if(focusWasInSidebar||triggerHadFocus) toggle.focus();
    }
  }
  if(media.addEventListener) media.addEventListener('change',handleViewportChange);
  else if(media.addListener) media.addListener(handleViewportChange);

  if(media.matches) applyMobileClosedState();
  else applyDesktopState();
})();
(function(){
  var cv=document.getElementById('canvas');
  var topbar=cv ? cv.querySelector('.topbar') : null;
  if(!cv||!topbar) return;

  var isScrolled=null;
  function syncTopbarElevation(){
    var next=cv.scrollTop>0;
    if(next===isScrolled) return;
    topbar.classList.toggle('is-scrolled',next);
    isScrolled=next;
  }

  cv.addEventListener('scroll',syncTopbarElevation,{passive:true});
  syncTopbarElevation();
})();
</script>
