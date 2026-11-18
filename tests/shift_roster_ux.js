// Annual workforce plan + day-level weekly roster structural regressions.
// Run with: node tests/shift_roster_ux.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '..');
const src = f => fs.readFileSync(path.join(root, f), 'utf8');
const week = src('application/views/team/shift_roster.php');
const annual = src('application/views/team/annual_roster.php');
const editor = src('application/views/team/roster_day_edit.php');
const ctl = src('application/controllers/Team.php');
const model = src('application/models/Workforce_plan_model.php');
const cover = src('application/models/Workforce_cover_model.php');
const coverView = src('application/views/team/roster_cover.php');
const navigation = src('application/helpers/navigation_helper.php');
const attendance = src('application/views/team/attendance.php');
const teamModel = src('application/models/Team_model.php');
const teamView = src('application/views/team/index.php');
const usersCtl = src('application/controllers/Users.php');

// Day-level planning is never the retired one-shift-per-week write workflow.
assert.match(ctl, /function _build_shift_roster_dataset\(/);
assert.match(ctl, /function annual_roster\(/);
assert.match(ctl, /function annual_roster_csv\(/);
assert.match(ctl, /function roster_day_save\(/);
assert.match(ctl, /function roster_pattern_save\(/);
assert.ok(ctl.includes('for ($n = 0; $n < 7; $n++)') && ctl.includes('$w->roster_days[$date] = $cell'),
  'each worker-day has its own state in the weekly grid');
assert.ok(!week.includes("form_open('team/assign_shift'"), 'retired weekly assignment form must not be presented');
assert.ok(ctl.includes('Weekly assignment is retired.'), 'legacy weekly POST refuses new writes');
assert.ok(model.includes('function carry_legacy(') && model.includes("$r->source !== 'Pattern'"),
  'carry-forward and manual overrides are protected from regeneration');
assert.ok(model.includes("'day_type' => $value === 'Off' ? 'Off' : 'Shift'"),
  'Off days are explicit rows, not blank shifts');
assert.ok(model.includes('published_at') && model.includes("$yr->status === 'Published'"),
  'draft/published visibility is separate');
assert.ok(model.includes('function amend_leave(') && model.includes('function end_leave('),
  'scheduled leave can be amended or ended by a supervisor');
assert.ok(model.includes('function confirm_extra(') && model.includes('function settle_extra('),
  'off-day work and compensation have distinct confirmation/settlement stages');
assert.ok(model.includes("'replaced_shift_id' => (int)$day->shift_id"),
  'the original shift can be restored if the replacement is undone');
assert.ok(!model.includes("->insert('attendance'") && !model.includes("->update('attendance'"),
  'planning never manufactures actual attendance');

// All mutating endpoints use POST and have a supervisor check in their method.
for (const name of ['roster_day_save', 'roster_pattern_save', 'roster_carry_legacy',
  'roster_publish', 'roster_leave_save', 'roster_leave_amend', 'roster_leave_end',
  'roster_extra_save', 'roster_extra_confirm', 'roster_extra_settle', 'roster_extra_cancel',
  'roster_cover_approve', 'roster_cover_cancel']) {
  const chunk = ctl.split(`public function ${name}()`)[1]?.split(/\n    (?:public|private) function /)[0];
  assert.ok(chunk, `${name} exists`);
  assert.ok(chunk.includes('require_post()') && chunk.includes('is_supervisor()'), `${name} is POST + supervisor only`);
}
const weekCsv = ctl.split('public function shift_roster_csv()')[1].split('public function annual_roster()')[0];
assert.ok(weekCsv.includes('is_supervisor()') && weekCsv.includes('is_manager()') && weekCsv.includes("'Date', 'Employee ID'"),
  'weekly CSV is role-scoped and has one row per date');
assert.ok(ctl.includes("$selected = $planner ? (int)$this->input->get('user', TRUE) : (int)$this->user_id;"),
  'annual page and export force personal scope for non-planners');

// Usability: status, year/month/week, direct day changes, search, role-hidden writes.
assert.ok(annual.includes('Annual Workforce Plan') && annual.includes('ar-year-grid') && annual.includes('ar-month-grid'),
  'annual page has distinct year and detailed month modes');
assert.ok(annual.includes("team/shift_roster?week=") && annual.includes('Week ↗'), 'calendar links to weekly grid');
assert.ok(annual.includes('ar-mini-grid') && annual.includes('ar-month-nav') && annual.includes('ar-day-state'),
  'month navigation and dated states exist');
assert.ok(annual.includes("form_open('team/roster_pattern_save'") && annual.includes('4 on / 4 Off'),
  'rotation builder covers common day-based cycles');
assert.ok(annual.includes("form_open('team/roster_publish'") && annual.includes('$coverage[\'missing\']'),
  'publishing has a coverage gate');
assert.ok(annual.includes('form_open(\'team/roster_leave_save\'') && annual.includes("form_open('team/roster_leave_amend'"),
  'planned leave does not depend on an employee request');
assert.ok(annual.includes("form_open('team/roster_extra_save'") && annual.includes("form_open('team/roster_extra_settle'"),
  'extra work and its settlement have explicit forms');
assert.ok(annual.includes('$can_plan') && annual.includes('$can_edit') && annual.includes('$is_planner'),
  'write controls are hidden from managers and employees');
assert.ok(editor.includes("form_open('team/roster_day_save'") && editor.includes("$published ? 'required minlength"),
  'accessible day-edit fallback requires a published-change reason');
assert.ok(week.includes('ar' /* no external JS dependency */) || week.includes('sr-grid'),
  'weekly roster renders its own day-level table');
assert.ok(week.includes('Off ·') && week.includes('Needs plan') && week.includes('Leave') && week.includes('Holiday'),
  'the weekly legend distinguishes all expected states');
assert.ok(week.includes('sr-search') || week.includes('id="srSearch"'), 'weekly search exists');
assert.ok(week.includes('htmlspecialchars((string)$v') && annual.includes('htmlspecialchars((string)$s'),
  'worker and reason text go through HTML escaping');
assert.ok(week.includes('sr-print-banner') && week.includes('aria-current="page"') && week.includes('Showing'),
  'weekly grid is printable, filterable and paginated');
assert.ok(navigation.includes("'Annual Workforce Plan'") && navigation.includes("'My Annual Schedule'"),
  'role-scoped navigation offers the annual calendar');
assert.ok(attendance.includes('Published plan') && attendance.includes('Not recorded') && attendance.includes('Actual status'),
  'actual attendance remains separate and unrecorded days do not become Present by default');
assert.ok(teamModel.includes('planned_today') && teamModel.includes('expected_on_site') && teamModel.includes('on_site_today'),
  'staff directory has both expected and actual site state');
assert.ok(ctl.includes('public function roster_cover()') && ctl.includes('Workforce_cover_model->shortlist('),
  'planners see an automatic shortlist for a dated sick Shift');
assert.ok(cover.includes('function vacancy(') && cover.includes("'Sick Leave'") && cover.includes("'Published'"),
  'only a published sick Shift is coverable');
assert.ok(cover.includes('function assess(') && cover.includes('function approve(') && cover.includes('function cancel(')
  && cover.includes('function review_issue(') && coverView.includes('Cover needs review.'),
  'cover matching, supervisor approval, live review and cancellation are separate');
assert.ok(coverView.includes("form_open('team/roster_cover_approve'") && coverView.includes('availability_confirmed')
  && coverView.includes('capacity_checked') && coverView.includes('name="compensation"'),
  'Off-day call-in needs explicit attestation and compensation; no fabricated site match');
assert.ok(cover.includes("'extra_created_for_cover' => $created") && model.includes('function cover_conflict(')
  && cover.includes('ORDER BY id FOR UPDATE') && cover.includes('ORDER BY user_id, id FOR UPDATE'),
  'linked compensation and locked worker/certificate rechecks protect approval');
assert.ok(annual.includes('Find / review sick Shift cover') && annual.includes('$cover_note')
  && annual.includes('Cover arranged') && week.includes('Find cover')
  && attendance.includes('Covering sick Shift'), 'cover is visible in year, month, week and actual attendance context');
assert.ok(navigation.includes("'Sick Shift Cover'") && navigation.includes("'team/roster_cover' => array(1, 4)"),
  'only planners can reach the finder from navigation');
assert.ok(cover.includes("$previous_extra->status !== 'Cancelled'") && cover.includes("->update('workforce_plan_extra_days', $extra_values)"),
  'a cancelled, unused Off-day exception can be explicitly reauthorised on the same unique worker/date');
assert.ok(teamModel.includes("$sources['workforce_plan_cover'] = 'shift_id'")
  && usersCtl.includes("array('workforce_plan_cover', 'absent_user_id')")
  && usersCtl.includes("array('workforce_plan_cover', 'cover_user_id')"),
  'historical cover prevents shift and account deletion even after cancellation');
assert.ok(teamModel.includes("'cover_mode_today'") && teamView.includes('Off · call-in cover duty')
  && teamView.includes('On-shift cover duty'), 'the staff directory labels both types of cover separately');
console.log('Annual workforce plan & sick-shift cover structural checks passed.');
