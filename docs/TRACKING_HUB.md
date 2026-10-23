# Tracking Hub

From the first click to the riyal, in one place. A customer who sees an ad, scans a code or follows a broker's link is followed
through the landing page, the form, the lead, the automation, the sales team, the appointment, the reservation and the sale — and
the result is handed back to the platform that paid for the click.

Nothing here is a parallel system. The Hub extends the tracking that already existed — `/t/{token}`, the beacon, `tracking_events`,
attribution, the offline-conversion pipeline — and every number on its screens is one the rest of the product already uses.

## The path

```
Ad / social / partner / QR
  → tracking link  /t/{token}                (records the click, the session, the click ids; sets mt_vid / mt_sid)
    → redirect to an address        or        landing page  /l/{token}   (a form we host)
      → form_started · form_step · form_submitted        (browser beacon + the submit itself)
        → LandingSubmission: validate → event → normalise → dedupe → attribution → project/unit → lead
          → the existing lead pipeline: automation, WhatsApp, email fallback, brochure, qualification, SLA, salesperson alert
            → qualified · interested · appointment · visit · reservation · won (+ revenue)
              → destinations: GA4, Google Ads, Meta, TikTok, Snapchat, LinkedIn, X, GTM web/server, webhook
```

## Pieces

| Piece | Where | What it is |
|---|---|---|
| Tracking link | `tracking_links`, `TrackingLinkController` | Project, unit, campaign, ad, creative, partner, platform, UTMs, custom parameters, `mode` (`redirect` / `landing`), `landing_form_id`. |
| Visitor session | `visitor_sessions`, `Domain/Tracking/Sessions` | First-party visitor (`mt_vid`) and session (`mt_sid`) ids; first and last touch (UTMs, click ids, referrer, landing url), device, consent. |
| Event store | `tracking_events` (extended) | One store. `event_id` doubles as the idempotency key (`evt:{id}`); a replay is the same event. Canonical names: `link_clicked`, `landing_viewed`, `form_started`, `form_step_completed`, `form_submitted`, `lead_created`, `qualified`, `interested`, `appointment_requested`, `appointment_booked`, `visit_completed`, `reservation_created`, `won`, `lost`, `revenue_recorded`, `whatsapp_started`, `call_started`, `brochure_opened`, `project_viewed`, `property_viewed`. |
| Click ids | `Domain/Tracking/ClickIds` | `gclid`, `gbraid`, `wbraid`, `fbclid`, `ttclid`, `sccid`, `li_fat_id`, `twclid`, `msclkid` — read from the visit, kept on the session and the lead, first and last touch. |
| Smart form | `landing_forms`, `Domain/Tracking/Forms/*` | A JSON list of fields; the list is the contract. 11 field types, required / hidden / default / order / step, validation, conditional visibility, mapping to a lead field, a custom field or tracking metadata. The raw payload is kept. A form that cannot reach the customer (no phone or email mapped) is refused when it is saved. |
| Landing page | `PublicLandingController`, `/l/:token` (`pages/public/LeadPage.tsx`) | Right to left, mobile first, one column. The view is recorded by the server before the page is sent; the page only reports the form events. |
| Link analytics | `Domain/Tracking/LinkAnalytics` | 14 counts, 11 rates, the nine-step funnel with drop-off, a daily trend. Funnel steps after the form come from `LeadFunnel` — the same cumulative funnel every other screen uses. |
| Link health | `Domain/Tracking/LinkHealth` | `healthy` / `warning` / `critical`, check by check: recent activity, UTMs, ad click id, page tag, server events, duplicates, leads without a trail. |
| Project Acquisition | `ProjectAcquisitionController`, project tab | Links, forms, funnel, sources, campaigns, recent events, first/last-touch attribution, diagnostics. |
| Destinations | `tracking_destinations`, `Domain/Tracking/Destinations/*` | Configuration and readiness of each platform; nothing is sent until one is switched on. |
| Outbox | `destination_deliveries`, `Jobs/SendDestinationEvent` | One row per (destination, event). Queued, retried with backoff, never in the request that made the lead. |

