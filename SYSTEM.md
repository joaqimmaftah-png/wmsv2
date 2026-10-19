# Workshop Management System — Technical & Functional Specification

**Status of this document:** generated from a full static review of the
repository at commit `a4a2a6e` ("upload my system to github"), the only commit
in the uploaded history. Every statement below was verified against the source
code, `application/config/*`, or the database dump
`database/yzzme_43038742_aml.sql`. It describes the system **as implemented**,
not as intended: partially implemented, dead, and referenced-but-absent
functionality is catalogued in §17–§18.

> Review-environment limitation: the review sandbox has no PHP or MySQL
> runtime, so nothing was executed. Behaviour that depends on runtime
> configuration (PHP/MySQL versions, `sql_mode`, web-server rules) is marked
> as *to verify at runtime* where relevant (§19).

---

## 1. Purpose and scope

The Workshop Management System (WMS) runs the maintenance operation of a
heavy-equipment fleet workshop (company name seeded as "A&ML" in `settings`).
It replaces a set of manual Excel workbooks — the W36 weekly fleet report, the
"Backlog on going/done" sheets, the store's Inventory/Sortie/Receipts sheets,
and a week-ahead availability forecast workbook — with one database-backed
application, while still exporting workbooks in the familiar layouts.

In scope (implemented): fleet register, work orders and labour tracking, PM
scheduling, bilingual equipment inspections, defect backlog, spare-parts
inventory with reservations and procurement, lubricants, workshop team
records, reliability KPIs, weekly/daily reporting, and planned-availability
forecasting.

Out of scope in the code as shipped: any public API, e-mail/SMS notifications,
accounting integration, customer-facing access, offline/mobile apps, and
background/scheduled processing.

### Terminology

