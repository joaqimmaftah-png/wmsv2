# Forecast Page(s) — Professional Data Table UX Audit, Improvement & Implementation

**Application:** WMS v2 (Workshop / Warehouse Management System) — CodeIgniter 3, PHP 8.2+, MySQL/MariaDB
**Module:** Planned-availability forecast (`Forecast` controller, `Forecast_model`)
**Branch:** `arena/771c510e-wmsv2`
**Access mode:** Local source review; the original implementation was limited to view-layer (templates + vanilla JS). No PHP/MySQL runtime is available in this sandbox, so server-side execution, PHP unit tests and browser E2E tests could NOT be run here (see §12).

**Rolling-table follow-up:** A later focused audit corrects the rolling three-week grid's local sticky behavior, weekly status/column structure, model-owned downtime display and Excel parity, search/type/status filters, and missing-table state. That follow-up touches `Forecast.php` and `Forecast_excel.php` without changing forecast calculations, routes, schema, or permission policy. See `docs/forecast-rolling-table-audit.md` for the current scope and verification status.

---

## 1. Executive summary

The forecast module is a well-engineered planning system: every figure is computed in one place (`Forecast_model`, via the shared `kpi_availability()` / `kpi_rollup()` engine), mutations are POST-only with CSRF, publish/freeze is guarded, and the existing screens already surface real operational problems (overbooked machine-days, unplaceable PM schedules, parts coverage gaps) instead of hiding them.

The audit's data-integrity findings are largely PASS. The UX findings concentrate on the **data-table surfaces**:

1. **No status-based navigation** — the machine grid mixes Planned / Out of service / Stand-by / No-plan rows with no way to isolate exceptions (Principle 1: FAIL).
2. **No search or status filter** in the toolbar (Principle 2: PARTIAL).
3. **Hand-rolled inline badges** duplicate the shared `.badge` design system, producing subtle color/spacing drift across forecast pages (Principle 3 & 7: PARTIAL).
4. **No sticky machine column** on wide day-matrix tables; the machine column scrolls away on small screens (Principle 9: PARTIAL).
5. **Accessibility gaps** — `title`-only tooltips, no ARIA on the filter surface, no focus-visible styling beyond browser defaults (Principle 10: PARTIAL).

**What was implemented (view layer only, backend contract untouched):**
- `application/views/forecast/planned.php` — status filter tabs (All / Planned / Out of service / Stand-by / No plan) with fleet-wide counts, client-side search by registration or location, sticky first column, shared `.badge` status badges, empty state, filter caption explaining count scope, inert decorative `kpi-glow` removed, `assets/js/wms-tables.js` included.
- `application/views/forecast/jobs.php` — job STATUS column rendered through shared badges with semantic colors.
- `application/views/forecast/rolling.php` — sticky first column on the three-week fleet grid.
- `application/views/forecast/spares.php`, `allowances.php` — shared `.badge` classes in place of inline chips.
- `assets/js/wms-tables.js` (new) — dependency-free client filter: ARIA tablist (click + Enter/Space), search, counts, sessionStorage per week, graceful degradation.
- `tests/table_ux.js` — extended with 15 forecast regression checks; full suite passes in this sandbox.

**Not implemented (recommendations requiring confirmation or a PHP runtime):** server-side search/filter, a dedicated machine-week drilldown, layout-level media queries, runtime verification (PHP tests / browser E2E).

---

## 2. Table purpose and user workflow

