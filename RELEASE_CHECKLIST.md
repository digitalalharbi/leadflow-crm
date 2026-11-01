# RELEASE CHECKLIST — «مهتم» v0.6.2

**v0.5.1 supersedes v0.5.0.** v0.5.0 was tagged before a cross-workspace leak was found and fixed (#204: the lists of
managers and salespeople, WhatsApp approval and SLA escalation recipients, agent notes, user search and onboarding read users
across workspaces). v0.5.0 was never deployed; do not deploy it. **Product freeze is ON** from v0.5.1: only fixes from here until launch.

Status words: **READY** (works as shipped, nothing to supply) · **EXTERNAL CONFIG REQUIRED** (a value only you can
supply; the feature stays honest-off until it is there) · **OPTIONAL AFTER LAUNCH** (nice to have; nothing depends on it).

The product is tested Local-only. Nothing below has been exercised against a real server; the gates
are the repository's own suites (see "Gates" at the end). `./scripts/check-deploy-readiness.sh` reports
which of these exist on a machine without printing any value.

## v0.6.2 — guarded production delivery (2026-10-31)

This patch carries the v0.6.1 public site unchanged and makes the audited shared
VPS topology deployable without granting GitHub Actions an unrestricted shell.
Production uses the dedicated `leadflow-deploy` identity, a forced-command
dispatcher, a root-owned deployment gate, `/opt/leadflow-crm`, strict known-host
verification, the existing Dockerized Caddy `web_proxy` network, bounded
container resources, and explicit free-disk and swap gates. The public site and
API use the single `https://mohtam.pro` origin.

Before activation: install the restricted identity with
`scripts/vps/install-leadflow-deploy.sh`, keep `PRODUCTION_ENABLED=false`, pass
the read-only VPS audit, verify an off-server backup and restore, then enable
production. Every subsequent merge to `main` deploys automatically after CI. The release commit must be the commit
reported by the authenticated internal health endpoint.

## v0.6.1 — the public face of مهتم (2026-10-29)

**Why a patch, not a minor:** nothing changes for a workspace's data or its API. v0.6.1 puts the marketing homepage at
`https://mohtam.pro/`, the sign-in at `/login` and the app at `/dashboard`, and ships the official brand identity:
titles «مهتم — حوّل الاهتمام إلى فرصة» (em dash, no `|`), favicon and PWA icons served from the brand folder, the social
card, structured data, and branded emails and error pages. It also fixes the shared-report render loop caused by the
title suffix and keeps the phone bottom bar's home tab stable now that home is `/dashboard`.

Contents: #214. No migrations. Deploy the `v0.6.1` tag with `deploy-production.sh`; nothing else to run.

Gate: Full Playwright on `01cd023` (the code of this tag) — 846 passed, 0 failed, 64 skipped; backend, frontend,
lint, typecheck and build green.

## v0.6.0 — مهتم as a commercial multi-company SaaS (2026-10-24)

**Why a minor version, not a patch:** v0.6.0 turns the single-workspace CRM into a product sold to independent companies
(plans, subscriptions, seats, entitlements, a separate platform console, invitations, multi-workspace people). It also ships
the Tracking Hub and the advanced form builder (#208), decided for this release on 2026-10-24. Both run on the current
domains — tracking links stay `https://api.mohtam.pro/t/<token>`, landing pages `https://mohtam.pro/l/<token>`.
`go.mohtam.pro` stays DNS-only; it is not served and nothing generates links on it.

Contents: #208 (Tracking Hub, form builder V2), #210 (SaaS), and main's read-only VPS audit workflow (`64f1bd7`, CI only,
`workflow_dispatch`, never ran — GitHub billing blocked its only run).

### First run on a new server (in addition to the deploy order below)

1. Deploy the `v0.6.0` tag with `deploy-production.sh` (migrations are additive; they move existing workspaces onto the
   Enterprise plan so nothing they use disappears, and backfill proven emails for existing people).
2. Create the platform owner on the server: `php artisan leadflow:create-super-admin --email=…` (prompts for the password).
3. Sign in at `https://mohtam.pro/platform/login` and enrol the authenticator app — `PLATFORM_REQUIRE_MFA=true` keeps the
   console closed until you do. Store the recovery codes off the server.
4. Console → Plans: set the prices (they start empty; revenue reads "not configured" until then), seats and limits.
5. Console → Companies → New company: the owner receives a 7-day setup link by email — this needs the mail provider (§4).
   Until it is configured, the email is recorded as *logged* and not delivered.

## At a glance

