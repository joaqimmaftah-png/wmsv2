// Dependency-free structural checks for the role-scoped workspace entry points.
// Run with: node tests/sidebar_workspaces.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const source = file => fs.readFileSync(path.join(root, file), 'utf8');
const sidebar = source('application/views/layouts/sidebar.php');
const workspace = source('application/controllers/Workspace.php');
const workspaceView = source('application/views/workspace/index.php');
const baseController = source('application/core/MY_Controller.php');

function section(startMarker, endMarker) {
  const start = sidebar.indexOf(startMarker);
  const end = sidebar.indexOf(endMarker, start);
  assert.ok(start >= 0 && end > start, `sidebar section ${startMarker} is bounded`);
  return sidebar.slice(start, end);
}

const groups = [
  {
    key: 'maintenance',
    section: section('<!-- ── 2. MAINTENANCE', '<!-- ── 3. INVENTORY'),
    roleGuard: '$is_supervisor || $is_technician || $is_manager',
    directRoutes: ['workorders/create', 'workorders', 'equipment', 'pm', 'inspections', 'backlog'],
  },
  {
    key: 'inventory',
    section: section('<!-- ── 3. INVENTORY', '<!-- ── 3b. PLANNING'),
    roleGuard: null,
    directRoutes: ['materials', 'materials/inventory', 'materials/transactions', 'orders', 'spareparts', 'suppliers', 'lubricants'],
  },
  {
    key: 'planning',
    section: section('<!-- ── 3b. PLANNING', '<!-- ── 4. REPORTS'),
    roleGuard: '$is_supervisor || $is_manager',
    directRoutes: ['forecast', 'reports/weekly_targets', 'forecast/plan_vs_actual', 'forecast/rolling', 'forecast/spares', 'forecast/allowances'],
  },
  {
    key: 'team',
    section: section('<!-- ── 5. TEAM / MY RECORD', '<!-- ── 6. ADMIN'),
    roleGuard: '$is_supervisor || $is_technician || $is_store || $is_manager',
    directRoutes: ['team', 'team/attendance', 'team/report', 'attendance', 'attendance/punch', 'attendance/board', 'attendance/log', 'team/shift_roster', 'team/certifications'],
  },
];

for (const group of groups) {
  assert.equal((group.section.match(new RegExp(`base_url\\('workspace/${group.key}'\\)`, 'g')) || []).length, 1,
    `${group.key} sidebar section has one workspace entry point`);
  assert.ok(group.section.includes('$' + group.key + '_active'),
    `${group.key} workspace entry uses the group active state`);
  assert.ok(group.section.includes(`$nav_aria_current('${group.key}', 'workspace/${group.key}')`),
    `${group.key} workspace entry uses the explicit current-location owner`);
  if (group.roleGuard) {
    assert.ok(group.section.includes(group.roleGuard),
      `${group.key} preserves its prior role visibility boundary`);
  }
  for (const route of group.directRoutes) {
    assert.ok(!group.section.includes(`base_url('${route}')`),
      `${group.key} no longer duplicates ${route} as a global sidebar item`);
    assert.ok(workspace.includes(`'${route}'`),
      `${group.key} hub retains ${route} as an in-app destination`);
  }
}

assert.ok(sidebar.includes("$is_ceo ? 'Executive Summary' : 'Dashboard'"),
  'CEO remains on the Executive Summary and does not receive operational workspace links');
assert.ok(sidebar.includes("$is_store ? 'My Record' : 'Team'"),
  'Store users retain a personal-record label rather than a team-directory label');
const attendanceSitesRule = sidebar.match(/'attendance\/sites'\s*=>\s*'admin-sites'/);
const attendanceTeamRule = sidebar.match(/'attendance'\s*=>\s*'team'/);
assert.ok(attendanceSitesRule && attendanceTeamRule &&
          sidebar.indexOf(attendanceSitesRule[0]) < sidebar.indexOf(attendanceTeamRule[0]),
  'Attendance Sites resolves to Admin before the broader Attendance-to-Team route rule');

assert.ok(baseController.includes("'workspace'   => array(1, 2, 3, 4)"),
  'workspace controller is limited to the existing non-CEO operational roles');
for (const method of ['maintenance', 'inventory', 'planning', 'team']) {
  assert.ok(workspace.includes(`public function ${method}()`), `${method} workspace route exists`);
  assert.ok(workspace.includes(`'workspace/${method}'`), `${method} appears in cross-workspace navigation`);
}
assert.ok(workspace.includes('if (!in_array($role, $allowedRoles, TRUE))') &&
          workspace.includes("show_error('This workspace is not available for your role.', 403)"),
  'each workspace applies an explicit method-level role allow-list');
assert.ok(workspace.includes("if (in_array($role, $item['roles'], TRUE))"),
  'workspace destinations are filtered by their existing role scopes');
