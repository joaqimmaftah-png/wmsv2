# Database Schema, Migrations & XAMPP / CI-CD Guide

## 1. Fresh Installation (XAMPP, Docker, Linux, or cPanel)

`database/schema.sql` contains the complete, production-ready baseline schema:
- Application tables plus the `schema_migrations` tracking ledger (including `backlog_events`)
- Single-column `PRIMARY KEY (id)` on `ci_sessions` aligned with `$config['sess_match_ip'] = FALSE` (prevents IPv4/IPv6 `127.0.0.1` vs `::1` session/CSRF mismatches on Windows XAMPP)
- All **93 InnoDB foreign-key constraints**, unique constraints, and reporting indexes
- All **29 documented `settings` defaults** (`Settings_model::DEFAULTS`)
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

## 2. Upgrading an Existing Database (`0001` – `0016`)

Every file in `database/migrations/` is **strictly idempotent** (safe on both **MySQL 8.0+** and **MariaDB 10.4+** without non-portable `IF NOT EXISTS` DDL syntax) and tracked in `schema_migrations`:

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
   - Tests fresh `schema.sql` load, double-idempotent execution of `0001`–`0016`, and live authenticated HTTP + `.xlsx` export verification against **both MySQL 8.0 and MariaDB 10.4 (XAMPP engine)**
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
