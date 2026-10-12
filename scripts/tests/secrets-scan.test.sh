#!/usr/bin/env bash
# Tests for `make secrets-scan` (gitleaks over the git history). Run: bash scripts/tests/secrets-scan.test.sh
# Each case is a throwaway git repository in a temporary directory, scanned with the repository's Makefile
# (`make -C <tmp repo> -f Makefile secrets-scan`). Needs Docker (the pinned gitleaks image, no network).
# The fake key is generated at run time, so no secret-looking value is ever written into this repository.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MAKEFILE="$HERE/../../Makefile"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
status=0
output=''

fail() {
  failures=$((failures + 1))
  printf 'FAIL %s\n' "$1"
}

# A random value in the shape of an AWS access key id (which gitleaks reports), built from parts at run time.
fake_key() {
  local tail
  tail="$(LC_ALL=C tr -dc 'A-Z2-7' </dev/urandom | head -c 16)"
  printf '%s%s' 'AK''IA' "$tail"
}

new_repo() { # <dir>: an empty repository with one clean commit
  local dir="$TMP/$1"
  mkdir -p "$dir"
  git -C "$dir" init -q
  printf 'hello\n' >"$dir/README"
  commit "$1" 'init'
}

commit() { # <dir> <message>
  git -C "$TMP/$1" add -A
  git -C "$TMP/$1" -c user.name=test -c user.email=test@example.invalid -c commit.gpgsign=false commit -q -m "$2"
}

scan() { # <dir>
  status=0
  output="$(timeout 180 make -s --no-print-directory -C "$TMP/$1" -f "$MAKEFILE" secrets-scan 2>&1)" || status=$?
}

new_repo clean
scan clean
[ "$status" -eq 0 ] || fail "a clean history: expected exit 0, got $status: $output"

new_repo leak
printf 'aws_access_key_id = "%s"\n' "$(fake_key)" >"$TMP/leak/settings.ini"
commit leak 'add settings'
scan leak
[ "$status" -ne 0 ] || fail 'control: a committed access key is not reported (the cases below would prove nothing)'

# An inline `gitleaks:allow` comment must not hide a finding (--ignore-gitleaks-allow).
new_repo allow
printf 'aws_access_key_id = "%s" # gitleaks:allow\n' "$(fake_key)" >"$TMP/allow/settings.ini"
commit allow 'add settings'
scan allow
[ "$status" -ne 0 ] || fail "a gitleaks:allow comment hides a committed key: $output"

# A tracked .gitleaksignore (anywhere) could list fingerprints to skip; it is refused before the scan.
new_repo ignorefile
mkdir -p "$TMP/ignorefile/sub"
printf 'deadbeef:settings.ini:generic-api-key:1\n' >"$TMP/ignorefile/sub/.gitleaksignore"
commit ignorefile 'add ignore file'
scan ignorefile
[ "$status" -ne 0 ] || fail 'a tracked sub/.gitleaksignore is accepted'
grep -qF '.gitleaksignore' <<<"$output" || fail ".gitleaksignore: output does not name it: $output"

if [ "$failures" -ne 0 ]; then
  printf '%s check(s) failed\n' "$failures"
  exit 1
fi
echo 'secrets-scan: all checks passed'
