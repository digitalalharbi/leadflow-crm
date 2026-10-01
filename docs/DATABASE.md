# Database

PostgreSQL 16. Chosen over MySQL for `jsonb`, `ilike`, partial indexes and
aggregate `FILTER` — all of which the reports and the ingestion pipeline use.

## Conventions

* `id` — sequential `bigint`, used for joins and foreign keys, never exposed
* `uuid` — public identifier, how every API addresses a record
* `tenant_id` — on every table holding customer data, enforced by a global scope
* `created_at` / `updated_at` — on everything
* `deleted_at` — only where recovery genuinely matters (leads, users, projects)
* Money as `decimal(14,2)`, never float
* Flexible provider data as `jsonb`, never a string blob

## Tables

### Tenancy

| Table | Purpose |
| --- | --- |
| `tenants` | The customer. Slug is the subdomain; plan and subscription fields are ready for billing. |
| `tenant_brandings` | Company name, product name, logo, colours, support contacts. One row per tenant. |
| `tenant_settings` | Locale, currency, timezone, country code, assignment mode, score thresholds, WhatsApp template, onboarding checklist. |
| `users` | Admin / manager / sales, plus the platform super admin with `tenant_id = NULL`. Email is unique **per tenant**, so one consultant can administer several agencies. |

### CRM core

| Table | Purpose |
| --- | --- |
| `projects` | What a lead is interested in. Deliberately not an inventory system — no units, no floor plans. |
| `leads` | The central record. Identity, attribution, qualification, pipeline state and provider traceability. |
| `lead_activities` | Append-only timeline: created, assigned, status changed, called, WhatsApp opened, won, lost, re-engaged… |
| `lead_notes` | Free text written by a rep. |
| `lead_source_events` | One row per inbound touch. A returning phone number adds an event here rather than a second lead. |
| `follow_ups` | Scheduled callbacks with due date, assignee, note, outcome. |
| `lost_reasons` | Per-tenant, seeded with ten sensible defaults, editable by an admin. |
| `custom_fields` / `custom_field_values` | Simple per-tenant extra fields (text, number, select, date, boolean). Not a form builder. |

### Integrations

| Table | Purpose |
| --- | --- |
| `integrations` | One row per configured provider per tenant. Credentials encrypted; `field_mapping` and `defaults` as `jsonb`. |
| `webhook_endpoints` | Per-tenant ingestion URLs. Only a bcrypt hash of the secret is stored. |
| `webhook_logs` | Every inbound attempt with its outcome. `tenant_id` is nullable so a rejected unknown key is still recorded. |
| `imports` / `import_rows` | CSV wizard state and per-row results, so a failed row can be shown back to the user. |

### Platform

| Table | Purpose |
| --- | --- |
| `notifications` | Laravel's table plus `tenant_id`, so notifications can be scoped and purged per tenant. |
| `audit_logs` | Security-relevant actions only. `tenant_id` nullable for super-admin actions. |
| `personal_access_tokens` | Sanctum. |

## The `leads` table

The one worth reading in full.

```
Identity        first_name, last_name, full_name, phone, phone_normalized,
                email, city

Attribution     platform, source, campaign_name, campaign_id, adset_name,
                adset_id, ad_name, ad_id, form_name, form_id

Qualification   status, temperature, score, budget_min, budget_max,
                purchase_purpose, payment_method, financing_status,
                preferred_property_type, preferred_location, purchase_timeline

Workflow        assigned_to, assigned_at, next_follow_up_at, last_contact_at,
                won_at, lost_at, lost_reason_id, lost_note, re_engaged_count

Traceability    provider, provider_lead_id, provider_payload (jsonb, hidden),
                created_by
```

### The constraint that defines the product

```sql
UNIQUE (tenant_id, phone_normalized)
```

Duplicate detection is a database guarantee, not application etiquette. Two
tenants may both hold the same buyer; one tenant may not hold them twice.
`phone_normalized` is E.164, so `0512345678` and `+966 51 234 5678` collide as
they should.

## Indexes

Every index exists because a specific screen needs it:

| Index | Serves |
| --- | --- |
| `(tenant_id, phone_normalized)` unique | De-duplication on every ingest |
| `(tenant_id, status)` | Leads filter, pipeline columns, reports |
| `(tenant_id, temperature)` | "Who is hot" on the dashboard |
| `(tenant_id, assigned_to)` | A sales rep's own list |
| `(tenant_id, project_id)` | Project filter and report |
| `(tenant_id, created_at)` | Default sort, date filters, 14-day trend |
| `(tenant_id, next_follow_up_at)` | Overdue detection |
| `(tenant_id, platform)` | Platform report |
| `(provider, provider_lead_id)` | Idempotency when a provider re-sends |
| `(tenant_id, status, due_at)` on `follow_ups` | Overdue / today / upcoming buckets |
| `(tenant_id, assigned_to, status, due_at)` | A rep's follow-up queue |
| `(tenant_id, lead_id, created_at)` on activities | Lead timeline |

## Avoiding N+1

`Model::preventLazyLoading()` is enabled outside production, so a missing eager
load throws during development instead of quietly costing 200 queries in staging.
List endpoints eager-load only the columns they render:

```php
->with(['assignee:id,uuid,name', 'project:id,uuid,name'])
```

## Migrations

```bash
php artisan migrate              # apply
php artisan migrate:status       # what is pending
php artisan migrate --pretend    # show the SQL without running it
```

`migrate:fresh` and `db:wipe` are local-only. CI fails the production deploy if
either appears in the deploy path.
