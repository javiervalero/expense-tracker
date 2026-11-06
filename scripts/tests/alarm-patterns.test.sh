#!/usr/bin/env bash
# Tests for the CloudWatch filter patterns of modules/observability (Phase 7 security review, MEDIUM: log-message
# alarms must not be forgeable by request-controlled text). Run: bash scripts/tests/alarm-patterns.test.sh
# The patterns are read from the `pattern = "..."` literals between `# alarm-messages:begin` and `# alarm-messages:end`
# (HCL escapes `\"` and `\\` are undone), every quoted term is turned into its literal text, and `grep -F` stands in
# for CloudWatch's substring match. Fixtures are literal JSON lines, written the way JsonLogger's json_encode writes
# them: every `"` and `\` inside a value is escaped with a backslash. A genuine line must match every term of its
# alarm (for an alarm with `?` terms: at least one), and a forged line must match none.
# Not proven here (needs the AWS read-only test-metric-filter, Phase 8): that CloudWatch reads `\"` inside a quoted term.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
MAIN_TF="${1:-$REPO/infra/terraform/modules/observability/main.tf}"

failures=0
fail() {
  failures=$((failures + 1))
  printf 'FAIL %s\n' "$1"
}

# Prints "<key><TAB><pattern>" for each entry of the guarded list, the HCL string unescaped.
patterns() {
  awk '
    /alarm-messages:begin/ { listed = 1; next }
    /alarm-messages:end/ { listed = 0; next }
    listed && match($0, /pattern[ \t]*=[ \t]*"([^"\\]|\\.)*"/) {
      key = $1
      raw = substr($0, RSTART, RLENGTH)
      sub(/^pattern[ \t]*=[ \t]*"/, "", raw)
      sub(/"$/, "", raw)
      out = ""
      n = length(raw)
      for (i = 1; i <= n; i++) {
        c = substr(raw, i, 1)
        if (c == "\\") { i++; c = substr(raw, i, 1) }
        out = out c
      }
      print key "\t" out
    }
  ' "$MAIN_TF"
}

# Prints the literal text of each quoted term of a pattern, one per line (\" inside a term is a quote).
terms() {
  awk '
    {
      s = $0; n = length(s); inq = 0; cur = ""
      for (i = 1; i <= n; i++) {
        c = substr(s, i, 1)
        if (inq && c == "\\" && substr(s, i + 1, 1) == "\"") { cur = cur "\""; i++; continue }
        if (c == "\"") {
          if (inq) { print cur; cur = "" }
          inq = !inq
          continue
        }
        if (inq) cur = cur c
      }
    }
  ' <<<"$1"
}

# Succeeds when the line matches the pattern: a plain pattern needs all its terms, a `?` pattern needs one of them.
line_matches() { # <pattern> <line>
  local pattern="$1" line="$2" term any=0 all=1 count=0
  while IFS= read -r term; do
    count=$((count + 1))
    if grep -qF -- "$term" <<<"$line"; then any=1; else all=0; fi
  done < <(terms "$pattern")
  [ "$count" -gt 0 ] || return 1 # no pattern, no match
  case "$pattern" in
    '?'*) [ "$any" -eq 1 ] ;;
    *) [ "$all" -eq 1 ] ;;
  esac
}

expect_match() { # <key> <label> <line>
  line_matches "${PATTERN[$1]:-}" "$3" || fail "$1: genuine line must match: $2"
}
expect_no_match() { # <key> <label> <line>
  if line_matches "${PATTERN[$1]:-}" "$3"; then fail "$1: forged line must NOT match: $2"; fi
}

declare -A PATTERN=()
while IFS=$'\t' read -r key pattern; do
  PATTERN["$key"]="$pattern"
done < <(patterns)

for key in receipt_cleanup_failed client_address_unavailable login_throttle_cleanup_failed corrupt_item; do
  [ -n "${PATTERN[$key]:-}" ] || fail "$key: no literal pattern = \"...\" between the markers of $MAIN_TF"
done

T='"time":"2026-11-06T10:00:00+00:00","level":"error"'
GENUINE_PREFIX='2026-11-06T10:00:00.000Z 1f2e3d4c-aaaa-bbbb-cccc-1234567890ab '

