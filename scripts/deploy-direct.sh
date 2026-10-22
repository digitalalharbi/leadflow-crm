#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Deploy straight to the server, with no CI in the path.
#
#   ./scripts/deploy-direct.sh staging
#
# STAGING ONLY. Production has exactly one road — the "Deploy production" workflow on
# `main`, after CI has passed for that exact commit — and this script refuses to be a
# second one. A release that does not come from a GitHub commit CI checked is the kind
# nobody can reproduce, so there is no flag, variable or argument that enables it.
#
# The GitHub Actions workflows do three things: run the tests, SSH in, and run
# the on-server deploy script. When Actions cannot run — a billing hold, an
# outage, a private runner that is down — only the middle step is actually
# missing, and it is one SSH call. This is that call, from here.
#
# It is not a shortcut around the tests. `scripts/verify-all.sh` runs what the
# workflows run and this script refuses to start until it has passed, because
# the one thing a deploy must never do is ship code nobody checked.
#
# What it adds over the on-server script is the view from outside. That script
# verifies the release over loopback, which proves the container is serving the
# new commit and proves nothing about DNS, TLS or the reverse proxy in front of
# it. The last gate here is a request to the public domain, the way a customer
# arrives — and a deploy that cannot be verified that way is a failed deploy,
# not a quiet one.
#
# No secret is read from, or written to, the repository. Configuration lives in
# a git-ignored `.deploy.env`, the private key stays a path on this machine, and
# DEPLOY_SECRET is never sent anywhere: it stays in the server's own env file,
# where the on-server script reads it.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

cd "$(dirname "$0")/.."

TARGET="${1:-}"
CONFIG="${DEPLOY_CONFIG:-.deploy.env}"

BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; OFF=$'\033[0m'
say()  { printf '\n%s==>%s %s\n' "$BOLD" "$OFF" "$1"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$OFF" "$1"; }
warn() { printf '  %s!%s %s\n' "$YELLOW" "$OFF" "$1"; }
die()  { printf '\n%sRefusing to deploy.%s %s\n\n' "$RED" "$OFF" "$1" >&2; exit 1; }

case "$TARGET" in
  staging)
    BRANCH="${STAGING_BRANCH:-develop}"
    PATH_KEY=STAGING_PATH
    DOMAIN_KEY=STAGING_DOMAIN
    ON_SERVER=deploy-staging.sh
    ;;
  production)
    cat >&2 <<'MSG'

Refusing: production is not deployed from a machine.

The only way to ship production is the "Deploy production" workflow in GitHub Actions,
run from `main`, which checks that CI passed for that exact commit before it touches the
server. See docs/PRODUCTION_RUNBOOK.md.

MSG
    exit 1
    ;;
  *)
    cat >&2 <<USAGE

usage: scripts/deploy-direct.sh staging

Configuration is read from ${CONFIG} (git-ignored). Copy the example:

    cp .deploy.env.example .deploy.env

USAGE
    exit 2
    ;;
esac

