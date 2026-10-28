# Weekly W36 report: table UX audit and implementation

## 1. Executive summary

The target is a weekly fleet availability report, not a transactional record list. It compares a fleet, equipment groups and machines against daily and weekly targets in the same order as the approved workbook. The implementation retains that comparison and the frozen-publication contract. Source inspection identified a misleading fleet count under “Down”, a date picker that could be overridden by the week field, and a published/live distinction that was lost when requesting a live view. Local changes address these and add a machine finder without changing aggregates or exports. No production data or schema was changed. This is a source-based audit; PHP, a configured database and an authenticated browser were unavailable, so acceptance is not yet established.

## 2. Purpose and workflow

Supervisors and managers (the Reports access matrix) select a week, inspect machine/day availability, breakdowns, targets and data quality, export the workbook or print, and publish or re-publish a frozen report. The machine identifier is the key displayed identifier. Machine rows relate to equipment, sites, work orders, targets and report groups; detailed daily figures and breakdown events are expandable. The model also builds PM, inspection, action-plan, per-type and chart summaries. Actual dataset size, growth, user research, timezone configuration and mobile operating conditions were not verified. The model materializes the entire report; no server-side table paging is implemented.

## 3. Existing implementation evidence

* CodeIgniter PHP view `application/views/reports/weekly_w36.php`, `Reports::weekly_w36`, `export_weekly_w36_xlsx`, `export_weekly_w36_pdf`, `publish_weekly_w36`, and `W36_report_model::build` / `report_for_week` supply the report. The model delegates calculations to KPI helpers, targets and settings. The Excel writer consumes the report array; print renders the same view.
* `MY_Controller::ACCESS_MATRIX` restricts reports to roles 1 and 4; publish and rename additionally check supervisor/manager, POST and CSRF. This is source evidence, not a penetration test.
* Published snapshots are intended to be frozen; explicit `live` builds again. Quality notes distinguish error, warning and information; these are not machine workflow statuses. Inline machine details contain the current status and breakdown events. The report includes no record-level edit/delete endpoint.
* Missing numbers use an em dash; percentages use two decimal places. Group/fleet values are model rollups, not sums of visible rows. Daily and weekly target misses were signaled in red/bold. A no-work-order warning and quality list exist. Errors are handled at controller/framework level; no asynchronous loading state is needed for this server-rendered page.

## 4. UX audit matrix (before changes)

| ID | Principle | Classification | Evidence and impact |
|---|---|---|---|
| U1 | Status tabs | NOT APPLICABLE | This is a comparative worksheet, not a status queue; tabs could obscure groups and distort interpretation of rollups. |
| U2 | Toolbar | FAIL | Week and date shared a GET form; controller prioritizes nonempty `week`, so choosing a date could show the old week. No machine finder. See R1/R4. |
| U3 | Table | FAIL | Fleet machine count was placed in the Down column, falsely implying all reportable machines were down. Wide sheet ordering and numeric alignment otherwise support comparison. See R2. |
| U4 | Cards | NOT APPLICABLE | Daily comparisons and worksheet order are central; cards would fragment the report. |
| U5 | Inspection | PARTIAL | Native details expose daily figures and breakdown events for grouped machines; unclassified rows do not have equivalent detail. See R7. |
| U6 | Row actions | NOT APPLICABLE | No supported machine-level mutations in this report; native expansion is the relevant interaction. |
| U7 | Semantic color | PARTIAL | Quality labels include text; target misses in the grid relied on red/bold. See R5. |
| U8 | Pagination | NOT APPLICABLE | Pagination would split the sheet and its group context. Full report size/performance remain unverified (R8). |
| U9 | Responsive design | PARTIAL | Horizontal overflow preserves all columns, but there was no explicit scroll hint or keyboard-focusable region. See R6. |
| U10 | Accessibility and feedback | PARTIAL | Native details and labels exist, but keyboard, screen-reader, touch and reduced-motion behavior were not tested; see R5/R6/R9. |

## 5. Enterprise findings

* **Data accuracy: FAIL before changes.** R2 is a misleading presentation, not a change to the calculation. Full numerical correctness and workbook parity require real data and are NOT VERIFIED.
* **Functional integrity: FAIL before changes.** R1 and R3 affect which week or reading users see/export. Date and live-view changes are implemented but require browser and integration verification. Search is intentionally screen-only.
* **Performance/scalability: NOT VERIFIED.** Entire report and all rows are built/rendered before filtering. No production volumes, SQL plans, timings or load tests were available. Do not claim a speed improvement.
* **Consistency: PARTIAL.** Existing report classes, colors and native controls were reused. Sheet-style terminology persists in other report sections; no application-wide design-system audit was performed.
* **Security/permissions: PARTIAL.** Source shows controller-level access and backend authorization for publish/rename plus CSRF fields. Runtime denial, CSRF rejection, direct endpoint authorization and sensitive-data exposure have not been tested. UI hiding is not the security boundary.