| Item | Detail |
|---|---|
| **Module** | Planned-availability forecast (tasks F5–F7, F10, F11; decisions D29–D33 per `SYSTEM.md` §7.11) |
| **Business purpose** | Plan the week's maintenance per machine-day (PM placement, standing/unplanned allowances, corrective/standby jobs, weekly inspection rounds), publish/freeze the plan as the baseline, compare plan vs actual, run a rolling 3-week view, and derive the PM spare-parts requirement |
| **Primary users** | Supervisors (role 1) — build, adjust, publish; Managers (role 4) — read-only view of the plan and variance. Access matrix `MY_Controller::ACCESS_MATRIX`: `forecast` => roles 1 and 4 |
| **Most frequent tasks** | Open the week plan; see fleet/group availability at a glance; find machines with problems (out of service, overbooked days, no plan); generate/places PM, allowances, jobs; adjust a machine-day cell by hand; publish/freeze; compare with actuals; check the parts forecast for procurement |
| **Primary record identifier** | Equipment registration number (`equipment.registration_no`) within a week (ISO week label `YYYY-Wnn`) |
| **Key statuses** | Plan status per machine-row: PLANNED / OUT OF SERVICE / OUT n DAYS / STAND-BY / NO PLAN; job statuses: Planned / Issued / Done / Missed / Cancelled; ledger categories: PM, Standing, Unplanned, OutOfService, Corrective; ledger sources: Default, Manual, FromPM, Derived, FromPlan |
| **Actions** | Generate (PM / inspections / standing / unplanned), save cell override, save/delete job, create inspection rounds, publish (+revision with note), export XLSX (planned / rolling), add/remove part requirements |
| **Data volume** | One week × reportable fleet (non-retired, reportable machines). The prior “dozens to low hundreds” estimate was not measured against a live database; actual row count and response time are unknown, so pagination necessity is unverified. |
| **Tech stack** | CodeIgniter 3, vanilla PHP views, shared light-theme design system (`layouts/header.php`: `.tbl`, `.card`, `.kpi`, `.badge`, `.btn`, `.form-control`), dark variant `header-apple.php` (not used by forecast). No JS framework |
| **Existing issues known from code comments** | Historical workbook defects the engine deliberately avoids: silent clamping of overbooked days, dropped out-of-service machines, split groups on untrimmed report-group names, `#REF!` sheet references in the old `WEEK 40 SCHEDULE` |

---

## 3. Existing implementation findings

### 3.1 Pages and surfaces

| Page (route) | View | Purpose |
|---|---|---|
| `forecast` | `forecast/planned.php` | Planned availability week: KPI strip, group roll-up (D30), unit view headcount per day (F7), per-group machine × day matrix with heat-shaded load cells, overbooked warnings (MP-0), PM placement issues (MP-4), publish/freeze controls, manual cell override |
| `forecast/jobs` | `forecast/jobs.php` | One machine × one day: job table (Edit/Remove with confirm) + add/edit form (MP-2) |
| `forecast/plan_vs_actual` | `forecast/plan_vs_actual.php` | Frozen baseline vs weekly actual, variance by group split into PM / unplanned causes (F6) |
| `forecast/rolling` | `forecast/rolling.php` | Three-week fleet grid (F11) |
| `forecast/spares` | `forecast/spares.php` | PM parts requirement vs on-hand vs on-order, shortage = required − on hand − on order, lead times (F10) |
| `forecast/allowances` | `forecast/allowances.php` | Per-machine standing / unplanned allowance derivation preview (F9) |
| `forecast/parts/<id>` | `forecast/parts.php` | Parts a PM service consumes (CRUD, supervisor only) |
| `forecast/export` | controller action | XLSX workbook from the same model calls as the screens |

### 3.2 What already works well (evidence from code)

- **Single source of truth:** views never recompute KPIs; `Forecast_model` is the sole engine (`kpi_availability` / `kpi_rollup`, D29/D30).
- **Overbooking reported, never clamped:** `held_days()`, overbooked warnings with excess hours; publishing overbooked weeks requires a note frozen into the snapshot (MP-0).
- **Protected sources:** Manual and FromPlan ledger rows are never overwritten by generators (D31, MP-2).
- **Publish/freeze:** revision 1 is the immutable baseline; later revisions require a note; plan-vs-actual always measures against revision 1 (D33).
- **Authorization:** controller matrix (roles 1/4), `can_write()` (1/2/3 — effectively supervisors within the forecast), `require_post()` on all mutations, `csrf_field()` on every form, deletes scoped to schedule id.
- **Feedback:** flash success/error after every mutation; delete actions wrapped in `confirm()`.
- **Empty/loading/error states:** `tables_ready` guard card; "no published plan" state on plan_vs_actual; empty rows for no-machines / no-parts states.

