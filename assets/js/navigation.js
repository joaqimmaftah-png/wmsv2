/* Shared navigation shell. No hover-only expansion; native details work without JS. */
(function () {
  'use strict';
  var sidebar = document.getElementById('sidebar');
  var toggle = document.getElementById('nav-toggle');
  var close = document.getElementById('nav-close');
  var backdrop = document.getElementById('nav-backdrop');
  var canvas = document.getElementById('canvas');
  if (!sidebar || !toggle || !canvas) return;

  var mobile = window.matchMedia('(max-width: 760px)');
  // Dense registers (attendance, work orders, inventory) need the full canvas at
  // laptop widths. Below 1600px the shell therefore starts on the icon rail —
  // exactly the width those tables were designed for — and the labelled rail is
  // one explicit click away. An explicit choice always wins and persists.
  var compact = window.matchMedia('(min-width: 761px) and (max-width: 1599px)');
  var main = document.getElementById('main-content');
  if (main) main.setAttribute('tabindex', '-1');
  var storageKey = 'wms.nav.collapsed';
  var groupKey = 'wms.nav.groups.' + (sidebar.getAttribute('data-role') || 'user');
  var groups = Array.prototype.slice.call(sidebar.querySelectorAll('[data-nav-group]'));

  function read(key, fallback) {
    try { var value = window.localStorage.getItem(key); return value === null ? fallback : value; }
    catch (error) { return fallback; }
  }
  function write(key, value) {
    try { window.localStorage.setItem(key, value); } catch (error) { /* Private browsing remains usable. */ }
  }
  function isMobile() { return mobile.matches; }
  function storedChoice() {
    var value = read(storageKey, null);
    return value === null ? null : value === '1';
  }
  function defaultCollapsed() { return compact.matches; }
  function setCollapsed(collapsed, persist) {
    document.body.classList.toggle('nav-collapsed', collapsed);
    if (!isMobile()) {
      toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      toggle.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
    }
    groups.forEach(syncGroup);
    if (persist) write(storageKey, collapsed ? '1' : '0');
  }
  function syncGroup(group) {
    var summary = group.querySelector('summary');
    if (summary) summary.setAttribute('aria-expanded', (group.open && (!document.body.classList.contains('nav-collapsed') || isMobile())) ? 'true' : 'false');
  }
  function openDrawer() {
    document.body.classList.remove('nav-collapsed');
    document.body.classList.add('nav-open');
    sidebar.setAttribute('role', 'dialog');
    sidebar.setAttribute('aria-modal', 'true');
    backdrop.hidden = false;
    canvas.setAttribute('inert', '');
    toggle.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-label', 'Close navigation');
    var current = sidebar.querySelector('[aria-current="page"]');
    (current || close || sidebar.querySelector('a,button,summary')).focus();
  }
  function closeDrawer(restoreFocus) {
    document.body.classList.remove('nav-open');
    sidebar.removeAttribute('role');
    sidebar.removeAttribute('aria-modal');
    backdrop.hidden = true;
    canvas.removeAttribute('inert');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Open navigation');
    if (restoreFocus) toggle.focus();
  }
  function syncViewport() {
    if (isMobile()) {
      closeDrawer(false);
      document.body.classList.remove('nav-collapsed');
    } else {
      if (document.body.classList.contains('nav-open')) closeDrawer(false);
      var choice = storedChoice();
      setCollapsed(choice === null ? defaultCollapsed() : choice, false);
    }
  }

  // The current destination is always visible. Other manually chosen states may persist.
  var saved = {};
  try { saved = JSON.parse(read(groupKey, '{}')) || {}; } catch (error) { saved = {}; }
  groups.forEach(function (group) {
    var key = group.getAttribute('data-nav-group');
    var current = group.classList.contains('is-current');
    group.open = current || saved[key] === true;
    syncGroup(group);
    group.addEventListener('toggle', function () { syncGroup(group); });
    var summary = group.querySelector('summary');
    summary.addEventListener('click', function (event) {
      if (!isMobile() && document.body.classList.contains('nav-collapsed')) {
        event.preventDefault();
        setCollapsed(false, true);
        group.open = true;
        syncGroup(group);
      }
    });
    summary.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' && group.open) {
        var first = group.querySelector('.nav-group-panel a');
        if (first) { event.preventDefault(); first.focus(); }
      }
    });
  });
  function saveGroups() {
    var state = {};
    groups.forEach(function (group) { state[group.getAttribute('data-nav-group')] = group.open; });
    write(groupKey, JSON.stringify(state));
  }
  groups.forEach(function (group) { group.addEventListener('toggle', saveGroups); });

  toggle.addEventListener('click', function () {
    if (isMobile()) {
      if (document.body.classList.contains('nav-open')) closeDrawer(true); else openDrawer();
    } else {
      setCollapsed(!document.body.classList.contains('nav-collapsed'), true);
    }
  });
  if (close) close.addEventListener('click', function () { closeDrawer(true); });
  if (backdrop) backdrop.addEventListener('click', function () { closeDrawer(true); });
  document.addEventListener('keydown', function (event) {
    if (!isMobile() || !document.body.classList.contains('nav-open')) return;
    if (event.key === 'Escape') { event.preventDefault(); closeDrawer(true); return; }
    if (event.key !== 'Tab') return;
    var focusable = Array.prototype.filter.call(sidebar.querySelectorAll('a[href],button,summary'), function (node) {
      return node.getClientRects().length > 0 && !node.hasAttribute('disabled');
    });
    if (!focusable.length) return;
    var first = focusable[0], last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  sidebar.addEventListener('click', function (event) {
    var link = event.target.closest ? event.target.closest('a[href]') : null;
    if (link && isMobile()) closeDrawer(false);
  });
  if (mobile.addEventListener) {
    mobile.addEventListener('change', syncViewport);
    compact.addEventListener('change', syncViewport);
  } else {
    mobile.addListener(syncViewport);
    compact.addListener(syncViewport);
  }
  syncViewport();
  // Keep the first viewport sync instant. Dense registers measure their table
  // width during load; animating 240px→68px overflows them before the rail settles.
  // Two frames let the initial geometry paint before user-driven motion is enabled.
  if (window.requestAnimationFrame) {
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () { document.body.classList.remove('nav-boot'); });
    });
  } else {
    document.body.classList.remove('nav-boot');
  }
})();