## 6. Prioritized defect and improvement register

Each entry specifies evidence, impact, severity, recommendation, acceptance, dependency/risk and verification. The status is implementation status, not a claim of runtime acceptance.

* **R1 — Date selection (High, functional; implemented locally).** Evidence: the form submitted both `week` and `week_start`; controller resolves `week` first. Impact: wrong week displayed. Recommendation: separate GET forms, retaining controller precedence and route contract. Acceptance: selecting any valid date opens its containing week, and ISO selection still works. Risk: week settings may differ from ISO; test actual configuration. Verify both paths in an authenticated browser, including boundaries and invalid dates.
* **R2 — Fleet count (High, data presentation; implemented locally).** Evidence: `machine_total` was rendered beneath `DOWN`. Impact: total fleet size was easily read as down count. Recommendation: label total in the fleet description, leave no number under Down. Acceptance: fleet label and total are unambiguous and weekly values remain in their original columns. Risk: print uses the same view. Verify screen and print with known available/down fleet.
* **R3 — Live vs published reading (High, data integrity; implemented locally).** Evidence: `report_for_week` looked up snapshot metadata only inside the non-live branch; export links did not specify live, yet the page claimed the same data. Impact: a live preview could appear unpublished and downloads could silently use frozen numbers. Recommendation: retain publication metadata during live builds, label Excel as frozen and route Print/PDF to live when showing a published week's live view; preserve the Excel endpoint's frozen contract. Acceptance: frozen and live views clearly identify themselves, Excel remains frozen, print from live requests live, re-publish remains authorized. Risk: metadata lookup now runs for live builds; live data can change between requests. Verify with a published week whose underlying data has changed and exercise re-publication without production data.
* **R4 — Find machine (Medium, usability; implemented locally).** Evidence: all rows rendered without a finder. Impact: locating a machine in a wide grouped report requires scanning. Recommendation: client-side ID/model/location finder, count visible machine rows, keep detail rows paired and retain unfiltered group/fleet totals. Acceptance: case-insensitive match, reset and no-match feedback work; unclassified machines participate; totals and exports explicitly remain full report. Risk: client filtering is not a scaling solution, and group headers remain even with no visible machines. Verify browser with grouped/unclassified machines, no matches and JS disabled; automated simulated-DOM check added.
* **R5 — Target misses (Medium, accessibility; implemented locally for main machine grid).** Evidence: red/bold alone conveyed misses. Impact: users who cannot distinguish color may miss the comparison. Recommendation: add textual “below target” for daily and weekly machine cells. Acceptance: misses have text and color, null targets do not show a false miss. Risk: wider rows; detail and chart color semantics need further review. Verify with target above, equal to, below and absent, including screen reader and print.
* **R6 — Wide grid navigation (Medium, responsive/accessibility; implemented locally).** Evidence: overflow region had no explicit keyboard focus or instruction. Impact: wide comparison is difficult to discover or operate on smaller screens. Recommendation: labeled focusable scroll region, caption, hint and focus outline. Acceptance: all columns and actions reachable with keyboard and touch at narrow widths. Risk: long report remains wide by design. Verify desktop/tablet/mobile and keyboard in real browsers.
* **R7 — Unclassified detail (Medium, functional; open).** Evidence: unclassified rows have no expandable daily detail, unlike grouped machines. Impact: harder to investigate missing classification. Recommendation: reuse the same detail rendering for unclassified rows after checking data contract. Acceptance: each reportable machine has equivalent relevant daily/events inspection without duplication. Risk: avoid altering workbook/group rules; requires representative data. Verify against a real unclassified record.
* **R8 — Full dataset performance (Medium, performance; open).** Evidence: model builds full nested report and view renders all rows. Impact: unknown on large fleets. Recommendation: measure first, retain whole-report rollups; consider server-side solutions only if warranted. Acceptance: agreed load-time and fleet-size targets are met without changing totals. Dependency: representative volumes and business thresholds. Verify profiling and SQL plans.
* **R9 — Publish safety and states (Medium, workflow; open).** Evidence: publish and force re-publish are POST forms and server role checks exist, but no explicit confirmation on the page; server renders the result and flashes feedback. Impact: accidental replacement of a freeze is possible; runtime errors/denials remain unverified. Recommendation: agree on confirmation and consequences before modifying workflow; verify all flash/error states. Acceptance: authorized actions, CSRF, error handling, and consequences are clear and tested. Risk: an unsolicited confirmation or changed publication policy could affect workflow. Verify role/CSRF matrix and failed/successful POSTs in a non-production environment.

## 7. Structure and interaction specification

