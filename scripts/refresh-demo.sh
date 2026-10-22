#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Rebuilds the demo tenant on staging, so a prospect always sees a full,
# believable account rather than whatever the last demo left behind.
#
# Deliberately NOT part of the deploy: it force-deletes the demo tenant, and a
# deploy should never be able to destroy data on its own. Run it when you want a
# clean slate.
#
#   ./scripts/refresh-demo.sh staging
# ---------------------------------------------------------------------------
set -Eeuo pipefail

ENVIRONMENT="${1:-staging}"

if [ "$ENVIRONMENT" = "production" ]; then
  echo "Refusing: demo data must never be seeded into production." >&2
  exit 1
fi

cd "$(dirname "$0")/.."

COMPOSE_FILE="docker-compose.${ENVIRONMENT}.yml"
ENV_FILE=".env.${ENVIRONMENT}"

[ -f "$COMPOSE_FILE" ] || { echo "No compose file for '${ENVIRONMENT}'." >&2; exit 1; }

compose() { docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"; }

cat >&2 <<EOF

  This replaces the demo tenant on ${ENVIRONMENT} with a fresh dataset.
  Anything entered into that tenant since the last refresh is lost.
  Other tenants are untouched.

EOF

if [ "${FORCE_REFRESH:-0}" != "1" ]; then
  read -r -p "Type the environment name to continue: " CONFIRM
  [ "$CONFIRM" = "$ENVIRONMENT" ] || { echo "Aborted."; exit 1; }
fi

echo "==> Taking a backup first"
./scripts/backup-db.sh "$ENVIRONMENT" pre-demo-refresh || echo "Backup skipped"

echo "==> Reseeding the demo tenant"
compose exec -T backend php artisan db:seed --class=DemoSeeder --force

cat <<EOF

  Demo data refreshed.

  Sign in with the credentials in README-DEMO.md, and use the
  "Simulate an incoming lead" button on the Integrations page to show a lead
  arriving live during a demo.

EOF
