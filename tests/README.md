# Tests

```bash
php tests/run.php
```

No Composer, PHPUnit, database or web server: a plain PHP 8+ process runs a
small assertion harness and exits non-zero when something fails. CI runs the
same command (`.github/workflows/tests.yml`).

## Defect workflow regression checks

```bash
php tests/backlog_workflow.php
```

This runs the real backlog/inspection/work-order policy code against in-memory
fakes: technician proposal/closure/cancellation boundaries, scheduling success
and failure rollback, idempotent retries, historical evidence, selected linked
defects, inspection evidence, missing schema/index, stale defect forms, closed
WO guards, locked plan rechecks and mismatched-machine protection. `tests/live_e2e.py` also contains
authenticated database cases for invalid and frozen planning, a committed
job/ledger row, stale edits, work-order verification, idempotent retries and
historical evidence. CI is configured to run these and `tests/ui_e2e.js` against
the real create and work-order verification forms.
These are not a substitute for a staging pass on **MySQL/MariaDB**: exercise
migrations 0015–0016 twice, different roles, concurrent submissions and stale
edits, frozen weeks, manual overrides, ledger failures, old multiline defects,
exports, actual browser zoom and assistive technology before deployment.

## Forecast publication and planning concurrency

On a **disposable** database imported from `database/schema.sql` with all
migrations applied, run:

```bash
WMS_DB_HOST=127.0.0.1 WMS_DB_PORT=3306 WMS_DB_USER=... WMS_DB_PASS=... \
WMS_DB_NAME=... WMS_TEST_DESTRUCTIVE=1 python3 tests/forecast_lock_integration.py
```

The runner starts independent PHP processes using the real CodeIgniter database
driver and models. It creates temporary dated jobs, ledger entries, snapshots and
a defect; it also temporarily changes the engine of two test tables and restores
it. **Never run it on a production database.** It refuses a date range with
existing rows and cleans its own range afterwards, but a killed test process may
require manual cleanup. `WMS_TEST_PHP` can override the `php` executable.
The suite checks publication in both orders, cross-week changes, competing
publishers, generation, manual cells, backlog planning and stale proposals,
linked-job activation versus defect machine changes in both orders, duplicate
and closed-defect guards, rollback, disconnect, timeouts, missing/malformed
locks, non-InnoDB tables and frozen inspection-job status. CI is configured for MySQL 8 and MariaDB 10.4;
run it on the actual target version and do a separate staging walkthrough.

## What is covered

The cases concentrate on the pure logic that has broken silently in the past:

| Area | Cases |
|---|---|
| **K-18 — session payload** | the projection keeps the whitelisted fields, derives `full_name`, accepts an object **or** an array, and never carries `password`, `salt` or a remember-token; `wms_user_full_name()` handles object/array/null |
| **K-11 — currency** | vocabulary sanity (unique, three upper-case letters, UGX present), posted values normalised and junk rejected, `settings.currency` read and validated with a fallback, `wms_money()` formatting |
| **K-17 — base_url** | normal/port/sub-directory/IPv6 hosts, HTTPS and `cookie_secure`, `WMS_BASE_URL` precedence, `WMS_COOKIE_SECURE=0`, the CLI fallback, and a reject table (quotes, slash, backslash, userinfo, fragment, double port, `..`, empty) |

The `base_url` cases include a **copy of `application/config/config.php`** from
the system temp directory, so they exercise the deployed code (including
`wms_base_url_from_host()`) and a developer's own `config.local.php` cannot
change the result.

## What the dependency-free unit runner does not cover

- Database behavior, migrations and report SQL; use the separate real-database
  tests above and the staging walkthrough in `README.md` → *Testing*.
- Browser layout, JavaScript and uploads; use `tests/ui_e2e.js` and manual testing.

## Conventions

- Add a case as a named `check()` / `is_same()`; the name is what a failure
  prints.
- Keep the runner dependency-free. If a test needs CodeIgniter, stub only the
  piece under test (as the session/currency tests stub `get_instance()`).
- Prefer exercising real code over duplicating a rule in the test. When a rule
  lives inline in a large file, extract it into a function first (that is why
  `wms_base_url_from_host()` exists) rather than copying it here.

## Shared-layout and page-title checks (Node)

```bash
node tests/page_titles.js
```

