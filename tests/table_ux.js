// Dependency-free regression checks for the three table surfaces and the budget draft calculator.
// Run with: node tests/table_ux.js. This does not replace PHP/MySQL/browser integration tests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const root = require('node:path').join(__dirname, '..');
const source = file => fs.readFileSync(require('node:path').join(root, file), 'utf8');

for (const page of ['suppliers/index.php', 'lubricants/index.php', 'lubricants/budget.php']) {
  const css = source('application/views/' + page).split('</style>')[0];
  assert.doesNotMatch(css, /var\(--card\s*,/, page + ' must not use an undefined dark surface fallback');
  assert.match(css, /background:\s*var\(--surface\)/, page + ' should use the layout surface');
}
for (const [page, controller, prefix] of [
  ['suppliers/index.php', 'Suppliers.php', 'sup'],
  ['lubricants/index.php', 'Lubricants.php', 'lub']
]) {
  const view = source('application/views/' + page);
  const code = source('application/controllers/' + controller);
  assert.ok(code.indexOf('$rows          = $paginate ?') < code.indexOf('$groupsMap = array();'), 'groups built after pagination');
  assert.match(code, /foreach \(\$rows as \$[sc]\) \{[\s\S]{0,280}\$groupsMap/, 'groups sourced from current page');
  assert.match(view, new RegExp('#' + prefix + 'Shell:not\\(\\[data-view="groups"\\]\\) \\.' + prefix + '-cards-container'), 'mobile cards must not override groups');
  assert.match(view, /window\.history\.replaceState\(null, '', url\.toString\(\)\)/, 'view should survive navigation via URL');
  assert.match(view, new RegExp('\\.' + prefix + '-pager a\\[href\\]'), 'pager should follow the active view');
}
const budget = source('application/views/lubricants/budget.php');
assert.match(budget, /\$can_write && !empty\(\$lines\)/, 'no empty budget submit');
assert.match(budget, /\!\$can_write \? 'readonly'/, 'no edit affordance for read-only mode');

// ── Suppliers Actions column (overflow menu) ──
const supIndex = source('application/views/suppliers/index.php');
assert.match(supIndex, /data-sup-menu=/, 'suppliers actions column uses an overflow menu button');
assert.match(supIndex, /role="menu"/, 'suppliers overflow menu has a menu role');
assert.match(supIndex, /aria-expanded="false"/, 'suppliers overflow button exposes expanded state');
assert.match(supIndex, /onsubmit="return confirm\('/, 'supplier delete requires explicit confirmation');
assert.match(supIndex, /csrf_field\(\)/, 'supplier delete form carries CSRF protection');
assert.match(supIndex, /sup-menu-item--danger/, 'supplier delete uses the destructive style');

// ── Suppliers ↔ /team/report design-system parity ──
assert.match(supIndex, /\.sup-table thead th \{\s*position: sticky/, 'supplier table header is sticky');
assert.match(supIndex, /aria-sort="/, 'sortable supplier headers expose ARIA sort state');
assert.match(supIndex, /\$cls\s+= 'sup-th-sort'/, 'supplier headers use the shared sort link component');
assert.match(supIndex, /class="sup-chips-bar no-print"/, 'active filters render as a chips strip');
assert.match(supIndex, /class="sup-select sup-autosubmit"/, 'scope filters are marked for auto-submit');
assert.match(supIndex, /filterForm\.submit\(\)/, 'scope filters submit the form on change');
assert.match(supIndex, /sup-rate-fill/, 'on-time delivery renders as a colour-coded rate bar');
assert.match(supIndex, /'b-success'/, 'active suppliers use the shared success badge');
assert.match(supIndex, /'b-danger'/, 'blacklisted suppliers use the shared danger badge');
assert.doesNotMatch(supIndex, /sup-st-active/, 'bespoke supplier status pill classes are gone');
assert.match(supIndex, /\.sup-print-banner \{/, 'a print-only report banner exists');
assert.match(supIndex, /@media print/, 'suppliers ship a clean print layout');
assert.match(supIndex, /sup-empty-ico/, 'zero-match state has an illustrated empty state');
assert.match(supIndex, /sup-user-forced-table/, 'an explicit table choice survives the mobile card fallback');
assert.match(supIndex, /sheetCache\[currentId\]/, 'drawer payloads are cached per supplier');
assert.doesNotMatch(supIndex, /supBatchPdf/, 'no dead batch PDF button is rendered');

// Exercise the actual inline calculator with minimal fake DOM nodes.
const script = budget.match(/<script>\s*([\s\S]*?)\s*<\/script>/)[1]
  .replace(/<\?= json_encode\(\$ccy\) \?>/, JSON.stringify('TZS'))

function element(value = '') {
  return {value, textContent: '', style: {}, handlers: {}, getAttribute(name) { return this.attrs[name]; },
    addEventListener(type, cb) { this.handlers[type] = cb; }, attrs: { 'data-id': '7', 'data-pm': '4.00' }};
}
const qty = element('3');
const cost = element('10'); cost.attrs['data-actual-qty'] = '2.00'; cost.attrs['data-default-rate'] = '8.00';
const ids = Object.fromEntries(['grandTotal','budgetRemaining','budgetUtilization','kpiPlannedTotal','budgetIssueValue','issue-value-7','total-7','bgtFillPmBtn'].map(id=>[id,element()]));
const document = {
  getElementById: id => ids[id],
  querySelectorAll: sel => sel === '.budget-qty' ? [qty] : sel === '.budget-cost' ? [cost] : [],
  querySelector: sel => sel === '.budget-cost[data-id="7"]' ? cost : null
};
vm.runInNewContext(script, {document, Number, Array, parseFloat});
qty.handlers.input();
assert.equal(ids.grandTotal.textContent, 'TZS 30.00');
assert.equal(ids.budgetRemaining.textContent, 'TZS 10.00');
assert.equal(ids.budgetIssueValue.textContent, 'TZS 20.00');
assert.equal(ids.budgetUtilization.textContent, '66.7% utilized · Unsaved');
cost.value = '5'; cost.handlers.input();
assert.equal(ids.budgetIssueValue.textContent, 'TZS 10.00');
assert.equal(ids['issue-value-7'].textContent, 'TZS 10.00');
assert.equal(ids.budgetRemaining.textContent, 'TZS 5.00');

// ── Forecast table surfaces (Planned Availability, Jobs, Rolling, Spares, Allowances, Parts) ──
const forecastViews = [
  'forecast/planned.php',
  'forecast/jobs.php',
  'forecast/plan_vs_actual.php',
  'forecast/rolling.php',
  'forecast/spares.php',
  'forecast/allowances.php',
  'forecast/parts.php'
];
for (const view of forecastViews) {
  const src = source('application/views/' + view);
  assert.ok(src.indexOf('<?php') === 0 || src.indexOf('<') >= 0, view + ' is a PHP view');
}
// Planned Availability: status tab list, client search, week picker, sticky machine column,
// shared badge classes, decorative glow removed, CSRF on mutation forms, confirm on publish.
const planned = source('application/views/forecast/planned.php');
assert.match(planned, /role="tablist"/, 'planned view exposes an accessible status tab list');
assert.match(planned, /data-status=/, 'planned view exposes machine status for client filtering');
assert.match(planned, /type="search"/, 'planned view has a client-side search input');
assert.match(planned, /type="week"/, 'planned view keeps the ISO week picker');
assert.match(planned, /tbl-sticky-first/, 'planned view pins the machine column');
assert.match(planned, /class="badge b-/, 'planned view uses the shared badge system');
assert.match(planned, /onsubmit="return confirm\('/, 'publish requires explicit acknowledgement');
assert.match(planned, /csrf_field\(\)/, 'planned mutation forms carry CSRF protection');
assert.doesNotMatch(planned, /kpi-glow/, 'planned view drops the inert decorative glow');
// Jobs: destructive row actions confirm and carry CSRF; status uses shared badges.
const jobs = source('application/views/forecast/jobs.php');
assert.match(jobs, /onsubmit="return confirm\('/, 'job removal requires confirmation');
assert.match(jobs, /csrf_field\(\)/, 'job forms carry CSRF protection');
assert.match(jobs, /\$job_status_badge\s*=/, 'job view maps statuses to shared badge classes');
assert.match(jobs, /class="badge /, 'job status renders through the shared badge system');
// Rolling: sticky machine column on the three-week grid.
assert.match(source('application/views/forecast/rolling.php'), /tbl-sticky-first/, 'rolling grid pins the machine column');
// Spares / allowances / parts: shared badge classes instead of hand-rolled chips.
assert.match(source('application/views/forecast/spares.php'), /class="badge b-/, 'spares view uses the shared badge system');
assert.match(source('application/views/forecast/allowances.php'), /class="badge b-/, 'allowances view uses the shared badge system');
assert.match(source('application/views/forecast/parts.php'), /onsubmit="return confirm\('/, 'part removal requires confirmation');
assert.match(source('application/views/forecast/parts.php'), /csrf_field\(\)/, 'part forms carry CSRF protection');

console.log('Table surface and budget calculator regressions: passed');
