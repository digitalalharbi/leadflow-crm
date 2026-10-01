# Integrations

## Contract

Every lead source implements one interface:

```php
interface LeadProviderInterface
{
    public function key(): string;
    public function toIncomingLead(array $payload, ?Integration $i = null): IncomingLead;
    public function isConfigured(?Integration $i = null): bool;
    public function fetch(Integration $i): array;      // push-based providers return []
    public function requiredCredentials(): array;
}
```

Adding a source means writing one class. Nothing downstream changes, because
everything converges on `IncomingLead` before the ingestion service sees it.

## The pipeline

```
Provider payload
   │
   ▼  provider->toIncomingLead()   field mapping + aliases
IncomingLead (canonical DTO)
   │
   ▼  LeadIngestionService
   ├─ normalise phone to E.164
   ├─ validate (needs a phone or an email)
   ├─ duplicate check on (tenant_id, phone_normalized)   ← row lock
   ├─ create  ──or──  re-engage existing
   ├─ record a lead_source_event (always, duplicate or not)
   ├─ assign (explicit → round robin → unassigned)
   ├─ log an activity
   └─ notify the assignee
```

The row lock matters: two webhooks delivering the same number at the same
moment cannot both create a lead.

## Providers

| Provider | Delivery | Credentials | Status without them |
| --- | --- | --- | --- |
| **Generic webhook** | push | none | Works today |
| **CSV import** | upload | none | Works today |
| Google Sheets | pull | `access_token` | Sandbox: returns no rows |
| Meta (FB + IG) | push or pull | `access_token` | Configuration required |
| TikTok | pull | `access_token`, `advertiser_id` | Configuration required |
| Google Ads | push | `google_key` | Configuration required |
| LinkedIn | pull | `access_token`, `account_id` | Configuration required |
| Snapchat | pull | `access_token`, `ad_account_id` | Configuration required |

A provider without credentials never throws. It reports "configuration required"
on the Integrations page and the rest of the product carries on.

## The generic webhook

The most important integration in the MVP, because it accepts anything.

```bash
curl -X POST 'https://api.example.com/api/v1/integrations/webhooks/<key>/leads' \
  -H 'Content-Type: application/json' \
  -H 'X-Webhook-Secret: <secret>' \
  -d '{"name":"سعود الحربي","mobile":"0512345678","city_name":"الرياض"}'
```

### Field mapping

Stored per integration as `jsonb`, `{ "incoming": "canonical" }`:

```json
{ "اسم_العميل": "full_name", "رقم_الاتصال": "phone", "المنطقة": "city" }
```

Common names are recognised without any mapping at all: `name`, `fullname`,
`الاسم`, `mobile`, `phone_number`, `الجوال`, `city_name`, `المدينة`, `campaign`,
`utm_campaign`, and more. Nested payloads are dot-flattened, and the leaf name is
also exposed — so `lead.phone` maps as `phone` without configuration.

### Defaults

```json
{ "platform": "tiktok", "source": "landing_page", "project_id": 3 }
```

Applied only where the payload left the field empty.

## Meta

Meta delivers `field_data` rather than a flat object:

```json
{ "id": "lead-99", "form_id": "f-1",
  "field_data": [
    { "name": "full_name",    "values": ["نورة الشمري"] },
    { "name": "phone_number", "values": ["+966512345678"] }
  ] }
```

`MetaLeadProvider` flattens that before the standard mapping runs. The `GET` on
the webhook path answers Meta's subscription handshake using `META_VERIFY_TOKEN`.

## CSV import

Upload → preview → map columns → validate → import → results.

The mapping step is pre-filled by matching headers against known names, including
Arabic ones (`الاسم`, `الجوال`, `المدينة`), so most files need one click. The
import runs on the queue, rows are processed individually, and a failed row is
recorded with its reason rather than aborting the file. The uploaded file is
deleted once processing finishes.

## Adding a provider

1. Extend `AbstractLeadProvider`, implement `provider()`
2. Override `aliases()` if the platform uses unusual field names
3. Override `toIncomingLead()` only if the payload is not a flat object
4. Implement `fetch()` for pull-based sources
5. List it in `ProviderRegistry::$map` and `connectable()`
6. Add `requiredCredentials()` and a `config/integrations.php` entry
7. Write a test using a captured real payload

Step 7 is what makes a provider trustworthy without live credentials.
