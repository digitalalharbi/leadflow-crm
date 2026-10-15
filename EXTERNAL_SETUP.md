# External setup

Everything in this list needs a value only you can supply. Nothing here blocks
the product: the application, its tests and its deployment pipeline are complete
and working without any of it. Each item below says exactly what changes once it
is filled in.

**Start here:**

```bash
./scripts/check-deploy-readiness.sh
```

It reports which secrets and variables exist, which are missing, and the exact
command for each — without printing any value. Run it again after each change.

> **Why a green deploy job can still mean nothing shipped.** The deploy
> workflows are deliberately written to *skip* rather than fail when the server
> is not configured, so that pull requests are never blocked by missing
> infrastructure. A "Deploy staging" run therefore reports success whether it
> deployed or did nothing. Its run summary now says which happened, and the
> script above answers it before you push.

---

## 0. Deploying without GitHub Actions

Actions is one of two ways to reach the server, not the only one. The workflows
run the tests, SSH in, and run the repository's own on-server deploy script —
and when Actions cannot run at all (a billing hold, an outage, a runner that is
down), only the middle step is actually missing. It is one SSH call.

```bash
cp .deploy.env.example .deploy.env     # host, user, key path, paths, domains
$EDITOR .deploy.env                    # git-ignored; the key stays a path

./scripts/verify-all.sh                # what the four workflows run, run here
./scripts/deploy-direct.sh staging     # ... then production
```

`verify-all.sh` mirrors `backend-tests.yml`, `frontend-tests.yml` and
`e2e-tests.yml` step for step and stamps the commit it passed on.
`deploy-direct.sh` refuses to deploy a commit without that stamp — with no CI
run to point at, "the tests passed" has to mean a run somebody can name.

It is not CI and does not claim to be: CI runs on a clean checkout, a clean
database and a machine doing nothing else. A failure there is worth believing;
a pass is worth less, and it cannot tell you whether the branch still passes
beside everybody else's.

The deploy itself calls `scripts/deploy-staging.sh` or
`scripts/deploy-production.sh` on the server unchanged — the same scripts
Actions calls, with the same backup, the same maintenance window and the same
automatic rollback. What the direct driver adds is the view from outside: the
on-server check verifies the release over loopback, which proves the container
is serving the new commit and proves nothing about DNS, TLS or the proxy in
front of it. The last gate is a request to the public domain, the way a
customer arrives, and a deploy that cannot be verified that way is a failed
deploy rather than a quiet one.

**This still needs a server** — section 2 below. What it does not need is a
GitHub Actions minute.

---

## 0b. The live feed — optional, no external account

Off by default. With `BROADCAST_CONNECTION=log` nothing is published, the
websocket container sits idle, and every screen refetches on its timer exactly
as it did before. Nothing about the product depends on it being on.

To turn it on, generate three values per environment and set them on both sides:

```bash
# server — backend/.env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=... REVERB_APP_KEY=... REVERB_APP_SECRET=...
REVERB_HOST=ws.example.com REVERB_PORT=443 REVERB_SCHEME=https

# browser — build time only
VITE_REVERB_APP_KEY=<the same key> VITE_REVERB_HOST=ws.example.com
VITE_REVERB_PORT=443 VITE_REVERB_SCHEME=https
```

The key is public by design: it identifies the application to the socket server,
and every channel is private and authorised through the API against the
signed-in person. **`REVERB_APP_SECRET` never goes near the frontend build** —
anything in a `VITE_` variable ships inside the bundle a customer downloads.

Two things the deployment has to provide:

- **A worker.** The feed is published from the queue so a provider's webhook is
  never held up by a socket call. With Horizon stopped, nothing arrives however
  healthy the socket looks — check that first.
- **A route to the socket.** The `reverb` container listens on 8080 and is not
  published to the host; the reverse proxy terminates TLS and forwards `/app`
  and `/apps` to it, the same way it does for the API.

Verified end to end on a local stack: a lead created through the API reached an
open work queue in under a second, with the toast that only the socket can
produce.

