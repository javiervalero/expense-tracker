#!/usr/bin/env bash
# Tests for scripts/smoke.sh (the curl smoke test). Run: bash scripts/tests/smoke.test.sh
# Nothing here touches the network or Docker: a stub `curl`, `docker`, `make` and `jq` (scripts/tests/fixtures/smoke/) are
# placed first on PATH. They replay canned answers (routes.txt) and log their argv (FAKE_ARGV_LOG: what `ps` would show)
# and the request as a server would see it (FAKE_WIRE_LOG: with the secrets, so the markers provably flow).
# Covered: usage and exit 2; xtrace forced off; the happy path (headers, flags, output shape, numbering); a stubbed 500 in
# the middle (exit 1, the step is named, the cleanup DELETEs still ran); exit 3 when cleanup fails; no secret in the
# output or in the argv log (and the check can fail: negative controls); hostile upload and view URLs and an unknown
# form field are refused before any POST; register 429; the pinned jq image rules.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SMOKE="${SMOKE_UNDER_TEST:-$HERE/../smoke.sh}"
FIX="$HERE/fixtures/smoke"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

STATE="$WORK/state"
ARGV_LOG="$WORK/argv.log"
WIRE_LOG="$WORK/wire.log"
OUT_FILE="$WORK/output.txt"
PINNED="registry.invalid/jq:1.8.2@sha256:0000000000000000000000000000000000000000000000000000000000000000"
BASE='http://localhost:5173'
LOGIN_PASSWORD='PASSWORDMARKER_Qx7-91zK'
# Every secret value the stubs can produce. Each is at least 10 characters, so a hit cannot be an accident.
MARKERS=(COOKIEVALUEMARKER_0123456789 POLICYMARKER_0123456789ab== SIGNATUREMARKER_0123456789 VIEWURLMARKER_0123456789 VIEWSIGMARKER_0123456789 BODYMARKER_0123456789)

failures=0
status=0
output=''
SHELL_FLAGS=''

fail() {
  failures=$((failures + 1))
  printf 'FAIL %s\n' "$1"
}

run_smoke() { # [VAR=value ...] -- smoke.sh arguments...   (sets status and output; stdout and stderr are merged)
  local envs=()
  while [ "$1" != -- ]; do
    envs+=("$1")
    shift
  done
  shift
  rm -rf "$STATE"
  mkdir -p "$STATE"
  : >"$ARGV_LOG"
  : >"$WIRE_LOG"
  status=0
  # shellcheck disable=SC2086
  output="$(env -u SMOKE_EMAIL -u SMOKE_PASSWORD -u SMOKE_JQ -u SHELLOPTS -u BASH_XTRACEFD \
    PATH="$FIX:$PATH" FAKE_DIR="$STATE" FAKE_ARGV_LOG="$ARGV_LOG" FAKE_WIRE_LOG="$WIRE_LOG" \
    FAKE_MAKE_JQ_IMAGE="$PINNED" JQ_IMAGE="$PINNED" "${envs[@]}" bash $SHELL_FLAGS "$SMOKE" "$@" 2>&1)" || status=$?
  printf '%s\n' "$output" >"$OUT_FILE"
}

expect_status() { # <expected> <label>
  [ "$status" -eq "$1" ] || fail "$2: expected exit $1, got $status: $output"
}

expect_in_output() { # <text> <label>
  grep -qF -- "$1" <<<"$output" || fail "$2: output does not contain '$1': $output"
}

expect_only_check_lines() { # <label>: every line of the output is an ok / not ok line
  local bad
  bad="$(grep -vE '^(ok|not ok) [0-9]+ - [^ ]' <<<"$output" || true)"
  [ -z "$bad" ] || fail "$1: output has lines that are not check lines: $bad"
}

contains() { # <text> <file>: true when the file holds the text
  grep -qF -- "$1" "$2"
}

