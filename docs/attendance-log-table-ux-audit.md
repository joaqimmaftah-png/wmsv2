# Attendance Punch Log (`attendance/log`) — Professional Data Table UX Audit, Improvement & Implementation

**Date:** 2026-10-27 · **Scope:** `application/views/attendance/log.php`, `Attendance::log()` / `Attendance::correction()` (unchanged mutation), `Attendance_model::get_punches()` (unchanged query). No schema changes, no new permissions, no invented fields/statuses.

**Module rule preserved absolutely:** punches are immutable. A correction is a *new* event (`origin = supervisor`, reason + author stored); nothing on this page edits or deletes history. The redesign touches only how the day is *read*.

---

## 1. Executive Summary

The punch log is the attendance audit view: a per-worker day summary (state + assignment eligibility), a supervisor correction form, and the raw punch-events table. The events table listed the whole day unpaginated with no tabs, search, site filter, sorting or export — on a busy site, finding "the correction" or "Jane's punches" meant scanning every row. This change adds the enterprise table layer to the events table only (KPI strip, type/corrections tabs, search, site filter, sorting, pagination, Table/Cards, CSV export) while leaving the worker summary, correction workflow and immutability guarantee intact. One data-semantics trap was caught during implementation: the displayed radius must stay the **punch-time snapshot** (`attendance_punches.radius_m`), not the site's current radius — verified against the schema.

## 2. Table Purpose and User Workflow

- **Purpose:** audit & troubleshooting of one day's attendance — reconstruct exactly who punched, when, where, how (phone vs correction), and correct missed punches transparently.
- **Primary users:** Supervisor (reviews, corrects), Manager (read-only oversight). Page is supervisor/manager-only server-side (`Attendance::log()` guard); corrections are supervisor-only (`correction()` guard + POST).
- **Frequent tasks:** (1) check who is on site / eligible right now; (2) find a worker's punches; (3) review corrections and their reasons; (4) add a correction with a reason; (5) hand the day to payroll/audit (export); (6) jump to the worker's profile.
- **Records:** (a) worker-day summary rows (worker → punches, ON SITE/AWAY state, eligibility + block reasons); (b) punch events (one row per punch). Primary identifier: worker (name + employee ID) → profile `team/view/<id>`; event identifier: server timestamp + worker.
- **Statuses (existing only):** punch types `check_in`/`check_out`; origin `phone`/`supervisor` (correction); worker state ON SITE/AWAY; eligibility Yes/block-reasons. No new statuses were created.
- **Relationships:** sites (geofence), worker profiles, AML timesheets (`team/report`), shift roster eligibility inputs (certs/shift — computed by the model, untouched).

## 3. Existing Implementation Findings (pre-change evidence)

- Strong foundations kept: immutable-event audit model with reasons/authors; eligibility block reasons shown in words; correction form explains it "cannot be edited away later"; date picker + Today shortcut; timesheets link.
- Worker summary table: punches inline per worker (time + IN/OUT + ✎ marker), state badge, eligibility text. Escaped output throughout via `$h()`.
- **Defects (events table):** all punches for the day rendered at once; no type segmentation; no search/site filter/sort; no pagination; no export; correction form `<label>`s had no `for` association; origin badge for corrections used a hard-coded hex (`#6d3fc0`) instead of tokens; worker names in the summary weren't linked to profiles.

## 4. UX Audit Matrix — 10 Principles

