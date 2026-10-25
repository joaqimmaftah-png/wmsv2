# Tests

```bash
php tests/run.php
```

No Composer, PHPUnit, database or web server: a plain PHP 8+ process runs a
small assertion harness and exits non-zero when something fails. CI runs the
same command (`.github/workflows/tests.yml`).

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

## What is not covered

- Anything needing MySQL (models, migrations, report SQL). Those still need the
  manual walkthrough described in `README.md` → *Testing*.
- Anything needing a browser (layout, JavaScript, uploads).

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
states are present. These do not substitute for PHP/MySQL or
browser/assistive-technology tests. See `docs/table-ux-audit.md` for the
staging acceptance checklist.

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

## Lubricant planning master audit regressions (Node)

```bash
node tests/lubricant_masters_ux.js
```

Dependency-free source checks plus a minimal DOM simulation of the real OEM
specification editor. Covers the budget, specifications, types and two-month
forecast. PHP, database, authenticated-browser and month-end fixtures remain
required; see `docs/lubricant-planning-table-audit.md`.
