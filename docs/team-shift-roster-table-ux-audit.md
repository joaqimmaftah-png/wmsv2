# Shift Roster (`team/shift_roster`) — Professional Data Table UX Audit, Improvement & Implementation

**Date:** 2026-10-27 · **Scope:** `application/views/team/shift_roster.php`, `Team::shift_roster()` and its existing endpoints (`assign_shift`, `shift_save`, `shift_delete`). No schema changes, no new permissions, no invented fields/statuses.

**Key domain fact established by inspection:** an assignment is **one shift per worker per week** (`shift_assignments`: `user_id`, `shift_id`, `week_start`; the model deletes any existing row for the week before inserting). The 7 day cells repeat that single weekly shift — the matrix is a *weekly coverage board*, not a per-day scheduler. The redesign preserves this model exactly.

---

## 1. Executive Summary

The roster answered "who is on which shift this week?" but forced supervisors to scan every active worker to find gaps: no coverage summary, no Unassigned view, no search/filter/sort/pagination, unescaped worker names, unlabelled assign controls, and no export. This change rebuilds the page as an enterprise table in the house pattern (shared with the Certifications Tracker): KPI coverage strip, Assigned/Unassigned tabs with counts, search + shift filter + chips, sorting, pagination, Table/Cards views, week CSV export (Supervisors & Managers), print view, accessible labels — while every mutation still posts to the existing supervisor-only endpoints with unchanged validation.

## 2. Table Purpose and User Workflow

