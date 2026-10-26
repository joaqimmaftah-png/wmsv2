# Forecast / Rolling Plan — Professional Data Table UX Audit & Implementation

**Scope:** The fleet matrix at `/forecast/rolling`, its `_rolling_data()` controller helper, `Forecast_model::planned_availability()` output, and `Forecast_excel::build_rolling()`.

**Purpose:** A focused audit of the rolling three-week machine table. The broader forecast audit in `docs/forecast-table-ux-audit.md` remains the source for the wider planning workflow, permissions, formula decisions, and sibling forecast surfaces. This document records the rolling-specific facts and changes rather than repeating unrelated planned-week table work.

**Change boundary:** No schema, production data, route, forecast calculation, status vocabulary, or permission policy changed. The table reuses the existing equipment profile route and the model's existing planned-availability output. The controller now exposes the equipment ID already present in each forecast row to support that existing profile link, and reports whether the forecast tables are installed so the page can distinguish setup failure from an empty plan.

**Verification boundary:** Source-level regression assertions, JavaScript-independent GET control checks, PHP AST parsing, and `git diff --check` are available. Native PHP/database/browser/XLSX rendering was not available in this workspace; those runtime checks remain open.

## 1. Executive summary

The Rolling Maintenance Plan is a **three-week fleet comparison matrix**, not a CRUD table or a per-week operational work queue. One row represents a machine; the three weekly groups show the plan status, planned-model downtime hours, and machine-level planned availability for the same machine across successive weeks. The primary task is to identify plan exceptions and compare a machine's plan across the three-week window.

The existing screen already had weekly fleet KPI cards, a base-week selector, a complete-plan XLSX export, and a sticky-column class. Review found several rolling-specific defects beyond the prior broad forecast audit:

- The rolling view emitted three cells per week while the headers only described an ISO-week cell, downtime, and availability; the ISO week heading occupied a single column rather than spanning a weekly group. The first body cell repeated downtime (or showed `OUT`) without a clear subheading.
- Both screen and workbook recomputed downtime as standing + unplanned hours, omitting the model's `corrective_hours`. This could understate the model-owned downtime value and disagree with forecast availability.
- The rolling view used `tbl-sticky-first`, but its only CSS definition was local to `planned.php`; the class alone did not make the Rolling table sticky.
- The table had no machine search, equipment-type filter, or three-week status narrowing. It also showed no weekly status for `STAND-BY` or `NO PLAN`.
- With the forecast ledger table absent, the controller still built a rolling view from empty planned rows; the page could look like an empty/unplanned forecast rather than disclose that the tables were not configured.

The implementation now has a grouped, labelled three-week header; weekly status badges; model-owned downtime values in screen and workbook; a working server-rendered search/type/status filter; status counts with explicit overlap/full-scope rules; 50-row-default pagination with 10/25/50/100 choices after filtering; a local sticky machine column; the existing equipment profile link; and a clear missing-table state. Search, filters and pagination are GET-driven, so they work without JavaScript. The matrix remains one row per machine; no card view was added because cards would weaken the week-to-week comparison. The user reports the fleet exceeds hundreds, which prompted pagination; exact reportable count and performance benefit remain unverified, and view-level slicing does not reduce model/database work.

## 2. Table purpose and workflow

