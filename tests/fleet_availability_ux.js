const assert = require('node:assert/strict');
const fs = require('node:fs');
const read = (path) => fs.readFileSync(path, 'utf8');

const routes = read('application/config/routes.php');
const reports = read('application/controllers/Reports.php');
const workorders = read('application/controllers/Workorders.php');
const w36 = read('application/models/W36_report_model.php');
const weekly = read('application/views/reports/weekly_w36.php');
const availability = read('application/views/reports/fleet_availability.php');
const downtime = read('application/views/reports/downtime_log.php');
const woForm = read('application/views/maintenance/wo_form.php');
const dashboardModel = read('application/models/Dashboard_model.php');

assert.match(routes, /reports\/fleet_availability/);
assert.match(routes, /reports\/downtime_log/);
assert.match(reports, /public function fleet_availability\(\)/);
assert.match(reports, /public function downtime_log\(\)/);
assert.match(reports, /report_for_week\(\$week_start/);
assert.match(reports, /get\('live'\) === '1'/, 'live readings are an explicit opt-in');
assert.match(w36, /hours_in_period/);
assert.match(w36, /kpi_union_hours\(/, 'event hours reuse W36 interval-union clipping');
assert.match(weekly, /\$daily, \$day, \$index/);
assert.match(weekly, /\$daily\[\$day\]/, 'weekly daily rows support date-string keys');
assert.match(availability, /Availability is not defined for this period/);
assert.match(availability, /Download Excel/);
assert.match(downtime, /workorders\/create\?fleet_availability=1/);
assert.match(downtime, /hours_in_period/);
assert.match(woForm, /name="fleet_availability"/);
assert.match(workorders, /_normalize_local_datetime/);
assert.match(workorders, /restoration must not be earlier than the start/);
assert.doesNotMatch(dashboardModel, /availability_pct'\]\s*===\s*null\s*\?\s*100/);

console.log('Fleet Availability integration structural checks passed.');
