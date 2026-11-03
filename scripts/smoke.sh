#!/usr/bin/env bash
# End-to-end smoke test over HTTP, for any base URL (plan phase 6, D17-D20).
# Usage: scripts/smoke.sh [--cross-user] <base-url>
#   <base-url>   exactly https://<host>[:port], or http://localhost[:port]. No path, query, fragment or userinfo.
#                127.0.0.1 is refused: the session cookie is Secure and curl keeps it only for localhost.
#   --cross-user also registers a second user and checks that it cannot see the first user's data (2 registrations).
# Environment:
#   JQ_IMAGE        the pinned jq image (name@sha256:<64 hex>); make exports it, else `make print-JQ_IMAGE` is asked.
#   SMOKE_JQ        a local jq command to use instead of the container (for example on a CI runner).
#   SMOKE_EMAIL + SMOKE_PASSWORD   sign in with an existing account instead of registering a fresh one (registration
#                   is throttled to 5 per hour per address). Such an account must not hold data in 2001-02 / 2001-03.
# Exit codes: 0 all checks passed; 1 a check failed (the run stops, then cleans up); 2 usage or preflight error;
#             3 the checks passed (or stopped) but the cleanup failed.
#
# What it never does: send X-Origin-Verify (Vite adds it locally, CloudFront on AWS), print a body, header, cookie,
# password, upload policy, signature or signed URL, or put one in a command line (secrets travel in files of a private
# temporary directory: `curl -K <file>` and `--data-binary @<file>`). Output is only `ok N - name` / `not ok N - name`.
# The inputs are fixed and hand-written; there is one negative case per rule, no iteration over payloads, no scanning.
set +x
set -euo pipefail
umask 077
export LC_ALL=C

# The two files the receipt checks upload (D20). backend/tests/Unit/Smoke/SmokeFixturesTest.php reads these two lines
# and runs them through the worker's FileInspector. The PNG is a 1x1 image made once with GD (imagepng) in the php tools
# container; the text is declared as image/png and must come back as failed / unsupported-type.
readonly PNG_BASE64='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC'
readonly INVALID_TEXT='This is plain text, not an image.'

readonly THROTTLE_MESSAGE='registration is throttled (5 per hour per address): wait, restart the local stack, or set SMOKE_EMAIL/SMOKE_PASSWORD'
readonly BASE_HTTPS_RE='^https://[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:[0-9]{1,5})?$'
readonly BASE_LOCAL_RE='^http://localhost(:[0-9]{1,5})?$'
readonly FIELD_NAME_RE='^(key|Content-Type|Policy|X-Amz-[A-Za-z0-9-]+)$'
readonly FIELD_VALUE_RE='^[A-Za-z0-9+/=._:-]+$'
readonly UUID_RE='^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
readonly MAX_POLL_MS=5000
# How long a receipt may stay pending. SMOKE_MAX_WAIT (digits, seconds) exists so a test can reach the timeout.
MAX_WAIT_SECONDS=${SMOKE_MAX_WAIT:-30}

usage_error() {
  echo "usage: scripts/smoke.sh [--cross-user] <base-url>   (https://host[:port] or http://localhost[:port])" >&2
  exit 2
}

preflight_error() {
  echo "smoke: $1" >&2
  exit 2
}

# ---- arguments and environment (no external command runs before these pass) ------------------------------------------
CROSS_USER=0
BASE=''
positional=0
for arg in "$@"; do
  case "$arg" in
    --cross-user) CROSS_USER=1 ;;
    -?*) usage_error ;;
    *) positional=$((positional + 1)); BASE=$arg ;;
  esac
