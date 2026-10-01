#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Staging deploy. Runs ON THE VPS; CI invokes it over SSH.
#
# The server is never edited by hand: it checks out exactly the commit CI asks
# for, so what runs on staging is always what is on GitHub.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

DEPLOY_PATH="${STAGING_PATH:-/var/www/leads-crm/staging}"
BRANCH="${DEPLOY_BRANCH:-develop}"
COMMIT="${DEPLOY_COMMIT:-}"
COMPOSE_FILE="docker-compose.staging.yml"
ENV_FILE=".env.staging"
HEALTH_URL="${STAGING_HEALTH_URL:-http://127.0.0.1:8081/api/health}"

say() { printf '\n\033[1;36m==>\033[0m %s\n' "$1"; }

cd "$DEPLOY_PATH"

compose() { docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"; }

# Remember where we were, so a failed deploy can go straight back.
PREVIOUS_COMMIT="$(git rev-parse HEAD)"
echo "$PREVIOUS_COMMIT" > .last-good-commit.tmp

rollback() {
  say "Deploy failed — rolling back to ${PREVIOUS_COMMIT:0:8}"
  git reset --hard "$PREVIOUS_COMMIT"
  compose up -d --build
  compose exec -T backend php artisan up || true
  say "Rolled back"
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

# Staging is disposable, but a dump still costs seconds and saves hours.
say "Backing up the staging database"
"$DEPLOY_PATH/scripts/backup-db.sh" staging pre-deploy || echo "Backup skipped (first deploy?)"

say "Starting the application"
compose up -d --remove-orphans

say "Running migrations"
# --force because staging is non-interactive. Never --seed, never :fresh.
compose exec -T backend php artisan migrate --force

say "Rebuilding caches"
compose exec -T backend php artisan config:cache
compose exec -T backend php artisan route:cache
compose exec -T backend php artisan event:cache

say "Restarting workers onto the new code"
compose exec -T backend php artisan horizon:terminate || true
compose restart queue horizon scheduler

say "Health check"
for attempt in $(seq 1 30); do
  if curl -fsS "$HEALTH_URL" | grep -q '"status":"healthy"'; then
    say "Healthy after ${attempt}s"
    trap - ERR
    mv .last-good-commit.tmp .last-good-commit
    say "Staging is live at https://${STAGING_DOMAIN:-staging.example.com}"
    exit 0
  fi
  sleep 1
done

echo "Health check never passed." >&2
compose logs --tail=50 backend >&2
false
