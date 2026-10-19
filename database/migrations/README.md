# Database migrations

`database/schema.sql` is the **baseline**: a fresh installation imports it and
needs nothing from this directory.

This directory is for a database that already exists — typically one restored
from the production dump before a change shipped — so the schema can be brought
forward without a manual `ALTER TABLE` hunt.

## Running them

```bash
WMS_DB_HOST=localhost \
WMS_DB_USER=wms \
WMS_DB_PASS='…' \
WMS_DB_NAME=wms \
    scripts/migrate.sh --dry-run     # list what is pending
```

Drop `--dry-run` to apply. The runner:

- creates `schema_migrations (filename, applied_at)` if it is missing;
- applies every `*.sql` here in lexical order, once, recording the file name;
- passes credentials through a `0600` temporary defaults file, not the command
  line;
- exits non-zero on the first failure. Re-running is safe because each file
  checks `information_schema` before changing anything — MySQL DDL is not
  transactional, so a file can be half-applied when a connection dies.

Then update `database/schema.sql` if the change alters the baseline (both files
below already are part of it), and keep the matching register row in
`SYSTEM.md` current.

## Files

| File | Change |
|---|---|
| `0001_inspection_items_priority.sql` | K-16 — `inspection_items.priority ENUM('P1','P2','P3')`, explicit defect severity for the template editor |
| `0002_drop_duplicate_import_index.sql` | K-20 — drops `uq_txn_import_key`, the duplicate of `uq_mt_import_key` on `material_transactions.import_key` |

Both changes are optional at runtime: without them the application derives the
severity from the checklist category (exactly as before K-16) and simply keeps
maintaining the duplicate index.

## Adding a migration

1. Name it `NNNN_short_description.sql` (next number, lower-case, no spaces).
2. Make it idempotent — guard every `ALTER` with an `information_schema` check
   and a prepared statement, as the two existing files do.
3. Do **not** edit a file that has already been applied; add a new one.
4. If it changes what a new installation should look like, update
   `database/schema.sql` too.
