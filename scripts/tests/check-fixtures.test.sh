#!/usr/bin/env bash
# Tests for scripts/check-fixtures.sh. Run: bash scripts/tests/check-fixtures.test.sh
# Exit codes asserted: 0 = identical, 1 = differs or a file exists on one side only, 2 = bad usage.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$HERE/../check-fixtures.sh"
REPO="$HERE/../.."
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

failures=0
status=0
output=''

run_guard() {
  status=0
  output="$("$GUARD" "$@" 2>&1)" || status=$?
}

expect() { # <expected status> <label>
  if [ "$status" -ne "$1" ]; then
    failures=$((failures + 1))
    printf 'FAIL %s: expected exit %s, got %s\n%s\n' "$2" "$1" "$status" "$output"
  fi
}

fresh_pair() {
  rm -rf "$WORK/a" "$WORK/b"
  mkdir -p "$WORK/a" "$WORK/b"
  printf '{"x":1}\n' | tee "$WORK/a/one.json" >"$WORK/b/one.json"
}

fresh_pair
run_guard "$WORK/a" "$WORK/b"
expect 0 'identical directories'

fresh_pair
printf '{"x":2}\n' >"$WORK/b/one.json"
run_guard "$WORK/a" "$WORK/b"
expect 1 'a modified copy'
grep -qF 'one.json' <<<"$output" || { failures=$((failures + 1)); echo "FAIL output does not name one.json: $output"; }

fresh_pair
printf '{}\n' >"$WORK/a/extra.json"
run_guard "$WORK/a" "$WORK/b"
expect 1 'a file on one side only'

fresh_pair
printf 'not a fixture\n' >"$WORK/b/README.md"
run_guard "$WORK/a" "$WORK/b"
expect 0 'non-JSON files (READMEs) are not compared'

fresh_pair
run_guard "$WORK/a"
expect 2 'missing argument'

run_guard "$WORK/a" "$WORK/missing"
expect 2 'missing directory'

rm -rf "$WORK/empty-a" "$WORK/empty-b"
mkdir "$WORK/empty-a" "$WORK/empty-b"
run_guard "$WORK/empty-a" "$WORK/empty-b"
expect 2 'no JSON fixtures at all is an error, not a pass'

run_guard "$REPO/backend/tests/Fixtures/s3-events" "$REPO/docker/local-aws/tests/Fixtures/s3-events"
expect 0 'the real fixtures'

if [ "$failures" -ne 0 ]; then
  printf '%s check(s) failed\n' "$failures"
  exit 1
fi
echo 'check-fixtures: all checks passed'
