// Run with: node tests/workshop_weekly_report_ux.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const view = fs.readFileSync('application/views/reports/workshop_weekly.php', 'utf8');
const controller = fs.readFileSync('application/controllers/Reports.php', 'utf8');
const script = view.slice(view.lastIndexOf('<script>') + 8, view.lastIndexOf('</script>'));

// Guard the WMS data sources and retained production export endpoints.
assert.match(controller, /\$this->Kpi_engine_model->fleet\(\$week_start, \$week_end\)/);
assert.match(controller, /'daily'\s*=>\s*\$k\s*===\s*null\s*\?\s*\[\]\s*:\s*\$k\['daily'\]/);
assert.match(controller, /'work_orders'\s*=>\s*\$machine_wos/);
assert.match(controller, /\$this->Kpi_engine_model->rollup\(\$daily_members\)/);
assert.match(controller, /function export_workshop_weekly_xlsx/);
assert.match(controller, /function export_workshop_weekly_pdf/);
assert.match(view, /reports\/export_workshop_weekly_xlsx\?week_start=/);
assert.match(view, /reports\/export_workshop_weekly_pdf\?week_start=/);
assert.match(view, /equipment\/view\//);
assert.match(view, /workorders\/view\//);
assert.doesNotMatch(view, /EX 004|Hydraulic leak|events logged here/i, 'reference mock data and mock logging must not enter production');
assert.doesNotMatch(view, /IBM Plex|Barlow Condensed|JetBrains Mono/, 'report typography must inherit the shared WMS font stack');
assert.match(view, /\.wwr\{[^}]*font-family:inherit/s, 'report root inherits the application font');
assert.match(view, /Below target only/);
assert.match(view, /Expand all/);
assert.match(view, /Collapse all/);
assert.match(view, /Daily availability/);

function makeGroup(id) {
  const button = { attrs: {}, setAttribute(name, value) { this.attrs[name] = value; } };
  return { hidden: false, dataset: { groupRow: id, open: '0' }, querySelector: () => button, button };
}
function makeMachine(parent, search, location, below) {
  return { hidden: true, dataset: { parent, search, location, below, matches: '1' } };
}
const g1 = makeGroup('g1'), g2 = makeGroup('g2');
const m1 = makeMachine('g1', 'ex-1 excavator north', 'north', '1');
const m2 = makeMachine('g1', 'ex-2 excavator south', 'south', '0');
const m3 = makeMachine('g2', 'do-1 dozer south', 'south', '1');
const rows = [m1, m2, m3], groups = [g1, g2];
const listeners = {};
const input = (value = '') => ({ value, checked: false, addEventListener(type, fn) { listeners[type] = fn; } });
const search = input(''), location = input(''), below = input();
const count = {}, noMatches = { hidden: true };
const root = { addEventListener(type, fn) { listeners[`root-${type}`] = fn; } };
const templates = { 'wwr-detail-1': { innerHTML: '<section>equipment details</section>' } };
const layer = {
  hidden: true, innerHTML: '',
  addEventListener(type, fn) { listeners[`layer-${type}`] = fn; },
  querySelector(selector) { return selector === '.wwr-close' ? { focus() { this.focused = true; } } : null; }
};
const table = { querySelectorAll(selector) { return selector === '.wwr-machine-row' ? rows : groups; } };
const elements = {
  'wwr-search': search, 'wwr-location': location, 'wwr-below-target': below,
  'wwr-match-count': count, 'wwr-no-matches': noMatches,
  'wwr-machine-table': table, 'wwr-layer': layer
};
const document = {
  body: { style: {} },
  querySelector(selector) { return selector === '.wwr' ? root : null; },
  getElementById(id) { return elements[id] || templates[id] || null; },
  addEventListener(type, fn) { listeners[`document-${type}`] = fn; },
  contains() { return true; }
};
vm.runInNewContext(script, { document });

function click(action, extra = {}) {
  const button = { dataset: { wwrAction: action, ...extra }, focus() { this.focused = true; } };
  listeners['root-click']({ target: { closest: selector => selector === '[data-wwr-action]' ? button : null } });
  return button;
}
assert.equal(m1.hidden, true, 'machine rows start collapsed');
click('toggle', { group: 'g1' });
assert.equal(m1.hidden, false); assert.equal(m2.hidden, false); assert.equal(m3.hidden, true);
search.value = 'north'; listeners.input();
assert.equal(m1.hidden, false); assert.equal(m2.hidden, true); assert.equal(g2.hidden, true);
location.value = 'south'; listeners.change();
assert.equal(m1.hidden, true); assert.equal(m2.hidden, true); assert.equal(m3.hidden, true, 'search and location filters combine');
search.value = ''; listeners.input();
assert.equal(m2.hidden, false); assert.equal(m3.hidden, false);
location.value = '';
below.checked = true; listeners.change();
assert.equal(m1.hidden, false); assert.equal(m2.hidden, true); assert.equal(m3.hidden, false);
search.value = 'no such machine'; listeners.input();
assert.equal(noMatches.hidden, false); assert.match(count.textContent, /0 machines shown/);
search.value = ''; location.value = ''; below.checked = false; listeners.input();
click('collapse'); assert.equal(m1.hidden, true); assert.equal(m3.hidden, true);
click('expand'); assert.equal(m1.hidden, false); assert.equal(m3.hidden, false);

const openButton = click('drawer', { detail: 'wwr-detail-1' });
assert.equal(layer.hidden, false); assert.match(layer.innerHTML, /equipment details/);
assert.equal(document.body.style.overflow, 'hidden');
const closeEvent = { target: { closest: selector => selector === '[data-wwr-action="close"]' ? {} : null } };
listeners['layer-click'](closeEvent);
assert.equal(layer.hidden, true); assert.equal(document.body.style.overflow, '');
assert.equal(openButton.focused, true, 'closing the drawer restores focus to its trigger');

console.log('Workshop weekly report UX checks passed.');
