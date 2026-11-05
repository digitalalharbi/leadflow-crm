#!/usr/bin/env bash
# Names, never values: which variable sits on a given line of an env file, and
# which values would not survive being read by a shell.
#
#   ./scripts/env-audit.sh production [line]
#
# The v0.9.0 deploy ran part of one value as a command and printed it in a
# private log. This says which variable that was, so the right credential can be
# rotated, without anyone having to open the file.
set -Eeuo pipefail
cd "$(dirname "$0")/.."

ENVIRONMENT="${1:-production}"
LINE="${2:-131}"
ENV_FILE=".env.${ENVIRONMENT}"
[ -f "$ENV_FILE" ] || { echo "env_audit file=missing"; exit 0; }

# Only ever prints what is left of the first "=", and only when that is a plain name.
name_of() {
  local line="${1%$'\r'}" key
  line="${line#export }"
  case "$line" in
    '') echo "blank" ;;
    '#'*) echo "comment" ;;
    *=*)
      key="${line%%=*}"
      if [[ "$key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then echo "key=${key}"; else echo "malformed_no_plain_name"; fi ;;
    *) echo "no_equals_sign_continuation_or_stray_text" ;;
  esac
}

TOTAL="$(wc -l < "$ENV_FILE" | tr -d ' ')"
echo "env_audit lines=${TOTAL} modified=$(date -u -r "$ENV_FILE" +%Y-%m-%dT%H:%MZ 2>/dev/null || stat -c %y "$ENV_FILE" 2>/dev/null | cut -c1-16)"
echo "env_audit line=${LINE} $(name_of "$(sed -n "${LINE}p" "$ENV_FILE")")"

# A value a shell would split: holds a space or starts with one, and is not wholly quoted.
n=0
while IFS= read -r line || [ -n "$line" ]; do
  n=$((n + 1))
  line="${line%$'\r'}"
  case "$line" in ''|'#'*) continue ;; esac
  line="${line#export }"
  key="${line%%=*}"
  [[ "$key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { echo "env_audit line=${n} not_an_assignment"; continue; }
  [ "$key" != "$line" ] || continue
  value="${line#*=}"
  case "$value" in \"*\"|\'*\') continue ;; esac
  case "$value" in
    ' '*) echo "env_audit line=${n} key=${key} value_starts_with_a_space" ;;
    *' '*) echo "env_audit line=${n} key=${key} unquoted_value_holds_a_space" ;;
  esac
done < "$ENV_FILE"