### 3.3 Pre-existing design-system conventions (reused where possible)

- `layouts/header.php` (light theme, used by forecast): `.tbl`, `.card`, `.ch/.ct/.cs`, `.kpi`, `.badge` (semantic `.b-*` variants), `.btn` / `.btn-primary` / `.btn-ghost` / `.btn-danger` / `.btn-success` / `.btn-amber`, `.btn-sm`, `.form-control`, `.form-select`, `.flash` / `.flash-ok` / `.flash-err`, `.grid .g-*`, `.ca` links, `.mono`.
- Flash messages render at the top of `.page` (`layouts/sidebar.php`) and auto-dismiss after 5s (`layouts/footer.php`).
- URL helper and CSRF helper are autoloaded (`application/config/autoload.php`).

---

## 4. UX audit matrix (10 core principles)

Rating key: PASS / PARTIAL / FAIL / NOT APPLICABLE / NOT VERIFIED.

| # | Principle | Rating | Summary |
|---|---|---|---|
| 1 | Status-based tab filtering | **FAIL** | No status tabs or counts on any forecast surface |
| 2 | Smart toolbar and controls | **PARTIAL** | Week switcher + export + generate/publish exist; no search or status filter |
| 3 | Professional table view | **PARTIAL** | Good typography/alignment/hover; hand-rolled badges, no sticky first column, heat scale undocumented |
| 4 | Card or grid view | **NOT APPLICABLE** | Day-by-day comparison is the core task; cards would weaken it |
| 5 | Record detail panel / page | **PARTIAL** | `forecast/jobs` is the machine-day detail, reachable from grid cells; no single machine-week summary |
| 6 | Contextual row actions | **PARTIAL** | Edit/Remove per job with confirm + server guards; no overflow menu needed (few actions) |
| 7 | Semantic color system | **PARTIAL** | Availability/spares/allowances colors consistent; badges hand-rolled instead of shared classes |
| 8 | Pagination and data navigation | **NOT VERIFIED** | All reportable rows are shown server-side, but no live fleet count or response-time benchmark is available. The complete-fleet workflow is a reason to avoid unnecessary pagination, not evidence that pagination is unnecessary. |
| 9 | Responsive design | **PARTIAL** | `overflow:auto` tables wrap; no sticky first column; KPI grid has no mobile adaptation |
| 10 | Micro-interactions, accessibility, feedback | **PARTIAL** | Hover rows, flash feedback, confirm() on destroy; title-only tooltips, no ARIA, no focus-visible |

### Detailed findings

**F-01 — Principle 1: FAIL — No status-based tab filtering**
- Current behavior: The planned-availability machine grid lists every reportable machine (Planned / Out of service / Out n days / Stand-by / No plan) in group order. There is no control to isolate, e.g., "machines with no plan yet" or "out of service".
- Evidence: `application/views/forecast/planned.php` — toolbar contains only the week form and Export XLSX; no tabs, filters, or counts by status. Same on `rolling.php` and `spares.php`.
- Impact: On a full fleet the planner must scan the whole matrix to find exceptions; the most operationally important rows (out of service, no plan) have equal visual weight to healthy rows.
- Severity: High.
- Recommendation: Status tabs All / Planned / Out of service / Stand-by / No plan with a count per tab; counts cover the whole fleet for the week (stated in a caption so they are not confused with filtered counts); client-side filtering keeps the backend contract unchanged (implemented in planned.php).
- Acceptance criteria: Tabs present with counts; selecting a tab hides non-matching machine rows; group roll-ups/unit view remain fleet-wide (caption says so); keyboard accessible (Enter/Space).
- Verification: node test asserts `role="tablist"` and `data-status` attributes (tests/table_ux.js); runtime click/keyboard verification needs a PHP server — not available here.

