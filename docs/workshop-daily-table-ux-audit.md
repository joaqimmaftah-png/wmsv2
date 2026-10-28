# Workshop Daily Report (`reports/workshop_daily`) — Professional Data Table UX Audit, Improvement & Implementation

**Date:** 2026-10-28 · **Scope:** `application/views/reports/workshop_daily.php`, `Reports::workshop_daily()` and `_build_workshop_daily()` (report math unchanged). XLSX/PDF exports untouched. No schema changes, no new permissions, no invented fields/statuses.

**Prior related work:** `docs/workshop-daily-ux-review.md` reviewed this screen's UX previously; the KPI-engine data corrections it drove (engine availability on operating hours, required-weighted rollups) are *preserved exactly* — this pass is the data-table layer only.

---

## 1. Executive Summary

The Workshop Daily is the fleet's morning meeting on one screen: KPI header, per-type availability summary, per-type charts, and one wide detail table (18 columns) listing every machine grouped by equipment type. The detail table rendered the **entire fleet at once** with no tabs, search, type filter, sorting or pagination — on a large fleet, finding "everything that's down" or "the truck down longest" meant scanning the whole sheet. This change flattens the grouped report into a proper enterprise table (All/Down/Available tabs, type filter, search, sorting, pagination, Table/Cards) while the grouped report — KPIs, fleet summary strip, charts, and the XLSX/PDF exports — continues to cover the full fleet exactly as before. The screen now states that export scope honestly ("Excel / PDF above always export the full day").

## 2. Table Purpose and User Workflow

- **Purpose:** daily fleet monitoring/reporting — how much of the fleet is available today, what is down, since when, and what is being done about it.
- **Primary users:** Supervisors & Managers only (`reports` ACCESS_MATRIX roles 1, 4). Read-only report; the underlying records are edited in Equipment/Work Orders, not here.
- **Frequent tasks:** (1) morning check — what's down and for how long; (2) find a specific machine; (3) review one equipment type; (4) spot long-down machines (days ↓, downtime hours); (5) share the day (Excel/PDF/print).
- **Record:** one machine-day row. Primary identifier: registration/machine ID → equipment profile (`equipment/view/<id>`). Status (existing only): Down / Available, derived by the existing engine rules (open work order in Open/In Progress/Waiting Parts/Waiting Tools, or `equip_is_down()`).
- **Data semantics (preserved, not re-derived):** availability comes from `Kpi_engine_model` (operating-hours day, downtime as the union of breakdown intervals — Q1), group KPI is the required-weighted rollup ("never an average of percentages"), statuses from the report builder. The table layer only *selects and orders* those values.

## 3. Existing Implementation Findings (pre-change evidence)

