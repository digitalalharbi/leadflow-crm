# White label

The commercial promise: take this system, put a customer's name on it, and sell
it as theirs — without touching the source.

```
شركة ABC للتطوير العقاري
ABC Sales CRM
crm.abc.sa
```

## How it works

Branding is **data**, not configuration. The React app contains no product name,
no colour and no logo. It calls one endpoint:

```
GET /api/v1/branding?tenant=abc
```

```json
{ "company_name": "شركة ABC للتطوير العقاري",
  "system_name": "ABC Sales CRM",
  "logo_url": "https://cdn.example.com/abc.png",
  "primary_color": "#aa00aa",
  "locale": "ar", "currency": "SAR", "timezone": "Asia/Riyadh" }
```

That endpoint is **public**, because the login screen has to render a customer's
identity before anyone has signed in. It returns presentational fields only —
never plan, subscription state, user counts or anything operational.

### Applying it

`useBrandingTheme` writes the colours into CSS custom properties:

```ts
root.style.setProperty('--brand', branding.primary_color)
root.style.setProperty('--brand-soft', `color-mix(in srgb, ${primary} 10%, white)`)
```

Every component reads `var(--brand)`. One assignment rebrands the entire
application — buttons, active navigation, charts, focus rings, the login panel.
No rebuild, no per-customer bundle.

The document title and favicon are set from the same payload.

## What a tenant controls

| | Field |
| --- | --- |
| Identity | Company name, system name |
| Imagery | Logo, login logo, login background, favicon |
| Colour | Primary, secondary |
| Contact | Support email, support phone |
| Locale | Language (ar/en), currency, timezone, country code |
| Workflow | Assignment mode, score thresholds, WhatsApp template, lost reasons, custom fields |

An admin edits these in **Settings → Branding**, with a live preview: moving the
colour picker updates the running app immediately.

## Onboarding a customer

One API call provisions everything:

```bash
POST /api/v1/super-admin/tenants
{
  "company_name": "شركة ABC للتطوير العقاري",
  "system_name": "ABC Sales CRM",
  "subdomain": "abc",
  "primary_color": "#aa00aa",
  "admin_name": "مدير ABC",
  "admin_email": "admin@abc.sa",
  "admin_password": "…"
}
```

In one transaction that creates:

* the tenant, with a unique slug and a trial window
* branding and settings
* ten default lost reasons
* the first admin user
* a working inbound webhook endpoint, with its secret returned once

The admin can sign in immediately. No code change, no migration, no deploy.

## Subdomains

`client1.example.com` resolves to the tenant with slug `client1`, via a wildcard
DNS record. This drives **branding only** — authorisation always comes from the
authenticated account, so landing on the wrong subdomain shows the wrong logo,
never the wrong data.

Reserved slugs (`www`, `api`, `app`, `admin`, `staging`, `demo`, …) can never be
assigned to a customer.

Custom domains (`crm.abc.sa`) are deliberately out of MVP scope, but tenant
resolution goes through a single function, so adding them is a lookup change and
a certificate, not a refactor.

## Verifying it

`WhiteLabelTest` asserts that two tenants provisioned from the same code have
different names, different colours and different login screens, and that the
public branding endpoint leaks nothing operational.

```bash
cd backend && php artisan test --filter=WhiteLabelTest
```
