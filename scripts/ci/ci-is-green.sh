#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Is CI green for this commit? Used by the deploy workflows so that a deploy
# never ships code CI has not passed — without CI and deploy being one workflow
# (CI decides whether the code is healthy; a deploy only asks).
#
#   scripts/ci/ci-is-green.sh [sha] [max-wait-seconds]
#
# Always exits 0 and prints one line, `green=true|false|blocked`, plus a reason on
# stderr, so the caller can branch without `continue-on-error`:
#   true     CI finished for this commit and every job passed
#   false    CI finished and something really failed, or never ran
#   blocked  CI could not start (GitHub Actions billing) — not a code failure
# ---------------------------------------------------------------------------
set -uo pipefail

SHA="${1:-${GITHUB_SHA:-$(git rev-parse HEAD)}}"
WAIT="${2:-2700}"
REPO="${GITHUB_REPOSITORY:-$(gh repo view --json nameWithOwner -q .nameWithOwner)}"
DEADLINE=$(( $(date +%s) + WAIT ))

while :; do
  ROW="$(gh api "repos/${REPO}/actions/workflows/ci.yml/runs?head_sha=${SHA}&per_page=1" --jq '.workflow_runs[0] | [.id, .status, (.conclusion // "")] | @tsv' 2>/dev/null || true)"

  if [ -n "$ROW" ]; then
    RUN_ID="$(printf '%s' "$ROW" | cut -f1)"; STATUS="$(printf '%s' "$ROW" | cut -f2)"; CONCLUSION="$(printf '%s' "$ROW" | cut -f3)"

    if [ "$STATUS" = "completed" ]; then
      if [ "$CONCLUSION" = "success" ]; then echo "green=true"; exit 0; fi

      # A job that never starts has no steps and an annotation naming the billing hold.
      BILLING="$(gh api "repos/${REPO}/actions/runs/${RUN_ID}/jobs" --jq '.jobs[] | select(.conclusion=="failure") | .id' 2>/dev/null | head -1 \
        | xargs -I{} gh api "repos/${REPO}/check-runs/{}/annotations" --jq '.[].message' 2>/dev/null | grep -ci 'payments have failed\|spending limit' || true)"

      if [ "${BILLING:-0}" -gt 0 ]; then echo "CI could not start: GitHub Actions billing." >&2; echo "green=blocked"; exit 0; fi
      echo "CI finished with: ${CONCLUSION:-unknown}." >&2; echo "green=false"; exit 0
    fi
  fi

  if [ "$(date +%s)" -ge "$DEADLINE" ]; then echo "CI did not finish within ${WAIT}s." >&2; echo "green=false"; exit 0; fi
  sleep 30
done
