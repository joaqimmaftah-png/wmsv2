// Dependency-free structural regressions for the /reports hub.
// Run with: node tests/reports_hub.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const source = file => fs.readFileSync(path.join(root, file), 'utf8');

const view       = source('application/views/reports/index.php');
const controller = source('application/controllers/Reports.php');
const w36model   = source('application/models/W36_report_model.php');
const team       = source('application/controllers/Team.php');
const materials  = source('application/controllers/Materials.php');
const equipment  = source('application/controllers/Equipment.php');

const cStart = controller.indexOf('public function index()');
const cEnd   = controller.indexOf('public function daily()');
const index  = controller.slice(cStart, cEnd);

/* ── Page ownership and semantics ───────────────────────────────────── */
assert.equal((view.match(/<h1\b/g) || []).length, 1, 'exactly one main h1');
assert.ok(view.includes('<h1 class="main-page-title">'), 'title belongs to main content');
assert.ok(!view.includes('<div class="page-heading"'), 'no legacy heading div');
assert.ok(view.includes('aria-labelledby="rpt-lib-h"'), 'report library is a labelled section');
assert.ok((view.match(/<h2 /g) || []).length >= 3, 'named panels are real headings');

/* ── Works without JavaScript ───────────────────────────────────────── */
assert.ok(!/<script\b/i.test(view), 'the hub ships no inline JavaScript');
assert.ok(!view.includes('onclick='), 'no inline click handlers');
assert.ok(!view.includes('addEventListener('), 'no JS dependency');
assert.ok(!view.includes('.href ='), 'no JS href rewriting');
assert.ok(view.includes('method="get"'), 'report launchers are ordinary GET forms');
assert.ok(view.includes('formaction='), 'exports reuse the same form and period');
assert.ok(!/method="post"/i.test(view), 'the hub exposes no write actions');

/* ── Honest, dependency-free chart ──────────────────────────────────── */
assert.ok(view.includes('class="rpt-chart-svg"'), 'trend is a server-rendered SVG');
assert.ok(view.includes('viewBox="0 0 100 100"'), 'fixed 0–100 axis');
assert.ok(view.includes('stroke-dasharray='), 'target line is drawn');
assert.ok(view.includes('role="img"'), 'chart has an image role');
assert.ok(view.includes('<table class="rpt-sr">'), 'chart has an accessible data table');
assert.ok(!view.includes('<canvas'), 'no blank canvas when the CDN is offline');
assert.ok(!view.includes('new Chart('), 'no chart-library dependency');

/* ── Honest empty / partial / unavailable states ────────────────────── */
assert.ok(view.includes('Operational figures are unavailable.'), 'data-unavailable banner');
assert.ok(view.includes('Not enough data for a trend'), 'limited-data state');
assert.ok(view.includes('Nothing needs attention'), 'all-clear state');
assert.ok(view.includes(": '—'"), 'unavailable KPIs show a dash, not a zero');
assert.ok(view.includes('no breakdowns in window'), 'undefined MTTR/MTBF are named, not faked');
assert.ok(view.includes('@media (prefers-reduced-motion:reduce)'), 'reduced motion honoured');
assert.ok(view.includes('focus-visible'), 'visible keyboard focus');

/* ── Five-state W36 publication ladder ──────────────────────────────── */
assert.ok(w36model.includes('public function published_week_starts('),
  'W36 exposes a read-only publication census (row presence, not payload decode)');
assert.ok(controller.includes('_publication_window') && controller.includes('_publication_state'),
  'deadline and state classification are single, testable helpers');
assert.ok(controller.includes('PUBLICATION_LOOKBACK_WEEKS'),
  'the look-back over completed weeks is bounded');
assert.ok(index.includes('published_week_starts('), 'index() checks completed weeks');
assert.ok(controller.includes('$this->_publication_window($week_end)'),
  'the deadline derives from the configured week end, not an invented SLA');
assert.ok(view.includes("'w36_pub'") && view.includes("'state' => 'unknown'"),
  'publication state has an honest default');
for (const s of ['unknown', 'published', 'draft_in_progress', 'draft_due_soon', 'unpublished_action_required']) {
  assert.ok(view.includes("'" + s + "' => array("), `publication state ${s} is declared`);
}
for (const [priority, display] of [
  ['Informational', 'Draft — Week in progress'],
  ['Warning', 'Draft — Publication due soon'],
  ['High', 'Unpublished — Action required'],
  ['No action', 'Published'],
]) {
  assert.ok(view.includes(priority) && view.includes(display),
    `priority ${priority} carries its own display text`);
}
assert.ok(view.includes("'crit' => 0, 'high' => 1, 'warn' => 2, 'info' => 3"),
  'High ranks above Warning and Attention');
