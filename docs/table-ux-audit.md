# Suppliers, Lubricant Consumption & Lube Budget — table UX audit

**Scope:** local source audit and implementation on the existing branch, 22 October 2026. No production access or data changes. **Evidence level:** code inspection, Node regression checks and PHP *parser* only; no PHP executable, MySQL, authenticated browser session or live records in this workspace. “Implemented” below means changed in source, not proven in production.

## 1. Executive summary

The dark cards were a **visual defect**: all three pages used `var(--card, #111827)` although the rendered light layout (`MY_Controller::render()` → `layouts/header.php`) defines `--surface: #ffffff` and no `--card`. The fallback made KPI tiles, toolbar/tabs, record cards and inspectors navy. These now inherit `--surface`, with semantic badges following shared color tokens. Two **functional/data-presentation defects** also warranted correction: grouped views displayed *all* matching rows while the pager counted only one page; and lubricant cards treated negative OEM variance differently from the table. Budget cost is an *estimate*, not booked spending: the database stores issued quantity but no historical issue price. Labels now say so in these views. Other unresolved risks are documented below; in particular, full-collection in-memory list processing and legacy export headings still need separate decisions.

## 2. Table purpose & workflow

| Target | Purpose / users / primary task | Identity / relations / statuses / actions |
|---|---|---|
| Supplier Directory (`/suppliers`) | Purchasing/store/supervisors locate a vendor, inspect contact, catalog, lead time and PO history, open profile or export. | `suppliers.id`, company name; `supplier_contacts`, `supplier_materials`, `spare_orders`, fleet via work orders. Actual status enum: Active, Inactive, Blacklisted (`database/schema.sql:730`). Inspector endpoint `Suppliers::summary_json`; profile and edit pages. Write actions gated by `can_write()` (roles 1,2,3); other roles can inspect within module access rules. |
| Consumption Ledger (`/lubricants`) | Store/supervisors trace issued fluid to equipment, OEM baseline and work order; managers may review. | `lube_consumption.id`, date, equipment registration, lubricant type; `equipment`, `lube_types`, `work_orders`, users, OEM specifications. “Over variance”, “linked WO”, “no WO” are overlapping **segments**, not persisted statuses. Inspector endpoint `Lubricants::summary_json`; record entry for roles 1/3. |
| Monthly Lube Budget (`/lubricants/budget`) | Supervisor/store plan monthly quantity × unit rate, compare PM requirement and recorded issues, save one month's allocations/export. | `(year, month, lube_type_id)` business key; `lube_budget`, types, PM requirement, monthly consumption. No status or row-level action in schema. Endpoint `Lubricants::budget` GET/POST and budget exports, all restricted to roles 1/3. |

**Actual stack:** CodeIgniter 3, PHP templates and inline CSS/JS, MySQL, shared light design tokens; no frontend framework. Supplier and ledger `get_*_overview()` load the month's/all suppliers into PHP, then controller filters, sorts, counts, slices; neither is server-side SQL pagination. Supplied data volume, device mix, latency targets, live permissions and real-world data quality are **not verified**.

## 3. Existing implementation findings

- `application/views/layouts/header.php` supplies white `--surface`, accessible blue/green/amber/red tokens; `header-apple.php` is not the rendered layout. The page-local navy `--card` fallback was not themed (F-01).
- Both list controllers implement query search, sort, segment counts, filters, 10/25/50/100/150 page sizes, XLSX/CSV and URL-encoded filters. Segment counts are from the whole directory or selected month **before** search/advanced filters, while the result count is after filters. This is now disclosed. Group calculations formerly happened before slicing; fixed (F-02).
- Cards/table/group views and async inspectors already existed. Drawer focus and fetch race handling were incomplete (F-03). Status segment navigation was incorrectly marked up as keyboard-managed ARIA tabs; these are navigation links, now `aria-current`.
- Budget was already an editable matrix, not a searchable record list. Its inputs allowed editing even in fallback read-only rendering, its zero budget was displayed as missing, empty type lists had a blank table and unsaved changes updated planned total but not issue valuation/remaining/utilization (F-04).
- `Lubricant_model::get_budget_overview()` calculates issue value as issue quantity × *selected month budget rate*, falling back to type rate. `get_ledger_overview()` uses the same valuation principle; `lube_consumption` has no recorded issue price. Historic periods therefore cannot claim “actual spend” (F-05). The model also sums quantity across fluid units (F-10).
- `Lubricants::budget()` previously coerced malformed and negative numeric POST values to zero and wrote line by line without a transaction. Now validates all recognized rows first and commits/rolls back as one unit (F-06). Form loss on server rejection remains.