| Item | Evidence-based finding |
|---|---|
| **Module / route** | `Forecast::rolling()` rendered at `/forecast/rolling`; navigation is titled “Rolling Plan.” Task comments call it F11 — rolling three-week plan. |
| **Business purpose** | Compare the planned status and availability of each reportable machine over three successive weeks; inspect gaps/out-of-service time and navigate to equipment context. |
| **Users** | The existing `MY_Controller::ACCESS_MATRIX` grants Forecast access to roles 1 and 4 (supervisor and manager, documented in the broader forecast audit). Rolling is read-only; no new write controls are introduced. |
| **Frequent tasks** | Select a base ISO week; scan fleet summary cards; find a machine by registration/type/location; isolate out-of-service time, stand-by reserve, missing plans, or machines planned in all three weeks; compare weekly downtime and planned availability; export the complete rolling workbook. |
| **Primary identifier** | `equipment.registration_no`, unique within the forecast machine list under the existing model/data assumptions. `equipment_id` is used only to link to the existing profile. |
| **Immediate fields** | Machine ID, equipment type and location; then, per week, status, model downtime hours and machine-level planned availability. Fleet availability/available units/PM hours remain in the existing three weekly KPI cards. |
| **Statuses** | Derived from real per-week `Forecast_model::planned_availability()` fields, using the same priority already used by `forecast/planned.php`: whole-week out of service; partial `out_days`; stand-by; no plan (`has_plan` false); otherwise planned. These are presentation categories, not a new database enum. |
| **Related records** | Weekly plan rows are assembled from `equipment_planned_downtime`, existing maintenance-plan jobs, standing allowances and reportable equipment through the forecast model. All three week plans come from `planned_availability()`. |
| **Available actions** | Change the base week; apply GET search/type filter; follow status-filter links; export the complete three-week XLSX; follow the machine ID to `equipment/view/{id}`. No plan mutation, publish, edit or delete action exists on this page. |
| **Data volume** | One row per reportable fleet machine, not one row per machine-week. The earlier “dozens to low hundreds” figure was an unverified estimate. The user now reports the live fleet exceeds hundreds; this supports considering pagination, but the exact reportable count and render time have not been independently measured in this environment. |
| **Default ordering** | `_rolling_data()` uses `ksort()` on registration number. That stable, familiar order is retained; no new sort order is inferred. |
| **Responsive use** | Keep the matrix on narrow screens because preserving the same machine across three weeks is the core task. Allow horizontal scroll within the table card, keep MACHINE sticky, and wrap/stack the controls rather than shrinking the 12-column matrix into unreadable text. |
| **Stack** | CodeIgniter 3, PHP view, `Forecast_model`, `Forecast_excel`, shared `.tbl`, `.card`, `.kpi`, `.badge`, `.form-control` / `.form-select` classes and vanilla PHP/GET navigation. The controls use server-rendered filtering and need no JavaScript. |
| **Loading/empty/error/permission** | Server-rendered, so no async loading state is needed. Empty fleet, no filter matches, and missing forecast-table states are distinct. Forecast role checks and equipment-profile access remain on their existing controllers; link visibility does not replace authorization. |

## 3. Existing implementation evidence

- `application/controllers/Forecast.php`: `Forecast::rolling()` resolves the base ISO week, calls `_rolling_data()`, and renders this view. `_rolling_data()` creates three consecutive weekly `planned_availability()` payloads, unions machine rows by registration number, and sorts those keys.
- `application/models/Forecast_model.php`: each weekly machine row already contains `equipment_id`, `registration_no`, `type`, `location`, `out_of_service`, `out_days`, `standby`, `has_plan`, `downtime_hours`, and `availability` (among other planning fields). The forecast view must present these values, not reimplement the availability formula.
- `application/models/Forecast_model.php` and its existing KPI calculation own `downtime_hours`; it includes the `corrective_hours` contribution and model cap. The old `application/views/forecast/rolling.php` and `application/libraries/Forecast_excel.php::build_rolling()` instead summed `standing_hours + unplanned_hours` locally; they therefore omitted corrective work.
- `application/views/forecast/planned.php` already derives visible plan labels from the actual status fields and uses shared badge classes: `OUT OF SERVICE`, `OUT n DAYS`, `STAND-BY`, `NO PLAN`, `PLANNED`.
- The old `application/views/forecast/rolling.php` table had one header row with an ISO week label in the first of three columns, followed by `DT h` and `AVAIL`. Its body output a status-like `OUT` or a downtime number in that first cell, then output downtime again in the next cell. The week header did not span the three columns and there was no `STATUS` subheading.
- The old `application/views/forecast/rolling.php` had no search, type filter, or status controls. The older broad audit reported only the `tbl-sticky-first` class for Rolling; the corresponding CSS rule existed in `application/views/forecast/planned.php`, so it was not available to this independent view.
- The old rolling controller did not send `tables_ready` to the view. `Forecast_model::planned_rows()` safely returns no plan rows when the ledger is missing, but without a view guard this is indistinguishable from an unconfigured/empty draft.
- The old rolling workbook emitted two fields per week (downtime, availability) and repeated the same local downtime omission. It did not identify `STAND-BY` or `NO PLAN` in its rows.
- `/equipment/view/{id}` is an existing profile route used by other application tables. Equipment access is separately guarded by its controller/access matrix. No new route or action is introduced.

