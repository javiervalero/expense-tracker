#!/usr/bin/env bash
# Tests for scripts/check-tftest-mocks.sh. Run: bash scripts/tests/check-tftest-mocks.test.sh
# Exit codes asserted: 0 = every *.tftest.hcl mocks the aws provider, 1 = one lacks the mock, 2 = bad usage.
# All fixtures are created at run time in a temporary directory.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$HERE/../check-tftest-mocks.sh"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

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

new_dir() { # <name>: prints a fresh empty directory
  mkdir -p "$TMP/$1"
  printf '%s' "$TMP/$1"
}

# A test file with the mock passes.
d="$(new_dir ok)"
printf 'mock_provider "aws" {}\n\nrun "x" {\n  command = plan\n}\n' >"$d/a.tftest.hcl"
run_guard "$d"
expect_status 'file with the mock' 0

# A file without the mock fails and is named.
d="$(new_dir missing)"
printf 'run "x" {\n  command = plan\n}\n' >"$d/bad.tftest.hcl"
run_guard "$d"
expect_status 'file without the mock' 1
grep -qF 'bad.tftest.hcl' <<<"$output" || fail "missing: output does not name the file: $output"

# One good and one bad file: still fails.
d="$(new_dir mixed)"
printf 'mock_provider "aws" {}\n' >"$d/good.tftest.hcl"
printf '# mock_provider "aws" is only mentioned in a comment\n' >"$d/bad.tftest.hcl"
run_guard "$d"
expect_status 'commented mention does not count' 1
grep -qF 'bad.tftest.hcl' <<<"$output" || fail "mixed: output does not name bad file: $output"
grep -qF 'good.tftest.hcl' <<<"$output" && fail "mixed: output names the good file: $output"

# A different provider mock is not enough.
d="$(new_dir other)"
printf 'mock_provider "random" {}\n' >"$d/other.tftest.hcl"
run_guard "$d"
expect_status 'mock of another provider' 1

# Nested directories are scanned.
d="$(new_dir nested)"
mkdir -p "$d/envs/dev/tests"
printf 'run "x" {}\n' >"$d/envs/dev/tests/deep.tftest.hcl"
run_guard "$d"
expect_status 'nested file without the mock' 1

# A name with spaces is handled.
d="$(new_dir spaces)"
printf 'run "x" {}\n' >"$d/with space.tftest.hcl"
run_guard "$d"
expect_status 'file name with a space' 1
grep -qF 'with space.tftest.hcl' <<<"$output" || fail "spaces: output does not name the file: $output"

# No test files at all is fine (bootstrap has none yet).
d="$(new_dir empty)"
run_guard "$d"
expect_status 'no test files' 0

# A mock next to a real provider block, or a run block that passes a provider, is rejected (the mock would be bypassed).
d="$(new_dir realprovider)"
printf 'mock_provider "aws" {}\nprovider "aws" {\n  region = "us-east-1"\n}\n' >"$d/p.tftest.hcl"
run_guard "$d"
expect_status 'real provider block next to the mock' 1
d="$(new_dir providersarg)"
printf 'mock_provider "aws" {}\nrun "x" {\n  providers = {\n    aws = aws\n  }\n}\n' >"$d/q.tftest.hcl"
run_guard "$d"
expect_status 'run block passing providers' 1

# A mock inside a block comment does not count.
d="$(new_dir blockcomment)"
printf '/*\nmock_provider "aws" {}\n*/\nrun "x" {}\n' >"$d/c.tftest.hcl"
run_guard "$d"
expect_status 'mock inside a block comment' 1

# The JSON test format is loaded by Terraform too: rejected outright.
d="$(new_dir json)"
printf '{}\n' >"$d/j.tftest.json"
run_guard "$d"
expect_status 'tftest.json file' 1

# A symlink named *.tftest.hcl is checked like a file.
d="$(new_dir symlink)"
printf 'run "x" {}\n' >"$TMP/target-without-mock"
ln -s "$TMP/target-without-mock" "$d/link.tftest.hcl"
run_guard "$d"
expect_status 'symlinked test file without the mock' 1

# A scan that cannot read a directory fails closed (exit 2), it does not report "no test files".
if [ "$(id -u)" -ne 0 ]; then
  d="$(new_dir unreadable)"
  mkdir -p "$d/locked"
  chmod 000 "$d/locked"
  run_guard "$d"
  chmod 755 "$d/locked"
  expect_status 'unreadable directory' 2
else
  echo 'SKIP unreadable directory (running as root: permissions do not apply)'
fi

# A relative directory argument starting with a dash is a directory, not a find option.
d="$(new_dir -dash)"
printf 'run "x" {}\n' >"$d/d.tftest.hcl"
dash_status=0
(cd "$TMP" && "$GUARD" -dash >/dev/null 2>&1) || dash_status=$?
[ "$dash_status" -eq 1 ] || fail "dash directory: expected exit 1, got $dash_status"

# Round 2: a symlinked directory is followed, as Terraform does (as the argument and as a subdirectory).
mkdir -p "$TMP/realtests"
printf 'run "x" {}\n' >"$TMP/realtests/x.tftest.hcl"
d="$(new_dir symdir)"
ln -s "$TMP/realtests" "$d/tests"
run_guard "$d"
expect_status 'symlinked subdirectory with an unmocked test' 1
ln -s "$TMP/realtests" "$TMP/linkarg"
run_guard "$TMP/linkarg"
expect_status 'symlinked directory argument with an unmocked test' 1

# A dangling symlink named *.tftest.hcl cannot be read: fails closed.
d="$(new_dir dangling)"
ln -s "$TMP/nowhere" "$d/gone.tftest.hcl"
run_guard "$d"
expect_status 'dangling symlink' 2

# Provider written without the usual spacing, or on one line with a run block.
d="$(new_dir tight)"
printf 'mock_provider "aws" {}\nprovider"aws" {}\n' >"$d/t.tftest.hcl"
run_guard "$d"
expect_status 'provider"aws" without a space' 1
d="$(new_dir oneline)"
printf 'mock_provider "aws" {}\nrun "x" { providers = { aws = aws } }\n' >"$d/o.tftest.hcl"
run_guard "$d"
expect_status 'providers on the same line as a run block' 1

# A comment opener inside a line comment or a string must not hide the lines that follow.
d="$(new_dir fakeblock1)"
printf 'mock_provider "aws" {}\n// note /* oops\nprovider "aws" {}\n' >"$d/f1.tftest.hcl"
run_guard "$d"
expect_status 'block opener inside a // comment' 1
d="$(new_dir fakeblock2)"
printf 'mock_provider "aws" {}\nvariables {\n  a = "x/*"\n}\nprovider "aws" {}\n' >"$d/f2.tftest.hcl"
run_guard "$d"
expect_status 'block opener inside a string' 1

# A real mock followed by a trailing comment and a block comment around other code still passes.
d="$(new_dir okcomments)"
printf 'mock_provider "aws" {} # the mock\n/* provider "aws" {} */\nrun "x" {}\n' >"$d/ok.tftest.hcl"
run_guard "$d"
expect_status 'commented-out provider next to a real mock' 0

# Bad usage: missing directory, no argument.
run_guard "$TMP/does-not-exist"
expect_status 'missing directory' 2
run_guard
expect_status 'no argument scans the repository tests, which are all mocked' 0

if [ "$failures" -ne 0 ]; then
  printf '%d failure(s)\n' "$failures"
  exit 1
fi
echo 'check-tftest-mocks: all cases passed'
