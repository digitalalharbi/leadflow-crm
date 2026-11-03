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


## Form builder V2

The landing form is no longer a list of fields: it is **sections → rows → columns → fields**, built on the screen (`/tracking/forms/:id`), with
the same `FormSchema` underneath — the flat field list is still the contract of what may be asked and where each answer lands; the layout only
places the fields, so a form can be drawn a hundred ways without changing what a submission means.

| Part | Where | What it is |
|---|---|---|
| Layout | `FormLayout` | Up to 10 sections, 30 rows each, 1–4 columns per row. Every field is placed exactly once; a field nobody placed is asked at the end, never lost. A form without a layout gets one from its steps. |
| Field types | `FormSchema::TYPES` | text, phone, email, number, textarea, select, multi select, radio, checkbox, date, time, hidden, heading, paragraph, divider, consent, **matrix**, **repeater**. Display types ask nothing and cannot be mapped or required. |
| Matrix | `field.matrix` | Rows × columns; one answer per row, or several; "every row" or "at least N". Saved as structured JSON (`{row: column}`), read as text on the lead (`الموقع: مهم جدًا`), aggregated on the form's analytics. |
| Repeater | `field.repeater` | A list of rows with their own columns (name, phone, relation…); min / max / default rows; each cell is checked like any field and a refusal names the row and the column (`guests.1.mobile`). |
| Conditions | `Logic`, `lib/formLogic.ts` | `show_when`, `hide_when`, `require_when` on a field, a row or a section; `equals, not_equals, contains, gt, gte, lt, lte, empty, not_empty`; AND / OR. The server and the page run **the same vectors** (`frontend/src/features/formVectors.json`), so what the page shows the server accepts. The server re-runs every condition: an answer to a hidden question is dropped, and a hidden question cannot be demanded. |
| Phone | `PhoneCountries` | A country selector (the workspace's default, Saudi by default); the number leaves as one international number, and the number as typed is kept beside it (`raw.typed`). |
| Validation | per field | required, min / max, length, pattern, and the manager's own message; errors sit beside the field and nothing typed is lost. |
| Steps | `settings.mode = multi` | Each section is a step, with a progress bar, back / next, and the visitor's place kept in their own browser. `form_start` and `form_step` are recorded; the lead is made only on submit. |
| Header and look | `FormDesign` | Five header layouts, a logo and a hero image (uploaded: PNG / JPEG / WebP ≤ 1.5 MB, checked by content, served by us with `nosniff`; **no SVG**), and a look chosen from **closed lists** (colour tokens, radius, spacing, field size, theme). There is no free CSS, no HTML, no colour string: the page maps each token to a value it owns. |
| After sending | `settings.thanks` | The thanks, up to four actions (WhatsApp, call, appointment, project, brochure) and an optional redirect after a delay. |
| Templates | `FormTemplates` | Real estate interest, property visit, reservation interest, investment qualification, broker lead, general contact — each a whole form that already works. A form can also be copied. |
| Intent signals | `settings.signals`, `FormSignals` | Rules a workspace writes ("budget ≥ 2M", "buying within 3 months", "cash") each worth points, up to 30 together; kept as one fact on the lead, so the interest score says *why* (`form_intent`). Never shown to the visitor. |

### Draft, publish, versions

A form row is the **draft** a manager edits. **Publish** freezes it as a version (`landing_form_versions`, with a checksum) and points the form
at it; the public page serves the published version, so editing the draft never reaches a campaign that is already running. Publishing an unchanged
draft makes no new version. A submission remembers the version its visitor saw (`form_version_id` on the trail and on the source event), so an old
lead can always be explained. A form published before versions existed gets its first version the first time it is shown. `variant` is on every
version so an A/B test can later publish two live versions of one form without a schema change.

### Completion events

`dataLayer` hears `form_view`, `form_start`, `form_step`, `form_submit` (the button pressed and the answers passing the page's checks) and
`form_success` — **only after the server has kept the lead**, under the same `event_id` the server recorded the submission with and the browser pixels
use, so a platform counts it once. What is pushed is ids and campaign names (`tracking_link_id`, `project_id`, `form_id`, `form_version`, source,
medium, campaign) — never a name, a number or an address. The internal event store stays the source of truth.

### Analytics per form

`GET /tracking/forms/{id}/analytics`: views, starts, sent, completion and abandonment rates, average completion time, a step-by-step funnel with
drop-off, where people stopped (a step and a **field key** — a beacon cannot carry what was typed), matrix answers added up across everybody, and
submissions per version.

### Security

Public requests have an allowance of their own per kind, per visitor and per page (views, step reports, submit): a shared counter would let a busy
mobile network lock its customers out of the one button that matters. A honeypot, an idempotent submission id and consent are as before; every
manager text is plain text; tenant isolation is checked on every new endpoint.

## Project landing pages and lead sources

A landing page is a `LandingForm` with `page` set, not a second system. It holds a global `slug` from the moment it is created, draft or not (`landing_slug_claims` has every address a page ever held, and its unique index is what decides a race). Publishing gives it a `default_link_id`, so `/l/<slug>` resolves to a tracking link and the visit, submission, beacons, sessions, analytics and destinations run on the path every link uses. Tokens resolve first; a page's old slugs stay reserved and keep opening it. A page opened from outside runs from `src/landing/main.tsx`: its own entry, stylesheet and dictionary (`src/i18n/publicPage.*.ts`), and no app router, query client, API client or dashboard code. New words a public page shows go in those dictionary files.

- **Config:** `LandingPageConfig`, a closed list, frozen in each published version.
- **Lead:** `leads.landing_form_id` keeps the first page that brought it.
- **Beacons** always post the link's token, never the slug.
- **WhatsApp:** the page writes `Ref: XXXXXX`, derived from the session. A click keeps a reference only if it is that session's own; the first inbound message carrying one makes the lead (`WhatsappAttribution`) on pages in `inbound` mode.
- **Platform forms:** `lead_form_mappings` (project + field map), applied inside `LeadIngestionService`.
- **Sources screen:** `ProjectLeadSources`, read from the same leads as the pipeline.
- **Conversion names:** `tracking_destinations.event_map`, only for events the server witnessed (`DestinationKind::MAPPABLE_EVENTS`).
