# Owner actions — «مهتم»

Only things that need you: an account, a payment, a DNS record, an approval or a server key. Everything that can be
done in code is done. Each item says what to do and how to check it.

| # | Action | Where | How to check |
| --- | --- | --- | --- |
| 1 | **Create the first platform owner, if none exists yet.** On the VPS, in `/opt/leadflow-crm`: `docker compose -f docker-compose.production.yml --env-file .env.production exec backend php artisan mohtam:bootstrap-admin`. It asks for email, name and a hidden password, and running it twice changes nothing. Then sign in at `https://mohtam.pro/platform/login`; it asks for a second factor | VPS console | You reach the platform console |
| 2 | **`api.mohtam.pro` (optional, not a blocker: the app and its API answer on `https://mohtam.pro/api`).** TYPE: A / HOST: api / VALUE: 187.55.224.77 / TTL: 3600 / PURPOSE: a separate API address | Hostinger DNS | `dig +short api.mohtam.pro` returns the IP; TLS is then issued on the server |
| 2a | **Google sign-in.** In Google Cloud, an OAuth client of type Web with this exact redirect URI: `https://mohtam.pro/api/v1/auth/google/callback`. On the server, in `.env.production`: `GOOGLE_LOGIN_CLIENT_ID` and `GOOGLE_LOGIN_CLIENT_SECRET`, then restart. The button appears by itself. It signs in existing people only and never creates an account | console.cloud.google.com + VPS console | `curl https://mohtam.pro/api/v1/auth/providers` lists google |
| 3 | **BLOCKED_EXTERNAL: email DNS for `mohtam.pro`.** The values come from the provider in item 4; none is invented here. For each: TYPE: TXT / HOST: `@` / VALUE: the provider's SPF / TTL: 3600 / PURPOSE: SPF. TYPE: TXT or CNAME / HOST: the provider's DKIM host / VALUE: the provider's key / TTL: 3600 / PURPOSE: DKIM. TYPE: TXT / HOST: `_dmarc` / VALUE: your chosen policy / TTL: 3600 / PURPOSE: DMARC. TYPE: MX / HOST: `@` / VALUE: your mailbox host's server (item 5) / TTL: 3600 / PURPOSE: receiving at info@ | Hostinger DNS + mail provider | `php artisan mohtam:email-dns mohtam.pro --selector=<provider selector>` prints READY |
| 4 | **BLOCKED_EXTERNAL: MAIL PROVIDER.** Needed: a provider account (Resend, AWS SES or any SMTP service), `mohtam.pro` verified as the sender, and its key. On the server, in `/opt/leadflow-crm/.env.production`: `MAIL_MAILER=resend` with `RESEND_API_KEY=...` (or `MAIL_MAILER=ses` with the `AWS_*` keys, or `MAIL_MAILER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`), plus `MAIL_FROM_ADDRESS=info@mohtam.pro` and `MAIL_DKIM_SELECTOR`. Then restart: `docker compose -f docker-compose.production.yml --env-file .env.production up -d`. Until then invitations and owner links are handed to the person who asked, and forgot-password reaches nobody | Provider dashboard + VPS console | The next deploy's log shows it in Readiness; a test email arrives at info@mohtam.pro |
| 5 | **A mailbox for `info@mohtam.pro`** that someone reads (support, privacy, security reports go there) | Mail host | Send a message to it |
| 6 | **GitHub billing** — Actions jobs are refused: "recent account payments have failed or your spending limit needs to be increased" | github.com → Settings → Billing & plans | A CI run starts on the next push |
| 7 | **Meta app** (WhatsApp Cloud API + Lead Ads): business verification, app review for `whatsapp_business_messaging`, `leads_retrieval`, `pages_manage_metadata`; then `META_APP_ID`, `META_APP_SECRET`, `META_VERIFY_TOKEN` | developers.facebook.com | Integrations → WhatsApp → Test connection |
| 8 | **WhatsApp message templates** approved by Meta (welcome, follow-up, appointment reminder, brochure) | WhatsApp Manager | Templates show "approved" in Integrations → WhatsApp |
| 9 | **Google Cloud project** with OAuth consent screen verified; `GOOGLE_SHEETS_CLIENT_ID/SECRET`, `GOOGLE_PICKER_API_KEY`, `GOOGLE_PROJECT_NUMBER` (and Google Ads developer token if used) | console.cloud.google.com | Integrations → Google Sheets → Connect |
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

Deploy access is proven (read-only audit green, Nov 3, 2026) and needs nothing from you.

Public sign-up does not exist: there is no route for it, and a test keeps it that way. Companies are created in the platform console, people by invitation, the first owner by the command above.

Known and accepted for now, none blocks use:
- A Google Sheet's rows must be added at the bottom. Deleting or sorting rows above the read position can make the importer skip new ones.
- Once mail goes out, an unproven address cannot export, invite, create API keys or webhooks, share reports or change security policy until it is confirmed. Everyday work is not held back. Until then, nothing is held back, because nobody could confirm.
- Sessions stay bearer tokens for now: see `docs/POST_LAUNCH_SECURITY.md`.
- A visit has no unit picker, Work Now lists leads and not tasks, custom fields cannot be edited after creation, follow-ups have no delete button.

Not needed from you: code, tests, migrations, UI, documentation, deployment scripts.
