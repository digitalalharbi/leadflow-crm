# Mohtam — Reporting, Short Links & Real Estate Funnel Specification

## Status

This document is the canonical implementation specification for:

- Reports
- Saved Reports
- Client Live Reports
- Short Links
- Real Estate Funnel
- Marketing Reporting
- Sales Reporting
- Attribution Reporting
- Tracking Hub Reporting
- Revenue Reporting
- Client Sharing
- Report Security
- Report Templates
- Report Exports
- Report Branding

This specification must be implemented completely.
Do not silently omit requirements.

---

# 1. Product Goal

Mohtam must provide a unified real-estate performance reporting experience.

The primary client experience is:

**One Project → One Report → One Short Link → Everything Important**

A client opening one report link should understand:

Marketing → Awareness → Interest → Engagement → Viewing → Purchase Decision → Referral → Revenue

without opening Meta, TikTok, Snapchat, Google Ads, X, LinkedIn, Excel, separate CRM reports, or multiple dashboards.

Mohtam must connect:

Advertising → Tracking → Leads → Sales Team → Follow-up → Visits → Reservations → Sales → Revenue

in one place.

---

# 2. Canonical Real Estate Funnel

The official Mohtam real-estate funnel is:

## وعي
↓
## اهتمام
↓
## تفاعل
↓
## حجز معاينة
↓
## قرار شراء
↓
## إحالة

This is the canonical funnel across the product and must be used consistently in Dashboard, Internal Reports, Saved Reports, Client Live Reports, Project Analytics, Marketing Analytics, Sales Analytics, Attribution, Tracking Hub, Executive Summary, Email Digests, Charts, and Exports.

Do not create competing funnel definitions.

---

# 3. Funnel Mapping

## 3.1 وعي
- Impressions
- Ad clicks
- Tracking link views
- Landing page views
- Form views
- QR visits
- Website visits
- Portal visits
- Social traffic

## 3.2 اهتمام
- Lead created
- Form submitted
- WhatsApp inquiry
- Call inquiry
- Imported lead
- Manual lead
- Source captured

## 3.3 تفاعل
- Contacted
- Qualified
- Interested
- Follow-up
- WhatsApp interaction
- Call result
- Sales engagement

## 3.4 حجز معاينة
- Appointment booked
- Viewing scheduled
- Visit completed

## 3.5 قرار شراء
- Reservation
- Negotiation
- Won
- Sale
- Revenue

## 3.6 إحالة
- Referral
- Handoff
- Broker referral
- Recommendation
- Repeat opportunity

Existing operational stages remain available as detailed sub-stages. Do not delete Qualified, Interested, Appointment, Visit, Reservation, Won, or Lost.

---

# 4. Funnel Analytics

Each funnel stage must support:
- Count
- Conversion rate
- Drop-off rate
- Previous-period comparison
- Trend
- Revenue where applicable

Filtering must support Company, Project, Platform, Campaign, Source, Sales team, Sales rep, and Date range.

---

# 5. Reporting Source of Truth

Reports must use existing Mohtam data from Tracking, Tracking Hub, Forms, Campaigns, Leads, Attribution, Sales, Follow-ups, Visits, Reservations, Revenue, Users, Projects, Units, and Sources.

Do not create a second business truth. Do not duplicate analytical facts unnecessarily.

---

# 6. Reports Library

Create a professional workspace section: **التقارير**

Display:
- Report name
- Project
- Template
- Owner
- Date range
- Last updated
- Sharing status
- Views
- Status

Statuses: Draft, Live, Archived.

Actions: Open, Edit, Duplicate, Share, Copy Link, Revoke, Archive, Export.

---

# 7. Client Access Center

Create a workspace section: **تقارير العملاء**

Display Client, Project, Report, Short Link, Last Viewed, Total Views, Expiry, Protection, and Status.

Actions: Open, Copy Link, Edit, Duplicate, Revoke, Export.

This is the central place for external report sharing.

