# Requirements matrix — Master P0 directive (Nov 3, 2026)

The one list. Status is honest: VERIFIED means implemented, deployed and confirmed on the real production surface. Anything less says so.

| ID | Requirement | Backend | Frontend | Tests | Status | Remaining gap |
| --- | --- | --- | --- | --- | --- | --- |
| §4–7 | Workspace and project isolation | Rounds 1–4 of audits; scopes on reservations, units, links, forms; imports under uploader scope; duplicates, spend, integrations closed | Pages hidden by server | `IsolationRound4Test`, `SecurityRound3Test`, `AuthHardeningTest` | IMPLEMENTED_NOT_VERIFIED | Not a formal proof: the last audit read the code it names |
| §8–10 | One login, server-decided destination | `Destination`, `/auth/login` platform handoff | `Login.tsx`, `homeFor` | `WorkspaceGovernanceTest` | IMPLEMENTED_NOT_VERIFIED | `/platform/login` stays as a technical route |
| §11 | Google login | `GoogleLoginController`, `GoogleLoginProbe`, the client's registered callback | Login button | `GoogleLoginTest` | IMPLEMENTED_NOT_VERIFIED | Offered on production and Google accepts the callback. No real person has signed in yet |
| §12–16 | Owner governance | `WorkspaceModules`, `disabled_modules`, per-person module control on the user rule layer, custom roles in the matrix, all audited | Modules, matrix with custom roles, per-person dialog, audit diff | `WorkspaceGovernanceTest` | IMPLEMENTED_NOT_VERIFIED | Needs the owner's console session on production. Only whole-screen modules can be taken from one person: the others borrow a permission another screen needs (exports is `leads.view`) |
| §17–23 | Mobile navigation | | Tabs by role, full-screen More, project sheet, full-screen notifications grouped urgent, today, earlier | `mobile.spec.ts`, `notifications.spec.ts` | IMPLEMENTED_NOT_VERIFIED | Needs a signed-in session on production |
| §24–25 | Inputs and login | | `.field`, AuthShell, login 52px, every field 52px with 16px text on phones | Vitest, `public.spec.ts`, `mobile.spec.ts` | IMPLEMENTED_NOT_VERIFIED | Dense desktop screens stay at 44px on purpose: filters and table tools sit beside 44px buttons |
| §26–31 | Marketing | `PublicContactController` | `/insights`, contact form | `LandingAndContactTest` | BLOCKED_EXTERNAL | Pages live and fit 430px. The form stays hidden until `MOHTAM_MARKETING_WORKSPACE` is set on the server. No prerender |
| §32–39 | Landing pages | Sections, gallery, submissions, lead count per person | Builder panel, restore, delete; list shows status, leads, copy link, open page; §66 phone edits text and settings only | `LandingAndContactTest`, `mobile.spec.ts`, `form-builder.spec.ts` | BLOCKED_OPERATIONAL_EVIDENCE | Needs a real published page and a real submission in production |
| §40–48 | Live and snapshot reports | Pause, resume, duplicate, archive, snapshot, full funnel | Share tab, project tab makes a report from a preset | `ReportLifecycleTest` | BLOCKED_OPERATIONAL_EVIDENCE | Needs a real report in production |
| §49–56 | Notifications, email, automation | Templates, priorities, triggers, actions, idempotency | Prefs, rule stats | `NotificationsAutomationLiveTest` | IMPLEMENTED_NOT_VERIFIED | Master asks for neither a template editor nor web push as P0; left out. Webhook and sheet actions: not built, re-evaluate on demand |
| §52 | Delivery truth | Accepted, delivered, bounced from provider events; failure reasons in the deploy log | Delivery panel | `LandingAndContactTest`, `ProductionReadinessTest` | BLOCKED_OPERATIONAL_EVIDENCE | SMTP accepted 21 in 7 days; no message checked in an inbox. Hostinger SMTP has no delivery webhook, so accepted is as far as the app can see. 23 of 24 failures are `554 5.7.1` on the recipient domain, and which addresses is not yet known |
| §56 | Sheets production flow | Existing pipeline, on the heavy worker | | `ASheetRowBecomesRevenueTest` | IMPLEMENTED_NOT_VERIFIED | Sheet to lead proven in production: 2 sources, 51 runs with rows, 430 leads. Assignment, notification, Work Now and SLA on a real row need a signed-in look |
| §57–59 | Performance | Heavy worker for imports; `leadflow:measure` reports p50 and p95 | Last updated on Dashboard, Work Now, Leads, Automation, Reports and Project | `docs/PERFORMANCE.md` | PARTIAL | p50 and p95 measured on 10,043 leads on a laptop (`docs/PERFORMANCE.md`), not on the production host |
| §60 | Readiness center | Mail, Google login, engine, Landing and Reports rows on real evidence; Backups under infrastructure | Live, Partial, Awaiting external or Error, never above the ladder | `ProductionReadinessTest`, `readiness.spec.ts` | IMPLEMENTED_NOT_VERIFIED | Needs the owner's console session on production |
| §70–73 | Analytics and automation impact | Estimated manual actions from the action log, auto mode and carried out only, weights in `platform.manual_action_minutes` | Labelled an estimate on Automation | `EstimatedManualActionsTest` | IMPLEMENTED_NOT_VERIFIED | Weights are a stated assumption, not a measurement |

