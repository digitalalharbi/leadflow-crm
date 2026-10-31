# Integrations runbook

One screen: **Integrations** (sources, ad accounts, WhatsApp, email, calls, API keys, webhooks) and its technical twin
**Readiness** (credentials, OAuth, webhook, permissions, last sync, production status: *Live verified · Configured ·
Awaiting external · Error*). Every connection belongs to exactly one workspace; platform credentials (the app id/secret
of each ad platform) live in the server `.env`.

## Lead sources
| Source | How leads arrive | Needs |
| --- | --- | --- |
| Website / landing forms | Mohtam forms (`/l/<token>`), honeypot + rate limit + idempotent submission id | nothing |
| Generic webhook | `POST /api/v1/integrations/webhooks/<key>/leads` with `X-Webhook-Secret` | nothing |
| Public API | `POST /api/v1/public/leads` with a workspace API key | an API key |
| Google Sheets | OAuth → picker → mapping → preview → import → sync (incremental, deduplicated) | Google app (owner) |
| Meta Lead Ads | webhook + pull | Meta app review (owner) |
| TikTok / Snapchat / LinkedIn / Google Ads | provider adapters; awaiting credentials until the apps exist | developer apps (owner) |
| CSV | upload → mapping → preview → import | nothing |

Every source goes through the same ingestion: normalisation, deduplication (provider lead id, normalised phone, email),
suppression list, attribution, assignment, automation.

## Out of Mohtam
- **Outbound webhooks** (Integrations → Webhooks): signed `t=…,v1=HMAC`, retried 1m/5m/30m/2h/6h, paused after 15
  failures in a row, delivery log with resend. See `docs/API.md`.
- **Tracking destinations** (GA4, Meta CAPI, TikTok, signed webhook): the internal event is stored first; a destination
  failing never loses it.
- **Public API** with scoped, expiring, revocable keys. See `docs/API.md`.

## When something fails
1. Readiness shows the failing item and its last error (no secret is ever printed).
2. "Test connection" answers success / permission / authentication / network.
3. A token that cannot refresh marks the connection degraded and alerts the workspace admin.
4. Disconnect revokes locally and stops jobs; history (leads, events, deliveries) stays.
