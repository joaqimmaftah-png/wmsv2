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
const menu = {hidden:false, classList:{add() {}}, style:{}, offsetWidth:205, offsetHeight:90, contains(target) { return target === this; }};
const button = {hidden:true, attrs:{'aria-controls':'more-1'}, getAttribute(k) { return this.attrs[k]; }, setAttribute(k,v) { this.attrs[k]=v; }, addEventListener(n,fn) { events['menu:' + n] = fn; }, getBoundingClientRect() { return {right:300,top:100,bottom:130}; }, contains(target) { return target === this; }, focus() { this.focused=true; }};
const table = {querySelectorAll(selector) { return selector === '.it-more-toggle' ? [button] : rows; }};
const elements = {'it-tools':tools,'it-table':table,'it-search':search,'it-results':results,'it-empty':empty,'it-reset':reset,'more-1':menu};
const document = {getElementById: id => elements[id], addEventListener: (n,fn) => { events['document:' + n] = fn; }};
const window = {innerWidth:800, innerHeight:600, addEventListener: (n,fn) => { events['window:' + n] = fn; }};
const script = view.slice(view.lastIndexOf('<script>') + 8, view.lastIndexOf('</script>'));
vm.runInNewContext(script, {document, window});
assert.equal(tools.hidden, false); assert.equal(results.textContent, '2 of 2 templates shown');
assert.equal(menu.hidden, true); assert.equal(button.hidden, false);
events['menu:click'](); assert.equal(menu.hidden, false); assert.equal(button.attrs['aria-expanded'], 'true'); assert.equal(menu.style.left, '95px');
let prevented = false; events['document:keydown']({key:'Escape', preventDefault() { prevented = true; }});
assert.equal(menu.hidden, true); assert.equal(button.attrs['aria-expanded'], 'false'); assert.equal(button.focused, true); assert.equal(prevented, true);
events['menu:click'](); events['document:click']({target:{}}); assert.equal(menu.hidden, true);
events['menu:click'](); events['window:scroll'](); assert.equal(menu.hidden, true);
events['menu:click'](); search.value='excavatrice'; events['it-search:input'](); assert.equal(menu.hidden, true); search.value=''; events['it-search:input']();
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
assert.ok(view.includes('class="it-btn it-more-toggle" aria-expanded="false"'));
assert.ok(view.includes('aria-label="More actions for'));
assert.match(view, /it-more-menu\.is-enhanced \{ position:fixed/);
assert.match(view, /class="it-btn it-btn-danger it-menu-item it-menu-danger"/);
console.log('Inspection template register checks passed.');
