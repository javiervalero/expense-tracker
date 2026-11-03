#!/usr/bin/env bash
# Tests for scripts/bref-layers.php. Run: bash scripts/tests/bref-layers.test.sh
# The script runs in the pinned php tools image (make php-image), as the host user; PHP_RUN can replace that command
# (CI could call php directly). Fixtures are tiny hand-written layers.json files created at run time under build/.
# Exit codes asserted: 0 = file written (or no drift), 1 = bad input or drift.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
cd "$ROOT"

PHP_IMAGE="${PHP_IMAGE:-expense-tracker-php:dev}"
PHP_RUN="${PHP_RUN:-docker run --rm -u $(id -u):$(id -g) -v $ROOT:/app -w /app $PHP_IMAGE php}"

mkdir -p build
WORK="$(mktemp -d "$ROOT/build/bref-layers-test.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
REL="${WORK#"$ROOT"/}" # the same directory as seen from /app inside the container

failures=0
status=0
output=''

run_script() { # <args...>
  status=0
  # shellcheck disable=SC2086 # PHP_RUN is a command line on purpose
  output="$(timeout 120 $PHP_RUN scripts/bref-layers.php "$@" 2>&1)" || status=$?
}

fail() {
  failures=$((failures + 1))
  printf 'FAIL %s\n' "$1"
}

expect_status() { # <label> <expected>
  [ "$status" -eq "$2" ] || fail "$1: expected exit $2, got $status: $output"
}

# Fixtures: a php-85 file, and a gd file, each with two regions.
printf '{\n    "php-85": {"us-east-1": 20, "eu-west-1": 21},\n    "arm-php-85": {"us-east-1": 1}\n}\n' >"$WORK/php.json"
printf '{\n    "gd-php-85": {"us-east-1": 1, "eu-west-1": 2}\n}\n' >"$WORK/gd.json"

# Region keys must be a JSON object with real region names: a list, or a name that is not a region, fails.
printf '{"php-85": [20, 21]}\n' >"$WORK/list.json"
run_script --php-layers "$REL/list.json" --gd-layers "$REL/gd.json" --out "$REL/list-out.json"
expect_status 'regions given as a list' 1
printf '{"php-85": {"Mars": 20}}\n' >"$WORK/badregion-php.json"
printf '{"gd-php-85": {"Mars": 1}}\n' >"$WORK/badregion-gd.json"
run_script --php-layers "$REL/badregion-php.json" --gd-layers "$REL/badregion-gd.json" --out "$REL/badregion-out.json"
expect_status 'region name that is not a region' 1
[ ! -e "$WORK/badregion-out.json" ] || fail 'bad region: an output file was written'

# Good input: exit 0, sorted keys, integers, trailing newline, nothing else.
run_script --php-layers "$REL/php.json" --gd-layers "$REL/gd.json" --out "$REL/out.json"
expect_status 'good input' 0
want='{
    "bref_gd_layer_versions": {
        "eu-west-1": 2,
        "us-east-1": 1
    },
    "bref_php_layer_versions": {
        "eu-west-1": 21,
        "us-east-1": 20
    }
}'
[ "$(cat "$WORK/out.json")" = "$want" ] || fail "good input: unexpected content: $(cat "$WORK/out.json")"
[ "$(tail -c 1 "$WORK/out.json" | od -An -c | tr -d ' ')" = '\n' ] || fail 'good input: no trailing newline'

# Same input, same bytes.
run_script --php-layers "$REL/php.json" --gd-layers "$REL/gd.json" --out "$REL/out2.json"
cmp -s "$WORK/out.json" "$WORK/out2.json" || fail 'same input gave different bytes'

# Region order in the input does not change the output.
printf '{"php-85": {"eu-west-1": 21, "us-east-1": 20}}' >"$WORK/php-reordered.json"
run_script --php-layers "$REL/php-reordered.json" --gd-layers "$REL/gd.json" --out "$REL/out3.json"
cmp -s "$WORK/out.json" "$WORK/out3.json" || fail 'reordered input gave different bytes'

