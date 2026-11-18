# Database Schema, Migrations & XAMPP / CI-CD Guide

## 1. Fresh Installation (XAMPP, Docker, Linux, or cPanel)

`database/schema.sql` contains the complete, production-ready baseline schema:
- Application tables plus the `schema_migrations` tracking ledger (including `backlog_events`)
- Single-column `PRIMARY KEY (id)` on `ci_sessions` aligned with `$config['sess_match_ip'] = FALSE` (prevents IPv4/IPv6 `127.0.0.1` vs `::1` session/CSRF mismatches on Windows XAMPP)
- InnoDB foreign keys, unique constraints, and reporting indexes (including annual worker/date integrity)
- Documented `settings` defaults (`Settings_model::DEFAULTS`), including leave reminders
- All **14 bilingual (EN/FR) workshop `inspection_templates` + 630 `inspection_items`** (zero PII)

### Quick Setup on XAMPP (`C:\xampp\htdocs\wmsv2`)
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Create your `.env` from `.env.example` (or leave defaults: `DB_HOST=127.0.0.1`, `DB_USER=root`, `DB_PASS=`, `DB_NAME=wmsv2`).
   - Copy or leave `application/config/config.php` as-is — `base_url` is detected automatically for any subfolder (e.g. `http://localhost/wmsv2/`), and `.htaccess` uses relative routing without a hardcoded `RewriteBase /`.
3. Import schema + verify + create your first Supervisor account in two commands:
   ```bat
   scripts\migrate.bat --schema --verify
   C:\xampp\php\php.exe scripts\create-admin.php admin@workshop.local "StrongPass123!" "Workshop" "Supervisor" 1
   ```
   *(Or import `database/schema.sql` via `http://localhost/phpmyadmin` and create the admin user via `scripts/create-admin.php`.)*
4. Open **`http://localhost/wmsv2/`** and sign in.

---

## 2. Upgrading an Existing Database (`0001` – `0018`)

Migrations are tracked in `schema_migrations`. Most additive migrations are re-runnable on MySQL 8.0+ and MariaDB 10.4+; **0017 is ledger-only** (it adds an FK without an existence guard and is skipped on `--force` once recorded). Do not delete its ledger row to rerun it on an existing database.

| Version | File | Purpose |
| :--- | :--- | :--- |
| `0001` | `0001_inspection_items_priority.sql` | Adds explicit `priority ENUM('P1','P2','P3')` severity column on `inspection_items` |
| `0002` | `0002_drop_duplicate_import_index.sql` | Drops duplicate unique index `uq_txn_import_key` on `material_transactions.import_key` |
| `0003` | `0003_schema_migrations_and_sessions_pk.sql` | Creates `schema_migrations` ledger & fixes `ci_sessions` composite PK `(id, ip_address)` → `(id)` |
| `0004` | `0004_reconcile_columns_and_tables.sql` | Reconciles all Wave W0–W4 & MP1–MP3 tables, columns, and ENUMs on older databases |
| `0005` | `0005_unique_constraints_and_indexes.sql` | Deduplicates `lube_specifications(equipment_type_id, lube_type_id)`, adds `uq_lube_spec_eq_lube` & 41 missing indexes |
| `0006` | `0006_foreign_keys_integrity.sql` | Cleans orphaned nullable foreign IDs and enforces all 54 missing InnoDB `FOREIGN KEY` constraints |
| `0007` | `0007_seed_master_data_and_checklists.sql` | Seeds all master reference tables, all 25 `settings` defaults (`INSERT IGNORE`), and all 14 bilingual EN/FR `inspection_templates` + 630 `inspection_items` + `P1` safety severities |
| `0008` | `0008_restore_equipment_register.sql` | Restores the equipment register master data |
| `0009` | `0009_restore_suppliers_and_inventory.sql` | Restores the cleaned suppliers, inventory items and stock history |
| `0010` | `0010_attendance_punches.sql` | Creates `attendance_sites` and `attendance_punches` for the QR + GPS check-in workflow, and seeds the four attendance settings (gate off, warn policy, 60 s token, 120 s grace). No punch data is seeded |
| `0011` | `0011_restore_verbatim_asset_machine_ids.sql` | Aligns `equipment.registration_no` verbatim with the master Asset Register document (`EX 006`, `C-HEADT 007`, `C-HEADT 008`) while preserving all `equipment.id` foreign-key links |
| `0012` | `0012_sync_equipment_with_asset_register.sql` | Synchronizes all 116 equipment rows, 21 types/categories, 36 approved sites, and 338 specs across every column (`MACHINE ID`, `TYPE`, `CATEGORY`, `BRAND`, `MODEL`, `YEAR`, `SERIAL NO`, `ENGINE MODEL`, `CURRENT HOURS`, `CURRENT KM`, `LOCATION`, `STATUS`) with `Equipment_Register.xlsx` |
| `0013` | `0013_reconcile_stale_equipment_duplicates.sql` | Reconciles and merges any duplicate/template/stale equipment rows across all 12 foreign-key child tables so existing databases converge cleanly to the canonical 116 Asset Register rows |
| `0014` | `0014_worker_site_and_timesheet_settings.sql` | Adds worker site and timesheet settings |
| `0015` | `0015_backlog_workflow_safety.sql` | Adds defect proposals, closure evidence, activity and idempotency without rewriting existing defects |
| `0016` | `0016_forecast_write_lock.sql` | Adds the InnoDB singleton used to serialize forecast publication with plan and ledger writes |
| `0017` | `0017_user_blacklist_and_delete.sql` | Adds Blacklisted user status and attributable blacklist audit fields (ledger-only after first application) |
| `0018` | `0018_annual_workforce_plan.sql` | Adds year Draft/Published status, worker/date Shift/Off/Holiday bases, supervisor-planned leave, Off-day extra-work settlement, audit events and in-app reminder defaults |
| `0019` | `0019_sick_shift_cover.sql` | Adds supervisor-approved sick Shift cover, Off-day call-in linkage, worker-availability attestation and auditable cancellation; does not rewrite the annual plan or attendance |

