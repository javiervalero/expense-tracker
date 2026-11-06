#!/usr/bin/env bash
# Tests for scripts/check-prevent-destroy.sh. Run: bash scripts/tests/check-prevent-destroy.test.sh
# Exit codes asserted: 0 = every aws_s3_bucket* resource has `prevent_destroy = true`, 1 = one lacks it, 2 = bad usage.
# All fixtures are created at run time in a temporary directory.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$HERE/../check-prevent-destroy.sh"

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

protected() { # <type> <name>: a protected resource block
  printf 'resource "%s" "%s" {\n  bucket = "x"\n\n  lifecycle {\n    prevent_destroy = true\n  }\n}\n\n' "$1" "$2"
}

# All protected passes.
d="$(new_dir ok)"
{ protected aws_s3_bucket state; protected aws_s3_bucket_versioning state; } >"$d/main.tf"
run_guard "$d"
expect_status 'all protected' 0

# One missing fails and is named.
d="$(new_dir missing)"
{
  protected aws_s3_bucket state
  printf 'resource "aws_s3_bucket_policy" "state" {\n  bucket = "x"\n}\n'
} >"$d/main.tf"
run_guard "$d"
expect_status 'one missing' 1
grep -qF 'aws_s3_bucket_policy.state' <<<"$output" || fail "missing: output does not name the resource: $output"
grep -qF 'aws_s3_bucket.state' <<<"$output" && fail "missing: output names the protected bucket: $output"

# A commented-out prevent_destroy does not count.
d="$(new_dir commented)"
printf 'resource "aws_s3_bucket" "state" {\n  # prevent_destroy = true\n}\n' >"$d/main.tf"
run_guard "$d"
expect_status 'commented prevent_destroy' 1

# prevent_destroy = false fails.
d="$(new_dir false)"
printf 'resource "aws_s3_bucket" "state" {\n  lifecycle {\n    prevent_destroy = false\n  }\n}\n' >"$d/main.tf"
run_guard "$d"
expect_status 'prevent_destroy false' 1

# A prevent_destroy in the NEXT block does not protect this one.
d="$(new_dir neighbour)"
{
  printf 'resource "aws_s3_bucket" "a" {\n}\n\n'
  protected aws_s3_bucket b
} >"$d/main.tf"
run_guard "$d"
expect_status 'neighbour protected' 1
grep -qF 'aws_s3_bucket.a' <<<"$output" || fail "neighbour: output does not name a: $output"

# Non-S3 resources are ignored.
d="$(new_dir other)"
printf 'resource "aws_iam_role" "r" {\n  name = "x"\n}\n' >"$d/main.tf"
run_guard "$d"
expect_status 'non-S3 resource' 0

# Nested directories are scanned.
d="$(new_dir nested)"
mkdir -p "$d/a/b"
printf 'resource "aws_s3_bucket" "deep" {\n}\n' >"$d/a/b/deep.tf"
run_guard "$d"
expect_status 'nested unprotected' 1
grep -qF 'aws_s3_bucket.deep' <<<"$output" || fail "nested: output does not name the resource: $output"

# No .tf files passes.
d="$(new_dir empty)"
run_guard "$d"
expect_status 'no .tf files' 0

# A directory name with spaces and a file name with spaces are handled.
d="$(new_dir 'with space')"
printf 'resource "aws_s3_bucket" "sp" {\n}\n' >"$d/my file.tf"
run_guard "$d"
expect_status 'spaces' 1

# A leading-dash directory is not read as an option.
mkdir -p "$TMP/dash/-dir"
printf 'resource "aws_s3_bucket" "dd" {\n}\n' >"$TMP/dash/-dir/main.tf"
status=0
output="$(cd "$TMP/dash" && timeout 60 "$GUARD" -dir 2>&1)" || status=$?
expect_status 'leading dash' 1
status=0
output="$(cd "$TMP/dash" && timeout 60 "$GUARD" ./-dir 2>&1)" || status=$?
expect_status 'leading dash with ./' 1

# Bad usage: a path that is not a directory.
run_guard "$TMP/does-not-exist"
expect_status 'missing directory' 2

# --require-bucket (used by make tf-unit): a directory that holds no aws_s3_bucket resource is an error (exit 2), so
# renaming or emptying the bootstrap directory cannot silently switch the check off.
d="$(new_dir req-empty)"
run_guard --require-bucket "$d"
expect_status '--require-bucket on a directory without .tf files' 2
grep -qi 'aws_s3_bucket' <<<"$output" || fail "--require-bucket empty: message does not mention the bucket: $output"
d="$(new_dir req-nobucket)"
printf 'resource "aws_s3_bucket_policy" "state" {\n  lifecycle {\n    prevent_destroy = true\n  }\n}\n' >"$d/main.tf"
run_guard --require-bucket "$d"
expect_status '--require-bucket with only a bucket child resource' 2
d="$(new_dir req-ok)"
protected aws_s3_bucket state >"$d/main.tf"
run_guard --require-bucket "$d"
expect_status '--require-bucket with a protected bucket' 0
d="$(new_dir req-bad)"
printf 'resource "aws_s3_bucket" "state" {\n  bucket = "x"\n}\n' >"$d/main.tf"
run_guard --require-bucket "$d"
expect_status '--require-bucket with an unprotected bucket' 1

# Windows line endings, tabs and a trailing comment still count as a protected resource.
d="$(new_dir crlf)"
printf 'resource "aws_s3_bucket" "state" {\r\n\tlifecycle {\r\n\t\tprevent_destroy = true # keep\r\n\t}\r\n}\r\n' >"$d/main.tf"
run_guard --require-bucket "$d"
expect_status 'CRLF, tabs and a trailing comment' 0

# The real bootstrap root (the default directory) is protected and holds the bucket: exit 0.
status=0
output="$(timeout 60 "$GUARD" --require-bucket 2>&1)" || status=$?
[ "$status" -eq 0 ] || fail "default directory with --require-bucket: expected exit 0, got $status: $output"

if [ "$failures" -ne 0 ]; then
  printf '%d check(s) failed\n' "$failures"
  exit 1
fi
printf 'check-prevent-destroy tests: ok\n'
