# مهتم — design system (reference implementation)

The visual authority is the supplied reference dashboard: soft grey frame on a
darker canvas, white rounded cards, a floating capsule header with a pill
navigation track, a compact icon rail with a green circular active state, deep
green accents, hatched rounded bars, pill filters. One system, applied through
tokens — no component carries a raw colour.

## Phase A — audit (what existed)

* Stack: React 19, Vite, Tailwind 4 (`@theme` tokens in `src/index.css`), no UI
  framework. Shared primitives already in `components/ui` (Card, Button, Badge,
  Tabs, Table, Drawer, Modal, Input/Select, PageHeader, States).
* Already token-driven and pill-based, so the reference was reached by changing
  tokens and the shell rather than rewriting screens.
* Navigation was a full-width labelled sidebar + a plain top bar.
* Constraints found in tests (kept, not weakened): KPI links and `kpi-*` hooks,
  `lead-flow` chart contract (3 named series, table view, print marks),
  1366×768 density budgets, one banner landmark, drawer on phones.

## Phase B — foundation (done)

| Area | Where |
|---|---|
| Colour, radius, shadow, spacing tokens | `src/index.css` `@theme` |
| Typography (Inter Variable + IBM Plex Sans Arabic, JetBrains Mono for technical values) | `:root --font-*`, `src/main.tsx` (self-hosted `@fontsource`) |
| App frame (outer canvas → rounded workspace) | `layout/AppShell.tsx` |
| Floating header + pill nav + account menu | `layout/Topbar.tsx` |
| Icon rail with tooltips | `layout/IconRail.tsx` |
| One navigation model for header, rail and phone drawer | `layout/navigation.ts` |
| Page title row above the rail (portal slot) | `ui/PageHeader.tsx` |
| Component classes (`chrome-circle`, `nav-pill`, `rail-item`, `segment`, `filter-pill`) | `src/index.css` |

### Palette (sampled from the reference; tenants may override `--brand`)

`--color-outer #dcdce0` · `--color-canvas #eeeeee` · surface `#ffffff` ·
`--brand #078759` · `--brand-secondary #139b70` · tint = 42 % brand on white ·
deep = 82 % brand on black · ink `#111111` · muted `#777777` · hairline `#f0f0f0`.
Tints are *derived* from `--brand`, so a white-label colour yields a coherent set.

### Typography

Body 400, labels 500, section/card titles 600, page title and key numbers 700.
Tabular numerals on tables, `time`, metrics and `.tabular`. Arabic gets looser
leading (1.75 body, 1.45 headings) and zero letter-spacing. Mono is applied to
`code`/`kbd`/`pre` and to technical inputs (hosts, SIDs, tokens, ids).

## Phase C — dashboard (done)

Reference grid (`hero | chart | total` / `mini | chart | pipeline` /
`table | table | pipeline`) over real data: needs-attention hero, hatched
rounded-bar lead flow, total + trend, conversion + funnel steps, team avatars,
recommended-leads table.

## Phase D — remaining screens (not done)

They inherit tokens, shell, typography and shared primitives automatically.
Per-screen composition against the reference is still to be reviewed:
Leads detail, Pipeline, Follow-ups, Projects, Project workspace, Companies,
Automation, Integrations, Reports, Data quality, Users, Settings, Readiness,
Login, drawers and modals.

## Known gaps

* Pixel comparison is by eye: the reference image is not stored in the repo, so
  no numeric screenshot diff has been run.
* On a short laptop (≤ 820 px high) the frame tightens (margins, header, rail
  items) to keep the working screens inside 1366×768.

## Visual primitives (`frontend/src/components/visual`)

The vocabulary every screen draws with: `Donut`, `RingProgress`, `ProgressBar`,
`PipelineFunnel`, `RankBars`, `KpiCard`/`Delta`, `ActionCard`, `StatusPill`.
Rules: colour comes from `tones.ts` (token-driven, white-label safe, brand ramps via
`color-mix`); state is shown by a badge (icon + colour + one word from the nine-word
status vocabulary) and never explained in a sentence; geometry is computed by pure
functions (`arcs`, `funnelRows`, `rank`, `clampPercent`) that are unit-tested. Technical
terms (API, OAuth, webhook, payload, queue, cursor, internal IDs) live only under
Advanced / Diagnostics / Settings. Arabic UI face is Noto Sans Arabic Variable
(IBM Plex Sans Arabic is a documented fallback and is not shipped); numbers are tabular.
