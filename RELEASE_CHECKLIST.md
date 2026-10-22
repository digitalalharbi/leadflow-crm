# RELEASE CHECKLIST — «مهتم» release candidate

Status words: **READY** (works as shipped, nothing to supply) · **EXTERNAL CONFIG REQUIRED** (a value only you can
supply; the feature stays honest-off until it is there) · **OPTIONAL** (nice to have; nothing depends on it).

The product is tested Local-only. Nothing below has been exercised against a real server; the gates
are the repository's own suites (see "Gates" at the end). `./scripts/check-deploy-readiness.sh` reports
which of these exist on a machine without printing any value.

## At a glance

| | Items |
| --- | --- |
| **READY** | the application and its tenant isolation, roles and per-element access, audit log, encrypted credentials, signed webhooks and rate limits · the sales-first phone flow · the installable app (manifest, service worker, offline notice) · the brand kit · the public pages (§P) · email as a recorded log (§4) · queue, scheduler and backups once their processes run (§3) |
| **EXTERNAL CONFIG REQUIRED** | server, domain and HTTPS (§2) · a mail provider to really send (§4) · WhatsApp Business credentials (§5) · Google Sheets OAuth (§6) · each ad platform's app credentials (§7) · the four public-page values (§P): legal name, contact email, postal address, named processors |
| **OPTIONAL** | AI key (§8) · Sentry and log shipping (§9) · Twilio calls (§10) · inbound-email parse domain (§4) |

## 1. Application (READY)

| Variable | Note |
| --- | --- |
| `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL`, `APP_TIMEZONE` | `php artisan key:generate` once; `APP_URL` is the API origin |
| `APP_CENTRAL_DOMAIN`, `FRONTEND_URL`, `SESSION_DOMAIN`, `TRUSTED_PROXIES` | the web origin; cookies are scoped to it |
| `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL 15+; migrations are forward-only (`php artisan migrate --force`) |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` works with nothing else installed; `redis` needs `REDIS_*` |
| `SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_NAME`, `SUPER_ADMIN_PASSWORD` | the first sign-in; rotate after |
| `API_RATE_LIMIT_PER_MINUTE`, `LOGIN_RATE_LIMIT_PER_MINUTE`, `LOGIN_RATE_LIMIT_PER_IP_PER_MINUTE`, `WEBHOOK_MAX_BODY_BYTES`, `WEBHOOK_RATE_LIMIT_PER_MINUTE` | defaults are sane; set only to change them |
| `LOG_CHANNEL`, `LOG_LEVEL` | `daily` + `warning` in production |

## 2. Server, domain, HTTPS (EXTERNAL CONFIG REQUIRED — yours tomorrow)

- VPS with PHP 8.4 (`pdo_pgsql`, `gd`, `intl`, `bcmath`, `mbstring`), Node 20 for the frontend build, PostgreSQL, nginx.
- Two origins behind HTTPS: the web app (`FRONTEND_URL`) and the API (`APP_URL`). CORS/CSRF are driven by these two values; the session cookie is `Secure` + `SameSite` from `SESSION_*`.
- `.deploy.env` (git-ignored) + `./scripts/deploy-direct.sh` exist for a direct SSH deploy; production is never deployed from a laptop by that script (staging only) — the on-server script is `scripts/` → read `EXTERNAL_SETUP.md §0`.
- No CI/CD is wired (GitHub Actions disabled); the gates are run locally before tagging.

## 3. Queue, scheduler, backups (READY once the processes run)

- **Queue worker**: `php artisan queue:work --tries=1 --timeout=120` as a supervised service (Horizon if Redis). Email deliveries, notification jobs, proposal execution and the automation journey all ride on it; with no worker everything stays `queued` and the delivery screen says so.
- **Scheduler**: `* * * * * php artisan schedule:run` (cron). Hourly: follow-up reminders, daily digest, interest refresh, email digests, anomaly detection, tracking watch, reservation expiry; 03:30 daily payload pruning (`RETENTION_PAYLOAD_DAYS`).
- **Backups**: `./scripts/backup-db.sh <env> <label>` before every migration; keep a copy off-box. The RC itself is bundled at `~/Developer/leadflow-backups/leadflow-crm-rc-2026-10-22.bundle` on the build machine.

## 4. Email — the one door (EXTERNAL CONFIG REQUIRED to really send; READY as log)