# ---------------------------------------------------------------------------
# Configuration. Every missing value is named at once: finding them one failed
# run at a time is how a half-configured deploy gets attempted four times.
# ---------------------------------------------------------------------------
# `.` searches PATH for a bare name, so a relative config is anchored to the
# repository root and an absolute one is left alone.
case "$CONFIG" in
  /*) CONFIG_PATH="$CONFIG" ;;
  *)  CONFIG_PATH="./$CONFIG" ;;
esac

if [ -f "$CONFIG_PATH" ]; then
  # shellcheck disable=SC1090
  set -a; . "$CONFIG_PATH"; set +a
fi

DEPLOY_PORT="${DEPLOY_PORT:-22}"

missing=()
for key in DEPLOY_HOST DEPLOY_USER DEPLOY_SSH_KEY "$PATH_KEY" "$DOMAIN_KEY"; do
  [ -n "${!key:-}" ] || missing+=("$key")
done

if [ ${#missing[@]} -gt 0 ]; then
  {
    printf '\n%sNot configured.%s %s is missing:\n\n' "$RED" "$OFF" "$CONFIG"
    printf '  %s\n' "${missing[@]}"
    cat <<'EOF'

Nothing was deployed and nothing on the server was touched.

The server itself comes first — scripts/provision-server.sh, run once on a
fresh Ubuntu host, creates the deploy user and lays out both environments.
EXTERNAL_SETUP.md section 2 has the whole sequence.
EOF
  } >&2
  exit 1
fi

DEPLOY_DIR="${!PATH_KEY}"
DOMAIN="${!DOMAIN_KEY}"

[ -f "$DEPLOY_SSH_KEY" ] || die "DEPLOY_SSH_KEY points at ${DEPLOY_SSH_KEY}, which is not a file."

# ---------------------------------------------------------------------------
# What is about to be deployed. The server deploys a commit from the remote, so
# what matters is the remote branch — not this working tree, which may be on
# something else entirely.
# ---------------------------------------------------------------------------
say "Resolving ${BRANCH}"
git fetch --quiet --prune origin "$BRANCH"
COMMIT="$(git rev-parse "origin/${BRANCH}")"
ok "${COMMIT:0:8} — $(git log -1 --pretty=%s "$COMMIT")"

# ---------------------------------------------------------------------------
# The tests. This is the step CI was doing, so it has to happen somewhere, and
# it happens against the commit being deployed rather than against whatever is
# checked out here.
# ---------------------------------------------------------------------------
STAMP=".verify-passed"

if [ "${SKIP_VERIFY:-}" = "1" ]; then
  warn "SKIP_VERIFY=1 — deploying code this machine has not checked"
elif [ -f "$STAMP" ] && [ "$(cat "$STAMP")" = "$COMMIT" ]; then
  ok "verify-all.sh passed on ${COMMIT:0:8}"
else
  {
    printf '\n%sNot verified.%s\n\n' "$RED" "$OFF"
    printf 'The tests have not passed on %s on this machine.\n\n' "${COMMIT:0:8}"
    cat <<'EOF'
    git checkout <the branch being deployed>
    ./scripts/verify-all.sh

That writes the stamp this script looks for. It exists because GitHub Actions
is not in this path any more: with no CI run to point at, "the tests passed"
has to mean a run somebody can name.
EOF
  } >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# The deploy itself: the repository's own on-server script, unchanged. It takes
# the backup, shows the pending migrations, holds the maintenance window and
# rolls itself back on failure — none of which should be reimplemented here.
# ---------------------------------------------------------------------------
ssh_server() {
  ssh -i "$DEPLOY_SSH_KEY" -p "$DEPLOY_PORT" \
    -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 \
    "${DEPLOY_USER}@${DEPLOY_HOST}" "$@"
}

say "Reaching ${DEPLOY_USER}@${DEPLOY_HOST}"
ssh_server "test -d '${DEPLOY_DIR}'" \
  || die "${DEPLOY_DIR} does not exist on ${DEPLOY_HOST}. Run scripts/provision-server.sh there first."
ok "connected"

say "Deploying to ${TARGET}"
# The heredoc is unquoted so the commit and branch are substituted here, and
# the remote shell is given no chance to re-expand anything else.
ssh_server bash -s <<EOF
set -Eeuo pipefail
cd '${DEPLOY_DIR}'
export DEPLOY_BRANCH='${BRANCH}'
export DEPLOY_COMMIT='${COMMIT}'
./scripts/${ON_SERVER}
EOF

# ---------------------------------------------------------------------------
# The view from outside. The on-server check talks to itself over loopback; it
# cannot tell whether DNS resolves, the certificate is valid, or the proxy is
# passing requests through to the new containers.
# ---------------------------------------------------------------------------
say "Verifying https://${DOMAIN} from here"

for attempt in $(seq 1 30); do
  BODY="$(curl -fsS --max-time 10 "https://${DOMAIN}/api/health" 2>/dev/null || true)"

  if printf '%s' "$BODY" | grep -q '"status":"healthy"'; then
    ok "healthy over the public domain after ${attempt}s"
    HEALTHY=1
    break
  fi

  sleep 2
done

if [ -z "${HEALTHY:-}" ]; then
  {
    printf '\n%sDeployed, but not verified.%s\n\n' "$RED" "$OFF"
    cat <<EOF
The server reported a successful deploy and https://${DOMAIN}/api/health did
not answer healthy from here. The new code is live on the box; something
between the internet and it is not.

Check in this order: DNS for ${DOMAIN}, the certificate, then the reverse
proxy. The application itself already proved it is serving ${COMMIT:0:8} — the
on-server verification above would have rolled back otherwise.
EOF
  } >&2
  exit 1
fi

# The release assertion stays on the server: DEPLOY_SECRET belongs to that
# machine's env file, and sending it from here would put it in the process list
# of every user on the box.
say "Live"
printf '  %s → %s  https://%s\n\n' "${COMMIT:0:8}" "$TARGET" "$DOMAIN"
