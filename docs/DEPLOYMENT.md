# Deployment

## Rule

GitHub is the source of truth. The VPS is never edited by hand. Every deploy is
`git reset --hard` to the exact commit CI names, so what runs is always what is
on GitHub.

```
local → commit → push → GitHub → Actions → tests → VPS → live
```

## Environments

| | Branch | Database | Domain | Debug |
| --- | --- | --- | --- | --- |
| Local | any | `leadflow_local` | localhost | on |
| Staging | `develop` | `leadflow_staging` | `staging.example.com` | off |
| Production | `main` | `leadflow_production` | `app.example.com` | off |

Databases are never shared. Staging holds demo data; production never does.

## Provisioning a VPS

Ubuntu 22.04/24.04, 4 GB RAM minimum. Full commands are in
[`EXTERNAL_SETUP.md`](../EXTERNAL_SETUP.md) §2. In summary:

1. Create a `deploy` user with no password and Docker group membership
2. Install Docker and Compose
3. Add the CI deploy key to `~deploy/.ssh/authorized_keys`
4. Disable password and root SSH login
5. `ufw allow OpenSSH, 80, 443`
6. Clone the repo into `/var/www/leads-crm/{staging,production}`
7. Create `.env.staging` / `.env.production` from `.env.example`

Deploys never run as root.

## TLS

Host Nginx terminates TLS and proxies to the containers, which bind to loopback
only. Start from `docker/nginx/edge.conf.example`, then:

```bash
sudo certbot --nginx -d staging.example.com -d staging-api.example.com
sudo systemctl enable --now certbot.timer
```

Per-tenant subdomains need a wildcard certificate (DNS-01 challenge).

## Staging deploy

Triggered by any push to `develop`.

```
backend tests  ─┐
                ├─▶ ssh deploy ─▶ scripts/deploy-staging.sh
frontend tests ─┘
```

On the server:

```
git fetch && git reset --hard <sha>
docker compose build --pull
start postgres + redis, wait for healthy
backup database
docker compose up -d
php artisan migrate --force
php artisan config:cache route:cache event:cache
php artisan horizon:terminate && restart workers
health check (30 attempts, 1s apart)
```

A failed health check triggers an automatic rollback to the previous commit.

## Production deploy

Triggered by any push to `main`, with three extra gates:

1. **Pre-flight job** — installs production dependencies, caches config, routes
   and events, and applies every migration to an empty database. A broken config
   or a bad migration fails here, before the VPS is touched.
2. **Destructive-command check** — fails the build if `migrate:fresh`, `db:wipe`
   or `DROP DATABASE` appears anywhere in `scripts/` or `.github/workflows/`.
3. **Verified backup** — taken and gzip-tested before migrations run.

The maintenance window covers only the migration step, and `--secret` lets the
team preview the release before customers are let back in.

## Health check

```
GET /api/health
```

```json
{ "success": true,
  "data": { "status": "healthy", "checks": { "application": "ok", "database": "ok", "redis": "ok" } } }
```

Returns 503 when any component is down. Deliberately discloses no version or
configuration.

## Rollback

```bash
./scripts/rollback.sh production            # previous known-good commit
./scripts/rollback.sh production v1.0.0     # a specific release tag
```

The deploy script records the previous commit in `.last-good-commit` on success,
so a rollback never has to guess.

**What rollback does and does not do:** it restores code. It does not reverse
migrations — a migration that already ran stays applied. That is precisely why a
backup is taken first. If a release included a destructive migration:

```bash
./scripts/restore-db.sh production ./backups/production/<file>.sql.gz
```

## Backups

```bash
./scripts/backup-db.sh production pre-migration
```

Gzip-verified on creation; a corrupt dump is deleted and the script fails rather
than reporting a backup that cannot be restored. Pruned after
`BACKUP_RETAIN_DAYS` (default 14).

Backups land in `./backups/<environment>/` on the VPS by default. **Move them
off-server before go-live** — a backup on the machine you are protecting is not
a backup.

## Zero-downtime

Staging and production run php-fpm, Horizon and the scheduler as separate
containers. `docker compose up -d` replaces containers one service at a time, and
only the migration step enters maintenance mode. For a true zero-downtime
production, run two API replicas behind the host Nginx; the compose file is
structured so adding `deploy.replicas` is the only change.

## Scheduled work

The `scheduler` container runs `schedule:work`, which dispatches:

* `leadflow:follow-up-reminders` — hourly
* `horizon:snapshot` — every five minutes

## Troubleshooting

```bash
docker compose -f docker-compose.staging.yml --env-file .env.staging logs -f backend
docker compose ... exec backend php artisan migrate:status
docker compose ... exec backend php artisan about
docker compose ... ps
curl -s localhost:8081/api/health | jq
```

Queue not draining? Check Horizon is up and `REDIS_HOST` points at the `redis`
service, not `127.0.0.1`.
