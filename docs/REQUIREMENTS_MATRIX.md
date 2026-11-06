# Requirements matrix — Master P0 directive (Nov 3, 2026)

The one list. Status is honest: VERIFIED means implemented, deployed and confirmed on the real production surface. Anything less says so.

| ID | Requirement | Backend | Frontend | Tests | Status | Remaining gap |
| --- | --- | --- | --- | --- | --- | --- |
| §4–7 | Workspace and project isolation | Rounds 1–4 of audits; scopes on reservations, units, links, forms; imports under uploader scope; duplicates, spend, integrations closed | Pages hidden by server | `IsolationRound4Test`, `SecurityRound3Test`, `AuthHardeningTest` | IMPLEMENTED_NOT_VERIFIED | Not a formal proof: the last audit read the code it names |
| §8–10 | One login, server-decided destination | `Destination`, `/auth/login` platform handoff | `Login.tsx`, `homeFor` | `WorkspaceGovernanceTest` | IMPLEMENTED_NOT_VERIFIED | `/platform/login` stays as a technical route |
| §11 | Google login | `GoogleLoginController`, `GoogleLoginProbe`, the client's registered callback | Login button | `GoogleLoginTest` | IMPLEMENTED_NOT_VERIFIED | Offered on production and Google accepts the callback. No real person has signed in yet |
| §12–16 | Owner governance | `WorkspaceModules`, `disabled_modules`, per-person module control on the user rule layer, custom roles in the matrix, all audited | Modules, matrix with custom roles, per-person dialog, audit diff | `WorkspaceGovernanceTest` | IMPLEMENTED_NOT_VERIFIED | Needs the owner's console session on production. Only whole-screen modules can be taken from one person: the others borrow a permission another screen needs (exports is `leads.view`) |
| §17–23 | Mobile navigation | | Tabs by role, full-screen More, project sheet, full-screen notifications grouped urgent, today, earlier | `mobile.spec.ts`, `notifications.spec.ts` | PARTIAL | VERIFIED in production: notification grouping, signed in on desktop. Not verified: the phone layout signed in |
| §24–25 | Inputs and login | | `.field`, AuthShell, login 52px, every field 52px with 16px text on phones | Vitest, `public.spec.ts`, `mobile.spec.ts` | IMPLEMENTED_NOT_VERIFIED | Dense desktop screens stay at 44px on purpose: filters and table tools sit beside 44px buttons |
| §26–31 | Marketing | `PublicContactController` | `/insights`, contact form | `LandingAndContactTest` | BLOCKED_EXTERNAL | Pages live and fit 430px. The form stays hidden until `MOHTAM_MARKETING_WORKSPACE` is set on the server. No prerender |
| §32–39 | Landing pages | Sections, gallery, submissions, lead count per person | Builder panel, restore, delete; list shows status, leads, copy link, open page; §66 phone edits text and settings only | `LandingAndContactTest`, `mobile.spec.ts`, `form-builder.spec.ts` | VERIFIED, one sub-path open | VERIFIED in production Nov 5: a page published, a visitor's submission, the right project, the lead, UTM source and campaign, the same phone written two ways joining one lead, the manager alert. Open: automatic assignment, because that workspace assigns by hand |
| §40–48 | Live and snapshot reports | Pause, resume, duplicate, archive, snapshot, full funnel | Share tab, project tab makes a report from a preset | `ReportLifecycleTest` | PARTIAL | VERIFIED in production: a live report read through its public link. Not verified: snapshot, pause, resume, duplicate, which need an account with a confirmed email |
| §49–56 | Notifications, email, automation | Templates, priorities, triggers, actions, idempotency | Prefs, rule stats | `NotificationsAutomationLiveTest` | IMPLEMENTED_NOT_VERIFIED | Master asks for neither a template editor nor web push as P0; left out. Webhook and sheet actions: not built, re-evaluate on demand |
| §52 | Delivery truth | Accepted, delivered, bounced from provider events; failure reasons in the deploy log; reserved test addresses written down, not sent | Delivery panel | `LandingAndContactTest`, `ProductionReadinessTest` | PARTIAL | EMAIL INBOX DELIVERY: VERIFIED for `info@mohtam.pro`, with SPF, DKIM and DMARC passing. Not verified: Gmail and Outlook inboxes. No real-domain recipient was rejected by the reserved-domain rule; one real-domain send was affected by the isolated SMTP authentication failure of Nov 3 |
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

## Production acceptance — v0.9.5, Nov 5, 2026

Live commit `63b7e41`. Done in a signed-in production session as the admin of the demo workspace `mohtam-demo`, with test records labelled as tests. That account's address is on a reserved `.test` domain and cannot be confirmed.

