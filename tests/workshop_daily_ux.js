// Structural regressions for the Workshop Daily Report table.
// Run with: node tests/workshop_daily_ux.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const src = f => fs.readFileSync(path.join(root, f), 'utf8');

const view = src('application/views/reports/workshop_daily.php');
const controller = src('application/controllers/Reports.php');

// --- Controller: dataset builder; exports untouched ------------------------------
assert.ok(controller.includes('function _build_workshop_daily_dataset'), 'Reports::_build_workshop_daily_dataset missing');
assert.ok(controller.includes('function _build_workshop_daily'), 'grouped report builder still exists');
assert.ok(controller.includes('function export_workshop_daily_xlsx') && controller.includes('function export_workshop_daily_pdf'),
  'XLSX/PDF exports still exist');
assert.ok(controller.includes("'down'") && controller.includes("'available'"), 'status tab keys exist in dataset builder');

// --- View: structure ---------------------------------------------------------------
assert.ok(view.includes('role="tablist"') && view.includes('aria-selected'), 'status tabs use tablist semantics');
assert.ok(view.includes("'All Machines'") && view.includes("'Down'"), 'tabs include All Machines and Down');
assert.ok(view.includes('aria-sort') && view.includes('scope="col"'), 'sortable headers expose aria-sort and scope');
assert.ok(view.includes('wd-autosubmit'), 'scope filters auto-submit');
assert.ok(view.includes('id="wdSearch"') && view.includes("e.key === '/'"), 'search input with / keyboard shortcut');
assert.ok(view.includes('aria-current="page"') && view.includes('Showing'), 'pagination footer with showing summary');
assert.ok(view.includes('Excel / PDF above always export the full day'), 'export scope is stated honestly');
assert.ok(view.includes('Nothing is down'), 'down-tab empty state exists');
assert.ok(view.includes('No machines match the current filters'), 'filtered-empty state exists');
assert.ok(view.includes('equipment/view/'), 'machine IDs link to the equipment profile');
assert.ok(view.includes('@media print'), 'print styles retained');

console.log('Workshop Daily table UX structural checks passed.');
