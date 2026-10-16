#!/usr/bin/env bash
# Tests for scripts/check-env.sh (the logic of `make check-env`). Run: bash scripts/tests/check-env.test.sh
# Only temporary env files are used, never the real .env. Exit codes asserted: 0 = ok, 1 = a problem, 2 = bad usage.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$HERE/../check-env.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

failures=0
status=0
output=''

EXAMPLE="$WORK/example"
ENVFILE="$WORK/env"
cat >"$EXAMPLE" <<'EXAMPLE_END'
AWS_REGION=eu-west-1
AWS_ACCESS_KEY_ID=localdev
AWS_SECRET_ACCESS_KEY=localdevsecret
DYNAMODB_ENDPOINT=http://dynamodb:8000
DYNAMODB_TABLE=expense-tracker-local
EXAMPLE_END

run_guard() { # <env file>
  status=0
  output="$("$GUARD" "$1" "$EXAMPLE" 2>&1)" || status=$?
}

fail() {
  failures=$((failures + 1))
  printf 'FAIL %s\n' "$1"
}

expect() { # <expected status> <label>
  if [ "$status" -ne "$1" ]; then
    fail "$2: expected exit $1, got $status: $output"
  fi
}

expect_in_output() { # <text> <label>
  grep -qF -- "$1" <<<"$output" || fail "$2: output does not contain '$1': $output"
}

expect_not_in_output() { # <text> <label>
  if grep -qF -- "$1" <<<"$output"; then fail "$2: output leaks '$1'"; fi
}

fresh_env() { cp "$EXAMPLE" "$ENVFILE"; }

fresh_env
run_guard "$ENVFILE"
expect 0 'the fake pair from the example'

fresh_env
sed -i 's/^AWS_ACCESS_KEY_ID=.*/AWS_ACCESS_KEY_ID=AKIAREALLOOKINGKEY1/' "$ENVFILE"
run_guard "$ENVFILE"
expect 1 'a different key id'
expect_in_output 'AWS_ACCESS_KEY_ID' 'a different key id'
expect_not_in_output 'AKIAREALLOOKINGKEY1' 'a different key id'
expect_not_in_output 'localdev' 'a different key id'

fresh_env
sed -i 's/^AWS_SECRET_ACCESS_KEY=.*/AWS_SECRET_ACCESS_KEY=SuperSecretRealValue99/' "$ENVFILE"
run_guard "$ENVFILE"
expect 1 'a different secret key'
expect_in_output 'AWS_SECRET_ACCESS_KEY' 'a different secret key'
expect_not_in_output 'SuperSecretRealValue99' 'a different secret key'

fresh_env
printf 'AWS_SESSION_TOKEN=FwoGZXIvYXdzEtokenvalue\n' >>"$ENVFILE"
run_guard "$ENVFILE"
expect 1 'a session token'
expect_in_output 'AWS_SESSION_TOKEN' 'a session token'
expect_not_in_output 'FwoGZXIvYXdzEtokenvalue' 'a session token'

fresh_env
printf 'AWS_SESSION_TOKEN=\n' >>"$ENVFILE"
run_guard "$ENVFILE"
expect 1 'an empty session token line is still a session token line'

fresh_env
sed -i '/^DYNAMODB_ENDPOINT=/d' "$ENVFILE"
run_guard "$ENVFILE"
expect 1 'a missing endpoint'
expect_in_output "add 'DYNAMODB_ENDPOINT=http://dynamodb:8000'" 'a missing endpoint'

fresh_env
sed -i '/^DYNAMODB_TABLE=/d' "$ENVFILE"
run_guard "$ENVFILE"
expect 1 'a missing table'
expect_in_output 'DYNAMODB_TABLE is missing from .env' 'a missing table'

fresh_env
sed -i 's/$/\r/' "$ENVFILE"
run_guard "$ENVFILE"
expect 0 'CRLF line endings'

fresh_env
sed -i 's/^/export /' "$ENVFILE"
run_guard "$ENVFILE"
expect 0 'export lines'

fresh_env
sed -i 's/^/export /; s/$/\r/' "$ENVFILE"
sed -i 's/^export AWS_ACCESS_KEY_ID=.*/export AWS_ACCESS_KEY_ID=AKIAOTHERKEYCRLF\r/' "$ENVFILE"
run_guard "$ENVFILE"
expect 1 'a different key id with export and CRLF'
expect_not_in_output 'AKIAOTHERKEYCRLF' 'a different key id with export and CRLF'

run_guard "$WORK/missing"
expect 2 'a missing env file'

status=0
output="$("$GUARD" "$ENVFILE" 2>&1)" || status=$?
expect 2 'a missing argument'

if [ "$failures" -ne 0 ]; then
  printf '%s check(s) failed\n' "$failures"
  exit 1
fi
echo 'check-env: all checks passed'