## 4. UX audit matrix — 10 principles

Ratings reflect the implementation and available evidence. “PARTIAL” means source-level wiring exists but runtime/visual/accessibility verification is still required; no item is marked PASS only because it looks plausible in source.

| # | Principle | Baseline | Current rating | Finding / rationale |
|---|---|---|---|---|
| 1 | Status-based filtering | No rolling status filters. | **PARTIAL** | GET links now narrow rows by real conditions in the selected three-week window. Each count is a machine count over all machines; “any week” conditions can overlap, and the view says so. The wording/count behavior is source-tested, not browser/runtime-tested. |
| 2 | Smart toolbar | Week selector and full XLSX export only. | **PARTIAL** | Search by registration/type/location, observed equipment-type selector and week-preserving status links are functional GET controls. No page-size or view toggle: this fleet matrix is one row per machine and a card view would weaken comparison. Runtime interaction remains unverified. |
| 3 | Professional table | Grouped week context was weak; duplicated downtime; only column-class marker for sticky first cell. | **PARTIAL** | ISO-week group headers span three labelled subcolumns (status, downtime hours, plan availability); numeric columns are right-aligned; badges are shared classes; week separators and local sticky-column CSS are present. Real browser rendering, contrast and long-label behavior remain unverified. |
| 4 | Card/grid view | One fleet comparison matrix. | **NOT APPLICABLE** | Three weeks side-by-side per machine is the table's core comparison. Cards would split a single machine's time series and impede week-to-week scanning. Horizontal scrolling with a sticky identifier preserves the actual task. |
| 5 | Record detail panel/page | Machine ID was plain text; no machine-week detail route exists. | **PARTIAL** | Machine ID now opens the established equipment profile. It supplies machine context, not a new machine-week breakdown; the forecast already has a date-specific `forecast/jobs` route but no honest rolling-row destination. No new detail endpoint was invented. |
| 6 | Contextual row actions | No row-level action. | **PARTIAL** | A single, useful existing View Equipment Profile link is shown. The rolling report is read-only, so no edit/status/delete/export menu is added per row. Route access/click-through was not live-tested. |
| 7 | Semantic color system | Out only had a hand-styled `OUT` chip; no other per-week status. | **PARTIAL** | Existing planned-week badge vocabulary is reused for whole-week out, partial out days, stand-by, no-plan and planned. Text labels accompany color. Palette/contrast in the rendered theme were not browser-tested. |
| 8 | Pagination/data navigation | All fleet machines rendered; no summary. | **NOT VERIFIED** | The view now paginates filtered rows (50 default; 10/25/50/100 sizes), retaining each machine's three weeks and full-scope counts/export. The user reports more than hundreds of fleet machines, but the exact reportable count and render timings are not independently available; array slicing reduces DOM, not model work. |
| 9 | Responsive design | Table overflowed horizontally; sticky class did not have local CSS. | **PARTIAL** | The table remains horizontally scrollable by design; the machine ID is sticky using CSS defined in the rolling view; controls wrap and tabs scroll on small screens. Phone/tablet visual testing remains outstanding. |
| 10 | Micro-interactions/accessibility | No status labels or search; machine was not navigable. | **PARTIAL** | Native GET forms/links, associated labels, current-filter indication, table caption, grouped headers, result live region, explicit empty states and badge text improve semantics without JS. Keyboard/screen-reader testing and focus/contrast checks remain outstanding. |

## 5. Additional enterprise-grade findings

