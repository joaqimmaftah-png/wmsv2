# Lubricant planning tables — deep UX, data and implementation audit

**Targets:** Lube Budget, OEM Specifications, Lube Types and PM Forecast. **Scope:** local source + targeted implementation on `arena/a89514f0-wmsv2`, 22 October 2026. No production data/schema changed. The earlier [ledger/supplier/budget audit](table-ux-audit.md) remains applicable; this document concentrates on the four requested lubricant pages. **Evidence limits:** code, schema and existing E2E tests inspected; Node checks and PHP parsing run locally; no PHP binary, local DB, authenticated browser or real production volumes are available. Code changes below are **not** a claim of production verification.

## 1. Executive summary

Three sibling pages still used an undefined `--card` CSS token with a navy fallback even though `MY_Controller::render()` loads the **light** `layouts/header.php` (`--surface: #fff`). The subnavigation is now consistent across all four pages. The more important audit findings were **data integrity** (malformed/negative spec values silently became zero, invalid type rates/categories/units could be coerced), **interpretation** (forecast is date-only although the budget PM requirement also projects hour-based services; totals of L/Kg/Pcs are not meaningful), **navigation** (budget year selector could not display older supported periods), and **forecast date rollover** (month advancement from the 29th–31st could skip a month). Safe changes are implemented without new endpoints or schema; remaining valuation/export and data-volume questions need business confirmation and staging verification.

## 2. Purpose, users and workflow (existing behavior, not invented)

| Page | Purpose / identity / primary user | Data, dependencies and actual actions |
|---|---|---|
| `/lubricants/budget` | Supervisor (role 1) or Store (3) allocates monthly quantity and unit rate per active fluid grade, compares dated/hour-based PM demand against issues. Key is `(lube_type_id, year, month)`. | `lube_budget`, `lube_types`, `lube_consumption`, PM schedules and OEM specs. `Lubricants::budget()` loads month and accepts CSRF-protected POST; `budget_csv/xlsx()` export. `unique_budget` enforces key. No approval/status workflow. |
| `/lubricants/specifications` | Supervisor/Store maintains recommended fluid quantity by **equipment type + lubricant type**. This affects subsequent forecasts and new issue snapshots, not previous issues. | `lube_specifications`, `equipment_types`, `lube_types`; `(equipment_type_id, lube_type_id)` unique index. `Lubricants::specifications()` upserts on POST, lists joined specs; no separate delete endpoint. Technician (2) sees read-only table. |
| `/lubricants/types` | Supervisor creates an active fluid grade with category, unit (L/Kg/Pcs), default unit cost and description. Store/Technician browse the active catalog. | `lube_types` has status enum Active/Discontinued; `get_all_types()` returns **only Active**. Existing controller implements create only; no edit/archive/restore action. Budget, specs, ledger and forecast reference these types. |
| `/lubricants/forecast` | Supervisor, Store or Technician monitors anticipated requirements for **current + next calendar month** by grade and month; no editing. | `get_monthly_forecast()` multiplies active PM schedules with due **dates** in the two-month window by OEM spec qty. Does not project hour-based schedules or apply `service_interval_hours`. No export, filters or status tabs. |

**Stack:** CodeIgniter 3/PHP/MySQL; shared `.card`, `.tbl`, form classes, `--surface`, `--sep`, semantic colors; page-local CSS. `MY_Controller` handles authentication and module access (roles 1,2,3 for Lubricants); controller POST guards enforce tighter rights. CI CSRF is on and `form_open()` supplies token. Expected data volume, desktop/mobile mix, server latency and downstream export consumers are unknown.

## 3. Implementation findings

