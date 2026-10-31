#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Can this repository actually deploy?
#
# The deploy workflows are written to skip rather than fail when the server is
# not configured — which keeps pull requests unblocked, but means a green
# "Deploy staging" job can mean "deployed" or "did nothing", and the two look
# identical from the outside. This answers the question directly.
#
# Read-only. It prints which secrets exist, never their values.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

cd "$(dirname "$0")/.."

BOLD=$'\033[1m'; RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; OFF=$'\033[0m'
MISSING=0

say()  { printf '%s\n' "$*"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$OFF" "$1"; }
bad()  { printf '  %s✗%s %-22s %s\n' "$RED" "$OFF" "$1" "$2"; MISSING=$((MISSING+1)); }
warn() { printf '  %s!%s %-22s %s\n' "$YELLOW" "$OFF" "$1" "$2"; }

if ! command -v gh >/dev/null 2>&1; then
  say "${RED}gh is not installed${OFF} — install the GitHub CLI, or read the repository"
  say "settings page instead: Settings → Secrets and variables → Actions."
  exit 1
fi

if ! gh auth status >/dev/null 2>&1; then
  say "${RED}gh is not authenticated${OFF} — run: gh auth login"
  exit 1
fi

say ""
say "${BOLD}Deployment readiness${OFF}"
say ""

# Every API call is wrapped: a flaky connection should report "could not check"
# rather than hang for a minute and a quarter per call.
api() {
  local out
  if ! out=$(GH_PROMPT_DISABLED=1 "$@" 2>/dev/null); then
    return 1
  fi
  printf '%s\n' "$out"
}

# --- Secrets the deploy jobs read -------------------------------------------
if ! SECRETS=$(api gh secret list --json name --jq '.[].name'); then
  say "${RED}Could not reach the GitHub API.${OFF} Check your connection and try again,"
  say "or read Settings → Secrets and variables → Actions in a browser."
  exit 1
fi

say "${BOLD}Secrets${OFF}  (Settings → Secrets and variables → Actions → Secrets)"

check_secret() {
  local name="$1" hint="$2"
  if printf '%s\n' "$SECRETS" | grep -qx "$name"; then
    ok "$name"
  else
    bad "$name" "$hint"
  fi
}

check_secret VPS_HOST        "server IP or hostname — gates every deploy step"
check_secret VPS_USER        "the dedicated 'leadflow-deploy' user"
check_secret VPS_SSH_KEY     "the PRIVATE half of the deploy key"
check_secret VPS_SSH_KNOWN_HOSTS "pre-verified SSH host key line(s)"
check_secret STAGING_PATH    "/var/www/leads-crm/staging"
check_secret PRODUCTION_PATH "/opt/leadflow-crm"

if printf '%s\n' "$SECRETS" | grep -qx VPS_PORT; then
  ok "VPS_PORT"
else
  warn "VPS_PORT" "optional — defaults to 22"
fi

# --- Variables used for the post-deploy health check -------------------------
say ""
say "${BOLD}Variables${OFF}  (same page → Variables)"

VARS=$(api gh variable list --json name --jq '.[].name' || echo "")

for v in STAGING_DOMAIN PRODUCTION_DOMAIN; do
  if printf '%s\n' "$VARS" | grep -qx "$v"; then
    ok "$v"
  else
    # Not optional any more: the deploy job fails without it, because a run
    # that could not check the deployed environment has not shown it works.
    bad "$v" "required — a deploy that cannot be verified over HTTP fails"
  fi
done

# --- What the workflows will actually do -------------------------------------
# --- The piece that lives on the server, not in GitHub ----------------------
say ""
say "${BOLD}On the server${OFF}  (not a GitHub secret — set in each .env file)"
warn "DEPLOY_SECRET" "without it a deploy cannot prove the new build went live"

say ""
say "${BOLD}Verdict${OFF}"

if [ "$MISSING" -eq 0 ]; then
  say "  ${GREEN}Ready.${OFF} A push to develop deploys staging; a push to main deploys production after CI passes."
else
  say "  ${RED}Not ready — $MISSING required secret(s) missing.${OFF}"
  say ""
  say "  Until they are set, \"Deploy staging\" ends with STAGING NOT CONFIGURED and nothing"
  say "  reaches a server. That is a state to fix, not a failure: CI decides the code's health."
  say ""
  say "  Provision the server first (EXTERNAL_SETUP.md section 2.1), generate a"
  say "  deploy key (2.2), then set the secrets from your own machine:"
  say ""
  say "    gh secret set VPS_HOST        --body '203.0.113.10'"
  say "    gh secret set VPS_USER        --body 'leadflow-deploy'"
  say "    gh secret set VPS_SSH_KEY     < ~/.ssh/leadflow_deploy"
  say "    gh secret set VPS_SSH_KNOWN_HOSTS < ./leadflow-known-hosts"
  say "    gh secret set STAGING_PATH    --body '/var/www/leads-crm/staging'"
  say "    gh secret set PRODUCTION_PATH --body '/opt/leadflow-crm'"
  say ""
  say "  Then re-run this script, and re-run the last deploy workflow."
fi

say ""
exit 0
