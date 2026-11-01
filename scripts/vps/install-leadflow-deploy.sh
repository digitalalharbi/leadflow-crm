#!/usr/bin/env bash
# One-time, root-only installer for the restricted GitHub Actions SSH identity.
set -Eeuo pipefail

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH
umask 077

PROJECT=/opt/leadflow-crm
DEPLOY_USER=leadflow-deploy
PUBLIC_KEY_FILE="${1:-}"

die() { printf 'install-leadflow-deploy: %s\n' "$1" >&2; exit "${2:-1}"; }

[ "$(id -u)" -eq 0 ] || die "run this installer as root"
[ "$#" -eq 1 ] || die "usage: $0 /path/to/leadflow_deploy.pub" 64
[ -f "$PUBLIC_KEY_FILE" ] || die "public key file does not exist: $PUBLIC_KEY_FILE" 66
id "$DEPLOY_USER" >/dev/null 2>&1 || die "the dedicated $DEPLOY_USER account does not exist" 67
[ -d "$PROJECT/.git" ] || die "$PROJECT is not a Git checkout" 68
[ "$(stat -c %u "$PROJECT")" -eq 0 ] || die "$PROJECT must be owned by root" 68

read -r key_type key_material key_comment < "$PUBLIC_KEY_FILE"
[ "$key_type" = ssh-ed25519 ] || die "the deployment key must be an Ed25519 public key" 69
[[ "$key_material" =~ ^[A-Za-z0-9+/]+={0,2}$ ]] || die "invalid public-key payload" 69
[ "$(awk 'NF { count++ } END { print count + 0 }' "$PUBLIC_KEY_FILE")" -eq 1 ] \
  || die "the public key file must contain exactly one non-empty line" 69
ssh-keygen -l -f "$PUBLIC_KEY_FILE" >/dev/null 2>&1 || die "ssh-keygen rejected the public key" 69

install -o root -g root -m 0755 \
  "$PROJECT/scripts/vps/leadflow-ssh-dispatch" \
  /usr/local/sbin/leadflow-ssh-dispatch
install -o root -g root -m 0755 \
  "$PROJECT/scripts/vps/leadflow-deploy" \
  /usr/local/sbin/leadflow-deploy

sudoers_tmp="$(mktemp)"
auth_tmp="$(mktemp)"
trap 'rm -f "$sudoers_tmp" "$auth_tmp"' EXIT
install -m 0600 "$PROJECT/scripts/vps/leadflow-deploy.sudoers" "$sudoers_tmp"
visudo -cf "$sudoers_tmp" >/dev/null
install -o root -g root -m 0440 "$sudoers_tmp" /etc/sudoers.d/leadflow-deploy
visudo -cf /etc/sudoers.d/leadflow-deploy >/dev/null

ssh_dir="/home/$DEPLOY_USER/.ssh"
authorized="$ssh_dir/authorized_keys"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 0700 "$ssh_dir"
touch "$authorized"

# Remove every existing occurrence of this key material first. That prevents an
# older unrestricted copy of the same key from bypassing the forced command.
awk -v material="$key_material" '
  {
    keep = 1
    for (field = 1; field <= NF; field++) {
      if ($field == material) keep = 0
    }
    if (keep) print
  }
' "$authorized" > "$auth_tmp"
printf 'restrict,command="/usr/local/sbin/leadflow-ssh-dispatch" %s %s%s\n' \
  "$key_type" "$key_material" "${key_comment:+ $key_comment}" >> "$auth_tmp"
install -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 0600 "$auth_tmp" "$authorized"

passwd -l "$DEPLOY_USER" >/dev/null 2>&1 || true

printf 'DEPLOY_IDENTITY_INSTALLED=PASS\n'
printf 'DEPLOY_USER=%s\n' "$DEPLOY_USER"
printf 'DEPLOY_PATH=%s\n' "$PROJECT"
ssh-keygen -l -f "$PUBLIC_KEY_FILE"