| # | Principle | Verdict (before) | Evidence / change |
|---|---|---|---|
| 1 | Status tabs | **FAIL → Fixed** | All / Check-ins / Check-outs / Corrections tabs with counts after search+site filter. Documented overlap: Corrections is a subset view (origin-based) — type counts always sum to All |
| 2 | Smart toolbar | **PARTIAL → Fixed** | Date navigation existed (kept). Added search (`/`, 450 ms debounce), site filter, Table/Cards toggle, Export CSV |
| 3 | Professional table | **PARTIAL → Fixed** | Sortable Time/Worker/Punch headers (`aria-sort`, `scope`), numeric Distance/Accuracy right-aligned, badges via tinted tokens with text labels (never colour alone) |
| 4 | Card view | **FAIL → Fixed** | Event cards: worker/site, type badge, big timestamp, distance/accuracy, origin + reason/author; state preserved via URL |
| 5 | Record detail | **PARTIAL (justified)** | A punch's detail is fully on the row (time, site, distance, accuracy, origin, author, reason); the worker profile is the person-level detail and is now linked from both tables. A drawer would duplicate row content |
| 6 | Contextual row actions | **N/A (justified)** | Punch rows intentionally have **no** actions — immutability is the product rule. The only mutation (correction) stays a deliberate, reasoned form; not turned into row buttons |
| 7 | Semantic colour | **PARTIAL → Fixed** | Check-in green / check-out amber retained; correction purple now uses `--purple-fill`/`--purple` tokens instead of hard-coded hex; all statuses carry text |
| 8 | Pagination | **FAIL → Fixed** | Server pagination 10/25/50/100, windowed pager, `aria-current`, "Showing a–b of N punches"; date/filters/sort preserved in every link |
| 9 | Responsive | **PARTIAL → Fixed** | Events table scrolls horizontally; cards give a no-scroll path; KPI strip collapses ≤900 px |
| 10 | Micro-interactions & a11y | **PARTIAL → Fixed** | Correction labels now `for`-associated; search keyboard shortcut + debounce; `role=tablist`; print banner + print CSS hides controls |

## 5. Additional Enterprise-Grade Findings

- **A. Data accuracy:** radius is the punch-time snapshot (`punches.radius_m`, "Radius in force when the punch was accepted" per `database/migrations/0010_attendance_punches.sql`) — *not* `sites.radius_m` via the model's `site_radius_m` alias. The implementation initially read the alias and was corrected before delivery; a structural test now pins the snapshot field. Distances/accuracies rounded for display exactly as before.
- **B. Functional integrity:** search (worker/ID/site/reason/author), site filter, tabs, sort (Time/Worker/Type), pagination and CSV compose from one builder (`_build_log_dataset`); the worker summary still groups the *full* day's punches (its counts are not narrowed by the events filters — deliberate, it's the day state) — and the KPI "On Site" tile is labelled "*state for the selected date".
- **C. Performance:** one `get_punches()` query per render (unchanged); filtering in PHP over a single day's events (bounded by daily headcount, not history).
- **D. Consistency:** KPI/tab/chip/pager/token conventions match the Certifications Tracker and Shift Roster; house `.tbl`/`.card` classes retained for this module's local style family.
- **E. Security:** page + export gated to Supervisors/Managers server-side; output escaped via the existing `$h()`; CSV sanitises formula-injection leading characters and uses BOM/UTF-8 like the other exports; correction endpoint untouched (POST + supervisor + reason ≥5 chars + worker existence, all pre-existing).

## 6. Prioritized Defect & Improvement Register

| ID | Severity | Finding → outcome |
|---|---|---|
| AL-01 | **High** | Busy days unfindable: no tabs/search/filter/sort/pagination on the events table → full enterprise toolbar; verified by dataset harness |
| AL-02 | **Medium** | No export of the day for payroll/audit → `attendance/log_csv`, filter-faithful, same role gate as the page |
| AL-03 | **Medium** | Correction form labels not associated (`for`/`id`) → all six controls labelled |
| AL-04 | **Low** | Hard-coded hex for the CORRECTION badge → design tokens |
| AL-05 | **Low** | Worker names not linked to profiles → linked in summary + events + cards |
| AL-06 | **Low** | No print output → print banner + control-hiding CSS |
| AL-07 | **Info (guarded)** | Radius semantics: punch-time snapshot vs current site radius — pinned by test so a future "cleanup" can't silently change audit meaning |

## 7. Recommended Table Structure & Interaction Specification (as built)

- **Events columns:** Time (sortable, mono) | Worker (name link + employee ID; sortable) | Punch badge (sortable) | Site | Distance (right, with punch-time radius) | Accuracy (right) | Origin (Phone / CORRECTION badge) | Recorded by / reason (author + quoted reason for corrections; IP for phone punches).
- **Tabs:** All Punches / Check-ins / Check-outs / Corrections (workflow order: everything → arrivals → departures → audit subset). Counts after search + site filter; overlap documented.
- **KPI strip (filtered day):** Punches · Check-ins · Check-outs · Corrections · On Site (selected date) with distinct-workers sub-line.
- **Toolbar:** search, site filter (distinct sites present that day), Apply/Clear, Table/Cards, Export CSV. Date navigation (prev form/Today) unchanged at page head.
- **Worker summary:** unchanged semantics; worker names link to profiles.
- **Correction form:** unchanged fields/validation; labels associated.

