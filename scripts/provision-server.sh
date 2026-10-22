#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Prepares a fresh Ubuntu server to receive deploys. Run ON THE SERVER, as root,
# once:
#
#   curl -fsSL https://raw.githubusercontent.com/<owner>/leadflow-crm/develop/scripts/provision-server.sh | bash -s -- <owner>
#
# or, if the repository is private, copy this file over and run it.
#
# It creates the deploy user, installs Docker, lays out the directories, clones
# both environments, and hardens SSH. It writes no secret: the deploy key's
# public half and the env files are the two things you supply afterwards, and
# the script says so at the end.
#
# Safe to re-run — every step checks before it acts.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

OWNER="${1:-}"
REPO="${2:-leadflow-crm}"
BASE="/var/www/leads-crm"

BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; OFF=$'\033[0m'
say()  { printf '\n%s==>%s %s\n' "$BOLD" "$OFF" "$1"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$OFF" "$1"; }
skip() { printf '  %s·%s %s (already done)\n' "$YELLOW" "$OFF" "$1"; }

if [ "$(id -u)" -ne 0 ]; then
  printf '%sRun as root.%s  sudo bash %s\n' "$RED" "$OFF" "$0"
  exit 1
fi

if [ -z "$OWNER" ]; then
  printf '%sUsage:%s bash %s <github-owner> [repo-name]\n' "$RED" "$OFF" "$0"
  exit 1
fi

say "Deploy user"
if id deploy >/dev/null 2>&1; then
  skip "user 'deploy' exists"
else
  adduser --disabled-password --gecos "" deploy
  ok "created 'deploy'"
fi

say "Docker"
if command -v docker >/dev/null 2>&1; then
  skip "docker installed"
else
  curl -fsSL https://get.docker.com | sh
  ok "docker installed"
fi
usermod -aG docker deploy
ok "'deploy' is in the docker group"

say "Directories"
mkdir -p "$BASE"/{staging,production}
chown -R deploy:deploy "$BASE"
ok "$BASE/{staging,production}"

say "Repository"
for env in staging production; do
  target="$BASE/$env"
  branch=$([ "$env" = staging ] && echo develop || echo main)

  if [ -d "$target/.git" ]; then
    skip "$env already cloned"
  else
    sudo -u deploy git clone "https://github.com/$OWNER/$REPO.git" "$target"
    sudo -u deploy git -C "$target" checkout "$branch"
    ok "$env cloned at $branch"
  fi
done

say "SSH hardening"
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
touch /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys
ok "/home/deploy/.ssh ready"

sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
ok "password and root login disabled"

say "Firewall"
if command -v ufw >/dev/null 2>&1; then
  ufw allow OpenSSH >/dev/null
  ufw allow 80 >/dev/null
  ufw allow 443 >/dev/null
  ufw --force enable >/dev/null
  ok "OpenSSH, 80, 443"
else
  skip "ufw not installed"
fi

say "Scheduled backups"
# The backup script existed and ran before a deploy — which is a backup of the
# state just before a change, and no backup at all of a Tuesday with no deploys.
# Daily, off the hour so the two environments never dump at once, and into a log
# that is rotated rather than left to grow.
cat > /etc/cron.d/leadflow-backup <<CRON
# Managed by scripts/provision-server.sh — edits are overwritten.
17 2 * * * deploy cd $BASE/production && ./scripts/backup-db.sh production daily >> /var/log/leadflow-backup.log 2>&1
47 2 * * * deploy cd $BASE/staging && ./scripts/backup-db.sh staging daily >> /var/log/leadflow-backup.log 2>&1
CRON
chmod 644 /etc/cron.d/leadflow-backup
touch /var/log/leadflow-backup.log
chown deploy:deploy /var/log/leadflow-backup.log
cat > /etc/logrotate.d/leadflow-backup <<'LOGROTATE'
/var/log/leadflow-backup.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    create 0644 deploy deploy
}
LOGROTATE
ok "daily dumps at 02:17 (production) and 02:47 (staging)"

# Reloaded last: a bad sshd_config should not lock anyone out mid-script.
if sshd -t; then
  systemctl reload ssh
  ok "sshd reloaded"
else
  printf '  %s✗%s sshd config rejected — NOT reloading. Fix it before logging out.\n' "$RED" "$OFF"
  exit 1
fi

cat <<DONE

${BOLD}Server is ready. Two things remain, and only you can do them:${OFF}

  ${BOLD}1. The deploy key${OFF}
     On your own machine:

       ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/leadflow_deploy -N ""

     Append the PUBLIC half here:

       /home/deploy/.ssh/authorized_keys

     Then set the PRIVATE half as the VPS_SSH_KEY secret. It must never be
     pasted into a chat, a file in the repository, or anywhere else.

  ${BOLD}2. The environment files${OFF}
     On this server, for each environment:

       sudo -u deploy cp $BASE/staging/.env.staging.example $BASE/staging/.env.staging
       sudo -u deploy cp $BASE/production/.env.production.example $BASE/production/.env.production

     Fill in APP_KEY, DB_PASSWORD, the domains and SUPER_ADMIN_PASSWORD.
     Generate APP_KEY with:  openssl rand -base64 32

Then run ./scripts/check-deploy-readiness.sh from your own machine.

DONE
