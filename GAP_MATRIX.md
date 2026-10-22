# مهتم — gap matrix against the SaaS specification

Audited against the running code on `develop`, not against intent. This file was
rewritten on 2026-10-08: every P0 row and all but one P1 row said **Missing**
while the code had been built, which is worse than no document — it sends the
next person to build what already exists.

"Built" below means the behaviour is in the code **and** has tests that fail when
it is removed. Where something is partial, the row says what is absent.

## P0 — the lead moves on its own

| # | Feature | State | Where |
| --- | --- | --- | --- |
| 1 | Domain events | **Built** | `app/Events/*` — created, assigned, replied, stage changed, outcome recorded, qualified, won, lost. Registered by hand in `AppServiceProvider`; discovery is off, because both were on once and every rule ran twice |
| 2 | Automation engine | **Built** | `Domain/Automation/AutomationEngine` — trigger → condition → action, queued, with delayed actions via `ScheduledAction` |
| 3 | Rules builder | **Built** | Conditions and actions as data; templates, duplication, preview and a match-any/match-all toggle |
| 4 | SLA timers + escalation | **Built** | `Domain/Sla/SlaSweeper` — remind → escalate → reassign, counted in the workspace's own working hours (`BusinessHours`), and each step notifies a person |
| 5 | Lifecycle statuses | **Built** | Ten, including `replied`, `negotiation`, `invalid`, `no_response` |
| 6 | Deduplication | **Built** | `Domain/Leads/DuplicateMatcher` — provider lead id, then normalised phone, then email, strongest signal first and no fuzzy name matching |
| 7 | Assignment strategies | **Built** | `Services/Leads/LeadAssignmentService` — a narrowing chain: the project's team, then the language read off the number, then the city, then workload-per-weight or round robin. Away reps are skipped |
| 8 | Automation trace | **Built** | `AutomationRun` per decision with its reason; the lead timeline shows what a rule did and the insights screen shows what it did not |
| 9 | Async ingestion | **Partial, deliberately** | Imports, exports, WhatsApp retries and scheduled actions are queued. The webhook itself is **not**: it answers 422 with the reason a lead was rejected, which a queue cannot do, and providers use that answer. The cost is that a slow ingestion holds the request |

## P1 — the manager can see and steer

| # | Feature | State | Where |
| --- | --- | --- | --- |
| 10 | Appointments | **Built** | `Models/Appointment`, reminders on the follow-up sweep |
| 11 | Next best action | **Built** | `Domain/Leads/NextBestAction` — derived from stage, score, SLA and last contact |
| 12 | Sales workspace | **Built** | `اعمل الآن` — one lead, one action, one outcome, then the next |
| 13 | Offline conversions | **Built** | `Domain/Conversions` with per-provider adapters, hashed identifiers, queued export; sends nothing until a provider is configured |
| 14 | Cost per qualified lead / ROAS | **Built** | `CampaignSpend` entered by hand per campaign and day; a campaign nobody costed reports no cost rather than a cost of zero |
| 15 | Integration health | **Built** | Status, last error, last delivery and a test call per source; the daily digest names a source that has gone quiet |
| 16 | Realtime | **Built** | Private per-workspace and per-person channels over Reverb. Off unless configured, and the screens keep their timers either way |
| 17 | Data quality | **Built** | Invalid phones, unmapped campaigns, stale leads, duplicates |
| 18 | Command palette | **Built** | Global search and navigation |
| 19 | Onboarding | **Built** | `Domain/Onboarding` — a checklist and a traced test lead that says which step swallowed it |

## P2 — valuable, not load-bearing

Not started, and nothing above depends on them: AI assist, calendar sync,
further provider adapters, cohort and forecast analytics.

## Closed since this was written (2026-10-15)

| Feature | What was wrong | Where |
| --- | --- | --- |
| Files automation | The whole feature was unreachable. Six endpoints, a chooser that reads a lead's own answers, a per-lead link whose opens feed the engagement score, twenty-five passing backend tests — and no screen called any of it, so no workspace could upload a file for the chooser to choose. | `components/projects/ProjectFiles`, `components/leads/LeadFiles`, `features/files.ts` |
| A rule can contact the customer | `WhatsappSender` was reachable from one controller and `FileForTheLead` from one more, both of them a person pressing a button. The automation engine had eight actions and none of them could send anything, so "WhatsApp qualification sequences" and "files automation" existed as machinery and as nothing else. | `send_whatsapp` and `share_file` in `Domain/Automation/ActionExecutor` |

| Google Sheets could not be connected | `GoogleTokens` refreshed a token and nothing could ever *obtain* one — no authorize step, no callback. The only route to "connected" was pasting a refresh token into a field. | `Domain/Sheets/GoogleOAuth`, `GoogleOAuthController`, `/oauth/google/callback` |
| WhatsApp could only send plain text | Meta refuses free text outside a 24-hour window with code 131047, so every first message to a new lead — the case automation exists for — would have failed in production while passing every test against the mock. No templates, no attachments, and a retry degraded a template into text. | `WhatsappSender::sendSaved/sendDocument`, `SendSpec`, `WhatsappCloudProvider` |
| "Externally verified" could be faked by the mock | WhatsApp's evidence was any inbound event or any delivered message — which the mock and the E2E fixtures both write. | `IntegrationHealthReport::whatsapp` |
| Calls had a provider interface and no provider | The abstraction's own claim ("a provider can be connected later") had never been exercised. | `TwilioCallProvider`, `TwilioStatusController`, `CallSettingsController` |
| A project was two tabs | An overview of three fields and a repository view. | `ProjectDetail` — nine tabs, `ProjectActivityController` |

| Google needed two sensitive scopes | `spreadsheets.readonly` plus `drive.metadata.readonly` could see every sheet's name and read any sheet in the account — more reach than the product uses, and the reason the app was headed for Google's scope review. | `drive.file` only, with Google's picker: `lib/googlePicker`, `GoogleOAuthController::picker` |
| "Is it live" had no answer | Six things stand between a deployment and being live and each was visible somewhere different, or nowhere. | `Domain/Readiness`, `/readiness`, `leadflow:readiness`, `leadflow:heartbeat` |

The shape is the same one row 43 of `NEXT_SESSION.md` describes: a capability
built, tested, and called by nothing. It is worth checking for deliberately,
because every test passes the whole time.

## What is actually left

1. **A server.** Everything deploys; nothing is deployed. `EXTERNAL_SETUP.md`
   section 0 and 2.
2. **Row 9**, if a provider ever proves slow enough to matter. The fix is an
   accepted-then-processed handshake, which changes what the 422 means — not a
   change to make speculatively.
3. **P2.**