Verifies that the global header contains utilities only, the content canvas uses a
`<main>` landmark, and every standard rendered view has one main `<h1>` (with
separate, mutually exclusive screen/print headings for the W36 report).

## Global Find and Operational Attention checks (Node)

```bash
node --check assets/js/global-find.js
node tests/global_find_attention.js
```

Dependency-free structural checks for role/technician scoping, authorized record
and filtered-queue destinations, dialog semantics, keyboard behavior and the
inventory drawer deep-link. Authenticated database and browser/assistive-
technology checks remain necessary.

## Table UX regression checks (Node)

```bash
node tests/table_ux.js
node tests/reorder_ux.js
```

Dependency-free source checks for the Suppliers, Lubricants, Lube Budget and
Re-order Report surfaces, grouped-page calculation and the actual inline budget
draft calculator. The Re-order checks verify that the `/team/report` visual
language is scoped locally, the existing filters/export/routes remain intact,
status tabs and table/card views are real GET-driven controls, server-side
pagination uses the allow-listed `10 / 25 / 50 / 100` sizes and preserves URL
state, full-scope totals are separated from bounded page rows, quantities remain
honest when min/max levels are unavailable, and semantic/print/reduced-motion
states are present. Forecast checks cover planned-week, rolling, allowance, and
spare-parts surfaces. Rolling assertions include grouped three-week headers, GET
search/type/status filters, 10/25/50/100 pagination after filtering with preserved
URL state, full-scope count/export semantics, model-owned downtime in screen and
workbook, and the existing equipment-profile link. Allowance and spares assertions
cover provenance wording, accessible controls/table headers, responsive layout,
search/filter/sort/page state, equipment/material deep links, and honest empty/data
states. PM-parts checks cover associated labels and table semantics, unit and stock-
context disclosures, the searchable material picker, inventory deep links, responsive
layout, and safe removal confirmation. Forecast/jobs checks cover labeled selectors
and editor fields, explicit empty/read-only states, approved hold-code meanings,
responsive job identity, and the Standby hours rule. Plan-vs-actual checks cover
snapshot/actual availability states, table semantics, responsive layout and return
navigation. These are source checks, not PHP/MySQL or browser/assistive-technology tests.
See `docs/table-ux-audit.md`, `docs/forecast-table-ux-audit.md`, and
`docs/forecast-rolling-table-audit.md` for the corresponding acceptance checklists.

## Reports hub structural checks (Node)

```bash
node tests/reports_hub.js
```

Dependency-free checks that `/reports` owns its single `h1`, works with
JavaScript disabled (ordinary GET forms where every export inherits the chosen
period through `formaction`), renders its one trend as an accessible SVG with a
data table instead of a CDN-dependent canvas, escapes the user-controlled
weekly report name, and shows honest unavailable / limited-data / all-clear
states. It also checks the five-state W36 publication ladder: an open week is
`Draft — Week in progress` (Informational), its final day is `Draft —
Publication due soon` (Warning), a missed deadline is `Unpublished — Action
required` (High), an already published previous week is `Published` (No
action) and older unpublished weeks are `Unpublished — Overdue` (High). It
verifies the deadline comes from the configured week end, that only a bounded
window of *completed* weeks is checked, and that the missing-snapshot-table /
unreadable-database cases are disclosed rather than reported as a known draft.
Also verifies that every report launcher resolves to a real controller method
and that the hub reads the KPI engine, store ledger, inventory catalogue and
weekly-report model behind a `Throwable` guard. PHP/MySQL and
authenticated-browser checks are still required.

## Service Kit report audit regressions (Node)

```bash
node tests/service_kits_ux.js
```

Dependency-free structural/source checks for batched recommendation queries,
the preserved New-part and top-15 semantics, status/search/pagination controls,
empty states, the existing equipment profile link, site-currency labels, and
Excel inclusion of work-order oil records. It parses the inline report JavaScript
with PHP-rendered literals substituted; it does not replace PHP/MySQL or
browser verification. See `docs/service-kits-report-audit.md` for the audit and
acceptance checklist.

## Lubricant planning master audit regressions (Node)

```bash
node tests/lubricant_masters_ux.js
```

Dependency-free source checks plus a minimal DOM simulation of the real OEM
specification editor. Covers the budget, specifications, types and two-month
forecast. PHP, database, authenticated-browser and month-end fixtures remain
required; see `docs/lubricant-planning-table-audit.md`.
