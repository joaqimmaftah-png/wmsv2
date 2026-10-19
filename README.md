# Workshop Management System (WMS)

A web-based workshop / fleet-maintenance management system for a heavy-equipment
workshop: work orders, preventive maintenance (PM), equipment register, bilingual
inspection checklists, a defect (backlog) register, store inventory with spare-parts
tracking, suppliers and purchase orders with goods receipts, lubricants, team/HR
records, reliability KPIs (availability, MTTR, MTBF), a weekly fleet report
("W36"), and a planned-availability forecast.

Built on **CodeIgniter 3.1.13 (PHP)** with a **MySQL/MariaDB** database. It is
deployed as a classic server-rendered application on shared (cPanel) hosting —
there is no Composer, no Node build step, and no external PHP dependency: the
Excel reader/writer and the PDF generator are hand-written in this repository.

> **Companion document:** [`SYSTEM.md`](SYSTEM.md) is the full technical and
> functional specification (modules, business rules, workflows, complete database
> schema, route catalogue, known issues). This README covers setup and operation.

---

## ⚠️ Read this first — repository contents

This repository currently contains material that should not be in version control:

- `database/yzzme_43038742_aml.sql` is a **full production database dump**,
  including real staff accounts (most password hashes are legacy **MD5**),
  the equipment fleet, and ≈12,700 stock movements.
- `application/config/database.local.php` and `application/config/config.local.php`
  are **committed with live-looking credentials / encryption key**, even though
  the code comments state they are git-ignored. There is **no `.gitignore`** in
  the repository.
- `application/logs/` contains committed runtime log files.

Treat every credential and hash in this history as exposed. See
[`SYSTEM.md` §18 (issues K-01…K-03)](SYSTEM.md) for the remediation checklist
(rotate the DB password and encryption key, add a `.gitignore`, untrack the
files, force password resets). Do not import the dump into a public environment
without resetting all passwords.

---

## Key features

