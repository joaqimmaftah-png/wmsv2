// Structural regressions for the Attendance Punch Log enterprise table.
// Run with: node tests/attendance_log_ux.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const src = f => fs.readFileSync(path.join(root, f), 'utf8');

const view = src('application/views/attendance/log.php');
const controller = src('application/controllers/Attendance.php');

// --- Controller: dataset builder + export --------------------------------------
assert.ok(controller.includes('function _build_log_dataset'), 'Attendance::_build_log_dataset missing');
assert.ok(controller.includes('function log_csv'), 'Attendance::log_csv missing');
const csvBlock = controller.slice(controller.indexOf('function log_csv'), controller.indexOf('function log_csv') + 800);
assert.ok(csvBlock.includes('is_supervisor()') && csvBlock.includes('is_manager()'),
  'log CSV export must be supervisor/manager gated server-side');
assert.ok(controller.includes("'check_in'") && controller.includes("'corrections'"),
  'punch-type and corrections tab keys exist in dataset builder');

// --- View: structure ------------------------------------------------------------
assert.ok(view.includes('role="tablist"') && view.includes('aria-selected'),
  'punch tabs use tablist semantics with counts');
assert.ok(view.includes("'All Punches'") && view.includes("'Corrections'"),
  'tabs include All Punches and Corrections');
assert.ok(view.includes('aria-sort') && view.includes('scope="col"'),
  'sortable headers expose aria-sort and scope');
assert.ok(view.includes('al-autosubmit'), 'scope filters auto-submit');
assert.ok(view.includes('id="alSearch"') && view.includes("e.key === '/'"),
  'search input with / keyboard shortcut');
assert.ok(view.includes('aria-current="page"') && view.includes('Showing'),
  'pagination footer with showing summary');
assert.ok(view.includes('al-print-banner'), 'print banner exists');
assert.ok(view.includes('@media print'), 'print styles exist');

// --- View: data, audit integrity & safety ---------------------------------------
assert.ok(view.includes("form_open('attendance/correction')"), 'correction uses the existing authorised endpoint');
assert.ok(view.includes('No punch can be edited or deleted from this screen'),
  'audit-trail guarantee stays visible');
assert.ok(view.includes('for="alCorrReason"') && view.includes('id="alCorrReason"'),
  'correction reason input is labelled');
assert.ok(view.includes('for="alCorrWorker"'), 'correction worker select is labelled');
assert.ok(view.includes('Unassigned') === false, 'no invented attendance statuses');
assert.ok(view.includes('No punches on this date'), 'empty-day state exists');
assert.ok(view.includes('No corrections on this date'), 'corrections empty state exists');
assert.ok(view.includes('No punches match the current filters'), 'filtered-empty state exists');
assert.ok(view.includes('attendance/log_csv'), 'export links to the log CSV endpoint');
assert.ok(view.includes('$p->radius_m'), 'radius shown is the punch-time snapshot stored on the punch row');

console.log('Attendance Log table UX structural checks passed.');
