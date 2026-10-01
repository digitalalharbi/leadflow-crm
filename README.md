# LeadFlow CRM

A multi-tenant, white-label CRM for teams that buy leads and have to call them
back fast. Built for real-estate developers first, but nothing in the data model
is specific to property — the same codebase serves clinics, dealerships or any
business whose leads arrive from ad platforms and landing pages.

One codebase. One database. Every customer isolated, branded as their own product.

---

## What it does

A lead is submitted on Meta, TikTok, Snapchat, Google, LinkedIn, a landing page
or a spreadsheet. It lands in one inbox, normalised and de-duplicated, assigned
to a rep, and tracked through six stages until it is won or lost.

The first screen answers the only questions a sales rep has in the morning:

* How many new leads came in?
* Who do I have to follow up today, and who did I let slip?
* Who is hot?
* What is actually converting?

### Core capabilities

| Area | What is included |
| --- | --- |
| **Multi-tenancy** | One PostgreSQL database, `tenant_id` on every customer table, enforced by a global scope plus policies. A tenant cannot see another tenant's data even with a valid UUID. |
| **White label** | Company name, product name, logo, favicon, colours, support details — all per tenant, read from the API at runtime. Nothing is hard-coded in the SPA. |
| **Lead ingestion** | One pipeline for every source: normalise → validate → de-duplicate → create/re-engage → attribute → assign → log → notify. |
| **Duplicate detection** | Saudi phone normalisation: `0512345678`, `512345678`, `966512345678`, `+966 51 234 5678` and `٠٥١٢٣٤٥٦٧٨` all collapse to `+966512345678`. A returning number re-engages the existing lead instead of creating a second one. |
| **Pipeline** | New → Contacted → Qualified → Appointment → Won → Lost, with drag-and-drop and optimistic updates. Lost always requires a reason. |
| **Follow-ups** | Overdue / today / upcoming buckets, with in-app reminders dispatched hourly. |
| **Integrations** | Generic webhook (per-tenant URL + secret), CSV import wizard, Google Sheets, Meta, TikTok, Google Ads, LinkedIn, Snapchat. |
| **Reports** | By platform, campaign, project, sales rep, stage, source and lost reason, with CSV export. |
| **Roles** | Admin, manager, sales — plus a platform super admin who provisions tenants. |
| **Languages** | Arabic (RTL) by default, English (LTR) supported from day one. |

---

## Architecture

```
React 19 SPA  ──HTTPS──▶  Nginx  ──▶  Laravel 12 API (php-fpm)
                                           │
                                           ├──▶ PostgreSQL 16   (source of truth)
                                           ├──▶ Redis 7         (cache, queues)
                                           └──▶ Horizon         (queue dashboard)

Ad platforms / landing pages ──POST──▶ /api/v1/integrations/webhooks/{key}/leads
```

Deliberately **not** in the MVP: WebSockets, Elasticsearch, Kubernetes,
microservices, full DDD. The brief was a product that ships and stays
maintainable, and those would each cost more than they return at this stage.

Request flow on the backend:

```
Route → Middleware (auth, tenant context, tenant active)
      → Form Request (validation)
      → Controller (thin)
      → Service / Action (business logic)
      → Eloquent model (global tenant scope)
      → API Resource (response shaping)
```

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the reasoning behind each
decision, including the ones that were deliberately left simple.

---

## Stack

**Backend** — Laravel 12 · PHP 8.3+ · PostgreSQL 16 · Redis 7 · Sanctum · Horizon
**Frontend** — React 19 · Vite · TypeScript (strict) · Tailwind CSS 4 · TanStack Query · Zustand · React Router · dnd-kit · Recharts · i18next
**Infrastructure** — Docker Compose · Nginx · GitHub Actions · Let's Encrypt

---

## Requirements

* PHP 8.3 or 8.4 with `pdo_pgsql`, `mbstring`, `bcmath`, `intl`, `zip`
* Composer 2
* Node 22+
* PostgreSQL 16 and Redis 7
* Docker + Docker Compose (optional locally, used in staging and production)

---

## Local setup

```bash
git clone git@github.com:<owner>/leadflow-crm.git
cd leadflow-crm
./scripts/setup-local.sh
```

That copies the env files, installs both dependency trees, generates the app key,
migrates, and seeds a realistic demo tenant. It uses Docker when it is available
and falls back to the PHP and PostgreSQL already on your machine when it is not.

Then:

```bash
(cd backend && php artisan serve)   # http://localhost:8000
(cd frontend && npm run dev)        # http://localhost:5173
```

Sign-in details for the seeded demo tenant are in
[`README-DEMO.md`](README-DEMO.md).

### With Docker

```bash
cp .env.example .env     # then fill in DB_PASSWORD
docker compose up -d
docker compose exec backend php artisan migrate --seed
```

| Service | URL |
| --- | --- |
| API | http://localhost:8000 |
| SPA | http://localhost:5173 |
| Health | http://localhost:8000/api/health |

---

## Environment variables

Only `.env.example` is committed. Real values never are.

