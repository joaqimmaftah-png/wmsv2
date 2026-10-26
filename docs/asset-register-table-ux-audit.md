# Reports / Asset Register — Data Table UX Audit & Implementation

**Scope:** `/reports/asset_register` and the register data assembled by `Asset_model::get_register()`.
**Audit mode:** Local source review plus implementation. No production data, schema, route, or API contract was changed.
**Verification limit:** PHP and a running database/browser are unavailable in this workspace. Client-side JavaScript syntax and structural regression checks are run; interactive browser behavior remains to be confirmed in CI/live UI.

## 1. Executive summary

The Asset Register is a supervisor/manager fleet report and data-exchange surface, not the primary equipment-maintenance workflow. It combines asset identity and technical details with lifetime work-order aggregates, availability, warranty, and next preventive-maintenance information. Supervisors can add/import; supervisors and managers can export/print and follow records into the equipment profile.

Before this change, the report rendered one wide table per equipment type, with no search, filter, sorting, or pagination. The tables required horizontal scrolling and the notes appeared in a separate table. The report already had role-gated add/import, full Excel export, a template, fleet KPI cards, and shared equipment status mapping.

The implementation consolidates the data into one register and adds status filters/counts, type filtering, search, sortable columns, page-size controls, client-side pagination, and a responsive card view. The existing full-register export, import/template, print, KPIs, and profile navigation remain. An off-by-one warranty-expiry KPI count is corrected. No new packages or schema changes were introduced.

## 2. Table purpose and workflow

- **Purpose:** Review/search fleet master data and maintenance-related indicators; import or export the register; navigate to a full equipment profile.
- **Users:** Supervisor (role 1) and Manager (role 4), due to the Reports controller access matrix. Creation/import remain supervisor-only. The equipment profile is already available to roles 1, 2, and 4.
- **Common tasks:** Find a Machine ID/type/serial/location; review status, meter readings, work-order history, downtime, warranty, and upcoming PM; export the full workbook; import a validated workbook as a supervisor; open an equipment profile.
- **Primary identifier:** `registration_no` / Machine ID.
- **Important immediate fields:** Machine ID, equipment type, brand/model, location, status, hours/KM, WOs/breakdowns/downtime, availability, warranty, next PM.
- **Statuses:** Canonical equipment enum from `equip_statuses()`: Available, In Maintenance, Breakdown, Standby, Retired. Status meaning/style reuses `equip_status_map()`; visible labels remain present in badges.
- **Data/relationships:** `equipment` + `equipment_types`; aggregates over `work_orders`; active schedules from `pm_schedules`. The model returns all matching rows and grouped data. README documents a maximum of 2,000 rows per import file; total fleet size is not capped here.
- **Available actions:** Add Register and Import Excel (supervisor UI and server guard), Import Template and full Excel export, Print, and the new per-row View profile link. There is no report-level edit/delete/status action; edits remain in the Equipment module.
- **Stack/conventions:** PHP/CodeIgniter server-rendered view, shared WMS styles/status helpers, dependency-free inline JavaScript. The module already has a richer Equipment Fleet table/drawer, but the Reports register includes different lifetime/export data and remains a distinct report.
- **Loading/error/empty/permission states:** Server-rendered page (no asynchronous table load). Existing import success/error summary remains. This implementation adds a no-assets state and a no-filter-results state. Controller-level role denial remains in force. Database/global error behavior was not re-tested here.

## 3. Existing implementation evidence

- `Reports::asset_register()` calls `Asset_model::get_register()`, supplies equipment types/import summary/history, and renders this view.
- `Asset_model::get_register()` selects equipment/type fields and enriches rows with work-order aggregates and the earliest active PM schedule. Excel export uses the same model.
- The previous view grouped by type, displayed a fixed 16-column table with a minimum width, had no filtering/paging/sorting, and rendered notes in a separate final table. Because the grouped loop reused `$assets`, that notes table only examined the final equipment-type group.
- `Dashboard_model::get_asset_register()` is a backwards-compatible delegate, so this work leaves that API/data contract intact.
- `MY_Controller::ACCESS_MATRIX` permits Reports for roles 1 and 4. `Reports::import_asset_register()` separately checks `is_supervisor()`. The add and import controls were already hidden from managers; those restrictions are preserved.
- The old warranty KPI counted `warranty_days < 0`, while the row model labels `warranty_days <= 0` as Expired. The KPI therefore excluded the expiry day itself; the count now uses `<= 0`.

