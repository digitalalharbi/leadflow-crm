# Security

## Threat model

The product holds names, phone numbers and buying intent for other companies'
customers. The realistic threats are:

1. One tenant reading another tenant's leads
2. A stolen or guessed credential
3. An attacker posting junk or probing the public ingestion endpoint
4. Stored ad-platform credentials leaking
5. An insider exfiltrating the lead list

## Controls

### Tenant isolation
Covered in depth in [MULTI_TENANCY.md](MULTI_TENANCY.md). Four layers; 18 tests.

### Authentication
* Sanctum bearer tokens, hashed at rest
* Passwords hashed with bcrypt via Laravel's `hashed` cast
* Login rate-limited: 5 attempts per minute per email+IP, 20 per IP
* **Identical** response for an unknown email and a wrong password, so the login
  form cannot be used to enumerate accounts
* Deactivating a user deletes their tokens immediately
* Suspending a tenant deletes every token belonging to it
* Changing a password revokes all other sessions

### Authorisation
* A policy on every resource
* Role checks in both the API and the UI — the UI hides what a user cannot do;
  the API refuses it regardless
* The super-admin console returns **404**, not 403, to a tenant user: it does not
  admit that the route exists

### Public ingestion endpoint
This is the only unauthenticated write path, so it is the most defended:

* Tenant comes from the endpoint key, never from the payload
* Shared secret hashed with bcrypt, compared in constant time
* Body size capped before parsing (`WEBHOOK_MAX_BODY_BYTES`, default 64 KB)
* Rate limited per endpoint + IP
* An unknown key and a bad secret return the **same** 401, so valid keys cannot
  be probed
* Every attempt is logged — accepted, duplicate, rejected or failed
* `secret`, `token`, `password` and `authorization` fields are redacted before
  the payload is stored

### Secrets
* Integration credentials use Laravel's `encrypted:array` cast — encrypted at
  rest with `APP_KEY`
* Credentials are in `$hidden` and never serialised; the API returns only *which*
  keys are configured, never a value
* A webhook secret is shown exactly once, at creation, and only its hash is kept
* The audit logger redacts anything whose key matches `password|secret|token|
  credentials|access_token|client_secret|api_key`
* `.gitignore` excludes `.env`, `*.pem`, `*.key`, `id_rsa*`

### Input and output
* Form Requests validate every write
* Mass assignment protection stays on; `preventSilentlyDiscardingAttributes` is
  enabled outside production so a stray attribute fails loudly in development
* React escapes by default; the app uses no `dangerouslySetInnerHTML`
* Raw server errors never reach the UI — statuses are mapped to translated
  messages, and a 500 shows a generic string unless `APP_DEBUG` is on
* `provider_payload` is hidden from the lead API resource

### Transport and headers
* HTTP redirects to HTTPS; HSTS set at the edge
* `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: strict-origin-when-cross-origin`
* `X-Powered-By` suppressed; only `index.php` is executable under the web root

### Auditing
`audit_logs` records logins, user creation and deactivation, role changes,
tenant settings and branding changes, integration changes, webhook secret
rotation and lead deletion. Routine CRUD lives in `lead_activities` instead, so
the audit trail stays readable.

### Data retention
* Soft deletes on leads, users and projects — a mistake is recoverable
* An uploaded CSV is deleted from disk as soon as it has been imported

## Deployment safety

CI fails the production deploy if `migrate:fresh`, `db:wipe` or `DROP DATABASE`
appears anywhere in `scripts/` or `.github/workflows/`. Production takes a
gzip-verified backup before migrations, and a failed health check rolls the code
back automatically.

## Pre-release security review

Before tagging, the backend was audited adversarially — reading it as an engineer
who had not written it, specifically hunting for isolation leaks. It found real
defects. All are fixed and covered by regression tests
(`CrossTenantInjectionTest`, `SecurityHardeningTest`).