| | Items |
| --- | --- |
| **READY** | the application and its tenant isolation, roles and per-element access, audit log, encrypted credentials, signed webhooks and rate limits · the sales-first phone flow · the installable app (manifest, service worker, offline notice) · the brand kit · the public pages (§P) · email as a recorded log (§4) · queue, scheduler and backups once their processes run (§3) |
| **EXTERNAL CONFIG REQUIRED** | server, domain and HTTPS (§2) · a mail provider to really send (§4) · WhatsApp Business credentials (§5) · Google Sheets OAuth (§6) · each ad platform's app credentials (§7) · the four public-page values (§P): legal name, contact email, postal address, named processors |
| **OPTIONAL AFTER LAUNCH** | AI key (§8) · Sentry and log shipping (§9) · Twilio calls (§10) · inbound-email parse domain (§4) |

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
| replies to leads | `EMAIL_INBOUND_DOMAIN`, `EMAIL_INBOUND_SECRET` + an inbound-parse webhook (Postmark/SendGrid/Mailgun) | OPTIONAL AFTER LAUNCH — without it customer replies reach the workspace mailbox, not the thread |

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

## 8. AI (OPTIONAL AFTER LAUNCH)

`ANTHROPIC_API_KEY` (+ `ANTHROPIC_BASE_URL`, `ANTHROPIC_TIMEOUT`, `AGENT_MODEL_*`) or a per-workspace key
in Settings. Without it the agents and summaries say so and use their deterministic fallbacks.

## 9. Observability (OPTIONAL AFTER LAUNCH)

Sentry is not wired on the backend (no `SENTRY_*` read anywhere); the frontend SDK is installed but
needs a DSN before it reports anything. `LOG_SLACK_WEBHOOK_URL` / Papertrail variables are available
for log shipping.

## 10. Calls (OPTIONAL AFTER LAUNCH)

Twilio Voice is per-workspace (Settings → Calls); no server variable. The status webhook is public and signed.

## 11. Automation first — alerts, summaries, the journeys (READY; three things only you can switch on)