assert.ok(view.includes("'high' => 'High'"), 'High is word-labelled, not colour-only');
assert.ok(view.includes("'tone' => 'high'") && view.includes('Review & publish'),
  'High items are actionable with a real destination');
assert.ok(view.includes('Publication due soon') && view.includes('Review draft'),
  'the Warning step is actionable on the deadline-approaching day');
assert.ok(view.includes('was published — no action needed'),
  'an already published previous week is explicitly No action');
assert.ok(view.includes('unpublished — Overdue'),
  'older unpublished completed weeks are Overdue');
assert.ok(view.includes('reports/weekly_w36?week_start='),
  'every publication item links by exact week start, honouring a non-Monday week start');
assert.ok(!view.includes('if (!$w36_published)'),
  'the in-progress draft is not blanket-added to the attention list');
assert.ok(view.includes('$h($item[\'note\'])'), 'the publication note is escaped');
assert.ok(/\.rpt-exc-act\s*\{[^}]*min-height:24px/.test(view) &&
  /\.rpt-panel-link\s*\{[^}]*min-height:24px/.test(view),
  'inline report links meet the 24px target-size minimum');

/* Honest partial states for the publication check itself */
assert.ok(view.includes('Status unavailable'), 'unreadable DB is not reported as a known draft');
assert.ok(view.includes("$defaults['w36_pub']"), 'a partial telemetry payload cannot lose state keys');
assert.ok(view.includes('Publishing is unavailable because the snapshot table'),
  'a missing snapshot table is disclosed, not treated as an unpublished week');
assert.ok(view.includes('Weekly publication could not be checked'),
  'the all-clear sentence admits when publication could not be checked');
assert.ok(view.includes('most recent completed weekly reports'),
  'the all-clear sentence names the bounded window it checked');

/* ── User-controlled weekly report name is escaped, never trusted ────── */
assert.ok(view.includes("'title' => $report_name . ' Weekly Report'"), 'name stays raw in the spec');
assert.ok(view.includes("$h($item['title'])"), 'row titles are escaped at render');
assert.ok(view.includes("$h($ex['label'])"), 'exception labels are escaped at render');
assert.ok(!/<\?=\s*\$report_name\s*\?>/.test(view), 'no raw report name in markup');

/* ── The hub reads real models, guarded, and keeps the picker JS-free ─── */
assert.ok(cStart !== -1 && cEnd > cStart, 'index() located');
for (const call of [
  'get_availability_window()', 'get_availability_trend(30)', 'count_overdue_pm()',
  'get_reorder_items()', 'snapshot_of($week_start)', 'availability_target_pct()',
  'get_fleet_kpi_by_type()', 'iso_week_label($week_start)',
]) {
  assert.ok(index.includes(call), `index() reads ${call}`);
}
assert.ok(index.includes('catch (Throwable $e)'), 'optional-table failure is caught');
assert.ok(index.includes("'available'           => false"), 'fallback is explicitly unavailable');
assert.ok(/function equipment_history\(\$id = null\)/.test(controller),
  'equipment history accepts the picker id from the query string');
assert.ok(controller.includes("$id = $this->input->get('id', TRUE);"), 'picker id is read safely');

/* ── Every launcher resolves to a real controller method ─────────────── */
const refs = [...view.matchAll(/'(reports\/[^']+)'/g)].map(m => m[1]);
assert.ok(refs.length >= 25, `expected the full report catalogue, found ${refs.length}`);
const missing = [];
for (const ref of new Set(refs)) {
  const method = ref.split('#')[0].split('?')[0].split('/')[1];
  if (method && !new RegExp('public function ' + method + '\\s*\\(').test(controller)) {
    missing.push(ref);
  }
}
assert.deepEqual(missing, [], 'every reports/ link maps to a real method: ' + missing.join(', '));

assert.ok(/public function report\s*\(/.test(team), 'team/report exists');
assert.ok(/public function timesheets_batch\s*\(/.test(team), 'batch timesheets exist');
assert.ok(/public function inventory\s*\(/.test(materials), 'inventory list exists');
assert.ok(/public function index\s*\(/.test(equipment), 'equipment index exists');

console.log(`Reports hub structural checks passed (${new Set(refs).size} report endpoints verified).`);
