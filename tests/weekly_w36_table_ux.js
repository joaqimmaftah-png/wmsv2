// Run with node tests/weekly_w36_table_ux.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const view = fs.readFileSync('application/views/reports/weekly_w36.php', 'utf8');
const model = fs.readFileSync('application/models/W36_report_model.php', 'utf8');
const script = view.slice(view.lastIndexOf('<script>') + 8, view.lastIndexOf('</script>'));
function row(text, detail) { return { hidden: false, getAttribute: () => text, nextElementSibling: detail }; }
const detail = {hidden: false, classList: {contains: name => name === 'detail'}};
const rows = [row('EX-1 Cat North', detail), row('EX-2 Volvo South', null)];
let listener;
const tools = {hidden: true}, input = {value: '', addEventListener: (_, fn) => listener = fn}, count = {};
const elements = {'w36-search-tools': tools, 'w36-machine-search': input, 'w36-search-count': count, 'w36-machine-table': {querySelectorAll: () => rows}};
vm.runInNewContext(script, {document: {getElementById: id => elements[id]}});
assert.equal(tools.hidden, false);
assert.equal(count.textContent, '2 of 2 machine rows shown');
input.value = 'north'; listener();
assert.equal(rows[0].hidden, false); assert.equal(detail.hidden, false); assert.equal(rows[1].hidden, true);
input.value = 'missing'; listener();
assert.equal(detail.hidden, true); assert.match(count.textContent, /no matches/);
input.value = ''; listener(); assert.equal(detail.hidden, false); assert.equal(rows[1].hidden, false);
assert.match(view, /<td colspan="4">FLEET<\/td>/);
assert.match(view, /published snapshot; Print \/ PDF uses this live reading/);
assert.match(view, /\?week=' \. \$week_label \. \(\$snapshot === null && \$published !== null \? '&live=1'/);
const picker = view.slice(view.indexOf('<form class="w36-picker" method="get"'), view.indexOf('<form class="w36-picker" method="post"'));
assert.match(picker, /<\/form>[\s\S]*<form class="w36-picker" method="get"[\s\S]*name="week_start"/);
const lookup = model.slice(model.indexOf('public function report_for_week'), model.indexOf('private function payload_matches'));
assert.match(lookup, /}\s*\/\/ A live reading[\s\S]*snapshot_row\(\$start\)/);
console.log('weekly W36 table UX checks passed');
