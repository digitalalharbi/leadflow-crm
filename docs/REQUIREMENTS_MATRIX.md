# Requirements matrix — Master P0 directive (Nov 3, 2026)

The one list. Status is honest: VERIFIED means implemented, deployed and confirmed on the real production surface. Anything less says so.

| ID | Requirement | Backend | Frontend | Tests | Status | Remaining gap |
| --- | --- | --- | --- | --- | --- | --- |
| §4–7 | Workspace and project isolation | Rounds 1–4 of audits; scopes on reservations, units, links, forms; imports under uploader scope; duplicates, spend, integrations closed | Pages hidden by server | `IsolationRound4Test`, `SecurityRound3Test`, `AuthHardeningTest` | IMPLEMENTED_NOT_VERIFIED | Not a formal proof: the last audit read the code it names |
| §8–10 | One login, server-decided destination | `Destination`, `/auth/login` platform handoff | `Login.tsx`, `homeFor` | `WorkspaceGovernanceTest` | IMPLEMENTED_NOT_VERIFIED | `/platform/login` stays as a technical route |
| §11 | Google login | `GoogleLoginController` | Login button | `GoogleLoginTest` | BLOCKED_OPERATIONAL_EVIDENCE | Needs credentials on the server and a real sign-in |
| §12–16 | Owner governance | `WorkspaceModules`, `disabled_modules`, audited | Modules, matrix, audit diff | `WorkspaceGovernanceTest` | IMPLEMENTED_NOT_VERIFIED | Per-person override from the console; custom roles not in the matrix |
| §17–23 | Mobile navigation | | Tabs by role, full-screen More, project sheet, notifications | `mobile.spec.ts` | IMPLEMENTED_NOT_VERIFIED | Notifications not grouped by urgency or date |
| §24–25 | Inputs and login | | `.field`, AuthShell | Vitest, `public.spec.ts` | IMPLEMENTED_NOT_VERIFIED | General fields are 44px; login fields 52px |
| §26–31 | Marketing | `PublicContactController` | `/insights`, contact form | `LandingAndContactTest` | IMPLEMENTED_NOT_VERIFIED | Needs `MOHTAM_MARKETING_WORKSPACE`; no prerender |
| §32–39 | Landing pages | Sections, gallery, submissions | Builder panel, restore, delete | `LandingAndContactTest` | BLOCKED_OPERATIONAL_EVIDENCE | Needs a real published page; builder is not phone-adapted |
| §40–48 | Live and snapshot reports | Pause, resume, duplicate, archive, snapshot | Share tab | `ReportLifecycleTest` | BLOCKED_OPERATIONAL_EVIDENCE | Needs a real report; funnel has no Contacted, Replied or Negotiation stages; no project Reports area |
| §49–56 | Notifications, email, automation | Templates, priorities, triggers, actions, idempotency | Prefs, rule stats | `NotificationsAutomationLiveTest` | IMPLEMENTED_NOT_VERIFIED | No template editor; no web push; webhook and sheet actions missing |
| §52 | Delivery truth | Accepted, delivered, bounced from provider events | Delivery panel | `LandingAndContactTest` | BLOCKED_OPERATIONAL_EVIDENCE | Provider webhook must be pointed at the app |
| §56 | Sheets production flow | Existing pipeline | | `ASheetRowBecomesRevenueTest` | BLOCKED_OPERATIONAL_EVIDENCE | Needs a real row and production evidence |
| §57–59 | Performance | Heavy worker for imports | Last updated on the dashboard | | PARTIAL | Only the dashboard shows it; p50/p95 not measured |
| §60 | Readiness center | Mail, Google login, engine rows | | `ProductionReadinessTest` | PARTIAL | Reports, landing, backups rows missing |
| §70–73 | Analytics and automation impact | Existing insights | | | PARTIAL | Estimated manual actions saved not built |
