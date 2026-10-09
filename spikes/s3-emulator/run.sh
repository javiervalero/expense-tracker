#!/usr/bin/env bash
# Runs the whole S3 emulator spike: install deps, start stack, bootstrap, tests, outage scenario.
# Usage: ./run.sh [up|test|down]   (default: up + test; `down` removes the throwaway spikeb project and volumes)
set -euo pipefail
cd "$(dirname "$0")"
export HOST_UID="$(id -u)" HOST_GID="$(id -g)"
DC=(docker compose -p spikeb -f compose.yml)
PHPUNIT=(php vendor/bin/phpunit --no-progress)
run_tests() { "${DC[@]}" run --rm --no-deps -e OUTAGE_PHASE="${OUTAGE_PHASE:-}" tests "$@"; }

install_deps() {
  if [ ! -d vendor ]; then
    docker run --rm --user "$HOST_UID:$HOST_GID" -e COMPOSER_HOME=/tmp -v "$PWD:/app" -w /app \
      composer:2.10.3 install --no-interaction
  fi
}

wait_healthy() {
  for _ in $(seq 1 60); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' "$1" 2>/dev/null)" = healthy ] && return 0
    sleep 1
  done
  echo "service $1 did not become healthy" >&2; return 1
}

case "${1:-all}" in
  down)
    "${DC[@]}" --profile tools down -v
    ;;
  up|all|test)
    install_deps
    mkdir -p results .state
    [ "${1:-all}" = test ] || { rm -f results/results.jsonl .state/*; "${DC[@]}" up -d --wait; }
    run_tests php bootstrap.php
    status=0
    run_tests "${PHPUNIT[@]}" || status=$?

    echo "== outage scenario: receiver down, then back =="
    "${DC[@]}" stop capture
    OUTAGE_PHASE=1 run_tests "${PHPUNIT[@]}" --filter testOutagePhase1 || status=$?
    "${DC[@]}" start capture
    wait_healthy spikeb-capture-1
    OUTAGE_PHASE=2 run_tests "${PHPUNIT[@]}" --filter testOutagePhase2 || status=$?
    exit "$status"
    ;;
esac
