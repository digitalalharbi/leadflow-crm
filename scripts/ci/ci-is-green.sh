#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Is CI green for this commit? Used by the deploy workflows so that a deploy
# never ships code CI has not passed — without CI and deploy being one workflow
# (CI decides whether the code is healthy; a deploy only asks).
#
#   scripts/ci/ci-is-green.sh [sha] [max-wait-seconds]
#
# Always exits 0 and prints one line, `green=true|false|blocked`, so the caller can
# branch without `continue-on-error`:
#   true     CI finished for this commit and every job passed
#   false    CI finished and something failed or was cancelled, or it never finished
#   blocked  CI could not start (GitHub Actions billing) — not a code failure
#
# Only success is green: a cancelled or timed-out run, a run still going at the
# deadline and a commit with no run are all false.
#
# It reads the run that tested this exact commit (a push, or a re-run by hand). A pull
# request whose head is the same commit — main syncing back into develop — tests a merge
# with another branch, so it is not this commit's answer, and starting one must not
# replace it.
#
# When the answer is not true it says why: workflow, each job, its conclusion, how long it
# took and whether it failed, was cancelled, timed out or is pending. To stderr and the job
# summary, so stdout stays the one line.
# ---------------------------------------------------------------------------
set -uo pipefail

SHA="${1:-${GITHUB_SHA:-$(git rev-parse HEAD)}}"
WAIT="${2:-12000}"
REPO="${GITHUB_REPOSITORY:-$(gh repo view --json nameWithOwner -q .nameWithOwner)}"
STARTED=$(date +%s)
DEADLINE=$(( STARTED + WAIT ))
HEARTBEAT="${CI_GATE_HEARTBEAT:-300}"
POLL="${CI_GATE_POLL:-30}"
NEXT_HEARTBEAT=$(( STARTED + HEARTBEAT ))

say() { printf '%s\n' "$*" >&2; [ -z "${GITHUB_STEP_SUMMARY:-}" ] || printf '%s\n\n' "$*" >> "$GITHUB_STEP_SUMMARY"; }

# passed | failed | cancelled | timed out | pending | skipped, from what GitHub reports. A job
# stopped by its own timeout-minutes is reported as `cancelled`; its annotation tells them apart.
classify() {
  local job_id="$1" status="$2" conclusion="$3"

  if [ "$status" != "completed" ]; then echo "pending"; return; fi

  case "$conclusion" in
    success) echo "passed" ;;
    skipped) echo "skipped" ;;
    timed_out) echo "timed out" ;;
    cancelled)
      if gh api "repos/${REPO}/check-runs/${job_id}/annotations" --jq '.[].message' 2>/dev/null | grep -qi 'exceeded the maximum execution time'; then
        echo "timed out"
      else
        echo "cancelled"
      fi
      ;;
    *) echo "failed" ;;
  esac
}

diagnose() {
  local run_id="$1" job_id name status conclusion seconds state

  say "CI gate: workflow \"CI\", run ${run_id} — https://github.com/${REPO}/actions/runs/${run_id} — commit ${SHA}, waited $(( $(date +%s) - STARTED ))s of ${WAIT}s."

  while IFS=$'\t' read -r job_id name status conclusion seconds; do
    [ -n "$job_id" ] || continue
    state="$(classify "$job_id" "$status" "$conclusion")"
    say "- job: ${name} | conclusion: ${conclusion:-none} | elapsed: $(( seconds / 60 ))m$(( seconds % 60 ))s | ${state}"
  done < <(gh api "repos/${REPO}/actions/runs/${run_id}/jobs?per_page=50" --jq '.jobs[] | [.id, .name, .status, (.conclusion // ""), ((((.completed_at // (now | todate)) | fromdate) - ((.started_at // (now | todate)) | fromdate)) | floor)] | @tsv' 2>/dev/null)
}

RUN_ID=""

while :; do
  ROW="$(gh api "repos/${REPO}/actions/workflows/ci.yml/runs?head_sha=${SHA}&per_page=30" --jq '[.workflow_runs[] | select(.event == "push" or .event == "workflow_dispatch")][0] | select(. != null) | [.id, .status, (.conclusion // "")] | @tsv' 2>/dev/null || true)"

  if [ -n "$ROW" ]; then
    RUN_ID="$(printf '%s' "$ROW" | cut -f1)"; STATUS="$(printf '%s' "$ROW" | cut -f2)"; CONCLUSION="$(printf '%s' "$ROW" | cut -f3)"

    if [ "$STATUS" = "completed" ]; then
      if [ "$CONCLUSION" = "success" ]; then echo "green=true"; exit 0; fi

      # A job that never starts has no steps and an annotation naming the billing hold.
      BILLING="$(gh api "repos/${REPO}/actions/runs/${RUN_ID}/jobs" --jq '.jobs[] | select(.conclusion=="failure") | .id' 2>/dev/null | head -1 \
        | xargs -I{} gh api "repos/${REPO}/check-runs/{}/annotations" --jq '.[].message' 2>/dev/null | grep -ci 'payments have failed\|spending limit' || true)"

      if [ "${BILLING:-0}" -gt 0 ]; then say "CI could not start: GitHub Actions billing."; echo "green=blocked"; exit 0; fi

      say "CI finished with: ${CONCLUSION:-unknown}. Nothing was deployed."
      diagnose "$RUN_ID"
      echo "green=false"
      exit 0
    fi
  fi

  NOW=$(date +%s)

  if [ "$NOW" -ge "$DEADLINE" ]; then
    if [ -n "$RUN_ID" ]; then
      say "CI did not finish within ${WAIT}s (pending). Nothing was deployed."
      diagnose "$RUN_ID"
    else
      say "No CI run for ${SHA} after ${WAIT}s (pending: never started). Nothing was deployed."
    fi

    echo "green=false"
    exit 0
  fi

  if [ "$NOW" -ge "$NEXT_HEARTBEAT" ]; then
    printf 'Waiting for CI on %s: %ss of %ss, run %s (%s).\n' "$SHA" "$(( NOW - STARTED ))" "$WAIT" "${RUN_ID:-not started}" "${STATUS:-none}" >&2
    NEXT_HEARTBEAT=$(( NOW + HEARTBEAT ))
  fi

  sleep "$POLL"
done