## Hybrid tracking: the browser and the server say it once

A conversion reaches a platform twice — from the visitor's browser and from our server — and the platform must count it once.
The submission id is the shared `event_id`: the server records `form_submitted` under it, and the page gives the same id to its
own pixel (`fbq('track','Lead',{}, {eventID})`, TikTok `event_id`, GA4 `event_id`, `dataLayer`).

Which copy goes where is decided without any network call and written on the delivery, so "why was this not sent?" always has an
answer: `not_allowed`, `consent_missing`, `consent_denied`, `browser_covers`, `via_conversions`, `awaiting_credentials`.

- GA4 (server): the later stages only. Meta and TikTok (server): `form_submitted`, deduplicated against the pixel by `event_id`.
- Google Ads, Snapchat, LinkedIn, X: the later stages go through the existing **offline-conversion** pipeline (`ConversionRecorder`,
  `ExporterRegistry`) — not a second sender, so nothing is sent twice.
- GTM server and the webhook: signed `X-Webhook-Signature: sha256=HMAC(raw body)`.
- Hashed email and phone, and click ids, are sent only with the visitor's consent and only if `hash_identifiers` is on.

## GTM

The page keeps a plain `dataLayer` array and pushes `landing_viewed`, `form_started` and `form_submitted` with their `event_id`.
A GTM web container, and the page tags of the other platforms, load **only after the visitor has agreed** — from an allowlist of
hosts, with each identifier checked for its shape (`lib/landingTags.ts`). Server-side GTM is a destination, not a replacement.

## Privacy

- Consent is asked on the form (`consent_required`, wording and privacy address are the workspace's). The destination's
  `consent_mode` is `required` (default) or `unless_denied`.
- Only an allowlist of events may leave, per destination. Identifiers are hashed (SHA-256) before they leave. Credentials are
  encrypted at rest, never returned by the API (only *which* are set) and never written to the audit trail.
- A destination endpoint must be `https`, a hostname (not an IP) and not an internal name (`SafeUrl`).
- Retention (`leadflow:prune-payloads`, daily): delivery logs after `RETENTION_DELIVERY_DAYS` (90); visitor sessions lose the address
  hash, browser, OS and click ids after `RETENTION_SESSION_DAYS` (365) — the row and *which source* stay, because a lead's credit
  rests on them. Raw submissions follow the existing payload retention.
- The visitor and session cookies (`mt_vid`, `mt_sid`) are first-party and not encrypted by Laravel — they are ids, not secrets.

## Security and tenancy

- Every model is tenant-scoped; the public token resolves the workspace and nothing else. A missing, expired, unpublished, draft,
  archived or foreign page is one answer — `404` — so a token cannot be probed.
- A link may name only this workspace's project, unit, campaign, ad, creative, partner, form and salesperson (the user lookup is
  scoped to the workspace, because `User` is the one model no global scope guards).
- Reading is for `tracking.view`; making forms, links and destinations is a manager's call (`TrackingLinkPolicy`,
  `LandingFormPolicy`, `TrackingDestinationPolicy`). Money appears only with `revenue.view`. A project manager sees only their projects.
- The public submit is throttled, honeypot-guarded, idempotent on the submission id, and validates against the form's own contract —
  an answer to a field the form does not have is dropped, a hidden field's value is the server's, never the browser's.
- A failure of any destination never loses the lead: the lead is created first, the sends are queued.

## What needs external setup

Nothing here sends to a platform without credentials, and nothing pretends to. Each destination shows its readiness:

| Readiness | Meaning |
|---|---|
| `not_configured` | The public identifier is missing or malformed. |
| `browser_only` | The page tag is set; the server copy needs its credential (GA4 API secret, Meta / TikTok access token). |
| `awaiting_credentials` | Server-only destination without its credential. |
| `ready` | Everything needed is present. |

Meta Conversions API, TikTok Events API, GA4 Measurement Protocol and the offline-conversion exporters need the workspace's own
credentials; Meta-approved WhatsApp templates are needed for messages outside the 24-hour window.