| Area | Rating | Evidence-based finding / handling |
|---|---|---|
| **Data accuracy** | **PARTIAL** | Corrected the screen/workbook downtime display to use `r['downtime_hours']`, the existing model output that includes corrective hours and follows the availability rule. Per-machine `PLAN AVAIL` remains a model value. The view explains that a NO PLAN value is not actual uptime and that stand-by is excluded from fleet/group roll-ups. Forecast calculations themselves are unchanged. |
| **Functional integrity** | **PARTIAL** | Search, status and equipment type filters are ordinary GET state and combine before row rendering; status counts remain over the full three-week machine set; base-week changes preserve those filters; export is explicitly full-scope. PHP/database and browser interaction tests were not available. |
| **Performance/scalability** | **NOT VERIFIED** | Pagination now slices the filtered machine array before HTML output, reducing rendered rows/DOM. The existing three `planned_availability()` calls still assemble the full fleet first, so this does not reduce DB/model work. No independently verified row count or response-time benchmark is available; query-level pagination/optimization needs measurement and careful preservation of full-scope counts. |
| **Consistency** | **PARTIAL** | Status semantics and `.badge` classes match the sibling planned-week page; weekly model values are reused. The rolling-specific CSS is scoped locally so the sticky behavior no longer depends on styles embedded in another view. Visual parity requires browser review. |
| **Security/permissions** | **PARTIAL** | Query parameters are scalar-checked, status is allow-listed, type is validated against types in this result, search is length-limited and used only for in-memory matching, and rendered values/URLs are escaped. Equipment ID is integer-cast in the existing profile route. Server authorization is unchanged and was not live-tested. |
| **Empty/error state** | **PARTIAL** | The controller now checks the existing forecast table readiness and the view presents an explicit setup state; an actually empty fleet and zero filter matches have separate messages. Those branches are source-tested only. |
| **Export consistency** | **PARTIAL** | Excel now includes the same weekly status, model downtime and planned-availability metrics as the screen; the complete workbook remains independent of filters. The file was not generated/opened in a real XLSX runtime here. |
| **Business rules** | **PARTIAL** | Source comparison shows no availability formula, week-resolution rule, role matrix, planning status vocabulary, data source, or mutation flow changed. New display filters only select rows after the existing forecast data is assembled. Runtime/data-fixture verification remains outstanding. |

## 6. Prioritized finding register

| ID | Severity | Evidence / current behavior | User impact | Recommendation / implemented resolution | Acceptance / verification | Dependencies / risks |
|---|---|---|---|---|---|---|
| RL-01 | **High** | `rolling.php` and `Forecast_excel::build_rolling()` summed `standing_hours + unplanned_hours`; `Forecast_model` also includes corrective downtime in its model-owned `downtime_hours`. | Screen/export understated downtime when corrective work was planned and could disagree with model availability. | Both surfaces now use the model's `downtime_hours`; no view/export formula is recreated. | Build a week with corrective hours; compare source output, display and generated XLSX. | PHP + MySQL/XLSX runtime fixture unavailable here. |
| RL-02 | **High** | Old header placed the week label over one of three subcolumns, and the first body cell repeated DT or showed `OUT`; weekly NO PLAN/STAND-BY states were not explicit. | Ambiguous column meaning and hidden planning exceptions increase misreading risk. | ISO-week headings span labelled STATUS / DOWNTIME h / PLAN AVAIL columns; badges derive from existing row fields. | Verify full-out, partial-out, standby, no-plan, planned, and mixed-week fixture rows. | No new status taxonomy; test source assertions pass, runtime fixture pending. |
| RL-03 | **Medium** | No rolling search, type filter or way to isolate weekly conditions. | Users must scan the full fleet to find a machine or planning exception. | Added GET search, observed equipment-type selector, status links and count summary. | Browser-test combined filters, preserved week/search/type state and no-match recovery; source assertions pass. | Server-rendered filtering runs after the existing 3 week-model calls; no live volume benchmark. |
| RL-04 | **Medium** | `tbl-sticky-first` existed, but its only rule was embedded in `planned.php`, not loaded by `rolling.php`. | Machine identity scrolled away from weekly metrics on narrow screens. | Added scoped sticky CSS in the Rolling view and a divider/opaque background. | Verify horizontal scroll, sticky layering and responsive matrix at phone/tablet widths. | Browser viewport testing unavailable. |
| RL-05 | **Medium** | Rolling controller did not pass forecast-table readiness; empty ledger reads could resemble a no-plan forecast. | Users could confuse a setup problem with an actual empty draft. | Controller now checks the existing readiness method and the view displays a dedicated setup message. | Exercise missing-ledger and truly-empty-fleet database fixtures separately. | No schema or data change; DB runtime unavailable. |
| RL-06 | **Low** | Machine ID was plain text; no machine-week detail route exists. | Users lacked a direct machine profile path from this view. | Registration links to the existing, separately authorized equipment profile; no invented machine-week route/action. | Verify profile click-through for forecast roles 1 and 4. | Equipment access policy remains server-authoritative; live auth untested. |
| RL-07 | **Medium / watch** | Matrix is horizontally scrollable; user reports the live fleet exceeds hundreds. The view now slices the filtered row list, but model assembly remains full-scope. | Large row sets can make scanning and browser rendering costly; pagination can also interrupt comparisons if weekly values are split. | Added 50-row-default pagination (10/25/50/100); each machine's three weekly values stay on one page row; KPIs, status counts and XLSX export remain full-scope. | Confirm exact reportable count and benchmark full render against paginated output; test page/filter/week-state preservation. | User-reported scale is not independently queried; PHP array pagination reduces DOM but not DB/model work. |

