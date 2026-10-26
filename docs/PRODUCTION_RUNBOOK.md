# Production runbook — from nothing to a live, verified URL

One document, copy/paste in order.

> **Shared Hostinger VPS override:** the audited `mohtam.pro` deployment does
> not use the generic provisioning, Nginx, `/var/www`, or Docker-group examples
> below. It uses `/opt/leadflow-crm`, the existing Dockerized Caddy,
> `leadflow-deploy` with a forced-command gate, one apex domain, and the
> architecture/gates in `docs/VPS_CADDY_PLAN.md`. Keep
> `PRODUCTION_ENABLED=false` until internal validation, database backup restore,
> and an explicitly approved Caddy/DNS activation are complete.

## The one road to production

```
PR → CI → merge to develop → release (merge to main) → "Deploy production" workflow, run from main
   → CI must have passed for that exact SHA → the server's working tree must be clean
   → SSH: git fetch that SHA from GitHub → backup → build → migrate → restart
   → health → smoke → the serving SHA is asserted
```

Production is released from **`main`** by that workflow and by nothing else. There is no
deploy from a laptop, no FTP or file-manager upload, no `scp`/`rsync`, no copy of source
onto the server, and no edit on the server that is not a commit: the server fetches one
exact commit from GitHub. `scripts/check-deploy-source.sh` fails the build if a second
road is opened.

**By hand, once:** the VPS, the SSH key, DNS and the domain, HTTPS, GitHub secrets and
variables, `.env.production`, Docker. Everything after that is a commit.

> Nothing in this file is "done" until `./scripts/verify-production.sh` prints
> **PRODUCTION VERIFIED**. That is the only thing that may call a deployment
> production-verified (§12).

Placeholders: `<VPS_IP>`, `<OWNER>` (GitHub owner), `app.<your-domain>`,
`api.<your-domain>`.

---

## 1. What you need

| | Requirement |
|---|---|
| VPS | Ubuntu 22.04 / 24.04, **4 GB RAM / 2 vCPU / 40 GB disk** minimum, public IPv4, root SSH for the first step only |
| Domain | One you control, DNS editable. Two names: `app.<domain>` (the app) and `api.<domain>` (the API) |
| GitHub | Repo `<OWNER>/leadflow-crm`, `gh` logged in on your machine (`gh auth login`) |
| Your machine | `git`, `ssh`, `curl`, `dig`, `python3`, `gh`, and a working checkout of `develop` |

## 2. DNS (at your DNS provider)

```
app.<domain>.   A   <VPS_IP>
api.<domain>.   A   <VPS_IP>
```

Check from your machine — both must print `<VPS_IP>` before you continue:

```bash
dig +short app.<domain>; dig +short api.<domain>
```

## 3. Provision the server (once, as root on the VPS)

```bash
ssh root@<VPS_IP>
curl -fsSL https://raw.githubusercontent.com/<OWNER>/leadflow-crm/main/scripts/provision-server.sh | bash -s -- <OWNER>
```

Creates the `deploy` user, installs Docker, clones the repo into
`/var/www/leads-crm/{staging,production}`, hardens SSH, and writes the nightly
backup job `/etc/cron.d/leadflow-backup`. (Private repo: copy the script over and
run `bash provision-server.sh <OWNER>`.)

Provisioning disables root and password login, so add the deploy key **before
you close the root session**. On **your machine** (keep the root shell open):

```bash
ssh-keygen -t ed25519 -f ~/.ssh/leadflow_deploy -C 'leadflow deploy' -N ''
cat ~/.ssh/leadflow_deploy.pub | ssh root@<VPS_IP> 'cat >> /home/deploy/.ssh/authorized_keys'
ssh -i ~/.ssh/leadflow_deploy deploy@<VPS_IP> true && echo SSH_OK     # must print SSH_OK
```

## 4. HTTPS (on the VPS)

```bash
sudo apt-get install -y nginx certbot python3-certbot-nginx
sudo cp /var/www/leads-crm/production/docker/nginx/edge.conf.example /etc/nginx/sites-available/leadflow-production
```

Edit the copy: replace `staging.example.com` → `app.<domain>`,
`staging-api.example.com` → `api.<domain>`, and the upstream ports for production:
SPA `127.0.0.1:9080`, API `127.0.0.1:9081` (the Reverb websocket, if used, is
proxied by the same file under `/app` and `/apps`). Then:

