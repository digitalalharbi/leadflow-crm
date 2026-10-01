# Demo access

These accounts exist only in **local and staging** environments, created by
`DemoSeeder`. They are never seeded in production, and the seeder refuses to run
when `APP_ENV=production`.

## Demo tenant

**شركة رواسي للتطوير العقاري** — subdomain `rawasi`, branded as *رواسي CRM*
with a teal primary colour, so the white-label behaviour is visible immediately.

| Role | Email | Password | Sees |
| --- | --- | --- | --- |
| Admin | `admin@rawasi.test` | `password` | Everything: settings, integrations, team, reports |
| Sales manager | `manager@rawasi.test` | `password` | All leads, reports, assignment — not tenant settings |
| Sales rep | `fahad@rawasi.test` | `password` | Only leads assigned to them |
| Sales rep | `noura@rawasi.test` | `password` | Only leads assigned to them |
| Sales rep | `khalid@rawasi.test` | `password` | Only leads assigned to them |
| Sales rep | `reem@rawasi.test` | `password` | Only leads assigned to them |
| Sales rep | `majed@rawasi.test` | `password` | Only leads assigned to them |

Sign in with the admin and then with a sales rep to see role gating: the sidebar,
the dashboard totals and the leads list all change.

## Platform owner

| Role | Email | Password |
| --- | --- | --- |
| Super admin | value of `SUPER_ADMIN_EMAIL` (default `owner@example.com`) | value of `SUPER_ADMIN_PASSWORD` (default `password` locally) |

The super admin belongs to no tenant and only sees the platform console, where
new customers are created.

## Seeded data

* 3 projects, 1 sales manager, 5 sales reps
* ~85 leads spread realistically across the funnel, not evenly
* Hot / Warm / Cold mix driven by the actual scoring rules
* Follow-ups deliberately split across overdue, today and upcoming
* Activity timelines, notes, lost reasons and campaign attribution
* Six realistic campaigns across Meta, TikTok, Snapchat, Google Ads and LinkedIn

## Webhook endpoint

The seeder prints a working endpoint and its secret when it runs:

```
POST /api/v1/integrations/webhooks/<key>/leads
X-Webhook-Secret: <secret>
```

Re-run `php artisan db:seed --class=DemoSeeder` to print a fresh pair. Try it:

```bash
curl -X POST "http://localhost:8000/api/v1/integrations/webhooks/<key>/leads" \
  -H 'Content-Type: application/json' \
  -H 'X-Webhook-Secret: <secret>' \
  -d '{"name":"سعود الحربي","mobile":"0512345678","city":"الرياض"}'
```

Send it twice with the number written differently each time — `0512345678`, then
`+966 51 234 5678` — and the second call re-engages the first lead rather than
creating a duplicate.

## Resetting

```bash
cd backend
php artisan migrate:fresh --seed     # local only; wipes everything
```

> **Never** run `migrate:fresh` against staging or production. The production
> deploy path is checked by CI to make sure that command cannot appear in it.

## Before going live

Change every password above, set a real `SUPER_ADMIN_PASSWORD`, and do not seed
demo data into production.
