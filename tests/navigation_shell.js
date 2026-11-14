// Dependency-free structural regressions for the approved navigation shell.
// Run with: node tests/navigation_shell.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const src = file => fs.readFileSync(path.join(root, file), 'utf8');

const helper = src('application/helpers/navigation_helper.php');
const sidebar = src('application/views/layouts/sidebar.php');
const header = src('application/views/layouts/header.php');
const js = src('assets/js/navigation.js');
const controller = src('application/core/MY_Controller.php');
const moduleNav = src('application/views/shared/_module_nav.php');
const planningNav = src('application/views/forecast/_nav.php');
const attendanceNav = src('application/views/attendance/_nav.php');

// One canonical model, loaded by the real renderer and used by the layout.
assert.ok(controller.includes("$this->load->helper('navigation');"), 'render() loads the navigation helper');
assert.ok(controller.includes("$this->load->view('layouts/header',") && !controller.includes('header-apple'), 'render() uses the maintained header layout');
assert.ok(sidebar.includes('wms_nav_tree(') && sidebar.includes('wms_nav_active('), 'sidebar is data-driven');
assert.ok(!sidebar.includes('function ni_active'), 'legacy active matcher is gone');
assert.ok(!sidebar.includes('sb-user') && !sidebar.includes('sb-pin'), 'duplicate sidebar identity/pin block is gone');
assert.ok(!/mouseenter|mouseleave|hover\s*\(/.test(js), 'navigation never depends on hover expansion');

// Explicit disclosures and a labelled mobile drawer.
assert.ok(sidebar.includes('<details class="nav-group') && sidebar.includes('<summary class="nav-group-toggle"'), 'groups use native disclosures');
assert.ok(sidebar.includes('aria-expanded=') && sidebar.includes('aria-controls='), 'disclosures expose state and panel');
assert.ok(sidebar.includes('aria-current="page"'), 'current leaf is identified');
assert.ok(sidebar.includes('id="nav-toggle"') && sidebar.includes('aria-controls="sidebar"'), 'mobile drawer has a labelled control');
assert.ok(sidebar.includes('id="nav-close"') && sidebar.includes('aria-label="Close navigation"'), 'drawer has a close control');
assert.ok(sidebar.includes('id="nav-backdrop"'), 'drawer has a dismissible backdrop');
assert.ok(js.includes('wms.nav.collapsed') && js.includes('wms.nav.groups.'), 'collapse and group state persist');
assert.ok(js.includes("event.key === 'Escape'") && js.includes("event.key !== 'Tab'"), 'drawer supports Escape and focus containment');
assert.ok(js.includes("setAttribute('inert', '')"), 'canvas is inert while the drawer is open');
assert.ok(js.includes('matchMedia') && js.includes('760px'), 'drawer behaviour is viewport-aware');
// Dense registers were designed against the 68px rail canvas: laptop widths must
// not silently lose 172px of table width to an always-expanded sidebar.
assert.ok(js.includes('max-width: 1599px') && js.includes('function defaultCollapsed'), 'laptop widths auto-compact to the rail');
assert.ok(js.includes('function storedChoice') && js.includes('choice === null ? defaultCollapsed() : choice'), 'an explicit collapse choice overrides the width default');

// `php -S host:port index.php` routes every request through the front
// controller. Without this guard the shell JS 404s in CI/local dev and the
// laptop compact default never runs, overflowing dense registers.
const front = src('index.php');
assert.ok(front.includes("PHP_SAPI === 'cli-server'") && front.includes("'/assets/'") && front.includes('return false;'), 'built-in server serves public shell assets');
assert.ok(front.includes('realpath') && front.includes('DIRECTORY_SEPARATOR'), 'asset guard confines resolved paths to assets/');
assert.ok(src('.github/workflows/tests.yml').includes('assets/js/navigation.js'), 'CI asserts the navigation asset is actually served');

// Accessibility and motion contracts in the shared stylesheet.
assert.ok(header.includes('class="skip-link" href="#main-content"'), 'skip link targets main content');
assert.ok(/#sidebar a:focus-visible/.test(header) && /summary:focus-visible/.test(header), 'navigation has visible keyboard focus');
assert.ok(/@media \(max-width:760px\)/.test(header) && /body\.nav-open #sidebar/.test(header), 'mobile drawer styles exist');
assert.ok(/@media \(prefers-reduced-motion:reduce\)/.test(header) && /#sidebar,#canvas/.test(header), 'reduced motion disables shell transitions');
assert.ok(/@media print/.test(header) && /#sidebar/.test(header), 'print hides the shell');
assert.ok(header.includes('.module-nav a[aria-current="page"]'), 'page-local navigation identifies its current leaf');

// Page-local suites replace specialist global entries.
assert.ok(moduleNav.includes('wms_nav_link_allowed('), 'module links are presentation-filtered');
for (const label of ['Planned Availability', 'Plan vs Actual', '3-Week Plan', 'PM Parts Forecast', 'Planning Allowances', 'Availability Targets']) {
  assert.ok(planningNav.includes(label), `Planning suite keeps ${label}`);
}
assert.ok(attendanceNav.includes('My Attendance & Timesheet') && attendanceNav.includes('Check-in Log'), 'attendance suite has personal and workforce sets');
assert.ok(attendanceNav.includes('array(2, 3)'), 'attendance suite scopes personal navigation to Technician/Store');

// Canonical naming table.
for (const label of ['Inventory Overview', 'Stock Items', 'Stock Movements', 'Purchase Orders', 'Work Order Parts', 'Defect Backlog', 'Preventive Maintenance', 'PM Parts Forecast', 'Planning Allowances', '3-Week Plan', 'Shift Roster', 'User Accounts', 'System Settings']) {
  assert.ok(helper.includes(`'${label}'`), `canonical navigation label ${label}`);
}
assert.ok(src('application/views/reports/_fleet_nav.php').includes('Downtime Register'), 'fleet local nav uses Downtime Register');
assert.ok(src('application/views/reports/downtime_log.php').includes('<h1>Downtime Register</h1>'), 'downtime page uses Downtime Register');
assert.ok(src('application/views/attendance/log.php').includes('Check-in Log'), 'attendance log uses Check-in Log');

// Report library consolidation and honest counts.
const reports = src('application/views/reports/index.php');
assert.ok(!reports.includes('aria-label="Fleet availability reports"'), 'hub does not duplicate the fleet local nav');
assert.ok(reports.includes("'title' => 'Fleet Availability'") && reports.includes("'title' => 'Downtime Register'"), 'fleet specialist reports remain reachable from the library');
assert.ok(reports.includes('$quick_export_count = count($quick_exports);'), 'quick-export count is derived from the catalogue');
assert.ok(reports.includes('<?= (int)$quick_export_count ?>'), 'quick-export count is rendered, not hardcoded');

// Contextual links are authorized or de-linked; personal attendance stays personal.
assert.ok(src('application/views/dashboard/index.php').includes("wms_nav_link_allowed('equipment', $role_id)"), 'dashboard equipment links are role-filtered');
assert.ok(src('application/views/spareparts/index.php').includes('canViewWo'), 'Store parts board does not hardcode WO anchors');
assert.ok(src('application/views/orders/index.php').includes('canViewWo'), 'order drawer WO anchor is role-filtered');
assert.ok(src('application/views/materials/transactions.php').includes('canViewEq'), 'stock movement asset anchor is role-filtered');
assert.ok(src('application/views/lubricants/index.php').includes('canViewWo') && src('application/views/lubricants/index.php').includes('canViewEq'), 'lubricant drawer cross-links are role-filtered');
assert.ok(src('application/views/lubricants/budget.php').includes('(bool)$can_write : FALSE'), 'budget view fails closed without an explicit write flag');
assert.ok(src('application/views/team/report.php').includes("$can_batch ? 'Attendance &amp; Timesheets' : 'My Attendance &amp; Timesheet'"), 'personal timesheet breadcrumb is not a workforce link');
assert.ok(src('application/views/attendance/index.php').includes('$personal_scope'), 'personal on-site status is scoped in the view');
assert.ok(src('application/views/users/form.php').includes("'name'=>'CEO'"), 'role help covers the CEO Executive-Summary-only role');

console.log('Navigation shell structural checks passed.');
