# Next session — resume here

Branch: `feature/mahtam-brand-analytics`
Last commit: `5548a47` — "feat: pipeline cards carry only what decides the next move"
Base: `develop`. **Do not tag, merge, or cut a beta.** `v0.4.0` ships only when
the whole scope in `DESIGN_PROGRESS.md` is done.

Read `DESIGN_PROGRESS.md` first — it holds the decisions that should not be
revisited (brand colour, mark, chart-palette independence, Arabic typography
rules). Do not re-derive them.

## Start here

**Pipeline — finish it.** The lead card is done. Still to do:
- Column headers: stage name + count, with the stage colour from
  `STATUS_COLOR` in `components/charts/palette.ts`.
- The board at 375px: it should scroll horizontally, not stack.

## Then, in order

1. **Follow-ups** — a daily work queue. Tabs: today / upcoming / overdue /
   completed. Highlight late without flooding the page red. Quick complete and
   outcome should be one interaction.
2. **Projects** — not CRUD cards. Each project is a business unit: leads,
   qualified, qualification rate, appointments, won, plus a mini conversion
   visualisation. Reuse `DistributionBar` or `ConversionFunnel` rather than
   inventing a chart.
3. **Integrations** — provider cards with status (connected / needs setup /
   error) and last sync. The WhatsApp card already exists and is good; the
   others are a tile grid that needs the same treatment.
4. **Users** — system-user management, not HR: name, role, assigned leads,
   qualified, won, status. `/reports/sales-team` already returns per-user
   counts and can feed it.
5. **Settings** — split the single long page into sections: general, branding,
   lead settings, scoring, notifications, integrations.
6. **Super Admin** — platform console; keep it visually distinct from a tenant
   workspace.

## Then the passes

- **Favicon** from the brand mark (`components/brand/BrandMark.tsx`), with the
  tenant's favicon still overriding. `useBrandingTheme` already swaps the link
  element — the default needs to become the Mahtam mark rather than Vite's.
- Mobile (375px), RTL and dark review, per page.
- Visual QA with Playwright screenshots into `artifacts/visual-review/`.
- Consistency audit: raw palette classes, radii, duplicate card styles, stale
  user-visible "LeadFlow" strings.
- E2E additions: dashboard drill-down, matrix interaction, lead filters, tenant
  branding override, theme toggle, report filters, mobile nav.
- Performance re-measure (`php artisan leadflow:measure`), then PR, CI, merge,
  tag `v0.4.0`.

## Running it

```bash
cd backend && php artisan serve --port=8000
cd frontend && npm run dev -- --port 5173
```

Demo tenant `rawasi` has the rich data. To view a page without the login form:

```bash
php artisan tinker --execute="echo App\Models\User::where('email','admin@rawasi.test')->first()->createToken('preview')->plainTextToken;"
```

then in the browser console:
`localStorage.setItem('leadflow.token', '<token>')`
