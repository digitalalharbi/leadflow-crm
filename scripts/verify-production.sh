#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# The only thing that may call a deployment "Production Verified".
#
# All eight must hold, each observed now, none inferred:
#   1 public HTTPS URL        5 Redis reachable
#   2 queue worker running    6 backup verified (fresh, off-server)
#   3 scheduler running       7 readiness healthy
#   4 database reachable      8 smoke test passed
#
#   ./scripts/verify-production.sh
# Prints PRODUCTION VERIFIED and exits 0, or names what is missing and exits 1.
# ---------------------------------------------------------------------------
set -uo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=lib/production-common.sh
. scripts/lib/production-common.sh

head1 "Production verification"

for v in DEPLOY_HOST DEPLOY_USER PRODUCTION_PATH PRODUCTION_DOMAIN PRODUCTION_API_DOMAIN; do
  [ -n "${!v:-}" ] || { fail "$v" "missing in ${CONFIG}"; }
done
[ -f "${DEPLOY_SSH_KEY:-/nonexistent}" ] || fail "DEPLOY_SSH_KEY" "not a file"
if [ "$FAIL_COUNT" -gt 0 ]; then summary "NOT PRODUCTION VERIFIED"; exit 1; fi

# 1 — public HTTPS URL
if public_host "$PRODUCTION_DOMAIN" && curl -sS -o /dev/null --max-time 10 "https://${PRODUCTION_DOMAIN}/" 2>/dev/null; then pass "1 public HTTPS URL" "https://${PRODUCTION_DOMAIN}"; else fail "1 public HTTPS URL" "not answering with a valid certificate"; fi

# 2–7 — what the application itself reports, read on the server.
JSON="$(remote_compose "exec -T backend php artisan leadflow:readiness --json" 2>/dev/null || true)"
if [ -z "$JSON" ]; then
  for n in "2 queue worker" "3 scheduler" "4 database" "5 Redis" "6 backup" "7 readiness"; do fail "$n" "could not read leadflow:readiness"; done
else
  row() { printf '%s' "$JSON" | python3 -I -c "
import json,sys
d=json.load(sys.stdin)
r=[x for x in d.get('infrastructure',[]) if x['key']=='$1']
print(r[0]['state'] if r else 'missing')" 2>/dev/null || echo unreadable; }
  kind="$(printf '%s' "$JSON" | python3 -I -c "import json,sys;print(json.load(sys.stdin).get('deployment',{}).get('kind',''))" 2>/dev/null)"

  [ "$(row horizon)" = live_verified ] && pass "2 queue worker" "Horizon running" || fail "2 queue worker" "Horizon is $(row horizon)"
  [ "$(row queues)" = live_verified ] && pass "3 scheduler" "heartbeat fresh (the scheduler writes it every minute)" || fail "3 scheduler" "no fresh heartbeat: queues is $(row queues)"
  [ "$(row database)" = live_verified ] && pass "4 database" "reachable, no pending migrations" || fail "4 database" "database is $(row database)"
  [ "$(row redis)" = live_verified ] && pass "5 Redis" "reachable" || fail "5 Redis" "redis is $(row redis)"
  [ "$(row backups)" = live_verified ] && pass "6 backup" "fresh and copied off the server" || fail "6 backup" "backups is $(row backups)"
  [ "$kind" = production ] && pass "7 readiness" "the application reports itself as Production" || fail "7 readiness" "the application reports deployment '${kind:-unknown}', not production"
fi

# 8 — the smoke test, as its own run so its table prints.
if "$(dirname "$0")/smoke-production.sh"; then pass "8 smoke test" "passed"; else fail "8 smoke test" "failed"; fi

if [ "$FAIL_COUNT" -eq 0 ]; then
  summary "PRODUCTION VERIFIED"
  printf '  %s at %s\n\n' "$(git rev-parse --short HEAD)" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  exit 0
fi
summary "NOT PRODUCTION VERIFIED"
exit 1