Every product email (report shares, daily sales / weekly marketing / weekly executive digests,
instant alerts, a lead's email, the mail-server test) leaves through `App\Domain\Mail\MailerProvider`.
Default `LEADFLOW_MAIL_PROVIDER=log`: everything is recorded as **logged**, never "sent".

| Provider | Variables | Status |
| --- | --- | --- |
| `log` | — | READY (writes to the log, sends nothing) |
| `smtp` | `LEADFLOW_MAIL_PROVIDER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME` | EXTERNAL CONFIG REQUIRED (credentials) |
| `ses` | `LEADFLOW_MAIL_PROVIDER=ses`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `composer require aws/aws-sdk-php` | EXTERNAL CONFIG REQUIRED (keys + SDK) |
| `resend` | `LEADFLOW_MAIL_PROVIDER=resend`, `RESEND_API_KEY` | EXTERNAL CONFIG REQUIRED (key) |
| sender | `LEADFLOW_MAIL_FROM_ADDRESS`, `LEADFLOW_MAIL_FROM_NAME` (fall back to `MAIL_FROM_*`) | set with the provider |
| replies to leads | `EMAIL_INBOUND_DOMAIN`, `EMAIL_INBOUND_SECRET` + an inbound-parse webhook (Postmark/SendGrid/Mailgun) | OPTIONAL — without it customer replies reach the workspace mailbox, not the thread |

A workspace may also connect its own SMTP in Settings → Integrations → Email; that door is per-workspace and needs no server variable. None of the four adapters has sent a real message yet.

## 5. WhatsApp Business (EXTERNAL CONFIG REQUIRED per workspace)

- Server: `WHATSAPP_GRAPH_URL`, `WHATSAPP_GRAPH_VERSION` (defaults fine), `WHATSAPP_RATE_LIMIT_*`, `WHATSAPP_MESSAGES_PER_LEAD`.
- Per workspace (Settings → Integrations → WhatsApp): phone number id, access token, app secret, verify token; the webhook URL is shown on that screen. Without a token the mock provider runs and the screen says "configuration required".

## 6. Google Sheets (EXTERNAL CONFIG REQUIRED)

`GOOGLE_SHEETS_CLIENT_ID`, `GOOGLE_SHEETS_CLIENT_SECRET`, `GOOGLE_PICKER_API_KEY`, `GOOGLE_PROJECT_NUMBER` — an OAuth client in Google Cloud with the redirect URL the Integrations screen prints. Sheet imports stay off until then.

## 7. Ad platforms (EXTERNAL CONFIG REQUIRED each; the product is honest without them)

| Platform | Variables |
| --- | --- |
| Meta (lead ads + marketing API) | `META_APP_ID`, `META_APP_SECRET`, `META_VERIFY_TOKEN` |
| TikTok | `TIKTOK_APP_ID`, `TIKTOK_APP_SECRET` |
| Snapchat | `SNAPCHAT_CLIENT_ID`, `SNAPCHAT_CLIENT_SECRET` |
| Google Ads | `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, `GOOGLE_ADS_DEVELOPER_TOKEN` |
| LinkedIn | `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET` |
| X | OAuth 1.0a is per-workspace in the Integrations screen |

`ADS_SANDBOX` must stay unset/false in production (it is ignored there anyway): campaign changes are
proposed, approved and executed only against a connected account.

## 8. AI (OPTIONAL)

`ANTHROPIC_API_KEY` (+ `ANTHROPIC_BASE_URL`, `ANTHROPIC_TIMEOUT`, `AGENT_MODEL_*`) or a per-workspace key
in Settings. Without it the agents and summaries say so and use their deterministic fallbacks.

## 9. Observability (OPTIONAL)

Sentry is not wired on the backend (no `SENTRY_*` read anywhere); the frontend SDK is installed but
needs a DSN before it reports anything. `LOG_SLACK_WEBHOOK_URL` / Papertrail variables are available
for log shipping.

## 10. Calls (OPTIONAL)

Twilio Voice is per-workspace (Settings → Calls); no server variable. The status webhook is public and signed.

## P. Public pages: privacy, terms, data deletion, security, contact, about (READY; four values EXTERNAL CONFIG REQUIRED)

The pages ship as drafts and invent nothing. Until a value is set they show a visible marker,
"[يُضاف قبل الإطلاق: …]", in its place; none of them is hidden. They are **build-time** values (compiled into the
bundle, public by nature), passed through `docker-compose.*.yml` → `docker/Dockerfile.frontend`, or `frontend/.env`
for a plain `npm run build`.

| Variable | What it is | State |
| --- | --- | --- |
| `VITE_PUBLIC_LEGAL_NAME` | the legal entity that provides the service | EXTERNAL CONFIG REQUIRED — not set |
| `VITE_PUBLIC_CONTACT_EMAIL` | one mailbox for support, privacy and deletion requests | EXTERNAL CONFIG REQUIRED — not set |
| `VITE_PUBLIC_ADDRESS` | the postal address | EXTERNAL CONFIG REQUIRED — not set |
| `VITE_PUBLIC_PROCESSORS` | the named outside services that process data (hosting, mail, WhatsApp, …) | EXTERNAL CONFIG REQUIRED — not set |

- **Data deletion** is instructions only (write to the mailbox above): there is no self-service deletion endpoint, and the page says so.
- **Password reset** is by the workspace administrator; there is no forgot-password flow, and the sign-in screen links to the contact page instead.
- The texts are drafts: have them read by counsel before launch. The security page states no certification (no SOC 2, ISO 27001, PCI or GDPR claim).
- The public pages are indexable; the sign-in and the app stay `noindex`.

## Installable app and browsers (READY; HTTPS is yours tomorrow)

- The app installs to a phone or desktop (name «مهتم», standalone, green theme). A service worker, the install prompt and the offline notice need **HTTPS on the web origin** — they do not run on plain HTTP except on localhost.
- `docker/nginx/spa.conf` serves `sw.js`, `site.webmanifest` and `offline.html` with `no-cache`; if you use another web server, give those three files the same rule. A cached `sw.js` means an installed app never hears of an update.
- Nothing from `/api/` is ever stored by the worker. With no connection the app shows a notice, pages fall back to an offline page, and every change (save, send, approve) is refused out loud — nothing is queued.
- Tested in Chromium (Chrome and Edge share the engine), Firefox, WebKit (Safari engine), an iPhone 14 profile and a Pixel 7 profile, at 375, 390, 430, 768 and 1366×768. **Not** tested on a real iPhone, a real Android phone, or Microsoft Edge itself; add-to-home-screen on iOS is a manual step the app explains on screen.

## Gates run on this RC (2026-10-22, `develop`)

- Backend: full `php artisan test` — see the final report for the exact count.
- Pint, `npm run typecheck`, `npm run lint`, `vitest run` — green.
- Playwright: full suite, single worker, isolated database; any failure rerun ×3 and classified.
