# Project state — مهتم (Leads CRM)

**What this file is.** The recovery checkpoint required by the owner's global operating policy: the
last verified commit, what is open, what verifies it, and the safe next action. Deliberately short
and mechanical, so a session resuming after context compaction knows exactly what it is resuming
from.

**What this file is not.** It is not the product narrative. [`README.md`](../README.md) describes
the product, [`docs/REQUIREMENTS_MATRIX.md`](REQUIREMENTS_MATRIX.md) carries requirement status,
and [`docs/GITHUB_FLOW.md`](GITHUB_FLOW.md) owns the branch and CI rules. Where this file and Git
disagree, **Git is right and this file is stale.**

Updated 2026-11-07.

## Last verified commit

| | |
|---|---|
| Repository | `digitalalharbi/leadflow-crm` |
| Integration branch | `develop` — same content as v0.9.11 plus this docs change |
| Full sha | `dd2210d5e369e4c7f5b4579f47ee16bed82c7199` on `main` (Release v0.9.11, #263, tag `v0.9.11`) |
| Committed | 2026-11-07 |
| Release branch | `main` — v0.9.11. **Live:** production serves `dd2210d` (deploy run 42377362775: backup, restore check, first-client smoke, 9 of 9 smoke checks; automation readiness `ready`). The new phone navigation is in the live bundle (checked by fetching its chunks) |

`develop` is where development lands; `main` is releases only and is never touched by
development. Verify, do not trust:

```bash
git fetch origin && git log --oneline -10 origin/develop && git status
```

## Disk on the VPS — cleared Nov 6, 2026

The v0.9.7 deploy was refused twice at 15G free. Every image was in use, so pruning images gave
back 0B; the space was the build cache (9.4G). The owner ran `docker builder prune -af` as root:
23G free. From v0.9.8 the deploy script empties the build cache itself when short of room (in
effect since the v0.9.9 deploy). Never `docker volume prune` on that server: thirteen volumes are
unattached and may hold another project's data.

## Release notes for the record

- v0.9.10 (`8471333`, #260): `mohtam:create-workspace` creates a clean company workspace from the
  server; the owner chooses a password from an emailed link. Not yet run on production: the owner
  runs it with `--company="شركة مهتم" --slug=mohtam --admin-email=admin@mohtam.pro`. `*@mohtam.pro`
  is a catch-all into `info@`, set up from another session; its activation is not confirmed here.
- v0.9.11 (`dd2210d`, #262): phone navigation as an app. More is a sheet that rises from the bottom
  with every place as a card; a centre add-lead action; every screen fits a phone (tables that do
  not fit become cards, tab strips wrap, the pipeline shows one stage at a time, grids never exceed
  the screen). The mobile spec sweeps all 27 signed-in screens for page overflow and for anything
  that scrolls sideways; an audit of 39 screens passed on iPhone 14 WebKit and Pixel 7 Chromium.
- The "الريادة" company in screenshots and specs is the e2e fixture tenant, never production.
- v0.9.8 (`902dea8`) merged but never deployed: CI on main failed on one e2e spec. Shipped as v0.9.9.
  Tag `v0.9.9` was mis-pointed and corrected by the owner to `c3b99e8` on Nov 6.

## Proven live since v0.9.7

Stalled-email recovery: before the first scheduler tick the log read `sending=3 sent=99`; after it,
`sent=102` and no `sending`. The three `SendQueuedEmail / MaxAttemptsExceededException` entries
are the old ones and leave the 24-hour window on their own.

## Active pull requests

| PR | Branch | Title |
|---|---|---|

```bash
gh pr list --repo digitalalharbi/leadflow-crm --state open
gh pr checks <number> --repo digitalalharbi/leadflow-crm
```

## Commands that verify

```bash
cd backend  && ./vendor/bin/pint --test && php artisan test
cd frontend && npm run lint && npm run typecheck && npm run test && npm run build
cd frontend && npm run test:e2e
cd frontend && npm run check:charts
```

Backend tests run against **PostgreSQL**, never SQLite — the app uses `jsonb`, `ilike` and
aggregate `FILTER`.

CI is `.github/workflows/ci.yml`: Backend, Frontend and End-to-end as three reusable workflows
with one verdict job. **Green means the code is sound**; nothing about a server, a secret or a
domain can make it red. `deploy-staging.yml` and `deploy-production.yml` run no tests and report
`NOT CONFIGURED` rather than failing when their host variables are absent.

## Outstanding work and blockers

Requirement status is [`docs/REQUIREMENTS_MATRIX.md`](REQUIREMENTS_MATRIX.md). The owner-only
items are [`docs/OWNER_ACTIONS.md`](OWNER_ACTIONS.md) and
[`EXTERNAL_SETUP.md`](../EXTERNAL_SETUP.md); the credential inventory — names only, never values —
is [`docs/SECRETS_INVENTORY.md`](SECRETS_INVENTORY.md).

`NEXT_SESSION.md`, `BUILD_STATUS.md`, `GAP_MATRIX.md` and `DESIGN_PROGRESS.md` are earlier
narrative snapshots — `NEXT_SESSION.md` is dated 2026-10-07 and describes pull requests up to #45
while the repository is past #235. **Read them for intent, never for current state.**

## Decisions that outrank a recollection

- `origin/develop` is where work lands; `origin/main` is releases only.
- **Tenant isolation is the product.** `tenant_id` on every customer table, a global scope plus
  policies, and a valid UUID from another tenant must still answer not-found. Every change touching
  a query, a route or a policy names the test that proves it.
- No secret value in any file, commit, log or document.
- A Saudi phone number written five ways is one lead; never weaken duplicate detection.
- Losing a lead always requires a reason.
- **One session, one branch, one worktree.** `~/Developer/leadflow-crm` is the primary clone and a
  concurrent session may be working in it — cut a worktree instead of sharing the tree.
- Stage only the files you changed, by explicit path.

## Safe next actions

1. `git fetch origin` and read `docs/REQUIREMENTS_MATRIX.md` for the next open requirement.
2. Branch from the freshest `origin/develop`, in its own worktree.
3. Run the gate above and paste the real counts into the pull request.
4. Keep this file's *Last verified commit* and *Active pull requests* current at each milestone.
