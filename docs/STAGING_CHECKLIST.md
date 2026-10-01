# Staging checklist

Everything in the repository is ready. What remains is the external input only
you can provide: a server, a domain, and certificates.

**Status:** the pipeline runs every test on each push and then *skips* the deploy
step with a notice, because `VPS_HOST` is not set. Nothing fails; nothing is
deployed. Setting the secrets in §2 turns that step on.

---

## What is already in place

| | Verified |
| --- | --- |
| `deploy-staging` workflow | Runs backend, frontend and E2E suites, then deploys over SSH |
| Docker images | `docker/php/Dockerfile` (php-fpm, queue, Horizon, scheduler), `docker/Dockerfile.frontend` (static SPA build) |
| Compose stack | 8 services: postgres, redis, backend, api, frontend, queue, horizon, scheduler |
| Nginx | `api.conf` (php-fpm), `spa.conf` (SPA fallback + caching), `edge.conf.example` (TLS edge) |
| Environment template | `.env.staging.example` — copy to `.env.staging` on the VPS |
| Health check | `GET /api/health` — application, database, Redis; 503 when degraded |
| Database migration | `migrate --force` in the deploy script; never `migrate:fresh` |
| Horizon restart | `horizon:terminate` then the workers are restarted onto the new code |
| Frontend build | Built into the image at deploy time with the environment's API URL |
| Rollback | Automatic on a failed health check; manual via `scripts/rollback.sh` |
| Logs | `docker compose logs -f backend`; the workflow prints them on failure |
| Backups | Taken before migrations, gzip-verified, pruned after `BACKUP_RETAIN_DAYS` |
| Destructive-command gate | CI fails if `migrate:fresh`, `db:wipe` or `DROP DATABASE` enters the deploy path |

Ports are bound to `127.0.0.1` only. PostgreSQL and Redis are not published at
all — the host Nginx is the single entry point.

---

## 1. Provision the VPS

Ubuntu 22.04 or 24.04, 4 GB RAM minimum (8 GB if staging and production share it).

```bash
# As root, once
adduser --disabled-password --gecos "" deploy
curl -fsSL https://get.docker.com | sh
usermod -aG docker deploy

mkdir -p /var/www/leads-crm/{staging,production}
chown -R deploy:deploy /var/www/leads-crm

# Key-only SSH for the deploy user
mkdir -p /home/deploy/.ssh && chmod 700 /home/deploy/.ssh
# paste the PUBLIC key into /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh

sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
systemctl reload ssh

ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw --force enable
```

Then, as `deploy`:

```bash
git clone https://github.com/<owner>/leadflow-crm.git /var/www/leads-crm/staging
cd /var/www/leads-crm/staging && git checkout develop
cp .env.staging.example .env.staging
# fill in APP_KEY, DB_PASSWORD, the domains, SUPER_ADMIN_PASSWORD
```

- [ ] VPS provisioned, `deploy` user created, Docker installed
- [ ] Repository cloned into `/var/www/leads-crm/staging` on `develop`
- [ ] `.env.staging` filled in

---

## 2. Deployment secrets

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/leadflow_deploy -N ""
# public half  -> /home/deploy/.ssh/authorized_keys
# private half -> VPS_SSH_KEY below

gh secret set VPS_HOST --body "203.0.113.10"
gh secret set VPS_USER --body "deploy"
gh secret set VPS_SSH_KEY < ~/.ssh/leadflow_deploy
gh secret set STAGING_PATH --body "/var/www/leads-crm/staging"
gh secret set PRODUCTION_PATH --body "/var/www/leads-crm/production"