---

# 8. Report Builder

Authorized users can configure Report name, Client, Project(s), Date range, Comparison period, Attribution model, Currency, Sections, Platforms, Campaigns, Sources, Sales team, Sales users, Branding, Notes, and Share settings.

---

# 9. Report Templates

Provide:
- Executive Summary
- Marketing Performance
- Sales Performance
- Full Funnel
- Project Performance
- Campaign Performance
- Team Performance
- Owner / Investor Report

All templates must use the same reporting engine. Templates are configurations, not separate systems.

---

# 10. One-Link Client Experience

The client should receive one link only.

Current canonical format:
`https://mohtam.pro/r/<token>`

Future format when the short domain is activated:
`https://go.mohtam.pro/r/<token>`

Old links must remain compatible after future migration.
Do not use long URLs such as `/reports/share/...`.

---

# 11. Global Short-Link Standard

External Mohtam links must remain short.

Canonical routes:
- Reports: `/r/<token>`
- Forms: `/f/<token>`
- Tracking: `/t/<token>`
- Invitations: `/i/<token>`
- Generic shared resources: `/s/<token>`

Never expose tenant IDs, project IDs, database IDs, or sequential internal identifiers in public URLs.
Use secure high-entropy tokens.

---

# 12. Short-Link Service

Implement one reusable short-link layer supporting Token, Resource type, Workspace, Project, Created by, Created at, Active/inactive, Expiry, Optional password, View count, Last viewed, and Revoked at.

Do not create unrelated link implementations for every feature.

---

# 13. Client Report Header

The shared report should include Company logo, Project logo, Company name, Project name, Report name, Selected period, Last updated, and Live status.

Optional: Client name, Account manager, Contact details.

---

# 14. Executive Summary

Where actual data exists, display:
- Advertising Spend
- Leads
- Qualified Leads
- Interested Leads
- Visits
- Reservations
- Sales
- Revenue
- CPL
- CPA
- CAC
- Conversion Rate
- ROAS

Never invent values. If a metric cannot be calculated, display `غير متاح` or hide it according to report configuration. Do not show misleading zeros.

---

# 15. Marketing Performance

Unified platform comparison should support Meta, TikTok, Snapchat, Google Ads, X, LinkedIn, Organic, Referral, WhatsApp, Portals, and other real sources.

Per platform:
- Spend
- Impressions
- Clicks
- CTR
- CPC
- Leads
- CPL
- Qualified
- Interested
- Visits
- Reservations
- Sales
- Revenue
- CAC
- ROAS

---

# 16. Platform Data vs Mohtam Attribution

Never mix **Platform Reported Performance** with **Mohtam Attributed Performance**. They must be clearly labelled separately.

---

# 17. Campaign Reporting

Campaign table supports Campaign, Platform, Status, Spend, Impressions, Clicks, CTR, CPC, Leads, CPL, Qualified, Interested, Visits, Reservations, Sales, Revenue, and ROAS.

Controls: Search, Sort, Filter, Date comparison, Platform, Project, Source, Campaign detail.

---

# 18. Sales Reporting

Display Leads received, Assigned, Contacted, Not contacted, Qualified, Interested, Follow-ups, Appointments, Visits, Reservations, Won, Lost, and Revenue.

Also show Average first response time, SLA breaches, Overdue follow-ups, and Unassigned leads.

---

# 19. Sales Team Reporting

Optional report section. Per salesperson show Leads assigned, Contact rate, Average response time, Follow-up completion, Qualified, Interested, Visits, Reservations, Sales, Conversion rate, and Revenue.

Do not expose team performance publicly unless explicitly enabled.

---

# 20. Lead Quality

Where real data exists, support High interest, Medium interest, Low interest, Qualified, Unqualified, Lost, Budget segment, Purchase timeline, Financing, Unit preference, and Project interest.

Never invent missing lead attributes.

---

# 21. Loss Analysis

