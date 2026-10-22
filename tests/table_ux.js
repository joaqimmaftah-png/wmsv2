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
  assert.match(view, /window\.location\.assign\(url\.toString\(\)\)/, 'view should survive navigation via URL');
}
const budget = source('application/views/lubricants/budget.php');
assert.match(budget, /\$can_write && !empty\(\$lines\)/, 'no empty budget submit');
assert.match(budget, /\!\$can_write \? 'readonly'/, 'no edit affordance for read-only mode');

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
console.log('Table surface and budget calculator regressions: passed');
