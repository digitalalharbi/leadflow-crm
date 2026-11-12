# Project state — مهتم (Leads CRM)

**What this file is.** The recovery checkpoint required by the owner's global operating policy: the
last verified commit, what is open, what verifies it, and the safe next action. Deliberately short
and mechanical, so a session resuming after context compaction knows exactly what it is resuming
from.

**What this file is not.** It is not the product narrative. [`README.md`](../README.md) describes
the product, [`docs/REQUIREMENTS_MATRIX.md`](REQUIREMENTS_MATRIX.md) carries requirement status,
and [`docs/GITHUB_FLOW.md`](GITHUB_FLOW.md) owns the branch and CI rules. Where this file and Git
disagree, **Git is right and this file is stale.**

Updated 2026-11-12 22:50 UTC.

## Last verified commit

| | |
|---|---|
| Repository | `digitalalharbi/leadflow-crm` |
| Integration branch | `develop` — `01fc399b58f7dfb524692d3c9276ca614dedec2e` (#275 bounce reader; all of it is in v0.9.15) |
| Full sha | `1642a0225981ec494d7efac591bdb085bb41f1ef` on `main` (Release v0.9.15, #276, tag `v0.9.15`) — **merged and tagged, NOT live** (see the blocker below) |
| Committed | 2026-11-08 |
| Release branch | `main` — v0.9.15 (#275 bounce reader, off by default). **Live is still v0.9.14** `98bda683af3f4d8ccf6e4913edbca07121a7fc53`: deploy run 42508592072 (CI 42508592082 green): migrations ran, restore check PASS (132 restored / 133 live), `FIRST_CLIENT_SMOKE=PASS`, `SMOKE: PASS` 9/9. `evidence email_failure` shows 74x 554 recipient-domain and 2x SMTP auth failures (historic, MAIL_PASSWORD rotation is owner-only). Checked by curl: `/pricing` 200 HTML, `/signup` 200 HTML, `/api/v1/public/plans` 200 JSON, `POST /api/v1/public/signup` 404, random password-link token 404 |

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

- v0.9.10 (`8471333`, #260): `mohtam:create-workspace`. **Run on production Nov 7, 20:18 UTC:**
  workspace `mohtam` ("شركة مهتم"), owner `admin@mohtam.pro`. Its setup email reached the `info@`
  inbox (not junk) two seconds later through the `*@mohtam.pro` catch-all, which therefore works.
  `MOHTAM_MARKETING_WORKSPACE=mohtam` was added to `.env.production` and deployed (run 42410866200:
  `active=3 marketing_workspace_set=yes`, smoke 9/9); `/api/v1/public/contact` now answers
  `form: true`. The owner chooses the password from the email; `mohtam-demo` stays active until the
  owner suspends it from the platform console (never delete).
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
| #277 | `feat/shared-report-branding` | Shared client report: save as PDF — CI 43307618260, Backend and Frontend green, Playwright running since 21:10 UTC |
| #278 | `feat/share-link-preview` | Shared report links preview as their own report in WhatsApp — first run 43314130596 failed its own nginx gate (an unquoted `{20,64}` cut the regex: `pcre2_compile() failed`); fixed by quoting it in `afc7c93`, run 43322641465 pending. Merge only when "Front-door nginx config parses and answers" is green |

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

**TOP BLOCKER — v0.9.15 deploy refused (owner-only, VPS side).** Deploy production run 43307147482 for
`1642a02` aborted at 2026-11-12 22:25 UTC in the step "Refuse a server whose working tree is not clean", exit 66,
before anything was fetched, built, migrated or restarted:

```
ABORT DEPLOY — the LeadFlow checkout is not clean:
     M docker-compose.production.yml
    ?? docker-compose.production.yml.bak-2026-11-10
```

Someone edited `docker-compose.production.yml` on the server on Nov 10 and kept a `.bak` copy. CI 43307147615 on the same
sha is green. Production therefore still runs v0.9.14; curl at 22:45 UTC: `/` 200, `/pricing` 200, `/signup` 200,
`/api/v1/public/plans` 200, `POST /api/v1/public/signup` 404, random password-link token 404. The agent cannot run
anything on the VPS. The owner, on the server in `/opt/leadflow-crm`: `git diff docker-compose.production.yml` to see the
change; either put it in a commit on GitHub (a PR to `develop`, then a release) or discard it (`git checkout --
docker-compose.production.yml`), and remove the `.bak-2026-11-10` file; then re-run the deploy from this Mac:
`gh workflow run deploy-production.yml --ref main -f confirm=deploy`. Note that `.env.production` is where server-only
settings belong; the compose file is tracked and must match Git.

**Sales funnel (#267, live since v0.9.12):** public `/pricing` (live plan catalogue, `GET /public/plans`), `/signup` as a
trial *request* (a demo lead through `/public/contact`), and `mohtam:demo-access` for the demo workspace. No public route creates a
company; `RealUseReadinessTest` pins it. `VERIFIED` by CI and by curl against production (see the release row above). `mohtam-demo` is
kept as the sales demo.

## التقرير النهائي لوكيل المتابعة — 2026-11-11

الطابور فارغ. المهمة المجدولة `mohtam-autopilot` **لم تُعطَّل**: التعطيل يتطلب موافقة المالك من التطبيق ولا يمكن للوكيل تنفيذه
في تشغيل آلي. على المالك تعطيلها يدوياً من قائمة المهام المجدولة.

- **المُسلَّم:** ‎#265 (سجل نظيف)، ‎#266 (المرسل الآلي `no-reply@mohtam.pro` والرد على `info@`)، ‎#267 (التسعير وطلب التجربة)،
  ‎#269 (توقيع الرسائل الآلية باسم مالك العميل)، ‎#271 (رسائل الترحيب والمتابعة تحمل بيانات المشروع). الإصدارات v0.9.12 و v0.9.13
  و v0.9.14.
- **الحي الآن:** v0.9.14 على `main` ‏`98bda683af3f4d8ccf6e4913edbca07121a7fc53` (الوسم `v0.9.14` على نفس الإيداع). تشغيل النشر
  42508592072 ناجح بعد CI ‏42508592082: الترحيلات نُفّذت، `RESTORE CHECK` ناجح، `FIRST_CLIENT_SMOKE=PASS`، ‏`SMOKE: PASS` ‏9/9.
- **فحص حي أُعيد في 2026-11-11 بـ curl:** ‏`/pricing` ‏200 HTML، ‏`/signup` ‏200 HTML، ‏`/api/v1/public/plans` ‏200 JSON،
  ‏`POST /api/v1/public/signup` ‏404، رابط كلمة مرور بـ 48 حرفاً عشوائياً 404.
- **غير مُثبت:** وصول بريد حقيقي بعد تغيير المرسل لم يُختبر (لا يُرسَل بريد من هذا الوكيل). سجل `email_failure` يحمل 74 رفضاً 554
  وفشلَي مصادقة SMTP قديمة.
- **للمالك فقط:** تدوير `MAIL_PASSWORD` في Hostinger وفي ملف البيئة على الخادم؛ تشغيل `mohtam:demo-access` على الخادم؛ تحديد أسعار
  الباقات من لوحة المنصة؛ دعوة الدخول عبر Google؛ قرار تعليق (لا حذف) مساحة العرض القديمة؛ وجهة النسخ الاحتياطي خارج الخادم؛ وأي أمر
  على الخادم.

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
