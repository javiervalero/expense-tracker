#!/usr/bin/env bash
# Tests for `make secrets-scan` (gitleaks over the git history AND over uncommitted work: staged, unstaged, untracked). Run: bash scripts/tests/secrets-scan.test.sh
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

scan() { # <dir> [target]: TMPDIR is private to the run, so leftovers of the scan can be detected
  status=0
  mkdir -p "$TMP/tmpdir"
  output="$(TMPDIR="$TMP/tmpdir" timeout 180 make -s --no-print-directory -C "$TMP/$1" -f "$MAKEFILE" "${2:-secrets-scan}" 2>&1)" || status=$?
}

stage() { git -C "$TMP/$1" add -A; }

new_repo clean
scan clean
[ "$status" -eq 0 ] || fail "a clean history: expected exit 0, got $status: $output"

new_repo leak
key="$(fake_key)"
printf 'aws_access_key_id = "%s"\n' "$key" >"$TMP/leak/settings.ini"
commit leak 'add settings'
scan leak
[ "$status" -ne 0 ] || fail 'control: a committed access key is not reported (the cases below would prove nothing)'
! grep -qF "$key" <<<"$output" || fail 'history scan: the key is printed in clear'

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

# --- Uncommitted work: the history scan alone never sees it ---------------------------------------------------------

new_repo wt-clean
scan wt-clean
[ "$status" -eq 0 ] || fail "a clean tree: expected exit 0, got $status: $output"
grep -qF 'nothing uncommitted to scan' <<<"$output" || fail "a clean tree: no 'nothing uncommitted to scan' message: $output"
[ -z "$(ls -A "$TMP/tmpdir")" ] || fail "a clean tree: the scan left files in TMPDIR: $(ls -A "$TMP/tmpdir")"

new_repo wt-untracked
key="$(fake_key)"
printf 'aws_access_key_id = "%s"\n' "$key" >"$TMP/wt-untracked/settings.ini"
scan wt-untracked
[ "$status" -ne 0 ] || fail "a key in an untracked file is not reported: $output"
grep -qF 'REDACTED' <<<"$output" || fail "untracked: the finding is not redacted: $output"
! grep -qF "$key" <<<"$output" || fail 'worktree scan: the key is printed in clear'
[ -z "$(ls -A "$TMP/tmpdir")" ] || fail "a failing scan left files in TMPDIR: $(ls -A "$TMP/tmpdir")"

new_repo wt-staged
printf 'aws_access_key_id = "%s"\n' "$(fake_key)" >"$TMP/wt-staged/settings.ini"
stage wt-staged
scan wt-staged
[ "$status" -ne 0 ] || fail "a key in a staged new file is not reported: $output"

new_repo wt-unstaged
printf 'aws_access_key_id = "%s"\n' "$(fake_key)" >>"$TMP/wt-unstaged/README"
scan wt-unstaged
[ "$status" -ne 0 ] || fail "a key in an unstaged edit of a tracked file is not reported: $output"

# Staged blobs are committed content too: the index is scanned apart from the disk.
new_repo wt-staged-removed # staged key, then overwritten on disk
printf 'aws_access_key_id = "%s"\n' "$(fake_key)" >"$TMP/wt-staged-removed/settings.ini"
stage wt-staged-removed
printf 'clean\n' >"$TMP/wt-staged-removed/settings.ini"
scan wt-staged-removed
[ "$status" -ne 0 ] || fail "a staged key overwritten on disk is not reported: $output"

new_repo wt-staged-restored # staged key in a tracked file, disk restored to HEAD content
printf 'aws_access_key_id = "%s"\n' "$(fake_key)" >>"$TMP/wt-staged-restored/README"
stage wt-staged-restored
printf 'hello\n' >"$TMP/wt-staged-restored/README"
scan wt-staged-restored
[ "$status" -ne 0 ] || fail "a staged key with the disk restored to HEAD is not reported: $output"

new_repo wt-staged-rm # staged key, file removed from disk only
printf 'aws_access_key_id = "%s"\n' "$(fake_key)" >"$TMP/wt-staged-rm/settings.ini"
stage wt-staged-rm
rm "$TMP/wt-staged-rm/settings.ini"
scan wt-staged-rm
[ "$status" -ne 0 ] || fail "a staged key with the file removed from disk is not reported: $output"

new_repo wt-git-rm # a staged deletion leaves nothing to scan
git -C "$TMP/wt-git-rm" rm -q README
scan wt-git-rm
[ "$status" -eq 0 ] || fail "a staged deletion: expected exit 0, got $status: $output"

mkdir -p "$TMP/wt-unborn" # no commit yet
git -C "$TMP/wt-unborn" init -q
printf 'aws_access_key_id = "%s"\n' "$(fake_key)" >"$TMP/wt-unborn/settings.ini"
stage wt-unborn
scan wt-unborn secrets-scan-worktree
[ "$status" -ne 0 ] || fail "a staged key before the first commit is not reported: $output"

new_repo wt-dash # a name that looks like an option
printf 'aws_access_key_id = "%s"\n' "$(fake_key)" >"$TMP/wt-dash/-settings.ini"
scan wt-dash
[ "$status" -ne 0 ] || fail "a key in a file named -settings.ini is not reported: $output"

# Fail closed: when git cannot list the changes the scan must not claim there is nothing to scan.
new_repo wt-git-broken
printf 'garbage' >"$TMP/wt-git-broken/.git/index"
scan wt-git-broken secrets-scan-worktree
[ "$status" -ne 0 ] || fail "a failing git command passes the scan: $output"
! grep -qF 'nothing uncommitted' <<<"$output" || fail "a failing git command reports nothing to scan: $output"

