#!/usr/bin/env bash
# A helper named run() in a test class is a fatal error, not a failing test.
#
# PHPUnit\Framework\TestCase::run() is final, so PHP aborts while *loading* the
# class — which takes the whole suite with it and reports the error against
# PHPUnit rather than against the file that caused it. A PHPUnit-based guard
# cannot catch this: it never gets to run.
#
# So it is checked here, before the suite starts.
set -uo pipefail

cd "$(dirname "$0")/.."

offenders=$(grep -rln --include='*Test.php' -E 'function[[:space:]]+run[[:space:]]*\(' tests/ 2>/dev/null || true)

if [ -n "$offenders" ]; then
  echo "✗ a test class declares run(), which is final on PHPUnit\\Framework\\TestCase:"
  echo "$offenders" | sed 's/^/    /'
  echo
  echo "  PHP aborts while loading the class, so the whole suite dies and the"
  echo "  error points at PHPUnit instead of at the file. Rename the helper."
  exit 1
fi

echo "✓ no test class shadows PHPUnit's final run()"