---

## 1. GitHub — done

- [x] Repository created and pushed
- [x] `main` and `develop` branches
- [x] Four workflows committed

**Recommended next:** protect both branches so the pipeline cannot be bypassed.

```bash
gh api -X PUT repos/:owner/leadflow-crm/branches/main/protection \
  -F required_pull_request_reviews.required_approving_review_count=1 \
  -F required_status_checks.strict=true \
  -F 'required_status_checks.contexts[]=test' \
  -F enforce_admins=false -F restrictions=null
```

---

## 2. VPS and deployment secrets

**Until these are set:** CI runs every test on each push and then skips the
deploy step with a notice. Nothing fails; nothing is deployed.

### 2.1 Provision the server

Ubuntu 22.04 or 24.04, 4 GB RAM minimum (8 GB if staging and production share it).

**One command, on the server, as root:**

```bash
bash scripts/provision-server.sh <github-owner>
```

It creates the deploy user, installs Docker, lays out `/var/www/leads-crm`,
clones both environments at the right branch, hardens SSH and opens the
firewall. It is safe to re-run, and it refuses to reload `sshd` if the config
it just wrote is invalid — so a mistake cannot lock you out.

It writes no secret. It ends by naming the two things only you can supply: the
deploy key's public half, and the env files.

<details>
<summary>The same steps by hand</summary>

```bash
# As root, once
adduser --disabled-password --gecos "" deploy
usermod -aG docker deploy          # after installing Docker
mkdir -p /var/www/leads-crm/{staging,production}
chown -R deploy:deploy /var/www/leads-crm

# Docker
curl -fsSL https://get.docker.com | sh

# Deploy user's SSH access — key only, no password
mkdir -p /home/deploy/.ssh && chmod 700 /home/deploy/.ssh
# paste the PUBLIC key into /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh

# Disable password and root login
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
systemctl reload ssh

ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw --force enable
```

</details>

Then clone the repository into each directory and create its env file:

```bash
sudo -u deploy -i
git clone https://github.com/<owner>/leadflow-crm.git /var/www/leads-crm/staging
cd /var/www/leads-crm/staging && git checkout develop
cp .env.example .env.staging     # fill in APP_KEY, DB_PASSWORD, domains
```

### 2.2 Generate a deploy key

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/leadflow_deploy -N ""
# public half  -> /home/deploy/.ssh/authorized_keys on the VPS
# private half -> the VPS_SSH_KEY secret below
```

### 2.3 Add the secrets

- [ ] `VPS_HOST` — server IP or hostname
- [ ] `VPS_USER` — `deploy`
- [ ] `VPS_PORT` — optional, defaults to 22
- [ ] `VPS_SSH_KEY` — the **private** key generated above
- [ ] `STAGING_PATH` — `/var/www/leads-crm/staging`
- [ ] `PRODUCTION_PATH` — `/var/www/leads-crm/production`

```bash
gh secret set VPS_HOST --body "203.0.113.10"
gh secret set VPS_USER --body "deploy"
gh secret set VPS_SSH_KEY < ~/.ssh/leadflow_deploy
gh secret set STAGING_PATH --body "/var/www/leads-crm/staging"
gh secret set PRODUCTION_PATH --body "/var/www/leads-crm/production"
```

And the two repository **variables**. These are required, not cosmetic: the
deploy job checks the deployed environment over HTTP and fails without them,
because a run that could not check what it shipped has not shown that it works.

- [ ] `STAGING_DOMAIN` — e.g. `staging.example.com`
- [ ] `PRODUCTION_DOMAIN` — e.g. `app.example.com`

```bash
gh variable set STAGING_DOMAIN --body "staging.example.com"
gh variable set PRODUCTION_DOMAIN --body "app.example.com"
```

**Once set:** every push to `develop` deploys to staging automatically.

---

### 2.4 No domain yet?

The deploy works on a bare IP, with one limitation stated plainly: there is no
TLS, because no public certificate authority issues for an IP address. Bearer
tokens and session cookies therefore cross the network in clear text. Use it to
get a deployment working and to demo — not for real customer data.

On the server:

```bash
cp docker/nginx/edge-ip.conf.example /etc/nginx/sites-available/leadflow
ln -s /etc/nginx/sites-available/leadflow /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