**F-02 — Principle 2: PARTIAL — Toolbar lacks search and status filter**
- Current behavior: Toolbar has week `<input type="week">` + Open, Export XLSX, and (draft) generate/publish buttons. No search input, no status or group filter, no advanced filters.
- Evidence: `planned.php` toolbar markup. `spares.php` has a horizon weeks select (relevant filter) and week input — good.
- Impact: Finding a machine by registration number or location requires scanning rows.
- Severity: High.
- Recommendation: Add client-side search (registration/location) plus the status tabs from F-01. A page-size selector depends on the unresolved pagination decision; measure the live reportable row count and response time first. View toggle (list/card): NOT APPLICABLE — the matrix is the comparison tool.
- Implemented: search input (`role="search"`, `onsubmit="return false"`, client-only) and status tabs in `planned.php`.
- Verification: tests assert `type="search"` and `role="tablist"`.

**F-03 — Principle 3: PARTIAL — Professional table view**
- PASS items: restrained uppercase headers; dates consistent (`d M Y`, `d/m`); numeric quantities right-aligned with `mono`; availability color-coded green ≥90% / amber ≥70% / red; hover rows (`tbl tbody tr:hover`); subtle separators; status badges with text labels (color never alone); tooltips on heat cells; overbooked outline.
- PARTIAL items: statuses rendered as hand-rolled inline `style="padding:3px 8px;..."` spans instead of the shared `.badge` system (drift in padding/radius/font-weight across pages); cell heat intensity (`rgba(192,57,43, total/24*0.9)`) has no legend; first `MACHINE` column not sticky in wide matrices; `kpi-glow` decorative divs are `display:none` in the light theme forecast uses — inert DOM noise.
- Severity: Medium.
- Recommendation: Replace hand-rolled badges with `.badge b-*` classes (implemented on planned jobs/status, spares COVERED/ORDER/CRITICAL, allowances DERIVED/FLAT, jobs status); add heat-scale legend (implemented as caption); sticky first column (implemented via `.tbl-sticky-first`); remove inert glows (implemented across all forecast views).
- Verification: tests assert `class="badge b-` and absence of `kpi-glow`.

**F-04 — Principle 4: NOT APPLICABLE — Card view**
- Justification: The forecast's core task is comparing the same machine across 7 days (or 3 weeks in rolling). A card view would break day-by-day comparability and hide the heat-shaded load cells. Not recommended; revisit only if a mobile-first "my machines" summary is requested.
- Verification: n/a — design decision recorded.

**F-05 — Principle 5: PARTIAL — Record inspection**
- Current behavior: Clicking a machine-day cell opens `forecast/jobs?equipment_id=&date=` — the day's jobs, ledger category breakdown, add/edit form. Group roll-up tables list machines but group rows are not links.
- Gap: No single "machine for the week" surface; a planner checking one machine across all seven days must read the matrix row.
- Severity: Medium.
- Recommendation: (Recommendation — requires confirmation) Make the machine registration in each group table link to a machine-week summary, or keep the existing matrix as that summary. Not implemented: changing links risks altering the established navigation; the matrix already serves this purpose.
- Verification: n/a for this release.

**F-06 — Principle 6: PARTIAL — Contextual row actions**
- Current behavior: Jobs table has Edit (link) and Remove (POST form with `confirm()`) per row; grid day-cells link to the jobs page; publish/delete_part forms confirm. `can_write()` and frozen-week guards run server-side; `csrf_field()` on all mutations.
- Missing: none critical. The action set per row is small enough that visible Edit/Remove is better than an overflow menu (Principle 6 discourages generic menus).
- Severity: Low.
- Recommendation: Keep as-is. STATUS column now uses semantic badges (implemented).
- Verification: tests assert `onsubmit="return confirm(` on parts/jobs delete forms and `csrf_field()` presence.