Display Lost count, Loss rate, Top loss reasons, and Funnel stage where opportunities were lost. Use stored reasons only.

---

# 22. Attribution Reporting

Support existing Mohtam models:
- First Touch
- Last Touch
- Linear
- Position-Based

Show: Source → Lead → Qualified → Interested → Visit → Reservation → Revenue.

The selected attribution model must always be visible.

---

# 23. Tracking Hub Reporting

Where available, report Tracking link views, Landing views, Form views, Form starts, Form submissions, Leads, Qualified, Interested, Visits, Reservations, and Revenue.

Represent clearly: Traffic → Interest → Lead → Sales → Revenue.

---

# 24. Source Reporting

Show only sources with actual data. Potential categories: Advertising, Organic, Portals, Social, WhatsApp, Referral, Broker, Influencer, QR, Landing page, Form, Manual, Import.

---

# 25. Project Reporting

Project report can display Leads, Sources, Spend, Qualified, Interested, Visits, Reservations, Sales, Revenue, Units, Conversion rate, and Sales team performance.

Support one project, selected projects, and portfolio reporting according to permissions.

---

# 26. Unit Reporting

Where actual unit data exists, show Unit, Interested leads, Visits, Reservations, Sales, Revenue, and Availability.

Sensitive inventory or pricing must not be public unless explicitly enabled.

---

# 27. Date Ranges

Support Today, Yesterday, Last 7 days, Last 14 days, Last 30 days, This month, Previous month, Quarter, and Custom range.

Comparison: Previous period, Previous month, Custom comparison.

---

# 28. Live Data & Freshness

Live reports read current Mohtam data. Display `آخر تحديث`.
Caching is allowed for performance, but never describe cached data as live without a freshness timestamp.

---

# 29. Report Sections

Report owner controls visibility.
Available sections: Executive Summary, Funnel, Marketing, Platforms, Campaigns, Sources, Attribution, Sales, Team, Visits, Reservations, Revenue, Units, Loss Analysis, Tracking, Insights, Attention.

Disabled sections must not be included in the backend response. Frontend hiding alone is not acceptable.

---

# 30. Client-Facing Arabic

Use professional terminology:
- العملاء المحتملون
- العملاء المؤهلون
- اهتمام مرتفع
- اهتمام متوسط
- اهتمام منخفض
- التفاعل
- حجز المعاينة
- الزيارات
- الحجوزات
- المبيعات
- الإيرادات
- تكلفة العميل المحتمل
- تكلفة الاستحواذ
- العائد على الإنفاق الإعلاني
- فرصة خاسرة
- الخسارة
- صفقات مُغلقة

Avoid informal CRM terminology.

---

# 31. Insights

Generate deterministic insights based only on real metrics. No unsupported AI-generated claims.

Examples:
- أفضل منصة من حيث تكلفة العميل المحتمل.
- أعلى مصدر من حيث التحويل إلى زيارة.
- أفضل مصدر من حيث الإيراد.
- ارتفاع معدل الاهتمام مقارنة بالفترة السابقة.
- انخفاض سرعة استجابة الفريق.
- حملة تحقق عددًا مرتفعًا من العملاء المحتملين بجودة منخفضة.
- مصدر محدود الحجم لكنه مرتفع التحويل.

---

# 32. Attention Center

Display **يحتاج انتباهك** with items such as Leads not contacted, SLA breaches, Unassigned leads, Overdue follow-ups, High CPL, Conversion decline, Tracking issues, Automation failures, and Campaign spend without meaningful conversion.

Shared/client report visibility must remain client-safe.

---

# 33. Notes

Authorized workspace users can add Executive note, Marketing note, Sales note, and Period summary.
Store author, timestamp, and revision/history. Client access is read-only by default.

---

# 34. Sharing Controls

Each report link supports Active/inactive, Optional password, Optional expiry, Revoke, Regenerate token, View count, and Last viewed.

Optional: allowed email, allowed email domain, OTP/email verification.

