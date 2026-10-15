#!/usr/bin/env bash
# Dumps the database to a timestamped, compressed file and prunes old copies.
#
#   ./scripts/backup-db.sh staging
#   ./scripts/backup-db.sh production pre-migration
set -Eeuo pipefail

ENVIRONMENT="${1:-staging}"
LABEL="${2:-manual}"
RETAIN_DAYS="${BACKUP_RETAIN_DAYS:-14}"

cd "$(dirname "$0")/.."

COMPOSE_FILE="docker-compose.${ENVIRONMENT}.yml"
ENV_FILE=".env.${ENVIRONMENT}"

[ -f "$COMPOSE_FILE" ] || { echo "No compose file for '${ENVIRONMENT}'." >&2; exit 1; }
[ -f "$ENV_FILE" ] || { echo "Missing ${ENV_FILE}." >&2; exit 1; }

# shellcheck disable=SC1090
set -a; source "$ENV_FILE"; set +a

BACKUP_DIR="${BACKUP_DIR:-./backups/${ENVIRONMENT}}"
mkdir -p "$BACKUP_DIR"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="${BACKUP_DIR}/${ENVIRONMENT}-${LABEL}-${STAMP}.sql.gz"

echo "==> Backing up ${DB_DATABASE} to ${TARGET}"

docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" exec -T postgres \
  pg_dump --no-owner --no-privileges -U "$DB_USERNAME" "$DB_DATABASE" \
  | gzip -9 > "$TARGET"

# A dump that cannot be read back is not a backup.
if ! gzip -t "$TARGET"; then
  echo "Backup is corrupt; removing it." >&2
  rm -f "$TARGET"
  exit 1
fi

SIZE="$(du -h "$TARGET" | cut -f1)"
echo "==> Backup complete (${SIZE})"

# A copy on the same machine survives a bad migration and does not survive the
# machine. BACKUP_UPLOAD_COMMAND is where off-server storage plugs in without a
# change here — it is run with the file path as its only argument, e.g.
#
#   BACKUP_UPLOAD_COMMAND='rclone copyto "$1" r2:leadflow-backups/$(basename "$1")'
#
# The local copy is kept either way, and a failed upload fails the run: a
# scheduled job that exits 0 having copied nothing is the failure that is found
# on the day the server is lost.
if [ -n "${BACKUP_UPLOAD_COMMAND:-}" ]; then
  echo "==> Copying off the server"
  if ! bash -c "${BACKUP_UPLOAD_COMMAND}" _ "$TARGET"; then
    echo "Off-server copy FAILED. The local backup is intact at ${TARGET}." >&2
    exit 2
  fi
fi

echo "==> Pruning backups older than ${RETAIN_DAYS} days"
find "$BACKUP_DIR" -name '*.sql.gz' -type f -mtime "+${RETAIN_DAYS}" -delete

echo "$TARGET"