## 4. UX audit matrix (before → implemented state)

| Principle | Before | Finding / impact | Implemented state | Status |
|---|---|---|---|---|
| 1. Status-based filtering | No tabs or status filters. | High task cost to isolate down, available, or retired units. | All + canonical status tabs with full-fleet counts; search/type criteria remain in force when switching tabs. Counts explicitly describe the full fleet, not the filtered subset. | PARTIAL — source and structural checks confirm wiring; browser behavior not verified. |
| 2. Smart toolbar | No search, filters, or page size. | Users must visually scan a long grouped report. | Search, equipment type filter, compact status controls, 10/25/50/100/All page sizes (25 desktop default; 10 on narrow screens), and Table/Cards controls. Existing import/export/print actions remain above. | PARTIAL — browser interaction and data-volume behavior not verified. |
| 3. Professional table | 16 columns, grouped blocks, fixed width, no sort controls; mixed center/text alignment. | Slow cross-type comparisons and unnecessary horizontal scanning. | One sortable table, Machine ID first, type visible, technical and maintenance fields retained, numeric values right-aligned, tabular numerals, shared status badges, restrained separators and sticky headers. | PARTIAL — static/source review only; rendered visual QA pending. |
| 4. Card/grid view | None; mobile depended on horizontal scrolling. | Hard to inspect one asset on a phone. | Cards are generated from the actual record row, include the record fields and profile link, and are selected by default at narrow widths. | PARTIAL — card construction is present and JS parses; responsive browser testing pending. |
| 5. Record inspection | No row action; identifier was plain text. | No direct route from report row to detailed record. | Machine ID and View profile link use the existing `equipment/view/{id}` route. A separate detail drawer is not duplicated; the Equipment profile is the established detail page. | PARTIAL — route is existing and generated in view; click-through not live-tested. |
| 6. Contextual row actions | No row actions. | No direct access to the record detail. | One safe, relevant View profile action. No report-level mutation or destructive action is added. Add/import permissions are unchanged and server-checked. | PASS for permission-preserving source changes; live authorization test not run. |
| 7. Semantic status color | Status color was derived from shared `equip_status_map()` but emitted as inline color/background and text. | Color vocabulary reused, but presentation differed from standard status badges. | Shared badge and LED classes are used with the status label, preserving semantics and redundant text. | PASS by source evidence; contrast not measured in a rendered browser. |
| 8. Pagination/navigation | Every row emitted, no page summary or page size. | Long output and no clear position in results. | Client-side 10/25/50/100/All pagination with filtered count, page summary, and disabled boundary controls; print temporarily shows all rows. | PARTIAL — client-side implementation present; large-fleet performance/runtime not measured. |
| 9. Responsive priority | Tables had `min-width:1100px`; only horizontal scroll. | Poor mobile prioritization and effort. | Responsive card mode prioritizes ID, type, brand/model/status then shows the other record fields; desktop/tablet retains the dense comparison table with horizontal overflow. | PARTIAL — source evidence only; phone/tablet testing pending. |
| 10. Accessibility/feedback | Limited controls, no search/filter empty state. | Keyboard/accessibility and no-results recovery were weak. | Native buttons/selects/search, names and pressed states, visible keyboard focus, result live region, empty/no-match messages, slash-to-focus search, reduced-motion handling. | PARTIAL — semantic/source review only; screen-reader/keyboard audit pending. |

## 5. Additional enterprise findings

| Area | Result | Evidence / notes |
|---|---|---|
| Data accuracy | PARTIAL | Status vocabulary is shared and status counts derive from the full model rows. Warranty expiry count now includes the expiration day, matching the row label. The availability calculation remains unchanged: `((current_hours - lifetime downtime) / current_hours) * 100`, with 100% when current hours is zero. The intended business definition/time window and behavior above 100% need owner confirmation; no metric was redefined. |
| Functional integrity | PARTIAL | Search indexes identification, location, status, meter readings, work-order/downtime/availability fields, warranty/PM values, and notes; status/type/search combine; sort and paging are implemented in the view. Automated live interaction and imported/exported workbook comparisons were not possible here. Excel export remains the unfiltered full-register export. |
| Performance/scalability | PARTIAL | Previous and current controller/model load the whole fleet and global work-order/PM aggregates. Client-side paging improves navigation but does not reduce response/query/initial table DOM size. Card DOM is generated only when card mode is requested. Existing imports allow up to 2,000 rows per upload, not a total fleet ceiling. If total fleet grows substantially beyond a few thousand rows, server-side filtering/counting/pagination should be designed separately. |
| Shared design consistency | PASS by source review | Reuses global `tbl`, `card`, `btn`, `form-select`, WMS design tokens, `equip_status_map()` badges and LEDs; no dependency or competing palette added. |
| Security/permissions | PASS by source review | No new endpoint or mutation. View escapes dynamic cell, query, status, and URL values. Existing Reports role gate and supervisor-only import/add paths remain. Profile links point to the existing role-protected Equipment module. Backend authorization remains authoritative. |
| Empty/error/loading | PARTIAL | New no-assets and no-match recovery states; import result/errors remain. No asynchronous loading state is needed for this server-rendered view. Global/database runtime errors and permission-denied pages were not exercised. |

