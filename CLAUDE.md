# مهتم (Leads CRM) — session bootstrap

**`origin/main` and `origin/develop` on `https://github.com/digitalalharbi/leadflow-crm` are the
single source of truth.** Not a local branch, not a worktree, not anything said in an earlier
conversation. Where memory and Git disagree, Git is right and memory is stale.

`مهتم` is a multi-tenant, white-label CRM for teams that buy leads and have to call them back fast.
Laravel 12 API + React 19 SPA, PostgreSQL 16, Redis 7, Horizon. Arabic RTL by default, English LTR
supported. The full description is [`README.md`](README.md) — this file does not repeat it.

## Start of every session

```bash
git fetch origin
git log --oneline -10 origin/develop
git status
```

Then read, in this order — they are the project's own record and they outrank any recollection:

- @docs/PROJECT_STATE.md — the recovery checkpoint: last verified commit, open pull requests, the
  commands that verify, the standing blockers, the safe next action
- @docs/GITHUB_FLOW.md — how a change reaches `develop`, then `main`, then a server
- @docs/REQUIREMENTS_MATRIX.md — every requirement and its state
- @docs/MULTI_TENANCY.md — the isolation model, which is the one thing in this product that must
  never regress
- @docs/TESTING.md — what runs, and what each suite is responsible for
- @docs/SECURITY.md and @docs/POST_LAUNCH_SECURITY.md
- @README.md — the product, the stack, local setup, the integrations

`NEXT_SESSION.md`, `BUILD_STATUS.md`, `GAP_MATRIX.md` and `DESIGN_PROGRESS.md` are earlier narrative
snapshots. Read them for intent, never for current state — several are months old. If a document
contradicts the code, the code is the fact and the document is a defect worth fixing.

## How work reaches main

```
feat/* → PR → CI → merge to develop → staging (when configured) → develop → PR → main → production
```

- **`develop` is the integration branch.** Branch from it, open the pull request into it.
- **`main` is releases only.** It is never touched by development.
- Branch names: `feat/<scope>`, `fix/<scope>`, `chore/<scope>`.
- CI (`ci.yml`) runs on every pull request and on every push to `develop` and `main`: Backend
  (Pint, `composer validate`, migrations, PHPUnit on PostgreSQL, tenant isolation), Frontend
  (ESLint, strict TypeScript, Vitest, production build) and End-to-end (Playwright against the real
  API and SPA). **Green means the code is sound** — nothing about a server, a secret or a domain can
  make it red.
- `deploy-staging.yml` and `deploy-production.yml` run no tests and report `NOT CONFIGURED` rather
  than failing when their host variables are absent. A deploy that did not happen is never reported
  as one.

## Working alongside another session

- **One session, one branch, one worktree.** `~/Developer/leadflow-crm` is the primary clone and a
  concurrent session may be working in it; `git worktree add ../<name> -b <branch> origin/develop`
  rather than sharing a tree.
- Stage **only the files you changed, by explicit path** — never `git add .`, `-A`, or a whole
  directory. Before every commit: `git status --short` and `git diff --cached --name-only`.
- Never reset, restore, stash, checkout over or reformat another session's files. Format only what
  you changed.
- Never force-push, never delete a branch, never rewrite remote history.

## The gate

```bash
cd backend  && ./vendor/bin/pint --test && php artisan test
cd frontend && npm run lint && npm run typecheck && npm run test && npm run build
cd frontend && npm run test:e2e
```

Backend tests run against **PostgreSQL**, not SQLite: the app uses `jsonb`, `ilike` and aggregate
`FILTER`, none of which SQLite supports.

## The rules that are not negotiable

- **Tenant isolation is the product.** `tenant_id` on every customer table, a global scope plus
  policies, and a tenant cannot read another tenant's row even with a valid UUID. Every change that
  touches a query, a route or a policy says in its pull request which test proves one tenant cannot
  see another's data.
- **No secret value in any file, commit, log or document.** `docs/SECRETS_INVENTORY.md` is the
  inventory; values live on the server.
- **Nothing is hard-coded in the SPA that a tenant brands** — company name, product name, logo,
  favicon, colours and support details are read from the API at runtime.
- **A Saudi phone number written five ways is one lead.** Duplicate detection normalises
  `0512345678`, `512345678`, `966512345678`, `+966 51 234 5678` and `٠٥١٢٣٤٥٦٧٨` to one key. Never
  weaken it; a second row for a returning caller is a double-counted lead and an invented
  conversion rate.
- **Lost always requires a reason.** A pipeline that can lose a lead silently cannot report why.
- Do not fabricate business data. Demo and sandbox data is labelled as such.

## The owner's global policy

`~/.claude/CLAUDE.md` carries the owner's operating policy for every project. **This file outranks
it on anything it addresses** — it fills gaps, it never overrides a decision made here.

## Status vocabulary

`VERIFIED` built and proven here, evidence named · `IMPLEMENTED_NOT_VERIFIED` written, not proven —
not done · `AWAITING_CREDENTIALS` complete, needs a credential · `BLOCKED_EXTERNAL` waiting on the
owner or a provider · `LIVE_VERIFIED` real credentials, a real round trip, and the result visible in
the product. Never write `LIVE_VERIFIED`, or "deployed", without that external evidence.