## 4. UX audit matrix (10 principles)

Codes: **P** PASS by source evidence, **~** PARTIAL, **F** FAIL, **NA** not applicable, **NV** not verified at runtime. Status reflects *current source after this change*, not a verified production release.

| Principle | Suppliers | Consumption | Budget | Evidence / remaining qualification |
|---|---|---|---|---|
| 1. Segment tabs | ~ | ~ | NA | List controllers compute overlapping segments before search; explicit scope hint added. Budget has no status workflow. Counts not checked against DB fixtures. |
| 2. Toolbar | ~ | ~ | P | GET controls, exports and authorized add/record are present; month/year and exports suit budget. List search needs server-side scale and full integration verification. |
| 3. Table | ~ | ~ | ~ | Dense column hierarchy, numeric columns aligned right, adaptive matrix; shared surface/badges fixed. Some values still truncate and need real-data inspection. |
| 4. Cards/groups | ~ | ~ | NA | Lists offer cards and grouping, now page-consistent and mobile-exclusive. Budget matrix needs comparison and editing, not a separate card toggle. |
| 5. Detail inspection | ~ | ~ | NA | Lists fetch record-specific JSON; focus return/trap and stale response guard added. Budget edits inline by month; separate record inspector adds no value. |
| 6. Row actions | ~ | ~ | NA | Suppliers have Inspect/Profile, ledger Inspect, budget edits inline. Avoid irrelevant menus; inspect privilege checks in staging. |
| 7. Semantic color | ~ | ~ | ~ | Shared `--surface`/badge tokens used; light-browser contrast not measured and some legacy colors remain in surrounding modules. |
| 8. Pagination | ~ | ~ | NA | Lists slice and display range; grouped results now slice first. This is application-memory pagination; budget type matrix is not paginated. |
| 9. Responsive | ~ | ~ | ~ | List cards replace dense tables ≤900px; grouping takes precedence. Budget rows become a 2-column labelled grid; device test needed. |
| 10. Feedback/a11y | ~ | ~ | ~ | Esc/Tab/focus, empty states and budget unsaved calculations improved. No full browser/AT audit; server-error draft restoration missing. |

## 5. Enterprise-grade findings

| Requirement | Suppliers | Consumption | Budget | Evidence and limitation |
|---|---|---|---|---|
| A. Data accuracy | ~ | ~ | ~ | Schema/model fields reviewed; estimates clarified; budget saved zero distinguished from unset. Need live total/valuation reconciliation and historical-rate decision. |
| B. Functional integrity | ~ | ~ | ~ | Controller export uses same list dataset; URL view state and group range aligned. Budget validates/transactions. End-to-end write/export/navigation not verified. |
| C. Performance | ~ | ~ | ~ | Overview queries batch joins/aggregation but materialize all records and sort/filter in PHP. Dataset size and latency unavailable; database-based pagination is a future design decision. |
| D. Consistency | ~ | ~ | ~ | Page surfaces now shared; period terminology fixed in on-screen UI. CSV/XLSX budget headings still say “Actual MTD” and are a separate compatibility decision. |
| E. Security | ~ | ~ | ~ | `MY_Controller` authenticates/enforces module access, mutations check roles, config enables CSRF, `form_open` used for budget. Authorization not penetration-tested; never infer permissions from hidden buttons. |

