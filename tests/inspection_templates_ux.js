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
const search = Object.assign(input(''), {id:'it-search'}), status = Object.assign(input(''), {id:'it-status'}), reset = Object.assign(input(''), {id:'it-reset'});
const rows = [
  {hidden:false, getAttribute(k) { return {'data-search':'Excavator Excavatrice excavator Heavy', 'data-status':'active'}[k]; }},
  {hidden:false, getAttribute(k) { return {'data-search':'Vehicle Véhicule lv General', 'data-status':'inactive'}[k]; }},
];
const tools = {hidden:true}; const results = {}; const empty = {hidden:true};
const table = {querySelectorAll() { return rows; }};
const elements = {'it-tools':tools,'it-table':table,'it-search':search,'it-status':status,'it-results':results,'it-empty':empty,'it-reset':reset};
const document = {getElementById: id => elements[id]};
const script = view.slice(view.lastIndexOf('<script>') + 8, view.lastIndexOf('</script>'));
vm.runInNewContext(script, {document});
assert.equal(tools.hidden, false); assert.equal(results.textContent, '2 of 2 templates shown');
status.value = 'inactive'; events['it-status:change']();
assert.equal(rows[0].hidden, true); assert.equal(rows[1].hidden, false); assert.equal(results.textContent, '1 of 2 templates shown');
search.value = 'excavatrice'; events['it-search:input']();
assert.equal(empty.hidden, false); assert.equal(results.textContent, '0 of 2 templates shown');
status.value = ''; events['it-status:change'](); assert.equal(rows[0].hidden, false); assert.equal(rows[1].hidden, true);
events['it-reset:click'](); assert.equal(search.value, ''); assert.equal(status.value, ''); assert.equal(search.focused, true); assert.ok(rows.every(r => !r.hidden));
vm.runInNewContext(script, {document:{getElementById:()=>null}});
console.log('Inspection template register checks passed.');