- The budget's `get_budget_overview()` aggregates PM requirement via `get_pm_driven_requirement()` (including a **20 hours/day assumption** for hourly schedules), saved budget rows and monthly issue quantity. Issue cost is **estimated from the selected budget rate or current fluid rate**, not booked historical acquisition cost. Legacy CSV/XLSX headings still say “Actual MTD” despite historical periods; renaming may break consumers.
- Mixing quantities across fluid units produced misleading budget KPI totals, while row-level quantities were correctly labelled. The three mixed-unit KPIs are now expressed as **fluid grades with allocation**, **issue records**, and **grades with PM demand**; line-level quantities and money remain. Aggregated totals in the model are unchanged for integration compatibility.
- `get_monthly_forecast()` always returns two month keys, including when both have zero visible demand, so the old `empty($forecast)` guard never showed its empty state. It also used `strtotime('+1 month')` from today's date, which can skip February from January 31. It now anchors advancement to the first of the month; the view checks positive **active** grade demand and distinguishes no-demand months.
- Specification updates previously relied on re-entering a type/fluid pair into an add-style form. Existing rows now offer a role-gated Edit action that prefills this same real POST form and announces whether submission updates or adds. A discontinued fluid's spec remains visible but not offered for editing; server rejects new/tampered spec POSTs for discontinued types.
- `Lubricants::specifications()` previously turned nonnumeric or negative recommended quantities into 0, and `types()` turned invalid costs into 0, accepted arbitrary units and normalized unknown categories to Other. Both now reject malformed/out-of-schema input before writing, and report DB operation success/failure. Existing valid zero quantity/rate are retained; no invented minimums or duplicate-name rule.
- Quantity fields are DECIMAL(10,2). Previously budget quantity input `step="0.1"` could mark a valid saved 2-decimal amount invalid. Budget/spec/forecast now show two decimals where data precision matters; budget input uses `0.01`. Missing spec interval (`NULL`) and explicitly saved `0` now display differently.

## 4. Ten-principle UX audit matrix

**P** = supported by source evidence; **~** = partial; **NA** = not applicable (reason below); **NV** = not verified at runtime. Status describes the **modified source**, not a certified production UI.

| Principle | Budget | Specs | Types | Forecast | Evidence / specific rationale |
|---|---|---|---|---|---|
| 1. Status tabs | NA | NA | NA | NA | Budget/specs have no lifecycle statuses; types list only active and offers no status change; forecast is two dated periods, not workflow states. No invented tabs. |
| 2. Toolbar/controls | ~ | ~ | ~ | NA | Budget year/month and actual CSV/XLSX; spec/type create form when authorized. Forecast has fixed two-month horizon and no supported action; adding controls would be decorative. Historic year access improved. |
| 3. Table clarity | ~ | ~ | ~ | ~ | Existing business columns retained; money and qty aligned; spec/type deterministic name ordering; forecast still scrolls horizontally on wide screens. Browser review pending. |
| 4. Card view | NA | NA | NA | ~ | Dense edit matrix and small master tables need direct comparison, not redundant cards. Forecast uses focused month cards ≤900px, avoiding a many-column mobile matrix. |
| 5. Record detail | NA | ~ | NA | NA | Budget editing is inline by month; spec row Edit prefills complete existing fields. Type create-only has no supported detail/edit route; forecast is an aggregate. Do not invent history panels. |
| 6. Row actions | NA | ~ | NA | NA | Spec Edit uses existing upsert endpoint, guarded server-side; no delete/approve supported. Other three pages have no existing row mutation route. |
| 7. Semantic color | ~ | ~ | ~ | ~ | Subnav now uses `--surface`; forecast Current chip uses `--blue-d`/`--blue`. Screen-level contrast still needs browser measurement. |
| 8. Pagination | NA | ~ | ~ | NA | Budget rows are one month's active grades; spec/type catalogs are unbounded queries, size unknown—benchmark before adding pagination. Forecast exactly two rows. |
| 9. Responsive | ~ | ~ | ~ | ~ | Budget row grid ≤900px; spec/type overflow is contained in table wrapper; forecast month cards ≤900px. Touch/zoom tests pending. |
| 10. Feedback/a11y | ~ | ~ | ~ | ~ | Existing flash and CSRF maintained; new associated labels, focus outlines, empty states, spec edit focus/hint. Error redirects still discard drafts; AT checks pending. |

