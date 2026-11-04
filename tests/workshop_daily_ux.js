// Structural regressions for the Workshop Daily Report table and output contract.
// Run with: node tests/workshop_daily_ux.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const src = f => fs.readFileSync(path.join(root, f), 'utf8');

const view = src('application/views/reports/workshop_daily.php');
const controller = src('application/controllers/Reports.php');

function methodBody(name, nextName) {
  const start = controller.indexOf(`function ${name}(`);
  assert.ok(start >= 0, `${name} exists`);
  const end = controller.indexOf(`function ${nextName}(`, start + 1);
  assert.ok(end > start, `${name} has a bounded body`);
  return controller.slice(start, end);
}

// --- Controller: dataset builder and routes ---------------------------------------
assert.ok(controller.includes('function _build_workshop_daily_dataset'), 'Reports::_build_workshop_daily_dataset missing');
assert.ok(controller.includes('function _build_workshop_daily'), 'grouped report builder still exists');
assert.ok(controller.includes('function export_workshop_daily_xlsx') && controller.includes('function export_workshop_daily_pdf'),
  'XLSX/PDF exports still exist');
assert.ok(controller.includes("'down'") && controller.includes("'available'"), 'status tab keys exist in dataset builder');

// --- C-02: one shared summary, with readiness and availability kept distinct ------
const summary = methodBody('_workshop_daily_summary', 'export_workshop_daily_pdf');
assert.ok(controller.includes("$data['daily_summary'] = $this->_workshop_daily_summary($report, $date)"),
  'screen receives the same summary used by exports');
assert.ok(summary.includes("$this->Kpi_engine_model->fleet($date, $date)") &&
          summary.includes("'availability'  => $availability === null ? null : $availability / 100"),
          'fleet availability uses the KPI engine and preserves no-data');
assert.ok(summary.includes("'readiness'     => $totalEq > 0 ? $totalAvail / $totalEq : null") &&
          summary.includes("$targetRollup = $this->Kpi_engine_model->rollup($targetMembers)") &&
          summary.includes("'target'        => $fleetTarget"),
          'status readiness and the required-time-weighted resolved fleet target are separate summary fields');
const dailyBuild = methodBody('_build_workshop_daily', '_workshop_daily_summary');
assert.ok(dailyBuild.includes("field_exists('report_group', 'equipment')") &&
          dailyBuild.includes("target_for($eq->id, $date, $reportGroup)") &&
          dailyBuild.includes("$member['target'] = $m['target']") &&
          dailyBuild.includes("$rollup['weekly_target'] !== null"),
          'equipment/group targets are resolved and rolled up with the existing required-time weighting');
const pdf = methodBody('_export_workshop_pdf', '_export_workshop_xlsx');
assert.ok(pdf.includes('$this->_workshop_daily_summary($report, $date)') &&
          pdf.includes('status readiness') && pdf.includes('Fleet Availability (time-based)') &&
          pdf.includes('No qualifying time base'),
          'PDF labels both measures and uses the shared no-data contract');
assert.ok(pdf.includes('Time-based · target') && pdf.includes('td-pct-neutral') &&
          pdf.includes('available (status)') && pdf.includes('AVAILABLE (STATUS)') &&
          pdf.includes('$kpi >= $dtgt') && pdf.includes('$avail >= $mtgt') &&
          pdf.includes('$overallKpi>=$fleetTarget'),
  'PDF exposes status-count labels and uses equipment/type/fleet targets with neutral no-data styling');
assert.ok(!pdf.includes('$kpi>=90') && !pdf.includes('$kpi>=70') &&
          !pdf.includes('$avail>=90') && !pdf.includes('$avail>=70'),
          'Daily PDF has no fixed 90/70 availability thresholds');
const xlsx = methodBody('_export_workshop_xlsx', '_valid_month');
assert.ok(xlsx.includes('$this->_workshop_daily_summary($report, $date)'),
  'XLSX uses the shared fleet summary');
assert.ok(!xlsx.includes('$kpiSum') && !xlsx.includes('$kpiCount') && !xlsx.includes('$overallAvail'),
  'XLSX no longer averages type KPIs or places readiness under an availability label');
