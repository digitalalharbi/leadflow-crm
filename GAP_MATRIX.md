# مهتم — gap matrix against the SaaS specification

Audited against the running code on `develop`, not against intent. "Partial"
means the seam exists and the behaviour does not.

## What already holds up

These are built, tested, and need no rework — the brief's foundations are met.

| Area | Where |
| --- | --- |
| Provider adapters behind one port | `Services/Integrations/Providers/*` — Meta, TikTok, Snapchat, Google Ads, LinkedIn, Sheets, CSV, webhook, manual |
| Ingestion pipeline: normalise → validate → dedup → create/re-engage | `Services/Leads/LeadIngestionService` |
| Phone normalisation for every Saudi shape | `Services/Leads/PhoneNormalizer` |
| Attribution touchpoints kept per arrival | `Models/LeadSourceEvent` |
| Scoring with tenant-configurable thresholds | `Services/Leads/LeadScoringService` |
| Tenant isolation that fails closed | `Scopes/TenantScope`, `CrossTenantInjectionTest` |
| Audit log | `Models/AuditLog`, `Services/AuditLogger` |
| WhatsApp send/receive, templates, delivery status | `Services/Whatsapp/*` |
| Queues, Horizon, scheduler, health, rollback | compose + `scripts/deploy-*` |

## P0 — the lead never moves on its own

The brief's core complaint: a rep must do everything by hand. Everything in
this block is what stops that.

| # | Feature | State | Why it matters | Action |
| --- | --- | --- | --- | --- |
| 1 | **Domain events** | **Missing** — `app/Events` and `app/Listeners` are empty | Nothing can react to anything. Every automation below depends on this | Emit `LeadCreated`, `LeadAssigned`, `LeadReplied`, `LeadStageChanged`, `LeadQualified`, `LeadWon`, `LeadLost`, `FollowUpDue` |
| 2 | **Automation engine** | **Missing** | The product's whole premise. No trigger → condition → action → delay | Rules stored per tenant, executed on events, queued |
| 3 | **Rules engine / builder** | **Missing** | Every rule change is a deploy today | Conditions and actions as data, evaluated at runtime |
| 4 | **SLA timers + escalation** | **Missing** | A lead nobody touches stays untouched forever | Timers per stage, reminder → escalate → reassign |
| 5 | **Lifecycle statuses** | **Partial** — 6 of 10 | No `replied`, `negotiation`, `invalid`, `no_response`, so those outcomes have nowhere to go | Extend the enum and the board |
| 6 | **Deduplication** | **Partial** — normalised phone only | Same person from Meta then TikTok becomes two leads if the phone differs by a digit | Add email, provider lead id, external id, WhatsApp number |
| 7 | **Assignment strategies** | **Partial** — manual + round robin | Cannot route by project, campaign, city, language, workload, score or working hours | Strategy per rule, with workload and availability |
| 8 | **Automation trace** | **Missing** | "Why was this assigned?" has no answer | Record every decision with its reason |
| 9 | **Async ingestion** | **Partial** — one job exists | Webhook processing is synchronous; a slow provider blocks the request | Queue ingestion, add retry and dead-letter |

## P1 — the manager cannot see or steer

| # | Feature | State | Action |
| --- | --- | --- | --- |
| 10 | Appointments module | **Missing** | Model, reminders, calendar-ready |
| 11 | Next Best Action | **Missing** | Derived per lead from stage, score, SLA, last contact |
| 12 | Sales workspace + "Next Lead" | **Missing** | One screen, one button |
| 13 | Offline conversions | **Missing** | Architecture + queue, no sending without configuration |
| 14 | Cost per qualified lead / ROAS | **Partial** — counts, no spend | Needs ad-spend ingestion per provider |
| 15 | Integration health centre | **Partial** — status exists | Token expiry, rate limit, last error, reconnect, test |
| 16 | Realtime | **Missing** | Broadcasting for new lead, reply, assignment |
| 17 | Data quality dashboard | **Missing** | Invalid phones, unmapped campaigns, stale leads |
| 18 | Global search / command palette | **Missing** | |
| 19 | Onboarding wizard + test lead | **Partial** — checklist only | Ten steps, then a traced test lead |

## P2 — valuable, not load-bearing

AI assist, calendar sync, extended providers, advanced analytics.

## Order of execution

Events first, because items 2, 3, 4, 8 and 16 all hang off them. Then the
automation engine, then SLA, then the statuses and dedup that give automation
something to decide about.
