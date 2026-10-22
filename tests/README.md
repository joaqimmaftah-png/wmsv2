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

## Table UX regression checks (Node)

```bash
node tests/table_ux.js
```

Dependency-free source checks for the Suppliers, Lubricants and Lube Budget
surfaces, grouped-page calculation and the actual inline budget draft calculator.
These do not substitute for PHP/MySQL or browser/assistive-technology tests.
See `docs/table-ux-audit.md` for the staging acceptance checklist.