---

# 35. Share Security

Public report tokens must use high entropy, not reveal internal IDs, remain tenant scoped, remain project scoped, and enforce section visibility server-side.

Company A token must never expose Company B data.

---

# 36. Report Activity

Track Created, Edited, Published, Shared, Viewed, Exported, Password changed, Token regenerated, Revoked, and Archived.

---

# 37. Report Versioning

Preserve configuration history for significant changes: selected sections, projects, attribution model, visibility, notes, branding, share settings.

Do not snapshot full analytics unnecessarily. A live report normally shows current data for its configured period.

---

# 38. Export

Support PDF, Excel, and CSV where appropriate.

Exports must respect tenant permissions, project permissions, report visibility, and section settings. Hidden sections must never appear in an export. Every export must be audited.

---

# 39. Branding

Support Company logo, Project logo, Company name, Project name, Accent color, Footer/contact details.

Do not allow arbitrary HTML/CSS injection. Support plan entitlement for removing Mohtam branding.

---

# 40. Mobile Client Experience

Must be excellent at 390px, tablet, and desktop.

Recommended client order:
1. Report Header
2. Executive KPIs
3. Real Estate Funnel
4. Trend
5. Platforms
6. Campaigns
7. Sales
8. Visits
9. Reservations
10. Revenue
11. Insights
12. Attention

No page-level horizontal scroll.

---

# 41. Internal vs Public Reports

Internal reports may allow drill-down. Public client reports default to aggregate data.

Do not expose by default lead phone, lead email, private sales notes, internal IDs, or confidential integration information.

---

# 42. Tenant Isolation

Every report query must be Tenant scoped, Project scoped, and Permission scoped.
No global business-data query without tenant context.

---

# 43. Cache Isolation

Report cache keys must include relevant context such as tenant, report, project, date range, report version/configuration, and visibility.

No report cache may ever be shared across tenants.

---

# 44. Performance

Use aggregated SQL, safe tenant-scoped caching, precomputed summaries only when justified, no N+1, pagination, and lazy/deferred secondary sections.

Accuracy always has priority over apparent speed.

---

# 45. Empty States

No ad integration:
`لم يتم ربط حساب إعلاني بعد. يتم عرض النتائج حسب مصدر العملاء المسجل في مهتم.`

No revenue:
`لا توجد مبيعات مسجلة خلال الفترة المحددة.`

No campaigns:
`لا توجد حملات مرتبطة خلال هذه الفترة.`

Do not display misleading zeros when data is unavailable.

---

# 46. URL Migration

Current canonical report URL:
`https://mohtam.pro/r/<token>`

Future:
`https://go.mohtam.pro/r/<token>`

Architecture must allow future migration without breaking existing links.

---

# 47. Domain Standard

Production identity: `mohtam.pro`
API: `api.mohtam.pro`

Do not introduce old domains, staging domains, or long public URLs.

---

# 48. SaaS & Subscription Integration

Report capabilities must respect Workspace subscription, Plan, Feature entitlements, Project limits, and Seats where relevant.

Potential paid report features:
- Advanced Attribution
- Advanced Reports
- PDF Export
- Excel Export
- White-label Reports
- Custom Branding
- Remove Mohtam Branding
- Client OTP
- Advanced Report Templates

Do not hardcode pricing.

---

# 49. Security Requirements

Audit Tenant isolation, Project isolation, Report ownership, Token entropy, Password protection, Expiry, Revocation, Cache isolation, Export security, File access, Section permissions, Public payload PII, and Rate limiting.

---

# 50. Required Test Matrix

Test at minimum Company A and Company B with multiple projects per company.

Roles:
- Admin
- Manager
- Marketing
- Sales Manager
- Sales Rep

Scenarios:
- Saved reports
- Templates
- Short links
- Password protection
- Expiry
- Revocation
- View tracking
- Export
- Tenant isolation
- Project isolation
- Mobile
- RTL
- Empty states
- No ad integration
- No revenue
- No sales
- Tracking-only project
- Sales-only project
- Full funnel
- Multi-project report

