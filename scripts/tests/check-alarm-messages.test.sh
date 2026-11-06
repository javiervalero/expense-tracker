#!/usr/bin/env bash
# Tests for scripts/check-alarm-messages.sh. Run: bash scripts/tests/check-alarm-messages.test.sh
# Exit codes asserted: 0 = every alarm message is still in the PHP code, 1 = one is gone, 2 = bad usage or nothing to check.
# Fixtures are created at run time in one fresh directory under build/ (git-ignored), removed on exit. The removal is
# guarded: only a non-empty path inside build/ whose name is alarm-messages-test.* is ever deleted.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
GUARD="$HERE/../check-alarm-messages.sh"

mkdir -p "$REPO/build"
TMP="$(mktemp -d "$REPO/build/alarm-messages-test.XXXXXX")"

cleanup() {
  [ -n "${TMP:-}" ] || return 0
  local resolved build_root
  resolved="$(realpath -m -- "$TMP")"
  build_root="$(realpath -m -- "$REPO/build")"
  case "$resolved" in
    "$build_root"/alarm-messages-test.*) [ "$(dirname "$resolved")" = "$build_root" ] && rm -rf -- "$resolved" ;;
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

new_dir() { # <name>: prints a fresh empty directory
  mkdir -p "$TMP/$1"
  printf '%s' "$TMP/$1"
}

module_with() { # <dir> <message>...: a module whose guarded list holds the messages
  local dir="$1"
  shift
  {
    printf 'locals {\n  # alarm-messages:begin\n  messages = {\n'
    local i=0 message
    for message in "$@"; do
      i=$((i + 1))
      printf '    m%d = { message = "%s", metric = "M%d" }\n' "$i" "$message" "$i"
    done
    printf '  }\n  # alarm-messages:end\n}\n'
  } >"$dir/main.tf"
}

# The real tree: every message in the real module is still in backend/src.
run_guard
expect_status 'real tree' 0
grep -qF 'receipt cleanup failed' <<<"$output" || fail "real tree: the output does not list the messages it checked: $output"

# Synthetic: present, in a single-quoted PHP string.
mod="$(new_dir synth-mod)"
src="$(new_dir synth-src)"
module_with "$mod" 'receipt cleanup failed' 'CorruptItem'
printf "<?php\n\$logger->error('receipt cleanup failed', []);\nconst X = 'CorruptItem';\n" >"$src/a.php"
run_guard "$mod" "$src"
expect_status 'synthetic present' 0

# A message that is gone is named, and the ones that remain are not.
mod="$(new_dir gone-mod)"
src="$(new_dir gone-src)"
module_with "$mod" 'receipt cleanup failed' 'client address unavailable'
printf "<?php\n\$logger->error('receipt cleanup failed', []);\n" >"$src/a.php"
run_guard "$mod" "$src"
expect_status 'one gone' 1
grep -qF 'client address unavailable' <<<"$output" || fail "one gone: the output does not name the missing message: $output"
grep -F 'missing' <<<"$output" | grep -qF 'receipt cleanup failed' && fail "one gone: the output blames a message that is present: $output"

# A literal that only survives in a comment does not count: the message was renamed in the code.
mod="$(new_dir comment-mod)"
src="$(new_dir comment-src)"
module_with "$mod" 'receipt cleanup failed'
printf "<?php\n/**\n * Logs \`receipt cleanup failed\`.\n */\n// receipt cleanup failed\n\$logger->error('cleanup failed', []);\n" >"$src/a.php"
run_guard "$mod" "$src"
expect_status 'comment only' 1

# A message that appears only in a non-PHP file does not count.
mod="$(new_dir nonphp-mod)"
src="$(new_dir nonphp-src)"
module_with "$mod" 'receipt cleanup failed'
printf 'receipt cleanup failed\n' >"$src/notes.md"
run_guard "$mod" "$src"
expect_status 'non-PHP file only' 1

# The real code with one message renamed in a COPY of backend/src: the guard must fail and name it.
copy="$(new_dir real-copy)"
cp -R "$REPO/backend/src" "$copy/src"
cleaner="$copy/src/Receipts/ReceiptObjectCleaner.php"
cp -p "$cleaner" "$TMP/ReceiptObjectCleaner.original"
content="$(<"$cleaner")"
printf '%s\n' "${content//\'receipt cleanup failed\'/\'receipt clean-up failed\'}" >"$cleaner"
grep -qF "'receipt clean-up failed'" "$cleaner" || fail 'fixture: the rename did not happen in the copy'
run_guard "$REPO/infra/terraform/modules/observability" "$copy/src"
expect_status 'real code with a renamed message' 1
grep -qF 'receipt cleanup failed' <<<"$output" || fail "renamed: the output does not name the message: $output"

# The same copy with the real name restored passes again (the failure above was the rename, nothing else).
cp -p "$TMP/ReceiptObjectCleaner.original" "$cleaner"
run_guard "$REPO/infra/terraform/modules/observability" "$copy/src"
expect_status 'copy restored' 0

# Nothing to check is an error, not a pass: no markers, markers around nothing, an empty message.
mod="$(new_dir nomark-mod)"
src="$(new_dir nomark-src)"
printf '<?php\n' >"$src/a.php"
printf 'locals {\n  messages = { a = { message = "x y" } }\n}\n' >"$mod/main.tf"
run_guard "$mod" "$src"
expect_status 'no markers' 2
mod="$(new_dir empty-mod)"
printf 'locals {\n  # alarm-messages:begin\n  # alarm-messages:end\n}\n' >"$mod/main.tf"
run_guard "$mod" "$src"
expect_status 'markers around nothing' 2
mod="$(new_dir blank-mod)"
module_with "$mod" ''
run_guard "$mod" "$src"
expect_status 'empty message' 2
mod="$(new_dir notf-mod)"
run_guard "$mod" "$src"
expect_status 'module without .tf files' 2

# Bad usage.
run_guard "$TMP/does-not-exist" "$src"
expect_status 'missing module directory' 2
run_guard "$mod" "$TMP/does-not-exist"
expect_status 'missing source directory' 2
run_guard one two three
expect_status 'too many arguments' 2

if [ "$failures" -ne 0 ]; then
  printf '%d check(s) failed\n' "$failures"
  exit 1
fi
printf 'check-alarm-messages tests: ok\n'
