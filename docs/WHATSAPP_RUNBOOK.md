# WhatsApp runbook

**Architecture:** `WhatsappProviderInterface` with the Meta Cloud API adapter (`WhatsappCloudProvider`); another BSP plugs
in behind the same interface. Each workspace connects its own number; credentials are encrypted and never returned.

## Connecting a workspace
1. Meta Business verified, a WhatsApp Business Account and a phone number (owner action).
2. Integrations → WhatsApp: phone number ID, WABA ID, access token (system-user, permanent), app secret, verify token.
3. In Meta's app → WhatsApp → Configuration: callback URL `https://mohtam.pro/api/v1/integrations/whatsapp/webhook` (Integrations → WhatsApp shows the exact one) (or the API host
   once `api.mohtam.pro` resolves), the same verify token; subscribe to `messages`.
4. Test connection. Templates sync from Meta; only **approved** templates can be sent outside the 24-hour window.

## What the webhook does
Signature (`X-Hub-Signature-256`, the app secret) is checked first; the phone number ID resolves the workspace; each
message id is processed once (unique index), so a Meta retry cannot duplicate a message or a reply. Inbound: stored on the
lead's conversation, the first reply stamps consent, the reply stops "waiting for reply" automations. Status callbacks
update sent/delivered/read/failed with Meta's error code.

## Opt-out and takeover
A reply that is only a stop word (`إيقاف`, `توقف`, `إلغاء الاشتراك`, `STOP`, `unsubscribe`, …) stamps `whatsapp_opt_out_at`
and adds the number to the workspace suppression list: no automated message reaches it again, from any lead. When a
person writes to the lead by hand, automation pauses for that lead (human takeover).

## Troubleshooting
| Symptom | Check |
| --- | --- |
| Test connection fails "authentication" | token expired or not a system-user token |
| Template send refused | template not approved, wrong language, or variables count differs |
| No inbound messages | webhook subscription in Meta, verify token, app secret (signature failures are logged as rejected) |
| `131047` re-engagement error | outside 24h: must be an approved template |
