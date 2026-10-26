# Service Kit Recommendations — Report Audit, Specification & Verification

**Scope:** `Reports::service_kits()`, `Dashboard_model::get_service_kit_recommendations()`, `reports/service_kits.php`, and the Service Kit XLSX export.

**Change boundary:** No database schema, production data, route, endpoint contract, permission rule, or business-status vocabulary was changed. The existing equipment profile route and report/export endpoints are reused. The implementation stays in CodeIgniter/PHP, the existing helper/CSS conventions, and dependency-free inline JavaScript.

**Verification boundary:** Source review and dependency-free Node checks were run. This workspace has Node but no PHP executable, database, or authenticated browser; PHP lint, PHP tests, workbook opening, and live interaction checks remain outstanding.

## 1. Executive summary

The Service Kit report is a **per-machine maintenance planning/review surface**. Its value is the detail bundle for each machine: active PM schedules and next due information alongside frequently used historical spare parts, lubricant consumption, and work-order oil records. It is not an inventory availability/reorder report, a maintenance work-order action screen, or a time-bounded period report.

The original implementation correctly retained rich per-machine detail and the existing all-history inclusion rules, but it had no way to find or narrow a machine within a type. Its recommendation model executed history reads inside the equipment loop (N+1 query growth). The screen displayed oil rows but the workbook omitted them. The zero-kit KPI expression could call `array_merge()` with no arguments on PHP 8+, and costs were marked with a hard-coded dollar sign despite the application having a site-currency helper.

The implementation now batches the existing history reads, keeps the same equipment inclusion rule and per-equipment top-15 parts cap, makes the cards searchable/filterable/paginated, exposes existing equipment status and profile context, handles the zero-kit view/export paths, uses configured currency, and exports the same three record families shown on screen. Status counts are clearly scoped to all kits in the selected type and are not search-dependent. Entry counts are identified as stored rows, not unique work orders.

## 2. Report job, data and existing workflow

### Job and users

- **Report job (inferred from the actual report contents):** help a maintenance planner/supervisor review each machine’s PM context and recorded consumable history when considering service-kit needs.
- **Primary record:** one non-retired machine with at least one active PM schedule, eligible historical spare-part row, lubricant-consumption row, or eligible work-order oil row.
- **Primary identifier:** equipment `registration_no` (shown as the machine ID); the machine’s database ID is carried for a link to the existing equipment profile.
- **Default scope:** all stored history selected by the source model; no date window is applied. The report title/subtitle now says “all recorded work-order history.”
- **Audience/permission fact:** the controller delegates to the existing `Reports` render/access path. No role or access check is changed here. The machine profile link targets the already-used `equipment/view/{id}` route, whose own controller remains the authority for access.

### Data and business logic evidence

`Dashboard_model::get_service_kit_recommendations()` selects non-retired equipment and then gathers:

1. Active rows from `pm_schedules`.
2. `wo_spare_parts` joined to `work_orders`, restricted to `spare_type = 'New'`, grouped by equipment, part name and part number; a per-equipment SQL window rank retains the top 15 groups in the existing order (`times_used DESC`, then `total_qty DESC`). Table names use the configured database prefix; the `IN` list is built only from integer-cast equipment IDs.
3. `lube_consumption` joined to `lube_types`, grouped by equipment and the existing lube attributes.
4. `wo_oil_records` joined to `work_orders`, with non-NULL `oil_type`, grouped by equipment, oil type, grade and system/location.

A machine is retained if any one of those data families is non-empty. `COUNT(*)` in the aggregates counts matching stored rows. It does **not** prove that the count is a count of distinct work orders; for example, a work order can contain multiple lines. This distinction is now stated in the UI/export. The per-machine top-15 parts cap is applied in the grouped SQL using `ROW_NUMBER() OVER (PARTITION BY equipment_id ...)`, supported by the project database baseline (MySQL 8+ / MariaDB 10.4+), so extra grouped part rows are not transferred to PHP.

The spare-part schema exposes `unit_cost` but no currency column. The report therefore formats that existing cost using `wms_site_currency()` / `wms_money()`; this communicates the application’s configured site currency and does not invent a currency field.

### Existing controls/routes retained