## 7. Recommended table structure and interaction specification

### Table structure

1. **MACHINE** — strong registration link to existing equipment profile; sticky on horizontal scroll.
2. **TYPE** — existing equipment type.
3. **LOCATION** — existing location.
4. For each ISO week, a grouped heading with date range and exactly three subcolumns:
   - **STATUS** — OUT OF SERVICE / OUT n DAYS / STAND-BY / NO PLAN / PLANNED, derived using the existing planned-week priority.
   - **DOWNTIME h** — model-provided weekly `downtime_hours`, including corrective work and the model's cap.
   - **PLAN AVAIL** — model-provided per-machine availability for that week; whole-week out-of-service displays `—` as it is not scored.

**Default sort:** existing registration-number order (`ksort`); no new sorting or business priority is inferred. Text identifiers are left aligned, status is centered, and hour/percentage values are right aligned with tabular numerals.

### Filters and toolbar

- **Search:** GET text search on registration number, equipment type and location; case-insensitive fallback; capped to 160 characters. The search is applied to rows already assembled for the three weeks, not to SQL.
- **Equipment type:** select populated only from types present in the reportable machine set.
- **Status tabs:** All machines; Out-of-service time in any week; Stand-by reserve; Missing a plan in any week; Planned in all 3 weeks.
- **Counts:** full three-week machine counts, not search/type filtered. “Any week” conditions can overlap; an explanatory note says counts need not sum to All. Planned-in-all-weeks is a distinct all-three-weeks condition.
- **State preservation:** status and page links retain the current week, search, equipment type, status, and page size. Search/type/status/week changes reset to page 1; Clear filters retains the current week and page size.
- **Page size/pagination:** a footer modeled on the Attendance & Timesheets (`team/report`) arrangement shows the visible range, Prev/numbered/Next links, and a Rows selector at the right. Server-rendered slicing follows search/type/status filters; default is 50 rows with allow-listed 10/25/50/100 sizes. Each machine's three-week row remains intact. KPI cards, status counts, and XLSX export remain full-scope. This limits rendered rows/DOM only: the model still assembles all three weeks before slicing.
- **View toggle:** not included. Cards would break the central side-by-side time comparison.
- **Export:** label makes full-scope export explicit. Search/type/status filters affect the screen only, not the existing rolling workbook endpoint.

### Empty, loading and error states

- **No forecast tables:** explicit setup alert, not a misleading “no plan”/empty fleet state; no export button is shown.
- **No machines in this forecast:** dedicated empty-fleet message.
- **No matches:** message with a clear-filters link.
- **Loading:** not applicable to the server-rendered GET page.
- **Database/runtime error:** not exercised; standard application error handling remains.

### Record inspection and actions

- Machine registration is a read-only link to the existing `equipment/view/{id}` profile. It does not add edit/delete/status changes or a new route.
- No machine-week action is added. `forecast/jobs` requires a specific date, and inventing a date/action from a rolling weekly row would be misleading. A dedicated machine-week detail page is a future product decision if users need it.
- Week selection and export remain the established controller actions; no mutation form is added.

### Visual, responsive and accessibility specification

