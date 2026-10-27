// Structural regressions for the Certifications Tracker enterprise table.
// Run with: node tests/certifications_table_ux.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const src = f => fs.readFileSync(path.join(root, f), 'utf8');

const view = src('application/views/team/certifications.php');
const controller = src('application/controllers/Team.php');
const model = src('application/models/Team_model.php');

// --- Dataset pipeline (controller) -------------------------------------------
assert.ok(controller.includes('_build_certifications_dataset()'),
  'tracker uses a shared server-side dataset builder');
assert.ok(controller.includes('public function certifications_csv()'),
  'filtered CSV export endpoint exists');
assert.ok(controller.includes("in_array($tab_in, $valid_tabs, TRUE)") || controller.includes("$valid_tabs = array('all', 'expired', 'expiring', 'valid', 'no_expiry', 'none')"),
  'status tab input is whitelisted');
assert.ok(controller.includes("$valid_sort = array('expiry', 'worker', 'certificate', 'issued', 'status')"),
  'sort column input is whitelisted');
assert.ok(controller.includes('array(10, 25, 50, 100)') && controller.includes("$per_page"),
  'page size is restricted to 10/25/50/100');
assert.ok(controller.includes("$can_view_all = ($this->is_supervisor() || $this->is_manager())"),
  'workforce scope is supervisor/manager only');
assert.ok(controller.includes("$only_uid     = $can_view_all ? NULL : (int)$this->user_id"),
  'other roles are scoped to their own certificates (CERT-01)');
assert.ok(controller.includes("strpos($hay, $q_low) === FALSE"),
  'search is applied before tab counts');
assert.ok(controller.includes("'none' => 0") && controller.includes("isset($cert_user_ids[(int)$w->id])"),
  'workers with zero certificates form the None Recorded segment');
assert.ok(controller.includes("\x22'%\x22") === false, 'no stray quoting in builder');
assert.ok(controller.includes("fwrite($out, \"\\xEF\\xBB\\xBF\")"),
  'CSV export writes UTF-8 BOM like roster_csv');
assert.ok(controller.includes("$safe_cell"), 'CSV export sanitises formula-injection cells');

// --- Model --------------------------------------------------------------------
assert.ok(model.includes('function get_certifications_overview($only_user_id = NULL)'),
  'model returns all-status certification records joined with workers');
assert.ok(model.includes("->join('users u', 'u.id = c.user_id', 'inner')")
  || model.includes("->join('users u', 'u.id = c.user_id"),
  'certification overview joins the worker record');

// --- View: structure ----------------------------------------------------------
assert.ok(view.includes('aria-label="Certification summary"'), 'KPI strip is labelled');
assert.ok(view.includes('Certificates in Scope') && view.includes('No Certificates'),
  'KPI strip includes total and workers-without-certificates');
assert.ok(view.includes('role="tablist"') && view.includes('aria-selected'),
  'status tabs use tablist semantics with counts');
assert.ok(view.includes("'All Certificates'") && view.includes("'None Recorded'"),
  'tabs include All and None Recorded');
assert.ok(view.includes('aria-sort') && view.includes('scope="col"'),
  'sortable headers expose aria-sort and scope');
assert.ok(view.includes('ct-autosubmit'), 'scope filters auto-submit');
assert.ok(view.includes("id=\"ctSearch\"") && view.includes("e.key === '/'"),
  'search input with / keyboard shortcut');
assert.ok(view.includes('aria-pressed') && view.includes("'Cards'") === false || view.includes('> Cards'),
  'view toggle exposes pressed state');
assert.ok(view.includes('Active filters') || view.includes('aria-label="Active filters"'),
  'active filter chips strip exists');
assert.ok(view.includes('aria-current="page"') && view.includes('Showing'),
  'pagination footer with showing summary');
assert.ok(view.includes('ct-print-banner'), 'print banner exists');
assert.ok(view.includes('@media print') && view.includes('@media(max-width:760px)'),
  'print and mobile breakpoints exist');

// --- View: data & safety -------------------------------------------------------
assert.ok(view.includes("htmlspecialchars((string)$c->worker_name"),
  'worker name is escaped in the table (CERT-01 follow-up)');
assert.ok(view.includes("htmlspecialchars($w->full_name"),
  'worker name is escaped in None Recorded rows');
assert.ok(!view.includes('$w->first_name .') , 'unescaped name concatenation removed');
assert.ok(view.includes('badge b-danger') || view.includes("$bcls"),
  'status badges use the shared tinted badge system via $badge_of');
assert.ok(view.includes("'No expiry date'"), 'no-expiry records are labelled, not silently Valid');
assert.ok(view.includes('No document'), 'missing documents are explicit');
assert.ok(view.includes('aria-label="Open '), 'document links describe their purpose');
assert.ok(view.includes("form_open('team/delete_certification/'"),
  'supervisor delete uses the existing authorised endpoint (CSRF via form_open)');
assert.ok(view.includes("!empty($is_supervisor)"), 'delete action is supervisor-gated in the UI');
assert.ok(view.includes('Delete this certification? The attached document is removed too.'),
  'delete confirmation states the irreversible consequence');
assert.ok(view.includes('Every worker in scope has a certificate recorded'),
  'None Recorded empty state explains itself');
assert.ok(view.includes('No certificates match the current filters'),
  'filtered-empty state exists with guidance');
assert.ok(!view.includes('bg-success') && !view.includes('bg-warning text-dark'),
  'retired solid Bootstrap badges removed');
assert.ok(!/#1a3c5e|#dc2626|#94a3b8/.test(view),
  'hard-coded hex colours replaced by design tokens');


// --- Renew/Edit, inspector drawer, early-warning band (2026-10-27) ------------
const modelSrc = src('application/models/Team_model.php');
const profileSrc = src('application/views/team/view.php');
assert.ok(modelSrc.includes('function update_certification'),
  'Team_model.update_certification missing');
assert.ok(controller.includes('function edit_certification'), 'Team::edit_certification missing');
assert.ok(controller.includes('function certifications_json'), 'Team::certifications_json missing');
const editBlock = controller.slice(controller.indexOf('function edit_certification'), controller.indexOf('function edit_certification') + 3200);
assert.ok(editBlock.includes('require_post()'), 'edit endpoint must be POST-only');
assert.ok(editBlock.includes('is_supervisor()'), 'edit endpoint must be supervisor-only server-side');
const jsonBlock = controller.slice(controller.indexOf('function certifications_json'), controller.indexOf('function certifications_json') + 2600);
assert.ok(jsonBlock.includes('You can only inspect your own certificates'),
  'inspector must scope non-privileged roles to their own records');
assert.ok(controller.includes("'upcoming'") && view.includes("'upcoming'"),
  'early-warning band tab exists in builder and view');
assert.ok(view.includes('Valid (>90d)'), 'valid tab relabelled when the band exists');
assert.ok(view.includes('ctDrawer') && view.includes('data-cert-inspect') && view.includes('team/certifications_json/'),
  'inspector drawer fed by the JSON endpoint');
assert.ok(view.includes('ctEditDialog') && view.includes('data-cert-edit') && view.includes('team/edit_certification/'),
  'tracker has supervisor Renew/Edit dialog wired to the endpoint');
assert.ok(profileSrc.includes('editCertDialog') && profileSrc.includes('data-cert-edit-btn') && profileSrc.includes('team/edit_certification/'),
  'profile has Renew/Edit dialog wired to the same endpoint');

console.log('Certifications Tracker table UX structural checks passed.');
