// Run with: node tests/weekly_report_visual_consistency.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const view = fs.readFileSync('application/views/reports/weekly_w36.php', 'utf8');
const dashboard = fs.readFileSync('application/views/dashboard/index.php', 'utf8');
const transactions = fs.readFileSync('application/views/materials/transactions.php', 'utf8');
const screen = view.slice(view.indexOf('/* Screen-only slate surfaces'), view.indexOf('</style>', view.indexOf('/* Screen-only slate surfaces')));
for (const color of ['#0F172A', '#64748B', '#E2E8F0', '#CBD5E1', '#1D4ED8', '#F8FAFC']) {
  assert.ok(screen.includes(color), `${color} appears in weekly report`);
  assert.ok(transactions.includes(color), `${color} matches transactions`);
}
assert.ok(dashboard.includes('--sk-accent:#1D4ED8') && dashboard.includes('--sk-border:#E2E8F0'));
assert.match(screen, /@media screen/);
assert.match(view, /\$print_mode \? ' w36-print' : ' w36-screen'/);
assert.match(screen, /font-family:inherit/);
assert.match(screen, /\.w36-syn-kpi, \.w36-screen \.w36-reg-kpi/);
assert.match(screen, /\.w36-btn-primary:hover/);
assert.match(screen, /:focus-visible/);
assert.match(view, /\.w36-print \.w36-syn-kpis/);
console.log('Weekly report screen visual consistency checks passed.');