```bash
sudo ln -s /etc/nginx/sites-available/leadflow-production /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d app.<domain> -d api.<domain> --redirect -m <you@email> --agree-tos -n
sudo systemctl enable --now certbot.timer
sudo ufw allow OpenSSH && sudo ufw allow 80 && sudo ufw allow 443 && sudo ufw --force enable
```

Check from your machine (no `-k`, so a bad certificate fails):

```bash
curl -sSI https://app.<domain>/ | head -1
curl -sSI https://api.<domain>/api/health | head -1   # 502 is fine until the first deploy
```

## 5. Production environment (on the VPS)

```bash
cd /var/www/leads-crm/production
cp .env.production.example .env.production
chmod 600 .env.production
echo "base64:$(openssl rand -base64 32)"   # → APP_KEY
openssl rand -hex 32    # → DEPLOY_SECRET
openssl rand -hex 32    # → DB_PASSWORD
nano .env.production
```

Required, with a real value (the preflight fails on any empty one):

```
APP_KEY  PRODUCTION_DOMAIN=app.<domain>  PRODUCTION_API_DOMAIN=api.<domain>
DB_DATABASE  DB_USERNAME  DB_PASSWORD  DEPLOY_SECRET
SUPER_ADMIN_EMAIL  SUPER_ADMIN_NAME  SUPER_ADMIN_PASSWORD
```

Backups and alerts (§9, §10): `BACKUP_UPLOAD_COMMAND`, `BACKUP_RETAIN_DAYS`,
`SENTRY_LARAVEL_DSN` and/or `LOG_STACK=stderr,slack` + `LOG_SLACK_WEBHOOK_URL`.

Do **not** set `APP_SCHEME=http`; production is HTTPS only. Integration
credentials (Google, Meta, WhatsApp, SMTP, Twilio, Anthropic) are filled in the
same file *after* the first verified deploy — see `EXTERNAL_SETUP.md`.

## 6. GitHub secrets and variable (names only — your machine)

```bash
gh secret set VPS_HOST        --body '<VPS_IP>'
gh secret set VPS_USER        --body 'deploy'
gh secret set VPS_PORT        --body '22'
gh secret set VPS_SSH_KEY     < ~/.ssh/leadflow_deploy
gh secret set PRODUCTION_PATH --body '/var/www/leads-crm/production'
gh variable set PRODUCTION_DOMAIN     --body 'app.<domain>'
gh variable set PRODUCTION_API_DOMAIN --body 'api.<domain>'   # the smoke test needs it
gh variable set PRODUCTION_ENABLED    --body 'true'           # only when everything above exists
```

## 7. Operator checks from your machine (optional, they deploy nothing)

`preflight-production.sh` and `verify-production.sh` read the server and report; neither
ships a release. They read a git-ignored `.deploy.env`:

```bash
cd leadflow-crm && git checkout main && git pull
cp .deploy.env.example .deploy.env          # git-ignored
```

Set: `DEPLOY_HOST=<VPS_IP>`, `DEPLOY_USER=deploy`, `DEPLOY_PORT=22`,
`DEPLOY_SSH_KEY=~/.ssh/leadflow_deploy`, `PRODUCTION_PATH=/var/www/leads-crm/production`,
`PRODUCTION_DOMAIN=app.<domain>`, `PRODUCTION_API_DOMAIN=api.<domain>`.
Never put `DEPLOY_SECRET` here — it stays in the server's `.env.production`.

```bash
./scripts/preflight-production.sh      # PASS/FAIL per item: server, HTTPS, env, database, backups, CI
```

## 8. CI must pass for the exact commit

Production ships only a commit CI has passed. Merge `develop` into `main` (the release),
let CI finish on that commit, and note its SHA. The workflow checks this itself
(`scripts/ci/ci-is-green.sh <sha>`) and refuses otherwise. If GitHub Actions cannot run
(billing), the answer is **BLOCKED — BILLING** and production does not ship: there is no
way around it from a laptop, by design.

## 9. Backups

Nightly job is installed by provisioning (`/etc/cron.d/leadflow-backup`,
02:17). Add an **off-server copy** in `.env.production`, e.g. with rclone:

```
BACKUP_UPLOAD_COMMAND='rclone copyto "$1" remote:leadflow-backups/$(basename "$1")'
```