| Variable | Purpose |
| --- | --- |
| `APP_KEY` | Laravel encryption key. Also encrypts stored integration credentials. |
| `APP_CENTRAL_DOMAIN` | Root domain for subdomain tenant resolution (`client1.example.com`). |
| `FRONTEND_URL` | SPA origin, used for CORS. |
| `DB_*` | PostgreSQL connection. Each environment uses its own database. |
| `REDIS_*` | Cache, queues and Horizon. |
| `SUPER_ADMIN_EMAIL` | Bootstraps the platform owner account. |
| `WEBHOOK_MAX_BODY_BYTES` | Hard cap on an inbound webhook payload. |
| `WEBHOOK_RATE_LIMIT_PER_MINUTE` | Per-endpoint ingestion rate limit. |
| `META_*`, `TIKTOK_*`, `GOOGLE_*`, `LINKEDIN_*`, `SNAPCHAT_*` | Ad-platform credentials. Blank keeps that provider in sandbox mode instead of breaking the app. |

Everything that still needs a real value from a human is listed in
[`EXTERNAL_SETUP.md`](EXTERNAL_SETUP.md).

---

## Testing

```bash
# Backend — runs against PostgreSQL, because the app uses jsonb,
# ilike and aggregate FILTER, none of which SQLite supports.
cd backend
createdb leadflow_testing
php artisan test
./vendor/bin/pint --test

# Frontend
cd frontend
npm run lint
npm run typecheck
npm run test
npm run build
```

The suite that matters most is `TenantIsolationTest`: it asserts that a user of
tenant A cannot read, change, or even confirm the existence of tenant B's data —
including by guessing a UUID. See [`docs/TESTING.md`](docs/TESTING.md).

---

## Git workflow

```
feature/*  ──PR──▶  develop  ──▶  staging    (auto-deploy)
                       │
                       └──PR──▶  main  ──▶  production  (auto-deploy)
fix/*      ──PR──▶  develop
```

* `develop` is staging. Every merge deploys.
* `main` is production. Every merge deploys, behind an extra verification job.
* The server is never edited by hand. GitHub is the source of truth; the deploy
  script does `git reset --hard` to the exact commit CI names.

Releases are tagged `v0.1.0`, `v0.2.0`, … and `v1.0.0` for the first sellable build.

---

## Deployment

### Staging

Push to `develop`. GitHub Actions runs both test suites, then over SSH:

```
fetch → reset --hard → build images → back up database
      → migrate --force → cache config/routes/events
      → restart workers → health check
```

A failed health check rolls the code back automatically.

### Production

Merge `develop` → `main`. Same flow plus:

* a pre-flight job that boots the app, caches the config and applies migrations
  to an empty database before the VPS is touched;
* a grep that fails the build if `migrate:fresh`, `db:wipe` or `DROP DATABASE`
  ever appears in the deploy path;
* a verified backup before migrations;
* a short maintenance window only while the schema changes.

Full runbook: [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

---

## Backups and rollback

```bash
./scripts/backup-db.sh production pre-migration
./scripts/restore-db.sh production ./backups/production/<file>.sql.gz
./scripts/rollback.sh production            # previous known-good release
./scripts/rollback.sh production v1.0.0     # a specific tag
```

Backups are gzip-verified on creation and pruned after `BACKUP_RETAIN_DAYS`
(default 14). Rolling back restores **code**; migrations already applied stay
applied, which is why the backup exists.

---

## Integrations

Each tenant gets a unique, secret-protected webhook endpoint:

```bash
curl -X POST 'https://api.example.com/api/v1/integrations/webhooks/<key>/leads' \
  -H 'Content-Type: application/json' \
  -H 'X-Webhook-Secret: <secret>' \
  -d '{"name":"سعود الحربي","mobile":"0512345678","city":"الرياض","campaign":"حملة النرجس"}'
```

Incoming field names are mapped per tenant (`mobile → phone`, `الاسم → full_name`),
so a landing page does not have to change to match the CRM.

Adding a provider means writing one class that implements `LeadProviderInterface`;
nothing downstream changes. See [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md).

---

## Documentation

| Document | Contents |
| --- | --- |
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Layers, request lifecycle, and the trade-offs taken |
| [DATABASE.md](docs/DATABASE.md) | Every table, index and the reason for it |
| [API.md](docs/API.md) | Endpoint reference and the response envelope |
| [MULTI_TENANCY.md](docs/MULTI_TENANCY.md) | How isolation is enforced, and how to extend it safely |
| [SECURITY.md](docs/SECURITY.md) | Threat model and the controls in place |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | VPS provisioning, TLS, deploy and rollback |
| [INTEGRATIONS.md](docs/INTEGRATIONS.md) | Provider contract and per-provider notes |
| [WHITE_LABEL.md](docs/WHITE_LABEL.md) | Branding model and how to onboard a customer |
| [TESTING.md](docs/TESTING.md) | What is covered and how to run it |
| [EXTERNAL_SETUP.md](EXTERNAL_SETUP.md) | The only things still needing a human |
| [BUILD_STATUS.md](BUILD_STATUS.md) | What is done, in progress, and deferred |

---

## Licence

Proprietary. All rights reserved.