assert.ok(workspace.includes("array(1, 3, 4)),\n                $this->_item('Pending Spares'"),
  'Spare Orders are not offered to Technicians who lack Orders controller access');
assert.ok(workspace.includes("$this->_item('Suppliers', 'suppliers'") &&
          workspace.includes("array(1, 3, 4)),\n                $this->_item('Lubricants'"),
  'Suppliers are not offered to Technicians who lack Suppliers controller access');
assert.ok(workspace.includes("$selfRoute = 'team/view/' . (int)$this->user_id") &&
          workspace.includes("$selfService = in_array($role, array(2, 3), TRUE)"),
  'Technician and Store timesheet links remain scoped to the signed-in user');
assert.ok(workspace.includes("if ((int)$this->role_id === 3)"),
  'the default workspace route sends Store users to Inventory, not Maintenance');

const destinationRoutes = [
  ['workorders/create', 'Workorders', 'create'], ['workorders', 'Workorders', 'index'],
  ['equipment', 'Equipment', 'index'], ['pm', 'Pm', 'index'], ['inspections', 'Inspections', 'index'],
  ['backlog', 'Backlog', 'index'], ['materials', 'Materials', 'index'],
  ['materials/inventory', 'Materials', 'inventory'], ['materials/transactions', 'Materials', 'transactions'],
  ['orders', 'Orders', 'index'], ['spareparts', 'Spareparts', 'index'], ['suppliers', 'Suppliers', 'index'],
  ['lubricants', 'Lubricants', 'index'], ['lubricants/budget', 'Lubricants', 'budget'],
  ['forecast', 'Forecast', 'index'], ['reports/weekly_targets', 'Reports', 'weekly_targets'],
  ['forecast/plan_vs_actual', 'Forecast', 'plan_vs_actual'],
  ['forecast/rolling', 'Forecast', 'rolling'], ['forecast/spares', 'Forecast', 'spares'],
  ['forecast/allowances', 'Forecast', 'allowances'], ['team', 'Team', 'index'],
  ['team/view/{user_id}', 'Team', 'view'], ['team/attendance', 'Team', 'attendance'],
  ['team/report', 'Team', 'report'], ['team/shift_roster', 'Team', 'shift_roster'],
  ['team/certifications', 'Team', 'certifications'], ['attendance', 'Attendance', 'index'],
  ['attendance/punch', 'Attendance', 'punch'], ['attendance/board', 'Attendance', 'board'],
  ['attendance/log', 'Attendance', 'log'],
];
for (const [route, controller, method] of destinationRoutes) {
  const src = source(`application/controllers/${controller}.php`);
  assert.ok(new RegExp(`public function\\s+${method}\\s*\\(`).test(src),
    `workspace destination ${route} resolves to ${controller}::${method}()`);
}

const planningStart = workspace.indexOf('public function planning()');
const planningEnd = workspace.indexOf('public function team()', planningStart);
const planningWorkspace = workspace.slice(planningStart, planningEnd);
assert.ok(planningWorkspace.includes("$this->_item('Availability Targets', 'reports/weekly_targets', 'Review weekly machine and group targets; edits remain Supervisor-only.', array(1, 4))"),
  'Availability Targets is moved into the existing Supervisor/Manager Planning hub');
assert.ok(/'reports'\s*=>\s*array\(1,\s*4\)/.test(baseController),
  'the Reports controller remains available only to the existing Supervisor/Manager roles');
const reportsController = source('application/controllers/Reports.php');
const targetReadStart = reportsController.indexOf('public function weekly_targets()');
const targetReadEnd = reportsController.indexOf('public function weekly_targets_save()', targetReadStart);
assert.ok(targetReadStart >= 0 && targetReadEnd > targetReadStart &&
          reportsController.slice(targetReadStart, targetReadEnd).includes("$data['can_edit']        = $this->is_supervisor();"),
  'the planning launcher retains read access while target editing remains Supervisor-only');
for (const method of ['weekly_targets_save', 'weekly_targets_bulk', 'weekly_targets_copy', 'weekly_targets_delete']) {
  const start = reportsController.indexOf(`public function ${method}(`);
  const end = reportsController.indexOf('public function ', start + 1);
  assert.ok(start >= 0 && reportsController.slice(start, end < 0 ? undefined : end).includes('!$this->is_supervisor()'),
    `${method} remains Supervisor-only after the navigation move`);
}

for (const marker of ['aria-label="Workspace sections"', 'aria-current="page"',
                      'base_url($module[\'route\'])', 'base_url($link[\'route\'])',
                      'Choose a destination', 'focus-visible', 'prefers-reduced-motion:reduce']) {
  assert.ok(workspaceView.includes(marker), `workspace view includes ${marker}`);
}
assert.ok(!workspaceView.includes('<form') && !workspaceView.includes('onclick='),
  'workspace hubs are read-only ordinary-link launchpads with no JavaScript dependency');

console.log('Sidebar workspace flow structural checks passed.');
