# Weekly W36 report: `Synthese` per-type weekly block redesign

Companion note to `weekly-w36-table-audit.md`, `weekly-w36-action-plan-table.md` and
`weekly-w36-registers-table.md`. Scope: the **per-type weekly block** of
`application/views/reports/weekly_w36.php` — columns `BQ:CB` of sheet `<report_name>`
(e.g. the `W44` sheet). No model, controller, Excel writer, route or business rule changed.

## 1. Goal

The action plan and the two registers were rebuilt in the Workshop Weekly
(`reports/workshop_weekly.php`) design language; the per-type block between them was still a
bare sheet dump: twelve headers copied verbatim from the workbook (typos included —
*Fleet Availible Nb*, *NUMBER OF FAILLURE Nb*, *AVAIBILITY OPERATIONAL %*), the group label
buried in the third column, no caption, no scroll label, no availability bar, no target
comparison, no total row, and a `wk` cell class whose only effect was a blue header tint.

## 2. Before → after

| Piece | Before | After |
|---|---|---|
| Container | bare `.w36-tblwrap` after the availability grid | a `.w36-panel` with a heading naming the week and `columns BQ:CB`, plus a legend explaining what the block is |
| Row header | `APPLICATION` third column | the equipment type leads the row (`<th scope="row">`), with its machine count beneath and a **“below target”** note when its availability misses the weekly target |
| Headers | the sheet's own strings, typos and abbreviations | plain words with the unit attached (`Open time h`, `Preventive h`, `MTBF h` …); the sheet's exact twelve headers are still listed, in order, in the panel footnote so the workbook mapping stays auditable |
| Availability | a bare percentage in the eleventh column | the shared `$availability_bar` (track + fill + value, toned against the target) followed by the target column |
| Target comparison | none | `$misses()` (BR-W17) drives the bar tone **and** the “below target” note — colour is never the only cue |
| Downtime | `TOTAL DOWN TIME` number | the same figure, red-badged when above zero, and a plain `0.00` when it is genuinely zero (a dash would read as “no data” in a figures column) |
| Totals | none — the reader added twelve columns by eye | a computed **Fleet** row: counts and hours are the sums of the rows above; MTBF, MTTR and availability are the model's own fleet roll-up, and the footnote says so (averaging the type rows would not be the same figure) |
| Structure | none | caption, `scope="col"`/`scope="row"`, sticky header, labelled focusable scroll region (`role="region"`, `aria-label`), print stylesheet parity |
| Dead CSS | `table.w36 thead th.wk` tint, `$block_cols` array | both removed |

## 3. Data contract

Exactly the twelve `BQ:CB` figures, in the sheet's order, computed by the same model:

- `per_type[$code]['available'|'down']` — the fleet counts;
- `$week['open_hours']` — the week's open hours per machine (the same for every type, as the
  sheet repeats it);
- `groups[].rollup` — `preventive_hours`, `required_time_hours`, `downtime_hours`, `breakdowns`,
  `mtbf_hours`, `mttr_hours`, `availability`, `weekly_target`;
- `fleet.rollup` for the fleet row, which the model already proves reconciles with the machine
  rows (the D-W3 invariant).

Nothing was added, renamed in the data, or recomputed for display — only re-presented. The
Excel sheet keeps its `BQ:CB` layout and its own spellings.

## 4. Verification

- `node tests/weekly_w36_table_ux.js` — extended with guards on this panel: the table id, the
  labelled scroll region, the type as row header, the computed fleet row, `$availability_bar` and
  `$misses` use, the in-words below-target note, `scope` attributes, and two negative guards —
  the misspelled sheet headers may appear **only once** each (in the traceability footnote, never
  as live column headers), and `$block_cols` / `class="wk"` / `th.wk` must be gone. Passes.
- Twelve further node suites, `php tests/run.php` (345 assertions), `php tests/backlog_workflow.php`
  (97) — all pass. `php -l` clean, `git diff --check` clean.
- Rendered end-to-end with a representative W44 payload (two types, one below target and one
  above, a machine with real downtime and one with none): screen and print both show two type rows
  plus the fleet row, three availability bars, two “below target” notes (the excavator type and the
  fleet), the sheet-header mapping and a printed `0.00`.
- NOT VERIFIED: real browser/device rendering, screen readers, and large-fleet performance. The
  preview uses sample data only; no production data or schema was touched.
