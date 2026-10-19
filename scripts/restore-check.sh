#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Proves a backup can actually be restored — without touching the live database.
#
# Restores the newest (or a named) dump into a throw-away Postgres container
# with no network and compares the migration count with the live database.
# Run ON THE SERVER, from the production folder:
#
#   ./scripts/restore-check.sh production [backup.sql.gz]
#
# restore-db.sh is the real, destructive restore. This is the rehearsal.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

ENVIRONMENT="${1:-production}"
BACKUP="${2:-}"

cd "$(dirname "$0")/.."
COMPOSE_FILE="docker-compose.${ENVIRONMENT}.yml"
ENV_FILE=".env.${ENVIRONMENT}"
[ -f "$ENV_FILE" ] || { echo "Missing ${ENV_FILE}." >&2; exit 1; }

# shellcheck disable=SC1090
set -a; source "$ENV_FILE"; set +a

[ -n "$BACKUP" ] || BACKUP="$(ls -1t "./backups/${ENVIRONMENT}/"*.sql.gz 2>/dev/null | head -1 || true)"
[ -n "$BACKUP" ] && [ -f "$BACKUP" ] || { echo "No backup found in ./backups/${ENVIRONMENT}." >&2; exit 1; }
gzip -t "$BACKUP" || { echo "Backup is corrupt: $BACKUP" >&2; exit 1; }

NAME="leadflow-restore-check-$$"
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "==> Restoring $(basename "$BACKUP") into a disposable database"
docker run -d --name "$NAME" --network none -e POSTGRES_PASSWORD=check postgres:16-alpine >/dev/null
until docker exec "$NAME" pg_isready -U postgres >/dev/null 2>&1; do sleep 1; done

gunzip -c "$BACKUP" | docker exec -i "$NAME" psql -q -v ON_ERROR_STOP=1 -U postgres >/dev/null

count() { docker exec "$NAME" psql -Atq -U postgres -c "$1"; }
live() { docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" exec -T postgres psql -Atq -U "$DB_USERNAME" "$DB_DATABASE" -c "$1" | tr -d '\r'; }

restored="$(count 'select count(*) from migrations')"
current="$(live 'select count(*) from migrations')"
tables="$(count "select count(*) from information_schema.tables where table_schema='public'")"

echo "    tables restored:        ${tables}"
echo "    migrations restored:    ${restored}"
echo "    migrations in live db:  ${current}"

# A nightly dump can be one migration behind the live schema right after a
# deploy; it can never be ahead of it, and it can never be empty.
if [ "${restored:-0}" -gt 0 ] && [ "$restored" -le "$current" ]; then
  echo "RESTORE CHECK: PASS ($(basename "$BACKUP"))"
else
  echo "RESTORE CHECK: FAIL" >&2
  exit 1
fi
