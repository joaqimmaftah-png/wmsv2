// Run with: node tests/weekly_w36_table_ux.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const view = fs.readFileSync('application/views/reports/weekly_w36.php', 'utf8');
const model = fs.readFileSync('application/models/W36_report_model.php', 'utf8');
const script = view.slice(view.lastIndexOf('<script>') + 8, view.lastIndexOf('</script>'));

function control(action, extra = {}) {
  return {
    dataset: { w36Action: action, ...extra }, attrs: {},
    setAttribute(name, value) { this.attrs[name] = value; },
    closest(selector) { return selector === '[data-w36-action]' ? this : (selector === 'tr.machine' ? this.machine : null); }
  };
}
function machine(parent, text, loc, below) {
  const detail = { hidden: true, classList: { contains: name => name === 'detail' } };
  const toggle = control('toggle-machine');
  const row = {
    hidden: true, dataset: { detailOpen: '0' }, nextElementSibling: detail, toggle,
    getAttribute(name) {
      return ({'data-machine-search':text,'data-location':loc,'data-below':below,'data-parent':parent})[name] || '';
    },
    querySelector: selector => selector === '[data-w36-action="toggle-machine"]' ? toggle : null
  };
  toggle.machine = row;
  return row;
}
function group(id) {
  const toggle = control('toggle-group', {group:id});
  const row = {
    hidden: false, dataset: {open:'0'}, toggle,
    getAttribute(name) { return name === 'data-group-row' ? id : ''; },
    querySelector: selector => selector === '[data-w36-action="toggle-group"]' ? toggle : null
  };
  return row;
}
const g1 = group('g1'), g2 = group('g2');
const rows = [machine('g1','EX-1 excavator north','north','1'), machine('g2','DO-1 dozer south','south','0')];
const groups = [g1,g2];
const handlers = {};
const makeInput = value => ({value,checked:false,addEventListener(type,fn){handlers[`input-${type}-${this.id}`]=fn;}});
const tools = {hidden:true,addEventListener(type,fn){handlers[`tools-${type}`]=fn;}};
const search = makeInput(''); search.id='search';
const location = makeInput(''); location.id='location';
const below = makeInput(''); below.id='below';
const count = {}, noMatches = {hidden:true};
const table = {
  querySelectorAll(selector) { return selector.indexOf('tr.machine') === 0 ? rows : groups; },
  addEventListener(type,fn){handlers[`table-${type}`]=fn;}
};
const elements = {
  'w36-search-tools':tools,'w36-machine-search':search,'w36-location-filter':location,
  'w36-below-target':below,'w36-search-count':count,'w36-no-matches':noMatches,
  'w36-machine-table':table
};
vm.runInNewContext(script,{document:{getElementById:id=>elements[id]}});
assert.equal(tools.hidden,false);
assert.equal(count.textContent,'2 of 2 machine rows match');
assert.equal(rows[0].hidden,true,'type sections start collapsed');

function clickTable(action) { handlers['table-click']({target:{closest:selector=>selector==='[data-w36-action]'?action:null}}); }
function clickTools(action) { handlers['tools-click']({target:{closest:selector=>selector==='[data-w36-action]'?action:null}}); }
clickTable(g1.toggle);
assert.equal(rows[0].hidden,false); assert.equal(rows[0].nextElementSibling.hidden,true);
clickTable(rows[0].toggle);
assert.equal(rows[0].nextElementSibling.hidden,false,'machine toggle opens its detail row');
search.value='north';handlers['input-input-search']();
assert.equal(rows[0].hidden,false);assert.equal(rows[1].hidden,true);assert.equal(g2.hidden,true);
search.value='missing';handlers['input-input-search']();
assert.equal(rows[0].nextElementSibling.hidden,true);assert.equal(noMatches.hidden,false);
search.value='';handlers['input-input-search']();
assert.equal(rows[0].nextElementSibling.hidden,false,'clearing filters restores the machine detail state');
location.value='south';handlers['input-change-location']();
assert.equal(rows[0].hidden,true);assert.equal(rows[1].hidden,false,'a location filter opens groups with matches');
location.value='';below.checked=true;handlers['input-change-below']();
assert.equal(rows[0].hidden,false);assert.equal(rows[1].hidden,true);
below.checked=false;handlers['input-change-below']();
clickTools(control('collapse'));assert.equal(rows[0].hidden,true);
clickTools(control('expand'));assert.equal(rows[0].hidden,false);assert.equal(rows[1].hidden,false);

assert.match(view, /<tr class="fleet w36-fleet-row">/);
assert.match(view, /Daily availability · Mon–Sun/);
assert.match(view, /w36-avbar/);
assert.match(view, /w36-group-toggle/);
assert.match(view, /w36-machine-toggle/);
assert.match(view, /w36-weekly-detail/);
assert.match(view, /Below target only/);
assert.match(view, /Expand all/);
assert.match(view, /Collapse all/);
assert.match(view, /published snapshot; Print \/ PDF uses this live reading/);
assert.match(view, /\?week=' \. \$week_label \. \(\$snapshot === null && \$published !== null \? '&live=1'/);
const picker = view.slice(view.indexOf('<form class="w36-picker" method="get"'), view.indexOf('<form class="w36-picker" method="post"'));
assert.match(picker, /<\/form>[\s\S]*<form class="w36-picker" method="get"[\s\S]*name="week_start"/);
const lookup = model.slice(model.indexOf('public function report_for_week'), model.indexOf('private function payload_matches'));
assert.match(lookup, /}\s*\/\/ A live reading[\s\S]*snapshot_row\(\$start\)/);