**F-07 — Principle 7: PARTIAL — Semantic color system**
- PASS: availability green/amber/red (identical `$col` in planned, plan_vs_actual, rolling); spares COVERED green / ORDER red / CRITICAL red; allowances DERIVED blue / FLAT gray; out-of-service amber; stand-by gray.
- PARTIAL: colors implemented as inline `style` blocks rather than `.badge b-*` classes — same hues, different padding/radius, drift risk if the design system changes.
- Severity: Low.
- Recommendation: Migrate to shared classes (implemented); keep color meanings unchanged.
- Verification: tests assert `class="badge b-` on planned/spares/allowances.

**F-08 — Principle 8: NOT VERIFIED — Pagination**
- Current behavior: The page renders the full reportable fleet server-side.
- Rationale: Keeping the fleet together supports the monitoring/planning workflow, but no live reportable row count or response-time benchmark is available. The earlier estimate (dozens to low hundreds) was not measured, so it cannot establish whether pagination is necessary.
- Recommendation: Measure representative fleet size, route response time and browser render behavior first; then compare a complete matrix with pagination without splitting a machine's time-series context unnecessarily.
- Verification: Not performed; no live database or PHP/MySQL runtime is available in this workspace.

**F-09 — Principle 9: PARTIAL — Responsive design**
- Current behavior: All tables sit in `overflow:auto` cards, so they scroll horizontally on narrow screens; week form wraps (`flex-wrap:wrap`); fixed-width selects (170px) may cramp below ~360px.
- Gaps: First `MACHINE` column scrolls away with the matrix (no sticky column); KPI strip uses `.grid .g-4` with no media query — four tiles squeeze on phones (layout-level issue in `layouts/header.php`, no `@media` rules exist at all except print).
- Severity: Medium.
- Recommendation: Sticky first column on machine grid + rolling grid (implemented); layout media queries flagged as out-of-module-scope (they affect the whole app).
- Verification: tests assert `tbl-sticky-first` on planned and rolling.

**F-10 — Principle 10: PARTIAL — Micro-interactions, accessibility, feedback**
- PASS: row hover, flash ok/err with 5s auto-dismiss, `confirm()` on destructive actions, native keyboard tab order for forms/buttons.
- GAPS: `title` attributes carry the only tooltip text (not keyboard accessible); no `aria-pressed`/`role` on filter controls before this change; focus styling is browser default only; cards always play the `rise` animation (no `prefers-reduced-motion` handling).
- Severity: Low.
- Recommendation: ARIA tablist + `aria-pressed`, `focus-visible` outline on tabs (implemented); keep tooltips as supplementary labels only; `prefers-reduced-motion` flagged as layout-level.
- Verification: tests assert `role="tablist"`, `aria-pressed` handling in JS.

---

## 5. Additional enterprise-grade findings

| ID | Area | Rating | Finding |
|---|---|---|---|
| A-01 | Data accuracy | **PASS** | All figures from `Forecast_model`; `null` → `—`; zero vs empty preserved; overbooking reported not clamped; tab counts computed from actual `$rows` |
| B-01 | Functional integrity | **PARTIAL** | CSRF + `require_post` + `can_write` + frozen-week guards on every mutation; scoped deletes; flash errors on failure. Gap: feedback relies on full-page flash after redirect; the new client filter has no server counterpart (by design — enhancement only) |
| C-01 | Performance | **NOT VERIFIED** | All reads are server-side per week, model queries use existing indexes (`idx_epd_created_by` etc.). Cannot verify: no PHP/MySQL in this sandbox |
| D-01 | Consistency | **PARTIAL** | Forecast views pre-date shared `.badge`/`.tbl`/`.kpi` conventions; heavy inline styling. Partially aligned in this change |
| E-01 | Security | **PASS** | Controller matrix limits forecast to roles 1/4; mutations POST-only with CSRF; delete scoped by schedule id; view-level `$is_supervisor` flags mirror server authorization. Hiding a button never replaces backend authorization |

---

## 6. Prioritized defect & improvement register

