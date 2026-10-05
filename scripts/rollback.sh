#!/usr/bin/env bash
# Returns an environment to its previous release. Run on the VPS.
#
#   ./scripts/rollback.sh production            # last known-good commit
#   ./scripts/rollback.sh production <sha|tag>  # a specific release
set -Eeuo pipefail

ENVIRONMENT="${1:-staging}"
TARGET="${2:-}"

case "$ENVIRONMENT" in
  staging)    DEPLOY_PATH="${STAGING_PATH:-/var/www/leads-crm/staging}" ;;
  production) DEPLOY_PATH="${PRODUCTION_PATH:-/var/www/leads-crm/production}" ;;
  *) echo "Usage: $0 <staging|production> [commit-or-tag]" >&2; exit 1 ;;
esac

COMPOSE_FILE="docker-compose.${ENVIRONMENT}.yml"
ENV_FILE=".env.${ENVIRONMENT}"

cd "$DEPLOY_PATH"
compose() { docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"; }

if [ -z "$TARGET" ]; then
  [ -f .last-good-commit ] || { echo "No .last-good-commit recorded; pass a commit." >&2; exit 1; }
  TARGET="$(cat .last-good-commit)"
fi

echo "==> Rolling ${ENVIRONMENT} back to ${TARGET}"
git fetch --prune origin
git reset --hard "$TARGET"

compose up -d --build --remove-orphans

# Schema changes are not undone: a rollback restores code, not data. Only run
# `migrate` so the older code meets a schema it understands.
compose exec -T backend php artisan migrate --force
compose exec -T backend php artisan config:cache
compose exec -T backend php artisan route:cache
compose exec -T backend php artisan horizon:terminate || true
compose restart horizon scheduler

cat <<EOF

  Rolled ${ENVIRONMENT} back to ${TARGET}.

  Note: database migrations are not reversed by this script. If the release you
  rolled back from changed the schema destructively, restore a backup:

      ./scripts/restore-db.sh ${ENVIRONMENT} ./backups/${ENVIRONMENT}/<file>.sql.gz

EOF
