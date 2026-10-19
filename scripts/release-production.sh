#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# One command from a clean develop to a verified production release:
#
#   ./scripts/release-production.sh
#
#   preflight  → backup → deploy → migrate → restart workers → health check
#              → smoke test → verification → automatic rollback on any failure
#
# Backup, migrate, worker restart and the on-server health check are
# scripts/deploy-production.sh (run through deploy-direct.sh); this adds the
# strict gate in front and the outside-in gates behind, and undoes the release
# if either of the latter fails.
#
# It deploys PRODUCTION_BRANCH (default: develop). It never touches `main`.
# ---------------------------------------------------------------------------
set -uo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=lib/production-common.sh
. scripts/lib/production-common.sh

say() { printf '\n%s==>%s %s\n' "$BOLD" "$OFF" "$1"; }
export PRODUCTION_BRANCH

say "1/5 Preflight"
if ! scripts/preflight-production.sh; then
  printf '%sRefusing to deploy.%s Fix every FAIL above; nothing was changed on the server.\n\n' "$RED" "$OFF" >&2
  exit 1
fi

git fetch --quiet origin "$PRODUCTION_BRANCH"
COMMIT="$(git rev-parse "origin/${PRODUCTION_BRANCH}")"
PREVIOUS="$(ssh_server "cat '${PRODUCTION_PATH}/.last-good-commit' 2>/dev/null || true")"

say "2/5 Backup, deploy, migrate, restart workers, health check (on the server)"
# The on-server script takes a verified backup first, shows pending migrations,
# runs them in a short maintenance window, restarts Horizon and the scheduler,
# asserts the new commit is the one answering, and rolls itself back if not.
if ! scripts/deploy-direct.sh production; then
  printf '\n%sDeploy failed.%s The on-server script already rolled the code back.\n' "$RED" "$OFF" >&2
  exit 1
fi

rollback() {
  printf '\n%s%s — rolling production back%s\n' "$RED" "$1" "$OFF" >&2
  ssh_server "cd '${PRODUCTION_PATH}' && ./scripts/rollback.sh production" >&2 || true
  printf 'Rolled back to %s. The database is not reversed; the pre-deploy backup is in %s/backups/production.\n\n' "${PREVIOUS:-the last good commit}" "$PRODUCTION_PATH" >&2
  exit 1
}

say "3/5 Smoke test"
EXPECTED_COMMIT="$COMMIT" scripts/smoke-production.sh || rollback "Smoke test failed"

say "4/5 Verification"
scripts/verify-production.sh || rollback "Verification failed"

say "5/5 Done"
printf '%sPRODUCTION VERIFIED%s  https://%s  %s\n\n' "$GREEN" "$OFF" "$PRODUCTION_DOMAIN" "${COMMIT:0:8}"