- `Reports::service_kits()` calls the same recommendation model and renders `reports/service_kits`.
- `Reports::export_service_kits_xlsx()` calls the same recommendation model and exports the full result, across types.
- The print control remains browser print; it is now labelled “Print visible page” because client-side pagination hides off-page cards.
- The export remains explicitly all-types and is not affected by the screen’s client-side filters.
- Per-machine navigation uses the pre-existing `equipment/view/{id}` destination. No edit, delete, status-change, reorder, stock, or work-order action was added.

## 3. Ten-principle audit

The following task-centred dimensions assess whether this specific report helps users make a correct service-kit review without confusing historical usage with current stock or unique work orders.

| # | Principle | Before | Finding / consequence | Implemented state | Result |
|---|---|---|---|---|---|
| 1 | **Purpose is apparent** | Title was clear, but the summary phrase did not explicitly distinguish all recorded history from a recent period. | Users could infer a recent-window report. | Subtitle states “all recorded work-order history”; entries remain separate for parts, lubricants and work-order oils. | Improved; wording should be confirmed with the report owner if a business-specific definition of “history” exists. |
| 2 | **Trust the source and scope** | Screen showed parts, lube rows and oil rows; Excel omitted oil rows. Time scope was implicit. | Screen and download could disagree; history could be mistaken for stock-on-hand. | Excel now includes all three displayed record families; no current inventory/availability claim is made; export title and UI indicate historical scope. | Implemented in source; workbook comparison needs a real workbook test. |
| 3 | **Preserve useful per-machine context** | Detailed machine cards already put schedule, operating hours, parts, lubricants and oils together. | This is the right display shape for a kit-review task; reducing it to a fleet-only aggregate would hide item context. | Rich per-machine cards and item-level tables are retained; machine profile and status context are added. | Preserved. |
| 4 | **Find a relevant machine/item quickly** | Only equipment-type tabs existed; no search. | Users had to scan every card in the selected type. | Search covers machine ID, model, location, observed status, PM/schedule names, parts, lubricants and oils. Matching state is reflected in the URL. | Implemented in source; browser search test pending. |
| 5 | **Narrow scope with explicit controls** | Type tabs only. | No status narrowing or manageable page lengths. | Existing equipment type tabs retain search/status/page-size state; status controls use actual statuses present in the selected type; page size supports 10/25/50/100/All. | Implemented in source; combined-filter/browser test pending. |
| 6 | **Keep counts meaningful** | Unique-name KPIs were fragile at zero kits; entry counters used “times”/“used” labels. | Zero data could error; “times” can be misread as unique work orders. | KPI flattening is zero-safe; labels identify unique names shown; detail/export counts say “entries/rows” and disclose they are not unique work orders. | Implemented in source; PHP runtime test pending. |
| 7 | **Keep statuses and identity consistent** | Status was not surfaced as a clear card badge/profile shortcut. | Harder to triage or reach the canonical record. | Shared `equip_status_map()` badge/LED classes and textual labels are used; machine ID links to existing equipment profile. No new status taxonomy or profile route. | Implemented in source; live link/access test pending. |
| 8 | **Support operational scanning** | N+1 history reads scaled with every machine; no result summary or page navigation. | Large fleet report did avoidably repeated database reads and offered no position in results. | History is read in batches keyed by equipment ID. Client pagination reports matching machines and the current page; status counts stay fixed for the selected type and do not change with search. | Model structure and JS parsing checked; production query plan/volume and browser behavior unmeasured. |
| 9 | **Be accurate and avoid invented claims/actions** | `$` was hard-coded; historical row counts and unique work orders were not distinguished. | Currency could be wrong and a row count could be over-interpreted. | Uses configured site currency, `COUNT(*)` is labelled as stored rows, no stock-on-hand metric/action is introduced, and existing recommendation rules remain. | Implemented in source; site-setting/live data verification pending. |
| 10 | **Support empty, keyboard and responsive use** | Zero-kit KPI could fail; no search empty state or keyboard shortcut existed. | Empty/filtered views lacked recovery and keyboard support. | Zero-kit view and workbook states are defined; no-match panel has clear action; native search/select/buttons, pressed states, live result summary, `/` search focus and Escape reset are present. Toolbar/cards wrap for smaller viewports. | Source/JS syntax checked only; keyboard, assistive technology and responsive visual QA remain outstanding. |