## Production evidence — v0.9.2, Nov 4, 2026

Live commit `84e4c32`, from the deploy log and read-only checks on `https://mohtam.pro`.

| Check | Result |
| --- | --- |
| Backup | Taken before the deploy; `RESTORE CHECK: PASS`, 132 of 132 migrations in a throw-away database |
| Migrations | v0.9.1 applied 4 additive ones; v0.9.2 had none |
| Smoke | 9 of 9 over HTTPS; `FIRST_CLIENT_SMOKE=PASS`, 16 steps, rolled back |
| Health | Healthy, realtime ok |
| Public pages | `/`, `/login`, `/about`, `/contact`, `/security`, `/privacy`, `/terms`, `/data-deletion`, `/insights`, `/features` answer 200 |
| Phones | Login at 390px: fields 52px with 16px text, no sideways scroll. `/` and `/insights` at 430px: no sideways scroll |
| No public sign-up | `/api/v1/auth/register` 404; anonymous `/api/v1/leads` 401 |
| Mail DNS | SPF, DKIM (`hostingermail-a`), DMARC `p=none` and MX all published |

## Production evidence — v0.9.3, Nov 4, 2026

Live commit `c2441b4`, from the deploy log and read-only checks.

| Check | Result |
| --- | --- |
| Queue | No failed job in the last 24 hours; `automation` reads ready. The 21 seen earlier aged out of that window, so this does not prove the commit-timing diagnosis: it was inferred from the error and the code. Watch the deploy log |
| Google sign-in | Offered. Google accepts the callback with no sign-in; the pass-through answers 302 to the login handler. A real sign-in is still unproven |
| Sheets | 2 sources, 51 completed runs with rows, 430 leads from sheets, counted on the server |
| Email | 21 sent, 24 failed and 820 logged in 7 days. Sending happens, and the failures are not yet explained |

What these do not prove: a signed-in session, a delivered email in an inbox, a real sheet row becoming a lead, or a real published page taking a submission.

## Production evidence — v0.9.4, Nov 4, 2026

Live commit `60bebc2`. Backup taken, `RESTORE CHECK: PASS`, 9 of 9 over HTTPS, `FIRST_CLIENT_SMOKE=PASS`, no migrations pending, no failed job in the last 24 hours.

Readiness: `vps` and `google` live verified; `mail`, `google_login`, `automation`, `reports` ready; `domain` ready, waiting for a request in the console; `landing` waiting for a first published page.

Nothing in this file is marked VERIFIED for a screen behind sign-in: no session was available, and none was asked for.

