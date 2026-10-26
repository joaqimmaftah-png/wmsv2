// Dependency-free regression checks for the three table surfaces and the budget draft calculator.
// Run with: node tests/table_ux.js. This does not replace PHP/MySQL/browser integration tests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const root = require('node:path').join(__dirname, '..');
const source = file => fs.readFileSync(require('node:path').join(root, file), 'utf8');

for (const page of ['suppliers/index.php', 'lubricants/index.php', 'lubricants/budget.php']) {
  const css = source('application/views/' + page).split('</style>')[0];
  assert.doesNotMatch(css, /var\(--card\s*,/, page + ' must not use an undefined dark surface fallback');
  assert.match(css, /background:\s*var\(--surface\)/, page + ' should use the layout surface');
}
for (const [page, controller, prefix] of [
  ['suppliers/index.php', 'Suppliers.php', 'sup'],
  ['lubricants/index.php', 'Lubricants.php', 'lub']
]) {
  const view = source('application/views/' + page);
  const code = source('application/controllers/' + controller);
  assert.ok(code.indexOf('$rows          = $paginate ?') < code.indexOf('$groupsMap = array();'), 'groups built after pagination');
  assert.match(code, /foreach \(\$rows as \$[sc]\) \{[\s\S]{0,280}\$groupsMap/, 'groups sourced from current page');
  assert.match(view, new RegExp('#' + prefix + 'Shell:not\\(\\[data-view="groups"\\]\\) \\.' + prefix + '-cards-container'), 'mobile cards must not override groups');
  assert.match(view, /window\.history\.replaceState\(null, '', url\.toString\(\)\)/, 'view should survive navigation via URL');
  assert.match(view, new RegExp('\\.' + prefix + '-pager a\\[href\\]'), 'pager should follow the active view');
}
const budget = source('application/views/lubricants/budget.php');
assert.match(budget, /\$can_write && !empty\(\$lines\)/, 'no empty budget submit');
assert.match(budget, /\!\$can_write \? 'readonly'/, 'no edit affordance for read-only mode');

// ── Suppliers Actions column (overflow menu) ──
const supIndex = source('application/views/suppliers/index.php');
assert.match(supIndex, /data-sup-menu=/, 'suppliers actions column uses an overflow menu button');
assert.match(supIndex, /role="menu"/, 'suppliers overflow menu has a menu role');
assert.match(supIndex, /aria-expanded="false"/, 'suppliers overflow button exposes expanded state');
assert.match(supIndex, /onsubmit="return confirm\('/, 'supplier delete requires explicit confirmation');
assert.match(supIndex, /csrf_field\(\)/, 'supplier delete form carries CSRF protection');
assert.match(supIndex, /sup-menu-item--danger/, 'supplier delete uses the destructive style');

// ── Suppliers ↔ /team/report design-system parity ──
assert.match(supIndex, /\.sup-table thead th \{\s*position: sticky/, 'supplier table header is sticky');
assert.match(supIndex, /aria-sort="/, 'sortable supplier headers expose ARIA sort state');
assert.match(supIndex, /\$cls\s+= 'sup-th-sort'/, 'supplier headers use the shared sort link component');
assert.match(supIndex, /class="sup-chips-bar no-print"/, 'active filters render as a chips strip');
assert.match(supIndex, /class="sup-select sup-autosubmit"/, 'scope filters are marked for auto-submit');
assert.match(supIndex, /filterForm\.submit\(\)/, 'scope filters submit the form on change');
assert.match(supIndex, /sup-rate-fill/, 'on-time delivery renders as a colour-coded rate bar');
assert.match(supIndex, /'b-success'/, 'active suppliers use the shared success badge');
assert.match(supIndex, /'b-danger'/, 'blacklisted suppliers use the shared danger badge');
assert.doesNotMatch(supIndex, /sup-st-active/, 'bespoke supplier status pill classes are gone');
assert.match(supIndex, /\.sup-print-banner \{/, 'a print-only report banner exists');
assert.match(supIndex, /@media print/, 'suppliers ship a clean print layout');
assert.match(supIndex, /sup-empty-ico/, 'zero-match state has an illustrated empty state');
assert.match(supIndex, /sup-user-forced-table/, 'an explicit table choice survives the mobile card fallback');
assert.match(supIndex, /sheetCache\[currentId\]/, 'drawer payloads are cached per supplier');
assert.doesNotMatch(supIndex, /supBatchPdf/, 'no dead batch PDF button is rendered');

// Exercise the actual inline calculator with minimal fake DOM nodes.
const script = budget.match(/<script>\s*([\s\S]*?)\s*<\/script>/)[1]
  .replace(/<\?= json_encode\(\$ccy\) \?>/, JSON.stringify('TZS'))

function element(value = '') {
  return {value, textContent: '', style: {}, handlers: {}, getAttribute(name) { return this.attrs[name]; },
    addEventListener(type, cb) { this.handlers[type] = cb; }, attrs: { 'data-id': '7', 'data-pm': '4.00' }};
}
const qty = element('3');
const cost = element('10'); cost.attrs['data-actual-qty'] = '2.00'; cost.attrs['data-default-rate'] = '8.00';
const ids = Object.fromEntries(['grandTotal','budgetRemaining','budgetUtilization','kpiPlannedTotal','budgetIssueValue','issue-value-7','total-7','bgtFillPmBtn'].map(id=>[id,element()]));
const document = {
  getElementById: id => ids[id],
  querySelectorAll: sel => sel === '.budget-qty' ? [qty] : sel === '.budget-cost' ? [cost] : [],
  querySelector: sel => sel === '.budget-cost[data-id="7"]' ? cost : null
};
vm.runInNewContext(script, {document, Number, Array, parseFloat});
qty.handlers.input();
assert.equal(ids.grandTotal.textContent, 'TZS 30.00');
assert.equal(ids.budgetRemaining.textContent, 'TZS 10.00');
assert.equal(ids.budgetIssueValue.textContent, 'TZS 20.00');
assert.equal(ids.budgetUtilization.textContent, '66.7% utilized · Unsaved');
cost.value = '5'; cost.handlers.input();
assert.equal(ids.budgetIssueValue.textContent, 'TZS 10.00');
assert.equal(ids['issue-value-7'].textContent, 'TZS 10.00');
assert.equal(ids.budgetRemaining.textContent, 'TZS 5.00');

// ── Forecast table surfaces (Planned Availability, Jobs, Rolling, Spares, Allowances, Parts) ──
const forecastViews = [
  'forecast/planned.php',
  'forecast/jobs.php',
  'forecast/plan_vs_actual.php',
  'forecast/rolling.php',
  'forecast/spares.php',
  'forecast/allowances.php',
  'forecast/parts.php'
];
for (const view of forecastViews) {
  const src = source('application/views/' + view);
  assert.ok(src.indexOf('<?php') === 0 || src.indexOf('<') >= 0, view + ' is a PHP view');
}
// Planned Availability: status tab list, client search, week picker, sticky machine column,
// shared badge classes, decorative glow removed, CSRF on mutation forms, confirm on publish.
const planned = source('application/views/forecast/planned.php');
assert.match(planned, /role="tablist"/, 'planned view exposes an accessible status tab list');
assert.match(planned, /data-status=/, 'planned view exposes machine status for client filtering');
assert.match(planned, /type="search"/, 'planned view has a client-side search input');
assert.match(planned, /type="week"/, 'planned view keeps the ISO week picker');
assert.match(planned, /tbl-sticky-first/, 'planned view pins the machine column');
assert.match(planned, /class="badge b-/, 'planned view uses the shared badge system');
assert.match(planned, /onsubmit="return confirm\('/, 'publish requires explicit acknowledgement');
assert.match(planned, /csrf_field\(\)/, 'planned mutation forms carry CSRF protection');
assert.doesNotMatch(planned, /kpi-glow/, 'planned view drops the inert decorative glow');
// Jobs: destructive row actions confirm and carry CSRF; status uses shared badges.
const pva = source('application/views/forecast/plan_vs_actual.php');
assert.match(pva, /for="pva-week"[\s\S]*id="pva-week"/, 'plan-vs-actual week selector has an associated label');
assert.match(pva, /snapshot_tables_ready[\s\S]*Forecast snapshot table is not set up/, 'plan-vs-actual distinguishes missing snapshot tables');
assert.match(pva, /published baseline could not be read[\s\S]*\$h\(\$error\)/, 'plan-vs-actual distinguishes an unreadable baseline snapshot');
assert.match(pva, /No published plan for/, 'plan-vs-actual retains a separate unpublished-week state');
assert.match(pva, /actual and variance values show —, not zero/, 'unavailable actuals are not represented as zero');
assert.match(pva, /Some planned groups have no usable matching actual value/, 'partial group actual gaps are disclosed');
assert.match(pva, /<caption class="pva-sr-only">/, 'plan-vs-actual table has an accessible caption');
assert.match(pva, /<th scope="col">GROUP<\/th>/, 'plan-vs-actual table headers declare column scope');
assert.match(pva, /pva-group-table th:first-child,.pva-group-table td:first-child \{ position:sticky/, 'group identity stays visible while the comparison table scrolls');
assert.match(pva, /@media \(max-width:900px\) \{ \.pva-kpis \{ grid-template-columns:repeat\(2,minmax\(0,1fr\)\); \} \}/, 'plan-vs-actual KPIs adapt at tablet widths');
assert.match(pva, /@media \(max-width:560px\)[\s\S]*\.pva-kpis \{ grid-template-columns:1fr;/, 'plan-vs-actual KPIs and week controls adapt on phones');
assert.match(pva, /Back to the published week plan[\s\S]*forecast\?week=/, 'published comparison links back to the same planned week');
const jobs = source('application/views/forecast/jobs.php');
assert.match(jobs, /onsubmit="return confirm\('/, 'job removal requires confirmation');
assert.match(jobs, /csrf_field\(\)/, 'job forms carry CSRF protection');
assert.match(jobs, /\$job_status_badge\s*=/, 'job view maps statuses to shared badge classes');
assert.match(jobs, /class="badge /, 'job status renders through the shared badge system');
assert.match(jobs, /for="jobs-machine"[\s\S]*id="jobs-machine"/, 'machine selector has an associated label');
assert.match(jobs, /for="jobs-date"[\s\S]*id="jobs-date"/, 'day selector has an associated label');
assert.match(jobs, /for="job-hours"[\s\S]*id="job-hours"/, 'hours field has an associated label');
assert.match(jobs, /<caption class="jobs-sr-only">/, 'job table has an accessible caption');
assert.match(jobs, /<th scope="col">CODE<\/th>/, 'job table headers declare column scope');
assert.match(jobs, /No reportable machines are available for this week/, 'jobs view distinguishes an empty machine list');
assert.match(jobs, /Choose a machine and day above to view its planned hours and jobs/, 'jobs view explains the unselected machine state');
assert.match(jobs, /You have view-only access\. A supervisor can change jobs/, 'read-only users get an explanation when the editor is hidden');
assert.match(jobs, /wo_hold_codes\(\)/, 'hold labels use the shared business-approved vocabulary');
assert.match(jobs, /\$hold_labels\[\$c\]/, 'hold options render a label alongside each unchanged code');
assert.match(source('application/helpers/wo_helper.php'), /'WSP'\s*=>\s*'Waiting for Spare Part'/, 'the shared helper retains the approved WSP meaning');
assert.match(jobs, /A multi-day repair is one job with an end date/, 'multi-day job behavior remains explained');
assert.match(jobs, /jobs-table th:nth-child\(2\),\.jobs-table td:nth-child\(2\) \{ position:sticky/, 'job identity stays visible during horizontal table scrolling');
assert.match(jobs, /aria-label="Edit job:/, 'repeated edit actions have job-specific accessible names');
assert.match(jobs, /aria-label="Remove job:/, 'repeated remove actions have job-specific accessible names');
assert.match(jobs, /hours\.required = !isStandby/, 'hours requirement follows the existing Standby exception');
const jobsScript = jobs.match(/<script>\s*([\s\S]*?)\s*<\/script>/)[1];
const jobElements = {
  'job-type': {value: 'Inspection', handlers: {}, addEventListener(type, cb) { this.handlers[type] = cb; }},
  'job-hours': {value: '', disabled: false, required: true},
  'job-hours-help': {textContent: ''}
};
vm.runInNewContext(jobsScript, {document: {getElementById: id => jobElements[id]}});
assert.equal(jobElements['job-hours'].required, true, 'non-Standby job hours are required');
jobElements['job-type'].value = 'Standby';
jobElements['job-type'].handlers.change();
assert.equal(jobElements['job-hours'].disabled, true, 'Standby does not request hours that the model ignores');
assert.equal(jobElements['job-hours'].value, '0', 'Standby is clearly represented as zero hours');
jobElements['job-type'].value = 'Repair';
jobElements['job-type'].handlers.change();
assert.equal(jobElements['job-hours'].disabled, false, 'hours become editable for other job types');
assert.equal(jobElements['job-hours'].required, true, 'hours are required again for other job types');
// Rolling: the three-week comparison stays a matrix, with meaningful filters,
// visible weekly status, model-owned downtime, and the established profile link.
const rolling = source('application/views/forecast/rolling.php');
assert.match(rolling, /tbl-sticky-first/, 'rolling grid pins the machine column');
assert.match(rolling, /\.rolling-table th:first-child,.rolling-table td:first-child \{ position:sticky; left:0;/, 'rolling view defines its own sticky machine-column CSS');
assert.match(rolling, /\.rolling-kpi-grid \{ grid-template-columns:repeat\(2,minmax\(0,1fr\)\); \}/, 'rolling weekly KPI cards adapt at tablet widths');
assert.match(rolling, /\.rolling-kpi-grid \{ grid-template-columns:1fr; \}/, 'rolling weekly KPI cards stack on phones');
assert.match(rolling, /<colgroup><col><col><col><\/colgroup>[\s\S]*?<colgroup span="3"><\/colgroup>/, 'rolling table defines column groups for the week headers');
assert.match(rolling, /colspan="3" class="rolling-week-head"/, 'rolling weeks are grouped across their three metrics');
assert.match(rolling, /scope="col" class="rolling-week-start">STATUS/, 'each week has a labelled status column');
assert.match(rolling, /DOWNTIME h/, 'downtime metric has an explicit unit');
assert.match(rolling, /PLAN AVAIL/, 'availability is identified as a planned metric');
assert.match(rolling, /name="q"/, 'rolling plan has a real GET search field');
assert.match(rolling, /name="equipment_type"/, 'rolling plan can filter by observed equipment type');
assert.match(rolling, /name="per_page"/, 'rolling plan exposes a page-size selector');
assert.match(rolling, /\$rolling_page_sizes\s*=\s*array\(10, 25, 50, 100\)/, 'rolling page size is allow-listed');
assert.match(rolling, /\$rolling_page_rows\s*=\s*array_slice\(\$rolling_filtered,\s*\$rolling_page_offset,\s*\$rolling_per_page\)/, 'pagination slices the already-filtered rows');
assert.match(rolling, /\$rolling_page_url\s*=\s*function[\s\S]*?'week'\s*=>\s*\$base_label[\s\S]*?'page'\s*=>\s*\(int\)\$page_number[\s\S]*\$rolling_query[\s\S]*\$rolling_type[\s\S]*\$rolling_status/, 'pagination links preserve week and active filters');
assert.match(rolling, /class="rolling-footer no-print"/, 'rolling pager uses a footer-style arrangement');
assert.match(rolling, /\.rolling-footer \{[\s\S]*display:flex;[\s\S]*justify-content:space-between/, 'rolling pager matches the timesheet footer layout');
assert.match(rolling, /\.rolling-page-link \{[\s\S]*min-width:30px;height:28px/, 'rolling page links match the compact timesheet controls');
assert.match(rolling, /class="rolling-pages" aria-label="Rolling plan pagination"/, 'pagination is exposed as a labelled navigation region');
assert.match(rolling, /class="rolling-page-link[^\"]*is-current/, 'current page uses the timesheet-style active link treatment');
assert.match(rolling, /class="rolling-page-size-form"[\s\S]*name="per_page" onchange="this\.form\.submit\(\)"[\s\S]*\(int\)\$pageSize \?> \/ page/, 'page-size select sits in the pagination footer');
assert.match(rolling, /rolling-toolbar,\.rolling-status-tabs,\.rolling-filter-note,\.rolling-footer,\.rolling-no-print/, 'print output hides filter and pagination controls');
assert.match(rolling, /This printout contains the current page only/, 'printed pages disclose the pagination scope');
assert.match(rolling, /Showing <strong class="mono">.*rolling_show_start.*rolling_show_end/s, 'footer reports the visible range and filtered total');
assert.match(rolling, /Out-of-service time in any week/, 'rolling filters disclose the three-week condition');
assert.match(rolling, /conditions can overlap/, 'rolling overlapping status counts are explained');
assert.match(rolling, /\$r\['downtime_hours'\]/, 'screen uses the model-owned downtime value');
assert.doesNotMatch(rolling, /standing_hours\s*\+\s*\$r\['unplanned_hours'\]/, 'screen does not omit corrective downtime by recomputing it');
assert.match(rolling, /base_url\('equipment\/view\/'\s*\.\s*\(int\)\$machine\['equipment_id'\]/, 'machine identifier links to the existing profile route');
const rollingController = source('application/controllers/Forecast.php');
assert.match(rollingController, /'equipment_id'\s*=>\s*\(int\)\$r\['equipment_id'\]/, 'rolling rows carry the existing equipment key for profile navigation');
assert.match(rollingController, /'tables_ready'\s*=>\s*\$tables_ready/, 'rolling controller sends forecast-table readiness to the view');
assert.match(rolling, /The forecast tables are not set up yet/, 'rolling view distinguishes missing tables from an empty draft');
const forecastExcel = source('application/libraries/Forecast_excel.php');
const rollingExcel = forecastExcel.slice(forecastExcel.indexOf('public function build_rolling('), forecastExcel.indexOf('public function paint('));
assert.match(rollingExcel, /' STATUS'/, 'rolling workbook includes weekly status columns');
assert.match(rollingExcel, /\$r\['downtime_hours'\]/, 'rolling workbook uses the model-owned downtime value');
assert.match(rollingExcel, /\$r\['availability'\]/, 'rolling workbook retains model availability values');
// Spares / allowances / parts: shared badges, accessible semantics, usable navigation, and state clarity.
const spares = source('application/views/forecast/spares.php');
assert.match(spares, /class="badge b-/, 'spares view uses the shared badge system');
assert.match(spares, /for="sparesWeek"[\s\S]*id="sparesWeek"/, 'spares week selector has an associated label');
assert.match(spares, /for="sparesHorizon"[\s\S]*id="sparesHorizon"/, 'spares horizon selector has an associated label');
assert.match(spares, /<caption class="spares-sr-only">/, 'spares material table has an accessible caption');
assert.match(spares, /<th scope="col">ITEM<\/th>/, 'spares table headers declare column scope');
assert.match(spares, /type="search" name="q"/, 'spares has item search');
assert.match(spares, /name="coverage" class="form-select spares-coverage"/, 'spares can filter coverage');
assert.match(spares, /name="critical" class="form-select spares-critical"/, 'spares can filter critical items');
assert.match(spares, /name="per_page" onchange="this\.form\.submit\(\)"/, 'spares exposes a page-size selector');
assert.match(spares, /class="spares-pages" aria-label="Spares forecast pagination"/, 'spares pagination has an accessible label');
assert.match(spares, /materials\/inventory\?' \. http_build_query/, 'spares item codes deep-link to inventory details');
assert.match(spares, /elseif \(\(int\)\$schedules === 0\)/, 'spares distinguishes a no-due-schedules state');
assert.match(spares, /empty\(\$table_ready\)/, 'spares distinguishes a missing requirements table');
assert.doesNotMatch(spares, /No PM services fall due in this window/, 'spares no-results state does not mislabel missing parts lists');
assert.match(spares, /one occurrence for each active PM schedule/, 'spares discloses one-next-due schedule scope');
assert.match(spares, /not an item-specific ETA/, 'route lead-time averages are not presented as item ETAs');
assert.match(spares, /outstanding-order tables are unavailable/i, 'spares discloses when open-order quantities cannot be read');
assert.match(spares, /max-width:1000px[\s\S]*spares-kpis[\s\S]*max-width:700px[\s\S]*spares-kpis/, 'spares KPI grid adapts for tablet and mobile');
assert.doesNotMatch(spares, /675 hours|across 44 machines|found out on Thursday/, 'spares page removes historical claims without a source/year');
const partsView = source('application/views/forecast/parts.php');
assert.match(partsView, /does not estimate additional recurring due dates/, 'PM parts editor describes the current forecast scope accurately');
const sparesModel = source('application/models/Forecast_model.php');
assert.match(sparesModel, /next_due_date falls within the supplied window/, 'spares model documentation states the actual input date scope');
assert.match(sparesModel, /date\('d M Y', strtotime\(\$p->next_due_date\)\)/, 'needed-by rows retain the year');
const sparesController = source('application/controllers/Forecast.php');
assert.match(sparesController, /\$data\['on_order_source_ready'\]\s*=\s*\$this->db->table_exists\('spare_orders'\)/, 'spares controller reports on-order source availability');
const allowances = source('application/views/forecast/allowances.php');
assert.match(allowances, /class="badge b-/, 'allowances view uses the shared badge system');
assert.match(allowances, /for="allowanceWeek"[\s\S]*id="allowanceWeek"/, 'week selector has an associated accessible label');
assert.match(allowances, /<caption class="allowance-sr-only">/, 'allowances table has an accessible caption');
assert.match(allowances, /<th scope="col">MACHINE<\/th>/, 'allowances table headers declare column scope');
assert.match(allowances, /type="search" name="q"/, 'allowances has a searchable machine table');
assert.match(allowances, /name="type" class="form-select allowance-type-filter"/, 'allowances can filter by equipment type');
assert.match(allowances, /name="source" class="form-select allowance-source-filter"/, 'allowances can filter by rate source');
assert.match(allowances, /name="per_page" onchange="this\.form\.submit\(\)"/, 'allowances table has an adjustable page size');
assert.match(allowances, /class="allowance-pages" aria-label="Allowance pagination"/, 'allowance pagination has an accessible navigation label');
assert.match(allowances, /base_url\('equipment\/view\/' \. \(int\)\$row\['equipment_id'\]\)/, 'machine registration links to its existing equipment profile');
assert.match(allowances, /History window:<\/strong>/, 'derived rate preview shows exact history dates');
assert.match(allowances, /bounded to 0\.5–24 h\/day/, 'allowance methodology states both the floor and cap');
assert.doesNotMatch(allowances, /historical week-40 forecast|1 894|966|253/, 'allowance view does not show historical figures without a known year/source');
assert.match(allowances, /max-width:820px[\s\S]*allowance-kpis[\s\S]*max-width:480px/, 'allowance KPI layout adapts at tablet and phone widths');
const allowanceController = source('application/controllers/Forecast.php');
assert.match(allowanceController, /'can_edit_settings'\s*=>\s*\$this->is_supervisor\(\)/, 'only supervisor role gets the Settings link flag');
assert.match(allowances, /if \(!empty\(\$can_edit_settings\)\):[\s\S]*base_url\('settings'\)/, 'Settings link is hidden for non-supervisors');
assert.match(allowances, /not a promise that every day receives an entry/, 'rate preview does not promise that every allowance will be written');
assert.doesNotMatch(allowances, /what the generators will write/, 'allowance heading avoids overpromising generator output');
assert.match(allowanceController, /'lookback_from'\s*=>\s*\$history_window\['from'\]/, 'allowances controller passes the model-owned history start');
const forecastModel = source('application/models/Forecast_model.php');
assert.match(forecastModel, /function unplanned_allowance_history_window\(\$week_start\)/, 'model owns the allowance history window');
assert.match(forecastModel, /\$window\s*=\s*\$this->unplanned_allowance_history_window\(\$week_start\)/, 'allowance calculation reuses its displayed history window');
assert.match(forecastModel, /max\(0\.5, min\(24\.0, \$per_day\)\)/, 'allowance floor and ceiling remain in the calculation');
assert.match(partsView, /onsubmit="return confirm\('Remove this part from the service\?'\);"/, 'part removal confirmation does not interpolate catalogue text into JavaScript');
assert.match(partsView, /csrf_field\(\)/, 'part forms carry CSRF protection');
assert.match(partsView, /for="parts-material-id"[\s\S]*id="parts-material-id"/, 'part selector has an associated label');
assert.match(partsView, /for="parts-qty"[\s\S]*id="parts-qty"/, 'quantity field has an associated label');
assert.match(partsView, /for="parts-note"[\s\S]*id="parts-note"/, 'note field has an associated label');
assert.match(partsView, /<caption class="parts-sr-only">/, 'parts table has an accessible caption');
assert.match(partsView, /<th scope="col">ITEM<\/th>/, 'parts table headers declare column scope');
assert.match(partsView, /\$parts_h\(\$unit\)[\s\S]*ON HAND|ON HAND[\s\S]*\$parts_h\(\$unit\)/, 'on-hand quantities carry the material unit');
assert.match(partsView, /spares forecast accounts for[\s\S]*open orders/i, 'parts view explains the difference between current stock and forecast shortage');
assert.doesNotMatch(partsView, /\$r\['on_hand'\]\s*<\s*\$r\['qty'\]/, 'on-hand comparison is not presented as an unqualified shortage');
assert.match(partsView, /class="badge b-critical"/, 'critical parts use the shared badge system');
assert.match(partsView, /materials\/inventory\?[\s\S]*'item'\s*=>\s*\(int\)\$row\['material_id'\]/, 'part codes link to the existing inventory item drawer');
assert.match(partsView, /type="search"[\s\S]*parts-material-search/, 'the material picker has a searchable enhancement');
assert.match(partsView, /option\.textContent\.toLocaleLowerCase\(\)\.indexOf\(query\)/, 'the picker search matches the visible code and description');
assert.match(partsView, /No inventory items are available to choose/, 'the add form explains an empty inventory catalogue');
assert.match(partsView, /@media \(max-width:760px\)[\s\S]*parts-form-grid \{ grid-template-columns:1fr;/, 'parts form stacks on narrow screens');
assert.match(partsView, /parts-table th:first-child,.parts-table td:first-child \{ position:sticky/, 'part identity stays visible while the table scrolls');
assert.match(source('application/controllers/Forecast.php'), /'materials_ready'\s*=>\s*\$this->db->table_exists\('materials'\)/, 'parts controller distinguishes an unavailable inventory catalogue');
assert.match(partsView, /id="parts-search-controls" hidden/, 'the search control is progressively enhanced when JavaScript is available');
const partsScript = partsView.match(/<script>\s*([\s\S]*?)\s*<\/script>/)[1];
const partOption = text => ({textContent: text, hidden: false, selected: false});
const partOptions = [
  {textContent: '— choose a part —', hidden: false, selected: true},
  partOption('AB-1 — oil filter (2 each on hand)'),
  partOption('ZX-9 — drive belt (0 each on hand)')
];
const partsElements = {
  'parts-search-controls': {hidden: true},
  'parts-material-search': {value: '', handlers: {}, addEventListener(type, cb) { this.handlers[type] = cb; }},
  'parts-material-id': {options: partOptions, value: '1'},
  'parts-no-match': {hidden: true}
};
vm.runInNewContext(partsScript, {document: {getElementById: id => partsElements[id]}, Array});
assert.equal(partsElements['parts-search-controls'].hidden, false, 'search activates only after its JavaScript is ready');
const filterParts = partsElements['parts-material-search'].handlers.input;
partsElements['parts-material-search'].value = 'drive';
filterParts();
assert.equal(partOptions[1].hidden, true, 'picker search hides nonmatching parts');
assert.equal(partOptions[2].hidden, false, 'picker search retains a description match');
assert.equal(partsElements['parts-no-match'].hidden, true, 'a matching part hides the no-results message');
partsElements['parts-material-search'].value = 'no such material';
filterParts();
assert.equal(partsElements['parts-no-match'].hidden, false, 'an empty search result is explained');

// ── Lubricants suite: one shared design system across all six surfaces ──
// The suite partial owns the shell chrome (nav, page head, KPI cards, bars,
// badges, empty states, print banner) so no surface can drift on its own.
const lubUi = source('application/views/lubricants/_ui.php');
for (const helper of ['$lub_nav', '$lub_page_head', '$lub_print_banner', '$lub_kpi', '$lub_bar', '$lub_badge', '$lub_empty']) {
  assert.ok(lubUi.includes(helper + ' = function'), helper + ' is provided by the shared lubricants partial');
}
assert.match(lubUi, /#lubShell, #lubBudgetShell, \.lub-page/, 'suite tokens are scoped to the lubricants shells');
assert.match(lubUi, /@media print/, 'suite partial carries print rules');

// Each surface opts into the partial and marks its own nav entry as active.
for (const [view, active] of [
  ['index.php', 'ledger'],
  ['record.php', 'ledger'],
  ['budget.php', 'budget'],
  ['types.php', 'types'],
  ['specifications.php', 'specifications'],
  ['forecast.php', 'forecast']
]) {
  const src = source('application/views/lubricants/' + view);
  assert.match(src, /include __DIR__ \. '\/_ui\.php';/, view + ' includes the shared partial');
  assert.ok(src.includes("$lub_nav('" + active + "')"), view + ' marks the ' + active + ' nav entry active');
  assert.match(src, /\$lub_page_head\(array\(/, view + ' renders the shared page head');
  assert.match(src, /\$lub_print_banner\(/, view + ' prints a report banner');
  assert.doesNotMatch(src, /\.lub-subnav\s*\{/, view + ' must not redeclare suite nav styles');
}
// The budget editor keeps its exact POST contract and calculator hooks.
assert.match(budget, /form_open\('lubricants\/budget'\)/, 'budget posts back to its own endpoint');
assert.match(budget, /name="budget\[<\?= \$lid \?>\]\[qty\]"/, 'budget qty field name is unchanged');
assert.match(budget, /name="budget\[<\?= \$lid \?>\]\[cost\]"/, 'budget rate field name is unchanged');
for (const attr of ['data-pm=', 'data-actual-qty=', 'data-default-rate=']) {
  assert.ok(budget.includes(attr), 'budget calculator keeps the ' + attr + ' attribute');
}
for (const id of ['grandTotal', 'kpiPlannedTotal', 'budgetIssueValue', 'budgetRemaining', 'budgetUtilization', 'bgtFillPmBtn']) {
  assert.ok(budget.includes('id="' + id + '"'), 'budget keeps #' + id);
}
assert.match(budget, /id="total-<\?= \$lid \?>"/, 'budget keeps the per-line total target');
assert.match(budget, /id="issue-value-<\?= \$lid \?>"/, 'budget keeps the per-line issue value target');
assert.match(budget, /budget_csv/, 'budget CSV export is wired');
assert.match(budget, /budget_xlsx/, 'budget Excel export is wired');
assert.doesNotMatch(budget, /\.lub-subnav/, 'budget view drops its private nav copy');

// Types and specifications gate their editors on the roles the controller sends.
const lubeTypes = source('application/views/lubricants/types.php');
assert.match(lubeTypes, /if \(\$can_manage_types\):/, 'type creation is supervisor-gated');
assert.match(lubeTypes, /form_open\('lubricants\/types'\)/, 'type register posts to its endpoint');
assert.match(lubeTypes, /\$lub_empty\(/, 'type catalogue has an illustrated empty state');
const lubeSpecs = source('application/views/lubricants/specifications.php');
assert.match(lubeSpecs, /if \(\$can_write\):/, 'spec editor is write-gated');
assert.match(lubeSpecs, /form_open\('lubricants\/specifications'\)/, 'spec form posts to its endpoint');
assert.match(lubeSpecs, /data-spec-edit/, 'spec rows expose the edit affordance');
assert.ok(lubeSpecs.includes('id="specSaveHint"'), 'spec form keeps its live save hint');
assert.match(lubeSpecs, /\$lub_empty\(/, 'spec master has an illustrated empty state');
// Record keeps its dependent work-order filter and every required field.
const lubeRecord = source('application/views/lubricants/record.php');
for (const field of ['name="equipment_id"', 'name="lube_type_id"', 'name="quantity_used"', 'name="consumption_date"', 'name="work_order_id"']) {
  assert.ok(lubeRecord.includes(field), 'record form keeps ' + field);
}
assert.match(lubeRecord, /data-equipment=/, 'work-order options carry their machine for filtering');
assert.match(lubeRecord, /opt\.disabled = off/, 'off-machine work orders are disabled, not just hidden');
// Forecast keeps a sticky first column and a mobile month card fallback.
const lubeForecast = source('application/views/lubricants/forecast.php');
assert.match(lubeForecast, /position: sticky; left: 0/, 'forecast pins the month column');
assert.match(lubeForecast, /\.lub-forecast-months \{ display: grid/, 'forecast swaps to month cards on small screens');
assert.doesNotMatch(lubeForecast, /kpi-glow/, 'forecast drops the inert decorative glow');

// ── Reports / Asset Register: actual register data, actions, and table UX ──
const assetRegister = source('application/views/reports/asset_register.php');
const assetModel = source('application/models/Asset_model.php');
for (const control of ['id="assetSearch"', 'id="assetTypeFilter"', 'id="assetPageSize"', 'data-asset-status=', 'data-asset-view=', 'id="assetPrevPage"', 'id="assetNextPage"']) {
  assert.ok(assetRegister.includes(control), 'asset register keeps ' + control);
}
for (const [field, label] of [
  ['registration_no', 'Machine ID'], ['type_name', 'Type'], ['brand_model', 'Brand / model'],
  ['serial_number', 'Serial no.'], ['engine_model', 'Engine'], ['current_hours', 'Hours'],
  ['current_km', 'KM'], ['total_wo', 'Work orders'], ['breakdowns', 'Breakdowns'],
  ['total_down', 'Downtime hours'], ['availability', 'Availability'],
  ['warranty_expiry', 'Warranty'], ['next_pm_date', 'Next PM'], ['notes', 'Notes']
]) {
  assert.ok(assetRegister.includes('data-card-label="' + label + '"'), 'asset register retains ' + field);
}
assert.match(assetRegister, /data-search="<\?= \$asset_h\(implode\(' ', \$searchable\)\) \?>"/, 'search indexes the record values emitted by the server');
assert.match(assetRegister, /function update\(\)[\s\S]*activeStatus[\s\S]*selectedType[\s\S]*term/, 'status, type, and search filters combine in the results pass');
assert.match(assetRegister, /function sortRows\(\)[\s\S]*sortDirection/, 'table headers drive real client-side sorting');
assert.match(assetRegister, /matches\.slice\(first, last\)/, 'pagination controls visible records rather than just styling the toolbar');
assert.match(assetRegister, /function makeCard\(row\)/, 'card view is built from each actual table row');
assert.match(assetRegister, /base_url\('equipment\/view\/'\./, 'asset identifiers open the existing equipment profile');
assert.match(assetRegister, /reports\/export_asset_register_xlsx/, 'full workbook export remains available');
assert.match(assetRegister, /reports\/import_asset_register/, 'existing import flow remains available');
assert.match(assetRegister, /if \(\$role_id == 1\)/, 'asset creation and import affordances remain supervisor-gated');
assert.match(assetModel, /\$a\['warranty_days'\] <= 0/, 'expired-today assets are included in the expired-warranty KPI');

console.log('Table surface, budget calculator, and Asset Register regressions: passed');