for (const marker of ['MACHINE AVAILABILITY (TIME)', 'TYPE AVAILABILITY (TIME)',
                      'MACHINE TARGET (%)', 'TYPE TARGET (%)',
                      'STATUS READINESS — available status rows / reported fleet',
                      'FLEET AVAILABILITY — KPI engine time-based',
                      "$footer[] = array('v' => $metricRow[1], 's' => $metricRow[2])"]) {
  assert.ok(xlsx.includes(marker), `XLSX output contract includes ${marker}`);
}
assert.ok(xlsx.includes('$avDisplayPct >= $avTargetPct') &&
          xlsx.includes('$groupDisplayPct >= $groupTargetPct') &&
          xlsx.includes('$fleetDisplayPct >= $fleetTargetPct') &&
          xlsx.includes("['v' => (float)$avTarget,                    's' => $sTarget]") &&
          xlsx.includes("['v' => $isFirst ? (float)$groupTarget : null, 's' => $sTarget]") &&
          xlsx.includes('$groupPctStyle') && xlsx.includes('$fleetAvailabilityStyle') &&
          xlsx.includes("$xlsx->mergeCells('A1','T1')"),
          'machine/type/fleet targets are visible, target-based and included in the workbook structure');
assert.ok(xlsx.includes("$readinessStyle = $summary['readiness'] === null ? $sPctN : $sTotP") &&
          xlsx.includes("$fleetAvailabilityStyle = $fleetDisplayPct === null ? $sPctN"),
          'undefined footer values are neutral and status readiness is not target-scored');
assert.ok(!xlsx.includes('>= 0.9') && !xlsx.includes('>=0.9') &&
          !xlsx.includes('>= 0.7') && !xlsx.includes('>=0.7'),
          'Daily XLSX has no fixed 90/70 availability thresholds');
const dataset = methodBody('_build_workshop_daily_dataset', 'export_workshop_daily_xlsx');
assert.ok(dataset.includes("if ($a['availability'] === null && $b['availability'] !== null) return 1") &&
          dataset.includes("if ($b['availability'] === null && $a['availability'] !== null) return -1"),
          'availability sorting keeps unknown values last in both directions');

// --- View: original table/navigation behavior -------------------------------------
assert.ok(view.includes('role="tablist"') && view.includes('aria-selected'), 'status tabs use tablist semantics');
assert.ok(view.includes("'All Machines'") && view.includes("'Down'"), 'tabs include All Machines and Down');
assert.ok(view.includes('aria-sort') && view.includes('scope="col"'), 'sortable headers expose aria-sort and scope');
assert.ok(view.includes('wd-autosubmit'), 'scope filters auto-submit');
assert.ok(view.includes('id="wdSearch"') && view.includes("e.key === '/'"), 'search input with / keyboard shortcut');
assert.ok(view.includes('aria-current="page"') && view.includes('Showing'), 'pagination footer with showing summary');
assert.ok(view.includes('Excel / PDF above always export the full day'), 'export scope is stated honestly');
assert.ok(view.includes('Nothing is down'), 'down-tab empty state exists');
assert.ok(view.includes('No machines match the current filters'), 'filtered-empty state exists');
assert.ok(view.includes('equipment/view/'), 'machine IDs link to the equipment profile');
assert.ok(view.includes('@media print'), 'print styles retained');

// --- View: labelled measures, resolved targets and null-safe presentation ---------
for (const marker of ['Available (status)', 'Down (status)', 'Fleet Availability',
                      'Time-based · KPI engine · target', 'No qualifying time base',
                      "$m['availability'] === null ? '—'", '$avail_color($m[\'availability\'], $m[\'target\'])']) {
  assert.ok(view.includes(marker), `Daily screen includes ${marker}`);
}
assert.ok(!view.includes('Overall Availability') && !view.includes('$overallAvail'),
  'status readiness is no longer presented as overall availability');
assert.ok(view.includes("$values[] = $m['availability'] === null ? null") &&
          view.includes("'kpi'       => $g['daily_kpi'] === null ? null") &&
          view.includes("'targets'   => $targets"),
          'chart data preserves nulls and carries machine targets');
assert.ok(view.includes("if (typeof Chart === 'undefined') return;") &&
          view.includes('if (v === null)') && view.includes('ct.targets[i]') &&
          view.includes("ctx.raw === null ? 'No qualifying time base'"),
          'charts are dependency-safe and use null/target-aware bars and tooltips');
assert.ok(!view.includes('v >= 90') && !view.includes('v >= 70') &&
          !view.includes("$ct['kpi']>=90") && !view.includes("$ct['kpi']>=70"),
          'Daily screen and charts have no fixed 90/70 thresholds');

// Parse both actual inline scripts after substituting the PHP chart-data literal.
const scripts = [...view.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1]);
assert.ok(scripts.length >= 2, 'chart and table interaction scripts are present');
for (const source of scripts) {
  const script = source.replace(/<\?=\s*json_encode\(\$chartData\)\s*\?>/g, '[]');
  assert.ok(!script.includes('<?'), 'all PHP literals in Daily scripts are covered by the parse check');
  new Function(script); // eslint-disable-line no-new-func
}

console.log('Workshop Daily table/output structural checks passed.');
