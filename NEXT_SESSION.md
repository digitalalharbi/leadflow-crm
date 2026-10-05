# Next session — مهتم

Updated 2026-10-05.

## Where things stand

`develop` carries PRs #15–#27. Nothing is on `main`.

| Area | State |
|---|---|
| Automation engine, events, SLA sweeper | Done (#19) |
| Rules API, closed vocabulary, policy | Done (#21) |
| Six rule templates, `notify`, `stop_follow_ups` | Done (#24) |
| Rules screen: gallery, builder, preview | Done (#25) |
| Next Best Action + work queue API | Done (#20) |
| "اعمل الآن": one-press outcome loop, shortcuts | Done (#23) |
| Contact-state invariant (3 levels) | Done (#22) |
| Data quality centre + drill-down | Done (#26) |
| Automation trace on the lead page | Done (#27) |
| Missed follow-ups bucket | This branch |

Suites at the time of writing: **509 backend**, **62 frontend**, **~100 E2E**.
Typecheck and lint clean.

## Open, and worth doing next

### P1, in the order I would take them

1. **Offline conversions** — adapters for Meta / Google / TikTok / Snapchat.
   Build the adapter and the queue; send nothing until real credentials exist.
2. **Cost per qualified lead and ROAS** — blocked on ad-spend ingestion, which
   is the same credentials problem as above.
3. **Realtime** — new lead, reply, assignment, stage change. Everything polls
   today; the work screen in particular would feel different.
4. **Global search / command palette.**
5. **Onboarding wizard + traceable test lead** (spec items 24 and 25). The
   trace from #27 is the hard half and it already exists.
6. **Daily manager digest** (item 18). The numbers all exist; this is
   assembling and scheduling them.

### P2

AI assist, calendar sync, extended providers.

### Deliberately not done

**Delay and branch in automation rules** (part of spec item 5). A delay means a
scheduled job per rule step with its own retry and cancellation semantics —
"wait 3 days then message, unless the lead replied" needs the *unless*, or it
becomes the thing that messages customers after they have already bought. It is
a day's work done properly and a liability done quickly.

**Draft/Published** is implemented as enable/disable, which is the same control
under a different word. Say so rather than adding a second flag.

## Three things to be careful of

### A test failed once and I could not reproduce it

During #24 a single unnamed test failed in a run that took 97 seconds against a
normal 35. Nine subsequent runs, including randomised order, were clean. It
smells like something time-sensitive — there is precedent in this repo
(`FollowUpTest` used to fail for two hours a day). If it reappears, capture the
name before rerunning.

### A soft-deleted lead blocks its phone number forever

`leads_tenant_phone_unique` is a plain unique index on `(tenant_id,
phone_normalized)`, not a partial one. A soft-deleted lead keeps occupying it,
so the same person can never be re-added after deletion — they would hit a
constraint violation rather than a duplicate warning. Worth a partial unique
index `where deleted_at is null`, with a deliberate decision about what
re-adding a deleted lead should do.

### Any sentence with two numbers in it is a plural bug

i18next picks its plural category from a single `count`, so a string holding two
counts silently skips pluralisation entirely — no warning, no fallback, just
slightly wrong Arabic. It happened three times in one day:
`{{conditions}} شرط · {{actions}} إجراء`, `تنطبق على {{count}} من آخر {{sampled}}`,
and `بقي {{count}} عميل`. Split them into two keys and join. `plurals.test.ts`
pins 19 cases.

## Still blocked on you

Deployment cannot proceed past this point without:

1. **Server IP** and the **SSH user** to deploy as.
2. A **deploy SSH key you generate** — do not paste the private key into the
   chat; add the public half to the server's `authorized_keys` and the private
   half to the GitHub secret yourself.
3. The five GitHub Actions secrets named in `EXTERNAL_SETUP.md`.

For the advertising integrations, separately: real API credentials for Meta,
Google, TikTok or Snapchat. The adapters can be written and tested without
them; nothing will be sent to a live ad account until you provide them.

`scripts/check-deploy-readiness.sh` prints which secrets are missing by name and
never prints a value.

## One local gotcha

Another project on this machine serves ports 5173 and 5174, and Playwright's
`reuseExistingServer: true` will happily run the whole suite against it — every
spec fails with a baffling `page-title not found`. Run E2E with an explicit free
port:

```
E2E_NO_SERVER=1 E2E_BASE_URL=http://127.0.0.1:5301 npx playwright test
```

with `npm run dev -- --port 5301 --host 127.0.0.1 --strictPort` alongside. The
`--host` matters: Vite binds IPv6-only otherwise and Playwright cannot reach it.
