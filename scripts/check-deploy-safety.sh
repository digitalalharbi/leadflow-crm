#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Fails if a command that can destroy a production database is reachable from
# the deploy path.
#
# Only executable lines are examined: comments and documentation are allowed to
# name these commands (this file does), and the patterns below are assembled
# from fragments so that the checker can never match itself.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

cd "$(dirname "$0")/.."

# Assembled at runtime; a literal here would make this script its own failure.
FRESH="migrate""fresh"
WIPE="db"":""wipe"
DROP="DROP""DATABASE"

PATTERN="artisan[[:space:]]+(${FRESH%fresh}:fresh|${WIPE})|${DROP%DATABASE}[[:space:]]+DATABASE"

FAILED=0

# Everything that actually runs during a deploy.
TARGETS=$(find scripts -name '*.sh' ! -name 'check-deploy-safety.sh')

for file in $TARGETS; do
  # Strip comments and blank lines, keep original numbering.
  MATCHES=$(grep -nE "$PATTERN" "$file" | grep -vE '^[0-9]+:[[:space:]]*#' || true)

  if [ -n "$MATCHES" ]; then
    echo "::error file=${file}::Destructive database command in the deploy path"
    echo "$MATCHES"
    FAILED=1
  fi
done

if [ "$FAILED" -eq 1 ]; then
  cat >&2 <<EOF

  A deploy script may never reset or drop a database.
  Use 'php artisan migrate --force' and restore from a backup if you need to
  roll data back.

EOF
  exit 1
fi

echo "No destructive database commands are reachable from the deploy path."
