#!/usr/bin/env bash
# Restores a dump produced by backup-db.sh. Destructive, so it asks first.
#
#   ./scripts/restore-db.sh staging ./backups/staging/staging-manual-....sql.gz
set -Eeuo pipefail

ENVIRONMENT="${1:-}"
BACKUP_FILE="${2:-}"

if [ -z "$ENVIRONMENT" ] || [ -z "$BACKUP_FILE" ]; then
  echo "Usage: $0 <staging|production> <backup.sql.gz>" >&2
  exit 1
fi

cd "$(dirname "$0")/.."

COMPOSE_FILE="docker-compose.${ENVIRONMENT}.yml"
ENV_FILE=".env.${ENVIRONMENT}"

[ -f "$BACKUP_FILE" ] || { echo "No such backup: ${BACKUP_FILE}" >&2; exit 1; }
gzip -t "$BACKUP_FILE" || { echo "Backup is corrupt." >&2; exit 1; }

# shellcheck source=lib/env-file.sh
. "scripts/lib/env-file.sh"
load_env_file "$ENV_FILE"

cat >&2 <<EOF

  This will REPLACE the contents of '${DB_DATABASE}' (${ENVIRONMENT})
  with ${BACKUP_FILE}. Current data will be lost.

EOF

if [ "${FORCE_RESTORE:-0}" != "1" ]; then
  read -r -p "Type the environment name to continue: " CONFIRM
  [ "$CONFIRM" = "$ENVIRONMENT" ] || { echo "Aborted."; exit 1; }
fi

compose() { docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"; }

echo "==> Stopping workers so nothing writes mid-restore"
compose stop horizon scheduler || true

echo "==> Restoring"
gunzip -c "$BACKUP_FILE" | compose exec -T postgres \
  psql -v ON_ERROR_STOP=1 -U "$DB_USERNAME" -d "$DB_DATABASE"

echo "==> Restarting workers"
compose up -d horizon scheduler

echo "==> Restore complete"