- Use shared `.tbl`, `.card`, `.kpi`, `.badge`, `.btn`, `.form-control` and `.form-select` conventions. The rolling table owns only scoped matrix styles.
- Keep week groups visually separated with a stronger leading rule; keep the sticky machine ID on an opaque surface with a divider/shadow so scrolled data does not overlap it.
- Status remains text plus semantic shared badge; availability uses the existing green/amber/red thresholds but remains a number, not a status substitute.
- On narrow screens the three weekly KPI cards reduce from three columns to two and then one; controls wrap; filter links can scroll horizontally; the matrix scrolls inside its card instead of shrinking text or hiding data.
- Use a two-row semantic header, `scope=colgroup` week headings, a caption, associated labels, active-link `aria-current`, and a live result summary. No action depends on color alone.
- Print hides the filter/pagination furniture and horizontal overflow, restores normal table layout, and states that only the current page is printed; the full-plan XLSX remains the complete-fleet output. Print/browser output requires visual verification.

## 8. Before-and-after comparison

| Area | Before | After | Business behavior? | Verification |
|---|---|---|---|---|
| Weekly columns | One week label in a single column, then DT and AVAIL; body duplicated DT/status content. | ISO week spans STATUS, DOWNTIME h and PLAN AVAIL; one output per named column. | Presentation correction. | Source regression assertions; browser rendering pending. |
| Downtime | View and workbook recomputed standing + unplanned, omitting corrective. | Both use the existing model `downtime_hours` value. | Corrects displayed reporting value; model arithmetic untouched. | Source assertions; fixture/runtime parity pending. |
| Machine state | No weekly status column. | Existing out/out-days/standby/no-plan/planned state visible as badges. | Display-only; status derivation reuses existing row fields. | Source tests; mixed-state fixture pending. |
| Find/filter | No search/type/status narrowing. | GET search, type selector and three-week condition links; counts and scope disclosed. | No data/API mutation; filtered screen only. | Source tests; HTTP/browser combinations pending. |
| Pagination | Entire fleet rendered with no page navigation. | 50-row default, 10/25/50/100 choices; filters apply before slicing; filter/week/page-size state is preserved in GET links. | Presentation-only. KPI/status counts and XLSX remain full-scope; model assembly still processes the full set. | Source tests; live count, route timing and browser rendering pending. |
| Sticky machine ID | Class present; no CSS in this view. | Local scoped sticky-column CSS and a profile link. | UI/navigation only; existing route reused. | Source tests; viewport/click/access verification pending. |
| Missing-table state | Empty planned rows could resemble a no-plan fleet. | Controller readiness check and explicit setup message. | No forecast calculation change. | Static source; requires a database fixture. |
| XLSX | Two metrics per week, no weekly state, downtime omitted corrective. | Status, model downtime and planned availability per week; complete export remains full-scope. | Workbook layout/detail changes, no schema/API change. | Source tests; actual generated workbook not opened here. |

## 9. Implementation plan and dependencies

### Plan and dependencies

1. Trace the rolling route, `_rolling_data()` aggregation, per-week model fields, existing forecast status semantics, profile route and workbook builder — completed before edits.
2. Correct presentation defects by using the existing model downtime output and adding labels for existing row status fields; do not alter forecast calculations — implemented.
3. Add server-rendered GET filtering, counts, 10/25/50/100 page sizes, filter-preserving pagination, empty/setup states and existing profile navigation — implemented without an endpoint, JavaScript dependency or mutation action.
4. Align rolling XLSX fields with the screen and add source regressions/audit documentation — implemented.
5. Validate with PHP/database/browser/XLSX fixtures in an equipped environment — outstanding.

**New dependencies:** none. No migration, schema change, new route, new permission, frontend package, or production-data operation is required.

## 10. Updated code and implementation

- `application/controllers/Forecast.php` — `rolling()` now passes `tables_ready` and avoids building a forecast when the ledger table is missing; `_rolling_data()` carries the existing equipment ID in the internal machine record for the profile link.
- `application/views/forecast/rolling.php` — real GET filters and full-scope counts; a timesheet-style footer with range, Prev/numbered/Next links and a Rows selector; validated 10/25/50/100 pagination after filtering with query-state-preserving links; clear/full-empty/missing-table states; machine profile link; two-row grouped headers; per-week status badge; model-owned downtime; local sticky/responsive/print styles and metric explanation. Pagination reduces rendered rows but not model/database assembly work.
- `application/libraries/Forecast_excel.php` — rolling workbook now emits status, model downtime and plan availability for each week; metric comes directly from the model row.
- `tests/table_ux.js` — rolling regressions for grouped headers, controls/count scope, filter-preserving allow-listed pagination, downtime consistency, equipment-profile route, and workbook parity.
- `tests/README.md` — documents the rolling-specific checks and verification limits.
- `docs/forecast-rolling-table-audit.md` — this targeted audit/specification and acceptance checklist.

