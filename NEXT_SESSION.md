# Next session — مهتم

Updated 2026-10-07.

## Where things stand

`develop` carries PRs #15–#43. Nothing is on `main`.

Suites: **621 backend** (2067 assertions), **105 frontend unit**, **167 E2E**.
Typecheck, lint and build clean.

| Area | State |
|---|---|
| Automation engine, rules API, templates, builder, trace | Done (#19–#27) |
| Next Best Action, "اعمل الآن" work queue | Done (#20, #23) |
| Data quality centre, drill-down, share of workspace | Done (#26, #45) |
| Chart palette — measured, CVD-checked, drift-guarded | Done (#34) |
| Lead flow timeline, automation activity, stage timings | Done (#35, #37) |
| Rep performance, printing | Done (#38, #39) |
| Lead journey (per-lead milestones and gaps) | Done (#40) |
| Ingestion health — arrivals, duplicates, source staleness | Done (#41) |
| Category naming + Arabic interest scale | Done (#42) |
| **Pull-based sources actually working** | Done (#43) |
| عوامل الإغلاق — does speed/scoring predict a sale | #44, open |
| Data-quality share of workspace | #45, open |

## The one that mattered most

`fetch()` was implemented on five providers — Meta, TikTok, Snapchat, LinkedIn,
Google Sheets — and **called by nothing**. No command, no job, no schedule
entry. A workspace could paste a valid access token, watch the card go green,
and wait forever. The page also named the credentials each source needs and
gave you nowhere to type them, so four of seven providers were unreachable from
the interface entirely.

Both are fixed in #43: `LeadPuller`, `leadflow:pull-leads` every five minutes, a
«مزامنة الآن» button, and a credentials form. Verified against the live TikTok
Business API — the error comes back in TikTok's own words and `last_synced_at`
is correctly left null.

**This needs the Laravel scheduler running in production.** Without it, pull
sources deliver nothing and a valid token looks exactly like a wrong one.
`docs/DEPLOYMENT.md` now lists all five scheduled commands and what breaks
without each.

## Open, and worth doing next

### P1, in the order I would take them

1. **Offline conversions** — adapters exist; the queue and exporters are
   written. Nothing is sent until real ad credentials exist.
2. **Cost per qualified lead and ROAS** — blocked on ad-spend ingestion, the
   same credentials problem.
3. **Realtime** — new lead, reply, assignment, stage change. Everything polls
   today; the work screen in particular would feel different.
4. **Daily manager digest.** The numbers all exist; this is assembling and
   scheduling them.
5. **A partial unique index on leads** — see below, it is a real bug.

### P2

AI assist, calendar sync, extended providers.

## Things to be careful of

### Deleting a lead does not stick, and that is deliberate

This section used to say a soft-deleted lead blocks its phone number forever —
that the same person could never be re-added. **That was wrong**, and it had
been repeated from an earlier handover without anyone testing it.

`leads_tenant_phone_unique` really is a plain unique index with no
`where deleted_at is null`, and inserting a second row directly does violate it.
But nothing in the product inserts that row. `DuplicateMatcher` searches
`withTrashed()` on purpose, and `LeadIngestionService` **restores** the deleted
lead and marks it re-engaged. Both paths into the system — the webhook and
manual creation — go through it, so re-adding the number returns 200 and brings
the record back. The index is consistent with that design; making it partial
would enable a second row the application never wants.

The real defect was that the resurrection was silent. Somebody deletes a lead;
the number comes back; the record reappears in the list with the name, notes,
owner and history they removed, and the only thing they are told is that the
number "already exists" — about a record they cannot see. Fixed: the restore is
recorded on the lead's own timeline (`re_engaged` carries `restored`), and the
person typing the number in is told a deleted lead was brought back rather than
that a duplicate exists.

What is still a genuine decision, rather than a bug: whether a deleted lead
*should* come back at all, or whether deletion should mean the next arrival
starts a clean record. Restoring is right for inbound traffic — the person
really did come back. It is more arguable when the lead was deleted because the
number was wrong, or at somebody's request.

### Any sentence with two numbers in it is a plural bug

i18next picks its plural category from a single `count`, so a string holding two
counts silently skips pluralisation — no warning, no fallback, just slightly
wrong Arabic. Found again this session: the awaiting-contact heading
interpolated the workspace's configurable first-contact target into a fixed
`دقيقة`. At the default of 30 that is correct, which is exactly why it survived;
set the target to 3 and the dashboard said "3 دقيقة". `plurals.test.ts` pins the
cases.

### Lazy-loading violations do not surface under PHPUnit here

`AppServiceProvider` calls `Model::preventLazyLoading(! isProduction())`, so a
relation read without eager loading throws in local and staging. It does not
throw under `php artisan test`: a plainly lazy access on a freshly fetched
model fires nothing, verified by installing the violation callback by hand and
watching it stay silent.

That cost a real bug. `DailyDigest` read `$tenant->settings` directly; every
test passed, because their tenant came from a fixture that had already loaded
the relation — and the command then threw for every workspace and sent nothing.
Running it was the only thing that showed it.

So: **run commands, do not trust the suite for this class of bug.** A test that
claims to guard a lazy load in this project is guarding nothing, and the one
written for the digest says so in its own docblock rather than implying
otherwise.

### Never scrape a card's text for a number

Twice in one session a test read a whole card, stripped non-digits, and got a
number that was never on screen. The metric tile renders its figure *and* a
period-over-period change, so "39" and "87.5%" became **398750**. The data
quality card did the same with "10" and "10 عملاء من 43" → **1010**. Both
passed for a while because they compared one mangled number against another.
Values that a test needs now carry their own `data-testid`.

### Fixtures are part of the product

Three screens in a row could not be exercised because the fixtures never
produced the data they read:

* **Velocity** — `won_at` was set to an absolute date, so every won lead closed
  three days *before* it was created. The chart silently drops any step whose
  end precedes its start, so two of three steps showed a dash in every run.
* **Ingestion** — no `lead_source_events` were seeded at all.
* **عوامل الإغلاق** — every lead was under three weeks old, so nothing was
  mature enough to count.

And the windows fight each other: the dashboard measures the last thirty days,
the conversion analysis counts only leads older than that. Half the closed
leads now sit in each. Aging all of them is exactly how the velocity chart went
back to a dash.

### GitHub will not run CI on a conflicting PR

A stacked PR targeting another branch gets no workflow run at all — they are
scoped to PRs into `main`/`develop`. Retargeting does not help on its own,
because the head commit has not changed, and an empty commit does not help
either when the branch conflicts with its base. **Merge the base in and resolve
first**; CI starts the moment the PR is mergeable.

Also: **retarget the child before merging the parent.** Deleting a base branch
closes its child PR rather than retargeting it — #39 had to be reopened after
recreating the deleted ref.

## Still blocked on you

### Deployment

1. **Server IP** and the **SSH user** to deploy as.
2. A **deploy SSH key you generate** — do not paste the private key into a chat;
   add the public half to the server's `authorized_keys` and the private half to
   the GitHub secret yourself.
3. The five GitHub Actions secrets named in `EXTERNAL_SETUP.md`.

The staging deploy currently fails on every merge to `develop`, by design: it
checks for `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY` and `DEPLOY_PATH_SECRET` and
exits non-zero rather than reporting a green deploy that shipped nothing.
`scripts/check-deploy-readiness.sh` prints which are missing by name and never
prints a value.

### Live ad sources

Real credentials for Meta, TikTok, Snapchat, LinkedIn or Google Sheets. Each
needs someone with access to that ad account to create an app, grant scopes and
generate a token; none of it can be done from مهتم, and no part of مهتم asks for
an ad-account password.

`docs/CONNECTING_SOURCES.md` is the runbook: which keys per platform, where each
comes from, how to read a sync result, and what to check when a source stops.

The generic webhook needs **no credentials** and works today — it is the fastest
way to prove the whole pipeline end to end.

## Running it locally

Another project on this machine owns ports **8000** and **5173/5174**, and
Playwright's `reuseExistingServer` will happily run the whole suite against it.
Use explicit free ports:

```bash
cd backend && php artisan serve --host=127.0.0.1 --port=8012
cd frontend && VITE_PROXY_TARGET=http://127.0.0.1:8012 \
  npx vite --port 5301 --host 127.0.0.1 --strictPort
```

```bash
E2E_NO_SERVER=1 E2E_BASE_URL=http://127.0.0.1:5301 \
  E2E_API_URL=http://127.0.0.1:8012 npx playwright test
```

`--host` matters: Vite binds IPv6-only otherwise and Playwright cannot reach it.
`VITE_PROXY_TARGET` matters too — the dev server's default proxy target is 8000,
which is the other project.

Start both servers as long-lived processes, not as `nohup … &` from a one-shot
shell. A server that dies mid-run fails eighteen tests with `ECONNREFUSED` and
looks exactly like a code regression. Check
`curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8012/api/health`
before any long run.

## CI stopped: GitHub Actions billing (2026-10-08)

Every workflow began failing within seconds, on all three jobs, with:

> The job was not started because recent account payments have failed or your
> spending limit needs to be increased.

Not a code failure. The same commits pass locally, and the runs that started
before the cutoff passed in CI. The fix is in GitHub → Settings → Billing &
plans: repair the payment method or raise the Actions spending limit.

Left open and unverifiable until then — do not merge on local green alone, CI is
the arbiter:

- **#65** SLA reminders reach a person. Backend and Playwright passed; the one
  red check was the `format.test.ts` flake, which #67 has since fixed on
  `develop`. Merge `develop` in and it needs a single re-run.
- **#66** reports stop claiming "no data" when the answer was 403.
- **#68** a rule can name the rep it assigns to — and closes a cross-tenant hole
  in `ActionExecutor`, which looked a named rep up by uuid with no tenant
  filter. `User` is deliberately outside the tenant scope, so that handed leads
  to people in other workspaces. Worth getting in.