gh variable set STAGING_DOMAIN --body "staging.example.com"
gh variable set PRODUCTION_DOMAIN --body "app.example.com"
```

- [ ] `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`, `STAGING_PATH`, `PRODUCTION_PATH`
- [ ] `STAGING_DOMAIN`, `PRODUCTION_DOMAIN` variables

**Once set:** every push to `develop` deploys to staging automatically.

---

## 3. Domain and DNS

| Host | Points to | Purpose |
| --- | --- | --- |
| `staging` | VPS IP | Staging SPA |
| `staging-api` | VPS IP | Staging API |
| `app` | VPS IP | Production SPA |
| `api` | VPS IP | Production API |
| `*` | VPS IP | Per-tenant subdomains (`client1.example.com`) |

The wildcard is what lets a new customer get their own subdomain without a DNS
change. If Cloudflare is in front, set SSL/TLS to **Full (strict)** — "Flexible"
would leave the hop to your server unencrypted.

- [ ] Domain registered
- [ ] A records created, wildcard included

---

## 4. TLS

```bash
sudo apt install certbot python3-certbot-nginx
sudo cp docker/nginx/edge.conf.example /etc/nginx/sites-available/leadflow
sudo ln -s /etc/nginx/sites-available/leadflow /etc/nginx/sites-enabled/
# edit the server_name lines, then:
sudo certbot --nginx -d staging.example.com -d staging-api.example.com
sudo systemctl enable --now certbot.timer
```

A wildcard certificate for per-tenant subdomains needs the DNS-01 challenge:

```bash
sudo certbot certonly --manual --preferred-challenges=dns -d '*.example.com' -d example.com
```

- [ ] Certificates issued, HTTP redirects to HTTPS
- [ ] Auto-renewal enabled

---

## 5. First deploy

```bash
# On the VPS, as deploy
cd /var/www/leads-crm/staging
docker compose -f docker-compose.staging.yml --env-file .env.staging up -d postgres redis
docker compose -f docker-compose.staging.yml --env-file .env.staging run --rm backend php artisan key:generate --show
# put that value in .env.staging as APP_KEY, then:
./scripts/deploy-staging.sh
```

After that, pushing to `develop` is the deploy.

- [ ] `APP_KEY` generated and saved
- [ ] First deploy green, `curl https://staging.example.com/api/health` returns `healthy`

---

## 6. Make it demo-ready

```bash
# Platform owner (random password printed once)
docker compose -f docker-compose.staging.yml --env-file .env.staging \
  exec backend php artisan leadflow:create-super-admin

# Full demo tenant: 180 leads, 14 campaigns, follow-ups in all three buckets
./scripts/refresh-demo.sh staging
```

During a demo, the **Simulate an incoming lead** button on the Integrations page
pushes a lead through the real ingestion pipeline so a prospect watches it land.
That control and its endpoint exist only outside production.

- [ ] Super admin created, password stored safely
- [ ] Demo tenant seeded, sign-in verified against `README-DEMO.md`

---

## 7. Protect the branches

So a red pipeline actually blocks a merge:

```bash
gh api -X PUT repos/:owner/leadflow-crm/branches/develop/protection \
  -F required_status_checks.strict=true \
  -F 'required_status_checks.contexts[]=backend-tests / PHP 8.4' \
  -F 'required_status_checks.contexts[]=frontend-tests / test' \
  -F 'required_status_checks.contexts[]=e2e / Playwright' \
  -F enforce_admins=false -F restrictions=null \
  -F required_pull_request_reviews.required_approving_review_count=1
```

- [ ] `develop` and `main` protected with the three suites required

---

## 8. Before real customer data

- [ ] `SUPER_ADMIN_PASSWORD` set (seeding refuses to run without it outside local)
- [ ] `TRUSTED_PROXIES` set to the host Nginx range, not `*`
- [ ] A distinct `APP_KEY` per environment — it encrypts stored integration
      credentials, and losing it loses them
- [ ] Backups pointed at off-server storage; `./backups` on the same VPS is not a backup
- [ ] `/horizon` behind a VPN or the admin gate before it is exposed
- [ ] A real mailer if you want email notifications (in-app ones work without one)

---

## Summary

| Blocking? | Item |
| --- | --- |
| No | Everything above |
| — | The app, its 180 backend / 32 unit / 72 browser tests and its pipeline all run today |

The only genuine external dependencies are a server, a domain and certificates.
Ad-platform API keys are listed in [EXTERNAL_SETUP.md](../EXTERNAL_SETUP.md); the
generic webhook and CSV import work without any of them.