In `.env.staging`, point everything at the IP and drop the scheme to http:

```
APP_SCHEME=http
STAGING_DOMAIN=203.0.113.10
STAGING_API_DOMAIN=203.0.113.10
```

One origin serves both: `/` is the app, `/api` is the API. When a domain
arrives, switch to `edge.conf.example`, remove `APP_SCHEME`, and redeploy —
nothing else changes.

Production has no equivalent mode on purpose.

---

## 3. Domain and DNS

**Until these exist:** the app runs on IP or localhost; subdomain tenant
resolution falls back to the `?tenant=` query parameter, which works fine.

- [ ] Register the domain
- [ ] A records:

| Host | Points to | Purpose |
| --- | --- | --- |
| `staging` | VPS IP | Staging SPA |
| `staging-api` | VPS IP | Staging API |
| `app` | VPS IP | Production SPA |
| `api` | VPS IP | Production API |
| `*` (wildcard) | VPS IP | Per-tenant subdomains (`client1.example.com`) |

The wildcard record is what makes `client1.example.com` work without touching
DNS for each new customer.

### Cloudflare

Optional. If used, set SSL/TLS mode to **Full (strict)** — "Flexible" would
leave the hop between Cloudflare and your server unencrypted.

---

## 4. TLS certificates

**Until done:** the app is reachable over plain HTTP only.

```bash
sudo apt install certbot python3-certbot-nginx
sudo cp docker/nginx/edge.conf.example /etc/nginx/sites-available/leadflow
sudo ln -s /etc/nginx/sites-available/leadflow /etc/nginx/sites-enabled/
# edit the server_name lines for your domains, then:
sudo certbot --nginx -d staging.example.com -d staging-api.example.com
sudo certbot --nginx -d app.example.com -d api.example.com
sudo systemctl enable --now certbot.timer    # auto-renewal
```

For per-tenant subdomains you need a wildcard certificate, which requires the
DNS-01 challenge:

```bash
sudo certbot certonly --manual --preferred-challenges=dns \
  -d '*.example.com' -d example.com
```

---

## WhatsApp Business Platform

Unlike every other integration here, this one is entered by **each client**, in
their own Integrations → WhatsApp screen — not by you in an environment file.
Two developers using the system message their buyers from their own numbers, so
the credentials belong to the workspace, not to the platform.

| Field | Where it comes from |
| --- | --- |
| Phone number id | Meta Business → WhatsApp → API Setup |
| WhatsApp Business account id | the same screen |
| Access token | a System User token with `whatsapp_business_messaging` and `whatsapp_business_management` |
| App secret | the Meta app's Basic Settings — this is what proves an inbound delivery really came from Meta |
| Verify token | generated for you in the screen; copy it into Meta's webhook settings |

Point Meta's webhook at:

```
POST https://<api-domain>/api/v1/integrations/whatsapp/webhook
```

One URL serves every client, because Meta allows one callback per app. The
tenant is decided by the phone number id inside each delivery and the signature
is verified against that tenant's own app secret.

### Templates, and the 24-hour window

WhatsApp lets a business message a customer freely for 24 hours after the
customer last wrote, and after that — or to start a conversation at all — only
with a **template Meta approved in advance**. This matters for automation: a rule
that messages a brand-new lead is, by definition, outside the window.

1. Create and submit the template in Meta Business → WhatsApp Manager → Message
   templates. Approval is Meta's, usually minutes to a day.
2. In Integrations → Saved messages, open the message and enter the template's
   exact name, its language and the lead fields that fill `{{1}}`, `{{2}}`, … in
   order.

