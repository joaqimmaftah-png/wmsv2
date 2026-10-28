// Structural regressions for the Worker Profile (team/view) tables.
// Run with: node tests/team_profile_ux.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const src = f => fs.readFileSync(path.join(root, f), 'utf8');

const view = src('application/views/team/view.php');
const controller = src('application/controllers/Team.php');

// --- Controller: paged/tabbed datasets for the profile cards --------------------
assert.ok(controller.includes('cert_counts') && controller.includes("'no_expiry'"),
  'profile certifications dataset computes tab counts incl. no_expiry');
assert.ok(controller.includes('display_status') && controller.includes("'No expiry date'"),
  'NULL expiry displays as No expiry date on the profile (tracker parity)');
assert.ok(controller.includes('leave_counts') && controller.includes('lper_page'),
  'leave history dataset computes status counts and pagination');

// --- View: certifications card ----------------------------------------------------
assert.ok(view.includes('aria-label="Certification status"') && view.includes('role="tablist"'),
  'certification status tabs with tablist semantics');
assert.ok(view.includes("$display_status === 'Valid' ? 'b-available'"),
  'certification badges use the shared tinted badge system');
assert.ok(!view.includes("bg-success' : ($c->status"), 'retired solid badge logic removed from cert rows');
assert.ok(view.includes('aria-label="Open document for'), 'document links describe their purpose');
assert.ok(view.includes('No document'), 'missing documents are explicit');
assert.ok(view.includes("form_open('team/delete_certification/'"), 'delete uses the existing endpoint');
assert.ok(view.includes('data-cert-edit-btn') && view.includes('editCertDialog'),
  'Renew/Edit dialog remains wired');
assert.ok(view.includes('No expired certifications'), 'tab empty states explain themselves');

// --- View: leave history ------------------------------------------------------------
assert.ok(view.includes('aria-label="Leave status"'), 'leave status tabs exist');
assert.ok(view.includes("'b-available'"), 'leave badges use tinted house classes');
assert.ok(view.includes('No pending leave requests'), 'leave tab empty states exist');

// --- View: accessibility & labels -----------------------------------------------------
assert.ok(view.includes('role="img"') && view.includes('aria-label="<?= htmlspecialchars($title'),
  'attendance day cells carry accessible names, not colour alone');
assert.ok(view.includes('Last 30 days:'), 'attendance strip has a visible text summary');
assert.ok(view.includes('for="addCert_cert_name"'), 'add-certification labels are associated');
assert.ok(view.includes('for="vpTsMonth"') && view.includes('for="vpTsPeriod"'), 'timesheet form labels are associated');
assert.ok(view.includes('scope="col"'), 'table headers expose scope');

console.log('Worker Profile table UX structural checks passed.');
