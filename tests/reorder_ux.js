// Dependency-free structural checks for the /reports/reorder enterprise table.
// Run with: node tests/reorder_ux.js
// This complements the PHP-WASM render and authenticated MySQL/browser checks.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const source = file => fs.readFileSync(path.join(root, file), 'utf8');
const view = source('application/views/reports/reorder.php');
const reports = source('application/controllers/Reports.php');

/* Ownership, preserved routes and real data contract. */
assert.equal((view.match(/<h1\b/g) || []).length, 1, 're-order view owns exactly one h1');
assert.ok(view.includes("site_url('reports/reorder')"), 'filter form stays on reports/reorder');
assert.ok(view.includes("base_url('reports/export_reorder_xlsx')"), 'existing Excel export remains available');
assert.ok(view.includes("base_url('orders')"), 'existing Spare orders destination remains available');
assert.ok(reports.includes('public function reorder()'), 'reorder controller method is preserved');
assert.ok(reports.includes('get_reorder_items($filter)'), 'reorder still reads the Materials_model query');
assert.ok(reports.includes('public function export_reorder_xlsx()'), 'reorder export method is preserved');
for (const key of ['category', 'location', 'search']) {
  assert.match(view, new RegExp("'" + key + "'\\s*=>\\s*\\$g\\('" + key + "'\\)"),
    `export preserves the ${key} filter contract`);
}
assert.ok(!view.includes("$_GET['sort']") && !view.includes("$_GET['dir']") && !view.includes("$_GET['view']"),
  'display-only query state is scalar-validated through qsParam');

/* The /team/report visual language is scoped, not dependent on undefined globals. */
for (const token of ['--ro-fill:', '--ro-sep-opaque:', '--ro-blue-lt:', '--ro-r-pill:', '--ro-ease:', '--ro-font:', '--ro-mono:']) {
  assert.ok(view.includes(token), `reorder defines local ${token.slice(2, -1)} token`);
}
for (const surface of ['.ro-head', '.ro-kpis', '.ro-scope-bar', '.ro-toolbar', '.ro-chips-bar', '.ro-table', '.ro-cards-grid', '.ro-footer']) {
  assert.ok(view.includes(surface), `reorder includes ${surface} surface`);
}
assert.ok(view.includes('position:sticky'), 'table header is sticky');
assert.ok(view.includes('font-variant-numeric:tabular-nums'), 'quantities use tabular numerals');
assert.ok(!view.includes('kpi-glow'), 'decorative KPI glow was removed');
assert.ok(!/linear-gradient|radial-gradient/.test(view), 'no decorative gradients');

/* Honest states and operationally meaningful controls. */
for (const state of ['all', 'out', 'in']) {
  assert.ok(view.includes("'" + state + "'"), `stock state ${state} exists`);
}
assert.ok(view.includes('Not available'), 'quantity is not faked as zero when levels are unavailable');
assert.ok(view.includes('Nothing needs re-ordering'), 'all-clear empty state exists');
assert.ok(view.includes('No items match your current filters'), 'filtered empty state exists');
assert.ok(view.includes('No stock data available'), 'no-stock-data state exists');
assert.ok(view.includes('never zero'), 'calculation note explains missing quantity semantics');
assert.ok(view.includes('Excel download includes all items'), 'export scope is disclosed separately from status tabs');
assert.ok(view.includes('aria-sort='), 'sortable headers expose aria-sort');
assert.ok(view.includes('scope="col"'), 'table headers are semantic');
assert.ok(view.includes('ro-sr-only'), 'table has an accessible caption utility');
assert.ok(view.includes('aria-label="Filter by stock state"'), 'state navigation is labelled');
assert.ok(view.includes('aria-label="Display layout"'), 'view switch is labelled');
assert.ok(view.includes('prefers-reduced-motion:reduce'), 'reduced motion is handled');
assert.ok(view.includes(':focus-visible'), 'keyboard focus is visible');
assert.ok(view.includes('@media print'), 'print output is explicitly designed');
assert.ok(view.includes('addEventListener(\'keydown\''), 'search shortcut is a real enhancement');
assert.ok(!/<a[^>]+href="#">/.test(view), 'no placeholder links');

/* All user-controlled values are escaped through the view helper. */
assert.ok(view.includes('htmlspecialchars((string)$v, ENT_QUOTES, \'UTF-8\')'), 'view escaping helper is present');
assert.ok(view.includes('$h($m->item_code)'), 'item code is escaped');
assert.ok(view.includes('$h($m->description)'), 'description attributes are escaped');
assert.ok(view.includes('$h($exportUrl)'), 'export URL is escaped');

console.log('Re-order report structural checks passed.');
