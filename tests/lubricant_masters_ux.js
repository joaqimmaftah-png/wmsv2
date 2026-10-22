// Source/interaction regression checks; run with `node tests/lubricant_masters_ux.js`.
// A PHP + MySQL integration run is still required before release.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const view = page => read('application/views/lubricants/' + page + '.php');

for (const page of ['budget', 'specifications', 'types', 'forecast']) {
  const css = view(page).split('</style>')[0];
  assert.doesNotMatch(css, /var\(--card\s*,/, page + ' has an undefined dark fallback');
  assert.match(css, /background:\s*var\(--surface\)/, page + ' must use the shared surface');
}
const budget = view('budget');
assert.match(budget, /min="2000" max="2100"/, 'historical budget year remains selectable');
assert.match(budget, /step="0.01" min="0"\s+data-id/, 'saved two-decimal budget quantities should remain valid inputs');
assert.match(budget, /PM Plan Coverage/, 'mixed units must not be summed in a KPI');
assert.doesNotMatch(budget, /number_format\(\(float\)\$totals\['(?:budget_qty|pm_req_qty|actual_qty)'\]/, 'mixed-unit KPI reintroduced');
const forecast = view('forecast');
assert.match(forecast, /\$has_visible_demand/, 'two empty month keys need a real empty state');
assert.match(forecast, /lub-forecast-months/, 'small-screen month cards required');
assert.match(forecast, /Hour-based projections and specification intervals are not included/, 'forecast method must be explicit');
assert.match(read('application/models/Lubricant_model.php'), /strtotime\(\$m0 \. '-01 \+1 month'\)/, 'month rollover must start at day one');
const spec = view('specifications');
assert.match(spec, /data-spec-edit/, 'existing standard should be editable with the existing POST');
assert.match(spec, /for="specQty"/, 'quantity label must target input');
assert.match(spec, /service_interval_hours !== NULL/, 'zero hours and absent interval must be distinct');
const types = view('types');
assert.match(types, /No active lubricant types found/, 'catalog needs empty state');
const controller = read('application/controllers/Lubricants.php');
assert.match(controller, /!is_finite\(\$recQty\) \|\| \$recQty < 0/, 'invalid specs must not overwrite existing standard with zero');
assert.match(controller, /!in_array\(\$unit, array\('L', 'Kg', 'Pcs'\), TRUE\)/, 'posted unit should match existing form options');

// Run the actual inline spec editor without PHP or a browser, verifying correct row pairing.
const script = spec.match(/<script>\s*([\s\S]*?)\s*<\/script>/)[1];
function field(value = '') {
  return {value, handlers: {}, addEventListener(type, cb) { this.handlers[type] = cb; },
    focus() { this.focused = true; }};
}
const els = {specEquipment: field(''), specLube: field(''), specQty: field(''),
  specInterval: field(''), specNotes: field(''), specSaveHint: {textContent: ''}};
const button = {dataset: {eq:'3', lube:'5', qty:'28.50', interval:'250', notes:'OEM manual'}, handlers: {},
  addEventListener(type, cb) { this.handlers[type] = cb; }};
const document = {getElementById: id => els[id], querySelectorAll: selector => selector === '[data-spec-edit]' ? [button] : []};
vm.runInNewContext(script, {document, Array});
button.handlers.click();
assert.equal(els.specEquipment.value, '3');
assert.equal(els.specLube.value, '5');
assert.equal(els.specQty.value, '28.50');
assert.equal(els.specInterval.value, '250');
assert.equal(els.specNotes.value, 'OEM manual');
assert.equal(els.specSaveHint.textContent, 'This pair already has a standard. Saving will update it.');
assert.equal(els.specQty.focused, true);
console.log('Lubricant master/forecast UX source and spec editor checks: passed');