| Module | What it does |
|---|---|
| Dashboard | Operational KPI dashboard (30-day availability/MTTR/MTBF, open WOs, overdue PM, attendance, pending-spares board) and a separate read-only Executive Summary for the CEO role |
| Work Orders | Corrective / Preventive / Incident work orders with printable job cards, multi-technician labour sessions, spare-part and oil lines, incident (HSE) details with photo/PDF evidence, drawn operator signature, two-stage close (Completed → supervisor-verified Closed) |
| Equipment | Asset register with types, meter readings (hours/km) with validation and history, report grouping/site classification, manufacturer spec sheets, per-machine KPIs |
| PM Planner | Preventive-maintenance schedules (Hours/Km/Days/Months intervals), completion recording, 12-month calendar forecast, per-service spare-part requirements |
| Inspections | 14 seeded bilingual (EN/FR) checklist templates; signed inspection records; failed items automatically raise/annotate/close backlog defects; real PDF output |
| Backlog | Defect register (P1–P3) with Excel export mirroring the legacy "Backlog on going / done" sheets; planning a defect pushes a job into the maintenance plan |
| Inventory (Materials) | Stock items with a movement ledger (receive/issue), reorder data, Excel export/template/**import with dry-run validation and per-row error report** |
| Pending Spare Parts | Matches parts requested on open work orders against stock; Allocate → Issue reservations; in-app availability alerts when stock arrives |
| Suppliers & Orders | Supplier master with contacts and part catalogue; purchase-order pipeline with measured lead-time estimates; partial deliveries, GRN (goods receipt note) per delivery, order documents |
| Lubricants | Lube types, per-equipment-type specifications, consumption recording with variance, monthly budget, PM-driven requirement vs actual compliance |
| Team | Staff list on the same `users` table, daily attendance, leave requests/approval, certifications with expiry tracking, monthly attendance report, shift-roster display |
| Users & Settings | Role-based user administration (Supervisor only); system settings; sign-in security screen (throttling policy + security events) |
| Reports | Daily/weekly/monthly reports, MTTR/MTBF, lube compliance, store registers (issue/receipt/reorder), asset register (+ Excel import), annual report/plan, service-kit recommendations, workshop daily/weekly reports, and the W36 weekly fleet report (screen, Excel, print, publish/freeze) |
| Forecast | Planned-availability week planner (PM placement, maintenance jobs, weekly inspection rounds, standing/unplanned allowances), publish/freeze with revisions, plan-vs-actual, rolling 3-week plan, PM spare-parts forecast |

## Technology stack

| Layer | Technology |
|---|---|
| Framework | CodeIgniter 3.1.13 (vendored in `system/`) |
| Language | PHP (see version note below) |
| Database | MySQL / MariaDB via `mysqli`, `utf8mb4` (reference dump produced on MariaDB 11.4) |
| Frontend | Server-rendered PHP views; inline CSS design system in `application/views/layouts/header.php`; **Chart.js 4.4.1 and the Inter font are loaded from CDN** (jsDelivr / Google Fonts) — pages degrade without internet |
| Excel | In-house `XlsxWriter` helper + `Xlsx_reader` library (ZipArchive + SimpleXML) — no PhpSpreadsheet |
| PDF | In-house `Pdf_writer` (PDF 1.4) — used for inspection PDFs; report "PDFs" are print-optimised HTML pages |
| Sessions | CodeIgniter database session driver (`ci_sessions` table) |

**PHP version:** the code requires PHP ≥ 7.0 (`Throwable`, `??`). The reference
dump was produced under PHP 7.2; the codebase carries PHP 8.2 dynamic-property
declarations, and the built-in setup screen recommends PHP 8.1/8.2 on cPanel.
Required extensions: `mysqli`, `mbstring`, `iconv`, `zip`, `xml`
(SimpleXML/libxml). `gd` is needed for the Team signature-image processing and
is used optionally for signature images in inspection PDFs; `zlib` optionally
compresses inspection PDFs. Visit `/inspections/pdf_diagnostics` (sign-in
required) for an on-screen environment check.

## Project structure

```
.
├── index.php                  Front controller (ENVIRONMENT from CI_ENV server var, default production)
├── .htaccess                  Apache rewrite + security headers (cPanel)
├── .htaccess.cpanel-alternative  Fallback rewrite rules for FastCGI hosts (rename to .htaccess if needed)
├── system/                    CodeIgniter 3.1.13 framework (vendored — do not edit)
├── application/
│   ├── config/                config.php, database.php, routes.php, autoload.php
│   │                          + *.local.php(.example) host-local overrides (secrets)
│   ├── controllers/           17 controllers (Auth + 16 modules)
│   ├── core/MY_Controller.php Authentication gate + role access matrix + layout renderer
│   ├── models/                21 models (business logic, KPI/forecast engines)
│   ├── libraries/             Excel import/export, PDF writer, XLSX reader
│   ├── helpers/               kpi (pure KPI arithmetic), wo, equip, xlsx, csrf
│   ├── views/                 94 view files (layouts + one folder per module)
│   ├── cache/                 must be writable
│   └── logs/                  must be writable (CI logs + php_errors.log)
├── database/
│   └── yzzme_43038742_aml.sql The ONLY schema/seed artifact — full production dump (59 tables)
└── uploads/                   User files (web-served, script execution blocked by .htaccess)
    ├── photos/                equipment & user photos
    ├── signatures/            drawn/uploaded signatures
    ├── wo_attachments/        incident evidence
    ├── order_docs/            order documents (delivery notes/invoices)
    ├── tmp/                   generated workbooks & import reports (Deny from all)
    ├── documents/             (present, currently unused by code)
    └── logoam.png             company logo used by the W36 workbook/print view
```

> There are **no** database migrations, seeders, CLI scripts, cron jobs, or
> automated tests in this repository — the SQL dump contains the complete
> schema. Decision/task IDs cited in code comments (D29, F-06, MP-3, …) are
> labels from the development history; the rule each one produced is stated
> in the comment itself and in `SYSTEM.md` (§17).

## Installation

### 1. Prerequisites

- Apache with `mod_rewrite` (the shipped `.htaccess`), or an equivalent
  rewrite to `index.php`. Nginx works but needs its own rewrite + the
  uploads script-block rule (documented in `uploads/.htaccess`).
- PHP with the extensions listed above.
- A MySQL/MariaDB server and an **empty** database.

### 2. Get the code

```bash
git clone <this-repository> wmsv2
cd wmsv2
```

Point the web server document root at the repository root (where `index.php`
is). `base_url` is auto-detected from the request host, so no URL edit is
normally needed.

### 3. Create the database and import the dump

```bash
mysql -u <user> -p -e "CREATE DATABASE <dbname> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u <user> -p <dbname> < database/yzzme_43038742_aml.sql
```

Notes:

- The dump contains **no** `CREATE DATABASE`, `USE`, or `DROP TABLE`
  statements — import it into an empty database; importing twice will fail on
  existing tables.
- It creates all 59 tables including `ci_sessions` (required before first
  sign-in) and loads reference data (roles, settings, inspection templates,
  report groups/sites, equipment types…) **plus production operational data**
  (66 user accounts, 116 machines, ≈1,700 stock items, ≈12,700 movements).
- On cPanel: create the database and user under *MySQL Databases*, grant the
  user **ALL PRIVILEGES** on it, then import the file with phpMyAdmin.

### 4. Configure credentials (never commit these files)

```bash
cp application/config/database.local.php.example application/config/database.local.php
cp application/config/config.local.php.example   application/config/config.local.php
```

Edit `database.local.php` with the real host/user/password/database.
In `config.local.php` set a long random `$config['encryption_key']`, e.g.:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Credentials can alternatively come from environment variables
(`WMS_DB_HOST`, `WMS_DB_USER`, `WMS_DB_PASS`, `WMS_DB_NAME`); the
`database.local.php` file wins when both are present. If no credentials are
configured at all, the application intentionally shows a "Database Setup
Required" page instead of connecting.

> ⚠️ The copies of `database.local.php` / `config.local.php` currently in git
> contain real values from the production host — **overwrite them locally and
> do not commit them back** (see the warning at the top).

### 5. Make runtime directories writable

The PHP process must be able to write to:

```
application/logs/  application/cache/  uploads/ (and every subdirectory)
```

Import/export workbooks are generated in `uploads/tmp/`; several Forecast and
Backlog exports use the PHP system temp directory instead.

### 6. First sign-in

Open the site root — you are redirected to `/login`. After importing the
production dump, the accounts are the production staff accounts (their
passwords are not documented anywhere and most hashes are MD5 — the sign-in
screen still accepts legacy MD5/SHA1 hashes and upgrades them to bcrypt on
first successful sign-in; see `legacy_password_login` below).

For a fresh/cleaned database, create a supervisor directly:

```bash
php -r "echo password_hash('Pick-A-Strong-Password', PASSWORD_DEFAULT), PHP_EOL;"
```

```sql
INSERT INTO users (role_id, employee_id, first_name, last_name, email, password, status)
VALUES (1, 'ADMIN001', 'System', 'Admin', 'admin@example.com', '<paste-bcrypt-hash>', 'Active');
```

Then manage all further accounts under **Users** in the UI (Supervisor only).
To retire legacy hashes once everyone has a bcrypt hash, set
`$config['legacy_password_login'] = FALSE;` in `config.local.php` (or the
`WMS_LEGACY_PASSWORD_LOGIN=0` environment variable).

## Configuration reference

| Where | What |
|---|---|
| `application/config/config.php` | Committed defaults: auto-detected `base_url`, DB-backed sessions (`wms_session`, 2 h), CSRF protection **on** (`csrf_token_wms`, regenerated per request), cookie `Secure` auto-enabled on HTTPS, `legacy_password_login` |
| `application/config/config.local.php` | Host-local overrides (encryption key, `legacy_password_login`, `cookie_secure`) — **secret, do not commit** |
| `application/config/database.php` | Reads credentials from `WMS_DB_*` env vars, then `database.local.php`; defines `WMS_DB_MISSING` when unconfigured |
| `CI_ENV` server variable | `production` (default) / `development` / `testing` — set in `index.php`; development enables error display and `db_debug` |
| `WMS_ENCRYPTION_KEY`, `WMS_LEGACY_PASSWORD_LOGIN`, `WMS_COOKIE_SECURE` | Environment-variable equivalents read by `config.php` |
| `settings` database table, edited under **Settings** | Business configuration: open hours (daily/weekly), availability target, report week start day, breakdown counting and MTBF zero-failure modes, PM meter staleness, forecast allowances, sign-in IP cap. Every key has a hard-coded default in `Settings_model`, so a missing row never breaks a report. Full list: `SYSTEM.md` §12 |
| PHP `date.timezone` | The application never sets a timezone — set it in `php.ini`/host settings or all timestamps use the server default |

## Roles and access

Five roles (`roles` table; enforced centrally in `MY_Controller`):

| ID | Role | Access summary |
|---|---|---|
| 1 | Supervisor | Everything, including Users, Settings, verification/closure actions, imports |
| 2 | Technician | Work orders, equipment (view), PM, inspections, backlog, inventory, pending spares, lubricants, team |
| 3 | Store | Inventory, pending spares, suppliers, orders, lubricants, team |
| 4 | Manager | **View-only** almost everywhere (any POST is blocked) except order status changes, alert acknowledgements, and publishing/naming the W36 weekly report; no lubricants/users/settings |
| 5 | CEO | Dashboard only, rendered as a read-only Executive Summary |

Every signed-in non-CEO user can open their own profile and change their own
password. The full controller-by-role matrix and per-action restrictions are in
`SYSTEM.md` §4. Sign-in is throttled (5 failures per account+IP, 20 per account,
30 per source IP across ≥3 accounts, per 15 minutes) and lock-out events are
recorded under **Settings → Sign-in Security**.

## Daily operation & data exchange

- **Excel imports** (`.xlsx` only, ≤ 8 MB uploads): inventory
  (`/materials/inventory`, ≤ 5,000 rows, dry-run supported) and the asset
  register (`/reports/asset_register`, supervisor only, ≤ 2,000 rows). Both
  produce a downloadable per-row import report and write an audit row
  (`inventory_import_logs` / `asset_import_logs`). Downloadable templates are
  generated by the application so the format can never drift.
- **Excel/CSV exports:** inventory, backlog, asset register, annual
  report/plan, service kits, materials movement, store registers, reorder
  list, workshop daily/weekly, W36 weekly workbook, forecast workbooks
  (planned + rolling), plus CSV exports for work orders/attendance/lubricants.
- **Print documents:** job cards (whole WO or single labour session), GRN per
  delivery, daily report, W36 and workshop reports (print-optimised HTML —
  use the browser's "Save as PDF"). Inspection records are the only true
  server-generated PDFs.
- **In-app notifications only:** spare-part availability alerts (bell counter +
  `/spareparts/alerts`) and sign-in security events for supervisors. There is
  **no e-mail/SMS integration and no background job or cron** — housekeeping
  (login-attempt pruning, import-report cleanup) happens opportunistically
  during requests.
- **JSON endpoints** (all session-authenticated, used by the UI):
  `/materials/api_lookup`, `/workorders/api_spare_lookup`,
  `/workorders/api_stock_check`, `/orders/api_lead_time_estimate`.
  There is no public/token API.
- **Diagnostics:** `/materials/ping` (table checks) and
  `/inspections/pdf_diagnostics` (extension checks) — both require sign-in.

## Testing

There is **no automated test suite in this repository** and no Composer /
PHPUnit setup. Verify changes manually: sign in as each role, and for
report/forecast changes compare the W36 screen, its Excel export and the
dashboard cards — they intentionally share one KPI engine
(`application/helpers/kpi_helper.php`) and must agree.

## Deployment (cPanel production checklist)

1. Upload the code (or `git pull`) into the site root.
2. Create `database.local.php` / `config.local.php` on the server (step 4) —
   if you are changing the DB password, create them with the *current*
   credentials first, verify the site works, then rotate.
3. Import the SQL dump into an empty database (first install only).
4. Set the PHP version (the app's setup page suggests 8.1/8.2) and enable the
   extensions listed above; set `date.timezone`.
5. Ensure `application/logs`, `application/cache` and `uploads/**` are writable.
6. Serve over **HTTPS** (session cookie `Secure` flag follows the scheme).
7. If every page except the home page 404s, try `.htaccess.cpanel-alternative`
   (rename it to `.htaccess`, keeping a backup of the original).
8. Back up **both** the database and the `uploads/` tree (signatures, evidence
   and order documents live only on disk) plus the two `*.local.php` files.

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| "🗄️ Database Setup Required" page | `database.local.php` missing or host/user/database empty. Create it from the `.example` file, then import `database/yzzme_43038742_aml.sql` |
| Sign-in page loads but every login says the database is not answering | DB reachable by config but schema not imported (or user lacks SELECT). Import the dump |
| Blank/500 page right after sign-in | `ci_sessions` or `settings` table missing — re-import; check `application/logs/php_errors.log` and the dated CI log in the same folder |
| "The action you have requested is not allowed" (403) on submit | CSRF token missing/expired — reload the form and resubmit; do not open the same form in two tabs for a long time (tokens regenerate) |
| Imports fail with a generic upload error | File must be `.xlsx` ≤ 8 MB and within row limits; check PHP `upload_max_filesize`/`post_max_size`; `uploads/tmp/` must be writable |
| Excel/PDF export is a 500 | `uploads/tmp/` (or the PHP temp dir) not writable; for PDFs check `/inspections/pdf_diagnostics` |
| Photos/signatures return 403/404 on nginx | `.htaccess` is ignored on nginx — apply the uploads rules in the server block (see `uploads/.htaccess` footer) |
| Availability figures look wrong after changing open hours | **Settings** values (`daily_open_hours`, `weekly_open_hours`, target, week start) drive every KPI screen; reports already *published* (forecast baselines, W36 snapshots) stay frozen by design |
| Account "locked" after wrong passwords | Wait 15 minutes or sign in successfully from the account+IP pair; supervisors can review events under **Settings → Sign-in Security** |
| Attachment / order-document **download** returns 500 | Known bug K-04 (`SYSTEM.md` §18): the `download` helper is never loaded. Files remain in `uploads/wo_attachments/` / `uploads/order_docs/` |

## Documentation

- [`SYSTEM.md`](SYSTEM.md) — complete technical & functional specification:
  architecture, all modules and their status, business rules and formulas,
  end-to-end workflows, the full 59-table schema with relationships, the
  route catalogue, configuration reference, and the known-issues register.
- In-code comments are extensive and are the design record for the KPI engine,
  forecast and inventory rules (they cite decision IDs such as D29/D31 and
  audit findings such as F-06 whose source documents are not in this repo).
