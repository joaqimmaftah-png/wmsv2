// Run with: node tests/inspection_templates_ux.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const view = fs.readFileSync('application/views/inspections/templates.php', 'utf8');
const controller = fs.readFileSync('application/controllers/Inspections.php', 'utf8');
const model = fs.readFileSync('application/models/Inspection_model.php', 'utf8');
assert.match(view, /form_open\('inspections\/template_delete\//);
assert.match(view, /if \(\$records === 0\)/);
assert.match(view, /rawurlencode\(\(string\)\$t->code\)/);
assert.match(view, /Record counts include drafts and submitted inspections/);
assert.match(view, /aria-live="polite"/);
assert.match(view, /id="it-tools" hidden/);
assert.match(controller, /public function template_delete[\s\S]*?require_post\(\)[\s\S]*?is_supervisor\(\)[\s\S]*?template_record_count/);
assert.match(model, /function get_record_counts\(\)[\s\S]*?group_by\('template_id'\)/);
const events = {};
const input = value => ({value, addEventListener(name, fn) { events[this.id + ':' + name] = fn; }, focus() { this.focused = true; }});
const search = Object.assign(input(''), {id:'it-search'}), reset = Object.assign(input(''), {id:'it-reset'});
const tabs = ['', 'active', 'inactive'].map(value => ({
  attrs: {'data-status-filter':value}, classList: {toggle(name, on) { this[name] = on; }},
  getAttribute(k) { return this.attrs[k]; }, setAttribute(k,v) { this.attrs[k] = v; },
  addEventListener(name, fn) { events['tab:' + value + ':' + name] = fn; }
}));
const rows = [
  {hidden:false, getAttribute(k) { return {'data-search':'Excavator Excavatrice excavator Heavy', 'data-status':'active'}[k]; }},
  {hidden:false, getAttribute(k) { return {'data-search':'Vehicle Véhicule lv General', 'data-status':'inactive'}[k]; }},
];
const tools = {hidden:true, querySelectorAll() { return tabs; }}; const results = {}; const empty = {hidden:true};
const table = {querySelectorAll() { return rows; }};
const elements = {'it-tools':tools,'it-table':table,'it-search':search,'it-results':results,'it-empty':empty,'it-reset':reset};
const document = {getElementById: id => elements[id]};
const script = view.slice(view.lastIndexOf('<script>') + 8, view.lastIndexOf('</script>'));
vm.runInNewContext(script, {document});
assert.equal(tools.hidden, false); assert.equal(results.textContent, '2 of 2 templates shown');
events['tab:inactive:click']();
assert.equal(tabs[2].attrs['aria-pressed'], 'true'); assert.equal(tabs[0].attrs['aria-pressed'], 'false');
assert.equal(rows[0].hidden, true); assert.equal(rows[1].hidden, false); assert.equal(results.textContent, '1 of 2 templates shown');
search.value = 'excavatrice'; events['it-search:input']();
assert.equal(empty.hidden, false); assert.equal(results.textContent, '0 of 2 templates shown');
events['tab::click'](); assert.equal(rows[0].hidden, false); assert.equal(rows[1].hidden, true);
events['tab:active:click'](); events['it-reset:click']();
assert.equal(search.value, ''); assert.equal(tabs[0].attrs['aria-pressed'], 'true'); assert.equal(search.focused, true); assert.ok(rows.every(r => !r.hidden));
vm.runInNewContext(script, {document:{getElementById:()=>null}});
const timesheet = fs.readFileSync('application/views/team/report.php', 'utf8');
for (const token of ['var(--surface)', 'var(--sep)', 'var(--r-lg)', 'var(--shadow-card)', 'var(--blue-lt)']) {
  assert.ok(timesheet.includes(token) && view.includes(token), 'shared timesheet token ' + token);
}
assert.match(view, /data-status-filter="inactive"/);
assert.match(view, /role="group" aria-label="Filter templates by status"/);
console.log('Inspection template register checks passed.');