A saved message with no template can still *answer* a lead; it is refused, with
the reason, when it would have to start a conversation. Nothing is sent that
Meta would reject.

Files go as attachments, uploaded to Meta and sent by media id, with the tracked
link in the caption — never as a public link Meta's servers would fetch, which
would be counted as the customer opening it.

### What "verified" means here

The Integrations screen says **External verification required** until a real
delivery receipt or inbound message has come through a workspace that holds live
credentials. Entering a token does not change that; neither does anything the
demo provider records. It changes when Meta says a message reached a phone.

**Until a workspace has these**, it runs on the mock provider: messages are
recorded, appear in the conversation, and carry real statuses, but nothing
reaches a customer and the integration says *configuration required*. The mock
is refused outside local development, so a staging or production workspace
without credentials cannot send at all — a message a rep believes was delivered
but never left the building is worse than a visible failure.

> The ordered, step-by-step version of everything below — provisioning, deploy,
> credentials, the evidence that closes each row, and the live smoke test — is
> [`docs/PILOT_RUNBOOK.md`](docs/PILOT_RUNBOOK.md).

## Commissioning — after the credentials, with no code change

Entering a credential and proving it are two steps, and the **Production
readiness** screen (and `php artisan leadflow:readiness` on the server) separates
them:

| State | Means | Moved by |
| --- | --- | --- |
| External verification required | something outside the application is missing or unproven | you, or the provider |
| Ready | everything the application needs has been supplied | credentials entered |
| Live verified | a real connection or event has been observed | the other end, and only that |

For Google, WhatsApp, Twilio and the ad platforms the screen has a **Run the
check** button, which uses the credential once against the real provider and
keeps the result:

- **Google** — asks Google to accept the OAuth client (a deliberately invalid
  code: `invalid_grant` means the client is right, `invalid_client` means the id
  or secret is wrong), confirms the picker is configured, and for a connected
  workspace renews the token and confirms the scope is `drive.file`.
- **WhatsApp** — asks Meta to describe the number; the verified business name
  that comes back is what to check against your own.
- **Twilio** — asks Twilio to describe the account; no call is placed.
- **Ad platforms** — pulls from each platform that has credentials, which is the
  real thing: whatever leads are waiting are ingested, and that is also the first
  real event. Webhook-only platforms call *us*, so they say so rather than pass.

A passing check does **not** make a row live. That takes a delivery receipt, a
measured call, a lead arriving, a sheet read, a scheduler pulse — something the
other end did. VPS and domain are never "run": the server's own heartbeat and
the request that opened the screen are the evidence.

---

## 5. Ad-platform credentials

**Until these exist:** each provider is fully built, tested with mocks, and
reports *"Configuration required"* on the Integrations page. The generic webhook
and CSV import work today and cover most real traffic — most agencies forward ad
leads through Zapier, Make or a landing page anyway.