| Severity | Defect | Fix |
| --- | --- | --- |
| **Critical** | A webhook payload could set `leads.project_id` to another tenant's internal id, and the project report joined `projects` with no tenant predicate — so tenant A could enumerate every other customer's project names | Internal foreign keys removed from the fields a payload may set; ingestion re-resolves them under the tenant scope; every report join carries its own `tenant_id` predicate |
| High | `DatabaseSeeder` would create the platform owner with the password `password` in production | Seeding aborts outside local development unless `SUPER_ADMIN_PASSWORD` is set; read via config, not `env()` |
| High | `trustProxies(at: '*')` made `X-Forwarded-For` caller-controlled, bypassing the login limiter and forging audit-log IPs | Trust only `TRUSTED_PROXIES` |
| High | The `api` rate limiter was defined but never attached, leaving every authenticated endpoint unthrottled | `throttleApi()` added to the middleware stack |
| High | CSV import and integration config accepted arbitrary write targets | Both allow-listed; integration defaults take UUIDs resolved under the tenant scope |
| Medium | `unique(tenant_id, email)` ignored soft deletes, so a removed user could never be re-added (500) | Partial unique index on active rows only |
| Medium | Login took the first row when one email existed in several tenants, locking the other account out | Check the password against each candidate, preferring active accounts |
| Medium | Any rep could read another user's import, exposing raw CSV rows for leads they are not assigned to | `ImportPolicy` scopes imports to their uploader |
| Medium | Notification routes sat outside `tenant.active`, so a suspended tenant kept reading lead names and phone numbers | Moved inside the gate; only `/auth/me` and logout stay outside |
| Medium | `rescore()` added to the stored score, so toggling a status could drive any lead to "hot" | Recomputed from the lead's attributes plus a stage bonus |
| Medium | `APP_DEBUG=true` was the committed default, and the debug branch echoed raw SQL | Default is now `false`, and detail requires a local environment as well as the flag |
| Medium | Several multi-step writes had no transaction | Wrapped |
| Low | Credentials in a webhook body were redacted for the delivery log but stored in clear on the lead | Redacted everywhere the payload is persisted |
| Low | `logout()` crashed on a session-authenticated request | Guarded against `TransientToken` |
| Low | Pipeline issued ~24 queries; mark-all-read issued one UPDATE per row | One windowed query; one bulk UPDATE |

## Second security review (pre-demo)

The product was audited a second time before the Live Demo release, on the
assumption that the first pass had been read too charitably. This round covered
IDOR, tenant isolation, identifier guessing, webhook injection, CSV import,
custom fields, project assignment, reporting, filters, super-admin routes,
integration credentials and mass assignment.

Each finding got a failing regression test **before** its fix, and every test was
re-run against the unfixed code to confirm it actually detects the defect — the
previous review had shipped one assertion that could never fail.

| Severity | Defect | Fix | Test |
| --- | --- | --- | --- |
| **High** | Exported CSVs wrote lead fields verbatim, so a lead name submitted through the public webhook as `=cmd\|'/c calc'!A1` became a live formula in the buyer's spreadsheet — remote code execution against the customer, injected by an anonymous caller | `CsvWriter` prefixes any cell opening with `= + - @ TAB CR` with an apostrophe; both the leads export and the report export go through it | `ExportSafetyTest` |
| Medium | Every `{uuid}` route and the `project_id` / `assigned_to` filters returned **500** for a malformed identifier: the value reached a Postgres `uuid` column and raised SQLSTATE 22P02, which the renderer had no case for | One route pattern constrains every identifier segment, so a bad id is a 404 at the router; filters drop malformed values; the renderer maps a query failure to 404/500 and never renders its message | `MalformedInputTest` |
| Medium | The trusted-proxy test read a protected property through `??`, so it asserted `null !== '*'` and would have passed with every proxy trusted | Replaced with a behavioural assertion that a forged `X-Forwarded-For` does not reach the audit trail; `*` is now filtered out of `TRUSTED_PROXIES` rather than honoured | `SecurityHardeningTest` |
| Low | Login answered an unknown email without hashing anything — a timing oracle for enumerating addresses. The `unset($candidates)` line claimed to prevent this and did nothing | When no candidate exists, hash anyway, at the configured cost | `SecurityHardeningTest` |
| Low | The webhook secret could be sent in the query string, where it lands in access logs, proxy logs and browser history | Accepted only from the `X-Webhook-Secret` header, a bearer token, or the request body | `WebhookReadinessTest` |
| Low | An ingestion failure wrote `$e->getMessage()` — the SQL statement with its bindings, i.e. the lead's name and phone — to the application log and to the tenant-readable `webhook_logs.error` | Both record the exception class and a correlation reference; the message is dropped | `WebhookReadinessTest` |
| Low | `POST /leads` let a sales rep hand a lead to any colleague, and anyone hand one to a deactivated account or a user in another tenant — the id was resolved without checking the policy, `is_active`, or the result | Choosing an owner now requires `assignOnCreate`; the target must be active and in the tenant; an id we cannot honour is refused, not silently dropped | `AssignmentIntegrityTest` |
| Low | `rotateWebhookSecret` authorized against `Integration::class` when the endpoint had no integration (`integration_id` is nullable), reaching a TypeError — a 500 and an unauthorized rotation path | Guarded with `abort_if`, as `deleteWebhook` already was | `WebhookReadinessTest` |
| Low | The payload flattener took a "leaf" from keys with no dot, stripping their first character — so an advertiser's `xcity` field could pass itself off as `city` | A key with no dot has no leaf | `WebhookReadinessTest` |

## Third security review (WhatsApp and qualification)

The WhatsApp release added two things the product did not have before: a public
endpoint anyone on the internet can POST to, and long-lived credentials
belonging to each customer. It was reviewed on that basis, adversarially, by
someone who had not written it.

