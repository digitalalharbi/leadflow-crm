# Pilot runbook — from "deployed" to "live verified"

Everything here is the part of going live that needs a person, a server or a
provider. It is ordered, and each step says what *evidence* closes it: a row on
the Production readiness screen becomes **Live verified** only when something
real has happened, never because a value was entered or a check passed.

Run it in order. Stop at the first step that does not produce its evidence — a
later green on top of an earlier unknown is not a result.

## 0. What has to exist first

| Needs | From |
| --- | --- |
| A fresh Ubuntu server, root access once | you |
| A domain with DNS pointing at it | you |
| A Google Cloud project: OAuth client, picker API key, project number | you |
| A Meta app with a WhatsApp number, system-user token, app secret, one approved template | the client |
| A Twilio account, a number, geo-permission for Saudi Arabia | the client |
| API access for whichever ad platforms the pilot uses | you / the client |

## 1. The server

```bash
# On the server, as root, once
bash provision-server.sh <github-owner>
```

Then supply the two things the script cannot: the deploy key's **public** half in
`/home/deploy/.ssh/authorized_keys`, and `.env.production` (copy the example,
fill `APP_KEY`, `DB_PASSWORD`, the domains, `SUPER_ADMIN_PASSWORD`). The private
key stays on your machine, as a path in `.deploy.env`.

Provisioning also installs, without being asked:

- a daily database dump at 02:17 UTC (production) and 02:47 (staging), kept 14 days;
- log rotation on every container (20 MB × 5 files each);
- a firewall allowing SSH, 80 and 443 only.

**Two decisions that are yours, and that the script does not make:**

1. **Off-server backups.** A dump on the same disk survives a bad migration and
   does not survive the disk. Set `BACKUP_UPLOAD_COMMAND` in `.env.production`
   (it receives the file path as `$1`, e.g. `rclone copyto "$1" remote:leadflow/`).
   A failed upload fails the cron job; it does not pass silently.
2. **Alerts.** Set `LOG_STACK=stderr,slack` and `LOG_SLACK_WEBHOOK_URL`. Only
   `error` and above is sent (`LOG_ALERT_LEVEL` to change it).

## 2. Deploy

```bash
cp .deploy.env.example .deploy.env     # fill DEPLOY_HOST, DEPLOY_USER, DEPLOY_SSH_KEY, domains
./scripts/verify-all.sh                # the tests; deploy refuses without it
./scripts/deploy-direct.sh staging
```

Production is deployed only by the **Deploy production** workflow, triggered by a push to `main`
(docs/PRODUCTION_RUNBOOK.md); `deploy-direct.sh production` refuses.

A deploy is complete only when the **public HTTPS domain** answers with the new
commit. The script verifies that, and treats "the container is up" as not enough.

## 3. Platform rows — no credentials involved

On the server:

```bash
php artisan leadflow:readiness
```

| Row | Closed by | Wait |
| --- | --- | --- |
| VPS | scheduler **and** queue worker pulses, in production mode with debug off | ~3 minutes after boot |
| Domain | a secure request arriving on the configured name — open `/readiness` over HTTPS | immediate |

If VPS stays at *ready*: `docker compose logs scheduler horizon`. The pulse is
`leadflow:heartbeat` each minute plus a queued job that horizon must run.

## 4. Credentials, then commissioning

Sign in as the workspace admin and open **Integrations**, then **Production
readiness**. For each integration: enter the credential, press **Run the check**.
A check that passes moves the row to *ready* and **no further**.

| Integration | Enter | The check asks |
| --- | --- | --- |
| Google | `GOOGLE_SHEETS_CLIENT_ID/SECRET`, `GOOGLE_PICKER_API_KEY`, `GOOGLE_PROJECT_NUMBER` in `.env.production`; then *Connect a Google account* in the screen | Google to accept the client; renews the token; confirms scope is `drive.file` |
| WhatsApp | Integrations → WhatsApp card; register the approved template name on a saved message | Meta to describe the number — compare the business name that comes back with yours |
| Twilio | Integrations → Telephony | Twilio to describe the account; no call is placed |
| Ad platforms | each platform's card | pulls from each — real leads, ingested; webhook-only platforms report that they call us |

## 5. Evidence — what makes a row Live verified

Use a **pilot workspace and a pilot project**. These steps create real records.

| Row | Do this once | Evidence recorded |
| --- | --- | --- |
| Google | Create a sheet with one test lead; *Choose a file in Google*, map, import | a completed sync that read rows |
| WhatsApp | Send a message to a team phone from a lead; reply from the phone | a delivery receipt, and an inbound message |
| Twilio | Press *Call* on a lead whose number is a team phone; rep answers, then ends | a call with a **measured** duration from Twilio |
| Ads | Submit a lead through a real form of one platform (Meta's lead-ads testing tool counts) | a lead carrying that platform's own id |

If a step does not produce its evidence the row stays *ready* — that is the screen
working, and the reason is in the row.

## 6. The smoke test

`PilotSmokePathTest` walks this path against the demo providers on every run, so
a failure here is about the credentials or the environment and not a discovery
about the product. Walk it by hand once, live, with team phones:

1. **Lead** — submit a test lead from the ad form
2. **Source** — it shows the platform and campaign
3. **Dedup** — submit the same phone again; still one lead
4. **Project** — it carries the pilot project
5. **Assignment** — it has an owner, and the rule's first follow-up exists
6. **WhatsApp** — message it; reply from the phone; the conversation shows both
7. **File** — send the brochure; open the link on the phone; opens = 1
8. **Call** — call it; the call shows status and duration
9. **Follow-up** — complete the one the rule booked
10. **Appointment** — book one; the lead moves to *appointment*
11. **Won / Lost** — close one as won **with a value**, another as lost with a reason
12. **Revenue** — the campaign shows the won value and not the lost lead

## 7. Pilot is open when

- every row is **Live verified**, or is marked out of scope for this pilot by
  name (an ad platform nobody is using is not a blocker, and should not be
  entered just to turn a row green);
- the twelve steps above ran once, live;
- a backup was **restored** to a scratch database once (`scripts/restore-db.sh`).
  A dump nobody has read back is a hope.

After that, no development until real use produces a defect.

## Rolling back

```bash
./scripts/rollback.sh production          # previous release
./scripts/restore-db.sh production <dump> # only if a migration damaged data
```

Rolling back code never rolls back the database: a migration that ran stays run.
That is why a dump is taken immediately before every deploy that migrates.