- Strengths kept: engine-sourced availability with documented provenance comments; KPI header + per-type summary strip + per-type bar charts matching the PDF layout; XLSX/PDF exports; print CSS; escaped machine fields.
- **Defects:** (a) entire fleet rendered in one 18-column, `min-width:1200px` table — no tabs/search/filter/sort/pagination; (b) group headers/counts were fused into the first row of each group (counts vanished when you scrolled; the row's TYPE cell only filled on the first row), so sorting/filtering by machine was impossible without restructuring; (c) machine IDs were plain text — no link to the equipment profile; (d) blanks for zero values (Total DT, Days ↓, Start Date) read as missing data rather than zero/none; (e) no empty-state distinction (no fleet vs. nothing down vs. no filter match); (f) date input had no label.

## 4. UX Audit Matrix — 10 Principles

| # | Principle | Verdict (before) | Evidence / change |
|---|---|---|---|
| 1 | Status tabs | **FAIL → Fixed** | All Machines / Down / Available tabs with counts after search + type filter; type headcounts also live in the Fleet Summary strip |
| 2 | Smart toolbar | **PARTIAL → Fixed** | Date + Excel/PDF/Print existed (kept). Added search (`/`, debounce), type filter, Table/Cards toggle; toolbar notes exports are full-day |
| 3 | Professional table | **PARTIAL → Fixed** | Type now on every row (group-fused first-row pattern removed from the *screen* table; PDF keeps its layout), sortable Type/Machine/Status/Total DT/Days ↓/Avail % with `aria-sort`/`scope`, numerics right-aligned, zero shown as "—" deliberately |
| 4 | Card view | **FAIL → Fixed** | Machine cards: ID/type/model/location, status badge, Avail/Downtime/Days-down/E-T metrics, observation + ongoing action; same state via URL |
| 5 | Record detail | **PARTIAL → Fixed** | Machine IDs now link to `equipment/view/<id>` (the detail page) in table + cards; a drawer was not added — the profile owns machine detail |
| 6 | Contextual row actions | **N/A (justified)** | Read-only report for roles 1/4; the only row action is opening the equipment profile. Mutations belong to Equipment/Work Orders — none invented here |
| 7 | Semantic colour | **PASS → kept** | Down = `b-danger`, Available = `b-success` badges with text; availability % and days-down colour thresholds retained with numeric text (never colour-alone) |
| 8 | Pagination | **FAIL → Fixed** | Server pagination 10/25/50/100, windowed pager, `aria-current`, "Showing a–b of N machines"; date/filters/sort preserved in links |
| 9 | Responsive | **PARTIAL → Fixed** | Wide table still scrolls on small screens (18 data points need width); Cards view gives the no-scroll path; KPI/fleet strips already wrap |
| 10 | Micro-interactions & a11y | **PARTIAL → Fixed** | Search shortcut + debounce, labelled date/search/type/per-page controls, `role=tablist`, existing print CSS retained |

## 5. Additional Enterprise-Grade Findings

- **A. Data accuracy:** the table displays the engine's values verbatim; per-type "# EQ / AVAIL / DOWN" and Daily KPI moved out of fused table rows into the existing Fleet Summary strip (same numbers, always visible). Zero downtime/days now render "—" (means none) instead of blank.
- **B. Functional integrity:** search (machine/model/location/observation/action), type filter, status tab, sort (Type/Machine/Status/Availability/Days↓/Total DT) and pagination compose from `_build_workshop_daily_dataset()` over the unchanged grouped report; charts/KPIs always reflect the full day regardless of table filters (they're the day summary — filtering them by a table search would misreport the fleet).
- **C. Performance:** one grouped-report build per request (unchanged queries); flatten/filter/sort in PHP over the fleet (bounded by fleet size); exports build the same report independently.
- **D. Consistency:** KPI strip, tabs, chips, pager, tokens and badge classes follow the house pattern used across Team/Attendance/Reports tables; module `.tbl/.card` chrome retained.
- **E. Security:** reports role gate (1/4) unchanged; XLSX/PDF endpoints untouched; all machine text escaped (was already; verified with a payload registration in the harness).

## 6. Prioritized Defect & Improvement Register

| ID | Severity | Finding → outcome |
|---|---|---|
| WD-01 | **High** | Whole fleet in one unpaginated, unsortable sheet → tabs/search/type filter/sort/pagination; verified by harness |
| WD-02 | **Medium** | Group counts fused into first rows (lost on scroll, blocked sorting) → counts live in Fleet Summary strip + tab counts; type repeated per row |
| WD-03 | **Medium** | No path from a row to the machine's record → machine ID links to the equipment profile |
| WD-04 | **Low** | Zero values blank (ambiguous) → "—" for none/zero downtime, days, dates |
| WD-05 | **Low** | No empty-state semantics → distinct no-fleet / nothing-down / no-match states |
| WD-06 | **Low** | Unlabelled date input → toolbar labels (`for`/`sr-only`) across the new controls |

## 7. Recommended Table Structure & Interaction Specification (as built)

- **Columns (screen table):** Type | Machine ID (profile link) | Model | Location | Observation | Ongoing Action | E/T | Status badge | Down Time | Up Time | Total DT (right) | Start Date | Days ↓ | Avail % (right). Group counts (# EQ/AVAIL/DOWN, Daily KPI) remain in the Fleet Summary strip above.
- **Tabs:** All Machines / Down / Available (morning-meeting order: everything → problems → healthy). Counts after search + type filter.
- **Toolbar:** date (unchanged), search, type filter with per-type counts, Apply/Clear, Table/Cards, page-size in pager; Excel/PDF/Print unchanged and explicitly full-day.
- **Cards:** machine ID (link), type · model · location, status, Avail/Downtime/Days-down/E-T, observation, ongoing action.
- **Sort defaults:** Machine ID A–Z; Availability ↑ surfaces worst machines first; Days ↓ orders by days-down value.

## 8. Responsive Behaviour

Desktop: full table. Small screens: table scrolls horizontally (data density justified); Cards view provides the touch path with the decision-critical fields (status, availability, days down). Print: existing print CSS (sidebar/topbar/controls hidden, compact table) retained.

## 9. Before-and-After Comparison

| Area | Before | After | Business behaviour changed? |
|---|---|---|---|
| Find down machines | Scan whole sheet | Down tab + count | No |
| Sort by worst availability | Impossible (fused groups) | Avail % / Days ↓ / Total DT sorting | No |
| Machine detail | None | Profile link per machine | No |
| Large fleets | One huge table | Pagination 10–100 | No |
| Exports | XLSX/PDF full day | Identical, scope now stated on screen | No |
| KPIs/charts | Full day | Full day (unchanged, deliberately) | No |

## 10. Implementation Plan & Dependencies (executed)

1. Controller: `_build_workshop_daily_dataset()` flattening + `workshop_daily()` paging; `_build_workshop_daily()` and both export endpoints untouched. 2. View: tabs/toolbar/cards/pagination around the existing KPI/strip/charts. 3. Structural tests + CI. 4. PHP-WASM verification.

## 11. Implementation

Files: `application/controllers/Reports.php` (dataset builder + paged screen data), `application/views/reports/workshop_daily.php`, `tests/workshop_daily_ux.js` (new), `.github/workflows/tests.yml`, this document.

## 12. Test Results & Verification Evidence

| Check | Result |
|---|---|
| PHP-WASM `TOKEN_PARSE` lint (controller, view) | ✅ OK |
| Dataset + render harness (real PHP; 5 machines, 2 types, 2 down): flatten counts 5/2/3, type counts, Down tab, search-by-model narrows before counts, type filter, availability/days-down sorts, escaped registration render, badges, profile links, cards, "Nothing is down" state, exactly one `<h1>` | ✅ 14/14, zero PHP warnings |
| `node tests/workshop_daily_ux.js` (new, in CI) | ✅ Pass |
| Existing suites: `team_profile_ux`, `attendance_log_ux`, `shift_roster_ux`, `certifications_table_ux`, `page_titles`, `table_ux`, `global_find_attention`, `reorder_ux`, `reports_hub`, `service_kits_ux` | ✅ Pass |
| `node tests/lubricant_masters_ux.js` | ❌ Pre-existing failure (lubricant views untouched; disclosed, not fixed here) |
| Live MySQL/browser E2E (incl. XLSX/PDF generation) | ⏳ Runs in CI — **not verified locally** |

## 13. Outstanding Risks, Unresolved Decisions & Acceptance Checklist

**Not built (documented):** screen-filtered export (CSV of the filtered table) — the module's official outputs are the full-day XLSX/PDF; a filtered CSV would be a new artifact whose audience/format needs a decision. Availability-target (per-machine `target`) display: the builder already computes `target` per machine but the screen never showed it; adding a target column/variance is a reporting decision, not smuggled in here. Multi-day range table — `workshop_weekly` owns ranges.

**Acceptance checklist:** ☑ correct records/values (engine values verbatim; harness) · ☑ hierarchy supports the morning meeting (Down first-class) · ☑ search/filters/sort/pagination compose · ☑ counts match defined rules · ☑ actions functional & authorised (read-only; profile links correct) · ☑ record detail opens the correct machine · ☑ no destructive ops on this page · ☑ responsive preserves status/availability/days-down · ☑ empty states (no fleet / nothing down / no match) · ☑ keyboard/labels (`/`, tabs, aria-sort, form labels) · ☑ house conventions · ☑ tests pass with failures disclosed · ☑ no functionality removed (KPIs, strip, charts, XLSX/PDF, print intact) · ☐ CI DB/E2E on PR (pending, outside sandbox).

**Sample verification tasks:** (1) Manager: "what's down right now and for how long?" → Down tab, sort Days ↓ descending. (2) Supervisor: find one machine → search its registration → open its profile. (3) Manager: send today's sheet → Excel/PDF (full day, as labelled).
