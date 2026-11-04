// Dependency-free structural checks for the shared sidebar disclosure and current-location behavior.
// Run with: node tests/sidebar_accessibility.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const sidebar = read('application/views/layouts/sidebar.php');
const header = read('application/views/layouts/header.php');

assert.ok(sidebar.includes('<nav id="sidebar" aria-label="Primary navigation">'),
  'the sidebar remains a named navigation landmark');
assert.ok(sidebar.includes('id="sidebar-toggle"') &&
          sidebar.includes('aria-expanded="false" aria-controls="sidebar"'),
  'the desktop/sidebar disclosure has an explicit labelled control and initial ARIA state');
assert.ok(sidebar.includes('id="sidebar-menu-trigger"') &&
          sidebar.includes('aria-label="Open primary navigation" aria-expanded="false" aria-controls="sidebar"'),
  'the narrow-screen drawer has a visible, labelled menu trigger');
assert.ok(sidebar.includes('id="sidebar-backdrop"') && sidebar.includes('aria-hidden="true" hidden'),
  'the mobile drawer has a non-semantic backdrop that starts closed');
const toggleStart = sidebar.indexOf('id="sidebar-toggle"');
const toggleEnd = sidebar.indexOf('</button>', toggleStart);
const toggleMarkup = sidebar.slice(toggleStart, toggleEnd);
assert.ok(toggleMarkup.includes('aria-label="Expand navigation"') &&
          toggleMarkup.includes('aria-expanded="false"') &&
          toggleMarkup.includes('aria-controls="sidebar"'),
  'the collapsed sidebar toggle has an accessible name and disclosure state');

const routeOwnerText = sidebar.split('$nav_route_owners = array(')[1]?.split(');')[0];
assert.ok(routeOwnerText, 'an explicit route-to-sidebar-owner table is defined');
const routeOwners = Array.from(routeOwnerText.matchAll(/'([^']+)'\s*=>\s*'([^']+)'/g), match => [match[1], match[2]]);
assert.deepEqual(routeOwners, [
  ['attendance/sites', 'admin-sites'],
  ['settings/security', 'admin-security'],
  ['users/profile', 'account-profile'],
  ['users/change_password', 'account-password'],
  ['workspace/maintenance', 'maintenance'],
  ['workorders', 'maintenance'],
  ['equipment', 'maintenance'],
  ['pm', 'maintenance'],
  ['inspections', 'maintenance'],
  ['backlog', 'maintenance'],
  ['workspace/inventory', 'inventory'],
  ['materials', 'inventory'],
  ['orders', 'inventory'],
  ['spareparts', 'inventory'],
  ['suppliers', 'inventory'],
  ['lubricants', 'inventory'],
  ['workspace/planning', 'planning'],
  ['forecast', 'planning'],
  ['reports', 'reports'],
  ['workspace/team', 'team'],
  ['team', 'team'],
  ['attendance', 'team'],
  ['users', 'admin-users'],
  ['settings', 'admin-settings'],
  ['dashboard', 'dashboard'],
], 'the route-owner table explicitly covers each existing sidebar destination');
assert.ok(sidebar.includes("$uri === $route || ($route !== '' && strpos($uri, $route . '/') === 0)"),
  'route ownership matches complete path segments instead of ambiguous raw prefixes');
const ruleIndex = route => routeOwners.findIndex(([path]) => path === route);
assert.ok(ruleIndex('attendance/sites') < ruleIndex('attendance'),
  'specific Attendance Sites routing wins over the broader Attendance owner');
assert.ok(ruleIndex('settings/security') < ruleIndex('settings'),
  'specific Sign-in Security routing wins over the broader Settings owner');
assert.ok(ruleIndex('users/profile') < ruleIndex('users') &&
          ruleIndex('users/change_password') < ruleIndex('users'),
  'account menu routes win over the broader User Management prefix');
const resolveOwner = uri => uri === '' ? 'dashboard' :
  (routeOwners.find(([route]) => uri === route || uri.startsWith(`${route}/`)) || [null, null])[1];