| Term | Meaning in this system |
|---|---|
| WO | Work order (`work_orders`), numbered `WO-AML-001…` |
| PM | Preventive maintenance; a `pm_schedules` row per machine/service |
| SMR | Service meter reading (the inspection form's meter field) |
| Sortie | Stock issue (outgoing) — legacy French Excel term kept in `materials.qty_sortie` |
| GRN | Goods Receipt Note — one per recorded delivery (`goods_receipts`) |
| W36 | The weekly fleet report, named after the Excel sheet/workbook it replaces (built for an ISO week, e.g. `2026-W36`) |
| Reportable machine | Equipment with `include_in_weekly_report = 1` and not `Retired`; the population of the weekly report and forecast |
| Hold code | Why work waits: `WSP` spare part, `WQT` quotation, `WCF` confirmation, `UD` under diagnosis, `UR` under repair |
| Open hours | The hours a machine is expected to be available per day/week (`settings`) — the denominator family of every availability figure |

## 2. Architecture

Classic server-rendered CodeIgniter 3.1.13 MVC. No Composer
(`composer_autoload = FALSE`), no JavaScript build; the framework is vendored
under `system/`.

```
Browser ──► index.php (ENVIRONMENT from CI_ENV, default production)
          ──► CodeIgniter router (application/config/routes.php + default routing)
          ──► Controller (extends MY_Controller, except Auth)
                ├─ MY_Controller::__construct: DB-config check → session auth
                │  → role access matrix → load `settings` into view vars
                ├─ Models (Query Builder; raw SQL only for SELECT … FOR UPDATE locks, parameter-bound)
                ├─ Libraries (Excel in/out, XLSX reader, PDF writer)
                └─ render(): layouts/header + layouts/sidebar + module view + layouts/footer
          ──► MySQL/MariaDB (mysqli, utf8mb4)   ──► uploads/ (user files)
```

Key architectural facts:

- **Autoload** (`application/config/autoload.php`): libraries `database`,
  `session`, `form_validation`, `pagination`; helpers `url`, `form`, `date`,
  `file`, `string`, `wo`, `equip`, `kpi`, `csrf`. Models are loaded per
  controller.
- **`MY_Controller`** is the security and presentation backbone: it redirects
  anonymous users to `/login`, enforces the role matrix (§4), loads the whole
  `settings` table into `site_settings` view data on every request, and its
  `render()` injects the current user, role, unread spare-part alert count and
  unacknowledged security-event count (supervisors) into every page. It also
  provides `require_post()` (405 for non-POST) and a `json()` responder used
  by the AJAX endpoints.
- **Print documents bypass the layout**: job cards, GRNs, the daily-report
  print page and the W36/workshop "PDF" exports render standalone HTML meant
  for browser print-to-PDF. Only inspection records produce a binary PDF
  server-side.
- **One KPI arithmetic**: availability/MTTR/MTBF are computed exclusively by
  pure functions in `application/helpers/kpi_helper.php`, reached through
  `Kpi_engine_model`, `W36_report_model` and `Forecast_model`. Screens must
  not recompute (the dashboard's older divergent arithmetic was replaced by
  delegations to `Kpi_engine_model` inside `Dashboard_model`).
- **One stock writer**: all balance changes go through
  `Materials_model::add_stock_movement()` (plus opening-stock logging), which
  owns locking, idempotency and the movement ledger (§7.5).
- **One meter writer**: `Equipment_model::record_meter_reading()` owns every
  hours/km change (§7.3).
- **Defensive schema probes**: the schema ships as a single dump, and many
  code paths check `table_exists()`/`field_exists()` and degrade (hide
  fields, skip features) instead of erroring when an older database lacks a
  table/column (§13). With the shipped dump all probed tables/columns exist.
- **External runtime dependencies in the browser only**: Chart.js 4.4.1
  (jsDelivr) and the Inter font (Google Fonts) via CDN.

## 3. Module catalogue

Controllers live in `application/controllers/`; each extends `MY_Controller`
(authenticated) except `Auth` (extends `CI_Controller`). Status legend:
**Implemented** = fully wired UI→controller→model→DB; **Partial** = works with
the noted gaps (§17 details).

| # | Module (Controller) | Main models | Responsibility | Status |
|---|---|---|---|---|
| 1 | `Auth` | `Auth_throttle_model` | Sign-in/out, legacy-hash upgrade, throttling | Implemented |
| 2 | `Dashboard` | `Dashboard_model`, `Spareparts_model` (+ order/lube/materials for CEO) | Operational dashboard; CEO Executive Summary variant | Implemented |
| 3 | `Workorders` | `Workorder_model`, `Equipment_model`, `Team_model`, `Lubricant_model`, `Materials_model`, `Spareparts_model` | WO CRUD, status lifecycle, labour sessions, job cards, incidents & evidence, spare/oil lines | Implemented |
| 4 | `Equipment` | `Equipment_model`, `Workorder_model` | Fleet register, machine detail + KPIs, meter updates | Implemented |
| 5 | `Pm` | `Pm_model`, `Equipment_model` | PM schedules, completion, 12-month calendar | Implemented |
| 6 | `Inspections` | `Inspection_model`, `Equipment_model` | Template checklists EN/FR, signed records, PDF, defect automation | Implemented |
| 7 | `Backlog` | `Backlog_model`, `Equipment_model`, `Maintenance_plan_model` | Defect register, planning push to maintenance plan, Excel export | Implemented |
| 8 | `Materials` | `Materials_model`, `Spareparts_model` | Inventory dashboard/list, receive/issue, Excel import/export/template | Implemented |
| 9 | `Spareparts` | `Spareparts_model`, `Materials_model` | Pending-spares board, allocate/release/issue, availability alerts | Implemented |
| 10 | `Suppliers` | `Supplier_model`, `Materials_model`, `Order_model` | Suppliers, contacts, part catalogue, "who supplies this part?" | Implemented |
| 11 | `Orders` | `Order_model`, `Supplier_model`, `Materials_model`, `Spareparts_model` | Purchase orders, status pipeline, receiving + GRN, documents, lead-time estimate | Implemented |
| 12 | `Lubricants` | `Lubricant_model`, `Equipment_model` | Lube types/specs, consumption + variance, budget, forecast & compliance widgets | Implemented |
| 13 | `Team` | `Team_model` | Staff (= `users`) list/detail, attendance, leave, certifications, shift roster, monthly report | Implemented |
| 14 | `Users` | `Team_model` (loaded, most work via `$this->db`) | Account administration, own profile & password | Implemented |
| 15 | `Settings` | `Settings_model`, `Auth_throttle_model` | Business settings; sign-in security console | Implemented |
| 16 | `Reports` | 14 models (dashboard/WO/team/lube/equipment/order/supplier/asset/materials/store/settings/KPI target/KPI engine/W36) | 20+ reports and exports incl. W36 and the asset-register import | Implemented |
| 17 | `Forecast` | `Forecast_model`, `Maintenance_plan_model` (+ PM/materials/order/W36 on demand) | Planned availability, jobs, publish/revisions, plan-vs-actual, rolling plan, spares & allowances, workbook export | Implemented |

Views: 94 PHP files under `application/views/` (one folder per module, plus
`layouts/`, `auth/`, and custom `errors/html/`). `layouts/header-apple.php`
exists but is **not used** (`render()` loads `layouts/header.php`).

## 4. Authentication, authorization, security controls

### 4.1 Authentication (`Auth::login`, `POST /login`)

- Form validation: valid e-mail, password ≥ 4 chars (creation policy is ≥ 8 —
  see §7.10). Lookup: `users` by e-mail with `status != 'Inactive'`.
- Password verification order: (1) `password_verify()` (bcrypt,
  `PASSWORD_DEFAULT`); (2) legacy **MD5**, (3) legacy **SHA1** — steps 2–3 only
  while `legacy_password_login` is TRUE, and a successful legacy sign-in
  immediately re-hashes to bcrypt. 63 of the 66 accounts in the shipped dump
  still hold MD5-format hashes, so the switch is currently load-bearing.
- On success: throttle history cleared, `last_login` stamped, session vars
  `user_id`, `role_id` and `user_data` set — `user_data` is the whitelisted
  view of the row (`wms_session_user()`: id, role, names, e-mail, position,
  photo, signature, status, full name), **not** the row itself, so the
  password hash never reaches `ci_sessions` (K-18). The session ID is
  regenerated with destroy. Logout is **POST-only** (405 otherwise) and
  destroys the session.
- Failure messages never distinguish "locked" from "wrong password"; a failed
  `users` query (DB/schema problem) produces a specific "database is not
  answering" message instead of a 500.

### 4.2 Sign-in throttling & security events (`Auth_throttle_model`, tables `login_attempts`, `security_events`)

Window 900 s (15 min). Locked when **any** of:

- ≥ 5 failures for the same account **+** source IP;
- ≥ 20 failures for the same account from any IP;
- ≥ 30 failures from one source IP spread over ≥ 3 distinct accounts
  (credential stuffing) — only while setting `login_ip_cap_enabled = 1`.

Attempts older than 24 h and events older than 90 days are pruned
opportunistically during sign-in processing. Threshold crossings write a
`security_events` row; supervisors see a badge and manage them at
`/settings/security` (acknowledge with note, toggle the IP cap, view 24 h/7 d/
30 d counts). IP detection uses CodeIgniter's input IP; `proxy_ips` is empty
by default, so behind a proxy/CDN all visitors may share one IP (that is what
the IP-cap toggle is for).

### 4.3 Authorization — controller matrix (`MY_Controller::ACCESS_MATRIX`)

Enforced before any data loads; an unlisted controller defaults to
Supervisor-only. `✔` = may open the module.

| Controller | 1 Supervisor | 2 Technician | 3 Store | 4 Manager | 5 CEO |
|---|:---:|:---:|:---:|:---:|:---:|
| dashboard | ✔ | ✔ | ✔ | ✔ | ✔ (Executive view) |
| workorders | ✔ | ✔ | – | ✔ view | – |
| equipment | ✔ | ✔ | – | ✔ view | – |
| pm | ✔ | ✔ | – | ✔ view | – |
| inspections | ✔ | ✔ | – | ✔ view | – |
| backlog | ✔ | ✔ | – | ✔ view | – |
| materials | ✔ | ✔ | ✔ | ✔ view | – |
| spareparts | ✔ | ✔ | ✔ | ✔ | – |
| suppliers | ✔ | – | ✔ | ✔ view | – |
| orders | ✔ | – | ✔ | ✔ | – |
| lubricants | ✔ | ✔ | ✔ | – | – |
| reports | ✔ | – | – | ✔ | – |
| forecast | ✔ | – | – | ✔ view | – |
| team | ✔ | ✔ | ✔ | ✔ view | – |
| users | ✔ | – | – | – | – |
| settings | ✔ | – | – | – | – |

Additional rules in `MY_Controller`:

- **Manager is view-only**: for role 4, *any* POST — and any request to an
  action whose name matches
  `create|edit|delete|add|remove|update|receive|issue|upload|review|mark|change|save|insert|start|stop|close|generate|publish|complete|approve|release|allocate|record|submit|toggle|cancel|sync|assign|import|reconcile`
  — is blocked unless the exact `controller/action` is whitelisted:
  `orders/update_status`, `spareparts/mark_read`, `spareparts/mark_all_read`,
  `reports/publish_weekly_w36`, `reports/weekly_report_name` (POST only).
- **Self-service**: `users/profile` and `users/change_password` bypass the
  matrix for roles 1–4 (never CEO) and only ever touch the caller's own row.
- **CEO (5)** can open only `dashboard` (Executive Summary) and auth.
- Helpers: `can_write()` = roles 1/2/3; `is_supervisor()` = role 1.

Per-action restrictions inside controllers (on top of the matrix):

| Action(s) | Extra restriction |
|---|---|
| `equipment/create`, `equipment/edit` | Supervisor only |
| `pm/create`, `pm/edit` | Supervisor only (`pm/complete`: roles 1–2 via matrix) |
| `workorders/update_status` → `Closed` | Supervisor only (verification step); all status changes POST-only, row-locked transaction |
| `materials/create/edit/receive/issue/import_excel` | `can_write()` (1/2/3); `materials/delete` Supervisor + POST |
| `backlog/create/edit` | `can_write()`; `backlog/close` Supervisor + POST; `backlog/delete` Supervisor + POST |
| `spareparts/allocate/release/issue` | `can_write()` + POST; `mark_read/mark_all_read` POST |
| `lubricants/record` | Roles 1 and 3 only; `lubricants/types` POST Supervisor only; `specifications`/`budget` POST: any matrix role that can reach them (1/2/3) |
| `team/create`, `team/edit`, `team/approve_leave` | Supervisor only (approve also POST); `team/leave_request` always creates for the caller |
| `users/*` administration | Supervisor only (matrix **and** `Users::__construct`); cannot deactivate/delete/change own role; delete = deactivate |
| `settings/*` | Supervisor only (matrix **and** in-method check) |
| `reports/import_asset_register` | Supervisor + POST; weekly-target mutations POST (Manager blocked by the view-only rule, so effectively Supervisor) |
| `forecast/save_job/delete_job/create_inspection_rounds` | `can_write()` — within the forecast matrix (1/4) that means Supervisor; `generate/save_cell/publish/save_part/delete_part` POST-only, frozen-week guards apply |
| `suppliers/delete`, contact/catalog deletes | POST-only |
| `orders/update_status`, `orders/receive` (POST), `upload_document` | POST-only; transition whitelist in `Order_model` |

Alert visibility (`Spareparts_model`): supervisors, store and managers see all
spare-part alerts; technicians only alerts for WOs they created, supervise, or
are assigned to (CSV match on `assigned_to`).

### 4.4 Other security controls

- **CSRF**: global CodeIgniter protection on, token name `csrf_token_wms`,
  cookie `csrf_cookie_wms`, regenerated every request. Hand-written forms use
  the `csrf_field()` helper; `form_open()` adds it automatically. State-changing
  actions additionally enforce POST via `require_post()`.
- **Sessions**: database driver, table `ci_sessions`, cookie `wms_session`,
  expiration 7200 s, regenerate every 300 s with destroy, `HttpOnly`,
  `SameSite=Lax`, `Secure` auto-enabled when the request is HTTPS (overridable
  with `WMS_COOKIE_SECURE=0`).
- **XSS**: `global_xss_filtering` is off; controllers read most input with
  `$this->input->post(..., TRUE)` (CI's XSS filter) and views generally escape
  with `htmlspecialchars()`/`html_escape()`. Output escaping is a per-view
  discipline, not a framework guarantee.
- **SQL**: Query Builder throughout; the only raw queries are
  `SELECT … FOR UPDATE` row locks with bound parameters.
- **Uploads**: CI upload library with extension allow-lists and size caps
  (§14); `uploads/.htaccess` disables the PHP engine and denies script
  extensions; the root `.htaccess` also blocks `uploads/*.php`; `uploads/tmp/`
  is `Deny from all`. Application/system folders carry `Deny` `.htaccess`
  files. On nginx these protections must be re-created in server config.
- **Headers**: root `.htaccess` sets `X-Content-Type-Options: nosniff` and
  `X-Frame-Options: SAMEORIGIN` when `mod_headers` is available; hidden files
  (e.g. `.git`) are blocked except `.well-known`.
- **Secrets handling by design**: DB credentials/encryption key are read from
  `*.local.php` files or `WMS_*` environment variables; if none exist the app
  refuses to connect and shows a setup page. The files are git-ignored and the
  `.example` templates are the only copies in the repository; the pre-rotation
  values are still in its public history and must be rotated (K-01,
  `SECURITY.md`).
- Sessions are DB-backed (`ci_sessions`). `user_data` holds only the fields a
  view reads; the password hash is **not** stored (K-18). A session written by
  an older build is rewritten on its next request by `MY_Controller::_check_auth()`,
  which drops the extra columns.
- `base_url` comes from `WMS_BASE_URL`, then `config.local.php`, then
  auto-detection; detection accepts only a syntactically valid `host[:port]`
  and answers 400 otherwise, so a crafted `Host` header cannot be reflected
  into links (K-17).

## 5. Database

### 5.1 Overview

One MySQL/MariaDB database, 59 tables, `utf8mb4`. The schema artifact in the
repo is `database/schema.sql`: all 59 tables with their indexes and foreign
keys, plus the master data the application needs to start (5 roles,
21 equipment types, 7 lubricant types, 17 report groups, 35 report sites,
19 settings rows, 3 shifts). It contains **no staff, fleet, supplier, stock,
session or inspection records**.

The production dump it was derived from (`database/yzzme_43038742_aml.sql`,
phpMyAdmin, MariaDB 11.4, 2026-10-19) held 66 users, 116 equipment,
1,697 materials, 12,721 material transactions and 630 inspection items over
14 templates; 39 tables were empty in it, including `work_orders`,
`pm_schedules` and `spare_orders`. That file is no longer tracked (K-02) and
must not be re-added — it is a data export, not source.

There are no `CREATE DATABASE`/`USE`/`DROP TABLE` statements in either file.

`database/schema.sql` **is** the baseline schema. Changes made after a database
was created live in `database/migrations/` and are applied by
`scripts/migrate.sh` (idempotent files, recorded in `schema_migrations`); a new
installation imports only `schema.sql`. §13 lists the runtime schema probes that
let screens degrade gracefully on a database where a migration has not run yet.

Conventions: surrogate `INT` auto-increment PKs (except `ci_sessions`);
statuses are MySQL `ENUM`s (the authoritative value lists are in §5.3);
timestamps are app-written `DATETIME`s or `CURRENT_TIMESTAMP` defaults,
depending on the table's generation. Only 42 foreign keys are declared (§5.2);
many relationships are logical only and are listed as such.

### 5.2 Relationships

**Declared foreign keys (42)** — `table.column → target` (`CASCADE`/`SET NULL`
only where present; the rest are default RESTRICT/NO ACTION):

```
attendance.user_id, attendance.created_by            → users.id
backlogs.equipment_id                                → equipment.id  (SET NULL)
certifications.user_id                               → users.id
equipment.equipment_type_id                          → equipment_types.id
equipment_planned_downtime.equipment_id              → equipment.id  (CASCADE)
equipment_specs.equipment_id                         → equipment.id  (CASCADE)
incident_details.wo_id                               → work_orders.id (CASCADE)
inspection_items.template_id                         → inspection_templates.id (CASCADE)
inspection_records.template_id                       → inspection_templates.id
inspection_record_items.record_id                    → inspection_records.id (CASCADE)
inspection_record_items.item_id                      → inspection_items.id
kpi_log.equipment_id                                 → equipment.id
leave_requests.user_id, leave_requests.approved_by   → users.id
lube_budget.lube_type_id                             → lube_types.id
lube_consumption.{work_order_id, equipment_id, lube_type_id, recorded_by}
                                                     → work_orders / equipment / lube_types / users
lube_specifications.{equipment_type_id, lube_type_id}→ equipment_types / lube_types
maintenance_plan_jobs.equipment_id                   → equipment.id (CASCADE)
material_transactions.{material_id, created_by}      → materials / users
pm_completions.{pm_schedule_id, work_order_id}       → pm_schedules / work_orders
pm_part_requirements.pm_schedule_id                  → pm_schedules.id (CASCADE)
pm_schedules.equipment_id                            → equipment.id
shift_assignments.{user_id, shift_id}                → users / shifts
users.role_id                                        → roles.id
work_orders.{equipment_id, created_by, supervisor_id}→ equipment / users / users
wo_attachments.wo_id                                 → work_orders.id (CASCADE)
wo_attachments.uploaded_by                           → users.id (SET NULL)
wo_oil_records.work_order_id                         → work_orders.id
wo_sessions.wo_id                                    → work_orders.id (CASCADE)
wo_sessions.switched_to_wo_id                        → work_orders.id (SET NULL)
wo_spare_parts.work_order_id                         → work_orders.id
wo_status_history.work_order_id                      → work_orders.id
```

**Important logical relationships without FK constraints** (indexes exist on
some; referential integrity is the application's problem): `backlogs.work_order_id`,
`backlogs.inspection_item_id`, `backlogs.recurrence_of` (self),
`backlogs.{created_by, verified_by, closed_by}` → users;
`equipment.report_group` → `report_groups.code` (string match);
`equipment.location` vs `report_sites.code` (sanitised vocabulary);
`equipment_kpi_targets.{equipment_id, group_code}`;
`equipment_meter_readings.{equipment_id, work_order_id, recorded_by}`;
`forecast_snapshots.published_by`; `goods_receipts.order_id` → `spare_orders`,
`goods_receipt_lines.{receipt_id, order_item_id, material_id, movement_id}`
→ `material_transactions`; `inspection_records.equipment_id`;
`maintenance_plan_jobs.{pm_schedule_id, work_order_id, backlog_id}`;
`material_transactions.{equipment_id, supplier_id}`;
`materials.supplier_id`; `order_documents.order_id`; `order_status_history.order_id`;
`pm_part_requirements.material_id`; `spare_orders.{supplier_id, linked_wo_id, requested_by}`;
`spare_order_items.{order_id, material_id}`;
`spare_part_alerts.{wo_spare_part_id, work_order_id, material_id, received_by, read_by}`;
`supplier_contacts.supplier_id`; `supplier_materials.{supplier_id, material_id}`;
`weekly_report_names.updated_by`; `weekly_report_snapshots.published_by`;
`wo_sessions.{technician_id, created_by}`; `wo_spare_allocations.{wo_spare_part_id, work_order_id, material_id, allocated_by}`;
`work_orders.{completed_by, operator_id, verified_by, breakdown_event_id (self-grouping)}`;
`work_orders.assigned_to` is a **CSV of user IDs** in a `VARCHAR`, not a relation.

Practical consequences: deleting a supplier (`suppliers` delete is a hard
delete) orphans its orders/contacts/catalogue rows; deleting a material is
blocked by the `material_transactions` FK once movements exist; user
"deletion" is deactivation, preserving FK history.

### 5.3 Table catalogue

Notation: `NN` = NOT NULL, `AI` = auto-increment PK, `UQ` = unique,
`=x` = default. Integer display widths omitted. "Rows" = rows in the shipped
dump (production snapshot, not a design property).

#### Identity, sessions, security

**`roles`** (5 rows, seeded) — `id AI`, `name NN`, `description`.
Seeded: 1 Supervisor, 2 Technician, 3 Store, 4 Manager, 5 CEO. Role IDs are
hard-coded throughout `MY_Controller`; do not renumber.

**`users`** (66 rows) — staff **and** login accounts in one table.
`id AI`, `role_id NN →roles`, `employee_id NN UQ`, `first_name NN`,
`last_name NN`, `email NN UQ`, `password NN` (bcrypt; legacy MD5/SHA1 may
exist), `phone`, `position`, `skill_level ENUM(Junior,Senior,Expert,Specialist)=Junior`,
`photo`, `join_date`, `status ENUM(Active,Inactive,On Leave)=Active`,
`last_login`, `created_at`, `updated_at`, `signature` (path under
`uploads/signatures/`).

**`ci_sessions`** — CodeIgniter DB sessions. PK (`id`, `ip_address`),
`timestamp`, `data BLOB`. Required by the session driver.

**`login_attempts`** — `id AI`, `email NN`, `ip_address NN`, `attempted_at NN`.
Indexed for the three throttle queries + pruning. Rows are threat telemetry,
not an audit log (24 h retention).

**`security_events`** — `id AI`, `event_type NN`, `subject NN` (account or IP),
`ip_address NN`, `attempts NN=0`, `distinct_accounts`, `window_seconds NN=900`,
`details`, `created_at NN`, `acknowledged_by`, `acknowledged_at`,
`acknowledged_note`. 90-day retention (pruned opportunistically).

**`settings`** (19 rows seeded) — `id AI`, `setting_key NN UQ`,
`setting_value TEXT`, `description`. Business configuration; see §12.

#### Equipment & meters

**`equipment_types`** (21 rows) — `id AI`, `name NN`, `category`, `description`.

**`equipment`** (116 rows) — the fleet register. `id AI`,
`equipment_type_id NN →equipment_types`, `registration_no NN UQ` (fleet
number, upper-cased on write), `legacy_code`, `machine_type`, `brand`,
`model`, `year`, `serial_number`, `frame_no`, `engine_model`,
`engine_description`, `engine_serial`, `current_hours DECIMAL=0`,
`current_km DECIMAL=0`, `meter_type ENUM(Hours,Kilometres)=Hours`,
`location` (report-site vocabulary), `masterdata_source`,
`status ENUM(Available,In Maintenance,Breakdown,Standby,Retired)=Available`,
`report_group` (→ `report_groups.code`), `report_sort NN=0`,
`include_in_weekly_report NN=1`, `photo`, `purchase_date`,
`manufacture_date`, `commission_date`, `start_meter`,
`fatigue_system ENUM(Yes,No,N/A)=N/A`, `order_no`, `warranty_expiry`,
`notes`, `created_at`, `updated_at`.

**`equipment_specs`** (338 rows) — manufacturer spec sheet imported with the
asset register. `id AI`, `equipment_id NN →equipment (CASCADE)`,
`spec_key NN`, `spec_label NN`, `spec_value NN`, `sort_order NN=0`,
`created_at`, `updated_at`; UQ (`equipment_id`, `spec_key`).

**`equipment_meter_readings`** (44 rows) — meter history written only by
`record_meter_reading()`. `id AI`, `equipment_id NN`, `reading_date NN`,
`hours DECIMAL(10,1)`, `km DECIMAL(10,1)`,
`source ENUM(Manual,Import,WorkOrder)=Manual`, `work_order_id`,
`recorded_by`, `created_at`; UQ (`equipment_id`, `reading_date`) — one row per
machine-day holds whichever meter(s) were recorded that day.

**`report_groups`** (17 rows seeded: EXCAVATOR, DOZER, ADT, GRADER, BOWSER_DB,
BOWSER_WB, RIG, CRANE, FORKLIFT, GENERATOR, TOWER_LIGHT, BUS, LV, WATER_PUMP,
COMPRESSOR, WELDING_PLANT, SERVICE_VAN) — `id AI`, `code NN UQ`, `label NN`,
`sort_order NN=0`, `active NN=1`. Drives W36/forecast grouping and order.

**`report_sites`** (35 rows) — `id AI`, `code NN UQ`, `label NN`,
`sort_order NN=0`, `is_active NN=1`, `created_at`. Controlled vocabulary for
`equipment.location`; `Equipment_model::sanitize_location()` maps free text
onto it.

**`equipment_kpi_targets`** (empty in dump) — availability targets.
`id AI`, `scope ENUM(Equipment,Group) NN`, `equipment_id`, `group_code`,
`target_date` (NULL = standing default), `target_pct DECIMAL(5,4) NN`
(**stored as a fraction, 0.88 = 88 %**), `note`, `created_by`, timestamps.

**`kpi_log`** (empty; **no code references it**) — legacy per-day KPI table:
`equipment_id →equipment`, `log_date NN`, `availability_pct`, `mttr_hours`,
`mtbf_hours`, `total_downtime`, `breakdown_count`, `pm_count`.

#### Work orders

**`work_orders`** — core entity. `id AI`, `wo_number NN UQ` (`WO-AML-%03d`,
generated as last number + 1), `equipment_id NN →equipment`,
`type ENUM(Corrective Maintenance, Preventive Maintenance, Incident) NN`,
`is_breakdown NN=0` (derived from type on every save — §7.1),
`breakdown_event_id` (groups several WOs of one failure; read by the KPI
engine; the work-order detail page links/detaches it), `priority ENUM(Critical,High,Medium,Low)=Medium`,
`status ENUM(Open,In Progress,Waiting Parts,Waiting Tools,Completed,Closed)=Open`,
`hold_code ENUM(WSP,WQT,WCF,UD,UR)` (read by W36; written by the WO form and the quick status form),
`machine_status VARCHAR=Operational` (free form: Operational/Breakdown…),
`equipment_systems` (CSV), `title NN`, `description`, `symptoms`,
`root_cause`, `pending_work`, `remarks`, `planner`, `purchase_order`,
`approval`, `workplace`, `action_taken`, `hours_reading`, `start_datetime`,
`end_datetime`, `downtime_hours`, `assigned_to` (CSV of user IDs),
`created_by NN →users`, `completed_by`, `estimated_hours`, `actual_hours`,
`notes`, timestamps, `supervisor_id →users`, `operator_id`, `operator_name`,
`operator_signature` (path), `expected_return_date`, `verified_by`,
`verified_at`, `verification_notes`.

**`wo_sessions`** — labour stints. `id AI`, `wo_id NN →work_orders (CASCADE)`,
`session_no NN`, `technician_id`, `start_datetime NN`, `end_datetime`,
`hours_worked`, `work_performed`, `interruption_reason`,
`switched_to_wo_id →work_orders (SET NULL)`, `status ENUM(Open,Closed)=Open`,
`created_by`, timestamps. Application-enforced invariant: at most one Open
session per WO and per technician.

**`wo_spare_parts`** — parts requested/used on a WO (the "lines" the pending-spares
engine tracks). `id AI`, `work_order_id NN →work_orders`, `item_code`,
`part_name NN`, `part_number`, `spare_type ENUM(New,From Machine)=New`,
`source_machine`, `quantity`, `unit=pcs`, `unit_cost`, `total_cost`, `notes`.
No `material_id` column — linkage to inventory is resolved by matching
`item_code`/`part_number`/name in `Spareparts_model`.

**`wo_spare_allocations`** — stock reservations against a WO line.
`id AI`, `wo_spare_part_id NN`, `work_order_id NN`, `material_id NN`,
`qty_allocated NN=0`, `status ENUM(Allocated,Issued,Released)=Allocated`,
`allocated_by`, `allocated_at`, `issued_at`, `notes`.

**`spare_part_alerts`** — availability notifications. `id AI`,
`wo_spare_part_id`, `work_order_id NN`, `material_id`, `item_code`,
`part_name`, `qty_received`, `stock_after`,
`alert_type ENUM(Available,Partial)=Available`, `message`, `received_by`,
`status ENUM(New,Read,Dismissed)=New`, `created_at`, `read_at`, `read_by`.

**`wo_oil_records`** — free-text oil lines printed on the job card.
`id AI`, `work_order_id NN →work_orders`, `system_location`, `oil_type`,
`grade`, `quantity`. **Not** linked to `lube_types`/`materials` at entry; a
copy is posted to `lube_consumption` (with `work_order_id`) whenever the work
order is saved or edited, matching the oil type/grade to a `lube_types` row.
A line that matches nothing stays on the job card and is reported in the
flash message.

**`wo_status_history`** — `id AI`, `work_order_id NN →work_orders`,
`old_status`, `new_status`, `changed_by`, `notes`, `changed_at`. Written by
`update_status` and session start (the create/edit form does not write history
rows for status changes made there).

**`incident_details`** — one row per Incident WO (`wo_id UQ →work_orders
CASCADE`): `incident_datetime`, `site`, `department`, `location`,
`involved_party_type ENUM(Employee,Contractor,Third Party)`, `staff_id`,
`person_name`, `person_function`, `injured NN=0`, `fleet_no`,
`part_affected`, `equipment_damaged NN=0`, `description`,
`injury_damage_details`, `actions_taken`,
`activity_status ENUM(Controlled,Monitored,Uncontrolled)`,
`review_status ENUM(Submitted,Reviewed)=Submitted`, `reviewed_by`,
`reviewed_at`, timestamps.

**`wo_attachments`** — incident evidence files. `id AI`,
`wo_id NN →work_orders (CASCADE)`, `category NN` (default value truncated in
dump display; application writes `'Incident Evidence'`), `file_name NN`
(stored name in `uploads/wo_attachments/`), `original_name NN`,
`uploaded_by →users (SET NULL)`, `uploaded_at`.

#### PM, inspections, backlog, planning, forecast

**`pm_schedules`** — `id AI`, `equipment_id NN →equipment`,
`schedule_name NN`, `description`,
`interval_type ENUM(Hours,Days,Months,Km)=Hours`, `interval_value NN=250`,
`standard_hours DECIMAL(5,2)` (how long the service holds the machine —
drives forecast placement; NULL = cannot be placed), `last_done_date`,
`last_done_hours`, `next_due_date`, `next_due_hours`,
`status ENUM(Active,Inactive)=Active`, `tasks_checklist`, timestamps,
`preferred_weekday` (1–7; set on "Weekly inspection" rounds).

**`pm_completions`** — `id AI`, `pm_schedule_id NN →pm_schedules`,
`work_order_id →work_orders` (written when Mark Done names the job that did
the service — §17),
`completed_date NN`, `completed_hours`, `completed_by`, `notes`, `created_at`.

**`pm_part_requirements`** — parts a service consumes. `id AI`,
`pm_schedule_id NN →pm_schedules (CASCADE)`, `material_id NN`,
`qty DECIMAL(12,3)=1`, `is_critical NN=0`, `note`, `created_at`;
UQ (`pm_schedule_id`, `material_id`).

**`inspection_templates`** (14 seeded) — `id AI`, `code NN UQ`
(`heavy_equipment`, `lv_bus`, `heavy_vehicle`, `excavator`, `adt`, `grader`,
`dozer`, `drill_rigg`, `bus`, `hiab`, `light_vehicle`, `bowser`,
`service_van`, `forklift`), `name_en NN`, `name_fr NN`, `category_en`,
`category_fr`, `layout_type ENUM(matrix,compliance)=compliance`,
`columns_json` (matrix layouts), `meter_label_en/fr=SMR`, `active NN=1`.

**`inspection_items`** (630 seeded) — checklist lines. `id AI`,
`template_id NN →inspection_templates (CASCADE)`, `sort_order NN=0`,
`row_type ENUM(section,item)=item`, `item_no`, `category_en`, `category_fr`
(the verdict logic pattern-matches these — §7.4), `text_en`, `text_fr`,
`na_columns`.

**`inspection_records`** — submitted inspections. `id AI`,
`template_id NN →inspection_templates`, `language ENUM(en,fr)=en`,
`inspection_date`, `fleet_number`, `equipment_type`, `smr`, `serial_number`,
`model`, `equipment_id`, `general_comments`, four signatory blocks
(`mechanic/supervisor/operator/prod_supervisor` × `_name`, `_signature` path,
`_date`), `status ENUM(draft,submitted)=submitted` (the UI only ever writes
`submitted`), `created_by`, timestamps.

**`inspection_record_items`** — per-item answers. `id AI`,
`record_id NN →inspection_records (CASCADE)`,
`item_id NN →inspection_items`, `value_json` (`{"result":"Yes|No|NA"}`
for compliance layouts; per-column `ok|fail|na` for matrix layouts),
`comment`.

**`backlogs`** (44 rows) — defect register. `id AI`,
`equipment_id →equipment (SET NULL)`, `asset_label NN` (keeps free-text
assets representable), `model`, `serial_no`, `start_odometer`,
`start_date NN`, `description NN`,
`source ENUM(Inspection,Checklist,Job Card,Operator,Other)=Other`,
`reported_by`, `in_charge`, `priority ENUM(P1,P2,P3)`, `deadline`,
`observation`, `machine_status ENUM(OPERATIONAL,BREAKDOWN,STANDBY)`,
`status ENUM(Open,In Progress,Closed,Cancelled)=Open`, `end_odometer`,
`end_date`, `closing_remarks`, `mechanic`, `supervisor`, `work_order_id`,
`planned_date`, `estimated_duration` (**minutes**; converted to
hours/day when pushed to the plan), `source_ref UQ` (idempotency key, e.g.
`insp_<record>_item_<item>`), `created_by`, timestamps, `verified_by`,
`verified_at`, `verification_notes`, `closed_by`, `inspection_item_id`,
`recurrence_of` (→ earlier closed defect).

**`maintenance_plan_jobs`** — planner jobs (several per machine-day allowed).
`id AI`, `equipment_id NN →equipment (CASCADE)`, `start_date NN`,
`end_date NN`, `hours_per_day NN=0`,
`job_type ENUM(Inspection,PMService,Repair,Rebuild,Standby,Other) NN`,
`category ENUM(PM,Standing,Unplanned,OutOfService,Corrective) NN`,
`title NN`, `hold_code ENUM(WSP,WQT,WCF,UD,UR)`, `expected_return`,
`pm_schedule_id`, `work_order_id`, `backlog_id`,
`status ENUM(Planned,Issued,Done,Missed,Cancelled)=Planned`,
`source ENUM(Manual,FromPM,FromInspection,FromWorkOrder,FromBacklog)=Manual`,
`created_by`, `updated_by`, timestamps. Max span 92 days (application rule).

**`equipment_planned_downtime`** — the forecast ledger: one row per
machine · day · category. `id AI`, `equipment_id NN →equipment (CASCADE)`,
`plan_date NN`, `category ENUM(PM,Standing,Unplanned,OutOfService,Corrective) NN`,
`hours NN=0`, `source ENUM(Default,Manual,FromPM,Derived,FromPlan)=Manual`,
`source_note`, `created_by`, timestamps; UQ (`equipment_id`, `plan_date`,
`category`).

**`forecast_snapshots`** — published (frozen) forecast weeks. `id AI`,
`week_start NN`, `week_end NN`, `iso_label NN`, `revision NN=1`,
`is_baseline NN=0` (revision 1), `status ENUM(Draft,Published)=Published`,
`payload LONGTEXT NN` (JSON of the whole computed plan),
`payload_checksum CHAR(64) NN` (SHA-256), `fleet_total`,
`fleet_out_of_service`, `fleet_available`, `fleet_availability`,
`revision_note`, `published_by`, `published_at`; UQ (`week_start`, `revision`).

#### Weekly report (W36)

**`weekly_report_snapshots`** — published W36 weeks. `id AI`,
`week_start NN UQ`, `week_end NN`, `iso_label NN`, `payload LONGTEXT NN`
(JSON), `payload_checksum CHAR(64)`, `build_ms`, `published_by`,
`published_at`. At most one snapshot per week; publishing an already-published
week is refused ("already published") unless the caller explicitly forces it
(`W36_report_model::publish($week, $user, $force)` — the publish endpoint
passes a posted `force` flag), in which case the row is updated in place (§7.9).

**`weekly_report_names`** — custom display name per week.
`id AI`, `week_start NN UQ`, `report_name VARCHAR(22) NN`, `updated_by`,
`updated_at`. Locked once the week is published (controller checks
`is_published`).

#### Inventory, suppliers, orders

**`materials`** (1,697 rows) — stock master. `id AI`, `item_code NN UQ`,
`category NN=CONSUMABLE` (free text with a suggested vocabulary),
`description NN`, `part_number_size`, `unit=pcs`,
`initial_stock NN=0`, `qty_received NN=0`, `qty_sortie NN=0`,
`final_qty NN=0` (**stored derived value** — the balance is always
`initial + received − sortie`; writers recompute it, readers derive it and
the inventory screen flags rows where the stored value disagrees),
`min_stock`, `max_stock`, `unit_price_ugx`, `unit_price_usd`,
`supplier_id`, `supplier_name`, `is_active NN=1`, `application`,
`location`, `created_at`, `updated_at`. Stock columns are `INT` — **whole
units only** everywhere (§7.5).

**`material_transactions`** (12,721 rows) — movement ledger. `id AI`,
`material_id NN →materials`, `type ENUM(RECEIVE,ISSUE) NN`, `txn_date`
(business date), `reference_no` (MRN/requisition/order no), `qty NN`,
`allocation_type ENUM(Equipment,Department,Other)=Other`, `equipment_id`,
`allocation_label`, `person`, `supplier_id`, `supplier_name`,
`qty_approved`, `unit_price`, `currency CHAR(3)`,
`source ENUM(Manual,Excel Import,Order GRN,Work Order)=Manual`,
`import_key` (idempotency; **two** unique indexes exist on this column —
`uq_mt_import_key` and `uq_txn_import_key`), `affects_balance NN=1`
(historical Excel rows are flagged 0), `stock_before`, `stock_after`,
`notes`, `created_by →users`, `created_at`.

**`suppliers`** (22 rows) — `id AI`, `company_name NN`, `tax_id`,
`country NN`, `city`, `address`, `currency NN=TZS`, `payment_terms`,
`lead_time_guarantee_days`, `status ENUM(Active,Inactive,Blacklisted)=Active`,
`notes`, timestamps.

**`supplier_contacts`** — `id AI`, `supplier_id NN`, `name NN`, `role`,
`phone`, `email`, `created_at`.

**`supplier_materials`** — supplier catalogue. `id AI`, `supplier_id NN`,
`material_id`, `part_number`, `description NN`, `unit_price`,
`currency=TZS`, `moq`, `lead_time_days`, `is_preferred NN=0`, timestamps.

**`spare_orders`** — purchase orders. `id AI`, `order_no NN UQ`,
`supplier_id NN`, `country NN` (copied from supplier at creation),
`shipping_method ENUM(Air Freight,Sea Freight,Road Transport,Rail)`,
`order_date NN`, `expected_delivery_date`, `actual_delivery_date`,
`status ENUM(Pending Approval,Sent to Supplier,Confirmed,In Transit,Customs Clearance,Received at Store,Verified & Closed,Cancelled)`,
`tracking_number`, `carrier`, `currency NN=TZS`, `total_cost NN=0`,
`linked_wo_id`, `requested_by`, `notes`,
`discrepancy_flag ENUM(None,Shortage,Damage,Wrong Item)=None`,
`discrepancy_notes`, timestamps.

**`spare_order_items`** — `id AI`, `order_id NN`, `material_id`,
`description NN`, `part_number`, `unit NN=pcs`, `qty_ordered NN=0`,
`qty_received` (running total maintained by receipts), `unit_price`.

**`goods_receipts`** — one row per delivery. `id AI`, `order_id NN`,
`grn_no NN UQ`, `submit_token UQ` (one-time form token, anti double-submit),
`receipt_date NN`, `received_by`, `discrepancy_flag NN=None` (free-form
varchar in schema), `discrepancy_notes`, `notes`, `created_at`.

**`goods_receipt_lines`** — `id AI`, `receipt_id NN`, `order_item_id NN`,
`material_id`, `description`, `unit`, `qty_ordered NN=0`,
`qty_previously_received NN=0`, `qty_delivered NN=0`,
`qty_outstanding_after NN=0`, `unit_price`, `movement_id` (→ the
`material_transactions` row that posted the stock), `notes`.

**`order_status_history`** — `id AI`, `order_id NN`, `status NN`,
`changed_by`, `changed_at`, `notes`.

**`order_documents`** — `id AI`, `order_id NN`, `doc_type` (e.g. Delivery
Note), `file_name NN` (in `uploads/order_docs/`), `original_name`,
`uploaded_by`, `uploaded_at`.

**`inventory_import_logs` / `asset_import_logs`** — import audit rows:
`user_id`, `file_name`, `mode`, `duplicate_action` (+ `stock_action` for
inventory), row counters (`total_rows`, `inserted/imported`, `updated`,
`skipped`, `failed`, (+ `duplicates` for inventory)), `created_at`.

#### Lubricants

**`lube_types`** (7 rows) — `id AI`, `name NN`,
`category ENUM(Engine Oil,Hydraulic Oil,Transmission Oil,Differential Oil,Grease,Coolant,Compressor Oil,Filter,Other) NN`,
`grade`, `unit=L`, `unit_cost=0`, `description`,
`status ENUM(Active,Discontinued)=Active`.

**`lube_specifications`** — recommended quantity per equipment **type**.
`id AI`, `equipment_type_id NN →equipment_types`,
`lube_type_id NN →lube_types`, `recommended_qty NN`,
`service_interval_hours`, `notes`. (No unique key — duplicates possible;
`Lubricant_model::get_spec()` effectively takes one row.)

**`lube_consumption`** — `id AI`, `work_order_id →work_orders` (written by
the work-order oil-line sync as well as the Lubricants screen),
`equipment_id NN →equipment`, `lube_type_id NN →lube_types`,
`quantity_used NN`, `recommended_qty` (copied from spec at entry),
`variance` (= used − recommended), `variance_reason`, `consumption_date NN`,
`recorded_by →users`, `equipment_hours`, `notes`, `created_at`.

**`lube_budget`** — `id AI`, `lube_type_id NN →lube_types`, `year NN`,
`month NN`, `budget_qty`, `unit_cost`, `budget_cost`; UQ
(`lube_type_id`,`year`,`month`) — written with `REPLACE`.

#### Team / HR

**`attendance`** — `id AI`, `user_id NN →users`, `date NN`,
`status ENUM(Present,Absent,Rest,Leave,Holiday)=Present` (the entry UI offers
Present/Absent/Leave/Rest), `check_in`, `check_out`, `hours_worked`
(not computed by the UI), `notes`, `created_by →users`, `created_at`;
UQ (`user_id`,`date`) — saving a day uses `REPLACE` inside a transaction.

**`leave_requests`** — `id AI`, `user_id NN →users`,
`leave_type ENUM(Annual Leave,Sick Leave,Emergency,Unpaid,Maternity,Paternity,Training) NN`,
`start_date NN`, `end_date NN`, `days_count` (inclusive calendar days),
`reason`, `status ENUM(Pending,Approved,Rejected)=Pending`,
`approved_by →users`, `approved_at`, `notes`, `created_at`. Approval writes
`attendance` rows (`status = Leave`, marker in `notes`) for every day of the
request and removes exactly those rows when the decision is reversed.

**`certifications`** (1 row) — `id AI`, `user_id NN →users`, `cert_name NN`,
`cert_number`, `issued_date`, `expiry_date`, `issuing_body`, `document`
(PDF/JPG/PNG under `uploads/documents/`, written by the Team certification
form), `status ENUM(Valid,Expired,Expiring Soon)=Valid` (recomputed from the
expiry date by `Team_model::refresh_cert_status()`, so a stored status cannot
outlive the certificate), `created_at`.

**`shifts`** (3 rows) — `id AI`, `name NN`, `start_time NN`, `end_time NN`,
`description`. **`shift_assignments`** — `id AI`, `user_id NN →users`,
`shift_id NN →shifts`, `week_start NN` (ISO Monday). Both are edited from
Team → Shift Roster: shift types by supervisors, and one assignment per
worker per week (the table has no unique key, so the screen deletes the
existing row before inserting).

#### Migration/history artefacts

**`excel_migration_log`** (7 rows; **no code references it**) — audit of the
original Excel→DB data migration: `batch NN`, `source_file NN`,
`source_sheet NN`, `target_table NN`, `rows_read/loaded/skipped`, `notes`,
`run_at`. Keep for provenance of the ≈12.7k historical movements.

## 6. The KPI engine (definitions that everything else obeys)

Implemented as pure functions in `application/helpers/kpi_helper.php`
(autoloaded) and orchestrated by `Kpi_engine_model` (dashboards, equipment
page), `W36_report_model` (weekly report) and `Forecast_model` (plan). Inputs
per machine and period:

- **Open hours** — `settings.daily_open_hours` (default 24) × days; a 7-day
  period uses `settings.weekly_open_hours` (default 168) when set.
- **Preventive time** — actual labour hours on Preventive-Maintenance WOs =
  Σ `wo_sessions.hours_worked` of closed sessions in the period. (Sessions,
  not `downtime_hours`, are the source.)
- **Required time** = open hours − preventive time.
- **Breakdown intervals** — from WOs where `is_breakdown = 1`, using
  `start_datetime`→`end_datetime` (still-open WOs clamped to the period end),
  **merged as a union** so overlapping/touching WOs of one failure never
  double-count. Multiple WOs sharing `breakdown_event_id` count as one
  **event**; a WO with NULL event id is its own event.
- **Downtime (Hours Down)** = hours of the union ∩ period.
- **Number of Breakdowns** — per `settings.breakdown_count_mode`:
  `start_in_period` (default; events whose start ∈ period) or `overlapping`.
- **Number of Days Down** — calendar days from breakdown date to restoration
  date (or period end while still down); same-day = 1; **not capped** by the
  period length.
- **Availability** = (required − downtime) ÷ required, clamped 0…1
  (`kpi_availability()`). Null when required = 0 (cards display 100).
- **MTTR** = downtime ÷ events. **MTBF** = (required − downtime) ÷ events.
  With zero events both are null; display follows
  `settings.mtbf_zero_failure_mode` (`dash` = show "–"/0, `uptime` = show
  uptime as MTBF).
- **Roll-ups** (`kpi_rollup()`) — group/fleet figures are recomputed from
  summed required/downtime/preventive totals, never by averaging machine
  percentages. (The forecast can additionally show the historical Excel
  unweighted mean as a labelled *reference* when
  `forecast_show_excel_reference = 1`.)
- **Targets** (`Kpi_target_model::resolve()`) — per machine-day, first match
  wins: equipment+day → equipment default → group+day → group default →
  `settings.default_availability_target` (default 0.88). Stored as fractions;
  the UI accepts "88" and normalises. Variance is reported in percentage
  points.
- **What counts as a breakdown WO** — `work_orders.is_breakdown = 1`.
  The WO forms never post it; `Workorder_model` derives it on every
  create/update as `type = 'Corrective Maintenance'` (a caller may set the
  flag explicitly). `wo_helper.php` (`wo_is_breakdown()`, `wo_bucket()`) is
  the PHP-side predicate and falls back to the corrective type for rows
  lacking the flag.
- **Dashboard windows** — cards use a trailing 30-day window ending today
  (`Kpi_engine_model::DASHBOARD_WINDOW_DAYS`).

A machine whose meter basis for judging PM currency is stale is reported as
**not judged** rather than guessed (`settings.pm_meter_stale_days`, default
30; 0 disables) in the W36 PM-service KPI.

## 7. Business rules by domain

### 7.1 Work orders & labour sessions

- Creation (`workorders/create`): number = `WO-AML-` + zero-padded
  (last sequence + 1) — derived from the most recent `WO-AML-%` row, not a
  counter table (concurrent creates rely on the `wo_number` unique key).
  `status` defaults to `Open`; `downtime_hours` is recomputed from
  start/end datetimes when both are present. A Corrective WO immediately sets
  its equipment's status to `Breakdown`. Spare-part lines, oil lines,
  incident details + evidence, and the operator's drawn signature (base64 PNG
  → `uploads/signatures/`) are saved with the WO. Create and edit validate
  server-side before anything is written: machine + non-empty title + a valid
  `type`/`status` on create, and title + `type`/`priority`/`status`/
  `machine_status` on edit. A rejected insert/update aborts — no child rows are
  written and the page does not claim success (K-09).
- Spare-part lines are **upserted** (matched by item code, else part name) so
  row IDs — and therefore stock reservations/alerts referencing them — survive
  edits; lines removed from the form are deleted (their reservations become
  inert). Oil lines are delete-and-reinsert.
- Status lifecycle: `Open → In Progress → (Waiting Parts | Waiting Tools) →
  Completed → Closed`. Transitions are **not** whitelisted in code — any
  posted status is accepted by `update_status` — except:
  - `In Progress` stamps `start_datetime` if empty;
  - `Completed` (the workshop says "done") stamps `end_datetime` +
    `completed_by`, closes any open labour session, sets `actual_hours` =
    Σ session hours (manual value only when no sessions), recomputes
    `downtime_hours` = now − start, returns the equipment to `Available`, and
    **auto-issues** all still-reserved spare allocations (books ISSUE
    movements);
  - `Closed` (verification) is **Supervisor-only**, stamps
    `verified_by/verified_at/verification_notes`, and closes any Open/
    In-Progress backlog defects pointing at this WO (recording the verifier).
  The edit form can also set `Completed/Closed`; it stamps `completed_by`
  once and auto-issues allocations on the transition, but it does **not**
  apply the supervisor-verification rule, verification stamps, backlog
  closing, or equipment-status sync — those live only in `update_status`.
- `update_status` and session start/close run in transactions with
  `SELECT … FOR UPDATE` on the WO (and on the technician for session start).
- Labour sessions: starting a session for a technician closes their open
  session on any other WO (marked `switched_to_wo_id`); a WO may have one
  open session; first session on a WO moves it to `In Progress` + stamps
  start; close computes `hours_worked` from timestamps. Each session can
  print its own job card.
- Incidents: structured HSE fields (`incident_details`, one per WO),
  multi-file evidence (gif/jpg/jpeg/png/pdf/doc/docx ≤ 8 MB each, generated
  filenames) into `uploads/wo_attachments/`; a supervisor "review" stamps
  `review_status=Reviewed`. Evidence files whose DB row fails to save are
  deleted from disk again. Attachment download is fixed (K-04).
- AJAX helpers: `api_spare_lookup?code=` returns part name/number/unit and
  stock (= initial+received−sortie); `api_stock_check?code=&qty=` warns when
  qty > stock. Both degrade to `ok` when the item/table is absent.

### 7.2 Equipment

- Registration number is upper-cased and unique; create/edit are
  Supervisor-only; photo upload gif/jpg/jpeg/png ≤ 5 MB to `uploads/photos/`.
- `location` is sanitised against the `report_sites` vocabulary;
  `report_group`/`report_sort`/`include_in_weekly_report` are sanitised
  against `report_groups` and decide W36/forecast membership and ordering
  ("reportable" = included **and** `status != Retired`).
- The create/edit forms write `current_hours`/`current_km` **directly**
  (master-data entry); all *operational* meter changes go through the meter
  writer (§7.3). The detail page shows WO history (20), PM schedules, spec
  sheet, and 30-day KPIs from `Kpi_engine_model`
  (availability/MTTR/MTBF/downtime/breakdowns/target/variance).
- Equipment `status` automation (§17): every work-order path that changes
  status — create, edit and the quick status form — runs
  `Workorders::_sync_equipment_status()`. A corrective WO puts the machine in
  `Breakdown`; `In Progress` on a planned job puts it in `In Maintenance`; a
  completed/closed job releases the machine to `Available`. The machine then
  follows the strongest remaining claim from other open work orders on it (an
  open breakdown keeps it `Breakdown`, an open planned job keeps it
  `In Maintenance`), so closing one job cannot mark a machine available while
  another is still down. Going *backwards* (e.g. `In Progress` → `Open`) is
  still a manual correction on the equipment form, and a `Retired` machine is
  never revived by work-order activity (`Equipment_model::update_status()`
  validates the enum and guards retirement).

### 7.3 Meter readings — `Equipment_model::record_meter_reading()`

The single writer, used by `equipment/update_hours`, PM completion and
inspection save (SMR). Rules: value > 0; unit defaults to the machine's
`meter_type` unless the caller forces `Hours`/`Kilometres`; never backwards;
jump > 24 h/day (hours) or 1,500 km/day (km) since the previous reading is
refused; same-day row is updated in place (downward same-day change needs
override); `equipment.current_*` follows the latest reading and only moves
up — unless `override` ("This is a correction", equipment screen only) is
given, in which case the recorder is stored on the row. A refusal never
blocks the caller's main action (PM completion/inspection still save; the
message is surfaced). PM completion calls the writer for `Hours` and `Km`
schedules — translating `Km` to `Kilometres` only at the writer — so both
intervals record a reading (K-05 resolved).

### 7.4 PM schedules, inspections, backlog

**PM** (`Pm` controller): create/edit Supervisor-only. Next due date:
`Days` → +interval days; `Months` → +interval months; `Hours`/`Km` → an
**estimate of +30 days** from last-done (and `next_due_hours` = last + interval
for Hours and Km). Edit recomputes next-due fields only when interval or
last-done changed (protects forecast placement). `standard_hours` is clamped
0–999.99, blank → NULL. Completion (planner modal posts `pm_id` in the body;
URI form also accepted) inserts a `pm_completions` row — naming the optional
same-machine work order in `work_order_id` — advances last/next fields, and
records the completion meter reading (§7.3). A blank meter box leaves the
previous `last_done_hours`/`next_due_hours` untouched rather than NULLing
them; a work order from another machine aborts the whole completion with a
message.
`Pm_model::get_12month_forecast()` feeds the calendar view.

**Inspections**: templates are data (`inspection_templates`+`inspection_items`,
bilingual EN/FR; the UI language is kept in the session). Two layouts:
`compliance` (per-item Yes/No/NA) and `matrix` (per-column ok/fail/na from
`columns_json`). Save (create or edit by hidden `record_id`) stores answers as
JSON, keeps existing signatures when a signatory doesn't re-sign, attributes
new records to the signed-in inspector (edits preserve the original), records
the SMR as a meter reading when equipment is linked, and then runs the defect
engine. Templates are seed/dump data *and* editable from Inspections →
Manage Templates (supervisors): the editor creates/edits/deletes a template
and its bilingual rows, refuses invalid codes/layouts, keeps rows that a
submitted record references, and only deletes a template with zero records.
Rows already used by a record keep their `item_id`, so historical inspections
stay readable.

Defect engine (`Inspection_model::process_backlog_findings()`), run on every
save (idempotent via `backlogs.source_ref = insp_<record>_item_<item>`, UQ):
an item **fails** when its result is `No` (compliance) or any column is
`fail` (matrix). Priority/machine-status come from the item's category text:
contains *No-Go* (or FR *arrêt/interdit/non conforme*) → **P1 + BREAKDOWN**;
*Go & Fix* (or *corriger/conforme*) → **P2 + OPERATIONAL**; otherwise P3.
For each item, per machine (`equipment_id` + `inspection_item_id`):
failed + already-open defect → append a "re-seen" note to `observation` and
escalate priority (never down); failed + previously closed → new defect with
`recurrence_of`; failed + none → new Open defect (source `Inspection`,
description = item text in the record language + comment); **passing now →
closes** the open defect(s) for that item, verified by the inspector. Items
without a linked machine (`equipment_id` empty) still create defects but
cannot match/annotate/close existing ones.

Inspection PDF (`inspections/pdf/<id>`): generated in pure PHP
(`Inspection_pdf` + `Pdf_writer`, PDF 1.4, WinAnsi text, optional GD signature
images, optional zlib) and streamed inline; failures render an explanatory
HTML error instead of a 500.

**Backlog**: required fields asset, start date, description; end ≥ start;
status `Closed` without end date uses today; free-text asset labels are
resolved to a machine when they match a registration number. `days_down`
(shown/exported) is computed in the model from start date (and end date when
closed). Closure policy mirrors work orders (§7.1): Supervisor-only; a defect
with an unfinished WO cannot be closed from the backlog (close the WO
instead — which closes the defect with verifier stamps). Planning fields:
when `planned_date` + `estimated_duration` (minutes) are set on a
machine-linked defect, a `maintenance_plan_jobs` job (`Repair`/`Corrective`,
source `FromBacklog`, hours/day = minutes ÷ 60, single day) is created or
updated; clearing them deletes the job. Excel export reproduces the two
legacy sheets ("Backlog on going" / "Backlog done").

### 7.5 Inventory (store) — `Materials_model`

- Balance invariant: **stock = initial_stock + qty_received − qty_sortie**
  (integers). `final_qty` is a stored copy recomputed by every writer; the
  inventory UI has a stock-check that surfaces rows where they disagree.
- `add_stock_movement()` — the only way stock moves at runtime. Rules:
  positive **whole** quantities only (fractions refused, never rounded);
  row locked `FOR UPDATE`; ISSUE may not take the balance below zero unless
  the caller passes `allow_negative` (used by allocation auto-issue paths
  that record the authoriser); balance columns are written as literal values
  computed from the locked row; a `material_transactions` row logs
  type/qty/`stock_before`/`stock_after`/user and the register context
  (`txn_date`, `reference_no`, allocation, person, supplier, price, currency,
  `source`, `import_key`). A supplied `import_key` makes the call idempotent
  (returns `skipped` with the original before/after) — used by GRN posting
  (`grn:<order>:<token>:item:<id>`) and imports.
- Opening stock (`log_opening_stock`/`set_opening_stock`) logs a movement
  without touching `qty_received` (it already lives in `initial_stock`).
- Manual receive/issue (`materials/receive`, `materials/issue`): whole qty ≥ 1;
  issue refuses qty > balance. Receive additionally runs the pending-spares
  receipt matcher (§7.6) and announces matched WOs. Register context: issues
  can be charged to an equipment (allocation label becomes its registration
  number), a department label, or Other, with a collecting person; receipts
  capture supplier (matched to `suppliers` by exact company name), approved
  qty, unit price and a currency the operator picks (default: the site
  currency from `settings.currency`, K-11).
- Item edit may change the three stock columns directly (Supervisor/Store/
  Technician with write access); every change is logged as an adjusting
  movement ("…adjusted via item edit") and a negative result is warned about,
  not blocked.
- Low-stock: model threshold `LOW_STOCK = 5` (out ≤ 0, low ≤ 5) for badges;
  the **reorder report** instead uses per-item `min_stock`/`max_stock`
  (`get_reorder_items()`; requires the extended columns, present in the dump).
- Delete is a hard `DELETE` (Supervisor, POST) — the
  `material_transactions` FK makes it fail once the item has movements.
- **Excel**: export (stock list + a re-importable "Import Data" sheet),
  generated template, and import. Import rules are centralised in
  `Inventory_importer` (the same validator backs manual create/edit):
  canonical columns, header matching insensitive to case/spaces/punctuation,
  required item code + description, whole-number stock, known category/unit
  unless explicitly allowed to create, duplicate-code policies
  `skip|update|fail` (default skip), stock handling `ignore|receive|set`,
  optional dry-run ("validate" mode), ≤ 5,000 rows, all writes in one
  transaction with rollback reporting. The full per-row result is parked as
  JSON in `uploads/tmp/` (token in flash-session, owner-checked, 24 h
  expiry) and downloadable as an Excel import report with a correctable
  "Failed Rows" sheet. Every import writes an `inventory_import_logs` row.

### 7.6 Pending spare parts — `Spareparts_model`

Answers "which pending jobs can proceed now that parts arrived?".

- Demand lines = `wo_spare_parts` of WOs not Completed/Closed. Each line is
  resolved to a material by exact `item_code`, else part-number, else name
  matching (`resolve_material()`/`get_material_index()`); unresolvable lines
  are listed as **Unlinked**.
- Per line: required = line quantity; allocated/issued from
  `wo_spare_allocations`; **free qty** = balance − Σ active reservations for
  that material (across all WOs). Line status: `Allocated` (fully reserved),
  `Available` (free ≥ still-needed), `Partial` (0 < free < needed),
  `Pending` (free = 0), `Unlinked`. On-order quantities (open
  `spare_order_items` outstanding per material) are shown alongside.
- `allocate` reserves free stock (cannot exceed free or still-needed;
  transaction with row locks); `release` frees a reservation; `issue` books
  an ISSUE movement through the single stock writer and marks the allocation
  Issued. When a WO completes/closes, `auto_issue_for_wo()` issues everything
  still reserved for it.
- `process_receipt()` runs after any stock receipt (manual receive and order
  GRN): for each waiting line of that material whose coverability **improved**
  (free-before < needed ≤ free-after → `Available`; free increased but still
  short → `Partial`) it inserts a `spare_part_alerts` row. No alert is
  created when the line was already coverable. Alerts surface in the top-bar
  bell (count via `MY_Controller::render`), the dashboard board, and
  `/spareparts/alerts` (mark read / mark all read, POST-only).

### 7.7 Suppliers & purchase orders

- Order header copies `country` and default `currency` from the supplier;
  initial status `Pending Approval`; expected delivery = order date +
  estimated lead time unless given. Lead-time estimation
  (`Order_model::get_estimated_lead_time()`): measured average
  `actual_delivery_date − order_date` for the same country + shipping method
  over Received/Closed orders when ≥ 3 samples exist ("history"); else the
  supplier's `lead_time_guarantee_days` ("supplier_quote"); else 30 days
  ("default"). The same estimate powers `/orders/api_lead_time_estimate`,
  overdue/due-soon lists, on-time rates, spend by supplier and lead time by
  route (Reports → Store Analytics, CEO dashboard).
- Status pipeline is a strict whitelist (`Order_model::$TRANSITIONS`):
  `Pending Approval → Sent to Supplier → Confirmed → In Transit →
  (Customs Clearance) → Received at Store → Verified & Closed`, with
  `Cancelled` reachable from every non-final state; final states have no
  exits. Re-posting the current status is a no-op. Changes are POST-only,
  row-locked, and logged to `order_status_history`; tracking number/carrier
  can be updated in the same post. Managers may perform this one write
  (approval step).
- **Receiving** is separate from status changes. Per delivery: quantities per
  line must be numeric, ≥ 0, ≤ outstanding; an all-zero delivery is refused;
  Cancelled/Closed orders receive nothing; quantities for catalogue-linked
  lines must be whole units. Each line posts stock via `add_stock_movement`
  with `source='Order GRN'` and a per-form `import_key`; the rendered form
  carries a one-time 32-hex `submit_token` (also UNIQUE in
  `goods_receipts`), so a double-submit is refused as "already recorded".
  The GRN row + lines snapshot ordered/previously/delivered/outstanding-after
  and the movement ID. First delivery stamps `actual_delivery_date`;
  discrepancy flag/notes are copied to the order; a fully-received order
  auto-advances to `Received at Store`, partial deliveries only log history.
  Receipts also run the pending-spares matcher (§7.6). `/orders/grn/<id>[/<receipt_id>]`
  prints the latest or a specific GRN (receipt must belong to the order);
  orders received before GRN rows existed fall back to an order-level print.
- Documents: upload (gif/jpg/jpeg/png/pdf/doc/docx ≤ 8 MB) to
  `uploads/order_docs/`; download fixed in this revision (K-04).
- Supplier deletion is a hard delete with no reference checks (§5.2).

### 7.8 Lubricants

- Recording consumption (roles 1/3): looks up the spec by the machine's
  equipment **type** + lube type; stores `recommended_qty` and
  `variance = used − recommended` (NULLs when no spec). No stock is decremented
  — lubricants are not linked to `materials`.
- Requirement model (`get_pm_driven_requirement(from,to,avg_daily_hrs=20)`):
  for each Active PM schedule due in the window — by `next_due_date`, or for
  Hours-intervals by projecting `(next_due_hours − current_hours) ÷ 20 h/day`
  from today — add every lube spec of that machine's type once. Compliance =
  required vs Σ `lube_consumption` in the window (variance and %); yearly
  rollup repeats per month. The 20 h/day projection is a hard-coded default
  parameter, not a setting.
- Budget: one row per (lube type, year, month), `budget_cost = qty × unit_cost`
  computed on save; CEO dashboard shows used vs budget for the current month.
  The lubricants "forecast" page shows this and next month's PM-date-driven
  requirement (date check only).

### 7.9 Weekly report (W36)

`W36_report_model::build(week_start)` produces the single array every W36
consumer reads (screen `reports/weekly_w36`, Excel via `W36_excel`, print
HTML, Forecast's plan-vs-actual actuals):

- Population: reportable machines (§7.2), ordered by `report_groups.sort_order`
  then `equipment.report_sort`/registration; site labels from `report_sites`.
- Per machine: 7 day blocks (per-day availability, downtime, preventive
  hours, breakdowns, target) + weekly roll-up incl. **Number of Days Down**;
  per-group blocks and a fleet row via `kpi_rollup()`; a per-type block;
  PM-service KPI (schedules due/done with the stale-meter "not judged" rule);
  inspection register for the week; a **Synthèse** (summary) with an action
  plan built from open WOs/backlogs (`pending_work`/`action_taken` text and
  the WO `hold_code`, written by the work-order screens) and KPI synthesis; plus a
  data-quality list explaining anomalies (missing targets provenance etc.).
- Week resolution honours `report_week_start_day`; URL input accepts ISO
  labels (`2026-W36`) validated against real ISO weeks, or a date.
- **Publish** (`reports/publish_weekly_w36`, Supervisor or Manager): stores
  the JSON payload + SHA-256 + build time in `weekly_report_snapshots`;
  afterwards `report_for_week()` serves the frozen snapshot (checksum-verified)
  unless `?live=1` is passed to the print/PDF endpoints. The week's display
  name (`weekly_report_names`, ≤ 22 chars) can be set until publication.
- Excel export reproduces the approved workbook geometry (sheet `W36`, group
  blocks, 7 day blocks, weekly block, per-type block, logo from
  `uploads/logoam.png` when present). All cells come from `build()`.

### 7.10 Team, users, settings

- Team screens operate on the same `users` rows. Attendance save writes the
  whole day atomically: statuses limited to Present/Absent/Leave/Rest/Holiday,
  times validated `HH:MM`, unknown user IDs or any invalid row roll the whole
  day back. An existing day for the same `(user_id, date)` is updated in place
  (never REPLACE), so its `hours_worked` and notes survive. Monthly report
  counts present/absent/leave/rest/holiday per worker; the attendance
  percentage divides by the days actually judged (present + absent + leave),
  so weekends and public holidays no longer cap it below 100%. `hours_worked`
  is stored but never computed by the UI.
- Leave: days = inclusive calendar days (min 1); submitting a request that
  overlaps a pending/approved one is refused. Approval runs one transaction:
  the request row plus an `attendance` row (`status = Leave`, notes
  `Auto: approved leave request #<id>`) for every day, skipping a day the
  person actually worked. Rejecting/reverting removes exactly the rows it
  wrote. Balances per type come from the `leave_entitlements` setting
  (Annual 21 / Sick 10 / Emergency 3 by default); an untracked type shows
  "—". A request is never blocked by the balance — the requester is warned
  and the supervisor decides.
- Certifications: adding and deleting a certificate is supervisor-only (the
  worker page's Add dialog is hidden from other roles and the endpoint refuses
  them); a certificate's document is deleted only after the row delete
  succeeds. `Team_model::refresh_cert_status()` recomputes the stored status
  from `expiry_date` (Expired / Expiring Soon within `cert_alert_days` = 30 by
  default / Valid) — on the profile and on the tracker, so "Valid" can never
  outlive the certificate. Documents (pdf/jpg/png ≤ 8 MB) are stored under
  `uploads/documents/` and linked from both screens; deleting a certificate
  removes its file.
- Shift roster: week keyed by its ISO Monday; shift types are `HH:MM`, start
  ≠ end; deleting a shift still assigned to anyone is refused; assignments
  are one row per worker per week (delete-then-insert, the table has no
  unique key).
- Signature upload (Team create/edit) converts the image to a transparent PNG
  by removing dark backgrounds pixel-by-pixel; when GD is unavailable the
  upload is stored unchanged instead of fatal-erroring (K-15).
  Profile signatures are drawn on a canvas and saved as `sig_user_<id>.png`.
- Users administration validates unique e-mail/employee ID and password ≥ 8 on
  create; role changes accept IDs 1–5; self-deactivation/self-role-change/
  self-delete are refused; delete = set `Inactive` (sign-in then refuses the
  account). Own password change requires the current password (bcrypt verify
  only — a legacy-hash account must sign in once, which upgrades it, before
  it can change its password here). `Team::create/edit` is a second path onto
  the same table and now applies the same checks (required names, valid and
  unique e-mail, unique employee ID on create, password ≥ 8, a real role); both
  actions are supervisor-only and a failed write keeps the form and its values
  on screen (K-09).
- Settings screen writes through `Settings_model::save_many()`, which only
  accepts keys in `Settings_model::DEFAULTS` or already present in the table,
  and coerces each known key to its documented range (§12).

### 7.11 Planned-availability forecast & maintenance plan

The forecast expresses a **plan in the same metric as the actual**: planned PM
hours reduce required time, planned Standing + Unplanned + Corrective hours
are downtime, `OutOfService` removes the machine from the population for
those days (counted in the unit view instead). All figures come from
`kpi_availability()`/`kpi_rollup()`; historical-Excel reference figures
(24 h divisor, unweighted mean) are shown only when
`forecast_show_excel_reference = 1`. Known bias, documented in the code:
actuals never record Standing (daily-check) time, so plan-vs-actual splits
the variance by category.

- Ledger: `equipment_planned_downtime` (machine·day·category, §5.3) with
  `source` ∈ Default/Manual/FromPM/Derived/FromPlan. Generators **never
  overwrite** `Manual` or `FromPlan` rows ("protected sources") and never
  charge allowances on days the machine is held out of service.
- Generators (`forecast/generate`, idempotent, per week):
  1. weekly inspection placement (below), 2. plan-job sync, 3. PM placement —
     schedules due in the week by date, or by meter projection
     (hours/day from readings inside `pm_utilisation_lookback_days`, else
     `pm_default_hours_per_day` per type, else the schedule's stored
     `next_due_date` marked "estimated"); schedules without `standard_hours`
     or any due basis are reported, never guessed; 4. standing allowance —
     per-type JSON setting else default 2 h/day; 5. unplanned allowance —
     `flat` (default 1 h/day) or `derived` per machine from its corrective
     history over `unplanned_allowance_lookback_weeks` (the Allowances screen
     previews the derivation before anything is written).
- Maintenance-plan jobs (`maintenance_plan_jobs`) are the planner's explicit
  jobs; `Maintenance_plan_model::sync_week()` derives ledger rows
  (`FromPlan`) as Σ `hours_per_day` per machine·day·category, leaves Manual
  rows winning (reported as overridden cells), replaces generated rows, and
  deletes orphaned FromPlan rows. Jobs validate span ≤ 92 days, known
  type/category/status/hold code; job type suggests a default category
  (Inspection/PMService→PM, Repair→Corrective, Rebuild→OutOfService,
  Standby→Standing, Other→Unplanned).
- Weekly inspection rounds are `pm_schedules` named `Weekly inspection`
  (Days/7) with `preferred_weekday` and `standard_hours` from settings
  (`inspection_default_hours` default 1, per-type overrides).
  `create_inspection_rounds` adds one per reportable machine lacking one,
  load-levelling weekdays; generation places a job on the round's weekday
  unless the machine is on Standby or planned out that day/week (skips are
  reported by name); an `inspection_records` row inside the week later marks
  the job Done (`sync_inspection_status`).
- Overbooking: a machine-day whose planned hours exceed the day's open hours
  produces a warning. Publishing with warnings requires an acknowledgement
  note, which is frozen into the snapshot.
- **Publish/freeze**: revision 1 becomes the baseline; later revisions require
  an explanatory note and never replace the baseline. While a baseline exists
  for a week, `generate`, `save_cell`, job save/delete and inspection
  generation touching that week are refused. `plan_vs_actual` compares the
  **baseline** plan against actuals from `W36_report_model::report_for_week()`
  (group + fleet roll-ups); when the weekly report can't be built the screen
  says so instead of showing zeros.
- Rolling plan: the same computation for weeks +0/+1/+2 in one grid.
- PM spares forecast (`forecast/spares?weeks=1..13`, default 4): from
  `pm_part_requirements` of schedules due in the horizon vs current stock
  and on-order quantities, with measured supplier lead times by route;
  schedules without requirements are listed as configuration gaps.
  Requirements are maintained at `/forecast/parts/<pm_schedule_id>`.
- Workbook export (`forecast/export/planned|rolling`): generated from the
  same model calls as the screens; the plan-vs-actual sheet is included only
  for published weeks. Files are written to the PHP temp dir under a
  deterministic name (K-19).

## 8. End-to-end workflows

**W1 — Breakdown repair.** Operator/supervisor creates a Corrective WO
(equipment → `Breakdown`; `is_breakdown` derived) → technician starts a labour
session (WO → In Progress) → parts requested on the WO appear on the pending
spares board → store allocates/issues (§7.6) → session closed (hours booked)
→ status `Completed` (equipment → Available, reserved stock auto-issued,
downtime computed) → supervisor verifies → `Closed` (linked defects close
with verifier) → the downtime interval now feeds dashboard KPIs and the W36
week containing it.

**W2 — Inspection → defect → repair.** Inspector fills a checklist (EN/FR) →
failed No-Go item raises a P1 backlog defect (machine BREAKDOWN) → planner
sets planned date + duration → a Corrective job appears in the maintenance
plan/forecast → work is done (raise/link a WO that references the defect via
`backlogs.work_order_id` — set in the data, there is no dedicated "create WO
from backlog" button; the linkage is by editing records) → WO Closed by
supervisor closes the defect; alternatively a later inspection passing the
same item closes it, or the supervisor closes it directly once its WO is
finished.

**W3 — PM cycle.** Schedule created (interval + standard hours + part
requirements) → dashboard/PM planner show due/overdue (next 14 days on the
dashboard) → forecast places `standard_hours` on the projected due date →
on completion, `pm/complete` logs the completion, advances the schedule and
records the meter reading → sessions booked against a Preventive WO for the
service reduce required time in availability.

**W4 — Stock receipt → job unblocked.** Store receives stock (manual receive
or order GRN) through the single stock writer → matcher finds waiting WO
lines for that item → `Available`/`Partial` alerts appear (bell, dashboard,
alerts page, scoped per §4.3) → store allocates free stock to the WO line →
issues it at hand-out (ISSUE movement) → WO proceeds; leftovers auto-issue
when the WO completes.

**W5 — Procurement.** Order created against a supplier (expected date from
lead-time estimate) → pipeline: approval (Manager may advance) → sent →
confirmed → in transit → customs → deliveries received line-by-line (stock
posted, GRN printed per delivery) → fully received ⇒ `Received at Store` →
store verifies ⇒ `Verified & Closed`. Delivery documents attach to the
order. Measured lead times from closed orders improve future estimates.

**W6 — Weekly reporting.** During the week: WOs, sessions, inspections and
meter readings accumulate. Reporter opens `/reports/weekly_w36?week=2026-W36`,
reviews machine/group/fleet KPIs, PM and inspection registers, synthèse and
data-quality notes → optionally renames the report → exports the workbook or
print view → **publishes** to freeze the week (later views serve the snapshot;
`?live=1` on print endpoints recomputes). Availability targets for the week
are maintained beforehand under `/reports/weekly_targets` (per machine/group,
bulk apply, copy week) by a supervisor.

**W7 — Forecast week.** Planner opens `/forecast?week=…` → creates inspection
rounds if missing → `Generate` (inspections, job sync, PM, allowances;
manual cells preserved) → adjusts jobs (`/forecast/jobs`) and individual
cells → checks overbooking warnings and unplaceable PM list → `Publish`
(baseline frozen) → during/after the week, `/forecast/plan_vs_actual` and the
3-week `/forecast/rolling` view drive the next cycle; revisions require a
note and keep the baseline.

**W8 — Inventory import.** Download template → fill → import with dry-run
(validate) → fix rows from the downloadable report if needed → import for
real (single transaction) → audit row in `inventory_import_logs` + import
movements (`source='Excel Import'`, `import_key` dedupes re-runs). The asset
register import (`/reports/asset_register`, supervisor) follows the same
pattern into `equipment`/`equipment_types`/`equipment_specs`
(`Asset_model`, `Asset_importer`; duplicates skip by default, blanks never
overwrite on update; new types only when allowed).

**W9 — Account lifecycle.** Supervisor creates the account (Users → bcrypt,
role) → user signs in (throttling applies; legacy hash upgraded if applicable)
→ user maintains profile/signature/password → supervisor deactivates to end
access (history preserved). Sign-in attacks surface at Settings → Sign-in
Security for acknowledgement.

## 9. Routes and endpoints

Default controller: `dashboard`. `routes.php` adds `login`/`logout` aliases
and per-module patterns, but plain CodeIgniter routing
(`/controller/method/arg…`) resolves every public method. All endpoints below
require authentication except `login`. `POST` marks actions that mutate on
POST (many also render a form on GET); POST-only endpoints return 405 on GET.

| Controller | Endpoints (method[args]) |
|---|---|
| auth | `login` (GET form/POST), `logout` (POST-only) |
| dashboard | `index` — also `/` and `/dashboard` |
| workorders | `index`, `create` (POST saves), `view(id)`, `edit(id)`, `update_status(id)` POST, `jobcard(id)` (print; `?autoprint=1`), `api_spare_lookup` JSON GET, `api_stock_check` JSON GET, `start_session(id)` POST, `close_session(session_id, wo_id)` POST, `session_jobcard(session_id)`, `review_incident(id)` POST, `upload_evidence(id)` POST, `download_attachment(id)`, `delete_attachment(att_id, wo_id)` POST, `link_breakdown_event(id)` POST |
| equipment | `index`, `create`, `view(id)`, `edit(id)`, `update_hours(id)` POST |
| pm | `index`, `create`, `edit(id)`, `complete([id])` POST (id in URI or `pm_id` field), `calendar` |
| inspections | `index`, `fill(code, [en\|fr])`, `edit(id)`, `save` POST, `records(code)`, `view(id)`, `pdf(id)`, `pdf_diagnostics`, `templates`, `template_edit([id])` (GET form/POST save), `template_delete(id)` POST |
| backlog | `index`, `create`, `edit(id)`, `close(id)` POST, `delete(id)` POST, `export_xlsx` |
| materials | `index` (dashboard), `inventory`, `create`, `edit(id)`, `receive(id)`, `issue(id)`, `transactions`, `report` (= export), `export_xlsx`, `template_xlsx`, `import_excel` POST, `import_report(token)`, `delete(id)` POST, `api_lookup` JSON GET, `ping` (diagnostic) |
| spareparts | `index`, `alerts`, `mark_read(id)` POST, `mark_all_read` POST, `allocate(line_id)` POST, `release(allocation_id)` POST, `issue(allocation_id)` POST |
| suppliers | `index`, `create`, `edit(id)`, `view(id)`, `add_contact(supplier_id)` POST, `delete_contact(id, supplier_id)` POST, `add_catalog_item(supplier_id)` POST, `delete_catalog_item(id, supplier_id)` POST, `find_part`, `delete(id)` POST |
| orders | `index`, `create`, `view(id)`, `update_status(id)` POST, `receive(id)` (GET form/POST), `grn(id, [receipt_id])` print, `upload_document(id)` POST, `download_document(doc_id)`, `api_lead_time_estimate` JSON GET |
| lubricants | `index`, `record`, `types`, `specifications`, `budget`, `forecast` |
| team | `index`, `create`, `view(id)`, `attendance` (GET/POST), `leave_request`, `edit(id)`, `approve_leave(id)` POST, `certifications([user_id])`, `delete_certification(id)` POST, `shift_roster` (`?week=YYYY-Www` or date), `shift_save` POST, `shift_delete(id)` POST, `assign_shift` POST, `report` |
| users | `index`, `create`, `edit(id)`, `toggle_status(id)` POST, `change_role(id)` (reads POST), `reset_password(id)`, `profile`, `change_password`, `delete(id)` POST |
| settings | `index`, `security` (POST actions: acknowledge, policy) |
| forecast | `index` (`?week=`), `generate` POST, `create_inspection_rounds` POST, `save_cell` POST, `publish` POST, `jobs`, `save_job` POST, `delete_job` POST, `plan_vs_actual`, `rolling`, `parts(id)`, `save_part` POST, `delete_part` POST, `export([planned\|rolling])`, `spares`, `allowances` |
| reports | `index`, `daily`, `weekly`, `monthly`, `mttr_mtbf`, `availability_forecast`, `monthly_plan`, `export_monthly_plan_xlsx`, `lube_compliance`, `lube_compliance_yearly`, `store_analytics`, `asset_register`, `export_asset_register_xlsx`, `asset_template_xlsx`, `import_asset_register` POST, `asset_import_report(token)`, `annual_report`, `export_annual_report_xlsx`, `annual_plan`, `export_annual_plan_xlsx`, `service_kits`, `export_service_kits_xlsx`, `equipment_history(id)`, `materials_movement`, `export_materials_xlsx`, `workshop_weekly`, `export_workshop_weekly_xlsx`, `export_workshop_weekly_pdf` (print HTML), `workshop_daily`, `export_workshop_daily_xlsx`, `export_workshop_daily_pdf` (print HTML), `export_excel(workorders\|attendance\|lubricants)` **CSV** despite the name, `pdf_daily` (print HTML), `issue_register`, `export_issue_register_xlsx`, `receipt_register`, `export_receipt_register_xlsx`, `reorder`, `export_reorder_xlsx`, `weekly_targets`, `weekly_targets_save/bulk/copy/delete` POST, `weekly_w36`, `weekly_report_name` POST, `export_weekly_w36_xlsx`, `export_weekly_w36_pdf` (print HTML, `?live=1`, `?noauto=1`), `publish_weekly_w36` POST |

**Dead routes removed** (K-07): `maintenance/*` (there is no `Maintenance`
controller; the *views* folder `views/maintenance/` is used by
workorders/equipment/pm) and `dashboard/kpi_data` (no such method). Nothing
linked to either; both only ever produced a 404 through a route that resolved
to nothing.

Report inventory (all under `reports`, roles Supervisor + Manager):
daily / weekly / monthly operational reports; MTTR-MTBF (3–24 months);
availability forecast and monthly maintenance plan (from `Dashboard_model`);
workshop daily & weekly (fleet-by-type readiness + KPI-engine availability,
screen/XLSX/print); lube compliance (period + yearly); store analytics
(orders spend, one total per currency, and lead times); issue & receipt
registers and reorder list
(`Store_model` over the movement ledger; they self-disable with a notice if
the register columns are absent); materials movement; asset register;
annual report & annual plan (PM schedule projections); service-kit
recommendations (parts implied by upcoming PM — from `Dashboard_model`);
equipment history (WOs + KPIs + lube); W36 weekly (§7.9) + weekly targets.

## 10. Frontend

- One layout: `layouts/header.php` (the entire CSS design system inline —
  light "Apple-style" theme, CSS variables), `layouts/sidebar.php` (role-aware
  navigation; visibility mirrors §4.3 but enforcement is server-side),
  `layouts/footer.php`. Page title/company name from `settings.company_name`
  (fallback "Workshop WMS").
- Chart.js renders dashboard/report charts from JSON embedded by the views.
  Without internet, charts and the Inter font silently degrade; data tables
  remain functional.
- Inspections are bilingual: `<html lang>` and all checklist text switch
  EN/FR per record/template; the rest of the UI is English (with some legacy
  French store terminology).
- Signature capture is canvas-based (operator on the WO form, four signatories
  on inspections, own profile), posted as base64 PNG and stored under
  `uploads/signatures/`.
- Flash messages (`success`/`error`) are the standard feedback mechanism;
  long operations (imports, forecast generation) compose detailed counts into
  the flash text.

## 11. Libraries and helpers

| File | Responsibility |
|---|---|
| `libraries/Xlsx_reader.php` | Minimal .xlsx reader (ZipArchive + SimpleXML): shared/inline strings, numbers, booleans, formula results, Excel serial dates (incl. 1900/1904 systems), sheet picking, header-hint row detection, zip safety limits |
| `helpers/xlsx_helper.php` | `XlsxWriter` — styled multi-sheet .xlsx writer used by every Excel export (styles shared-string serialisation order is load-bearing; see file header) |
| `libraries/Inventory_excel.php` / `Asset_excel.php` | Workbook builders: export, import template (with dropdowns/instructions), import report |
| `libraries/Inventory_importer.php` / `Asset_importer.php` | Validation + import engines; also the manual-entry validators (`validate_manual()`) — the single definition of field rules for their domains |
| `libraries/Forecast_excel.php` | Planned-availability, plan-vs-actual and rolling-plan sheets from `Forecast_model` output |
| `libraries/W36_excel.php` | The approved W36 workbook geometry from `W36_report_model::build()` output (labels/geometry are the static contract fixed in the class) |
| `libraries/Pdf_writer.php` | Dependency-free PDF 1.4 writer (text, rects, lines, GD image embedding, WinAnsi) |
| `libraries/Inspection_pdf.php` | Lays out inspection records (matrix + compliance) on `Pdf_writer` |
| `helpers/kpi_helper.php` | Pure KPI functions: interval merge/union, per-day splitting, breakdown events, days/hours down, availability, MTTR/MTBF, roll-ups (§6) — no DB access |
| `helpers/wo_helper.php` | Breakdown classification (`wo_is_breakdown`, `wo_bucket(s)`) and the five hold codes with labels |
| `helpers/equip_helper.php` | Equipment-status predicates/colours — the single interpretation of `equipment.status` values (the enum's `In Maintenance`, not `Maintenance`) |
| `helpers/csrf_helper.php` | `csrf_field()` for hand-written forms (token name from the Security class) |
| `helpers/tmp_helper.php` | `wms_temp_path()` — private temp path for a generated download (random token + the offered filename), so concurrent exports cannot overwrite each other (K-19) |

Models not yet mentioned: `Store_model` (issue/receipt registers, register
totals, allocation consumption, monthly movement; feature-detects the movement
columns), `Asset_model` (register queries, import writes/log; also exposes the
equipment status list), `Dashboard_model` (dashboard aggregates — KPI figures
delegate to `Kpi_engine_model`; also annual report/plan, monthly plan, asset
register read, service-kit recommendations, MTTR/MTBF trend, availability
forecast used by Reports).

## 12. Configuration reference

### 12.1 Files & environment

| Item | Location / name | Notes |
|---|---|---|
| App config | `application/config/config.php` | `base_url` from `WMS_BASE_URL`, else auto-detected from a validated `HTTP_HOST` + script path (CLI: `http://localhost/`); a malformed Host is refused with 400 (K-17). Sessions/CSRF per §4.4; log threshold 1 (errors) to `application/logs/`; `index_page=''` (requires rewrite) |
| Local overrides | `application/config/config.local.php` | Loaded last by `config.php`; template: `config.local.php.example`. Typical keys: `encryption_key`, `legacy_password_login`, `cookie_secure` |
| DB config | `application/config/database.php` | `mysqli`, `utf8mb4_unicode_ci`, `db_debug` only in development; credentials from env then local file; defines `WMS_DB_MISSING` when host/user/database empty → setup page in `MY_Controller`/`Auth` |
| DB credentials | `application/config/database.local.php` or env `WMS_DB_HOST` (host[:port]), `WMS_DB_USER`, `WMS_DB_PASS`, `WMS_DB_NAME` | Local file wins over env |
| Environment | server var `CI_ENV` | `production` (default: errors logged to `application/logs/php_errors.log`, deprecations suppressed), `development` (display errors, `db_debug`), `testing` |
| `WMS_ENCRYPTION_KEY` | env | If unset **and** no local override, `config.php` derives a predictable fallback from a static salt + host (the Encryption library is not currently loaded anywhere, but do not rely on the fallback) |
| `WMS_LEGACY_PASSWORD_LOGIN=0` | env | Disables MD5/SHA1 sign-in acceptance |
| `WMS_BASE_URL` | env | Pins `base_url` (e.g. `https://wms.example.com`); takes precedence over detection. Can also be set as `$config['base_url']` in `config.local.php` |
| `WMS_COOKIE_SECURE=0` | env | Forces non-secure cookies (local HTTP dev) |
| Timezone | PHP `date.timezone` | Never set by the app; the dump's `SET time_zone='+00:00'` applies to the import session only |

### 12.2 Database `settings` keys

Read through `Settings_model` (every key falls back to the default below when
missing/invalid; the Settings screen edits the report + forecast groups;
`login_ip_cap_enabled` is edited on the security screen):

| Key | Default | Meaning |
|---|---|---|
| `company_name` | *(seeded `A&ML`; fallback "Workshop WMS" in views)* | Display name in titles/reports |
| `currency` | `USD` | Site currency (3-letter code). Drives the spend totals (which are kept **per currency**, never summed across one) and the default in the supplier, order, catalog and stock-receipt pickers (K-11). The vocabulary lives in `helpers/currency_helper.php` |
| `weekly_open_hours` | `168` | Open hours in a 7-day week (≤0 → 7 × daily) |
| `daily_open_hours` | `24` | Open hours/day (clamped 1–24) |
| `default_availability_target` | `0.88` | Final fallback target (fraction; "88" accepted) |
| `report_week_start_day` | `1` | ISO weekday the report week starts (1=Mon…7=Sun) |
| `breakdown_count_mode` | `start_in_period` | or `overlapping` (§6) |
| `mtbf_zero_failure_mode` | `dash` | or `uptime` (§6) |
| `pm_meter_stale_days` | `30` | Stale-meter "not judged" threshold (0 = off) |
| `login_ip_cap_enabled` | `1` | Per-source-IP sign-in cap (§4.2) |
| `standing_allowance_hours` | `{}` | JSON: equipment-type → standing h/day |
| `standing_allowance_default_hours` | `2` | Fallback standing h/day (0–24) |
| `inspection_default_hours` | `1` | Duration of a weekly inspection round |
| `inspection_hours_by_type` | `{}` | JSON per-type inspection hours |
| `pm_utilisation_lookback_days` | `28` | Meter-projection window (1–365) |
| `pm_default_hours_per_day` | `{}` | JSON fallback utilisation by type (empty = report, don't guess) |
| `unplanned_allowance_mode` | `flat` | or `derived` |
| `unplanned_allowance_default_hours` | `1` | Flat-mode breakdown allowance h/day |
| `unplanned_allowance_lookback_weeks` | `12` | Derived-mode history window (1–104) |
| `forecast_show_excel_reference` | `1` | Show historical-Excel reference figures in forecast |

Seeded legacy keys `working_hours_per_day`, `work_days_per_month` and
`pm_alert_days` exist in the dump but **no code reads them** (verified by
search); alert windows in code are hard-coded (dashboard PM +14 days, etc.).
`cert_alert_days` is read by the certification screens and `currency` by
`wms_site_currency()` (K-11), so those two are live settings now.

## 13. Schema-version tolerance

`database/schema.sql` is the baseline; `database/migrations/` brings an
existing database forward (`scripts/migrate.sh`). Until an optional migration
has run, screens tolerate the older shape. Runtime probes
(`table_exists` on ~30 tables; `field_exists` on backlog verification
columns, `equipment.{report_group,report_sort,include_in_weekly_report}`,
`materials.min_stock`, `pm_schedules.{standard_hours,preferred_weekday}`,
`material_transactions.txn_date`) let screens run with reduced features
when a table or column is missing, usually with an explanatory notice
pointing at the shipped dump. The probed feature areas: WO evidence
attachments (`wo_attachments`), pending-spares tracking
(`wo_spare_allocations`, `spare_part_alerts`), store-register/material
extension columns + `equipment_specs`, forecast tables
(`equipment_planned_downtime`, `forecast_snapshots`, `pm_part_requirements`,
PM `standard_hours`), maintenance-plan jobs + `preferred_weekday`,
`login_attempts`, goods receipts + movement `import_key` uniqueness,
backlog closure/inspection-link columns, and `security_events`.
**To verify at runtime** for any database other than the shipped dump.

## 14. File storage

| Path | Contents | Written by | Web access |
|---|---|---|---|
| `uploads/photos/` | equipment photos (`eq_<ts>`), user photos (`user_<id>_<ts>`), gif/jpg/png ≤ 5 MB / 2 MB | Equipment, Team, Users | Direct (script-blocked) |
| `uploads/signatures/` | drawn PNGs (`opsig_…`, `insp_…`, `sig_user_<id>.png`) + converted uploads (`sig_…`) | Workorders, Inspections, Users, Team | Direct (script-blocked) |
| `uploads/wo_attachments/` | incident evidence (`evd_<wo>_<ts>_<n>`), ≤ 8 MB/file | Workorders | Direct (script-blocked); download endpoint works (K-04 fix) |
| `uploads/order_docs/` | order documents (`ord_<id>_<ts>`), ≤ 8 MB | Orders | Direct (script-blocked); endpoint works (K-04 fix) |
| `uploads/tmp/` | generated workbooks (deleted after send), uploaded import files (deleted after processing), parked import-report JSON (24 h) | Materials, Reports | **Denied** (`uploads/tmp/.htaccess`) |
| `uploads/documents/` | certification documents (`cert_<user_id>_<ts>`), pdf/jpg/png ≤ 8 MB | Team | Direct (script-blocked); deleting the certificate removes its file |
| `uploads/logoam.png` | company logo embedded in W36 outputs | manual | Direct |
| PHP temp dir | generated workbooks (`wms_<random>_<name>`, deleted after send) | Forecast, Backlog, Reports | n/a |

Deleting DB rows does not delete files (except attachment delete, which
removes the file first — see `Workorder_model::delete_attachment`), and
editing signatures/photos orphans the previous file.

## 15. Error handling, logging, background work

- `ENVIRONMENT=production`: PHP errors logged (not displayed) to
  `application/logs/php_errors.log`; CodeIgniter log (threshold 1 = errors)
  writes dated files in the same directory. Custom error views in
  `views/errors/html/`.
- Missing DB configuration renders a dedicated setup page (HTTP 500) from
  `MY_Controller`/`Auth`, walking through creating `database.local.php` and
  importing the shipped dump.
- Models and engines degrade on missing tables/columns (logged via
  `log_message('error', …)` where it matters, e.g. settings load, sign-in
  lookup, inventory export sanity check).
- Multi-write flows are transactional with `FOR UPDATE` locks: WO status and
  sessions, order status and receiving, attendance day save, stock movements,
  spare allocate/issue, Excel imports. Failures roll back and flash a
  "nothing was saved" message.
- **No cron/queue/workers exist.** Retention and cleanup are opportunistic:
  login attempts/events pruned during sign-in processing; import-report JSON
  pruned on the next import; CI garbage-collects DB sessions probabilistically.
- Notifications are in-app only (spare-part alerts, security-event badge).
  Nothing sends e-mail.

## 16. Deployment & operations

- Target: Apache (cPanel) with the shipped `.htaccess` (rewrite to
  `index.php`, hidden-file block, uploads PHP block, security headers when
  available). `application/`, `system/` and `database/` each carry a
  deny-all `.htaccess`, so the code and the SQL dump are not served even if
  PHP handling is misconfigured. `.htaccess.cpanel-alternative` is the FastCGI
  fallback.
  `FallbackResource` covers missing `mod_rewrite`. Nginx/IIS need equivalent
  hand-written rules (notes inside `uploads/.htaccess`).
- Install/upgrade order for credential changes (documented in
  `database.php`): create local config with current credentials → deploy →
  verify → rotate password → verify old password fails.
- Credentials live **only** in `application/config/*.local.php` (or the
  `WMS_*` environment variables); both files are git-ignored and the
  `.example` templates are the only copies in the repository. The history of
  this repository still contains the pre-rotation values — see
  `SECURITY.md` (K-01).
- Writable: `application/logs`, `application/cache`, `uploads/**`. PHP needs
  `mysqli, mbstring, iconv, zip, xml`; `gd` for Team signature processing
  (optional elsewhere); `zlib` optional. Upload limits: app allows ≤ 8 MB
  files — PHP `upload_max_filesize`/`post_max_size` must be at least that.
- Backups must cover: the database, the `uploads/` tree, and the two
  `*.local.php` files. Published forecast/W36 snapshots are DB rows (JSON +
  checksum), so they are covered by DB backup.
- Performance posture: list screens render full filtered result sets (the
  `pagination` library is autoloaded but never called anywhere);
  the materials dashboard aggregates the whole items table in PHP; W36 and
  forecast builds are whole-fleet computations per request (build time is
  recorded on W36 snapshots). Fine at the shipped data volumes (116 machines,
  ~1.7k items); re-check if fleet/history grows by an order of magnitude.
- Data volumes in the dump if you keep production data: `material_transactions`
  dominates (~12.7k rows incl. `affects_balance=0` history).

## 17. Implementation status matrix

**Implemented and wired end-to-end:** everything in §3 marked Implemented,
including: two-stage WO closure with verification; labour sessions; incident
records + evidence upload; meter writer; PM completion; bilingual inspections
with PDF and defect automation; backlog planning push; stock ledger with
idempotent movements; pending-spares allocate/issue/alerts; order pipeline
with partial GRN receiving; lube compliance; attendance/leave/certifications;
user admin; settings + security console; the W36 build/publish/export chain;
forecast generate/publish/plan-vs-actual/rolling/spares; both Excel imports
with dry-run and error reports.

**Implemented in this revision:** the nine gaps below now have code paths.
The file column names the controller/model/view that carries the change;
the rules each one follows are stated in §7 and in the code comments.

| Area | What now works | Files |
|---|---|---|
| Shift roster | Supervisor screen creates/edits shifts and assigns people per week; assignment counts per shift; a shift in use cannot be deleted | `Team::shift_roster/shift_save/shift_delete/assign_shift`, `views/team/shift_roster.php` |
| WO `hold_code` | Set on the WO form and on the quick status form; shown as a badge on the list and detail; cleared when the job is Completed/Closed | `Workorders::create/edit/update_status`, `views/maintenance/{wo_form,wo_view,workorders}.php` |
| Breakdown events | Detail page links a breakdown WO to another event on the same machine (or detaches it); cycles and cross-machine links refused | `Workorder_model::link_breakdown_event`, `Workorders::link_breakdown_event` |
| WO oil lines vs lubricants | Oil lines are posted to `lube_consumption` with `work_order_id` (idempotent); the detail page shows the ledger; unmatched lines are reported | `Workorder_model::sync_lube_consumption`, `get_lube_consumption`, `workorders/view` |
| Equipment status automation | Create/edit/quick-status all keep `equipment.status` in step; planned work sets `In Maintenance`; the machine follows the strongest open claim and is only Available when nothing is open | `Workorders::_sync_equipment_status` |
| Certifications | Stored status refreshed from the expiry date; document upload (pdf/jpg/png ≤8 MB) with a detail-page link | `Team_model::refresh_cert_status`, `Team::_save_cert_document` |
| Leave | Approval writes attendance rows (and reverting removes only those); balances per type shown on the request and profile pages; overlap refused | `Team_model::apply_leave_to_attendance/revert_leave_attendance/get_leave_balance` |
| Templates (inspections) | Supervisor template editor: create/edit/delete, EN/FR names, compliance or matrix layout, item rows, usage counts; delete only at zero records | `Inspections::templates/template_edit/template_delete`, `Inspection_model::save_template/delete_template` |
| PM completions | Mark Done records the meter reading and the optional same-machine work order in `pm_completions.work_order_id` | `Pm::complete`, `views/maintenance/pm_planner.php` |

Two previously-unused paths are live now: `uploads/documents/` stores
certification documents, and `Workorder_model::get_lube_consumption()` is
called by the work-order detail page.

**Also fixed in this revision (register §18):** the backlog
supervisor close button that never rendered (K-06); three dead routes
(K-07); the write paths that flashed success over a rejected INSERT/UPDATE
(K-09, now resolved: WO create + edit, team create/edit — whose form was
create-only, so "editing" a worker created a duplicate account — plus
certifications and shift types); the last null-dereference write paths (K-10);
the worker profile page's dead "Add Certification" modal and hidden edit
controls (K-22); the dashboard's CSV assignee join (K-12); and colliding
temp-file names in
all eleven workbook exports (K-19). The dashboard's **Failures** and
**Downtime** cards now come from the KPI engine — the same 30-day window,
the same breakdown-event grouping and the same open-hour clipping as W36 —
instead of a raw count/sum of `work_orders`; the fleet window is computed
once per request instead of three times.

**Present but dead/unused:** `kpi_log` and `excel_migration_log` tables (no
code references); `views/layouts/header-apple.php`; model methods with no
callers in controllers/views
(e.g. `Dashboard_model::count_overdue_pm_count`,
`Dashboard_model::get_wo_ratio`); inspection
`status='draft'` (schema allows, UI always submits); CodeIgniter's Encryption
library (configured key, never loaded).

**Repository self-containedness:** an earlier version of the codebase
pointed (in setup pages, flash messages and comments) at migration files,
review documents, diagnostic scripts and workbooks that were never
committed. Those references have been removed: runtime messages now point
at the shipped dump, and comments describe the probed table/column instead
of a migration number. The decision/task/finding IDs still cited in
comments (D1–D37, Forecast F3–F11, MP-0…MP-4, audit findings F-02…,
BR-Wxx, OPEN-x…) are retained as labels — the rule each produced is stated
in the comment itself and recorded in this document. The only non-text
change in that cleanup was renaming the forecast view flag
`$migration_ready` to `$tables_ready`.

## 18. Known issues register

Severity reflects impact on the production system as shipped. File references
are to the reviewed commit.

| ID | Sev | Issue | Evidence / notes |
|---|---|---|---|
| K-01 | **Critical — rotation outstanding** | The live MySQL password and the application `encryption_key` were committed in `application/config/database.local.php` and `config.local.php` in the first commit. The repository is **public**, so both values must be treated as compromised regardless of what happens next. | **Fixed in the tree:** both files removed from the index (still on disk for the running install), `.gitignore` now excludes `*.local.php`/`.env*`/logs/uploads, `.githooks/pre-commit` refuses them, and `database/.htaccess` closes direct HTTP access to the dump. `scripts/purge-secrets.sh` performs and verifies the history rewrite without pushing. **Operator action still required:** rotate the DB password and the key on the host and force-push the rewritten history — [`SECURITY.md`](SECURITY.md) is the step-by-step runbook. Nothing is encrypted with the old key (the Encryption library is never loaded), so its rotation needs no re-encryption. |
| K-02 | **Critical — resets outstanding** | The production dump (`database/yzzme_43038742_aml.sql`) was tracked in a **public** repository. It exposed 66 staff accounts — 63 with unsalted **MD5** hashes — plus names, e-mails and phones, and the committed local config kept `legacy_password_login = TRUE`, so a cracked hash was directly usable. | **Fixed in the tree:** the dump is untracked and ignored; `database/schema.sql` (59 tables + master data, no people/fleet/stock) is the install artifact, and every "import the dump" message now names it. The Users screen counts and marks accounts still on a legacy hash so the reset campaign is measurable. **Operator action:** treat every account in that dump as compromised — reset each password (Users → Reset Password), then set `legacy_password_login = FALSE` in `config.local.php`; see `SECURITY.md` §5. The hashes are still in public history until the K-01 purge is run. |
| K-03 | **Resolved** | Runtime logs were committed (`application/logs/log-2026-10-19.php`, `php_errors.log`), revealing absolute server paths and operational details. | Fixed in this revision: both files removed from the tree, `application/logs/*` ignored (`.gitkeep` kept), and `.gitignore` covers `*.log`. They remain in history — the K-01 purge covers that. |
| K-04 | **Resolved** | Attachment/document **downloads fatal-errored**: `force_download()` is called in `Workorders::download_attachment()` and `Orders::download_document()` but CodeIgniter's `download` helper was never loaded. Uploads work; downloads returned 500. Files remain reachable by direct URL under `uploads/…` (names are timestamp-based, i.e. guessable — a confidentiality consideration for incident evidence). | Fixed in this revision: `download` added to `$autoload['helper']`. |
| K-05 | **Resolved** | PM completion never recorded the meter reading for `Km`-interval schedules: `Pm::complete()` checked `['Hours','Kilometres']` but the enum value is `Km`. Hours schedules worked. | Fixed in this revision: the handler compares `Hours`/`Km` and translates to `Kilometres` only for the meter writer (`Pm.php`). |
| K-06 | **Resolved** | The backlog list's supervisor **"Close (verify)" button never rendered**: `Backlog::index()` set `$data['is_supervisor']` **after** `$this->render(...)`. | Fixed in this revision: the flag is part of the render data. A sweep of every controller found no other `render()`-before-data site. |
| K-07 | **Resolved** | Dead routes: `maintenance/*` (no controller) and `dashboard/kpi_data` (no method) → 404. | Fixed in this revision: both removed from `routes.php`; no view or controller linked to them. |
| K-08 | **Resolved** | Onboarding references were broken: the built-in setup pages and code comments pointed to at least a dozen files that didn't exist in the repo, including the import file named in the "Database Setup Required" page. All such references have been rewritten (§17) — messages now name the shipped dump and comments describe the probed table/column, so a fresh clone can be installed by following the in-app text and `README.md`. | `MY_Controller::_show_db_config_error()`, `Auth::__construct()`, various comments. |
| K-09 | **Resolved** | Weak server-side validation on several write paths. `Team::create/edit` skipped the uniqueness/min-length checks that `Users` enforces, and the team form was create-only — "editing" a worker inserted a duplicate account. With non-strict SQL modes, invalid enum values can be silently coerced (connection sets `stricton = FALSE`). | Fixed in this revision: WO create validates machine/type/status/title and aborts a failed insert before any child row is written; WO edit validates title/type/priority/status/machine status and aborts on a failed UPDATE; `Team::create/edit` now validate required names, `valid_email`, unique `email`/`employee_id`, an 8-character password and a real role, and guard the insert/update result. `views/team/form.php` is a single create/edit form that posts each field back on a validation error. |
| K-10 | **Resolved** | Null dereferences on crafted input: `Lubricants::record()` used `$eq->equipment_type_id` without checking the equipment exists; `Orders::create()` did the same with the supplier. | Fixed in this revision: both guard the lookup and flash a message; the optional lubricant work-order link is also validated against the machine. |
| K-11 | **Resolved** | Currency handling was inconsistent: seeded `settings.currency='USD'` was never read; suppliers/orders defaulted to `TZS`; material prices are `unit_price_ugx`/`unit_price_usd`; manual goods receipts hard-coded `UGX`; and the CEO dashboard and store analytics added `total_cost` from TZS, UGX and USD orders into one meaningless total. | Fixed in this revision: `helpers/currency_helper.php` is the one vocabulary, `settings.currency` is the one default (editable on the Settings screen, which previously did not offer UGX at all), and every currency picker draws from it. A stock receipt records the currency the operator selected. Spend is grouped by `o.currency` in `Order_model::get_spend_by_supplier()` and shown as one total per currency; supplier bars are scaled within their own currency. No exchange-rate conversion is invented. |
| K-12 | **Resolved** | Dashboard "recent work orders" resolved the assignee by joining `users.id = work_orders.assigned_to`, but `assigned_to` is a CSV — at best the first technician matched (MySQL coercion). | Fixed in this revision: the card reads `Workorder_model::get_recent()`, which resolves every technician through the CSV parser; the CSV column is never joined again. |
| K-13 | **Resolved** | No migrations, seeders or tests: `database/schema.sql` was the only schema artifact, and the defensive table/field probes (§13) could mask schema drift instead of failing loudly. | Fixed in this revision: `database/migrations/` + `scripts/migrate.sh` apply forward-only, idempotent SQL and record it in `schema_migrations` (`--dry-run` lists pending files); a fresh install still imports only `schema.sql`. `tests/run.php` is a dependency-free suite (K-11/K-17/K-18 logic, 54 checks) and `.github/workflows/tests.yml` runs it plus `php -l` and `bash -n` on every push/PR. The runtime probes remain for databases where a migration has not been applied; the register entry stays closed because drift can now be detected and applied rather than hand-edited. |
| K-14 | **Resolved** | `Settings_model::save_many()`'s comment said `settings` had no unique index on `setting_key`; the schema defines one (`UNIQUE KEY setting_key`). | Comment corrected in this revision. The select-then-upsert is deliberately unchanged — it is correct with or without the constraint and creates a missing row with its documented description. |
| K-15 | **Resolved** | `Team::_make_transparent()` called GD functions without an availability guard → fatal error on hosts without `gd` when a signature image is uploaded. | Fixed in this revision: the function copies the upload unchanged when `gd` is absent. The per-pixel loop is still slow for very large images. |
| K-16 | **Resolved** | Inspection defect priority depended on substring matching of free-text item categories (`no-go`, `go & fix`, FR variants). Renaming a template category silently changed P1/P2 behaviour. | Fixed in this revision: `inspection_items.priority` (`P1`/`P2`/`P3`, NULL = derive) is set from a **Severity** select in the template editor and read by `_inspection_item_verdict()`; the category wording remains only the fallback for rows saved before the column existed. Fresh installs get the column from `database/schema.sql`; an existing database keeps working and can be upgraded with<br>`ALTER TABLE inspection_items ADD COLUMN priority ENUM('P1','P2','P3') NULL DEFAULT NULL AFTER na_columns;` |
| K-17 | **Resolved** | `base_url` was assembled from the raw request `Host` header: a crafted value was reflected into every generated link (attribute injection), and a proxy that rewrote Host changed every URL. | Fixed in this revision: `WMS_BASE_URL` → `config.local.php` → validated auto-detection, in that order. Detection accepts only `hostname[:port]`, IPv4 or `[IPv6][:port]`; anything else gets HTTP 400 and a log line. `SCRIPT_NAME` is validated too. Pin the value in production. |
| K-18 | **Resolved** | The whole user row — including the password hash — was stored in the DB-backed session (`user_data`), so every signed-in user had a hash at rest in `ci_sessions`, rewritten on each session regeneration. | Fixed in this revision: `wms_session_user()` (`helpers/user_helper.php`) projects the row onto the fields a view actually reads; `Auth::login()` and the profile save use it, and `MY_Controller::_check_auth()` rewrites any pre-existing session payload that still carries `password`. Export headers now resolve `generated_by` through `wms_user_full_name()`. |
| K-19 | **Resolved** | Workbook exports wrote to `sys_get_temp_dir()` under deterministic (label/timestamp) names — simultaneous exports of the same week shared one path, so a download could be truncated or contain another user's file. | Fixed in this revision: all 11 export sites use `wms_temp_path()` (`helpers/tmp_helper.php`), which prefixes a random token while keeping the offered filename. `uploads/tmp/` exports still use per-second names. |
| K-20 | **Resolved** | Duplicate unique indexes on `material_transactions.import_key` (`uq_mt_import_key`, `uq_txn_import_key`) — harmless, but every insert maintained the same index twice. | Removed `uq_txn_import_key` from `database/schema.sql`. An existing database keeps both unless the operator runs `ALTER TABLE material_transactions DROP INDEX uq_txn_import_key;` (optional; the remaining index enforces the same uniqueness). |
| K-21 | Info | `README.md` in the original upload was a 20-byte UTF-16 placeholder ("# wmsv2"); replaced by the current `README.md`. | Git history. |
| K-22 | **Resolved** | The worker profile page rendered a **Bootstrap modal** for adding certifications, but the layout loads no Bootstrap CSS or JS (only Chart.js): `data-bs-toggle` did nothing and the "modal" body was laid out inline at the bottom of the page, so the "Add" button never opened anything. The page also had no link to the (new) edit form. | Fixed in this revision: a native `<dialog>` (no framework), a supervisor-only **Edit Profile** button, and `$data['is_supervisor']` from the controller; the role badge uses the theme's `b-secondary` instead of the unstyled `.bg-light .text-dark`; the attendance strip and legend know the `Holiday` status. |

Not investigated to a verdict (needs a running instance): actual rendered
output of every view (possible undefined-variable notices masked in
production mode), Excel geometry fidelity vs the legacy workbooks, W36
figures vs the workshop's approved reference numbers, and behaviour under the
production PHP/MySQL versions and `sql_mode`.

## 19. Verification method

How this document (and `README.md`) was produced, so future maintainers can
re-check it:

1. Full file inventory; every controller (17), the base controller, all
   models (21), libraries (9) and helpers (5) read or systematically
   extracted (public methods, models loaded, tables touched, guards).
2. The dump was parsed mechanically: 59 `CREATE TABLE`s (columns, types,
   enums, defaults, keys), 42 foreign keys from `ALTER TABLE … ADD
   CONSTRAINT`, per-table inserted-row counts, and seed contents for
   reference tables. User rows were analysed only in aggregate (hash-format
   counts) — no credential or hash value is reproduced in any document.
3. Cross-checks: routes vs controller methods (found K-07); tables named in
   code vs tables in the dump (only `kpi_log`, `excel_migration_log` unreferenced);
   helper availability for `force_download` (K-04); enum values vs the
   strings compared in code (K-05); referenced file paths vs the repo tree
   (found the dangling references later removed — §17, K-08);
   committed-file inventory vs `.gitignore` claims (K-01…K-03).
4. No code was executed. The documentation review itself modified no
   application file; a follow-up cleanup in the same pull request rewrote
   the messages/comments that referenced files absent from this repository
   (§17) — text-only edits plus the one view-variable rename noted there,
   verified by residual searches and a full diff review.

**Outstanding / unverifiable in this review:** runtime behaviour (no PHP or
MySQL in the review environment) — in particular whether the app boots
cleanly on the production PHP version with the dump imported; the items
marked *to verify at runtime*; and the business correctness of KPI outputs
against the workshop's approved reference figures (the legacy workbooks
that define them are not in the repository).