| Priority | ID | Finding | Severity | Status | Implemented in this change |
|---|---|---|---|---|---|
| 1 | F-01 | No status-based tab filtering | High | ✅ Yes (planned.php, client-side) | Yes |
| 2 | F-02 | No toolbar search | High | ✅ Yes (planned.php, client-side) | Yes |
| 3 | F-03 | Hand-rolled badges vs shared system | Medium | ✅ Yes (planned, jobs, rolling, spares, allowances) | Yes |
| 4 | F-09 | Machine column not sticky on matrices | Medium | ✅ Yes (planned, rolling) | Yes |
| 5 | F-10 | ARIA / focus-visible on filters | Low | ✅ Yes (tabs) | Yes |
| 6 | F-03 | Heat scale undocumented | Low | ✅ Yes (caption) | Yes |
| 7 | F-05 | No machine-week drilldown surface | Medium | No — recommendation only | No |
| 8 | F-09 | KPI grid has no mobile adaptation | Medium | No — layout-level, out of module scope | No |
| 9 | C-01 | Performance verification | — | No — no runtime available | No |
| 10 | F-03 | Remove inert `kpi-glow` | Low | ✅ Yes (all forecast views) | Yes |

---

## 7. Recommended table structure and interaction specification

### A. Table structure — Planned Availability (primary surface)

| Column | Alignment | Format | Priority |
|---|---|---|---|
| MACHINE | left | registration no, monospace, sticky | 1 |
| LOCATION | left | short text | 2 |
| Days (Mon–Sun) | center | planned hours total + job-code chips; heat-shaded by load; red outline if overbooked | 3 |
| PM h | right | hours, 2 decimals stripped of trailing zeros | 4 |
| AVAILABILITY | right | %, color green ≥90 / amber ≥70 / red | 5 |
| STATUS | center | semantic badge: PLANNED (green), OUT OF SERVICE (amber), OUT n DAYS (amber), STAND-BY (gray), NO PLAN (gray) | 6 |

- Default sort: group order (worst availability first, per model), then registration. Sorting individual columns is NOT added — sorting a day-matrix has no predictable operational result (Principle 3: sort only where meaningful).
- Optional columns: none beyond current set; the reference (unweighted) column stays behind the `forecast_show_excel_reference` setting.

### B. Filters and toolbar

- Status tabs: All → Planned → Out of service → Stand-by → No plan (workflow order: healthy first, exceptions last).
- Counts: fleet-wide for the week; caption states they are not filtered counts.
- Search: machine registration or location, client-side, debounced by native `input` event.
- Week switcher (`<input type="week">`), Export XLSX, generate/publish controls unchanged.

### C. Presentation

- Row hierarchy: group cards (worst-first) → machine rows; unit view (headcount per day) above the grid as fleet-wide context; KPI strip as headline.
- Status badges: `.badge b-completed` (PLANNED), `.badge b-high` (OUT OF SERVICE / OUT n DAYS), `.badge b-standby` (STAND-BY), `.badge b-secondary` (NO PLAN).
- Empty state: "No machines match" when a tab/search yields zero results; fleet-wide empty ("No machines in the forecast") when there are no rows.
- Loading: server-rendered, no skeleton needed; error state: existing `tables_ready` guard card.

### D. Record inspection

- Machine-day: `forecast/jobs` (existing page) — ledger breakdown + job table + add/edit form. Status column now badged.
- Machine-week: the matrix row itself is the summary; no new page added in this change (see register F-05).
- Edit vs view: frozen weeks refuse writes (server); manual override form available supervisor-only on draft weeks.

### E. Interaction and permission rules

- Row actions (jobs): Edit link, Remove POST form with `confirm()`, scoped delete, `csrf_field()`.
- Publish: `confirm()` + revision note (required when overbooked or revision ≥ 2).
- Roles: supervisor writes; manager reads. View-level flags mirror server guards.
- Feedback: flash success/error after mutation; filter has no server round-trip (graceful degradation).

### F. Visual design

- Typography: existing scale (headings Syne/Inter, data JetBrains Mono `mono`, captions `cs`).
- Spacing: existing card/padding tokens; gap-10 toolbar.
- Surfaces: `.card` / `.surface`, `.tbl` separators.
- Colors: shared `.badge b-*` semantic fills; availability `$col` scale unchanged.
- Focus: `focus-visible` outline on tabs; native focus elsewhere.