- **Purpose:** weekly operational planning/monitoring — assign each active worker a shift for a chosen week and see coverage gaps at a glance.
- **Primary users:** Supervisor (plans/assigns, manages shift types), Manager (read-only oversight/export), Technicians/Store Keepers (check their own week's shift).
- **Frequent tasks:** (1) find who is still unassigned this week; (2) assign/clear a worker's shift; (3) move between weeks; (4) check coverage per shift; (5) add/edit/remove shift types; (6) share/export the week.
- **Record:** one worker-week row (worker × week → shift or Unassigned). Primary identifier: worker (name + employee ID); detail page is the worker profile (`team/view/<id>`).
- **Statuses (existing only):** Assigned (shift set) / Unassigned (no row for the week). Shift types are user-defined records, not statuses.
- **Actions & permissions (unchanged):** assign/clear + shift-type CRUD — supervisor-only, enforced server-side in `Team::assign_shift/shift_save/shift_delete`; roster itself visible to roles 1–4 via the `team` ACCESS_MATRIX.

## 3. Existing Implementation Findings (pre-change evidence)

- Single full-page table of **all** active workers; week navigation via prev/next/`input[type=week]` (good, preserved).
- Same shift repeated across 7 columns; today highlighted; legend with shift times under the table.
- Per-row assign `<select>` + Set form (supervisor only) — functional, but the select had **no accessible label**, and switching a shift required a full form submit per worker (unchanged — server pattern).
- Shift-type editor: one inline form per shift + add form + delete with confirm; delete blocked server-side while assigned anywhere.
- **Defects:** worker name output **unescaped** (`$w->first_name . ' ' . $w->last_name`) — XSS via a crafted name; no search/filter/sort/pagination (all workers always rendered); no coverage/gap summary; no export for the roster week (`roster_csv` exports the workforce directory, a different dataset); shift-type inputs largely unlabelled; delete confirm didn't state the assignment constraint; no print styles; custom shift names all fell back to one grey badge class (name text still carried meaning).

## 4. UX Audit Matrix — 10 Principles

| # | Principle | Verdict (before) | Evidence / change |
|---|---|---|---|
| 1 | Status tabs | **FAIL → Fixed** | No Assigned/Unassigned segmentation. Added All Staff / Assigned / Unassigned tabs; counts after search+shift filter (house rule), KPI coverage computed on the full week |
| 2 | Smart toolbar | **PARTIAL → Fixed** | Only week navigation existed. Added search (`/` shortcut, 450 ms debounce), shift filter (incl. Unassigned only), sort select, Table/Cards toggle, Export CSV; week nav preserved |
| 3 | Professional table | **PARTIAL → Fixed** | Matrix kept (it *is* the domain view); Worker header sortable with `aria-sort`/`scope`, today marked in text ("· today") not colour alone, names escaped and linked to profile |
| 4 | Card view | **FAIL → Fixed** | Worker cards with week strip (7 day cells, today outlined), shift badge/times, profile link, assign form for supervisors; state (week/filters/sort) preserved via URL |
| 5 | Record detail | **PARTIAL (justified)** | A roster row's detail *is* the worker profile; worker names now link there. A drawer was not added — assignment context (one shift/week) fits on the row; inventing per-day detail would misrepresent the data model |
| 6 | Contextual row actions | **PARTIAL → Fixed** | Assign select kept visible (primary task); every select now has an `sr-only` label naming the worker; Delete-shift confirm now states the assignment constraint |
| 7 | Semantic colour | **PARTIAL** | Shift badge colours keyed to Morning/Afternoon/Night names, grey fallback; meaning never colour-alone (shift name text in every cell). Unassigned is an explicit grey badge/"—" plus, in cards, the word "Unassigned" |
| 8 | Pagination | **FAIL → Fixed** | Server pagination 10/25/50/100 with windowed pager, `aria-current`, "Showing a–b of N workers"; filters/sort/week preserved in every link |
| 9 | Responsive | **PARTIAL → Fixed** | Matrix scrolls horizontally (correct for a week board); cards give a no-scroll mobile path; KPI strip collapses to 2 columns ≤900 px |
| 10 | Micro-interactions & a11y | **PARTIAL → Fixed** | `/` focuses search, debounce, labelled week/shift/sort/per-page controls, `role=tablist`, focus-visible inherits house styles, print banner + `@media print` hides controls |

## 5. Additional Enterprise-Grade Findings

- **A. Data accuracy:** coverage %, per-shift headcounts and tab counts are computed from the same decorated dataset the rows render from; Unassigned is explicit (no row = unassigned), never conflated with a shift named "none". Week is normalised server-side by the existing `_roster_week()` (ISO week/DateTime guarded).
- **B. Functional integrity:** search (name/ID/position/shift), shift filter, tab, sort (Worker/Position/Shift), pagination and CSV all compose from `_build_shift_roster_dataset()`; the export mirrors the on-screen filters for the chosen week.
- **C. Performance:** one `get_all_active()` + one assignments query per render (unchanged); filtering/sorting/pagination in PHP over the active-staff set (bounded by headcount, not history).
- **D. Consistency:** same KPI/tab/chip/pager/token conventions as the Certifications Tracker and workforce directory; existing endpoints, flash messages and week-redirect behaviour preserved.
- **E. Security:** worker names/positions/employee IDs escaped everywhere (fixes the unescaped-name output); CSV gated to Supervisors/Managers server-side with formula-injection sanitisation; mutations remain POST + CSRF via `form_open` and supervisor-checked in the controller — UI gating is never the only control.

## 6. Prioritized Defect & Improvement Register

| ID | Severity | Finding → outcome |
|---|---|---|
| SR-01 | **High** | Worker names rendered unescaped → escaped everywhere (table, cards, chips); verified by render harness with a `<b>` payload name |
| SR-02 | **High** | No way to find coverage gaps (unassigned workers) without scanning all rows → Unassigned tab + coverage KPI + per-shift headcounts |
| SR-03 | **Medium** | No search/filter/sort/pagination; page grows with headcount → full toolbar + server pagination |
| SR-04 | **Medium** | No roster-week export → `team/shift_roster_csv` (Supervisors/Managers), filter-faithful |
| SR-05 | **Medium** | Assign/shift-type/week controls unlabelled → `label`/`sr-only` labels naming the worker/control; `role=tablist`, `aria-sort` |
| SR-06 | **Low** | Delete-shift confirm didn't explain the assignment block → confirm text + existing "Still assigned" hint retained |
| SR-07 | **Low** | No print output → print banner + control-hiding print CSS |
| SR-08 | **Low** | Custom shift names all grey badge → retained (name text carries meaning); documented, not recoloured arbitrarily |

## 7. Recommended Table Structure & Interaction Specification (as built)

- **Columns:** Worker (name link, employee ID · position; sortable) | Mon–Sun (date + shift badge or "—", today column tinted + "· today") | Assign (supervisor; select + Set, posts to `team/assign_shift`).
- **Tabs:** All Staff / Assigned / Unassigned (order = planning workflow: everyone → done → gaps). Counts after search + shift filter.
- **KPI strip (full week, before tabs):** Active Staff · Assigned · Unassigned · Coverage % · Shift Types with per-shift headcount.
- **Toolbar:** search, shift filter (All / Unassigned only / each shift), sort select (Worker/Position/Shift × asc/desc), Apply, Clear, Table/Cards toggle, Export CSV (Supervisors/Managers).
- **Cards:** worker, ID · position, shift badge, 7-day strip, shift times, assign form.
- **Pagination:** 10/25/50/100, windowed pager, showing summary; all state in the URL (`week` always preserved).
- **Shift Types panel:** unchanged endpoints/validation; labelled inputs; delete confirm states the assignment constraint.

## 8. Responsive Behaviour

Desktop: full matrix. ≤900 px: KPI strip 2-up; matrix horizontally scrollable inside `.table-responsive` (week structure must stay comparable); Cards view offers a scroll-free alternative with identical data/actions. Print: banner with week range, controls hidden, matrix only.

## 9. Before-and-After Comparison

| Area | Before | After | Business behaviour changed? |
|---|---|---|---|
| Find unassigned | Scan all rows | Unassigned tab + KPI count | No — same assignments |
| Worker name safety | Raw output | Escaped | No |
| Navigation | All workers, one page | Search/filter/sort/pagination | No |
| Export | Directory CSV only (different data) | Week roster CSV, filter-faithful | No (new read-only endpoint) |
| Mobile | Wide table only | Cards view | No |
| Assign workflow | Select + Set per row | Identical, now labelled | No |

## 10. Implementation Plan & Dependencies (executed)

1. Controller: `_build_shift_roster_dataset()` + paged `shift_roster()` + `shift_roster_csv()`. 2. View rewrite in house tokens. 3. Structural tests + CI wiring. 4. PHP-WASM verification. No migrations, no endpoint changes for mutations, no permission changes.

## 11. Implementation

Files: `application/controllers/Team.php` (dataset builder, CSV), `application/views/team/shift_roster.php` (rewrite), `tests/shift_roster_ux.js` (new), `.github/workflows/tests.yml` (suite wired), this document. Existing `assign_shift` / `shift_save` / `shift_delete` untouched.

## 12. Test Results & Verification Evidence

| Check | Result |
|---|---|
| PHP-WASM `TOKEN_PARSE` lint (controller, view) | ✅ OK |
| Dataset + render harness (real PHP, stubbed model): tab counts, coverage 67% on 2/3 assigned, search-by-shift narrows before counts, shift=none/id filters, shift sort, escaped name render, badges, assign form supervisor-only, cards, exactly one `<h1>` | ✅ 16/16, zero PHP warnings |
| `node tests/shift_roster_ux.js` (new, in CI) | ✅ Pass |
| Existing suites: `certifications_table_ux`, `page_titles`, `table_ux`, `global_find_attention`, `reorder_ux`, `reports_hub`, `service_kits_ux` | ✅ Pass |
| `node tests/lubricant_masters_ux.js` | ❌ Pre-existing failure (lubricant views untouched; disclosed, not fixed here) |
| Live MySQL/browser E2E | ⏳ Runs in CI — **not verified locally** |

## 13. Outstanding Risks, Unresolved Decisions & Acceptance Checklist

**Not built (need decisions, not invented):** per-day assignments (the schema stores one shift per worker-week — a per-day roster is a schema/product decision); drag-to-assign or bulk-assign (would change the mutation pattern); shift colours for custom shift names beyond the three built-in names (needs a colour field or a naming convention decision).

**Acceptance checklist:** ☑ correct records/values (harness) · ☑ gap-finding task supported (Unassigned tab/KPI) · ☑ search/filters/sort/pagination/export compose (one builder, tested) · ☑ counts match defined rules · ☑ actions functional & authorised (existing endpoints; supervisor-gated + server-checked) · ☑ worker detail opens correct profile · ☑ destructive op confirms + constraint stated · ☑ responsive preserves week structure (scroll) + cards path · ☑ empty states (no staff / all assigned / no matches) · ☑ keyboard/labels (`/`, tabs, aria-sort, labelled selects) · ☑ house conventions · ☑ tests pass with failures disclosed · ☑ no functionality removed (assign + shift-type CRUD + week navigation preserved) · ☐ CI DB/E2E on PR (pending, outside sandbox).

**Sample verification tasks:** (1) Supervisor: "who still needs a shift next week?" → next week → Unassigned tab. (2) Manager: export next week's roster → CSV matches the filtered screen. (3) Technician: "what shift am I on?" → search own name, read the week strip.