# --- genuine lines (with and without a log prefix) ---
expect_match receipt_cleanup_failed 'bare line' "{$T,\"message\":\"receipt cleanup failed\",\"requestId\":\"r1\"}"
expect_match receipt_cleanup_failed 'prefixed line' "${GENUINE_PREFIX}{$T,\"message\":\"receipt cleanup failed\",\"requestId\":\"r1\"}"
expect_match client_address_unavailable 'bare line' "{$T,\"message\":\"client address unavailable\",\"reason\":\"no-source-ip\"}"
expect_match login_throttle_cleanup_failed 'bare line' "{$T,\"message\":\"login throttle clean-up failed after a successful login\",\"requestId\":\"r1\",\"scope\":\"account\"}"
expect_match corrupt_item 'unhandled exception line' "{$T,\"message\":\"unhandled exception\",\"requestId\":\"r1\",\"status\":500,\"exception\":\"App\\\\Infrastructure\\\\DynamoDb\\\\StorageFailure\",\"exceptionMessage\":\"DynamoDB GetItem failed (CorruptItem)\",\"file\":\"/var/task/src/X.php\",\"line\":156}"
expect_match corrupt_item 'prefixed unhandled exception line' "${GENUINE_PREFIX}{$T,\"message\":\"unhandled exception\",\"exceptionMessage\":\"DynamoDB Query failed (CorruptItem)\",\"file\":\"/var/task/src/X.php\",\"line\":1}"
expect_match corrupt_item 'code field line' "{$T,\"message\":\"could not end the carried session\",\"requestId\":\"r1\",\"operation\":\"GetItem\",\"code\":\"CorruptItem\"}"

# --- forged lines: the attacker controls "path" and "origin", json_encode escapes their quotes and backslashes ---
W='"time":"2026-11-06T10:00:00+00:00","level":"warning","message":"origin verification failed","requestId":"r1","method":"GET"'
forge() { # <key> <text-as-typed-by-the-attacker-already-json-escaped>
  expect_no_match "$1" "path: $2" "{$W,\"path\":\"$2\",\"reason\":\"missing\"}"
  expect_no_match "$1" "origin: $2" "{\"time\":\"t\",\"level\":\"warning\",\"message\":\"csrf check failed\",\"path\":\"/x\",\"reason\":\"origin\",\"origin\":\"$2\"}"
}
forge receipt_cleanup_failed '/receipt cleanup failed'
forge receipt_cleanup_failed 'receipt cleanup failed'
forge receipt_cleanup_failed '\"message\":\"receipt cleanup failed\"'
forge receipt_cleanup_failed '\\\"message\\\":\\\"receipt cleanup failed\\\"'
forge client_address_unavailable '/client address unavailable'
forge client_address_unavailable '\"message\":\"client address unavailable\"'
forge login_throttle_cleanup_failed '/login throttle clean-up failed after a successful login'
forge login_throttle_cleanup_failed '\"message\":\"login throttle clean-up failed after a successful login\"'
forge corrupt_item '/CorruptItem'
forge corrupt_item 'CorruptItem'
forge corrupt_item '/x (CorruptItem)'
forge corrupt_item '\"code\":\"CorruptItem\"'
forge corrupt_item '(CorruptItem)\",\"file\"'
forge corrupt_item '\\\"code\\\":\\\"CorruptItem\\\"'

# A path that ends the value early cannot be written: the closing quote of a value is the logger's, never the attacker's.
expect_no_match corrupt_item 'path ending in (CorruptItem), next key reason' "{$W,\"path\":\"/x (CorruptItem)\",\"reason\":\"missing\"}"
# The request-log line (path, then clientIp) is not an anchor either.
expect_no_match corrupt_item 'request log line' '{"time":"t","level":"info","message":"request","path":"/CorruptItem","clientIp":"203.0.113.9"}'

if [ "$failures" -gt 0 ]; then
  printf '%d failure(s)\n' "$failures"
  exit 1
fi
printf 'alarm-patterns: ok\n'
