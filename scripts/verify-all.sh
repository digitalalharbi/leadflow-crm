#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Everything the four GitHub Actions workflows run, run here, in their order.
#
#   ./scripts/verify-all.sh            backend + frontend + e2e
#   ./scripts/verify-all.sh --no-e2e   skip the browser run (the slow half)
#
# This exists because a local pass has to mean something specific while Actions
# is not in the path. It mirrors backend-tests.yml, frontend-tests.yml and
# e2e-tests.yml step for step rather than approximating them, and on success it
# writes `.verify-passed` with the commit it ran against — which is what
# deploy-direct.sh refuses to deploy without.
#
# It is not CI and does not claim to be. CI runs on a clean checkout, a clean
# database and a machine doing nothing else; this runs on yours. A failure here
# is worth believing. A pass here is worth rather less, and the one thing it
# cannot tell you is whether the branch still passes beside everybody else's.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

cd "$(dirname "$0")/.."

E2E=1
[ "${1:-}" = "--no-e2e" ] && E2E=0

BOLD=$'\033[1m'; GREEN=$'\033[32m'; RED=$'\033[31m'; DIM=$'\033[2m'; OFF=$'\033[0m'
step() { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$OFF"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$OFF" "$1"; }

STARTED=$(date +%s)
FAILED=""

on_failure() {
  printf '\n%sFailed:%s %s\n\n' "$RED" "$OFF" "${FAILED:-unknown step}" >&2
  printf 'Nothing was stamped. The commit is not verified.\n\n' >&2
}
trap on_failure ERR

run() { FAILED="$1"; shift; "$@"; ok "$FAILED"; }

# --- the whole repository ---------------------------------------------------
step "Repository"
run "no conflict markers" ./scripts/check-conflict-markers.sh

# --- backend-tests.yml ------------------------------------------------------
step "Backend"
cd backend
run "composer.json is valid" composer validate --strict --no-check-publish
run "code style (pint)" ./vendor/bin/pint --test
run "test helpers are sound" ./scripts/check-test-helpers.sh
run "backend suite" php artisan test
# The workflow runs this again on its own so a tenancy failure is never lost in
# a wall of other output. Same here: it is the one suite whose failure means
# customer data crossing a workspace boundary.
run "tenant isolation" php artisan test --filter=TenantIsolationTest
cd ..

# --- frontend-tests.yml -----------------------------------------------------
step "Frontend"
cd frontend
run "lint" npm run lint
run "typecheck" npm run typecheck
run "unit tests" npm run test
run "production build" npm run build
cd ..

# --- e2e-tests.yml ----------------------------------------------------------
if [ "$E2E" = 1 ]; then
  step "End to end"

  # The workflow starts its own servers on 8000/5173. Locally the dev servers
  # are already running on this project's ports, so point Playwright at those
  # instead of starting a second pair that would fight them for the database.
  API="${E2E_API_URL:-http://127.0.0.1:8012}"
  WEB="${E2E_BASE_URL:-http://127.0.0.1:5301}"

  for url in "$API/api/health" "$WEB"; do
    FAILED="the dev servers are running"
    curl -fsS --max-time 5 "$url" >/dev/null 2>&1 || {
      printf '\n%s is not answering.%s\n\n' "$url" "$OFF" >&2
      printf 'Start both dev servers first, or run with --no-e2e.\n\n' >&2
      false
    }
  done
  ok "the dev servers are running"

  FAILED="e2e fixtures"
  (cd backend && php artisan db:seed --class=E2ESeeder --no-interaction >/dev/null)
  ok "$FAILED"

  cd frontend
  FAILED="playwright"
  E2E_NO_SERVER=1 E2E_API_URL="$API" E2E_BASE_URL="$WEB" \
    E2E_BACKEND_DIR="$(cd ../backend && pwd)" npx playwright test
  ok "$FAILED"
  cd ..
fi

trap - ERR

COMMIT="$(git rev-parse HEAD)"
printf '%s' "$COMMIT" > .verify-passed

printf '\n%sPassed%s in %ss on %s%s%s\n' \
  "$GREEN" "$OFF" "$(( $(date +%s) - STARTED ))" "$BOLD" "${COMMIT:0:8}" "$OFF"
printf '%s  %s — a local run, not a CI run%s\n\n' "$DIM" "$(git log -1 --pretty=%s)" "$OFF"
