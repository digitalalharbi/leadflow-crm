# shellcheck shell=bash
# Shared by preflight-production.sh, smoke-production.sh and verify-production.sh.
# Sourced, never executed.
#
# Configuration is read from a git-ignored .deploy.env when one exists (an operator's
# machine), and from the environment otherwise (the GitHub Actions runner). These
# scripts check a release; none of them ships one.

BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; OFF=$'\033[0m'

CONFIG="${DEPLOY_CONFIG:-.deploy.env}"
case "$CONFIG" in /*) CONFIG_PATH="$CONFIG" ;; *) CONFIG_PATH="./$CONFIG" ;; esac

if [ -f "$CONFIG_PATH" ]; then
  # shellcheck disable=SC1090
  set -a; . "$CONFIG_PATH"; set +a
fi

DEPLOY_PORT="${DEPLOY_PORT:-22}"
# Production is released from `main` and nothing else.
PRODUCTION_BRANCH="${PRODUCTION_BRANCH:-main}"
# The key is a path; "~" is not expanded inside a sourced assignment.
DEPLOY_SSH_KEY="${DEPLOY_SSH_KEY:-}"
DEPLOY_SSH_KEY="${DEPLOY_SSH_KEY/#\~/$HOME}"

# The API is its own name in production (see docker-compose.production.yml). It
# defaults to the app domain only when the operator runs a single-host layout.
PRODUCTION_API_DOMAIN="${PRODUCTION_API_DOMAIN:-}"

PASS_COUNT=0; FAIL_COUNT=0; WARN_COUNT=0

pass() { PASS_COUNT=$((PASS_COUNT+1)); printf '  %sPASS%s  %-26s %s\n' "$GREEN" "$OFF" "$1" "${2:-}"; }
fail() { FAIL_COUNT=$((FAIL_COUNT+1)); printf '  %sFAIL%s  %-26s %s\n' "$RED" "$OFF" "$1" "${2:-}"; }
warn() { WARN_COUNT=$((WARN_COUNT+1)); printf '  %sWARN%s  %-26s %s\n' "$YELLOW" "$OFF" "$1" "${2:-}"; }
head1() { printf '\n%s%s%s\n' "$BOLD" "$1" "$OFF"; }

# Same rule as App\Support\Deployment::publicHost — a name a customer could
# open. A laptop, a bare IP and the documentation domains are not one.
public_host() {
  local h; h="$(printf '%s' "$1" | tr 'A-Z' 'a-z')"
  [ -n "$h" ] || return 1
  case "$h" in
    localhost|*.localhost|*.local|*.test|*.invalid|*.internal|*.example|example.com|*.example.com|example.org|*.example.org|example.net|*.example.net) return 1 ;;
  esac
  printf '%s' "$h" | grep -Eq '^[0-9.]+$|:' && return 1
  printf '%s' "$h" | grep -Eq '^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$'
}

ssh_server() {
  ssh -i "$DEPLOY_SSH_KEY" -p "$DEPLOY_PORT" \
    -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 \
    "${DEPLOY_USER}@${DEPLOY_HOST}" "$@"
}

# Runs `docker compose` for production on the server, from the project folder.
remote_compose() {
  ssh_server "cd '${PRODUCTION_PATH}' && docker compose -f docker-compose.production.yml --env-file .env.production $*"
}

# Reads one KEY from the server's .env.production without ever printing it.
# Prints "set" or "empty" — the value stays on the server.
remote_env_state() {
  ssh_server "cd '${PRODUCTION_PATH}' && v=\$(sed -n 's/^$1=//p' .env.production | tail -1 | tr -d \"'\\\"\"); [ -n \"\$v\" ] && echo set || echo empty" 2>/dev/null || echo unreadable
}

summary() {
  printf '\n%s%s%s  %d passed, %d failed, %d warning(s)\n\n' "$BOLD" "$1" "$OFF" "$PASS_COUNT" "$FAIL_COUNT" "$WARN_COUNT"
}
