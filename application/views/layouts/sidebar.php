<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$uri       = uri_string();
$active_nav = wms_nav_active($uri, isset($role_id) ? (int)$role_id : 4);
$nav_tree  = wms_nav_tree(isset($role_id) ? (int)$role_id : 4);
$role_id   = isset($role_id) ? (int)$role_id : 4;

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
<nav id="sidebar" aria-label="Primary navigation" data-role="<?= (int)$role_id ?>">
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
  <button type="button" class="nav-close" id="nav-close" aria-label="Close navigation">
    <?= wms_nav_icon('close') ?>
  </button>
  <div class="nav-wrap">
    <?php foreach ($nav_tree['pinned'] as $item):
      $is_current = ($active_nav['key'] === $item['key']); ?>
    <a href="<?= base_url($item['path']) ?>" class="ni ni-pinned<?= $is_current ? ' on' : '' ?>"
       title="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"<?= $is_current ? ' aria-current="page"' : '' ?>>
      <span class="ni-icon"><?= wms_nav_icon($item['icon']) ?></span>
      <span class="ni-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
      <span class="ni-tip" aria-hidden="true"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
    </a>
    <?php endforeach; ?>

    <?php foreach ($nav_tree['groups'] as $group):
      $group_active = ($active_nav['group'] === $group['key']);
      $group_count = 0;
      if ($group['key'] === 'inventory') $group_count = $spare_alert_count;
      if ($group['key'] === 'administration') $group_count = $security_alert_count;
      $panel_id = 'nav-group-' . $group['key']; ?>
    <details class="nav-group<?= $group_active ? ' is-current' : '' ?>" data-nav-group="<?= $group['key'] ?>" open>
      <summary class="nav-group-toggle" aria-expanded="true" aria-controls="<?= $panel_id ?>"
               title="<?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?>">
        <span class="ni-icon"><?= wms_nav_icon($group['icon']) ?></span>
        <span class="nav-group-label"><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($group_count > 0):
          $group_badge_text = $group['key'] === 'inventory' ? 'new parts-availability alerts' : 'security events to review'; ?>
        <span class="nav-badge" aria-hidden="true"><?= (int)$group_count ?></span><span class="sr-only"><?= (int)$group_count ?> <?= $group_badge_text ?></span><?php endif; ?>
        <span class="nav-group-chevron"><?= wms_nav_icon('chevron') ?></span>
        <span class="ni-tip" aria-hidden="true"><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></span>
      </summary>
      <div class="nav-group-panel" id="<?= $panel_id ?>">
        <?php foreach ($group['items'] as $item):
          $is_current = ($active_nav['key'] === $item['key']);
          $badge = ($item['key'] === 'workorder_parts') ? $spare_alert_count : (($item['key'] === 'security') ? $security_alert_count : 0);
          $badge_text = $item['key'] === 'workorder_parts' ? 'new parts-availability alerts' : 'security events to review'; ?>
        <a href="<?= base_url($item['path']) ?>" class="ni<?= $is_current ? ' on' : '' ?>"
           title="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"<?= $is_current ? ' aria-current="page"' : '' ?>>
          <span class="ni-icon"><?= wms_nav_icon($item['icon']) ?></span>
          <span class="ni-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
          <?php if ($badge > 0): ?><span class="nav-badge" aria-hidden="true"><?= (int)$badge ?></span><span class="sr-only"><?= (int)$badge ?> <?= $badge_text ?></span><?php endif; ?>
          <span class="ni-tip" aria-hidden="true"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endforeach; ?>
  </div><!-- /nav-wrap -->
</nav><!-- /#sidebar -->

<!-- ═══ CANVAS ═══ -->
<div class="nav-backdrop" id="nav-backdrop" hidden></div>
<div id="canvas">
  <header class="topbar">
    <div class="topbar-row">
      <div class="topbar-actions">
        <button type="button" class="nav-toggle" id="nav-toggle" aria-controls="sidebar" aria-expanded="true" aria-label="Collapse navigation">
          <?= wms_nav_icon('menu') ?><span class="nav-toggle-label">Menu</span>
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
            <?php if (!$is_ceo): ?>
            <a href="<?= base_url('users/profile'); ?>" class="tbd-item">
              <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              My Profile &amp; Signature
            </a>
            <a href="<?= base_url('users/change_password'); ?>" class="tbd-item">
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

<script src="<?= base_url('assets/js/navigation.js') ?>" defer></script>
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