## 4. Enterprise and integrity review

| Area | Finding and decision |
|---|---|
| **Business logic / data contract** | Retains equipment eligibility, active schedule logic, historical join sources, New-part filter, grouping, sorting and top-15 per-machine parts cap. Adds `equipment_id` to each returned kit for the existing profile link; existing keys remain. No controller route or export endpoint changes. |
| **Data integrity** | No source write path is present in this report; no database schema or production data was changed. Counts remain `COUNT(*)` row aggregates; quantities/costs are sums/averages from the existing fields. |
| **Access/security** | Existing report/controller access gate and Equipment profile authorization remain untouched. Search/query values are scalar-checked, page size allow-listed, status checked against the selected type’s available values, and rendered text/attributes are escaped. UI filtering is not an authorization boundary. |
| **Currency** | No currency field exists on spare-part cost rows. The visible/exported cost code comes from the established site currency helper; no USD/TZS hard-code is introduced. |
| **Export completeness** | The workbook still contains all types and now includes work-order oil rows as well as spare parts and lubricant consumption. Each detail family is labelled separately. Empty data now produces an explanatory workbook sheet instead of a workbook with no sheets. |
| **Scale** | The model moves from per-equipment history queries to a fixed number of batched reads. Client-side filtering/pagination still renders all cards for the selected type in the DOM; server-side paging is a future scale option only if measured fleet size or rendering time warrants it. No benchmark or production row count was available. |
| **Failure/loading states** | The page and workbook receive synchronous model data; there is no asynchronous loading state to add. Empty report and no-match states are implemented. Database-failure behavior and permission-denied behavior were not exercised. |

## 5. Implemented interaction and presentation specification

- **Type navigation:** existing model groups remain the top-level type scope. Switching type carries the current search, status, and page-size URL state; page is carried too, then safely clamped to the destination group’s page range.
- **Search:** case-insensitive, immediate client-side filtering across each rendered kit’s existing identification and recommendation detail. Initial `q` is scalar-checked and capped at 200 characters. `/` focuses search outside text inputs; Escape clears current search/status filters.
- **Status filter:** “All” plus the established status values observed in the selected type. Counts are the full selected-type kit population, unaffected by search; they are not a count of visible results.
- **Page size/pagination:** 10/25/50/100/All. Changing search, status or page size resets to page one. Results show the current visible range, total matching machines and page number. Query state uses the existing page URL; no new backend endpoint is introduced.
- **Machine card:** machine ID, model, location, type, shared equipment status badge, profile link, hours, next PM/date, active PM schedule badges, spare-part table, lubricant-consumption table and work-order oil table. Existing card-per-machine format remains because it preserves the report’s nested data.
- **Empty states:** a zero-kit report explains that eligible history or active PM schedule data was not found; a non-empty report with no current filter matches presents a clear-filter action.
- **Export and print:** export remains full-scope/all-types and independent of client filters; it contains the same three item families as the screen. Print is explicitly the currently visible page, not the hidden paginated cards or all types.
- **Currency and count vocabulary:** cost header names the site currency. Parts, lubricant, and oil aggregate counts are stored-entry/row counts, not distinct work-order counts.

## 6. Prioritized findings and remaining decisions

| ID | Finding | Priority | Resolution / next acceptance |
|---|---|---|---|
| SK-01 | Recommendation model previously read history inside the equipment loop. | High | Batched reads implemented. Verify query counts/runtime with representative database volume. |
| SK-02 | Screen showed work-order oils that Excel left out. | High | Oil export implemented. Download and open a workbook containing parts-only, lube-only, oil-only and mixed machines. |
| SK-03 | No search/status filtering/page navigation within a type. | Medium | Client controls implemented. Exercise type + status + search + page-size combinations and URL state in browser. |
| SK-04 | Zero-kit KPI could invoke `array_merge()` with zero arguments. | Medium | Zero-safe aggregation and empty message implemented. Verify on supported PHP versions with an empty model result. |
| SK-05 | Cost display hard-coded `$`; schema has no cost-currency column. | Medium | Uses configured site currency helper and labels export column. Confirm intended site setting and verify both screen/workbook. |
| SK-06 | `COUNT(*)` was presented as times used. | Medium | UI and workbook label stored-row entry counts and explicitly distinguish them from unique work orders. Confirm wording is understood by planners. |
| SK-07 | All client cards still exist in DOM for the selected type. | Low / scale watch | Not a demonstrated defect at current scale; measure before proposing backend pagination. |
| SK-08 | No live authenticated, database or accessibility test was available. | High verification risk | Complete the checklist below before treating runtime behavior as fully verified. |

