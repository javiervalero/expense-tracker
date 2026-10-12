#!/usr/bin/env bash
# Tests for scripts/check-php-platform.sh. Run: bash scripts/tests/check-php-platform.test.sh
#
# They use the real bref/php-85 image pinned by digest in the fixture Dockerfile (pulled on first use), so
# they prove what the guard claims: the PHP version inside that exact layer. Exit codes asserted:
# 0 = match, 1 = mismatch or vendor check failed, 2 = bad usage or unreadable input, 3 = no pinned bref image.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$HERE/../check-php-platform.sh"
FIXTURES="$HERE/fixtures"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

failures=0
checks=0
output=''
status=0

run_guard() {
  status=0
  output="$("$GUARD" "$@" 2>&1)" || status=$?
}

expect_status() { # <expected> <label>
  checks=$((checks + 1))
  if [ "$status" -ne "$1" ]; then
    failures=$((failures + 1))
    printf 'FAIL %s: expected exit %s, got %s\n%s\n' "$2" "$1" "$status" "$output"
  fi
}

expect_output() { # <fixed string> <label>
  checks=$((checks + 1))
  if ! grep -qF -- "$1" <<<"$output"; then
    failures=$((failures + 1))
    printf 'FAIL %s: output lacks "%s"\n%s\n' "$2" "$1" "$output"
  fi
}

# --- the guard exists and is executable
if [ ! -x "$GUARD" ]; then
  printf 'FAIL guard script %s is missing or not executable\n' "$GUARD"
  exit 1
fi

# --- matching composer.json: exit 0 and says which versions were compared
run_guard --composer-json "$FIXTURES/composer.match.json" "$FIXTURES/Dockerfile.worker"
expect_status 0 'matching platform'
expect_output 'OK' 'matching platform'
expect_output '8.5.11' 'matching platform reports the version'

# --- same, forcing the php-in-image reader (used when jq is not installed)
USE_JQ=0 run_guard --composer-json "$FIXTURES/composer.match.json" "$FIXTURES/Dockerfile.worker"
expect_status 0 'matching platform without jq'

# --- mismatching composer.json: exit 1 and names both versions
run_guard --composer-json "$FIXTURES/composer.mismatch.json" "$FIXTURES/Dockerfile.worker"
expect_status 1 'mismatching platform'
expect_output 'MISMATCH' 'mismatching platform'
expect_output '8.4.0' 'mismatching platform names composer platform'
expect_output '8.5.11' 'mismatching platform names image version'

# --- composer.json without config.platform.php
run_guard --composer-json "$FIXTURES/composer.no-platform.json" "$FIXTURES/Dockerfile.worker"
expect_status 2 'no config.platform.php'
expect_output 'config.platform.php' 'no config.platform.php'

# --- Dockerfile problems
run_guard --composer-json "$FIXTURES/composer.match.json" "$FIXTURES/Dockerfile.no-digest"
expect_status 3 'bref FROM without digest'
expect_output '@sha256' 'bref FROM without digest'

run_guard --composer-json "$FIXTURES/composer.match.json" "$FIXTURES/Dockerfile.no-bref"
expect_status 3 'no bref/php-85 FROM'
expect_output 'bref/php-85' 'no bref/php-85 FROM'

run_guard --composer-json "$FIXTURES/composer.match.json" "$WORK/missing/Dockerfile"
expect_status 2 'missing Dockerfile'
expect_output 'not found' 'missing Dockerfile'

run_guard --composer-json "$WORK/missing.json" "$FIXTURES/Dockerfile.worker"
expect_status 2 'missing composer.json'
expect_output 'not found' 'missing composer.json'

# --- usage errors
run_guard --no-such-option
expect_status 2 'unknown option'
expect_output 'Usage' 'unknown option'

# --- composer.json path handling: relative paths are resolved, ':' and ',' (docker mount syntax) are refused
status=0
output="$(cd "$FIXTURES" && USE_JQ=0 "$GUARD" --composer-json composer.match.json Dockerfile.worker 2>&1)" || status=$?
expect_status 0 'relative --composer-json is resolved to an absolute mount path'
expect_output 'OK' 'relative --composer-json'

COLON_JSON="$WORK/with:colon.json"
cp "$FIXTURES/composer.match.json" "$COLON_JSON"
run_guard --composer-json "$COLON_JSON" "$FIXTURES/Dockerfile.worker"
expect_status 2 "--composer-json containing ':'"
expect_output "':'" "--composer-json containing ':'"