No `Forecast_model` formula, route definition, database schema, production data, or permission logic changed.

## 11. Test results and verification evidence

- `node tests/table_ux.js` — **passed** after pagination-specific assertions were added; verifies page-size allow-list, slicing after filters, state-preserving URLs and pagination navigation markup, along with existing table/budget/Asset Register structural checks.
- `node --check tests/table_ux.js` — **passed**.
- `node tests/page_titles.js` — **passed** (87 rendered views).
- `node tests/global_find_attention.js` — **passed**.
- `node tests/reports_hub.js` — **passed** (49 report endpoints verified).
- PHP AST parsing with `php-parser` 3.2.5 for `Forecast.php`, `Forecast_excel.php` and `forecast/rolling.php` — **passed**; parser checks syntax shape but are not native PHP lint/runtime tests.
- `git diff --check` — **passed**.
- `php -l` / `php tests/run.php` — **not available**: PHP is not installed in this workspace.
- Live fleet-size/performance assessment — **not independently performed**: the user reports that the fleet exceeds hundreds, but the application config targets a local database and this workspace has no PHP runtime, MySQL/MariaDB client or server, or database-related environment configuration. The repository's asset-register workbook is not live data and was not used as a substitute. Pagination now limits rendered rows; confirm the exact reportable row count from the same `Forecast_model::planned_availability()` results and measure route response time/browser rendering before deciding if query-level optimization is also needed.
- DB-backed checks — **not run**: no MySQL/MariaDB instance.
- Browser/accessibility, viewport, profile-link permission, and XLSX download/open verification — **not run**.

## 12. Outstanding risks and unresolved decisions

Runtime acceptance remains open for database fixture branches, mixed filter combinations, browser/accessibility behavior, live profile authorization and generated XLSX output. The current source-level tests establish structural contracts only. See the checklist below before production acceptance.

## 13. Acceptance checklist

- [x] Preserve the three-week forecast-model data source, formulas, user roles, route and mutation behavior.
- [x] Use existing machine/week fields and status semantics; no new database status or metric.
- [x] Correct duplicate/ambiguous weekly table cells and use model downtime (including corrective) in screen and workbook.
- [x] Add real GET search, type and status filtering with explicit overlapping/full-scope counts.
- [x] Keep the current matrix, intact per-machine weekly comparisons and registration order; no card layout was added.
- [x] Add view-level pagination (default 50, allow-listed 10/25/50/100) after filters, retaining each machine's three-week row and full-scope counts/KPIs/export.
- [ ] Confirm the user-reported fleet size and benchmark full render against the paginated DOM; determine whether query/model-level optimization is also required.
- [x] Add missing-table, empty-fleet and no-match source states.
- [x] Reuse existing equipment-profile URL; no new mutation action or permission.
- [x] Extend source-level table regression checks.
- [ ] Verify fixture rows for full out-of-service, partial `out_days`, standby, no-plan and planned across all three weeks.
- [ ] Compare weekly model `downtime_hours` and availability with screen and generated workbook, including corrective hours and out-of-service handling.
- [ ] Verify filters combine, counts remain full-scope/overlap as stated, week change preserves state, and zero matches recover in a browser.
- [ ] Exercise missing-ledger and true-empty-fleet states against a database.
- [ ] Verify profile access for forecast roles 1 and 4; server-side equipment authorization remains authoritative.
- [ ] Check desktop/tablet/mobile horizontal scroll, sticky first column, keyboard/focus, screen-reader headings, contrast and print output.
- [ ] Confirm the reportable fleet count and benchmark full render versus 50-row pages (10/25/100 alternatives); decide whether view-level slicing suffices or query-level optimization is required.
