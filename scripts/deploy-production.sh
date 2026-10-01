#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Production deploy. Deliberately more conservative than staging:
#   - a verified backup is taken BEFORE any migration;
#   - migrations are shown before they run;
#   - the app goes into maintenance mode only while migrations run;
#   - a failed health check rolls back to the previous commit automatically.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

DEPLOY_PATH="${PRODUCTION_PATH:-/var/www/leads-crm/production}"
BRANCH="${DEPLOY_BRANCH:-main}"
COMMIT="${DEPLOY_COMMIT:-}"
COMPOSE_FILE="docker-compose.production.yml"
ENV_FILE=".env.production"
HEALTH_URL="${PRODUCTION_HEALTH_URL:-http://127.0.0.1:9081/api/health}"

say() { printf '\n\033[1;36m==>\033[0m %s\n' "$1"; }

cd "$DEPLOY_PATH"

compose() { docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"; }

PREVIOUS_COMMIT="$(git rev-parse HEAD)"
BACKUP_PATH=""

rollback() {
  say "Deploy failed — rolling back to ${PREVIOUS_COMMIT:0:8}"
  git reset --hard "$PREVIOUS_COMMIT"
  compose up -d --build
  compose exec -T backend php artisan up || true

  cat >&2 <<EOF

  Code has been rolled back.

  The database was NOT rolled back: a migration that already ran stays applied.
  If this deploy included a destructive migration, restore the backup manually:

      ./scripts/restore-db.sh production ${BACKUP_PATH:-<backup file>}

EOF
  exit 1
}
trap rollback ERR

say "Fetching ${BRANCH}"
git fetch --prune origin "$BRANCH"
git reset --hard "${COMMIT:-origin/$BRANCH}"
say "Now at $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

say "Building images"
compose build --pull

say "Starting data services"
compose up -d postgres redis
until compose exec -T postgres pg_isready >/dev/null 2>&1; do sleep 1; done

say "Backing up the production database"
BACKUP_PATH="$("$DEPLOY_PATH/scripts/backup-db.sh" production pre-deploy | tail -1)"
say "Backup: ${BACKUP_PATH}"

say "Pending migrations"
compose run --rm backend php artisan migrate:status | grep -i pending || echo "  (none)"

say "Starting the application"
compose up -d --remove-orphans

# A short maintenance window only while the schema changes; `--secret` lets the
# team verify the release before it is reopened to customers.
say "Entering maintenance mode"
compose exec -T backend php artisan down --render="errors::503" --retry=30 \
  --secret="${MAINTENANCE_SECRET:-deploy-preview}" || true

say "Running migrations"
compose exec -T backend php artisan migrate --force

say "Rebuilding caches"
compose exec -T backend php artisan config:cache
compose exec -T backend php artisan route:cache
compose exec -T backend php artisan event:cache

say "Leaving maintenance mode"
compose exec -T backend php artisan up

say "Restarting workers onto the new code"
compose exec -T backend php artisan horizon:terminate || true
compose restart queue horizon scheduler

say "Health check"
for attempt in $(seq 1 45); do
  if curl -fsS "$HEALTH_URL" | grep -q '"status":"healthy"'; then
    say "Healthy after ${attempt}s"
    trap - ERR
    echo "$PREVIOUS_COMMIT" > .last-good-commit
    say "Production is live at https://${PRODUCTION_DOMAIN:-app.example.com}"
    exit 0
  fi
  sleep 1
done

echo "Health check never passed." >&2
compose logs --tail=50 backend >&2
false