| Platform | Variables | Where to get them |
| --- | --- | --- |
|Meta (Facebook + Instagram) | `META_APP_ID`, `META_APP_SECRET`, `META_VERIFY_TOKEN` | developers.facebook.com → app with `leads_retrieval` + `pages_manage_ads` |
| TikTok | `TIKTOK_APP_ID`, `TIKTOK_APP_SECRET` | business-api.tiktok.com |
| Google Ads | `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, `GOOGLE_ADS_DEVELOPER_TOKEN` | Google Cloud Console + Google Ads API Center |
| Google Sheets | `GOOGLE_SHEETS_CLIENT_ID`, `GOOGLE_SHEETS_CLIENT_SECRET` | Google Cloud Console → enable Sheets API |
| LinkedIn | `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET` | linkedin.com/developers → Lead Sync API access |
| Snapchat | `SNAPCHAT_CLIENT_ID`, `SNAPCHAT_CLIENT_SECRET` | business.snapchat.com → Marketing API |

### Google Sheets — the consent flow and the file picker

`GOOGLE_SHEETS_CLIENT_ID` and `GOOGLE_SHEETS_CLIENT_SECRET` belong to the
**platform**; each client then connects its own Google account with one button in
Integrations → Google Sheets, and chooses the spreadsheet in **Google's own
picker**. In the Google Cloud Console:

1. Create an OAuth client of type *Web application*.
2. Add this as an **authorised redirect URI**, exactly:

   ```
   https://<api-domain>/oauth/google/callback
   ```
3. Enable the *Google Sheets API* and the *Google Picker API*.
4. Create an **API key**, restrict it to your app's origin and to the Picker
   API, and set it as `GOOGLE_PICKER_API_KEY`. It is sent to the browser — it is
   not a secret, and the restriction is what makes that fine.
5. Set `GOOGLE_PROJECT_NUMBER` to the project's **number** (not its id). Without
   it the picker still opens and the file it returns is not added to the grant,
   so the first read fails with a 404 that looks like a typo.
6. Set `FRONTEND_URL` so the browser is returned to the app after consent.

**One scope: `drive.file`.** It covers files a person chose in the picker and
nothing else in their Drive, which is why the product never lists anybody's
spreadsheets. It is a *non-sensitive* scope, so — unlike the two read-only scopes
this replaced — it does not put the app through Google's sensitive/restricted
scope review, and the consent screen says what is true: "see files you open with
this app". If you later publish the OAuth app, the brand-verification step
(name, logo, domain, privacy policy) still applies, and it is Google's.

If the page ever gets a Content-Security-Policy, the picker needs
`script-src https://apis.google.com` and `frame-src https://docs.google.com`.

### Telephony

Entered by each client, in Integrations → Telephony, like WhatsApp: their own
Twilio Account SID, Auth Token and the number calls come from. The screen shows
the call-status URL to give Twilio and a **Test connection** button that asks
Twilio to describe the account without placing a call.

- Enable **Saudi Arabia (+966)** under Twilio → Voice → Geo permissions, or
  every call is refused with code 21215 and falls back to the rep dialling.
- Each rep needs a mobile number on their profile: the bridge rings the rep
  first and connects the lead when they answer.

Without it, the rep dials — which is reported as exactly that, not as a failure.

Meta also needs its webhook pointed at:

```
https://api.example.com/api/v1/integrations/webhooks/<key>/leads
```

The `GET` on that path answers Meta's subscription handshake using `META_VERIFY_TOKEN`.

---

## 6. Production hardening

- [ ] Set `SUPER_ADMIN_PASSWORD` — seeding now **refuses to run** outside local
      development without it, so this cannot be forgotten
- [ ] Set `TRUSTED_PROXIES` to your load balancer's address or CIDR (leave empty
      if the app is reached directly); trusting every proxy would let a caller
      forge their IP past the login rate limiter
- [ ] Generate a distinct `APP_KEY` per environment
      (it encrypts stored integration credentials — losing it loses them)
- [ ] Set `APP_DEBUG=false` (the compose files already do)
- [ ] Point backups at off-server storage; `./backups` on the same VPS does not
      survive the VPS
- [ ] Configure a real mailer if you want email notifications
      (in-app notifications work without one)

---

## 7. Optional

- [ ] Error tracking (Sentry or similar)
- [ ] Uptime monitoring against `/api/health`
- [ ] Protect `/horizon` behind the admin gate or a VPN before exposing it

---

## Summary

| Needs | Item | Whose |
| --- | --- | --- |
| A server | VPS, a `deploy` user with an SSH key | you |
| A domain | DNS for the app and API, TLS | you |
| Google Sheets | an OAuth client, an API key and project number for the picker | you |
| WhatsApp | per client: number, token, app secret; approved templates | each client → Meta |
| Telephony | per client: Twilio account and number | each client → Twilio |
| Ad platforms | per platform API access | you → each platform |

Everything else — the product, its tests and its pipeline — is complete and runs
today. Each external item is isolated behind configuration, so filling one in
never requires a code change, and until it is filled in the Integrations screen
says **External verification required** and names what it is waiting for.