Keep fleet, group and machine rows in approved group order, followed by seven day availability/target columns and existing weekly metrics: days down, open time, preventive time, required time, downtime, failures, MTBF, MTTR, operational availability, target. Text is left aligned, values right aligned, dates follow existing report settings, percentages retain two decimals, and missing values remain distinct from zero. No free sorting or column hiding: either can break sheet/group context. No currency applies to this grid.

Keep ISO-week navigation, separate date-in-week selection, existing export and authorized publication controls. Search matches the rendered machine ID, model and location; count means visible machine rows out of rendered machine rows, not aggregate counts. Search never changes the report, quality notes, rollups, or exports. No invented status filters, page size, cards or record actions. Keep expandable daily blocks/events; resolving the unclassified detail inconsistency is R7. Existing controls continue to use backend authorization, CSRF and errors; no change to business validation.

## 8. Responsive and visual specification

Retain the dense neutral bordered surface and report's existing primary accent and semantic colors. Keep full data available through a named, focusable horizontal scroll region, with a visible hint and caption; do not hide operational columns. Wrap the finder and toolbar, preserve readable text, provide visible focus and native details keyboard behavior. Grid misses have text as well as color. Real viewport, touch target, contrast and assistive-technology checks remain outstanding. Avoid motion and card layouts for this comparison-heavy report.

## 9. Before and after

| Change | Before → after | Benefit / business effect | Verification |
|---|---|---|---|
| Date picker | Both dates submitted → independent forms | Correct intended selection; no calculation change | Browser boundary tests, R1 |
| Fleet count | Under Down → labeled reportable total | Avoids misleading down count; no value change | Compare known fleet screen/print, R2 |
| Published/live | Live metadata lost and download description misleading → preserved metadata, explicit Excel/print reading | Reduces risk of using wrong version; Excel remains frozen | Published/live integration tests, R3 |
| Finder | Scan all rows → matching machine rows and paired details | Easier navigation; full report and exports unchanged | Node simulated DOM plus browser, R4 |
| Target and scroll cues | Color-only misses and implicit scrolling → text cues, caption, focusable region | Better access; no metric changes | Accessibility/viewport tests, R5–R6 |

## 10. Implementation plan and dependencies

Local changes are in the existing view and model; no dependencies, schema changes or new APIs. Added a Node regression check. Next: run PHP suite and lint, seed a non-production environment with representative published/live, classified/unclassified and missing-target data, then perform browser, authorization, export/print, accessibility and performance checks. Address R7–R9 only after confirming requirements and testing risk.

## 11. Actual implementation

See `application/views/reports/weekly_w36.php`, `application/models/W36_report_model.php` and `tests/weekly_w36_table_ux.js`. Snapshot metadata lookup is now also performed for explicit live reads. The Excel endpoint still deliberately returns published data when available. Print/live output is a separate build and can differ if underlying data changes between requests.

## 12. Test results and evidence

* `node tests/weekly_w36_table_ux.js` — passed: simulated finder filtering, paired detail and no-match/reset behavior, plus source assertions for the picker, fleet label and live link/metadata wiring. This is not a browser or PHP integration test.
* `node tests/weekly_w36_export_ux.js` — passed existing source-based export guards.
* `node tests/page_titles.js` — passed for 87 rendered-view source checks.
* `git diff --check` — passed.
* PHP lint and `php tests/run.php` — NOT RUN: PHP executable unavailable. No authenticated server/database/browser or actual export/print execution was available.

## 13. Risks, decisions and acceptance checklist

| Acceptance criterion | Status and resolution |
|---|---|
| Correct values and record/column hierarchy | NOT VERIFIED — compare seeded model output, screen and workbook, especially fleet and unclassified data (R2/R7). |
| Search/filter/sort/pagination interaction | PARTIAL — finder simulated; sorting/pagination intentionally not offered, search does not filter rollups or exports. Browser test required. |
| Status counts | N/A — no status tabs; search count is visible machine rows. Verify count with real groups. |
| Actions, permissions, CSRF and success/failure | NOT VERIFIED — source checks exist; exercise authorized and denied requests, failure and re-publish (R9). |
| Record details | PARTIAL — grouped machine details exist; unclassified lacks equivalent detail (R7). Verify right record/events. |
| Responsive, loading, empty, errors, denied | PARTIAL — server rendered, so no client loading state; existing quality and error/redirect paths require real tests; no-match feedback simulated. Test zero-machine and denied states. |
| Keyboard, focus, labels and contrast | NOT VERIFIED — test native details, scroll region, controls and target cues with assistive technology and actual devices. |
| Shared conventions, integrations and regression tests | PARTIAL — local classes and existing routes preserved; Node checks passed, PHP and end-to-end suites pending. Verify workbook, print, snapshot, reports hub and related modules. |
| Performance and data volume | NOT VERIFIED — establish realistic fleet size, thresholds and profiling (R8). |

No production authorization was assumed. Business decisions still needed for publish confirmation and any changes to unclassified record inspection or performance architecture.
