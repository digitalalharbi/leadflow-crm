#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# One answer to "is GitHub sync healthy?" — the same words in every project:
#
#   GREEN    healthy
#   AMBER    waiting, or not configured yet (a state to fix, never a failure)
#   BLOCKED  GitHub Actions cannot start (billing) — not a failure of the code
#   RED      a real failure: a test, a build, a workflow, or a deploy that was
#            attempted and failed
#
# A missing server, secret or credential is AMBER. RED is only ever earned.
#
#   ./scripts/github-status.sh            # table
#   ./scripts/github-status.sh --json     # machine-readable
#
# Exit 1 only when something is RED. Read-only.
# ---------------------------------------------------------------------------
set -uo pipefail
cd "$(dirname "$0")/.."

JSON=0; [ "${1:-}" = "--json" ] && JSON=1
REPO="${GITHUB_REPOSITORY:-digitalalharbi/leadflow-crm}"
INTEGRATION="${INTEGRATION_BRANCH:-develop}"

G=$'\033[32m'; A=$'\033[33m'; R=$'\033[31m'; B=$'\033[1m'; O=$'\033[0m'
ROWS=(); ANY_RED=0

row() { # name state detail
  ROWS+=("$1|$2|$3")
  [ "$2" = "RED" ] && ANY_RED=1
}

# `timeout` is not on a stock macOS: fall back to perl's alarm so the check works on a laptop too.
with_timeout() {
  local secs="$1"; shift
  if command -v timeout >/dev/null 2>&1; then timeout "$secs" "$@"
  elif command -v gtimeout >/dev/null 2>&1; then gtimeout "$secs" "$@"
  else perl -e 'alarm shift; exec @ARGV' "$secs" "$@"; fi
}

api() { # retried: api.github.com is flaky from some networks
  local out i
  for i in 1 2 3; do out="$(GH_PROMPT_DISABLED=1 gh api "$@" 2>/dev/null)" && { printf '%s' "$out"; return 0; }; sleep 3; done
  return 1
}

# Did this run fail because Actions could not start (billing), rather than because of code?
billing_blocked() { # run id
  local job
  job="$(api "repos/${REPO}/actions/runs/$1/jobs" --jq '.jobs[] | select(.conclusion=="failure") | .id' | head -1)"
  [ -n "$job" ] && api "repos/${REPO}/check-runs/${job}/annotations" --jq '.[].message' | grep -qi 'payments have failed\|spending limit'
}

verdict() { # run id, conclusion/status -> state|detail
  local id="$1" status="$2" conclusion="$3"
  if [ "$status" != "completed" ]; then echo "AMBER|running"; return; fi
  case "$conclusion" in
    success) echo "GREEN|passed" ;;
    skipped|neutral|cancelled) echo "AMBER|${conclusion}" ;;
    *) if billing_blocked "$id"; then echo "BLOCKED|BILLING — jobs could not start"; else echo "RED|${conclusion}"; fi ;;
  esac
}

# ---- Repository sync ----------------------------------------------------
URL="$(git remote get-url origin 2>/dev/null || echo '')"
case "$URL" in *"${REPO}"*|*"${REPO}.git"*) row "Repository" GREEN "$REPO" ;; *) row "Repository" RED "origin is '$URL', expected $REPO" ;; esac

if with_timeout 25 git ls-remote --exit-code origin HEAD >/dev/null 2>&1; then row "Fetch" GREEN "git ls-remote origin"; else row "Fetch" RED "cannot reach origin"; fi
if with_timeout 40 git push --dry-run origin "${INTEGRATION}:${INTEGRATION}" >/dev/null 2>&1; then row "Push" GREEN "authenticated, write access"; else row "Push" RED "push --dry-run refused"; fi

PUSH_OK="$(api "repos/${REPO}" --jq '.permissions.push')"
OPEN="$(api "repos/${REPO}/pulls?state=open&per_page=100" --jq 'length')"
if [ "$PUSH_OK" = "true" ]; then row "PR flow" GREEN "can open and merge PRs into ${INTEGRATION} (${OPEN:-0} open)"; elif [ -z "$PUSH_OK" ]; then row "PR flow" AMBER "GitHub API unreachable"; else row "PR flow" RED "no push permission"; fi

# ---- CI -----------------------------------------------------------------
HEAD_SHA="$(git ls-remote origin "refs/heads/${INTEGRATION}" 2>/dev/null | cut -c1-40)"
CI_RUN="$(api "repos/${REPO}/actions/workflows/ci.yml/runs?branch=${INTEGRATION}&per_page=1" --jq '.workflow_runs[0] | [.id, .status, (.conclusion // ""), .head_sha] | @tsv')"

if [ -z "$CI_RUN" ]; then
  # The CI workflow has not run on this branch yet: fall back to the newest run that carried the tests.
  CI_RUN="$(api "repos/${REPO}/actions/runs?branch=${INTEGRATION}&per_page=1" --jq '.workflow_runs[0] | [.id, .status, (.conclusion // ""), .head_sha] | @tsv')"
fi

