// Dependency-free structural regressions for Global Find + Operational Attention.
// Run with: node tests/global_find_attention.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const source = file => fs.readFileSync(path.join(root, file), 'utf8');

const base = source('application/core/MY_Controller.php');
const routes = source('application/config/routes.php');
const searchController = source('application/controllers/Search.php');
const searchModel = source('application/models/Search_model.php');
const attentionModel = source('application/models/Attention_model.php');
const sparepartsModel = source('application/models/Spareparts_model.php');
const sidebar = source('application/views/layouts/sidebar.php');
const header = source('application/views/layouts/header.php');
const footer = source('application/views/layouts/footer.php');
const inventory = source('application/views/materials/inventory.php');
const js = source('assets/js/global-find.js');

assert.ok(base.includes("'search'      => array(1, 2, 3, 4)"),
  'Global Find is restricted to operational roles and excludes CEO');
assert.ok(routes.includes("$route['search'] = 'search/index';"), 'the search route is explicit');
assert.ok(searchController.includes("!== 'get'"), 'search is read-only');
assert.ok(searchController.includes('Cache-Control: no-store'), 'role-scoped results are not cached');
assert.ok(searchModel.includes("if ($role_id === 2) $this->_scope_technician_work_orders"),
  'technician WO results use server-side record scoping');
assert.ok(searchModel.includes("wo.assigned_to LIKE '%,{$uid},%'"),
  'technician assignment matching handles an interior CSV entry');
assert.ok(searchModel.includes("wo.assigned_to LIKE '%,{$uid}'"),
  'technician assignment matching handles the final CSV entry');
assert.ok(searchModel.includes("base_url('workorders/view/'"), 'WO results open the record');
assert.ok(searchModel.includes("base_url('equipment/view/'"), 'equipment results open the record');
assert.ok(searchModel.includes("rawurlencode((string)$row->item_code)"),
  'inventory deep-links safely carry the exact item-code filter');
assert.ok(searchModel.includes("or_like('part_number_size'"), 'part numbers are searchable');
assert.ok(searchModel.includes('CASE WHEN wo.wo_number ='), 'exact WO identifiers rank first');
assert.ok(searchModel.includes('CASE WHEN item_code ='), 'exact item codes rank first');

assert.ok(sidebar.includes('aria-haspopup="dialog"'), 'the Find trigger identifies its dialog');
assert.ok(sidebar.includes('id="operational-attention"'), 'the attention disclosure is present');
assert.ok(sidebar.includes('settings/security'), 'sign-in security remains a separate destination');
assert.ok(footer.includes('role="dialog" aria-modal="true"'), 'Global Find uses modal dialog semantics');
assert.ok(footer.includes('id="global-find-status" role="status" aria-live="polite"'),
  'search outcome announcements are available to assistive technology');
assert.ok(js.includes('(event.ctrlKey || event.metaKey)'), 'Ctrl and Command are both supported');
assert.ok(js.includes("key === 'k'"), 'the global shortcut is K');
const closedGuard = js.indexOf('if (!isOpen) return;');
const slashGuard = js.indexOf("event.key === '/'");
assert.ok(closedGuard !== -1 && slashGuard > closedGuard,
  'the / guard applies only inside Global Find, preserving page-local shortcuts');
assert.ok(js.includes('function trapTab'), 'modal focus is contained');
assert.ok(js.includes('activeController.abort()'), 'stale searches are cancelled');
assert.ok(js.includes('node.textContent = String(text)'), 'record text is inserted without HTML parsing');
assert.ok(header.includes('.global-find-panel { width:100%;'), 'the mobile Find dialog is full width');
assert.ok(/\.topbar-actions\s*\{[^}]*width:100%/.test(header),
  'the utility row spans the header so Find can anchor the start');
assert.ok(/\.global-find-trigger\s*\{[^}]*margin-right:auto/.test(header),
  'Find stays left-aligned while the other utilities remain right-aligned');
assert.ok(/\.global-find-trigger\s*\{[^}]*width:clamp\(240px,32%,360px\)/.test(header),
  'Find uses available desktop space without crowding narrower layouts');
assert.ok(header.includes('.global-find-root[hidden]'), 'hidden dialog state wins over display styling');

assert.ok(attentionModel.includes("base_url('workorders?type=Incident')"),
  'incident attention links to the incident queue');
assert.ok(attentionModel.includes("base_url('pm?tab=overdue')"),
  'PM attention links to the overdue queue');
assert.ok(attentionModel.includes("base_url('workorders?tab=completed')"),
  'verification attention links to the completed queue');
assert.ok(attentionModel.includes("base_url('spareparts/alerts?status=New')"),
  'parts attention links to newly available parts');
assert.ok(attentionModel.includes('if ($role === 1)'), 'verification is supervisor-only');
assert.ok(attentionModel.includes("if ((int)$role_id === 2) $this->_scope_technician_work_orders"),
  'technician incident alerts use the same WO record scope');
assert.ok(attentionModel.includes("(int)$role_id === 3"),
  'store part alerts avoid unauthorized WO destinations');
assert.ok(sparepartsModel.includes('get_recent_alerts($user_id, $role_id, $limit = 6, $status = NULL)'),
  'recent parts alerts can be limited to newly available records');
assert.ok(inventory.includes('window.location.search.match(/[?&]item='),
  'inventory recognizes the Global Find item deep-link');
assert.ok(inventory.includes('openDrawer(initialItemId, initialOpener)'),
  'inventory deep-links open the existing item drawer');

console.log('Global Find and Operational Attention structural checks passed.');
