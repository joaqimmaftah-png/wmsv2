#!/usr/bin/env bash
#
# Apply pending SQL migrations to an existing database.
#
#     WMS_DB_HOST=localhost WMS_DB_USER=wms WMS_DB_PASS=… WMS_DB_NAME=wms \
#         scripts/migrate.sh [--dry-run]
#
# `database/schema.sql` is the baseline for a new installation. This runner is
# for a database that already exists (typically restored from the production
# dump) and needs the changes made after it was created.
#
# What it does:
#   • creates `schema_migrations` (filename, applied_at) if missing;
#   • applies every `database/migrations/*.sql` in lexical order, exactly once;
#   • in --dry-run mode, lists pending files and connects to nothing that
#     changes (it still reads the database to decide what is pending).
#
# Auth comes from a 0600 temporary defaults file, so the password is not on the
# command line (visible in `ps`) and not in the shell history of a child.

set -euo pipefail

cd "$(dirname "$0")/.."

DRY_RUN=0
case "${1:-}" in
    --dry-run) DRY_RUN=1 ;;
    -h|--help)
        sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
        exit 0
        ;;
    '') ;;
    *)
        echo "usage: $0 [--dry-run]" >&2
        exit 2
        ;;
esac

: "${WMS_DB_NAME:?set WMS_DB_NAME to the database that should be migrated}"
: "${WMS_DB_USER:?set WMS_DB_USER}"
WMS_DB_HOST="${WMS_DB_HOST:-localhost}"
WMS_DB_PASS="${WMS_DB_PASS:-}"

# The application accepts host:port in WMS_DB_HOST; mysql wants them separately.
WMS_DB_PORT=""
if [[ "$WMS_DB_HOST" =~ ^([^:]+):([0-9]+)$ ]]; then
    WMS_DB_HOST="${BASH_REMATCH[1]}"
    WMS_DB_PORT="${BASH_REMATCH[2]}"
fi

defaults_file="$(mktemp)"
chmod 600 "$defaults_file"
cleanup() { rm -f "$defaults_file"; }
trap cleanup EXIT

{
    echo '[client]'
    printf 'host=%s\n' "$WMS_DB_HOST"
    [[ -n "$WMS_DB_PORT" ]] && printf 'port=%s\n' "$WMS_DB_PORT"
    printf 'user=%s\n' "$WMS_DB_USER"
    printf 'password=%s\n' "$WMS_DB_PASS"
} > "$defaults_file"

db() {
    mysql --defaults-extra-file="$defaults_file" --database="$WMS_DB_NAME" "$@"
}

shopt -s nullglob
files=(database/migrations/*.sql)
shopt -u nullglob

if [[ ${#files[@]} -eq 0 ]]; then
    echo "No migration files in database/migrations/ — nothing to do."
    exit 0
fi

has_table="$(db -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'")"

if [[ "$has_table" == "0" && "$DRY_RUN" -eq 0 ]]; then
    db -e "CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   VARCHAR(255) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
fi

pending=0
applied_now=0

for file in "${files[@]}"; do
    name="$(basename "$file")"

    # Our own filenames; a guard so a stray name cannot break the SQL below.
    if [[ ! "$name" =~ ^[A-Za-z0-9._-]+$ ]]; then
        echo "Skipping $name (unexpected file name)" >&2
        continue
    fi

    count=0
    if [[ "$has_table" != "0" ]]; then
        count="$(db -N -B -e "SELECT COUNT(*) FROM schema_migrations WHERE filename = '$name'")"
    fi

    if [[ "$count" != "0" ]]; then
        continue
    fi

    pending=$((pending + 1))

    if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "pending: $name"
        continue
    fi

    echo "applying: $name"
    db < "$file"
    db -e "INSERT INTO schema_migrations (filename) VALUES ('$name')"
    applied_now=$((applied_now + 1))
done

if [[ "$DRY_RUN" -eq 1 ]]; then
    if [[ "$pending" -eq 0 ]]; then
        echo "Database is up to date."
    else
        echo "$pending migration(s) pending."
    fi
else
    echo "Applied $applied_now migration(s); database is up to date."
fi
