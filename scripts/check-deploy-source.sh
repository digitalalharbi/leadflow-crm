#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Fails if the repository stops being the only source of a production release.
#
# Production has one road: PR -> CI -> develop -> main -> the "Deploy production"
# workflow -> the server fetches one exact commit from GitHub. This checks the
# things that keep it that way, so a later change cannot quietly open a second road:
#
#   - nothing in the deploy path uploads or copies files (scp, rsync, ftp, sftp)
#   - there is no deploy-from-a-machine script for production
#   - every PRODUCTION_BRANCH default is `main`
#   - the workflow refuses any ref but main, checks the server's tree, and smoke-tests
#   - the on-server scripts refuse a dirty tree before they arm a rollback
#   - the image build ignores env files, node_modules, tests, logs and dev leftovers
#
# Run by the "Deploy production" workflow and by the backend test suite.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

cd "$(dirname "$0")/.."

FAILED=0
fail() { echo "::error::$1"; echo "  FAIL  $1"; FAILED=1; }
pass() { echo "  ok    $1"; }
# Executable lines only: comments and documentation may name these tools (this file does).
code() { grep -vE '^[[:space:]]*#' "$1" | grep -vE '^[[:space:]]*-?[[:space:]]*(echo|printf)[[:space:]]'; }
line_of() { grep -nE "$1" "$2" | head -1 | cut -d: -f1; }

WF=.github/workflows/deploy-production.yml
PROD=scripts/deploy-production.sh
STAG=scripts/deploy-staging.sh

# 1. Nothing uploads or copies files. Assembled from fragments so this file never matches itself.
UPLOAD="(^|[^[:alnum:]_.-])(s""cp|rs""ync|s""ftp|f""tp|lf""tp|curlftpfs)([[:space:]]|$)"
HITS=""
for file in $(find scripts -name '*.sh' ! -name 'check-deploy-source.sh') .github/workflows/*.yml; do
  found="$(code "$file" | grep -nE "$UPLOAD" || true)"
  [ -z "$found" ] || HITS="${HITS}${file}: ${found}"$'\n'
done
[ -z "$HITS" ] && pass "no file upload or copy in the deploy path" || { fail "a deploy script uploads or copies files:"; printf '%s' "$HITS"; }

# 2. No second road to production.
[ ! -e scripts/release-production.sh ] && pass "no release-from-a-machine script" || fail "scripts/release-production.sh exists: production is released only by the workflow"
grep -q 'Refusing: production is not deployed from a machine' scripts/deploy-direct.sh \
  && pass "deploy-direct.sh refuses production" || fail "scripts/deploy-direct.sh must refuse production"
if code scripts/deploy-direct.sh | grep -qE 'ON_SERVER=deploy-production\.sh'; then fail "deploy-direct.sh can still run deploy-production.sh"; else pass "deploy-direct.sh cannot reach deploy-production.sh"; fi

# 3. Production source is main.
BAD="$(grep -rnE 'PRODUCTION_BRANCH(:-|=)' scripts .deploy.env.example docs 2>/dev/null | grep -vE '^[^:]+:[0-9]+:[[:space:]]*#' | grep -vE 'PRODUCTION_BRANCH(:-|=)"?main' | grep -vE 'check-deploy-source.sh' || true)"
[ -z "$BAD" ] && pass "every PRODUCTION_BRANCH default is main" || { fail "PRODUCTION_BRANCH must default to main:"; echo "$BAD"; }
grep -q 'DEPLOY_BRANCH=main' "$WF" && pass "workflow deploys main" || fail "$WF must export DEPLOY_BRANCH=main"
grep -q 'BRANCH" = "main"' "$PROD" && pass "deploy-production.sh refuses any branch but main" || fail "$PROD must refuse any branch but main"

# 4. The workflow's guards, and the smoke test after the health check.
grep -q "refs/heads/main" "$WF" && grep -q 'Refuse any ref but main' "$WF" && pass "workflow refuses any ref but main" || fail "$WF needs an explicit refs/heads/main guard"
[ "$(grep -c "github.ref == 'refs/heads/main'" "$WF")" -ge 2 ] && pass "verify and deploy are locked to main" || fail "the verify and deploy jobs must both require github.ref == 'refs/heads/main'"
grep -q 'ci-is-green.sh' "$WF" && pass "workflow requires CI green for the exact SHA" || fail "$WF must run ci-is-green.sh"
grep -q 'working tree is not clean' "$WF" && pass "workflow refuses a dirty server tree" || fail "$WF must check the server working tree over SSH"
HEALTH="$(line_of 'Verify production is healthy' "$WF" || true)"; SMOKE="$(line_of 'smoke-production.sh' "$WF" || true)"
{ [ -n "$HEALTH" ] && [ -n "$SMOKE" ] && [ "$SMOKE" -gt "$HEALTH" ]; } && pass "smoke test runs after the health check" || fail "$WF must run smoke-production.sh after the health check"

# 5. The on-server scripts refuse a dirty tree BEFORE a rollback is armed.
for file in "$PROD" "$STAG"; do
  guard="$(line_of '^require_clean_worktree' "$file" || true)"; trap_line="$(line_of '^trap rollback ERR' "$file" || true)"
  { [ -n "$guard" ] && [ -n "$trap_line" ] && [ "$guard" -lt "$trap_line" ]; } && pass "$file checks the tree before arming the rollback" || fail "$file must call require_clean_worktree before 'trap rollback ERR'"
done
[ -f scripts/lib/worktree-guard.sh ] && pass "worktree guard present" || fail "scripts/lib/worktree-guard.sh is missing"

# 6. The image gets nothing that is not in a commit and needed by a build.
if [ -f .dockerignore ]; then
  for entry in '.git' '.env' '**/.env' '**/.env.*' 'backend/vendor' 'backend/tests' 'backend/storage/logs/*' 'frontend/node_modules' 'frontend/dist' 'frontend/e2e' '**/*.log' 'backups'; do
    grep -qxF "$entry" .dockerignore || fail ".dockerignore must list '$entry'"
  done
  [ "$FAILED" -eq 0 ] && pass ".dockerignore keeps env files, node_modules, tests, logs and dev leftovers out of the image"
else
  fail ".dockerignore is missing"
fi

# 7. Untracked state the deploy writes is ignored, or the clean-tree guard would refuse the next deploy.
grep -qxF '.last-good-commit' .gitignore && pass ".last-good-commit is git-ignored" || fail ".gitignore must list .last-good-commit"

if [ "$FAILED" -ne 0 ]; then
  echo ""
  echo "  The deploy path is not the single, GitHub-only road it is meant to be."
  exit 1
fi

echo ""
echo "  Production can only be released from GitHub: main, CI-checked, by the workflow."
