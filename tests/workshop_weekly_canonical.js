// Run with: node tests/workshop_weekly_canonical.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const read = path => fs.readFileSync(path, 'utf8');
const controller = read('application/controllers/Reports.php');
const core = read('application/core/MY_Controller.php');
const hub = read('application/views/reports/index.php');
const weekly = read('application/views/reports/weekly_w36.php');
for (const [canonical, legacy] of [
  ['workshop_weekly', 'weekly_w36'],
  ['export_workshop_weekly_xlsx', 'export_weekly_w36_xlsx'],
  ['export_workshop_weekly_pdf', 'export_weekly_w36_pdf'],
  ['publish_workshop_weekly', 'publish_weekly_w36'],
]) {
  assert.match(controller, new RegExp(`public function ${canonical}\\(\\)\\s*\\{\\s*\\$this->${legacy}\\(\\);`));
  assert.match(controller, new RegExp(`public function ${legacy}\\(\\)`));
}
assert.ok(core.includes("'reports/publish_workshop_weekly'"));
assert.ok(core.includes("'reports/publish_weekly_w36'"));
assert.equal((hub.match(/'title' => 'Workshop Weekly Report'/g) || []).length, 1);
for (const route of ['workshop_weekly', 'export_workshop_weekly_xlsx', 'export_workshop_weekly_pdf', 'publish_workshop_weekly']) {
  assert.ok(weekly.includes(`reports/${route}`), `${route} used in report`);
}
for (const file of ['index.php', 'fleet_availability.php', 'downtime_log.php', '_fleet_nav.php']) {
  assert.ok(!read(`application/views/reports/${file}`).includes('reports/weekly_w36'), `${file} links to canonical report`);
}
assert.ok(controller.includes("'live' => $this->input->get('live') !== null"));
assert.ok(controller.includes("$this->W36_report_model->publish($week_start"));
console.log('Canonical workshop weekly routes checked.');
