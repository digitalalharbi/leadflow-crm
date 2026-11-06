# Project state — مهتم (Leads CRM)

**What this file is.** The recovery checkpoint required by the owner's global operating policy: the
last verified commit, what is open, what verifies it, and the safe next action. Deliberately short
and mechanical, so a session resuming after context compaction knows exactly what it is resuming
from.

**What this file is not.** It is not the product narrative. [`README.md`](../README.md) describes
the product, [`docs/REQUIREMENTS_MATRIX.md`](REQUIREMENTS_MATRIX.md) carries requirement status,
and [`docs/GITHUB_FLOW.md`](GITHUB_FLOW.md) owns the branch and CI rules. Where this file and Git
disagree, **Git is right and this file is stale.**

Updated 2026-11-06.

## Last verified commit

| | |
|---|---|
| Repository | `digitalalharbi/leadflow-crm` |
| Integration branch | `develop` — `0c1e8bd`, same content as v0.9.7 |
| Full sha | `3f494db` on `main` (Release v0.9.7, #253, tag `v0.9.7`) |
| Committed | 2026-11-06 |
| Release branch | `main` — v0.9.7, CI green. **Not live:** the deploy (run 42156864332) was refused for disk before it changed anything. Production serves v0.9.6 `cd8c3a5` and is healthy |

`develop` is where development lands; `main` is releases only and is never touched by
development. Verify, do not trust:

```bash
git fetch origin && git log --oneline -10 origin/develop && git status
```

## Standing blocker — disk on the VPS (Nov 6, 2026)

`ABORT DEPLOY — less than 18 GiB is free on the deployment filesystem.` The server has 48G shared
by three projects. The refusal comes from the script already on the server, which runs before the
new one is checked out, and the deploy identity has no shell: only the owner, as root, can clear
it. On the VPS: `docker image prune -f`, then `df -h /` must show more than 18G available. Then:

```bash
gh workflow run deploy-production.yml --ref main -f confirm=deploy
```

The label-filtered prune from #251 reclaimed 0B on the server, so it does not solve this. From
v0.9.7 the deploy log prints `disk` lines (`docker system df`) and the free figure in the refusal.

## Waiting on that deploy (v0.9.7)

- `leadflow:recover-emails` every ten minutes and an SMTP timeout of 30s. Production still has
  three deliveries left as `sending` and `SendQueuedEmail / MaxAttemptsExceededException` three
  times in its last deploy log. The cause (a worker restarted mid-send) is inferred.
- After it deploys, read the log for `evidence email_7d … sending=` going to 0.

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
