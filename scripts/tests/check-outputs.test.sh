#!/usr/bin/env bash
# Tests for scripts/check-outputs.sh. Run: bash scripts/tests/check-outputs.test.sh
# Exit codes asserted: 0 = outputs.tf holds exactly the seven D26 outputs and no secret, 1 = it does not, 2 = bad usage.
# Fixtures are created at run time in one fresh directory under build/ (git-ignored), removed on exit. The removal is
# guarded: only a non-empty path inside build/ whose name is outputs-test.* is ever deleted.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
GUARD="$HERE/../check-outputs.sh"
REAL="$REPO/infra/terraform/envs/dev/outputs.tf"

mkdir -p "$REPO/build"
TMP="$(mktemp -d "$REPO/build/outputs-test.XXXXXX")"

cleanup() {
  [ -n "${TMP:-}" ] || return 0
  local resolved build_root
  resolved="$(realpath -m -- "$TMP")"
  build_root="$(realpath -m -- "$REPO/build")"
  case "$resolved" in
    "$build_root"/outputs-test.*) [ "$(dirname "$resolved")" = "$build_root" ] && rm -rf -- "$resolved" ;;
  esac
  return 0
}
trap cleanup EXIT

failures=0
status=0
output=''

run_guard() {
  status=0
  output="$(timeout 60 "$GUARD" "$@" 2>&1)" || status=$?
}

fail() {
  failures=$((failures + 1))
  printf 'FAIL %s\n' "$1"
}

expect_status() { # <label> <expected>
  [ "$status" -eq "$2" ] || fail "$1: expected exit $2, got $status: $output"
}

# The real file passes.
run_guard
expect_status 'real file (default path)' 0
run_guard "$REAL"
expect_status 'real file (argument)' 0

content="$(<"$REAL")"

# A copy with one extra output that carries a secret fails, and the extra output is named.
extra="$TMP/extra.tf"
printf '%s\n\noutput "origin_verify_secret" {\n  value = random_password.origin_verify_a.result\n}\n' "$content" >"$extra"
run_guard "$extra"
expect_status 'extra output with a secret' 1
grep -qF 'origin_verify_secret' <<<"$output" || fail "extra output: the output does not name it: $output"

# An extra harmless output also fails (the list is exactly seven).
harmless="$TMP/harmless.tf"
printf '%s\n\noutput "region" {\n  value = "us-east-1"\n}\n' "$content" >"$harmless"
run_guard "$harmless"
expect_status 'extra harmless output' 1
grep -qF 'region' <<<"$output" || fail "extra harmless output: the output does not name it: $output"

# A secret mentioned inside a legitimate output fails.
mention="$TMP/mention.tf"
printf '%s\n' "${content//module.edge.domain_name/random_password.origin_verify_a.result}" >"$mention"
grep -qF 'random_password' "$mention" || fail 'fixture: the substitution did not happen'
run_guard "$mention"
expect_status 'secret mentioned in an existing output' 1
grep -qF 'random_password' <<<"$output" || fail "mention: the output does not say what was found: $output"

# An origin_verify mention alone (a module output) fails.
verify="$TMP/verify.tf"
printf '%s\n' "${content//module.edge.domain_name/module.edge.origin_verify_header_value}" >"$verify"
run_guard "$verify"
expect_status 'origin_verify mentioned' 1

# A renamed output fails: the new name is unexpected and the old one is missing.
renamed="$TMP/renamed.tf"
printf '%s\n' "${content//output \"table_name\"/output \"table\"}" >"$renamed"
grep -qF 'output "table"' "$renamed" || fail 'fixture: the rename did not happen'
run_guard "$renamed"
expect_status 'renamed output' 1
grep -qF 'unexpected output "table"' <<<"$output" || fail "renamed: the output does not name the new name: $output"

# A missing output fails.
missing="$TMP/missing.tf"
printf 'output "table_name" {\n  value = "x"\n}\n' >"$missing"
run_guard "$missing"
expect_status 'only one output' 1

# No output at all is an error, not a pass.
none="$TMP/none.tf"
printf '# nothing\n' >"$none"
run_guard "$none"
expect_status 'file without outputs' 1

# Bad usage.
run_guard "$TMP/does-not-exist.tf"
expect_status 'missing file' 2
run_guard one two
expect_status 'too many arguments' 2

if [ "$failures" -ne 0 ]; then
  printf '%d check(s) failed\n' "$failures"
  exit 1
fi
printf 'check-outputs tests: ok\n'