## 5. Enterprise-grade findings

| Requirement | Budget | Specs | Types | Forecast | Evidence / limit |
|---|---|---|---|---|---|
| A. Data accuracy | ~ | ~ | ~ | ~ | Qty units and precision clarified, malformed input rejected, date rollover corrected; cost is an estimate and forecast method differs from budget PM requirement. Reconcile DB samples. |
| B. Functional integrity | ~ | ~ | ~ | ~ | Existing routes/CSRF/upsert/exports preserved; spec Edit prefill and month cards have Node checks, but real POST/export/render not tested here. |
| C. Performance | ~ | ~ | ~ | ~ | Budget loads all active types, spec/type masters load full tables, forecast batches schedules/specs. No measured volumes or latency; don't add generic pagination without evidence. |
| D. Consistency | ~ | ~ | ~ | ~ | Four subnav surfaces use shared token. Exports retain legacy “Actual” headings pending contract review; schema has Discontinued but active type catalog intentionally excludes it. |
| E. Security | ~ | ~ | ~ | ~ | Server roles and CSRF inspected; guarded spec action. Need authenticated role matrix and crafted POST tests in staging. Hiding a form is not an authorization test. |

## 6. Prioritized defect and improvement register

| ID / type / status | Current behavior and reproduction evidence | Impact / severity | Recommendation and acceptance | Dependencies / verification |
|---|---|---|---|---|
| **L-01 Visual — implemented; runtime NV** | Spec/type/forecast subnav used `var(--card, #111827)` against white `--surface` in `layouts/header.php`. Open each page in light layout. | Inconsistent dark controls. **High** | Use shared `--surface`, semantic Current chip and visible focus; no `--card` fallback. | Contrast/viewport screenshot and Node source check. |
| **L-02 Data integrity — implemented; runtime NV** | `Lubricants::specifications()` coerced invalid/negative recommended qty to 0; a crafted POST could replace an existing standard with zero. | Incorrect OEM reference, subsequent issue variance/PM demand. **High** | Reject malformed/out-of-range quantity, interval and notes before upsert; no write on failure; valid zero remains allowed. | DB-backed invalid POST then readback + valid update and role test. Form draft still lost on error. |
| **L-03 Data integrity — implemented; runtime NV** | `types()` silently coerced invalid rates, unknown categories/units; schema columns have length/decimal limits. | Incorrect grade metadata, downstream budget valuation. **High** | Validate against actual category vocabulary and L/Kg/Pcs options, finite nonnegative rate within DECIMAL(10,2), field lengths; only flash success if DB insert succeeds. | Crafted POST cases + DB readback + all-role access tests. |
| **L-04 Forecast correctness — implemented; runtime NV** | `strtotime('+1 month')` from Jan 29–31 can skip February in `get_monthly_forecast()`. | Missing next-month demand at month end. **High** | Anchor next month to first day; result keys exactly current + next month even across December/January. | Time-controlled PHP/model integration test (Jan 31, Dec 31) with seeded schedules. |
| **L-05 Forecast interpretation — partly implemented** | Forecast uses dated schedules only; budget PM requirement also projects hourly schedules with assumed 20h/day. `service_interval_hours` not used by either forecast model. | Users may expect identical totals or mistake forecast for guaranteed issue quantity. **High** | On-screen calculation note, no fabricated demand. Agree whether both forecasts should share methodology before altering model. | Compare dated/hourly sample and document approved rule; changes would affect budget/forecast integration. |
| **L-06 Budget interpretation — implemented on page; exports open** | Totals sum L, Kg and Pcs and CSV/XLSX call monthly rate-based estimate “Actual MTD”. | Non-comparable totals and financial overclaim. **High** | Replace mixed-unit KPI totals with grade/issue counts, preserve per-grade quantity + unit. Version export labels after consumer approval. | Test mixed-unit seed and export downstream; confirm finance definition of actual cost. |
| **L-07 Workflow — implemented; runtime NV** | Spec master showed existing values but no direct way to load them into the upsert form. | Accidental overwrite / repeated manual lookup. **Medium** | Authorized Edit button fills existing type/fluid/quantity/interval/notes, focus moves to qty, hint announces add/update; discontinued specs remain read-only. | Keyboard and scripted edit/save/readback; server enforces active fluid. |
| **L-08 Navigation/empty — implemented; runtime NV** | Budget year picker showed only now-2…now+1 despite GET accepting 2000…2100; forecast had false empty test. | Hidden historical budgets / misleading blank forecast. **Medium** | Select any supported year; honest forecast empty state with two-month scoped message. | Load 2021 budget in current year and zero-demand month via seeded staging. |
| **L-09 Scale — open** | `get_all_specs()`, `get_all_types()` and forecast schedules loaded fully; specs table may grow. | Possible latency/horizontal scan cost, size unknown. **Low until measured** | Benchmark realistic catalog/schedule volumes; consider existing-data search or SQL pagination only if warranted. | Volumes, DB explain plans, benchmark thresholds; no schema change now. |
| **L-10 Error recovery — open** | Invalid spec/type/budget POST redirects with flash and loses unsaved draft. Concurrent spec inserts also rely on unique index. | Re-entry and possible generic DB error. **Medium** | Render old input with field-level errors; handle unique-key conflict gracefully after DB-backed design. | CI integration tests with CSRF, valid/invalid/duplicate submissions. |

