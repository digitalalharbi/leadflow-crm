# Incident runbook

## First five minutes
1. `curl -s https://mohtam.pro/api/health` — `application`, `database`, `redis`, `queue`, `storage` must be `ok`
   (`realtime` is reported, not gating). 503 = degraded.
2. Is it everyone or one workspace? One workspace: check its subscription state and integrations (Readiness).
3. Roll back only from a release tag: `scripts/rollback.sh` (as `leadflow-deploy`, never root).

## Known issue: intermittent hangs (seen 2026-10-31)
`/sw.js` timed out in 3 of 4 requests and ~1 in 5 `/api/health` requests hung for 15–20 s, while `/`, `/login` and icons
answered in ~0.4 s. Both are served by the same nginx, so the likely causes are on the server: PHP-FPM workers exhausted
(`pm.max_children`), an upstream keep-alive mismatch, or the `/sw.js` location falling through to PHP. Check, with SSH:
- `sudo tail -n 200 /var/log/nginx/error.log` — `upstream timed out` / `no live upstreams`.
- `systemctl status php8.4-fpm` and the FPM log for `server reached pm.max_children`.
- `curl -sI http://127.0.0.1/sw.js` on the server — it must be a static file with `Cache-Control: no-cache`.
The app side is already defensive: the service worker never touches `/api`, never blocks navigation (network first),
and installs even if one precached file fails.

## Queue stuck
`php artisan queue:failed` (counts only; payloads may hold PII), restart workers (`supervisorctl restart all`),
`php artisan queue:retry all` once the cause is fixed. Outbound webhooks pause themselves after 15 failures — resume
from Integrations → Webhooks.

## A provider is down (Meta, mail, Google)
Leads keep arriving through every other source and are stored; outbound sends retry with backoff; nothing is lost.
Tell affected workspaces only if it lasts more than an hour.

## Security event
Failed logins, MFA failures, key and webhook changes are in the audit trail. Revoke: sessions (Profile → Security, or
platform console), API keys (Integrations → API keys), webhook secrets (rotate). Rotate `APP_KEY` only with the
re-encryption procedure in `docs/SECURITY.md`.