for (const [uri, owner] of [
  ['', 'dashboard'], ['dashboard', 'dashboard'], ['workspace/maintenance', 'maintenance'],
  ['workorders/view/12', 'maintenance'], ['materials/inventory', 'inventory'],
  ['forecast/plan_vs_actual', 'planning'], ['reports/annual_report', 'reports'],
  ['team/shift_roster', 'team'], ['attendance/log', 'team'],
  ['attendance/sites/edit/3', 'admin-sites'], ['settings', 'admin-settings'],
  ['settings/security/audit', 'admin-security'], ['users/profile', 'account-profile'],
  ['users/change_password', 'account-password'], ['users/edit/8', 'admin-users'],
  ['dashboard-extra', null], ['reporting', null],
]) {
  assert.equal(resolveOwner(uri), owner, `route ${uri || '(default)'} has one precise owner`);
}
assert.ok(!sidebar.includes('ni_active('), 'the old overlapping prefix-active helper is removed');

const activeLinks = Array.from(sidebar.matchAll(/\$nav_aria_current\('([^']+)',\s*'([^']+)'\)/g), match => [match[1], match[2]]);
assert.deepEqual(activeLinks, [
  ['dashboard', 'dashboard'],
  ['maintenance', 'workspace/maintenance'],
  ['inventory', 'workspace/inventory'],
  ['planning', 'workspace/planning'],
  ['reports', 'reports'],
  ['team', 'workspace/team'],
  ['admin-users', 'users'],
  ['admin-sites', 'attendance/sites'],
  ['admin-settings', 'settings'],
  ['admin-security', 'settings/security'],
  ['account-profile', 'users/profile'],
  ['account-password', 'users/change_password'],
], 'sidebar and account destinations derive aria-current from their unique route owner');
assert.ok(sidebar.includes("return 'aria-current=\"' . ($current_route === $target_route ? 'page' : 'location') . '\"';"),
  'exact destinations use aria-current=page and parent workspace destinations use aria-current=location');

assert.ok(header.includes('.ni:focus-visible') &&
          header.includes('#sidebar a:focus-visible,#sidebar button:focus-visible'),
  'keyboard focus has a visible indicator on links and disclosure controls');
assert.ok(header.includes('min-height:44px') &&
          header.includes('height:44px;border-radius:var(--r-sm);padding:0 10px;'),
  'disclosure and navigation link targets meet the 44-pixel touch target');
assert.ok(header.includes('.ni.on::before') && header.includes('background:var(--blue)'),
  'the current destination has a persistent visual marker in addition to color');
assert.ok(header.includes('scrollbar-width:thin') &&
          header.includes('.nav-wrap::-webkit-scrollbar { width:6px; }') &&
          !header.includes('scrollbar-width:none') &&
          !header.includes('.nav-wrap::-webkit-scrollbar { display:none; }'),
  'long sidebar navigation exposes a visible overflow cue');
assert.ok(!header.includes('.ni-tip') && !sidebar.includes('ni-tip'),
  'clipped hover-only custom tooltips are removed; labels and native titles remain');
assert.ok(sidebar.includes("sb.addEventListener('focusin'") &&
          sidebar.includes("sb.addEventListener('focusout'") &&
          sidebar.includes("toggle.addEventListener('click'"),
  'focus and an explicit click control replace hover-only discovery');
assert.ok(!sidebar.includes("addEventListener('mouseenter'") &&
          !sidebar.includes("addEventListener('mouseleave'"),
  'navigation expansion no longer depends on pointer hover or leave events');

assert.ok(header.includes('@media (max-width:760px)') &&
          header.includes('transform:translateX(-100%)') &&
          header.includes('transform:translateX(0)') &&
          header.includes('#canvas { margin-left:0; }'),
  'narrow screens use a labelled off-canvas drawer without squeezing the page');
assert.ok(sidebar.includes('function openDrawer()') && sidebar.includes('function closeDrawer(restoreFocus)') &&
          sidebar.includes("e.key==='Escape'") && sidebar.includes("e.key!=='Tab'") &&
          sidebar.includes('focusableItems()') && sidebar.includes("menuTrigger.focus()"),
  'the drawer supports Escape, a keyboard focus trap, and focus return');
assert.ok(sidebar.includes("canvas.setAttribute('inert','')") &&
          sidebar.includes("canvas.setAttribute('aria-hidden','true')"),
  'the open mobile drawer prevents interaction with the obscured page');
assert.ok(!header.includes('#canvas.expanded') && !sidebar.includes('cv.style.marginLeft'),
  'desktop expansion overlays the canvas instead of changing its width or margin');
assert.ok(header.includes('@media (prefers-reduced-motion:reduce)') &&
          header.includes('#sidebar,.sidebar-backdrop,.sb-toggle,.ni { transition:none; }'),
  'sidebar motion respects reduced-motion preferences');

console.log('Sidebar accessibility and active-location structural checks passed.');