## 7. Files changed

- `application/models/Dashboard_model.php` — batched schedule/spare-part/lubricant/oil reads; preserved aggregation, ordering, grouping, eligibility and top-15 rules; returns equipment ID in each kit.
- `application/views/reports/service_kits.php` — safe zero-state KPI calculation; guarded controls; type/search/status/page-size/pagination toolbar; status/profile context; history/entry/currency language; empty/no-match states and responsive/keyboard interaction.
- `application/controllers/Reports.php` — Excel now includes work-order oil records, uses site-currency cost heading, labels entry counts, and handles empty exports. Endpoints/signatures unchanged.
- `tests/service_kits_ux.js` — dependency-free source and inline-JavaScript syntax assertions for model semantics, controls, empty handling, profile route, currency, and oil export.
- `tests/README.md` — documents how to run the new Service Kit structural regression check and its limits.
- `docs/service-kits-report-audit.md` — this evidence-based audit, specification and verification checklist.

## 8. Verification results

- `node tests/service_kits_ux.js` — **passed** (19 structural/source assertions and inline JavaScript parse check).
- Inline report JavaScript — **parsed** with Node after replacing PHP-rendered literals for syntax validation; this does not exercise the DOM or browser behavior.
- `node tests/page_titles.js`, `tests/global_find_attention.js`, `tests/table_ux.js`, `tests/reorder_ux.js`, and `tests/reports_hub.js` — **passed**.
- `node tests/lubricant_masters_ux.js` — **failed outside this change** at its line 31 assertion `catalog needs empty state` (untouched lubricant view/test files); this was not compared against a clean-baseline run.
- `git diff --check` — **passed**.
- `php-parser` 3.2.5 AST parse — **passed** for `Reports.php`, `Dashboard_model.php`, and `reports/service_kits.php`; this is a syntax-parser check, not native PHP lint/runtime execution.
- `php -l` for changed PHP files / `php tests/run.php` — **not run; PHP is not installed in this workspace**.
- Database query/result comparison, empty-database request, authenticated route/access tests, XLSX download/open/contents, real browser pagination/URL behavior, responsive visual QA, screen-reader/keyboard audit, and currency-setting validation — **not run**.

## 9. Acceptance checklist

- [x] Existing report and export routes/signatures preserved.
- [x] Existing model inclusion/filter/order/top-15 behavior retained in the batch implementation.
- [x] Existing equipment profile route reused; no new mutating action or permission added.
- [x] No schema or production-data changes.
- [x] Screen recommendation families and Excel recommendation families aligned (parts, lubricant records, work-order oil records).
- [x] Empty view/export and zero-safe KPI paths implemented.
- [x] Search, status, type, and page-size controls documented; entry count and status-count scope disclosed.
- [x] `node tests/service_kits_ux.js` passed.
- [ ] Run PHP lint and `php tests/run.php` in supported PHP CI/runtime.
- [ ] Compare old/new model output on representative fixtures/database rows, including no equipment, equipment with only schedules, only oils, and over 15 grouped parts.
- [ ] Verify search coverage, status counts, filter combinations, page boundary behavior, URL state and reset controls in a browser.
- [ ] Download/open XLSX for mixed and empty fixtures; verify currencies, section rows and stored-entry counts.
- [ ] Verify profile link access under report-access roles and ensure report/export access rules are unchanged.
- [ ] Check keyboard, focus, screen-reader announcements, responsive card/grid layout and print output in authenticated browser QA.
- [ ] Confirm with reporting owner that all-time historical scope and site-currency treatment are the intended business interpretation.