## 8. Responsive Behaviour

Desktop: summary + correction side-by-side, events full width. ≤900 px: KPI strip 2-up; events table horizontal scroll or Cards view; correction form stacks via the existing form-grid. Print: banner with the day, controls hidden, tables only.

## 9. Before-and-After Comparison

| Area | Before | After | Business behaviour changed? |
|---|---|---|---|
| Find a punch/correction | Scan the whole day | Tabs + search + site filter | No |
| Export | None | Filter-faithful CSV of the day | No (new read-only endpoint) |
| Long days | One huge table | Pagination 10–100 | No |
| Correction workflow | Form with unassociated labels | Same form, labels fixed | No |
| Punch radius shown | Punch-time snapshot | Same snapshot (guarded by test) | No |
| Row actions | None (by design) | Still none — immutability preserved | No |

## 10. Implementation Plan & Dependencies (executed)

1. Controller: `_build_log_dataset()` + paged `log()` wiring + `log_csv()`. 2. View: KPI/tabs/toolbar/cards/pagination on the events card; label fixes; token colour; profile links. 3. Structural tests + CI. 4. PHP-WASM verification incl. the radius-semantics check. No migrations; correction endpoint untouched.

## 11. Implementation

Files: `application/controllers/Attendance.php` (builder + CSV), `application/views/attendance/log.php`, `tests/attendance_log_ux.js` (new), `.github/workflows/tests.yml`, this document.

## 12. Test Results & Verification Evidence

| Check | Result |
|---|---|
| PHP-WASM `TOKEN_PARSE` lint (controller, view) | ✅ OK |
| Dataset + render harness (real PHP): tab counts (4/2/2/1), distinct workers, search-by-reason narrows before counts, site filter, worker/time sorts, escaped-name render, correction badge+reason, radius snapshot "150 m", cards, empty-day state, exactly one `<h1>` | ✅ 17/17, zero PHP warnings |
| `node tests/attendance_log_ux.js` (new, in CI) | ✅ Pass |
| Existing suites: `shift_roster_ux`, `certifications_table_ux`, `page_titles`, `table_ux`, `global_find_attention`, `reorder_ux`, `reports_hub`, `service_kits_ux` | ✅ Pass |
| `node tests/lubricant_masters_ux.js` | ❌ Pre-existing failure (lubricant views untouched; disclosed, not fixed here) |
| Live MySQL/browser E2E | ⏳ Runs in CI — **not verified locally** |

## 13. Outstanding Risks, Unresolved Decisions & Acceptance Checklist

**Not built (need decisions):** date-range (multi-day) log view — the module is deliberately day-scoped and corrections/timesheets build on that; a range report belongs with `team/report` semantics, not invented here. Per-worker day *detail* drawer — row + profile already carry the data (see §4.5 justification). "On Site Now" KPI reflects the *selected* date's end state, labelled as such; a real-time-only tile would need a defined rule for past dates.

**Acceptance checklist:** ☑ correct records/values (harness; radius snapshot guarded) · ☑ audit task supported (corrections tab/search) · ☑ search/filters/sort/pagination/export compose (one builder, tested) · ☑ counts match defined rules (overlap documented) · ☑ actions functional & authorised (correction endpoint untouched; export same gate as page) · ☑ worker detail opens correct profile · ☑ no destructive ops exist on this page (immutability) · ☑ responsive preserves time/worker/type · ☑ empty states (no punches / no corrections / no matches) · ☑ keyboard/labels (`/`, tabs, aria-sort, form labels) · ☑ house conventions · ☑ tests pass with failures disclosed · ☑ no functionality removed · ☐ CI DB/E2E on PR (pending, outside sandbox).

**Sample verification tasks:** (1) Supervisor: "show me today's corrections and why" → Corrections tab. (2) Manager: export the day for payroll → CSV equals filtered screen. (3) Supervisor: correct a missed punch-out → correction form (reason required) → new CORRECTION event appears, original untouched.
