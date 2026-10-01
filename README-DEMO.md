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

* 5 projects, 1 sales manager, 5 sales reps
* 180 leads shaped like a real funnel — 48 new, 42 contacted, 31 qualified,
  18 with an appointment, 14 won, 27 lost — not spread evenly
* Hot / Warm / Cold mix driven by the actual scoring rules
* Follow-ups across all three buckets: 22 overdue, 59 due today, 36 upcoming.
  Overdue has to be visible, because it is the problem the product solves
* Activity timelines, notes, lost reasons and campaign attribution
* 14 campaigns across Meta, TikTok, Snapchat, Google Ads, LinkedIn and owned
  channels, so the source report has something to say
* WhatsApp conversations on about half the worked leads — including twenty-odd
  where the customer has not replied yet, which is the case the follow-up
  queue exists for
* Qualification filled in to the degree each stage implies, so the scoring
  card has reasons to show and the campaign quality report has rates to
  compare

Refresh it at any time with `./scripts/refresh-demo.sh`.

## During a demo

The **Integrations** page has a *Simulate an incoming lead* control. It pushes a
lead through the real ingestion pipeline — normalisation, duplicate detection,
assignment, activity log — so you can say "this one just arrived from the
Snapchat campaign" and have the prospect watch it land. It is admin-only, and
neither the control nor its endpoint exists in production.

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
