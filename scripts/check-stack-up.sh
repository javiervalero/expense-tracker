#!/usr/bin/env bash
# The guard at the start of `make test-integration`: is the whole local stack running? Usage: check-stack-up.sh [compose command...]
#
# `docker compose run` starts the dependencies of the service it runs, so on a stopped stack the integration tests would
# half-start it (without worker-runner) and fail later. This guard only READS the state (`config --services`,
# `ps --services --status running`): it never starts, stops or removes anything.
#
# Every long-running service must be running. `config --services` lists only the services of the default profiles, so
# the tools-profile services are not counted; the one-shot local-aws-init exits by design and is skipped explicitly.
# The compose command is the arguments (the Makefile passes $(COMPOSE), which unsets the AWS variables); with no
# arguments it is `docker compose -p expense-tracker`.
#
# Exit codes: 0 = all running, 1 = a service is missing, or docker could not answer.
set -euo pipefail

ONE_SHOT_SERVICES=(local-aws-init)

if [ "$#" -eq 0 ]; then
  set -- docker compose -p expense-tracker
fi

all="$("$@" config --services)" || {
  echo "check-stack-up: 'config --services' failed (see above); cannot tell whether the stack is up." >&2
  exit 1
}
running="$("$@" ps --services --status running)" || {
  echo "check-stack-up: 'ps' failed (see above); cannot tell whether the stack is up." >&2
  exit 1
}

[ -n "$all" ] || { echo "check-stack-up: 'config --services' listed no services." >&2; exit 1; }

missing=()
while IFS= read -r service; do
  [ -n "$service" ] || continue
  for one_shot in "${ONE_SHOT_SERVICES[@]}"; do
    [ "$service" != "$one_shot" ] || continue 2
  done
  grep -qxF -- "$service" <<<"$running" || missing+=("$service")
done <<<"$all"

if [ "${#missing[@]}" -ne 0 ]; then
  echo "The local stack is not fully up. Not running: ${missing[*]}. Run 'make up' first." >&2
  exit 1
fi