Audited end to end on 2026-10-23: a lead arrives by webhook and the conversation starts without anybody (normalise → de-duplicate →
attribute → project → assign → WhatsApp + email + brochure + the project's questions → the owner told → the SLA clock), and the
follow-up plan lays out the week (now · 15 min · 4 h · 24 h · 3 d · 7 d). When a customer turns **interested** the general nurture
stops and the interested journey takes its place (owner reminded first, a different message to the customer each time, a visit
offered, a last word); it ends on a visit, a held unit, won, lost, an unsubscribe, or a person taking the conversation.

- **Instant alerts** (new lead · hot · interested · visit asked · reservation · high value · SLA · automation failed · tracking): each names the
  project and the source, opens that lead, and goes only to its owner (or this workspace's managers). Per person: instant, daily, weekly or off.
- **Summaries**: the daily sales one at 08:00, the weekly marketing and executive ones on Sunday, the monthly one on the 1st — at 08:00 *where the
  workspace is*, once each; every figure and list item opens the screen or lead it counts. An alert chosen "instant" is not repeated in a summary.
- **Guards** on every automatic message: quiet hours, daily cap, cooldown, the same message never twice in a day, a person who has the lead, an
  unsubscribe, an unreachable contact, a closed lead, a visit or a held unit.
- **Email** leaves only through `MailerProvider` (a test fails the build if anything reaches for Laravel's mailer).
- **Durable**: a scheduled step retries three times with backoff, a lock stops two workers running it, a step whose timer was lost is found by
  `leadflow:sweep-scheduled` every ten minutes, and a step that dies is recorded as failed and the managers are told.

| Item | State |
| --- | --- |
| Queue worker and scheduler running (§3) | EXTERNAL CONFIG REQUIRED — without them nothing waits, retries or digests |
| A real mail provider (§4) — until then summaries and alerts are recorded as *logged* | EXTERNAL CONFIG REQUIRED |
| **Meta-approved WhatsApp templates for the interested journey's four messages** (`interested`, `interested_follow_up`, `visit_invite`, `final`): a message that starts a conversation outside the 24-hour window must be an approved template; register each under Settings → Messages | EXTERNAL CONFIG REQUIRED per workspace |
| The four new messages' wording | OPTIONAL AFTER LAUNCH — defaults are installed in every workspace and editable per project |

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

## Production readiness inventory (what exists in the repository, and what only the server can answer)

Nothing below is guessed: an item is **READY** only when the repository ships it and a test or script exercises it; anything
that needs a machine, a name or a secret only you hold is **EXTERNAL CONFIG REQUIRED** and says what is missing.
`./scripts/preflight-production.sh` prints the same picture for a given machine without echoing a value (on the build machine:
1 check passes, 23 fail — every one of them an external value or server fact).

| Item | Status | Where / what is missing |
| --- | --- | --- |
| VPS (PHP 8.4, Node 20 build, PostgreSQL, nginx) | EXTERNAL CONFIG REQUIRED | no server is named anywhere; `scripts/provision-server.sh` and `docs/PRODUCTION_RUNBOOK.md` describe it |
| Domain and DNS (web origin + API origin) | EXTERNAL CONFIG REQUIRED | the two hostnames are yours to choose; they become `FRONTEND_URL` and `APP_URL` |
| HTTPS | EXTERNAL CONFIG REQUIRED | certificates on both origins; the installable app and the service worker need it |
| `.env.production` | EXTERNAL CONFIG REQUIRED | `.env.production.example` lists every variable; `APP_KEY`, database and mail secrets are generated on the server, never committed |
| PostgreSQL | READY (as a service in `docker-compose.production.yml`) | a managed instance is an alternative; migrations are forward-only and reversible (checked up → down → up with an identical schema) |
| Redis | READY (compose service, append-only) | needed for Horizon, cache and queue in production |
| Queue worker / Horizon | READY once the process runs | supervised `horizon` service; `leadflow:sweep-scheduled` re-queues any scheduled action the queue lost |
| Scheduler | READY once the process runs | `schedule:work` service writes a heartbeat the readiness page reads |
| Storage permissions | READY in the image | `storage/` and `bootstrap/cache` are writable by the app user in the Dockerfile; verify on the first deploy |
| Backups | READY | `scripts/backup-db.sh` before every migration; keep a copy off the box |
| Restore procedure | READY | `scripts/restore-check.sh` (non-destructive, prints `RESTORE CHECK: PASS`) and `scripts/restore-db.sh` (the disaster restore) — run the check once on the server before go-live |
| Logs and rotation | READY | `daily` channel in production; container logs rotate at 20 MB × 5 in compose |
| Error tracking (Sentry) | OPTIONAL AFTER LAUNCH | not wired on the backend; the runbook lists the one `composer require` and the DSN |
| Health endpoint | READY | `GET /api/health` (no auth; component status only — application, database, Redis, queue — and the serving commit only to a caller holding the deploy secret) |
| Readiness | READY | the in-app Readiness page and `scripts/verify-production.sh` / `smoke-production.sh` after a deploy |
| Rollback | READY | `scripts/rollback.sh`; the pre-deploy backup is the safety net |

### Public values (build-time, §P)

`VITE_PUBLIC_LEGAL_NAME`, `VITE_PUBLIC_CONTACT_EMAIL`, `VITE_PUBLIC_ADDRESS` and `VITE_PUBLIC_PROCESSORS` are **not set** and are
**not invented**: until they are, the privacy, terms, data-deletion, security, contact and about pages show a visible
"[يُضاف قبل الإطلاق: …]" marker in each place. They are required before the public launch.

### Provider readiness (nothing is sent without credentials)

| Provider | State |
| --- | --- |
| Email: SMTP / SES / Resend | the adapters are built and the default `log` provider records every email as *logged*, never *sent*; a real provider needs its credentials (§4) |
| Meta (leads, WhatsApp, conversions) | needs the app credentials per workspace (§5, §7). WhatsApp messages that start a conversation outside the 24-hour window — in particular the four Interested-journey messages (`interested`, `interested_follow_up`, `visit_invite`, `final`) — must be **Meta-approved templates**, registered under Settings → Messages |
| Google (Sheets, Ads) | OAuth / developer credentials per workspace (§6, §7) |
| TikTok, Snapchat, LinkedIn, X | app credentials per workspace (§7) |

## Production deployment status — waiting for a server (2026-10-24)

**v0.6.0 is the release for production and is not deployed.** `main` = tag `v0.6.0` is the only source of truth for
production; `v0.5.1` (`f2068c9`) remains tagged as the previous release. Nothing is deployed until these exist:

| Needed | Where it goes | State |
| --- | --- | --- |
| `VPS_HOST` | GitHub → Settings → Secrets (and the operator's SSH config) | missing |
| `VPS_USER` (non-root) | GitHub → Settings → Secrets | missing |
| SSH access | the deploy key's public half in the server's `authorized_keys` (`~/.ssh/mohtam_deploy.pub`) | waiting for the server |
| `PRODUCTION_PATH` | `/var/www/mohtam` | decided |
| DNS | `mohtam.pro`, `www.mohtam.pro`, `api.mohtam.pro` A/AAAA → the server; `go.mohtam.pro` DNS-only | apex points at a parking IP |

The scripts are ready: `provision-server.sh`, `deploy-production.sh` (deploys only a commit GitHub has on `main`, refuses a
dirty server, backs up before migrating), `preflight-production.sh`, `verify-production.sh`, `smoke-production.sh`,
`backup-db.sh`, `restore-check.sh`; the edge config is `docker/nginx/edge.mohtam.conf.example` (www → apex, api, no `go.`).

In order, once the server exists:

1. Provision: `provision-server.sh` as root, from the **`v0.6.0` tag** (never `develop`).
2. DNS and HTTPS for `mohtam.pro`, `www.mohtam.pro`, `api.mohtam.pro`; certbot with auto-renewal; the edge config above.
3. `.env.production` from `.env.production.example` (domains already filled in; `APP_KEY`, `DB_PASSWORD`, `DEPLOY_SECRET`,
   `SUPER_ADMIN_PASSWORD` generated on the server — no value is invented here). Run `preflight-production.sh`.
4. PostgreSQL and Redis (compose services); Horizon and the scheduler supervised (compose `restart: unless-stopped`).
5. Backups on a schedule, a copy off the box, `restore-check.sh` once.
6. Deploy `v0.6.0` with `deploy-production.sh`, then the SaaS first-run steps above.
7. `verify-production.sh` and `smoke-production.sh`, then by hand: platform login + MFA; create a company → setup link →
   owner signs in; invite a rep → accept; company A cannot see company B; suspend a company → its subscription screen;
   Work Now on a phone (sale value, loss reason, visit time); a tracking link on `api.mohtam.pro/t/…`.

Still **EXTERNAL CONFIG REQUIRED**, none of it blocking the deploy: plan prices (console), a mail provider (§4 — invitations and
password links depend on it to reach people), the four public values (§P), WhatsApp (§5), Google OAuth (§6), ad platforms (§7),
Sentry (§9), and — only if online payment is wanted — a payment gateway behind `BillingProvider` (manual billing works without one).

## Gates run for v0.5.1 (2026-10-23, `develop` at 372f9f0)

All local; no CI/CD is wired. Nothing has been run against a real server, a real phone, or Microsoft Edge itself.

| Gate | Result |
| --- | --- |
| Backend, full `php artisan test` | 2166 passed (9443 assertions) |
| Pint | passed |
| `npm run typecheck` · `npm run lint` | clean · exit 0 (warnings only) |
| Vitest, full | 333 passed (32 files) |
| Playwright, full, one worker, all five engines | 736 passed, 62 skipped by design, 1 failed — the one (mobile-safari, Work Now on a phone) passed ×3 on separate reruns while the machine was at load average 30+ from other projects' builds: flaky/environmental |
| PWA production build + `playwright.pwa.config.ts` (Chromium, Firefox, WebKit, iPhone 14, Pixel 7) | 38 passed, 6 skipped by design, 1 failed — the one (Android, offline page) passed ×3 on separate reruns at load average 60+: environmental |
| Tenant isolation / cross-workspace leaks (`TenantIsolationTest`, `CrossTenantInjectionTest`, `WorkspacePeopleNeverLeakTest`, `TenantSettingsIsolationTest`) and RBAC (`RbacFinalReviewTest`, `RolesAndPermissionsTest`, `DynamicAccessTest`) | 66 passed (889 assertions) |
| Automation: the lead journey, the interested journey, guardrails (quiet hours, cap, cooldown, duplicate, human takeover), durable scheduled actions and `leadflow:sweep-scheduled`, alerts, SLA, staff WhatsApp | 87 passed (312 assertions) |
| Digests and report delivery: daily, weekly, monthly, scheduled reports, email alerts | 68 passed (493 assertions) |
| Migrations | 105 run, 0 pending; `migrate:reset` then `migrate` leaves an identical schema (`pg_dump -s` diff empty); the release adds one additive migration (`install_interested_messages`), nothing destructive |

The #204 fix is covered: every "my managers / my salespeople" read goes through `User::inWorkspace($tenant)` (fail-closed), and
tests pin the manager and seller lists, WhatsApp approval recipients, SLA escalation recipients, agent notes, user search,
salesperson filters, onboarding lists and notifications — workspace A never sends to, shows or leaks to workspace B.

Found and fixed during the gates (not features): the sales-team report returned a 500 on any date range (an ambiguous
`created_at` in the shared lead date filter); `agent_messages` had no index on its thread (additive migration);
a sales rep was shown a link to `/automation` on the dashboard (now shown only with `automation.view`); the bottom
tab bar's `tab-*` test ids collided with the pages' own tab strips (now `bottom-tab-*`); the cross-workspace leak above.

Left to you: the four public-page values (§P), the server (§2) and the credentials in §4–§7.
