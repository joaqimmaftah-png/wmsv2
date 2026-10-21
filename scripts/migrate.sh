#!/usr/bin/env bash
# Cross-platform shell wrapper around scripts/migrate.php.
# Preserves backward compatibility with `DB_HOST`, `DB_USER`, `DB_PASS`,
# `DB_NAME` (or first positional argument `<db_name>`) while gaining full
# `schema_migrations` ledger tracking, `--status`, `--schema`, and `--verify`.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

ARGS=()
if [ "$#" -gt 0 ] && [[ "$1" != --* ]]; then
    export WMS_DB_NAME="$1"
    shift
fi

exec "$PHP_BIN" "$ROOT_DIR/scripts/migrate.php" "$@"
