# API

Base URL: `/api/v1`. Authentication: `Authorization: Bearer <token>`.

## Response envelope

Every endpoint returns the same shape, success or failure:

```json
{ "success": true, "data": { }, "message": null, "errors": null }
```

Paginated collections add `meta`:

```json
{
  "success": true,
  "data": [ ],
  "meta": { "current_page": 1, "last_page": 4, "per_page": 25, "total": 85, "from": 1, "to": 25 },
  "message": null,
  "errors": null
}
```

Errors put field messages in `errors`:

```json
{
  "success": false,
  "data": null,
  "message": "The submitted data is invalid.",
  "errors": { "phone": ["Another lead in your organisation already uses this phone number."] }
}
```

## Status codes

| Code | Meaning |
| --- | --- |
| 200 / 201 | Success |
| 401 | Not authenticated, or the token was revoked |
| 402 | Subscription expired |
| 403 | Authenticated but not allowed; also a suspended tenant |
| 404 | Not found — **including another tenant's record**, which is never acknowledged |
| 413 | Webhook payload too large |
| 422 | Validation failed |
| 429 | Rate limited |

## Public

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/health` | Application, database and Redis. No version or config disclosed. |
| `GET` | `/v1/branding?tenant={slug}` | Presentational fields only. Powers the login screen. |
| `POST` | `/v1/auth/login` | Rate limited. `{ email, password, tenant? }` |
| `POST` | `/v1/integrations/webhooks/{key}/leads` | Lead ingestion. Secret via `X-Webhook-Secret`. |
| `GET` | `/v1/integrations/webhooks/{key}/leads` | Meta subscription handshake. |

## Authenticated

### Auth
```
GET    /v1/auth/me
POST   /v1/auth/logout
POST   /v1/auth/logout-all
POST   /v1/auth/password
```

### Leads
```
GET    /v1/leads                    search, filters, sort, pagination
POST   /v1/leads
GET    /v1/leads/{uuid}             lead + activities + notes + follow-ups
PATCH  /v1/leads/{uuid}
DELETE /v1/leads/{uuid}             soft delete, admin only
POST   /v1/leads/{uuid}/assign
POST   /v1/leads/{uuid}/status      lost_reason_id required when status=lost
POST   /v1/leads/{uuid}/temperature
POST   /v1/leads/{uuid}/notes
POST   /v1/leads/{uuid}/contact     channel: call | whatsapp | email | no_answer
GET    /v1/leads/export             CSV, honours the current filters
```

**Query parameters on `GET /v1/leads`**

| Parameter | Example |
| --- | --- |
| `search` | `0512345678` — a phone-shaped term also matches the normalised column |
| `status[]`, `temperature[]`, `platform[]` | repeatable |
| `project_id`, `assigned_to` | UUID; `assigned_to=unassigned` is supported |
| `date_from`, `date_to` | `YYYY-MM-DD` |
| `follow_up` | `overdue` \| `today` \| `upcoming` |
| `sort`, `direction` | `created_at`, `score`, `full_name`, … / `asc` \| `desc` |
| `page`, `per_page` | per_page capped at 100 |

### Pipeline, follow-ups, projects, users
```
GET    /v1/pipeline                 columns with per-column totals
GET    /v1/follow-ups?bucket=       overdue | today | upcoming | completed | all
GET    /v1/follow-ups/counts
POST   /v1/follow-ups
PATCH  /v1/follow-ups/{uuid}
POST   /v1/follow-ups/{uuid}/complete
DELETE /v1/follow-ups/{uuid}

GET|POST       /v1/projects
GET|PATCH|DELETE /v1/projects/{uuid}

GET    /v1/users
GET    /v1/users/assignable         lightweight, available to every role
POST   /v1/users
PATCH  /v1/users/{uuid}
DELETE /v1/users/{uuid}
```

### Dashboard and reports
```
GET    /v1/dashboard?date_from=&project_id=&assigned_to=
GET    /v1/reports?type=            platform | campaign | project | sales_rep |
                                    status | lost_reason | source
GET    /v1/reports/export
```

### Integrations
```
GET    /v1/integrations             catalogue + this tenant's instances
POST   /v1/integrations
PATCH  /v1/integrations/{uuid}
DELETE /v1/integrations/{uuid}
POST   /v1/integrations/{uuid}/webhooks       returns the secret once
POST   /v1/webhook-endpoints/{uuid}/rotate
DELETE /v1/webhook-endpoints/{uuid}
GET    /v1/webhook-logs

GET    /v1/imports
POST   /v1/imports                  multipart CSV upload
GET    /v1/imports/{uuid}
POST   /v1/imports/{uuid}/process   mapping + defaults, runs on the queue
```

### Settings
```
GET    /v1/settings
PATCH  /v1/settings                 locale, currency, timezone, assignment, scoring
PATCH  /v1/settings/branding
POST   /v1/settings/onboarding
POST|PATCH /v1/settings/lost-reasons[/{uuid}]
POST|PATCH /v1/settings/custom-fields[/{uuid}]
```

### Platform console — super admin only
```
GET    /v1/super-admin/stats
GET    /v1/super-admin/tenants
POST   /v1/super-admin/tenants      provisions tenant + branding + settings +
                                    admin + defaults + webhook, in one call
GET    /v1/super-admin/tenants/{uuid}
PATCH  /v1/super-admin/tenants/{uuid}
POST   /v1/super-admin/tenants/{uuid}/admins
```

These return **404** to a tenant user, not 403.

## Ingestion example

```bash
curl -X POST 'https://api.example.com/api/v1/integrations/webhooks/<key>/leads' \
  -H 'Content-Type: application/json' \
  -H 'X-Webhook-Secret: <secret>' \
  -d '{
        "name": "سعود الحربي",
        "mobile": "0512345678",
        "city_name": "الرياض",
        "campaign": "حملة النرجس"
      }'
```

```json
{ "success": true,
  "data": { "lead_id": "c2ca0f54-…", "outcome": "created" },
  "message": "Lead created.", "errors": null }
```

Send the same number as `+966 51 234 5678` and the outcome becomes
`re_engaged` — the original lead is updated and the new campaign is recorded as
a source event.

## Rate limits

| Scope | Limit |
| --- | --- |
| Authenticated API | 120 / minute / user |
| Login | 5 / minute / email+IP, 20 / minute / IP |
| Webhook | `WEBHOOK_RATE_LIMIT_PER_MINUTE` (default 120) per endpoint + IP |