assert_no_secret() { # <label> [extra secret ...]: none of the secrets is in the output or in the argv log
  local label=$1 secret
  shift
  for secret in "${MARKERS[@]}" "$@"; do
    [ "${#secret}" -ge 10 ] || { fail "$label: the secret '$secret' is too short to be a meaningful marker"; continue; }
    if contains "$secret" "$OUT_FILE"; then fail "$label: the output contains a secret ($secret)"; fi
    if contains "$secret" "$ARGV_LOG"; then fail "$label: the argv log contains a secret ($secret)"; fi
  done
}

assert_no_post() { # <label>: nothing was uploaded and no hostile host was contacted
  if grep -q '^FORM ' "$WIRE_LOG"; then fail "$1: a form was posted"; fi
  if grep -q 'evil' "$WIRE_LOG" "$ARGV_LOG"; then fail "$1: the hostile host was contacted"; fi
  if contains 'evil' "$OUT_FILE"; then fail "$1: the hostile value was echoed"; fi
}

line_of() { # <regex> <file> -> number of the last matching line (0 when none)
  grep -nE -- "$1" "$2" | tail -n 1 | cut -d: -f1 || true
}

# ---- usage and exit 2: nothing is called ---------------------------------------------------------------------------
expect_refused() { # <label> <expected text> args...
  local label=$1 text=$2
  shift 2
  run_smoke -- "$@"
  expect_status 2 "$label"
  expect_in_output "$text" "$label"
  [ ! -s "$ARGV_LOG" ] || fail "$label: curl or docker was called: $(<"$ARGV_LOG")"
}

expect_refused 'no argument' 'usage:'
expect_refused 'only an option' 'usage:' --cross-user
expect_refused 'unknown option' 'usage:' --bogus "$BASE"
expect_refused 'two urls' 'usage:' "$BASE" "$BASE"
expect_refused 'plain http on a real host' 'usage:' http://example.com
expect_refused 'http on a real host with a port' 'usage:' http://example.com:5173
expect_refused 'https with a path' 'usage:' https://example.com/api
expect_refused 'https with a query' 'usage:' 'https://example.com?x=1'
expect_refused 'https with a fragment' 'usage:' 'https://example.com#top'
expect_refused 'https with userinfo' 'usage:' 'https://user:pw@example.com'
expect_refused 'localhost with a trailing slash' 'usage:' 'http://localhost:5173/'
expect_refused 'localhost with userinfo' 'usage:' 'http://user@localhost:5173'
expect_refused '127.0.0.1' 'use localhost' http://127.0.0.1:5173
expect_refused 'javascript scheme' 'usage:' 'javascript:alert(1)'
expect_refused 'ftp scheme' 'usage:' ftp://localhost
expect_refused 'empty string' 'usage:' ''

# ---- login-mode input rules (exit 2, nothing called) ---------------------------------------------------------------
run_smoke SMOKE_EMAIL=someone@example.com -- "$BASE"
expect_status 2 'SMOKE_EMAIL without SMOKE_PASSWORD'
run_smoke SMOKE_PASSWORD=some-password-1234 -- "$BASE"
expect_status 2 'SMOKE_PASSWORD without SMOKE_EMAIL'
run_smoke SMOKE_EMAIL=someone@example.com 'SMOKE_PASSWORD=has"quote-1234567' -- "$BASE"
expect_status 2 'a password with a double quote is refused (it would need JSON escaping)'
[ ! -s "$ARGV_LOG" ] || fail 'login-mode input rules: something was called'