## 6. Prioritized register

| ID | Finding | Severity | Resolution / acceptance criteria | Remaining risk |
|---|---|---|---|---|
| AR-01 | No search, status/type filtering, sorting, or pagination. | High | Implemented controls operate on actual server-rendered records; verify combinations and counts in browser. | Browser-level functional test pending. |
| AR-02 | Wide table unusable on mobile without horizontal scanning. | High | Implemented table/cards switch with mobile card default; verify at 390px and tablet widths. | Responsive visual QA pending. |
| AR-03 | Per-type group tables make cross-type comparison difficult; notes table only looked at the final type group. | Medium | Consolidated table retains a Type column; all notes are now present/searchable in their owning record/card. | Cross-type behavior requires populated live data verification. |
| AR-04 | Warranty-expired KPI excluded warranty ending today. | Medium | Corrected `< 0` to `<= 0`, matching `warranty_status` classification. | PHP regression suite unavailable locally. |
| AR-05 | “Active Fleet” counted every non-Retired asset, including Breakdown and In Maintenance. | Medium | Label changed to “Non-retired Fleet” without changing its count/formula. | Confirm business prefers this non-retired definition. |
| AR-06 | Availability formula/time window is not self-evident and zero-hour assets show 100%. | High | Not changed; document/confirm the metric definition before altering. | Requires maintenance/reporting owner decision and historical data validation. |
| AR-07 | Entire fleet remains loaded/rendered, so client pagination does not protect against very large datasets. | Medium | Documented; card DOM is lazy-created. Server-side pagination is a future scale step if measured fleet size warrants it. | No total fleet-size telemetry or production benchmark available. |

## 7. Implemented table and interaction specification

- **Column order:** Machine ID, Type, Brand / model, Year, Serial no., Engine, Location, Status, Hours, KM, WOs, Breakdowns, Down hrs, Availability, Warranty, Next PM, Notes, Actions.
- **Primary/secondary:** Machine ID is the strong clickable identifier. Type, brand/model, location, status, and readings support identification/triage. History/warranty/PM data is available in the dense table and card view. Detailed equipment fields remain on the existing profile page.
- **Default ordering:** Machine ID ascending. Clickable headings sort text, number, and ISO date values; repeated click reverses direction.
- **Filters:** All plus the canonical equipment statuses; counts are full-fleet counts. Search and type filter are combined with the status selection. Search supports the displayed/business-identification fields and notes.
- **Pagination:** Client-side 10, 25, 50, 100, or All; filtered result count and page navigation. Pagination resets to the first page after a filter/sort change.
- **View:** Dense sortable table for comparisons; responsive Cards for record-focused/mobile review. Switching view retains filter/page/sort state. Cards link to the equipment profile.
- **Actions/permissions:** Existing Add Register/Import Excel remain supervisor-only. Template/export/print remain available under Reports access. Per-row View profile is read-only; no new status/edit/delete action. Import server guard and controller access rules are untouched.
- **Print:** Print stylesheet removes toolbar/actions and temporarily shows the complete register, not only the currently visible page.
- **Empty states:** “No assets are registered yet” for a genuinely empty fleet; “No assets match these filters” with Clear filters for zero results.

## 8. Responsive and visual specification

- Desktop: one dense comparison table with sticky header, horizontal overflow only when the viewport cannot fit all technical/report fields; tabular numeric alignment; Machine ID first; shared status badge and LED plus text.
- Narrow screens: Card view selected automatically at ≤700px; one/two-column key-value field grid, readable touch controls, no tiny text; manual Table/Cards switch remains available.
- Toolbar wraps at small widths. Status controls scroll horizontally instead of overflowing the page. Cards are lazily constructed only if the card view is selected.
- Focus is visible; native controls remain keyboard operable; no action depends on color alone. Reduced-motion preference is respected.
- Colors reuse existing tokens/status classes. Availability colors retain the prior 90%/70% thresholds; they were not reinterpreted as equipment status.