# A missing php-85 key fails and names the key; no output file is left behind.
printf '{"arm-php-85": {"us-east-1": 1}}' >"$WORK/nokey.json"
run_script --php-layers "$REL/nokey.json" --gd-layers "$REL/gd.json" --out "$REL/nokey-out.json"
expect_status 'missing php-85 key' 1
grep -qF 'php-85' <<<"$output" || fail "missing php-85 key: message does not name it: $output"
[ ! -e "$WORK/nokey-out.json" ] || fail 'missing key: an output file was written'

# A missing gd-php-85 key fails.
printf '{"other": {"us-east-1": 1}}' >"$WORK/nogd.json"
run_script --php-layers "$REL/php.json" --gd-layers "$REL/nogd.json" --out "$REL/nogd-out.json"
expect_status 'missing gd-php-85 key' 1
grep -qF 'gd-php-85' <<<"$output" || fail "missing gd key: message does not name it: $output"

# A region present in one file but not the other fails and names the region.
printf '{"gd-php-85": {"us-east-1": 1}}' >"$WORK/gd-short.json"
run_script --php-layers "$REL/php.json" --gd-layers "$REL/gd-short.json" --out "$REL/short-out.json"
expect_status 'region missing in the gd file' 1
grep -qF 'eu-west-1' <<<"$output" || fail "region missing in gd: message does not name the region: $output"
printf '{"gd-php-85": {"us-east-1": 1, "eu-west-1": 2, "ap-south-1": 3}}' >"$WORK/gd-long.json"
run_script --php-layers "$REL/php.json" --gd-layers "$REL/gd-long.json" --out "$REL/long-out.json"
expect_status 'region missing in the php file' 1
grep -qF 'ap-south-1' <<<"$output" || fail "region missing in php: message does not name the region: $output"

# A non-integer version fails: string, float, zero, negative, null.
for bad in '"20"' '20.5' '0' '-3' 'null' 'true'; do
  printf '{"php-85": {"us-east-1": %s, "eu-west-1": 21}}' "$bad" >"$WORK/bad.json"
  run_script --php-layers "$REL/bad.json" --gd-layers "$REL/gd.json" --out "$REL/bad-out.json"
  expect_status "version $bad" 1
  grep -qF 'us-east-1' <<<"$output" || fail "version $bad: message does not name the region: $output"
done

# Unreadable or invalid JSON, and a missing file, fail with a message.
printf '{not json' >"$WORK/broken.json"
run_script --php-layers "$REL/broken.json" --gd-layers "$REL/gd.json" --out "$REL/broken-out.json"
expect_status 'invalid JSON' 1
run_script --php-layers "$REL/does-not-exist.json" --gd-layers "$REL/gd.json" --out "$REL/x-out.json"
expect_status 'missing input file' 1
run_script --unknown-option
expect_status 'unknown option' 1

# Drift: --check compares without writing. Equal passes; changed content or a missing file fails.
run_script --php-layers "$REL/php.json" --gd-layers "$REL/gd.json" --out "$REL/out.json" --check
expect_status 'check, no drift' 0
printf '{"php-85": {"us-east-1": 22, "eu-west-1": 21}}' >"$WORK/php-new.json"
run_script --php-layers "$REL/php-new.json" --gd-layers "$REL/gd.json" --out "$REL/out.json" --check
expect_status 'check, drift' 1
grep -qi 'drift\|differs\|out of date' <<<"$output" || fail "check, drift: message does not say so: $output"
cmp -s "$WORK/out.json" "$WORK/out2.json" || fail 'check modified the committed file'
run_script --php-layers "$REL/php.json" --gd-layers "$REL/gd.json" --out "$REL/absent.json" --check
expect_status 'check, file absent' 1

if [ "$failures" -ne 0 ]; then
  printf '%d failure(s)\n' "$failures"
  exit 1
fi
echo 'bref-layers: all cases passed'