## 6. Prioritized defect & improvement register

Each entry includes status, reproduction/evidence, impact, severity, recommendation/acceptance, risks and verification.

| ID / type / status | Current behavior & evidence / reproduction | Impact / severity | Recommendation & acceptance criteria | Dependencies / verification |
|---|---|---|---|---|
| **F-01 Visual — implemented, runtime NV** | `--card` undefined in rendered `header.php`; page CSS fell back to `#111827`/`#0f172a`. | Inconsistent black tiles and unreadable light-text status badges. **High** | All three pages use `--surface`, shared semantic badge tokens. No undefined `--card` fallback; readable on white at desktop/mobile. | Browser screenshot + contrast audit in authenticated light layout. `node tests/table_ux.js` checks source. |
| **F-02 Functional — implemented, runtime NV** | `Suppliers::_build_suppliers_dataset` and `Lubricants::_build_ledger_dataset` grouped `$filtered` before slicing `$rows`; select “By Country/Machine” on page 2. | Pager says page of 25 while group view shows all matches. **High** | Group `$rows` and preserve view in URL across paging; group subtotal refers only to page. | Validate >25-row seeded dataset, group totals/page range/prev-next. Distinct groups can span pages, by design. |
| **F-03 A11y/functional — implemented, runtime NV** | Inspectors declared dialogs but did not focus/trap, and older async responses could overwrite a newer selection. | Keyboard escape / incorrect record inspection. **High** | Focus close control on open, loop Tab within inspector, restore trigger on close, ignore stale responses. | Keyboard and throttled-network browser exercise with multiple records; ensure footer links remain reachable. |
| **F-04 Data UX — implemented, runtime NV** | Budget updated one KPI while remaining/utilization stayed stale; saved zero displayed `—`; no-type table empty. | Incorrect draft decision or zero mistaken as missing. **Medium** | Recompute issue valuation, line costs and dependent KPIs on input, label unsaved, show zero for saved rows, explain empty state; no submit on empty list. | Node calculator regression; real POST, refresh and browser viewport check. |
| **F-05 Data meaning — partly implemented** | `Lubricant_model` multiplies monthly quantity by current budget/type rate, yet page called value “actual spend”/“MTD” for any selected month. | Finance user could read estimate as booked expenditure. **High** | On-screen label “Estimated Issue Value” and “Period Issues”; retain model behavior. CSV/XLSX headings remain pending compatibility approval. | Confirm cost accounting intent with finance; reconcile issue sample, rates and reports. Changing valuation requires new persisted source, **not** a display-only fix. |
| **F-06 Integrity — implemented, runtime NV** | `Lubricants::budget()` coerced invalid/negative POST to zero and performed individual replaces. | Accidental zeroing / partial monthly budget. **High** | Prevalidate finite nonnegative decimal(10,2)-bounded inputs and computed totals, then transactional replace and failure feedback. | PHP/MySQL integration tests with bad input, DB error rollback and successful month POST; error redirect currently drops draft (risk). |
| **F-07 Scale — open** | `get_directory_overview()` loads suppliers, contacts, quotes, POs; `get_ledger_overview()` loads whole selected month; controllers sort/filter in memory. | Increasing memory and latency at scale. **Medium**, volume unknown | Benchmark representative data; if needed, SQL-filter + indexed count/page while keeping group/count/export semantics. | Data volumes, indexing plan, reconciled counts, performance baselines; do not change query strategy without DB tests. |
| **F-08 Data export — open** | Budget CSV/XLSX (`Lubricants::budget_csv/xlsx`) still label estimated valuation “Actual MTD”; exports are consumed externally. | Misinterpretation / contract change risk. **Medium** | Agree versioned headings/metadata with consumers, preserve columns/order until approved. | Downstream export consumers; test both file formats on authentic data. |
| **F-09 Error recovery — open** | Budget validation redirects on invalid input, discarding draft. No tested loading/error/permission-denied UI across all three pages. | Re-entry cost / recovery uncertainty. **Medium** | Return old draft values with field-level errors using existing flash/CSRF conventions; improve error states only when endpoint behavior is confirmed. | PHP/CI integration test + keyboard/AT check. |
| **F-10 Mixed units — partly implemented** | `Lubricant_model::get_ledger_overview()` sums `quantity_used` over all fluid types without checking `lube_types.unit`; aggregate can mix litres and other units. | Apparent volume could be meaningless. **Medium**, actual unit mix unverified | Removed mixed-unit number from grouped machine header; keep per-row units. KPI still shows overall quantity; agree unit-specific summaries or suppress aggregate after checking real type units. | Inspect type catalog and reconcile grouped monthly amounts; do not convert between units without business rule. |