| Item | Result | Evidence |
| --- | --- | --- |
| Signed-in portal | PASS on desktop | Dashboard, Work Now, Leads, Automation, Reports, Readiness, Audit and Project open with no error; `/platform` sends a company admin home; notifications show urgent, today, earlier |
| Landing live | PASS | Published `/l/acceptance-test-20261105`, opened it as a visitor, then paused it: the address answers 404 again |
| Landing to lead | PASS | One submission became one lead in the right project, platform `landing_page`, source and campaign from the UTM tags, a "new lead with no owner" alert, journey entries, and an audit entry. A second submission with the phone written differently joined the same lead |
| Assignment | By configuration | The workspace assigns by hand, so the lead stayed unassigned and managers were alerted. Automatic assignment was not exercised |
| Live report | PASS, read only | An existing live link answers with a funnel of 216 leads |
| Snapshot and report lifecycle | NOT VERIFIED | The server refused with "Confirm your email first": correct for an unproven address, so it needs an account with a confirmed one |
| Email provider acceptance | PASS | 65 sent in 7 days |
| Email inbox delivery | PASS for `info@mohtam.pro` | A sales email from the product arrived in the inbox with SPF, DKIM and DMARC all passing. Gmail and Outlook were not tested |
| Email failures | Classified | 74 of 75 went to demo accounts on a reserved test domain, refused with "reserved for documentation". The other one is a single login failure on Nov 3 during setup, with 65 sent since. No real recipient was refused |
| Phones, signed in | NOT VERIFIED | Chrome would not narrow below desktop width, and the session could not be moved to an emulator |
| Google sign-in | NOT VERIFIED | Needs the owner's own Google account |
| Sheet row to sales flow | NOT VERIFIED | Sheets keep importing (475 leads, 68 runs with rows), but the sheets belong to the other workspace |
| Marketing form | OWNER DECISION | Two active workspaces; the one named like Mohtam is the demo |
| Off-server backup | NOT CONFIGURED | The deploy log has no off-server copy step |

Defects this found, fixed in v0.9.5: a sales email had no text direction, a project tab showed `projects.tabs.sources`, and mail to reserved test domains was sent and counted as failed.

## Production closure — v0.9.6, Nov 5 to 6, 2026

Live commit `cd8c3a5` (deploy runs 42117383654 and 42120414586: backup, restore check, first-client smoke and 9 of 9 smoke checks passed).

| Item | Result | Evidence |
| --- | --- | --- |
| Mail From and Reply-To | VERIFIED | A sales email sent from production on Nov 5 22:19 UTC carries From `info@mohtam.pro` and Reply-To `info@mohtam.pro`. The display name on an email to a lead is the workspace's own name; the name `مهتم — Mohtam` belongs to platform mail and was not observed on a message |
| SPF, DKIM, DMARC | VERIFIED | Same message: `dkim=pass` (selector `hostingermail-a`), `spf=pass`, `dmarc=pass` at the receiving server |
| Inbox placement of that message | Junk | It was sent from `info@mohtam.pro` to `info@mohtam.pro` and the filter marked it as spam. The v0.9.5 message from `no-reply@` to the same mailbox reached the inbox. Placement at Gmail and Outlook is not tested |
| Credential on line 131 | IDENTIFIED: `MAIL_PASSWORD` | `env_audit line=131 key=MAIL_PASSWORD`, file last modified Nov 3 16:43 UTC, no credential holds a space. Rotation is the owner's, at Hostinger Email |
| Phones, signed in | TOOL LIMITATION | The signed-in Chrome window is full screen and cannot be narrowed; the session cannot be moved to an emulator without the password. Phone layouts are covered by the e2e suite on three mobile projects, not by a production session |
| Workspace and project isolation | PARTIAL | Project narrowing is enforced by the server (256 leads to 1 with a project header), the platform console and super-admin routes answer 404 to a company admin, unknown ids answer 404. A second, project-limited identity was not available |
| Sheet row to sales flow | OBSERVED on a real row | A row added to the demo workspace's sheet on Nov 5 12:57 UTC became a lead with a new-lead notification, an automation attempt handed to a person, and a place in Work Now. It has no project and no owner, by that workspace's configuration. No test row was written: there is no authorised write path to the sheet |
| Snapshot | NOT VERIFIED | Needs an account with a confirmed address |
| Queued email | DEFECT, FIXED and VERIFIED live | `SendQueuedEmail / MaxAttemptsExceededException` three times and three deliveries left as `sending`. A send interrupted by a stopped worker was never looked at again. Now recovered every ten minutes and counted as a failed try. Released in v0.9.7 and proven live on Nov 6: `sending=3 sent=99` before the first sweep, `sent=102` and none sending after |
| Bounces | OBSERVED | Gmail returns mail for one nonexistent user address in the other workspace; the product records those as sent, because the provider accepted them |