done
[ "$positional" -eq 1 ] || usage_error
if [[ $BASE =~ ^http://127\.0\.0\.1 ]]; then
  preflight_error "use localhost, not 127.0.0.1 (the session cookie is Secure and curl keeps it only for localhost)"
fi
[[ $BASE =~ $BASE_HTTPS_RE || $BASE =~ $BASE_LOCAL_RE ]] || usage_error
[[ $MAX_WAIT_SECONDS =~ ^[0-9]{1,3}$ ]] || preflight_error "SMOKE_MAX_WAIT must be a number of seconds (digits only)"

# Where signed uploads and views may go, tied to the mode (S2): a localhost base talks to the local S3 emulator only;
# an https base accepts https only (never http://localhost:9000). Phase 8: pin the expected S3 host.
PROXY_ARGS=()
if [[ $BASE =~ $BASE_LOCAL_RE ]]; then
  S3_ORIGIN_RE='http://localhost:9000'
  PROXY_ARGS=(--noproxy localhost)
else
  S3_ORIGIN_RE='https://[A-Za-z0-9.-]+(:[0-9]{1,5})?'
fi
UPLOAD_URL_RE='^'$S3_ORIGIN_RE'(/[A-Za-z0-9._~/-]*)?$'
VIEW_URL_RE='^'$S3_ORIGIN_RE'/[A-Za-z0-9._~/%+=&:?-]*$'

LOGIN_MODE=0
EMAIL=''
PASSWORD=''
if [ -n "${SMOKE_EMAIL:-}" ] || [ -n "${SMOKE_PASSWORD:-}" ]; then
  [ -n "${SMOKE_EMAIL:-}" ] && [ -n "${SMOKE_PASSWORD:-}" ] || preflight_error "set both SMOKE_EMAIL and SMOKE_PASSWORD, or neither"
  [[ $SMOKE_EMAIL =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$ ]] || preflight_error "SMOKE_EMAIL is not a plain email address"
  [[ $SMOKE_PASSWORD =~ ^[\ -~]{12,128}$ && $SMOKE_PASSWORD != *\"* && $SMOKE_PASSWORD != *\\* ]] \
    || preflight_error "SMOKE_PASSWORD must be 12-128 printable ASCII characters without a double quote or a backslash"
  LOGIN_MODE=1
  EMAIL=$SMOKE_EMAIL
  PASSWORD=$SMOKE_PASSWORD
  unset SMOKE_PASSWORD
fi

# ---- preflight: curl, jq -------------------------------------------------------------------------------------------
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
command -v curl >/dev/null || preflight_error "curl is not installed"
curl_version="$(curl -q --version 2>/dev/null)" || preflight_error "curl --version failed"
curl_first_line=${curl_version%%$'\n'*}
if [[ $curl_first_line =~ ^curl\ ([0-9]+)\.([0-9]+) ]]; then
  if [ "${BASH_REMATCH[1]}" -lt 7 ] || { [ "${BASH_REMATCH[1]}" -eq 7 ] && [ "${BASH_REMATCH[2]}" -lt 79 ]; }; then
    preflight_error "curl 7.79 or later is needed (it keeps the Secure cookie on http://localhost)"
  fi
else
  preflight_error "could not read the curl version"
fi
command -v base64 >/dev/null || preflight_error "base64 is not installed"

if [ -n "${SMOKE_JQ:-}" ]; then
  JQ=("$SMOKE_JQ")
else
  jq_image=${JQ_IMAGE:-}
  if [ -z "$jq_image" ]; then
    jq_image="$(make -s --no-print-directory -C "$REPO" print-JQ_IMAGE 2>/dev/null)" || jq_image=''
  fi
  [[ $jq_image =~ ^[^[:space:]]+@sha256:[0-9a-f]{64}$ ]] \
    || preflight_error "the jq image is not pinned by digest (JQ_IMAGE must be name@sha256:<64 hex>)"
  command -v docker >/dev/null || preflight_error "docker is not installed (or set SMOKE_JQ to a local jq)"
  JQ=(docker run --rm -i --log-driver none --network none "$jq_image")
fi

# ---- state, temporary directory, cleanup ---------------------------------------------------------------------------
API="$BASE/api/v1"
WORK="$(mktemp -d)"
chmod 700 "$WORK"
JAR="$WORK/jar.txt"
JAR2="$WORK/jar2.txt"
RESP="$WORK/resp.json"
BODY="$WORK/body.json"
CONF="$WORK/curl.conf"
EXT_OUT="$WORK/ext.out"
ERR="$WORK/err.txt"
: >"$JAR"
: >"$ERR"

N=0
GROUP=''
CODE=000
CURL_RC=0
CTYPE=''
ID=''
VERSION=''
REQ_EXTRA=()
EXPENSES=()
CATEGORIES=()
declare -A GONE=()
SESSION_OPEN=0
SESSION2_OPEN=0
CLEANUP_FAILED=0
CLEANUP_REPORTED=0

clean() { printf '%s' "$1" | tr -cd '[:print:]' | cut -c1-200; }

# One HTTP call to the API. Sets CODE (000 when curl itself failed) and CURL_RC; the body is in $RESP.
# Optional: REQ_JAR (another cookie jar), NO_XRW (leave out X-Requested-With), REQ_EXTRA (more curl arguments).
req() { # <method> <path> [body-file]
  local method=$1 path=$2 body=${3:-} jar=${REQ_JAR:-$JAR} rc=0
  # -q first: curl must not read ~/.curlrc (it could add a proxy, a verbose flag or a header). PROXY_ARGS: no proxy for localhost.
  local args=(-q -sS --max-time 15 --max-redirs 0 --proto =https,http -o "$RESP" -w '%{http_code}' -X "$method"
    -H "Origin: $BASE" -H 'Accept: application/json' -b "$jar" -c "$jar")
  if [ "${#PROXY_ARGS[@]}" -gt 0 ]; then args+=("${PROXY_ARGS[@]}"); fi
  if [ -z "${NO_XRW:-}" ]; then args+=(-H 'X-Requested-With: XMLHttpRequest'); fi
  if [ -n "$body" ]; then args+=(-H 'Content-Type: application/json' --data-binary "@$body"); fi
  if [ "${#REQ_EXTRA[@]}" -gt 0 ]; then args+=("${REQ_EXTRA[@]}"); fi
  : >"$RESP"
  CODE="$(curl "${args[@]}" "$API$path" 2>>"$ERR")" || rc=$?
  CURL_RC=$rc
  if [ "$rc" -ne 0 ]; then CODE=000; fi
  return 0
}

# One call to a signed address (the bucket upload, or the view address) described by a curl config file.
ext_call() { # <config-file>
  local out rc=0
  : >"$EXT_OUT"
  local args=(-q -sS --max-time 15 --max-redirs 0 --proto =https,http -o "$EXT_OUT" -w '%{http_code} %{content_type}')
  if [ "${#PROXY_ARGS[@]}" -gt 0 ]; then args+=("${PROXY_ARGS[@]}"); fi
  out="$(curl "${args[@]}" -K "$1" 2>/dev/null)" || rc=$?
  CURL_RC=$rc
  CODE=${out%% *}
  CTYPE=${out#* }
  if [ "$rc" -ne 0 ]; then CODE=000; CTYPE=''; fi
  rm -f "$CONF"
  return 0
}

jq_r() { "${JQ[@]}" -r "$@" 2>>"$ERR"; }
jq_t() { "${JQ[@]}" -e "$@" >/dev/null 2>>"$ERR"; }

title_of() {
  local title=''
  if [ "$CODE" = 000 ]; then
    printf 'curl exit %s' "$CURL_RC"
    return 0
  fi
  title="$(jq_r '.title // empty' <"$RESP")" || title=''
  [ -n "$title" ] || title='no problem title'
  clean "$title"
}

begin() {
  N=$((N + 1))
  GROUP=$1
}

pass() { printf 'ok %d - %s\n' "$N" "$GROUP"; }

fail_check() { # <step> <code> <detail>
  printf 'not ok %d - %s: %s (HTTP %s: %s)\n' "$N" "$GROUP" "$1" "$2" "$(clean "$3")"
  exit 1
}

expect() { # <code> <step>: the last call answered with this status
  [ "$CODE" = "$1" ] || fail_check "$2" "$CODE" "$(title_of)"
}

assert() { # <step> <jq arguments...>: a yes/no test on the last body
  jq_t "${@:2}" <"$RESP" || fail_check "$1" "$CODE" 'unexpected response content'
}

read_id() { # <step>: ID and VERSION from the last body
  ID="$(jq_r '.id' <"$RESP")" || ID=''
  [[ $ID =~ $UUID_RE ]] || fail_check "$1" "$CODE" 'the response has no valid id'
  VERSION="$(jq_r '.version' <"$RESP")" || VERSION=''
  [[ $VERSION =~ ^[0-9]+$ ]] || fail_check "$1" "$CODE" 'the response has no valid version'
}

put_body() { printf '%s' "$1" >"$BODY"; }

report() { req GET "/reports/monthly?month=$1"; }

cleanup_all() { # best effort; never exits. Sets CLEANUP_FAILED.
  local id
  for id in "${EXPENSES[@]}"; do
    [ -z "${GONE[$id]:-}" ] || continue
    req DELETE "/expenses/$id"
    if [ "$CODE" = 204 ] || [ "$CODE" = 404 ]; then GONE[$id]=1; else CLEANUP_FAILED=1; fi
  done
  for id in "${CATEGORIES[@]}"; do
    [ -z "${GONE[$id]:-}" ] || continue
    req DELETE "/categories/$id"
    if [ "$CODE" = 204 ] || [ "$CODE" = 404 ]; then GONE[$id]=1; else CLEANUP_FAILED=1; fi
  done
  if [ "$SESSION2_OPEN" -eq 1 ]; then
    REQ_JAR=$JAR2 req POST /auth/logout
    if [ "$CODE" = 204 ] || [ "$CODE" = 401 ]; then SESSION2_OPEN=0; else CLEANUP_FAILED=1; fi
  fi
  if [ "$SESSION_OPEN" -eq 1 ]; then
    req POST /auth/logout
    # 401: the session was already closed (expired, or ended by the server)
    if [ "$CODE" = 204 ] || [ "$CODE" = 401 ]; then SESSION_OPEN=0; else CLEANUP_FAILED=1; fi
  fi
  return 0
}

on_exit() {
  local rc=$?
  # No -e (a failed cleanup call must not end the trap) and no -u (an empty array must not abort it on older bash).
  set +eu
  # A second Ctrl-C during the network cleanup still removes the temporary directory (it holds the cookie jar).
  trap 'rm -rf "$WORK"; exit 130' INT TERM HUP
  trap - EXIT
  cleanup_all
  if [ "$CLEANUP_FAILED" -ne 0 ] && [ "$CLEANUP_REPORTED" -eq 0 ]; then
    printf 'not ok %d - cleanup: some smoke data or a session could not be removed\n' "$((N + 1))"
  fi
  rm -rf "$WORK"
  if [ "$rc" -eq 0 ] && [ "$CLEANUP_FAILED" -ne 0 ]; then rc=3; fi
  exit "$rc"
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

new_credentials() { # sets NEW_EMAIL and NEW_PASSWORD (the password is never printed)
  local stamp hex
  stamp="$(date -u +%Y%m%dT%H%M%SZ)"
  hex="$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"
  NEW_EMAIL="smoke-$stamp-$hex@example.com"
  NEW_PASSWORD="$(head -c 24 /dev/urandom | base64 | tr -d '\n')"
}

# ---- upload helpers -------------------------------------------------------------------------------------------------
declare -a FNAMES=() FVALUES=()

# Reads the signed form of the last upload answer. Every refusal happens here, before anything is sent.
read_upload_form() { # <step>
  local url lines name value
  url="$(jq_r '.url' <"$RESP")" || url=''
  [[ $url =~ $UPLOAD_URL_RE ]] || fail_check "$1" "$CODE" 'the upload address is not on the allow-list'
  UPLOAD_URL=$url
  lines="$(jq_r '.fields | to_entries[] | [.key, .value] | @tsv' <"$RESP")" || lines=''
  FNAMES=()
  FVALUES=()
  while IFS=$'\t' read -r name value; do
    [ -n "$name" ] || continue
    [[ $name =~ $FIELD_NAME_RE ]] || fail_check "$1" "$CODE" 'the upload form has a field that is not on the allow-list'
    [[ $value =~ $FIELD_VALUE_RE ]] || fail_check "$1" "$CODE" 'the upload form has a field value with unexpected characters'
    FNAMES+=("$name")
    FVALUES+=("$value")
  done <<<"$lines"
  [ "${#FNAMES[@]}" -gt 0 ] || fail_check "$1" "$CODE" 'the upload form has no fields'
}

start_upload() { # <expense id> <file> <declared size> <step>: policy, then the form is POSTed, the file part last
  local id=$1 file=$2 size=$3 step=$4 i
  put_body "{\"contentType\":\"image/png\",\"size\":$size}"
  req POST "/expenses/$id/receipt/upload" "$BODY"
  expect 201 "$step: start the upload"
  read_upload_form "$step: check the signed form"
  {
    printf 'url = "%s"\n' "$UPLOAD_URL"
    for i in "${!FNAMES[@]}"; do printf 'form-string = "%s=%s"\n' "${FNAMES[$i]}" "${FVALUES[$i]}"; done
    printf 'form = "file=@%s;type=image/png"\n' "$file"
  } >"$CONF"
  ext_call "$CONF"
}

await_receipt() { # <expense id>: RSTATUS settles, or the run fails after MAX_WAIT_SECONDS
  local id=$1 delay_ms=1000 started=$SECONDS remaining_ms sleep_ms
  while :; do
    req GET "/expenses/$id/receipt"
    expect 200 'read the receipt'
    RSTATUS="$(jq_r '.status' <"$RESP")" || RSTATUS=''
    [ "$RSTATUS" = pending ] || return 0
    remaining_ms=$((MAX_WAIT_SECONDS * 1000 - (SECONDS - started) * 1000))
    [ "$remaining_ms" -gt 0 ] || fail_check 'the receipt is still pending' "$CODE" 'the worker did not settle it in time'
    sleep_ms=$delay_ms
    if [ "$sleep_ms" -gt "$remaining_ms" ]; then sleep_ms=$remaining_ms; fi
    sleep "$(printf '%d.%03d' "$((sleep_ms / 1000))" "$((sleep_ms % 1000))")"
    delay_ms=$((delay_ms * 3 / 2))
    if [ "$delay_ms" -gt "$MAX_POLL_MS" ]; then delay_ms=$MAX_POLL_MS; fi
  done
}

file_size() { # <file>
  local size
  size="$(wc -c <"$1")"
  printf '%s' "${size//[[:space:]]/}"
}

create_expense() { # <date> <amount> <description> <category id or empty> <step>: sets ID and VERSION
  local category='null'
  if [ -n "$4" ]; then category="\"$4\""; fi
  put_body "$(printf '{"date":"%s","amountCents":%d,"description":"%s","categoryId":%s}' "$1" "$2" "$3" "$category")"
  req POST /expenses "$BODY"
  expect 201 "$5"
  read_id "$5"
  EXPENSES+=("$ID")
}

# ---- the checks -----------------------------------------------------------------------------------------------------
begin 'health endpoint answers'
req GET /health
expect 200 'GET /health'
pass

begin 'no session is refused'
req GET /auth/me
expect 401 'GET /auth/me without a cookie'
pass

if [ "$LOGIN_MODE" -eq 1 ]; then
  begin 'sign in with the given account'
  printf '{"email":"%s","password":"%s"}' "$EMAIL" "$PASSWORD" >"$BODY"
  req POST /auth/login "$BODY"
  rm -f "$BODY"
  expect 200 'sign in'
  SESSION_OPEN=1
  for month in 2001-02 2001-03; do
    report "$month"
    expect 200 "read $month"
    assert 'leftover smoke data in this account' --arg m "$month" '.month == $m and .expenseCount == 0'
  done
else
  begin 'register a new account'
  new_credentials
  EMAIL=$NEW_EMAIL
  PASSWORD=$NEW_PASSWORD
  printf '{"email":"%s","password":"%s"}' "$EMAIL" "$PASSWORD" >"$BODY"
  req POST /auth/register "$BODY"
  rm -f "$BODY"
  if [ "$CODE" = 429 ]; then fail_check 'register' 429 "$THROTTLE_MESSAGE"; fi
  expect 201 'register'
  SESSION_OPEN=1
fi
pass

begin 'a state-changing call without X-Requested-With is refused'
put_body '{"name":"Smoke csrf"}'
NO_XRW=1 req POST /categories "$BODY"
expect 403 'POST /categories without the header'
pass

begin 'the session identifies the user'
req GET /auth/me
expect 200 'GET /auth/me'
assert 'the email is the one signed in' --arg e "${EMAIL,,}" '.email == $e'
pass

begin 'categories'
put_body '{"name":"Smoke food"}'
req POST /categories "$BODY"
expect 201 'create Smoke food'
read_id 'create Smoke food'
FOOD=$ID
FOOD_VERSION=$VERSION
CATEGORIES+=("$FOOD")
put_body '{"name":"Smoke travel"}'
req POST /categories "$BODY"
expect 201 'create Smoke travel'
read_id 'create Smoke travel'
TRAVEL=$ID
CATEGORIES+=("$TRAVEL")
req GET /categories
expect 200 'list'
assert 'the list holds the two new categories' '.items | length == 2'
put_body "{\"name\":\"Smoke meals\",\"version\":$FOOD_VERSION}"
req PATCH "/categories/$FOOD" "$BODY"
expect 200 'rename with the current version'
assert 'the rename is stored' '.name == "Smoke meals" and .version == 2'
put_body '{"name":"smoke TRAVEL"}'
req POST /categories "$BODY"
expect 409 'a duplicate name (any case)'
pass

begin 'expenses'
create_expense 2001-01-31 700 'Smoke january' '' 'create the 2001-01-31 expense'
JAN=$ID
create_expense 2001-02-01 1250 'Smoke lunch' "$FOOD" 'create the 2001-02-01 expense'
FEB_FOOD=$ID
FEB_FOOD_VERSION=$VERSION
create_expense 2001-02-15 3000 'Smoke train' "$TRAVEL" 'create the 2001-02-15 expense'
create_expense 2001-02-28 400 'Smoke misc' '' 'create the 2001-02-28 expense'
FEB_NONE=$ID
create_expense 2001-03-01 99 'Smoke march' '' 'create the 2001-03-01 expense'
MARCH=$ID
req GET "/expenses/$FEB_FOOD"
expect 200 'read one expense'
assert 'the amount is as created' '.amountCents == 1250'
put_body "{\"amountCents\":1500,\"version\":$FEB_FOOD_VERSION}"
req PATCH "/expenses/$FEB_FOOD" "$BODY"
expect 200 'update with the current version'
assert 'the update is stored' '.amountCents == 1500 and .version == 2'
put_body "{\"amountCents\":1600,\"version\":$FEB_FOOD_VERSION}"
req PATCH "/expenses/$FEB_FOOD" "$BODY"
expect 409 'update with a stale version'
req GET "/expenses?from=2001-02-01&to=2001-02-28&category=$FOOD&q=lunch&limit=1"
expect 200 'filtered list'
assert 'the filters select the one expense' '(.items | length) == 1 and .items[0].description == "Smoke lunch"'
cursor=''
declare -A SEEN=()
for _page in 1 2 3 4 5 6 7 8 9 10 11 12; do
  REQ_EXTRA=()
  if [ -n "$cursor" ]; then REQ_EXTRA=(--get --data-urlencode "cursor=$cursor"); fi
  req GET '/expenses?from=2001-01-01&to=2001-03-31&limit=1'
  REQ_EXTRA=()
  expect 200 'list one page'
  page_ids="$(jq_r '.items[].id' <"$RESP")" || page_ids=''
  while IFS= read -r item_id; do
    [ -n "$item_id" ] || continue
    [[ $item_id =~ $UUID_RE ]] || fail_check 'list one page' "$CODE" 'an item has no valid id'
    [ -z "${SEEN[$item_id]:-}" ] || fail_check 'following nextCursor never repeats an expense' "$CODE" 'the same expense came twice'
    SEEN[$item_id]=1
  done <<<"$page_ids"
  cursor="$(jq_r '.nextCursor // empty' <"$RESP")" || cursor=''
  [ -n "$cursor" ] || break
done
[ -z "$cursor" ] || fail_check 'following nextCursor ends' "$CODE" 'there is still a next page after twelve'
[ "${#SEEN[@]}" -eq 5 ] || fail_check 'following nextCursor collects the five expenses' "$CODE" 'wrong number of expenses'
pass

begin 'monthly summary'
report 2001-02
expect 200 'read 2001-02'
assert '2001-02 totals' --arg food "$FOOD" --arg travel "$TRAVEL" \
  '.month == "2001-02" and .totalCents == 4900 and .expenseCount == 3 and .complete == true
   and (.categories | length) == 3
   and .categories[0].categoryId == $travel and .categories[0].name == "Smoke travel"
   and .categories[0].totalCents == 3000 and .categories[0].expenseCount == 1
   and .categories[1].categoryId == $food and .categories[1].name == "Smoke meals"
   and .categories[1].totalCents == 1500 and .categories[1].expenseCount == 1
   and .categories[2].categoryId == null and .categories[2].name == null
   and .categories[2].totalCents == 400 and .categories[2].expenseCount == 1'
report 2001-03
expect 200 'read 2001-03'
assert '2001-03 holds only the boundary expense' \
  '.month == "2001-03" and .totalCents == 99 and .expenseCount == 1 and .complete == true
   and (.categories | length) == 1 and .categories[0].categoryId == null and .categories[0].totalCents == 99'
report 2001-04
expect 200 'read 2001-04'
assert '2001-04 is empty' '.month == "2001-04" and .totalCents == 0 and .expenseCount == 0 and .complete == true and .categories == []'
report 2001-13
expect 400 'month=2001-13 is refused'
req GET /reports/monthly
expect 400 'a missing month is refused'
pass

begin 'monthly summary after deleting a category'
req DELETE "/categories/$TRAVEL"
expect 204 'delete Smoke travel'
GONE[$TRAVEL]=1
report 2001-02
expect 200 'read 2001-02 again'
assert 'the deleted category joins the uncategorized row' --arg food "$FOOD" \
  '.totalCents == 4900 and .expenseCount == 3 and (.categories | length) == 2
   and .categories[0].categoryId == null and .categories[0].name == null
   and .categories[0].totalCents == 3400 and .categories[0].expenseCount == 2
   and .categories[1].categoryId == $food and .categories[1].totalCents == 1500'
pass

begin 'receipts'
printf '%s' "$PNG_BASE64" | base64 -d >"$WORK/receipt.png"
printf '%s' "$INVALID_TEXT" >"$WORK/receipt.txt"
{ cat "$WORK/receipt.png"; printf 'x'; } >"$WORK/oversize.png"
png_size="$(file_size "$WORK/receipt.png")"
text_size="$(file_size "$WORK/receipt.txt")"
[ "$png_size" -gt 0 ] || fail_check 'decode the PNG fixture' 000 'the fixture is empty'
start_upload "$JAN" "$WORK/receipt.png" "$png_size" 'valid PNG'
expect 204 'valid PNG: upload to the bucket'
await_receipt "$JAN"
[ "$RSTATUS" = ready ] || fail_check 'valid PNG: the receipt becomes ready' "$CODE" 'the receipt did not become ready'
assert 'valid PNG: ready with both addresses' '.status == "ready" and .contentType == "image/png" and .failureReason == null and .viewUrl != null and .thumbnailUrl != null'
view="$(jq_r '.viewUrl' <"$RESP")" || view=''
[[ $view =~ $VIEW_URL_RE ]] || fail_check 'valid PNG: check the view address' "$CODE" 'the view address is not on the allow-list'
printf 'url = "%s"\n' "$view" >"$CONF"
ext_call "$CONF"
[ "$CODE" = 200 ] || fail_check 'valid PNG: fetch the view address' "$CODE" 'the original was not served'
[ "${CTYPE%%;*}" = image/png ] || fail_check 'valid PNG: the view address serves image/png' "$CODE" 'wrong content type'
start_upload "$MARCH" "$WORK/receipt.txt" "$text_size" 'text declared as PNG'
expect 204 'text declared as PNG: upload to the bucket'
await_receipt "$MARCH"
[ "$RSTATUS" = failed ] || fail_check 'text declared as PNG: the receipt fails' "$CODE" 'the receipt did not fail'
assert 'text declared as PNG: unsupported-type' '.status == "failed" and .failureReason == "unsupported-type" and .viewUrl == null'
start_upload "$FEB_NONE" "$WORK/oversize.png" "$png_size" 'size + 1'
# Any 400 passes here. Phase 8: tighten it to the bucket's EntityTooLarge answer once it is seen against real S3.
expect 400 'size + 1: the bucket refuses the upload'
req DELETE "/expenses/$JAN/receipt"
expect 204 'delete the receipt'
req GET "/expenses/$JAN/receipt"
expect 404 'the deleted receipt is gone'
pass

if [ "$CROSS_USER" -eq 1 ]; then
  begin 'another user cannot see this user'"'"'s data'
  new_credentials
  printf '{"email":"%s","password":"%s"}' "$NEW_EMAIL" "$NEW_PASSWORD" >"$BODY"
  REQ_JAR=$JAR2 req POST /auth/register "$BODY"
  rm -f "$BODY"
  if [ "$CODE" = 429 ]; then fail_check 'register the second user' 429 "$THROTTLE_MESSAGE"; fi
  expect 201 'register the second user'
  SESSION2_OPEN=1
  REQ_JAR=$JAR2 req GET "/expenses/$JAN"
  expect 404 'the other user reads an expense'
  REQ_JAR=$JAR2 req GET "/expenses/$FEB_NONE/receipt"
  expect 404 'the other user reads a receipt'
  REQ_JAR=$JAR2 report 2001-02
  expect 200 'the other user reads 2001-02'
  assert 'the other user sees an empty month' '.totalCents == 0 and .expenseCount == 0 and .categories == []'
  REQ_JAR=$JAR2 req GET '/expenses?from=2001-01-01&to=2001-03-31'
  expect 200 'the other user lists expenses'
  assert 'the other user sees no expenses' '.items == []'
  pass
fi

begin 'cleanup'
cleanup_all
if [ "$CLEANUP_FAILED" -ne 0 ]; then
  CLEANUP_REPORTED=1
  printf 'not ok %d - cleanup: some smoke data or a session could not be removed\n' "$N"
  exit 3
fi
req GET /auth/me
expect 401 'the session is closed'
pass
