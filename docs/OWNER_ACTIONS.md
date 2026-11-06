# Owner actions — «مهتم»

Only things that need you: an account, a payment, a DNS record, an approval or a server key. Everything that can be
done in code is done. Each item says what to do and how to check it.

| # | Action | Where | How to check |
| --- | --- | --- | --- |
| 1 | **Create the first platform owner, if none exists yet.** On the VPS, in `/opt/leadflow-crm`: `docker compose -f docker-compose.production.yml --env-file .env.production exec backend php artisan mohtam:bootstrap-admin`. It asks for email, name and a hidden password, and running it twice changes nothing. Then sign in at `https://mohtam.pro/platform/login`; it asks for a second factor | VPS console | You reach the platform console |
| 2 | **`api.mohtam.pro` (optional, not a blocker: the app and its API answer on `https://mohtam.pro/api`).** TYPE: A / HOST: api / VALUE: 187.55.224.77 / TTL: 3600 / PURPOSE: a separate API address | Hostinger DNS | `dig +short api.mohtam.pro` returns the IP; TLS is then issued on the server |
| 2a | **Google sign-in: no setup to repeat.** It runs on the Google client already on the server, through the callback that client has registered. Needed once: press Google on the login page with the address of an existing account | `https://mohtam.pro/login` | You land in your workspace |
| 3 | **Resolved: email DNS for `mohtam.pro`.** Checked Nov 4, 2026: SPF includes Hostinger, DKIM `hostingermail-a/b/c` delegated to Hostinger, DMARC published with `p=none`, MX on Hostinger. Nothing to repeat. Later, once delivery is steady, tighten DMARC to `p=quarantine` | Hostinger DNS | `dig +short TXT _dmarc.mohtam.pro` |
| 4 | **Resolved: mail provider.** Production sends through Hostinger SMTP; 21 messages accepted in 7 days. Nothing to repeat | — | Deploy log, `evidence email` lines |
| 5 | **Resolved: `info@mohtam.pro` exists** and sends. Check once that someone reads it | Mail host | Send a message to it |
| 6 | **Resolved: GitHub billing.** Actions run and deploys pass | — | Actions tab |
| 7 | **Meta app** (WhatsApp Cloud API + Lead Ads): business verification, app review for `whatsapp_business_messaging`, `leads_retrieval`, `pages_manage_metadata`; then `META_APP_ID`, `META_APP_SECRET`, `META_VERIFY_TOKEN` | developers.facebook.com | Integrations → WhatsApp → Test connection |
| 8 | **WhatsApp message templates** approved by Meta (welcome, follow-up, appointment reminder, brochure) | WhatsApp Manager | Templates show "approved" in Integrations → WhatsApp |
| 9 | **Resolved: Google project for Sheets and sign-in.** 2 sheets sync, 430 leads, and the client is accepted. Add `GOOGLE_ADS_*` only if you want Google Ads | — | Deploy log, `google live_verified` |
| 10 | **TikTok / Snapchat / LinkedIn developer apps** (only those you will use): `TIKTOK_APP_ID/SECRET`, `SNAPCHAT_CLIENT_ID/SECRET`, `LINKEDIN_CLIENT_ID/SECRET` | Each platform's developer portal | Integrations → the platform card leaves "Awaiting credentials" |
| 11 | **Payment provider** for subscriptions (a Saudi-capable one: e.g. Moyasar, Tap, HyperPay) — choose and open the merchant account | Provider | Until then billing stays manual (the platform console records invoices) |
| 12 | **SMS / telephony provider** (optional): only if you want click-to-call or SMS OTP | Provider | Integrations → Calls → Test connection |
| 13 | **Off-server backup storage** (S3-compatible bucket) for `BACKUP_*` | Storage provider | `scripts/restore-check.sh` passes against a downloaded backup |
| 14 | **Plan prices** — the plans start without prices on purpose | Platform console → Plans | Revenue no longer reads "not configured" |
| 16 | **Google Calendar two-way sync** (optional): add the `https://www.googleapis.com/auth/calendar.events` scope to the Google OAuth consent screen and get it verified. Until then each person subscribes to their private calendar address (Profile → My visits in my calendar), which works with Google, Apple and Outlook today | console.cloud.google.com → OAuth consent screen | Readiness → Calendar shows “Google Calendar sync” checked |
| 17 | **Platform lead forms** (`BLOCKED_EXTERNAL`): the Meta, TikTok, Snapchat, Google Ads and LinkedIn apps in items 7, 9 and 10 are what let their forms be listed to choose from and their leads pulled. Until then a form is tied by typing its ID in Project → Lead Sources, and its leads arrive once its webhook or credentials exist | Each platform's developer portal | Project → Lead Sources: the form's card turns "Connected" and its lead count rises |
| 18 | **Link previews for landing pages** (optional, one line of server config): social apps read a page's title and picture without running its JavaScript. To show each page's own preview, have the proxy send `/l/*` from those crawlers to the API. Search engines and people already get each page's own title | Caddy on the VPS | Sharing `mohtam.pro/l/<page>` on WhatsApp shows that page's picture |
| 19 | **`go.mohtam.pro`** (optional): a separate address for campaign pages. Pages work on `mohtam.pro/l/<page>` without it | Hostinger DNS | `dig +short go.mohtam.pro` |
| 15 | **Named list of data processors** for the privacy page (hosting, mail, WhatsApp, AI) — the page says "available on request" until you publish one | Tell Claude the names | `/privacy#processors` lists them |

