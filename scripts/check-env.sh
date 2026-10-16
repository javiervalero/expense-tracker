#!/usr/bin/env bash
# The logic of `make check-env`: is the env file fit to start the local stack? Usage: check-env.sh <env-file> <example-file>
#
# 1. Keys that docker-compose.yml reads WITHOUT a default must be set (DYNAMODB_TABLE, and DYNAMODB_ENDPOINT, which the
#    API also needs): the message names the line to add, taken from the example file.
# 2. The AWS credentials must be the fake pair of the example file, and there must be no session token. api-php runs
#    request code that can read its own environment, so a real key in .env would be exposed to it. The messages name
#    the variable only: a value is NEVER printed.
#
# Exit codes: 0 = fine, 1 = a problem (all of them are listed), 2 = bad usage.
# Lines may have CRLF endings and an `export ` prefix; the last assignment of a variable wins.
set -euo pipefail

if [ "$#" -ne 2 ] || [ ! -f "$1" ] || [ ! -f "$2" ]; then
  echo "usage: check-env.sh <env-file> <example-file> (both must exist)" >&2
  exit 2
fi
ENV_FILE="$1"
EXAMPLE_FILE="$2"

REQUIRED_KEYS=(DYNAMODB_TABLE DYNAMODB_ENDPOINT)
FAKE_KEYS=(AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY)

# <file> <key>: the value of the last assignment, CR and surrounding blanks removed. Empty output for none.
value_of() {
  tr -d '\r' <"$1" |
    sed -nE "s/^[[:space:]]*(export[[:space:]]+)?$2=[[:space:]]*(.*[^[:space:]])?[[:space:]]*$/\\2/p" |
    tail -n 1
}

# <file> <key>: succeeds when some line assigns the key (even to an empty value).
is_assigned() {
  tr -d '\r' <"$1" | grep -Eq "^[[:space:]]*(export[[:space:]]+)?$2="
}

problems=0
problem() {
  echo "$1" >&2
  problems=$((problems + 1))
}

for key in "${REQUIRED_KEYS[@]}"; do
  # A usable value: starts with a character that is not a blank, a quote or a comment sign.
  if ! tr -d '\r' <"$ENV_FILE" | grep -Eq "^[[:space:]]*(export[[:space:]]+)?$key=[[:space:]]*[^[:space:]\"'#]"; then
    example="$(grep -m 1 "^$key=" "$EXAMPLE_FILE" || echo "$key=<value>")"
    problem "$key is missing from .env: add '$example' (see .env.example)"
  fi
done

for key in "${FAKE_KEYS[@]}"; do
  if ! is_assigned "$ENV_FILE" "$key"; then
    problem "$key is missing from .env: copy the line from .env.example (the local stack only runs with the fake pair)"
  elif [ "$(value_of "$ENV_FILE" "$key")" != "$(value_of "$EXAMPLE_FILE" "$key")" ]; then
    problem "$key in .env is not the fake local value from .env.example; the local stack only runs with the fake pair"
  fi
done

if is_assigned "$ENV_FILE" AWS_SESSION_TOKEN; then
  problem "AWS_SESSION_TOKEN is set in .env; remove it: the local stack only runs with the fake pair, which has no session token"
fi

[ "$problems" -eq 0 ]
