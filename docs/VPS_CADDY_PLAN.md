# LeadFlow plan for the existing Hostinger VPS

This plan records the audited topology. It does not authorize container start,
Caddy reload, DNS change, or public activation.

## Isolation

- Compose project: `leadflow-production`.
- Private internal network: `leadflow-production-internal`.
- Dedicated PostgreSQL, Redis, and application volumes.
- PostgreSQL and Redis publish no host ports and remain only on the internal
  network. PHP services use a separate egress network for approved outbound
  integrations.
- Only `api`, `frontend`, and `reverb` join the existing external `web_proxy`
  network, using project-specific aliases.
- Loopback ports 9080 and 9081 are retained for internal validation.
- Docker JSON logs rotate at 20 MB times five per container.
- Runtime memory limits total approximately 1.6 GiB.

## Capacity gate

After targeted log recovery the VPS had about 24.25 GiB free, one CPU, 3.8 GiB
RAM, about 2.1 GiB available memory, and no swap. Production builds remain
sequential. Do not build or start LeadFlow until either a 2 GiB swap file is
approved and configured or the VPS is upgraded; 2 vCPU and 8 GiB RAM is the
preferred production size. `MIN_DEPLOY_SWAP_MB=1900` enforces this gate.

## Deployment identity

The `leadflow-deploy` SSH account is not a member of the Docker group and does
not own `/opt/leadflow-crm`. Its Actions key is restricted in `authorized_keys`
to `scripts/vps/leadflow-ssh-dispatch`. The dispatcher permits only the audit,
verify, deploy, and release actions implemented by the root-owned
`/usr/local/sbin/leadflow-deploy`; arbitrary shells and Docker commands are
refused. GitHub repository access uses a separate read-only deploy key.

## Routing

`docker/caddy/leadflow.Caddyfile.example` routes the React SPA and Laravel API
on the single `mohtam.pro` origin and proxies Reverb WebSockets. The combined
Caddy configuration must be validated before an approved graceful reload.

## Activation and rollback

Keep public DNS unchanged until internal API, database, Redis, Horizon,
Scheduler, Reverb, authentication, static assets, and backup restoration pass.
Rollback is project-scoped: stop only `leadflow-production`, restore its last
known-good commit/database if required, and remove only the LeadFlow Caddy
blocks during an approved reload. Never stop shared Caddy or another project.