**Security: rotate `MAIL_PASSWORD` at Hostinger Email.** Line 131 of `/opt/leadflow-crm/.env.production` is `MAIL_PASSWORD`, the password of the mailbox `info@mohtam.pro` (named by the v0.9.6 deploy log, which prints names and never values). The failed v0.9.0 deploy printed part of it in a private GitHub Actions log, and the file has not changed since Nov 3, so that password is still the one in use. In hPanel: Emails, `info@mohtam.pro`, change password. Then put the new one on line 131 inside single quotes and re-run the deploy. Until both are done, mail keeps working on the old password.

**Mail failures explained, nothing to do.** 74 of 75 failures in 7 days went to demo accounts on a reserved test domain; from v0.9.5 those are written down and not sent.

**Landing pages proven in production** (Nov 5, on a labelled test page in the demo workspace, since paused). Publish your first real one when ready.

**Disk on the VPS.** v0.9.6 is live after you pruned on the server, but the disk sits at exactly the 18 GiB the deploy needs, so the next release may be refused again. Before the next deploy, as root: `docker image prune -f`, then `df -h /`. The deploy log now prints what holds the space.

**Still yours, in order.** (1) Rotate `MAIL_PASSWORD` as above. (2) Sign in once with Google. (3) Decide which workspace receives marketing enquiries: there are two, and the one named like Mohtam is the demo. (4) Off-server backups: set `BACKUP_UPLOAD_COMMAND`. (5) On your phone, signed in, open Work Now, More and Notifications once: this could not be emulated from here. (6) From an account with a confirmed email, make one snapshot of a report.

**Worth knowing, yours to decide.** In the demo workspace `mohtam-demo`: the sheet source has no default project and assignment is by hand, so a new sheet row becomes a lead with no project and no owner; and all nine users have addresses on a reserved test domain, so no alert email reaches anyone. In the other workspace, one user's address does not exist at its mail host: Gmail returns every message to it as "Address not found" (they collect in the Junk folder of `info@mohtam.pro`). Correct or remove that user.

**Marketing form.** To let the site's contact form create leads, add one line to `/opt/leadflow-crm/.env.production`: `MOHTAM_MARKETING_WORKSPACE=<your workspace slug>`. Until then the page offers email and phone. Check: `https://mohtam.pro/api/v1/public/contact` says `form: true`.

Deploy access is proven (read-only audit green, Nov 3, 2026) and needs nothing from you.

Public sign-up does not exist: there is no route for it, and a test keeps it that way. Companies are created in the platform console, people by invitation, the first owner by the command above.

Known and accepted for now, none blocks use:
- A Google Sheet's rows must be added at the bottom. Deleting or sorting rows above the read position can make the importer skip new ones.
- Once mail goes out, an unproven address cannot export, invite, create API keys or webhooks, share reports or change security policy until it is confirmed. Everyday work is not held back. Until then, nothing is held back, because nobody could confirm.
- Sessions stay bearer tokens for now: see `docs/POST_LAUNCH_SECURITY.md`.
- A visit has no unit picker, Work Now lists leads and not tasks, custom fields cannot be edited after creation, follow-ups have no delete button.

Not needed from you: code, tests, migrations, UI, documentation, deployment scripts.
