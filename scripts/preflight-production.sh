#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Production preflight: PASS or FAIL for every item, and a non-zero exit if any
# one fails. Nothing here changes the server except starting its data services
# (postgres, redis), which is idempotent and is what the deploy does first.
#
#   ./scripts/preflight-production.sh
#
# A deploy never starts from a preflight that failed: release-production.sh
# refuses. There is no "skip" — a check that cannot run because something it
# depends on is missing FAILs, and says what it was waiting for.
# ---------------------------------------------------------------------------
set -uo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=lib/production-common.sh
. scripts/lib/production-common.sh

# The names the on-server environment must define, with a value, before the
# first request. DEPLOY_SECRET is what lets the deploy prove which build serves.
REQUIRED_ENV=(APP_KEY PRODUCTION_DOMAIN PRODUCTION_API_DOMAIN DB_USERNAME DB_PASSWORD DEPLOY_SECRET SUPER_ADMIN_EMAIL SUPER_ADMIN_PASSWORD)
# Repository secrets GitHub Actions needs; names only.
REQUIRED_GH_SECRETS=(VPS_HOST VPS_USER VPS_SSH_KEY PRODUCTION_PATH)

head1 "1. Server"
if [ -n "${DEPLOY_HOST:-}" ]; then pass "VPS_HOST" "(DEPLOY_HOST) is set"; HOST_OK=1; else fail "VPS_HOST" "DEPLOY_HOST is missing in ${CONFIG}"; HOST_OK=0; fi
[ -n "${DEPLOY_USER:-}" ] && pass "VPS_USER" "(DEPLOY_USER) is set" || fail "VPS_USER" "DEPLOY_USER is missing"
[ -n "${PRODUCTION_PATH:-}" ] && pass "PRODUCTION_PATH" "$PRODUCTION_PATH" || fail "PRODUCTION_PATH" "missing"
if [ -n "${DEPLOY_SSH_KEY:-}" ] && [ -f "$DEPLOY_SSH_KEY" ]; then pass "SSH key file" "present (contents never read)"; KEY_OK=1; else fail "SSH key file" "DEPLOY_SSH_KEY is unset or not a file"; KEY_OK=0; fi

SSH_OK=0
if [ "$HOST_OK" = 1 ] && [ "$KEY_OK" = 1 ] && [ -n "${DEPLOY_USER:-}" ]; then
  if ssh_server true 2>/dev/null; then pass "SSH reachable" "${DEPLOY_USER}@${DEPLOY_HOST}:${DEPLOY_PORT}"; SSH_OK=1; else fail "SSH reachable" "cannot log in to ${DEPLOY_HOST}"; fi
else
  fail "SSH reachable" "blocked: host, user and key are required first"
fi

head1 "2. Domain and HTTPS"
for pair in "PRODUCTION_DOMAIN:${PRODUCTION_DOMAIN:-}" "PRODUCTION_API_DOMAIN:${PRODUCTION_API_DOMAIN:-}"; do
  key="${pair%%:*}"; val="${pair#*:}"
  if [ -z "$val" ]; then fail "$key" "missing"
  elif public_host "$val"; then pass "$key" "$val is a public hostname"
  else fail "$key" "'$val' is not a public hostname (localhost, IPs, example.* and .test/.local are rejected)"; fi
done

resolve() { dig +short "$1" 2>/dev/null | grep -E '^[0-9.]+$' | sort -u || true; }
for d in "${PRODUCTION_DOMAIN:-}" "${PRODUCTION_API_DOMAIN:-}"; do
  [ -n "$d" ] && public_host "$d" || { fail "DNS ${d:-<unset>}" "blocked: not a valid public hostname"; continue; }
  ips="$(resolve "$d")"
  if [ -z "$ips" ]; then fail "DNS $d" "does not resolve"
  elif [ "$HOST_OK" = 1 ] && printf '%s\n' "$ips" | grep -qx "$(printf '%s' "$DEPLOY_HOST")"; then pass "DNS $d" "→ $DEPLOY_HOST"
  elif [ "$HOST_OK" = 1 ] && [ "$(resolve "$DEPLOY_HOST")" != "" ] && [ "$(resolve "$DEPLOY_HOST")" = "$ips" ]; then pass "DNS $d" "→ same address as $DEPLOY_HOST"
  else fail "DNS $d" "resolves to $(echo "$ips" | tr '\n' ' ')not ${DEPLOY_HOST:-<VPS_HOST unset>}"; fi
done

for d in "${PRODUCTION_DOMAIN:-}" "${PRODUCTION_API_DOMAIN:-}"; do
  [ -n "$d" ] && public_host "$d" || { fail "HTTPS ${d:-<unset>}" "blocked: not a valid public hostname"; continue; }
  # No -k: an expired, self-signed or mismatched certificate must fail.
  code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "https://${d}/" 2>/dev/null || true)"
  if [ -n "$code" ] && [ "$code" != "000" ]; then pass "HTTPS $d" "valid certificate, HTTP $code"; else fail "HTTPS $d" "no valid TLS answer (certbot not run, or port 443 closed)"; fi
done

head1 "3. Secrets (names only — values are never printed)"
if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
  names="$(GH_PROMPT_DISABLED=1 gh secret list --json name --jq '.[].name' 2>/dev/null || true)"
  if [ -z "$names" ]; then
    fail "GitHub secrets" "none visible (empty, or the API was unreachable)"
  else
    for s in "${REQUIRED_GH_SECRETS[@]}"; do
      printf '%s\n' "$names" | grep -qx "$s" && pass "GitHub secret $s" "exists" || fail "GitHub secret $s" "missing"
    done
  fi
  vars="$(GH_PROMPT_DISABLED=1 gh variable list --json name --jq '.[].name' 2>/dev/null || true)"
  printf '%s\n' "$vars" | grep -qx PRODUCTION_DOMAIN && pass "GitHub variable PRODUCTION_DOMAIN" "exists" || fail "GitHub variable PRODUCTION_DOMAIN" "missing"
