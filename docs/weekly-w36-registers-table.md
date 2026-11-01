# Weekly W36 report: `Synthese` inspection & planned-PM registers redesign

Companion note to `weekly-w36-table-audit.md` and `weekly-w36-action-plan-table.md`. Scope: the
**Weekly inspection report & weekly planned PM services** panel of
`application/views/reports/weekly_w36.php` — the two registers of sheet
`<report_name> Synthese` (e.g. `W44 Synthese`). No model, controller, Excel writer, route or
business rule changed.

## 1. Goal

The action plan next to it was rebuilt in the Workshop Weekly (`reports/workshop_weekly.php`)
design language; these two registers were still the old flat table — seven columns, two of them
both headed `STATUS`, no counters, no collapse, and a group row that was a single `colspan`
sentence. This brings them to the same discipline: summary counters first, a screen-only filter
bar, collapsible category rows whose values stay aligned with the machine columns, sticky
headers in a labelled scroll region, and cues that never rely on colour alone.

## 2. Before → after

| Piece | Before | After |
|---|---|---|
| Counters | a separate nine-tile strip below the table | four summary cards above it — machines inspected, not inspected, PM services due, PM done ratio — **counted from the rows printed** (BR-27). Every one of the nine old figures still appears, once, as a headline or in a card's sub-line (the sheet's *actions planified* = machines + PM services is the fourth card's sub-line) |
| Headers | `CATEGORIE / MACHINE ID / INSPECTION COMMENTS / STATUS / TYPE / STATUS / PM COMMENTS` — two columns called STATUS | `Equipment / Inspection / Inspection comments / Planned PM services / Down h` |
| Inspection | status chip and date in different places | one cell: status chip (`w36-insp`) above “Inspected ‹date›” or “No inspection on file this week” |
| PM services | three parallel comma lists (`TYPE`, `STATUS`, `PM COMMENTS`) whose pairing was lost | one line per service: interval, its own status chip, the reason the report computed, and — when the meter judged it — the value judged and the age of the reading behind it (G4-stale) |
| Group rows | `<tr><td colspan=7>` prose line, always open | chevron toggle (`aria-expanded`), “N machines · X inspected · Y not inspected”, the group's inspection tally, its service tally and total downtime; collapsed by default, Expand/Collapse all in the toolbar |
| Search attribute | read the **stale `$m`** of the main grid loop, so every row carried the last machine's text (nothing consumed it) | each row carries its own ID, category, inspection status and date, comments, service intervals, statuses and reasons |
| Filtering | none | screen-only: search (also matches comments, intervals and reasons), **Not inspected only**, **PM not done only**, aria-live “N of M machines shown”, no-match state. Never touches counters, print or the Excel download |
| Legend | none | foot note stating the inspection precedence (BREAKDOWN ▸ DONE ▸ UNDONE) and that a service's reason is computed, never typed |
| Print / PDF | all rows, old styles | all rows expanded server-side (`data-open=1`, no `hidden`), no toolbar, compact cards, static headers |

## 3. Data contract

Every value still comes from `W36_report_model::synthese_register()` — nothing is added,
reordered or recomputed for display:

- `inspection.status` (BREAKDOWN ▸ DONE ▸ UNDONE precedence, decided in the model), `inspected_on`,
  `comments[]` (rendered with `nl2br(esc())`, an em-dash when empty);
- `pm.services[].type` (the database's own interval label), `.status`, `.comment`, plus the
  evidence already exposed for G4-stale: `.due_by`, `.meter_value`, `.meter_reading_on`;
- `hours_down`, printed as a red `n h` badge only when > 0, `—` otherwise (a dash means none).

The Excel sheet keeps its seven-column layout; the panel's own legend says so.

## 4. Markup and behaviour contract

- Namespace `w36-reg-*` for ids and hooks; the Synthese panels now share the `w36-syn-*` /
  `w36-reg-*` CSS rules through grouped selectors, and reuses `w36-group-toggle`, `w36-insp`,
  `w36-status`, `w36-days`, `w36-avbar`'s palette and `.w36-table-tools`.
- Group ids are `w36-reg-group-<sha1(code)[0:10]>`; machine rows point at them with `data-parent`;
  `data-insp` carries the lower-cased status `done|undone|breakdown` — the “not inspected” filter
  is “anything that is not `done`”, so a machine that is down counts as outstanding;
  `data-pm="1"` when at least one service is not DONE.
- The script is a third IIFE inside the existing final `<script>` block (screen mode only), same
  delegated `data-w36-action` pattern: `reg-toggle-group`, `reg-expand`, `reg-collapse`. A filter
  opens any category that has a match, so a search never hides its own result.
- The toolbar stays `hidden` until JS confirms it is usable (progressive enhancement): without
  JS every row prints, which is the correct degraded reading.

## 5. Verification

- `node tests/weekly_w36_table_ux.js` — extended with a third simulated-DOM context for the
  registers: toolbar reveal, collapsed start, category toggle, search by service interval,
  “not inspected” (BREAKDOWN counted as outstanding), “PM not done”, no-match state,
  expand/collapse all, plus source guards (including a check that the panel no longer reads the
  main grid's `$m`). Passed, together with the pre-existing grid and action-plan checks.
- `node tests/weekly_w36_export_ux.js`, `node tests/page_titles.js` and twelve further node
  suites; `php tests/run.php` (345 assertions) and `php tests/backlog_workflow.php` (97) — all passed.
- PHP syntax checked (`php -l`) — clean; the view's inline script parses as JS.
- Rendered end-to-end with a representative W44 payload (3 machines, 2 groups, one DONE / one
  BREAKDOWN / one UNDONE inspection, a meter-judged service with a stale reading, a service with
  no reading on file, a date-judged service, an unapproved site) through php-wasm:
  cards read 1 inspected / 2 not inspected (1 UNDONE, 1 BREAKDOWN) / 4 services due (2 done) /
  50.00 %; screen mode starts collapsed with the toolbar hidden until JS; print mode renders every
  row expanded with `data-open="1"` and no toolbar.
- NOT VERIFIED: real browser/device rendering, screen readers, and large-fleet performance. The
  preview uses sample data only; no production data or schema was touched.