## 7. Table and interaction specification (why each fits)

- **Budget:** keep month/year, exports and inline allocation matrix. Order fluid+grade/unit, category, PM demand, editable qty, unit cost, computed budget cost, monthly issues with estimated value. Money aligned right in installation currency. No status tab/card/detail or sorting: operator must review all grades in one month's allocation. Default catalog order category then name. Year numeric 2000–2100; date/month retained on export. Counts in KPI are *grades/issue records* rather than physically incompatible units. Form uses existing atomic POST and CSRF. Zero budget is different from absent row. Loading/empty/error beyond server-render/flash remains unverified.
- **OEM specs:** order machine type, lubricant/grade, recommended qty + unit, optional interval hours, notes, authorized Edit. Default machine type then fluid name. ID is existing equipment-type/fluid pair, not a made-up serial. Single form supports both add/update with a warning before overwriting; POST validated and role-guarded. No destructive action (not supported). No status badges: archived/discontinued grade is shown read-only, not implied safe for new assignments.
- **Types:** active catalog ordered category then name; columns name (primary), grade, category, unit, default rate with currency, description. Supervisor-only create uses actual enumerated categories/units; no invented edit, import, status tab or archive menu. If volume grows, consider search *only after* measuring scale and confirming needs. Read-only roles see the catalog and a useful empty state.
- **Forecast:** exactly two months by fluid grade and unit, no status tabs/sort/pagination/export. Desktop cross-month matrix supports comparison; mobile month cards show nonzero grades and an explicit no-demand month message. Current-month chip is blue; `—` means no dated demand for that grade. No fake detailed asset breakdown, assignment or approval action: these are not provided by the endpoint.

## 8. Responsive / visual design specification