# ---- the pinned jq image -------------------------------------------------------------------------------------------
run_smoke JQ_IMAGE=registry.invalid/jq:latest -- "$BASE"
expect_status 2 'an unpinned JQ_IMAGE'
expect_in_output 'not pinned' 'an unpinned JQ_IMAGE'
run_smoke JQ_IMAGE= -- "$BASE"
expect_status 0 'JQ_IMAGE unset: asked from make print-JQ_IMAGE'
contains 'make -s --no-print-directory' "$ARGV_LOG" || fail 'JQ_IMAGE unset: make print-JQ_IMAGE was not asked'
run_smoke JQ_IMAGE= FAKE_MAKE_JQ_IMAGE=registry.invalid/jq:latest -- "$BASE"
expect_status 2 'an unpinned image from make print-JQ_IMAGE'
run_smoke JQ_IMAGE=registry.invalid/jq:latest SMOKE_JQ=jq -- "$BASE"
expect_status 0 'SMOKE_JQ names a local jq: the image is not needed'
if grep -q 'registry.invalid' "$ARGV_LOG"; then fail 'SMOKE_JQ: a container was used'; fi

# ---- the happy path ------------------------------------------------------------------------------------------------
run_smoke -- "$BASE"
expect_status 0 'happy path'
expect_only_check_lines 'happy path'
[ "$(grep -c '^ok ' <<<"$output")" -eq 11 ] || fail "happy path: expected 11 ok lines: $output"
if grep -q '^not ok' <<<"$output"; then fail "happy path: a check failed: $output"; fi
numbers="$(sed -E 's/^ok ([0-9]+) - .*/\1/' "$OUT_FILE" | tr '\n' ' ')"
[ "$numbers" = '1 2 3 4 5 6 7 8 9 10 11 ' ] || fail "happy path: check numbers are not 1..11 in order: $numbers"
if contains 'X-Origin-Verify' "$ARGV_LOG" || contains 'X-Origin-Verify' "$WIRE_LOG"; then fail 'the origin secret header was sent'; fi
api_calls="$(grep -c 'api/v1/' "$ARGV_LOG" || true)"
[ "$api_calls" -gt 30 ] || fail "happy path: only $api_calls API calls were made"
without_origin="$(grep 'api/v1/' "$ARGV_LOG" | grep -vc -F -- "Origin: $BASE" || true)"
[ "$without_origin" -eq 0 ] || fail "happy path: $without_origin API calls had no Origin header"
without_xrw="$(grep 'api/v1/' "$ARGV_LOG" | grep -vc -F -- 'X-Requested-With: XMLHttpRequest' || true)"
[ "$without_xrw" -eq 1 ] || fail "happy path: exactly one call (the CSRF check) may lack X-Requested-With, got $without_xrw"
for flag in '-sS' '--max-time 15' '--max-redirs 0' '--proto =https,http'; do
  missing="$(grep -v -F -- '--version' "$ARGV_LOG" | grep -v '^run ' | grep -v '^make ' | grep -vc -F -- "$flag" || true)"
  [ "$missing" -eq 0 ] || fail "happy path: $missing curl calls lack '$flag'"
