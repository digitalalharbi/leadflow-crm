# Reporting, Short Links & Funnel — compliance checklist

Against `MOHTAM-REPORTING-LINKS-FUNNEL-SPEC.md` (55 sections). Status is **COMPLETE / PARTIAL / MISSING / BLOCKED**.
Evidence names the code and the test that proves it; a status here is a claim about the code, not about a green run.

| § | Section | Status | Where it lives / what proves it |
|---|---------|--------|---------------------------------|
| 1 | Product goal | COMPLETE | One report → one short link → marketing → funnel → leads → team → visits → reservations → sales → revenue → attribution → insights → attention, all from Mohtam data. `ReportMatrixTest`, `e2e/report-library.spec.ts` |
| 2 | Canonical funnel | COMPLETE | `Domain/Funnel/RealEstateFunnel`; drawn by one component (`visual/RealEstateFunnel.tsx`) on the dashboard, manager center, project page, campaign revenue panel, public/preview reports and the digests; exports derive from the same payload. `CanonicalFunnelTest`, `FunnelEndpointTest`, `RealEstateFunnel.test.tsx` |
| 3 | Funnel mapping | COMPLETE | Awareness = platform impressions, else Mohtam-measured visits (never summed; `basis` names it). Interest = lead created. Engagement, booking, decision per the spec's lists; revenue sits on decision. Referral = partner/broker, referral or recommendation source, repeat opportunity. The operational stages (qualified, interested, appointment, visit, reservation, negotiation, won, lost) are the `sub_stages`; none removed. *Interpretation:* "handoff" is read as a broker/partner hand-off; the automation-to-human handoff is an operational state, not a referral. |
| 4 | Funnel analytics | COMPLETE | Count, conversion, drop-off, previous-period comparison (`change`, `previous`), trend per stage (`trend.points`, awareness and referral included), revenue; filters: project(s), platform(s), campaign(s), source/channel(s), sales rep(s), date range, partner. There is no separate "sales team" entity in the data model; the rep filter is the team filter. |
| 5 | Source of truth | COMPLETE | No new analytical tables: every figure is counted from leads, appointments, reservations, follow-ups, tracking events, metric snapshots, campaigns, properties. Only configuration (reports, notes, revisions, activity, links) is new. |
| 6 | Reports library | COMPLETE | `pages/ReportLibrary`, `ShareTab`; name, project, template, owner, period, last updated, sharing, views, status (draft/live/archived); open, edit, duplicate, share, copy link, revoke, archive, export. `ReportLibraryTest`, `e2e/report-library.spec.ts` |
| 7 | Client Access Center | COMPLETE | `pages/ClientAccess` + `GET /report-links`; client, project, report, short link, last viewed, views, expiry, protection, status; open, copy, edit, duplicate, revoke, export. |
| 8 | Report builder | COMPLETE | `ShareBuilder` + `BuilderSettings`: name, client, projects, period, comparison, attribution model, currency, sections (order, visibility, per-section period), platforms, campaigns, sources, sales users, branding, notes (panel), share settings (links panel). |
| 9 | Report templates | COMPLETE | executive, marketing, sales, full funnel, project, campaign, team, investor — plus client, partner, statement, management. One engine (`LiveReport`); templates are section lists in `ReportTemplates`. |
| 10 | One-link experience | COMPLETE | One address per client; the page loads the whole report (first screen, then the rest) from it. |
| 11 | Short-link standard | COMPLETE | `/r /f /t /i /s` on `mohtam.pro`; 22-char base-62 tokens, no ids, no tenant or project names. `config/links.php`, `ShortLink::urlFor`, `ReportSecurityTest` |
| 12 | Short-link service | COMPLETE | One layer: `short_links` + `Domain/Links/ShortLinks` (type, resource, workspace, project, creator, active, expiry, password, views, last viewed, revoked). Reports, tracking, forms, invitations and files issue their addresses through it. |
| 13 | Client report header | COMPLETE | Company logo, project logo, company and project names, report name, period, last update + live badge, client, account manager (when switched on), contact/footer text. |
| 14 | Executive summary | COMPLETE | Spend, leads, qualified, interested, visits, reservations, sales, revenue, CPL, CPA (cost per booked viewing), CAC, conversion rate, ROAS; unavailable = "غير متاح", never a zero. |
| 15 | Marketing performance | COMPLETE | `marketing` section: per platform spend, impressions, clicks, CTR, CPC (reported) and leads → revenue, CPL, CAC, ROAS (attributed), for Meta, TikTok, Snapchat, Google Ads, X, LinkedIn, organic, referral, WhatsApp, portals, broker, influencer, QR, landing page, form, manual, import. |
| 16 | Platform vs Mohtam | COMPLETE | Two separately labelled blocks that are never merged; awareness names its basis. `ReportSectionsTest::marketing_keeps…` |
| 17 | Campaign reporting | COMPLETE | Full column set; search, sort, platform and project filters, previous-period change, row detail. |
| 18 | Sales reporting | COMPLETE | Received → lost, revenue, first-response time, SLA breaches, overdue follow-ups, unassigned. |
| 19 | Team reporting | COMPLETE | Per salesperson, names and rates only; not on a public link unless `expose_team` is switched on (`ReportTemplates::plan`). |
| 20 | Lead quality | COMPLETE | Interest high/medium/low, qualified, unqualified, lost, budget segment, purchase timeline, financing, unit preference, project interest; only stored attributes. |
| 21 | Loss analysis | COMPLETE | Lost count, rate, stored reasons, stage lost at. |
| 22 | Attribution | COMPLETE | First, last, linear, position; the selected model is always named; Source → Lead → Qualified → Interested → Visit → Reservation → Revenue; all four side by side with `advanced_attribution`. |
| 23 | Tracking Hub reporting | COMPLETE | Link views, landing views, form views/starts/submissions, leads → revenue. |
| 24 | Source reporting | COMPLETE | Only channels with data. |
| 25 | Project reporting | COMPLETE | One, selected, or all projects; per project leads, sources, spend, qualified … revenue, units, conversion. |
| 26 | Unit reporting | COMPLETE | Interested, visits, reservations, sold, revenue, availability; price only with `show_unit_prices`. |
| 27 | Date ranges | COMPLETE | Today … custom; comparison: previous period, previous month, custom (`ReportPeriod`). |
| 28 | Live data & freshness | COMPLETE | "آخر تحديث" on the page; a cached answer says `cached` with its age. |
| 29 | Report sections | COMPLETE | All 17 spec sections; a disabled section is not in the response (`ReportSectionsTest::a_hidden_section…`). |
| 30 | Client-facing Arabic | COMPLETE | Spec terms throughout (`rsec`, `library`, `builder`, `links`); no informal CRM terms. |
| 31 | Insights | COMPLETE | Deterministic, only from enabled sections (`ReportSections::findings`). |
| 32 | Attention center | COMPLETE | Not-contacted, SLA breaches, unassigned, overdue follow-ups, high CPL, conversion decline, tracking issues, automation failures, spend without conversion; client copy has no links into the product. |
| 33 | Notes | COMPLETE | Executive, marketing, sales, period; author, time, revisions kept; plain text; read-only for the client. |
| 34 | Sharing controls | COMPLETE | Active/inactive, password, expiry, revoke, regenerate, views, last viewed, allowed emails, allowed domains, emailed code. |
| 35 | Share security | COMPLETE | High entropy, hashed at rest, tenant- and project-scoped, sections enforced server-side. `ReportSecurityTest` |
| 36 | Report activity | COMPLETE | created, edited, published, shared, viewed, exported, password changed, token regenerated, revoked, archived (+ unarchived, duplicated, note, link created). |
| 37 | Versioning | COMPLETE | `report_revisions`: sections, layout, projects, model, branding, filters, options, notes, share settings (no password, no token, no analytics). |
| 38 | Export | COMPLETE | CSV, Excel, PDF (mPDF); team exports follow permission and section settings, client exports follow the link's switch and the plan; every export audited. |
| 39 | Branding | COMPLETE | Logo, project logo, name, accent colour, footer — text and https images only; white-label (name, logo, hide "powered by") needs the plan. |
| 40 | Mobile client experience | COMPLETE | §40 order is the default section order; no page-level horizontal scroll at 390px (`e2e/report-library.spec.ts`, `live-reports.spec.ts`). |
| 41 | Internal vs public | COMPLETE | Preview = internal (team sections, drill-down); public = aggregate, no phone/email/notes/ids. |
| 42 | Tenant isolation | COMPLETE | Every report query runs inside the workspace and the report's projects; `ReportSecurityTest`, `ReportMatrixTest`, `CompanyIsolationMatrixTest`. |
| 43 | Cache isolation | COMPLETE | `ReportCache::key` includes workspace, report, revision, audience, sections, projects, filters, model, period, branding. |
| 44 | Performance | COMPLETE | Aggregated SQL, bounded rows, short tenant-keyed cache, first screen then the rest (`?part=`). |
| 45 | Empty states | COMPLETE | The three spec sentences, verbatim, plus "غير متاح" for unavailable figures. |
| 46 | URL migration | COMPLETE | `LINKS_SHORT_ENABLED` changes only newly issued addresses; routes answer on every host; old addresses keep working. |
| 47 | Domain standard | COMPLETE | `mohtam.pro` / `api.mohtam.pro`; no other host introduced. |
| 48 | SaaS integration | COMPLETE | `advanced_reports`, `report_pdf`, `report_excel`, `advanced_templates`, `advanced_attribution`, `white_label_reports`, `client_otp`, `custom_branding` (existing); a closed subscription closes the links; no price in code. |
| 49 | Security requirements | COMPLETE | `ReportSecurityTest` covers every item. |
| 50 | Test matrix | COMPLETE | Two companies × several projects; admin, manager, marketing, sales manager, sales rep; every scenario in the list (`ReportMatrixTest`, `ReportLibraryTest`, `ReportSecurityTest`, `ReportSectionsTest`, e2e). |
| 51 | Full gates | see final report | |
| 52 | Existing system intact | see final report | |
| 53 | Method | COMPLETE | Batches, each tested and committed on `feat/reporting-links-funnel`. |
| 54 | Completion rule | COMPLETE | `e2e/report-library.spec.ts` walks project → report → sections → publish → short link → open → full performance. |
| 55 | Final report | see final report | |

## Known interpretations and limits

- **CPA** is "cost per action": spend divided by booked/held viewings. **CAC** is spend divided by sales.
- **Awareness** is impressions when ad accounts report them; otherwise Mohtam's own measurement. They are never added together.
- **Handoff** (§3.6) is read as a broker/partner hand-off. The automation-to-human handoff is an operational state.
- **Sales team** has no entity of its own in the data model; the rep filter is the team filter.
- **Logos** are https addresses; uploading an image file is not offered.
- **Account manager** in the header is the report's owner.