---

# 51. Full Gates

Before declaring complete run:
- Backend full suite
- Pint
- Typecheck
- Lint
- Vitest
- Production build
- Full Playwright

Do not release with unresolved report security failures, tenant isolation failures, project isolation failures, incorrect metrics, or public data leakage.

---

# 52. Existing System Must Remain Intact

Do not break:
- Multi-tenant SaaS
- Platform Admin
- Subscriptions
- Plans
- Seats
- Feature Entitlements
- RBAC
- Tenant Isolation
- Project Isolation
- Tracking Hub
- Work Now
- Leads
- Visits
- Reservations
- Revenue
- Notifications
- Digests
- Attribution
- Existing v0.6.0 capabilities

---

# 53. Implementation Method

Work in bounded batches.
For each batch:

Implement → Test → Security Test → Commit → Continue

Do not ask whether to continue.
Do not silently reduce the specification.

If an item already exists: verify it, reuse it, improve only when required, and mark it complete.
Do not rebuild working functionality unnecessarily.

---

# 54. Completion Rule

Implementation is not complete merely because the UI exists.

The complete system must work end-to-end using real Mohtam data.

A client must be able to:

Project → Create Report → Select Sections → Publish → Copy Short Link → Send Link → Open Link → Understand Complete Performance

from:

وعي → اهتمام → تفاعل → حجز معاينة → قرار شراء → إحالة

and see the relationship between:

Marketing → Sales → Visits → Reservations → Revenue

in one place.

---

# 55. Final Required Report

Return:

CANONICAL FUNNEL: PASS / FAIL
REPORT ENGINE: PASS / FAIL
SAVED REPORTS: PASS / FAIL
REPORT TEMPLATES: PASS / FAIL
CLIENT REPORTS: PASS / FAIL
CLIENT ACCESS CENTER: PASS / FAIL
SHORT LINKS: PASS / FAIL
ONE-LINK EXPERIENCE: PASS / FAIL
SHORT-LINK SECURITY: PASS / FAIL
MARKETING REPORTING: PASS / FAIL
CAMPAIGN REPORTING: PASS / FAIL
SALES REPORTING: PASS / FAIL
TEAM REPORTING: PASS / FAIL
FUNNEL REPORTING: PASS / FAIL
ATTRIBUTION REPORTING: PASS / FAIL
TRACKING HUB REPORTING: PASS / FAIL
REVENUE REPORTING: PASS / FAIL
LOSS ANALYSIS: PASS / FAIL
REPORT PASSWORD: PASS / FAIL
REPORT EXPIRY: PASS / FAIL
REPORT REVOCATION: PASS / FAIL
REPORT VIEW TRACKING: PASS / FAIL
REPORT EXPORT: PASS / FAIL
REPORT VERSIONING: PASS / FAIL
REPORT BRANDING: PASS / FAIL
TENANT ISOLATION: PASS / FAIL
PROJECT ISOLATION: PASS / FAIL
CACHE ISOLATION: PASS / FAIL
SHARE SECURITY: PASS / FAIL
PII PROTECTION: PASS / FAIL
MOBILE: PASS / FAIL
RTL: PASS / FAIL
REAL DATA ONLY: PASS / FAIL
NO LONG PUBLIC URLS: PASS / FAIL

CANONICAL REPORT URL:
`mohtam.pro/r/<token>`

FUTURE SHORT REPORT URL:
`go.mohtam.pro/r/<token>`

BACKEND TESTS: ...
FRONTEND TESTS: ...
PLAYWRIGHT: ...

CLIENT REPORTING READY: YES / NO
PRODUCTION REPORTING READY: YES / NO

HIGH-VALUE REMAINING GAPS:
[...]

Do not mark READY unless all critical reporting, sharing, security and isolation requirements in this document have been checked.
