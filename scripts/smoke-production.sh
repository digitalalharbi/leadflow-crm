#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Production smoke test, from the outside: what a customer's browser meets.
# PASS/FAIL per check; non-zero if any fails. Read-only — it creates nothing.
#
#   ./scripts/smoke-production.sh
# ---------------------------------------------------------------------------
set -uo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=lib/production-common.sh
. scripts/lib/production-common.sh

APP="${PRODUCTION_DOMAIN:-}"; API="${PRODUCTION_API_DOMAIN:-}"
head1 "Smoke test — https://${APP:-<unset>}"

if ! public_host "$APP" || ! public_host "$API"; then
  fail "domains" "PRODUCTION_DOMAIN / PRODUCTION_API_DOMAIN are not public hostnames"
  summary "SMOKE: FAIL"; exit 1
fi

get() { curl -sS --max-time 15 -o "$2" -w '%{http_code}' "$1" 2>/dev/null || echo 000; }
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT

code="$(get "https://${API}/api/health" "$T/health")"
if [ "$code" = 200 ] && grep -q '"status":"healthy"' "$T/health"; then pass "API health" "healthy over HTTPS"; else fail "API health" "HTTP $code"; fi
grep -q '"database":"ok"' "$T/health" && grep -q '"redis":"ok"' "$T/health" && pass "health: db + redis" "both ok" || fail "health: db + redis" "not both ok"

code="$(get "https://${APP}/" "$T/index")"
if [ "$code" = 200 ] && grep -qi '<div id="root"' "$T/index"; then pass "app shell" "served"; else fail "app shell" "HTTP $code or not the SPA"; fi

# Plain HTTP must lead to HTTPS, never serve content.
loc="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code} %{redirect_url}' "http://${APP}/" 2>/dev/null || echo '000 ')"
case "$loc" in 30[1278]\ https://*) pass "HTTP → HTTPS" "redirects" ;; *) fail "HTTP → HTTPS" "got: $loc" ;; esac

code="$(get "https://${API}/api/v1/leads" "$T/anon")"
[ "$code" = 401 ] && pass "auth enforced" "anonymous /leads → 401" || fail "auth enforced" "anonymous /leads → HTTP $code"

code="$(curl -sS --max-time 15 -o "$T/login" -w '%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{}' "https://${API}/api/v1/auth/login" 2>/dev/null || echo 000)"
[ "$code" = 422 ] || [ "$code" = 401 ] || [ "$code" = 429 ] && pass "login reachable" "validates input (HTTP $code)" || fail "login reachable" "HTTP $code"

code="$(get "https://${API}/api/v1/__no_such_route__" "$T/nf")"
if [ "$code" = 404 ] && ! grep -Eqi 'Stack trace|vendor/laravel|APP_KEY|Whoops' "$T/nf"; then pass "no debug output" "404 without a trace"; else fail "no debug output" "HTTP $code or a trace leaked"; fi

hdr="$(curl -sSI --max-time 10 "https://${APP}/" 2>/dev/null | tr 'A-Z' 'a-z')"
printf '%s' "$hdr" | grep -q 'strict-transport-security' && pass "HSTS" "present" || fail "HSTS" "Strict-Transport-Security header missing"

# The commit answering is the commit shipped. DEPLOY_SECRET stays on the
# server; the request is made from there.
if [ -n "${EXPECTED_COMMIT:-}" ] && [ -n "${DEPLOY_HOST:-}" ] && [ -f "${DEPLOY_SSH_KEY:-/nonexistent}" ]; then
  served="$(ssh_server "cd '${PRODUCTION_PATH}' && S=\$(sed -n 's/^DEPLOY_SECRET=//p' .env.production | tail -1 | tr -d \"'\\\"\"); curl -fsS -H \"X-Deploy-Secret: \$S\" http://127.0.0.1:9081/api/health" 2>/dev/null | sed -n 's/.*"release":"\([0-9a-f]\{40\}\)".*/\1/p')"
  [ "$served" = "$EXPECTED_COMMIT" ] && pass "release" "serving ${EXPECTED_COMMIT:0:8}" || fail "release" "expected ${EXPECTED_COMMIT:0:8}, serving ${served:-<none>}"
fi

if [ "$FAIL_COUNT" -eq 0 ]; then summary "SMOKE: PASS"; exit 0; fi
summary "SMOKE: FAIL"; exit 1