## 7. Recommended structure & interaction specification

- **Supplier directory:** retain checkbox, company/TIN, country/city, currency/terms, primary contact, quoted lead days, catalog count, PO count, status, Inspect/Profile. Company is the primary identifier; numeric counts/right aligned or centered consistently. Default company ascending; sort only implemented fields. Tabs All, Active, Catalog Quoted, With PO History, Inactive/Blacklisted (segments overlap). Counts represent the full directory; result range reflects all active filters. Search existing company/tax/contact/catalog/orders; country/currency/status filters; 10/25/50/100/150; CSV/XLSX as supported. Inspector lists existing contacts/catalog/POs and actions gated by `can_write()`; profile is the deeper page.
- **Consumption ledger:** retain checkbox, issue date, registration, lubricant/grade, dispensed amount, OEM standard, variance, valued cost, linked work order, Inspect. Default date descending with ID tie break. Tabs All, Over-Spec, Linked to WO, Engine & Hydraulic, No WO Link (not a persisted status). Counts are selected month before other filters; search existing equipment/fluid/WO/recorder; filter equipment/type/month; same page-size/export pattern. Inspector displays existing OEM variance, issue details and links, not an invented workflow state.
- **Budget:** keep single month/year matrix, not status tabs, sorting, pagination or cards: edit quantity and rate beside PM demand, calculated cost and period issues; footer monthly total, save transaction, CSV/XLSX. Identifiers are fluid name+grade and month. All numbers align right; currency from `wms_site_currency()`. Zero is distinct from missing allocation; estimated value explicitly not accounting spend. Read-only users cannot enter this route by current role policy. Existing month picker/exports preserved.
- **Behavior:** lists keep URL search/filters/sort/page/view; switching view retains the page. Drawer navigation is non-mutating; destructive changes remain on existing dedicated pages with server authorization. Budget POST is CSRF-protected by existing CI config and `form_open`, prevalidated and atomic. Do not add imaginary status-change menus, approvals or history.

## 8. Responsive & visual specification

Use `--surface`, `--sep`, `--t1`–`--t3`, `--blue`, `--green-d`/`--amber-d`/`--red-d` consistently with the light shared layout. Subtle bordered 12px tiles, compact 10–14px typography, right-aligned amounts, neutral row dividers, blue selected control and visible blue keyboard outline. At ≤900px list rows become single-column cards; optional country/machine grouping stays exclusive and lists all **current-page** records. Budget becomes a labelled 2-column input matrix instead of hiding decision data. Above that width table wrappers scroll when constrained rather than clipping critical fields. Empty list/budget states are explanatory; async drawers show loading/error. Recheck color contrast, text wrapping, touch target size, browser zoom and reduced-motion in staging; these are **not verified** here.

## 9. Before → after (no business-rule changes unless noted)