### Sick-shift cover rollout (0019)

1. Back up the database, pause roster/leave writes, apply migration `0019`, deploy the matching application code to **every** instance, and run `php scripts/migrate.php --verify`. It requires `0018` and InnoDB. Old and new application code must not edit planning concurrently: the new endpoints serialize on the planning-year row. A fresh install has `0019` in `database/schema.sql`; an existing install receives only the additive table.
2. In **Team → Annual Workforce Plan**, publish dated Shifts and explicit Off days; supervisors can schedule Sick Leave directly, without an employee request. An approved exceptional Sick Leave request may also create a vacancy. Open **Team → Sick Shift Cover** for the month or use a sick Shift's *Find cover* link. Only published, current/future Shift dates are actionable; Off/Holiday/legacy/draft dates never become vacancies.
3. The shortlist shows qualified same-role/position/site workers on that Shift and separately lists potential Off-day call-ins; it explains conflicting candidates and warns when nobody is safe to approach. Skill level, current named certificates held by the absent worker, other cover, leave, actual attendance, sessions and active workload inform the result. A certificate match is only a **proxy** for competence. The supervisor must independently check certification requirements, safe rest, capacity and consent. An on-shift colleague shares duties but does **not** replace headcount.
4. An Off worker is **not** available by default. To approve cover, confirm their agreement and choose **Pay** or **replacement Off**. Approval records a separate authorised extra-work exception and an availability attestation, leaving the base Off day intact. A pre-existing authorised call-in on the matching Shift may instead be reused; its compensation is not silently changed. Actual attendance remains a separate, subsequently recorded record. After actual work, confirm and settle the extra-work entry through the normal annual-plan workflow; WMS does not calculate wages.
5. Eligibility is re-evaluated inside the year lock on POST. Changing a covered worker's dated plan, approved leave, or linked call-in requires cancelling the cover first; duplicate candidates cannot cover two vacancies on the same day. Cancellation preserves the event history and only revokes an unused call-in created *for that assignment*. The cover detail rechecks current eligibility and flags changed profiles, certificates, schedules or actual attendance for review. Direct SQL and old application versions bypass workflow checks; review affected assignments after security/status changes.
6. On a disposable copy, run `WMS_TEST_DESTRUCTIVE=1 python3 tests/annual_plan_live.py` followed by `WMS_TEST_DESTRUCTIVE=1 python3 tests/sick_cover_live.py` with the CI fixture accounts, MySQL CLI and HTTP server. The PR CI exercises fresh **and** incremental migration 0019, role controls, revalidation, call-ins and cancellation on MySQL 8 and MariaDB 10.4.

### Annual workforce plan rollout (0018)

1. **Back up**, deploy migration 0018 and the matching application version together, then run `php scripts/migrate.php --verify` before supervisors start scheduling. It is additive: weekly shifts, approved leave requests, attendance and punch events are not rewritten. Never plan on an instance still running the old weekly-assignment code; existing weekly shifts remain read-only until carried explicitly into a draft year.
2. Open **Team → Annual Workforce Plan**, define any missing shift types in the week view, and generate a 2–56-day rotation for each active worker. Every generated date has a real Shift or explicit Off; override individual dates or mark Holidays. Optionally carry existing future weekly assignments into the draft, then review and correct their assumed seven workdays. Publish only after all remaining worker-days are covered. Staff see only published years; managers may preview but only supervisors can change or publish.
3. Schedule leave directly on the year view (no worker request). Published leave changes expected availability, reserves underlying Shift days in the balance, and drives in-app start/return notices in the operational attention bell. The default notice windows are **7** days before leave and **2** days before return; supervisors can edit them in Settings. Both years must be published before a cross-year return reminder is shown. Legacy employee requests are retained separately for unscheduled exceptions and charged to the year(s) of their dates.
4. For an authorised Off-day call-in, preserve the Off base and create a separate extra-work exception with a reason and a shift. After the date, record actual attendance and confirm worked hours from that record. Settle **Pay** using an external payroll reference (WMS does not calculate pay amounts), or schedule a future replacement Off over an existing published Shift, then record actual Rest before marking it Taken. Changes to published plans and compensation are audited. Attendance and QR punches remain independent evidence, never filled by roster generation.
5. Test a full-year rotation, per-date override, cross-year leave and Off-day settlement on a staging MySQL/MariaDB copy before production. The live CI suite exercises these endpoints on both supported engines; the unit suite alone cannot validate migrations or SQL.

