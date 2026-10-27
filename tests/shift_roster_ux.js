// Structural regressions for the Shift Roster enterprise table.
// Run with: node tests/shift_roster_ux.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const src = f => fs.readFileSync(path.join(root, f), 'utf8');

const view = src('application/views/team/shift_roster.php');
const controller = src('application/controllers/Team.php');

// --- Controller: dataset builder + export --------------------------------------
assert.ok(controller.includes('function _build_shift_roster_dataset'), 'Team::_build_shift_roster_dataset missing');
assert.ok(controller.includes('function shift_roster_csv'), 'Team::shift_roster_csv missing');
const csvBlock = controller.slice(controller.indexOf('function shift_roster_csv'), controller.indexOf('function shift_roster_csv') + 900);
assert.ok(csvBlock.includes('is_supervisor()') && csvBlock.includes('is_manager()'),
  'roster CSV export must be supervisor/manager gated server-side');
assert.ok(controller.includes("'assigned'") && controller.includes("'unassigned'"),
  'assigned/unassigned tab keys exist in dataset builder');

// --- View: structure ------------------------------------------------------------
assert.ok(view.includes('role="tablist"') && view.includes('aria-selected'),
  'status tabs use tablist semantics with counts');
assert.ok(view.includes("'All Staff'") && view.includes("'Unassigned'"),
  'tabs include All Staff and Unassigned');
assert.ok(view.includes('aria-sort') && view.includes('scope="col"'),
  'sortable worker header exposes aria-sort and scope');
assert.ok(view.includes('sr-autosubmit'), 'scope filters auto-submit');
assert.ok(view.includes('id="srSearch"') && view.includes("e.key === '/'"),
  'search input with / keyboard shortcut');
assert.ok(view.includes('aria-pressed') && view.includes('>Table<') === false || view.includes('>Table</a>') || view.includes('Table</a>'),
  'view toggle exists');
assert.ok(view.includes('Active filters'), 'active filter chips strip exists');
assert.ok(view.includes('aria-current="page"') && view.includes('Showing'),
  'pagination footer with showing summary');
assert.ok(view.includes('sr-print-banner'), 'print banner exists');
assert.ok(view.includes('@media print'), 'print styles exist');

// --- View: data & safety ---------------------------------------------------------
assert.ok(view.includes('htmlspecialchars((string)$w->full_name_roster'),
  'worker name is escaped in roster rows');
assert.ok(!view.includes("$w->first_name . ' ' . $w->last_name"),
  'unescaped worker-name concatenation removed from the view');
assert.ok(view.includes('Unassigned'), 'unassigned workers are labelled explicitly');
assert.ok(view.includes("form_open('team/assign_shift'"), 'assignment uses the existing authorised endpoint');
assert.ok(view.includes("form_open('team/shift_save'"), 'shift-type save uses the existing endpoint');
assert.ok(view.includes("form_open('team/shift_delete/'"), 'shift-type delete uses the existing endpoint');
assert.ok(view.includes('!empty($can_edit)'), 'mutating controls are supervisor-gated in the UI');
assert.ok(view.includes('for="srAssign'), 'assign selects have accessible labels');
assert.ok(view.includes('Everyone is assigned this week'), 'unassigned empty state explains itself');
assert.ok(view.includes('No workers match the current filters'), 'filtered-empty state exists');
assert.ok(view.includes('team/shift_roster_csv'), 'export links to the roster CSV endpoint');

console.log('Shift Roster table UX structural checks passed.');
