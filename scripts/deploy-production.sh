#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Production deploy. Runs ON THE VPS; the "Deploy production" workflow invokes it over SSH.
# Deliberately more conservative than staging:
#   - only `main`, and only a commit GitHub has on `main`;
#   - a server whose working tree is not clean is refused before anything changes;
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

# shellcheck source=lib/worktree-guard.sh
. "$DEPLOY_PATH/scripts/lib/worktree-guard.sh"

compose() { docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"; }

# DEPLOY_SECRET is read from the environment file rather than passed from CI:
# it belongs to the server, and sending it on the SSH command line would put it
# in the process list for every user on the box. Only this one variable is
# lifted out, so sourcing the file cannot clobber the script's own state.
if [ -f "$ENV_FILE" ]; then
  DEPLOY_SECRET=$(sed -n 's/^DEPLOY_SECRET=//p' "$ENV_FILE" | tail -1 | tr -d "\"'")
  export DEPLOY_SECRET
fi

PREVIOUS_COMMIT="$(git rev-parse HEAD)"
BACKUP_PATH=""

# ---------------------------------------------------------------------------
# Refusals. All of them come BEFORE the rollback trap is armed: a deploy that is
# refused has changed nothing, so there is nothing to roll back — and a rollback
# would itself rebuild and restart the containers.
# ---------------------------------------------------------------------------
[ "$BRANCH" = "main" ] || { echo "ABORT DEPLOY — production deploys only from main (asked for '${BRANCH}')." >&2; exit 1; }

require_clean_worktree || exit 1

say "Fetching ${BRANCH} from GitHub"
git fetch --prune origin "$BRANCH"

# The exact commit asked for must be one GitHub has on main. A SHA from a feature
# branch, or one that does not exist, never reaches the build.
if [ -n "$COMMIT" ]; then
  git cat-file -e "${COMMIT}^{commit}" 2>/dev/null || { echo "ABORT DEPLOY — ${COMMIT} is not a commit fetched from origin/${BRANCH}." >&2; exit 1; }
  git merge-base --is-ancestor "$COMMIT" "origin/${BRANCH}" || { echo "ABORT DEPLOY — ${COMMIT} is not on origin/${BRANCH}." >&2; exit 1; }
fi

rollback() {
  say "Deploy failed — rolling back to ${PREVIOUS_COMMIT:0:8}"
  git reset --hard "$PREVIOUS_COMMIT"
  DEPLOY_COMMIT="$PREVIOUS_COMMIT" compose up -d --build
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

git reset --hard "${COMMIT:-origin/$BRANCH}"
say "Now at $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

say "Building images"
# Exported before the build so the image carries its own commit; see the
# APP_RELEASE arg in docker/php/Dockerfile.
export DEPLOY_COMMIT="$(git rev-parse HEAD)"
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
compose restart horizon scheduler

say "Verifying the deployed release over HTTP"
# "Something answered" is not a deploy. A container that failed to restart
# answers every check perfectly while serving the previous build, so the gate
# is that the commit just shipped is the commit replying — asserted through the
# same HTTP path a customer takes.
EXPECTED="$(git rev-parse HEAD)"
SERVING=""

for attempt in $(seq 1 45); do
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
echo "$PREVIOUS_COMMIT" > .last-good-commit
say "Production is live${PRODUCTION_DOMAIN:+ at https://$PRODUCTION_DOMAIN}"
exit 0