done
if grep -v '^run ' "$ARGV_LOG" | grep -E -- '(^| )(-v|-i|-D|--verbose|--include|--dump-header|--trace[a-z-]*)( |$)' >/dev/null; then fail 'happy path: a verbose or header-dumping curl flag was used'; fi
curl_lines_start_with_q() { # <file>: true when the file has curl calls and every one begins with -q (S1)
  local total bad
  total="$(grep -v -e '^run ' -e '^make ' "$1" | grep -c . || true)"
  bad="$(grep -v -e '^run ' -e '^make ' "$1" | grep -v '^-q ' | grep -c . || true)"
  [ "$total" -gt 0 ] && [ "$bad" -eq 0 ]
}
curl_lines_start_with_q "$ARGV_LOG" || fail 'happy path: -q is not the first argument of every curl call'
printf -- '-q -sS ok\n-sS missing-q\n' >"$WORK/planted-argv.log"
if curl_lines_start_with_q "$WORK/planted-argv.log"; then fail 'negative control: the -q check cannot fail'; fi
printf 'run --rm -i x\n' >"$WORK/no-curl-argv.log"
if curl_lines_start_with_q "$WORK/no-curl-argv.log"; then fail 'negative control: the -q check passes with no curl call at all'; fi
without_noproxy="$(grep -v -e '^run ' -e '^make ' -e '--version' "$ARGV_LOG" | grep -vc -F -- '--noproxy localhost' || true)"
[ "$without_noproxy" -eq 0 ] || fail "happy path: $without_noproxy curl calls lack --noproxy localhost in localhost mode"
docker_calls="$(grep -c '^run ' "$ARGV_LOG" || true)"
[ "$docker_calls" -gt 20 ] || fail "happy path: only $docker_calls jq calls"
bad_docker="$(grep '^run ' "$ARGV_LOG" | grep -vc -F -- "run --rm -i --log-driver none --network none $PINNED " || true)"
[ "$bad_docker" -eq 0 ] || fail "happy path: $bad_docker docker calls are not 'run --rm -i --network none <pinned image>'"
[ "$(grep -c '^POST http://localhost:9000/receipts$' "$WIRE_LOG")" -eq 3 ] || fail 'happy path: expected 3 uploads (valid, text, oversize)'
[ "$(grep -c '^FORM FILE:file=@' "$WIRE_LOG")" -eq 3 ] || fail 'happy path: every form must carry the file part'
# the file is the LAST part of each form
last_parts="$(awk '/^FORM /{last=$0} /^  -> /{ if (last != "") print last; last="" }' "$WIRE_LOG" | grep -vc '^FORM FILE:file=@' || true)"
[ "$last_parts" -eq 0 ] || fail 'happy path: a form has a field after the file part'
contains 'GET http://localhost:9000/receipts/VIEWURLMARKER_0123456789?X-Amz-Signature=VIEWSIGMARKER_0123456789' "$WIRE_LOG" || fail 'happy path: the view URL was not fetched'
if ! grep -q '^POST http://localhost:5173/api/v1/auth/register$' "$WIRE_LOG"; then fail 'happy path: a fresh user was not registered'; fi
if grep -q 'auth/login' "$WIRE_LOG"; then fail 'happy path: it logged in instead of registering'; fi
fresh_email="$(grep -m1 -o '"email":"[^"]*"' "$WIRE_LOG" | cut -d'"' -f4)"
[[ "$fresh_email" =~ ^smoke-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}@example\.com$ ]] || fail "happy path: odd fresh email: $fresh_email"
fresh_password="$(grep -m1 -o '"password":"[^"]*"' "$WIRE_LOG" | cut -d'"' -f4)"
[ "${#fresh_password}" -ge 20 ] || fail 'happy path: the random password is missing or short, so the leak check below would be vacuous'
assert_no_secret 'happy path' "$fresh_password" "$fresh_email"
# negative controls: the same check really fails when a secret is there
cp "$OUT_FILE" "$WORK/planted.txt"
printf 'oops %s\n' "$fresh_password" >>"$WORK/planted.txt"
contains "$fresh_password" "$WORK/planted.txt" || fail 'negative control: the leak check cannot see a planted password'
for secret in "${MARKERS[@]}" "$fresh_password"; do
  contains "$secret" "$WIRE_LOG" || fail "negative control: '$secret' never reached the wire, so the check proves nothing"
done

run_smoke -- --cross-user "$BASE"
expect_status 0 'cross-user happy path'
expect_only_check_lines 'cross-user'
[ "$(grep -c '^ok ' <<<"$output")" -eq 12 ] || fail "cross-user: expected 12 ok lines: $output"
[ "$(grep -c '^POST http://localhost:5173/api/v1/auth/register$' "$WIRE_LOG")" -eq 2 ] || fail 'cross-user: two users must register'
assert_no_secret 'cross-user'

run_smoke FAKE_UPLOAD_URL=https://s3.example.com/receipts \
  'FAKE_VIEW_URL=https://s3.example.com/receipts/VIEWURLMARKER_0123456789?X-Amz-Signature=VIEWSIGMARKER_0123456789' -- https://app.example.com:8443
expect_status 0 'https base url'contains 'Origin: https://app.example.com:8443' "$ARGV_LOG" || fail 'https base url: Origin is not the base URL'
contains 'POST https://app.example.com:8443/api/v1/auth/register' "$WIRE_LOG" || fail 'https base url: wrong register URL'

# ---- login mode: no registration, the password is never shown ------------------------------------------------------
run_smoke SMOKE_EMAIL=Login.User@example.com "SMOKE_PASSWORD=$LOGIN_PASSWORD" -- "$BASE"
expect_status 0 'login mode'
grep -q '^POST http://localhost:5173/api/v1/auth/login$' "$WIRE_LOG" || fail 'login mode: no login'
if grep -q 'auth/register' "$WIRE_LOG"; then fail 'login mode: it registered'; fi
contains "$LOGIN_PASSWORD" "$WIRE_LOG" || fail 'negative control: the login password never reached the wire'
assert_no_secret 'login mode' "$LOGIN_PASSWORD"

# ---- xtrace is forced off ------------------------------------------------------------------------------------------
export PS4='+TRACE '
for variant in 'SHELLOPTS=xtrace' 'BASH_XTRACEFD=2'; do
  run_smoke SMOKE_EMAIL=someone@example.com "SMOKE_PASSWORD=$LOGIN_PASSWORD" SHELLOPTS=xtrace "$variant" -- "$BASE"
  expect_status 0 "xtrace via $variant"
  traced="$(grep -c '^+TRACE ' <<<"$output" || true)"
  [ "$traced" -le 1 ] || fail "xtrace via $variant: $traced trace lines reached the output"
  assert_no_secret "xtrace via $variant" "$LOGIN_PASSWORD"
done
SHELL_FLAGS='-x'
run_smoke SMOKE_EMAIL=someone@example.com "SMOKE_PASSWORD=$LOGIN_PASSWORD" -- "$BASE"
SHELL_FLAGS=''
expect_status 0 'bash -x'
traced="$(grep -c '^+TRACE ' <<<"$output" || true)"
[ "$traced" -le 1 ] || fail "bash -x: $traced trace lines reached the output"
assert_no_secret 'bash -x' "$LOGIN_PASSWORD"
unset PS4

# ---- a stubbed 500 in the middle: exit 1, the step is named, the cleanup ran ---------------------------------------
run_smoke FAKE_FORCE='GET /reports/monthly=500' -- --cross-user "$BASE"
expect_status 1 'a 500 at the summary step'
grep -qE '^not ok 8 - .*[Ss]ummary.* \(HTTP 500: Stubbed problem title\)$' <<<"$output" || fail "500: the not ok line does not name the step: $output"
[ "$(grep -c '^not ok' <<<"$output")" -eq 1 ] || fail '500: expected exactly one not ok line'
[ "$(tail -n 1 <<<"$output" | cut -c1-6)" = 'not ok' ] || fail '500: nothing may follow the not ok line'
failed_at="$(line_of '^  -> 500$' "$WIRE_LOG")"
[ "$(grep -c '^DELETE http://localhost:5173/api/v1/expenses/aaaaaaaa-[0-9a-f-]*$' "$WIRE_LOG")" -eq 5 ] || fail '500: the 5 expenses were not deleted by the cleanup'
[ "$(grep -c '^DELETE http://localhost:5173/api/v1/categories/aaaaaaaa-[0-9a-f-]*$' "$WIRE_LOG")" -eq 2 ] || fail '500: the 2 categories were not deleted by the cleanup'
logout_at="$(line_of '^POST http://localhost:5173/api/v1/auth/logout$' "$WIRE_LOG")"
first_delete_after="$(awk -v failed="$failed_at" 'NR > failed && /^DELETE /{print NR; exit}' "$WIRE_LOG")"
[ -n "$first_delete_after" ] || fail '500: no DELETE came after the failing call'
[ "${logout_at:-0}" -gt "${failed_at:-0}" ] || fail '500: the cleanup did not sign out after the failure'
assert_no_secret '500 at a middle step'

# ---- the cleanup itself failing is exit 3 ---------------------------------------------------------------------------
run_smoke FAKE_FORCE='POST /auth/logout=500' -- "$BASE"
expect_status 3 'cleanup failure'
grep -qE '^not ok 11 - .*[Cc]leanup' <<<"$output" || fail "cleanup failure: no not ok line for the cleanup: $output"
[ "$(grep -c '^ok ' <<<"$output")" -eq 10 ] || fail 'cleanup failure: the ten checks before must have passed'

# ---- hostile upload URLs, an unknown field and a hostile view URL: refused before any POST -------------------------
expect_hostile() { # <label> <VAR=value>
  run_smoke "$2" -- "$BASE"
  expect_status 1 "$1"
  grep -qE '^not ok [0-9]+ - ' <<<"$output" || fail "$1: no not ok line: $output"
  assert_no_post "$1"
  assert_no_secret "$1"
}
expect_hostile 'upload to http://evil.example' 'FAKE_UPLOAD_URL=http://evil.example/receipts'
expect_hostile 'upload to https with userinfo' 'FAKE_UPLOAD_URL=https://user:pw@evil.example/receipts'
expect_hostile 'upload URL with a query' 'FAKE_UPLOAD_URL=http://localhost:9000/receipts?redirect=evil'
expect_hostile 'upload URL with a fragment' 'FAKE_UPLOAD_URL=http://localhost:9000/receipts#evil'
expect_hostile 'upload to another localhost port' 'FAKE_UPLOAD_URL=http://localhost:9001/receipts'
expect_hostile 'upload to 127.0.0.1' 'FAKE_UPLOAD_URL=http://127.0.0.1:9000/receipts'
expect_hostile 'upload with an unknown form field (acl)' 'FAKE_EXTRA_FIELD=acl'
expect_hostile 'upload with a field name that only looks like X-Amz' 'FAKE_EXTRA_FIELD=x-amz-evil'
run_smoke 'FAKE_VIEW_URL=http://evil.example/receipts/x?sig=VIEWSIGMARKER_9876543210' -- "$BASE"
expect_status 1 'hostile view URL'
grep -qE '^not ok [0-9]+ - ' <<<"$output" || fail 'hostile view URL: no not ok line'
if grep -q '^GET http://evil' "$WIRE_LOG"; then fail 'hostile view URL: it was fetched'; fi
if contains 'VIEWSIGMARKER_9876543210' "$OUT_FILE"; then fail 'hostile view URL: the signature was printed'; fi
# S2: the allow-list follows the mode. A localhost base accepts only http://localhost:9000; an https base only https.
HTTPS_VIEW='FAKE_VIEW_URL=https://s3.example.com/receipts/VIEWURLMARKER_0123456789?X-Amz-Signature=VIEWSIGMARKER_0123456789'
run_smoke 'FAKE_UPLOAD_URL=https://s3.eu-west-1.example.com/receipts' -- "$BASE"
expect_status 1 'localhost base refuses an https upload URL'
grep -qE '^not ok [0-9]+ - ' <<<"$output" || fail 'localhost base, https upload: no not ok line'
if grep -q '^FORM ' "$WIRE_LOG"; then fail 'localhost base, https upload: a form was posted'; fi
run_smoke "$HTTPS_VIEW" -- "$BASE"
expect_status 1 'localhost base refuses an https view URL'
if grep -q '^GET https://s3' "$WIRE_LOG"; then fail 'localhost base, https view: it was fetched'; fi
run_smoke 'FAKE_UPLOAD_URL=https://s3.eu-west-1.example.com/receipts' "$HTTPS_VIEW" -- https://app.example.com
expect_status 0 'https base accepts https upload and view URLs'
contains 'POST https://s3.eu-west-1.example.com/receipts' "$WIRE_LOG" || fail 'https base: the https upload URL was not posted to'
run_smoke "$HTTPS_VIEW" -- https://app.example.com
expect_status 1 'https base refuses http://localhost:9000 for the upload'
if grep -q '^FORM ' "$WIRE_LOG"; then fail 'https base, localhost upload: a form was posted'; fi
run_smoke 'FAKE_UPLOAD_URL=https://s3.eu-west-1.example.com/receipts' -- https://app.example.com
expect_status 1 'https base refuses http://localhost:9000 for the view'
if grep -q '^GET http://localhost:9000' "$WIRE_LOG"; then fail 'https base, localhost view: it was fetched'; fi

# C1: a failing yes/no jq test is a failed check, with the generic detail and nothing else
run_smoke FAKE_JQ_E_EXIT=1 -- "$BASE"
expect_status 1 'a jq assertion that fails'
grep -qE '^not ok 5 - .*\(HTTP 200: unexpected response content\)$' <<<"$output" || fail "jq assertion: wrong not ok line: $output"
assert_no_secret 'jq assertion that fails'

# C5: the page walk must end, and must not see the same expense twice
run_smoke FAKE_CURSOR_FOREVER=1 -- "$BASE"
expect_status 1 'a cursor that never ends'
grep -qE '^not ok 7 - ' <<<"$output" || fail "endless cursor: wrong not ok line: $output"
run_smoke FAKE_DUPLICATE_ITEM_IDS=1 -- "$BASE"
expect_status 1 'the same expense on two pages'
grep -qE '^not ok 7 - ' <<<"$output" || fail "duplicate ids: wrong not ok line: $output"

# C6: a logout that answers 401 at cleanup means the session was already closed
run_smoke FAKE_FORCE='POST /auth/logout=401' -- "$BASE"
expect_status 0 'cleanup treats a 401 logout as closed'

# C4: the receipt wait is bounded (SMOKE_MAX_WAIT, in seconds), and the run fails instead of hanging
run_smoke FAKE_RECEIPT_STATUSES=pending SMOKE_MAX_WAIT=1 -- "$BASE"
expect_status 1 'a receipt that stays pending'
grep -qE '^not ok 10 - .*still pending' <<<"$output" || fail "pending receipt: wrong not ok line: $output"
run_smoke SMOKE_MAX_WAIT=abc -- "$BASE"
expect_status 2 'SMOKE_MAX_WAIT must be digits'
run_smoke 'SMOKE_MAX_WAIT=1;2' -- "$BASE"
expect_status 2 'SMOKE_MAX_WAIT with a separator'

# ---- registration throttled ----------------------------------------------------------------------------------------
run_smoke FAKE_FORCE='POST /auth/register=429' -- "$BASE"
expect_status 1 'register 429'
expect_in_output 'registration is throttled (5 per hour per address)' 'register 429'
expect_in_output 'SMOKE_EMAIL/SMOKE_PASSWORD' 'register 429'
grep -qE '^not ok 3 - ' <<<"$output" || fail 'register 429: not check 3'
if grep -q 'categories' "$WIRE_LOG"; then fail 'register 429: it went on'; fi

# ---- a receipt that never settles is a failure, not a hang ---------------------------------------------------------
run_smoke FAKE_RECEIPT_STATUSES='failed failed' -- "$BASE"
expect_status 1 'a valid PNG reported as failed'
grep -qE '^not ok 10 - ' <<<"$output" || fail "failed PNG: the receipts check must fail: $output"

if [ "$failures" -ne 0 ]; then
  printf '%s check(s) failed\n' "$failures"
  exit 1
fi
echo "smoke.test.sh: all passed"