else
  fail "GitHub secrets" "gh is not installed or not logged in, so they cannot be checked"
fi

if [ "$SSH_OK" = 1 ]; then
  if ssh_server "test -f '${PRODUCTION_PATH}/.env.production'" 2>/dev/null; then
    pass ".env.production" "exists on the server"
    for k in "${REQUIRED_ENV[@]}"; do
      case "$(remote_env_state "$k")" in set) pass "env $k" "has a value" ;; empty) fail "env $k" "is empty" ;; *) fail "env $k" "unreadable" ;; esac
    done
    if ssh_server "grep -Eq '(example\.(com|org|net))|^APP_SCHEME=http$' '${PRODUCTION_PATH}/.env.production'" 2>/dev/null; then
      fail "env placeholders" "contains example.* or APP_SCHEME=http — production is HTTPS only"
    else pass "env placeholders" "none"; fi
  else
    fail ".env.production" "missing in ${PRODUCTION_PATH}"
  fi
else
  fail ".env.production" "blocked: SSH is not reachable"
fi

head1 "4. Database, Redis and host"
if [ "$SSH_OK" = 1 ] && ssh_server "test -f '${PRODUCTION_PATH}/.env.production'" 2>/dev/null; then
  ssh_server "command -v docker >/dev/null && docker compose version >/dev/null 2>&1" && pass "Docker + Compose" "installed" || fail "Docker + Compose" "not installed"
  remote_compose "up -d postgres redis" >/dev/null 2>&1 || true
  sleep 3
  remote_compose "exec -T postgres sh -c 'pg_isready -U \"\$POSTGRES_USER\"'" >/dev/null 2>&1 && pass "Database reachable" "pg_isready" || fail "Database reachable" "postgres did not answer"
  [ "$(remote_compose "exec -T redis redis-cli ping" 2>/dev/null | tr -d '\r')" = "PONG" ] && pass "Redis reachable" "PONG" || fail "Redis reachable" "redis did not answer"
  free_gb="$(ssh_server "df -BG --output=avail '${PRODUCTION_PATH}' | tail -1 | tr -dc 0-9" 2>/dev/null || echo 0)"
  [ "${free_gb:-0}" -ge 5 ] && pass "Disk space" "${free_gb} GB free" || fail "Disk space" "${free_gb:-0} GB free, need 5"
else
  for n in "Docker + Compose" "Database reachable" "Redis reachable" "Disk space"; do fail "$n" "blocked: server or .env.production unavailable"; done
fi

head1 "5. Backups"
if [ "$SSH_OK" = 1 ]; then
  ssh_server "test -x '${PRODUCTION_PATH}/scripts/backup-db.sh'" 2>/dev/null && pass "backup script" "executable" || fail "backup script" "missing or not executable"
  ssh_server "grep -qs backup-db.sh /etc/cron.d/* || crontab -l 2>/dev/null | grep -q backup-db.sh" && pass "backup schedule" "nightly cron job present" || fail "backup schedule" "no nightly job (scripts/provision-server.sh writes /etc/cron.d/leadflow-backup)"
  case "$(remote_env_state BACKUP_UPLOAD_COMMAND)" in set) pass "off-server copy" "BACKUP_UPLOAD_COMMAND set" ;; *) fail "off-server copy" "BACKUP_UPLOAD_COMMAND is not set — a backup on the same disk does not survive the disk" ;; esac
else
  for n in "backup script" "backup schedule" "off-server copy"; do fail "$n" "blocked: SSH is not reachable"; done
fi

head1 "6. Release"
if [ -n "$(git status --porcelain 2>/dev/null)" ]; then fail "working tree" "uncommitted changes"; else pass "working tree" "clean"; fi
git fetch --quiet origin "$PRODUCTION_BRANCH" 2>/dev/null || true
if [ "$(git rev-parse HEAD 2>/dev/null)" = "$(git rev-parse "origin/${PRODUCTION_BRANCH}" 2>/dev/null)" ]; then pass "commit pushed" "HEAD = origin/${PRODUCTION_BRANCH}"; else fail "commit pushed" "HEAD is not origin/${PRODUCTION_BRANCH}"; fi
if [ -f .verify-passed ] && [ "$(cat .verify-passed)" = "$(git rev-parse "origin/${PRODUCTION_BRANCH}" 2>/dev/null)" ]; then pass "tests on this commit" "scripts/verify-all.sh passed"; else fail "tests on this commit" "run ./scripts/verify-all.sh first"; fi

head1 "Not blocking, but Production Verified will say so"
if [ "$SSH_OK" = 1 ]; then
  { [ "$(remote_env_state SENTRY_LARAVEL_DSN)" = set ] || [ "$(remote_env_state LOG_SLACK_WEBHOOK_URL)" = set ]; } && pass "error alerts" "Sentry DSN or Slack webhook set" || warn "error alerts" "neither SENTRY_LARAVEL_DSN nor LOG_SLACK_WEBHOOK_URL is set"
fi

if [ "$FAIL_COUNT" -eq 0 ]; then summary "PREFLIGHT: PASS"; exit 0; fi
summary "PREFLIGHT: FAIL — deploy is blocked"
exit 1