if [ -n "$CI_RUN" ]; then
  RID="$(printf '%s' "$CI_RUN" | cut -f1)"; RST="$(printf '%s' "$CI_RUN" | cut -f2)"; RCO="$(printf '%s' "$CI_RUN" | cut -f3)"; RSHA="$(printf '%s' "$CI_RUN" | cut -f4)"

  for pair in "Backend CI|backend" "Frontend CI|frontend" "E2E CI|end-to-end"; do
    label="${pair%%|*}"; key="${pair##*|}"
    J="$(api "repos/${REPO}/actions/runs/${RID}/jobs" --jq ".jobs[] | select(.name | ascii_downcase | startswith(\"${key}\")) | [.status, (.conclusion // \"\")] | @tsv" | head -1)"
    if [ -z "$J" ]; then row "$label" AMBER "no run yet"; continue; fi
    v="$(verdict "$RID" "$(printf '%s' "$J" | cut -f1)" "$(printf '%s' "$J" | cut -f2)")"
    row "$label" "${v%%|*}" "${v#*|}"
  done

  v="$(verdict "$RID" "$RST" "$RCO")"
  row "Develop" "${v%%|*}" "${v#*|} (${RSHA:0:8}${HEAD_SHA:+, tip ${HEAD_SHA:0:8}})"
  [ -n "$HEAD_SHA" ] && [ "$RSHA" != "$HEAD_SHA" ] && row "Latest commit" AMBER "CI has not run on ${HEAD_SHA:0:8} yet"
  [ -n "$HEAD_SHA" ] && [ "$RSHA" = "$HEAD_SHA" ] && row "Latest commit" "${v%%|*}" "${HEAD_SHA:0:8}"
else
  for label in "Backend CI" "Frontend CI" "E2E CI" "Develop" "Latest commit"; do row "$label" AMBER "no CI run found"; done
fi

# ---- Staging / Production: configured, then attempted --------------------
SECRETS="$(api "repos/${REPO}/actions/secrets" --jq '.secrets[].name')"
VARS="$(api "repos/${REPO}/actions/variables" --jq '.variables[] | .name + "=" + .value')"
has() { printf '%s\n' "$SECRETS" | grep -qx "$1"; }
hasvar() { printf '%s\n' "$VARS" | grep -q "^$1="; }

last_deploy() { # workflow file -> "state|detail"
  local r
  r="$(api "repos/${REPO}/actions/workflows/$1/runs?per_page=1" --jq '.workflow_runs[0] | [.id, .status, (.conclusion // "")] | @tsv')"
  [ -z "$r" ] && { echo "AMBER|never deployed"; return; }
  verdict "$(printf '%s' "$r" | cut -f1)" "$(printf '%s' "$r" | cut -f2)" "$(printf '%s' "$r" | cut -f3)"
}

missing=""
for n in VPS_HOST VPS_PORT VPS_USER VPS_SSH_KEY VPS_SSH_KNOWN_HOSTS STAGING_PATH; do has "$n" || missing="$missing $n"; done
hasvar STAGING_DOMAIN || missing="$missing STAGING_DOMAIN"
if [ -n "$missing" ]; then
  row "Staging" AMBER "NOT CONFIGURED — missing:$missing"
else
  v="$(last_deploy deploy-staging.yml)"; row "Staging" "${v%%|*}" "${v#*|}"
fi

missing=""
for n in VPS_HOST VPS_PORT VPS_USER VPS_SSH_KEY VPS_SSH_KNOWN_HOSTS PRODUCTION_PATH; do has "$n" || missing="$missing $n"; done
hasvar PRODUCTION_DOMAIN || missing="$missing PRODUCTION_DOMAIN"
printf '%s\n' "$VARS" | grep -q '^PRODUCTION_ENABLED=true' || missing="$missing PRODUCTION_ENABLED"
if [ -n "$missing" ]; then
  row "Production" AMBER "NOT CONFIGURED — missing:$missing"
else
  v="$(last_deploy deploy-production.yml)"; row "Production" "${v%%|*}" "${v#*|}"
fi

# ---- Output ---------------------------------------------------------------
if [ "$JSON" = 1 ]; then
  printf '{'; first=1
  for r in "${ROWS[@]}"; do
    n="${r%%|*}"; rest="${r#*|}"; s="${rest%%|*}"; d="${rest#*|}"
    [ $first = 1 ] || printf ','; first=0
    printf '"%s":{"state":"%s","detail":"%s"}' "$n" "$s" "${d//\"/\'}"
  done
  printf '}\n'
else
  printf '\n%s%s%s  (%s, integration branch: %s)\n\n' "$B" "GitHub sync" "$O" "$REPO" "$INTEGRATION"
  for r in "${ROWS[@]}"; do
    n="${r%%|*}"; rest="${r#*|}"; s="${rest%%|*}"; d="${rest#*|}"
    case "$s" in GREEN) c="$G"; t="GREEN  ";; AMBER) c="$A"; t="AMBER  ";; BLOCKED) c="$A"; t="BLOCKED";; *) c="$R"; t="RED    ";; esac
    printf '  %-14s %s%s%s  %s\n' "$n" "$c" "$t" "$O" "$d"
  done
  printf '\n  GREEN healthy · AMBER waiting / not configured · BLOCKED billing, not code · RED a real failure\n\n'
fi

exit "$ANY_RED"
