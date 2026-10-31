# Email runbook

**Sender:** `مهتم <info@mohtam.pro>` (`MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`). **Providers:** SMTP, AWS SES, Resend, or a
workspace's own SMTP — chosen by `MAIL_MAILER` / the workspace's email card; no provider is hard-coded. In tests and
until configured, the `log` mailer records mail as *logged*, never as *sent*.

## Going live
1. Open the provider account; verify `mohtam.pro` there.
2. Publish in Hostinger DNS what the provider shows: SPF (one `v=spf1` TXT at the apex — merge, never add a second),
   DKIM (TXT or CNAME under `<selector>._domainkey`), DMARC (`_dmarc` TXT, start with `p=none; rua=mailto:info@mohtam.pro`,
   move to `quarantine` after two clean weeks), and MX for replies.
3. Set `MAIL_MAILER`, the provider key, `MAIL_DKIM_SELECTOR` in the server `.env`; `php artisan config:cache`.
4. `php artisan mohtam:email-dns` must print **READY** (exits 1 otherwise). Same data: `GET /api/v1/readiness/email-dns`.
5. Send a test from Integrations → Email; check it lands in the inbox, not spam, at info@mohtam.pro.

## Kinds of mail
- Transactional (invitation, password link, account setup, security alert): always sent; no unsubscribe.
- Operational (new lead, SLA, follow-up, assignment, visit, reservation) and digests (daily/weekly/monthly): per-person
  preferences (Profile → Notifications).
- Marketing automation (nurture, brochure, reminder): carries an unsubscribe link (`/e/u/<token>`); unsubscribing stamps
  the lead and adds the address to the workspace suppression list.

## Bounces and complaints
Provider events arrive at the inbound email webhook; a bounce stamps `email_bounced_at` and adds the address to the
suppression list (reason `bounced`). Nothing is retried to a bounced address.

## Troubleshooting
| Symptom | Check |
| --- | --- |
| "logged", not "sent" | `MAIL_MAILER` is `log` or the key is missing — Readiness → Email |
| Lands in spam | `mohtam:email-dns`; DMARC alignment (From domain = DKIM domain) |
| Nothing for one lead | the lead has `email_opt_out_at` / `email_bounced_at`, or the address is on Settings → Suppressions |