// ── the `Synthese` action-plan table: same delegated rules, its own ids ──────
const synHandlers = {};
function synRow(parent, text, loc, below, hold) {
  return {
    hidden: true, dataset: {},
    getAttribute(name) {
      return ({'data-machine-search':text,'data-location':loc,'data-below':below,'data-hold':hold,'data-parent':parent})[name] || '';
    }
  };
}
function synGroup(id) {
  const toggle = control('syn-toggle-group', {group:id});
  return {
    hidden: false, dataset: {open:'0'}, toggle,
    getAttribute(name) { return name === 'data-group-row' ? id : ''; },
    querySelector: selector => selector === '[data-w36-action="syn-toggle-group"]' ? toggle : null
  };
}
const sg1 = synGroup('sg1'), sg2 = synGroup('sg2');
const synRows = [synRow('sg1','EX-1 excavator north oil leak','north','1','0'), synRow('sg2','DO-1 dozer south awaiting parts','south','0','1')];
const synGroups = [sg1,sg2];
const synToolsEl = {hidden:true,addEventListener(type,fn){synHandlers[`tools-${type}`]=fn;}};
const synInput = {value:'',addEventListener(type,fn){synHandlers[`search-${type}`]=fn;}};
const synLocation = {value:'',addEventListener(type,fn){synHandlers[`location-${type}`]=fn;}};
const synBelow = {checked:false,addEventListener(type,fn){synHandlers[`below-${type}`]=fn;}};
const synHold = {checked:false,addEventListener(type,fn){synHandlers[`hold-${type}`]=fn;}};
const synCount = {}, synNoMatches = {hidden:true};
const synTable = {
  querySelectorAll(selector) { return selector.indexOf('tr.machine') === 0 ? synRows : synGroups; },
  addEventListener(type,fn){synHandlers[`table-${type}`]=fn;}
};
const elements2 = Object.assign({}, elements, {
  'w36-syn-tools':synToolsEl,'w36-syn-search':synInput,'w36-syn-location':synLocation,
  'w36-syn-below':synBelow,'w36-syn-hold':synHold,'w36-syn-count':synCount,
  'w36-syn-no-matches':synNoMatches,'w36-syn-table':synTable
});
vm.runInNewContext(script,{document:{getElementById:id=>elements2[id]}});
assert.equal(synToolsEl.hidden,false,'the plan toolbar appears when JS runs');
assert.equal(synCount.textContent,'2 of 2 machines shown');
assert.equal(synRows[0].hidden,true,'categories start collapsed');
synHandlers['table-click']({target:{closest:selector=>selector==='[data-w36-action]'?sg1.toggle:null}});
assert.equal(synRows[0].hidden,false,'a category toggle opens its machines');
synInput.value='leak';synHandlers['search-input']();
assert.equal(synRows[0].hidden,false);assert.equal(synRows[1].hidden,true);assert.equal(sg2.hidden,true);
synInput.value='';synBelow.checked=true;synHandlers['below-change']();
assert.equal(synRows[0].hidden,false);assert.equal(synRows[1].hidden,true,'below-target filter keeps only misses');
synBelow.checked=false;synHold.checked=true;synHandlers['hold-change']();
assert.equal(synRows[0].hidden,true);assert.equal(synRows[1].hidden,false,'on-hold filter keeps only held machines');
synInput.value='missing';synHandlers['search-input']();
assert.equal(synNoMatches.hidden,false,'no matches show the empty state');
synInput.value='';synHold.checked=false;synHandlers['hold-change']();
synHandlers['tools-click']({target:{closest:selector=>selector==='[data-w36-action]'?control('syn-expand'):null}});
assert.equal(synRows[0].hidden,false);assert.equal(synRows[1].hidden,false,'expand all opens every category');
synHandlers['tools-click']({target:{closest:selector=>selector==='[data-w36-action]'?control('syn-collapse'):null}});
assert.equal(synRows[0].hidden,true,'collapse all closes every category');

assert.match(view, /id="w36-syn-table"/);
assert.match(view, /w36-syn-kpis/);
assert.match(view, /data-w36-action="syn-toggle-group"/);
assert.match(view, /data-w36-action="syn-expand"/);
assert.match(view, /w36-syn-search/);
assert.match(view, /On hold only/);
assert.match(view, /w36-syn-wo/);
assert.match(view, /Work order <\?= \$esc\(\$r\['work_order'\]\)/);
assert.match(view, /data-hold="<\?= \$r\['hold_code'\] !== null \? '1' : '0' \?>"/);
assert.match(view, /data-machine-search="<\?= \$esc\(\$r_text\)/);
console.log('weekly W36 table UX checks passed');