# --- VENDOR_CHECK_DIR guard: refuse dangerous values BEFORE any rm. The tools that could change anything are
# replaced by logging stubs, so even a guard that is broken cannot delete a real directory in this test.
STUBS="$WORK/stubs"
RM_LOG="$WORK/rm.log"
mkdir -p "$STUBS"
printf '#!/bin/sh\necho "$@" >> "%s"\n' "$RM_LOG" >"$STUBS/rm"
printf '#!/bin/sh\nexit 0\n' >"$STUBS/mkdir"
printf '#!/bin/sh\nexit 0\n' >"$STUBS/cp"
printf '#!/bin/sh\necho 8.5.11\n' >"$STUBS/docker"
chmod +x "$STUBS"/*

FAKE_ROOT="$WORK/fakeroot"
mkdir -p "$FAKE_ROOT/scripts" "$FAKE_ROOT/build"
cp "$GUARD" "$FAKE_ROOT/scripts/check-php-platform.sh"
FAKE_HOME="$WORK/fakehome"
mkdir -p "$FAKE_HOME"

expect_vendor_dir_refused() { # <label> <guard path> <VENDOR_CHECK_DIR> <HOME> <sentinel dir>
  : >"$RM_LOG"
  mkdir -p "$5"
  : >"$5/sentinel"
  status=0
  output="$(PATH="$STUBS:$PATH" HOME="$4" VENDOR_CHECK_DIR="$3" "$2" --with-vendor \
    --composer-json "$FIXTURES/composer.match.json" "$FIXTURES/Dockerfile.worker" 2>&1)" || status=$?
  expect_status 2 "$1 is refused"
  expect_output 'VENDOR_CHECK_DIR' "$1 is refused with a clear message"
  checks=$((checks + 1))
  if [ -s "$RM_LOG" ] || [ ! -e "$5/sentinel" ]; then
    failures=$((failures + 1))
    printf 'FAIL %s: rm ran (%s) or the sentinel is gone\n' "$1" "$(cat "$RM_LOG")"
  fi
}

expect_vendor_dir_refused 'VENDOR_CHECK_DIR=/' "$GUARD" / "$FAKE_HOME" "$FAKE_HOME"
expect_vendor_dir_refused 'VENDOR_CHECK_DIR=$HOME' "$GUARD" "$FAKE_HOME" "$FAKE_HOME" "$FAKE_HOME"
expect_vendor_dir_refused 'VENDOR_CHECK_DIR=$ROOT' "$FAKE_ROOT/scripts/check-php-platform.sh" "$FAKE_ROOT" "$FAKE_HOME" "$FAKE_ROOT"
expect_vendor_dir_refused 'VENDOR_CHECK_DIR empty' "$GUARD" '' "$FAKE_HOME" "$FAKE_HOME"
expect_vendor_dir_refused 'VENDOR_CHECK_DIR outside build/ and temp' "$GUARD" "$HOME/not-allowed-$$" "$FAKE_HOME" "$FAKE_HOME"
# build/../../.. climbs out of the fake repo and its temp parent, landing on the temp dir itself
expect_vendor_dir_refused 'VENDOR_CHECK_DIR with .. escaping build/' "$FAKE_ROOT/scripts/check-php-platform.sh" "$FAKE_ROOT/build/../../.." "$FAKE_HOME" "$WORK"

# --- vendor check: needs the dev image (composer); skipped with a message when it is not built
PHP_IMAGE="${PHP_IMAGE:-expense-tracker-php:dev}"
if docker image inspect "$PHP_IMAGE" >/dev/null 2>&1; then
  VENDOR_DIR="$WORK/vendor-check"
  export VENDOR_CHECK_DIR="$VENDOR_DIR"
  run_guard --with-vendor --composer-json "$FIXTURES/vendor-ok/composer.json" "$FIXTURES/Dockerfile.worker"
  expect_status 0 'vendor check with a clean production install'
  expect_output 'vendor/autoload.php loads' 'vendor check'
  checks=$((checks + 1))
  if [ ! -f "$VENDOR_DIR/vendor/autoload.php" ]; then
    failures=$((failures + 1))
    echo 'FAIL vendor check did not install into the vendor-check directory'
  fi

  # a mismatch must still fail before anything is installed
  rm -rf "$VENDOR_DIR"
  run_guard --with-vendor --composer-json "$FIXTURES/composer.mismatch.json" "$FIXTURES/Dockerfile.worker"
  expect_status 1 'vendor check does not hide a mismatch'
  checks=$((checks + 1))
  if [ -e "$VENDOR_DIR" ]; then
    failures=$((failures + 1))
    echo 'FAIL a mismatch should stop before installing anything'
  fi
else
  echo "SKIP vendor check tests: image $PHP_IMAGE not built (run: make php-image)"
fi

if [ "$failures" -ne 0 ]; then
  printf '%s of %s checks failed\n' "$failures" "$checks"
  exit 1
fi
printf 'All %s checks passed\n' "$checks"
