#!/usr/bin/env bash
# Conflict markers must never reach a branch.
#
# `git add -A` after a merge stages every file, including ones still holding
# their markers — git reads that as "resolved" and the commit goes through. It
# happened here: a merge reported four conflicts, the output was read through
# `tail -8`, and the fourth went in. CI caught it only because the file was PHP
# and the parser objected. The same markers in a `.md`, `.json`, `.css` or a
# YAML workflow would have reached `develop` with nothing to object at all.
#
# So the whole tree is checked, not the parseable part of it.
set -uo pipefail

cd "$(dirname "$0")/.."

# `=======` alone is too common in prose and rulers to match on, so the two
# unambiguous markers are what this looks for.
offenders=$(git grep -lE '^(<<<<<<< |>>>>>>> )' -- \
  ':!*.lock' ':!**/node_modules/**' ':!**/vendor/**' 2>/dev/null || true)

if [ -n "$offenders" ]; then
  echo "✗ conflict markers are committed in:"
  echo "$offenders" | sed 's/^/    /'
  echo
  echo "  A merge left these behind and 'git add -A' staged them as resolved."
  echo "  Resolve each hunk, then re-run this before committing."
  exit 1
fi

echo "✓ no conflict markers in the tree"
