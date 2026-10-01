# Build status

Last updated: 2026-10-01

```
Backend tests   150 passed
Frontend tests   32 passed
Lint / types     clean (Pint, oxlint, tsc strict)
Production build succeeds
```

---

## Completed

### Foundation
- [x] Monorepo: `backend/`, `frontend/`, `docker/`, `docs/`, `scripts/`, `.github/`
- [x] Laravel 12 (pinned) on PHP 8.4, API only
- [x] React 19 + Vite + TypeScript strict + Tailwind 4
- [x] PostgreSQL 16 schema, 29 tables, indexed for the screens that use them
- [x] Redis for cache and queues; Horizon for the queue dashboard
- [x] Git repository, `main` + `develop`, pushed to GitHub

### Multi-tenancy and auth
- [x] `TenantContext` + global scope + create/update guards
- [x] Policies on every resource
- [x] Sanctum token auth; per-device tokens
- [x] Roles: admin, manager, sales + platform super admin
- [x] Tenant suspension and subscription expiry enforced mid-session
- [x] 18 isolation tests, run as their own CI step
- [x] Adversarial security review completed; 15 defects found and fixed, each
      with a regression test (see `docs/SECURITY.md`)

### White label
- [x] Per-tenant branding served from a public endpoint
- [x] Runtime theming via CSS custom properties — no rebuild per customer
- [x] Subdomain tenant resolution with reserved slugs
- [x] One-call tenant provisioning (tenant + branding + settings + admin +
      lost reasons + webhook)
- [x] Arabic RTL default, English LTR supported

### CRM
- [x] Leads: CRUD, search, filters, sort, server-side pagination, CSV export
- [x] Saudi phone normalisation incl. Arabic-Indic digits
- [x] Duplicate detection as a database constraint; returning numbers re-engage
- [x] Six-stage pipeline with drag-and-drop and optimistic updates
- [x] Lost reasons mandatory on loss
- [x] Follow-ups: overdue / today / upcoming / completed
- [x] Activity timeline, notes, call and WhatsApp logging
- [x] Projects, custom fields, lead scoring and temperature
- [x] Manual, round-robin and explicit assignment
- [x] In-app notifications with hourly reminder dispatch

### Integrations
- [x] Generic webhook: per-tenant URL + hashed secret, rate limited, size capped,
      fully logged, payload redacted
- [x] Per-tenant field mapping with Arabic and English aliases
- [x] CSV import wizard on the queue
- [x] Google Sheets, Meta, TikTok, Google Ads, LinkedIn, Snapchat adapters
- [x] Integrations page with per-provider status

### UI
- [x] Login, Dashboard, Leads, Lead detail, Pipeline, Follow-ups, Projects,
      Reports, Integrations, Users, Settings, Profile, Platform console
- [x] Design tokens, UI primitives, skeletons, empty states, error states
- [x] Responsive: verified at 375 px, 768 px and desktop
- [x] Onboarding checklist for a new tenant admin

### DevOps
- [x] Docker Compose for local, staging and production
- [x] Four GitHub Actions workflows
- [x] Deploy scripts with health check and automatic rollback
- [x] Backup / restore / rollback scripts
- [x] CI gate that refuses destructive database commands in the deploy path
- [x] Nginx configs including a TLS edge example

### Documentation
- [x] README, 9 documents in `docs/`, `EXTERNAL_SETUP.md`, `README-DEMO.md`

---

## Pending external credentials

None of these block the product; each is isolated behind configuration.

| Item | Effect today | Unblocks |
| --- | --- | --- |
| VPS host + SSH key | CI tests, then skips deploy with a notice | Live staging and production |
| Domain + DNS | Runs on localhost / IP | Public URLs, per-tenant subdomains |
| TLS certificates | HTTP only | HTTPS |
| Meta / TikTok / Google / LinkedIn / Snapchat keys | Those providers report "configuration required"; the generic webhook and CSV import work | Direct API pulls |

Details and commands: [`EXTERNAL_SETUP.md`](EXTERNAL_SETUP.md).

---

## Deferred by design

Out of MVP scope, and the architecture does not block any of them:

WhatsApp inbox · AI qualification and summaries · marketing automation builder ·
call centre · property inventory · accounting and invoicing · payment gateway ·
native mobile app · Elasticsearch · Kubernetes · microservices · full DDD ·
WebSockets · custom domains

---

## Known gaps

Honest list:

1. **No automated browser E2E.** Flows were verified by hand in a real browser —
   login, dashboard, leads, detail, status change, note, pipeline drag, mobile
   layout. A Playwright suite is the first thing to add.
2. **Backups default to the same VPS.** Flagged in `EXTERNAL_SETUP.md`; needs an
   off-site target before go-live.
3. **No password-reset email flow.** Needs a configured mailer; admins can set a
   password in the meantime.
4. **Provider `fetch()` tested with mocks only**, since live credentials are not
   available.
5. **`/horizon` is admin-gated only.** Put it behind a VPN before exposing it.

---

## Next five, commercially

1. Automated browser E2E, so releases stop depending on a manual pass
2. WhatsApp Cloud API — a real inbox is the most-requested feature in this market
3. Campaign spend ingestion → CPL, CPQL, cost per appointment, cost per sale
4. Billing: Stripe or a local gateway against the plan fields that already exist
5. Conversion APIs (Meta CAPI, Google offline conversions, TikTok Events) to send
   won deals back to the ad platforms