new_repo wt-space
mkdir -p "$TMP/wt-space/my dir"
printf 'aws_access_key_id = "%s"\n' "$(fake_key)" >"$TMP/wt-space/my dir/new settings.ini"
scan wt-space
[ "$status" -ne 0 ] || fail "a key in a file whose path has spaces is not reported: $output"
grep -qF 'my dir/new settings.ini' <<<"$output" || fail "spaces: the finding does not name the repo-relative path: $output"

# A deleted tracked file is not a file to scan (and must not break the copy).
new_repo wt-deleted
rm "$TMP/wt-deleted/README"
scan wt-deleted
[ "$status" -eq 0 ] || fail "a deleted tracked file: expected exit 0, got $status: $output"

# Ignored files are not part of the work to commit.
new_repo wt-ignored
printf 'ignored.ini\n' >"$TMP/wt-ignored/.gitignore"
commit wt-ignored 'ignore a file'
printf 'aws_access_key_id = "%s"\n' "$(fake_key)" >"$TMP/wt-ignored/ignored.ini"
scan wt-ignored
[ "$status" -eq 0 ] || fail "a git-ignored file is scanned: $output"

# An inline `gitleaks:allow` comment must not hide an uncommitted finding either.
new_repo wt-inline-allow
printf 'aws_access_key_id = "%s" # gitleaks:allow\n' "$(fake_key)" >"$TMP/wt-inline-allow/settings.ini"
scan wt-inline-allow
[ "$status" -ne 0 ] || fail "a gitleaks:allow comment hides an untracked key: $output"

# An untracked .gitleaksignore is refused like a tracked one.
new_repo wt-ignorefile
printf 'deadbeef:settings.ini:generic-api-key:1\n' >"$TMP/wt-ignorefile/.gitleaksignore"
scan wt-ignorefile
[ "$status" -ne 0 ] || fail 'an untracked .gitleaksignore is accepted'

# Path allowlists of the repository's .gitleaks.toml (path AND rule AND value) still match in dir mode, because the
# scan root keeps repo-relative paths. The fake is the one the real allowlist names; the same value at another path,
# and another value at the allowlisted path, must still be reported.
ALLOW_PATH='frontend/src/views/__tests__/AuthPages.http.spec.ts'
fake_password_line() { printf 'const password = "%s"\n' "$1"; }
new_repo wt-allow
cp "$HERE/../../.gitleaks.toml" "$TMP/wt-allow/.gitleaks.toml"
commit wt-allow 'add gitleaks config'
mkdir -p "$TMP/wt-allow/$(dirname "$ALLOW_PATH")"
fake_password_line 'uniq-secret-pw-91' >"$TMP/wt-allow/$ALLOW_PATH"
scan wt-allow
[ "$status" -eq 0 ] || fail "the allowlisted fake at its allowlisted path is reported: $output"

new_repo wt-allow-staged
cp "$HERE/../../.gitleaks.toml" "$TMP/wt-allow-staged/.gitleaks.toml"
commit wt-allow-staged 'add gitleaks config'
mkdir -p "$TMP/wt-allow-staged/$(dirname "$ALLOW_PATH")"
fake_password_line 'uniq-secret-pw-91' >"$TMP/wt-allow-staged/$ALLOW_PATH"
stage wt-allow-staged
scan wt-allow-staged
[ "$status" -eq 0 ] || fail "the allowlisted fake, staged, at its allowlisted path is reported: $output"

new_repo wt-allow-path
cp "$HERE/../../.gitleaks.toml" "$TMP/wt-allow-path/.gitleaks.toml"
commit wt-allow-path 'add gitleaks config'
fake_password_line 'uniq-secret-pw-91' >"$TMP/wt-allow-path/other.spec.ts"
scan wt-allow-path
[ "$status" -ne 0 ] || fail "control: the allowlisted value at another path is not reported: $output"

new_repo wt-allow-value
cp "$HERE/../../.gitleaks.toml" "$TMP/wt-allow-value/.gitleaks.toml"
commit wt-allow-value 'add gitleaks config'
mkdir -p "$TMP/wt-allow-value/$(dirname "$ALLOW_PATH")"
fake_password_line 'xq7Kp2Zr9Tm4Wv8Bj3Lc' >"$TMP/wt-allow-value/$ALLOW_PATH"
scan wt-allow-value
[ "$status" -ne 0 ] || fail "control: another value at the allowlisted path is not reported: $output"

# Both roots scanned: a finding on disk must survive a clean index scan (exit status of the first container is kept).
new_repo wt-both
printf 'aws_access_key_id = "%s"\n' "$(fake_key)" >"$TMP/wt-both/settings.ini"
printf 'clean\n' >"$TMP/wt-both/staged.txt"
git -C "$TMP/wt-both" add staged.txt
scan wt-both secrets-scan-worktree
[ "$status" -ne 0 ] || fail "an untracked key plus a clean staged file passes: $output"
grep -qF 'scanning 1 index file' <<<"$output" || fail "both roots: the index root was not scanned: $output"

# The worktree target works on its own too.
scan wt-untracked secrets-scan-worktree
[ "$status" -ne 0 ] || fail "secrets-scan-worktree alone misses an untracked key: $output"
! grep -qF "$key" <<<"$output" || fail 'secrets-scan-worktree alone: the key is printed in clear'

if [ "$failures" -ne 0 ]; then
  printf '%s check(s) failed\n' "$failures"
  exit 1
fi
echo 'secrets-scan: all checks passed'
