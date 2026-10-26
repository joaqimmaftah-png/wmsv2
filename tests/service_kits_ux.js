#!/usr/bin/env node
'use strict';

const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const root = require('path').join(__dirname, '..');
const read = (name) => fs.readFileSync(`${root}/${name}`, 'utf8');
const view = read('application/views/reports/service_kits.php');
const model = read('application/models/Dashboard_model.php');
const controller = read('application/controllers/Reports.php');
const method = model.slice(model.indexOf('public function get_service_kit_recommendations()'));
const exportMethod = controller.slice(controller.indexOf('private function _export_service_kits_xlsx($data)'));

function has(name, source, regex) {
  assert.match(source, regex, name);
  console.log(`✓ ${name}`);
}

// The model keeps recommendation semantics while batching histories by fleet.
has('Service kit history is batched by equipment ID', method, /where_in\('equipment_id',\s*\$equipmentIds\)/);
has('Spare part history remains limited to New parts', method, /AND wsp\.spare_type = 'New'/);
has('Top-15 parts-per-machine behavior is preserved in SQL', method, /ROW_NUMBER\(\) OVER \([\s\S]*PARTITION BY equipment_id[\s\S]*WHERE equipment_part_rank <= 15/);
has('Raw parts query uses configured table prefixes and integer-cast IDs', method, /array_map\('intval', \$equipmentIds\)[\s\S]*dbprefix\('wo_spare_parts'\)/);
has('Equipment with no history or active schedule remains excluded', method, /if \(empty\(\$eqParts\).*empty\(\$eqSchedules\)\) continue/);
has('Kit includes equipment ID for its existing profile route', method, /'equipment_id'\s*=>\s*\$id/);

// Report controls search all displayed context, filter observed statuses, and paginate cards.
has('Query values are guarded and page sizes allow-listed', view, /array\(0, 10, 25, 50, 100\)/);
has('Status choices are derived from the selected type', view, /foreach \(\$activeKits as \$statusKit\)/);
has('Status count scope is disclosed', view, /Status counts include all kits in the selected type; search does not change them/);
has('Machine and recommendation detail are searchable', view, /data-sk-search="<\?= \$sk_h\(implode\(' ', \$skSearchValues\)\) \?>"/);
has('Full equipment profile is an existing equipment/view link', view, /base_url\('equipment\/view\/'.\(int\)\$kit\['equipment_id'\]\)/);
has('Zero-kit KPIs use safe flattening rather than zero-argument array_merge', view, /\$allPartRows = array\(\)/);
has('Empty report state includes all tracked history types and active PM', view, /No recommendation history or active PM schedule data found/);
has('Costs use the configured site currency helper', view, /wms_money\(\$p->avg_cost, \$siteCurrency\)/);
has('Entry counts are explicitly not unique work orders', view, /not unique work orders/);

// Export follows all-types scope and includes each detail family on screen.
assert.ok(exportMethod.includes("AVG COST ('.$currency.')"), 'Excel labels average cost with site currency');
console.log('✓ Export uses the site currency in the cost heading');
has('Excel has a zero-kit explanatory workbook state', exportMethod, /No recommendation history or active PM schedule data is available/);
has('Excel exports work-order oil records', exportMethod, /WORK ORDER OIL RECORDS/);
has('Excel entry counts are labelled as stored rows', exportMethod, /ENTRY COUNT \(ROWS\)/);

// Parse the actual inline JavaScript after substituting only PHP-rendered literals.
const scriptMatch = view.match(/<script>([\s\S]*?)<\/script>/);
assert(scriptMatch, 'inline report interaction script exists');
const script = scriptMatch[1].replace(/<\?=.*?\?>/gs, '"all"');
new vm.Script(script, { filename: 'service_kits.inline.js' });
console.log('✓ inline filter, URL-state, and pagination JavaScript parses');

console.log('Service Kit UX structural checks passed.');
