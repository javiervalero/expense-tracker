#!/usr/bin/env bash
# Spike A: Bref 3 on php-85. A list of named checks A1..A11.
# Prints PASS/FAIL per check, exits non-zero if any check fails.
# Docker only. Containers are named spikea-*; host ports 19001..19006 on 127.0.0.1.
# Usage: ./run.sh            (all checks)     ./run.sh A2 A5   (selected checks)
set -uo pipefail
cd "$(dirname "$0")"

OUT=.out
mkdir -p "$OUT"
FAILS=0

BREF_IMG=bref/php-85:3
BREF_DEV_IMG=bref/php-85-dev:3
GD_IMG=bref/extra-gd-php-85:3
FPM_IMG=php:8.5-fpm-alpine
CLI_IMG=php:8.5-cli-alpine
COMPOSER_IMG=composer:2.10.3
NETSHOOT_IMG=nicolaka/netshoot:latest   # digest is recorded in A1 (pins.txt)
WORKER_IMG=spikea-worker
VENDOR_IMG=spikea-vendor
TOOLS_IMG=spikea-tools
RIE_PATH=/2015-03-31/functions/function/invocations
CONTAINERS=(spikea-fn spikea-fn-norun spikea-fpm spikea-telem-on spikea-telem-off spikea-telem-cap-on spikea-telem-cap-off)