Every recommendation maps to the forecast's purpose: status-first filtering and a visible machine identifier improve fleet scanning. Whether pagination is needed remains a separate, measurement-dependent decision; any paginated design should preserve useful machine/week context.

---

## 8. Responsive behavior and visual design specification

- **Desktop:** full matrix, 7 day columns, sticky MACHINE column, all KPI tiles.
- **Tablet:** matrix scrolls horizontally within its card; MACHINE column stays visible (sticky); toolbar wraps (week → search → export → tabs flow to next line).
- **Phone:** same scrollable matrix with sticky first column; KPI `.g-4` tiles squeeze (layout-level media queries are missing from `layouts/header.php` and are flagged as out of module scope); touch targets are native buttons/links ≥ 44px equivalent.
- Color does not carry meaning alone: every status badge has a text label; availability % carries its color.
- Reduced motion: not handled at layout level (cards animate `rise` unconditionally) — flagged.

---

## 9. Before-and-after comparison

| Change | Before | After | Why | Business behavior? | Verification |
|---|---|---|---|---|---|
| Status tabs | None — full fleet listed | All / Planned / Out of service / Stand-by / No plan with counts | Isolate exceptions fast (Principle 1) | No | Test asserts `role="tablist"` + `data-status`; runtime check needs PHP server |
| Toolbar search | None | Client search by registration/location | Find a machine without scanning (Principle 2) | No | Test asserts `type="search"` |
| Sticky machine column | Scrolls away | Sticky on matrix + rolling grid | Keep row identity while scanning days (Principle 9) | No | Test asserts `tbl-sticky-first` |
| Shared badges | Hand-rolled inline styles | `.badge b-*` classes | Design-system consistency (Principles 3/7) | No | Test asserts `class="badge b-` |
| Inert `kpi-glow` | Rendered, `display:none` in light theme | Removed | Remove decorative DOM noise | No | Test asserts no `kpi-glow` |
| Job status badge | Plain text | Semantic badge | Scan job status at a glance (Principle 7) | No | Test asserts badge map |
| ARIA/focus on tabs | None | `role="tablist"`, `aria-pressed`, `focus-visible` | Keyboard/screen-reader access (Principle 10) | No | Test asserts `role="tablist"` |

Visual vs functional: badge/tab/sticky changes are primarily visual + operability; all data, endpoints, calculations and permissions are unchanged.

---

## 10. Implementation plan and dependencies

1. View edits to the 6 forecast views + new `assets/js/wms-tables.js` — **done** (this change set).
2. Extend `tests/table_ux.js` — **done**, suite passes.
3. Runtime verification — blocked: no PHP/MySQL in sandbox; `.github/workflows/tests.yml` runs `php tests/run.php` plus MySQL/MariaDB E2E on CI. Needs a deployed environment to confirm.
4. Layout media queries for KPI grid (app-wide) — out of module scope; flagged to product.
5. Machine-week drilldown — recommendation, needs product confirmation.

Dependencies: none new (vanilla JS, no dependencies). Backend contract untouched — no controller/model/database changes, so no migration or schema risk.

---

## 11. Updated code (implementation)

Files changed (view layer only; no business logic altered):

- `application/views/forecast/planned.php` — status counts computation; status filter tabs with counts; client search form; `tbl-sticky-first` machine tables; shared `.badge` statuses; empty state; filter caption; removed inert `kpi-glow`; includes `assets/js/wms-tables.js`.
- `application/views/forecast/jobs.php` — job STATUS column via shared badges (`$job_status_badge` map).
- `application/views/forecast/rolling.php` — sticky first column; removed inert `kpi-glow`.
- `application/views/forecast/plan_vs_actual.php` — removed inert `kpi-glow`.
- `application/views/forecast/spares.php` — COVERED/ORDER/CRITICAL via `.badge`; removed inert `kpi-glow`.
- `application/views/forecast/allowances.php` — DERIVED/FLAT via `.badge`; removed inert `kpi-glow`.
- `assets/js/wms-tables.js` (new) — ARIA tablist filter, client search, per-week sessionStorage, graceful degradation.
- `tests/table_ux.js` — 15 forecast regression checks added.

