# Owner actions — «مهتم»

Only things that need you: an account, a payment, a DNS record, an approval or a server key. Everything that can be
done in code is done. Each item says what to do and how to check it.

| # | Action | Where | How to check |
| --- | --- | --- | --- |
| 1 | **Authorize the deploy key for `leadflow-deploy`.** Add this public key to `/home/leadflow-deploy/.ssh/authorized_keys` (owner `leadflow-deploy`, mode 600) from the hosting panel's console: `ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP5F1WWvCMlIBnF0p9ZNDnUZj1pEIIvw3xMFWZMVHBCg mohtam-deploy` | VPS console (Hostinger) | Tell Claude; one SSH check, then deploy from the release tag |
| 2 | **DNS: `api.mohtam.pro`** A record → `187.55.224.77` | Hostinger DNS | `dig +short api.mohtam.pro` returns the IP; TLS is then issued on the server |
| 3 | **Email DNS for `mohtam.pro`**: SPF, DKIM (from your mail provider), DMARC, MX — none exist today | Hostinger DNS + mail provider | `php artisan mohtam:email-dns mohtam.pro --selector=<provider selector>` prints READY |
| 4 | **Mail provider account** (Resend or AWS SES) with `mohtam.pro` verified; put the key in the server `.env` (`MAIL_MAILER`, `RESEND_API_KEY` or SES keys, `MAIL_DKIM_SELECTOR`) | Provider dashboard | Readiness → Email; a test email arrives at info@mohtam.pro |
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
| 15 | **Named list of data processors** for the privacy page (hosting, mail, WhatsApp, AI) — the page says "available on request" until you publish one | Tell Claude the names | `/privacy#processors` lists them |

Not needed from you: code, tests, migrations, UI, documentation, deployment scripts.
