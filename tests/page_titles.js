// Dependency-free regression checks for global-header / main-page title ownership.
// Run with: node tests/page_titles.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const source = file => fs.readFileSync(path.join(root, file), 'utf8');
const viewSource = view => source(`application/views/${view}.php`);

const sidebar = viewSource('layouts/sidebar');
const footer = viewSource('layouts/footer');
const header = viewSource('layouts/header');
const topbar = sidebar.split('<header class="topbar">')[1].split('</header>')[0];

assert.ok(!topbar.includes('page_title'), 'the global header must not render the page title');
assert.ok(!topbar.includes('topbar-greeting'), 'the global header must not render the Dashboard greeting');
assert.ok(topbar.includes('topbar-actions'), 'global actions remain in the header');
assert.ok(topbar.includes('global-find-trigger'), 'Global Find remains available in the utility header');
assert.ok(topbar.includes('attention-trigger'), 'operational attention remains available');
assert.ok(sidebar.includes('spareparts/alerts?status=New'), 'parts attention links to the filtered availability queue');
assert.ok(topbar.includes('tb-user-trigger'), 'the account disclosure remains available');
assert.ok(sidebar.includes('<main class="page" id="main-content">'), 'the content canvas uses a main landmark');
assert.ok(footer.includes('</main><!-- /.page -->'), 'the main landmark is closed by the footer');
assert.ok(header.includes('<title>'), 'the browser document title is preserved');
assert.ok(!/\.topbar-row\s*\{[^}]*flex-direction\s*:\s*column/.test(header),
  'mobile utility controls must not use the obsolete stacked-title row');

const dashboard = viewSource('dashboard/index');
assert.ok(dashboard.includes('<h1 class="main-page-title">'), 'Dashboard owns its main h1');
assert.ok(dashboard.includes('main-page-greeting'), 'Dashboard greeting moved into the main content');
assert.ok(dashboard.includes('<time datetime='), 'Dashboard date uses a time element');

const controllers = fs.readdirSync(path.join(root, 'application/controllers'))
  .filter(file => file.endsWith('.php'))
  .map(file => source(`application/controllers/${file}`))
  .join('\n');
const views = new Set();
for (const match of controllers.matchAll(/->render\(\s*(['"])([^'"]+)\1/g)) {
  views.add(match[2]);
}
assert.ok(views.size >= 80, `render-view discovery failed: found only ${views.size} views`);

const lubricantsUi = viewSource('lubricants/_ui');
const inspectionsIndex = viewSource('inspections/index');
for (const view of [...views].sort()) {
  let rendered = viewSource(view);
  if (view === 'inspections/records') {
    assert.ok(rendered.includes("include __DIR__ . '/index.php'"),
      'inspection records must delegate to the unified register');
    rendered += inspectionsIndex;
  }
  if (view.startsWith('lubricants/')) {
    assert.ok(rendered.includes("include __DIR__ . '/_ui.php'"),
      `${view} must include the shared lubricants UI`);
    assert.ok(rendered.includes('$lub_page_head(array('),
      `${view} must render its shared page heading`);
    rendered += lubricantsUi;
  }

  const openings = (rendered.match(/<h1\b/g) || []).length;
  const closings = (rendered.match(/<\/h1>/g) || []).length;
  if (view === 'reports/weekly_w36') {
    // Print and screen are mutually exclusive branches of the same view.
    assert.ok(rendered.includes('<?php if ($print_mode): ?>'), 'W36 keeps its print branch');
    assert.ok(rendered.includes('<?php if (!$print_mode): ?>'), 'W36 keeps its screen branch');
    assert.equal(openings, 2, 'W36 must have one print h1 and one screen h1');
    assert.equal(closings, 2, 'W36 must close both alternate h1 elements');
    continue;
  }
  assert.equal(openings, 1, `${view} must have exactly one main h1`);
  assert.equal(closings, 1, `${view} must close its main h1 exactly once`);
  assert.ok(!rendered.includes('<div class="page-heading"'),
    `${view} must not revert its page heading to a div`);
}

console.log(`Page-title ownership checks passed for ${views.size} rendered views.`);