No routes, controllers, models, migrations, or database contracts were touched.

---

## 12. Test results and verification evidence

| Suite | Command | Result | Notes |
|---|---|---|---|
| Table UX regression (Node) | `node tests/table_ux.js` | **PASSED** | Includes pre-existing supplier/lubricant/budget checks + 15 new forecast checks |
| PHP unit/regression | `php tests/run.php` | NOT RUN | No PHP binary in sandbox |
| Browser E2E (Playwright) | `tests/live_e2e.py` | NOT RUN | Needs a running server + browser |
| PHP lint (`php -l`) | — | NOT RUN | No PHP binary in sandbox |

**Verification limitation:** This sandbox has no PHP, MySQL, or web server, so server-side behavior (CSRF execution, `can_write` guards, flash rendering, count accuracy) could not be executed here. All view edits preserve the existing PHP syntax conventions and backend contracts; the Node suite validates the markup contracts (ARIA roles, attributes, CSRF presence, confirm handlers, badge classes). Runtime verification must be completed in an environment with PHP 8.2+ and MySQL before merging.

New test assertions (tests/table_ux.js):
- planned: `role="tablist"`, `data-status=`, `type="search"`, `type="week"`, `tbl-sticky-first`, `class="badge b-`, publish `onsubmit="return confirm(`, `csrf_field()`, no `kpi-glow`
- jobs: delete-form `confirm`, `csrf_field()`, `$job_status_badge` map, `class="badge`
- rolling: `tbl-sticky-first`
- spares/allowances: `class="badge b-`
- parts: delete `confirm`, `csrf_field()`

---

## 13. Outstanding risks, unresolved decisions, and acceptance checklist

### Risks
1. **Client-only filter:** tab/search state lives in `sessionStorage` keyed by week and resets on server round-trips (week change via the Open form reloads the page — acceptable; counts always recomputed server-side from `$rows`).
2. **Counts are fleet-wide:** caption explicitly states tab counts cover the whole week, not the filtered list (Principle 1 count-clarity requirement).
3. **PHP syntax unverified:** no `php -l` possible here; review by a PHP environment before merge.
4. **Layout media queries missing app-wide:** KPI `.g-4` squeeze on phones is a layout issue, not fixable from this module alone.

### Unresolved decisions (need product confirmation)
- Machine-week drilldown surface (F-05): the matrix currently serves as the week summary; confirm whether a dedicated view is wanted.
- Whether server-side search/filter is required for very large fleets (>300 machines).

### Acceptance checklist
- [x] Table presents correct records/values (unchanged model calls — same data as before)
- [x] Column hierarchy supports the primary task (exceptions first via tabs, machine column always visible)
- [ ] Search/filter/sort/pagination work together — verified by markup tests; runtime pending (no PHP server)
- [ ] Status counts match counting rules (fleet-wide, documented in caption) — markup-verified; runtime pending
- [ ] All displayed actions functional and authorized (unchanged controllers — CSRF, `require_post`, `can_write`, frozen guards intact)
- [ ] Record details open correct record (unchanged `forecast/jobs` links)
- [ ] Changes/destructive operations handle success/failure (unchanged flash flow)
- [ ] Responsive layouts preserve essential info (sticky column; tables scroll; toolbar wraps)
- [ ] Loading/empty/error/permission states handled (existing guards unchanged)
- [ ] Keyboard navigation / focus / accessible labels — ARIA tablist added; runtime pending
- [ ] Shared design conventions followed (`.badge`, `.tbl`, `.kpi` classes now used)
- [ ] Relevant tests pass — `node tests/table_ux.js` PASSED
- [ ] No existing functionality removed (only additive UI + class swaps with identical colors)
- [ ] Related modules unchanged (no controller/model/touch of other views)