| Before | After | Why / benefit / verification |
|---|---|---|
| Dark navy fallback cards in white application | White shared surface across three pages and light-appropriate status tokens | Visual only; consistent scan/readability. Compare authenticated screenshots. |
| Group view ignored page, view toggle was transient | Group only current-page rows; switch navigates with view query retained | Functional list navigation; no changed records. Test page 2 of >25. |
| Drawer had no focus management or response ordering | Focus trap/restore and stale-response guard | Safer record inspection; keyboard/network simulation. |
| Negative variance shown green in cards; null called “Top-Up” | Amber negative, blue “No OEM Std”, “No WO Link” segment | Accurate interpretation; compare table/card for same row. |
| Remaining/utilization stale after draft edit; bad POST could overwrite budget | Recalculated draft issue valuation and unsaved KPIs; numeric prevalidation and transaction | Better planning/data integrity, no new field/schema; run PHP/MySQL write tests. |
| “Actual spend/MTD” for rate-based estimate in selected period | “Estimated Issue Value” / “Period Issues” on screens | Prevent overclaim; underlying math unchanged. Exports remain unresolved. |

## 10. Implementation plan & dependencies

**Done in source:** surface/badge mapping, list group/view/mobile behavior, inspector keyboard/async behavior, budget draft/empty/zero feedback and validated atomic POST, a Node regression check. **Next:** run PHP test runner and PHP lint on a PHP-enabled host; staging seed multi-page vendor and month issue data; permission matrix across roles; compare export/filter results; then benchmark with representative volumes. **Decision needed:** whether to rename export headings (breaking downstream parsers) and whether finance requires historically recorded cost. No database migrations or production data changes were made.

## 11. Updated implementation

Changed `application/views/suppliers/index.php`, `application/views/lubricants/index.php`, `application/views/lubricants/budget.php`, `application/controllers/Suppliers.php`, `application/controllers/Lubricants.php`; added `tests/table_ux.js`. Existing routes, status vocabulary, columns, database schema and endpoint signatures retained. Suppliers controller changes only reorder grouping relative to pagination; lubricant controller also validates and transactionally writes budget lines.

## 12. Test results / evidence

- `node tests/table_ux.js`: **PASS**, checks all three surfaces, paged grouping and mobile selectors, URL view state, read-only/empty budget markup and executes actual inline budget JS with sample draft values (30.00 planned → 10.00 balance; rate change → 15.00 planned, 10.00 estimated issued and 5.00 balance).
- `git diff --check`: **PASS**.
- All five changed PHP files: **parsed successfully** using `php-parser` 3.2.5 (JavaScript parser); this is **not native PHP lint/execution**.
- `php -l ...` / `php tests/run.php`: **NOT RUN**, `php` executable unavailable (`command -v php` empty). No DB service/session/real browser; query semantics, redirects, transactions, styling and permissions remain **unverified at runtime**. Run `php tests/run.php`, `php -l` on modified PHP files, and authenticated desktop/mobile regression in staging.

## 13. Risks, decisions & acceptance checklist

| Criterion | Status / evidence / resolution |
|---|---|
| Correct list values, counts and combined search/filter/sort/page/export | **Not verified** with real DB; controllers inspected and F-02 fixed. Seed data and reconcile selected-month/directory counts and file exports. |
| Actions, permissions, correct drawer records, failure feedback | **Partially implemented / not verified**; server guards and CSRF inspected, focus/race fixed. Test roles 1–4, inaccessible routes, slow/failing JSON and budget DB failure. |
| Visual surface and mobile information hierarchy | **Implemented in source / not browser verified**; screenshot at 320/768/1440px, 200% zoom, check contrasts and navigation. |
| Budget zero, unsaved calculations, valid/invalid writes | **Node checks passed for calculator; DB unverified**. Verify invalid request leaves all persisted rows untouched, valid POST saves each, flash feedback and readback. |
| Performance / historical accounting / export labels / mixed units | **Open** (F-05/F-07/F-08/F-10); establish volume/latency targets and finance/export-consumer decisions before changing behavior. |
| Existing functionality and integrations | **Not verified** without PHP and MySQL runtime; use existing `tests/run.php` and module regression walkthrough before release. |

Acceptance is **not complete** until the not-verified items above are exercised in an authenticated, database-backed staging environment. No measured productivity/performance claim is made.
