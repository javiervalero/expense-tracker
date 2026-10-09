#!/usr/bin/env bash
# Tests for scripts/check-bundle-secrets.sh. Run: bash scripts/tests/check-bundle-secrets.test.sh
# The value comes from the environment (BUNDLE_SECRET_VALUE), never from argv.
# Exit codes asserted: 0 = value absent, 1 = value found in a file, 2 = error (bad usage, short value, unreadable file).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$HERE/../check-bundle-secrets.sh"
WORK="$(mktemp -d)"
trap 'chmod -R u+rwx "$WORK" 2>/dev/null || true; rm -rf "$WORK"' EXIT

SECRET='local-origin-secret'

failures=0
status=0
output=''

# run_guard <value> <args...>: the value is passed in the environment, as the Makefile does.
run_guard() {
  local value="$1"
  shift
  status=0
  output="$(BUNDLE_SECRET_VALUE="$value" "$GUARD" "$@" 2>&1)" || status=$?
}

expect() { # <expected status> <label>
  if [ "$status" -ne "$1" ]; then
    failures=$((failures + 1))
    printf 'FAIL %s: expected exit %s, got %s\n%s\n' "$2" "$1" "$status" "$output"
  fi
}

expect_output_lacks() { # <text> <label>
  if grep -qF -- "$1" <<<"$output"; then
    failures=$((failures + 1))
    printf 'FAIL %s: output contains the text: %s\n' "$2" "$output"
  fi
}

fresh_dist() {
  rm -rf "$WORK/dist"
  mkdir -p "$WORK/dist/assets"
  printf 'console.log("hello")\n' >"$WORK/dist/assets/app.js"
  printf '<html></html>\n' >"$WORK/dist/index.html"
}

fresh_dist
run_guard "$SECRET" "$WORK/dist"
expect 0 'a clean bundle'

fresh_dist
printf 'const k="%s";\n' "$SECRET" >"$WORK/dist/assets/app.js"
run_guard "$SECRET" "$WORK/dist"
expect 1 'the value inside a script'
grep -qF 'app.js' <<<"$output" || { failures=$((failures + 1)); echo "FAIL output does not name app.js: $output"; }
expect_output_lacks "$SECRET" 'the output must not print the value'

fresh_dist
printf '{"sourcesContent":["%s"]}\n' "$SECRET" >"$WORK/dist/assets/app.js.map"
run_guard "$SECRET" "$WORK/dist"
expect 1 'the value inside a source map'

fresh_dist
printf 'a.b*c(d)xyz\n' >"$WORK/dist/assets/app.js"
run_guard 'a.b*c(d)xyz' "$WORK/dist"
expect 1 'a value with regex characters is matched literally'
run_guard 'aXb*c(d)xyz' "$WORK/dist"
expect 0 'regex characters are not interpreted as a pattern'

# Normalisation of what `grep ... .env | cut` returns.
fresh_dist
printf 'const k="%s";\n' "$SECRET" >"$WORK/dist/assets/app.js"
run_guard "\"$SECRET\"" "$WORK/dist"
expect 1 'a double-quoted value is unquoted before searching'
run_guard "'$SECRET'" "$WORK/dist"
expect 1 'a single-quoted value is unquoted before searching'
run_guard "$SECRET"$'\r' "$WORK/dist"
expect 1 'a value with a CRLF line ending is stripped of the CR before searching'
run_guard "\"$SECRET\""$'\r' "$WORK/dist"
expect 1 'a quoted value with a CRLF line ending'

# Encoded leaks: base64 in all three alignments, base64url, hex.
for prefix in '' 'x' 'xy'; do
  fresh_dist
  printf '%s%s' "$prefix" "$SECRET" | base64 -w0 >"$WORK/dist/assets/app.js"
  run_guard "$SECRET" "$WORK/dist"
  expect 1 "the value base64-encoded at alignment ${#prefix}"
done

fresh_dist
printf 'before-%s-after' "$SECRET" | base64 -w0 | tr '+/' '-_' | tr -d '=' >"$WORK/dist/assets/app.js"
run_guard "$SECRET" "$WORK/dist"
expect 1 'the value base64url-encoded'

fresh_dist
printf 'k="%s"' "$(printf '%s' "$SECRET" | od -An -tx1 | tr -d ' \n')" >"$WORK/dist/assets/app.js"
run_guard "$SECRET" "$WORK/dist"
expect 1 'the value hex-encoded (lower case)'
printf 'k="%s"' "$(printf '%s' "$SECRET" | od -An -tx1 | tr -d ' \n' | tr 'a-f' 'A-F')" >"$WORK/dist/assets/app.js"
run_guard "$SECRET" "$WORK/dist"
expect 1 'the value hex-encoded (upper case)'

fresh_dist
printf 'dGhpcyBpcyB1bnJlbGF0ZWQ= 6162636465666768\n' >"$WORK/dist/assets/app.js"
run_guard "$SECRET" "$WORK/dist"
expect 0 'unrelated base64 and hex text'

# A file that cannot be read must not look like "absent".
if [ "$(id -u)" -ne 0 ]; then
  fresh_dist
  printf 'nothing here\n' >"$WORK/dist/assets/locked.js"
  chmod 000 "$WORK/dist/assets/locked.js"
  run_guard "$SECRET" "$WORK/dist"
  expect 2 'an unreadable file is an error, not a pass'
  chmod 644 "$WORK/dist/assets/locked.js"
else
  echo "SKIP unreadable-file test: running as root, permissions do not apply"
fi

# Bad usage.
fresh_dist
run_guard '' "$WORK/dist"
expect 2 'an empty value'
run_guard '   ' "$WORK/dist"
expect 2 'a blank value'
run_guard '""' "$WORK/dist"
expect 2 'a value that is only quotes'
run_guard '1234567' "$WORK/dist"
expect 2 'a value shorter than 8 characters (7)'
run_guard '12345678' "$WORK/dist"
expect 0 'a value of exactly 8 characters is accepted'
run_guard "$SECRET" "$WORK/dist" "$SECRET"
expect 2 'the value given as a second argument (argv is not allowed)'

status=0
output="$(env -u BUNDLE_SECRET_VALUE "$GUARD" "$WORK/dist" 2>&1)" || status=$?
expect 2 'the variable not set at all'

run_guard "$SECRET" "$WORK/missing"
expect 2 'a missing directory'

mkdir -p "$WORK/empty"
run_guard "$SECRET" "$WORK/empty"
expect 2 'an empty directory (nothing was built)'

run_guard "$SECRET"
expect 2 'no directory argument'

if [ "$failures" -ne 0 ]; then
  echo "check-bundle-secrets.test.sh: $failures failure(s)"
  exit 1
fi
echo "check-bundle-secrets.test.sh: all checks passed"
