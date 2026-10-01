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

## Remaining

Pages, in this order. Each needs individual design attention — inheriting
tokens is not enough. Open each in the browser and look at it.

- [ ] Leads
- [ ] Lead Detail
- [ ] Pipeline
- [ ] Follow-ups
- [ ] Projects
- [ ] Reports (tabs: overview / campaigns / platforms / sales team / lost reasons)
- [ ] Integrations
- [ ] Users
- [ ] Settings (split into sections, not one long page)
- [ ] Super Admin

Then:

- [ ] Favicon from the brand mark; tenant favicon still overrides
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

331 backend · 32 frontend unit · 91 Playwright. None of these may regress.