Ten findings, each with a failing regression test written before the fix.

| Severity | Defect | Fix |
| --- | --- | --- |
| **High** | `TemplateRenderer` called a `firstWord()` method that was never written. Any lead without a first name — the normal shape of a lead that arrived as a bare phone number through the public webhook — produced a hard 500 on the composer and on template preview. `\Error` is not a `RuntimeException`, so the controller's catch did not see it | Implemented as `givenName()`, which also refuses a "name" that is a phone number or an email and drops the placeholder instead |
| **Medium/High** | Unsigned webhooks were accepted wherever `APP_ENV` was not the literal string `production`. `prod`, `staging`, `uat` or a typo all failed open, and a phone number id is a public Meta identifier rather than a secret — so anyone could write messages, opt-ins and score changes into a workspace that had a number but no app secret | Inverted to an allow-list (`local`, `testing`) in both the signature check and the send gate. A deny-list on one spelling of one environment name is not a security control |
| Medium | The public webhook's throttle keyed on `route('key')`, a parameter these routes do not have. Every tenant collapsed into one bucket per source IP — and Meta delivers from a narrow range, so one busy workspace would starve the rest until Meta disabled the callback | Its own limiter, keyed on the phone number the delivery arrived on, with a looser per-IP limit outside it |
| Medium | Both webhook rejection paths wrote a row keyed on a fresh UUID, before any authentication, so the unique index could never collapse a repeat — unauthenticated unbounded table growth. `tenant_id` is nullable there, and a NULL never collides in a Postgres unique index, so no fixed key would have helped either | Rejections are a tally: a partial unique index over `COALESCE(tenant_id, 0)` and an upsert that increments `occurrences`. "4,812 probes against this number" is also more useful than 4,812 identical rows |
| Medium | Two workspaces could claim the same WhatsApp number — the check was a bare `exists()` with nothing behind it in the schema — and the routing query had no `ORDER BY`, so Postgres decided whose customer messages landed where | A partial unique index on `config->>'phone_number_id'`, which is also the index that unauthenticated lookup needed; the query is ordered and active-only |
| Low/Medium | `PATCH /integrations/{uuid}` threw on the WhatsApp integration and answered 500, and `DELETE` succeeded — bypassing the proper disconnect, leaving the credentials on the row and writing the wrong action to the audit trail | Providers with a screen of their own are not found through the generic endpoints |
| Low | The integration row was created before the phone number was validated, so a rejected first save left an orphan that reported itself connectable — and outside production told reps "Message sent." for messages nothing had sent | The whole save is one transaction |
| Low | A `statuses` callback wrote attacker-supplied failure text before the terminality check, with a lookup that did not filter on direction — so arbitrary text could be stamped onto a message the customer had sent, and shown back to the tenant | Outbound only, written after the status is allowed to move, and truncated |
| Low | `per_page=-1` on the conversation: the query builder silently ignores a negative limit, so no `LIMIT` was emitted and the entire history came back | Floored as well as capped |
| Low | Saved messages borrowed `IntegrationPolicy`, which is admin-only — excluding sales managers by accident, and tying "who may rewrite what we say to buyers?" to "who may hold an API key?". `index()` had no authorization at all and `?all=1` exposed deactivated messages to everyone | A `MessageTemplatePolicy` of its own |

Also closed from the same review: the claim on an inbound message now happens
inside the transaction that writes it, so a failure between them no longer
answers Meta's retry with "already seen" and loses the customer's message; and
outbound sending is rate-limited per conversation, because a stuck client or a
stolen token pointed at one buyer is how a tenant's WhatsApp number gets
reported — which ends the channel for them.

### What the review found nothing in

Worth recording, because these were the categories most likely to be wrong:
credential leakage through responses, logs, the audit trail or serialisation;
the signature comparison itself (computed over the captured raw body, compared
with `hash_equals`, per tenant); replay (`ON CONFLICT DO NOTHING` lets the index
decide rather than a check-then-insert); cross-tenant routing; IDOR on the new
lead, qualification and template routes; mass assignment; and SQL injection in
the reports, where every raw fragment is built from enum constants.

## Known gaps

Honest list of what is **not** done, and why:

| Gap | Why | Mitigation |
| --- | --- | --- |
| No 2FA | Not in the MVP scope | Rate limiting + lockout; Sanctum supports adding it |
| No password reset email flow | Needs a configured mailer | Admins can set a password; the table exists |
| No field-level encryption on phone numbers | Would break search and de-duplication | Encrypted at rest at the disk/provider level |
| `/horizon` is admin-gated only | Convenience during the MVP | Put it behind a VPN before production exposure |
| Backups land on the same VPS by default | No off-site target configured yet | `EXTERNAL_SETUP.md` flags this before go-live |
