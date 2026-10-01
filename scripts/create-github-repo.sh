#!/usr/bin/env bash
# Creates the GitHub repository and pushes both long-lived branches.
# Only needed if `gh` was not authenticated when the project was built.
set -Eeuo pipefail

REPO_NAME="${1:-leadflow-crm}"
VISIBILITY="${2:---private}"

cd "$(dirname "$0")/.."

command -v gh >/dev/null || { echo "Install the GitHub CLI: https://cli.github.com" >&2; exit 1; }
gh auth status >/dev/null 2>&1 || { echo "Run 'gh auth login' first." >&2; exit 1; }

[ -d .git ] || { git init; git symbolic-ref HEAD refs/heads/main; }

if ! git rev-parse HEAD >/dev/null 2>&1; then
  git add -A
  git commit -m "chore: bootstrap LeadFlow CRM"
fi

gh repo create "$REPO_NAME" "$VISIBILITY" --source=. --remote=origin --push

git show-ref --verify --quiet refs/heads/develop || git branch develop
git push -u origin develop

cat <<EOF

  Repository ready: $(gh repo view --json url -q .url)

  Next, add the deployment secrets (see EXTERNAL_SETUP.md):

      gh secret set VPS_HOST
      gh secret set VPS_USER
      gh secret set VPS_SSH_KEY < ~/.ssh/leadflow_deploy
      gh secret set STAGING_PATH
      gh secret set PRODUCTION_PATH
      gh secret set STAGING_DOMAIN
      gh secret set PRODUCTION_DOMAIN

EOF