### Defect and forecast workflow rollout (0015–0016)

1. Back up the database and pause application writes. Apply all pending
   migrations, deploy the matching application code to **all** instances, and
   verify the schema before allowing writes again. Older code can automatically
   close defects on an inspection pass, so mixed-version operation is unsafe.
2. Migration 0015 adds nullable columns, a unique manual-submission token and
   `backlog_events`; it does not split, relabel or rewrite historical rows. A
   pre-existing closure is displayed as **verification not confirmed**, even
   if an older automated process populated a verifier field. Investigate the
   underlying evidence rather than treating this label as a finding of error.
3. An actual `Planned`/`Issued` maintenance job, not a date on a defect, counts
   as scheduled. Technician dates/durations are proposals; a supervisor must
   commit them. A failed change leaves the existing job intact. Manual forecast
   overrides still take precedence, and published weeks remain frozen; raising
   a revision does not unlock their jobs.
4. A historical completion may have an unknown completion date and verifier.
   The supervisor's present review is recorded separately from completion and
   any personal verification. Closed and cancelled defect corrections need a
   reason. Deletion is disabled; cancel an incorrect record with a reason.
5. If 0015 is absent or partially applied, open reporting still works, but
   closure/cancellation, audited amendments and committed scheduling are
   unavailable. A planning request is retained as a note, not a plan. Run role,
   legacy-data, rollback, idempotency and published-week checks on MySQL or
   MariaDB before production; see `tests/README.md`. The token index must be a
   unique, single-column, full-length index on `submit_token`; a partial or
   incorrectly named/indexed migration is not workflow-ready.
6. Migration 0016 creates `forecast_write_lock` and its singleton row. The
   application takes this **global** InnoDB lock in a fresh transaction before
   reading or writing a plan, including multi-week jobs; it keeps the lock
   through publication or all job, ledger and defect updates. Missing or
   malformed lock/schema, non-InnoDB participating tables and lock timeouts
   block planning safely. `scripts/migrate.php --verify` checks the lock and
   participating engines; repair malformed pre-existing tables before rollout.
   All application instances must use the new code. Direct SQL and older code
   bypass the lock, so do not mix them with live planning. Run the concurrency
   suite against the target MySQL/MariaDB version and rehearse in staging;
   the global lock may cause unrelated weeks to wait during bulk generation.

### Cross-Platform Commands
```bash
# Apply pending migrations
php scripts/migrate.php            # Linux / macOS / cPanel SSH
scripts\migrate.bat                # Windows XAMPP

# Check applied vs pending migrations
php scripts/migrate.php --status

# Run full schema, FK, settings & checklist health verification
php scripts/migrate.php --verify
```

---

## 3. Automated CI/CD (GitHub Actions + Auto-Deploy to XAMPP / VPS / cPanel)

1. **Continuous Integration (`.github/workflows/tests.yml`)** — runs automatically on every `push` and `pull_request`:
   - Lints all `.php` files and runs `php tests/backlog_workflow.php` and `php tests/run.php` across **PHP 8.2, 8.3, and 8.4**
   - Tests fresh `schema.sql` load, incremental migration `0019`, repeatable migrations through `0019` (skipping the ledger-only `0017` on force), and live authenticated HTTP + `.xlsx` exports against **both MySQL 8.0 and MariaDB 10.4 (XAMPP engine)**
2. **Continuous Deployment (`.github/workflows/deploy.yml` + XAMPP automation)**:
   - **Option A — Local / On-Premises Windows XAMPP (Zero Inbound Ports)**:
     - **Scheduled Auto-Pull + Migrate (Simplest — 1 command)**:
       ```powershell
       powershell -ExecutionPolicy Bypass -File scripts\deploy-xampp.ps1 -InstallScheduledTask
       ```
       Polls `origin/main` every 5 minutes, fast-forwards new commits, runs `php scripts/migrate.php --verify`, runs `tests/run.php`, and checks `http://127.0.0.1/wmsv2/login`.
     - **Instant Push-Triggered Deploy via GitHub Self-Hosted Runner**:
       Install GitHub's self-hosted runner on the XAMPP machine (**Settings → Actions → Runners → New self-hosted runner → Windows**) and set repository variable `ENABLE_XAMPP_RUNNER=true` (and optional `XAMPP_APP_DIR=C:\xampp\htdocs\wmsv2`). Every push to `main` triggers `deploy-xampp` immediately after `validate` passes.
     - **Local Git Pull Hook**: Run `php scripts/install-git-hooks.php` once so every manual `git pull` automatically migrates and tests.
   - **Option B — Remote VPS / Cloud / SSH-enabled cPanel**: Set GitHub Secrets `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY`, and `DEPLOY_PATH`.
   - **Option C — Shared cPanel without SSH (FTPS)**: Set GitHub Secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, and `FTP_SERVER_DIR`.
