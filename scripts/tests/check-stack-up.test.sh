#!/usr/bin/env bash
# Tests for scripts/check-stack-up.sh (the guard at the start of `make test-integration`). Run: bash scripts/tests/check-stack-up.test.sh
# A fake `docker` is placed first on PATH: there is no real docker here, and nothing is ever started or stopped. The fake
# answers `config --services` and `ps --services --status running` from FAKE_* variables and logs every call, so the
# test can also prove that only those two read-only subcommands are used. Failures are injected with FAKE_FAIL (every call),
# FAKE_FAIL_CONFIG and FAKE_FAIL_PS (one subcommand). Exit codes asserted exactly: 0 = up, 1 = not up or docker could not answer.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$HERE/../check-stack-up.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

mkdir "$WORK/bin"
cat >"$WORK/bin/docker" <<'FAKE_END'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$FAKE_LOG"
[ "${FAKE_FAIL:-}" = "1" ] && { echo "fake docker: daemon not reachable" >&2; exit 1; }
case " $* " in
  *" config --services "*)
    [ "${FAKE_FAIL_CONFIG:-}" = "1" ] && { echo "fake docker: config failed" >&2; exit 1; }
    printf '%s\n' $FAKE_ALL ;;
  *" ps --services --status running "*)
    [ "${FAKE_FAIL_PS:-}" = "1" ] && { echo "fake docker: ps failed" >&2; exit 1; }
    printf '%s\n' $FAKE_RUNNING ;;
  *) echo "fake docker: unexpected call: $*" >&2; exit 99 ;;
esac
FAKE_END
chmod +x "$WORK/bin/docker"
export PATH="$WORK/bin:$PATH"
export FAKE_LOG="$WORK/calls.log"

ALL='dynamodb sqs s3 api-php api frontend worker worker-runner local-aws-init'
RUNNING_ALL='dynamodb sqs s3 api-php api frontend worker worker-runner'

failures=0
status=0
output=''

run_guard() { # <running services> [guard args...]
  export FAKE_ALL="${ALL_SERVICES-$ALL}" FAKE_RUNNING="$1"
  shift
  : >"$FAKE_LOG"
  status=0
  output="$("$GUARD" "$@" 2>&1)" || status=$?
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
  if grep -qF -- "$1" <<<"$output"; then fail "$2: output contains '$1': $output"; fi
}

run_guard "$RUNNING_ALL"
expect 0 'every service running (the one-shot local-aws-init is not required)'

run_guard "dynamodb sqs s3 api-php api frontend worker-runner"
expect 1 'one service missing'
expect_in_output 'worker' 'one service missing'
expect_in_output 'make up' 'one service missing'
expect_not_in_output 'local-aws-init' 'the one-shot is never reported'

run_guard "dynamodb sqs s3 api-php api frontend worker"
expect 1 'worker-runner missing'
expect_in_output 'worker-runner' 'worker-runner missing'

run_guard ""
expect 1 'nothing running'
expect_in_output 'dynamodb' 'nothing running'
expect_in_output 'make up' 'nothing running'

FAKE_FAIL=1 run_guard "$RUNNING_ALL"
expect 1 'docker failing'
expect_in_output 'config --services' 'docker failing'

FAKE_FAIL_CONFIG=1 run_guard "$RUNNING_ALL"
expect 1 'config failing'
expect_in_output "'config --services' failed" 'config failing'

FAKE_FAIL_PS=1 run_guard "$RUNNING_ALL"
expect 1 'ps failing while config succeeds'
expect_in_output "'ps' failed" 'ps failing while config succeeds'
expect_not_in_output 'Not running' 'ps failing while config succeeds'

ALL_SERVICES= run_guard "$RUNNING_ALL"
expect 1 'config lists no services'
expect_in_output 'listed no services' 'config lists no services'

run_guard "$RUNNING_ALL" env -u AWS_PROFILE docker compose -p expense-tracker
expect 0 'the compose command given as arguments (the Makefile form)'
grep -qF -- 'compose -p expense-tracker config --services' "$FAKE_LOG" || fail "compose arguments: call not seen: $(cat "$FAKE_LOG")"

run_guard "$RUNNING_ALL"
if grep -qE -- ' (up|down|start|stop|rm|restart|kill|run) ' <<<" $(cat "$FAKE_LOG") "; then
  fail "read-only: a state-changing compose subcommand was called: $(cat "$FAKE_LOG")"
fi

if [ "$failures" -ne 0 ]; then
  printf '%s check(s) failed\n' "$failures"
  exit 1
fi
echo 'check-stack-up: all checks passed'
