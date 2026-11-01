# Secrets inventory

Names only — never values. **Owner** says whose credential it is: the *platform* (one per installation, in the server
`.env`) or a *workspace* (entered by that company's admin in Integrations, encrypted at rest, never returned by the API,
never logged, shown masked).

| Name | Owner | Used for | Required for launch |
| --- | --- | --- | --- |
| `APP_KEY` | platform | encryption of every stored credential | yes |
| `DB_PASSWORD` | platform | PostgreSQL | yes |
| `REDIS_PASSWORD` | platform | cache, queue, sessions | if Redis requires auth |
| `DEPLOY_SECRET` | platform | `/api/health` reveals the serving release only with it | yes |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_APP_ID` | platform | live updates | yes |
| `SUPER_ADMIN_PASSWORD` | platform | first sign-in (rotate after; MFA required) | first run only |
| `MAIL_MAILER`, `RESEND_API_KEY` *or* `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`/`AWS_DEFAULT_REGION` (SES) *or* `MAIL_HOST`/`MAIL_USERNAME`/`MAIL_PASSWORD` (SMTP) | platform | account and operational email | yes (else mail is logged, not sent) |
| `MAIL_FROM_ADDRESS` (=`info@mohtam.pro`), `MAIL_FROM_NAME` (=`مهتم`), `MAIL_DKIM_SELECTOR` | platform | sender identity and DNS check | yes |
| `META_APP_ID`, `META_APP_SECRET`, `META_VERIFY_TOKEN` | platform | Meta app: Lead Ads, WhatsApp webhooks | for Meta / WhatsApp |
| WhatsApp `access_token`, `phone_number_id`, `waba_id`, `app_secret`, `verify_token` | workspace | the company's own WhatsApp Business number | per company |
| `GOOGLE_SHEETS_CLIENT_ID`, `GOOGLE_SHEETS_CLIENT_SECRET`, `GOOGLE_PICKER_API_KEY`, `GOOGLE_PROJECT_NUMBER` | platform | Google Sheets import | for Sheets |
| Google OAuth tokens (access/refresh) | workspace | each company's Sheets connection | per company |
| `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, `GOOGLE_ADS_DEVELOPER_TOKEN` | platform | Google Ads | optional |
| `TIKTOK_APP_ID`, `TIKTOK_APP_SECRET` | platform | TikTok Lead Generation | optional |
| `SNAPCHAT_CLIENT_ID`, `SNAPCHAT_CLIENT_SECRET` | platform | Snapchat | optional |
| `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET` | platform | LinkedIn | optional |
| Ad-account OAuth tokens | workspace | each company's ad accounts | per company |
| Tracking destination keys (GA4 API secret, Meta CAPI token, TikTok token, webhook signing secret) | workspace | conversions out | per company |
| Calls provider `account_sid`, `auth_token` | workspace | click-to-call | optional |
| Workspace API keys (`mht_…`) | workspace | the public API — stored only as SHA-256 | per company |
| Outbound webhook secrets (`whsec_…`) | workspace | signing deliveries — encrypted | per company |
| `ANTHROPIC_API_KEY` | platform | AI drafting/summaries | optional |
| `SENTRY_LARAVEL_DSN` | platform | error reporting | optional |
| `AWS_*` for backups (`BACKUP_*` bucket) | platform | off-server backups | before go-live |

Rules enforced in code: `AuditLogger` redacts any key containing password/secret/token/credentials/api_key; credentials
use Laravel's `encrypted` casts; API resources expose `has_token` / hints, never the value.