A backup on the same disk is only "Ready"; the readiness screen reaches
"Live Verified" for backups only for a fresh dump **copied off the server**.
Restore check — run once before go-live, on the server. It restores the newest
dump into a disposable, network-less Postgres container and compares it with the
live database; it never touches the live one:

```bash
cd /var/www/leads-crm/production
./scripts/backup-db.sh production restore-check
./scripts/restore-check.sh production        # prints RESTORE CHECK: PASS
```

(`restore-db.sh` is the real, destructive restore — for disasters only.)

## 10. Logging and error monitoring

* Container logs rotate at 20 MB × 5 (compose). `docker compose -f docker-compose.production.yml logs -f --tail=100 backend`.
* Sentry: `composer require sentry/sentry-laravel` is part of the release
  (see "Sentry SDK" in §14), then `SENTRY_LARAVEL_DSN=` in `.env.production`.
  The readiness row stays **Ready**, not Live, until you trigger a test event
  and see it in Sentry's inbox.

## 11. Deploy — the workflow

GitHub → Actions → **Deploy production** → *Run workflow* → branch **`main`** → type `deploy`.

It runs, in order, and stops at the first failure:

1. **Branch guard** — any ref but `main` is refused before the server is contacted.
2. **Pre-flight verification** — production config caches, migrations apply to an empty database, no destructive command in the deploy path, no deploy path that bypasses GitHub.
3. **CI for the exact SHA** — must be green.
4. **Clean-tree guard** — over SSH: any modified or untracked source file on the server aborts the deploy with nothing changed. The SHA must be on `origin/main`.
5. **Deploy** — `git fetch` that SHA from GitHub, verified backup, build, show pending migrations, short maintenance window, migrate, rebuild caches, restart Horizon and the scheduler, and the commit just shipped must be the one answering.
6. **Health check** — `https://<domain>/api/health`.
7. **Smoke test** (`smoke-production.sh`) — HTTPS, SPA, HTTP→HTTPS, auth enforced, login, no debug output, HSTS, and the release SHA.

Then, from your machine, `./scripts/verify-production.sh` for the eight conditions of §12.

Services: `postgres`, `redis`, `backend` (php-fpm), `api` (nginx), `frontend`,
`horizon` (queue worker), `scheduler` (`schedule:work`; also writes the heartbeat
every minute), `reverb` (websocket).

## 12. What "Production Verified" means

All eight, each observed at verification time:

1. public HTTPS URL answering with a valid certificate
2. queue worker running (Horizon)
3. scheduler running (fresh heartbeat)
4. database reachable (no pending migrations)
5. Redis reachable
6. backup verified (fresh, copied off the server)
7. readiness healthy (the app itself reports `deployment: production`)
8. smoke test passed

```bash
./scripts/verify-production.sh      # exit 0 and "PRODUCTION VERIFIED", or names what is missing
```

An integration (Google Sheets, WhatsApp, email, Twilio, Meta, Claude) is
**Live Verified** only after a real event in Production, and **Production
Verified** only when that event is recent and error-free — see the readiness
screen's Evidence list. Credentials alone are never evidence.

## 13. Rollback

The on-server deploy rolls its code back by itself if the migration or its own health
check fails. A smoke-test failure makes the workflow run red and leaves the release up:
roll back by hand:

```bash
ssh deploy@<VPS_IP> 'cd /var/www/leads-crm/production && ./scripts/rollback.sh production'            # last good
ssh deploy@<VPS_IP> 'cd /var/www/leads-crm/production && ./scripts/rollback.sh production <sha|tag>'  # specific
```

Code only. Migrations are not reversed; if a release changed the schema
destructively, restore the pre-deploy backup with `scripts/restore-db.sh`.
**Rehearse a rollback once** after the first release: run the manual rollback, then
`./scripts/smoke-production.sh`.

## 14. After verification

* Fill integration credentials in `.env.production`, `docker compose … up -d`,
  then connect them in the app and generate the real events (a new Google Sheet
  row; an inbound WhatsApp message; a real email) — each becomes Live Verified
  on the Readiness screen only when that event is recorded.
* Sentry SDK is not installed yet (`composer require sentry/sentry-laravel`
  on `develop`); until it is, error tracking reads *Not configured*.
* Every later release is the same road: merge `develop` into `main`, CI on that commit,
  run **Deploy production** from `main`.
