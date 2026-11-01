# GitHub flow, CI and deploys

One flow, the same in every project (CampaignsHub, digitalSmart, مهتم):

```
LOCAL → PUSH → PR → CI → MERGE TO develop → STAGING (when available) → PRODUCTION (when approved)
```

| | |
|---|---|
| `feat/*` | all development. Branch from `develop`, open a PR into `develop`. |
| `develop` | the integration branch. CI runs on every PR and on every push. |
| `main` | releases only. Never touched by development. |

## CI is separate from deploy

**`ci.yml` — "CI"** runs on every pull request and on every push to `develop` and `main`.
It calls three reusable workflows and has one verdict job:

| Job | What it runs |
|---|---|
| Backend | Pint, composer validate, migrations, PHPUnit (PostgreSQL), tenant isolation |
| Frontend | ESLint, TypeScript (strict), Vitest, production build |
| End-to-end | Playwright against the real API and the real SPA, synchronous queue |
| CI result | fails only if one of the three really failed |

CI decides whether the code is healthy. **GREEN means the code is sound.** Nothing about a
server, a secret or a domain can make it red.

**`deploy-staging.yml`** (push to `develop`, or by hand) never runs tests. It asks one question —
are `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`, `STAGING_PATH` and the variable `STAGING_DOMAIN` set?

- not set → the run ends with **STAGING NOT CONFIGURED** (job summary + a notice annotation).
  Nothing was deployed, and nothing about `develop`'s health changes;
- set → it waits for CI on that commit (`scripts/ci/ci-is-green.sh`), deploys only a commit CI
  passed, and verifies the new build over HTTPS. **A deploy that is attempted and fails is red.**

**`deploy-production.yml`** runs automatically on every push/merge to `main`; `workflow_dispatch`
with the confirmation `deploy` remains available for an explicit re-run. It runs only when the owner
has set the variable `PRODUCTION_ENABLED=true` after the server,
domain, HTTPS, secrets and backups exist (`docs/PRODUCTION_RUNBOOK.md`); otherwise it ends with
**PRODUCTION NOT CONFIGURED**. It refuses a commit CI has not passed.

## Status words

```
GREEN    healthy
AMBER    waiting, or not configured yet — a state to fix, never a failure
BLOCKED  GitHub Actions cannot start (billing) — not a failure of the code
RED      a real failure: a test, a build, a workflow, or a deploy that was attempted and failed
```

A missing VPS, secret or credential is **AMBER**. A billing hold is **BLOCKED — BILLING**: the jobs
never start, so nothing is known about the code, and the workflows are not changed to hide that.

```bash
./scripts/github-status.sh          # Repository, Fetch, Push, PR flow, Backend/Frontend/E2E CI,
                                    # Develop, Latest commit, Staging, Production
./scripts/github-status.sh --json
```

It exits non-zero only when something is RED. It is read-only.

## What is the same across the three systems, and what is not

| | CampaignsHub | digitalSmart | مهتم |
|---|---|---|---|
| CI workflow name | `CI` | `CI` | `CI` |
| CI jobs | backend / frontend (+ e2e) | backend / frontend / e2e | backend / frontend / e2e + `CI result` |
| CI triggers | pull request | PR, push `main`, merge queue | PR, push `develop` and `main` |
| Deploy separate from CI | yes | yes | yes |
| Production trigger | push to `main` | push to `main`, gated by `PRODUCTION_ENABLED` | push to `main`, gated by `PRODUCTION_ENABLED` |
| "Not configured" | — | `awaiting` job (success + summary) | `config`/`awaiting` job (success + notice) |
| Integration branch | `main` | `main` | `develop` (`main` = releases) |
| Branch protection / required checks | public repo: available | public repo: available | **unavailable on this plan** (private repo) — the PR flow is a convention until the plan allows it |
| Actions minutes | public: free | public: free | private: **billing applies** |

The deliberate branch difference is that مهتم keeps `develop` as the integration branch and
`main` for releases. A merge to `main` is the production trigger.
