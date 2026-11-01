# Weekly W36 report: `Synthese` action-plan table redesign

Companion note to `weekly-w36-table-audit.md`. Scope: the **Workshop action plan** panel of
`application/views/reports/weekly_w36.php` — the on-screen reading of sheet `W44 Synthese`
(the name follows the week: `$report_name . ' Synthese'`). No model, controller, Excel writer,
route or business rule was changed.

## 1. Goal

Bring the action plan up to the same design philosophy the Workshop Weekly report
(`application/views/reports/workshop_weekly.php`) and the restyled main W36 grid already use:
summary counters first, a screen-only filter bar, collapsible category summary rows whose
values stay aligned with the machine columns, sticky headers inside a labelled scrollable
region, and cues that never rely on colour alone. The sheet's data contract is untouched:
every value still comes from the machine's own work order via `W36_report_model::action_plan()` (BR-25).

## 2. Before → after

| Piece | Before | After |
|---|---|---|
| Header | `h3` + paragraph | same heading + the workshop-weekly style KPI strip: machines on the plan, below target, on hold, machine days down — each counted from the very rows printed |
| 12 flat columns (CATEGORIE … STATUS) | every machine row repeated its category | 8 columns: one **Equipment** cell (machine ID, model · location, status chip), Meter, **Availability bar** + Target aligned across rows, wrapped Comment and Action-plan cells, Days-down badge, Hold_code chip. Category is carried by its summary row, as in the main grid |
| Group rows | prose `colspan=12` line, always open | chevron toggle (`aria-expanded`), small "N machines · X available · Y down", group availability bar and target in the machine columns; collapsed by default, Expand/Collapse all in the toolbar |
| Days down | red bold number, or `—` | red `n d` badge with a tooltip, colour + unit text; `—` still means none |
| Search attribute | leaked the main grid's stale `$m` (every row carried the same, last machine's text; nothing consumed it) | each row carries its own ID, model, location, category, status, hold code, WO number, comment and action text |
| Filtering | none | screen-only bar: search (also matches comment/action text), location, Below target only, **On hold only**, aria-live "N of M machines shown" + no-match state. Filters never touch counters, print or the Excel download |
| Action provenance | invisible | `Work order WO-…` caption under the action text when a work order backs the row |
| Hold-code legend | top paragraph | below the table, next to the colour-rule note |
| Print / PDF | all rows, old styles | all rows expanded server-side (`data-open=1`, no `hidden`), no toolbar, compact KPI strip, static headers; group/target values still aligned |

## 3. Markup and behaviour contract

- Namespace `w36-syn-*`; reuses the existing `w36-group-toggle`, `w36-status`, `w36-hold`,
  `w36-avbar`, `miss` components and the restyled grid's palette; the toolbar reuses
  `.w36-table-tools` and stays `hidden` until JS confirms it is usable (progressive enhancement).
- Group ids are `w36-syn-group-<sha1(code)[0:9]>`; machine rows point at them with
  `data-parent`; `data-below` uses the same BR-W17 comparison as the grid (`availability < target`),
  `data-hold` any hold code.
- The script is a second IIFE inside the existing final `<script>` block (screen mode only),
  same delegated `data-w36-action` pattern: `syn-toggle-group`, `syn-expand`, `syn-collapse`.
- Excel keeps its own twelve-column sheet layout; the preview header says so explicitly.

## 4. Verification

- `node tests/weekly_w36_table_ux.js` — extended: simulated-DOM checks for the plan filter
  (search incl. comment text, below-target, on-hold, location, expand/collapse, no-match state)
  and source assertions for the new hooks. Passed, together with the pre-existing grid checks.
- `node tests/weekly_w36_export_ux.js`, `node tests/page_titles.js` — passed.
- PHP syntax checked (`php -l` via php-wasm) — clean.
- Rendered end-to-end with a representative W44 payload (3 machines, 2 groups, hold codes,
  an unapproved site, null comments/actions) through php-wasm: counters read 3 / 2 / 2 / 6;
  rows, group toggles, WO captions, badges present; print-mode render shows every row expanded
  with the toolbar removed and compact KPI cards.
- NOT VERIFIED: real browser/device rendering, screen readers, and large-fleet performance.
  The preview uses sample data only; no production data or schema was touched.
