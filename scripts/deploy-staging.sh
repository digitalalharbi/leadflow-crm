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

# DEPLOY_SECRET is read from the environment file rather than passed from CI:
# it belongs to the server, and sending it on the SSH command line would put it
# in the process list for every user on the box. Only this one variable is
# lifted out, so sourcing the file cannot clobber the script's own state.
if [ -f "$ENV_FILE" ]; then
  DEPLOY_SECRET=$(sed -n 's/^DEPLOY_SECRET=//p' "$ENV_FILE" | tail -1 | tr -d "\"'")
  export DEPLOY_SECRET
fi

# Remember where we were, so a failed deploy can go straight back.
PREVIOUS_COMMIT="$(git rev-parse HEAD)"
echo "$PREVIOUS_COMMIT" > .last-good-commit.tmp

rollback() {
  say "Deploy failed — rolling back to ${PREVIOUS_COMMIT:0:8}"
  git reset --hard "$PREVIOUS_COMMIT"
  # Rebuilt with the previous commit's marker, so the health endpoint reports
  # what is actually running rather than what we tried to ship.
  DEPLOY_COMMIT="$PREVIOUS_COMMIT" compose up -d --build

  # Staging restores its database as well, because a migration that ran before
  # the failure leaves a schema the old code cannot read — and here there is no
  # customer data to lose by going back. Production deliberately does not do
  # this; see the note in deploy-production.sh.
  if [ -n "${BACKUP_PATH:-}" ] && [ -f "$BACKUP_PATH" ]; then
    say "Restoring the database from ${BACKUP_PATH}"
    FORCE_RESTORE=1 ./scripts/restore-db.sh staging "$BACKUP_PATH" || \
      echo "Database restore failed — staging schema may be ahead of its code." >&2
  fi

  compose exec -T backend php artisan up || true
  say "Rolled back"
  exit 1
}

BACKUP_PATH=""
trap rollback ERR

say "Fetching ${BRANCH}"
git fetch --prune origin "$BRANCH"
git reset --hard "${COMMIT:-origin/$BRANCH}"
say "Now at $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

say "Building images"
# Exported before the build so the image can carry its own identity; see the
# APP_RELEASE arg in docker/php/Dockerfile.
export DEPLOY_COMMIT="$(git rev-parse HEAD)"
compose build --pull

say "Starting data services"
compose up -d postgres redis
until compose exec -T postgres pg_isready >/dev/null 2>&1; do sleep 1; done

# Staging is disposable, but a dump still costs seconds and saves hours — and
# the path is what lets the rollback below undo a migration, not just the code.
say "Backing up the staging database"
BACKUP_PATH="$("$DEPLOY_PATH/scripts/backup-db.sh" staging pre-deploy | tail -1)" \
  || { BACKUP_PATH=""; echo "Backup skipped (first deploy?)"; }

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


say "Verifying the deployed release over HTTP"
# The container can answer every health check perfectly while still serving the
# previous build — a restart that silently failed looks exactly like a success.
# So the gate is not "something responds", it is "the commit I just deployed is
# the commit answering", asserted through the same HTTP path a user takes.
EXPECTED="$(git rev-parse HEAD)"
SERVING=""
for attempt in $(seq 1 30); do
  BODY="$(curl -fsS -H "X-Deploy-Secret: ${DEPLOY_SECRET:-}" "$HEALTH_URL" 2>/dev/null || true)"

  if printf '%s' "$BODY" | grep -q '"status":"healthy"'; then
    SERVING="$(printf '%s' "$BODY" | sed -n 's/.*"release":"\([0-9a-f]\{40\}\)".*/\1/p')"

    if [ -z "${DEPLOY_SECRET:-}" ]; then
      say "Healthy after ${attempt}s (DEPLOY_SECRET unset — release not asserted)"
      break
    fi

    if [ "$SERVING" = "$EXPECTED" ]; then
      say "Healthy after ${attempt}s, serving ${EXPECTED:0:8}"
      break
    fi
  fi

  SERVING=""
  sleep 1
done

if [ -n "${DEPLOY_SECRET:-}" ] && [ "$SERVING" != "$EXPECTED" ]; then
  echo "The deployed release never became live." >&2
  echo "  expected ${EXPECTED:0:8}, serving ${SERVING:-<none>}" >&2
  compose logs --tail=50 backend >&2
  false
fi

trap - ERR
mv .last-good-commit.tmp .last-good-commit
say "Staging is live${STAGING_DOMAIN:+ at https://$STAGING_DOMAIN}"
exit 0
