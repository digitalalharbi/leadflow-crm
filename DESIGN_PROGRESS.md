# v0.4.0 — Mahtam brand & analytics

Working branch: `feature/mahtam-brand-analytics` (base: `develop`).
**No tag, no merge, no beta until every item below is done.**

This file is the checkpoint: it should be enough to resume from a cold start
without re-reading the diff.

## Decisions already made — do not revisit

- **Brand**: teal `#12A594`, ink `#0B1220`. Teal rather than blue because this
  market's CRMs are all blue and the mark has to survive a printed sales deck.
- **Mark**: a closed loop with م's descending tail, opening onto a dot about to
  enter it. Two earlier versions were scrapped after viewing — one read as a
  tadpole, one as a Latin "C" with the tail running backwards for Arabic.
- **Chart palette is independent of the tenant colour.** Only the primary series
  takes `--brand`; a chart whose every series is a tint of one hue cannot be
  read, and under white label that hue belongs to the customer.
- **No raw Tailwind palette classes in components.** Everything reads a token.
- **Arabic takes no letter-spacing**, gets 1.8 line-height, and signed numbers
  are forced `dir="ltr"` so bidi does not turn `+25` into `25+`.
- `/dev/design-system` is guarded at the *import*, so the chunk is absent from a
  production build rather than merely unrouted.

## Completed

| Area | Where |
| --- | --- |
| Brand mark / wordmark / lockup | `frontend/src/components/brand/` |
| Login + brand field visualisation | `frontend/src/pages/Login.tsx`, `BrandField.tsx` |
| Design tokens, typography scale, motion | `frontend/src/index.css` |
| Dark mode (rebuilt on the ink colour, not inverted) | `frontend/src/index.css` `.dark` |
| Chart system | `frontend/src/components/charts/` |
| Dashboard rebuild | `frontend/src/pages/Dashboard.tsx` |
| KPI strip with period-over-period change | `charts/MetricCard.tsx` |
| Interactive funnel incl. **replied** stage | `charts/ConversionFunnel.tsx` |
| Campaign quality chart | `charts/CampaignQualityChart.tsx` |
| Campaign matrix (volume × quality) | `charts/CampaignMatrix.tsx` |
| Platform analytics | `charts/PlatformPerformanceChart.tsx` |
| Lead quality distribution | `charts/DistributionBar.tsx` |
| Response time distribution | `charts/ResponseTimeChart.tsx` |
| Dashboard analytics service | `backend/app/Services/Analytics/DashboardAnalytics.php` |
| Demo campaigns with distinct quality profiles | `backend/database/seeders/Support/DemoContent.php` |
| `/dev/design-system` | `frontend/src/pages/dev/DesignSystem.tsx` |
| **Leads** list: chip filter bar, identity column, source column | `frontend/src/pages/Leads.tsx`, `components/leads/LeadFilters.tsx` |
| **Lead detail** header: score ring + one-line facts | `frontend/src/pages/LeadDetail.tsx`, `components/leads/ScoreRing.tsx` |
| **Reports**: five tabs, one filter set, lost-reason bars, sales-team table | `frontend/src/pages/Reports.tsx`, `charts/LostReasonsChart.tsx` |
| Sales-team report API (measurements, not a ranking) | `backend/.../SalesPerformanceController::salesTeam` |
| **Pipeline** card slimmed to what decides the next move | `frontend/src/pages/Pipeline.tsx` |
| **Follow-ups** as a work queue, due-time block leading each row | `frontend/src/pages/FollowUps.tsx` |
| **Projects** as business units with funnel metrics | `frontend/src/pages/Projects.tsx`, `ProjectController::index` |
| **Integrations** hub with provider marks of our own | `frontend/src/pages/Integrations.tsx`, `components/integrations/ProviderMark.tsx` |
| **Users** identified in one cell, measured by outcome | `frontend/src/pages/Users.tsx` |
| **Settings** split into five sections by what is configured | `frontend/src/pages/Settings.tsx` |
| **Platform console** on its own chrome, with subscription + usage | `frontend/src/pages/Platform.tsx`, `AppShell.tsx`, `index.css` |
| Icon set drawn from the mark (favicon, pinned tab, touch icon) | `frontend/public/` |

## Remaining

Pages, in this order. Each needs individual design attention — inheriting
tokens is not enough. Open each in the browser and look at it.

- [x] Leads
- [x] Lead Detail
- [x] Pipeline
- [x] Follow-ups
- [x] Projects
- [x] Reports (tabs: overview / campaigns / platforms / sales team / lost reasons)
- [x] Integrations
- [x] Users
- [x] Settings (split into sections, not one long page)
- [x] Super Admin — own chrome, subscription + 30-day usage, tenant panel

Then:

- [x] Favicon from the brand mark; tenant favicon still overrides
- [ ] Mobile review, per page, at 375px
- [ ] RTL review, per page
- [ ] Dark mode review, per page
- [ ] Visual QA with screenshots; fix what it finds
- [ ] Consistency audit (raw palette, radii, duplicate card styles, stale
      "LeadFlow" strings visible to users)
- [ ] E2E additions: dashboard drill-down, matrix interaction, lead filters,
      tenant branding override, theme, report filters, mobile nav
- [ ] Performance re-measure: dashboard API, reports API, lead list, lead detail
- [ ] PR to `develop`, CI green, merge, tag `v0.4.0`

## How to run

```bash
cd backend && php artisan serve --port=8000
cd frontend && npm run dev -- --port 5173
```

Demo tenant `rawasi` has the rich data (180 leads, campaigns with real spread).
E2E fixtures live in tenant `e2e-alpha`. To look at a page without logging in:

```bash
php artisan tinker --execute="echo App\Models\User::where('email','admin@rawasi.test')->first()->createToken('preview')->plainTextToken;"
```

then `localStorage.setItem('leadflow.token', '<token>')` in the browser.

## Test baseline

**336 backend · 39 frontend unit · 91 Playwright.** None of these may regress.

## Visual bugs found and fixed so far

Each of these was found by opening the page, not by a failing test — which is
the reason the brief asks for the browser pass.

1. The brand mark's first two drafts read as a tadpole and as a Latin "C" with
   the tail running backwards for Arabic. Redrawn twice.
2. The demo seeder marked 80% of leads still in "new" as already contacted, so
   the funnel's contact stage read 98% and the neglected-leads panel was empty.
3. The lead header's score ring said 89 while the breakdown beneath it totalled
   77 — the seeder wrote a random score per stage instead of running the rules
   engine. All 180 demo leads now agree with it.
4. An overdue follow-up rendered as `23:53`, a bare clock time with no hint it
   had passed. `formatDue` now says how late it is.
5. 62% of the demo workspace read "hot", because every qualification field was
   filled for every lead regardless of stage. Qualification now arrives in the
   order a rep learns it; the split is 39/16/45.
6. Campaign bubbles in the matrix all clustered, because demo campaigns were
   assigned at random and so converted identically. Campaigns now carry a
   quality weight and are picked by how far the lead got.
7. An email or a slug set under an Arabic name carried `dir="ltr"` on the block
   itself, which moves the block's *alignment* to the far side as well as its
   character order — so every such pair sat ragged. The Latin run is isolated
   with `<bdi>` instead. Hit Users, Platform, Settings and Reports.
8. The title and favicon were only written when a tenant had branding of their
   own, so a branded customer's identity survived into the next session's tab.
   Both are now written on every change, falling back to the platform's.
9. `public/icons.svg` — a sheet of unrelated social-network marks from the Vite
   scaffold — had been shipping to production since the first commit.
