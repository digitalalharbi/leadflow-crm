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

## Known gaps

Honest list of what is **not** done, and why:

| Gap | Why | Mitigation |
| --- | --- | --- |
| No 2FA | Not in the MVP scope | Rate limiting + lockout; Sanctum supports adding it |
| No password reset email flow | Needs a configured mailer | Admins can set a password; the table exists |
| No field-level encryption on phone numbers | Would break search and de-duplication | Encrypted at rest at the disk/provider level |
| `/horizon` is admin-gated only | Convenience during the MVP | Put it behind a VPN before production exposure |
| Backups land on the same VPS by default | No off-site target configured yet | `EXTERNAL_SETUP.md` flags this before go-live |