cleanup() { docker rm -f "${CONTAINERS[@]}" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup

log() { echo "$*"; }

# invoke PORT FILE -> prints status line, headers, body (curl -si), retrying while RIE starts
invoke() {
  local port=$1 file=$2 i
  for i in $(seq 1 40); do
    if curl -si -m 30 -X POST --data-binary "@$file" "http://127.0.0.1:$port$RIE_PATH"; then return 0; fi
    sleep 0.25
  done
  return 1
}

check() { # check NAME DESCRIPTION FUNCTION
  local name=$1 desc=$2 fn=$3
  if [[ -n "${SELECTED:-}" && " $SELECTED " != *" $name "* ]]; then return 0; fi
  # Not inside an `if`: errexit is ignored in condition context, which would hide failures.
  ( set -euo pipefail; "$fn" ) >"$OUT/$name.log" 2>&1
  local rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "PASS $name  $desc"
  else
    echo "FAIL $name  $desc"
    tail -n 12 "$OUT/$name.log" | sed 's/^/     | /'
    FAILS=$((FAILS + 1))
  fi
}

build_worker() {
  docker build -q -f Dockerfile.worker --target vendor -t "$VENDOR_IMG" . >/dev/null
  docker build -q -f Dockerfile.worker -t "$WORKER_IMG" . >/dev/null
}

# ---------------------------------------------------------------- A1 pins
a1() {
  : > "$OUT/pins.txt"
  local img d
  for img in $BREF_IMG $BREF_DEV_IMG $GD_IMG $FPM_IMG $CLI_IMG $COMPOSER_IMG $NETSHOOT_IMG; do
    docker pull -q "$img" >/dev/null
    d=$(docker image inspect "$img" --format '{{index .RepoDigests 0}}')
    [[ "$d" == *@sha256:* ]] || { echo "no digest for $img"; return 1; }
    echo "$img $d" | tee -a "$OUT/pins.txt"
  done
  local v
  v=$(docker run --rm --entrypoint php "$BREF_IMG" -r 'echo PHP_VERSION;')
  echo "bref/php-85:3 PHP_VERSION=$v" | tee -a "$OUT/pins.txt"
  [[ "$v" =~ ^8\.5\.[0-9]+$ ]]
  # the version recorded in composer.json config.platform.php must equal the layer's version
  grep -q "\"php\": \"$v\"" composer.json
}

# ---------------------------------------------------------------- A2 function mode via RIE
a2() {
  build_worker
  docker run -d --name spikea-fn -p 127.0.0.1:19001:8080 -e BREF_RUNTIME=function "$WORKER_IMG" worker.php >/dev/null
  echo "--- 3-record batch (m2 body is 'fail')"
  invoke 19001 events/sqs-batch.json | tee "$OUT/a2-batch.txt"
  tail -n 1 "$OUT/a2-batch.txt" | grep -q '"batchItemFailures":\[{"itemIdentifier":"m2"}\]'
  grep -q '^HTTP/[0-9.]* 200' "$OUT/a2-batch.txt"
  echo "--- handler that throws"
  invoke 19001 events/sqs-throw.json | tee "$OUT/a2-throw.txt"
  grep -q 'errorType' "$OUT/a2-throw.txt"
  grep -q 'errorMessage' "$OUT/a2-throw.txt"
  echo "--- BREF_RUNTIME unset"
  docker run -d --name spikea-fn-norun -p 127.0.0.1:19002:8080 "$WORKER_IMG" worker.php >/dev/null
  invoke 19002 events/sqs-batch.json | tee "$OUT/a2-unset.txt" || true
  sleep 1
  docker logs spikea-fn-norun 2>&1 | tee "$OUT/a2-unset-logs.txt" | tail -n 8
  grep -q 'BREF_RUNTIME' "$OUT/a2-unset-logs.txt"
}

# ---------------------------------------------------------------- A3 FPM mode
a3() {
  [[ -n "$(docker images -q "$WORKER_IMG")" ]] || build_worker
  docker run -d --name spikea-fpm -p 127.0.0.1:19003:8080 -e BREF_RUNTIME=fpm -e APP_SPIKE_VAR=visible-in-php "$WORKER_IMG" public/index.php >/dev/null
  invoke 19003 events/apigw-v2.json | tee "$OUT/a3.txt"
  local body; body=$(tail -n 1 "$OUT/a3.txt")
  grep -q '^HTTP/[0-9.]* 200' "$OUT/a3.txt"
  echo "$body" | grep -q '\\"remote_addr\\":\\"127.0.0.1\\"'
  echo "$body" | grep -q '\\"http_cloudfront_viewer_address\\":\\"203.0.113.7:51234\\"'
  echo "$body" | grep -q '\\"env_app_spike_var\\":\\"visible-in-php\\"'
}

# ---------------------------------------------------------------- A4 GD with the extra layer
a4() {
  [[ -n "$(docker images -q "$WORKER_IMG")" ]] || build_worker
  docker run --rm --entrypoint php -v "$PWD/scripts:/s:ro" "$WORKER_IMG" -d error_reporting=-1 /s/gd-check.php | tee "$OUT/a4.txt"
  grep -q 'WebP Support=1' "$OUT/a4.txt"
  grep -q 'JPEG Support=1' "$OUT/a4.txt"
  grep -q 'PNG Support=1' "$OUT/a4.txt"
  grep -q 'webp_roundtrip=ok' "$OUT/a4.txt"
}

# ---------------------------------------------------------------- A5 Argon2id
a5() {
  local code='$h=password_hash("correct horse", PASSWORD_ARGON2ID); echo PHP_VERSION," ",PASSWORD_ARGON2ID," ",substr($h,0,31)," verify=",var_export(password_verify("correct horse",$h),true),"\n";'
  docker run --rm --entrypoint php "$BREF_IMG" -r "$code" | tee "$OUT/a5-bref.txt"
  docker run --rm "$FPM_IMG" php -r "$code" | tee "$OUT/a5-fpm.txt"
  grep -q 'argon2id.*verify=true' "$OUT/a5-bref.txt"
  grep -q 'argon2id.*verify=true' "$OUT/a5-fpm.txt"
}

# ---------------------------------------------------------------- A6 telemetry switch
telem_capture() { # telem_capture LABEL ENV_ARGS...  -> prints number of UDP/8125 packets
  local label=$1; shift
  docker run -d --name "spikea-telem-$label" -e BREF_RUNTIME=function "$@" "$WORKER_IMG" worker.php >/dev/null
  docker run -d --name "spikea-telem-cap-$label" --network "container:spikea-telem-$label" \
    --cap-add NET_RAW --cap-add NET_ADMIN "$NETSHOOT_IMG" \
    sh -c 'tcpdump -nn -l -i any "udp and not port 53" > /tmp/cap.txt 2>/dev/null' >/dev/null
  sleep 2
  # RIE listens on 8080 inside the shared netns; call it from a throwaway container in the same netns
  docker run --rm --network "container:spikea-telem-$label" "$NETSHOOT_IMG" sh -c '
    for i in $(seq 1 1500); do
      curl -s -m 10 -o /dev/null -X POST --data-binary "{\"Records\":[]}" http://127.0.0.1:8080'"$RIE_PATH"';
    done'
  sleep 1
  docker exec "spikea-telem-cap-$label" cat /tmp/cap.txt > "$OUT/a6-$label.txt" || true
  local n; n=$(grep -c '8125' "$OUT/a6-$label.txt" || true)
  echo "$label: packets to port 8125 = $n"
  docker rm -f "spikea-telem-cap-$label" "spikea-telem-$label" >/dev/null
  echo "$n" > "$OUT/a6-$label.count"
}
a6() {
  [[ -n "$(docker images -q "$VENDOR_IMG")" ]] || build_worker
  echo "--- source reading"
  docker run --rm --entrypoint sh "$VENDOR_IMG" -c 'grep -rn "BREF_PING_DISABLE\|3.219.198.164" /app/vendor/bref/bref/src; grep -n "\"version\"" /app/vendor/composer/installed.json | head -0; grep -A3 "\"name\": \"bref/bref\"" /app/vendor/composer/installed.json' | tee "$OUT/a6-source.txt"
  grep -q "BREF_PING_DISABLE" "$OUT/a6-source.txt"
  echo "--- traffic, switch OFF (ping enabled)"
  telem_capture on
  echo "--- traffic, BREF_PING_DISABLE=1"
  telem_capture off -e BREF_PING_DISABLE=1
  [[ "$(cat "$OUT/a6-on.count")" -ge 1 ]]
  [[ "$(cat "$OUT/a6-off.count")" -eq 0 ]]
}

# ---------------------------------------------------------------- A7 layers.json
a7() {
  scripts/a7-layers-json.sh | tee "$OUT/a7.txt"
  grep -q '^LOOKUP_OK' "$OUT/a7.txt"
}

# ---------------------------------------------------------------- A8 dependency tree
a8() {
  docker run --rm -v "$PWD":/app:ro -w /app "$COMPOSER_IMG" sh -c 'cp -r /app/composer.json /app/composer.lock /tmp/ && cd /tmp && composer show --locked --tree bref/bref --no-interaction' | tee "$OUT/a8.txt"
  grep -q '^bref/bref' "$OUT/a8.txt"
  grep -q 'symfony/process' "$OUT/a8.txt"
}

# ---------------------------------------------------------------- A9 tools on 8.5
a9() {
  build_tools
  docker run --rm "$TOOLS_IMG" scripts/run-tools.sh | tee "$OUT/a9.txt"
  grep -q 'PHPUNIT_OK' "$OUT/a9.txt"
  grep -q 'PHPSTAN_OK' "$OUT/a9.txt"
  grep -q 'CSFIXER_OK' "$OUT/a9.txt"
}
build_tools() { docker build -q -f Dockerfile.tools -t "$TOOLS_IMG" . >/dev/null; }

# ---------------------------------------------------------------- A10 coverage driver
a10() {
  build_tools
  docker run --rm "$TOOLS_IMG" php -m | grep -i -E '^(pcov|xdebug)$' | tee "$OUT/a10.txt"
  docker run --rm "$TOOLS_IMG" scripts/run-coverage.sh | tee -a "$OUT/a10.txt"
  grep -q 'COVERAGE_OK' "$OUT/a10.txt"
}

# ---------------------------------------------------------------- A11 production vendor
a11() {
  [[ -n "$(docker images -q "$WORKER_IMG")" ]] || build_worker
  docker run --rm --entrypoint php "$WORKER_IMG" -d error_reporting=-1 -d display_errors=1 \
    -r 'require "/var/task/vendor/autoload.php"; echo "autoload_ok ", PHP_VERSION, "\n"; echo class_exists("Bref\\Runtime\\LambdaRuntime") ? "bref_class_ok\n" : "NO\n"; echo is_file("/var/task/vendor/composer/platform_check.php") ? "platform_check_present\n" : "NO_PLATFORM_CHECK\n"; echo is_dir("/var/task/vendor/phpunit") ? "DEV_PRESENT\n" : "no_dev_ok\n";' | tee "$OUT/a11.txt"
  grep -q autoload_ok "$OUT/a11.txt"
  grep -q bref_class_ok "$OUT/a11.txt"
  grep -q platform_check_present "$OUT/a11.txt"
  grep -q no_dev_ok "$OUT/a11.txt"
}

SELECTED="${*:-}"
check A1  "pins: digests and exact PHP patch version"            a1
check A2  "function mode via RIE: batchItemFailures, throw, unset" a2
check A3  "FPM mode: REMOTE_ADDR, CloudFront viewer header, env"  a3
check A4  "GD layer: JPEG, PNG, WebP"                            a4
check A5  "Argon2id in bref/php-85 and php:8.5-fpm-alpine"       a5
check A6  "telemetry switch: source and UDP capture"             a6
check A7  "extra-php-extensions layers.json lookup"              a7
check A8  "composer show --tree bref/bref (platform pin)"        a8
check A9  "tools on 8.5 with E_ALL and deprecations as errors"   a9
check A10 "coverage driver pcov (fallback xdebug)"               a10
check A11 "--no-dev vendor loads in bref/php-85"                 a11

echo
if [[ $FAILS -eq 0 ]]; then echo "ALL CHECKS PASSED"; exit 0; fi
echo "$FAILS CHECK(S) FAILED"; exit 1