## 9. Before/after comparison

| Area | Before | After | Business behavior change? | Verification |
|---|---|---|---|---|
| Navigation | Separate table per equipment type, no search or paging. | Single searchable table with status/type filters, sorting, and pagination. | No data/API change; display navigation only. | Structural Node assertions passed; interactive browser test pending. |
| Mobile | Wide table required horizontal scrolling. | Card view exposes the same record values and profile link. | No business-rule change. | JS syntax check passed; responsive browser test pending. |
| Notes | Separate notes appendix scoped accidentally to last type table. | Notes accompany their asset, are searchable, and appear in cards. | Data remains unchanged; display corrected. | Static source review; multi-type live data test pending. |
| Warranty KPI | Warranty expiring today was not counted as expired. | Count aligns with model status (`<= 0`). | Corrects a displayed count; no stored data change. | Static assertion added; PHP test suite unavailable locally. |
| Fleet KPI label | “Active Fleet” included all non-Retired statuses. | “Non-retired Fleet” accurately describes same set. | Label only; count unchanged. | Static source review. |
| Print/export/import | Full Excel export and print existed; import/template supervisor-gated. | All remain; print explicitly includes all rows despite pagination. | No endpoint or permission change. | Source check; live workbook/print tests pending. |

## 10. Implementation plan and dependencies

The implementation preserves the existing server-rendered controller/model contract and uses the already loaded equipment types/status helpers. No frontend package, endpoint, database migration, or design-system dependency is needed. Follow-up work is limited to running the project's PHP CI/live HTTP checks and deciding the availability KPI definition. If measured fleets grow materially, the next planned step is server-side search/count/sort/pagination rather than expanding the client-side table.

## 11. Updated code / actual implementation

Changed files:
- `application/views/reports/asset_register.php` — unified table, toolbar, filters, sorting, client-side pagination, responsive/lazy card view, empty states, print behavior, and row profile links.
- `application/models/Asset_model.php` — warranty-expiry count boundary corrected to include expiry day.
- `tests/table_ux.js` — regression assertions for real fields, controls, filtering/sorting/pagination, profile links, preserved export/import, supervisor gating, and warranty count.
- `docs/asset-register-table-ux-audit.md` — audit evidence, specification, comparison, and remaining acceptance checklist.

No production data or schema changes. Existing Equipment profile is reused as the detail page; no duplicate drawer is introduced.

## 12. Test and verification evidence

- `node tests/table_ux.js` — **passed**.
- Extracted new inline Asset Register JavaScript `node --check` — **passed**.
- Synthetic DOM interaction smoke test (temporary jsdom fixture) — **passed** for status filtering, text search, page-size/next-page behavior, numeric sorting, and card creation/filtering. This is not a real-browser responsive/accessibility test.
- `git diff --check` — **passed**.
- `php-parser` AST parse for the modified PHP view/model — **passed** (syntax parser, not the PHP runtime).
- PHP lint / `php tests/run.php` — **not run**: PHP runtime is not installed in this workspace.
- Live HTTP/database/browser checks — **not run**: no local PHP/MySQL/browser runtime was available. CI and authenticated live UI verification remain required before calling every interaction verified.

## 13. Outstanding risks and acceptance checklist

- [x] Existing Excel import/export/template and print entry points retained in source.
- [x] Existing supervisor-only add/import and Reports role access left unchanged.
- [x] Existing canonical statuses, identifiers, operational fields, and record profile route retained.
- [x] No schema or production-data changes.
- [x] Regression assertions and JavaScript syntax checks passed.
- [ ] Run PHP lint and `php tests/run.php` in PHP 8.2–8.4 CI.
- [ ] Verify status counts/filter/search combinations against representative records, including zero-results and an empty fleet.
- [ ] Verify sort directions, page boundaries, page-size changes, and state preservation across Table/Cards.
- [ ] Verify mobile card layout at 390px and desktop/table layout at 1440px; check contrast and keyboard focus.
- [ ] Verify profile links for supervisor/manager, add/import controls for manager/supervisor, and export/import/print in authenticated browser tests.
- [ ] Confirm the availability metric definition with the reporting owner before changing its formula.
- [ ] Reassess server-side pagination if total fleet size or render/query time grows materially.
