# Connecting a real lead source

What an operator does, per platform, to make leads arrive. `INTEGRATIONS.md`
describes the contract this sits on; this file is the runbook.

Every source is connected from **مصادر العملاء** (Integrations). Nothing here
needs a developer or a deploy.

## The two kinds of source

They are genuinely different and the screen treats them differently.

| | **Push** | **Pull** |
| --- | --- | --- |
| Who calls whom | the platform calls us | we call the platform |
| What you copy | a webhook URL **out** of مهتم | an access token **into** مهتم |
| The button | «إنشاء رابط» | «مزامنة الآن» |
| Providers | Generic webhook, Google Ads, Meta (webhook mode) | Meta (API mode), TikTok, Snapchat, LinkedIn, Google Sheets |

A pull source is polled every five minutes by `leadflow:pull-leads`. The poll
is scheduled in `routes/console.php` and needs the Laravel scheduler running —
see `DEPLOYMENT.md`. Without the scheduler, pull sources deliver nothing, and
that is the first thing to check if a token looks right and no leads appear.

## Push: the generic webhook

Works with no credentials, and is the fastest way to prove the pipeline end to
end.

1. **مصادر العملاء** → the *Webhook* card → «إنشاء رابط».
2. Copy the URL **and the secret**. The secret is shown once and is not
   recoverable; rotate it if it is lost.
3. Give both to whoever is sending the leads. They post JSON with the secret in
   an `X-Webhook-Secret` header.

```bash
curl -X POST 'https://<your-domain>/api/v1/integrations/webhooks/<key>/leads' \
  -H 'Content-Type: application/json' \
  -H 'X-Webhook-Secret: <secret>' \
  -d '{"full_name":"محمد الحارثي","phone":"0500912345","city":"الرياض","platform":"meta"}'
```

Expected replies: `created` the first time, `re_engaged` for a number already
in the workspace, `401` for a wrong secret. Field names are matched loosely —
`name`, `mobile`, `الجوال`, `المدينة` and many more are understood without any
mapping.

## Pull: what each platform needs

Paste these under «إضافة بيانات الاتصال» on that platform's card. They are
stored encrypted and never shown again; leaving a field empty on a later edit
keeps the current value rather than clearing it.

| Platform | Keys | Where it comes from |
| --- | --- | --- |
| **Meta** (Facebook + Instagram) | `access_token` | A Page access token with `leads_retrieval` and `pages_show_list`, from a Meta app in *Business Settings → System Users*. The app must also be subscribed to the Page's `leadgen` field. |
| **TikTok** | `access_token`, `advertiser_id` | TikTok Business API app → *Authorised Accounts*. The advertiser id is on the ad account. |
| **Snapchat** | `access_token`, `ad_account_id` | Snap Marketing API OAuth app → *Ad Accounts*. |
| **LinkedIn** | `access_token`, `account_id` | LinkedIn Marketing Developer Platform, with `r_ads` and `r_ads_leadgen_automation`. |
| **Google Sheets** | `access_token` | A Google OAuth client with the Sheets read scope. Put the sheet id in the integration's config. |
| **Google Ads** | `google_key` | A Google Ads *Lead form* webhook key, set on the form itself. Google Ads is **push**: it posts to the webhook URL, and the key is what it signs with. |

After saving, press **«مزامنة الآن»**. It calls the platform immediately and
shows what came back — how many rows were fetched, how many were new, and the
platform's own error if it refused. That is the fastest way to tell a wrong
token from a quiet afternoon.

## Reading the result

- **الواصل / عملاء جدد** — rows fetched, and how many became leads.
- **سبق استلامه** — rows this workspace had already taken. A poll re-reads the
  same window every five minutes, so on a healthy source this is most of them.
- **مكرر** — a *different* row that matched an existing phone. A high number
  here is a real duplicate problem at the source, not polling.
- **مرفوض** — rows with no phone and no email. There is nothing to call.

On the **وصول العملاء** card, «آخر عميل منذ …» per source is the number to
watch. A source with a healthy total and nothing for nine days is the broken
one, and the total alone cannot tell you that.

## When a source stops

`last_error` is shown on the card and is the platform's own message, not ours.
The common ones:

- **Token expired** — Meta and TikTok tokens expire. Re-issue and paste again.
- **404 / permission** — the token is valid but lacks the scope, or the
  advertiser/account id belongs to a different account.
- **Nothing fetched, no error** — the source has nothing new. Check
  «آخر عميل منذ …» before assuming a fault.

A failed poll deliberately does **not** update «آخر مزامنة». A source that has
been refusing a token for a week must not read as synced a minute ago.

## What is not automated

Issuing the credentials themselves. Every platform above requires someone with
access to that ad account to create an app, grant scopes and generate a token;
none of it can be done from مهتم, and no part of مهتم will ask for an ad
account password. Paste the resulting token and nothing else.