Reuse `--surface` (#fff in rendered light layout), `--sep`, `--t1/--t2`, `--blue`/`--blue-d` and existing `.card`/`.tbl`/`.form-*`. Avoid navy fallback, arbitrary rainbow badges and undersized type. All number comparisons retain unit or currency, with two decimal places where decimal(10,2) applies. Spec/type tables scroll inside their own card on a narrow device; do not compress labels to illegibility. Forecast becomes a two-month card grid below 900px; essential values and units remain visible. Budget uses the existing labelled row grid. Focus outlines are visible; keyboard edit focuses the value field. Test at 320/390/768/1440px and 200% zoom with authenticated light theme; CSS alone cannot prove contrast or touch adequacy.

## 9. Before → after comparison

| Before | After | Kind / benefit / business behavior / verification |
|---|---|---|
| Navy subnav on white app | Shared white surface across all four pages | Visual only; align hierarchy; screenshot. |
| Invalid spec or type values silently stored as zero/fallback | Reject malformed inputs and signal save failure | Integrity, not new business fields; crafted POST/readback. |
| Hidden historical year; budget KPI mixes units | Full supported year picker, per-grade unit quantities and compatible KPI counts | UX/data presentation; same model/storage; check 2021 and L/Kg/Pcs seed. |
| Spec value visible but hard to update | Role-gated Edit prefills same real upsert form | Task efficiency, not a new endpoint; edit/readback and keyboard. |
| Forecast blank two-row matrix / date rollover issue | Correct two-month window, useful zero-demand state and compact mobile cards | Functional/data display; seeded month-end test, narrow viewport. |
| Forecast looked equivalent to budget PM demand | Note states date-only method and omitted hour projections | Interpretive, no rule changed; reconcile representative PMs. |

## 10. Implementation plan / dependencies

**Implemented:** CSS surface + a11y affordances, type/spec validation and deterministic ordering, spec Edit prefill, budget year/precision/KPI correction, forecast month rollover/empty/mobile presentation, Node checks. **Next before acceptance:** run PHP native test suite/lint, seed representative mixed-unit and month-end PM fixtures, run role/CSRF/write/export browser tests on MySQL and MariaDB, then review cost/export terminology with accounting. No new routes, status enums, dependencies or schema changes. Preserve existing CSV/XLSX headings until downstream consumers approve a versioned rename.

## 11. Code changes

`application/controllers/Lubricants.php`, `application/models/Lubricant_model.php`, `application/views/lubricants/{budget,specifications,types,forecast}.php`, `tests/lubricant_masters_ux.js` and `tests/README.md`. Uses existing CI conventions; no production data modified.

## 12. Tests and verification evidence

- `node tests/lubricant_masters_ux.js`: **PASS**, checks surfaces, honest forecast note/empty/card markup, month anchoring, budget year/mixed-unit KPI text, controller guards, and executes the actual inline specification editor with a 3/5 record to verify correct values and focus.
- `node tests/table_ux.js`: **PASS**, earlier supplier/ledger/budget surface/draft calculator regressions.
- `git diff --check`: **PASS**.
- Six changed PHP files **parsed** with JavaScript `php-parser` 3.2.5 (not native PHP lint). `php tests/run.php`, `php -l`, MySQL/MariaDB and browser E2E: **not run locally** because `php` and DB are unavailable. Existing `tests/live_e2e.py` verifies baseline Store spec/budget POST and forecast GET, but these new changes have **not** been exercised with a live server here.

## 13. Open risks / decisions / acceptance checklist

| Acceptance criterion | Status and required resolution |
|---|---|
| Correct records, units, month-year values, spec upsert, type create | **Source checked; runtime NV.** Seed active/discontinued type and two equipment classes, save and reload on real DB. |
| POST validation + authorization + CSRF, no false success | **Implemented, runtime NV.** Test Supervisor/Store/Technician direct POSTs, malformed numbers, long text, invalid unit/category and DB failure. |
| Forecast two months including Jan31/Dec31, honest empty state | **Source logic corrected, runtime NV.** Freeze time and compare due-date fixtures with month keys and mobile display. |
| Budget/forecast accounting and method consistent | **Partial.** Finance must define historically booked cost; PM owner must decide whether date-only forecast should match budget hour projections. No claim of equivalence now. |
| Search/sort/filter/pagination and status counts | **NA** to fixed forecast/budget matrix and current small create-only masters; revisit only if actual volumes/tasks justify. |
| Accessibility/responsive/focus/empty/error | **Partial source evidence; browser NV.** Check keyboard, 200% zoom, color contrast and touch on every role/page. |
| No existing functionality removed, integration and tests pass | **Not verified** on PHP/MySQL in this branch; run `php tests/run.php`, browser/live E2E and regression exports before deployment. |

**Do not mark ready for production solely from this local audit.** Performance gains or user productivity gains have not been measured.
