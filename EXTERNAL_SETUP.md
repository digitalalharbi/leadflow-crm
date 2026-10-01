# External setup

Everything in this list needs a value only you can supply. Nothing here blocks
the product: the application, its tests and its deployment pipeline are complete
and working without any of it. Each item below says exactly what changes once it
is filled in.

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

And the two repository **variables** (used for the public health check and the
deployment URL shown in GitHub):

- [ ] `STAGING_DOMAIN` — e.g. `staging.example.com`
- [ ] `PRODUCTION_DOMAIN` — e.g. `app.example.com`

```bash
gh variable set STAGING_DOMAIN --body "staging.example.com"
gh variable set PRODUCTION_DOMAIN --body "app.example.com"
```

**Once set:** every push to `develop` deploys to staging automatically.

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

**Until a workspace has these**, it runs on the mock provider: messages are
recorded, appear in the conversation, and carry real statuses, but nothing
reaches a customer and the integration says *configuration required*. The mock
is refused outside local development, so a staging or production workspace
without credentials cannot send at all — a message a rep believes was delivered
but never left the building is worse than a visible failure.

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

| Blocking the product? | Item |
| --- | --- |
| No | Everything on this page |
| — | The app, its 182 tests and its pipeline are complete and run today |

The only genuinely external dependencies are a server, a domain and ad-platform
API access. Each is isolated behind configuration, so filling one in never
requires a code change.
